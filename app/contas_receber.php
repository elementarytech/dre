<?php

declare(strict_types=1);

// Página: contas_receber.php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/conciliacao_helpers.php';
require_once __DIR__ . '/config/bb_api.php';

mb_internal_encoding('UTF-8');
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/contas_a_receber.log');

function get_db(): PDO
{
  if (class_exists('conexao') && method_exists('conexao', 'getInstance')) {
    $db = conexao::getInstance();
    if ($db instanceof PDO) return $db;
  }
  foreach (['getPDO', 'pdo'] as $fn) {
    if (function_exists($fn)) {
      $db = $fn();
      if ($db instanceof PDO) return $db;
    }
  }
  foreach (['pdo', 'db', 'conn', 'connection'] as $var) {
    if (isset($GLOBALS[$var]) && $GLOBALS[$var] instanceof PDO) {
      return $GLOBALS[$var];
    }
  }
  throw new RuntimeException('Erro interno: class conexao::getInstance() não retornou PDO. Verifique config/conexao.php');
}

if (!function_exists('db')) {
  function db(): PDO
  {
    return get_db();
  }
}

function coluna_existe(PDO $db, string $tabela, string $coluna): bool
{
  $st = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  $st->execute([$tabela, $coluna]);
  return (bool)$st->fetchColumn();
}

