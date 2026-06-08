<?php
// /app/transferencia_bancaria.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Transferência entre Bancos</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .badge-ativo {
            background: rgba(34, 197, 94, .12);
            color: #14532d;
            border-radius: 999px;
            padding: .3rem .65rem;
            font-size: .78rem;
            font-weight: 600;
        }
        .badge-cancelado {
            background: rgba(239, 68, 68, .12);
            color: #991b1b;
            border-radius: 999px;
            padding: .3rem .65rem;
            font-size: .78rem;
            font-weight: 600;
        }
        .table thead th {
            font-size: .78rem;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 1px solid rgba(17,24,39,.08) !important;
        }
        .help-mini { font-size: .84rem; color: #64748b; }
        .arrow-icon { color: #6366f1; }
    </style>
</head>

<body data-page="transferencia_bancaria">
<div class="d-flex" id="wrapper">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div id="page-content-wrapper" class="flex-grow-1">

        <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
            <button class="btn btn-outline-secondary me-2" id="menu-toggle">
                <i class="fa-solid fa-bars"></i>
            </button>
            <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Transferência entre Bancos</span>
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
                    <h5 class="mb-1 mt-1">Transferência entre Bancos</h5>
                    <p class="help-mini mb-0">
                        Registra a movimentação de saldo de uma conta para outra.
                        O saldo ERP de ambos os bancos é atualizado imediatamente.
                    </p>
                </div>
                <div class="mt-2 mt-sm-0">
                    <button type="button" class="btn btn-sm btn-primary" id="btnNovaTransferencia"
                            data-bs-toggle="modal" data-bs-target="#modalTransferencia">
                        <i class="fa-solid fa-right-left me-1"></i>Nova Transferência
                    </button>
                </div>
            </div>

            <!-- Filtros -->
            <div class="card mb-3">
                <div class="card-body py-3">
                    <form class="row g-2 align-items-end" id="frmFiltros">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">De</label>
                            <input type="date" class="form-control form-control-sm" id="fDe">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Até</label>
                            <input type="date" class="form-control form-control-sm" id="fAte">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Situação</label>
                            <select class="form-select form-select-sm" id="fStatus">
                                <option value="">Todas</option>
                                <option value="ATIVO" selected>Ativas</option>
                                <option value="CANCELADO">Canceladas</option>
                            </select>
                        </div>
                        <div class="col-md-3 text-end">
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                                <i class="fa-solid fa-magnifying-glass me-1"></i>Filtrar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabela -->
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted">Transferências registradas</span>
                        <span class="small text-muted" id="lblTotal">—</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Data</th>
                                    <th>Origem</th>
                                    <th></th>
                                    <th>Destino</th>
                                    <th>Valor</th>
                                    <th>Descrição</th>
                                    <th>Situação</th>
                                    <th>Usuário</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="tbTransferencias">
                                <tr><td colspan="10" class="text-muted small">Carregando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <footer class="text-muted small mt-4">© <?= date('Y') ?> DRE - Sistema Financeiro</footer>

        </div>
    </div>
</div>

<!-- Modal Nova Transferência -->
<div class="modal fade" id="modalTransferencia" tabindex="-1" aria-labelledby="modalTransferenciaLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="modalTransferenciaLabel">
                        <i class="fa-solid fa-right-left me-2 text-primary"></i>Nova Transferência entre Bancos
                    </h5>
                    <div class="help-mini">O saldo ERP dos dois bancos será ajustado na data informada.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <form id="frmTransferencia" autocomplete="off">

                    <div class="row g-3 align-items-end">

                        <div class="col-12 col-md-5">
                            <label class="form-label small">Banco de Origem *</label>
                            <select class="form-select form-select-sm" id="bancoOrigemId" required>
                                <option value="">Selecione...</option>
                            </select>
                            <div class="help-mini mt-1">Saída de caixa — saldo diminui</div>
                        </div>

                        <div class="col-12 col-md-2 text-center">
                            <span class="arrow-icon fs-4"><i class="fa-solid fa-arrow-right"></i></span>
                        </div>

                        <div class="col-12 col-md-5">
                            <label class="form-label small">Banco de Destino *</label>
                            <select class="form-select form-select-sm" id="bancoDestinoId" required>
                                <option value="">Selecione...</option>
                            </select>
                            <div class="help-mini mt-1">Entrada de caixa — saldo aumenta</div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label small">Data da Transferência *</label>
                            <input type="date" class="form-control form-control-sm" id="trbData" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label small">Valor (R$) *</label>
                            <input type="text" class="form-control form-control-sm" id="trbValor"
                                   placeholder="0,00" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label small">Descrição / Observação</label>
                            <input type="text" class="form-control form-control-sm" id="trbDescricao"
                                   placeholder="Opcional">
                        </div>

                    </div>

                    <!-- Preview de saldo -->
                    <div id="previewSaldo" class="alert alert-light border mt-3 small d-none">
                        <div class="fw-semibold mb-1">Prévia do ajuste de saldo ERP</div>
                        <div id="previewTexto"></div>
                    </div>

                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSalvarTransferencia">
                    <i class="fa-solid fa-floppy-disk me-1"></i>Registrar Transferência
                </button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/scripts.php'; ?>

<script>
const ENDPOINT = 'endpoints/transferencia_bancaria.php';

async function api(params, method = 'GET') {
    let url = ENDPOINT;
    const opt = { method };

    if (method === 'GET') {
        url += '?' + new URLSearchParams(params).toString();
    } else {
        const fd = new FormData();
        Object.entries(params).forEach(([k, v]) => fd.append(k, v ?? ''));
        opt.body = fd;
    }

    const r   = await fetch(url, opt);
    const txt = await r.text();
    let j;
    try { j = JSON.parse(txt); } catch {
        console.error('Resposta não-JSON:', txt);
        throw new Error('Endpoint não retornou JSON.');
    }
    if (!j.ok) throw new Error(j.msg || 'Erro na requisição');
    return j;
}

const modal = new bootstrap.Modal(document.getElementById('modalTransferencia'));

function brl(v) {
    return parseFloat(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function badgeStatus(s) {
    if (s === 'ATIVO') return '<span class="badge-ativo">ATIVA</span>';
    return '<span class="badge-cancelado">CANCELADA</span>';
}

// Carrega combos de bancos
async function carregarBancos() {
    const j = await api({ acao: 'combo_bancos' });
    const selOri = document.getElementById('bancoOrigemId');
    const selDes = document.getElementById('bancoDestinoId');
    [selOri, selDes].forEach(sel => {
        const prev = sel.value;
        sel.innerHTML = '<option value="">Selecione...</option>';
        j.rows.forEach(b => {
            sel.insertAdjacentHTML('beforeend', `<option value="${b.id}">${b.nome}</option>`);
        });
        if (prev) sel.value = prev;
    });
}

// Data padrão = hoje
function setDataHoje() {
    const d = new Date();
    const pad = n => String(n).padStart(2, '0');
    document.getElementById('trbData').value =
        `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
}

function limparForm() {
    document.getElementById('frmTransferencia').reset();
    document.getElementById('bancoOrigemId').value  = '';
    document.getElementById('bancoDestinoId').value = '';
    setDataHoje();
    document.getElementById('previewSaldo').classList.add('d-none');
}

// Máscara de valor monetário
function maskMoney(el) {
    el.addEventListener('input', () => {
        let v = el.value.replace(/\D/g, '');
        if (!v) { el.value = ''; return; }
        v = (parseInt(v, 10) / 100).toFixed(2);
        el.value = v.replace('.', ',');
    });
}
maskMoney(document.getElementById('trbValor'));

// Listar transferências
async function listar() {
    const j = await api({
        acao:   'listar',
        de:     document.getElementById('fDe').value,
        ate:    document.getElementById('fAte').value,
        status: document.getElementById('fStatus').value,
    });

    const tb = document.getElementById('tbTransferencias');
    tb.innerHTML = '';
    document.getElementById('lblTotal').textContent = `${j.total} registro(s)`;

    if (!j.rows || j.rows.length === 0) {
        tb.innerHTML = '<tr><td colspan="10" class="text-muted small">Nenhuma transferência encontrada.</td></tr>';
        return;
    }

    j.rows.forEach((r, i) => {
        const dataBr = r.TRB_DATA
            ? r.TRB_DATA.split('-').reverse().join('/')
            : '—';
        const btnCancelar = r.TRB_STATUS === 'ATIVO'
            ? `<button class="btn btn-sm btn-outline-danger" title="Cancelar transferência"
                       data-id="${r.TRB_CODIGO_PK}" data-act="cancelar">
                   <i class="fa-solid fa-xmark"></i>
               </button>`
            : '';

        tb.insertAdjacentHTML('beforeend', `
            <tr>
                <td>${i + 1}</td>
                <td>${dataBr}</td>
                <td class="small">${r.banco_origem}</td>
                <td class="text-center arrow-icon"><i class="fa-solid fa-arrow-right"></i></td>
                <td class="small">${r.banco_destino}</td>
                <td class="fw-semibold text-success">${brl(r.TRB_VALOR)}</td>
                <td class="small text-muted">${r.TRB_DESCRICAO || '—'}</td>
                <td>${badgeStatus(r.TRB_STATUS)}</td>
                <td class="small text-muted">${r.TRB_USUARIO || '—'}</td>
                <td class="text-end">${btnCancelar}</td>
            </tr>
        `);
    });
}

// Salvar
async function salvar() {
    const valor = document.getElementById('trbValor').value.replace(',', '.');
    const params = {
        acao:            'salvar',
        banco_origem_id:  document.getElementById('bancoOrigemId').value,
        banco_destino_id: document.getElementById('bancoDestinoId').value,
        data:             document.getElementById('trbData').value,
        valor:            valor,
        descricao:        document.getElementById('trbDescricao').value.trim(),
    };

    if (!params.banco_origem_id || !params.banco_destino_id) {
        return Swal.fire({ icon: 'warning', title: 'Atenção', text: 'Selecione os bancos de origem e destino.' });
    }
    if (params.banco_origem_id === params.banco_destino_id) {
        return Swal.fire({ icon: 'warning', title: 'Atenção', text: 'Banco de origem e destino devem ser diferentes.' });
    }
    if (!parseFloat(valor) || parseFloat(valor) <= 0) {
        return Swal.fire({ icon: 'warning', title: 'Atenção', text: 'Informe um valor válido.' });
    }
    if (!params.data) {
        return Swal.fire({ icon: 'warning', title: 'Atenção', text: 'Informe a data da transferência.' });
    }

    const confirm = await Swal.fire({
        icon: 'question',
        title: 'Confirmar transferência?',
        html: `Transferir <strong>${brl(parseFloat(valor))}</strong> do banco selecionado para o destino.<br>
               <small class="text-muted">O saldo ERP de ambos será ajustado.</small>`,
        showCancelButton:    true,
        confirmButtonText:   'Sim, registrar',
        cancelButtonText:    'Cancelar',
        confirmButtonColor:  '#4f46e5',
    });

    if (!confirm.isConfirmed) return;

    try {
        await api(params, 'POST');
        modal.hide();
        Swal.fire({ icon: 'success', title: 'Registrado', text: 'Transferência registrada com sucesso!', timer: 1200, showConfirmButton: false });
        await listar();
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Erro', text: e.message });
    }
}

// Cancelar
async function cancelar(id) {
    const c = await Swal.fire({
        icon: 'warning',
        title: 'Cancelar transferência?',
        text: 'Os ajustes de saldo ERP e os movimentos bancários serão desfeitos.',
        showCancelButton:   true,
        confirmButtonText:  'Sim, cancelar',
        cancelButtonText:   'Voltar',
        confirmButtonColor: '#dc2626',
    });
    if (!c.isConfirmed) return;

    try {
        await api({ acao: 'cancelar', id }, 'POST');
        Swal.fire({ icon: 'success', title: 'Cancelada', text: 'Transferência cancelada.', timer: 1000, showConfirmButton: false });
        await listar();
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Erro', text: e.message });
    }
}

// Binds
document.getElementById('btnNovaTransferencia').addEventListener('click', () => {
    limparForm();
    carregarBancos().catch(e => Swal.fire({ icon: 'error', title: 'Erro', text: e.message }));
});

document.getElementById('btnSalvarTransferencia').addEventListener('click', () =>
    salvar().catch(e => Swal.fire({ icon: 'error', title: 'Erro', text: e.message }))
);

document.getElementById('frmFiltros').addEventListener('submit', e => {
    e.preventDefault();
    listar().catch(err => Swal.fire({ icon: 'error', title: 'Erro', text: err.message }));
});

document.getElementById('tbTransferencias').addEventListener('click', e => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    if (btn.dataset.act === 'cancelar')
        cancelar(btn.dataset.id).catch(err => Swal.fire({ icon: 'error', title: 'Erro', text: err.message }));
});

// Init
listar().catch(err => Swal.fire({ icon: 'error', title: 'Erro', text: err.message }));
</script>

<script src="assets/session_keeper.js" defer></script>
</body>
</html>
