<?php
// /app/diagnostico_contas_pagar.php
// Página de diagnóstico — uso pontual.
// Mostra para PAGAR e RECEBER:
//  1) Resumo geral (com banco / sem banco / aberto / cancelado).
//  2) Comparativo por banco vs Saldo Fluxo de Caixa, Saldo OFX, Σ débitos OFX, Σ créditos OFX.
//  3) Listas de órfãs (sem banco vinculado), separadas por situação:
//        - Pagas/Recebidas sem banco
//        - Em aberto/atrasadas sem banco
//  4) Fluxo cruzado por banco (movimentação esperada vs OFX importado).
//
// Acesso: depois de logado como ADMIN, abrir /app/diagnostico_contas_pagar.php no navegador.

declare(strict_types=1);

@set_time_limit(180); // Página de diagnóstico — uso pontual, faz dezenas de roundtrips ao DB remoto.

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/status_dict.php';
require_once __DIR__ . '/config/saldos.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Acesso restrito. Faça login com um usuário ADMIN.');
}

// ============================================================
// FILTRO DE PERÍODO (via GET)
// Modos: tudo | 7d | 15d | 30d | mes | trim | ano | custom
// Datas que entram no filtro:
//   - Pagar PAGAS         → CPG_DATA_PAGAMENTO
//   - Pagar EM ABERTO     → CPG_VENCIMENTO
//   - Receber RECEBIDAS   → CRE_RECEBIDO_EM
//   - Receber EM ABERTO   → CRE_VENCIMENTO
//   - Movimento OFX       → COM_DATA_MOVIMENTO
// ============================================================
$periodo = strtolower(trim((string)($_GET['periodo'] ?? 'tudo')));
$dtIniReq = trim((string)($_GET['data_ini'] ?? ''));
$dtFimReq = trim((string)($_GET['data_fim'] ?? ''));
$validData = static fn(string $s) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$hoje = new DateTime('today');
$dtIni = null; $dtFim = null; $periodoLabel = 'Sem filtro de período (todos os registros)';

if ($periodo === 'custom' && $validData($dtIniReq) && $validData($dtFimReq)) {
    $dtIni = new DateTime($dtIniReq);
    $dtFim = new DateTime($dtFimReq);
    $periodoLabel = 'Personalizado: ' . $dtIni->format('d/m/Y') . ' a ' . $dtFim->format('d/m/Y');
} elseif ($periodo === '7d') {
    $dtIni = (clone $hoje)->modify('-6 days'); $dtFim = clone $hoje;
    $periodoLabel = 'Últimos 7 dias';
} elseif ($periodo === '15d') {
    $dtIni = (clone $hoje)->modify('-14 days'); $dtFim = clone $hoje;
    $periodoLabel = 'Últimos 15 dias';
} elseif ($periodo === '30d') {
    $dtIni = (clone $hoje)->modify('-29 days'); $dtFim = clone $hoje;
    $periodoLabel = 'Últimos 30 dias';
} elseif ($periodo === 'mes') {
    $dtIni = new DateTime($hoje->format('Y-m-01'));
    $dtFim = (clone $dtIni)->modify('last day of this month');
    $periodoLabel = 'Mês atual (' . $dtIni->format('m/Y') . ')';
} elseif ($periodo === 'trim') {
    $mes = (int)$hoje->format('n');
    $inicioTrimMes = ((int)floor(($mes - 1) / 3) * 3) + 1;
    $dtIni = new DateTime($hoje->format('Y') . '-' . str_pad((string)$inicioTrimMes, 2, '0', STR_PAD_LEFT) . '-01');
    $dtFim = (clone $dtIni)->modify('+2 months')->modify('last day of this month');
    $periodoLabel = 'Trimestre atual';
} elseif ($periodo === 'ano') {
    $dtIni = new DateTime($hoje->format('Y') . '-01-01');
    $dtFim = new DateTime($hoje->format('Y') . '-12-31');
    $periodoLabel = 'Ano atual (' . $hoje->format('Y') . ')';
}

$temFiltro = ($dtIni !== null && $dtFim !== null);
$dtIniSql  = $temFiltro ? $dtIni->format('Y-m-d') : null;
$dtFimSql  = $temFiltro ? $dtFim->format('Y-m-d') : null;

// Helper que devolve a cláusula AND col BETWEEN '...' AND '...' ou string vazia.
$cl = static function (string $col) use ($temFiltro, $dtIniSql, $dtFimSql): string {
    if (!$temFiltro) return '';
    // Datas vêm de regex validada — concatenação segura.
    return " AND {$col} BETWEEN '{$dtIniSql}' AND '{$dtFimSql}' ";
};

$brl = function ($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); };
$num = function ($v) { return number_format((int)$v, 0, ',', '.'); };
$h   = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

