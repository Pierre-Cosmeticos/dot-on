"""Paleta, folha de estilo e helpers de widget (identidade visual unica)."""
from PyQt5.QtCore import Qt
from PyQt5.QtGui import QColor
from PyQt5.QtWidgets import QFrame, QGraphicsDropShadowEffect, QLabel, QPushButton

COR = {
    'primaria':    '#2563eb',
    'sucesso':     '#16a34a',
    'alerta':      '#f59e0b',
    'perigo':      '#dc2626',
    'roxo':        '#7c3aed',
    'cinza':       '#64748b',
    'texto':       '#0f172a',
    'texto_fraco': '#64748b',
    'texto_suave': '#94a3b8',
    'fundo':       '#f1f5f9',
    'cartao':      '#ffffff',
    'borda':       '#e2e8f0',
}


def shade(hex_color: str, factor: float) -> str:
    """Clareia (factor>1) ou escurece (factor<1) uma cor #rrggbb."""
    c = hex_color.lstrip('#')
    r, g, b = int(c[0:2], 16), int(c[2:4], 16), int(c[4:6], 16)
    r = max(0, min(255, int(r * factor)))
    g = max(0, min(255, int(g * factor)))
    b = max(0, min(255, int(b * factor)))
    return f'#{r:02x}{g:02x}{b:02x}'


def btn_css(color: str, alto: bool = True) -> str:
    hover = shade(color, 1.12)
    press = shade(color, 0.82)
    pad = '12px 14px' if alto else '9px 12px'
    fs = '13px' if alto else '12px'
    return f"""
    QPushButton {{
        background:{color}; color:white; padding:{pad};
        font-weight:600; border:none; border-radius:10px; font-size:{fs};
    }}
    QPushButton:hover {{ background:{hover}; }}
    QPushButton:pressed {{ background:{press}; }}
    QPushButton:disabled {{ background:#e2e8f0; color:#94a3b8; }}
    """


def botao(texto: str, cor: str, alto: bool = True) -> QPushButton:
    b = QPushButton(texto)
    b.setStyleSheet(btn_css(cor, alto))
    b.setCursor(Qt.PointingHandCursor)
    return b


def badge_css(color: str) -> str:
    return (f"background:{shade(color, 1.7)};color:{color};font-size:13px;font-weight:700;"
            f"padding:6px 16px;border-radius:20px;letter-spacing:0.5px")


APP_QSS = f"""
    QWidget {{
        font-family: 'Segoe UI', 'Segoe UI Variable', Arial, sans-serif;
        color: {COR['texto']};
    }}
    QMainWindow, QDialog {{ background: {COR['fundo']}; }}
    QLineEdit, QTextEdit, QSpinBox {{
        border: 1px solid {COR['borda']}; border-radius: 8px; padding: 8px 10px;
        background: white; font-size: 13px; selection-background-color: {COR['primaria']};
    }}
    QLineEdit:focus, QTextEdit:focus, QSpinBox:focus {{ border: 1px solid {COR['primaria']}; }}
    QLabel {{ color: {COR['texto']}; }}
    QMenu {{ background: white; border: 1px solid {COR['borda']}; border-radius: 8px; padding: 4px; }}
    QMenu::item {{ padding: 8px 24px; border-radius: 6px; }}
    QMenu::item:selected {{ background: {COR['fundo']}; color: {COR['primaria']}; }}
    QProgressBar {{
        border: none; border-radius: 7px; background: {COR['borda']};
        height: 14px; text-align: center; font-size: 10px; color: {COR['texto_fraco']};
    }}
    QProgressBar::chunk {{ border-radius: 7px; background: {COR['sucesso']}; }}
    QTabWidget::pane {{ border: none; background: transparent; }}
    QTabBar::tab {{
        background: transparent; color: {COR['texto_fraco']}; padding: 8px 14px;
        min-width: 90px; font-weight: 600; font-size: 13px; border-bottom: 2px solid transparent;
    }}
    QTabBar::tab:selected {{ color: {COR['primaria']}; border-bottom: 2px solid {COR['primaria']}; }}
    QTabBar::tab:hover {{ color: {COR['texto']}; }}
    QTableWidget {{
        background: white; border: 1px solid {COR['borda']}; border-radius: 10px;
        gridline-color: {COR['borda']}; font-size: 12px;
    }}
    QHeaderView::section {{
        background: {COR['fundo']}; color: {COR['texto_fraco']}; padding: 6px;
        border: none; border-bottom: 1px solid {COR['borda']}; font-weight: 600; font-size: 11px;
    }}
    QScrollBar:vertical {{ width: 8px; background: transparent; }}
    QScrollBar::handle:vertical {{ background: {COR['borda']}; border-radius: 4px; }}
    QToolTip {{ background: white; color: {COR['texto']}; border: 1px solid {COR['borda']}; padding: 4px; }}
"""


def cartao() -> QFrame:
    f = QFrame()
    # Seletor por objectName: "QFrame" puro atingiria os QLabel filhos (QLabel herda de QFrame).
    f.setObjectName('cartao')
    f.setStyleSheet(f"QFrame#cartao {{ background:{COR['cartao']}; border:1px solid {COR['borda']}; border-radius:14px; }}")
    sombra = QGraphicsDropShadowEffect()
    sombra.setBlurRadius(18)
    sombra.setXOffset(0)
    sombra.setYOffset(3)
    sombra.setColor(QColor(15, 23, 42, 28))
    f.setGraphicsEffect(sombra)
    return f


def rotulo(texto: str, tamanho: int = 11, cor: str = None, negrito: bool = False,
           alinhar=None) -> QLabel:
    lb = QLabel(texto)
    css = f"font-size:{tamanho}px;color:{cor or COR['texto_fraco']};"
    if negrito:
        css += "font-weight:700;"
    lb.setStyleSheet(css)
    if alinhar is not None:
        lb.setAlignment(alinhar)
    return lb
