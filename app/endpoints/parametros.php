<?php
// /app/endpoints/parametros.php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexao.php';
/** @var PDO $pdo */

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

try {
    if ($action === 'listar') {
        $rows = $pdo->query("
            SELECT CFG_CHAVE, CFG_GRUPO, CFG_DESCRICAO, CFG_VALOR, CFG_TIPO
            FROM tb_configuracoes
            ORDER BY CFG_GRUPO, CFG_CHAVE
        ")->fetchAll();
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    if ($action === 'salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!isset($body['parametros']) || !is_array($body['parametros'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'Payload inválido.']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE tb_configuracoes SET CFG_VALOR = :valor
            WHERE CFG_CHAVE = :chave
        ");

        $pdo->beginTransaction();
        foreach ($body['parametros'] as $chave => $valor) {
            // valida que a chave existe
            $check = $pdo->prepare("SELECT 1 FROM tb_configuracoes WHERE CFG_CHAVE = ?");
            $check->execute([$chave]);
            if (!$check->fetchColumn()) continue;

            $stmt->execute([':valor' => $valor, ':chave' => $chave]);
        }
        $pdo->commit();

        echo json_encode(['ok' => true, 'msg' => 'Parâmetros salvos com sucesso.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