// ============================================================
// 1) RESUMO GERAL — CONTAS A PAGAR
// ============================================================
// Filtro de data: usa CPG_DATA_PAGAMENTO para PAGAS e CPG_VENCIMENTO para ABERTAS/ATRASADAS.
$resumoP = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN CPG_STATUS = 'PAGO' AND CPG_BANCO_PAGAMENTO_FK IS NOT NULL "
            . $cl('CPG_DATA_PAGAMENTO') . " THEN 1 ELSE 0 END) AS pagas_com_banco,
        SUM(CASE WHEN CPG_STATUS = 'PAGO' AND CPG_BANCO_PAGAMENTO_FK IS NULL "
            . $cl('CPG_DATA_PAGAMENTO') . " THEN 1 ELSE 0 END) AS pagas_sem_banco,
        SUM(CASE WHEN CPG_STATUS = 'PAGO' "
            . $cl('CPG_DATA_PAGAMENTO') . " THEN 1 ELSE 0 END) AS pagas_total,
        SUM(CASE WHEN CPG_STATUS = 'PAGO' AND CPG_BANCO_PAGAMENTO_FK IS NOT NULL "
            . $cl('CPG_DATA_PAGAMENTO') . " THEN COALESCE(CPG_VALOR_PAGO, CPG_VALOR_PARCELA) ELSE 0 END) AS soma_pagas_com_banco,
        SUM(CASE WHEN CPG_STATUS = 'PAGO' AND CPG_BANCO_PAGAMENTO_FK IS NULL "
            . $cl('CPG_DATA_PAGAMENTO') . " THEN COALESCE(CPG_VALOR_PAGO, CPG_VALOR_PARCELA) ELSE 0 END) AS soma_pagas_sem_banco,
        SUM(CASE WHEN CPG_STATUS IN ('ABERTO','ATRASADO') "
            . $cl('CPG_VENCIMENTO') . " THEN 1 ELSE 0 END) AS abertas,
        SUM(CASE WHEN CPG_STATUS IN ('ABERTO','ATRASADO') "
            . $cl('CPG_VENCIMENTO') . " THEN CPG_VALOR_PARCELA ELSE 0 END) AS soma_abertas,
        SUM(CASE WHEN CPG_STATUS IN ('ABERTO','ATRASADO') AND CPG_BANCO_PAGAMENTO_FK IS NULL "
            . $cl('CPG_VENCIMENTO') . " THEN 1 ELSE 0 END) AS abertas_sem_banco,
        SUM(CASE WHEN CPG_STATUS IN ('ABERTO','ATRASADO') AND CPG_BANCO_PAGAMENTO_FK IS NULL "
            . $cl('CPG_VENCIMENTO') . " THEN CPG_VALOR_PARCELA ELSE 0 END) AS soma_abertas_sem_banco,
        SUM(CASE WHEN CPG_STATUS = 'CANCELADO' THEN 1 ELSE 0 END) AS canceladas
    FROM tb_contas_pagar
")->fetch(PDO::FETCH_ASSOC) ?: [];

// ============================================================
// 1B) RESUMO GERAL — CONTAS A RECEBER
// ============================================================
$resumoR = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN CRE_STATUS IN ('RECEBIDO','PAGO') AND CRE_BANCO_FK IS NOT NULL "
            . $cl('CRE_RECEBIDO_EM') . " THEN 1 ELSE 0 END) AS receb_com_banco,
        SUM(CASE WHEN CRE_STATUS IN ('RECEBIDO','PAGO') AND CRE_BANCO_FK IS NULL "
            . $cl('CRE_RECEBIDO_EM') . " THEN 1 ELSE 0 END) AS receb_sem_banco,
        SUM(CASE WHEN CRE_STATUS IN ('RECEBIDO','PAGO') "
            . $cl('CRE_RECEBIDO_EM') . " THEN 1 ELSE 0 END) AS receb_total,
        SUM(CASE WHEN CRE_STATUS IN ('RECEBIDO','PAGO') AND CRE_BANCO_FK IS NOT NULL "
            . $cl('CRE_RECEBIDO_EM') . " THEN COALESCE(NULLIF(CRE_VALOR_RECEBIDO,0), CRE_VALOR) ELSE 0 END) AS soma_receb_com_banco,
        SUM(CASE WHEN CRE_STATUS IN ('RECEBIDO','PAGO') AND CRE_BANCO_FK IS NULL "
            . $cl('CRE_RECEBIDO_EM') . " THEN COALESCE(NULLIF(CRE_VALOR_RECEBIDO,0), CRE_VALOR) ELSE 0 END) AS soma_receb_sem_banco,
        SUM(CASE WHEN CRE_STATUS IN ('ABERTO','PROGRAMADO','PENDENTE') "
            . $cl('CRE_VENCIMENTO') . " THEN 1 ELSE 0 END) AS abertas,
        SUM(CASE WHEN CRE_STATUS IN ('ABERTO','PROGRAMADO','PENDENTE') "
            . $cl('CRE_VENCIMENTO') . " THEN CRE_VALOR ELSE 0 END) AS soma_abertas,
        SUM(CASE WHEN CRE_STATUS IN ('ABERTO','PROGRAMADO','PENDENTE') AND CRE_BANCO_FK IS NULL "
            . $cl('CRE_VENCIMENTO') . " THEN 1 ELSE 0 END) AS abertas_sem_banco,
        SUM(CASE WHEN CRE_STATUS IN ('ABERTO','PROGRAMADO','PENDENTE') AND CRE_BANCO_FK IS NULL "
            . $cl('CRE_VENCIMENTO') . " THEN CRE_VALOR ELSE 0 END) AS soma_abertas_sem_banco,
        SUM(CASE WHEN CRE_STATUS = 'CANCELADO' THEN 1 ELSE 0 END) AS canceladas
    FROM tb_contas_receber
")->fetch(PDO::FETCH_ASSOC) ?: [];

$pctP = ($resumoP['pagas_total'] ?? 0) > 0
    ? round(($resumoP['pagas_sem_banco'] / $resumoP['pagas_total']) * 100, 1) : 0.0;
$pctR = ($resumoR['receb_total'] ?? 0) > 0
    ? round(($resumoR['receb_sem_banco'] / $resumoR['receb_total']) * 100, 1) : 0.0;

// ============================================================
// 2) COMPARATIVO POR BANCO
// ============================================================
$bancos = $pdo->query("
    SELECT BAN_ID, BAN_APELIDO, BAN_NOME, BAN_CODIGO, BAN_AGENCIA, BAN_CONTA
    FROM tb_banco
    WHERE BAN_STATUS = 'ATIVO'
    ORDER BY BAN_APELIDO, BAN_NOME
")->fetchAll(PDO::FETCH_ASSOC);

$stPagasBanco = $pdo->prepare("
    SELECT COUNT(*) AS qtd, COALESCE(SUM(COALESCE(CPG_VALOR_PAGO, CPG_VALOR_PARCELA)),0) AS soma
    FROM tb_contas_pagar
    WHERE CPG_STATUS = 'PAGO' AND CPG_BANCO_PAGAMENTO_FK = ? "
    . $cl('CPG_DATA_PAGAMENTO') . "
");
$stRecebBanco = $pdo->prepare("
    SELECT COUNT(*) AS qtd, COALESCE(SUM(COALESCE(NULLIF(CRE_VALOR_RECEBIDO,0), CRE_VALOR)),0) AS soma
    FROM tb_contas_receber
    WHERE CRE_STATUS IN ('RECEBIDO','PAGO') AND CRE_BANCO_FK = ? "
    . $cl('CRE_RECEBIDO_EM') . "
");
$stAbertasPagarBanco = $pdo->prepare("
    SELECT COUNT(*) AS qtd, COALESCE(SUM(CPG_VALOR_PARCELA),0) AS soma
    FROM tb_contas_pagar
    WHERE CPG_STATUS IN ('ABERTO','ATRASADO') AND CPG_BANCO_PAGAMENTO_FK = ? "
    . $cl('CPG_VENCIMENTO') . "
");
$stAbertasRecebBanco = $pdo->prepare("
    SELECT COUNT(*) AS qtd, COALESCE(SUM(CRE_VALOR),0) AS soma
    FROM tb_contas_receber
    WHERE CRE_STATUS IN ('ABERTO','PROGRAMADO','PENDENTE') AND CRE_BANCO_FK = ? "
    . $cl('CRE_VENCIMENTO') . "
");
// Saldo legado (apenas referência histórica; tabela deprecada após briefing 1)
$stFcb = $pdo->prepare("
    SELECT FCB_SALDO_ATUAL, FCB_DATA
    FROM tb_fluxo_caixa_banco
    WHERE FCB_BANCO_FK = ? AND FCB_DATA <= CURDATE()
    ORDER BY FCB_DATA DESC, FCB_CODIGO_PK DESC LIMIT 1
");
$stOfxBal = $pdo->prepare("
    SELECT COI_SALDO_FINAL, COI_DATA_FINAL
    FROM tb_conciliacao_ofx_importacao
    WHERE COI_BANCO_FK = ? AND COI_SALDO_FINAL IS NOT NULL
    ORDER BY COI_CODIGO_PK DESC LIMIT 1
");
// Último SET ATIVO em tb_conciliacao_ajuste_saldo (baseline atual do saldoErpConta)
$stSet = $pdo->prepare("
    SELECT CAS_SALDO_NOVO, CAS_DATA
    FROM tb_conciliacao_ajuste_saldo
    WHERE CAS_BANCO_FK = ? AND CAS_CONTA_REF = ?
      AND CAS_CAMPO_AJUSTADO = 'SALDO_ERP'
      AND CAS_OPERACAO = 'SET' AND CAS_STATUS = 'ATIVO'
    ORDER BY CAS_DATA DESC, CAS_CODIGO_PK DESC LIMIT 1
");
$stOfxDeb = $pdo->prepare("
    SELECT COUNT(*) AS qtd, COALESCE(SUM(ABS(COM_VALOR)),0) AS soma
    FROM tb_conciliacao_ofx_movimento
    WHERE COM_BANCO_FK = ? AND COM_TIPO = 'DEBITO' "
    . $cl('COM_DATA_MOVIMENTO') . "
");
$stOfxCre = $pdo->prepare("
    SELECT COUNT(*) AS qtd, COALESCE(SUM(ABS(COM_VALOR)),0) AS soma
    FROM tb_conciliacao_ofx_movimento
    WHERE COM_BANCO_FK = ? AND COM_TIPO = 'CREDITO' "
    . $cl('COM_DATA_MOVIMENTO') . "
");

$linhasBanco = [];
$totSomaPagas = $totSomaReceb = $totDebOfx = $totCreOfx = $totAbP = $totAbR = 0.0;

foreach ($bancos as $b) {
    $banId    = (int)$b['BAN_ID'];
    $contaRef = trim((string)$b['BAN_AGENCIA']) . '/' . trim((string)$b['BAN_CONTA']);

    $stPagasBanco->execute([$banId]); $rp = $stPagasBanco->fetch(PDO::FETCH_ASSOC);
    $stRecebBanco->execute([$banId]); $rr = $stRecebBanco->fetch(PDO::FETCH_ASSOC);
    $stAbertasPagarBanco->execute([$banId]); $ap = $stAbertasPagarBanco->fetch(PDO::FETCH_ASSOC);
    $stAbertasRecebBanco->execute([$banId]); $ar = $stAbertasRecebBanco->fetch(PDO::FETCH_ASSOC);
    $stFcb->execute([$banId]); $fcb = $stFcb->fetch(PDO::FETCH_ASSOC);
    $stOfxBal->execute([$banId]); $ob = $stOfxBal->fetch(PDO::FETCH_ASSOC);
    $stOfxDeb->execute([$banId]); $od = $stOfxDeb->fetch(PDO::FETCH_ASSOC);
    $stOfxCre->execute([$banId]); $oc = $stOfxCre->fetch(PDO::FETCH_ASSOC);
    $stSet->execute([$banId, $contaRef]); $set = $stSet->fetch(PDO::FETCH_ASSOC);

    // Saldo calculado pela função canônica (mesma usada pela Conciliação e pelo Fluxo de Caixa)
    $saldoErp = saldoErpConta($pdo, $banId, $contaRef);
    $saldoBan = saldoBancarioOfx($pdo, $banId, $contaRef);

    $somaPagas  = (float)$rp['soma'];
    $somaReceb  = (float)$rr['soma'];
    $somaDebOfx = (float)$od['soma'];
    $somaCreOfx = (float)$oc['soma'];

    $totSomaPagas += $somaPagas;
    $totSomaReceb += $somaReceb;
    $totDebOfx    += $somaDebOfx;
    $totCreOfx    += $somaCreOfx;
    $totAbP       += (float)$ap['soma'];
    $totAbR       += (float)$ar['soma'];

    $linhasBanco[] = [
        'ban_id'      => $banId,
        'apelido'     => $b['BAN_APELIDO'] ?: $b['BAN_NOME'],
        'codigo'      => $b['BAN_CODIGO'],
        'ag_conta'    => $contaRef,
        'pagas_qtd'   => (int)$rp['qtd'],
        'pagas_soma'  => $somaPagas,
        'receb_qtd'   => (int)$rr['qtd'],
        'receb_soma'  => $somaReceb,
        'aber_p_qtd'  => (int)$ap['qtd'],
        'aber_p_soma' => (float)$ap['soma'],
        'aber_r_qtd'  => (int)$ar['qtd'],
        'aber_r_soma' => (float)$ar['soma'],
        'set_valor'   => $set ? (float)$set['CAS_SALDO_NOVO'] : null,
        'set_data'    => $set ? $set['CAS_DATA'] : null,
        'saldo_erp'   => $saldoErp,
        'saldo_ban'   => $saldoBan,
        'fcb_saldo'   => $fcb ? (float)$fcb['FCB_SALDO_ATUAL'] : null,
        'fcb_data'    => $fcb ? $fcb['FCB_DATA'] : null,
        'ofx_saldo'   => $ob ? (float)$ob['COI_SALDO_FINAL'] : null,
        'ofx_data'    => $ob ? $ob['COI_DATA_FINAL'] : null,
        'ofx_deb_qtd' => (int)$od['qtd'],
        'ofx_deb_soma'=> $somaDebOfx,
        'ofx_cre_qtd' => (int)$oc['qtd'],
        'ofx_cre_soma'=> $somaCreOfx,
        'fluxo_sis'   => $somaReceb - $somaPagas,
        'fluxo_ofx'   => $somaCreOfx - $somaDebOfx,
    ];
}

// ============================================================
// 3) ÓRFÃS (4 listas: pagar pagas, pagar abertas, receber recebidas, receber abertas)
// ============================================================
$lstPagasOrf = $pdo->query("
    SELECT cp.CPG_CODIGO_PK, cp.CPG_DATA_PAGAMENTO, cp.CPG_VENCIMENTO,
           COALESCE(NULLIF(TRIM(cp.CPG_DESCRICAO),''),'(sem descrição)') AS descricao,
           cp.CPG_DOCUMENTO, cp.CPG_NOTA_FISCAL,
           cp.CPG_VALOR_PARCELA, cp.CPG_VALOR_PAGO,
           f.FOR_RAZAO_SOCIAL, f.FOR_NOME_FANTASIA
    FROM tb_contas_pagar cp
    LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
    WHERE cp.CPG_STATUS = 'PAGO' AND cp.CPG_BANCO_PAGAMENTO_FK IS NULL "
    . $cl('cp.CPG_DATA_PAGAMENTO') . "
    ORDER BY cp.CPG_DATA_PAGAMENTO DESC, cp.CPG_CODIGO_PK DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$lstAbertasPagarOrf = $pdo->query("
    SELECT cp.CPG_CODIGO_PK, cp.CPG_VENCIMENTO, cp.CPG_STATUS,
           COALESCE(NULLIF(TRIM(cp.CPG_DESCRICAO),''),'(sem descrição)') AS descricao,
           cp.CPG_DOCUMENTO, cp.CPG_VALOR_PARCELA,
           f.FOR_RAZAO_SOCIAL, f.FOR_NOME_FANTASIA
    FROM tb_contas_pagar cp
    LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
    WHERE cp.CPG_STATUS IN ('ABERTO','ATRASADO') AND cp.CPG_BANCO_PAGAMENTO_FK IS NULL "
    . $cl('cp.CPG_VENCIMENTO') . "
    ORDER BY cp.CPG_VENCIMENTO ASC, cp.CPG_CODIGO_PK ASC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// Detecta nome real da tabela de cliente (alguns esquemas usam `cliente`, outros `tb_cliente`)
$tabClienteSt = $pdo->query("
    SELECT TABLE_NAME FROM information_schema.tables
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('cliente','tb_cliente')
    LIMIT 1
");
$tabCliente = $tabClienteSt ? ($tabClienteSt->fetchColumn() ?: '') : '';

// Detecta o campo de nome do cliente (CLI_NOME_RAZAO ou CLI_NOME)
$cliNomeCol = '';
if ($tabCliente) {
    $stCol = $pdo->prepare("
        SELECT COLUMN_NAME FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          AND COLUMN_NAME IN ('CLI_NOME_RAZAO','CLI_NOME','CLI_RAZAO_SOCIAL','CLI_NOME_FANTASIA')
        ORDER BY FIELD(COLUMN_NAME,'CLI_NOME_RAZAO','CLI_NOME','CLI_RAZAO_SOCIAL','CLI_NOME_FANTASIA')
        LIMIT 1
    ");
    $stCol->execute([$tabCliente]);
    $cliNomeCol = (string)($stCol->fetchColumn() ?: '');
}

// Monta join condicional para receber
$joinCli = '';
$selCli  = "NULL AS CLI_NOME_RESOLVIDO";
if ($tabCliente && $cliNomeCol) {
    $joinCli = "LEFT JOIN {$tabCliente} cl ON cl.CLI_ID = cr.CRE_CLIENTE_FK";
    $selCli  = "cl.{$cliNomeCol} AS CLI_NOME_RESOLVIDO";
}

$lstRecebOrf = $pdo->query("
    SELECT cr.CRE_ID, cr.CRE_RECEBIDO_EM, cr.CRE_VENCIMENTO,
           COALESCE(NULLIF(TRIM(cr.CRE_OBSERVACAO),''),'(sem descrição)') AS descricao,
           cr.CRE_DOCUMENTO,
           cr.CRE_VALOR, cr.CRE_VALOR_RECEBIDO,
           cr.CRE_CLIENTE_NOME, {$selCli}
    FROM tb_contas_receber cr
    {$joinCli}
    WHERE cr.CRE_STATUS IN ('RECEBIDO','PAGO') AND cr.CRE_BANCO_FK IS NULL "
    . $cl('cr.CRE_RECEBIDO_EM') . "
    ORDER BY cr.CRE_RECEBIDO_EM DESC, cr.CRE_ID DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$lstAbertasRecebOrf = $pdo->query("
    SELECT cr.CRE_ID, cr.CRE_VENCIMENTO, cr.CRE_STATUS,
           COALESCE(NULLIF(TRIM(cr.CRE_OBSERVACAO),''),'(sem descrição)') AS descricao,
           cr.CRE_DOCUMENTO, cr.CRE_VALOR,
           cr.CRE_CLIENTE_NOME, {$selCli}
    FROM tb_contas_receber cr
    {$joinCli}
    WHERE cr.CRE_STATUS IN ('ABERTO','PROGRAMADO','PENDENTE') AND cr.CRE_BANCO_FK IS NULL "
    . $cl('cr.CRE_VENCIMENTO') . "
    ORDER BY cr.CRE_VENCIMENTO ASC, cr.CRE_ID ASC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 4) Total OFX geral
// ============================================================
$totalOfx = $pdo->query("
    SELECT
        SUM(CASE WHEN COM_TIPO='DEBITO'  THEN ABS(COM_VALOR) ELSE 0 END) AS soma_deb,
        SUM(CASE WHEN COM_TIPO='CREDITO' THEN ABS(COM_VALOR) ELSE 0 END) AS soma_cre,
        SUM(CASE WHEN COM_TIPO='DEBITO'  THEN 1 ELSE 0 END) AS qtd_deb,
        SUM(CASE WHEN COM_TIPO='CREDITO' THEN 1 ELSE 0 END) AS qtd_cre
    FROM tb_conciliacao_ofx_movimento
    WHERE 1=1 "
    . $cl('COM_DATA_MOVIMENTO') . "
")->fetch(PDO::FETCH_ASSOC) ?: ['soma_deb'=>0,'soma_cre'=>0,'qtd_deb'=>0,'qtd_cre'=>0];

?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Diagnóstico — Pagar / Receber × Bancos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<style>
    body { background:#f3f4f6; font-family:'Poppins', system-ui, sans-serif; padding-bottom:50px; }
    .kpi-card { border:0; border-radius:12px; }
    .kpi-card .label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
    .kpi-card .value { font-size:22px; font-weight:600; color:#0f172a; line-height:1.1; }
    .kpi-card .sub { font-size:11px; color:#64748b; }
    .table thead th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#475569; background:#f1f5f9; }
    .table td, .table th { font-size:13px; }
    .num { font-variant-numeric: tabular-nums; }
    .neg { color:#b91c1c; }
    .pos { color:#15803d; }
    .small-muted { font-size:11px; color:#64748b; }
    .section-title { font-size:14px; font-weight:700; color:#0f172a; margin:24px 0 8px; padding-bottom:6px; border-bottom:2px solid #0f172a; }
    .section-title-r { border-color:#15803d; color:#15803d; }
    .section-title-p { border-color:#b91c1c; color:#b91c1c; }
    .badge-soft-warn { background:#fef3c7; color:#92400e; }
    .badge-soft-ok   { background:#dcfce7; color:#166534; }
    .border-pagar  { border-left:4px solid #b91c1c; }
    .border-receber { border-left:4px solid #15803d; }
    details > summary { cursor:pointer; }
    details[open] > summary { margin-bottom:6px; }
</style>
</head>
<body>

<div class="container-fluid py-4">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-clipboard-data me-2"></i>Diagnóstico — Pagar / Receber × Bancos</h4>
            <div class="small-muted">
                Análise gerada em <?=date('d/m/Y H:i')?> — <strong><?=$h($periodoLabel)?></strong>.
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-sm btn-outline-primary" title="Voltar ao Dashboard"><i class="bi bi-house-door-fill me-1"></i>Dashboard</a>
            <a href="contas_pagar.php"   class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-up-right me-1"></i>Contas a Pagar</a>
            <a href="contas_receber.php" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-down-right me-1"></i>Contas a Receber</a>
            <a href="fluxo_caixa.php"    class="btn btn-sm btn-outline-secondary"><i class="bi bi-graph-up me-1"></i>Fluxo de Caixa</a>
        </div>
    </div>

    <!-- Filtro de período -->
    <div class="card mb-3" style="border-radius:12px;border:1px solid #e5e7eb">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-12 col-md-auto">
                    <label class="form-label small fw-bold mb-1 d-block"><i class="bi bi-calendar-range me-1"></i>Período</label>
                    <div class="btn-group" role="group" aria-label="Períodos rápidos">
                        <?php
                        $quickBtns = [
                            'tudo' => 'Tudo',
                            '7d'   => '7 dias',
                            '15d'  => '15 dias',
                            '30d'  => '30 dias',
                            'mes'  => 'Este mês',
                            'trim' => 'Trimestre',
                            'ano'  => 'Este ano',
                        ];
                        foreach ($quickBtns as $k => $rotulo):
                            $ativo = ($periodo === $k);
                        ?>
                            <a href="?periodo=<?=$h($k)?>" class="btn btn-sm <?= $ativo ? 'btn-primary' : 'btn-outline-secondary' ?>"><?=$rotulo?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold mb-1">Data inicial</label>
                    <input type="date" name="data_ini" class="form-control form-control-sm" value="<?=$h($dtIniReq)?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold mb-1">Data final</label>
                    <input type="date" name="data_fim" class="form-control form-control-sm" value="<?=$h($dtFimReq)?>">
                </div>
                <div class="col-12 col-md-auto">
                    <input type="hidden" name="periodo" value="custom">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-funnel me-1"></i>Aplicar período</button>
                    <a href="?periodo=tudo" class="btn btn-sm btn-outline-secondary" title="Remover filtro"><i class="bi bi-x-circle me-1"></i>Limpar</a>
                </div>
            </form>
            <?php if ($temFiltro): ?>
                <div class="small-muted mt-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Filtrando: <strong>PAGAS</strong> por data de pagamento, <strong>RECEBIDAS</strong> por data de recebimento,
                    <strong>EM ABERTO</strong> por vencimento, <strong>OFX</strong> por data do movimento.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============== CONTAS A PAGAR ============== -->
    <div class="section-title section-title-p"><i class="bi bi-arrow-up-right me-1"></i>CONTAS A PAGAR</div>

    <div class="row g-2 mb-2">
        <div class="col-md-3"><div class="card kpi-card p-3 h-100">
            <div class="label">Total</div>
            <div class="value"><?=$num($resumoP['total'] ?? 0)?></div>
            <div class="sub"><?=$num($resumoP['canceladas'] ?? 0)?> canceladas</div>
        </div></div>
        <div class="col-md-3"><div class="card kpi-card p-3 h-100">
            <div class="label">Pagas <span class="text-success">com banco</span></div>
            <div class="value"><?=$num($resumoP['pagas_com_banco'] ?? 0)?></div>
            <div class="sub"><?=$brl($resumoP['soma_pagas_com_banco'] ?? 0)?></div>
        </div></div>
        <div class="col-md-3"><div class="card kpi-card border-pagar p-3 h-100">
            <div class="label">Pagas <span class="text-danger">sem banco</span></div>
            <div class="value neg"><?=$num($resumoP['pagas_sem_banco'] ?? 0)?></div>
            <div class="sub"><?=$brl($resumoP['soma_pagas_sem_banco'] ?? 0)?> — <?=$pctP?>% das pagas</div>
        </div></div>
        <div class="col-md-3"><div class="card kpi-card p-3 h-100">
            <div class="label">Em aberto / atrasadas</div>
            <div class="value"><?=$num($resumoP['abertas'] ?? 0)?></div>
            <div class="sub">
                <?=$brl($resumoP['soma_abertas'] ?? 0)?> total
                <?php if (($resumoP['abertas_sem_banco'] ?? 0) > 0): ?>
                    <span class="badge badge-soft-warn ms-1"><?=$num($resumoP['abertas_sem_banco'])?> sem banco previsto</span>
                <?php endif; ?>
            </div>
        </div></div>
    </div>

    <!-- ============== CONTAS A RECEBER ============== -->
    <div class="section-title section-title-r"><i class="bi bi-arrow-down-right me-1"></i>CONTAS A RECEBER</div>

    <div class="row g-2 mb-3">
        <div class="col-md-3"><div class="card kpi-card p-3 h-100">
            <div class="label">Total</div>
            <div class="value"><?=$num($resumoR['total'] ?? 0)?></div>
            <div class="sub"><?=$num($resumoR['canceladas'] ?? 0)?> canceladas</div>
        </div></div>
        <div class="col-md-3"><div class="card kpi-card p-3 h-100">
            <div class="label">Recebidas <span class="text-success">com banco</span></div>
            <div class="value"><?=$num($resumoR['receb_com_banco'] ?? 0)?></div>
            <div class="sub"><?=$brl($resumoR['soma_receb_com_banco'] ?? 0)?></div>
        </div></div>
        <div class="col-md-3"><div class="card kpi-card border-pagar p-3 h-100">
            <div class="label">Recebidas <span class="text-danger">sem banco</span></div>
            <div class="value neg"><?=$num($resumoR['receb_sem_banco'] ?? 0)?></div>
            <div class="sub"><?=$brl($resumoR['soma_receb_sem_banco'] ?? 0)?> — <?=$pctR?>% das recebidas</div>
        </div></div>
        <div class="col-md-3"><div class="card kpi-card p-3 h-100">
            <div class="label">Em aberto / programadas</div>
            <div class="value"><?=$num($resumoR['abertas'] ?? 0)?></div>
            <div class="sub">
                <?=$brl($resumoR['soma_abertas'] ?? 0)?> total
                <?php if (($resumoR['abertas_sem_banco'] ?? 0) > 0): ?>
                    <span class="badge badge-soft-warn ms-1"><?=$num($resumoR['abertas_sem_banco'])?> sem banco previsto</span>
                <?php endif; ?>
            </div>
        </div></div>
    </div>

    <!-- ============== VERIFICAÇÃO DO saldoErpConta() ============== -->
    <div class="card kpi-card mb-3" style="border-left:4px solid #15803d">
        <div class="card-header bg-white border-0 pt-3 pb-2">
            <h6 class="fw-bold mb-1"><i class="bi bi-calculator me-2"></i>Validação do cálculo de saldo (pós-correção do SET)</h6>
            <div class="small-muted">
                Mostra o saldo retornado por <code>saldoErpConta()</code> e <code>saldoBancarioOfx()</code> — as funções canônicas usadas hoje pela Conciliação Bancária e pelo Fluxo de Caixa.
                Use esta tabela para conferir se o saldo do sistema bate com os extratos reais dos bancos.
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Banco</th>
                        <th>Ag/Conta</th>
                        <th class="text-end">SET (baseline)</th>
                        <th>Data SET</th>
                        <th class="text-end">Σ Receb após SET</th>
                        <th class="text-end">Σ Pagto após SET</th>
                        <th class="text-end">= Saldo ERP <small class="text-muted">(saldoErpConta)</small></th>
                        <th class="text-end">Saldo banco <small class="text-muted">(saldoBancarioOfx)</small></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linhasBanco as $b): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?=$h($b['apelido'])?></div>
                            <div class="small-muted">cód. <?=$h($b['codigo'])?></div>
                        </td>
                        <td class="num"><?=$h($b['ag_conta'])?></td>
                        <td class="num text-end">
                            <?php if ($b['set_valor'] !== null): ?>
                                <?=$brl($b['set_valor'])?>
                            <?php else: ?>
                                <span class="text-muted small">— sem SET —</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?=$b['set_data'] ? date('d/m/Y', strtotime($b['set_data'])) : '—'?></td>
                        <td class="num text-end pos">
                            <?php
                                $deltaReceb = ($b['set_data']) ? ($b['saldo_erp'] - ($b['set_valor'] ?? 0) + $b['ofx_deb_soma']) : 0;
                                // Para essa coluna, mostramos apenas o que está visível: a soma de recebimentos posteriores ao SET.
                                // Cálculo direto: (saldo_erp − baseline) + Σ pagamentos posteriores. Mas como não temos isso pronto,
                                // mostramos a soma dos recebimentos de contas a receber RECEBIDO/PAGO posteriores.
                            ?>
                            <span class="small-muted">ver saldo final →</span>
                        </td>
                        <td class="num text-end neg">
                            <span class="small-muted">ver saldo final →</span>
                        </td>
                        <td class="num text-end fw-bold" style="font-size:14px">
                            <span class="<?=$b['saldo_erp']<0?'neg':'pos'?>"><?=$brl($b['saldo_erp'])?></span>
                        </td>
                        <td class="num text-end fw-semibold">
                            <?=$brl($b['saldo_ban'])?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white small-muted">
            <strong>Como ler:</strong> a coluna "Saldo ERP" é exatamente o que aparece na tela de <em>Conciliação Bancária</em> e <em>Fluxo de Caixa</em> para cada banco. Se ela bate com o saldo do extrato real do banco no mesmo dia, o sistema está sincronizado. Se diverge, a diferença revela contas faltando lançar (ou divergência de banco vinculado).
        </div>
    </div>

    <!-- ============== POR BANCO — TABELA UNIFICADA ============== -->
    <div class="card kpi-card mb-3">
        <div class="card-header bg-white border-0 pt-3 pb-2">
            <h6 class="fw-bold mb-1"><i class="bi bi-bank me-2"></i>Comparativo por banco</h6>
            <div class="small-muted">Movimentação concluída e em aberto por banco, e como bate com OFX e Fluxo de Caixa.</div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th rowspan="2">Banco</th>
                        <th rowspan="2">Ag/Conta</th>
                        <th colspan="2" class="text-center text-danger">Pagar (concluído)</th>
                        <th colspan="2" class="text-center text-success">Receber (concluído)</th>
                        <th colspan="2" class="text-center">Em aberto</th>
                        <th rowspan="2" class="text-end">Saldo FCX</th>
                        <th rowspan="2" class="text-end">Saldo OFX</th>
                        <th rowspan="2" class="text-end">Σ Déb OFX</th>
                        <th rowspan="2" class="text-end">Σ Cré OFX</th>
                    </tr>
                    <tr>
                        <th class="text-end">qtd</th>
                        <th class="text-end">soma</th>
                        <th class="text-end">qtd</th>
                        <th class="text-end">soma</th>
                        <th class="text-end">a pagar</th>
                        <th class="text-end">a receber</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linhasBanco as $b): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?=$h($b['apelido'])?></div>
                            <div class="small-muted">cód. <?=$h($b['codigo'])?></div>
                        </td>
                        <td class="num"><?=$h($b['ag_conta'])?></td>

                        <td class="num text-end"><?=$num($b['pagas_qtd'])?></td>
                        <td class="num text-end neg"><?=$brl($b['pagas_soma'])?></td>

                        <td class="num text-end"><?=$num($b['receb_qtd'])?></td>
                        <td class="num text-end pos"><?=$brl($b['receb_soma'])?></td>

                        <td class="num text-end">
                            <?=$brl($b['aber_p_soma'])?>
                            <div class="small-muted"><?=$num($b['aber_p_qtd'])?> conta(s)</div>
                        </td>
                        <td class="num text-end">
                            <?=$brl($b['aber_r_soma'])?>
                            <div class="small-muted"><?=$num($b['aber_r_qtd'])?> conta(s)</div>
                        </td>

                        <td class="num text-end">
                            <?php if ($b['fcb_saldo'] !== null): ?>
                                <span class="<?=$b['fcb_saldo']<0?'neg':''?>"><?=$brl($b['fcb_saldo'])?></span>
                                <div class="small-muted"><?=$b['fcb_data']?date('d/m/Y',strtotime($b['fcb_data'])):'—'?></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="num text-end">
                            <?php if ($b['ofx_saldo'] !== null): ?>
                                <?=$brl($b['ofx_saldo'])?>
                                <div class="small-muted"><?=$b['ofx_data']?date('d/m/Y',strtotime($b['ofx_data'])):'—'?></div>
                            <?php else: ?>
                                <span class="text-muted">sem OFX</span>
                            <?php endif; ?>
                        </td>
                        <td class="num text-end">
                            <?=$brl($b['ofx_deb_soma'])?>
                            <div class="small-muted"><?=$num($b['ofx_deb_qtd'])?> mov.</div>
                        </td>
                        <td class="num text-end">
                            <?=$brl($b['ofx_cre_soma'])?>
                            <div class="small-muted"><?=$num($b['ofx_cre_qtd'])?> mov.</div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="3" class="text-end">Totais</th>
                        <th class="num text-end neg"><?=$brl($totSomaPagas)?></th>
                        <th class="text-end"></th>
                        <th class="num text-end pos"><?=$brl($totSomaReceb)?></th>
                        <th class="num text-end"><?=$brl($totAbP)?></th>
                        <th class="num text-end"><?=$brl($totAbR)?></th>
                        <th></th><th></th>
                        <th class="num text-end"><?=$brl($totDebOfx)?></th>
                        <th class="num text-end"><?=$brl($totCreOfx)?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ============== FLUXO CRUZADO POR BANCO ============== -->
    <div class="card kpi-card mb-3">
        <div class="card-header bg-white border-0 pt-3 pb-2">
            <h6 class="fw-bold mb-1"><i class="bi bi-arrow-left-right me-2"></i>Fluxo cruzado: sistema vs OFX, por banco</h6>
            <div class="small-muted">
                Σ Recebido − Σ Pago no sistema vs Σ Crédito − Σ Débito no OFX. Diferença próxima de zero = OFX e sistema concordam sobre a movimentação líquida do banco.
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Banco</th>
                        <th class="text-end">Fluxo sistema (rec − pag)</th>
                        <th class="text-end">Fluxo OFX (cred − deb)</th>
                        <th class="text-end">Diferença</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linhasBanco as $b): $dif = $b['fluxo_sis'] - $b['fluxo_ofx']; ?>
                    <tr>
                        <td><?=$h($b['apelido'])?></td>
                        <td class="num text-end <?=$b['fluxo_sis']<0?'neg':'pos'?>"><?=$brl($b['fluxo_sis'])?></td>
                        <td class="num text-end <?=$b['fluxo_ofx']<0?'neg':'pos'?>"><?=$brl($b['fluxo_ofx'])?></td>
                        <td class="num text-end fw-semibold <?=abs($dif)<0.01?'pos':'neg'?>"><?=$brl($dif)?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============== ÓRFÃS DE BANCO — 4 LISTAS ============== -->
    <div class="section-title"><i class="bi bi-search me-1"></i>LANÇAMENTOS SEM BANCO VINCULADO</div>

    <!-- 3.1 Pagas sem banco -->
    <details class="card kpi-card mb-2" <?=count($lstPagasOrf)>0?'open':''?>>
        <summary class="card-header bg-white border-0 pt-3 pb-2">
            <h6 class="fw-bold mb-1 d-inline">
                <i class="bi bi-arrow-up-right text-danger me-1"></i>Contas a pagar PAGAS sem banco
                <span class="badge badge-soft-warn ms-2"><?=count($lstPagasOrf)?></span>
            </h6>
            <div class="small-muted">Pagamento já registrado mas sem identificação do banco que pagou. Abrir cada uma e preencher o banco.</div>
        </summary>
        <div class="table-responsive" style="max-height:380px;overflow:auto">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr>
                    <th style="width:80px">ID</th>
                    <th style="width:100px">Pagto</th>
                    <th>Descrição</th>
                    <th>Fornecedor</th>
                    <th style="width:130px">Documento</th>
                    <th style="width:120px" class="text-end">Valor pago</th>
                </tr></thead>
                <tbody>
                <?php if (empty($lstPagasOrf)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Nenhuma. ✓</td></tr>
                <?php else: foreach ($lstPagasOrf as $o): ?>
                    <tr>
                        <td class="num"><a href="contas_pagar.php?abrir=<?=$h($o['CPG_CODIGO_PK'])?>" target="_blank">#<?=$h($o['CPG_CODIGO_PK'])?></a></td>
                        <td class="num"><?=$o['CPG_DATA_PAGAMENTO']?date('d/m/Y',strtotime($o['CPG_DATA_PAGAMENTO'])):'—'?></td>
                        <td><?=$h($o['descricao'])?></td>
                        <td><?=$h($o['FOR_NOME_FANTASIA'] ?: $o['FOR_RAZAO_SOCIAL'] ?: '—')?></td>
                        <td class="num"><?=$h($o['CPG_DOCUMENTO'] ?: $o['CPG_NOTA_FISCAL'] ?: '—')?></td>
                        <td class="num text-end"><?=$brl($o['CPG_VALOR_PAGO'] ?: $o['CPG_VALOR_PARCELA'])?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </details>

    <!-- 3.2 Abertas sem banco -->
    <details class="card kpi-card mb-2" <?=count($lstAbertasPagarOrf)>0?'open':''?>>
        <summary class="card-header bg-white border-0 pt-3 pb-2">
            <h6 class="fw-bold mb-1 d-inline">
                <i class="bi bi-hourglass-split text-warning me-1"></i>Contas a pagar EM ABERTO/ATRASADAS sem banco previsto
                <span class="badge badge-soft-warn ms-2"><?=count($lstAbertasPagarOrf)?></span>
            </h6>
            <div class="small-muted">Não têm banco indicado para o pagamento. Quando forem pagas, vão herdar essa lacuna se nada for ajustado.</div>
        </summary>
        <div class="table-responsive" style="max-height:380px;overflow:auto">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr>
                    <th style="width:80px">ID</th>
                    <th style="width:100px">Vencto</th>
                    <th style="width:90px">Status</th>
                    <th>Descrição</th>
                    <th>Fornecedor</th>
                    <th style="width:130px">Documento</th>
                    <th style="width:120px" class="text-end">Valor</th>
                </tr></thead>
                <tbody>
                <?php if (empty($lstAbertasPagarOrf)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">Nenhuma. ✓</td></tr>
                <?php else: foreach ($lstAbertasPagarOrf as $o): ?>
                    <tr>
                        <td class="num"><a href="contas_pagar.php?abrir=<?=$h($o['CPG_CODIGO_PK'])?>" target="_blank">#<?=$h($o['CPG_CODIGO_PK'])?></a></td>
                        <td class="num"><?=$o['CPG_VENCIMENTO']?date('d/m/Y',strtotime($o['CPG_VENCIMENTO'])):'—'?></td>
                        <td><span class="badge bg-secondary"><?=$h($o['CPG_STATUS'])?></span></td>
                        <td><?=$h($o['descricao'])?></td>
                        <td><?=$h($o['FOR_NOME_FANTASIA'] ?: $o['FOR_RAZAO_SOCIAL'] ?: '—')?></td>
                        <td class="num"><?=$h($o['CPG_DOCUMENTO'] ?: '—')?></td>
                        <td class="num text-end"><?=$brl($o['CPG_VALOR_PARCELA'])?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </details>

    <!-- 3.3 Recebidas sem banco -->
    <details class="card kpi-card mb-2" <?=count($lstRecebOrf)>0?'open':''?>>
        <summary class="card-header bg-white border-0 pt-3 pb-2">
            <h6 class="fw-bold mb-1 d-inline">
                <i class="bi bi-arrow-down-right text-success me-1"></i>Contas a receber RECEBIDAS sem banco
                <span class="badge badge-soft-warn ms-2"><?=count($lstRecebOrf)?></span>
            </h6>
            <div class="small-muted">Recebimento já registrado mas sem identificação do banco que recebeu. Abrir cada uma e preencher o banco.</div>
        </summary>
        <div class="table-responsive" style="max-height:380px;overflow:auto">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr>
                    <th style="width:80px">ID</th>
                    <th style="width:100px">Recebto</th>
                    <th>Descrição</th>
                    <th>Cliente</th>
                    <th style="width:130px">Documento</th>
                    <th style="width:120px" class="text-end">Valor recebido</th>
                </tr></thead>
                <tbody>
                <?php if (empty($lstRecebOrf)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Nenhuma. ✓</td></tr>
                <?php else: foreach ($lstRecebOrf as $o): ?>
                    <tr>
                        <td class="num"><a href="contas_receber.php?abrir=<?=$h($o['CRE_ID'])?>" target="_blank">#<?=$h($o['CRE_ID'])?></a></td>
                        <td class="num"><?=$o['CRE_RECEBIDO_EM']?date('d/m/Y',strtotime($o['CRE_RECEBIDO_EM'])):'—'?></td>
                        <td><?=$h($o['descricao'])?></td>
                        <td><?=$h(($o['CLI_NOME_RESOLVIDO'] ?? '') ?: ($o['CRE_CLIENTE_NOME'] ?? '') ?: '—')?></td>
                        <td class="num"><?=$h($o['CRE_DOCUMENTO'] ?: '—')?></td>
                        <td class="num text-end"><?=$brl($o['CRE_VALOR_RECEBIDO'] ?: $o['CRE_VALOR'])?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </details>

    <!-- 3.4 Receber em aberto sem banco -->
    <details class="card kpi-card mb-3" <?=count($lstAbertasRecebOrf)>0?'open':''?>>
        <summary class="card-header bg-white border-0 pt-3 pb-2">
            <h6 class="fw-bold mb-1 d-inline">
                <i class="bi bi-hourglass text-info me-1"></i>Contas a receber EM ABERTO/PROGRAMADAS sem banco previsto
                <span class="badge badge-soft-warn ms-2"><?=count($lstAbertasRecebOrf)?></span>
            </h6>
            <div class="small-muted">Não têm banco indicado para o recebimento. Quando entrarem, vão herdar essa lacuna se nada for ajustado.</div>
        </summary>
        <div class="table-responsive" style="max-height:380px;overflow:auto">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr>
                    <th style="width:80px">ID</th>
                    <th style="width:100px">Vencto</th>
                    <th style="width:100px">Status</th>
                    <th>Descrição</th>
                    <th>Cliente</th>
                    <th style="width:130px">Documento</th>
                    <th style="width:120px" class="text-end">Valor</th>
                </tr></thead>
                <tbody>
                <?php if (empty($lstAbertasRecebOrf)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">Nenhuma. ✓</td></tr>
                <?php else: foreach ($lstAbertasRecebOrf as $o): ?>
                    <tr>
                        <td class="num"><a href="contas_receber.php?abrir=<?=$h($o['CRE_ID'])?>" target="_blank">#<?=$h($o['CRE_ID'])?></a></td>
                        <td class="num"><?=$o['CRE_VENCIMENTO']?date('d/m/Y',strtotime($o['CRE_VENCIMENTO'])):'—'?></td>
                        <td><span class="badge bg-secondary"><?=$h($o['CRE_STATUS'])?></span></td>
                        <td><?=$h($o['descricao'])?></td>
                        <td><?=$h(($o['CLI_NOME_RESOLVIDO'] ?? '') ?: ($o['CRE_CLIENTE_NOME'] ?? '') ?: '—')?></td>
                        <td class="num"><?=$h($o['CRE_DOCUMENTO'] ?: '—')?></td>
                        <td class="num text-end"><?=$brl($o['CRE_VALOR'])?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </details>

    <!-- ============== VALIDAÇÃO GERAL ============== -->
    <div class="card kpi-card mb-3">
        <div class="card-header bg-white border-0 pt-3 pb-2">
            <h6 class="fw-bold mb-1"><i class="bi bi-check2-square me-2"></i>Validação cruzada — totais</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="label small-muted text-uppercase mb-1">DÉBITOS</div>
                    <div class="d-flex justify-content-between"><span>Σ contas pagas (com banco)</span><strong class="num"><?=$brl($totSomaPagas)?></strong></div>
                    <div class="d-flex justify-content-between"><span>Σ débitos OFX importados</span><strong class="num"><?=$brl($totalOfx['soma_deb'])?> <span class="small-muted">(<?=$num($totalOfx['qtd_deb'])?> mov.)</span></strong></div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between"><span class="fw-semibold">Diferença</span>
                        <strong class="num <?=abs($totSomaPagas-(float)$totalOfx['soma_deb'])<0.01?'pos':'neg'?>"><?=$brl($totSomaPagas-(float)$totalOfx['soma_deb'])?></strong>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="label small-muted text-uppercase mb-1">CRÉDITOS</div>
                    <div class="d-flex justify-content-between"><span>Σ contas recebidas (com banco)</span><strong class="num"><?=$brl($totSomaReceb)?></strong></div>
                    <div class="d-flex justify-content-between"><span>Σ créditos OFX importados</span><strong class="num"><?=$brl($totalOfx['soma_cre'])?> <span class="small-muted">(<?=$num($totalOfx['qtd_cre'])?> mov.)</span></strong></div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between"><span class="fw-semibold">Diferença</span>
                        <strong class="num <?=abs($totSomaReceb-(float)$totalOfx['soma_cre'])<0.01?'pos':'neg'?>"><?=$brl($totSomaReceb-(float)$totalOfx['soma_cre'])?></strong>
                    </div>
                </div>
            </div>
            <hr>
            <div class="small-muted">
                <strong>Como ler:</strong>
                Diferença <strong>positiva</strong> = sistema tem mais lançamentos que o OFX importado (faltam OFX antigos, ou movimentações fora do banco).
                Diferença <strong>negativa</strong> = OFX tem mais movimento que o sistema (movimentos OFX ainda não conciliados/lançados).
                O ideal é diferença ≈ 0 quando todo o histórico OFX está importado e conciliado.
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/session_keeper.js" defer></script>
</body>
</html>
