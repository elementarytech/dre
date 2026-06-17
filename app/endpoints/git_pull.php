<?php
// /app/endpoints/git_pull.php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$repoDir = realpath(__DIR__ . '/../../');

if (!$repoDir || !is_dir($repoDir . '/.git')) {
    echo json_encode(['ok' => false, 'msg' => 'Diretório git não encontrado.']);
    exit;
}

// Garante que o SSH use a chave correta (o servidor web roda como outro usuário)
$sshKey  = 'C:/Users/carlos.santos/.ssh/id_ed25519_dre';
$sshKnown = 'C:/Users/carlos.santos/.ssh/known_hosts';
$gitSsh  = "ssh -i {$sshKey} -p 443 -o UserKnownHostsFile={$sshKnown} -o StrictHostKeyChecking=no";

$cmd = sprintf(
    'cd /d %s && git -c safe.directory=%s -c core.sshCommand=%s pull 2>&1',
    escapeshellarg($repoDir),
    escapeshellarg($repoDir),
    escapeshellarg($gitSsh)
);

$output   = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);

$text = implode("\n", $output);
$ok   = ($exitCode === 0);

// Detecta se já estava atualizado
$upToDate = str_contains($text, 'Already up to date') || str_contains($text, 'Já está atualizado');

echo json_encode([
    'ok'        => $ok,
    'up_to_date' => $upToDate,
    'output'    => $text,
]);
