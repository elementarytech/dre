<?php

declare(strict_types=1);

require_once __DIR__ . '/config/conexao.php';

$nome  = 'Carlos Roberto';
$email = 'carlosdombosco@gmail.com';
$senha = '123'; // troque depois

$hash = password_hash($senha, PASSWORD_DEFAULT);

$sql = "INSERT INTO usuarios (USU_NOME, USU_EMAIL, USU_SENHA_HASH, USU_PERFIL, USU_STATUS)
        VALUES (?, ?, ?, 'ADMIN', 'ATIVO')";

try {
    $pdo->prepare($sql)->execute([$nome, $email, $hash]);
    echo "ADMIN criado: {$email} / {$senha}";
} catch (Throwable $e) {
    echo "Erro: " . $e->getMessage();
}
