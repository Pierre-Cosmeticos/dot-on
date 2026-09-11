"""
Nucleo de calculo da jornada (sem Qt, sem rede).

Recebe as batidas do dia (servidor + fila offline), a escala do dia e o
relogio do servidor, e devolve um Resumo com estado, tempos e horarios
previstos. Tudo que a interface mostra sai daqui, entao fechar/reabrir o
agente nunca altera os numeros: eles vem das batidas, nao do processo.
"""
from dataclasses import dataclass
from datetime import datetime, timedelta, time
from typing import Optional

FMT = '%Y-%m-%d %H:%M:%S'


def parse_momento(s) -> Optional[datetime]:
    try:
        return datetime.strptime(str(s), FMT)
    except (ValueError, TypeError):
        return None


def _parse_hora(v) -> Optional[time]:
    v = '' if v is None else str(v).strip()
    if not v:
        return None
    for fmt in ('%H:%M:%S', '%H:%M'):
        try:
            return datetime.strptime(v[:8] if fmt == '%H:%M:%S' else v[:5], fmt).time()
        except ValueError:
            continue
    return None


def fmt_hms(seg) -> str:
    seg = int(abs(seg))
    return f"{seg // 3600:02d}:{(seg % 3600) // 60:02d}:{seg % 60:02d}"


def fmt_hm(seg) -> str:
    m = int(abs(seg)) // 60
    return f"{m // 60}h{m % 60:02d}"


@dataclass
class Resumo:
    estado: str                                   # fora | trabalhando | intervalo
    segundos_trabalhados: float
    segundos_intervalo_total: float               # intervalos ja concluidos + o em andamento
    intervalo_inicio: Optional[datetime]          # None se nao esta em intervalo
    segundos_intervalo_restante: float            # negativo = estourou
    almoco_segundos: int
    carga_segundos: int
    segundos_faltando: float                      # negativo = alem da carga
    extra_aprovada_segundos: int
    limite_segundos: int                          # carga + extra aprovada
    entrada_prevista: Optional[datetime]
    saida_prevista: Optional[datetime]
    fim_previsto: Optional[datetime]              # saida prevista + extra aprovada
    segundos_sem_pausa: float                     # trabalho continuo desde a ultima entrada/retorno
    primeira_entrada: Optional[datetime]
    ultima_batida: Optional[datetime]
    trabalha_hoje: bool


def _carga_segundos(escala: dict, trabalha: bool) -> int:
    if not trabalha:
        return 0
    c = escala.get('carga_diaria_minutos')
    if c not in (None, '', 0, '0'):
        try:
            return max(0, int(c)) * 60
        except (TypeError, ValueError):
            pass
    ent, sai = _parse_hora(escala.get('entrada')), _parse_hora(escala.get('saida'))
    if ent and sai:
        base = datetime(2000, 1, 1)
        total = (datetime.combine(base, sai) - datetime.combine(base, ent)).total_seconds()
        if total < 0:
            total += 86400
        almoco = _int(escala.get('almoco_minutos')) * 60
        return int(max(0, total - almoco))
    return 0


def _int(v, default=0) -> int:
    try:
        return int(v)
    except (TypeError, ValueError):
        return default


def calcular(batidas, escala, extra_aprovada_min, agora: datetime) -> Resumo:
    """Reconstroi a jornada de HOJE a partir das batidas.

    Pareamento (mesma logica do servidor):
      entrada -> saida_intervalo | saida   fecha um segmento de trabalho
      saida_intervalo -> retorno_intervalo fecha um segmento de intervalo
    Batidas de outro dia ou com momento invalido sao ignoradas.
    """
    escala = escala if isinstance(escala, dict) else {}
    hoje = agora.date()

    itens = []
    for b in batidas or []:
        ts = parse_momento((b or {}).get('momento'))
        if ts and ts.date() == hoje and b.get('tipo'):
            itens.append((ts, b['tipo']))
    itens.sort(key=lambda x: x[0])

    trab = 0.0
    inter = 0.0
    seg_inicio = None       # segmento de trabalho em aberto
    int_inicio = None       # intervalo em aberto
    primeira = None
    ultima = None
    for ts, tipo in itens:
        ultima = ts
        if tipo == 'entrada':
            if primeira is None:
                primeira = ts
            if int_inicio:          # entrada direta apos intervalo sem retorno
                inter += (ts - int_inicio).total_seconds(); int_inicio = None
            if seg_inicio is None:
                seg_inicio = ts
        elif tipo == 'saida_intervalo':
            if seg_inicio:
                trab += (ts - seg_inicio).total_seconds(); seg_inicio = None
            if int_inicio is None:
                int_inicio = ts
        elif tipo == 'retorno_intervalo':
            if int_inicio:
                inter += (ts - int_inicio).total_seconds(); int_inicio = None
            if seg_inicio is None:
                seg_inicio = ts
        elif tipo == 'saida':
            if int_inicio:
                inter += (ts - int_inicio).total_seconds(); int_inicio = None
            if seg_inicio:
                trab += (ts - seg_inicio).total_seconds(); seg_inicio = None

    if int_inicio is not None:
        estado = 'intervalo'
        inter += max(0.0, (agora - int_inicio).total_seconds())
        sem_pausa = 0.0
    elif seg_inicio is not None:
        estado = 'trabalhando'
        vivo = max(0.0, (agora - seg_inicio).total_seconds())
        trab += vivo
        sem_pausa = vivo
    else:
        estado = 'fora'
        sem_pausa = 0.0

    trabalha = bool(escala) and _int(escala.get('trabalha_hoje', 1), 1) == 1
    almoco_seg = _int(escala.get('almoco_minutos')) * 60 if trabalha else 0
    carga = _carga_segundos(escala, trabalha)

    ent_t = _parse_hora(escala.get('entrada')) if trabalha else None
    sai_t = _parse_hora(escala.get('saida')) if trabalha else None
    entrada_prev = datetime.combine(hoje, ent_t) if ent_t else None
    saida_prev = datetime.combine(hoje, sai_t) if sai_t else None
    if entrada_prev and saida_prev and saida_prev < entrada_prev:
        saida_prev += timedelta(days=1)     # turno que vira a noite
    extra_seg = max(0, _int(extra_aprovada_min)) * 60
    fim_prev = (saida_prev + timedelta(seconds=extra_seg)) if saida_prev else None

    return Resumo(
        estado=estado,
        segundos_trabalhados=trab,
        segundos_intervalo_total=inter,
        intervalo_inicio=int_inicio,
        segundos_intervalo_restante=almoco_seg - inter,
        almoco_segundos=almoco_seg,
        carga_segundos=carga,
        segundos_faltando=carga - trab,
        extra_aprovada_segundos=extra_seg,
        limite_segundos=carga + extra_seg,
        entrada_prevista=entrada_prev,
        saida_prevista=saida_prev,
        fim_previsto=fim_prev,
        segundos_sem_pausa=sem_pausa,
        primeira_entrada=primeira,
        ultima_batida=ultima,
        trabalha_hoje=trabalha,
    )
