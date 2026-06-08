<?php
// /app/extrato_banco.php — Extrato detalhado por banco (relatório imprimível)
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/conexao.php';

$bancoId = (int)($_GET['banco_id'] ?? 0);
$di      = trim((string)($_GET['di'] ?? ''));
$df      = trim((string)($_GET['df'] ?? ''));

if ($bancoId <= 0) {
    http_response_code(400);
    echo 'banco_id é obrigatório.';
    exit;
}

// Período padrão: últimos 90 dias
if ($di === '') $di = (new DateTimeImmutable('today'))->modify('-90 days')->format('Y-m-d');
if ($df === '') $df = (new DateTimeImmutable('today'))->format('Y-m-d');

// Banco
$stB = $pdo->prepare("SELECT BAN_ID, BAN_APELIDO, BAN_NOME, BAN_AGENCIA, BAN_AGENCIA_DV, BAN_CONTA, BAN_CONTA_DV, BAN_CODIGO FROM tb_banco WHERE BAN_ID = ? LIMIT 1");
$stB->execute([$bancoId]);
$banco = $stB->fetch(PDO::FETCH_ASSOC);
if (!$banco) {
    http_response_code(404);
    echo 'Banco não encontrado.';
    exit;
}

// Saldo anterior (último FCB anterior ao período)
$stSA = $pdo->prepare("
    SELECT FCB_SALDO_ATUAL
    FROM tb_fluxo_caixa_banco
    WHERE FCB_BANCO_FK = :b AND FCB_DATA < :d
    ORDER BY FCB_DATA DESC LIMIT 1
");
$stSA->execute([':b' => $bancoId, ':d' => $di]);
$saldoAnterior = (float)($stSA->fetchColumn() ?: 0);

// Movimentos OFX no período
$stOfx = $pdo->prepare("
    SELECT
        COM_CODIGO_PK    AS id,
        COM_DATA_MOVIMENTO AS data,
        COM_DESCRICAO    AS descricao,
        COM_DOCUMENTO    AS documento,
        COM_VALOR        AS valor,
        COM_TIPO         AS tipo,
        COM_CONCILIADO   AS conciliado,
        COM_REFERENCIA_TIPO AS ref_tipo,
        COM_REFERENCIA_FK   AS ref_fk,
        'OFX' AS origem
    FROM tb_conciliacao_ofx_movimento
    WHERE COM_BANCO_FK = :b
      AND COM_DATA_MOVIMENTO BETWEEN :di AND :df
    ORDER BY COM_DATA_MOVIMENTO ASC, COM_CODIGO_PK ASC
");
$stOfx->execute([':b' => $bancoId, ':di' => $di, ':df' => $df]);
$movsOfx = $stOfx->fetchAll(PDO::FETCH_ASSOC);

// Pagamentos (contas a pagar PAGAS) sem vínculo OFX no período (para não duplicar)
$stPag = $pdo->prepare("
    SELECT
        c.CPG_CODIGO_PK AS id,
        c.CPG_DATA_PAGAMENTO AS data,
        c.CPG_DESCRICAO AS descricao,
        c.CPG_DOCUMENTO AS documento,
        c.CPG_VALOR_PAGO AS valor,
        f.FOR_RAZAO_SOCIAL AS fornecedor,
        f.FOR_NOME_FANTASIA AS fornecedor_fantasia,
        'CONTAS_PAGAR' AS origem
    FROM tb_contas_pagar c
    LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = c.CPG_FORNECEDOR_FK
    WHERE c.CPG_BANCO_PAGAMENTO_FK = :b
      AND c.CPG_STATUS = 'PAGO'
      AND c.CPG_DATA_PAGAMENTO BETWEEN :di AND :df
      AND NOT EXISTS (
          SELECT 1 FROM tb_conciliacao_ofx_movimento m
          WHERE m.COM_REFERENCIA_TIPO = 'CONTA_PAGAR'
            AND m.COM_REFERENCIA_FK   = c.CPG_CODIGO_PK
      )
    ORDER BY c.CPG_DATA_PAGAMENTO ASC, c.CPG_CODIGO_PK ASC
");
$stPag->execute([':b' => $bancoId, ':di' => $di . ' 00:00:00', ':df' => $df . ' 23:59:59']);
$pagamentos = $stPag->fetchAll(PDO::FETCH_ASSOC);

// Recebimentos (contas a receber RECEBIDAS) sem vínculo OFX no período
$stRec = [];
try {
    $stR = $pdo->prepare("
        SELECT
            c.CRE_ID AS id,
            c.CRE_RECEBIDO_EM AS data,
            c.CRE_OBSERVACAO AS descricao,
            c.CRE_DOCUMENTO AS documento,
            COALESCE(c.CRE_VALOR_RECEBIDO, c.CRE_VALOR) AS valor,
            COALESCE(c.CRE_CLIENTE_NOME, cl.CLI_NOME_RAZAO) AS cliente
        FROM tb_contas_receber c
        LEFT JOIN cliente cl ON cl.CLI_ID = c.CRE_CLIENTE_FK
        WHERE c.CRE_BANCO_FK = :b
          AND c.CRE_STATUS IN ('RECEBIDO','PAGO')
          AND c.CRE_RECEBIDO_EM BETWEEN :di AND :df
        ORDER BY c.CRE_RECEBIDO_EM ASC, c.CRE_ID ASC
    ");
    $stR->execute([':b' => $bancoId, ':di' => $di . ' 00:00:00', ':df' => $df . ' 23:59:59']);
    $stRec = $stR->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Se a tabela tiver outro shape, ignora silenciosamente para não derrubar a tela
    $stRec = [];
}

// Unifica em uma única lista de lançamentos
$lancs = [];
foreach ($movsOfx as $m) {
    $valor = (float)$m['valor'];
    $lancs[] = [
        'data'       => substr((string)$m['data'], 0, 10),
        'descricao'  => (string)$m['descricao'],
        'documento'  => (string)$m['documento'],
        'valor'      => $valor,
        'tipo'       => $valor >= 0 ? 'C' : 'D',
        'origem'     => 'OFX' . (strtoupper((string)$m['conciliado']) === 'SIM' ? ' · conciliado' : ''),
        'extra'      => $m['ref_tipo'] ? ('Vinculado a ' . $m['ref_tipo'] . ' #' . $m['ref_fk']) : '',
    ];
}
foreach ($pagamentos as $p) {
    $valor = (float)$p['valor'];
    $forn = $p['fornecedor_fantasia'] ?: $p['fornecedor'] ?: '';
    $lancs[] = [
        'data'       => substr((string)$p['data'], 0, 10),
        'descricao'  => trim(($forn ? $forn . ' — ' : '') . (string)$p['descricao']),
        'documento'  => (string)$p['documento'],
        'valor'      => -$valor,
        'tipo'       => 'D',
        'origem'     => 'Contas a Pagar #' . $p['id'],
        'extra'      => '',
    ];
}
foreach ($stRec as $r) {
    $valor = (float)$r['valor'];
    $cli = $r['cliente'] ?? '';
    $lancs[] = [
        'data'       => substr((string)$r['data'], 0, 10),
        'descricao'  => trim(($cli ? $cli . ' — ' : '') . (string)($r['descricao'] ?? '')),
        'documento'  => (string)($r['documento'] ?? ''),
        'valor'      => $valor,
        'tipo'       => 'C',
        'origem'     => 'Contas a Receber #' . $r['id'],
        'extra'      => '',
    ];
}

usort($lancs, function ($a, $b) {
    $cmp = strcmp($a['data'], $b['data']);
    return $cmp !== 0 ? $cmp : ($a['valor'] <=> $b['valor']);
});

// Calcula saldo corrente
$saldoCorrente = $saldoAnterior;
$totalEntradas = 0.0;
$totalSaidas   = 0.0;
foreach ($lancs as &$l) {
    $saldoCorrente += $l['valor'];
    $l['saldo'] = $saldoCorrente;
    if ($l['valor'] >= 0) $totalEntradas += $l['valor'];
    else                  $totalSaidas   += abs($l['valor']);
}
unset($l);

$nomeBanco = $banco['BAN_APELIDO'] ?: $banco['BAN_NOME'];
$ag = trim(($banco['BAN_AGENCIA'] ?: '') . ($banco['BAN_AGENCIA_DV'] ? '-' . $banco['BAN_AGENCIA_DV'] : ''));
$ct = trim(($banco['BAN_CONTA']   ?: '') . ($banco['BAN_CONTA_DV']   ? '-' . $banco['BAN_CONTA_DV']   : ''));

function fmtMoney(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}
function fmtDataBR(string $d): string {
    if (strlen($d) < 10) return $d;
    return substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Extrato — <?= htmlspecialchars($nomeBanco) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background:#f3f4f6; padding:24px; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-variant-numeric: tabular-nums; }
        .ext-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:24px; box-shadow:0 2px 14px rgba(0,0,0,.04); }
        .ext-header { border-bottom:2px solid #0f172a; padding-bottom:12px; margin-bottom:16px; }
        .ext-kpi { background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:10px 14px; }
        .ext-kpi .lbl { font-size:11px; color:#64748b; text-transform:uppercase; }
        .ext-kpi .val { font-size:18px; font-weight:700; }
        table.ext-tab { width:100%; border-collapse:collapse; }
        table.ext-tab th { font-size:11px; text-transform:uppercase; color:#64748b; border-bottom:1px solid #e5e7eb; padding:6px 8px; text-align:left; }
        table.ext-tab td { padding:8px; border-bottom:1px solid #f1f5f9; font-size:13px; vertical-align:top; }
        table.ext-tab tr:hover td { background:#fafafa; }
        .badge-c { background:#dcfce7; color:#15803d; padding:1px 8px; border-radius:6px; font-size:11px; font-weight:600; }
        .badge-d { background:#fee2e2; color:#b91c1c; padding:1px 8px; border-radius:6px; font-size:11px; font-weight:600; }
        .text-d { color:#b91c1c; }
        .text-c { color:#15803d; }
        .small-muted { font-size:11px; color:#94a3b8; }
        .filter-bar { background:#f1f5f9; border:1px solid #e5e7eb; padding:10px 14px; border-radius:10px; margin-bottom:14px; }
        @media print {
            body { background:#fff; padding:0; }
            .ext-card { box-shadow:none; border:0; padding:0; }
            .no-print { display:none !important; }
        }
    </style>
</head>
<body>

<div class="ext-card">
    <div class="ext-header d-flex justify-content-between align-items-start">
        <div>
            <h4 class="fw-bold mb-1"><i class="fa-solid fa-building-columns me-2"></i>Extrato Bancário</h4>
            <div class="fw-semibold"><?= htmlspecialchars($nomeBanco) ?></div>
            <div class="small-muted">
                <?= htmlspecialchars($banco['BAN_CODIGO'] ?: '') ?> — <?= htmlspecialchars($banco['BAN_NOME']) ?>
                · Agência <span class="mono"><?= htmlspecialchars($ag ?: '—') ?></span>
                · Conta <span class="mono"><?= htmlspecialchars($ct ?: '—') ?></span>
            </div>
            <div class="small-muted mt-1">
                Período: <strong><?= fmtDataBR($di) ?></strong> a <strong><?= fmtDataBR($df) ?></strong>
                · Emitido em <?= date('d/m/Y H:i') ?>
            </div>
        </div>
        <div class="d-flex gap-2 no-print">
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="fa-solid fa-print me-1"></i>Imprimir
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.close()">
                <i class="fa-solid fa-xmark me-1"></i>Fechar
            </button>
        </div>
    </div>

    <form method="get" class="filter-bar no-print d-flex align-items-end gap-2 flex-wrap">
        <input type="hidden" name="banco_id" value="<?= (int)$bancoId ?>">
        <div>
            <label class="form-label small mb-1">De</label>
            <input type="date" name="di" value="<?= htmlspecialchars($di) ?>" class="form-control form-control-sm">
        </div>
        <div>
            <label class="form-label small mb-1">Até</label>
            <input type="date" name="df" value="<?= htmlspecialchars($df) ?>" class="form-control form-control-sm">
        </div>
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-filter me-1"></i>Filtrar</button>
    </form>

    <div class="row g-2 mb-3">
        <div class="col"><div class="ext-kpi"><div class="lbl">Saldo anterior</div><div class="val mono"><?= fmtMoney($saldoAnterior) ?></div></div></div>
        <div class="col"><div class="ext-kpi"><div class="lbl">Entradas</div><div class="val mono text-c"><?= fmtMoney($totalEntradas) ?></div></div></div>
        <div class="col"><div class="ext-kpi"><div class="lbl">Saídas</div><div class="val mono text-d"><?= fmtMoney($totalSaidas) ?></div></div></div>
        <div class="col"><div class="ext-kpi"><div class="lbl">Saldo final</div><div class="val mono"><?= fmtMoney($saldoCorrente) ?></div></div></div>
    </div>

    <table class="ext-tab">
        <thead>
            <tr>
                <th style="width:90px">Data</th>
                <th>Descrição</th>
                <th style="width:110px">Documento</th>
                <th style="width:60px" class="text-center">Tipo</th>
                <th style="width:130px" class="text-end">Valor</th>
                <th style="width:140px" class="text-end">Saldo</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!count($lancs)): ?>
            <tr><td colspan="6" class="text-center small-muted py-4">Nenhum lançamento no período.</td></tr>
        <?php else: foreach ($lancs as $l): ?>
            <tr>
                <td class="mono"><?= fmtDataBR($l['data']) ?></td>
                <td>
                    <?= htmlspecialchars($l['descricao'] ?: '—') ?>
                    <?php if ($l['origem']): ?><div class="small-muted"><?= htmlspecialchars($l['origem']) ?><?php if ($l['extra']) echo ' · ' . htmlspecialchars($l['extra']); ?></div><?php endif; ?>
                </td>
                <td class="mono small"><?= htmlspecialchars($l['documento'] ?: '—') ?></td>
                <td class="text-center">
                    <?php if ($l['tipo'] === 'C'): ?><span class="badge-c">C</span><?php else: ?><span class="badge-d">D</span><?php endif; ?>
                </td>
                <td class="text-end mono <?= $l['valor'] >= 0 ? 'text-c' : 'text-d' ?>"><?= fmtMoney($l['valor']) ?></td>
                <td class="text-end mono"><?= fmtMoney($l['saldo']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="small-muted mt-3">
        Fontes: movimentos OFX importados + lançamentos pagos no contas a pagar/receber sem vínculo OFX (para evitar duplicidade).
    </div>
</div>

  <script src="assets/session_keeper.js" defer></script>
</body>
</html>
