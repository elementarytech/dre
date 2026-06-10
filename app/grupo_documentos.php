<?php
// /app/grupo_documentos.php
declare(strict_types=1);
require_once __DIR__ . '/config/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <title>DRE - CNPJs/CPFs do Grupo</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .badge-soft-success { background: rgba(34,197,94,.12); color:#14532d; border-radius:999px; padding:.3rem .65rem; font-size:.78rem; font-weight:600; }
        .badge-soft-secondary { background: rgba(100,116,139,.12); color:#475569; border-radius:999px; padding:.3rem .65rem; font-size:.78rem; font-weight:600; }
        .help { font-size:.86rem; color:#64748b; }
        .help-mini { font-size:.82rem; color:#64748b; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
        .table thead th { font-size:.78rem; letter-spacing:.06em; text-transform:uppercase; color:#6b7280; border-bottom:1px solid rgba(17,24,39,.08) !important; }
    </style>
</head>
<body data-page="config">
<div class="d-flex" id="wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div id="page-content-wrapper" class="flex-grow-1">

        <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
            <button class="btn btn-outline-secondary me-2" id="menu-toggle"><i class="fa-solid fa-bars"></i></button>
            <span class="navbar-brand mb-0 h6 d-none d-sm-inline">CNPJs/CPFs do Grupo</span>
            <div class="collapse navbar-collapse justify-content-end">
                <div class="d-flex align-items-center gap-2">
                    <span class="small text-muted"><?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?></span>
                    <a class="btn btn-sm btn-outline-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-1"></i>Sair</a>
                </div>
            </div>
        </nav>

        <div class="container-fluid py-4">

            <div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
                <div>
                    <h5 class="mb-1 mt-1">CNPJs / CPFs do Grupo</h5>
                    <p class="help mb-0">
                        Cadastro de documentos que representam contas "da casa".
                        Movimentos OFX cujo MEMO contenha um desses documentos são tratados como
                        <strong>transferências internas</strong> — não viram receita/despesa nem aparecem nos modais de conciliação.
                    </p>
                </div>
                <div class="mt-2 mt-sm-0">
                    <a href="conciliacao_bancaria.php" class="btn btn-sm btn-outline-secondary me-1">
                        <i class="fa-solid fa-arrow-left me-1"></i>Voltar à Conciliação
                    </a>
                    <button id="btnNovo" type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalDoc">
                        <i class="fa-solid fa-plus me-1"></i>Novo cadastro
                    </button>
                </div>
            </div>

            <div class="alert alert-info border-0 small py-2 mb-3">
                <i class="bi bi-info-circle me-1"></i>
                CNPJs das empresas cadastradas já foram importados automaticamente.
                <strong>Adicione CPFs de sócios</strong> (tipo PF) para detectar PIX entre empresa e sócios como transferência interna.
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted">Documentos cadastrados</span>
                        <span class="small text-muted" id="lblTotal">—</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:80px;">Tipo</th>
                                    <th>Documento</th>
                                    <th>Nome</th>
                                    <th>Observação</th>
                                    <th>Cadastrado em</th>
                                    <th>Situação</th>
                                    <th class="text-end" style="width:140px;">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="tbDocs"><tr><td colspan="7" class="text-muted small">Carregando…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <footer class="text-muted small mt-4">© <?= date('Y') ?> DRE - Sistema Financeiro</footer>
        </div>
    </div>
</div>

<!-- Modal Cadastro -->
<div class="modal fade" id="modalDoc" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-id-card me-1 text-primary"></i><span id="mTitulo">Novo cadastro</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="frmDoc" autocomplete="off">
                    <input type="hidden" id="GDO_ID" value="">

                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small">Tipo *</label>
                            <select id="GDO_TIPO" class="form-select form-select-sm">
                                <option value="PJ">PJ (CNPJ)</option>
                                <option value="PF">PF (CPF)</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small">Documento *</label>
                            <input type="text" class="form-control form-control-sm mono" id="GDO_DOC" placeholder="CNPJ ou CPF (só números ou formatado)">
                            <div class="help-mini mt-1">Aceita com ou sem pontuação. Salvamos só os dígitos.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Nome *</label>
                            <input type="text" class="form-control form-control-sm" id="GDO_NOME" placeholder="Ex: AVS Apoio Adm, Diogo André, etc.">
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Observação</label>
                            <textarea class="form-control form-control-sm" id="GDO_OBS" rows="2" placeholder="Opcional"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-sm btn-primary" id="btnSalvar"><i class="fa-solid fa-check me-1"></i>Salvar</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/scripts.php'; ?>

<script>
const ENDPOINT = 'endpoints/grupo_documentos.php';
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

async function api(params, method='GET') {
    let url = ENDPOINT;
    const opt = { method };
    if (method === 'GET') {
        url += '?' + new URLSearchParams(params).toString();
    } else {
        const fd = new FormData();
        Object.entries(params).forEach(([k, v]) => fd.append(k, v ?? ''));
        opt.body = fd;
    }
    const r = await fetch(url, opt);
    const j = await r.json();
    if (!j.ok) throw new Error(j.msg || 'Erro');
    return j;
}

function fmtDoc(doc, tipo) {
    const d = String(doc || '').replace(/\D/g,'');
    if (tipo === 'PJ' && d.length === 14) {
        return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
    }
    if (tipo === 'PF' && d.length === 11) {
        return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
    }
    return doc;
}

async function carregar() {
    const tb = document.getElementById('tbDocs');
    tb.innerHTML = '<tr><td colspan="7" class="text-muted small text-center py-3">Carregando…</td></tr>';
    try {
        const j = await api({ acao: 'listar' });
        const rows = j.rows || [];
        document.getElementById('lblTotal').textContent = rows.length + ' documento(s)';
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="7" class="text-muted small text-center py-3">Nenhum documento cadastrado.</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(r => `
            <tr ${r.GDO_STATUS === 'INATIVO' ? 'class="text-muted"' : ''}>
                <td><span class="badge ${r.GDO_TIPO==='PJ'?'bg-primary-subtle text-primary':'bg-warning-subtle text-warning-emphasis'}">${esc(r.GDO_TIPO)}</span></td>
                <td class="mono">${esc(fmtDoc(r.GDO_DOCUMENTO, r.GDO_TIPO))}</td>
                <td class="fw-semibold">${esc(r.GDO_NOME)}</td>
                <td class="small text-muted">${esc(r.GDO_OBSERVACAO || '—')}</td>
                <td class="small">${esc(r.data_cadastro_br || '—')}<br><span class="text-muted" style="font-size:.72rem">${esc(r.GDO_USUARIO || '')}</span></td>
                <td>${r.GDO_STATUS === 'ATIVO'
                    ? '<span class="badge-soft-success">Ativo</span>'
                    : '<span class="badge-soft-secondary">Inativo</span>'}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary" onclick="editar(${r.GDO_CODIGO_PK}, ${JSON.stringify(r).replace(/"/g,'&quot;')})"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm ${r.GDO_STATUS==='ATIVO' ? 'btn-outline-secondary' : 'btn-outline-success'}"
                            onclick="alternarStatus(${r.GDO_CODIGO_PK})"
                            title="${r.GDO_STATUS==='ATIVO' ? 'Desativar' : 'Reativar'}">
                        <i class="bi ${r.GDO_STATUS==='ATIVO' ? 'bi-toggle-on' : 'bi-toggle-off'}"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        tb.innerHTML = `<tr><td colspan="7" class="text-danger small text-center py-3">Erro: ${esc(err.message)}</td></tr>`;
    }
}

function abrirNovo() {
    document.getElementById('GDO_ID').value = '';
    document.getElementById('GDO_TIPO').value = 'PJ';
    document.getElementById('GDO_DOC').value = '';
    document.getElementById('GDO_NOME').value = '';
    document.getElementById('GDO_OBS').value = '';
    document.getElementById('mTitulo').textContent = 'Novo cadastro';
}

window.editar = function(id, raw) {
    let row = raw;
    if (typeof raw === 'string') {
        try { row = JSON.parse(raw.replace(/&quot;/g,'"')); } catch(e) { row = {}; }
    }
    document.getElementById('GDO_ID').value = id;
    document.getElementById('GDO_TIPO').value = row.GDO_TIPO || 'PJ';
    document.getElementById('GDO_DOC').value = row.GDO_DOCUMENTO || '';
    document.getElementById('GDO_NOME').value = row.GDO_NOME || '';
    document.getElementById('GDO_OBS').value = row.GDO_OBSERVACAO || '';
    document.getElementById('mTitulo').textContent = 'Editar cadastro';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDoc')).show();
};

window.alternarStatus = async function(id) {
    try {
        await api({ acao: 'alternar_status', id }, 'POST');
        await carregar();
    } catch (err) {
        Swal.fire({icon:'error', title:'Erro', text: err.message});
    }
};

document.getElementById('btnNovo').addEventListener('click', abrirNovo);

document.getElementById('btnSalvar').addEventListener('click', async () => {
    const payload = {
        acao: 'salvar',
        id: document.getElementById('GDO_ID').value,
        tipo: document.getElementById('GDO_TIPO').value,
        documento: document.getElementById('GDO_DOC').value.trim(),
        nome: document.getElementById('GDO_NOME').value.trim(),
        observacao: document.getElementById('GDO_OBS').value.trim(),
    };
    try {
        await api(payload, 'POST');
        bootstrap.Modal.getInstance(document.getElementById('modalDoc'))?.hide();
        await carregar();
        Swal.fire({icon:'success', title:'Salvo!', timer:1200, showConfirmButton:false});
    } catch (err) {
        Swal.fire({icon:'error', title:'Não foi possível salvar', text: err.message});
    }
});

carregar();
</script>
<script src="assets/session_keeper.js" defer></script>
</body>
</html>
