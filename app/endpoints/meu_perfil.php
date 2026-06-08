<?php
// /app/endpoints/meu_perfil.php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/meu_perfil_endpoint.log');

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    json_out(['ok' => false, 'msg' => 'Não autenticado.'], 401);
}

$acao = $_REQUEST['acao'] ?? '';
$userId = (int)$_SESSION['user_id'];

try {

    if ($acao === 'get') {
        $st = $pdo->prepare("SELECT USU_ID, USU_NOME, USU_EMAIL, USU_PERFIL, USU_STATUS, USU_ULTIMO_LOGIN
                         FROM usuarios WHERE USU_ID=? LIMIT 1");
        $st->execute([$userId]);
        $u = $st->fetch();
        if (!$u) json_out(['ok' => false, 'msg' => 'Usuário não encontrado.'], 404);

        json_out(['ok' => true, 'user' => $u]);
    }

    if ($acao === 'salvar_tudo') {
        require_post();

        $nome  = trim((string)($_POST['USU_NOME'] ?? ''));
        $email = trim((string)($_POST['USU_EMAIL'] ?? ''));

        $senhaAtual = (string)($_POST['senha_atual'] ?? '');
        $senhaNova  = (string)($_POST['senha_nova'] ?? '');

        if ($nome === '' || $email === '') {
            json_out(['ok' => false, 'msg' => 'Nome e e-mail são obrigatórios.'], 422);
        }

        // checar e-mail duplicado (exceto o próprio)
        $st = $pdo->prepare("SELECT USU_ID FROM usuarios WHERE USU_EMAIL=? AND USU_ID<>? LIMIT 1");
        $st->execute([$email, $userId]);
        if ($st->fetch()) {
            json_out(['ok' => false, 'msg' => 'Este e-mail já está em uso.'], 409);
        }

        // busca hash atual (só precisa se for trocar senha)
        $trocarSenha = ($senhaAtual !== '' || $senhaNova !== '');

        if ($trocarSenha) {
            if ($senhaAtual === '' || $senhaNova === '') {
                json_out(['ok' => false, 'msg' => 'Para trocar a senha, informe senha atual e nova senha.'], 422);
            }
            if (strlen($senhaNova) < 6) {
                json_out(['ok' => false, 'msg' => 'A nova senha deve ter no mínimo 6 caracteres.'], 422);
            }

            $st = $pdo->prepare("SELECT USU_SENHA_HASH, USU_STATUS FROM usuarios WHERE USU_ID=? LIMIT 1");
            $st->execute([$userId]);
            $u = $st->fetch();
            if (!$u) json_out(['ok' => false, 'msg' => 'Usuário não encontrado.'], 404);

            if (($u['USU_STATUS'] ?? '') !== 'ATIVO') {
                json_out(['ok' => false, 'msg' => 'Usuário inativo.'], 403);
            }

            if (!password_verify($senhaAtual, (string)$u['USU_SENHA_HASH'])) {
                json_out(['ok' => false, 'msg' => 'Senha atual incorreta.'], 422);
            }

            $hash = password_hash($senhaNova, PASSWORD_DEFAULT);

            $st = $pdo->prepare("UPDATE usuarios SET USU_NOME=?, USU_EMAIL=?, USU_SENHA_HASH=? WHERE USU_ID=?");
            $st->execute([$nome, $email, $hash, $userId]);
        } else {
            $st = $pdo->prepare("UPDATE usuarios SET USU_NOME=?, USU_EMAIL=? WHERE USU_ID=?");
            $st->execute([$nome, $email, $userId]);
        }

        // manter sessão atualizada — reabre o lock que auth.php libera para performance.
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['user_nome'] = $nome;
        session_write_close();

        json_out(['ok' => true, 'msg' => 'Alterações salvas.']);
    }




    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    if ((int)$e->getCode() === 23000) {
        json_out(['ok' => false, 'msg' => 'E-mail já cadastrado.'], 409);
    }
    json_out(['ok' => false, 'msg' => 'Erro no banco.', 'detail' => $e->getMessage()], 500);
} catch (Throwable $e) {
    json_out(['ok' => false, 'msg' => 'Erro interno.', 'detail' => $e->getMessage()], 500);
}
