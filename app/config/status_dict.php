<?php
// /app/config/status_dict.php
// Dicionário central de status do sistema.
// Objetivo: evitar divergência entre módulos quando uma query filtra por status.

declare(strict_types=1);

if (!defined('STATUS_DICT_LOADED')) {
    define('STATUS_DICT_LOADED', true);

    // ---------- Contas a Receber ----------
    define('CRE_STATUS_EM_ABERTO', ['ABERTO', 'PROGRAMADO', 'PENDENTE']);
    define('CRE_STATUS_PAGO',     ['RECEBIDO', 'PAGO']);
    define('CRE_STATUS_CANCELADO', ['CANCELADO']);

    // ---------- Contas a Pagar ----------
    // 'ATRASADO' é rótulo derivado do vencimento e não deve ser persistido.
    // Mantemos no conjunto "em aberto" para absorver linhas legadas que ainda
    // tenham CPG_STATUS='ATRASADO' gravado no banco.
    define('CPG_STATUS_EM_ABERTO', ['ABERTO', 'ATRASADO']);
    define('CPG_STATUS_PAGO',      ['PAGO']);
    define('CPG_STATUS_CANCELADO', ['CANCELADO']);

    // ---------- Contratos ----------
    define('CTR_STATUS_ATIVO',     ['ATIVO']);
    define('CTR_STATUS_SUSPENSO',  ['SUSPENSO']);
    define('CTR_STATUS_ENCERRADO', ['ENCERRADO']);

    // ---------- Parcelas de contrato ----------
    define('CPA_STATUS_EM_ABERTO', ['PROGRAMADO', 'EM_ABERTO']);
    define('CPA_STATUS_PAGO',      ['RECEBIDO', 'PAGO']);
    define('CPA_STATUS_CANCELADO', ['CANCELADO']);

    /**
     * Gera placeholders "?, ?, ?" para usar em cláusulas SQL IN().
     */
    function sql_placeholders(array $arr): string
    {
        if (empty($arr)) return 'NULL';
        return implode(',', array_fill(0, count($arr), '?'));
    }
}
