<?php
// /app/endpoints/clientes.php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/clientes_endpoint.log');

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// exige login
if (empty($_SESSION['user_id'])) {
    json_out(['ok' => false, 'msg' => 'Não autenticado.'], 401);
}

// (opcional) se quiser travar só ADMIN, descomente:
// if (($_SESSION['user_perfil'] ?? '') !== 'ADMIN') {
//     json_out(['ok' => false, 'msg' => 'Acesso restrito (ADMIN).'], 403);
// }

$acao = $_REQUEST['acao'] ?? '';

function only_digits(string $v): string
{
    return preg_replace('/\D+/', '', $v) ?? '';
}

try {

    if ($acao === 'listar') {
        $q      = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $tipo   = trim((string)($_GET['tipo'] ?? ''));

        $sql = "SELECT
                    CLI_ID, CLI_NOME_RAZAO, CLI_TIPO_PESSOA, CLI_DOCUMENTO,
                    CLI_TELEFONE, CLI_EMAIL, CLI_CIDADE, CLI_UF, CLI_STATUS
                FROM cliente
                WHERE 1=1";
        $params = [];

        if ($q !== '') {
            $sql .= " AND (CLI_NOME_RAZAO LIKE ? OR CLI_DOCUMENTO LIKE ? OR CLI_EMAIL LIKE ?)";
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
        }
        if ($status !== '') {
            $sql .= " AND CLI_STATUS = ?";
            $params[] = $status;
        }
        if ($tipo !== '') {
            $sql .= " AND CLI_TIPO_PESSOA = ?";
            $params[] = $tipo;
        }

        $sql .= " ORDER BY CLI_ID DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);

        $rows = $st->fetchAll();
        json_out(['ok' => true, 'rows' => $rows, 'total' => count($rows)]);
    }

    if ($acao === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("SELECT
                CLI_ID, CLI_NOME_RAZAO, CLI_TIPO_PESSOA, CLI_DOCUMENTO, CLI_IE, CLI_DATA_NASC_FUNDACAO,
                CLI_TELEFONE, CLI_WHATSAPP, CLI_EMAIL, CLI_STATUS, CLI_OBSERVACAO,
                CLI_CEP, CLI_ENDERECO, CLI_NUMERO, CLI_COMPLEMENTO, CLI_BAIRRO, CLI_CIDADE, CLI_UF
            FROM cliente
            WHERE CLI_ID = ?");
        $st->execute([$id]);
        $row = $st->fetch();

        if (!$row) json_out(['ok' => false, 'msg' => 'Cliente não encontrado.'], 404);
        json_out(['ok' => true, 'row' => $row]);
    }

    if ($acao === 'salvar') {
        require_post();

        $id   = (int)($_POST['CLI_ID'] ?? 0);

        $nome = trim((string)($_POST['CLI_NOME_RAZAO'] ?? ''));
        $tipo = strtoupper(trim((string)($_POST['CLI_TIPO_PESSOA'] ?? 'F')));
        if (!in_array($tipo, ['F', 'J'], true)) $tipo = 'F';

        $doc  = trim((string)($_POST['CLI_DOCUMENTO'] ?? ''));
        $doc  = $doc !== '' ? $doc : null;

        $ie   = trim((string)($_POST['CLI_IE'] ?? ''));
        $ie   = $ie !== '' ? $ie : null;

        $data = trim((string)($_POST['CLI_DATA_NASC_FUNDACAO'] ?? ''));
        $data = $data !== '' ? $data : null;

        $tel  = trim((string)($_POST['CLI_TELEFONE'] ?? ''));
        $tel  = $tel !== '' ? $tel : null;

        $wpp  = trim((string)($_POST['CLI_WHATSAPP'] ?? ''));
        $wpp  = $wpp !== '' ? $wpp : null;

        $email = trim((string)($_POST['CLI_EMAIL'] ?? ''));
        $email = $email !== '' ? $email : null;

        $status = strtoupper(trim((string)($_POST['CLI_STATUS'] ?? 'ATIVO')));
        if (!in_array($status, ['ATIVO', 'INATIVO'], true)) $status = 'ATIVO';

        $obs = trim((string)($_POST['CLI_OBSERVACAO'] ?? ''));
        $obs = $obs !== '' ? $obs : null;

        $cep  = trim((string)($_POST['CLI_CEP'] ?? ''));
        $cep  = $cep !== '' ? $cep : null;

        $end  = trim((string)($_POST['CLI_ENDERECO'] ?? ''));
        $end  = $end !== '' ? $end : null;

        $num  = trim((string)($_POST['CLI_NUMERO'] ?? ''));
        $num  = $num !== '' ? $num : null;

        $comp = trim((string)($_POST['CLI_COMPLEMENTO'] ?? ''));
        $comp = $comp !== '' ? $comp : null;

        $bairro = trim((string)($_POST['CLI_BAIRRO'] ?? ''));
        $bairro = $bairro !== '' ? $bairro : null;

        $cidade = trim((string)($_POST['CLI_CIDADE'] ?? ''));
        $cidade = $cidade !== '' ? $cidade : null;

        $uf = strtoupper(trim((string)($_POST['CLI_UF'] ?? '')));
        $uf = $uf !== '' ? $uf : null;

        if ($nome === '')   json_out(['ok' => false, 'msg' => 'Nome/Razão Social é obrigatório.'], 422);
        if ($doc === null)  json_out(['ok' => false, 'msg' => 'Informe o CPF/CNPJ.'], 422);
        if ($tel === null)  json_out(['ok' => false, 'msg' => 'Informe o telefone.'], 422);
        if ($email === null) json_out(['ok' => false, 'msg' => 'Informe o e-mail.'], 422);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(['ok' => false, 'msg' => 'Informe um e-mail válido.'], 422);
        }
        if ($cep === null)    json_out(['ok' => false, 'msg' => 'Informe o CEP.'], 422);
        if ($end === null)    json_out(['ok' => false, 'msg' => 'Informe o endereço.'], 422);
        if ($num === null)    json_out(['ok' => false, 'msg' => 'Informe o número.'], 422);
        if ($bairro === null) json_out(['ok' => false, 'msg' => 'Informe o bairro.'], 422);
        if ($cidade === null) json_out(['ok' => false, 'msg' => 'Informe a cidade.'], 422);
        if ($uf === null)     json_out(['ok' => false, 'msg' => 'Informe a UF.'], 422);

        // valida documento
        $digits = only_digits((string)$doc);
        if ($tipo === 'J') {
            if (strlen($digits) !== 14) json_out(['ok' => false, 'msg' => 'CNPJ inválido.'], 422);
        } else {
            if (strlen($digits) !== 11) json_out(['ok' => false, 'msg' => 'CPF inválido.'], 422);
        }

        if ($id > 0) {
            $st = $pdo->prepare("UPDATE cliente SET
                    CLI_NOME_RAZAO=?, CLI_TIPO_PESSOA=?, CLI_DOCUMENTO=?, CLI_IE=?, CLI_DATA_NASC_FUNDACAO=?,
                    CLI_TELEFONE=?, CLI_WHATSAPP=?, CLI_EMAIL=?, CLI_STATUS=?, CLI_OBSERVACAO=?,
                    CLI_CEP=?, CLI_ENDERECO=?, CLI_NUMERO=?, CLI_COMPLEMENTO=?, CLI_BAIRRO=?, CLI_CIDADE=?, CLI_UF=?
                WHERE CLI_ID=?");
            $st->execute([
                $nome,
                $tipo,
                $doc,
                $ie,
                $data,
                $tel,
                $wpp,
                $email,
                $status,
                $obs,
                $cep,
                $end,
                $num,
                $comp,
                $bairro,
                $cidade,
                $uf,
                $id
            ]);

            json_out(['ok' => true, 'msg' => 'Cliente atualizado.']);
        } else {
            $st = $pdo->prepare("INSERT INTO cliente (
                    CLI_NOME_RAZAO, CLI_TIPO_PESSOA, CLI_DOCUMENTO, CLI_IE, CLI_DATA_NASC_FUNDACAO,
                    CLI_TELEFONE, CLI_WHATSAPP, CLI_EMAIL, CLI_STATUS, CLI_OBSERVACAO,
                    CLI_CEP, CLI_ENDERECO, CLI_NUMERO, CLI_COMPLEMENTO, CLI_BAIRRO, CLI_CIDADE, CLI_UF
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                $nome,
                $tipo,
                $doc,
                $ie,
                $data,
                $tel,
                $wpp,
                $email,
                $status,
                $obs,
                $cep,
                $end,
                $num,
                $comp,
                $bairro,
                $cidade,
                $uf
            ]);

            json_out(['ok' => true, 'msg' => 'Cliente criado.']);
        }
    }

    if ($acao === 'inativar') {
        require_post();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("UPDATE cliente SET CLI_STATUS='INATIVO' WHERE CLI_ID=?");
        $st->execute([$id]);

        json_out(['ok' => true, 'msg' => 'Cliente inativado.']);
    }

    if ($acao === 'reativar') {
        require_post();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("UPDATE cliente SET CLI_STATUS='ATIVO' WHERE CLI_ID=?");
        $st->execute([$id]);

        json_out(['ok' => true, 'msg' => 'Cliente reativado.']);
    }

    // stubs para futuro (contratos/anexos)
    if ($acao === 'listar_contratos') {
        json_out(['ok' => true, 'rows' => []]);
    }
    if ($acao === 'listar_anexos') {
        json_out(['ok' => true, 'rows' => []]);
    }

    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    json_out(['ok' => false, 'msg' => 'Erro no banco.', 'detail' => $e->getMessage()], 500);
} catch (Throwable $e) {
    json_out(['ok' => false, 'msg' => 'Erro interno.', 'detail' => $e->getMessage()], 500);
}
