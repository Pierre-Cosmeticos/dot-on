<?php
$titulo = 'Agente Windows · Downloads'; $pagina = 'downloads';
require __DIR__ . '/_layout.php';

$base = '../downloads/';
?>
<div class="panel">
    <h2><?= icon('downloads', 18) ?>DOT-ON Agente Windows</h2>
    <p>Aplicativo desktop instalado no Windows do funcionário para registrar o ponto, acompanhar o intervalo e a carga do dia, solicitar hora extra e detectar ociosidade. O encerramento do expediente é sempre feito pelo funcionário: o agente apenas lembra, nunca bloqueia a tela.</p>

    <div class="download-box">
        <h3>Agente Python (recomendado)</h3>
        <p>Pacote com o código do agente. Requer <strong>Python 3.10+</strong> no Windows. Já vem com a URL de produção configurada.</p>
        <p><a href="<?= $base ?>dot-on-agent.zip" class="btn btn-primary" download><?= icon('download', 16) ?>Baixar dot-on-agent.zip</a></p>
        <p style="font-size:12px;color:#6b7280;margin-top:8px">
            Dica: para entregar aos funcionários já configurado por empresa (sem digitar a URL), use a
            página <strong>Instaladores personalizados</strong> no painel SysAdmin — ela gera um ZIP com o
            <code>dot-on.ini</code> da empresa embutido.
        </p>
    </div>

    <div class="download-box">
        <h3>Executável Standalone (.exe)</h3>
        <p>Arquivo único <code>.exe</code>, sem precisar instalar Python. Gerado com
        <code>pyinstaller --onefile --windowed --name DOT-ON-Agent agent.py</code> e enviado para
        <code>app/downloads/DOT-ON-Agent.exe</code>.</p>
        <?php if (file_exists(__DIR__ . '/../downloads/DOT-ON-Agent.exe')): ?>
            <p><a href="<?= $base ?>DOT-ON-Agent.exe" class="btn btn-secondary" download><?= icon('download', 16) ?>Baixar DOT-ON-Agent.exe</a></p>
            <p style="font-size:12px;color:#6b7280;margin-top:8px">
                Na primeira execução o Windows Defender pode exibir "App não reconhecido": clique em
                "Mais informações" → "Executar assim mesmo".
            </p>
        <?php else: ?>
            <p style="font-size:13px;color:#b45309;background:#fffbeb;padding:8px 12px;border-radius:6px;">
                Ainda não disponível — o <code>.exe</code> precisa ser gerado e enviado ao servidor.
                Enquanto isso, use o agente Python acima.
            </p>
        <?php endif; ?>
    </div>

    <div class="download-box">
        <h3>Configuração inicial do agente</h3>
        <ol>
            <li>Baixar o ZIP e descompactar em uma pasta (ex.: <code>C:\DOT-ON\</code>)</li>
            <li>Instalar dependências: <code>pip install -r requirements.txt</code></li>
            <li>Executar: <code>python agent.py</code></li>
            <li>Na tela de login, confirmar o endereço do servidor:
                <br><code>https://dot-on.com.br/app/api/</code></li>
            <li>Fazer login com e-mail e senha cadastrados no painel (será solicitada a troca de senha no primeiro acesso)</li>
            <li>O agente fica na bandeja do sistema (System Tray); pode fechar a janela que continua rodando</li>
        </ol>
    </div>

    <div class="download-box">
        <h3>Distribuição corporativa (em rede)</h3>
        <p>Para implantar em vários PCs simultaneamente:</p>
        <ul style="margin-left:20px">
            <li>Use <strong>GPO do Active Directory</strong> para distribuir o .exe e configurar Startup</li>
            <li>Ou crie um script PowerShell que baixa+instala via SCCM/Intune</li>
            <li>O servidor já registra IP/hostname de cada batida (auditoria)</li>
        </ul>
    </div>
</div>
</main></body></html>
