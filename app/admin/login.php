<?php
/**
 * Tela de login web - DOT-ON SaaS
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/icons.php';

$erro = '';
// Evita open redirect: só aceita caminhos locais relativos (sem host/esquema).
$back = $_GET['back'] ?? 'index.php';
if (!is_string($back) || $back === '' || preg_match('#^(?:[a-z]+:)?//#i', $back) || $back[0] === '/' || strpos($back, "\\") !== false) {
    $back = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $user = autenticar($email, $senha);
    if ($user) {
        login_sessao($user);
        // Se precisa trocar senha, vai para tela de troca
        if (!empty($user['precisa_trocar_senha'])) {
            header('Location: trocar_senha.php');
            exit;
        }
        // Funcionário comum sempre vai para o portal /me/ (nunca para o painel,
        // mesmo que o ?back aponte para uma página do admin). Gestor/RH/admin
        // seguem o ?back — inclusive ../me/ quando vieram do portal.
        if (!pode_acessar_painel($user)) {
            header('Location: ../me/');
            exit;
        }
        header('Location: ' . $back);
        exit;
    }
    $erro = 'E-mail ou senha incorretos';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login · DOT-ON</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:#0f172a;min-height:100vh;
    display:flex;align-items:center;justify-content:center;padding:20px;-webkit-font-smoothing:antialiased;
    background:radial-gradient(1100px 600px at 100% -10%,#dbeafe 0,transparent 55%),
               radial-gradient(900px 500px at -10% 110%,#d1fae5 0,transparent 55%),#f8fafc}
.card{background:#fff;border-radius:20px;padding:38px 36px;width:100%;max-width:410px;
    border:1px solid #e2e8f0;box-shadow:0 20px 50px rgba(15,23,42,.10)}
.brand{display:flex;flex-direction:column;align-items:center;text-align:center;margin-bottom:26px}
.brand .mark{display:flex;align-items:center;gap:10px;font-size:1.5rem;font-weight:800;letter-spacing:-.02em}
.brand .sub{color:#64748b;font-size:.88rem;margin-top:8px}
.alert{display:flex;align-items:center;gap:9px;padding:11px 14px;border-radius:10px;font-size:.86rem;margin-bottom:16px;
    background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.alert svg{flex-shrink:0}
.alert.success{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.field{margin-bottom:14px}
.field label{display:block;font-size:.8rem;font-weight:600;color:#334155;margin-bottom:6px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:.95rem;outline:none;transition:all .15s;font-family:inherit}
.field input:focus{border-color:#1d4ed8;box-shadow:0 0 0 3px rgba(29,78,216,.14)}
.btn{width:100%;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px;border-radius:10px;
    border:none;background:#1d4ed8;color:#fff;font-size:.98rem;font-weight:700;cursor:pointer;
    box-shadow:0 4px 12px rgba(29,78,216,.28);margin-top:6px;transition:all .15s;font-family:inherit}
.btn:hover{background:#1e40af;box-shadow:0 6px 18px rgba(29,78,216,.36)}
.links{margin-top:20px;display:flex;justify-content:space-between;font-size:.85rem}
.links a{color:#1d4ed8;text-decoration:none;font-weight:500}
.links a:hover{text-decoration:underline}
.divider{margin:22px 0 4px;padding-top:20px;border-top:1px solid #e2e8f0;text-align:center;color:#64748b;font-size:.85rem}
.divider .new-link{display:inline-flex;align-items:center;gap:7px;background:#10b981;color:#fff;padding:10px 22px;
    border-radius:10px;text-decoration:none;font-weight:600;margin-top:12px;box-shadow:0 4px 12px rgba(16,185,129,.28);transition:all .15s}
.divider .new-link:hover{background:#059669}
</style>
</head>
<body>
<form class="card" method="post">
<div class="brand">
<div class="mark"><?= icon_logo(30) ?><span>DOT-ON</span></div>
<div class="sub">Controle de Ponto Digital</div>
</div>

<?php if ($erro): ?><div class="alert"><?= icon('alert', 18) ?><span><?=htmlspecialchars($erro)?></span></div><?php endif; ?>
<?php if (!empty($_GET['msg']) && $_GET['msg']==='senha_alterada'): ?><div class="alert success"><?= icon('check', 18) ?><span>Senha alterada com sucesso! Faça login.</span></div><?php endif; ?>
<?php if (!empty($_GET['msg']) && $_GET['msg']==='reset_enviado'): ?><div class="alert success"><?= icon('mail', 18) ?><span>Verifique seu e-mail para redefinir a senha.</span></div><?php endif; ?>

<div class="field">
<label>E-mail</label>
<input type="email" name="email" required autofocus placeholder="voce@empresa.com.br" value="<?=htmlspecialchars($_POST['email'] ?? '')?>">
</div>
<div class="field">
<label>Senha</label>
<input type="password" name="senha" required placeholder="••••••••">
</div>
<button class="btn" type="submit"><span>Entrar</span><?= icon('arrow-right', 18) ?></button>

<div class="links">
<a href="esqueci_senha.php">Esqueci minha senha</a>
<a href="../">← Voltar ao site</a>
</div>

<div class="divider">
Ainda não tem conta?<br>
<a href="../signup.php" class="new-link"><?= icon('rocket', 17) ?><span>Criar conta grátis</span></a>
</div>
</form>
</body>
</html>
