<?php
declare(strict_types=1);
require_once __DIR__ . '/config/auth.php';

if (strtoupper((string)($_SESSION['user_perfil'] ?? '')) !== 'ADMIN') {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Liberação de Pagamento</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        :root { --radius:.875rem; --border:#e5e7eb; }
        body { background:#f4f6f9; }

        .page-title { font-size:1.25rem; font-weight:700; color:#0f172a; margin:0; }

        .kpi-strip { display:flex; flex-wrap:nowrap; gap:.625rem; margin-bottom:1rem; }
        .kpi-card {
            flex:1 1 0; min-width:0; background:#fff; border:1px solid var(--border);
            border-radius:var(--radius); box-shadow:0 1px 3px rgba(15,23,42,.05);
            padding:.85rem 1rem; display:flex; align-items:center; gap:.75rem;
            position:relative; overflow:hidden;
        }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.amber::before { background:#d97706; }
        .kpi-card.green::before { background:#16a34a; }
        .kpi-card.blue::before  { background:#2563eb; }

        .kpi-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; }
        .kpi-icon.amber  { background:#fff7ed; color:#d97706; }
        .kpi-icon.green  { background:#f0fdf4; color:#16a34a; }
        .kpi-icon.blue   { background:#eff6ff; color:#2563eb; }

        .kpi-label { font-size:.66rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; margin-bottom:.15rem; }
        .kpi-val   { font-size:1.15rem; font-weight:800; color:#0f172a; line-height:1.15; }

        @media (max-width:767.98px) { .kpi-strip { flex-wrap:wrap; } .kpi-card { flex:1 1 100%; } }

        .card { border:1px solid var(--border); border-radius:var(--radius); box-shadow:0 1px 3px rgba(15,23,42,.05); }
        .card-header { background:#fff; border-bottom:1px solid var(--border); padding:.85rem 1rem; }

        .table thead th { font-size:.68rem; letter-spacing:.06em; text-transform:uppercase; color:#64748b; border-bottom:1px solid var(--border); }
        .table tbody td { font-size:.82rem; color:#334155; vertical-align:middle; border-bottom:1px solid #f1f5f9; }
        .table tbody tr:hover { background:#f8fafc; }

        .badge-soft-success { background:rgba(22,163,106,.1); color:#15803d; border-radius:999px; padding:.22rem .52rem; font-size:.7rem; font-weight:600; }
        .badge-soft-warning { background:rgba(234,179,8,.1); color:#854d0e; border-radius:999px; padding:.22rem .52rem; font-size:.7rem; font-weight:600; }
        .badge-soft-danger  { background:rgba(239,68,68,.1); color:#b91c1c; border-radius:999px; padding:.22rem .52rem; font-size:.7rem; font-weight:600; }
        .badge-soft-primary { background:rgba(37,99,235,.1); color:#1d4ed8; border-radius:999px; padding:.22rem .52rem; font-size:.7rem; font-weight:600; }
        .badge-soft-secondary { background:rgba(107,114,128,.1); color:#374151; border-radius:999px; padding:.22rem .52rem; font-size:.7rem; font-weight:600; }

        .mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
        .truncate { max-width:300px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:inline-block; vertical-align:bottom; }

        .modal-xl2 { max-width:92vw; }
        @media (min-width:1200px) { .modal-xl2 { max-width:1000px; } }

        .lbl-filtro { font-size:.65rem; text-transform:uppercase; letter-spacing:.04em; color:#94a3b8; font-weight:600; }
    </style>
</head>

<body data-page="financeiro">
    <div class="d-flex" id="wrapper">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">
            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle"><i class="fa-solid fa-bars"></i></button>
                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Liberação de Pagamento</span>
                <div class="collapse navbar-collapse justify-content-end">
                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted"><?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?> (<?= htmlspecialchars($_SESSION['user_perfil'] ?? 'USER') ?>)</span>
                        <a class="btn btn-sm btn-outline-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-1"></i>Sair</a>
                    </div>
                </div>
            </nav>

            <div class="container-fluid py-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div>
                        <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">Fluxo de Caixa</div>
                        <h1 class="page-title">Liberação de Pagamento</h1>
                    </div>
                </div>

                <!-- KPIs -->
                <div class="kpi-strip">
                    <div class="kpi-card amber">
                        <div class="kpi-icon amber"><i class="fa-solid fa-clock"></i></div>
                        <div>
                            <div class="kpi-label">Pendentes</div>
                            <div class="kpi-val" id="kpiPendentes">0</div>
                        </div>
                    </div>
                    <div class="kpi-card amber">
                        <div class="kpi-icon amber"><i class="fa-solid fa-money-bill-wave"></i></div>
                        <div>
                            <div class="kpi-label">Total Pendente</div>
                            <div class="kpi-val mono" id="kpiTotPendente">R$ 0,00</div>
                        </div>
                    </div>
                    <div class="kpi-card green">
                        <div class="kpi-icon green"><i class="fa-solid fa-circle-check"></i></div>
                        <div>
                            <div class="kpi-label">Total Autorizado</div>
                            <div class="kpi-val mono" id="kpiTotAutorizado">R$ 0,00</div>
                        </div>
                    </div>
                </div>

                <!-- Filtros + Tabela -->
                <div class="card">
                    <div class="card-header py-2">
                        <!-- Linha principal: filtros essenciais -->
                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-md">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                                    <input id="txtBusca" type="text" class="form-control form-control-sm" placeholder="Buscar por fornecedor, CNPJ, documento...">
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <select id="selStatus" class="form-select form-select-sm">
                                    <option value="">Todos os Status</option>
                                    <option value="ABERTO" selected>Aberto</option>
                                    <option value="PAGO">Pago</option>
                                    <option value="CANCELADO">Cancelado</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <select id="selEmpresa" class="form-select form-select-sm">
                                    <option value="">Todas as Empresas</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <input id="dtIni" type="date" class="form-control form-control-sm" title="Vencimento de">
                            </div>
                            <div class="col-6 col-md-2">
                                <input id="dtFim" type="date" class="form-control form-control-sm" title="Vencimento até">
                            </div>
                        </div>

                        <!-- Linha secundária: toggles, limpar e chips -->
                        <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#maisFiltros">
                                <i class="fa-solid fa-filter me-1"></i>Mais filtros
                            </button>
                            <button class="btn btn-outline-danger btn-sm" id="btnLimpar">
                                <i class="fa-solid fa-rotate-left me-1"></i>Limpar filtros
                            </button>
                            <div id="chipsFiltros" class="d-flex flex-wrap gap-2"></div>
                        </div>

                        <!-- Filtros adicionais -->
                        <div class="collapse mt-2" id="maisFiltros">
                            <div class="row g-2 align-items-end">
                                <div class="col-6 col-md-3">
                                    <label class="form-label mb-0 lbl-filtro">Visão</label>
                                    <select id="selVisao" class="form-select form-select-sm">
                                        <option value="TODAS">Pend. + Autor.</option>
                                        <option value="PENDENTES" selected>Pendentes</option>
                                        <option value="AUTORIZADAS">Autorizadas</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label mb-0 lbl-filtro">Valor mín</label>
                                    <input id="valorMin" type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="0,00">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label mb-0 lbl-filtro">Valor máx</label>
                                    <input id="valorMax" type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="0,00">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:50px">ID</th>
                                        <th>Vencimento</th>
                                        <th>Fornecedor</th>
                                        <th>Doc / NF</th>
                                        <th class="text-end">Valor</th>
                                        <th>Status</th>
                                        <th>Liberação</th>
                                        <th class="text-end" style="width:140px">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody">
                                    <tr><td colspan="8" class="text-muted small p-3">Carregando...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <footer class="text-muted small mt-4 text-center" style="font-size:.72rem">
                    © <?= date('Y') ?> SYNC-ERP — Liberação de Pagamento
                </footer>
            </div>
        </div>
    </div>

    <!-- Modal detalhe -->
    <div class="modal fade" id="modalDetalhe" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl2 modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0">Detalhes da conta</h5>
                        <div class="text-muted small" id="mdSub">—</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6 col-md-2"><div class="text-muted small">ID</div><div class="fw-semibold mono" id="mdId">—</div></div>
                        <div class="col-6 col-md-2"><div class="text-muted small">Status</div><div id="mdStatus">—</div></div>
                        <div class="col-6 col-md-2"><div class="text-muted small">Vencimento</div><div class="fw-semibold mono" id="mdVenc">—</div></div>
                        <div class="col-6 col-md-3"><div class="text-muted small">Valor</div><div class="fw-semibold mono" id="mdValor">—</div></div>
                        <div class="col-6 col-md-3"><div class="text-muted small">Liberação</div><div id="mdLiberacao">—</div></div>
                        <div class="col-12"><div class="text-muted small">Fornecedor</div><div class="fw-semibold" id="mdFornecedor">—</div></div>
                        <div class="col-6"><div class="text-muted small">CPF/CNPJ</div><div class="mono" id="mdCpfCnpj">—</div></div>
                        <div class="col-6"><div class="text-muted small">Autorizado por</div><div class="fw-semibold" id="mdAutorizadoPor">—</div></div>
                        <div class="col-6"><div class="text-muted small">Autorizado em</div><div class="mono" id="mdAutorizadoEm">—</div></div>
                        <div class="col-6"><div class="text-muted small">Documento</div><div class="mono" id="mdDocumento">—</div></div>
                        <div class="col-6"><div class="text-muted small">Nota Fiscal</div><div class="mono" id="mdNf">—</div></div>
                        <div class="col-12"><div class="text-muted small">Complemento</div><div id="mdComplemento">—</div></div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-danger btn-sm" id="btnPendente">
                            <i class="fa-solid fa-xmark me-1"></i>Voltar p/ pendente
                        </button>
                        <button type="button" class="btn btn-success btn-sm" id="btnAutorizar">
                            <i class="fa-solid fa-check me-1"></i>Liberar p/ pagamento
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
    const API = 'endpoints/fluxo_caixa.php';
    let modalDetalhe;
    let modalAtualId = null;

    function money(v) { return Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits:2, maximumFractionDigits:2 }); }

    function fmtBR(iso) {
        if (!iso) return '—';
        const p = String(iso).substring(0,10).split('-');
        return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : iso;
    }

    function badgeStatus(s) {
        s = String(s || '').trim().toUpperCase();
        if (!s) s = 'ABERTO';
        const map = { ABERTO:'badge-soft-primary', PAGO:'badge-soft-success', CANCELADO:'badge-soft-secondary', ATRASADO:'badge-soft-danger' };
        return `<span class="${map[s] || 'badge-soft-secondary'}">${s}</span>`;
    }

    function badgeAutorizacao(s) {
        s = String(s || 'PENDENTE').toUpperCase();
        return s === 'AUTORIZADO'
            ? '<span class="badge-soft-success"><i class="bi bi-check-circle me-1"></i>Liberado</span>'
            : '<span class="badge-soft-warning"><i class="bi bi-lock me-1"></i>Pendente</span>';
    }

    async function apiGet(params) {
        const r = await fetch(API + '?' + new URLSearchParams(params), { cache:'no-store' });
        return await r.json();
    }

    async function apiPost(payload) {
        const r = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
        return await r.json();
    }

    const FILTROS_KEY = 'liberacao_pagamento_filtros_v1';

    function coletarFiltros() {
        return {
            busca: document.getElementById('txtBusca').value.trim(),
            visao: document.getElementById('selVisao').value,
            status: document.getElementById('selStatus').value,
            empresa: document.getElementById('selEmpresa').value,
            dt_ini: document.getElementById('dtIni').value,
            dt_fim: document.getElementById('dtFim').value,
            valor_min: document.getElementById('valorMin').value,
            valor_max: document.getElementById('valorMax').value,
        };
    }

    function salvarFiltros() {
        try { localStorage.setItem(FILTROS_KEY, JSON.stringify(coletarFiltros())); } catch (e) {}
    }

    function carregarFiltrosSalvos() {
        try { return JSON.parse(localStorage.getItem(FILTROS_KEY) || 'null'); } catch (e) { return null; }
    }

    function renderChips() {
        const f = coletarFiltros();
        const chips = [];
        const lbl = (txt, clear) => chips.push({ txt, clear });

        if (f.busca)    lbl(`Busca: "${f.busca}"`, () => { document.getElementById('txtBusca').value = ''; });
        if (f.visao && f.visao !== 'TODAS') lbl(`Visão: ${f.visao === 'PENDENTES' ? 'Pendentes' : 'Autorizadas'}`, () => { document.getElementById('selVisao').value = 'TODAS'; });
        if (f.status)   lbl(`Status: ${f.status}`, () => { document.getElementById('selStatus').value = ''; });
        if (f.empresa) {
            const sel = document.getElementById('selEmpresa');
            const nome = sel.options[sel.selectedIndex]?.text || f.empresa;
            lbl(`Empresa: ${nome}`, () => { sel.value = ''; });
        }
        if (f.dt_ini)    lbl(`De: ${f.dt_ini}`, () => { document.getElementById('dtIni').value = ''; });
        if (f.dt_fim)    lbl(`Até: ${f.dt_fim}`, () => { document.getElementById('dtFim').value = ''; });
        if (f.valor_min) lbl(`Mín: R$ ${Number(f.valor_min).toLocaleString('pt-BR', {minimumFractionDigits:2})}`, () => { document.getElementById('valorMin').value = ''; });
        if (f.valor_max) lbl(`Máx: R$ ${Number(f.valor_max).toLocaleString('pt-BR', {minimumFractionDigits:2})}`, () => { document.getElementById('valorMax').value = ''; });

        const box = document.getElementById('chipsFiltros');
        if (!chips.length) { box.innerHTML = ''; return; }
        box.innerHTML = chips.map((c, i) =>
            `<span class="badge-soft-secondary d-inline-flex align-items-center gap-1" style="cursor:pointer" data-chip="${i}">${c.txt} <i class="fa-solid fa-xmark"></i></span>`
        ).join('');
        box.querySelectorAll('[data-chip]').forEach(el => {
            el.addEventListener('click', () => {
                chips[Number(el.dataset.chip)].clear();
                debounceList(50);
            });
        });
    }

    async function carregarEmpresas() {
        try {
            const r = await apiGet({ acao: 'combo_empresas' });
            if (!r.ok || !Array.isArray(r.rows)) return;
            const sel = document.getElementById('selEmpresa');
            r.rows.forEach(e => {
                const opt = document.createElement('option');
                opt.value = e.EMP_ID; opt.textContent = e.EMP_NOME;
                sel.appendChild(opt);
            });
        } catch (e) { /* silencioso */ }
    }

    async function listar() {
        salvarFiltros();
        renderChips();
        const r = await apiGet(Object.assign({ acao: 'listar_liberacao_pagamento' }, coletarFiltros()));

        const tbody = document.getElementById('tbody');

        // KPIs
        document.getElementById('kpiPendentes').textContent = r.pendentes || 0;
        document.getElementById('kpiTotPendente').textContent = 'R$ ' + money(r.total_pendente || 0);
        document.getElementById('kpiTotAutorizado').textContent = 'R$ ' + money(r.total_autorizado || 0);

        if (!r.ok || !r.rows || !r.rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-muted small p-3">Nenhuma conta encontrada.</td></tr>';
            return;
        }

        tbody.innerHTML = r.rows.map(it => {
            const docNf = [it.CPG_DOCUMENTO, it.CPG_NOTA_FISCAL].filter(Boolean).join(' / ') || '—';
            return `
            <tr>
                <td class="mono">${it.CPG_CODIGO_PK}</td>
                <td class="mono">${fmtBR(it.CPG_VENCIMENTO)}</td>
                <td>
                    <span class="truncate" title="${(it.FOR_NOME_FANTASIA || it.FOR_RAZAO_SOCIAL) || ''}">${(it.FOR_NOME_FANTASIA || it.FOR_RAZAO_SOCIAL) || '—'}</span>
                    ${it.FOR_CNPJ ? '<br><small class="text-muted mono">' + it.FOR_CNPJ + '</small>' : ''}
                </td>
                <td class="mono">${docNf}</td>
                <td class="text-end mono fw-semibold">R$ ${money(it.CPG_VALOR_PARCELA)}</td>
                <td>${badgeStatus(it.CPG_STATUS)}</td>
                <td>${badgeAutorizacao(it.CPG_AUTORIZACAO_STATUS)}</td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-secondary me-1" data-view="${it.CPG_CODIGO_PK}" title="Detalhes">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-success me-1" data-auth="${it.CPG_CODIGO_PK}" title="Liberar">
                        <i class="fa-solid fa-check"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" data-pend="${it.CPG_CODIGO_PK}" title="Voltar pendente">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </td>
            </tr>`;
        }).join('');
    }

    async function abrirDetalhe(id) {
        const r = await apiGet({ acao:'buscar_liberacao_pagamento', id });
        if (!r.ok || !r.row) { Swal.fire({ icon:'error', title:'Erro', text:r.msg || 'Conta não encontrada.' }); return; }

        const it = r.row;
        modalAtualId = id;

        document.getElementById('mdSub').textContent = `${(it.FOR_NOME_FANTASIA || it.FOR_RAZAO_SOCIAL) || '—'} • R$ ${money(it.CPG_VALOR_PARCELA)}`;
        document.getElementById('mdId').textContent = it.CPG_CODIGO_PK || '—';
        document.getElementById('mdStatus').innerHTML = badgeStatus(it.CPG_STATUS);
        document.getElementById('mdVenc').textContent = fmtBR(it.CPG_VENCIMENTO);
        document.getElementById('mdValor').textContent = 'R$ ' + money(it.CPG_VALOR_PARCELA);
        document.getElementById('mdLiberacao').innerHTML = badgeAutorizacao(it.CPG_AUTORIZACAO_STATUS);
        document.getElementById('mdFornecedor').textContent = (it.FOR_NOME_FANTASIA || it.FOR_RAZAO_SOCIAL) || '—';
        document.getElementById('mdCpfCnpj').textContent = it.FOR_CNPJ || '—';

        // Autorizado por / em
        const autorizado = String(it.CPG_AUTORIZACAO_STATUS || '').toUpperCase() === 'AUTORIZADO';
        if (autorizado) {
            const nome = (it.CPG_AUTORIZADO_POR_NOME || '').trim();
            const email = (it.CPG_AUTORIZADO_POR_EMAIL || '').trim();
            document.getElementById('mdAutorizadoPor').innerHTML = nome
                ? `${nome}${email ? ' <small class="text-muted">(' + email + ')</small>' : ''}`
                : (it.CPG_AUTORIZADO_POR ? `Usuário #${it.CPG_AUTORIZADO_POR}` : '—');
            const dt = it.CPG_AUTORIZADO_EM;
            if (dt) {
                const iso = String(dt).substring(0, 10);
                const hora = String(dt).substring(11, 16);
                document.getElementById('mdAutorizadoEm').textContent = fmtBR(iso) + (hora ? ' ' + hora : '');
            } else {
                document.getElementById('mdAutorizadoEm').textContent = '—';
            }
        } else {
            document.getElementById('mdAutorizadoPor').textContent = '—';
            document.getElementById('mdAutorizadoEm').textContent = '—';
        }
        document.getElementById('mdDocumento').textContent = it.CPG_DOCUMENTO || '—';
        document.getElementById('mdNf').textContent = it.CPG_NOTA_FISCAL || '—';
        document.getElementById('mdComplemento').textContent = it.CPG_COMPLEMENTO || '—';

        modalDetalhe.show();
    }

    async function alterarAutorizacao(id, status) {
        const r = await apiPost({ acao:'alterar_autorizacao', id, status });
        if (!r.ok) { Swal.fire({ icon:'error', title:'Erro', text:r.msg }); return; }

        await listar();
        if (modalAtualId === id) await abrirDetalhe(id);

        Swal.fire({
            icon:'success',
            title: status === 'AUTORIZADO' ? 'Conta liberada!' : 'Voltou para pendente',
            timer:1000, showConfirmButton:false
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

    function restaurarFiltrosOuDefault() {
        const salvos = carregarFiltrosSalvos();
        if (salvos) {
            if ('busca' in salvos)     document.getElementById('txtBusca').value = salvos.busca || '';
            if ('visao' in salvos)     document.getElementById('selVisao').value = salvos.visao || 'PENDENTES';
            if ('status' in salvos)    document.getElementById('selStatus').value = salvos.status || '';
            if ('dt_ini' in salvos)    document.getElementById('dtIni').value = salvos.dt_ini || '';
            if ('dt_fim' in salvos)    document.getElementById('dtFim').value = salvos.dt_fim || '';
            if ('valor_min' in salvos) document.getElementById('valorMin').value = salvos.valor_min || '';
            if ('valor_max' in salvos) document.getElementById('valorMax').value = salvos.valor_max || '';
            // empresa é aplicada após carregarEmpresas()
            return salvos;
        }
        // Default: mês atual
        const hoje = new Date();
        const primeiroDia = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
        const ultimoDia  = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);
        const toISO = d => d.toISOString().substring(0, 10);
        document.getElementById('dtIni').value = toISO(primeiroDia);
        document.getElementById('dtFim').value = toISO(ultimoDia);
        return null;
    }

    document.addEventListener('DOMContentLoaded', async () => {
        modalDetalhe = new bootstrap.Modal(document.getElementById('modalDetalhe'));

        const salvos = restaurarFiltrosOuDefault();
        await carregarEmpresas();
        if (salvos && salvos.empresa) document.getElementById('selEmpresa').value = salvos.empresa;

        await listar();

        // Filtros: texto e valor com debounce 500ms; selects/datas imediato (silencioso).
        ['txtBusca', 'valorMin', 'valorMax'].forEach(id =>
            document.getElementById(id).addEventListener('input', () => debounceList(500))
        );
        ['selVisao', 'selStatus', 'selEmpresa', 'dtIni', 'dtFim'].forEach(id =>
            document.getElementById(id).addEventListener('change', () => debounceList(50))
        );

        document.getElementById('btnLimpar').addEventListener('click', () => {
            document.getElementById('txtBusca').value = '';
            document.getElementById('selVisao').value = 'TODAS';
            document.getElementById('selStatus').value = '';
            document.getElementById('selEmpresa').value = '';
            document.getElementById('dtIni').value = '';
            document.getElementById('dtFim').value = '';
            document.getElementById('valorMin').value = '';
            document.getElementById('valorMax').value = '';
            debounceList(50);
        });

        // Ações na tabela
        document.getElementById('tbody').addEventListener('click', async (e) => {
            const btnView = e.target.closest('[data-view]');
            const btnAuth = e.target.closest('[data-auth]');
            const btnPend = e.target.closest('[data-pend]');
            if (btnView) await abrirDetalhe(Number(btnView.dataset.view));
            if (btnAuth) await alterarAutorizacao(Number(btnAuth.dataset.auth), 'AUTORIZADO');
            if (btnPend) await alterarAutorizacao(Number(btnPend.dataset.pend), 'PENDENTE');
        });

        // Modal buttons
        document.getElementById('btnAutorizar').addEventListener('click', async () => {
            if (modalAtualId) await alterarAutorizacao(modalAtualId, 'AUTORIZADO');
        });
        document.getElementById('btnPendente').addEventListener('click', async () => {
            if (modalAtualId) await alterarAutorizacao(modalAtualId, 'PENDENTE');
        });
    });
    </script>
  <script src="assets/session_keeper.js" defer></script>
</body>
</html>
