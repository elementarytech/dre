<?php
// /app/endpoints/tb_centro_custo.php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/centro_custo_endpoint.log');

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) json_out(['ok' => false, 'msg' => 'Não autenticado.'], 401);

$acao = $_REQUEST['acao'] ?? '';

try {
    $asStr = static fn($v) => trim((string)($v ?? ''));
    $asInt = static fn($v) => (int)($v ?? 0);

    // LISTAR
    if ($acao === 'listar') {
        $buscar = $asStr($_GET['buscar'] ?? '');
        $status = strtoupper($asStr($_GET['status'] ?? '')); // ATIVO/INATIVO ou vazio

        $sql = "SELECT
                    CEC_ID, CEC_CODIGO, CEC_NOME, CEC_STATUS, CEC_OBS, CEC_CRIADO_EM
                FROM tb_centro_custo
                WHERE 1=1";
        $params = [];

        if ($buscar !== '') {
            $sql .= " AND (CEC_CODIGO LIKE ? OR CEC_NOME LIKE ?)";
            $like = "%{$buscar}%";
            $params[] = $like;
            $params[] = $like;
        }
        if ($status !== '') {
            $sql .= " AND CEC_STATUS = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY CEC_CODIGO ASC, CEC_NOME ASC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        json_out(['ok' => true, 'rows' => $rows, 'total' => count($rows)]);
    }

    // GET
    if ($acao === 'get') {
        $id = $asInt($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("SELECT * FROM tb_centro_custo WHERE CEC_ID=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'msg' => 'Centro de custo não encontrado.'], 404);

        json_out(['ok' => true, 'row' => $row]);
    }

    // SALVAR (CRIAR/EDITAR)
    if ($acao === 'salvar') {
        require_post();

        $id     = $asInt($_POST['CEC_ID'] ?? 0);
        $codigo = $asStr($_POST['CEC_CODIGO'] ?? '');
        $nome   = $asStr($_POST['CEC_NOME'] ?? '');
        $status = strtoupper($asStr($_POST['CEC_STATUS'] ?? 'ATIVO'));
        $empresaFk = $asInt($_POST['CEC_EMPRESA_FK'] ?? 0);
        $obs    = $asStr($_POST['CEC_OBS'] ?? '');

        if ($codigo === '') json_out(['ok' => false, 'msg' => 'Informe o código.'], 422);
        if ($nome === '') json_out(['ok' => false, 'msg' => 'Informe o nome.'], 422);
        if (!in_array($status, ['ATIVO', 'INATIVO'], true)) $status = 'ATIVO';

        if ($id <= 0) {
            // evita duplicar código
            $st0 = $pdo->prepare("SELECT 1 FROM tb_centro_custo WHERE CEC_CODIGO=? LIMIT 1");
            $st0->execute([$codigo]);
            if ($st0->fetchColumn()) json_out(['ok' => false, 'msg' => 'Código já cadastrado.'], 409);

            $st = $pdo->prepare("INSERT INTO tb_centro_custo (CEC_CODIGO, CEC_EMPRESA_FK, CEC_NOME, CEC_STATUS, CEC_OBS)
                                 VALUES (?,?,?,?,?)");
            $st->execute([$codigo, ($empresaFk > 0 ? $empresaFk : null), $nome, $status, $obs]);
            $id = (int)$pdo->lastInsertId();

            json_out(['ok' => true, 'msg' => 'Centro de custo criado.', 'id' => $id]);
        } else {
            // evita duplicar código em outro registro
            $st0 = $pdo->prepare("SELECT 1 FROM tb_centro_custo WHERE CEC_CODIGO=? AND CEC_ID<>? LIMIT 1");
            $st0->execute([$codigo, $id]);
            if ($st0->fetchColumn()) json_out(['ok' => false, 'msg' => 'Código já cadastrado em outro registro.'], 409);

            $st = $pdo->prepare("UPDATE tb_centro_custo
                                 SET CEC_CODIGO=?, CEC_EMPRESA_FK=?, CEC_NOME=?, CEC_STATUS=?, CEC_OBS=?
                                 WHERE CEC_ID=?");
            $st->execute([$codigo, ($empresaFk > 0 ? $empresaFk : null), $nome, $status, $obs, $id]);

            json_out(['ok' => true, 'msg' => 'Centro de custo atualizado.', 'id' => $id]);
        }
    }

    // EXCLUIR
    if ($acao === 'excluir') {
        require_post();
        $id = $asInt($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        // (opcional) aqui você pode impedir exclusão se existir vínculo em lançamentos
        $st = $pdo->prepare("DELETE FROM tb_centro_custo WHERE CEC_ID=?");
        $st->execute([$id]);

        json_out(['ok' => true, 'msg' => 'Centro de custo excluído.']);
    }


    // COMBO EMPRESAS (para selects)
    if ($acao === 'empresas_combo') {
        $st = $pdo->query("SELECT EMP_ID AS id, EMP_RAZAO_SOCIAL AS nome
                           FROM tb_empresa
                           ORDER BY EMP_RAZAO_SOCIAL ASC");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'rows' => $rows]);
    }



    // COMBO (para selects)
    if ($acao === 'combo') {
        $empresa_fk = $asInt($_GET['empresa_fk'] ?? 0);

        $sql = "SELECT CEC_ID, CEC_CODIGO, CEC_NOME
            FROM tb_centro_custo
            WHERE CEC_STATUS='ATIVO'";

        $params = [];

        if ($empresa_fk > 0) {
            $sql .= " AND CEC_EMPRESA_FK = ?";
            $params[] = $empresa_fk;
        }

        $sql .= " ORDER BY CEC_CODIGO ASC, CEC_NOME ASC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['CEC_LABEL'] = trim(($r['CEC_CODIGO'] ? $r['CEC_CODIGO'] . ' - ' : '') . ($r['CEC_NOME'] ?? ''));
        }

        json_out(['ok' => true, 'rows' => $rows]);
    }




    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    json_out(['ok' => false, 'msg' => 'Erro no banco.', 'detail' => $e->getMessage()], 500);
} catch (Throwable $e) {
    json_out(['ok' => false, 'msg' => 'Erro interno.', 'detail' => $e->getMessage()], 500);
}
