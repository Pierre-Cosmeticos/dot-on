"""
Cliente HTTP da API do DOT-ON.

Toda conta de ponto usa agora(): o relogio local + o delta medido contra o
campo 'agora' que a API devolve. Assim um relogio errado (ou em outro fuso)
na maquina do funcionario nao desloca o tempo trabalhado.
"""
import json
import socket
import uuid
from datetime import datetime, timedelta
from typing import Optional

import requests

from .jornada import FMT, parse_momento
from .paths import TOKEN_FILE, log

TIMEOUT = 15


class APIClient:
    def __init__(self, base_url: str):
        self.base = base_url.rstrip('/')
        self.token: Optional[str] = None
        self.user: Optional[dict] = None
        self.empresa: Optional[dict] = None
        self.precisa_trocar_senha = 0
        self.delta_relogio = 0.0
        self.online = True
        self._carregar_token()

    # ---------------- sessao local ----------------
    def _carregar_token(self):
        if not TOKEN_FILE.exists():
            return
        try:
            d = json.loads(TOKEN_FILE.read_text(encoding='utf-8'))
            self.token, self.user, self.empresa = d.get('token'), d.get('user'), d.get('empresa')
        except (OSError, ValueError):
            pass

    def _salvar_token(self):
        try:
            TOKEN_FILE.write_text(json.dumps({'token': self.token, 'user': self.user,
                                              'empresa': self.empresa}), encoding='utf-8')
        except OSError:
            pass

    def logado(self) -> bool:
        return bool(self.token and self.user)

    def logout(self):
        self.token = self.user = self.empresa = None
        self.precisa_trocar_senha = 0
        try:
            if TOKEN_FILE.exists():
                TOKEN_FILE.unlink()
        except OSError:
            pass

    # ---------------- relogio ----------------
    def agora(self) -> datetime:
        return datetime.now() + timedelta(seconds=self.delta_relogio)

    def hoje(self) -> str:
        return self.agora().strftime('%Y-%m-%d')

    def _sincronizar_relogio(self, agora_srv):
        ts = parse_momento(agora_srv)
        if not ts:
            return
        delta = (ts - datetime.now()).total_seconds()
        if abs(delta - self.delta_relogio) > 2:
            log(f"Relogio ajustado pelo servidor: delta={delta:+.0f}s")
        self.delta_relogio = delta

    # ---------------- transporte ----------------
    def _headers(self):
        h = {'Content-Type': 'application/json'}
        if self.token:
            h['X-Auth-Token'] = self.token
        return h

    def _tratar(self, r) -> dict:
        j = r.json()
        self.online = True
        if not isinstance(j, dict):
            return {'ok': False, 'erro': 'resposta invalida'}
        if j.get('agora'):
            self._sincronizar_relogio(j['agora'])
        if r.status_code == 401:
            j['nao_autorizado'] = True
        return j

    def _get(self, rota: str, timeout=10) -> dict:
        try:
            return self._tratar(requests.get(f"{self.base}/{rota}", headers=self._headers(), timeout=timeout))
        except Exception as e:
            self.online = False
            return {'ok': False, 'offline': True, 'erro': str(e)}

    def _post(self, rota: str, payload: dict, timeout=TIMEOUT) -> dict:
        try:
            return self._tratar(requests.post(f"{self.base}/{rota}", headers=self._headers(),
                                              json=payload, timeout=timeout))
        except Exception as e:
            self.online = False
            return {'ok': False, 'offline': True, 'erro': str(e)}

    # ---------------- autenticacao ----------------
    def login(self, email: str, senha: str):
        j = self._post('login', {'email': email, 'senha': senha})
        if j.get('ok'):
            self.token, self.user, self.empresa = j['token'], j['user'], j.get('empresa')
            self.precisa_trocar_senha = int(j.get('precisa_trocar_senha') or 0)
            self._salvar_token()
            log(f"Login OK: {self.user.get('nome')}")
            return True, j['user']
        if j.get('offline'):
            return False, f"Falha de conexao: {j.get('erro')}"
        return False, j.get('erro', 'Erro desconhecido')

    def trocar_senha(self, senha_atual: str, nova: str) -> dict:
        j = self._post('trocar-senha', {'senha_atual': senha_atual, 'nova_senha': nova})
        if j.get('ok'):
            self.precisa_trocar_senha = 0
        elif j.get('offline'):
            j['erro'] = f"Falha de conexao: {j.get('erro')}"
        return j

    # ---------------- ponto ----------------
    def batida(self, tipo: str, momento: Optional[str] = None, client_uid: Optional[str] = None) -> dict:
        """Registra uma batida. Sem rede devolve offline=True e o payload para a fila."""
        payload = {
            'tipo': tipo,
            'momento': momento or self.agora().strftime(FMT),
            'hostname': socket.gethostname(),
            'client_uid': client_uid or uuid.uuid4().hex,
        }
        j = self._post('batida', payload)
        if j.get('offline'):
            log(f"Erro batida (sem conexao): {j.get('erro')}")
            j['batida'] = payload
        return j

    def ociosidade(self, inicio: datetime, fim: datetime, motivo='inatividade') -> dict:
        return self._post('ociosidade', {'inicio': inicio.strftime(FMT),
                                         'fim': fim.strftime(FMT), 'motivo': motivo})

    def heartbeat(self) -> dict:
        return self._post('heartbeat', {}, timeout=5)

    # ---------------- hora extra ----------------
    def solicitar_extra(self, minutos: int, justificativa: str) -> dict:
        return self._post('hora-extra/solicitar', {'minutos': int(minutos), 'justificativa': justificativa})

    def extras_hoje(self) -> Optional[list]:
        """Pedidos de hora extra do dia (None se a API estiver fora)."""
        j = self._get('hora-extra/hoje')
        return list(j.get('pedidos') or []) if j.get('ok') else None

    # ---------------- leituras ----------------
    def escala(self) -> Optional[dict]:
        j = self._get('escala')
        if not j.get('ok'):
            return None
        esc = j.get('escala')
        return esc if isinstance(esc, dict) else {}

    def config(self) -> Optional[dict]:
        """Parametros da empresa. Empresa sem configuracao devolve {} (e nao lista)."""
        j = self._get('config')
        if not j.get('ok'):
            return None
        cfg = j.get('config')
        return cfg if isinstance(cfg, dict) else {}

    def sessao_atual(self) -> dict:
        return self._get('sessao/atual')

    def sessao_mes(self, mes: str) -> dict:
        return self._get(f'sessao/mes/{mes}')
