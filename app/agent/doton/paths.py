"""
Caminhos e log do agente.

Em modo congelado (PyInstaller onefile) __file__ aponta para uma pasta
temporaria (_MEIxxxx) que o Windows apaga ao fechar o app. Por isso:
 - EXE_DIR  = pasta onde o .exe realmente esta (LER o dot-on.ini do instalador)
 - DATA_DIR = %APPDATA%/DOT-ON (GRAVAR token, config, fila e log)
"""
import os
import sys
from datetime import datetime
from pathlib import Path

CONGELADO = bool(getattr(sys, 'frozen', False))

if CONGELADO:
    EXE_DIR = Path(sys.executable).parent
    DATA_DIR = Path(os.environ.get('APPDATA') or os.environ.get('LOCALAPPDATA') or EXE_DIR) / 'DOT-ON'
else:
    EXE_DIR = Path(__file__).resolve().parent.parent
    DATA_DIR = EXE_DIR
try:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
except OSError:
    DATA_DIR = EXE_DIR

CONFIG_FILE = DATA_DIR / 'config.json'
TOKEN_FILE = DATA_DIR / '.token'
LOG_FILE = DATA_DIR / 'agent.log'
FILA_FILE = DATA_DIR / 'batidas_pendentes.json'
SESSAO_CACHE_FILE = DATA_DIR / 'sessao_hoje.json'  # ultima sessao vista (boot sem internet)
UPDATE_DIR = DATA_DIR / 'update'
INI_FILE = EXE_DIR / 'dot-on.ini'
LOG_MAX_BYTES = 2 * 1024 * 1024

_escritas = 0


def recurso(nome: str) -> str:
    """Caminho de um recurso embutido (datas do PyInstaller ou pasta do script)."""
    base = getattr(sys, '_MEIPASS', None) or str(EXE_DIR)
    return os.path.join(base, nome)


def rotacionar_log() -> None:
    """Mantem no maximo um agent.log + um agent.log.old (o agente roda por dias)."""
    try:
        if LOG_FILE.exists() and LOG_FILE.stat().st_size > LOG_MAX_BYTES:
            velho = LOG_FILE.with_name('agent.log.old')
            if velho.exists():
                velho.unlink()
            LOG_FILE.rename(velho)
    except OSError:
        pass


def log(msg: str) -> None:
    global _escritas
    linha = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    try:
        print(linha)
    except Exception:
        pass
    try:
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(linha + '\n')
        _escritas += 1
        if _escritas % 200 == 0:
            rotacionar_log()
    except OSError:
        pass
