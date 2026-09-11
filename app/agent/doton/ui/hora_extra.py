"""Dialogo de solicitacao de hora extra."""
from PyQt5.QtWidgets import QDialog, QHBoxLayout, QLabel, QSpinBox, QTextEdit, QVBoxLayout

from ..jornada import fmt_hm
from .tema import COR, botao


class HoraExtraDialog(QDialog):
    """Pergunta minutos + justificativa. Use .exec_() e depois .resultado()."""

    def __init__(self, parent=None, minutos_sugeridos: int = 60, contexto: str = ''):
        super().__init__(parent)
        self.setWindowTitle("Solicitar hora extra")
        self.setFixedSize(440, 360)
        lay = QVBoxLayout(self)
        lay.setContentsMargins(24, 20, 24, 20)
        lay.setSpacing(10)

        t = QLabel("Solicitar hora extra")
        t.setStyleSheet(f"font-size:17px;font-weight:700;color:{COR['roxo']}")
        lay.addWidget(t)
        if contexto:
            c = QLabel(contexto)
            c.setWordWrap(True)
            c.setStyleSheet(f"color:{COR['texto_fraco']};font-size:12px")
            lay.addWidget(c)

        lay.addWidget(QLabel("Quantos minutos extras voce precisa?"))
        self.sp = QSpinBox()
        self.sp.setRange(15, 240)
        self.sp.setSingleStep(15)
        self.sp.setSuffix(" min")
        self.sp.setValue(max(15, min(240, int(round(minutos_sugeridos / 15.0)) * 15 or 60)))
        lay.addWidget(self.sp)

        lay.addWidget(QLabel("Justificativa (obrigatoria):"))
        self.txt = QTextEdit()
        self.txt.setMaximumHeight(90)
        self.txt.setPlaceholderText("Descreva o motivo da hora extra...")
        lay.addWidget(self.txt)

        self.erro = QLabel("")
        self.erro.setStyleSheet(f"color:{COR['perigo']};font-size:12px")
        lay.addWidget(self.erro)

        btns = QHBoxLayout()
        btns.setSpacing(10)
        cancel = botao("Cancelar", COR['cinza'], alto=False)
        ok = botao("Enviar solicitacao", COR['roxo'], alto=False)
        btns.addWidget(cancel)
        btns.addWidget(ok)
        lay.addLayout(btns)
        cancel.clicked.connect(self.reject)
        ok.clicked.connect(self._confirmar)

    def _confirmar(self):
        if len(self.txt.toPlainText().strip()) < 5:
            self.erro.setText("Descreva a justificativa (minimo 5 caracteres).")
            return
        self.accept()

    def resultado(self):
        return self.sp.value(), self.txt.toPlainText().strip()


def contexto_carga(resumo) -> str:
    """Texto explicativo mostrado quando o dialogo abre por carga atingida."""
    if resumo.carga_segundos <= 0:
        return ''
    alem = -resumo.segundos_faltando
    if alem >= 0:
        return (f"Voce ja cumpriu sua carga de {fmt_hm(resumo.limite_segundos)} "
                f"e esta {fmt_hm(alem)} alem. O gestor precisa aprovar para o tempo "
                f"contar como hora extra.")
    return f"Faltam {fmt_hm(resumo.segundos_faltando)} para sua carga de {fmt_hm(resumo.limite_segundos)}."
