<?php
/**
 * DOT-ON · Camada de faturamento SaaS (regra de negócio)
 * ------------------------------------------------------
 * Orquestra a integração com o Asaas: assinatura de uma empresa,
 * sincronização das cobranças e liberação de acesso ao painel.
 *
 * Princípios:
 *  - O status é SEMPRE derivado das cobranças gravadas localmente
 *    (billing_reavaliar_status), nunca do "último evento recebido".
 *  - Toda cobrança gravada vem da API do Asaas (sync ou re-consulta no
 *    webhook) — o payload do webhook não é confiável por si só.
 *  - Sandbox e produção não se misturam (coluna `ambiente`).
 *
 * Status (dot_empresas.assinatura_status / dot_assinaturas.status):
 *   trial    → em período de teste, sem assinatura
 *   pending  → assinou, aguardando pagamento
 *   active   → em dia (período pago vigente)
 *   overdue  → há cobrança vencida e não paga
 *   canceled → assinatura cancelada (acesso até o fim do período pago)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/asaas.php';

// Dias de carência após o vencimento antes de bloquear o acesso ao painel.
if (!defined('BILLING_CARENCIA_DIAS')) define('BILLING_CARENCIA_DIAS', 10);

const BILLING_PAGOS     = ['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH'];
const BILLING_EM_ABERTO = ['PENDING', 'OVERDUE', 'AWAITING_RISK_ANALYSIS'];

/** Data de hoje (Y-m-d) no fuso do negócio, independente do php.ini. */
function billing_hoje(): string {
    return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}

/** Soma dias a uma data Y-m-d. */
function billing_data_mais_dias(string $data, int $dias): string {
    return (new DateTime(substr($data, 0, 10)))->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d');
}

/** Diferença em dias (b - a) entre duas datas Y-m-d. */
function billing_dias_entre(string $a, string $b): int {
    return (int)(new DateTime(substr($a, 0, 10)))->diff(new DateTime(substr($b, 0, 10)))->format('%r%a');
}

/* ============================================================
 * Schema (migração preguiçosa e idempotente)
 * ============================================================ */

/**
 * Garante que as tabelas/colunas de faturamento existam.
 * Caminho rápido: UMA consulta ao information_schema; só roda DDL se faltar algo.
 * Lança exceção se o DDL falhar (o gating do painel trata como fail-open).
 */
