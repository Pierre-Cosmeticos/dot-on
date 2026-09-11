"""
"Iniciar com o Windows": cria/remove um atalho na pasta Startup do usuario.

Usa o WScript.Shell via PowerShell para nao depender de pywin32. Em modo
script (nao congelado) o atalho aponta para pythonw + agent.py.
"""
import os
import subprocess
import sys
from pathlib import Path

from .paths import CONGELADO, EXE_DIR, log

NOME_ATALHO = 'DOT-ON Agent.lnk'
CREATE_NO_WINDOW = 0x08000000


def pasta_startup() -> Path:
    base = os.environ.get('APPDATA') or str(Path.home())
    return Path(base) / 'Microsoft' / 'Windows' / 'Start Menu' / 'Programs' / 'Startup'


def caminho_atalho() -> Path:
    return pasta_startup() / NOME_ATALHO


def esta_ativo() -> bool:
    return caminho_atalho().exists()


def _alvo():
    if CONGELADO:
        return sys.executable, ''
    pyw = Path(sys.executable).with_name('pythonw.exe')
    exe = str(pyw if pyw.exists() else sys.executable)
    return exe, '"%s"' % (EXE_DIR / 'agent.py')


def _ps(s: str) -> str:
    """Escapa para string PowerShell entre aspas simples."""
    return s.replace("'", "''")


def ativar() -> bool:
    exe, args = _alvo()
    lnk = caminho_atalho()
    script = (
        "$s = (New-Object -ComObject WScript.Shell).CreateShortcut('%s');"
        "$s.TargetPath = '%s';"
        "$s.Arguments = '%s';"
        "$s.WorkingDirectory = '%s';"
        "$s.IconLocation = '%s,0';"
        "$s.Description = 'DOT-ON Agent - registro de ponto';"
        "$s.Save()"
    ) % (_ps(str(lnk)), _ps(exe), _ps(args), _ps(str(EXE_DIR)), _ps(exe))
    try:
        lnk.parent.mkdir(parents=True, exist_ok=True)
        subprocess.run(['powershell', '-NoProfile', '-NonInteractive', '-Command', script],
                       check=True, capture_output=True, timeout=30, creationflags=CREATE_NO_WINDOW)
        log("Startup: atalho criado.")
        return lnk.exists()
    except Exception as e:
        log(f"Startup: falha ao criar atalho: {e}")
        return False


def desativar() -> bool:
    try:
        lnk = caminho_atalho()
        if lnk.exists():
            lnk.unlink()
        log("Startup: atalho removido.")
        return True
    except OSError as e:
        log(f"Startup: falha ao remover atalho: {e}")
        return False
