<?php

declare(strict_types=1);

// TODO: investigar lentidão linha 1048 reportada em contas_a_pagar.log (timeout 30s) — não reproduzido em staging.
// Página: contas_pagar.php (antiga contas_pagar.php)
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/helpers.php';

mb_internal_encoding('UTF-8');
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/contas_a_pagar.log');

function get_db(): PDO
{
    // Padrão do projeto: classe conexao::getInstance() (vide bancos.php)
    if (class_exists('conexao') && method_exists('conexao', 'getInstance')) {
        $db = conexao::getInstance();
        if ($db instanceof PDO) return $db;
    }

    // Fallbacks (caso sua conexao.php exponha função/variável)
    foreach (['getPDO', 'pdo'] as $fn) {
        if (function_exists($fn)) {
            $db = $fn();
            if ($db instanceof PDO) return $db;
        }
    }
    foreach (['pdo', 'db', 'conn', 'connection'] as $var) {
        if (isset($GLOBALS[$var]) && $GLOBALS[$var] instanceof PDO) {
            return $GLOBALS[$var];
        }
    }

    throw new RuntimeException('Erro interno: Class "conexao" not found (ou não retornou PDO). Verifique config/conexao.php');
}

// Compat: alguns endpoints usam db() para obter o PDO
if (!function_exists('db')) {
    function db(): PDO
    {
        return get_db();
    }
}


function coluna_existe(PDO $db, string $tabela, string $coluna): bool
{
    $st = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$tabela, $coluna]);
    return (bool)$st->fetchColumn();
}


function json_out(array $payload, int $code = 200): void
{
    if (function_exists('ob_get_level')) while (ob_get_level() > 0) @ob_end_clean();
    header_remove();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_q(string $k, string $default = ''): string
{
    return trim((string)($_GET[$k] ?? $default));
}

function as_date_or_null(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    return $s;
}

function as_month_or_null(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '') return null;

    // Accept YYYY-MM (month input) or YYYY-MM-DD (date input)
    if (preg_match('/^\d{4}-\d{2}$/', $s)) {
        return $s . '-01'; // store as DATE using day 01
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }
    return null;
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function str_or_null($v): ?string
{
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : $v;
}
function num_or_null($v): ?float
{
    if ($v === '' || $v === null) return null;
    return (float)$v;
}
function bool01($v): int
{
    return (!empty($v) && ($v === true || $v === 1 || $v === "1" || $v === "true" || $v === "on")) ? 1 : 0;
}

function add_month_keep_day(string $ymd, int $n, ?int $diaFixo = null): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $ymd) ?: new DateTime($ymd);
    if ($diaFixo !== null && $diaFixo >= 1 && $diaFixo <= 31) {
        $dt->setDate((int)$dt->format('Y'), (int)$dt->format('m'), 1);
        $dt->modify("+$n month");
        $y = (int)$dt->format('Y');
        $m = (int)$dt->format('m');
        $lastDay = (int)date('t', strtotime(sprintf('%04d-%02d-01', $y, $m)));
        $d = min($diaFixo, $lastDay);
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
    $dt->modify("+$n month");
    return $dt->format('Y-m-d');
}

function calcular_status_por_vencimento(?string $dataBase): string
{
    // ATRASADO é um rótulo derivado (vencimento < hoje), não um status persistido.
    // Mantemos sempre 'ABERTO' no banco para que os filtros de status funcionem
    // consistentemente; a UI calcula o badge ATRASADO em tempo de renderização.
    return 'ABERTO';
}

function obter_grupo_parcelas(PDO $db, int $id): ?array
{
    $st = $db->prepare("SELECT * FROM tb_contas_pagar WHERE CPG_CODIGO_PK = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    return [
        'row' => $row,
        'where' => [
            'c.CPG_DATA_CRIACAO <=> :data_criacao',
            'c.CPG_EMPRESA_FK <=> :empresa_fk',
            'c.CPG_FORNECEDOR_FK <=> :fornecedor_fk',
            'c.CPG_FUNCIONARIO_FK <=> :funcionario_fk',
            'c.CPG_MODO <=> :modo',
            'c.CPG_QTD_PARCELAS <=> :qtd_parcelas',
            'c.CPG_PRIMEIRO_VENCIMENTO <=> :primeiro_vencimento',
            'c.CPG_DIA_VENCIMENTO <=> :dia_vencimento',
            'c.CPG_DOCUMENTO <=> :documento',
        ],
        'params' => [
            ':data_criacao' => $row['CPG_DATA_CRIACAO'] ?? null,
            ':empresa_fk' => $row['CPG_EMPRESA_FK'] ?? null,
            ':fornecedor_fk' => $row['CPG_FORNECEDOR_FK'] ?? null,
            ':funcionario_fk' => $row['CPG_FUNCIONARIO_FK'] ?? null,
            ':modo' => $row['CPG_MODO'] ?? null,
            ':qtd_parcelas' => $row['CPG_QTD_PARCELAS'] ?? null,
            ':primeiro_vencimento' => $row['CPG_PRIMEIRO_VENCIMENTO'] ?? null,
            ':dia_vencimento' => $row['CPG_DIA_VENCIMENTO'] ?? null,
            ':documento' => $row['CPG_DOCUMENTO'] ?? null,
        ],
    ];
}

function ids_grupo_parcelas(PDO $db, int $id): array
{
    $grp = obter_grupo_parcelas($db, $id);
    if (!$grp) return [];

    $sql = "SELECT c.CPG_CODIGO_PK FROM tb_contas_pagar c WHERE " . implode(' AND ', $grp['where']) . " ORDER BY c.CPG_NUM_PARCELA ASC, c.CPG_CODIGO_PK ASC";
    $st = $db->prepare($sql);
    $st->execute($grp['params']);
    return array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'CPG_CODIGO_PK'));
}


function table_columns(string $table): array
{
    $st = db()->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return array_map(fn($r) => (string)$r['COLUMN_NAME'], $st->fetchAll());
}

function detect_rateio_cols(array $cols): array
{
    $c = array_map('strtolower', $cols);
    $pick = function (array $cands) use ($cols, $c) {
        foreach ($cands as $cand) {
            $i = array_search(strtolower($cand), $c, true);
            if ($i !== false) return $cols[$i];
        }
        return null;
    };

    // FK conta pagar
    $fk = $pick(['RCP_CPG_FK', 'RTP_CPG_FK', 'RAT_CPG_FK', 'CPG_FK', 'CONTA_PAGAR_FK', 'RATEIO_CPG_FK', 'CPG_CODIGO_FK', 'CPG_ID']);
    // Plano/conta contábil (rateio "conta_id")
    $conta = $pick(['RCP_CONTA_FK', 'RTP_CONTA_FK', 'CONTA_FK', 'PLANO_CONTA_FK', 'CONTA_CONTABIL_FK', 'CONTA_ID']);
    // Centro de custo
    $cc = $pick(['RCP_CC_FK', 'RTP_CC_FK', 'CC_FK', 'CENTRO_CUSTO_FK', 'CENTRODECUSTO_FK', 'CC_ID']);
    // Percentual
    $perc = $pick(['RCP_PERCENTUAL', 'RTP_PERCENTUAL', 'PERCENTUAL', 'PERC']);
    // Valor
    $val = $pick(['RCP_VALOR', 'RTP_VALOR', 'VALOR', 'VLR']);

    // PK opcional
    $pk = $pick(['RCP_CODIGO_PK', 'RTP_CODIGO_PK', 'RAT_CODIGO_PK', 'ID']);

    return [
        'pk' => $pk,
        'fk' => $fk,
        'conta' => $conta,
        'cc' => $cc,
        'perc' => $perc,
        'valor' => $val,
    ];
}

function sync_rateio_rows(int $cpgId, array $rateio, float $valorBase): void
{
    // Estrutura fixa conforme DDL informada pelo Carlos:
    // tb_rateio_contas_pagar(
    //  RCP_CONTA_PAGAR_FK, RCP_PLANO_CONTAS_FK, RCP_CENTRO_CUSTO_FK, RCP_PERCENTUAL, RCP_VALOR
    // )

    try {
        // garante que a tabela existe (não quebra o salvamento principal)
        table_columns('tb_rateio_contas_pagar');
    } catch (Throwable $e) {
        return;
    }

    // limpa rateio anterior deste título
    $del = db()->prepare("DELETE FROM tb_rateio_contas_pagar WHERE RCP_CONTA_PAGAR_FK = ?");
    $del->execute([$cpgId]);

    if (!is_array($rateio) || !$rateio) return;

    $ins = db()->prepare("
        INSERT INTO tb_rateio_contas_pagar
            (RCP_CONTA_PAGAR_FK, RCP_PLANO_CONTAS_FK, RCP_CENTRO_CUSTO_FK, RCP_PERCENTUAL, RCP_VALOR)
        VALUES
            (?,?,?,?,?)
    ");

    foreach ($rateio as $r) {
        if (!is_array($r)) continue;

        $planoId = (int)($r['conta_id'] ?? 0);
        $ccId = (int)($r['cc_id'] ?? 0);
        if ($planoId <= 0 || $ccId <= 0) continue;

        $perc = (float)($r['perc'] ?? 0);
        $val  = (float)($r['valor'] ?? 0);

        // Se não veio valor (ou veio 0 com percentual), calcula por percentual sobre a base.
        if (($val <= 0) && ($perc > 0) && $valorBase > 0) {
            $val = round($valorBase * ($perc / 100), 2);
        }

        $ins->execute([$cpgId, $planoId, $ccId, $perc, $val]);
    }
}


/* ===========================
   OCR: EXTRAÇÃO DE DADOS
=========================== */
/**
 * Recebe o texto bruto do Tesseract e tenta extrair os campos relevantes
 * para o lançamento de contas a pagar usando expressões regulares.
 */
/**
 * Parser de NF-e / NFS-e (XML) — extrai dados do emitente, valor, vencimento.
 * Tenta NF-e primeiro (layout nacional) e NFS-e depois (ABRASF + variantes).
 * Retorna array vazio se não conseguir extrair o essencial.
 */
function nfe_extrair_dados(string $xml, string $fornList): array
{
    libxml_use_internal_errors(true);

    // Remove namespaces para simplificar o xpath
    $xmlFlat = preg_replace('/\sxmlns(:\w+)?="[^"]+"/', '', $xml);
    $xmlFlat = preg_replace('/<(\/?)[\w]+:([\w]+)/', '<$1$2', $xmlFlat);

    $doc = simplexml_load_string($xmlFlat);
    if (!$doc) return [];

    $pick = function ($nodes) {
        if (!is_array($nodes) && !($nodes instanceof Traversable)) return '';
        foreach ($nodes as $n) {
            $v = trim((string)$n);
            if ($v !== '') return $v;
        }
        return '';
    };

    // ── Tenta NF-e (mercadoria) ───────────────────────────────────────────
    $infNFe = $doc->xpath('//infNFe')[0] ?? null;
    if ($infNFe) {
        $fornecedorNome = (string)($infNFe->emit->xFant ?? $infNFe->emit->xNome ?? '');
        $fornecedorCnpj = preg_replace('/\D/', '', (string)($infNFe->emit->CNPJ ?? $infNFe->emit->CPF ?? ''));
        $valor = (float)($infNFe->total->ICMSTot->vNF ?? 0);
        $documento = (string)($infNFe->ide->nNF ?? '');
        $emissao = null;
        if (!empty($infNFe->ide->dhEmi)) $emissao = substr((string)$infNFe->ide->dhEmi, 0, 10);
        elseif (!empty($infNFe->ide->dEmi)) $emissao = (string)$infNFe->ide->dEmi;

        $vencimento = null;
        if (!empty($infNFe->cobr->dup)) {
            foreach ($infNFe->cobr->dup as $dup) {
                if (!empty($dup->dVenc)) { $vencimento = (string)$dup->dVenc; break; }
            }
        }
        if (!$vencimento && $emissao) $vencimento = $emissao;

        $descricao = '';
        if (!empty($infNFe->det)) {
            $det0 = $infNFe->det[0] ?? null;
            if ($det0 && !empty($det0->prod->xProd)) {
                $descricao = mb_substr((string)$det0->prod->xProd, 0, 80);
            }
        }
        if ($descricao === '') {
            $descricao = 'NF-e ' . $documento . ' - ' . mb_substr($fornecedorNome, 0, 40);
        }

        return nfe_montar_ia(
            $fornecedorNome, $fornecedorCnpj, $valor, $documento, $emissao, $vencimento,
            $descricao, 'NF-e', $fornList, 'Dados extraídos do XML da NF-e.'
        );
    }

    // ── NFS-e (serviço) ────────────────────────────────────────────────────
    // Cobre: Padrão Nacional SPED (<infNFSe><emit>), ABRASF (<InfNfse><PrestadorServico>)
    // e variantes municipais com maiúsculas/minúsculas mistas.
    $fornecedorCnpj = preg_replace('/\D/', '', $pick($doc->xpath(
        // Padrão Nacional SPED (minúsculo)
        '//infNFSe/emit/CNPJ | //infNFSe/emit/CPF'
        . ' | //emit/CNPJ | //emit/CPF'
        . ' | //prest/CNPJ | //prest/CPF'
        // ABRASF
        . ' | //PrestadorServico/IdentificacaoPrestador/CpfCnpj/Cnpj'
        . ' | //PrestadorServico/IdentificacaoPrestador/Cnpj'
        . ' | //Prestador/IdentificacaoPrestador/CpfCnpj/Cnpj'
        . ' | //Prestador/IdentificacaoPrestador/Cnpj'
        . ' | //Prestador/CpfCnpj/Cnpj'
        . ' | //Prestador/Cnpj'
        . ' | //DadosPrestador/Cnpj'
        . ' | //IdentificacaoPrestador/Cnpj'
        . ' | //PrestadorServico/IdentificacaoPrestador/CpfCnpj/Cpf'
        . ' | //Prestador/IdentificacaoPrestador/CpfCnpj/Cpf'
    )));

    $fornecedorNome = $pick($doc->xpath(
        '//infNFSe/emit/xNome | //infNFSe/emit/xFant'
        . ' | //emit/xNome | //emit/xFant'
        . ' | //PrestadorServico/RazaoSocial'
        . ' | //Prestador/RazaoSocial'
        . ' | //DadosPrestador/RazaoSocial'
        . ' | //PrestadorServico/NomeFantasia'
        . ' | //Prestador/NomeFantasia'
    ));

    $valor = (float)$pick($doc->xpath(
        // Padrão Nacional SPED: <valores><vLiq> externo, ou <infDPS><valores><vServPrest><vServ>
        '//infNFSe/valores/vLiq'
        . ' | //NFSe/infNFSe/valores/vLiq'
        . ' | //infDPS/valores/vServPrest/vServ'
        . ' | //DPS/infDPS/valores/vServPrest/vServ'
        // ABRASF
        . ' | //Servico/Valores/ValorLiquidoNfse'
        . ' | //Valores/ValorLiquidoNfse'
        . ' | //ValoresNfse/ValorLiquidoNfse'
        . ' | //Servico/Valores/ValorServicos'
        . ' | //Valores/ValorServicos'
        . ' | //ValorServicos'
        . ' | //ValorTotal'
    ));

    $documento = $pick($doc->xpath(
        '//infNFSe/nNFSe | //nNFSe'
        . ' | //infDPS/nDPS | //nDPS'
        . ' | //InfNfse/Numero'
        . ' | //Nfse/InfNfse/Numero'
        . ' | //IdentificacaoNfse/Numero'
        . ' | //Numero'
        . ' | //IdentificacaoRps/Numero'
    ));

    $emissao = $pick($doc->xpath(
        '//infDPS/dhEmi | //dhEmi'
        . ' | //infNFSe/dhProc | //dhProc'
        . ' | //dCompet'
        . ' | //InfNfse/DataEmissao'
        . ' | //Nfse/InfNfse/DataEmissao'
        . ' | //DataEmissao'
        . ' | //DataEmissaoRps'
    ));
    if ($emissao !== '') $emissao = substr($emissao, 0, 10);

    $vencimento = $pick($doc->xpath(
        '//DataVencimento | //Vencimento | //dVenc'
    ));
    if ($vencimento !== '') $vencimento = substr($vencimento, 0, 10);
    if (!$vencimento) $vencimento = $emissao ?: null;

    $descricao = $pick($doc->xpath(
        '//infDPS/serv/cServ/xDescServ | //cServ/xDescServ | //xDescServ'
        . ' | //infNFSe/xTribNac'
        . ' | //Servico/Discriminacao'
        . ' | //Discriminacao'
        . ' | //DescricaoRps'
    ));
    if ($descricao === '') {
        $descricao = 'NFS-e ' . $documento . ' - ' . mb_substr($fornecedorNome, 0, 40);
    } else {
        $descricao = mb_substr(preg_replace('/\s+/', ' ', $descricao), 0, 80);
    }

    if ($valor > 0 || $fornecedorCnpj !== '' || $fornecedorNome !== '') {
        return nfe_montar_ia(
            $fornecedorNome, $fornecedorCnpj, $valor, $documento, $emissao ?: null, $vencimento,
            $descricao, 'NFS-e', $fornList, 'Dados extraídos do XML da NFS-e.'
        );
    }

    return [];
}

function nfe_montar_ia(
    string $fornNome, string $fornCnpj, float $valor, string $doc,
    ?string $emissao, ?string $venc, string $descricao, string $tipo,
    string $fornList, string $obs
): array {
    $fornecedorIdSugerido = null;
    if ($fornCnpj !== '' && $fornList !== '') {
        if (preg_match_all('/ID:\s*(\d+)\)/', $fornList, $ids, PREG_OFFSET_CAPTURE)) {
            foreach ($ids[1] as $m) {
                $pos = $m[1];
                $blocoAntes = substr($fornList, max(0, $pos - 300), 300);
                if (strpos(preg_replace('/\D/', '', $blocoAntes), $fornCnpj) !== false) {
                    $fornecedorIdSugerido = (int)$m[0];
                    break;
                }
            }
        }
    }

    return [
        'fornecedor_nome' => $fornNome,
        'fornecedor_cnpj' => $fornCnpj ?: null,
        'fornecedor_id_sugerido' => $fornecedorIdSugerido,
        'valor' => round($valor, 2),
        'vencimento' => $venc,
        'emissao' => $emissao,
        'documento' => $doc ?: null,
        'descricao' => $descricao,
        'tipo_conta' => $tipo,
        'observacao_ia' => $obs,
        'confianca' => 'ALTA',
    ];
}

function ocr_extrair_dados(string $texto, string $fornList): array
{
    $t = $texto; // alias legível

    $linhas = array_values(array_filter(array_map('trim', explode("\n", $t)), fn($l) => strlen($l) > 3));

    // ── Detecta se é boleto ──────────────────────────────────────────────────
    $eBoleto = (bool) preg_match('/benefici[aá]rio|cedente|banco\s+cobrador|linha\s+digit[aá]vel|nosso\s+n[uú]mero/i', $t);

    // ── CNPJ / CPF ───────────────────────────────────────────────────────────
    $cnpj = null;
    if ($eBoleto) {
        // Em boletos, o CNPJ do beneficiário vem logo após "CNPJ" perto do bloco do beneficiário
        // Estratégia: pega o CNPJ que aparece na mesma linha ou logo após "beneficiário/cedente"
        if (preg_match('/(?:benefici[aá]rio|cedente)[\s\S]{0,200}?(\d{2}[\.\s]?\d{3}[\.\s]?\d{3}[\/\s]?\d{4}[\-\s]?\d{2})/i', $t, $m)) {
            $cnpj = preg_replace('/\D/', '', $m[1]);
        } elseif (preg_match('/CNPJ[\s:\/]*(\d{2}[\.\s]?\d{3}[\.\s]?\d{3}[\/\s]?\d{4}[\-\s]?\d{2})/i', $t, $m)) {
            $cnpj = preg_replace('/\D/', '', $m[1]);
        }
    }
    // Fallback geral: primeiro CNPJ encontrado no texto
    if (!$cnpj && preg_match('/\d{2}[\.\s]?\d{3}[\.\s]?\d{3}[\/\s]?\d{4}[\-\s]?\d{2}/', $t, $m)) {
        $cnpj = preg_replace('/\D/', '', $m[0]);
    }

    // ── Nome do fornecedor ───────────────────────────────────────────────────
    // O Tesseract lê o boleto assim:
    //   "Beneficiario CNP] Vencimento"   ← linha dos rótulos (juntos)
    //   "HostGator Brasil LTDA 15.754.475/0001-40 28/08/2025"  ← linha dos valores (juntos)
    // O nome é o trecho ANTES do primeiro CNPJ (xx.xxx.xxx/xxxx-xx) na linha seguinte.
    $fornNome = null;

    // Normaliza quebras de linha (\r\n ou \r → \n) antes de dividir
    $tNorm = str_replace(["\r\n", "\r"], "\n", $t);
    $todasLinhas = array_map('trim', explode("\n", $tNorm));

    foreach ($todasLinhas as $i => $linha) {
        if (preg_match('/^benefici/i', $linha) || preg_match('/^cedente/i', $linha)) {
            for ($j = $i + 1; $j <= $i + 3 && isset($todasLinhas[$j]); $j++) {
                $prox = trim($todasLinhas[$j]);
                if (strlen($prox) < 3) continue;

                // Pega tudo antes do CNPJ (xx.xxx.xxx/xxxx-xx)
                if (preg_match('/^(.+?)\s+\d{2}[\.\s]?\d{3}[\.\s]?\d{3}[\s\/]?\d{4}/i', $prox, $nm)) {
                    $candidato = trim($nm[1]);
                    if (strlen($candidato) > 2 && preg_match('/[A-Za-zÀ-ú]{3,}/', $candidato)) {
                        $fornNome = mb_substr($candidato, 0, 80);
                        break 2;
                    }
                }

                // Se não tem CNPJ na linha, pega a linha inteira
                if (
                    preg_match('/[A-Za-zÀ-ú]{4,}/', $prox)
                    && !preg_match('/^(?:cnpj|cpf|agência|banco|vencimento|valor|pagador)/i', $prox)
                ) {
                    $fornNome = mb_substr($prox, 0, 80);
                    break 2;
                }
            }
        }
    }

    // ── Valor ───────────────────────────────────────────────────────────────
    // Padrões: R$ 1.234,56 | 1.234,56 | 1234.56
    $valor = null;
    $padroesValor = [
        '/R\$\s*([\d\.]+,\d{2})/',
        '/VALOR[^\d]*([\d\.]+,\d{2})/i',
        '/TOTAL[^\d]*([\d\.]+,\d{2})/i',
        '/[\d]{1,3}(?:\.[\d]{3})*,\d{2}/',
    ];
    foreach ($padroesValor as $p) {
        if (preg_match($p, $t, $m)) {
            $raw   = preg_replace('/[^\d,]/', '', end($m));
            $valor = (float) str_replace(',', '.', str_replace('.', '', $raw));
            if ($valor > 0) break;
        }
    }

    // ── Vencimento ──────────────────────────────────────────────────────────
    $vencimento = null;
    $padroesData = [
        '/(?:vencimento|venc\.?|due date)[^\d]*(\d{2})[\/\-\.](\d{2})[\/\-\.](\d{2,4})/i',
        '/(\d{2})[\/\-\.](\d{2})[\/\-\.](\d{4})/',
        '/(\d{2})[\/\-\.](\d{2})[\/\-\.](\d{2})/',
    ];
    foreach ($padroesData as $p) {
        if (preg_match($p, $t, $m)) {
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = (int)$m[3];
            if ($y < 100) $y += 2000;
            if ($d > 0 && $d <= 31 && $mo > 0 && $mo <= 12 && $y >= 2000) {
                $vencimento = sprintf('%04d-%02d-%02d', $y, $mo, $d);
                break;
            }
        }
    }

    // ── Emissão ─────────────────────────────────────────────────────────────
    $emissao = null;
    if (preg_match('/(?:emiss[aã]o|data)[^\d]*(\d{2})[\/\-\.](\d{2})[\/\-\.](\d{2,4})/i', $t, $m)) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        if ($y < 100) $y += 2000;
        $emissao = sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    // ── Número do documento ─────────────────────────────────────────────────
    $documento = null;
    if (preg_match('/(?:n[uú]mero|nf[e\-]?|nota fiscal|boleto|fatura|doc\.?)[^\d]*(\d{4,})/i', $t, $m)) {
        $documento = $m[1];
    }

    // ── Tipo de conta (palavras-chave) ───────────────────────────────────────
    $tipoMap = [
        'energia'      => 'Energia Elétrica',
        'elétri'       => 'Energia Elétrica',
        'eletri'       => 'Energia Elétrica',
        'copel'        => 'Energia Elétrica',
        'cemig'        => 'Energia Elétrica',
        'coelce'       => 'Energia Elétrica',
        'enel'         => 'Energia Elétrica',
        'telefon'      => 'Telefone',
        'telecom'      => 'Telefone',
        'claro'        => 'Telefone',
        'vivo'         => 'Telefone',
        'tim '         => 'Telefone',
        'oi '          => 'Telefone',
        'internet'     => 'Internet',
        'água'         => 'Água e Esgoto',
        'agua'         => 'Água e Esgoto',
        'saneamento'   => 'Água e Esgoto',
        'sabesp'       => 'Água e Esgoto',
        'embasa'       => 'Água e Esgoto',
        'aluguel'      => 'Aluguel',
        'locação'      => 'Aluguel',
        'locacao'      => 'Aluguel',
        'condomínio'   => 'Condomínio',
        'condominio'   => 'Condomínio',
        'seguro'       => 'Seguro',
        'iptu'         => 'IPTU',
        'nota fiscal'  => 'Fornecedor',
        'nf-e'         => 'Fornecedor',
        'fatura'       => 'Fatura',
        'boleto'       => 'Boleto Bancário',
        'honorário'    => 'Honorários',
        'honorario'    => 'Honorários',
        'salário'      => 'Folha de Pagamento',
        'salario'      => 'Folha de Pagamento',
    ];
    $tipo = 'Outros';
    $tLower = mb_strtolower($t);
    foreach ($tipoMap as $palavra => $label) {
        if (strpos($tLower, $palavra) !== false) {
            $tipo = $label;
            break;
        }
    }

    // ── Correspondência com fornecedor cadastrado ────────────────────────────
    $fornIdSugerido = null;
    if ($cnpj && $fornList) {
        // Formato da lista: "Nome (CNPJ: 00000000000000, ID: 5)"
        if (preg_match('/ID:\s*(\d+)\)/i', preg_replace(
            '/\s+/',
            ' ',
            implode(' ', array_filter(
                explode("\n", $fornList),
                fn($l) => strpos(preg_replace('/\D/', '', $l), $cnpj) !== false
            ))
        ), $mId)) {
            $fornIdSugerido = (int)$mId[1];
        }
    }

    // ── Descrição automática ─────────────────────────────────────────────────
    $desc = $tipo;
    if ($fornNome) $desc .= ' - ' . mb_substr($fornNome, 0, 50);

    // ── Confiança ────────────────────────────────────────────────────────────
    $pontos = 0;
    if ($cnpj)       $pontos++;
    if ($valor)      $pontos++;
    if ($vencimento) $pontos++;
    if ($fornNome)   $pontos++;
    $confianca = $pontos >= 3 ? 'ALTA' : ($pontos >= 2 ? 'MÉDIA' : 'BAIXA');

    return [
        'fornecedor_nome'       => $fornNome,
        'fornecedor_cnpj'       => $cnpj,
        'fornecedor_id_sugerido' => $fornIdSugerido,
        'valor'                 => $valor,
        'vencimento'            => $vencimento,
        'emissao'               => $emissao,
        'documento'             => $documento,
        'descricao'             => mb_substr($desc, 0, 80),
        'tipo_conta'            => $tipo,
        'observacao_ia'         => 'Dados extraídos via OCR (Tesseract). Confira os campos antes de salvar.',
        'confianca'             => $confianca,
    ];
}

/* ===========================
   ENDPOINTS AJAX
=========================== */
if (isset($_GET['acao']) && $_GET['acao'] === 'formas_pagamento') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db = get_db();
        $st = $db->prepare("SELECT FPG_CODIGO_PK AS id, FPG_DESCRICAO AS descricao FROM tb_forma_pagamento ORDER BY FPG_DESCRICAO");
        $st->execute();
        echo json_encode(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'Erro ao carregar formas', 'detail' => $e->getMessage()]);
    }
    exit;
}



if (isset($_GET['acao']) && $_GET['acao'] === 'bancos') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db = get_db();
        $st = $db->prepare("SELECT BAN_ID AS id, BAN_NOME AS descricao FROM tb_banco ORDER BY BAN_NOME");
        $st->execute();
        echo json_encode(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'Erro ao carregar bancos', 'detail' => $e->getMessage()]);
    }
    exit;
}

// POST JSON: pagar_parcela
$raw = file_get_contents('php://input');
if ($raw) {
    $j = json_decode($raw, true);
    if (is_array($j) && ($j['acao'] ?? '') === 'pagar_parcela') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $db = get_db();

            $id = (int)($j['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'ID da conta inválido']);
                exit;
            }

            // Buscar o valor da parcela e status de autorização
            $stCheck = $db->prepare("SELECT CPG_VALOR_PARCELA, COALESCE(CPG_AUTORIZACAO_STATUS, 'PENDENTE') AS CPG_AUTORIZACAO_STATUS FROM tb_contas_pagar WHERE CPG_CODIGO_PK = ?");
            $stCheck->execute([$id]);
            $rowCheck = $stCheck->fetch();
            if (!$rowCheck) {
                echo json_encode(['ok' => false, 'msg' => 'Conta não encontrada']);
                exit;
            }

            // Trava: só permite pagamento se autorizado no Fluxo de Caixa
            if (strtoupper((string)($rowCheck['CPG_AUTORIZACAO_STATUS'] ?? 'PENDENTE')) !== 'AUTORIZADO') {
                echo json_encode(['ok' => false, 'msg' => 'Esta conta ainda não foi autorizada para pagamento. Solicite a liberação no Fluxo de Caixa.']);
                exit;
            }

            $valorParcela = (float)($rowCheck['CPG_VALOR_PARCELA'] ?? 0);

            // Campos obrigatórios
            $forma = (int)($j['forma_pag_fk'] ?? 0);
            if ($forma <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'Forma de pagamento é obrigatória']);
                exit;
            }

            $dtPag = trim((string)($j['data_pagamento'] ?? ''));
            if ($dtPag === '') {
                echo json_encode(['ok' => false, 'msg' => 'Data de pagamento é obrigatória']);
                exit;
            }

            $valorPago = trim((string)($j['valor_pago'] ?? ''));
            if ($valorPago === '') {
                echo json_encode(['ok' => false, 'msg' => 'Valor é obrigatório']);
                exit;
            }
            $valorPago = str_replace(['R$', ' '], '', $valorPago);
            if (strpos($valorPago, ',') !== false && strpos($valorPago, '.') !== false) $valorPago = str_replace('.', '', $valorPago);
            $valorPago = (float)str_replace(',', '.', $valorPago);
            if ($valorPago <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'Valor deve ser maior que zero']);
                exit;
            }

            // Campos opcionais
            $integral = trim((string)($j['integral_parcial'] ?? 'INTEGRAL'));
            $banco = (int)($j['banco_fk'] ?? 0);
            $banco = $banco > 0 ? $banco : null;
            $obs = trim((string)($j['observacao'] ?? ''));
            $obs = $obs !== '' ? $obs : null;
            $cheque = trim((string)($j['cheque'] ?? ''));
            $cheque = $cheque !== '' ? $cheque : null;

            // Determinar o status:
            //  - INTEGRAL (usuário confirmou quitação, mesmo com desconto/juros) → PAGO
            //  - valor pago >= valor da parcela → PAGO
            //  - caso contrário → ABERTO (pagamento parcial em curso)
            $integralUp = strtoupper($integral);
            $novoStatus = ($integralUp === 'INTEGRAL' || $valorPago >= $valorParcela) ? 'PAGO' : 'ABERTO';
            $pagoFlag   = ($novoStatus === 'PAGO') ? 'SIM' : 'NÃO';

            $sql = "UPDATE tb_contas_pagar SET
        CPG_PARCELA_PAGA_COM_FK = :forma,
        CPG_INTEGRAL_PARCIAL = :integral,
        CPG_DATA_PAGAMENTO = :dtPag,
        CPG_BANCO_PAGAMENTO_FK = :banco,
        CPG_VALOR_PAGO = :valorPago,
        CPG_OBSERVACAO_PAGAMENTO = :obs,
        CPG_CHEQUE = :cheque,
        CPG_STATUS = :status,
        CPG_PAGO = :pagoFlag
      WHERE CPG_CODIGO_PK = :id";

            $st = $db->prepare($sql);
            $st->execute([
                ':forma' => $forma,
                ':integral' => $integral,
                ':dtPag' => $dtPag,
                ':banco' => $banco,
                ':valorPago' => $valorPago,
                ':obs' => $obs,
                ':cheque' => $cheque,
                ':status' => $novoStatus,
                ':pagoFlag' => $pagoFlag,
                ':id' => $id
            ]);

            // Saldo bancário é calculado dinamicamente por saldoErpConta() a partir
            // de tb_contas_pagar. Marcar como PAGO já basta — não há mais write em
            // tb_fluxo_caixa_banco e o checkbox "Conciliar pagamento" foi removido.

            echo json_encode(['ok' => true, 'msg' => 'Pagamento registrado com sucesso']);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'Erro ao salvar pagamento', 'detail' => $e->getMessage()]);
        }
        exit;
    }
}



