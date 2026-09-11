"""Teste de fumaca da janela principal com Qt offscreen e API falsa (sem rede)."""
import os
os.environ.setdefault('QT_QPA_PLATFORM', 'offscreen')

import time
from datetime import datetime

import pytest
from PyQt5.QtGui import QIcon
from PyQt5.QtWidgets import QApplication

from doton.api import APIClient
from doton.fila import Fila
from doton.ui import principal as P


class FakeAPI(APIClient):
    def __init__(self):
        self.base = 'http://fake'
        self.token = 't'
        self.user = {'nome': 'Fulano', 'matricula': '1'}
        self.empresa = None
        self.precisa_trocar_senha = 0
        self.delta_relogio = 0.0
        self.online = True
        self.fixo = datetime(2026, 9, 11, 12, 58, 30)
        self.batidas_srv = []
        self.extras = []
        self.posts = []

    def agora(self):
        return self.fixo

    def sessao_atual(self):
        return {'ok': True, 'agora': self.fixo.strftime('%Y-%m-%d %H:%M:%S'), 'batidas': self.batidas_srv}

    def escala(self):
        return {'entrada': '08:00:00', 'saida': '17:00:00', 'almoco_minutos': 60,
                'carga_diaria_minutos': 480, 'trabalha_hoje': 1}

    def config(self):
        return {}

    def extras_hoje(self):
        return self.extras

    def sessao_mes(self, mes):
        return {'ok': True, 'mes': mes, 'dias': [], 'resumo': {}}

    def heartbeat(self):
        return {'ok': True}

    def batida(self, tipo, momento=None, client_uid=None):
        self.posts.append(tipo)
        m = self.fixo.strftime('%Y-%m-%d %H:%M:%S')
        self.batidas_srv.append({'tipo': tipo, 'momento': m})
        return {'ok': True, 'nsr': 1, 'momento': m}


@pytest.fixture(scope='module')
def app():
    return QApplication.instance() or QApplication([])


def nova_janela(api, tmp_path):
    w = P.MainWindow(api, {'verificar_segundos': 30, 'sincronizar_segundos': 60},
                     Fila(tmp_path / 'fila.json'), QIcon())
    for t in (w.t_tick, w.t_check, w.t_sync, w.t_espelho, w.t_pulso):
        t.stop()
    return w


@pytest.fixture
def janela(app, tmp_path, monkeypatch):
    monkeypatch.setattr(P, 'SESSAO_CACHE_FILE', tmp_path / 'cache.json')
    api = FakeAPI()
    w = nova_janela(api, tmp_path)
    notif = []
    monkeypatch.setattr(w, '_notificar', lambda titulo, msg, urgente=False: notif.append(titulo))
    w.notif = notif
    # O dialogo real (exec_) travaria o loop offscreen; registra a abertura.
    w.extra_aberta = []
    monkeypatch.setattr(w, 'abrir_hora_extra', lambda: w.extra_aberta.append(1))
    return w, api


def sync(w, api):
    w._on_sync({'recusadas': [], 'sessao': api.sessao_atual(), 'escala': api.escala(),
                'config': api.config(), 'extras': api.extras_hoje()})


def test_boot_fora_e_jornada(janela):
    w, api = janela
    api.fixo = datetime(2026, 9, 11, 7, 30)
    sync(w, api)
    assert w.lbl_estado.text() == 'FORA DO EXPEDIENTE'
    assert w.col_entrada.text() == '08:00' and w.col_saida.text() == '17:00'
    assert w.col_almoco.text() == '60 min' and w.col_carga.text() == '8h00'
    assert w.botoes['entrada'].isEnabled() and not w.botoes['saida'].isEnabled()


def test_intervalo_restante_e_alerta_90s(janela):
    w, api = janela
    api.batidas_srv = [{'tipo': 'entrada', 'momento': '2026-09-11 08:00:00'},
                       {'tipo': 'saida_intervalo', 'momento': '2026-09-11 12:00:00'}]
    api.fixo = datetime(2026, 9, 11, 12, 58, 30)
    sync(w, api)
    assert w.lbl_estado.text() == 'EM INTERVALO'
    assert not w.card_int.isHidden()
    assert w.lbl_int.text() == '01:30'
    assert w.lbl_cron.text() == '04:00:00'
    assert 'Intervalo acabando' in w.notif
    assert w.botoes['retorno_intervalo'].isEnabled() and not w.botoes['entrada'].isEnabled()


def test_carga_atingida_abre_extra(janela):
    w, api = janela
    aberto = w.extra_aberta
    api.batidas_srv = [{'tipo': 'entrada', 'momento': '2026-09-11 08:00:00'},
                       {'tipo': 'saida_intervalo', 'momento': '2026-09-11 12:00:00'},
                       {'tipo': 'retorno_intervalo', 'momento': '2026-09-11 13:00:00'}]
    api.fixo = datetime(2026, 9, 11, 17, 0, 5)
    sync(w, api)
    assert 'Carga diaria atingida' in w.notif
    fim = time.monotonic() + 2
    while not aberto and time.monotonic() < fim:
        QApplication.processEvents()
        time.sleep(0.05)
    assert aberto == [1]
    assert 'cumprida' in w.lbl_carga.text()


