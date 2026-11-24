<?php
/**
 * API de cadastro público SaaS - DOT-ON
 * Rotas: GET /cnpj/{cnpj}, POST /signup, POST /parse_funcionarios
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/billing.php';

function signup_api_route(): string {
    if (!empty($_GET['rota'])) {
        return trim((string)$_GET['rota'], '/');
    }

    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $uriPath = trim($uriPath, '/');

    if (preg_match('#(?:^|/)app/api/signup\.php/?(.*)$#', $uriPath, $m)) {
        $path = trim($m[1] ?? '', '/');
        return $path !== '' ? $path : 'signup';
    }

    if (preg_match('#(?:^|/)app/api/?(.*)$#', $uriPath, $m)) {
        return trim($m[1] ?? '', '/');
    }

    if (!empty($_SERVER['PATH_INFO'])) {
        return trim((string)$_SERVER['PATH_INFO'], '/');
    }

    return '';
}

$rota = signup_api_route();

try {

    // ============= VALIDAR CNPJ via BrasilAPI =============
    if (preg_match('#^cnpj/(\d{14})$#', $rota, $m)) {
        $cnpj = $m[1];
        // BrasilAPI (gratuita, sem chave)
        $url = "https://brasilapi.com.br/api/cnpj/v1/" . $cnpj;
        $ctx = stream_context_create(['http'=>['timeout'=>10, 'user_agent'=>'DOT-ON/1.0']]);
        $resp = @file_get_contents($url, false, $ctx);
        if (!$resp) {
            // Fallback: só valida formato
            echo json_encode(['ok'=>true, 'fonte'=>'local', 'dados'=>null, 'aviso'=>'API externa indisponível, preencha manualmente']);
            exit;
        }
        $data = json_decode($resp, true);
        if (!$data || isset($data['message'])) {
            echo json_encode(['ok'=>false, 'erro'=>'CNPJ não encontrado na Receita Federal']);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'fonte' => 'brasilapi',
            'dados' => [
                'cnpj' => $cnpj,
                'razao_social' => $data['razao_social'] ?? '',
                'nome_fantasia' => $data['nome_fantasia'] ?? '',
                'cep' => preg_replace('/\D/','', $data['cep'] ?? ''),
                'endereco' => trim(($data['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($data['logradouro'] ?? '') . ', ' . ($data['numero'] ?? '') . ' - ' . ($data['bairro'] ?? '')),
                'cidade' => $data['municipio'] ?? '',
                'uf' => $data['uf'] ?? '',
                'situacao' => $data['descricao_situacao_cadastral'] ?? '',
                'porte' => $data['porte'] ?? '',
            ]
        ]);
        exit;
    }

    // ============= PARSE FUNCIONÁRIOS (CSV/XLSX) =============
    if ($rota === 'parse_funcionarios' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_FILES['arquivo'])) {
            echo json_encode(['ok'=>false,'erro'=>'Nenhum arquivo enviado']);
            exit;
        }
        $tmp = $_FILES['arquivo']['tmp_name'];
        $nome = $_FILES['arquivo']['name'];
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));

        $linhas = [];
        if ($ext === 'csv') {
            $f = fopen($tmp, 'r');
            // Detecta delimitador
            $primeira_linha = fgets($f);
            rewind($f);
            $delim = (substr_count($primeira_linha, ';') > substr_count($primeira_linha, ',')) ? ';' : ',';
            while (($row = fgetcsv($f, 0, $delim)) !== false) {
                $linhas[] = array_map('trim', $row);
            }
            fclose($f);
        } elseif ($ext === 'xlsx' || $ext === 'xls') {
            // Parser XLSX simples sem dependências
            $linhas = parse_xlsx_simple($tmp);
        } else {
            echo json_encode(['ok'=>false,'erro'=>'Formato não suportado. Use .csv ou .xlsx']);
            exit;
        }

        if (count($linhas) < 2) {
            echo json_encode(['ok'=>false,'erro'=>'Planilha vazia ou só com cabeçalho']);
            exit;
        }

        // Detecta cabeçalho (primeira linha = nomes das colunas)
        $cabec = array_map(function($c){ return strtolower(trim((string)$c)); }, $linhas[0]);
        $idx_nome = null; $idx_email = null; $idx_cpf = null; $idx_mat = null;
        foreach ($cabec as $i => $col) {
            if (in_array($col, ['nome','funcionario','colaborador','nome completo'])) $idx_nome = $i;
            if (in_array($col, ['email','e-mail','mail'])) $idx_email = $i;
            if (in_array($col, ['cpf'])) $idx_cpf = $i;
            if (in_array($col, ['matricula','matrícula','mat','id'])) $idx_mat = $i;
        }
        if ($idx_nome === null || $idx_email === null) {
            echo json_encode(['ok'=>false,'erro'=>'Cabeçalho da planilha precisa ter as colunas "nome" e "email"']);
            exit;
        }

        $funcionarios = [];
        $duplicados_email = [];
        for ($i=1; $i<count($linhas); $i++) {
            $row = $linhas[$i];
            $nome = trim((string)($row[$idx_nome] ?? ''));
            $email = strtolower(trim((string)($row[$idx_email] ?? '')));
            if (!$nome || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            if (in_array($email, $duplicados_email)) continue;
            $duplicados_email[] = $email;
            $funcionarios[] = [
                'nome' => $nome,
                'email' => $email,
                'cpf' => $idx_cpf !== null ? preg_replace('/\D/','', (string)($row[$idx_cpf] ?? '')) : '',
                'matricula' => $idx_mat !== null ? trim((string)($row[$idx_mat] ?? '')) : '',
            ];
        }

        echo json_encode(['ok'=>true, 'funcionarios' => $funcionarios, 'total' => count($funcionarios)]);
        exit;
    }

    // ============= SIGNUP - Criar empresa + gestor + funcionários =============
    if ($rota === 'signup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        if (!$in) { echo json_encode(['ok'=>false,'erro'=>'JSON inválido']); exit; }

        $g = $in['gestor'] ?? [];
        $e = $in['empresa'] ?? [];
        $j = $in['jornada'] ?? [];
        $funcs = $in['funcionarios'] ?? [];
        $enviar_email = !empty($in['enviar_email']);

        // Validações
        if (empty($g['email']) || empty($g['senha']) || empty($g['nome'])) {
            echo json_encode(['ok'=>false,'erro'=>'Dados do gestor incompletos']); exit;
        }
        if (empty($e['cnpj']) || empty($e['razao_social'])) {
            echo json_encode(['ok'=>false,'erro'=>'Dados da empresa incompletos']); exit;
        }

        $cnpj = preg_replace('/\D/','', $e['cnpj']);
        if (strlen($cnpj) !== 14) { echo json_encode(['ok'=>false,'erro'=>'CNPJ inválido']); exit; }

        // Garantia de CNPJ válido: dígitos verificadores (local, sempre) + existência
        // na Receita (best-effort — só bloqueia se a API AFIRMAR inexistente/baixado;
        // se estiver indisponível, não trava o cadastro).
        if (!cnpj_valido($cnpj)) {
            echo json_encode(['ok'=>false,'erro'=>'CNPJ inválido — verifique os dígitos.']); exit;
        }
        $situacao_cnpj = cnpj_consulta_receita($cnpj);
        if ($situacao_cnpj === 'inexistente') {
            echo json_encode(['ok'=>false,'erro'=>'CNPJ não encontrado na Receita Federal.']); exit;
        }
        if ($situacao_cnpj === 'baixado') {
            echo json_encode(['ok'=>false,'erro'=>'Este CNPJ consta como baixado/inativo na Receita Federal.']); exit;
        }

        $pdo = db();

        // CNPJ já existe?
        $st = $pdo->prepare("SELECT id FROM dot_empresas WHERE REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') = ?");
        $st->execute([$cnpj]);
        if ($st->fetch()) {
            echo json_encode(['ok'=>false,'erro'=>'Este CNPJ já está cadastrado. Faça login ou recupere sua senha.']);
            exit;
        }

        // E-mail gestor já existe?
        $st = $pdo->prepare("SELECT id FROM dot_usuarios WHERE email = ?");
        $st->execute([$g['email']]);
        if ($st->fetch()) {
            echo json_encode(['ok'=>false,'erro'=>'Este e-mail já está em uso. Faça login ou recupere sua senha.']);
            exit;
        }

        // Lido ANTES da transação: na 1ª chamada após um deploy, billing_config()
        // pode rodar DDL (CREATE/ALTER), que faz COMMIT implícito no MySQL.
        $dias_trial = (int)(billing_config()['dias_trial'] ?? 30);
        if ($dias_trial <= 0) $dias_trial = 30;

        $pdo->beginTransaction();
        try {
            // 1. Cria empresa
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i','-', $e['nome_fantasia'] ?: $e['razao_social']));
            $slug = substr($slug, 0, 60) . '-' . substr(md5($cnpj), 0, 6);

            // Separar cidade/uf
            $cidade = ''; $uf = '';
            if (!empty($e['cidade_uf'])) {
                $parts = explode('/', $e['cidade_uf']);
                $cidade = trim($parts[0] ?? '');
                $uf = strtoupper(trim($parts[1] ?? ''));
            }

            // Duração do trial ($dias_trial) vem da config de faturamento, lida acima.
            $st = $pdo->prepare("INSERT INTO dot_empresas (razao_social, nome_fantasia, cnpj, slug, telefone, email_contato, setor, cep, cidade, uf, endereco, plano, ativo, trial_expira, criado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $st->execute([
                $e['razao_social'],
                $e['nome_fantasia'] ?: $e['razao_social'],
                $e['cnpj'],
                $slug,
                $e['telefone'] ?? '',
                $g['email'],
                $e['setor'] ?? '',
                $e['cep'] ?? '',
                $cidade,
                $uf,
                $e['endereco'] ?? '',
                'free',
                1,
                date('Y-m-d', strtotime('+30 days'))
            ]);
            $empresa_id = (int)$pdo->lastInsertId();

            // 1b. Semeia a sequência de NSR da empresa (evita NSR preso em 1)
            $pdo->prepare("INSERT INTO dot_nsr_sequencia (empresa_id, ultimo_nsr) VALUES (?, 0)
                           ON DUPLICATE KEY UPDATE ultimo_nsr = ultimo_nsr")
                ->execute([$empresa_id]);

            // 2. Cria escala
            $st = $pdo->prepare("INSERT INTO dot_escalas (empresa_id, nome, entrada, intervalo_inicio, intervalo_fim, saida, dias_semana, carga_diaria_minutos, tolerancia_minutos, intervalo_obrigatorio_minutos, ativo) VALUES (?,?,?,?,?,?,?,?,?,?,1)");
            $entrada = $j['entrada'] ?? '08:00';
            $saida = $j['saida'] ?? '17:00';
            $ai = $j['almoco_ini'] ?? '12:00';
            $af = $j['almoco_fim'] ?? '13:00';
            $tol = $j['tolerancia'] ?? 10;
            $dias = $j['dias_semana'] ?? 62;
            // Carga diária em minutos
            $minSaida = ((int)substr($saida,0,2))*60 + (int)substr($saida,3,2);
            $minEnt = ((int)substr($entrada,0,2))*60 + (int)substr($entrada,3,2);
            $minAi = ((int)substr($ai,0,2))*60 + (int)substr($ai,3,2);
            $minAf = ((int)substr($af,0,2))*60 + (int)substr($af,3,2);
            $carga = $minSaida - $minEnt - ($minAf - $minAi);
            $intMin = $minAf - $minAi;
            $st->execute([$empresa_id, 'Padrão', $entrada.':00', $ai.':00', $af.':00', $saida.':00', $dias, $carga, $tol, $intMin]);
            $escala_id = (int)$pdo->lastInsertId();

            // 3. Cria gestor
            $senha_hash = password_hash($g['senha'], PASSWORD_BCRYPT);
            $api_token = bin2hex(random_bytes(32));
            $st = $pdo->prepare("INSERT INTO dot_usuarios (empresa_id, nome_completo, email, celular, senha_hash, matricula, perfil, escala_id, ativo, api_token, email_confirmado, criado_em) VALUES (?,?,?,?,?,?,?,?,1,?,1,NOW())");
            $st->execute([$empresa_id, $g['nome'], $g['email'], $g['celular'] ?? '', $senha_hash, 'GES001', 'admin', $escala_id, $api_token]);
            $gestor_id = (int)$pdo->lastInsertId();

            // 4. Funcionários
            $funcs_criados = [];
            $emails_falhos = [];
            foreach ($funcs as $i => $f) {
                $email_f = strtolower(trim($f['email'] ?? ''));
                $nome_f = trim($f['nome'] ?? '');
                if (!$nome_f || !$email_f) continue;

                // Verificar duplicata
                $st = $pdo->prepare("SELECT id FROM dot_usuarios WHERE email = ?");
                $st->execute([$email_f]);
                if ($st->fetch()) { $emails_falhos[] = $email_f; continue; }

                $senha_temp = gerar_senha_temp();
                $hash_f = password_hash($senha_temp, PASSWORD_BCRYPT);
                $matricula = $f['matricula'] ?? sprintf('FUN%03d', $i+1);

                $st = $pdo->prepare("INSERT INTO dot_usuarios (empresa_id, nome_completo, email, senha_hash, matricula, cpf, perfil, escala_id, ativo, api_token, precisa_trocar_senha, criado_em) VALUES (?,?,?,?,?,?,?,?,1,?,1,NOW())");
                $st->execute([$empresa_id, $nome_f, $email_f, $hash_f, $matricula, $f['cpf'] ?? '', 'funcionario', $escala_id, bin2hex(random_bytes(32))]);

                $funcs_criados[] = ['nome'=>$nome_f, 'email'=>$email_f, 'senha_temp'=>$senha_temp];
            }

            // 5. Onboarding registrado
            $pdo->prepare("INSERT INTO dot_onboarding (empresa_id, passo_conta, passo_empresa, passo_jornada, passo_funcionarios, passo_concluido, concluido_em) VALUES (?,1,1,1,1,1,NOW())")
                ->execute([$empresa_id]);

            $pdo->commit();

            // 6. Enviar e-mails (após commit, fora da transação)
            $url_painel = 'https://dot-on.com.br/app/admin/login.php';
            $url_exe = 'https://dot-on.com.br/app/downloads/DOT-ON-Agent.exe';

            // Boas-vindas gestor
            if ($enviar_email) {
                [$assunto, $html, $texto] = email_template_boasvindas_gestor($g['nome'], $e['nome_fantasia'] ?: $e['razao_social'], $g['email'], 'A senha que você definiu', $url_painel);
                email_enviar($g['email'], $g['nome'], $assunto, $html, $texto, $empresa_id);

                // Funcionários
                foreach ($funcs_criados as $fc) {
                    [$assunto, $html, $texto] = email_template_boasvindas_funcionario($fc['nome'], $e['nome_fantasia'] ?: $e['razao_social'], $fc['email'], $fc['senha_temp'], $url_exe, $url_painel);
                    email_enfileirar($fc['email'], $fc['nome'], $assunto, $html, $texto, $empresa_id);
                }
                // Tenta processar fila imediatamente
                email_processar_fila(20);
            }

            $msg = "Empresa criada! ";
            if (count($funcs_criados)) $msg .= count($funcs_criados) . " funcionários cadastrados. ";
            if ($enviar_email) $msg .= "E-mails de boas-vindas enviados.";

            echo json_encode([
                'ok' => true,
                'empresa_id' => $empresa_id,
                'gestor' => ['id' => $gestor_id, 'email' => $g['email'], 'token' => $api_token],
                'funcionarios_criados' => count($funcs_criados),
                'emails_duplicados' => $emails_falhos,
                'mensagem' => $msg,
                'url_painel' => $url_painel,
            ]);
            exit;

        } catch (Throwable $e_inner) {
            $pdo->rollBack();
            error_log("DOT-ON signup error: " . $e_inner->getMessage());
            echo json_encode(['ok'=>false, 'erro'=>'Erro ao criar empresa. Tente novamente.']);
            exit;
        }
    }

    // Rota não encontrada
    http_response_code(404);
    echo json_encode(['ok'=>false, 'erro'=>'Rota não encontrada: ' . $rota]);

} catch (Throwable $e) {
    http_response_code(500);
    error_log("DOT-ON signup fatal: " . $e->getMessage());
    echo json_encode(['ok'=>false, 'erro'=>'Erro interno']);
}

// ============= Helpers =============
function gerar_senha_temp($len = 10) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i=0; $i<$len; $i++) $s .= $chars[random_int(0, strlen($chars)-1)];
    return $s;
}

// Parser XLSX minimalista (sem dependências PHPExcel/PhpSpreadsheet)
function parse_xlsx_simple($file) {
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) return [];

    // Shared strings
    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        // Captura cada <si>... pegando textos
        if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $ssXml, $m)) {
            foreach ($m[1] as $si) {
                $text = '';
                if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                    foreach ($tm[1] as $t) $text .= $t;
                }
                $strings[] = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }

    // Sheet1
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) return [];

    $rows = [];
    if (preg_match_all('/<row\b[^>]*>(.*?)<\/row>/s', $sheetXml, $rm)) {
        foreach ($rm[1] as $rowXml) {
            $cells = [];
            if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $rowXml, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $cellMatch) {
                    $attrs = $cellMatch[1];
                    $inner = $cellMatch[2];
                    $is_str = strpos($attrs, 't="s"') !== false;
                    if (preg_match('/<v>(.*?)<\/v>/', $inner, $vm)) {
                        $val = $vm[1];
                        if ($is_str) $val = $strings[(int)$val] ?? '';
                        $cells[] = $val;
                    } else {
                        $cells[] = '';
                    }
                }
            }
            $rows[] = $cells;
        }
    }
    return $rows;
}
