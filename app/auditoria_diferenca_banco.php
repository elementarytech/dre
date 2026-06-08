<?php
// /app/auditoria_diferenca_banco.php
// Auditoria por banco: mostra exatamente quais lançamentos compõem a diferença
// entre Saldo Bancário (OFX) e Saldo ERP (sistema).
//
// Decompõe a diferença em 4 grupos:
//   A — Recebimentos no ERP sem vínculo OFX (puxam ERP pra cima)
//   B — Pagamentos no ERP sem vínculo OFX (puxam ERP pra baixo)
//   C — Movimentos OFX importados sem vínculo a conta (puxam Bancário)
//   D — Ajustes manuais SOMA/SUB (afetam só ERP)
//
// Fórmula: Diferença (Bancário − ERP) = C − A + B − D
//
// Acesso: /app/auditoria_diferenca_banco.php?banco_id=X (ou seleciona no dropdown)

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/status_dict.php';
require_once __DIR__ . '/config/saldos.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Acesso restrito. Faça login com um usuário ADMIN.');
}

$brl = function ($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); };
$h   = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$dataBR = function ($v) { return $v ? date('d/m/Y', strtotime((string)$v)) : '—'; };

// Lista de bancos ativos para o dropdown
$bancos = $pdo->query("
    SELECT BAN_ID, BAN_APELIDO, BAN_NOME, BAN_CODIGO, BAN_AGENCIA, BAN_CONTA
    FROM tb_banco WHERE BAN_STATUS = 'ATIVO'
    ORDER BY BAN_APELIDO, BAN_NOME
")->fetchAll(PDO::FETCH_ASSOC);

$bancoIdSel = (int)($_GET['banco_id'] ?? 0);
$bancoSel = null;
foreach ($bancos as $b) {
    if ((int)$b['BAN_ID'] === $bancoIdSel) { $bancoSel = $b; break; }
}

// Calcula tudo se houver banco selecionado
$saldoBancario = null;
$saldoErp = null;
$diferenca = null;
$setRow = null;
$contaRef = '';

$listaA = []; $somaA = 0;
$listaB = []; $somaB = 0;
$listaC = []; $somaC = 0;
$listaD = []; $somaD = 0;

if ($bancoSel) {
    $contaRef = trim((string)$bancoSel['BAN_AGENCIA']) . '/' . trim((string)$bancoSel['BAN_CONTA']);
    $banId    = (int)$bancoSel['BAN_ID'];

    $saldoBancario = saldoBancarioOfx($pdo, $banId, $contaRef);
    $saldoErp      = saldoErpConta($pdo, $banId, $contaRef);
    $diferenca     = $saldoBancario - $saldoErp;

    // Pega o SET ATIVO do banco (data de corte)
    $stSet = $pdo->prepare("
        SELECT CAS_CODIGO_PK, CAS_SALDO_NOVO, CAS_DATA, CAS_DATA_CADASTRO
        FROM tb_conciliacao_ajuste_saldo
        WHERE CAS_BANCO_FK = ? AND CAS_CONTA_REF = ?
          AND CAS_CAMPO_AJUSTADO = 'SALDO_ERP'
          AND CAS_OPERACAO = 'SET' AND CAS_STATUS = 'ATIVO'
        ORDER BY CAS_DATA DESC, CAS_CODIGO_PK DESC LIMIT 1
    ");
    $stSet->execute([$banId, $contaRef]);
    $setRow = $stSet->fetch(PDO::FETCH_ASSOC);
    $dataCorte = $setRow ? (string)$setRow['CAS_DATA'] : '0000-00-00';

    // === GRUPO A: Receber sem OFX ===
    $stA = $pdo->prepare("
        SELECT cr.CRE_ID AS id,
               cr.CRE_RECEBIDO_EM AS data,
               COALESCE(NULLIF(cr.CRE_VALOR_RECEBIDO, 0), cr.CRE_VALOR) AS valor,
               cr.CRE_VALOR AS valor_total,
               cr.CRE_CLIENTE_NOME AS contraparte,
               cr.CRE_DOCUMENTO AS doc,
               cr.CRE_STATUS AS status
        FROM tb_contas_receber cr
        WHERE cr.CRE_BANCO_FK = ?
          AND cr.CRE_STATUS IN ('RECEBIDO','PAGO')
          AND cr.CRE_RECEBIDO_EM > ?
          AND cr.CRE_OFX_MOVIMENTO_FK IS NULL
          AND NOT EXISTS (
            SELECT 1 FROM tb_conciliacao_vinculo v
            WHERE v.VIN_LANCAMENTO_TIPO = 'CONTA_RECEBER'
              AND v.VIN_LANCAMENTO_FK = cr.CRE_ID
              AND v.VIN_STATUS = 'ATIVO'
          )
        ORDER BY cr.CRE_RECEBIDO_EM DESC, cr.CRE_ID DESC
    ");
    $stA->execute([$banId, $dataCorte]);
    $listaA = $stA->fetchAll(PDO::FETCH_ASSOC);
    foreach ($listaA as $l) $somaA += (float)$l['valor'];

    // === GRUPO B: Pagar sem OFX ===
    $stB = $pdo->prepare("
        SELECT cp.CPG_CODIGO_PK AS id,
               cp.CPG_DATA_PAGAMENTO AS data,
               COALESCE(cp.CPG_VALOR_PAGO, cp.CPG_VALOR_PARCELA) AS valor,
               cp.CPG_VALOR_PARCELA AS valor_total,
               COALESCE(NULLIF(f.FOR_NOME_FANTASIA,''), f.FOR_RAZAO_SOCIAL) AS contraparte,
               cp.CPG_DOCUMENTO AS doc,
               cp.CPG_STATUS AS status,
               cp.CPG_DESCRICAO AS descricao
        FROM tb_contas_pagar cp
        LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
        WHERE cp.CPG_BANCO_PAGAMENTO_FK = ?
          AND cp.CPG_STATUS = 'PAGO'
          AND cp.CPG_DATA_PAGAMENTO > ?
          AND cp.CPG_OFX_MOVIMENTO_FK IS NULL
          AND NOT EXISTS (
            SELECT 1 FROM tb_conciliacao_vinculo v
            WHERE v.VIN_LANCAMENTO_TIPO = 'CONTA_PAGAR'
              AND v.VIN_LANCAMENTO_FK = cp.CPG_CODIGO_PK
              AND v.VIN_STATUS = 'ATIVO'
          )
        ORDER BY cp.CPG_DATA_PAGAMENTO DESC, cp.CPG_CODIGO_PK DESC
    ");
    $stB->execute([$banId, $dataCorte]);
    $listaB = $stB->fetchAll(PDO::FETCH_ASSOC);
    foreach ($listaB as $l) $somaB += (float)$l['valor'];

    // === GRUPO C: OFX sem vínculo ===
    $stC = $pdo->prepare("
        SELECT m.COM_CODIGO_PK AS id,
               m.COM_DATA_MOVIMENTO AS data,
               m.COM_VALOR AS valor,
               m.COM_DESCRICAO AS descricao,
               m.COM_DOCUMENTO AS doc,
               m.COM_TIPO AS tipo_mov,
               m.COM_CONCILIADO AS conciliado
        FROM tb_conciliacao_ofx_movimento m
        WHERE m.COM_BANCO_FK = ?
          AND m.COM_DATA_MOVIMENTO > ?
          AND COALESCE(m.COM_CONCILIADO, 'NAO') <> 'SIM'
          AND NOT EXISTS (
            SELECT 1 FROM tb_conciliacao_vinculo v
            WHERE v.VIN_OFX_MOVIMENTO_FK = m.COM_CODIGO_PK
              AND v.VIN_STATUS = 'ATIVO'
          )
        ORDER BY m.COM_DATA_MOVIMENTO DESC, m.COM_CODIGO_PK DESC
    ");
    $stC->execute([$banId, $dataCorte]);
    $listaC = $stC->fetchAll(PDO::FETCH_ASSOC);
    foreach ($listaC as $l) $somaC += (float)$l['valor'];

    // === GRUPO D: Ajustes manuais ===
    $stD = $pdo->prepare("
        SELECT CAS_CODIGO_PK AS id,
               CAS_DATA AS data,
               CAS_OPERACAO AS operacao,
               CAS_VALOR AS valor_bruto,
               CASE WHEN CAS_OPERACAO = 'SOMA' THEN CAS_VALOR ELSE -CAS_VALOR END AS valor,
               CAS_MOTIVO AS motivo,
               CAS_OBSERVACAO AS observacao,
               CAS_USUARIO AS usuario
        FROM tb_conciliacao_ajuste_saldo
        WHERE CAS_BANCO_FK = ?
          AND CAS_CONTA_REF = ?
          AND CAS_CAMPO_AJUSTADO = 'SALDO_ERP'
          AND CAS_OPERACAO IN ('SOMA','SUB')
          AND CAS_STATUS = 'ATIVO'
          AND CAS_DATA > ?
        ORDER BY CAS_DATA DESC, CAS_CODIGO_PK DESC
    ");
    $stD->execute([$banId, $contaRef, $dataCorte]);
    $listaD = $stD->fetchAll(PDO::FETCH_ASSOC);
    foreach ($listaD as $l) $somaD += (float)$l['valor'];
}

// Soma calculada vs diferença real
$somaCalculada = $bancoSel ? ($somaC - $somaA + $somaB - $somaD) : null;
$bate = $bancoSel ? (abs((float)$somaCalculada - (float)$diferenca) < 1.00) : null; // tolerância R$ 1

?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Auditoria de Diferença por Banco</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<style>
    body { background:#f3f4f6; font-family:'Poppins', system-ui, sans-serif; padding-bottom:40px; }
    .kpi-card { border:0; border-radius:10px; }
    .label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
    .value { font-size:20px; font-weight:600; color:#0f172a; line-height:1.1; }
    .neg { color:#b91c1c; }
    .pos { color:#15803d; }
    .num { font-variant-numeric: tabular-nums; }
    .small-muted { font-size:11px; color:#64748b; }
    .table thead th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#475569; background:#f1f5f9; }
    .table td, .table th { font-size:13px; }
    .grupo-titulo { font-size:13px; font-weight:700; color:#0f172a; margin:18px 0 6px; padding-bottom:5px; border-bottom:2px solid; }
    .grupo-A { border-color:#16a34a; color:#15803d; }
    .grupo-B { border-color:#dc2626; color:#b91c1c; }
    .grupo-C { border-color:#0284c7; color:#0369a1; }
    .grupo-D { border-color:#a16207; color:#854d0e; }
    .formula { background:#fff7ed; border:1px solid #fed7aa; border-radius:6px; padding:14px 18px; font-size:13px; }
    .formula .ok { color:#15803d; font-weight:700; }
    .formula .nok { color:#b91c1c; font-weight:700; }
</style>
</head>
<body>

<div class="container-fluid py-4">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-search me-2"></i>Auditoria de diferença por banco</h4>
            <div class="small-muted">Lista os lançamentos que justificam a divergência entre Saldo Bancário e Saldo ERP.</div>
        </div>
        <a href="conciliacao_bancaria.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
    </div>

    <!-- Filtro de banco -->
    <div class="card kpi-card mb-3 p-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label small fw-semibold mb-1">Banco</label>
                <select name="banco_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">— escolha um banco —</option>
                    <?php foreach ($bancos as $b): ?>
                        <option value="<?=$b['BAN_ID']?>" <?=$bancoIdSel==(int)$b['BAN_ID']?'selected':''?>>
                            <?=$h(($b['BAN_APELIDO'] ?: $b['BAN_NOME']))?> · <?=$h($b['BAN_CODIGO'])?> · <?=$h($b['BAN_AGENCIA'])?>/<?=$h($b['BAN_CONTA'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 small-muted">
                <?php if ($bancoSel): ?>
                    Banco: <strong><?=$h($bancoSel['BAN_APELIDO'] ?: $bancoSel['BAN_NOME'])?></strong> ·
                    Conta: <code><?=$h($contaRef)?></code> ·
                    SET: <?php if ($setRow): ?>
                        <strong><?=$brl($setRow['CAS_SALDO_NOVO'])?></strong> em <?=$dataBR($setRow['CAS_DATA'])?>
                    <?php else: ?>
                        <em>nenhum (parte de zero)</em>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$bancoSel): ?>
        <div class="alert alert-info">Escolha um banco no filtro acima.</div>
    <?php else: ?>

    <!-- Cards principais -->
    <div class="row g-2 mb-3">
        <div class="col-md-3"><div class="card kpi-card p-3 h-100">
            <div class="label">Saldo Bancário</div>
            <div class="value <?=$saldoBancario<0?'neg':''?>"><?=$brl($saldoBancario)?></div>
            <div class="small-muted">SET + Σ movimentos OFX</div>
        </div></div>
        <div class="col-md-3"><div class="card kpi-card p-3 h-100">
            <div class="label">Saldo ERP</div>
            <div class="value <?=$saldoErp<0?'neg':''?>"><?=$brl($saldoErp)?></div>
            <div class="small-muted">SET + Σ baixas (status correto)</div>
        </div></div>
        <div class="col-md-3"><div class="card kpi-card p-3 h-100" style="border-left:4px solid <?=abs($diferenca)<0.01?'#15803d':'#b91c1c'?>">
            <div class="label">Diferença real (Bancário − ERP)</div>
            <div class="value <?=abs($diferenca)<0.01?'pos':'neg'?>"><?=$brl($diferenca)?></div>
            <div class="small-muted">o que precisa justificar</div>
        </div></div>
        <div class="col-md-3"><div class="card kpi-card p-3 h-100" style="border-left:4px solid <?=$bate?'#15803d':'#a16207'?>">
            <div class="label">Soma calculada (C − A + B − D)</div>
            <div class="value <?=$bate?'pos':'neg'?>"><?=$brl($somaCalculada)?></div>
            <div class="small-muted"><?=$bate?'✓ bate com a diferença':'✗ não bate (verificar)'?></div>
        </div></div>
    </div>

    <!-- Validação -->
    <div class="formula mb-4">
        <strong>Fórmula:</strong> Diferença = (C − A) + (B − D) = OFX órfãos − Receb sem OFX + Pagto sem OFX − Ajustes ERP<br>
        <span class="num"><?=$brl($somaC)?></span>
        − <span class="num"><?=$brl($somaA)?></span>
        + <span class="num"><?=$brl($somaB)?></span>
        − <span class="num"><?=$brl($somaD)?></span>
        = <strong class="num"><?=$brl($somaCalculada)?></strong>
        vs diferença real <strong class="num"><?=$brl($diferenca)?></strong>
        <span class="<?=$bate?'ok':'nok'?>">
            <?=$bate?'✓ bate':'✗ NÃO bate (pode haver vínculo legado fora do escopo, conta sem banco vinculado, ou data anterior ao SET)'?>
        </span>
    </div>

    <!-- Grupo A -->
    <div class="grupo-titulo grupo-A">
        <i class="bi bi-arrow-down-right me-1"></i>A. Recebimentos no ERP sem vínculo OFX —
        <strong><?=count($listaA)?></strong> lançamento(s) — Total <strong><?=$brl($somaA)?></strong>
    </div>
    <div class="card kpi-card mb-2">
    <?php if (empty($listaA)): ?>
        <div class="card-body text-muted small text-center">Nenhum.</div>
    <?php else: ?>
        <div class="table-responsive" style="max-height:380px;overflow:auto">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr>
                    <th style="width:80px">ID</th>
                    <th style="width:100px">Recebto</th>
                    <th>Cliente</th>
                    <th style="width:140px">Documento</th>
                    <th style="width:90px">Status</th>
                    <th class="text-end" style="width:130px">Valor recebido</th>
                </tr></thead>
                <tbody>
                <?php foreach ($listaA as $l): ?>
                    <tr>
                        <td class="num"><a href="contas_receber.php?abrir=<?=$h($l['id'])?>" target="_blank">#<?=$h($l['id'])?></a></td>
                        <td class="num"><?=$dataBR($l['data'])?></td>
                        <td><?=$h($l['contraparte'] ?: '—')?></td>
                        <td class="num"><?=$h($l['doc'] ?: '—')?></td>
                        <td><span class="badge bg-success-subtle text-success"><?=$h($l['status'])?></span></td>
                        <td class="num text-end pos"><?=$brl($l['valor'])?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light"><tr>
                    <th colspan="5" class="text-end">Total Grupo A</th>
                    <th class="num text-end"><?=$brl($somaA)?></th>
                </tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
    </div>

    <!-- Grupo B -->
    <div class="grupo-titulo grupo-B">
        <i class="bi bi-arrow-up-right me-1"></i>B. Pagamentos no ERP sem vínculo OFX —
        <strong><?=count($listaB)?></strong> lançamento(s) — Total <strong><?=$brl($somaB)?></strong>
    </div>
    <div class="card kpi-card mb-2">
    <?php if (empty($listaB)): ?>
        <div class="card-body text-muted small text-center">Nenhum.</div>
    <?php else: ?>
        <div class="table-responsive" style="max-height:380px;overflow:auto">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr>
                    <th style="width:80px">ID</th>
                    <th style="width:100px">Pagto</th>
                    <th>Fornecedor / Descrição</th>
                    <th style="width:140px">Documento</th>
                    <th style="width:90px">Status</th>
                    <th class="text-end" style="width:130px">Valor pago</th>
                </tr></thead>
                <tbody>
                <?php foreach ($listaB as $l): ?>
                    <tr>
                        <td class="num"><a href="contas_pagar.php?abrir=<?=$h($l['id'])?>" target="_blank">#<?=$h($l['id'])?></a></td>
                        <td class="num"><?=$dataBR($l['data'])?></td>
                        <td><?=$h(($l['contraparte'] ?: $l['descricao'] ?: '—'))?></td>
                        <td class="num"><?=$h($l['doc'] ?: '—')?></td>
                        <td><span class="badge bg-danger-subtle text-danger"><?=$h($l['status'])?></span></td>
                        <td class="num text-end neg"><?=$brl($l['valor'])?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light"><tr>
                    <th colspan="5" class="text-end">Total Grupo B</th>
                    <th class="num text-end"><?=$brl($somaB)?></th>
                </tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
    </div>

    <!-- Grupo C -->
    <div class="grupo-titulo grupo-C">
        <i class="bi bi-bank me-1"></i>C. Movimentos OFX importados sem vínculo —
        <strong><?=count($listaC)?></strong> movimento(s) — Total <strong><?=$brl($somaC)?></strong>
    </div>
    <div class="card kpi-card mb-2">
    <?php if (empty($listaC)): ?>
        <div class="card-body text-muted small text-center">Nenhum.</div>
    <?php else: ?>
        <div class="table-responsive" style="max-height:380px;overflow:auto">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr>
                    <th style="width:80px">ID</th>
                    <th style="width:100px">Data</th>
                    <th>Descrição (extrato)</th>
                    <th style="width:140px">Documento</th>
                    <th style="width:90px">Tipo</th>
                    <th class="text-end" style="width:130px">Valor</th>
                </tr></thead>
                <tbody>
                <?php foreach ($listaC as $l): $vl=(float)$l['valor']; ?>
                    <tr>
                        <td class="num">#<?=$h($l['id'])?></td>
                        <td class="num"><?=$dataBR($l['data'])?></td>
                        <td class="small"><?=$h(mb_substr((string)$l['descricao'], 0, 80))?></td>
                        <td class="num"><?=$h($l['doc'] ?: '—')?></td>
                        <td><span class="badge bg-<?=$vl>=0?'success':'danger'?>-subtle text-<?=$vl>=0?'success':'danger'?>"><?=$h($l['tipo_mov'])?></span></td>
                        <td class="num text-end <?=$vl>=0?'pos':'neg'?>"><?=$brl($vl)?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light"><tr>
                    <th colspan="5" class="text-end">Total Grupo C (líquido)</th>
                    <th class="num text-end"><?=$brl($somaC)?></th>
                </tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
    </div>

    <!-- Grupo D -->
    <div class="grupo-titulo grupo-D">
        <i class="bi bi-sliders me-1"></i>D. Ajustes manuais ATIVOS posteriores ao SET —
        <strong><?=count($listaD)?></strong> ajuste(s) — Total <strong><?=$brl($somaD)?></strong>
    </div>
    <div class="card kpi-card mb-3">
    <?php if (empty($listaD)): ?>
        <div class="card-body text-muted small text-center">Nenhum.</div>
    <?php else: ?>
        <div class="table-responsive" style="max-height:380px;overflow:auto">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr>
                    <th style="width:80px">ID</th>
                    <th style="width:100px">Data</th>
                    <th style="width:80px">Operação</th>
                    <th>Motivo / Observação</th>
                    <th style="width:140px">Usuário</th>
                    <th class="text-end" style="width:130px">Valor</th>
                </tr></thead>
                <tbody>
                <?php foreach ($listaD as $l): $vl=(float)$l['valor']; ?>
                    <tr>
                        <td class="num">#<?=$h($l['id'])?></td>
                        <td class="num"><?=$dataBR($l['data'])?></td>
                        <td><span class="badge bg-warning-subtle text-warning-emphasis"><?=$h($l['operacao'])?></span></td>
                        <td class="small"><?=$h($l['motivo'] ?: '—')?><?php if($l['observacao']): ?><br><span class="text-muted"><?=$h(mb_substr((string)$l['observacao'], 0, 100))?></span><?php endif; ?></td>
                        <td class="small"><?=$h($l['usuario'] ?: '—')?></td>
                        <td class="num text-end <?=$vl>=0?'pos':'neg'?>"><?=$brl($vl)?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light"><tr>
                    <th colspan="5" class="text-end">Total Grupo D (líquido)</th>
                    <th class="num text-end"><?=$brl($somaD)?></th>
                </tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
    </div>

    <!-- Como ler -->
    <div class="card kpi-card p-3 small-muted">
        <strong>Como ler:</strong>
        <ul class="mb-0 mt-1">
            <li><strong>A</strong>: o sistema acha que recebeu, mas o banco (OFX) não confirmou ainda. Importar OFX do período cobre.</li>
            <li><strong>B</strong>: o sistema acha que pagou, mas o banco não confirmou ainda. Idem A.</li>
            <li><strong>C</strong>: o banco mostra um movimento que ainda não tem conta_pagar/receber correspondente no sistema. Conciliar via modal.</li>
            <li><strong>D</strong>: ajustes manuais que afetam o saldo ERP. Devem ter motivo claro.</li>
        </ul>
        <div class="mt-2">
            Se a <strong>Soma calculada</strong> bate com a <strong>Diferença real</strong> (alerta verde), tudo está mapeado nos 4 grupos.
            Se não bate, pode haver: vínculos legados fora do escopo, contas sem banco vinculado, ou movimentos com data anterior ao SET.
        </div>
    </div>

    <?php endif; ?>

</div>

</body>
</html>
