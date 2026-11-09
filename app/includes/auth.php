<?php
/**
 * DOT-ON Auth - autenticação com suporte multi-tenant + troca de senha obrigatória
 */
require_once __DIR__ . '/db.php';

/**
 * Autentica via e-mail + senha. Retorna dados do usuário ou null.
 */
function autenticar($email, $senha) {
    if (!$email || !$senha) return null;
    $st = db()->prepare("SELECT * FROM dot_usuarios WHERE LOWER(email) = LOWER(?) AND ativo = 1 LIMIT 1");
    $st->execute([trim($email)]);
    $user = $st->fetch();
    if (!$user) return null;
    if (!password_verify($senha, $user['senha_hash'])) return null;

    // Registra último login
    try {
        db()->prepare("UPDATE dot_usuarios SET ultimo_login = NOW() WHERE id = ?")->execute([$user['id']]);
    } catch (Throwable $e) {}

    return $user;
}

/**
 * Autentica via api_token. Retorna usuário ou null.
 */
function autenticar_token($token) {
    if (!$token) return null;
    $st = db()->prepare("SELECT * FROM dot_usuarios WHERE api_token = ? AND ativo = 1 LIMIT 1");
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/**
 * Pega Bearer Token do header
 */
function bearer_token() {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'authorization' && preg_match('/Bearer\s+(.+)/i', $v, $m)) return trim($m[1]);
        if (strtolower($k) === 'x-auth-token') return trim($v);
    }
    return $_GET['token'] ?? null;
}

/**
 * Sessão WEB: requer login. Se não tiver, redireciona para login.
 * Se precisa_trocar_senha=1, redireciona para tela de troca.
 */
function requer_login($permitir_troca_senha = false) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['dot_user'])) {
        $back = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header("Location: login.php?back=$back");
        exit;
    }
    // Revalida no banco: usuário excluído ou desativado perde a sessão na hora,
    // e edições (perfil, nome, e-mail) feitas pelo admin valem sem novo login.
    try {
        $st = db()->prepare("SELECT * FROM dot_usuarios WHERE id = ? AND ativo = 1 LIMIT 1");
        $st->execute([(int)$_SESSION['dot_user']['id']]);
        $atual = $st->fetch();
    } catch (Throwable $e) {
        $atual = $_SESSION['dot_user']; // falha de banco não derruba sessões válidas
    }
    if (!$atual) {
        logout();
        header("Location: login.php");
        exit;
    }
    if (isset($atual['senha_hash'])) {
        $troca = (int)($_SESSION['dot_user']['precisa_trocar_senha'] ?? 0);
        login_sessao($atual);
        // trocar_senha.php zera a flag só na sessão; preserva esse estado.
        $_SESSION['dot_user']['precisa_trocar_senha'] = min($troca, (int)($atual['precisa_trocar_senha'] ?? 0));
    }
    $u = $_SESSION['dot_user'];

    // Se precisa trocar senha e não estamos na tela de troca, redireciona
    if (!$permitir_troca_senha && !empty($u['precisa_trocar_senha'])) {
        $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($cur !== 'trocar_senha.php') {
            header("Location: trocar_senha.php");
            exit;
        }
    }
    return $u;
}

/** Perfis com acesso ao painel administrativo (/app/admin). */
const PERFIS_PAINEL = ['admin', 'gestor', 'rh'];

function pode_acessar_painel($u): bool {
    return in_array($u['perfil'] ?? '', PERFIS_PAINEL, true);
}

/**
 * Destino padrão após login/troca de senha, relativo a /app/admin/.
 * Funcionário comum sempre vai para o portal /me/.
 */
function destino_pos_login($u): string {
    return pode_acessar_painel($u) ? 'index.php' : '../me/';
}

/**
 * Exige login + perfil de painel (admin/gestor/rh). Funcionário comum é
 * mandado para o portal /me/ em vez de ver o painel.
 */
function requer_painel() {
    $u = requer_login();
    if (!pode_acessar_painel($u)) {
        header('Location: ../me/');
        exit;
    }
    return $u;
}

/**
 * Exige login + um dos perfis informados. Senão, 403.
 * Use no topo de páginas restritas a admin/rh/gestor, e em endpoints de exportação.
 */
function requer_perfil(array $perfis, $permitir_troca_senha = false) {
    $u = requer_login($permitir_troca_senha);
    if (!in_array($u['perfil'] ?? '', $perfis, true)) {
        http_response_code(403);
        die('Acesso negado: você não tem permissão para acessar esta página.');
    }
    return $u;
}

/* ============ CSRF (proteção de formulários POST) ============ */

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Campo hidden pronto para embutir nos formulários. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** Valida o token do POST. Encerra a requisição com 419 se inválido. */
function csrf_check(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $t = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !is_string($t) || !hash_equals($_SESSION['csrf_token'], $t)) {
        http_response_code(419);
        die('Sessão expirada ou requisição inválida (CSRF). Recarregue a página e tente novamente.');
    }
}

/**
 * Login na sessão web
 */
function login_sessao($user) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['dot_user'] = [
        'id' => (int)$user['id'],
        'empresa_id' => (int)$user['empresa_id'],
        'nome_completo' => $user['nome_completo'],
        'email' => $user['email'],
        'perfil' => $user['perfil'],
        'matricula' => $user['matricula'],
        'escala_id' => (int)$user['escala_id'],
        'api_token' => $user['api_token'],
        'precisa_trocar_senha' => (int)($user['precisa_trocar_senha'] ?? 0),
    ];
}

/**
 * Logout
 */
function logout() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION['dot_user']);
    session_destroy();
}

/**
 * Atualiza senha do usuário
 */
function trocar_senha_usuario($user_id, $nova_senha) {
    $hash = password_hash($nova_senha, PASSWORD_BCRYPT);
    db()->prepare("UPDATE dot_usuarios SET senha_hash = ?, precisa_trocar_senha = 0, token_reset = NULL, token_reset_expira = NULL WHERE id = ?")
        ->execute([$hash, $user_id]);
    return true;
}

/**
 * Cria token de reset de senha (com expiração de 1h)
 */
function criar_token_reset($email) {
    $st = db()->prepare("SELECT id, nome_completo FROM dot_usuarios WHERE LOWER(email) = LOWER(?) AND ativo = 1 LIMIT 1");
    $st->execute([trim($email)]);
    $u = $st->fetch();
    if (!$u) return null;

    $token = bin2hex(random_bytes(32));
    $exp = date('Y-m-d H:i:s', time() + 3600);
    db()->prepare("UPDATE dot_usuarios SET token_reset = ?, token_reset_expira = ? WHERE id = ?")
        ->execute([$token, $exp, $u['id']]);

    return ['token' => $token, 'user_id' => $u['id'], 'nome' => $u['nome_completo']];
}

/**
 * Valida token de reset e retorna user
 */
function validar_token_reset($token) {
    if (!$token) return null;
    $st = db()->prepare("SELECT * FROM dot_usuarios WHERE token_reset = ? AND token_reset_expira > NOW() AND ativo = 1");
    $st->execute([$token]);
    return $st->fetch() ?: null;
}
