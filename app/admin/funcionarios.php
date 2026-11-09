<?php
$titulo = 'Funcionários'; $pagina = 'funcionarios';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/mailer.php';

$emp_id = $user['empresa_id'];
$msg = ''; $erro = '';

// Helper p/ senha temporária
function gerar_senha_temp_admin($len = 10) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i=0; $i<$len; $i++) $s .= $chars[random_int(0, strlen($chars)-1)];
    return $s;
}

/** Gestor/RH só gerenciam funcionários comuns; admin gerencia todos. */
function pode_gerir_alvo(array $ator, array $alvo): bool {
    return $ator['perfil'] === 'admin' || $alvo['perfil'] === 'funcionario';
}

function buscar_funcionario(int $uid, int $emp_id) {
    $st = db()->prepare("SELECT * FROM dot_usuarios WHERE id=? AND empresa_id=?");
    $st->execute([$uid, $emp_id]);
    return $st->fetch() ?: null;
}

/**
 * Apaga o usuário e todos os registros ligados a ele (batidas, comprovantes,
 * sessões, ociosidade, horas extras, justificativas + anexos, jornada).
 * A trilha de auditoria (dot_auditoria) é preservada de propósito.
 * Retorna a contagem de linhas removidas por tabela.
 */
function excluir_funcionario_permanente(int $uid, int $emp_id): array {
    $pdo = db();
    // Ordem: filhos antes dos pais (crp→batidas, ociosidade/extras→sessões).
    $tabelas = ['dot_crp', 'dot_ociosidade', 'dot_horas_extras', 'dot_justificativas',
                'dot_batidas', 'dot_sessoes', 'dot_usuario_jornada'];
    // Algumas tabelas são criadas sob demanda; só mexe nas que existem.
    $in = implode(',', array_fill(0, count($tabelas), '?'));
    $st = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)");
    $st->execute($tabelas);
    $existentes = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));

    $anexos = [];
    if (in_array('dot_justificativas', $existentes, true)) {
        $st = $pdo->prepare("SELECT anexo_arquivo FROM dot_justificativas
                             WHERE usuario_id=? AND anexo_arquivo IS NOT NULL AND anexo_arquivo <> ''");
        $st->execute([$uid]);
        $anexos = $st->fetchAll(PDO::FETCH_COLUMN);
    }

    $removidos = [];
    $pdo->beginTransaction();
    try {
        foreach ($tabelas as $t) {
            if (!in_array($t, $existentes, true)) continue;
            $st = $pdo->prepare("DELETE FROM $t WHERE usuario_id = ?");
            $st->execute([$uid]);
            if ($st->rowCount()) $removidos[$t] = $st->rowCount();
        }
        $st = $pdo->prepare("DELETE FROM dot_usuarios WHERE id = ? AND empresa_id = ?");
        $st->execute([$uid, $emp_id]);
        if ($st->rowCount() !== 1) throw new RuntimeException("usuário #$uid não encontrado na empresa #$emp_id");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Arquivos só depois do commit: se o banco falhar, os comprovantes continuam lá.
    require_once __DIR__ . '/../includes/justificativas.php';
    foreach ($anexos as $arq) {
        @unlink(jus_dir_uploads() . '/' . basename($arq));
    }
    return $removidos;
}

$editar = null;

// AÇÕES POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($user['perfil'], ['admin','rh','gestor'])) {
    csrf_check();

    // Editar dados
    if (!empty($_POST['acao']) && $_POST['acao'] === 'editar') {
        $uid = (int)$_POST['user_id'];
        $alvo = buscar_funcionario($uid, $emp_id);
        if (!$alvo || !pode_gerir_alvo($user, $alvo)) {
            $erro = "Você não tem permissão para editar este usuário.";
        } else {
            $nome = trim($_POST['nome'] ?? '');
            // E-mail é o login (e destino do "reenviar credenciais"): só admin altera,
            // senão gestor/RH poderiam apontá-lo para si e assumir a conta.
            $email = $user['perfil'] === 'admin' ? strtolower(trim($_POST['email'] ?? '')) : strtolower($alvo['email']);
            $matricula = trim($_POST['matricula'] ?? '');
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            $perfis_permitidos = ($user['perfil'] === 'admin') ? ['funcionario','gestor','rh','admin'] : ['funcionario'];
            $perfil = in_array($_POST['perfil'] ?? '', $perfis_permitidos, true) ? $_POST['perfil'] : $alvo['perfil'];
            if ($uid === (int)$user['id']) $perfil = $alvo['perfil']; // ninguém altera o próprio perfil
            $escala_id = (int)($_POST['escala_id'] ?? 0);

            $st = db()->prepare("SELECT id FROM dot_usuarios WHERE LOWER(email)=? AND id<>?");
            $st->execute([$email, $uid]);
            $email_em_uso = (bool)$st->fetch();
            $st = db()->prepare("SELECT id FROM dot_escalas WHERE id=? AND empresa_id=?");
            $st->execute([$escala_id, $emp_id]);
            $escala_ok = (bool)$st->fetch();

            if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $erro = "Informe nome e um e-mail válido.";
            } elseif ($email_em_uso) {
                $erro = "Já existe outro usuário com este e-mail.";
            } elseif ($cpf !== '' && strlen($cpf) !== 11) {
                $erro = "CPF deve ter 11 dígitos.";
            } elseif ($matricula === '') {
                $erro = "A matrícula não pode ficar vazia.";
            } else {
                db()->prepare("UPDATE dot_usuarios SET nome_completo=?, email=?, matricula=?, cpf=?, perfil=?, escala_id=?
                               WHERE id=? AND empresa_id=?")
                    ->execute([$nome, $email, $matricula, $cpf, $perfil, $escala_ok ? $escala_id : $alvo['escala_id'], $uid, $emp_id]);
                $mudou = [];
                foreach (['nome_completo'=>$nome, 'email'=>$email, 'matricula'=>$matricula, 'perfil'=>$perfil] as $k => $v) {
                    if ((string)$alvo[$k] !== (string)$v) $mudou[$k] = ['de'=>$alvo[$k], 'para'=>$v];
                }
                if (strtolower($alvo['email']) !== $email) {
                    // Link de redefinição pendente foi para o endereço antigo: invalida.
                    db()->prepare("UPDATE dot_usuarios SET token_reset=NULL, token_reset_expira=NULL WHERE id=?")->execute([$uid]);
                    $assunto = 'DOT-ON: seu e-mail de acesso foi alterado';
                    $texto = "Olá, {$alvo['nome_completo']}.\n\nO e-mail de acesso da sua conta DOT-ON foi alterado de {$alvo['email']} para {$email} por {$user['nome_completo']}.\nSe você não reconhece essa alteração, procure o RH da sua empresa.";
                    try { email_enviar($alvo['email'], $alvo['nome_completo'], $assunto, nl2br(htmlspecialchars($texto)), $texto, $emp_id); }
                    catch (Throwable $e) { error_log("DOT-ON aviso troca de e-mail #$uid: " . $e->getMessage()); }
                }
                if ((string)$alvo['cpf'] !== $cpf) $mudou['cpf'] = 'alterado';
                if ($escala_ok && (int)$alvo['escala_id'] !== $escala_id) $mudou['escala_id'] = ['de'=>$alvo['escala_id'], 'para'=>$escala_id];
                auditar($user['id'], 'funcionario_editado', 'usuario', $uid, $mudou);
                $msg = "Dados de <strong>" . htmlspecialchars($nome) . "</strong> atualizados.";
            }
            if ($erro) $editar = array_merge($alvo, ['nome_completo'=>$nome, 'email'=>$email, 'matricula'=>$matricula, 'cpf'=>$cpf, 'perfil'=>$perfil, 'escala_id'=>$escala_id]);
        }
    }
    // Excluir permanentemente (somente admin)
    elseif (!empty($_POST['acao']) && $_POST['acao'] === 'excluir') {
        $uid = (int)$_POST['user_id'];
        $alvo = buscar_funcionario($uid, $emp_id);
        if ($user['perfil'] !== 'admin' || !$alvo) {
            $erro = "Somente o administrador pode excluir usuários.";
        } elseif ($uid === (int)$user['id']) {
            $erro = "Você não pode excluir o seu próprio usuário.";
        } elseif (strtolower(trim($_POST['confirmar_email'] ?? '')) !== strtolower($alvo['email'])) {
            $erro = "Confirmação não confere. Digite exatamente o e-mail do usuário para excluir.";
            $editar = $alvo;
        } else {
            try {
                $removidos = excluir_funcionario_permanente($uid, $emp_id);
                auditar($user['id'], 'funcionario_excluido', 'usuario', $uid, [
                    'nome' => $alvo['nome_completo'], 'email' => $alvo['email'],
                    'matricula' => $alvo['matricula'], 'perfil' => $alvo['perfil'],
                    'registros_removidos' => $removidos,
                ]);
                $msg = "Usuário <strong>" . htmlspecialchars($alvo['nome_completo']) . "</strong> excluído permanentemente.";
            } catch (Throwable $e) {
                error_log("DOT-ON funcionarios excluir #$uid: " . $e->getMessage());
                $erro = "Não foi possível excluir o usuário. Nada foi apagado.";
                $editar = $alvo;
            }
        }
    }
    // Reenviar credenciais
    elseif (!empty($_POST['acao']) && $_POST['acao'] === 'reenviar') {
        $uid = (int)$_POST['user_id'];
        $st = db()->prepare("SELECT * FROM dot_usuarios WHERE id=? AND empresa_id=?");
        $st->execute([$uid, $emp_id]);
        $u = $st->fetch();
        if ($u) {
            $senha_nova = gerar_senha_temp_admin();
            $hash = password_hash($senha_nova, PASSWORD_BCRYPT);
            db()->prepare("UPDATE dot_usuarios SET senha_hash=?, precisa_trocar_senha=1 WHERE id=?")->execute([$hash, $uid]);
            $url_exe = 'https://dot-on.com.br/app/downloads/DOT-ON-Agent.exe';
            $url_painel = 'https://dot-on.com.br/app/admin/login.php';
            [$assunto, $html, $texto] = email_template_boasvindas_funcionario($u['nome_completo'], $empresa['nome_fantasia'] ?: $empresa['razao_social'], $u['email'], $senha_nova, $url_exe, $url_painel);
            email_enviar($u['email'], $u['nome_completo'], $assunto, $html, $texto, $emp_id);
            $msg = "Nova senha enviada para <strong>" . htmlspecialchars($u['email']) . "</strong>";
        }
    }
    // Ativar/Desativar
    elseif (!empty($_POST['acao']) && in_array($_POST['acao'], ['ativar','desativar'])) {
        $uid = (int)$_POST['user_id'];
        $ativo = $_POST['acao'] === 'ativar' ? 1 : 0;
        db()->prepare("UPDATE dot_usuarios SET ativo=? WHERE id=? AND empresa_id=?")
            ->execute([$ativo, $uid, $emp_id]);
        $msg = $ativo ? "Funcionário ativado" : "Funcionário desativado";
    }
    // Criar novo
    elseif (!empty($_POST['nome']) && !empty($_POST['email'])) {
        $nome = trim($_POST['nome']);
        $email = strtolower(trim($_POST['email']));
        $matricula = trim($_POST['matricula'] ?? '');
        $cpf = preg_replace('/\D/','', $_POST['cpf'] ?? '');
        // Só admin pode criar perfis elevados; gestor/rh criam apenas funcionários.
        $perfis_permitidos = ($user['perfil'] === 'admin') ? ['funcionario','gestor','rh','admin'] : ['funcionario'];
        $perfil = in_array($_POST['perfil'] ?? '', $perfis_permitidos, true) ? $_POST['perfil'] : 'funcionario';
        $enviar_email = !empty($_POST['enviar_email']);

        // Verifica e-mail duplicado
        $st = db()->prepare("SELECT id FROM dot_usuarios WHERE email=?");
        $st->execute([$email]);
        if ($st->fetch()) {
            $erro = "Já existe um usuário com este e-mail.";
        } else {
            try {
                $senha_temp = gerar_senha_temp_admin();
                $hash = password_hash($senha_temp, PASSWORD_BCRYPT);
                $token = bin2hex(random_bytes(32));
                if (!$matricula) {
                    $st = db()->prepare("SELECT COUNT(*) FROM dot_usuarios WHERE empresa_id=?");
                    $st->execute([$emp_id]);
                    $matricula = sprintf('FUN%03d', $st->fetchColumn() + 1);
                }
                db()->prepare("INSERT INTO dot_usuarios (empresa_id, escala_id, matricula, nome_completo, email, cpf, senha_hash, perfil, api_token, precisa_trocar_senha, ativo, criado_em)
                    VALUES (?, (SELECT id FROM dot_escalas WHERE empresa_id=? LIMIT 1), ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())")
                    ->execute([$emp_id, $emp_id, $matricula, $nome, $email, $cpf, $hash, $perfil, $token]);

                if ($enviar_email) {
                    $url_exe = 'https://dot-on.com.br/app/downloads/DOT-ON-Agent.exe';
                    $url_painel = 'https://dot-on.com.br/app/admin/login.php';
                    [$assunto, $html, $texto] = email_template_boasvindas_funcionario($nome, $empresa['nome_fantasia'] ?: $empresa['razao_social'], $email, $senha_temp, $url_exe, $url_painel);
                    email_enviar($email, $nome, $assunto, $html, $texto, $emp_id);
                    $msg = "Funcionário criado e e-mail de boas-vindas enviado para <strong>$email</strong>";
                } else {
                    $msg = "Funcionário criado. Senha temporária: <code>$senha_temp</code> (anote ou copie agora)";
                }
            } catch (Throwable $e) {
                error_log("DOT-ON funcionarios criar: " . $e->getMessage());
                $erro = "Não foi possível criar o funcionário. Verifique os dados e tente novamente.";
            }
        }
    }
}

// LISTA
$stmt = db()->prepare("SELECT u.*, e.nome AS esc_nome
    FROM dot_usuarios u
    LEFT JOIN dot_escalas e ON e.id=u.escala_id
    WHERE u.empresa_id=?
    ORDER BY u.ativo DESC, u.nome_completo");
$stmt->execute([$emp_id]);
$lista = $stmt->fetchAll();

$pode_gerir = in_array($user['perfil'], ['admin','rh','gestor']);
if (!$editar && $pode_gerir && !empty($_GET['editar']) && !$msg) {
    $editar = buscar_funcionario((int)$_GET['editar'], $emp_id);
    if ($editar && !pode_gerir_alvo($user, $editar)) {
        $editar = null;
        $erro = "Você não tem permissão para editar este usuário.";
    }
}
$escalas = [];
if ($editar) {
    $st = db()->prepare("SELECT id, nome FROM dot_escalas WHERE empresa_id=? ORDER BY nome");
    $st->execute([$emp_id]);
    $escalas = $st->fetchAll();
    $st = db()->prepare("SELECT COUNT(*) FROM dot_batidas WHERE usuario_id=?");
    $st->execute([(int)$editar['id']]);
    $qtd_batidas = (int)$st->fetchColumn();
}
$perfis_rotulo = ['funcionario'=>'Funcionário', 'gestor'=>'Gestor', 'rh'=>'RH', 'admin'=>'Admin'];
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= $msg ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<?php if ($editar):
    $eh_proprio = (int)$editar['id'] === (int)$user['id'];
    $perfis_opcoes = $user['perfil'] === 'admin' ? array_keys($perfis_rotulo) : ['funcionario'];
?>
<div class="panel" id="editar">
    <h2><?= icon('edit', 18) ?>Editar: <?= htmlspecialchars($editar['nome_completo']) ?></h2>
    <form method="post" class="form-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="editar">
        <input type="hidden" name="user_id" value="<?= (int)$editar['id'] ?>">
        <label>Matrícula
            <input name="matricula" value="<?= htmlspecialchars($editar['matricula'] ?? '') ?>" required>
        </label>
        <label style="flex:2">Nome completo
            <input name="nome" value="<?= htmlspecialchars($editar['nome_completo']) ?>" required>
        </label>
        <label style="flex:2">E-mail (login)
            <input name="email" type="email" value="<?= htmlspecialchars($editar['email']) ?>" required
                <?= $user['perfil'] !== 'admin' ? 'readonly title="Somente o administrador altera o e-mail de login"' : '' ?>>
        </label>
        <label>CPF
            <input name="cpf" value="<?= htmlspecialchars($editar['cpf'] ?? '') ?>" maxlength="14">
        </label>
        <label>Perfil
            <select name="perfil" <?= $eh_proprio ? 'disabled title="Você não pode alterar o próprio perfil"' : '' ?>>
                <?php foreach ($perfis_opcoes as $p): ?>
                    <option value="<?= $p ?>" <?= $editar['perfil'] === $p ? 'selected' : '' ?>><?= $perfis_rotulo[$p] ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($escalas): ?>
        <label>Escala
            <select name="escala_id">
                <?php foreach ($escalas as $esc): ?>
                    <option value="<?= (int)$esc['id'] ?>" <?= (int)$editar['escala_id'] === (int)$esc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($esc['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <button class="btn btn-primary"><?= icon('save', 16) ?> Salvar</button>
        <a href="funcionarios.php" class="btn btn-secondary">Cancelar</a>
    </form>

    <?php if ($user['perfil'] === 'admin' && !$eh_proprio): ?>
    <div style="margin-top:8px;padding:16px;border:1px solid var(--danger);border-radius:12px">
        <h3 style="color:var(--danger);font-size:15px;margin-bottom:8px;display:flex;align-items:center;gap:6px"><?= icon('trash', 16) ?> Excluir permanentemente</h3>
        <p style="font-size:.85rem;color:var(--muted);margin-bottom:10px">
            Apaga o usuário <strong>e todo o histórico dele</strong>: batidas, comprovantes (CRP), espelho/banco de horas,
            horas extras, justificativas e anexos. <strong>Não pode ser desfeito</strong> e as batidas somem do AFD/AEJ.
            <?php if ($qtd_batidas): ?>
            <br><strong style="color:var(--danger)">Este usuário tem <?= $qtd_batidas ?> batida(s).</strong>
            Apagá-las quebra a cadeia de integridade (REP-P) da empresa: a Auditoria Fiscal passará a acusar
            divergência de hash nas batidas registradas depois delas, inclusive de outros funcionários.
            <?php endif; ?>
            <br>A Portaria 671 exige guardar os registros de ponto por 5 anos — para quem saiu da empresa, prefira
            <strong>Desativar</strong>, que bloqueia o acesso e mantém o histórico. Use a exclusão para cadastros
            feitos por engano ou duplicados.
        </p>
        <form method="post" class="form-inline" style="margin-bottom:0"
              onsubmit="return confirm('Excluir permanentemente este usuário e todo o histórico? Esta ação não pode ser desfeita.')">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="excluir">
            <input type="hidden" name="user_id" value="<?= (int)$editar['id'] ?>">
            <label style="flex:2">Para confirmar, digite o e-mail <code><?= htmlspecialchars($editar['email']) ?></code>
                <input name="confirmar_email" autocomplete="off" required>
            </label>
            <button class="btn btn-danger"><?= icon('trash', 16) ?> Excluir permanentemente</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (in_array($user['perfil'], ['admin','rh','gestor'])): ?>
<div class="panel">
    <h2><?= icon('plus', 18) ?>Adicionar funcionário</h2>
    <form method="post" class="form-inline">
        <?= csrf_field() ?>
        <input name="matricula" placeholder="Matrícula (opcional)">
        <input name="nome" placeholder="Nome completo" required style="flex:2">
        <input name="email" type="email" placeholder="E-mail" required style="flex:2">
        <input name="cpf" placeholder="CPF (opcional)" maxlength="14">
        <select name="perfil">
            <option value="funcionario">Funcionário</option>
            <option value="gestor">Gestor</option>
            <option value="rh">RH</option>
        </select>
        <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;color:#94a3b8">
            <input type="checkbox" name="enviar_email" value="1" checked>
            Enviar e-mail
        </label>
        <button class="btn btn-primary">Adicionar</button>
    </form>
    <p style="margin-top:10px;font-size:.85rem;color:#64748b;display:flex;align-items:center;gap:6px">
    <?= icon('lightbulb', 15) ?><span><strong>Importar em lote:</strong> Para adicionar vários funcionários de uma vez,
    <a href="funcionarios_importar.php">use o importador de planilha CSV/XLSX</a>.</span>
    </p>
</div>
<?php endif; ?>

<div class="panel">
    <h2><?= icon('funcionarios', 18) ?>Funcionários da <?= htmlspecialchars($empresa['nome_fantasia'] ?: $empresa['razao_social']) ?> (<?= count($lista) ?>)</h2>
    <table class="tbl">
        <thead><tr><th>Mat.</th><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th>Último login</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($lista as $u): ?>
            <tr style="<?= $u['ativo']?'':'opacity:.55' ?>">
                <td><code><?= htmlspecialchars($u['matricula']) ?></code></td>
                <td><?= htmlspecialchars($u['nome_completo']) ?>
                    <?php if ($u['precisa_trocar_senha']): ?><span class="tag" style="background:#fef3c7;color:#92400e;font-size:.7rem">1º login</span><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><span class="tag"><?= $u['perfil'] ?></span></td>
                <td><?= $u['ativo'] ? '<span style="color:#10b981">●</span> Ativo' : '<span style="color:#94a3b8">●</span> Inativo' ?></td>
                <td><?= $u['ultimo_login'] ? date('d/m H:i', strtotime($u['ultimo_login'])) : '—' ?></td>
                <td style="display:flex;gap:6px">
                    <?php if (in_array($user['perfil'], ['admin','rh','gestor'])): ?>
                    <?php if (pode_gerir_alvo($user, $u)): ?>
                    <a href="funcionarios.php?editar=<?= (int)$u['id'] ?>#editar" class="btn btn-secondary btn-sm" title="Editar<?= $user['perfil'] === 'admin' ? ' / excluir' : '' ?>"><?= icon('edit', 16) ?></a>
                    <?php endif; ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Gerar nova senha temporária e enviar e-mail?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="reenviar">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button class="btn btn-secondary btn-sm" title="Reenviar credenciais"><?= icon('send', 16) ?></button>
                    </form>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="<?= $u['ativo']?'desativar':'ativar' ?>">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button class="btn btn-secondary btn-sm" title="<?= $u['ativo']?'Desativar':'Ativar' ?>"><?= $u['ativo'] ? icon('power',16) : icon('check',16) ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; if (!$lista): ?>
            <tr><td colspan="7" class="empty">Nenhum funcionário cadastrado ainda. Use o formulário acima ou <a href="funcionarios_importar.php">importe uma planilha</a>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</main></body></html>
