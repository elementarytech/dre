<?php
// /app/endpoints/empresas.php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/empresas_endpoint.log');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// trava: exige login
if (empty($_SESSION['user_id'])) {
    json_out(['ok' => false, 'msg' => 'Não autenticado.'], 401);
}

$acao = $_REQUEST['acao'] ?? '';

try {
    // ===== LISTAR =====
    if ($acao === 'listar') {
        $buscar = trim((string)($_GET['buscar'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $tipo   = trim((string)($_GET['tipo'] ?? ''));

        $w = [];
        $p = [];

        if ($buscar !== '') {
            $w[] = "(EMP_CODIGO LIKE ? OR EMP_RAZAO_SOCIAL LIKE ? OR EMP_NOME_FANTASIA LIKE ? OR EMP_CNPJ LIKE ?)";
            $like = '%' . $buscar . '%';
            $p[] = $like;
            $p[] = $like;
            $p[] = $like;
            $p[] = $like;
        }
        if ($status !== '') {
            $w[] = "EMP_STATUS = ?";
            $p[] = $status;
        }
        if ($tipo !== '') {
            $w[] = "EMP_TIPO = ?";
            $p[] = $tipo;
        }

        $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';

        $sql = "SELECT
                    EMP_ID, EMP_CODIGO, EMP_RAZAO_SOCIAL, EMP_NOME_FANTASIA, EMP_CNPJ,
                    EMP_TIPO, EMP_STATUS
                FROM tb_empresa
                $where
                ORDER BY EMP_ID DESC";

        $st = $pdo->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll();

        json_out(['ok' => true, 'rows' => $rows, 'total' => count($rows)]);
    }

    // ===== GET =====
    if ($acao === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("SELECT * FROM tb_empresa WHERE EMP_ID = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();

        if (!$row) json_out(['ok' => false, 'msg' => 'Empresa não encontrada.'], 404);

        json_out(['ok' => true, 'row' => $row]);
    }

    // ===== SALVAR =====
    if ($acao === 'salvar') {
        require_post();

        $id = (int)($_POST['EMP_ID'] ?? 0);

        // --- Dados cadastrais ---
        $EMP_CODIGO        = trim((string)($_POST['EMP_CODIGO'] ?? ''));
        $EMP_RAZAO_SOCIAL  = trim((string)($_POST['EMP_RAZAO_SOCIAL'] ?? ''));
        $EMP_NOME_FANTASIA = trim((string)($_POST['EMP_NOME_FANTASIA'] ?? ''));
        $EMP_CNPJ          = trim((string)($_POST['EMP_CNPJ'] ?? ''));
        $EMP_TIPO          = trim((string)($_POST['EMP_TIPO'] ?? 'MATRIZ'));
        $EMP_STATUS        = trim((string)($_POST['EMP_STATUS'] ?? 'ATIVO'));
        $EMP_EMAIL         = trim((string)($_POST['EMP_EMAIL'] ?? ''));
        $EMP_TELEFONE      = trim((string)($_POST['EMP_TELEFONE'] ?? ''));
        $EMP_SITE          = trim((string)($_POST['EMP_SITE'] ?? ''));
        $EMP_OBSERVACAO    = trim((string)($_POST['EMP_OBSERVACAO'] ?? ''));

        // --- Endereço ---
        $EMP_CEP         = trim((string)($_POST['EMP_CEP'] ?? ''));
        $EMP_LOGRADOURO  = trim((string)($_POST['EMP_LOGRADOURO'] ?? ''));
        $EMP_NUMERO      = trim((string)($_POST['EMP_NUMERO'] ?? ''));
        $EMP_COMPLEMENTO = trim((string)($_POST['EMP_COMPLEMENTO'] ?? ''));
        $EMP_BAIRRO      = trim((string)($_POST['EMP_BAIRRO'] ?? ''));
        $EMP_UF          = trim((string)($_POST['EMP_UF'] ?? ''));
        $EMP_PAIS        = trim((string)($_POST['EMP_PAIS'] ?? 'Brasil'));
        $EMP_CIDADE      = trim((string)($_POST['EMP_CIDADE'] ?? ''));
        $EMP_IBGE        = trim((string)($_POST['EMP_IBGE'] ?? ''));

        // --- Fiscal / Tributário ---
        $EMP_IE                = trim((string)($_POST['EMP_IE'] ?? ''));
        $EMP_IM                = trim((string)($_POST['EMP_IM'] ?? ''));
        $EMP_REGIME_TRIBUTARIO = trim((string)($_POST['EMP_REGIME_TRIBUTARIO'] ?? ''));
        $EMP_CNAE_PRINCIPAL    = trim((string)($_POST['EMP_CNAE_PRINCIPAL'] ?? ''));
        $EMP_NATUREZA_JURIDICA = trim((string)($_POST['EMP_NATUREZA_JURIDICA'] ?? ''));
        $EMP_OBSERVACAO_FISCAL = trim((string)($_POST['EMP_OBSERVACAO_FISCAL'] ?? ''));

        // --- Parâmetros financeiros ---
        $EMP_BANCO_PADRAO_BOLETO        = trim((string)($_POST['EMP_BANCO_PADRAO_BOLETO'] ?? ''));
        $EMP_CARTEIRA_COBRANCA          = trim((string)($_POST['EMP_CARTEIRA_COBRANCA'] ?? ''));
        $EMP_DIAS_TOLERANCIA_VENCIMENTO = (int)($_POST['EMP_DIAS_TOLERANCIA_VENCIMENTO'] ?? 0);
        $EMP_ENVIO_COBRANCA             = trim((string)($_POST['EMP_ENVIO_COBRANCA'] ?? 'EMAIL'));
        $EMP_PLANO_CONTAS_PADRAO        = trim((string)($_POST['EMP_PLANO_CONTAS_PADRAO'] ?? ''));
        $EMP_CENTRO_CUSTO_PADRAO        = trim((string)($_POST['EMP_CENTRO_CUSTO_PADRAO'] ?? ''));
        $EMP_TEXTO_PADRAO_DOCS          = trim((string)($_POST['EMP_TEXTO_PADRAO_DOCS'] ?? ''));

        if ($EMP_RAZAO_SOCIAL === '' || $EMP_CNPJ === '') {
            json_out(['ok' => false, 'msg' => 'Razão Social e CNPJ são obrigatórios.'], 422);
        }

        // normaliza enums se quiser (não obrigatório, mas ajuda)
        if (!in_array($EMP_TIPO, ['MATRIZ', 'FILIAL'], true)) $EMP_TIPO = 'MATRIZ';
        if (!in_array($EMP_STATUS, ['ATIVO', 'INATIVO'], true)) $EMP_STATUS = 'ATIVO';
        if (!in_array($EMP_ENVIO_COBRANCA, ['EMAIL', 'PORTAL', 'EMAIL_PORTAL'], true)) $EMP_ENVIO_COBRANCA = 'EMAIL';
        if ($EMP_REGIME_TRIBUTARIO !== '' && !in_array($EMP_REGIME_TRIBUTARIO, ['SIMPLES_NACIONAL', 'LUCRO_PRESUMIDO', 'LUCRO_REAL'], true)) {
            $EMP_REGIME_TRIBUTARIO = '';
        }

        // evita duplicidade de CNPJ
        $st = $pdo->prepare("SELECT EMP_ID FROM tb_empresa WHERE EMP_CNPJ = ? AND EMP_ID <> ? LIMIT 1");
        $st->execute([$EMP_CNPJ, $id]);
        if ($st->fetch()) {
            json_out(['ok' => false, 'msg' => 'Já existe uma empresa com este CNPJ.'], 409);
        }

        if ($id > 0) {
            // UPDATE (mesmas colunas do schema)
            $sql = "UPDATE tb_empresa SET
                        EMP_CODIGO=?,
                        EMP_RAZAO_SOCIAL=?,
                        EMP_NOME_FANTASIA=?,
                        EMP_CNPJ=?,

                        EMP_IE=?,
                        EMP_IM=?,
                        EMP_REGIME_TRIBUTARIO=?,
                        EMP_CNAE_PRINCIPAL=?,
                        EMP_NATUREZA_JURIDICA=?,
                        EMP_OBSERVACAO_FISCAL=?,

                        EMP_BANCO_PADRAO_BOLETO=?,
                        EMP_CARTEIRA_COBRANCA=?,
                        EMP_DIAS_TOLERANCIA_VENCIMENTO=?,
                        EMP_PLANO_CONTAS_PADRAO=?,
                        EMP_CENTRO_CUSTO_PADRAO=?,
                        EMP_ENVIO_COBRANCA=?,
                        EMP_TEXTO_PADRAO_DOCS=?,

                        EMP_TIPO=?,
                        EMP_STATUS=?,
                        EMP_EMAIL=?,
                        EMP_TELEFONE=?,
                        EMP_SITE=?,
                        EMP_OBSERVACAO=?,

                        EMP_CEP=?,
                        EMP_LOGRADOURO=?,
                        EMP_NUMERO=?,
                        EMP_COMPLEMENTO=?,
                        EMP_BAIRRO=?,
                        EMP_UF=?,
                        EMP_PAIS=?,
                        EMP_CIDADE=?,
                        EMP_IBGE=?
                    WHERE EMP_ID=?";

            $st = $pdo->prepare($sql);
            $st->execute([
                $EMP_CODIGO,
                $EMP_RAZAO_SOCIAL,
                $EMP_NOME_FANTASIA,
                $EMP_CNPJ,

                $EMP_IE,
                $EMP_IM,
                $EMP_REGIME_TRIBUTARIO !== '' ? $EMP_REGIME_TRIBUTARIO : null,
                $EMP_CNAE_PRINCIPAL,
                $EMP_NATUREZA_JURIDICA,
                $EMP_OBSERVACAO_FISCAL,

                $EMP_BANCO_PADRAO_BOLETO,
                $EMP_CARTEIRA_COBRANCA,
                $EMP_DIAS_TOLERANCIA_VENCIMENTO,
                $EMP_PLANO_CONTAS_PADRAO,
                $EMP_CENTRO_CUSTO_PADRAO,
                $EMP_ENVIO_COBRANCA,
                $EMP_TEXTO_PADRAO_DOCS,

                $EMP_TIPO,
                $EMP_STATUS,
                $EMP_EMAIL,
                $EMP_TELEFONE,
                $EMP_SITE,
                $EMP_OBSERVACAO,

                $EMP_CEP,
                $EMP_LOGRADOURO,
                $EMP_NUMERO,
                $EMP_COMPLEMENTO,
                $EMP_BAIRRO,
                $EMP_UF,
                $EMP_PAIS,
                $EMP_CIDADE,
                $EMP_IBGE,
                $id
            ]);
        } else {
            // INSERT (32 colunas = 32 valores)
            $sql = "INSERT INTO tb_empresa (
                        EMP_CODIGO, EMP_RAZAO_SOCIAL, EMP_NOME_FANTASIA, EMP_CNPJ,
                        EMP_IE, EMP_IM, EMP_REGIME_TRIBUTARIO, EMP_CNAE_PRINCIPAL, EMP_NATUREZA_JURIDICA, EMP_OBSERVACAO_FISCAL,
                        EMP_BANCO_PADRAO_BOLETO, EMP_CARTEIRA_COBRANCA, EMP_DIAS_TOLERANCIA_VENCIMENTO, EMP_PLANO_CONTAS_PADRAO, EMP_CENTRO_CUSTO_PADRAO, EMP_ENVIO_COBRANCA, EMP_TEXTO_PADRAO_DOCS,
                        EMP_TIPO, EMP_STATUS, EMP_EMAIL, EMP_TELEFONE, EMP_SITE, EMP_OBSERVACAO,
                        EMP_CEP, EMP_LOGRADOURO, EMP_NUMERO, EMP_COMPLEMENTO, EMP_BAIRRO, EMP_UF, EMP_PAIS, EMP_CIDADE, EMP_IBGE
                    ) VALUES (
                        ?,?,?,?,
                        ?,?,?,?,?,
                        ?,
                        ?,?,?,?,?,?,
                        ?,
                        ?,?,?,?,?,?,
                        ?,?,?,?,?,?,?,?,?
                    )";

            $st = $pdo->prepare($sql);
            $st->execute([
                $EMP_CODIGO,
                $EMP_RAZAO_SOCIAL,
                $EMP_NOME_FANTASIA,
                $EMP_CNPJ,
                $EMP_IE,
                $EMP_IM,
                ($EMP_REGIME_TRIBUTARIO !== '' ? $EMP_REGIME_TRIBUTARIO : null),
                $EMP_CNAE_PRINCIPAL,
                $EMP_NATUREZA_JURIDICA,
                $EMP_OBSERVACAO_FISCAL,
                $EMP_BANCO_PADRAO_BOLETO,
                $EMP_CARTEIRA_COBRANCA,
                $EMP_DIAS_TOLERANCIA_VENCIMENTO,
                $EMP_PLANO_CONTAS_PADRAO,
                $EMP_CENTRO_CUSTO_PADRAO,
                $EMP_ENVIO_COBRANCA,
                $EMP_TEXTO_PADRAO_DOCS,
                $EMP_TIPO,
                $EMP_STATUS,
                $EMP_EMAIL,
                $EMP_TELEFONE,
                $EMP_SITE,
                $EMP_OBSERVACAO,
                $EMP_CEP,
                $EMP_LOGRADOURO,
                $EMP_NUMERO,
                $EMP_COMPLEMENTO,
                $EMP_BAIRRO,
                $EMP_UF,
                $EMP_PAIS,
                $EMP_CIDADE,
                $EMP_IBGE
            ]);

            $id = (int)$pdo->lastInsertId();
        }

        json_out(['ok' => true, 'id' => $id, 'msg' => 'Salvo.']);
    }

    // ===== INATIVAR =====
    if ($acao === 'inativar') {
        require_post();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("UPDATE tb_empresa SET EMP_STATUS='INATIVO' WHERE EMP_ID=?");
        $st->execute([$id]);

        json_out(['ok' => true, 'msg' => 'Inativada.']);
    }

    // ===== REATIVAR =====
    if ($acao === 'reativar') {
        require_post();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("UPDATE tb_empresa SET EMP_STATUS='ATIVO' WHERE EMP_ID=?");
        $st->execute([$id]);

        json_out(['ok' => true, 'msg' => 'Reativada.']);
    }

    json_out(['ok' => false, 'msg' => 'Ação inválida.'], 400);
} catch (PDOException $e) {
    if ((int)$e->getCode() === 23000) {
        json_out(['ok' => false, 'msg' => 'CNPJ já cadastrado.'], 409);
    }
    json_out(['ok' => false, 'msg' => 'Erro no banco.', 'detail' => $e->getMessage()], 500);
} catch (Throwable $e) {
    json_out(['ok' => false, 'msg' => 'Erro interno.', 'detail' => $e->getMessage()], 500);
}
