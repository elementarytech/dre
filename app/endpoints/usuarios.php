<?php
// /app/endpoints/usuarios.php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/usuarios_endpoint.log');

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// trava: só ADMIN mexe em usuários
if (empty($_SESSION['user_id'])) {
    json_out(['ok' => false, 'msg' => 'Não autenticado.'], 401);
}
if (($_SESSION['user_perfil'] ?? '') !== 'ADMIN') {
    json_out(['ok' => false, 'msg' => 'Acesso restrito (ADMIN).'], 403);
}

$acao = $_REQUEST['acao'] ?? '';

// Coleta + valida os campos cadastrais novos (A.4). Retorna array tipado ou encerra com json_out.
function coletar_campos_cadastrais_usuario(): array {
    $cpfCnpj = preg_replace('/\D/', '', (string)($_POST['USU_CPF_CNPJ'] ?? ''));
    $tel     = preg_replace('/\D/', '', (string)($_POST['USU_TELEFONE'] ?? ''));
    $cep     = preg_replace('/\D/', '', (string)($_POST['USU_CEP'] ?? ''));
    $end     = trim((string)($_POST['USU_ENDERECO'] ?? ''));
    $num     = trim((string)($_POST['USU_NUMERO'] ?? ''));
    $comp    = trim((string)($_POST['USU_COMPLEMENTO'] ?? ''));
    $bai     = trim((string)($_POST['USU_BAIRRO'] ?? ''));
    $cid     = trim((string)($_POST['USU_CIDADE'] ?? ''));
    $uf      = strtoupper(trim((string)($_POST['USU_UF'] ?? '')));

    if ($cpfCnpj === '' || (strlen($cpfCnpj) !== 11 && strlen($cpfCnpj) !== 14)) {
        json_out(['ok' => false, 'msg' => 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.'], 422);
    }
    if (strlen($tel) < 10) json_out(['ok' => false, 'msg' => 'Informe o telefone.'], 422);
    if (strlen($cep) !== 8) json_out(['ok' => false, 'msg' => 'Informe um CEP válido.'], 422);
    if ($end === '' || $num === '' || $bai === '' || $cid === '' || strlen($uf) !== 2) {
        json_out(['ok' => false, 'msg' => 'Preencha o endereço completo (Endereço, Número, Bairro, Cidade e UF).'], 422);
    }

    return [
        'cpfCnpj' => $cpfCnpj,
        'tel'     => $tel,
        'cep'     => $cep,
        'end'     => $end,
        'num'     => $num,
        'comp'    => ($comp === '' ? null : $comp),
        'bai'     => $bai,
        'cid'     => $cid,
        'uf'      => $uf,
    ];
}

try {

    /* =========================
       LISTAR
    ========================== */
    if ($acao === 'listar') {
        $q         = trim((string)($_GET['q'] ?? ''));
        $perfil    = trim((string)($_GET['perfil'] ?? ''));
        $status    = trim((string)($_GET['status'] ?? ''));
        $empresaId = (int)($_GET['empresaId'] ?? 0);

        $sql = "SELECT
                    u.USU_ID, u.USU_NOME, u.USU_EMAIL, u.USU_PERFIL, u.USU_STATUS, u.USU_ULTIMO_LOGIN,
                    u.USU_EMPRESA_ID, u.USU_CARGO, u.USU_ACESSO_TODAS_EMPRESAS, u.USU_OBSERVACAO,
                    e.EMP_NOME_FANTASIA, e.EMP_RAZAO_SOCIAL
                FROM usuarios u
                LEFT JOIN tb_empresa e ON e.EMP_ID = u.USU_EMPRESA_ID
                WHERE 1=1";
        $params = [];

        if ($q !== '') {
            $sql .= " AND (u.USU_NOME LIKE ? OR u.USU_EMAIL LIKE ? OR u.USU_CARGO LIKE ?)";
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
        }
        if ($perfil !== '') {
            $sql .= " AND u.USU_PERFIL = ?";
            $params[] = $perfil;
        }
        if ($status !== '') {
            $sql .= " AND u.USU_STATUS = ?";
            $params[] = $status;
        }
        if ($empresaId > 0) {
            // quem tem "todas" passa no filtro também
            $sql .= " AND (u.USU_ACESSO_TODAS_EMPRESAS='SIM' OR u.USU_EMPRESA_ID = ?)";
            $params[] = $empresaId;
        }

        $sql .= " ORDER BY u.USU_ID DESC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();

        // normaliza nome da empresa pra tela
        foreach ($rows as &$r) {
            $r['EMP_NOME'] = $r['EMP_NOME_FANTASIA'] ?: ($r['EMP_RAZAO_SOCIAL'] ?: null);
        }

        json_out(['ok' => true, 'rows' => $rows]);
    }

    /* =========================
       OBTER
    ========================== */
    if ($acao === 'obter') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("SELECT
                                USU_ID, USU_NOME, USU_EMAIL, USU_PERFIL, USU_STATUS,
                                USU_EMPRESA_ID, USU_CARGO, USU_ACESSO_TODAS_EMPRESAS, USU_OBSERVACAO,
                                USU_CPF_CNPJ, USU_TELEFONE, USU_CEP, USU_ENDERECO, USU_NUMERO,
                                USU_COMPLEMENTO, USU_BAIRRO, USU_CIDADE, USU_UF
                             FROM usuarios
                             WHERE USU_ID = ?
                             LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();

        if (!$row) json_out(['ok' => false, 'msg' => 'Usuário não encontrado.'], 404);
        json_out(['ok' => true, 'row' => $row]);
    }

    /* =========================
       CRIAR
    ========================== */
    if ($acao === 'criar') {
        require_post();

        $nome   = trim((string)($_POST['USU_NOME'] ?? ''));
        $email  = trim((string)($_POST['USU_EMAIL'] ?? ''));
        $perfil = trim((string)($_POST['USU_PERFIL'] ?? 'USER'));
        $status = trim((string)($_POST['USU_STATUS'] ?? 'ATIVO'));
        $senha  = (string)($_POST['SENHA'] ?? '');

        $cargo  = trim((string)($_POST['USU_CARGO'] ?? ''));
        $obs    = trim((string)($_POST['USU_OBSERVACAO'] ?? ''));

        $acessoTodas = strtoupper(trim((string)($_POST['USU_ACESSO_TODAS_EMPRESAS'] ?? 'NAO')));
        if (!in_array($acessoTodas, ['SIM', 'NAO'], true)) $acessoTodas = 'NAO';

        // empresa pode vir '' quando marcou "todas"
        $empresaRaw = trim((string)($_POST['USU_EMPRESA_ID'] ?? ''));
        $empresaId = ($empresaRaw === '') ? null : (int)$empresaRaw;

        if ($nome === '' || $email === '') {
            json_out(['ok' => false, 'msg' => 'Nome e e-mail são obrigatórios.'], 422);
        }

        if ($senha === '' || strlen($senha) < 6) {
            json_out(['ok' => false, 'msg' => 'Informe uma senha (mín. 6 caracteres).'], 422);
        }

        if (!in_array($perfil, ['ADMIN', 'USER'], true)) $perfil = 'USER';
        if (!in_array($status, ['ATIVO', 'INATIVO'], true)) $status = 'ATIVO';

        // validação correta (não exigir empresa quando for "todas")
        if ($acessoTodas === 'NAO' && (!$empresaId || $empresaId <= 0)) {
            json_out(['ok' => false, 'msg' => 'Selecione a empresa do usuário ou marque acesso a todas.'], 422);
        }
        if ($acessoTodas === 'SIM') {
            $empresaId = null; // salva NULL
        }

        $hash = password_hash($senha, PASSWORD_DEFAULT);

        $c = coletar_campos_cadastrais_usuario();

        $st = $pdo->prepare("
            INSERT INTO usuarios
            (USU_NOME, USU_EMAIL, USU_SENHA_HASH, USU_PERFIL, USU_STATUS,
             USU_EMPRESA_ID, USU_CARGO, USU_ACESSO_TODAS_EMPRESAS, USU_OBSERVACAO,
             USU_CPF_CNPJ, USU_TELEFONE, USU_CEP, USU_ENDERECO, USU_NUMERO,
             USU_COMPLEMENTO, USU_BAIRRO, USU_CIDADE, USU_UF)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $st->execute([
            $nome, $email, $hash, $perfil, $status,
            $empresaId, $cargo, $acessoTodas, $obs,
            $c['cpfCnpj'], $c['tel'], $c['cep'], $c['end'], $c['num'],
            $c['comp'], $c['bai'], $c['cid'], $c['uf']
        ]);

        json_out(['ok' => true, 'msg' => 'Usuário criado.']);
    }

    /* =========================
       EDITAR
    ========================== */
    if ($acao === 'editar') {
        require_post();

        $id     = (int)($_POST['USU_ID'] ?? 0);
        $nome   = trim((string)($_POST['USU_NOME'] ?? ''));
        $email  = trim((string)($_POST['USU_EMAIL'] ?? ''));
        $perfil = trim((string)($_POST['USU_PERFIL'] ?? 'USER'));
        $status = trim((string)($_POST['USU_STATUS'] ?? 'ATIVO'));
        $senha  = (string)($_POST['SENHA'] ?? '');

        $cargo = trim((string)($_POST['USU_CARGO'] ?? ''));
        $obs   = trim((string)($_POST['USU_OBSERVACAO'] ?? ''));

        $acessoTodas = strtoupper(trim((string)($_POST['USU_ACESSO_TODAS_EMPRESAS'] ?? 'NAO')));
        if (!in_array($acessoTodas, ['SIM', 'NAO'], true)) $acessoTodas = 'NAO';

        $empresaRaw = trim((string)($_POST['USU_EMPRESA_ID'] ?? ''));
        $empresaId = ($empresaRaw === '') ? null : (int)$empresaRaw;

        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);
        if ($nome === '' || $email === '') json_out(['ok' => false, 'msg' => 'Nome e e-mail são obrigatórios.'], 422);

        if (!in_array($perfil, ['ADMIN', 'USER'], true)) $perfil = 'USER';
        if (!in_array($status, ['ATIVO', 'INATIVO'], true)) $status = 'ATIVO';

        // validação correta
        if ($acessoTodas === 'NAO' && (!$empresaId || $empresaId <= 0)) {
            json_out(['ok' => false, 'msg' => 'Selecione a empresa do usuário ou marque acesso a todas.'], 422);
        }
        if ($acessoTodas === 'SIM') {
            $empresaId = null;
        }

        // garante que o alvo existe
        $st = $pdo->prepare("SELECT USU_ID, USU_PERFIL FROM usuarios WHERE USU_ID=?");
        $st->execute([$id]);
        $alvo = $st->fetch();
        if (!$alvo) json_out(['ok' => false, 'msg' => 'Usuário não encontrado.'], 404);

        $c = coletar_campos_cadastrais_usuario();

        if ($senha !== '') {
            if (strlen($senha) < 6) json_out(['ok' => false, 'msg' => 'Senha deve ter no mínimo 6 caracteres.'], 422);
            $hash = password_hash($senha, PASSWORD_DEFAULT);

            $st = $pdo->prepare("
                UPDATE usuarios
                   SET USU_NOME=?, USU_EMAIL=?, USU_SENHA_HASH=?, USU_PERFIL=?, USU_STATUS=?,
                       USU_EMPRESA_ID=?, USU_CARGO=?, USU_ACESSO_TODAS_EMPRESAS=?, USU_OBSERVACAO=?,
                       USU_CPF_CNPJ=?, USU_TELEFONE=?, USU_CEP=?, USU_ENDERECO=?, USU_NUMERO=?,
                       USU_COMPLEMENTO=?, USU_BAIRRO=?, USU_CIDADE=?, USU_UF=?
                 WHERE USU_ID=?
            ");
            $st->execute([
                $nome, $email, $hash, $perfil, $status,
                $empresaId, $cargo, $acessoTodas, $obs,
                $c['cpfCnpj'], $c['tel'], $c['cep'], $c['end'], $c['num'],
                $c['comp'], $c['bai'], $c['cid'], $c['uf'],
                $id
            ]);
        } else {
            $st = $pdo->prepare("
                UPDATE usuarios
                   SET USU_NOME=?, USU_EMAIL=?, USU_PERFIL=?, USU_STATUS=?,
                       USU_EMPRESA_ID=?, USU_CARGO=?, USU_ACESSO_TODAS_EMPRESAS=?, USU_OBSERVACAO=?,
                       USU_CPF_CNPJ=?, USU_TELEFONE=?, USU_CEP=?, USU_ENDERECO=?, USU_NUMERO=?,
                       USU_COMPLEMENTO=?, USU_BAIRRO=?, USU_CIDADE=?, USU_UF=?
                 WHERE USU_ID=?
            ");
            $st->execute([
                $nome, $email, $perfil, $status,
                $empresaId, $cargo, $acessoTodas, $obs,
                $c['cpfCnpj'], $c['tel'], $c['cep'], $c['end'], $c['num'],
                $c['comp'], $c['bai'], $c['cid'], $c['uf'],
                $id
            ]);
        }

        json_out(['ok' => true, 'msg' => 'Usuário atualizado.']);
    }

    /* =========================
       INATIVAR (EXCLUIR)
    ========================== */
    if ($acao === 'excluir') {
        require_post();

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        // não permitir inativar a si mesmo
        if ((int)($_SESSION['user_id'] ?? 0) === $id) {
            json_out(['ok' => false, 'msg' => 'Você não pode inativar seu próprio usuário.'], 422);
        }

        $st = $pdo->prepare("UPDATE usuarios SET USU_STATUS='INATIVO' WHERE USU_ID=?");
        $st->execute([$id]);

        json_out(['ok' => true, 'msg' => 'Usuário inativado.']);
    }

    /* =========================
       REATIVAR
    ========================== */
    if ($acao === 'reativar') {
        require_post();

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("UPDATE usuarios SET USU_STATUS='ATIVO' WHERE USU_ID=?");
        $st->execute([$id]);

        json_out(['ok' => true, 'msg' => 'Usuário reativado.']);
    }

    /* =========================
       RESET SENHA
    ========================== */
    if ($acao === 'reset_senha') {
        require_post();

        $id = (int)($_POST['id'] ?? 0);
        $senha = (string)($_POST['senha'] ?? '');

        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);
        if ($senha === '' || strlen($senha) < 6) {
            json_out(['ok' => false, 'msg' => 'A senha deve ter no mínimo 6 caracteres.'], 422);
        }

        $hash = password_hash($senha, PASSWORD_DEFAULT);

        $st = $pdo->prepare("UPDATE usuarios SET USU_SENHA_HASH=? WHERE USU_ID=?");
        $st->execute([$hash, $id]);

        json_out(['ok' => true, 'msg' => 'Senha redefinida.']);
    }

    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    // duplicidade de e-mail
    if ((int)$e->getCode() === 23000) {
        json_out(['ok' => false, 'msg' => 'E-mail já cadastrado.'], 409);
    }
    json_out(['ok' => false, 'msg' => 'Erro no banco.', 'detail' => $e->getMessage()], 500);
} catch (Throwable $e) {
    json_out(['ok' => false, 'msg' => 'Erro interno.', 'detail' => $e->getMessage()], 500);
}
