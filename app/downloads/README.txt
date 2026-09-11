============================================================
 DOT-ON AGENT 2.0 - Registro de Ponto Eletronico para Windows
============================================================

FORMA RECOMENDADA: baixe o executavel DOT-ON-Agent.exe pelo link
personalizado da sua empresa (/app/install/SEU-SLUG). Nao precisa
instalar Python nem dependencias; o agente se atualiza sozinho.

INSTALACAO A PARTIR DO CODIGO (opcional):
 1. Descompacte o ZIP em uma pasta (ex.: C:\DOT-ON\).
    O ZIP cria a pasta "dot-on-agent" com:
      - agent.py e a pasta doton\
      - config.json
      - requirements.txt
      - LEIA-ME.txt
 2. No PowerShell, dentro da pasta:
      pip install -r requirements.txt
 3. Confira o endereco do servidor em config.json:
      "api_url": "https://dot-on.com.br/app/api/"
 4. Execute:
      python agent.py

OBS: se voce baixou pelo LINK PERSONALIZADO da sua empresa, o pacote ja
inclui um "dot-on.ini" com a URL correta e o agente usa esse arquivo
automaticamente.

PRIMEIRO ACESSO:
 - Faca login com o e-mail e a senha temporaria recebidos.
 - O sistema vai pedir para criar uma nova senha (min. 8 caracteres).

GERAR EXECUTAVEL (.exe):
 - pip install pyinstaller
 - pyinstaller DOT-ON-Agent.spec
 - O executavel ficara em dist\DOT-ON-Agent.exe
   (envie para app/downloads/DOT-ON-Agent.exe e atualize agent-version.json
   para liberar a atualizacao automatica)

INICIAR COM O WINDOWS:
 - Botao direito no icone da bandeja -> "Iniciar com o Windows".

FUNCIONALIDADES:
 - Registro de entrada, intervalo, retorno e saida
 - Tempo restante do intervalo com aviso 1min30s antes do fim
 - Tempo trabalhado calculado pelas batidas do servidor
 - Jornada prevista do dia (entrada, saida, almoco, carga)
 - Hora extra com aprovacao do gestor; ao atingir a carga o agente abre o pedido
 - Avisos de entrada nao registrada, 6h sem pausa e fim do expediente previsto
 - Aba Espelho com banco de horas do mes
 - Deteccao de ociosidade, fila offline, atualizacao automatica
 - O expediente nunca e encerrado automaticamente; a tela nunca e bloqueada

SUPORTE: pierre@syscomai.com.br
============================================================
