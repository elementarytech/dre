<?php
/**
 * /app/sql/instalar_auditoria.php
 * ------------------------------------------------------------------
 * Instalador (idempotente) do sistema de AUDITORIA por triggers.
 *
 * Cria a tabela tb_auditoria e (re)gera os triggers AFTER INSERT/UPDATE/DELETE
 * para todas as tabelas financeiras listadas em $TABELAS. Cada trigger grava em
 * tb_auditoria o snapshot completo (antes/depois em JSON), QUEM fez (lido das
 * variáveis de sessão do MySQL @app_user_id / @app_user_nome / @app_ip, que o
 * config/conexao.php publica a partir de $_SESSION), quando e a ação.
 *
 * Rode novamente sempre que mudar o schema de alguma tabela auditada (os triggers
 * congelam a lista de colunas no momento da geração) ou ao adicionar tabelas.
 *
 * Uso (CLI):   php app/sql/instalar_auditoria.php
 * Uso (web):   acessível só por ADMIN (ver guarda abaixo).
 * ------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';

// Em ambiente web, restringe a administradores.
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../config/auth.php';
    if (strtoupper((string)($_SESSION['user_perfil'] ?? '')) !== 'ADMIN') {
        http_response_code(403);
        exit('Acesso restrito a administradores.');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

$TABELAS = [
    'tb_contas_pagar', 'tb_contas_receber', 'tb_rateio_contas_pagar',
    'tb_conciliacao_ajuste_saldo', 'tb_conciliacao_ofx_movimento', 'tb_conciliacao_ofx_importacao',
    'tb_conciliacao_vinculo', 'tb_conciliacao_resumo_conta',
    'tb_transferencia_bancaria', 'tb_transferencia_interna',
    'tb_banco', 'tb_plano_contas', 'tb_fornecedor',
    'tb_fluxo_caixa', 'tb_fluxo_caixa_banco',
    'contratos', 'contrato_parcelas', 'tb_forma_pagamento', 'tb_centro_custo',
];

$pdo->exec("CREATE TABLE IF NOT EXISTS tb_auditoria (
    AUD_ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    AUD_DATA_HORA DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    AUD_USUARIO_ID INT NULL,
    AUD_USUARIO_NOME VARCHAR(150) NULL,
    AUD_ORIGEM VARCHAR(20) NOT NULL DEFAULT 'APP',
    AUD_IP VARCHAR(45) NULL,
    AUD_TABELA VARCHAR(64) NOT NULL,
    AUD_REGISTRO_PK VARCHAR(64) NULL,
    AUD_ACAO ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    AUD_DADOS_ANTES JSON NULL,
    AUD_DADOS_DEPOIS JSON NULL,
    INDEX idx_aud_tabela_reg (AUD_TABELA, AUD_REGISTRO_PK),
    INDEX idx_aud_data (AUD_DATA_HORA),
    INDEX idx_aud_usuario (AUD_USUARIO_ID),
    INDEX idx_aud_acao (AUD_ACAO)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "tb_auditoria OK\n";

$pkDe = function (string $t) use ($pdo): ?string {
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY'
        ORDER BY ORDINAL_POSITION LIMIT 1");
    $st->execute([$t]);
    return $st->fetchColumn() ?: null;
};
$colsDe = function (string $t) use ($pdo): array {
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION");
    $st->execute([$t]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
$jsonObj = function (array $cols, string $prefix): string {
    $p = [];
    foreach ($cols as $c) $p[] = "'" . $c . "', " . $prefix . ".`" . $c . "`";
    return "JSON_OBJECT(" . implode(", ", $p) . ")";
};

$ORIGEM = "IF(@app_user_id IS NULL AND @app_user_nome IS NULL, 'SQL/DIRETO', 'APP')";

foreach ($TABELAS as $t) {
    $pk = $pkDe($t);
    if (!$pk) { echo "  PULADO $t (sem PK)\n"; continue; }
    $cols = $colsDe($t);
    $jOld = $jsonObj($cols, 'OLD');
    $jNew = $jsonObj($cols, 'NEW');

    foreach (['ins', 'upd', 'del'] as $ac) $pdo->exec("DROP TRIGGER IF EXISTS trg_aud_{$t}_{$ac}");

    $pdo->exec("CREATE TRIGGER trg_aud_{$t}_ins AFTER INSERT ON `$t` FOR EACH ROW
        INSERT INTO tb_auditoria (AUD_USUARIO_ID,AUD_USUARIO_NOME,AUD_ORIGEM,AUD_IP,AUD_TABELA,AUD_REGISTRO_PK,AUD_ACAO,AUD_DADOS_ANTES,AUD_DADOS_DEPOIS)
        VALUES (@app_user_id,@app_user_nome,$ORIGEM,@app_ip,'$t',NEW.`$pk`,'INSERT',NULL,$jNew)");
    $pdo->exec("CREATE TRIGGER trg_aud_{$t}_upd AFTER UPDATE ON `$t` FOR EACH ROW
        INSERT INTO tb_auditoria (AUD_USUARIO_ID,AUD_USUARIO_NOME,AUD_ORIGEM,AUD_IP,AUD_TABELA,AUD_REGISTRO_PK,AUD_ACAO,AUD_DADOS_ANTES,AUD_DADOS_DEPOIS)
        VALUES (@app_user_id,@app_user_nome,$ORIGEM,@app_ip,'$t',NEW.`$pk`,'UPDATE',$jOld,$jNew)");
    $pdo->exec("CREATE TRIGGER trg_aud_{$t}_del AFTER DELETE ON `$t` FOR EACH ROW
        INSERT INTO tb_auditoria (AUD_USUARIO_ID,AUD_USUARIO_NOME,AUD_ORIGEM,AUD_IP,AUD_TABELA,AUD_REGISTRO_PK,AUD_ACAO,AUD_DADOS_ANTES,AUD_DADOS_DEPOIS)
        VALUES (@app_user_id,@app_user_nome,$ORIGEM,@app_ip,'$t',OLD.`$pk`,'DELETE',$jOld,NULL)");

    echo "  triggers OK: $t (pk=$pk, " . count($cols) . " cols)\n";
}

$total = $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'trg_aud_%'")->fetchColumn();
echo "\nTotal de triggers de auditoria instalados: $total\n";
