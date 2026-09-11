from datetime import datetime, timedelta
from doton.jornada import calcular, parse_momento, fmt_hms, fmt_hm

D = '2026-09-11'
def t(hhmm):
    return datetime.strptime(f'{D} {hhmm}:00', '%Y-%m-%d %H:%M:%S')
def b(tipo, hhmm):
    return {'tipo': tipo, 'momento': f'{D} {hhmm}:00'}

ESC = {'entrada': '08:00:00', 'saida': '17:00:00', 'almoco_minutos': 60,
       'carga_diaria_minutos': 480, 'trabalha_hoje': 1}

def test_sem_batidas_fora():
    r = calcular([], ESC, 0, t('07:00'))
    assert r.estado == 'fora'
    assert r.segundos_trabalhados == 0
    assert r.primeira_entrada is None
    assert r.entrada_prevista == t('08:00')
    assert r.saida_prevista == t('17:00')
    assert r.fim_previsto == t('17:00')
    assert r.carga_segundos == 480 * 60
    assert r.segundos_faltando == 480 * 60
    assert r.trabalha_hoje is True

def test_trabalhando_apos_entrada():
    r = calcular([b('entrada', '08:00')], ESC, 0, t('10:30'))
    assert r.estado == 'trabalhando'
    assert r.segundos_trabalhados == 2.5 * 3600
    assert r.segundos_sem_pausa == 2.5 * 3600
    assert r.primeira_entrada == t('08:00')
    assert r.segundos_faltando == 480 * 60 - 2.5 * 3600

def test_em_intervalo_restante():
    r = calcular([b('entrada', '08:00'), b('saida_intervalo', '12:00')], ESC, 0, t('12:20'))
    assert r.estado == 'intervalo'
    assert r.intervalo_inicio == t('12:00')
    assert r.segundos_trabalhados == 4 * 3600      # parado no intervalo
    assert r.segundos_intervalo_restante == 40 * 60
    assert r.almoco_segundos == 3600

def test_intervalo_estourado_negativo():
    r = calcular([b('entrada', '08:00'), b('saida_intervalo', '12:00')], ESC, 0, t('13:05'))
    assert r.segundos_intervalo_restante == -5 * 60

def test_retorno_soma_intervalo_e_reinicia_sem_pausa():
    bs = [b('entrada', '08:00'), b('saida_intervalo', '12:00'), b('retorno_intervalo', '13:00')]
    r = calcular(bs, ESC, 0, t('15:00'))
    assert r.estado == 'trabalhando'
    assert r.segundos_trabalhados == 6 * 3600
    assert r.segundos_intervalo_total == 3600
    assert r.segundos_sem_pausa == 2 * 3600
    assert r.intervalo_inicio is None
    # o que resta de intervalo apos usar tudo
    assert r.segundos_intervalo_restante == 0

def test_saida_fecha_tudo():
    bs = [b('entrada', '08:00'), b('saida_intervalo', '12:00'),
          b('retorno_intervalo', '13:00'), b('saida', '17:00')]
    r = calcular(bs, ESC, 0, t('19:00'))
    assert r.estado == 'fora'
    assert r.segundos_trabalhados == 8 * 3600
    assert r.segundos_faltando == 0

def test_batidas_fora_de_ordem_sao_ordenadas():
    bs = [b('saida_intervalo', '12:00'), b('entrada', '08:00')]
    r = calcular(bs, ESC, 0, t('12:10'))
    assert r.estado == 'intervalo'
    assert r.segundos_trabalhados == 4 * 3600

def test_extra_aprovada_move_fim_previsto():
    r = calcular([b('entrada', '08:00')], ESC, 90, t('10:00'))
    assert r.fim_previsto == t('18:30')
    assert r.saida_prevista == t('17:00')

def test_folga():
    esc = dict(ESC, trabalha_hoje=0)
    r = calcular([], esc, 0, t('10:00'))
    assert r.trabalha_hoje is False
    assert r.carga_segundos == 0
    assert r.entrada_prevista is None
    assert r.fim_previsto is None

def test_sem_escala():
    r = calcular([b('entrada', '08:00')], None, 0, t('09:00'))
    assert r.estado == 'trabalhando'
    assert r.trabalha_hoje is False
    assert r.carga_segundos == 0
    assert r.almoco_segundos == 0

def test_carga_derivada_quando_nao_informada():
    esc = {'entrada': '09:00:00', 'saida': '18:00:00', 'almoco_minutos': 60, 'trabalha_hoje': 1}
    r = calcular([], esc, 0, t('08:00'))
    assert r.carga_segundos == 8 * 3600

def test_ignora_batidas_de_outro_dia():
    bs = [{'tipo': 'entrada', 'momento': '2026-09-10 08:00:00'}]
    r = calcular(bs, ESC, 0, t('09:00'))
    assert r.estado == 'fora'

def test_ignora_momento_invalido():
    bs = [{'tipo': 'entrada', 'momento': 'xx'}, b('entrada', '08:00')]
    r = calcular(bs, ESC, 0, t('09:00'))
    assert r.segundos_trabalhados == 3600

def test_helpers():
    assert parse_momento('2026-09-11 08:05:09') == datetime(2026, 9, 11, 8, 5, 9)
    assert parse_momento('nope') is None
    assert fmt_hms(3661) == '01:01:01'
    assert fmt_hms(-5) == '00:00:05'
    assert fmt_hm(5400) == '1h30'
    assert fmt_hm(0) == '0h00'
