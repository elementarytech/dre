<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/conciliacao_bancaria_endpoint.log');

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/saldos.php';
require_once __DIR__ . '/../config/status_dict.php';
require_once __DIR__ . '/../config/conciliacao_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('json_out')) {
    function json_out(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (empty($_SESSION['user_id']) && empty($_SESSION['usuarioSession'])) {
    json_out(['ok' => false, 'msg' => 'Não autenticado.'], 401);
}

function periodoDatas(string $periodo): array
{
    $hoje = new DateTimeImmutable('today');

    if ($periodo === '90D') {
        $ini = $hoje->modify('-90 days');
    } elseif ($periodo === '30D') {
        $ini = $hoje->modify('-30 days');
    } else {
        $ini = $hoje->modify('first day of this month');
    }

    return [$ini->format('Y-m-d'), $hoje->format('Y-m-d')];
}

function garantirIndiceHashOfx(PDO $pdo): void
{
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tb_conciliacao_ofx_movimento'
              AND INDEX_NAME = 'idx_com_hash'
        ");
        $st->execute();
        if ((int)$st->fetchColumn() === 0) {
            $pdo->exec("CREATE INDEX idx_com_hash ON tb_conciliacao_ofx_movimento(COM_HASH)");
        }
    } catch (Throwable $e) {
        // índice opcional — segue sem bloquear a importação
    }
}

