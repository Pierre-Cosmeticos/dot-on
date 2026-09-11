"""
Configuracao local: config.json (gravado na pasta de dados) + dot-on.ini
(entregue pelo instalador da empresa ao lado do .exe; tem prioridade).
"""
import configparser
import json

from .paths import CONFIG_FILE, INI_FILE, log

DEFAULT_API_URL = "https://dot-on.com.br/app/api/"

DEFAULTS = {
    'api_url': DEFAULT_API_URL,
    'ociosidade_segundos': 300,          # limite local; a empresa pode sobrescrever via /config
    'verificar_segundos': 30,            # ociosidade + heartbeat
    'sincronizar_segundos': 60,          # re-le sessao/escala/extra na API
    'heartbeat_segundos': 60,
    'tolerancia_entrada_min': 10,        # aviso de entrada atrasada
    'sem_pausa_horas': 6,                # lembrete de intervalo
}


def ler_ini() -> dict:
    dados = {}
    if not INI_FILE.exists():
        return dados
    try:
        cp = configparser.ConfigParser()
        cp.read(INI_FILE, encoding='utf-8')
        for chave in ('api_url', 'empresa_nome'):
            if cp.has_option('server', chave):
                dados[chave] = cp.get('server', chave).strip()
    except Exception as e:
        log(f"Falha ao ler dot-on.ini: {e}")
    return dados


def carregar() -> dict:
    cfg = {}
    if CONFIG_FILE.exists():
        try:
            cfg = json.loads(CONFIG_FILE.read_text(encoding='utf-8'))
            if not isinstance(cfg, dict):
                cfg = {}
        except (OSError, ValueError):
            cfg = {}
    for k, v in DEFAULTS.items():
        cfg.setdefault(k, v)
    ini = ler_ini()
    if ini.get('api_url'):
        cfg['api_url'] = ini['api_url']
    if ini.get('empresa_nome'):
        cfg['empresa_nome'] = ini['empresa_nome']
    salvar(cfg)
    return cfg


def salvar(cfg: dict) -> None:
    try:
        CONFIG_FILE.write_text(json.dumps(cfg, indent=2, ensure_ascii=False), encoding='utf-8')
    except OSError:
        pass
