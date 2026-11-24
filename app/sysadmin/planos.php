<?php
$pagina = 'planos'; $titulo = 'Planos & Preços';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/billing.php';

$cfg = billing_config();
$valor = (float)$cfg['valor_plano'];
$brl = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
?>

<h1>💳 Plano & Preço</h1>

<div class="alert info">
    💡 O DOT-ON adota <strong>plano único de valor fixo</strong> (assinatura mensal). O valor é definido em
    <a href="faturamento.php" style="color:#bfdbfe;text-decoration:underline;">Faturamento</a> e cobrado via <strong>Asaas</strong> (Pix, boleto ou cartão).
    Empresas começam com <strong><?= (int)$cfg['dias_trial'] ?> dias de trial</strong> grátis.
</div>

<div class="cards">
    <div class="card" style="border-left:4px solid #64748b;">
        <div class="label">🆓 TRIAL</div>
        <div class="value">R$ 0</div>
        <div class="sub"><?= (int)$cfg['dias_trial'] ?> dias · sem cartão</div>
        <hr style="border:none; border-top:1px solid #334155; margin:14px 0;">
        <div style="color:#94a3b8; font-size:13px; line-height:1.7;">
            ✓ Acesso completo por <?= (int)$cfg['dias_trial'] ?> dias<br>
            ✓ Todos os recursos liberados<br>
            ✓ Sem compromisso<br>
            ✗ Expira automaticamente
        </div>
    </div>

    <div class="card" style="border-left:4px solid #10b981;">
        <div class="label">⭐ <?= htmlspecialchars(strtoupper($cfg['plano_nome'])) ?></div>
        <div class="value"><?= $brl($valor) ?><span style="font-size:14px; color:#94a3b8;">/mês</span></div>
        <div class="sub">Valor único · funcionários ilimitados</div>
        <hr style="border:none; border-top:1px solid #334155; margin:14px 0;">
        <div style="color:#94a3b8; font-size:13px; line-height:1.7;">
            ✓ Funcionários ilimitados<br>
            ✓ Agente Windows + batida no navegador (geo)<br>
            ✓ CRP por e-mail em toda batida<br>
            ✓ Banco de horas, extras, faltas e abonos<br>
            ✓ AFD / AEJ / espelho de ponto<br>
            ✓ Certificado ICP-Brasil (A1)<br>
            ✓ Painel do gestor + auditoria fiscal
        </div>
    </div>
</div>

<div class="panel">
    <h2 style="margin-top:0;">📊 Posicionamento de mercado</h2>
    <p style="color:#cbd5e1;line-height:1.8;">
        Concorrentes de ponto eletrônico SaaS no Brasil (2025/2026) partem de <strong>R$ 49,90 a R$ 69,90/mês</strong>
        na entrada (Pontomais ~R$ 59,90; Tangerino/Sólides ~R$ 69,90; PontoSimples ~R$ 49,90), ou cobram por
        colaborador (<strong>R$ 2,84 a R$ 4,50</strong> por pessoa/mês — Genyo ~R$ 3,75; Tangerino ~R$ 4,50).
        O plano único do DOT-ON fica <strong>levemente abaixo da entrada do mercado</strong> e, por não cobrar
        por cabeça, torna-se muito mais barato conforme a empresa cresce.
    </p>
</div>

<div class="panel">
    <h2 style="margin-top:0;">✅ Integração de cobrança (Asaas) — implementada</h2>
    <ul style="color:#cbd5e1; margin-left:24px; line-height:2;">
        <li>Cliente da API Asaas (<code>app/includes/asaas.php</code>) — clientes, assinaturas, cobranças</li>
        <li>Tabelas <code>dot_billing_config</code>, <code>dot_assinaturas</code> e <code>dot_pagamentos</code></li>
        <li>Webhook (<code>/app/api/asaas_webhook.php</code>) confirma pagamento → ativa/bloqueia empresa</li>
        <li>Página do gestor <code>/app/admin/assinatura.php</code> (assinar, pagar, histórico)</li>
        <li>Painel financeiro com MRR, recebido no mês e inadimplência em <a href="faturamento.php" style="color:#93c5fd;">Faturamento</a></li>
    </ul>
    <p style="color:#94a3b8;font-size:13px;margin-top:8px;">Próximos passos sugeridos: NFS-e automatizada e régua de e-mails de cobrança.</p>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
