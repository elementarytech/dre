<?php
// /app/formas_pagamento.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Formas de Pagamento</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .table thead th {
            font-size: .78rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 1px solid rgba(17, 24, 39, .08) !important;
        }

        .help {
            font-size: .86rem;
            color: #64748b;
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

        .toolbar-card .form-label {
            font-size: .8rem;
            color: #6b7280;
        }

        .toolbar-card .form-control,
        .toolbar-card .form-select {
            min-height: 34px;
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

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Formas de Pagamento</span>

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
                <div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
                    <div>
                        <h5 class="mb-1 mt-1">Cadastro de formas de pagamento</h5>
                        <p class="help mb-0">Cadastre e gerencie as formas de pagamento utilizadas no financeiro.</p>
                    </div>
                    <div class="mt-2 mt-sm-0">
                        <button id="btnNovo" type="button" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-plus me-1"></i>Nova forma de pagamento
                        </button>
                    </div>
                </div>

                <div class="card mb-3 toolbar-card">
                    <div class="card-body py-3">
                        <form class="row g-2 align-items-end" id="frmFiltros" onsubmit="return false;">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Buscar</label>
                                <input type="text" class="form-control form-control-sm" id="fBuscar" placeholder="ID ou descrição...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Status</label>
                                <select class="form-select form-select-sm" id="fStatus">
                                    <option value="">Todos</option>
                                    <option value="ATIVO">Ativo</option>
                                    <option value="INATIVO">Inativo</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex gap-2">
                                <button type="button" id="btnFiltrar" class="btn btn-sm btn-primary w-100">
                                    <i class="fa-solid fa-magnifying-glass me-1"></i>Filtrar
                                </button>
                                <button type="button" id="btnLimpar" class="btn btn-sm btn-outline-secondary w-100">
                                    Limpar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th style="width: 90px;">ID</th>
                                        <th>Descrição</th>
                                        <th style="width: 140px;">Status</th>
                                        <th style="width: 160px;" class="text-end pe-3">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">Carregando...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalFormaPagamento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitulo">Nova forma de pagamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <form id="frmFormaPagamento" onsubmit="return false;">
                        <input type="hidden" id="FPG_CODIGO_PK" value="0">

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label small">Descrição <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="FPG_DESCRICAO" maxlength="255" placeholder="Ex: PIX, Cartão de Crédito, Boleto...">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Status</label>
                                <select class="form-select form-select-sm" id="FPG_STATUS">
                                    <option value="ATIVO">Ativo</option>
                                    <option value="INATIVO">Inativo</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger btn-sm me-auto d-none" id="btnExcluir">
                        <i class="fa-solid fa-trash me-1"></i>Excluir
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnSalvar">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>
    <script>
        const ENDPOINT = 'endpoints/formas_pagamento.php';
        const modalFormaPagamento = new bootstrap.Modal(document.getElementById('modalFormaPagamento'));
        const tbody = document.getElementById('tbody');

        const safe = v => (v ?? '').toString();

        function badgeStatus(s) {
            return String(s).toUpperCase() === 'ATIVO' ?
                '<span class="badge-soft-success">ATIVO</span>' :
                '<span class="badge-soft-danger">INATIVO</span>';
        }

        async function api(params = {}, method = 'GET') {
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
                console.error('NÃO JSON:', txt);
                throw new Error('Endpoint não retornou JSON. Veja o console.');
            }

            if (!j.ok) throw new Error(j.msg || 'Falha na requisição.');
            return j;
        }

        function limparForm() {
            document.getElementById('FPG_CODIGO_PK').value = '0';
            document.getElementById('FPG_DESCRICAO').value = '';
            document.getElementById('FPG_STATUS').value = 'ATIVO';
            document.getElementById('modalTitulo').textContent = 'Nova forma de pagamento';
            document.getElementById('btnExcluir').classList.add('d-none');
        }

        function setForm(row) {
            document.getElementById('FPG_CODIGO_PK').value = row.FPG_CODIGO_PK || '0';
            document.getElementById('FPG_DESCRICAO').value = row.FPG_DESCRICAO || '';
            document.getElementById('FPG_STATUS').value = row.FPG_STATUS || 'ATIVO';
            document.getElementById('modalTitulo').textContent = 'Editar forma de pagamento';
            document.getElementById('btnExcluir').classList.remove('d-none');
        }

        function getForm() {
            return {
                FPG_CODIGO_PK: document.getElementById('FPG_CODIGO_PK').value,
                FPG_DESCRICAO: document.getElementById('FPG_DESCRICAO').value.trim(),
                FPG_STATUS: document.getElementById('FPG_STATUS').value
            };
        }

        async function listar() {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">Carregando...</td></tr>';

            try {
                const j = await api({
                    acao: 'listar',
                    buscar: document.getElementById('fBuscar').value.trim(),
                    status: document.getElementById('fStatus').value
                });

                if (!j.rows || !j.rows.length) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr>';
                    return;
                }

                tbody.innerHTML = j.rows.map(r => `
                <tr>
                    <td>${safe(r.FPG_CODIGO_PK)}</td>
                    <td>${safe(r.FPG_DESCRICAO)}</td>
                    <td>${badgeStatus(r.FPG_STATUS)}</td>
                    <td class="text-end pe-3">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="abrirEditar(${Number(r.FPG_CODIGO_PK)})">
                            <i class="fa-solid fa-pen-to-square me-1"></i>Editar
                        </button>
                    </td>
                </tr>
            `).join('');
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-4">${safe(e.message)}</td></tr>`;
            }
        }

        async function abrirEditar(id) {
            const j = await api({
                acao: 'get',
                id
            }, 'GET');
            setForm(j.row);
            modalFormaPagamento.show();
        }

        async function salvar() {
            const d = getForm();

            if (!d.FPG_DESCRICAO) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Informe a descrição da forma de pagamento.'
                });
                document.getElementById('FPG_DESCRICAO').focus();
                return;
            }

            try {
                await api({
                    acao: 'salvar',
                    ...d
                }, 'POST');
                modalFormaPagamento.hide();

                Swal.fire({
                    icon: 'success',
                    title: 'Ok',
                    text: 'Forma de pagamento salva com sucesso!',
                    timer: 1000,
                    showConfirmButton: false
                });

                await listar();
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: e.message
                });
            }
        }

        async function excluirAtual() {
            const id = Number(document.getElementById('FPG_CODIGO_PK').value || 0);
            if (id <= 0) return;

            const conf = await Swal.fire({
                icon: 'warning',
                title: 'Excluir registro?',
                text: 'Essa ação não poderá ser desfeita.',
                showCancelButton: true,
                confirmButtonText: 'Sim, excluir',
                cancelButtonText: 'Cancelar'
            });

            if (!conf.isConfirmed) return;

            try {
                await api({
                    acao: 'excluir',
                    id
                }, 'POST');
                modalFormaPagamento.hide();
                Swal.fire({
                    icon: 'success',
                    title: 'Excluído',
                    text: 'Registro excluído com sucesso.',
                    timer: 1000,
                    showConfirmButton: false
                });
                await listar();
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: e.message
                });
            }
        }

        document.getElementById('btnNovo').addEventListener('click', () => {
            limparForm();
            modalFormaPagamento.show();
        });

        document.getElementById('btnSalvar').addEventListener('click', salvar);
        document.getElementById('btnExcluir').addEventListener('click', excluirAtual);
        document.getElementById('btnFiltrar').addEventListener('click', listar);
        document.getElementById('btnLimpar').addEventListener('click', () => {
            document.getElementById('fBuscar').value = '';
            document.getElementById('fStatus').value = '';
            listar();
        });

        document.getElementById('fBuscar').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                listar();
            }
        });

        document.addEventListener('DOMContentLoaded', listar);
    </script>
  <script src="assets/session_keeper.js" defer></script>
</body>

</html>