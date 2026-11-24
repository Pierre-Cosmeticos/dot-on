<?php
/**
 * DOT-ON · Webhook do Asaas
 * -------------------------
 * Recebe eventos de cobrança (PAYMENT_*) e de assinatura (SUBSCRIPTION_*),
 * grava a cobrança e RECALCULA o status da empresa a partir dos dados
 * (billing_reavaliar_status). Idempotente e tolerante a eventos fora de ordem.
 *
 * Configure no painel do Asaas apontando para:
 *   https://dot-on.com.br/app/api/asaas_webhook.php
 * com o "Token de autenticação" igual ao webhook_token salvo
 * em Faturamento (o Asaas o envia no header `asaas-access-token`).
 *
 * O token é OBRIGATÓRIO: sem ele qualquer pessoa poderia forjar um
 * "pagamento confirmado" e liberar uma empresa sem pagar.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/billing.php';

header('Content-Type: application/json; charset=utf-8');

// Log de depuração do webhook (app/logs é protegido por .htaccess). Nunca loga o token.
function wh_log(string $msg): void {
    $arq = __DIR__ . '/../logs/asaas_webhook.log';
    @file_put_contents($arq, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function wh_responder(int $http, array $corpo): void {
    http_response_code($http);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    wh_responder(405, ['ok' => false, 'erro' => 'Método não permitido']);
}

try {
    $cfg = billing_config();
} catch (Throwable $e) {
    wh_log('ERRO config: ' . $e->getMessage());
    wh_responder(503, ['ok' => false, 'erro' => 'indisponível']); // Asaas tenta de novo
}

$tokenEsperado = trim((string)($cfg['webhook_token'] ?? ''));
if ($tokenEsperado === '') {
    wh_log('503 webhook_token não configurado em Faturamento — evento recusado');
    wh_responder(503, ['ok' => false, 'erro' => 'Webhook não configurado']);
}
$tokenRecebido = (string)($_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '');
if (!hash_equals($tokenEsperado, $tokenRecebido)) {
    wh_log('401 token inválido (header ' . ($tokenRecebido === '' ? 'ausente' : 'diferente do salvo') . ')');
    wh_responder(401, ['ok' => false, 'erro' => 'Token inválido']);
}

$raw = file_get_contents('php://input');
$evt = json_decode((string)$raw, true);
if (!is_array($evt) || empty($evt['event'])) {
    wh_log('400 payload inválido: ' . substr((string)$raw, 0, 300));
    wh_responder(400, ['ok' => false, 'erro' => 'Payload inválido']);
}

$evento = (string)$evt['event'];

/** Descobre a empresa pela assinatura (mais confiável), referência externa ou customer. */
function wh_empresa(?string $subId, ?string $ref, ?string $customer): int {
    if ($subId) {
        $st = db()->prepare("SELECT empresa_id FROM dot_assinaturas WHERE asaas_subscription_id = ? LIMIT 1");
        $st->execute([$subId]);
        if ($id = (int)$st->fetchColumn()) return $id;
    }
    if ($ref && preg_match('/^empresa:(\d+)$/', $ref, $m)) {
        $st = db()->prepare("SELECT id FROM dot_empresas WHERE id = ?");
        $st->execute([(int)$m[1]]);
        if ($id = (int)$st->fetchColumn()) return $id;
    }
    if ($customer) {
        $st = db()->prepare("SELECT empresa_id FROM dot_assinaturas WHERE asaas_customer_id = ? LIMIT 1");
        $st->execute([$customer]);
        if ($id = (int)$st->fetchColumn()) return $id;
    }
    return 0;
}

$cli = AsaasClient::fromConfig();
if (!$cli) {
    wh_log('503 gateway sem chave de API — evento recusado');
    wh_responder(503, ['ok' => false, 'erro' => 'Gateway não configurado']);
}

