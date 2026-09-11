"""
Auto-update do executavel.

O agente checa o manifesto (GET /agent/versao) logo apos abrir. Havendo versao
mais nova, baixa o .exe (validando sha256 e tamanho), grava um .bat que espera
este processo terminar, substitui o executavel e reabre o app.
"""
import hashlib
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Optional

import requests

from . import AGENT_VERSION
from .paths import UPDATE_DIR, log


def _ver_tuple(v):
    nums = re.findall(r'\d+', str(v or ''))
    return tuple(int(n) for n in nums[:4]) or (0,)


def verificar(api_base: str) -> Optional[dict]:
    """Manifesto do servidor se houver versao mais nova; senao None."""
    try:
        j = requests.get(f"{api_base.rstrip('/')}/agent/versao", timeout=10).json()
        if not j.get('ok'):
            return None
        if _ver_tuple(j.get('versao')) > _ver_tuple(AGENT_VERSION):
            log(f"Update disponivel: {AGENT_VERSION} -> {j.get('versao')}")
            return j
        log(f"Update: ja na versao mais recente ({AGENT_VERSION}).")
    except Exception as e:
        log(f"Update: falha ao checar versao: {e}")
    return None


def _lancar_updater(novo_exe: Path):
    alvo = Path(sys.executable)
    bat = novo_exe.parent / 'update.bat'
    pid = os.getpid()
    conteudo = (
        "@echo off\r\n"
        "setlocal\r\n"
        f'set "PID={pid}"\r\n'
        ":wait\r\n"
        'tasklist /FI "PID eq %PID%" 2>nul | find "%PID%" >nul\r\n'
        "if not errorlevel 1 (\r\n"
        "  timeout /t 1 /nobreak >nul\r\n"
        "  goto wait\r\n"
        ")\r\n"
        ":copyloop\r\n"
        f'copy /Y "{novo_exe}" "{alvo}" >nul\r\n'
        "if errorlevel 1 (\r\n"
        "  timeout /t 1 /nobreak >nul\r\n"
        "  goto copyloop\r\n"
        ")\r\n"
        f'start "" "{alvo}"\r\n'
        f'del "{novo_exe}" >nul 2>&1\r\n'
        '(goto) 2>nul & del "%~f0"\r\n'
    )
    # newline='' preserva os CRLF; 'mbcs' e o codepage ANSI do cmd.exe (caminhos com acento).
    try:
        bat.write_text(conteudo, encoding='mbcs', newline='')
    except (LookupError, UnicodeEncodeError):
        bat.write_text(conteudo, encoding='utf-8', newline='')
    CREATE_NO_WINDOW = 0x08000000
    DETACHED_PROCESS = 0x00000008
    subprocess.Popen(['cmd', '/c', str(bat)],
                     creationflags=CREATE_NO_WINDOW | DETACHED_PROCESS, close_fds=True)
    log(f"Update: updater lancado (PID atual {pid}); aguardando encerramento.")


def baixar_e_instalar(manifest: dict, api_base: str):
    """Baixa o novo .exe, valida e dispara o updater. Retorna (ok, erro)."""
    try:
        url = manifest.get('url') or f"{api_base.rstrip('/')}/agent/download"
        UPDATE_DIR.mkdir(parents=True, exist_ok=True)
        novo = UPDATE_DIR / 'DOT-ON-Agent.new.exe'
        h = hashlib.sha256()
        with requests.get(url, stream=True, timeout=180) as resp:
            resp.raise_for_status()
            with open(novo, 'wb') as f:
                for chunk in resp.iter_content(65536):
                    if chunk:
                        f.write(chunk)
                        h.update(chunk)
        sha = manifest.get('sha256')
        if sha and h.hexdigest().lower() != str(sha).lower():
            novo.unlink(missing_ok=True)
            return False, "checksum invalido (download corrompido)"
        tam = manifest.get('tamanho')
        if tam and novo.stat().st_size != int(tam):
            novo.unlink(missing_ok=True)
            return False, "tamanho invalido"
        _lancar_updater(novo)
        return True, None
    except Exception as e:
        log(f"Update: erro ao baixar/instalar: {e}")
        return False, str(e)
