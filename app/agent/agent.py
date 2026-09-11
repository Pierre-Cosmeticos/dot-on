"""
DOT-ON Agent - Registro de Ponto Eletronico (Cliente Desktop Windows)
=====================================================================
Ponto de entrada. O codigo vive no pacote `doton/`:
  jornada.py / alertas.py  regras de ponto e avisos (puros, testados em tests/)
  api.py / fila.py         servidor e fila offline
  ui/                      janela, login, hora extra, espelho (PyQt5)
  updater.py / startup.py  auto-update e "iniciar com o Windows"

Funcoes:
 - Entrada, intervalo (com tempo restante e aviso 1min30s antes do fim),
   retorno e saida; o expediente NUNCA e encerrado automaticamente.
 - Tempo trabalhado calculado pelas batidas do servidor, mesmo apos reabrir.
 - Jornada prevista do dia (entrada, saida, almoco, carga).
 - Hora extra com aprovacao do gestor; ao estourar a carga o agente abre a
   janela e o pedido automaticamente.
 - Avisos de entrada atrasada, pausa apos 6h, fim do expediente previsto.
 - Espelho do mes (banco de horas), ociosidade, fila offline, auto-update.

Configuracao:
 - O instalador da empresa entrega "dot-on.ini" ao lado do executavel; o
   api_url dele tem prioridade. Sem .ini, usa-se config.json (gerado na 1a
   execucao em %APPDATA%\\DOT-ON).
"""
import ctypes
import os
import sys
import traceback

from PyQt5.QtCore import Qt
from PyQt5.QtGui import QColor, QFont, QIcon, QPainter, QPixmap
from PyQt5.QtWidgets import QApplication, QDialog, QMessageBox

from doton import AGENT_VERSION, config
from doton.api import APIClient
from doton.fila import Fila
from doton.paths import FILA_FILE, log, recurso, rotacionar_log
from doton.ui.login import LoginDialog
from doton.ui.principal import MainWindow
from doton.ui.tema import APP_QSS


def app_icon() -> QIcon:
    ico = recurso('icon.ico')
    if os.path.exists(ico):
        ic = QIcon(ico)
        if not ic.isNull():
            return ic
    pm = QPixmap(64, 64)
    pm.fill(Qt.transparent)
    p = QPainter(pm)
    p.setRenderHint(QPainter.Antialiasing)
    p.setBrush(QColor('#2563eb'))
    p.setPen(Qt.NoPen)
    p.drawEllipse(4, 4, 56, 56)
    p.setPen(QColor('#fff'))
    p.setFont(QFont('Arial', 26, QFont.Bold))
    p.drawText(pm.rect(), Qt.AlignCenter, "D")
    p.end()
    return QIcon(pm)


def instancia_unica() -> bool:
    """Impede um segundo agente na mesma sessao do Windows (mutex 'Local\\')."""
    try:
        ERROR_ALREADY_EXISTS = 183
        ctypes.windll.kernel32.CreateMutexW(None, False, 'Local\\DOT-ON-Agent')
        return ctypes.windll.kernel32.GetLastError() != ERROR_ALREADY_EXISTS
    except Exception:
        return True


def main():
    def _excepthook(exc_type, exc, tb):
        log("ERRO NAO TRATADO:\n" + "".join(traceback.format_exception(exc_type, exc, tb)))
    sys.excepthook = _excepthook
    rotacionar_log()

    app = QApplication(sys.argv)
    app.setQuitOnLastWindowClosed(False)
    app.setStyleSheet(APP_QSS)
    icone = app_icon()
    app.setWindowIcon(icone)

    if not instancia_unica():
        log("Ja existe um DOT-ON Agent aberto nesta sessao; encerrando este.")
        QMessageBox.information(None, "DOT-ON",
                                "O DOT-ON Agent ja esta aberto.\nProcure o icone azul na bandeja, ao lado do relogio.")
        sys.exit(0)

    cfg = config.carregar()
    api = APIClient(cfg['api_url'])
    if not api.logado():
        if LoginDialog(api, cfg).exec_() != QDialog.Accepted:
            sys.exit(0)

    win = MainWindow(api, cfg, Fila(FILA_FILE), icone)
    win.show()
    log(f"DOT-ON Agent {AGENT_VERSION} iniciado.")
    sys.exit(app.exec_())


if __name__ == '__main__':
    main()
