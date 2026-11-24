<?php
/**
 * Layout do painel Super Admin SyscomAI
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Exige super_admin
function requer_super_admin() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $u = $_SESSION['dot_user'] ?? null;
    if (!$u || $u['perfil'] !== 'super_admin') {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function sysadmin_log($acao, $empresa_id = null, $detalhes = []) {
    $u = $_SESSION['dot_user'] ?? null;
    if (!$u) return;
    db()->prepare("INSERT INTO dot_sysadmin_log (super_admin_id, acao, empresa_id, detalhes, ip) VALUES (?,?,?,?,?)")
        ->execute([$u['id'], $acao, $empresa_id, json_encode($detalhes), $_SERVER['REMOTE_ADDR'] ?? '']);
}

$sa = requer_super_admin();
$pagina = $pagina ?? 'dashboard';
$titulo = $titulo ?? 'Super Admin SyscomAI';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($titulo) ?> · SyscomAI</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#0f172a; color:#e2e8f0; }
.topbar { background:linear-gradient(90deg,#1e293b 0%,#334155 100%); padding:14px 24px; display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 8px rgba(0,0,0,0.3); }
.brand { display:flex; align-items:center; gap:10px; font-size:18px; font-weight:700; color:#fff; }
.brand .badge { background:#dc2626; color:#fff; padding:2px 8px; border-radius:4px; font-size:11px; letter-spacing:0.5px; }
.user-info { color:#94a3b8; font-size:13px; }
.user-info a { color:#f87171; margin-left:12px; text-decoration:none; }
.container { display:flex; min-height:calc(100vh - 56px); }
.sidebar { width:220px; background:#1e293b; padding:18px 0; }
.sidebar a { display:block; padding:11px 22px; color:#cbd5e1; text-decoration:none; font-size:14px; border-left:3px solid transparent; transition:all .15s; }
.sidebar a:hover { background:#334155; color:#fff; }
.sidebar a.active { border-left-color:#dc2626; background:#334155; color:#fff; font-weight:600; }
.sidebar .icon { display:inline-block; width:20px; margin-right:8px; }
.main { flex:1; padding:24px 32px; overflow-x:auto; }
h1 { color:#f1f5f9; margin-bottom:20px; font-size:24px; }
h2 { color:#cbd5e1; margin:24px 0 12px; font-size:18px; }
.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; margin-bottom:24px; }
.card { background:#1e293b; padding:20px; border-radius:10px; border:1px solid #334155; }
.card .label { color:#94a3b8; font-size:13px; margin-bottom:6px; }
.card .value { color:#f8fafc; font-size:28px; font-weight:700; }
.card .sub { color:#64748b; font-size:12px; margin-top:4px; }
.card.success { border-left:4px solid #10b981; }
.card.warning { border-left:4px solid #f59e0b; }
.card.danger { border-left:4px solid #ef4444; }
.card.info { border-left:4px solid #3b82f6; }
.panel { background:#1e293b; border:1px solid #334155; border-radius:10px; padding:20px; margin-bottom:20px; }
table { width:100%; border-collapse:collapse; }
th { background:#0f172a; color:#94a3b8; padding:10px 12px; text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #334155; }
td { padding:10px 12px; border-bottom:1px solid #334155; font-size:14px; color:#e2e8f0; }
tr:hover td { background:#283548; }
.badge-pill { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.3px; }
.badge-pill.free { background:#475569; color:#cbd5e1; }
.badge-pill.basic { background:#3b82f6; color:#fff; }
.badge-pill.pro { background:#8b5cf6; color:#fff; }
.badge-pill.enterprise { background:#fbbf24; color:#1e293b; }
.badge-pill.active { background:#10b981; color:#fff; }
.badge-pill.inactive { background:#ef4444; color:#fff; }
.badge-pill.trial { background:#f59e0b; color:#fff; }
.btn { padding:8px 14px; background:#dc2626; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:13px; text-decoration:none; display:inline-block; }
.btn:hover { background:#b91c1c; }
.btn.btn-sm { padding:5px 10px; font-size:12px; }
.btn.btn-outline { background:transparent; border:1px solid #475569; color:#cbd5e1; }
.btn.btn-outline:hover { background:#334155; color:#fff; }
.btn.btn-success { background:#10b981; }
.btn.btn-danger { background:#ef4444; }
.btn.btn-warning { background:#f59e0b; }
.alert { padding:12px 16px; border-radius:8px; margin-bottom:16px; }
.alert.success { background:rgba(16,185,129,0.15); border:1px solid #10b981; color:#a7f3d0; }
.alert.error { background:rgba(239,68,68,0.15); border:1px solid #ef4444; color:#fecaca; }
.alert.info { background:rgba(59,130,246,0.15); border:1px solid #3b82f6; color:#bfdbfe; }
input, select { background:#0f172a; border:1px solid #475569; color:#e2e8f0; padding:8px 12px; border-radius:6px; font-size:14px; }
input:focus, select:focus { outline:none; border-color:#dc2626; }
.muted { color:#64748b; font-size:12px; }
</style>
</head>
<body>
<div class="topbar">
    <div class="brand">
        🛡️ DOT-ON SuperAdmin <span class="badge">SYSCOMAI</span>
    </div>
    <div class="user-info">
        👤 <?= htmlspecialchars($sa['nome_completo'] ?? 'SysAdmin') ?> 
        · <a href="logout.php">Sair</a>
    </div>
</div>
<div class="container">
    <nav class="sidebar">
        <a href="index.php" class="<?= $pagina==='dashboard'?'active':'' ?>"><span class="icon">📊</span>Dashboard</a>
        <a href="empresas.php" class="<?= $pagina==='empresas'?'active':'' ?>"><span class="icon">🏢</span>Empresas</a>
        <a href="usuarios.php" class="<?= $pagina==='usuarios'?'active':'' ?>"><span class="icon">👥</span>Usuários</a>
        <a href="metricas.php" class="<?= $pagina==='metricas'?'active':'' ?>"><span class="icon">📈</span>Métricas</a>
        <a href="planos.php" class="<?= $pagina==='planos'?'active':'' ?>"><span class="icon">💳</span>Planos</a>
        <a href="faturamento.php" class="<?= $pagina==='faturamento'?'active':'' ?>"><span class="icon">💰</span>Faturamento</a>
        <a href="instaladores.php" class="<?= $pagina==='instaladores'?'active':'' ?>"><span class="icon">📦</span>Instaladores</a>
        <a href="auditoria.php" class="<?= $pagina==='auditoria'?'active':'' ?>"><span class="icon">🔍</span>Auditoria</a>
        <a href="smtp.php" class="<?= $pagina==='smtp'?'active':'' ?>"><span class="icon">📧</span>SMTP</a>
    </nav>
    <main class="main">
