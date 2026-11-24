# Envio automático da folha de ponto

O gestor cadastra e-mails (contador, RH, contas a pagar...) em **Painel → Envio
da Folha** e escolhe a frequência. Nos dias devidos, cada e-mail recebe um link
pessoal para a folha de ponto de todos os funcionários.

## O que precisa ser feito para ativar em produção

1. **SMTP configurado** em SuperAdmin → SMTP (o mesmo dos outros e-mails).
2. **Cron diário**, pela manhã (ex.: 06:00). Pode rodar de hora em hora — não
   repete envio e faz as novas tentativas saírem mais cedo.
   - CLI: `php /caminho/app/cron/folha_envio.php`
   - URL: `https://dot-on.com.br/app/cron/folha_envio.php?token=<DOTON_CRON_TOKEN>`
     (mesma variável de ambiente do cron do faturamento).
3. As tabelas são criadas sozinhas no primeiro uso
   (`2026_11_folha_envio.sql` existe para quem prefere aplicar à mão).

## Regras

| Frequência | Quando envia | Período da folha |
|---|---|---|
| Dias do mês | dias marcados (dia que não existe no mês → último dia) | dia 1 até a véspera (no dia 1: mês anterior inteiro) |
| Semanal | dia da semana escolhido | 7 dias anteriores |
| Quinzenal | dias 1 e 16 | quinzena que fechou |
| Diário | todo dia | acumulado do mês até a véspera |

- O e-mail só tem empresa, período e link. Nenhum dado de funcionário.
- A página do link (`app/folha.php`) abre sem login, mostra nome, matrícula,
  função e horários. **Não mostra CPF nem PIS.**
- Link: um por destinatário e por envio, vale 30 dias, pode ser cancelado ou
  reenviado no histórico. No banco fica só o SHA-256 do token (por isso o
  envio não passa por `dot_email_fila`).
- E-mail que falha é tentado de novo pelo cron (até 3 vezes, por 3 dias).
- Empresa bloqueada no faturamento ou inativa não recebe envio agendado.
- Limites: 10 e-mails por empresa; 20 e-mails manuais ("Enviar agora" /
  "Reenviar") por empresa por dia.

## Teste local

Em `Dot-on/localdev`: `mock_smtp.py` (SMTP falso, grava em `mail_out/`),
`seed_folha.php` (funcionários e batidas de exemplo) e `test_folha.php`.
