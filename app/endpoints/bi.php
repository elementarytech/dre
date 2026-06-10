<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/status_dict.php';
require_once __DIR__ . '/../config/saldos.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    json_out(['ok' => false, 'msg' => 'Não autenticado.'], 401);
}

if (strtoupper((string)($_SESSION['user_perfil'] ?? '')) !== 'ADMIN') {
    json_out(['ok' => false, 'msg' => 'Acesso restrito a administradores.'], 403);
}

$acao = $_GET['acao'] ?? '';

try {
    $asStr = static fn($v) => trim((string)($v ?? ''));
    $asInt = static fn($v) => (int)($v ?? 0);

    $empresaId = $asStr($_GET['empresa_id'] ?? 'TODAS');
    $periodo = strtoupper($asStr($_GET['periodo'] ?? '30D'));
    $dataIniParam = $asStr($_GET['data_ini'] ?? '');
    $dataFimParam = $asStr($_GET['data_fim'] ?? '');

    $hoje = new DateTime('today');

    // Se datas manuais foram informadas, usar elas
    if ($dataIniParam !== '' && $dataFimParam !== '') {
        $dtIni = new DateTime($dataIniParam);
        $dtFim = new DateTime($dataFimParam);
        $periodoLabel = 'Personalizado';
    } elseif ($periodo === '7D') {
        $dtIni = (clone $hoje)->modify('-6 days');
        $dtFim = clone $hoje;
        $periodoLabel = 'Últimos 7 dias';
    } elseif ($periodo === '15D') {
        $dtIni = (clone $hoje)->modify('-14 days');
        $dtFim = clone $hoje;
        $periodoLabel = 'Últimos 15 dias';
    } elseif ($periodo === 'MES' || $periodo === '30D') {
        $dtIni = (clone $hoje)->modify('-29 days');
        $dtFim = clone $hoje;
        $periodoLabel = 'Últimos 30 dias';
    } elseif ($periodo === 'TRIM') {
        $mes = (int)$hoje->format('n');
        $inicioTrimMes = ((int)floor(($mes - 1) / 3) * 3) + 1;
        $dtIni = new DateTime($hoje->format('Y') . '-' . str_pad((string)$inicioTrimMes, 2, '0', STR_PAD_LEFT) . '-01');
        $dtFim = (clone $dtIni)->modify('+2 months')->modify('last day of this month');
        $periodoLabel = 'Trimestre atual';
    } elseif ($periodo === 'ANO') {
        $dtIni = new DateTime($hoje->format('Y') . '-01-01');
        $dtFim = new DateTime($hoje->format('Y') . '-12-31');
        $periodoLabel = 'Ano atual';
    } else {
        $dtIni = (clone $hoje)->modify('-29 days');
        $dtFim = clone $hoje;
        $periodoLabel = 'Últimos 30 dias';
    }

    $dtIniSql = $dtIni->format('Y-m-d');
    $dtFimSql = $dtFim->format('Y-m-d');
    $empresaFiltro = ($empresaId !== 'TODAS' && ctype_digit($empresaId)) ? (int)$empresaId : 0;

    $empresaNome = 'Todas as empresas';
    if ($empresaFiltro > 0) {
        $stEmp = $pdo->prepare("SELECT EMP_ID, COALESCE(NULLIF(EMP_NOME_FANTASIA,''), EMP_RAZAO_SOCIAL) AS EMP_NOME
                                FROM tb_empresa
                                WHERE EMP_ID = ?
                                LIMIT 1");
        $stEmp->execute([$empresaFiltro]);
        $rowEmp = $stEmp->fetch(PDO::FETCH_ASSOC);
        if ($rowEmp) {
            $empresaNome = $rowEmp['EMP_NOME'];
        }
    }

    if ($acao === 'empresas') {
        $st = $pdo->query("SELECT EMP_ID, COALESCE(NULLIF(EMP_NOME_FANTASIA,''), EMP_RAZAO_SOCIAL) AS EMP_NOME
                           FROM tb_empresa
                           WHERE EMP_STATUS = 'ATIVO'
                           ORDER BY EMP_NOME");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'overview') {
        $phRecPago    = sql_placeholders(CRE_STATUS_PAGO);
        $phRecAberto  = sql_placeholders(CRE_STATUS_EM_ABERTO);
        $phCpgPago    = sql_placeholders(CPG_STATUS_PAGO);
        $phCpgAberto  = sql_placeholders(CPG_STATUS_EM_ABERTO);

        // RECEITA PREVISTA / RECEBIDA / ATRASADA
        // Recebido inclui parciais: CRE_VALOR_RECEBIDO > 0 (exclui CANCELADO).
        $sqlRec = "SELECT
                        COALESCE(SUM(CASE WHEN CRE_STATUS IN ({$phRecAberto}) OR CRE_STATUS IN ({$phRecPago})
                                     THEN CRE_VALOR ELSE 0 END),0) AS total_previsto,
                        COALESCE(SUM(CASE WHEN COALESCE(CRE_VALOR_RECEBIDO,0) > 0
                                          AND CRE_RECEBIDO_EM IS NOT NULL
                                          AND UPPER(COALESCE(CRE_STATUS,'')) <> 'CANCELADO'
                                     THEN CRE_VALOR_RECEBIDO ELSE 0 END),0) AS total_recebido,
                        COALESCE(SUM(CASE WHEN CRE_STATUS IN ({$phRecAberto}) AND CRE_VENCIMENTO < CURDATE()
                                     THEN GREATEST(0, CRE_VALOR - COALESCE(CRE_VALOR_RECEBIDO,0)) ELSE 0 END),0) AS total_atrasado
                   FROM tb_contas_receber
                   WHERE CRE_VENCIMENTO BETWEEN ? AND ?";
        $paramsRec = array_merge(
            CRE_STATUS_EM_ABERTO, CRE_STATUS_PAGO,
            CRE_STATUS_EM_ABERTO,
            [$dtIniSql, $dtFimSql]
        );

        if ($empresaFiltro > 0) {
            $sqlRec .= " AND CRE_CONTRATO_FK IN (SELECT CTR_ID FROM contratos WHERE CTR_EMPRESA_ID = ?)";
            $paramsRec[] = $empresaFiltro;
        }

        $stRec = $pdo->prepare($sqlRec);
        $stRec->execute($paramsRec);
        $rec = $stRec->fetch(PDO::FETCH_ASSOC) ?: [];

        // DESPESA PREVISTA / PAGA
        // Pago inclui parciais: CPG_VALOR_PAGO > 0 (exclui CANCELADO).
        $sqlPag = "SELECT
                        COALESCE(SUM(CASE WHEN CPG_STATUS IN ({$phCpgAberto}) OR CPG_STATUS IN ({$phCpgPago})
                                     THEN CPG_VALOR_PARCELA ELSE 0 END),0) AS total_previsto,
                        COALESCE(SUM(CASE WHEN COALESCE(CPG_VALOR_PAGO,0) > 0
                                          AND CPG_DATA_PAGAMENTO IS NOT NULL
                                          AND UPPER(COALESCE(CPG_STATUS,'')) <> 'CANCELADO'
                                     THEN CPG_VALOR_PAGO ELSE 0 END),0) AS total_pago
                   FROM tb_contas_pagar
                   WHERE CPG_VENCIMENTO BETWEEN ? AND ?";
        $paramsPag = array_merge(
            CPG_STATUS_EM_ABERTO, CPG_STATUS_PAGO,
            [$dtIniSql, $dtFimSql]
        );

        if ($empresaFiltro > 0) {
            $sqlPag .= " AND CPG_EMPRESA_FK = ?";
            $paramsPag[] = $empresaFiltro;
        }

        $stPag = $pdo->prepare($sqlPag);
        $stPag->execute($paramsPag);
        $pag = $stPag->fetch(PDO::FETCH_ASSOC) ?: [];

        // MENSAL 12 MESES (2 queries agrupadas em vez de 24)
        $mesIni12 = (clone $hoje)->modify("first day of -11 month")->format('Y-m-01');
        $mesFim12 = (clone $hoje)->modify("last day of this month")->format('Y-m-t');

        $sqlRecMensal = "SELECT DATE_FORMAT(CRE_RECEBIDO_EM, '%Y-%m') AS mes,
                                COALESCE(SUM(CRE_VALOR_RECEBIDO),0) AS total
                         FROM tb_contas_receber
                         WHERE COALESCE(CRE_VALOR_RECEBIDO,0) > 0
                           AND UPPER(COALESCE(CRE_STATUS,'')) <> 'CANCELADO'
                           AND CRE_RECEBIDO_EM IS NOT NULL
                           AND CRE_RECEBIDO_EM BETWEEN ? AND ?";
        $parRecM = [$mesIni12, $mesFim12];
        if ($empresaFiltro > 0) {
            $sqlRecMensal .= " AND CRE_CONTRATO_FK IN (SELECT CTR_ID FROM contratos WHERE CTR_EMPRESA_ID = ?)";
            $parRecM[] = $empresaFiltro;
        }
        $sqlRecMensal .= " GROUP BY mes";
        $st = $pdo->prepare($sqlRecMensal);
        $st->execute($parRecM);
        $recMensalMap = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $recMensalMap[$row['mes']] = (float)$row['total']; }

        $sqlDespMensal = "SELECT DATE_FORMAT(CPG_DATA_PAGAMENTO, '%Y-%m') AS mes,
                                 COALESCE(SUM(CPG_VALOR_PAGO),0) AS total
                          FROM tb_contas_pagar
                          WHERE COALESCE(CPG_VALOR_PAGO,0) > 0
                            AND UPPER(COALESCE(CPG_STATUS,'')) <> 'CANCELADO'
                            AND CPG_DATA_PAGAMENTO IS NOT NULL
                            AND CPG_DATA_PAGAMENTO BETWEEN ? AND ?";
        $parDespM = [$mesIni12, $mesFim12];
        if ($empresaFiltro > 0) {
            $sqlDespMensal .= " AND CPG_EMPRESA_FK = ?";
            $parDespM[] = $empresaFiltro;
        }
        $sqlDespMensal .= " GROUP BY mes";
        $st = $pdo->prepare($sqlDespMensal);
        $st->execute($parDespM);
        $despMensalMap = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $despMensalMap[$row['mes']] = (float)$row['total']; }

        $labels = [];
        $receitas = [];
        $despesas = [];
        $resultados = [];
        for ($i = 11; $i >= 0; $i--) {
            $ref = (clone $hoje)->modify("first day of -{$i} month");
            $chave = $ref->format('Y-m');
            $labels[] = $ref->format('m/Y');
            $r = $recMensalMap[$chave] ?? 0.0;
            $d = $despMensalMap[$chave] ?? 0.0;
            $receitas[] = $r;
            $despesas[] = $d;
            $resultados[] = $r - $d;
        }

        // RESULTADO POR EMPRESA
        $sqlEmp = "SELECT
                        e.EMP_ID,
                        COALESCE(NULLIF(e.EMP_NOME_FANTASIA,''), e.EMP_RAZAO_SOCIAL) AS EMP_NOME,
                        COALESCE((
                            SELECT SUM(cr.CRE_VALOR_RECEBIDO)
                            FROM tb_contas_receber cr
                            INNER JOIN contratos ctr ON ctr.CTR_ID = cr.CRE_CONTRATO_FK
                            WHERE ctr.CTR_EMPRESA_ID = e.EMP_ID
                              AND COALESCE(cr.CRE_VALOR_RECEBIDO,0) > 0
                              AND UPPER(COALESCE(cr.CRE_STATUS,'')) <> 'CANCELADO'
                              AND cr.CRE_RECEBIDO_EM IS NOT NULL
                              AND cr.CRE_RECEBIDO_EM BETWEEN ? AND ?
                        ),0) AS receita,
                        COALESCE((
                            SELECT SUM(cp.CPG_VALOR_PAGO)
                            FROM tb_contas_pagar cp
                            WHERE cp.CPG_EMPRESA_FK = e.EMP_ID
                              AND COALESCE(cp.CPG_VALOR_PAGO,0) > 0
                              AND UPPER(COALESCE(cp.CPG_STATUS,'')) <> 'CANCELADO'
                              AND cp.CPG_DATA_PAGAMENTO IS NOT NULL
                              AND cp.CPG_DATA_PAGAMENTO BETWEEN ? AND ?
                        ),0) AS despesa
                    FROM tb_empresa e
                    WHERE e.EMP_STATUS = 'ATIVO'";

        $parEmp = array_merge(
            [$dtIniSql, $dtFimSql],
            [$dtIniSql, $dtFimSql]
        );
        if ($empresaFiltro > 0) {
            $sqlEmp .= " AND e.EMP_ID = ?";
            $parEmp[] = $empresaFiltro;
        }

        $sqlEmp .= " ORDER BY EMP_NOME";
        $stEmp = $pdo->prepare($sqlEmp);
        $stEmp->execute($parEmp);
        $rowsEmp = $stEmp->fetchAll(PDO::FETCH_ASSOC);

        $empLabels = [];
        $empValores = [];
        foreach ($rowsEmp as $e) {
            $empLabels[] = $e['EMP_NOME'];
            $empValores[] = (float)$e['receita'] - (float)$e['despesa'];
        }

        // BANCOS - saldo via Conciliação Bancária (fonte da verdade)
        $sqlBancos = "SELECT
                        b.BAN_ID,
                        b.BAN_APELIDO,
                        b.BAN_NOME,
                        b.BAN_AGENCIA,
                        b.BAN_CONTA,
                        COALESCE(NULLIF(emp.EMP_NOME_FANTASIA,''), emp.EMP_RAZAO_SOCIAL) AS EMPRESA_NOME,
                        emp.EMP_ID
                      FROM tb_banco b
                      LEFT JOIN tb_empresa emp ON emp.EMP_CNPJ = b.BAN_CEDENTE_DOC
                      WHERE b.BAN_STATUS = 'ATIVO'";
        $parBancos = [];

        if ($empresaFiltro > 0) {
            $sqlBancos .= " AND emp.EMP_ID = ?";
            $parBancos[] = $empresaFiltro;
        }

        $sqlBancos .= " ORDER BY EMPRESA_NOME, b.BAN_APELIDO";
        $stB = $pdo->prepare($sqlBancos);
        $stB->execute($parBancos);
        $bancos = $stB->fetchAll(PDO::FETCH_ASSOC);
        foreach ($bancos as &$b) {
            $contaRef = trim((string)$b['BAN_AGENCIA']) . '/' . trim((string)$b['BAN_CONTA']);
            $b['SALDO_ATUAL'] = saldoErpConta($pdo, (int)$b['BAN_ID'], $contaRef);
            $b['FCB_DATA']    = date('Y-m-d');
            $b['FCB_DATA_BR'] = date('d/m/Y');
        }
        unset($b);

        // RESUMO CONTRATOS
        $sqlCtr = "SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN CTR_STATUS = 'ATIVO' THEN 1 ELSE 0 END) AS ativos,
                        COALESCE(SUM(CASE WHEN CTR_STATUS = 'ATIVO' THEN CTR_VALOR_MENSAL ELSE 0 END),0) AS valor_ativo
                   FROM contratos
                   WHERE 1=1";
        $parCtr = [];
        if ($empresaFiltro > 0) {
            $sqlCtr .= " AND CTR_EMPRESA_ID = ?";
            $parCtr[] = $empresaFiltro;
        }
        $stCtr = $pdo->prepare($sqlCtr);
        $stCtr->execute($parCtr);
        $ctr = $stCtr->fetch(PDO::FETCH_ASSOC) ?: [];

        $phCpaAberto = sql_placeholders(CPA_STATUS_EM_ABERTO);
        $sqlParc = "SELECT
                        SUM(CASE WHEN CPA_STATUS IN ({$phCpaAberto}) THEN 1 ELSE 0 END) AS abertas,
                        SUM(CASE WHEN CPA_STATUS IN ({$phCpaAberto}) AND CPA_VENCIMENTO < CURDATE() THEN 1 ELSE 0 END) AS atraso
                    FROM contrato_parcelas cp
                    INNER JOIN contratos c ON c.CTR_ID = cp.CPA_CTR_ID
                    WHERE 1=1";
        $parParc = array_merge(CPA_STATUS_EM_ABERTO, CPA_STATUS_EM_ABERTO);
        if ($empresaFiltro > 0) {
            $sqlParc .= " AND c.CTR_EMPRESA_ID = ?";
            $parParc[] = $empresaFiltro;
        }
        $stParc = $pdo->prepare($sqlParc);
        $stParc->execute($parParc);
        $parc = $stParc->fetch(PDO::FETCH_ASSOC) ?: [];

        $alertas = [];
        if ((float)($rec['total_atrasado'] ?? 0) > 0) {
            $alertas[] = [
                'titulo' => 'Inadimplência em aberto',
                'descricao' => 'Existem valores em atraso no contas a receber.'
            ];
        }
        if ((int)($parc['atraso'] ?? 0) > 0) {
            $alertas[] = [
                'titulo' => 'Parcelas contratuais em atraso',
                'descricao' => 'Há parcelas vencidas sem baixa na carteira.'
            ];
        }
        if (count($bancos) === 0) {
            $alertas[] = [
                'titulo' => 'Sem saldos bancários',
                'descricao' => 'Nenhum banco ativo cadastrado para esta seleção.'
            ];
        }

        json_out([
            'ok' => true,
            'empresa_label' => $empresaNome,
            'periodo_label' => $periodoLabel . ' (' . $dtIni->format('d/m/Y') . ' a ' . $dtFim->format('d/m/Y') . ')',
            'kpis' => [
                'receita_prevista' => (float)($rec['total_previsto'] ?? 0),
                'receita_recebida' => (float)($rec['total_recebido'] ?? 0),
                'despesa_prevista' => (float)($pag['total_previsto'] ?? 0),
                'despesa_paga' => (float)($pag['total_pago'] ?? 0),
                'resultado' => (float)($rec['total_recebido'] ?? 0) - (float)($pag['total_pago'] ?? 0),
            ],
            'grafico_mensal' => [
                'labels' => $labels,
                'receita' => $receitas,
                'despesa' => $despesas,
                'resultado' => $resultados,
            ],
            'resultado_empresas' => [
                'labels' => $empLabels,
                'valores' => $empValores,
            ],
            'bancos' => $bancos,
            'contratos' => [
                'ativos' => (int)($ctr['ativos'] ?? 0),
                'valor_ativo' => (float)($ctr['valor_ativo'] ?? 0),
                'parcelas_abertas' => (int)($parc['abertas'] ?? 0),
                'parcelas_atraso' => (int)($parc['atraso'] ?? 0),
            ],
            'alertas' => $alertas,
        ]);
    }

    if ($acao === 'previsao') {
        // Janela PRA FRENTE a partir de hoje. Se datas custom foram passadas, usa-as;
        // senão projeta de hoje + largura do período selecionado.
        if ($dataIniParam !== '' && $dataFimParam !== '') {
            $prevIni = new DateTime($dataIniParam);
            $prevFim = new DateTime($dataFimParam);
        } else {
            $prevIni = clone $hoje;
            if ($periodo === '7D')        $prevFim = (clone $hoje)->modify('+7 days');
            elseif ($periodo === '15D')   $prevFim = (clone $hoje)->modify('+15 days');
            elseif ($periodo === 'TRIM')  $prevFim = (clone $hoje)->modify('+3 months');
            elseif ($periodo === 'ANO')   $prevFim = new DateTime($hoje->format('Y') . '-12-31');
            else                          $prevFim = (clone $hoje)->modify('+30 days'); // 30D/MES default
        }
        $prevIniSql = $prevIni->format('Y-m-d');
        $prevFimSql = $prevFim->format('Y-m-d');

        // SALDO ATUAL (soma dos saldos ERP de todos os bancos ativos, respeitando empresa)
        $sqlBcs = "SELECT b.BAN_ID, b.BAN_AGENCIA, b.BAN_CONTA
                   FROM tb_banco b
                   LEFT JOIN tb_empresa emp ON emp.EMP_CNPJ = b.BAN_CEDENTE_DOC
                   WHERE b.BAN_STATUS = 'ATIVO'";
        $parBcs = [];
        if ($empresaFiltro > 0) { $sqlBcs .= " AND emp.EMP_ID = ?"; $parBcs[] = $empresaFiltro; }
        $stBcs = $pdo->prepare($sqlBcs);
        $stBcs->execute($parBcs);
        $saldoAtual = 0.0;
        foreach ($stBcs->fetchAll(PDO::FETCH_ASSOC) as $bc) {
            $contaRef = trim((string)$bc['BAN_AGENCIA']) . '/' . trim((string)$bc['BAN_CONTA']);
            $saldoAtual += saldoErpConta($pdo, (int)$bc['BAN_ID'], $contaRef);
        }

        // CRÉDITOS PREVISTOS (contas a receber em aberto, saldo restante > 0)
        $sqlCre = "SELECT cr.CRE_ID AS id,
                          cr.CRE_VENCIMENTO AS vencimento,
                          COALESCE(NULLIF(cr.CRE_CLIENTE_NOME,''), cl.CLI_NOME_RAZAO, 'Cliente') AS nome,
                          COALESCE(NULLIF(cr.CRE_DOCUMENTO,''), '') AS documento,
                          pc.PLC_NOME AS plano,
                          GREATEST(0, cr.CRE_VALOR - COALESCE(cr.CRE_VALOR_RECEBIDO,0)) AS valor
                   FROM tb_contas_receber cr
                   LEFT JOIN cliente cl ON cl.CLI_ID = cr.CRE_CLIENTE_FK
                   LEFT JOIN tb_plano_contas pc ON pc.PLC_CODIGO_PK = cr.CRE_PLANO_CONTAS_FK
                   WHERE UPPER(COALESCE(cr.CRE_STATUS,'')) IN ('ABERTO','ATRASADO','PROGRAMADO','PENDENTE')
                     AND cr.CRE_VENCIMENTO BETWEEN ? AND ?
                     AND GREATEST(0, cr.CRE_VALOR - COALESCE(cr.CRE_VALOR_RECEBIDO,0)) > 0";
        $parCre = [$prevIniSql, $prevFimSql];
        if ($empresaFiltro > 0) {
            $sqlCre .= " AND cr.CRE_CONTRATO_FK IN (SELECT CTR_ID FROM contratos WHERE CTR_EMPRESA_ID = ?)";
            $parCre[] = $empresaFiltro;
        }
        $stCre = $pdo->prepare($sqlCre);
        $stCre->execute($parCre);
        $creditos = $stCre->fetchAll(PDO::FETCH_ASSOC);

        // DÉBITOS PREVISTOS (contas a pagar em aberto, saldo restante > 0)
        $sqlPag = "SELECT cp.CPG_CODIGO_PK AS id,
                          cp.CPG_VENCIMENTO AS vencimento,
                          COALESCE(NULLIF(f.FOR_NOME_FANTASIA,''), f.FOR_RAZAO_SOCIAL, NULLIF(fu.FUN_NOME,''), 'Fornecedor') AS nome,
                          COALESCE(NULLIF(cp.CPG_DESCRICAO,''), '') AS documento,
                          pc.PLC_NOME AS plano,
                          GREATEST(0, cp.CPG_VALOR_PARCELA - COALESCE(cp.CPG_VALOR_PAGO,0)) AS valor
                   FROM tb_contas_pagar cp
                   LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
                   LEFT JOIN tb_funcionarios fu ON fu.FUN_CODIGO_PK = cp.CPG_FUNCIONARIO_FK
                   LEFT JOIN tb_plano_contas pc ON pc.PLC_CODIGO_PK = cp.CPG_PLANO_CONTAS_FK
                   WHERE (UPPER(COALESCE(cp.CPG_STATUS,'')) IN ('ABERTO','ATRASADO') OR cp.CPG_STATUS IS NULL OR TRIM(cp.CPG_STATUS)='')
                     AND cp.CPG_DATA_PAGAMENTO IS NULL
                     AND cp.CPG_VENCIMENTO BETWEEN ? AND ?
                     AND GREATEST(0, cp.CPG_VALOR_PARCELA - COALESCE(cp.CPG_VALOR_PAGO,0)) > 0";
        $parPag = [$prevIniSql, $prevFimSql];
        if ($empresaFiltro > 0) { $sqlPag .= " AND cp.CPG_EMPRESA_FK = ?"; $parPag[] = $empresaFiltro; }
        $stPag = $pdo->prepare($sqlPag);
        $stPag->execute($parPag);
        $debitos = $stPag->fetchAll(PDO::FETCH_ASSOC);

        // Combina num único array de itens, marca tipo, ordena por vencimento
        $itens = [];
        $totalReceber = 0.0;
        $totalPagar = 0.0;
        foreach ($creditos as $c) {
            $v = (float)$c['valor'];
            $totalReceber += $v;
            $itens[] = [
                'tipo'       => 'CREDITO',
                'vencimento' => $c['vencimento'],
                'nome'       => $c['nome'],
                'descricao'  => $c['documento'],
                'plano'      => $c['plano'],
                'valor'      => $v,
            ];
        }
        foreach ($debitos as $d) {
            $v = (float)$d['valor'];
            $totalPagar += $v;
            $itens[] = [
                'tipo'       => 'DEBITO',
                'vencimento' => $d['vencimento'],
                'nome'       => $d['nome'],
                'descricao'  => $d['documento'],
                'plano'      => $d['plano'],
                'valor'      => $v,
            ];
        }
        usort($itens, static function ($a, $b) {
            return strcmp((string)$a['vencimento'], (string)$b['vencimento']);
        });

        // Saldo acumulado por item + série diária para o gráfico
        $saldoCorrente = $saldoAtual;
        $serieDia = []; // data => saldo ao fim do dia
        foreach ($itens as &$it) {
            $delta = ($it['tipo'] === 'CREDITO') ? $it['valor'] : -$it['valor'];
            $saldoCorrente = round($saldoCorrente + $delta, 2);
            $it['saldo_acumulado'] = $saldoCorrente;
            $serieDia[$it['vencimento']] = $saldoCorrente;
        }
        unset($it);

        // Série diária contínua (preenche dias sem movimento com o último saldo)
        $gLabels = [];
        $gSaldo = [];
        $cursor = clone $prevIni;
        $ultimoSaldo = $saldoAtual;
        $limite = (clone $prevFim);
        $maxDias = 400; $count = 0;
        while ($cursor <= $limite && $count < $maxDias) {
            $key = $cursor->format('Y-m-d');
            if (isset($serieDia[$key])) $ultimoSaldo = $serieDia[$key];
            $gLabels[] = $cursor->format('d/m');
            $gSaldo[] = $ultimoSaldo;
            $cursor->modify('+1 day');
            $count++;
        }

        $resultadoPrev = round($totalReceber - $totalPagar, 2);
        $saldoProjetado = round($saldoAtual + $resultadoPrev, 2);

        json_out([
            'ok' => true,
            'empresa_label' => $empresaNome,
            'periodo_label' => 'Previsão: ' . $prevIni->format('d/m/Y') . ' a ' . $prevFim->format('d/m/Y'),
            'saldo_atual' => round($saldoAtual, 2),
            'total_receber' => round($totalReceber, 2),
            'total_pagar' => round($totalPagar, 2),
            'resultado_previsto' => $resultadoPrev,
            'saldo_projetado' => $saldoProjetado,
            'itens' => $itens,
            'grafico' => ['labels' => $gLabels, 'saldo' => $gSaldo],
        ]);
    }

    if ($acao === 'contratos') {
        $sqlKpi = "SELECT
                        COUNT(*) AS total_contratos,
                        SUM(CASE WHEN CTR_STATUS = 'ATIVO' THEN 1 ELSE 0 END) AS ativos,
                        COALESCE(SUM(CASE WHEN CTR_STATUS = 'ATIVO' THEN CTR_VALOR_MENSAL ELSE 0 END),0) AS valor_ativo
                   FROM contratos
                   WHERE 1=1";
        $parKpi = [];
        if ($empresaFiltro > 0) {
            $sqlKpi .= " AND CTR_EMPRESA_ID = ?";
            $parKpi[] = $empresaFiltro;
        }
        $st = $pdo->prepare($sqlKpi);
        $st->execute($parKpi);
        $kpi = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $phCpaAberto = sql_placeholders(CPA_STATUS_EM_ABERTO);
        $sqlParc = "SELECT
                        SUM(CASE WHEN CPA_STATUS IN ({$phCpaAberto}) THEN 1 ELSE 0 END) AS abertas,
                        SUM(CASE WHEN CPA_STATUS IN ({$phCpaAberto}) AND CPA_VENCIMENTO < CURDATE() THEN 1 ELSE 0 END) AS atraso,
                        SUM(CASE WHEN CPA_STATUS = 'RECEBIDO' THEN 1 ELSE 0 END) AS recebidas,
                        SUM(CASE WHEN CPA_STATUS = 'CANCELADO' THEN 1 ELSE 0 END) AS canceladas
                    FROM contrato_parcelas cp
                    INNER JOIN contratos c ON c.CTR_ID = cp.CPA_CTR_ID
                    WHERE 1=1";
        $par1 = array_merge(CPA_STATUS_EM_ABERTO, CPA_STATUS_EM_ABERTO);
        if ($empresaFiltro > 0) {
            $sqlParc .= " AND c.CTR_EMPRESA_ID = ?";
            $par1[] = $empresaFiltro;
        }
        $st = $pdo->prepare($sqlParc);
        $st->execute($par1);
        $parc = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $sqlStatusCtr = "SELECT CTR_STATUS, COUNT(*) AS total
                         FROM contratos
                         WHERE 1=1";
        $parStatus = [];
        if ($empresaFiltro > 0) {
            $sqlStatusCtr .= " AND CTR_EMPRESA_ID = ?";
            $parStatus[] = $empresaFiltro;
        }
        $sqlStatusCtr .= " GROUP BY CTR_STATUS ORDER BY CTR_STATUS";
        $st = $pdo->prepare($sqlStatusCtr);
        $st->execute($parStatus);
        $statusCtr = $st->fetchAll(PDO::FETCH_ASSOC);

        $labelsCtr = [];
        $valoresCtr = [];
        foreach ($statusCtr as $r) {
            $labelsCtr[] = $r['CTR_STATUS'];
            $valoresCtr[] = (int)$r['total'];
        }

        $labelsParc = ['PROGRAMADO/EM_ABERTO', 'ATRASO', 'RECEBIDO', 'CANCELADO'];
        $valoresParc = [
            (int)($parc['abertas'] ?? 0),
            (int)($parc['atraso'] ?? 0),
            (int)($parc['recebidas'] ?? 0),
            (int)($parc['canceladas'] ?? 0),
        ];

        $sqlLista = "SELECT
                        c.CTR_ID,
                        c.CTR_NUMERO,
                        c.CTR_VALOR_MENSAL,
                        c.CTR_STATUS,
                        cli.CLI_NOME_RAZAO AS CLIENTE_NOME,
                        COALESCE(NULLIF(emp.EMP_NOME_FANTASIA,''), emp.EMP_RAZAO_SOCIAL) AS EMPRESA_NOME,
                        (
                            SELECT MIN(cp.CPA_VENCIMENTO)
                            FROM contrato_parcelas cp
                            WHERE cp.CPA_CTR_ID = c.CTR_ID
                              AND cp.CPA_STATUS NOT IN ('RECEBIDO','CANCELADO')
                        ) AS PROXIMA_COBRANCA
                     FROM contratos c
                     INNER JOIN cliente cli ON cli.CLI_ID = c.CTR_CLIENTE_ID
                     INNER JOIN tb_empresa emp ON emp.EMP_ID = c.CTR_EMPRESA_ID
                     WHERE 1=1";
        $parLista = [];
        if ($empresaFiltro > 0) {
            $sqlLista .= " AND c.CTR_EMPRESA_ID = ?";
            $parLista[] = $empresaFiltro;
        }
        $sqlLista .= " ORDER BY c.CTR_ID DESC LIMIT 100";
        $st = $pdo->prepare($sqlLista);
        $st->execute($parLista);
        $lista = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lista as &$r) {
            $r['PROXIMA_COBRANCA_BR'] = !empty($r['PROXIMA_COBRANCA']) ? date('d/m/Y', strtotime($r['PROXIMA_COBRANCA'])) : '';
        }

        json_out([
            'ok' => true,
            'kpis' => [
                'total_contratos' => (int)($kpi['total_contratos'] ?? 0),
                'ativos' => (int)($kpi['ativos'] ?? 0),
                'valor_ativo' => (float)($kpi['valor_ativo'] ?? 0),
                'parcelas_abertas' => (int)($parc['abertas'] ?? 0),
                'parcelas_atraso' => (int)($parc['atraso'] ?? 0),
            ],
            'status_contratos' => [
                'labels' => $labelsCtr,
                'valores' => $valoresCtr,
            ],
            'status_parcelas' => [
                'labels' => $labelsParc,
                'valores' => $valoresParc,
            ],
            'lista' => $lista,
        ]);
    }


    if ($acao === 'bancos_detalhado') {
        // Aba BANCOS do BI — visão executiva: para cada banco ativo retorna
        // saldo atual, entradas/saídas no período, qtd movimentos, % conciliado e
        // série diária pro mini-gráfico. Plus: consolidado pro gráfico geral.
        $phCre = sql_placeholders(CRE_STATUS_PAGO);
        $phCpg = sql_placeholders(CPG_STATUS_PAGO);

        // 1) Lista bancos ativos respeitando filtro de empresa
        $sqlBcs = "SELECT b.BAN_ID, b.BAN_APELIDO, b.BAN_NOME, b.BAN_CODIGO,
                          b.BAN_AGENCIA, b.BAN_AGENCIA_DV, b.BAN_CONTA, b.BAN_CONTA_DV,
                          COALESCE(NULLIF(emp.EMP_NOME_FANTASIA,''), emp.EMP_RAZAO_SOCIAL) AS EMPRESA_NOME,
                          emp.EMP_ID
                   FROM tb_banco b
                   LEFT JOIN tb_empresa emp ON emp.EMP_CNPJ = b.BAN_CEDENTE_DOC
                   WHERE b.BAN_STATUS = 'ATIVO'";
        $parBcs = [];
        if ($empresaFiltro > 0) { $sqlBcs .= " AND emp.EMP_ID = ?"; $parBcs[] = $empresaFiltro; }
        $sqlBcs .= " ORDER BY b.BAN_APELIDO ASC";
        $stB = $pdo->prepare($sqlBcs);
        $stB->execute($parBcs);
        $bancos = $stB->fetchAll(PDO::FETCH_ASSOC);

        $consolidadoEntradas = 0.0;
        $consolidadoSaidas   = 0.0;
        $consolidadoSaldo    = 0.0;
        $totMovs = 0; $totConciliados = 0;
        $alertas = [];

        // Série consolidada (dia → entradas, saídas)
        $serieDiaEntradas = [];
        $serieDiaSaidas   = [];

        foreach ($bancos as &$b) {
            $bancoFk  = (int)$b['BAN_ID'];
            $contaRef = trim((string)$b['BAN_AGENCIA']) . '/' . trim((string)$b['BAN_CONTA']);

            $saldoAtual = (float)saldoErpConta($pdo, $bancoFk, $contaRef);
            $consolidadoSaldo += $saldoAtual;

            // Entradas no período (recebimentos com status RECEBIDO/PAGO)
            $sqlEnt = "SELECT COALESCE(SUM(COALESCE(NULLIF(CRE_VALOR_RECEBIDO,0), CRE_VALOR)),0) AS total,
                              COUNT(*) AS qtd
                       FROM tb_contas_receber
                       WHERE CRE_BANCO_FK = ?
                         AND CRE_STATUS IN ({$phCre})
                         AND CRE_RECEBIDO_EM BETWEEN ? AND ?";
            $stE = $pdo->prepare($sqlEnt);
            $stE->execute(array_merge([$bancoFk], CRE_STATUS_PAGO, [$dtIniSql, $dtFimSql]));
            $entRow = $stE->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'qtd' => 0];
            $b['ENTRADAS_VALOR'] = (float)$entRow['total'];
            $b['ENTRADAS_QTD']   = (int)$entRow['qtd'];

            // Saídas no período (pagamentos com status PAGO)
            $sqlSai = "SELECT COALESCE(SUM(COALESCE(CPG_VALOR_PAGO, CPG_VALOR_PARCELA)),0) AS total,
                              COUNT(*) AS qtd
                       FROM tb_contas_pagar
                       WHERE CPG_BANCO_PAGAMENTO_FK = ?
                         AND CPG_STATUS IN ({$phCpg})
                         AND CPG_DATA_PAGAMENTO BETWEEN ? AND ?";
            $stS = $pdo->prepare($sqlSai);
            $stS->execute(array_merge([$bancoFk], CPG_STATUS_PAGO, [$dtIniSql, $dtFimSql]));
            $saiRow = $stS->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'qtd' => 0];
            $b['SAIDAS_VALOR'] = (float)$saiRow['total'];
            $b['SAIDAS_QTD']   = (int)$saiRow['qtd'];

            // Movimentos OFX no período (qtd total e conciliados)
            // Considera apenas natureza NORMAL — TRANSFERENCIA_INTERNA/APLICACAO/RENDIMENTO/TARIFA
            // são auto-categorizados e não precisam de conciliação manual, então não
            // distorcem o KPI de "% conciliado".
            $sqlMov = "SELECT COUNT(*) AS total,
                              SUM(CASE WHEN COM_CONCILIADO = 'SIM' THEN 1 ELSE 0 END) AS conciliados
                       FROM tb_conciliacao_ofx_movimento
                       WHERE COM_BANCO_FK = ?
                         AND COM_DATA_MOVIMENTO BETWEEN ? AND ?
                         AND COM_NATUREZA = 'NORMAL'";
            $stM = $pdo->prepare($sqlMov);
            $stM->execute([$bancoFk, $dtIniSql, $dtFimSql]);
            $movRow = $stM->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'conciliados' => 0];
            $b['OFX_TOTAL']       = (int)$movRow['total'];
            $b['OFX_CONCILIADOS'] = (int)$movRow['conciliados'];
            $b['OFX_PERC']        = $b['OFX_TOTAL'] > 0
                ? round(($b['OFX_CONCILIADOS'] / $b['OFX_TOTAL']) * 100, 1)
                : 100.0;
            $totMovs += $b['OFX_TOTAL'];
            $totConciliados += $b['OFX_CONCILIADOS'];

            // Variação líquida no período
            $b['VARIACAO_PERIODO'] = round($b['ENTRADAS_VALOR'] - $b['SAIDAS_VALOR'], 2);
            $b['SALDO_ATUAL']      = round($saldoAtual, 2);
            $b['SALDO_INICIAL']    = round($saldoAtual - $b['VARIACAO_PERIODO'], 2);

            // Série diária pra mini-gráfico (saldo evolutivo no período)
            $sqlDia = "SELECT DATE(CRE_RECEBIDO_EM) AS d, SUM(COALESCE(NULLIF(CRE_VALOR_RECEBIDO,0), CRE_VALOR)) AS v
                       FROM tb_contas_receber
                       WHERE CRE_BANCO_FK = ? AND CRE_STATUS IN ({$phCre})
                         AND CRE_RECEBIDO_EM BETWEEN ? AND ?
                       GROUP BY DATE(CRE_RECEBIDO_EM)";
            $stD = $pdo->prepare($sqlDia);
            $stD->execute(array_merge([$bancoFk], CRE_STATUS_PAGO, [$dtIniSql, $dtFimSql]));
            $entradasDia = [];
            foreach ($stD->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $entradasDia[$row['d']] = (float)$row['v'];
                $serieDiaEntradas[$row['d']] = ($serieDiaEntradas[$row['d']] ?? 0) + (float)$row['v'];
            }

            $sqlDia2 = "SELECT DATE(CPG_DATA_PAGAMENTO) AS d, SUM(COALESCE(CPG_VALOR_PAGO, CPG_VALOR_PARCELA)) AS v
                        FROM tb_contas_pagar
                        WHERE CPG_BANCO_PAGAMENTO_FK = ? AND CPG_STATUS IN ({$phCpg})
                          AND CPG_DATA_PAGAMENTO BETWEEN ? AND ?
                        GROUP BY DATE(CPG_DATA_PAGAMENTO)";
            $stD2 = $pdo->prepare($sqlDia2);
            $stD2->execute(array_merge([$bancoFk], CPG_STATUS_PAGO, [$dtIniSql, $dtFimSql]));
            $saidasDia = [];
            foreach ($stD2->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $saidasDia[$row['d']] = (float)$row['v'];
                $serieDiaSaidas[$row['d']] = ($serieDiaSaidas[$row['d']] ?? 0) + (float)$row['v'];
            }

            // Cria serie diária acumulada para mini-gráfico (do saldo inicial até atual)
            // + séries diárias de entradas/saídas para uso quando o usuário filtrar por banco no front.
            $cursor = clone $dtIni;
            $fim    = clone $dtFim;
            $saldoCorrente = $b['SALDO_INICIAL'];
            $sparkSaldo = [];
            $entDiaArr  = [];
            $saiDiaArr  = [];
            $diasMax = 0;
            while ($cursor <= $fim && $diasMax < 400) {
                $key = $cursor->format('Y-m-d');
                $entHoje = round((float)($entradasDia[$key] ?? 0), 2);
                $saiHoje = round((float)($saidasDia[$key] ?? 0), 2);
                $entDiaArr[] = $entHoje;
                $saiDiaArr[] = $saiHoje;
                $saldoCorrente += $entHoje - $saiHoje;
                $sparkSaldo[] = round($saldoCorrente, 2);
                $cursor->modify('+1 day');
                $diasMax++;
            }
            $b['SPARK_SALDO']   = $sparkSaldo;
            $b['ENT_DIA']       = $entDiaArr;
            $b['SAI_DIA']       = $saiDiaArr;

            $consolidadoEntradas += $b['ENTRADAS_VALOR'];
            $consolidadoSaidas   += $b['SAIDAS_VALOR'];

            // Alertas por banco
            if ($saldoAtual < 0) {
                $alertas[] = ['nivel' => 'danger', 'banco' => $b['BAN_APELIDO'],
                              'titulo' => 'Saldo negativo', 'descricao' => sprintf('%s está com saldo de %s.', $b['BAN_APELIDO'], number_format($saldoAtual, 2, ',', '.'))];
            }
            if ($b['ENTRADAS_QTD'] === 0 && $b['SAIDAS_QTD'] === 0 && $b['OFX_TOTAL'] === 0) {
                $alertas[] = ['nivel' => 'info', 'banco' => $b['BAN_APELIDO'],
                              'titulo' => 'Sem movimento no período', 'descricao' => $b['BAN_APELIDO'] . ' não teve movimentação no período selecionado.'];
            }
            if ($b['OFX_TOTAL'] > 0 && $b['OFX_PERC'] < 80) {
                $alertas[] = ['nivel' => 'warning', 'banco' => $b['BAN_APELIDO'],
                              'titulo' => 'Conciliação baixa', 'descricao' => sprintf('%s: %d de %d movimentos OFX conciliados (%.1f%%).', $b['BAN_APELIDO'], $b['OFX_CONCILIADOS'], $b['OFX_TOTAL'], $b['OFX_PERC'])];
            }
        }
        unset($b);

        // 2) Série consolidada pro gráfico principal (entradas, saídas, saldo acumulado)
        $labels = [];
        $entSerie = [];
        $saiSerie = [];
        $cursor = clone $dtIni;
        $fim    = clone $dtFim;
        $diasMax = 0;
        while ($cursor <= $fim && $diasMax < 400) {
            $key = $cursor->format('Y-m-d');
            $labels[] = $cursor->format('d/m');
            $entSerie[] = round((float)($serieDiaEntradas[$key] ?? 0), 2);
            $saiSerie[] = round((float)($serieDiaSaidas[$key] ?? 0), 2);
            $cursor->modify('+1 day');
            $diasMax++;
        }

        // 3) Top movimentos no período (3 maiores entradas + 3 maiores saídas)
        $sqlTopE = "SELECT cr.CRE_VALOR_RECEBIDO AS valor, cr.CRE_RECEBIDO_EM AS data,
                           cr.CRE_CLIENTE_NOME AS contraparte, b.BAN_APELIDO AS banco,
                           cr.CRE_BANCO_FK AS banco_id
                    FROM tb_contas_receber cr
                    LEFT JOIN tb_banco b ON b.BAN_ID = cr.CRE_BANCO_FK
                    WHERE cr.CRE_STATUS IN ({$phCre})
                      AND cr.CRE_RECEBIDO_EM BETWEEN ? AND ?";
        $parTopE = array_merge(CRE_STATUS_PAGO, [$dtIniSql, $dtFimSql]);
        if ($empresaFiltro > 0) {
            $sqlTopE .= " AND cr.CRE_CONTRATO_FK IN (SELECT CTR_ID FROM contratos WHERE CTR_EMPRESA_ID = ?)";
            $parTopE[] = $empresaFiltro;
        }
        $sqlTopE .= " ORDER BY cr.CRE_VALOR_RECEBIDO DESC LIMIT 5";
        $stTE = $pdo->prepare($sqlTopE);
        $stTE->execute($parTopE);
        $topEntradas = $stTE->fetchAll(PDO::FETCH_ASSOC);

        $sqlTopS = "SELECT cp.CPG_VALOR_PAGO AS valor, cp.CPG_DATA_PAGAMENTO AS data,
                           COALESCE(NULLIF(f.FOR_NOME_FANTASIA,''), f.FOR_RAZAO_SOCIAL, NULLIF(fu.FUN_NOME,''), '—') AS contraparte,
                           b.BAN_APELIDO AS banco,
                           cp.CPG_BANCO_PAGAMENTO_FK AS banco_id
                    FROM tb_contas_pagar cp
                    LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
                    LEFT JOIN tb_funcionarios fu ON fu.FUN_CODIGO_PK = cp.CPG_FUNCIONARIO_FK
                    LEFT JOIN tb_banco b ON b.BAN_ID = cp.CPG_BANCO_PAGAMENTO_FK
                    WHERE cp.CPG_STATUS IN ({$phCpg})
                      AND cp.CPG_DATA_PAGAMENTO BETWEEN ? AND ?";
        $parTopS = array_merge(CPG_STATUS_PAGO, [$dtIniSql, $dtFimSql]);
        if ($empresaFiltro > 0) {
            $sqlTopS .= " AND cp.CPG_EMPRESA_FK = ?";
            $parTopS[] = $empresaFiltro;
        }
        $sqlTopS .= " ORDER BY cp.CPG_VALOR_PAGO DESC LIMIT 5";
        $stTS = $pdo->prepare($sqlTopS);
        $stTS->execute($parTopS);
        $topSaidas = $stTS->fetchAll(PDO::FETCH_ASSOC);

        $percConciliacao = $totMovs > 0 ? round(($totConciliados / $totMovs) * 100, 1) : 100.0;

        json_out([
            'ok' => true,
            'empresa_label' => $empresaNome,
            'periodo_label' => $periodoLabel . ' (' . $dtIni->format('d/m/Y') . ' a ' . $dtFim->format('d/m/Y') . ')',
            'kpis' => [
                'saldo_total'       => round($consolidadoSaldo, 2),
                'entradas_total'    => round($consolidadoEntradas, 2),
                'saidas_total'      => round($consolidadoSaidas, 2),
                'resultado_periodo' => round($consolidadoEntradas - $consolidadoSaidas, 2),
                'movs_total'        => $totMovs,
                'movs_conciliados'  => $totConciliados,
                'perc_conciliacao'  => $percConciliacao,
                'qtd_bancos'        => count($bancos),
            ],
            'bancos' => $bancos,
            'grafico' => [
                'labels'   => $labels,
                'entradas' => $entSerie,
                'saidas'   => $saiSerie,
            ],
            'top_entradas' => $topEntradas,
            'top_saidas'   => $topSaidas,
            'alertas'      => $alertas,
        ]);
    }

    if ($acao === 'movimentos_banco') {
        // Lista detalhada de movimentos do banco no período (entradas + saídas + OFX).
        $bancoFk = (int)($_GET['banco_id'] ?? 0);
        if ($bancoFk <= 0) json_out(['ok' => false, 'msg' => 'Informe banco_id.'], 422);

        $phCre = sql_placeholders(CRE_STATUS_PAGO);
        $phCpg = sql_placeholders(CPG_STATUS_PAGO);

        // Entradas (recebimentos)
        $sqlE = "SELECT 'ENTRADA' AS tipo, cr.CRE_ID AS id,
                        cr.CRE_RECEBIDO_EM AS data,
                        COALESCE(NULLIF(cr.CRE_VALOR_RECEBIDO,0), cr.CRE_VALOR) AS valor,
                        COALESCE(NULLIF(cr.CRE_CLIENTE_NOME,''), cl.CLI_NOME_RAZAO, 'Cliente') AS contraparte,
                        cr.CRE_DOCUMENTO AS documento,
                        cr.CRE_STATUS AS status,
                        cr.CRE_OFX_MOVIMENTO_FK AS ofx_fk,
                        'tb_contas_receber' AS origem
                 FROM tb_contas_receber cr
                 LEFT JOIN cliente cl ON cl.CLI_ID = cr.CRE_CLIENTE_FK
                 WHERE cr.CRE_BANCO_FK = ?
                   AND cr.CRE_STATUS IN ({$phCre})
                   AND cr.CRE_RECEBIDO_EM BETWEEN ? AND ?";
        $stE = $pdo->prepare($sqlE);
        $stE->execute(array_merge([$bancoFk], CRE_STATUS_PAGO, [$dtIniSql, $dtFimSql]));
        $entradas = $stE->fetchAll(PDO::FETCH_ASSOC);

        // Saídas (pagamentos)
        $sqlS = "SELECT 'SAIDA' AS tipo, cp.CPG_CODIGO_PK AS id,
                        cp.CPG_DATA_PAGAMENTO AS data,
                        COALESCE(cp.CPG_VALOR_PAGO, cp.CPG_VALOR_PARCELA) AS valor,
                        COALESCE(NULLIF(f.FOR_NOME_FANTASIA,''), f.FOR_RAZAO_SOCIAL, NULLIF(fu.FUN_NOME,''), '—') AS contraparte,
                        cp.CPG_DOCUMENTO AS documento,
                        cp.CPG_STATUS AS status,
                        cp.CPG_OFX_MOVIMENTO_FK AS ofx_fk,
                        'tb_contas_pagar' AS origem
                 FROM tb_contas_pagar cp
                 LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
                 LEFT JOIN tb_funcionarios fu ON fu.FUN_CODIGO_PK = cp.CPG_FUNCIONARIO_FK
                 WHERE cp.CPG_BANCO_PAGAMENTO_FK = ?
                   AND cp.CPG_STATUS IN ({$phCpg})
                   AND cp.CPG_DATA_PAGAMENTO BETWEEN ? AND ?";
        $stS = $pdo->prepare($sqlS);
        $stS->execute(array_merge([$bancoFk], CPG_STATUS_PAGO, [$dtIniSql, $dtFimSql]));
        $saidas = $stS->fetchAll(PDO::FETCH_ASSOC);

        $movs = array_merge($entradas, $saidas);
        usort($movs, static fn($a, $b) => strcmp((string)$b['data'], (string)$a['data']));

        // Resumo
        $resumoE = array_sum(array_map(static fn($r) => (float)$r['valor'], $entradas));
        $resumoS = array_sum(array_map(static fn($r) => (float)$r['valor'], $saidas));

        json_out([
            'ok' => true,
            'banco_id' => $bancoFk,
            'periodo_label' => $dtIni->format('d/m/Y') . ' a ' . $dtFim->format('d/m/Y'),
            'rows' => $movs,
            'resumo' => [
                'qtd_entradas' => count($entradas),
                'qtd_saidas'   => count($saidas),
                'total_entradas' => round($resumoE, 2),
                'total_saidas'   => round($resumoS, 2),
                'resultado'      => round($resumoE - $resumoS, 2),
            ],
        ]);
    }

    if ($acao === 'dre') {
        $phRecPago   = sql_placeholders(CRE_STATUS_PAGO);
        $phCpgPago   = sql_placeholders(CPG_STATUS_PAGO);

        // RECEITA BRUTA: contas recebidas no período (inclui parciais)
        $sqlReceita = "SELECT COALESCE(SUM(cr.CRE_VALOR_RECEBIDO),0) AS total
                   FROM tb_contas_receber cr
                   WHERE COALESCE(cr.CRE_VALOR_RECEBIDO,0) > 0
                     AND UPPER(COALESCE(cr.CRE_STATUS,'')) <> 'CANCELADO'
                     AND cr.CRE_RECEBIDO_EM IS NOT NULL
                     AND cr.CRE_RECEBIDO_EM BETWEEN ? AND ?";
        $parReceita = [$dtIniSql, $dtFimSql];

        if ($empresaFiltro > 0) {
            $sqlReceita .= " AND cr.CRE_CONTRATO_FK IN (
                            SELECT CTR_ID
                            FROM contratos
                            WHERE CTR_EMPRESA_ID = ?
                        )";
            $parReceita[] = $empresaFiltro;
        }

        $st = $pdo->prepare($sqlReceita);
        $st->execute($parReceita);
        $receitaBruta = (float)$st->fetchColumn();

        // DESPESAS PAGAS no período (inclui parciais), classificadas por plano de contas
        $sqlDesp = "SELECT COALESCE(SUM(cp.CPG_VALOR_PAGO),0) AS total
                FROM tb_contas_pagar cp
                WHERE COALESCE(cp.CPG_VALOR_PAGO,0) > 0
                  AND UPPER(COALESCE(cp.CPG_STATUS,'')) <> 'CANCELADO'
                  AND cp.CPG_DATA_PAGAMENTO IS NOT NULL
                  AND cp.CPG_DATA_PAGAMENTO BETWEEN ? AND ?";
        $parDesp = [$dtIniSql, $dtFimSql];

        if ($empresaFiltro > 0) {
            $sqlDesp .= " AND cp.CPG_EMPRESA_FK = ?";
            $parDesp[] = $empresaFiltro;
        }

        $st = $pdo->prepare($sqlDesp);
        $st->execute($parDesp);
        $despesas = (float)$st->fetchColumn();

        $resultado = $receitaBruta - $despesas;
        $margem = $receitaBruta > 0 ? (($resultado / $receitaBruta) * 100) : 0;

        // mensal 12 meses (2 queries agrupadas)
        $mesIni12 = (clone $hoje)->modify("first day of -11 month")->format('Y-m-01');
        $mesFim12 = (clone $hoje)->modify("last day of this month")->format('Y-m-t');

        $sqlRM2 = "SELECT DATE_FORMAT(CRE_RECEBIDO_EM, '%Y-%m') AS mes,
                          COALESCE(SUM(CRE_VALOR_RECEBIDO),0) AS total
                   FROM tb_contas_receber
                   WHERE COALESCE(CRE_VALOR_RECEBIDO,0) > 0
                     AND UPPER(COALESCE(CRE_STATUS,'')) <> 'CANCELADO'
                     AND CRE_RECEBIDO_EM IS NOT NULL
                     AND CRE_RECEBIDO_EM BETWEEN ? AND ?";
        $parRM2 = [$mesIni12, $mesFim12];
        if ($empresaFiltro > 0) {
            $sqlRM2 .= " AND CRE_CONTRATO_FK IN (SELECT CTR_ID FROM contratos WHERE CTR_EMPRESA_ID = ?)";
            $parRM2[] = $empresaFiltro;
        }
        $sqlRM2 .= " GROUP BY mes";
        $st = $pdo->prepare($sqlRM2);
        $st->execute($parRM2);
        $recMap2 = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $recMap2[$row['mes']] = (float)$row['total']; }

        $sqlDM2 = "SELECT DATE_FORMAT(CPG_DATA_PAGAMENTO, '%Y-%m') AS mes,
                          COALESCE(SUM(CPG_VALOR_PAGO),0) AS total
                   FROM tb_contas_pagar
                   WHERE COALESCE(CPG_VALOR_PAGO,0) > 0
                     AND UPPER(COALESCE(CPG_STATUS,'')) <> 'CANCELADO'
                     AND CPG_DATA_PAGAMENTO IS NOT NULL
                     AND CPG_DATA_PAGAMENTO BETWEEN ? AND ?";
        $parDM2 = [$mesIni12, $mesFim12];
        if ($empresaFiltro > 0) {
            $sqlDM2 .= " AND CPG_EMPRESA_FK = ?";
            $parDM2[] = $empresaFiltro;
        }
        $sqlDM2 .= " GROUP BY mes";
        $st = $pdo->prepare($sqlDM2);
        $st->execute($parDM2);
        $despMap2 = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $despMap2[$row['mes']] = (float)$row['total']; }

        $labels = [];
        $arrReceita = [];
        $arrDespesa = [];
        $arrResultado = [];
        for ($i = 11; $i >= 0; $i--) {
            $ref = (clone $hoje)->modify("first day of -{$i} month");
            $chave = $ref->format('Y-m');
            $labels[] = $ref->format('m/Y');
            $r = $recMap2[$chave] ?? 0.0;
            $d = $despMap2[$chave] ?? 0.0;
            $arrReceita[] = $r;
            $arrDespesa[] = $d;
            $arrResultado[] = $r - $d;
        }

        // categorias de despesa por plano
        $sqlCat = "SELECT
                    COALESCE(
                        NULLIF(p1.PLC_NOME,''),
                        NULLIF(p2.PLC_NOME,''),
                        'Sem classificação'
                    ) AS CATEGORIA,
                    COALESCE(SUM(cp.CPG_VALOR_PAGO),0) AS TOTAL
               FROM tb_contas_pagar cp
               LEFT JOIN tb_plano_contas p2
                    ON p2.PLC_CODIGO_PK = cp.CPG_PLANO_CONTAS_FK
               LEFT JOIN tb_plano_contas p1
                    ON p1.PLC_CODIGO_PK = p2.PLC_PARENT_ID
               WHERE COALESCE(cp.CPG_VALOR_PAGO,0) > 0
                 AND UPPER(COALESCE(cp.CPG_STATUS,'')) <> 'CANCELADO'
                 AND cp.CPG_DATA_PAGAMENTO IS NOT NULL
                 AND cp.CPG_DATA_PAGAMENTO BETWEEN ? AND ?";
        $parCat = [$dtIniSql, $dtFimSql];

        if ($empresaFiltro > 0) {
            $sqlCat .= " AND cp.CPG_EMPRESA_FK = ?";
            $parCat[] = $empresaFiltro;
        }

        $sqlCat .= " GROUP BY CATEGORIA
                 ORDER BY TOTAL DESC
                 LIMIT 8";

        $st = $pdo->prepare($sqlCat);
        $st->execute($parCat);
        $rowsCat = $st->fetchAll(PDO::FETCH_ASSOC);

        $catLabels = [];
        $catValores = [];
        foreach ($rowsCat as $c) {
            $catLabels[] = $c['CATEGORIA'];
            $catValores[] = (float)$c['TOTAL'];
        }

        // tabela DRE resumida
        $resumo = [];
        $resumo[] = [
            'grupo' => 'RECEITAS',
            'descricao' => 'Receita Bruta Recebida',
            'valor' => $receitaBruta
        ];

        foreach ($rowsCat as $c) {
            $resumo[] = [
                'grupo' => 'DESPESAS',
                'descricao' => $c['CATEGORIA'],
                'valor' => (float)$c['TOTAL']
            ];
        }

        $resumo[] = [
            'grupo' => 'RESULTADO',
            'descricao' => 'Resultado Operacional',
            'valor' => $resultado
        ];

        json_out([
            'ok' => true,
            'periodo_label' => $periodoLabel . ' (' . $dtIni->format('d/m/Y') . ' a ' . $dtFim->format('d/m/Y') . ')',
            'kpis' => [
                'receita_bruta' => $receitaBruta,
                'despesas' => $despesas,
                'resultado_operacional' => $resultado,
                'margem' => $margem,
            ],
            'mensal' => [
                'labels' => $labels,
                'receita' => $arrReceita,
                'despesa' => $arrDespesa,
                'resultado' => $arrResultado,
            ],
            'categorias' => [
                'labels' => $catLabels,
                'valores' => $catValores,
            ],
            'resumo' => $resumo,
        ]);
    }




    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'msg' => 'Erro ao carregar BI.',
        'detail' => $e->getMessage()
    ], 500);
}