function json_out(array $payload, int $code = 200): void
{
  if (function_exists('ob_get_level')) while (ob_get_level() > 0) @ob_end_clean();
  header_remove();
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function as_str($v): string
{
  return trim((string)($v ?? ''));
}
function as_int($v): int
{
  return (int)($v ?? 0);
}

function as_date_ymd($v): ?string
{
  $v = trim((string)($v ?? ''));
  if ($v === '') return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
  return null;
}

function as_month_to_ymd01($v): ?string
{
  $v = trim((string)($v ?? '')); // YYYY-MM
  if ($v === '') return null;
  if (preg_match('/^\d{4}-\d{2}$/', $v)) return $v . '-01';
  return null;
}

function as_money($v): string
{
  $v = (string)($v ?? '0');
  $v = str_replace(['R$', ' ', "\xc2\xa0", "\u{00a0}"], '', $v);
  if (strpos($v, ',') !== false) {
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
  } else {
    $v = str_replace(',', '.', $v);
  }
  if ($v === '' || !is_numeric($v)) $v = '0';
  return number_format((float)$v, 2, '.', '');
}

function usuario_recebimento(): string
{
  foreach (['user_nome', 'usuarioSession', 'usuario_nome', 'nome', 'usuario'] as $k) {
    if (!empty($_SESSION[$k])) return trim((string)$_SESSION[$k]);
  }
  return 'Sistema';
}

function status_em_aberto_ou_atrasado(?string $vencimento): string
{
  if (!$vencimento) return 'ABERTO';
  return ($vencimento < date('Y-m-d')) ? 'ATRASADO' : 'ABERTO';
}

function status_pago(string $status): bool
{
  $s = strtoupper(trim($status));
  return in_array($s, ['RECEBIDO', 'PAGO'], true);
}


function config_valor(PDO $db, string $chave, string $padrao = 'N'): string
{
  $st = $db->prepare("SELECT CFG_VALOR FROM tb_configuracao WHERE CFG_CHAVE = ? LIMIT 1");
  $st->execute([$chave]);
  $val = $st->fetchColumn();
  if ($val === false || $val === null || $val === '') return strtoupper($padrao);
  return strtoupper(trim((string)$val));
}

$acao = $_REQUEST['acao'] ?? '';
if ($acao !== '') {
  try {
    $db = get_db();

    // =========================
    // COMBOS / AUTOCOMPLETE
    // =========================

    if ($acao === 'combo_forma_cobranca') {
      $st = $db->query("
        SELECT
          FPG_CODIGO_PK AS id,
          FPG_DESCRICAO AS nome
        FROM tb_forma_pagamento
        WHERE COALESCE(FPG_STATUS, 'ATIVO') = 'ATIVO'
        ORDER BY FPG_DESCRICAO
      ");
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'combo_empresas') {
      $st = $db->query("
    SELECT
      EMP_ID AS id,
      COALESCE(NULLIF(EMP_NOME_FANTASIA,''), EMP_RAZAO_SOCIAL) AS nome
    FROM tb_empresa
    WHERE EMP_STATUS = 'ATIVO'
    ORDER BY nome
  ");
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      json_out(['ok' => true, 'rows' => $rows]);
    }



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

      $st = $db->query($sql);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      json_out(['ok' => true, 'rows' => $rows]);
    }

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

      $st = $db->query($sql);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'combo_bancos') {
      $st = $db->query("
        SELECT
          BAN_ID AS id,
          COALESCE(NULLIF(BAN_APELIDO,''), BAN_NOME) AS nome
        FROM tb_banco
        WHERE BAN_STATUS = 'ATIVO'
        ORDER BY nome
      ");
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'combo_contratos') {
      $q = as_str($_GET['q'] ?? '');
      $limit = max(1, min(200, as_int($_GET['limit'] ?? 50)));

      $sql = "SELECT
                c.CTR_ID AS id,
                c.CTR_ID AS numero,
                cl.CLI_NOME_RAZAO AS cliente_nome,
                cl.CLI_ID AS cliente_id
              FROM contratos c
              LEFT JOIN cliente cl ON cl.CLI_ID = c.CTR_CLIENTE_ID
              WHERE 1=1";
      $params = [];

      if ($q !== '') {
        $sql .= " AND (c.CTR_ID LIKE :q1 OR cl.CLI_NOME_RAZAO LIKE :q2)";
        $params[':q1'] = "%{$q}%";
        $params[':q2'] = "%{$q}%";
      }

      $sql .= " ORDER BY c.CTR_ID DESC LIMIT {$limit}";
      $st = $db->prepare($sql);
      $st->execute($params);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      foreach ($rows as &$r) {
        $r['label'] = trim(($r['numero'] ?? '') . ' - ' . ($r['cliente_nome'] ?? ''));
      }
      unset($r);

      json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'autocomplete_cliente') {
      $q = as_str($_GET['q'] ?? '');
      $limit = max(1, min(50, as_int($_GET['limit'] ?? 10)));

      $sql = "SELECT
                CLI_ID AS id,
                CLI_NOME_RAZAO AS nome,
                CLI_DOCUMENTO AS cpf_cnpj
              FROM cliente
              WHERE (CLI_NOME_RAZAO LIKE :q1 OR CLI_DOCUMENTO LIKE :q2)
              ORDER BY CLI_NOME_RAZAO
              LIMIT {$limit}";
      $st = $db->prepare($sql);
      $st->execute([
        ':q1' => "%{$q}%",
        ':q2' => "%{$q}%"
      ]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      json_out(['ok' => true, 'rows' => $rows]);
    }

    if ($acao === 'cliente_doc') {
      $id = as_int($_GET['id'] ?? 0);
      $st = $db->prepare("SELECT CLI_DOCUMENTO FROM cliente WHERE CLI_ID = ? LIMIT 1");
      $st->execute([$id]);
      $doc = (string)($st->fetchColumn() ?: '');
      json_out(['ok' => true, 'documento' => $doc]);
    }

    // =========================
    // CRUD
    // =========================

    if ($acao === 'get') {
      $id = as_int($_GET['id'] ?? 0);
      $st = $db->prepare("SELECT * FROM tb_contas_receber WHERE CRE_ID = ? LIMIT 1");
      $st->execute([$id]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      json_out(['ok' => true, 'row' => $row]);
    }

    if ($acao === 'listar') {
      $q = as_str($_GET['q'] ?? '');
      $status = as_str($_GET['status'] ?? '');
      $origem = as_str($_GET['origem'] ?? '');
      $parcelas = strtolower(as_str($_GET['parcelas'] ?? '')); // '', 'unica', 'multipla'
      $dtIni = as_date_ymd($_GET['dtIni'] ?? '');
      $dtFim = as_date_ymd($_GET['dtFim'] ?? '');
      $empresa = as_str($_GET['empresa'] ?? '0');
      $tipoData = strtolower(as_str($_GET['tipo_data'] ?? 'vencimento')); // vencimento|recebimento|criacao
      $valorMin = as_str($_GET['valor_min'] ?? '');
      $valorMax = as_str($_GET['valor_max'] ?? '');
      $tipoRecebimento = strtoupper(as_str($_GET['tipo_recebimento'] ?? '')); // INTEGRAL|PARCIAL

      $page = max(1, as_int($_GET['page'] ?? 1));
      $per = max(5, min(200, as_int($_GET['per_page'] ?? 50)));
      $off = ($page - 1) * $per;

      $where = " WHERE 1=1 ";
      $params = [];

      // Filtros combinados (busca livre + demais filtros aplicam juntos)
      if ($status !== '') {
        $stUp = strtoupper($status);
        if ($stUp === 'PAGO') {
          $where .= " AND UPPER(r.CRE_STATUS) IN ('PAGO','RECEBIDO') ";
        } elseif ($stUp === 'PARCIAL') {
          // Pagamento parcial: recebeu algo mas não quitou — status ainda aberto/atrasado
          $where .= " AND UPPER(r.CRE_STATUS) NOT IN ('PAGO','RECEBIDO','CANCELADO')
                      AND COALESCE(r.CRE_VALOR_RECEBIDO, 0) > 0 ";
        } elseif ($stUp === 'ATRASADO') {
          // ATRASADO é rótulo derivado: em aberto/programado/pendente com vencimento
          // passado e sem recebimento. O sistema raramente persiste CRE_STATUS='ATRASADO'.
          $where .= " AND (UPPER(COALESCE(r.CRE_STATUS,'')) IN ('ABERTO','ATRASADO','PROGRAMADO','PENDENTE')
                           OR r.CRE_STATUS IS NULL
                           OR TRIM(r.CRE_STATUS) = '')
                      AND r.CRE_VENCIMENTO < CURDATE()
                      AND r.CRE_RECEBIDO_EM IS NULL ";
        } else {
          $where .= " AND r.CRE_STATUS = :st ";
          $params[':st'] = $status;
        }
      }
      if ($origem !== '') {
        $where .= " AND r.CRE_ORIGEM = :ori ";
        $params[':ori'] = $origem;
      }
      if ($empresa !== '' && $empresa !== '0') {
        $where .= " AND r.CRE_EMPRESA_FK = :emp ";
        $params[':emp'] = (int)$empresa;
      }

      // Tipo de recebimento (INTEGRAL / PARCIAL)
      if ($tipoRecebimento === 'INTEGRAL') {
        $where .= " AND UPPER(COALESCE(r.CRE_TIPO_RECEBIMENTO,'')) = 'INTEGRAL' ";
      } elseif ($tipoRecebimento === 'PARCIAL') {
        $where .= " AND (UPPER(COALESCE(r.CRE_TIPO_RECEBIMENTO,'')) = 'PARCIAL' OR (COALESCE(r.CRE_VALOR_RECEBIDO,0) > 0 AND UPPER(COALESCE(r.CRE_STATUS,'')) NOT IN ('PAGO','RECEBIDO','CANCELADO'))) ";
      }

      // Coluna de data conforme escolha do usuário
      $colData = 'r.CRE_VENCIMENTO';
      if ($tipoData === 'recebimento') $colData = 'r.CRE_RECEBIDO_EM';
      elseif ($tipoData === 'criacao') $colData = 'r.CRE_CREATED_AT';

      // Quando o usuário filtra ATRASADO, ignora a data inicial pelo mesmo motivo
      // de contas a pagar: atrasado é por natureza "do passado até hoje".
      $ignoraIni = (strtoupper($status) === 'ATRASADO');

      if ($dtIni && !$ignoraIni) {
        $where .= " AND $colData >= :ini ";
        $params[':ini'] = $dtIni;
      }
      if ($dtFim) {
        $where .= " AND $colData <= :fim ";
        $params[':fim'] = $dtFim;
      }

      // Faixa de valor
      if ($valorMin !== '' && is_numeric($valorMin)) {
        $where .= " AND r.CRE_VALOR >= :vmin ";
        $params[':vmin'] = (float)$valorMin;
      }
      if ($valorMax !== '' && is_numeric($valorMax)) {
        $where .= " AND r.CRE_VALOR <= :vmax ";
        $params[':vmax'] = (float)$valorMax;
      }

      // Filtro por quantidade de parcelas do contrato vinculado.
      if ($parcelas === 'unica') {
        $where .= " AND (
            r.CRE_CONTRATO_FK IS NULL
            OR r.CRE_CONTRATO_FK = 0
            OR (SELECT COUNT(*) FROM contrato_parcelas p WHERE p.CPA_CTR_ID = r.CRE_CONTRATO_FK) = 1
        ) ";
      } elseif ($parcelas === 'multipla') {
        $where .= " AND r.CRE_CONTRATO_FK IS NOT NULL
                    AND r.CRE_CONTRATO_FK > 0
                    AND (SELECT COUNT(*) FROM contrato_parcelas p WHERE p.CPA_CTR_ID = r.CRE_CONTRATO_FK) > 1 ";
      }

      if ($q !== '') {
        // Termo numérico também casa pelo ID da parcela (#ID).
        $idCond = ctype_digit($q) ? " OR r.CRE_ID = :qid" : "";
        $where .= " AND (
                    r.CRE_CLIENTE_NOME LIKE :q1 OR
                    r.CRE_CPF_CNPJ LIKE :q2 OR
                    r.CRE_DOCUMENTO LIKE :q3 OR
                    ct.CTR_ID LIKE :q4 OR
                    cl.CLI_NOME_RAZAO LIKE :q5
                    {$idCond}
                ) ";
        $params[':q1'] = "%{$q}%";
        $params[':q2'] = "%{$q}%";
        $params[':q3'] = "%{$q}%";
        $params[':q4'] = "%{$q}%";
        $params[':q5'] = "%{$q}%";
        if (ctype_digit($q)) $params[':qid'] = (int)$q;
      }

      $st = $db->prepare("SELECT COUNT(*)
                          FROM tb_contas_receber r
                          LEFT JOIN contratos ct ON ct.CTR_ID = r.CRE_CONTRATO_FK
                          LEFT JOIN cliente cl ON cl.CLI_ID = r.CRE_CLIENTE_FK
                          {$where}");
      $st->execute($params);
      $total = (int)$st->fetchColumn();

      // KPIs "em aberto / cancelado / vencido / parcial" mantêm o WHERE atual (vencimento).
      // "total_pago" é calculado à parte, sempre por data de recebimento, para bater com Fluxo de Caixa.
      $stResumo = $db->prepare("SELECT
                                COALESCE(SUM(CASE WHEN UPPER(COALESCE(r.CRE_STATUS,'')) <> 'CANCELADO' THEN r.CRE_VALOR ELSE 0 END), 0) AS total_lancado,
                                COALESCE(SUM(CASE WHEN UPPER(COALESCE(r.CRE_STATUS,'')) IN ('ABERTO','ATRASADO','PROGRAMADO','PENDENTE') THEN GREATEST(0, r.CRE_VALOR - COALESCE(r.CRE_VALOR_RECEBIDO, 0)) ELSE 0 END), 0) AS total_aberto,
                                COALESCE(SUM(CASE WHEN UPPER(COALESCE(r.CRE_STATUS,'')) = 'CANCELADO' THEN r.CRE_VALOR ELSE 0 END), 0) AS total_cancelado,
                                COALESCE(SUM(CASE WHEN UPPER(COALESCE(r.CRE_STATUS,'')) IN ('ABERTO','ATRASADO','PROGRAMADO','PENDENTE') AND r.CRE_VENCIMENTO < CURDATE() THEN GREATEST(0, r.CRE_VALOR - COALESCE(r.CRE_VALOR_RECEBIDO, 0)) ELSE 0 END), 0) AS total_vencido,
                                COALESCE(SUM(CASE WHEN UPPER(COALESCE(r.CRE_STATUS,'')) NOT IN ('PAGO','RECEBIDO','CANCELADO') AND COALESCE(r.CRE_VALOR_RECEBIDO, 0) > 0 THEN r.CRE_VALOR_RECEBIDO ELSE 0 END), 0) AS total_parcial
                              FROM tb_contas_receber r
                              LEFT JOIN contratos ct ON ct.CTR_ID = r.CRE_CONTRATO_FK
                              LEFT JOIN cliente cl ON cl.CLI_ID = r.CRE_CLIENTE_FK
                              {$where}");
      $stResumo->execute($params);
      $resumo = $stResumo->fetch(PDO::FETCH_ASSOC) ?: [
        'total_lancado' => 0,
        'total_aberto' => 0,
        'total_cancelado' => 0,
        'total_vencido' => 0,
        'total_parcial' => 0,
      ];

      // TOTAL PAGO: respeita o WHERE da listagem (mesmo filtro de período/data/empresa/busca/etc).
      // Soma o valor recebido (CRE_VALOR_RECEBIDO) das contas que já tiveram algum recebimento,
      // excluindo canceladas. Assim o card sempre bate com a listagem mostrada — quando o usuário
      // filtra "por data de vencimento", soma o pago das contas com vencimento no período;
      // quando filtra "por data de recebimento", soma o pago das contas recebidas no período.
      $sqlPago = "SELECT COALESCE(SUM(COALESCE(r.CRE_VALOR_RECEBIDO, 0)), 0) AS total_pago
                  FROM tb_contas_receber r
                  LEFT JOIN contratos ct ON ct.CTR_ID = r.CRE_CONTRATO_FK
                  LEFT JOIN cliente cl  ON cl.CLI_ID  = r.CRE_CLIENTE_FK
                  {$where}
                    AND COALESCE(r.CRE_VALOR_RECEBIDO, 0) > 0
                    AND UPPER(COALESCE(r.CRE_STATUS,'')) <> 'CANCELADO' ";
      $stP = $db->prepare($sqlPago);
      $stP->execute($params);
      $resumo['total_pago'] = (float)$stP->fetchColumn();

      $sql = "SELECT
                r.*,
                ct.CTR_ID AS CTR_ID,
                cl.CLI_NOME_RAZAO AS CLI_NOME_RAZAO,
                (SELECT COUNT(*) FROM tb_contas_receber r2 WHERE r2.CRE_CONTRATO_FK = r.CRE_CONTRATO_FK AND r2.CRE_CONTRATO_FK IS NOT NULL AND r2.CRE_CONTRATO_FK > 0) AS TOTAL_PARCELAS_CONTRATO,
                (SELECT BOL_STATUS FROM tb_boleto_bb WHERE BOL_CRE_FK = r.CRE_ID AND BOL_STATUS = 'EMITIDO' LIMIT 1) AS BOL_STATUS_EMITIDO
              FROM tb_contas_receber r
              LEFT JOIN contratos ct ON ct.CTR_ID = r.CRE_CONTRATO_FK
              LEFT JOIN cliente cl ON cl.CLI_ID = r.CRE_CLIENTE_FK
              {$where}
              ORDER BY r.CRE_VENCIMENTO ASC, r.CRE_ID ASC
              LIMIT {$per} OFFSET {$off}";
      $st = $db->prepare($sql);
      $st->execute($params);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      $pages = (int)ceil($total / $per);

      json_out([
        'ok' => true,
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $per,
        'pages' => $pages,
        'from' => $total ? ($off + 1) : 0,
        'to' => min($off + $per, $total),
        'resumo' => $resumo,
      ]);
    }

    if ($acao === 'salvar_aporte') {
      // Aporte de sócio: entrada de capital próprio (sem contrapartida).
      $empresaFk   = as_int($_POST['EMPRESA_FK'] ?? 0);
      $bancoFk     = as_int($_POST['BANCO_FK'] ?? 0);
      $planoFk     = as_int($_POST['PLANO_CONTAS_FK'] ?? 0);
      $centroFk    = as_int($_POST['CENTRO_CUSTO_FK'] ?? 0);
      $socioNome   = as_str($_POST['SOCIO_NOME'] ?? '');
      $socioDoc    = as_str($_POST['SOCIO_DOC'] ?? '');
      $dataEntrada = as_date_ymd($_POST['DATA_ENTRADA'] ?? '');
      $valor       = as_money($_POST['VALOR'] ?? '0');
      $documento   = as_str($_POST['DOCUMENTO'] ?? '');
      $obs         = as_str($_POST['OBSERVACAO'] ?? '');

      if ($empresaFk <= 0)        json_out(['ok' => false, 'msg' => 'Selecione a empresa.'], 422);
      if ($bancoFk   <= 0)        json_out(['ok' => false, 'msg' => 'Selecione o banco onde o valor entrou.'], 422);
      if ($socioNome === '')      json_out(['ok' => false, 'msg' => 'Informe o nome do sócio.'], 422);
      if (!$dataEntrada)          json_out(['ok' => false, 'msg' => 'Informe a data de entrada.'], 422);
      if ((float)$valor <= 0)     json_out(['ok' => false, 'msg' => 'Informe o valor.'], 422);

      $st = $db->prepare("INSERT INTO tb_contas_receber
            (CRE_ORIGEM, CRE_EMPRESA_FK, CRE_PLANO_CONTAS_FK, CRE_CENTRO_CUSTO_FK, CRE_BANCO_FK,
             CRE_COMPETENCIA, CRE_VENCIMENTO, CRE_CLIENTE_NOME, CRE_CPF_CNPJ,
             CRE_VALOR, CRE_VALOR_RECEBIDO, CRE_DOCUMENTO, CRE_RECEBIDO_EM, CRE_RECEBIDO_POR, CRE_RECEBIDO_AT,
             CRE_STATUS, CRE_OBSERVACAO, CRE_CREATED_AT, CRE_UPDATED_AT)
          VALUES ('APORTE_SOCIO',?,?,?,?, ?,?,?,?, ?,?,?,?,?,NOW(), 'RECEBIDO',?, NOW(), NOW())");
      $st->execute([
        $empresaFk,
        ($planoFk  > 0 ? $planoFk  : null),
        ($centroFk > 0 ? $centroFk : null),
        $bancoFk,
        substr($dataEntrada, 0, 7) . '-01',
        $dataEntrada,
        $socioNome,
        $socioDoc,
        $valor,
        $valor,
        $documento,
        $dataEntrada,
        usuario_recebimento(),
        ('Aporte de sócio. ' . $obs),
      ]);
      json_out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }

    if ($acao === 'salvar_emprestimo') {
      // Empréstimo recebido: 1 entrada em CRE (recebida) + N parcelas em CPG (a pagar).
      $empresaFk     = as_int($_POST['EMPRESA_FK'] ?? 0);
      $bancoFk       = as_int($_POST['BANCO_FK'] ?? 0);
      $credorNome    = as_str($_POST['CREDOR_NOME'] ?? '');
      $credorDoc     = as_str($_POST['CREDOR_DOC'] ?? '');
      $dataEntrada   = as_date_ymd($_POST['DATA_ENTRADA'] ?? '');
      $valorTotal    = as_money($_POST['VALOR_TOTAL'] ?? '0');
      $documento     = as_str($_POST['DOCUMENTO'] ?? '');
      $obs           = as_str($_POST['OBSERVACAO'] ?? '');

      $planoReceitaFk = as_int($_POST['PLANO_RECEITA_FK'] ?? 0);

      // Bloco contas a pagar
      $qtdParcelas    = as_int($_POST['QTD_PARCELAS'] ?? 0);
      $primeiroVenc   = as_date_ymd($_POST['PRIMEIRO_VENCIMENTO'] ?? '');
      $valorParcela   = as_money($_POST['VALOR_PARCELA'] ?? '0');
      $planoDespesaFk = as_int($_POST['PLANO_DESPESA_FK'] ?? 0);
      $centroDespesaFk = as_int($_POST['CENTRO_DESPESA_FK'] ?? 0);
      $formaPgto      = as_str($_POST['FORMA_PAGAMENTO'] ?? '');

      if ($empresaFk <= 0)        json_out(['ok' => false, 'msg' => 'Selecione a empresa.'], 422);
      if ($bancoFk   <= 0)        json_out(['ok' => false, 'msg' => 'Selecione o banco onde o valor entrou.'], 422);
      if ($credorNome === '')     json_out(['ok' => false, 'msg' => 'Informe o credor (banco/instituição).'], 422);
      if (!$dataEntrada)          json_out(['ok' => false, 'msg' => 'Informe a data de entrada.'], 422);
      if ((float)$valorTotal <= 0) json_out(['ok' => false, 'msg' => 'Informe o valor total liberado.'], 422);
      if ($qtdParcelas <= 0)      json_out(['ok' => false, 'msg' => 'Informe a quantidade de parcelas.'], 422);
      if (!$primeiroVenc)         json_out(['ok' => false, 'msg' => 'Informe o primeiro vencimento.'], 422);
      if ((float)$valorParcela <= 0) json_out(['ok' => false, 'msg' => 'Informe o valor da parcela.'], 422);

      try {
        $db->beginTransaction();

        // 1) Entrada do capital em tb_contas_receber (já recebido)
        $stCre = $db->prepare("INSERT INTO tb_contas_receber
            (CRE_ORIGEM, CRE_EMPRESA_FK, CRE_PLANO_CONTAS_FK, CRE_BANCO_FK,
             CRE_COMPETENCIA, CRE_VENCIMENTO, CRE_CLIENTE_NOME, CRE_CPF_CNPJ,
             CRE_VALOR, CRE_VALOR_RECEBIDO, CRE_DOCUMENTO, CRE_RECEBIDO_EM, CRE_RECEBIDO_POR, CRE_RECEBIDO_AT,
             CRE_STATUS, CRE_OBSERVACAO, CRE_CREATED_AT, CRE_UPDATED_AT)
          VALUES ('EMPRESTIMO',?,?,?, ?,?,?,?, ?,?,?,?,?,NOW(), 'RECEBIDO',?, NOW(), NOW())");
        $stCre->execute([
          $empresaFk,
          ($planoReceitaFk > 0 ? $planoReceitaFk : null),
          $bancoFk,
          substr($dataEntrada, 0, 7) . '-01',
          $dataEntrada,
          $credorNome,
          $credorDoc,
          $valorTotal,
          $valorTotal,
          $documento,
          $dataEntrada,
          usuario_recebimento(),
          ('Empréstimo recebido de ' . $credorNome . ' em ' . $qtdParcelas . 'x. ' . $obs),
        ]);
        $creId = (int)$db->lastInsertId();

        // 2) N parcelas em tb_contas_pagar
        $stCpg = $db->prepare("INSERT INTO tb_contas_pagar
            (CPG_EMPRESA_FK, CPG_PLANO_CONTAS_FK, CPG_CENTRO_CUSTO_FK,
             CPG_TIPO, CPG_QTD_PARCELAS, CPG_NUM_PARCELA,
             CPG_VENCIMENTO, CPG_VALOR_PARCELA,
             CPG_DESCRICAO, CPG_DOCUMENTO, CPG_FORMA_PAGAMENTO, CPG_RELACIONADO,
             CPG_STATUS, CPG_MODO, CPG_COMPETENCIA, CPG_PRIMEIRO_VENCIMENTO,
             CPG_OBSERVACOES, CPG_CREATED_AT)
          VALUES (?,?,?, 'D',?,?, ?,?, ?,?,?,?, 'ABERTO','APRAZO',?,?, ?, NOW())");

        for ($i = 1; $i <= $qtdParcelas; $i++) {
          $venc = (new DateTimeImmutable($primeiroVenc))
            ->modify('+' . ($i - 1) . ' months')
            ->format('Y-m-d');
          $descricao = sprintf('Empréstimo %s — parcela %d/%d', $credorNome, $i, $qtdParcelas);
          $stCpg->execute([
            $empresaFk,
            ($planoDespesaFk  > 0 ? $planoDespesaFk  : null),
            ($centroDespesaFk > 0 ? $centroDespesaFk : null),
            $qtdParcelas,
            $i,
            $venc,
            $valorParcela,
            $descricao,
            $documento,
            $formaPgto,
            ('CRE#' . $creId),
            substr($venc, 0, 7) . '-01',
            $primeiroVenc,
            ('Parcela ' . $i . '/' . $qtdParcelas . ' do empréstimo. CRE de origem #' . $creId),
          ]);
        }

        $db->commit();
        json_out(['ok' => true, 'cre_id' => $creId, 'parcelas' => $qtdParcelas]);
      } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[salvar_emprestimo] ' . $e->getMessage());
        json_out(['ok' => false, 'msg' => 'Erro ao gravar empréstimo.', 'detail' => $e->getMessage()], 500);
      }
    }

    if ($acao === 'salvar') {
      $id = as_int($_POST['CRE_ID'] ?? 0);

      $origem = strtoupper(as_str($_POST['CRE_ORIGEM'] ?? 'AVULSO'));
      $contratoId = as_int($_POST['CRE_CONTRATO_FK'] ?? 0);
      if ($origem !== 'CONTRATO') $contratoId = 0;

      $empresaFk = as_int($_POST['CRE_EMPRESA_FK'] ?? 0);
      $planoFk = as_int($_POST['CRE_PLANO_CONTAS_FK'] ?? 0);
      $centroFk = as_int($_POST['CRE_CENTRO_CUSTO_FK'] ?? 0);
      $bancoFk = as_int($_POST['CRE_BANCO_FK'] ?? 0);

      $competencia = as_month_to_ymd01($_POST['CRE_COMPETENCIA'] ?? '');
      $vencimento = as_date_ymd($_POST['CRE_VENCIMENTO'] ?? '');

      $clienteId = as_int($_POST['CRE_CLIENTE_FK'] ?? 0);
      $clienteNome = as_str($_POST['CRE_CLIENTE_NOME'] ?? '');
      $cpfCnpj = as_str($_POST['CRE_CPF_CNPJ'] ?? '');

      $valor = as_money($_POST['CRE_VALOR'] ?? '0');
      $forma = as_str($_POST['CRE_FORMA_COBRANCA'] ?? '');
      $documento = as_str($_POST['CRE_DOCUMENTO'] ?? '');

      $recebidoEm = as_date_ymd($_POST['CRE_RECEBIDO_EM'] ?? '');
      $status = strtoupper(as_str($_POST['CRE_STATUS'] ?? 'ABERTO'));
      $obs = as_str($_POST['CRE_OBSERVACAO'] ?? '');

      if (!$vencimento) json_out(['ok' => false, 'msg' => 'Informe o vencimento.'], 422);
      if ((float)$valor <= 0) json_out(['ok' => false, 'msg' => 'Informe o valor.'], 422);
      if ($clienteNome === '' && $clienteId <= 0) json_out(['ok' => false, 'msg' => 'Informe o cliente.'], 422);
      if ($empresaFk <= 0) json_out(['ok' => false, 'msg' => 'Selecione a empresa.'], 422);

      if ($recebidoEm) $status = 'RECEBIDO';

      if ($id > 0) {
        $sql = "UPDATE tb_contas_receber SET
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
                  CRE_OBSERVACAO = ?,
                  CRE_UPDATED_AT = NOW()
                WHERE CRE_ID = ?";
        $st = $db->prepare($sql);
        $st->execute([
          $origem,
          ($contratoId > 0 ? $contratoId : null),
          ($empresaFk > 0 ? $empresaFk : null),
          ($planoFk > 0 ? $planoFk : null),
          ($centroFk > 0 ? $centroFk : null),
          ($bancoFk > 0 ? $bancoFk : null),
          $competencia,
          $vencimento,
          ($clienteId > 0 ? $clienteId : null),
          $clienteNome,
          $cpfCnpj,
          $valor,
          $forma,
          $documento,
          $recebidoEm,
          $status,
          $obs,
          $id
        ]);
        json_out(['ok' => true, 'id' => $id]);
      } else {
        $sql = "INSERT INTO tb_contas_receber
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
                    CRE_OBSERVACAO,
                    CRE_CREATED_AT,
                    CRE_UPDATED_AT
                  )
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW(), NOW())";
        $st = $db->prepare($sql);
        $st->execute([
          $origem,
          ($contratoId > 0 ? $contratoId : null),
          ($empresaFk > 0 ? $empresaFk : null),
          ($planoFk > 0 ? $planoFk : null),
          ($centroFk > 0 ? $centroFk : null),
          ($bancoFk > 0 ? $bancoFk : null),
          $competencia,
          $vencimento,
          ($clienteId > 0 ? $clienteId : null),
          $clienteNome,
          $cpfCnpj,
          $valor,
          $forma,
          $documento,
          $recebidoEm,
          $status,
          $obs
        ]);
        json_out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
      }
    }

    if ($acao === 'excluir') {
      $id = as_int($_POST['id'] ?? $_POST['CRE_ID'] ?? 0);
      if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

      $st = $db->prepare("SELECT CRE_STATUS, CRE_BANCO_FK, CRE_RECEBIDO_EM, CRE_VALOR_RECEBIDO, CRE_VALOR
                            FROM tb_contas_receber WHERE CRE_ID = ? LIMIT 1");
      $st->execute([$id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);

      $permitirExcluirPagas = config_valor($db, 'permitir_excluir_parcelas_pagas', 'N') === 'S';

      if ($row && status_pago((string)($row['CRE_STATUS'] ?? '')) && !$permitirExcluirPagas) {
        json_out([
          'ok' => false,
          'msg' => 'Parcela já paga. A exclusão de parcelas pagas está desativada na configuração do sistema.'
        ], 422);
      }

      $st = $db->prepare("DELETE FROM tb_contas_receber WHERE CRE_ID = ?");
      $st->execute([$id]);

      // Reverte saldo bancário se o título estava recebido com banco associado
      $reversao = ['ok' => false];
      if ($row && status_pago((string)($row['CRE_STATUS'] ?? ''))) {
        $bancoFk = (int)($row['CRE_BANCO_FK'] ?? 0);
        $valor = (float)($row['CRE_VALOR_RECEBIDO'] ?? 0);
        if ($valor <= 0) $valor = (float)($row['CRE_VALOR'] ?? 0);
        $dataRec = $row['CRE_RECEBIDO_EM'] ? substr((string)$row['CRE_RECEBIDO_EM'], 0, 10) : null;

        if ($bancoFk > 0 && $valor > 0) {
          $reversao = reverter_saldo_banco($db, $bancoFk, $valor, 'ENTRADA', $dataRec);
        }
      }

      json_out(['ok' => true, 'reversao_saldo' => $reversao]);
    }

    if ($acao === 'baixar_manual') {
      $id = as_int($_POST['CRE_ID'] ?? $_POST['id'] ?? 0);
      $bancoFk = as_int($_POST['CRE_BANCO_FK'] ?? 0);
      $recebidoEm = as_date_ymd($_POST['CRE_RECEBIDO_EM'] ?? '');
      $obs = as_str($_POST['CRE_OBSERVACAO'] ?? '');
      $formaRecebimento = as_str($_POST['CRE_FORMA_COBRANCA'] ?? '');
      $tipoRecebimento = strtoupper(as_str($_POST['CRE_TIPO_RECEBIMENTO'] ?? 'INTEGRAL'));
      $valorRecebido = as_money($_POST['CRE_VALOR_RECEBIDO'] ?? '0');

      if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);
      if ($bancoFk <= 0) json_out(['ok' => false, 'msg' => 'Selecione o banco de recebimento.'], 422);
      if (!$recebidoEm) json_out(['ok' => false, 'msg' => 'Informe a data de recebimento.'], 422);
      if ($tipoRecebimento !== 'INTEGRAL' && $tipoRecebimento !== 'PARCIAL') $tipoRecebimento = 'INTEGRAL';

      $st = $db->prepare("SELECT CRE_VALOR, CRE_VENCIMENTO, CRE_STATUS, CRE_OBSERVACAO,
                                COALESCE(CRE_VALOR_RECEBIDO, 0) AS CRE_VALOR_RECEBIDO,
                                COALESCE(CRE_TIPO_RECEBIMENTO, '') AS CRE_TIPO_RECEBIMENTO
                             FROM tb_contas_receber
                             WHERE CRE_ID = ?
                             LIMIT 1");
      $st->execute([$id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) json_out(['ok' => false, 'msg' => 'Registro não encontrado.'], 404);
      if (status_pago((string)($row['CRE_STATUS'] ?? ''))) {
        json_out(['ok' => false, 'msg' => 'Parcela já paga. Essa parcela já foi paga e não pode ser alterada. Contate o administrador.'], 422);
      }

      $valorOriginal = round((float)($row['CRE_VALOR'] ?? 0), 2);
      $valorJaRecebido = round((float)($row['CRE_VALOR_RECEBIDO'] ?? 0), 2);
      $saldoAnterior = round(max(0, $valorOriginal - $valorJaRecebido), 2);
      if ((float)$valorRecebido <= 0) $valorRecebido = number_format($saldoAnterior > 0 ? $saldoAnterior : $valorOriginal, 2, '.', '');
      $valorRecebidoAtual = round((float)$valorRecebido, 2);
      if ($valorRecebidoAtual > $saldoAnterior + 0.01 && $saldoAnterior > 0) {
        json_out(['ok' => false, 'msg' => 'O valor informado é maior que o saldo restante da parcela.'], 422);
      }
      $novoValorRecebidoTotal = round($valorJaRecebido + $valorRecebidoAtual, 2);
      $saldoRestante = round(max(0, $valorOriginal - $novoValorRecebidoTotal), 2);
      // Se o usuário marcou INTEGRAL explicitamente (mesmo com desconto/juros fazendo o valor não fechar),
      // trata como quitado. Caso contrário, só considera PAGO quando o saldo realmente zera.
      $usuarioMarcouIntegral = ($tipoRecebimento === 'INTEGRAL');
      $quitado = ($saldoRestante <= 0.00001) || $usuarioMarcouIntegral;
      $statusFinal = $quitado ? 'PAGO' : status_em_aberto_ou_atrasado($row['CRE_VENCIMENTO'] ?? null);
      $tipoRecebimentoFinal = $quitado ? 'INTEGRAL' : 'PARCIAL';

      if ($formaRecebimento !== '' && ctype_digit($formaRecebimento)) {
        $stForma = $db->prepare("SELECT FPG_DESCRICAO FROM tb_forma_pagamento WHERE FPG_CODIGO_PK = ? LIMIT 1");
        $stForma->execute([(int)$formaRecebimento]);
        $formaDescricao = trim((string)($stForma->fetchColumn() ?: ''));
        if ($formaDescricao !== '') $formaRecebimento = $formaDescricao;
      }

      $obsAtual = trim((string)($row['CRE_OBSERVACAO'] ?? ''));
      $linhasObs = [];
      if ($formaRecebimento !== '') $linhasObs[] = 'Forma de recebimento: ' . $formaRecebimento;
      if ($tipoRecebimentoFinal !== '') $linhasObs[] = 'Tipo: ' . $tipoRecebimentoFinal;
      if ($valorRecebidoAtual > 0) $linhasObs[] = 'Valor recebido nesta baixa: R$ ' . number_format($valorRecebidoAtual, 2, ',', '.');
      if ($novoValorRecebidoTotal > 0) $linhasObs[] = 'Total recebido acumulado: R$ ' . number_format($novoValorRecebidoTotal, 2, ',', '.');
      if ($obs !== '') $linhasObs[] = $obs;
      $obsNova = trim(implode("
", $linhasObs));
      $obsFinal = trim($obsAtual . ($obsAtual !== '' && $obsNova !== '' ? "
" : '') . $obsNova);

      $sets = [
        'CRE_BANCO_FK = ?',
        'CRE_RECEBIDO_EM = ?',
        'CRE_STATUS = ?',
        'CRE_OBSERVACAO = ?',
        'CRE_UPDATED_AT = NOW()'
      ];
      $params = [$bancoFk, $recebidoEm, $statusFinal, $obsFinal];

      if (coluna_existe($db, 'tb_contas_receber', 'CRE_VALOR_RECEBIDO')) {
        $sets[] = 'CRE_VALOR_RECEBIDO = ?';
        $params[] = $novoValorRecebidoTotal;
      }
      if (coluna_existe($db, 'tb_contas_receber', 'CRE_TIPO_RECEBIMENTO')) {
        $sets[] = 'CRE_TIPO_RECEBIMENTO = ?';
        $params[] = $tipoRecebimentoFinal;
      }
      if (coluna_existe($db, 'tb_contas_receber', 'CRE_RECEBIDO_POR')) {
        $sets[] = 'CRE_RECEBIDO_POR = ?';
        $params[] = usuario_recebimento();
      }
      if (coluna_existe($db, 'tb_contas_receber', 'CRE_RECEBIDO_AT')) {
        $sets[] = 'CRE_RECEBIDO_AT = NOW()';
      }
      if ($formaRecebimento !== '' && coluna_existe($db, 'tb_contas_receber', 'CRE_FORMA_COBRANCA')) {
        $sets[] = 'CRE_FORMA_COBRANCA = ?';
        $params[] = $formaRecebimento;
      }

      $params[] = $id;
      $sql = 'UPDATE tb_contas_receber SET ' . implode(', ', $sets) . ' WHERE CRE_ID = ?';
      $st = $db->prepare($sql);
      $st->execute($params);

      // Saldo bancário é calculado dinamicamente por saldoErpConta() a partir
      // de tb_contas_receber. Marcar como RECEBIDO/PAGO já basta.

      json_out(['ok' => true]);
    }

    if ($acao === 'reabrir_conta') {
        $id     = as_int($_POST['id'] ?? $_REQUEST['id'] ?? 0);
        $senha  = (string)($_POST['senha'] ?? '');
        $motivo = trim((string)($_POST['motivo'] ?? ''));

        if ($id <= 0)      json_out(['ok' => false, 'msg' => 'ID inválido.'], 400);
        if ($senha === '') json_out(['ok' => false, 'msg' => 'Informe a senha de um usuário ADMIN.'], 400);

        // Valida senha contra qualquer ADMIN ativo
        $stU = $db->prepare("SELECT USU_ID, USU_NOME, USU_SENHA_HASH
                              FROM usuarios
                              WHERE USU_PERFIL = 'ADMIN' AND USU_STATUS = 'ATIVO'");
        $stU->execute();
        $admins = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $adminValido = null;
        foreach ($admins as $u) {
            if (password_verify($senha, (string)($u['USU_SENHA_HASH'] ?? ''))) {
                $adminValido = $u;
                break;
            }
        }
        if (!$adminValido) {
            json_out(['ok' => false, 'msg' => 'Senha inválida. Nenhum usuário ADMIN autenticado.'], 401);
        }

        $stC = $db->prepare("SELECT CRE_ID, CRE_STATUS, CRE_BANCO_FK,
                                    CRE_RECEBIDO_EM, CRE_VALOR_RECEBIDO, CRE_VALOR,
                                    CRE_OFX_MOVIMENTO_FK, CRE_OBSERVACAO
                             FROM tb_contas_receber WHERE CRE_ID = ? LIMIT 1");
        $stC->execute([$id]);
        $cr = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$cr) json_out(['ok' => false, 'msg' => 'Conta não encontrada.'], 404);

        $statusAtual = strtoupper((string)$cr['CRE_STATUS']);
        if (!in_array($statusAtual, ['RECEBIDO', 'PAGO'], true)) {
            json_out(['ok' => false, 'msg' => 'A conta não está recebida — nada a reabrir.'], 422);
        }

        $usuarioNome = (string)($_SESSION['user_nome'] ?? $_SESSION['usuarioSession'] ?? 'Sistema');
        $adminNome   = (string)$adminValido['USU_NOME'];
        $obsReabertura = '[REABERTO em ' . date('d/m/Y H:i') . ' por ' . $usuarioNome
            . ' — autorizado por ' . $adminNome
            . ($motivo !== '' ? ' | Motivo: ' . $motivo : '') . ']';

        $db->beginTransaction();
        try {
            // 1. Captura movimentos OFX afetados (via vínculos ativos da tabela nova)
            $stVin = $db->prepare("SELECT VIN_CODIGO_PK, VIN_OFX_MOVIMENTO_FK
                                    FROM tb_conciliacao_vinculo
                                    WHERE VIN_LANCAMENTO_TIPO = 'CONTA_RECEBER'
                                      AND VIN_LANCAMENTO_FK = ? AND VIN_STATUS = 'ATIVO'");
            $stVin->execute([$id]);
            $vinculos = $stVin->fetchAll(PDO::FETCH_ASSOC);

            // 2. Cancela vínculos OFX ativos (tabela nova)
            $stCancel = $db->prepare("UPDATE tb_conciliacao_vinculo
                                       SET VIN_STATUS = 'CANCELADO',
                                           VIN_CANCELADO_EM = NOW(),
                                           VIN_CANCELADO_POR = ?
                                       WHERE VIN_CODIGO_PK = ?");
            foreach ($vinculos as $v) {
                $stCancel->execute([$adminNome . ' (reabertura conta #' . $id . ')', (int)$v['VIN_CODIGO_PK']]);
            }

            // 3. Captura vínculo legado (1:1) para recalcular status do movimento depois
            $movLegado = (int)($cr['CRE_OFX_MOVIMENTO_FK'] ?? 0);

            // 4. Reabre a conta: limpa pagamento, volta status pra ABERTO, registra observação
            $sets = [
                'CRE_STATUS = ?',
                'CRE_RECEBIDO_EM = NULL',
                'CRE_VALOR_RECEBIDO = NULL',
                'CRE_BANCO_FK = NULL',
                'CRE_OFX_MOVIMENTO_FK = NULL',
                "CRE_OBSERVACAO = CONCAT(IFNULL(CRE_OBSERVACAO, ''),
                                        CASE WHEN IFNULL(CRE_OBSERVACAO,'') = '' THEN '' ELSE CHAR(10) END,
                                        ?)",
                'CRE_UPDATED_AT = NOW()'
            ];
            $params = ['ABERTO', $obsReabertura];

            if (coluna_existe($db, 'tb_contas_receber', 'CRE_TIPO_RECEBIMENTO')) {
                $sets[] = 'CRE_TIPO_RECEBIMENTO = NULL';
            }
            if (coluna_existe($db, 'tb_contas_receber', 'CRE_RECEBIDO_POR')) {
                $sets[] = 'CRE_RECEBIDO_POR = NULL';
            }
            if (coluna_existe($db, 'tb_contas_receber', 'CRE_RECEBIDO_AT')) {
                $sets[] = 'CRE_RECEBIDO_AT = NULL';
            }

            $params[] = $id;
            $sqlUpd = 'UPDATE tb_contas_receber SET ' . implode(', ', $sets) . ' WHERE CRE_ID = ?';
            $stUpd = $db->prepare($sqlUpd);
            $stUpd->execute($params);

            // 5. Recalcula status dos movimentos OFX afetados (vínculos novos + legado)
            $movsParaRecalcular = array_unique(array_filter(array_map(
                fn($v) => (int)$v['VIN_OFX_MOVIMENTO_FK'], $vinculos
            )));
            if ($movLegado > 0) $movsParaRecalcular[] = $movLegado;
            foreach (array_unique($movsParaRecalcular) as $movFk) {
                if ($movFk > 0 && function_exists('recalcularStatusMovimento')) {
                    recalcularStatusMovimento($db, $movFk);
                }
            }

            $db->commit();

            json_out([
                'ok' => true,
                'msg' => 'Conta reaberta com sucesso. ' . count($vinculos) . ' vínculo(s) OFX cancelado(s).',
                'autorizado_por' => $adminNome,
                'vinculos_cancelados' => count($vinculos),
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            json_out(['ok' => false, 'msg' => 'Falha na reabertura: ' . $e->getMessage()], 500);
        }
    }

    // =========================================================
    // BOLETO BANCO DO BRASIL
    // =========================================================

    if ($acao === 'consultar_boleto_bb') {
      $creId = as_int($_REQUEST['id'] ?? 0);
      if ($creId <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

      // Verifica se tabela existe
      $tbExiste = $db->query("SHOW TABLES LIKE 'tb_boleto_bb'")->fetchColumn();
      if (!$tbExiste) {
        json_out(['ok' => false, 'msg' => 'Execute a migration 2026_05_26_boleto_bb.sql primeiro.'], 500);
      }

      $st = $db->prepare(
        "SELECT b.BOL_ID, b.BOL_NUMERO, b.BOL_NOSSO_NUMERO, b.BOL_LINHA_DIGITAVEL,
                b.BOL_CODIGO_BARRA, b.BOL_QR_CODE_PIX, b.BOL_URL_IMAGE_QR,
                b.BOL_STATUS, b.BOL_VALOR, b.BOL_VENCIMENTO, b.BOL_PAGO_EM, b.BOL_VALOR_PAGO,
                b.BOL_CREATED_AT,
                c.CRE_DOCUMENTO, c.CRE_CPF_CNPJ, c.CRE_CLIENTE_NOME,
                e.EMP_RAZAO_SOCIAL, e.EMP_CNPJ,
                e.EMP_LOGRADOURO, e.EMP_NUMERO AS EMP_NUM, e.EMP_BAIRRO, e.EMP_CIDADE, e.EMP_UF, e.EMP_CEP,
                COALESCE(ag.BAN_CEDENTE_NOME, e.EMP_RAZAO_SOCIAL)  AS BAN_CEDENTE_NOME,
                COALESCE(ag.BAN_INSTRUCOES, '')                     AS BAN_INSTRUCOES,
                COALESCE(ag.BAN_AGENCIA,
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(b.BOL_PAYLOAD_RESPONSE,'$.beneficiario.agencia')),   'null'))
                    AS BAN_AGENCIA,
                COALESCE(ag.BAN_AGENCIA_DV,
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(b.BOL_PAYLOAD_RESPONSE,'$.beneficiario.digitoVerificadorAgencia')), 'null'))
                    AS BAN_AGENCIA_DV,
                COALESCE(ag.BAN_CONTA,
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(b.BOL_PAYLOAD_RESPONSE,'$.beneficiario.contaCorrente')), 'null'))
                    AS BAN_CONTA,
                COALESCE(ag.BAN_CONTA_DV,
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(b.BOL_PAYLOAD_RESPONSE,'$.beneficiario.digitoVerificadorContaCorrente')), 'null'))
                    AS BAN_CONTA_DV
           FROM tb_boleto_bb b
           JOIN tb_contas_receber c ON c.CRE_ID = b.BOL_CRE_FK
           JOIN tb_empresa e        ON e.EMP_ID = b.BOL_EMPRESA_FK
           LEFT JOIN tb_banco ag    ON ag.BAN_ID = COALESCE(
               NULLIF(e.EMP_BB_BANCO_FK, 0),
               NULLIF(NULLIF(e.EMP_BANCO_PADRAO_BOLETO, ''), '0')
           )
          WHERE b.BOL_CRE_FK = ?
          ORDER BY b.BOL_ID DESC
          LIMIT 1"
      );
      $st->execute([$creId]);
      $boleto = $st->fetch(PDO::FETCH_ASSOC);

      if (!$boleto) {
        json_out(['ok' => false, 'msg' => 'Nenhum boleto gerado para este lançamento.', 'exists' => false]);
      }
      json_out(['ok' => true, 'exists' => true, 'boleto' => $boleto]);
    }

    if ($acao === 'gerar_boleto_bb') {
      $creId = as_int($_POST['id'] ?? $_REQUEST['id'] ?? 0);
      if ($creId <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

      BancoDobrasilApi::loadConfig($db);

      // Verifica se tabela existe
      $tbExiste = $db->query("SHOW TABLES LIKE 'tb_boleto_bb'")->fetchColumn();
      if (!$tbExiste) {
        json_out(['ok' => false, 'msg' => 'Execute a migration 2026_05_26_boleto_bb.sql primeiro.'], 500);
      }

      // Busca a conta
      $st = $db->prepare(
        "SELECT c.*, e.EMP_CNPJ, e.EMP_RAZAO_SOCIAL,
                e.EMP_BB_CONVENIO, e.EMP_BB_CARTEIRA, e.EMP_BB_VARIACAO_CARTEIRA,
                e.EMP_BB_BANCO_FK, e.EMP_BANCO_PADRAO_BOLETO,
                e.EMP_LOGRADOURO, e.EMP_NUMERO AS EMP_NUM, e.EMP_BAIRRO, e.EMP_CIDADE, e.EMP_UF, e.EMP_CEP
           FROM tb_contas_receber c
           JOIN tb_empresa e ON e.EMP_ID = c.CRE_EMPRESA_FK
          WHERE c.CRE_ID = ?
          LIMIT 1"
      );
      $st->execute([$creId]);
      $cre = $st->fetch(PDO::FETCH_ASSOC);
      if (!$cre) json_out(['ok' => false, 'msg' => 'Lançamento não encontrado.'], 404);

      $statusCre = strtoupper(trim((string)($cre['CRE_STATUS'] ?? '')));
      if (in_array($statusCre, ['PAGO', 'RECEBIDO'], true)) {
        json_out(['ok' => false, 'msg' => 'Este lançamento já foi recebido. Não é possível gerar boleto.'], 422);
      }

      $convenio  = trim((string)($cre['EMP_BB_CONVENIO'] ?? ''));
      if ($convenio === '') $convenio = BancoDobrasilApi::getConvenio();
      $carteira  = (int)($cre['EMP_BB_CARTEIRA'] ?? 0) ?: BancoDobrasilApi::getCarteira();
      $variacao  = (int)($cre['EMP_BB_VARIACAO_CARTEIRA'] ?? 0) ?: BancoDobrasilApi::getVariacao();

      if ($convenio === '') {
        json_out(['ok' => false, 'msg' => 'Configure o Número do Convênio BB nos Parâmetros do Sistema ou na empresa.'], 422);
      }

      // Nosso número: CRE_ID zero-padded 10 dígitos
      $nossoNumero      = str_pad((string)$creId, 10, '0', STR_PAD_LEFT);
      // Formato BB: "000" + convenio(7) + nossoNumero(10) = 20 chars (string, zeros à esquerda obrigatórios)
      $numeroTituloCliente = '000' . str_pad($convenio, 7, '0', STR_PAD_LEFT) . $nossoNumero;

      $cpfCnpj = BancoDobrasilApi::somenteDigitos((string)($cre['CRE_CPF_CNPJ'] ?? ''));
      if ($cpfCnpj === '') {
        json_out(['ok' => false, 'msg' => 'CPF/CNPJ do pagador não informado no lançamento.'], 422);
      }

      $vencimento = trim((string)($cre['CRE_VENCIMENTO'] ?? ''));
      if (!$vencimento) {
        json_out(['ok' => false, 'msg' => 'Vencimento não informado.'], 422);
      }

      $valor = round((float)($cre['CRE_VALOR'] ?? 0), 2);
      if ($valor <= 0) {
        json_out(['ok' => false, 'msg' => 'Valor do lançamento inválido.'], 422);
      }

      $empCnpj = BancoDobrasilApi::somenteDigitos((string)($cre['EMP_CNPJ'] ?? ''));

      $payload = [
        'numeroConvenio'                   => (int)$convenio,
        'numeroCarteira'                   => $carteira,
        'numeroVariacaoCarteira'           => $variacao,
        'codigoModalidade'                 => 1,
        'dataEmissao'                      => BancoDobrasilApi::fmtData(date('Y-m-d')),
        'dataVencimento'                   => BancoDobrasilApi::fmtData($vencimento),
        'valorOriginal'                    => $valor,
        'valorAbatimento'                  => 0,
        'quantidadeDiasProtesto'           => 0,
        'indicadorNegativacao'             => 'N',
        'codigoAceite'                     => 'A',
        'codigoTipoTitulo'                 => '02',
        'indicadorPermissaoRecebimentoParcial' => 'N',
        'numeroTituloCliente'              => $numeroTituloCliente,
        'numeroTituloBeneficiario'         => (string)$creId,
        'campoUtilizacaoEmpresa'           => 'CRE' . $nossoNumero,
        'desconto'                         => ['tipo' => 0],
        'jurosMora'                        => ['tipo' => 0],
        'multa'                            => ['tipo' => 0],
        'pix'                              => ['indicadorAgendamento' => 'N'],
        'pagador' => [
          'tipoInscricao'  => BancoDobrasilApi::tipoInscricao($cpfCnpj),
          'numeroInscricao'=> (int)$cpfCnpj,
          'nome'           => substr(trim((string)($cre['CRE_CLIENTE_NOME'] ?? 'Pagador')), 0, 60),
          'endereco'       => substr(trim(((string)($cre['EMP_LOGRADOURO'] ?? '')) . ' ' . ((string)($cre['EMP_NUM'] ?? ''))), 0, 60) ?: '-',
          'cep'            => (int)BancoDobrasilApi::somenteDigitos((string)($cre['EMP_CEP'] ?? '70000000')),
          'cidade'         => substr(trim((string)($cre['EMP_CIDADE'] ?? 'Cidade')), 0, 20) ?: 'Cidade',
          'bairro'         => substr(trim((string)($cre['EMP_BAIRRO'] ?? 'Bairro')), 0, 20) ?: 'Bairro',
          'uf'             => strtoupper(substr(trim((string)($cre['EMP_UF'] ?? 'MG')), 0, 2)) ?: 'MG',
          'telefone'       => '',
        ],
        'mensagem' => [
          'linha1' => 'Ref: ' . (trim((string)($cre['CRE_DOCUMENTO'] ?? '')) ?: "CRE#$creId"),
          'linha2' => 'Cliente: ' . substr(trim((string)($cre['CRE_CLIENTE_NOME'] ?? '')), 0, 40),
          'linha3' => '',
          'linha4' => '',
          'linha5' => '',
        ],
      ];

      // Verifica se já existe um boleto EMITIDO para esta conta
      $stExiste = $db->prepare("SELECT BOL_ID FROM tb_boleto_bb WHERE BOL_CRE_FK = ? AND BOL_STATUS = 'EMITIDO' LIMIT 1");
      $stExiste->execute([$creId]);
      if ($stExiste->fetchColumn()) {
        json_out(['ok' => false, 'msg' => 'Já existe um boleto emitido para este lançamento. Consulte-o pelo ícone de boleto.'], 409);
      }

      try {
        $resposta = BancoDobrasilApi::criarBoleto($payload);
      } catch (Throwable $e) {
        json_out(['ok' => false, 'msg' => 'Erro na API do Banco do Brasil: ' . $e->getMessage()], 502);
      }

      // Persiste o boleto gerado
      $empFk  = (int)($cre['CRE_EMPRESA_FK'] ?? 0);
      $numero = trim((string)($resposta['numero'] ?? $resposta['numeroBoleto'] ?? ''));
      $linha  = trim((string)($resposta['linhaDigitavel'] ?? $resposta['linha_digitavel'] ?? ''));
      $barra  = trim((string)($resposta['codigoBarraNumerico'] ?? $resposta['codigoBarra'] ?? ''));
      // BB API retorna o payload PIX no campo emv (qrCode.url pode vir vazio)
      $pixStr = trim((string)($resposta['qrCode']['emv'] ?? $resposta['qrCode']['url'] ?? $resposta['pix']['payload'] ?? $resposta['pixCopiaECola'] ?? ''));
      $pixImg = trim((string)($resposta['qrCode']['image'] ?? $resposta['qrCode']['urlImagemQrCode'] ?? ''));

      $db->prepare(
        "INSERT INTO tb_boleto_bb
           (BOL_CRE_FK, BOL_EMPRESA_FK, BOL_NUMERO, BOL_NOSSO_NUMERO,
            BOL_LINHA_DIGITAVEL, BOL_CODIGO_BARRA, BOL_QR_CODE_PIX, BOL_URL_IMAGE_QR,
            BOL_STATUS, BOL_VALOR, BOL_VENCIMENTO,
            BOL_PAYLOAD_REQUEST, BOL_PAYLOAD_RESPONSE)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
      )->execute([
        $creId, $empFk, $numero, $nossoNumero,
        $linha, $barra, $pixStr, $pixImg,
        'EMITIDO', $valor, $vencimento,
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        json_encode($resposta, JSON_UNESCAPED_UNICODE),
      ]);

      json_out([
        'ok'     => true,
        'msg'    => 'Boleto gerado com sucesso!',
        'boleto' => [
          'BOL_NUMERO'          => $numero,
          'BOL_NOSSO_NUMERO'    => $nossoNumero,
          'BOL_LINHA_DIGITAVEL' => $linha,
          'BOL_CODIGO_BARRA'    => $barra,
          'BOL_QR_CODE_PIX'     => $pixStr,
          'BOL_URL_IMAGE_QR'    => $pixImg,
          'BOL_STATUS'          => 'EMITIDO',
          'BOL_VALOR'           => $valor,
          'BOL_VENCIMENTO'      => $vencimento,
        ],
      ]);
    }

    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
  } catch (Throwable $e) {
    json_out(['ok' => false, 'msg' => 'Erro no banco.', 'detail' => $e->getMessage()], 500);
  }
}


$permiteExcluirParcelasPagasFront = 'N';
try {
  $permiteExcluirParcelasPagasFront = config_valor(get_db(), 'permitir_excluir_parcelas_pagas', 'N');
} catch (Throwable $e) {
  $permiteExcluirParcelasPagasFront = 'N';
}

?>
<!doctype html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Contas a Receber</title>
  <?php include __DIR__ . '/includes/head.php'; ?>
  <style>
    :root {
      --bs-primary: #2563eb;
      --bs-primary-rgb: 37, 99, 235;
      --radius: .875rem;
      --shadow-sm: 0 1px 3px rgba(15,23,42,.06);
      --shadow-md: 0 4px 16px rgba(15,23,42,.07);
      --border: #e5e7eb;
    }

    body {
      font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif;
      background: #f4f6f9;
    }

    .page-title {
      font-size: 1.35rem;
      font-weight: 700;
      color: #0f172a;
      margin: 0;
      letter-spacing: -.01em;
    }

    /* ---- KPI Cards ---- */
    .kpi-row { gap: .75rem; }

    .kpi-card {
      background: #fff;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow-sm);
      padding: .85rem 1rem;
      display: flex;
      align-items: center;
      gap: .75rem;
      transition: box-shadow .2s, transform .2s;
    }
    .kpi-card:hover {
      box-shadow: var(--shadow-md);
      transform: translateY(-1px);
    }

    .kpi-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      flex-shrink: 0;
    }
    .kpi-icon.aberto   { background: #fef2f2; color: #dc2626; }
    .kpi-icon.pago     { background: #f0fdf4; color: #16a34a; }
    .kpi-icon.cancelado { background: #f3f4f6; color: #6b7280; }
    .kpi-icon.vencido  { background: #fff7ed; color: #ea580c; }

    .kpi-info { min-width: 0; }

    .kpi-label {
      font-size: .7rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #6b7280;
      line-height: 1;
      margin-bottom: .2rem;
    }

    .kpi-value {
      font-size: 1.15rem;
      font-weight: 700;
      line-height: 1.15;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .kpi-value.aberto   { color: #dc2626; }
    .kpi-value.pago     { color: #16a34a; }
    .kpi-value.cancelado { color: #6b7280; }
    .kpi-value.vencido  { color: #ea580c; }

    /* ---- Card / Table ---- */
    .card {
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow-sm);
    }

    .card-header {
      background: #fff;
      border-bottom: 1px solid var(--border);
      padding: .85rem 1rem;
      border-radius: var(--radius) var(--radius) 0 0;
    }

    .btn-primary {
      background: var(--bs-primary);
      border-color: var(--bs-primary);
      border-radius: .6rem;
      font-weight: 500;
      font-size: .85rem;
      padding: .45rem .9rem;
    }
    .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }

    .badge-success,
    .badge-warning,
    .badge-danger,
    .badge-secondary {
      padding: .22rem .52rem;
      border-radius: 999px;
      font-size: .7rem;
      font-weight: 600;
      letter-spacing: .02em;
    }
    .badge-success   { background: #dcfce7; color: #15803d; }
    .badge-warning   { background: #fef3c7; color: #b45309; }
    .badge-danger    { background: #fee2e2; color: #b91c1c; }
    .badge-secondary { background: #f3f4f6; color: #4b5563; }

    .table { font-size: .82rem; margin-bottom: 0; }

    .table thead th {
      background: #f8fafc;
      color: #64748b;
      font-weight: 600;
      font-size: .7rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      border-bottom: 1px solid var(--border);
      padding: .6rem .55rem;
    }

    .table tbody tr { transition: background .15s; }
    .table tbody tr:hover { background: #f1f5f9; }

    .table tbody td {
      padding: .5rem .55rem;
      vertical-align: middle;
      font-size: .8rem;
      line-height: 1.25;
      white-space: nowrap;
      color: #334155;
      border-bottom: 1px solid #f1f5f9;
    }

    .col-cliente,
    .table thead .th-cliente {
      width: 28%;
      min-width: 280px;
      white-space: normal !important;
    }

    .col-acoes,
    .table thead .th-acoes {
      width: 115px;
    }

    .badge-modo-avista,
    .badge-modo-parcelado {
      border-radius: 999px;
      padding: .2rem .5rem;
      font-size: .68rem;
      font-weight: 600;
      letter-spacing: .02em;
    }
    .badge-modo-avista { background: #f1f5f9; color: #475569; }
    .badge-modo-parcelado { background: #e0f2fe; color: #0369a1; }

    .badge-tipo-integral,
    .badge-tipo-parcial {
      border-radius: 999px;
      padding: .2rem .5rem;
      font-size: .68rem;
      font-weight: 600;
    }
    .badge-tipo-integral { background: #dcfce7; color: #15803d; }
    .badge-tipo-parcial  { background: #fef9c3; color: #854d0e; }

    .btn-action-view,
    .btn-action-edit,
    .btn-action-delete,
    .btn-action-pay {
      width: 30px;
      height: 30px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: .5rem;
      border: 1px solid transparent;
      background: transparent;
      transition: all .15s;
      font-size: .82rem;
    }

    .btn-action-view   { color: #6b7280; }
    .btn-action-edit   { color: #2563eb; }
    .btn-action-delete { color: #ef4444; }
    .btn-action-pay    { color: #16a34a; }

    .btn-action-view:hover   { background: #f3f4f6; border-color: #d1d5db; }
    .btn-action-edit:hover   { background: #eff6ff; border-color: #bfdbfe; }
    .btn-action-delete:hover { background: #fef2f2; border-color: #fecaca; }
    .btn-action-pay:hover    { background: #f0fdf4; border-color: #bbf7d0; }

    .detail-card {
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: .5rem;
      padding: 12px 14px;
      margin-bottom: 10px;
    }
    .detail-card h6 {
      font-size: .78rem;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #6b7280;
      margin: 0 0 8px;
      font-weight: 700;
    }
    .detail-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6px 16px;
      font-size: .88rem;
    }
    .detail-grid .lbl {
      color: #6b7280;
      font-weight: 500;
    }
    .detail-grid .val {
      color: #111827;
      font-weight: 600;
      text-align: right;
    }
    .detail-grid .full {
      grid-column: 1 / -1;
      text-align: left;
      color: #111827;
      font-weight: 500;
    }

    .mono {
      font-family: ui-monospace, 'Courier New', monospace;
    }

    .modal-header {
      border-bottom: 1px solid var(--border);
    }

    .form-label {
      font-weight: 500;
      font-size: .8rem;
      color: #374151;
      margin-bottom: .35rem;
    }

    .form-control,
    .form-select {
      border-color: #d1d5db;
      border-radius: .6rem;
      font-size: .85rem;
      padding: .42rem .7rem;
    }

    .form-control:focus,
    .form-select:focus {
      border-color: var(--bs-primary);
      box-shadow: 0 0 0 .18rem rgba(37,99,235,.15);
    }

    .autocomplete-container {
      position: relative;
    }

    .text-nowrap {
      white-space: nowrap;
    }

    /* Filtro header */
    .card-header .form-label {
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .05em;
      color: #94a3b8;
    }

    /* Pagination */
    .pagination .page-link {
      border-radius: .5rem;
      font-size: .78rem;
      margin: 0 .12rem;
      border: none;
      color: #475569;
    }
    .pagination .page-item.active .page-link {
      background: var(--bs-primary);
      color: #fff;
    }

    /* Responsivo - KPI adapta em telas menores */
    @media (max-width: 767.98px) {
      .kpi-row {
        flex-wrap: wrap !important;
      }
      .kpi-row > .kpi-card {
        flex: 1 1 calc(50% - .5rem) !important;
        min-width: calc(50% - .5rem);
      }
      .kpi-card { padding: .6rem .7rem; }
      .kpi-icon { width: 32px; height: 32px; font-size: .8rem; border-radius: 8px; }
      .kpi-value { font-size: .95rem; }
      .kpi-label { font-size: .62rem; }
    }

    @media (max-width: 399.98px) {
      .kpi-row > .kpi-card {
        flex: 1 1 100% !important;
        min-width: 100%;
      }
    }
  </style>
</head>

<body data-page="financeiro">
  <div class="d-flex" id="wrapper">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div id="page-content-wrapper" class="flex-grow-1">

      <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
        <button class="btn btn-outline-secondary me-2" id="menu-toggle">
          <i class="fa-solid fa-bars"></i>
        </button>

        <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Contas a Receber</span>

        <div class="collapse navbar-collapse justify-content-end">
          <div class="d-flex align-items-center gap-2">
            <span class="small text-muted">
              <?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?>
              (<?= htmlspecialchars($_SESSION['user_perfil'] ?? 'USER') ?>)
            </span>
            <a class="btn btn-sm btn-outline-danger" href="logout.php">
              <i class="fa-solid fa-right-from-bracket me-1"></i>Sair
            </a>
          </div>
        </div>
      </nav>

      <div class="container-fluid py-4">

        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h1 class="page-title">Contas a Receber</h1>
            <div class="small text-muted" style="font-size:.78rem;">Gerencie títulos a receber (avulsos e por contrato).</div>
          </div>
          <div>
            <button id="btnNovo" class="btn btn-primary"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#painelTipoLancamento"
                    aria-expanded="false"
                    aria-controls="painelTipoLancamento">
              <i class="bi bi-plus-lg me-1"></i> Novo Lançamento
            </button>
          </div>
        </div>

        <div class="collapse mb-3" id="painelTipoLancamento">
          <div class="card border-primary">
            <div class="card-body">
              <div class="small text-muted mb-2">Selecione o tipo de lançamento:</div>
              <div class="row g-2">
                <div class="col-md-4">
                  <button type="button" class="btn btn-outline-primary w-100 text-start py-3 tipo-lanc" data-tipo="avulso">
                    <div class="d-flex align-items-center gap-3">
                      <i class="bi bi-cash-stack" style="font-size:1.6rem;"></i>
                      <div>
                        <div class="fw-bold">Recebimento Avulso</div>
                        <div class="small text-muted">Entrada sem contrato</div>
                      </div>
                    </div>
                  </button>
                </div>
                <div class="col-md-4">
                  <button type="button" class="btn btn-outline-success w-100 text-start py-3 tipo-lanc" data-tipo="emprestimo">
                    <div class="d-flex align-items-center gap-3">
                      <i class="bi bi-bank" style="font-size:1.6rem;"></i>
                      <div>
                        <div class="fw-bold">Empréstimo Recebido</div>
                        <div class="small text-muted">Entrada de capital + parcelas a pagar</div>
                      </div>
                    </div>
                  </button>
                </div>
                <div class="col-md-4">
                  <button type="button" class="btn btn-outline-warning w-100 text-start py-3 tipo-lanc" data-tipo="aporte">
                    <div class="d-flex align-items-center gap-3">
                      <i class="bi bi-piggy-bank" style="font-size:1.6rem;"></i>
                      <div>
                        <div class="fw-bold">Aporte de Sócio</div>
                        <div class="small text-muted">Capital próprio (sem contrapartida)</div>
                      </div>
                    </div>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="d-flex flex-nowrap gap-2 mb-3 kpi-row">
          <div class="kpi-card flex-fill">
            <div class="kpi-icon" style="background:rgba(37,99,235,.12);color:#2563eb;"><i class="fa-solid fa-cash-register"></i></div>
            <div class="kpi-info">
              <div class="kpi-label">Valor Lançado</div>
              <div class="kpi-value" style="color:#2563eb;" id="cardLancado">R$ 0,00</div>
            </div>
          </div>
          <div class="kpi-card flex-fill">
            <div class="kpi-icon aberto"><i class="fa-solid fa-clock"></i></div>
            <div class="kpi-info">
              <div class="kpi-label">Em Aberto</div>
              <div class="kpi-value aberto" id="cardAberto">R$ 0,00</div>
            </div>
          </div>
          <div class="kpi-card flex-fill">
            <div class="kpi-icon pago"><i class="fa-solid fa-circle-check"></i></div>
            <div class="kpi-info">
              <div class="kpi-label">Total Pago</div>
              <div class="kpi-value pago" id="cardPago">R$ 0,00</div>
            </div>
          </div>
          <div class="kpi-card flex-fill">
            <div class="kpi-icon cancelado"><i class="fa-solid fa-ban"></i></div>
            <div class="kpi-info">
              <div class="kpi-label">Cancelado</div>
              <div class="kpi-value cancelado" id="cardCancelado">R$ 0,00</div>
            </div>
          </div>
          <div class="kpi-card flex-fill">
            <div class="kpi-icon" style="background:rgba(217,119,6,.12);color:#d97706;"><i class="fa-solid fa-arrow-right-arrow-left"></i></div>
            <div class="kpi-info">
              <div class="kpi-label">Pgto. Parcial</div>
              <div class="kpi-value" style="color:#d97706;" id="cardParcial">R$ 0,00</div>
            </div>
          </div>
          <div class="kpi-card flex-fill">
            <div class="kpi-icon vencido"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="kpi-info">
              <div class="kpi-label">Vencidos</div>
              <div class="kpi-value vencido" id="cardVencido">R$ 0,00</div>
            </div>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header py-2">
            <div class="row g-2 align-items-end">
              <div class="col-md-3">
                <input id="fBusca" class="form-control form-control-sm" placeholder="Buscar por cliente, CPF/CNPJ, documento...">
              </div>

              <div class="col-md-2">
                <select id="fStatus" class="form-select form-select-sm">
                  <option value="">Todos os Status</option>
                  <option value="ABERTO">Em aberto</option>
                  <option value="PAGO">Pago / Recebido</option>
                  <option value="PARCIAL">Pagamento Parcial</option>
                  <option value="ATRASADO">Atrasado</option>
                  <option value="CANCELADO">Cancelado</option>
                </select>
              </div>

              <div class="col-md-2">
                <select id="fEmpresa" class="form-select form-select-sm">
                  <option value="0">Todas as Empresas</option>
                </select>
              </div>

              <div class="col-md-2">
                <input id="fDtIni" type="date" class="form-control form-control-sm" placeholder="Data inicial">
              </div>

              <div class="col-md-2">
                <input id="fDtFim" type="date" class="form-control form-control-sm" placeholder="Data final">
              </div>

              <div class="col-md-1">
                <button class="btn btn-success btn-sm w-100" id="btnExportar" title="Exportar CSV"><i class="bi bi-download"></i></button>
              </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
              <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filtrosAvancados" aria-expanded="false" aria-controls="filtrosAvancados">
                <i class="bi bi-funnel me-1"></i>Mais filtros
              </button>
              <button class="btn btn-sm btn-outline-danger d-none" type="button" id="btnLimparFiltros" title="Limpar todos os filtros">
                <i class="bi bi-x-circle me-1"></i>Limpar filtros
              </button>
              <div id="chipsFiltros" class="d-flex flex-wrap gap-1"></div>
            </div>

            <div class="collapse mt-2" id="filtrosAvancados">
              <div class="card card-body bg-light py-2">
                <div class="row g-2 align-items-end">
                  <div class="col-md-2">
                    <label class="form-label small mb-1">Origem</label>
                    <select id="fOrigem" class="form-select form-select-sm">
                      <option value="">Todas</option>
                      <option value="CONTRATO">Contrato</option>
                      <option value="AVULSO">Avulso</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label small mb-1">Parcelas</label>
                    <select id="fParcelas" class="form-select form-select-sm" title="Filtra por quantidade de parcelas do contrato vinculado">
                      <option value="">Todas</option>
                      <option value="unica">Única (1)</option>
                      <option value="multipla">Parcelado (2+)</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label small mb-1">Tipo Recebimento</label>
                    <select id="fTipoRecebimento" class="form-select form-select-sm">
                      <option value="">Todos</option>
                      <option value="INTEGRAL">Integral</option>
                      <option value="PARCIAL">Parcial</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label small mb-1">Filtrar data por</label>
                    <select id="fTipoData" class="form-select form-select-sm">
                      <option value="vencimento">Vencimento</option>
                      <option value="recebimento">Recebimento</option>
                      <option value="criacao">Criação</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label small mb-1">Valor mínimo</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" id="fValorMin" placeholder="0,00">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label small mb-1">Valor máximo</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" id="fValorMax" placeholder="0,00">
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead>
                  <tr>
                    <th style="width: 70px;">ID</th>
                    <th style="width: 120px;">Status</th>
                    <th style="width: 120px;">Lançamento</th>
                    <th style="width: 120px;">Vencimento</th>
                    <th style="width: 90px;">Parcela</th>
                    <th class="th-cliente">Cliente</th>
                    <th style="width: 110px;">NF</th>
                    <th style="width: 120px;">Modo</th>
                    <th class="text-end" style="width: 120px;">Valor</th>
                    <th class="text-end" style="width: 120px;">Valor Pago</th>
                    <th class="text-end" style="width: 130px;" title="Saldo restante a receber em contas com pagamento parcial">Restante</th>
                    <th style="width: 120px;">Pagamento</th>
                    <th style="width: 100px;">Tipo</th>
                    <th class="text-end th-acoes">Ações</th>
                  </tr>
                </thead>
                <tbody id="tbody"></tbody>
              </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
              <div class="text-muted small" id="pgInfo">—</div>
              <div class="d-flex align-items-center gap-2">
                <select id="perPage" class="form-select form-select-sm" style="width:auto">
                  <option value="25">25</option>
                  <option value="50" selected>50</option>
                  <option value="100">100</option>
                  <option value="200">200</option>
                </select>
                <nav>
                  <ul class="pagination pagination-sm mb-0" id="pg"></ul>
                </nav>
              </div>
            </div>

          </div>
        </div>

      </div>
    </div>
  </div>

  <?php include __DIR__ . '/includes/scripts.php'; ?>

  <div class="modal fade" id="modalReceber" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">

        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i><span id="mTitle">Receber</span></h5>
          <button class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>

        <div class="modal-body">
          <form id="formReceber" novalidate>
            <input type="hidden" id="mId" value="">

            <div class="row g-3">

              <div class="col-md-3">
                <label class="form-label">Origem</label>
                <select id="mOrigem" class="form-select">
                  <option value="CONTRATO">Contrato</option>
                  <option value="AVULSO">Avulso</option>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label">Contrato</label>
                <select id="mContrato" class="form-select">
                  <option value="">Carregando...</option>
                </select>
                <small class="text-muted">CTR_ID - Cliente</small>
              </div>

              <div class="col-md-2">
                <label class="form-label">Competência</label>
                <input id="mCompetencia" type="month" class="form-control">
              </div>

              <div class="col-md-3">
                <label class="form-label">Vencimento <span class="text-danger">*</span></label>
                <input id="mVencimento" type="date" class="form-control" required>
              </div>

              <div class="col-md-3">
                <label class="form-label">Empresa <span class="text-danger">*</span></label>
                <select id="mEmpresa" class="form-select">
                  <option value="">Carregando...</option>
                </select>
              </div>

              <div class="col-md-3">
                <label class="form-label">Plano de Contas</label>
                <select id="mPlano" class="form-select">
                  <option value="">Carregando...</option>
                </select>
              </div>

              <div class="col-md-3">
                <label class="form-label">Centro de Custo</label>
                <select id="mCentro" class="form-select">
                  <option value="">Carregando...</option>
                </select>
              </div>

              <div class="col-md-3">
                <label class="form-label">Banco</label>
                <select id="mBanco" class="form-select">
                  <option value="">Carregando...</option>
                </select>
              </div>

              <div class="col-md-6 position-relative">
                <label class="form-label">Cliente <span class="text-danger">*</span></label>
                <input id="mCliente" class="form-control" autocomplete="off" placeholder="Digite para buscar...">
                <input type="hidden" id="mClienteId">
                <div class="list-group position-absolute w-100 d-none" id="acCliente" style="z-index: 2000; max-height: 260px; overflow:auto;"></div>
              </div>

              <div class="col-md-3">
                <label class="form-label">CPF/CNPJ</label>
                <input id="mCpfCnpj" class="form-control mono" disabled>
              </div>

              <div class="col-md-3">
                <label class="form-label">Valor <span class="text-danger">*</span></label>
                <div class="input-group">
                  <span class="input-group-text">R$</span>
                  <input id="mValor"
                    class="form-control text-end mono"
                    inputmode="numeric"
                    autocomplete="off"
                    required>
                </div>
              </div>

              <div class="col-md-4">
                <label class="form-label">Forma Cobrança</label>
                <select id="mForma" class="form-select">
                  <option value="">Carregando...</option>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label">Documento</label>
                <input id="mDocumento" class="form-control mono" placeholder="Ex.: BOL-000123">
              </div>

              <div class="col-md-4">
                <label class="form-label">Recebido em</label>
                <input id="mRecebidoEm" type="date" class="form-control">
              </div>

              <div class="col-md-4">
                <label class="form-label">Status</label>
                <select id="mStatus" class="form-select">
                  <option value="ABERTO">Em aberto</option>
                  <option value="PAGO">Pago / Recebido</option>
                  <option value="ATRASADO">Atrasado</option>
                  <option value="CANCELADO">Cancelado</option>
                </select>
              </div>

              <div class="col-md-8">
                <label class="form-label">Observação</label>
                <textarea id="mObs" class="form-control" rows="3"></textarea>
              </div>

            </div>
          </form>
        </div>

        <div class="modal-footer justify-content-between">
          <button type="button" class="btn btn-outline-danger d-none" id="btnExcluir">
            <i class="bi bi-trash me-1"></i> Excluir
          </button>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
              Cancelar
            </button>
            <button type="button" class="btn btn-primary" id="btnSalvar">
              <i class="bi bi-check2-circle me-1"></i> Salvar
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Modal Boleto BB -->
  <div class="modal fade" id="modalBoletoBB" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header" style="background:#002060; color:#fff;">
          <h5 class="modal-title"><i class="bi bi-upc-scan me-2"></i>Boleto Banco do Brasil</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="boletoBBBody">
          <div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Carregando...</p></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
          <button class="btn btn-primary d-none" id="btnGerarBoleto" onclick="confirmarGerarBoleto()">
            <i class="bi bi-plus-circle me-1"></i>Gerar Boleto
          </button>
          <button class="btn btn-outline-secondary d-none" id="btnCopiarLinha" onclick="copiarLinha()">
            <i class="bi bi-clipboard me-1"></i>Copiar linha digitável
          </button>
          <button class="btn btn-outline-info d-none" id="btnCopiarPix" onclick="copiarPix()">
            <i class="bi bi-qr-code me-1"></i>Copiar Pix
          </button>
          <button class="btn btn-outline-dark d-none" id="btnImprimirBoleto" onclick="imprimirBoleto()">
            <i class="bi bi-printer me-1"></i>Imprimir / 2ª via
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Baixa Manual -->
  <div class="modal fade" id="modalBaixaManual" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h4 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Receber Parcela</h4>
          <button class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body">
          <form id="formBaixaManual" novalidate>
            <input type="hidden" id="bxId" value="">

            <div class="row g-3 align-items-end">
              <div class="col-md-4">
                <label class="form-label">Forma Pag.</label>
                <select id="bxForma" class="form-select"></select>
              </div>

              <div class="col-md-4">
                <label class="form-label">Integral/Parcial</label>
                <select id="bxTipoRecebimento" class="form-select">
                  <option value="INTEGRAL">Integral</option>
                  <option value="PARCIAL">Parcial</option>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label">Pagamento <span class="text-danger">*</span></label>
                <input id="bxRecebidoEm" type="date" class="form-control" required>
              </div>

              <div class="col-md-6">
                <label class="form-label">Lançamento <span class="text-danger">*</span></label>
                <input id="bxLancamento" type="text" class="form-control" readonly>
              </div>

              <div class="col-md-6">
                <label class="form-label">Banco <span class="text-danger">*</span></label>
                <select id="bxBanco" class="form-select" required>
                  <option value="">Carregando...</option>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label">Valor da Parcela</label>
                <div class="input-group">
                  <span class="input-group-text">R$</span>
                  <input id="bxValorParcela" type="text" class="form-control text-end" readonly>
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Valor Pago <span class="text-danger">*</span></label>
                <div class="input-group">
                  <span class="input-group-text">R$</span>
                  <input id="bxValorPago" type="text" class="form-control text-end" placeholder="0,00">
                </div>
                <div class="form-text" id="bxValorPagoHelp">No modo integral, este valor será igual ao total.</div>
                <div class="mt-2 small text-muted">
                  <i class="bi bi-info-circle me-1"></i>
                  O saldo bancário é atualizado automaticamente quando este recebimento for marcado como RECEBIDO.
                </div>
              </div>

              <div class="col-md-12">
                <label class="form-label">Observações</label>
                <textarea id="bxObs" class="form-control" rows="3" placeholder="Observação do recebimento"></textarea>
              </div>
            </div>
          </form>
        </div>
        <div class="modal-footer justify-content-between">
          <div></div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i> Cancelar
            </button>
            <button type="button" class="btn btn-success" id="btnConfirmarBaixaManual">
              <i class="bi bi-check2-circle me-1"></i> Receber
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- Modal: Empréstimo Recebido                                   -->
  <!-- ============================================================ -->
  <div class="modal fade" id="modalEmprestimo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="bi bi-bank me-2"></i>Novo Empréstimo Recebido</h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">

          <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Será criada <strong>uma entrada</strong> no Contas a Receber (capital recebido) e
            <strong>N parcelas</strong> no Contas a Pagar (devolução do empréstimo).
          </div>

          <h6 class="text-success border-bottom pb-1 mb-3"><i class="bi bi-arrow-down-circle me-1"></i>Entrada (Receita)</h6>
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Empresa *</label>
              <select id="empEmpresa" class="form-select"><option value="">Carregando...</option></select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Banco onde caiu *</label>
              <select id="empBanco" class="form-select"><option value="">Carregando...</option></select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Data da entrada *</label>
              <input id="empData" type="date" class="form-control">
            </div>

            <div class="col-md-6">
              <label class="form-label">Credor (banco/instituição) *</label>
              <input id="empCredor" class="form-control" placeholder="Ex.: BTG Pactual S/A">
            </div>
            <div class="col-md-3">
              <label class="form-label">CNPJ do credor</label>
              <input id="empCredorDoc" class="form-control mono">
            </div>
            <div class="col-md-3">
              <label class="form-label">Valor total liberado *</label>
              <input id="empValorTotal" class="form-control text-end" placeholder="0,00">
            </div>

            <div class="col-md-6">
              <label class="form-label">Plano de contas (Receita)</label>
              <select id="empPlanoReceita" class="form-select"><option value="">— Selecione —</option></select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Documento/Contrato</label>
              <input id="empDocumento" class="form-control mono" placeholder="Ex.: CCB-12345">
            </div>
            <div class="col-md-3">
              <label class="form-label">&nbsp;</label>
              <div class="form-control-plaintext small text-muted" id="empResumoEntrada">—</div>
            </div>
          </div>

          <h6 class="text-danger border-bottom pb-1 mb-3 mt-4"><i class="bi bi-arrow-up-circle me-1"></i>Pagamento (Contas a Pagar)</h6>
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label">Qtd. parcelas *</label>
              <input id="empQtdParcelas" type="number" min="1" max="120" class="form-control text-center" value="12">
            </div>
            <div class="col-md-3">
              <label class="form-label">1º vencimento *</label>
              <input id="empPrimeiroVenc" type="date" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">Valor da parcela *</label>
              <input id="empValorParcela" class="form-control text-end" placeholder="0,00">
            </div>
            <div class="col-md-3">
              <label class="form-label">Total a pagar</label>
              <input id="empTotalPagar" class="form-control text-end" readonly>
            </div>

            <div class="col-md-6">
              <label class="form-label">Plano de contas (Despesa)</label>
              <select id="empPlanoDespesa" class="form-select"><option value="">— Selecione —</option></select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Centro de custo</label>
              <select id="empCentroDespesa" class="form-select"><option value="">— Selecione —</option></select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Forma de pagamento</label>
              <input id="empFormaPgto" class="form-control" placeholder="Ex.: Débito automático">
            </div>

            <div class="col-12">
              <label class="form-label">Observação</label>
              <textarea id="empObs" class="form-control" rows="2"></textarea>
            </div>
          </div>

        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Cancelar</button>
          <button class="btn btn-success" id="btnSalvarEmprestimo"><i class="bi bi-check2-circle me-1"></i>Gravar empréstimo</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- Modal: Aporte de Sócio                                        -->
  <!-- ============================================================ -->
  <div class="modal fade" id="modalAporte" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header bg-warning">
          <h5 class="modal-title"><i class="bi bi-piggy-bank me-2"></i>Aporte de Sócio</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-light border py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Aporte de capital próprio (sem contrapartida em Contas a Pagar).
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Empresa *</label>
              <select id="apEmpresa" class="form-select"><option value="">Carregando...</option></select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Banco onde caiu *</label>
              <select id="apBanco" class="form-select"><option value="">Carregando...</option></select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Sócio *</label>
              <input id="apSocio" class="form-control" placeholder="Nome do sócio">
            </div>
            <div class="col-md-6">
              <label class="form-label">CPF/CNPJ</label>
              <input id="apSocioDoc" class="form-control mono">
            </div>

            <div class="col-md-4">
              <label class="form-label">Data da entrada *</label>
              <input id="apData" type="date" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Valor *</label>
              <input id="apValor" class="form-control text-end" placeholder="0,00">
            </div>
            <div class="col-md-4">
              <label class="form-label">Documento</label>
              <input id="apDocumento" class="form-control mono">
            </div>

            <div class="col-md-8">
              <label class="form-label">Plano de contas</label>
              <select id="apPlano" class="form-select"><option value="">— Selecione —</option></select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Centro de custo</label>
              <select id="apCentro" class="form-select"><option value="">— Selecione —</option></select>
            </div>

            <div class="col-12">
              <label class="form-label">Observação</label>
              <textarea id="apObs" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Cancelar</button>
          <button class="btn btn-warning" id="btnSalvarAporte"><i class="bi bi-check2-circle me-1"></i>Gravar aporte</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    const apiUrl = "<?= basename($_SERVER['PHP_SELF']) ?>";
    const bbLogoBase64 = '<?= base64_encode(file_get_contents(__DIR__ . "/boleto/logo.png")) ?>';

    const PERMITE_EXCLUIR_PARCELAS_PAGAS = "<?= htmlspecialchars($permiteExcluirParcelasPagasFront) ?>";

    const el = (id) => document.getElementById(id);
    const tbody = el('tbody');

    const fBusca = el('fBusca');
    const fStatus = el('fStatus');
    const fOrigem = el('fOrigem');
    const fParcelas = el('fParcelas');
    const fDtIni = el('fDtIni');
    const fDtFim = el('fDtFim');
    const fEmpresa = el('fEmpresa');
    const fTipoRecebimento = el('fTipoRecebimento');
    const fTipoData = el('fTipoData');
    const fValorMin = el('fValorMin');
    const fValorMax = el('fValorMax');
    const chipsFiltros = el('chipsFiltros');
    const btnLimparFiltros = el('btnLimparFiltros');
    const btnExportar = el('btnExportar');

    const pg = el('pg');
    const pgInfo = el('pgInfo');
    const perPage = el('perPage');

    const modalReceberEl = el('modalReceber');
    const modalReceber = window.bootstrap?.Modal ? bootstrap.Modal.getOrCreateInstance(modalReceberEl) : null;
    const modalBaixaManualEl = el('modalBaixaManual');
    const modalBaixaManual = window.bootstrap?.Modal ? bootstrap.Modal.getOrCreateInstance(modalBaixaManualEl) : null;

    const mTitle = el('mTitle');
    const mId = el('mId');
    const mOrigem = el('mOrigem');
    const mContrato = el('mContrato');
    const mCompetencia = el('mCompetencia');
    const mVencimento = el('mVencimento');

    const mEmpresa = el('mEmpresa');
    const mPlano = el('mPlano');
    const mCentro = el('mCentro');
    const mBanco = el('mBanco');

    const mCliente = el('mCliente');
    const mClienteId = el('mClienteId');
    const mCpfCnpj = el('mCpfCnpj');
    const mValor = el('mValor');
    const mForma = el('mForma');
    const mDocumento = el('mDocumento');
    const mRecebidoEm = el('mRecebidoEm');
    const mStatus = el('mStatus');
    const mObs = el('mObs');
    const btnExcluir = el('btnExcluir');
    const btnSalvar = el('btnSalvar');

    const bxId = el('bxId');
    const bxForma = el('bxForma');
    const bxTipoRecebimento = el('bxTipoRecebimento');
    const bxLancamento = el('bxLancamento');
    const bxBanco = el('bxBanco');
    const bxValorParcela = el('bxValorParcela');
    const bxValorPago = el('bxValorPago');
    const bxValorPagoHelp = el('bxValorPagoHelp');
    const bxRecebidoEm = el('bxRecebidoEm');
    const bxObs = el('bxObs');
    const btnConfirmarBaixaManual = el('btnConfirmarBaixaManual');

    const acCliente = el('acCliente');

    function qs(params) {
      return new URLSearchParams(params).toString();
    }

    async function apiGet(params) {
      const r = await fetch(apiUrl + '?' + qs(params), {
        credentials: 'same-origin'
      });
      return await r.json();
    }

    async function apiPost(params) {
      const fd = new FormData();
      Object.entries(params).forEach(([k, v]) => fd.append(k, v ?? ''));
      const r = await fetch(apiUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });
      return await r.json();
    }

    function brl(v) {
      const n = Number(v || 0);
      return n.toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL'
      });
    }

    function fmtBR(iso) {
      if (!iso) return '—';
      const p = String(iso).split('-');
      return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : iso;
    }

    function fmtCompetencia(iso) {
      if (!iso) return '—';
      const d = new Date(iso + 'T00:00:00');
      return d.toLocaleDateString('pt-BR', {
        month: 'long',
        year: 'numeric'
      });
    }

    function statusPago(s) {
      s = String(s || '').toUpperCase();
      return s === 'RECEBIDO' || s === 'PAGO';
    }

    function badgeStatus(s) {
      s = String(s || '').toUpperCase();
      if (statusPago(s)) return '<span class="badge-success">Pago</span>';
      if (s === 'ABERTO') return '<span class="badge-warning">Em aberto</span>';
      if (s === 'ATRASADO') return '<span class="badge-danger">Atrasado</span>';
      if (s === 'CANCELADO') return '<span class="badge-secondary">Cancelado</span>';
      return '<span class="badge-secondary">' + (s || '—') + '</span>';
    }

    // Status efetivo para exibição: deriva ATRASADO quando a parcela está em
    // aberto (ou parcial) e o vencimento já passou. ATRASADO raramente é
    // persistido em CRE_STATUS — é um rótulo derivado da data.
    function statusReceberEfetivo(r) {
      const s = String(r.CRE_STATUS || '').toUpperCase();
      if (s === 'RECEBIDO' || s === 'PAGO' || s === 'CANCELADO') return s;
      const venc = String(r.CRE_VENCIMENTO || '').slice(0, 10);
      const d = new Date();
      const hoje = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
      if (venc && venc < hoje) return 'ATRASADO';
      return 'ABERTO';
    }

    function badgeTipoRecebimento(tipo) {
      tipo = String(tipo || '').toUpperCase();
      if (tipo === 'INTEGRAL') return '<span class="badge-tipo-integral">INTEGRAL</span>';
      if (tipo === 'PARCIAL') return '<span class="badge-tipo-parcial">PARCIAL</span>';
      return '—';
    }

    let STATE = {
      page: 1,
      per: 50,
      total: 0,
      pages: 1
    };

    function renderPaginacao() {
      pg.innerHTML = '';
      const pages = STATE.pages || 1;
      const page = STATE.page;

      const add = (p, label, disabled = false, active = false) => {
        const li = document.createElement('li');
        li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
        li.innerHTML = '<a class="page-link" href="#">' + label + '</a>';
        li.onclick = (e) => {
          e.preventDefault();
          if (disabled || active) return;
          STATE.page = p;
          listar();
        };
        pg.appendChild(li);
      };

      add(Math.max(1, page - 1), '«', page === 1);

      const win = 2;
      const start = Math.max(1, page - win);
      const end = Math.min(pages, page + win);

      if (start > 1) {
        add(1, '1', false, page === 1);
        if (start > 2) add(page, '…', true, false);
      }
      for (let p = start; p <= end; p++) add(p, String(p), false, p === page);
      if (end < pages) {
        if (end < pages - 1) add(page, '…', true, false);
        add(pages, String(pages), false, page === pages);
      }

      add(Math.min(pages, page + 1), '»', page === pages);

      const from = (STATE.total ? ((STATE.page - 1) * STATE.per + 1) : 0);
      const to = Math.min((STATE.page * STATE.per), STATE.total);
      pgInfo.textContent = `Mostrando ${from}-${to} de ${STATE.total}`;
    }

    function coletarFiltros() {
      return {
        q: fBusca.value.trim(),
        status: fStatus.value || '',
        origem: fOrigem ? (fOrigem.value || '') : '',
        parcelas: fParcelas ? (fParcelas.value || '') : '',
        dtIni: fDtIni.value || '',
        dtFim: fDtFim.value || '',
        empresa: fEmpresa ? (fEmpresa.value || '0') : '0',
        tipo_recebimento: fTipoRecebimento ? (fTipoRecebimento.value || '') : '',
        tipo_data: fTipoData ? (fTipoData.value || 'vencimento') : 'vencimento',
        valor_min: fValorMin ? (fValorMin.value || '') : '',
        valor_max: fValorMax ? (fValorMax.value || '') : ''
      };
    }

    const FILTROS_LS_KEY = 'cr_filtros_v1';
    function salvarFiltros() {
      try { localStorage.setItem(FILTROS_LS_KEY, JSON.stringify(coletarFiltros())); } catch(e) {}
    }
    function carregarFiltrosSalvos() {
      try {
        const raw = localStorage.getItem(FILTROS_LS_KEY);
        if (!raw) return null;
        return JSON.parse(raw);
      } catch(e) { return null; }
    }

    function renderChipsFiltros() {
      const f = coletarFiltros();
      const chips = [];
      if (f.q)                              chips.push({label: 'Busca: "' + f.q + '"', clear: () => { fBusca.value = ''; }});
      if (f.status && f.status !== '')      chips.push({label: 'Status: ' + f.status, clear: () => { fStatus.value = ''; }});
      if (f.empresa && f.empresa !== '0') {
        const opt = fEmpresa ? fEmpresa.options[fEmpresa.selectedIndex] : null;
        chips.push({label: 'Empresa: ' + (opt ? opt.text : f.empresa), clear: () => { fEmpresa.value = '0'; }});
      }
      if (f.origem && f.origem !== '')      chips.push({label: 'Origem: ' + f.origem, clear: () => { fOrigem.value = ''; }});
      if (f.tipo_recebimento && f.tipo_recebimento !== '') chips.push({label: 'Recebimento: ' + f.tipo_recebimento, clear: () => { fTipoRecebimento.value = ''; }});
      if (f.parcelas && f.parcelas !== '')  chips.push({label: 'Parcelas: ' + (f.parcelas === 'unica' ? 'Única' : 'Parcelado'), clear: () => { fParcelas.value = ''; }});
      if (f.dtIni)                          chips.push({label: 'De: ' + f.dtIni, clear: () => { fDtIni.value = ''; }});
      if (f.dtFim)                          chips.push({label: 'Até: ' + f.dtFim, clear: () => { fDtFim.value = ''; }});
      if (f.dtIni || f.dtFim) {
        const labelData = f.tipo_data === 'recebimento' ? 'recebimento' : (f.tipo_data === 'criacao' ? 'criação' : 'vencimento');
        chips.push({label: 'Por data de: ' + labelData, clear: () => { if (fTipoData) fTipoData.value = 'vencimento'; }});
      }
      if (f.valor_min)                      chips.push({label: 'Valor ≥ ' + f.valor_min, clear: () => { fValorMin.value = ''; }});
      if (f.valor_max)                      chips.push({label: 'Valor ≤ ' + f.valor_max, clear: () => { fValorMax.value = ''; }});

      if (!chips.length) {
        if (chipsFiltros) chipsFiltros.innerHTML = '';
        if (btnLimparFiltros) btnLimparFiltros.classList.add('d-none');
        return;
      }
      if (btnLimparFiltros) btnLimparFiltros.classList.remove('d-none');
      if (chipsFiltros) chipsFiltros.innerHTML = '';
      chips.forEach(c => {
        const el2 = document.createElement('span');
        el2.className = 'badge bg-secondary-subtle text-secondary-emphasis border d-inline-flex align-items-center gap-1';
        el2.style.cursor = 'pointer';
        el2.title = 'Remover este filtro';
        el2.innerHTML = c.label + ' <i class="bi bi-x"></i>';
        el2.addEventListener('click', () => {
          c.clear();
          STATE.page = 1;
          salvarFiltros();
          renderChipsFiltros();
          listar();
        });
        if (chipsFiltros) chipsFiltros.appendChild(el2);
      });
    }

    function limparTodosFiltros() {
      fBusca.value = '';
      fStatus.value = '';
      if (fEmpresa) fEmpresa.value = '0';
      if (fOrigem) fOrigem.value = '';
      if (fParcelas) fParcelas.value = '';
      if (fTipoRecebimento) fTipoRecebimento.value = '';
      if (fTipoData) fTipoData.value = 'vencimento';
      fDtIni.value = '';
      fDtFim.value = '';
      if (fValorMin) fValorMin.value = '';
      if (fValorMax) fValorMax.value = '';
      STATE.page = 1;
      salvarFiltros();
      renderChipsFiltros();
      listar();
    }

    async function listar() {
      salvarFiltros();
      renderChipsFiltros();

      const f = coletarFiltros();
      const data = await apiGet(Object.assign({
        acao: 'listar',
        page: STATE.page,
        per_page: STATE.per
      }, f));

      if (!data.ok) {
        console.error(data);
        Swal.fire({
          icon: 'error',
          title: 'Erro',
          text: data.msg || 'Erro no banco.'
        });
        return;
      }

      STATE.total = data.total || 0;
      STATE.pages = data.pages || 1;

      const resumo = data.resumo || {};
      const elLancado = document.getElementById('cardLancado');
      const elAberto = document.getElementById('cardAberto');
      const elPago = document.getElementById('cardPago');
      const elCancelado = document.getElementById('cardCancelado');
      const elVencido = document.getElementById('cardVencido');
      const elParcial = document.getElementById('cardParcial');
      if (elLancado) elLancado.textContent = brl(resumo.total_lancado || 0);
      if (elAberto) elAberto.textContent = brl(resumo.total_aberto || 0);
      if (elPago) elPago.textContent = brl(resumo.total_pago || 0);
      if (elCancelado) elCancelado.textContent = brl(resumo.total_cancelado || 0);
      if (elVencido) elVencido.textContent = brl(resumo.total_vencido || 0);
      if (elParcial) elParcial.textContent = brl(resumo.total_parcial || 0);

      tbody.innerHTML = (data.rows || []).map(r => {
        const cliente = r.CRE_CLIENTE_NOME || r.CLI_NOME_RAZAO || '—';
        const modo = String(r.CRE_ORIGEM || '').toUpperCase() === 'CONTRATO' ?
          '<span class="badge-modo-parcelado">PARCELADO</span>' :
          '<span class="badge-modo-avista">À VISTA</span>';
        const valorPago = Number(r.CRE_VALOR_RECEBIDO || 0) > 0 ? brl(r.CRE_VALOR_RECEBIDO) : '—';
        const tipoRec = badgeTipoRecebimento(r.CRE_TIPO_RECEBIMENTO || (statusPago(r.CRE_STATUS) ? 'INTEGRAL' : (Number(r.CRE_VALOR_RECEBIDO || 0) > 0 ? 'PARCIAL' : '')));
        const bloqueada = statusPago(r.CRE_STATUS);

        return `
          <tr>
            <td class="mono">${r.CRE_ID || '—'}</td>
            <td>${badgeStatus(statusReceberEfetivo(r))}</td>
            <td class="mono">${r.CRE_CREATED_AT ? fmtBR(String(r.CRE_CREATED_AT).slice(0,10)) : '—'}</td>
            <td class="mono">${fmtBR(r.CRE_VENCIMENTO)}</td>
            <td class="mono">${(() => {
              const doc = String(r.CRE_DOCUMENTO || '');
              const parts = doc.split('/');
              const totalParc = Number(r.TOTAL_PARCELAS_CONTRATO || 0);
              if (parts.length >= 2) {
                const num = parts[parts.length - 1];
                const total = totalParc > 0 ? String(totalParc).padStart(2,'0') : num.padStart(2,'0');
                return String(num).padStart(2,'0') + '/' + total;
              }
              return '01/01';
            })()}</td>
            <td class="fw-semibold col-cliente">${cliente}</td>
            <td class="mono">${r.CRE_NF || '—'}</td>
            <td>${modo}</td>
            <td class="text-end mono">${brl(r.CRE_VALOR)}</td>
            <td class="text-end mono">${valorPago}</td>
            <td class="text-end mono">${(() => {
              const v = Number(r.CRE_VALOR || 0);
              const p = Number(r.CRE_VALOR_RECEBIDO || 0);
              const rest = Math.max(0, v - p);
              if (p > 0.005 && rest > 0.005) {
                return `<span class="text-warning fw-bold" title="Pagamento parcial — falta receber">${brl(rest)}</span>`;
              }
              return '<span class="text-muted">—</span>';
            })()}</td>
            <td class="mono">${r.CRE_RECEBIDO_EM ? fmtBR(r.CRE_RECEBIDO_EM) : '—'}</td>
            <td>${tipoRec}</td>
            <td class="text-end text-nowrap col-acoes">
              <div class="d-flex gap-1 justify-content-end flex-nowrap">
                <button class="btn btn-sm btn-action-view" onclick="event.stopPropagation(); verDetalhes(${r.CRE_ID})" title="Ver detalhes">
                  <i class="bi bi-eye"></i>
                </button>
                <button class="btn btn-sm btn-action-edit" onclick="event.stopPropagation(); ${bloqueada ? 'alertaParcelaPaga()' : 'abrirEdicao(' + r.CRE_ID + ')'}" title="Editar">
                  <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-action-delete" onclick="event.stopPropagation(); ${(bloqueada && PERMITE_EXCLUIR_PARCELAS_PAGAS !== 'S') ? 'alertaParcelaPaga()' : 'excluirRegistro(' + r.CRE_ID + ')'}" title="Excluir">
                  <i class="bi bi-trash"></i>
                </button>
                <button class="btn btn-sm btn-action-pay" onclick="event.stopPropagation(); ${bloqueada ? 'alertaParcelaPaga()' : 'abrirBaixaManual(' + r.CRE_ID + ')'}" title="Baixar manualmente">
                  <i class="bi bi-cash-coin"></i>
                </button>
                ${bloqueada ? `
                <button class="btn btn-sm btn-outline-warning" onclick="event.stopPropagation(); abrirReabrirContaReceber(${r.CRE_ID})" title="Reabrir conta recebida (requer senha ADMIN)">
                  <i class="bi bi-arrow-counterclockwise"></i>
                </button>` : ''}
                ${r.BOL_STATUS_EMITIDO
                  ? `<button class="btn btn-sm" onclick="event.stopPropagation(); abrirBoletoBB(${r.CRE_ID})" title="Boleto emitido — clique para ver/reimprimir" style="background:#002060;color:#fff;border:none;position:relative;">
                       <i class="bi bi-upc-scan"></i>
                       <span style="position:absolute;top:-4px;right:-4px;width:9px;height:9px;background:#22c55e;border-radius:50%;border:1px solid #fff;"></span>
                     </button>`
                  : `<button class="btn btn-sm btn-boleto-bb" onclick="event.stopPropagation(); abrirBoletoBB(${r.CRE_ID})" title="Gerar Boleto Banco do Brasil" style="background:#002060;color:#fff;border:none;opacity:.65;">
                       <i class="bi bi-upc-scan"></i>
                     </button>`
                }
              </div>
            </td>
          </tr>
        `;
      }).join('');

      renderPaginacao();
    }

    function alertaParcelaPaga() {
      return Swal.fire({
        icon: 'warning',
        title: 'Parcela já paga',
        text: 'Essa parcela já foi paga e não pode ser alterada. Contate o administrador.'
      });
    }

    function setOptions(select, rows, firstLabel = 'Selecione...') {
      select.innerHTML =
        `<option value="">${firstLabel}</option>` +
        (rows || []).map(r => {
          const id = r.id ?? '';
          const nome = r.nome ?? '';
          return `<option value="${id}">${nome}</option>`;
        }).join('');
    }

    async function carregarFormasEContratosEBancos() {
      const [formas, contratos, bancos] = await Promise.all([
        apiGet({
          acao: 'combo_forma_cobranca'
        }),
        apiGet({
          acao: 'combo_contratos',
          limit: 150
        }),
        apiGet({
          acao: 'combo_bancos'
        })
      ]);

      if (formas.ok) {
        const formasHtml = `<option value="">Selecione...</option>` + (formas.rows || []).map(r => `<option value="${String(r.nome || '').replace(/"/g,'&quot;')}">${r.nome}</option>`).join('');
        mForma.innerHTML = formasHtml;
        bxForma.innerHTML = formasHtml;
      }

      if (contratos.ok) {
        mContrato.innerHTML = `<option value="">(nenhum)</option>` + (contratos.rows || []).map(r =>
          `<option value="${r.id}" data-cliente-id="${r.cliente_id||''}" data-cliente-nome="${(r.cliente_nome||'').replace(/"/g,'&quot;')}">
            ${r.label || (r.numero + ' - ' + r.cliente_nome)}
          </option>`
        ).join('');
      }

      if (bancos.ok) {
        setOptions(mBanco, bancos.rows || [], 'Selecione...');
        setOptions(bxBanco, bancos.rows || [], 'Selecione...');
      }
    }


    function setSelectByValueOrText(select, rawValue) {
      if (!select) return;
      const wanted = String(rawValue || '').trim();
      if (!wanted) {
        select.value = '';
        return;
      }
      const lowerWanted = wanted.toLowerCase();
      const option = Array.from(select.options || []).find(opt => String(opt.value || '').trim() === wanted || String(opt.textContent || '').trim().toLowerCase() === lowerWanted || String(opt.dataset?.nome || '').trim().toLowerCase() === lowerWanted);
      select.value = option ? option.value : '';
    }
    async function carregarEmpresas() {
      const data = await apiGet({
        acao: 'combo_empresas'
      });
      if (data.ok) {
        setOptions(mEmpresa, data.rows || [], 'Selecione...');
        // Popular também o filtro de empresa
        if (fEmpresa) {
          const opts = (data.rows || []).map(e => `<option value="${e.id}">${e.nome}</option>`).join('');
          fEmpresa.innerHTML = '<option value="0">Todas as Empresas</option>' + opts;
        }
      } else {
        mEmpresa.innerHTML = '<option value="">Falha ao carregar</option>';
      }
    }

    async function carregarPlanoECentroPorEmpresa(empresaId, planoSel = '', centroSel = '') {
      const [plano, centro] = await Promise.all([
        apiGet({
          acao: 'combo_plano_contas'
        }),
        apiGet({
          acao: 'combo_centro_custo'
        })
      ]);

      if (plano.ok) {
        setOptions(mPlano, plano.rows || [], 'Selecione...');
        if (planoSel) mPlano.value = String(planoSel);
      } else {
        mPlano.innerHTML = '<option value="">Falha ao carregar</option>';
      }

      if (centro.ok) {
        setOptions(mCentro, centro.rows || [], 'Selecione...');
        if (centroSel) mCentro.value = String(centroSel);
      } else {
        mCentro.innerHTML = '<option value="">Falha ao carregar</option>';
      }
    }

    function limparModal() {
      mId.value = '';
      mOrigem.value = 'AVULSO';
      mContrato.value = '';
      mCompetencia.value = '';
      mVencimento.value = '';

      mEmpresa.value = '';
      mPlano.innerHTML = '<option value="">Selecione a empresa...</option>';
      mCentro.innerHTML = '<option value="">Selecione a empresa...</option>';
      mBanco.value = '';

      mCliente.value = '';
      mClienteId.value = '';
      mCpfCnpj.value = '';
      mValor.value = '';
      mForma.value = '';
      mDocumento.value = '';
      mRecebidoEm.value = '';
      mStatus.value = 'ABERTO';
      mObs.value = '';
    }

    // === Novo Lançamento: painel inline + dispatch por tipo ===
    const painelTipoEl = el('painelTipoLancamento');
    const painelTipo = window.bootstrap?.Collapse
      ? bootstrap.Collapse.getOrCreateInstance(painelTipoEl, { toggle: false })
      : null;

    document.querySelectorAll('.tipo-lanc').forEach(btn => {
      btn.addEventListener('click', () => {
        const tipo = btn.dataset.tipo;
        painelTipo?.hide();
        if (tipo === 'avulso') {
          mTitle.textContent = 'Recebimento Avulso';
          limparModal();
          if (mOrigem) mOrigem.value = 'AVULSO';
          btnExcluir.classList.add('d-none');
          modalReceber?.show();
        } else if (tipo === 'emprestimo') {
          abrirModalEmprestimo();
        } else if (tipo === 'aporte') {
          abrirModalAporte();
        }
      });
    });

    // ---------- Helpers compartilhados ----------
    const fmtMoeda = (n) => Number(n || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const parseMoeda = (s) => {
      const v = String(s || '0').replace(/\./g, '').replace(',', '.');
      const n = Number(v);
      return isFinite(n) ? n : 0;
    };
    function bindMoeda(input) {
      if (!input) return;
      input.addEventListener('input', () => mascaraMoedaBR(input));
      input.addEventListener('blur', () => { if (!input.value) input.value = '0,00'; });
    }
    async function popularCombo(select, params, valorAtual) {
      if (!select) return;
      try {
        const r = await fetch(apiUrl + '?' + qs(params), { credentials: 'same-origin' });
        const txt = await r.text();
        let data;
        try { data = JSON.parse(txt); }
        catch (e) {
          console.error('[popularCombo] Resposta não-JSON em', params.acao, '→', txt.slice(0, 200));
          select.innerHTML = '<option value="">Erro: ' + params.acao + '</option>';
          return;
        }
        select.innerHTML = '<option value="">— Selecione —</option>';
        (data.rows || []).forEach(row => {
          const opt = document.createElement('option');
          opt.value = row.id;
          opt.textContent = row.nome;
          if (String(valorAtual || '') === String(row.id)) opt.selected = true;
          select.appendChild(opt);
        });
      } catch (e) {
        console.error('[popularCombo] erro de rede em', params.acao, e);
        select.innerHTML = '<option value="">Erro de rede</option>';
      }
    }

    // ---------- Modal Empréstimo ----------
    const modalEmpEl = el('modalEmprestimo');
    const modalEmp = window.bootstrap?.Modal ? bootstrap.Modal.getOrCreateInstance(modalEmpEl) : null;
    const empValorTotalEl   = el('empValorTotal');
    const empQtdParcelasEl  = el('empQtdParcelas');
    const empValorParcelaEl = el('empValorParcela');
    const empTotalPagarEl   = el('empTotalPagar');
    const empResumoEntrada  = el('empResumoEntrada');

    bindMoeda(empValorTotalEl);
    bindMoeda(empValorParcelaEl);

    function recalcParcela(origem) {
      const total = parseMoeda(empValorTotalEl.value);
      const n = Math.max(1, parseInt(empQtdParcelasEl.value || '0', 10));
      if (origem === 'total' || origem === 'qtd') {
        if (total > 0 && n > 0) empValorParcelaEl.value = fmtMoeda(total / n);
      }
      const parcela = parseMoeda(empValorParcelaEl.value);
      empTotalPagarEl.value = fmtMoeda(parcela * n);
      if (empResumoEntrada) empResumoEntrada.textContent = total > 0 ? ('Entrada: R$ ' + fmtMoeda(total)) : '—';
    }
    empValorTotalEl?.addEventListener('input', () => recalcParcela('total'));
    empQtdParcelasEl?.addEventListener('input', () => recalcParcela('qtd'));
    empValorParcelaEl?.addEventListener('input', () => recalcParcela('parcela'));

    async function abrirModalEmprestimo() {
      // limpar
      ['empCredor','empCredorDoc','empDocumento','empValorTotal','empValorParcela','empFormaPgto','empObs']
        .forEach(id => { const e = el(id); if (e) e.value = ''; });
      empQtdParcelasEl.value = '12';
      empValorTotalEl.value = '0,00';
      empValorParcelaEl.value = '0,00';
      empTotalPagarEl.value = '0,00';
      const hoje = new Date().toISOString().slice(0,10);
      el('empData').value = hoje;
      el('empPrimeiroVenc').value = hoje;

      await Promise.all([
        popularCombo(el('empEmpresa'),       { acao: 'combo_empresas' }),
        popularCombo(el('empBanco'),         { acao: 'combo_bancos' }),
        popularCombo(el('empPlanoReceita'),  { acao: 'combo_plano_contas' }),
        popularCombo(el('empPlanoDespesa'),  { acao: 'combo_plano_contas' }),
        popularCombo(el('empCentroDespesa'), { acao: 'combo_centro_custo' }),
      ]);
      modalEmp?.show();
    }

    el('btnSalvarEmprestimo')?.addEventListener('click', async () => {
      const payload = {
        acao: 'salvar_emprestimo',
        EMPRESA_FK:        el('empEmpresa').value,
        BANCO_FK:          el('empBanco').value,
        CREDOR_NOME:       el('empCredor').value.trim(),
        CREDOR_DOC:        el('empCredorDoc').value.trim(),
        DATA_ENTRADA:      el('empData').value,
        VALOR_TOTAL:       el('empValorTotal').value,
        DOCUMENTO:         el('empDocumento').value.trim(),
        PLANO_RECEITA_FK:  el('empPlanoReceita').value,
        QTD_PARCELAS:      el('empQtdParcelas').value,
        PRIMEIRO_VENCIMENTO: el('empPrimeiroVenc').value,
        VALOR_PARCELA:     el('empValorParcela').value,
        PLANO_DESPESA_FK:  el('empPlanoDespesa').value,
        CENTRO_DESPESA_FK: el('empCentroDespesa').value,
        FORMA_PAGAMENTO:   el('empFormaPgto').value.trim(),
        OBSERVACAO:        el('empObs').value.trim(),
      };
      const data = await apiPost(payload);
      if (!data.ok) {
        Swal.fire({ icon: 'error', title: 'Não foi possível gravar', text: data.msg || data.detail || 'Erro.' });
        return;
      }
      modalEmp?.hide();
      Swal.fire({
        icon: 'success',
        title: 'Empréstimo registrado',
        text: 'CRE #' + data.cre_id + ' + ' + data.parcelas + ' parcela(s) em Contas a Pagar.',
        timer: 2200,
        showConfirmButton: false
      });
      if (typeof listar === 'function') await listar();
    });

    // ---------- Modal Aporte ----------
    const modalApEl = el('modalAporte');
    const modalAp = window.bootstrap?.Modal ? bootstrap.Modal.getOrCreateInstance(modalApEl) : null;
    bindMoeda(el('apValor'));

    async function abrirModalAporte() {
      ['apSocio','apSocioDoc','apDocumento','apObs'].forEach(id => { const e = el(id); if (e) e.value = ''; });
      el('apValor').value = '0,00';
      const hoje = new Date().toISOString().slice(0,10);
      el('apData').value = hoje;

      await Promise.all([
        popularCombo(el('apEmpresa'), { acao: 'combo_empresas' }),
        popularCombo(el('apBanco'),   { acao: 'combo_bancos' }),
        popularCombo(el('apPlano'),   { acao: 'combo_plano_contas' }),
        popularCombo(el('apCentro'),  { acao: 'combo_centro_custo' }),
      ]);
      modalAp?.show();
    }

    el('btnSalvarAporte')?.addEventListener('click', async () => {
      const payload = {
        acao: 'salvar_aporte',
        EMPRESA_FK:       el('apEmpresa').value,
        BANCO_FK:         el('apBanco').value,
        SOCIO_NOME:       el('apSocio').value.trim(),
        SOCIO_DOC:        el('apSocioDoc').value.trim(),
        DATA_ENTRADA:     el('apData').value,
        VALOR:            el('apValor').value,
        DOCUMENTO:        el('apDocumento').value.trim(),
        PLANO_CONTAS_FK:  el('apPlano').value,
        CENTRO_CUSTO_FK:  el('apCentro').value,
        OBSERVACAO:       el('apObs').value.trim(),
      };
      const data = await apiPost(payload);
      if (!data.ok) {
        Swal.fire({ icon: 'error', title: 'Não foi possível gravar', text: data.msg || data.detail || 'Erro.' });
        return;
      }
      modalAp?.hide();
      Swal.fire({ icon: 'success', title: 'Aporte registrado', timer: 1800, showConfirmButton: false });
      if (typeof listar === 'function') await listar();
    });

    async function verDetalhes(id) {
      const data = await apiGet({ acao: 'get', id });
      if (!data.ok || !data.row) {
        Swal.fire({ icon: 'error', title: 'Erro', text: data.msg || 'Não foi possível carregar o registro.' });
        return;
      }
      const r = data.row;
      const esc = (v) => (v === null || v === undefined || v === '') ? '—' :
        String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
      const dt = (v) => v ? fmtBR(String(v).slice(0,10)) : '—';
      const dinheiro = (v) => (v === null || v === undefined || v === '') ? '—' : brl(v);

      const pago = statusPago(r.CRE_STATUS) || (Number(r.CRE_VALOR_RECEBIDO || 0) > 0);
      const parcN = Number(r.CRE_PARCELA_NUM || 0);
      const parcT = Number(r.CRE_PARCELA_TOTAL || 0);
      const parcela = (parcN && parcT) ? `${String(parcN).padStart(2,'0')}/${String(parcT).padStart(2,'0')}` : '—';

      const html = `
        <div class="text-start">
          <div class="detail-card">
            <h6><i class="bi bi-receipt me-1"></i>Lançamento</h6>
            <div class="detail-grid">
              <div class="lbl">ID</div>                 <div class="val mono">#${esc(r.CRE_ID)}</div>
              <div class="lbl">Status</div>             <div class="val">${esc(r.CRE_STATUS)}</div>
              <div class="lbl">Origem</div>             <div class="val">${esc(r.CRE_ORIGEM)}</div>
              <div class="lbl">Cadastrado em</div>      <div class="val mono">${dt(r.CRE_CREATED_AT)}</div>
              <div class="lbl">Vencimento</div>         <div class="val mono">${dt(r.CRE_VENCIMENTO)}</div>
              <div class="lbl">Competência</div>        <div class="val mono">${esc(r.CRE_COMPETENCIA)}</div>
              <div class="lbl">Parcela</div>            <div class="val mono">${esc(parcela)}</div>
              <div class="lbl">Cliente</div>            <div class="val">${esc(r.CRE_CLIENTE_NOME)}</div>
              <div class="lbl">CPF/CNPJ</div>           <div class="val mono">${esc(r.CRE_CPF_CNPJ)}</div>
              <div class="lbl">NF</div>                 <div class="val mono">${esc(r.CRE_NF)}</div>
              <div class="lbl">Documento</div>          <div class="val mono">${esc(r.CRE_DOCUMENTO)}</div>
              <div class="lbl">Forma de cobrança</div>  <div class="val">${esc(r.CRE_FORMA_COBRANCA)}</div>
              <div class="lbl">Valor</div>              <div class="val mono">${dinheiro(r.CRE_VALOR)}</div>
            </div>
          </div>

          <div class="detail-card" style="background:${pago ? '#f0fdf4' : '#fef9c3'}; border-color:${pago ? '#bbf7d0' : '#fde68a'}">
            <h6><i class="bi bi-cash-coin me-1"></i>Recebimento ${pago ? '' : '<span class="text-warning">(pendente)</span>'}</h6>
            ${pago ? `
              <div class="detail-grid">
                <div class="lbl">Valor recebido</div>   <div class="val mono">${dinheiro(r.CRE_VALOR_RECEBIDO)}</div>
                <div class="lbl">Saldo restante</div>   <div class="val mono">${dinheiro(Math.max(0, Number(r.CRE_VALOR || 0) - Number(r.CRE_VALOR_RECEBIDO || 0)))}</div>
                <div class="lbl">Recebido em</div>      <div class="val mono">${dt(r.CRE_RECEBIDO_EM)}</div>
                <div class="lbl">Tipo</div>             <div class="val">${esc(r.CRE_TIPO_RECEBIMENTO)}</div>
                <div class="lbl">Banco (FK)</div>       <div class="val mono">${esc(r.CRE_BANCO_FK)}</div>
              </div>
            ` : `<div class="text-muted small">Lançamento ainda não foi recebido.</div>`}
          </div>

          ${r.CRE_OBSERVACAO ? `
          <div class="detail-card">
            <h6><i class="bi bi-chat-left-text me-1"></i>Observação</h6>
            <div class="detail-grid"><div class="full">${esc(r.CRE_OBSERVACAO)}</div></div>
          </div>` : ''}
        </div>
      `;

      Swal.fire({
        title: `Detalhes do recebimento #${esc(r.CRE_ID)}`,
        html,
        width: 640,
        confirmButtonText: 'Fechar',
        confirmButtonColor: '#6b7280',
      });
    }

    async function abrirEdicao(id) {
      const data = await apiGet({
        acao: 'get',
        id
      });
      if (!data.ok) {
        Swal.fire({
          icon: 'error',
          title: 'Erro',
          text: data.msg || 'Erro no banco.'
        });
        return;
      }

      const r = data.row || {};
      if (statusPago(r.CRE_STATUS) && PERMITE_EXCLUIR_PARCELAS_PAGAS !== 'S') {
        alertaParcelaPaga();
        return;
      }

      mTitle.textContent = 'Editar recebimento';

      mId.value = r.CRE_ID || '';
      mOrigem.value = r.CRE_ORIGEM || 'AVULSO';
      mContrato.value = r.CRE_CONTRATO_FK || '';
      mCompetencia.value = r.CRE_COMPETENCIA ? String(r.CRE_COMPETENCIA).slice(0, 7) : '';
      mVencimento.value = r.CRE_VENCIMENTO || '';

      mEmpresa.value = r.CRE_EMPRESA_FK || '';
      await carregarPlanoECentroPorEmpresa(mEmpresa.value, r.CRE_PLANO_CONTAS_FK || '', r.CRE_CENTRO_CUSTO_FK || '');
      mBanco.value = r.CRE_BANCO_FK || '';

      mClienteId.value = r.CRE_CLIENTE_FK || '';
      mCliente.value = r.CRE_CLIENTE_NOME || '';
      mCpfCnpj.value = r.CRE_CPF_CNPJ || '';
      mValor.value = r.CRE_VALOR || '';
      setSelectByValueOrText(mForma, r.CRE_FORMA_COBRANCA || '');
      mDocumento.value = r.CRE_DOCUMENTO || '';
      mRecebidoEm.value = r.CRE_RECEBIDO_EM || '';
      mStatus.value = r.CRE_STATUS || 'ABERTO';
      mObs.value = r.CRE_OBSERVACAO || '';

      btnExcluir.classList.remove('d-none');
      modalReceber?.show();
    }

    btnSalvar.onclick = async () => {
      if (!mVencimento.value) {
        Swal.fire({
          icon: 'warning',
          title: 'Informe o vencimento'
        });
        return;
      }
      if (!mEmpresa.value) {
        Swal.fire({
          icon: 'warning',
          title: 'Selecione a empresa'
        });
        return;
      }
      if (!mCliente.value.trim() && !mClienteId.value) {
        Swal.fire({
          icon: 'warning',
          title: 'Informe o cliente'
        });
        return;
      }

      const valorNormalizado = String(mValor.value || '0').replace(/\./g, '').replace(',', '.');
      const v = Number(valorNormalizado);
      if (!mValor.value || isNaN(v) || v <= 0) {
        Swal.fire({
          icon: 'warning',
          title: 'Informe o valor'
        });
        return;
      }

      const payload = {
        acao: 'salvar',
        CRE_ID: mId.value,
        CRE_ORIGEM: mOrigem.value,
        CRE_CONTRATO_FK: (mOrigem.value === 'CONTRATO' ? (mContrato.value || '') : ''),
        CRE_EMPRESA_FK: mEmpresa.value,
        CRE_PLANO_CONTAS_FK: mPlano.value,
        CRE_CENTRO_CUSTO_FK: mCentro.value,
        CRE_BANCO_FK: mBanco.value,
        CRE_COMPETENCIA: mCompetencia.value,
        CRE_VENCIMENTO: mVencimento.value,
        CRE_CLIENTE_FK: mClienteId.value,
        CRE_CLIENTE_NOME: mCliente.value.trim(),
        CRE_CPF_CNPJ: (mCpfCnpj.value || '').trim(),
        CRE_VALOR: mValor.value,
        CRE_FORMA_COBRANCA: (mForma.options[mForma.selectedIndex]?.dataset?.nome || mForma.value || ''),
        CRE_DOCUMENTO: mDocumento.value.trim(),
        CRE_RECEBIDO_EM: mRecebidoEm.value,
        CRE_STATUS: mStatus.value,
        CRE_OBSERVACAO: mObs.value.trim()
      };

      const data = await apiPost(payload);
      if (!data.ok) {
        console.error(data);
        Swal.fire({
          icon: 'error',
          title: 'Erro',
          text: data.msg || 'Erro no banco.'
        });
        return;
      }

      modalReceber?.hide();
      await listar();
      Swal.fire({
        icon: 'success',
        title: 'Salvo!',
        timer: 1200,
        showConfirmButton: false
      });
    };

    btnExcluir.onclick = async () => {
      if (!mId.value) return;

      const resp = await Swal.fire({
        icon: 'warning',
        title: 'Excluir?',
        text: 'Confirma excluir este título?',
        showCancelButton: true,
        confirmButtonText: 'Sim, excluir',
        cancelButtonText: 'Cancelar'
      });
      if (!resp.isConfirmed) return;

      const data = await apiPost({
        acao: 'excluir',
        id: mId.value
      });
      if (!data.ok) {
        Swal.fire({
          icon: 'error',
          title: 'Erro',
          text: data.msg || 'Erro no banco.'
        });
        return;
      }
      modalReceber?.hide();
      await listar();
      Swal.fire({
        icon: 'success',
        title: 'Excluído!',
        timer: 1200,
        showConfirmButton: false
      });
    };

    function atualizarModoValorPago() {
      const tipo = String(bxTipoRecebimento?.value || 'INTEGRAL').toUpperCase();
      const valorParcela = String(bxValorParcela?.value || '0,00').trim();
      const saldoRestante = Number((bxValorParcela?.dataset?.saldo || '0').replace(',', '.')) || 0;
      if (tipo === 'INTEGRAL') {
        bxValorPago.value = brl(saldoRestante > 0 ? saldoRestante : 0);
        bxValorPago.readOnly = true;
        if (bxValorPagoHelp) bxValorPagoHelp.textContent = 'No modo integral, este valor será igual ao total.';
      } else {
        bxValorPago.readOnly = false;
        if (!bxValorPago.value || bxValorPago.value === valorParcela || bxValorPago.value === brl(saldoRestante > 0 ? saldoRestante : 0)) bxValorPago.value = '';
        if (bxValorPagoHelp) bxValorPagoHelp.textContent = 'No modo parcial, informe somente o valor efetivamente recebido.';
      }
    }

    bxTipoRecebimento?.addEventListener('change', atualizarModoValorPago);
    bxValorPago?.addEventListener('blur', () => {
      if (typeof aplicarMascaraMoedaBR === 'function') aplicarMascaraMoedaBR(bxValorPago);
    });

    window.abrirBaixaManual = async function(id) {
      bxId.value = String(id || '');
      bxForma.value = '';
      bxTipoRecebimento.value = 'INTEGRAL';
      bxLancamento.value = '';
      bxBanco.value = '';
      bxValorParcela.value = '0,00';
      bxValorParcela.dataset.total = '0';
      bxValorParcela.dataset.recebido = '0';
      bxValorParcela.dataset.saldo = '0';
      bxValorPago.value = '';
      bxObs.value = '';
      bxRecebidoEm.value = new Date().toISOString().slice(0, 10);

      try {
        const data = await apiGet({
          acao: 'get',
          id
        });
        if (data && data.ok && data.row) {
          const r = data.row;
          if (statusPago(r.CRE_STATUS)) {
            alertaParcelaPaga();
            return;
          }
          bxLancamento.value = r.CRE_CLIENTE_NOME || r.CRE_DOCUMENTO || ('Lançamento #' + (r.CRE_ID || ''));
          const totalParcela = Number(r.CRE_VALOR || 0);
          const recebidoAtual = Number(r.CRE_VALOR_RECEBIDO || 0);
          const saldoAtual = Math.max(0, totalParcela - recebidoAtual);
          bxValorParcela.value = brl(totalParcela);
          bxValorParcela.dataset.total = String(totalParcela || 0);
          bxValorParcela.dataset.recebido = String(recebidoAtual || 0);
          bxValorParcela.dataset.saldo = String(saldoAtual || totalParcela || 0);
          if (r.CRE_FORMA_COBRANCA) setSelectByValueOrText(bxForma, r.CRE_FORMA_COBRANCA);
          if (r.CRE_BANCO_FK) bxBanco.value = String(r.CRE_BANCO_FK);
          if (r.CRE_RECEBIDO_EM) bxRecebidoEm.value = r.CRE_RECEBIDO_EM;
          if (recebidoAtual > 0 && saldoAtual > 0) bxTipoRecebimento.value = 'PARCIAL';
        }
      } catch (e) {}

      atualizarModoValorPago();
      modalBaixaManual?.show();
    };

    btnConfirmarBaixaManual.onclick = async () => {
      if (!bxId.value) {
        Swal.fire({
          icon: 'warning',
          title: 'Selecione um registro.'
        });
        return;
      }
      if (!bxBanco.value) {
        Swal.fire({
          icon: 'warning',
          title: 'Selecione o banco de recebimento.'
        });
        return;
      }
      if (!bxRecebidoEm.value) {
        Swal.fire({
          icon: 'warning',
          title: 'Informe a data de recebimento.'
        });
        return;
      }

      const valorPago = String(bxValorPago.value || '').trim();
      const tipo = String(bxTipoRecebimento.value || 'INTEGRAL').toUpperCase();
      if (tipo === 'PARCIAL' && !valorPago) {
        Swal.fire({
          icon: 'warning',
          title: 'Informe o valor pago.'
        });
        return;
      }

      const data = await apiPost({
        acao: 'baixar_manual',
        CRE_ID: bxId.value,
        CRE_BANCO_FK: bxBanco.value,
        CRE_FORMA_COBRANCA: (bxForma.options[bxForma.selectedIndex]?.dataset?.nome || bxForma.value || ''),
        CRE_TIPO_RECEBIMENTO: bxTipoRecebimento.value,
        CRE_VALOR_RECEBIDO: bxValorPago.value,
        CRE_RECEBIDO_EM: bxRecebidoEm.value,
        CRE_OBSERVACAO: bxObs.value
      });

      if (!data.ok) {
        Swal.fire({
          icon: 'error',
          title: 'Erro',
          text: data.msg || 'Erro ao receber parcela.'
        });
        return;
      }

      modalBaixaManual?.hide();
      await listar();
      Swal.fire({
        icon: 'success',
        title: 'Recebimento realizado!',
        timer: 1200,
        showConfirmButton: false
      });
    };

    window.abrirReabrirContaReceber = async function(id) {
      if (!id) return;

      const { value: formValues, isConfirmed } = await Swal.fire({
        title: 'Reabrir conta recebida?',
        icon: 'warning',
        html: `
          <div class="text-start small">
            <p class="mb-2"><strong>Atenção!</strong> Ao reabrir esta conta:</p>
            <ul class="ps-3 mb-2">
              <li>O <b>valor recebido</b> será zerado.</li>
              <li>O <b>banco recebedor</b> será desvinculado.</li>
              <li>Eventuais <b>vínculos OFX</b> serão cancelados automaticamente.</li>
              <li>Status volta para <b>ABERTO</b>.</li>
            </ul>
            <p class="mb-2 text-danger">Somente um <b>usuário ADMIN</b> pode autorizar.</p>
            <label class="form-label small fw-bold mt-2">Motivo (opcional)</label>
            <input id="swal-motivo-cr" class="form-control form-control-sm" placeholder="Motivo da reabertura">
            <label class="form-label small fw-bold mt-2">Senha de um ADMIN <span class="text-danger">*</span></label>
            <input id="swal-senha-cr" type="password" class="form-control form-control-sm" placeholder="Senha ADMIN">
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Reabrir conta',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f0ad4e',
        focusConfirm: false,
        preConfirm: () => {
          const senha = document.getElementById('swal-senha-cr').value.trim();
          const motivo = document.getElementById('swal-motivo-cr').value.trim();
          if (!senha) {
            Swal.showValidationMessage('Senha obrigatória.');
            return false;
          }
          return { senha, motivo };
        }
      });

      if (!isConfirmed || !formValues) return;

      try {
        const j = await apiPost({ acao: 'reabrir_conta', id, senha: formValues.senha, motivo: formValues.motivo });
        if (!j.ok) {
          Swal.fire({ icon: 'error', title: 'Não foi possível reabrir', text: j.msg || 'Erro desconhecido.' });
          return;
        }
        await Swal.fire({
          icon: 'success',
          title: 'Conta reaberta',
          text: `Autorizado por ${j.autorizado_por}. ${j.msg}`,
          timer: 3000
        });
        await listar();
      } catch (err) {
        Swal.fire({ icon: 'error', title: 'Erro', text: err.message || String(err) });
      }
    };

    async function excluirRegistro(id) {
      const resp = await Swal.fire({
        icon: 'warning',
        title: 'Excluir?',
        text: 'Confirma excluir este título?',
        showCancelButton: true,
        confirmButtonText: 'Sim, excluir',
        cancelButtonText: 'Cancelar'
      });
      if (!resp.isConfirmed) return;

      const data = await apiPost({
        acao: 'excluir',
        id
      });
      if (!data.ok) {
        Swal.fire({
          icon: 'error',
          title: 'Erro',
          text: data.msg || 'Erro no banco.'
        });
        return;
      }
      await listar();
      Swal.fire({
        icon: 'success',
        title: 'Excluído!',
        timer: 1200,
        showConfirmButton: false
      });
    }

    let _dbTmr = null;
    function debounceList(delay = 500) {
      clearTimeout(_dbTmr);
      _dbTmr = setTimeout(() => {
        window.__silentFetch = true;
        Promise.resolve().then(listar).finally(() => { window.__silentFetch = false; });
      }, delay);
    }
    [fBusca, fValorMin, fValorMax].filter(Boolean).forEach(elm => {
      elm.addEventListener('input', () => { STATE.page = 1; debounceList(500); });
    });
    [fDtIni, fDtFim].filter(Boolean).forEach(elm => {
      elm.addEventListener('change', () => { STATE.page = 1; debounceList(50); });
    });
    [fOrigem, fParcelas, fEmpresa, fTipoRecebimento, fTipoData].filter(Boolean).forEach(elm => {
      elm.addEventListener('change', () => { STATE.page = 1; debounceList(50); });
    });
    if (fStatus) {
      fStatus.addEventListener('change', () => {
        // Atrasado é tudo do passado até hoje — limpa a data inicial pra não restringir.
        if (fStatus.value === 'ATRASADO') {
          if (typeof fDtIni !== 'undefined' && fDtIni) fDtIni.value = '';
        }
        STATE.page = 1;
        debounceList(50);
      });
    }
    if (btnLimparFiltros) btnLimparFiltros.addEventListener('click', limparTodosFiltros);

    if (btnExportar) btnExportar.addEventListener('click', () => {
      const f = coletarFiltros();
      const params = new URLSearchParams(Object.assign({ acao: 'listar', per_page: '9999' }, f));
      apiGet(Object.fromEntries(params)).then(data => {
        if (!data.ok || !data.rows || !data.rows.length) return;
        const cols = ['CRE_ID','CRE_STATUS','CRE_CREATED_AT','CRE_VENCIMENTO','CRE_CLIENTE_NOME','CRE_DOCUMENTO','CRE_NF','CRE_VALOR','CRE_VALOR_RECEBIDO','CRE_RECEBIDO_EM'];
        const header = cols.join(';');
        const lines = data.rows.map(r => cols.map(c => String(r[c] ?? '').replace(/;/g,',')).join(';'));
        const csv = '\uFEFF' + header + '\n' + lines.join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = 'contas_receber.csv'; a.click();
        URL.revokeObjectURL(url);
      });
    });

    perPage.onchange = () => {
      STATE.per = Number(perPage.value || 50);
      STATE.page = 1;
      listar();
    };

    let tmr = null;
    mCliente.addEventListener('input', () => {
      clearTimeout(tmr);
      const q = mCliente.value.trim();
      mClienteId.value = '';
      mCpfCnpj.value = '';

      if (!q) {
        acCliente.classList.add('d-none');
        return;
      }

      tmr = setTimeout(async () => {
        const data = await apiGet({
          acao: 'autocomplete_cliente',
          q,
          limit: 10
        });
        if (!data.ok) {
          acCliente.classList.add('d-none');
          return;
        }

        const rows = data.rows || [];
        if (!rows.length) {
          acCliente.classList.add('d-none');
          return;
        }

        acCliente.innerHTML = rows.map(r => `
          <button type="button" class="list-group-item list-group-item-action"
            data-id="${r.id}" data-nome="${(r.nome||'').replace(/"/g,'&quot;')}" data-doc="${(r.cpf_cnpj||'').replace(/"/g,'&quot;')}">
            <div class="d-flex justify-content-between">
              <div>${r.nome}</div>
              <div class="text-muted mono">${r.cpf_cnpj||''}</div>
            </div>
          </button>
        `).join('');

        acCliente.classList.remove('d-none');
      }, 250);
    });

    acCliente.addEventListener('click', async (e) => {
      const btn = e.target.closest('button[data-id]');
      if (!btn) return;

      mClienteId.value = btn.dataset.id || '';
      mCliente.value = btn.dataset.nome || '';
      mCpfCnpj.value = btn.dataset.doc || '';
      acCliente.classList.add('d-none');

      if (!mCpfCnpj.value && mClienteId.value) {
        try {
          const d = await apiGet({
            acao: 'cliente_doc',
            id: mClienteId.value
          });
          if (d && d.ok) mCpfCnpj.value = d.documento || '';
        } catch (e) {}
      }
    });

    document.addEventListener('click', (e) => {
      if (!acCliente.contains(e.target) && e.target !== mCliente) acCliente.classList.add('d-none');
    });

    mContrato.addEventListener('change', () => {
      if (mOrigem.value !== 'CONTRATO') return;
      const opt = mContrato.selectedOptions[0];
      if (!opt) return;

      const cliId = opt.getAttribute('data-cliente-id') || '';
      const cliNome = opt.getAttribute('data-cliente-nome') || '';

      if (cliId && !mClienteId.value) {
        mClienteId.value = cliId;
        mCliente.value = cliNome;
      }
    });

    mEmpresa.addEventListener('change', async () => {
      await carregarPlanoECentroPorEmpresa(mEmpresa.value);
    });

    (async function init() {
      // Restaurar filtros salvos OU pré-preencher mês atual (default)
      const filtrosSalvos = carregarFiltrosSalvos();
      if (filtrosSalvos) {
        if ('q'         in filtrosSalvos) fBusca.value    = filtrosSalvos.q || '';
        if ('status'    in filtrosSalvos) fStatus.value   = filtrosSalvos.status || '';
        if ('dtIni'     in filtrosSalvos) fDtIni.value    = filtrosSalvos.dtIni || '';
        if ('dtFim'     in filtrosSalvos) fDtFim.value    = filtrosSalvos.dtFim || '';
        if (fOrigem   && 'origem'    in filtrosSalvos) fOrigem.value   = filtrosSalvos.origem || '';
        if (fParcelas && 'parcelas'  in filtrosSalvos) fParcelas.value = filtrosSalvos.parcelas || '';
        if (fTipoRecebimento && 'tipo_recebimento' in filtrosSalvos) fTipoRecebimento.value = filtrosSalvos.tipo_recebimento || '';
        if (fTipoData && 'tipo_data' in filtrosSalvos) fTipoData.value = filtrosSalvos.tipo_data || 'vencimento';
        if (fValorMin && 'valor_min' in filtrosSalvos) fValorMin.value = filtrosSalvos.valor_min || '';
        if (fValorMax && 'valor_max' in filtrosSalvos) fValorMax.value = filtrosSalvos.valor_max || '';
        // empresa será aplicada após carregarEmpresas (abaixo)
      } else {
        // Default: mês atual
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        fDtIni.value = `${y}-${m}-01`;
        const lastDay = new Date(y, now.getMonth() + 1, 0).getDate();
        fDtFim.value = `${y}-${m}-${String(lastDay).padStart(2, '0')}`;
      }

      // Carregar combos do modal e filtro de empresas
      await Promise.all([
        carregarFormasEContratosEBancos(),
        carregarEmpresas(),
        carregarPlanoECentroPorEmpresa('')
      ]);

      // Aplicar empresa salva (após carregarEmpresas popular o select)
      if (filtrosSalvos && fEmpresa && filtrosSalvos.empresa) {
        fEmpresa.value = filtrosSalvos.empresa;
      }

      await listar();
    })();


    const campoValor = document.getElementById("mValor");

    function mascaraMoedaBR(input) {

      let valor = input.value.replace(/\D/g, '');

      if (!valor) {
        input.value = "0,00";
        return;
      }

      valor = (parseInt(valor, 10) / 100).toFixed(2);

      valor = valor
        .replace(".", ",")
        .replace(/\B(?=(\d{3})+(?!\d))/g, ".");

      input.value = valor;
    }

    campoValor.addEventListener("input", function() {
      mascaraMoedaBR(this);
    });

    /*
    COLOCA VALOR PADRÃO 0,00 AO RECEBER FOCO
    campoValor.addEventListener("focus", function() {
      if (!this.value) {
        this.value = "0,00";
      }
    });
    */

    campoValor.addEventListener("blur", function() {
      if (!this.value) {
        this.value = "0,00";
      }
    });

    // ── Boleto Banco do Brasil ──────────────────────────────────────────────

    let _boletoBBCreId = null;
    const _modalBoletoBB = window.bootstrap?.Modal
      ? bootstrap.Modal.getOrCreateInstance(document.getElementById('modalBoletoBB'))
      : null;

    window.abrirBoletoBB = async function(id) {
      _boletoBBCreId = id;
      const body = document.getElementById('boletoBBBody');
      const btnGerar = document.getElementById('btnGerarBoleto');
      const btnLinha = document.getElementById('btnCopiarLinha');
      const btnPix   = document.getElementById('btnCopiarPix');

      btnGerar.classList.add('d-none');
      btnLinha.classList.add('d-none');
      btnPix.classList.add('d-none');
      body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Consultando boleto...</p></div>';
      _modalBoletoBB?.show();

      const data = await apiGet({ acao: 'consultar_boleto_bb', id });

      if (!data.ok && !data.exists) {
        // Nenhum boleto ainda
        body.innerHTML = `
          <div class="text-center py-4">
            <i class="bi bi-upc-scan" style="font-size:3rem;color:#002060;"></i>
            <h5 class="mt-3">Nenhum boleto gerado</h5>
            <p class="text-muted">Este lançamento ainda não possui boleto registrado no Banco do Brasil.</p>
          </div>`;
        btnGerar.classList.remove('d-none');
        return;
      }

      if (!data.ok) {
        body.innerHTML = `<div class="alert alert-danger">${data.msg || 'Erro ao consultar boleto.'}</div>`;
        return;
      }

      _renderBoletoBB(body, data.boleto, btnLinha, btnPix);
    };

    function _renderBoletoBB(body, b, btnLinha, btnPix) {
      const fmtVal = (v) => Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
      const fmtDt  = (v) => v ? String(v).slice(0,10).split('-').reverse().join('/') : '—';
      const statusClasses = { EMITIDO: 'bg-primary', LIQUIDADO: 'bg-success', CANCELADO: 'bg-secondary', EXPIRADO: 'bg-warning text-dark' };
      const sc = statusClasses[b.BOL_STATUS] || 'bg-secondary';

      const pixBlock = b.BOL_QR_CODE_PIX ? `
        <div class="alert alert-info py-2">
          <strong><i class="bi bi-qr-code me-1"></i>Pix copia e cola:</strong>
          <div class="font-monospace small mt-1 text-break" id="pixPayload">${b.BOL_QR_CODE_PIX}</div>
        </div>` : '';

      const qrImgBlock = b.BOL_URL_IMAGE_QR ? `
        <div class="text-center mb-3">
          <img src="${b.BOL_URL_IMAGE_QR}" alt="QR Code Pix" style="max-width:200px;" class="border rounded p-2">
        </div>` : '';

      body.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-3">
          <span class="badge ${sc} fs-6">${b.BOL_STATUS}</span>
          <small class="text-muted">Emitido em: ${fmtDt(b.BOL_CREATED_AT)}</small>
        </div>
        <table class="table table-sm table-bordered">
          <tbody>
            <tr><th style="width:200px">Número do Boleto</th><td class="font-monospace">${b.BOL_NUMERO || '—'}</td></tr>
            <tr><th>Nosso Número</th><td class="font-monospace">${b.BOL_NOSSO_NUMERO || '—'}</td></tr>
            <tr><th>Valor</th><td class="font-monospace fw-bold">R$ ${fmtVal(b.BOL_VALOR)}</td></tr>
            <tr><th>Vencimento</th><td class="font-monospace">${fmtDt(b.BOL_VENCIMENTO)}</td></tr>
            ${b.BOL_PAGO_EM ? `<tr><th>Pago em</th><td class="font-monospace text-success fw-bold">${fmtDt(b.BOL_PAGO_EM)}</td></tr>` : ''}
            ${b.BOL_VALOR_PAGO ? `<tr><th>Valor Pago</th><td class="font-monospace text-success fw-bold">R$ ${fmtVal(b.BOL_VALOR_PAGO)}</td></tr>` : ''}
          </tbody>
        </table>
        ${b.BOL_LINHA_DIGITAVEL ? `
        <div class="mb-3">
          <label class="form-label fw-bold small">Linha Digitável</label>
          <div class="input-group">
            <input type="text" class="form-control font-monospace" id="linhaDigitavel" value="${b.BOL_LINHA_DIGITAVEL}" readonly>
          </div>
        </div>` : ''}
        ${b.BOL_CODIGO_BARRA ? `
        <div class="mb-3">
          <label class="form-label fw-bold small">Código de Barras</label>
          <div class="input-group">
            <input type="text" class="form-control font-monospace small" value="${b.BOL_CODIGO_BARRA}" readonly>
          </div>
        </div>` : ''}
        ${qrImgBlock}
        ${pixBlock}
      `;

      if (b.BOL_LINHA_DIGITAVEL) btnLinha.classList.remove('d-none');
      if (b.BOL_QR_CODE_PIX)     btnPix.classList.remove('d-none');

      const btnImprimir = document.getElementById('btnImprimirBoleto');
      if (b.BOL_LINHA_DIGITAVEL && b.BOL_STATUS === 'EMITIDO') {
        btnImprimir.classList.remove('d-none');
        btnImprimir._boleto = b;
      } else {
        btnImprimir.classList.add('d-none');
      }
    }

    window.confirmarGerarBoleto = async function() {
      if (!_boletoBBCreId) return;
      const body     = document.getElementById('boletoBBBody');
      const btnGerar = document.getElementById('btnGerarBoleto');
      const btnLinha = document.getElementById('btnCopiarLinha');
      const btnPix   = document.getElementById('btnCopiarPix');

      const conf = await Swal.fire({
        icon: 'question',
        title: 'Gerar boleto?',
        text: 'Será gerado e registrado um boleto no Banco do Brasil para este lançamento.',
        showCancelButton: true,
        confirmButtonText: 'Sim, gerar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#002060',
      });
      if (!conf.isConfirmed) return;

      btnGerar.disabled = true;
      btnGerar.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Gerando...';
      body.innerHTML = '<div class="text-center py-4"><div class="spinner-border" style="color:#002060;"></div><p class="mt-2 text-muted">Registrando boleto no Banco do Brasil...</p></div>';

      const data = await apiPost({ acao: 'gerar_boleto_bb', id: _boletoBBCreId });

      btnGerar.disabled = false;
      btnGerar.innerHTML = '<i class="bi bi-plus-circle me-1"></i>Gerar Boleto';

      if (!data.ok) {
        body.innerHTML = `<div class="alert alert-danger"><strong>Erro:</strong> ${data.msg || 'Falha ao gerar boleto.'}</div>`;
        return;
      }

      Swal.fire({ icon: 'success', title: 'Boleto gerado!', timer: 1500, showConfirmButton: false });
      btnGerar.classList.add('d-none');
      // Rebusca dados completos (com joins empresa/banco/cliente) para o botão imprimir
      const fullData = await apiGet({ acao: 'consultar_boleto_bb', id: _boletoBBCreId });
      _renderBoletoBB(body, fullData.ok ? fullData.boleto : data.boleto, btnLinha, btnPix);
    };

    window.copiarLinha = function() {
      const el = document.getElementById('linhaDigitavel');
      if (!el) return;
      navigator.clipboard?.writeText(el.value).then(() =>
        Swal.fire({ icon: 'success', title: 'Copiado!', text: 'Linha digitável copiada.', timer: 1200, showConfirmButton: false })
      ).catch(() => { el.select(); document.execCommand('copy'); });
    };

    window.copiarPix = function() {
      const el = document.getElementById('pixPayload');
      if (!el) return;
      navigator.clipboard?.writeText(el.textContent).then(() =>
        Swal.fire({ icon: 'success', title: 'Copiado!', text: 'Pix copia e cola copiado.', timer: 1200, showConfirmButton: false })
      ).catch(() => {});
    };

    window.imprimirBoleto = function() {
      const btn = document.getElementById('btnImprimirBoleto');
      const b   = btn._boleto;
      if (!b) return;

      const fmtVal = v => Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const fmtDt  = v => v ? String(v).slice(0, 10).split('-').reverse().join('/') : '';
      const esc    = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

      const beneficiario  = esc((b.BAN_CEDENTE_NOME  || b.EMP_RAZAO_SOCIAL || '').toUpperCase());
      const empCnpj       = esc(b.EMP_CNPJ || '');
      const agencia       = b.BAN_AGENCIA  ? b.BAN_AGENCIA + '-' + (b.BAN_AGENCIA_DV || '') : '';
      const conta         = b.BAN_CONTA    ? b.BAN_CONTA   + '-' + (b.BAN_CONTA_DV   || '') : '';
      const agConta       = agencia && conta ? agencia + ' / ' + conta : (agencia || conta);
      const instrucoes    = esc(b.BAN_INSTRUCOES || 'Não receber após o vencimento.');
      const pagador       = esc(b.CRE_CLIENTE_NOME || '');
      const pagadorDoc    = b.CRE_CPF_CNPJ || '';
      const pagadorDocFmt = pagadorDoc.length > 11
        ? pagadorDoc.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5')
        : pagadorDoc.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
      const endEmp = esc([b.EMP_LOGRADOURO, b.EMP_NUM, b.EMP_BAIRRO, b.EMP_CIDADE, b.EMP_UF].filter(Boolean).join(', '));

      const endEmpHtml = endEmp
        ? '<div class="row"><div class="cell" style="flex:1"><span class="cell-lbl">Endereço do Beneficiário</span><span class="cell-val" style="font-size:9px">' + endEmp + '</span></div></div>'
        : '';
      const barcode    = b.BOL_CODIGO_BARRA || '';
      const pixPayload = b.BOL_QR_CODE_PIX  || '';
      const pixSafeStr = pixPayload.replace(/\\/g,'\\\\').replace(/'/g,"\\'");

      const win = window.open('', '_blank', 'width=980,height=800');
      win.document.write(`<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Boleto BB – ${b.BOL_NOSSO_NUMERO || b.BOL_NUMERO}</title>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"><\/script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,Helvetica,sans-serif;font-size:10px;color:#000;background:#fff}
.pg{padding:8mm 10mm}
/* PIX topo */
.pix-top{border:1px solid #c9c9c9;border-radius:5px;padding:7px 10px;margin-bottom:7px;display:flex;gap:14px;align-items:center}
.pix-top-info{flex:1}
.pix-top-title{font-size:11px;font-weight:700;color:#00305A;margin-bottom:3px}
.pix-top-steps{font-size:8px;color:#444;line-height:1.7;padding-left:14px}
.pix-copia{font-family:"Courier New",monospace;font-size:7.5px;word-break:break-all;color:#555;background:#f5f5f5;border:1px solid #ddd;border-radius:3px;padding:3px 5px;margin-top:4px}
.pix-qr-wrap{flex:0 0 108px;text-align:center}
.pix-qr-wrap canvas,.pix-qr-wrap img{width:104px!important;height:104px!important}
/* cabeçalho */
.hdr{display:flex;align-items:center;border-bottom:3px solid #00305A;padding-bottom:5px;margin-bottom:0}
.hdr img.logo{height:38px;width:auto}
.hdr-cod{font-size:17px;font-weight:900;color:#00305A;border-left:3px solid #00305A;border-right:3px solid #00305A;padding:0 9px;margin:0 8px;line-height:1}
.hdr-linha{flex:1;text-align:right;font-size:11.5px;font-weight:700;font-family:"Courier New",monospace;letter-spacing:.5px}
/* células */
.section-box{border:1px solid #555;margin-top:-1px}
.row{display:flex;width:100%}
.cell{border:1px solid #555;padding:2px 5px;min-height:24px}
.cell+.cell{border-left:none}
.row+.row .cell{border-top:none}
.lbl{font-size:7px;font-weight:700;text-transform:uppercase;color:#444;display:block;margin-bottom:1px}
.val{font-size:10.5px;font-weight:600;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mono{font-family:"Courier New",monospace}
.big{font-size:12.5px;font-weight:700}
/* separador */
.corte{border-top:1px dashed #aaa;margin:7px 0;text-align:center;font-size:8px;color:#999;position:relative}
.corte span{background:#fff;padding:0 8px;position:relative;top:-1px}
/* instrucoes */
.instrucoes{font-size:8.5px;line-height:1.5;color:#333;white-space:pre-line}
@media print{@page{size:A4 portrait;margin:8mm 10mm}body{background:#fff}.pg{padding:0}}
</style>
</head>
<body><div class="pg">

${pixPayload ? '<div class="pix-top"><div class="pix-top-info"><div class="pix-top-title">Você pode pagar esse boleto usando PIX!</div><ol class="pix-top-steps"><li>Abra o aplicativo do seu banco</li><li>Escolha a opção de Pagamento por PIX</li><li>Escaneie o QR Code ou copie e cole o código</li><li>A confirmação é realizada em poucos minutos</li></ol><div class="pix-copia">' + esc(pixPayload) + '</div></div><div class="pix-qr-wrap"><div id="qr-pix"></div></div></div>' : ''}

<!-- CANHOTO -->
<div class="hdr" style="margin-bottom:5px">
  <img class="logo" src="data:image/png;base64,${bbLogoBase64}" alt="Banco do Brasil">
  <span class="hdr-cod">001-9</span>
  <span class="hdr-linha">${b.BOL_LINHA_DIGITAVEL || ''}</span>
</div>
<div class="section-box">
  <div class="row">
    <div class="cell" style="flex:3"><span class="lbl">Beneficiário</span><span class="val">${beneficiario}</span></div>
    <div class="cell" style="flex:1.4"><span class="lbl">Agência / Código do Beneficiário</span><span class="val mono">${agConta}</span></div>
    <div class="cell" style="flex:.85"><span class="lbl">Vencimento</span><span class="val mono big">${fmtDt(b.BOL_VENCIMENTO)}</span></div>
    <div class="cell" style="flex:.9;text-align:right"><span class="lbl">Valor do Documento (R$)</span><span class="val mono big">R$&nbsp;${fmtVal(b.BOL_VALOR)}</span></div>
  </div>
  <div class="row">
    <div class="cell" style="flex:2"><span class="lbl">Nosso Número</span><span class="val mono">${b.BOL_NOSSO_NUMERO || '—'}</span></div>
    <div class="cell" style="flex:1"><span class="lbl">Emissão</span><span class="val mono">${fmtDt(b.BOL_CREATED_AT)}</span></div>
    <div class="cell" style="flex:1"><span class="lbl">Espécie</span><span class="val">RC</span></div>
    <div class="cell" style="flex:.75;text-align:right"><span class="lbl">Valor Cobrado (R$)</span><span class="val"></span></div>
  </div>
  <div class="row">
    <div class="cell" style="flex:1"><span class="lbl">Sacado / Pagador</span><span class="val">${pagador} &nbsp;–&nbsp; CPF/CNPJ: ${pagadorDocFmt}</span></div>
  </div>
</div>

<div class="corte"><span>✂&nbsp;&nbsp;Recibo do Sacado&nbsp;&nbsp;✂</span></div>

<!-- FICHA DE COMPENSAÇÃO -->
<div class="hdr" style="margin-bottom:4px">
  <img class="logo" src="data:image/png;base64,${bbLogoBase64}" alt="Banco do Brasil">
  <span class="hdr-cod">001-9</span>
  <span class="hdr-linha">${b.BOL_LINHA_DIGITAVEL || ''}</span>
</div>
<div class="section-box">
  <div class="row">
    <div class="cell" style="flex:3"><span class="lbl">Beneficiário</span><span class="val">${beneficiario} – CNPJ: ${empCnpj}</span></div>
    <div class="cell" style="flex:1.4"><span class="lbl">Agência / Código do Beneficiário</span><span class="val mono">${agConta}</span></div>
    <div class="cell" style="flex:.85"><span class="lbl">Espécie Moeda</span><span class="val">R$</span></div>
    <div class="cell" style="flex:.9;text-align:right"><span class="lbl">Vencimento</span><span class="val mono big">${fmtDt(b.BOL_VENCIMENTO)}</span></div>
  </div>
  <div class="row">
    <div class="cell" style="flex:2"><span class="lbl">Nosso Número</span><span class="val mono">${b.BOL_NOSSO_NUMERO || '—'}</span></div>
    <div class="cell" style="flex:1.2"><span class="lbl">Número do Documento</span><span class="val mono">${b.CRE_DOCUMENTO || b.BOL_NUMERO || ''}</span></div>
    <div class="cell" style="flex:.9"><span class="lbl">Data do Documento</span><span class="val mono">${fmtDt(b.BOL_CREATED_AT)}</span></div>
    <div class="cell" style="flex:.6"><span class="lbl">Carteira</span><span class="val">17/019</span></div>
    <div class="cell" style="flex:.55"><span class="lbl">Espécie</span><span class="val">RC</span></div>
    <div class="cell" style="flex:.9;text-align:right"><span class="lbl">Valor do Documento (R$)</span><span class="val mono big">R$&nbsp;${fmtVal(b.BOL_VALOR)}</span></div>
  </div>
  <div class="row">
    <div class="cell" style="flex:1"><span class="lbl">Desconto / Abatimento</span><span class="val"></span></div>
    <div class="cell" style="flex:1"><span class="lbl">Outras Deduções</span><span class="val"></span></div>
    <div class="cell" style="flex:1"><span class="lbl">Mora / Multa</span><span class="val"></span></div>
    <div class="cell" style="flex:1"><span class="lbl">Outros Acréscimos</span><span class="val"></span></div>
    <div class="cell" style="flex:1;text-align:right"><span class="lbl">Valor Cobrado (R$)</span><span class="val"></span></div>
  </div>
  <div class="row">
    <div class="cell" style="flex:3;min-height:52px">
      <span class="lbl">Instruções</span>
      <div class="instrucoes" style="margin-top:2px">${instrucoes}</div>
    </div>
    <div class="cell" style="flex:1">
      <span class="lbl">Local de Pagamento</span>
      <span class="val" style="font-size:8.5px;white-space:normal">Pagável em qualquer banco até o vencimento.</span>
    </div>
  </div>
  <div class="row">
    <div class="cell" style="flex:1">
      <span class="lbl">Sacado / Pagador</span>
      <span class="val" style="font-size:11px;font-weight:700">${pagador}</span>
      <span class="val" style="font-size:9px;font-weight:400">CPF/CNPJ: ${pagadorDocFmt}</span>
    </div>
  </div>
  ${endEmpHtml}
</div>

<div style="margin-top:6px"><svg id="barcode"></svg></div>

</div><!-- .pg -->
<script>
window.onload = function() {
  var cod = '${barcode}';
  var pix = '${pixSafeStr}';
  if (cod) { JsBarcode('#barcode', cod, {format:'ITF',width:1.5,height:48,displayValue:false,margin:0}); }
  if (pix && typeof QRCode !== 'undefined') {
    new QRCode(document.getElementById('qr-pix'), {text:pix, width:104, height:104, correctLevel:QRCode.CorrectLevel.M});
  }
  setTimeout(function(){ window.print(); }, 900);
};
<\/script>
</body></html>`);
      win.document.close();
    };

  </script>

  <script src="assets/session_keeper.js" defer></script>
</body>

</html>