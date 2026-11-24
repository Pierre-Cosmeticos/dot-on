<?php
/**
 * DOT-ON - Conexão PDO
 */

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = require __DIR__ . '/../config/database.php';
        // Desenvolvimento local: DOTON_DB_HOST aponta para um banco de teste e
        // substitui a config. Em produção essa variável não existe.
        if (getenv('DOTON_DB_HOST')) {
            $cfg['host']     = getenv('DOTON_DB_HOST');
            $cfg['port']     = getenv('DOTON_DB_PORT') ?: 3306;
            $cfg['database'] = getenv('DOTON_DB_NAME') ?: $cfg['database'];
            $cfg['username'] = getenv('DOTON_DB_USER') ?: 'root';
            $cfg['password'] = (string)getenv('DOTON_DB_PASS');
        }
        $porta = !empty($cfg['port']) ? ";port={$cfg['port']}" : '';
        $dsn = "mysql:host={$cfg['host']}{$porta};dbname={$cfg['database']};charset={$cfg['charset']}";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '-03:00'");
    }
    return $pdo;
}

function auditar(?int $userId, string $acao, string $entidade = '', $entidadeId = null, $detalhes = null) {
    try {
        db()->prepare("INSERT INTO dot_auditoria (usuario_id, acao, entidade, entidade_id, detalhes, ip, user_agent)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $userId, $acao, $entidade, $entidadeId,
                $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
            ]);
    } catch (Throwable $e) { /* silencioso */ }
}
