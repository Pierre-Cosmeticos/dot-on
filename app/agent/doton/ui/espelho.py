"""Aba Espelho: saldo do banco de horas no mes e os ultimos dias."""
import threading
from datetime import datetime

from PyQt5.QtCore import Qt, pyqtSignal
from PyQt5.QtGui import QColor
from PyQt5.QtWidgets import (QAbstractItemView, QHBoxLayout, QHeaderView, QLabel,
                             QTableWidget, QTableWidgetItem, QVBoxLayout, QWidget)

from ..api import APIClient
from ..paths import log
from .tema import COR, botao, cartao, rotulo

MESES = ['janeiro', 'fevereiro', 'marco', 'abril', 'maio', 'junho', 'julho',
         'agosto', 'setembro', 'outubro', 'novembro', 'dezembro']
SITUACAO_COR = {
    'falta': COR['perigo'], 'falta_abonada': COR['cinza'], 'feriado': COR['primaria'],
}


def _hm(minutos) -> str:
    try:
        m = int(minutos or 0)
    except (TypeError, ValueError):
        return '--'
    sinal = '-' if m < 0 else ''
    m = abs(m)
    return f"{sinal}{m // 60}h{m % 60:02d}"


class EspelhoWidget(QWidget):
    dados_prontos = pyqtSignal(object)

    def __init__(self, api: APIClient, parent=None):
        super().__init__(parent)
        self.api = api
        self._carregando = False
        self._ultimo = None
        self.dados_prontos.connect(self._render)
        self._build()

    def _tile(self, titulo):
        c = cartao()
        lay = QVBoxLayout(c)
        lay.setContentsMargins(14, 10, 14, 10)
        lay.setSpacing(2)
        lay.addWidget(rotulo(titulo, 11))
        v = QLabel("--")
        v.setStyleSheet(f"font-size:20px;font-weight:800;color:{COR['texto']}")
        lay.addWidget(v)
        return c, v

    def _build(self):
        raiz = QVBoxLayout(self)
        raiz.setContentsMargins(0, 6, 0, 0)
        raiz.setSpacing(10)

        topo = QHBoxLayout()
        self.lbl_mes = QLabel("")
        self.lbl_mes.setStyleSheet(f"font-size:14px;font-weight:700;color:{COR['texto']}")
        topo.addWidget(self.lbl_mes)
        topo.addStretch()
        self.btn_atualizar = botao("Atualizar", COR['cinza'], alto=False)
        self.btn_atualizar.clicked.connect(self.atualizar)
        topo.addWidget(self.btn_atualizar)
        raiz.addLayout(topo)

        tiles = QHBoxLayout()
        tiles.setSpacing(10)
        c1, self.v_favor = self._tile("A favor")
        c2, self.v_debito = self._tile("Em debito")
        c3, self.v_saldo = self._tile("Saldo do mes")
        for c in (c1, c2, c3):
            tiles.addWidget(c)
        raiz.addLayout(tiles)

        self.tabela = QTableWidget(0, 5)
        self.tabela.setHorizontalHeaderLabels(["Dia", "Trabalhado", "Carga", "Saldo", "Situacao"])
        self.tabela.verticalHeader().setVisible(False)
        self.tabela.setEditTriggers(QAbstractItemView.NoEditTriggers)
        self.tabela.setSelectionMode(QAbstractItemView.NoSelection)
        self.tabela.setFocusPolicy(Qt.NoFocus)
        self.tabela.setShowGrid(False)
        h = self.tabela.horizontalHeader()
        h.setSectionResizeMode(QHeaderView.Stretch)
        h.setSectionResizeMode(0, QHeaderView.ResizeToContents)
        raiz.addWidget(self.tabela, 1)

        self.lbl_status = rotulo("", 11, alinhar=Qt.AlignCenter)
        raiz.addWidget(self.lbl_status)

    def atualizar(self):
        if self._carregando:
            return
        self._carregando = True
        self.btn_atualizar.setEnabled(False)
        self.lbl_status.setText("Carregando...")
        mes = self.api.agora().strftime('%Y-%m')

        def work():
            try:
                self.dados_prontos.emit(self.api.sessao_mes(mes))
            except Exception as e:
                log(f"Espelho: erro: {e}")
                self.dados_prontos.emit({'ok': False, 'erro': str(e)})
        threading.Thread(target=work, daemon=True).start()

    def _render(self, d):
        self._carregando = False
        self.btn_atualizar.setEnabled(True)
        if not d or not d.get('ok'):
            self.lbl_status.setText("Nao foi possivel carregar o espelho (sem conexao).")
            return
        self._ultimo = d
        mes = d.get('mes') or ''
        try:
            dt = datetime.strptime(mes, '%Y-%m')
            self.lbl_mes.setText(f"{MESES[dt.month - 1].capitalize()} de {dt.year}")
        except ValueError:
            self.lbl_mes.setText(mes)
        res = d.get('resumo') or {}
        self.v_favor.setText(_hm(res.get('minutos_a_favor')))
        self.v_favor.setStyleSheet(f"font-size:20px;font-weight:800;color:{COR['sucesso']}")
        self.v_debito.setText(_hm(res.get('minutos_em_debito')))
        self.v_debito.setStyleSheet(f"font-size:20px;font-weight:800;color:{COR['perigo']}")
        saldo = int(res.get('saldo_minutos') or 0)
        self.v_saldo.setText(('+' if saldo > 0 else '') + _hm(saldo))
        self.v_saldo.setStyleSheet(
            f"font-size:20px;font-weight:800;color:{COR['sucesso'] if saldo >= 0 else COR['perigo']}")

        dias = list(d.get('dias') or [])[:15]
        self.tabela.setRowCount(len(dias))
        for i, dia in enumerate(dias):
            data = str(dia.get('data_ref') or '')
            try:
                dt = datetime.strptime(data, '%Y-%m-%d')
                txt_data = dt.strftime('%d/%m') + ' ' + ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'][dt.weekday()]
            except ValueError:
                txt_data = data
            saldo_d = dia.get('saldo')
            sit = dia.get('situacao') or dia.get('status') or ''
            cor = SITUACAO_COR.get(str(dia.get('status') or ''), None)
            vals = [txt_data, _hm(dia.get('minutos_trabalhados')), _hm(dia.get('carga')),
                    ('' if saldo_d is None else ('+' if int(saldo_d) > 0 else '') + _hm(saldo_d)), sit]
            for j, v in enumerate(vals):
                it = QTableWidgetItem(v)
                it.setTextAlignment(Qt.AlignCenter if j else Qt.AlignLeft | Qt.AlignVCenter)
                if j == 3 and saldo_d is not None:
                    it.setForeground(QColor(COR['sucesso'] if int(saldo_d) >= 0 else COR['perigo']))
                if j == 4 and cor:
                    it.setForeground(QColor(cor))
                self.tabela.setItem(i, j, it)
        self.lbl_status.setText(f"Atualizado as {self.api.agora().strftime('%H:%M')}. "
                                "Espelho completo no portal do funcionario.")
