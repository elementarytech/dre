<?php
// /app/endpoints/grupo_documentos.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexao.php';

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) @ob_end_clean();
}
header_remove();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    json_out(['ok' => false, 'msg' => 'Não autenticado.'], 401);
}

$acao = $_REQUEST['acao'] ?? '';

try {
    if ($acao === 'listar') {
        $st = $pdo->query("
            SELECT GDO_CODIGO_PK, GDO_TIPO, GDO_DOCUMENTO, GDO_NOME, GDO_STATUS, GDO_OBSERVACAO,
                   DATE_FORMAT(GDO_DATA_CADASTRO, '%d/%m/%Y %H:%i') AS data_cadastro_br,
                   GDO_USUARIO
            FROM tb_grupo_documento
            ORDER BY GDO_STATUS ASC, GDO_TIPO, GDO_NOME
        ");
        json_out(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($acao === 'salvar') {
        $id   = (int)($_POST['id'] ?? 0);
        $tipo = strtoupper(trim((string)($_POST['tipo'] ?? '')));
        $doc  = preg_replace('/\D+/', '', (string)($_POST['documento'] ?? ''));
        $nome = trim((string)($_POST['nome'] ?? ''));
        $obs  = trim((string)($_POST['observacao'] ?? ''));

        if (!in_array($tipo, ['PJ','PF'], true)) {
            json_out(['ok' => false, 'msg' => 'Tipo inválido (PJ ou PF).'], 422);
        }
        if (!in_array(strlen($doc), [11, 14], true)) {
            json_out(['ok' => false, 'msg' => 'Documento inválido — precisa ter 11 (CPF) ou 14 (CNPJ) dígitos.'], 422);
        }
        if ($nome === '') {
            json_out(['ok' => false, 'msg' => 'Informe o nome.'], 422);
        }
        if (($tipo === 'PJ' && strlen($doc) !== 14) || ($tipo === 'PF' && strlen($doc) !== 11)) {
            json_out(['ok' => false, 'msg' => 'Tipo e quantidade de dígitos do documento não combinam.'], 422);
        }

        $usuario = (string)($_SESSION['user_nome'] ?? $_SESSION['usuarioSession'] ?? 'Sistema');

        if ($id > 0) {
            $st = $pdo->prepare("UPDATE tb_grupo_documento
                                 SET GDO_TIPO = ?, GDO_DOCUMENTO = ?, GDO_NOME = ?, GDO_OBSERVACAO = ?
                                 WHERE GDO_CODIGO_PK = ?");
            $st->execute([$tipo, $doc, $nome, $obs, $id]);
        } else {
            $st = $pdo->prepare("INSERT INTO tb_grupo_documento
                                   (GDO_TIPO, GDO_DOCUMENTO, GDO_NOME, GDO_OBSERVACAO, GDO_USUARIO)
                                 VALUES (?, ?, ?, ?, ?)");
            $st->execute([$tipo, $doc, $nome, $obs, $usuario]);
        }
        json_out(['ok' => true, 'msg' => 'Salvo.']);
    }

    if ($acao === 'alternar_status') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE tb_grupo_documento
                       SET GDO_STATUS = IF(GDO_STATUS = 'ATIVO', 'INATIVO', 'ATIVO')
                       WHERE GDO_CODIGO_PK = ?")->execute([$id]);
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    // Duplicado de documento → mensagem amigável
    if ((int)$e->getCode() === 23000) {
        json_out(['ok' => false, 'msg' => 'Esse documento já está cadastrado no grupo.'], 422);
    }
    json_out(['ok' => false, 'msg' => 'Erro: ' . $e->getMessage()], 500);
}
