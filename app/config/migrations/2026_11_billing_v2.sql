-- ============================================================
-- DOT-ON · Faturamento v2 (correções de nov/2026)
-- Só para bancos que JÁ têm as tabelas da versão de julho.
-- Instalação nova: use 2026_07_billing_asaas.sql + o bloco "Ativação" abaixo.
--
-- billing_ensure_schema() aplica TUDO isto sozinho na primeira página após o
-- deploy (inclusive a ativação). Rode à mão só se o usuário do banco não tiver
-- permissão de ALTER. Cada ALTER falha com "Duplicate column" se já aplicado.
-- ============================================================

ALTER TABLE dot_billing_config ADD COLUMN legado_migrado_em DATETIME NULL;
ALTER TABLE dot_assinaturas ADD COLUMN ambiente VARCHAR(12) NULL AFTER empresa_id;
ALTER TABLE dot_assinaturas ADD COLUMN pago_ate DATE NULL AFTER proximo_vencimento;
ALTER TABLE dot_assinaturas ADD COLUMN carencia_desde DATE NULL AFTER pago_ate;
ALTER TABLE dot_pagamentos  ADD COLUMN asaas_subscription_id VARCHAR(64) NULL AFTER asaas_payment_id;
ALTER TABLE dot_pagamentos  ADD COLUMN ambiente VARCHAR(12) NULL AFTER asaas_subscription_id;
-- (se dot_empresas ainda não tiver a coluna)
-- ALTER TABLE dot_empresas ADD COLUMN assinatura_status VARCHAR(20) NOT NULL DEFAULT 'trial';

-- ------------------------------------------------------------
-- Ativação (UMA vez): empresas que já existem ganham trial novo de 30 dias,
-- para ninguém ser bloqueado de surpresa no primeiro acesso após o deploy.
-- Só rode se legado_migrado_em estiver NULL.
-- ------------------------------------------------------------
UPDATE dot_empresas
   SET trial_expira = DATE_ADD(CURDATE(), INTERVAL 30 DAY)
 WHERE (trial_expira IS NULL OR trial_expira < DATE_ADD(CURDATE(), INTERVAL 30 DAY))
   AND id NOT IN (SELECT empresa_id FROM dot_assinaturas WHERE ambiente IS NOT NULL);
UPDATE dot_empresas SET assinatura_status = 'trial'
 WHERE id NOT IN (SELECT empresa_id FROM dot_assinaturas WHERE ambiente IS NOT NULL);
UPDATE dot_billing_config SET legado_migrado_em = NOW();

-- Assinaturas/cobranças antigas (julho) ficam com ambiente NULL e são tratadas
-- como "de outro ambiente" (ignoradas) — a empresa volta às regras do trial.
