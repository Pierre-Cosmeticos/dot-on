<?php
$cors_allow = array_filter(array_map('trim', explode(',',
    getenv('DOTON_CORS_ORIGINS') ?: 'https://dot-on.com.br,https://www.dot-on.com.br')));
$cors_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($cors_origin && in_array($cors_origin, $cors_allow, true)) {
    header('Access-Control-Allow-Origin: ' . $cors_origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/repp.php';
require_once __DIR__ . '/../includes/crp.php';
require_once __DIR__ . '/../includes/justificativas.php';
require_once __DIR__ . '/../includes/ajuste_ponto.php';
require_once __DIR__ . '/../includes/banco_horas.php';

function api_route(): string {
    if (!empty($_GET['rota'])) {
        return trim((string)$_GET['rota'], '/');
    }

    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $uriPath = trim($uriPath, '/');

    if (preg_match('#(?:^|/)app/api/index\.php/?(.*)$#', $uriPath, $m)) {
        return trim($m[1] ?? '', '/');
    }

    if (preg_match('#(?:^|/)app/api/?(.*)$#', $uriPath, $m)) {
        return trim($m[1] ?? '', '/');
    }

    if (!empty($_SERVER['PATH_INFO'])) {
        return trim((string)$_SERVER['PATH_INFO'], '/');
    }

    return '';
}

$path = api_route();
$method = $_SERVER['REQUEST_METHOD'];

if (preg_match('#^validar/(\d+)$#', $path, $m)) {
    $_GET['nsr'] = $m[1];
    $path = 'validar';
}

if (preg_match('#^hora-extra/status/(\d+)$#', $path, $m)) {
    $_GET['id'] = $m[1];
    $path = 'hora-extra/status';
}

if (preg_match('#^sessao/mes/(\d{4}-\d{2})$#', $path, $m)) {
    $_GET['mes'] = $m[1];
    $path = 'sessao/mes';
}

try {
    switch ("$method $path") {

        case 'POST login':
            $in = get_input();
            $u = autenticar($in['email'] ?? '', $in['senha'] ?? '');
            if (!$u) json_response(['ok'=>false, 'erro'=>'Credenciais inválidas'], 401);
            // Busca dados da empresa
            $stEmp = db()->prepare("SELECT id, razao_social, nome_fantasia, plano, ativo FROM dot_empresas WHERE id=?");
            $stEmp->execute([$u['empresa_id']]);
            $emp = $stEmp->fetch();
            if (!$emp || !$emp['ativo']) json_response(['ok'=>false,'erro'=>'Empresa inativa'], 401);
            json_response([
                'ok' => true,
                'token' => $u['api_token'],
                'precisa_trocar_senha' => (int)($u['precisa_trocar_senha'] ?? 0),
                'user' => [
                    'id' => $u['id'], 'nome' => $u['nome_completo'],
                    'matricula' => $u['matricula'], 'perfil' => $u['perfil'],
                    'empresa_id' => $u['empresa_id'], 'escala_id' => $u['escala_id'],
                    'email' => $u['email'],
                ],
                'empresa' => [
                    'id' => $emp['id'],
                    'razao_social' => $emp['razao_social'],
                    'nome_fantasia' => $emp['nome_fantasia'],
                    'plano' => $emp['plano'],
                ]
            ]);
            break;

        case 'POST trocar-senha':
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'], 401);
            $in = get_input();
            $atual = $in['senha_atual'] ?? '';
            $nova = $in['nova_senha'] ?? '';
            if (strlen($nova) < 8) json_response(['ok'=>false,'erro'=>'Nova senha precisa ter no mínimo 8 caracteres'], 400);
            if (!password_verify($atual, $u['senha_hash'])) json_response(['ok'=>false,'erro'=>'Senha atual incorreta'], 400);
            trocar_senha_usuario($u['id'], $nova);
            json_response(['ok'=>true, 'mensagem'=>'Senha alterada com sucesso']);
            break;

        // ---------- BATIDA com REP-P (hash encadeado + CRP) ----------
        case 'POST batida':
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            $in = get_input();
            $tipo = $in['tipo'] ?? '';
            $tiposValidos = ['entrada','saida_intervalo','retorno_intervalo','saida','extra_inicio','extra_fim'];
            if (!in_array($tipo, $tiposValidos)) json_response(['ok'=>false,'erro'=>'Tipo inválido'],400);

            // Idempotência: o agente reenvia batidas que ficaram na fila offline
            // com o mesmo client_uid. Se já foi gravada, devolve a mesma resposta
            // em vez de duplicar o registro na cadeia REP-P.
            $client_uid = substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['client_uid'] ?? '')), 0, 40);
            $client_uid = $client_uid !== '' ? $client_uid : null;
            batidas_garantir_client_uid();
            if ($client_uid) {
                $st = db()->prepare("SELECT nsr, momento, sessao_id, hash_registro
                                     FROM dot_batidas WHERE usuario_id=? AND client_uid=? LIMIT 1");
                $st->execute([$u['id'], $client_uid]);
                if ($ja = $st->fetch()) {
                    json_response([
                        'ok'=>true, 'duplicada'=>true,
                        'nsr'=>(int)$ja['nsr'], 'sessao_id'=>(int)$ja['sessao_id'],
                        'hash'=>$ja['hash_registro'], 'momento'=>$ja['momento'],
                        'agora'=>date('Y-m-d H:i:s'),
                    ]);
                }
            }

            // Origem e localização. Batidas pelo NAVEGADOR (origem 'web') exigem
            // geolocalização; o agent desktop (origem 'desktop') não exige.
            $origem_batida = (($in['origem'] ?? '') === 'web') ? 'web' : 'desktop';
            $lat = $in['latitude'] ?? null;
            $lng = $in['longitude'] ?? null;
            $precisao = isset($in['precisao']) && is_numeric($in['precisao']) ? (float)$in['precisao'] : null;
            if ($origem_batida === 'web') {
                if (!is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180) {
                    json_response(['ok'=>false,'erro'=>'Localização obrigatória para bater ponto pelo navegador. Ative o GPS e permita o acesso à sua localização.'], 422);
                }
            }
            $lat = is_numeric($lat) ? round((float)$lat, 7) : null;
            $lng = is_numeric($lng) ? round((float)$lng, 7) : null;

            // Valida o momento informado pelo agente. Formato inválido ou data no
            // futuro (além de 5 min de tolerância de relógio) cai para a hora do servidor.
            $momento_in = (string)($in['momento'] ?? '');
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $momento_in);
            $agora = new DateTime();
            if (!$dt || $dt->format('Y-m-d H:i:s') !== $momento_in) {
                $dt = $agora;
            } elseif ($dt > (clone $agora)->modify('+5 minutes')) {
                $dt = $agora;
            }
            $momento = $dt->format('Y-m-d H:i:s');
            $hostname = substr($in['hostname'] ?? '', 0, 100);
            $data_ref = $dt->format('Y-m-d');

            $pdo = db();
            // sessão do dia
            $stmt = $pdo->prepare("SELECT id FROM dot_sessoes WHERE usuario_id=? AND data_ref=?");
            $stmt->execute([$u['id'], $data_ref]);
            $sessao = $stmt->fetch();
            if (!$sessao && $tipo === 'entrada') {
                $pdo->prepare("INSERT INTO dot_sessoes (usuario_id, data_ref, inicio, status) VALUES (?,?,?, 'aberta')")
                    ->execute([$u['id'], $data_ref, $momento]);
                $sessao_id = (int)$pdo->lastInsertId();
            } elseif ($sessao) {
                $sessao_id = (int)$sessao['id'];
                if ($tipo === 'saida') {
                    $pdo->prepare("UPDATE dot_sessoes SET fim=?, status='encerrada' WHERE id=?")
                        ->execute([$momento, $sessao_id]);
                }
            } else {
                json_response(['ok'=>false,'erro'=>'Não há sessão aberta. Registre a entrada primeiro.'],400);
            }

            // NSR sequencial atômico
            $nsr = proximo_nsr((int)$u['empresa_id']);

            // hash encadeado
            $hash_anterior = repp_ultimo_hash((int)$u['empresa_id']);
            $batida_arr = [
                'nsr' => $nsr,
                'empresa_id' => $u['empresa_id'],
                'usuario_id' => $u['id'],
                'cpf_snapshot' => $u['cpf'] ?? '',
                'pis_snapshot' => $u['pis'] ?? '',
                'tipo' => $tipo,
                'momento' => $momento,
            ];
            $hash_atual = repp_hash_batida($batida_arr, $hash_anterior);

            // Detecta extemporânea (batida >30min depois do real)
            $extemporanea = (strtotime($momento) < time() - 1800) ? 1 : 0;

            batidas_garantir_geo();
            $pdo->prepare("INSERT INTO dot_batidas
                (nsr, sessao_id, empresa_id, usuario_id, tipo, momento, origem, ip_origem, hostname,
                 cpf_snapshot, pis_snapshot, hash_registro, hash_anterior, hash_alg, extemporanea,
                 latitude, longitude, precisao_metros, client_uid)
                VALUES (?,?,?,?,?,?, ?, ?, ?, ?, ?, ?, ?, 'SHA-256', ?, ?, ?, ?, ?)")
                ->execute([$nsr, $sessao_id, $u['empresa_id'], $u['id'], $tipo, $momento,
                           $origem_batida, $_SERVER['REMOTE_ADDR'] ?? null, $hostname,
                           $u['cpf'] ?? '', $u['pis'] ?? '',
                           $hash_atual, $hash_anterior, $extemporanea,
                           $lat, $lng, $precisao, $client_uid]);
            $batida_id = (int)$pdo->lastInsertId();

            // Recalcula minutos trabalhados/intervalo da sessão a partir das
            // batidas (mantém dot_sessoes coerente para espelho e banco de horas).
            try { jus_recalcular_sessao($sessao_id); }
            catch (Throwable $e) { error_log("recalc sessao fail: " . $e->getMessage()); }

            // Emite CRP automaticamente (com e-mail)
            $crp_result = null;
            try { $crp_result = crp_emitir($batida_id, true); }
            catch (Throwable $e) { error_log("CRP fail: " . $e->getMessage()); }

            auditar((int)$u['id'], 'batida', 'sessao', $sessao_id, ['tipo'=>$tipo, 'nsr'=>$nsr]);

            json_response([
                'ok'=>true,
                'nsr'=>$nsr,
                'sessao_id'=>$sessao_id,
                'hash'=>$hash_atual,
                // Momento REALMENTE gravado e hora do servidor: o agente ancora
                // o cronômetro nestes valores, nunca no relógio da máquina.
                'momento'=>$momento,
                'agora'=>$agora->format('Y-m-d H:i:s'),
                'crp_url' => $crp_result['url'] ?? null,
                'crp_emitido' => !empty($crp_result['ok']),
                'extemporanea' => (bool)$extemporanea,
            ]);
            break;

        case 'POST ociosidade':
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            $in = get_input();
            $inicio = $in['inicio'] ?? null;
            $fim = $in['fim'] ?? null;
            if (!$inicio || !$fim) json_response(['ok'=>false,'erro'=>'inicio e fim obrigatórios'],400);
            $duracao = max(0, strtotime($fim) - strtotime($inicio));
            $data_ref = substr($inicio, 0, 10);
            $stmt = db()->prepare("SELECT id FROM dot_sessoes WHERE usuario_id=? AND data_ref=?");
            $stmt->execute([$u['id'], $data_ref]);
            $sessao_id = $stmt->fetchColumn();
            if (!$sessao_id) json_response(['ok'=>false,'erro'=>'Sessão não encontrada'],400);
            db()->prepare("INSERT INTO dot_ociosidade (sessao_id, usuario_id, inicio, fim, duracao_segundos, motivo)
                           VALUES (?,?,?,?,?,?)")
                ->execute([$sessao_id, $u['id'], $inicio, $fim, $duracao, substr($in['motivo'] ?? '', 0, 255)]);
            db()->prepare("UPDATE dot_sessoes SET minutos_ociosos = minutos_ociosos + ? WHERE id=?")
                ->execute([intdiv($duracao, 60), $sessao_id]);
            json_response(['ok'=>true, 'duracao_segundos'=>$duracao]);
            break;

        case 'POST hora-extra/solicitar':
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            $in = get_input();
            $minutos = (int)($in['minutos'] ?? 0);
            $justif = trim($in['justificativa'] ?? '');
            if ($minutos <= 0 || !$justif) json_response(['ok'=>false,'erro'=>'minutos e justificativa obrigatórios'],400);
            $data_ref = date('Y-m-d');
            $stmt = db()->prepare("SELECT id FROM dot_sessoes WHERE usuario_id=? AND data_ref=?");
            $stmt->execute([$u['id'], $data_ref]);
            $sessao_id = $stmt->fetchColumn();
            db()->prepare("INSERT INTO dot_horas_extras (usuario_id, sessao_id, data_ref, minutos_solicitados, justificativa)
                           VALUES (?,?,?,?,?)")
                ->execute([$u['id'], $sessao_id ?: null, $data_ref, $minutos, $justif]);
            $id = (int)db()->lastInsertId();
            auditar((int)$u['id'], 'solicitar_extra', 'hora_extra', $id, ['minutos'=>$minutos]);
            try { notificar_gestor_extra((int)$u['id'], $id, $minutos, $justif); } catch (Throwable $e) {}
            json_response(['ok'=>true,'id'=>$id,'status'=>'pendente']);
            break;

        case 'GET hora-extra/status':
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare("SELECT id, status, minutos_solicitados, minutos_aprovados, motivo_decisao, decidido_em
                                   FROM dot_horas_extras WHERE id=? AND usuario_id=?");
            $stmt->execute([$id, $u['id']]);
            $r = $stmt->fetch();
            if (!$r) json_response(['ok'=>false,'erro'=>'Não encontrado'],404);
            json_response(['ok'=>true, 'pedido'=>$r]);
            break;

        case 'GET hora-extra/hoje':
            // Pedidos de hora extra do dia do próprio funcionário. O agente usa
            // para recuperar um pedido pendente/aprovado ao ser reaberto (antes
            // o id ficava só em memória e se perdia ao fechar o app).
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            $stmt = db()->prepare("SELECT id, status, minutos_solicitados, minutos_aprovados,
                                          motivo_decisao, solicitado_em, decidido_em
                                   FROM dot_horas_extras WHERE usuario_id=? AND data_ref=?
                                   ORDER BY solicitado_em DESC, id DESC LIMIT 20");
            $stmt->execute([$u['id'], date('Y-m-d')]);
            $pedidos = array_map(function($p){
                return [
                    'id' => (int)$p['id'], 'status' => $p['status'],
                    'minutos_solicitados' => (int)$p['minutos_solicitados'],
                    'minutos_aprovados' => (int)($p['minutos_aprovados'] ?? 0),
                    'motivo_decisao' => $p['motivo_decisao'],
                    'solicitado_em' => $p['solicitado_em'], 'decidido_em' => $p['decidido_em'],
                ];
            }, $stmt->fetchAll());
            json_response(['ok'=>true, 'agora'=>date('Y-m-d H:i:s'), 'pedidos'=>$pedidos]);
            break;

        case 'GET sessao/atual':
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            $data_ref = date('Y-m-d');
            $stmt = db()->prepare("SELECT * FROM dot_sessoes WHERE usuario_id=? AND data_ref=?");
            $stmt->execute([$u['id'], $data_ref]);
            $sessao = $stmt->fetch();
            $batidas = [];
            if ($sessao) {
                batidas_garantir_cancelamento();
                $stmt = db()->prepare("SELECT nsr, tipo, momento FROM dot_batidas WHERE sessao_id=? AND COALESCE(cancelada,0)=0 ORDER BY momento");
                $stmt->execute([$sessao['id']]);
                $batidas = $stmt->fetchAll();
            }
            // 'agora' = relógio do servidor. O agente usa para calcular a
            // diferença com o relógio local e manter o tempo trabalhado
            // ancorado nas batidas, não no momento em que o app foi aberto.
            json_response([
                'ok'      => true,
                'agora'   => date('Y-m-d H:i:s'),
                'sessao'  => $sessao,
                'batidas' => $batidas,
            ]);
            break;

        case 'GET sessao/mes':
            // Espelho do mês do funcionário, com saldo diário e resumo do banco
            // de horas (mesma apuração do painel admin). Faltas/feriados também
            // entram como dias, com status 'falta' / 'falta_abonada' / 'feriado'.
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            $mes = $_GET['mes'] ?? date('Y-m');
            $inicio = $mes . '-01';
            $fim = date('Y-m-t', strtotime($inicio));
            $ap = bh_apurar((int)$u['id'], (int)$u['empresa_id'], $inicio, $fim);
            $apd = [];
            foreach ($ap['dias'] as $d) $apd[$d['data_ref']] = $d;
            $stmt = db()->prepare("SELECT data_ref, minutos_trabalhados, minutos_intervalo, minutos_extras, minutos_ociosos, status FROM dot_sessoes WHERE usuario_id=? AND data_ref BETWEEN ? AND ?");
            $stmt->execute([$u['id'], $inicio, $fim]);
            $dias = [];
            foreach ($stmt->fetchAll() as $s) {
                $d = $apd[$s['data_ref']] ?? null;
                $s['carga']    = $d ? $d['carga'] : null;
                $s['saldo']    = $d ? $d['saldo'] : null;
                $s['situacao'] = $d ? $d['situacao'] : null;
                $dias[$s['data_ref']] = $s;
            }
            foreach ($apd as $data => $d) {
                if (isset($dias[$data])) continue; // dia sem sessão: falta/abonada/feriado
                $dias[$data] = [
                    'data_ref' => $data,
                    'minutos_trabalhados' => $d['minutos_trabalhados'],
                    'minutos_intervalo' => 0,
                    'minutos_extras' => $d['minutos_extras'],
                    'minutos_ociosos' => $d['minutos_ociosos'],
                    'status' => $d['situacao'] === 'Feriado' ? 'feriado'
                              : ($d['situacao'] === 'Falta abonada' ? 'falta_abonada' : 'falta'),
                    'carga' => $d['carga'], 'saldo' => $d['saldo'], 'situacao' => $d['situacao'],
                ];
            }
            krsort($dias); // mais recente primeiro (como o ORDER BY DESC anterior)
            json_response(['ok'=>true, 'mes'=>$mes, 'dias'=>array_values($dias),
                'resumo'=>[
                    'minutos_a_favor'   => $ap['total_positivo'],
                    'minutos_em_debito' => $ap['total_negativo'],
                    'saldo_minutos'     => $ap['saldo_periodo'],
                ]]);
            break;

        case 'POST justificativa':
            // Funcionário envia justificativa de ausência/atraso OU correção de ponto.
            // Aceita JSON (sem anexo) ou multipart/form-data (com comprovação em $_FILES['anexo']).
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            $in = get_input();
            try {
                $anexo = jus_processar_upload($_FILES['anexo'] ?? null, (int)$u['empresa_id'], (int)$u['id']);
                $id = jus_criar((int)$u['empresa_id'], (int)$u['id'], [
                    'categoria'        => $in['categoria'] ?? 'justificativa',
                    'tipo'             => $in['tipo'] ?? 'outro',
                    'data_ref'         => $in['data_ref'] ?? date('Y-m-d'),
                    'batida_tipo'      => $in['batida_tipo'] ?? null,
                    'horario_correto'  => $in['horario_correto'] ?? null,
                    'motivo'           => $in['motivo'] ?? '',
                ], $anexo);
            } catch (RuntimeException $e) {
                json_response(['ok'=>false,'erro'=>$e->getMessage()], 400);
            }
            json_response(['ok'=>true, 'id'=>$id, 'mensagem'=>'Solicitação enviada para aprovação do gestor.']);
            break;

        case 'GET justificativas/minhas':
            // Lista as solicitações do próprio funcionário com status
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            $itens = jus_listar_do_funcionario((int)$u['id'], 50);
            $out = array_map(function($j){
                return [
                    'id'=>(int)$j['id'], 'categoria'=>$j['categoria'], 'tipo'=>$j['tipo'],
                    'tipo_label'=>jus_label_tipo($j['categoria']==='correcao' ? ($j['batida_tipo'] ?: 'esquecimento') : $j['tipo']),
                    'data_ref'=>$j['data_ref'], 'batida_tipo'=>$j['batida_tipo'], 'horario_correto'=>$j['horario_correto'],
                    'motivo'=>$j['motivo'], 'tem_anexo'=>!empty($j['anexo_arquivo']),
                    'status'=>$j['status'], 'solicitado_em'=>$j['solicitado_em'],
                    'decidido_em'=>$j['decidido_em'], 'motivo_decisao'=>$j['motivo_decisao'],
                ];
            }, $itens);
            json_response(['ok'=>true, 'itens'=>$out]);
            break;

        case 'GET escala':
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            if (!$u['escala_id']) json_response(['ok'=>true,'escala'=>null]);
            $stmt = db()->prepare("SELECT * FROM dot_escalas WHERE id=? AND empresa_id=?");
            $stmt->execute([$u['escala_id'], $u['empresa_id']]);
            $esc = $stmt->fetch() ?: null;
            if ($esc) {
                // Aplica a jornada do funcionário no dia atual (0=domingo..6=sábado).
                // Almoço é por duração (sem horário fixo): zera intervalo_inicio/fim.
                $dia = jornada_dia((int)$u['id'], (int)date('w'));
                if ($dia) {
                    $esc['dia_semana'] = (int)$dia['dia_semana'];
                    $esc['trabalha_hoje'] = (int)$dia['trabalha'];
                    $esc['almoco_minutos'] = (int)$dia['almoco_minutos'];
                    // Almoço é por duração — sem horário fixo. Strings vazias (não null)
                    // para não quebrar agents antigos que fazem fatiamento [:5].
                    $esc['intervalo_inicio'] = '';
                    $esc['intervalo_fim'] = '';
                    if ((int)$dia['trabalha'] === 1) {
                        $esc['entrada'] = $dia['entrada'] ?: $esc['entrada'];
                        $esc['saida'] = $dia['saida'] ?: $esc['saida'];
                        if ($dia['carga_minutos'] !== null) $esc['carga_diaria_minutos'] = (int)$dia['carga_minutos'];
                    }
                }
            }
            json_response(['ok'=>true,'escala'=>$esc]);
            break;

        case 'GET config':
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            $stmt = db()->prepare("SELECT chave, valor FROM dot_config WHERE empresa_id=?");
            $stmt->execute([$u['empresa_id']]);
            $cfg = [];
            foreach ($stmt->fetchAll() as $r) $cfg[$r['chave']] = $r['valor'];
            // (object) força "{}" quando a empresa não tem nenhuma configuração.
            // Sem isso o PHP serializa array vazio como "[]" e o agente quebra
            // ao chamar .get() num objeto que virou lista.
            json_response(['ok'=>true,'config'=>(object)$cfg]);
            break;

        case 'POST heartbeat':
            $u = autenticar_token(bearer_token());
            if (!$u) json_response(['ok'=>false,'erro'=>'Token inválido'],401);
            json_response(['ok'=>true, 'server_time'=>date('Y-m-d H:i:s')]);
            break;

        // ---------- VALIDAÇÃO PÚBLICA (resposta JSON) ----------
        // Identificada pelo qr_token do CRP (único por batida). O NSR sozinho
        // é sequencial POR EMPRESA, logo não identifica a batida sem o token.
        case 'GET validar':
            $qr = trim((string)($_GET['t'] ?? ''));
            if ($qr === '') json_response(['ok'=>false,'erro'=>'Token de validação (t) obrigatório'], 400);
            $stmt = db()->prepare("SELECT b.*, u.nome_completo, u.matricula, e.razao_social, e.cnpj
                FROM dot_crp c
                JOIN dot_batidas b ON b.id=c.batida_id
                JOIN dot_usuarios u ON u.id=b.usuario_id
                JOIN dot_empresas e ON e.id=b.empresa_id
                WHERE c.qr_token=? LIMIT 1");
            $stmt->execute([$qr]);
            $bf = $stmt->fetch();
            if (!$bf) json_response(['ok'=>false,'erro'=>'Comprovante não encontrado'], 404);
            // Recalcula hash para confirmar integridade
            $recalc = repp_hash_batida([
                'nsr' => $bf['nsr'], 'empresa_id' => $bf['empresa_id'], 'usuario_id' => $bf['usuario_id'],
                'cpf_snapshot' => $bf['cpf_snapshot'], 'pis_snapshot' => $bf['pis_snapshot'],
                'tipo' => $bf['tipo'], 'momento' => $bf['momento'],
            ], $bf['hash_anterior']);
            $integro = ($recalc === $bf['hash_registro']);
            json_response([
                'ok' => true,
                'autentico' => $integro,
                'batida' => [
                    'nsr' => $bf['nsr'], 'tipo' => $bf['tipo'], 'momento' => $bf['momento'],
                    'hash_registro' => $bf['hash_registro'], 'hash_anterior' => $bf['hash_anterior'],
                    'hash_alg' => $bf['hash_alg'] ?? 'SHA-256',
                    'nome_completo' => $bf['nome_completo'], 'matricula' => $bf['matricula'],
                    'razao_social' => $bf['razao_social'], 'cnpj' => $bf['cnpj'],
                ],
                'hash_recalculado' => $recalc,
            ]);
            break;

        // ---------- AUTO-UPDATE DO AGENT DESKTOP ----------
        // Público (sem token): o agent checa a versão logo no boot, antes mesmo de
        // depender de uma sessão válida. Só expõe versão + URL pública do executável.
        case 'GET agent/versao':
            $exe = __DIR__ . '/../downloads/DOT-ON-Agent.exe';
            if (!is_file($exe)) json_response(['ok'=>false,'erro'=>'Executável indisponível'], 503);
            $mf = agent_manifest();
            $scheme = ((($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') == 443)) ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'dot-on.com.br';
            json_response([
                'ok'          => true,
                'versao'      => $mf['versao'],
                'obrigatoria' => $mf['obrigatoria'],
                'notas'       => $mf['notas'],
                'url'         => "$scheme://$host/app/api/agent/download",
                'tamanho'     => filesize($exe),
                'sha256'      => agent_exe_sha256($exe),
            ]);
            break;

        case 'GET agent/download':
            $exe = __DIR__ . '/../downloads/DOT-ON-Agent.exe';
            if (!is_file($exe)) json_response(['ok'=>false,'erro'=>'Executável indisponível'], 503);
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="DOT-ON-Agent.exe"');
            header('Content-Length: ' . filesize($exe));
            header('X-Content-Type-Options: nosniff');
            readfile($exe);
            exit;

        case 'GET ping':
        case 'GET ':
            json_response(['ok'=>true,'app'=>'dot-on','version'=>'1.0','server_time'=>date('c')]);
            break;

        default:
            json_response(['ok'=>false,'erro'=>'Rota não encontrada','rota'=>"$method $path"], 404);
    }
} catch (Throwable $e) {
    error_log("[DOT-ON API] " . $e->getMessage());
    json_response(['ok'=>false,'erro'=>'Erro interno'], 500);
}
