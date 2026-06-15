<?php
// /app/relatorios/pendencias_conciliacao.php
// Relatório de pendências de conciliação bancária por período.
// Lista os movimentos OFX que AINDA precisam ser conciliados (mesmo critério
// das telas de conciliação: não conciliados e que não são internos —
// transferência interna, aplicação/resgate, tarifa, rendimento).
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexao.php';

$__rel = '../';

// Data inicial padrão = 17/04/2026 (acerto de saldos). Editável no filtro.
const PENDREL_DATA_BASE = '2026-04-17';

// Naturezas internas resolvidas automaticamente (não entram como pendência).
const PENDREL_NATUREZAS_INTERNAS = "'TRANSFERENCIA_INTERNA','APLICACAO','TARIFA','RENDIMENTO'";

function pr_int($v): int { return (int)($v ?? 0); }
function pr_str($v): string { return trim((string)($v ?? '')); }
function pr_date_ymd($v): ?string {
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    return null;
}
function pr_json_out(array $payload, int $code = 200): void {
    while (function_exists('ob_get_level') && ob_get_level() > 0) @ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// Build da query a partir dos filtros (compartilhado: listar + export)
// ====================================================================
function pr_buildQuery(array $f): array {
    $where = [
        "COALESCE(m.COM_CONCILIADO,'NAO') <> 'SIM'",
        "COALESCE(m.COM_NATUREZA,'NORMAL') NOT IN (" . PENDREL_NATUREZAS_INTERNAS . ")",
    ];
    $params = [];

    // Período (sobre a data do movimento). Default na inicial = acerto de saldos.
    $dtIni = pr_date_ymd($f['dt_ini'] ?? '') ?? PENDREL_DATA_BASE;
    $dtFim = pr_date_ymd($f['dt_fim'] ?? '');
    $where[] = "m.COM_DATA_MOVIMENTO >= ?"; $params[] = $dtIni;
    if ($dtFim) { $where[] = "m.COM_DATA_MOVIMENTO <= ?"; $params[] = $dtFim; }

    // Banco
    $bancoId = pr_int($f['banco_id'] ?? 0);
    if ($bancoId > 0) { $where[] = "m.COM_BANCO_FK = ?"; $params[] = $bancoId; }

    // Tipo (DEBITO/CREDITO)
    $tipo = strtoupper(pr_str($f['tipo'] ?? ''));
    if (in_array($tipo, ['DEBITO', 'CREDITO'], true)) { $where[] = "m.COM_TIPO = ?"; $params[] = $tipo; }

    // Texto livre (descrição/documento)
    $q = pr_str($f['q'] ?? '');
    if ($q !== '') {
        $where[] = "(m.COM_DESCRICAO LIKE ? OR m.COM_DOCUMENTO LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like;
    }

    $sqlBase = "
        FROM tb_conciliacao_ofx_movimento m
        LEFT JOIN tb_banco b ON b.BAN_ID = m.COM_BANCO_FK
        WHERE " . implode("\n          AND ", $where);

    return [$sqlBase, $params, $dtIni, $dtFim];
}

$acao = $_GET['acao'] ?? '';

// ====================================================================
// AJAX: listar
// ====================================================================
if ($acao === 'listar') {
    try {
        [$sqlBase, $params] = pr_buildQuery($_GET);

        // Totais (qtd + soma de saídas e entradas)
        $sqlTot = "
            SELECT
              COUNT(*) AS qtd,
              COALESCE(SUM(CASE WHEN m.COM_TIPO='DEBITO'  THEN ABS(m.COM_VALOR) ELSE 0 END),0) AS total_deb,
              COALESCE(SUM(CASE WHEN m.COM_TIPO='CREDITO' THEN ABS(m.COM_VALOR) ELSE 0 END),0) AS total_cred
            $sqlBase";
        $st = $pdo->prepare($sqlTot);
        $st->execute($params);
        $tot = $st->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total_deb' => 0, 'total_cred' => 0];

        $sqlList = "
            SELECT
              m.COM_CODIGO_PK   AS id,
              m.COM_DATA_MOVIMENTO AS data_mov,
              m.COM_TIPO        AS tipo,
              m.COM_VALOR       AS valor,
              m.COM_DESCRICAO   AS descricao,
              m.COM_DOCUMENTO   AS documento,
              m.COM_CONCILIADO  AS conciliado,
              COALESCE(NULLIF(b.BAN_APELIDO,''), b.BAN_NOME) AS banco_nome
            $sqlBase
            ORDER BY m.COM_DATA_MOVIMENTO ASC, m.COM_CODIGO_PK ASC
        ";
        $st = $pdo->prepare($sqlList);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        pr_json_out([
            'ok' => true,
            'rows' => $rows,
            'total' => (int)$tot['qtd'],
            'total_deb' => (float)$tot['total_deb'],
            'total_cred' => (float)$tot['total_cred'],
        ]);
    } catch (Throwable $e) {
        pr_json_out(['ok' => false, 'msg' => 'Erro ao consultar.', 'detail' => $e->getMessage()], 500);
    }
}

// ====================================================================
// EXPORT CSV
// ====================================================================
if ($acao === 'export') {
    try {
        [$sqlBase, $params] = pr_buildQuery($_GET);
        $sqlAll = "
            SELECT
              m.COM_CODIGO_PK   AS id,
              m.COM_DATA_MOVIMENTO AS data_mov,
              m.COM_TIPO        AS tipo,
              m.COM_VALOR       AS valor,
              m.COM_DESCRICAO   AS descricao,
              m.COM_DOCUMENTO   AS documento,
              COALESCE(NULLIF(b.BAN_APELIDO,''), b.BAN_NOME) AS banco_nome
            $sqlBase
            ORDER BY b.BAN_APELIDO ASC, m.COM_DATA_MOVIMENTO ASC
        ";
        $st = $pdo->prepare($sqlAll);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $stamp = date('Ymd_His');
        $headers = ['#', 'Data', 'Banco', 'Tipo', 'Documento', 'Descrição', 'Valor (R$)'];
        $totalDeb = 0.0; $totalCred = 0.0;

        while (function_exists('ob_get_level') && ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="pendencias_conciliacao_' . $stamp . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, ';');
        foreach ($rows as $r) {
            $v = (float)$r['valor'];
            if ($r['tipo'] === 'DEBITO') $totalDeb += abs($v); else $totalCred += abs($v);
            fputcsv($out, [
                $r['id'],
                $r['data_mov'] ? date('d/m/Y', strtotime((string)$r['data_mov'])) : '',
                $r['banco_nome'] ?? '',
                $r['tipo'] === 'DEBITO' ? 'Débito (saída)' : 'Crédito (entrada)',
                (string)$r['documento'],
                (string)$r['descricao'],
                number_format(abs($v), 2, ',', '.'),
            ], ';');
        }
        fputcsv($out, ['', '', '', '', '', 'TOTAL SAÍDAS', number_format($totalDeb, 2, ',', '.')], ';');
        fputcsv($out, ['', '', '', '', '', 'TOTAL ENTRADAS', number_format($totalCred, 2, ',', '.')], ';');
        fclose($out);
        exit;
    } catch (Throwable $e) {
        pr_json_out(['ok' => false, 'msg' => 'Erro ao exportar.', 'detail' => $e->getMessage()], 500);
    }
}

// ====================================================================
// HTML
// ====================================================================
$bancos = $pdo->query("SELECT BAN_ID AS id, COALESCE(NULLIF(BAN_APELIDO,''), BAN_NOME) AS nome FROM tb_banco WHERE BAN_STATUS='ATIVO' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <title>Relatório • Pendências de Conciliação</title>
  <?php include __DIR__ . '/../includes/head.php'; ?>
  <style>
    body { background:#f4f6f9; font-family:'Inter',system-ui,sans-serif; }
    .page-title { font-size:1.3rem; font-weight:700; color:#0f172a; margin:0; }
    .filtros-card { background:#fff; border:1px solid #e5e7eb; border-radius:.75rem; padding:1rem; }
    .resumo-card { background:#fff; border:1px solid #e5e7eb; border-radius:.75rem; padding:.85rem 1rem; }
    .table thead th { font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; color:#475569; background:#f8fafc; }
    .table tbody td { font-size:.85rem; vertical-align:middle; }
    .mono { font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; }
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
          <span class="navbar-brand mb-0 h6">Pendências de Conciliação</span>
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
            <h1 class="page-title">Pendências de Conciliação por período</h1>
            <div class="small text-muted" style="font-size:.78rem;">
              Movimentos do banco (OFX) que ainda precisam ser conciliados. Não inclui internos
              (transferência, aplicação/resgate, tarifa, rendimento). Período inicia em
              <b><?= date('d/m/Y', strtotime(PENDREL_DATA_BASE)) ?></b> por padrão — ajuste se quiser.
            </div>
          </div>
        </div>

        <!-- Filtros -->
        <div class="filtros-card mb-3 no-print">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label small mb-1">Banco</label>
              <select id="fBanco" class="form-select form-select-sm">
                <option value="0">— Todos —</option>
                <?php foreach ($bancos as $b): ?>
                  <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-1">Tipo</label>
              <select id="fTipo" class="form-select form-select-sm">
                <option value="">— Todos —</option>
                <option value="DEBITO">Débito (saída)</option>
                <option value="CREDITO">Crédito (entrada)</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-1">Data inicial</label>
              <input id="fDtIni" type="date" class="form-control form-control-sm" value="<?= PENDREL_DATA_BASE ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label small mb-1">Data final</label>
              <input id="fDtFim" type="date" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-1">Buscar (descrição/documento)</label>
              <input id="fBusca" class="form-control form-control-sm" placeholder="Texto livre">
            </div>
            <div class="col-12 d-flex gap-2 mt-1">
              <button id="btnFiltrar" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-filter me-1"></i>Aplicar
              </button>
              <button id="btnLimpar" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-eraser me-1"></i>Limpar
              </button>
              <button id="btnCsv" class="btn btn-success btn-sm ms-auto">
                <i class="fa-solid fa-file-csv me-1"></i>Exportar CSV
              </button>
              <button id="btnImprimir" class="btn btn-outline-dark btn-sm">
                <i class="fa-solid fa-print me-1"></i>Imprimir
              </button>
            </div>
          </div>
        </div>

        <!-- Resumo -->
        <div class="row g-2 mb-3">
          <div class="col-md-3">
            <div class="resumo-card">
              <div class="text-muted small text-uppercase">Pendências</div>
              <div class="fw-bold fs-5" id="resumoQtd">—</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="resumo-card">
              <div class="text-muted small text-uppercase">Total saídas (débitos)</div>
              <div class="fw-bold fs-5 text-danger" id="resumoDeb">R$ 0,00</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="resumo-card">
              <div class="text-muted small text-uppercase">Total entradas (créditos)</div>
              <div class="fw-bold fs-5 text-primary" id="resumoCred">R$ 0,00</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="resumo-card text-muted small" id="resumoFiltros">A partir de <?= date('d/m/Y', strtotime(PENDREL_DATA_BASE)) ?>.</div>
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
                    <th>Data</th>
                    <th>Banco</th>
                    <th>Tipo</th>
                    <th>Documento</th>
                    <th>Descrição</th>
                    <th class="text-end">Valor (R$)</th>
                  </tr>
                </thead>
                <tbody id="tbody">
                  <tr><td colspan="7" class="text-center text-muted py-4">Clique em <b>Aplicar</b> para gerar.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const apiUrl = location.pathname;
    const el  = id => document.getElementById(id);
    const fmt = n => Number(n||0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function getFiltros() {
      return {
        banco_id: el('fBanco').value,
        tipo:     el('fTipo').value,
        dt_ini:   el('fDtIni').value,
        dt_fim:   el('fDtFim').value,
        q:        el('fBusca').value,
      };
    }
    function buildQS(extra={}) {
      const f = Object.assign(getFiltros(), extra);
      return Object.entries(f)
        .filter(([k,v]) => v !== '' && v !== null && v !== undefined)
        .map(([k,v]) => `${k}=${encodeURIComponent(v)}`)
        .join('&');
    }
    function dmy(d) {
      if (!d) return '—';
      const s = String(d).slice(0,10);
      const [y,m,dd] = s.split('-');
      return (dd && m && y) ? `${dd}/${m}/${y}` : s;
    }
    function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function badgeTipo(t) {
      return t === 'DEBITO'
        ? '<span class="badge bg-danger-subtle text-danger">Débito</span>'
        : '<span class="badge bg-primary-subtle text-primary">Crédito</span>';
    }

    function renderTabela(rows) {
      const tbody = el('tbody');
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-success py-4"><i class="fa-solid fa-circle-check me-1"></i>Nenhuma pendência no período. Tudo conciliado!</td></tr>';
        return;
      }
      tbody.innerHTML = rows.map(r => `
        <tr>
          <td class="mono">${r.id}</td>
          <td class="mono">${dmy(r.data_mov)}</td>
          <td>${esc(r.banco_nome || '—')}</td>
          <td>${badgeTipo(r.tipo)}</td>
          <td class="small">${esc(r.documento || '—')}</td>
          <td class="small">${esc(r.descricao || '—')}</td>
          <td class="text-end mono ${r.tipo==='DEBITO'?'text-danger':'text-primary'}">${r.tipo==='DEBITO'?'-':'+'} R$ ${fmt(Math.abs(r.valor))}</td>
        </tr>`).join('');
    }

    function renderResumo(j) {
      el('resumoQtd').textContent = j.total || 0;
      el('resumoDeb').textContent = 'R$ ' + fmt(j.total_deb);
      el('resumoCred').textContent = 'R$ ' + fmt(j.total_cred);
      const di = el('fDtIni').value, df = el('fDtFim').value;
      el('resumoFiltros').textContent = 'Período: ' + (di ? dmy(di) : '—') + (df ? ' até ' + dmy(df) : ' em diante');
    }

    async function carregar() {
      const tbody = el('tbody');
      tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><i class="fa-solid fa-spinner fa-spin"></i> Carregando...</td></tr>';
      try {
        const r = await fetch(apiUrl + '?' + buildQS({ acao: 'listar' }), { credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) { tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4">${esc(j.msg||'Erro')}</td></tr>`; return; }
        renderTabela(j.rows || []);
        renderResumo(j);
      } catch (e) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Erro de rede.</td></tr>';
      }
    }

    el('btnFiltrar').addEventListener('click', carregar);
    el('btnCsv').addEventListener('click', () => { location.href = apiUrl + '?' + buildQS({ acao: 'export' }); });
    el('btnImprimir').addEventListener('click', () => window.print());
    el('btnLimpar').addEventListener('click', () => {
      el('fBanco').value = '0'; el('fTipo').value = ''; el('fDtIni').value = '<?= PENDREL_DATA_BASE ?>';
      el('fDtFim').value = ''; el('fBusca').value = ''; carregar();
    });
    document.querySelectorAll('#fBanco,#fTipo').forEach(s => s.addEventListener('change', carregar));
    el('fBusca').addEventListener('keydown', e => { if (e.key === 'Enter') carregar(); });

    carregar(); // gera já com o período padrão
  </script>
</body>
</html>
