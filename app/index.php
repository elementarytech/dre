<?php
// /app/index.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php'; // protege a página (sessão)
require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/status_dict.php';

// ===== DB helpers =====
function db(): PDO
{
    if (class_exists('conexao') && method_exists('conexao', 'getInstance')) {
        $db = conexao::getInstance();
        if ($db instanceof PDO) return $db;
    }
    foreach (['getPDO', 'pdo'] as $fn) {
        if (function_exists($fn)) {
            $db = $fn();
            if ($db instanceof PDO) return $db;
        }
    }
    foreach (['pdo', 'db', 'conn'] as $var) {
        if (isset($GLOBALS[$var]) && $GLOBALS[$var] instanceof PDO) {
            return $GLOBALS[$var];
        }
    }
    throw new RuntimeException('Conexão com o banco não disponível.');
}

function table_exists(PDO $db, string $table): bool
{
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

// ===== Resolução de período =====
// Suporta: mes_atual (default), mes_anterior, ultimos_30, ano_atual
$periodoSel = $_GET['periodo'] ?? 'mes_atual';
$periodosValidos = ['mes_atual', 'mes_anterior', 'ultimos_30', 'ano_atual'];
if (!in_array($periodoSel, $periodosValidos, true)) $periodoSel = 'mes_atual';

$empresaSel = (int)($_GET['empresa'] ?? 0);

$hoje = (new DateTime('today'))->format('Y-m-d');

switch ($periodoSel) {
    case 'mes_anterior':
        $ini = (new DateTime('first day of last month'))->format('Y-m-d');
        $fim = (new DateTime('last day of last month'))->format('Y-m-d');
        // Período anterior de comparação: 2 meses atrás
        $iniAnt = (new DateTime('first day of -2 months'))->format('Y-m-d');
        $fimAnt = (new DateTime('last day of -2 months'))->format('Y-m-d');
        $labelPeriodo = 'Mês anterior (' . (new DateTime('first day of last month'))->format('m/Y') . ')';
        break;
    case 'ultimos_30':
        $ini = (new DateTime('-29 days'))->format('Y-m-d');
        $fim = $hoje;
        $iniAnt = (new DateTime('-59 days'))->format('Y-m-d');
        $fimAnt = (new DateTime('-30 days'))->format('Y-m-d');
        $labelPeriodo = 'Últimos 30 dias';
        break;
    case 'ano_atual':
        $ini = (new DateTime('first day of January'))->format('Y-m-d');
        $fim = (new DateTime('last day of December'))->format('Y-m-d');
        $iniAnt = (new DateTime('first day of January last year'))->format('Y-m-d');
        $fimAnt = (new DateTime('last day of December last year'))->format('Y-m-d');
        $labelPeriodo = 'Ano atual (' . date('Y') . ')';
        break;
    case 'mes_atual':
    default:
        $ini = (new DateTime('first day of this month'))->format('Y-m-d');
        $fim = (new DateTime('last day of this month'))->format('Y-m-d');
        $iniAnt = (new DateTime('first day of last month'))->format('Y-m-d');
        $fimAnt = (new DateTime('last day of last month'))->format('Y-m-d');
        $labelPeriodo = 'Mês atual (' . (new DateTime('now'))->format('m/Y') . ')';
        break;
}

$db = db();
$hasPagar = table_exists($db, 'tb_contas_pagar');
$hasReceber = table_exists($db, 'tb_contas_receber');
$hasLogSusp = table_exists($db, 'tb_log_suspensao_contratos');
$hasEmpresa = table_exists($db, 'tb_empresa');

// ===== Empresas para combo =====
$empresas = [];
if ($hasEmpresa) {
    $stE = $db->query("SELECT EMP_ID, COALESCE(NULLIF(EMP_NOME_FANTASIA,''), EMP_RAZAO_SOCIAL) AS EMP_NOME
                       FROM tb_empresa
                       WHERE COALESCE(EMP_STATUS,'ATIVO') = 'ATIVO'
                       ORDER BY EMP_NOME");
    $empresas = $stE ? $stE->fetchAll(PDO::FETCH_ASSOC) : [];
}

// ===== Helpers =====
$placeholdersCreAberto = sql_placeholders(CRE_STATUS_EM_ABERTO);
$placeholdersCrePago   = sql_placeholders(CRE_STATUS_PAGO);
$placeholdersCpgAberto = sql_placeholders(CPG_STATUS_EM_ABERTO);
$placeholdersCpgPago   = sql_placeholders(CPG_STATUS_PAGO);

// Snippet de filtro por empresa (opcional)
$whereEmpresaCre = $empresaSel > 0 ? ' AND CRE_EMPRESA_FK = ? ' : '';
$whereEmpresaCpg = $empresaSel > 0 ? ' AND CPG_EMPRESA_FK = ? ' : '';

/**
 * Executa as agregações de RECEBER (valor recebido e em aberto/atrasado) para um período informado.
 */
$aggReceber = function (string $iniP, string $fimP) use ($db, $hasReceber, $placeholdersCreAberto, $placeholdersCrePago, $whereEmpresaCre, $empresaSel, $hoje) {
    $base = ['recebido' => 0.0, 'aberto' => 0.0, 'atrasado' => 0.0];
    if (!$hasReceber) return $base;

    // Usar >= e < DATE_ADD em vez de DATE(col) BETWEEN permite uso de índice.
    $sql = "SELECT
        COALESCE(SUM(CASE WHEN CRE_STATUS IN ({$placeholdersCrePago})
                           AND CRE_RECEBIDO_EM IS NOT NULL
                           AND CRE_RECEBIDO_EM >= ?
                           AND CRE_RECEBIDO_EM <  DATE_ADD(?, INTERVAL 1 DAY)
                     THEN COALESCE(NULLIF(CRE_VALOR_RECEBIDO,0), CRE_VALOR) ELSE 0 END),0) AS recebido,
        COALESCE(SUM(CASE WHEN CRE_STATUS IN ({$placeholdersCreAberto})
                     THEN CRE_VALOR ELSE 0 END),0) AS aberto,
        COALESCE(SUM(CASE WHEN CRE_STATUS IN ({$placeholdersCreAberto}) AND CRE_VENCIMENTO < ?
                     THEN CRE_VALOR ELSE 0 END),0) AS atrasado
        FROM tb_contas_receber
        WHERE 1=1 {$whereEmpresaCre}";

    $params = array_merge(
        CRE_STATUS_PAGO,
        [$iniP, $fimP],
        CRE_STATUS_EM_ABERTO,
        CRE_STATUS_EM_ABERTO,
        [$hoje]
    );
    if ($empresaSel > 0) $params[] = $empresaSel;

    $st = $db->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'recebido' => (float)($r['recebido'] ?? 0),
        'aberto'   => (float)($r['aberto'] ?? 0),
        'atrasado' => (float)($r['atrasado'] ?? 0),
    ];
};

$aggPagar = function (string $iniP, string $fimP) use ($db, $hasPagar, $placeholdersCpgAberto, $placeholdersCpgPago, $whereEmpresaCpg, $empresaSel) {
    $base = ['pago' => 0.0, 'aberto' => 0.0];
    if (!$hasPagar) return $base;

    $sql = "SELECT
        COALESCE(SUM(CASE WHEN CPG_STATUS IN ({$placeholdersCpgPago})
                           AND CPG_DATA_PAGAMENTO IS NOT NULL
                           AND CPG_DATA_PAGAMENTO >= ?
                           AND CPG_DATA_PAGAMENTO <  DATE_ADD(?, INTERVAL 1 DAY)
                     THEN COALESCE(CPG_VALOR_PAGO, CPG_VALOR_PARCELA) ELSE 0 END),0) AS pago,
        COALESCE(SUM(CASE WHEN CPG_STATUS IN ({$placeholdersCpgAberto})
                     THEN CPG_VALOR_PARCELA ELSE 0 END),0) AS aberto
        FROM tb_contas_pagar
        WHERE 1=1 {$whereEmpresaCpg}";

    $params = array_merge(
        CPG_STATUS_PAGO,
        [$iniP, $fimP],
        CPG_STATUS_EM_ABERTO
    );
    if ($empresaSel > 0) $params[] = $empresaSel;

    $st = $db->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'pago'   => (float)($r['pago'] ?? 0),
        'aberto' => (float)($r['aberto'] ?? 0),
    ];
};

