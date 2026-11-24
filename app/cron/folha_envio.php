<?php
/**
 * DOT-ON · Envio automático da folha de ponto
 * -------------------------------------------
 * Manda, para as empresas com envio devido hoje, o e-mail com o link da folha
 * de ponto a cada destinatário cadastrado. Idempotente — pode rodar várias
 * vezes no dia (não repete envio; tenta de novo os e-mails que falharam).
 *
 * Agende 1x/dia pela manhã (ex.: 06:00). Rodar de hora em hora também serve
 * e faz as novas tentativas saírem mais cedo.
 *
 *   • CLI (recomendado):
 *       php /caminho/app/cron/folha_envio.php
 *
 *   • HTTP, protegido por token (mesma variável do faturamento):
 *       GET https://dot-on.com.br/app/cron/folha_envio.php?token=<DOTON_CRON_TOKEN>
 */

require_once __DIR__ . '/../includes/folha_envio.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $tokenCfg = trim((string)(getenv('DOTON_CRON_TOKEN') ?: ''));
    $tokenReq = (string)($_GET['token'] ?? '');
    if ($tokenCfg === '' || !hash_equals($tokenCfg, $tokenReq)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'acesso negado']);
        exit;
    }
}

@set_time_limit(0);
$res = folha_executar_agenda();

if ($isCli) {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n");
} else {
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
}