function getBanco(PDO $pdo, int $bancoFk): array|false
{
    $st = $pdo->prepare("
        SELECT
            BAN_ID,
            BAN_APELIDO,
            BAN_CODIGO,
            BAN_NOME,
            BAN_AGENCIA,
            BAN_CONTA,
            BAN_STATUS
        FROM tb_banco
        WHERE BAN_ID = :id
        LIMIT 1
    ");
    $st->execute([':id' => $bancoFk]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

$acao = (string)($_REQUEST['acao'] ?? '');

try {

    if ($acao === 'combo_bancos') {
        $st = $pdo->query("
            SELECT
                BAN_ID,
                BAN_APELIDO,
                BAN_CODIGO,
                BAN_NOME,
                BAN_AGENCIA,
                BAN_CONTA
            FROM tb_banco
            WHERE BAN_STATUS = 'ATIVO'
            ORDER BY BAN_APELIDO ASC, BAN_NOME ASC
        ");

        $bancos = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $bancos[] = [
                'id' => (int) $r['BAN_ID'],
                'texto' => trim((string)$r['BAN_CODIGO'] . ' - ' . (string)($r['BAN_APELIDO'] ?: $r['BAN_NOME'])),
            ];
        }

        json_out(['ok' => true, 'bancos' => $bancos]);
    }

    if ($acao === 'combo_contas_banco') {
        $bancoFk = (int)($_GET['banco_fk'] ?? 0);
        $banco = getBanco($pdo, $bancoFk);

        if (!$banco) {
            json_out(['ok' => true, 'contas' => []]);
        }

        $contaRef = contaRefBanco($banco);

        json_out([
            'ok' => true,
            'contas' => [[
                'conta_ref' => $contaRef,
                'texto' => (string)($banco['BAN_APELIDO'] ?: $banco['BAN_NOME']) . ' • ' . $contaRef,
            ]],
        ]);
    }

    if ($acao === 'combo_todas_contas') {
        $st = $pdo->query("
            SELECT
                BAN_ID,
                BAN_APELIDO,
                BAN_NOME,
                BAN_AGENCIA,
                BAN_CONTA
            FROM tb_banco
            WHERE BAN_STATUS = 'ATIVO'
            ORDER BY BAN_APELIDO ASC, BAN_NOME ASC
        ");

        $contas = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $contaRef = contaRefBanco($r);
            $contas[] = [
                'chave' => (int)$r['BAN_ID'] . '|' . $contaRef,
                'texto' => (string)($r['BAN_APELIDO'] ?: $r['BAN_NOME']) . ' • ' . $contaRef,
            ];
        }

        json_out(['ok' => true, 'contas' => $contas]);
    }

    if ($acao === 'preview_conta') {
        $chave = (string)($_GET['chave'] ?? '');
        if ($chave === '' || strpos($chave, '|') === false) {
            json_out(['ok' => false, 'msg' => 'Conta inválida.'], 422);
        }

        [$bancoFkStr, $contaRef] = explode('|', $chave, 2);
        $bancoFk = (int)$bancoFkStr;

        $saldoBancario = saldoBancarioOfx($pdo, $bancoFk, $contaRef);
        $saldoErp = saldoErpConta($pdo, $bancoFk, $contaRef);
        $diferenca = $saldoBancario - $saldoErp;

        $stA = $pdo->prepare("
            SELECT
                CAS_DATA,
                DATE_FORMAT(CAS_DATA, '%d/%m/%Y') AS data_br,
                CAS_MOTIVO AS motivo,
                CAS_OBSERVACAO AS observacao,
                CAS_VALOR AS valor
            FROM tb_conciliacao_ajuste_saldo
            WHERE CAS_BANCO_FK = :banco_fk
              AND CAS_CONTA_REF = :conta_ref
              AND CAS_STATUS = 'ATIVO'
            ORDER BY CAS_CODIGO_PK DESC
            LIMIT 4
        ");
        $stA->execute([
            ':banco_fk' => $bancoFk,
            ':conta_ref' => $contaRef,
        ]);

        json_out([
            'ok' => true,
            'saldo_bancario' => $saldoBancario,
            'saldo_erp' => $saldoErp,
            'diferenca' => $diferenca,
            'auditoria' => $stA->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    if ($acao === 'resumo') {
        $periodo = (string)($_GET['periodo'] ?? 'MES');
        $busca = trim((string)($_GET['busca'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $dtIniFiltro = trim((string)($_GET['dt_ini'] ?? ''));
        $dtFimFiltro = trim((string)($_GET['dt_fim'] ?? ''));
        [$dtIniPeriodo, $dtFimPeriodo] = periodoDatas($periodo);

        $st = $pdo->query("
            SELECT
                BAN_ID,
                BAN_APELIDO,
                BAN_CODIGO,
                BAN_NOME,
                BAN_AGENCIA,
                BAN_CONTA
            FROM tb_banco
            WHERE BAN_STATUS = 'ATIVO'
            ORDER BY BAN_APELIDO ASC, BAN_NOME ASC
        ");
        $bancos = $st->fetchAll(PDO::FETCH_ASSOC);

        $contas = [];
        $saldoBancTotal = 0.00;
        $saldoErpTotal = 0.00;

        foreach ($bancos as $b) {
            $bancoFk = (int)$b['BAN_ID'];
            $contaRef = contaRefBanco($b);

            // Saldo bancário canônico: SET (baseline) + Σ movimentos OFX posteriores ao SET.
            // A função saldoBancarioOfx() já trata isso internamente — não sobrescrever.
            $saldoBancario = saldoBancarioOfx($pdo, $bancoFk, $contaRef);

            // Apenas para metadados (data de atualização exibida no card): pega data do SET ativo.
            $stSet = $pdo->prepare("
                SELECT CAS_SALDO_NOVO, CAS_DATA_CADASTRO
                FROM tb_conciliacao_ajuste_saldo
                WHERE CAS_BANCO_FK = :banco_fk
                  AND CAS_CONTA_REF = :conta_ref
                  AND CAS_CAMPO_AJUSTADO = 'SALDO_BANCARIO'
                  AND CAS_OPERACAO = 'SET'
                  AND CAS_STATUS = 'ATIVO'
                ORDER BY CAS_CODIGO_PK DESC
                LIMIT 1
            ");
            $stSet->execute([
                ':banco_fk' => $bancoFk,
                ':conta_ref' => $contaRef,
            ]);
            $set = $stSet->fetch(PDO::FETCH_ASSOC);
            // NÃO sobrescrever $saldoBancario com $set['CAS_SALDO_NOVO']:
            // isso ignoraria os movimentos OFX posteriores ao SET (era bug do
            // código antigo, pré-briefing 4 / SET-como-baseline).

            $saldoErp = saldoErpConta($pdo, $bancoFk, $contaRef);
            $diferenca = $saldoBancario - $saldoErp;
            $conciliacaoStatus = abs($diferenca) < 0.01 ? 'OK' : 'DIVERGENTE';

            // Data de atualização = atividade mais recente que afeta o saldo:
            // último movimento OFX (não cancelado) OU o ajuste SET ativo — o que for
            // mais recente. Antes só usava a data do SET (ficava travada em ajustes
            // antigos) e o ramo do último movimento era código morto ($last = null).
            $stUlt = $pdo->prepare("
                SELECT MAX(COM_DATA_MOVIMENTO) AS ult
                FROM tb_conciliacao_ofx_movimento
                WHERE COM_BANCO_FK = :banco_fk
                  AND COM_CONTA_REF = :conta_ref
                  AND COALESCE(COM_STATUS, '') <> 'CANCELADO'
            ");
            $stUlt->execute([':banco_fk' => $bancoFk, ':conta_ref' => $contaRef]);
            $ultMov = (string)($stUlt->fetchColumn() ?: '');

            $datasAtualizacao = array_filter([
                $ultMov,
                $set && !empty($set['CAS_DATA_CADASTRO']) ? substr((string)$set['CAS_DATA_CADASTRO'], 0, 10) : '',
            ]);
            $atualizado = $datasAtualizacao ? max($datasAtualizacao) : date('Y-m-d');

            $textoBusca = mb_strtolower(
                (string)($b['BAN_APELIDO'] ?: $b['BAN_NOME']) . ' ' .
                    (string)$b['BAN_CODIGO'] . ' ' .
                    (string)$b['BAN_AGENCIA'] . ' ' .
                    (string)$b['BAN_CONTA']
            );

            if ($busca !== '' && strpos($textoBusca, mb_strtolower($busca)) === false) {
                continue;
            }
            if ($status !== '' && strtoupper($status) !== $conciliacaoStatus) {
                continue;
            }
            if ($dtIniFiltro !== '' && $atualizado < $dtIniFiltro) {
                continue;
            }
            if ($dtFimFiltro !== '' && $atualizado > $dtFimFiltro) {
                continue;
            }

            $contas[] = [
                'banco_fk' => $bancoFk,
                'apelido' => (string)($b['BAN_APELIDO'] ?: $b['BAN_NOME']),
                'banco_nome' => (string)$b['BAN_CODIGO'] . ' - ' . (string)$b['BAN_NOME'],
                'agencia' => (string)$b['BAN_AGENCIA'],
                'conta_ref' => $contaRef,
                'saldo_bancario' => $saldoBancario,
                'saldo_erp' => $saldoErp,
                'diferenca' => $diferenca,
                'conciliacao_status' => $conciliacaoStatus,
                'atualizado_em_br' => date('d/m/Y', strtotime($atualizado)),
            ];

            $saldoBancTotal += $saldoBancario;
            $saldoErpTotal += $saldoErp;
        }

        usort($contas, fn(array $a, array $b): int => $b['saldo_bancario'] <=> $a['saldo_bancario']);

        $stMov = $pdo->prepare("
            SELECT
                IFNULL(SUM(CASE WHEN COM_VALOR > 0 THEN COM_VALOR ELSE 0 END), 0) AS entradas,
                IFNULL(SUM(CASE WHEN COM_VALOR < 0 THEN ABS(COM_VALOR) ELSE 0 END), 0) AS saidas
            FROM tb_conciliacao_ofx_movimento
            WHERE COM_DATA_MOVIMENTO BETWEEN :ini AND :fim
        ");
        $stMov->execute([
            ':ini' => $dtIniPeriodo,
            ':fim' => $dtFimPeriodo,
        ]);
        $mov = $stMov->fetch(PDO::FETCH_ASSOC) ?: ['entradas' => 0, 'saidas' => 0];

        json_out([
            'ok' => true,
            'cards' => [
                'saldo_bancario_total' => $saldoBancTotal,
                'saldo_erp_total' => $saldoErpTotal,
                'entradas_periodo' => (float)$mov['entradas'],
                'saidas_periodo' => (float)$mov['saidas'],
            ],
            'contas' => $contas,
        ]);
    }

    if ($acao === 'listar_extrato') {
        $bancoFk = trim((string)($_GET['banco_fk'] ?? ''));
        $contaRef = trim((string)($_GET['conta_ref'] ?? ''));
        $busca = trim((string)($_GET['busca'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));

        $sql = "
            SELECT
                m.COM_CODIGO_PK,
                DATE_FORMAT(m.COM_DATA_MOVIMENTO, '%d/%m/%Y') AS data_br,
                m.COM_DESCRICAO,
                m.COM_VALOR,
                m.COM_SALDO_APOS,
                m.COM_STATUS,
                m.COM_CONCILIADO,
                m.COM_NATUREZA,
                m.COM_DOCUMENTO_CONTRAPARTE,
                b.BAN_APELIDO,
                b.BAN_NOME
            FROM tb_conciliacao_ofx_movimento m
            INNER JOIN tb_banco b ON b.BAN_ID = m.COM_BANCO_FK
            WHERE 1 = 1
        ";
        $params = [];

        if ($bancoFk !== '') {
            $sql .= " AND m.COM_BANCO_FK = :banco_fk";
            $params[':banco_fk'] = $bancoFk;
        }
        if ($contaRef !== '') {
            $sql .= " AND m.COM_CONTA_REF = :conta_ref";
            $params[':conta_ref'] = $contaRef;
        }
        if ($busca !== '') {
            $sql .= " AND (m.COM_DESCRICAO LIKE :busca OR CAST(m.COM_VALOR AS CHAR) LIKE :busca)";
            $params[':busca'] = '%' . $busca . '%';
        }
        // Filtros estendidos para o COM_CONCILIADO:
        //   PENDENTES_PARCIAIS → esconde os totalmente conciliados (default operacional)
        //   PARCIAL            → só os com conciliação parcial
        //   IMPORTADO/PENDENTE → só os não conciliados ainda
        //   CONCILIADO         → só os já fechados (SIM)
        if ($status === 'PENDENTES_PARCIAIS') {
            $sql .= " AND COALESCE(m.COM_CONCILIADO,'NAO') IN ('NAO','PARCIAL')";
        } elseif ($status === 'PARCIAL') {
            $sql .= " AND m.COM_CONCILIADO = 'PARCIAL'";
        } elseif ($status === 'CONCILIADO') {
            $sql .= " AND m.COM_CONCILIADO = 'SIM'";
        } elseif ($status !== '') {
            $sql .= " AND m.COM_STATUS = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY m.COM_DATA_MOVIMENTO DESC, m.COM_CODIGO_PK DESC";

        $st = $pdo->prepare($sql);
        $st->execute($params);

        $movimentos = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $movimentos[] = [
                'id'              => (int)$r['COM_CODIGO_PK'],
                'data_br'         => (string)$r['data_br'],
                'descricao'       => (string)$r['COM_DESCRICAO'],
                'valor'           => (float)$r['COM_VALOR'],
                'saldo_apos'      => (float)$r['COM_SALDO_APOS'],
                'status'          => (string)$r['COM_STATUS'],
                'conciliado'      => (string)$r['COM_CONCILIADO'],
                'natureza'        => (string)($r['COM_NATUREZA'] ?? 'NORMAL'),
                'doc_contraparte' => (string)($r['COM_DOCUMENTO_CONTRAPARTE'] ?? ''),
                'banco_nome'      => (string)($r['BAN_APELIDO'] ?: $r['BAN_NOME']),
            ];
        }

        json_out(['ok' => true, 'movimentos' => $movimentos]);
    }

    if ($acao === 'detalhe_extrato') {
        $id = (int)($_GET['id'] ?? 0);

        $st = $pdo->prepare("
            SELECT
                m.COM_CODIGO_PK,
                m.COM_BANCO_FK,
                m.COM_DATA_MOVIMENTO,
                m.COM_DOCUMENTO,
                DATE_FORMAT(m.COM_DATA_MOVIMENTO, '%d/%m/%Y') AS data_br,
                m.COM_DESCRICAO,
                m.COM_VALOR,
                m.COM_SALDO_APOS,
                m.COM_STATUS,
                b.BAN_APELIDO,
                b.BAN_NOME
            FROM tb_conciliacao_ofx_movimento m
            INNER JOIN tb_banco b ON b.BAN_ID = m.COM_BANCO_FK
            WHERE m.COM_CODIGO_PK = :id
            LIMIT 1
        ");
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        if (!$r) {
            json_out(['ok' => false, 'msg' => 'Movimento não encontrado.'], 404);
        }

        $match = null;
        $matchTipo = null;

        if (strtoupper((string)$r['COM_STATUS']) !== 'CONCILIADO') {
            $valorAbs = abs((float)$r['COM_VALOR']);
            $bancoFk  = (int)$r['COM_BANCO_FK'];
            $dataMov  = (string)$r['COM_DATA_MOVIMENTO'];
            $doc      = trim((string)($r['COM_DOCUMENTO'] ?? ''));
            $di = (new DateTime($dataMov))->modify('-3 days')->format('Y-m-d 00:00:00');
            $df = (new DateTime($dataMov))->modify('+3 days')->format('Y-m-d 23:59:59');

            if ((float)$r['COM_VALOR'] < 0) {
                $matchTipo = 'PAGAR';
                $sqlBase = "
                    SELECT cp.CPG_CODIGO_PK AS id, cp.CPG_DESCRICAO AS descricao,
                           cp.CPG_VENCIMENTO AS vencimento, cp.CPG_DATA_PAGAMENTO AS data_pagamento,
                           cp.CPG_VALOR_PARCELA AS valor, cp.CPG_VALOR_PAGO AS valor_pago,
                           cp.CPG_STATUS AS status, cp.CPG_DOCUMENTO AS documento,
                           cp.CPG_NUM_PARCELA AS num_parcela, cp.CPG_QTD_PARCELAS AS qtd_parcelas,
                           COALESCE(NULLIF(f.FOR_NOME_FANTASIA,''), f.FOR_RAZAO_SOCIAL) AS fornecedor
                    FROM tb_contas_pagar cp
                    LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
                    WHERE (cp.CPG_BANCO_PAGAMENTO_FK = :banco OR cp.CPG_BANCO_PAGAMENTO_FK IS NULL)
                      AND cp.CPG_OFX_MOVIMENTO_FK IS NULL
                      AND ABS(IFNULL(cp.CPG_VALOR_PAGO, cp.CPG_VALOR_PARCELA) - :valor) < 0.01
                ";
                if ($doc !== '') {
                    $st2 = $pdo->prepare($sqlBase . " AND (cp.CPG_DOCUMENTO = :doc1 OR cp.CPG_NOTA_FISCAL = :doc2) LIMIT 1");
                    $st2->execute([':banco' => $bancoFk, ':valor' => $valorAbs, ':doc1' => $doc, ':doc2' => $doc]);
                    $match = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                if (!$match) {
                    $st2 = $pdo->prepare($sqlBase . " AND cp.CPG_VENCIMENTO BETWEEN :di AND :df LIMIT 1");
                    $st2->execute([':banco' => $bancoFk, ':valor' => $valorAbs, ':di' => $di, ':df' => $df]);
                    $match = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            } else {
                $matchTipo = 'RECEBER';
                // [T-011] Match em cascata com tolerância de 5 centavos:
                //   1) valor cheio + sem OFX (caso comum)
                //   2) saldo restante (cobre fechamento de parcial — PIX após adiantamento)
                //   3) documento exato
                //   4) valor já recebido + vencimento ±3 dias (fallback histórico)
                // Cliente extraído do CNPJ/CPF da descrição do OFX prioriza parcelas do mesmo cliente.
                $cnpjCpfDescr = '';
                if (preg_match('/(\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{3}\.\d{3}\.\d{3}-\d{2})/', (string)$r['COM_DESCRICAO'], $mDoc)) {
                    $cnpjCpfDescr = preg_replace('/\D+/', '', $mDoc[1]);
                }

                $selectFields = "
                    SELECT cr.CRE_ID AS id, cr.CRE_OBSERVACAO AS descricao,
                           cr.CRE_VENCIMENTO AS vencimento, cr.CRE_RECEBIDO_EM AS data_recebimento,
                           cr.CRE_VALOR AS valor, cr.CRE_VALOR_RECEBIDO AS valor_recebido,
                           GREATEST(0, cr.CRE_VALOR - COALESCE(cr.CRE_VALOR_RECEBIDO,0)) AS saldo_restante,
                           cr.CRE_STATUS AS status, cr.CRE_DOCUMENTO AS documento,
                           cpa.CPA_NUM AS num_parcela, cpa.CPA_TOTAL AS qtd_parcelas,
                           COALESCE(cr.CRE_CLIENTE_NOME, '') AS cliente
                    FROM tb_contas_receber cr
                    LEFT JOIN contrato_parcelas cpa
                        ON cpa.CPA_CTR_ID = cr.CRE_CONTRATO_FK
                       AND cpa.CPA_VENCIMENTO = cr.CRE_VENCIMENTO
                    LEFT JOIN cliente cl ON cl.CLI_ID = cr.CRE_CLIENTE_FK
                ";

                $whereBase = " WHERE (cr.CRE_BANCO_FK = :banco OR cr.CRE_BANCO_FK IS NULL)
                                 AND UPPER(COALESCE(cr.CRE_STATUS,'')) IN ('ABERTO','ATRASADO','PROGRAMADO','PENDENTE') ";

                $orderPrior = "";
                if ($cnpjCpfDescr !== '') {
                    $orderPrior = " ORDER BY (REPLACE(REPLACE(REPLACE(IFNULL(cl.CLI_DOCUMENTO,''),'.',''),'/',''),'-','') = :cnpjDescr OR REPLACE(REPLACE(REPLACE(IFNULL(cr.CRE_CPF_CNPJ,''),'.',''),'/',''),'-','') = :cnpjDescr) DESC, ABS(DATEDIFF(cr.CRE_VENCIMENTO, :dataMov)) ASC ";
                } else {
                    $orderPrior = " ORDER BY ABS(DATEDIFF(cr.CRE_VENCIMENTO, :dataMov)) ASC ";
                }

                // Tentativa 1: valor cheio da parcela bate (parcela sem nenhum recebimento, fluxo normal)
                $sql1 = $selectFields . $whereBase . " AND cr.CRE_OFX_MOVIMENTO_FK IS NULL
                                                       AND ABS(cr.CRE_VALOR - :valor) < 0.05 "
                      . $orderPrior . " LIMIT 1";
                $st2 = $pdo->prepare($sql1);
                $params1 = [':banco' => $bancoFk, ':valor' => $valorAbs, ':dataMov' => $dataMov];
                if ($cnpjCpfDescr !== '') $params1[':cnpjDescr'] = $cnpjCpfDescr;
                $st2->execute($params1);
                $match = $st2->fetch(PDO::FETCH_ASSOC) ?: null;

                // Tentativa 2: saldo restante bate (cobre o "fechamento" de parcela com adiantamento parcial,
                // mesmo que a parcela já tenha 1 OFX vinculado anteriormente).
                if (!$match) {
                    $sql2 = $selectFields . $whereBase . " AND COALESCE(cr.CRE_VALOR_RECEBIDO,0) > 0
                                                           AND ABS(GREATEST(0, cr.CRE_VALOR - COALESCE(cr.CRE_VALOR_RECEBIDO,0)) - :valor) < 0.05 "
                          . $orderPrior . " LIMIT 1";
                    $st2 = $pdo->prepare($sql2);
                    $st2->execute($params1);
                    $match = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
                }

                // Tentativa 3: documento exato (fallback)
                if (!$match && $doc !== '') {
                    $sql3 = $selectFields . $whereBase . " AND cr.CRE_OFX_MOVIMENTO_FK IS NULL
                                                           AND cr.CRE_DOCUMENTO = :doc LIMIT 1";
                    $st2 = $pdo->prepare($sql3);
                    $st2->execute([':banco' => $bancoFk, ':doc' => $doc]);
                    $match = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
                }

                // Tentativa 4: valor já recebido + vencimento ±3 dias (compatibilidade histórica)
                if (!$match) {
                    $sql4 = $selectFields . $whereBase . " AND cr.CRE_OFX_MOVIMENTO_FK IS NULL
                                                           AND ABS(IFNULL(NULLIF(cr.CRE_VALOR_RECEBIDO,0), cr.CRE_VALOR) - :valor) < 0.05
                                                           AND cr.CRE_VENCIMENTO BETWEEN :di AND :df LIMIT 1";
                    $st2 = $pdo->prepare($sql4);
                    $st2->execute([':banco' => $bancoFk, ':valor' => $valorAbs, ':di' => $di, ':df' => $df]);
                    $match = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
        }

        json_out([
            'ok' => true,
            'movimento' => [
                'id' => (int)$r['COM_CODIGO_PK'],
                'data_br' => (string)$r['data_br'],
                'descricao' => (string)$r['COM_DESCRICAO'],
                'valor' => (float)$r['COM_VALOR'],
                'saldo_apos' => (float)$r['COM_SALDO_APOS'],
                'status' => (string)$r['COM_STATUS'],
                'banco_nome' => (string)($r['BAN_APELIDO'] ?: $r['BAN_NOME']),
            ],
            'match' => $match,
            'match_tipo' => $matchTipo,
        ]);
    }

    if ($acao === 'conciliar_movimento') {
        $id = (int)($_POST['id'] ?? 0);

        $st = $pdo->prepare("
            UPDATE tb_conciliacao_ofx_movimento
            SET COM_STATUS = 'CONCILIADO',
                COM_CONCILIADO = 'SIM'
            WHERE COM_CODIGO_PK = :id
        ");
        $st->execute([':id' => $id]);

        json_out(['ok' => true]);
    }

    if ($acao === 'conciliar_e_vincular') {
        // Atalho: efetiva o vínculo bidirecional reaproveitando vincular_lancamento_existente.
        // Recebe id (movimento_fk), tipo ('PAGAR'|'RECEBER'), lancamento_id, e cai no bloco
        // existente alterando $acao + ajustando $_POST.
        $movFk  = (int)($_POST['id'] ?? 0);
        $tipo   = strtoupper((string)($_POST['tipo'] ?? ''));
        $lancId = (int)($_POST['lancamento_id'] ?? 0);

        if ($movFk <= 0 || !in_array($tipo, ['PAGAR','RECEBER'], true) || $lancId <= 0) {
            json_out(['ok' => false, 'msg' => 'Parâmetros inválidos.'], 422);
        }

        $_POST['movimento_fk']  = $movFk;
        $_POST['tipo']          = $tipo;
        $_POST['lancamento_id'] = $lancId;
        $acao = 'vincular_lancamento_existente';
        // Não usa json_out aqui — fluxo continua e cai no bloco vincular_lancamento_existente.
    }

    if ($acao === 'confirmar_vinculos_sugeridos') {
        $itensRaw = $_POST['itens'] ?? '';
        $itens = is_string($itensRaw) ? json_decode($itensRaw, true) : $itensRaw;
        if (!is_array($itens) || !count($itens)) {
            json_out(['ok' => false, 'msg' => 'Nenhum item enviado.'], 422);
        }

        $sucessos = 0;
        $erros = [];

        foreach ($itens as $i => $it) {
            $movFk  = (int)($it['movimento_fk'] ?? 0);
            $tipo   = strtoupper((string)($it['tipo'] ?? ''));
            $lancId = (int)($it['lancamento_id'] ?? 0);
            if ($movFk <= 0 || !in_array($tipo, ['PAGAR','RECEBER'], true) || $lancId <= 0) {
                $erros[] = "item #$i: parâmetros inválidos";
                continue;
            }

            $pdo->beginTransaction();
            try {
                $stMov = $pdo->prepare("SELECT COM_BANCO_FK, COM_DATA_MOVIMENTO, COM_VALOR, COM_CONCILIADO
                                        FROM tb_conciliacao_ofx_movimento WHERE COM_CODIGO_PK = ? LIMIT 1");
                $stMov->execute([$movFk]);
                $mov = $stMov->fetch(PDO::FETCH_ASSOC);
                if (!$mov) throw new Exception('movimento não encontrado');
                if (strtoupper((string)$mov['COM_CONCILIADO']) === 'SIM') {
                    throw new Exception('movimento já conciliado');
                }
                $valorAbs = abs((float)$mov['COM_VALOR']);
                $dataMov  = (string)$mov['COM_DATA_MOVIMENTO'];
                $bancoFk  = (int)$mov['COM_BANCO_FK'];

                if ($tipo === 'PAGAR') {
                    $stL = $pdo->prepare("SELECT CPG_STATUS, CPG_OFX_MOVIMENTO_FK
                                          FROM tb_contas_pagar WHERE CPG_CODIGO_PK = ? LIMIT 1");
                    $stL->execute([$lancId]);
                    $lanc = $stL->fetch(PDO::FETCH_ASSOC);
                    if (!$lanc) throw new Exception('conta a pagar não encontrada');
                    if ($lanc['CPG_OFX_MOVIMENTO_FK']) throw new Exception('conta já vinculada a outro movimento');

                    if (strtoupper((string)$lanc['CPG_STATUS']) === 'PAGO') {
                        $pdo->prepare("UPDATE tb_contas_pagar SET CPG_OFX_MOVIMENTO_FK = ? WHERE CPG_CODIGO_PK = ?")
                            ->execute([$movFk, $lancId]);
                    } else {
                        $pdo->prepare("UPDATE tb_contas_pagar
                                       SET CPG_PAGO = 'SIM', CPG_STATUS = 'PAGO',
                                           CPG_DATA_PAGAMENTO = ?, CPG_BANCO_PAGAMENTO_FK = ?,
                                           CPG_VALOR_PAGO = ?, CPG_OFX_MOVIMENTO_FK = ?,
                                           CPG_AUTORIZACAO_STATUS = COALESCE(CPG_AUTORIZACAO_STATUS, 'AUTORIZADO')
                                       WHERE CPG_CODIGO_PK = ?")
                            ->execute([$dataMov, $bancoFk, $valorAbs, $movFk, $lancId]);
                    }
                } else {
                    $stL = $pdo->prepare("SELECT CRE_STATUS, CRE_OFX_MOVIMENTO_FK
                                          FROM tb_contas_receber WHERE CRE_ID = ? LIMIT 1");
                    $stL->execute([$lancId]);
                    $lanc = $stL->fetch(PDO::FETCH_ASSOC);
                    if (!$lanc) throw new Exception('conta a receber não encontrada');
                    if ($lanc['CRE_OFX_MOVIMENTO_FK']) throw new Exception('conta já vinculada a outro movimento');

                    if (in_array(strtoupper((string)$lanc['CRE_STATUS']), ['RECEBIDO','PAGO'], true)) {
                        $pdo->prepare("UPDATE tb_contas_receber SET CRE_OFX_MOVIMENTO_FK = ? WHERE CRE_ID = ?")
                            ->execute([$movFk, $lancId]);
                    } else {
                        $pdo->prepare("UPDATE tb_contas_receber
                                       SET CRE_STATUS = 'RECEBIDO', CRE_RECEBIDO_EM = ?,
                                           CRE_BANCO_FK = ?, CRE_VALOR_RECEBIDO = ?,
                                           CRE_OFX_MOVIMENTO_FK = ?
                                       WHERE CRE_ID = ?")
                            ->execute([$dataMov, $bancoFk, $valorAbs, $movFk, $lancId]);
                    }
                }

                $pdo->prepare("UPDATE tb_conciliacao_ofx_movimento
                               SET COM_STATUS = 'CONCILIADO', COM_CONCILIADO = 'SIM',
                                   COM_REFERENCIA_TIPO = ?, COM_REFERENCIA_FK = ?
                               WHERE COM_CODIGO_PK = ?")
                    ->execute([$tipo === 'PAGAR' ? 'CONTA_PAGAR' : 'CONTA_RECEBER', $lancId, $movFk]);

                $pdo->commit();
                $sucessos++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $erros[] = "movimento $movFk: " . $e->getMessage();
            }
        }

        json_out([
            'ok' => true,
            'sucessos' => $sucessos,
            'erros'    => $erros,
            'msg'      => "$sucessos vínculo(s) confirmado(s)" . (count($erros) ? " · " . count($erros) . " falha(s)" : ''),
        ]);
    }

    if ($acao === 'salvar_ajuste') {
        $chave = (string)($_POST['chave'] ?? '');
        $data = (string)($_POST['data'] ?? date('Y-m-d'));
        $campo = trim((string)($_POST['campo'] ?? ''));
        $operacao = trim((string)($_POST['operacao'] ?? ''));
        $valor = (float)($_POST['valor'] ?? 0);
        $motivo = trim((string)($_POST['motivo'] ?? ''));
        $observacao = trim((string)($_POST['observacao'] ?? ''));

        if ($chave === '' || strpos($chave, '|') === false) {
            json_out(['ok' => false, 'msg' => 'Conta inválida.'], 422);
        }
        if ($valor <= 0) {
            json_out(['ok' => false, 'msg' => 'Informe um valor válido.'], 422);
        }
        if ($motivo === '') {
            json_out(['ok' => false, 'msg' => 'Selecione o motivo.'], 422);
        }
        if ($observacao === '') {
            json_out(['ok' => false, 'msg' => 'A observação é obrigatória.'], 422);
        }

        [$bancoFkStr, $contaRef] = explode('|', $chave, 2);
        $bancoFk = (int)$bancoFkStr;

        $banco = getBanco($pdo, $bancoFk);
        if (!$banco) {
            json_out(['ok' => false, 'msg' => 'Banco não encontrado.'], 404);
        }

        $saldoBancario = saldoBancarioOfx($pdo, $bancoFk, $contaRef);

        $saldoErp = saldoErpConta($pdo, $bancoFk, $contaRef);
        $saldoAnterior = ($campo === 'SALDO_BANCARIO') ? $saldoBancario : $saldoErp;

        if ($operacao === 'SOMA') {
            $saldoNovo = $saldoAnterior + $valor;
        } elseif ($operacao === 'SUB') {
            $saldoNovo = $saldoAnterior - $valor;
        } else {
            $saldoNovo = $valor;
        }

        $usuario = (string)($_SESSION['usuarioSession'] ?? $_SESSION['user_nome'] ?? 'Sistema');

        $pdo->beginTransaction();

        $st = $pdo->prepare("
            INSERT INTO tb_conciliacao_ajuste_saldo (
                CAS_BANCO_FK,
                CAS_CONTA_REF,
                CAS_DATA,
                CAS_CAMPO_AJUSTADO,
                CAS_OPERACAO,
                CAS_VALOR,
                CAS_SALDO_ANTERIOR,
                CAS_SALDO_NOVO,
                CAS_MOTIVO,
                CAS_OBSERVACAO,
                CAS_STATUS,
                CAS_USUARIO
            ) VALUES (
                :banco_fk,
                :conta_ref,
                :data,
                :campo,
                :operacao,
                :valor,
                :saldo_anterior,
                :saldo_novo,
                :motivo,
                :observacao,
                'ATIVO',
                :usuario
            )
        ");
        $st->execute([
            ':banco_fk' => $bancoFk,
            ':conta_ref' => $contaRef,
            ':data' => $data,
            ':campo' => $campo,
            ':operacao' => $operacao,
            ':valor' => $valor,
            ':saldo_anterior' => $saldoAnterior,
            ':saldo_novo' => $saldoNovo,
            ':motivo' => $motivo,
            ':observacao' => $observacao,
            ':usuario' => $usuario,
        ]);

        if ($campo === 'SALDO_BANCARIO') {
            $valorMov = 0.0;
            if ($operacao === 'SOMA') {
                $valorMov = $valor;
            } elseif ($operacao === 'SUB') {
                $valorMov = -$valor;
            } elseif ($operacao === 'SET') {
                $valorMov = $saldoNovo - $saldoAnterior;
            }

            $descricao = 'AJUSTE MANUAL DE SALDO - ' . $motivo . ' - ' . $observacao;
            $hash = hash(
                'sha256',
                $bancoFk . '|' . $contaRef . '|' . $data . '|' . $valorMov . '|' . $descricao . '|MANUAL|' . microtime(true)
            );

            $stMov = $pdo->prepare("
                INSERT INTO tb_conciliacao_ofx_movimento (
                    COM_IMPORTACAO_FK,
                    COM_BANCO_FK,
                    COM_CONTA_REF,
                    COM_DATA_MOVIMENTO,
                    COM_DOCUMENTO,
                    COM_DESCRICAO,
                    COM_VALOR,
                    COM_SALDO_APOS,
                    COM_TIPO,
                    COM_HASH,
                    COM_STATUS,
                    COM_CONCILIADO,
                    COM_REFERENCIA_TIPO
                ) VALUES (
                    NULL,
                    :banco_fk,
                    :conta_ref,
                    :data_movimento,
                    'MANUAL',
                    :descricao,
                    :valor,
                    :saldo_apos,
                    :tipo,
                    :hash,
                    'IMPORTADO',
                    'NAO',
                    'AJUSTE_MANUAL'
                )
            ");
            $stMov->execute([
                ':banco_fk' => $bancoFk,
                ':conta_ref' => $contaRef,
                ':data_movimento' => $data,
                ':descricao' => mb_substr($descricao, 0, 255),
                ':valor' => $valorMov,
                ':saldo_apos' => $saldoNovo,
                ':tipo' => $valorMov >= 0 ? 'CREDITO' : 'DEBITO',
                ':hash' => $hash,
            ]);
        }

        $pdo->commit();

        json_out(['ok' => true]);
    }

    if ($acao === 'importar_ofx') {
        @set_time_limit(300);
        @ini_set('memory_limit', '256M');
        garantirIndiceHashOfx($pdo);
        $bancoFk = (int)($_POST['banco_fk'] ?? 0);
        $contaRef = trim((string)($_POST['conta_ref'] ?? ''));
        $dataIni = $_POST['data_ini'] ?? null;
        $dataFim = $_POST['data_fim'] ?? null;
        $forcarBanco = !empty($_POST['forcar_banco']); // override consciente da validação BANKID

        if ($bancoFk <= 0) {
            json_out(['ok' => false, 'msg' => 'Banco inválido.'], 422);
        }
        if ($contaRef === '') {
            json_out(['ok' => false, 'msg' => 'Conta inválida.'], 422);
        }
        if (empty($_FILES['arquivo_ofx']['tmp_name'])) {
            json_out(['ok' => false, 'msg' => 'Selecione o arquivo OFX.'], 422);
        }

        $conteudo = file_get_contents($_FILES['arquivo_ofx']['tmp_name']);
        if ($conteudo === false || $conteudo === '') {
            json_out(['ok' => false, 'msg' => 'Não foi possível ler o arquivo OFX.'], 422);
        }

        // Validação BANKID/ACCTID do OFX vs banco escolhido (Fase G)
        $ofxBankId = null;
        $ofxAcctId = null;
        if (preg_match('/<BANKID>([^\r\n<]+)/i', $conteudo, $mb)) $ofxBankId = trim((string)$mb[1]);
        if (preg_match('/<ACCTID>([^\r\n<]+)/i', $conteudo, $ma)) $ofxAcctId = trim((string)$ma[1]);

        $banco = getBanco($pdo, $bancoFk);
        if ($banco) {
            $apenasDigitos = static fn(?string $s): string => preg_replace('/\D+/', '', (string)$s) ?? '';
            $banCodigo  = $apenasDigitos($banco['BAN_CODIGO'] ?? '');
            $banConta   = $apenasDigitos($banco['BAN_CONTA'] ?? '');
            $banAgencia = $apenasDigitos($banco['BAN_AGENCIA'] ?? '');
            $ofxBank    = $apenasDigitos($ofxBankId);
            $ofxAcct    = $apenasDigitos($ofxAcctId);

            $bankIdConfere = ($ofxBank !== '' && $banCodigo !== '' && $ofxBank === $banCodigo);
            // ACCTID confere se contém a conta ou agência (formatos variam: "9861/99306", "00993060", etc)
            $acctConfere = ($ofxAcct !== '' && (
                ($banConta   !== '' && (str_contains($ofxAcct, $banConta) || str_contains($banConta, $ofxAcct)))
             || ($banAgencia !== '' && str_contains($ofxAcct, $banAgencia))
            ));

            if (($ofxBank !== '' || $ofxAcct !== '') && !$bankIdConfere && !$acctConfere && !$forcarBanco) {
                json_out([
                    'ok'   => false,
                    'msg'  => sprintf(
                        'Banco do arquivo OFX não confere com o banco selecionado. '
                      . 'Arquivo: BANKID=%s ACCTID=%s · Selecionado: %s (cód %s · ag %s · cc %s). '
                      . 'Reimporte no banco correto, ou marque "forçar" se tem certeza.',
                        $ofxBankId ?? '?', $ofxAcctId ?? '?',
                        $banco['BAN_APELIDO'] ?? '?', $banco['BAN_CODIGO'] ?? '?',
                        $banco['BAN_AGENCIA'] ?? '?', $banco['BAN_CONTA'] ?? '?'
                    ),
                    'code' => 'BANKID_MISMATCH',
                    'ofx_bank_id' => $ofxBankId,
                    'ofx_acct_id' => $ofxAcctId,
                ], 422);
            }
        }

        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/si', $conteudo, $matches);
        $movimentos = $matches[1] ?? [];

        $saldoLedgerOfx = null;
        if (preg_match('/<LEDGERBAL>(.*?)<\/LEDGERBAL>/si', $conteudo, $mLedger)
            || preg_match('/<LEDGERBAL>(.*?)(?=<AVAILBAL>|<\/STMTRS>|<\/CCSTMTRS>|$)/si', $conteudo, $mLedger)) {
            if (preg_match('/<BALAMT>([^\r\n<]+)/i', $mLedger[1], $mBal)) {
                $saldoLedgerOfx = (float)trim((string)$mBal[1]);
            }
        }

        $usuario = (string)($_SESSION['usuarioSession'] ?? $_SESSION['user_nome'] ?? 'Sistema');
        $nomeArquivo = (string)($_FILES['arquivo_ofx']['name'] ?? 'arquivo.ofx');

        $pdo->beginTransaction();

        $stImp = $pdo->prepare("
            INSERT INTO tb_conciliacao_ofx_importacao (
                COI_BANCO_FK,
                COI_CONTA_REF,
                COI_NOME_ARQUIVO,
                COI_DATA_INICIAL,
                COI_DATA_FINAL,
                COI_USUARIO
            ) VALUES (
                :banco_fk,
                :conta_ref,
                :nome_arquivo,
                :data_ini,
                :data_fim,
                :usuario
            )
        ");
        $stImp->execute([
            ':banco_fk' => $bancoFk,
            ':conta_ref' => $contaRef,
            ':nome_arquivo' => $nomeArquivo,
            ':data_ini' => $dataIni,
            ':data_fim' => $dataFim,
            ':usuario' => $usuario,
        ]);
        $importacaoFk = (int)$pdo->lastInsertId();

        $saldoAtual = 0.00;
        $entradas = 0.00;
        $saidas = 0.00;
        $incluidos = 0;

        $stUlt = $pdo->prepare("
            SELECT COM_SALDO_APOS
            FROM tb_conciliacao_ofx_movimento
            WHERE COM_BANCO_FK = :banco_fk
              AND COM_CONTA_REF = :conta_ref
              AND COALESCE(COM_STATUS, '') <> 'CANCELADO'
            ORDER BY COM_DATA_MOVIMENTO DESC, COM_CODIGO_PK DESC
            LIMIT 1
        ");
        $stUlt->execute([
            ':banco_fk' => $bancoFk,
            ':conta_ref' => $contaRef,
        ]);
        $ultSaldo = $stUlt->fetchColumn();
        if ($ultSaldo !== false) {
            $saldoAtual = (float)$ultSaldo;
        }

        foreach ($movimentos as $bloco) {
            if (preg_match('/<MEMO>([^\r\n<]+)/i', $bloco, $mm)
                && preg_match('/^SALDO\s+ANTERIOR/iu', trim((string)$mm[1]))
                && preg_match('/<TRNAMT>([^\r\n<]+)/i', $bloco, $mv)) {
                $saldoAtual = (float)trim((string)$mv[1]);
                break;
            }
        }

        // Dedup verifica AMBOS os hashes:
        //  - hash_legado (inclui banco/conta — para detectar reimport no MESMO banco já feito antes da Fase G)
        //  - hash_fingerprint (só fingerprint do movimento — detecta reimport mesmo em banco diferente)
        // Ambos os hashes incluem o FITID (documento). Optamos por NÃO usar fingerprint
        // sem FITID porque transações legítimas distintas podem ter mesmo
        // (data + valor + descrição) — ex: 2 PIX iguais pra mesma pessoa no mesmo dia.
        // O FITID é o que diferencia transações reais — confiamos no banco.
        $stDup = $pdo->prepare("
            SELECT COM_CODIGO_PK, COM_BANCO_FK
            FROM tb_conciliacao_ofx_movimento
            WHERE COM_HASH IN (:hash_new, :hash_legado)
            LIMIT 1
        ");

        $stMov = $pdo->prepare("
            INSERT INTO tb_conciliacao_ofx_movimento (
                COM_IMPORTACAO_FK,
                COM_BANCO_FK,
                COM_CONTA_REF,
                COM_DATA_MOVIMENTO,
                COM_DOCUMENTO,
                COM_DESCRICAO,
                COM_VALOR,
                COM_SALDO_APOS,
                COM_TIPO,
                COM_NATUREZA,
                COM_DOCUMENTO_CONTRAPARTE,
                COM_HASH,
                COM_STATUS,
                COM_CONCILIADO
            ) VALUES (
                :importacao_fk,
                :banco_fk,
                :conta_ref,
                :data_movimento,
                :documento,
                :descricao,
                :valor,
                :saldo_apos,
                :tipo,
                :natureza,
                :doc_contraparte,
                :hash,
                'IMPORTADO',
                'NAO'
            )
        ");

        // Pré-carrega documentos e nomes do grupo (Fase C — detecção de natureza)
        $stGrupo = $pdo->query("SELECT GDO_DOCUMENTO, GDO_NOME FROM tb_grupo_documento WHERE GDO_STATUS = 'ATIVO'");
        $docsGrupo = [];
        $nomesGrupo = [];
        foreach ($stGrupo->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $docsGrupo[(string)$g['GDO_DOCUMENTO']] = true;
            $nome = trim(mb_strtoupper((string)$g['GDO_NOME'], 'UTF-8'));
            // Só considera nomes com 5+ caracteres pra evitar falsos positivos
            if (mb_strlen($nome) >= 5) {
                $nomesGrupo[] = $nome;
            }
        }

        // Regex de CNPJ/CPF com proteção contra falso positivo em FITID do BB
        // (FITID do BB pode ter pontos, ex: 11.848.430.597.722 — lookbehind impede confusão)
        $cnpjCpfPattern = '/(?:'
            . '\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}'   // CNPJ formatado
            . '|\d{3}\.\d{3}\.\d{3}-\d{2}'         // CPF formatado
            . '|(?<![\d.])\d{14}(?![\d.])'         // CNPJ só dígitos (14)
            . '|(?<![\d.])\d{11}(?![\d.])'         // CPF só dígitos (11)
            . ')/';

        foreach ($movimentos as $bloco) {
            preg_match('/<DTPOSTED>([^\r\n<]+)/i', $bloco, $mData);
            preg_match('/<TRNAMT>([^\r\n<]+)/i', $bloco, $mValor);
            preg_match('/<MEMO>([^<]*)/i', $bloco, $mMemo);     // [^<]* aceita MEMO vazio
            preg_match('/<NAME>([^<]*)/i', $bloco, $mName);     // BB usa NAME pra categoria
            preg_match('/<FITID>([^\r\n<]+)/i', $bloco, $mFitid);
            preg_match('/<TRNTYPE>([^\r\n<]+)/i', $bloco, $mTipoOfx);

            $dataBruta = trim((string)($mData[1] ?? ''));
            $valor     = (float)trim((string)($mValor[1] ?? 0));
            $memoCru   = trim((string)($mMemo[1] ?? ''));
            $nameCru   = trim((string)($mName[1] ?? ''));
            $trnType   = strtoupper(trim((string)($mTipoOfx[1] ?? '')));
            $documento = trim((string)($mFitid[1] ?? ''));

            if (strlen($dataBruta) < 8) {
                continue;
            }

            // Descrição final combina NAME e MEMO. BB usa NAME pra categoria;
            // Itaú/BTG joga tudo no MEMO (NAME geralmente ausente).
            if ($nameCru !== '' && $memoCru !== '') {
                $descricao = $nameCru . ' · ' . $memoCru;
            } elseif ($nameCru !== '') {
                $descricao = $nameCru;
            } elseif ($memoCru !== '') {
                $descricao = $memoCru;
            } else {
                $descricao = 'Movimento OFX';
            }

            // Filtra linhas de saldo: olha NAME (BB) e MEMO (Itaú)
            $descNorm = mb_strtoupper($descricao, 'UTF-8');
            $nameNorm = mb_strtoupper($nameCru, 'UTF-8');
            $memoNorm = mb_strtoupper($memoCru, 'UTF-8');

            $padraoSaldo = '/^SALDO\s+(ANTERIOR|TOTAL|EM\s+CONTA|DISPON[IÍ]VEL|DO\s+DIA|FINAL|INICIAL)/u';
            if (preg_match($padraoSaldo, $memoNorm)
                || preg_match($padraoSaldo, $nameNorm)
                || $memoNorm === 'SALDO'
                || $nameNorm === 'SALDO ANTERIOR'
                || $nameNorm === 'SALDO DO DIA') {
                continue;
            }
            // Também ignora movimentos com valor zero E categoria de saldo
            if (abs($valor) < 0.005 && (str_contains($nameNorm, 'SALDO') || str_contains($memoNorm, 'SALDO'))) {
                continue;
            }

            $dataSql = substr($dataBruta, 0, 4) . '-' . substr($dataBruta, 4, 2) . '-' . substr($dataBruta, 6, 2);
            // Hash novo (Fase G): só fingerprint do movimento — detecta reimport em qualquer banco.
            $hashFingerprint = hash('sha256', $dataSql . '|' . $valor . '|' . $descricao . '|' . $documento);
            // Hash legado (compatibilidade): formato antigo, banco+conta+fingerprint.
            $hashLegado = hash('sha256', $bancoFk . '|' . $contaRef . '|' . $dataSql . '|' . $valor . '|' . $descricao . '|' . $documento);

            $stDup->execute([':hash_new' => $hashFingerprint, ':hash_legado' => $hashLegado]);
            if ($stDup->fetch()) {
                $stDup->closeCursor();
                continue;
            }
            $stDup->closeCursor();

            $saldoAtual += $valor;

            if ($valor >= 0) {
                $entradas += $valor;
                $tipo = 'CREDITO';
            } else {
                $saidas += abs($valor);
                $tipo = 'DEBITO';
            }

            // ========== Detecção de natureza ==========
            $natureza = 'NORMAL';
            $docContraparte = null;

            // 1) Por documento (CNPJ/CPF formatado OU sem formatação)
            if (preg_match($cnpjCpfPattern, $descricao, $mDoc)) {
                $docLimpo = preg_replace('/\D+/', '', $mDoc[0]);
                if (in_array(strlen($docLimpo), [11, 14], true)) {
                    $docContraparte = $docLimpo;
                    if (isset($docsGrupo[$docLimpo])) {
                        $natureza = 'TRANSFERENCIA_INTERNA';
                    }
                }
            }

            // 2) Por nome (só pra PIX/TED/DOC, quando documento não veio)
            if ($natureza === 'NORMAL' && $nameCru !== ''
                && preg_match('/PIX|TED|DOC|TRANSFER/iu', $nameCru)) {
                foreach ($nomesGrupo as $nomeGrupo) {
                    if (mb_stripos($descricao, $nomeGrupo) !== false) {
                        $natureza = 'TRANSFERENCIA_INTERNA';
                        break;
                    }
                }
            }

            // 3) Aplicação/resgate automático do mesmo banco (Rende Fácil, MaxiInvest,
            //    e o resgate de conta remunerada do BTG: "RESGATE CONTA REMUNERADA").
            //    O crédito-contrapartida ("CRÉDITO NA CONTA CORRENTE") é tratado depois,
            //    por pareamento (ver bloco "Resgate automático" pós-loop), pois o texto
            //    sozinho é genérico demais.
            if ($natureza === 'NORMAL') {
                $padraoAplicacao = '/RENDE\s+F[AÁ]CIL|APLIC(A[CÇ][AÃ]O)?\s+AUT|RESGATE\s+AUT|RESGATE\s+CONTA\s+REMUNERADA|CONTA\s+REMUNERADA|MAXI\s*INVEST|EASY\s*INVEST|FUNDO\s+AUTOM|CDB\s+AUT/iu';
                if (preg_match($padraoAplicacao, $nameCru) || preg_match($padraoAplicacao, $descricao)) {
                    $natureza = 'APLICACAO';
                }
            }

            // 4) Rendimento de aplicação (juros pagos)
            if ($natureza === 'NORMAL') {
                if (preg_match('/REND(IMENTO)?\s+PAGO\s+APLIC|REND(IMENTOS)?\s+POUPAN/iu', $descricao . ' ' . $nameCru)) {
                    $natureza = 'RENDIMENTO';
                }
            }

            // 5) Tarifa bancária
            if ($natureza === 'NORMAL') {
                if (preg_match('/TARIFA|D[EÉ]BITO\s+SERVI[CÇ]O|IOF|TX\s+ANUIDADE|TAR\.?\s+AGRUPADAS/iu', $descricao . ' ' . $nameCru)) {
                    $natureza = 'TARIFA';
                }
            }
            // ========== Fim detecção ==========

            $stMov->execute([
                ':importacao_fk'   => $importacaoFk,
                ':banco_fk'        => $bancoFk,
                ':conta_ref'       => $contaRef,
                ':data_movimento'  => $dataSql,
                ':documento'       => $documento,
                ':descricao'       => mb_substr($descricao, 0, 255),
                ':valor'           => $valor,
                ':saldo_apos'      => $saldoAtual,
                ':tipo'            => $tipo,
                ':natureza'        => $natureza,
                ':doc_contraparte' => $docContraparte,
                ':hash'            => $hashFingerprint,
            ]);

            $incluidos++;
        }

        // ===== Resgate automático de aplicação (ex.: BTG) — pareamento (opção B) =====
        // O "CRÉDITO NA CONTA CORRENTE" (+) é a contrapartida do "RESGATE CONTA
        // REMUNERADA" (-) de mesmo valor/dia na mesma conta: é movimento interno
        // (soma zero), não é receita. Só marca como APLICACAO quando o par existe —
        // evita classificar errado um crédito legítimo. O resgate (débito) já foi
        // pego pelo regex de natureza acima.
        $pdo->prepare("
            UPDATE tb_conciliacao_ofx_movimento c
            JOIN tb_conciliacao_ofx_movimento r
              ON r.COM_BANCO_FK = c.COM_BANCO_FK
             AND r.COM_CONTA_REF = c.COM_CONTA_REF
             AND r.COM_DATA_MOVIMENTO = c.COM_DATA_MOVIMENTO
             AND r.COM_VALOR < 0
             AND ABS(ABS(r.COM_VALOR) - c.COM_VALOR) < 0.01
             AND r.COM_DESCRICAO LIKE '%RESGATE CONTA REMUNERADA%'
            SET c.COM_NATUREZA = 'APLICACAO'
            WHERE c.COM_BANCO_FK = :banco
              AND c.COM_CONTA_REF = :conta
              AND c.COM_NATUREZA = 'NORMAL'
              AND c.COM_VALOR > 0
              AND c.COM_DESCRICAO LIKE '%CR_DITO NA CONTA CORRENTE%'
        ")->execute([':banco' => $bancoFk, ':conta' => $contaRef]);

        $saldoFinalOficial = ($saldoLedgerOfx !== null) ? $saldoLedgerOfx : $saldoAtual;

        $stUp = $pdo->prepare("
            UPDATE tb_conciliacao_ofx_importacao
            SET COI_SALDO_FINAL = :saldo_final,
                COI_TOTAL_ENTRADAS = :entradas,
                COI_TOTAL_SAIDAS = :saidas
            WHERE COI_CODIGO_PK = :id
        ");
        $stUp->execute([
            ':saldo_final' => $saldoFinalOficial,
            ':entradas' => $entradas,
            ':saidas' => $saidas,
            ':id' => $importacaoFk,
        ]);

        $pdo->commit();

        json_out([
            'ok' => true,
            'msg' => 'OFX importado com sucesso. ' . $incluidos . ' movimento(s) incluído(s).',
            'importacao_fk' => $importacaoFk,
            'incluidos' => $incluidos,
        ]);
    }

    if ($acao === 'debitos_orfaos') {
        // Lista os DÉBITOS de uma importação OFX que ainda não foram conciliados
        // e que NÃO têm um lançamento pago equivalente em tb_contas_pagar
        // (mesmo banco, mesmo valor absoluto, data ±3 dias).
        $importacaoFk = (int)($_GET['importacao_fk'] ?? 0);
        if ($importacaoFk <= 0) {
            json_out(['ok' => false, 'msg' => 'Importação inválida.'], 422);
        }

        // Busca o banco/conta da importação para listar todos os débitos do mesmo
        // banco/conta ainda não conciliados (resolve reimportação + dedupe por hash).
        $stImp = $pdo->prepare("SELECT COI_BANCO_FK, COI_CONTA_REF FROM tb_conciliacao_ofx_importacao WHERE COI_CODIGO_PK = ? LIMIT 1");
        $stImp->execute([$importacaoFk]);
        $imp = $stImp->fetch(PDO::FETCH_ASSOC);
        if (!$imp) {
            json_out(['ok' => false, 'msg' => 'Importação não encontrada.'], 404);
        }
        $impBanco = (int)$imp['COI_BANCO_FK'];
        $impConta = (string)$imp['COI_CONTA_REF'];

        $stMov = $pdo->prepare("
            SELECT
                m.COM_CODIGO_PK,
                m.COM_BANCO_FK,
                m.COM_CONTA_REF,
                m.COM_DATA_MOVIMENTO,
                m.COM_DESCRICAO,
                m.COM_DOCUMENTO,
                m.COM_VALOR,
                m.COM_CONCILIADO,
                m.COM_REFERENCIA_TIPO,
                m.COM_REFERENCIA_FK,
                b.BAN_APELIDO,
                b.BAN_NOME
            FROM tb_conciliacao_ofx_movimento m
            LEFT JOIN tb_banco b ON b.BAN_ID = m.COM_BANCO_FK
            WHERE m.COM_BANCO_FK = :banco
              AND m.COM_CONTA_REF = :conta
              AND m.COM_TIPO = 'DEBITO'
              AND COALESCE(m.COM_CONCILIADO, 'NAO') <> 'SIM'
              AND m.COM_NATUREZA NOT IN ('TRANSFERENCIA_INTERNA','APLICACAO','TARIFA','RENDIMENTO')
            ORDER BY m.COM_DATA_MOVIMENTO ASC, m.COM_CODIGO_PK ASC
        ");
        $stMov->execute([':banco' => $impBanco, ':conta' => $impConta]);
        $debitos = $stMov->fetchAll(PDO::FETCH_ASSOC);

        // Carrega informações do lançamento encontrado (não só CPG_CODIGO_PK).
        // Fase B: afrouxa banco (aceita NULL), usa IFNULL(VALOR_PAGO, VALOR_PARCELA)
        // e exclui contas já vinculadas a outro movimento (evita match cruzado).
        $selectMatch = "
            SELECT cp.CPG_CODIGO_PK AS id,
                   cp.CPG_BANCO_PAGAMENTO_FK AS banco_fk,
                   cp.CPG_OFX_MOVIMENTO_FK AS ja_vinculado,
                   cp.CPG_VENCIMENTO AS vencimento,
                   cp.CPG_DATA_PAGAMENTO AS data_pagamento,
                   cp.CPG_VALOR_PAGO AS valor_pago,
                   cp.CPG_VALOR_PARCELA AS valor,
                   cp.CPG_DESCRICAO AS descricao,
                   cp.CPG_DOCUMENTO AS documento,
                   cp.CPG_STATUS AS status,
                   cp.CPG_NUM_PARCELA AS num_parcela,
                   cp.CPG_QTD_PARCELAS AS qtd_parcelas,
                   COALESCE(NULLIF(f.FOR_NOME_FANTASIA,''), f.FOR_RAZAO_SOCIAL) AS fornecedor
            FROM tb_contas_pagar cp
            LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
            WHERE (cp.CPG_BANCO_PAGAMENTO_FK = ? OR cp.CPG_BANCO_PAGAMENTO_FK IS NULL)
              AND cp.CPG_STATUS = 'PAGO'
              AND ABS(IFNULL(cp.CPG_VALOR_PAGO, cp.CPG_VALOR_PARCELA) - ?) < 0.01
              AND cp.CPG_OFX_MOVIMENTO_FK IS NULL
        ";
        // sufixos preparados na hora (porque o NOT IN cresce a cada iteração).

        // Fase A: lookup retroativo — contas_pagar que JÁ apontam para algum movimento
        // desta lista, independente de COM_CONCILIADO. Cobre vínculo unilateral.
        $movIds = array_map(fn($d) => (int)$d['COM_CODIGO_PK'], $debitos);
        $mapVinculoDireto = [];
        if (!empty($movIds)) {
            $ph = implode(',', array_fill(0, count($movIds), '?'));
            $sqlVinc = "
                SELECT cp.CPG_CODIGO_PK AS id,
                       cp.CPG_OFX_MOVIMENTO_FK AS mov_fk,
                       cp.CPG_VENCIMENTO AS vencimento,
                       cp.CPG_DATA_PAGAMENTO AS data_pagamento,
                       cp.CPG_VALOR_PAGO AS valor_pago,
                       cp.CPG_VALOR_PARCELA AS valor,
                       cp.CPG_DESCRICAO AS descricao,
                       cp.CPG_DOCUMENTO AS documento,
                       cp.CPG_STATUS AS status,
                       cp.CPG_NUM_PARCELA AS num_parcela,
                       cp.CPG_QTD_PARCELAS AS qtd_parcelas,
                       COALESCE(NULLIF(f.FOR_NOME_FANTASIA,''), f.FOR_RAZAO_SOCIAL) AS fornecedor
                FROM tb_contas_pagar cp
                LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
                WHERE cp.CPG_OFX_MOVIMENTO_FK IN ({$ph})
            ";
            $stVinc = $pdo->prepare($sqlVinc);
            $stVinc->execute($movIds);
            foreach ($stVinc->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $mapVinculoDireto[(int)$row['mov_fk']] = $row;
            }
        }

        $resultado = [];
        $totalMatch = 0;
        $totalOrfaos = 0;
        $idsJaSugeridos = []; // exclusivo: IDs de tb_contas_pagar já oferecidos neste lote

        foreach ($debitos as $d) {
            $valorAbs = abs((float)$d['COM_VALOR']);
            $data     = (string)$d['COM_DATA_MOVIMENTO'];
            $di       = (new DateTime($data))->modify('-3 days')->format('Y-m-d 00:00:00');
            $df       = (new DateTime($data))->modify('+3 days')->format('Y-m-d 23:59:59');

            $match = null;

            // Fase A: tentativa direta via CPG_OFX_MOVIMENTO_FK (auto-cura COM_CONCILIADO).
            $direto = $mapVinculoDireto[(int)$d['COM_CODIGO_PK']] ?? null;
            if ($direto) {
                $match = $direto;
                if (strtoupper((string)$d['COM_CONCILIADO']) !== 'SIM') {
                    try {
                        $pdo->prepare("
                            UPDATE tb_conciliacao_ofx_movimento
                            SET COM_STATUS = 'CONCILIADO',
                                COM_CONCILIADO = 'SIM',
                                COM_REFERENCIA_TIPO = 'CONTA_PAGAR',
                                COM_REFERENCIA_FK = ?
                            WHERE COM_CODIGO_PK = ?
                              AND COALESCE(COM_CONCILIADO,'NAO') <> 'SIM'
                        ")->execute([(int)$direto['id'], (int)$d['COM_CODIGO_PK']]);
                    } catch (Throwable $e) {
                        error_log('[debitos_orfaos auto-cura] falha update mov ' . $d['COM_CODIGO_PK'] . ': ' . $e->getMessage());
                    }
                }
            }

            // Constrói cláusula NOT IN com IDs já sugeridos neste lote.
            $exclusao = '';
            $paramsExcl = [];
            if (!empty($idsJaSugeridos)) {
                $phExcl = implode(',', array_fill(0, count($idsJaSugeridos), '?'));
                $exclusao = " AND cp.CPG_CODIGO_PK NOT IN ({$phExcl}) ";
                $paramsExcl = $idsJaSugeridos;
            }

            $doc = trim((string)$d['COM_DOCUMENTO']);
            if (!$match && $doc !== '') {
                $stMatchDoc = $pdo->prepare($selectMatch . $exclusao . " AND (cp.CPG_DOCUMENTO = ? OR cp.CPG_NOTA_FISCAL = ?) LIMIT 1");
                $stMatchDoc->execute(array_merge([(int)$d['COM_BANCO_FK'], $valorAbs], $paramsExcl, [$doc, $doc]));
                $match = $stMatchDoc->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$match) {
                $stMatchVD = $pdo->prepare($selectMatch . $exclusao . " AND cp.CPG_DATA_PAGAMENTO BETWEEN ? AND ? LIMIT 1");
                $stMatchVD->execute(array_merge([(int)$d['COM_BANCO_FK'], $valorAbs], $paramsExcl, [$di, $df]));
                $match = $stMatchVD->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($match) {
                $idsJaSugeridos[] = (int)$match['id'];
                $totalMatch++;
            } else {
                $totalOrfaos++;
            }

            $resultado[] = [
                'movimento_fk' => (int)$d['COM_CODIGO_PK'],
                'banco_fk'     => (int)$d['COM_BANCO_FK'],
                'banco_nome'   => $d['BAN_APELIDO'] ?: $d['BAN_NOME'],
                'data'         => $data,
                'descricao'    => (string)$d['COM_DESCRICAO'],
                'documento'    => (string)$d['COM_DOCUMENTO'],
                'valor'        => $valorAbs,
                'match'        => $match,
            ];
        }

        json_out([
            'ok' => true,
            'total_debitos'  => count($debitos),
            'total_match'    => $totalMatch,
            'total_orfaos'   => $totalOrfaos,
            'debitos_orfaos' => $resultado,
        ]);
    }

    if ($acao === 'combo_empresas_conc') {
        $rows = $pdo->query("SELECT EMP_ID AS id, EMP_NOME_FANTASIA AS nome FROM tb_empresa WHERE COALESCE(EMP_STATUS,'ATIVO')='ATIVO' ORDER BY EMP_NOME_FANTASIA")->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'combo_plano_contas_conc') {
        $empresaFk = (int)($_GET['empresa_fk'] ?? 0);
        $where = "WHERE COALESCE(PLC_STATUS,'ATIVO')='ATIVO'";
        $params = [];
        if ($empresaFk > 0) {
            $where .= " AND (PLC_EMPRESA_FK = :emp OR PLC_EMPRESA_FK IS NULL OR PLC_EMPRESA_FK = 0)";
            $params[':emp'] = $empresaFk;
        }
        $st = $pdo->prepare("SELECT PLC_CODIGO_PK AS id, CONCAT(IFNULL(PLC_CODIGO,''),' - ',PLC_NOME) AS nome FROM tb_plano_contas $where ORDER BY PLC_CODIGO");
        $st->execute($params);
        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'autocomplete_fornecedor_conc') {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') json_out(['ok' => true, 'rows' => []]);
        $qDigits = preg_replace('/\D+/', '', $q);
        $st = $pdo->prepare("
            SELECT FOR_CODIGO_PK AS id, FOR_RAZAO_SOCIAL AS razao, FOR_NOME_FANTASIA AS fantasia, FOR_CNPJ AS cnpj
            FROM tb_fornecedor
            WHERE FOR_RAZAO_SOCIAL LIKE :q1
               OR FOR_NOME_FANTASIA LIKE :q2
               OR REPLACE(REPLACE(REPLACE(IFNULL(FOR_CNPJ,''),'.',''),'/',''),'-','') LIKE :qd
            ORDER BY FOR_NOME_FANTASIA, FOR_RAZAO_SOCIAL
            LIMIT 15
        ");
        $st->execute([':q1' => '%'.$q.'%', ':q2' => '%'.$q.'%', ':qd' => '%'.($qDigits ?: $q).'%']);
        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'criar_pagar_em_lote') {
        // Recebe lista de itens (JSON em $_POST['itens']) para criar como contas a pagar PAGAS
        // e marcar movimentos OFX como conciliados.
        $itensRaw = $_POST['itens'] ?? '';
        $itens = is_string($itensRaw) ? json_decode($itensRaw, true) : $itensRaw;
        if (!is_array($itens) || !count($itens)) {
            json_out(['ok' => false, 'msg' => 'Nenhum item enviado.'], 422);
        }

        $usuario = (string)($_SESSION['user_nome'] ?? $_SESSION['usuarioSession'] ?? 'Sistema');
        $hoje    = date('Y-m-d H:i:s');

        $stMov = $pdo->prepare("
            SELECT COM_CODIGO_PK, COM_BANCO_FK, COM_DATA_MOVIMENTO, COM_DESCRICAO, COM_DOCUMENTO, COM_VALOR, COM_CONCILIADO
            FROM tb_conciliacao_ofx_movimento
            WHERE COM_CODIGO_PK = :id
            LIMIT 1
        ");

        $stIns = $pdo->prepare("
            INSERT INTO tb_contas_pagar (
                CPG_DATA_CRIACAO, CPG_FORNECEDOR_FK, CPG_FUNCIONARIO_FK, CPG_PLANO_CONTAS_FK, CPG_CENTRO_CUSTO_FK,
                CPG_VENCIMENTO, CPG_VALOR_PARCELA, CPG_DESCRICAO, CPG_DOCUMENTO, CPG_STATUS, CPG_MODO,
                CPG_COMPETENCIA, CPG_BANCO, CPG_COMPLEMENTO, CPG_TIPO, CPG_FORMA_PAGAMENTO, CPG_OBSERVACOES,
                CPG_ENTRADA, CPG_QTD_PARCELAS, CPG_NUM_PARCELA, CPG_PRIMEIRO_VENCIMENTO, CPG_DIA_VENCIMENTO,
                CPG_NOTA_FISCAL, CPG_EMISSAO, CPG_UNIDADE_NEGOCIO, CPG_EMPRESA_FK, CPG_PROJETO, CPG_IGNORA_FLUXO,
                CPG_RATEIO_JSON, CPG_CONTA_CONTABIL,
                CPG_PAGO, CPG_DATA_PAGAMENTO, CPG_BANCO_PAGAMENTO_FK, CPG_OFX_MOVIMENTO_FK, CPG_VALOR_PAGO, CPG_AUTORIZACAO_STATUS
            ) VALUES (
                :dc, :forn, NULL, :plano, :cc,
                :venc, :valor, :desc, :doc, 'PAGO', 'AVISTA',
                :comp, NULL, '', 'D', NULL, :obs,
                NULL, 1, 1, :venc2, NULL,
                NULL, NULL, NULL, :emp, NULL, 0,
                NULL, NULL,
                'SIM', :dtPag, :bancoPag, :ofxMov, :valorPag, 'AUTORIZADO'
            )
        ");

        $stUpdMov = $pdo->prepare("
            UPDATE tb_conciliacao_ofx_movimento
            SET COM_STATUS = 'CONCILIADO',
                COM_CONCILIADO = 'SIM',
                COM_REFERENCIA_TIPO = 'CONTA_PAGAR',
                COM_REFERENCIA_FK = :ref
            WHERE COM_CODIGO_PK = :id
        ");

        $pdo->beginTransaction();

        $criados = [];
        $erros   = [];

        foreach ($itens as $it) {
            $movFk = (int)($it['movimento_fk'] ?? 0);
            if ($movFk <= 0) { $erros[] = 'movimento_fk inválido'; continue; }

            $stMov->execute([':id' => $movFk]);
            $mov = $stMov->fetch(PDO::FETCH_ASSOC);
            if (!$mov) { $erros[] = "movimento $movFk não encontrado"; continue; }
            if (strtoupper((string)$mov['COM_CONCILIADO']) === 'SIM') {
                $erros[] = "movimento $movFk já conciliado";
                continue;
            }

            $valorAbs = abs((float)$mov['COM_VALOR']);
            $dataMov  = (string)$mov['COM_DATA_MOVIMENTO'];
            $bancoFk  = (int)$mov['COM_BANCO_FK'];

            $fornFk   = (int)($it['fornecedor_fk'] ?? 0); if ($fornFk <= 0) $fornFk = null;
            $planoFk  = (int)($it['plano_contas_fk'] ?? 0); if ($planoFk <= 0) $planoFk = null;
            $ccFk     = (int)($it['centro_custo_fk'] ?? 0); if ($ccFk <= 0)   $ccFk   = null;
            $empFk    = (int)($it['empresa_fk'] ?? 0); if ($empFk <= 0)      $empFk  = null;
            $desc     = trim((string)($it['descricao'] ?? $mov['COM_DESCRICAO']));
            $doc      = trim((string)($it['documento'] ?? $mov['COM_DOCUMENTO']));
            $venc     = trim((string)($it['vencimento'] ?? $dataMov));
            $obsTexto = 'Lançamento criado via Conciliação Bancária por ' . $usuario . ' em ' . $hoje;

            $stIns->execute([
                ':dc'       => $hoje,
                ':forn'     => $fornFk,
                ':plano'    => $planoFk,
                ':cc'       => $ccFk,
                ':venc'     => $venc,
                ':valor'    => $valorAbs,
                ':desc'     => mb_substr($desc, 0, 255),
                ':doc'      => mb_substr($doc, 0, 100),
                ':comp'     => substr($dataMov, 0, 7),
                ':obs'      => $obsTexto,
                ':venc2'    => $venc,
                ':emp'      => $empFk,
                ':dtPag'    => $dataMov,
                ':bancoPag' => $bancoFk,
                ':ofxMov'   => $movFk,
                ':valorPag' => $valorAbs,
            ]);
            $newId = (int)$pdo->lastInsertId();

            $stUpdMov->execute([':ref' => $newId, ':id' => $movFk]);

            $criados[] = ['movimento_fk' => $movFk, 'cpg_id' => $newId];
        }

        $pdo->commit();

        json_out([
            'ok' => true,
            'criados' => $criados,
            'erros'   => $erros,
            'msg'     => count($criados) . ' lançamento(s) criado(s) e conciliado(s).',
        ]);
    }

    if ($acao === 'ultima_importacao') {
        $bancoFk  = (int)($_GET['banco_fk'] ?? 0);
        $contaRef = trim((string)($_GET['conta_ref'] ?? ''));
        $sql = "SELECT COI_CODIGO_PK FROM tb_conciliacao_ofx_importacao WHERE 1=1";
        $params = [];
        if ($bancoFk > 0) { $sql .= " AND COI_BANCO_FK = ?"; $params[] = $bancoFk; }
        if ($contaRef !== '') { $sql .= " AND COI_CONTA_REF = ?"; $params[] = $contaRef; }
        $sql .= " ORDER BY COI_CODIGO_PK DESC LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $id = (int)$st->fetchColumn();
        json_out(['ok' => true, 'importacao_fk' => $id]);
    }

    if ($acao === 'creditos_orfaos') {
        $importacaoFk = (int)($_GET['importacao_fk'] ?? 0);
        if ($importacaoFk <= 0) {
            json_out(['ok' => false, 'msg' => 'Importação inválida.'], 422);
        }

        $stImp = $pdo->prepare("SELECT COI_BANCO_FK, COI_CONTA_REF FROM tb_conciliacao_ofx_importacao WHERE COI_CODIGO_PK = ? LIMIT 1");
        $stImp->execute([$importacaoFk]);
        $imp = $stImp->fetch(PDO::FETCH_ASSOC);
        if (!$imp) {
            json_out(['ok' => false, 'msg' => 'Importação não encontrada.'], 404);
        }
        $impBanco = (int)$imp['COI_BANCO_FK'];
        $impConta = (string)$imp['COI_CONTA_REF'];

        $stMov = $pdo->prepare("
            SELECT
                m.COM_CODIGO_PK,
                m.COM_BANCO_FK,
                m.COM_CONTA_REF,
                m.COM_DATA_MOVIMENTO,
                m.COM_DESCRICAO,
                m.COM_DOCUMENTO,
                m.COM_VALOR,
                m.COM_CONCILIADO,
                m.COM_REFERENCIA_TIPO,
                m.COM_REFERENCIA_FK,
                b.BAN_APELIDO,
                b.BAN_NOME
            FROM tb_conciliacao_ofx_movimento m
            LEFT JOIN tb_banco b ON b.BAN_ID = m.COM_BANCO_FK
            WHERE m.COM_BANCO_FK = :banco
              AND m.COM_CONTA_REF = :conta
              AND m.COM_TIPO = 'CREDITO'
              AND COALESCE(m.COM_CONCILIADO, 'NAO') <> 'SIM'
              AND m.COM_NATUREZA NOT IN ('TRANSFERENCIA_INTERNA','APLICACAO','TARIFA','RENDIMENTO')
            ORDER BY m.COM_DATA_MOVIMENTO ASC, m.COM_CODIGO_PK ASC
        ");
        $stMov->execute([':banco' => $impBanco, ':conta' => $impConta]);
        $creditos = $stMov->fetchAll(PDO::FETCH_ASSOC);

        $phRec = sql_placeholders(CRE_STATUS_PAGO);

        $selectMatchCre = "
            SELECT cr.CRE_ID AS id,
                   cr.CRE_VENCIMENTO AS vencimento,
                   cr.CRE_RECEBIDO_EM AS data_recebimento,
                   cr.CRE_VALOR_RECEBIDO AS valor_recebido,
                   cr.CRE_VALOR AS valor,
                   cr.CRE_DOCUMENTO AS documento,
                   cr.CRE_STATUS AS status,
                   COALESCE(cr.CRE_CLIENTE_NOME, '') AS cliente,
                   cpa.CPA_NUM AS num_parcela,
                   cpa.CPA_TOTAL AS qtd_parcelas
            FROM tb_contas_receber cr
            LEFT JOIN contrato_parcelas cpa
                ON cpa.CPA_CTR_ID = cr.CRE_CONTRATO_FK
               AND cpa.CPA_VENCIMENTO = cr.CRE_VENCIMENTO
            WHERE cr.CRE_BANCO_FK = ?
              AND cr.CRE_STATUS IN ({$phRec})
              AND ABS(IFNULL(cr.CRE_VALOR_RECEBIDO, cr.CRE_VALOR) - ?) < 0.01
        ";

        $resultado = [];
        $totalMatch = 0;
        $totalOrfaos = 0;
        $idsJaSugeridos = []; // exclusivo: IDs de tb_contas_receber já oferecidos neste lote

        foreach ($creditos as $c) {
            $valorAbs = abs((float)$c['COM_VALOR']);
            $data     = (string)$c['COM_DATA_MOVIMENTO'];
            $di       = (new DateTime($data))->modify('-3 days')->format('Y-m-d 00:00:00');
            $df       = (new DateTime($data))->modify('+3 days')->format('Y-m-d 23:59:59');

            $exclusao = '';
            $paramsExcl = [];
            if (!empty($idsJaSugeridos)) {
                $phExcl = implode(',', array_fill(0, count($idsJaSugeridos), '?'));
                $exclusao = " AND cr.CRE_ID NOT IN ({$phExcl}) ";
                $paramsExcl = $idsJaSugeridos;
            }

            $match = null;
            $doc = trim((string)$c['COM_DOCUMENTO']);
            if ($doc !== '') {
                $stMatchDoc = $pdo->prepare($selectMatchCre . $exclusao . " AND cr.CRE_DOCUMENTO = ? LIMIT 1");
                $stMatchDoc->execute(array_merge(
                    [(int)$c['COM_BANCO_FK']],
                    CRE_STATUS_PAGO,
                    [$valorAbs],
                    $paramsExcl,
                    [$doc]
                ));
                $match = $stMatchDoc->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$match) {
                $stMatchVD = $pdo->prepare($selectMatchCre . $exclusao . " AND cr.CRE_RECEBIDO_EM BETWEEN ? AND ? LIMIT 1");
                $stMatchVD->execute(array_merge(
                    [(int)$c['COM_BANCO_FK']],
                    CRE_STATUS_PAGO,
                    [$valorAbs],
                    $paramsExcl,
                    [$di, $df]
                ));
                $match = $stMatchVD->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($match) {
                $idsJaSugeridos[] = (int)$match['id'];
                $totalMatch++;
            } else {
                $totalOrfaos++;
            }

            $resultado[] = [
                'movimento_fk' => (int)$c['COM_CODIGO_PK'],
                'banco_fk'     => (int)$c['COM_BANCO_FK'],
                'banco_nome'   => $c['BAN_APELIDO'] ?: $c['BAN_NOME'],
                'data'         => $data,
                'descricao'    => (string)$c['COM_DESCRICAO'],
                'documento'    => (string)$c['COM_DOCUMENTO'],
                'valor'        => $valorAbs,
                'match'        => $match,
            ];
        }

        json_out([
            'ok' => true,
            'total_creditos'  => count($creditos),
            'total_match'     => $totalMatch,
            'total_orfaos'    => $totalOrfaos,
            'creditos_orfaos' => $resultado,
        ]);
    }

    if ($acao === 'autocomplete_cliente_conc') {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') json_out(['ok' => true, 'rows' => []]);
        $qDigits = preg_replace('/\D+/', '', $q);
        $st = $pdo->prepare("
            SELECT CLI_ID AS id, CLI_NOME_RAZAO AS nome, CLI_CPF_CNPJ AS cpf_cnpj
            FROM cliente
            WHERE CLI_NOME_RAZAO LIKE :q1
               OR REPLACE(REPLACE(REPLACE(IFNULL(CLI_CPF_CNPJ,''),'.',''),'/',''),'-','') LIKE :qd
            ORDER BY CLI_NOME_RAZAO
            LIMIT 15
        ");
        $st->execute([':q1' => '%'.$q.'%', ':qd' => '%'.($qDigits ?: $q).'%']);
        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'criar_receber_em_lote') {
        $itensRaw = $_POST['itens'] ?? '';
        $itens = is_string($itensRaw) ? json_decode($itensRaw, true) : $itensRaw;
        if (!is_array($itens) || !count($itens)) {
            json_out(['ok' => false, 'msg' => 'Nenhum item enviado.'], 422);
        }

        $usuario = (string)($_SESSION['user_nome'] ?? $_SESSION['usuarioSession'] ?? 'Sistema');
        $hoje    = date('Y-m-d H:i:s');

        $stMov = $pdo->prepare("
            SELECT COM_CODIGO_PK, COM_BANCO_FK, COM_DATA_MOVIMENTO, COM_DESCRICAO, COM_DOCUMENTO, COM_VALOR, COM_CONCILIADO
            FROM tb_conciliacao_ofx_movimento
            WHERE COM_CODIGO_PK = :id
            LIMIT 1
        ");

        $stIns = $pdo->prepare("
            INSERT INTO tb_contas_receber (
                CRE_ORIGEM,
                CRE_EMPRESA_FK, CRE_PLANO_CONTAS_FK, CRE_CENTRO_CUSTO_FK,
                CRE_BANCO_FK, CRE_OFX_MOVIMENTO_FK,
                CRE_COMPETENCIA, CRE_VENCIMENTO,
                CRE_CLIENTE_FK, CRE_CLIENTE_NOME,
                CRE_VALOR, CRE_DOCUMENTO,
                CRE_RECEBIDO_EM, CRE_VALOR_RECEBIDO,
                CRE_STATUS, CRE_OBSERVACAO
            ) VALUES (
                'CONCILIACAO',
                :emp, :plano, :cc,
                :banco, :ofxMov,
                :comp, :venc,
                :cli, :cliNome,
                :valor, :doc,
                :dtRec, :valorRec,
                'RECEBIDO', :obs
            )
        ");

        $stUpdMov = $pdo->prepare("
            UPDATE tb_conciliacao_ofx_movimento
            SET COM_STATUS = 'CONCILIADO',
                COM_CONCILIADO = 'SIM',
                COM_REFERENCIA_TIPO = 'CONTA_RECEBER',
                COM_REFERENCIA_FK = :ref
            WHERE COM_CODIGO_PK = :id
        ");

        $pdo->beginTransaction();

        $criados = [];
        $erros   = [];

        foreach ($itens as $it) {
            $movFk = (int)($it['movimento_fk'] ?? 0);
            if ($movFk <= 0) { $erros[] = 'movimento_fk inválido'; continue; }

            $stMov->execute([':id' => $movFk]);
            $mov = $stMov->fetch(PDO::FETCH_ASSOC);
            if (!$mov) { $erros[] = "movimento $movFk não encontrado"; continue; }
            if (strtoupper((string)$mov['COM_CONCILIADO']) === 'SIM') {
                $erros[] = "movimento $movFk já conciliado";
                continue;
            }

            $valorAbs = abs((float)$mov['COM_VALOR']);
            $dataMov  = (string)$mov['COM_DATA_MOVIMENTO'];
            $bancoFk  = (int)$mov['COM_BANCO_FK'];

            $cliFk    = (int)($it['cliente_fk'] ?? 0); if ($cliFk <= 0) $cliFk = null;
            $cliNome  = trim((string)($it['cliente_nome'] ?? ''));
            $planoFk  = (int)($it['plano_contas_fk'] ?? 0); if ($planoFk <= 0) $planoFk = null;
            $ccFk     = (int)($it['centro_custo_fk'] ?? 0); if ($ccFk <= 0)   $ccFk   = null;
            $empFk    = (int)($it['empresa_fk'] ?? 0); if ($empFk <= 0)      $empFk  = null;
            $desc     = trim((string)($it['descricao'] ?? $mov['COM_DESCRICAO']));
            // Fallback: se o usuário não escolheu cliente, usa a descrição do extrato OFX
            // (que tipicamente contém o nome do pagador — ex: "PIX RECEBIDO DE FULANO LTDA").
            if ($cliNome === '') $cliNome = trim((string)$mov['COM_DESCRICAO']);
            $doc      = trim((string)($it['documento'] ?? $mov['COM_DOCUMENTO']));
            $venc     = trim((string)($it['vencimento'] ?? $dataMov));
            $obsTexto = 'Lançamento criado via Conciliação Bancária por ' . $usuario . ' em ' . $hoje
                . (strlen($desc) ? ' — ' . $desc : '');

            $stIns->execute([
                ':emp'      => $empFk,
                ':plano'    => $planoFk,
                ':cc'       => $ccFk,
                ':banco'    => $bancoFk,
                ':ofxMov'   => $movFk,
                ':comp'     => substr($dataMov, 0, 7) . '-01',
                ':venc'     => $venc,
                ':cli'      => $cliFk,
                ':cliNome'  => mb_substr($cliNome, 0, 255),
                ':valor'    => $valorAbs,
                ':doc'      => mb_substr($doc, 0, 100),
                ':dtRec'    => $dataMov,
                ':valorRec' => $valorAbs,
                ':obs'      => $obsTexto,
            ]);
            $newId = (int)$pdo->lastInsertId();

            $stUpdMov->execute([':ref' => $newId, ':id' => $movFk]);

            $criados[] = ['movimento_fk' => $movFk, 'cre_id' => $newId];
        }

        $pdo->commit();

        json_out([
            'ok' => true,
            'criados' => $criados,
            'erros'   => $erros,
            'msg'     => count($criados) . ' recebimento(s) criado(s) e conciliado(s).',
        ]);
    }

    if ($acao === 'lancamentos_disponiveis') {
        // Lista lançamentos sem vínculo OFX num mês (YYYY-MM) para o select da conciliação.
        $tipo    = strtoupper((string)($_GET['tipo'] ?? ''));
        $mes     = trim((string)($_GET['mes'] ?? '')); // formato YYYY-MM
        $bancoFk = (int)($_GET['banco_fk'] ?? 0);

        if (!in_array($tipo, ['PAGAR', 'RECEBER'], true) || !preg_match('/^\d{4}-\d{2}$/', $mes)) {
            json_out(['ok' => false, 'msg' => 'Parâmetros inválidos.'], 422);
        }
        // Janela ampliada para o mês de referência ± 1: pagamentos/recebimentos
        // frequentemente cruzam a virada do mês (vence 31/05, cai no extrato em
        // 01/06; ou PIX antecipado de uma parcela do mês seguinte). Sem isso, o
        // lançamento ficava invisível na conciliação do movimento.
        $baseMes = new DateTime($mes . '-01');
        $iniMes  = (clone $baseMes)->modify('first day of previous month')->format('Y-m-d');
        $fimMes  = (clone $baseMes)->modify('last day of next month')->format('Y-m-d');

        if ($tipo === 'PAGAR') {
            $sql = "
                SELECT cp.CPG_CODIGO_PK AS id,
                       cp.CPG_VENCIMENTO AS vencimento,
                       cp.CPG_VALOR_PARCELA AS valor,
                       cp.CPG_VALOR_PAGO AS valor_pago,
                       cp.CPG_DATA_PAGAMENTO AS data_pagamento,
                       cp.CPG_DESCRICAO AS descricao,
                       cp.CPG_DOCUMENTO AS documento,
                       cp.CPG_STATUS AS status,
                       cp.CPG_NUM_PARCELA AS num_parcela,
                       cp.CPG_QTD_PARCELAS AS qtd_parcelas,
                       COALESCE(NULLIF(f.FOR_NOME_FANTASIA,''), f.FOR_RAZAO_SOCIAL) AS fornecedor
                FROM tb_contas_pagar cp
                LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
                WHERE cp.CPG_OFX_MOVIMENTO_FK IS NULL
                  AND UPPER(COALESCE(cp.CPG_STATUS,'')) <> 'CANCELADO'
                  AND (
                        (cp.CPG_VENCIMENTO BETWEEN ? AND ?)
                     OR (cp.CPG_DATA_PAGAMENTO BETWEEN ? AND ?)
                     OR (cp.CPG_VALOR_PAGO IS NOT NULL
                         AND cp.CPG_VALOR_PAGO > 0
                         AND cp.CPG_VALOR_PAGO < cp.CPG_VALOR_PARCELA)
                  )
                ORDER BY cp.CPG_VENCIMENTO ASC, cp.CPG_CODIGO_PK ASC
            ";
            $params = [$iniMes, $fimMes, $iniMes, $fimMes];
        } else {
            // Mais inclusivo: aceita qualquer status exceto CANCELADO (incluindo ATRASADO),
            // e traz também contas com saldo parcial em qualquer mês (uma conta parcial
            // pode ser completada por um OFX de outro mês).
            $sql = "
                SELECT cr.CRE_ID AS id,
                       cr.CRE_VENCIMENTO AS vencimento,
                       cr.CRE_VALOR AS valor,
                       cr.CRE_VALOR_RECEBIDO AS valor_recebido,
                       cr.CRE_RECEBIDO_EM AS data_recebimento,
                       cr.CRE_OBSERVACAO AS descricao,
                       cr.CRE_DOCUMENTO AS documento,
                       cr.CRE_STATUS AS status,
                       cpa.CPA_NUM AS num_parcela,
                       cpa.CPA_TOTAL AS qtd_parcelas,
                       COALESCE(cr.CRE_CLIENTE_NOME, '') AS cliente
                FROM tb_contas_receber cr
                LEFT JOIN contrato_parcelas cpa
                    ON cpa.CPA_CTR_ID = cr.CRE_CONTRATO_FK
                   AND cpa.CPA_VENCIMENTO = cr.CRE_VENCIMENTO
                WHERE cr.CRE_OFX_MOVIMENTO_FK IS NULL
                  AND UPPER(COALESCE(cr.CRE_STATUS,'')) <> 'CANCELADO'
                  AND (
                        (cr.CRE_VENCIMENTO BETWEEN ? AND ?)
                     OR (cr.CRE_RECEBIDO_EM BETWEEN ? AND ?)
                     OR (cr.CRE_VALOR_RECEBIDO IS NOT NULL
                         AND cr.CRE_VALOR_RECEBIDO > 0
                         AND cr.CRE_VALOR_RECEBIDO < cr.CRE_VALOR)
                  )
                ORDER BY cr.CRE_VENCIMENTO ASC, cr.CRE_ID ASC
            ";
            $params = [$iniMes, $fimMes, $iniMes, $fimMes];
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'buscar_lancamento_existente') {
        $tipo    = strtoupper((string)($_GET['tipo'] ?? ''));
        $q       = trim((string)($_GET['q'] ?? ''));
        $valor   = (float)($_GET['valor'] ?? 0);
        $bancoFk = (int)($_GET['banco_fk'] ?? 0);
        $qLike   = '%' . $q . '%';

        if (!in_array($tipo, ['PAGAR', 'RECEBER'], true)) {
            json_out(['ok' => false, 'msg' => 'Tipo inválido.'], 422);
        }

        // Detecta busca por #ID (ex.: "2950" ou "#2950"). Com texto/#ID a busca NÃO
        // trava por valor, banco nem mês — acha o lançamento exato em qualquer período
        // (exigindo apenas que não esteja vinculado nem cancelado). Sem texto, mantém
        // o autocomplete por valor (comportamento original).
        $idBusca  = preg_match('/^#?(\d+)$/', $q, $mq) ? (int)$mq[1] : 0;
        $temTexto = ($q !== '');

        if ($tipo === 'PAGAR') {
            $cols = "cp.CPG_CODIGO_PK AS id,
                     cp.CPG_VENCIMENTO AS vencimento,
                     cp.CPG_VALOR_PARCELA AS valor,
                     cp.CPG_DESCRICAO AS descricao,
                     cp.CPG_DOCUMENTO AS documento,
                     cp.CPG_STATUS AS status,
                     cp.CPG_DATA_PAGAMENTO AS data_pagamento,
                     cp.CPG_VALOR_PAGO AS valor_pago,
                     cp.CPG_OFX_MOVIMENTO_FK AS ja_vinculado,
                     cp.CPG_NUM_PARCELA AS num_parcela,
                     cp.CPG_QTD_PARCELAS AS qtd_parcelas,
                     f.FOR_RAZAO_SOCIAL AS fornecedor_razao,
                     f.FOR_NOME_FANTASIA AS fornecedor_fantasia";
            $where  = ["cp.CPG_OFX_MOVIMENTO_FK IS NULL", "UPPER(COALESCE(cp.CPG_STATUS,'')) <> 'CANCELADO'"];
            $params = [];
            if ($temTexto) {
                $cond = "cp.CPG_DESCRICAO LIKE ? OR cp.CPG_DOCUMENTO LIKE ? OR cp.CPG_NOTA_FISCAL LIKE ?
                         OR f.FOR_RAZAO_SOCIAL LIKE ? OR f.FOR_NOME_FANTASIA LIKE ?";
                $params = [$qLike, $qLike, $qLike, $qLike, $qLike];
                if ($idBusca > 0) { $cond = "cp.CPG_CODIGO_PK = ? OR " . $cond; array_unshift($params, $idBusca); }
                $where[] = "($cond)";
            } else {
                $where[] = "cp.CPG_STATUS IN ('ABERTO','ATRASADO','PAGO')";
                $where[] = "ABS(IFNULL(cp.CPG_VALOR_PAGO, cp.CPG_VALOR_PARCELA) - ?) < 0.50";
                $params[] = $valor;
                if ($bancoFk > 0) { $where[] = "(cp.CPG_BANCO_PAGAMENTO_FK = ? OR cp.CPG_BANCO_PAGAMENTO_FK IS NULL)"; $params[] = $bancoFk; }
            }
            $sql = "SELECT {$cols}
                    FROM tb_contas_pagar cp
                    LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = cp.CPG_FORNECEDOR_FK
                    WHERE " . implode("\n      AND ", $where) . "
                    ORDER BY ABS(DATEDIFF(cp.CPG_VENCIMENTO, CURDATE())) ASC
                    LIMIT 30";
        } else {
            $cols = "cr.CRE_ID AS id,
                     cr.CRE_VENCIMENTO AS vencimento,
                     cr.CRE_VALOR AS valor,
                     cr.CRE_OBSERVACAO AS descricao,
                     cr.CRE_DOCUMENTO AS documento,
                     cr.CRE_STATUS AS status,
                     cr.CRE_RECEBIDO_EM AS data_recebimento,
                     cr.CRE_VALOR_RECEBIDO AS valor_recebido,
                     cr.CRE_OFX_MOVIMENTO_FK AS ja_vinculado,
                     cpa.CPA_NUM AS num_parcela,
                     cpa.CPA_TOTAL AS qtd_parcelas,
                     cr.CRE_CLIENTE_NOME AS cliente_nome";
            $where  = ["cr.CRE_OFX_MOVIMENTO_FK IS NULL", "UPPER(COALESCE(cr.CRE_STATUS,'')) <> 'CANCELADO'"];
            $params = [];
            if ($temTexto) {
                $cond = "cr.CRE_OBSERVACAO LIKE ? OR cr.CRE_DOCUMENTO LIKE ? OR cr.CRE_CLIENTE_NOME LIKE ?";
                $params = [$qLike, $qLike, $qLike];
                if ($idBusca > 0) { $cond = "cr.CRE_ID = ? OR " . $cond; array_unshift($params, $idBusca); }
                $where[] = "($cond)";
            } else {
                $where[] = "cr.CRE_STATUS IN ('ABERTO','PROGRAMADO','PENDENTE','RECEBIDO','PAGO')";
                $where[] = "ABS(IFNULL(cr.CRE_VALOR_RECEBIDO, cr.CRE_VALOR) - ?) < 0.50";
                $params[] = $valor;
                if ($bancoFk > 0) { $where[] = "(cr.CRE_BANCO_FK = ? OR cr.CRE_BANCO_FK IS NULL)"; $params[] = $bancoFk; }
            }
            $sql = "SELECT {$cols}
                    FROM tb_contas_receber cr
                    LEFT JOIN contrato_parcelas cpa
                        ON cpa.CPA_CTR_ID = cr.CRE_CONTRATO_FK
                       AND cpa.CPA_VENCIMENTO = cr.CRE_VENCIMENTO
                    WHERE " . implode("\n      AND ", $where) . "
                    ORDER BY ABS(DATEDIFF(cr.CRE_VENCIMENTO, CURDATE())) ASC
                    LIMIT 30";
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'vincular_lancamento_existente') {
        $tipo   = strtoupper((string)($_POST['tipo'] ?? ''));
        $lancId = (int)($_POST['lancamento_id'] ?? 0);
        $movFk  = (int)($_POST['movimento_fk'] ?? 0);

        if (!in_array($tipo, ['PAGAR', 'RECEBER'], true) || $lancId <= 0 || $movFk <= 0) {
            json_out(['ok' => false, 'msg' => 'Parâmetros inválidos.'], 422);
        }

        $stMov = $pdo->prepare("
            SELECT COM_CODIGO_PK, COM_BANCO_FK, COM_DATA_MOVIMENTO, COM_VALOR, COM_CONCILIADO,
                   COM_REFERENCIA_TIPO, COM_REFERENCIA_FK
            FROM tb_conciliacao_ofx_movimento
            WHERE COM_CODIGO_PK = ? LIMIT 1
        ");
        $stMov->execute([$movFk]);
        $mov = $stMov->fetch(PDO::FETCH_ASSOC);
        if (!$mov) json_out(['ok' => false, 'msg' => 'Movimento OFX não encontrado.'], 404);
        if (strtoupper((string)$mov['COM_CONCILIADO']) === 'SIM') {
            json_out(['ok' => false, 'msg' => 'Este movimento já foi conciliado.'], 409);
        }
        $valorAbs = abs((float)$mov['COM_VALOR']);
        $dataMov  = (string)$mov['COM_DATA_MOVIMENTO'];
        $bancoFk  = (int)$mov['COM_BANCO_FK'];

        $pdo->beginTransaction();
        try {
            if ($tipo === 'PAGAR') {
                $stL = $pdo->prepare("SELECT CPG_CODIGO_PK, CPG_STATUS, CPG_VALOR_PARCELA, CPG_VENCIMENTO,
                                             COALESCE(CPG_VALOR_PAGO,0) AS CPG_VALOR_PAGO,
                                             CPG_OFX_MOVIMENTO_FK
                                      FROM tb_contas_pagar WHERE CPG_CODIGO_PK = ? LIMIT 1");
                $stL->execute([$lancId]);
                $lanc = $stL->fetch(PDO::FETCH_ASSOC);
                if (!$lanc) throw new Exception('Lançamento a pagar não encontrado.');
                if ($lanc['CPG_OFX_MOVIMENTO_FK']) {
                    throw new Exception('Este lançamento já está vinculado a outro movimento OFX.');
                }

                $statusAtual = strtoupper((string)$lanc['CPG_STATUS']);
                if ($statusAtual === 'PAGO') {
                    $pdo->prepare("UPDATE tb_contas_pagar
                                   SET CPG_OFX_MOVIMENTO_FK = ?
                                   WHERE CPG_CODIGO_PK = ?")
                        ->execute([$movFk, $lancId]);
                } else {
                    // Soma o valor do OFX ao já pago (suporta múltiplos pagamentos numa mesma parcela).
                    $valorOriginal = (float)$lanc['CPG_VALOR_PARCELA'];
                    $jaPago        = (float)$lanc['CPG_VALOR_PAGO'];
                    $novoPago      = round($jaPago + $valorAbs, 2);
                    $quitado       = ($novoPago + 0.005) >= $valorOriginal;
                    $venc          = (string)($lanc['CPG_VENCIMENTO'] ?? '');
                    if ($quitado) {
                        $novoStatus = 'PAGO';
                        $novoPagoFlag = 'SIM';
                        $novoTipo   = 'INTEGRAL';
                    } else {
                        $novoStatus = ($venc !== '' && $venc < date('Y-m-d')) ? 'ATRASADO' : 'ABERTO';
                        $novoPagoFlag = 'NAO';
                        $novoTipo   = 'PARCIAL';
                    }
                    $pdo->prepare("UPDATE tb_contas_pagar
                                   SET CPG_PAGO = ?,
                                       CPG_STATUS = ?,
                                       CPG_INTEGRAL_PARCIAL = ?,
                                       CPG_DATA_PAGAMENTO = ?,
                                       CPG_BANCO_PAGAMENTO_FK = ?,
                                       CPG_VALOR_PAGO = ?,
                                       CPG_OFX_MOVIMENTO_FK = ?,
                                       CPG_AUTORIZACAO_STATUS = COALESCE(CPG_AUTORIZACAO_STATUS, 'AUTORIZADO')
                                   WHERE CPG_CODIGO_PK = ?")
                        ->execute([$novoPagoFlag, $novoStatus, $novoTipo, $dataMov, $bancoFk, $novoPago, $movFk, $lancId]);
                }
            } else {
                $stL = $pdo->prepare("SELECT CRE_ID, CRE_STATUS, CRE_VALOR, CRE_VENCIMENTO,
                                             COALESCE(CRE_VALOR_RECEBIDO,0) AS CRE_VALOR_RECEBIDO,
                                             CRE_OFX_MOVIMENTO_FK
                                      FROM tb_contas_receber WHERE CRE_ID = ? LIMIT 1");
                $stL->execute([$lancId]);
                $lanc = $stL->fetch(PDO::FETCH_ASSOC);
                if (!$lanc) throw new Exception('Lançamento a receber não encontrado.');
                if ($lanc['CRE_OFX_MOVIMENTO_FK']) {
                    throw new Exception('Este lançamento já está vinculado a outro movimento OFX.');
                }

                $statusAtual = strtoupper((string)$lanc['CRE_STATUS']);
                if (in_array($statusAtual, ['RECEBIDO', 'PAGO'], true)) {
                    $pdo->prepare("UPDATE tb_contas_receber
                                   SET CRE_OFX_MOVIMENTO_FK = ?
                                   WHERE CRE_ID = ?")
                        ->execute([$movFk, $lancId]);
                } else {
                    // Soma o valor do OFX ao que já foi recebido (suporta múltiplos PIX numa mesma parcela).
                    $valorOriginal = (float)$lanc['CRE_VALOR'];
                    $jaRecebido    = (float)$lanc['CRE_VALOR_RECEBIDO'];
                    $novoRecebido  = round($jaRecebido + $valorAbs, 2);
                    $quitado       = ($novoRecebido + 0.005) >= $valorOriginal;
                    $venc          = (string)($lanc['CRE_VENCIMENTO'] ?? '');
                    if ($quitado) {
                        $novoStatus = 'RECEBIDO';
                        $novoTipo   = 'INTEGRAL';
                    } else {
                        $novoStatus = ($venc !== '' && $venc < date('Y-m-d')) ? 'ATRASADO' : 'ABERTO';
                        $novoTipo   = 'PARCIAL';
                    }
                    $pdo->prepare("UPDATE tb_contas_receber
                                   SET CRE_STATUS = ?,
                                       CRE_TIPO_RECEBIMENTO = ?,
                                       CRE_RECEBIDO_EM = ?,
                                       CRE_BANCO_FK = ?,
                                       CRE_VALOR_RECEBIDO = ?,
                                       CRE_OFX_MOVIMENTO_FK = ?
                                   WHERE CRE_ID = ?")
                        ->execute([$novoStatus, $novoTipo, $dataMov, $bancoFk, $novoRecebido, $movFk, $lancId]);
                }
            }

            $pdo->prepare("UPDATE tb_conciliacao_ofx_movimento
                           SET COM_STATUS = 'CONCILIADO',
                               COM_CONCILIADO = 'SIM',
                               COM_REFERENCIA_TIPO = ?,
                               COM_REFERENCIA_FK = ?
                           WHERE COM_CODIGO_PK = ?")
                ->execute([$tipo === 'PAGAR' ? 'CONTA_PAGAR' : 'CONTA_RECEBER', $lancId, $movFk]);

            $pdo->commit();
            json_out(['ok' => true, 'msg' => 'Vínculo realizado com sucesso.']);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_out(['ok' => false, 'msg' => $e->getMessage()], 422);
        }
    }

    // ============================================================
    // HISTÓRICO DE IMPORTAÇÕES OFX (T-NOVO)
    // Listagem das importações + exclusão controlada por senha admin.
    // ============================================================

    if ($acao === 'listar_importacoes_ofx') {
        // Lista importações com contadores: total de movimentos, conciliados, vínculos ativos.
        $bancoFk = (int)($_GET['banco_fk'] ?? 0);
        $limit   = max(1, min(500, (int)($_GET['limit'] ?? 100)));

        $params = [];
        $where  = "";
        if ($bancoFk > 0) {
            $where = " WHERE i.COI_BANCO_FK = :banco ";
            $params[':banco'] = $bancoFk;
        }

        $sql = "
            SELECT i.COI_CODIGO_PK   AS id,
                   i.COI_DATA_CADASTRO AS importado_em,
                   i.COI_USUARIO     AS usuario,
                   i.COI_NOME_ARQUIVO AS arquivo,
                   i.COI_BANCO_FK    AS banco_fk,
                   COALESCE(NULLIF(b.BAN_APELIDO,''), b.BAN_NOME) AS banco_nome,
                   i.COI_CONTA_REF   AS conta,
                   i.COI_DATA_INICIAL AS periodo_ini,
                   i.COI_DATA_FINAL   AS periodo_fim,
                   i.COI_SALDO_INICIAL AS saldo_ini,
                   i.COI_SALDO_FINAL  AS saldo_fim,
                   i.COI_TOTAL_ENTRADAS AS entradas,
                   i.COI_TOTAL_SAIDAS   AS saidas,
                   i.COI_STATUS      AS status,
                   (SELECT COUNT(*) FROM tb_conciliacao_ofx_movimento m
                     WHERE m.COM_IMPORTACAO_FK = i.COI_CODIGO_PK) AS qtd_movimentos,
                   (SELECT COUNT(*) FROM tb_conciliacao_ofx_movimento m
                     WHERE m.COM_IMPORTACAO_FK = i.COI_CODIGO_PK
                       AND m.COM_CONCILIADO = 'SIM') AS qtd_conciliados,
                   (SELECT COUNT(*) FROM tb_conciliacao_vinculo v
                     INNER JOIN tb_conciliacao_ofx_movimento m
                             ON m.COM_CODIGO_PK = v.VIN_OFX_MOVIMENTO_FK
                     WHERE m.COM_IMPORTACAO_FK = i.COI_CODIGO_PK
                       AND v.VIN_STATUS = 'ATIVO') AS qtd_vinculos_ativos,
                   (SELECT COUNT(*) FROM tb_contas_receber cr
                     WHERE cr.CRE_ORIGEM = 'CONCILIACAO'
                       AND cr.CRE_OFX_MOVIMENTO_FK IN
                         (SELECT COM_CODIGO_PK FROM tb_conciliacao_ofx_movimento
                          WHERE COM_IMPORTACAO_FK = i.COI_CODIGO_PK)) AS qtd_avulsos_criados
              FROM tb_conciliacao_ofx_importacao i
              LEFT JOIN tb_banco b ON b.BAN_ID = i.COI_BANCO_FK
              {$where}
              ORDER BY i.COI_DATA_CADASTRO DESC, i.COI_CODIGO_PK DESC
              LIMIT {$limit}
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'excluir_importacao_ofx') {
        // Exclui uma importação OFX inteira (todos os movimentos) com reversão completa
        // dos vínculos (novos via tb_conciliacao_vinculo + legados via *_OFX_MOVIMENTO_FK)
        // e exclusão dos avulsos criados pelo "criar_receber_em_lote".
        // Requer senha de qualquer usuário ADMIN ativo.

        $importId = (int)($_POST['importacao_id'] ?? 0);
        $senha    = (string)($_POST['senha'] ?? '');
        $motivo   = trim((string)($_POST['motivo'] ?? ''));

        if ($importId <= 0)  json_out(['ok' => false, 'msg' => 'ID da importação inválido.'], 400);
        if ($senha === '')   json_out(['ok' => false, 'msg' => 'Informe a senha de um usuário ADMIN.'], 400);

        // Valida senha contra qualquer ADMIN ativo (mesmo padrão do reabrir_conta)
        $stU = $pdo->prepare("SELECT USU_ID, USU_NOME, USU_SENHA_HASH
                              FROM usuarios
                              WHERE USU_PERFIL = 'ADMIN' AND USU_STATUS = 'ATIVO'");
        $stU->execute();
        $admins = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $adminValido = null;
        foreach ($admins as $u) {
            if (password_verify($senha, (string)($u['USU_SENHA_HASH'] ?? ''))) {
                $adminValido = $u;
                break;
            }
        }
        if (!$adminValido) {
            json_out(['ok' => false, 'msg' => 'Senha inválida. Nenhum usuário ADMIN autenticado.'], 401);
        }
        $adminNome = (string)$adminValido['USU_NOME'];

        // Confere se a importação existe
        $stImp = $pdo->prepare("SELECT COI_CODIGO_PK, COI_NOME_ARQUIVO
                                FROM tb_conciliacao_ofx_importacao WHERE COI_CODIGO_PK = ? LIMIT 1");
        $stImp->execute([$importId]);
        $imp = $stImp->fetch(PDO::FETCH_ASSOC);
        if (!$imp) json_out(['ok' => false, 'msg' => 'Importação não encontrada.'], 404);

        $pdo->beginTransaction();
        try {
            // 1) Lista todos os movimentos dessa importação
            $stMovs = $pdo->prepare("SELECT COM_CODIGO_PK FROM tb_conciliacao_ofx_movimento
                                     WHERE COM_IMPORTACAO_FK = ?");
            $stMovs->execute([$importId]);
            $movIds = array_map(static fn($r) => (int)$r['COM_CODIGO_PK'], $stMovs->fetchAll(PDO::FETCH_ASSOC));

            $totalVinculosCancelados = 0;
            $totalLegadosRevertidos  = 0;
            $totalAvulsosExcluidos   = 0;

            foreach ($movIds as $movFk) {
                // 2) Cancela vínculos novos (tb_conciliacao_vinculo) e reverte alocações
                $stV = $pdo->prepare("SELECT VIN_CODIGO_PK, VIN_LANCAMENTO_TIPO, VIN_LANCAMENTO_FK, VIN_VALOR_ALOCADO
                                      FROM tb_conciliacao_vinculo
                                      WHERE VIN_OFX_MOVIMENTO_FK = ? AND VIN_STATUS = 'ATIVO' FOR UPDATE");
                $stV->execute([$movFk]);
                $vinculos = $stV->fetchAll(PDO::FETCH_ASSOC);

                foreach ($vinculos as $v) {
                    $pdo->prepare("UPDATE tb_conciliacao_vinculo
                                   SET VIN_STATUS = 'CANCELADO',
                                       VIN_CANCELADO_EM = NOW(),
                                       VIN_CANCELADO_POR = ?
                                   WHERE VIN_CODIGO_PK = ?")
                        ->execute([$adminNome . ' (exclusão import #' . $importId . ')',
                                   (int)$v['VIN_CODIGO_PK']]);

                    $tipo = $v['VIN_LANCAMENTO_TIPO'] === 'CONTA_PAGAR' ? 'PAGAR' : 'RECEBER';
                    if (function_exists('reverterAlocacaoConta')) {
                        reverterAlocacaoConta($pdo, $tipo, (int)$v['VIN_LANCAMENTO_FK'], (float)$v['VIN_VALOR_ALOCADO']);
                    }
                    $totalVinculosCancelados++;
                }

                // 3) Trata vínculo legado em CONTAS A PAGAR (CPG_OFX_MOVIMENTO_FK)
                $stLP = $pdo->prepare("SELECT CPG_CODIGO_PK, CPG_VALOR_PAGO, CPG_VALOR_PARCELA
                                       FROM tb_contas_pagar WHERE CPG_OFX_MOVIMENTO_FK = ? FOR UPDATE");
                $stLP->execute([$movFk]);
                foreach ($stLP->fetchAll(PDO::FETCH_ASSOC) as $lp) {
                    $valor = (float)($lp['CPG_VALOR_PAGO'] ?? 0);
                    if ($valor <= 0) $valor = (float)($lp['CPG_VALOR_PARCELA'] ?? 0);
                    if (function_exists('reverterAlocacaoConta') && $valor > 0) {
                        reverterAlocacaoConta($pdo, 'PAGAR', (int)$lp['CPG_CODIGO_PK'], $valor);
                    }
                    $pdo->prepare("UPDATE tb_contas_pagar SET CPG_OFX_MOVIMENTO_FK = NULL WHERE CPG_CODIGO_PK = ?")
                        ->execute([(int)$lp['CPG_CODIGO_PK']]);
                    $totalLegadosRevertidos++;
                }

                // 4) Trata vínculo legado em CONTAS A RECEBER (CRE_OFX_MOVIMENTO_FK)
                //    Distinção: avulsos criados via "criar_receber_em_lote" (CRE_ORIGEM='CONCILIACAO')
                //    devem ser EXCLUÍDOS — eles existem só por causa do OFX.
                //    Os demais (parcelas reais com OFX vinculado) só desvinculam e revertem.
                $stLR = $pdo->prepare("SELECT CRE_ID, CRE_ORIGEM, CRE_VALOR_RECEBIDO, CRE_VALOR
                                       FROM tb_contas_receber WHERE CRE_OFX_MOVIMENTO_FK = ? FOR UPDATE");
                $stLR->execute([$movFk]);
                foreach ($stLR->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                    if (strtoupper((string)$lr['CRE_ORIGEM']) === 'CONCILIACAO') {
                        // Avulso criado pelo OFX: exclui inteiro
                        $pdo->prepare("DELETE FROM tb_contas_receber WHERE CRE_ID = ?")
                            ->execute([(int)$lr['CRE_ID']]);
                        $totalAvulsosExcluidos++;
                    } else {
                        // Parcela real: reverte alocação e desvincula
                        $valor = (float)($lr['CRE_VALOR_RECEBIDO'] ?? 0);
                        if ($valor <= 0) $valor = (float)($lr['CRE_VALOR'] ?? 0);
                        if (function_exists('reverterAlocacaoConta') && $valor > 0) {
                            reverterAlocacaoConta($pdo, 'RECEBER', (int)$lr['CRE_ID'], $valor);
                        }
                        $pdo->prepare("UPDATE tb_contas_receber SET CRE_OFX_MOVIMENTO_FK = NULL WHERE CRE_ID = ?")
                            ->execute([(int)$lr['CRE_ID']]);
                        $totalLegadosRevertidos++;
                    }
                }
            }

            // 5) Exclui movimentos da importação
            $stDelMov = $pdo->prepare("DELETE FROM tb_conciliacao_ofx_movimento WHERE COM_IMPORTACAO_FK = ?");
            $stDelMov->execute([$importId]);
            $movsExcluidos = $stDelMov->rowCount();

            // 6) Exclui o registro da importação
            $stDelImp = $pdo->prepare("DELETE FROM tb_conciliacao_ofx_importacao WHERE COI_CODIGO_PK = ?");
            $stDelImp->execute([$importId]);

            $pdo->commit();

            json_out([
                'ok' => true,
                'msg' => sprintf(
                    'Importação #%d (%s) excluída. %d movimento(s) removido(s), %d vínculo(s) cancelado(s), %d vínculo(s) legado(s) revertido(s), %d avulso(s) excluído(s).',
                    $importId,
                    (string)$imp['COI_NOME_ARQUIVO'],
                    $movsExcluidos,
                    $totalVinculosCancelados,
                    $totalLegadosRevertidos,
                    $totalAvulsosExcluidos
                ),
                'autorizado_por'      => $adminNome,
                'movimentos_excluidos'=> $movsExcluidos,
                'vinculos_cancelados' => $totalVinculosCancelados,
                'legados_revertidos'  => $totalLegadosRevertidos,
                'avulsos_excluidos'   => $totalAvulsosExcluidos,
                'motivo'              => $motivo,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['ok' => false, 'msg' => 'Falha na exclusão: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================
    // TRANSFERÊNCIAS INTERNAS / NATUREZA DOS MOVIMENTOS OFX
    // (Briefing consolidado 2026-06-09)
    // ============================================================

    if ($acao === 'detectar_pares_transferencia') {
        // Casa débito (saída) em banco A com crédito (entrada) em banco B,
        // ambos marcados como TRANSFERENCIA_INTERNA, com mesmo valor (±1 ct)
        // e datas dentro de ±1 dia. Cria 1 par ATIVO em tb_transferencia_interna.
        $pdo->beginTransaction();
        try {
            $stSaidas = $pdo->query("
                SELECT m.COM_CODIGO_PK, m.COM_BANCO_FK, m.COM_DATA_MOVIMENTO,
                       m.COM_VALOR, m.COM_DOCUMENTO_CONTRAPARTE
                FROM tb_conciliacao_ofx_movimento m
                WHERE m.COM_TIPO = 'DEBITO'
                  AND m.COM_NATUREZA = 'TRANSFERENCIA_INTERNA'
                  AND NOT EXISTS (
                      SELECT 1 FROM tb_transferencia_interna t
                      WHERE t.TFI_MOV_ORIGEM_FK = m.COM_CODIGO_PK AND t.TFI_STATUS = 'ATIVO'
                  )
            ");
            $saidas = $stSaidas->fetchAll(PDO::FETCH_ASSOC);

            $stEntrada = $pdo->prepare("
                SELECT m.COM_CODIGO_PK
                FROM tb_conciliacao_ofx_movimento m
                WHERE m.COM_TIPO = 'CREDITO'
                  AND m.COM_NATUREZA = 'TRANSFERENCIA_INTERNA'
                  AND m.COM_BANCO_FK <> :banco_origem
                  AND ABS(ABS(m.COM_VALOR) - :valor) < 0.01
                  AND ABS(DATEDIFF(m.COM_DATA_MOVIMENTO, :data)) <= 1
                  AND NOT EXISTS (
                      SELECT 1 FROM tb_transferencia_interna t
                      WHERE t.TFI_MOV_DESTINO_FK = m.COM_CODIGO_PK AND t.TFI_STATUS = 'ATIVO'
                  )
                LIMIT 1
            ");

            $stIns = $pdo->prepare("
                INSERT INTO tb_transferencia_interna
                    (TFI_MOV_ORIGEM_FK, TFI_MOV_DESTINO_FK, TFI_VALOR, TFI_MODO_DETECCAO, TFI_USUARIO)
                VALUES (?, ?, ?, 'AUTOMATICO', ?)
            ");

            $usuario = (string)($_SESSION['user_nome'] ?? $_SESSION['usuarioSession'] ?? 'Sistema');
            $criados = 0;

            foreach ($saidas as $s) {
                $stEntrada->execute([
                    ':banco_origem' => $s['COM_BANCO_FK'],
                    ':valor' => abs((float)$s['COM_VALOR']),
                    ':data' => $s['COM_DATA_MOVIMENTO'],
                ]);
                $destino = $stEntrada->fetch(PDO::FETCH_ASSOC);
                if (!$destino) continue;

                $stIns->execute([
                    $s['COM_CODIGO_PK'],
                    $destino['COM_CODIGO_PK'],
                    abs((float)$s['COM_VALOR']),
                    $usuario,
                ]);
                $criados++;
            }

            $pdo->commit();
            json_out(['ok' => true, 'pares_criados' => $criados]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['ok' => false, 'msg' => 'Falha: ' . $e->getMessage()], 500);
        }
    }

    if ($acao === 'listar_transferencias_internas') {
        $st = $pdo->query("
            SELECT t.TFI_CODIGO_PK AS id,
                   t.TFI_VALOR AS valor,
                   DATE_FORMAT(t.TFI_DATA_DETECCAO, '%d/%m/%Y %H:%i') AS data_deteccao,
                   t.TFI_MODO_DETECCAO AS modo,
                   mo.COM_CODIGO_PK AS origem_id,
                   DATE_FORMAT(mo.COM_DATA_MOVIMENTO, '%d/%m/%Y') AS origem_data,
                   mo.COM_DESCRICAO AS origem_desc,
                   bo.BAN_APELIDO AS origem_banco,
                   md.COM_CODIGO_PK AS destino_id,
                   DATE_FORMAT(md.COM_DATA_MOVIMENTO, '%d/%m/%Y') AS destino_data,
                   md.COM_DESCRICAO AS destino_desc,
                   bd.BAN_APELIDO AS destino_banco
            FROM tb_transferencia_interna t
            INNER JOIN tb_conciliacao_ofx_movimento mo ON mo.COM_CODIGO_PK = t.TFI_MOV_ORIGEM_FK
            INNER JOIN tb_conciliacao_ofx_movimento md ON md.COM_CODIGO_PK = t.TFI_MOV_DESTINO_FK
            LEFT JOIN tb_banco bo ON bo.BAN_ID = mo.COM_BANCO_FK
            LEFT JOIN tb_banco bd ON bd.BAN_ID = md.COM_BANCO_FK
            WHERE t.TFI_STATUS = 'ATIVO'
            ORDER BY t.TFI_DATA_DETECCAO DESC
            LIMIT 200
        ");
        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'listar_transferencias_sem_par') {
        $st = $pdo->query("
            SELECT m.COM_CODIGO_PK AS id,
                   DATE_FORMAT(m.COM_DATA_MOVIMENTO, '%d/%m/%Y') AS data,
                   m.COM_VALOR AS valor,
                   m.COM_DESCRICAO AS descricao,
                   m.COM_TIPO AS tipo,
                   b.BAN_APELIDO AS banco,
                   m.COM_DOCUMENTO_CONTRAPARTE AS doc_contraparte
            FROM tb_conciliacao_ofx_movimento m
            LEFT JOIN tb_banco b ON b.BAN_ID = m.COM_BANCO_FK
            WHERE m.COM_NATUREZA = 'TRANSFERENCIA_INTERNA'
              AND NOT EXISTS (
                SELECT 1 FROM tb_transferencia_interna t
                WHERE (t.TFI_MOV_ORIGEM_FK = m.COM_CODIGO_PK OR t.TFI_MOV_DESTINO_FK = m.COM_CODIGO_PK)
                  AND t.TFI_STATUS = 'ATIVO'
              )
            ORDER BY m.COM_DATA_MOVIMENTO DESC, m.COM_CODIGO_PK DESC
            LIMIT 200
        ");
        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'alterar_natureza_movimento') {
        $movId    = (int)($_POST['movimento_fk'] ?? 0);
        $natureza = strtoupper((string)($_POST['natureza'] ?? 'NORMAL'));
        $valid    = ['NORMAL','TRANSFERENCIA_INTERNA','APLICACAO','RENDIMENTO','TARIFA'];
        if ($movId <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);
        if (!in_array($natureza, $valid, true)) {
            json_out(['ok' => false, 'msg' => 'Natureza inválida.'], 422);
        }
        $pdo->prepare("UPDATE tb_conciliacao_ofx_movimento SET COM_NATUREZA = ? WHERE COM_CODIGO_PK = ?")
            ->execute([$natureza, $movId]);

        // Se voltou para NORMAL, cancela par associado (se houver)
        if ($natureza === 'NORMAL') {
            $pdo->prepare("UPDATE tb_transferencia_interna
                           SET TFI_STATUS = 'CANCELADO'
                           WHERE (TFI_MOV_ORIGEM_FK = ? OR TFI_MOV_DESTINO_FK = ?)
                             AND TFI_STATUS = 'ATIVO'")
                ->execute([$movId, $movId]);
        }
        json_out(['ok' => true]);
    }

    if ($acao === 'vincular_par_manual') {
        $origemId  = (int)($_POST['origem_id'] ?? 0);
        $destinoId = (int)($_POST['destino_id'] ?? 0);
        if ($origemId <= 0 || $destinoId <= 0 || $origemId === $destinoId) {
            json_out(['ok' => false, 'msg' => 'IDs inválidos.'], 422);
        }

        $stV = $pdo->prepare("SELECT COM_CODIGO_PK, COM_TIPO, COM_VALOR, COM_NATUREZA, COM_BANCO_FK
                              FROM tb_conciliacao_ofx_movimento
                              WHERE COM_CODIGO_PK IN (?, ?)");
        $stV->execute([$origemId, $destinoId]);
        $rows = $stV->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 2) json_out(['ok' => false, 'msg' => 'Movimentos não encontrados.'], 404);

        $origem  = ((int)$rows[0]['COM_CODIGO_PK'] === $origemId)  ? $rows[0] : $rows[1];
        $destino = ((int)$rows[0]['COM_CODIGO_PK'] === $destinoId) ? $rows[0] : $rows[1];

        if (strtoupper((string)$origem['COM_TIPO']) !== 'DEBITO' || strtoupper((string)$destino['COM_TIPO']) !== 'CREDITO') {
            json_out(['ok' => false, 'msg' => 'Origem deve ser DÉBITO, destino deve ser CRÉDITO.'], 422);
        }
        if ((int)$origem['COM_BANCO_FK'] === (int)$destino['COM_BANCO_FK']) {
            json_out(['ok' => false, 'msg' => 'Origem e destino devem ser bancos diferentes.'], 422);
        }
        if (abs(abs((float)$origem['COM_VALOR']) - (float)$destino['COM_VALOR']) > 0.01) {
            json_out(['ok' => false, 'msg' => 'Valores não batem entre origem e destino.'], 422);
        }

        $usuario = (string)($_SESSION['user_nome'] ?? $_SESSION['usuarioSession'] ?? 'Sistema');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE tb_conciliacao_ofx_movimento
                           SET COM_NATUREZA = 'TRANSFERENCIA_INTERNA'
                           WHERE COM_CODIGO_PK IN (?, ?)")
                ->execute([$origemId, $destinoId]);

            $pdo->prepare("
                INSERT INTO tb_transferencia_interna
                    (TFI_MOV_ORIGEM_FK, TFI_MOV_DESTINO_FK, TFI_VALOR, TFI_MODO_DETECCAO, TFI_USUARIO)
                VALUES (?, ?, ?, 'MANUAL', ?)
            ")->execute([$origemId, $destinoId, abs((float)$origem['COM_VALOR']), $usuario]);
            $pdo->commit();
            json_out(['ok' => true, 'msg' => 'Par vinculado.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['ok' => false, 'msg' => 'Falha: ' . $e->getMessage()], 500);
        }
    }

    if ($acao === 'auditar_vinculos_ofx') {
        // Retorna 4 coortes de inconsistência:
        //  A) conta_pagar PAGO com CPG_OFX_MOVIMENTO_FK preenchido + movimento OFX correspondente NÃO conciliado
        //  B) movimento OFX com COM_REFERENCIA_TIPO='CONTA_PAGAR' + COM_REFERENCIA_FK apontando pra conta sem CPG_OFX_MOVIMENTO_FK
        //  C) conta_receber RECEBIDO com CRE_OFX_MOVIMENTO_FK preenchido + movimento OFX correspondente NÃO conciliado
        //  D) movimento OFX com COM_REFERENCIA_TIPO='CONTA_RECEBER' + conta sem CRE_OFX_MOVIMENTO_FK
        $coorteA = $pdo->query("
            SELECT cp.CPG_CODIGO_PK AS conta_id, cp.CPG_OFX_MOVIMENTO_FK AS mov_fk,
                   cp.CPG_DESCRICAO AS descricao, cp.CPG_VALOR_PAGO AS valor,
                   cp.CPG_DATA_PAGAMENTO AS data
            FROM tb_contas_pagar cp
            INNER JOIN tb_conciliacao_ofx_movimento m
                ON m.COM_CODIGO_PK = cp.CPG_OFX_MOVIMENTO_FK
            WHERE cp.CPG_STATUS = 'PAGO'
              AND COALESCE(m.COM_CONCILIADO,'NAO') <> 'SIM'
            LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);

        $coorteB = $pdo->query("
            SELECT m.COM_CODIGO_PK AS mov_fk, m.COM_REFERENCIA_FK AS conta_id,
                   m.COM_DESCRICAO AS descricao, m.COM_VALOR AS valor,
                   m.COM_DATA_MOVIMENTO AS data
            FROM tb_conciliacao_ofx_movimento m
            INNER JOIN tb_contas_pagar cp ON cp.CPG_CODIGO_PK = m.COM_REFERENCIA_FK
            WHERE m.COM_REFERENCIA_TIPO = 'CONTA_PAGAR'
              AND m.COM_REFERENCIA_FK IS NOT NULL
              AND cp.CPG_OFX_MOVIMENTO_FK IS NULL
            LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);

        $coorteC = $pdo->query("
            SELECT cr.CRE_ID AS conta_id, cr.CRE_OFX_MOVIMENTO_FK AS mov_fk,
                   cr.CRE_DESCRICAO AS descricao, cr.CRE_VALOR_RECEBIDO AS valor,
                   cr.CRE_RECEBIDO_EM AS data
            FROM tb_contas_receber cr
            INNER JOIN tb_conciliacao_ofx_movimento m
                ON m.COM_CODIGO_PK = cr.CRE_OFX_MOVIMENTO_FK
            WHERE cr.CRE_STATUS IN ('RECEBIDO','PAGO')
              AND COALESCE(m.COM_CONCILIADO,'NAO') <> 'SIM'
            LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);

        $coorteD = $pdo->query("
            SELECT m.COM_CODIGO_PK AS mov_fk, m.COM_REFERENCIA_FK AS conta_id,
                   m.COM_DESCRICAO AS descricao, m.COM_VALOR AS valor,
                   m.COM_DATA_MOVIMENTO AS data
            FROM tb_conciliacao_ofx_movimento m
            INNER JOIN tb_contas_receber cr ON cr.CRE_ID = m.COM_REFERENCIA_FK
            WHERE m.COM_REFERENCIA_TIPO = 'CONTA_RECEBER'
              AND m.COM_REFERENCIA_FK IS NOT NULL
              AND cr.CRE_OFX_MOVIMENTO_FK IS NULL
            LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);

        json_out([
            'ok' => true,
            'coorteA' => $coorteA, 'totalA' => count($coorteA),
            'coorteB' => $coorteB, 'totalB' => count($coorteB),
            'coorteC' => $coorteC, 'totalC' => count($coorteC),
            'coorteD' => $coorteD, 'totalD' => count($coorteD),
            'total'   => count($coorteA) + count($coorteB) + count($coorteC) + count($coorteD),
        ]);
    }

    if ($acao === 'curar_vinculos_ofx') {
        $pdo->beginTransaction();
        try {
            // A: marcar movimento como conciliado quando há conta apontando pra ele
            $a = $pdo->exec("
                UPDATE tb_conciliacao_ofx_movimento m
                INNER JOIN tb_contas_pagar cp
                    ON cp.CPG_OFX_MOVIMENTO_FK = m.COM_CODIGO_PK
                    AND cp.CPG_STATUS = 'PAGO'
                SET m.COM_STATUS = 'CONCILIADO',
                    m.COM_CONCILIADO = 'SIM',
                    m.COM_REFERENCIA_TIPO = 'CONTA_PAGAR',
                    m.COM_REFERENCIA_FK = cp.CPG_CODIGO_PK
                WHERE COALESCE(m.COM_CONCILIADO,'NAO') <> 'SIM'
            ");

            // B: preencher CPG_OFX_MOVIMENTO_FK quando o movimento aponta pra conta
            $b = $pdo->exec("
                UPDATE tb_contas_pagar cp
                INNER JOIN tb_conciliacao_ofx_movimento m
                    ON m.COM_REFERENCIA_FK = cp.CPG_CODIGO_PK
                    AND m.COM_REFERENCIA_TIPO = 'CONTA_PAGAR'
                SET cp.CPG_OFX_MOVIMENTO_FK = m.COM_CODIGO_PK
                WHERE cp.CPG_OFX_MOVIMENTO_FK IS NULL
            ");

            // C: idem A para receber
            $c = $pdo->exec("
                UPDATE tb_conciliacao_ofx_movimento m
                INNER JOIN tb_contas_receber cr
                    ON cr.CRE_OFX_MOVIMENTO_FK = m.COM_CODIGO_PK
                    AND cr.CRE_STATUS IN ('RECEBIDO','PAGO')
                SET m.COM_STATUS = 'CONCILIADO',
                    m.COM_CONCILIADO = 'SIM',
                    m.COM_REFERENCIA_TIPO = 'CONTA_RECEBER',
                    m.COM_REFERENCIA_FK = cr.CRE_ID
                WHERE COALESCE(m.COM_CONCILIADO,'NAO') <> 'SIM'
            ");

            // D: idem B para receber
            $d = $pdo->exec("
                UPDATE tb_contas_receber cr
                INNER JOIN tb_conciliacao_ofx_movimento m
                    ON m.COM_REFERENCIA_FK = cr.CRE_ID
                    AND m.COM_REFERENCIA_TIPO = 'CONTA_RECEBER'
                SET cr.CRE_OFX_MOVIMENTO_FK = m.COM_CODIGO_PK
                WHERE cr.CRE_OFX_MOVIMENTO_FK IS NULL
            ");

            $pdo->commit();
            json_out([
                'ok'  => true,
                'curados' => ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d, 'total' => $a + $b + $c + $d],
                'msg' => sprintf('%d vínculo(s) curado(s).', $a + $b + $c + $d),
            ]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_out(['ok' => false, 'msg' => 'Falha na cura: ' . $e->getMessage()], 500);
        }
    }

    if ($acao === 'vincular_lancamentos_em_lote') {
        $movFk    = (int)($_POST['movimento_fk'] ?? 0);
        $itensRaw = $_POST['itens'] ?? '';
        $itens    = is_string($itensRaw) ? json_decode($itensRaw, true) : $itensRaw;

        if ($movFk <= 0 || !is_array($itens) || count($itens) === 0) {
            json_out(['ok' => false, 'msg' => 'Parâmetros inválidos.'], 422);
        }

        $stMov = $pdo->prepare("SELECT COM_CODIGO_PK, COM_BANCO_FK, COM_DATA_MOVIMENTO,
                                       COM_VALOR, COM_CONCILIADO
                                FROM tb_conciliacao_ofx_movimento
                                WHERE COM_CODIGO_PK = ? LIMIT 1");
        $stMov->execute([$movFk]);
        $mov = $stMov->fetch(PDO::FETCH_ASSOC);
        if (!$mov) json_out(['ok' => false, 'msg' => 'Movimento não encontrado.'], 404);
        if (strtoupper((string)$mov['COM_CONCILIADO']) === 'SIM') {
            json_out(['ok' => false, 'msg' => 'Movimento já está conciliado.'], 409);
        }

        $valorMov = abs((float)$mov['COM_VALOR']);
        $bancoFk  = (int)$mov['COM_BANCO_FK'];
        $dataMov  = (string)$mov['COM_DATA_MOVIMENTO'];

        // Soma das alocações pode ser MENOR que o movimento (conciliação parcial),
        // mas NÃO pode ser maior (não dá pra super-alocar dinheiro que não tem).
        $somaAlocada = 0.0;
        foreach ($itens as $it) {
            $somaAlocada += abs((float)($it['valor_alocado'] ?? 0));
        }
        if ($somaAlocada > $valorMov + 0.005) {
            json_out([
                'ok'  => false,
                'msg' => sprintf(
                    'Soma das alocações (R$ %s) é MAIOR que o valor do movimento (R$ %s). Reduza as alocações.',
                    number_format($somaAlocada, 2, ',', '.'),
                    number_format($valorMov, 2, ',', '.')
                ),
            ], 422);
        }

        $usuario = (string)($_SESSION['user_nome'] ?? $_SESSION['usuarioSession'] ?? 'Sistema');

        $pdo->beginTransaction();
        try {
            $stIns = $pdo->prepare("
                INSERT INTO tb_conciliacao_vinculo
                    (VIN_OFX_MOVIMENTO_FK, VIN_LANCAMENTO_TIPO, VIN_LANCAMENTO_FK,
                     VIN_VALOR_ALOCADO, VIN_TIPO_ALOCACAO, VIN_USUARIO)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $primeiroVinculoPagar   = null;
            $primeiroVinculoReceber = null;

            foreach ($itens as $it) {
                $tipo      = strtoupper((string)($it['tipo'] ?? ''));
                $lancId    = (int)($it['lancamento_id'] ?? 0);
                $valorAloc = abs((float)($it['valor_alocado'] ?? 0));
                $tipoAloc  = strtoupper((string)($it['tipo_alocacao'] ?? 'INTEGRAL'));

                if (!in_array($tipo, ['PAGAR', 'RECEBER'], true) || $lancId <= 0 || $valorAloc <= 0) {
                    throw new Exception('Item inválido.');
                }
                if (!in_array($tipoAloc, ['INTEGRAL', 'PARCIAL'], true)) {
                    $tipoAloc = 'INTEGRAL';
                }

                $tipoSql = $tipo === 'PAGAR' ? 'CONTA_PAGAR' : 'CONTA_RECEBER';

                // Aplica a alocação primeiro — devolve o tipo real (derivado do saldo restante).
                $resultado = aplicarAlocacaoConta($pdo, $tipo, $lancId, $valorAloc, $bancoFk, $dataMov, $movFk);

                // Grava o vínculo com o tipo REAL, não o que o usuário selecionou no dropdown.
                $stIns->execute([$movFk, $tipoSql, $lancId, $valorAloc, $resultado['tipo_alocacao_real'], $usuario]);

                if ($tipo === 'PAGAR') {
                    if ($primeiroVinculoPagar === null) $primeiroVinculoPagar = $lancId;
                } else {
                    if ($primeiroVinculoReceber === null) $primeiroVinculoReceber = $lancId;
                }
            }

            $refTipo = $primeiroVinculoPagar !== null ? 'CONTA_PAGAR' : 'CONTA_RECEBER';
            $refFk   = $primeiroVinculoPagar ?? $primeiroVinculoReceber;

            // Atualiza apenas a referência (compat. com queries legadas) — o COM_STATUS/
            // COM_CONCILIADO é decidido pela função canônica, que detecta PARCIAL/SIM.
            $pdo->prepare("UPDATE tb_conciliacao_ofx_movimento
                           SET COM_REFERENCIA_TIPO = ?, COM_REFERENCIA_FK = ?
                           WHERE COM_CODIGO_PK = ?")
                ->execute([$refTipo, $refFk, $movFk]);
            recalcularStatusMovimento($pdo, $movFk);

            // Lê o estado final pra dar feedback claro no JSON
            $st = $pdo->prepare("SELECT COM_CONCILIADO FROM tb_conciliacao_ofx_movimento WHERE COM_CODIGO_PK = ?");
            $st->execute([$movFk]);
            $estadoFinal = (string)$st->fetchColumn();
            $msgFinal = count($itens) . ' vínculo(s) registrado(s)';
            if ($estadoFinal === 'PARCIAL') {
                $msgFinal .= sprintf(' — conciliação PARCIAL (R$ %s de R$ %s).',
                    number_format($somaAlocada, 2, ',', '.'),
                    number_format($valorMov, 2, ',', '.'));
            } else {
                $msgFinal .= ' e conciliação confirmada.';
            }

            $pdo->commit();
            json_out(['ok' => true, 'msg' => $msgFinal, 'estado' => $estadoFinal]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_out(['ok' => false, 'msg' => 'Falha: ' . $e->getMessage()], 422);
        }
    }

    if ($acao === 'cancelar_vinculo') {
        $vinId      = (int)($_POST['vinculo_id'] ?? 0);
        $legacyMov  = (int)($_POST['legacy_movimento_fk'] ?? 0);
        $legacyTipo = strtoupper((string)($_POST['legacy_tipo'] ?? ''));

        if ($vinId <= 0 && ($legacyMov <= 0 || !in_array($legacyTipo, ['PAGAR', 'RECEBER'], true))) {
            json_out(['ok' => false, 'msg' => 'Parâmetros inválidos.'], 422);
        }

        $usuario = (string)($_SESSION['user_nome'] ?? $_SESSION['usuarioSession'] ?? 'Sistema');

        $pdo->beginTransaction();
        try {
            if ($vinId > 0) {
                $stV = $pdo->prepare("SELECT VIN_OFX_MOVIMENTO_FK, VIN_LANCAMENTO_TIPO, VIN_LANCAMENTO_FK,
                                             VIN_VALOR_ALOCADO, VIN_STATUS
                                      FROM tb_conciliacao_vinculo WHERE VIN_CODIGO_PK = ? FOR UPDATE");
                $stV->execute([$vinId]);
                $vin = $stV->fetch(PDO::FETCH_ASSOC);
                if (!$vin) throw new Exception('Vínculo não encontrado.');
                if ($vin['VIN_STATUS'] === 'CANCELADO') {
                    $pdo->commit();
                    json_out(['ok' => true, 'msg' => 'Vínculo já estava cancelado.']);
                }

                $movFk  = (int)$vin['VIN_OFX_MOVIMENTO_FK'];
                $tipo   = $vin['VIN_LANCAMENTO_TIPO'] === 'CONTA_PAGAR' ? 'PAGAR' : 'RECEBER';
                $lancId = (int)$vin['VIN_LANCAMENTO_FK'];
                $valor  = (float)$vin['VIN_VALOR_ALOCADO'];

                $pdo->prepare("UPDATE tb_conciliacao_vinculo
                               SET VIN_STATUS = 'CANCELADO', VIN_CANCELADO_EM = NOW(), VIN_CANCELADO_POR = ?
                               WHERE VIN_CODIGO_PK = ?")
                    ->execute([$usuario, $vinId]);

                reverterAlocacaoConta($pdo, $tipo, $lancId, $valor);
                recalcularStatusMovimento($pdo, $movFk);
            } else {
                $stMov = $pdo->prepare("SELECT COM_VALOR FROM tb_conciliacao_ofx_movimento
                                        WHERE COM_CODIGO_PK = ? FOR UPDATE");
                $stMov->execute([$legacyMov]);
                $mov = $stMov->fetch(PDO::FETCH_ASSOC);
                if (!$mov) throw new Exception('Movimento legado não encontrado.');
                $valor = abs((float)$mov['COM_VALOR']);

                if ($legacyTipo === 'PAGAR') {
                    $stCp = $pdo->prepare("SELECT CPG_CODIGO_PK FROM tb_contas_pagar
                                           WHERE CPG_OFX_MOVIMENTO_FK = ? LIMIT 1");
                    $stCp->execute([$legacyMov]);
                    $lancId = (int)$stCp->fetchColumn();
                    if ($lancId <= 0) throw new Exception('Conta a pagar legada não encontrada.');
                    reverterAlocacaoConta($pdo, 'PAGAR', $lancId, $valor);
                    $pdo->prepare("UPDATE tb_contas_pagar SET CPG_OFX_MOVIMENTO_FK = NULL
                                   WHERE CPG_CODIGO_PK = ?")->execute([$lancId]);
                } else {
                    $stCr = $pdo->prepare("SELECT CRE_ID FROM tb_contas_receber
                                           WHERE CRE_OFX_MOVIMENTO_FK = ? LIMIT 1");
                    $stCr->execute([$legacyMov]);
                    $lancId = (int)$stCr->fetchColumn();
                    if ($lancId <= 0) throw new Exception('Conta a receber legada não encontrada.');
                    reverterAlocacaoConta($pdo, 'RECEBER', $lancId, $valor);
                    $pdo->prepare("UPDATE tb_contas_receber SET CRE_OFX_MOVIMENTO_FK = NULL
                                   WHERE CRE_ID = ?")->execute([$lancId]);
                }

                recalcularStatusMovimento($pdo, $legacyMov);
            }

            $pdo->commit();
            json_out(['ok' => true, 'msg' => 'Vínculo cancelado.']);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_out(['ok' => false, 'msg' => 'Falha ao cancelar: ' . $e->getMessage()], 422);
        }
    }

    if ($acao === 'cancelar_integracao') {
        $movFk = (int)($_POST['movimento_fk'] ?? 0);
        if ($movFk <= 0) json_out(['ok' => false, 'msg' => 'Movimento inválido.'], 422);

        $usuario = (string)($_SESSION['user_nome'] ?? $_SESSION['usuarioSession'] ?? 'Sistema');

        $pdo->beginTransaction();
        try {
            $stV = $pdo->prepare("SELECT VIN_CODIGO_PK, VIN_LANCAMENTO_TIPO, VIN_LANCAMENTO_FK, VIN_VALOR_ALOCADO
                                  FROM tb_conciliacao_vinculo
                                  WHERE VIN_OFX_MOVIMENTO_FK = ? AND VIN_STATUS = 'ATIVO' FOR UPDATE");
            $stV->execute([$movFk]);
            $vinculos = $stV->fetchAll(PDO::FETCH_ASSOC);

            foreach ($vinculos as $v) {
                $pdo->prepare("UPDATE tb_conciliacao_vinculo
                               SET VIN_STATUS = 'CANCELADO', VIN_CANCELADO_EM = NOW(), VIN_CANCELADO_POR = ?
                               WHERE VIN_CODIGO_PK = ?")
                    ->execute([$usuario, (int)$v['VIN_CODIGO_PK']]);

                $tipo = $v['VIN_LANCAMENTO_TIPO'] === 'CONTA_PAGAR' ? 'PAGAR' : 'RECEBER';
                reverterAlocacaoConta($pdo, $tipo, (int)$v['VIN_LANCAMENTO_FK'], (float)$v['VIN_VALOR_ALOCADO']);
            }

            $stCp = $pdo->prepare("SELECT CPG_CODIGO_PK, CPG_VALOR_PAGO, CPG_VALOR_PARCELA
                                   FROM tb_contas_pagar WHERE CPG_OFX_MOVIMENTO_FK = ? FOR UPDATE");
            $stCp->execute([$movFk]);
            foreach ($stCp->fetchAll(PDO::FETCH_ASSOC) as $cp) {
                $valor = (float)($cp['CPG_VALOR_PAGO'] ?: $cp['CPG_VALOR_PARCELA']);
                reverterAlocacaoConta($pdo, 'PAGAR', (int)$cp['CPG_CODIGO_PK'], $valor);
                $pdo->prepare("UPDATE tb_contas_pagar SET CPG_OFX_MOVIMENTO_FK = NULL
                               WHERE CPG_CODIGO_PK = ?")->execute([(int)$cp['CPG_CODIGO_PK']]);
            }

            $stCr = $pdo->prepare("SELECT CRE_ID, CRE_VALOR_RECEBIDO, CRE_VALOR
                                   FROM tb_contas_receber WHERE CRE_OFX_MOVIMENTO_FK = ? FOR UPDATE");
            $stCr->execute([$movFk]);
            foreach ($stCr->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                $valor = (float)($cr['CRE_VALOR_RECEBIDO'] ?: $cr['CRE_VALOR']);
                reverterAlocacaoConta($pdo, 'RECEBER', (int)$cr['CRE_ID'], $valor);
                $pdo->prepare("UPDATE tb_contas_receber SET CRE_OFX_MOVIMENTO_FK = NULL
                               WHERE CRE_ID = ?")->execute([(int)$cr['CRE_ID']]);
            }

            recalcularStatusMovimento($pdo, $movFk);

            $pdo->commit();
            json_out(['ok' => true, 'msg' => 'Integração cancelada. Movimento voltou para Importado.']);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_out(['ok' => false, 'msg' => 'Falha: ' . $e->getMessage()], 422);
        }
    }

    if ($acao === 'resumo_importacoes_banco') {
        $bancoFk = (int)($_GET['banco_fk'] ?? 0);
        if ($bancoFk <= 0) json_out(['ok' => false, 'msg' => 'Banco inválido.'], 422);

        $st = $pdo->prepare("
            SELECT
                i.COI_CODIGO_PK AS imp_id,
                i.COI_NOME_ARQUIVO AS arquivo,
                i.COI_DATA_INICIAL AS data_ini,
                i.COI_DATA_FINAL   AS data_fim,
                i.COI_SALDO_FINAL  AS saldo_final,
                i.COI_DATA_CADASTRO AS data_cadastro,
                COUNT(m.COM_CODIGO_PK) AS qtd_total,
                SUM(CASE WHEN m.COM_CONCILIADO = 'SIM' THEN 1 ELSE 0 END) AS qtd_conciliados,
                SUM(CASE WHEN COALESCE(m.COM_CONCILIADO,'NAO') <> 'SIM' AND m.COM_TIPO = 'DEBITO' THEN 1 ELSE 0 END) AS qtd_debitos_pendentes,
                SUM(CASE WHEN COALESCE(m.COM_CONCILIADO,'NAO') <> 'SIM' AND m.COM_TIPO = 'CREDITO' THEN 1 ELSE 0 END) AS qtd_creditos_pendentes
            FROM tb_conciliacao_ofx_importacao i
            LEFT JOIN tb_conciliacao_ofx_movimento m ON m.COM_IMPORTACAO_FK = i.COI_CODIGO_PK
            WHERE i.COI_BANCO_FK = ?
            GROUP BY i.COI_CODIGO_PK
            ORDER BY i.COI_CODIGO_PK DESC
        ");
        $st->execute([$bancoFk]);
        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'listar_vinculos_ativos_banco') {
        $bancoFk = (int)($_GET['banco_fk'] ?? 0);
        if ($bancoFk <= 0) json_out(['ok' => false, 'msg' => 'Banco inválido.'], 422);

        $sqlNovo = "
            SELECT
                v.VIN_CODIGO_PK AS vin_id,
                v.VIN_OFX_MOVIMENTO_FK AS mov_fk,
                v.VIN_LANCAMENTO_TIPO AS tipo,
                v.VIN_LANCAMENTO_FK AS lanc_fk,
                v.VIN_VALOR_ALOCADO AS valor_alocado,
                v.VIN_TIPO_ALOCACAO AS tipo_alocacao,
                v.VIN_DATA_VINCULACAO AS data_vinc,
                v.VIN_USUARIO AS usuario,
                'NOVO' AS origem,
                m.COM_DATA_MOVIMENTO AS mov_data,
                m.COM_VALOR AS mov_valor,
                m.COM_DESCRICAO AS mov_descricao
            FROM tb_conciliacao_vinculo v
            INNER JOIN tb_conciliacao_ofx_movimento m ON m.COM_CODIGO_PK = v.VIN_OFX_MOVIMENTO_FK
            WHERE v.VIN_STATUS = 'ATIVO'
              AND m.COM_BANCO_FK = ?
        ";

        $sqlLegPagar = "
            SELECT
                NULL AS vin_id,
                cp.CPG_OFX_MOVIMENTO_FK AS mov_fk,
                'CONTA_PAGAR' AS tipo,
                cp.CPG_CODIGO_PK AS lanc_fk,
                COALESCE(cp.CPG_VALOR_PAGO, cp.CPG_VALOR_PARCELA) AS valor_alocado,
                'INTEGRAL' AS tipo_alocacao,
                cp.CPG_DATA_PAGAMENTO AS data_vinc,
                NULL AS usuario,
                'LEGADO' AS origem,
                m.COM_DATA_MOVIMENTO AS mov_data,
                m.COM_VALOR AS mov_valor,
                m.COM_DESCRICAO AS mov_descricao
            FROM tb_contas_pagar cp
            INNER JOIN tb_conciliacao_ofx_movimento m ON m.COM_CODIGO_PK = cp.CPG_OFX_MOVIMENTO_FK
            WHERE cp.CPG_OFX_MOVIMENTO_FK IS NOT NULL
              AND m.COM_BANCO_FK = ?
              AND NOT EXISTS (
                  SELECT 1 FROM tb_conciliacao_vinculo v2
                  WHERE v2.VIN_OFX_MOVIMENTO_FK = m.COM_CODIGO_PK AND v2.VIN_STATUS = 'ATIVO'
              )
        ";

        $sqlLegRec = "
            SELECT
                NULL AS vin_id,
                cr.CRE_OFX_MOVIMENTO_FK AS mov_fk,
                'CONTA_RECEBER' AS tipo,
                cr.CRE_ID AS lanc_fk,
                COALESCE(cr.CRE_VALOR_RECEBIDO, cr.CRE_VALOR) AS valor_alocado,
                'INTEGRAL' AS tipo_alocacao,
                cr.CRE_RECEBIDO_EM AS data_vinc,
                NULL AS usuario,
                'LEGADO' AS origem,
                m.COM_DATA_MOVIMENTO AS mov_data,
                m.COM_VALOR AS mov_valor,
                m.COM_DESCRICAO AS mov_descricao
            FROM tb_contas_receber cr
            INNER JOIN tb_conciliacao_ofx_movimento m ON m.COM_CODIGO_PK = cr.CRE_OFX_MOVIMENTO_FK
            WHERE cr.CRE_OFX_MOVIMENTO_FK IS NOT NULL
              AND m.COM_BANCO_FK = ?
              AND NOT EXISTS (
                  SELECT 1 FROM tb_conciliacao_vinculo v2
                  WHERE v2.VIN_OFX_MOVIMENTO_FK = m.COM_CODIGO_PK AND v2.VIN_STATUS = 'ATIVO'
              )
        ";

        $rows = [];
        $stCpg = $pdo->prepare("SELECT CPG_DESCRICAO AS descricao, CPG_VALOR_PARCELA AS valor,
                                       CPG_NUM_PARCELA AS num_parcela, CPG_QTD_PARCELAS AS qtd_parcelas
                                FROM tb_contas_pagar WHERE CPG_CODIGO_PK = ?");
        $stCre = $pdo->prepare("SELECT TRIM(CONCAT_WS(' · ',
                                          NULLIF(cr.CRE_CLIENTE_NOME,''),
                                          CASE WHEN cr.CRE_DOCUMENTO IS NOT NULL AND cr.CRE_DOCUMENTO <> '' THEN CONCAT('doc ', cr.CRE_DOCUMENTO) END
                                       )) AS descricao,
                                       cr.CRE_VALOR AS valor,
                                       cpa.CPA_NUM AS num_parcela,
                                       cpa.CPA_TOTAL AS qtd_parcelas
                                FROM tb_contas_receber cr
                                LEFT JOIN contrato_parcelas cpa
                                    ON cpa.CPA_CTR_ID = cr.CRE_CONTRATO_FK
                                   AND cpa.CPA_VENCIMENTO = cr.CRE_VENCIMENTO
                                WHERE cr.CRE_ID = ?");

        foreach ([$sqlNovo, $sqlLegPagar, $sqlLegRec] as $sql) {
            $st = $pdo->prepare($sql);
            $st->execute([$bancoFk]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $stD = $r['tipo'] === 'CONTA_PAGAR' ? $stCpg : $stCre;
                $stD->execute([$r['lanc_fk']]);
                $info = $stD->fetch(PDO::FETCH_ASSOC) ?: ['descricao' => '', 'valor' => 0];
                $r['lanc_descricao'] = (string)($info['descricao'] ?? '');
                $r['lanc_valor']     = (float)($info['valor'] ?? 0);
                $r['num_parcela']    = $info['num_parcela'] ?? null;
                $r['qtd_parcelas']   = $info['qtd_parcelas'] ?? null;
                $rows[] = $r;
            }
        }

        usort($rows, fn($a, $b) => strcmp((string)($b['data_vinc'] ?? ''), (string)($a['data_vinc'] ?? '')));

        json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'obter_alocacoes_movimento') {
        $movFk = (int)($_REQUEST['movimento_fk'] ?? 0);
        if ($movFk <= 0) json_out(['ok' => false, 'msg' => 'Inválido.'], 422);

        $vinculos = listarVinculosMovimento($pdo, $movFk);
        $somaAloc = 0.0;
        foreach ($vinculos as $v) $somaAloc += (float)$v['valor_alocado'];

        json_out(['ok' => true, 'vinculos' => $vinculos, 'soma_alocada' => $somaAloc]);
    }

    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out([
        'ok' => false,
        'msg' => 'Erro no banco.',
        'detail' => $e->getMessage(),
    ], 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out([
        'ok' => false,
        'msg' => 'Erro interno.',
        'detail' => $e->getMessage(),
    ], 500);
}
