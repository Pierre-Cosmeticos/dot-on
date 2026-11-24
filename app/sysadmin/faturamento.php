<?php
$pagina = 'faturamento'; $titulo = 'Faturamento';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/billing.php';

$pdo = db();
$msg = ''; $msg_tipo = '';

billing_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar_config') {
        $campos = [
            'ambiente'    => in_array($_POST['ambiente'] ?? '', ['sandbox','production']) ? $_POST['ambiente'] : 'sandbox',
            'valor_plano' => max(0, (float)str_replace(',', '.', $_POST['valor_plano'] ?? '49.90')),
            'plano_nome'  => trim($_POST['plano_nome'] ?? 'DOT-ON Profissional') ?: 'DOT-ON Profissional',
            'dias_trial'  => max(0, min(365, (int)($_POST['dias_trial'] ?? 30))),
            'ativo'       => 1,
        ];
        // Só sobrescreve a chave se o campo veio preenchido (evita apagar por engano).
        $novaChave = trim($_POST['api_key'] ?? '');
        if ($novaChave !== '' && strpos($novaChave, '••') === false) $campos['api_key'] = $novaChave;

        $novoToken = trim($_POST['webhook_token'] ?? '');
        if ($novoToken !== '') $campos['webhook_token'] = $novoToken;

        billing_salvar_config($campos);
        sysadmin_log('billing_config', null, ['ambiente' => $campos['ambiente'], 'valor' => $campos['valor_plano']]);
        $msg = 'Configuração de faturamento salva.'; $msg_tipo = 'success';
    }

    if ($acao === 'gerar_token') {
        billing_salvar_config(['webhook_token' => bin2hex(random_bytes(16))]);
        $msg = 'Novo token de webhook gerado. Copie-o para o painel do Asaas.'; $msg_tipo = 'success';
    }

    if ($acao === 'salvar_token') {
        // Permite colar um token definido no Asaas (ex.: gerado lá) para que os
        // dois lados batam. billing_salvar_config só toca no campo informado.
        $t = trim($_POST['webhook_token'] ?? '');
        if ($t === '') {
            $msg = 'Cole o token antes de salvar.'; $msg_tipo = 'error';
        } else {
            billing_salvar_config(['webhook_token' => $t]);
            sysadmin_log('billing_webhook_token', null, []);
            $msg = 'Token do webhook salvo. Confirme que é idêntico ao do Asaas.'; $msg_tipo = 'success';
        }
    }

    if ($acao === 'sincronizar') {
        $r = billing_reconciliar();
        if (!empty($r['ok'])) {
            $msg = "Sincronização concluída: {$r['processadas']} assinatura(s) atualizada(s)" . ($r['erros'] ? ", {$r['erros']} com erro (ver log)" : '') . '.';
            $msg_tipo = $r['erros'] ? 'error' : 'success';
        } else {
            $msg = 'Não foi possível sincronizar: ' . ($r['erro'] ?? 'erro desconhecido'); $msg_tipo = 'error';
        }
        sysadmin_log('billing_sync', null, $r);
    }

    if ($acao === 'testar') {
        $cli = AsaasClient::fromConfig();
        if (!$cli) { $msg = 'Configure a chave da API antes de testar.'; $msg_tipo = 'error'; }
        else {
            try {
                // GET leve — valida a credencial SEM criar nenhum dado no Asaas.
                $cli->validarCredencial();
                $amb = (billing_config()['ambiente'] ?? 'sandbox') === 'production' ? 'Produção' : 'Sandbox';
                $msg = "✓ Conexão com o Asaas OK ({$amb}) — credencial válida."; $msg_tipo = 'success';
            } catch (AsaasException $e) {
                $msg = '✗ Falha: ' . $e->getMessage() . ' (confira a chave e se o ambiente bate com ela).';
                $msg_tipo = 'error';
            }
        }
    }
}

$cfg = billing_config(true);
$configurado = billing_configurado();

