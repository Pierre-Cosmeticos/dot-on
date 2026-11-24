<?php
/**
 * DOT-ON · Envio automático da folha de ponto
 * -------------------------------------------
 * O gestor cadastra e-mails (contador, RH, contas a pagar...) e escolhe a
 * frequência. Nos dias devidos, o cron manda a cada destinatário um LINK
 * para as folhas de ponto de todos os funcionários.
 *
 * Regras que não podem ser quebradas:
 *   • o e-mail não leva dado de funcionário (só empresa, período e link);
 *   • a página do link não mostra CPF nem PIS;
 *   • o token do link só existe em texto puro dentro do e-mail — no banco
 *     fica apenas o hash SHA-256 (por isso o envio NÃO usa dot_email_fila);
 *   • o mesmo envio agendado não sai duas vezes (chave_agenda única).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';

const FOLHA_FREQUENCIAS      = ['dias_mes', 'semanal', 'quinzenal', 'diario'];
const FOLHA_MAX_DESTINATARIOS = 10;   // por empresa
const FOLHA_MAX_MANUAIS_DIA   = 20;   // e-mails de "Enviar agora"/"Reenviar" por empresa por dia
const FOLHA_VALIDADE_DIAS     = 30;   // validade do link
const FOLHA_MAX_TENTATIVAS    = 3;    // tentativas de entrega de um envio agendado
const FOLHA_INTERVALO_TENTATIVA_H = 6; // horas mínimas entre duas tentativas do mesmo envio
const FOLHA_DIAS_RECUPERACAO  = 2;    // o cron recupera um envio devido há até N dias (cron que não rodou)
const FOLHA_DIAS_SEMANA       = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];

/* ============================== datas ============================== */

/** "Hoje" no fuso do sistema (o cron em CLI pode não ler o .user.ini). */
function folha_hoje(): string {
    return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}

function folha_agora(): string {
    return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
}

