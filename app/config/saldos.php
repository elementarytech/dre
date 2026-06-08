<?php
// /app/config/saldos.php
// Funções centrais de cálculo de saldo bancário e ERP por conta.
// FONTE DA VERDADE: a tela de Conciliação Bancária. O Fluxo de Caixa também
// consome este arquivo para garantir que ambas as telas mostrem o mesmo saldo.

declare(strict_types=1);

require_once __DIR__ . '/status_dict.php';

if (!function_exists('saldoBancarioOfx')) {
    /**
     * Saldo bancário (lado banco): último OFX importado, ou último movimento.
     * Se houver SET ATIVO em tb_conciliacao_ajuste_saldo (CAMPO=SALDO_BANCARIO),
     * ele funciona como BASELINE na sua data: tudo OFX posterior é acumulado por cima.
     */
    function saldoBancarioOfx(PDO $pdo, int $bancoFk, string $contaRef): float
    {
        // Cache por request: evita re-executar 4 queries quando a mesma página
        // chama a função várias vezes para o mesmo banco (ex: Conciliação + Diagnóstico).
        static $cache = [];
        $key = $bancoFk . '|' . $contaRef;
        if (array_key_exists($key, $cache)) return $cache[$key];

        // 1) Buscar SET de SALDO_BANCARIO (baseline + data de corte)
        $stSetB = $pdo->prepare("
            SELECT CAS_SALDO_NOVO, CAS_DATA
            FROM tb_conciliacao_ajuste_saldo
            WHERE CAS_BANCO_FK = :banco_fk
              AND CAS_CONTA_REF = :conta_ref
              AND CAS_CAMPO_AJUSTADO = 'SALDO_BANCARIO'
              AND CAS_OPERACAO = 'SET'
              AND CAS_STATUS = 'ATIVO'
            ORDER BY CAS_DATA DESC, CAS_CODIGO_PK DESC
            LIMIT 1
        ");
        $stSetB->execute([':banco_fk' => $bancoFk, ':conta_ref' => $contaRef]);
        $setRow = $stSetB->fetch(PDO::FETCH_ASSOC);

        if ($setRow) {
            $baseline  = (float) $setRow['CAS_SALDO_NOVO'];
            $dataCorte = (string) $setRow['CAS_DATA'];

            $st = $pdo->prepare("
                SELECT COALESCE(SUM(COM_VALOR), 0)
                FROM tb_conciliacao_ofx_movimento
                WHERE COM_BANCO_FK = :banco_fk
                  AND COM_CONTA_REF = :conta_ref
                  AND COM_DATA_MOVIMENTO > :data_corte
            ");
            $st->execute([
                ':banco_fk'   => $bancoFk,
                ':conta_ref'  => $contaRef,
                ':data_corte' => $dataCorte,
            ]);
            return $cache[$key] = $baseline + (float) $st->fetchColumn();
        }

        // 2) Sem SET: saldo final do último OFX importado
        $st = $pdo->prepare("
            SELECT COI_SALDO_FINAL
            FROM tb_conciliacao_ofx_importacao
            WHERE COI_BANCO_FK = :banco_fk
              AND COI_CONTA_REF = :conta_ref
              AND COI_SALDO_FINAL IS NOT NULL
            ORDER BY COI_CODIGO_PK DESC
            LIMIT 1
        ");
        $st->execute([':banco_fk' => $bancoFk, ':conta_ref' => $contaRef]);
        $v = $st->fetchColumn();
        if ($v !== false) {
            return $cache[$key] = (float)$v;
        }

        // 3) Fallback: saldo do último movimento OFX
        $st = $pdo->prepare("
            SELECT COM_SALDO_APOS
            FROM tb_conciliacao_ofx_movimento
            WHERE COM_BANCO_FK = :banco_fk
              AND COM_CONTA_REF = :conta_ref
            ORDER BY COM_DATA_MOVIMENTO DESC, COM_CODIGO_PK DESC
            LIMIT 1
        ");
        $st->execute([':banco_fk' => $bancoFk, ':conta_ref' => $contaRef]);
        $v = $st->fetchColumn();
        return $cache[$key] = ($v !== false ? (float)$v : 0.00);
    }
}

if (!function_exists('saldoErpConta')) {
    /**
     * Saldo ERP (lado sistema): soma contas a receber com status RECEBIDO/PAGO,
     * subtrai contas a pagar com status PAGO, e aplica os ajustes manuais ATIVOS.
     * REGRA: só entra no cálculo o que estiver com o status correto. Valor pago/recebido
     * preenchido sem o status correto NÃO entra (decisão validada com o cliente).
     */
    function saldoErpConta(PDO $pdo, int $bancoFk, string $contaRef): float
    {
        // Cache por request: a Conciliação chama 1x por banco; o Diagnóstico também.
        // Sem cache, chamadas duplicadas no mesmo render disparam 4 queries cada.
        static $cache = [];
        $key = $bancoFk . '|' . $contaRef;
        if (array_key_exists($key, $cache)) return $cache[$key];

        $phCre = sql_placeholders(CRE_STATUS_PAGO); // ('RECEBIDO','PAGO')
        $phCpg = sql_placeholders(CPG_STATUS_PAGO); // ('PAGO')

        // 1) SET ATIVO mais recente: define o saldo na data D (baseline).
        //    Sem SET, parte de 0 e soma toda a história.
        $stSet = $pdo->prepare("
            SELECT CAS_SALDO_NOVO, CAS_DATA
            FROM tb_conciliacao_ajuste_saldo
            WHERE CAS_BANCO_FK = :banco_fk
              AND CAS_CONTA_REF = :conta_ref
              AND CAS_CAMPO_AJUSTADO = 'SALDO_ERP'
              AND CAS_OPERACAO = 'SET'
              AND CAS_STATUS = 'ATIVO'
            ORDER BY CAS_DATA DESC, CAS_CODIGO_PK DESC
            LIMIT 1
        ");
        $stSet->execute([':banco_fk' => $bancoFk, ':conta_ref' => $contaRef]);
        $setRow = $stSet->fetch(PDO::FETCH_ASSOC);

        $baseline  = $setRow ? (float) $setRow['CAS_SALDO_NOVO'] : 0.0;
        $dataCorte = $setRow ? (string) $setRow['CAS_DATA']      : '0000-00-00';

        // 2) Recebimentos POSTERIORES à data de corte
        $sqlR = "
            SELECT COALESCE(SUM(COALESCE(NULLIF(CRE_VALOR_RECEBIDO,0), CRE_VALOR)), 0)
            FROM tb_contas_receber
            WHERE CRE_BANCO_FK = ?
              AND CRE_STATUS IN ({$phCre})
              AND CRE_RECEBIDO_EM > ?
        ";
        $stR = $pdo->prepare($sqlR);
        $stR->execute(array_merge([$bancoFk], CRE_STATUS_PAGO, [$dataCorte]));
        $saldoReceber = (float) $stR->fetchColumn();

        // 3) Pagamentos POSTERIORES à data de corte
        $sqlP = "
            SELECT COALESCE(SUM(COALESCE(CPG_VALOR_PAGO, CPG_VALOR_PARCELA)), 0)
            FROM tb_contas_pagar
            WHERE CPG_BANCO_PAGAMENTO_FK = ?
              AND CPG_STATUS IN ({$phCpg})
              AND CPG_DATA_PAGAMENTO > ?
        ";
        $stP = $pdo->prepare($sqlP);
        $stP->execute(array_merge([$bancoFk], CPG_STATUS_PAGO, [$dataCorte]));
        $saldoPagar = (float) $stP->fetchColumn();

        // 4) Ajustes SOMA/SUB ATIVOS posteriores ao SET
        $stA = $pdo->prepare("
            SELECT COALESCE(SUM(
                CASE
                    WHEN CAS_OPERACAO = 'SOMA' THEN CAS_VALOR
                    WHEN CAS_OPERACAO = 'SUB'  THEN -CAS_VALOR
                    ELSE 0
                END
            ), 0)
            FROM tb_conciliacao_ajuste_saldo
            WHERE CAS_BANCO_FK = :banco_fk
              AND CAS_CONTA_REF = :conta_ref
              AND CAS_CAMPO_AJUSTADO = 'SALDO_ERP'
              AND CAS_OPERACAO IN ('SOMA','SUB')
              AND CAS_STATUS = 'ATIVO'
              AND CAS_DATA > :data_corte
        ");
        $stA->execute([
            ':banco_fk'   => $bancoFk,
            ':conta_ref'  => $contaRef,
            ':data_corte' => $dataCorte,
        ]);
        $ajustes = (float) $stA->fetchColumn();

        return $cache[$key] = $baseline + $saldoReceber - $saldoPagar + $ajustes;
    }
}

if (!function_exists('contaRefBanco')) {
    function contaRefBanco(array $banco): string
    {
        return trim((string)($banco['BAN_AGENCIA'] ?? '')) . '/' . trim((string)($banco['BAN_CONTA'] ?? ''));
    }
}
