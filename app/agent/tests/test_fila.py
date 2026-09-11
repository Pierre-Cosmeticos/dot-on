from doton.fila import Fila


def test_fila_vazia(tmp_path):
    f = Fila(tmp_path / 'fila.json')
    assert f.ler() == []
    assert f.tamanho() == 0


def test_adicionar_persiste_e_le(tmp_path):
    f = Fila(tmp_path / 'fila.json')
    n = f.adicionar({'tipo': 'entrada', 'momento': '2026-09-11 08:00:00', 'client_uid': 'a'})
    assert n == 1
    assert Fila(tmp_path / 'fila.json').ler()[0]['tipo'] == 'entrada'


def test_gravar_vazio_apaga_arquivo(tmp_path):
    p = tmp_path / 'fila.json'
    f = Fila(p)
    f.adicionar({'tipo': 'entrada', 'momento': '2026-09-11 08:00:00'})
    assert p.exists()
    f.gravar([])
    assert not p.exists()
    assert f.ler() == []


def test_arquivo_corrompido_vira_vazio(tmp_path):
    p = tmp_path / 'fila.json'
    p.write_text('{nao e lista', encoding='utf-8')
    assert Fila(p).ler() == []


def test_de_hoje_filtra_por_data(tmp_path):
    f = Fila(tmp_path / 'fila.json')
    f.adicionar({'tipo': 'entrada', 'momento': '2026-09-10 08:00:00'})
    f.adicionar({'tipo': 'entrada', 'momento': '2026-09-11 08:00:00'})
    f.adicionar({'tipo': '', 'momento': '2026-09-11 09:00:00'})
    hoje = f.de_hoje('2026-09-11')
    assert [b['momento'] for b in hoje] == ['2026-09-11 08:00:00']
