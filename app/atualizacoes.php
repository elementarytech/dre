<?php
// /app/atualizacoes.php
// Tela de Atualizações do sistema: lista as entregas/atualizações e, em cada uma,
// um checklist de validação para o usuário conferir o que foi feito.
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/conexao.php';

$__userNome   = (string)($_SESSION['user_nome'] ?? 'Usuário');
$__userPerfil = (string)($_SESSION['user_perfil'] ?? 'USER');

// ====================================================================
// API JSON: validações COMPARTILHADAS (banco de dados, não localStorage).
// ====================================================================
$__acao = (string)($_GET['acao'] ?? $_POST['acao'] ?? '');

if ($__acao === 'listar_validacoes') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rows = $pdo->query("SELECT AV_ATUALIZACAO, AV_ITEM, AV_USUARIO, AV_VALIDADO_EM
                             FROM tb_atualizacao_validacao")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['AV_ATUALIZACAO'] . '|' . $r['AV_ITEM']] = [
                'usuario' => $r['AV_USUARIO'],
                'em'      => $r['AV_VALIDADO_EM'] ? date('d/m/Y', strtotime((string)$r['AV_VALIDADO_EM'])) : '',
            ];
        }
        echo json_encode(['ok' => true, 'marcados' => $out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($__acao === 'marcar_validacao') {
    header('Content-Type: application/json; charset=utf-8');
    $atu   = trim((string)($_POST['atualizacao'] ?? ''));
    $item  = (int)($_POST['item'] ?? -1);
    $valor = (string)($_POST['valor'] ?? '0') === '1';
    if ($atu === '' || $item < 0) { echo json_encode(['ok' => false, 'msg' => 'Parâmetros inválidos.']); exit; }
    try {
        if ($valor) {
            $st = $pdo->prepare("INSERT INTO tb_atualizacao_validacao (AV_ATUALIZACAO, AV_ITEM, AV_USUARIO)
                                 VALUES (?, ?, ?)
                                 ON DUPLICATE KEY UPDATE AV_USUARIO = VALUES(AV_USUARIO), AV_VALIDADO_EM = NOW()");
            $st->execute([$atu, $item, $__userNome]);
        } else {
            $pdo->prepare("DELETE FROM tb_atualizacao_validacao WHERE AV_ATUALIZACAO = ? AND AV_ITEM = ?")
                ->execute([$atu, $item]);
        }
        echo json_encode(['ok' => true, 'usuario' => $__userNome, 'em' => date('d/m/Y')]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ====================================================================
// Catálogo de atualizações (mais recente primeiro). Cada uma tem um
// checklist de validação. Para registrar novas, basta acrescentar aqui.
// ====================================================================
$ATUALIZACOES = [
    [
        'id'      => 'conc-2026-06',
        'data'    => '17/06/2026',
        'versao'  => 'Conciliação 1.0',
        'status'  => 'Concluído',
        'icone'   => 'fa-scale-balanced',
        'titulo'  => 'Conciliação Bancária — pacote de melhorias',
        'resumo'  => 'Tratamento de internos (rendimento/resgate/aplicação), parciais, busca, ajuste de centavos, cancelamento de vínculo e ícones.',
        'itens'   => [
            ['t' => '#7', 'd' => 'Rendimentos: ao importar OFX do BTG, os "VALOR DE RENDIMENTO REMUNERADA" não aparecem mais como pendentes (viram internos) e não criam mais conta a receber de centavos.'],
            ['t' => '',   'd' => 'Resgate/Aplicação automática BTG: os pares "Crédito na conta corrente / Resgate" e "Débito na conta corrente / Aplicação" não aparecem como pendentes — só o gasto/recebimento real.'],
            ['t' => '#1', 'd' => 'Contadores batem: em "Importações do banco", Conc. + Déb.Pend. + Créd.Pend. + Internos = Movs. O número de pendentes diminui ao conciliar.'],
            ['t' => '#3', 'd' => 'Pagamento/recebimento parcial: ao conciliar um valor parcial, a parcela aparece na busca e aceita o 2º recebimento até quitar.'],
            ['t' => '',   'd' => 'Busca de vínculo (🔍 Buscar por nome, valor, #ID): digitar mostra lista de resultados na hora; clicar seleciona. Funciona por nome, valor e #ID (inclusive de outros meses).'],
            ['t' => '',   'd' => 'Diferença de centavos: ao conciliar um valor 1 centavo diferente da parcela, o sistema pergunta se quer ajustar e só corrige após o OK.'],
            ['t' => '#8', 'd' => 'Cancelar vínculo errado: nas linhas verdes ("Vinculado a #X"), o botão "Cancelar vínculo" desfaz e volta o movimento para pendente.'],
            ['t' => '',   'd' => 'Confirmar sugeridos selecionável: dá para desmarcar sugestões erradas e confirmar só as corretas.'],
            ['t' => '#4', 'd' => 'Ícones: crédito/entrada = seta verde para cima; débito/saída = seta vermelha para baixo (KPIs, extrato, detalhe).'],
            ['t' => '#6', 'd' => 'Obrigatoriedade: ao "Lançar selecionados", se faltar Empresa, Fornecedor/Cliente ou Conta contábil, a linha fica destacada e bloqueia até preencher.'],
            ['t' => '#10','d' => 'Filtro em "Vínculos ativos": ao abrir "Conferir vínculos", o campo de filtro localiza por descrição, lançamento, #ID, valor ou tipo.'],
        ],
    ],
    [
        'id'      => 'receber-2026-06',
        'data'    => '17/06/2026',
        'versao'  => 'Contas a Receber 1.1',
        'status'  => 'Concluído',
        'icone'   => 'fa-arrow-down-wide-short',
        'titulo'  => 'Contas a Receber — status e busca',
        'resumo'  => 'Status ATRASADO derivado do vencimento e busca por #ID.',
        'itens'   => [
            ['t' => '#5', 'd' => 'Atrasado x Em aberto: parcela vencida e não recebida aparece como ATRASADO (não mais "Em aberto").'],
            ['t' => '#11','d' => 'Busca por ID: digitar o ID (ex.: 1382) encontra a parcela.'],
        ],
    ],
    [
        'id'      => 'bancos-2026-06',
        'data'    => '16/06/2026',
        'versao'  => 'Bancos 1.0',
        'status'  => 'Concluído',
        'icone'   => 'fa-building-columns',
        'titulo'  => 'Bancos / Saldos',
        'resumo'  => 'Saldo bancário ignora movimento cancelado e data "Atualizado" reflete a atividade real.',
        'itens'   => [
            ['t' => '', 'd' => 'Saldo ignora cancelado: transferência cancelada não compõe mais o saldo bancário.'],
            ['t' => '', 'd' => 'Data "Atualizado": reflete a atividade real (último movimento), não fica travada numa data antiga.'],
        ],
    ],
    [
        'id'      => 'relatorios-2026-06',
        'data'    => '12/06/2026',
        'versao'  => 'Relatórios 1.1',
        'status'  => 'Concluído',
        'icone'   => 'fa-file-invoice-dollar',
        'titulo'  => 'Relatório de Pendências de Conciliação (novo)',
        'resumo'  => 'Novo relatório para enxergar tudo que falta conciliar, por período e banco.',
        'itens'   => [
            ['t' => '', 'd' => 'Em Relatórios → "Pendências de Conciliação": filtra por período (padrão 17/04), banco e tipo; exporta CSV e imprime.'],
        ],
    ],
    [
        'id'      => 'roadmap-proximos',
        'data'    => '—',
        'versao'  => 'Próximos',
        'status'  => 'Planejado',
        'icone'   => 'fa-list-check',
        'titulo'  => 'Próximas etapas (a escopar)',
        'resumo'  => 'Itens dependentes de decisão / novas funcionalidades.',
        'itens'   => [
            ['t' => '#6', 'd' => 'Centro de Custo obrigatório no lançamento da conciliação (precisa adicionar o campo no modal).'],
            ['t' => '#13','d' => 'Tela de controle de cartão de crédito (funcionalidade nova).'],
            ['t' => '#14','d' => 'Reajuste anual de contratos (funcionalidade nova).'],
        ],
    ],
];

function ax_badge_status(string $s): string {
    $map = [
        'Concluído' => 'background:#dcfce7;color:#166534',
        'Planejado' => 'background:#fef3c7;color:#92400e',
        'Em validação' => 'background:#dbeafe;color:#1e40af',
    ];
    $css = $map[$s] ?? 'background:#e5e7eb;color:#374151';
    return '<span class="ax-status" style="' . $css . '">' . htmlspecialchars($s) . '</span>';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <title>Atualizações • SYNC ERP</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .ax-page-title { font-size: 1.2rem; font-weight: 700; color: #0f172a; margin: 0; }
        .ax-card {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
            box-shadow: 0 1px 3px rgba(15,23,42,.06); padding: 16px 18px;
            display: flex; gap: 14px; align-items: flex-start;
            transition: box-shadow .15s, border-color .15s, transform .15s;
        }
        .ax-card:hover { box-shadow: 0 8px 24px rgba(15,23,42,.10); border-color: #2563eb; }
        .ax-ico {
            width: 46px; height: 46px; border-radius: 12px; flex: 0 0 auto;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem; color: #fff;
            background: linear-gradient(135deg, #0b1220, #2563eb);
        }
        .ax-titulo { font-weight: 700; color: #0f172a; margin: 0; font-size: 1rem; }
        .ax-resumo { color: #64748b; font-size: .86rem; margin: 4px 0 0; line-height: 1.4; }
        .ax-meta { font-size: .74rem; color: #94a3b8; margin-top: 6px; }
        .ax-status { display:inline-block; font-size: .68rem; font-weight: 700; padding: 2px 9px; border-radius: 999px; letter-spacing:.02em; }
        .ax-pill { font-size: .72rem; color:#475569; background:#f1f5f9; border-radius:999px; padding:2px 9px; }
        .ax-prog-mini { height: 6px; background:#e5e7eb; border-radius: 999px; overflow:hidden; margin-top:8px; max-width: 320px; }
        .ax-prog-mini > span { display:block; height:100%; width:0; background:linear-gradient(90deg,#16a34a,#2563eb); transition:width .3s; }

        /* Modal checklist */
        .ck-item { display:flex; gap:12px; padding:11px 4px; border-bottom:1px solid #f1f3f5; align-items:flex-start; }
        .ck-item:last-child { border-bottom:none; }
        .ck-item input[type=checkbox]{ width:20px;height:20px;margin-top:1px;accent-color:#2563eb;cursor:pointer;flex:0 0 auto; }
        .ck-item label{ cursor:pointer; font-size:.88rem; color:#1f2937; }
        .ck-item.done label{ color:#9ca3af; text-decoration:line-through; }
        .ck-tag{ display:inline-block;background:#eef2ff;color:#3730a3;font-size:.66rem;font-weight:700;padding:1px 7px;border-radius:6px;margin-right:6px;vertical-align:middle; }
        .ck-prog { height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden; }
        .ck-prog > span{ display:block;height:100%;width:0;background:linear-gradient(90deg,#16a34a,#2563eb);transition:width .3s; }
    </style>
</head>
<body data-page="atualizacoes">
    <div class="d-flex" id="wrapper">

        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">

            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle"><i class="fa-solid fa-bars"></i></button>
                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Atualizações</span>
                <div class="collapse navbar-collapse justify-content-end">
                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted"><?= htmlspecialchars($__userNome) ?> (<?= htmlspecialchars($__userPerfil) ?>)</span>
                        <a class="btn btn-sm btn-outline-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-1"></i>Sair</a>
                    </div>
                </div>
            </nav>

            <div class="container-fluid py-4">

                <div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
                    <div>
                        <h5 class="ax-page-title">Atualizações do sistema</h5>
                        <p class="help text-muted mb-0" style="font-size:.82rem">
                            Histórico das entregas. Clique em <b>Validar</b> em cada atualização para conferir, item a item,
                            o que foi feito. As marcações ficam salvas neste navegador.
                        </p>
                    </div>
                    <span class="ax-pill"><i class="fa-solid fa-rotate me-1"></i><?= count($ATUALIZACOES) ?> atualização(ões)</span>
                </div>

                <div class="row g-3">
                    <?php foreach ($ATUALIZACOES as $a): ?>
                        <div class="col-12">
                            <div class="ax-card" id="card-<?= htmlspecialchars($a['id']) ?>">
                                <div class="ax-ico"><i class="fa-solid <?= htmlspecialchars($a['icone']) ?>"></i></div>
                                <div class="flex-grow-1" style="min-width:0">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <p class="ax-titulo"><?= htmlspecialchars($a['titulo']) ?></p>
                                        <?= ax_badge_status($a['status']) ?>
                                        <span class="ax-pill"><?= htmlspecialchars($a['versao']) ?></span>
                                    </div>
                                    <p class="ax-resumo"><?= htmlspecialchars($a['resumo']) ?></p>
                                    <div class="ax-meta">
                                        <i class="fa-regular fa-calendar me-1"></i><?= htmlspecialchars($a['data']) ?>
                                        · <span data-prog-label="<?= htmlspecialchars($a['id']) ?>">0/<?= count($a['itens']) ?></span> validados
                                    </div>
                                    <div class="ax-prog-mini"><span data-prog-bar="<?= htmlspecialchars($a['id']) ?>"></span></div>
                                </div>
                                <div class="flex-shrink-0 align-self-center">
                                    <button type="button" class="btn btn-sm btn-primary"
                                            onclick='abrirChecklist(<?= json_encode($a, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <i class="fa-solid fa-clipboard-check me-1"></i>Validar
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- Modal: checklist de validação -->
    <div class="modal fade" id="modalChecklist" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border:0;border-radius:14px;overflow:hidden">
                <div class="modal-header" style="background:#0f172a;color:#fff">
                    <h5 class="modal-title fw-bold mb-0"><i class="fa-solid fa-clipboard-check me-2"></i><span id="ckTitulo">Validação</span></h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body" style="background:#f8fafc">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted" id="ckContador">0 de 0 validados</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="ckLimpar"><i class="fa-solid fa-eraser me-1"></i>Limpar</button>
                    </div>
                    <div class="ck-prog mb-3"><span id="ckBar"></span></div>
                    <div id="ckLista" class="bg-white border rounded p-2"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>
    <script src="assets/session_keeper.js" defer></script>
    <script>
        // Validações COMPARTILHADAS (servidor). AX_STATE = { "id|item": {usuario, em} }.
        const AX_URL = location.pathname;
        let AX_STATE = {};
        let ckAtual = null;
        function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

        async function axCarregar() {
            try {
                const r = await fetch(AX_URL + '?acao=listar_validacoes', { credentials: 'same-origin' });
                const j = await r.json();
                if (j && j.ok) { AX_STATE = j.marcados || {}; }
            } catch (e) { console.warn('Falha ao carregar validações', e); }
            axRefreshCards();
            if (ckAtual) renderCheck(ckAtual);
        }

        async function axMarcar(atualizacao, item, valor) {
            const fd = new FormData();
            fd.append('acao', 'marcar_validacao');
            fd.append('atualizacao', atualizacao);
            fd.append('item', item);
            fd.append('valor', valor ? '1' : '0');
            const r = await fetch(AX_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
            return r.json();
        }

        function renderCheck(a) {
            const lista = document.getElementById('ckLista');
            lista.innerHTML = a.itens.map((it, i) => {
                const info = AX_STATE[a.id + '|' + i];
                const marc = !!info;
                const quem = marc && info.usuario ? `<div class="text-muted" style="font-size:.72rem;margin-top:2px"><i class="fa-solid fa-check text-success me-1"></i>validado por ${esc(info.usuario)}${info.em ? ' em ' + esc(info.em) : ''}</div>` : '';
                return `<div class="ck-item ${marc ? 'done' : ''}">
                    <input type="checkbox" data-atu="${esc(a.id)}" data-item="${i}" ${marc ? 'checked' : ''}>
                    <label>${it.t ? `<span class="ck-tag">${esc(it.t)}</span>` : ''}${esc(it.d)}${quem}</label>
                </div>`;
            }).join('');
            lista.querySelectorAll('input[type=checkbox]').forEach(cb => {
                cb.addEventListener('change', async () => {
                    cb.disabled = true;
                    const atu = cb.dataset.atu, item = parseInt(cb.dataset.item, 10), val = cb.checked;
                    const j = await axMarcar(atu, item, val);
                    if (j && j.ok) {
                        if (val) AX_STATE[atu + '|' + item] = { usuario: j.usuario, em: j.em };
                        else delete AX_STATE[atu + '|' + item];
                        renderCheck(ckAtual); ckRefresh(); axRefreshCards();
                    } else {
                        cb.checked = !val;
                        if (window.Swal) Swal.fire({ icon: 'error', title: 'Erro ao salvar', text: (j && j.msg) || 'Tente novamente.' });
                    }
                    cb.disabled = false;
                });
            });
            ckRefresh();
        }

        function abrirChecklist(a) {
            ckAtual = a;
            document.getElementById('ckTitulo').textContent = a.titulo;
            renderCheck(a);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalChecklist')).show();
        }

        function ckRefresh() {
            if (!ckAtual) return;
            const total = ckAtual.itens.length;
            const done = ckAtual.itens.filter((_, i) => AX_STATE[ckAtual.id + '|' + i]).length;
            document.getElementById('ckContador').textContent = `${done} de ${total} validados`;
            document.getElementById('ckBar').style.width = (total ? done / total * 100 : 0) + '%';
        }

        document.getElementById('ckLimpar').addEventListener('click', async () => {
            if (!ckAtual) return;
            const r = await Swal.fire({ icon: 'warning', title: 'Limpar validações desta atualização?', showCancelButton: true, confirmButtonText: 'Sim, limpar', cancelButtonText: 'Cancelar' });
            if (!r.isConfirmed) return;
            for (let i = 0; i < ckAtual.itens.length; i++) {
                if (AX_STATE[ckAtual.id + '|' + i]) { await axMarcar(ckAtual.id, i, false); delete AX_STATE[ckAtual.id + '|' + i]; }
            }
            renderCheck(ckAtual); ckRefresh(); axRefreshCards();
        });

        // Barrinhas de progresso de cada card na listagem.
        function axRefreshCards() {
            document.querySelectorAll('[data-prog-bar]').forEach(bar => {
                const id = bar.getAttribute('data-prog-bar');
                const totalEl = document.querySelector(`[data-prog-label="${id}"]`);
                const total = parseInt((totalEl?.textContent || '0/0').split('/')[1], 10) || 0;
                let done = 0;
                for (let i = 0; i < total; i++) if (AX_STATE[id + '|' + i]) done++;
                bar.style.width = (total ? done / total * 100 : 0) + '%';
                if (totalEl) totalEl.textContent = `${done}/${total}`;
            });
        }
        document.addEventListener('DOMContentLoaded', axCarregar);
    </script>
</body>
</html>
