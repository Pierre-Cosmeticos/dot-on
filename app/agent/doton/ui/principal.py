"""
Janela principal: orquestra timers, chamadas de rede em thread e a bandeja.

Nenhuma regra de tempo vive aqui. A cada segundo a janela pede a
jornada.calcular() um Resumo novo (batidas do servidor + fila offline de hoje,
escala do dia, extra aprovada, relogio do servidor) e apenas desenha; os
avisos vem de alertas.Alertador. Assim fechar e reabrir o agente nunca muda
os numeros.
"""
import json
import socket
import threading
import time
import traceback
from datetime import timedelta

from PyQt5.QtCore import Qt, QTimer, pyqtSignal
from PyQt5.QtWidgets import (QAction, QApplication, QDialog, QHBoxLayout, QLabel, QMainWindow,
                             QMenu, QMessageBox, QProgressBar, QSystemTrayIcon, QTabWidget,
                             QVBoxLayout, QWidget)

from .. import AGENT_VERSION, startup, updater
from ..alertas import Alerta, Alertador
from ..api import APIClient
from ..fila import Fila
from ..jornada import Resumo, calcular, fmt_hm, fmt_hms
from ..ociosidade import segundos_ocioso
from ..paths import CONGELADO, SESSAO_CACHE_FILE, log
from .espelho import EspelhoWidget
from .hora_extra import HoraExtraDialog, contexto_carga
from .login import LoginDialog
from .tema import COR, badge_css, botao, btn_css, cartao, rotulo, shade

ESTADO_COR = {'fora': COR['cinza'], 'trabalhando': COR['sucesso'], 'intervalo': COR['alerta']}
ESTADO_TXT = {'fora': 'FORA DO EXPEDIENTE', 'trabalhando': 'TRABALHANDO', 'intervalo': 'EM INTERVALO'}
TEXTO_BOTAO = {'entrada': 'Iniciar expediente', 'saida': 'Encerrar expediente',
               'saida_intervalo': 'Iniciar intervalo', 'retorno_intervalo': 'Retornar do intervalo'}
MSG_SUCESSO = {'entrada': 'Entrada registrada. Bom trabalho!', 'saida': 'Saida registrada. Ate amanha!',
               'saida_intervalo': 'Intervalo iniciado. Bom descanso!',
               'retorno_intervalo': 'Retorno registrado. Bom trabalho!'}


