<?php
// /app/endpoints/fornecedores.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexao.php';

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

function get_db(): PDO
{
    if (class_exists('conexao') && method_exists('conexao', 'getInstance')) {
        $db = conexao::getInstance();
        if ($db instanceof PDO) return $db;
    }

    foreach (['db', 'getPDO', 'conexao', 'pdo'] as $fn) {
        if (function_exists($fn)) {
            $db = $fn();
            if ($db instanceof PDO) return $db;
        }
    }

    foreach (['pdo', 'db', 'conexao', 'conn', 'connection'] as $var) {
        if (isset($GLOBALS[$var]) && $GLOBALS[$var] instanceof PDO) {
            return $GLOBALS[$var];
        }
    }

    throw new RuntimeException('Conexão não encontrada. Verifique config/conexao.php (PDO/função/classe).');
}

function only_digits(?string $v): string
{
    $v = (string)($v ?? '');
    return preg_replace('/\D+/', '', $v) ?? '';
}

function validar_cpf(string $cpf): bool
{
    $cpf = only_digits($cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;

    for ($t = 9; $t <= 10; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) {
            $sum += (int)$cpf[$i] * ($t + 1 - $i);
        }
        $rem = (10 * $sum) % 11;
        if ((int)$cpf[$t] !== ($rem >= 10 ? 0 : $rem)) return false;
    }
    return true;
}

