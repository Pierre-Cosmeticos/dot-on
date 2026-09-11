"""
Fila de batidas feitas sem internet.

Queda de rede nao pode custar a batida do funcionario. Quando o POST falha por
rede, a batida (com o momento em que foi feita e um client_uid unico) fica em
disco e e reenviada nos ciclos seguintes. O client_uid torna o reenvio
idempotente no servidor, que tambem marca a batida como extemporanea.
"""
import json
import threading
from pathlib import Path
from typing import List


class Fila:
    def __init__(self, caminho: Path):
        self.caminho = Path(caminho)
        self.lock = threading.Lock()
        self._cache = None      # evita ler o disco a cada segundo (o tick chama de_hoje)

    def ler(self) -> List[dict]:
        if self._cache is not None:
            return list(self._cache)
        try:
            dados = json.loads(self.caminho.read_text(encoding='utf-8'))
            itens = [d for d in dados if isinstance(d, dict)] if isinstance(dados, list) else []
        except (OSError, ValueError):
            itens = []
        self._cache = itens
        return list(itens)

    def gravar(self, itens: List[dict]) -> None:
        self._cache = list(itens)
        try:
            if itens:
                self.caminho.write_text(json.dumps(itens, indent=2), encoding='utf-8')
            elif self.caminho.exists():
                self.caminho.unlink()
        except OSError:
            pass

    def adicionar(self, batida: dict) -> int:
        with self.lock:
            itens = self.ler()
            itens.append(batida)
            self.gravar(itens)
            return len(itens)

    def tamanho(self) -> int:
        return len(self.ler())

    def de_hoje(self, hoje: str) -> List[dict]:
        """Batidas validas cujo momento comeca com a data 'YYYY-MM-DD' informada."""
        return [b for b in self.ler()
                if b.get('tipo') and str(b.get('momento', '')).startswith(hoje)]
