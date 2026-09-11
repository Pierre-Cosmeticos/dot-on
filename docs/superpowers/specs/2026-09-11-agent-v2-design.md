# DOT-ON Agent v2 — design da reescrita

Data: 2026-09-11. Branch: `dev`.

## Objetivo

Reescrever o agente desktop (Windows, Python + PyQt5) do zero, com regras de
ponto isoladas da interface e testáveis, cobrindo:

1. Registrar entrada, intervalo, retorno do intervalo e saída.
2. Mostrar o tempo restante do intervalo (contagem regressiva ao vivo).
3. Alertar 1min30s antes do fim do intervalo (e avisar se estourar).
4. Mostrar o tempo trabalhado calculado pelas batidas do servidor, nunca pelo
   momento em que o app foi aberto.
5. Mostrar entrada e saída previstas na jornada do dia (e "Folga").
6. Solicitar hora extra, com pedido pendente recuperado do servidor ao reabrir.
7. Ao ultrapassar a carga diária sem extra aprovada: trazer a janela para
   frente, notificar e abrir o diálogo de hora extra.

Extras aprovadas pelo usuário:

8. Aviso de atraso na entrada (passou da hora prevista sem batida).
9. Lembrete de intervalo após 6h seguidas sem pausa.
10. Saída antecipada mostra quanto falta e que vira débito no banco de horas.
11. Aba "Espelho": saldo do banco de horas do mês e últimos dias (`/sessao/mes`).
12. Opção "Iniciar com o Windows" na bandeja (atalho na pasta Startup).
13. Indicador online/offline/pendentes no cabeçalho.

Mantidos da versão 1.x: login com troca de senha obrigatória, dot-on.ini do
instalador, fila offline idempotente (client_uid), ociosidade via
GetLastInputInfo, heartbeat, auto-update por manifesto, instância única,
`%APPDATA%\DOT-ON` como pasta de dados, ícone embutido.

Regra fixa do produto: o agente NUNCA registra a saída sozinho nem bloqueia a
tela. No fim previsto apenas lembra.

## Estrutura

```
app/agent/
  agent.py              # entrypoint (mantém spec do PyInstaller e docs)
  doton/
    __init__.py         # AGENT_VERSION
    paths.py            # EXE_DIR, DATA_DIR, arquivos, log com rotação
    config.py           # dot-on.ini + config.json + defaults
    api.py              # APIClient (HTTP, token, relógio do servidor)
    fila.py             # fila offline em disco, idempotente
    jornada.py          # PURO: estado/tempos a partir das batidas + escala
    alertas.py          # PURO: quais avisos disparar, cada um uma vez
    ociosidade.py       # GetLastInputInfo
    updater.py          # verificar/baixar/instalar nova versão
    startup.py          # atalho na pasta Startup do Windows
    ui/
      tema.py           # cores, QSS, helpers de widget
      login.py          # LoginDialog, TrocarSenhaDialog
      hora_extra.py     # HoraExtraDialog
      principal.py      # MainWindow (orquestra timers, sinais, bandeja)
      espelho.py        # widget da aba Espelho
  tests/
    test_jornada.py
    test_alertas.py
    test_fila.py
```

## Módulo `jornada.py` (núcleo, sem Qt)

Entradas: lista de batidas `{tipo, momento}` (servidor + fila offline de hoje),
escala do dia (`entrada`, `saida`, `almoco_minutos`, `carga_diaria_minutos`,
`trabalha_hoje`), minutos de extra aprovada, `agora` (relógio do servidor).

Saída: `Resumo` (dataclass) com:

- `estado`: `fora | trabalhando | intervalo`
- `segundos_trabalhados`, `segundos_intervalo_total`
- `intervalo_inicio`, `segundos_intervalo_restante` (pode ser negativo)
- `carga_segundos`, `segundos_faltando` (negativo = além da carga)
- `fim_previsto` (saída da escala + extra aprovada), `entrada_prevista`
- `segundos_sem_pausa` (trabalho contínuo desde a última entrada/retorno)
- `primeira_entrada` (None se não bateu)

