<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/icons.php';
$user = requer_painel(); // painel só para admin/gestor/rh; funcionário vai para /me/
$empresa = tenant_empresa();
$pagina = $pagina ?? 'dashboard';

// Itens do menu: [slug, arquivo, ícone, rótulo]
$nav_itens = [
    ['dashboard',      'index.php',         'dashboard',      'Dashboard'],
    ['funcionarios',   'funcionarios.php',  'funcionarios',   'Funcionários'],
    ['empresa',        'empresa.php',       'empresa',        'Empresa'],
    ['certificado',    'certificado.php',   'certificado',    'Certificado ICP'],
    ['batidas',        'batidas.php',       'batidas',        'Batidas'],
    ['ajuste_ponto',   'ajuste_ponto.php',  'ajuste',         'Ajuste de Ponto'],
    ['auditoria',      'auditoria.php',     'auditoria',      'Auditoria Fiscal'],
    ['extras',         'horas_extras.php',  'extras',         'Horas Extras'],
    ['justificativas', 'justificativas.php','justificativas', 'Justificativas'],
    ['banco_horas',    'banco_horas.php',   'banco_horas',    'Banco de Horas'],
    ['espelho',        'espelho.php',       'espelho',        'Espelho de Ponto'],
    ['relatorios',     'relatorios.php',    'relatorios',     'Relatórios & AFD/AEJ'],
    ['config',         'config.php',        'config',         'Configurações'],
    ['downloads',      'downloads.php',     'downloads',      'Agente Windows'],
];

// Badges de pendências (extras e justificativas)
$badges = ['extras' => 0, 'justificativas' => 0];
try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM dot_horas_extras he
                           JOIN dot_usuarios u ON u.id = he.usuario_id
                           WHERE u.empresa_id = ? AND he.status='pendente' AND he.minutos_solicitados > 0");
    $stmt->execute([$user['empresa_id']]);
    $badges['extras'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}
try {
    require_once __DIR__ . '/../includes/justificativas.php';
    $badges['justificativas'] = (int)jus_contar_pendentes((int)$user['empresa_id']);
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>DOT-ON · <?= htmlspecialchars($titulo ?? 'Painel') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%232563eb'/><text x='50' y='73' font-size='60' font-family='Segoe UI,Arial,sans-serif' font-weight='700' fill='white' text-anchor='middle'>D</text></svg>">
<script>(function(){try{if(localStorage.getItem('dot-theme')==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="admin-body">
<input type="checkbox" id="nav-toggle" class="nav-toggle">
<label for="nav-toggle" class="nav-burger" aria-label="Menu">
    <span></span><span></span><span></span>
</label>
<aside class="sidebar">
    <div class="brand"><?= icon_logo(30) ?><span>DOT-ON</span></div>
    <?php if ($empresa): ?>
    <div class="brand-company">
        <span class="lbl">Empresa</span>
        <div class="name"><?= icon('empresa', 15) ?><span><?= htmlspecialchars($empresa['nome_fantasia'] ?: $empresa['razao_social']) ?></span></div>
        <?php if ($empresa['plano'] === 'free' && $empresa['trial_expira']):
            $dias = max(0, (strtotime($empresa['trial_expira']) - time()) / 86400);
        ?>
        <div class="trial"><?= round($dias) ?> dias de trial restantes</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <nav>
        <?php foreach ($nav_itens as [$slug, $arquivo, $ic, $rotulo]):
            $b = $badges[$slug] ?? 0; ?>
        <a href="<?= $arquivo ?>" class="<?= $pagina === $slug ? 'active' : '' ?>">
            <?= icon($ic) ?><span class="nav-label"><?= $rotulo ?></span>
            <?php if ($b > 0): ?><span class="badge"><?= $b ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="theme-row">
        <span class="theme-lbl"><?= icon('moon', 16) ?><span>Modo escuro</span></span>
        <label class="switch">
            <input type="checkbox" id="theme-switch">
            <span class="slider"></span>
        </label>
    </div>
    <div class="userbox">
        <div class="u-info">
            <strong><?= htmlspecialchars($user['nome_completo']) ?></strong>
            <small><?= htmlspecialchars($user['perfil']) ?></small>
        </div>
        <a class="logout" href="logout.php"><?= icon('logout', 16) ?><span>Sair</span></a>
    </div>
</aside>
<script>
(function(){
    var sw = document.getElementById('theme-switch');
    if (!sw) return;
    sw.checked = document.documentElement.getAttribute('data-theme') === 'dark';
    sw.addEventListener('change', function(){
        if (sw.checked) { document.documentElement.setAttribute('data-theme','dark'); }
        else { document.documentElement.removeAttribute('data-theme'); }
        try { localStorage.setItem('dot-theme', sw.checked ? 'dark' : 'light'); } catch(e){}
    });
})();
</script>
<main class="content">
    <h1><?= htmlspecialchars($titulo ?? 'Painel') ?></h1>
