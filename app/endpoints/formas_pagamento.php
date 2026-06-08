<?php
// /app/endpoints/formas_pagamento.php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexao.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function db(): PDO
{
    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Conexão com banco não disponível.');
    }
    return $pdo;
}

function req(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function qry(string $key, string $default = ''): string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

try {
    $acao = strtolower(trim((string)($_REQUEST['acao'] ?? 'listar')));

    if ($acao === 'combo') {
        $st = db()->prepare("SELECT FPG_CODIGO_PK, FPG_DESCRICAO FROM tb_forma_pagamento WHERE FPG_STATUS='ATIVO' ORDER BY FPG_DESCRICAO");
        $st->execute();
        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'listar') {
        $buscar = qry('buscar');
        $status = strtoupper(qry('status'));

        $sql = "SELECT FPG_CODIGO_PK, FPG_DESCRICAO, FPG_STATUS FROM tb_forma_pagamento WHERE 1=1";
        $params = [];

        if ($buscar !== '') {
            $sql .= " AND (CAST(FPG_CODIGO_PK AS CHAR) LIKE ? OR FPG_DESCRICAO LIKE ?)";
            $params[] = "%{$buscar}%";
            $params[] = "%{$buscar}%";
        }

        if ($status !== '') {
            $sql .= " AND FPG_STATUS = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY FPG_DESCRICAO ASC, FPG_CODIGO_PK ASC";
        $st = db()->prepare($sql);
        $st->execute($params);

        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'get') {
        $id = (int)qry('id', '0');
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = db()->prepare("SELECT FPG_CODIGO_PK, FPG_DESCRICAO, FPG_STATUS FROM tb_forma_pagamento WHERE FPG_CODIGO_PK = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            json_out(['ok' => false, 'msg' => 'Registro não encontrado.'], 404);
        }

        json_out(['ok' => true, 'row' => $row]);
    }

    if ($acao === 'salvar') {
        $id = (int)req('FPG_CODIGO_PK', '0');
        $descricao = req('FPG_DESCRICAO');
        $status = strtoupper(req('FPG_STATUS', 'ATIVO'));

        if ($descricao === '') {
            json_out(['ok' => false, 'msg' => 'Informe a descrição.'], 422);
        }

        if (!in_array($status, ['ATIVO', 'INATIVO'], true)) {
            $status = 'ATIVO';
        }

        $stDup = db()->prepare("SELECT FPG_CODIGO_PK FROM tb_forma_pagamento WHERE UPPER(TRIM(FPG_DESCRICAO)) = UPPER(TRIM(?)) AND FPG_CODIGO_PK <> ? LIMIT 1");
        $stDup->execute([$descricao, $id]);
        if ($stDup->fetch()) {
            json_out(['ok' => false, 'msg' => 'Já existe uma forma de pagamento com essa descrição.'], 409);
        }

        if ($id <= 0) {
            $st = db()->prepare("INSERT INTO tb_forma_pagamento (FPG_DESCRICAO, FPG_STATUS) VALUES (?, ?)");
            $st->execute([$descricao, $status]);
            $id = (int)db()->lastInsertId();
        } else {
            $st = db()->prepare("UPDATE tb_forma_pagamento SET FPG_DESCRICAO = ?, FPG_STATUS = ? WHERE FPG_CODIGO_PK = ?");
            $st->execute([$descricao, $status, $id]);
        }

        json_out(['ok' => true, 'id' => $id, 'msg' => 'Registro salvo com sucesso.']);
    }

    if ($acao === 'excluir') {
        $id = (int)req('id', '0');
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $sqlChecks = [
            ['tb_contas_pagar', 'CPG_FORMA_PAGAMENTO'],
            ['tb_contas_receber', 'CPR_FORMA_PAGAMENTO'],
        ];

        foreach ($sqlChecks as [$table, $field]) {
            try {
                $st = db()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$field} = ?");
                $st->execute([$id]);
                $qtd = (int)$st->fetchColumn();
                if ($qtd > 0) {
                    json_out(['ok' => false, 'msg' => 'Esta forma de pagamento está vinculada a lançamentos e não pode ser excluída.'], 409);
                }
            } catch (Throwable $e) {
                // ignora se a tabela/campo não existir neste ambiente
            }
        }

        $st = db()->prepare("DELETE FROM tb_forma_pagamento WHERE FPG_CODIGO_PK = ?");
        $st->execute([$id]);

        json_out(['ok' => true, 'msg' => 'Registro excluído com sucesso.']);
    }

    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    json_out(['ok' => false, 'msg' => 'Erro no banco.', 'detail' => $e->getMessage()], 500);
} catch (Throwable $e) {
    json_out(['ok' => false, 'msg' => 'Erro interno.', 'detail' => $e->getMessage()], 500);
}
