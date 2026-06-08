<?php
require_once __DIR__ . '/../conn/conn.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $acao = $_REQUEST['acao'] ?? '';

  // helpers
  $jsonOk = function ($arr) {
    echo json_encode(array_merge(['ok' => true], $arr), JSON_UNESCAPED_UNICODE);
    exit;
  };
  $jsonErr = function ($msg, $detail = null) {
    echo json_encode(['ok' => false, 'msg' => $msg, 'detail' => $detail], JSON_UNESCAPED_UNICODE);
    exit;
  };

  $asInt = function ($v) {
    return (int)($v ?? 0);
  };

  $asStr = function ($v) {
    return trim((string)($v ?? ''));
  };

  // ============================================================
  // AUTOCOMPLETE CLIENTE (nome/cpf_cnpj)
  // ============================================================
  if ($acao === 'autocomplete_cliente') {
    $q = trim($_GET['q'] ?? '');
    $limit = (int)($_GET['limit'] ?? 10);
    if ($limit <= 0 || $limit > 50) $limit = 10;

    $sql = "
      SELECT
        CLI_ID as id,
        CLI_NOME_RAZAO as nome,
        CLI_CPF_CNPJ as cpf_cnpj
      FROM cliente
      WHERE (CLI_NOME_RAZAO LIKE :q OR CLI_CPF_CNPJ LIKE :q)
      ORDER BY CLI_NOME_RAZAO
      LIMIT {$limit}
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':q' => "%{$q}%"]);
    $jsonOk(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  // ============================================================
  // COMBO EMPRESAS
  // ============================================================
  if ($acao === 'combo_empresas') {
    $sql = "
      SELECT
        EMP_ID AS id,
        COALESCE(NULLIF(EMP_NOME_FANTASIA,''), EMP_RAZAO_SOCIAL) AS nome
      FROM tb_empresa
      WHERE EMP_STATUS = 'ATIVO'
      ORDER BY nome
    ";
    $st = $pdo->query($sql);
    $jsonOk(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  // ============================================================
  // COMBO PLANO DE CONTAS (FILTRADO POR EMPRESA)
  // ============================================================
  if ($acao === 'combo_plano_contas') {
    $sql = "
      SELECT
        PLC_CODIGO_PK AS id,
        CONCAT(
          COALESCE(NULLIF(PLC_CODIGO,''), CONCAT('#', PLC_CODIGO_PK)),
          ' - ',
          COALESCE(NULLIF(PLC_NOME,''), 'Sem nome')
        ) AS nome
      FROM tb_plano_contas
      WHERE PLC_STATUS = 'ATIVO'
      ORDER BY PLC_CODIGO ASC, PLC_NOME ASC
    ";

    $st = $pdo->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $jsonOk(['rows' => $rows, 'dados' => $rows]);
  }

  // ============================================================
  // COMBO CENTRO DE CUSTO (FILTRADO POR EMPRESA)
  // ============================================================
  if ($acao === 'combo_centro_custo') {
    $sql = "
      SELECT
        CEC_ID AS id,
        CONCAT(
          COALESCE(NULLIF(CEC_CODIGO,''), CONCAT('#', CEC_ID)),
          ' - ',
          COALESCE(NULLIF(CEC_NOME,''), 'Sem nome')
        ) AS nome
      FROM tb_centro_custo
      WHERE CEC_STATUS = 'ATIVO'
      ORDER BY CEC_CODIGO ASC, CEC_NOME ASC
    ";

    $st = $pdo->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $jsonOk(['rows' => $rows, 'dados' => $rows]);
  }

  // ============================================================
  // COMBO BANCOS
  // ============================================================
  if ($acao === 'combo_bancos') {
    $sql = "
      SELECT
        BAN_ID AS id,
        COALESCE(NULLIF(BAN_APELIDO,''), BAN_NOME) AS nome
      FROM tb_banco
      WHERE BAN_STATUS = 'ATIVO'
      ORDER BY nome
    ";
    $st = $pdo->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $jsonOk(['rows' => $rows, 'dados' => $rows]);
  }

  // ============================================================
  // COMBO CONTRATOS (CTR_NUMERO - NOME_CLIENTE)
  // ============================================================
  if ($acao === 'combo_contratos') {
    $q = trim($_GET['q'] ?? '');
    $limit = (int)($_GET['limit'] ?? 50);
    if ($limit <= 0 || $limit > 200) $limit = 50;

    $sql = "
      SELECT
        c.CTR_ID as id,
        c.CTR_NUMERO as numero,
        cl.CLI_NOME_RAZAO as cliente_nome,
        cl.CLI_ID as cliente_id
      FROM tb_contratos c
      LEFT JOIN cliente cl ON cl.CLI_ID = c.CTR_CLIENTE_FK
      WHERE 1=1
    ";
    $params = [];

    if ($q !== '') {
      $sql .= " AND (c.CTR_NUMERO LIKE :q1 OR cl.CLI_NOME_RAZAO LIKE :q2)";
      $params[':q1'] = "%{$q}%";
      $params[':q2'] = "%{$q}%";
    }

    $sql .= " ORDER BY c.CTR_NUMERO DESC LIMIT {$limit}";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
      $r['label'] = trim(($r['numero'] ?? '') . ' - ' . ($r['cliente_nome'] ?? ''));
    }
    $jsonOk(['rows' => $rows]);
  }

  // ============================================================
  // COMBO FORMAS
  // ============================================================
  if ($acao === 'combo_formas') {
    $rows = [
      ['id' => '', 'nome' => 'Selecione...'],
      ['id' => 'BOLETO', 'nome' => 'Boleto'],
      ['id' => 'PIX', 'nome' => 'Pix'],
      ['id' => 'CARTAO', 'nome' => 'Cartão'],
      ['id' => 'DINHEIRO', 'nome' => 'Dinheiro'],
      ['id' => 'TRANSFERENCIA', 'nome' => 'Transferência'],
    ];
    $jsonOk(['rows' => $rows]);
  }

  // ============================================================
  // LISTAR (filtros + paginação)
  // ============================================================
  if ($acao === 'listar') {
    $q = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $origem = trim($_GET['origem'] ?? '');
    $dtIni = trim($_GET['dtIni'] ?? '');
    $dtFim = trim($_GET['dtFim'] ?? '');

    $page = (int)($_GET['page'] ?? 1);
    $per  = (int)($_GET['per_page'] ?? 20);
    if ($page < 1) $page = 1;
    if ($per < 5) $per = 5;
    if ($per > 200) $per = 200;
    $off = ($page - 1) * $per;

    $where = " WHERE 1=1 ";
    $params = [];

    if ($status !== '') {
      $where .= " AND r.CRE_STATUS = :st ";
      $params[':st'] = $status;
    }
    if ($origem !== '') {
      $where .= " AND r.CRE_ORIGEM = :ori ";
      $params[':ori'] = $origem;
    }
    if ($dtIni !== '') {
      $where .= " AND r.CRE_VENCIMENTO >= :ini ";
      $params[':ini'] = $dtIni;
    }
    if ($dtFim !== '') {
      $where .= " AND r.CRE_VENCIMENTO <= :fim ";
      $params[':fim'] = $dtFim;
    }

    if ($q !== '') {
      $where .= " AND (
        r.CRE_CLIENTE_NOME LIKE :q1 OR
        r.CRE_CPF_CNPJ LIKE :q2 OR
        r.CRE_DOCUMENTO LIKE :q3
      ) ";
      $params[':q1'] = "%{$q}%";
      $params[':q2'] = "%{$q}%";
      $params[':q3'] = "%{$q}%";
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM tb_contas_receber r {$where}");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $sql = "
      SELECT
        r.*,
        ct.CTR_NUMERO as CTR_NUMERO,
        cl.CLI_NOME_RAZAO as CLI_NOME
      FROM tb_contas_receber r
      LEFT JOIN tb_contratos ct ON ct.CTR_ID = r.CRE_CONTRATO_FK
      LEFT JOIN cliente cl ON cl.CLI_ID = r.CRE_CLIENTE_FK
      {$where}
      ORDER BY r.CRE_VENCIMENTO ASC, r.CRE_ID ASC
      LIMIT {$per} OFFSET {$off}
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $pages = (int)ceil($total / $per);
    $jsonOk([
      'rows' => $rows,
      'total' => $total,
      'page' => $page,
      'per_page' => $per,
      'pages' => $pages,
      'from' => $total ? ($off + 1) : 0,
      'to' => min($off + $per, $total)
    ]);
  }

  // ============================================================
  // GET 1
  // ============================================================
  if ($acao === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM tb_contas_receber WHERE CRE_ID = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $jsonOk(['row' => $row]);
  }

  // ============================================================
  // SALVAR
  // ============================================================
  if ($acao === 'salvar') {
    $id = $_POST['CRE_ID'] ?? '';

    $origem = strtoupper(trim($_POST['CRE_ORIGEM'] ?? 'AVULSO'));
    $contrato_fk = $_POST['CRE_CONTRATO_FK'] ?? null;

    // D.1 — recebimento avulso (sem contrato vinculado) só para ADMIN.
    // Edições de registros já existentes seguem permitidas; bloqueio incide em criação avulsa.
    $isEdicao = !empty($id);
    $temContrato = !empty($contrato_fk) && $contrato_fk !== '0';
    if (!$isEdicao && !$temContrato && function_exists('is_admin') && !is_admin()) {
      $jsonErr('Sem permissão para novo recebimento avulso. Use um contrato.');
    }

    $empresa_fk = $asInt($_POST['CRE_EMPRESA_FK'] ?? 0);
    $plano_fk = $asInt($_POST['CRE_PLANO_CONTAS_FK'] ?? 0);
    $centro_fk = $asInt($_POST['CRE_CENTRO_CUSTO_FK'] ?? 0);
    $banco_fk = $asInt($_POST['CRE_BANCO_FK'] ?? 0);

    $competencia = trim($_POST['CRE_COMPETENCIA'] ?? '');
    $vencimento  = trim($_POST['CRE_VENCIMENTO'] ?? '');

    $cliente_fk = $_POST['CRE_CLIENTE_FK'] ?? null;
    $cliente_nome = trim($_POST['CRE_CLIENTE_NOME'] ?? '');
    $cpf_cnpj = trim($_POST['CRE_CPF_CNPJ'] ?? '');

    $valorRaw = trim((string)($_POST['CRE_VALOR'] ?? '0'));
    $valorRaw = str_replace(['R$', ' '], '', $valorRaw);
    if (strpos($valorRaw, ',') !== false) {
      $valorRaw = str_replace('.', '', $valorRaw);
      $valorRaw = str_replace(',', '.', $valorRaw);
    } else {
      $valorRaw = str_replace(',', '.', $valorRaw);
    }
    $valor = (float)$valorRaw;

    $forma = trim($_POST['CRE_FORMA_COBRANCA'] ?? '');
    $documento = trim($_POST['CRE_DOCUMENTO'] ?? '');

    $recebido_em = trim($_POST['CRE_RECEBIDO_EM'] ?? '');
    $status = strtoupper(trim($_POST['CRE_STATUS'] ?? 'ABERTO'));
    $obs = trim($_POST['CRE_OBSERVACAO'] ?? '');

    if ($vencimento === '' || $valor <= 0) $jsonErr('Vencimento e Valor são obrigatórios.');
    if ($empresa_fk <= 0) $jsonErr('Selecione a empresa.');

    $competencia_date = null;
    if ($competencia !== '') {
      $competencia_date = $competencia . '-01';
    }

    if ($contrato_fk === '' || $contrato_fk === '0') $contrato_fk = null;
    if ($cliente_fk === '' || $cliente_fk === '0') $cliente_fk = null;
    if ($plano_fk <= 0) $plano_fk = null;
    if ($centro_fk <= 0) $centro_fk = null;
    if ($banco_fk <= 0) $banco_fk = null;
    if ($recebido_em === '') $recebido_em = null;

    if ($recebido_em) $status = 'RECEBIDO';

    if ($id) {
      $sql = "
        UPDATE tb_contas_receber SET
          CRE_ORIGEM = ?,
          CRE_CONTRATO_FK = ?,
          CRE_EMPRESA_FK = ?,
          CRE_PLANO_CONTAS_FK = ?,
          CRE_CENTRO_CUSTO_FK = ?,
          CRE_BANCO_FK = ?,
          CRE_COMPETENCIA = ?,
          CRE_VENCIMENTO = ?,
          CRE_CLIENTE_FK = ?,
          CRE_CLIENTE_NOME = ?,
          CRE_CPF_CNPJ = ?,
          CRE_VALOR = ?,
          CRE_FORMA_COBRANCA = ?,
          CRE_DOCUMENTO = ?,
          CRE_RECEBIDO_EM = ?,
          CRE_STATUS = ?,
          CRE_OBSERVACAO = ?
        WHERE CRE_ID = ?
      ";
      $st = $pdo->prepare($sql);
      $st->execute([
        $origem,
        $contrato_fk,
        $empresa_fk,
        $plano_fk,
        $centro_fk,
        $banco_fk,
        $competencia_date,
        $vencimento,
        $cliente_fk,
        $cliente_nome,
        $cpf_cnpj,
        $valor,
        $forma,
        $documento,
        $recebido_em,
        $status,
        $obs,
        $id
      ]);
      $jsonOk(['id' => (int)$id]);
    } else {
      $sql = "
        INSERT INTO tb_contas_receber
          (
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
            CRE_RECEBIDO_EM,
            CRE_STATUS,
            CRE_OBSERVACAO
          )
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ";
      $st = $pdo->prepare($sql);
      $st->execute([
        $origem,
        $contrato_fk,
        $empresa_fk,
        $plano_fk,
        $centro_fk,
        $banco_fk,
        $competencia_date,
        $vencimento,
        $cliente_fk,
        $cliente_nome,
        $cpf_cnpj,
        $valor,
        $forma,
        $documento,
        $recebido_em,
        $status,
        $obs
      ]);
      $jsonOk(['id' => (int)$pdo->lastInsertId()]);
    }
  }


  // ============================================================
  // BAIXAR MANUALMENTE
  // ============================================================
  if ($acao === 'baixar_manual') {
    $id = (int)($_POST['id'] ?? 0);
    $banco_fk = $asInt($_POST['CRE_BANCO_FK'] ?? 0);
    $recebido_em = trim((string)($_POST['CRE_RECEBIDO_EM'] ?? ''));
    $obs = trim((string)($_POST['CRE_OBSERVACAO'] ?? ''));

    if ($id <= 0) $jsonErr('Parcela inválida.');
    if ($banco_fk <= 0) $jsonErr('Selecione o banco de recebimento.');
    if ($recebido_em === '') $jsonErr('Informe a data do recebimento.');

    $st = $pdo->prepare("SELECT CRE_ID, CRE_OBSERVACAO, CRE_STATUS FROM tb_contas_receber WHERE CRE_ID = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) $jsonErr('Parcela não encontrada.');
    if (strtoupper((string)$row['CRE_STATUS']) === 'RECEBIDO') {
      $jsonErr('Esta parcela já foi baixada como recebida.');
    }

    $obsAtual = trim((string)($row['CRE_OBSERVACAO'] ?? ''));
    $obsFinal = $obsAtual;
    if ($obs !== '') {
      $obsFinal = $obsAtual !== '' ? ($obsAtual . "
" . $obs) : $obs;
    }

    $sql = "
      UPDATE tb_contas_receber
         SET CRE_BANCO_FK = ?,
             CRE_RECEBIDO_EM = ?,
             CRE_STATUS = 'RECEBIDO',
             CRE_OBSERVACAO = ?
       WHERE CRE_ID = ?
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$banco_fk, $recebido_em, $obsFinal, $id]);

    $jsonOk(['id' => $id]);
  }

  // ============================================================
  // EXCLUIR
  // ============================================================
  if ($acao === 'excluir') {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("DELETE FROM tb_contas_receber WHERE CRE_ID = ?");
    $st->execute([$id]);
    $jsonOk([]);
  }

  $jsonErr('Ação inválida');
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'msg' => 'Erro no banco.', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
