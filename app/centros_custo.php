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
    <title>DRE - Centros de Custo</title>
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
    </style>
</head>

<body data-page="cadastros-centros-custo">
    <div class="d-flex" id="wrapper">
        <?php include $APP_ROOT . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">
            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle"><i class="fa-solid fa-bars"></i></button>
                <span class="navbar-brand mb-0 h6">Centros de Custo</span>
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
                                <i class="fa-solid fa-plus me-1"></i>Novo Centro de Custo
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
                                        <th style="width:120px">Código</th>
                                        <th>Nome</th>
                                        <th style="width:120px">Status</th>
                                        <th style="width:120px" class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="tb">
                                    <tr>
                                        <td colspan="4" class="text-muted small">Carregando...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="small text-muted mt-2" id="infoTotal"></div>
                    </div>
                </div>

                <footer class="text-muted small mt-4">© <?= date('Y') ?> DRE - Sistema Financeiro</footer>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="modalCC" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="modalTitle">Centro de custo</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="CEC_ID" value="0" />
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Código</label>
                            <input type="text" class="form-control form-control-sm" id="CEC_CODIGO" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1">Nome</label>
                            <input type="text" class="form-control form-control-sm" id="CEC_NOME" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Status</label>
                            <select class="form-select form-select-sm" id="CEC_STATUS">
                                <option value="ATIVO">Ativo</option>
                                <option value="INATIVO">Inativo</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small mb-1">Empresa</label>
                            <select class="form-select form-select-sm" id="CEC_EMPRESA_FK">
                                <option value="">Selecione uma empresa...</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1">Observações</label>
                            <textarea class="form-control form-control-sm" rows="3" id="CEC_OBS"></textarea>
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
        const ENDPOINT = 'endpoints/centros_custo.php';
        const modal = new bootstrap.Modal(document.getElementById('modalCC'));

        function badgeStatus(s) {
            s = (s || '').toUpperCase();
            if (s === 'ATIVO') return `<span class="badge-soft-success">Ativo</span>`;
            return `<span class="badge-soft-danger">Inativo</span>`;
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

        async function carregarEmpresas() {
            const sel = document.getElementById('CEC_EMPRESA_FK');
            try {
                const j = await api({
                    acao: 'empresas_combo'
                }, 'GET');
                // mantém a option vazia inicial e adiciona as empresas
                j.rows.forEach(emp => {
                    const opt = document.createElement('option');
                    opt.value = emp.id;
                    opt.textContent = emp.nome;
                    sel.appendChild(opt);
                });
            } catch (e) {
                console.warn('Não foi possível carregar empresas:', e.message);
            }
        }

        function limparModal() {
            document.getElementById('CEC_ID').value = '0';
            document.getElementById('CEC_CODIGO').value = '';
            document.getElementById('CEC_NOME').value = '';
            document.getElementById('CEC_STATUS').value = 'ATIVO';
            document.getElementById('CEC_EMPRESA_FK').value = '';
            document.getElementById('CEC_OBS').value = '';
        }

        async function listar() {
            const buscar = document.getElementById('fBuscar').value.trim();
            const status = document.getElementById('fStatus').value;

            const j = await api({
                acao: 'listar',
                buscar,
                status
            }, 'GET');
            const tb = document.getElementById('tb');
            tb.innerHTML = '';

            if (!j.rows || j.rows.length === 0) {
                tb.innerHTML = `<tr><td colspan="4" class="text-muted small">Nenhum registro.</td></tr>`;
                document.getElementById('infoTotal').textContent = '';
                return;
            }

            j.rows.forEach(r => {
                tb.insertAdjacentHTML('beforeend', `
        <tr>
          <td>${r.CEC_CODIGO ?? ''}</td>
          <td>${r.CEC_NOME ?? ''}</td>
          <td>${badgeStatus(r.CEC_STATUS)}</td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary me-1" data-act="edit" data-id="${r.CEC_ID}">
              <i class="fa-solid fa-pen"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" data-act="del" data-id="${r.CEC_ID}">
              <i class="fa-solid fa-trash"></i>
            </button>
          </td>
        </tr>
      `);
            });

            document.getElementById('infoTotal').textContent = `${j.total} registro(s)`;
        }

        async function abrirEditar(id) {
            const j = await api({
                acao: 'get',
                id
            }, 'GET');
            const r = j.row;

            document.getElementById('CEC_ID').value = r.CEC_ID;
            document.getElementById('CEC_CODIGO').value = r.CEC_CODIGO ?? '';
            document.getElementById('CEC_NOME').value = r.CEC_NOME ?? '';
            document.getElementById('CEC_STATUS').value = (r.CEC_STATUS ?? 'ATIVO');
            document.getElementById('CEC_EMPRESA_FK').value = r.CEC_EMPRESA_FK ?? '';
            document.getElementById('CEC_OBS').value = r.CEC_OBS ?? '';

            document.getElementById('modalTitle').textContent = 'Editar centro de custo';
            modal.show();
        }

        async function salvar() {
            const id = document.getElementById('CEC_ID').value;
            const CEC_CODIGO = document.getElementById('CEC_CODIGO').value.trim();
            const CEC_NOME = document.getElementById('CEC_NOME').value.trim();
            const CEC_STATUS = document.getElementById('CEC_STATUS').value;
            const CEC_EMPRESA_FK = document.getElementById('CEC_EMPRESA_FK').value;
            const CEC_OBS = document.getElementById('CEC_OBS').value;

            const j = await api({
                acao: 'salvar',
                CEC_ID: id,
                CEC_CODIGO,
                CEC_NOME,
                CEC_STATUS,
                CEC_EMPRESA_FK,
                CEC_OBS
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
                text: 'Confirma excluir este centro de custo?',
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
        document.getElementById('btnFiltrar').addEventListener('click', () => listar().catch(err => Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: err.message
        })));
        document.getElementById('btnNovo').addEventListener('click', () => {
            limparModal();
            document.getElementById('modalTitle').textContent = 'Novo centro de custo';
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

        // init
        (async () => {
            try {
                await carregarEmpresas();
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