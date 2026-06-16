<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/conexao.php';

$usuarioTopo = $_SESSION['usuarioSession'] ?? 'Admin';
$hojeTopo = date('d/m/Y');
?>
<!doctype html>
<html lang="pt-br">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>DRE | Bancos • Conciliação</title>

    <?php include __DIR__ . '/includes/head.php'; ?>

    <style>
        :root {
            --page-bg: #f3f4f6;
            --cr: 14px;
            --tz: 1095;
        }

        body {
            background: var(--page-bg);
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .topbar {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 12px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-title {
            font-size: 22px;
            font-weight: 800;
            margin: 0;
        }

        .cardish {
            background: #fff;
            border: 1px solid #eef0f3;
            border-radius: var(--cr);
            box-shadow: 0 2px 14px rgba(0, 0, 0, .04);
        }

        .kpi-card {
            transition: box-shadow .18s;
        }

        .kpi-card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, .09);
        }

        .kpi-value {
            font-size: 26px;
            font-weight: 900;
            margin: 0;
            line-height: 1.1;
        }

        .kpi-sub {
            margin: 2px 0 0;
            color: #6b7280;
            font-size: 12px;
        }

        .mono {
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .sh {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }

        .ss {
            font-size: 12px;
            color: #6b7280;
            margin: 2px 0 0;
        }

        .truncate {
            max-width: 360px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
            vertical-align: bottom;
        }

        .badge-soft-success {
            background: rgba(34, 197, 94, .12);
            color: #16a34a;
        }

        .badge-soft-danger {
            background: rgba(239, 68, 68, .12);
            color: #dc2626;
        }

        .badge-soft-warning {
            background: rgba(234, 179, 8, .12);
            color: #ca8a04;
        }

        .badge-soft-primary {
            background: rgba(59, 130, 246, .12);
            color: #2563eb;
        }

        .badge-soft-secondary {
            background: rgba(107, 114, 128, .12);
            color: #374151;
        }

        .table td,
        .table th {
            vertical-align: middle;
            font-size: 13.5px;
        }

        .table thead th {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: #6b7280;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #e5e7eb;
            padding: 5px 11px;
            border-radius: 999px;
            background: #fff;
            font-size: 13px;
        }

        .segmented {
            display: inline-flex;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            background: #f9fafb;
        }

        .segmented button {
            border: 0;
            padding: .42rem .8rem;
            background: transparent;
            font-weight: 600;
            color: #6b7280;
            font-size: 13.5px;
            transition: all .15s;
        }

        .segmented button.active,
        .segmented button[aria-selected="true"] {
            background: #111827;
            color: #fff;
        }

        .notice {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 10px 14px;
            color: #475569;
            font-size: 13px;
        }

        .btn-icon {
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 7px;
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #374151;
            transition: all .15s;
            font-size: 13px;
            cursor: pointer;
        }

        .btn-icon:hover {
            background: #f3f4f6;
        }

        .btn-icon.is-success:hover {
            background: rgba(34, 197, 94, .08);
            border-color: #86efac;
            color: #16a34a;
        }

        .btn-icon.is-primary:hover {
            background: rgba(59, 130, 246, .08);
            border-color: #93c5fd;
            color: #2563eb;
        }

        .sdot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
        }

        .sdot-ok {
            background: #22c55e;
        }

        .sdot-warn {
            background: #f59e0b;
        }

        tr.row-div td:first-child {
            border-left: 3px solid #f59e0b;
        }

        tr.row-ok td:first-child {
            border-left: 3px solid #22c55e;
        }

        #toast-container {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: var(--tz);
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }

        .toast-msg {
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            min-width: 300px;
            max-width: 420px;
            background: #1e293b;
            color: #f8fafc;
            border-radius: 12px;
            padding: 13px 15px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .25);
        }

        .toast-msg.is-success .ti {
            color: #4ade80;
        }

        .toast-msg.is-danger .ti {
            color: #f87171;
        }

        .toast-msg.is-warning .ti {
            color: #fbbf24;
        }

        .toast-msg.is-info .ti {
            color: #60a5fa;
        }

        .ti {
            font-size: 1.1rem;
            flex-shrink: 0;
            padding-top: 1px;
        }

        .toast-body {
            flex: 1;
            font-size: .875rem;
            line-height: 1.45;
        }

        .toast-close {
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0;
        }

        .toast-close:hover {
            color: #fff;
        }

        .aj-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 55%, #3b82f6 100%);
            padding: 22px 24px 18px;
            border-radius: 16px 16px 0 0;
        }

        .aj-kpi-chip {
            background: rgba(255, 255, 255, .14);
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 10px;
            padding: 9px 14px;
            min-width: 140px;
        }

        .aj-kpi-chip .lbl {
            font-size: 10.5px;
            color: rgba(255, 255, 255, .7);
            margin: 0;
        }

        .aj-kpi-chip .val {
            font-size: 17px;
            font-weight: 800;
            color: #fff;
            margin: 0;
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .aj-kpi-chip.novo {
            border-color: rgba(134, 239, 172, .5);
            background: rgba(134, 239, 172, .12);
        }

        .aj-kpi-chip.novo .val {
            color: #bbf7d0;
        }

        .op-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 12px 14px;
        }

        .calc-row {
            font-size: 13.5px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .saldo-preview {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 14px;
        }

        .sp-lbl {
            color: #6b7280;
            font-size: 11px;
            margin: 0;
        }

        .sp-val {
            font-size: 15px;
            font-weight: 800;
            margin: 0;
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .mini-bar {
            height: 6px;
            border-radius: 99px;
            background: #e5e7eb;
            overflow: hidden;
        }

        .mini-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width .35s ease;
        }

        .audit-item {
            border-left: 2px solid #e5e7eb;
            padding-left: 12px;
            position: relative;
            margin-bottom: 10px;
        }

        .audit-item::before {
            content: '';
            position: absolute;
            left: -5px;
            top: 4px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #9ca3af;
            border: 2px solid #fff;
        }

        .audit-item.ok::before {
            background: #22c55e;
        }

        .audit-item.warn::before {
            background: #f59e0b;
        }

        .dt-filter input[type=date] {
            border: 0;
            background: transparent;
            font-size: 13px;
            padding: 0;
            color: #374151;
            width: 110px;
            outline: none;
        }

        .dt-filter input[type=date]:focus {
            color: #2563eb;
        }

        @media(max-width:991.98px) {
            .topbar-title {
                font-size: 18px;
            }

            .kpi-value {
                font-size: 22px;
            }

            .aj-header {
                padding: 16px 16px 14px;
            }

            .aj-kpi-chip {
                min-width: 110px;
            }
        }

        /* Modais de revisão de débitos/créditos: usam mais largura da tela
           para acomodar selects de empresa/cliente/plano sem apertar. */
        .modal-conciliacao-wide .modal-dialog {
            max-width: 95vw;
            width: 1500px;
        }
        @media (max-width: 1500px) {
            .modal-conciliacao-wide .modal-dialog {
                width: 95vw;
                max-width: 95vw;
            }
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body data-page="bancos-conciliacao">
    <div class="d-flex" id="wrapper">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1 d-flex flex-column" style="min-height:100vh">

            <header class="topbar d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-sm btn-outline-secondary" id="menu-toggle" aria-label="Menu">
                        <i class="bi bi-list fs-5"></i>
                    </button>
                    <h1 class="topbar-title">Bancos / Conciliação</h1>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="text-secondary small d-none d-md-inline">Hoje: <strong class="mono"><?= $hojeTopo ?></strong></span>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($usuarioTopo) ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li><a class="dropdown-item" href="perfil.php"><i class="bi bi-person me-2"></i>Meu Perfil</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sair</a></li>
                        </ul>
                    </div>
                </div>
            </header>

            <main class="p-3 p-lg-4 flex-grow-1">

                <nav aria-label="Breadcrumb" class="mb-3">
                    <ol class="breadcrumb mb-0 small">
                        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-secondary">Financeiro</a></li>
                        <li class="breadcrumb-item active fw-semibold">Bancos / Conciliação</li>
                    </ol>
                </nav>

                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3">
                        <div class="cardish p-3 kpi-card h-100">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="ss">Saldo bancário total</span>
                                <span class="badge badge-soft-primary rounded-pill"><i class="bi bi-bank"></i></span>
                            </div>
                            <p class="kpi-value text-primary mono" id="kpiSaldoBanc">R$ 0,00</p>
                            <p class="kpi-sub">Soma de todas as contas</p>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="cardish p-3 kpi-card h-100">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="ss">Saldo ERP total</span>
                                <span class="badge badge-soft-secondary rounded-pill"><i class="bi bi-server"></i></span>
                            </div>
                            <p class="kpi-value mono" id="kpiSaldoErp">R$ 0,00</p>
                            <p class="kpi-sub">Lançamentos internos</p>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="cardish p-3 kpi-card h-100">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="ss">Entradas (período)</span>
                                <span class="badge badge-soft-success rounded-pill"><i class="bi bi-arrow-down-circle"></i></span>
                            </div>
                            <p class="kpi-value text-success mono" id="kpiEntradas">R$ 0,00</p>
                            <p class="kpi-sub">Créditos no extrato OFX</p>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="cardish p-3 kpi-card h-100">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="ss">Saídas (período)</span>
                                <span class="badge badge-soft-danger rounded-pill"><i class="bi bi-arrow-up-circle"></i></span>
                            </div>
                            <p class="kpi-value text-danger mono" id="kpiSaidas">R$ 0,00</p>
                            <p class="kpi-sub">Débitos no extrato OFX</p>
                        </div>
                    </div>
                </div>

                <div class="cardish px-3 py-2 mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <div class="pill">
                            <i class="bi bi-calendar3 text-secondary"></i>
                            <select id="selPeriodo" class="form-select form-select-sm border-0 p-0 bg-transparent" style="width:auto">
                                <option value="MES">Este mês</option>
                                <option value="30D">Últimos 30 dias</option>
                                <option value="90D">Últimos 90 dias</option>
                            </select>
                        </div>
                        <span id="kpiDivBadge" class="badge rounded-pill"></span>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary" id="btnAjusteSaldo" type="button">
                            <i class="bi bi-sliders me-1"></i>Ajustar saldo
                        </button>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear me-1"></i>Operações
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><button class="dropdown-item" id="btnExportCSV"><i class="bi bi-filetype-csv me-2 text-secondary"></i>Exportar extrato (CSV)</button></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="cardish p-3 mb-3">
                    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
                        <div>
                            <p class="sh mb-0">Saldos das contas bancárias</p>
                            <p class="ss">Vinculadas ao cadastro bancário — ordenadas por saldo (maior → menor).</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <div class="input-group" style="max-width:230px">
                                <span class="input-group-text bg-white border-end-0 pe-1"><i class="bi bi-search text-secondary" style="font-size:13px"></i></span>
                                <input id="txtSaldoBusca" class="form-control form-control-sm border-start-0" placeholder="Banco, agência…" autocomplete="off">
                            </div>
                            <div class="pill dt-filter gap-1">
                                <i class="bi bi-calendar-range text-secondary" style="font-size:12px"></i>
                                <input id="saldoDtIni" type="date" title="Atualizado a partir de">
                                <span class="text-muted" style="font-size:11px">–</span>
                                <input id="saldoDtFim" type="date" title="Atualizado até">
                                <button id="btnLimparDtSaldo" type="button" title="Limpar datas" style="border:none;background:none;padding:0;color:#9ca3af;cursor:pointer;font-size:12px;line-height:1">✕</button>
                            </div>
                            <div class="pill">
                                <i class="bi bi-funnel text-secondary" style="font-size:12px"></i>
                                <select id="selConcStatus" class="form-select form-select-sm border-0 bg-transparent" style="min-width:140px;padding-right:1.6rem">
                                    <option value="">Todos status</option>
                                    <option value="OK">✓ Conciliado</option>
                                    <option value="DIVERGENTE">⚠ Divergente</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:38px">#</th>
                                    <th>Banco / Conta</th>
                                    <th style="width:162px">Agência / Conta</th>
                                    <th style="width:148px" class="text-end">Saldo bancário</th>
                                    <th style="width:148px" class="text-end">Saldo ERP</th>
                                    <th style="width:130px" class="text-end">Diferença</th>
                                    <th style="width:128px">Conciliação</th>
                                    <th style="width:105px">Atualizado</th>
                                    <th style="width:60px" class="text-center">Ver</th>
                                </tr>
                            </thead>
                            <tbody id="tbodySaldos"></tbody>
                        </table>
                    </div>

                    <div class="notice mt-3">
                        <i class="bi bi-info-circle me-1"></i>
                        O <strong>Saldo ERP</strong> vem de <a href="contas_receber.php" class="text-decoration-none">Contas a Receber</a>
                        e <a href="contas_pagar.php" class="text-decoration-none">Contas a Pagar</a> liquidadas.
                        Use <em>Ajustar saldo</em> apenas para correções pontuais auditadas.
                    </div>
                </div>

                <div class="cardish p-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                        <div>
                            <p class="sh mb-0">Conciliação e CNAB</p>
                            <p class="ss">Espelho do extrato OFX + remessa/retorno CNAB 240/400.</p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <a href="diagnostico_contas_pagar.php" class="btn btn-sm btn-outline-warning" title="Painel de Integridade — pagamentos/recebimentos sem banco, vínculos inconsistentes etc.">
                                <i class="bi bi-shield-check me-1"></i>Painel de Integridade
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnRevisarVinculosOfx" title="Listar importações OFX do banco e abrir os modais de revisão sob demanda">
                                <i class="bi bi-list-check me-1"></i>Revisar vínculos OFX
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info" id="btnConferirVinculos" title="Listar todos os vínculos OFX ativos do banco (novos e legados) com opção de cancelar">
                                <i class="bi bi-link-45deg me-1"></i>Conferir vínculos
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="btnHistoricoOfx" title="Histórico de importações OFX — permite excluir importação errada com reversão completa (requer senha ADMIN)">
                                <i class="bi bi-clock-history me-1"></i>Histórico OFX
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info" id="btnTransferenciasInternas" title="Pares de transferência entre contas próprias + cadastro de CNPJs/CPFs do grupo">
                                <i class="bi bi-arrow-left-right me-1"></i>Transferências internas
                            </button>
                            <div class="segmented" role="tablist">
                                <button type="button" id="tabOfxBtn" role="tab" aria-selected="true" aria-controls="tabOfx" class="active"><i class="bi bi-file-earmark-text me-1"></i>Conciliação (OFX)</button>
                                <button type="button" id="tabCnabBtn" role="tab" aria-selected="false" aria-controls="tabCnab"><i class="bi bi-file-binary me-1"></i>CNAB</button>
                            </div>
                        </div>
                    </div>

                    <section id="tabOfx" role="tabpanel" aria-labelledby="tabOfxBtn">
                        <div class="row g-2 align-items-end mb-3 p-2 rounded" style="background:#f9fafb;border:1px solid #e5e7eb">
                            <div class="col-12 col-lg-3">
                                <label class="form-label small fw-semibold mb-1" for="selBancoOfx">Banco</label>
                                <select id="selBancoOfx" class="form-select form-select-sm"></select>
                            </div>
                            <div class="col-12 col-lg-3">
                                <label class="form-label small fw-semibold mb-1" for="selContaOfx">Conta</label>
                                <select id="selContaOfx" class="form-select form-select-sm"></select>
                            </div>
                            <div class="col-6 col-lg-2">
                                <label class="form-label small fw-semibold mb-1" for="dtIniOfx">De</label>
                                <input id="dtIniOfx" type="date" class="form-control form-control-sm">
                            </div>
                            <div class="col-6 col-lg-2">
                                <label class="form-label small fw-semibold mb-1" for="dtFimOfx">Até</label>
                                <input id="dtFimOfx" type="date" class="form-control form-control-sm">
                            </div>
                            <div class="col-12 col-lg-2">
                                <label class="form-label small fw-semibold mb-1" for="fileOfx">Arquivo .ofx</label>
                                <input id="fileOfx" type="file" class="form-control form-control-sm" accept=".ofx">
                            </div>
                            <div class="col-12 d-flex gap-2 justify-content-between align-items-center flex-wrap mt-1">
                                <span class="badge badge-soft-secondary" id="ofxStatus"><i class="bi bi-clock me-1"></i>Aguardando arquivo</span>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-secondary" id="btnRevisarConciliacao" type="button" title="Reabrir os modais de conciliação da última importação">
                                        <i class="bi bi-arrow-repeat me-1"></i>Revisar conciliação
                                    </button>
                                    <button class="btn btn-sm btn-primary" id="btnProcessarOfx"><i class="bi bi-upload me-1"></i>Processar OFX</button>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <p class="sh mb-0">Extrato — espelho do banco</p>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <div class="input-group" style="max-width:270px">
                                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary" style="font-size:13px"></i></span>
                                    <input id="txtExtratoBusca" class="form-control border-start-0 form-control-sm" placeholder="Descrição ou valor…" autocomplete="off">
                                </div>
                                <div class="pill">
                                    <i class="bi bi-bank text-secondary" style="font-size:12px"></i>
                                    <select id="selBancoExtrato" class="form-select form-select-sm border-0 bg-transparent" style="min-width:160px;padding-right:1.6rem"></select>
                                </div>
                                <div class="pill">
                                    <i class="bi bi-funnel text-secondary" style="font-size:12px"></i>
                                    <select id="selStatusExtrato" class="form-select form-select-sm border-0 bg-transparent" style="min-width:130px;padding-right:1.6rem">
                                        <option value="PENDENTES_PARCIAIS" selected>Pendentes + Parciais</option>
                                        <option value="">Todos</option>
                                        <option value="IMPORTADO">Importado</option>
                                        <option value="PARCIAL">Parcial</option>
                                        <option value="CONCILIADO">Conciliado</option>
                                        <option value="PENDENTE">Pendente</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:105px">Data</th>
                                        <th style="width:128px">Banco</th>
                                        <th>Descrição</th>
                                        <th style="width:126px" class="text-end">Valor</th>
                                        <th style="width:134px" class="text-end">Saldo após</th>
                                        <th style="width:110px">Status</th>
                                        <th style="width:56px" class="text-center">Ação</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyExtrato"></tbody>
                            </table>
                        </div>
                    </section>

                    <section id="tabCnab" role="tabpanel" aria-labelledby="tabCnabBtn" class="d-none">
                        <div class="notice">
                            Integração CNAB pode entrar depois. A estrutura visual já está pronta nesta tela.
                        </div>
                    </section>
                </div>
            </main>

            <footer class="text-muted px-4 pb-3 mt-auto" style="font-size:12px">
                © <?= date('Y') ?> DRE — Sistema de Gestão Financeira
            </footer>
        </div>
    </div>

    <div id="toast-container"></div>

    <div class="modal fade" id="modalAjusteSaldo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-width:760px">
            <div class="modal-content" style="border:0;border-radius:16px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25)">
                <div class="aj-header">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div>
                            <h5 class="modal-title fw-bold text-white mb-1" style="font-size:18px">
                                <i class="bi bi-sliders me-2"></i>Ajuste de saldo bancário
                            </h5>
                            <p class="mb-0" style="font-size:12px;color:rgba(255,255,255,.7)">
                                Ajuste contábil pontual · motivo obrigatório · trilha de auditoria
                            </p>
                        </div>
                        <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar" style="opacity:.8;margin-top:2px"></button>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <div class="aj-kpi-chip">
                            <p class="lbl">Saldo bancário atual</p>
                            <p class="val" id="ajHdrBanc">R$ —</p>
                        </div>
                        <div class="aj-kpi-chip">
                            <p class="lbl">Saldo ERP atual</p>
                            <p class="val" id="ajHdrErp">R$ —</p>
                        </div>
                        <div class="aj-kpi-chip">
                            <p class="lbl">Diferença atual</p>
                            <p class="val" id="ajHdrDiff" style="color:#fbbf24">R$ —</p>
                        </div>
                        <div class="aj-kpi-chip novo">
                            <p class="lbl">Novo saldo estimado</p>
                            <p class="val" id="ajHdrNovo">—</p>
                        </div>
                    </div>
                </div>

                <div class="modal-body p-4" style="background:#fff">
                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <div class="row g-2 mb-3">
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">Conta bancária</label>
                                    <select id="ajConta" class="form-select form-select-sm"></select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold mb-1">Data do ajuste</label>
                                    <input id="ajData" type="date" class="form-control form-control-sm">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold mb-1">Campo a ajustar</label>
                                    <select id="ajTipo" class="form-select form-select-sm">
                                        <option value="SALDO_BANCARIO">Saldo bancário</option>
                                        <option value="SALDO_ERP">Saldo ERP</option>
                                    </select>
                                </div>
                            </div>

                            <div class="op-box mb-3">
                                <p class="small fw-bold text-primary mb-2" style="font-size:12px">
                                    <i class="bi bi-calculator me-1"></i>OPERAÇÃO E VALOR
                                </p>
                                <div class="row g-2">
                                    <div class="col-7">
                                        <label class="form-label small fw-semibold mb-1">Tipo</label>
                                        <select id="ajOp" class="form-select form-select-sm fw-semibold">
                                            <option value="SOMA">➕ Adicionar ao saldo</option>
                                            <option value="SUB">➖ Subtrair do saldo</option>
                                            <option value="SET">🎯 Definir valor exato</option>
                                        </select>
                                    </div>
                                    <div class="col-5">
                                        <label class="form-label small fw-semibold mb-1">Valor (R$)</label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text fw-semibold">R$</span>
                                            <input id="ajValor" type="number" step="0.01" min="0" class="form-control fw-bold" placeholder="0,00">
                                        </div>
                                    </div>
                                </div>
                                <div id="ajCalcBox" class="mt-2 pt-2 border-top d-none">
                                    <div class="d-flex align-items-center gap-2 flex-wrap calc-row">
                                        <span class="text-muted" id="ajCalcBase">0,00</span>
                                        <span class="fw-bold" id="ajCalcOp" style="color:#2563eb;font-size:15px">+</span>
                                        <span class="fw-bold" id="ajCalcVal" style="color:#2563eb">0,00</span>
                                        <span class="text-muted">=</span>
                                        <span class="fw-bold text-success" id="ajCalcResult" style="font-size:16px">0,00</span>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">Motivo</label>
                                    <select id="ajMotivo" class="form-select form-select-sm">
                                        <option value="">Selecione o motivo…</option>
                                        <option value="CONCILIACAO">Conciliação bancária</option>
                                        <option value="CORRECAO_LANCAMENTO">Correção de lançamento</option>
                                        <option value="SALDO_INICIAL">Saldo inicial (implantação)</option>
                                        <option value="TARIFA">Tarifa/taxa não registrada</option>
                                        <option value="OUTRO">Outro</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">Observação <span class="text-danger">*</span></label>
                                    <textarea id="ajObs" class="form-control form-control-sm" rows="3" maxlength="500" placeholder="Descreva o motivo do ajuste"></textarea>
                                    <div class="d-flex justify-content-end mt-1">
                                        <span id="ajObsCount" class="text-muted" style="font-size:11px">0 / 500</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-5 d-flex flex-column gap-3">
                            <div class="saldo-preview">
                                <p class="sp-lbl mb-1" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">
                                    <i class="bi bi-activity me-1"></i>Status da conciliação
                                </p>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div class="mini-bar flex-grow-1">
                                        <div class="mini-bar-fill" id="ajPreviewBar" style="width:0%;background:#f59e0b"></div>
                                    </div>
                                    <span class="small fw-bold mono" id="ajDiffPct" style="min-width:36px;text-align:right;font-size:12px;color:#f59e0b">0%</span>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <p class="sp-lbl">Saldo bancário</p>
                                        <p class="sp-val text-primary" id="ajPreviewBanc">R$ 0,00</p>
                                    </div>
                                    <div class="col-6">
                                        <p class="sp-lbl">Saldo ERP</p>
                                        <p class="sp-val" id="ajPreviewErp">R$ 0,00</p>
                                    </div>
                                    <div class="col-12 pt-2 border-top">
                                        <p class="sp-lbl">Diferença (bancário − ERP)</p>
                                        <p class="sp-val" id="ajPreviewDiff">R$ 0,00</p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex-grow-1">
                                <p class="small fw-semibold text-muted mb-2"><i class="bi bi-clock-history me-1"></i>Histórico de ajustes desta conta</p>
                                <div id="ajAuditoria">
                                    <p class="text-muted small fst-italic">Nenhum ajuste registrado.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer" style="background:#f9fafb;border-top:1px solid #e5e7eb">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-sm btn-primary px-4" id="btnSalvarAjuste">
                        <i class="bi bi-check2-circle me-1"></i>Salvar ajuste
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalDetalheExtrato" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-1">
                    <h5 class="modal-title fw-bold"><i class="bi bi-link-45deg me-2 text-success"></i>Detalhe / Conciliar lançamento</h5>
                    <button class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body" id="modalDetalheBody"></div>
                <div class="modal-footer border-0 pt-0 d-flex justify-content-between">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button class="btn btn-sm btn-success" id="btnConciliarLanc"><i class="bi bi-check-circle me-1"></i>Marcar como conciliado</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: revisão de débitos pendentes após import OFX -->
    <div class="modal fade modal-conciliacao-wide" id="modalDebitosPendentes" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border:0;border-radius:14px;overflow:hidden">
                <div class="modal-header" style="background:#0f172a;color:#fff">
                    <div>
                        <h5 class="modal-title fw-bold mb-1">
                            <i class="bi bi-receipt-cutoff me-2"></i>Débitos do extrato sem lançamento no contas a pagar
                        </h5>
                        <p class="mb-0" style="font-size:12px;color:rgba(255,255,255,.7)">
                            Selecione os débitos que devem virar lançamentos pagos no contas a pagar.
                            Eles serão criados já como <strong>PAGO</strong> e marcados como <strong>conciliados</strong>.
                        </p>
                    </div>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body" style="background:#f8fafc">
                    <div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-3" id="dpResumo" style="font-size:13px">
                        <i class="bi bi-info-circle"></i>
                        <div id="dpResumoTxt">—</div>
                    </div>

                    <div class="row g-2 mb-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1">Empresa padrão</label>
                            <select id="dpEmpresaPadrao" class="form-select form-select-sm"></select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1">Plano de contas padrão</label>
                            <select id="dpPlanoPadrao" class="form-select form-select-sm"></select>
                        </div>
                        <div class="col-md-6 text-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="dpAplicarPadrao">
                                <i class="bi bi-arrow-down-square me-1"></i>Aplicar padrão a todos
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="dpMarcarTodos">
                                <i class="bi bi-check2-square me-1"></i>Selecionar todos
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive border rounded bg-white">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:36px"><input type="checkbox" id="dpChkAll"></th>
                                    <th style="width:90px">Data</th>
                                    <th>Descrição (extrato)</th>
                                    <th style="width:110px" class="text-end">Valor</th>
                                    <th style="width:260px">Vincular a lançamento existente</th>
                                    <th style="width:180px">Empresa</th>
                                    <th style="width:220px">Fornecedor</th>
                                    <th style="width:180px">Plano de contas</th>
                                </tr>
                            </thead>
                            <tbody id="dpTbody"></tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-success me-auto" id="btnDpConfirmarVinculos">
                        <i class="bi bi-link-45deg me-1"></i>Confirmar todos os vínculos sugeridos
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button class="btn btn-sm btn-primary" id="btnDpLancar">
                        <i class="bi bi-check2-circle me-1"></i>Lançar selecionados
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: revisão de créditos pendentes após import OFX -->
    <div class="modal fade modal-conciliacao-wide" id="modalCreditosPendentes" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border:0;border-radius:14px;overflow:hidden">
                <div class="modal-header" style="background:#0f172a;color:#fff">
                    <div>
                        <h5 class="modal-title fw-bold mb-1">
                            <i class="bi bi-arrow-down-circle me-2"></i>Créditos do extrato sem lançamento no contas a receber
                        </h5>
                        <p class="mb-0" style="font-size:12px;color:rgba(255,255,255,.7)">
                            Selecione os créditos que devem virar lançamentos recebidos no contas a receber.
                            Eles serão criados já como <strong>RECEBIDO</strong> e marcados como <strong>conciliados</strong>.
                        </p>
                    </div>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body" style="background:#f8fafc">
                    <div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-3" id="cpResumo" style="font-size:13px">
                        <i class="bi bi-info-circle"></i>
                        <div id="cpResumoTxt">—</div>
                    </div>

                    <div class="row g-2 mb-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1">Empresa padrão</label>
                            <select id="cpEmpresaPadrao" class="form-select form-select-sm"></select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1">Plano de contas padrão</label>
                            <select id="cpPlanoPadrao" class="form-select form-select-sm"></select>
                        </div>
                        <div class="col-md-6 text-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="cpAplicarPadrao">
                                <i class="bi bi-arrow-down-square me-1"></i>Aplicar padrão a todos
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="cpMarcarTodos">
                                <i class="bi bi-check2-square me-1"></i>Selecionar todos
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive border rounded bg-white">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:36px"><input type="checkbox" id="cpChkAll"></th>
                                    <th style="width:90px">Data</th>
                                    <th>Descrição (extrato)</th>
                                    <th style="width:110px" class="text-end">Valor</th>
                                    <th style="width:260px">Vincular a lançamento existente</th>
                                    <th style="width:180px">Empresa</th>
                                    <th style="width:220px">Cliente</th>
                                    <th style="width:180px">Plano de contas</th>
                                </tr>
                            </thead>
                            <tbody id="cpTbody"></tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-success me-auto" id="btnCpConfirmarVinculos">
                        <i class="bi bi-link-45deg me-1"></i>Confirmar todos os vínculos sugeridos
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button class="btn btn-sm btn-success" id="btnCpLancar">
                        <i class="bi bi-check2-circle me-1"></i>Lançar como recebidos
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Revisar vínculos OFX (importações do banco) -->
    <div class="modal fade" id="modalRevisarVinculos" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border:0;border-radius:14px;overflow:hidden">
                <div class="modal-header" style="background:#0f172a;color:#fff">
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-list-check me-2"></i>Importações do banco
                    </h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body" style="background:#f8fafc">
                    <div id="rvMensagem" class="text-muted small mb-2"></div>
                    <div class="table-responsive border rounded bg-white">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Arquivo</th>
                                    <th>Período</th>
                                    <th class="text-end">Saldo final</th>
                                    <th class="text-end">Movs</th>
                                    <th class="text-end">Conc.</th>
                                    <th class="text-end">Déb. pend.</th>
                                    <th class="text-end">Créd. pend.</th>
                                    <th class="text-end" title="Movimentos internos resolvidos automaticamente (transferência, aplicação/resgate, tarifa, rendimento) — não precisam de vínculo">Internos</th>
                                    <th>Importada em</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="rvTbody"><tr><td colspan="11" class="text-center text-muted small">Carregando…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Transferências internas + cadastro de docs do grupo -->
    <div class="modal fade" id="modalTransferenciasInternas" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border:0;border-radius:14px;overflow:hidden">
                <div class="modal-header" style="background:#0ea5e9;color:#fff">
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-arrow-left-right me-2"></i>Transferências entre contas próprias
                    </h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body" style="background:#f8fafc">
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#abaPares">Pares casados</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#abaSemPar">Sem par</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#abaDocs">CNPJs/CPFs do grupo</a></li>
                    </ul>
                    <div class="tab-content pt-3">
                        <div class="tab-pane fade show active" id="abaPares" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <p class="small text-muted mb-0">Débitos e créditos casados entre bancos diferentes com mesmo valor e datas próximas.</p>
                                <button class="btn btn-sm btn-warning" id="btnRodarDeteccao">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Rodar detecção agora
                                </button>
                            </div>
                            <div class="table-responsive border rounded bg-white">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Origem (saída)</th>
                                            <th>Destino (entrada)</th>
                                            <th class="text-end">Valor</th>
                                            <th>Modo</th>
                                            <th>Detectado em</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tiPares">
                                        <tr><td colspan="5" class="text-muted small text-center py-3">Carregando…</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="abaSemPar" role="tabpanel">
                            <p class="small text-muted">Movimentos marcados como transferência interna (CNPJ/nome do grupo no MEMO) que ainda não casaram com um par no outro banco.</p>
                            <div class="table-responsive border rounded bg-white">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Banco</th>
                                            <th>Data</th>
                                            <th>Descrição</th>
                                            <th>Tipo</th>
                                            <th class="text-end">Valor</th>
                                            <th>Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tiSemPar">
                                        <tr><td colspan="6" class="text-muted small text-center py-3">Carregando…</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="abaDocs" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <p class="small text-muted mb-0">CNPJs/CPFs cadastrados como "do grupo". Movimentos com esses documentos no MEMO são marcados como transferência interna automaticamente.</p>
                                <a href="grupo_documentos.php" class="btn btn-sm btn-primary"><i class="bi bi-pencil me-1"></i>Gerenciar cadastro</a>
                            </div>
                            <div class="table-responsive border rounded bg-white">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Tipo</th>
                                            <th>Documento</th>
                                            <th>Nome</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tiDocs">
                                        <tr><td colspan="4" class="text-muted small text-center py-3">Carregando…</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Histórico de Importações OFX -->
    <div class="modal fade" id="modalHistoricoOfx" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border:0;border-radius:14px;overflow:hidden">
                <div class="modal-header" style="background:#dc2626;color:#fff">
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-clock-history me-2"></i>Histórico de Importações OFX
                    </h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body" style="background:#f8fafc">
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Atenção:</strong> excluir uma importação OFX é uma ação destrutiva.
                        Todos os vínculos feitos com movimentos dessa importação serão
                        <strong>desfeitos</strong> (vínculos cancelados, status das contas revertido,
                        avulsos criados pelo OFX excluídos). Requer senha de um usuário <strong>ADMIN</strong>.
                    </div>
                    <div id="histOfxMensagem" class="text-muted small mb-2"></div>
                    <div class="table-responsive border rounded bg-white">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Importado em</th>
                                    <th>Usuário</th>
                                    <th>Banco</th>
                                    <th>Arquivo</th>
                                    <th class="text-end">Entradas</th>
                                    <th class="text-end">Saídas</th>
                                    <th class="text-center">Movs.</th>
                                    <th class="text-center" title="Movimentos conciliados">Concil.</th>
                                    <th class="text-center" title="Vínculos novos ativos">Vínc.</th>
                                    <th class="text-center" title="Avulsos criados pelo OFX">Avulsos</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="histOfxBody">
                                <tr><td colspan="11" class="text-center text-muted py-4">Carregando…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Conferir vínculos ativos -->
    <div class="modal fade" id="modalConferirVinculos" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border:0;border-radius:14px;overflow:hidden">
                <div class="modal-header" style="background:#0f172a;color:#fff">
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-link-45deg me-2"></i>Vínculos ativos
                    </h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body" style="background:#f8fafc">
                    <div id="cvMensagem" class="text-muted small mb-2"></div>
                    <div class="table-responsive border rounded bg-white">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Movimento OFX</th>
                                    <th>Lançamento</th>
                                    <th>Tipo</th>
                                    <th class="text-end">Alocado</th>
                                    <th>Alocação</th>
                                    <th>Origem</th>
                                    <th>Data</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="cvTbody"><tr><td colspan="8" class="text-center text-muted small">Carregando…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php // include __DIR__ . '/includes/footer.php';
    ?>

    <script>
        "use strict";

        const endpoint = "endpoints/conciliacao_bancaria.php";
        let bsAjuste = null;
        let bsDetalhe = null;
        let detalheId = null;
        let detalheMatch = null;
        let detalheMatchTipo = null;
        let resumoAtual = [];
        let auditoriaAtual = [];

        const money = n => Number(n || 0).toLocaleString("pt-BR", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        const pad = n => String(n).padStart(2, "0");
        const todayISO = () => {
            const d = new Date();
            return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
        };

        function formatDate(s) {
            if (!s) return "";
            const m = String(s).slice(0, 10).split("-");
            if (m.length !== 3) return s;
            return `${m[2]}/${m[1]}/${m[0]}`;
        }

        function showToast(msg, type = "info") {
            const c = document.getElementById("toast-container");
            const el = document.createElement("div");
            el.className = `toast-msg is-${type}`;
            el.innerHTML = `
        <i class="ti bi ${type === 'success' ? 'bi-check-circle-fill' : type === 'danger' ? 'bi-x-circle-fill' : type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill'}"></i>
        <div class="toast-body">${msg}</div>
        <button class="toast-close" type="button">&times;</button>
    `;
            el.querySelector(".toast-close").onclick = () => el.remove();
            c.appendChild(el);
            setTimeout(() => el.remove(), 4000);
        }

        async function apiGet(params = {}) {
            const url = new URL(endpoint, window.location.origin + window.location.pathname);
            Object.keys(params).forEach(k => url.searchParams.append(k, params[k]));

            const r = await fetch(url.toString(), {
                method: "GET",
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                }
            });

            const txt = await r.text();

            try {
                return JSON.parse(txt);
            } catch (e) {
                console.error("Resposta inválida do endpoint GET:", txt);
                return {
                    ok: false,
                    msg: "Resposta inválida do endpoint.",
                    detail: txt
                };
            }
        }

        async function apiPostForm(formData) {
            const r = await fetch(endpoint, {
                method: "POST",
                body: formData,
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                }
            });

            const txt = await r.text();

            try {
                return JSON.parse(txt);
            } catch (e) {
                console.error("Resposta inválida do endpoint POST:", txt);
                return {
                    ok: false,
                    msg: "Resposta inválida do endpoint.",
                    detail: txt
                };
            }
        }

        function badgeConciliacao(status) {
            if (status === "OK") return `<span class="badge badge-soft-success d-inline-flex align-items-center gap-1"><span class="sdot sdot-ok"></span>OK</span>`;
            if (status === "DIVERGENTE") return `<span class="badge badge-soft-warning d-inline-flex align-items-center gap-1"><span class="sdot sdot-warn"></span>Divergente</span>`;
            return `<span class="badge badge-soft-secondary">${status || '-'}</span>`;
        }

        function badgeExtrato(status, conciliado) {
            // Status PARCIAL é decidido pelo COM_CONCILIADO ('PARCIAL'), não pelo COM_STATUS
            // (que continua 'CONCILIADO' quando há qualquer vínculo).
            if (conciliado === "PARCIAL") return `<span class="badge badge-soft-warning" style="background:#fef3c7;color:#92400e">Parcial</span>`;
            if (status === "CONCILIADO") return `<span class="badge badge-soft-success">Conciliado</span>`;
            if (status === "IMPORTADO") return `<span class="badge badge-soft-secondary">Importado</span>`;
            if (status === "PENDENTE") return `<span class="badge badge-soft-warning">Pendente</span>`;
            return `<span class="badge badge-soft-secondary">${status || '-'}</span>`;
        }

        function setTab(name) {
            const tabOfx = document.getElementById("tabOfx");
            const tabCnab = document.getElementById("tabCnab");
            const btnOfx = document.getElementById("tabOfxBtn");
            const btnCnab = document.getElementById("tabCnabBtn");

            if (name === "cnab") {
                tabOfx.classList.add("d-none");
                tabCnab.classList.remove("d-none");
                btnOfx.classList.remove("active");
                btnCnab.classList.add("active");
                btnOfx.setAttribute("aria-selected", "false");
                btnCnab.setAttribute("aria-selected", "true");
            } else {
                tabCnab.classList.add("d-none");
                tabOfx.classList.remove("d-none");
                btnCnab.classList.remove("active");
                btnOfx.classList.add("active");
                btnCnab.setAttribute("aria-selected", "false");
                btnOfx.setAttribute("aria-selected", "true");
            }
        }

        async function carregarCombosBancos() {
            const j = await apiGet({
                acao: "combo_bancos"
            });

            if (!j.ok) {
                showToast(j.msg || "Não foi possível carregar os bancos.", "danger");
                return;
            }

            const selBancoOfx = document.getElementById("selBancoOfx");
            const selBancoExtrato = document.getElementById("selBancoExtrato");

            selBancoOfx.innerHTML = "";
            selBancoExtrato.innerHTML = `<option value="">Todos os bancos</option>`;

            if (!j.bancos || !j.bancos.length) {
                selBancoOfx.innerHTML = `<option value="">Nenhum banco ativo</option>`;
                return;
            }

            j.bancos.forEach(b => {
                selBancoOfx.innerHTML += `<option value="${b.id}">${b.texto}</option>`;
                selBancoExtrato.innerHTML += `<option value="${b.id}">${b.texto}</option>`;
            });

            const bancoInicial = selBancoOfx.value;
            if (bancoInicial) {
                await carregarContasBanco(bancoInicial);
            }
        }

        async function carregarContasBanco(bancoFk) {
            const j = await apiGet({
                acao: "combo_contas_banco",
                banco_fk: bancoFk
            });
            const selContaOfx = document.getElementById("selContaOfx");
            selContaOfx.innerHTML = "";

            if (!j.ok || !j.contas || !j.contas.length) {
                selContaOfx.innerHTML = `<option value="">Nenhuma conta</option>`;
                return;
            }

            j.contas.forEach(c => {
                selContaOfx.innerHTML += `<option value="${c.conta_ref}">${c.texto}</option>`;
            });
        }

        async function carregarResumo() {
            const j = await apiGet({
                acao: "resumo",
                periodo: document.getElementById("selPeriodo").value,
                busca: document.getElementById("txtSaldoBusca").value || "",
                status: document.getElementById("selConcStatus").value || "",
                dt_ini: document.getElementById("saldoDtIni").value || "",
                dt_fim: document.getElementById("saldoDtFim").value || ""
            });

            if (!j.ok) {
                showToast(j.msg || "Erro ao carregar resumo.", "danger");
                return;
            }

            resumoAtual = j.contas || [];

            document.getElementById("kpiSaldoBanc").textContent = `R$ ${money(j.cards.saldo_bancario_total)}`;
            document.getElementById("kpiSaldoErp").textContent = `R$ ${money(j.cards.saldo_erp_total)}`;
            document.getElementById("kpiEntradas").textContent = `R$ ${money(j.cards.entradas_periodo)}`;
            document.getElementById("kpiSaidas").textContent = `R$ ${money(j.cards.saidas_periodo)}`;

            const diff = Number(j.cards.saldo_bancario_total || 0) - Number(j.cards.saldo_erp_total || 0);
            const badge = document.getElementById("kpiDivBadge");
            if (Math.abs(diff) < 0.01) {
                badge.className = "badge rounded-pill badge-soft-success";
                badge.textContent = "✓ Conciliado";
            } else {
                badge.className = "badge rounded-pill badge-soft-warning";
                badge.textContent = `Diferença: R$ ${money(diff)}`;
            }

            renderSaldos();
        }

        function renderSaldos() {
            const tbody = document.getElementById("tbodySaldos");
            tbody.innerHTML = "";

            if (!resumoAtual.length) {
                tbody.innerHTML = `<tr><td colspan="9" class="text-muted py-3 text-center small">Nenhuma conta encontrada.</td></tr>`;
                return;
            }

            resumoAtual.forEach((s, idx) => {
                const diff = Number(s.diferenca || 0);
                const cls = s.conciliacao_status === "OK" ? "row-ok" : "row-div";
                const corDiff = Math.abs(diff) < 0.01 ? "text-success" : diff > 0 ? "text-primary" : "text-danger";

                tbody.innerHTML += `
            <tr class="${cls}">
                <td class="mono text-muted small">${idx + 1}</td>
                <td>
                    <div class="fw-semibold">${s.apelido}</div>
                    <div class="text-muted small">${s.banco_nome}</div>
                </td>
                <td class="mono small">${s.agencia}/${s.conta_ref}</td>
                <td class="text-end mono fw-bold">R$ ${money(s.saldo_bancario)}</td>
                <td class="text-end mono">R$ ${money(s.saldo_erp)}</td>
                <td class="text-end mono fw-semibold ${corDiff}">R$ ${money(s.diferenca)}</td>
                <td>${badgeConciliacao(s.conciliacao_status)}</td>
                <td class="mono small text-muted">${s.atualizado_em_br}</td>
                <td class="text-center">
                    <button class="btn-icon is-primary btn-ver-extrato" data-banco="${s.banco_fk}" data-conta="${s.conta_ref}" title="Ver extrato">
                        <i class="bi bi-eye"></i>
                    </button>
                </td>
            </tr>
        `;
            });

            document.querySelectorAll(".btn-ver-extrato").forEach(btn => {
                btn.addEventListener("click", () => {
                    document.getElementById("selBancoExtrato").value = btn.dataset.banco;
                    carregarExtrato(btn.dataset.banco, btn.dataset.conta);
                    document.getElementById("tabOfx").scrollIntoView({
                        behavior: "smooth",
                        block: "start"
                    });
                });
            });
        }

        async function carregarExtrato(bancoFk = "", contaRef = "") {
            const j = await apiGet({
                acao: "listar_extrato",
                banco_fk: bancoFk || document.getElementById("selBancoExtrato").value || "",
                conta_ref: contaRef,
                busca: document.getElementById("txtExtratoBusca").value || "",
                status: document.getElementById("selStatusExtrato").value || ""
            });

            const tbody = document.getElementById("tbodyExtrato");
            tbody.innerHTML = "";

            if (!j.ok || !j.movimentos.length) {
                tbody.innerHTML = `<tr><td colspan="7" class="text-muted py-3 text-center small">Nenhum lançamento.</td></tr>`;
                return;
            }

            j.movimentos.forEach(r => {
                const valor = Number(r.valor || 0);
                const isOut = valor < 0;
                // Badge de natureza (Fase H do Briefing 2026-06-09)
                const natBadge = (r.natureza === 'TRANSFERENCIA_INTERNA')
                    ? '<span class="badge ms-1" style="background:rgba(14,165,233,.12);color:#0c4a6e">Transf. interna</span>'
                    : (r.natureza === 'APLICACAO')
                    ? '<span class="badge ms-1" style="background:rgba(245,158,11,.15);color:#92400e">Aplicação</span>'
                    : (r.natureza === 'RENDIMENTO')
                    ? '<span class="badge ms-1" style="background:rgba(34,197,94,.12);color:#14532d">Rendimento</span>'
                    : (r.natureza === 'TARIFA')
                    ? '<span class="badge ms-1" style="background:rgba(100,116,139,.15);color:#475569">Tarifa</span>'
                    : '';
                tbody.innerHTML += `
            <tr style="${r.status === 'CONCILIADO' ? 'opacity:.6' : ''}">
                <td class="mono small">${r.data_br}</td>
                <td class="fw-semibold small">${r.banco_nome}</td>
                <td><span class="truncate" title="${r.descricao}">${r.descricao}</span>${natBadge}</td>
                <td class="text-end mono small ${isOut ? 'text-danger' : 'text-success'}">${isOut ? '−' : '+'}R$ ${money(Math.abs(valor))}</td>
                <td class="text-end mono small">R$ ${money(r.saldo_apos)}</td>
                <td>${badgeExtrato(r.status, r.conciliado)}</td>
                <td class="text-center">
                    ${r.status !== 'CONCILIADO'
                        ? `<button class="btn-icon is-success btn-detalhe-ext" data-id="${r.id}" title="Detalhe/Conciliar"><i class="bi bi-link-45deg"></i></button>`
                        : `<button class="btn-icon" disabled><i class="bi bi-check-circle-fill text-success"></i></button>`}
                </td>
            </tr>
        `;
            });

            document.querySelectorAll(".btn-detalhe-ext").forEach(btn => {
                btn.addEventListener("click", () => abrirDetalheExtrato(btn.dataset.id));
            });
        }

        async function abrirDetalheExtrato(id) {
            detalheId = id;
            detalheMatch = null;
            detalheMatchTipo = null;

            const j = await apiGet({
                acao: "detalhe_extrato",
                id
            });
            if (!j.ok) {
                showToast(j.msg || "Erro ao abrir detalhe.", "danger");
                return;
            }

            const r = j.movimento;
            const valor = Number(r.valor || 0);
            const isOut = valor < 0;

            let blocoMatch = '';
            if (j.match) {
                detalheMatch = j.match;
                detalheMatchTipo = j.match_tipo;
                const m = j.match;
                const lblTipo = j.match_tipo === 'PAGAR' ? 'conta a pagar' : 'conta a receber';
                const nomeContraparte = (j.match_tipo === 'PAGAR') ? (m.fornecedor || '') : (m.cliente || '');
                const venc = m.vencimento ? formatDateBR(m.vencimento) : '-';
                const valorRef = Number(m.valor_pago || m.valor_recebido || m.valor || 0);
                blocoMatch = `
            <div class="alert alert-success py-2 small mt-2 mb-0">
                <i class="bi bi-link-45deg me-1"></i>
                <strong>Vínculo sugerido:</strong> ${lblTipo} #${m.id}${lancParcelaBadge(m)}
                ${nomeContraparte ? '· ' + escapeHtml(nomeContraparte) : ''}
                · venc. ${venc} · R$ ${money(valorRef)} · status ${escapeHtml(m.status || '')}
                <div class="text-muted mt-1" style="font-size:11px">
                    Ao clicar em <strong>Conciliar e vincular</strong>, este movimento será associado a essa ${lblTipo}.
                    Se ela ainda estiver em aberto, será marcada como ${j.match_tipo === 'PAGAR' ? 'PAGO' : 'RECEBIDO'} e o saldo do banco será atualizado.
                </div>
            </div>
        `;
            } else if (String(r.status || '').toUpperCase() !== 'CONCILIADO') {
                blocoMatch = `
            <div class="alert alert-warning py-2 small mt-2 mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>Sem match automático.</strong>
                "Marcar como conciliado" só esconde da lista de revisão; <u>não cria nem atualiza nenhuma conta</u>.
                Para atualizar o saldo, use o modal de revisão (<em>"Lançar selecionados"</em> ou <em>"Vincular a lançamento existente"</em>).
            </div>
        `;
            }

            // Vínculos atuais (tabela nova + fallback legado)
            let blocoVinculos = '';
            try {
                const a = await apiGet({ acao: 'obter_alocacoes_movimento', movimento_fk: id });
                if (a.ok && Array.isArray(a.vinculos) && a.vinculos.length > 0) {
                    const linhas = a.vinculos.map(v => `
                        <tr>
                            <td><span class="badge ${v.tipo === 'PAGAR' ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'}">${v.tipo}</span>${v.origem === 'LEGADO' ? ' <span class="badge bg-light text-muted" title="Vínculo legado 1:1">legado</span>' : ''}</td>
                            <td class="small">#${v.lancamento_id}${lancParcelaBadge(v)} · ${escapeHtml(v.lancamento_descricao || '')}</td>
                            <td class="text-end mono small">R$ ${money(v.valor_alocado)}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger btn-cancelar-vinculo"
                                        data-vin="${v.vin_id || ''}"
                                        data-mov-legacy="${v.origem === 'LEGADO' ? id : ''}"
                                        data-tipo="${v.tipo}"
                                        title="Cancelar este vínculo">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </td>
                        </tr>
                    `).join('');
                    blocoVinculos = `
                        <hr class="my-2">
                        <h6 class="small fw-bold mb-2"><i class="bi bi-diagram-3 me-1"></i>Vínculos desta integração</h6>
                        <table class="table table-sm mb-2">
                            <thead><tr><th>Tipo</th><th>Lançamento</th><th class="text-end">Alocado</th><th></th></tr></thead>
                            <tbody>${linhas}</tbody>
                            <tfoot><tr><th colspan="2" class="text-end small">Total alocado</th><th class="text-end mono small">R$ ${money(a.soma_alocada)}</th><th></th></tr></tfoot>
                        </table>
                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn btn-sm btn-outline-warning" id="btnCancelarIntegracao" data-mov="${id}">
                                <i class="bi bi-trash me-1"></i>Cancelar integração inteira
                            </button>
                        </div>
                    `;
                }
            } catch (e) { /* silencioso */ }

            document.getElementById("modalDetalheBody").innerHTML = `
        <div class="row g-2">
            <div class="col-6"><p class="small text-muted mb-0">Data</p><p class="fw-semibold mb-1">${r.data_br}</p></div>
            <div class="col-6"><p class="small text-muted mb-0">Banco</p><p class="fw-semibold mb-1">${r.banco_nome}</p></div>
            <div class="col-12"><p class="small text-muted mb-0">Descrição</p><p class="fw-semibold mb-1">${escapeHtml(r.descricao)}</p></div>
            <div class="col-6"><p class="small text-muted mb-0">Valor</p><p class="fw-bold mono fs-5 ${isOut ? 'text-danger' : 'text-success'} mb-1">${isOut ? '−' : '+'}R$ ${money(Math.abs(valor))}</p></div>
            <div class="col-6"><p class="small text-muted mb-0">Saldo após</p><p class="fw-semibold mono mb-1">R$ ${money(r.saldo_apos)}</p></div>
            <div class="col-12"><p class="small text-muted mb-0">Status</p>${badgeExtrato(r.status, r.conciliado)}</div>
            <div class="col-12">${blocoMatch}</div>
            <div class="col-12">${blocoVinculos}</div>
        </div>
    `;

            const btn = document.getElementById('btnConciliarLanc');
            if (btn) {
                if (detalheMatch) {
                    btn.innerHTML = '<i class="bi bi-link-45deg me-1"></i>Conciliar e vincular';
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-primary');
                } else {
                    btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Marcar como conciliado';
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-success');
                }
            }

            bsDetalhe.show();
        }

        async function conciliarLancamento() {
            if (!detalheId) return;

            const fd = new FormData();
            if (detalheMatch && detalheMatchTipo) {
                fd.append("acao", "conciliar_e_vincular");
                fd.append("id", detalheId);
                fd.append("tipo", detalheMatchTipo);
                fd.append("lancamento_id", detalheMatch.id);
            } else {
                fd.append("acao", "conciliar_movimento");
                fd.append("id", detalheId);
            }

            const j = await apiPostForm(fd);
            if (!j.ok) {
                showToast(j.msg || "Erro ao conciliar.", "danger");
                return;
            }

            bsDetalhe.hide();
            await carregarResumo();
            await carregarExtrato();
            showToast(detalheMatch ? "Vínculo realizado e saldo atualizado." : "Movimento marcado como conciliado.", "success");
        }

        async function carregarModalAjuste() {
            const j = await apiGet({
                acao: "combo_todas_contas"
            });
            if (!j.ok) {
                showToast(j.msg || "Erro ao carregar contas.", "danger");
                return;
            }

            const sel = document.getElementById("ajConta");
            sel.innerHTML = "";

            if (!j.contas || !j.contas.length) {
                showToast("Nenhuma conta bancária ativa encontrada.", "warning");
                return;
            }

            j.contas.forEach(c => {
                sel.innerHTML += `<option value="${c.chave}">${c.texto}</option>`;
            });

            document.getElementById("ajData").value = todayISO();
            document.getElementById("ajTipo").value = "SALDO_BANCARIO";
            document.getElementById("ajOp").value = "SOMA";
            document.getElementById("ajValor").value = "";
            document.getElementById("ajMotivo").value = "";
            document.getElementById("ajObs").value = "";
            document.getElementById("ajObsCount").textContent = "0 / 500";

            await atualizarPreviewAjuste();
            bsAjuste.show();
        }

        async function atualizarPreviewAjuste() {
            const chave = document.getElementById("ajConta").value;
            if (!chave) return;

            const j = await apiGet({
                acao: "preview_conta",
                chave
            });
            if (!j.ok) return;

            const banc = Number(j.saldo_bancario || 0);
            const erp = Number(j.saldo_erp || 0);
            const diff = Number(j.diferenca || 0);

            document.getElementById("ajHdrBanc").textContent = `R$ ${money(banc)}`;
            document.getElementById("ajHdrErp").textContent = `R$ ${money(erp)}`;
            document.getElementById("ajHdrDiff").textContent = `R$ ${money(diff)}`;
            document.getElementById("ajPreviewBanc").textContent = `R$ ${money(banc)}`;
            document.getElementById("ajPreviewErp").textContent = `R$ ${money(erp)}`;
            document.getElementById("ajPreviewDiff").textContent = `R$ ${money(diff)}`;

            const pct = Math.min(100, Math.abs(diff) / Math.max(Math.abs(banc), 1) * 500);
            document.getElementById("ajPreviewBar").style.width = pct + "%";
            document.getElementById("ajDiffPct").textContent = pct.toFixed(0) + "%";

            const val = Number(document.getElementById("ajValor").value || 0);
            const op = document.getElementById("ajOp").value;
            const tipo = document.getElementById("ajTipo").value;
            const calcBox = document.getElementById("ajCalcBox");

            if (val > 0) {
                const base = tipo === "SALDO_BANCARIO" ? banc : erp;
                const novo = op === "SOMA" ? base + val : op === "SUB" ? base - val : val;

                calcBox.classList.remove("d-none");
                document.getElementById("ajCalcBase").textContent = `R$ ${money(base)}`;
                document.getElementById("ajCalcOp").textContent = op === "SOMA" ? "+" : op === "SUB" ? "−" : "=";
                document.getElementById("ajCalcVal").textContent = `R$ ${money(val)}`;
                document.getElementById("ajCalcResult").textContent = `R$ ${money(novo)}`;
                document.getElementById("ajHdrNovo").textContent = `R$ ${money(novo)}`;
            } else {
                calcBox.classList.add("d-none");
                document.getElementById("ajHdrNovo").textContent = "—";
            }

            auditoriaAtual = j.auditoria || [];
            const box = document.getElementById("ajAuditoria");
            if (!auditoriaAtual.length) {
                box.innerHTML = `<p class="text-muted small fst-italic">Nenhum ajuste registrado para esta conta.</p>`;
            } else {
                box.innerHTML = auditoriaAtual.map(a => `
            <div class="audit-item ${Math.abs(Number(a.diferenca_pos || 0)) < 0.01 ? 'ok' : 'warn'} mb-2">
                <p class="small mb-0 fw-semibold">${a.motivo} — R$ ${money(a.valor)}</p>
                <p class="small text-muted mb-0">${a.observacao || ''} <span class="ms-1 mono">${a.data_br}</span></p>
            </div>
        `).join('');
            }
        }

        async function salvarAjuste() {
            const chave = document.getElementById("ajConta").value;
            const data = document.getElementById("ajData").value;
            const campo = document.getElementById("ajTipo").value;
            const operacao = document.getElementById("ajOp").value;
            const valor = document.getElementById("ajValor").value;
            const motivo = document.getElementById("ajMotivo").value;
            const observacao = document.getElementById("ajObs").value;

            const fd = new FormData();
            fd.append("acao", "salvar_ajuste");
            fd.append("chave", chave);
            fd.append("data", data);
            fd.append("campo", campo);
            fd.append("operacao", operacao);
            fd.append("valor", valor);
            fd.append("motivo", motivo);
            fd.append("observacao", observacao);

            const j = await apiPostForm(fd);
            if (!j.ok) {
                showToast(j.msg || "Erro ao salvar ajuste.", "danger");
                return;
            }

            bsAjuste.hide();
            await carregarResumo();
            await carregarExtrato();
            showToast("Ajuste salvo com sucesso.", "success");
        }

        async function processarOfx() {
            const bancoFk = document.getElementById("selBancoOfx").value;
            const contaRef = document.getElementById("selContaOfx").value;
            const dataIni = document.getElementById("dtIniOfx").value;
            const dataFim = document.getElementById("dtFimOfx").value;
            const file = document.getElementById("fileOfx").files[0];

            if (!bancoFk) return showToast("Selecione o banco.", "warning");
            if (!contaRef) return showToast("Selecione a conta.", "warning");
            if (!file) return showToast("Selecione o arquivo .OFX.", "warning");

            const fd = new FormData();
            fd.append("acao", "importar_ofx");
            fd.append("banco_fk", bancoFk);
            fd.append("conta_ref", contaRef);
            fd.append("data_ini", dataIni);
            fd.append("data_fim", dataFim);
            fd.append("arquivo_ofx", file);

            const statusEl = document.getElementById("ofxStatus");
            statusEl.innerHTML = `<i class="bi bi-hourglass-split me-1"></i>Enviando...`;

            let j;
            try {
                j = await apiPostForm(fd);
            } catch (err) {
                console.error("Falha de rede ao importar OFX:", err);
                statusEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1 text-danger"></i>Falha de rede`;
                showToast("Falha de rede ao enviar o OFX.", "danger");
                return;
            }

            if (!j.ok) {
                console.error("Erro OFX:", j);
                statusEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1 text-danger"></i>Erro`;
                showToast(j.msg || "Erro ao importar OFX.", "danger");
                return;
            }

            statusEl.innerHTML = `<i class="bi bi-check-circle-fill me-1 text-success"></i>OFX processado`;
            await carregarResumo();
            // Filtra a listagem do extrato para mostrar apenas o banco recém-importado.
            const selBancoExtrato = document.getElementById("selBancoExtrato");
            if (selBancoExtrato) selBancoExtrato.value = bancoFk;
            await carregarExtrato(bancoFk, contaRef);
            showToast(j.msg || "OFX importado com sucesso.", "success");

            if (j.importacao_fk) {
                window.lastImportacaoFk = j.importacao_fk;
                await abrirFluxoConciliacao(j.importacao_fk);
            }
        }

        async function abrirFluxoConciliacao(impFk) {
            await abrirModalDebitosPendentes(impFk);
            const elDeb = document.getElementById("modalDebitosPendentes");
            if (elDeb) {
                elDeb.addEventListener("hidden.bs.modal", function onHide() {
                    elDeb.removeEventListener("hidden.bs.modal", onHide);
                    abrirModalCreditosPendentes(impFk);
                });
            } else {
                await abrirModalCreditosPendentes(impFk);
            }
        }

        async function revisarConciliacao() {
            let impFk = window.lastImportacaoFk || 0;
            if (!impFk) {
                const bancoFk = document.getElementById("selBancoOfx").value || "";
                const contaRef = document.getElementById("selContaOfx").value || "";
                const j = await apiGet({ acao: "ultima_importacao", banco_fk: bancoFk, conta_ref: contaRef });
                impFk = (j.ok && j.importacao_fk) ? j.importacao_fk : 0;
            }
            if (!impFk) {
                showToast("Nenhuma importação encontrada. Importe um OFX primeiro.", "warning");
                return;
            }
            window.lastImportacaoFk = impFk;
            await abrirFluxoConciliacao(impFk);
        }

        // ====== Modal: débitos pendentes após import OFX ======
        let bsDebitosPendentes = null;
        let dpDebitos = [];
        let dpLancMes = {}; // { 'YYYY-MM': [lancamentos disponiveis] }
        let cpLancMes = {};

        async function carregarLancamentosMes(tipo, datas, bancoFk) {
            const meses = Array.from(new Set(datas.map(d => (d || '').substring(0, 7)).filter(Boolean)));
            const map = {};
            await Promise.all(meses.map(async m => {
                const j = await apiGet({ acao: "lancamentos_disponiveis", tipo, mes: m, banco_fk: bancoFk || 0 });
                map[m] = (j && j.ok) ? (j.rows || []) : [];
            }));
            return map;
        }

        function lancSaldoRestante(tipo, r) {
            // Saldo restante a alocar = valor total − valor já pago/recebido (parcial existente).
            const total = Number(r.valor || 0);
            const jaQuitado = (tipo === 'PAGAR') ? Number(r.valor_pago || 0) : Number(r.valor_recebido || 0);
            return Math.max(0, total - jaQuitado);
        }
        function lancParcelaLabel(r) {
            const n = r && r.num_parcela;
            const t = r && r.qtd_parcelas;
            if (n && t) {
                return ` · parcela ${String(n).padStart(2,'0')}/${String(t).padStart(2,'0')}`;
            }
            return '';
        }
        function lancParcelaBadge(r) {
            const n = r && (r.num_parcela ?? r.numParcela);
            const t = r && (r.qtd_parcelas ?? r.qtdParcelas);
            if (n && t) {
                return ` <span class="badge bg-info-subtle text-info">parc ${String(n).padStart(2,'0')}/${String(t).padStart(2,'0')}</span>`;
            }
            return '';
        }
        function lancJaQuitada(tipo, r) {
            const total = Number(r.valor || 0);
            const jaQuitado = (tipo === 'PAGAR') ? Number(r.valor_pago || 0) : Number(r.valor_recebido || 0);
            const status = String(r.status || '').toUpperCase();
            if (tipo === 'PAGAR') {
                return status === 'PAGO' && Math.abs(jaQuitado - total) < 0.01;
            }
            return (status === 'RECEBIDO' || status === 'PAGO') && Math.abs(jaQuitado - total) < 0.01;
        }
        function lancOptionLabel(tipo, r) {
            const venc = r.vencimento ? formatDateBR(r.vencimento) : '-';
            const total = Number(r.valor || 0);
            const saldo = lancSaldoRestante(tipo, r);
            const jaPaga = lancJaQuitada(tipo, r);
            const nomeRaw = (tipo === 'PAGAR') ? (r.fornecedor || r.descricao || '-') : (r.cliente || r.descricao || '-');
            const nome = String(nomeRaw).toUpperCase();
            const doc = r.documento ? ' · doc ' + r.documento : '';

            let valorTxt;
            if (jaPaga) {
                valorTxt = `R$ ${money(total)} · ✓ JÁ PAGO — apenas vincular OFX`;
            } else if (saldo < total - 0.005) {
                valorTxt = `R$ ${money(saldo)} restante (de R$ ${money(total)})`;
            } else {
                valorTxt = `R$ ${money(total)}`;
            }
            // Ordem: NOME (caps) → VALOR → #ID → parcela → venc → doc → status
            return `${nome} → ${valorTxt} · #${r.id}${lancParcelaLabel(r)} · ${venc}${doc} · ${r.status || ''}`;
        }

        function formatDateBR(s) {
            if (!s) return '-';
            const d = String(s).substring(0, 10);
            if (/^\d{4}-\d{2}-\d{2}$/.test(d)) return d.split('-').reverse().join('/');
            return d;
        }

        // Normaliza um lançamento vindo de buscar_lancamento_existente para o formato
        // que lancOptionLabel/lancSaldoRestante/lancJaQuitada esperam.
        function normalizarLancServidor(tipo, r) {
            if (tipo === 'PAGAR') r.fornecedor = r.fornecedor_fantasia || r.fornecedor_razao || r.descricao || '';
            else                  r.cliente    = r.cliente_nome || r.descricao || '';
            return r;
        }

        // Busca lançamentos no servidor por texto/#ID, SEM travar por mês (resolve o
        // caso de conciliar um movimento contra um lançamento de outro período).
        async function buscarLancServidor(tipo, termo, valorMov, bancoFk) {
            const j = await apiGet({ acao: "buscar_lancamento_existente", tipo, q: termo, valor: valorMov || 0, banco_fk: bancoFk || 0 });
            if (!j || !j.ok || !Array.isArray(j.rows)) return [];
            return j.rows.map(r => normalizarLancServidor(tipo, r));
        }

        // Monta um <option> do modal de alocação múltipla a partir de um lançamento.
        function pmBuildOption(tipo, r) {
            const label = lancOptionLabel(tipo, r);
            const desc = (tipo === 'PAGAR') ? (r.fornecedor || r.descricao || '') : (r.cliente || r.descricao || '');
            return `<option value="${r.id}"
                data-saldo="${lancSaldoRestante(tipo, r)}"
                data-total="${Number(r.valor || 0)}"
                data-quitada="${lancJaQuitada(tipo, r) ? '1' : '0'}"
                data-num-parcela="${r.num_parcela || ''}"
                data-qtd-parcelas="${r.qtd_parcelas || ''}"
                data-desc="${escapeAttr(desc)}"
                data-search="${escapeAttr(pmNormalizar(label))}"
                data-remote="1">${escapeHtml(label)}</option>`;
        }

        // <option> simples para os selects inline (linhas de débito/crédito pendente).
        function inlineBuildOption(tipo, r) {
            const lbl = lancOptionLabel(tipo, r);
            return `<option value="${r.id}" data-search="${escapeAttr(pmNormalizar(lbl + ' #' + r.id))}" data-remote="1">${escapeHtml(lbl)}</option>`;
        }

        // Busca no servidor e injeta no select inline as options ainda não presentes,
        // depois reaplica o filtro digitado. Usado quando o lançamento está fora do mês.
        async function inlineBuscaRemota(tipo, inpB, sel) {
            const termo = (inpB.value || '').trim();
            if (termo.length < 2) return;
            const rows = await buscarLancServidor(tipo, termo, 0, 0);
            const novos = rows.filter(r => !sel.querySelector('option[value="' + r.id + '"]'));
            novos.forEach(r => sel.insertAdjacentHTML('beforeend', inlineBuildOption(tipo, r)));
            const t = pmNormalizar(inpB.value);
            sel.querySelectorAll('option[data-search]').forEach(opt => {
                opt.hidden = !!t && (opt.dataset.search || '').indexOf(t) < 0;
            });
        }

        async function abrirModalDebitosPendentes(importacaoFk) {
            const j = await apiGet({ acao: "debitos_orfaos", importacao_fk: importacaoFk });
            if (!j.ok) {
                showToast(j.msg || "Erro ao buscar débitos pendentes.", "danger");
                return;
            }

            dpDebitos = j.debitos_orfaos || [];

            const txt = document.getElementById("dpResumoTxt");
            const tot = j.total_debitos || 0;
            const tm = j.total_match || 0;
            const to = j.total_orfaos || 0;
            if (!dpDebitos.length) {
                txt.innerHTML = `Nenhum débito a revisar.`;
                document.getElementById("dpTbody").innerHTML = "";
                if (!bsDebitosPendentes) bsDebitosPendentes = new bootstrap.Modal(document.getElementById("modalDebitosPendentes"));
                bsDebitosPendentes.show();
                return;
            }

            txt.innerHTML = `<strong>${tot}</strong> débito(s) no extrato — <span class="text-success fw-semibold">${tm} já vinculado(s)</span> · <span class="text-danger fw-semibold">${to} sem vínculo</span>.`;

            const bancoFk = (dpDebitos[0] && dpDebitos[0].banco_fk) || 0;
            const datas = dpDebitos.filter(d => !d.match).map(d => d.data);
            await Promise.all([
                dpCarregarEmpresas(),
                dpCarregarPlanoContas(),
                carregarLancamentosMes("PAGAR", datas, bancoFk).then(m => { dpLancMes = m; })
            ]);
            dpRenderTabela();

            if (!bsDebitosPendentes) bsDebitosPendentes = new bootstrap.Modal(document.getElementById("modalDebitosPendentes"));
            bsDebitosPendentes.show();
        }

        async function dpCarregarEmpresas() {
            const j = await apiGet({ acao: "combo_empresas_conc" });
            if (!j.ok) return;
            const opts = '<option value="">Selecione...</option>' + (j.rows || []).map(e => `<option value="${e.id}">${e.nome}</option>`).join('');
            document.getElementById("dpEmpresaPadrao").innerHTML = opts;
        }

        async function dpCarregarPlanoContas(empresaFk) {
            const params = { acao: "combo_plano_contas_conc" };
            if (empresaFk) params.empresa_fk = empresaFk;
            const j = await apiGet(params);
            if (!j.ok) return;
            const opts = '<option value="">Selecione...</option>' + (j.rows || []).map(p => `<option value="${p.id}">${p.nome}</option>`).join('');
            document.getElementById("dpPlanoPadrao").innerHTML = opts;
            // atualiza selects das linhas também
            document.querySelectorAll(".dp-row-plano").forEach(sel => {
                const cur = sel.value;
                sel.innerHTML = opts;
                if (cur) sel.value = cur;
            });
        }

        function dpRenderTabela() {
            const tb = document.getElementById("dpTbody");
            const planoOpts = document.getElementById("dpPlanoPadrao").innerHTML || '<option value="">Selecione...</option>';
            const empresaOpts = document.getElementById("dpEmpresaPadrao").innerHTML || '<option value="">Selecione...</option>';

            tb.innerHTML = dpDebitos.map((d, i) => {
                if (d.match) {
                    const m = d.match;
                    const vencBr = m.vencimento ? formatDate(m.vencimento) : '-';
                    const valr = (m.valor_pago || m.valor || 0);
                    return `
                    <tr data-idx="${i}" data-matched="1" style="background:#e8f7ee">
                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                        <td class="text-nowrap small">${formatDate(d.data)}</td>
                        <td class="small">
                            <div class="fw-semibold">${escapeHtml(d.descricao || '-')}</div>
                            <div class="text-muted">${d.banco_nome ? escapeHtml(d.banco_nome) : ''}${d.documento ? ' · doc ' + escapeHtml(d.documento) : ''}</div>
                        </td>
                        <td class="text-end mono small">R$ ${money(d.valor)}</td>
                        <td colspan="4" class="small">
                            <div class="text-success fw-semibold">
                                <i class="bi bi-link-45deg me-1"></i>Vinculado a #${m.id}${lancParcelaBadge(m)} — ${escapeHtml(m.fornecedor || m.descricao || '-')}
                            </div>
                            <div class="text-muted">
                                Venc ${vencBr} · R$ ${money(valr)}${m.documento ? ' · doc ' + escapeHtml(m.documento) : ''} · ${escapeHtml(m.status || '')}
                            </div>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1 btn-cancelar-vinc"
                                    data-mov-fk="${d.movimento_fk}" data-lanc="#${m.id}">
                                <i class="bi bi-x-circle me-1"></i>Cancelar vínculo (vínculo errado)
                            </button>
                        </td>
                    </tr>`;
                }
                const mesD = (d.data || '').substring(0, 7);
                const lancList = (dpLancMes[mesD] || []);
                const lancOpts = '<option value="">— vincular a um lançamento do mês —</option>' +
                    lancList.map(r => {
                        const lbl = lancOptionLabel("PAGAR", r);
                        return `<option value="${r.id}" data-search="${escapeAttr(pmNormalizar(lbl + ' #' + r.id))}">${escapeHtml(lbl)}</option>`;
                    }).join('');
                return `
                <tr data-idx="${i}" data-matched="0" style="background:#fdecec">
                    <td><input type="checkbox" class="dp-row-chk" checked></td>
                    <td class="text-nowrap small">${formatDate(d.data)}</td>
                    <td class="small">
                        <div class="fw-semibold">${escapeHtml(d.descricao || '-')}</div>
                        <div class="text-muted">${d.banco_nome ? escapeHtml(d.banco_nome) : ''}${d.documento ? ' · doc ' + escapeHtml(d.documento) : ''}</div>
                    </td>
                    <td class="text-end mono small">R$ ${money(d.valor)}</td>
                    <td>
                        <input type="text" class="form-control form-control-sm dp-row-vinc-busca mb-1" placeholder="🔍 Filtrar por nome, valor, #ID…" autocomplete="off">
                        <select class="form-select form-select-sm dp-row-vinc-fk" title="${lancList.length} lançamento(s) sem vínculo (mês ${mesD} ± 1)">
                            ${lancOpts}
                        </select>
                        <div class="dp-row-vinc-info small text-success mt-1 d-none"></div>
                        <button type="button" class="btn btn-sm btn-link p-0 mt-1 btn-mais-vinculos" data-tipo="PAGAR" data-mov-fk="${d.movimento_fk}" data-valor="${Math.abs(Number(d.valor||0))}" data-mes="${mesD}" data-data="${d.data || ''}">
                            <i class="bi bi-plus-square me-1"></i>Vincular múltiplos lançamentos
                        </button>
                    </td>
                    <td><select class="form-select form-select-sm dp-row-empresa">${empresaOpts}</select></td>
                    <td>
                        <div class="position-relative">
                            <input type="text" class="form-control form-control-sm dp-row-fornecedor-busca" placeholder="Digite p/ buscar..." autocomplete="off">
                            <input type="hidden" class="dp-row-fornecedor-fk">
                            <div class="dp-autocomplete bg-white border rounded shadow-sm position-absolute w-100 d-none" style="z-index:1080;max-height:180px;overflow:auto;font-size:12px"></div>
                        </div>
                    </td>
                    <td><select class="form-select form-select-sm dp-row-plano">${planoOpts}</select></td>
                </tr>`;
            }).join("");

            // change handler do select de vincular: mostra resumo da seleção
            tb.querySelectorAll('tr[data-matched="0"] .dp-row-vinc-fk').forEach(sel => {
                sel.addEventListener("change", () => {
                    const tr = sel.closest("tr");
                    const inf = tr.querySelector(".dp-row-vinc-info");
                    if (sel.value) {
                        const lbl = sel.options[sel.selectedIndex].text;
                        inf.textContent = "Vincular: " + lbl;
                        inf.classList.remove("d-none");
                    } else {
                        inf.classList.add("d-none");
                        inf.textContent = "";
                    }
                });
            });

            // busca/filtro do select de vincular: filtra options por nome, valor e #ID
            tb.querySelectorAll('tr[data-matched="0"]').forEach(tr => {
                const inpB = tr.querySelector('.dp-row-vinc-busca');
                const sel = tr.querySelector('.dp-row-vinc-fk');
                if (!inpB || !sel) return;
                let dpBuscaTimer = null;
                inpB.addEventListener('input', () => {
                    const termo = pmNormalizar(inpB.value);
                    sel.querySelectorAll('option[data-search]').forEach(opt => {
                        opt.hidden = !!termo && (opt.dataset.search || '').indexOf(termo) < 0;
                    });
                    const optSel = sel.options[sel.selectedIndex];
                    if (optSel && optSel.hidden) { sel.value = ''; sel.dispatchEvent(new Event('change')); }
                    clearTimeout(dpBuscaTimer);
                    dpBuscaTimer = setTimeout(() => inlineBuscaRemota('PAGAR', inpB, sel), 350);
                });
                // Enter seleciona a 1ª option visível
                inpB.addEventListener('keydown', (ev) => {
                    if (ev.key !== 'Enter') return;
                    ev.preventDefault();
                    const first = Array.from(sel.querySelectorAll('option[data-search]')).find(o => !o.hidden);
                    if (first) { sel.value = first.value; sel.dispatchEvent(new Event('change')); }
                });
            });

            // autocomplete fornecedor por linha (apenas linhas órfãs)
            tb.querySelectorAll('tr[data-matched="0"]').forEach(tr => {
                const inp = tr.querySelector(".dp-row-fornecedor-busca");
                const hid = tr.querySelector(".dp-row-fornecedor-fk");
                const box = tr.querySelector(".dp-autocomplete");
                if (!inp) return;
                let timer = null;
                inp.addEventListener("input", () => {
                    hid.value = "";
                    clearTimeout(timer);
                    const q = inp.value.trim();
                    if (!q) { box.classList.add("d-none"); return; }
                    timer = setTimeout(async () => {
                        const j = await apiGet({ acao: "autocomplete_fornecedor_conc", q });
                        if (!j.ok || !j.rows || !j.rows.length) { box.classList.add("d-none"); return; }
                        box.innerHTML = j.rows.map(f => `
                            <div class="dp-ac-item px-2 py-1 border-bottom" data-id="${f.id}" data-label="${escapeAttr(f.fantasia || f.razao || '')}" style="cursor:pointer">
                                <div><strong>${escapeHtml(f.fantasia || f.razao || '')}</strong></div>
                                <div class="text-muted">${escapeHtml(f.cnpj || '')}</div>
                            </div>
                        `).join("");
                        box.classList.remove("d-none");
                        box.querySelectorAll(".dp-ac-item").forEach(it => {
                            it.addEventListener("click", () => {
                                hid.value = it.getAttribute("data-id");
                                inp.value = it.getAttribute("data-label");
                                box.classList.add("d-none");
                            });
                        });
                    }, 250);
                });
                inp.addEventListener("blur", () => setTimeout(() => box.classList.add("d-none"), 200));
            });
        }

        function escapeHtml(s) { return String(s ?? "").replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
        function escapeAttr(s) { return escapeHtml(s).replace(/"/g, '&quot;'); }

        document.addEventListener("DOMContentLoaded", () => {
            const chkAll = document.getElementById("dpChkAll");
            if (chkAll) chkAll.addEventListener("change", () => {
                document.querySelectorAll(".dp-row-chk").forEach(c => c.checked = chkAll.checked);
            });

            const empPadrao = document.getElementById("dpEmpresaPadrao");
            if (empPadrao) empPadrao.addEventListener("change", () => {
                if (empPadrao.value) dpCarregarPlanoContas(empPadrao.value);
            });

            const btnAplicar = document.getElementById("dpAplicarPadrao");
            if (btnAplicar) btnAplicar.addEventListener("click", () => {
                const emp = document.getElementById("dpEmpresaPadrao").value;
                const plano = document.getElementById("dpPlanoPadrao").value;
                document.querySelectorAll(".dp-row-empresa").forEach(s => { if (emp) s.value = emp; });
                document.querySelectorAll(".dp-row-plano").forEach(s => { if (plano) s.value = plano; });
            });

            const btnMarcar = document.getElementById("dpMarcarTodos");
            if (btnMarcar) btnMarcar.addEventListener("click", () => {
                const todas = Array.from(document.querySelectorAll(".dp-row-chk"));
                const algumaDesmarcada = todas.some(c => !c.checked);
                todas.forEach(c => c.checked = algumaDesmarcada);
            });

            const btnLancar = document.getElementById("btnDpLancar");
            if (btnLancar) btnLancar.addEventListener("click", dpLancar);

            const btnConfirmarVincDp = document.getElementById("btnDpConfirmarVinculos");
            if (btnConfirmarVincDp) btnConfirmarVincDp.addEventListener("click",
                () => confirmarVinculosSugeridos("PAGAR"));
        });

        async function dpLancar() {
            const itensCriar = [];
            const itensVincular = [];
            document.querySelectorAll("#dpTbody tr").forEach(tr => {
                const chk = tr.querySelector(".dp-row-chk");
                if (!chk || !chk.checked) return;
                const idx = parseInt(tr.getAttribute("data-idx"), 10);
                const d = dpDebitos[idx];
                if (!d) return;
                const vincFk = (tr.querySelector(".dp-row-vinc-fk") || {}).value || "";
                if (vincFk) {
                    itensVincular.push({ movimento_fk: d.movimento_fk, lancamento_id: parseInt(vincFk, 10) });
                    return;
                }
                const fornFk = tr.querySelector(".dp-row-fornecedor-fk").value || 0;
                const empFk  = tr.querySelector(".dp-row-empresa").value || 0;
                const planoFk = tr.querySelector(".dp-row-plano").value || 0;
                itensCriar.push({
                    movimento_fk: d.movimento_fk,
                    fornecedor_fk: fornFk,
                    empresa_fk: empFk,
                    plano_contas_fk: planoFk,
                    descricao: d.descricao,
                    documento: d.documento,
                    vencimento: d.data
                });
            });

            if (!itensCriar.length && !itensVincular.length) {
                showToast("Selecione ao menos um débito.", "warning");
                return;
            }

            let okVinc = 0, errVinc = 0;
            for (const v of itensVincular) {
                const fd = new FormData();
                fd.append("acao", "vincular_lancamento_existente");
                fd.append("tipo", "PAGAR");
                fd.append("movimento_fk", v.movimento_fk);
                fd.append("lancamento_id", v.lancamento_id);
                let r = await apiPostForm(fd);
                if (!r.ok && r.needs_ajuste) r = await confirmarAjusteEReenviar(fd, r.ajuste);
                if (r.ok) okVinc++; else if (!r.cancelado) { errVinc++; console.warn("Falha vincular:", r); }
            }

            let okCri = 0;
            if (itensCriar.length) {
                const fd = new FormData();
                fd.append("acao", "criar_pagar_em_lote");
                fd.append("itens", JSON.stringify(itensCriar));
                const j = await apiPostForm(fd);
                if (!j.ok) { showToast(j.msg || "Erro ao lançar.", "danger"); return; }
                okCri = (j.criados || []).length;
            }

            const partes = [];
            if (okCri) partes.push(okCri + " criado(s)");
            if (okVinc) partes.push(okVinc + " vinculado(s)");
            if (errVinc) partes.push(errVinc + " falha(s) ao vincular");
            showToast(partes.join(" / ") || "Operação concluída.", errVinc ? "warning" : "success");

            if (bsDebitosPendentes) bsDebitosPendentes.hide();
            await carregarResumo();
            await carregarExtrato();
        }

        async function confirmarVinculosSugeridos(tipo) {
            try {
                console.log("[confirmarVinculosSugeridos] iniciado, tipo=", tipo);
                const fonte = tipo === "PAGAR" ? (dpDebitos || []) : (cpCreditos || []);
                console.log("[confirmarVinculosSugeridos] fonte length=", fonte.length, "amostra=", fonte.slice(0, 2));

                // Aceita match.id ou match.CPG_CODIGO_PK ou match.CRE_ID (defensivo)
                const sugeridos = fonte
                    .filter(d => {
                        if (!d.match) return false;
                        const id = d.match.id || d.match.CPG_CODIGO_PK || d.match.CRE_ID || d.match.lancamento_id;
                        return id && Number(id) > 0;
                    })
                    .map(d => ({
                        movimento_fk: d.movimento_fk,
                        tipo: tipo,
                        lancamento_id: Number(d.match.id || d.match.CPG_CODIGO_PK || d.match.CRE_ID || d.match.lancamento_id),
                    }));

                console.log("[confirmarVinculosSugeridos] sugeridos=", sugeridos);

                if (!sugeridos.length) {
                    showToast("Nenhum vínculo sugerido para confirmar. Confira o console (F12) para detalhes.", "warning");
                    return;
                }

                // Confirmação (com fallback se Swal não estiver disponível)
                let confirmou = false;
                if (typeof Swal !== "undefined" && Swal.fire) {
                    const r = await Swal.fire({
                        icon: "question",
                        title: `Confirmar ${sugeridos.length} vínculo(s)?`,
                        text: "Cada movimento será vinculado à conta sugerida; contas em aberto serão marcadas como PAGO/RECEBIDO automaticamente.",
                        showCancelButton: true,
                        confirmButtonText: "Sim, confirmar",
                        cancelButtonText: "Cancelar",
                    });
                    confirmou = !!r.isConfirmed;
                } else {
                    confirmou = window.confirm(`Confirmar ${sugeridos.length} vínculo(s)?`);
                }
                if (!confirmou) {
                    console.log("[confirmarVinculosSugeridos] usuário cancelou");
                    return;
                }

                console.log("[confirmarVinculosSugeridos] enviando POST...");
                const fd = new FormData();
                fd.append("acao", "confirmar_vinculos_sugeridos");
                fd.append("itens", JSON.stringify(sugeridos));
                let j = await apiPostForm(fd);
                console.log("[confirmarVinculosSugeridos] resposta=", j);

                if (j && !j.ok && j.needs_ajuste) {
                    const linhas = (j.ajustes || []).map(a =>
                        `• #${a.lancamento_id}: R$ ${money(a.de)} → <b>R$ ${money(a.para)}</b> (dif. R$ ${money(Math.abs(a.diff))})`
                    ).join('<br>');
                    const conf = await Swal.fire({
                        icon: 'question',
                        title: 'Ajustar valor da(s) parcela(s)?',
                        html: `Diferença de arredondamento (poucos centavos) detectada. Para fechar 100%, `
                            + `o valor da(s) parcela(s) será corrigido:<br><br>${linhas}<br><br>Deseja ajustar e concluir?`,
                        showCancelButton: true,
                        confirmButtonText: 'Sim, ajustar e conciliar',
                        cancelButtonText: 'Cancelar'
                    });
                    if (!conf.isConfirmed) return;
                    fd.append('ajustar_valor', '1');
                    j = await apiPostForm(fd);
                }

                if (!j || !j.ok) {
                    showToast((j && j.msg) || "Erro ao confirmar vínculos.", "danger");
                    return;
                }

                const totalErros = (j.erros || []).length;
                if (totalErros > 0) {
                    console.warn("[confirmarVinculosSugeridos] falhas:", j.erros);
                    Swal.fire({
                        icon: "warning",
                        title: `${j.sucessos} confirmado(s), ${totalErros} falha(s)`,
                        html: "<div class='text-start small' style='max-height:300px;overflow:auto'>" +
                              j.erros.map(e => "• " + escapeHtml(e)).join("<br>") + "</div>",
                    });
                } else {
                    Swal.fire({ icon: "success", title: "Pronto", text: j.msg, timer: 2200, showConfirmButton: false });
                }

                if (tipo === "PAGAR" && bsDebitosPendentes) bsDebitosPendentes.hide();
                if (tipo === "RECEBER" && bsCreditosPendentes) bsCreditosPendentes.hide();
                await carregarResumo();
                await carregarExtrato();
            } catch (err) {
                console.error("[confirmarVinculosSugeridos] erro inesperado:", err);
                showToast("Erro inesperado: " + (err.message || err), "danger");
            }
        }

        // ====== Vincular a lançamento existente (PAGAR/RECEBER) ======
        function bindVincularRow(tr, tipo, valor, bancoFk) {
            const inp = tr.querySelector(".dp-row-vinc-busca") || tr.querySelector(".cp-row-vinc-busca");
            const hid = tr.querySelector(".dp-row-vinc-fk")    || tr.querySelector(".cp-row-vinc-fk");
            const box = tr.querySelector(".dp-vinc-autocomplete") || tr.querySelector(".cp-vinc-autocomplete");
            const inf = tr.querySelector(".dp-row-vinc-info") || tr.querySelector(".cp-row-vinc-info");
            if (!inp || !hid || !box) return;
            let timer = null;
            inp.addEventListener("input", () => {
                hid.value = "";
                if (inf) { inf.classList.add("d-none"); inf.textContent = ""; }
                clearTimeout(timer);
                const q = inp.value.trim();
                if (q.length < 2) { box.classList.add("d-none"); return; }
                timer = setTimeout(async () => {
                    const j = await apiGet({
                        acao: "buscar_lancamento_existente",
                        tipo, q, valor, banco_fk: bancoFk || 0
                    });
                    if (!j.ok || !j.rows || !j.rows.length) {
                        box.innerHTML = '<div class="px-2 py-1 text-muted small">Nenhum lançamento encontrado.</div>';
                        box.classList.remove("d-none");
                        return;
                    }
                    box.innerHTML = j.rows.map(r => {
                        const nome = tipo === 'PAGAR'
                            ? (r.fornecedor_fantasia || r.fornecedor_razao || r.descricao || '-')
                            : (r.cliente_nome || r.descricao || '-');
                        const venc = r.vencimento ? formatDate(r.vencimento) : '-';
                        const valr = (r.valor_pago || r.valor_recebido || r.valor || 0);
                        return `
                            <div class="dp-vinc-item px-2 py-1 border-bottom" data-id="${r.id}"
                                 data-label="${escapeAttr(nome + ' · ' + (r.documento || ''))}"
                                 style="cursor:pointer">
                                <div><strong>${escapeHtml(nome)}</strong> <span class="text-muted">#${r.id}</span></div>
                                <div class="text-muted">venc ${venc} · R$ ${money(valr)}${r.documento ? ' · doc ' + escapeHtml(r.documento) : ''} · ${escapeHtml(r.status || '')}</div>
                            </div>
                        `;
                    }).join("");
                    box.classList.remove("d-none");
                    box.querySelectorAll(".dp-vinc-item").forEach(it => {
                        it.addEventListener("click", () => {
                            hid.value = it.getAttribute("data-id");
                            inp.value = it.getAttribute("data-label");
                            box.classList.add("d-none");
                            if (inf) {
                                inf.textContent = "Vincular a #" + hid.value;
                                inf.classList.remove("d-none");
                            }
                        });
                    });
                }, 300);
            });
            inp.addEventListener("blur", () => setTimeout(() => box.classList.add("d-none"), 200));
        }

        // ====== Modal: créditos pendentes após import OFX ======
        let bsCreditosPendentes = null;
        let cpCreditos = [];

        async function abrirModalCreditosPendentes(importacaoFk) {
            const j = await apiGet({ acao: "creditos_orfaos", importacao_fk: importacaoFk });
            if (!j.ok) {
                showToast(j.msg || "Erro ao buscar créditos pendentes.", "danger");
                return;
            }

            cpCreditos = j.creditos_orfaos || [];

            const txt = document.getElementById("cpResumoTxt");
            const totC = j.total_creditos || 0;
            const tmC  = j.total_match || 0;
            const toC  = j.total_orfaos || 0;
            if (!cpCreditos.length) {
                txt.innerHTML = `Nenhum crédito a revisar.`;
                document.getElementById("cpTbody").innerHTML = "";
                if (!bsCreditosPendentes) bsCreditosPendentes = new bootstrap.Modal(document.getElementById("modalCreditosPendentes"));
                bsCreditosPendentes.show();
                return;
            }

            txt.innerHTML = `<strong>${totC}</strong> crédito(s) no extrato — <span class="text-success fw-semibold">${tmC} já vinculado(s)</span> · <span class="text-danger fw-semibold">${toC} sem vínculo</span>.`;

            const bancoFkC = (cpCreditos[0] && cpCreditos[0].banco_fk) || 0;
            const datasC = cpCreditos.filter(c => !c.match).map(c => c.data);
            await Promise.all([
                cpCarregarEmpresas(),
                cpCarregarPlanoContas(),
                carregarLancamentosMes("RECEBER", datasC, bancoFkC).then(m => { cpLancMes = m; })
            ]);
            cpRenderTabela();

            if (!bsCreditosPendentes) bsCreditosPendentes = new bootstrap.Modal(document.getElementById("modalCreditosPendentes"));
            bsCreditosPendentes.show();
        }

        async function cpCarregarEmpresas() {
            const j = await apiGet({ acao: "combo_empresas_conc" });
            if (!j.ok) return;
            const opts = '<option value="">Selecione...</option>' + (j.rows || []).map(e => `<option value="${e.id}">${e.nome}</option>`).join('');
            document.getElementById("cpEmpresaPadrao").innerHTML = opts;
        }

        async function cpCarregarPlanoContas(empresaFk) {
            const params = { acao: "combo_plano_contas_conc" };
            if (empresaFk) params.empresa_fk = empresaFk;
            const j = await apiGet(params);
            if (!j.ok) return;
            const opts = '<option value="">Selecione...</option>' + (j.rows || []).map(p => `<option value="${p.id}">${p.nome}</option>`).join('');
            document.getElementById("cpPlanoPadrao").innerHTML = opts;
            document.querySelectorAll(".cp-row-plano").forEach(sel => {
                const cur = sel.value;
                sel.innerHTML = opts;
                if (cur) sel.value = cur;
            });
        }

        function cpRenderTabela() {
            const tb = document.getElementById("cpTbody");
            const planoOpts = document.getElementById("cpPlanoPadrao").innerHTML || '<option value="">Selecione...</option>';
            const empresaOpts = document.getElementById("cpEmpresaPadrao").innerHTML || '<option value="">Selecione...</option>';

            tb.innerHTML = cpCreditos.map((c, i) => {
                if (c.match) {
                    const m = c.match;
                    const vencBr = m.vencimento ? formatDate(m.vencimento) : '-';
                    const valr = (m.valor_recebido || m.valor || 0);
                    return `
                    <tr data-idx="${i}" data-matched="1" style="background:#e8f7ee">
                        <td><i class="bi bi-check-circle-fill text-success"></i></td>
                        <td class="text-nowrap small">${formatDate(c.data)}</td>
                        <td class="small">
                            <div class="fw-semibold">${escapeHtml(c.descricao || '-')}</div>
                            <div class="text-muted">${c.banco_nome ? escapeHtml(c.banco_nome) : ''}${c.documento ? ' · doc ' + escapeHtml(c.documento) : ''}</div>
                        </td>
                        <td class="text-end mono small">R$ ${money(c.valor)}</td>
                        <td colspan="4" class="small">
                            <div class="text-success fw-semibold">
                                <i class="bi bi-link-45deg me-1"></i>Vinculado a #${m.id}${lancParcelaBadge(m)} — ${escapeHtml(m.cliente || '-')}
                            </div>
                            <div class="text-muted">
                                Venc ${vencBr} · R$ ${money(valr)}${m.documento ? ' · doc ' + escapeHtml(m.documento) : ''} · ${escapeHtml(m.status || '')}
                            </div>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1 btn-cancelar-vinc"
                                    data-mov-fk="${c.movimento_fk}" data-lanc="#${m.id}">
                                <i class="bi bi-x-circle me-1"></i>Cancelar vínculo (vínculo errado)
                            </button>
                        </td>
                    </tr>`;
                }
                const mesC = (c.data || '').substring(0, 7);
                const lancListC = (cpLancMes[mesC] || []);
                const lancOptsC = '<option value="">— vincular a um lançamento do mês —</option>' +
                    lancListC.map(r => {
                        const lbl = lancOptionLabel("RECEBER", r);
                        return `<option value="${r.id}" data-search="${escapeAttr(pmNormalizar(lbl + ' #' + r.id))}">${escapeHtml(lbl)}</option>`;
                    }).join('');
                return `
                <tr data-idx="${i}" data-matched="0" style="background:#fdecec">
                    <td><input type="checkbox" class="cp-row-chk" checked></td>
                    <td class="text-nowrap small">${formatDate(c.data)}</td>
                    <td class="small">
                        <div class="fw-semibold">${escapeHtml(c.descricao || '-')}</div>
                        <div class="text-muted">${c.banco_nome ? escapeHtml(c.banco_nome) : ''}${c.documento ? ' · doc ' + escapeHtml(c.documento) : ''}</div>
                    </td>
                    <td class="text-end mono small">R$ ${money(c.valor)}</td>
                    <td>
                        <input type="text" class="form-control form-control-sm cp-row-vinc-busca mb-1" placeholder="🔍 Filtrar por nome, valor, #ID…" autocomplete="off">
                        <select class="form-select form-select-sm cp-row-vinc-fk" title="${lancListC.length} lançamento(s) sem vínculo (mês ${mesC} ± 1)">
                            ${lancOptsC}
                        </select>
                        <div class="cp-row-vinc-info small text-success mt-1 d-none"></div>
                        <button type="button" class="btn btn-sm btn-link p-0 mt-1 btn-mais-vinculos" data-tipo="RECEBER" data-mov-fk="${c.movimento_fk}" data-valor="${Math.abs(Number(c.valor||0))}" data-mes="${mesC}" data-data="${c.data || ''}">
                            <i class="bi bi-plus-square me-1"></i>Vincular múltiplos lançamentos
                        </button>
                    </td>
                    <td><select class="form-select form-select-sm cp-row-empresa">${empresaOpts}</select></td>
                    <td>
                        <div class="position-relative">
                            <input type="text" class="form-control form-control-sm cp-row-cliente-busca" placeholder="Digite p/ buscar..." autocomplete="off">
                            <input type="hidden" class="cp-row-cliente-fk">
                            <input type="hidden" class="cp-row-cliente-nome">
                            <div class="cp-cli-autocomplete bg-white border rounded shadow-sm position-absolute w-100 d-none" style="z-index:1080;max-height:180px;overflow:auto;font-size:12px"></div>
                        </div>
                    </td>
                    <td><select class="form-select form-select-sm cp-row-plano">${planoOpts}</select></td>
                </tr>`;
            }).join("");

            // autocomplete cliente por linha (apenas órfãs)
            tb.querySelectorAll('tr[data-matched="0"]').forEach(tr => {
                const inp = tr.querySelector(".cp-row-cliente-busca");
                const hid = tr.querySelector(".cp-row-cliente-fk");
                const hidNome = tr.querySelector(".cp-row-cliente-nome");
                const box = tr.querySelector(".cp-cli-autocomplete");
                if (!inp) return;
                let timer = null;
                inp.addEventListener("input", () => {
                    hid.value = "";
                    hidNome.value = inp.value;
                    clearTimeout(timer);
                    const q = inp.value.trim();
                    if (!q) { box.classList.add("d-none"); return; }
                    timer = setTimeout(async () => {
                        const j = await apiGet({ acao: "autocomplete_cliente_conc", q });
                        if (!j.ok || !j.rows || !j.rows.length) { box.classList.add("d-none"); return; }
                        box.innerHTML = j.rows.map(c => `
                            <div class="cp-cli-item px-2 py-1 border-bottom" data-id="${c.id}" data-label="${escapeAttr(c.nome || '')}" style="cursor:pointer">
                                <div><strong>${escapeHtml(c.nome || '')}</strong></div>
                                <div class="text-muted">${escapeHtml(c.cpf_cnpj || '')}</div>
                            </div>
                        `).join("");
                        box.classList.remove("d-none");
                        box.querySelectorAll(".cp-cli-item").forEach(it => {
                            it.addEventListener("click", () => {
                                hid.value = it.getAttribute("data-id");
                                inp.value = it.getAttribute("data-label");
                                hidNome.value = inp.value;
                                box.classList.add("d-none");
                            });
                        });
                    }, 250);
                });
                inp.addEventListener("blur", () => setTimeout(() => box.classList.add("d-none"), 200));
            });

            // change handler do select de vincular: mostra resumo da seleção
            tb.querySelectorAll('tr[data-matched="0"] .cp-row-vinc-fk').forEach(sel => {
                sel.addEventListener("change", () => {
                    const tr = sel.closest("tr");
                    const inf = tr.querySelector(".cp-row-vinc-info");
                    if (sel.value) {
                        const lbl = sel.options[sel.selectedIndex].text;
                        inf.textContent = "Vincular: " + lbl;
                        inf.classList.remove("d-none");
                    } else {
                        inf.classList.add("d-none");
                        inf.textContent = "";
                    }
                });
            });

            // busca/filtro do select de vincular: filtra options por nome, valor e #ID
            tb.querySelectorAll('tr[data-matched="0"]').forEach(tr => {
                const inpB = tr.querySelector('.cp-row-vinc-busca');
                const sel = tr.querySelector('.cp-row-vinc-fk');
                if (!inpB || !sel) return;
                let cpBuscaTimer = null;
                inpB.addEventListener('input', () => {
                    const termo = pmNormalizar(inpB.value);
                    sel.querySelectorAll('option[data-search]').forEach(opt => {
                        opt.hidden = !!termo && (opt.dataset.search || '').indexOf(termo) < 0;
                    });
                    const optSel = sel.options[sel.selectedIndex];
                    if (optSel && optSel.hidden) { sel.value = ''; sel.dispatchEvent(new Event('change')); }
                    clearTimeout(cpBuscaTimer);
                    cpBuscaTimer = setTimeout(() => inlineBuscaRemota('RECEBER', inpB, sel), 350);
                });
                // Enter seleciona a 1ª option visível
                inpB.addEventListener('keydown', (ev) => {
                    if (ev.key !== 'Enter') return;
                    ev.preventDefault();
                    const first = Array.from(sel.querySelectorAll('option[data-search]')).find(o => !o.hidden);
                    if (first) { sel.value = first.value; sel.dispatchEvent(new Event('change')); }
                });
            });
        }

        // Confirma com o usuário o ajuste de centavos (arredondamento) e reenvia o
        // vínculo autorizando a correção do valor da parcela (opção B).
        async function confirmarAjusteEReenviar(fd, a) {
            const conf = await Swal.fire({
                icon: 'question',
                title: 'Ajustar valor da parcela?',
                html: `O valor conciliado é <b>R$ ${money(a.para)}</b>, mas a parcela #${a.lancamento_id} `
                    + `está cadastrada como <b>R$ ${money(a.de)}</b> (diferença de `
                    + `<b>R$ ${money(Math.abs(a.diff))}</b>, provável arredondamento).<br><br>`
                    + `Deseja <b>corrigir a parcela para R$ ${money(a.para)}</b> e concluir a conciliação?`,
                showCancelButton: true,
                confirmButtonText: 'Sim, ajustar e conciliar',
                cancelButtonText: 'Cancelar'
            });
            if (!conf.isConfirmed) return { ok: false, cancelado: true };
            fd.append('ajustar_valor', '1');
            return await apiPostForm(fd);
        }

        async function cpLancar() {
            const itensCriar = [];
            const itensVincular = [];
            document.querySelectorAll("#cpTbody tr").forEach(tr => {
                const chk = tr.querySelector(".cp-row-chk");
                if (!chk || !chk.checked) return;
                const idx = parseInt(tr.getAttribute("data-idx"), 10);
                const c = cpCreditos[idx];
                if (!c) return;
                const vincFk = (tr.querySelector(".cp-row-vinc-fk") || {}).value || "";
                if (vincFk) {
                    itensVincular.push({ movimento_fk: c.movimento_fk, lancamento_id: parseInt(vincFk, 10) });
                    return;
                }
                const cliFk = tr.querySelector(".cp-row-cliente-fk").value || 0;
                const cliNome = tr.querySelector(".cp-row-cliente-nome").value || tr.querySelector(".cp-row-cliente-busca").value || '';
                const empFk = tr.querySelector(".cp-row-empresa").value || 0;
                const planoFk = tr.querySelector(".cp-row-plano").value || 0;
                itensCriar.push({
                    movimento_fk: c.movimento_fk,
                    cliente_fk: cliFk,
                    cliente_nome: cliNome,
                    empresa_fk: empFk,
                    plano_contas_fk: planoFk,
                    descricao: c.descricao,
                    documento: c.documento,
                    vencimento: c.data
                });
            });

            if (!itensCriar.length && !itensVincular.length) {
                showToast("Selecione ao menos um crédito.", "warning");
                return;
            }

            let okVinc = 0, errVinc = 0;
            for (const v of itensVincular) {
                const fd = new FormData();
                fd.append("acao", "vincular_lancamento_existente");
                fd.append("tipo", "RECEBER");
                fd.append("movimento_fk", v.movimento_fk);
                fd.append("lancamento_id", v.lancamento_id);
                let r = await apiPostForm(fd);
                if (!r.ok && r.needs_ajuste) r = await confirmarAjusteEReenviar(fd, r.ajuste);
                if (r.ok) okVinc++; else if (!r.cancelado) { errVinc++; console.warn("Falha vincular:", r); }
            }

            let okCri = 0;
            if (itensCriar.length) {
                const fd = new FormData();
                fd.append("acao", "criar_receber_em_lote");
                fd.append("itens", JSON.stringify(itensCriar));
                const j = await apiPostForm(fd);
                if (!j.ok) { showToast(j.msg || "Erro ao lançar.", "danger"); return; }
                okCri = (j.criados || []).length;
            }

            const partes = [];
            if (okCri) partes.push(okCri + " criado(s)");
            if (okVinc) partes.push(okVinc + " vinculado(s)");
            if (errVinc) partes.push(errVinc + " falha(s) ao vincular");
            showToast(partes.join(" / ") || "Operação concluída.", errVinc ? "warning" : "success");

            if (bsCreditosPendentes) bsCreditosPendentes.hide();
            await carregarResumo();
            await carregarExtrato();
        }

        document.addEventListener("DOMContentLoaded", () => {
            const chkAll = document.getElementById("cpChkAll");
            if (chkAll) chkAll.addEventListener("change", () => {
                document.querySelectorAll(".cp-row-chk").forEach(c => c.checked = chkAll.checked);
            });
            const empPadrao = document.getElementById("cpEmpresaPadrao");
            if (empPadrao) empPadrao.addEventListener("change", () => {
                if (empPadrao.value) cpCarregarPlanoContas(empPadrao.value);
            });
            const btnAplicar = document.getElementById("cpAplicarPadrao");
            if (btnAplicar) btnAplicar.addEventListener("click", () => {
                const emp = document.getElementById("cpEmpresaPadrao").value;
                const plano = document.getElementById("cpPlanoPadrao").value;
                document.querySelectorAll(".cp-row-empresa").forEach(s => { if (emp) s.value = emp; });
                document.querySelectorAll(".cp-row-plano").forEach(s => { if (plano) s.value = plano; });
            });
            const btnMarcar = document.getElementById("cpMarcarTodos");
            if (btnMarcar) btnMarcar.addEventListener("click", () => {
                const todas = Array.from(document.querySelectorAll(".cp-row-chk"));
                const algumaDesmarcada = todas.some(c => !c.checked);
                todas.forEach(c => c.checked = algumaDesmarcada);
            });
            const btnLancar = document.getElementById("btnCpLancar");
            if (btnLancar) btnLancar.addEventListener("click", cpLancar);

            const btnConfirmarVincCp = document.getElementById("btnCpConfirmarVinculos");
            if (btnConfirmarVincCp) btnConfirmarVincCp.addEventListener("click",
                () => confirmarVinculosSugeridos("RECEBER"));
        });

        async function exportarCSV() {
            const j = await apiGet({
                acao: "listar_extrato",
                banco_fk: document.getElementById("selBancoExtrato").value || "",
                busca: document.getElementById("txtExtratoBusca").value || "",
                status: document.getElementById("selStatusExtrato").value || ""
            });

            if (!j.ok || !j.movimentos || !j.movimentos.length) {
                showToast("Nenhum lançamento para exportar.", "warning");
                return;
            }

            const rows = [
                ["Data", "Banco", "Descrição", "Valor", "Saldo após", "Status"]
            ];
            j.movimentos.forEach(r => {
                rows.push([
                    r.data_br,
                    r.banco_nome,
                    r.descricao,
                    r.valor,
                    r.saldo_apos,
                    r.status
                ]);
            });

            const csv = rows.map(r => r.map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(";")).join("\n");
            const blob = new Blob([csv], {
                type: "text/csv;charset=utf-8;"
            });
            const a = document.createElement("a");
            a.href = URL.createObjectURL(blob);
            a.download = "extrato_conciliacao.csv";
            a.click();
        }

        document.addEventListener("DOMContentLoaded", async function() {
            bsAjuste = new bootstrap.Modal(document.getElementById("modalAjusteSaldo"));
            bsDetalhe = new bootstrap.Modal(document.getElementById("modalDetalheExtrato"));

            document.getElementById("tabOfxBtn").addEventListener("click", () => setTab("ofx"));
            document.getElementById("tabCnabBtn").addEventListener("click", () => setTab("cnab"));

            document.getElementById("selPeriodo").addEventListener("change", carregarResumo);
            document.getElementById("txtSaldoBusca").addEventListener("input", carregarResumo);
            document.getElementById("selConcStatus").addEventListener("change", carregarResumo);
            document.getElementById("saldoDtIni").addEventListener("change", carregarResumo);
            document.getElementById("saldoDtFim").addEventListener("change", carregarResumo);

            document.getElementById("btnLimparDtSaldo").addEventListener("click", () => {
                document.getElementById("saldoDtIni").value = "";
                document.getElementById("saldoDtFim").value = "";
                carregarResumo();
            });

            document.getElementById("selBancoOfx").addEventListener("change", async e => {
                await carregarContasBanco(e.target.value);
            });

            document.getElementById("txtExtratoBusca").addEventListener("input", () => carregarExtrato());
            document.getElementById("selBancoExtrato").addEventListener("change", () => carregarExtrato());
            document.getElementById("selStatusExtrato").addEventListener("change", () => carregarExtrato());

            document.getElementById("btnProcessarOfx").addEventListener("click", processarOfx);
            const btnRev = document.getElementById("btnRevisarConciliacao");
            if (btnRev) btnRev.addEventListener("click", revisarConciliacao);
            document.getElementById("btnExportCSV").addEventListener("click", exportarCSV);
            document.getElementById("btnConciliarLanc").addEventListener("click", conciliarLancamento);
            document.getElementById("btnAjusteSaldo").addEventListener("click", carregarModalAjuste);
            document.getElementById("btnSalvarAjuste").addEventListener("click", salvarAjuste);

            ["ajConta", "ajTipo", "ajOp", "ajValor"].forEach(id => {
                document.getElementById(id).addEventListener("change", atualizarPreviewAjuste);
                document.getElementById(id).addEventListener("input", atualizarPreviewAjuste);
            });

            document.getElementById("ajObs").addEventListener("input", function() {
                document.getElementById("ajObsCount").textContent = `${this.value.length} / 500`;
            });

            const bancos = await apiGet({
                acao: "combo_bancos"
            });
            if (!bancos.ok) {
                console.error("combo_bancos:", bancos);
                showToast(bancos.msg || "Erro ao carregar bancos.", "danger");
                return;
            }

            await carregarCombosBancos();
            await carregarResumo();
            await carregarExtrato();
        });

        // ====== Painel inline de vínculo múltiplo ======
        // Permite alocar 1 movimento OFX em N lançamentos (parcial/integral) na transação atômica.
        function abrirPainelMultiplo(btn) {
            const tr = btn.closest('tr');
            if (!tr) return;
            // Remove painel existente para esta linha
            const next = tr.nextElementSibling;
            if (next && next.classList.contains('painel-multi-row') && next.dataset.parent === tr.dataset.idx) {
                next.remove();
                return;
            }
            const tipo    = btn.dataset.tipo;
            const movFk   = parseInt(btn.dataset.movFk, 10);
            const valorMov = Number(btn.dataset.valor || 0);
            const mes     = btn.dataset.mes;
            const dataMov = String(btn.dataset.data || '').substring(0, 10);
            const lancListRaw = (tipo === 'PAGAR') ? (dpLancMes[mes] || []) : (cpLancMes[mes] || []);
            // Ordena por relevância em relação à data do movimento OFX:
            //   1º) recebido_em == data do mov (alta probabilidade de ser este pagamento)
            //   2º) vencimento  == data do mov
            //   3º) demais, por vencimento crescente
            const _d10 = (s) => String(s || '').substring(0, 10);
            const lancList = [...lancListRaw].sort((a, b) => {
                const aRec = _d10(a.data_recebimento) === dataMov ? 0 : 1;
                const bRec = _d10(b.data_recebimento) === dataMov ? 0 : 1;
                if (aRec !== bRec) return aRec - bRec;
                const aVc = _d10(a.vencimento) === dataMov ? 0 : 1;
                const bVc = _d10(b.vencimento) === dataMov ? 0 : 1;
                if (aVc !== bVc) return aVc - bVc;
                return _d10(a.vencimento).localeCompare(_d10(b.vencimento));
            });

            const colspan = tr.children.length;
            const row = document.createElement('tr');
            row.className = 'painel-multi-row';
            row.dataset.parent = tr.dataset.idx;
            row.innerHTML = `
                <td colspan="${colspan}" style="background:#fff8e6;border-top:2px solid #f59e0b;padding:14px">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="fw-semibold text-warning-emphasis">
                            <i class="bi bi-diagram-3 me-1"></i>Alocação múltipla — ${tipo === 'PAGAR' ? 'débito' : 'crédito'} <span class="mono">R$ ${money(valorMov)}</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-link text-danger pm-fechar p-0"><i class="bi bi-x-lg"></i> Fechar</button>
                    </div>

                    <!-- Bloco 1: Busca + select -->
                    <div class="card mb-2" style="border:1px solid #fcd34d">
                        <div class="card-body p-2">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="small fw-semibold mb-1">Buscar lançamento</label>
                                    <input type="text" class="form-control form-control-sm pm-busca" placeholder="🔍 Filtrar por nome, valor, #ID, doc, parcela…">
                                </div>
                                <div class="col-12">
                                    <select class="form-select form-select-sm pm-lanc" size="1">
                                        <option value="">— selecione um lançamento —</option>
                                        ${(() => {
                                            const pend = lancList.filter(r => !lancJaQuitada(tipo, r));
                                            const quit = lancList.filter(r =>  lancJaQuitada(tipo, r));
                                            const renderOpt = (r) => {
                                                const label = lancOptionLabel(tipo, r);
                                                const desc = (tipo==='PAGAR' ? (r.fornecedor || r.descricao || '') : (r.cliente || r.descricao || ''));
                                                return `<option value="${r.id}"
                                                    data-saldo="${lancSaldoRestante(tipo, r)}"
                                                    data-total="${Number(r.valor || 0)}"
                                                    data-quitada="${lancJaQuitada(tipo, r) ? '1' : '0'}"
                                                    data-num-parcela="${r.num_parcela || ''}"
                                                    data-qtd-parcelas="${r.qtd_parcelas || ''}"
                                                    data-desc="${escapeAttr(desc)}"
                                                    data-search="${escapeAttr(pmNormalizar(label))}"
                                                >${escapeHtml(label)}</option>`;
                                            };
                                            let out = '';
                                            if (pend.length) out += `<optgroup label="Em aberto / parcial (${pend.length})">${pend.map(renderOpt).join('')}</optgroup>`;
                                            if (quit.length) out += `<optgroup label="Já pagos — apenas vincular OFX (${quit.length})">${quit.map(renderOpt).join('')}</optgroup>`;
                                            return out;
                                        })()}
                                    </select>
                                    <div class="pm-busca-info small text-muted mt-1" style="font-size:11px"></div>
                                </div>
                                <div class="col-12 pm-info-aloc-wrap d-none">
                                    <div class="pm-info-aloc alert py-1 px-2 mb-0" style="font-size:12px"></div>
                                </div>
                                <div class="col-md-3">
                                    <label class="small fw-semibold mb-1">Tipo de alocação</label>
                                    <select class="form-select form-select-sm pm-tipo-aloc">
                                        <option value="INTEGRAL">Integral</option>
                                        <option value="PARCIAL">Parcial</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="small fw-semibold mb-1">Valor a alocar</label>
                                    <input type="text" class="form-control form-control-sm mono pm-valor" placeholder="0,00" disabled>
                                </div>
                                <div class="col-md-6 d-flex align-items-end justify-content-end">
                                    <button type="button" class="btn btn-sm btn-success pm-add" disabled>
                                        <i class="bi bi-plus-circle me-1"></i>Adicionar à lista
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bloco 2: Tabela das alocações já adicionadas -->
                    <div class="card mb-2">
                        <div class="card-header py-1 px-2 small fw-semibold bg-light">
                            <i class="bi bi-list-check me-1"></i>Alocações adicionadas
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Lançamento</th>
                                        <th style="width:110px">Tipo</th>
                                        <th class="text-end" style="width:140px">Alocado</th>
                                        <th style="width:50px"></th>
                                    </tr>
                                </thead>
                                <tbody class="pm-lista">
                                    <tr><td colspan="4" class="text-center text-muted small py-3">Nenhuma alocação ainda. Selecione lançamentos acima e clique em <strong>Adicionar à lista</strong>.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Bloco 3: Totais + confirmar -->
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-1">
                        <div class="d-flex gap-3 flex-wrap small">
                            <span class="text-muted">Movimento OFX: <strong class="mono text-dark">R$ ${money(valorMov)}</strong></span>
                            <span class="text-muted">Total alocado: <strong class="pm-total mono text-success">R$ 0,00</strong></span>
                            <span class="text-muted">Restante a alocar: <strong class="pm-restante mono text-danger">R$ ${money(valorMov)}</strong></span>
                        </div>
                        <button type="button" class="btn btn-primary pm-confirmar" disabled>
                            <i class="bi bi-check2-circle me-1"></i>Confirmar conciliação
                        </button>
                    </div>
                </td>
            `;
            tr.parentNode.insertBefore(row, tr.nextSibling);

            // Estado local
            const estado = { alocacoes: [], restante: valorMov, valorMov, tipo, movFk };
            row._pmEstado = estado;

            const selLanc = row.querySelector('.pm-lanc');
            const inpBusca = row.querySelector('.pm-busca');
            const infoBusca = row.querySelector('.pm-busca-info');
            const selTipoAloc = row.querySelector('.pm-tipo-aloc');
            const inpValor = row.querySelector('.pm-valor');
            const btnAdd = row.querySelector('.pm-add');
            const btnConfirmar = row.querySelector('.pm-confirmar');

            // Filtro do dropdown: oculta options cujo data-search não bate com o termo digitado.
            // Optgroup sem nenhum option visível também é ocultado.
            const totalOpts = Array.from(selLanc.querySelectorAll('option[data-search]')).length;
            function filtrarLancs() {
                const termo = pmNormalizar((inpBusca && inpBusca.value) || '');
                let visiveis = 0;
                selLanc.querySelectorAll('option[data-search]').forEach(opt => {
                    const bate = !termo || (opt.dataset.search || '').indexOf(termo) >= 0;
                    opt.hidden = !bate;
                    if (bate) visiveis++;
                });
                selLanc.querySelectorAll('optgroup').forEach(g => {
                    const algumVisivel = Array.from(g.querySelectorAll('option')).some(o => !o.hidden);
                    g.hidden = !algumVisivel;
                });
                // Se a opção selecionada foi escondida, limpa seleção e refaz UI dependente.
                const optSel = selLanc.options[selLanc.selectedIndex];
                if (optSel && optSel.hidden) {
                    selLanc.value = '';
                    atualizarValorAuto();
                }
                if (infoBusca) {
                    if (!termo) {
                        infoBusca.textContent = totalOpts ? (totalOpts + ' lançamento(s) disponível(is)') : '';
                    } else {
                        infoBusca.textContent = visiveis + ' de ' + totalOpts + ' lançamento(s)';
                    }
                }
            }
            if (inpBusca) {
                // Fallback no servidor: busca lançamentos de QUALQUER mês por texto/#ID
                // e injeta como options num grupo separado (resolve conciliar contra
                // lançamento fora do mês do movimento, ex.: PIX de junho quitando #2950 de fev).
                let buscaTimer = null;
                async function buscaRemota() {
                    const termo = (inpBusca.value || '').trim();
                    if (termo.length < 2) return;
                    const rows = await buscarLancServidor(tipo, termo, valorMov, 0);
                    if (!rows.length) return;
                    const novos = rows.filter(r =>
                        !selLanc.querySelector('option[value="' + r.id + '"]') &&
                        !estado.alocacoes.some(a => a.lancamento_id === Number(r.id))
                    );
                    if (novos.length) {
                        let grp = selLanc.querySelector('optgroup[data-remote="1"]');
                        if (!grp) {
                            grp = document.createElement('optgroup');
                            grp.label = '🔎 Encontrados em outros meses';
                            grp.setAttribute('data-remote', '1');
                            selLanc.appendChild(grp);
                        }
                        novos.forEach(r => grp.insertAdjacentHTML('beforeend', pmBuildOption(tipo, r)));
                    }
                    filtrarLancs();
                }
                inpBusca.addEventListener('input', () => {
                    filtrarLancs();
                    clearTimeout(buscaTimer);
                    buscaTimer = setTimeout(buscaRemota, 350);
                });
                // Atalho: Enter no campo de busca seleciona a 1ª option visível.
                inpBusca.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        const first = Array.from(selLanc.querySelectorAll('option[data-search]')).find(o => !o.hidden);
                        if (first) {
                            selLanc.value = first.value;
                            atualizarValorAuto();
                        }
                    }
                });
            }
            filtrarLancs(); // inicializa contagem

            function atualizarBtnAdd() {
                const okSel = !!selLanc.value;
                const v = parseValorBR(inpValor.value);
                const okVal = (selTipoAloc.value === 'INTEGRAL') || (v > 0 && v <= estado.restante + 0.005);
                btnAdd.disabled = !(okSel && okVal && estado.restante > 0.005);
            }
            function atualizarValorAuto() {
                const opt = selLanc.options[selLanc.selectedIndex];
                const quitada = !!(opt && opt.dataset.quitada === '1');
                const saldo = Number((opt && opt.dataset.saldo) || 0);
                const total = Number((opt && opt.dataset.total) || 0);

                if (quitada) {
                    selTipoAloc.value = 'INTEGRAL';
                    selTipoAloc.disabled = true;
                    inpValor.value = total > 0 ? formatValorBR(total) : '';
                    inpValor.disabled = true;
                    inpValor.title = 'Conta já está paga — vincular OFX usa o valor total';
                } else if (selTipoAloc.value === 'INTEGRAL') {
                    selTipoAloc.disabled = false;
                    const v = Math.min(saldo, estado.restante);
                    inpValor.value = (v > 0) ? formatValorBR(v) : '';
                    inpValor.disabled = true;
                    inpValor.title = '';
                } else {
                    selTipoAloc.disabled = false;
                    inpValor.disabled = false;
                    inpValor.title = '';
                }
                // Info visual sobre estado da conta selecionada
                const infoAloc = row.querySelector('.pm-info-aloc');
                const infoWrap = row.querySelector('.pm-info-aloc-wrap');
                if (infoAloc && infoWrap) {
                    if (!opt || !opt.value) {
                        infoWrap.classList.add('d-none');
                        infoAloc.innerHTML = '';
                    } else if (quitada) {
                        infoWrap.classList.remove('d-none');
                        infoAloc.className = 'pm-info-aloc alert alert-secondary py-1 px-2 mb-0';
                        infoAloc.style.fontSize = '12px';
                        infoAloc.innerHTML = `<i class="bi bi-check-circle text-success me-1"></i>Já paga (R$ ${money(total)}) — esta operação só vincula o OFX, sem nova baixa.`;
                    } else if (saldo > 0.005 && saldo < total - 0.005) {
                        const jaPg = Math.max(0, total - saldo);
                        infoWrap.classList.remove('d-none');
                        infoAloc.className = 'pm-info-aloc alert alert-warning py-1 px-2 mb-0';
                        infoAloc.style.fontSize = '12px';
                        infoAloc.innerHTML = `<i class="bi bi-hourglass-split me-1"></i>Conta parcial: já recebido R$ ${money(jaPg)} de R$ ${money(total)}. Saldo a receber: <strong>R$ ${money(saldo)}</strong>. Será completado nesta conciliação.`;
                    } else {
                        infoWrap.classList.remove('d-none');
                        infoAloc.className = 'pm-info-aloc alert alert-primary py-1 px-2 mb-0';
                        infoAloc.style.fontSize = '12px';
                        infoAloc.innerHTML = `<i class="bi bi-cash-coin me-1"></i>Conta em aberto (R$ ${money(total)}) — será <strong>baixada</strong> e vinculada ao OFX nesta operação.`;
                    }
                }
                atualizarBtnAdd();
            }
            function renderLista() {
                const tb = row.querySelector('.pm-lista');
                if (!estado.alocacoes.length) {
                    tb.innerHTML = `<tr><td colspan="4" class="text-muted small">Nenhuma alocação ainda.</td></tr>`;
                } else {
                    tb.innerHTML = estado.alocacoes.map((a, i) => `
                        <tr>
                            <td class="small">#${a.lancamento_id}${lancParcelaBadge(a)} — ${escapeHtml(a.descricao || '')}${a.ja_paga ? ' <span class="badge bg-info-subtle text-info ms-1">já paga</span>' : ''}</td>
                            <td><span class="badge ${a.tipo_alocacao === 'PARCIAL' ? 'bg-warning text-dark' : 'bg-secondary'}">${a.tipo_alocacao}</span></td>
                            <td class="text-end mono">R$ ${money(a.valor_alocado)}</td>
                            <td><button type="button" class="btn btn-sm btn-link text-danger pm-remover" data-idx="${i}"><i class="bi bi-trash"></i></button></td>
                        </tr>
                    `).join('');
                }

                // Derivar restante da soma das alocações (fonte da verdade).
                const totalAlocado = estado.alocacoes.reduce((acc, a) => acc + Number(a.valor_alocado || 0), 0);
                estado.restante = Math.max(0, estado.valorMov - totalAlocado);

                const elTotal    = row.querySelector('.pm-total');
                const elRestante = row.querySelector('.pm-restante');
                if (elTotal)    elTotal.textContent    = 'R$ ' + money(totalAlocado);
                if (elRestante) elRestante.textContent = 'R$ ' + money(estado.restante);

                if (elRestante) {
                    if (Math.abs(estado.restante) < 0.005) {
                        elRestante.classList.remove('text-danger');
                        elRestante.classList.add('text-success');
                    } else {
                        elRestante.classList.remove('text-success');
                        elRestante.classList.add('text-danger');
                    }
                }

                btnConfirmar.disabled = !(Math.abs(estado.restante) < 0.005 && estado.alocacoes.length > 0);
            }

            selLanc.addEventListener('change', atualizarValorAuto);
            selTipoAloc.addEventListener('change', atualizarValorAuto);
            inpValor.addEventListener('input', atualizarBtnAdd);

            btnAdd.addEventListener('click', () => {
                const opt = selLanc.options[selLanc.selectedIndex];
                if (!opt || !selLanc.value) return;
                const tipoAloc = selTipoAloc.value;
                const lancId = parseInt(selLanc.value, 10);
                const quitada = opt.dataset.quitada === '1';
                const total = Number(opt.dataset.total || 0);
                let valor;
                if (quitada) {
                    valor = total;
                } else if (tipoAloc === 'INTEGRAL') {
                    const saldo = Number(opt.dataset.saldo || 0);
                    valor = Math.min(saldo, estado.restante);
                } else {
                    valor = parseValorBR(inpValor.value);
                }
                if (!(valor > 0)) { showToast('Valor inválido.', 'warning'); return; }
                if (valor > estado.restante + 0.005) { showToast('Valor excede o restante.', 'warning'); return; }
                if (estado.alocacoes.some(a => a.lancamento_id === lancId)) {
                    showToast('Lançamento já adicionado.', 'warning'); return;
                }
                estado.alocacoes.push({
                    lancamento_id: lancId,
                    tipo: estado.tipo,
                    valor_alocado: valor,
                    tipo_alocacao: quitada ? 'INTEGRAL' : tipoAloc,
                    descricao: opt.dataset.desc || '',
                    ja_paga: quitada,
                    num_parcela: opt.dataset.numParcela || null,
                    qtd_parcelas: opt.dataset.qtdParcelas || null,
                });
                // remove a opção da lista para evitar repetição
                opt.remove();
                selLanc.value = '';
                inpValor.value = '';
                inpValor.disabled = true;
                selTipoAloc.disabled = false;
                btnAdd.disabled = true;
                renderLista();
            });

            row.querySelector('.pm-lista').addEventListener('click', (ev) => {
                const b = ev.target.closest('.pm-remover');
                if (!b) return;
                const idx = parseInt(b.dataset.idx, 10);
                const removida = estado.alocacoes.splice(idx, 1)[0];
                // recoloca a opção no select
                const r = lancList.find(x => x.id === removida.lancamento_id);
                if (r) {
                    const opt = document.createElement('option');
                    opt.value = r.id;
                    opt.dataset.saldo = lancSaldoRestante(estado.tipo, r);
                    opt.dataset.total = Number(r.valor || 0);
                    opt.dataset.quitada = lancJaQuitada(estado.tipo, r) ? '1' : '0';
                    opt.dataset.numParcela = r.num_parcela || '';
                    opt.dataset.qtdParcelas = r.qtd_parcelas || '';
                    opt.dataset.desc = (estado.tipo === 'PAGAR') ? (r.fornecedor || r.descricao || '') : (r.cliente || r.descricao || '');
                    opt.textContent = lancOptionLabel(estado.tipo, r);
                    selLanc.appendChild(opt);
                }
                renderLista();
            });

            btnConfirmar.addEventListener('click', async () => {
                if (Math.abs(estado.restante) > 0.005) {
                    showToast('Restante deve ser zero antes de confirmar.', 'warning');
                    return;
                }

                // Antes de gravar, mostra o que vai acontecer com cada conta para o usuário aprovar.
                // Separa em 2 grupos: contas que sofrerão BAIXA (valor recebido será atualizado)
                // e contas JÁ PAGAS que apenas serão VINCULADAS ao OFX (sem mexer em valor).
                const baixas = estado.alocacoes.filter(a => !a.ja_paga);
                const vincs  = estado.alocacoes.filter(a =>  a.ja_paga);
                const moneyBR = (v) => Number(v||0).toLocaleString('pt-BR', { minimumFractionDigits:2, maximumFractionDigits:2 });
                const sumBaixas = baixas.reduce((s,a) => s + Number(a.valor_alocado||0), 0);
                const sumVincs  = vincs .reduce((s,a) => s + Number(a.valor_alocado||0), 0);

                let html = '<div class="text-start small">';
                if (baixas.length) {
                    html += `<div class="alert alert-warning py-1 px-2 mb-2"><strong>${baixas.length} conta(s) será(ão) BAIXADA(S):</strong><br>`;
                    html += baixas.map(a => `&nbsp;• #${a.lancamento_id} ${a.descricao ? '— ' + a.descricao : ''} <span class="mono">R$ ${moneyBR(a.valor_alocado)}</span> <span class="badge bg-light text-dark">${a.tipo_alocacao}</span>`).join('<br>');
                    html += `<br><strong>Total baixado: R$ ${moneyBR(sumBaixas)}</strong></div>`;
                }
                if (vincs.length) {
                    html += `<div class="alert alert-secondary py-1 px-2 mb-2"><strong>${vincs.length} conta(s) JÁ PAGA(S) — apenas vincular OFX:</strong><br>`;
                    html += vincs.map(a => `&nbsp;• #${a.lancamento_id} ${a.descricao ? '— ' + a.descricao : ''} <span class="mono">R$ ${moneyBR(a.valor_alocado)}</span>`).join('<br>');
                    html += `<br><strong>Total já pago: R$ ${moneyBR(sumVincs)}</strong></div>`;
                }
                html += `<div class="text-muted">Movimento OFX: R$ ${moneyBR(estado.valorMov)}</div></div>`;

                const r = await Swal.fire({
                    icon: 'question',
                    title: 'Confirmar conciliação?',
                    html,
                    showCancelButton: true,
                    confirmButtonText: 'Sim, confirmar',
                    cancelButtonText: 'Voltar',
                    width: 600
                });
                if (!r.isConfirmed) return;

                btnConfirmar.disabled = true;
                const fd = new FormData();
                fd.append('acao', 'vincular_lancamentos_em_lote');
                fd.append('movimento_fk', estado.movFk);
                fd.append('itens', JSON.stringify(estado.alocacoes));
                let j = await apiPostForm(fd);
                if (!j.ok && j.needs_ajuste) {
                    const linhas = (j.ajustes || []).map(a =>
                        `• #${a.lancamento_id}: R$ ${money(a.de)} → <b>R$ ${money(a.para)}</b> (dif. R$ ${money(Math.abs(a.diff))})`
                    ).join('<br>');
                    const conf = await Swal.fire({
                        icon: 'question',
                        title: 'Ajustar valor da(s) parcela(s)?',
                        html: `Diferença de arredondamento (poucos centavos) detectada. Para fechar 100%, `
                            + `o valor da(s) parcela(s) será corrigido:<br><br>${linhas}<br><br>Deseja ajustar e concluir?`,
                        showCancelButton: true,
                        confirmButtonText: 'Sim, ajustar e conciliar',
                        cancelButtonText: 'Cancelar'
                    });
                    if (!conf.isConfirmed) { btnConfirmar.disabled = false; return; }
                    fd.append('ajustar_valor', '1');
                    j = await apiPostForm(fd);
                }
                if (!j.ok) {
                    showToast(j.msg || 'Erro.', 'danger');
                    btnConfirmar.disabled = false;
                    return;
                }
                showToast(j.msg || 'Conciliação confirmada.', 'success');
                // Esconde modal de revisão atual e recarrega
                if (estado.tipo === 'PAGAR' && bsDebitosPendentes) bsDebitosPendentes.hide();
                if (estado.tipo === 'RECEBER' && bsCreditosPendentes) bsCreditosPendentes.hide();
                await carregarResumo();
                await carregarExtrato();
            });

            row.querySelector('.pm-fechar').addEventListener('click', () => row.remove());
        }

        // Normaliza string para busca: lowercase, sem acentos, sem espaços duplicados.
        function pmNormalizar(s) {
            return String(s || '')
                .toLowerCase()
                .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
                .replace(/\s+/g, ' ')
                .trim();
        }

        function parseValorBR(s) {
            if (typeof s !== 'string') return Number(s) || 0;
            const t = s.replace(/\./g, '').replace(',', '.').replace(/[^\d.\-]/g, '');
            const n = parseFloat(t);
            return isNaN(n) ? 0 : n;
        }
        function formatValorBR(n) {
            return Number(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Delegação de eventos para o botão "+ múltiplo"
        document.addEventListener('click', (ev) => {
            const b = ev.target.closest('.btn-mais-vinculos');
            if (b) abrirPainelMultiplo(b);
        });

        // Cancelar vínculo individual
        document.addEventListener('click', async (ev) => {
            const btn = ev.target.closest('.btn-cancelar-vinculo');
            if (!btn) return;
            const r = await Swal.fire({
                icon: 'warning',
                title: 'Cancelar este vínculo?',
                text: 'A conta voltará ao status anterior. Pode reaplicar depois.',
                showCancelButton: true,
                confirmButtonText: 'Sim, cancelar',
                cancelButtonText: 'Voltar'
            });
            if (!r.isConfirmed) return;

            const fd = new FormData();
            fd.append('acao', 'cancelar_vinculo');
            if (btn.dataset.vin) {
                fd.append('vinculo_id', btn.dataset.vin);
            } else {
                fd.append('legacy_movimento_fk', btn.dataset.movLegacy);
                fd.append('legacy_tipo', btn.dataset.tipo);
            }
            const j = await apiPostForm(fd);
            if (!j.ok) { showToast(j.msg || 'Erro.', 'danger'); return; }
            showToast(j.msg || 'Vínculo cancelado.', 'success');
            if (bsDetalhe) bsDetalhe.hide();
            await carregarResumo();
            await carregarExtrato();
        });

        // ====== Botão "Revisar vínculos OFX" (Briefing 11) ======
        document.getElementById('btnRevisarVinculosOfx')?.addEventListener('click', async () => {
            const bancoFk = parseInt(document.getElementById('selBancoOfx')?.value || '0', 10);
            if (!bancoFk) { showToast('Selecione um banco antes.', 'warning'); return; }

            const tb = document.getElementById('rvTbody');
            tb.innerHTML = '<tr><td colspan="11" class="text-center text-muted small">Carregando…</td></tr>';
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRevisarVinculos'));
            modal.show();

            const j = await apiGet({ acao: 'resumo_importacoes_banco', banco_fk: bancoFk });
            if (!j.ok) {
                tb.innerHTML = `<tr><td colspan="11" class="text-center text-danger small">${escapeHtml(j.msg || 'Erro')}</td></tr>`;
                return;
            }
            if (!j.rows || !j.rows.length) {
                tb.innerHTML = '<tr><td colspan="11" class="text-center text-muted small">Nenhuma importação para este banco.</td></tr>';
                return;
            }

            tb.innerHTML = j.rows.map(r => {
                const periodo = (r.data_ini && r.data_fim && r.data_ini !== '0000-00-00')
                    ? `${formatDateBR(r.data_ini)} → ${formatDateBR(r.data_fim)}` : '—';
                const podeRevisarD = parseInt(r.qtd_debitos_pendentes, 10) > 0;
                const podeRevisarC = parseInt(r.qtd_creditos_pendentes, 10) > 0;
                return `
                    <tr>
                        <td class="small">#${r.imp_id}</td>
                        <td class="small text-truncate" style="max-width:220px" title="${escapeAttr(r.arquivo || '')}">${escapeHtml(r.arquivo || '—')}</td>
                        <td class="small">${periodo}</td>
                        <td class="text-end mono small">R$ ${money(r.saldo_final || 0)}</td>
                        <td class="text-end small">${r.qtd_total}</td>
                        <td class="text-end small text-success">${r.qtd_conciliados}</td>
                        <td class="text-end small ${podeRevisarD ? 'text-warning fw-bold' : 'text-muted'}">${r.qtd_debitos_pendentes}</td>
                        <td class="text-end small ${podeRevisarC ? 'text-warning fw-bold' : 'text-muted'}">${r.qtd_creditos_pendentes}</td>
                        <td class="text-end small text-muted" title="Internos resolvidos automaticamente (transferência, aplicação/resgate, tarifa, rendimento)">${r.qtd_internos ?? 0}</td>
                        <td class="small text-muted">${r.data_cadastro ? formatDateBR(r.data_cadastro) : '—'}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-danger" data-rv-deb="${r.imp_id}" ${podeRevisarD ? '' : 'disabled'} title="Revisar débitos pendentes">
                                <i class="bi bi-arrow-up-right"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-success" data-rv-cred="${r.imp_id}" ${podeRevisarC ? '' : 'disabled'} title="Revisar créditos pendentes">
                                <i class="bi bi-arrow-down-right"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        });

        // Delegação dos cliques nos botões "Revisar débitos/créditos"
        document.body.addEventListener('click', async (ev) => {
            const bD = ev.target.closest('[data-rv-deb]');
            if (bD && !bD.disabled) {
                const impFk = parseInt(bD.dataset.rvDeb, 10);
                bootstrap.Modal.getInstance(document.getElementById('modalRevisarVinculos'))?.hide();
                window.lastImportacaoFk = impFk;
                await abrirModalDebitosPendentes(impFk);
                return;
            }
            const bC = ev.target.closest('[data-rv-cred]');
            if (bC && !bC.disabled) {
                const impFk = parseInt(bC.dataset.rvCred, 10);
                bootstrap.Modal.getInstance(document.getElementById('modalRevisarVinculos'))?.hide();
                window.lastImportacaoFk = impFk;
                await abrirModalCreditosPendentes(impFk);
                return;
            }

            // Cancelar um vínculo errado direto na tela de débitos/créditos pendentes.
            const bCanc = ev.target.closest('.btn-cancelar-vinc');
            if (bCanc) {
                const movFk = parseInt(bCanc.dataset.movFk, 10);
                const lanc  = bCanc.dataset.lanc || '';
                if (!movFk) return;
                const conf = await Swal.fire({
                    icon: 'warning',
                    title: 'Cancelar este vínculo?',
                    html: `O vínculo com o lançamento <b>${lanc}</b> será desfeito: a baixa é revertida `
                        + `e o movimento volta para <b>pendente</b> para você vincular ao correto.<br><br>Confirmar?`,
                    showCancelButton: true,
                    confirmButtonText: 'Sim, cancelar vínculo',
                    cancelButtonText: 'Voltar',
                    confirmButtonColor: '#dc3545'
                });
                if (!conf.isConfirmed) return;
                const fd = new FormData();
                fd.append('acao', 'cancelar_integracao');
                fd.append('movimento_fk', movFk);
                const j = await apiPostForm(fd);
                if (!j || !j.ok) { showToast((j && j.msg) || 'Falha ao cancelar vínculo.', 'danger'); return; }
                showToast('Vínculo cancelado. Movimento voltou para pendente.', 'success');
                // Recarrega o modal aberto (débitos ou créditos) e os totais.
                const noDebito = !!ev.target.closest('#dpTbody');
                const impFk = window.lastImportacaoFk || 0;
                if (noDebito) await abrirModalDebitosPendentes(impFk);
                else          await abrirModalCreditosPendentes(impFk);
                await carregarResumo();
                await carregarExtrato();
                return;
            }
        });

        // ====== Botão "Histórico OFX" — listar e excluir importações ======
        async function carregarHistoricoOfx() {
            const bancoFk = parseInt(document.getElementById('selBancoOfx')?.value || '0', 10);
            const tb = document.getElementById('histOfxBody');
            const msgEl = document.getElementById('histOfxMensagem');
            tb.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-3">Carregando…</td></tr>';
            if (msgEl) msgEl.textContent = bancoFk > 0
                ? 'Mostrando importações do banco selecionado.'
                : 'Mostrando todas as importações (todos os bancos).';

            try {
                const params = new URLSearchParams({ acao: 'listar_importacoes_ofx', limit: '200' });
                if (bancoFk > 0) params.set('banco_fk', String(bancoFk));
                const r = await fetch(endpoint + '?' + params.toString(), { credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) {
                    tb.innerHTML = '<tr><td colspan="11" class="text-center text-danger small py-3">Erro: ' + (j.msg || 'falha ao carregar') + '</td></tr>';
                    return;
                }
                const rows = j.rows || [];
                if (!rows.length) {
                    tb.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-3">Nenhuma importação encontrada.</td></tr>';
                    return;
                }
                const brl = v => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                const fmtDt = s => { if (!s) return '—'; const d = new Date(String(s).replace(' ', 'T')); return d.toLocaleString('pt-BR'); };

                tb.innerHTML = rows.map(r => {
                    const temVinculo = (Number(r.qtd_conciliados) > 0)
                                    || (Number(r.qtd_vinculos_ativos) > 0)
                                    || (Number(r.qtd_avulsos_criados) > 0);
                    const corBadge = temVinculo ? 'bg-warning text-dark' : 'bg-light text-secondary border';
                    const txtBadge = temVinculo ? 'Com vínculos' : 'Sem vínculos';
                    return `
                        <tr>
                            <td class="small">${fmtDt(r.importado_em)}</td>
                            <td class="small">${esc(r.usuario || '—')}</td>
                            <td class="small">${esc(r.banco_nome || '—')}</td>
                            <td class="small" style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.arquivo)}">${esc(r.arquivo || '—')}</td>
                            <td class="small text-end font-monospace">${brl(r.entradas)}</td>
                            <td class="small text-end font-monospace">${brl(r.saidas)}</td>
                            <td class="small text-center">${Number(r.qtd_movimentos)}</td>
                            <td class="small text-center">${Number(r.qtd_conciliados)}</td>
                            <td class="small text-center">${Number(r.qtd_vinculos_ativos)}</td>
                            <td class="small text-center">${Number(r.qtd_avulsos_criados)}</td>
                            <td class="text-end">
                                <span class="badge ${corBadge} me-1">${txtBadge}</span>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="excluirImportacaoOfx(${Number(r.id)}, ${temVinculo ? 1 : 0})"
                                        title="Excluir esta importação e reverter o que foi feito">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>`;
                }).join('');
            } catch (err) {
                tb.innerHTML = '<tr><td colspan="11" class="text-center text-danger small py-3">Erro de rede: ' + String(err.message || err) + '</td></tr>';
            }
        }

        window.excluirImportacaoOfx = async function(importId, temVinculo) {
            const aviso = temVinculo
                ? `<div class="alert alert-warning small text-start mb-2">
                       <i class="bi bi-exclamation-triangle me-1"></i>
                       Esta importação <strong>tem vínculos ativos</strong>. Ao excluir, todos os vínculos
                       serão cancelados, as parcelas voltarão ao status anterior e os avulsos
                       criados pelo OFX serão excluídos.
                   </div>`
                : `<div class="alert alert-light border small text-start mb-2">
                       <i class="bi bi-info-circle me-1"></i>
                       Esta importação <strong>não tem vínculos ativos</strong>. Será apenas removida
                       do sistema (movimentos e cabeçalho).
                   </div>`;

            // O modal Bootstrap "modalHistoricoOfx" usa enforceFocus, que rouba o foco do
            // SweetAlert por cima — bloqueando a digitação na senha. Fix: renderizar o
            // SweetAlert DENTRO do próprio modal Bootstrap (mesmo contexto de foco).
            const { value: formValues, isConfirmed } = await Swal.fire({
                title: 'Excluir importação OFX?',
                icon: 'warning',
                target: document.getElementById('modalHistoricoOfx'),
                heightAuto: false,
                html: `
                    ${aviso}
                    <div class="text-start small">
                        <label class="form-label small fw-bold mt-2">Motivo (opcional)</label>
                        <input id="swal-hist-motivo" class="form-control form-control-sm" placeholder="Ex.: arquivo OFX errado, datas erradas, vou reimportar correto">
                        <label class="form-label small fw-bold mt-2">Senha de um ADMIN <span class="text-danger">*</span></label>
                        <input id="swal-hist-senha" type="password" class="form-control form-control-sm" placeholder="Senha de qualquer usuário ADMIN" autocomplete="off">
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Excluir',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc2626',
                focusConfirm: false,
                didOpen: () => {
                    // Garante foco no campo senha assim que abre
                    setTimeout(() => {
                        const inp = document.getElementById('swal-hist-senha');
                        if (inp) inp.focus();
                    }, 50);
                },
                preConfirm: () => {
                    const senha = document.getElementById('swal-hist-senha').value.trim();
                    const motivo = document.getElementById('swal-hist-motivo').value.trim();
                    if (!senha) { Swal.showValidationMessage('Senha obrigatória.'); return false; }
                    return { senha, motivo };
                }
            });

            if (!isConfirmed || !formValues) return;

            try {
                const fd = new FormData();
                fd.append('acao', 'excluir_importacao_ofx');
                fd.append('importacao_id', String(importId));
                fd.append('senha', formValues.senha);
                fd.append('motivo', formValues.motivo);
                const r = await fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) {
                    Swal.fire({ icon: 'error', title: 'Não foi possível excluir', text: j.msg || 'Erro desconhecido' });
                    return;
                }
                await Swal.fire({
                    icon: 'success',
                    title: 'Importação excluída',
                    html: `<div class="small text-start">${(j.msg || 'Concluído').replace(/\n/g,'<br>')}<br><br><b>Autorizado por:</b> ${(j.autorizado_por||'—')}</div>`,
                    confirmButtonText: 'OK'
                });
                await carregarHistoricoOfx();
                // Atualiza tela principal de conciliação se a função estiver disponível
                if (typeof carregarMovimentos === 'function') { try { await carregarMovimentos(); } catch(e) {} }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Erro', text: String(err.message || err) });
            }
        };

        document.getElementById('btnHistoricoOfx')?.addEventListener('click', () => {
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHistoricoOfx'));
            modal.show();
            carregarHistoricoOfx();
        });

        // ====== Botão "Transferências internas" + 3 abas ======
        async function carregarParesTransferencia() {
            const tb = document.getElementById('tiPares');
            tb.innerHTML = '<tr><td colspan="5" class="text-muted small text-center py-3">Carregando…</td></tr>';
            try {
                const r = await fetch(endpoint + '?acao=listar_transferencias_internas', { credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) {
                    tb.innerHTML = '<tr><td colspan="5" class="text-danger small text-center py-3">' + (j.msg || 'Erro') + '</td></tr>';
                    return;
                }
                const rows = j.rows || [];
                if (!rows.length) {
                    tb.innerHTML = '<tr><td colspan="5" class="text-muted small text-center py-3">Nenhum par detectado ainda. Clique em "Rodar detecção agora".</td></tr>';
                    return;
                }
                const brl = v => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                tb.innerHTML = rows.map(r => `
                    <tr>
                        <td class="small">
                            <div class="fw-semibold">${esc(r.origem_banco || '—')}</div>
                            <div class="text-muted" style="font-size:.72rem">${esc(r.origem_data)} • ${esc((r.origem_desc || '').slice(0, 60))}</div>
                        </td>
                        <td class="small">
                            <div class="fw-semibold">${esc(r.destino_banco || '—')}</div>
                            <div class="text-muted" style="font-size:.72rem">${esc(r.destino_data)} • ${esc((r.destino_desc || '').slice(0, 60))}</div>
                        </td>
                        <td class="text-end mono fw-semibold">${brl(r.valor)}</td>
                        <td><span class="badge ${r.modo === 'MANUAL' ? 'bg-info-subtle text-info' : 'bg-success-subtle text-success'}">${esc(r.modo)}</span></td>
                        <td class="small text-muted">${esc(r.data_deteccao)}</td>
                    </tr>
                `).join('');
            } catch (err) {
                tb.innerHTML = '<tr><td colspan="5" class="text-danger small text-center py-3">Erro de rede</td></tr>';
            }
        }

        async function carregarSemPar() {
            const tb = document.getElementById('tiSemPar');
            tb.innerHTML = '<tr><td colspan="6" class="text-muted small text-center py-3">Carregando…</td></tr>';
            try {
                const r = await fetch(endpoint + '?acao=listar_transferencias_sem_par', { credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) {
                    tb.innerHTML = '<tr><td colspan="6" class="text-danger small text-center py-3">' + (j.msg || 'Erro') + '</td></tr>';
                    return;
                }
                const rows = j.rows || [];
                if (!rows.length) {
                    tb.innerHTML = '<tr><td colspan="6" class="text-muted small text-center py-3">Tudo casado. Sem movimentos órfãos.</td></tr>';
                    return;
                }
                const brl = v => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                tb.innerHTML = rows.map(r => `
                    <tr>
                        <td class="small">${esc(r.banco || '—')}</td>
                        <td class="small">${esc(r.data)}</td>
                        <td class="small">${esc((r.descricao || '').slice(0, 70))}</td>
                        <td><span class="badge ${r.tipo === 'DEBITO' ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'}">${esc(r.tipo)}</span></td>
                        <td class="text-end mono">${brl(Math.abs(r.valor))}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-secondary" onclick="desmarcarTransferencia(${Number(r.id)})" title="Marcar como NORMAL (sai da lista de transferências internas)">
                                <i class="bi bi-x-circle"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            } catch (err) {
                tb.innerHTML = '<tr><td colspan="6" class="text-danger small text-center py-3">Erro de rede</td></tr>';
            }
        }

        async function carregarDocsGrupo() {
            const tb = document.getElementById('tiDocs');
            tb.innerHTML = '<tr><td colspan="4" class="text-muted small text-center py-3">Carregando…</td></tr>';
            try {
                const r = await fetch('endpoints/grupo_documentos.php?acao=listar', { credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) {
                    tb.innerHTML = '<tr><td colspan="4" class="text-danger small text-center py-3">' + (j.msg || 'Erro') + '</td></tr>';
                    return;
                }
                const rows = j.rows || [];
                if (!rows.length) {
                    tb.innerHTML = '<tr><td colspan="4" class="text-muted small text-center py-3">Nenhum documento cadastrado.</td></tr>';
                    return;
                }
                const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                const fmtDoc = (d, t) => {
                    const x = String(d).replace(/\D/g, '');
                    if (t === 'PJ' && x.length === 14) return x.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
                    if (t === 'PF' && x.length === 11) return x.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
                    return d;
                };
                tb.innerHTML = rows.map(r => `
                    <tr ${r.GDO_STATUS === 'INATIVO' ? 'class="text-muted"' : ''}>
                        <td><span class="badge ${r.GDO_TIPO === 'PJ' ? 'bg-primary-subtle text-primary' : 'bg-warning-subtle text-warning-emphasis'}">${esc(r.GDO_TIPO)}</span></td>
                        <td class="mono small">${esc(fmtDoc(r.GDO_DOCUMENTO, r.GDO_TIPO))}</td>
                        <td class="small">${esc(r.GDO_NOME)}</td>
                        <td>${r.GDO_STATUS === 'ATIVO'
                            ? '<span class="badge bg-success-subtle text-success">Ativo</span>'
                            : '<span class="badge bg-secondary-subtle text-secondary">Inativo</span>'}</td>
                    </tr>
                `).join('');
            } catch (err) {
                tb.innerHTML = '<tr><td colspan="4" class="text-danger small text-center py-3">Erro de rede</td></tr>';
            }
        }

        window.desmarcarTransferencia = async function(movId) {
            const conf = await Swal.fire({
                title: 'Desmarcar transferência interna?',
                text: 'Este movimento volta a ser NORMAL e poderá ser conciliado como conta a pagar/receber normalmente.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sim, desmarcar',
                cancelButtonText: 'Cancelar',
            });
            if (!conf.isConfirmed) return;
            try {
                const fd = new FormData();
                fd.append('acao', 'alterar_natureza_movimento');
                fd.append('movimento_fk', String(movId));
                fd.append('natureza', 'NORMAL');
                const r = await fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) throw new Error(j.msg || 'Erro');
                await carregarSemPar();
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Erro', text: err.message });
            }
        };

        document.getElementById('btnRodarDeteccao')?.addEventListener('click', async () => {
            const btn = document.getElementById('btnRodarDeteccao');
            btn.disabled = true;
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Detectando…';
            try {
                const fd = new FormData();
                fd.append('acao', 'detectar_pares_transferencia');
                const r = await fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) throw new Error(j.msg || 'Erro');
                Swal.fire({
                    icon: 'success',
                    title: 'Detecção concluída',
                    text: `${j.pares_criados || 0} novo(s) par(es) detectado(s).`,
                    timer: 2200,
                    showConfirmButton: false
                });
                await carregarParesTransferencia();
                await carregarSemPar();
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Erro', text: err.message });
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        });

        document.getElementById('btnTransferenciasInternas')?.addEventListener('click', () => {
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTransferenciasInternas'));
            modal.show();
            carregarParesTransferencia();
            carregarSemPar();
            carregarDocsGrupo();
        });

        // ====== Botão "Conferir vínculos" (Briefing 11) ======
        document.getElementById('btnConferirVinculos')?.addEventListener('click', async () => {
            const bancoFk = parseInt(document.getElementById('selBancoOfx')?.value || '0', 10);
            if (!bancoFk) { showToast('Selecione um banco antes.', 'warning'); return; }

            const tb = document.getElementById('cvTbody');
            tb.innerHTML = '<tr><td colspan="8" class="text-center text-muted small">Carregando…</td></tr>';
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalConferirVinculos'));
            modal.show();

            const j = await apiGet({ acao: 'listar_vinculos_ativos_banco', banco_fk: bancoFk });
            if (!j.ok) {
                tb.innerHTML = `<tr><td colspan="8" class="text-center text-danger small">${escapeHtml(j.msg || 'Erro')}</td></tr>`;
                return;
            }
            if (!j.rows || !j.rows.length) {
                tb.innerHTML = '<tr><td colspan="8" class="text-center text-muted small">Nenhum vínculo ativo para este banco.</td></tr>';
                return;
            }

            tb.innerHTML = j.rows.map(r => {
                const movDescricao = `${formatDateBR(r.mov_data)} · ${escapeHtml((r.mov_descricao || '').substring(0, 60))}`;
                const lancDescricao = `#${r.lanc_fk}${lancParcelaBadge(r)} · ${escapeHtml(r.lanc_descricao || '')}`;
                const tipoBadge = r.tipo === 'CONTA_PAGAR'
                    ? '<span class="badge bg-danger-subtle text-danger">Pagar</span>'
                    : '<span class="badge bg-success-subtle text-success">Receber</span>';
                const origemBadge = r.origem === 'NOVO'
                    ? '<span class="badge bg-primary-subtle text-primary">Novo</span>'
                    : '<span class="badge bg-secondary-subtle text-secondary">Legado 1:1</span>';
                const tipoAlocBadge = r.tipo_alocacao === 'PARCIAL'
                    ? '<span class="badge bg-warning-subtle text-warning-emphasis">Parcial</span>'
                    : '<span class="badge bg-light text-dark">Integral</span>';

                const tipoCurto = r.tipo === 'CONTA_PAGAR' ? 'PAGAR' : 'RECEBER';
                const dataAttrCancelar = r.vin_id
                    ? `data-vin="${r.vin_id}"`
                    : `data-mov-legacy="${r.mov_fk}" data-tipo="${tipoCurto}"`;

                return `
                    <tr>
                        <td class="small">${movDescricao}<br><span class="text-muted mono">R$ ${money(Math.abs(r.mov_valor || 0))}</span></td>
                        <td class="small">${lancDescricao}<br><span class="text-muted mono">total: R$ ${money(r.lanc_valor || 0)}</span></td>
                        <td>${tipoBadge}</td>
                        <td class="text-end mono small">R$ ${money(r.valor_alocado || 0)}</td>
                        <td>${tipoAlocBadge}</td>
                        <td>${origemBadge}</td>
                        <td class="small text-muted">${r.data_vinc ? formatDateBR(r.data_vinc) : '—'}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-danger btn-cancelar-vinculo" ${dataAttrCancelar} title="Cancelar este vínculo">
                                <i class="bi bi-x-circle"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-warning btn-cancelar-integracao-cv" data-mov="${r.mov_fk}" title="Cancelar integração inteira do movimento">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        });

        // Cancelar integração inteira a partir do modal "Conferir vínculos"
        document.body.addEventListener('click', async (ev) => {
            const btn = ev.target.closest('.btn-cancelar-integracao-cv');
            if (!btn) return;
            const r = await Swal.fire({
                icon: 'warning',
                title: 'Cancelar integração inteira?',
                text: 'Todos os vínculos deste movimento serão desfeitos. O movimento volta para Importado.',
                showCancelButton: true,
                confirmButtonText: 'Sim, cancelar tudo',
                cancelButtonText: 'Voltar'
            });
            if (!r.isConfirmed) return;

            const fd = new FormData();
            fd.append('acao', 'cancelar_integracao');
            fd.append('movimento_fk', btn.dataset.mov);
            const j = await apiPostForm(fd);
            if (!j.ok) { showToast(j.msg || 'Erro.', 'danger'); return; }
            showToast(j.msg || 'Integração cancelada.', 'success');
            bootstrap.Modal.getInstance(document.getElementById('modalConferirVinculos'))?.hide();
            await carregarResumo();
            await carregarExtrato();
        });

        // Cancelar integração inteira
        document.addEventListener('click', async (ev) => {
            const btn = ev.target.closest('#btnCancelarIntegracao');
            if (!btn) return;
            const r = await Swal.fire({
                icon: 'warning',
                title: 'Cancelar integração inteira?',
                text: 'Todos os vínculos deste movimento serão desfeitos. Movimento volta para Importado.',
                showCancelButton: true,
                confirmButtonText: 'Sim, cancelar tudo',
                cancelButtonText: 'Voltar'
            });
            if (!r.isConfirmed) return;

            const fd = new FormData();
            fd.append('acao', 'cancelar_integracao');
            fd.append('movimento_fk', btn.dataset.mov);
            const j = await apiPostForm(fd);
            if (!j.ok) { showToast(j.msg || 'Erro.', 'danger'); return; }
            showToast(j.msg || 'Integração cancelada.', 'success');
            if (bsDetalhe) bsDetalhe.hide();
            await carregarResumo();
            await carregarExtrato();
        });
    </script>
  <script src="assets/session_keeper.js" defer></script>
</body>

</html>