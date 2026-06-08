<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// =============================================================
// Expiração por inatividade.
// Sessões ficam vivas enquanto o usuário interage. Após 1h sem
// nenhuma requisição autenticada, derrubamos a sessão. Isto evita
// que usuários permaneçam em versões antigas após deploys.
// Para ajustar, mude SESSION_IDLE_SECONDS abaixo.
// =============================================================
if (!defined('SESSION_IDLE_SECONDS')) {
    define('SESSION_IDLE_SECONDS', 3600); // 1 hora
}

$logado = !empty($_SESSION['user_id']);

$isEndpoint = (strpos($_SERVER['REQUEST_URI'] ?? '', '/endpoints/') !== false);
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$isJsonExpected = $isEndpoint || stripos($accept, 'application/json') !== false;

// 1) Se está logado, verifica inatividade ANTES de prosseguir.
if ($logado) {
    $now  = time();
    $last = (int)($_SESSION['last_activity'] ?? 0);
    if ($last > 0 && ($now - $last) > SESSION_IDLE_SECONDS) {
        // Expirou — destruir sessão e tratar como não logado.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        @session_destroy();
        $logado = false;
    } else {
        // Renova o carimbo de atividade.
        $_SESSION['last_activity'] = $now;
    }
}

if (!$logado) {
    if ($isJsonExpected) {
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'msg' => 'Sessão expirada. Faça login novamente.',
            'expired' => true,
        ]);
        exit;
    }
    header('Location: /login/');
    exit;
}

// Libera o lock do arquivo de sessão imediatamente para não bloquear
// navegações paralelas (abas/requisições concorrentes do mesmo usuário).
// Os dados de $_SESSION continuam lidos em memória, apenas não são mais
// persistidos — páginas que precisam gravar sessão devem reabrir com
// session_start() antes de modificar.
if (function_exists('session_write_close')) {
    @session_write_close();
}

if (!function_exists('is_admin')) {
    function is_admin(): bool {
        return strtoupper((string)($_SESSION['user_perfil'] ?? '')) === 'ADMIN';
    }
}

if (!function_exists('require_admin')) {
    function require_admin(bool $json = true): void {
        if (is_admin()) return;
        if ($json) {
            if (function_exists('ob_get_level')) {
                while (ob_get_level() > 0) @ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'msg' => 'Acesso restrito a administradores.']);
            exit;
        }
        http_response_code(403);
        exit('Acesso negado.');
    }
}