try {
    $acao = get_q('acao');

    if ($acao === 'buscar_fornecedor') {
        $q = trim(get_q('q'));
        $limit = (int)get_q('limit', '10');
        if ($limit <= 0 || $limit > 30) $limit = 10;

        $where = [];
        $params = [];

        // Por padrão, só ATIVOS
        $where[] = "f.FOR_STATUS = 'ATIVO'";

        if ($q !== '') {
            $where[] = "(f.FOR_RAZAO_SOCIAL LIKE :q1 OR f.FOR_NOME_FANTASIA LIKE :q2 OR f.FOR_CNPJ LIKE :q3)";
            $params[':q1'] = '%' . $q . '%';
            $params[':q2'] = '%' . $q . '%';
            $params[':q3'] = '%' . $q . '%';
        }

        $sql = "SELECT
        f.FOR_CODIGO_PK AS FOR_CODIGO_PK,
        f.FOR_CNPJ AS FOR_CNPJ,
        f.FOR_RAZAO_SOCIAL AS FOR_RAZAO_SOCIAL,
        f.FOR_NOME_FANTASIA AS FOR_NOME_FANTASIA
      FROM tb_fornecedor f";

        // ✅ AQUI
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY f.FOR_RAZAO_SOCIAL ASC LIMIT " . (int)$limit;


        /*
        json_out([
            'ok' => false,
            'debug_file' => __FILE__,
            'debug_sql' => $sql,
            'debug_params' => $params
        ]);
        */


        if (count($params)) {
            foreach (array_keys($params) as $k) {
                if (strpos($sql, $k) === false) {
                    json_out(['ok' => false, 'msg' => "DEBUG: param $k não existe no SQL", 'sql' => $sql, 'params' => $params]);
                }
            }
        }

        $st = db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();

        json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'reabrir_conta') {
        $j = body_json();
        $id = (int)($j['id'] ?? 0);
        $senha = (string)($j['senha'] ?? '');
        $motivo = trim((string)($j['motivo'] ?? ''));

        if ($id <= 0)       json_out(['ok' => false, 'msg' => 'ID inválido.'], 400);
        if ($senha === '')  json_out(['ok' => false, 'msg' => 'Informe a senha de um usuário ADMIN.'], 400);

        $db = db();

        // Valida senha contra qualquer usuário ADMIN ativo
        $stU = $db->prepare("SELECT USU_ID, USU_NOME, USU_SENHA_HASH FROM usuarios WHERE USU_PERFIL='ADMIN' AND USU_STATUS='ATIVO'");
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

        // Checa se a conta está realmente fechada e captura dados de pagamento
        $stC = $db->prepare("SELECT CPG_STATUS, CPG_BANCO_PAGAMENTO_FK, CPG_DATA_PAGAMENTO, CPG_VALOR_PAGO, CPG_VALOR_PARCELA
                               FROM tb_contas_pagar WHERE CPG_CODIGO_PK = ? LIMIT 1");
        $stC->execute([$id]);
        $rowC = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$rowC) {
            json_out(['ok' => false, 'msg' => 'Conta não encontrada.'], 404);
        }
        if (strtoupper((string)($rowC['CPG_STATUS'] ?? '')) !== 'PAGO') {
            json_out(['ok' => false, 'msg' => 'A conta não está paga — nada a reabrir.'], 422);
        }

        $usuarioNome = (string)($_SESSION['user_nome'] ?? 'Sistema');
        $adminNome = (string)$adminValido['USU_NOME'];
        $obsReabertura = '[REABERTO em ' . date('d/m/Y H:i') . ' por ' . $usuarioNome
            . ' — autorizado por ' . $adminNome . ($motivo !== '' ? ' | Motivo: ' . $motivo : '') . ']';

        $db->beginTransaction();

        // Reabre a conta: limpa pagamento e volta a status ABERTO
        $stUpd = $db->prepare("UPDATE tb_contas_pagar SET
            CPG_STATUS = 'ABERTO',
            CPG_DATA_PAGAMENTO = NULL,
            CPG_VALOR_PAGO = 0,
            CPG_BANCO_PAGAMENTO_FK = NULL,
            CPG_PARCELA_PAGA_COM_FK = NULL,
            CPG_INTEGRAL_PARCIAL = NULL,
            CPG_CHEQUE = NULL,
            CPG_OBSERVACAO_PAGAMENTO = CONCAT(IFNULL(CPG_OBSERVACAO_PAGAMENTO,''), CASE WHEN IFNULL(CPG_OBSERVACAO_PAGAMENTO,'') = '' THEN '' ELSE CHAR(10) END, :obsReab)
            WHERE CPG_CODIGO_PK = :id");
        $stUpd->execute([':obsReab' => $obsReabertura, ':id' => $id]);

        // Reverte saldo bancário (se a conta havia sido paga via banco)
        $bancoFk = (int)($rowC['CPG_BANCO_PAGAMENTO_FK'] ?? 0);
        $valorRev = (float)($rowC['CPG_VALOR_PAGO'] ?? 0);
        if ($valorRev <= 0) $valorRev = (float)($rowC['CPG_VALOR_PARCELA'] ?? 0);
        $dtPagOrig = $rowC['CPG_DATA_PAGAMENTO'] ? substr((string)$rowC['CPG_DATA_PAGAMENTO'], 0, 10) : null;

        $reversao = ['ok' => false];
        if ($bancoFk > 0 && $valorRev > 0) {
            $reversao = reverter_saldo_banco($db, $bancoFk, $valorRev, 'SAIDA', $dtPagOrig);
        }

        $db->commit();

        json_out([
            'ok' => true,
            'msg' => 'Conta reaberta. O valor retornou ao saldo da conta.',
            'autorizado_por' => $adminNome,
            'reversao_saldo' => $reversao,
        ]);
    }


    if ($acao === 'buscar_funcionarios') {
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 10);
        if ($limit <= 0 || $limit > 30) $limit = 10;

        if ($q === '') {
            json_out(['ok' => true, 'rows' => []]);
        }

        $db = db();

        // busca por nome ou CPF (somente dígitos)
        $qDigits = preg_replace('/\D+/', '', $q);
        $likeNome = '%' . $q . '%';
        $likeCpf = ($qDigits !== '') ? ('%' . $qDigits . '%') : null;

        $sql = "SELECT FUN_CODIGO_PK, FUN_NOME, FUN_CPF
            FROM tb_funcionarios
            WHERE (FUN_NOME LIKE :nome " . ($likeCpf ? " OR REPLACE(REPLACE(REPLACE(FUN_CPF,'.',''),'-',''),' ','') LIKE :cpf " : "") . ")
            ORDER BY FUN_NOME
            LIMIT {$limit}";

        $st = $db->prepare($sql);
        $st->bindValue(':nome', $likeNome, PDO::PARAM_STR);
        if ($likeCpf) $st->bindValue(':cpf', $likeCpf, PDO::PARAM_STR);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'buscar_plano_contas') {
        // Busca contas do plano de contas
        $sql = "SELECT 
                    PLC_CODIGO_PK as id,
                    PLC_NOME as nome,
                    COALESCE(PLC_EMPRESA_FK,0) as empresa_fk
                FROM tb_plano_contas
                WHERE PLC_STATUS = 'ATIVO'
                ORDER BY PLC_NOME ASC";

        $st = db()->prepare($sql);
        $st->execute();
        $rows = $st->fetchAll();

        json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'buscar_centros_custo') {
        try {
            $sql = "SELECT CEC_ID AS id, CEC_NOME AS nome
                    FROM tb_centro_custo
                    WHERE (CEC_STATUS = 'ATIVO' OR CEC_STATUS IS NULL)
                    ORDER BY CEC_NOME ASC";
            $st = db()->prepare($sql);
            $st->execute();
            $rows = $st->fetchAll();
            json_out(['ok' => true, 'rows' => $rows]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'msg' => 'Não foi possível carregar Centro de Custo', 'detail' => $e->getMessage()], 500);
        }
    }


    if ($acao === 'buscar_empresas') {
        // Unidades de Negócio (tb_empresa)
        $sql = "SELECT 
                    EMP_ID as id,
                    EMP_RAZAO_SOCIAL as nome
                FROM tb_empresa
                ORDER BY EMP_RAZAO_SOCIAL ASC";
        $st = db()->query($sql);
        $rows = $st->fetchAll();
        json_out(['ok' => true, 'rows' => $rows]);
    }


    if ($acao === 'buscar_departamentos') {
        // Departamentos (tb_departamento)
        $sql = "SELECT 
                    DEP_CODIGO_PK as id,
                    DEP_DESCRICAO as descricao
                FROM tb_departamento
                ORDER BY DEP_DESCRICAO ASC";
        $st = db()->query($sql);
        $rows = $st->fetchAll();
        json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'buscar_bancos') {
        $sql = "SELECT 
                    BAN_ID as id,
                    BAN_NOME as descricao
                FROM tb_banco
                WHERE BAN_STATUS = 'ATIVO'
                ORDER BY BAN_NOME ASC";

        $st = db()->prepare($sql);
        $st->execute();
        $rows = $st->fetchAll();

        json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'buscar_formas_pagamento') {
        $sql = "SELECT 
                    FPG_CODIGO_PK as id,
                    FPG_DESCRICAO as descricao
                FROM tb_forma_pagamento
                WHERE FPG_STATUS = 'ATIVO'
                ORDER BY FPG_DESCRICAO ASC";

        $st = db()->prepare($sql);
        $st->execute();
        $rows = $st->fetchAll();

        json_out(['ok' => true, 'rows' => $rows]);
    }

    // ── Análise de documento: OCR (Tesseract + Ghostscript) com fallback Gemini ─
    if ($acao === 'ler_arquivo') {
        $body     = body_json();
        $base64   = trim((string)($body['base64'] ?? ''));
        $mimeType = trim((string)($body['mime_type'] ?? ''));
        $fornList = trim((string)($body['forn_list'] ?? ''));

        if ($base64 === '') {
            json_out(['ok' => false, 'msg' => 'Nenhum documento recebido.'], 400);
        }

        $raw = base64_decode($base64);
        if ($raw === false || $raw === '') {
            json_out(['ok' => false, 'msg' => 'Arquivo inválido.'], 400);
        }

        // ── XML (NF-e / NFS-e) ────────────────────────────────────────
        $ehXml = (stripos($mimeType, 'xml') !== false
            || strpos(ltrim($raw), '<?xml') === 0
            || preg_match('/<(nfeProc|NFe|Nfse|CompNfse|infNFe|ConsultarNfseResposta)\b/i', substr($raw, 0, 4000)));
        if ($ehXml) {
            $ia = nfe_extrair_dados($raw, $fornList);
            if (empty($ia)) {
                json_out(['ok' => false, 'msg' => 'Layout de XML não reconhecido. Tente "Analisar com IA".'], 422);
            }
            json_out(['ok' => true, 'ia' => $ia]);
        }

        // ── PDF: Ghostscript txtwrite → regex ─────────────────────────
        $ehPdf = (stripos($mimeType, 'pdf') !== false || substr($raw, 0, 4) === '%PDF');
        if ($ehPdf) {
            $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
            $gsCandidates = $isWin
                ? array_merge(
                    glob('C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe') ?: [],
                    ['gswin64c', 'gs']
                )
                : ['gs'];
            $gsExe = null;
            foreach ($gsCandidates as $c) {
                if (file_exists($c)) { $gsExe = $c; break; }
                $check = $isWin
                    ? trim((string)shell_exec('where ' . escapeshellarg($c) . ' 2>NUL'))
                    : trim((string)shell_exec('which ' . escapeshellarg($c) . ' 2>/dev/null'));
                if ($check !== '') { $gsExe = $c; break; }
            }

            if (!$gsExe) {
                json_out(['ok' => false, 'msg' => 'Ghostscript não está instalado no servidor. Use "Analisar com IA" para PDF.'], 500);
            }

            $tmpDir = sys_get_temp_dir();
            $sep = $isWin ? '\\' : '/';
            $pdfFile = $tmpDir . $sep . 'ler_' . uniqid('', true) . '.pdf';
            $txtFile = $pdfFile . '.txt';
            file_put_contents($pdfFile, $raw);

            $cmd = sprintf(
                '%s -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=txtwrite -sOutputFile=%s %s 2>&1',
                escapeshellarg($gsExe),
                escapeshellarg($txtFile),
                escapeshellarg($pdfFile)
            );
            exec($cmd, $out, $ret);

            $texto = '';
            if ($ret === 0 && file_exists($txtFile)) {
                $texto = (string)file_get_contents($txtFile);
            }
            @unlink($pdfFile);
            @unlink($txtFile);

            if (trim($texto) === '') {
                json_out(['ok' => false, 'msg' => 'Não foi possível extrair texto do PDF (provavelmente é imagem escaneada). Use "Analisar com IA".'], 422);
            }

            $ia = ocr_extrair_dados($texto, $fornList);
            // confiança local
            if (!empty($ia['valor']) && (!empty($ia['vencimento']) || !empty($ia['emissao']))) {
                $ia['confianca'] = 'ALTA';
                $ia['observacao_ia'] = 'Texto extraído do PDF via Ghostscript.';
            } else {
                $ia['confianca'] = 'BAIXA';
                $ia['observacao_ia'] = 'PDF lido mas poucos campos reconhecidos — revise.';
            }
            json_out(['ok' => true, 'ia' => $ia, '_texto_debug' => mb_substr($texto, 0, 2000)]);
        }

        json_out(['ok' => false, 'msg' => 'Formato não suportado pela leitura local. Use "Analisar com IA".'], 422);
    }

    if ($acao === 'analisar_ia') {
        $body     = body_json();
        $base64   = trim((string)($body['base64']    ?? ''));
        $mimeType = trim((string)($body['mime_type'] ?? 'image/jpeg'));
        $fornList = trim((string)($body['forn_list'] ?? ''));

        if ($base64 === '') {
            json_out(['ok' => false, 'msg' => 'Nenhum documento recebido.'], 400);
        }

        // ── XML (NF-e) — parse determinístico, sem IA ───────────────────
        $conteudoRaw = base64_decode($base64);
        $ehXml = (
            stripos($mimeType, 'xml') !== false
            || (strpos(ltrim($conteudoRaw), '<?xml') === 0)
            || preg_match('/<(nfeProc|NFe|infNFe)\b/i', substr($conteudoRaw, 0, 2000))
        );

        if ($ehXml) {
            $ia = nfe_extrair_dados($conteudoRaw, $fornList);
            if (!empty($ia)) {
                json_out(['ok' => true, 'ia' => $ia]);
            }
            // Parser nativo não entendeu o layout → manda o texto do XML pro Gemini
            $xmlTrunc = mb_substr($conteudoRaw, 0, 30000);
            $promptXml = "Você é um sistema de leitura de notas fiscais eletrônicas. "
                . "Analise este XML (NF-e ou NFS-e) e extraia os dados para lançamento em contas a pagar.\n\n"
                . "FORNECEDORES CADASTRADOS:\n" . ($fornList ?: '(nenhum)') . "\n\n"
                . "XML:\n" . $xmlTrunc . "\n\n"
                . "Responda APENAS JSON válido, sem markdown:\n"
                . '{"fornecedor_nome":"...","fornecedor_cnpj":"somente digitos ou null","fornecedor_id_sugerido":null,'
                . '"valor":0.00,"vencimento":"AAAA-MM-DD ou null","emissao":"AAAA-MM-DD ou null",'
                . '"documento":"numero ou null","descricao":"max 80 chars","tipo_conta":"NF-e ou NFS-e",'
                . '"observacao_ia":"observacao ou null","confianca":"ALTA|MEDIA|BAIXA"}';

            $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : (getenv('GEMINI_API_KEY') ?: '');
            if ($geminiKey === '') {
                json_out(['ok' => false, 'msg' => 'Não foi possível ler o XML e a chave do Gemini não está configurada.'], 500);
            }

            $payload = json_encode([
                'contents' => [['parts' => [['text' => $promptXml]]]]
            ], JSON_UNESCAPED_UNICODE);

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode($geminiKey);
            $ch = curl_init($url);
            $curlOpts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ];
            foreach ([
                __DIR__ . '/config/cacert.pem',
                'C:\\laragon\\etc\\ssl\\cacert.pem',
                '/etc/ssl/certs/ca-certificates.crt',
                '/etc/pki/tls/certs/ca-bundle.crt',
            ] as $p) {
                if (is_file($p)) { $curlOpts[CURLOPT_CAINFO] = $p; break; }
            }
            curl_setopt_array($ch, $curlOpts);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr || $httpCode !== 200) {
                $respJson = json_decode($result, true);
                $msg = $respJson['error']['message'] ?? ($curlErr ?: ('HTTP ' . $httpCode));
                json_out(['ok' => false, 'msg' => 'Não foi possível ler o XML automaticamente. ' . $msg], 500);
            }

            $rawText = json_decode($result, true)['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            $clean = preg_replace('/```json|```/i', '', $rawText);
            $ia = json_decode(trim($clean), true);
            if (!is_array($ia)) {
                json_out(['ok' => false, 'msg' => 'Gemini não retornou JSON válido.', 'raw' => substr($rawText, 0, 300)], 500);
            }
            json_out(['ok' => true, 'ia' => $ia]);
        }

        // ── Caminhos dos executáveis (Windows + Linux) ───────────────────
        $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

        // Ghostscript — testa executáveis em ordem de preferência
        $gsCandidates = $isWin
            ? [
                'C:\\Program Files\\gs\\gs10.07.0\\bin\\gswin64c.exe',
                'C:\\Program Files\\gs\\gs10.04.0\\bin\\gswin64c.exe',
                'C:\\Program Files\\gs\\gs9.56.1\\bin\\gswin64c.exe',
                'gswin64c',
                'gs',
            ]
            : ['gs'];
        $gsExe = null;
        foreach ($gsCandidates as $candidate) {
            if (file_exists($candidate) || ($isWin === false && trim(shell_exec('which ' . escapeshellarg($candidate) . ' 2>/dev/null')))) {
                $gsExe = $candidate;
                break;
            }
            // No Windows, testa pelo where
            if ($isWin) {
                exec('where ' . escapeshellarg($candidate) . ' 2>NUL', $wo, $wr);
                if ($wr === 0 && !empty($wo[0])) {
                    $gsExe = $candidate;
                    break;
                }
            }
        }

        // Tesseract
        $tessCandidates = $isWin
            ? [
                'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
                'tesseract',
            ]
            : ['tesseract'];
        $tessExe = null;
        foreach ($tessCandidates as $candidate) {
            if (file_exists($candidate)) {
                $tessExe = $candidate;
                break;
            }
            if ($isWin) {
                exec('where ' . escapeshellarg($candidate) . ' 2>NUL', $wo2, $wr2);
                if ($wr2 === 0 && !empty($wo2[0])) {
                    $tessExe = $candidate;
                    break;
                }
            } else {
                if (trim(shell_exec('which ' . escapeshellarg($candidate) . ' 2>/dev/null'))) {
                    $tessExe = $candidate;
                    break;
                }
            }
        }

        // ── 1. Decodifica o arquivo ──────────────────────────────────────
        $tmpDir  = sys_get_temp_dir();
        $tmpBase = $tmpDir . ($isWin ? '\\' : '/') . 'ocr_' . uniqid('', true);
        $rawFile = $tmpBase . ($mimeType === 'application/pdf' ? '.pdf' : '.png');
        $ocrImg  = $tmpBase . '_ocr.png';
        $txtFile = $tmpBase . '_out';

        file_put_contents($rawFile, base64_decode($base64));

        $ocrOk = false;
        $texto = '';

        // ── 2. Tenta OCR (Tesseract + Ghostscript para PDF) ─────────────
        if ($tessExe) {
            $imgParaOcr = $rawFile;

            if ($mimeType === 'application/pdf') {
                $imgParaOcr = null;

                if ($gsExe) {
                    $gsCmd = sprintf(
                        '%s -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r200 -dFirstPage=1 -dLastPage=1 -sOutputFile=%s %s 2>&1',
                        escapeshellarg($gsExe),
                        escapeshellarg($ocrImg),
                        escapeshellarg($rawFile)
                    );
                    exec($gsCmd, $gsOut, $gsRet);
                    if ($gsRet === 0 && file_exists($ocrImg)) {
                        $imgParaOcr = $ocrImg;
                    }
                }

                if (!$imgParaOcr) {
                    // Tenta ImageMagick como segundo fallback para PDF
                    $imExe = $isWin ? 'magick' : 'convert';
                    $imCmd = sprintf(
                        '%s -density 200 %s[0] %s 2>&1',
                        $imExe,
                        escapeshellarg($rawFile),
                        escapeshellarg($ocrImg)
                    );
                    exec($imCmd, $imOut, $imRet);
                    if ($imRet === 0 && file_exists($ocrImg)) {
                        $imgParaOcr = $ocrImg;
                    }
                }
            }

            if ($imgParaOcr) {
                // Tenta com por+eng, cai para eng se não tiver pacote português
                foreach (['por+eng', 'eng'] as $lang) {
                    $tessCmd = sprintf(
                        '%s %s %s -l %s --psm 6 2>&1',
                        escapeshellarg($tessExe),
                        escapeshellarg($imgParaOcr),
                        escapeshellarg($txtFile),
                        $lang
                    );
                    exec($tessCmd, $tessOut, $tessRet);
                    if ($tessRet === 0 && file_exists($txtFile . '.txt')) break;
                }

                if (file_exists($txtFile . '.txt')) {
                    $texto = file_get_contents($txtFile . '.txt');
                    $ocrOk = trim($texto) !== '';
                }
            }

            // Limpeza
            @unlink($rawFile);
            @unlink($ocrImg);
            @unlink($txtFile . '.txt');
        }

        // ── 3. Se OCR funcionou → extrai com regex ───────────────────────
        if ($ocrOk) {
            $ia = ocr_extrair_dados($texto, $fornList);
            json_out(['ok' => true, 'ia' => $ia, '_ocr_debug' => $texto]);
        }

        // ── 4. Fallback: Google Gemini ───────────────────────────────────
        @unlink($rawFile); // garante limpeza

        $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : (getenv('GEMINI_API_KEY') ?: '');

        if ($geminiKey === '') {
            json_out([
                'ok' => false,
                'msg' => 'OCR local falhou (Tesseract/Ghostscript não disponível) e a chave do Gemini não está configurada. ' .
                    'Defina GEMINI_API_KEY em config/conexao.php ou instale Tesseract + Ghostscript.'
            ], 500);
        }

        $prompt = "Você é um sistema de leitura de contas a pagar. Analise este documento e extraia os dados para lançamento.\n\n"
            . "FORNECEDORES CADASTRADOS:\n" . ($fornList ?: '(nenhum)') . "\n\n"
            . "Responda APENAS JSON válido, sem markdown:\n"
            . '{"fornecedor_nome":"...","fornecedor_cnpj":"somente digitos ou null","fornecedor_id_sugerido":null,'
            . '"valor":0.00,"vencimento":"AAAA-MM-DD ou null","emissao":"AAAA-MM-DD ou null",'
            . '"documento":"numero ou null","descricao":"max 80 chars","tipo_conta":"ex: Energia Eletrica",'
            . '"observacao_ia":"observacao ou null","confianca":"ALTA|MEDIA|BAIXA"}';

        // Gemini suporta PDF e imagem nativamente via inline_data
        $geminiPart = [
            'inline_data' => ['mime_type' => $mimeType, 'data' => $base64]
        ];

        $geminiPayload = json_encode([
            'contents' => [[
                'parts' => [
                    $geminiPart,
                    ['text' => $prompt],
                ]
            ]]
        ], JSON_UNESCAPED_UNICODE);

        $geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode($geminiKey);

        $ch = curl_init($geminiUrl);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $geminiPayload,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ];
        $caBundle = null;
        foreach ([
            __DIR__ . '/config/cacert.pem',
            'C:\\laragon\\etc\\ssl\\cacert.pem',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
        ] as $caPath) {
            if (is_file($caPath)) { $caBundle = $caPath; break; }
        }
        if ($caBundle) {
            $curlOpts[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($ch, $curlOpts);
        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            json_out(['ok' => false, 'msg' => 'Erro ao conectar ao Gemini: ' . $curlErr], 500);
        }

        $geminiResp = json_decode($result, true);
        if ($httpCode !== 200) {
            $errMsg = $geminiResp['error']['message'] ?? ('HTTP ' . $httpCode);
            json_out(['ok' => false, 'msg' => 'Erro do Gemini: ' . $errMsg], 500);
        }

        $rawText = $geminiResp['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
        $clean   = preg_replace('/```json|```/i', '', $rawText);
        $ia      = json_decode(trim($clean), true);

        if (!is_array($ia)) {
            json_out(['ok' => false, 'msg' => 'Gemini não retornou JSON válido.', 'raw' => substr($rawText, 0, 300)], 500);
        }

        json_out(['ok' => true, 'ia' => $ia]);
    }
    // ── fim analisar_ia ───────────────────────────────────────────────────────

    if ($acao === 'opcoes_sistema') {
        // Retorna as opções fixas do sistema (tipos)
        json_out([
            'ok' => true,
            'tipos' => [
                ['value' => 'D', 'label' => 'Débito'],
                ['value' => 'C', 'label' => 'Crédito']
            ]
        ]);
    }



    if ($acao === 'listar_empresas') {
        $sql = "SELECT EMP_ID AS id, EMP_RAZAO_SOCIAL AS nome FROM tb_empresa ORDER BY EMP_RAZAO_SOCIAL ASC";
        $st = db()->query($sql);
        $rows = $st->fetchAll();

        json_out([
            'ok' => true,
            'rows' => $rows
        ]);
    }

    if ($acao === 'listar') {
        $q      = get_q('q');           // busca livre
        $status = strtoupper(get_q('status', 'TODOS')); // ABERTO/PAGO/CANCELADO/TODOS
        $tipo   = strtoupper(get_q('tipo', 'TODOS'));   // D/C/TODOS
        $ini    = get_q('ini');         // YYYY-MM-DD
        $fim    = get_q('fim');         // YYYY-MM-DD
        $empresa = get_q('empresa', '0'); // Empresa (0 = todas)
        $tipoData = strtolower(get_q('tipo_data', 'vencimento')); // vencimento|pagamento|criacao
        $valorMin = get_q('valor_min');
        $valorMax = get_q('valor_max');
        $fornecedorFk = (int)get_q('fornecedor_fk', '0');


        // Paginação
        $page = (int)get_q('page', '1');
        if ($page < 1) $page = 1;
        $perPage = (int)get_q('per_page', '50');
        if ($perPage < 10) $perPage = 10;
        if ($perPage > 200) $perPage = 200;
        $offset = ($page - 1) * $perPage;


        $where = [];
        $params = [];

        // Filtros combinados (busca livre + demais filtros aplicam juntos)
        if ($status !== '' && $status !== 'TODOS') {
            if ($status === 'ATRASADO') {
                // Atrasado = em aberto com vencimento passado.
                // Aceita status='ABERTO'/'ATRASADO' e também legados com status vazio/NULL.
                $where[] = "(c.CPG_STATUS IN ('ABERTO','ATRASADO') OR c.CPG_STATUS IS NULL OR TRIM(c.CPG_STATUS) = '')";
                $where[] = "c.CPG_VENCIMENTO < CURDATE()";
                $where[] = "c.CPG_DATA_PAGAMENTO IS NULL";
            } elseif ($status === 'ABERTO') {
                // Aberto inclui atrasadas (vencidas) e legados com status em branco.
                $where[] = "(c.CPG_STATUS IN ('ABERTO','ATRASADO') OR c.CPG_STATUS IS NULL OR TRIM(c.CPG_STATUS) = '')";
                $where[] = "c.CPG_DATA_PAGAMENTO IS NULL";
            } else {
                $where[] = "c.CPG_STATUS = :st";
                $params[':st'] = $status;
            }
        }
        if ($tipo !== '' && $tipo !== 'TODOS') {
            $where[] = "c.CPG_TIPO = :tp";
            $params[':tp'] = $tipo;
        }
        if ($empresa !== '' && $empresa !== '0') {
            $where[] = "c.CPG_EMPRESA_FK = :emp";
            $params[':emp'] = (int)$empresa;
        }
        if ($fornecedorFk > 0) {
            $where[] = "c.CPG_FORNECEDOR_FK = :ffk";
            $params[':ffk'] = $fornecedorFk;
        }

        // Coluna de data conforme escolha do usuário
        $colData = 'c.CPG_VENCIMENTO';
        if ($tipoData === 'pagamento')   $colData = 'c.CPG_DATA_PAGAMENTO';
        elseif ($tipoData === 'criacao') $colData = 'c.CPG_DATA_CRIACAO';

        // Quando o usuário filtra ATRASADO, ignora a data inicial: atrasado é
        // por definição "do passado até hoje", então qualquer corte inferior
        // (ex.: default de início do mês atual) esconderia atrasados antigos.
        $ignoraIni = ($status === 'ATRASADO');

        if ($ini !== '' && !$ignoraIni) {
            $where[] = "$colData >= :ini";
            $params[':ini'] = $ini;
        }
        if ($fim !== '') {
            $where[] = "$colData <= :fim";
            $params[':fim'] = $fim;
        }

        // Faixa de valor
        if ($valorMin !== '' && is_numeric($valorMin)) {
            $where[] = "c.CPG_VALOR_PARCELA >= :vmin";
            $params[':vmin'] = (float)$valorMin;
        }
        if ($valorMax !== '' && is_numeric($valorMax)) {
            $where[] = "c.CPG_VALOR_PARCELA <= :vmax";
            $params[':vmax'] = (float)$valorMax;
        }

        if ($q !== '') {
            $qd = preg_replace('/\D+/', '', $q);
            $apenasDigitos = ($qd !== '' && $qd === $q);

            if ($apenasDigitos) {
                // Busca numérica: foca em CNPJ (limpo), documento, NF, valor, id
                $where[] = "(
                    REPLACE(REPLACE(REPLACE(IFNULL(f.FOR_CNPJ,''),'.',''),'/',''),'-','') LIKE :q1
                    OR c.CPG_DOCUMENTO LIKE :q2
                    OR c.CPG_NOTA_FISCAL LIKE :q3
                    OR CAST(c.CPG_VALOR_PARCELA AS CHAR) LIKE :q4
                    OR c.CPG_CODIGO_PK = :q5
                )";
                $qv = '%' . $qd . '%';
                $params[':q1'] = $qv;
                $params[':q2'] = $qv;
                $params[':q3'] = $qv;
                $params[':q4'] = $qv;
                $params[':q5'] = (int)$qd;
            } else {
                // Busca textual em razão/fantasia/documento/descrição/NF/complemento
                $where[] = "(
                    f.FOR_RAZAO_SOCIAL LIKE :q1
                    OR f.FOR_NOME_FANTASIA LIKE :q2
                    OR f.FOR_CNPJ LIKE :q3
                    OR c.CPG_DOCUMENTO LIKE :q4
                    OR c.CPG_DESCRICAO LIKE :q5
                    OR c.CPG_NOTA_FISCAL LIKE :q6
                    OR c.CPG_COMPLEMENTO LIKE :q7
                )";
                $qv = '%' . $q . '%';
                $params[':q1'] = $qv;
                $params[':q2'] = $qv;
                $params[':q3'] = $qv;
                $params[':q4'] = $qv;
                $params[':q5'] = $qv;
                $params[':q6'] = $qv;
                $params[':q7'] = $qv;
            }
        }

        $sql = <<<SQL
