<?php
/**
 * Envio automático da folha de ponto — configuração do gestor.
 * E-mails que recebem, frequência, "Enviar agora" e histórico dos links.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/folha_envio.php';

// As ações rodam antes do layout para poder redirecionar (evita reenvio ao
// recarregar a página). O layout repete o controle de acesso e o bloqueio
// por faturamento; aqui, empresa bloqueada não executa ação nenhuma.
$u_acao = requer_painel();
$empresa_id_acao = (int)$u_acao['empresa_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $flash = ['tipo' => 'ok', 'msg' => ''];
    try {
        $acao = (string)($_POST['acao'] ?? '');
        // Empresa bloqueada no faturamento não configura nem envia, mas pode
        // sempre cancelar um link e remover um e-mail.
        if (!in_array($acao, ['cancelar', 'del_dest'], true) && !folha_empresa_liberada($empresa_id_acao)) {
            throw new InvalidArgumentException('Regularize a assinatura para usar o envio automático.');
        }
        $uid = (int)$u_acao['id'];
        // Os envios falam com o servidor de e-mail e podem demorar: libera a
        // sessão para não travar as outras abas do usuário enquanto isso.
        if (in_array($acao, ['enviar_agora', 'reenviar'], true)) session_write_close();
        switch ($acao) {
            case 'salvar':
                folha_salvar_config($empresa_id_acao, !empty($_POST['ativo']), (string)($_POST['frequencia'] ?? ''),
                    $_POST['dias'] ?? [], $_POST['dia_semana'] ?? 1, $uid);
                $flash['msg'] = 'Configuração salva.';
                break;
            case 'add_dest':
                folha_adicionar_destinatario($empresa_id_acao, (string)($_POST['email'] ?? ''), (string)($_POST['nome'] ?? ''), $uid);
                $flash['msg'] = 'E-mail adicionado.';
                break;
            case 'del_dest':
                folha_remover_destinatario($empresa_id_acao, (int)($_POST['id'] ?? 0), $uid);
                $flash['msg'] = 'E-mail removido. Os links que ele já recebeu continuam valendo até você cancelá-los no histórico.';
                break;
            case 'enviar_agora':
                $r = folha_enviar_agora($empresa_id_acao, $uid);
                $periodo = folha_fmt_data($r['inicio']) . ' a ' . folha_fmt_data($r['fim']);
                if ($r['falhas'] > 0) {
                    $flash = ['tipo' => 'erro', 'msg' => "Período $periodo: {$r['enviados']} e-mail(s) enviado(s) e {$r['falhas']} com falha. Veja o histórico abaixo."];
                } else {
                    $flash['msg'] = "Folha de $periodo enviada para {$r['enviados']} e-mail(s).";
                }
                break;
            case 'cancelar':
                $flash['msg'] = folha_cancelar_envio($empresa_id_acao, (int)($_POST['id'] ?? 0), $uid)
                    ? 'Link cancelado. Ele não abre mais.' : 'Este link já estava cancelado.';
                break;
            case 'reenviar':
                $r = folha_reenviar($empresa_id_acao, (int)($_POST['id'] ?? 0), $uid);
                $flash = $r['ok']
                    ? ['tipo' => 'ok', 'msg' => 'Novo link enviado. O link anterior deixou de valer.']
                    : ['tipo' => 'erro', 'msg' => 'Não foi possível enviar o e-mail agora. O link anterior continua valendo. Tente novamente em alguns minutos.'];
                break;
            default:
                $flash = ['tipo' => 'erro', 'msg' => 'Ação inválida.'];
        }
    } catch (InvalidArgumentException $e) {
        $flash = ['tipo' => 'erro', 'msg' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('DOT-ON folha_envio empresa ' . $empresa_id_acao . ': ' . $e->getMessage());
        $flash = ['tipo' => 'erro', 'msg' => 'Não foi possível concluir agora. Tente novamente em alguns minutos.'];
    }
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['folha_flash'] = $flash;
    header('Location: folha_envio.php');
    exit;
}

$titulo = 'Envio automático da folha'; $pagina = 'folha_envio';
require __DIR__ . '/_layout.php';

$flash = $_SESSION['folha_flash'] ?? null;
unset($_SESSION['folha_flash']);

$empresa_id = (int)$user['empresa_id'];
$cfg        = folha_config($empresa_id);
$dests      = folha_destinatarios($empresa_id);
$historico  = folha_historico($empresa_id, 50);
$dias_sel   = folha_normalizar_dias($cfg['dias']);
$hoje       = folha_hoje();
$agora      = folha_agora();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Próximo envio (até 62 dias à frente) — só para mostrar ao gestor.
$proximo = null;
if ($cfg['ativo'] && $dests) {
    // Se o envio agendado de hoje já saiu, o próximo é a partir de amanhã.
    $st = db()->prepare("SELECT COUNT(*) FROM dot_folha_envios WHERE empresa_id = ? AND origem = 'agendado' AND data_envio = ?");
    $st->execute([$empresa_id, $hoje]);
    for ($i = ((int)$st->fetchColumn() > 0 ? 1 : 0); $i <= 62; $i++) {
        $d = folha_mais_dias($hoje, $i);
        if (folha_devido($cfg, $d)) { $proximo = $d; break; }
    }
}
[$p_ini, $p_fim] = folha_periodo($cfg['frequencia'], $hoje);
$smtp_ok = (bool)smtp_config();
?>
<style>
.fe-dias{display:grid;grid-template-columns:repeat(auto-fill,minmax(46px,1fr));gap:6px;max-width:560px}
.fe-dias label{display:block;position:relative;cursor:pointer;user-select:none}
.fe-dias input{position:absolute;opacity:0;width:100%;height:100%;left:0;top:0;margin:0;cursor:pointer}
.fe-dias span{display:block;text-align:center;border:1px solid var(--border);border-radius:8px;padding:7px 0;font-size:14px}
.fe-dias input:checked+span{background:var(--primary);color:#fff;border-color:var(--primary);font-weight:700}
.fe-dias input:focus-visible+span{outline:2px solid var(--primary);outline-offset:2px}
.fe-freq{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:16px}
.fe-freq label{border:1px solid var(--border);border-radius:10px;padding:12px;cursor:pointer;display:block}
.fe-freq small{display:block;color:var(--muted);margin-top:4px;font-size:12px;line-height:1.35}
.fe-in{padding:9px 11px;border:1px solid var(--border);border-radius:8px;font-size:14px;background:var(--card);color:var(--text)}
.fe-nota{color:var(--muted);font-size:13px;line-height:1.5}
.fe-tag{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
.fe-tag.ok{background:#dcfce7;color:#166534}.fe-tag.erro{background:#fee2e2;color:#991b1b}
.fe-tag.neutro{background:var(--subtle);color:var(--muted)}.fe-tag.info{background:#dbeafe;color:#1e40af}
.fe-acoes{display:flex;gap:6px;flex-wrap:wrap}
.fe-acoes form{margin:0}
.fe-scroll{overflow-x:auto}
</style>

<?php if ($flash && $flash['msg'] !== ''): ?>
<div class="alert <?= $flash['tipo'] === 'ok' ? 'alert-ok' : 'alert-erro' ?>"><?= $h($flash['msg']) ?></div>
<?php endif; ?>
<?php if (!$smtp_ok): ?>
<div class="alert alert-erro">O envio de e-mails do sistema não está configurado. Fale com o suporte antes de ativar o envio automático.</div>
<?php endif; ?>

<div class="panel">
    <h2><?= icon('mail', 18) ?>Quem recebe</h2>
    <p class="fe-nota" style="margin-bottom:14px">
        Contador, RH, contas a pagar ou você mesmo. Cada pessoa recebe um e-mail com um link para a folha de ponto
        de todos os funcionários. O e-mail não leva dados de funcionários, e a folha do link não mostra CPF nem PIS.
    </p>
    <?php if ($dests): ?>
    <div class="fe-scroll"><table class="tbl" style="margin-bottom:16px">
        <thead><tr><th>E-mail</th><th>Nome</th><th>Desde</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($dests as $d): ?>
            <tr>
                <td><?= $h($d['email']) ?></td>
                <td><?= $h($d['nome'] ?: '—') ?></td>
                <td><?= $h(folha_fmt_data($d['criado_em'])) ?></td>
                <td style="text-align:right">
                    <form method="post" onsubmit="return confirm('Remover este e-mail da lista?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="del_dest">
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                        <button class="btn btn-secondary btn-sm"><?= icon('trash', 14) ?>Remover</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p class="fe-nota" style="margin-bottom:14px"><strong>Nenhum e-mail cadastrado ainda.</strong></p>
    <?php endif; ?>

    <?php if (count($dests) < FOLHA_MAX_DESTINATARIOS): ?>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="add_dest">
        <label class="fe-nota">E-mail<br><input class="fe-in" type="email" name="email" required maxlength="190" placeholder="contador@exemplo.com.br" style="min-width:260px"></label>
        <label class="fe-nota">Nome (opcional)<br><input class="fe-in" type="text" name="nome" maxlength="120" placeholder="Ex.: Contador"></label>
        <button class="btn btn-primary"><?= icon('plus', 16) ?>Adicionar</button>
    </form>
    <?php else: ?>
    <p class="fe-nota">Limite de <?= FOLHA_MAX_DESTINATARIOS ?> e-mails atingido.</p>
    <?php endif; ?>
</div>

<div class="panel">
    <h2><?= icon('clock', 18) ?>Quando enviar</h2>
    <form method="post" id="fe-form">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar">

        <div class="fe-freq">
            <label><input type="radio" name="frequencia" value="dias_mes" <?= $cfg['frequencia'] === 'dias_mes' ? 'checked' : '' ?>> <strong>Dias do mês</strong>
                <small>Nos dias que você marcar (ex.: 15 e 30). Folha do dia 1 até a véspera do envio.</small></label>
            <label><input type="radio" name="frequencia" value="semanal" <?= $cfg['frequencia'] === 'semanal' ? 'checked' : '' ?>> <strong>Semanal</strong>
                <small>Uma vez por semana. Folha dos 7 dias anteriores.</small></label>
            <label><input type="radio" name="frequencia" value="quinzenal" <?= $cfg['frequencia'] === 'quinzenal' ? 'checked' : '' ?>> <strong>Quinzenal</strong>
                <small>Dias 1 e 16. Folha da quinzena que fechou.</small></label>
            <label><input type="radio" name="frequencia" value="diario" <?= $cfg['frequencia'] === 'diario' ? 'checked' : '' ?>> <strong>Diário</strong>
                <small>Todo dia. Folha acumulada do mês, do dia 1 até a véspera.</small></label>
        </div>

        <div data-freq="dias_mes" style="margin-bottom:16px">
            <p class="fe-nota" style="margin-bottom:8px">Marque os dias do envio. Em mês que não tem o dia marcado (29, 30 ou 31), o envio sai no último dia.</p>
            <div class="fe-dias">
                <?php for ($d = 1; $d <= 31; $d++): ?>
                <label><input type="checkbox" name="dias[]" value="<?= $d ?>" <?= in_array($d, $dias_sel, true) ? 'checked' : '' ?>><span><?= $d ?></span></label>
                <?php endfor; ?>
            </div>
        </div>

        <div data-freq="semanal" style="margin-bottom:16px">
            <label class="fe-nota">Dia da semana<br>
                <select class="fe-in" name="dia_semana">
                    <?php foreach (FOLHA_DIAS_SEMANA as $i => $nome): ?>
                    <option value="<?= $i ?>" <?= $cfg['dia_semana'] === $i ? 'selected' : '' ?>><?= $h($nome) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <label style="display:flex;align-items:center;gap:10px;margin:6px 0 16px;cursor:pointer">
            <input type="checkbox" name="ativo" value="1" <?= $cfg['ativo'] ? 'checked' : '' ?> style="width:18px;height:18px">
            <span><strong>Envio automático ligado</strong></span>
        </label>

        <button class="btn btn-primary"><?= icon('save', 16) ?>Salvar</button>
    </form>

    <p class="fe-nota" style="margin-top:16px">
        <?php if (!$cfg['ativo']): ?>
            O envio automático está <strong>desligado</strong>.
        <?php elseif (!$dests): ?>
            Envio ligado, mas <strong>sem e-mails cadastrados</strong> — nada será enviado.
        <?php elseif ($proximo): ?>
            Próximo envio: <strong><?= $h(folha_fmt_data($proximo)) ?></strong><?= $proximo === $hoje ? ' (hoje, na próxima rodada de envios)' : ', pela manhã' ?>.
        <?php endif; ?>
        O dia do envio não entra na folha, porque ainda não terminou.
    </p>
</div>

<div class="panel">
    <h2><?= icon('send', 18) ?>Enviar agora</h2>
    <p class="fe-nota" style="margin-bottom:14px">
        Manda já para todos os e-mails da lista, sem esperar a agenda. Período de hoje:
        <strong><?= $h(folha_fmt_data($p_ini)) ?> a <?= $h(folha_fmt_data($p_fim)) ?></strong>.
    </p>
    <form method="post" onsubmit="return confirm('Enviar a folha de ponto agora para <?= count($dests) ?> e-mail(s)?')">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="enviar_agora">
        <button class="btn btn-success" <?= (!$dests || !$smtp_ok) ? 'disabled' : '' ?>><?= icon('send', 16) ?>Enviar agora</button>
    </form>
</div>

<div class="panel">
    <h2><?= icon('inbox', 18) ?>Histórico de envios</h2>
    <p class="fe-nota" style="margin-bottom:14px">
        Cada link vale <?= FOLHA_VALIDADE_DIAS ?> dias e é só de quem recebeu. "Aberto" pode aparecer antes de a pessoa ler,
        porque alguns programas de e-mail abrem os links sozinhos para verificá-los.
    </p>
    <div class="fe-scroll"><table class="tbl">
        <thead><tr><th>Enviado em</th><th>Para</th><th>Período</th><th>Tipo</th><th>Situação</th><th>Aberto</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($historico as $v):
            $cancelado = !empty($v['cancelado_em']);
            $vencido   = !$cancelado && $v['expira_em'] < $agora;
            $falhou    = !(int)$v['email_enviado'];
            if ($cancelado)     { $tag = ['neutro', 'Cancelado']; }
            elseif ($falhou)    { $tag = ['erro', 'E-mail não enviado']; }
            elseif ($vencido)   { $tag = ['neutro', 'Vencido']; }
            else                { $tag = ['ok', 'Válido até ' . folha_fmt_data($v['expira_em'])]; }
        ?>
            <tr>
                <td style="white-space:nowrap"><?= $h(date('d/m/Y H:i', strtotime($v['criado_em']))) ?></td>
                <td><?= $h($v['email']) ?><?= $v['nome'] ? '<br><small class="fe-nota">' . $h($v['nome']) . '</small>' : '' ?></td>
                <td style="white-space:nowrap"><?= $h(date('d/m', strtotime($v['inicio']))) ?> a <?= $h(folha_fmt_data($v['fim'])) ?></td>
                <td><span class="fe-tag <?= $v['origem'] === 'manual' ? 'info' : 'neutro' ?>"><?= $v['origem'] === 'manual' ? 'Manual' : 'Agendado' ?></span></td>
                <td><span class="fe-tag <?= $tag[0] ?>"><?= $h($tag[1]) ?></span></td>
                <td><?= $v['aberto_em']
                        ? $h(date('d/m/Y H:i', strtotime($v['ultimo_acesso'] ?: $v['aberto_em']))) . '<br><small class="fe-nota">' . (int)$v['acessos'] . ' vez(es)</small>'
                        : '<span class="fe-nota">Ainda não</span>' ?></td>
                <td>
                    <div class="fe-acoes">
                    <?php if (!$cancelado): ?>
                        <form method="post" onsubmit="return confirm('Enviar um link novo para este e-mail? O link atual deixa de valer.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="reenviar">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <button class="btn btn-secondary btn-sm">Reenviar</button>
                        </form>
                        <?php if (!$vencido): ?>
                        <form method="post" onsubmit="return confirm('Cancelar este link? Ele deixa de abrir na hora.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="cancelar">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <button class="btn btn-secondary btn-sm">Cancelar link</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; if (!$historico): ?>
            <tr><td colspan="7" class="empty">Nenhum envio ainda.</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
</div>

<script>
(function(){
    var form = document.getElementById('fe-form');
    if (!form) return;
    function sync(){
        var f = (form.querySelector('input[name=frequencia]:checked') || {}).value;
        form.querySelectorAll('[data-freq]').forEach(function(el){
            el.style.display = el.getAttribute('data-freq') === f ? '' : 'none';
        });
    }
    form.querySelectorAll('input[name=frequencia]').forEach(function(r){ r.addEventListener('change', sync); });
    sync();
})();
</script>

</main></body></html>
