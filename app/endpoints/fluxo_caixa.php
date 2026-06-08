<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexao.php'; // <- seu padrão
require_once __DIR__ . '/../config/status_dict.php';
require_once __DIR__ . '/../config/saldos.php';

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Conexão com banco não encontrada.');
    }
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Erro de conexão: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function out($data)
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function inputJson()
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function hoje()
{
    return date('Y-m-d');
}

function usuarioIdAtual()
{
    // Sessão do login usa 'user_id'. 'usuario_id' era nome antigo — mantém fallback.
    if (!empty($_SESSION['user_id']))    return (int)$_SESSION['user_id'];
    if (!empty($_SESSION['usuario_id'])) return (int)$_SESSION['usuario_id'];
    return null;
}

function tabelaExiste(PDO $pdo, string $tabela): bool
{
    static $cache = [];
    if (array_key_exists($tabela, $cache)) return $cache[$tabela];

    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$tabela]);
    return $cache[$tabela] = (int)$st->fetchColumn() > 0;
}

function colunaExiste(PDO $pdo, string $tabela, string $coluna): bool
{
    static $cache = [];
    $chave = $tabela . '.' . $coluna;
    if (array_key_exists($chave, $cache)) return $cache[$chave];

    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$tabela, $coluna]);
    return $cache[$chave] = (int)$st->fetchColumn() > 0;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {

    if ($method === 'GET') {
        $acao = $_GET['acao'] ?? '';

        if ($acao === 'resumo_periodo') {
            $dataIni = $_GET['data_ini'] ?? date('Y-m-01');
            $dataFim = $_GET['data_fim'] ?? hoje();
            $empresaId = (int)($_GET['empresa_id'] ?? 0);
            $bancoId = (int)($_GET['banco_id'] ?? 0);

            $phCrePago = sql_placeholders(CRE_STATUS_PAGO);
            $phCpgPago = sql_placeholders(CPG_STATUS_PAGO);

            // Entradas: contas recebidas no período — usa valor efetivamente recebido
            $sqlRec = "
                SELECT COALESCE(SUM(COALESCE(NULLIF(CRE_VALOR_RECEBIDO,0), CRE_VALOR)), 0) AS total
                FROM tb_contas_receber
                WHERE CRE_STATUS IN ({$phCrePago})
                  AND CRE_RECEBIDO_EM IS NOT NULL
                  AND CRE_RECEBIDO_EM >= ?
                  AND CRE_RECEBIDO_EM <  DATE_ADD(?, INTERVAL 1 DAY)
            ";
            $paramsRec = array_merge(CRE_STATUS_PAGO, [$dataIni, $dataFim]);
            if ($empresaId > 0) {
                $sqlRec .= " AND CRE_EMPRESA_FK = ?";
                $paramsRec[] = $empresaId;
            }
            if ($bancoId > 0 && colunaExiste($pdo, 'tb_contas_receber', 'CRE_BANCO_FK')) {
                $sqlRec .= " AND CRE_BANCO_FK = ?";
                $paramsRec[] = $bancoId;
            }
            $st = $pdo->prepare($sqlRec);
            $st->execute($paramsRec);
            $entradas = (float)$st->fetchColumn();

            // Saídas: contas pagas no período
            $sqlPag = "
                SELECT COALESCE(SUM(COALESCE(CPG_VALOR_PAGO, CPG_VALOR_PARCELA)), 0) AS total
                FROM tb_contas_pagar
                WHERE CPG_STATUS IN ({$phCpgPago})
                  AND CPG_DATA_PAGAMENTO IS NOT NULL
                  AND CPG_DATA_PAGAMENTO >= ?
                  AND CPG_DATA_PAGAMENTO <  DATE_ADD(?, INTERVAL 1 DAY)
            ";
            $paramsPag = array_merge(CPG_STATUS_PAGO, [$dataIni, $dataFim]);
            if ($empresaId > 0) {
                $sqlPag .= " AND CPG_EMPRESA_FK = ?";
                $paramsPag[] = $empresaId;
            }
            if ($bancoId > 0 && colunaExiste($pdo, 'tb_contas_pagar', 'CPG_BANCO_PAGAMENTO_FK')) {
                $sqlPag .= " AND CPG_BANCO_PAGAMENTO_FK = ?";
                $paramsPag[] = $bancoId;
            }
            $st = $pdo->prepare($sqlPag);
            $st->execute($paramsPag);
            $saidas = (float)$st->fetchColumn();

            // Movimentos manuais/bancários em tb_fluxo_caixa no mesmo período
            if (tabelaExiste($pdo, 'tb_fluxo_caixa')) {
                $sqlFlc = "
                    SELECT
                        COALESCE(SUM(CASE WHEN FLC_TIPO = 'ENTRADA' THEN FLC_VALOR ELSE 0 END),0) AS ent,
                        COALESCE(SUM(CASE WHEN FLC_TIPO = 'SAIDA' THEN FLC_VALOR ELSE 0 END),0) AS sai
                    FROM tb_fluxo_caixa
                    WHERE FLC_DATA >= ?
                      AND FLC_DATA <  DATE_ADD(?, INTERVAL 1 DAY)
                ";
                $paramsFlc = [$dataIni, $dataFim];
                if ($bancoId > 0) {
                    $sqlFlc .= " AND FLC_BANCO_FK = ?";
                    $paramsFlc[] = $bancoId;
                }
                $st = $pdo->prepare($sqlFlc);
                $st->execute($paramsFlc);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['ent' => 0, 'sai' => 0];
                $entradas += (float)$r['ent'];
                $saidas += (float)$r['sai'];
            }

            out([
                'ok' => true,
                'entradas' => $entradas,
                'saidas' => $saidas,
                'saldo' => $entradas - $saidas
            ]);
        }

        if ($acao === 'listar_saldos_bancos') {
            // Fonte da verdade: Conciliação Bancária. Esta tela apenas consome.
            $sqlBancos = "
                SELECT
                    BAN_ID,
                    BAN_APELIDO,
                    BAN_NOME,
                    BAN_AGENCIA,
                    BAN_AGENCIA_DV,
                    BAN_CONTA,
                    BAN_CONTA_DV
                FROM tb_banco
                WHERE BAN_STATUS = 'ATIVO'
                ORDER BY BAN_APELIDO, BAN_NOME
            ";
            $bancos = $pdo->query($sqlBancos)->fetchAll(PDO::FETCH_ASSOC);

            // Última data de conciliação (apenas informativa)
            $stUltConc = $pdo->prepare("
                SELECT MAX(COM_DATA_MOVIMENTO)
                FROM tb_conciliacao_ofx_movimento
                WHERE COM_BANCO_FK = ? AND COM_CONCILIADO = 'SIM' AND COM_DATA_MOVIMENTO <= CURDATE()
            ");

            // Entradas/saídas só do dia de hoje (informativo, não compõem o saldo)
            $phCre = sql_placeholders(CRE_STATUS_PAGO);
            $phCpg = sql_placeholders(CPG_STATUS_PAGO);
            $sqlEntHoje = "SELECT COALESCE(SUM(COALESCE(NULLIF(CRE_VALOR_RECEBIDO,0), CRE_VALOR)),0)
                           FROM tb_contas_receber
                           WHERE CRE_BANCO_FK = ? AND CRE_STATUS IN ({$phCre}) AND CRE_RECEBIDO_EM = CURDATE()";
            $sqlSaiHoje = "SELECT COALESCE(SUM(COALESCE(CPG_VALOR_PAGO, CPG_VALOR_PARCELA)),0)
                           FROM tb_contas_pagar
                           WHERE CPG_BANCO_PAGAMENTO_FK = ? AND CPG_STATUS IN ({$phCpg}) AND DATE(CPG_DATA_PAGAMENTO) = CURDATE()";
            $stEntHoje = $pdo->prepare($sqlEntHoje);
            $stSaiHoje = $pdo->prepare($sqlSaiHoje);

            foreach ($bancos as &$b) {
                $banId    = (int)$b['BAN_ID'];
                $contaRef = contaRefBanco($b);

                // ===== SALDO VEM DA CONCILIAÇÃO =====
                $saldoAtual = saldoErpConta($pdo, $banId, $contaRef);

                $stUltConc->execute([$banId]);
                $ultimaConc = $stUltConc->fetchColumn() ?: null;

                $stEntHoje->execute(array_merge([$banId], CRE_STATUS_PAGO));
                $entHoje = (float)$stEntHoje->fetchColumn();
                $stSaiHoje->execute(array_merge([$banId], CPG_STATUS_PAGO));
                $saiHoje = (float)$stSaiHoje->fetchColumn();

                // Mantém os mesmos nomes de coluna que o front consome
                $b['FCB_SALDO_ATUAL']   = round($saldoAtual, 2);
                $b['FCB_ENTRADAS_DIA']  = round($entHoje, 2);
                $b['FCB_SAIDAS_DIA']    = round($saiHoje, 2);
                $b['FCB_DATA']          = date('Y-m-d');
                $b['ULTIMA_CONCILIACAO'] = $ultimaConc;
                // Compatibilidade com chaves antigas (front pode olhar)
                $b['SALDO_INICIAL']        = $b['FCB_SALDO_ATUAL'];
                $b['SALDO_INICIAL_DATA']   = null;
                $b['ENTRADAS_APOS_AJUSTE'] = $b['FCB_ENTRADAS_DIA'];
                $b['SAIDAS_APOS_AJUSTE']   = $b['FCB_SAIDAS_DIA'];
            }
            unset($b);

            out([
                'ok'   => true,
                'rows' => $bancos,
            ]);
        }

        if ($acao === 'listar_movimentacoes') {
            $tipo      = trim($_GET['tipo'] ?? '');
            $dataIni   = trim($_GET['data_ini'] ?? '');
            $dataFim   = trim($_GET['data_fim'] ?? '');
            $busca     = trim($_GET['busca'] ?? '');
            $empresaId = (int)($_GET['empresa_id'] ?? 0);
            $bancoId   = (int)($_GET['banco_id'] ?? 0);

            $phCrePago = sql_placeholders(CRE_STATUS_PAGO);
            $phCpgPago = sql_placeholders(CPG_STATUS_PAGO);

            $hasBancoCre = colunaExiste($pdo, 'tb_contas_receber', 'CRE_BANCO_FK');
            $hasBancoCpg = colunaExiste($pdo, 'tb_contas_pagar', 'CPG_BANCO_PAGAMENTO_FK');

            $rows = [];

            // ---- Entradas: contas recebidas ----
            if ($tipo === '' || $tipo === 'ENTRADA') {
                $whereRec = ["CRE_STATUS IN ({$phCrePago})", "CRE_RECEBIDO_EM IS NOT NULL"];
                $paramsRec = CRE_STATUS_PAGO;

                if ($dataIni !== '') {
                    $whereRec[] = "CRE_RECEBIDO_EM >= ?";
                    $paramsRec[] = $dataIni;
                }
                if ($dataFim !== '') {
                    $whereRec[] = "CRE_RECEBIDO_EM < DATE_ADD(?, INTERVAL 1 DAY)";
                    $paramsRec[] = $dataFim;
                }
                if ($busca !== '') {
                    $whereRec[] = "(CRE_CLIENTE_NOME LIKE ? OR CRE_DOCUMENTO LIKE ? OR CRE_OBSERVACAO LIKE ?)";
                    $b = '%' . $busca . '%';
                    $paramsRec[] = $b; $paramsRec[] = $b; $paramsRec[] = $b;
                }
                if ($empresaId > 0) {
                    $whereRec[] = "CRE_EMPRESA_FK = ?";
                    $paramsRec[] = $empresaId;
                }
                if ($bancoId > 0 && $hasBancoCre) {
                    $whereRec[] = "CRE_BANCO_FK = ?";
                    $paramsRec[] = $bancoId;
                }

                $sqlRec = "SELECT
                    CRE_ID AS id,
                    CRE_RECEBIDO_EM AS FLC_DATA,
                    'ENTRADA' AS FLC_TIPO,
                    COALESCE(CRE_CLIENTE_NOME, 'Recebimento') AS FLC_DESCRICAO,
                    COALESCE(CRE_DOCUMENTO, '') AS FLC_DOCUMENTO,
                    COALESCE(NULLIF(CRE_VALOR_RECEBIDO,0), CRE_VALOR) AS FLC_VALOR,
                    'CONTAS A RECEBER' AS FLC_ORIGEM
                FROM tb_contas_receber
                WHERE " . implode(' AND ', $whereRec) . "
                ORDER BY CRE_RECEBIDO_EM DESC
                LIMIT 200";

                $st = $pdo->prepare($sqlRec);
                $st->execute($paramsRec);
                $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
            }

            // ---- Saídas: contas pagas ----
            if ($tipo === '' || $tipo === 'SAIDA') {
                $wherePag = ["CPG_STATUS IN ({$phCpgPago})", "CPG_DATA_PAGAMENTO IS NOT NULL"];
                $paramsPag = CPG_STATUS_PAGO;

                if ($dataIni !== '') {
                    $wherePag[] = "CPG_DATA_PAGAMENTO >= ?";
                    $paramsPag[] = $dataIni;
                }
                if ($dataFim !== '') {
                    $wherePag[] = "CPG_DATA_PAGAMENTO < DATE_ADD(?, INTERVAL 1 DAY)";
                    $paramsPag[] = $dataFim;
                }
                if ($busca !== '') {
                    $wherePag[] = "(CPG_DESCRICAO LIKE ? OR CPG_DOCUMENTO LIKE ? OR CPG_NOTA_FISCAL LIKE ?)";
                    $b = '%' . $busca . '%';
                    $paramsPag[] = $b; $paramsPag[] = $b; $paramsPag[] = $b;
                }
                if ($empresaId > 0) {
                    $wherePag[] = "CPG_EMPRESA_FK = ?";
                    $paramsPag[] = $empresaId;
                }
                if ($bancoId > 0 && $hasBancoCpg) {
                    $wherePag[] = "CPG_BANCO_PAGAMENTO_FK = ?";
                    $paramsPag[] = $bancoId;
                }

                $sqlPag = "SELECT
                    CPG_CODIGO_PK AS id,
                    CPG_DATA_PAGAMENTO AS FLC_DATA,
                    'SAIDA' AS FLC_TIPO,
                    COALESCE(CPG_DESCRICAO, 'Pagamento') AS FLC_DESCRICAO,
                    COALESCE(CPG_DOCUMENTO, '') AS FLC_DOCUMENTO,
                    COALESCE(CPG_VALOR_PAGO, CPG_VALOR_PARCELA) AS FLC_VALOR,
                    'CONTAS A PAGAR' AS FLC_ORIGEM
                FROM tb_contas_pagar
                WHERE " . implode(' AND ', $wherePag) . "
                ORDER BY CPG_DATA_PAGAMENTO DESC
                LIMIT 200";

                $st = $pdo->prepare($sqlPag);
                $st->execute($paramsPag);
                $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
            }

            // ---- Movimentações bancárias diretas (tb_fluxo_caixa) ----
            if (tabelaExiste($pdo, 'tb_fluxo_caixa')) {
                $hasDescr = colunaExiste($pdo, 'tb_fluxo_caixa', 'FLC_DESCRICAO');
                $hasDoc   = colunaExiste($pdo, 'tb_fluxo_caixa', 'FLC_DOCUMENTO');
                $hasOrig  = colunaExiste($pdo, 'tb_fluxo_caixa', 'FLC_ORIGEM');
                $hasId    = colunaExiste($pdo, 'tb_fluxo_caixa', 'FLC_ID');

                $descExpr = $hasDescr ? "COALESCE(FLC_DESCRICAO,'Movimento bancário')" : "'Movimento bancário'";
                $docExpr  = $hasDoc   ? "COALESCE(FLC_DOCUMENTO,'')" : "''";
                $origExpr = $hasOrig  ? "COALESCE(FLC_ORIGEM,'FLUXO DE CAIXA')" : "'FLUXO DE CAIXA'";
                $idExpr   = $hasId    ? "FLC_ID" : "0";

                $whereFlc = ["FLC_DATA IS NOT NULL"];
                $paramsFlc = [];

                if ($tipo === 'ENTRADA' || $tipo === 'SAIDA') {
                    $whereFlc[] = "FLC_TIPO = ?";
                    $paramsFlc[] = $tipo;
                }
                if ($dataIni !== '') {
                    $whereFlc[] = "FLC_DATA >= ?";
                    $paramsFlc[] = $dataIni;
                }
                if ($dataFim !== '') {
                    $whereFlc[] = "FLC_DATA < DATE_ADD(?, INTERVAL 1 DAY)";
                    $paramsFlc[] = $dataFim;
                }
                if ($bancoId > 0) {
                    $whereFlc[] = "FLC_BANCO_FK = ?";
                    $paramsFlc[] = $bancoId;
                }
                if ($busca !== '' && ($hasDescr || $hasDoc)) {
                    $cond = [];
                    if ($hasDescr) $cond[] = "FLC_DESCRICAO LIKE ?";
                    if ($hasDoc)   $cond[] = "FLC_DOCUMENTO LIKE ?";
                    $whereFlc[] = '(' . implode(' OR ', $cond) . ')';
                    $b = '%' . $busca . '%';
                    foreach ($cond as $_) $paramsFlc[] = $b;
                }

                $sqlFlc = "SELECT
                    {$idExpr} AS id,
                    FLC_DATA,
                    FLC_TIPO,
                    {$descExpr} AS FLC_DESCRICAO,
                    {$docExpr} AS FLC_DOCUMENTO,
                    FLC_VALOR,
                    {$origExpr} AS FLC_ORIGEM
                FROM tb_fluxo_caixa
                WHERE " . implode(' AND ', $whereFlc) . "
                ORDER BY FLC_DATA DESC
                LIMIT 200";

                try {
                    $st = $pdo->prepare($sqlFlc);
                    $st->execute($paramsFlc);
                    $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
                } catch (Throwable $e) {
                    // Falha defensiva: se a estrutura da tabela divergir, ignora esta origem sem quebrar a tela.
                    error_log('fluxo_caixa tb_fluxo_caixa select falhou: ' . $e->getMessage());
                }
            }

            // Ordenar por data desc
            usort($rows, function($a, $b) {
                return strcmp((string)($b['FLC_DATA'] ?? ''), (string)($a['FLC_DATA'] ?? ''));
            });

            // Limitar a 300
            $rows = array_slice($rows, 0, 300);

            out([
                'ok' => true,
                'rows' => $rows
            ]);
        }

        if ($acao === 'listar_liberacao_pagamento') {
            $busca    = trim($_GET['busca'] ?? '');
            $visao    = trim($_GET['visao'] ?? 'TODAS');
            $status   = trim($_GET['status'] ?? '');
            $dtIni    = trim($_GET['dt_ini'] ?? '');
            $dtFim    = trim($_GET['dt_fim'] ?? '');
            $empresa  = (int)($_GET['empresa'] ?? 0);
            $valorMin = trim($_GET['valor_min'] ?? '');
            $valorMax = trim($_GET['valor_max'] ?? '');

            // Sem filtro de data: retorna todos os lançamentos. O LIMIT 500 abaixo
            // já protege contra varredura excessiva. Respeitamos o estado da UI: se
            // o usuário limpou as datas, não reimpomos um recorte silencioso.

            $where = [];
            $params = [];

            if ($status !== '') {
                $where[] = "cp.CPG_STATUS = :status";
                $params[':status'] = $status;
            }

            if ($visao === 'PENDENTES') {
                $where[] = "COALESCE(cp.CPG_AUTORIZACAO_STATUS, 'PENDENTE') = 'PENDENTE'";
            } elseif ($visao === 'AUTORIZADAS') {
                $where[] = "COALESCE(cp.CPG_AUTORIZACAO_STATUS, 'PENDENTE') = 'AUTORIZADO'";
            }

            if ($dtIni !== '') {
                $where[] = "cp.CPG_VENCIMENTO >= :dtIni";
                $params[':dtIni'] = $dtIni;
            }
            if ($dtFim !== '') {
                $where[] = "cp.CPG_VENCIMENTO <= :dtFim";
                $params[':dtFim'] = $dtFim;
            }

            if ($empresa > 0) {
                $where[] = "cp.CPG_EMPRESA_FK = :emp";
                $params[':emp'] = $empresa;
            }
            if ($valorMin !== '' && is_numeric($valorMin)) {
                $where[] = "cp.CPG_VALOR_PARCELA >= :vmin";
                $params[':vmin'] = (float)$valorMin;
            }
            if ($valorMax !== '' && is_numeric($valorMax)) {
                $where[] = "cp.CPG_VALOR_PARCELA <= :vmax";
                $params[':vmax'] = (float)$valorMax;
            }

            if ($busca !== '') {
                $where[] = "(
                    f.FOR_RAZAO_SOCIAL LIKE :b1
                    OR f.FOR_NOME_FANTASIA LIKE :b2
                    OR cp.CPG_DOCUMENTO LIKE :b3
                    OR cp.CPG_NOTA_FISCAL LIKE :b4
                    OR cp.CPG_DESCRICAO LIKE :b5
                )";
                $bv = '%' . $busca . '%';
                $params[':b1'] = $bv;
                $params[':b2'] = $bv;
                $params[':b3'] = $bv;
                $params[':b4'] = $bv;
                $params[':b5'] = $bv;
            }

            $whereSql = $where ? (" WHERE " . implode(' AND ', $where)) : '';

            $sql = "
                SELECT
                    cp.CPG_CODIGO_PK,
                    cp.CPG_VENCIMENTO,
                    cp.CPG_VALOR_PARCELA,
                    COALESCE(NULLIF(TRIM(cp.CPG_STATUS),''), 'ABERTO') AS CPG_STATUS,
                    cp.CPG_DOCUMENTO,
                    cp.CPG_NOTA_FISCAL,
                    COALESCE(cp.CPG_AUTORIZACAO_STATUS,'PENDENTE') AS CPG_AUTORIZACAO_STATUS,
                    f.FOR_RAZAO_SOCIAL,
                    f.FOR_NOME_FANTASIA,
                    f.FOR_CNPJ
                FROM tb_contas_pagar cp
                LEFT JOIN tb_fornecedor f
                    ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
                $whereSql
                ORDER BY cp.CPG_VENCIMENTO ASC, cp.CPG_CODIGO_PK DESC
                LIMIT 500
            ";

            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // KPIs respeitando filtros ativos (empresa, período e status em aberto)
            $phCpgAberto = sql_placeholders(CPG_STATUS_EM_ABERTO);
            $sqlAgg = "SELECT
                    SUM(CASE WHEN COALESCE(cp.CPG_AUTORIZACAO_STATUS,'PENDENTE') = 'PENDENTE' THEN 1 ELSE 0 END) AS qtd_pend,
                    SUM(CASE WHEN COALESCE(cp.CPG_AUTORIZACAO_STATUS,'PENDENTE') = 'PENDENTE' THEN cp.CPG_VALOR_PARCELA ELSE 0 END) AS tot_pend,
                    SUM(CASE WHEN COALESCE(cp.CPG_AUTORIZACAO_STATUS,'PENDENTE') = 'AUTORIZADO' THEN cp.CPG_VALOR_PARCELA ELSE 0 END) AS tot_aut
                FROM tb_contas_pagar cp
                LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
                WHERE cp.CPG_STATUS IN ({$phCpgAberto})";
            $paramsAgg = CPG_STATUS_EM_ABERTO;

            // Reaplica filtros (mantém coerência com a listagem)
            if ($dtIni !== '')    { $sqlAgg .= " AND cp.CPG_VENCIMENTO >= ?"; $paramsAgg[] = $dtIni; }
            if ($dtFim !== '')    { $sqlAgg .= " AND cp.CPG_VENCIMENTO <= ?"; $paramsAgg[] = $dtFim; }
            if ($empresa > 0)     { $sqlAgg .= " AND cp.CPG_EMPRESA_FK = ?"; $paramsAgg[] = $empresa; }
            if ($valorMin !== '' && is_numeric($valorMin)) { $sqlAgg .= " AND cp.CPG_VALOR_PARCELA >= ?"; $paramsAgg[] = (float)$valorMin; }
            if ($valorMax !== '' && is_numeric($valorMax)) { $sqlAgg .= " AND cp.CPG_VALOR_PARCELA <= ?"; $paramsAgg[] = (float)$valorMax; }
            if ($busca !== '') {
                $sqlAgg .= " AND (f.FOR_RAZAO_SOCIAL LIKE ? OR f.FOR_NOME_FANTASIA LIKE ? OR cp.CPG_DOCUMENTO LIKE ? OR cp.CPG_NOTA_FISCAL LIKE ? OR cp.CPG_DESCRICAO LIKE ?)";
                $bv = '%' . $busca . '%';
                for ($i = 0; $i < 5; $i++) $paramsAgg[] = $bv;
            }

            $stAgg = $pdo->prepare($sqlAgg);
            $stAgg->execute($paramsAgg);
            $agg = $stAgg->fetch(PDO::FETCH_ASSOC) ?: [];

            out([
                'ok' => true,
                'rows' => $rows,
                'pendentes' => (int)($agg['qtd_pend'] ?? 0),
                'total_pendente' => (float)($agg['tot_pend'] ?? 0),
                'total_autorizado' => (float)($agg['tot_aut'] ?? 0),
            ]);
        }

        if ($acao === 'combo_empresas') {
            if (!tabelaExiste($pdo, 'tb_empresa')) {
                out(['ok' => true, 'rows' => []]);
            }
            $st = $pdo->query("SELECT EMP_ID, COALESCE(NULLIF(EMP_NOME_FANTASIA,''), EMP_RAZAO_SOCIAL) AS EMP_NOME
                               FROM tb_empresa
                               WHERE COALESCE(EMP_STATUS,'ATIVO') = 'ATIVO'
                               ORDER BY EMP_NOME");
            out(['ok' => true, 'rows' => $st ? $st->fetchAll(PDO::FETCH_ASSOC) : []]);
        }

        if ($acao === 'buscar_liberacao_pagamento') {
            $id = (int)($_GET['id'] ?? 0);

            $sql = "
                SELECT
                    cp.*,
                    f.FOR_RAZAO_SOCIAL,
                    f.FOR_NOME_FANTASIA,
                    f.FOR_CNPJ,
                    u.USU_NOME AS CPG_AUTORIZADO_POR_NOME,
                    u.USU_EMAIL AS CPG_AUTORIZADO_POR_EMAIL
                FROM tb_contas_pagar cp
                LEFT JOIN tb_fornecedor f
                    ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
                LEFT JOIN usuarios u
                    ON u.USU_ID = cp.CPG_AUTORIZADO_POR
                WHERE cp.CPG_CODIGO_PK = :id
                LIMIT 1
            ";
            $st = $pdo->prepare($sql);
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                out([
                    'ok' => false,
                    'msg' => 'Conta não encontrada.'
                ]);
            }

            out([
                'ok' => true,
                'row' => $row
            ]);
        }

        out([
            'ok' => false,
            'msg' => 'Ação GET inválida.'
        ]);
    }

    if ($method === 'POST') {
        $data = inputJson();
        $acao = $data['acao'] ?? '';

        if ($acao === 'alterar_autorizacao') {
            $id = (int)($data['id'] ?? 0);
            $status = strtoupper(trim($data['status'] ?? 'PENDENTE'));

            if (!in_array($status, ['PENDENTE', 'AUTORIZADO'], true)) {
                out([
                    'ok' => false,
                    'msg' => 'Status de autorização inválido.'
                ]);
            }

            $sql = "
                UPDATE tb_contas_pagar
                SET
                    CPG_AUTORIZACAO_STATUS = :status,
                    CPG_AUTORIZADO_EM = :autorizado_em,
                    CPG_AUTORIZADO_POR = :autorizado_por
                WHERE CPG_CODIGO_PK = :id
            ";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':status' => $status,
                ':autorizado_em' => $status === 'AUTORIZADO' ? date('Y-m-d H:i:s') : null,
                ':autorizado_por' => $status === 'AUTORIZADO' ? usuarioIdAtual() : null,
                ':id' => $id
            ]);

            out([
                'ok' => true,
                'msg' => 'Autorização alterada com sucesso.'
            ]);
        }

        if ($acao === 'atualizar_saldo_banco' || $acao === 'atualizar_saldos_bancos') {
            out([
                'ok'  => false,
                'msg' => 'O ajuste de saldo bancário agora é feito apenas pela tela de Conciliação Bancária.',
                'redirect' => 'conciliacao_bancaria.php'
            ]);
        }

        out([
            'ok' => false,
            'msg' => 'Ação POST inválida.'
        ]);
    }

    out([
        'ok' => false,
        'msg' => 'Método não suportado.'
    ]);
} catch (Throwable $e) {
    out([
        'ok' => false,
        'msg' => 'Erro interno: ' . $e->getMessage()
    ]);
}