// ===== Agregações do período selecionado e do anterior =====
$recAtual = $aggReceber($ini, $fim);
$pagAtual = $aggPagar($ini, $fim);
$recAnt   = $aggReceber($iniAnt, $fimAnt);
$pagAnt   = $aggPagar($iniAnt, $fimAnt);

$receita_mes = $recAtual['recebido'];
$despesa_mes = $pagAtual['pago'];
$contas_receber_aberto = $recAtual['aberto'];
$contas_pagar_aberto = $pagAtual['aberto'];

// ===== Saldo operacional acumulado (soma histórica recebido - pago) =====
$caixa = 0.0;
if ($hasReceber) {
    $sql = "SELECT COALESCE(SUM(COALESCE(NULLIF(CRE_VALOR_RECEBIDO,0), CRE_VALOR)),0)
            FROM tb_contas_receber
            WHERE CRE_STATUS IN ({$placeholdersCrePago}) AND CRE_RECEBIDO_EM IS NOT NULL {$whereEmpresaCre}";
    $params = CRE_STATUS_PAGO;
    if ($empresaSel > 0) $params[] = $empresaSel;
    $st = $db->prepare($sql);
    $st->execute($params);
    $caixa += (float)$st->fetchColumn();
}
if ($hasPagar) {
    $sql = "SELECT COALESCE(SUM(COALESCE(CPG_VALOR_PAGO, CPG_VALOR_PARCELA)),0)
            FROM tb_contas_pagar
            WHERE CPG_STATUS IN ({$placeholdersCpgPago}) AND CPG_DATA_PAGAMENTO IS NOT NULL {$whereEmpresaCpg}";
    $params = CPG_STATUS_PAGO;
    if ($empresaSel > 0) $params[] = $empresaSel;
    $st = $db->prepare($sql);
    $st->execute($params);
    $caixa -= (float)$st->fetchColumn();
}

