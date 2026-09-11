from datetime import datetime
from doton.jornada import calcular
from doton.alertas import Alertador

D = '2026-09-11'
def t(hhmm, dia=D):
    return datetime.strptime(f'{dia} {hhmm}:00', '%Y-%m-%d %H:%M:%S')
def b(tipo, hhmm):
    return {'tipo': tipo, 'momento': f'{D} {hhmm}:00'}

ESC = {'entrada': '08:00:00', 'saida': '17:00:00', 'almoco_minutos': 60,
       'carga_diaria_minutos': 480, 'trabalha_hoje': 1}

def nomes(al, bs, hhmm, extra=0, pendente=False, esc=ESC):
    agora = t(hhmm)
    return [a.nome for a in al.avaliar(calcular(bs, esc, extra, agora), agora, pendente)]

INT = [b('entrada', '08:00'), b('saida_intervalo', '12:00')]

def test_intervalo_acabando_dispara_uma_vez_aos_90s():
    al = Alertador()
    assert nomes(al, INT, '12:58') == []                 # faltam 2min
    em_90s = datetime(2026, 9, 11, 12, 58, 30)
    r = al.avaliar(calcular(INT, ESC, 0, em_90s), em_90s, False)
    assert [a.nome for a in r] == ['intervalo_acabando']
    assert r[0].trazer_janela is True
    assert nomes(al, INT, '12:59') == []                 # nao repete

def test_intervalo_estourado_uma_vez():
    al = Alertador()
    assert nomes(al, INT, '13:00') == ['intervalo_estourado']
    assert nomes(al, INT, '13:10') == []

def test_alertas_de_intervalo_reiniciam_em_novo_intervalo():
    al = Alertador()
    nomes(al, INT, '13:00')
    bs2 = INT + [b('retorno_intervalo', '13:05'), b('saida_intervalo', '15:00')]
    # segundo intervalo ja comeca com saldo negativo (tudo usado) -> estourado de novo
    assert nomes(al, bs2, '15:01') == ['intervalo_estourado']

def test_carga_atingida_abre_extra_e_nao_repete():
    al = Alertador()
    bs = [b('entrada', '08:00'), b('saida_intervalo', '12:00'), b('retorno_intervalo', '13:00')]
    assert nomes(al, bs, '16:59') == []
    r = al.avaliar(calcular(bs, ESC, 0, t('17:00')), t('17:00'), False)
    assert 'carga_atingida' in [a.nome for a in r]
    ca = next(a for a in r if a.nome == 'carga_atingida')
    assert ca.abrir_extra is True and ca.trazer_janela is True
    assert 'carga_atingida' not in nomes(al, bs, '17:05')

def test_carga_atingida_nao_dispara_com_extra_aprovada_ou_pendente():
    bs = [b('entrada', '08:00'), b('saida_intervalo', '12:00'), b('retorno_intervalo', '13:00')]
    assert 'carga_atingida' not in nomes(Alertador(), bs, '17:30', extra=60)
    assert 'carga_atingida' not in nomes(Alertador(), bs, '17:30', pendente=True)

def test_carga_atingida_rearma_apos_rejeicao():
    al = Alertador()
    bs = [b('entrada', '08:00'), b('saida_intervalo', '12:00'), b('retorno_intervalo', '13:00')]
    assert 'carga_atingida' in nomes(al, bs, '17:00')
    al.rearmar('carga_atingida')
    assert 'carga_atingida' in nomes(al, bs, '17:01')

def test_fim_previsto_uma_vez_e_considera_extra():
    al = Alertador()
    bs = [b('entrada', '08:00')]
    assert 'fim_previsto' not in nomes(al, bs, '16:59', extra=60)
    assert 'fim_previsto' not in nomes(al, bs, '17:30', extra=60)
    assert 'fim_previsto' in nomes(al, bs, '18:00', extra=60)
    assert 'fim_previsto' not in nomes(al, bs, '18:10', extra=60)

def test_entrada_atrasada():
    al = Alertador(tolerancia_min=10)
    assert nomes(al, [], '08:05') == []
    assert nomes(al, [], '08:10') == ['entrada_atrasada']
    assert nomes(al, [], '08:20') == []
    # com entrada batida nao dispara
    assert nomes(Alertador(), [b('entrada', '08:30')], '09:00') == []
    # folga nao dispara
    assert nomes(Alertador(), [], '09:00', esc=dict(ESC, trabalha_hoje=0)) == []

def test_sem_pausa_6h():
    al = Alertador(sem_pausa_horas=6)
    bs = [b('entrada', '07:00')]
    assert nomes(al, bs, '12:59') == []
    assert nomes(al, bs, '13:00') == ['sem_pausa_6h']
    assert nomes(al, bs, '13:30') == []
    # sem almoco na jornada nao avisa
    assert nomes(Alertador(), bs, '13:00', esc=dict(ESC, almoco_minutos=0)) == []

def test_novo_dia_zera_disparos():
    al = Alertador()
    assert nomes(al, [], '08:30') == ['entrada_atrasada']
    amanha = datetime(2026, 9, 12, 8, 30)
    r = al.avaliar(calcular([], ESC, 0, amanha), amanha, False)
    assert [a.nome for a in r] == ['entrada_atrasada']