Função principal: `calcular(batidas, escala, extra_aprovada_min, agora) -> Resumo`.
Pareamento: entrada→(saida_intervalo|saida) fecha trabalho;
saida_intervalo→retorno_intervalo fecha intervalo. Batidas fora de ordem são
ordenadas por `momento`. Só batidas de hoje entram.

## Módulo `alertas.py` (sem Qt)

`Alertador` guarda quais avisos já dispararam hoje (chave `(data, nome)`) e
expõe `avaliar(resumo, agora) -> list[Alerta]`. Cada `Alerta` tem `nome`,
`titulo`, `mensagem`, `trazer_janela: bool`, `abrir_extra: bool`.

| nome | condição | ação |
|---|---|---|
| `intervalo_acabando` | em intervalo e restante ≤ 90s e > 0 | notificar + trazer janela |
| `intervalo_estourado` | em intervalo e restante ≤ 0 | notificar + trazer janela |
| `carga_atingida` | trabalhando/intervalo, trabalhado ≥ carga, sem extra aprovada nem pendente | notificar + trazer janela + abrir diálogo de extra |
| `fim_previsto` | trabalhando e agora ≥ fim_previsto | notificar |
| `entrada_atrasada` | fora, sem primeira entrada, trabalha hoje, agora ≥ entrada prevista + tolerância | notificar + trazer janela |
| `sem_pausa_6h` | trabalhando, sem_pausa ≥ 6h, almoco_minutos > 0 | notificar + trazer janela |

Cada alerta dispara uma vez por dia; `intervalo_acabando` e
`intervalo_estourado` disparam uma vez por intervalo (chave inclui
`intervalo_inicio`). `carga_atingida` re-arma se um pedido pendente for
rejeitado.

## API

Nova rota `GET hora-extra/hoje` em `app/api/index.php`: pedidos do dia do
usuário autenticado, `[{id, status, minutos_solicitados, minutos_aprovados,
motivo_decisao}]`, mais recentes primeiro. O agente, ao sincronizar, toma:
`aprovada` → soma `minutos_aprovados`; `pendente` → guarda o id; `rejeitada`
→ informa uma vez.

## Fluxo de sincronização

Timer de 60s (thread): reenviar fila → `GET sessao/atual` + `GET escala` +
`GET config` + `GET hora-extra/hoje` → sinal para a UI → `jornada.calcular`
→ `alertas.avaliar` → aplicar. Timer de 1s (UI): recalcula `Resumo` local
com `agora` do servidor e redesenha cronômetro, intervalo e barra. Timer de
30s: ociosidade e heartbeat.

Batida: thread → POST → sinal → força sincronização imediata (fonte da
verdade continua sendo o servidor; a UI mostra otimista enquanto isso).

## UI (janela 460×720, bandeja)

Cabeçalho: logo, nome/matrícula, pílula de conexão (Online / Offline / N
pendentes). Cartão de estado: badge, cronômetro grande, barra de carga,
"faltam Xh". Cartão de jornada: Entrada HH:MM · Saída HH:MM · Almoço N min ·
Carga Xh (ou Folga). Cartão de intervalo (só em intervalo): contagem
regressiva grande, vermelho quando < 1min30s. Botões: apenas os válidos para
o estado ficam habilitados. Hora extra: botão + status do pedido
(pendente/aprovada N min). Abas: Hoje | Espelho. Bandeja: Abrir, Iniciar com o
Windows (marcável), Sair da conta, Encerrar agente.

Saída antecipada: diálogo "Faltam Xh para cumprir a carga. Isso entra como
débito no banco de horas. Encerrar mesmo assim?".

## Testes

pytest em `app/agent/tests/`: `jornada` (reconstrução de estado nos 3
estados, intervalo restante, agente reaberto no meio do intervalo, extra
aprovada move o fim previsto, folga), `alertas` (cada regra dispara uma vez,
re-arme após rejeição), `fila` (add/ler/gravar, só hoje entra na conta).

## Entrega

- `AGENT_VERSION = "2.0.0"`, `agent-version.json` obrigatória com notas.
- Rebuild do .exe (`pyinstaller DOT-ON-Agent.spec`) → `app/downloads/`.
- LEIA-ME.txt, README.txt e `admin/downloads.php` atualizados.
- Sem commit até o usuário pedir.
