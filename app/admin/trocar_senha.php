<?php
/**
 * Tela obrigatória de troca de senha no 1º login
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/icons.php';

$user = requer_login(true); // permite acessar nesta tela mesmo com flag

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $senha_atual = $_POST['senha_atual'] ?? '';
    $nova = $_POST['nova_senha'] ?? '';
    $conf = $_POST['confirma_senha'] ?? '';

    // Busca senha hash atual
    $st = db()->prepare("SELECT senha_hash FROM dot_usuarios WHERE id = ?");
    $st->execute([$user['id']]);
    $row = $st->fetch();

    if (!password_verify($senha_atual, $row['senha_hash'])) {
        $erro = 'Senha atual incorreta';
    } elseif (strlen($nova) < 8) {
        $erro = 'A nova senha precisa ter no mínimo 8 caracteres';
    } elseif ($nova !== $conf) {
        $erro = 'As senhas não coincidem';
    } elseif ($nova === $senha_atual) {
        $erro = 'A nova senha deve ser diferente da atual';
    } else {
        trocar_senha_usuario($user['id'], $nova);
        $_SESSION['dot_user']['precisa_trocar_senha'] = 0;
        header('Location: ' . destino_pos_login($user) . '?msg=senha_trocada');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trocar senha · DOT-ON</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:#0f172a;min-height:100vh;
    display:flex;align-items:center;justify-content:center;padding:20px;-webkit-font-smoothing:antialiased;
    background:radial-gradient(1100px 600px at 100% -10%,#dbeafe 0,transparent 55%),
               radial-gradient(900px 500px at -10% 110%,#d1fae5 0,transparent 55%),#f8fafc}
.card{background:#fff;border-radius:20px;padding:38px 36px;width:100%;max-width:470px;border:1px solid #e2e8f0;box-shadow:0 20px 50px rgba(15,23,42,.10)}
.brand{display:flex;flex-direction:column;align-items:center;text-align:center;margin-bottom:22px}
.brand .mark{display:flex;align-items:center;gap:10px;font-size:1.5rem;font-weight:800;letter-spacing:-.02em;margin-bottom:10px}
.brand h1{font-size:1.25rem;color:#0f172a;margin-bottom:6px}
.brand p{color:#64748b;font-size:.88rem}
.alert{display:flex;align-items:center;gap:9px;padding:11px 14px;border-radius:10px;font-size:.86rem;margin-bottom:16px}
.alert.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.alert.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-size:.84rem}
.alert svg{flex-shrink:0}
.field{margin-bottom:14px}
.field label{display:block;font-size:.8rem;font-weight:600;color:#334155;margin-bottom:6px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:.95rem;outline:none;font-family:inherit}
.field input:focus{border-color:#1d4ed8;box-shadow:0 0 0 3px rgba(29,78,216,.14)}
.requirements{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:.82rem;color:#475569}
.requirements li{margin:3px 0;list-style:none;padding-left:24px;position:relative}
.requirements li::before{content:'○';position:absolute;left:8px;color:#94a3b8}
.requirements li.ok{color:#059669}
.requirements li.ok::before{content:'✓'}
.btn{width:100%;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px;border-radius:10px;border:none;background:#1d4ed8;color:#fff;font-size:.98rem;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(29,78,216,.28);margin-top:6px;transition:all .15s;font-family:inherit}
.btn:hover{background:#1e40af}
</style>
</head>
<body>
<form class="card" method="post" id="form">
<?= csrf_field() ?>
<div class="brand">
<div class="mark"><?= icon_logo(30) ?><span>DOT-ON</span></div>
<h1>Defina sua nova senha</h1>
<p>Como é seu primeiro acesso, você precisa criar uma senha pessoal.</p>
</div>

<?php if ($erro): ?><div class="alert err"><?= icon('alert', 18) ?><span><?=htmlspecialchars($erro)?></span></div><?php endif; ?>

<div class="alert info"><?= icon('certificado', 18) ?><span>Olá <strong><?=htmlspecialchars($user['nome_completo'])?></strong>. Por segurança, é necessário alterar a senha temporária recebida por e-mail.</span></div>

<div class="field">
<label>Senha atual (a que veio no e-mail)</label>
<input type="password" name="senha_atual" required autofocus>
</div>

<div class="field">
<label>Nova senha</label>
<input type="password" name="nova_senha" id="nova" required minlength="8">
</div>

<div class="requirements">
<ul style="padding-left:0">
<li id="r_len">Mínimo 8 caracteres</li>
<li id="r_num">Pelo menos 1 número</li>
<li id="r_letra">Pelo menos 1 letra</li>
</ul>
</div>

<div class="field">
<label>Confirme a nova senha</label>
<input type="password" name="confirma_senha" id="conf" required minlength="8">
</div>

<button class="btn" type="submit"><?= icon('check', 18) ?><span>Salvar nova senha</span></button>
</form>

<script>
const nova = document.getElementById('nova');
const r_len = document.getElementById('r_len');
const r_num = document.getElementById('r_num');
const r_letra = document.getElementById('r_letra');
nova.addEventListener('input', () => {
    const v = nova.value;
    r_len.classList.toggle('ok', v.length >= 8);
    r_num.classList.toggle('ok', /\d/.test(v));
    r_letra.classList.toggle('ok', /[a-zA-Z]/.test(v));
});
</script>
</body>
</html>
