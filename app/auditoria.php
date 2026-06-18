<?php
// /app/auditoria.php — Trilha de auditoria (consulta). Admin-only.
declare(strict_types=1);
require_once __DIR__ . '/config/auth.php';

// Página sensível: apenas administradores.
if (strtoupper((string)($_SESSION['user_perfil'] ?? '')) !== 'ADMIN') {
    header('Location: index.php');
    exit;
}

$usuarioTopo = (string)($_SESSION['user_nome'] ?? 'Usuário');
$hojeTopo = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>SYNC ERP - Auditoria</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .aud-badge-INSERT { background:#dcfce7; color:#166534; }
        .aud-badge-UPDATE { background:#dbeafe; color:#1e40af; }
        .aud-badge-DELETE { background:#fee2e2; color:#991b1b; }
        .aud-badge { font-size:.72rem; font-weight:600; padding:.25rem .55rem; border-radius:999px; }
        .aud-origem-app { color:#16a34a; }
        .aud-origem-sql { color:#dc2626; font-weight:600; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .aud-row { cursor:pointer; }
        .aud-row:hover { background:#f8fafc; }
        .diff-de { background:#fef2f2; color:#991b1b; }
        .diff-para { background:#f0fdf4; color:#166534; }
        .diff-cell { font-family: ui-monospace, monospace; font-size:.8rem; word-break:break-word; max-width:340px; }
        .ss { font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; }
    </style>
</head>

<body data-page="auditoria">
    <div class="d-flex" id="wrapper">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1 d-flex flex-column" style="min-height:100vh">

            <header class="topbar d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-sm btn-outline-secondary" id="menu-toggle" aria-label="Menu"><i class="bi bi-list fs-5"></i></button>
                    <h1 class="topbar-title">Auditoria</h1>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="text-secondary small d-none d-md-inline">Hoje: <strong class="mono"><?= $hojeTopo ?></strong></span>
                    <span class="text-secondary small"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($usuarioTopo) ?></span>
                </div>
            </header>

            <main class="p-3 p-lg-4 flex-grow-1">

                <nav aria-label="Breadcrumb" class="mb-3">
                    <ol class="breadcrumb mb-0 small">
                        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-secondary">Financeiro</a></li>
                        <li class="breadcrumb-item active fw-semibold">Auditoria</li>
                    </ol>
                </nav>

                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <div>
                        <h4 class="fw-bold mb-1"><i class="bi bi-clipboard-check me-2"></i>Trilha de auditoria</h4>
                        <div class="text-muted small">Quem alterou o quê, quando, e os valores antes → depois. Captura automática (telas, APIs e edições no banco).</div>
                    </div>
                    <div class="text-end">
                        <div class="ss">Total de eventos</div>
                        <div class="fs-4 fw-bold mono" id="kpiTotal">—</div>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card mb-3">
                    <div class="card-body py-3">
                        <div class="row g-2 align-items-end">
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">Usuário</label>
                                <select id="fUsuario" class="form-select form-select-sm"><option value="">Todos</option></select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">Tabela / Módulo</label>
                                <select id="fTabela" class="form-select form-select-sm"><option value="">Todas</option></select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">Ação</label>
                                <select id="fTipo" class="form-select form-select-sm">
                                    <option value="">Todas</option>
                                    <option value="INSERT">Criação</option>
                                    <option value="UPDATE">Alteração</option>
                                    <option value="DELETE">Exclusão</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">Origem</label>
                                <select id="fOrigem" class="form-select form-select-sm">
                                    <option value="">Todas</option>
                                    <option value="APP">Sistema (logado)</option>
                                    <option value="SQL/DIRETO">Edição direta no banco</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">Nº do registro</label>
                                <input id="fRegistro" type="text" class="form-control form-control-sm" placeholder="ex: 3216">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">Buscar (texto)</label>
                                <input id="fBusca" type="text" class="form-control form-control-sm" placeholder="valor / nome">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">De</label>
                                <input id="fDe" type="date" class="form-control form-control-sm">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold mb-1">Até</label>
                                <input id="fAte" type="date" class="form-control form-control-sm">
                            </div>
                            <div class="col-12 col-md-auto">
                                <button class="btn btn-sm btn-primary" id="btnFiltrar"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                                <button class="btn btn-sm btn-outline-secondary" id="btnLimpar"><i class="bi bi-x-circle me-1"></i>Limpar</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lista -->
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:160px">Data / Hora</th>
                                    <th>Usuário</th>
                                    <th style="width:110px">Origem</th>
                                    <th style="width:100px">Ação</th>
                                    <th>Módulo</th>
                                    <th style="width:90px">Registro</th>
                                    <th style="width:70px" class="text-end">Ver</th>
                                </tr>
                            </thead>
                            <tbody id="tbody"><tr><td colspan="7" class="text-center text-muted py-4">Carregando…</td></tr></tbody>
                        </table>
                    </div>
                    <div class="d-flex align-items-center justify-content-between p-2 flex-wrap gap-2">
                        <div class="small text-muted" id="infoPag">—</div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" id="btnPrev"><i class="bi bi-chevron-left"></i></button>
                            <button class="btn btn-outline-secondary" id="btnNext"><i class="bi bi-chevron-right"></i></button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal detalhe -->
    <div class="modal fade" id="modalDetalhe" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Detalhe do evento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalheBody">—</div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>
    <script>
        const EP = 'endpoints/auditoria.php';
        let pagina = 1, paginas = 1;
        const $ = id => document.getElementById(id);
        const esc = s => (s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])));

        function acaoLabel(a){ return {INSERT:'Criação',UPDATE:'Alteração',DELETE:'Exclusão'}[a]||a; }

        async function carregarCombos(){
            const r = await fetch(`${EP}?acao=combos`);
            const j = await r.json();
            if(!j.ok) return;
            const u = $('fUsuario');
            j.usuarios.forEach(x => { const o=document.createElement('option'); o.value=x.id||''; o.textContent=`${x.nome} (${x.qtd})`; u.appendChild(o); });
            const t = $('fTabela');
            j.tabelas.forEach(x => { const o=document.createElement('option'); o.value=x.tabela; o.textContent=`${x.label} (${x.qtd})`; t.appendChild(o); });
        }

        function filtrosQS(){
            const p = new URLSearchParams({ acao:'listar', pagina });
            const map = { usuario_id:'fUsuario', tabela:'fTabela', tipo:'fTipo', origem:'fOrigem', registro:'fRegistro', busca:'fBusca', de:'fDe', ate:'fAte' };
            for(const [k,id] of Object.entries(map)){ const v=$(id).value.trim(); if(v) p.set(k,v); }
            return p.toString();
        }

        async function listar(){
            const r = await fetch(`${EP}?${filtrosQS()}`);
            const j = await r.json();
            const tb = $('tbody');
            if(!j.ok){ tb.innerHTML = `<tr><td colspan="7" class="text-danger text-center py-3">${esc(j.msg||'Erro')}</td></tr>`; return; }
            paginas = j.paginas || 1;
            $('kpiTotal').textContent = j.total.toLocaleString('pt-BR');
            $('infoPag').textContent = `${j.total.toLocaleString('pt-BR')} evento(s) · página ${j.pagina} de ${j.paginas||1}`;
            $('btnPrev').disabled = j.pagina<=1; $('btnNext').disabled = j.pagina>=(j.paginas||1);
            if(!j.rows.length){ tb.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">Nenhum evento encontrado.</td></tr>`; return; }
            tb.innerHTML = j.rows.map(r => {
                const dt = r.AUD_DATA_HORA ? r.AUD_DATA_HORA.substring(8,10)+'/'+r.AUD_DATA_HORA.substring(5,7)+'/'+r.AUD_DATA_HORA.substring(0,4)+' '+r.AUD_DATA_HORA.substring(11,19) : '';
                const origem = r.AUD_ORIGEM==='APP'
                    ? `<span class="aud-origem-app small"><i class="bi bi-shield-check me-1"></i>Sistema</span>`
                    : `<span class="aud-origem-sql small"><i class="bi bi-database me-1"></i>Direto</span>`;
                return `<tr class="aud-row" data-id="${r.AUD_ID}">
                    <td class="mono small">${dt}</td>
                    <td>${esc(r.AUD_USUARIO_NOME)||'<span class="text-muted">—</span>'}</td>
                    <td>${origem}</td>
                    <td><span class="aud-badge aud-badge-${r.AUD_ACAO}">${acaoLabel(r.AUD_ACAO)}</span></td>
                    <td>${esc(r.AUD_TABELA_LABEL)}</td>
                    <td class="mono">#${esc(r.AUD_REGISTRO_PK)}</td>
                    <td class="text-end"><i class="bi bi-eye text-primary"></i></td>
                </tr>`;
            }).join('');
            tb.querySelectorAll('.aud-row').forEach(tr => tr.addEventListener('click', () => detalhe(tr.dataset.id)));
        }

        async function detalhe(id){
            const r = await fetch(`${EP}?acao=detalhe&id=${id}`);
            const j = await r.json();
            if(!j.ok){ Swal.fire('Erro', j.msg||'Falha', 'error'); return; }
            const g = j.registro;
            const dt = g.data_hora;
            const cabec = `
                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-3"><div class="ss">Quando</div><div class="mono small">${esc(dt)}</div></div>
                    <div class="col-6 col-md-3"><div class="ss">Quem</div><div>${esc(g.usuario_nome)||'—'}</div></div>
                    <div class="col-6 col-md-3"><div class="ss">Origem</div><div>${g.origem==='APP'?'Sistema (logado)':'<span class="text-danger fw-semibold">Edição direta no banco</span>'}${g.ip?` · <span class="mono small">${esc(g.ip)}</span>`:''}</div></div>
                    <div class="col-6 col-md-3"><div class="ss">Ação</div><div><span class="aud-badge aud-badge-${g.acao}">${acaoLabel(g.acao)}</span></div></div>
                    <div class="col-12"><div class="ss">Registro</div><div>${esc(g.tabela_label)} · <span class="mono">#${esc(g.registro_pk)}</span> <span class="text-muted small">(${esc(g.tabela)})</span></div></div>
                </div>`;
            let corpo;
            if(!j.campos.length){
                corpo = `<div class="alert alert-light border small mb-0">Nenhum campo alterado registrado.</div>`;
            } else {
                const linhas = j.campos.map(c => `
                    <tr>
                        <td class="small fw-semibold">${esc(c.campo)}</td>
                        <td class="diff-cell diff-de">${c.de==null?'<span class="text-muted">∅</span>':esc(c.de)}</td>
                        <td class="diff-cell diff-para">${c.para==null?'<span class="text-muted">∅</span>':esc(c.para)}</td>
                    </tr>`).join('');
                const tit = g.acao==='UPDATE' ? 'Campos alterados' : (g.acao==='INSERT'?'Valores criados':'Valores removidos');
                corpo = `<div class="ss mb-1">${tit} (${j.total_campos})</div>
                    <div class="table-responsive"><table class="table table-sm table-bordered mb-0">
                    <thead class="table-light"><tr><th>Campo</th><th>De (antes)</th><th>Para (depois)</th></tr></thead>
                    <tbody>${linhas}</tbody></table></div>`;
            }
            $('detalheBody').innerHTML = cabec + corpo;
            new bootstrap.Modal($('modalDetalhe')).show();
        }

        $('btnFiltrar').addEventListener('click', () => { pagina=1; listar(); });
        $('btnLimpar').addEventListener('click', () => {
            ['fUsuario','fTabela','fTipo','fOrigem','fRegistro','fBusca','fDe','fAte'].forEach(id=>$(id).value='');
            pagina=1; listar();
        });
        $('btnPrev').addEventListener('click', () => { if(pagina>1){ pagina--; listar(); } });
        $('btnNext').addEventListener('click', () => { if(pagina<paginas){ pagina++; listar(); } });
        $('fRegistro').addEventListener('keydown', e => { if(e.key==='Enter'){ pagina=1; listar(); } });
        $('fBusca').addEventListener('keydown', e => { if(e.key==='Enter'){ pagina=1; listar(); } });

        carregarCombos();
        listar();
    </script>
</body>
</html>
