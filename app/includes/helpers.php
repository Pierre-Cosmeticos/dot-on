<?php
/**
 * DOT-ON - Helpers genéricos
 */

function json_response($data, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // Cabeçalhos CORS já são definidos por requisição no roteador (allowlist).
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $_POST;
}

// bearer_token() removida — já definida em auth.php

function proximo_nsr(int $empresa_id): int {
    $pdo = db();
    // Upsert atômico: cria a linha da empresa se não existir e incrementa.
    // Evita o bug em que um UPDATE sem linha-semente deixava o NSR preso em 1.
    $gerencia_tx = !$pdo->inTransaction();
    if ($gerencia_tx) $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO dot_nsr_sequencia (empresa_id, ultimo_nsr) VALUES (?, 1)
                       ON DUPLICATE KEY UPDATE ultimo_nsr = ultimo_nsr + 1")
            ->execute([$empresa_id]);
        $stmt = $pdo->prepare("SELECT ultimo_nsr FROM dot_nsr_sequencia WHERE empresa_id = ?");
        $stmt->execute([$empresa_id]);
        $proximo = (int)$stmt->fetchColumn();
        if ($gerencia_tx) $pdo->commit();
        return $proximo;
    } catch (Throwable $e) {
        if ($gerencia_tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function hash_batida(int $nsr, int $usuario_id, string $tipo, string $momento, ?string $hash_anterior): string {
    return hash('sha256', "$nsr|$usuario_id|$tipo|$momento|" . ($hash_anterior ?? ''));
}

function fmt_minutos(int $min): string {
    $sinal = $min < 0 ? '-' : '';
    $min = abs($min);
    return sprintf("%s%02d:%02d", $sinal, intdiv($min, 60), $min % 60);
}

/**
 * SHA-256 do executável do agent, com cache por mtime para não re-hashear
 * um binário de dezenas de MB a cada checagem de versão de cada cliente.
 */
function agent_exe_sha256(string $exe_path): ?string {
    if (!is_file($exe_path)) return null;
    $mtime = filemtime($exe_path);
    $cache = $exe_path . '.sha256.json';
    if (is_file($cache)) {
        $c = json_decode((string)file_get_contents($cache), true);
        if (is_array($c) && (int)($c['mtime'] ?? 0) === (int)$mtime && !empty($c['sha256'])) {
            return (string)$c['sha256'];
        }
    }
    $h = hash_file('sha256', $exe_path);
    if ($h) @file_put_contents($cache, json_encode(['mtime' => $mtime, 'sha256' => $h]));
    return $h ?: null;
}

/**
 * Manifesto de versão do agent (fonte da verdade: downloads/agent-version.json).
 * Retorna sempre um array com defaults seguros mesmo se o arquivo não existir.
 */
function agent_manifest(): array {
    $file = __DIR__ . '/../downloads/agent-version.json';
    $mf = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    return [
        'versao'      => (string)($mf['versao'] ?? '1.0.0'),
        'obrigatoria' => (bool)($mf['obrigatoria'] ?? false),
        'notas'       => (string)($mf['notas'] ?? ''),
    ];
}

function get_config(int $empresa_id, string $chave, $default = null) {
    static $cache = [];
    if (!isset($cache[$empresa_id])) {
        $cache[$empresa_id] = [];
        $stmt = db()->prepare("SELECT chave, valor FROM dot_config WHERE empresa_id = ?");
        $stmt->execute([$empresa_id]);
        foreach ($stmt->fetchAll() as $r) $cache[$empresa_id][$r['chave']] = $r['valor'];
    }
    return $cache[$empresa_id][$chave] ?? $default;
}

/**
 * Valida os dígitos verificadores de um CNPJ (garantia local, sem rede).
 * Rejeita formato errado e sequências repetidas (00000000000000 etc.).
 */
function cnpj_valido(string $cnpj): bool {
    $cnpj = preg_replace('/\D/', '', $cnpj);
    if (strlen($cnpj) !== 14) return false;
    if (preg_match('/^(\d)\1{13}$/', $cnpj)) return false; // todos os dígitos iguais
    $dv = function (string $base): int {
        $pesos = strlen($base) === 12
            ? [5,4,3,2,9,8,7,6,5,4,3,2]
            : [6,5,4,3,2,9,8,7,6,5,4,3,2];
        $soma = 0;
        for ($i = 0, $n = strlen($base); $i < $n; $i++) $soma += (int)$base[$i] * $pesos[$i];
        $resto = $soma % 11;
        return $resto < 2 ? 0 : 11 - $resto;
    };
    $d1 = $dv(substr($cnpj, 0, 12));
    $d2 = $dv(substr($cnpj, 0, 12) . $d1);
    return (int)$cnpj[12] === $d1 && (int)$cnpj[13] === $d2;
}

/**
 * Confirma a existência do CNPJ na Receita Federal via BrasilAPI (best-effort).
 * Retorna: 'ok' | 'inexistente' | 'baixado' | 'indisponivel'.
 * Em 'indisponivel' (API fora/timeout) o chamador deve DEIXAR PASSAR — nunca
 * bloquear cadastro por indisponibilidade de serviço externo.
 */
function cnpj_consulta_receita(string $cnpj): string {
    $cnpj = preg_replace('/\D/', '', $cnpj);
    if (strlen($cnpj) !== 14) return 'inexistente';
    $url = "https://brasilapi.com.br/api/cnpj/v1/{$cnpj}";
    $ctx = stream_context_create(['http' => [
        'timeout' => 8, 'ignore_errors' => true, 'user_agent' => 'DOT-ON/1.0',
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false || $resp === '') return 'indisponivel';

    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    $data = json_decode($resp, true);
    if ($code === 404) return 'inexistente';
    if (!is_array($data)) return 'indisponivel';
    if (($data['type'] ?? '') === 'service_error') return 'indisponivel';
    if (!empty($data['message']) && empty($data['razao_social'])) {
        // BrasilAPI devolve {message: "CNPJ ... não encontrado"} para inexistentes
        return stripos($data['message'], 'não') !== false || stripos($data['message'], 'nao') !== false
            ? 'inexistente' : 'indisponivel';
    }
    $sit = mb_strtoupper((string)($data['descricao_situacao_cadastral'] ?? ''));
    if ($sit === 'BAIXADA' || $sit === 'BAIXADO') return 'baixado';
    if (!empty($data['razao_social']) || $sit !== '') return 'ok';
    return 'indisponivel';
}