function folha_data_valida(string $d): bool {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

function folha_mais_dias(string $data, int $dias): string {
    return (new DateTime($data))->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d');
}

/** Normaliza a lista de dias do mês: inteiros únicos de 1 a 31, em ordem. */
function folha_normalizar_dias($dias): array {
    if (is_string($dias)) $dias = $dias === '' ? [] : explode(',', $dias);
    $out = [];
    foreach ((array)$dias as $d) {
        if (!is_scalar($d) || !preg_match('/^\s*\d{1,2}\s*$/', (string)$d)) continue;
        $d = (int)$d;
        if ($d >= 1 && $d <= 31) $out[$d] = $d;
    }
    sort($out);
    return array_values($out);
}

/**
 * O envio é devido em $hoje para esta configuração?
 *  - dias_mes : dia marcado; dia que não existe no mês (29/30/31) cai no último dia
 *  - semanal  : dia da semana escolhido (0=domingo .. 6=sábado)
 *  - quinzenal: dias 1 e 16
 *  - diario   : sempre
 */
function folha_devido(array $cfg, string $hoje): bool {
    if (empty($cfg['ativo'])) return false;
    $dt  = new DateTime($hoje);
    $dia = (int)$dt->format('j');
    switch ($cfg['frequencia'] ?? '') {
        case 'diario':
            return true;
        case 'quinzenal':
            return $dia === 1 || $dia === 16;
        case 'semanal':
            return (int)$dt->format('w') === (int)($cfg['dia_semana'] ?? -1);
        case 'dias_mes':
            $dias = folha_normalizar_dias($cfg['dias'] ?? '');
            if (in_array($dia, $dias, true)) return true;
            $ultimo = (int)$dt->format('t');
            if ($dia === $ultimo) {
                foreach ($dias as $d) if ($d > $ultimo) return true;
            }
            return false;
    }
    return false;
}

/**
 * Período [inicio, fim] coberto por um envio feito em $hoje. Sempre termina
 * na véspera (o dia do envio ainda não acabou).
 *  - dias_mes / diario: do dia 1 do mês da véspera até a véspera
 *  - semanal          : os 7 dias anteriores
 *  - quinzenal        : a quinzena que fechou (1–15 ou 16–fim do mês)
 */
function folha_periodo(string $frequencia, string $hoje): array {
    $vespera = folha_mais_dias($hoje, -1);
    switch ($frequencia) {
        case 'semanal':
            return [folha_mais_dias($hoje, -7), $vespera];
        case 'quinzenal':
            $diaV = (int)substr($vespera, 8, 2);
            $ini  = substr($vespera, 0, 8) . ($diaV <= 15 ? '01' : '16');
            return [$ini, $vespera];
        default:
            return [substr($vespera, 0, 8) . '01', $vespera];
    }
}

/* ============================== schema ============================== */

function folha_garantir_schema(): void {
    static $ok = false;
    if ($ok) return;
    $n = (int)db()->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('dot_folha_envio_config','dot_folha_envio_destinatarios','dot_folha_envios')")->fetchColumn();
    if ($n < 3) {
        db()->exec("CREATE TABLE IF NOT EXISTS dot_folha_envio_config (
            empresa_id     INT UNSIGNED NOT NULL PRIMARY KEY,
            ativo          TINYINT(1)   NOT NULL DEFAULT 0,
            frequencia     VARCHAR(20)  NOT NULL DEFAULT 'dias_mes',
            dias           VARCHAR(120) NOT NULL DEFAULT '',
            dia_semana     TINYINT      NOT NULL DEFAULT 1,
            atualizado_por INT UNSIGNED NULL,
            atualizado_em  DATETIME     NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS dot_folha_envio_destinatarios (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id  INT UNSIGNED NOT NULL,
            email       VARCHAR(190) NOT NULL,
            nome        VARCHAR(120) NULL,
            criado_por  INT UNSIGNED NULL,
            criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_folha_dest (empresa_id, email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS dot_folha_envios (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id     INT UNSIGNED NOT NULL,
            email          VARCHAR(190) NOT NULL,
            nome           VARCHAR(120) NULL,
            data_envio     DATE         NOT NULL,
            inicio         DATE         NOT NULL,
            fim            DATE         NOT NULL,
            origem         VARCHAR(10)  NOT NULL DEFAULT 'agendado',
            chave_agenda   CHAR(64)     NULL,
            token_hash     CHAR(64)     NOT NULL,
            expira_em      DATETIME     NOT NULL,
            cancelado_em   DATETIME     NULL,
            email_enviado  TINYINT(1)   NOT NULL DEFAULT 0,
            email_erro     VARCHAR(255) NULL,
            tentativas     INT          NOT NULL DEFAULT 0,
            ultima_tentativa_em DATETIME NULL,
            aberto_em      DATETIME     NULL,
            ultimo_acesso  DATETIME     NULL,
            acessos        INT          NOT NULL DEFAULT 0,
            criado_por     INT UNSIGNED NULL,
            criado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_folha_token (token_hash),
            UNIQUE KEY uk_folha_agenda (chave_agenda),
            KEY idx_folha_empresa (empresa_id, data_envio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $ok = true;
}

/* ====================== configuração e destinatários ====================== */

function folha_config(int $empresaId): array {
    folha_garantir_schema();
    $st = db()->prepare("SELECT * FROM dot_folha_envio_config WHERE empresa_id = ?");
    $st->execute([$empresaId]);
    $c = $st->fetch();
    if (!$c) $c = ['empresa_id' => $empresaId, 'ativo' => 0, 'frequencia' => 'dias_mes', 'dias' => '', 'dia_semana' => 1];
    $c['ativo']      = (int)$c['ativo'];
    $c['dia_semana'] = (int)$c['dia_semana'];
    return $c;
}

/**
 * Salva a configuração. Lança InvalidArgumentException (mensagem exibível)
 * se os dados forem inválidos.
 */
function folha_salvar_config(int $empresaId, bool $ativo, string $frequencia, $dias, $diaSemana, ?int $userId): array {
    folha_garantir_schema();
    if (!in_array($frequencia, FOLHA_FREQUENCIAS, true)) {
        throw new InvalidArgumentException('Escolha uma frequência válida.');
    }
    $dias      = folha_normalizar_dias($dias);
    $diaSemana = (int)$diaSemana;
    if ($diaSemana < 0 || $diaSemana > 6) $diaSemana = 1;
    if ($ativo && $frequencia === 'dias_mes' && !$dias) {
        throw new InvalidArgumentException('Marque pelo menos um dia do mês para o envio.');
    }
    db()->prepare("INSERT INTO dot_folha_envio_config (empresa_id, ativo, frequencia, dias, dia_semana, atualizado_por, atualizado_em)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE ativo=VALUES(ativo), frequencia=VALUES(frequencia), dias=VALUES(dias),
                dia_semana=VALUES(dia_semana), atualizado_por=VALUES(atualizado_por), atualizado_em=VALUES(atualizado_em)")
        ->execute([$empresaId, $ativo ? 1 : 0, $frequencia, implode(',', $dias), $diaSemana, $userId, folha_agora()]);
    auditar($userId, 'folha_envio_config', 'empresa', $empresaId,
        ['ativo' => $ativo ? 1 : 0, 'frequencia' => $frequencia, 'dias' => $dias, 'dia_semana' => $diaSemana]);
    return folha_config($empresaId);
}

function folha_destinatarios(int $empresaId): array {
    folha_garantir_schema();
    $st = db()->prepare("SELECT * FROM dot_folha_envio_destinatarios WHERE empresa_id = ? ORDER BY id");
    $st->execute([$empresaId]);
    return $st->fetchAll();
}

/** Remove caracteres de controle (evita injeção de cabeçalho no e-mail) e limita o tamanho. */
function folha_limpar_nome(string $nome): string {
    $nome = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $nome) ?? '');
    return mb_substr($nome, 0, 120);
}

function folha_adicionar_destinatario(int $empresaId, string $email, string $nome, ?int $userId): void {
    folha_garantir_schema();
    $email = mb_strtolower(trim($email));
    if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Informe um e-mail válido.');
    }
    $nome = folha_limpar_nome($nome);
    $atuais = folha_destinatarios($empresaId);
    foreach ($atuais as $d) {
        if ($d['email'] === $email) throw new InvalidArgumentException('Este e-mail já está na lista.');
    }
    if (count($atuais) >= FOLHA_MAX_DESTINATARIOS) {
        throw new InvalidArgumentException('Limite de ' . FOLHA_MAX_DESTINATARIOS . ' e-mails atingido. Remova um para adicionar outro.');
    }
    db()->prepare("INSERT INTO dot_folha_envio_destinatarios (empresa_id, email, nome, criado_por, criado_em) VALUES (?,?,?,?,?)")
        ->execute([$empresaId, $email, $nome !== '' ? $nome : null, $userId, folha_agora()]);
    auditar($userId, 'folha_envio_destinatario_add', 'empresa', $empresaId, ['email' => $email, 'nome' => $nome]);
}

function folha_remover_destinatario(int $empresaId, int $id, ?int $userId): bool {
    folha_garantir_schema();
    $st = db()->prepare("SELECT email FROM dot_folha_envio_destinatarios WHERE id = ? AND empresa_id = ?");
    $st->execute([$id, $empresaId]);
    $email = $st->fetchColumn();
    if ($email === false) return false;
    db()->prepare("DELETE FROM dot_folha_envio_destinatarios WHERE id = ? AND empresa_id = ?")->execute([$id, $empresaId]);
    // E-mails agendados que ainda não saíram (falharam) não devem ser tentados de novo para quem foi removido.
    db()->prepare("UPDATE dot_folha_envios SET cancelado_em = ? WHERE empresa_id = ? AND email = ? AND email_enviado = 0 AND cancelado_em IS NULL")
        ->execute([folha_agora(), $empresaId, $email]);
    auditar($userId, 'folha_envio_destinatario_del', 'empresa', $empresaId, ['email' => $email]);
    return true;
}

/* ============================== envios ============================== */

function folha_base_url(): string {
    static $u = null;
    if ($u === null) {
        $cfg = require __DIR__ . '/../config/app.php';
        $u = rtrim((string)$cfg['base_url'], '/');
    }
    return $u;
}

function folha_link(string $token): string {
    return folha_base_url() . '/folha.php?t=' . $token;
}

function folha_fmt_data(string $d): string {
    return date('d/m/Y', strtotime(substr($d, 0, 10)));
}

function folha_empresa(int $empresaId): ?array {
    $st = db()->prepare("SELECT * FROM dot_empresas WHERE id = ?");
    $st->execute([$empresaId]);
    return $st->fetch() ?: null;
}

function folha_nome_empresa(array $empresa): string {
    return (string)(($empresa['nome_fantasia'] ?? '') ?: ($empresa['razao_social'] ?? ''));
}

/** E-mail do envio: empresa, período e link. Nenhum dado de funcionário. */
function folha_email_template(array $empresa, string $inicio, string $fim, string $link, string $expiraEm): array {
    $nomeEmp = folha_nome_empresa($empresa);
    $periodo = folha_fmt_data($inicio) . ' a ' . folha_fmt_data($fim);
    $validade = folha_fmt_data($expiraEm);
    $assunto = "Folha de ponto · $nomeEmp · $periodo";
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='font-family:Segoe UI,Arial,sans-serif;background:#f1f5f9;padding:20px;margin:0;color:#1e293b'>
<div style='max-width:600px;margin:auto;background:white;border-radius:14px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)'>
<div style='background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;padding:26px;text-align:center'>
<h1 style='margin:0;font-size:24px'>DOT-ON</h1>
<p style='margin:6px 0 0;opacity:.95'>Folha de ponto</p></div>
<div style='padding:30px'>
<p>Olá,</p>
<p>A folha de ponto dos funcionários de <strong>{$e($nomeEmp)}</strong> está disponível.</p>
<div style='background:#f1f5f9;border-left:4px solid #0284c7;padding:14px 18px;border-radius:6px;margin:18px 0'>
<strong>Período:</strong> {$e($periodo)}</div>
<p style='text-align:center;margin:26px 0'><a href='{$e($link)}' style='display:inline-block;background:#0284c7;color:white;padding:13px 34px;border-radius:8px;text-decoration:none;font-weight:700'>Abrir folhas de ponto</a></p>
<p style='font-size:13px;color:#64748b'>O link é pessoal, abre sem senha e vale até {$e($validade)}. Não o encaminhe a quem não deve ver a folha de ponto.</p>
<p style='font-size:13px;color:#64748b'>Você recebeu este e-mail porque a empresa cadastrou seu endereço para receber a folha de ponto. Para deixar de receber, fale com a empresa.</p>
</div>
<div style='background:#0f172a;color:#94a3b8;padding:14px;text-align:center;font-size:12px'>DOT-ON · Controle de Ponto Digital</div>
</div></body></html>";
    $texto = "Folha de ponto de $nomeEmp\nPeríodo: $periodo\n\nAbrir: $link\n\nO link é pessoal, abre sem senha e vale até $validade.";
    return [$assunto, $html, $texto];
}

/**
 * Gera um token novo para o envio, grava o hash e manda o e-mail.
 * Usado no primeiro envio e nos reenvios (o token anterior deixa de valer).
 * Retorna [ok, mensagem].
 */
function folha_entregar(int $envioId): array {
    $st = db()->prepare("SELECT * FROM dot_folha_envios WHERE id = ?");
    $st->execute([$envioId]);
    $envio = $st->fetch();
    if (!$envio) return [false, 'Envio não encontrado'];
    if (!empty($envio['cancelado_em'])) return [false, 'Link cancelado'];
    $empresa = folha_empresa((int)$envio['empresa_id']);
    if (!$empresa) return [false, 'Empresa não encontrada'];

    $token  = bin2hex(random_bytes(32));
    $expira = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))
        ->modify('+' . FOLHA_VALIDADE_DIAS . ' days')->format('Y-m-d H:i:s');
    db()->prepare("UPDATE dot_folha_envios SET token_hash = ?, expira_em = ?, tentativas = tentativas + 1, ultima_tentativa_em = ? WHERE id = ?")
        ->execute([hash('sha256', $token), $expira, folha_agora(), $envioId]);

    [$assunto, $html, $texto] = folha_email_template($empresa, $envio['inicio'], $envio['fim'], folha_link($token), $expira);
    try {
        [$ok, $msg] = email_enviar_smtp($envio['email'], (string)($envio['nome'] ?: $envio['email']), $assunto, $html, $texto);
    } catch (Throwable $e) {
        $ok = false; $msg = $e->getMessage();
    }
    db()->prepare("UPDATE dot_folha_envios SET email_enviado = ?, email_erro = ? WHERE id = ?")
        ->execute([$ok ? 1 : 0, $ok ? null : mb_substr((string)$msg, 0, 255), $envioId]);
    return [(bool)$ok, (string)$msg];
}

/**
 * Cria o registro de um envio e entrega o e-mail.
 * Agendado: idempotente por (empresa, e-mail, data) — se já existe, retorna null.
 * Retorna ['id'=>..., 'ok'=>bool, 'msg'=>string] ou null (já enviado hoje).
 */
function folha_criar_envio(int $empresaId, string $email, ?string $nome, string $dataEnvio, string $inicio, string $fim, string $origem, ?int $userId = null): ?array {
    folha_garantir_schema();
    $chave = $origem === 'agendado' ? hash('sha256', "$empresaId|$email|$dataEnvio") : null;
    // token_hash provisório (único); o definitivo é gravado em folha_entregar().
    $st = db()->prepare("INSERT IGNORE INTO dot_folha_envios
            (empresa_id, email, nome, data_envio, inicio, fim, origem, chave_agenda, token_hash, expira_em, criado_por, criado_em)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$empresaId, $email, $nome, $dataEnvio, $inicio, $fim, $origem, $chave,
        hash('sha256', random_bytes(32)), folha_agora(), $userId, folha_agora()]);
    if ($st->rowCount() < 1) return null;
    $id = (int)db()->lastInsertId();
    [$ok, $msg] = folha_entregar($id);
    return ['id' => $id, 'ok' => $ok, 'msg' => $msg];
}

/**
 * Quanto do limite diário de envios manuais (enviar agora / reenviar) a empresa
 * já usou hoje. Conta os e-mails entregues; tentativas que falharam pesam 1/3,
 * para uma falha de SMTP não travar o dia inteiro nem permitir insistir sem fim.
 */
function folha_manuais_hoje(int $empresaId): int {
    $st = db()->prepare("SELECT COALESCE(SUM(email_enviado = 1),0) AS entregues, COUNT(*) AS total FROM dot_folha_envios
        WHERE empresa_id = ? AND origem = 'manual' AND data_envio = ?");
    $st->execute([$empresaId, folha_hoje()]);
    $r = $st->fetch();
    $entregues = (int)$r['entregues'];
    return $entregues + intdiv((int)$r['total'] - $entregues, 3);
}

/** Executa $fn com uma trava por empresa: dois envios manuais simultâneos não furam o limite. */
function folha_com_trava(int $empresaId, callable $fn) {
    $nome = 'doton_folha_manual_' . $empresaId;
    $st = db()->prepare("SELECT GET_LOCK(?, 15)");
    $st->execute([$nome]);
    if ((int)$st->fetchColumn() !== 1) {
        throw new InvalidArgumentException('Outro envio desta empresa está em andamento. Aguarde um instante e tente de novo.');
    }
    try {
        return $fn();
    } finally {
        db()->prepare("SELECT RELEASE_LOCK(?)")->execute([$nome]);
    }
}

/** Falha em que não adianta tentar o próximo destinatário (servidor de e-mail fora do ar). */
function folha_falha_de_conexao(string $msg): bool {
    return strpos($msg, 'Conexão SMTP falhou') === 0 || strpos($msg, 'SMTP não configurado') === 0;
}

/**
 * Recalcula os minutos das sessões do período a partir das batidas, uma vez,
 * na hora do envio. A página do link só lê (não grava nada a cada abertura).
 */
function folha_recalcular_periodo(int $empresaId, string $inicio, string $fim): void {
    try {
        require_once __DIR__ . '/banco_horas.php';
        $st = db()->prepare("SELECT s.id FROM dot_sessoes s JOIN dot_usuarios u ON u.id = s.usuario_id
            WHERE u.empresa_id = ? AND s.data_ref BETWEEN ? AND ?");
        $st->execute([$empresaId, $inicio, $fim]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            try { jus_recalcular_sessao((int)$sid); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        error_log('DOT-ON folha_envio: recálculo do período falhou (' . $e->getMessage() . ')');
    }
}

/**
 * "Enviar agora": mesmo envio da agenda, fora da agenda, para todos os
 * destinatários. Retorna ['enviados'=>n, 'falhas'=>n, 'inicio'=>..., 'fim'=>...].
 */
function folha_enviar_agora(int $empresaId, ?int $userId): array {
    return folha_com_trava($empresaId, function () use ($empresaId, $userId) {
        $dests = folha_destinatarios($empresaId);
        if (!$dests) throw new InvalidArgumentException('Cadastre pelo menos um e-mail antes de enviar.');
        if (folha_manuais_hoje($empresaId) + count($dests) > FOLHA_MAX_MANUAIS_DIA) {
            throw new InvalidArgumentException('Limite diário de envios manuais atingido. Tente novamente amanhã.');
        }
        $cfg  = folha_config($empresaId);
        $hoje = folha_hoje();
        [$inicio, $fim] = folha_periodo($cfg['frequencia'], $hoje);
        folha_recalcular_periodo($empresaId, $inicio, $fim);
        $res = ['enviados' => 0, 'falhas' => 0, 'inicio' => $inicio, 'fim' => $fim];
        $semServidor = false;
        foreach ($dests as $d) {
            if ($semServidor) { $res['falhas']++; continue; } // não insiste com o servidor fora do ar
            $r = folha_criar_envio($empresaId, $d['email'], $d['nome'], $hoje, $inicio, $fim, 'manual', $userId);
            if ($r && $r['ok']) { $res['enviados']++; continue; }
            $res['falhas']++;
            if ($r && folha_falha_de_conexao($r['msg'])) $semServidor = true;
        }
        auditar($userId, 'folha_envio_manual', 'empresa', $empresaId, $res);
        return $res;
    });
}

function folha_envio_da_empresa(int $empresaId, int $envioId): ?array {
    folha_garantir_schema();
    $st = db()->prepare("SELECT * FROM dot_folha_envios WHERE id = ? AND empresa_id = ?");
    $st->execute([$envioId, $empresaId]);
    return $st->fetch() ?: null;
}

/** Cancela o link de um envio (deixa de abrir na hora). */
function folha_cancelar_envio(int $empresaId, int $envioId, ?int $userId): bool {
    $envio = folha_envio_da_empresa($empresaId, $envioId);
    if (!$envio || !empty($envio['cancelado_em'])) return false;
    db()->prepare("UPDATE dot_folha_envios SET cancelado_em = ? WHERE id = ? AND empresa_id = ?")
        ->execute([folha_agora(), $envioId, $empresaId]);
    auditar($userId, 'folha_envio_cancelar', 'folha_envio', $envioId, ['email' => $envio['email']]);
    return true;
}

/**
 * Reenvia um envio: sai um envio novo (manual), com o mesmo período, para o
 * mesmo destinatário. O link antigo só é cancelado depois que o novo e-mail
 * foi entregue — se a entrega falhar, o link antigo continua valendo.
 * Retorna ['id'=>..., 'ok'=>bool, 'msg'=>string].
 */
function folha_reenviar(int $empresaId, int $envioId, ?int $userId): array {
    return folha_com_trava($empresaId, function () use ($empresaId, $envioId, $userId) {
        $envio = folha_envio_da_empresa($empresaId, $envioId);
        if (!$envio) throw new InvalidArgumentException('Envio não encontrado.');
        if (folha_manuais_hoje($empresaId) + 1 > FOLHA_MAX_MANUAIS_DIA) {
            throw new InvalidArgumentException('Limite diário de envios manuais atingido. Tente novamente amanhã.');
        }
        $r = folha_criar_envio($empresaId, $envio['email'], $envio['nome'], folha_hoje(), $envio['inicio'], $envio['fim'], 'manual', $userId)
            ?? ['id' => 0, 'ok' => false, 'msg' => 'Não foi possível criar o envio'];
        // Cancela o que não vale mais: o antigo se o novo saiu; o novo se falhou.
        $cancelar = $r['ok'] ? (empty($envio['cancelado_em']) ? $envioId : 0) : (int)$r['id'];
        if ($cancelar) {
            db()->prepare("UPDATE dot_folha_envios SET cancelado_em = ? WHERE id = ? AND empresa_id = ?")
                ->execute([folha_agora(), $cancelar, $empresaId]);
        }
        auditar($userId, 'folha_envio_reenviar', 'folha_envio', $envioId, ['email' => $envio['email'], 'ok' => $r['ok']]);
        return $r;
    });
}

function folha_historico(int $empresaId, int $limite = 50): array {
    folha_garantir_schema();
    $limite = max(1, min(200, $limite));
    $st = db()->prepare("SELECT * FROM dot_folha_envios WHERE empresa_id = ? ORDER BY id DESC LIMIT $limite");
    $st->execute([$empresaId]);
    return $st->fetchAll();
}

/* ============================== cron ============================== */

/**
 * Dia de envio a processar para uma configuração: hoje, se for devido; senão o
 * dia devido mais recente dos últimos FOLHA_DIAS_RECUPERACAO dias (cron que não
 * rodou, empresa que estava bloqueada na hora). Nunca recupera um dia anterior
 * à última alteração da configuração. Retorna null se não há nada a enviar.
 */
function folha_dia_a_processar(array $cfg, string $hoje): ?string {
    if (folha_devido($cfg, $hoje)) return $hoje;
    $desde = !empty($cfg['atualizado_em']) ? substr((string)$cfg['atualizado_em'], 0, 10) : $hoje;
    for ($i = 1; $i <= FOLHA_DIAS_RECUPERACAO; $i++) {
        $d = folha_mais_dias($hoje, -$i);
        if ($d < $desde) break;
        if (folha_devido($cfg, $d)) return $d;
    }
    return null;
}

/**
 * Rotina diária (idempotente — pode rodar várias vezes por dia):
 *  1) para cada empresa com envio ativo e devido, cria os envios que faltam;
 *  2) tenta de novo os envios agendados recentes cujo e-mail falhou.
 */
function folha_executar_agenda(?string $hoje = null): array {
    folha_garantir_schema();
    $hoje = $hoje ?: folha_hoje();
    $res = ['data' => $hoje, 'empresas' => 0, 'enviados' => 0, 'falhas' => 0, 'ja_enviados' => 0, 'bloqueadas' => 0, 'reenviados' => 0, 'acima_do_limite' => 0];

    $lock = db()->query("SELECT GET_LOCK('doton_folha_envio', 0)")->fetchColumn();
    if ((int)$lock !== 1) { $res['erro'] = 'outra execução em andamento'; return $res; }

    try {
        $cfgs = db()->query("SELECT c.* FROM dot_folha_envio_config c
            JOIN dot_empresas e ON e.id = c.empresa_id
            WHERE c.ativo = 1 AND e.ativo = 1 ORDER BY c.empresa_id")->fetchAll();
        foreach ($cfgs as $cfg) {
            $dia = folha_dia_a_processar($cfg, $hoje);
            if ($dia === null) continue;
            $empresaId = (int)$cfg['empresa_id'];
            if (!folha_empresa_liberada($empresaId)) { $res['bloqueadas']++; continue; }
            $dests = folha_destinatarios($empresaId);
            if (!$dests) continue;
            $res['empresas']++;
            [$inicio, $fim] = folha_periodo($cfg['frequencia'], $dia);

            // Quem já tem envio agendado deste dia (não repete) e teto diário por
            // empresa (trocar a lista entre execuções não multiplica os e-mails).
            $st = db()->prepare("SELECT email FROM dot_folha_envios WHERE empresa_id = ? AND origem = 'agendado' AND data_envio = ?");
            $st->execute([$empresaId, $dia]);
            $jaTem = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
            $recalculado = false;
            foreach ($dests as $d) {
                if (isset($jaTem[$d['email']])) { $res['ja_enviados']++; continue; }
                if (count($jaTem) >= FOLHA_MAX_DESTINATARIOS) { $res['acima_do_limite']++; continue; }
                try {
                    if (!$recalculado) { folha_recalcular_periodo($empresaId, $inicio, $fim); $recalculado = true; }
                    $r = folha_criar_envio($empresaId, $d['email'], $d['nome'], $dia, $inicio, $fim, 'agendado');
                    $jaTem[$d['email']] = 1;
                    if ($r === null) $res['ja_enviados']++;
                    elseif ($r['ok']) $res['enviados']++;
                    else $res['falhas']++;
                } catch (Throwable $e) {
                    $res['falhas']++;
                    error_log('DOT-ON folha_envio empresa ' . $empresaId . ': ' . $e->getMessage());
                }
            }
        }

        // Nova tentativa para e-mails agendados que falharam nos últimos 3 dias.
        // Só para quem continua na lista de uma empresa com o envio ligado, e
        // com intervalo mínimo entre tentativas (cobre também os desta execução).
        $limite = (new DateTime(folha_agora()))->modify('-' . FOLHA_INTERVALO_TENTATIVA_H . ' hours')->format('Y-m-d H:i:s');
        $st = db()->prepare("SELECT v.id, v.empresa_id FROM dot_folha_envios v
            JOIN dot_empresas e ON e.id = v.empresa_id AND e.ativo = 1
            JOIN dot_folha_envio_config c ON c.empresa_id = v.empresa_id AND c.ativo = 1
            JOIN dot_folha_envio_destinatarios d ON d.empresa_id = v.empresa_id AND d.email = v.email
            WHERE v.origem = 'agendado' AND v.email_enviado = 0 AND v.cancelado_em IS NULL
              AND v.tentativas < ? AND v.data_envio >= ?
              AND (v.ultima_tentativa_em IS NULL OR v.ultima_tentativa_em < ?)");
        $st->execute([FOLHA_MAX_TENTATIVAS, folha_mais_dias($hoje, -3), $limite]);
        foreach ($st->fetchAll() as $row) {
            if (!folha_empresa_liberada((int)$row['empresa_id'])) continue;
            try {
                [$ok] = folha_entregar((int)$row['id']);
                if ($ok) $res['reenviados']++; else $res['falhas']++;
            } catch (Throwable $e) {
                $res['falhas']++;
                error_log('DOT-ON folha_envio reenvio ' . $row['id'] . ': ' . $e->getMessage());
            }
        }
    } finally {
        db()->query("SELECT RELEASE_LOCK('doton_folha_envio')");
    }
    return $res;
}

/**
 * Empresa bloqueada no faturamento não recebe envio agendado. Se a checagem
 * do faturamento falhar, não bloqueia (mesma regra fail-open do painel).
 */
function folha_empresa_liberada(int $empresaId): bool {
    try {
        require_once __DIR__ . '/billing.php';
        $emp = billing_empresa($empresaId);
        if (!$emp) return false;
        return billing_acesso_liberado($emp);
    } catch (Throwable $e) {
        error_log('DOT-ON folha_envio: checagem de faturamento falhou (' . $e->getMessage() . ')');
        return true;
    }
}

/* ========================= página do link ========================= */

/**
 * Valida o token do link. Retorna o envio (com a empresa em 'empresa') ou
 * null se o link não existe, venceu, foi cancelado ou a empresa está inativa.
 */
function folha_envio_por_token(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    folha_garantir_schema();
    $st = db()->prepare("SELECT * FROM dot_folha_envios WHERE token_hash = ?");
    $st->execute([hash('sha256', $token)]);
    $envio = $st->fetch();
    if (!$envio) return null;
    if (!empty($envio['cancelado_em'])) return null;
    if (empty($envio['email_enviado'])) return null; // token provisório ou entrega que falhou
    if ($envio['expira_em'] < folha_agora()) return null;
    $empresa = folha_empresa((int)$envio['empresa_id']);
    if (!$empresa || empty($empresa['ativo'])) return null;
    // Empresa bloqueada no faturamento perde o acesso ao painel; os links seguem a mesma regra.
    if (!folha_empresa_liberada((int)$envio['empresa_id'])) return null;
    $envio['empresa'] = $empresa;
    return $envio;
}

function folha_registrar_acesso(int $envioId): void {
    $agora = folha_agora();
    db()->prepare("UPDATE dot_folha_envios SET acessos = acessos + 1, ultimo_acesso = ?, aberto_em = COALESCE(aberto_em, ?) WHERE id = ?")
        ->execute([$agora, $agora, $envioId]);
}

/**
 * Dados da folha de todos os funcionários no período — SEM CPF e SEM PIS.
 * Entram: funcionários ativos e os desligados que têm registro no período
 * (ainda precisam ser pagos; a apuração deles para no último dia registrado,
 * para não inventar faltas depois do desligamento). Gestores/RH/admin só
 * entram se bateram ponto no período. Do abono só sai "Falta abonada" /
 * "Feriado": o tipo (atestado, consulta médica...) e o motivo não são expostos.
 * Somente leitura: as sessões são recalculadas na hora do envio.
 */
function folha_dados(int $empresaId, string $inicio, string $fim): array {
    require_once __DIR__ . '/banco_horas.php';
    batidas_garantir_cancelamento();

    $st = db()->prepare("SELECT u.id, u.nome_completo, u.matricula, u.funcao, u.perfil, u.ativo,
            e.nome AS escala_nome, e.entrada, e.saida,
            (SELECT COUNT(*) FROM dot_sessoes s WHERE s.usuario_id = u.id AND s.data_ref BETWEEN ? AND ?) AS sess_periodo
        FROM dot_usuarios u
        LEFT JOIN dot_escalas e ON e.id = u.escala_id
        WHERE u.empresa_id = ? AND u.perfil <> 'super_admin'
        ORDER BY u.nome_completo, u.id");
    $st->execute([$inicio, $fim, $empresaId]);

    $out = [];
    foreach ($st->fetchAll() as $u) {
        $temPeriodo = (int)$u['sess_periodo'] > 0;
        if (!(int)$u['ativo'] && !$temPeriodo) continue;
        if ($u['perfil'] !== 'funcionario' && !$temPeriodo) continue;

        $uid = (int)$u['id'];
        $ap  = bh_apurar($uid, $empresaId, $inicio, $fim, false);

        $s = db()->prepare("SELECT * FROM dot_sessoes WHERE usuario_id = ? AND data_ref BETWEEN ? AND ? ORDER BY data_ref");
        $s->execute([$uid, $inicio, $fim]);
        $sessoes = $s->fetchAll();

        $b = db()->prepare("SELECT tipo, momento FROM dot_batidas
            WHERE usuario_id = ? AND DATE(momento) BETWEEN ? AND ? AND COALESCE(cancelada,0) = 0 ORDER BY momento");
        $b->execute([$uid, $inicio, $fim]);
        $batidas = [];
        foreach ($b->fetchAll() as $bt) $batidas[substr($bt['momento'], 0, 10)][] = $bt;

        // Desligado: nada depois do último dia com registro no período.
        $corte = null;
        if (!(int)$u['ativo'] && $sessoes) $corte = $sessoes[count($sessoes) - 1]['data_ref'];

        $linhas = [];
        foreach ($sessoes as $se) {
            $linhas[$se['data_ref']]['sessao'] = [
                'minutos_trabalhados' => (int)$se['minutos_trabalhados'],
                'minutos_extras'      => (int)$se['minutos_extras'],
            ];
        }
        $faltas = 0; $abonadas = 0; $saldo = 0;
        foreach ($ap['dias'] as $d) {
            if ($corte !== null && $d['data_ref'] > $corte) continue;
            $linhas[$d['data_ref']]['ap'] = [
                'situacao'            => (string)$d['situacao'],
                'minutos_trabalhados' => (int)$d['minutos_trabalhados'],
                'saldo'               => (int)$d['saldo'],
            ];
            $saldo += (int)$d['saldo'];
            if ($d['situacao'] === 'Falta') $faltas++;
            elseif ($d['situacao'] === 'Falta abonada') $abonadas++;
        }
        ksort($linhas);
        $diasTrab = 0;
        foreach ($sessoes as $se) if ((int)$se['minutos_trabalhados'] > 0) $diasTrab++;

        $out[] = [
            'id'        => $uid,
            'nome'      => (string)$u['nome_completo'],
            'matricula' => (string)($u['matricula'] ?? ''),
            'funcao'    => (string)($u['funcao'] ?? ''),
            'escala'    => $u['escala_nome'] ? ($u['escala_nome'] . ' (' . substr((string)$u['entrada'], 0, 5) . '–' . substr((string)$u['saida'], 0, 5) . ')') : '',
            'ativo'     => (int)$u['ativo'],
            'dias_trabalhados'  => $diasTrab,
            'min_trabalhados'   => (int)array_sum(array_column($sessoes, 'minutos_trabalhados')),
            'min_extras'        => (int)array_sum(array_column($sessoes, 'minutos_extras')),
            'faltas'            => $faltas,
            'faltas_abonadas'   => $abonadas,
            'saldo'             => $saldo,
            'linhas'            => $linhas,
            'batidas'           => $batidas,
        ];
    }
    return $out;
}
