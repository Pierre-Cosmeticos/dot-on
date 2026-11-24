-- DOT-ON · Envio automático da folha de ponto (2026-11)
-- O sistema cria estas tabelas sozinho no primeiro uso (folha_garantir_schema()
-- em app/includes/folha_envio.php). Este arquivo existe para quem prefere
-- aplicar a migração à mão. Idempotente.

CREATE TABLE IF NOT EXISTS dot_folha_envio_config (
    empresa_id     INT UNSIGNED NOT NULL PRIMARY KEY,
    ativo          TINYINT(1)   NOT NULL DEFAULT 0,
    frequencia     VARCHAR(20)  NOT NULL DEFAULT 'dias_mes',  -- dias_mes | semanal | quinzenal | diario
    dias           VARCHAR(120) NOT NULL DEFAULT '',          -- dias do mês, CSV (frequencia = dias_mes)
    dia_semana     TINYINT      NOT NULL DEFAULT 1,           -- 0=domingo .. 6=sábado (frequencia = semanal)
    atualizado_por INT UNSIGNED NULL,
    atualizado_em  DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dot_folha_envio_destinatarios (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empresa_id  INT UNSIGNED NOT NULL,
    email       VARCHAR(190) NOT NULL,
    nome        VARCHAR(120) NULL,
    criado_por  INT UNSIGNED NULL,
    criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_folha_dest (empresa_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Um registro por e-mail enviado. O token do link só existe no e-mail;
-- aqui fica o hash SHA-256.
CREATE TABLE IF NOT EXISTS dot_folha_envios (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empresa_id     INT UNSIGNED NOT NULL,
    email          VARCHAR(190) NOT NULL,
    nome           VARCHAR(120) NULL,
    data_envio     DATE         NOT NULL,
    inicio         DATE         NOT NULL,                      -- período da folha
    fim            DATE         NOT NULL,
    origem         VARCHAR(10)  NOT NULL DEFAULT 'agendado',   -- agendado | manual
    chave_agenda   CHAR(64)     NULL,                          -- sha256(empresa|email|data): impede envio agendado repetido
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