function validar_cnpj(string $cnpj): bool
{
    $cnpj = only_digits($cnpj);
    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) return false;

    $calc = function (string $n, int $len) {
        $sum = 0;
        $pos = $len - 7;
        for ($i = $len; $i >= 1; $i--) {
            $sum += (int)$n[$len - $i] * $pos--;
            if ($pos < 2) $pos = 9;
        }
        $rem = $sum % 11;
        return $rem < 2 ? 0 : 11 - $rem;
    };

    return (int)$cnpj[12] === $calc($cnpj, 12)
        && (int)$cnpj[13] === $calc($cnpj, 13);
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
    $db   = get_db();
    $acao = (string)(get('acao', post('acao', '')));

    /* ── listar ─────────────────────────────────────────────────── */
    if ($acao === 'listar') {
        $buscar = trim((string)get('buscar', ''));
        $status = trim((string)get('status', ''));
        $uf     = strtoupper(trim((string)get('uf', '')));

        $where  = [];
        $params = [];

        if ($status !== '') {
            $where[]          = "FOR_STATUS = :status";
            $params[':status'] = $status;
        }

        if ($uf !== '') {
            $where[]      = "FOR_UF = :uf";
            $params[':uf'] = $uf;
        }

        if ($buscar !== '') {
            $where[] = "("
                . "FOR_RAZAO_SOCIAL LIKE :q1 OR "
                . "FOR_NOME_FANTASIA LIKE :q2 OR "
                . "FOR_CNPJ LIKE :q3 OR "
                . "FOR_TELEFONE LIKE :q4 OR "
                . "FOR_EMAIL LIKE :q5 OR "
                . "FOR_CIDADE LIKE :q6 OR "
                . "FOR_ENDERECO LIKE :q7"
                . ")";

            $like           = '%' . $buscar . '%';
            $params[':q1']  = $like;
            $params[':q2']  = $like;
            $params[':q3']  = $like;
            $params[':q4']  = $like;
            $params[':q5']  = $like;
            $params[':q6']  = $like;
            $params[':q7']  = $like;
        }

        $sql = "SELECT
                FOR_CODIGO_PK,
                FOR_TIPO,
                FOR_CNPJ,
                FOR_RAZAO_SOCIAL,
                FOR_NOME_FANTASIA,
                FOR_CEP,
                FOR_ENDERECO,
                FOR_NUMERO,
                FOR_COMPLEMENTO,
                FOR_BAIRRO,
                FOR_UF,
                FOR_CIDADE,
                FOR_TELEFONE,
                FOR_EMAIL,
                FOR_STATUS,
                FOR_CREATED_AT,
                FOR_UPDATED_AT
            FROM tb_fornecedor";

        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY FOR_STATUS ASC, FOR_RAZAO_SOCIAL ASC, FOR_CODIGO_PK DESC";

        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        json_out(['ok' => true, 'rows' => $rows, 'total' => count($rows)]);
    }

    /* ── combo ───────────────────────────────────────────────────── */
    if ($acao === 'combo') {
        $st = $db->prepare("
            SELECT FOR_CODIGO_PK, FOR_TIPO, FOR_RAZAO_SOCIAL, FOR_NOME_FANTASIA, FOR_CNPJ
            FROM tb_fornecedor
            WHERE FOR_STATUS = 'ATIVO'
            ORDER BY FOR_RAZAO_SOCIAL ASC
        ");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            $fantasia = trim((string)($r['FOR_NOME_FANTASIA'] ?? ''));
            $razao    = trim((string)($r['FOR_RAZAO_SOCIAL']  ?? ''));
            $cnpj     = trim((string)($r['FOR_CNPJ']          ?? ''));

            $label = $razao;
            if ($fantasia !== '' && mb_strtoupper($fantasia) !== mb_strtoupper($razao)) {
                $label .= ' (' . $fantasia . ')';
            }
            if ($cnpj !== '') {
                $label .= ' - ' . $cnpj;
            }

            $r['FOR_LABEL'] = $label;
        }
        unset($r);

        json_out(['ok' => true, 'rows' => $rows, 'total' => count($rows)]);
    }

    /* ── get ─────────────────────────────────────────────────────── */
    if ($acao === 'get') {
        $id = (int)get('id', 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 400);

        $st = $db->prepare("SELECT * FROM tb_fornecedor WHERE FOR_CODIGO_PK = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) json_out(['ok' => false, 'msg' => 'Registro não encontrado.'], 404);

        json_out(['ok' => true, 'row' => $row]);
    }

    /* ── salvar ──────────────────────────────────────────────────── */
    if ($acao === 'salvar') {
        $FOR_CODIGO_PK     = (int)post('FOR_CODIGO_PK', 0);
        $FOR_TIPO          = strtoupper(trim((string)post('FOR_TIPO', 'JURIDICA')));
        $FOR_CNPJ          = trim((string)post('FOR_CNPJ', ''));
        $FOR_RAZAO_SOCIAL  = trim((string)post('FOR_RAZAO_SOCIAL', ''));
        $FOR_NOME_FANTASIA = trim((string)post('FOR_NOME_FANTASIA', '')) ?: null;
        $FOR_CEP           = trim((string)post('FOR_CEP', ''))           ?: null;
        $FOR_ENDERECO      = trim((string)post('FOR_ENDERECO', ''))      ?: null;
        $FOR_NUMERO        = trim((string)post('FOR_NUMERO', ''))        ?: null;
        $FOR_COMPLEMENTO   = trim((string)post('FOR_COMPLEMENTO', ''))   ?: null;
        $FOR_BAIRRO        = trim((string)post('FOR_BAIRRO', ''))        ?: null;
        $FOR_UF            = strtoupper(trim((string)post('FOR_UF', ''))) ?: null;
        $FOR_CIDADE        = trim((string)post('FOR_CIDADE', ''))        ?: null;
        $FOR_TELEFONE      = trim((string)post('FOR_TELEFONE', ''))      ?: null;
        $FOR_EMAIL         = trim((string)post('FOR_EMAIL', ''))         ?: null;
        $FOR_STATUS        = trim((string)post('FOR_STATUS', 'ATIVO'))   ?: 'ATIVO';

        // Normaliza tipo
        if (!in_array($FOR_TIPO, ['FISICA', 'JURIDICA'], true)) {
            $FOR_TIPO = 'JURIDICA';
        }

        // Validação CPF ou CNPJ conforme o tipo
        if ($FOR_TIPO === 'FISICA') {
            if (!validar_cpf($FOR_CNPJ)) {
                json_out(['ok' => false, 'msg' => 'Informe um CPF válido.'], 400);
            }
        } else {
            if (!validar_cnpj($FOR_CNPJ)) {
                json_out(['ok' => false, 'msg' => 'Informe um CNPJ válido.'], 400);
            }
        }

        if ($FOR_RAZAO_SOCIAL === '') {
            $label = $FOR_TIPO === 'FISICA' ? 'nome' : 'razão social';
            json_out(['ok' => false, 'msg' => "Informe o $label."], 400);
        }

        if ($FOR_TELEFONE === null || $FOR_TELEFONE === '') json_out(['ok' => false, 'msg' => 'Informe o telefone.'], 400);
        if ($FOR_CEP      === null || $FOR_CEP      === '') json_out(['ok' => false, 'msg' => 'Informe o CEP.'], 400);
        if ($FOR_ENDERECO === null || $FOR_ENDERECO === '') json_out(['ok' => false, 'msg' => 'Informe o endereço.'], 400);
        if ($FOR_NUMERO   === null || $FOR_NUMERO   === '') json_out(['ok' => false, 'msg' => 'Informe o número.'], 400);
        if ($FOR_BAIRRO   === null || $FOR_BAIRRO   === '') json_out(['ok' => false, 'msg' => 'Informe o bairro.'], 400);
        if ($FOR_UF       === null || $FOR_UF       === '') json_out(['ok' => false, 'msg' => 'Selecione a UF.'], 400);
        if ($FOR_CIDADE   === null || $FOR_CIDADE   === '') json_out(['ok' => false, 'msg' => 'Selecione a cidade.'], 400);

        if ($FOR_EMAIL !== null && $FOR_EMAIL !== '' && !filter_var($FOR_EMAIL, FILTER_VALIDATE_EMAIL)) {
            json_out(['ok' => false, 'msg' => 'Informe um e-mail válido.'], 400);
        }

        $data = [
            ':FOR_TIPO'          => $FOR_TIPO,
            ':FOR_CNPJ'          => $FOR_CNPJ,
            ':FOR_RAZAO_SOCIAL'  => $FOR_RAZAO_SOCIAL,
            ':FOR_NOME_FANTASIA' => $FOR_NOME_FANTASIA,
            ':FOR_CEP'           => $FOR_CEP,
            ':FOR_ENDERECO'      => $FOR_ENDERECO,
            ':FOR_NUMERO'        => $FOR_NUMERO,
            ':FOR_COMPLEMENTO'   => $FOR_COMPLEMENTO,
            ':FOR_BAIRRO'        => $FOR_BAIRRO,
            ':FOR_UF'            => $FOR_UF,
            ':FOR_CIDADE'        => $FOR_CIDADE,
            ':FOR_TELEFONE'      => $FOR_TELEFONE,
            ':FOR_EMAIL'         => $FOR_EMAIL,
            ':FOR_STATUS'        => $FOR_STATUS,
        ];

        if ($FOR_CODIGO_PK > 0) {
            $sql = "UPDATE tb_fornecedor SET
                        FOR_TIPO          = :FOR_TIPO,
                        FOR_CNPJ          = :FOR_CNPJ,
                        FOR_RAZAO_SOCIAL  = :FOR_RAZAO_SOCIAL,
                        FOR_NOME_FANTASIA = :FOR_NOME_FANTASIA,
                        FOR_CEP           = :FOR_CEP,
                        FOR_ENDERECO      = :FOR_ENDERECO,
                        FOR_NUMERO        = :FOR_NUMERO,
                        FOR_COMPLEMENTO   = :FOR_COMPLEMENTO,
                        FOR_BAIRRO        = :FOR_BAIRRO,
                        FOR_UF            = :FOR_UF,
                        FOR_CIDADE        = :FOR_CIDADE,
                        FOR_TELEFONE      = :FOR_TELEFONE,
                        FOR_EMAIL         = :FOR_EMAIL,
                        FOR_STATUS        = :FOR_STATUS
                    WHERE FOR_CODIGO_PK = :FOR_CODIGO_PK
                    LIMIT 1";

            $data[':FOR_CODIGO_PK'] = $FOR_CODIGO_PK;

            $st = $db->prepare($sql);
            $st->execute($data);

            json_out(['ok' => true, 'msg' => 'Atualizado', 'id' => $FOR_CODIGO_PK]);
        } else {
            $sql = "INSERT INTO tb_fornecedor (
                        FOR_TIPO,
                        FOR_CNPJ,
                        FOR_RAZAO_SOCIAL,
                        FOR_NOME_FANTASIA,
                        FOR_CEP,
                        FOR_ENDERECO,
                        FOR_NUMERO,
                        FOR_COMPLEMENTO,
                        FOR_BAIRRO,
                        FOR_UF,
                        FOR_CIDADE,
                        FOR_TELEFONE,
                        FOR_EMAIL,
                        FOR_STATUS
                    ) VALUES (
                        :FOR_TIPO,
                        :FOR_CNPJ,
                        :FOR_RAZAO_SOCIAL,
                        :FOR_NOME_FANTASIA,
                        :FOR_CEP,
                        :FOR_ENDERECO,
                        :FOR_NUMERO,
                        :FOR_COMPLEMENTO,
                        :FOR_BAIRRO,
                        :FOR_UF,
                        :FOR_CIDADE,
                        :FOR_TELEFONE,
                        :FOR_EMAIL,
                        :FOR_STATUS
                    )";

            $st = $db->prepare($sql);
            $st->execute($data);

            $newId = (int)$db->lastInsertId();
            json_out(['ok' => true, 'msg' => 'Inserido', 'id' => $newId]);
        }
    }

    /* ── inativar / reativar ─────────────────────────────────────── */
    if ($acao === 'inativar' || $acao === 'reativar') {
        $id = (int)post('id', 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 400);

        $novo = ($acao === 'inativar') ? 'INATIVO' : 'ATIVO';

        $st = $db->prepare("UPDATE tb_fornecedor SET FOR_STATUS = :st WHERE FOR_CODIGO_PK = :id LIMIT 1");
        $st->execute([':st' => $novo, ':id' => $id]);

        json_out(['ok' => true, 'msg' => 'Ok']);
    }

    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    $msg = $e->getMessage();

    if (
        str_contains($msg, 'UQ_FOR_CNPJ') ||
        str_contains($msg, 'FOR_CNPJ')    ||
        str_contains($msg, 'Duplicate entry')
    ) {
        $tipo = strtoupper(trim((string)($_POST['FOR_TIPO'] ?? 'JURIDICA')));
        $doc  = $tipo === 'FISICA' ? 'CPF' : 'CNPJ';
        json_out(['ok' => false, 'msg' => "Já existe um fornecedor cadastrado com esse $doc."], 400);
    }

    json_out(['ok' => false, 'msg' => 'Erro no banco de dados.'], 500);
} catch (Throwable $e) {
    json_out(['ok' => false, 'msg' => $e->getMessage()], 500);
}
