<?php
// /app/endpoints/auditoria.php
// Consulta da trilha de auditoria (tb_auditoria), alimentada pelos triggers do banco.
declare(strict_types=1);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexao.php';

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) @ob_end_clean();
}
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// Auditoria é sensível → restrita a administradores.
require_admin();

function jout(array $p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Rótulos amigáveis das tabelas auditadas.
const AUD_LABELS = [
    'tb_contas_pagar' => 'Contas a Pagar',
    'tb_contas_receber' => 'Contas a Receber',
    'tb_rateio_contas_pagar' => 'Rateio (Contas a Pagar)',
    'tb_conciliacao_ajuste_saldo' => 'Ajuste de Saldo',
    'tb_conciliacao_ofx_movimento' => 'Movimento OFX',
    'tb_conciliacao_ofx_importacao' => 'Importação OFX',
    'tb_conciliacao_vinculo' => 'Vínculo Conciliação',
    'tb_conciliacao_resumo_conta' => 'Resumo Conciliação',
    'tb_transferencia_bancaria' => 'Transferência Bancária',
    'tb_transferencia_interna' => 'Transferência Interna',
    'tb_banco' => 'Bancos',
    'tb_plano_contas' => 'Plano de Contas',
    'tb_fornecedor' => 'Fornecedores',
    'tb_fluxo_caixa' => 'Fluxo de Caixa',
    'tb_fluxo_caixa_banco' => 'Fluxo de Caixa (Banco)',
    'contratos' => 'Contratos',
    'contrato_parcelas' => 'Parcelas de Contrato',
    'tb_forma_pagamento' => 'Formas de Pagamento',
    'tb_centro_custo' => 'Centros de Custo',
];

try {
    $acao = trim((string)($_REQUEST['acao'] ?? 'listar'));

    if ($acao === 'combos') {
        $usuarios = $pdo->query("
            SELECT AUD_USUARIO_ID id, AUD_USUARIO_NOME nome, COUNT(*) qtd
            FROM tb_auditoria
            WHERE AUD_USUARIO_NOME IS NOT NULL
            GROUP BY AUD_USUARIO_ID, AUD_USUARIO_NOME
            ORDER BY nome
        ")->fetchAll(PDO::FETCH_ASSOC);

        $tabelas = [];
        foreach ($pdo->query("SELECT AUD_TABELA t, COUNT(*) qtd FROM tb_auditoria GROUP BY AUD_TABELA ORDER BY qtd DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tabelas[] = ['tabela' => $r['t'], 'label' => AUD_LABELS[$r['t']] ?? $r['t'], 'qtd' => (int)$r['qtd']];
        }
        jout(['ok' => true, 'usuarios' => $usuarios, 'tabelas' => $tabelas, 'labels' => AUD_LABELS]);
    }

    if ($acao === 'listar') {
        $where = [];
        $par = [];

        $usuarioId = trim((string)($_GET['usuario_id'] ?? ''));
        if ($usuarioId !== '') { $where[] = 'AUD_USUARIO_ID = :uid'; $par[':uid'] = (int)$usuarioId; }

        $tabela = trim((string)($_GET['tabela'] ?? ''));
        if ($tabela !== '') { $where[] = 'AUD_TABELA = :tab'; $par[':tab'] = $tabela; }

        $registro = trim((string)($_GET['registro'] ?? ''));
        if ($registro !== '') { $where[] = 'AUD_REGISTRO_PK = :reg'; $par[':reg'] = $registro; }

        $acaoTipo = strtoupper(trim((string)($_GET['tipo'] ?? '')));
        if (in_array($acaoTipo, ['INSERT', 'UPDATE', 'DELETE'], true)) { $where[] = 'AUD_ACAO = :ac'; $par[':ac'] = $acaoTipo; }

        $origem = trim((string)($_GET['origem'] ?? ''));
        if ($origem === 'APP' || $origem === 'SQL/DIRETO') { $where[] = 'AUD_ORIGEM = :org'; $par[':org'] = $origem; }

        $de = trim((string)($_GET['de'] ?? ''));
        if ($de !== '') { $where[] = 'AUD_DATA_HORA >= :de'; $par[':de'] = $de . ' 00:00:00'; }
        $ate = trim((string)($_GET['ate'] ?? ''));
        if ($ate !== '') { $where[] = 'AUD_DATA_HORA <= :ate'; $par[':ate'] = $ate . ' 23:59:59'; }

        $busca = trim((string)($_GET['busca'] ?? ''));
        if ($busca !== '') {
            $where[] = '(AUD_DADOS_ANTES LIKE :b OR AUD_DADOS_DEPOIS LIKE :b OR AUD_USUARIO_NOME LIKE :b)';
            $par[':b'] = '%' . $busca . '%';
        }

        $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $porPagina = min(200, max(10, (int)($_GET['por_pagina'] ?? 50)));
        $offset = ($pagina - 1) * $porPagina;

        $stT = $pdo->prepare("SELECT COUNT(*) FROM tb_auditoria $wsql");
        $stT->execute($par);
        $total = (int)$stT->fetchColumn();

        $sql = "SELECT AUD_ID, AUD_DATA_HORA, AUD_USUARIO_ID, AUD_USUARIO_NOME, AUD_ORIGEM, AUD_IP,
                       AUD_TABELA, AUD_REGISTRO_PK, AUD_ACAO
                FROM tb_auditoria
                $wsql
                ORDER BY AUD_ID DESC
                LIMIT $porPagina OFFSET $offset";
        $st = $pdo->prepare($sql);
        $st->execute($par);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['AUD_TABELA_LABEL'] = AUD_LABELS[$r['AUD_TABELA']] ?? $r['AUD_TABELA'];
            $rows[] = $r;
        }

        jout([
            'ok' => true,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'paginas' => (int)ceil($total / $porPagina),
            'rows' => $rows,
        ]);
    }

    if ($acao === 'detalhe') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) jout(['ok' => false, 'msg' => 'ID inválido.'], 422);

        $st = $pdo->prepare("SELECT * FROM tb_auditoria WHERE AUD_ID = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) jout(['ok' => false, 'msg' => 'Registro não encontrado.'], 404);

        $antes = $r['AUD_DADOS_ANTES'] ? json_decode($r['AUD_DADOS_ANTES'], true) : null;
        $depois = $r['AUD_DADOS_DEPOIS'] ? json_decode($r['AUD_DADOS_DEPOIS'], true) : null;

        $keys = array_unique(array_merge(
            is_array($antes) ? array_keys($antes) : [],
            is_array($depois) ? array_keys($depois) : []
        ));

        $campos = [];
        foreach ($keys as $k) {
            $de = is_array($antes) ? ($antes[$k] ?? null) : null;
            $para = is_array($depois) ? ($depois[$k] ?? null) : null;
            $mudou = ((string)$de !== (string)$para);
            // No UPDATE, mostra só o que mudou; em INSERT/DELETE mostra tudo.
            if ($r['AUD_ACAO'] === 'UPDATE' && !$mudou) continue;
            $campos[] = ['campo' => $k, 'de' => $de, 'para' => $para, 'mudou' => $mudou];
        }

        jout([
            'ok' => true,
            'registro' => [
                'id' => (int)$r['AUD_ID'],
                'data_hora' => $r['AUD_DATA_HORA'],
                'usuario_id' => $r['AUD_USUARIO_ID'],
                'usuario_nome' => $r['AUD_USUARIO_NOME'],
                'origem' => $r['AUD_ORIGEM'],
                'ip' => $r['AUD_IP'],
                'tabela' => $r['AUD_TABELA'],
                'tabela_label' => AUD_LABELS[$r['AUD_TABELA']] ?? $r['AUD_TABELA'],
                'registro_pk' => $r['AUD_REGISTRO_PK'],
                'acao' => $r['AUD_ACAO'],
            ],
            'campos' => $campos,
            'total_campos' => count($campos),
        ]);
    }

    jout(['ok' => false, 'msg' => 'Ação não reconhecida.'], 400);
} catch (Throwable $e) {
    jout(['ok' => false, 'msg' => 'Erro interno: ' . $e->getMessage()], 500);
}
