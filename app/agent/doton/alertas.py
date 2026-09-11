"""
Regras de aviso ao funcionario (sem Qt).

O Alertador recebe o Resumo da jornada e decide quais avisos devem sair
AGORA, garantindo que cada um dispare uma unica vez por dia (ou por
intervalo, no caso dos avisos de intervalo). A interface so executa o que
vier na lista: notificar, trazer a janela para frente, abrir o dialogo de
hora extra.
"""
from dataclasses import dataclass
from datetime import datetime
from typing import List, Optional

from .jornada import Resumo, fmt_hm

AVISO_INTERVALO_SEG = 90        # 1min30s antes do fim do intervalo


@dataclass
class Alerta:
    nome: str
    titulo: str
    mensagem: str
    trazer_janela: bool = False
    abrir_extra: bool = False
    nivel: str = 'info'          # info | alerta | erro (cor do banner)


class Alertador:
    def __init__(self, tolerancia_min: int = 10, sem_pausa_horas: float = 6):
        self.tolerancia_min = max(0, int(tolerancia_min))
        self.sem_pausa_seg = float(sem_pausa_horas) * 3600
        self._disparados = set()
        self._dia = None

    def rearmar(self, nome: str):
        """Permite que um aviso dispare de novo (ex.: extra rejeitada)."""
        self._disparados = {k for k in self._disparados if k[0] != nome}

    def _chave(self, nome: str, extra=None):
        return (nome, extra)

    def _uma_vez(self, nome: str, extra=None) -> bool:
        k = self._chave(nome, extra)
        if k in self._disparados:
            return False
        self._disparados.add(k)
        return True

    def avaliar(self, r: Resumo, agora: datetime, extra_pendente: bool) -> List[Alerta]:
        if self._dia != agora.date():
            self._dia = agora.date()
            self._disparados = set()

        out: List[Alerta] = []

        # --- Intervalo ---
        if r.estado == 'intervalo' and r.almoco_segundos > 0 and r.intervalo_inicio:
            rest = r.segundos_intervalo_restante
            ini = r.intervalo_inicio
            if 0 < rest <= AVISO_INTERVALO_SEG and self._uma_vez('intervalo_acabando', ini):
                out.append(Alerta('intervalo_acabando', 'Intervalo acabando',
                                  f'Seu intervalo termina em {int(rest) // 60}min{int(rest) % 60:02d}s. '
                                  'Prepare-se para registrar o retorno.',
                                  trazer_janela=True, nivel='alerta'))
            elif rest <= 0 and self._uma_vez('intervalo_estourado', ini):
                out.append(Alerta('intervalo_estourado', 'Intervalo encerrado',
                                  'O tempo de intervalo terminou. Registre o retorno para voltar a contar o expediente.',
                                  trazer_janela=True, nivel='erro'))

        # --- Carga diaria atingida sem extra ---
        if (r.estado in ('trabalhando', 'intervalo') and r.carga_segundos > 0
                and r.segundos_trabalhados >= r.limite_segundos and not extra_pendente
                and self._uma_vez('carga_atingida')):
            out.append(Alerta('carga_atingida', 'Carga diaria atingida',
                              f'Voce completou sua carga de {fmt_hm(r.limite_segundos)}. '
                              'Solicite hora extra ao gestor ou encerre o expediente.',
                              trazer_janela=True, abrir_extra=True, nivel='alerta'))

        # --- Fim do expediente previsto (so lembra; a saida e sempre manual) ---
        if (r.estado == 'trabalhando' and r.fim_previsto and agora >= r.fim_previsto
                and self._uma_vez('fim_previsto')):
            out.append(Alerta('fim_previsto', 'Fim do expediente',
                              f'Seu expediente previsto terminou as {r.fim_previsto.strftime("%H:%M")}. '
                              'Encerre o expediente quando concluir.',
                              nivel='info'))

        # --- Entrada atrasada ---
        if (r.estado == 'fora' and r.trabalha_hoje and r.primeira_entrada is None
                and r.entrada_prevista is not None):
            limite = r.entrada_prevista.timestamp() + self.tolerancia_min * 60
            if agora.timestamp() >= limite and self._uma_vez('entrada_atrasada'):
                out.append(Alerta('entrada_atrasada', 'Entrada nao registrada',
                                  f'Sua entrada estava prevista para {r.entrada_prevista.strftime("%H:%M")} '
                                  'e ainda nao foi registrada. Inicie o expediente.',
                                  trazer_janela=True, nivel='alerta'))

        # --- Muito tempo sem pausa ---
        if (r.estado == 'trabalhando' and r.almoco_segundos > 0
                and r.segundos_sem_pausa >= self.sem_pausa_seg
                and self._uma_vez('sem_pausa_6h')):
            out.append(Alerta('sem_pausa_6h', 'Hora de fazer uma pausa',
                              f'Voce esta ha {fmt_hm(r.segundos_sem_pausa)} sem intervalo. '
                              'Registre o intervalo para descansar.',
                              trazer_janela=True, nivel='alerta'))

        return out