SELECT
  c.CPG_CODIGO_PK AS id,
  c.CPG_DATA_CRIACAO AS data_criacao,
  c.CPG_FORNECEDOR_FK AS fornecedor_fk,
  COALESCE(f.FOR_NOME_FANTASIA, f.FOR_RAZAO_SOCIAL) AS fornecedor,
  c.CPG_FUNCIONARIO_FK AS funcionario_fk,
  fu.FUN_NOME AS funcionario_nome,
  fu.FUN_CPF AS funcionario_cpf,
  f.FOR_CNPJ AS fornecedor_cnpj,
  c.CPG_PLANO_CONTAS_FK AS plano_contas_fk,
  c.CPG_CENTRO_CUSTO_FK AS centro_custo_fk,
  c.CPG_TIPO AS tipo,
  c.CPG_QTD_PARCELAS AS qtd_parcelas,
  c.CPG_NUM_PARCELA AS num_parcela,
  CONCAT(LPAD(IFNULL(c.CPG_NUM_PARCELA,0),2,'0'),'/',LPAD(IFNULL(c.CPG_QTD_PARCELAS,0),2,'0')) AS parcela_info,
  c.CPG_VENCIMENTO AS vencimento,
  c.CPG_VALOR_PARCELA AS valor_parcela,
  c.CPG_DATA_PAGAMENTO AS data_pagamento,
  c.CPG_DESCRICAO AS descricao,
  c.CPG_DOCUMENTO AS documento,
  c.CPG_NOTA_FISCAL AS nf,
  c.CPG_PAGO AS pago,
  c.CPG_STATUS AS status,
  c.CPG_RECEBIDO_POR AS recebido_por,
  c.CPG_DESCONTO AS desconto,
  c.CPG_JUROS AS juros,
  c.CPG_MULTA AS multa,
  c.CPG_MODO AS modo,
  c.CPG_VALOR_PAGO AS valor_pago,
  c.CPG_INTEGRAL_PARCIAL AS integral_parcial,
  c.CPG_COMPLEMENTO AS complemento,
  c.CPG_OBSERVACOES AS obs,
  c.CPG_LOTE_TITULOS AS lote_titulos,
  COALESCE(c.CPG_AUTORIZACAO_STATUS, 'PENDENTE') AS autorizacao_status
FROM tb_contas_pagar c
LEFT JOIN tb_empresa e ON e.EMP_ID = c.CPG_EMPRESA_FK
LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = c.CPG_FORNECEDOR_FK
LEFT JOIN tb_funcionarios fu ON fu.FUN_CODIGO_PK = c.CPG_FUNCIONARIO_FK
SQL;

        $whereSql = $where ? (" WHERE " . implode(" AND ", $where)) : "";

        // Total de registros (para paginação)
        $sqlCount = "SELECT COUNT(*) AS total
FROM tb_contas_pagar c
LEFT JOIN tb_empresa e ON e.EMP_ID = c.CPG_EMPRESA_FK
LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = c.CPG_FORNECEDOR_FK
LEFT JOIN tb_funcionarios fu ON fu.FUN_CODIGO_PK = c.CPG_FUNCIONARIO_FK" . $whereSql;

        $stCount = db()->prepare($sqlCount);
        $stCount->execute($params);
        $totalRows = (int)($stCount->fetchColumn() ?: 0);
        $totalPages = (int)max(1, ceil($totalRows / $perPage));

        // Totais (sem limitar pela página)
        $hoje = date('Y-m-d');
        // KPIs em aberto/vencido/cancelado: ainda fazem sentido por vencimento (respeitam $whereSql vigente).
        $sqlTot = "SELECT
  COALESCE(SUM(CASE WHEN UPPER(COALESCE(c.CPG_STATUS,'')) <> 'CANCELADO' THEN c.CPG_VALOR_PARCELA ELSE 0 END), 0) AS total_lancado,
  COALESCE(SUM(CASE WHEN (UPPER(COALESCE(c.CPG_STATUS,'')) IN ('ABERTO','ATRASADO') OR c.CPG_STATUS IS NULL OR TRIM(c.CPG_STATUS)='') AND c.CPG_DATA_PAGAMENTO IS NULL THEN GREATEST(0, c.CPG_VALOR_PARCELA - COALESCE(c.CPG_VALOR_PAGO, 0)) ELSE 0 END), 0) AS total_aberto,
  COALESCE(SUM(CASE WHEN UPPER(COALESCE(c.CPG_STATUS,'')) = 'CANCELADO' THEN c.CPG_VALOR_PARCELA ELSE 0 END), 0) AS total_cancelado,
  COALESCE(SUM(CASE WHEN (UPPER(COALESCE(c.CPG_STATUS,'')) IN ('ABERTO','ATRASADO') OR c.CPG_STATUS IS NULL OR TRIM(c.CPG_STATUS)='') AND c.CPG_DATA_PAGAMENTO IS NULL AND c.CPG_VENCIMENTO < :hoje THEN GREATEST(0, c.CPG_VALOR_PARCELA - COALESCE(c.CPG_VALOR_PAGO, 0)) ELSE 0 END), 0) AS total_vencido,
  COALESCE(SUM(CASE WHEN UPPER(COALESCE(c.CPG_STATUS,'')) NOT IN ('PAGO','CANCELADO') AND COALESCE(c.CPG_VALOR_PAGO, 0) > 0 THEN c.CPG_VALOR_PAGO ELSE 0 END), 0) AS total_parcial,
  COALESCE(SUM(CASE WHEN UPPER(COALESCE(c.CPG_STATUS,'')) <> 'CANCELADO' AND COALESCE(c.CPG_VALOR_PAGO, 0) > 0 THEN c.CPG_VALOR_PAGO ELSE 0 END), 0) AS total_pago