try {
    if (strncmp($evento, 'PAYMENT_', 8) === 0) {
        $paymentId = (string)($evt['payment']['id'] ?? '');
        if ($paymentId === '') wh_responder(400, ['ok' => false, 'erro' => 'Payload sem payment.id']);

        // O payload é só um aviso: o estado real vem da API, com a chave do
        // ambiente ATUAL. Cobrança de outro ambiente (ex.: sandbox avisando a
        // produção) ou forjada não existe aqui → 404 → ignorada.
        try {
            $p = $cli->buscarPagamento($paymentId);
        } catch (AsaasException $e) {
            if ($e->getCode() !== 404) throw $e; // Asaas fora → 500 → ele reenvia
            // Removida de vez no Asaas: se a conhecemos, marca como removida.
            $st = db()->prepare("SELECT empresa_id FROM dot_pagamentos WHERE asaas_payment_id = ?");
            $st->execute([$paymentId]);
            if ($empresaId = (int)$st->fetchColumn()) {
                db()->prepare("UPDATE dot_pagamentos SET status='DELETED' WHERE asaas_payment_id = ?")->execute([$paymentId]);
                billing_reavaliar_status($empresaId);
                wh_log("OK evento=$evento payment=$paymentId empresa=$empresaId → não existe mais no Asaas (DELETED)");
            } else {
                wh_log("IGNORADO evento=$evento payment=$paymentId não existe neste ambiente do Asaas");
            }
            wh_responder(200, ['ok' => true, 'ignorado' => true]);
        }

        $empresaId = wh_empresa($p['subscription'] ?? null, $p['externalReference'] ?? null, $p['customer'] ?? null);
        if (!$empresaId) {
            // Cobrança que não é de assinatura DOT-ON (ex.: outra venda na mesma conta).
            wh_log("IGNORADO evento=$evento payment=$paymentId ref=" . ($p['externalReference'] ?? '-'));
            wh_responder(200, ['ok' => true, 'ignorado' => true]);
        }

        billing_upsert_pagamento($empresaId, $p);
        billing_reavaliar_status($empresaId);

        $emp = billing_empresa($empresaId);
        auditar(null, 'asaas_webhook', 'faturamento', $empresaId,
            ['evento' => $evento, 'payment' => $paymentId, 'status' => $p['status'] ?? null]);
        wh_log("OK evento=$evento payment=$paymentId empresa=$empresaId pagamento=" . (!empty($p['deleted']) ? 'DELETED' : ($p['status'] ?? '-'))
            . ' empresa_status=' . ($emp['assinatura_status'] ?? '-'));
        wh_responder(200, ['ok' => true]);
    }

    if (strncmp($evento, 'SUBSCRIPTION_', 13) === 0) {
        $subId = (string)($evt['subscription']['id'] ?? '');
        $empresaId = $subId !== '' ? wh_empresa($subId, null, null) : 0;
        $a = $empresaId ? billing_assinatura($empresaId) : null;

        // Só age sobre a assinatura ATUAL da empresa, e confirma na API que ela acabou.
        if ($a && $a['asaas_subscription_id'] === $subId && billing_assinatura_do_ambiente($a)) {
            try {
                $s = $cli->buscarAssinatura($subId);
                $acabou = !empty($s['deleted']) || ($s['status'] ?? '') === 'INACTIVE';
            } catch (AsaasException $e) {
                if ($e->getCode() !== 404) throw $e;
                $acabou = true;
            }
            if ($acabou) {
                db()->prepare("UPDATE dot_assinaturas SET status='canceled' WHERE empresa_id = ?")->execute([$empresaId]);
                billing_reavaliar_status($empresaId);
                auditar(null, 'asaas_webhook', 'faturamento', $empresaId, ['evento' => $evento, 'subscription' => $subId]);
                wh_log("OK evento=$evento subscription=$subId empresa=$empresaId → canceled");
                wh_responder(200, ['ok' => true]);
            }
        }
        wh_log("SEM AÇÃO evento=$evento subscription=" . ($subId ?: '-'));
        wh_responder(200, ['ok' => true]);
    }

    wh_log("SEM AÇÃO evento=$evento");
    wh_responder(200, ['ok' => true]);

} catch (Throwable $e) {
    // Erro inesperado (ex.: banco fora): 500 para o Asaas reenviar depois.
    // O cron de reconciliação também corrige qualquer evento perdido.
    wh_log('ERRO evento=' . $evento . ' ' . get_class($e) . ': ' . $e->getMessage());
    error_log('DOT-ON asaas_webhook erro: ' . $e->getMessage());
    wh_responder(500, ['ok' => false, 'erro' => 'erro interno']);
}