function billing_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $pdo = db();

    $esperado = [
        'dot_billing_config.legado_migrado_em',
        'dot_assinaturas.ambiente',
        'dot_assinaturas.pago_ate',
        'dot_assinaturas.carencia_desde',
        'dot_pagamentos.asaas_subscription_id',
        'dot_pagamentos.ambiente',
        'dot_empresas.assinatura_status',
    ];
    $existentes = [];
    try {
        $rows = $pdo->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ('dot_billing_config','dot_assinaturas','dot_pagamentos','dot_empresas')
              AND COLUMN_NAME IN ('legado_migrado_em','ambiente','pago_ate','carencia_desde','asaas_subscription_id','assinatura_status')")
            ->fetchAll(PDO::FETCH_COLUMN);
        $existentes = array_flip($rows);
    } catch (Throwable $e) { /* cai no DDL abaixo */ }

    $falta = array_filter($esperado, fn($c) => !isset($existentes[$c]));
    if (!$falta) { $done = true; return; }

    $pdo->exec("CREATE TABLE IF NOT EXISTS dot_billing_config (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ambiente ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
        api_key TEXT NULL,
        webhook_token VARCHAR(80) NULL,
        valor_plano DECIMAL(10,2) NOT NULL DEFAULT 49.90,
        plano_nome VARCHAR(60) NOT NULL DEFAULT 'DOT-ON Profissional',
        ciclo VARCHAR(20) NOT NULL DEFAULT 'MONTHLY',
        dias_trial INT NOT NULL DEFAULT 30,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        legado_migrado_em DATETIME NULL,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dot_assinaturas (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT UNSIGNED NOT NULL,
        ambiente VARCHAR(12) NULL,
        asaas_customer_id VARCHAR(64) NULL,
        asaas_subscription_id VARCHAR(64) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        valor DECIMAL(10,2) NOT NULL DEFAULT 0,
        ciclo VARCHAR(20) NOT NULL DEFAULT 'MONTHLY',
        forma_pagamento VARCHAR(20) NOT NULL DEFAULT 'UNDEFINED',
        proximo_vencimento DATE NULL,
        pago_ate DATE NULL,
        carencia_desde DATE NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_empresa (empresa_id),
        KEY idx_sub (asaas_subscription_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dot_pagamentos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT UNSIGNED NOT NULL,
        assinatura_id INT UNSIGNED NULL,
        asaas_payment_id VARCHAR(64) NOT NULL,
        asaas_subscription_id VARCHAR(64) NULL,
        ambiente VARCHAR(12) NULL,
        valor DECIMAL(10,2) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
        forma VARCHAR(20) NULL,
        vencimento DATE NULL,
        pago_em DATETIME NULL,
        url_fatura TEXT NULL,
        url_boleto TEXT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_payment (asaas_payment_id),
        KEY idx_empresa (empresa_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Colunas adicionadas depois da 1ª versão (instalações que já tinham as tabelas).
    $addCol = function (string $tabela, string $coluna, string $ddl) use ($pdo) {
        $st = $pdo->query("SHOW COLUMNS FROM `$tabela` LIKE " . $pdo->quote($coluna));
        if (!$st->fetch()) $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN $ddl");
    };
    $addCol('dot_billing_config', 'legado_migrado_em', "legado_migrado_em DATETIME NULL");
    $addCol('dot_assinaturas', 'ambiente', "ambiente VARCHAR(12) NULL AFTER empresa_id");
    $addCol('dot_assinaturas', 'pago_ate', "pago_ate DATE NULL AFTER proximo_vencimento");
    $addCol('dot_assinaturas', 'carencia_desde', "carencia_desde DATE NULL AFTER pago_ate");
    $addCol('dot_pagamentos', 'asaas_subscription_id', "asaas_subscription_id VARCHAR(64) NULL AFTER asaas_payment_id");
    $addCol('dot_pagamentos', 'ambiente', "ambiente VARCHAR(12) NULL AFTER asaas_subscription_id");
    $addCol('dot_empresas', 'assinatura_status', "assinatura_status VARCHAR(20) NOT NULL DEFAULT 'trial'");

    // Semente da config
    $tem = $pdo->query("SELECT 1 FROM dot_billing_config LIMIT 1")->fetch();
    if (!$tem) {
        $pdo->exec("INSERT INTO dot_billing_config (ambiente, valor_plano, plano_nome, ciclo, dias_trial, ativo)
                    VALUES ('sandbox', 49.90, 'DOT-ON Profissional', 'MONTHLY', 30, 1)");
    }

    billing_migrar_empresas_legadas();
    $done = true;
}

/**
 * Roda UMA vez, na ativação do faturamento: toda empresa que já existia e ainda
 * não tem assinatura ganha um trial novo de `dias_trial` dias a partir de hoje
 * (quem já tinha trial mais longo mantém). Evita bloquear de surpresa clientes
 * antigos no primeiro acesso após o deploy.
 */
function billing_migrar_empresas_legadas(): void {
    $pdo = db();
    $cfg = $pdo->query("SELECT id, dias_trial, legado_migrado_em FROM dot_billing_config ORDER BY id LIMIT 1")->fetch();
    if (!$cfg || !empty($cfg['legado_migrado_em'])) return;

    $dias = (int)$cfg['dias_trial'] > 0 ? (int)$cfg['dias_trial'] : 30;
    $novoFim = billing_data_mais_dias(billing_hoje(), $dias);
    $semAssinatura = "id NOT IN (SELECT empresa_id FROM dot_assinaturas WHERE ambiente IS NOT NULL)";

    $pdo->prepare("UPDATE dot_empresas SET trial_expira = ?
        WHERE (trial_expira IS NULL OR trial_expira < ?) AND $semAssinatura")->execute([$novoFim, $novoFim]);
    $pdo->exec("UPDATE dot_empresas SET assinatura_status = 'trial' WHERE $semAssinatura");
    $pdo->prepare("UPDATE dot_billing_config SET legado_migrado_em = NOW() WHERE id = ?")->execute([$cfg['id']]);
    error_log("DOT-ON billing: ativação — empresas sem assinatura receberam trial até $novoFim");
}

/* ============================================================
 * Configuração
 * ============================================================ */

/** Config global de faturamento (linha única). Cacheado no request. */
function billing_config(bool $forcarReleitura = false): array {
    static $cache = null;
    if ($cache !== null && !$forcarReleitura) return $cache;
    billing_ensure_schema();
    $row = db()->query("SELECT * FROM dot_billing_config ORDER BY id LIMIT 1")->fetch();
    $cache = $row ?: [
        'ambiente' => 'sandbox', 'api_key' => '', 'webhook_token' => '',
        'valor_plano' => 49.90, 'plano_nome' => 'DOT-ON Profissional',
        'ciclo' => 'MONTHLY', 'dias_trial' => 30, 'ativo' => 1,
    ];
    return $cache;
}

/** Grava a config (upsert na linha única). Limpa o cache. Trata troca de ambiente. */
function billing_salvar_config(array $campos): void {
    billing_ensure_schema();
    $pdo = db();
    $row = $pdo->query("SELECT id, ambiente FROM dot_billing_config ORDER BY id LIMIT 1")->fetch();

    $cols = ['ambiente','api_key','webhook_token','valor_plano','plano_nome','ciclo','dias_trial','ativo'];
    $set  = []; $vals = [];
    foreach ($cols as $c) {
        if (array_key_exists($c, $campos)) { $set[] = "$c = ?"; $vals[] = $campos[$c]; }
    }
    if (!$set) return;

    if ($row) {
        $vals[] = $row['id'];
        $pdo->prepare("UPDATE dot_billing_config SET " . implode(',', $set) . " WHERE id = ?")->execute($vals);
    } else {
        $keys = array_keys(array_intersect_key($campos, array_flip($cols)));
        $ph   = implode(',', array_fill(0, count($keys), '?'));
        $pdo->prepare("INSERT INTO dot_billing_config (" . implode(',', $keys) . ") VALUES ($ph)")
            ->execute(array_values(array_intersect_key($campos, array_flip($cols))));
    }
    billing_config(true);

    if ($row && isset($campos['ambiente']) && $campos['ambiente'] !== $row['ambiente']) {
        billing_aplicar_troca_ambiente();
    }
}

/**
 * Após trocar sandbox ↔ produção: empresas cuja assinatura é do OUTRO ambiente
 * voltam a 'trial' (valem as regras do trial até assinarem no ambiente novo);
 * as do ambiente atual são recalculadas.
 */
function billing_aplicar_troca_ambiente(): void {
    foreach (db()->query("SELECT empresa_id, ambiente FROM dot_assinaturas")->fetchAll() as $r) {
        if ($r['ambiente'] === billing_ambiente()) {
            billing_reavaliar_status((int)$r['empresa_id']);
        } else {
            db()->prepare("UPDATE dot_empresas SET assinatura_status = 'trial' WHERE id = ?")->execute([$r['empresa_id']]);
        }
    }
}

function billing_valor_plano(): float {
    return (float)(billing_config()['valor_plano'] ?? 49.90);
}

function billing_ambiente(): string {
    return (billing_config()['ambiente'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
}

function billing_configurado(): bool {
    return !empty(billing_config()['api_key']);
}

/* ============================================================
 * Assinatura de uma empresa
 * ============================================================ */

/** Assinatura da empresa (ou null se nunca assinou). */
function billing_assinatura(int $empresaId): ?array {
    billing_ensure_schema();
    $st = db()->prepare("SELECT * FROM dot_assinaturas WHERE empresa_id = ? LIMIT 1");
    $st->execute([$empresaId]);
    return $st->fetch() ?: null;
}

/**
 * A assinatura local pertence ao ambiente (sandbox/produção) configurado agora?
 * IDs de cliente/assinatura do sandbox não existem na produção (e vice-versa),
 * então uma assinatura de outro ambiente é tratada como inexistente.
 */
function billing_assinatura_do_ambiente(?array $a): bool {
    return $a && ($a['ambiente'] ?? null) === billing_ambiente();
}

/** Empresa lida direto do banco (sem o cache estático de tenant_empresa()). */
function billing_empresa(int $empresaId): ?array {
    billing_ensure_schema();
    $st = db()->prepare("SELECT * FROM dot_empresas WHERE id = ?");
    $st->execute([$empresaId]);
    return $st->fetch() ?: null;
}

/** Últimas cobranças da empresa no ambiente atual (exclui as removidas no Asaas). */
function billing_pagamentos(int $empresaId, int $limite = 12): array {
    billing_ensure_schema();
    $st = db()->prepare("SELECT * FROM dot_pagamentos WHERE empresa_id = ? AND ambiente = ? AND status <> 'DELETED'
        ORDER BY vencimento DESC, id DESC LIMIT ?");
    $st->bindValue(1, $empresaId, PDO::PARAM_INT);
    $st->bindValue(2, billing_ambiente());
    $st->bindValue(3, $limite, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/**
 * Data do 1º vencimento ao assinar: nunca antes de hoje, nunca dentro do trial
 * e nunca dentro de um período já pago (re-assinatura após cancelamento).
 */
function billing_primeiro_vencimento(array $empresa, ?array $assinatura): string {
    $datas = [billing_hoje()];
    if (!empty($empresa['trial_expira'])) $datas[] = substr((string)$empresa['trial_expira'], 0, 10);
    if (!empty($assinatura['pago_ate']))  $datas[] = $assinatura['pago_ate'];
    return max($datas);
}

/**
 * Assina a empresa: cria (ou reaproveita) customer + subscription no Asaas
 * e persiste localmente. Idempotente por empresa:
 *  - lock no banco contra duplo clique;
 *  - antes de criar, procura no Asaas cliente/assinatura ativa com a mesma
 *    referência externa (cobre timeout/erro depois de o Asaas já ter criado).
 *
 * @param string $forma UNDEFINED (cliente escolhe) | BOLETO | PIX | CREDIT_CARD
 * @return array a assinatura persistida
 * @throws AsaasException
 */
function billing_assinar_empresa(int $empresaId, string $forma = 'UNDEFINED'): array {
    billing_ensure_schema();
    $pdo = db();
    $cfg = billing_config();

    $cliente = AsaasClient::fromConfig();
    if (!$cliente) throw new AsaasException('Gateway de pagamento não configurado. Fale com o suporte.', 0);

    $emp = billing_empresa($empresaId);
    if (!$emp) throw new AsaasException('Empresa não encontrada.', 0);

    // Prechecks: o Asaas exige CNPJ válido e e-mail. Mensagem clara em vez do erro cru do gateway.
    $cnpjLimpo = preg_replace('/\D/', '', (string)($emp['cnpj'] ?? ''));
    if (strlen($cnpjLimpo) !== 14) {
        throw new AsaasException('O CNPJ da empresa está incompleto/inválido. Corrija no cadastro da empresa antes de assinar.', 0);
    }
    if (empty($emp['email_contato']) || !filter_var($emp['email_contato'], FILTER_VALIDATE_EMAIL)) {
        throw new AsaasException('Cadastre um e-mail de contato válido da empresa antes de assinar.', 0);
    }

    $lock = 'doton_billing_assinar_' . $empresaId;
    $ok = (int)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($lock) . ", 15)")->fetchColumn();
    if ($ok !== 1) throw new AsaasException('Já existe uma assinatura sendo criada. Aguarde alguns segundos e atualize a página.', 0);

    try {
        $assinatura = billing_assinatura($empresaId);
        $mesmoAmb   = billing_assinatura_do_ambiente($assinatura);
        $ref        = 'empresa:' . $empresaId;

        // Já existe assinatura viva neste ambiente? Não recria — só ressincroniza.
        if ($mesmoAmb && $assinatura['asaas_subscription_id'] && $assinatura['status'] !== 'canceled') {
            try { billing_sincronizar_assinatura($empresaId); } catch (Throwable $e) {}
            return billing_assinatura($empresaId);
        }

        // 1) Customer: o local (mesmo ambiente) → o que já existir no Asaas → novo.
        $customerId = $mesmoAmb ? ($assinatura['asaas_customer_id'] ?? null) : null;
        if (!$customerId) {
            $achados = $cliente->listarClientesPorReferencia($ref);
            $customerId = $achados[0]['id'] ?? null;
        }
        if (!$customerId) {
            $c = $cliente->criarCliente([
                'name'        => $emp['razao_social'] ?: $emp['nome_fantasia'],
                'cpfCnpj'     => $cnpjLimpo,
                'email'       => $emp['email_contato'],
                'mobilePhone' => preg_replace('/\D/', '', (string)($emp['telefone'] ?? '')) ?: null,
                'externalReference' => $ref,
            ]);
            $customerId = $c['id'] ?? null;
            if (!$customerId) throw new AsaasException('Asaas não retornou o ID do cliente.', 0);
        }

        // 2) Subscription: adota uma ATIVA já existente (ex.: criada antes de um
        //    timeout) ou cria. Período já pago só conta se for do mesmo ambiente.
        $valor = billing_valor_plano();
        $sub = null;
        foreach ($cliente->listarAssinaturasPorReferencia($ref) as $s) {
            if (empty($s['deleted']) && ($s['status'] ?? '') === 'ACTIVE' && ($s['customer'] ?? '') === $customerId) { $sub = $s; break; }
        }
        if ($sub) {
            $vencimento = $sub['nextDueDate'] ?? billing_hoje();
        } else {
            $vencimento = billing_primeiro_vencimento($emp, $mesmoAmb ? $assinatura : null);
            $sub = $cliente->criarAssinatura([
                'customer'    => $customerId,
                'billingType' => $forma,
                'value'       => $valor,
                'nextDueDate' => $vencimento,
                'cycle'       => $cfg['ciclo'] ?? 'MONTHLY',
                'description' => ($cfg['plano_nome'] ?? 'DOT-ON') . ' — mensalidade',
                'externalReference' => $ref,
            ]);
        }
        $subId = $sub['id'] ?? null;
        if (!$subId) throw new AsaasException('Asaas não retornou o ID da assinatura.', 0);

        // 3) Persiste (upsert). Ao trocar de ambiente, pago_ate/carência antigos não valem.
        $pdo->prepare("INSERT INTO dot_assinaturas
                (empresa_id, ambiente, asaas_customer_id, asaas_subscription_id, status, valor, ciclo, forma_pagamento, proximo_vencimento, pago_ate, carencia_desde)
            VALUES (?,?,?,?,?,?,?,?,?,NULL,NULL)
            ON DUPLICATE KEY UPDATE
                -- Estes dois PRIMEIRO: as atribuições são sequenciais e o IF precisa
                -- comparar com o ambiente antigo, antes de ele ser sobrescrito.
                pago_ate       = IF(dot_assinaturas.ambiente <=> VALUES(ambiente), dot_assinaturas.pago_ate, NULL),
                carencia_desde = IF(dot_assinaturas.ambiente <=> VALUES(ambiente), dot_assinaturas.carencia_desde, NULL),
                ambiente = VALUES(ambiente),
                asaas_customer_id = VALUES(asaas_customer_id),
                asaas_subscription_id = VALUES(asaas_subscription_id),
                status = VALUES(status),
                valor = VALUES(valor),
                ciclo = VALUES(ciclo),
                forma_pagamento = VALUES(forma_pagamento),
                proximo_vencimento = VALUES(proximo_vencimento)")
            ->execute([$empresaId, billing_ambiente(), $customerId, $subId, 'pending', $sub['value'] ?? $valor,
                       $sub['cycle'] ?? ($cfg['ciclo'] ?? 'MONTHLY'), $sub['billingType'] ?? $forma, $vencimento]);

        // 4) Puxa a(s) cobrança(s) já geradas e recalcula o status
        try { billing_sincronizar_assinatura($empresaId); }
        catch (Throwable $e) { billing_reavaliar_status($empresaId); }

        return billing_assinatura($empresaId);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lock) . ")");
    }
}

/**
 * Cancela a assinatura no Asaas e localmente. Se o Asaas recusar, NÃO marca
 * como cancelada (senão o cliente continuaria sendo cobrado sem saber).
 * Também remove as cobranças em aberto dessa assinatura (sem cobrança órfã).
 * @throws AsaasException
 */
function billing_cancelar_assinatura(int $empresaId): void {
    $a = billing_assinatura($empresaId);
    if (!$a || !$a['asaas_subscription_id'] || $a['status'] === 'canceled') return;

    if (billing_assinatura_do_ambiente($a)) {
        $cli = AsaasClient::fromConfig();
        if (!$cli) throw new AsaasException('Gateway de pagamento não configurado. Fale com o suporte.', 0);
        try {
            $cli->cancelarAssinatura($a['asaas_subscription_id']);
        } catch (AsaasException $e) {
            if ($e->getCode() !== 404) throw $e; // 404 = já não existe no Asaas
        }
        // Garante que nenhuma cobrança em aberto dessa assinatura continue viva.
        $st = db()->prepare("SELECT asaas_payment_id FROM dot_pagamentos
            WHERE empresa_id = ? AND asaas_subscription_id = ? AND status IN ('PENDING','OVERDUE','AWAITING_RISK_ANALYSIS')");
        $st->execute([$empresaId, $a['asaas_subscription_id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            try {
                $cli->excluirPagamento($pid);
            } catch (AsaasException $e) {
                if ($e->getCode() !== 404) { error_log("DOT-ON cancelar: não removeu cobrança $pid: " . $e->getMessage()); continue; }
            }
            db()->prepare("UPDATE dot_pagamentos SET status = 'DELETED' WHERE asaas_payment_id = ?")->execute([$pid]);
        }
    }
    db()->prepare("UPDATE dot_assinaturas SET status='canceled' WHERE empresa_id = ?")->execute([$empresaId]);
    billing_reavaliar_status($empresaId);
}

/**
 * Ressincroniza com o Asaas: estado da assinatura + todas as cobranças.
 * Cobranças em aberto que sumiram da listagem (removidas no Asaas, webhook
 * perdido) são consultadas uma a uma. Lança AsaasException se o Asaas falhar.
 */
function billing_sincronizar_assinatura(int $empresaId): void {
    $a = billing_assinatura($empresaId);
    if (!$a || !$a['asaas_subscription_id'] || !billing_assinatura_do_ambiente($a)) return;
    $cli = AsaasClient::fromConfig();
    if (!$cli) return;

    $subId = $a['asaas_subscription_id'];

    // Assinatura removida/inativada direto no painel do Asaas → cancelada aqui.
    try {
        $sub = $cli->buscarAssinatura($subId);
        if (!empty($sub['deleted']) || ($sub['status'] ?? '') === 'INACTIVE') {
            db()->prepare("UPDATE dot_assinaturas SET status='canceled' WHERE empresa_id = ?")->execute([$empresaId]);
        }
    } catch (AsaasException $e) {
        if ($e->getCode() !== 404) throw $e;
        db()->prepare("UPDATE dot_assinaturas SET status='canceled' WHERE empresa_id = ?")->execute([$empresaId]);
    }

    $vistos = [];
    foreach ($cli->todosPagamentosDaAssinatura($subId) as $p) {
        billing_upsert_pagamento($empresaId, $p);
        $vistos[$p['id']] = true;
    }

    // Em aberto localmente mas fora da listagem → provavelmente removida no Asaas.
    $st = db()->prepare("SELECT asaas_payment_id FROM dot_pagamentos
        WHERE empresa_id = ? AND asaas_subscription_id = ? AND status IN ('PENDING','OVERDUE','AWAITING_RISK_ANALYSIS')");
    $st->execute([$empresaId, $subId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        if (isset($vistos[$pid])) continue;
        try {
            billing_upsert_pagamento($empresaId, $cli->buscarPagamento($pid));
        } catch (AsaasException $e) {
            if ($e->getCode() !== 404) throw $e;
            db()->prepare("UPDATE dot_pagamentos SET status = 'DELETED' WHERE asaas_payment_id = ?")->execute([$pid]);
        }
    }

    billing_reavaliar_status($empresaId);
}

/**
 * Upsert de uma cobrança na dot_pagamentos. O objeto deve vir da API do Asaas
 * (fonte da verdade). Cobrança removida no Asaas (deleted=true) vira DELETED.
 */
function billing_upsert_pagamento(int $empresaId, array $p): void {
    billing_ensure_schema();
    $pid = $p['id'] ?? null;
    if (!$pid) return;

    $status = (string)($p['status'] ?? 'PENDING');
    if (!empty($p['deleted'])) $status = 'DELETED';

    $assinatura = billing_assinatura($empresaId);
    $pagoEm = $p['clientPaymentDate'] ?? $p['paymentDate'] ?? $p['confirmedDate'] ?? null;
    $ambiente = ($assinatura && ($p['subscription'] ?? null) === $assinatura['asaas_subscription_id'] && !empty($assinatura['ambiente']))
        ? $assinatura['ambiente'] : billing_ambiente();

    db()->prepare("INSERT INTO dot_pagamentos
            (empresa_id, assinatura_id, asaas_payment_id, asaas_subscription_id, ambiente, valor, status, forma, vencimento, pago_em, url_fatura, url_boleto)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            asaas_subscription_id = COALESCE(VALUES(asaas_subscription_id), asaas_subscription_id),
            valor = VALUES(valor),
            status = VALUES(status),
            forma = VALUES(forma),
            vencimento = VALUES(vencimento),
            pago_em = VALUES(pago_em),
            url_fatura = COALESCE(VALUES(url_fatura), url_fatura),
            url_boleto = COALESCE(VALUES(url_boleto), url_boleto)")
        ->execute([
            $empresaId,
            $assinatura['id'] ?? null,
            $pid,
            $p['subscription'] ?? null,
            $ambiente,
            $p['value'] ?? 0,
            $status,
            $p['billingType'] ?? null,
            $p['dueDate'] ?? null,
            $pagoEm,
            $p['invoiceUrl'] ?? null,
            $p['bankSlipUrl'] ?? null,
        ]);
}

/** Quanto tempo uma cobrança cobre, conforme o ciclo da assinatura. */
function billing_intervalo_ciclo(string $ciclo): string {
    return [
        'WEEKLY' => '+1 week', 'BIWEEKLY' => '+2 weeks', 'MONTHLY' => '+1 month',
        'BIMONTHLY' => '+2 months', 'QUARTERLY' => '+3 months',
        'SEMIANNUALLY' => '+6 months', 'YEARLY' => '+1 year',
    ][$ciclo] ?? '+1 month';
}

/**
 * Recalcula o status da empresa a partir das cobranças locais (sem chamar o Asaas).
 * É a ÚNICA função que decide o status — webhook, cron e telas só a chamam.
 *
 * carencia_desde: 1º dia em que havia dinheiro devido e não pago. Só zera quando
 * a empresa fica em dia — NÃO zera ao cancelar/reassinar (senão cancelar e
 * assinar de novo renovaria a carência para sempre sem pagar).
 */
function billing_reavaliar_status(int $empresaId): void {
    $a = billing_assinatura($empresaId);
    if (!$a) return;

    $hoje = billing_hoje();
    // Só cobranças do mesmo ambiente da assinatura (sandbox não paga produção).
    $st = db()->prepare("SELECT status, vencimento, asaas_subscription_id FROM dot_pagamentos WHERE empresa_id = ? AND ambiente <=> ?");
    $st->execute([$empresaId, $a['ambiente']]);
    $pags = $st->fetchAll();

    // Período pago: maior (vencimento + 1 ciclo) entre as cobranças pagas.
    $intervalo = billing_intervalo_ciclo((string)$a['ciclo']);
    $pagoAte = null;
    foreach ($pags as $p) {
        if (in_array($p['status'], BILLING_PAGOS, true) && $p['vencimento']) {
            $fim = (new DateTime($p['vencimento']))->modify($intervalo)->format('Y-m-d');
            if ($pagoAte === null || $fim > $pagoAte) $pagoAte = $fim;
        }
    }

    // Cobranças em aberto da assinatura ATUAL (as de assinaturas antigas não contam).
    $vencidas = []; $abertas = [];
    foreach ($pags as $p) {
        if (!in_array($p['status'], BILLING_EM_ABERTO, true) || !$p['vencimento']) continue;
        if ($p['asaas_subscription_id'] && $p['asaas_subscription_id'] !== $a['asaas_subscription_id']) continue;
        $abertas[] = $p['vencimento'];
        // PENDING já vencido conta como atraso mesmo se o webhook OVERDUE não chegou.
        if ($p['status'] === 'OVERDUE' || $p['vencimento'] < $hoje) $vencidas[] = $p['vencimento'];
    }

    $emDia = $pagoAte !== null && $pagoAte >= $hoje;
    if ($a['status'] === 'canceled') {
        $status  = 'canceled';
        $proximo = null;
    } elseif ($vencidas) {
        $status  = 'overdue';
        $proximo = min($vencidas);
    } elseif ($emDia) {
        $status  = 'active';
        $proximo = $abertas ? min($abertas) : $pagoAte;
    } else {
        $status  = 'pending';
        $proximo = $abertas ? min($abertas) : ($pagoAte ?? $a['proximo_vencimento']);
    }

    // Carência: começa no 1º vencimento devido e não pago; só zera quando em dia.
    $carencia = $a['carencia_desde'] ?: null;
    if ($emDia && !$vencidas) {
        $carencia = null;
    } elseif ($status !== 'canceled') {
        $devidoDesde = $vencidas ? min($vencidas) : (($proximo && $proximo <= $hoje) ? $proximo : null);
        if ($devidoDesde !== null && ($carencia === null || $devidoDesde < $carencia)) $carencia = $devidoDesde;
    }

    db()->prepare("UPDATE dot_assinaturas SET status = ?, proximo_vencimento = ?, pago_ate = ?, carencia_desde = ? WHERE empresa_id = ?")
        ->execute([$status, $proximo, $pagoAte, $carencia, $empresaId]);
    db()->prepare("UPDATE dot_empresas SET assinatura_status = ? WHERE id = ?")->execute([$status, $empresaId]);
}

/* ============================================================
 * Gating de acesso
 * ============================================================ */

/**
 * Situação de acesso da empresa:
 *   ['liberado' => bool, 'motivo' => trial_expirado|inadimplente|cancelada|null,
 *    'dias_ate_bloqueio' => int|null]
 *
 * Libera se QUALQUER um valer:
 *   - sem assinatura (neste ambiente): trial válido, ou empresa sem data de trial
 *   - período pago vigente (inclusive após cancelar)
 *   - trial ainda válido
 *   - dentro da carência (BILLING_CARENCIA_DIAS dias desde o 1º vencimento não pago)
 *
 * Lança exceção se o schema não puder ser garantido — quem chama (layout) libera.
 */
function billing_situacao(array $empresa): array {
    billing_ensure_schema();
    $hoje  = billing_hoje();
    $trial = !empty($empresa['trial_expira']) ? substr((string)$empresa['trial_expira'], 0, 10) : null;
    $trialValido = $trial !== null && $trial >= $hoje;
    $livre = ['liberado' => true, 'motivo' => null, 'dias_ate_bloqueio' => null];
    $bloq  = fn(string $m) => ['liberado' => false, 'motivo' => $m, 'dias_ate_bloqueio' => 0];

    $a = billing_assinatura((int)$empresa['id']);
    if (!$a || !$a['asaas_subscription_id'] || !billing_assinatura_do_ambiente($a)) {
        return ($trial === null || $trialValido) ? $livre : $bloq('trial_expirado');
    }

    if (!empty($a['pago_ate']) && $a['pago_ate'] >= $hoje) return $livre;
    if ($trialValido) {
        // Ainda no trial: libera, mas informa quantos dias faltam (p/ avisos de pending).
        $inicio = $a['carencia_desde'] ?: $a['proximo_vencimento'];
        $dias = $inicio ? billing_dias_entre($hoje, billing_data_mais_dias($inicio, BILLING_CARENCIA_DIAS)) : null;
        return ['liberado' => true, 'motivo' => null, 'dias_ate_bloqueio' => $dias !== null ? max(0, $dias) : null];
    }
    if ($a['status'] === 'canceled') return $bloq('cancelada');

    // pending / overdue / active com período vencido: carência.
    $inicio = $a['carencia_desde'] ?: $a['proximo_vencimento'];
    if ($inicio) {
        $dias = billing_dias_entre($hoje, billing_data_mais_dias($inicio, BILLING_CARENCIA_DIAS));
        if ($dias >= 0) return ['liberado' => true, 'motivo' => null, 'dias_ate_bloqueio' => $dias];
    }
    return $bloq('inadimplente');
}

/** Atalho booleano (compatibilidade). */
function billing_acesso_liberado(array $empresa): bool {
    return billing_situacao($empresa)['liberado'];
}

/** Dias até o bloqueio (null se não está em pending/overdue). */
function billing_dias_ate_bloqueio(array $empresa): ?int {
    if (!in_array($empresa['assinatura_status'] ?? '', ['pending', 'overdue'], true)) return null;
    return billing_situacao($empresa)['dias_ate_bloqueio'];
}

/* ============================================================
 * Reconciliação (rede de segurança para webhooks perdidos)
 * ============================================================ */

/**
 * Ressincroniza todas as assinaturas vivas do ambiente atual com o Asaas.
 * Idempotente — pensada para rodar em cron diário.
 * @return array resumo {ok, processadas, erros}
 */
function billing_reconciliar(): array {
    billing_ensure_schema();
    if (!AsaasClient::fromConfig()) return ['ok' => false, 'erro' => 'gateway não configurado'];

    $st = db()->prepare("SELECT empresa_id FROM dot_assinaturas
        WHERE asaas_subscription_id IS NOT NULL AND status <> 'canceled' AND ambiente = ?");
    $st->execute([billing_ambiente()]);

    $ok = 0; $erros = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $empresaId) {
        try {
            billing_sincronizar_assinatura((int)$empresaId);
            $ok++;
        } catch (Throwable $e) {
            $erros++;
            error_log('DOT-ON billing_reconciliar empresa ' . $empresaId . ': ' . $e->getMessage());
        }
    }

    // Canceladas: só recalcula (o período pago expira com o tempo, sem chamar o Asaas).
    $st = db()->prepare("SELECT empresa_id FROM dot_assinaturas WHERE status = 'canceled' AND ambiente = ?");
    $st->execute([billing_ambiente()]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $empresaId) {
        billing_reavaliar_status((int)$empresaId);
    }

    return ['ok' => true, 'processadas' => $ok, 'erros' => $erros];
}