FROM tb_contas_pagar c
LEFT JOIN tb_empresa e ON e.EMP_ID = c.CPG_EMPRESA_FK
LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = c.CPG_FORNECEDOR_FK
LEFT JOIN tb_funcionarios fu ON fu.FUN_CODIGO_PK = c.CPG_FUNCIONARIO_FK" . $whereSql;

        $paramsTot = $params;
        $paramsTot[':hoje'] = $hoje;
        $stTot = db()->prepare($sqlTot);
        $stTot->execute($paramsTot);
        $totRow = $stTot->fetch() ?: [];

        $total_lancado = (float)($totRow['total_lancado'] ?? 0);
        $total_aberto = (float)($totRow['total_aberto'] ?? 0);
        $total_cancelado = (float)($totRow['total_cancelado'] ?? 0);
        $total_vencido = (float)($totRow['total_vencido'] ?? 0);
        $total_parcial = (float)($totRow['total_parcial'] ?? 0);
        // TOTAL PAGO: já calculado dentro de $sqlTot, respeita o WHERE da listagem.
        $total_pago = (float)($totRow['total_pago'] ?? 0);

        // Paginação na listagem
        $sql .= $whereSql;
        $sql .= " ORDER BY c.CPG_VENCIMENTO ASC, c.CPG_CODIGO_PK DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $list = [];
        foreach ($rows as $r) {
            $val = (float)($r['valor_parcela'] ?? 0);
            $statusRow = strtoupper(trim((string)($r['status'] ?? '')));
            if ($statusRow === '' && empty($r['data_pagamento'])) {
                $statusRow = 'ABERTO';
            }
            if ($statusRow === '') $statusRow = 'ABERTO';
            // Normaliza legado: linhas com CPG_STATUS='ATRASADO' viram 'ABERTO' na saída;
            // o front deriva o rótulo ATRASADO a partir do vencimento.
            if ($statusRow === 'ATRASADO') $statusRow = 'ABERTO';


            $list[] = [
                'id' => (int)$r['id'],
                'data_criacao' => $r['data_criacao'],
                'fornecedor_fk' => (int)($r['fornecedor_fk'] ?? 0),
                'fornecedor' => $r['fornecedor'],
                'fornecedor_cnpj' => $r['fornecedor_cnpj'],
                'plano_contas_fk' => (int)($r['plano_contas_fk'] ?? 0),
                'centro_custo_fk' => (int)($r['centro_custo_fk'] ?? 0),
                'tipo' => $r['tipo'],
                'qtd_parcelas' => (int)($r['qtd_parcelas'] ?? 0),
                'num_parcela' => (int)($r['num_parcela'] ?? 0),
                'parcela_info' => $r['parcela_info'],
                'vencimento' => $r['vencimento'],
                'valor' => $val,
                'valor_parcela' => $val,
                'data_pagamento' => $r['data_pagamento'],
                'descricao' => $r['descricao'],
                'documento' => $r['documento'],
                'modo' => $r['modo'] ?? null,

                'nf' => $r['nf'] ?? null,
                'pago' => (int)($r['pago'] ?? 0),
                'status' => $statusRow,
                'recebido_por' => $r['recebido_por'],
                'desconto' => (float)($r['desconto'] ?? 0),
                'juros' => (float)($r['juros'] ?? 0),
                'multa' => (float)($r['multa'] ?? 0),
                'valor_pago' => (float)($r['valor_pago'] ?? 0),
                'integral_parcial' => $r['integral_parcial'] ?? null,
                'complemento' => $r['complemento'] ?? null,
                'obs' => $r['obs'] ?? null,
                'lote_titulos' => $r['lote_titulos'] ?? null,
                'autorizacao_status' => $r['autorizacao_status'] ?? 'PENDENTE',
            ];
        }

        // Enriquece cada linha com stats do lote (Briefing 14): qtd irmãos, qtd pagos, saldo restante.
        $lotesDistintos = array_values(array_unique(array_filter(array_column($list, 'lote_titulos'))));
        $loteStats = [];
        if (!empty($lotesDistintos)) {
            $phLote = implode(',', array_fill(0, count($lotesDistintos), '?'));
            $sqlLote = "SELECT CPG_LOTE_TITULOS AS lote,
                               COUNT(*) AS qtd_total,
                               SUM(CASE WHEN UPPER(COALESCE(CPG_STATUS,'')) = 'PAGO' THEN 1 ELSE 0 END) AS qtd_pagos,
                               COALESCE(SUM(CPG_VALOR_PARCELA), 0) AS valor_total,
                               COALESCE(SUM(COALESCE(CPG_VALOR_PAGO, 0)), 0) AS valor_pago_total
                        FROM tb_contas_pagar
                        WHERE CPG_LOTE_TITULOS IN ({$phLote})
                          AND UPPER(COALESCE(CPG_STATUS,'')) <> 'CANCELADO'
                        GROUP BY CPG_LOTE_TITULOS";
            $stLoteStats = db()->prepare($sqlLote);
            $stLoteStats->execute($lotesDistintos);
            foreach ($stLoteStats->fetchAll() as $ls) {
                $loteStats[(string)$ls['lote']] = [
                    'qtd_total'        => (int)$ls['qtd_total'],
                    'qtd_pagos'        => (int)$ls['qtd_pagos'],
                    'valor_total'      => (float)$ls['valor_total'],
                    'valor_pago_total' => (float)$ls['valor_pago_total'],
                    'saldo_restante'   => max(0.0, (float)$ls['valor_total'] - (float)$ls['valor_pago_total']),
                ];
            }
        }
        foreach ($list as &$item) {
            $item['lote_stats'] = $item['lote_titulos'] ? ($loteStats[(string)$item['lote_titulos']] ?? null) : null;
        }
        unset($item);

        json_out([
            'ok' => true,
            'rows' => $list,
            'total_lancado' => $total_lancado,
            'total_aberto' => $total_aberto,
            'total_pago' => $total_pago,
            'total_cancelado' => $total_cancelado,
            'total_vencido' => $total_vencido,
            'total_parcial' => $total_parcial,
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages
        ]);
    }

    if ($acao === 'obter') {
        $id = (int)get_q('id', '0');
        if (!$id) json_out(['ok' => false, 'msg' => 'ID inválido']);

        $sql = <<<SQL
SELECT
  c.CPG_CODIGO_PK AS id,
  c.CPG_DATA_CRIACAO AS data_criacao,
  c.CPG_FORNECEDOR_FK AS fornecedor_fk,
  c.CPG_FUNCIONARIO_FK AS funcionario_fk,
  COALESCE(f.FOR_NOME_FANTASIA, f.FOR_RAZAO_SOCIAL) AS fornecedor,
  f.FOR_NOME_FANTASIA AS fornecedor_fantasia,
  f.FOR_RAZAO_SOCIAL AS fornecedor_razao_social,
  f.FOR_CNPJ AS fornecedor_cnpj,
  c.CPG_PLANO_CONTAS_FK AS plano_contas_fk,
  c.CPG_CENTRO_CUSTO_FK AS centro_custo_fk,
  c.CPG_TIPO AS tipo,
  c.CPG_QTD_PARCELAS AS qtd_parcelas,
  c.CPG_NUM_PARCELA AS num_parcela,
  CONCAT(LPAD(IFNULL(c.CPG_NUM_PARCELA,0),2,'0'),'/',LPAD(IFNULL(c.CPG_QTD_PARCELAS,0),2,'0')) AS parcela_info,
  c.CPG_VENCIMENTO AS vencimento,
  c.CPG_VALOR_PARCELA AS valor_parcela,
  c.CPG_DATA_PAGAMENTO AS data_pagamento,
  c.CPG_DESCRICAO AS descricao,
  c.CPG_DOCUMENTO AS documento,
  c.CPG_PAGO AS pago,
  c.CPG_STATUS AS status,
  c.CPG_RECEBIDO_POR AS recebido_por,
  c.CPG_DESCONTO AS desconto,
  c.CPG_JUROS AS juros,
  c.CPG_MULTA AS multa,
  c.CPG_MODO AS CPG_MODO,
  c.CPG_COMPETENCIA AS competencia,
  c.CPG_BANCO AS banco,
  c.CPG_COMPLEMENTO AS complemento,
  c.CPG_FORMA_PAGAMENTO AS forma_pag,
  c.CPG_OBSERVACOES AS obs,
  c.CPG_ENTRADA AS entrada,
  c.CPG_PRIMEIRO_VENCIMENTO AS primeiro_venc,
  c.CPG_DIA_VENCIMENTO AS dia_venc,
  c.CPG_NOTA_FISCAL AS nf,
  c.CPG_EMISSAO AS emissao,
  c.CPG_EMPRESA_FK AS unid_negocio_raw,
  c.CPG_EMPRESA_FK AS empresa_fk,
  e.EMP_ID AS unid_negocio_id,
  e.EMP_RAZAO_SOCIAL AS unid_negocio_nome,
  c.CPG_PROJETO AS projeto,
  c.CPG_IGNORA_FLUXO AS ignora_fluxo,
  c.CPG_RATEIO_JSON AS rateio_json,
  c.CPG_CONTA_CONTABIL AS conta_contabil,
  c.CPG_VALOR_PAGO AS valor_pago,
  c.CPG_INTEGRAL_PARCIAL AS integral_parcial,
  COALESCE(c.CPG_AUTORIZACAO_STATUS, 'PENDENTE') AS autorizacao_status,
  c.CPG_AUTORIZADO_EM AS autorizado_em,
  c.CPG_AUTORIZADO_POR AS autorizado_por,
  fu.FUN_NOME AS funcionario_nome,
  fu.FUN_CPF AS funcionario_cpf,
  pc.PLC_NOME AS plano_contas_nome,
  cc.CEC_NOME AS centro_custo_nome,
  ban.BAN_NOME AS banco_descricao,
  fpg.FPG_DESCRICAO AS forma_pag_descricao
FROM tb_contas_pagar c
LEFT JOIN tb_fornecedor f ON f.FOR_CODIGO_PK = c.CPG_FORNECEDOR_FK
LEFT JOIN tb_empresa e ON e.EMP_ID = c.CPG_EMPRESA_FK
LEFT JOIN tb_funcionarios fu ON fu.FUN_CODIGO_PK = c.CPG_FUNCIONARIO_FK
LEFT JOIN tb_plano_contas pc ON pc.PLC_CODIGO_PK = c.CPG_PLANO_CONTAS_FK
LEFT JOIN tb_centro_custo cc ON cc.CEC_ID = c.CPG_CENTRO_CUSTO_FK
LEFT JOIN tb_banco ban ON ban.BAN_ID = c.CPG_BANCO
LEFT JOIN tb_forma_pagamento fpg ON fpg.FPG_CODIGO_PK = c.CPG_FORMA_PAGAMENTO
WHERE c.CPG_CODIGO_PK = ?
SQL;

        $st = db()->prepare($sql);
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) json_out(['ok' => false, 'msg' => 'Não encontrado']);

        $rateioJson = $r['rateio_json'] ?? null;
        $rateio = [];
        if ($rateioJson) {
            $dec = json_decode($rateioJson, true);
            $rateio = is_array($dec) ? $dec : [];
        }

        // Buscar rateio da tabela tb_rateio_contas_pagar com JOINs para pegar descrições
        try {
            $sqlRateio = <<<SQL
SELECT
    rcp.RCP_PLANO_CONTAS_FK AS conta_id,
    rcp.RCP_CENTRO_CUSTO_FK AS cc_id,
    rcp.RCP_PERCENTUAL AS perc,
    rcp.RCP_VALOR AS valor,
    pc.PLC_CODIGO_PK AS conta_codigo,
    pc.PLC_NOME AS conta_descricao,
    cc.CEC_ID AS cc_codigo,
    cc.CEC_NOME AS cc_nome
FROM tb_rateio_contas_pagar rcp
LEFT JOIN tb_plano_contas pc ON pc.PLC_CODIGO_PK = rcp.RCP_PLANO_CONTAS_FK
LEFT JOIN tb_centro_custo cc ON cc.CEC_ID = rcp.RCP_CENTRO_CUSTO_FK
WHERE rcp.RCP_CONTA_PAGAR_FK = ?
ORDER BY rcp.RCP_PLANO_CONTAS_FK
SQL;
            $stRateio = db()->prepare($sqlRateio);
            $stRateio->execute([$id]);
            $rateioFromTable = $stRateio->fetchAll();

            if ($rateioFromTable && count($rateioFromTable) > 0) {
                // Usar dados da tabela (mais confiável)
                $rateio = array_map(function ($row) {
                    return [
                        'conta_id' => (int)($row['conta_id'] ?? 0),
                        'cc_id' => (int)($row['cc_id'] ?? 0),
                        'perc' => (float)($row['perc'] ?? 0),
                        'valor' => (float)($row['valor'] ?? 0),
                        'conta_codigo' => $row['conta_codigo'] ?? '',
                        'conta_descricao' => $row['conta_descricao'] ?? '',
                        'cc_codigo' => $row['cc_codigo'] ?? '',
                        'cc_nome' => $row['cc_nome'] ?? '',
                    ];
                }, $rateioFromTable);
            } else {
                // Fallback: usar dados direto da tb_contas_pagar (quando não há rateio múltiplo)
                if ((int)($r['plano_contas_fk'] ?? 0) > 0 && (int)($r['centro_custo_fk'] ?? 0) > 0) {
                    $rateio = [[
                        'conta_id' => (int)($r['plano_contas_fk'] ?? 0),
                        'cc_id' => (int)($r['centro_custo_fk'] ?? 0),
                        'perc' => 100,
                        'valor' => (float)($r['valor_parcela'] ?? 0),
                        'conta_codigo' => '',
                        'conta_descricao' => $r['plano_contas_nome'] ?? '',
                        'cc_codigo' => '',
                        'cc_nome' => $r['centro_custo_nome'] ?? '',
                    ]];
                }
            }
        } catch (Throwable $e) {
            // Se não conseguir buscar da tabela, usa o JSON
            error_log('[obter rateio] ' . $e->getMessage());
        }

        $row = [
            'id' => (int)$r['id'],
            'data_criacao' => $r['data_criacao'],
            'fornecedor_fk' => (int)($r['fornecedor_fk'] ?? 0),
            'funcionario_fk' => (int)($r['funcionario_fk'] ?? 0),
            'fornecedor' => $r['fornecedor'],
            'fornecedor_cnpj' => $r['fornecedor_cnpj'],
            'plano_contas_fk' => (int)($r['plano_contas_fk'] ?? 0),
            'plano_contas_nome' => $r['plano_contas_nome'] ?? null,
            'centro_custo_fk' => (int)($r['centro_custo_fk'] ?? 0),
            'centro_custo_nome' => $r['centro_custo_nome'] ?? null,
            'tipo' => $r['tipo'],
            'qtd_parcelas' => (int)($r['qtd_parcelas'] ?? 0),
            'num_parcela' => (int)($r['num_parcela'] ?? 0),
            'parcela_info' => $r['parcela_info'],
            'vencimento' => $r['vencimento'],
            'valor' => (float)($r['valor_parcela'] ?? 0),
            'data_pagamento' => $r['data_pagamento'],
            'descricao' => $r['descricao'],
            'documento' => $r['documento'],
            'pago' => (int)($r['pago'] ?? 0),
            'status' => $r['status'],
            'recebido_por' => $r['recebido_por'],
            'desconto' => (float)($r['desconto'] ?? 0),
            'juros' => (float)($r['juros'] ?? 0),
            'multa' => (float)($r['multa'] ?? 0),
            'modo' => $r['CPG_MODO'] ?? null,

            'competencia' => $r['competencia'],
            'banco' => $r['banco'],
            'banco_descricao' => $r['banco_descricao'] ?? null,
            'complemento' => $r['complemento'],
            'forma_pag' => $r['forma_pag'],
            'forma_pag_descricao' => $r['forma_pag_descricao'] ?? null,
            'obs' => $r['obs'],
            'entrada' => $r['entrada'],
            'parcelas' => (int)($r['qtd_parcelas'] ?? 1),
            'primeiro_venc' => $r['primeiro_venc'],
            'dia_venc' => (int)($r['dia_venc'] ?? 0),
            'nf' => $r['nf'],
            'emissao' => $r['emissao'],
            'unid_negocio_id' => (is_numeric($r['unid_negocio_raw'] ?? null) ? (int)$r['unid_negocio_raw'] : (int)($r['unid_negocio_id'] ?? 0)),
            'unid_negocio' => ($r['unid_negocio_nome'] ?? null) ?: ($r['unid_negocio_raw'] ?? null),
            'empresa_fk' => (int)($r['empresa_fk'] ?? 0),

            'projeto' => $r['projeto'],
            'ignora_fluxo' => (int)($r['ignora_fluxo'] ?? 0),
            'rateio' => $rateio,
            'conta_contabil' => $r['conta_contabil'],
            'cpf_cnpj' => $r['fornecedor_cnpj'],
            'fornecedor_fantasia' => $r['fornecedor_fantasia'] ?? null,
            'fornecedor_razao_social' => $r['fornecedor_razao_social'] ?? null,
            'funcionario_nome' => $r['funcionario_nome'] ?? null,
            'funcionario_cpf' => $r['funcionario_cpf'] ?? null,
            'autorizacao_status' => $r['autorizacao_status'] ?? 'PENDENTE',
            'autorizado_em' => $r['autorizado_em'] ?? null,
        ];

        json_out(['ok' => true, 'row' => $row]);
    }

    if ($acao === 'salvar') {
        $data = body_json();
        $id = (int)($data['id'] ?? 0);
        $applyScope = strtolower(trim((string)($data['apply_scope'] ?? 'one')));
        if (!in_array($applyScope, ['one', 'all'], true)) $applyScope = 'one';

        $modo = str_or_null($data['modo'] ?? 'AVISTA') ?? 'AVISTA';

        // Campos comuns
        $fornFk = (int)($data['fornecedor_fk'] ?? 0);
        $venc = as_date_or_null($data['vencimento'] ?? null);
        $valor = num_or_null($data['valor'] ?? 0) ?? 0.0;
        $desc = str_or_null($data['descricao'] ?? null);
        $doc = str_or_null($data['documento'] ?? null);
        $comp = str_or_null($data['complemento'] ?? null);
        $tipo = str_or_null($data['tipo'] ?? null);
        $banco = str_or_null($data['banco'] ?? null);
        $formaPag = str_or_null($data['forma_pag'] ?? null);
        $obs = str_or_null($data['obs'] ?? null);
        $competencia = as_month_or_null($data['competencia'] ?? null);
        $competenciaFolha = as_month_or_null($data['competencia_folha'] ?? null);
        $entradaData = as_date_or_null($data['entrada'] ?? null);
        $nf = str_or_null($data['nf'] ?? null);
        $emissao = as_date_or_null($data['emissao'] ?? null);
        $unidNegocioFk = (int)($data['unid_negocio_fk'] ?? 0);
        $empresaFk = (int)($data['empresa_fk'] ?? 0);
        $unidNegocio = $unidNegocioFk > 0 ? (string)$unidNegocioFk : str_or_null($data['unid_negocio'] ?? null);
        $projeto = str_or_null($data['projeto'] ?? null);
        $ignoraFluxo = bool01($data['ignora_fluxo'] ?? false);
        $status = 'ABERTO';
        $contaContabil = str_or_null($data['conta_contabil'] ?? null);
        // Funcionário (Folha: 1 lançamento por funcionário)
        $funcionarioFk = (int)($data['funcionario_fk'] ?? 0);
        // Lote de múltiplos títulos (Briefing 14): UUID gerado no frontend, mesmo para todos os títulos da mesma submissão.
        $loteTitulos = str_or_null($data['lote_titulos'] ?? null);
        if ($loteTitulos !== null && !preg_match('/^[A-Za-z0-9\-]{1,36}$/', $loteTitulos)) {
            $loteTitulos = null; // descarta se vier algo estranho
        }

        if ($empresaFk <= 0) {
            json_out(['ok' => false, 'msg' => 'Selecione a empresa (CPG_EMPRESA_FK)']);
        }
        if ($fornFk <= 0) {
            json_out(['ok' => false, 'msg' => 'Selecione o fornecedor.']);
        }
        if ($valor <= 0) {
            json_out(['ok' => false, 'msg' => 'Informe um valor maior que zero.']);
        }
        if (!$venc && !as_date_or_null($data['primeiro_venc'] ?? null)) {
            json_out(['ok' => false, 'msg' => 'Informe a data de vencimento.']);
        }

        // Plano de contas / Centro de custo (título)
        $planoContasFk = (int)($data['plano_contas_fk'] ?? 0);
        $centroCustoFk = (int)($data['centro_custo_fk'] ?? 0);

        $rateio = $data['rateio'] ?? [];
        $rateioJson = is_array($rateio) ? json_encode($rateio, JSON_UNESCAPED_UNICODE) : null;

        // Se o Plano/Centro do título não vierem do formulário, usa a 1ª linha válida do rateio
        if (($planoContasFk <= 0 || $centroCustoFk <= 0) && is_array($rateio)) {
            foreach ($rateio as $r) {
                if (!is_array($r)) continue;
                $p = (int)($r['conta_id'] ?? 0);
                $c = (int)($r['cc_id'] ?? 0);
                if ($p > 0 && $planoContasFk <= 0) $planoContasFk = $p;
                if ($c > 0 && $centroCustoFk <= 0) $centroCustoFk = $c;
                if ($planoContasFk > 0 && $centroCustoFk > 0) break;
            }
        }

        $parcelas = max(1, (int)($data['parcelas'] ?? 1));
        $primeiroVenc = as_date_or_null($data['primeiro_venc'] ?? null);
        $diaVenc = (int)($data['dia_venc'] ?? 0);

        // Se for À VISTA, sempre 1 parcela (evita duplicar registros)
        if ($modo === 'AVISTA') {
            $parcelas = 1;
            $primeiroVenc = $venc;
            $diaVenc = 0;
        }
        // Status calculado automaticamente pelo vencimento: apenas ABERTO ou ATRASADO
        $dataBaseStatus = null;
        if ($modo === 'AVISTA') {
            $dataBaseStatus = $venc;
        } else {
            $dataBaseStatus = $primeiroVenc ?: $venc;
        }
        $status = calcular_status_por_vencimento($dataBaseStatus);

        $hoje = date('Y-m-d H:i:s');

        $insertParcelasConta = function (PDO $db, int $fornFkInsert, int $planoContasFkInsert, int $centroCustoFkInsert, string $modoInsert, ?string $vencInsert, float $valorTotalInsert, ?string $descInsert, ?string $docInsert, ?string $competenciaInsert, ?string $bancoInsert, ?string $compInsert, ?string $tipoInsert, ?string $formaPagInsert, ?string $obsInsert, ?string $entradaDataInsert, int $qtdInsert, ?string $primeiroVencInsert, int $diaVencInsert, ?string $nfInsert, ?string $emissaoInsert, ?string $unidNegocioInsert, int $empresaFkInsert, ?string $projetoInsert, int $ignoraFluxoInsert, ?string $rateioJsonInsert, ?string $contaContabilInsert, array $rateioInsert) use ($hoje) {
            $qtdInsert = max(1, (int)$qtdInsert);
            $baseTotalInsert = (float)$valorTotalInsert;
            $baseParcelarInsert = max(0.0, ($baseTotalInsert * $qtdInsert));
            $v0Insert = $primeiroVencInsert ?: $vencInsert;
            if (!$v0Insert) {
                json_out(['ok' => false, 'msg' => 'Vencimento inválido'], 422);
            }

            $valorParcelaInsert = $qtdInsert > 0 ? round($baseParcelarInsert / $qtdInsert, 2) : $baseParcelarInsert;
            $somatorioInsert = 0.0;
            $idsInsert = [];

            $sqlInsertParcelas = <<<SQL
INSERT INTO tb_contas_pagar (
  CPG_DATA_CRIACAO, CPG_FORNECEDOR_FK, CPG_FUNCIONARIO_FK, CPG_PLANO_CONTAS_FK, CPG_CENTRO_CUSTO_FK, CPG_VENCIMENTO, CPG_VALOR_PARCELA,
  CPG_DESCRICAO, CPG_DOCUMENTO, CPG_STATUS, CPG_MODO, CPG_COMPETENCIA, CPG_BANCO,
  CPG_COMPLEMENTO, CPG_TIPO, CPG_FORMA_PAGAMENTO, CPG_OBSERVACOES,
  CPG_ENTRADA, CPG_QTD_PARCELAS, CPG_NUM_PARCELA, CPG_PRIMEIRO_VENCIMENTO, CPG_DIA_VENCIMENTO,
  CPG_NOTA_FISCAL, CPG_EMISSAO, CPG_UNIDADE_NEGOCIO, CPG_EMPRESA_FK, CPG_PROJETO, CPG_IGNORA_FLUXO,
  CPG_RATEIO_JSON, CPG_CONTA_CONTABIL
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
SQL;

            $stInsertParcelas = $db->prepare($sqlInsertParcelas);

            for ($iInsert = 1; $iInsert <= $qtdInsert; $iInsert++) {
                $venc_iInsert = ($iInsert === 1) ? $v0Insert : add_month_keep_day($v0Insert, $iInsert - 1, $diaVencInsert ?: null);

                $vpInsert = $valorParcelaInsert;
                if ($iInsert === $qtdInsert) {
                    $vpInsert = round($baseParcelarInsert - $somatorioInsert, 2);
                }
                $somatorioInsert += $vpInsert;

                $parcelaInfoInsert = str_pad((string)$iInsert, 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$qtdInsert, 2, '0', STR_PAD_LEFT);
                $desc_iInsert = $descInsert;
                if ($qtdInsert > 1) {
                    $desc_iInsert = trim(($descInsert ?: '') . ' (' . $parcelaInfoInsert . ')');
                }

                $stInsertParcelas->execute([
                    $hoje,
                    $fornFkInsert,
                    null,
                    $planoContasFkInsert,
                    $centroCustoFkInsert,
                    $venc_iInsert,
                    $vpInsert,
                    $desc_iInsert,
                    $docInsert,
                    calcular_status_por_vencimento($venc_iInsert),
                    $modoInsert,
                    $competenciaInsert,
                    $bancoInsert,
                    $compInsert,
                    $tipoInsert,
                    $formaPagInsert,
                    $obsInsert,
                    $entradaDataInsert,
                    $qtdInsert,
                    $iInsert,
                    $v0Insert,
                    $diaVencInsert,
                    $nfInsert,
                    $emissaoInsert,
                    $unidNegocioInsert,
                    $empresaFkInsert,
                    $projetoInsert,
                    $ignoraFluxoInsert,
                    $rateioJsonInsert,
                    $contaContabilInsert
                ]);

                $newIdInsert = (int)$db->lastInsertId();
                $idsInsert[] = $newIdInsert;
                sync_rateio_rows($newIdInsert, is_array($rateioInsert) ? $rateioInsert : [], (float)$vpInsert);
            }

            return $idsInsert;
        };

        if ($id > 0) {
            // UPDATE
            $db = db();
            $idsAfetados = [$id];
            if ($applyScope === 'all') {
                $idsAfetados = ids_grupo_parcelas($db, $id);
                if (!$idsAfetados) $idsAfetados = [$id];
            }

            $placeholders = implode(',', array_fill(0, count($idsAfetados), '?'));
            $statusUpdate = calcular_status_por_vencimento($venc);

            $stAtual = $db->prepare("SELECT CPG_QTD_PARCELAS, CPG_MODO, CPG_NUM_PARCELA, CPG_PRIMEIRO_VENCIMENTO, CPG_DATA_CRIACAO FROM tb_contas_pagar WHERE CPG_CODIGO_PK = ? LIMIT 1");
            $stAtual->execute([$id]);
            $contaAtual = $stAtual->fetch() ?: [];
            $qtdAtual = (int)($contaAtual['CPG_QTD_PARCELAS'] ?? 1);
            $modoAtual = (string)($contaAtual['CPG_MODO'] ?? 'AVISTA');
            $virandoParcelado = ($parcelas > 1 && $modo !== 'AVISTA' && $qtdAtual <= 1);

            if ($virandoParcelado) {
                try {
                    $db->beginTransaction();

                    $stDelRateio = $db->prepare("DELETE FROM tb_rateio_contas_pagar WHERE RCP_CONTA_PAGAR_FK IN ($placeholders)");
                    $stDelRateio->execute($idsAfetados);

                    $stDelConta = $db->prepare("DELETE FROM tb_contas_pagar WHERE CPG_CODIGO_PK IN ($placeholders)");
                    $stDelConta->execute($idsAfetados);

                    $idsNovos = $insertParcelasConta(
                        $db,
                        $fornFk,
                        $planoContasFk,
                        $centroCustoFk,
                        $modo,
                        $venc,
                        $valor,
                        $desc,
                        $doc,
                        $competencia,
                        $banco,
                        $comp,
                        $tipo,
                        $formaPag,
                        $obs,
                        $entradaData,
                        $parcelas,
                        ($primeiroVenc ?: $venc),
                        $diaVenc,
                        $nf,
                        $emissao,
                        $unidNegocio,
                        $empresaFk,
                        $projeto,
                        $ignoraFluxo,
                        $rateioJson,
                        $contaContabil,
                        is_array($rateio) ? $rateio : []
                    );

                    $db->commit();
                    json_out(['ok' => true, 'id' => (int)($idsNovos[0] ?? 0), 'ids_afetados' => $idsNovos, 'acao' => 'reparcelado']);
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
            }

            // Se apply_scope=all, atualizar campos comuns SEM alterar vencimento/status individual
            if ($applyScope === 'all' && count($idsAfetados) > 1) {
                $sqlAll = <<<SQL
UPDATE tb_contas_pagar SET
  CPG_FORNECEDOR_FK = ?,
  CPG_FUNCIONARIO_FK = ?,
  CPG_PLANO_CONTAS_FK = ?,
  CPG_CENTRO_CUSTO_FK = ?,
  CPG_VALOR_PARCELA = ?,
  CPG_DESCRICAO = ?,
  CPG_MODO = ?,
  CPG_COMPETENCIA = ?,
  CPG_BANCO = ?,
  CPG_COMPLEMENTO = ?,
  CPG_TIPO = ?,
  CPG_FORMA_PAGAMENTO = ?,
  CPG_OBSERVACOES = ?,
  CPG_NOTA_FISCAL = ?,
  CPG_EMISSAO = ?,
  CPG_UNIDADE_NEGOCIO = ?,
  CPG_EMPRESA_FK = ?,
  CPG_PROJETO = ?,
  CPG_IGNORA_FLUXO = ?,
  CPG_RATEIO_JSON = ?,
  CPG_CONTA_CONTABIL = ?
WHERE CPG_CODIGO_PK IN ($placeholders) AND CPG_STATUS != 'PAGO'
SQL;
                $paramsAll = [
                    $fornFk, null, $planoContasFk, $centroCustoFk,
                    $valor, $desc, $modo, $competencia, $banco, $comp, $tipo,
                    $formaPag, $obs, $nf, $emissao, $unidNegocio, $empresaFk,
                    $projeto, $ignoraFluxo, $rateioJson, $contaContabil,
                ];
                $paramsAll = array_merge($paramsAll, $idsAfetados);
                $st = $db->prepare($sqlAll);
                $st->execute($paramsAll);

                // Atualizar vencimento/documento/status SÓ da parcela editada
                $stOne = $db->prepare("UPDATE tb_contas_pagar SET CPG_VENCIMENTO = ?, CPG_DOCUMENTO = ?, CPG_STATUS = ?, CPG_ENTRADA = ? WHERE CPG_CODIGO_PK = ?");
                $stOne->execute([$venc, $doc, $statusUpdate, $entradaData, $id]);
            } else {
                // apply_scope=one: atualiza tudo na parcela individual
                $sqlOne = <<<SQL
UPDATE tb_contas_pagar SET
  CPG_FORNECEDOR_FK = ?,
  CPG_FUNCIONARIO_FK = ?,
  CPG_PLANO_CONTAS_FK = ?,
  CPG_CENTRO_CUSTO_FK = ?,
  CPG_VENCIMENTO = ?,
  CPG_VALOR_PARCELA = ?,
  CPG_DESCRICAO = ?,
  CPG_DOCUMENTO = ?,
  CPG_STATUS = ?,
  CPG_MODO = ?,
  CPG_COMPETENCIA = ?,
  CPG_BANCO = ?,
  CPG_COMPLEMENTO = ?,
  CPG_TIPO = ?,
  CPG_FORMA_PAGAMENTO = ?,
  CPG_OBSERVACOES = ?,
  CPG_ENTRADA = ?,
  CPG_NOTA_FISCAL = ?,
  CPG_EMISSAO = ?,
  CPG_UNIDADE_NEGOCIO = ?,
  CPG_EMPRESA_FK = ?,
  CPG_PROJETO = ?,
  CPG_IGNORA_FLUXO = ?,
  CPG_RATEIO_JSON = ?,
  CPG_CONTA_CONTABIL = ?
WHERE CPG_CODIGO_PK = ?
SQL;
                $paramsOne = [
                    $fornFk, null, $planoContasFk, $centroCustoFk,
                    $venc, $valor, $desc, $doc, $statusUpdate, $modo,
                    $competencia, $banco, $comp, $tipo, $formaPag, $obs,
                    $entradaData, $nf, $emissao, $unidNegocio, $empresaFk,
                    $projeto, $ignoraFluxo, $rateioJson, $contaContabil, $id,
                ];
                $st = $db->prepare($sqlOne);
                $st->execute($paramsOne);
            }

            foreach ($idsAfetados as $idRateio) {
                sync_rateio_rows((int)$idRateio, is_array($rateio) ? $rateio : [], (float)$valor);
            }

            json_out(['ok' => true, 'id' => $id, 'ids_afetados' => $idsAfetados]);
        }

        // INSERT
        $ids = $insertParcelasConta(
            db(),
            $fornFk,
            $planoContasFk,
            $centroCustoFk,
            $modo,
            $venc,
            $valor,
            $desc,
            $doc,
            $competencia,
            $banco,
            $comp,
            $tipo,
            $formaPag,
            $obs,
            $entradaData,
            $parcelas,
            ($primeiroVenc ?: $venc),
            $diaVenc,
            $nf,
            $emissao,
            $unidNegocio,
            $empresaFk,
            $projeto,
            $ignoraFluxo,
            $rateioJson,
            $contaContabil,
            is_array($rateio) ? $rateio : []
        );

        // Se vier lote_titulos, marca os IDs recém-criados (Briefing 14: múltiplos títulos no mesmo lançamento).
        if ($loteTitulos !== null && !empty($ids)) {
            $phLote = implode(',', array_fill(0, count($ids), '?'));
            $stLote = db()->prepare("UPDATE tb_contas_pagar SET CPG_LOTE_TITULOS = ? WHERE CPG_CODIGO_PK IN ({$phLote})");
            $stLote->execute(array_merge([$loteTitulos], array_map('intval', $ids)));
        }

        json_out(['ok' => true, 'id' => (int)($ids[0] ?? 0)]);
    }
    if ($acao === 'excluir') {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $applyScope = strtolower(trim((string)($_GET['apply_scope'] ?? $_POST['apply_scope'] ?? 'one')));
        if (!$id) json_out(['ok' => false, 'msg' => 'ID inválido']);
        if (!in_array($applyScope, ['one', 'all'], true)) $applyScope = 'one';

        $db = db();
        $idsCandidatos = [$id];
        if ($applyScope === 'all') {
            $idsCandidatos = ids_grupo_parcelas($db, $id);
            if (!$idsCandidatos) $idsCandidatos = [$id];
        }

        $placeholdersC = implode(',', array_fill(0, count($idsCandidatos), '?'));

        // Carrega status/valor pago de cada candidato para decidir o que pode ser excluído.
        $stStatus = $db->prepare("SELECT CPG_CODIGO_PK, CPG_STATUS, CPG_VALOR_PAGO
                                    FROM tb_contas_pagar
                                   WHERE CPG_CODIGO_PK IN ($placeholdersC)");
        $stStatus->execute($idsCandidatos);
        $linhasStatus = $stStatus->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $idsExcluir   = [];
        $idsMantidos  = [];
        foreach ($linhasStatus as $ls) {
            $statusUp  = strtoupper((string)($ls['CPG_STATUS'] ?? ''));
            $valorPago = (float)($ls['CPG_VALOR_PAGO'] ?? 0);
            $idAtual   = (int)$ls['CPG_CODIGO_PK'];
            // Bloqueia qualquer parcela que tenha pagamento registrado (PAGO ou parcial).
            if ($statusUp === 'PAGO' || $valorPago > 0) {
                $idsMantidos[] = $idAtual;
            } else {
                $idsExcluir[] = $idAtual;
            }
        }

        // Quando o usuário pediu para excluir só a parcela atual e ela está paga, bloqueia.
        if ($applyScope === 'one' && !$idsExcluir) {
            json_out([
                'ok' => false,
                'msg' => 'Esta parcela está PAGA (ou com pagamento parcial). Reabra a conta antes de excluir.',
            ]);
        }

        // Quando é "todas" e nenhuma está em aberto, devolve aviso explicando.
        if (!$idsExcluir) {
            json_out([
                'ok' => false,
                'msg' => 'Nenhuma parcela em ABERTO encontrada. Parcelas pagas precisam ser reabertas antes de excluir.',
                'ids_mantidos' => $idsMantidos,
            ]);
        }

        $placeholders = implode(',', array_fill(0, count($idsExcluir), '?'));

        try {
            $stRateio = $db->prepare("DELETE FROM tb_rateio_contas_pagar WHERE RCP_CONTA_PAGAR_FK IN ($placeholders)");
            $stRateio->execute($idsExcluir);
        } catch (Throwable $e) {
            // segue mesmo se a tabela de rateio não existir
        }

        $sql = "DELETE FROM tb_contas_pagar WHERE CPG_CODIGO_PK IN ($placeholders)";
        $st = $db->prepare($sql);
        $st->execute($idsExcluir);

        json_out([
            'ok' => true,
            'ids_afetados'   => $idsExcluir,
            'ids_mantidos'   => $idsMantidos,
            'qtd_excluidas'  => count($idsExcluir),
            'qtd_mantidas'   => count($idsMantidos),
        ]);
    }
} catch (Throwable $e) {
    error_log('[contas_a_pagar] ' . $e->getMessage());
    json_out(['ok' => false, 'msg' => 'Erro interno: ' . $e->getMessage()], 500);
}

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Contas a Pagar</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        :root {
            --bs-primary: #2563eb;
            --bs-primary-rgb: 37, 99, 235;
        }

        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f9fafb;
        }

        .page-header {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 1.25rem 0;
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: #111827;
            margin: 0;
        }

        .card {
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 1rem 1.25rem;
        }

        .btn-primary {
            background: var(--bs-primary);
            border-color: var(--bs-primary);
        }

        .btn-primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .badge-success {
            background-color: #10b981;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-weight: 500;
        }

        .badge-warning {
            background-color: #f59e0b;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-weight: 500;
        }

        .badge-danger {
            background-color: #ef4444;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-weight: 500;
        }

        .badge-secondary {
            background-color: #6b7280;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-weight: 500;
        }

        .table {
            font-size: 0.875rem;
        }

        .table thead th {
            background: #f9fafb;
            color: #374151;
            font-weight: 600;
            border-bottom: 2px solid #e5e7eb;
        }

        .table tbody tr:hover {
            background: #f9fafb;
        }

        .kpi-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: .875rem;
            box-shadow: 0 1px 3px rgba(15,23,42,.06);
            padding: .85rem 1rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            transition: box-shadow .2s, transform .2s;
        }
        .kpi-card:hover {
            box-shadow: 0 4px 16px rgba(15,23,42,.07);
            transform: translateY(-1px);
        }

        .kpi-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .kpi-icon.aberto   { background: #fef2f2; color: #dc2626; }
        .kpi-icon.pago     { background: #f0fdf4; color: #16a34a; }
        .kpi-icon.cancelado { background: #f3f4f6; color: #6b7280; }
        .kpi-icon.vencido  { background: #fff7ed; color: #ea580c; }

        .kpi-info { min-width: 0; }

        .kpi-label {
            font-size: .7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #6b7280;
            line-height: 1;
            margin-bottom: .2rem;
        }

        .kpi-value {
            font-size: 1.15rem;
            font-weight: 700;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .kpi-value.aberto   { color: #dc2626; }
        .kpi-value.pago     { color: #16a34a; }
        .kpi-value.cancelado { color: #6b7280; }
        .kpi-value.vencido  { color: #ea580c; }

        @media (max-width: 767.98px) {
            .kpi-row { flex-wrap: wrap !important; }
            .kpi-row > .kpi-card { flex: 1 1 calc(50% - .5rem) !important; min-width: calc(50% - .5rem); }
            .kpi-card { padding: .6rem .7rem; }
            .kpi-icon { width: 32px; height: 32px; font-size: .8rem; border-radius: 8px; }
            .kpi-value { font-size: .95rem; }
            .kpi-label { font-size: .62rem; }
        }
        @media (max-width: 399.98px) {
            .kpi-row > .kpi-card { flex: 1 1 100% !important; min-width: 100%; }
        }

        .mono {
            font-family: ui-monospace, 'Courier New', monospace;
        }

        .modal-header {
            border-bottom: 1px solid #e5e7eb;
        }

        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .form-control,
        .form-select {
            border-color: #d1d5db;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--bs-primary);
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.25);
        }

        .accordion-button:not(.collapsed) {
            background: #f3f4f6;
            color: #111827;
        }

        .autocomplete-container {
            position: relative;
        }

        .autocomplete-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #d1d5db;
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-radius: 0 0 0.375rem 0.375rem;
        }

        .autocomplete-item {
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
        }

        .autocomplete-item:hover {
            background: #f3f4f6;
        }

        .text-success {
            color: #10b981 !important;
        }

        .text-danger {
            color: #ef4444 !important;
        }

        .text-warning {
            color: #f59e0b !important;
        }

        /* Badges de Modo - Fonte Poppins */
        .badge-modo {
            font-family: 'Poppins', system-ui, -apple-system, sans-serif;
            font-weight: 500;
            font-size: 0.75rem;
            letter-spacing: 0.02em;
            padding: 0.35rem 0.65rem;
            border-radius: 0.375rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        /* Botões de ação na tabela */
        .table tbody .btn-sm {
            font-size: 0.8rem;
            padding: 0.35rem 0.65rem;
            white-space: nowrap;
        }

        .table tbody .btn-sm i {
            margin-right: 0.25rem;
        }

        .ocultar {
            display: none;
        }

        #parcela {
            visibility: hidden;
        }
    </style>

</head>

<body data-page="financeiro">
    <div class="d-flex" id="wrapper">

        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">

            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Contas a Pagar</span>

                <div class="collapse navbar-collapse justify-content-end">
                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted">
                            <?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?>
                            (<?= htmlspecialchars($_SESSION['user_perfil'] ?? 'USER') ?>)
                        </span>
                        <a class="btn btn-sm btn-outline-danger" href="logout.php">
                            <i class="fa-solid fa-right-from-bracket me-1"></i>Sair
                        </a>
                    </div>
                </div>
            </nav>

            <div class="py-3">
                <div class="container-fluid">
                    <!-- Cards de Resumo -->
                    <div class="d-flex flex-nowrap gap-2 mb-3 kpi-row" id="statsRow">
                        <div class="kpi-card flex-fill">
                            <div class="kpi-icon" style="background:rgba(37,99,235,.12);color:#2563eb;"><i class="fa-solid fa-cash-register"></i></div>
                            <div class="kpi-info">
                                <div class="kpi-label">Valor Lançado</div>
                                <div class="kpi-value" style="color:#2563eb;" id="statLancado">R$ 0,00</div>
                            </div>
                        </div>
                        <div class="kpi-card flex-fill">
                            <div class="kpi-icon aberto"><i class="fa-solid fa-clock"></i></div>
                            <div class="kpi-info">
                                <div class="kpi-label">Em Aberto</div>
                                <div class="kpi-value aberto" id="statAberto">R$ 0,00</div>
                            </div>
                        </div>
                        <div class="kpi-card flex-fill">
                            <div class="kpi-icon pago"><i class="fa-solid fa-circle-check"></i></div>
                            <div class="kpi-info">
                                <div class="kpi-label">Total Pago</div>
                                <div class="kpi-value pago" id="statPago">R$ 0,00</div>
                            </div>
                        </div>
                        <div class="kpi-card flex-fill">
                            <div class="kpi-icon cancelado"><i class="fa-solid fa-ban"></i></div>
                            <div class="kpi-info">
                                <div class="kpi-label">Cancelado</div>
                                <div class="kpi-value cancelado" id="statCancelado">R$ 0,00</div>
                            </div>
                        </div>
                        <div class="kpi-card flex-fill">
                            <div class="kpi-icon" style="background:rgba(217,119,6,.12);color:#d97706;"><i class="fa-solid fa-arrow-right-arrow-left"></i></div>
                            <div class="kpi-info">
                                <div class="kpi-label">Pgto. Parcial</div>
                                <div class="kpi-value" style="color:#d97706;" id="statParcial">R$ 0,00</div>
                            </div>
                        </div>
                        <div class="kpi-card flex-fill">
                            <div class="kpi-icon vencido"><i class="fa-solid fa-triangle-exclamation"></i></div>
                            <div class="kpi-info">
                                <div class="kpi-label">Vencidos</div>
                                <div class="kpi-value vencido" id="statVencido">R$ 0,00</div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtros e Tabela -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Listagem de Contas</h5>
                            <div class="d-flex gap-2">
                                <button class="btn btn-primary btn-sm" type="button" id="btnNova" title="Novo lançamento">
                                    <i class="bi bi-plus-lg me-1"></i>Novo lançamento
                                </button>
                                <button class="btn btn-warning btn-sm" type="button" id="btnLancamentoRapido" title="Lançamento rápido via IA">
                                    <i class="bi bi-lightning-charge-fill me-1"></i>Lançamento Rápido
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2 mb-2">
                                <div class="col-md-3">
                                    <input type="text" class="form-control" id="txtBusca" placeholder="Buscar por fornecedor, CNPJ, documento...">
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select" id="selStatus">
                                        <option value="TODOS">Todos os Status</option>
                                        <option value="ABERTO">Aberto</option>
                                        <option value="ATRASADO">Atrasado</option>
                                        <option value="PAGO">Pago</option>
                                        <option value="CANCELADO">Cancelado</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select" id="selEmpresa">
                                        <option value="0">Todas as Empresas</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="date" class="form-control" id="dtIni" placeholder="Data inicial">
                                </div>
                                <div class="col-md-2">
                                    <input type="date" class="form-control" id="dtFim" placeholder="Data final">
                                </div>
                                <div class="col-md-1">
                                    <button class="btn btn-success w-100" id="btnExportar" title="Exportar CSV"><i class="bi bi-download"></i></button>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filtrosAvancados" aria-expanded="false" aria-controls="filtrosAvancados">
                                    <i class="bi bi-funnel me-1"></i>Mais filtros
                                </button>
                                <button class="btn btn-sm btn-outline-danger d-none" type="button" id="btnLimparFiltros" title="Limpar todos os filtros">
                                    <i class="bi bi-x-circle me-1"></i>Limpar filtros
                                </button>
                                <div id="chipsFiltros" class="d-flex flex-wrap gap-1"></div>
                            </div>

                            <div class="collapse mb-3" id="filtrosAvancados">
                                <div class="card card-body bg-light py-2">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-2">
                                            <label class="form-label small mb-1">Tipo</label>
                                            <select class="form-select form-select-sm" id="selTipo">
                                                <option value="TODOS">Todos</option>
                                                <option value="D">Despesa (D)</option>
                                                <option value="C">Crédito (C)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small mb-1">Filtrar data por</label>
                                            <select class="form-select form-select-sm" id="selTipoData">
                                                <option value="vencimento">Vencimento</option>
                                                <option value="pagamento">Pagamento</option>
                                                <option value="criacao">Criação</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small mb-1">Valor mínimo</label>
                                            <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" id="valorMin" placeholder="0,00">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small mb-1">Valor máximo</label>
                                            <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" id="valorMax" placeholder="0,00">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width:55px;">ID</th>
                                            <th>Status</th>
                                            <th>Vencimento</th>
                                            <th>Fornecedor</th>
                                            <th>Doc / NF</th>
                                            <th class="text-end">Valor</th>
                                            <th class="text-end">Pago</th>
                                            <th>Parcial / Lote</th>
                                            <th>Complemento / Obs.</th>
                                            <th class="text-center">Liberação</th>
                                            <th style="width:120px;">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbody"></tbody>
                                </table>

                                <div class="d-flex flex-wrap justify-content-between align-items-center mt-3" id="paginacaoWrap">
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPagPrev">&lsaquo; Anterior</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPagNext">Próxima &rsaquo;</button>
                                        <span class="text-muted ms-2" id="pagInfo">Página 1</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <label class="text-muted mb-0" for="selPerPage">Por página</label>
                                        <select class="form-select form-select-sm" style="width:120px" id="selPerPage">
                                            <option value="25">25</option>
                                            <option value="50" selected>50</option>
                                            <option value="100">100</option>
                                            <option value="200">200</option>
                                        </select>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Resumo (visualização) -->
                <div class="modal fade" id="modalResumo" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Resumo da Conta</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <strong>ID:</strong> <span id="rId"></span>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Status:</strong> <span id="rStatus"></span>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <strong>Vencimento:</strong> <span id="rVencimento"></span>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Valor:</strong> <span id="rValor"></span>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <strong>Valor pago:</strong> <span id="rValorPago"></span>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Saldo restante:</strong> <span id="rSaldoRestante"></span>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <strong>Fornecedor:</strong> <span id="rFornecedor"></span>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>CPF/CNPJ:</strong> <span id="rCpfCnpj"></span>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <strong>Parcela:</strong> <span id="rParcela"></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Competência:</strong> <span id="rCompetencia"></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>NF:</strong> <span id="rNf"></span>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <strong>Documento:</strong> <span id="rDoc"></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Complemento:</strong> <span id="rComplemento"></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Banco:</strong> <span id="rBanco"></span>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <strong>Tipo:</strong> <span id="rTipo"></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Forma Pagamento:</strong> <span id="rForma"></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Relacionado:</strong> <span id="rRelacionado"></span>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <strong>Observações:</strong> <span id="rObs"></span>
                                    </div>
                                </div>
                                <h6 class="mb-2">Rateio</h6>
                                <table class="table table-sm table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Conta Contábil</th>
                                            <th>Centro Custo</th>
                                            <th class="text-end">%</th>
                                            <th class="text-end">Valor</th>
                                        </tr>
                                    </thead>
                                    <tbody id="rRateioBody"></tbody>
                                </table>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                                <button class="btn btn-primary" id="btnResumoEditar">Editar</button>
                            </div>
                        </div>
                    </div>
                </div>


                <div class="modal fade" id="modalPagar2" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content">

                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-cash-coin"></i> Pagar Parcela
                                </h5>

                                <button class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>

                                </button>
                            </div>

                            <div class="modal-body">
                                <form id="formPagar2" novalidate>
                                    <input type="hidden" id="pgIdConta" value="">

                                    <div class="row g-3">

                                        <div class="col-md-4">
                                            <label class="form-label">Forma Pag.</label>
                                            <select id="pgFormaPag2" class="form-select" required>
                                                <option value="">Carregando...</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Integral/Parcial</label>
                                            <select id="pgIntegralParcial2" class="form-select">
                                                <option value="INTEGRAL" selected>Integral</option>
                                                <option value="PARCIAL">Parcial</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Pagamento <span class="text-danger">*</span></label>
                                            <input id="pgDataPagamento2" type="date" class="form-control" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Lançamento <span class="text-danger">*</span></label>
                                            <input id="pgLancamento2" class="form-control" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Banco <span class="text-danger">*</span></label>
                                            <select id="pgBanco2" class="form-select" required>
                                                <option value="">Selecione...</option>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Valor da Parcela</label>
                                            <div class="input-group">
                                                <span class="input-group-text">R$</span>
                                                <input id="pgValorTotal2" class="form-control text-end" disabled>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Valor Pago <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text">R$</span>
                                                <input id="pgValorParcial2" class="form-control text-end money-field" inputmode="numeric" autocomplete="off" required>
                                            </div>
                                            <small class="text-muted" id="pgHintParcial2">No modo integral, este valor será igual ao total.</small>
                                            <div class="mt-2 small text-muted">
                                                <i class="bi bi-info-circle me-1"></i>
                                                O saldo bancário é atualizado automaticamente quando este pagamento for marcado como PAGO.
                                            </div>
                                        </div>

                                        <div class="col-md-8">
                                            <label class="form-label">Observações</label>
                                            <textarea id="pgObs2" class="form-control" rows="2"></textarea>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Cheque</label>
                                            <input id="pgCheque2" class="form-control">
                                        </div>

                                    </div>
                                </form>
                            </div>

                            <div class="modal-footer justify-content-end">
                                <button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">
                                    <i class="bi bi-x-circle me-1"></i> Cancelar
                                </button>

                                <button type="button" class="btn btn-success" onclick="salvarPagamentoPagar2()">
                                    <i class="bi bi-check2-circle me-1"></i> Pagar
                                </button>
                            </div>


                        </div>
                    </div>
                </div>


                <!-- MODAL NOVO/EDITAR (LANÇAMENTO) -->
                <div class="modal fade" id="modalPagar" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <div>
                                    <h5 class="modal-title" id="mTitle">Nova conta a pagar</h5>
                                    <div class="text-muted small">Rateio: Plano de Contas + Centro de Custo</div>
                                </div>
                                <button class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                            </div>

                            <div class="modal-body">
                                <input type="hidden" id="mId">
                                <input type="hidden" id="mFornecedorFk">

                                <!-- Modalidade -->
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                    <div class="d-flex flex-column">
                                        <div class="btn-group mode-tabs" role="group" aria-label="Tipo de conta">
                                            <button type="button" class="btn btn-outline-primary active" id="btnModoAVP">À vista / Parcelado</button>
                                        </div>

                                        <!-- Sub-modo (somente quando "À vista / Parcelado" estiver ativo) -->
                                        <div class="mt-2" id="subAVP">
                                            <div class="btn-group" role="group" aria-label="Submodalidade">
                                                <button type="button" class="btn btn-sm btn-outline-secondary active" id="btnSubAVISTA">À vista</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSubPARCELADO">Parcelado</button>
                                            </div>
                                        </div>
                                    </div>


                                    <div class="d-flex align-items-center gap-2" id="parcela">
                                        <div>
                                            <label class="form-label mb-1 small text-muted">Id.</label>
                                            <input id="mIdVisual" class="form-control form-control-sm mono" placeholder="(auto)" disabled>
                                        </div>
                                        <div>
                                            <label class="form-label mb-1 small text-muted">Parcela</label>
                                            <input id="mParcelaInfo" class="form-control form-control-sm mono" placeholder="Ex.: 1/3">
                                        </div>
                                    </div>

                                </div>

                                <ul class="nav nav-tabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" data-bs-toggle="tab" id="tabBtnDados" data-bs-target="#tabDados" type="button" role="tab">
                                            Dados
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" data-bs-toggle="tab" id="tabBtnOutras" data-bs-target="#tabOutras" type="button" role="tab">
                                            Outras Informações
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" data-bs-toggle="tab" id="tabBtnRateio" data-bs-target="#tabRateio" type="button" role="tab">
                                            Rateio
                                        </button>
                                    </li>
                                </ul>

                                <div class="tab-content pt-3">
                                    <!-- DADOS -->
                                    <div class="tab-pane fade show active" id="tabDados" role="tabpanel">

                                        <!-- Campos padrão (À vista / Parcelado) -->
                                        <div id="camposAVP">
                                            <div class="row g-2">
                                                <div class="col-md-3">
                                                    <label class="form-label">Empresa <span class="text-danger">*</span></label>
                                                    <select id="mEmpresa" class="form-select" required>
                                                        <option value="">Selecione...</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label">Fornecedor <span class="text-danger">*</span></label>
                                                    <div class="input-group autocomplete-container">
                                                        <input id="mFornecedor" class="form-control" placeholder="Ex.: Fornecedor Exemplo" autocomplete="off" required>

                                                        <!--
                                            <button class="btn btn-outline-secondary" type="button" title="Adicionar fornecedor (mock)" id="btnAddFornecedor">
                                                <i class="bi bi-plus-lg"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary" type="button" title="Buscar (mock)">
                                                <i class="bi bi-search"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary" type="button" title="Limpar">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            -->

                                                        <div class="autocomplete-results d-none" id="autocompleteFornecedor"></div>
                                                    </div>
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label">CPF/CNPJ</label>
                                                    <input id="mCpfCnpj" class="form-control mono" placeholder="00.000.000/0000-00">
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label">Vencimento <span class="text-danger">*</span></label>
                                                    <input id="mVencimento" type="date" class="form-control" required>
                                                </div>

                                                <div class="col-md-4">
                                                    <label class="form-label">Conta Contábil <span class="text-danger">*</span></label>
                                                    <select id="mContaContabil" class="form-select"></select>
                                                    <div class="form-text">Dica: aqui usamos contas analíticas (lançáveis) do Plano de Contas.</div>
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label">Centro de Custo</label>
                                                    <select id="mCentroCusto" class="form-select"></select>
                                                </div>

                                                <div class="col-md-2">
                                                    <label class="form-label">Banco (pagamento)</label>
                                                    <select id="mBanco" class="form-select"></select>
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label">Valor a pagar <span class="text-danger">*</span></label>
                                                    <input id="mValor"
                                                        type="text"
                                                        class="form-control text-end mono"
                                                        inputmode="numeric"
                                                        autocomplete="off"
                                                        placeholder="0,00"
                                                        required>
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label">Complemento</label>
                                                    <input id="mComplemento" class="form-control" placeholder="Ex.: Referente a serviços, período, etc.">
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label">Tipo</label>
                                                    <select id="mTipo" class="form-select"></select>
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label">Forma de pagamento</label>
                                                    <select id="mFormaPag" class="form-select"></select>
                                                </div>

                                                <!-- Bloco Parcelado -->
                                                <div class="col-12">
                                                    <div class="card border-0 bg-light" id="blocoParcelas" style="display:none;">
                                                        <div class="card-body">
                                                            <div class="row g-2 align-items-end">
                                                                <div class="col-md-3">
                                                                    <label class="form-label mb-1">Parcelas <span class="text-danger">*</span></label>
                                                                    <input id="mParcelas" type="number" min="1" step="1" class="form-control" value="2">
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <label class="form-label mb-1">Primeiro vencimento <span class="text-danger">*</span></label>
                                                                    <input id="mPrimeiroVenc" type="date" class="form-control">
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <label class="form-label mb-1">Próx. vencimentos todo dia</label>
                                                                    <input id="mDiaVenc" type="number" min="1" max="31" step="1" class="form-control" placeholder="Ex.: 7">
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <div class="alert alert-info mb-0 py-2">
                                                                        Parcelado: o sistema gera as parcelas automáticamente
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="col-12" id="multiTitulosWrap">
                                                    <div class="d-flex justify-content-between align-items-end mb-1 flex-wrap gap-2">
                                                        <div>
                                                            <small class="text-muted">Múltiplos títulos no mesmo lançamento (opcional)</small>
                                                            <div class="text-muted" style="font-size:11px">Adicione cada título com seus dados de venc./valor/doc. Ao salvar, todos serão criados.</div>
                                                        </div>
                                                        <button type="button" id="btnIncluirTitulo" class="btn btn-sm btn-outline-primary">
                                                            <i class="bi bi-plus-circle me-1"></i>Incluir título
                                                        </button>
                                                    </div>
                                                    <div id="tabelaTitulosWrap" class="table-responsive border rounded d-none mt-1">
                                                        <table class="table table-sm align-middle mb-0">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th style="width:40px">#</th>
                                                                    <th style="width:110px">Vencimento</th>
                                                                    <th class="text-end" style="width:110px">Valor</th>
                                                                    <th>Documento</th>
                                                                    <th>NF</th>
                                                                    <th style="width:110px">Emissão</th>
                                                                    <th>Complemento</th>
                                                                    <th style="width:40px"></th>
                                                                </tr>
                                                            </thead>
                                                            <tbody id="tbodyTitulos"></tbody>
                                                            <tfoot class="table-light">
                                                                <tr>
                                                                    <th colspan="2" class="text-end">Total dos títulos</th>
                                                                    <th class="text-end mono"><span id="totalTitulosLabel">R$ 0,00</span></th>
                                                                    <th colspan="5"></th>
                                                                </tr>
                                                            </tfoot>
                                                        </table>
                                                    </div>
                                                </div>

                                                <div class="col-12">
                                                    <label class="form-label">Observações</label>
                                                    <textarea id="mObs" class="form-control" rows="3"></textarea>
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label">Competência</label>
                                                    <input id="mCompetencia" type="month" class="form-control">
                                                </div>


                                                <div class="col-md-4" id="parcela">
                                                    <label class="form-label">Entrada (data)</label>
                                                    <input id="mEntrada" type="date" class="form-control">
                                                </div>


                                            </div>
                                        </div>

                                    </div>

                                    <!-- OUTRAS INFORMAÇÕES -->
                                    <div class="tab-pane fade" id="tabOutras" role="tabpanel">
                                        <div class="row g-2">
                                            <div class="col-md-4">
                                                <label class="form-label">NF-e / NFS-e</label>
                                                <input id="mNf" class="form-control mono" placeholder="Ex.: 123456 / NFS-e 0001">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Emissão</label>
                                                <input id="mEmissao" type="date" class="form-control">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Documento (Duplicata / Boleto)</label>
                                                <input id="mDocumento" class="form-control mono" placeholder="Ex.: BOL 000123">
                                            </div>


                                            <div class="col-md-4 ocultar">
                                                <label class="form-label">Unid. Negócio</label>
                                                <select id="mUnidNegocio" class="form-select">
                                                    <option value="">Selecione...</option>
                                                </select>
                                            </div>



                                            <div class="col-md-4">
                                                <label class="form-label">Projeto</label>
                                                <input id="mProjeto" class="form-control" placeholder="(opcional)">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Ignorar no Fluxo de Caixa</label>
                                                <div class="form-check mt-2">
                                                    <input id="mIgnorarFluxo" class="form-check-input" type="checkbox">
                                                    <label class="form-check-label" for="mIgnorarFluxo">Sim</label>
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="alert alert-secondary mb-0">
                                                    Observação: estes campos ajudam conciliação, relatórios e auditoria.
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- RATEIO -->
                                    <div class="tab-pane fade" id="tabRateio" role="tabpanel">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="text-muted small">
                                                Use contas <strong>Analíticas</strong> (lançáveis) do Plano de Contas.
                                            </div>
                                            <button class="btn btn-outline-secondary btn-sm" type="button" id="btnAddLinha">
                                                <i class="bi bi-plus-lg me-1"></i> Adicionar linha
                                            </button>
                                        </div>

                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered align-middle mb-2">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th style="width: 42%;">Conta (Plano de Contas)</th>
                                                        <th style="width: 28%;">Centro de Custo</th>
                                                        <th style="width: 10%;" class="text-end">% </th>
                                                        <th style="width: 15%;" class="text-end">Valor</th>
                                                        <th style="width: 5%;" class="text-center"> </th>
                                                    </tr>
                                                </thead>
                                                <tbody id="tbodyRateio"></tbody>
                                            </table>
                                        </div>

                                        <div class="d-flex justify-content-end gap-4">
                                            <div class="text-muted">Total %: <span class="mono" id="totPerc">0,00</span></div>
                                            <div class="text-muted">Total R$: <span class="mono" id="totValor">0,00</span></div>
                                        </div>

                                        <div class="alert alert-warning mt-2 mb-0">
                                            O rateio é preenchido automaticamente com a <strong>Conta Contábil</strong> e o <strong>Centro de Custo</strong> informados na aba Dados.
                                            Use <em>"Adicionar linha"</em> somente se quiser dividir entre múltiplas contas/centros &mdash; a soma deve fechar <strong>100%</strong>.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer d-flex justify-content-between">
                                <button class="btn btn-outline-danger d-none" id="btnExcluir" type="button">
                                    <i class="bi bi-trash me-1"></i> Excluir
                                </button>

                                <div class="ms-auto d-flex gap-2">
                                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button" id="btnCancelarModal">Cancelar</button>
                                    <button class="btn btn-primary" id="btnSalvar" type="button">
                                        <i class="bi bi-check2 me-1"></i> Salvar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        // Endpoint AJAX SEMPRE aponta para a página atual (contas_pagar.php)
        const CP_AJAX = window.location.pathname + window.location.search;

        function cpAjaxUrl(params) {
            const hasQuery = CP_AJAX.includes('?');
            // garante que vamos anexar parâmetros sem bagunçar
            return CP_AJAX + (hasQuery ? '&' : '?') + params;
        }


        document.addEventListener("DOMContentLoaded", async () => {

            const tbody = document.getElementById("tbody");
            const txtBusca = document.getElementById("txtBusca");
            const selStatus = document.getElementById("selStatus");
            const selEmpresa = document.getElementById("selEmpresa");
            const dtIni = document.getElementById("dtIni");
            const dtFim = document.getElementById("dtFim");
            const selTipo = document.getElementById("selTipo");
            const selTipoData = document.getElementById("selTipoData");
            const valorMin = document.getElementById("valorMin");
            const valorMax = document.getElementById("valorMax");
            const chipsFiltros = document.getElementById("chipsFiltros");
            const btnLimparFiltros = document.getElementById("btnLimparFiltros");
            const btnNova = document.getElementById("btnNova");
            const btnSalvar = document.getElementById("btnSalvar");
            const btnExcluir = document.getElementById("btnExcluir");
            const btnExportar = document.getElementById("btnExportar");

            const statLancado = document.getElementById("statLancado");
            const statAberto = document.getElementById("statAberto");
            const statPago = document.getElementById("statPago");
            const statCancelado = document.getElementById("statCancelado");
            const statVencido = document.getElementById("statVencido");
            const statParcial = document.getElementById("statParcial");

            const mTitle = document.getElementById("mTitle");
            const mId = document.getElementById("mId");
            const mIdVisual = document.getElementById("mIdVisual");
            const mParcelaInfo = document.getElementById("mParcelaInfo");
            const mEmpresa = document.getElementById("mEmpresa");
            const mFornecedor = document.getElementById("mFornecedor");
            const mFornecedorFk = document.getElementById("mFornecedorFk");
            const mCpfCnpj = document.getElementById("mCpfCnpj");
            const mVencimento = document.getElementById("mVencimento");
            const mContaContabil = document.getElementById("mContaContabil");
            const mCentroCusto = document.getElementById("mCentroCusto");
            const mBanco = document.getElementById("mBanco");
            const mValor = document.getElementById("mValor");
            const mComplemento = document.getElementById("mComplemento");
            const mTipo = document.getElementById("mTipo");
            const mFormaPag = document.getElementById("mFormaPag");
            const mObs = document.getElementById("mObs");
            const mCompetencia = document.getElementById("mCompetencia");
            const mEntrada = document.getElementById("mEntrada");
            const mParcelas = document.getElementById("mParcelas");
            const mPrimeiroVenc = document.getElementById("mPrimeiroVenc");
            const mDiaVenc = document.getElementById("mDiaVenc");
            const mNf = document.getElementById("mNf");
            const mEmissao = document.getElementById("mEmissao");
            const mDocumento = document.getElementById("mDocumento");
            const mUnidNegocio = document.getElementById("mUnidNegocio");
            const mProjeto = document.getElementById("mProjeto");
            const mIgnorarFluxo = document.getElementById("mIgnorarFluxo");

            const tbodyRateio = document.getElementById("tbodyRateio");
            const btnAddLinha = document.getElementById("btnAddLinha");
            const totPerc = document.getElementById("totPerc");
            const totValor = document.getElementById("totValor");

            const btnModoAVP = document.getElementById("btnModoAVP");
            const camposAVP = document.getElementById("camposAVP");
            const subAVP = document.getElementById("subAVP");
            const btnSubAVISTA = document.getElementById("btnSubAVISTA");
            const btnSubPARCELADO = document.getElementById("btnSubPARCELADO");
            const blocoParcelas = document.getElementById("blocoParcelas");

            const rId = document.getElementById("rId");
            const rStatus = document.getElementById("rStatus");
            const rVencimento = document.getElementById("rVencimento");
            const rValor = document.getElementById("rValor");
            const rFornecedor = document.getElementById("rFornecedor");
            const rCpfCnpj = document.getElementById("rCpfCnpj");
            const rParcela = document.getElementById("rParcela");
            const rCompetencia = document.getElementById("rCompetencia");
            const rNf = document.getElementById("rNf");
            const rDoc = document.getElementById("rDoc");
            const rComplemento = document.getElementById("rComplemento");
            const rBanco = document.getElementById("rBanco");
            const rTipo = document.getElementById("rTipo");
            const rForma = document.getElementById("rForma");
            const rRelacionado = document.getElementById("rRelacionado");
            const rObs = document.getElementById("rObs");
            const rRateioBody = document.getElementById("rRateioBody");
            const btnResumoEditar = document.getElementById("btnResumoEditar");

            const autocompleteFornecedor = document.getElementById("autocompleteFornecedor");

            let MAIN = "AVP";
            let MODO = "AVISTA";

            // ===== CARREGAR OPÇÕES DOS SELECTS =====
            let PLANO_CONTAS = [];
            let CENTROS_CUSTO = [];
            let EMPRESAS = [];
            let EMPRESA_SELECTED_ID = 0;
            let EMPRESA_SELECTED_NOME = "";
            let BANCOS = [];
            let FORMAS_PAGAMENTO = [];

            async function carregarOpcoes() {
                try {
                    console.log('=== INICIANDO CARREGAMENTO DE OPÇÕES ===');

                    const pcData = await apiGet({
                        acao: 'buscar_plano_contas'
                    });
                    if (pcData.ok && pcData.rows) {
                        PLANO_CONTAS = pcData.rows;
                        atualizarPlanoContasPorEmpresa();
                    }

                    const ccData = await apiGet({
                        acao: 'buscar_centros_custo'
                    });
                    if (ccData.ok && ccData.rows) {
                        CENTROS_CUSTO = ccData.rows;
                        refreshRateioCombos();
                    }

                    const empData = await apiGet({
                        acao: 'buscar_empresas'
                    });
                    if (empData.ok && empData.rows) {
                        EMPRESAS = empData.rows;
                        const opsEmp = '<option value="">Selecione...</option>' +
                            EMPRESAS.map(e => `<option value="${e.id}">${e.nome}</option>`).join('');
                        if (mUnidNegocio) mUnidNegocio.innerHTML = opsEmp;
                        if (mEmpresa) mEmpresa.innerHTML = opsEmp;

                        if (EMPRESA_SELECTED_ID && mUnidNegocio) {
                            mUnidNegocio.value = String(EMPRESA_SELECTED_ID);
                        }
                        if (mUnidNegocio && !mUnidNegocio.value && EMPRESA_SELECTED_NOME) {
                            const opt = Array.from(mUnidNegocio.options).find(o => (o.text || '').trim() === (EMPRESA_SELECTED_NOME || '').trim());
                            if (opt) mUnidNegocio.value = opt.value;
                        }
                        if (mUnidNegocio && !mUnidNegocio.value) {
                            const optDef = Array.from(mUnidNegocio.options).find(o => /EFY\s*CARD\s*LTDA/i.test(o.text || ''));
                            if (optDef) mUnidNegocio.value = optDef.value;
                        }
                    }

                    const banData = await apiGet({
                        acao: 'buscar_bancos'
                    });
                    if (banData.ok && banData.rows) {
                        BANCOS = banData.rows;
                        const opsBanco = '<option value="">Selecione...</option>' +
                            BANCOS.map(b => `<option value="${b.id}">${b.descricao}</option>`).join('');
                        if (mBanco) mBanco.innerHTML = opsBanco;
                    }

                    const fpgData = await apiGet({
                        acao: 'buscar_formas_pagamento'
                    });
                    if (fpgData.ok && fpgData.rows) {
                        FORMAS_PAGAMENTO = fpgData.rows;
                        const opsFormaPag = '<option value="">Selecione...</option>' +
                            FORMAS_PAGAMENTO.map(f => `<option value="${f.id}">${f.descricao}</option>`).join('');
                        if (mFormaPag) mFormaPag.innerHTML = opsFormaPag;
                    }

                    const opData = await apiGet({
                        acao: 'opcoes_sistema'
                    });
                    if (opData.ok && mTipo) {
                        mTipo.innerHTML = '<option value="">Selecione...</option>' +
                            opData.tipos.map(t => `<option value="${t.value}">${t.label}</option>`).join('');
                    }

                    console.log('=== CARREGAMENTO CONCLUÍDO ===');
                } catch (error) {
                    console.error('ERRO FATAL ao carregar opções:', error);
                    console.error('Stack trace:', error.stack);
                    alert('Erro ao carregar opções do sistema. Verifique o console (F12).');
                }
            }

            carregarOpcoes();

            if (mEmpresa) {
                mEmpresa.addEventListener('change', () => {
                    // Atualiza combos de Plano de Contas conforme empresa
                    atualizarPlanoContasPorEmpresa();
                });
            }

            function setMain(val) {
                MAIN = "AVP";
                btnModoAVP.classList.add("active");
                camposAVP.style.display = "";
                subAVP.style.display = "";
            }

            function setSub(val) {
                MODO = val;
                if (val === "AVISTA") {
                    btnSubAVISTA.classList.add("active");
                    btnSubPARCELADO.classList.remove("active");
                    blocoParcelas.style.display = "none";
                } else {
                    btnSubAVISTA.classList.remove("active");
                    btnSubPARCELADO.classList.add("active");
                    blocoParcelas.style.display = "";
                }
            }

            btnModoAVP.addEventListener("click", () => setMain("AVP"));
            btnSubAVISTA.addEventListener("click", () => setSub("AVISTA"));
            btnSubPARCELADO.addEventListener("click", () => setSub("PARCELADO"));

            /* FIZ ESSA ALTERAÇÃO AQUI ---
            async function apiGet(params) {
                const qs = new URLSearchParams(params).toString();
                const r = await fetch(`${apiUrl}?${qs}`);
                return r.json();
            }
                */




            async function apiGet(params) {
                const url = window.cpAjaxUrl(new URLSearchParams(params).toString());
                //console.log("apiGet URL =>", url);
                const r = await fetch(url, {
                    credentials: 'same-origin'
                });
                return await r.json();
            }

            async function apiPost(params, body) {
                const url = window.cpAjaxUrl(new URLSearchParams(params).toString());
                const r = await fetch(url, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify(body),
                    credentials: 'same-origin'
                });
                return await r.json();
            }

            function money(v) {
                const n = Number(v) || 0;
                return n.toLocaleString("pt-BR", {
                    style: "currency",
                    currency: "BRL"
                });
            }

            function formatDate(s) {
                if (!s) return "";
                const d = new Date(s + "T00:00:00");
                return d.toLocaleDateString("pt-BR");
            }

            // Coluna "Parcial / Lote" da listagem.
            // 1) Se a linha pertence a um lote (CPG_LOTE_TITULOS preenchido), mostra "Lote X/N pagos · saldo R$ Y".
            // 2) Senão, se a linha tem pagamento parcial (valor_pago > 0 e < valor), mostra "Parcial · saldo R$ Y".
            // 3) Caso contrário, "-".
            function parcialLoteCell(r) {
                const lote = r.lote_stats;
                if (lote && lote.qtd_total > 0) {
                    const saldo = Number(lote.saldo_restante || 0);
                    const cls = saldo > 0.005 ? 'text-warning' : 'text-success';
                    const ico = saldo > 0.005 ? 'bi-stack' : 'bi-check2-all';
                    return `<span class="badge bg-info-subtle text-info-emphasis"><i class="bi ${ico} me-1"></i>Lote</span>`
                         + ` <span class="text-muted">${lote.qtd_pagos}/${lote.qtd_total} pagos</span>`
                         + `<br><small class="${cls} mono">saldo R$ ${money(saldo)}</small>`;
                }
                const valor = Number(r.valor || 0);
                const pago = Number(r.valor_pago || 0);
                if (pago > 0.005 && pago + 0.005 < valor) {
                    const saldo = Math.max(0, valor - pago);
                    return `<span class="badge bg-warning-subtle text-warning-emphasis"><i class="bi bi-hourglass-split me-1"></i>Parcial</span>`
                         + `<br><small class="text-warning mono">saldo R$ ${money(saldo)}</small>`;
                }
                return '<span class="text-muted">-</span>';
            }

            function escapeHtml(s) {
                return String(s ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function badgeStatus(s) {
                const m = {
                    "ABERTO": "badge-warning",
                    "PAGO": "badge-success",
                    "CANCELADO": "badge-secondary",
                    "ATRASADO": "badge-danger"
                };
                return m[s] || "badge-secondary";
            }

            let PAG_PAGE = 1;
            let PAG_PER_PAGE = 50;

            async function carregarEmpresas() {
                if (!selEmpresa) return;
                const data = await apiGet({
                    acao: "listar_empresas"
                });
                if (!data || !data.ok) return;

                const opts = (data.rows || []).map(e => `<option value="${e.id}">${e.nome}</option>`).join('');
                selEmpresa.innerHTML = `<option value="0">Todas as Empresas</option>` + opts;
            }

            function coletarFiltros() {
                return {
                    q: txtBusca.value || "",
                    status: selStatus.value || "TODOS",
                    ini: dtIni.value || "",
                    fim: dtFim.value || "",
                    empresa: (selEmpresa ? (selEmpresa.value || "0") : "0"),
                    tipo: (selTipo ? (selTipo.value || "TODOS") : "TODOS"),
                    tipo_data: (selTipoData ? (selTipoData.value || "vencimento") : "vencimento"),
                    valor_min: (valorMin ? (valorMin.value || "") : ""),
                    valor_max: (valorMax ? (valorMax.value || "") : "")
                };
            }

            const FILTROS_LS_KEY = "cp_filtros_v1";
            function salvarFiltros() {
                try { localStorage.setItem(FILTROS_LS_KEY, JSON.stringify(coletarFiltros())); } catch(e) {}
            }
            function carregarFiltrosSalvos() {
                try {
                    const raw = localStorage.getItem(FILTROS_LS_KEY);
                    if (!raw) return null;
                    return JSON.parse(raw);
                } catch(e) { return null; }
            }

            function renderChipsFiltros() {
                const f = coletarFiltros();
                const chips = [];
                if (f.q)             chips.push({label: 'Busca: "' + f.q + '"', clear: () => { txtBusca.value = ""; }});
                if (f.status && f.status !== "TODOS") chips.push({label: 'Status: ' + f.status, clear: () => { selStatus.value = "TODOS"; }});
                if (f.empresa && f.empresa !== "0") {
                    const opt = selEmpresa ? selEmpresa.options[selEmpresa.selectedIndex] : null;
                    chips.push({label: 'Empresa: ' + (opt ? opt.text : f.empresa), clear: () => { selEmpresa.value = "0"; }});
                }
                if (f.tipo && f.tipo !== "TODOS") chips.push({label: 'Tipo: ' + (f.tipo === 'D' ? 'Despesa' : 'Crédito'), clear: () => { selTipo.value = "TODOS"; }});
                if (f.ini)           chips.push({label: 'De: ' + f.ini, clear: () => { dtIni.value = ""; }});
                if (f.fim)           chips.push({label: 'Até: ' + f.fim, clear: () => { dtFim.value = ""; }});
                if (f.ini || f.fim) {
                    const labelData = f.tipo_data === 'pagamento' ? 'pagamento' : (f.tipo_data === 'criacao' ? 'criação' : 'vencimento');
                    chips.push({label: 'Por data de: ' + labelData, clear: () => { selTipoData.value = "vencimento"; }});
                }
                if (f.valor_min)     chips.push({label: 'Valor ≥ ' + f.valor_min, clear: () => { valorMin.value = ""; }});
                if (f.valor_max)     chips.push({label: 'Valor ≤ ' + f.valor_max, clear: () => { valorMax.value = ""; }});

                if (!chips.length) {
                    chipsFiltros.innerHTML = "";
                    btnLimparFiltros.classList.add("d-none");
                    return;
                }
                btnLimparFiltros.classList.remove("d-none");
                chipsFiltros.innerHTML = "";
                chips.forEach((c, i) => {
                    const el = document.createElement("span");
                    el.className = "badge bg-secondary-subtle text-secondary-emphasis border d-inline-flex align-items-center gap-1";
                    el.style.cursor = "pointer";
                    el.title = "Remover este filtro";
                    el.innerHTML = c.label + ' <i class="bi bi-x"></i>';
                    el.addEventListener("click", () => {
                        c.clear();
                        PAG_PAGE = 1;
                        salvarFiltros();
                        renderChipsFiltros();
                        listar();
                    });
                    chipsFiltros.appendChild(el);
                });
            }

            function limparTodosFiltros() {
                txtBusca.value = "";
                selStatus.value = "TODOS";
                if (selEmpresa) selEmpresa.value = "0";
                if (selTipo) selTipo.value = "TODOS";
                if (selTipoData) selTipoData.value = "vencimento";
                dtIni.value = "";
                dtFim.value = "";
                if (valorMin) valorMin.value = "";
                if (valorMax) valorMax.value = "";
                PAG_PAGE = 1;
                salvarFiltros();
                renderChipsFiltros();
                listar();
            }

            async function listar() {
                salvarFiltros();
                renderChipsFiltros();

                const f = coletarFiltros();
                const data = await apiGet(Object.assign({
                    acao: "listar",
                    page: PAG_PAGE,
                    per_page: PAG_PER_PAGE
                }, f));

                if (!data.ok) {
                    tbody.innerHTML = `<tr><td colspan="11" class="text-center text-danger">Erro ao carregar</td></tr>`;
                    return;
                }

                if (statLancado) statLancado.textContent = money(data.total_lancado || 0);
                statAberto.textContent = money(data.total_aberto || 0);
                statPago.textContent = money(data.total_pago || 0);
                statCancelado.textContent = money(data.total_cancelado || 0);
                statVencido.textContent = money(data.total_vencido || 0);
                if (statParcial) statParcial.textContent = money(data.total_parcial || 0);

                renderPaginacao(data);


                const rows = data.rows || [];
                if (!rows.length) {
                    tbody.innerHTML = `<tr><td colspan="11" class="text-center text-muted">Nenhuma conta encontrada</td></tr>`;
                    return;
                }

                tbody.innerHTML = rows.map(r => {
                    const hoje = new Date().toISOString().split("T")[0];
                    const venc = r.vencimento || "";
                    let st = r.status || "";
                    if (st === "ABERTO" && venc < hoje) st = "ATRASADO";

                    const aut = String(r.autorizacao_status || 'PENDENTE').toUpperCase();
                    const autBadge = aut === 'AUTORIZADO'
                        ? '<span class="badge bg-success" style="font-size:.7rem"><i class="bi bi-check-circle me-1"></i>Liberado</span>'
                        : '<span class="badge bg-danger" style="font-size:.7rem"><i class="bi bi-lock me-1"></i>Aguardando</span>';

                    const docNf = [r.documento, r.nf].filter(Boolean).join(' / ') || '-';

                    return `
          <tr style="cursor:pointer;" onclick="abrirResumo(${r.id})">
            <td class="mono">${r.id}</td>
            <td><span class="badge ${badgeStatus(st)}">${st}</span></td>
            <td class="mono">${formatDate(venc)}${r.parcela_info && r.parcela_info !== '00/00' ? '<br><small class="text-muted">'+r.parcela_info+'</small>' : ''}</td>
            <td>${r.fornecedor||"-"}${r.fornecedor_cnpj ? '<br><small class="text-muted mono">'+r.fornecedor_cnpj+'</small>' : ''}</td>
            <td class="mono">${docNf}</td>
            <td class="text-end mono">${money(r.valor)}</td>
            <td class="text-end mono">${r.valor_pago > 0 ? money(r.valor_pago) + (r.data_pagamento ? '<br><small class="text-muted">'+formatDate(r.data_pagamento.slice(0,10))+'</small>' : '') : '-'}</td>
            <td class="small">${parcialLoteCell(r)}</td>
            <td class="small" style="white-space:pre-wrap;word-break:break-word;">${
              r.complemento || r.obs
                ? [r.complemento ? escapeHtml(r.complemento) : null, r.obs ? '<span class="text-muted">'+escapeHtml(r.obs)+'</span>' : null].filter(Boolean).join('<br>')
                : '-'
            }</td>
            <td class="text-center">${autBadge}</td>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-outline-primary me-1"
  onclick="event.stopPropagation(); (async()=>{ const ok = await window.verificarPodeEditar(${r.id}); if(ok) abrirEdicao(${r.id}); })();"
  title="Editar">
                <i class="bi bi-pencil"></i>
              </button>
              <button class="btn btn-sm btn-outline-secondary me-1"
  onclick="event.stopPropagation(); replicarLancamento(${r.id});"
  title="Replicar lançamento">
                <i class="bi bi-files"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger me-1"
  onclick="event.stopPropagation(); (async()=>{ const ok = await window.verificarStatusParcela(${r.id}); if(ok) await excluirLancamento(${r.id}); })();"
  title="Excluir">
                <i class="bi bi-trash"></i>
              </button>
              <button class="btn btn-sm btn-outline-success"
  onclick="event.stopPropagation(); abrirModalPagar2(${r.id});"
  title="Baixar / Pagar">
  <i class="bi bi-cash-coin"></i>
</button>
              ${st === 'PAGO' ? `<button class="btn btn-sm btn-outline-warning ms-1"
  onclick="event.stopPropagation(); abrirReabrirConta(${r.id});"
  title="Reabrir conta (requer senha ADMIN)">
  <i class="bi bi-arrow-counterclockwise"></i>
</button>` : ''}
            </td>
          </tr>
        `;
                }).join("");
            }

            // ✅ AUTOCOMPLETE FORNECEDOR
            let debounceTimer = null;
            mFornecedor.addEventListener("input", () => {
                clearTimeout(debounceTimer);
                const q = mFornecedor.value.trim();
                if (!q) {
                    autocompleteFornecedor.classList.add("d-none");
                    return;
                }
                debounceTimer = setTimeout(async () => {
                    const data = await apiGet({
                        acao: "buscar_fornecedor",
                        q,
                        limit: 10
                    });
                    if (!data.ok || !data.rows || !data.rows.length) {
                        autocompleteFornecedor.classList.add("d-none");
                        return;
                    }
                    autocompleteFornecedor.innerHTML = data.rows.map(f => `
          <div class="autocomplete-item" data-id="${f.FOR_CODIGO_PK}" data-razao="${(f.FOR_RAZAO_SOCIAL||"").replaceAll('"','&quot;')}" data-fantasia="${(f.FOR_NOME_FANTASIA||"").replaceAll('"','&quot;')}" data-cnpj="${(f.FOR_CNPJ||"").replaceAll('"','&quot;')}">
  <strong>${f.FOR_NOME_FANTASIA||f.FOR_RAZAO_SOCIAL||""}</strong><br>
            <small class="text-muted">${f.FOR_CNPJ||""}</small>
          </div>
        `).join("");
                    autocompleteFornecedor.classList.remove("d-none");

                    document.querySelectorAll(".autocomplete-item").forEach(item => {
                        item.addEventListener("click", () => {
                            const id = item.getAttribute("data-id");
                            const fantasia = item.getAttribute("data-fantasia") || item.getAttribute("data-razao");
                            const cnpj = item.getAttribute("data-cnpj");
                            setFornecedorSel(id, fantasia, cnpj);
                            autocompleteFornecedor.classList.add("d-none");
                        });
                    });
                }, 300);
            });

            function setFornecedorSel(id, razao, cnpj) {
                mFornecedorFk.value = id || "";
                mFornecedor.value = razao || "";
                mCpfCnpj.value = cnpj || "";
            }
            document.addEventListener("click", (e) => {
                if (!e.target.closest(".autocomplete-container")) {
                    autocompleteFornecedor.classList.add("d-none");
                }
            });

            // ====== Múltiplos títulos (Briefing 14) ======
            // Lista de títulos acumulados antes de salvar. Cada item = {vencimento, valor, documento, nf, emissao, complemento}.
            // Ao salvar, são submetidos um a um reusando a action `salvar` (modo AVISTA, 1 parcela cada),
            // herdando os campos compartilhados do formulário (empresa, fornecedor, conta contábil, banco etc.).
            let titulosLote = [];

            function renderTitulos() {
                const tbody = document.getElementById("tbodyTitulos");
                const wrap  = document.getElementById("tabelaTitulosWrap");
                const total = document.getElementById("totalTitulosLabel");
                if (!tbody || !wrap) return;

                if (!titulosLote.length) {
                    wrap.classList.add("d-none");
                    tbody.innerHTML = "";
                    if (total) total.textContent = "R$ 0,00";
                    return;
                }
                wrap.classList.remove("d-none");
                tbody.innerHTML = titulosLote.map((t, i) => `
                    <tr>
                        <td class="mono">${i + 1}</td>
                        <td class="mono small">${t.vencimento ? formatDate(t.vencimento) : '-'}</td>
                        <td class="text-end mono">${money(Number(t.valor || 0))}</td>
                        <td class="small">${escapeHtml(t.documento || '-')}</td>
                        <td class="small">${escapeHtml(t.nf || '-')}</td>
                        <td class="mono small">${t.emissao ? formatDate(t.emissao) : '-'}</td>
                        <td class="small">${escapeHtml(t.complemento || '-')}</td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-link text-danger p-0" data-rem-titulo="${i}" title="Remover título">
                                <i class="bi bi-x-circle"></i>
                            </button>
                        </td>
                    </tr>
                `).join("");

                const soma = titulosLote.reduce((acc, t) => acc + Number(t.valor || 0), 0);
                if (total) total.textContent = "R$ " + money(soma);
            }

            function incluirTituloAtual() {
                // Captura os campos variáveis do formulário, valida e empurra pra lista.
                const venc  = mVencimento.value || "";
                const valor = moedaToNumber(mValor.value || "");
                if (!venc) {
                    Swal.fire({ icon: 'warning', title: 'Vencimento obrigatório', text: 'Informe o vencimento do título antes de incluir.' });
                    mVencimento.focus();
                    return false;
                }
                if (!(valor > 0)) {
                    Swal.fire({ icon: 'warning', title: 'Valor inválido', text: 'Informe um valor maior que zero antes de incluir o título.' });
                    mValor.focus();
                    return false;
                }
                titulosLote.push({
                    vencimento: venc,
                    valor: valor,
                    documento: mDocumento.value || "",
                    nf: mNf.value || "",
                    emissao: mEmissao.value || "",
                    complemento: mComplemento.value || "",
                });
                // Limpa só os campos por-título; os compartilhados (empresa, fornecedor, conta contábil etc.) ficam.
                mVencimento.value = "";
                mValor.value = "";
                mDocumento.value = "";
                mNf.value = "";
                mEmissao.value = "";
                mComplemento.value = "";
                renderTitulos();
                mVencimento.focus();
                return true;
            }

            // Delegação dos cliques no botão e na tabela
            document.addEventListener("click", (ev) => {
                const btn = ev.target.closest("#btnIncluirTitulo");
                if (btn) { incluirTituloAtual(); return; }
                const rem = ev.target.closest("[data-rem-titulo]");
                if (rem) {
                    const idx = parseInt(rem.dataset.remTitulo, 10);
                    if (!isNaN(idx)) { titulosLote.splice(idx, 1); renderTitulos(); }
                }
            });

            function limparModal() {
                mTitle.textContent = "Nova conta a pagar";
                btnExcluir.classList.add("d-none");
                mId.value = "";
                mIdVisual.value = "";
                mParcelaInfo.value = "";
                titulosLote = [];
                renderTitulos();
                document.getElementById("multiTitulosWrap")?.classList.remove("d-none");
                if (mEmpresa) {
                    mEmpresa.value = "";
                }
                setMain("AVP");
                setSub("AVISTA");
                setFornecedorSel(null, "", "");
                mVencimento.value = "";
                mContaContabil.value = "";
                if (mCentroCusto) mCentroCusto.value = "";
                mBanco.value = "";
                mValor.value = "";
                mComplemento.value = "";
                mTipo.value = "";
                mFormaPag.value = "";
                mObs.value = "";
                mCompetencia.value = "";
                mEntrada.value = "";
                mParcelas.value = 2;
                mPrimeiroVenc.value = "";
                mDiaVenc.value = "";
                mNf.value = "";
                mEmissao.value = "";
                mDocumento.value = "";
                mUnidNegocio.value = "";
                mProjeto.value = "";
                mIgnorarFluxo.checked = false;
                tbodyRateio.innerHTML = "";
                atualizarPlanoContasPorEmpresa();
                addLinhaRateio({
                    conta_id: Number(mContaContabil.value || 0),
                    cc_id: Number((mCentroCusto && mCentroCusto.value) || 0),
                    perc: 100,
                    valor: moedaToNumber(mValor.value || '0')
                });
                atualizarTotaisRateio();
            }

            function addLinhaRateio(obj = {}) {
                const tr = document.createElement("tr");

                // Criar options para Plano de Contas
                const planoContasOpts = '<option value="">Selecione...</option>' +
                    (getPlanoContasFiltradas() || []).map(c =>
                        `<option value="${c.id}" ${c.id == (obj.conta_id || '') ? 'selected' : ''}>${c.nome}</option>`
                    ).join('');

                // Criar options para Centro de Custo
                const centroCustoOpts = '<option value="">Selecione...</option>' +
                    (CENTROS_CUSTO || []).map(cc =>
                        `<option value="${cc.id}" ${cc.id == (obj.cc_id || '') ? 'selected' : ''}>${cc.nome}</option>`
                    ).join('');

                tr.innerHTML = `
        <td><select class="form-select form-select-sm r-conta">${planoContasOpts}</select></td>
        <td><select class="form-select form-select-sm r-cc">${centroCustoOpts}</select></td>
        <td><input type="number" step="0.01" class="form-control form-control-sm text-end r-perc" value="${obj.perc||0}"></td>
        <td><input type="text" class="form-control form-control-sm text-end mono money-field r-valor" inputmode="numeric" autocomplete="off" value="${numberToMoeda(obj.valor||0)}"></td>
        <td class="text-center">
          <button type="button" class="btn btn-sm btn-danger btnRmvRateio">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      `;
                tbodyRateio.appendChild(tr);
                bindMascaraMoeda(tr.querySelectorAll(".money-field"));

                const percInput = tr.querySelector(".r-perc");
                const valorInput = tr.querySelector(".r-valor");

                // Quando digita %, calcula valor
                percInput.addEventListener("input", () => {
                    const perc = Number(percInput.value || 0);
                    const total = moedaToNumber(mValor.value);
                    const valor = (perc / 100) * total;
                    valorInput.value = numberToMoeda(valor);
                    atualizarTotaisRateio();
                });

                // Quando digita valor, calcula %
                valorInput.addEventListener("input", () => {
                    const valor = moedaToNumber(valorInput.value);
                    const total = moedaToNumber(mValor.value);
                    const perc = total > 0 ? (valor / total) * 100 : 0;
                    percInput.value = perc.toFixed(2);
                    atualizarTotaisRateio();
                });

                tr.querySelector(".btnRmvRateio").addEventListener("click", () => {
                    if (tbodyRateio.children.length > 1) {
                        tr.remove();
                        atualizarTotaisRateio();
                    }
                });
            }


            // Plano de contas filtrado pela empresa selecionada no modal
            function getPlanoContasFiltradas() {
                const empId = (mEmpresa && mEmpresa.value) ? Number(mEmpresa.value) : 0;
                if (!empId) return [];
                return (PLANO_CONTAS || []).filter(c => {
                    const fk = Number(c.empresa_fk || 0);
                    return fk === 0 || fk === empId;
                });
            }

            async function ensureEmpresasLoaded() {
                try {
                    // Se já tem opções carregadas, não faz nada
                    if (mEmpresa && mEmpresa.options && mEmpresa.options.length > 1) return;

                    const empData = await apiGet({
                        acao: 'buscar_empresas'
                    });
                    if (empData && empData.ok && empData.rows) {
                        EMPRESAS = empData.rows;

                        const opsEmp = '<option value="">Selecione...</option>' +
                            EMPRESAS.map(e => `<option value="${e.id}">${e.nome}</option>`).join('');

                        // Filtro (Filial/Unidade)
                        if (mUnidNegocio) mUnidNegocio.innerHTML = opsEmp;

                        // Folha (Filial/Unidade)

                        // Modal AVISTA/PARCELADO (Empresa)
                        if (mEmpresa) mEmpresa.innerHTML = opsEmp;
                    }
                } catch (e) {
                    console.warn('ensureEmpresasLoaded falhou:', e);
                }
            }


            function atualizarPlanoContasPorEmpresa() {
                if (!mContaContabil) return;
                const lista = getPlanoContasFiltradas();
                const ops = '<option value="">Selecione...</option>' + lista.map(c => `<option value="${c.id}">${c.nome}</option>`).join('');
                mContaContabil.innerHTML = ops;

                // Centro de custo (aba Dados): popula com mesma lista usada no rateio
                if (mCentroCusto) {
                    const ccVal = mCentroCusto.value;
                    const ccOps = '<option value="">Selecione...</option>' +
                        (CENTROS_CUSTO || []).map(cc => `<option value="${cc.id}">${cc.nome}</option>`).join('');
                    mCentroCusto.innerHTML = ccOps;
                    if (ccVal) mCentroCusto.value = ccVal;
                }

                // Atualiza linhas de rateio também
                refreshRateioCombos();
            }


            // Recarrega as opções dos selects do RATEIO (linhas já existentes)
            function refreshRateioCombos() {
                try {
                    const contaOpts = '<option value="">Selecione...</option>' +
                        (getPlanoContasFiltradas() || []).map(c => `<option value="${c.id}">${c.nome}</option>`).join('');
                    const ccOpts = '<option value="">Selecione...</option>' +
                        (CENTROS_CUSTO || []).map(cc => `<option value="${cc.id}">${cc.nome}</option>`).join('');

                    tbodyRateio.querySelectorAll("tr").forEach(tr => {
                        const selConta = tr.querySelector(".r-conta");
                        const selCc = tr.querySelector(".r-cc");
                        const contaVal = selConta ? selConta.value : "";
                        const ccVal = selCc ? selCc.value : "";

                        if (selConta) {
                            selConta.innerHTML = contaOpts;
                            if (contaVal) selConta.value = contaVal;
                        }
                        if (selCc) {
                            selCc.innerHTML = ccOpts;
                            if (ccVal) selCc.value = ccVal;
                        }
                    });
                } catch (e) {
                    console.warn("refreshRateioCombos falhou:", e);
                }
            }

            function atualizarTotaisRateio() {
                let sumPerc = 0;
                let sumValor = 0;
                tbodyRateio.querySelectorAll("tr").forEach(tr => {
                    sumPerc += Number(tr.querySelector(".r-perc").value || 0);
                    sumValor += moedaToNumber(tr.querySelector(".r-valor").value);
                });
                totPerc.textContent = sumPerc.toFixed(2);
                totValor.textContent = sumValor.toFixed(2);
            }

            btnAddLinha.addEventListener("click", () => {
                addLinhaRateio();
                atualizarTotaisRateio();
            });

            // Sincroniza a única linha do Rateio com a aba Dados (Conta Contábil / Centro / Valor).
            // Se houver mais de uma linha, respeita a edição manual do usuário.
            function sincronizarRateioComDados() {
                const linhas = tbodyRateio.querySelectorAll("tr");
                if (linhas.length !== 1) return;
                const tr = linhas[0];
                const contaSel = tr.querySelector(".r-conta");
                const ccSel = tr.querySelector(".r-cc");
                const percInp = tr.querySelector(".r-perc");
                const valorInp = tr.querySelector(".r-valor");
                if (contaSel && mContaContabil.value) contaSel.value = mContaContabil.value;
                if (ccSel && mCentroCusto && mCentroCusto.value) ccSel.value = mCentroCusto.value;
                if (percInp) percInp.value = 100;
                if (valorInp) valorInp.value = numberToMoeda(moedaToNumber(mValor.value || '0'));
                atualizarTotaisRateio();
            }
            [mContaContabil, mCentroCusto, mValor].forEach(el => {
                if (!el) return;
                el.addEventListener("change", sincronizarRateioComDados);
                if (el === mValor) el.addEventListener("input", sincronizarRateioComDados);
            });

            // Atualiza rateio quando valor total mudar
            mValor.addEventListener("input", () => {
                tbodyRateio.querySelectorAll("tr").forEach(tr => {
                    const percInput = tr.querySelector(".r-perc");
                    const valorInput = tr.querySelector(".r-valor");
                    const perc = Number(percInput.value || 0);
                    const total = moedaToNumber(mValor.value);
                    const valor = (perc / 100) * total;
                    valorInput.value = numberToMoeda(valor);
                });
                atualizarTotaisRateio();
            });

            function getRateioFromUI() {
                const arr = [];
                tbodyRateio.querySelectorAll("tr").forEach(tr => {
                    const contaId = tr.querySelector(".r-conta").value;
                    const ccId = tr.querySelector(".r-cc").value;
                    const perc = Number(tr.querySelector(".r-perc").value || 0);
                    const valor = moedaToNumber(tr.querySelector(".r-valor").value);
                    arr.push({
                        conta_id: contaId,
                        cc_id: ccId,
                        perc,
                        valor
                    });
                });
                return arr;
            }

            // ✅ CORRIGIDO: Usar Bootstrap 5 Modal API
            function formatDateToMonth(dateStr) {
                if (!dateStr) return "";
                // Se já está no formato YYYY-MM, retorna
                if (/^\d{4}-\d{2}$/.test(dateStr)) return dateStr;
                // Se está no formato YYYY-MM-DD, extrai YYYY-MM
                if (/^\d{4}-\d{2}-\d{2}/.test(dateStr)) {
                    return dateStr.substring(0, 7);
                }
                return dateStr;
            }

            function formatCompetencia(dateStr) {
                if (!dateStr) return "-";

                // Extrair ano e mês
                let year, month;
                if (/^\d{4}-\d{2}$/.test(dateStr)) {
                    [year, month] = dateStr.split('-');
                } else if (/^\d{4}-\d{2}-\d{2}/.test(dateStr)) {
                    [year, month] = dateStr.substring(0, 7).split('-');
                } else {
                    return dateStr;
                }

                const meses = [
                    'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'
                ];

                const monthIndex = parseInt(month, 10) - 1;
                if (monthIndex >= 0 && monthIndex < 12) {
                    return `${meses[monthIndex]} de ${year}`;
                }

                return dateStr;
            }

            function formatTipo(tipo) {
                if (!tipo) return "-";
                if (tipo === "D") return "Débito";
                if (tipo === "C") return "Crédito";
                return tipo;
            }

            function modalShow(id) {
                const modalEl = document.getElementById(id);
                if (!modalEl) return;
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            }

            function modalHide(id) {
                const modalEl = document.getElementById(id);
                if (!modalEl) return;
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }

            window.abrirResumo = async function(id) {
                const data = await apiGet({
                    acao: "obter",
                    id
                });
                if (!data.ok) {
                    alert(data.msg || "Erro");
                    return;
                }
                const r = data.row;

                const hoje = new Date().toISOString().split("T")[0];
                const venc = r.vencimento || "";
                let st = r.status || "";
                if (st === "ABERTO" && venc < hoje) st = "ATRASADO";

                rId.textContent = r.id;
                rStatus.innerHTML = `<span class="badge ${badgeStatus(st)}">${st}</span>`;
                rVencimento.textContent = formatDate(r.vencimento);
                rValor.textContent = money(r.valor);
                const _rValorPago = document.getElementById("rValorPago");
                const _rSaldoRest = document.getElementById("rSaldoRestante");
                const _vp = Number(r.valor_pago || 0);
                const _sr = Math.max(0, Number(r.valor || 0) - _vp);
                if (_rValorPago) _rValorPago.textContent = _vp > 0 ? money(_vp) : "—";
                if (_rSaldoRest) _rSaldoRest.textContent = money(_sr);
                rFornecedor.textContent = r.fornecedor || "-";
                rCpfCnpj.textContent = r.cpf_cnpj || "-";
                rParcela.textContent = r.parcela_info || "-";
                rCompetencia.textContent = formatCompetencia(r.competencia);

                rNf.textContent = r.nf || "-";
                rDoc.textContent = r.documento || "-";
                rComplemento.textContent = r.complemento || "-";

                // Usa banco_descricao se disponível, senão usa o código
                rBanco.textContent = r.banco_descricao || r.banco || "-";

                // Formata tipo D/C para Débito/Crédito
                rTipo.textContent = formatTipo(r.tipo);

                // Usa forma_pag_descricao se disponível, senão usa o código
                rForma.textContent = r.forma_pag_descricao || r.forma_pag || "-";

                rRelacionado.textContent = r.relacionado || "-";
                rObs.textContent = r.obs || "-";

                const rateio = Array.isArray(r.rateio) ? r.rateio : [];
                rRateioBody.innerHTML = rateio.length ? rateio.map(x => `
        <tr>
          <td class="mono">${x.conta_descricao || (x.conta_codigo ? x.conta_codigo : "-")}</td>
          <td class="mono">${x.cc_nome || (x.cc_codigo ? x.cc_codigo : (x.cc_id || "-"))}</td>
          <td class="text-end mono">${x.perc ? x.perc.toFixed(2) + '%' : '-'}</td>
          <td class="text-end mono">${money(x.valor || 0)}</td>
        </tr>
      `).join("") : `<tr><td colspan="4" class="text-muted text-center">Sem rateio.</td></tr>`;

                btnResumoEditar.onclick = async (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    const openId = Number(id || 0);
                    if (!openId) return;

                    try {
                        const ok = await window.verificarPodeEditar(openId);
                        if (ok) {
                            abrirEdicao(openId);
                        }
                    } catch (err) {
                        console.error(err);
                    }
                };
                modalShow("modalResumo");
            }

            window.abrirEdicao = async function(id) {
                console.log('abrirEdicao chamada com id:', id);
                limparModal();
                const data = await apiGet({
                    acao: "obter",
                    id
                });
                console.log('Dados recebidos:', data);
                if (!data.ok) {
                    alert(data.msg || "Erro");
                    return;
                }
                const r = data.row;

                mTitle.textContent = "Editar conta a pagar";
                btnExcluir.classList.remove("d-none");
                // Múltiplos títulos só fazem sentido em "Nova conta" — esconde durante edição.
                document.getElementById("multiTitulosWrap")?.classList.add("d-none");

                mId.value = r.id;
                mIdVisual.value = r.id;

                setMain("AVP");
                setSub(r.modo || "AVISTA");

                mParcelaInfo.value = r.parcela_info || "";

                await ensureEmpresasLoaded();

                if (mEmpresa) {
                    mEmpresa.value = r.empresa_fk ? String(r.empresa_fk) : (r.unid_negocio_id ? String(r.unid_negocio_id) : "");

                    if (!mEmpresa.value) {
                        const nome = (r.unid_negocio_nome || r.unid_negocio_raw || "").trim();
                        if (nome) {
                            const opt = Array.from(mEmpresa.options).find(o => (o.textContent || "").trim() === nome);
                            if (opt) mEmpresa.value = opt.value;
                        }
                    }
                }

                atualizarPlanoContasPorEmpresa();
                setFornecedorSel(r.fornecedor_fk || null, (r.fornecedor_fantasia || r.fornecedor || ""), (r.fornecedor_cnpj || ""));
                mCpfCnpj.value = r.cpf_cnpj || "";
                mVencimento.value = r.vencimento || "";
                mContaContabil.value = (r.plano_contas_fk ? String(r.plano_contas_fk) : (r.conta_contabil ? String(r.conta_contabil) : ""));
                if (mCentroCusto) mCentroCusto.value = r.centro_custo_fk ? String(r.centro_custo_fk) : "";
                mBanco.value = r.banco || "";
                mValor.value = numberToMoeda(r.valor || 0);
                mComplemento.value = r.complemento || "";
                mTipo.value = r.tipo || "";
                mFormaPag.value = r.forma_pag || "";
                mObs.value = r.obs || "";
                mCompetencia.value = formatDateToMonth(r.competencia || "");
                mEntrada.value = r.entrada || "";
                mParcelas.value = r.parcelas || 1;
                mPrimeiroVenc.value = r.primeiro_venc || "";
                mDiaVenc.value = r.dia_venc || "";
                mNf.value = r.nf || "";
                mEmissao.value = r.emissao || "";
                mDocumento.value = r.documento || "";
                EMPRESA_SELECTED_ID = Number(r.unid_negocio_id || 0) || 0;
                EMPRESA_SELECTED_NOME = r.unid_negocio || "";
                if (mUnidNegocio) {
                    if (EMPRESA_SELECTED_ID) mUnidNegocio.value = String(EMPRESA_SELECTED_ID);
                    if (!mUnidNegocio.value && EMPRESA_SELECTED_NOME) {
                        const opt = Array.from(mUnidNegocio.options).find(o => (o.textContent || "").trim() === EMPRESA_SELECTED_NOME.trim());
                        if (opt) mUnidNegocio.value = opt.value;
                    }
                }
                mProjeto.value = r.projeto || "";
                mIgnorarFluxo.checked = !!r.ignora_fluxo;

                tbodyRateio.innerHTML = "";
                (Array.isArray(r.rateio) ? r.rateio : []).forEach(x => addLinhaRateio(x));
                if (!(Array.isArray(r.rateio) && r.rateio.length)) {
                    addLinhaRateio({
                        perc: 100,
                        valor: Number(r.valor || 0)
                    });
                }
                atualizarTotaisRateio();
                modalShow("modalPagar");
            }

            // Replicar: abre o modal pré-preenchido com os dados de outro lançamento,
            // mas com ID zerado (cria novo ao salvar). Usuário pode trocar fornecedor,
            // valor ou qualquer outro campo antes de gravar.
            window.replicarLancamento = async function(id) {
                const data = await apiGet({ acao: "obter", id });
                if (!data.ok) {
                    alert(data.msg || "Erro ao carregar lançamento.");
                    return;
                }
                const r = data.row;

                limparModal();

                // Modo "novo" — não preenche mId nem mIdVisual; submit chama 'salvar' como criação.
                mTitle.textContent = "Replicar lançamento (será criado um novo)";
                btnExcluir.classList.add("d-none");

                setMain("AVP");
                setSub(r.modo || "AVISTA");

                await ensureEmpresasLoaded();

                if (mEmpresa) {
                    mEmpresa.value = r.empresa_fk ? String(r.empresa_fk) : (r.unid_negocio_id ? String(r.unid_negocio_id) : "");
                    if (!mEmpresa.value) {
                        const nome = (r.unid_negocio_nome || r.unid_negocio_raw || "").trim();
                        if (nome) {
                            const opt = Array.from(mEmpresa.options).find(o => (o.textContent || "").trim() === nome);
                            if (opt) mEmpresa.value = opt.value;
                        }
                    }
                }

                atualizarPlanoContasPorEmpresa();
                setFornecedorSel(r.fornecedor_fk || null, (r.fornecedor_fantasia || r.fornecedor || ""), (r.fornecedor_cnpj || ""));
                mCpfCnpj.value = r.cpf_cnpj || "";
                mVencimento.value = r.vencimento || "";
                mContaContabil.value = (r.plano_contas_fk ? String(r.plano_contas_fk) : (r.conta_contabil ? String(r.conta_contabil) : ""));
                if (mCentroCusto) mCentroCusto.value = r.centro_custo_fk ? String(r.centro_custo_fk) : "";
                mBanco.value = r.banco || "";
                mValor.value = numberToMoeda(r.valor || 0);
                mComplemento.value = r.complemento || "";
                mTipo.value = r.tipo || "";
                mFormaPag.value = r.forma_pag || "";
                mObs.value = r.obs || "";
                mCompetencia.value = formatDateToMonth(r.competencia || "");
                mEntrada.value = r.entrada || "";
                mParcelas.value = r.parcelas || 1;
                mPrimeiroVenc.value = r.primeiro_venc || "";
                mDiaVenc.value = r.dia_venc || "";
                mNf.value = r.nf || "";
                mEmissao.value = r.emissao || "";
                mDocumento.value = r.documento || "";
                EMPRESA_SELECTED_ID = Number(r.unid_negocio_id || 0) || 0;
                EMPRESA_SELECTED_NOME = r.unid_negocio || "";
                if (mUnidNegocio) {
                    if (EMPRESA_SELECTED_ID) mUnidNegocio.value = String(EMPRESA_SELECTED_ID);
                    if (!mUnidNegocio.value && EMPRESA_SELECTED_NOME) {
                        const opt = Array.from(mUnidNegocio.options).find(o => (o.textContent || "").trim() === EMPRESA_SELECTED_NOME.trim());
                        if (opt) mUnidNegocio.value = opt.value;
                    }
                }
                mProjeto.value = r.projeto || "";
                mIgnorarFluxo.checked = !!r.ignora_fluxo;

                tbodyRateio.innerHTML = "";
                (Array.isArray(r.rateio) ? r.rateio : []).forEach(x => addLinhaRateio(x));
                if (!(Array.isArray(r.rateio) && r.rateio.length)) {
                    addLinhaRateio({ perc: 100, valor: Number(r.valor || 0) });
                }
                atualizarTotaisRateio();
                modalShow("modalPagar");
            };


            async function perguntarEscopoParcelas(acaoTexto, pluralTexto = 'parcelas desta conta', infoHtml = '') {
                if (!(window.Swal && Swal.fire)) {
                    return 'all';
                }

                const infoBlock = infoHtml
                    ? `<div class="alert alert-warning small text-start py-2 px-3 mb-3" style="border-left:4px solid #f59e0b;">${infoHtml}</div>`
                    : '';

                const result = await Swal.fire({
                    title: `${acaoTexto} parcelas`,
                    html: `
                        ${infoBlock}
                        <div class="text-start">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="aplicar_parcelas_scope" id="scopeAll" value="all" checked>
                                <label class="form-check-label" for="scopeAll">Aplicar a todas as ${pluralTexto}</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="aplicar_parcelas_scope" id="scopeOne" value="one">
                                <label class="form-check-label" for="scopeOne">Aplicar apenas para essa parcela</label>
                            </div>
                        </div>
                    `,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Continuar',
                    cancelButtonText: 'Cancelar',
                    focusConfirm: false,
                    preConfirm: () => {
                        const marcado = document.querySelector('input[name="aplicar_parcelas_scope"]:checked');
                        return marcado ? marcado.value : 'all';
                    }
                });

                return result.isConfirmed ? (result.value || 'all') : null;
            }

            const INFO_EXCLUIR_PARCELAS = `
                <strong>Atenção:</strong> serão excluídas <b>somente</b> as parcelas em <b>ABERTO</b> ou <b>ATRASADO</b>.
                Parcelas <b>PAGAS</b> (ou com pagamento parcial) <u>não serão alteradas</u> —
                se precisar excluí-las, <b>reabra a conta</b> antes.
            `;

            function renderPaginacao(data) {
                const wrap = document.getElementById('paginacaoWrap');
                const info = document.getElementById('pagInfo');
                const btnPrev = document.getElementById('btnPagPrev');
                const btnNext = document.getElementById('btnPagNext');
                const selPer = document.getElementById('selPerPage');

                if (!wrap || !info || !btnPrev || !btnNext || !selPer) return;

                const page = Number(data.page || PAG_PAGE || 1);
                const totalPages = Number(data.total_pages || 1);
                const totalRows = Number(data.total_rows || 0);
                PAG_PAGE = page;

                info.textContent = `Página ${page} de ${totalPages} • ${totalRows} registros`;

                btnPrev.disabled = (page <= 1);
                btnNext.disabled = (page >= totalPages);

                // manter select sincronizado
                if (String(PAG_PER_PAGE) !== selPer.value) selPer.value = String(PAG_PER_PAGE);

                btnPrev.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (PAG_PAGE > 1) {
                        PAG_PAGE--;
                        listar();
                    }
                };

                btnNext.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (PAG_PAGE < totalPages) {
                        PAG_PAGE++;
                        listar();
                    }
                };

                selPer.onchange = () => {
                    PAG_PER_PAGE = Number(selPer.value || 50);
                    PAG_PAGE = 1;
                    listar();
                };
            }

            // Função para excluir lançamento direto da listagem
            window.abrirReabrirConta = async function(id) {
                if (!id) return;

                const { value: formValues, isConfirmed } = await Swal.fire({
                    title: 'Reabrir conta paga?',
                    icon: 'warning',
                    html: `
                        <div class="text-start small">
                            <p class="mb-2"><strong>Atenção!</strong> Ao reabrir esta conta:</p>
                            <ul class="ps-3 mb-2">
                                <li>A <b>consolidação do valor</b> será desfeita.</li>
                                <li>O valor voltará para o <b>saldo da conta</b> de onde foi retirado.</li>
                                <li>Status volta para <b>ABERTO</b> e dados do pagamento são limpos.</li>
                            </ul>
                            <p class="mb-2 text-danger">Somente um <b>usuário ADMIN</b> pode autorizar.</p>
                            <label class="form-label small fw-bold mt-2">Motivo (opcional)</label>
                            <input id="swal-motivo" class="form-control form-control-sm" placeholder="Motivo da reabertura">
                            <label class="form-label small fw-bold mt-2">Senha de um ADMIN <span class="text-danger">*</span></label>
                            <input id="swal-senha" type="password" class="form-control form-control-sm" placeholder="Senha ADMIN">
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Reabrir conta',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#f0ad4e',
                    focusConfirm: false,
                    preConfirm: () => {
                        const senha = document.getElementById('swal-senha').value.trim();
                        const motivo = document.getElementById('swal-motivo').value.trim();
                        if (!senha) {
                            Swal.showValidationMessage('Senha obrigatória.');
                            return false;
                        }
                        return { senha, motivo };
                    }
                });

                if (!isConfirmed || !formValues) return;

                try {
                    const j = await apiPost({ acao: 'reabrir_conta' }, { id, senha: formValues.senha, motivo: formValues.motivo });
                    if (!j.ok) {
                        Swal.fire({ icon: 'error', title: 'Não foi possível reabrir', text: j.msg || 'Erro desconhecido.' });
                        return;
                    }
                    await Swal.fire({
                        icon: 'success',
                        title: 'Conta reaberta',
                        text: `Autorizado por ${j.autorizado_por}. ${j.msg}`,
                        timer: 3000
                    });
                    if (typeof carregar === 'function') carregar();
                } catch (err) {
                    Swal.fire({ icon: 'error', title: 'Erro', text: err.message || String(err) });
                }
            };

            window.excluirLancamento = async function(id) {
                if (!id) return;

                const applyScope = await perguntarEscopoParcelas('Excluir', 'parcelas desta conta', INFO_EXCLUIR_PARCELAS);
                if (!applyScope) return;

                let confirmar = false;
                if (window.Swal && Swal.fire) {
                    const result = await Swal.fire({
                        title: 'Excluir lançamento?',
                        text: `Tem certeza que deseja excluir o lançamento #${id}? Somente parcelas em ABERTO/ATRASADO serão removidas.`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Sim, excluir!',
                        cancelButtonText: 'Cancelar'
                    });
                    confirmar = result.isConfirmed;
                } else {
                    confirmar = confirm(`Tem certeza que deseja excluir o lançamento #${id}?`);
                }

                if (!confirmar) return;

                const resp = await apiPost({
                    acao: "excluir",
                    id,
                    apply_scope: applyScope
                }, {});

                if (!resp.ok) {
                    if (window.Swal && Swal.fire) {
                        await Swal.fire({ icon: 'warning', title: 'Não foi possível excluir', text: resp.msg || 'Erro ao excluir' });
                    } else {
                        alert(resp.msg || "Erro ao excluir");
                    }
                    return;
                }

                if (window.Swal && Swal.fire) {
                    const qtdExc = Number(resp.qtd_excluidas || (resp.ids_afetados ? resp.ids_afetados.length : 0));
                    const qtdMan = Number(resp.qtd_mantidas || (resp.ids_mantidos ? resp.ids_mantidos.length : 0));
                    const textoMantidas = qtdMan > 0
                        ? `<div class="small text-muted mt-2">${qtdMan} parcela(s) <b>PAGA(S)</b> foram mantidas. Reabra a conta caso precise excluí-las.</div>`
                        : '';
                    await Swal.fire({
                        icon: "success",
                        title: `Excluído com sucesso! (${qtdExc})`,
                        html: textoMantidas,
                        confirmButtonText: "OK",
                        allowOutsideClick: false,
                        allowEscapeKey: false
                    });
                    window.location.reload();
                } else {
                    alert("Excluído com sucesso!");
                    window.location.reload();
                }
            }

            // ✅ CORRIGIDO: Abrir modal ao clicar no botão
            btnNova.addEventListener("click", () => {
                limparModal();
                modalShow("modalPagar");
            });

            btnSalvar.addEventListener("click", async () => {
                let payload = {
                    id: mId.value ? Number(mId.value) : 0,
                    modo: MODO
                };



                // aqui valida se a Conta contábil tá preenchida
                const contaContabil = document.getElementById('mContaContabil').value;

                if (!contaContabil || contaContabil === '' || contaContabil === '0') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Conta contábil obrigatória',
                        text: 'Selecione uma conta contábil para continuar.',
                        confirmButtonText: 'OK'
                    });

                    // Foco no campo
                    document.getElementById('mContaContabil').focus();

                    return false;
                } {
                    // Dados À vista / Parcelado
                    payload = {
                        ...payload,
                        parcela_info: mParcelaInfo.value || "",
                        empresa_fk: mEmpresa && mEmpresa.value ? Number(mEmpresa.value) : 0,
                        fornecedor: mFornecedor.value || "",
                        fornecedor_fk: mFornecedorFk.value ? parseInt(mFornecedorFk.value, 10) : 0,
                        vencimento: mVencimento.value || "",
                        descricao: mComplemento.value || "",
                        valor: moedaToNumber(mValor.value),
                        conta_contabil: mContaContabil.value || "",
                        plano_contas_fk: Number(mContaContabil.value || 0),
                        centro_custo_fk: Number((mCentroCusto && mCentroCusto.value) || 0),
                        banco: mBanco.value || "",
                        complemento: mComplemento.value || "",
                        tipo: mTipo.value || "",
                        forma_pag: mFormaPag.value || "",
                        obs: mObs.value || "",
                        competencia: mCompetencia.value || "",
                        entrada: mEntrada.value || "",
                        nf: mNf.value || "",
                        emissao: mEmissao.value || "",
                        documento: mDocumento.value || "",
                        unid_negocio_fk: Number(mUnidNegocio.value || 0),
                        unid_negocio: (mUnidNegocio && mUnidNegocio.selectedIndex >= 0 ? (mUnidNegocio.options[mUnidNegocio.selectedIndex].text || "") : ""),
                        projeto: mProjeto.value || "",
                        ignora_fluxo: mIgnorarFluxo.checked ? 1 : 0,
                        parcelas: Number(mParcelas.value || 1),
                        primeiro_venc: mPrimeiroVenc.value || "",
                        dia_venc: Number(mDiaVenc.value || 0),
                        rateio: getRateioFromUI()
                    };
                }

                // Validações básicas
                {
                    if (!payload.empresa_fk || payload.empresa_fk <= 0) {
                        if (window.Swal && Swal.fire) {
                            await Swal.fire({
                                icon: "warning",
                                title: "Informe a empresa",
                                text: "Selecione a empresa antes de continuar."
                            });
                        } else {
                            alert("Selecione a empresa.");
                        }
                        return;
                    }
                    if (moedaToNumber(mValor.value) <= 0) {
                        if (window.Swal && Swal.fire) {
                            await Swal.fire({
                                icon: "warning",
                                title: "Informe o valor",
                                text: "Digite um valor a pagar maior que zero."
                            });
                        } else {
                            alert("Digite um valor a pagar maior que zero.");
                        }
                        mValor.focus();
                        return;
                    }

                    if (!payload.fornecedor_fk || payload.fornecedor_fk <= 0) {
                        if (window.Swal && Swal.fire) {
                            await Swal.fire({
                                icon: "warning",
                                title: "Informe o fornecedor",
                                text: "Selecione um fornecedor válido antes de continuar."
                            });
                        } else {
                            alert("Selecione um fornecedor.");
                        }
                        return;
                    }
                }


                if (payload.id > 0) {
                    const applyScope = await perguntarEscopoParcelas('Alterar');
                    if (!applyScope) return;
                    payload.apply_scope = applyScope;
                }

                // ====== Múltiplos títulos: cria N lançamentos AVISTA num loop ======
                // Só vale para "Nova conta a pagar" (id=0). Se há títulos na lista, cada um vira 1 lançamento.
                // Se o form tem venc+valor preenchidos, oferece adicionar o que está no form como último título.
                if (payload.id === 0 && titulosLote.length > 0) {
                    const formTemDados = !!mVencimento.value && moedaToNumber(mValor.value) > 0;
                    if (formTemDados) {
                        const r = await Swal.fire({
                            icon: 'question',
                            title: 'Há campos preenchidos',
                            text: 'Você tem vencimento/valor no formulário ainda não incluídos. Adicionar como último título?',
                            showCancelButton: true,
                            confirmButtonText: 'Sim, adicionar',
                            cancelButtonText: 'Salvar só os já incluídos'
                        });
                        if (r.isConfirmed) {
                            if (!incluirTituloAtual()) return;
                        }
                    }

                    const total = titulosLote.length;
                    if (window.Swal && Swal.fire) {
                        Swal.fire({
                            title: `Criando ${total} título(s)…`,
                            text: 'Aguarde, processando.',
                            allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false,
                            didOpen: () => Swal.showLoading()
                        });
                    }

                    // Identificador do lote — UUID compartilhado por todos os títulos desta submissão.
                    // Backend grava em CPG_LOTE_TITULOS para a coluna "Parcial / Lote" na listagem.
                    const loteId = (crypto && crypto.randomUUID)
                        ? crypto.randomUUID()
                        : ('lt-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10));

                    let okCount = 0;
                    const erros = [];
                    for (let i = 0; i < total; i++) {
                        const t = titulosLote[i];
                        // Cada título sempre AVISTA (1 parcela), herdando o resto do payload compartilhado.
                        const p = {
                            ...payload,
                            modo: 'AVISTA',
                            parcelas: 1,
                            primeiro_venc: '',
                            dia_venc: 0,
                            entrada: '',
                            lote_titulos: loteId,
                            // campos por-título sobrescrevem o form:
                            vencimento: t.vencimento,
                            valor: Number(t.valor || 0),
                            documento: t.documento || '',
                            nf: t.nf || '',
                            emissao: t.emissao || '',
                            complemento: t.complemento || '',
                            descricao: t.complemento || ''
                        };
                        try {
                            const resp = await apiPost({ acao: 'salvar' }, p);
                            if (resp && resp.ok) okCount++;
                            else erros.push(`Título ${i + 1}: ${resp && resp.msg ? resp.msg : 'erro desconhecido'}`);
                        } catch (e) {
                            erros.push(`Título ${i + 1}: ${e.message || e}`);
                        }
                    }

                    if (window.Swal && Swal.fire) Swal.close();

                    if (erros.length === 0) {
                        await Swal.fire({
                            icon: 'success',
                            title: `${okCount} título(s) salvo(s)!`,
                            confirmButtonText: 'OK', allowOutsideClick: false, allowEscapeKey: false
                        });
                    } else {
                        await Swal.fire({
                            icon: okCount > 0 ? 'warning' : 'error',
                            title: okCount > 0 ? `${okCount} salvo(s) · ${erros.length} com erro` : 'Falha ao salvar',
                            html: `<div class="text-start small"><pre style="white-space:pre-wrap">${erros.map(e => '• ' + e).join('\n')}</pre></div>`,
                            confirmButtonText: 'OK'
                        });
                    }
                    window.location.reload();
                    return;
                }

                if (window.Swal && Swal.fire) {
                    Swal.fire({
                        title: "Carregando...",
                        text: (Number(mParcelas.value || 1) > 1 && !payload.id) ? "Criando parcelas, aguarde..." : "Salvando lançamento, aguarde...",
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                }

                let resp;
                try {
                    resp = await apiPost({
                        acao: "salvar"
                    }, payload);
                } catch (e) {
                    if (window.Swal && Swal.fire) Swal.close();
                    throw e;
                }

                if (window.Swal && Swal.fire) Swal.close();

                if (!resp.ok) {
                    if (window.Swal && Swal.fire) {
                        await Swal.fire({
                            icon: "error",
                            title: "Erro",
                            text: resp.msg || "Erro ao salvar",
                            confirmButtonText: "OK"
                        });
                    } else {
                        alert(resp.msg || "Erro ao salvar");
                    }
                    return;
                }


                if (window.Swal && Swal.fire) {
                    await Swal.fire({
                        icon: "success",
                        title: "Salvo com sucesso!",
                        confirmButtonText: "OK",
                        allowOutsideClick: false,
                        allowEscapeKey: false
                    });

                    // depois do OK
                    window.location.reload();
                } else {
                    alert("Salvo com sucesso!");
                    window.location.reload();
                }

                // OBS: não precisa mais disso aqui porque vai dar reload:
                // modalHide("modalPagar");
                // await listar();

            });

            btnExcluir.addEventListener("click", async () => {
                const id = Number(mId.value || 0);
                if (!id) return;

                const applyScope = await perguntarEscopoParcelas('Excluir', 'parcelas desta conta', INFO_EXCLUIR_PARCELAS);
                if (!applyScope) return;

                if (window.Swal && Swal.fire) {
                    const result = await Swal.fire({
                        title: "Tem certeza?",
                        text: "Deseja realmente excluir este lançamento? Somente parcelas em ABERTO/ATRASADO serão removidas.",
                        icon: "warning",
                        showCancelButton: true,
                        confirmButtonColor: "#d33",
                        cancelButtonColor: "#3085d6",
                        confirmButtonText: "Sim, excluir!",
                        cancelButtonText: "Cancelar"
                    });

                    if (!result.isConfirmed) return;
                } else {
                    if (!confirm("Excluir este lançamento?")) return;
                }

                const resp = await apiPost({
                    acao: "excluir",
                    id,
                    apply_scope: applyScope
                }, {});
                if (!resp.ok) {
                    if (window.Swal && Swal.fire) {
                        await Swal.fire({ icon: 'warning', title: 'Não foi possível excluir', text: resp.msg || 'Erro' });
                    } else {
                        alert(resp.msg || "Erro");
                    }
                    return;
                }

                if (window.Swal && Swal.fire) {
                    const qtdExc = Number(resp.qtd_excluidas || (resp.ids_afetados ? resp.ids_afetados.length : 0));
                    const qtdMan = Number(resp.qtd_mantidas || (resp.ids_mantidos ? resp.ids_mantidos.length : 0));
                    const textoMantidas = qtdMan > 0
                        ? `<div class="small text-muted mt-2">${qtdMan} parcela(s) <b>PAGA(S)</b> foram mantidas. Reabra a conta caso precise excluí-las.</div>`
                        : '';
                    await Swal.fire({
                        icon: "success",
                        title: `Excluído com sucesso! (${qtdExc})`,
                        html: textoMantidas,
                        confirmButtonText: "OK",
                        allowOutsideClick: false,
                        allowEscapeKey: false
                    });
                    window.location.reload();
                } else {
                    alert("Excluído com sucesso!");
                    window.location.reload();
                }

            });

            btnExportar.addEventListener("click", async () => {
                const f = coletarFiltros();
                const data = await apiGet(Object.assign({
                    acao: "listar",
                    page: 1,
                    per_page: 200
                }, f));
                if (!data.ok) {
                    alert(data.msg || "Erro");
                    return;
                }

                const rows = data.rows || [];
                const header = ["id", "status", "vencimento", "parcela", "fornecedor", "nf", "documento", "valor"];
                const csv = [header.join(";")].concat(rows.map(r => [
                    r.id, r.status, r.vencimento, (r.parcela_info || ""), (r.fornecedor || ""), (r.nf || ""), (r.documento || ""), r.valor
                ].map(x => String(x ?? "").replaceAll(";", ",")).join(";"))).join("\n");

                const blob = new Blob([csv], {
                    type: "text/csv;charset=utf-8"
                });
                const url = URL.createObjectURL(blob);
                const a = document.createElement("a");
                a.href = url;
                a.download = "contas_a_pagar.csv";
                document.body.appendChild(a);
                a.click();
                a.remove();
                URL.revokeObjectURL(url);
            });

            let t = null;

            // Debounce das recargas da lista: mais tempo pro usuário terminar de digitar,
            // e chamada silenciosa (sem overlay global) para não cobrir o input.
            function debounceList(delay = 500) {
                clearTimeout(t);
                t = setTimeout(() => {
                    window.__silentFetch = true;
                    Promise.resolve()
                        .then(listar)
                        .finally(() => { window.__silentFetch = false; });
                }, delay);
            }
            [txtBusca, valorMin, valorMax].filter(Boolean).forEach(el => el.addEventListener("input", () => { PAG_PAGE = 1; debounceList(500); }));
            [dtIni, dtFim].filter(Boolean).forEach(el => el.addEventListener("change", () => { PAG_PAGE = 1; debounceList(50); }));
            [selEmpresa, selTipo, selTipoData].filter(Boolean).forEach(el => el.addEventListener("change", () => { PAG_PAGE = 1; debounceList(50); }));

            if (selStatus) {
                selStatus.addEventListener("change", () => {
                    // Atrasado é tudo do passado até hoje — limpa a data inicial pra não restringir.
                    if (selStatus.value === "ATRASADO") {
                        if (typeof dtIni !== "undefined" && dtIni) dtIni.value = "";
                    }
                    PAG_PAGE = 1;
                    debounceList(50);
                });
            }

            if (btnLimparFiltros) btnLimparFiltros.addEventListener("click", limparTodosFiltros);

            // Restaurar filtros salvos OU pré-preencher mês atual (default)
            const filtrosSalvos = carregarFiltrosSalvos();
            if (filtrosSalvos) {
                if ("q"         in filtrosSalvos) txtBusca.value      = filtrosSalvos.q || "";
                if ("status"    in filtrosSalvos) selStatus.value     = filtrosSalvos.status || "TODOS";
                if ("ini"       in filtrosSalvos) dtIni.value         = filtrosSalvos.ini || "";
                if ("fim"       in filtrosSalvos) dtFim.value         = filtrosSalvos.fim || "";
                if (selTipo     && "tipo"      in filtrosSalvos) selTipo.value     = filtrosSalvos.tipo || "TODOS";
                if (selTipoData && "tipo_data" in filtrosSalvos) selTipoData.value = filtrosSalvos.tipo_data || "vencimento";
                if (valorMin    && "valor_min" in filtrosSalvos) valorMin.value    = filtrosSalvos.valor_min || "";
                if (valorMax    && "valor_max" in filtrosSalvos) valorMax.value    = filtrosSalvos.valor_max || "";
                // empresa será aplicada após carregarEmpresas (abaixo)
            } else {
                // Default: mês atual
                const now = new Date();
                const y = now.getFullYear();
                const m = String(now.getMonth() + 1).padStart(2, '0');
                dtIni.value = `${y}-${m}-01`;
                const lastDay = new Date(y, now.getMonth() + 1, 0).getDate();
                dtFim.value = `${y}-${m}-${String(lastDay).padStart(2, '0')}`;
            }

            // Carregar empresas, aplicar empresa salva e listar
            await carregarEmpresas();
            if (filtrosSalvos && selEmpresa && filtrosSalvos.empresa) {
                selEmpresa.value = filtrosSalvos.empresa;
            }
            await listar();









            // === Helpers ===
            function onlyDigits(v) {
                return (v || '').toString().replace(/\D+/g, '');
            }

            function moedaToNumber(v) {
                v = (v || '').toString().trim();
                if (!v) return 0;
                v = v.replace(/\./g, '').replace(',', '.');
                const n = parseFloat(v);
                return isNaN(n) ? 0 : n;
            }

            function numberToMoeda(n) {
                n = Number(n || 0);
                return n.toFixed(2).replace('.', ',');
            }

            function showModalCompat(modalId) {
                const el = document.getElementById(modalId);
                if (!el) return;

                // Bootstrap 5
                if (window.bootstrap && bootstrap.Modal) {
                    const m = bootstrap.Modal.getOrCreateInstance(el);
                    m.show();
                    return;
                }
                // Bootstrap 4 (jQuery)
                if (window.$ && $(el).modal) {
                    $(el).modal('show');
                    return;
                }
                // fallback
                el.style.display = 'block';
            }

            // === Carregar combos ===
            async function carregarFormasPagamento2() {
                const sel = document.getElementById('pgFormaPag2');
                sel.innerHTML = '<option value="">Carregando...</option>';

                const resp = await fetch(cpAjaxUrl('acao=formas_pagamento'), {
                    credentials: 'same-origin'
                });
                const data = await resp.json();

                if (!data || !data.ok) {
                    sel.innerHTML = '<option value="">Erro ao carregar</option>';
                    return;
                }

                sel.innerHTML =
                    '<option value="">Selecione...</option>' +
                    data.rows.map(r => `<option value="${r.id}">${r.descricao}</option>`).join('');
            }


            async function carregarBancos2() {
                const sel = document.getElementById('pgBanco2');
                sel.innerHTML = '<option value="">Carregando...</option>';

                const resp = await fetch(cpAjaxUrl('acao=bancos'), {
                    credentials: 'same-origin'
                });
                const data = await resp.json();

                if (!data || !data.ok) {
                    sel.innerHTML = '<option value="">Erro ao carregar</option>';
                    return;
                }

                sel.innerHTML =
                    '<option value="">Selecione...</option>' +
                    data.rows.map(r => `<option value="${r.id}">${r.descricao}</option>`).join('');
            }


            // === Abrir modal pagamento com dados ===
            async function abrirModalPagar2(idConta) {
                // 1) Buscar dados do lançamento (fornecedor + valor parcela)
                const resp = await fetch(cpAjaxUrl('acao=obter&id=' + encodeURIComponent(idConta)), {
                    credentials: 'same-origin'
                });
                const data = await resp.json();

                if (!data || !data.ok || !data.row) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Erro',
                        text: 'Erro ao carregar dados da conta.'
                    });
                    return;
                }

                // Valida se parcela já está paga
                if ((data.row.status || '').toUpperCase() === 'PAGO') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Parcela já paga',
                        text: 'Essa parcela já foi paga e não pode ser alterada. Contate o administrador.',
                        confirmButtonText: 'Ok'
                    });
                    return;
                }

                // Valida se a conta foi liberada para pagamento
                const autStatus = String(data.row.autorizacao_status || 'PENDENTE').toUpperCase();
                if (autStatus !== 'AUTORIZADO') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Conta não liberada para pagamento',
                        html: '<div style="text-align:left;font-size:.92rem;">'
                            + '<p><i class="bi bi-lock-fill text-danger me-1"></i> Esta conta está com status <b>Aguardando Liberação</b>.</p>'
                            + '<p class="text-muted mb-0">Para efetuar o pagamento, solicite a autorização em:<br><b>Fluxo de Caixa → Liberação de Pagamento</b></p>'
                            + '</div>',
                        confirmButtonText: 'Entendi',
                        confirmButtonColor: '#dc2626',
                    });
                    return;
                }

                // Abre o modal apenas se liberada
                if (window.bootstrap?.Modal) bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPagar2')).show();
                else if (window.$ && $('#modalPagar2').modal) $('#modalPagar2').modal('show');

                // Salva o ID no hidden
                document.getElementById('pgIdConta').value = idConta;

                // Lançamento = fornecedor
                document.getElementById('pgLancamento2').value = data.row.fornecedor || '';

                // Valor a pagar = CPG_VALOR_PARCELA (no JSON pode vir como valor_parcela)
                const valor = Number(data.row.valor_parcela ?? data.row.valor ?? 0);
                document.getElementById('pgValorTotal2').value = numberToMoeda(valor);
                document.getElementById('pgValorParcial2').value = numberToMoeda(valor);

                // Data pagamento = hoje
                document.getElementById('pgDataPagamento2').value = (new Date()).toISOString().slice(0, 10);

                // 2) Carregar Forma Pag.
                await carregarFormasPagamento2();

                // 3) Carregar Bancos
                await carregarBancos2();
            }

            // Expõe no window para que o botão inline consiga chamar
            window.abrirModalPagar2 = abrirModalPagar2;

            function ajustarIntegralParcial2() {
                const modo = document.getElementById('pgIntegralParcial2').value;
                const total = moedaToNumber(document.getElementById('pgValorTotal2').value);
                const parcialInput = document.getElementById('pgValorParcial2');
                const hint = document.getElementById('pgHintParcial2');

                if (modo === 'INTEGRAL') {
                    parcialInput.value = numberToMoeda(total);
                    if (hint) hint.textContent = 'No modo integral, este valor será igual ao total.';
                } else {
                    if (hint) hint.textContent = 'No modo parcial, informe quanto foi pago agora.';
                }
            }

            document.getElementById('pgIntegralParcial2')?.addEventListener('change', ajustarIntegralParcial2);

            // === Salvar pagamento ===
            document.getElementById('btnConfirmarPagamento2')?.addEventListener('click', async function() {
                const payload = {
                    acao: 'pagar_parcela',
                    id: document.getElementById('pgIdConta').value,
                    forma_pag_fk: document.getElementById('pgFormaPag2').value,
                    integral_parcial: document.getElementById('pgIntegralParcial2').value,
                    data_pagamento: document.getElementById('pgDataPagamento2').value,
                    banco_fk: document.getElementById('pgBanco2').value,
                    valor_pago: document.getElementById('pgValorParcial2').value,
                    observacao: document.getElementById('pgObs2').value,
                    cheque: document.getElementById('pgCheque2').value
                };

                if (!payload.forma_pag_fk || !payload.data_pagamento || !payload.banco_fk) {
                    alert('Informe Forma Pag., Data e Banco.');
                    return;
                }

                const resp = await fetch(cpAjaxUrl(''), { // mesma página
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8'
                    },
                    body: JSON.stringify(payload),
                    credentials: 'same-origin'
                });

                const data = await resp.json();
                if (!data || !data.ok) {
                    alert(data?.msg || 'Erro ao salvar pagamento.');
                    return;
                }

                // fecha modal
                if (window.$ && $('#modalPagar2').modal) $('#modalPagar2').modal('hide');
            });



        });
    </script>


    <script>
        (function() {

            // se já existir, não sobrescreve
            if (typeof window.abrirModalPagar2 === 'function') return;

            window.abrirModalPagar2 = async function(idConta) {
                // Primeiro verifica o status ANTES de abrir o modal
                const statusOk = await window.verificarStatusParcela(idConta);
                if (!statusOk) return; // Se já está paga, não abre o modal

                // Só abre o modal se a parcela não estiver paga
                const _elPg2b = document.getElementById('modalPagar2');
                if (window.bootstrap?.Modal) bootstrap.Modal.getOrCreateInstance(_elPg2b).show();
                else if (window.$ && $(_elPg2b).modal) $(_elPg2b).modal('show');
                await window.carregarDadosPagamento2(idConta);
            };


        })();
    </script>



    <script>
        /**
         * Usa a PRÓPRIA URL DA PÁGINA ATUAL (contas_pagar.php?...),
         * porque contas_pagar.php é include.
         */
        window.cpAjaxUrl = function(params) {
            return 'contas_pagar.php' + (params ? ('?' + params) : '');
        };

        /**
         * Verifica se a parcela já está paga antes de abrir o modal
         * Retorna true se pode pagar, false se já está paga
         */
        window.verificarStatusParcela = async function(idConta) {
            const urlObter = cpAjaxUrl('acao=obter&id=' + encodeURIComponent(idConta));

            try {
                const resp = await fetch(urlObter, {
                    credentials: 'same-origin'
                });
                const txt = await resp.text();
                const j = JSON.parse(txt);

                if (!j.ok || !j.row) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Erro',
                        text: j.msg || 'Erro ao obter dados da parcela'
                    });
                    return false;
                }

                // Verificar se a parcela já está PAGA
                if (j.row.status === 'PAGO') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Parcela já paga',
                        text: 'Essa parcela já foi paga e não pode ser alterada. Contate o administrador.',
                        confirmButtonText: 'Ok'
                    });
                    return false;
                }

                // Verificar se a parcela foi autorizada para pagamento
                const autorizacao = String(j.row.autorizacao_status || 'PENDENTE').toUpperCase();
                if (autorizacao !== 'AUTORIZADO') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Pagamento não autorizado',
                        html: '<p>Esta conta <b>ainda não foi liberada</b> para pagamento.</p><p class="text-muted small mb-0">Solicite a autorização no <b>Fluxo de Caixa → Liberação de Pagamento</b>.</p>',
                        confirmButtonText: 'Entendi'
                    });
                    return false;
                }

                return true; // Pode pagar

            } catch (e) {
                console.error('[PAGAR2] Erro ao verificar status:', e);
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: 'Erro ao verificar status da parcela'
                });
                return false;
            }
        };

        // Permite edição enquanto a parcela NÃO estiver finalizada (PAGO/RECEBIDO).
        // Diferente de verificarStatusParcela: aqui não exige autorização de pagamento.
        window.verificarPodeEditar = async function(idConta) {
            const urlObter = cpAjaxUrl('acao=obter&id=' + encodeURIComponent(idConta));
            try {
                const resp = await fetch(urlObter, { credentials: 'same-origin' });
                const j = JSON.parse(await resp.text());
                if (!j.ok || !j.row) {
                    Swal.fire({ icon: 'error', title: 'Erro', text: j.msg || 'Erro ao obter dados da parcela' });
                    return false;
                }
                const status = String(j.row.status || '').toUpperCase();
                if (status === 'PAGO' || status === 'RECEBIDO' || status === 'CANCELADO') {
                    const msg = (status === 'CANCELADO')
                        ? 'Esta parcela foi cancelada e não pode ser editada.'
                        : 'Parcelas com status "' + status + '" não podem ser editadas. Estorne primeiro pelo botão de reverter.';
                    Swal.fire({
                        icon: 'warning',
                        title: (status === 'CANCELADO' ? 'Parcela cancelada' : 'Parcela já finalizada'),
                        text: msg,
                        confirmButtonText: 'Ok'
                    });
                    return false;
                }
                return true;
            } catch (e) {
                console.error('[EDITAR] Erro ao verificar status:', e);
                Swal.fire({ icon: 'error', title: 'Erro', text: 'Erro ao verificar status da parcela' });
                return false;
            }
        };


        window.carregarDadosPagamento2 = async function(idConta) {
            console.log('[PAGAR2] carregarDadosPagamento2 id=', idConta);

            // Armazena o ID da conta no campo hidden
            document.getElementById('pgIdConta').value = idConta;

            // 1) Buscar dados da conta (fornecedor + valor parcela)
            const urlObter = cpAjaxUrl('acao=obter&id=' + encodeURIComponent(idConta));
            console.log('[PAGAR2] GET', urlObter);

            const resp1 = await fetch(urlObter, {
                credentials: 'same-origin'
            });
            const txt1 = await resp1.text();

            let j1;
            try {
                j1 = JSON.parse(txt1);
            } catch (e) {
                console.error('[PAGAR2] obter retornou HTML/texto (não JSON):', txt1.slice(0, 200));
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: 'Erro: obter não retornou JSON. Veja o console.'
                });
                return;
            }

            console.log('[PAGAR2] obter =>', j1);
            if (!j1.ok || !j1.row) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: j1.msg || 'Erro ao obter dados'
                });
                return;
            }

            const row = j1.row;

            // Fornecedor -> lançamento
            const fornecedor = (row.fornecedor || '').toString();
            document.getElementById('pgLancamento2').value = fornecedor;

            // Valor parcela
            const valor = Number(row.valor_parcela ?? row.valor ?? 0);
            document.getElementById('pgValorTotal2').value = numberToMoeda(valor);
            document.getElementById('pgValorParcial2').value = numberToMoeda(valor);

            // Data pagamento = hoje
            document.getElementById('pgDataPagamento2').value = (new Date()).toISOString().slice(0, 10);

            // 2) Formas de pagamento
            const selForma = document.getElementById('pgFormaPag2');
            selForma.innerHTML = `<option value="">Carregando...</option>`;

            const urlForma = cpAjaxUrl('acao=formas_pagamento');
            console.log('[PAGAR2] GET', urlForma);

            const resp2 = await fetch(urlForma, {
                credentials: 'same-origin'
            });
            const txt2 = await resp2.text();

            let j2;
            try {
                j2 = JSON.parse(txt2);
            } catch (e) {
                console.error('[PAGAR2] formas_pagamento retornou HTML/texto:', txt2.slice(0, 200));
                selForma.innerHTML = `<option value="">Erro ao carregar</option>`;
                return;
            }

            console.log('[PAGAR2] formas_pagamento =>', j2);

            if (j2.ok && Array.isArray(j2.rows)) {
                selForma.innerHTML = `<option value="">Selecione...</option>` + j2.rows.map(r =>
                    `<option value="${r.id}">${r.descricao}</option>`
                ).join('');
            } else {
                selForma.innerHTML = `<option value="">Erro ao carregar</option>`;
            }

            // 3) Bancos
            const selBanco = document.getElementById('pgBanco2');
            selBanco.innerHTML = `<option value="">Carregando...</option>`;

            const urlBanco = cpAjaxUrl('acao=bancos');
            console.log('[PAGAR2] GET', urlBanco);

            const resp3 = await fetch(urlBanco, {
                credentials: 'same-origin'
            });
            const txt3 = await resp3.text();

            let j3;
            try {
                j3 = JSON.parse(txt3);
            } catch (e) {
                console.error('[PAGAR2] bancos retornou HTML/texto:', txt3.slice(0, 200));
                selBanco.innerHTML = `<option value="">Erro ao carregar</option>`;
                return;
            }

            console.log('[PAGAR2] bancos =>', j3);

            if (j3.ok && Array.isArray(j3.rows)) {
                selBanco.innerHTML = `<option value="">Selecione...</option>` + j3.rows.map(r =>
                    `<option value="${r.id}">${r.descricao}</option>`
                ).join('');
            } else {
                selBanco.innerHTML = `<option value="">Erro ao carregar</option>`;
            }
        };



        function getVal(...ids) {
            for (const id of ids) {
                const el = document.getElementById(id);
                if (el && typeof el.value !== 'undefined') return (el.value || '').trim();
            }
            return '';
        }

        window.salvarPagamentoPagar2 = async function() {
            try {
                const payload = {
                    acao: 'pagar_parcela',
                    id: getVal('pgIdConta', 'pgIdConta2'),
                    forma_pag_fk: getVal('pgFormaPag2', 'pgFormaPag'),
                    integral_parcial: getVal('pgIntegralParcial2', 'pgIntegralParcial') || 'INTEGRAL',
                    data_pagamento: getVal('pgDataPagamento2', 'pgDataPagamento'),
                    banco_fk: getVal('pgBanco2', 'pgBanco'),
                    valor_pago: getVal('pgValorParcial2', 'pgValorParcial'),
                    observacao: getVal('pgObs2', 'pgObs'),
                    cheque: getVal('pgCheque2', 'pgCheque')
                };

                console.log('[PAGAR2] valores lidos:', payload);

                // Validação: apenas forma de pagamento, data e valor são obrigatórios
                if (!payload.id) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Erro',
                        text: 'ID da conta não encontrado.'
                    });
                    return;
                }

                if (!payload.forma_pag_fk) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Atenção',
                        text: 'Informe a Forma de Pagamento.'
                    });
                    return;
                }

                if (!payload.data_pagamento) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Atenção',
                        text: 'Informe a Data de Pagamento.'
                    });
                    return;
                }

                if (!payload.valor_pago) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Atenção',
                        text: 'Informe o Valor do Pagamento.'
                    });
                    return;
                }

                // Loader
                Swal.fire({
                    title: 'Carregando...',
                    text: 'Registrando pagamento, aguarde...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const resp = await fetch('contas_pagar.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8'
                    },
                    body: JSON.stringify(payload),
                    credentials: 'same-origin'
                });

                const txt = await resp.text();
                let data;

                try {
                    data = JSON.parse(txt);
                } catch (e) {
                    Swal.close();
                    console.error('[PAGAR2] POST retornou HTML/texto:', txt.slice(0, 500));

                    Swal.fire({
                        icon: 'error',
                        title: 'Erro',
                        text: 'O POST não retornou JSON. Veja o console.'
                    });
                    return;
                }

                Swal.close();

                if (!data.ok) {
                    console.error('[PAGAR2] erro:', data);

                    Swal.fire({
                        icon: 'error',
                        title: 'Erro',
                        text: data.msg || 'Erro ao salvar pagamento'
                    });
                    return;
                }

                if (window.$ && $('#modalPagar2').modal) {
                    $('#modalPagar2').modal('hide');
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Sucesso!',
                    text: data.msg || 'Pagamento registrado com sucesso!',
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    window.location.reload();
                });

                if (typeof listarLancamentos === 'function') listarLancamentos();
                if (typeof carregarLista === 'function') carregarLista();

            } catch (err) {
                Swal.close();
                console.error(err);

                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: 'Falha no POST do pagamento. Veja o console.'
                });
            }
        };


        function mascaraMoedaBR(input) {
            let valor = (input.value || "").replace(/\D/g, '');

            if (!valor) {
                input.value = "0,00";
                return;
            }

            valor = (parseInt(valor, 10) / 100).toFixed(2);
            valor = valor
                .replace(".", ",")
                .replace(/\B(?=(\d{3})+(?!\d))/g, ".");

            input.value = valor;
        }

        function bindMascaraMoeda(target) {
            const campos = (typeof target === "string") ?
                document.querySelectorAll(target) :
                (target instanceof NodeList || Array.isArray(target) ? target : [target]);

            campos.forEach((campo) => {
                if (!campo || campo.dataset.maskMoneyBound === "1") return;

                campo.dataset.maskMoneyBound = "1";
                campo.setAttribute("inputmode", "numeric");
                campo.setAttribute("autocomplete", "off");

                campo.addEventListener("input", function() {
                    mascaraMoedaBR(this);
                });


                campo.addEventListener("focus", function() {
                    if (!this.value) this.value = "";
                });

                campo.addEventListener("blur", function() {
                    if (!this.value) this.value = "";
                });



            });
        }

        bindMascaraMoeda([
            document.getElementById("mValor"),
            document.getElementById("pgValorParcial2"),
            document.getElementById("mEncINSS"),
            document.getElementById("mEncIR"),
            document.getElementById("mEncFGTS"),
            document.getElementById("mEncOutros")
        ]);

        bindMascaraMoeda(".money-field");
    </script>

    <!-- ═══════════════════════════════════════════════════════════
     MODAL: LANÇAMENTO RÁPIDO VIA IA
     ═══════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="modalLancamentoRapido" tabindex="-1" aria-labelledby="modalLancamentoRapidoLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">

                <!-- HEADER -->
                <div class="modal-header" style="background:linear-gradient(135deg,#f59f00,#f76707);color:#fff;">
                    <h5 class="modal-title fw-bold" id="modalLancamentoRapidoLabel">
                        <i class="bi bi-lightning-charge-fill me-2"></i>Lançamento Rápido com IA
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-0">

                    <!-- ETAPA 1: Upload / Câmera -->
                    <div id="lrEtapa1" class="p-4">
                        <p class="text-muted small mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Tire uma foto da conta, faça upload de um documento escaneado ou PDF.
                            A IA identificará os dados automaticamente para conferência.
                        </p>

                        <!-- Área de drop -->
                        <div id="lrDropArea"
                            style="border:2.5px dashed #dee2e6;border-radius:14px;padding:2.5rem 1.5rem;text-align:center;cursor:pointer;transition:all .2s;background:#f8f9fa;"
                            onclick="document.getElementById('lrFileInput').click()"
                            ondragover="lrDragOver(event)"
                            ondragleave="lrDragLeave(event)"
                            ondrop="lrDrop(event)">
                            <i class="bi bi-cloud-arrow-up" style="font-size:2.5rem;color:#adb5bd;"></i>
                            <div class="mt-2 fw-semibold text-secondary">Clique ou arraste aqui</div>
                            <div class="small text-muted">PDF, XML (NF-e), JPG, PNG, WEBP — máx. 10 MB</div>
                        </div>

                        <input type="file" id="lrFileInput" accept=".pdf,.xml,.jpg,.jpeg,.png,.webp" class="d-none">

                        <div class="d-flex align-items-center gap-2 my-3">
                            <hr class="flex-grow-1">
                            <span class="text-muted small">ou</span>
                            <hr class="flex-grow-1">
                        </div>

                        <!-- Câmera -->
                        <div class="text-center">
                            <button class="btn btn-outline-secondary" type="button" id="lrBtnCamera">
                                <i class="bi bi-camera-fill me-1"></i> Usar câmera
                            </button>
                        </div>

                        <!-- Preview câmera -->
                        <div id="lrCameraWrap" class="mt-3 d-none">
                            <video id="lrVideo" autoplay playsinline class="w-100 rounded" style="max-height:280px;object-fit:cover;"></video>
                            <div class="d-flex gap-2 mt-2">
                                <button class="btn btn-success flex-grow-1" type="button" id="lrBtnCapturar">
                                    <i class="bi bi-camera me-1"></i> Capturar
                                </button>
                                <button class="btn btn-outline-danger" type="button" id="lrBtnFecharCamera">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <canvas id="lrCanvas" class="d-none"></canvas>
                        </div>

                        <!-- Preview da imagem selecionada -->
                        <div id="lrPreviewWrap" class="mt-3 d-none text-center">
                            <div class="position-relative d-inline-block">
                                <img id="lrPreviewImg" src="" alt="Preview"
                                    class="rounded shadow-sm"
                                    style="max-height:260px;max-width:100%;object-fit:contain;border:1px solid #dee2e6;">
                                <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1"
                                    onclick="lrLimpar()" title="Remover">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <div id="lrNomeArquivo" class="small text-muted mt-1"></div>
                        </div>

                        <!-- Preview PDF -->
                        <div id="lrPdfPreview" class="mt-3 d-none">
                            <div class="alert alert-secondary d-flex align-items-center gap-2 mb-0">
                                <i class="bi bi-file-earmark-pdf text-danger fs-4"></i>
                                <div>
                                    <div class="fw-semibold" id="lrPdfNome">documento.pdf</div>
                                    <div class="small text-muted" id="lrPdfTamanho"></div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger ms-auto" onclick="lrLimpar()">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mt-4 text-end d-flex gap-2 justify-content-end">
                            <button class="btn btn-outline-secondary fw-bold px-4" type="button" id="lrBtnLerArquivo" disabled title="Lê PDF e XML sem usar IA">
                                <i class="bi bi-file-earmark-text me-1"></i> Ler arquivo
                            </button>
                            <button class="btn btn-warning fw-bold px-4" type="button" id="lrBtnAnalisar" disabled>
                                <i class="bi bi-stars me-1"></i> Analisar com IA
                            </button>
                        </div>
                    </div>

                    <!-- ETAPA 2: Processando -->
                    <div id="lrEtapa2" class="p-5 text-center d-none">
                        <div class="spinner-border text-warning mb-3" style="width:3rem;height:3rem;" role="status"></div>
                        <h6 class="fw-semibold">Analisando documento...</h6>
                        <p class="text-muted small">A IA está identificando fornecedor, valor, vencimento e demais dados.</p>
                        <div id="lrProgressText" class="small text-muted fst-italic"></div>
                    </div>

                    <!-- ETAPA 3: Conferência -->
                    <div id="lrEtapa3" class="d-none">
                        <!-- Banner de resultado da IA -->
                        <div id="lrIaBanner" class="px-4 pt-3 pb-2 border-bottom" style="background:#fff9e6;">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-stars text-warning fs-5"></i>
                                <span class="fw-semibold small">IA identificou:</span>
                                <span id="lrIaResumo" class="small text-muted"></span>
                            </div>
                        </div>

                        <div class="p-4">
                            <div class="row g-3">

                                <!-- Fornecedor -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Fornecedor <span class="text-danger">*</span>
                                        <span class="badge bg-warning text-dark ms-1 small" id="lrFornBadge" style="display:none;">IA</span>
                                    </label>
                                    <div class="input-group autocomplete-container position-relative">
                                        <input type="text" id="lrFornecedor" class="form-control"
                                            placeholder="Digite para buscar..." autocomplete="off">
                                        <div class="autocomplete-results d-none" id="lrAutocompleteFornecedor"
                                            style="position:absolute;top:100%;left:0;right:0;z-index:9999;background:#fff;border:1px solid #dee2e6;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.15);max-height:200px;overflow-y:auto;"></div>
                                    </div>
                                    <input type="hidden" id="lrFornecedorFk">
                                    <input type="text" id="lrCpfCnpj" class="form-control mt-1 mono"
                                        placeholder="CPF / CNPJ" readonly style="font-size:.82rem;">
                                </div>

                                <!-- Valor -->
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        Valor <span class="text-danger">*</span>
                                        <span class="badge bg-warning text-dark ms-1 small" id="lrValorBadge" style="display:none;">IA</span>
                                    </label>
                                    <input type="text" id="lrValor" class="form-control text-end mono money-field"
                                        inputmode="numeric" placeholder="0,00">
                                </div>

                                <!-- Vencimento -->
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        Vencimento <span class="text-danger">*</span>
                                        <span class="badge bg-warning text-dark ms-1 small" id="lrVencBadge" style="display:none;">IA</span>
                                    </label>
                                    <input type="date" id="lrVencimento" class="form-control">
                                </div>

                                <!-- Descrição -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Descrição / Complemento
                                        <span class="badge bg-warning text-dark ms-1 small" id="lrDescBadge" style="display:none;">IA</span>
                                    </label>
                                    <input type="text" id="lrDescricao" class="form-control"
                                        placeholder="Ex.: Aluguel mês 06/2025, Fatura energia...">
                                </div>

                                <!-- NF / Documento -->
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        Nº Documento / NF
                                        <span class="badge bg-warning text-dark ms-1 small" id="lrNfBadge" style="display:none;">IA</span>
                                    </label>
                                    <input type="text" id="lrDocumento" class="form-control mono"
                                        placeholder="Ex.: 000123">
                                </div>

                                <!-- Emissão -->
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        Data Emissão
                                        <span class="badge bg-warning text-dark ms-1 small" id="lrEmissaoBadge" style="display:none;">IA</span>
                                    </label>
                                    <input type="date" id="lrEmissao" class="form-control">
                                </div>

                                <div class="col-12">
                                    <hr class="my-1">
                                </div>

                                <!-- Plano de Contas -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Plano de Contas <span class="text-danger">*</span></label>
                                    <select id="lrPlanoContas" class="form-select">
                                        <option value="">Carregando...</option>
                                    </select>
                                </div>

                                <!-- Centro de Custo -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Centro de Custo</label>
                                    <select id="lrCentroCusto" class="form-select">
                                        <option value="">Carregando...</option>
                                    </select>
                                </div>

                                <!-- Modo pagamento -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Modo de Pagamento <span class="text-danger">*</span></label>
                                    <select id="lrModo" class="form-select">
                                        <option value="AVISTA">À Vista</option>
                                        <option value="PARCELADO">Parcelado</option>
                                    </select>
                                </div>

                                <!-- Banco -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Banco</label>
                                    <select id="lrBanco" class="form-select">
                                        <option value="">Carregando...</option>
                                    </select>
                                </div>

                                <!-- Forma de Pagamento -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Forma de Pagamento</label>
                                    <select id="lrFormaPag" class="form-select">
                                        <option value="">Carregando...</option>
                                    </select>
                                </div>

                                <!-- Parcelas (aparece quando parcelado) -->
                                <div class="col-12" id="lrBlocoParcelas" style="display:none;">
                                    <div class="card border-0 bg-light">
                                        <div class="card-body">
                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-3">
                                                    <label class="form-label mb-1">Nº de Parcelas <span class="text-danger">*</span></label>
                                                    <input type="number" id="lrParcelas" min="2" step="1" class="form-control" value="2">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label mb-1">Primeiro vencimento <span class="text-danger">*</span></label>
                                                    <input type="date" id="lrPrimeiroVenc" class="form-control">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label mb-1">Dia dos próx. vencimentos</label>
                                                    <input type="number" id="lrDiaVenc" min="1" max="31" class="form-control" placeholder="Ex.: 10">
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="alert alert-info mb-0 py-2 small">
                                                        Parcelas geradas automaticamente
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <!-- Observação da IA -->
                            <div id="lrIaObsWrap" class="mt-3 d-none">
                                <div class="alert alert-warning mb-0 py-2 small d-flex gap-2">
                                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
                                    <span id="lrIaObs"></span>
                                </div>
                            </div>

                        </div>
                    </div>

                </div><!-- /.modal-body -->

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-outline-secondary" id="lrBtnVoltar" style="display:none;">
                        <i class="bi bi-arrow-left me-1"></i> Voltar
                    </button>
                    <button type="button" class="btn btn-success fw-bold px-4" id="lrBtnSalvar" style="display:none;">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Usar no lançamento
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        /* ═══════════════════════════════════════════════════════════
   LANÇAMENTO RÁPIDO — Lógica completa
   ═══════════════════════════════════════════════════════════ */
        (function() {
            'use strict';

            // ── Estado ──────────────────────────────────────────────
            let lrStream = null;
            let lrArquivo = null; // File object
            let lrBase64 = null; // string base64 puro (sem data:...)
            let lrMimeType = null; // image/jpeg | image/png | application/pdf
            let lrDadosIA = {}; // dados retornados pela IA

            const ENDPOINT = window.location.pathname + window.location.search.split('?')[0].replace(/[^/]*$/, '') + 'contas_pagar.php';
            const SELF = window.location.href.split('?')[0];

            // ── Elementos ────────────────────────────────────────────
            const modal = document.getElementById('modalLancamentoRapido');
            const etapa1 = document.getElementById('lrEtapa1');
            const etapa2 = document.getElementById('lrEtapa2');
            const etapa3 = document.getElementById('lrEtapa3');
            const btnAnalisar = document.getElementById('lrBtnAnalisar');
            const btnLerArquivo = document.getElementById('lrBtnLerArquivo');
            const btnVoltar = document.getElementById('lrBtnVoltar');
            const btnSalvar = document.getElementById('lrBtnSalvar');
            const fileInput = document.getElementById('lrFileInput');
            const dropArea = document.getElementById('lrDropArea');
            const previewWrap = document.getElementById('lrPreviewWrap');
            const previewImg = document.getElementById('lrPreviewImg');
            const pdfPreview = document.getElementById('lrPdfPreview');
            const cameraWrap = document.getElementById('lrCameraWrap');
            const video = document.getElementById('lrVideo');
            const canvas = document.getElementById('lrCanvas');

            // ── Abrir modal ──────────────────────────────────────────
            document.getElementById('btnLancamentoRapido').addEventListener('click', () => {
                lrReset();
                carregarSelects();
                new bootstrap.Modal(modal).show();
            });

            // ── Reset completo ───────────────────────────────────────
            function lrReset() {
                lrArquivo = null;
                lrBase64 = null;
                lrMimeType = null;
                lrDadosIA = {};
                etapa1.classList.remove('d-none');
                etapa2.classList.add('d-none');
                etapa3.classList.add('d-none');
                btnVoltar.style.display = 'none';
                btnSalvar.style.display = 'none';
                btnAnalisar.disabled = true;
                btnLerArquivo.disabled = true;
                previewWrap.classList.add('d-none');
                pdfPreview.classList.add('d-none');
                cameraWrap.classList.add('d-none');
                dropArea.style.borderColor = '#dee2e6';
                dropArea.style.background = '#f8f9fa';
                fileInput.value = '';
                fecharCamera();
            }
            window.lrLimpar = lrReset;

            // ── Drag & Drop ──────────────────────────────────────────
            window.lrDragOver = (e) => {
                e.preventDefault();
                dropArea.style.borderColor = '#f59f00';
                dropArea.style.background = '#fff9e6';
            };
            window.lrDragLeave = () => {
                dropArea.style.borderColor = '#dee2e6';
                dropArea.style.background = '#f8f9fa';
            };
            window.lrDrop = (e) => {
                e.preventDefault();
                lrDragLeave();
                if (e.dataTransfer.files.length) processarArquivo(e.dataTransfer.files[0]);
            };

            fileInput.addEventListener('change', () => {
                if (fileInput.files.length) processarArquivo(fileInput.files[0]);
            });

            // ── Processar arquivo ────────────────────────────────────
            function processarArquivo(file) {
                const allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/xml', 'application/xml'];
                const isXml = /\.xml$/i.test(file.name) || allowed.slice(4).includes(file.type);
                if (!allowed.includes(file.type) && !isXml) {
                    alert('Formato não suportado. Use JPG, PNG, WEBP, PDF ou XML.');
                    return;
                }
                if (file.size > 10 * 1024 * 1024) {
                    alert('Arquivo muito grande. Máximo 10 MB.');
                    return;
                }
                lrArquivo = file;
                lrMimeType = isXml ? 'application/xml' : file.type;

                const reader = new FileReader();
                reader.onload = (e) => {
                    const dataUrl = e.target.result;
                    // extrai só o base64
                    lrBase64 = dataUrl.split(',')[1];

                    if (isXml) {
                        pdfPreview.classList.remove('d-none');
                        previewWrap.classList.add('d-none');
                        document.getElementById('lrPdfNome').textContent = file.name + ' (XML NF-e)';
                        document.getElementById('lrPdfTamanho').textContent =
                            (file.size / 1024).toFixed(0) + ' KB';
                    } else if (file.type === 'application/pdf') {
                        pdfPreview.classList.remove('d-none');
                        previewWrap.classList.add('d-none');
                        document.getElementById('lrPdfNome').textContent = file.name;
                        document.getElementById('lrPdfTamanho').textContent =
                            (file.size / 1024).toFixed(0) + ' KB';
                    } else {
                        previewWrap.classList.remove('d-none');
                        pdfPreview.classList.add('d-none');
                        previewImg.src = dataUrl;
                        document.getElementById('lrNomeArquivo').textContent = file.name;
                    }
                    btnAnalisar.disabled = false;
                    btnLerArquivo.disabled = false;
                    cameraWrap.classList.add('d-none');
                    fecharCamera();
                };
                reader.readAsDataURL(file);
            }

            // ── Câmera ───────────────────────────────────────────────
            document.getElementById('lrBtnCamera').addEventListener('click', async () => {
                try {
                    lrStream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: 'environment'
                        },
                        audio: false
                    });
                    video.srcObject = lrStream;
                    cameraWrap.classList.remove('d-none');
                    previewWrap.classList.add('d-none');
                    pdfPreview.classList.add('d-none');
                } catch {
                    alert('Não foi possível acessar a câmera.');
                }
            });

            document.getElementById('lrBtnCapturar').addEventListener('click', () => {
                canvas.width = video.videoWidth || 1280;
                canvas.height = video.videoHeight || 720;
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                const dataUrl = canvas.toDataURL('image/jpeg', 0.92);
                lrBase64 = dataUrl.split(',')[1];
                lrMimeType = 'image/jpeg';

                previewImg.src = dataUrl;
                previewWrap.classList.remove('d-none');
                document.getElementById('lrNomeArquivo').textContent = 'Foto capturada pela câmera';
                cameraWrap.classList.add('d-none');
                fecharCamera();
                btnAnalisar.disabled = false;
                btnLerArquivo.disabled = false;
            });

            document.getElementById('lrBtnFecharCamera').addEventListener('click', () => {
                cameraWrap.classList.add('d-none');
                fecharCamera();
            });

            function fecharCamera() {
                if (lrStream) {
                    lrStream.getTracks().forEach(t => t.stop());
                    lrStream = null;
                }
            }

            // modal fechado → para câmera
            modal.addEventListener('hidden.bs.modal', () => fecharCamera());

            // ── ANALISAR COM IA ──────────────────────────────────────
            btnAnalisar.addEventListener('click', () => analisarComIA());
            btnLerArquivo.addEventListener('click', () => lerArquivoLocal());

            // ── LER ARQUIVO SEM IA ───────────────────────────────────
            async function lerArquivoLocal() {
                if (!lrBase64) return;

                etapa1.classList.add('d-none');
                etapa2.classList.remove('d-none');
                btnVoltar.style.display = 'none';
                btnSalvar.style.display = 'none';

                const progressEl = document.getElementById('lrProgressText');
                progressEl.textContent = 'Lendo arquivo localmente...';

                try {
                    const fornRes = await fetch(SELF + '?acao=buscar_fornecedor&q=&limit=30');
                    const fornJson = await fornRes.json();
                    const fornList = (fornJson.rows || []).map(f =>
                        `${f.FOR_NOME_FANTASIA || f.FOR_RAZAO_SOCIAL} (CNPJ: ${f.FOR_CNPJ || 'N/D'}, ID: ${f.FOR_CODIGO_PK})`
                    ).join('\n');

                    const resp = await fetch(SELF + '?acao=ler_arquivo', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            base64: lrBase64,
                            mime_type: lrMimeType,
                            forn_list: fornList
                        })
                    });

                    if (!resp.ok) {
                        const errText = await resp.text();
                        throw new Error('Servidor retornou ' + resp.status + ': ' + errText.substring(0, 300));
                    }

                    const data = await resp.json();
                    if (!data.ok) throw new Error(data.msg || 'Erro ao ler arquivo.');

                    lrDadosIA = data.ia;
                    preencherEtapa3(data.ia);
                } catch (err) {
                    etapa2.classList.add('d-none');
                    etapa1.classList.remove('d-none');
                    btnAnalisar.disabled = false;
                    btnLerArquivo.disabled = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Erro ao ler arquivo',
                        text: err.message || 'Não foi possível ler o documento.'
                    });
                }
            }

            async function analisarComIA() {
                if (!lrBase64) return;

                etapa1.classList.add('d-none');
                etapa2.classList.remove('d-none');
                btnVoltar.style.display = 'none';
                btnSalvar.style.display = 'none';

                const progressEl = document.getElementById('lrProgressText');
                const msgs = ['Lendo o documento...', 'Identificando fornecedor...', 'Extraindo valor e vencimento...', 'Finalizando análise...'];
                let mi = 0;
                const ticker = setInterval(() => {
                    progressEl.textContent = msgs[mi++ % msgs.length];
                }, 1400);

                try {
                    // Busca fornecedores disponíveis para dar contexto à IA
                    const fornRes = await fetch(SELF + '?acao=buscar_fornecedor&q=&limit=30');
                    const fornJson = await fornRes.json();
                    const fornList = (fornJson.rows || []).map(f =>
                        `${f.FOR_NOME_FANTASIA || f.FOR_RAZAO_SOCIAL} (CNPJ: ${f.FOR_CNPJ || 'N/D'}, ID: ${f.FOR_CODIGO_PK})`
                    ).join('\n');

                    // Chama o PROXY PHP (evita CORS — o browser não pode chamar a Anthropic diretamente)
                    const mediaType = lrMimeType === 'application/pdf' ? 'application/pdf' : lrMimeType;

                    const proxyPayload = {
                        base64: lrBase64,
                        mime_type: mediaType,
                        forn_list: fornList
                    };

                    const resp = await fetch(SELF + '?acao=analisar_ia', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(proxyPayload)
                    });

                    clearInterval(ticker);

                    if (!resp.ok) {
                        const errText = await resp.text();
                        throw new Error('Servidor retornou ' + resp.status + ': ' + errText.substring(0, 300));
                    }

                    const proxyData = await resp.json();
                    if (!proxyData.ok) {
                        throw new Error(proxyData.msg || 'Erro desconhecido no servidor.');
                    }

                    // DEBUG temporário: mostra texto bruto do OCR no console do navegador
                    if (proxyData._ocr_debug) {
                        console.log('%c[OCR DEBUG - texto bruto do Tesseract]', 'color:orange;font-weight:bold;font-size:14px');
                        console.log(proxyData._ocr_debug);
                    }

                    // ia já vem parseado pelo PHP
                    const ia = proxyData.ia;

                    lrDadosIA = ia;
                    preencherEtapa3(ia);

                } catch (err) {
                    clearInterval(ticker);
                    etapa2.classList.add('d-none');
                    etapa1.classList.remove('d-none');
                    btnAnalisar.disabled = false;
                    btnLerArquivo.disabled = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Erro ao analisar',
                        text: err.message || 'Não foi possível processar o documento.'
                    });
                }
            }

            // ── Preencher etapa 3 com dados da IA ───────────────────
            function preencherEtapa3(ia) {
                etapa2.classList.add('d-none');
                etapa3.classList.remove('d-none');
                btnVoltar.style.display = '';
                btnSalvar.style.display = '';

                // Banner resumo
                const confiança = ia.confianca === 'ALTA' ? '✅ Alta confiança' :
                    ia.confianca === 'MÉDIA' ? '⚠️ Confiança média' :
                    '❌ Baixa confiança — revise os campos';
                document.getElementById('lrIaResumo').textContent =
                    `${ia.tipo_conta || 'conta'} · ${confiança}`;

                // Observação da IA
                if (ia.observacao_ia) {
                    document.getElementById('lrIaObs').textContent = ia.observacao_ia;
                    document.getElementById('lrIaObsWrap').classList.remove('d-none');
                } else {
                    document.getElementById('lrIaObsWrap').classList.add('d-none');
                }

                // Fornecedor
                if (ia.fornecedor_id_sugerido) {
                    document.getElementById('lrFornecedorFk').value = ia.fornecedor_id_sugerido;
                    document.getElementById('lrFornBadge').style.display = '';
                    // Busca o nome do fornecedor pelo ID
                    fetch(SELF + '?acao=buscar_fornecedor&q=&limit=100')
                        .then(r => r.json())
                        .then(j => {
                            const f = (j.rows || []).find(x => String(x.FOR_CODIGO_PK) === String(ia.fornecedor_id_sugerido));
                            if (f) {
                                document.getElementById('lrFornecedor').value = f.FOR_NOME_FANTASIA || f.FOR_RAZAO_SOCIAL;
                                document.getElementById('lrCpfCnpj').value = f.FOR_CNPJ || '';
                            } else {
                                document.getElementById('lrFornecedor').value = ia.fornecedor_nome || '';
                                document.getElementById('lrCpfCnpj').value = ia.fornecedor_cnpj || '';
                            }
                        });
                } else {
                    document.getElementById('lrFornecedor').value = ia.fornecedor_nome || '';
                    document.getElementById('lrCpfCnpj').value = ia.fornecedor_cnpj || '';
                    if (ia.fornecedor_nome) document.getElementById('lrFornBadge').style.display = '';
                }

                // Valor
                if (ia.valor) {
                    document.getElementById('lrValor').value = formatarMoeda(ia.valor);
                    document.getElementById('lrValorBadge').style.display = '';
                }

                // Vencimento
                if (ia.vencimento) {
                    document.getElementById('lrVencimento').value = ia.vencimento;
                    document.getElementById('lrVencBadge').style.display = '';
                }

                // Emissão
                if (ia.emissao) {
                    document.getElementById('lrEmissao').value = ia.emissao;
                    document.getElementById('lrEmissaoBadge').style.display = '';
                }

                // Descrição
                if (ia.descricao) {
                    document.getElementById('lrDescricao').value = ia.descricao;
                    document.getElementById('lrDescBadge').style.display = '';
                }

                // Documento
                if (ia.documento) {
                    document.getElementById('lrDocumento').value = ia.documento;
                    document.getElementById('lrNfBadge').style.display = '';
                }
            }

            // ── Voltar para etapa 1 ──────────────────────────────────
            btnVoltar.addEventListener('click', () => {
                etapa3.classList.add('d-none');
                etapa1.classList.remove('d-none');
                btnVoltar.style.display = 'none';
                btnSalvar.style.display = 'none';
                btnAnalisar.disabled = false;
                btnLerArquivo.disabled = false;
            });

            // ── Modo parcelado ───────────────────────────────────────
            document.getElementById('lrModo').addEventListener('change', function() {
                const bloco = document.getElementById('lrBlocoParcelas');
                bloco.style.display = this.value === 'PARCELADO' ? '' : 'none';
                if (this.value === 'PARCELADO') {
                    document.getElementById('lrPrimeiroVenc').value =
                        document.getElementById('lrVencimento').value || '';
                }
            });

            // ── Autocomplete fornecedor ──────────────────────────────
            let lrFornDebounce;
            document.getElementById('lrFornecedor').addEventListener('input', function() {
                clearTimeout(lrFornDebounce);
                const q = this.value.trim();
                const box = document.getElementById('lrAutocompleteFornecedor');
                if (q.length < 2) {
                    box.classList.add('d-none');
                    return;
                }

                lrFornDebounce = setTimeout(async () => {
                    const j = await fetch(SELF + '?acao=buscar_fornecedor&q=' + encodeURIComponent(q) + '&limit=10').then(r => r.json()).catch(() => ({
                        rows: []
                    }));
                    box.innerHTML = '';
                    (j.rows || []).forEach(f => {
                        const item = document.createElement('div');
                        item.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:.88rem;';
                        item.innerHTML = `<strong>${f.FOR_NOME_FANTASIA || f.FOR_RAZAO_SOCIAL}</strong><br><small class="text-muted">${f.FOR_CNPJ || ''}</small>`;
                        item.addEventListener('mousedown', () => {
                            document.getElementById('lrFornecedor').value = f.FOR_NOME_FANTASIA || f.FOR_RAZAO_SOCIAL;
                            document.getElementById('lrFornecedorFk').value = f.FOR_CODIGO_PK;
                            document.getElementById('lrCpfCnpj').value = f.FOR_CNPJ || '';
                            box.classList.add('d-none');
                        });
                        item.addEventListener('mouseover', () => item.style.background = '#fff9e6');
                        item.addEventListener('mouseout', () => item.style.background = '');
                        box.appendChild(item);
                    });
                    box.classList.toggle('d-none', j.rows.length === 0);
                }, 300);
            });
            document.getElementById('lrFornecedor').addEventListener('blur', () => {
                setTimeout(() => document.getElementById('lrAutocompleteFornecedor').classList.add('d-none'), 200);
            });

            // ── Transferir dados para o formulário principal ─────────
            // ── Usar dados no formulário principal ──────────────────
            // Ao clicar em "Usar no lançamento", fecha o modal rápido
            // e preenche o formulário principal (modalPagar) para que
            // o usuário escolha à vista ou parcelado e salve com segurança.
            btnSalvar.addEventListener('click', () => {
                // Coleta dados do formulário do modal rápido
                const fornFk = document.getElementById('lrFornecedorFk').value || '';
                const fornNome = document.getElementById('lrFornecedor').value || '';
                const valor = moedaParaNumero(document.getElementById('lrValor').value);
                const venc = document.getElementById('lrVencimento').value || '';
                const emissao = document.getElementById('lrEmissao').value || '';
                const doc = document.getElementById('lrDocumento').value.trim() || '';
                const desc = document.getElementById('lrDescricao').value.trim() || '';
                const planoFk = document.getElementById('lrPlanoContas').value || '';
                const ccFk = document.getElementById('lrCentroCusto').value || '';
                const banco = document.getElementById('lrBanco').value || '';
                const forma = document.getElementById('lrFormaPag').value || '';

                // Fecha o modal rápido
                bootstrap.Modal.getInstance(modal)?.hide();

                // Garante que o formulário principal está limpo
                if (typeof limparModal === 'function') limparModal();

                // Preenche os campos do formulário principal (modalPagar)
                const set = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) el.value = val;
                };

                set('mFornecedorFk', fornFk);
                set('mFornecedor', fornNome);
                set('mVencimento', venc);
                set('mEmissao', emissao);
                set('mDocumento', doc);
                set('mComplemento', desc);
                set('mBanco', banco);
                set('mFormaPag', forma);

                // Valor com máscara de moeda
                const mValorEl = document.getElementById('mValor');
                if (mValorEl) {
                    mValorEl.value = valor > 0 ?
                        valor.toLocaleString('pt-BR', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }) :
                        '';
                    mValorEl.dispatchEvent(new Event('input')); // atualiza rateio se houver listener
                }

                // Plano de contas
                if (planoFk) {
                    const selPlano = document.getElementById('mContaContabil');
                    if (selPlano) {
                        selPlano.value = planoFk;
                        // Se ainda não carregou as opções, aguarda um tick e tenta de novo
                        if (!selPlano.value && planoFk) {
                            setTimeout(() => {
                                selPlano.value = planoFk;
                            }, 400);
                        }
                    }
                }

                // Centro de custo (primeira linha do rateio, se existir)
                if (ccFk) {
                    const ccEl = document.querySelector('#tbodyRateio .r-cc');
                    if (ccEl) ccEl.value = ccFk;
                }

                // Abre o formulário principal
                if (typeof modalShow === 'function') {
                    modalShow('modalPagar');
                } else {
                    new bootstrap.Modal(document.getElementById('modalPagar')).show();
                }
            });

            // ── Carregar selects (plano, cc, banco, forma pagto) ─────
            let selectsCarregados = false;
            async function carregarSelects() {
                if (selectsCarregados) return;
                selectsCarregados = true;

                const [planos, ccs, bancos, formas] = await Promise.all([
                    fetch(SELF + '?acao=buscar_plano_contas').then(r => r.json()).catch(() => ({
                        rows: []
                    })),
                    fetch(SELF + '?acao=buscar_centros_custo').then(r => r.json()).catch(() => ({
                        rows: []
                    })),
                    fetch(SELF + '?acao=buscar_bancos').then(r => r.json()).catch(() => ({
                        rows: []
                    })),
                    fetch(SELF + '?acao=buscar_formas_pagamento').then(r => r.json()).catch(() => ({
                        rows: []
                    })),
                ]);

                preencherSelect('lrPlanoContas', planos.rows || [], 'id', 'nome', '-- Selecione --');
                preencherSelect('lrCentroCusto', ccs.rows || [], 'id', 'nome', '-- Nenhum --', true);
                preencherSelect('lrBanco', bancos.rows || [], 'id', 'descricao', '-- Nenhum --', true);
                preencherSelect('lrFormaPag', formas.rows || [], 'id', 'descricao', '-- Nenhum --', true);
            }

            function preencherSelect(id, rows, valKey, labelKey, placeholder, allowEmpty = false) {
                const sel = document.getElementById(id);
                if (!sel) return;
                sel.innerHTML = '';
                if (allowEmpty || !rows.length) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = placeholder;
                    sel.appendChild(opt);
                }
                rows.forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r[valKey];
                    opt.textContent = r[labelKey];
                    sel.appendChild(opt);
                });
            }

            // ── Helpers ──────────────────────────────────────────────
            function formatarMoeda(num) {
                return Number(num).toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function moedaParaNumero(str) {
                if (!str) return 0;
                str = str.replace(/[R$\s]/g, '');
                if (str.indexOf(',') > str.indexOf('.')) {
                    str = str.replace(/\./g, '').replace(',', '.');
                } else {
                    str = str.replace(/,/g, '');
                }
                return parseFloat(str) || 0;
            }

            // aplica máscara de moeda no campo valor do modal rápido
            document.getElementById('lrValor').addEventListener('input', function() {
                const digits = this.value.replace(/\D/g, '');
                if (!digits) {
                    this.value = '';
                    return;
                }
                const num = parseInt(digits) / 100;
                this.value = num.toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            });

        })();
    </script>

  <script src="assets/session_keeper.js" defer></script>
</body>

</html>