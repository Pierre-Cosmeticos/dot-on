<?php
$titulo = 'Assinatura'; $pagina = 'assinatura';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/billing.php';

// Só admin/rh mexem em cobrança
if (!in_array($user['perfil'] ?? '', ['admin','rh'], true)) {
    echo '<div class="panel"><p>Apenas administradores podem gerenciar a assinatura.</p></div></main></body></html>';
    exit;
}

$empresa_id = (int)$user['empresa_id'];
$cfg = billing_config();
$msg = ''; $msg_tipo = '';
$fmt = fn($d) => $d ? date('d/m/Y', strtotime($d)) : '—';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'assinar') {
        if (!billing_configurado()) {
            $msg = 'O pagamento online ainda não está disponível. Fale com o suporte para ativar sua assinatura.';
            $msg_tipo = 'error';
        } else {
            try {
                $forma = in_array($_POST['forma'] ?? '', ['UNDEFINED','PIX','BOLETO','CREDIT_CARD'], true) ? $_POST['forma'] : 'UNDEFINED';
                $a = billing_assinar_empresa($empresa_id, $forma);
                auditar($user['id'], 'assinatura_criada', 'faturamento', $empresa_id, ['forma' => $forma]);
                $msg = 'Assinatura criada! Primeira cobrança em ' . $fmt($a['proximo_vencimento'] ?? null)
                     . '. Use o link de pagamento abaixo para pagar.';
                $msg_tipo = 'success';
            } catch (Throwable $e) {
                error_log('DOT-ON assinar empresa ' . $empresa_id . ': ' . $e->getMessage());
                $msg = 'Não foi possível criar a assinatura: ' . $e->getMessage();
                $msg_tipo = 'error';
            }
        }
    }

    if ($acao === 'atualizar') {
        try {
            billing_sincronizar_assinatura($empresa_id);
            $msg = 'Status atualizado com o Asaas.'; $msg_tipo = 'success';
        } catch (Throwable $e) {
            error_log('DOT-ON sync empresa ' . $empresa_id . ': ' . $e->getMessage());
            $msg = 'Não foi possível consultar o Asaas agora. Tente novamente em alguns minutos.';
            $msg_tipo = 'error';
        }
    }

    if ($acao === 'cancelar') {
        try {
            billing_cancelar_assinatura($empresa_id);
            auditar($user['id'], 'assinatura_cancelada', 'faturamento', $empresa_id);
            $a = billing_assinatura($empresa_id);
            $msg = (!empty($a['pago_ate']) && $a['pago_ate'] >= billing_hoje())
                ? 'Assinatura cancelada. Você mantém o acesso até ' . $fmt($a['pago_ate']) . ' (fim do período pago).'
                : 'Assinatura cancelada. Nenhuma nova cobrança será gerada.';
            $msg_tipo = 'success';
        } catch (Throwable $e) {
            error_log('DOT-ON cancelar empresa ' . $empresa_id . ': ' . $e->getMessage());
            $msg = 'Não foi possível cancelar agora (' . $e->getMessage() . '). Nada foi alterado — tente novamente.';
            $msg_tipo = 'error';
        }
    }
}

$emp        = billing_empresa($empresa_id);          // sem cache: reflete o POST acima
$assinatura = billing_assinatura($empresa_id);
$pagamentos = billing_pagamentos($empresa_id);
$status     = $emp['assinatura_status'] ?? 'trial';
$situacao   = billing_situacao($emp);
$valor      = billing_valor_plano();
$hoje       = billing_hoje();
$brl = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');

// Assinatura viva = existe no Asaas neste ambiente e não foi cancelada.
$viva = $assinatura && $assinatura['asaas_subscription_id'] && $assinatura['status'] !== 'canceled'
        && billing_assinatura_do_ambiente($assinatura);

// Cobrança em aberto mais antiga DA ASSINATURA ATUAL (é a que precisa ser paga).
// Cobranças de assinaturas antigas nunca são oferecidas para pagamento.
$sub_atual = $viva ? $assinatura['asaas_subscription_id'] : null;
$pagavel = fn($p) => $sub_atual && in_array($p['status'], BILLING_EM_ABERTO, true)
                     && ($p['asaas_subscription_id'] ?? null) === $sub_atual;
$em_aberto = null;
foreach (array_reverse($pagamentos) as $p) {
    if ($pagavel($p)) { $em_aberto = $p; break; }
}

$dias_trial = null;
if ($status === 'trial' && !empty($emp['trial_expira'])) {
    $dias_trial = max(0, billing_dias_entre($hoje, substr($emp['trial_expira'], 0, 10)));
}

