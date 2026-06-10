<?php
// /app/endpoints/transferencia_bancaria.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/saldos.php';
require_once __DIR__ . '/../config/helpers.php';

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) @ob_end_clean();
}
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function json_out_trb(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getBancoTrb(PDO $pdo, int $id): array|false
{
    $st = $pdo->prepare("
        SELECT BAN_ID, BAN_APELIDO, BAN_NOME, BAN_CODIGO, BAN_AGENCIA, BAN_CONTA, BAN_STATUS
        FROM tb_banco
        WHERE BAN_ID = :id
        LIMIT 1
    ");
    $st->execute([':id' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

function contaRefTrb(array $banco): string
{
    return trim((string)($banco['BAN_AGENCIA'] ?? '')) . '/' . trim((string)($banco['BAN_CONTA'] ?? ''));
}

try {
    $acao = trim((string)($_REQUEST['acao'] ?? ''));

    // ── COMBO DE BANCOS ATIVOS ──────────────────────────────────────────────
    if ($acao === 'combo_bancos') {
        $st = $pdo->query("
            SELECT BAN_ID AS id,
                   CONCAT(BAN_APELIDO, ' — ', BAN_CODIGO, ' ', BAN_NOME) AS nome,
                   BAN_AGENCIA, BAN_CONTA
            FROM tb_banco
            WHERE BAN_STATUS = 'ATIVO'
            ORDER BY BAN_APELIDO
        ");
        json_out_trb(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── LISTAR TRANSFERÊNCIAS ───────────────────────────────────────────────
    if ($acao === 'listar') {
        $status = trim((string)($_GET['status'] ?? ''));
        $de     = trim((string)($_GET['de'] ?? ''));
        $ate    = trim((string)($_GET['ate'] ?? ''));

        $where  = [];
        $params = [];

        if ($status !== '') {
            $where[]         = 'tr.TRB_STATUS = :status';
            $params[':status'] = $status;
        }
        if ($de !== '') {
            $where[]     = 'tr.TRB_DATA >= :de';
            $params[':de'] = $de;
        }
        if ($ate !== '') {
            $where[]      = 'tr.TRB_DATA <= :ate';
            $params[':ate'] = $ate;
        }

        $sql = "
            SELECT
                tr.TRB_CODIGO_PK,
                tr.TRB_DATA,
                tr.TRB_VALOR,
                tr.TRB_DESCRICAO,
                tr.TRB_STATUS,
                tr.TRB_USUARIO,
                tr.TRB_CRIADO_EM,
                CONCAT(bo.BAN_APELIDO, ' — ', bo.BAN_CODIGO, ' ', bo.BAN_NOME) AS banco_origem,
                CONCAT(bd.BAN_APELIDO, ' — ', bd.BAN_CODIGO, ' ', bd.BAN_NOME) AS banco_destino
            FROM tb_transferencia_bancaria tr
            JOIN tb_banco bo ON bo.BAN_ID = tr.TRB_BANCO_ORIGEM_FK
            JOIN tb_banco bd ON bd.BAN_ID = tr.TRB_BANCO_DESTINO_FK
        ";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY tr.TRB_DATA DESC, tr.TRB_CODIGO_PK DESC LIMIT 200';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        json_out_trb(['ok' => true, 'total' => count($rows), 'rows' => $rows]);
    }

    // ── SALVAR (criar transferência) ────────────────────────────────────────
    if ($acao === 'salvar') {
        $bancoOrigemId  = (int)($_POST['banco_origem_id']  ?? 0);
        $bancoDestinoId = (int)($_POST['banco_destino_id'] ?? 0);
        $valor          = (float)str_replace(',', '.', (string)($_POST['valor'] ?? '0'));
        $data           = trim((string)($_POST['data']     ?? ''));
        $descricao      = trim((string)($_POST['descricao'] ?? ''));
        $usuario        = (string)($_SESSION['user_nome']  ?? 'sistema');
        // Flag opcional: quando 0/false, pula a criação dos movimentos OFX sintéticos.
        // Usado pela "transferência rápida" do bancos.php — só ajusta saldo ERP, sem
        // gerar fantasmas no extrato pra conciliar depois.
        // Default = 1 (true) pra preservar comportamento da tela transferencia_bancaria.php.
        $gerarOfx = !isset($_POST['gerar_movimentos_ofx'])
                    || in_array((string)$_POST['gerar_movimentos_ofx'], ['1', 'true', 'on'], true);

        if ($bancoOrigemId <= 0 || $bancoDestinoId <= 0) {
            json_out_trb(['ok' => false, 'msg' => 'Selecione os bancos de origem e destino.'], 422);
        }
        if ($bancoOrigemId === $bancoDestinoId) {
            json_out_trb(['ok' => false, 'msg' => 'Banco de origem e destino devem ser diferentes.'], 422);
        }
        if ($valor <= 0) {
            json_out_trb(['ok' => false, 'msg' => 'Informe um valor positivo.'], 422);
        }
        if ($data === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            json_out_trb(['ok' => false, 'msg' => 'Data inválida.'], 422);
        }

        $bancoOrigem  = getBancoTrb($pdo, $bancoOrigemId);
        $bancoDestino = getBancoTrb($pdo, $bancoDestinoId);

        if (!$bancoOrigem || $bancoOrigem['BAN_STATUS'] !== 'ATIVO') {
            json_out_trb(['ok' => false, 'msg' => 'Banco de origem não encontrado ou inativo.'], 422);
        }
        if (!$bancoDestino || $bancoDestino['BAN_STATUS'] !== 'ATIVO') {
            json_out_trb(['ok' => false, 'msg' => 'Banco de destino não encontrado ou inativo.'], 422);
        }

        $contaRefOrigem  = contaRefTrb($bancoOrigem);
        $contaRefDestino = contaRefTrb($bancoDestino);

        $saldoAnteriorOrigem  = saldoErpConta($pdo, $bancoOrigemId,  $contaRefOrigem);
        $saldoAnteriorDestino = saldoErpConta($pdo, $bancoDestinoId, $contaRefDestino);
        $saldoNovoOrigem      = $saldoAnteriorOrigem  - $valor;
        $saldoNovoDestino     = $saldoAnteriorDestino + $valor;

        $obs = $descricao !== ''
            ? "Transferência para {$bancoDestino['BAN_APELIDO']}: {$descricao}"
            : "Transferência para {$bancoDestino['BAN_APELIDO']}";
        $obsDestino = $descricao !== ''
            ? "Transferência de {$bancoOrigem['BAN_APELIDO']}: {$descricao}"
            : "Transferência de {$bancoOrigem['BAN_APELIDO']}";

        $pdo->beginTransaction();

        $stAdj = $pdo->prepare("
            INSERT INTO tb_conciliacao_ajuste_saldo (
                CAS_BANCO_FK, CAS_CONTA_REF, CAS_DATA, CAS_CAMPO_AJUSTADO,
                CAS_OPERACAO, CAS_VALOR, CAS_SALDO_ANTERIOR, CAS_SALDO_NOVO,
                CAS_MOTIVO, CAS_OBSERVACAO, CAS_STATUS, CAS_USUARIO
            ) VALUES (
                :banco_fk, :conta_ref, :data, 'SALDO_ERP',
                :operacao, :valor, :saldo_anterior, :saldo_novo,
                'TRANSFERENCIA_BANCARIA', :observacao, 'ATIVO', :usuario
            )
        ");

        // SUB no banco origem
        $stAdj->execute([
            ':banco_fk'       => $bancoOrigemId,
            ':conta_ref'      => $contaRefOrigem,
            ':data'           => $data,
            ':operacao'       => 'SUB',
            ':valor'          => $valor,
            ':saldo_anterior' => $saldoAnteriorOrigem,
            ':saldo_novo'     => $saldoNovoOrigem,
            ':observacao'     => $obs,
            ':usuario'        => $usuario,
        ]);
        $ajusteOrigemId = (int)$pdo->lastInsertId();

        // SOMA no banco destino
        $stAdj->execute([
            ':banco_fk'       => $bancoDestinoId,
            ':conta_ref'      => $contaRefDestino,
            ':data'           => $data,
            ':operacao'       => 'SOMA',
            ':valor'          => $valor,
            ':saldo_anterior' => $saldoAnteriorDestino,
            ':saldo_novo'     => $saldoNovoDestino,
            ':observacao'     => $obsDestino,
            ':usuario'        => $usuario,
        ]);
        $ajusteDestinoId = (int)$pdo->lastInsertId();

        // Movimentos OFX sintéticos (visibilidade no extrato).
        // Pulado quando gerar_movimentos_ofx = 0 (modo "transferência rápida" do bancos.php):
        // só atualiza saldo ERP, sem criar linhas no extrato pra conciliar depois.
        $movOrigemId  = null;
        $movDestinoId = null;
        if ($gerarOfx) {
            $stMov = $pdo->prepare("
                INSERT INTO tb_conciliacao_ofx_movimento (
                    COM_IMPORTACAO_FK, COM_BANCO_FK, COM_CONTA_REF,
                    COM_DATA_MOVIMENTO, COM_DOCUMENTO, COM_DESCRICAO,
                    COM_VALOR, COM_SALDO_APOS, COM_TIPO, COM_HASH,
                    COM_STATUS, COM_CONCILIADO, COM_REFERENCIA_TIPO
                ) VALUES (
                    NULL, :banco_fk, :conta_ref,
                    :data, 'TRANSFERENCIA', :descricao,
                    :valor, :saldo_apos, :tipo, :hash,
                    'IMPORTADO', 'NAO', 'TRANSFERENCIA_BANCARIA'
                )
            ");

            $hashOrigem = hash('sha256', $bancoOrigemId . '|' . $contaRefOrigem . '|' . $data . '|-' . $valor . '|' . $obs . '|TRB|' . microtime(true));
            $stMov->execute([
                ':banco_fk'   => $bancoOrigemId,
                ':conta_ref'  => $contaRefOrigem,
                ':data'       => $data,
                ':descricao'  => mb_substr('TRANSFERENCIA SAIDA - ' . $obs, 0, 255),
                ':valor'      => -$valor,
                ':saldo_apos' => $saldoNovoOrigem,
                ':tipo'       => 'DEBITO',
                ':hash'       => $hashOrigem,
            ]);
            $movOrigemId = (int)$pdo->lastInsertId();

            $hashDestino = hash('sha256', $bancoDestinoId . '|' . $contaRefDestino . '|' . $data . '|' . $valor . '|' . $obsDestino . '|TRB|' . microtime(true));
            $stMov->execute([
                ':banco_fk'   => $bancoDestinoId,
                ':conta_ref'  => $contaRefDestino,
                ':data'       => $data,
                ':descricao'  => mb_substr('TRANSFERENCIA ENTRADA - ' . $obsDestino, 0, 255),
                ':valor'      => $valor,
                ':saldo_apos' => $saldoNovoDestino,
                ':tipo'       => 'CREDITO',
                ':hash'       => $hashDestino,
            ]);
            $movDestinoId = (int)$pdo->lastInsertId();
        }

        // Registro mestre da transferência
        $stTrb = $pdo->prepare("
            INSERT INTO tb_transferencia_bancaria (
                TRB_DATA, TRB_VALOR, TRB_BANCO_ORIGEM_FK, TRB_BANCO_DESTINO_FK,
                TRB_DESCRICAO, TRB_STATUS,
                TRB_AJUSTE_ORIGEM_FK, TRB_AJUSTE_DESTINO_FK,
                TRB_MOV_ORIGEM_FK, TRB_MOV_DESTINO_FK,
                TRB_USUARIO
            ) VALUES (
                :data, :valor, :banco_origem, :banco_destino,
                :descricao, 'ATIVO',
                :ajuste_origem, :ajuste_destino,
                :mov_origem, :mov_destino,
                :usuario
            )
        ");
        $stTrb->execute([
            ':data'           => $data,
            ':valor'          => $valor,
            ':banco_origem'   => $bancoOrigemId,
            ':banco_destino'  => $bancoDestinoId,
            ':descricao'      => $descricao,
            ':ajuste_origem'  => $ajusteOrigemId,
            ':ajuste_destino' => $ajusteDestinoId,
            ':mov_origem'     => $movOrigemId,
            ':mov_destino'    => $movDestinoId,
            ':usuario'        => $usuario,
        ]);

        $pdo->commit();

        json_out_trb(['ok' => true, 'msg' => 'Transferência registrada com sucesso.']);
    }

    // ── CANCELAR transferência ──────────────────────────────────────────────
    if ($acao === 'cancelar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            json_out_trb(['ok' => false, 'msg' => 'ID inválido.'], 422);
        }

        $st = $pdo->prepare("SELECT * FROM tb_transferencia_bancaria WHERE TRB_CODIGO_PK = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $trb = $st->fetch(PDO::FETCH_ASSOC);

        if (!$trb) {
            json_out_trb(['ok' => false, 'msg' => 'Transferência não encontrada.'], 404);
        }
        if ($trb['TRB_STATUS'] === 'CANCELADO') {
            json_out_trb(['ok' => false, 'msg' => 'Transferência já está cancelada.'], 422);
        }

        $pdo->beginTransaction();

        // Inativa os ajustes ERP
        foreach (['TRB_AJUSTE_ORIGEM_FK', 'TRB_AJUSTE_DESTINO_FK'] as $col) {
            if (!empty($trb[$col])) {
                $pdo->prepare("UPDATE tb_conciliacao_ajuste_saldo SET CAS_STATUS = 'INATIVO' WHERE CAS_CODIGO_PK = ?")
                    ->execute([$trb[$col]]);
            }
        }

        // Inativa os movimentos OFX sintéticos
        foreach (['TRB_MOV_ORIGEM_FK', 'TRB_MOV_DESTINO_FK'] as $col) {
            if (!empty($trb[$col])) {
                $pdo->prepare("UPDATE tb_conciliacao_ofx_movimento SET COM_STATUS = 'CANCELADO' WHERE COM_CODIGO_PK = ?")
                    ->execute([$trb[$col]]);
            }
        }

        $pdo->prepare("UPDATE tb_transferencia_bancaria SET TRB_STATUS = 'CANCELADO' WHERE TRB_CODIGO_PK = ?")
            ->execute([$id]);

        $pdo->commit();

        json_out_trb(['ok' => true, 'msg' => 'Transferência cancelada.']);
    }

    json_out_trb(['ok' => false, 'msg' => 'Ação não reconhecida.'], 400);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out_trb(['ok' => false, 'msg' => 'Erro interno: ' . $e->getMessage()], 500);
}
