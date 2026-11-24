<?php
/**
 * Folha de ponto por link (sem login) — destino dos e-mails do envio automático.
 * Mostra o período gravado no envio, de todos os funcionários da empresa,
 * SEM CPF e SEM PIS. Link inválido, vencido ou cancelado → página genérica.
 */
require_once __DIR__ . '/includes/folha_envio.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=utf-8');

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$envio = null; $funcs = [];
try {
    $token = $_GET['t'] ?? '';
    $envio = is_string($token) ? folha_envio_por_token($token) : null;
    if ($envio) {
        $funcs = folha_dados((int)$envio['empresa_id'], $envio['inicio'], $envio['fim']);
        folha_registrar_acesso((int)$envio['id']);
    }
} catch (Throwable $e) {
    error_log('DOT-ON folha.php: ' . $e->getMessage());
    $envio = null;
}

if (!$envio) {
    http_response_code(404);
    ?><!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Link indisponível · DOT-ON</title>
<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f1f5f9;color:#1e293b;margin:0;padding:40px 16px}
.cx{max-width:480px;margin:60px auto;background:#fff;border-radius:14px;padding:32px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.08)}
h1{font-size:20px;margin:0 0 10px}p{color:#64748b;line-height:1.5;margin:0}</style></head><body>
<div class="cx"><h1>Link indisponível</h1>
<p>Este link não existe, venceu ou foi cancelado. Peça à empresa um novo envio da folha de ponto.</p></div>
</body></html><?php
    exit;
}

$emp      = $envio['empresa'];
$periodo  = folha_fmt_data($envio['inicio']) . ' a ' . folha_fmt_data($envio['fim']);
$dias_sem = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
$tot = ['dias' => 0, 'trab' => 0, 'extra' => 0, 'faltas' => 0];
foreach ($funcs as $f) {
    $tot['dias'] += $f['dias_trabalhados']; $tot['trab'] += $f['min_trabalhados'];
    $tot['extra'] += $f['min_extras'];      $tot['faltas'] += $f['faltas'];
}
?>
<!DOCTYPE html><html lang="pt-BR"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Folha de ponto · <?= $h(folha_nome_empresa($emp)) ?> · <?= $h($periodo) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,Helvetica,sans-serif;color:#222;padding:20px;font-size:11pt;background:#fff;max-width:1000px;margin:auto}
.barra{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px;padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:10pt}
.barra button{padding:10px 20px;background:#1d4ed8;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:10pt}
.cabec{border-bottom:2px solid #333;padding-bottom:10px;margin-bottom:16px}
.cabec h1{font-size:18pt;margin-bottom:4px}
.cabec .meta{font-size:9pt;color:#555}
.info{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;margin-bottom:16px;font-size:10pt}
h2{font-size:13pt;margin:0 0 10px}
.rol{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:9pt}
th,td{border:1px solid #999;padding:5px 7px;text-align:center}
th{background:#1e40af;color:#fff;font-weight:600}
tbody tr:nth-child(even){background:#f9fafb}
td.esq,th.esq{text-align:left}
tfoot td{font-weight:700;background:#e5e7eb}
.tot{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.tot div{flex:1;min-width:110px;background:#f3f4f6;padding:8px;border-radius:4px;text-align:center;font-size:9pt}
.tot strong{display:block;font-size:13pt;color:#1e40af}
.folha{margin-top:34px;padding-top:20px;border-top:2px dashed #cbd5e1}
.assinatura{margin-top:40px;display:flex;justify-content:space-around;font-size:9pt}
.assinatura div{text-align:center;width:40%}
.assinatura div p{border-top:1px solid #333;padding-top:6px;margin-top:40px}
.rodape{margin-top:20px;font-size:8pt;color:#777;text-align:center}
.neg{color:#b91c1c}.pos{color:#15803d}
a{color:#1d4ed8}
@media print{a{color:inherit;text-decoration:none}}
@media print{
    .no-print{display:none !important}
    body{padding:0;max-width:none}
    .folha{page-break-before:always;break-before:page;border-top:none;margin-top:0;padding-top:0}
    .rol{overflow:visible}
    th{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style></head><body>

<div class="barra no-print">
    <button type="button" onclick="window.print()">Imprimir todas / Salvar PDF</button>
    <span>Link pessoal de <strong><?= $h($envio['email']) ?></strong>, válido até <?= $h(folha_fmt_data($envio['expira_em'])) ?>. Não compartilhe.</span>
</div>

<div class="cabec">
    <h1>Folha de Ponto — Resumo do período</h1>
    <div class="meta">Período: <?= $h($periodo) ?></div>
</div>
<div class="info">
    <div><b>Empresa:</b> <?= $h($emp['razao_social']) ?></div>
    <div><b>CNPJ:</b> <?= $h($emp['cnpj'] ?? '—') ?></div>
    <div><b>Funcionários:</b> <?= count($funcs) ?></div>
    <div><b>Emitido em:</b> <?= $h(date('d/m/Y H:i', strtotime(folha_agora()))) ?></div>
</div>

<div class="rol"><table>
    <thead><tr>
        <th class="esq">Funcionário</th><th>Matrícula</th><th class="esq">Função</th>
        <th>Dias trabalhados</th><th>Horas trabalhadas</th><th>Horas extras</th>
        <th>Faltas</th><th>Faltas abonadas</th><th>Saldo do período</th>
    </tr></thead>
    <tbody>
    <?php foreach ($funcs as $f): ?>
        <tr>
            <td class="esq"><a href="#f<?= (int)$f['id'] ?>"><?= $h($f['nome']) ?></a><?= !$f['ativo'] ? ' <small>(desligado)</small>' : '' ?></td>
            <td><?= $h($f['matricula'] ?: '—') ?></td>
            <td class="esq"><?= $h($f['funcao'] ?: '—') ?></td>
            <td><?= (int)$f['dias_trabalhados'] ?></td>
            <td><?= fmt_minutos($f['min_trabalhados']) ?></td>
            <td><?= fmt_minutos($f['min_extras']) ?></td>
            <td><?= (int)$f['faltas'] ?></td>
            <td><?= (int)$f['faltas_abonadas'] ?></td>
            <td class="<?= $f['saldo'] < 0 ? 'neg' : 'pos' ?>"><?= fmt_minutos($f['saldo']) ?></td>
        </tr>
    <?php endforeach; if (!$funcs): ?>
        <tr><td colspan="9">Nenhum funcionário com folha neste período.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($funcs): ?>
    <tfoot><tr>
        <td class="esq" colspan="3">Total</td>
        <td><?= (int)$tot['dias'] ?></td><td><?= fmt_minutos($tot['trab']) ?></td><td><?= fmt_minutos($tot['extra']) ?></td>
        <td><?= (int)$tot['faltas'] ?></td><td></td><td></td>
    </tr></tfoot>
    <?php endif; ?>
</table></div>

<?php foreach ($funcs as $f): ?>
<section class="folha" id="f<?= (int)$f['id'] ?>">
    <div class="cabec">
        <h1>Espelho de Ponto Eletrônico</h1>
        <div class="meta">Período: <?= $h($periodo) ?></div>
    </div>
    <div class="info">
        <div><b>Empresa:</b> <?= $h($emp['razao_social']) ?></div>
        <div><b>CNPJ:</b> <?= $h($emp['cnpj'] ?? '—') ?></div>
        <div><b>Funcionário:</b> <?= $h($f['nome']) ?></div>
        <div><b>Matrícula:</b> <?= $h($f['matricula'] ?: '—') ?></div>
        <div><b>Função:</b> <?= $h($f['funcao'] ?: '—') ?></div>
        <div><b>Escala:</b> <?= $h($f['escala'] ?: '—') ?></div>
    </div>
    <div class="tot">
        <div><strong><?= (int)$f['dias_trabalhados'] ?></strong>Dias trabalhados</div>
        <div><strong><?= fmt_minutos($f['min_trabalhados']) ?></strong>Horas trabalhadas</div>
        <div><strong><?= fmt_minutos($f['min_extras']) ?></strong>Horas extras</div>
        <div><strong><?= (int)$f['faltas'] ?></strong>Faltas</div>
        <div><strong class="<?= $f['saldo'] < 0 ? 'neg' : 'pos' ?>"><?= fmt_minutos($f['saldo']) ?></strong>Saldo do período</div>
    </div>
    <div class="rol"><table>
        <thead><tr>
            <th>Data</th><th>Dia</th><th>Entrada</th><th>Saída Int.</th><th>Retorno</th><th>Saída</th>
            <th>Trab.</th><th>Extra</th><th>Saldo</th>
        </tr></thead>
        <tbody>
        <?php foreach ($f['linhas'] as $data_ref => $ln):
            $s  = $ln['sessao'] ?? null;
            $sd = $ln['ap'] ?? null;
            $dia = $dias_sem[(int)date('w', strtotime($data_ref))];
            if ($s):
                $bt = $f['batidas'][$data_ref] ?? [];
                $hora = function (string $tipo) use ($bt) {
                    foreach ($bt as $x) if ($x['tipo'] === $tipo) return date('H:i', strtotime($x['momento']));
                    return '—';
                };
        ?>
            <tr>
                <td><?= $h(folha_fmt_data($data_ref)) ?></td><td><?= $dia ?></td>
                <td><?= $hora('entrada') ?></td><td><?= $hora('saida_intervalo') ?></td>
                <td><?= $hora('retorno_intervalo') ?></td><td><?= $hora('saida') ?></td>
                <td><?= fmt_minutos((int)$s['minutos_trabalhados']) ?></td>
                <td><?= fmt_minutos((int)$s['minutos_extras']) ?></td>
                <td class="<?= $sd && $sd['saldo'] < 0 ? 'neg' : 'pos' ?>"><?= $sd ? fmt_minutos((int)$sd['saldo']) : '—' ?></td>
            </tr>
        <?php elseif ($sd): // falta, falta abonada ou feriado ?>
            <tr>
                <td><?= $h(folha_fmt_data($data_ref)) ?></td><td><?= $dia ?></td>
                <td colspan="4" style="color:#666"><?= $h($sd['situacao']) ?></td>
                <td><?= fmt_minutos((int)$sd['minutos_trabalhados']) ?></td>
                <td>—</td>
                <td class="<?= $sd['saldo'] < 0 ? 'neg' : 'pos' ?>"><?= fmt_minutos((int)$sd['saldo']) ?></td>
            </tr>
        <?php endif; endforeach; if (!$f['linhas']): ?>
            <tr><td colspan="9">Sem registros no período</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
    <div class="assinatura">
        <div><p>Assinatura do Funcionário</p></div>
        <div><p>Assinatura da Empresa</p></div>
    </div>
</section>
<?php endforeach; ?>

<p class="rodape">Documento gerado eletronicamente em <?= $h(date('d/m/Y H:i:s', strtotime(folha_agora()))) ?> · DOT-ON</p>
</body></html>
