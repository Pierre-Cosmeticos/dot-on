# DOT-ON · Faturamento SaaS (Asaas) — guia de ativação

Integração de cobrança recorrente via **Asaas** para operar o DOT-ON como SaaS,
com **plano único de valor fixo** e mensalidade automática (Pix, boleto ou cartão).

## Modelo comercial

- **Plano único**, valor fixo mensal, configurável (padrão **R$ 49,90/mês**).
- **Trial** de 30 dias (configurável) no cadastro — sem cartão.
- Sem cobrança por colaborador: funcionários ilimitados no mesmo preço.

### Por que R$ 49,90 (posicionamento de mercado 2025/2026)

| Concorrente        | Entrada / preço            |
|--------------------|----------------------------|
| PontoSimples       | a partir de R$ 49,90/mês   |
| Pontomais          | a partir de R$ 59,90/mês   |
| Tangerino / Sólides| a partir de R$ 69,90/mês ou R$ 4,50/colaborador |
| Genyo              | ~R$ 3,75/colaborador       |
| PontoBarato        | R$ 20 (5 func) / R$ 50 (20 func) |

## Passo a passo para ativar em produção

1. **Asaas**: crie a conta e gere a **API Key** (Configurações → Integrações → API).
   Comece pelo **Sandbox** (https://sandbox.asaas.com) — chave e conta são separadas da produção.
2. **SuperAdmin → 💰 Faturamento**:
   - Ambiente `Sandbox`, cole a chave, confira valor e dias de trial → **Salvar**.
   - **Testar conexão** (só lê; não cria nada no Asaas).
   - **Gerar novo token** de webhook e copie o valor.
3. **Asaas → Integrações → Webhooks** → novo webhook:
   - URL: `https://dot-on.com.br/app/api/asaas_webhook.php`
   - Token de autenticação: o mesmo do passo 2.
   - Eventos: todos de **Cobranças** (`PAYMENT_*`) e de **Assinaturas** (`SUBSCRIPTION_*`).
   - Tipo de envio: sequencial.
   > O token é **obrigatório**. Sem ele o webhook responde 503 e não processa nada
   > (antes aceitava qualquer chamada — dava para forjar um "pago").
4. **Cron diário** (rede de segurança para webhook perdido):
   - cPanel/CLI: `php /caminho/app/cron/billing_sync.php`
   - Ou por URL: defina a variável de ambiente `DOTON_CRON_TOKEN` e agende
     `https://dot-on.com.br/app/cron/billing_sync.php?token=<DOTON_CRON_TOKEN>`.
   - O mesmo efeito tem o botão **🔄 Sincronizar agora** em Faturamento.
5. **Teste no sandbox** com uma empresa de teste: assinar → pagar a fatura no sandbox →
   conferir que vira "Assinatura ativa". O log do webhook fica em `app/logs/asaas_webhook.log`.
6. **Virar para produção**: troque o ambiente para `Produção`, cole a chave de produção,
   gere/cadastre o webhook **no Asaas de produção** (é outro painel) e repita o teste com
   um valor real. Assinaturas criadas no sandbox são ignoradas automaticamente em produção.

## Regras de cobrança e acesso

O status é **calculado a partir das cobranças** (`billing_reavaliar_status()`), nunca do
"último evento" — webhooks repetidos, fora de ordem ou perdidos convergem para o certo.

| Status      | Quando                                         | Acesso ao painel |
|-------------|------------------------------------------------|------------------|
| `trial`     | sem assinatura                                 | até `trial_expira` (empresas antigas sem data: sempre) |
| `pending`   | assinou, 1º pagamento não compensou            | até vencimento + 10 dias (ou fim do trial, o que for maior) |
| `active`    | há cobrança paga e nenhuma vencida em aberto   | liberado |
| `overdue`   | há cobrança vencida e não paga                 | até vencimento + 10 dias (`BILLING_CARENCIA_DIAS`), com aviso de contagem regressiva |
| `canceled`  | cancelou (aqui ou no painel do Asaas)          | até o fim do período já pago (`pago_ate`) |

- **1ª cobrança** vence no fim do trial (quem assina no dia 5 do trial não perde os 25 dias
  restantes). Quem assina de novo após cancelar só paga quando o período já pago acabar.
- **Cancelar** chama o Asaas primeiro; se o Asaas falhar, nada muda localmente (o cliente
  nunca fica "cancelado aqui e cobrado lá").
- **Estorno/chargeback** tiram o período pago daquela cobrança.
- O **ponto do funcionário** (`/me` e API do agente) **nunca** é bloqueado — obrigação legal.
- Falha no faturamento (ex.: banco fora) **não derruba o painel**: o gating libera e loga.

## Schema

Criado/atualizado sozinho por `billing_ensure_schema()` (uma consulta leve por página;
só roda DDL se faltar algo). Para aplicar à mão (usuário do banco sem permissão de ALTER):

- instalação nova: `2026_07_billing_asaas.sql`
- já tinha as tabelas de julho: `2026_11_billing_v2.sql`

Tabelas: `dot_billing_config`, `dot_assinaturas`, `dot_pagamentos`;
coluna `dot_empresas.assinatura_status`.

## Testar localmente (sem Asaas real)

Fora do `public_html` (nunca vai no deploy), em `Dot-on/localdev/`:

- `iniciar.bat` — sobe MariaDB local (3307), o app (http://127.0.0.1:8000),
  o **simulador do Asaas** (8081) e o entregador de webhooks.
- Logins: `admin@teste.local` / `Teste@123` (gestor), `sysadmin@teste.local` / `Teste@123`
  (SuperAdmin em `/app/sysadmin/`).
- A fatura do simulador tem botões "Pagar com Pix/Cartão/Boleto (simular)" e "Simular atraso".
- `php -c php.ini test_billing.php` — 60 verificações de ponta a ponta
  (pare o entregador de webhooks antes; o teste entrega a fila sozinho).

O app só usa o banco/simulador locais quando as variáveis `DOTON_DB_HOST` e
`DOTON_ASAAS_BASE_URL` estão definidas — em produção elas não existem.

## Arquivos

- `app/includes/asaas.php` — cliente HTTP da API Asaas (com paginação)
- `app/includes/billing.php` — regra de negócio + schema + gating (única fonte do status)
- `app/api/asaas_webhook.php` — receptor de eventos (token obrigatório)
- `app/cron/billing_sync.php` — reconciliação diária
- `app/sysadmin/faturamento.php` — configuração, MRR, inadimplência, sincronizar
- `app/admin/assinatura.php` — página do gestor (assinar, pagar, atualizar, cancelar, histórico)
- `app/admin/_layout.php` — bloqueio do painel e avisos
