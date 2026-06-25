<?php
// /app/config/auditoria_sessao.php  (versionado no git)
// ------------------------------------------------------------------
// Publica o usuário logado nas variáveis de sessão do MySQL, que são
// lidas pelos TRIGGERS de auditoria (tabela tb_auditoria) para registrar
// QUEM fez cada alteração. Chamado pelo config/conexao.php logo após criar
// o PDO. Mantido aqui (e não inline no conexao.php) porque o conexao.php é
// gitignorado por ambiente — assim a lógica fica versionada.
//
// Como ligar em cada ambiente (uma vez, no fim do config/conexao.php):
//     require_once __DIR__ . '/auditoria_sessao.php';
//     publicarUsuarioAuditoria($pdo);
//
// Origem registrada nos triggers:
//   - 'APP'         → havia usuário logado (@app_user_* preenchidas)
//   - 'SQL/DIRETO'  → nenhuma variável setada (edição manual no banco)
// ------------------------------------------------------------------
declare(strict_types=1);

if (!function_exists('publicarUsuarioAuditoria')) {
    function publicarUsuarioAuditoria(PDO $pdo): void
    {
        try {
            // Garante a sessão iniciada ANTES de ler $_SESSION. Vários endpoints
            // dão require deste arquivo (via conexao.php) ANTES do próprio
            // session_start(), o que fazia a auditoria gravar usuário/IP NULL
            // (origem 'SQL/DIRETO') mesmo havendo usuário logado.
            if (PHP_SAPI !== 'cli'
                && session_status() === PHP_SESSION_NONE
                && !headers_sent()) {
                session_start();
            }

            $id   = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $nome = $_SESSION['user_nome'] ?? null;
            if ($nome === null && PHP_SAPI === 'cli') {
                $nome = 'CLI/CRON';
            }
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            $pdo->exec('SET @app_user_id = '   . ($id   !== null ? $id : 'NULL'));
            $pdo->exec('SET @app_user_nome = ' . ($nome !== null ? $pdo->quote((string) $nome) : 'NULL'));
            $pdo->exec('SET @app_ip = '        . ($ip   !== null ? $pdo->quote((string) $ip)   : 'NULL'));
        } catch (\Throwable $e) {
            // auditoria nunca deve impedir o acesso ao banco
        }
    }
}