// Métricas de faturamento
$assinaturas = $pdo->query("SELECT a.*, e.razao_social, e.nome_fantasia
    FROM dot_assinaturas a JOIN dot_empresas e ON e.id = a.empresa_id
    ORDER BY FIELD(a.status,'overdue','active','pending','canceled'), a.id DESC")->fetchAll();

$ativas   = array_filter($assinaturas, fn($a) => $a['status'] === 'active');
$overdue  = array_filter($assinaturas, fn($a) => $a['status'] === 'overdue');
$mrr      = array_sum(array_map(fn($a) => (float)$a['valor'], $ativas));
$recebido_mes = (float)$pdo->query("SELECT COALESCE(SUM(valor),0) FROM dot_pagamentos
    WHERE status IN ('CONFIRMED','RECEIVED','RECEIVED_IN_CASH') AND MONTH(pago_em)=MONTH(CURDATE()) AND YEAR(pago_em)=YEAR(CURDATE())")->fetchColumn();

$brl = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$webhook_url = rtrim((require __DIR__ . '/../config/app.php')['base_url'], '/') . '/api/asaas_webhook.php';
?>

<h1>💰 Faturamento (Asaas)</h1>

<?php if ($msg): ?><div class="alert <?= $msg_tipo ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if ($configurado && empty($cfg['webhook_token'])): ?>
<div class="alert error">
    🔔 O <strong>token do webhook</strong> não está definido — o webhook recusa todos os avisos do Asaas
    até ele ser configurado. Gere um token abaixo e cadastre o mesmo valor no Asaas.
</div>
<?php endif; ?>

<?php if (!$configurado): ?>
<div class="alert info">
    ⚙️ O gateway ainda não está configurado. Preencha a <strong>chave de API do Asaas</strong> abaixo para
    ativar as cobranças. Em ambiente <em>sandbox</em> nada é cobrado de verdade — ideal para testar.
</div>
<?php endif; ?>

<div class="cards">
    <div class="card success">
        <div class="label">MRR (receita recorrente)</div>
        <div class="value"><?= $brl($mrr) ?></div>
        <div class="sub"><?= count($ativas) ?> assinaturas ativas</div>
    </div>
    <div class="card info">
        <div class="label">Recebido no mês</div>
        <div class="value"><?= $brl($recebido_mes) ?></div>
    </div>
    <div class="card warning">
        <div class="label">Inadimplentes</div>
        <div class="value"><?= count($overdue) ?></div>
    </div>
    <div class="card">
        <div class="label">Plano único</div>
        <div class="value"><?= $brl($cfg['valor_plano']) ?><span style="font-size:13px;color:#94a3b8;">/mês</span></div>
        <div class="sub"><?= htmlspecialchars($cfg['plano_nome']) ?></div>
    </div>
</div>

<div class="panel">
    <h2 style="margin-top:0;">⚙️ Configuração do gateway</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar_config">
        <table>
            <tr>
                <th style="width:220px;">Ambiente</th>
                <td>
                    <select name="ambiente">
                        <option value="sandbox" <?= ($cfg['ambiente']??'')==='sandbox'?'selected':'' ?>>Sandbox (testes)</option>
                        <option value="production" <?= ($cfg['ambiente']??'')==='production'?'selected':'' ?>>Produção (cobra de verdade)</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Chave da API (access_token)</th>
                <td><input type="text" name="api_key" style="width:100%;max-width:520px;" autocomplete="off"
                    placeholder="<?= $configurado ? '•••••••• (deixe em branco para manter)' : '$aact_...' ?>"></td>
            </tr>
            <tr>
                <th>Valor do plano (mensal)</th>
                <td>R$ <input type="text" name="valor_plano" value="<?= number_format((float)$cfg['valor_plano'],2,',','.') ?>" style="width:120px;"></td>
            </tr>
            <tr>
                <th>Nome do plano</th>
                <td><input type="text" name="plano_nome" value="<?= htmlspecialchars($cfg['plano_nome']) ?>" style="width:100%;max-width:360px;"></td>
            </tr>
            <tr>
                <th>Dias de trial</th>
                <td><input type="number" name="dias_trial" value="<?= (int)$cfg['dias_trial'] ?>" min="0" max="365" style="width:90px;"> dias grátis no cadastro</td>
            </tr>
        </table>
        <div style="margin-top:16px;">
            <button class="btn btn-success" type="submit">💾 Salvar configuração</button>
        </div>
    </form>

    <form method="post" style="display:inline-block;margin-top:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="testar">
        <button class="btn btn-outline" type="submit">🔌 Testar conexão</button>
    </form>
    <form method="post" style="display:inline-block;margin-top:10px;margin-left:6px;">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="sincronizar">
        <button class="btn btn-outline" type="submit" title="Consulta o Asaas e corrige o status de todas as assinaturas (o mesmo que o cron diário faz)">🔄 Sincronizar agora</button>
    </form>
</div>

<div class="panel">
    <h2 style="margin-top:0;">🔔 Webhook</h2>
    <p style="color:#cbd5e1;line-height:1.7;">
        No painel do Asaas → <em>Integrações → Webhooks</em>, cadastre a URL abaixo e o token de autenticação.
        O Asaas notificará automaticamente cada pagamento confirmado, vencido ou estornado.
    </p>
    <table>
        <tr><th style="width:220px;">URL do webhook</th><td><code><?= htmlspecialchars($webhook_url) ?></code></td></tr>
        <tr><th>Token de autenticação</th>
            <td>
                <?php if (!empty($cfg['webhook_token'])): ?>
                    <code><?= htmlspecialchars($cfg['webhook_token']) ?></code>
                <?php else: ?>
                    <span class="muted">não definido</span>
                <?php endif; ?>
                <form method="post" style="display:inline-block;margin-left:10px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="gerar_token">
                    <button class="btn btn-sm btn-outline" type="submit">🔁 Gerar novo token</button>
                </form>
                <div style="margin-top:10px;">
                    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="salvar_token">
                        <input type="text" name="webhook_token" autocomplete="off" style="width:100%;max-width:420px;"
                               placeholder="colar aqui o token definido no Asaas (ex.: whsec_...)">
                        <button class="btn btn-sm btn-outline" type="submit">💾 Salvar token do Asaas</button>
                    </form>
                    <p class="muted" style="margin-top:6px;font-size:12px;">
                        Use isto se o token vier do Asaas. Ele precisa ser <strong>idêntico</strong> ao
                        campo “Token de autenticação” do webhook no Asaas — senão o webhook responde 401.
                    </p>
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="panel">
    <h2 style="margin-top:0;">📄 Assinaturas</h2>
    <table>
        <thead><tr><th>Empresa</th><th>Status</th><th>Valor</th><th>Próx. vencimento</th><th>Pago até</th><th>Ambiente</th><th>Subscription ID</th></tr></thead>
        <tbody>
        <?php foreach ($assinaturas as $a):
            $badge = ['active'=>'active','overdue'=>'trial','pending'=>'basic','canceled'=>'inactive'][$a['status']] ?? 'free'; ?>
            <tr>
                <td><strong><?= htmlspecialchars($a['nome_fantasia'] ?: $a['razao_social']) ?></strong></td>
                <td><span class="badge-pill <?= $badge ?>"><?= htmlspecialchars($a['status']) ?></span></td>
                <td><?= $brl($a['valor']) ?></td>
                <td class="muted"><?= $a['proximo_vencimento'] ? date('d/m/Y', strtotime($a['proximo_vencimento'])) : '—' ?></td>
                <td class="muted"><?= !empty($a['pago_ate']) ? date('d/m/Y', strtotime($a['pago_ate'])) : '—' ?></td>
                <td class="muted"><?= htmlspecialchars($a['ambiente'] ?? '—') ?><?= ($a['ambiente'] ?? null) !== ($cfg['ambiente'] ?? 'sandbox') ? ' <span title="De outro ambiente: ignorada">⚠️</span>' : '' ?></td>
                <td class="muted"><code><?= htmlspecialchars($a['asaas_subscription_id'] ?: '—') ?></code></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$assinaturas): ?>
            <tr><td colspan="7" style="text-align:center;padding:30px;color:#64748b;">Nenhuma assinatura ainda.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