// ===== DRE simplificado (regime de caixa enquanto não existe CMV/deduções) =====
$receita_bruta = $receita_mes;
$deducoes = 0.0;
$receita_liquida = max(0.0, $receita_bruta - $deducoes);
$cmv = 0.0;
$temCmv = $cmv > 0.0;
$temDeducoes = $deducoes > 0.0;
$lucro_bruto = $receita_liquida - $cmv;
$despesas_oper = $despesa_mes;
$ebitda = $lucro_bruto - $despesas_oper;
$resultado_liquido = $ebitda;
$margem_liquida = ($receita_liquida > 0.0) ? ($resultado_liquido / $receita_liquida) : 0.0;

$receita_bruta_ant = $recAnt['recebido'];
$resultado_liquido_ant = $recAnt['recebido'] - $pagAnt['pago'];

// Delta % entre período atual e anterior
$deltaPct = function (float $atual, float $anterior): ?float {
    if ($anterior == 0.0) {
        return $atual == 0.0 ? 0.0 : null; // null = sem base de comparação
    }
    return ($atual - $anterior) / abs($anterior);
};

$deltaReceita = $deltaPct($receita_bruta, $receita_bruta_ant);
$deltaResultado = $deltaPct($resultado_liquido, $resultado_liquido_ant);
$deltaDespesa = $deltaPct($despesa_mes, $pagAnt['pago']);

$kpis = [
    'receita_bruta'      => $receita_bruta,
    'deducoes'           => $deducoes,
    'receita_liquida'    => $receita_liquida,
    'cmv'                => $cmv,
    'lucro_bruto'        => $lucro_bruto,
    'despesas_oper'      => $despesas_oper,
    'ebitda'             => $ebitda,
    'resultado_liquido'  => $resultado_liquido,
    'margem_liquida'     => $margem_liquida,
    'caixa'              => $caixa,
    'contas_receber'     => $contas_receber_aberto,
    'contas_pagar'       => $contas_pagar_aberto,
];

// ===== Últimos lançamentos (receber + pagar) =====
$ultimos_lancamentos = [];
$limite = 10;
$tmp = [];
if ($hasReceber) {
    $sql = "SELECT
        CRE_ID AS id,
        COALESCE(CRE_RECEBIDO_EM, CRE_VENCIMENTO, CRE_CREATED_AT) AS dt,
        COALESCE(CRE_CLIENTE_NOME,'') AS pessoa,
        COALESCE(CRE_DOCUMENTO,'') AS documento,
        CRE_VALOR AS valor,
        CRE_STATUS AS status
      FROM tb_contas_receber
      WHERE 1=1 {$whereEmpresaCre}
      ORDER BY COALESCE(CRE_RECEBIDO_EM, CRE_VENCIMENTO, CRE_CREATED_AT) DESC, CRE_ID DESC
      LIMIT {$limite}";
    $st = $db->prepare($sql);
    $st->execute($empresaSel > 0 ? [$empresaSel] : []);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tmp[] = [
            'dt' => $r['dt'],
            'data' => $r['dt'] ? (new DateTime($r['dt']))->format('d/m/Y') : '',
            'tipo' => 'Receita',
            'descricao' => trim((($r['pessoa'] ?: 'Cliente')) . (($r['documento']) ? (' • ' . $r['documento']) : '')),
            'valor' => (float)$r['valor'],
            'status' => (string)$r['status'],
        ];
    }
}
if ($hasPagar) {
    $sql = "SELECT
        CPG_CODIGO_PK AS id,
        COALESCE(CPG_DATA_PAGAMENTO, CPG_VENCIMENTO, CPG_DATA_CRIACAO) AS dt,
        COALESCE(CPG_DESCRICAO,'') AS descricao,
        COALESCE(CPG_DOCUMENTO,'') AS documento,
        CPG_VALOR_PARCELA AS valor,
        CPG_STATUS AS status
      FROM tb_contas_pagar
      WHERE 1=1 {$whereEmpresaCpg}
      ORDER BY COALESCE(CPG_DATA_PAGAMENTO, CPG_VENCIMENTO, CPG_DATA_CRIACAO) DESC, CPG_CODIGO_PK DESC
      LIMIT {$limite}";
    $st = $db->prepare($sql);
    $st->execute($empresaSel > 0 ? [$empresaSel] : []);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tmp[] = [
            'dt' => $r['dt'],
            'data' => $r['dt'] ? (new DateTime($r['dt']))->format('d/m/Y') : '',
            'tipo' => 'Despesa',
            'descricao' => trim((($r['descricao'] ?: 'Despesa')) . (($r['documento']) ? (' • ' . $r['documento']) : '')),
            'valor' => (float)$r['valor'],
            'status' => (string)$r['status'],
        ];
    }
}
usort($tmp, function ($a, $b) {
    return strcmp((string)($b['dt'] ?? ''), (string)($a['dt'] ?? ''));
});
$ultimos_lancamentos = array_slice($tmp, 0, $limite);

