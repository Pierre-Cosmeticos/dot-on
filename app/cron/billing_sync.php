<?php
/**
 * DOT-ON · Reconciliação de faturamento (rede de segurança p/ webhooks perdidos)
 * ------------------------------------------------------------------------------
 * Ressincroniza as cobranças de todas as assinaturas com o Asaas e reavalia o
 * status de cada empresa. Idempotente — seguro rodar quantas vezes quiser.
 *
 * Agende 1x/dia. Duas formas de invocar:
 *
 *   • CLI (recomendado, sem exposição HTTP):
 *       php /caminho/app/cron/billing_sync.php
 *
 *   • HTTP (cron do painel/cPanel que só dispara URL), protegido por token:
 *       GET https://dot-on.com.br/app/cron/billing_sync.php?token=<DOTON_CRON_TOKEN>
 *     Defina a variável de ambiente DOTON_CRON_TOKEN na hospedagem. Sem ela,
 *     o acesso HTTP é negado (o modo CLI continua funcionando).
 */

require_once __DIR__ . '/../includes/billing.php';

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

$res = billing_reconciliar();

if ($isCli) {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n");
} else {
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
}
