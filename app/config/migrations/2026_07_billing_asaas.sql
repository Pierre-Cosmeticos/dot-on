-- ============================================================
-- DOT-ON · Faturamento SaaS (integração Asaas)
-- Migração idempotente — pode rodar mais de uma vez sem quebrar.
-- Também é aplicada automaticamente por billing_ensure_schema()
-- (app/includes/billing.php) na primeira visita ao painel de
-- Faturamento. Este arquivo serve como referência e para rodar
-- manualmente via cliente MySQL, se preferir.
-- ============================================================

-- 1) Configuração global do gateway (linha única, como dot_smtp_config).
--    A chave da API do Asaas NUNCA fica em código — só aqui, no banco.
CREATE TABLE IF NOT EXISTS dot_billing_config (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ambiente        ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    api_key         TEXT NULL,                       -- access_token do Asaas
    webhook_token   VARCHAR(80) NULL,                -- valida os webhooks recebidos
    valor_plano     DECIMAL(10,2) NOT NULL DEFAULT 49.90,
    plano_nome      VARCHAR(60) NOT NULL DEFAULT 'DOT-ON Profissional',
    ciclo           VARCHAR(20) NOT NULL DEFAULT 'MONTHLY',
    dias_trial      INT NOT NULL DEFAULT 30,
    ativo           TINYINT(1) NOT NULL DEFAULT 1,
    legado_migrado_em DATETIME NULL,                 -- quando as empresas antigas ganharam o trial de ativação
    atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Assinatura de uma empresa (1 empresa : 1 assinatura ativa).
CREATE TABLE IF NOT EXISTS dot_assinaturas (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empresa_id             INT UNSIGNED NOT NULL,
    ambiente               VARCHAR(12) NULL,     -- sandbox|production (IDs de um não valem no outro)
    asaas_customer_id      VARCHAR(64) NULL,
    asaas_subscription_id  VARCHAR(64) NULL,
    status                 VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|active|overdue|canceled (calculado)
    valor                  DECIMAL(10,2) NOT NULL DEFAULT 0,
    ciclo                  VARCHAR(20) NOT NULL DEFAULT 'MONTHLY',
    forma_pagamento        VARCHAR(20) NOT NULL DEFAULT 'UNDEFINED', -- BOLETO|PIX|CREDIT_CARD|UNDEFINED
    proximo_vencimento     DATE NULL,            -- cobrança em aberto mais antiga (base da carência)
    pago_ate               DATE NULL,            -- fim do período pago (vale mesmo após cancelar)
    carencia_desde         DATE NULL,            -- 1º vencimento não pago (não reinicia ao reassinar)
    criado_em              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_empresa (empresa_id),
    KEY idx_sub (asaas_subscription_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Cobranças/pagamentos individuais gerados pela assinatura.
CREATE TABLE IF NOT EXISTS dot_pagamentos (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empresa_id         INT UNSIGNED NOT NULL,
    assinatura_id      INT UNSIGNED NULL,
    asaas_payment_id   VARCHAR(64) NOT NULL,
    asaas_subscription_id VARCHAR(64) NULL,
    ambiente           VARCHAR(12) NULL,
    valor              DECIMAL(10,2) NOT NULL DEFAULT 0,
    status             VARCHAR(30) NOT NULL DEFAULT 'PENDING', -- PENDING|CONFIRMED|RECEIVED|OVERDUE|REFUNDED|CHARGEBACK_REQUESTED|DELETED
    forma              VARCHAR(20) NULL,
    vencimento         DATE NULL,
    pago_em            DATETIME NULL,
    url_fatura         TEXT NULL,        -- invoiceUrl (página do Asaas p/ pagar)
    url_boleto         TEXT NULL,        -- bankSlipUrl
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_payment (asaas_payment_id),
    KEY idx_empresa (empresa_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Coluna de status de assinatura na empresa (denormalizada p/ gating rápido).
--    (Se a coluna já existir, este ALTER falha com "Duplicate column" — pode ignorar.)
--    'trial' | 'pending' | 'active' | 'overdue' | 'canceled'
ALTER TABLE dot_empresas
    ADD COLUMN assinatura_status VARCHAR(20) NOT NULL DEFAULT 'trial';

-- Semente da configuração (só insere se a tabela estiver vazia).
INSERT INTO dot_billing_config (ambiente, valor_plano, plano_nome, ciclo, dias_trial, ativo)
SELECT 'sandbox', 49.90, 'DOT-ON Profissional', 'MONTHLY', 30, 1
WHERE NOT EXISTS (SELECT 1 FROM dot_billing_config);