class MainWindow(QMainWindow):
    sync_pronta = pyqtSignal(object)
    batida_pronta = pyqtSignal(str, object)
    extra_enviada = pyqtSignal(object, int)
    atualizacao_disp = pyqtSignal(object)

    def __init__(self, api: APIClient, cfg: dict, fila: Fila, icone):
        super().__init__()
        self.api = api
        self.cfg = cfg
        self.fila = fila
        self.icone = icone

        # ---- estado vindo do servidor ----
        self.escala = None            # None = ainda nao carregada
        self.config_srv = {}
        self.batidas = []             # batidas de hoje (servidor); fila entra no calculo
        self.extra_aprovada_min = 0
        self.extra_pendente = None    # dict do pedido pendente (id, minutos_solicitados)
        self._extras_vistos = {}      # id -> status, para notificar decisoes
        self._sincronizou = False     # ja houve resposta (ou falha) para liberar os botoes
        self._dados_confiaveis = False  # batidas de hoje vieram do servidor ou do cache de hoje
        self._carregar_cache()

        # ---- estado local ----
        self.alertador = Alertador(int(cfg.get('tolerancia_entrada_min', 10)),
                                   float(cfg.get('sem_pausa_horas', 6)))
        self.resumo: Resumo = calcular([], None, 0, api.agora())
        self.ocupado = False
        self.ocioso_desde = None
        self._sincronizando = False
        self._dlg_extra_aberto = False
        self._ultimo_heartbeat = 0.0
        self._banner_timer = None
        self._pulso_on = False
        self._relogando = False

        self.setWindowTitle(f"DOT-ON - {api.user.get('nome', '')}")
        self.setWindowIcon(icone)
        self.setFixedSize(470, 760)
        self._build_ui()
        self._build_tray()

        self.sync_pronta.connect(self._on_sync)
        self.batida_pronta.connect(self._on_batida)
        self.extra_enviada.connect(self._on_extra_enviada)
        self.atualizacao_disp.connect(self._on_atualizacao)

        self._iniciar_timers()
        QTimer.singleShot(300, self.sincronizar)
        QTimer.singleShot(5000, self._checar_update)
        QTimer.singleShot(1500, self.espelho.atualizar)

    # ================================================================
    # UI
    # ================================================================
    def _build_ui(self):
        central = QWidget()
        raiz = QVBoxLayout(central)
        raiz.setContentsMargins(16, 12, 16, 12)
        raiz.setSpacing(10)

        # ---- Cabecalho ----
        topo = QHBoxLayout()
        logo = QLabel("DOT-ON")
        logo.setStyleSheet(f"font-size:20px;font-weight:800;color:{COR['primaria']};letter-spacing:1px")
        topo.addWidget(logo)
        topo.addStretch()
        user = QLabel(f"{self.api.user.get('nome', '')}  ·  Mat. {self.api.user.get('matricula', '')}")
        user.setStyleSheet(f"font-size:12px;color:{COR['texto_fraco']}")
        topo.addWidget(user)
        topo.addSpacing(8)
        self.pill_conexao = QLabel("")
        topo.addWidget(self.pill_conexao)
        raiz.addLayout(topo)

        # ---- Banner ----
        self.banner = QLabel("")
        self.banner.setWordWrap(True)
        self.banner.setAlignment(Qt.AlignCenter)
        self.banner.setVisible(False)
        raiz.addWidget(self.banner)

        self.abas = QTabWidget()
        self.abas.setDocumentMode(True)
        self.abas.addTab(self._build_aba_hoje(), "Hoje")
        self.espelho = EspelhoWidget(self.api)
        self.abas.addTab(self.espelho, "Espelho")
        self.abas.currentChanged.connect(lambda i: i == 1 and self.espelho.atualizar())
        raiz.addWidget(self.abas, 1)

        # ---- Rodape ----
        self.lbl_info = QLabel("")
        self.lbl_info.setStyleSheet(f"font-size:10px;color:{COR['texto_suave']}")
        self.lbl_info.setWordWrap(True)
        self.lbl_info.setAlignment(Qt.AlignCenter)
        raiz.addWidget(self.lbl_info)
        self.setCentralWidget(central)

    def _build_aba_hoje(self):
        w = QWidget()
        lay = QVBoxLayout(w)
        lay.setContentsMargins(0, 8, 0, 0)
        lay.setSpacing(10)

        # ---- Cartao de estado ----
        card = cartao()
        ce = QVBoxLayout(card)
        ce.setContentsMargins(18, 14, 18, 14)
        ce.setSpacing(6)
        self.lbl_estado = QLabel("")
        self.lbl_estado.setAlignment(Qt.AlignCenter)
        lb = QHBoxLayout()
        lb.addStretch()
        lb.addWidget(self.lbl_estado)
        lb.addStretch()
        ce.addLayout(lb)
        self.lbl_cron = QLabel("00:00:00")
        self.lbl_cron.setAlignment(Qt.AlignCenter)
        ce.addWidget(self.lbl_cron)
        self.lbl_cron_sub = rotulo("tempo trabalhado hoje", 11, alinhar=Qt.AlignCenter)
        ce.addWidget(self.lbl_cron_sub)
        self.bar = QProgressBar()
        self.bar.setTextVisible(False)
        self.bar.setRange(0, 1000)
        ce.addWidget(self.bar)
        self.lbl_carga = rotulo("", 12, alinhar=Qt.AlignCenter)
        ce.addWidget(self.lbl_carga)
        lay.addWidget(card)

        # ---- Cartao de intervalo (so aparece em intervalo) ----
        self.card_int = cartao()
        ci = QVBoxLayout(self.card_int)
        ci.setContentsMargins(18, 10, 18, 10)
        ci.setSpacing(2)
        ci.addWidget(rotulo("INTERVALO RESTANTE", 10, negrito=True, alinhar=Qt.AlignCenter))
        self.lbl_int = QLabel("--:--")
        self.lbl_int.setAlignment(Qt.AlignCenter)
        ci.addWidget(self.lbl_int)
        self.lbl_int_sub = rotulo("", 11, alinhar=Qt.AlignCenter)
        ci.addWidget(self.lbl_int_sub)
        self.card_int.setVisible(False)
        lay.addWidget(self.card_int)

        # ---- Cartao de jornada ----
        card_j = cartao()
        cj = QHBoxLayout(card_j)
        cj.setContentsMargins(16, 10, 16, 10)
        cj.setSpacing(6)
        self.col_entrada = self._coluna(cj, "Entrada")
        self.col_saida = self._coluna(cj, "Saida")
        self.col_almoco = self._coluna(cj, "Almoco")
        self.col_carga = self._coluna(cj, "Carga")
        lay.addWidget(card_j)

        # ---- Botoes ----
        self.botoes = {}
        l1 = QHBoxLayout()
        l1.setSpacing(10)
        self.botoes['entrada'] = botao(TEXTO_BOTAO['entrada'], COR['sucesso'])
        self.botoes['saida'] = botao(TEXTO_BOTAO['saida'], COR['perigo'])
        l1.addWidget(self.botoes['entrada'])
        l1.addWidget(self.botoes['saida'])
        lay.addLayout(l1)
        l2 = QHBoxLayout()
        l2.setSpacing(10)
        self.botoes['saida_intervalo'] = botao(TEXTO_BOTAO['saida_intervalo'], COR['alerta'])
        self.botoes['retorno_intervalo'] = botao(TEXTO_BOTAO['retorno_intervalo'], COR['primaria'])
        l2.addWidget(self.botoes['saida_intervalo'])
        l2.addWidget(self.botoes['retorno_intervalo'])
        lay.addLayout(l2)
        self.botoes['entrada'].clicked.connect(lambda: self.bater('entrada'))
        self.botoes['saida'].clicked.connect(self.bater_saida)
        self.botoes['saida_intervalo'].clicked.connect(lambda: self.bater('saida_intervalo'))
        self.botoes['retorno_intervalo'].clicked.connect(lambda: self.bater('retorno_intervalo'))

        # ---- Hora extra ----
        self.btn_extra = botao("Solicitar hora extra", COR['roxo'])
        self.btn_extra.clicked.connect(self.abrir_hora_extra)
        lay.addWidget(self.btn_extra)
        self.lbl_extra = rotulo("", 11, alinhar=Qt.AlignCenter)
        lay.addWidget(self.lbl_extra)
        lay.addStretch()
        return w

    def _coluna(self, layout, titulo):
        box = QVBoxLayout()
        box.setSpacing(1)
        box.addWidget(rotulo(titulo, 10, alinhar=Qt.AlignCenter))
        v = QLabel("--:--")
        v.setAlignment(Qt.AlignCenter)
        v.setStyleSheet(f"font-size:15px;font-weight:700;color:{COR['texto']}")
        box.addWidget(v)
        layout.addLayout(box, 1)
        return v

    def _build_tray(self):
        self.tray = QSystemTrayIcon(self.icone, self)
        self.tray.setToolTip("DOT-ON - Registro de Ponto")
        menu = QMenu()
        a_abrir = QAction("Abrir DOT-ON", self)
        a_abrir.triggered.connect(self.trazer_para_frente)
        menu.addAction(a_abrir)
        self.a_startup = QAction("Iniciar com o Windows", self)
        self.a_startup.setCheckable(True)
        self.a_startup.setChecked(startup.esta_ativo())
        self.a_startup.triggered.connect(self._toggle_startup)
        menu.addAction(self.a_startup)
        menu.addSeparator()
        a_logout = QAction("Sair da conta", self)
        a_logout.triggered.connect(self._logout)
        menu.addAction(a_logout)
        a_quit = QAction("Encerrar agente", self)
        a_quit.triggered.connect(QApplication.instance().quit)
        menu.addAction(a_quit)
        self.tray.setContextMenu(menu)
        self.tray.activated.connect(lambda r: r == QSystemTrayIcon.Trigger and self.trazer_para_frente())
        self.tray.show()

    # ================================================================
    # TIMERS
    # ================================================================
    def _safe(self, fn):
        def wrapper(*a, **k):
            try:
                return fn(*a, **k)
            except Exception as e:
                log(f"ERRO em {getattr(fn, '__name__', 'slot')}: {e}\n{traceback.format_exc()}")
        return wrapper

    def _iniciar_timers(self):
        self.t_tick = QTimer(self)
        self.t_tick.timeout.connect(self._safe(self._tick))
        self.t_tick.start(1000)
        self.t_check = QTimer(self)
        self.t_check.timeout.connect(self._safe(self._check))
        self.t_check.start(max(10, int(self.cfg.get('verificar_segundos', 30))) * 1000)
        self.t_sync = QTimer(self)
        self.t_sync.timeout.connect(self._safe(self.sincronizar))
        self.t_sync.start(max(15, int(self.cfg.get('sincronizar_segundos', 60))) * 1000)
        self.t_espelho = QTimer(self)
        self.t_espelho.timeout.connect(self._safe(self.espelho.atualizar))
        self.t_espelho.start(10 * 60 * 1000)
        self.t_pulso = QTimer(self)
        self.t_pulso.timeout.connect(self._safe(self._pulsar_extra))
        self.t_pulso.start(600)
        self._tick()

    # ================================================================
    # SINCRONIZACAO (fonte da verdade = servidor)
    # ================================================================
    def sincronizar(self):
        if self._sincronizando:
            return
        self._sincronizando = True

        def work():
            try:
                recusadas = self._enviar_pendentes()
                self.sync_pronta.emit({
                    'recusadas': recusadas,
                    'sessao': self.api.sessao_atual(),
                    'escala': self.api.escala(),
                    'config': self.api.config(),
                    'extras': self.api.extras_hoje(),
                })
            except Exception as e:
                log(f"Sync: erro: {e}")
                self.sync_pronta.emit({'erro': str(e)})
        threading.Thread(target=work, daemon=True).start()

    def _enviar_pendentes(self):
        """Reenvia a fila offline. Devolve as batidas recusadas pelo servidor."""
        recusadas = []
        with self.fila.lock:
            itens = self.fila.ler()
            if not itens:
                return recusadas
            restantes = []
            for it in itens:
                r = self.api.batida(it.get('tipo'), it.get('momento'), it.get('client_uid'))
                if r.get('ok'):
                    log(f"Fila: '{it.get('tipo')}' de {it.get('momento')} enviada"
                        f"{' (ja registrada)' if r.get('duplicada') else ''}.")
                elif r.get('offline'):
                    restantes.append(it)
                else:
                    log(f"Fila: '{it.get('tipo')}' de {it.get('momento')} recusada: {r.get('erro')}")
                    recusadas.append(f"{TEXTO_BOTAO.get(it.get('tipo'), it.get('tipo'))} de "
                                     f"{str(it.get('momento'))[11:16]} ({r.get('erro')})")
            self.fila.gravar(restantes)
        return recusadas

    def _on_sync(self, d):
        self._sincronizando = False
        if d.get('erro'):
            return
        esc = d.get('escala')
        if esc is not None and esc != self.escala:
            self.escala = esc
            log("Escala carregada/atualizada pelo servidor.")
        cfg = d.get('config')
        if isinstance(cfg, dict):
            self.config_srv = cfg
            self._aplicar_config_srv()

        s = d.get('sessao') or {}
        if s.get('nao_autorizado'):
            self._sessao_expirada()
            return
        if s.get('ok') and not self.ocupado:
            self.batidas = [b for b in (s.get('batidas') or []) if isinstance(b, dict)]
            if not self._dados_confiaveis:
                log(f"Sessao sincronizada: {len(self.batidas)} batida(s).")
            self._dados_confiaveis = True
            self._salvar_cache()
        elif not self._sincronizou:
            log("Sessao nao carregada (API indisponivel); usando cache local.")
        # Mesmo sem resposta o funcionario pode bater (a batida vai para a fila).
        self._sincronizou = True

        extras = d.get('extras')
        if extras is not None:
            self._aplicar_extras(extras)

        rec = d.get('recusadas') or []
        if rec:
            msg = "O servidor recusou: " + "; ".join(rec[:3])
            self._banner(msg + ". Fale com seu gestor.", 'erro', persistente=True)
            self._notificar("Batida recusada", msg)
        self._tick()

    def _carregar_cache(self):
        """Boot sem internet: restaura as batidas de hoje vistas na ultima sincronizacao."""
        try:
            d = json.loads(SESSAO_CACHE_FILE.read_text(encoding='utf-8'))
            if d.get('data') == self.api.hoje():
                self.batidas = [b for b in d.get('batidas') or [] if isinstance(b, dict)]
                if isinstance(d.get('escala'), dict):
                    self.escala = d.get('escala')
                self.extra_aprovada_min = int(d.get('extra_aprovada_min') or 0)
                self._dados_confiaveis = True
                log(f"Cache: {len(self.batidas)} batida(s) de hoje restaurada(s).")
        except (OSError, ValueError, AttributeError):
            pass

    def _salvar_cache(self):
        try:
            SESSAO_CACHE_FILE.write_text(json.dumps({
                'data': self.api.hoje(), 'batidas': self.batidas, 'escala': self.escala,
                'extra_aprovada_min': self.extra_aprovada_min,
            }), encoding='utf-8')
        except (OSError, TypeError):
            pass

    def _aplicar_config_srv(self):
        tol = self.config_srv.get('tolerancia_entrada_minutos') or (self.escala or {}).get('tolerancia_minutos')
        if tol not in (None, ''):
            try:
                self.alertador.tolerancia_min = max(0, int(tol))
            except (TypeError, ValueError):
                pass

    def _aplicar_extras(self, extras):
        pend = [p for p in extras if p.get('status') == 'pendente']
        self.extra_aprovada_min = sum(int(p.get('minutos_aprovados') or 0)
                                      for p in extras if p.get('status') == 'aprovada')
        self.extra_pendente = pend[0] if pend else None
        for p in extras:
            pid, st = p.get('id'), p.get('status')
            ant = self._extras_vistos.get(pid)
            if ant is not None and ant != st:
                if st == 'aprovada':
                    m = int(p.get('minutos_aprovados') or 0)
                    self._banner(f"Hora extra aprovada: {m} min.", 'sucesso')
                    self._notificar("Hora extra aprovada", f"Seu gestor aprovou {m} min de hora extra.")
                elif st == 'rejeitada':
                    mot = (p.get('motivo_decisao') or '').strip()
                    self._banner("Sua solicitacao de hora extra foi rejeitada" + (f": {mot}" if mot else "."),
                                 'erro', persistente=True)
                    self._notificar("Hora extra rejeitada", "Encerre o expediente ou fale com seu gestor.")
                    self.alertador.rearmar('carga_atingida')
            self._extras_vistos[pid] = st

    def _sessao_expirada(self):
        if self._relogando:
            return
        self._relogando = True
        log("Token invalido: pedindo novo login.")
        self.api.logout()
        self.trazer_para_frente()
        dlg = LoginDialog(self.api, self.cfg, self)
        if dlg.exec_() != QDialog.Accepted:
            QApplication.instance().quit()
            return
        self._relogando = False
        self.setWindowTitle(f"DOT-ON - {self.api.user.get('nome', '')}")
        self.sincronizar()

    # ================================================================
    # TICK (1s): recalcula e desenha
    # ================================================================
    def _tick(self):
        agora = self.api.agora()
        batidas = self.batidas + self.fila.de_hoje(agora.strftime('%Y-%m-%d'))
        self.resumo = calcular(batidas, self.escala, self.extra_aprovada_min, agora)
        self._render()
        if self._dados_confiaveis:
            for a in self.alertador.avaliar(self.resumo, agora, self.extra_pendente is not None):
                self._aplicar_alerta(a)

    def _render(self):
        r = self.resumo
        cor = ESTADO_COR.get(r.estado, COR['cinza'])
        self.lbl_estado.setText(ESTADO_TXT.get(r.estado, r.estado.upper()))
        self.lbl_estado.setStyleSheet(badge_css(cor))
        self.lbl_cron.setText(fmt_hms(r.segundos_trabalhados))
        self.lbl_cron.setStyleSheet(f"font-size:44px;font-weight:800;color:{cor}")
        if r.primeira_entrada:
            self.lbl_cron_sub.setText(f"desde as {r.primeira_entrada.strftime('%H:%M')}"
                                      + (f"  ·  intervalo {fmt_hm(r.segundos_intervalo_total)}"
                                         if r.segundos_intervalo_total >= 60 else ""))
        else:
            self.lbl_cron_sub.setText("tempo trabalhado hoje" if self._sincronizou else "sincronizando com o servidor...")
        self._render_jornada()

        # barra e carga
        if r.limite_segundos > 0:
            self.bar.setVisible(True)
            self.bar.setValue(int(min(1000, r.segundos_trabalhados / r.limite_segundos * 1000)))
            alem = r.segundos_trabalhados - r.carga_segundos
            if r.segundos_trabalhados >= r.limite_segundos:
                chunk, css = COR['perigo'], f"font-size:12px;color:{COR['perigo']};font-weight:600"
                txt = f"Carga {fmt_hm(r.carga_segundos)} cumprida  ·  +{fmt_hm(alem)} alem"
                if r.extra_aprovada_segundos:
                    txt += f"  (extra aprovada {fmt_hm(r.extra_aprovada_segundos)})"
            elif alem >= 0:
                chunk, css = COR['alerta'], f"font-size:12px;color:{COR['alerta']};font-weight:600"
                txt = (f"Carga {fmt_hm(r.carga_segundos)} cumprida  ·  extra aprovada, "
                       f"faltam {fmt_hm(r.limite_segundos - r.segundos_trabalhados)}")
            else:
                chunk, css = COR['sucesso'], f"font-size:12px;color:{COR['texto_fraco']}"
                txt = f"Carga diaria {fmt_hm(r.carga_segundos)}  ·  faltam {fmt_hm(r.segundos_faltando)}"
            self.bar.setStyleSheet(
                f"QProgressBar{{border:none;border-radius:7px;background:{COR['borda']};height:14px;}}"
                f"QProgressBar::chunk{{border-radius:7px;background:{chunk};}}")
            self.lbl_carga.setText(txt)
            self.lbl_carga.setStyleSheet(css)
        else:
            self.bar.setVisible(False)
            self.lbl_carga.setText("Folga hoje" if (self.escala and not r.trabalha_hoje)
                                   else ("Sem jornada configurada" if self.escala is not None else ""))
            self.lbl_carga.setStyleSheet(f"font-size:12px;color:{COR['texto_fraco']}")

        # intervalo
        if r.estado == 'intervalo':
            self.card_int.setVisible(True)
            rest = r.segundos_intervalo_restante
            if r.almoco_segundos <= 0:
                self.lbl_int.setText(fmt_hms(r.segundos_intervalo_total))
                self.lbl_int.setStyleSheet(f"font-size:30px;font-weight:800;color:{COR['alerta']}")
                self.lbl_int_sub.setText("sem duracao definida na jornada")
            elif rest > 0:
                cor_i = COR['perigo'] if rest <= 90 else (COR['alerta'] if rest <= 300 else COR['sucesso'])
                self.lbl_int.setText(fmt_hms(rest)[3:] if rest < 3600 else fmt_hms(rest))
                self.lbl_int.setStyleSheet(f"font-size:30px;font-weight:800;color:{cor_i}")
                fim = r.intervalo_inicio + timedelta(seconds=r.almoco_segundos)
                self.lbl_int_sub.setText(f"retorno previsto as {fim.strftime('%H:%M')}  ·  "
                                         f"intervalo de {r.almoco_segundos // 60} min")
            else:
                self.lbl_int.setText("+" + (fmt_hms(rest)[3:] if -rest < 3600 else fmt_hms(rest)))
                self.lbl_int.setStyleSheet(f"font-size:30px;font-weight:800;color:{COR['perigo']}")
                self.lbl_int_sub.setText("intervalo estourado — registre o retorno")
        else:
            self.card_int.setVisible(False)

        # hora extra
        if self.extra_pendente:
            self.lbl_extra.setText(f"Pedido #{self.extra_pendente.get('id')} de "
                                   f"{self.extra_pendente.get('minutos_solicitados')} min aguardando o gestor")
            self.lbl_extra.setStyleSheet(f"font-size:11px;color:{COR['roxo']};font-weight:600")
        elif self.extra_aprovada_min:
            self.lbl_extra.setText(f"Hora extra aprovada hoje: {self.extra_aprovada_min} min")
            self.lbl_extra.setStyleSheet(f"font-size:11px;color:{COR['sucesso']};font-weight:600")
        else:
            self.lbl_extra.setText("")

        # botoes
        livre = not self.ocupado and self._sincronizou
        self.botoes['entrada'].setEnabled(livre and r.estado == 'fora')
        self.botoes['saida'].setEnabled(livre and r.estado in ('trabalhando', 'intervalo'))
        self.botoes['saida_intervalo'].setEnabled(livre and r.estado == 'trabalhando')
        self.botoes['retorno_intervalo'].setEnabled(livre and r.estado == 'intervalo')
        self.btn_extra.setEnabled(livre and r.estado in ('trabalhando', 'intervalo') and not self.extra_pendente)

        # conexao
        pend = self.fila.tamanho()
        if pend:
            self._pill(f"{pend} batida(s) aguardando envio", COR['alerta'])
        elif self.api.online:
            self._pill("Online", COR['sucesso'])
        else:
            self._pill("Offline", COR['perigo'])

        self.lbl_info.setText(f"v{AGENT_VERSION}  ·  {self.api.base}  ·  {socket.gethostname()}"
                              f"  ·  ocioso {int(segundos_ocioso())}s"
                              + (f"  ·  relogio {self.api.delta_relogio:+.0f}s" if abs(self.api.delta_relogio) > 60 else ""))

    def _pill(self, texto, cor):
        self.pill_conexao.setText("●  " + texto)
        self.pill_conexao.setStyleSheet(f"font-size:11px;font-weight:600;color:{cor};"
                                        f"background:{shade(cor, 1.75)};padding:3px 9px;border-radius:10px")

    def _render_jornada(self):
        r = self.resumo
        if not self.escala:
            for c in (self.col_entrada, self.col_saida, self.col_almoco, self.col_carga):
                c.setText("--")
            return
        if not r.trabalha_hoje:
            self.col_entrada.setText("Folga")
            self.col_saida.setText("Folga")
            self.col_almoco.setText("--")
            self.col_carga.setText("--")
            return
        self.col_entrada.setText(r.entrada_prevista.strftime('%H:%M') if r.entrada_prevista else '--:--')
        self.col_saida.setText(r.saida_prevista.strftime('%H:%M') if r.saida_prevista else '--:--')
        self.col_almoco.setText(f"{r.almoco_segundos // 60} min" if r.almoco_segundos else "sem")
        self.col_carga.setText(fmt_hm(r.carga_segundos) if r.carga_segundos else "--")

    # ================================================================
    # CHECK (30s): ociosidade + heartbeat
    # ================================================================
    def _check(self):
        if self.resumo.estado == 'trabalhando':
            idle = segundos_ocioso()
            limite = int(self.config_srv.get('ociosidade_segundos_limite')
                         or self.cfg.get('ociosidade_segundos', 300))
            if idle > limite:
                if not self.ocioso_desde:
                    self.ocioso_desde = self.api.agora() - timedelta(seconds=idle)
                    log(f"Ociosidade iniciada as {self.ocioso_desde:%H:%M:%S}")
            elif self.ocioso_desde:
                self._fechar_ociosidade()
        elif self.ocioso_desde:
            self._fechar_ociosidade()

        hb = max(30, int(self.cfg.get('heartbeat_segundos', 60)))
        if time.monotonic() - self._ultimo_heartbeat >= hb:
            self._ultimo_heartbeat = time.monotonic()
            self._em_thread(self.api.heartbeat)

    def _fechar_ociosidade(self):
        inicio, fim = self.ocioso_desde, self.api.agora()
        self.ocioso_desde = None
        log(f"Ociosidade reportada: {int((fim - inicio).total_seconds())}s")
        self._em_thread(lambda: self.api.ociosidade(inicio, fim))

    def _em_thread(self, fn):
        def wrapper():
            try:
                fn()
            except Exception as e:
                log(f"Erro em chamada assincrona: {e}")
        threading.Thread(target=wrapper, daemon=True).start()

    # ================================================================
    # BATIDAS
    # ================================================================
    def bater(self, tipo):
        if self.ocupado:
            return
        self.ocupado = True
        for b in self.botoes.values():
            b.setEnabled(False)
        self.botoes[tipo].setText("Registrando...")
        ocioso = self.ocioso_desde
        self.ocioso_desde = None

        def work():
            if ocioso:
                self.api.ociosidade(ocioso, self.api.agora())
            r = self.api.batida(tipo)
            if r.get('offline') and r.get('batida'):
                self.fila.adicionar(r['batida'])
            self.batida_pronta.emit(tipo, r)
        threading.Thread(target=work, daemon=True).start()

    def _on_batida(self, tipo, r):
        self.ocupado = False
        self.botoes[tipo].setText(TEXTO_BOTAO[tipo])
        offline = bool(r.get('offline'))
        if r.get('nao_autorizado'):
            self._sessao_expirada()
            return
        if not (r.get('ok') or offline):
            self._banner(r.get('erro') or 'Falha ao registrar a batida.', 'erro')
            self._tick()
            return
        msg = MSG_SUCESSO[tipo]
        if offline:
            # A batida esta na fila e entra no calculo por fila.de_hoje().
            self._banner(f"{msg} (sem conexao: sera enviada ao servidor assim que a internet voltar)", 'alerta',
                         persistente=True)
            self._notificar("Registrado sem conexao", f"{TEXTO_BOTAO[tipo]} pendente de envio.")
        else:
            momento = r.get('momento') or self.api.agora().strftime('%Y-%m-%d %H:%M:%S')
            self.batidas = self.batidas + [{'tipo': tipo, 'momento': momento}]
            nsr = r.get('nsr')
            self._banner(msg + (f"  ·  NSR {nsr:09d}" if isinstance(nsr, int) else ""), 'sucesso')
            self._notificar("DOT-ON", msg)
            QTimer.singleShot(1500, self.sincronizar)
        if tipo == 'saida':
            self.alertador.rearmar('carga_atingida')
        self._tick()

    def bater_saida(self):
        r = self.resumo
        if r.trabalha_hoje and r.carga_segundos > 0 and r.segundos_faltando > 60:
            falta = fmt_hm(r.segundos_faltando)
            resp = QMessageBox.question(
                self, "Saida antecipada",
                f"Faltam {falta} para cumprir sua carga de {fmt_hm(r.carga_segundos)}.\n\n"
                "Esse tempo entra como debito no seu banco de horas.\n"
                "Deseja encerrar o expediente mesmo assim?",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
            if resp != QMessageBox.Yes:
                return
        elif r.estado == 'intervalo':
            resp = QMessageBox.question(
                self, "Encerrar em intervalo",
                "Voce esta em intervalo. Encerrar o expediente agora?",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
            if resp != QMessageBox.Yes:
                return
        self.bater('saida')

    # ================================================================
    # HORA EXTRA
    # ================================================================
    def abrir_hora_extra(self):
        if self._dlg_extra_aberto or self.extra_pendente:
            return
        self._dlg_extra_aberto = True
        try:
            r = self.resumo
            sugerido = 60
            if r.carga_segundos > 0 and r.segundos_faltando < 0:
                sugerido = int((-r.segundos_faltando) / 60) + 30
            dlg = HoraExtraDialog(self, sugerido, contexto_carga(r))
            if dlg.exec_() != QDialog.Accepted:
                return
            minutos, just = dlg.resultado()
        finally:
            self._dlg_extra_aberto = False
        self.btn_extra.setEnabled(False)
        self.btn_extra.setText("Enviando...")

        def work():
            self.extra_enviada.emit(self.api.solicitar_extra(minutos, just), minutos)
        threading.Thread(target=work, daemon=True).start()

    def _on_extra_enviada(self, r, minutos):
        self.btn_extra.setText("Solicitar hora extra")
        if r.get('ok'):
            self.extra_pendente = {'id': r.get('id'), 'status': 'pendente', 'minutos_solicitados': minutos}
            self._extras_vistos[r.get('id')] = 'pendente'
            self._banner(f"Solicitacao de hora extra enviada ao gestor (#{r.get('id')}).", 'info')
            self._notificar("Hora extra solicitada", "Aguarde a aprovacao do gestor.")
        else:
            self._banner(r.get('erro') or 'Falha ao enviar solicitacao. Tente novamente.', 'erro')
        self._tick()

    def _pulsar_extra(self):
        precisa = (self.resumo.estado in ('trabalhando', 'intervalo') and self.resumo.limite_segundos > 0
                   and self.resumo.segundos_trabalhados >= self.resumo.limite_segundos
                   and not self.extra_pendente and self.btn_extra.isEnabled())
        if precisa:
            self._pulso_on = not self._pulso_on
            self.btn_extra.setText("⚠  Solicitar hora extra" if self._pulso_on else "Solicitar hora extra")
            self.btn_extra.setStyleSheet(btn_css(COR['roxo'] if self._pulso_on else shade(COR['roxo'], 1.35)))
        elif self._pulso_on:
            self._pulso_on = False
            self.btn_extra.setText("Solicitar hora extra")
            self.btn_extra.setStyleSheet(btn_css(COR['roxo']))

    # ================================================================
    # ALERTAS / FEEDBACK
    # ================================================================
    def _aplicar_alerta(self, a: Alerta):
        log(f"ALERTA {a.nome}: {a.mensagem}")
        self._banner(a.mensagem, a.nivel, persistente=a.nome in ('carga_atingida', 'intervalo_estourado'))
        self._notificar(a.titulo, a.mensagem, urgente=a.trazer_janela)
        if a.trazer_janela:
            self.trazer_para_frente()
        if a.abrir_extra:
            QTimer.singleShot(500, self.abrir_hora_extra)

    def _banner(self, msg, tipo='info', persistente=False):
        cores = {
            'sucesso': (COR['sucesso'], shade(COR['sucesso'], 1.75)),
            'erro':    (COR['perigo'], shade(COR['perigo'], 1.75)),
            'alerta':  (COR['alerta'], shade(COR['alerta'], 1.7)),
            'info':    (COR['primaria'], shade(COR['primaria'], 1.75)),
        }
        cor, bg = cores.get(tipo, cores['info'])
        self.banner.setText(msg)
        self.banner.setStyleSheet(f"background:{bg};color:{cor};font-weight:600;font-size:12px;"
                                  f"padding:9px 12px;border-radius:10px")
        self.banner.setVisible(True)
        if self._banner_timer:
            self._banner_timer.stop()
            self._banner_timer = None
        if not persistente:
            self._banner_timer = QTimer(self)
            self._banner_timer.setSingleShot(True)
            self._banner_timer.timeout.connect(lambda: self.banner.setVisible(False))
            self._banner_timer.start(6000)

    def _notificar(self, titulo, msg, urgente=False):
        try:
            icone = QSystemTrayIcon.Warning if urgente else QSystemTrayIcon.Information
            self.tray.showMessage(titulo, msg, icone, 8000 if urgente else 4000)
        except Exception:
            pass

    def trazer_para_frente(self):
        self.show()
        self.setWindowState((self.windowState() & ~Qt.WindowMinimized) | Qt.WindowActive)
        self.raise_()
        self.activateWindow()
        QApplication.alert(self, 3000)

    # ================================================================
    # BANDEJA / STARTUP / LOGOUT / UPDATE
    # ================================================================
    def _toggle_startup(self, marcado):
        ok = startup.ativar() if marcado else startup.desativar()
        if not ok:
            self.a_startup.setChecked(startup.esta_ativo())
            self._banner("Nao foi possivel alterar a inicializacao com o Windows.", 'erro')
        else:
            self._banner("O DOT-ON vai iniciar com o Windows." if marcado
                         else "O DOT-ON nao vai mais iniciar com o Windows.", 'info')

    def _logout(self):
        if self.resumo.estado != 'fora':
            resp = QMessageBox.question(self, "Sair da conta",
                                        "Seu expediente continua aberto no servidor. Sair da conta mesmo assim?",
                                        QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
            if resp != QMessageBox.Yes:
                return
        self.api.logout()
        QMessageBox.information(self, "DOT-ON", "Voce foi desconectado.")
        QApplication.instance().quit()

    def _checar_update(self):
        if not CONGELADO:
            log("Update: modo desenvolvimento, checagem ignorada.")
            return

        def work():
            m = updater.verificar(self.api.base)
            if m:
                self.atualizacao_disp.emit(m)
        threading.Thread(target=work, daemon=True).start()

    def _on_atualizacao(self, m):
        ver = m.get('versao', '?')
        notas = (m.get('notas') or '').strip()
        corpo = f"Versao {ver} disponivel." + (f"\n\n{notas}" if notas else "")
        if m.get('obrigatoria'):
            QMessageBox.information(self, "Atualizacao obrigatoria",
                                    corpo + "\n\nEsta atualizacao e obrigatoria e sera instalada agora. O app vai reiniciar.")
            self._aplicar_update(m)
        else:
            resp = QMessageBox.question(self, "Atualizacao disponivel",
                                        corpo + "\n\nAtualizar agora? O DOT-ON vai fechar e reabrir automaticamente.",
                                        QMessageBox.Yes | QMessageBox.No, QMessageBox.Yes)
            if resp == QMessageBox.Yes:
                self._aplicar_update(m)

    def _aplicar_update(self, m):
        self._banner(f"Baixando atualizacao {m.get('versao')}...", 'info', persistente=True)
        QApplication.processEvents()
        ok, err = updater.baixar_e_instalar(m, self.api.base)
        if not ok:
            self._banner(f"Falha na atualizacao: {err}", 'erro')
            return
        QMessageBox.information(self, "DOT-ON",
                                "Atualizacao baixada. O app vai fechar e reabrir automaticamente na nova versao.")
        QApplication.instance().quit()

    def closeEvent(self, e):
        e.ignore()
        self.hide()
        self._notificar("DOT-ON", "O agente continua rodando em segundo plano.")