def test_extra_pendente_bloqueia_alerta_e_botao(janela):
    w, api = janela
    api.extras = [{'id': 7, 'status': 'pendente', 'minutos_solicitados': 60, 'minutos_aprovados': 0}]
    api.batidas_srv = [{'tipo': 'entrada', 'momento': '2026-09-11 08:00:00'}]
    api.fixo = datetime(2026, 9, 11, 17, 0, 5)
    sync(w, api)
    assert 'Carga diaria atingida' not in w.notif
    assert not w.btn_extra.isEnabled()
    assert '#7' in w.lbl_extra.text()
    api.extras = [{'id': 7, 'status': 'aprovada', 'minutos_solicitados': 60, 'minutos_aprovados': 60}]
    sync(w, api)
    assert 'Hora extra aprovada' in w.notif
    assert w.extra_aprovada_min == 60
    assert w.resumo.fim_previsto == datetime(2026, 9, 11, 18, 0)


def test_extra_rejeitada_rearma_alerta(janela):
    w, api = janela
    api.extras = [{'id': 8, 'status': 'pendente', 'minutos_solicitados': 60, 'minutos_aprovados': 0}]
    api.batidas_srv = [{'tipo': 'entrada', 'momento': '2026-09-11 08:00:00'}]
    api.fixo = datetime(2026, 9, 11, 17, 0, 5)
    sync(w, api)
    api.extras = [{'id': 8, 'status': 'rejeitada', 'minutos_solicitados': 60, 'minutos_aprovados': 0,
                   'motivo_decisao': 'sem demanda'}]
    sync(w, api)
    assert 'Hora extra rejeitada' in w.notif
    assert 'Carga diaria atingida' in w.notif      # re-armado apos a rejeicao
    assert w.extra_pendente is None
    assert w.btn_extra.isEnabled()


def test_batida_online_atualiza_estado(janela):
    w, api = janela
    api.fixo = datetime(2026, 9, 11, 8, 0)
    sync(w, api)
    w.bater('entrada')
    fim = time.monotonic() + 3
    while w.ocupado and time.monotonic() < fim:
        QApplication.processEvents()
        time.sleep(0.05)
    assert api.posts == ['entrada']
    assert w.lbl_estado.text() == 'TRABALHANDO'
    assert w.botoes['saida_intervalo'].isEnabled()


def test_batida_offline_vai_para_fila(janela, monkeypatch):
    w, api = janela
    api.fixo = datetime(2026, 9, 11, 8, 0)
    sync(w, api)

    def offline(tipo, momento=None, client_uid=None):
        api.online = False
        return {'ok': False, 'offline': True, 'erro': 'sem rede',
                'batida': {'tipo': tipo, 'momento': api.fixo.strftime('%Y-%m-%d %H:%M:%S'), 'client_uid': 'x'}}
    monkeypatch.setattr(api, 'batida', offline)
    w.bater('entrada')
    fim = time.monotonic() + 3
    while w.ocupado and time.monotonic() < fim:
        QApplication.processEvents()
        time.sleep(0.05)
    assert w.fila.tamanho() == 1
    assert w.lbl_estado.text() == 'TRABALHANDO'      # a fila entra no calculo
    assert 'aguardando envio' in w.pill_conexao.text()


def test_cache_restaura_boot_offline(app, tmp_path, monkeypatch):
    cache = tmp_path / 'cache.json'
    monkeypatch.setattr(P, 'SESSAO_CACHE_FILE', cache)
    api = FakeAPI()
    api.batidas_srv = [{'tipo': 'entrada', 'momento': '2026-09-11 08:00:00'}]
    api.fixo = datetime(2026, 9, 11, 9, 0)
    w = nova_janela(api, tmp_path)
    monkeypatch.setattr(w, 'abrir_hora_extra', lambda: None)
    sync(w, api)
    assert cache.exists()

    api2 = FakeAPI()
    api2.fixo = datetime(2026, 9, 11, 9, 30)
    api2.online = False
    w2 = nova_janela(api2, tmp_path)
    monkeypatch.setattr(w2, 'abrir_hora_extra', lambda: None)
    w2._on_sync({'sessao': {'ok': False, 'offline': True}, 'escala': None, 'config': None, 'extras': None})
    assert w2.lbl_estado.text() == 'TRABALHANDO'
    assert w2.lbl_cron.text() == '01:30:00'
    assert w2.botoes['saida_intervalo'].isEnabled()
    assert w2.col_entrada.text() == '08:00'
