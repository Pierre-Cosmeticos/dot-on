"""Dialogos de login e de troca de senha obrigatoria."""
from PyQt5.QtCore import Qt
from PyQt5.QtWidgets import (QApplication, QDialog, QFormLayout, QLabel, QLineEdit,
                             QMessageBox, QVBoxLayout)

from .. import config as cfgmod
from ..api import APIClient
from .tema import COR, botao


class TrocarSenhaDialog(QDialog):
    def __init__(self, api: APIClient, senha_atual: str, parent=None):
        super().__init__(parent)
        self.api = api
        self.senha_atual = senha_atual
        self.setWindowTitle("DOT-ON - Trocar senha")
        self.setFixedSize(420, 320)
        lay = QVBoxLayout(self)
        lay.setContentsMargins(28, 24, 28, 24)
        lay.setSpacing(12)
        titulo = QLabel("Defina sua nova senha")
        titulo.setStyleSheet(f"font-size:18px;font-weight:700;color:{COR['texto']}")
        lay.addWidget(titulo)
        msg = QLabel("Por seguranca, e obrigatorio trocar a senha no primeiro acesso.")
        msg.setStyleSheet(f"color:{COR['texto_fraco']};font-size:12px")
        msg.setWordWrap(True)
        lay.addWidget(msg)
        form = QFormLayout()
        form.setSpacing(10)
        self.nova = QLineEdit()
        self.nova.setEchoMode(QLineEdit.Password)
        self.conf = QLineEdit()
        self.conf.setEchoMode(QLineEdit.Password)
        form.addRow("Nova senha", self.nova)
        form.addRow("Confirmar", self.conf)
        lay.addLayout(form)
        dica = QLabel("Minimo de 8 caracteres.")
        dica.setStyleSheet(f"color:{COR['texto_fraco']};font-size:11px")
        lay.addWidget(dica)
        btn = botao("Salvar nova senha", COR['primaria'])
        btn.clicked.connect(self.salvar)
        lay.addWidget(btn)
        self.status = QLabel("")
        self.status.setStyleSheet(f"color:{COR['perigo']};font-size:12px")
        self.status.setWordWrap(True)
        lay.addWidget(self.status)
        self.conf.returnPressed.connect(self.salvar)

    def salvar(self):
        nova, conf = self.nova.text(), self.conf.text()
        if len(nova) < 8:
            self.status.setText("A senha precisa ter no minimo 8 caracteres.")
            return
        if nova != conf:
            self.status.setText("As senhas nao coincidem.")
            return
        r = self.api.trocar_senha(self.senha_atual, nova)
        if r.get('ok'):
            QMessageBox.information(self, "DOT-ON", "Senha alterada com sucesso.")
            self.accept()
        else:
            self.status.setText(r.get('erro', 'Falha ao trocar senha.'))


class LoginDialog(QDialog):
    def __init__(self, api: APIClient, cfg: dict, parent=None):
        super().__init__(parent)
        self.api = api
        self.cfg = cfg
        self.setWindowTitle("DOT-ON - Login")
        self.setFixedSize(400, 420)
        lay = QVBoxLayout(self)
        lay.setContentsMargins(28, 24, 28, 24)
        lay.setSpacing(12)

        logo = QLabel("DOT-ON")
        logo.setStyleSheet(f"font-size:30px;font-weight:800;color:{COR['primaria']};letter-spacing:1px")
        logo.setAlignment(Qt.AlignCenter)
        lay.addWidget(logo)
        sub = QLabel(cfg.get('empresa_nome') or "Registro de ponto eletronico")
        sub.setStyleSheet(f"color:{COR['texto_fraco']};font-size:12px")
        sub.setAlignment(Qt.AlignCenter)
        lay.addWidget(sub)
        lay.addSpacing(8)

        form = QFormLayout()
        form.setSpacing(10)
        self.email = QLineEdit()
        self.email.setPlaceholderText("seu.email@empresa.com")
        self.senha = QLineEdit()
        self.senha.setEchoMode(QLineEdit.Password)
        self.senha.setPlaceholderText("Sua senha")
        self.servidor = QLineEdit(cfg.get('api_url', ''))
        form.addRow("E-mail", self.email)
        form.addRow("Senha", self.senha)
        form.addRow("Servidor", self.servidor)
        lay.addLayout(form)

        self.btn = botao("Entrar", COR['primaria'])
        self.btn.clicked.connect(self.fazer_login)
        lay.addWidget(self.btn)

        self.status = QLabel("")
        self.status.setStyleSheet(f"color:{COR['perigo']};font-size:12px")
        self.status.setWordWrap(True)
        self.status.setAlignment(Qt.AlignCenter)
        lay.addWidget(self.status)
        self.senha.returnPressed.connect(self.fazer_login)

    def fazer_login(self):
        api_url = self.servidor.text().strip()
        if not api_url:
            self.status.setText("Informe o endereco do servidor.")
            return
        self.cfg['api_url'] = api_url
        cfgmod.salvar(self.cfg)
        self.api.base = api_url.rstrip('/')
        self.btn.setEnabled(False)
        self.btn.setText("Entrando...")
        self.status.setText("")
        QApplication.processEvents()
        ok, info = self.api.login(self.email.text().strip(), self.senha.text())
        self.btn.setEnabled(True)
        self.btn.setText("Entrar")
        if not ok:
            self.status.setText(str(info))
            return
        if self.api.precisa_trocar_senha:
            td = TrocarSenhaDialog(self.api, self.senha.text(), self)
            if td.exec_() != QDialog.Accepted:
                self.status.setText("E necessario trocar a senha para continuar.")
                return
        self.accept()
