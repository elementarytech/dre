<?php

declare(strict_types=1);

// raiz do app (…/dre/app)
$APP_ROOT = realpath(__DIR__);
if (!$APP_ROOT) {
    http_response_code(500);
    exit('APP_ROOT inválido');
}

require_once $APP_ROOT . '/config/auth.php';
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>DRE - Plano de Contas</title>
    <?php include $APP_ROOT . '/includes/head.php'; ?>

    <style>
        .dashboard-card {
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(16, 24, 40, .06);
            border: 1px solid rgba(17, 24, 39, .06);
        }

        .table-dashboard thead th {
            font-size: .78rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 1px solid rgba(17, 24, 39, .08) !important;
        }

        .badge-soft-success {
            background: rgba(34, 197, 94, .12);
            color: #14532d;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem;
        }

        .badge-soft-danger {
            background: rgba(239, 68, 68, .12);
            color: #991b1b;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem;
        }

        .badge-soft-primary {
            background: rgba(59, 130, 246, .12);
            color: #1e3a8a;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem;
        }

        .badge-soft-warning {
            background: rgba(245, 158, 11, .14);
            color: #92400e;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem;
        }
    </style>
</head>

<body data-page="cadastros-plano-contas">
    <div class="d-flex" id="wrapper">
        <?php include $APP_ROOT . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">
            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle"><i class="fa-solid fa-bars"></i></button>
                <span class="navbar-brand mb-0 h6">Plano de Contas</span>
                <div class="collapse navbar-collapse justify-content-end">
                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted">
                            <?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?> (<?= htmlspecialchars($_SESSION['user_perfil'] ?? 'USER') ?>)
                        </span>
                        <a class="btn btn-sm btn-outline-danger" href="../logout.php"><i class="fa-solid fa-right-from-bracket me-1"></i>Sair</a>
                    </div>
                </div>
            </nav>

            <div class="container-fluid py-4">
                <div class="card dashboard-card mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                            <div class="d-flex flex-wrap gap-2">
                                <input class="form-control form-control-sm" style="width:260px" id="fBuscar" placeholder="Buscar por código ou nome..." />

                                <select class="form-select form-select-sm" style="width:220px" id="fEmpresa">
                                    <option value="">Empresa (todas)</option>
                                </select>
                                <select class="form-select form-select-sm" style="width:170px" id="fTipo">
                                    <option value="">Tipo (todos)</option>
                                    <option value="Ativo">Ativo</option>
                                    <option value="Passivo">Passivo</option>
                                    <option value="Receita">Receita</option>
                                    <option value="Despesa">Despesa</option>
                                    <option value="Resultado">Resultado</option>
                                </select>
                                <select class="form-select form-select-sm" style="width:170px" id="fStatus">
                                    <option value="">Status (todos)</option>
                                    <option value="ATIVO">Ativo</option>
                                    <option value="INATIVO">Inativo</option>
                                </select>
                                <button class="btn btn-sm btn-outline-secondary" id="btnFiltrar">
                                    <i class="fa-solid fa-filter me-1"></i>Filtrar
                                </button>
                            </div>

                            <button class="btn btn-sm btn-primary" id="btnNovo">
                                <i class="fa-solid fa-plus me-1"></i>Novo Plano de Contas
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card dashboard-card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 table-dashboard">
                                <thead>
                                    <tr>
                                        <th style="width:130px">Código</th>
                                        <th>Nome</th>
                                        <th style="width:220px">Empresa</th>
                                        <th style="width:120px">Tipo</th>
                                        <th style="width:120px">Status</th>
                                        <th style="width:120px" class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="tb">
                                    <tr>
                                        <td colspan="6" class="text-muted small">Carregando...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                            <div class="small text-muted" id="infoTotal"></div>
                            <div class="d-flex align-items-center gap-2">
                                <label class="small text-muted mb-0">Por página</label>
                                <select class="form-select form-select-sm" style="width:90px" id="perPage">
                                    <option value="10">10</option>
                                    <option value="20" selected>20</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>
                        <nav class="mt-2" aria-label="Paginação">
                            <ul class="pagination pagination-sm mb-0 justify-content-end" id="paginacao"></ul>
                        </nav>
                    </div>
                </div>

                <footer class="text-muted small mt-4">© <?= date('Y') ?> DRE - Sistema Financeiro</footer>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="modalPLC" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="modalTitle">Plano de contas</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="PLC_ID" value="0" />
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Empresa</label>
                            <select class="form-select form-select-sm" id="PLC_EMPRESA_FK">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small mb-1">Conta Pai (opcional)</label>
                            <select class="form-select form-select-sm" id="PLC_PARENT_ID">
                                <option value="">— Nenhuma —</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Nível</label>
                            <input type="number" class="form-control form-control-sm" id="PLC_NIVEL" value="1" readonly />
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small mb-1">Código</label>
                            <input type="text" class="form-control form-control-sm" id="PLC_CODIGO" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1">Nome</label>
                            <input type="text" class="form-control form-control-sm" id="PLC_NOME" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Tipo</label>
                            <select class="form-select form-select-sm" id="PLC_TIPO">
                                <option value="Ativo">Ativo</option>
                                <option value="Passivo">Passivo</option>
                                <option value="Receita">Receita</option>
                                <option value="Despesa">Despesa</option>
                                <option value="Resultado">Resultado</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Status</label>
                            <select class="form-select form-select-sm" id="PLC_STATUS">
                                <option value="ATIVO">Ativo</option>
                                <option value="INATIVO">Inativo</option>
                            </select>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label small mb-1">Observações</label>
                            <textarea class="form-control form-control-sm" rows="2" id="PLC_OBS"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-sm btn-primary" id="btnSalvar">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include $APP_ROOT . '/includes/scripts.php'; ?>
    <script>
        const ENDPOINT = 'endpoints/plano_contas.php';
        const modal = new bootstrap.Modal(document.getElementById('modalPLC'));

        const state = {
            page: 1,
            perPage: 20,
            pages: 1,
            total: 0,
            from: 0,
            to: 0,
        };

        function badgeStatus(s) {
            s = (s || '').toUpperCase();
            if (s === 'ATIVO') return `<span class="badge-soft-success">Ativo</span>`;
            return `<span class="badge-soft-danger">Inativo</span>`;
        }

        function badgeTipo(v) {
            const t = String(v || '').trim();
            const map = {
                'Ativo': 'badge-soft-primary',
                'Passivo': 'badge-soft-warning',
                'Receita': 'badge-soft-success',
                'Despesa': 'badge-soft-danger',
                'Resultado': 'badge-soft-primary'
            };
            const cls = map[t] || 'badge-soft-primary';
            return `<span class="${cls}">${t || '-'}</span>`;
        }

        async function api(params, method = 'GET') {
            let url = ENDPOINT;
            const opt = {
                method
            };
            if (method === 'GET') {
                url += '?' + new URLSearchParams(params).toString();
            } else {
                const fd = new FormData();
                Object.entries(params).forEach(([k, v]) => fd.append(k, v ?? ''));
                opt.body = fd;
            }
            const r = await fetch(url, opt);
            const txt = await r.text();
            let j;
            try {
                j = JSON.parse(txt);
            } catch (e) {
                console.error(txt);
                throw new Error('Endpoint não retornou JSON.');
            }
            if (!j.ok) throw new Error(j.msg || 'Falha na requisição.');
            return j;
        }

        function computeNivelByCodigo(cod) {
            cod = String(cod || '').trim();
            if (!cod) return 1;
            // padrão: 1.01.02 -> nível 3
            const parts = cod.split('.').filter(Boolean);
            return parts.length ? parts.length : 1;
        }

        async function carregarEmpresas(selectIdList = []) {
            const j = await api({
                acao: 'empresas_combo'
            }, 'GET');
            const rows = (j.rows || []).map(r => ({
                id: r.EMP_ID ?? r.id,
                nome: r.EMP_RAZAO_SOCIAL ?? r.nome ?? r.EMP_NOME ?? r.EMP_NOME_FANTASIA
            })).filter(r => r.id);

            selectIdList.forEach(selId => {
                const sel = document.getElementById(selId);
                if (!sel) return;
                const current = sel.value;
                sel.innerHTML = '<option value="">Empresa (todas)</option>' +
                    rows.map(r => `<option value="${r.id}">${r.nome ?? ''}</option>`).join('');
                if (current) sel.value = current;
            });

            // modal select (sem "todas")
            const selModal = document.getElementById('PLC_EMPRESA_FK');
            if (selModal) {
                const cur = selModal.value;
                selModal.innerHTML = '<option value="">Selecione...</option>' +
                    rows.map(r => `<option value="${r.id}">${r.nome ?? ''}</option>`).join('');
                if (cur) selModal.value = cur;
            }
        }

        async function carregarPais(empresaId, selectedId = '') {
            const sel = document.getElementById('PLC_PARENT_ID');
            if (!sel) return;
            sel.innerHTML = '<option value="">— Nenhuma —</option>';

            // Plano de contas é global: carrega os pais mesmo sem empresa selecionada.
            const j = await api({
                acao: 'pais_combo',
                empresa_fk: empresaId || ''
            }, 'GET');
            const rows = j.rows || [];
            sel.insertAdjacentHTML('beforeend',
                rows.map(r => {
                    const id = r.PLC_ID ?? r.PLC_CODIGO_PK ?? r.id;
                    const label = r.PLC_LABEL ?? `${r.PLC_CODIGO ?? ''} - ${r.PLC_NOME ?? ''}`.trim();
                    return `<option value="${id}">${label}</option>`;
                }).join('')
            );
            if (selectedId) sel.value = String(selectedId);
        }


        function limparModal() {
            document.getElementById('PLC_ID').value = '0';
            document.getElementById('PLC_EMPRESA_FK').value = '';
            document.getElementById('PLC_PARENT_ID').value = '';
            document.getElementById('PLC_NIVEL').value = '1';
            document.getElementById('PLC_CODIGO').value = '';
            document.getElementById('PLC_NOME').value = '';
            document.getElementById('PLC_TIPO').value = 'Despesa';
            document.getElementById('PLC_STATUS').value = 'ATIVO';
            document.getElementById('PLC_OBS').value = '';
        }

        async function listar() {
            const buscar = document.getElementById('fBuscar').value.trim();
            const empresa_fk = document.getElementById('fEmpresa').value;
            const tipo = document.getElementById('fTipo').value;
            const status = document.getElementById('fStatus').value;

            state.perPage = parseInt(document.getElementById('perPage').value || '20', 10) || 20;

            const j = await api({
                acao: 'listar',
                buscar,
                empresa_fk,
                tipo,
                status,
                page: state.page,
                per_page: state.perPage
            }, 'GET');

            state.total = parseInt(j.total || 0, 10) || 0;
            state.pages = parseInt(j.pages || 1, 10) || 1;
            state.from = parseInt(j.from || 0, 10) || 0;
            state.to = parseInt(j.to || 0, 10) || 0;

            const tb = document.getElementById('tb');
            tb.innerHTML = '';

            if (!j.rows || j.rows.length === 0) {
                tb.innerHTML = `<tr><td colspan="6" class="text-muted small">Nenhum registro.</td></tr>`;
                document.getElementById('infoTotal').textContent = '0 registro(s)';
                renderPaginacao();
                return;
            }

            j.rows.forEach(r => {
                tb.insertAdjacentHTML('beforeend', `
        <tr>
          <td>${r.PLC_CODIGO ?? ''}</td>
          <td>${r.PLC_NOME ?? ''}</td>
          <td>${r.EMP_RAZAO_SOCIAL ?? r.EMP_NOME ?? ''}</td>
          <td>${badgeTipo(r.PLC_TIPO)}</td>
          <td>${badgeStatus(r.PLC_STATUS)}</td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary me-1" data-act="edit" data-id="${r.PLC_ID}">
              <i class="fa-solid fa-pen"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" data-act="del" data-id="${r.PLC_ID}">
              <i class="fa-solid fa-trash"></i>
            </button>
          </td>
        </tr>
      `);
            });

            if (state.total > 0) {
                document.getElementById('infoTotal').textContent = `Mostrando ${state.from}-${state.to} de ${state.total} registro(s)`;
            } else {
                document.getElementById('infoTotal').textContent = '0 registro(s)';
            }

            renderPaginacao();
        }

        function renderPaginacao() {
            const ul = document.getElementById('paginacao');
            if (!ul) return;

            ul.innerHTML = '';
            const pages = state.pages || 1;
            const page = state.page || 1;

            const add = (label, p, disabled = false, active = false) => {
                ul.insertAdjacentHTML('beforeend', `
                    <li class="page-item ${disabled ? 'disabled' : ''} ${active ? 'active' : ''}">
                        <a class="page-link" href="#" data-page="${p}">${label}</a>
                    </li>
                `);
            };

            add('«', Math.max(1, page - 1), page <= 1);

            // janela de páginas (máx 7)
            let start = Math.max(1, page - 3);
            let end = Math.min(pages, start + 6);
            start = Math.max(1, end - 6);

            if (start > 1) {
                add('1', 1, false, page === 1);
                if (start > 2) {
                    ul.insertAdjacentHTML('beforeend', `<li class="page-item disabled"><span class="page-link">…</span></li>`);
                }
            }

            for (let p = start; p <= end; p++) {
                add(String(p), p, false, p === page);
            }

            if (end < pages) {
                if (end < pages - 1) {
                    ul.insertAdjacentHTML('beforeend', `<li class="page-item disabled"><span class="page-link">…</span></li>`);
                }
                add(String(pages), pages, false, page === pages);
            }

            add('»', Math.min(pages, page + 1), page >= pages);
        }

        async function abrirEditar(id) {
            const j = await api({
                acao: 'get',
                id
            }, 'GET');
            const r = j.row;

            document.getElementById('PLC_ID').value = r.PLC_ID ?? r.PLC_CODIGO_PK ?? '';
            document.getElementById('PLC_CODIGO').value = r.PLC_CODIGO ?? '';
            document.getElementById('PLC_NOME').value = r.PLC_NOME ?? '';
            document.getElementById('PLC_TIPO').value = (r.PLC_TIPO ?? 'Despesa');
            document.getElementById('PLC_STATUS').value = (r.PLC_STATUS ?? 'ATIVO');
            document.getElementById('PLC_OBS').value = r.PLC_OBS ?? '';

            document.getElementById('PLC_EMPRESA_FK').value = String(r.PLC_EMPRESA_FK ?? '');
            document.getElementById('PLC_NIVEL').value = String(r.PLC_NIVEL ?? computeNivelByCodigo(r.PLC_CODIGO));
            await carregarPais(r.PLC_EMPRESA_FK ?? '', r.PLC_PARENT_ID ?? '');

            document.getElementById('modalTitle').textContent = 'Editar plano de contas';
            modal.show();
        }

        async function salvar() {
            const id = document.getElementById('PLC_ID').value;
            const PLC_CODIGO = document.getElementById('PLC_CODIGO').value.trim();
            const PLC_NOME = document.getElementById('PLC_NOME').value.trim();
            const PLC_EMPRESA_FK = document.getElementById('PLC_EMPRESA_FK').value;
            const PLC_PARENT_ID = document.getElementById('PLC_PARENT_ID').value;
            const PLC_NIVEL = document.getElementById('PLC_NIVEL').value;

            const PLC_TIPO = document.getElementById('PLC_TIPO').value;
            const PLC_STATUS = document.getElementById('PLC_STATUS').value;
            const PLC_OBS = document.getElementById('PLC_OBS').value;

            const j = await api({
                acao: 'salvar',
                PLC_ID: id,
                PLC_EMPRESA_FK,
                PLC_PARENT_ID,
                PLC_NIVEL,
                PLC_CODIGO,
                PLC_NOME,
                PLC_TIPO,
                PLC_STATUS,
                PLC_OBS
            }, 'POST');

            Swal.fire({
                icon: 'success',
                title: 'Ok',
                text: j.msg,
                timer: 900,
                showConfirmButton: false
            });
            modal.hide();
            await listar();
        }

        async function excluir(id) {
            const c = await Swal.fire({
                icon: 'warning',
                title: 'Excluir?',
                text: 'Confirma excluir este plano de contas?',
                showCancelButton: true,
                confirmButtonText: 'Sim, excluir',
                cancelButtonText: 'Cancelar'
            });
            if (!c.isConfirmed) return;

            const j = await api({
                acao: 'excluir',
                id
            }, 'POST');
            Swal.fire({
                icon: 'success',
                title: 'Ok',
                text: j.msg,
                timer: 900,
                showConfirmButton: false
            });
            await listar();
        }

        // binds
        document.getElementById('btnFiltrar').addEventListener('click', () => {
            state.page = 1;
            listar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('fBuscar').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                state.page = 1;
                listar().catch(err => Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: err.message
                }));
            }
        });

        document.getElementById('perPage').addEventListener('change', () => {
            state.page = 1;
            listar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('paginacao').addEventListener('click', (e) => {
            const a = e.target.closest('a[data-page]');
            if (!a) return;
            e.preventDefault();
            const p = parseInt(a.dataset.page || '1', 10) || 1;
            if (p === state.page) return;
            state.page = p;
            listar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        // (removido listener duplicado do btnFiltrar)
        document.getElementById('fEmpresa').addEventListener('change', () => {
            state.page = 1;
            listar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('fTipo').addEventListener('change', () => {
            state.page = 1;
            listar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('fStatus').addEventListener('change', () => {
            state.page = 1;
            listar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        // (removido listener duplicado do fEmpresa)

        document.getElementById('btnNovo').addEventListener('click', async () => {
            limparModal();
            document.getElementById('modalTitle').textContent = 'Novo plano de contas';
            await carregarPais('');   // carrega contas pai (plano global) já ao abrir
            modal.show();
        });
        document.getElementById('btnSalvar').addEventListener('click', () => salvar().catch(err => Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: err.message
        })));

        document.getElementById('tb').addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-act]');
            if (!btn) return;
            const act = btn.dataset.act;
            const id = btn.dataset.id;
            if (act === 'edit') abrirEditar(id).catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
            if (act === 'del') excluir(id).catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        // binds extra
        document.getElementById('PLC_CODIGO').addEventListener('blur', () => {
            const cod = document.getElementById('PLC_CODIGO').value;
            document.getElementById('PLC_NIVEL').value = String(computeNivelByCodigo(cod));
        });
        document.getElementById('PLC_EMPRESA_FK').addEventListener('change', async () => {
            await carregarPais(document.getElementById('PLC_EMPRESA_FK').value, '');
        });

        // Ao escolher a conta pai: autocompleta o próximo código de filho livre + nível.
        // Sem conta pai: libera o código para o usuário criar a conta pai que quiser.
        document.getElementById('PLC_PARENT_ID').addEventListener('change', async () => {
            const parentId = document.getElementById('PLC_PARENT_ID').value;
            const codInp = document.getElementById('PLC_CODIGO');
            const nivelInp = document.getElementById('PLC_NIVEL');

            if (!parentId) {
                // Conta raiz/pai: usuário digita o código livremente.
                codInp.readOnly = false;
                codInp.focus();
                nivelInp.value = String(computeNivelByCodigo(codInp.value));
                return;
            }

            try {
                const j = await api({
                    acao: 'proximo_codigo_filho',
                    parent_id: parentId
                }, 'GET');
                if (j.ok) {
                    codInp.value = j.codigo;          // ex.: 01.02.012 (próximo livre)
                    nivelInp.value = String(j.nivel); // nível derivado do código
                    codInp.readOnly = false;          // editável, mas backend bloqueia duplicidade
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Erro', text: err.message });
            }
        });

        // init
        (async () => {
            try {
                await carregarEmpresas(['fEmpresa']);
                await listar();
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: err.message
                });
            }
        })();
    </script>
  <script src="assets/session_keeper.js" defer></script>
</body>

</html>