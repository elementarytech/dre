<?php
// /app/config/helpers.php
declare(strict_types=1);

if (!function_exists('json_out')) {
    function json_out(array $payload, int $code = 200): void
    {
        // limpa qualquer coisa já "ecoada" antes (warnings, espaços, etc.)
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }

        ini_set('zlib.output_compression', '0');
        header_remove();

        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('require_post')) {
    function require_post(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            json_out(['ok' => false, 'msg' => 'Método inválido. Use POST.'], 405);
        }
    }
}

/**
 * Estorna uma movimentação bancária previamente lançada em tb_fluxo_caixa_banco.
 *
 * Usado quando uma conta paga (ou recebida) é excluída/reaberta/estornada.
 *
 * @param PDO    $db
 * @param int    $bancoId           ID do banco que recebeu o lançamento original.
 * @param float  $valor             Valor positivo a estornar.
 * @param string $tipoOriginal      'SAIDA' (para reverter pagamento de conta a pagar)
 *                                  ou 'ENTRADA' (para reverter recebimento de conta a receber).
 * @param string|null $dataOriginal Data do pagamento original (YYYY-MM-DD) — usada para reduzir
 *                                  FCB_SAIDAS_DIA / FCB_ENTRADAS_DIA no dia em que foi registrado.
 *                                  Se null, apenas ajusta o saldo mais recente.
 * @return array   ['ok'=>bool, 'ajustado_dia'=>bool, 'ajustado_saldo'=>bool, 'msg'=>string?]
 */
if (!function_exists('reverter_saldo_banco')) {
// NO-OP: o saldo bancário é calculado dinamicamente por saldoErpConta()/saldoBancarioOfx()
// a partir de tb_contas_pagar/tb_contas_receber e tb_conciliacao_ofx_*. Assim que o
// CPG_STATUS deixa de ser 'PAGO' (ou CRE_STATUS deixa de ser RECEBIDO/PAGO), o saldo
// se ajusta sozinho — não há mais necessidade de mexer em tb_fluxo_caixa_banco.
// Assinatura preservada para compatibilidade com chamadas existentes.
function reverter_saldo_banco(PDO $db, int $bancoId, float $valor, string $tipoOriginal, ?string $dataOriginal = null): array
{
    return [
        'ok'             => true,
        'ajustado_dia'   => false,
        'ajustado_saldo' => false,
        'msg'            => 'no-op (saldo é calculado dinamicamente)',
    ];
}
}
