<?php
// /app/endpoints/bancos.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexao.php'; // <- seu padrão

// Resposta JSON limpa
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) @ob_end_clean();
}
ini_set('zlib.output_compression', '0');
header_remove();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Tenta obter um PDO independente do padrão usado no projeto.
 * - classe conexao::getInstance()
 * - função db()/getPDO()/conexao()
 * - variável $pdo/$db/$conexao
 */
function get_db(): PDO
{
    // 1) Classe conexao (padrão antigo)
    if (class_exists('conexao') && method_exists('conexao', 'getInstance')) {
        $db = conexao::getInstance();
        if ($db instanceof PDO) return $db;
    }

    // 2) Funções comuns
    foreach (['db', 'getPDO', 'conexao', 'pdo'] as $fn) {
        if (function_exists($fn)) {
            $db = $fn();
            if ($db instanceof PDO) return $db;
        }
    }

    // 3) Variáveis comuns que podem ter vindo do require
    foreach (['pdo', 'db', 'conexao', 'conn', 'connection'] as $var) {
        if (isset($GLOBALS[$var]) && $GLOBALS[$var] instanceof PDO) {
            return $GLOBALS[$var];
        }
    }

    throw new RuntimeException('Conexão não encontrada. Verifique config/conexao.php (PDO/funcão/classe).');
}

function only_digits(?string $v): string
{
    $v = (string)($v ?? '');
    return preg_replace('/\D+/', '', $v) ?? '';
}

function post(string $k, $default = '')
{
    return $_POST[$k] ?? $default;
}

function get(string $k, $default = '')
{
    return $_GET[$k] ?? $default;
}

