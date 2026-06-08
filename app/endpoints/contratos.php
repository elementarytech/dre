<?php
// /app/endpoints/contratos.php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/contratos_endpoint.log');

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    json_out(['ok' => false, 'msg' => 'Não autenticado.'], 401);
}

$acao = $_REQUEST['acao'] ?? '';

try {
    $asStr = static fn($v) => trim((string)($v ?? ''));
    $asInt = static fn($v) => (int)($v ?? 0);

    $asDate = static function ($v): ?string {
        $v = trim((string)($v ?? ''));
        if ($v === '') return null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return null;
    };

    $asMoney = static function ($v): string {
        $v = (string)($v ?? '0');
        $v = str_replace(['R$', ' '], '', $v);

        if (strpos($v, ',') !== false) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } else {
            $v = str_replace(',', '.', $v);
        }

        if ($v === '' || !is_numeric($v)) $v = '0';
        return number_format((float)$v, 2, '.', '');
    };

    $addMonths = static function (string $dateYmd, int $months): string {
        $dt = new DateTime($dateYmd);
        $dt->modify('first day of this month');
        $dt->modify("+{$months} months");
        return $dt->format('Y-m-d');
    };

    $formatCompetencia = static function (string $ymd): string {
        $dt = new DateTime($ymd);
        return $dt->format('m/Y');
    };

    $ajustarDiaVenc = static function (string $refYmd, int $dia): string {
        $dt = new DateTime($refYmd);

        $ano = (int)$dt->format('Y');
        $mes = (int)$dt->format('m');

        $ultimoDia = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
        $dia = max(1, min($dia, $ultimoDia));

        $dt->setDate($ano, $mes, $dia);

        return $dt->format('Y-m-d');
    };

    $toBrDate = static function ($v): ?string {
        if (!$v) return null;
        $v = substr((string)$v, 0, 10);
        $dt = DateTime::createFromFormat('Y-m-d', $v);
        return $dt ? $dt->format('d/m/Y') : null;
    };

    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    $currentUserNome = trim((string)($_SESSION['user_nome'] ?? 'Usuário'));

    $getColumnNames = static function (PDO $pdo, string $table): array {
        $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cols = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        return array_map(static fn($c) => (string)$c['Field'], $cols);
    };

    $tableExists = static function (PDO $pdo, string $table): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    };

    $getUserAuthConfig = static function (PDO $pdo): ?array {
        $candidates = ['tb_usuarios', 'usuarios', 'tb_usuario', 'usuario', 'users', 'user'];

        foreach ($candidates as $table) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
            $st->execute([$table]);
            if (!(bool)$st->fetchColumn()) {
                continue;
            }

            $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            $fields = array_map(static fn($c) => (string)$c['Field'], $cols);

            $perfilCol = in_array('USU_PERFIL', $fields, true) ? 'USU_PERFIL' : null;

            $emailCol = null;
            foreach (['USU_EMAIL', 'email', 'EMAIL', 'usuario_email', 'user_email'] as $c) {
                if (in_array($c, $fields, true)) {
                    $emailCol = $c;
                    break;
                }
            }

            $senhaCol = null;
            foreach (['USU_SENHA_HASH', 'USU_SENHA', 'senha_hash', 'senha', 'password_hash', 'password', 'PASSWORD', 'SENHA'] as $c) {
                if (in_array($c, $fields, true)) {
                    $senhaCol = $c;
                    break;
                }
            }

            $nomeCol = null;
            foreach (['USU_NOME', 'nome', 'NOME', 'username', 'USU_LOGIN', 'login'] as $c) {
                if (in_array($c, $fields, true)) {
                    $nomeCol = $c;
                    break;
                }
            }

            $idCol = null;
            foreach (['USU_ID', 'id', 'ID', 'USUARIO_ID'] as $c) {
                if (in_array($c, $fields, true)) {
                    $idCol = $c;
                    break;
                }
            }

            if ($perfilCol && $emailCol && $senhaCol) {
                return [
                    'table' => $table,
                    'perfil_col' => $perfilCol,
                    'email_col' => $emailCol,
                    'senha_col' => $senhaCol,
                    'nome_col' => $nomeCol,
                    'id_col' => $idCol,
                ];
            }
        }

        return null;
    };

    $validateAdminMasterPassword = static function (PDO $pdo, string $email, string $password) use ($getUserAuthConfig): array {
        $email = trim($email);
        $password = trim($password);

        if ($email === '') {
            return ['ok' => false, 'msg' => 'Informe o e-mail do ADMIN.'];
        }

        if ($password === '') {
            return ['ok' => false, 'msg' => 'Informe a senha do ADMIN.'];
        }

        $cfg = $getUserAuthConfig($pdo);
        if (!$cfg) {
            return ['ok' => false, 'msg' => 'Tabela de usuários/admin não encontrada para validar a exclusão.'];
        }

        $select = [];
        if ($cfg['id_col']) $select[] = "`{$cfg['id_col']}` AS ADMIN_ID";
        if ($cfg['nome_col']) $select[] = "`{$cfg['nome_col']}` AS ADMIN_NOME";
        $select[] = "`{$cfg['email_col']}` AS ADMIN_EMAIL";
        $select[] = "`{$cfg['senha_col']}` AS ADMIN_SENHA";

        $sql = "SELECT " . implode(', ', $select) . "
                FROM `{$cfg['table']}`
                WHERE UPPER(TRIM(`{$cfg['perfil_col']}`)) = 'ADMIN'
                  AND LOWER(TRIM(`{$cfg['email_col']}`)) = LOWER(?) 
                LIMIT 1";

        $st = $pdo->prepare($sql);
        $st->execute([$email]);
        $admin = $st->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            return ['ok' => false, 'msg' => 'E-mail ADMIN não encontrado ou sem permissão.'];
        }

        $hash = (string)($admin['ADMIN_SENHA'] ?? '');
        if ($hash === '') {
            return ['ok' => false, 'msg' => 'Senha do ADMIN não cadastrada.'];
        }

        $ok = false;
        if (function_exists('password_verify') && password_verify($password, $hash)) {
            $ok = true;
        } elseif (hash_equals($hash, $password)) {
            $ok = true;
        } elseif (hash_equals($hash, md5($password))) {
            $ok = true;
        } elseif (hash_equals($hash, sha1($password))) {
            $ok = true;
        }

        if (!$ok) {
            return ['ok' => false, 'msg' => 'E-mail ou senha inválidos.'];
        }

        return [
            'ok' => true,
            'admin_id' => (int)($admin['ADMIN_ID'] ?? 0),
            'admin_nome' => (string)($admin['ADMIN_NOME'] ?? $email),
            'admin_email' => (string)($admin['ADMIN_EMAIL'] ?? $email),
        ];
    };

    $garantirTabelaLogExclusao = static function (PDO $pdo): void {
        $stExiste = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tb_log_exclusao_contratos'");
        $stExiste->execute();
        $existe = (int)$stExiste->fetchColumn() > 0;

        if (!$existe) {
            $sql = "CREATE TABLE tb_log_exclusao_contratos (
                LOG_ID INT NOT NULL AUTO_INCREMENT,
                LOG_CTR_ID INT NOT NULL,
                LOG_CLIENTE_ID INT NULL,
                LOG_CLIENTE_NOME VARCHAR(255) NULL,
                LOG_EMPRESA_ID INT NULL,
                LOG_VALOR DECIMAL(15,2) NULL,
                LOG_PARCELAS_EXCLUIDAS INT NOT NULL DEFAULT 0,
                LOG_CRE_EXCLUIDAS INT NOT NULL DEFAULT 0,
                LOG_USUARIO_ID INT NULL,
                LOG_USUARIO_NOME VARCHAR(255) NULL,
                LOG_ADMIN_AUTORIZADOR_ID INT NULL,
                LOG_ADMIN_AUTORIZADOR_NOME VARCHAR(255) NULL,
                LOG_ADMIN_AUTORIZADOR_EMAIL VARCHAR(255) NULL,
                LOG_IP VARCHAR(45) NULL,
                LOG_USER_AGENT VARCHAR(255) NULL,
                LOG_DATA DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (LOG_ID),
                KEY idx_ctr_id (LOG_CTR_ID),
                KEY idx_usuario_id (LOG_USUARIO_ID),
                KEY idx_data (LOG_DATA)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $pdo->exec($sql);

            $stExiste->execute();
            $existe = (int)$stExiste->fetchColumn() > 0;

            if (!$existe) {
                throw new RuntimeException('Não foi possível criar a tabela tb_log_exclusao_contratos. Verifique a permissão CREATE da conexão com o banco.');
            }
        }

        $colunasNecessarias = [
            'LOG_CTR_ID' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_CTR_ID INT NOT NULL",
            'LOG_CLIENTE_ID' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_CLIENTE_ID INT NULL",
            'LOG_CLIENTE_NOME' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_CLIENTE_NOME VARCHAR(255) NULL",
            'LOG_EMPRESA_ID' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_EMPRESA_ID INT NULL",
            'LOG_VALOR' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_VALOR DECIMAL(15,2) NULL",
            'LOG_PARCELAS_EXCLUIDAS' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_PARCELAS_EXCLUIDAS INT NOT NULL DEFAULT 0",
            'LOG_CRE_EXCLUIDAS' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_CRE_EXCLUIDAS INT NOT NULL DEFAULT 0",
            'LOG_USUARIO_ID' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_USUARIO_ID INT NULL",
            'LOG_USUARIO_NOME' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_USUARIO_NOME VARCHAR(255) NULL",
            'LOG_ADMIN_AUTORIZADOR_ID' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_ADMIN_AUTORIZADOR_ID INT NULL",
            'LOG_ADMIN_AUTORIZADOR_NOME' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_ADMIN_AUTORIZADOR_NOME VARCHAR(255) NULL",
            'LOG_ADMIN_AUTORIZADOR_EMAIL' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_ADMIN_AUTORIZADOR_EMAIL VARCHAR(255) NULL",
            'LOG_IP' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_IP VARCHAR(45) NULL",
            'LOG_USER_AGENT' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_USER_AGENT VARCHAR(255) NULL",
            'LOG_DATA' => "ALTER TABLE tb_log_exclusao_contratos ADD COLUMN LOG_DATA DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        ];

        $stCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tb_log_exclusao_contratos'");
        $stCols->execute();
        $cols = $stCols->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_map('strtoupper', $cols ?: []);

        foreach ($colunasNecessarias as $coluna => $sqlAlter) {
            if (!in_array(strtoupper($coluna), $cols, true)) {
                $pdo->exec($sqlAlter);
            }
        }
    };

    $registrarLogExclusao = static function (PDO $pdo, array $payload): void {
        $st = $pdo->prepare("INSERT INTO tb_log_exclusao_contratos (
            LOG_CTR_ID,
            LOG_CLIENTE_ID,
            LOG_CLIENTE_NOME,
            LOG_EMPRESA_ID,
            LOG_VALOR,
            LOG_PARCELAS_EXCLUIDAS,
            LOG_CRE_EXCLUIDAS,
            LOG_USUARIO_ID,
            LOG_USUARIO_NOME,
            LOG_ADMIN_AUTORIZADOR_ID,
            LOG_ADMIN_AUTORIZADOR_NOME,
            LOG_ADMIN_AUTORIZADOR_EMAIL,
            LOG_IP,
            LOG_USER_AGENT
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $st->execute([
            (int)($payload['ctr_id'] ?? 0),
            (int)($payload['cliente_id'] ?? 0),
            (string)($payload['cliente_nome'] ?? ''),
            (int)($payload['empresa_id'] ?? 0),
            (float)($payload['valor'] ?? 0),
            (int)($payload['parcelas_excluidas'] ?? 0),
            (int)($payload['cre_excluidas'] ?? 0),
            (int)($payload['usuario_id'] ?? 0),
            (string)($payload['usuario_nome'] ?? ''),
            (int)($payload['admin_id'] ?? 0),
            (string)($payload['admin_nome'] ?? ''),
            (string)($payload['admin_email'] ?? ''),
            (string)($payload['ip'] ?? ''),
            substr((string)($payload['user_agent'] ?? ''), 0, 255),
        ]);

        $logFile = __DIR__ . '/contratos_exclusoes.log';
        $line = json_encode([
            'data' => date('Y-m-d H:i:s'),
            'ctr_id' => (int)($payload['ctr_id'] ?? 0),
            'cliente_id' => (int)($payload['cliente_id'] ?? 0),
            'cliente_nome' => (string)($payload['cliente_nome'] ?? ''),
            'empresa_id' => (int)($payload['empresa_id'] ?? 0),
            'valor' => (float)($payload['valor'] ?? 0),
            'parcelas_excluidas' => (int)($payload['parcelas_excluidas'] ?? 0),
            'cre_excluidas' => (int)($payload['cre_excluidas'] ?? 0),
            'usuario_id' => (int)($payload['usuario_id'] ?? 0),
            'usuario_nome' => (string)($payload['usuario_nome'] ?? ''),
            'admin_id' => (int)($payload['admin_id'] ?? 0),
            'admin_nome' => (string)($payload['admin_nome'] ?? ''),
            'admin_email' => (string)($payload['admin_email'] ?? ''),
            'ip' => (string)($payload['ip'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
    };

    $garantirTabelaLogSuspensao = static function (PDO $pdo): void {
        $stExiste = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tb_log_suspensao_contratos'");
        $stExiste->execute();
        $existe = (int)$stExiste->fetchColumn() > 0;

        if (!$existe) {
            $sql = "CREATE TABLE tb_log_suspensao_contratos (
                LOG_ID INT NOT NULL AUTO_INCREMENT,
                LOG_CTR_ID INT NOT NULL,
                LOG_CLIENTE_ID INT NULL,
                LOG_CLIENTE_NOME VARCHAR(255) NULL,
                LOG_EMPRESA_ID INT NULL,
                LOG_VALOR DECIMAL(15,2) NULL,
                LOG_REMOVER_PARCELAS VARCHAR(3) NOT NULL DEFAULT 'NAO',
                LOG_PARCELAS_REMOVIDAS INT NOT NULL DEFAULT 0,
                LOG_CRE_REMOVIDAS INT NOT NULL DEFAULT 0,
                LOG_USUARIO_ID INT NULL,
                LOG_USUARIO_NOME VARCHAR(255) NULL,
                LOG_IP VARCHAR(45) NULL,
                LOG_USER_AGENT VARCHAR(255) NULL,
                LOG_DATA DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (LOG_ID),
                KEY idx_susp_ctr_id (LOG_CTR_ID),
                KEY idx_susp_usuario_id (LOG_USUARIO_ID),
                KEY idx_susp_data (LOG_DATA)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $pdo->exec($sql);
        }
    };

    $registrarLogSuspensao = static function (PDO $pdo, array $payload): void {
        $st = $pdo->prepare("INSERT INTO tb_log_suspensao_contratos (
            LOG_CTR_ID,
            LOG_CLIENTE_ID,
            LOG_CLIENTE_NOME,
            LOG_EMPRESA_ID,
            LOG_VALOR,
            LOG_REMOVER_PARCELAS,
            LOG_PARCELAS_REMOVIDAS,
            LOG_CRE_REMOVIDAS,
            LOG_USUARIO_ID,
            LOG_USUARIO_NOME,
            LOG_IP,
            LOG_USER_AGENT
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

        $st->execute([
            (int)($payload['ctr_id'] ?? 0),
            (int)($payload['cliente_id'] ?? 0),
            (string)($payload['cliente_nome'] ?? ''),
            (int)($payload['empresa_id'] ?? 0),
            (float)($payload['valor'] ?? 0),
            (($payload['remover_parcelas'] ?? 'NAO') === 'SIM') ? 'SIM' : 'NAO',
            (int)($payload['parcelas_removidas'] ?? 0),
            (int)($payload['cre_removidas'] ?? 0),
            (int)($payload['usuario_id'] ?? 0),
            (string)($payload['usuario_nome'] ?? ''),
            (string)($payload['ip'] ?? ''),
            substr((string)($payload['user_agent'] ?? ''), 0, 255),
        ]);
    };

    if ($acao === 'listar') {
        $buscar   = $asStr($_GET['buscar'] ?? '');
        $status   = $asStr($_GET['status'] ?? '');
        $tipo     = $asStr($_GET['tipo'] ?? '');
        $empresa  = $asInt($_GET['empresaId'] ?? 0);
        $parcelas = strtolower($asStr($_GET['parcelas'] ?? '')); // '', 'unica', 'multipla'

        $sql = "SELECT
                    c.CTR_ID, c.CTR_TIPO, c.CTR_STATUS,
                    c.CTR_VALOR_MENSAL, c.CTR_DT_INICIO, c.CTR_DT_FIM, c.CTR_DIA_VENCIMENTO,
                    c.CTR_CLIENTE_ID, c.CTR_EMPRESA_ID,
                    cli.CLI_NOME_RAZAO AS CLIENTE_NOME,
                    emp.EMP_RAZAO_SOCIAL AS EMPRESA_RAZAO,
                    emp.EMP_NOME_FANTASIA AS EMPRESA_FANTASIA,
                    (SELECT COUNT(*) FROM contrato_parcelas p WHERE p.CPA_CTR_ID = c.CTR_ID) AS QTD_PARCELAS
                FROM contratos c
                INNER JOIN cliente cli ON cli.CLI_ID = c.CTR_CLIENTE_ID
                INNER JOIN tb_empresa emp ON emp.EMP_ID = c.CTR_EMPRESA_ID
                WHERE 1=1";
        $params = [];

        if ($buscar !== '') {
            $sql .= " AND (c.CTR_ID LIKE ? OR cli.CLI_NOME_RAZAO LIKE ? OR emp.EMP_RAZAO_SOCIAL LIKE ? OR emp.EMP_NOME_FANTASIA LIKE ?)";
            $like = "%{$buscar}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($status !== '') {
            $sql .= " AND c.CTR_STATUS = ?";
            $params[] = $status;
        }

        if ($tipo !== '') {
            $sql .= " AND c.CTR_TIPO = ?";
            $params[] = $tipo;
        }

        if ($empresa > 0) {
            $sql .= " AND c.CTR_EMPRESA_ID = ?";
            $params[] = $empresa;
        }

        if ($parcelas === 'unica') {
            $sql .= " AND (SELECT COUNT(*) FROM contrato_parcelas p2 WHERE p2.CPA_CTR_ID = c.CTR_ID) = 1";
        } elseif ($parcelas === 'multipla') {
            $sql .= " AND (SELECT COUNT(*) FROM contrato_parcelas p2 WHERE p2.CPA_CTR_ID = c.CTR_ID) > 1";
        }

        $sql .= " ORDER BY c.CTR_ID DESC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['EMPRESA_NOME'] = $r['EMPRESA_FANTASIA'] ?: $r['EMPRESA_RAZAO'];
            $r['CTR_DT_INICIO'] = $toBrDate($r['CTR_DT_INICIO']);
            $r['CTR_DT_FIM']    = $toBrDate($r['CTR_DT_FIM']);
        }

        json_out([
            'ok' => true,
            'rows' => $rows,
            'total' => count($rows),
        ]);
    } elseif ($acao === 'get' || $acao === 'obter') {
        $id = $asInt($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("SELECT
                                c.*,
                                cli.CLI_NOME_RAZAO AS CLIENTE_NOME,
                                emp.EMP_RAZAO_SOCIAL AS EMPRESA_RAZAO,
                                emp.EMP_NOME_FANTASIA AS EMPRESA_FANTASIA
                             FROM contratos c
                             INNER JOIN cliente cli ON cli.CLI_ID = c.CTR_CLIENTE_ID
                             INNER JOIN tb_empresa emp ON emp.EMP_ID = c.CTR_EMPRESA_ID
                             WHERE c.CTR_ID=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) json_out(['ok' => false, 'msg' => 'Contrato não encontrado.'], 404);

        $row['EMPRESA_NOME'] = $row['EMPRESA_FANTASIA'] ?: $row['EMPRESA_RAZAO'];

        $st2 = $pdo->prepare("SELECT CPA_STATUS, CPA_VENCIMENTO FROM contrato_parcelas WHERE CPA_CTR_ID=?");
        $st2->execute([$id]);
        $parc = $st2->fetchAll(PDO::FETCH_ASSOC);

        $stats = [
            'total'     => 0,
            'recebidas' => 0,
            'em_aberto' => 0,
            'em_atraso' => 0,
        ];

        $proxima = null;
        $hoje = (new DateTime('now'))->format('Y-m-d');

        foreach ($parc as $p) {
            $stats['total']++;

            $stt = strtoupper((string)($p['CPA_STATUS'] ?? ''));
            if ($stt === 'RECEBIDO') $stats['recebidas']++;
            if ($stt === 'PROGRAMADO' || $stt === 'EM_ABERTO') $stats['em_aberto']++;
            if ($stt === 'ATRASO') $stats['em_atraso']++;

            if ($stt === 'RECEBIDO' || $stt === 'CANCELADO') continue;

            $venc = (string)($p['CPA_VENCIMENTO'] ?? '');
            $venc = substr($venc, 0, 10);
            if ($venc === '') continue;

            if ($venc >= $hoje) {
                if ($proxima === null || $venc < $proxima) $proxima = $venc;
            }
        }

        if ($proxima === null) {
            foreach ($parc as $p) {
                $stt = strtoupper((string)($p['CPA_STATUS'] ?? ''));
                if ($stt === 'RECEBIDO' || $stt === 'CANCELADO') continue;

                $venc = (string)($p['CPA_VENCIMENTO'] ?? '');
                $venc = substr($venc, 0, 10);
                if ($venc === '') continue;

                if ($proxima === null || $venc < $proxima) $proxima = $venc;
            }
        }

        json_out([
            'ok' => true,
            'row' => $row,
            'stats' => $stats,
            'proxima_cobranca' => $proxima
        ]);
    } elseif ($acao === 'combo_empresas') {
        $st = $pdo->query("SELECT EMP_ID, EMP_RAZAO_SOCIAL, EMP_NOME_FANTASIA, EMP_CNPJ
                           FROM tb_empresa
                           WHERE EMP_STATUS='ATIVO'
                           ORDER BY EMP_RAZAO_SOCIAL");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['EMP_NOME'] = $r['EMP_NOME_FANTASIA'] ?: $r['EMP_RAZAO_SOCIAL'];
        }
        json_out(['ok' => true, 'rows' => $rows]);
    } elseif ($acao === 'combo_clientes') {
        $q = $asStr($_GET['q'] ?? '');
        $sql = "SELECT CLI_ID, CLI_NOME_RAZAO, CLI_DOCUMENTO
                FROM cliente
                WHERE 1=1";
        $params = [];

        if ($q !== '') {
            $sql .= " AND CLI_NOME_RAZAO LIKE ?";
            $params[] = "%{$q}%";
        }

        $sql .= " ORDER BY CLI_NOME_RAZAO LIMIT 50";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        json_out(['ok' => true, 'rows' => $rows]);
    } elseif ($acao === 'obs_salvar') {
        require_post();

        $id  = $asInt($_POST['id'] ?? ($_POST['CTR_ID'] ?? 0));
        $obs = $asStr($_POST['CTR_OBSERVACAO_INTERNA'] ?? ($_POST['obs'] ?? ''));

        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st0 = $pdo->prepare("SELECT CTR_ID FROM contratos WHERE CTR_ID=?");
        $st0->execute([$id]);
        if (!$st0->fetchColumn()) {
            json_out(['ok' => false, 'msg' => 'Contrato não encontrado.'], 404);
        }

        $cols = $getColumnNames($pdo, 'contratos');
        $hasAtualizado = in_array('CTR_ATUALIZADO_EM', $cols, true);

        if ($hasAtualizado) {
            $st = $pdo->prepare("UPDATE contratos SET CTR_OBSERVACAO_INTERNA=?, CTR_ATUALIZADO_EM=NOW() WHERE CTR_ID=?");
            $st->execute([$obs, $id]);
        } else {
            $st = $pdo->prepare("UPDATE contratos SET CTR_OBSERVACAO_INTERNA=? WHERE CTR_ID=?");
            $st->execute([$obs, $id]);
        }

        json_out(['ok' => true, 'msg' => 'Observações salvas.']);
    } elseif ($acao === 'salvar') {
        require_post();

        $id = $asInt($_POST['CTR_ID'] ?? 0);
        $CTR_CLIENTE_ID         = $asInt($_POST['CTR_CLIENTE_ID'] ?? 0);
        $CTR_EMPRESA_ID         = $asInt($_POST['CTR_EMPRESA_ID'] ?? 0);
        $CTR_TIPO               = $asStr($_POST['CTR_TIPO'] ?? 'RECORRENTE');
        $CTR_STATUS             = $asStr($_POST['CTR_STATUS'] ?? 'ATIVO');
        $CTR_VALOR_MENSAL       = $asMoney($_POST['CTR_VALOR_MENSAL'] ?? '0');
        $CTR_DIA_VENCIMENTO     = $asInt($_POST['CTR_DIA_VENCIMENTO'] ?? 10);
        $CTR_DT_INICIO          = $asDate($_POST['CTR_DT_INICIO'] ?? '') ?? null;
        $CTR_DT_FIM             = $asDate($_POST['CTR_DT_FIM'] ?? '') ?? null;
        $CTR_FORMA_COBRANCA     = 'BOLETO';
        $CTR_BANCO_FK           = $asInt($_POST['CTR_BANCO'] ?? 0);
        $CTR_BANCO              = '';
        $CTR_CARTEIRA           = $asStr($_POST['CTR_CARTEIRA'] ?? '');
        $CTR_CONVENIO           = $asStr($_POST['CTR_CONVENIO'] ?? '');
        $CTR_AGENCIA            = $asStr($_POST['CTR_AGENCIA'] ?? '');
        $CTR_CONTA              = $asStr($_POST['CTR_CONTA'] ?? '');
        $CTR_PLANO_CONTAS       = $asStr($_POST['CTR_PLANO_CONTAS'] ?? '');
        $CTR_CENTRO_CUSTO       = $asStr($_POST['CTR_CENTRO_CUSTO'] ?? '');
        $CTR_REFERENCIA_INTERNA = $asStr($_POST['CTR_REFERENCIA_INTERNA'] ?? '');
        $CTR_REAJUSTE           = $asStr($_POST['CTR_REAJUSTE'] ?? 'NENHUM');
        $CTR_REAJUSTE_TEXTO     = $asStr($_POST['CTR_REAJUSTE_TEXTO'] ?? '');
        $CTR_DESCRICAO          = $asStr($_POST['CTR_DESCRICAO'] ?? '');
        $CTR_OBS_FINANCEIRA     = $asStr($_POST['CTR_OBS_FINANCEIRA'] ?? '');
        $CTR_OBSERVACAO_INTERNA = $asStr($_POST['CTR_OBSERVACAO_INTERNA'] ?? '');
        $GERAR_PARCELAS         = strtoupper($asStr($_POST['GERAR_PARCELAS'] ?? 'SIM'));
        $QTD_PARCELAS           = $asInt($_POST['QTD_PARCELAS'] ?? 12);

        if ($CTR_CLIENTE_ID <= 0) json_out(['ok' => false, 'msg' => 'Selecione um cliente.'], 422);
        if ($CTR_EMPRESA_ID <= 0) json_out(['ok' => false, 'msg' => 'Selecione a empresa gestora.'], 422);
        if (!$CTR_DT_INICIO) json_out(['ok' => false, 'msg' => 'Informe a data de início.'], 422);
        if ($CTR_DIA_VENCIMENTO < 1 || $CTR_DIA_VENCIMENTO > 31) {
            json_out(['ok' => false, 'msg' => 'Dia de vencimento deve ser 1..31.'], 422);
        }

        $validTipo = ['RECORRENTE', 'PARCELADO', 'UNICO'];
        if (!in_array($CTR_TIPO, $validTipo, true)) $CTR_TIPO = 'RECORRENTE';

        $validStatus = ['ATIVO', 'SUSPENSO', 'ENCERRADO'];
        if (!in_array($CTR_STATUS, $validStatus, true)) $CTR_STATUS = 'ATIVO';

        $validReaj = ['NENHUM', 'ANUAL', 'MENSAL'];
        if (!in_array($CTR_REAJUSTE, $validReaj, true)) $CTR_REAJUSTE = 'NENHUM';

        if ($CTR_BANCO_FK <= 0) {
            json_out(['ok' => false, 'msg' => 'Selecione o banco.'], 422);
        }

        if ($GERAR_PARCELAS === 'SIM') {
            if ($CTR_PLANO_CONTAS === '' || (int)$CTR_PLANO_CONTAS <= 0) {
                json_out(['ok' => false, 'msg' => 'Selecione o plano de contas (obrigatório quando gerar parcelas = Sim).'], 422);
            }
            if ($CTR_CENTRO_CUSTO === '' || (int)$CTR_CENTRO_CUSTO <= 0) {
                json_out(['ok' => false, 'msg' => 'Selecione o centro de custo (obrigatório quando gerar parcelas = Sim).'], 422);
            }
        }

        $stBanco = $pdo->prepare("SELECT BAN_ID, BAN_APELIDO, BAN_NOME FROM tb_banco WHERE BAN_ID = ? LIMIT 1");
        $stBanco->execute([$CTR_BANCO_FK]);
        $bancoRow = $stBanco->fetch(PDO::FETCH_ASSOC);

        if (!$bancoRow) {
            json_out(['ok' => false, 'msg' => 'Banco inválido.'], 422);
        }

        $CTR_BANCO = trim((string)($bancoRow['BAN_APELIDO'] ?: $bancoRow['BAN_NOME']));

        $pdo->beginTransaction();

        if ($id <= 0) {
            $sql = "INSERT INTO contratos (
                        CTR_CLIENTE_ID, CTR_EMPRESA_ID,
                        CTR_TIPO, CTR_STATUS,
                        CTR_VALOR_MENSAL, CTR_DIA_VENCIMENTO,
                        CTR_DT_INICIO, CTR_DT_FIM,
                        CTR_FORMA_COBRANCA, CTR_BANCO, CTR_BANCO_FK,
                        CTR_CARTEIRA, CTR_CONVENIO, CTR_AGENCIA, CTR_CONTA,
                        CTR_PLANO_CONTAS, CTR_CENTRO_CUSTO,
                        CTR_REFERENCIA_INTERNA,
                        CTR_REAJUSTE, CTR_REAJUSTE_TEXTO,
                        CTR_DESCRICAO, CTR_OBS_FINANCEIRA, CTR_OBSERVACAO_INTERNA
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $st = $pdo->prepare($sql);
            $st->execute([
                $CTR_CLIENTE_ID,
                $CTR_EMPRESA_ID,
                $CTR_TIPO,
                $CTR_STATUS,
                $CTR_VALOR_MENSAL,
                $CTR_DIA_VENCIMENTO,
                $CTR_DT_INICIO,
                $CTR_DT_FIM,
                $CTR_FORMA_COBRANCA,
                $CTR_BANCO,
                $CTR_BANCO_FK,
                $CTR_CARTEIRA,
                $CTR_CONVENIO,
                $CTR_AGENCIA,
                $CTR_CONTA,
                $CTR_PLANO_CONTAS,
                $CTR_CENTRO_CUSTO,
                $CTR_REFERENCIA_INTERNA,
                $CTR_REAJUSTE,
                $CTR_REAJUSTE_TEXTO,
                $CTR_DESCRICAO,
                $CTR_OBS_FINANCEIRA,
                $CTR_OBSERVACAO_INTERNA
            ]);
            $id = (int)$pdo->lastInsertId();
        } else {
            $sql = "UPDATE contratos SET
                        CTR_CLIENTE_ID=?,
                        CTR_EMPRESA_ID=?,
                        CTR_TIPO=?,
                        CTR_STATUS=?,
                        CTR_VALOR_MENSAL=?,
                        CTR_DIA_VENCIMENTO=?,
                        CTR_DT_INICIO=?,
                        CTR_DT_FIM=?,
                        CTR_FORMA_COBRANCA=?,
                        CTR_BANCO=?,
                        CTR_BANCO_FK=?,
                        CTR_CARTEIRA=?,
                        CTR_CONVENIO=?,
                        CTR_AGENCIA=?,
                        CTR_CONTA=?,
                        CTR_PLANO_CONTAS=?,
                        CTR_CENTRO_CUSTO=?,
                        CTR_REFERENCIA_INTERNA=?,
                        CTR_REAJUSTE=?,
                        CTR_REAJUSTE_TEXTO=?,
                        CTR_DESCRICAO=?,
                        CTR_OBS_FINANCEIRA=?,
                        CTR_OBSERVACAO_INTERNA=?
                    WHERE CTR_ID=?";
            $st = $pdo->prepare($sql);
            $st->execute([
                $CTR_CLIENTE_ID,
                $CTR_EMPRESA_ID,
                $CTR_TIPO,
                $CTR_STATUS,
                $CTR_VALOR_MENSAL,
                $CTR_DIA_VENCIMENTO,
                $CTR_DT_INICIO,
                $CTR_DT_FIM,
                $CTR_FORMA_COBRANCA,
                $CTR_BANCO,
                $CTR_BANCO_FK,
                $CTR_CARTEIRA,
                $CTR_CONVENIO,
                $CTR_AGENCIA,
                $CTR_CONTA,
                $CTR_PLANO_CONTAS,
                $CTR_CENTRO_CUSTO,
                $CTR_REFERENCIA_INTERNA,
                $CTR_REAJUSTE,
                $CTR_REAJUSTE_TEXTO,
                $CTR_DESCRICAO,
                $CTR_OBS_FINANCEIRA,
                $CTR_OBSERVACAO_INTERNA,
                $id
            ]);
        }

        if ($GERAR_PARCELAS === 'SIM') {
            $total = 12;
            if ($CTR_TIPO === 'UNICO') $total = 1;
            if ($CTR_TIPO === 'PARCELADO') $total = max(1, min(120, $QTD_PARCELAS));
            if ($CTR_TIPO === 'RECORRENTE') $total = max(1, min(120, $QTD_PARCELAS));

            $stCli = $pdo->prepare("SELECT CLI_ID, CLI_NOME_RAZAO, CLI_DOCUMENTO FROM cliente WHERE CLI_ID = ? LIMIT 1");
            $stCli->execute([$CTR_CLIENTE_ID]);
            $cliente = $stCli->fetch(PDO::FETCH_ASSOC);

            if (!$cliente) {
                throw new Exception('Cliente do contrato não encontrado para gerar contas a receber.');
            }

            $clienteNome = trim((string)($cliente['CLI_NOME_RAZAO'] ?? ''));
            $clienteCpfCnpj = trim((string)($cliente['CLI_DOCUMENTO'] ?? ''));

            $stDelParc = $pdo->prepare("DELETE FROM contrato_parcelas WHERE CPA_CTR_ID = ? AND CPA_STATUS IN ('PROGRAMADO','EM_ABERTO')");
            $stDelParc->execute([$id]);

            $stDelCre = $pdo->prepare("DELETE FROM tb_contas_receber WHERE CRE_CONTRATO_FK = ? AND CRE_STATUS IN ('ABERTO', 'PROGRAMADO', 'PENDENTE')");
            $stDelCre->execute([$id]);

            $stInsParc = $pdo->prepare("INSERT INTO contrato_parcelas (CPA_CTR_ID, CPA_NUM, CPA_TOTAL, CPA_COMPETENCIA, CPA_VENCIMENTO, CPA_VALOR, CPA_STATUS)
                                        VALUES (?,?,?,?,?,?, 'PROGRAMADO')");

            $stInsCre = $pdo->prepare("INSERT INTO tb_contas_receber (
                    CRE_ORIGEM,
                    CRE_CONTRATO_FK,
                    CRE_EMPRESA_FK,
                    CRE_PLANO_CONTAS_FK,
                    CRE_CENTRO_CUSTO_FK,
                    CRE_BANCO_FK,
                    CRE_COMPETENCIA,
                    CRE_VENCIMENTO,
                    CRE_CLIENTE_FK,
                    CRE_CLIENTE_NOME,
                    CRE_CPF_CNPJ,
                    CRE_VALOR,
                    CRE_FORMA_COBRANCA,
                    CRE_DOCUMENTO,
                    CRE_STATUS,
                    CRE_OBSERVACAO
                ) VALUES ('CONTRATO', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ABERTO', ?)");

            for ($i = 0; $i < $total; $i++) {
                $numParcela = $i + 1;
                $refMes = $addMonths((string)$CTR_DT_INICIO, $i);
                $venc = $ajustarDiaVenc($refMes, $CTR_DIA_VENCIMENTO);
                $competStr = $formatCompetencia($refMes);
                $competDate = date('Y-m-01', strtotime($refMes));

                $documento = $id . '/' . str_pad((string)$numParcela, 2, '0', STR_PAD_LEFT);
                $obsConta = 'Gerado automaticamente a partir do contrato ID ' . $id . ' - parcela ' . $numParcela . '/' . $total;

                $stInsParc->execute([
                    $id,
                    $numParcela,
                    $total,
                    $competStr,
                    $venc,
                    $CTR_VALOR_MENSAL
                ]);

                $stInsCre->execute([
                    $id,
                    $CTR_EMPRESA_ID,
                    ($CTR_PLANO_CONTAS !== '' ? (int)$CTR_PLANO_CONTAS : null),
                    ($CTR_CENTRO_CUSTO !== '' ? (int)$CTR_CENTRO_CUSTO : null),
                    ($CTR_BANCO_FK > 0 ? $CTR_BANCO_FK : null),
                    $competDate,
                    $venc,
                    $CTR_CLIENTE_ID,
                    $clienteNome,
                    $clienteCpfCnpj,
                    $CTR_VALOR_MENSAL,
                    $CTR_FORMA_COBRANCA,
                    $documento,
                    $obsConta
                ]);
            }
        }

        $pdo->commit();
        json_out(['ok' => true, 'msg' => 'Contrato salvo.', 'id' => $id]);
    } elseif ($acao === 'suspender') {
        require_post();
        $id = $asInt($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $removerParcelas = strtoupper(trim((string)($_POST['remover_parcelas'] ?? 'NAO'))) === 'SIM';

        $stInfo = $pdo->prepare("SELECT c.CTR_ID, c.CTR_CLIENTE_ID, c.CTR_EMPRESA_ID, c.CTR_VALOR_MENSAL,
                                        cli.CLI_NOME_RAZAO
                                   FROM contratos c
                                   LEFT JOIN cliente cli ON cli.CLI_ID = c.CTR_CLIENTE_ID
                                  WHERE c.CTR_ID = ?");
        $stInfo->execute([$id]);
        $info = $stInfo->fetch(PDO::FETCH_ASSOC);

        if (!$info) json_out(['ok' => false, 'msg' => 'Contrato não encontrado.'], 404);

        $garantirTabelaLogSuspensao($pdo);

        $pdo->beginTransaction();
        try {
            $parcelasRemovidas = 0;
            $creRemovidas = 0;

            if ($removerParcelas) {
                $stCre = $pdo->prepare("DELETE FROM tb_contas_receber WHERE CRE_CONTRATO_FK = ? AND CRE_STATUS IN ('ABERTO','PROGRAMADO','PENDENTE')");
                $stCre->execute([$id]);
                $creRemovidas = $stCre->rowCount();

                $stParc = $pdo->prepare("DELETE FROM contrato_parcelas WHERE CPA_CTR_ID = ? AND CPA_STATUS IN ('PROGRAMADO','EM_ABERTO')");
                $stParc->execute([$id]);
                $parcelasRemovidas = $stParc->rowCount();
            }

            $st = $pdo->prepare("UPDATE contratos SET CTR_STATUS='SUSPENSO' WHERE CTR_ID=?");
            $st->execute([$id]);

            $registrarLogSuspensao($pdo, [
                'ctr_id' => $id,
                'cliente_id' => (int)($info['CTR_CLIENTE_ID'] ?? 0),
                'cliente_nome' => (string)($info['CLI_NOME_RAZAO'] ?? ''),
                'empresa_id' => (int)($info['CTR_EMPRESA_ID'] ?? 0),
                'valor' => (float)($info['CTR_VALOR_MENSAL'] ?? 0),
                'remover_parcelas' => $removerParcelas ? 'SIM' : 'NAO',
                'parcelas_removidas' => $parcelasRemovidas,
                'cre_removidas' => $creRemovidas,
                'usuario_id' => $currentUserId,
                'usuario_nome' => $currentUserNome,
                'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);

            $pdo->commit();

            json_out([
                'ok' => true,
                'msg' => 'Contrato suspenso.',
                'remover_parcelas' => $removerParcelas ? 'SIM' : 'NAO',
                'parcelas_removidas' => $parcelasRemovidas,
                'contas_receber_removidas' => $creRemovidas
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['ok' => false, 'msg' => 'Falha ao suspender: ' . $e->getMessage()], 500);
        }
    } elseif ($acao === 'reativar') {
        require_post();
        $id = $asInt($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("UPDATE contratos SET CTR_STATUS='ATIVO' WHERE CTR_ID=?");
        $st->execute([$id]);
        json_out(['ok' => true, 'msg' => 'Contrato reativado.']);
    } elseif ($acao === 'excluir') {
        require_post();

        $id = $asInt($_POST['id'] ?? 0);
        $usuarioAdmin = $asStr($_POST['usuario_admin'] ?? ''); // recebe o e-mail do ADMIN
        $senhaMaster = $asStr($_POST['senha_master'] ?? '');

        if ($id <= 0) {
            json_out(['ok' => false, 'msg' => 'ID do contrato inválido.'], 422);
        }

        $auth = $validateAdminMasterPassword($pdo, $usuarioAdmin, $senhaMaster);
        if (empty($auth['ok'])) {
            json_out(['ok' => false, 'msg' => $auth['msg'] ?? 'Senha master inválida.'], 403);
        }

        $stContrato = $pdo->prepare("SELECT c.*, cli.CLI_NOME_RAZAO AS CLIENTE_NOME
                                     FROM contratos c
                                     LEFT JOIN cliente cli ON cli.CLI_ID = c.CTR_CLIENTE_ID
                                     WHERE c.CTR_ID = ?");
        $stContrato->execute([$id]);
        $contrato = $stContrato->fetch(PDO::FETCH_ASSOC);

        if (!$contrato) {
            $stParcRest = $pdo->prepare("SELECT COUNT(*) FROM contrato_parcelas WHERE CPA_CTR_ID = ?");
            $stParcRest->execute([$id]);
            $restParc = (int)$stParcRest->fetchColumn();

            $stCreRest = $pdo->prepare("SELECT COUNT(*) FROM tb_contas_receber WHERE CRE_CONTRATO_FK = ?");
            $stCreRest->execute([$id]);
            $restCre = (int)$stCreRest->fetchColumn();

            if ($restParc === 0 && $restCre === 0) {
                json_out([
                    'ok' => true,
                    'msg' => 'Contrato já foi excluído.',
                    'parcelas_excluidas' => 0,
                    'contas_receber_excluidas' => 0,
                ]);
            }

            json_out(['ok' => false, 'msg' => 'Contrato não encontrado.'], 404);
        }

        $garantirTabelaLogExclusao($pdo);

        $pdo->beginTransaction();

        $stCountParc = $pdo->prepare("SELECT COUNT(*) FROM contrato_parcelas WHERE CPA_CTR_ID = ?");
        $stCountParc->execute([$id]);
        $parcelasExcluidas = (int)$stCountParc->fetchColumn();

        $stCountCre = $pdo->prepare("SELECT COUNT(*) FROM tb_contas_receber WHERE CRE_CONTRATO_FK = ?");
        $stCountCre->execute([$id]);
        $creExcluidas = (int)$stCountCre->fetchColumn();

        $stDelCre = $pdo->prepare("DELETE FROM tb_contas_receber WHERE CRE_CONTRATO_FK = ?");
        $stDelCre->execute([$id]);

        $stDelParc = $pdo->prepare("DELETE FROM contrato_parcelas WHERE CPA_CTR_ID = ?");
        $stDelParc->execute([$id]);

        $stDelCtr = $pdo->prepare("DELETE FROM contratos WHERE CTR_ID = ? LIMIT 1");
        $stDelCtr->execute([$id]);

        if ($stDelCtr->rowCount() < 1) {
            throw new RuntimeException('Não foi possível excluir o contrato.');
        }

        $registrarLogExclusao($pdo, [
            'ctr_id' => (int)$contrato['CTR_ID'],
            'cliente_id' => (int)($contrato['CTR_CLIENTE_ID'] ?? 0),
            'cliente_nome' => (string)($contrato['CLIENTE_NOME'] ?? ''),
            'empresa_id' => (int)($contrato['CTR_EMPRESA_ID'] ?? 0),
            'valor' => (float)($contrato['CTR_VALOR_MENSAL'] ?? 0),
            'parcelas_excluidas' => $parcelasExcluidas,
            'cre_excluidas' => $creExcluidas,
            'usuario_id' => $currentUserId,
            'usuario_nome' => $currentUserNome,
            'admin_id' => (int)($auth['admin_id'] ?? 0),
            'admin_nome' => (string)($auth['admin_nome'] ?? 'ADMIN'),
            'admin_email' => (string)($auth['admin_email'] ?? ''),
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);

        $pdo->commit();

        json_out([
            'ok' => true,
            'msg' => 'Contrato excluído com sucesso.',
            'parcelas_excluidas' => $parcelasExcluidas,
            'contas_receber_excluidas' => $creExcluidas,
        ]);
    } elseif ($acao === 'parcelas_listar') {
        $ctrId = $asInt($_GET['ctrId'] ?? 0);
        if ($ctrId <= 0) json_out(['ok' => false, 'msg' => 'ctrId inválido.'], 422);

        $st = $pdo->prepare("SELECT * FROM contrato_parcelas WHERE CPA_CTR_ID=? ORDER BY CPA_NUM ASC");
        $st->execute([$ctrId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        json_out(['ok' => true, 'rows' => $rows, 'total' => count($rows)]);
    } elseif ($acao === 'cnab_upload') {
        require_post();

        $ctrId = $asInt($_POST['CNB_CTR_ID'] ?? ($_POST['ctrId'] ?? 0));
        $tipo  = strtoupper($asStr($_POST['CNB_TIPO'] ?? ($_POST['tipo'] ?? 'REMESSA')));
        $banco = strtoupper($asStr($_POST['CNB_BANCO'] ?? ($_POST['banco'] ?? 'BRADESCO')));
        $obs   = $asStr($_POST['CNB_OBS'] ?? ($_POST['obs'] ?? ''));

        if ($ctrId <= 0) json_out(['ok' => false, 'msg' => 'Contrato inválido.'], 422);
        if (!in_array($tipo, ['REMESSA', 'RETORNO'], true)) $tipo = 'REMESSA';
        if (!in_array($banco, ['BRADESCO', 'SICREDI'], true)) $banco = 'BRADESCO';
        if (empty($_FILES['arquivo']['tmp_name'])) json_out(['ok' => false, 'msg' => 'Envie um arquivo.'], 422);

        $tmp = $_FILES['arquivo']['tmp_name'];
        $orig = basename((string)$_FILES['arquivo']['name']);
        $sha1 = sha1_file($tmp) ?: '';
        if ($sha1 === '') json_out(['ok' => false, 'msg' => 'Falha ao ler arquivo.'], 422);

        $dir = __DIR__ . '/../upload/cnab';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $nomeFinal = date('Ymd_His') . "_CTR{$ctrId}_{$tipo}_{$banco}_" . preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', $orig);
        $dest = $dir . '/' . $nomeFinal;

        if (!@move_uploaded_file($tmp, $dest)) {
            json_out(['ok' => false, 'msg' => 'Falha ao salvar arquivo no servidor.'], 500);
        }

        $st = $pdo->prepare("INSERT INTO contrato_arquivos_cnab (CNB_CTR_ID, CNB_TIPO, CNB_BANCO, CNB_NOME_ARQUIVO, CNB_SHA1, CNB_OBS)
                             VALUES (?,?,?,?,?,?)");
        $st->execute([$ctrId, $tipo, $banco, $nomeFinal, $sha1, $obs]);

        json_out(['ok' => true, 'msg' => 'Arquivo registrado.', 'arquivo' => $nomeFinal]);
    } elseif ($acao === 'cnab_listar') {
        $ctrId = $asInt($_GET['ctrId'] ?? 0);
        if ($ctrId <= 0) json_out(['ok' => false, 'msg' => 'ctrId inválido.'], 422);

        $st = $pdo->prepare("SELECT * FROM contrato_arquivos_cnab WHERE CNB_CTR_ID=? ORDER BY CNB_ID DESC");
        $st->execute([$ctrId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        json_out(['ok' => true, 'rows' => $rows, 'total' => count($rows)]);
    } elseif ($acao === 'log_exclusoes_listar') {
        $garantirTabelaLogExclusao($pdo);

        $buscar = $asStr($_GET['buscar'] ?? '');
        $dataIni = $asStr($_GET['data_ini'] ?? '');
        $dataFim = $asStr($_GET['data_fim'] ?? '');
        $usuario = $asStr($_GET['usuario'] ?? '');
        $admin = $asStr($_GET['admin'] ?? '');

        $sql = "SELECT *
                FROM tb_log_exclusao_contratos
                WHERE 1=1";
        $params = [];

        if ($buscar !== '') {
            $sql .= " AND (
                CAST(LOG_CTR_ID AS CHAR) LIKE ?
                OR LOG_CLIENTE_NOME LIKE ?
                OR LOG_USUARIO_NOME LIKE ?
                OR LOG_ADMIN_AUTORIZADOR_NOME LIKE ?
                OR LOG_ADMIN_AUTORIZADOR_EMAIL LIKE ?
            )";
            $like = "%{$buscar}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($usuario !== '') {
            $sql .= " AND LOG_USUARIO_NOME LIKE ?";
            $params[] = "%{$usuario}%";
        }

        if ($admin !== '') {
            $sql .= " AND (LOG_ADMIN_AUTORIZADOR_NOME LIKE ? OR LOG_ADMIN_AUTORIZADOR_EMAIL LIKE ?)";
            $params[] = "%{$admin}%";
            $params[] = "%{$admin}%";
        }

        if ($dataIni !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataIni)) {
            $sql .= " AND DATE(LOG_DATA) >= ?";
            $params[] = $dataIni;
        }

        if ($dataFim !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
            $sql .= " AND DATE(LOG_DATA) <= ?";
            $params[] = $dataFim;
        }

        $sql .= " ORDER BY LOG_ID DESC LIMIT 500";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        json_out([
            'ok' => true,
            'rows' => $rows,
            'total' => count($rows),
        ]);
    } elseif ($acao === 'log_suspensoes_listar') {
        $garantirTabelaLogSuspensao($pdo);

        $buscar = $asStr($_GET['buscar'] ?? '');
        $dataIni = $asStr($_GET['data_ini'] ?? '');
        $dataFim = $asStr($_GET['data_fim'] ?? '');
        $usuario = $asStr($_GET['usuario'] ?? '');
        $modo = strtoupper($asStr($_GET['modo'] ?? ''));

        $sql = "SELECT *
                FROM tb_log_suspensao_contratos
                WHERE 1=1";
        $params = [];

        if ($buscar !== '') {
            $sql .= " AND (
                CAST(LOG_CTR_ID AS CHAR) LIKE ?
                OR LOG_CLIENTE_NOME LIKE ?
                OR LOG_USUARIO_NOME LIKE ?
            )";
            $like = "%{$buscar}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($usuario !== '') {
            $sql .= " AND LOG_USUARIO_NOME LIKE ?";
            $params[] = "%{$usuario}%";
        }

        if ($modo === 'SIM' || $modo === 'NAO') {
            $sql .= " AND LOG_REMOVER_PARCELAS = ?";
            $params[] = $modo;
        }

        if ($dataIni !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataIni)) {
            $sql .= " AND DATE(LOG_DATA) >= ?";
            $params[] = $dataIni;
        }

        if ($dataFim !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
            $sql .= " AND DATE(LOG_DATA) <= ?";
            $params[] = $dataFim;
        }

        $sql .= " ORDER BY LOG_ID DESC LIMIT 500";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        json_out([
            'ok' => true,
            'rows' => $rows,
            'total' => count($rows),
        ]);
    } else {
        json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok' => false, 'msg' => 'Erro no banco.', 'detail' => $e->getMessage()], 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok' => false, 'msg' => 'Erro interno.', 'detail' => $e->getMessage()], 500);
}
