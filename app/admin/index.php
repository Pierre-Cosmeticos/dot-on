<?php
$titulo = 'Dashboard'; $pagina = 'dashboard';
require __DIR__ . '/_layout.php';

$emp_id = $user['empresa_id'];
$hoje = date('Y-m-d');

// Todas as queries filtram por empresa
$st = db()->prepare("SELECT COUNT(*) FROM dot_usuarios WHERE empresa_id=? AND perfil='funcionario' AND ativo=1");
$st->execute([$emp_id]);
$total_func = $st->fetchColumn();

$st = db()->prepare("SELECT COUNT(*) FROM dot_sessoes s JOIN dot_usuarios u ON u.id=s.usuario_id WHERE u.empresa_id=? AND s.data_ref=?");
$st->execute([$emp_id, $hoje]);
$sessoes_hoje = $st->fetchColumn();

$st = db()->prepare("SELECT COUNT(*) FROM dot_horas_extras he JOIN dot_usuarios u ON u.id=he.usuario_id WHERE u.empresa_id=? AND he.status='pendente'");
$st->execute([$emp_id]);
$extras_pend = $st->fetchColumn();

$st = db()->prepare("SELECT COUNT(*) FROM dot_batidas WHERE empresa_id=? AND DATE(momento)=?");
$st->execute([$emp_id, $hoje]);
$batidas_hoje = $st->fetchColumn();

$st = db()->prepare("SELECT b.nsr, b.tipo, b.momento, u.nome_completo
    FROM dot_batidas b JOIN dot_usuarios u ON u.id=b.usuario_id
    WHERE b.empresa_id=?
    ORDER BY b.momento DESC LIMIT 15");
$st->execute([$emp_id]);
$ultimas = $st->fetchAll();
?>
<?php if (!empty($_GET['msg']) && $_GET['msg']==='senha_trocada'): ?>
<div style="display:flex;align-items:center;gap:9px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px 16px;border-radius:12px;margin-bottom:18px">
<?= icon('check', 18) ?><span><strong>Senha alterada com sucesso!</strong> Você já está usando sua nova senha.</span>
</div>
<?php endif; ?>

<?php if ($empresa && ($empresa['assinatura_status'] ?? 'trial')==='trial' && !empty($empresa['trial_expira'])):
    $dias = max(0, billing_dias_entre(billing_hoje(), substr($empresa['trial_expira'], 0, 10)));
?>
<div style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;padding:12px 16px;border-radius:12px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;gap:12px">
<div style="display:flex;align-items:center;gap:9px"><?= icon('clock', 18) ?><span><strong>Teste grátis</strong> · <?= $dias ?> dia(s) restante(s)</span></div>
<a href="assinatura.php" class="btn btn-primary" style="padding:7px 14px;font-size:.82rem">Assinar</a>
</div>
<?php endif; ?>

<div class="cards">
    <div class="card"><div class="card-ic"><?= icon('funcionarios', 20) ?></div><div class="num"><?= $total_func ?></div><div class="lbl">Funcionários ativos</div></div>
    <div class="card"><div class="card-ic"><?= icon('espelho', 20) ?></div><div class="num"><?= $sessoes_hoje ?></div><div class="lbl">Sessões hoje</div></div>
    <div class="card"><div class="card-ic"><?= icon('batidas', 20) ?></div><div class="num"><?= $batidas_hoje ?></div><div class="lbl">Batidas hoje</div></div>
    <div class="card alert-card"><div class="card-ic"><?= icon('extras', 20) ?></div><div class="num"><?= $extras_pend ?></div><div class="lbl">Extras pendentes</div></div>
</div>

<div class="panel">
    <h2><?= icon('batidas', 18) ?>Últimas batidas da sua empresa</h2>
    <table class="tbl">
        <thead><tr><th>NSR</th><th>Funcionário</th><th>Tipo</th><th>Momento</th></tr></thead>
        <tbody>
        <?php foreach ($ultimas as $b): ?>
            <tr>
                <td><code><?= str_pad($b['nsr'], 9, '0', STR_PAD_LEFT) ?></code></td>
                <td><?= htmlspecialchars($b['nome_completo']) ?></td>
                <td><span class="tag tag-<?= $b['tipo'] ?>"><?= str_replace('_',' ',$b['tipo']) ?></span></td>
                <td><?= date('d/m/Y H:i:s', strtotime($b['momento'])) ?></td>
            </tr>
        <?php endforeach; if (!$ultimas): ?>
            <tr><td colspan="4" class="empty">Nenhuma batida registrada ainda. <a href="downloads.php">Distribua o agente Windows</a> para seus funcionários começarem.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($total_func == 0): ?>
<div class="panel" style="background:linear-gradient(135deg,#eff6ff,#f0fdf4);border-color:#bfdbfe">
<h2 style="color:#1e3a8a"><?= icon('rocket', 18) ?>Próximos passos</h2>
<ol style="line-height:2;color:#1e40af;padding-left:18px">
<li><a href="funcionarios.php">Cadastre seus funcionários</a> (ou importe via planilha)</li>
<li><a href="empresa.php">Complete os dados da sua empresa</a> (endereço, CNAE, etc.)</li>
<li><a href="downloads.php">Distribua o DOT-ON-Agent.exe</a> para os funcionários</li>
<li>Acompanhe as batidas em tempo real aqui no Dashboard</li>
</ol>
</div>
<?php endif; ?>

</main></body></html>