// ===== Top 5 clientes inadimplentes =====
$topInadimplentes = [];
if ($hasReceber) {
    $sql = "SELECT
                COALESCE(NULLIF(CRE_CLIENTE_NOME,''), 'Cliente não identificado') AS cliente,
                COUNT(*) AS qtd,
                SUM(CRE_VALOR) AS valor
            FROM tb_contas_receber
            WHERE CRE_STATUS IN ({$placeholdersCreAberto})
              AND CRE_VENCIMENTO < ?
              {$whereEmpresaCre}
            GROUP BY COALESCE(NULLIF(CRE_CLIENTE_NOME,''), 'Cliente não identificado')
            ORDER BY valor DESC
            LIMIT 5";
    $params = array_merge(CRE_STATUS_EM_ABERTO, [$hoje]);
    if ($empresaSel > 0) $params[] = $empresaSel;
    $st = $db->prepare($sql);
    $st->execute($params);
    $topInadimplentes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ===== Alertas =====
$alertas = [];

if ($hasPagar) {
    $sql = "SELECT COUNT(*) FROM tb_contas_pagar
            WHERE CPG_STATUS IN ({$placeholdersCpgAberto})
              AND CPG_VENCIMENTO BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
              {$whereEmpresaCpg}";
    $params = array_merge(CPG_STATUS_EM_ABERTO, [$hoje, $hoje]);
    if ($empresaSel > 0) $params[] = $empresaSel;
    $st = $db->prepare($sql);
    $st->execute($params);
    $qtd = (int)$st->fetchColumn();
    if ($qtd > 0) {
        $alertas[] = ['titulo' => 'Contas a pagar vencendo', 'texto' => $qtd . ' conta(s) vencem nos próximos 7 dias.', 'tipo' => 'warn'];
    }
}

if ($hasReceber) {
    $sql = "SELECT COUNT(*) FROM tb_contas_receber
            WHERE CRE_STATUS IN ({$placeholdersCreAberto})
              AND CRE_VENCIMENTO < ?
              {$whereEmpresaCre}";
    $params = array_merge(CRE_STATUS_EM_ABERTO, [$hoje]);
    if ($empresaSel > 0) $params[] = $empresaSel;
    $st = $db->prepare($sql);
    $st->execute($params);
    $qtd = (int)$st->fetchColumn();
    if ($qtd > 0) {
        $alertas[] = ['titulo' => 'Recebíveis em atraso', 'texto' => $qtd . ' título(s) estão vencidos.', 'tipo' => 'danger'];
    }
}

if ($hasPagar) {
    $sql = "SELECT COUNT(*) FROM tb_contas_pagar
            WHERE CPG_STATUS IN ({$placeholdersCpgAberto})
              AND COALESCE(CPG_AUTORIZACAO_STATUS,'PENDENTE')='PENDENTE'
              {$whereEmpresaCpg}";
    $params = CPG_STATUS_EM_ABERTO;
    if ($empresaSel > 0) $params[] = $empresaSel;
    $st = $db->prepare($sql);
    $st->execute($params);
    $qtd = (int)$st->fetchColumn();
    if ($qtd > 0) {
        $alertas[] = ['titulo' => 'Aguardando liberação', 'texto' => $qtd . ' conta(s) a pagar pendentes de autorização.', 'tipo' => 'warn'];
    }
}

if ($hasLogSusp) {
    $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(LOG_REMOVER_PARCELAS='SIM'),0)
                          FROM tb_log_suspensao_contratos
                         WHERE LOG_DATA >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $st->execute();
    $row = $st->fetch(PDO::FETCH_NUM);
    $qtdSusp = (int)($row[0] ?? 0);
    $qtdSuspRemov = (int)($row[1] ?? 0);
    if ($qtdSusp > 0) {
        $txt = $qtdSusp . ' contrato(s) suspenso(s) nos últimos 30 dias';
        if ($qtdSuspRemov > 0) $txt .= ' — ' . $qtdSuspRemov . ' com remoção de parcelas';
        $txt .= '.';
        $alertas[] = ['titulo' => 'Suspensões recentes', 'texto' => $txt, 'tipo' => 'warn'];
    }
}