try {
    $db = get_db(); // <- agora funciona com o seu config/conexao.php
    $acao = (string)(get('acao', post('acao', '')));

    if ($acao === 'listar') {
        $buscar = trim((string)get('buscar', ''));
        $status = trim((string)get('status', ''));

        $where = [];
        $params = [];

        if ($status !== '') {
            $where[] = "BAN_STATUS = :status";
            $params[':status'] = $status;
        }

        if ($buscar !== '') {
            $where[] = "("
                . "BAN_APELIDO LIKE :q OR BAN_NOME LIKE :q OR BAN_CODIGO LIKE :q OR "
                . "BAN_CONVENIO LIKE :q OR BAN_CARTEIRA LIKE :q OR "
                . "BAN_AGENCIA LIKE :q OR BAN_CONTA LIKE :q OR "
                . "BAN_CEDENTE_NOME LIKE :q OR BAN_CEDENTE_DOC LIKE :q"
                . ")";
            $params[':q'] = '%' . $buscar . '%';
        }

        $sql = "SELECT
                    BAN_ID, BAN_APELIDO, BAN_STATUS,
                    BAN_CODIGO, BAN_NOME, BAN_AMBIENTE,
                    BAN_CONVENIO, BAN_CARTEIRA,
                    BAN_AGENCIA, BAN_AGENCIA_DV, BAN_CONTA, BAN_CONTA_DV,
                    BAN_CEDENTE_NOME, BAN_CEDENTE_DOC
                FROM tb_banco";

        if ($where) $sql .= " WHERE " . implode(" AND ", $where);

        $sql .= " ORDER BY BAN_STATUS ASC, BAN_APELIDO ASC, BAN_ID DESC";

        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        json_out([
            'ok' => true,
            'rows' => $rows,
            'total' => count($rows),
        ]);
    }

    if ($acao === 'combo') {
        // para selects (somente ativos)
        $st = $db->prepare("
            SELECT BAN_ID, BAN_APELIDO, BAN_CODIGO, BAN_NOME
            FROM tb_banco
            WHERE BAN_STATUS = 'ATIVO'
            ORDER BY BAN_APELIDO ASC
        ");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            // Monta label: "237 - Bradesco (Apelido)" ou só o apelido se não tiver código/nome
            $partes = array_filter([$r['BAN_CODIGO'] ?? '', $r['BAN_NOME'] ?? '']);
            $prefixo = implode(' - ', $partes);
            $r['BAN_LABEL'] = $prefixo
                ? $prefixo . ($r['BAN_APELIDO'] ? ' (' . $r['BAN_APELIDO'] . ')' : '')
                : ($r['BAN_APELIDO'] ?: (string)$r['BAN_ID']);
        }
        unset($r);

        json_out(['ok' => true, 'rows' => $rows, 'total' => count($rows)]);
    }

    if ($acao === 'get') {
        $id = (int)get('id', 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 400);

        $st = $db->prepare("SELECT * FROM tb_banco WHERE BAN_ID = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) json_out(['ok' => false, 'msg' => 'Registro não encontrado.'], 404);

        json_out(['ok' => true, 'row' => $row]);
    }

    if ($acao === 'salvar') {
        $BAN_ID = (int)post('BAN_ID', 0);

        // ===== coleta/normalização =====
        $BAN_APELIDO = trim((string)post('BAN_APELIDO', ''));
        $BAN_STATUS  = trim((string)post('BAN_STATUS', 'ATIVO')) ?: 'ATIVO';

        $BAN_CODIGO  = substr(only_digits((string)post('BAN_CODIGO', '')), 0, 3);
        $BAN_NOME    = trim((string)post('BAN_NOME', ''));
        $BAN_ISPB    = substr(only_digits((string)post('BAN_ISPB', '')), 0, 8) ?: null;
        $BAN_AMBIENTE = trim((string)post('BAN_AMBIENTE', 'PRODUCAO')) ?: 'PRODUCAO';
        $BAN_SITE    = trim((string)post('BAN_SITE', '')) ?: null;
        $BAN_OBSERVACAO = trim((string)post('BAN_OBSERVACAO', '')) ?: null;
        $BAN_CAIXA_INTERNO = ((string)post('BAN_CAIXA_INTERNO', '0')) === '1' ? 1 : 0;

        $BAN_CEDENTE_NOME = trim((string)post('BAN_CEDENTE_NOME', ''));
        $BAN_CEDENTE_DOC  = trim((string)post('BAN_CEDENTE_DOC', ''));
        $BAN_CEDENTE_TIPO_DOC = trim((string)post('BAN_CEDENTE_TIPO_DOC', 'CNPJ')) ?: 'CNPJ';
        $BAN_CODIGO_CEDENTE = trim((string)post('BAN_CODIGO_CEDENTE', '')) ?: null;

        $BAN_AGENCIA    = trim((string)post('BAN_AGENCIA', ''));
        $BAN_AGENCIA_DV = trim((string)post('BAN_AGENCIA_DV', '')) ?: null;
        $BAN_CONTA      = trim((string)post('BAN_CONTA', ''));
        $BAN_CONTA_DV   = trim((string)post('BAN_CONTA_DV', '')) ?: null;
        $BAN_OPERACAO   = trim((string)post('BAN_OPERACAO', '')) ?: null;

        $BAN_CEDENTE_ENDERECO = trim((string)post('BAN_CEDENTE_ENDERECO', '')) ?: null;
        $BAN_CEDENTE_CIDADE   = trim((string)post('BAN_CEDENTE_CIDADE', '')) ?: null;
        $BAN_CEDENTE_UF       = strtoupper(trim((string)post('BAN_CEDENTE_UF', ''))) ?: null;
        $BAN_CEDENTE_CEP      = trim((string)post('BAN_CEDENTE_CEP', '')) ?: null;

        $BAN_CONVENIO = trim((string)post('BAN_CONVENIO', ''));
        $BAN_CARTEIRA = trim((string)post('BAN_CARTEIRA', ''));
        $BAN_MODALIDADE = trim((string)post('BAN_MODALIDADE', '')) ?: null;
        $BAN_CEDENTE_COD_BANCO = trim((string)post('BAN_CEDENTE_COD_BANCO', '')) ?: null;

        $BAN_NOSSO_NUM_TAM  = (int)post('BAN_NOSSO_NUM_TAM', 11);
        $BAN_NOSSO_NUM_PROX = (int)post('BAN_NOSSO_NUM_PROX', 1);

        $BAN_ACEITE = trim((string)post('BAN_ACEITE', 'N')) ?: 'N';
        $BAN_ESPECIE_DOC   = trim((string)post('BAN_ESPECIE_DOC', 'DM')) ?: 'DM';
        $BAN_ESPECIE_MOEDA = trim((string)post('BAN_ESPECIE_MOEDA', '9')) ?: '9';

        $BAN_PROTESTO_DIAS = (int)post('BAN_PROTESTO_DIAS', 0);
        $BAN_BAIXA_DIAS    = (int)post('BAN_BAIXA_DIAS', 0);
        $BAN_INSTRUCOES    = trim((string)post('BAN_INSTRUCOES', '')) ?: null;

        $BAN_CNAB = trim((string)post('BAN_CNAB', 'CNAB240')) ?: 'CNAB240';
        $BAN_LAYOUT = trim((string)post('BAN_LAYOUT', '')) ?: null;
        $BAN_COD_EMPRESA_BANCO = trim((string)post('BAN_COD_EMPRESA_BANCO', '')) ?: null;
        $BAN_COD_TRANSMISSAO   = trim((string)post('BAN_COD_TRANSMISSAO', '')) ?: null;

        $BAN_REMESSA_SEQ_PROX = (int)post('BAN_REMESSA_SEQ_PROX', 1);
        $BAN_LOTE_SEQ_PROX    = (int)post('BAN_LOTE_SEQ_PROX', 1);
        $BAN_REG_SEQ_PROX     = (int)post('BAN_REG_SEQ_PROX', 1);
        $BAN_ARQ_PREFIXO      = trim((string)post('BAN_ARQ_PREFIXO', '')) ?: null;

        // ===== validações mínimas =====
        if ($BAN_APELIDO === '' || $BAN_NOME === '' || $BAN_CODIGO === '' || strlen($BAN_CODIGO) !== 3) {
            json_out(['ok' => false, 'msg' => 'Informe Apelido, Banco (COMPE 3 dígitos) e Nome do banco.'], 400);
        }
        // Se NÃO for caixa interno, validar demais abas
        if (!$BAN_CAIXA_INTERNO) {
            if ($BAN_CEDENTE_NOME === '' || $BAN_CEDENTE_DOC === '' || $BAN_AGENCIA === '' || $BAN_CONTA === '') {
                json_out(['ok' => false, 'msg' => 'Informe Cedente (nome/doc), Agência e Conta.'], 400);
            }
            if ($BAN_CONVENIO === '' || $BAN_CARTEIRA === '') {
                json_out(['ok' => false, 'msg' => 'Informe Convênio e Carteira.'], 400);
            }
        }

        // ===== insert/update =====
        $data = [
            ':BAN_APELIDO' => $BAN_APELIDO,
            ':BAN_STATUS' => $BAN_STATUS,
            ':BAN_CODIGO' => $BAN_CODIGO,
            ':BAN_NOME' => $BAN_NOME,
            ':BAN_ISPB' => $BAN_ISPB,
            ':BAN_AMBIENTE' => $BAN_AMBIENTE,
            ':BAN_SITE' => $BAN_SITE,
            ':BAN_OBSERVACAO' => $BAN_OBSERVACAO,
            ':BAN_CAIXA_INTERNO' => $BAN_CAIXA_INTERNO,

            ':BAN_CEDENTE_NOME' => $BAN_CEDENTE_NOME,
            ':BAN_CEDENTE_DOC' => $BAN_CEDENTE_DOC,
            ':BAN_CEDENTE_TIPO_DOC' => $BAN_CEDENTE_TIPO_DOC,
            ':BAN_CODIGO_CEDENTE' => $BAN_CODIGO_CEDENTE,
            ':BAN_AGENCIA' => $BAN_AGENCIA,
            ':BAN_AGENCIA_DV' => $BAN_AGENCIA_DV,
            ':BAN_CONTA' => $BAN_CONTA,
            ':BAN_CONTA_DV' => $BAN_CONTA_DV,
            ':BAN_OPERACAO' => $BAN_OPERACAO,
            ':BAN_CEDENTE_ENDERECO' => $BAN_CEDENTE_ENDERECO,
            ':BAN_CEDENTE_CIDADE' => $BAN_CEDENTE_CIDADE,
            ':BAN_CEDENTE_UF' => $BAN_CEDENTE_UF,
            ':BAN_CEDENTE_CEP' => $BAN_CEDENTE_CEP,

            ':BAN_CONVENIO' => $BAN_CONVENIO,
            ':BAN_CARTEIRA' => $BAN_CARTEIRA,
            ':BAN_MODALIDADE' => $BAN_MODALIDADE,
            ':BAN_CEDENTE_COD_BANCO' => $BAN_CEDENTE_COD_BANCO,
            ':BAN_NOSSO_NUM_TAM' => $BAN_NOSSO_NUM_TAM,
            ':BAN_NOSSO_NUM_PROX' => $BAN_NOSSO_NUM_PROX,
            ':BAN_ACEITE' => $BAN_ACEITE,
            ':BAN_ESPECIE_DOC' => $BAN_ESPECIE_DOC,
            ':BAN_ESPECIE_MOEDA' => $BAN_ESPECIE_MOEDA,
            ':BAN_PROTESTO_DIAS' => $BAN_PROTESTO_DIAS,
            ':BAN_BAIXA_DIAS' => $BAN_BAIXA_DIAS,
            ':BAN_INSTRUCOES' => $BAN_INSTRUCOES,

            ':BAN_CNAB' => $BAN_CNAB,
            ':BAN_LAYOUT' => $BAN_LAYOUT,
            ':BAN_COD_EMPRESA_BANCO' => $BAN_COD_EMPRESA_BANCO,
            ':BAN_COD_TRANSMISSAO' => $BAN_COD_TRANSMISSAO,
            ':BAN_REMESSA_SEQ_PROX' => $BAN_REMESSA_SEQ_PROX,
            ':BAN_LOTE_SEQ_PROX' => $BAN_LOTE_SEQ_PROX,
            ':BAN_REG_SEQ_PROX' => $BAN_REG_SEQ_PROX,
            ':BAN_ARQ_PREFIXO' => $BAN_ARQ_PREFIXO,
        ];

        if ($BAN_ID > 0) {
            $sql = "UPDATE tb_banco SET
                        BAN_APELIDO = :BAN_APELIDO,
                        BAN_STATUS = :BAN_STATUS,
                        BAN_CAIXA_INTERNO = :BAN_CAIXA_INTERNO,
                        BAN_CODIGO = :BAN_CODIGO,
                        BAN_NOME = :BAN_NOME,
                        BAN_ISPB = :BAN_ISPB,
                        BAN_AMBIENTE = :BAN_AMBIENTE,
                        BAN_SITE = :BAN_SITE,
                        BAN_OBSERVACAO = :BAN_OBSERVACAO,

                        BAN_CEDENTE_NOME = :BAN_CEDENTE_NOME,
                        BAN_CEDENTE_DOC = :BAN_CEDENTE_DOC,
                        BAN_CEDENTE_TIPO_DOC = :BAN_CEDENTE_TIPO_DOC,
                        BAN_CODIGO_CEDENTE = :BAN_CODIGO_CEDENTE,
                        BAN_AGENCIA = :BAN_AGENCIA,
                        BAN_AGENCIA_DV = :BAN_AGENCIA_DV,
                        BAN_CONTA = :BAN_CONTA,
                        BAN_CONTA_DV = :BAN_CONTA_DV,
                        BAN_OPERACAO = :BAN_OPERACAO,
                        BAN_CEDENTE_ENDERECO = :BAN_CEDENTE_ENDERECO,
                        BAN_CEDENTE_CIDADE = :BAN_CEDENTE_CIDADE,
                        BAN_CEDENTE_UF = :BAN_CEDENTE_UF,
                        BAN_CEDENTE_CEP = :BAN_CEDENTE_CEP,

                        BAN_CONVENIO = :BAN_CONVENIO,
                        BAN_CARTEIRA = :BAN_CARTEIRA,
                        BAN_MODALIDADE = :BAN_MODALIDADE,
                        BAN_CEDENTE_COD_BANCO = :BAN_CEDENTE_COD_BANCO,
                        BAN_NOSSO_NUM_TAM = :BAN_NOSSO_NUM_TAM,
                        BAN_NOSSO_NUM_PROX = :BAN_NOSSO_NUM_PROX,
                        BAN_ACEITE = :BAN_ACEITE,
                        BAN_ESPECIE_DOC = :BAN_ESPECIE_DOC,
                        BAN_ESPECIE_MOEDA = :BAN_ESPECIE_MOEDA,
                        BAN_PROTESTO_DIAS = :BAN_PROTESTO_DIAS,
                        BAN_BAIXA_DIAS = :BAN_BAIXA_DIAS,
                        BAN_INSTRUCOES = :BAN_INSTRUCOES,

                        BAN_CNAB = :BAN_CNAB,
                        BAN_LAYOUT = :BAN_LAYOUT,
                        BAN_COD_EMPRESA_BANCO = :BAN_COD_EMPRESA_BANCO,
                        BAN_COD_TRANSMISSAO = :BAN_COD_TRANSMISSAO,
                        BAN_REMESSA_SEQ_PROX = :BAN_REMESSA_SEQ_PROX,
                        BAN_LOTE_SEQ_PROX = :BAN_LOTE_SEQ_PROX,
                        BAN_REG_SEQ_PROX = :BAN_REG_SEQ_PROX,
                        BAN_ARQ_PREFIXO = :BAN_ARQ_PREFIXO
                    WHERE BAN_ID = :BAN_ID
                    LIMIT 1";
            $data[':BAN_ID'] = $BAN_ID;

            $st = $db->prepare($sql);
            $st->execute($data);

            json_out(['ok' => true, 'msg' => 'Atualizado', 'id' => $BAN_ID]);
        } else {
            $sql = "INSERT INTO tb_banco (
                        BAN_APELIDO, BAN_STATUS, BAN_CAIXA_INTERNO, BAN_CODIGO, BAN_NOME, BAN_ISPB, BAN_AMBIENTE, BAN_SITE, BAN_OBSERVACAO,
                        BAN_CEDENTE_NOME, BAN_CEDENTE_DOC, BAN_CEDENTE_TIPO_DOC, BAN_CODIGO_CEDENTE,
                        BAN_AGENCIA, BAN_AGENCIA_DV, BAN_CONTA, BAN_CONTA_DV, BAN_OPERACAO,
                        BAN_CEDENTE_ENDERECO, BAN_CEDENTE_CIDADE, BAN_CEDENTE_UF, BAN_CEDENTE_CEP,
                        BAN_CONVENIO, BAN_CARTEIRA, BAN_MODALIDADE, BAN_CEDENTE_COD_BANCO,
                        BAN_NOSSO_NUM_TAM, BAN_NOSSO_NUM_PROX, BAN_ACEITE, BAN_ESPECIE_DOC, BAN_ESPECIE_MOEDA,
                        BAN_PROTESTO_DIAS, BAN_BAIXA_DIAS, BAN_INSTRUCOES,
                        BAN_CNAB, BAN_LAYOUT, BAN_COD_EMPRESA_BANCO, BAN_COD_TRANSMISSAO,
                        BAN_REMESSA_SEQ_PROX, BAN_LOTE_SEQ_PROX, BAN_REG_SEQ_PROX, BAN_ARQ_PREFIXO
                    ) VALUES (
                        :BAN_APELIDO, :BAN_STATUS, :BAN_CAIXA_INTERNO, :BAN_CODIGO, :BAN_NOME, :BAN_ISPB, :BAN_AMBIENTE, :BAN_SITE, :BAN_OBSERVACAO,
                        :BAN_CEDENTE_NOME, :BAN_CEDENTE_DOC, :BAN_CEDENTE_TIPO_DOC, :BAN_CODIGO_CEDENTE,
                        :BAN_AGENCIA, :BAN_AGENCIA_DV, :BAN_CONTA, :BAN_CONTA_DV, :BAN_OPERACAO,
                        :BAN_CEDENTE_ENDERECO, :BAN_CEDENTE_CIDADE, :BAN_CEDENTE_UF, :BAN_CEDENTE_CEP,
                        :BAN_CONVENIO, :BAN_CARTEIRA, :BAN_MODALIDADE, :BAN_CEDENTE_COD_BANCO,
                        :BAN_NOSSO_NUM_TAM, :BAN_NOSSO_NUM_PROX, :BAN_ACEITE, :BAN_ESPECIE_DOC, :BAN_ESPECIE_MOEDA,
                        :BAN_PROTESTO_DIAS, :BAN_BAIXA_DIAS, :BAN_INSTRUCOES,
                        :BAN_CNAB, :BAN_LAYOUT, :BAN_COD_EMPRESA_BANCO, :BAN_COD_TRANSMISSAO,
                        :BAN_REMESSA_SEQ_PROX, :BAN_LOTE_SEQ_PROX, :BAN_REG_SEQ_PROX, :BAN_ARQ_PREFIXO
                    )";
            $st = $db->prepare($sql);
            $st->execute($data);

            $newId = (int)$db->lastInsertId();
            json_out(['ok' => true, 'msg' => 'Inserido', 'id' => $newId]);
        }
    }

    if ($acao === 'inativar' || $acao === 'reativar') {
        $id = (int)post('id', 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 400);

        $novo = ($acao === 'inativar') ? 'INATIVO' : 'ATIVO';

        $st = $db->prepare("UPDATE tb_banco SET BAN_STATUS = :st WHERE BAN_ID = :id LIMIT 1");
        $st->execute([':st' => $novo, ':id' => $id]);

        json_out(['ok' => true, 'msg' => 'Ok']);
    }

    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    // erro comum: apelido duplicado (UK_BAN_APELIDO)
    $msg = $e->getMessage();
    if (str_contains($msg, 'UK_BAN_APELIDO') || str_contains($msg, 'BAN_APELIDO')) {
        json_out(['ok' => false, 'msg' => 'Já existe um cadastro com esse APELIDO.'], 400);
    }
    json_out(['ok' => false, 'msg' => 'Erro no banco de dados.'], 500);
} catch (Throwable $e) {
    json_out(['ok' => false, 'msg' => $e->getMessage()], 500);
}
