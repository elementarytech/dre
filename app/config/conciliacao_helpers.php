<?php

declare(strict_types=1);

if (!function_exists('obterDescricaoLancamento')) {
    function obterDescricaoLancamento(PDO $pdo, string $tipoSql, int $id): array
    {
        if ($tipoSql === 'CONTA_PAGAR') {
            $st = $pdo->prepare("SELECT CPG_DESCRICAO AS descricao, CPG_VALOR_PARCELA AS valor,
                                        CPG_NUM_PARCELA AS num_parcela, CPG_QTD_PARCELAS AS qtd_parcelas
                                 FROM tb_contas_pagar WHERE CPG_CODIGO_PK = ? LIMIT 1");
        } else {
            $st = $pdo->prepare("SELECT TRIM(CONCAT_WS(' · ',
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
                                 WHERE cr.CRE_ID = ? LIMIT 1");
        }
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['descricao' => '', 'valor' => 0, 'num_parcela' => null, 'qtd_parcelas' => null];
    }
}

if (!function_exists('listarVinculosMovimento')) {
    function listarVinculosMovimento(PDO $pdo, int $movFk): array
    {
        $st = $pdo->prepare("
            SELECT VIN_CODIGO_PK, VIN_LANCAMENTO_TIPO, VIN_LANCAMENTO_FK,
                   VIN_VALOR_ALOCADO, VIN_TIPO_ALOCACAO
            FROM tb_conciliacao_vinculo
            WHERE VIN_OFX_MOVIMENTO_FK = :mov AND VIN_STATUS = 'ATIVO'
            ORDER BY VIN_CODIGO_PK ASC
        ");
        $st->execute([':mov' => $movFk]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $out = [];
            foreach ($rows as $r) {
                $info = obterDescricaoLancamento($pdo, (string)$r['VIN_LANCAMENTO_TIPO'], (int)$r['VIN_LANCAMENTO_FK']);
                $out[] = [
                    'vin_id'         => (int)$r['VIN_CODIGO_PK'],
                    'tipo'           => $r['VIN_LANCAMENTO_TIPO'] === 'CONTA_PAGAR' ? 'PAGAR' : 'RECEBER',
                    'lancamento_id'  => (int)$r['VIN_LANCAMENTO_FK'],
                    'valor_alocado'  => (float)$r['VIN_VALOR_ALOCADO'],
                    'tipo_alocacao'  => (string)$r['VIN_TIPO_ALOCACAO'],
                    'origem'         => 'NOVO',
                    'lancamento_descricao' => (string)($info['descricao'] ?? ''),
                    'lancamento_valor'     => (float)($info['valor'] ?? 0),
                    'num_parcela'          => $info['num_parcela'] ?? null,
                    'qtd_parcelas'         => $info['qtd_parcelas'] ?? null,
                ];
            }
            return $out;
        }

        // Fallback legado 1:1 — contas a pagar
        $stCpg = $pdo->prepare("SELECT CPG_CODIGO_PK, CPG_DESCRICAO, CPG_VALOR_PARCELA, CPG_VALOR_PAGO,
                                       CPG_NUM_PARCELA, CPG_QTD_PARCELAS
                                FROM tb_contas_pagar WHERE CPG_OFX_MOVIMENTO_FK = ? LIMIT 1");
        $stCpg->execute([$movFk]);
        if ($cpg = $stCpg->fetch(PDO::FETCH_ASSOC)) {
            return [[
                'vin_id'         => null,
                'tipo'           => 'PAGAR',
                'lancamento_id'  => (int)$cpg['CPG_CODIGO_PK'],
                'valor_alocado'  => (float)($cpg['CPG_VALOR_PAGO'] ?: $cpg['CPG_VALOR_PARCELA']),
                'tipo_alocacao'  => 'INTEGRAL',
                'origem'         => 'LEGADO',
                'lancamento_descricao' => (string)$cpg['CPG_DESCRICAO'],
                'lancamento_valor'     => (float)$cpg['CPG_VALOR_PARCELA'],
                'num_parcela'          => $cpg['CPG_NUM_PARCELA'] ?? null,
                'qtd_parcelas'         => $cpg['CPG_QTD_PARCELAS'] ?? null,
            ]];
        }

        // Fallback legado 1:1 — contas a receber
        $stCre = $pdo->prepare("SELECT cr.CRE_ID,
                                       TRIM(CONCAT_WS(' · ',
                                           NULLIF(cr.CRE_CLIENTE_NOME,''),
                                           CASE WHEN cr.CRE_DOCUMENTO IS NOT NULL AND cr.CRE_DOCUMENTO <> '' THEN CONCAT('doc ', cr.CRE_DOCUMENTO) END
                                       )) AS descricao,
                                       cr.CRE_VALOR, cr.CRE_VALOR_RECEBIDO,
                                       cpa.CPA_NUM AS num_parcela,
                                       cpa.CPA_TOTAL AS qtd_parcelas
                                FROM tb_contas_receber cr
                                LEFT JOIN contrato_parcelas cpa
                                    ON cpa.CPA_CTR_ID = cr.CRE_CONTRATO_FK
                                   AND cpa.CPA_VENCIMENTO = cr.CRE_VENCIMENTO
                                WHERE cr.CRE_OFX_MOVIMENTO_FK = ? LIMIT 1");
        $stCre->execute([$movFk]);
        if ($cre = $stCre->fetch(PDO::FETCH_ASSOC)) {
            return [[
                'vin_id'         => null,
                'tipo'           => 'RECEBER',
                'lancamento_id'  => (int)$cre['CRE_ID'],
                'valor_alocado'  => (float)($cre['CRE_VALOR_RECEBIDO'] ?: $cre['CRE_VALOR']),
                'tipo_alocacao'  => 'INTEGRAL',
                'origem'         => 'LEGADO',
                'lancamento_descricao' => (string)$cre['descricao'],
                'lancamento_valor'     => (float)$cre['CRE_VALOR'],
                'num_parcela'          => $cre['num_parcela'] ?? null,
                'qtd_parcelas'         => $cre['qtd_parcelas'] ?? null,
            ]];
        }

        return [];
    }
}

if (!function_exists('aplicarAlocacaoConta')) {
    function aplicarAlocacaoConta(PDO $pdo, string $tipo, int $lancId, float $valorAloc,
                                  int $bancoFk, string $dataMov, int $movFk): array
    {
        if ($tipo === 'PAGAR') {
            $st = $pdo->prepare("SELECT CPG_VALOR_PARCELA, CPG_VALOR_PAGO, CPG_STATUS, CPG_OFX_MOVIMENTO_FK
                                 FROM tb_contas_pagar WHERE CPG_CODIGO_PK = ? FOR UPDATE");
            $st->execute([$lancId]);
            $cp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cp) throw new Exception("Conta a pagar #{$lancId} não encontrada.");
            if (strtoupper((string)$cp['CPG_STATUS']) === 'CANCELADO') {
                throw new Exception("Conta a pagar #{$lancId} está cancelada.");
            }

            $valorTotal     = (float)$cp['CPG_VALOR_PARCELA'];
            $valorPagoAtual = (float)($cp['CPG_VALOR_PAGO'] ?? 0);
            $saldoRestante  = max(0.0, $valorTotal - $valorPagoAtual);
            $jaQuitada      = (strtoupper((string)$cp['CPG_STATUS']) === 'PAGO')
                              && (abs($valorPagoAtual - $valorTotal) < 0.01);

            // Caso 1: já paga manualmente — só registra o vínculo OFX, não duplica baixa.
            if ($jaQuitada) {
                if (abs($valorAloc - $valorTotal) > 0.01) {
                    throw new Exception(sprintf(
                        'Conta a pagar #%d já está paga (R$ %s). Valor alocado deve ser o valor total da conta.',
                        $lancId, number_format($valorTotal, 2, ',', '.')
                    ));
                }
                if (empty($cp['CPG_OFX_MOVIMENTO_FK'])) {
                    $pdo->prepare("UPDATE tb_contas_pagar SET CPG_OFX_MOVIMENTO_FK = ? WHERE CPG_CODIGO_PK = ?")
                        ->execute([$movFk, $lancId]);
                }
                return ['tipo_alocacao_real' => 'INTEGRAL', 'novo_status' => 'PAGO', 'modo' => 'JA_PAGA_VINCULO'];
            }

            // Caso 2: em aberto/parcial — alocação normal.
            if ($valorAloc <= 0) throw new Exception("Valor de alocação deve ser > 0 para conta a pagar #{$lancId}.");
            if ($valorAloc > $saldoRestante + 0.01) {
                throw new Exception(sprintf(
                    'Alocação excede saldo restante da conta a pagar #%d (saldo: R$ %s, alocado: R$ %s).',
                    $lancId,
                    number_format($saldoRestante, 2, ',', '.'),
                    number_format($valorAloc, 2, ',', '.')
                ));
            }

            $novoValorPago = $valorPagoAtual + $valorAloc;
            $quitou        = ($novoValorPago + 0.005 >= $valorTotal);
            $novoStatus    = $quitou ? 'PAGO' : (string)$cp['CPG_STATUS'];
            $tipoAlocReal  = (abs($valorAloc - $saldoRestante) < 0.01) ? 'INTEGRAL' : 'PARCIAL';
            $cacheFk       = $cp['CPG_OFX_MOVIMENTO_FK'] ?: $movFk;

            $pdo->prepare("UPDATE tb_contas_pagar
                           SET CPG_VALOR_PAGO = ?,
                               CPG_DATA_PAGAMENTO = COALESCE(CPG_DATA_PAGAMENTO, ?),
                               CPG_BANCO_PAGAMENTO_FK = COALESCE(CPG_BANCO_PAGAMENTO_FK, ?),
                               CPG_OFX_MOVIMENTO_FK = ?,
                               CPG_STATUS = ?,
                               CPG_PAGO = ?,
                               CPG_AUTORIZACAO_STATUS = COALESCE(CPG_AUTORIZACAO_STATUS, 'AUTORIZADO')
                           WHERE CPG_CODIGO_PK = ?")
                ->execute([$novoValorPago, $dataMov, $bancoFk, $cacheFk,
                           $novoStatus, $quitou ? 'SIM' : 'NAO', $lancId]);

            return ['tipo_alocacao_real' => $tipoAlocReal, 'novo_status' => $novoStatus, 'modo' => 'INCREMENTO'];
        }

        // CONTA_RECEBER
        $st = $pdo->prepare("SELECT CRE_VALOR, CRE_VALOR_RECEBIDO, CRE_STATUS, CRE_OFX_MOVIMENTO_FK
                             FROM tb_contas_receber WHERE CRE_ID = ? FOR UPDATE");
        $st->execute([$lancId]);
        $cr = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cr) throw new Exception("Conta a receber #{$lancId} não encontrada.");
        if (strtoupper((string)$cr['CRE_STATUS']) === 'CANCELADO') {
            throw new Exception("Conta a receber #{$lancId} está cancelada.");
        }

        $valorTotal    = (float)$cr['CRE_VALOR'];
        $valorRecAtual = (float)($cr['CRE_VALOR_RECEBIDO'] ?? 0);
        $saldoRestante = max(0.0, $valorTotal - $valorRecAtual);
        $jaQuitada     = in_array(strtoupper((string)$cr['CRE_STATUS']), ['RECEBIDO', 'PAGO'], true)
                         && (abs($valorRecAtual - $valorTotal) < 0.01);

        if ($jaQuitada) {
            if (abs($valorAloc - $valorTotal) > 0.01) {
                throw new Exception(sprintf(
                    'Conta a receber #%d já está recebida (R$ %s). Valor alocado deve ser o valor total da conta.',
                    $lancId, number_format($valorTotal, 2, ',', '.')
                ));
            }
            if (empty($cr['CRE_OFX_MOVIMENTO_FK'])) {
                $pdo->prepare("UPDATE tb_contas_receber SET CRE_OFX_MOVIMENTO_FK = ? WHERE CRE_ID = ?")
                    ->execute([$movFk, $lancId]);
            }
            return ['tipo_alocacao_real' => 'INTEGRAL', 'novo_status' => 'RECEBIDO', 'modo' => 'JA_PAGA_VINCULO'];
        }

        if ($valorAloc <= 0) throw new Exception("Valor de alocação deve ser > 0 para conta a receber #{$lancId}.");
        if ($valorAloc > $saldoRestante + 0.01) {
            throw new Exception(sprintf(
                'Alocação excede saldo restante da conta a receber #%d (saldo: R$ %s, alocado: R$ %s).',
                $lancId,
                number_format($saldoRestante, 2, ',', '.'),
                number_format($valorAloc, 2, ',', '.')
            ));
        }

        $novoValorRec = $valorRecAtual + $valorAloc;
        $quitou       = ($novoValorRec + 0.005 >= $valorTotal);
        $novoStatus   = $quitou ? 'RECEBIDO' : (string)$cr['CRE_STATUS'];
        $tipoAlocReal = (abs($valorAloc - $saldoRestante) < 0.01) ? 'INTEGRAL' : 'PARCIAL';
        $cacheFk      = $cr['CRE_OFX_MOVIMENTO_FK'] ?: $movFk;

        $pdo->prepare("UPDATE tb_contas_receber
                       SET CRE_VALOR_RECEBIDO = ?,
                           CRE_RECEBIDO_EM = COALESCE(CRE_RECEBIDO_EM, ?),
                           CRE_BANCO_FK = COALESCE(CRE_BANCO_FK, ?),
                           CRE_OFX_MOVIMENTO_FK = ?,
                           CRE_STATUS = ?
                       WHERE CRE_ID = ?")
            ->execute([$novoValorRec, $dataMov, $bancoFk, $cacheFk, $novoStatus, $lancId]);

        return ['tipo_alocacao_real' => $tipoAlocReal, 'novo_status' => $novoStatus, 'modo' => 'INCREMENTO'];
    }
}

if (!function_exists('reverterAlocacaoConta')) {
    function reverterAlocacaoConta(PDO $pdo, string $tipo, int $lancId, float $valor): void
    {
        if ($tipo === 'PAGAR') {
            $st = $pdo->prepare("SELECT CPG_VALOR_PAGO, CPG_VALOR_PARCELA, CPG_STATUS
                                 FROM tb_contas_pagar WHERE CPG_CODIGO_PK = ? FOR UPDATE");
            $st->execute([$lancId]);
            $cp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cp) return;

            $novoValorPago = max(0.0, (float)($cp['CPG_VALOR_PAGO'] ?? 0) - $valor);
            $valorParcela  = (float)$cp['CPG_VALOR_PARCELA'];
            $statusAtual   = strtoupper((string)$cp['CPG_STATUS']);

            $novoStatus = $statusAtual;
            if ($statusAtual === 'PAGO' && $novoValorPago + 0.005 < $valorParcela) {
                $novoStatus = 'ABERTO';
            }

            $zerou = $novoValorPago <= 0.005;

            $pdo->prepare("UPDATE tb_contas_pagar
                           SET CPG_VALOR_PAGO = NULLIF(?, 0),
                               CPG_PAGO = CASE WHEN ? = 'PAGO' THEN 'SIM' ELSE 'NAO' END,
                               CPG_STATUS = ?,
                               CPG_DATA_PAGAMENTO = CASE WHEN ? = 1 THEN NULL ELSE CPG_DATA_PAGAMENTO END,
                               CPG_BANCO_PAGAMENTO_FK = CASE WHEN ? = 1 THEN NULL ELSE CPG_BANCO_PAGAMENTO_FK END
                           WHERE CPG_CODIGO_PK = ?")
                ->execute([$novoValorPago, $novoStatus, $novoStatus,
                           $zerou ? 1 : 0, $zerou ? 1 : 0, $lancId]);
        } else {
            $st = $pdo->prepare("SELECT CRE_VALOR_RECEBIDO, CRE_VALOR, CRE_STATUS
                                 FROM tb_contas_receber WHERE CRE_ID = ? FOR UPDATE");
            $st->execute([$lancId]);
            $cr = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cr) return;

            $novoValorRec = max(0.0, (float)($cr['CRE_VALOR_RECEBIDO'] ?? 0) - $valor);
            $valorTotal   = (float)$cr['CRE_VALOR'];
            $statusAtual  = strtoupper((string)$cr['CRE_STATUS']);

            $novoStatus = $statusAtual;
            if (in_array($statusAtual, ['RECEBIDO','PAGO'], true) && $novoValorRec + 0.005 < $valorTotal) {
                $novoStatus = 'ABERTO';
            }

            $zerou = $novoValorRec <= 0.005;

            $pdo->prepare("UPDATE tb_contas_receber
                           SET CRE_VALOR_RECEBIDO = NULLIF(?, 0),
                               CRE_STATUS = ?,
                               CRE_RECEBIDO_EM = CASE WHEN ? = 1 THEN NULL ELSE CRE_RECEBIDO_EM END
                           WHERE CRE_ID = ?")
                ->execute([$novoValorRec, $novoStatus, $zerou ? 1 : 0, $lancId]);
        }
    }
}

if (!function_exists('recalcularStatusMovimento')) {
    function recalcularStatusMovimento(PDO $pdo, int $movFk): void
    {
        $stV = $pdo->prepare("SELECT COUNT(*) FROM tb_conciliacao_vinculo
                              WHERE VIN_OFX_MOVIMENTO_FK = ? AND VIN_STATUS = 'ATIVO'");
        $stV->execute([$movFk]);
        $temNovo = (int)$stV->fetchColumn() > 0;

        $stCpg = $pdo->prepare("SELECT COUNT(*) FROM tb_contas_pagar WHERE CPG_OFX_MOVIMENTO_FK = ?");
        $stCpg->execute([$movFk]);
        $temLegadoP = (int)$stCpg->fetchColumn() > 0;

        $stCre = $pdo->prepare("SELECT COUNT(*) FROM tb_contas_receber WHERE CRE_OFX_MOVIMENTO_FK = ?");
        $stCre->execute([$movFk]);
        $temLegadoR = (int)$stCre->fetchColumn() > 0;

        if ($temNovo || $temLegadoP || $temLegadoR) {
            return;
        }

        $pdo->prepare("UPDATE tb_conciliacao_ofx_movimento
                       SET COM_STATUS = 'IMPORTADO', COM_CONCILIADO = 'NAO',
                           COM_REFERENCIA_TIPO = NULL, COM_REFERENCIA_FK = NULL
                       WHERE COM_CODIGO_PK = ?")
            ->execute([$movFk]);
    }
}