// Meta de margem só faz sentido quando há CMV ou deduções reais
if ($receita_liquida > 0 && ($temCmv || $temDeducoes) && $margem_liquida >= 0.30) {
    $alertas[] = ['titulo' => 'Meta de margem', 'texto' => 'Margem líquida acima de 30% no período.', 'tipo' => 'ok'];
}

if (!$alertas) {
    $alertas[] = ['titulo' => 'Sem alertas', 'texto' => 'Nenhum alerta importante no momento.', 'tipo' => 'ok'];
}

$fmtMoney = fn(float $v) => 'R$ ' . number_format($v, 2, ',', '.');
$fmtPct   = fn(float $v) => number_format($v * 100, 2, ',', '.') . '%';

/**
 * Renderiza o trecho HTML do delta (↑ 12,3% / ↓ 4,5% / —).
 */
$renderDelta = function (?float $delta) {
    if ($delta === null) {
        return '<span class="delta delta-neutral" title="Sem base de comparação">—</span>';
    }
    if (abs($delta) < 0.0001) {
        return '<span class="delta delta-neutral">0,00%</span>';
    }
    $up = $delta > 0;
    $icon = $up ? 'fa-arrow-up' : 'fa-arrow-down';
    $cls = $up ? 'delta-up' : 'delta-down';
    $txt = number_format(abs($delta) * 100, 2, ',', '.') . '%';
    return '<span class="delta ' . $cls . '"><i class="fa-solid ' . $icon . '"></i> ' . $txt . '</span>';
};

