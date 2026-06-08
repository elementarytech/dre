<?php
// /app/fluxo_caixa.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Fluxo de Caixa</title>
    <?php include __DIR__ . '/includes/head.php'; ?>

    <style>
        :root {
            --card-radius: 14px;
        }

        .cardish {
            background: #fff;
            border: 1px solid #eef0f3;
            border-radius: var(--card-radius);
            box-shadow: 0 2px 14px rgba(0, 0, 0, .04);
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-variant-numeric: tabular-nums;
        }

        .kpi-value {
            font-size: 28px;
            font-weight: 900;
            margin: 0;
            line-height: 1.1;
        }

        .kpi-sub {
            margin: 0;
            color: #6b7280;
            font-size: 13px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #e5e7eb;
            padding: 8px 12px;
            border-radius: 999px;
            background: #fff;
        }

        .pill select.form-select {
            padding-right: 2rem !important;
            background-position: right .6rem center;
            background-size: 16px 12px;
        }

        .btn-action {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }

        .table td,
        .table th {
            vertical-align: middle;
        }

        .table thead th {
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

        .badge-soft-warning {
            background: rgba(234, 179, 8, .12);
            color: #854d0e;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem;
        }

        .badge-soft-primary {
            background: rgba(59, 130, 246, .12);
            color: #1d4ed8;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem;
        }

        .badge-soft-secondary {
            background: rgba(107, 114, 128, .12);
            color: #374151;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem;
        }

        .help {
            font-size: .86rem;
            color: #64748b;
        }

        .help-mini {
            font-size: .84rem;
            color: #64748b;
        }

        .truncate {
            max-width: 320px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
            vertical-align: bottom;
        }

        .modal-xl2 {
            max-width: 92vw;
        }

        @media (min-width: 1200px) {
            .modal-xl2 {
                max-width: 1100px;
            }
        }

        @media (max-width: 991.98px) {
            .kpi-value {
                font-size: 24px;
            }
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

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Fluxo de Caixa</span>

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
                        <h5 class="mb-1 mt-1">Fluxo de caixa consolidado</h5>
                        <p class="help mb-0">
                            Visualize entradas, saídas, saldos por banco, movimentações e liberação de pagamentos em um único painel.
                        </p>
                    </div>
                </div>

                <!-- RESUMO -->
                <div class="cardish p-3 mb-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="fw-bold">Resumo do período</div>
                            <div class="text-muted small">Baseado em pagamentos, recebimentos e lançamentos do fluxo.</div>
                        </div>

                        <div class="d-flex gap-2 align-items-center">
                            <div class="pill">
                                <i class="fa-regular fa-calendar"></i>
                                <select id="selPeriodo" class="form-select form-select-sm border-0 p-0" style="width:auto">
                                    <option value="MES">Este mês</option>
                                    <option value="30D">Últimos 30 dias</option>
                                    <option value="90D">Últimos 90 dias</option>
                                </select>
                            </div>

                            <button class="btn btn-outline-secondary btn-sm" type="button" id="btnRecarregar">
                                <i class="fa-solid fa-rotate me-1"></i>Recarregar
                            </button>
                        </div>
                    </div>

                    <div class="row g-2 mt-1">
                        <div class="col-12 col-lg-4">
                            <div class="cardish p-3 h-100">
                                <div class="d-flex justify-content-between">
                                    <div class="fw-semibold">Entradas (período)</div>
                                    <span class="badge-soft-success">RECEBER</span>
                                </div>
                                <p class="kpi-value text-success mono" id="kpiEntradas">R$ 0,00</p>
                                <p class="kpi-sub">Somatório de entradas</p>
                            </div>
                        </div>

                        <div class="col-12 col-lg-4">
                            <div class="cardish p-3 h-100">
                                <div class="d-flex justify-content-between">
                                    <div class="fw-semibold">Saídas (período)</div>
                                    <span class="badge-soft-danger">PAGAR</span>
                                </div>
                                <p class="kpi-value text-danger mono" id="kpiSaidas">R$ 0,00</p>
                                <p class="kpi-sub">Somatório de saídas</p>
                            </div>
                        </div>

                        <div class="col-12 col-lg-4">
                            <div class="cardish p-3 h-100">
                                <div class="d-flex justify-content-between">
                                    <div class="fw-semibold">Saldo (período)</div>
                                    <span class="badge-soft-primary">RESULTADO</span>
                                </div>
                                <p class="kpi-value text-primary mono" id="kpiSaldo">R$ 0,00</p>
                                <p class="kpi-sub">Entradas - Saídas</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SALDOS POR BANCO (resumido) -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div>
                                <div class="fw-bold">Saldos por banco</div>
                                <div class="text-muted small">
                                    Visão resumida. Para detalhamento e divergências, veja a
                                    <a href="conciliacao_bancaria.php" class="text-decoration-none">Conciliação Bancária</a>.
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-2">
                                <span class="badge-soft-secondary small" id="bancosLastUpdate">Atualizado: —</span>
                                <span class="fw-bold" id="bancosTotalConsolidado" style="font-size:15px">R$ 0,00</span>
                                <button class="btn btn-primary btn-sm" type="button" id="btnAtualizarSaldo" title="Atualizar saldo">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2" id="bancosResumo">
                            <div class="text-muted small">Carregando...</div>
                        </div>
                    </div>
                </div>

                <!-- MOVIMENTAÇÕES -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <div class="fw-bold">Movimentações</div>
                            </div>

                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <div class="pill">
                                    <i class="fa-solid fa-filter"></i>
                                    <select id="selTipoMov" class="form-select form-select-sm border-0 p-0" style="width:auto">
                                        <option value="">Todos tipos</option>
                                        <option value="ENTRADA">Entrada</option>
                                        <option value="SAIDA">Saída</option>
                                    </select>
                                </div>

                                <div class="pill">
                                    <i class="fa-solid fa-building"></i>
                                    <select id="selEmpresa" class="form-select form-select-sm border-0 p-0" style="width:auto">
                                        <option value="0">Todas empresas</option>
                                    </select>
                                </div>

                                <div class="pill">
                                    <i class="fa-solid fa-building-columns"></i>
                                    <select id="selBanco" class="form-select form-select-sm border-0 p-0" style="width:auto">
                                        <option value="0">Todos bancos</option>
                                    </select>
                                </div>

                                <input id="dtMovIni" type="date" class="form-control form-control-sm" style="width:165px">
                                <input id="dtMovFim" type="date" class="form-control form-control-sm" style="width:165px">
                            </div>
                        </div>

                        <div class="row g-2 align-items-center mt-2">
                            <div class="col-12 col-lg-6">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                                    <input id="txtMovBusca" type="text" class="form-control" placeholder="Buscar por descrição/documento/origem...">
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive mt-3">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Tipo</th>
                                        <th>Descrição</th>
                                        <th>Documento</th>
                                        <th class="text-end">Valor</th>
                                        <th>Origem</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyMov">
                                    <tr>
                                        <td colspan="6" class="text-muted small">Carregando...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Liberação movida para tela própria -->
                <div class="card">
                    <div class="card-body text-center py-3">
                        <a href="liberacao_pagamento.php" class="btn btn-outline-primary btn-sm">
                            <i class="fa-solid fa-shield-check me-1"></i>Acessar Liberação de Pagamento
                        </a>
                    </div>
                </div>

                <!-- Hidden elements para manter JS compatível -->
                <div style="display:none">
                    <span id="badgePendentes"></span>
                    <span id="totPendente"></span>
                    <span id="totAutorizado"></span>
                    <input id="txtLibBusca"><select id="selLibVisao"></select><select id="selLibStatus"></select>
                    <table><tbody id="tbodyLiberacao"></tbody></table>
                </div>

                <footer class="text-muted small mt-4">
                    © <?= date('Y') ?> DRE - Sistema Financeiro
                </footer>

            </div>
        </div>
    </div>

    <!-- MODAL ATUALIZAR SALDO -->
    <div class="modal fade" id="modalSaldo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-building-columns me-2"></i>Atualizar saldo bancário</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Banco *</label>
                        <select id="slBanco" class="form-select">
                            <option value="">Selecione o banco...</option>
                        </select>
                    </div>

                    <!-- Painel de informações do banco selecionado -->
                    <div class="row g-2 mb-3" id="slInfoBox" style="display:none">
                        <div class="col-6">
                            <div class="border rounded p-2 bg-light h-100">
                                <div class="text-muted" style="font-size:11px"><i class="fa-solid fa-credit-card me-1"></i>Agência / Conta</div>
                                <div class="fw-semibold mono small" id="slAgConta">—</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2 bg-light h-100">
                                <div class="text-muted" style="font-size:11px"><i class="fa-solid fa-scale-balanced me-1"></i>Última conciliação</div>
                                <div class="fw-semibold mono small" id="slUltConc">—</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2 bg-light h-100">
                                <div class="text-muted" style="font-size:11px"><i class="fa-solid fa-clock me-1"></i>Saldo atualizado em</div>
                                <div class="fw-semibold mono small" id="slAtualizadoEm">—</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2 bg-light h-100">
                                <div class="text-muted" style="font-size:11px"><i class="fa-solid fa-coins me-1"></i>Saldo atual registrado</div>
                                <div class="fw-semibold mono small" id="slSaldoAnterior">—</div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Saldo atual da conta *</label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="text" class="form-control" id="slSaldo" placeholder="0,00">
                            </div>
                            <small class="text-muted">Informe o saldo que consta no extrato bancário hoje.</small>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Entradas do dia</label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="text" class="form-control" id="slEntradas" placeholder="0,00" value="0,00">
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Saídas do dia</label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="text" class="form-control" id="slSaidas" placeholder="0,00" value="0,00">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-info btn-sm" id="btnGerarExtrato" disabled>
                        <i class="fa-solid fa-file-lines me-1"></i>Gerar relatório (extrato)
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-primary btn-sm" id="btnSalvarSaldo">
                            <i class="fa-solid fa-check me-1"></i>Salvar saldo
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL -->
    <div class="modal fade" id="modalLiberacao" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl2 modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0">Nota / Conta para liberação</h5>
                        <div class="help-mini" id="mlSub">—</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="row g-2">
                                <div class="col-6 col-md-3">
                                    <div class="text-muted small">Id.</div>
                                    <div class="fw-semibold mono" id="mlId">—</div>
                                </div>

                                <div class="col-6 col-md-3">
                                    <div class="text-muted small">Status</div>
                                    <div id="mlStatus">—</div>
                                </div>

                                <div class="col-6 col-md-3">
                                    <div class="text-muted small">Vencimento</div>
                                    <div class="fw-semibold mono" id="mlVenc">—</div>
                                </div>

                                <div class="col-6 col-md-3">
                                    <div class="text-muted small">Valor</div>
                                    <div class="fw-semibold mono" id="mlValor">—</div>
                                </div>

                                <div class="col-12 mt-1">
                                    <div class="text-muted small">Fornecedor</div>
                                    <div class="fw-semibold" id="mlFornecedor">—</div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <div class="text-muted small">CPF/CNPJ</div>
                                    <div class="mono" id="mlCpfCnpj">—</div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <div class="text-muted small">Liberação</div>
                                    <div id="mlLiberacaoBadge" class="mb-1">—</div>
                                    <div class="text-muted small">Autorizado em</div>
                                    <div class="mono" id="mlAutorizadoEm">—</div>
                                </div>

                                <div class="col-12 col-md-6 mt-1">
                                    <div class="text-muted small">Documento</div>
                                    <div class="mono" id="mlDocumento">—</div>
                                </div>

                                <div class="col-12 col-md-6 mt-1">
                                    <div class="text-muted small">Nota Fiscal</div>
                                    <div class="mono" id="mlNf">—</div>
                                </div>

                                <div class="col-12 mt-1">
                                    <div class="text-muted small">Complemento</div>
                                    <div id="mlComplemento">—</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="alert alert-light border mb-0">
                                <strong>Regra:</strong> este modal só marca a liberação para pagamento. Não realiza o pagamento.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-danger btn-sm" id="btnModalPendente">
                            <i class="fa-solid fa-xmark me-1"></i>Voltar p/ pendente
                        </button>
                        <button type="button" class="btn btn-success btn-sm" id="btnModalAutorizar">
                            <i class="fa-solid fa-check me-1"></i>Liberar p/ pagamento
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        const API_URL = 'endpoints/fluxo_caixa.php';
        let modalLiberacao;
        let modalAtualId = null;

        function money(v) {
            return Number(v || 0).toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function fmtBR(iso) {
            if (!iso) return '—';
            const txt = String(iso).substring(0, 10);
            const p = txt.split('-');
            if (p.length !== 3) return iso;
            return `${p[2]}/${p[1]}/${p[0]}`;
        }

        function hojeISO() {
            const d = new Date();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${d.getFullYear()}-${m}-${day}`;
        }

        function periodoToDatas(periodo) {
            const hoje = new Date();

            if (periodo === 'MES') {
                const y = hoje.getFullYear();
                const m = String(hoje.getMonth() + 1).padStart(2, '0');
                return {
                    data_ini: `${y}-${m}-01`,
                    data_fim: hojeISO()
                };
            }

            const dias = periodo === '90D' ? 90 : 30;
            const ini = new Date();
            ini.setDate(ini.getDate() - dias);

            const mi = String(ini.getMonth() + 1).padStart(2, '0');
            const di = String(ini.getDate()).padStart(2, '0');

            return {
                data_ini: `${ini.getFullYear()}-${mi}-${di}`,
                data_fim: hojeISO()
            };
        }

        async function apiGet(params = {}) {
            const qs = new URLSearchParams(params).toString();
            const res = await fetch(API_URL + '?' + qs, {
                cache: 'no-store'
            });
            return await res.json();
        }

        async function apiPost(payload = {}) {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            return await res.json();
        }

        function badgeTipo(tipo) {
            tipo = String(tipo || '').toUpperCase();
            if (tipo === 'ENTRADA') return '<span class="badge-soft-success">ENTRADA</span>';
            if (tipo === 'SAIDA') return '<span class="badge-soft-danger">SAÍDA</span>';
            return '<span class="badge-soft-secondary">—</span>';
        }

        function badgeStatus(status) {
            status = String(status || '').trim().toUpperCase();
            if (!status) status = 'ABERTO';
            const map = {
                ABERTO: 'badge-soft-primary',
                PAGO: 'badge-soft-success',
                CANCELADO: 'badge-soft-secondary',
                ATRASADO: 'badge-soft-danger'
            };
            return `<span class="${map[status] || 'badge-soft-secondary'}">${status}</span>`;
        }

        function badgeAutorizacao(status) {
            status = String(status || 'PENDENTE').toUpperCase();
            if (status === 'AUTORIZADO') return `<span class="badge-soft-success">AUTORIZADO</span>`;
            return `<span class="badge-soft-warning">PENDENTE</span>`;
        }

        async function carregarResumo() {
            const periodo = document.getElementById('selPeriodo').value;
            const {
                data_ini,
                data_fim
            } = periodoToDatas(periodo);

            const r = await apiGet({
                acao: 'resumo_periodo',
                data_ini,
                data_fim,
                empresa_id: document.getElementById('selEmpresa')?.value || 0,
                banco_id: document.getElementById('selBanco')?.value || 0
            });

            if (!r.ok) return;

            document.getElementById('kpiEntradas').textContent = 'R$ ' + money(r.entradas);
            document.getElementById('kpiSaidas').textContent = 'R$ ' + money(r.saidas);
            document.getElementById('kpiSaldo').textContent = 'R$ ' + money(r.saldo);
        }

        let bancosCache = [];

        async function carregarBancos() {
            const r = await apiGet({
                acao: 'listar_saldos_bancos'
            });
            const wrap = document.getElementById('bancosResumo');
            const totalEl = document.getElementById('bancosTotalConsolidado');
            wrap.innerHTML = '';

            if (!r.ok || !r.rows || !r.rows.length) {
                wrap.innerHTML = `<div class="text-muted small">Nenhum banco cadastrado.</div>`;
                document.getElementById('bancosLastUpdate').textContent = 'Atualizado: —';
                totalEl.textContent = 'R$ 0,00';
                return;
            }

            bancosCache = r.rows;
            let ultima = '';
            let totalSaldo = 0;

            r.rows.forEach(b => {
                if (b.FCB_ATUALIZADO_EM && String(b.FCB_ATUALIZADO_EM) > ultima) {
                    ultima = String(b.FCB_ATUALIZADO_EM);
                }
                const saldo = Number(b.FCB_SALDO_ATUAL || 0);
                totalSaldo += saldo;
                const temSaldo = b.FCB_SALDO_ATUAL !== null;
                const corSaldo = !temSaldo ? 'text-muted' : (saldo >= 0 ? 'text-dark' : 'text-danger');
                const valorTxt = temSaldo ? ('R$ ' + money(saldo)) : 'R$ 0,00';
                const ultConc = b.ULTIMA_CONCILIACAO
                    ? fmtBR(String(b.ULTIMA_CONCILIACAO).substring(0,10))
                    : '—';

                wrap.innerHTML += `
                    <div class="border rounded px-3 py-2 d-flex align-items-center gap-3"
                         style="background:#fff;min-width:260px;cursor:pointer"
                         onclick="abrirModalSaldo(${b.BAN_ID})"
                         title="Atualizar saldo">
                        <div class="flex-grow-1">
                            <div class="fw-semibold small text-truncate" style="max-width:220px">${b.BAN_APELIDO || b.BAN_NOME || '—'}</div>
                            <div class="text-muted" style="font-size:11px">
                                <i class="fa-solid fa-scale-balanced me-1"></i>Últ. concil.: ${ultConc}
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="mono fw-bold ${corSaldo}">${valorTxt}</div>
                        </div>
                    </div>
                `;
            });

            totalEl.textContent = 'R$ ' + money(totalSaldo);
            totalEl.className = 'fw-bold mono ' + (totalSaldo >= 0 ? 'text-success' : 'text-danger');
            totalEl.style.fontSize = '15px';

            document.getElementById('bancosLastUpdate').textContent = ultima ? ('Atualizado: ' + fmtBR(ultima.substring(0,10))) : 'Atualizado: —';

            // Popular select do modal
            const sel = document.getElementById('slBanco');
            sel.innerHTML = '<option value="">Selecione o banco...</option>';
            r.rows.forEach(b => {
                sel.innerHTML += `<option value="${b.BAN_ID}">${b.BAN_APELIDO || b.BAN_NOME}</option>`;
            });
        }

        async function carregarMovimentacoes() {
            const tipo = document.getElementById('selTipoMov').value;
            const data_ini = document.getElementById('dtMovIni').value;
            const data_fim = document.getElementById('dtMovFim').value;
            const busca = document.getElementById('txtMovBusca').value;
            const empresa_id = document.getElementById('selEmpresa')?.value || 0;
            const banco_id = document.getElementById('selBanco')?.value || 0;

            const r = await apiGet({
                acao: 'listar_movimentacoes',
                tipo,
                data_ini,
                data_fim,
                busca,
                empresa_id,
                banco_id
            });

            const tbody = document.getElementById('tbodyMov');
            tbody.innerHTML = '';

            if (!r.ok || !r.rows || !r.rows.length) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-muted small">Nenhuma movimentação encontrada.</td></tr>`;
                return;
            }

            r.rows.forEach(m => {
                tbody.innerHTML += `
                    <tr>
                        <td class="mono">${fmtBR(m.FLC_DATA)}</td>
                        <td>${badgeTipo(m.FLC_TIPO)}</td>
                        <td><span class="truncate" title="${m.FLC_DESCRICAO || ''}">${m.FLC_DESCRICAO || '—'}</span></td>
                        <td class="mono">${m.FLC_DOCUMENTO || '—'}</td>
                        <td class="text-end mono ${m.FLC_TIPO === 'SAIDA' ? 'text-danger' : 'text-success'}">R$ ${money(m.FLC_VALOR)}</td>
                        <td class="mono">${m.FLC_ORIGEM || '—'}</td>
                    </tr>
                `;
            });
        }

        async function carregarLiberacao() {
            const busca = document.getElementById('txtLibBusca').value;
            const visao = document.getElementById('selLibVisao').value;
            const status = document.getElementById('selLibStatus').value;

            const r = await apiGet({
                acao: 'listar_liberacao_pagamento',
                busca,
                visao,
                status
            });

            const tbody = document.getElementById('tbodyLiberacao');
            tbody.innerHTML = '';

            if (!r.ok || !r.rows || !r.rows.length) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-muted small">Nenhuma conta encontrada para liberação.</td></tr>`;
                document.getElementById('badgePendentes').textContent = '0 pendentes';
                document.getElementById('totPendente').textContent = 'R$ 0,00';
                document.getElementById('totAutorizado').textContent = 'R$ 0,00';
                return;
            }

            document.getElementById('badgePendentes').textContent = `${r.pendentes || 0} pendentes`;
            document.getElementById('totPendente').textContent = 'R$ ' + money(r.total_pendente || 0);
            document.getElementById('totAutorizado').textContent = 'R$ ' + money(r.total_autorizado || 0);

            r.rows.forEach(it => {
                tbody.innerHTML += `
                    <tr>
                        <td class="mono">${fmtBR(it.CPG_VENCIMENTO)}</td>
                        <td class="fw-semibold">
                            <span class="truncate" title="${it.FOR_RAZAO_SOCIAL || ''}">${it.FOR_RAZAO_SOCIAL || '—'}</span>
                            <div class="text-muted small d-flex gap-2 align-items-center mt-1">
                                ${badgeStatus(it.CPG_STATUS)}
                                <span class="mono">${it.CPG_NOTA_FISCAL ? '• ' + it.CPG_NOTA_FISCAL : ''}</span>
                            </div>
                        </td>
                        <td class="mono">${it.CPG_DOCUMENTO || '—'}</td>
                        <td class="text-end mono">R$ ${money(it.CPG_VALOR_PARCELA)}</td>
                        <td>${badgeAutorizacao(it.CPG_AUTORIZACAO_STATUS)}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-secondary me-1" data-view="${it.CPG_CODIGO_PK}" title="Visualizar">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-success me-1" data-auth="${it.CPG_CODIGO_PK}" title="Autorizar">
                                <i class="fa-solid fa-check"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" data-pend="${it.CPG_CODIGO_PK}" title="Voltar pendente">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        async function abrirModalLiberacao(id) {
            const r = await apiGet({
                acao: 'buscar_liberacao_pagamento',
                id
            });

            if (!r.ok || !r.row) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: r.msg || 'Não foi possível carregar a conta.'
                });
                return;
            }

            const it = r.row;
            modalAtualId = id;

            document.getElementById('mlSub').textContent = `${it.FOR_RAZAO_SOCIAL || '—'} • Venc.: ${fmtBR(it.CPG_VENCIMENTO)} • R$ ${money(it.CPG_VALOR_PARCELA)}`;
            document.getElementById('mlId').textContent = it.CPG_CODIGO_PK || '—';
            document.getElementById('mlStatus').innerHTML = badgeStatus(it.CPG_STATUS);
            document.getElementById('mlVenc').textContent = fmtBR(it.CPG_VENCIMENTO);
            document.getElementById('mlValor').textContent = 'R$ ' + money(it.CPG_VALOR_PARCELA);
            document.getElementById('mlFornecedor').textContent = it.FOR_RAZAO_SOCIAL || '—';
            document.getElementById('mlCpfCnpj').textContent = it.FOR_CNPJ || '—';
            document.getElementById('mlLiberacaoBadge').innerHTML = badgeAutorizacao(it.CPG_AUTORIZACAO_STATUS);
            document.getElementById('mlAutorizadoEm').textContent = it.CPG_AUTORIZADO_EM || '—';
            document.getElementById('mlDocumento').textContent = it.CPG_DOCUMENTO || '—';
            document.getElementById('mlNf').textContent = it.CPG_NOTA_FISCAL || '—';
            document.getElementById('mlComplemento').textContent = it.CPG_COMPLEMENTO || '—';

            modalLiberacao.show();
        }

        async function alterarAutorizacao(id, status) {
            const r = await apiPost({
                acao: 'alterar_autorizacao',
                id,
                status
            });

            if (!r.ok) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: r.msg || 'Não foi possível alterar a autorização.'
                });
                return;
            }

            await carregarLiberacao();

            if (modalAtualId === id) {
                await abrirModalLiberacao(id);
            }
        }

        async function atualizarSaldos() {
            const r = await Swal.fire({
                icon: 'info',
                title: 'Ajuste de saldo',
                text: 'O ajuste de saldo bancário agora é feito na tela de Conciliação Bancária.',
                showCancelButton: true,
                confirmButtonText: 'Ir para Conciliação',
                cancelButtonText: 'Cancelar',
            });
            if (r.isConfirmed) {
                window.location.href = 'conciliacao_bancaria.php';
            }
        }

        async function carregarCombosFiltros() {
            // Empresas
            try {
                const re = await apiGet({ acao: 'combo_empresas' });
                if (re.ok && Array.isArray(re.rows)) {
                    const sel = document.getElementById('selEmpresa');
                    const atual = sel.value;
                    sel.innerHTML = '<option value="0">Todas empresas</option>';
                    re.rows.forEach(e => {
                        sel.innerHTML += `<option value="${e.EMP_ID}">${e.EMP_NOME || ('Empresa ' + e.EMP_ID)}</option>`;
                    });
                    sel.value = atual || '0';
                }
            } catch (e) {}

            // Bancos (reusa bancosCache preenchido por carregarBancos())
            const sb = document.getElementById('selBanco');
            const atual = sb.value;
            sb.innerHTML = '<option value="0">Todos bancos</option>';
            (bancosCache || []).forEach(b => {
                sb.innerHTML += `<option value="${b.BAN_ID}">${b.BAN_APELIDO || b.BAN_NOME || ('Banco ' + b.BAN_ID)}</option>`;
            });
            sb.value = atual || '0';
        }

        async function carregarTudo() {
            await Promise.all([
                carregarResumo(),
                carregarBancos(),
            ]);
            // Popular combo de banco depende dos dados carregados acima
            await carregarCombosFiltros();
            await carregarMovimentacoes();
        }

        document.addEventListener('DOMContentLoaded', async () => {
            modalLiberacao = new bootstrap.Modal(document.getElementById('modalLiberacao'));

            // Pré-preencher filtros de movimentação com data de hoje
            const hj = hojeISO();
            document.getElementById('dtMovIni').value = hj;
            document.getElementById('dtMovFim').value = hj;

            await carregarTudo();

            document.getElementById('selPeriodo').addEventListener('change', carregarResumo);
            document.getElementById('btnRecarregar').addEventListener('click', carregarTudo);

            // Modal saldo bancário
            const modalSaldoEl = document.getElementById('modalSaldo');
            const modalSaldo = bootstrap.Modal.getOrCreateInstance(modalSaldoEl);

            document.getElementById('btnAtualizarSaldo').addEventListener('click', () => {
                document.getElementById('slBanco').value = '';
                document.getElementById('slSaldo').value = '';
                document.getElementById('slEntradas').value = '0,00';
                document.getElementById('slSaidas').value = '0,00';
                modalSaldo.show();
            });

            // Quando seleciona um banco, preenche o saldo atual
            document.getElementById('slBanco').addEventListener('change', () => {
                const id = Number(document.getElementById('slBanco').value);
                const b = bancosCache.find(x => Number(x.BAN_ID) === id);
                const infoBox = document.getElementById('slInfoBox');
                const btnExtrato = document.getElementById('btnGerarExtrato');

                if (!b) {
                    infoBox.style.display = 'none';
                    btnExtrato.disabled = true;
                    document.getElementById('slSaldo').value = '';
                    document.getElementById('slEntradas').value = '0,00';
                    document.getElementById('slSaidas').value = '0,00';
                    return;
                }

                infoBox.style.display = '';
                btnExtrato.disabled = false;

                const ag = [b.BAN_AGENCIA, b.BAN_AGENCIA_DV].filter(Boolean).join('-');
                const ct = [b.BAN_CONTA, b.BAN_CONTA_DV].filter(Boolean).join('-');
                document.getElementById('slAgConta').textContent = (ag || '—') + ' / ' + (ct || '—');

                document.getElementById('slUltConc').innerHTML = b.ULTIMA_CONCILIACAO
                    ? fmtBR(String(b.ULTIMA_CONCILIACAO).substring(0,10))
                    : '<span class="text-muted">Nunca conciliado</span>';

                document.getElementById('slAtualizadoEm').innerHTML = b.FCB_ATUALIZADO_EM
                    ? fmtBR(String(b.FCB_ATUALIZADO_EM).substring(0,10))
                    : '<span class="text-muted">—</span>';

                document.getElementById('slSaldoAnterior').innerHTML = (b.FCB_SALDO_ATUAL !== null && b.FCB_SALDO_ATUAL !== undefined)
                    ? ('R$ ' + money(b.FCB_SALDO_ATUAL))
                    : '<span class="text-muted">—</span>';

                if (b.FCB_SALDO_ATUAL !== null) {
                    document.getElementById('slSaldo').value = money(b.FCB_SALDO_ATUAL);
                    document.getElementById('slEntradas').value = money(b.FCB_ENTRADAS_DIA || 0);
                    document.getElementById('slSaidas').value = money(b.FCB_SAIDAS_DIA || 0);
                } else {
                    document.getElementById('slSaldo').value = '';
                    document.getElementById('slEntradas').value = '0,00';
                    document.getElementById('slSaidas').value = '0,00';
                }
            });

            document.getElementById('btnGerarExtrato').addEventListener('click', () => {
                const id = document.getElementById('slBanco').value;
                if (!id) return;
                window.open('extrato_banco.php?banco_id=' + encodeURIComponent(id), '_blank');
            });

            document.getElementById('btnSalvarSaldo').addEventListener('click', async () => {
                modalSaldo.hide();
                const r = await Swal.fire({
                    icon: 'info',
                    title: 'Ajuste de saldo',
                    text: 'O ajuste de saldo bancário agora é feito apenas pela tela de Conciliação Bancária.',
                    showCancelButton: true,
                    confirmButtonText: 'Ir para Conciliação',
                    cancelButtonText: 'Cancelar',
                });
                if (r.isConfirmed) {
                    window.location.href = 'conciliacao_bancaria.php';
                }
            });

            // Função global para abrir modal com banco pré-selecionado
            // Agora redireciona direto para a Conciliação (fonte da verdade do saldo).
            window.abrirModalSaldo = function(bancoId) {
                window.location.href = 'conciliacao_bancaria.php';
            };

            ['selTipoMov', 'dtMovIni', 'dtMovFim', 'txtMovBusca', 'selEmpresa', 'selBanco'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                el.addEventListener('input', carregarMovimentacoes);
                el.addEventListener('change', carregarMovimentacoes);
            });

            // Empresa/Banco também reaplicam no resumo de KPIs
            document.getElementById('selEmpresa').addEventListener('change', carregarResumo);
            document.getElementById('selBanco').addEventListener('change', carregarResumo);

            // Liberação de pagamento movida para tela própria
        });
    </script>

  <script src="assets/session_keeper.js" defer></script>
</body>

</html>