$labels = [
    'trial'    => ['Período de teste', '#f59e0b'],
    'active'   => ['Assinatura ativa', '#10b981'],
    'overdue'  => ['Pagamento em atraso', '#ef4444'],
    'pending'  => ['Aguardando pagamento', '#f59e0b'],
    'canceled' => ['Cancelada', '#64748b'],
];
[$statusLabel, $statusCor] = $labels[$status] ?? ['—', '#64748b'];
$pago_ate = ($assinatura && billing_assinatura_do_ambiente($assinatura)) ? ($assinatura['pago_ate'] ?? null) : null;
?>
<?php if ($msg): ?><div class="alert <?= $msg_tipo==='success'?'success':'error' ?>" style="padding:12px 16px;border-radius:8px;margin-bottom:16px;background:<?= $msg_tipo==='success'?'#dcfce7':'#fee2e2' ?>;color:<?= $msg_tipo==='success'?'#166534':'#991b1b' ?>;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="panel">
    <h2><?= icon('config', 18) ?>Sua assinatura</h2>
    <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:center;margin-top:8px;">
        <div>
            <div style="font-size:13px;color:#6b7280;">Status</div>
            <div style="font-size:20px;font-weight:700;color:<?= $statusCor ?>;">● <?= $statusLabel ?></div>
            <?php if ($dias_trial !== null): ?>
                <div style="font-size:13px;color:#6b7280;margin-top:4px;"><?= $dias_trial ?> dia(s) restante(s) de teste grátis</div>
            <?php endif; ?>
            <?php if (in_array($status, ['pending','overdue'], true) && $situacao['dias_ate_bloqueio'] !== null && $situacao['liberado']
                      && $viva && !empty($assinatura['proximo_vencimento']) && $assinatura['proximo_vencimento'] <= $hoje): ?>
                <div style="font-size:13px;color:#6b7280;margin-top:4px;">Acesso garantido por mais <?= (int)$situacao['dias_ate_bloqueio'] ?> dia(s) enquanto o pagamento não compensa</div>
            <?php endif; ?>
        </div>
        <?php if ($viva && $assinatura['proximo_vencimento']): ?>
        <div>
            <div style="font-size:13px;color:#6b7280;"><?= $status === 'overdue' ? 'Venceu em' : 'Próximo vencimento' ?></div>
            <div style="font-size:20px;font-weight:700;"><?= $fmt($assinatura['proximo_vencimento']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($pago_ate && $pago_ate >= $hoje && !($viva && $pago_ate === ($assinatura['proximo_vencimento'] ?? null))): ?>
        <div>
            <div style="font-size:13px;color:#6b7280;">Pago até</div>
            <div style="font-size:20px;font-weight:700;"><?= $fmt($pago_ate) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Em dia: a próxima fatura (gerada pelo Asaas com antecedência) só ganha destaque
// perto do vencimento; antes disso fica no histórico.
$destacar_fatura = $em_aberto && $viva
    && ($status !== 'active' || ($em_aberto['vencimento'] && $em_aberto['vencimento'] <= billing_data_mais_dias($hoje, 7)));
?>
<?php if ($destacar_fatura): ?>
<div class="panel" style="border-left:4px solid <?= $em_aberto['status']==='OVERDUE' ? '#ef4444' : '#f59e0b' ?>;">
    <h2><?= icon('config', 18) ?>Fatura em aberto — <?= $brl($em_aberto['valor']) ?></h2>
    <p>Vencimento: <strong><?= $fmt($em_aberto['vencimento']) ?></strong></p>
    <p style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
        <?php if ($em_aberto['url_fatura']): ?>
            <a href="<?= htmlspecialchars($em_aberto['url_fatura']) ?>" target="_blank" rel="noopener" class="btn btn-primary">💳 Pagar agora (Pix / Boleto / Cartão)</a>
        <?php endif; ?>
        <?php if ($em_aberto['url_boleto']): ?>
            <a href="<?= htmlspecialchars($em_aberto['url_boleto']) ?>" target="_blank" rel="noopener" class="btn btn-outline">📄 Boleto</a>
        <?php endif; ?>
    </p>
    <form method="post" style="margin-top:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="atualizar">
        <button class="btn btn-outline" type="submit">🔄 Já paguei — atualizar status</button>
        <span class="muted" style="font-size:12px;color:#6b7280;margin-left:6px;">Pix e cartão confirmam na hora; boleto leva até 3 dias úteis.</span>
    </form>
</div>
<?php elseif ($viva && !$em_aberto && $status !== 'active'): /* cobrança ainda não sincronizada */ ?>
<div class="panel">
    <p>Sua assinatura foi criada e a cobrança está sendo gerada pelo Asaas.</p>
    <form method="post" style="margin-top:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="atualizar">
        <button class="btn btn-outline" type="submit">🔄 Atualizar</button>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <h2><?= icon('config', 18) ?>Plano <?= htmlspecialchars($cfg['plano_nome']) ?></h2>
    <div style="display:flex;align-items:baseline;gap:6px;margin:8px 0 16px;">
        <span style="font-size:38px;font-weight:800;"><?= $brl($valor) ?></span>
        <span style="color:#6b7280;">/mês · valor único, sem surpresa</span>
    </div>
    <ul style="line-height:2;color:#374151;margin-left:20px;">
        <li>✓ Funcionários ilimitados</li>
        <li>✓ Registro de ponto pelo Agente Windows + navegador (com geolocalização)</li>
        <li>✓ Comprovante de Registro de Ponto (CRP) por e-mail em toda batida</li>
        <li>✓ Banco de horas, horas extras, faltas e abonos</li>
        <li>✓ Espelho de ponto, AFD e AEJ para a fiscalização</li>
        <li>✓ Certificado ICP-Brasil (A1) e assinatura dos arquivos</li>
        <li>✓ Painel do gestor completo + auditoria fiscal</li>
    </ul>

    <?php if ($viva): ?>
        <?php if ($status === 'active'): ?>
            <div class="alert" style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:8px;">Sua assinatura está ativa. Obrigado! 💚</div>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Cancelar a assinatura? Nenhuma nova cobrança será gerada e você mantém o acesso até o fim do período já pago.');" style="margin-top:12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="cancelar">
            <button class="btn btn-outline" type="submit">Cancelar assinatura</button>
        </form>
    <?php else: ?>
        <?php
            $primeiro = billing_primeiro_vencimento($emp, billing_assinatura_do_ambiente($assinatura) ? $assinatura : null);
        ?>
        <form method="post" style="margin-top:8px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="assinar">
            <label>Forma de pagamento:
                <select name="forma">
                    <option value="UNDEFINED">Deixar o cliente escolher (Pix, Boleto ou Cartão)</option>
                    <option value="PIX">Pix</option>
                    <option value="BOLETO">Boleto</option>
                    <option value="CREDIT_CARD">Cartão de crédito</option>
                </select>
            </label>
            <button class="btn btn-primary" type="submit"><?= $status === 'canceled' ? '🔁 Assinar novamente' : '🚀 Assinar agora' ?> — <?= $brl($valor) ?>/mês</button>
        </form>
        <p class="muted" style="margin-top:8px;color:#6b7280;font-size:13px;">
            <?php if ($primeiro > $hoje): ?>
                A primeira cobrança vence em <strong><?= $fmt($primeiro) ?></strong><?= $status === 'trial' ? ' (fim do seu teste grátis — você não perde nenhum dia)' : ' (fim do período já pago)' ?>.
            <?php else: ?>
                A primeira cobrança vence hoje.
            <?php endif; ?>
        </p>
        <?php if (!billing_configurado()): ?>
            <p class="muted" style="margin-top:8px;color:#6b7280;font-size:13px;">O pagamento online está sendo ativado. Se precisar, fale com o suporte.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="panel">
    <h2><?= icon('relatorios', 18) ?>Histórico de pagamentos</h2>
    <table class="tbl">
        <thead><tr><th>Vencimento</th><th>Valor</th><th>Forma</th><th>Status</th><th>Pago em</th><th></th></tr></thead>
        <tbody>
        <?php
        $formas  = ['UNDEFINED'=>'A escolher','PIX'=>'Pix','BOLETO'=>'Boleto','CREDIT_CARD'=>'Cartão'];
        $rotulos = ['PENDING'=>'Em aberto','OVERDUE'=>'Vencida','CONFIRMED'=>'Paga','RECEIVED'=>'Paga','RECEIVED_IN_CASH'=>'Paga',
                    'REFUNDED'=>'Estornada','CHARGEBACK_REQUESTED'=>'Contestada','AWAITING_RISK_ANALYSIS'=>'Em análise'];
        foreach ($pagamentos as $p):
            $pago = in_array($p['status'], BILLING_PAGOS, true); ?>
            <tr>
                <td><?= $fmt($p['vencimento']) ?></td>
                <td><?= $brl($p['valor']) ?></td>
                <td><?= htmlspecialchars($formas[$p['forma']] ?? ($p['forma'] ?: '—')) ?></td>
                <td style="color:<?= $pago ? '#10b981' : ($p['status']==='OVERDUE' ? '#ef4444' : '#6b7280') ?>;font-weight:600;"><?= htmlspecialchars($rotulos[$p['status']] ?? $p['status']) ?></td>
                <td><?= $fmt($p['pago_em']) ?></td>
                <td><?php if ($p['url_fatura'] && $pagavel($p)): ?><a href="<?= htmlspecialchars($p['url_fatura']) ?>" target="_blank" rel="noopener">pagar</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pagamentos): ?>
            <tr><td colspan="6" style="text-align:center;padding:24px;color:#9ca3af;">Nenhuma cobrança gerada ainda.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</main></body></html>
