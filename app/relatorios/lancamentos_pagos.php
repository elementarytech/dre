<?php
// /app/relatorios/lancamentos_pagos.php
// Relatório de Lançamentos (Contas a Pagar) com filtros e export.
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/status_dict.php';

$__rel = '../';

// ====================================================================
// Helpers
// ====================================================================
function rp_int($v): int { return (int)($v ?? 0); }
function rp_str($v): string { return trim((string)($v ?? '')); }
function rp_date_ymd($v): ?string {
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    return null;
}
function rp_money($v): float {
    $v = trim((string)($v ?? ''));
    if ($v === '') return 0.0;
    $v = str_replace(['R$', ' ', "\xc2\xa0"], '', $v);
    if (strpos($v, ',') !== false) { $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); }
    return is_numeric($v) ? (float)$v : 0.0;
}

function rp_json_out(array $payload, int $code = 200): void {
    while (function_exists('ob_get_level') && ob_get_level() > 0) @ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// Build da query a partir dos filtros (compartilhado entre listar e export)
// ====================================================================
function rp_buildQuery(array $f): array {
    $where = [];
    $params = [];

    // Status (mapeia para CPG_STATUS)
    $status = strtoupper($f['status'] ?? 'PAGO');
    $statusFilter = '';
    switch ($status) {
        case 'PAGO':
            $where[] = "c.CPG_STATUS = 'PAGO'";
            break;
        case 'ABERTO':
            $where[] = "c.CPG_STATUS = 'ABERTO' AND (c.CPG_VENCIMENTO IS NULL OR c.CPG_VENCIMENTO >= CURDATE())";
            break;
        case 'ATRASADO':
            $where[] = "c.CPG_STATUS IN ('ABERTO','ATRASADO') AND c.CPG_VENCIMENTO < CURDATE()";
            break;
        case 'CANCELADO':
            $where[] = "c.CPG_STATUS = 'CANCELADO'";
            break;
        case 'TODOS':
        default:
            // sem filtro de status
            break;
    }

    // Período (data de pagamento se status=PAGO; senão vencimento)
    $dtIni = rp_date_ymd($f['dt_ini'] ?? '');
    $dtFim = rp_date_ymd($f['dt_fim'] ?? '');
    $campoData = ($status === 'PAGO') ? 'c.CPG_DATA_PAGAMENTO' : 'c.CPG_VENCIMENTO';

    if ($dtIni) { $where[] = "DATE($campoData) >= ?"; $params[] = $dtIni; }
    if ($dtFim) { $where[] = "DATE($campoData) <= ?"; $params[] = $dtFim; }

    // Banco (de pagamento) — -1 = sem banco associado (NULL)
    $bancoId = rp_int($f['banco_id'] ?? 0);
    if ($bancoId === -1) {
        $where[] = "c.CPG_BANCO_PAGAMENTO_FK IS NULL";
    } elseif ($bancoId > 0) {
        $where[] = "c.CPG_BANCO_PAGAMENTO_FK = ?";
        $params[] = $bancoId;
    }

    // Empresa
    $empresaId = rp_int($f['empresa_id'] ?? 0);
    if ($empresaId > 0) { $where[] = "c.CPG_EMPRESA_FK = ?"; $params[] = $empresaId; }

    // Fornecedor
    $fornId = rp_int($f['fornecedor_id'] ?? 0);
    if ($fornId > 0) { $where[] = "c.CPG_FORNECEDOR_FK = ?"; $params[] = $fornId; }

    // Plano de contas
    $planoId = rp_int($f['plano_id'] ?? 0);
    if ($planoId > 0) { $where[] = "c.CPG_PLANO_CONTAS_FK = ?"; $params[] = $planoId; }

    // Valor mín/máx (sobre valor pago se PAGO; senão valor parcela)
    $campoValor = ($status === 'PAGO')
        ? 'COALESCE(c.CPG_VALOR_PAGO, c.CPG_VALOR_PARCELA)'
        : 'c.CPG_VALOR_PARCELA';
    $vMin = rp_money($f['valor_min'] ?? '');
    $vMax = rp_money($f['valor_max'] ?? '');
    if ($vMin > 0) { $where[] = "$campoValor >= ?"; $params[] = $vMin; }
    if ($vMax > 0) { $where[] = "$campoValor <= ?"; $params[] = $vMax; }

    // Texto livre (descrição/documento/fornecedor)
    $q = rp_str($f['q'] ?? '');
    if ($q !== '') {
        $where[] = "(c.CPG_DESCRICAO LIKE ? OR c.CPG_DOCUMENTO LIKE ? OR f.FOR_RAZAO_SOCIAL LIKE ? OR f.FOR_NOME_FANTASIA LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $sqlBase = "
        FROM tb_contas_pagar c
        LEFT JOIN tb_fornecedor f   ON f.FOR_CODIGO_PK   = c.CPG_FORNECEDOR_FK
        LEFT JOIN tb_banco b         ON b.BAN_ID          = c.CPG_BANCO_PAGAMENTO_FK
        LEFT JOIN tb_empresa e       ON e.EMP_ID          = c.CPG_EMPRESA_FK
        LEFT JOIN tb_plano_contas p  ON p.PLC_CODIGO_PK   = c.CPG_PLANO_CONTAS_FK
        " . ($where ? "WHERE " . implode(' AND ', $where) : '');

    return [$sqlBase, $params, $campoData, $campoValor];
}

// ====================================================================
// AJAX: listar (paginado)
// ====================================================================
$acao = $_GET['acao'] ?? '';

if ($acao === 'listar') {
    try {
        [$sqlBase, $params, $campoData, $campoValor] = rp_buildQuery($_GET);

        $page = max(1, rp_int($_GET['page'] ?? 1));
        $per  = min(500, max(10, rp_int($_GET['per'] ?? 50)));
        $off  = ($page - 1) * $per;

        // Total (count + soma)
        $sqlTot = "SELECT COUNT(*) AS qtd, COALESCE(SUM($campoValor),0) AS total $sqlBase";
        $st = $pdo->prepare($sqlTot);
        $st->execute($params);
        $tot = $st->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total' => 0];

        // Página
        $sqlList = "
            SELECT
              c.CPG_CODIGO_PK            AS id,
              c.CPG_DATA_PAGAMENTO       AS data_pagamento,
              c.CPG_VENCIMENTO           AS vencimento,
              c.CPG_STATUS               AS status,
              c.CPG_NUM_PARCELA          AS num_parcela,
              c.CPG_QTD_PARCELAS         AS qtd_parcelas,
              c.CPG_VALOR_PARCELA        AS valor_parcela,
              c.CPG_VALOR_PAGO           AS valor_pago,
              $campoValor                AS valor_relevante,
              c.CPG_DESCRICAO            AS descricao,
              c.CPG_DOCUMENTO            AS documento,
              COALESCE(NULLIF(b.BAN_APELIDO,''), b.BAN_NOME) AS banco_nome,
              COALESCE(NULLIF(e.EMP_NOME_FANTASIA,''), e.EMP_RAZAO_SOCIAL) AS empresa_nome,
              COALESCE(NULLIF(f.FOR_NOME_FANTASIA,''), f.FOR_RAZAO_SOCIAL) AS fornecedor_nome,
              COALESCE(NULLIF(p.PLC_NOME,''), '—') AS plano_nome
            $sqlBase
            ORDER BY $campoData DESC, c.CPG_CODIGO_PK DESC
            LIMIT $per OFFSET $off
        ";
        $st = $pdo->prepare($sqlList);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        rp_json_out([
            'ok' => true,
            'rows' => $rows,
            'total' => (int)$tot['qtd'],
            'soma' => (float)$tot['total'],
            'page' => $page,
            'per'  => $per,
        ]);
    } catch (Throwable $e) {
        rp_json_out(['ok' => false, 'msg' => 'Erro ao consultar.', 'detail' => $e->getMessage()], 500);
    }
}

// ====================================================================
// EXPORT CSV / XLSX (HTML-Excel)
// ====================================================================
if ($acao === 'export') {
    try {
        [$sqlBase, $params, $campoData, $campoValor] = rp_buildQuery($_GET);
        $fmt = strtolower((string)($_GET['fmt'] ?? 'csv'));

        $sqlAll = "
            SELECT
              c.CPG_CODIGO_PK            AS id,
              c.CPG_DATA_PAGAMENTO       AS data_pagamento,
              c.CPG_VENCIMENTO           AS vencimento,
              c.CPG_STATUS               AS status,
              c.CPG_NUM_PARCELA          AS num_parcela,
              c.CPG_QTD_PARCELAS         AS qtd_parcelas,
              c.CPG_VALOR_PARCELA        AS valor_parcela,
              c.CPG_VALOR_PAGO           AS valor_pago,
              $campoValor                AS valor_relevante,
              c.CPG_DESCRICAO            AS descricao,
              c.CPG_DOCUMENTO            AS documento,
              COALESCE(NULLIF(b.BAN_APELIDO,''), b.BAN_NOME, '—') AS banco_nome,
              COALESCE(NULLIF(e.EMP_NOME_FANTASIA,''), e.EMP_RAZAO_SOCIAL, '—') AS empresa_nome,
              COALESCE(NULLIF(f.FOR_NOME_FANTASIA,''), f.FOR_RAZAO_SOCIAL, '—') AS fornecedor_nome,
              COALESCE(NULLIF(p.PLC_NOME,''), '—') AS plano_nome
            $sqlBase
            ORDER BY $campoData DESC, c.CPG_CODIGO_PK DESC
        ";
        $st = $pdo->prepare($sqlAll);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $headers = ['#','Data Pagto','Vencimento','Status','Parcela','Banco','Empresa','Fornecedor','Plano de Contas','Documento','Descrição','Valor (R$)'];

        $stamp = date('Ymd_His');
        $totalSoma = 0.0;
        $linhas = [];
        foreach ($rows as $i => $r) {
            $valor = (float)$r['valor_relevante'];
            $totalSoma += $valor;
            $parc = ($r['num_parcela'] && $r['qtd_parcelas']) ? $r['num_parcela'].'/'.$r['qtd_parcelas'] : '';
            $dataPgto = $r['data_pagamento'] ? date('d/m/Y', strtotime((string)$r['data_pagamento'])) : '';
            $venc     = $r['vencimento']     ? date('d/m/Y', strtotime((string)$r['vencimento']))     : '';
            $linhas[] = [
                (string)$r['id'], $dataPgto, $venc, (string)$r['status'], $parc,
                (string)$r['banco_nome'], (string)$r['empresa_nome'], (string)$r['fornecedor_nome'],
                (string)$r['plano_nome'], (string)$r['documento'], (string)$r['descricao'],
                number_format($valor, 2, ',', '.'),
            ];
        }

        if ($fmt === 'xlsx' || $fmt === 'xls') {
            // HTML-Excel: o Excel/LibreOffice abrem perfeitamente, sem dependência externa.
            while (function_exists('ob_get_level') && ob_get_level() > 0) @ob_end_clean();
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="lancamentos_'.$stamp.'.xls"');
            echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel
            echo "<table border='1'><thead><tr>";
            foreach ($headers as $h) echo "<th>".htmlspecialchars($h, ENT_QUOTES, 'UTF-8')."</th>";
            echo "</tr></thead><tbody>";
            foreach ($linhas as $l) {
                echo "<tr>";
                foreach ($l as $c) echo "<td>".htmlspecialchars($c, ENT_QUOTES, 'UTF-8')."</td>";
                echo "</tr>";
            }
            echo "<tr><td colspan='11' style='text-align:right;font-weight:bold'>TOTAL</td>";
            echo "<td style='font-weight:bold'>".number_format($totalSoma, 2, ',', '.')."</td></tr>";
            echo "</tbody></table>";
            exit;
        }

        // CSV padrão (separador ; e UTF-8 com BOM, abre direto no Excel)
        while (function_exists('ob_get_level') && ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="lancamentos_'.$stamp.'.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM
        fputcsv($out, $headers, ';');
        foreach ($linhas as $l) fputcsv($out, $l, ';');
        fputcsv($out, ['','','','','','','','','','','TOTAL', number_format($totalSoma, 2, ',', '.')], ';');
        fclose($out);
        exit;

    } catch (Throwable $e) {
        rp_json_out(['ok' => false, 'msg' => 'Erro ao exportar.', 'detail' => $e->getMessage()], 500);
    }
}

// ====================================================================
// HTML
// ====================================================================
$bancos    = $pdo->query("SELECT BAN_ID AS id, COALESCE(NULLIF(BAN_APELIDO,''), BAN_NOME) AS nome FROM tb_banco WHERE BAN_STATUS='ATIVO' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$empresas  = $pdo->query("SELECT EMP_ID AS id, COALESCE(NULLIF(EMP_NOME_FANTASIA,''), EMP_RAZAO_SOCIAL) AS nome FROM tb_empresa WHERE EMP_STATUS='ATIVO' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$planos    = $pdo->query("SELECT PLC_CODIGO_PK AS id, CONCAT(COALESCE(NULLIF(PLC_CODIGO,''), CONCAT('#', PLC_CODIGO_PK)), ' - ', PLC_NOME) AS nome FROM tb_plano_contas WHERE PLC_STATUS='ATIVO' AND PLC_TIPO='Despesa' ORDER BY PLC_CODIGO")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <title>Relatório • Lançamentos Pagos</title>
  <?php include __DIR__ . '/../includes/head.php'; ?>
  <style>
    body { background:#f4f6f9; font-family:'Inter',system-ui,sans-serif; }
    .page-title { font-size:1.3rem; font-weight:700; color:#0f172a; margin:0; }
    .filtros-card { background:#fff; border:1px solid #e5e7eb; border-radius:.75rem; padding:1rem; }
    .resumo-card { background:#fff; border:1px solid #e5e7eb; border-radius:.75rem; padding:.85rem 1rem; }
    .table thead th { font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; color:#475569; background:#f8fafc; }
    .table tbody td { font-size:.85rem; vertical-align:middle; }
    .badge-status { font-size:.7rem; padding:.25em .55em; }
    .mono { font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; }
    .total-row { background:#f1f5f9 !important; font-weight:700; }
    @media print {
      .sidebar, .navbar, .filtros-card, .no-print { display:none !important; }
      main { margin-left:0 !important; }
      body { background:#fff !important; }
    }
  </style>
</head>
<body>
  <div class="d-flex">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-grow-1" style="min-width:0;">
      <nav class="navbar bg-white border-bottom px-3 py-2 sticky-top no-print">
        <div class="d-flex align-items-center gap-2">
          <a href="index.php" class="btn btn-sm btn-link text-decoration-none">
            <i class="fa-solid fa-arrow-left me-1"></i>Relatórios
          </a>
          <span class="navbar-brand mb-0 h6">Lançamentos Pagos</span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="small text-muted"><?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?></span>
          <a class="btn btn-sm btn-outline-danger" href="<?= $__rel ?>logout.php">
            <i class="fa-solid fa-right-from-bracket me-1"></i>Sair
          </a>
        </div>
      </nav>

      <div class="container-fluid py-3">

        <div class="d-flex justify-content-between align-items-end mb-3">
          <div>
            <h1 class="page-title">Lançamentos — Contas a Pagar</h1>
            <div class="small text-muted" style="font-size:.78rem;">Monte filtros e exporte/imprima o relatório.</div>
          </div>
        </div>

        <!-- Filtros -->
        <div class="filtros-card mb-3 no-print">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label small mb-1">Banco</label>
              <select id="fBanco" class="form-select form-select-sm">
                <option value="0">— Todos —</option>
                <option value="-1">— Sem banco associado —</option>
                <?php foreach ($bancos as $b): ?>
                  <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-1">Status</label>
              <select id="fStatus" class="form-select form-select-sm">
                <option value="PAGO" selected>Pago</option>
                <option value="ABERTO">Em aberto</option>
                <option value="ATRASADO">Atrasado</option>
                <option value="CANCELADO">Cancelado</option>
                <option value="TODOS">Todos</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-1">Empresa</label>
              <select id="fEmpresa" class="form-select form-select-sm">
                <option value="0">— Todas —</option>
                <?php foreach ($empresas as $e): ?>
                  <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-1">Plano de contas (Despesa)</label>
              <select id="fPlano" class="form-select form-select-sm">
                <option value="0">— Todos —</option>
                <?php foreach ($planos as $p): ?>
                  <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-1">Data inicial</label>
              <input id="fDtIni" type="date" class="form-control form-control-sm">
            </div>

            <div class="col-md-2">
              <label class="form-label small mb-1">Data final</label>
              <input id="fDtFim" type="date" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-1">Valor mín. (R$)</label>
              <input id="fValMin" class="form-control form-control-sm text-end" placeholder="0,00">
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-1">Valor máx. (R$)</label>
              <input id="fValMax" class="form-control form-control-sm text-end" placeholder="0,00">
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-1">Buscar (descrição/documento/fornecedor)</label>
              <input id="fBusca" class="form-control form-control-sm" placeholder="Texto livre">
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
              <button id="btnFiltrar" class="btn btn-primary btn-sm flex-grow-1">
                <i class="fa-solid fa-filter me-1"></i>Aplicar
              </button>
              <button id="btnLimpar" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-eraser"></i>
              </button>
            </div>
          </div>

          <hr class="my-3">
          <div class="d-flex flex-wrap gap-2">
            <button id="btnCsv" class="btn btn-success btn-sm">
              <i class="fa-solid fa-file-csv me-1"></i>Exportar CSV
            </button>
            <button id="btnXlsx" class="btn btn-success btn-sm">
              <i class="fa-solid fa-file-excel me-1"></i>Exportar Excel (XLS)
            </button>
            <button id="btnImprimir" class="btn btn-outline-dark btn-sm">
              <i class="fa-solid fa-print me-1"></i>Imprimir
            </button>
          </div>
        </div>

        <!-- Resumo -->
        <div class="row g-2 mb-3">
          <div class="col-md-3">
            <div class="resumo-card">
              <div class="text-muted small text-uppercase">Lançamentos</div>
              <div class="fw-bold fs-5" id="resumoQtd">—</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="resumo-card">
              <div class="text-muted small text-uppercase">Valor total</div>
              <div class="fw-bold fs-5 text-primary" id="resumoTotal">R$ 0,00</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="resumo-card text-muted small" id="resumoFiltros">Sem filtros aplicados.</div>
          </div>
        </div>

        <!-- Tabela -->
        <div class="card">
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Data Pagto</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th>Parc.</th>
                    <th>Banco</th>
                    <th>Empresa</th>
                    <th>Fornecedor</th>
                    <th>Plano</th>
                    <th>Documento</th>
                    <th>Descrição</th>
                    <th class="text-end">Valor (R$)</th>
                  </tr>
                </thead>
                <tbody id="tbody">
                  <tr><td colspan="12" class="text-center text-muted py-4">Use os filtros e clique em <b>Aplicar</b>.</td></tr>
                </tbody>
                <tfoot>
                  <tr class="total-row" id="totalRow" style="display:none;">
                    <td colspan="11" class="text-end">TOTAL</td>
                    <td class="text-end mono" id="totalRowVal">R$ 0,00</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>

        <!-- Paginação -->
        <div class="d-flex justify-content-between align-items-center mt-3 no-print" id="paginacaoBox" style="display:none;">
          <div class="small text-muted" id="paginacaoInfo"></div>
          <div class="d-flex gap-1">
            <button id="btnPrev" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-chevron-left"></i></button>
            <button id="btnNext" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-chevron-right"></i></button>
          </div>
        </div>

      </div>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const apiUrl = location.pathname; // mesmo arquivo

    const el  = id => document.getElementById(id);
    const fmt = n => Number(n||0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function getFiltros() {
      return {
        banco_id:      el('fBanco').value,
        status:        el('fStatus').value,
        empresa_id:    el('fEmpresa').value,
        plano_id:      el('fPlano').value,
        dt_ini:        el('fDtIni').value,
        dt_fim:        el('fDtFim').value,
        valor_min:     el('fValMin').value,
        valor_max:     el('fValMax').value,
        q:             el('fBusca').value,
      };
    }

    function buildQS(extra={}) {
      const f = Object.assign(getFiltros(), extra);
      return Object.entries(f)
        .filter(([k,v]) => v !== '' && v !== null && v !== undefined && v !== '0')
        .map(([k,v]) => `${k}=${encodeURIComponent(v)}`)
        .join('&');
    }

    let pageAtual = 1;
    const perPage = 50;

    async function carregar(pagina = 1) {
      pageAtual = pagina;
      const tbody = el('tbody');
      tbody.innerHTML = '<tr><td colspan="12" class="text-center py-4"><i class="fa-solid fa-spinner fa-spin"></i> Carregando...</td></tr>';
      const qs = buildQS({ acao: 'listar', page: pagina, per: perPage });
      try {
        const r = await fetch(apiUrl + '?' + qs, { credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) {
          tbody.innerHTML = `<tr><td colspan="12" class="text-center text-danger py-4">${j.msg || 'Erro'}</td></tr>`;
          return;
        }
        renderTabela(j.rows || []);
        renderResumo(j);
      } catch (e) {
        tbody.innerHTML = `<tr><td colspan="12" class="text-center text-danger py-4">Erro de rede.</td></tr>`;
        console.error(e);
      }
    }

    function badgeStatus(s) {
      const m = {
        PAGO:      'success',
        ABERTO:    'warning',
        ATRASADO:  'danger',
        CANCELADO: 'secondary'
      };
      const cls = m[String(s||'').toUpperCase()] || 'secondary';
      return `<span class="badge bg-${cls} badge-status">${s||'—'}</span>`;
    }

    function dmy(d) {
      if (!d) return '—';
      const s = String(d).slice(0,10);
      const [y,m,dd] = s.split('-');
      return (dd && m && y) ? `${dd}/${m}/${y}` : s;
    }

    function renderTabela(rows) {
      const tbody = el('tbody');
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4">Nenhum lançamento encontrado.</td></tr>';
        el('totalRow').style.display = 'none';
        return;
      }
      const html = rows.map(r => {
        const parc = (r.num_parcela && r.qtd_parcelas) ? `${r.num_parcela}/${r.qtd_parcelas}` : '—';
        return `<tr>
          <td class="mono">${r.id}</td>
          <td class="mono">${dmy(r.data_pagamento)}</td>
          <td class="mono">${dmy(r.vencimento)}</td>
          <td>${badgeStatus(r.status)}</td>
          <td class="mono small">${parc}</td>
          <td>${r.banco_nome || '—'}</td>
          <td class="small">${r.empresa_nome || '—'}</td>
          <td class="small">${r.fornecedor_nome || '—'}</td>
          <td class="small text-muted">${r.plano_nome || '—'}</td>
          <td class="mono small">${r.documento || ''}</td>
          <td class="small">${r.descricao || ''}</td>
          <td class="text-end mono fw-bold">R$ ${fmt(r.valor_relevante)}</td>
        </tr>`;
      }).join('');
      tbody.innerHTML = html;
    }

    function renderResumo(j) {
      el('resumoQtd').textContent = (j.total || 0).toLocaleString('pt-BR') + ' lançamento(s)';
      el('resumoTotal').textContent = 'R$ ' + fmt(j.soma || 0);
      el('totalRowVal').textContent = 'R$ ' + fmt(j.soma || 0);
      el('totalRow').style.display = '';
      el('paginacaoBox').style.display = '';

      const totPages = Math.max(1, Math.ceil((j.total||0) / perPage));
      el('paginacaoInfo').textContent = `Página ${j.page} de ${totPages}`;
      el('btnPrev').disabled = (j.page <= 1);
      el('btnNext').disabled = (j.page >= totPages);

      // Resumo dos filtros aplicados
      const f = getFiltros();
      const labels = [];
      if (f.status) labels.push('Status: ' + f.status);
      if (f.banco_id !== '0' && f.banco_id) labels.push('Banco: ' + el('fBanco').options[el('fBanco').selectedIndex].text);
      if (f.empresa_id !== '0' && f.empresa_id) labels.push('Empresa: ' + el('fEmpresa').options[el('fEmpresa').selectedIndex].text);
      if (f.plano_id !== '0' && f.plano_id) labels.push('Plano: ' + el('fPlano').options[el('fPlano').selectedIndex].text);
      if (f.dt_ini) labels.push('De: ' + dmy(f.dt_ini));
      if (f.dt_fim) labels.push('Até: ' + dmy(f.dt_fim));
      if (f.valor_min) labels.push('Mín: R$ ' + f.valor_min);
      if (f.valor_max) labels.push('Máx: R$ ' + f.valor_max);
      if (f.q) labels.push('Busca: ' + f.q);
      el('resumoFiltros').textContent = labels.length ? labels.join(' • ') : 'Sem filtros aplicados.';
    }

    el('btnFiltrar').onclick = () => carregar(1);
    el('btnLimpar').onclick  = () => {
      ['fBanco','fStatus','fEmpresa','fPlano','fDtIni','fDtFim','fValMin','fValMax','fBusca'].forEach(id => {
        const e = el(id);
        if (e.tagName === 'SELECT') e.selectedIndex = 0;
        else e.value = '';
      });
      el('fStatus').value = 'PAGO';
      carregar(1);
    };
    el('btnPrev').onclick = () => carregar(pageAtual - 1);
    el('btnNext').onclick = () => carregar(pageAtual + 1);
    el('btnCsv').onclick  = () => { window.location.href = apiUrl + '?' + buildQS({ acao:'export', fmt:'csv'  }); };
    el('btnXlsx').onclick = () => { window.location.href = apiUrl + '?' + buildQS({ acao:'export', fmt:'xlsx' }); };
    el('btnImprimir').onclick = () => window.print();

    // Carga inicial: mês corrente, status PAGO
    (function preset() {
      const hoje = new Date();
      const ini = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
      const fim = new Date(hoje.getFullYear(), hoje.getMonth()+1, 0);
      el('fDtIni').value = ini.toISOString().slice(0,10);
      el('fDtFim').value = fim.toISOString().slice(0,10);
      carregar(1);
    })();

    // Submit por Enter nos filtros
    document.querySelectorAll('input,select').forEach(e => {
      e.addEventListener('keydown', ev => { if (ev.key === 'Enter') carregar(1); });
    });
  </script>
  <script src="<?= $__rel ?>assets/session_keeper.js" defer></script>
</body>
</html>
