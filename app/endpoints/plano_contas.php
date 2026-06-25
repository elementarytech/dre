<?php
// /app/endpoints/plano_contas.php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
ob_start();

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    ob_clean();
    echo json_encode(['ok' => false, 'msg' => 'Não autenticado.']);
    exit;
}

// helpers fallback (caso não exista no helpers.php)
if (!function_exists('json_out')) {
    function json_out(array $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('require_post')) {
    function require_post(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            json_out(['ok' => false, 'msg' => 'Método inválido.'], 405);
        }
    }
}

$acao = $_REQUEST['acao'] ?? '';

$asStr = static fn($v) => trim((string)($v ?? ''));
$asInt = static fn($v) => (int)($v ?? 0);

$calcNivel = static function (string $codigo): int {
    $codigo = trim($codigo);
    if ($codigo === '') return 1;
    $parts = array_values(array_filter(explode('.', $codigo), static fn($p) => trim($p) !== ''));
    return count($parts) > 0 ? count($parts) : 1;
};

try {
    // ============================================================
    // LISTAR
    // ============================================================
    if ($acao === 'listar') {
        $buscar     = $asStr($_GET['buscar'] ?? '');
        $empresa_fk = $asStr($_GET['empresa_fk'] ?? '');
        $tipo       = $asStr($_GET['tipo'] ?? '');
        $status     = $asStr($_GET['status'] ?? '');

        // paginação
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $perPage  = (int)($_GET['per_page'] ?? 20);
        if ($perPage < 5) $perPage = 5;
        if ($perPage > 100) $perPage = 100;
        $offset   = ($page - 1) * $perPage;

        $sql = "
            SELECT
                p.PLC_CODIGO_PK AS PLC_ID,
                p.PLC_CODIGO_PK,
                p.PLC_EMPRESA_FK,
                p.PLC_PARENT_ID,
                p.PLC_CODIGO,
                p.PLC_NOME,
                p.PLC_TIPO,
                p.PLC_NIVEL,
                p.PLC_STATUS,
                p.PLC_OBS,
                e.EMP_RAZAO_SOCIAL
            FROM tb_plano_contas p
            LEFT JOIN tb_empresa e
                ON e.EMP_ID = p.PLC_EMPRESA_FK
            WHERE 1=1
        ";
        $params = [];

        if ($empresa_fk !== '') {
            $sql .= " AND p.PLC_EMPRESA_FK = :empresa_fk";
            $params[':empresa_fk'] = (int)$empresa_fk;
        }

        if ($tipo !== '') {
            $sql .= " AND p.PLC_TIPO = :tipo";
            $params[':tipo'] = $tipo;
        }

        if ($status !== '') {
            $sql .= " AND p.PLC_STATUS = :status";
            $params[':status'] = $status;
        }

        if ($buscar !== '') {
            // IMPORTANTE: não reutilize o mesmo placeholder (:b) 2x com PDO em modo nativo
            // pois pode gerar HY093 (Invalid parameter number)
            $sql .= " AND (p.PLC_CODIGO LIKE :b1 OR p.PLC_NOME LIKE :b2)";
            $params[':b1'] = '%' . $buscar . '%';
            $params[':b2'] = '%' . $buscar . '%';
        }

        // total
        $sqlCount = "SELECT COUNT(*) AS total FROM (" . $sql . ") x";
        $stc = $pdo->prepare($sqlCount);
        $stc->execute($params);
        $total = (int)($stc->fetchColumn() ?: 0);

        $pages = (int)ceil($total / $perPage);
        if ($pages <= 0) $pages = 1;
        if ($page > $pages) {
            $page = $pages;
            $offset = ($page - 1) * $perPage;
        }

        $sql .= " ORDER BY p.PLC_CODIGO ASC, p.PLC_NOME ASC LIMIT :limit OFFSET :offset";

        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $from = $total > 0 ? ($offset + 1) : 0;
        $to   = $total > 0 ? min($offset + $perPage, $total) : 0;

        json_out([
            'ok' => true,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'from' => $from,
            'to' => $to,
        ]);
    }

    // ============================================================
    // GET (1)
    // ============================================================
    if ($acao === 'get') {
        $id = (int)($_GET['id'] ?? 0);

        $stmt = $pdo->prepare("
        SELECT
            PLC_CODIGO_PK,
            PLC_EMPRESA_FK,
            PLC_PARENT_ID,
            PLC_CODIGO,
            PLC_NOME,
            PLC_TIPO,
            PLC_NIVEL,
            PLC_STATUS,
            PLC_OBS,
            PLC_CRIADO_EM
        FROM tb_plano_contas
        WHERE PLC_CODIGO_PK = ?
        LIMIT 1
    ");
        $stmt->execute([$id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'  => true,
            'row' => $row
        ]);
        exit;
    }

    // ============================================================
    // SALVAR (INSERT/UPDATE)
    // ============================================================
    if ($acao === 'salvar') {
        require_post();

        $id          = $asInt($_POST['PLC_ID'] ?? 0);
        $empresa_fk  = $asStr($_POST['PLC_EMPRESA_FK'] ?? '');
        $parent_id   = $asStr($_POST['PLC_PARENT_ID'] ?? '');
        $nivel       = $asInt($_POST['PLC_NIVEL'] ?? 0);
        $codigo      = $asStr($_POST['PLC_CODIGO'] ?? '');
        $nome        = $asStr($_POST['PLC_NOME'] ?? '');
        $tipo        = $asStr($_POST['PLC_TIPO'] ?? '');
        $status      = $asStr($_POST['PLC_STATUS'] ?? 'ATIVO');
        $obs         = $asStr($_POST['PLC_OBS'] ?? '');

        if ($codigo === '' || $nome === '' || $tipo === '') {
            json_out(['ok' => false, 'msg' => 'Informe Código, Nome e Tipo.'], 422);
        }

        $validTipos = ['Ativo', 'Passivo', 'Receita', 'Despesa', 'Resultado'];
        if (!in_array($tipo, $validTipos, true)) {
            json_out(['ok' => false, 'msg' => 'Tipo inválido.'], 422);
        }

        $empresa_fk_db = ($empresa_fk === '' ? null : (int)$empresa_fk);
        $parent_id_db  = ($parent_id === '' ? null : (int)$parent_id);

        if ($nivel <= 0) $nivel = $calcNivel($codigo);

        // valida parent (se informado)
        if ($parent_id_db !== null) {
            $stp = $pdo->prepare("SELECT 1 FROM tb_plano_contas WHERE PLC_CODIGO_PK=? LIMIT 1");
            $stp->execute([$parent_id_db]);
            if (!$stp->fetchColumn()) {
                json_out(['ok' => false, 'msg' => 'Conta pai inválida.'], 422);
            }
            if ($id > 0 && $parent_id_db === $id) {
                json_out(['ok' => false, 'msg' => 'Conta pai não pode ser a própria conta.'], 422);
            }
        }

        // evita duplicar código na mesma empresa (incluindo empresa NULL)
        if ($id <= 0) {
            $st0 = $pdo->prepare("
                SELECT 1
                FROM tb_plano_contas
                WHERE PLC_CODIGO = ?
                  AND (PLC_EMPRESA_FK <=> ?)
                LIMIT 1
            ");
            $st0->execute([$codigo, $empresa_fk_db]);
            if ($st0->fetchColumn()) json_out(['ok' => false, 'msg' => 'Código já cadastrado para esta empresa.'], 409);

            $st = $pdo->prepare("
                INSERT INTO tb_plano_contas
                    (PLC_EMPRESA_FK, PLC_PARENT_ID, PLC_CODIGO, PLC_NOME, PLC_TIPO, PLC_NIVEL, PLC_STATUS, PLC_OBS)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st->execute([$empresa_fk_db, $parent_id_db, $codigo, $nome, $tipo, $nivel, $status, $obs]);
            $newId = (int)$pdo->lastInsertId();

            json_out(['ok' => true, 'msg' => 'Plano de contas criado.', 'id' => $newId]);
        } else {
            $st0 = $pdo->prepare("
                SELECT 1
                FROM tb_plano_contas
                WHERE PLC_CODIGO = ?
                  AND (PLC_EMPRESA_FK <=> ?)
                  AND PLC_CODIGO_PK <> ?
                LIMIT 1
            ");
            $st0->execute([$codigo, $empresa_fk_db, $id]);
            if ($st0->fetchColumn()) json_out(['ok' => false, 'msg' => 'Código já cadastrado em outro registro desta empresa.'], 409);

            $st = $pdo->prepare("
                UPDATE tb_plano_contas SET
                    PLC_EMPRESA_FK = ?,
                    PLC_PARENT_ID  = ?,
                    PLC_CODIGO     = ?,
                    PLC_NOME       = ?,
                    PLC_TIPO       = ?,
                    PLC_NIVEL      = ?,
                    PLC_STATUS     = ?,
                    PLC_OBS        = ?
                WHERE PLC_CODIGO_PK = ?
            ");
            $st->execute([$empresa_fk_db, $parent_id_db, $codigo, $nome, $tipo, $nivel, $status, $obs, $id]);

            json_out(['ok' => true, 'msg' => 'Plano de contas atualizado.', 'id' => $id]);
        }
    }

    // ============================================================
    // EXCLUIR
    // ============================================================
    if ($acao === 'excluir') {
        require_post();
        $id = $asInt($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("DELETE FROM tb_plano_contas WHERE PLC_CODIGO_PK=?");
        $st->execute([$id]);

        json_out(['ok' => true, 'msg' => 'Plano de contas excluído.']);
    }

    // ============================================================
    // COMBO EMPRESAS (EMP_ID / EMP_RAZAO_SOCIAL)
    // ============================================================
    if ($acao === 'empresas_combo') {
        $st = $pdo->prepare("
            SELECT EMP_ID, EMP_RAZAO_SOCIAL
            FROM tb_empresa
            ORDER BY EMP_RAZAO_SOCIAL
        ");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        json_out(['ok' => true, 'rows' => $rows]);
    }

    // ============================================================
    // COMBO (para selects em outros módulos, ex: contratos)
    // ============================================================
    if ($acao === 'combo') {
        $empresa_fk = $asStr($_GET['empresa_fk'] ?? '');

        $sql = "
            SELECT
                PLC_CODIGO_PK AS PLC_ID,
                PLC_CODIGO,
                PLC_NOME,
                PLC_TIPO
            FROM tb_plano_contas
            WHERE PLC_STATUS = 'ATIVO'
        ";
        $params = [];

        if ($empresa_fk !== '') {
            $sql .= " AND PLC_EMPRESA_FK = ?";
            $params[] = (int)$empresa_fk;
        }

        $sql .= " ORDER BY PLC_CODIGO ASC, PLC_NOME ASC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['PLC_LABEL'] = trim(($r['PLC_CODIGO'] ? $r['PLC_CODIGO'] . ' - ' : '') . ($r['PLC_NOME'] ?? ''));
        }

        json_out(['ok' => true, 'rows' => $rows]);
    }

    // ============================================================
    // COMBO CONTAS PAI (por empresa)
    // ============================================================
    if ($acao === 'pais_combo') {
        $empresa_fk = $asStr($_GET['empresa_fk'] ?? '');

        // O plano de contas é global (compartilhado entre empresas): as contas
        // existentes têm PLC_EMPRESA_FK = NULL. Por isso a lista de "Conta Pai"
        // inclui as contas globais (NULL) e, se houver, as da empresa selecionada.
        if ($empresa_fk === '') {
            $st = $pdo->prepare("
                SELECT PLC_CODIGO_PK AS PLC_ID, PLC_CODIGO, PLC_NOME, PLC_TIPO
                FROM tb_plano_contas
                WHERE PLC_STATUS='ATIVO'
                ORDER BY PLC_CODIGO ASC, PLC_NOME ASC
            ");
            $st->execute();
        } else {
            $st = $pdo->prepare("
                SELECT PLC_CODIGO_PK AS PLC_ID, PLC_CODIGO, PLC_NOME, PLC_TIPO
                FROM tb_plano_contas
                WHERE PLC_STATUS='ATIVO'
                  AND (PLC_EMPRESA_FK = ? OR PLC_EMPRESA_FK IS NULL)
                ORDER BY PLC_CODIGO ASC, PLC_NOME ASC
            ");
            $st->execute([(int)$empresa_fk]);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['PLC_LABEL'] = trim(($r['PLC_CODIGO'] ? $r['PLC_CODIGO'] . ' - ' : '') . ($r['PLC_NOME'] ?? ''));
        }

        json_out(['ok' => true, 'rows' => $rows]);
    }

    // ============================================================
    // PRÓXIMO CÓDIGO FILHO LIVRE (auto-preenchimento ao escolher a conta pai)
    // ============================================================
    if ($acao === 'proximo_codigo_filho') {
        $parent_id = (int)($_GET['parent_id'] ?? 0);
        if ($parent_id <= 0) json_out(['ok' => false, 'msg' => 'Conta pai inválida.'], 422);

        $stp = $pdo->prepare("SELECT PLC_CODIGO FROM tb_plano_contas WHERE PLC_CODIGO_PK=? LIMIT 1");
        $stp->execute([$parent_id]);
        $parentCod = trim((string)($stp->fetchColumn() ?: ''));
        if ($parentCod === '') json_out(['ok' => false, 'msg' => 'Conta pai não encontrada.'], 404);

        $prefix = $parentCod . '.';

        // Coleta os filhos diretos (qualquer status/empresa) p/ não reutilizar código.
        $st = $pdo->prepare("SELECT PLC_CODIGO FROM tb_plano_contas WHERE PLC_CODIGO LIKE ?");
        $st->execute([$prefix . '%']);
        $used = [];
        $width = 0;
        foreach ($st as $r) {
            $rest = substr(trim((string)$r['PLC_CODIGO']), strlen($prefix));
            $seg  = explode('.', $rest)[0]; // só o segmento direto
            if ($seg !== '' && ctype_digit($seg)) {
                $used[(int)$seg] = true;
                $width = max($width, strlen($seg));
            }
        }
        if ($width <= 0) $width = 3; // padrão usado no plano (ex.: 01.02.001)

        // primeiro número livre (preenche lacunas)
        $n = 1;
        while (isset($used[$n])) $n++;

        $childCod = $prefix . str_pad((string)$n, $width, '0', STR_PAD_LEFT);
        $nivel = count(array_filter(explode('.', $childCod), fn($x) => $x !== ''));

        json_out(['ok' => true, 'codigo' => $childCod, 'nivel' => $nivel, 'parent_codigo' => $parentCod]);
    }

    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    json_out(['ok' => false, 'msg' => 'Erro no banco.', 'detail' => $e->getMessage()], 500);
} catch (Throwable $e) {
    json_out(['ok' => false, 'msg' => 'Erro interno.', 'detail' => $e->getMessage()], 500);
}