$ultimaAtualizacao = (new DateTime('now'))->format('d/m/Y H:i:s');
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Dashboard</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        :root { --radius: .875rem; --border: #e5e7eb; }
        body { background: #f4f6f9; }

        /* KPI cards - linha única */
        .kpi-strip { display: flex; flex-wrap: nowrap; gap: .625rem; margin-bottom: 1rem; }

        .kpi-card {
            flex: 1 1 0; min-width: 0;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 1px 3px rgba(15,23,42,.05);
            padding: .85rem 1rem;
            position: relative;
            overflow: hidden;
            transition: box-shadow .2s, transform .2s;
        }
        .kpi-card:hover { box-shadow: 0 4px 16px rgba(15,23,42,.08); transform: translateY(-1px); }

        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.blue::before   { background:#2563eb; }
        .kpi-card.green::before  { background:#16a34a; }
        .kpi-card.red::before    { background:#dc2626; }
        .kpi-card.amber::before  { background:#d97706; }
        .kpi-card.purple::before { background:#7c3aed; }
        .kpi-card.cyan::before   { background:#0891b2; }
        .kpi-card.gray::before   { background:#94a3b8; }

        .kpi-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; }
        .kpi-icon.blue   { background:#eff6ff; color:#2563eb; }
        .kpi-icon.green  { background:#f0fdf4; color:#16a34a; }
        .kpi-icon.red    { background:#fef2f2; color:#dc2626; }
        .kpi-icon.amber  { background:#fff7ed; color:#d97706; }
        .kpi-icon.purple { background:#faf5ff; color:#7c3aed; }
        .kpi-icon.cyan   { background:#ecfeff; color:#0891b2; }
        .kpi-icon.gray   { background:#f1f5f9; color:#94a3b8; }

        .kpi-label { font-size:.66rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; margin-bottom:.15rem; }
        .kpi-val   { font-size:1.1rem; font-weight:800; color:#0f172a; line-height:1.15; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .kpi-val.muted { color:#94a3b8; }
        .kpi-sub   { font-size:.7rem; color:#94a3b8; margin-top:.15rem; }

        .delta { font-size:.68rem; font-weight:700; padding:.1rem .35rem; border-radius:999px; }
        .delta-up { color:#15803d; background:rgba(22,163,74,.1); }
        .delta-down { color:#b91c1c; background:rgba(239,68,68,.1); }
        .delta-neutral { color:#64748b; background:#f1f5f9; }

        @media (max-width:991.98px) { .kpi-strip { flex-wrap:wrap; } .kpi-card { flex:1 1 calc(50% - .35rem); min-width:calc(50% - .35rem); } }
        @media (max-width:575.98px) { .kpi-card { flex:1 1 100%; } }

        /* Caixa cards */
        .caixa-strip { display:flex; flex-wrap:nowrap; gap:.625rem; margin-bottom:1rem; }
        .caixa-card {
            flex:1 1 0; min-width:0;
            background:#fff; border:1px solid var(--border); border-radius:var(--radius);
            box-shadow:0 1px 3px rgba(15,23,42,.05);
            padding:.85rem 1rem;
            display:flex; align-items:center; gap:.75rem;
            transition: box-shadow .2s, transform .2s;
        }
        .caixa-card:hover { box-shadow:0 4px 16px rgba(15,23,42,.08); transform:translateY(-1px); }

        @media (max-width:767.98px) { .caixa-strip { flex-wrap:wrap; } .caixa-card { flex:1 1 100%; } }

        /* Section cards */
        .dash-card {
            background:#fff; border:1px solid var(--border); border-radius:var(--radius);
            box-shadow:0 1px 3px rgba(15,23,42,.05); padding:1rem;
        }
        .dash-card-title { font-size:.88rem; font-weight:700; color:#0f172a; margin:0; }
        .dash-card-sub   { font-size:.72rem; color:#94a3b8; }

        /* Table */
        .table thead th { font-size:.66rem; letter-spacing:.06em; text-transform:uppercase; color:#64748b; border-bottom:1px solid var(--border); padding:.55rem .5rem; }
        .table tbody td { font-size:.8rem; padding:.5rem; color:#334155; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .table tbody tr:hover { background:#f8fafc; }

        .badge-soft       { background:rgba(37,99,235,.1); color:#1e40af; border-radius:999px; padding:.2rem .5rem; font-size:.68rem; font-weight:600; }
        .badge-soft-red   { background:rgba(239,68,68,.1); color:#b91c1c; border-radius:999px; padding:.2rem .5rem; font-size:.68rem; font-weight:600; }
        .badge-soft-green { background:rgba(22,163,106,.1); color:#15803d; border-radius:999px; padding:.2rem .5rem; font-size:.68rem; font-weight:600; }
        .badge-soft-amber { background:rgba(217,119,6,.1); color:#b45309; border-radius:999px; padding:.2rem .5rem; font-size:.68rem; font-weight:600; }

        .pill { display:inline-flex; align-items:center; gap:.35rem; padding:.3rem .65rem; border-radius:999px; background:#f1f5f9; color:#475569; font-size:.75rem; white-space:nowrap; }

        /* Alert items */
        .alert-item { padding:.65rem .75rem; border-radius:10px; border:1px solid #f1f5f9; margin-bottom:.4rem; transition:background .15s; }
        .alert-item:hover { background:#f8fafc; }
        .alert-item:last-child { margin-bottom:0; }
        .alert-item .a-title { font-weight:600; font-size:.82rem; color:#0f172a; }
        .alert-item .a-desc  { font-size:.72rem; color:#64748b; margin-top:1px; }

        .mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }

        .page-title { font-size:1.25rem; font-weight:700; color:#0f172a; margin:0; letter-spacing:-.01em; }

        /* Filter bar */
        .filter-bar {
            background:#fff; border:1px solid var(--border); border-radius:var(--radius);
            padding:.6rem .85rem; margin-bottom:1rem; box-shadow:0 1px 3px rgba(15,23,42,.05);
        }
        .filter-bar label { font-size:.72rem; font-weight:600; color:#6b7280; margin-bottom:.15rem; }
        .filter-bar select { font-size:.82rem; }

        /* Top clientes */
        .top-row { display:flex; align-items:center; justify-content:space-between; padding:.45rem 0; border-bottom:1px dashed #f1f5f9; font-size:.78rem; }
        .top-row:last-child { border-bottom:0; }
        .top-row .nome { color:#0f172a; font-weight:500; max-width:60%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .top-row .val { color:#b91c1c; font-weight:700; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
    </style>
</head>

<body data-page="dashboard">

    <div class="d-flex" id="wrapper">

        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Conteúdo -->
        <div id="page-content-wrapper" class="flex-grow-1">

            <!-- Topbar -->
            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Dashboard DRE</span>

                <div class="collapse navbar-collapse justify-content-end">
                    <div class="d-flex align-items-center gap-2">
                        <span class="pill"><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars($labelPeriodo) ?></span>
                        <span class="pill"><i class="fa-regular fa-circle-user"></i> <?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?></span>
                        <a class="btn btn-sm btn-outline-danger" href="logout.php">
                            <i class="fa-solid fa-right-from-bracket me-1"></i>Sair
                        </a>
                    </div>
                </div>
            </nav>

            <div class="container-fluid py-3">

                <!-- Header -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div>
                        <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">Painel Financeiro</div>
                        <h1 class="page-title">Dashboard DRE</h1>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="pill" title="Última atualização"><i class="fa-regular fa-clock"></i> <?= htmlspecialchars($ultimaAtualizacao) ?></span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnAtualizar" title="Recarregar dashboard">
                            <i class="fa-solid fa-rotate-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Filtros -->
                <form class="filter-bar" method="GET" id="frmFiltros">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="d-block">Período</label>
                            <select class="form-select form-select-sm" name="periodo">
                                <option value="mes_atual"     <?= $periodoSel === 'mes_atual' ? 'selected' : '' ?>>Mês atual</option>
                                <option value="mes_anterior"  <?= $periodoSel === 'mes_anterior' ? 'selected' : '' ?>>Mês anterior</option>
                                <option value="ultimos_30"    <?= $periodoSel === 'ultimos_30' ? 'selected' : '' ?>>Últimos 30 dias</option>
                                <option value="ano_atual"     <?= $periodoSel === 'ano_atual' ? 'selected' : '' ?>>Ano atual</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="d-block">Empresa</label>
                            <select class="form-select form-select-sm" name="empresa">
                                <option value="0">Todas as empresas</option>
                                <?php foreach ($empresas as $e): ?>
                                    <option value="<?= (int)$e['EMP_ID'] ?>" <?= $empresaSel === (int)$e['EMP_ID'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($e['EMP_NOME']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="fa-solid fa-filter me-1"></i>Aplicar
                            </button>
                        </div>
                    </div>
                </form>

                <!-- KPIs DRE - linha única -->
                <div class="kpi-strip">
                    <div class="kpi-card green">
                        <div class="d-flex align-items-center gap-3">
                            <div class="kpi-icon green"><i class="fa-solid fa-arrow-trend-up"></i></div>
                            <div class="flex-grow-1">
                                <div class="kpi-label d-flex justify-content-between align-items-center">
                                    <span>Receita Líquida</span>
                                    <?= $renderDelta($deltaReceita) ?>
                                </div>
                                <div class="kpi-val"><?= $fmtMoney($kpis['receita_liquida']) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php if ($temCmv || $temDeducoes): ?>
                    <div class="kpi-card blue">
                        <div class="d-flex align-items-center gap-3">
                            <div class="kpi-icon blue"><i class="fa-solid fa-coins"></i></div>
                            <div>
                                <div class="kpi-label">Lucro Bruto</div>
                                <div class="kpi-val"><?= $fmtMoney($kpis['lucro_bruto']) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="kpi-card gray" title="Cadastre CMV e deduções para calcular o Lucro Bruto">
                        <div class="d-flex align-items-center gap-3">
                            <div class="kpi-icon gray"><i class="fa-solid fa-coins"></i></div>
                            <div>
                                <div class="kpi-label">Lucro Bruto</div>
                                <div class="kpi-val muted">— <small class="kpi-sub d-block">sem CMV/deduções</small></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="kpi-card purple">
                        <div class="d-flex align-items-center gap-3">
                            <div class="kpi-icon purple"><i class="fa-solid fa-bolt"></i></div>
                            <div class="flex-grow-1">
                                <div class="kpi-label d-flex justify-content-between align-items-center">
                                    <span>EBITDA</span>
                                    <?= $renderDelta($deltaResultado) ?>
                                </div>
                                <div class="kpi-val"><?= $fmtMoney($kpis['ebitda']) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="kpi-card <?= $kpis['resultado_liquido'] >= 0 ? 'green' : 'red' ?>">
                        <div class="d-flex align-items-center gap-3">
                            <div class="kpi-icon <?= $kpis['resultado_liquido'] >= 0 ? 'green' : 'red' ?>"><i class="fa-solid fa-chart-line"></i></div>
                            <div class="flex-grow-1">
                                <div class="kpi-label d-flex justify-content-between align-items-center">
                                    <span>Resultado Líquido</span>
                                    <?= $renderDelta($deltaResultado) ?>
                                </div>
                                <div class="kpi-val"><?= $fmtMoney($kpis['resultado_liquido']) ?></div>
                                <div class="kpi-sub">Margem: <?= $fmtPct($kpis['margem_liquida']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Caixa / Receber / Pagar - linha única -->
                <div class="caixa-strip">
                    <div class="caixa-card">
                        <div class="kpi-icon cyan"><i class="fa-solid fa-vault"></i></div>
                        <div>
                            <div class="kpi-label">Saldo Operacional</div>
                            <div class="kpi-val mono" style="color:<?= $kpis['caixa'] >= 0 ? '#0891b2' : '#dc2626' ?>"><?= $fmtMoney($kpis['caixa']) ?></div>
                            <div class="kpi-sub">Recebido − pago (acumulado)</div>
                        </div>
                    </div>
                    <div class="caixa-card">
                        <div class="kpi-icon green"><i class="fa-solid fa-arrow-down"></i></div>
                        <div>
                            <div class="kpi-label">A Receber</div>
                            <div class="kpi-val mono" style="color:#16a34a"><?= $fmtMoney($kpis['contas_receber']) ?></div>
                            <div class="kpi-sub">Aberto + programado + pendente</div>
                        </div>
                    </div>
                    <div class="caixa-card">
                        <div class="kpi-icon red"><i class="fa-solid fa-arrow-up"></i></div>
                        <div>
                            <div class="kpi-label">A Pagar</div>
                            <div class="kpi-val mono" style="color:#dc2626"><?= $fmtMoney($kpis['contas_pagar']) ?></div>
                            <div class="kpi-sub">Total em aberto</div>
                        </div>
                    </div>
                </div>

                <!-- Alertas + Últimos lançamentos -->
                <div class="row g-3">
                    <div class="col-12 col-xl-4">
                        <div class="dash-card h-100">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <div class="dash-card-title"><i class="fa-solid fa-bell me-1 text-muted"></i>Alertas</div>
                                    <div class="dash-card-sub">Itens que pedem atenção</div>
                                </div>
                                <span class="pill"><?= count($alertas) ?></span>
                            </div>

                            <?php foreach ($alertas as $a): ?>
                                <?php
                                $icon = $a['tipo'] === 'danger' ? 'fa-circle-exclamation' : ($a['tipo'] === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-check');
                                $iconColor = $a['tipo'] === 'danger' ? '#dc2626' : ($a['tipo'] === 'warn' ? '#d97706' : '#16a34a');
                                ?>
                                <div class="alert-item">
                                    <div class="a-title"><i class="fa-solid <?= $icon ?> me-1" style="color:<?= $iconColor ?>"></i><?= htmlspecialchars($a['titulo']) ?></div>
                                    <div class="a-desc"><?= htmlspecialchars($a['texto']) ?></div>
                                </div>
                            <?php endforeach; ?>

                            <?php if (!empty($topInadimplentes)): ?>
                                <div class="mt-3 pt-3" style="border-top:1px solid #f1f5f9">
                                    <div class="dash-card-title mb-2"><i class="fa-solid fa-user-clock me-1 text-muted"></i>Top 5 inadimplentes</div>
                                    <?php foreach ($topInadimplentes as $t): ?>
                                        <div class="top-row">
                                            <span class="nome" title="<?= htmlspecialchars($t['cliente']) ?>"><?= htmlspecialchars($t['cliente']) ?> <small class="text-muted">(<?= (int)$t['qtd'] ?>)</small></span>
                                            <span class="val"><?= $fmtMoney((float)$t['valor']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12 col-xl-8">
                        <div class="dash-card h-100">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <div class="dash-card-title"><i class="fa-solid fa-clock-rotate-left me-1 text-muted"></i>Últimos lançamentos</div>
                                    <div class="dash-card-sub">Movimentações recentes</div>
                                </div>
                                <span class="pill"><?= count($ultimos_lancamentos) ?> registros</span>
                            </div>

                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Data</th>
                                            <th>Tipo</th>
                                            <th>Descrição</th>
                                            <th class="text-end">Valor</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($ultimos_lancamentos)): ?>
                                            <tr><td colspan="5" class="text-muted small text-center py-3">Nenhum lançamento encontrado.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($ultimos_lancamentos as $r): ?>
                                                <?php
                                                $isRec = ($r['tipo'] === 'Receita');
                                                $stUp = strtoupper($r['status']);
                                                $statusBadge = ($stUp === 'RECEBIDO' || $stUp === 'PAGO') ? 'badge-soft-green' : (($stUp === 'ATRASADO' || $stUp === 'ATRASO') ? 'badge-soft-red' : 'badge-soft-amber');
                                                ?>
                                                <tr>
                                                    <td class="mono"><?= htmlspecialchars($r['data']) ?></td>
                                                    <td><span class="<?= $isRec ? 'badge-soft-green' : 'badge-soft-red' ?>"><?= $isRec ? '<i class="fa-solid fa-arrow-down me-1"></i>' : '<i class="fa-solid fa-arrow-up me-1"></i>' ?><?= htmlspecialchars($r['tipo']) ?></span></td>
                                                    <td style="max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($r['descricao']) ?>"><?= htmlspecialchars($r['descricao']) ?></td>
                                                    <td class="text-end mono fw-semibold"><?= $fmtMoney((float)$r['valor']) ?></td>
                                                    <td><span class="<?= $statusBadge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <footer class="text-muted small mt-4 text-center" style="font-size:.72rem">
                    © <?= date('Y') ?> SYNC-ERP — Sistema de Gestão Financeira
                </footer>

            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        document.getElementById('btnAtualizar')?.addEventListener('click', () => {
            window.location.reload();
        });
    </script>

  <script src="assets/session_keeper.js" defer></script>
</body>

</html>
