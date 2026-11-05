<?php
/**
 * Redirecionador: /me/login.php → /admin/login.php (login único do sistema)
 *
 * O back precisa ser relativo a /app/admin/: o login descarta caminhos
 * absolutos (proteção contra open redirect), e um back "/app/me/" virava
 * "index.php" — mandando quem entrou pelo portal para o painel.
 */
$qs = http_build_query(['back' => '../me/']);
header("Location: ../admin/login.php?$qs");
exit;
