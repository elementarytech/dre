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
    <title>DRE - BI Financeiro</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --bi-bg: #f4f6f9;
            --bi-surface: #fff;
            --bi-border: #e5e7eb;
            --bi-text: #0f172a;
            --bi-muted: #64748b;
            --bi-blue: #2563eb;
            --bi-green: #16a34a;
            --bi-red: #dc2626;
            --bi-amber: #d97706;
            --bi-purple: #7c3aed;
            --bi-cyan: #0891b2;
            --radius: .875rem;
        }

        body[data-page="bi"] { background: var(--bi-bg); }

        /* ---- Topbar ---- */
        .bi-topbar {
            background: #fff;
            border-bottom: 1px solid var(--bi-border);
            padding: 10px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 20;
        }

        .bi-brand {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--bi-text);
        }
        .bi-brand span { color: var(--bi-blue); }

        .bi-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* ---- Empresa selector ---- */
        .emp-selector {
            display: flex;
            flex-wrap: wrap;
            border: 1px solid var(--bi-border);
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }

        .emp-btn {
            border: none;
            background: transparent;
            padding: 6px 14px;
            font-size: .78rem;
            font-weight: 600;
            color: var(--bi-muted);
            border-right: 1px solid var(--bi-border);
            cursor: pointer;
            transition: all .15s;
        }
        .emp-btn:last-child { border-right: none; }
        .emp-btn:hover { background: #f8fafc; }
        .emp-btn.active { background: var(--bi-blue); color: #fff; }

        /* ---- Tabs ---- */
        .bi-tabs {
            display: flex;
            gap: 0;
            padding: 0 18px;
            background: #fff;
            border-bottom: 1px solid var(--bi-border);
            overflow-x: auto;
        }

        .bi-tab {
            border: none;
            background: transparent;
            padding: 10px 16px;
            font-size: .82rem;
            font-weight: 600;
            color: var(--bi-muted);
            border-bottom: 2px solid transparent;
            white-space: nowrap;
            cursor: pointer;
            transition: all .15s;
        }
        .bi-tab:hover { color: var(--bi-text); }
        .bi-tab.active { color: var(--bi-blue); border-bottom-color: var(--bi-blue); }

        .bi-body { padding: 18px; }
        .bi-view { display: none; }
        .bi-view.active { display: block; }

        /* ---- KPI Grid ---- */
        .kpi-grid {
            display: flex;
            flex-wrap: nowrap;
            gap: 10px;
            margin-bottom: 16px;
        }

        .kpi {
            flex: 1 1 0;
            min-width: 0;
            background: #fff;
            border: 1px solid var(--bi-border);
            border-radius: var(--radius);
            padding: 14px 16px;
            position: relative;
            overflow: hidden;
            transition: box-shadow .2s, transform .2s;
        }
        .kpi:hover {
            box-shadow: 0 4px 16px rgba(15,23,42,.07);
            transform: translateY(-1px);
        }

        .kpi::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
        }
        .kpi.blue::before   { background: var(--bi-blue); }
        .kpi.green::before  { background: var(--bi-green); }
        .kpi.red::before    { background: var(--bi-red); }
        .kpi.purple::before { background: var(--bi-purple); }
        .kpi.amber::before  { background: var(--bi-amber); }
        .kpi.cyan::before   { background: var(--bi-cyan); }

        .kpi-lbl {
            font-size: .68rem;
            color: var(--bi-muted);
            font-weight: 600;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .kpi-val {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--bi-text);
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .kpi-sub {
            margin-top: 4px;
            font-size: .7rem;
            color: var(--bi-muted);
        }

        @media (max-width: 991.98px) {
            .kpi-grid { flex-wrap: wrap; }
            .kpi { flex: 1 1 calc(50% - 5px); min-width: calc(50% - 5px); }
        }
        @media (max-width: 575.98px) {
            .kpi { flex: 1 1 100%; }
        }

        /* ---- Grid layouts ---- */
        .grid-2 {
            display: grid;
            grid-template-columns: 1.8fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }
        .grid-2-eq {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }
        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        @media (max-width: 1100px) {
            .grid-2, .grid-2-eq, .grid-3 { grid-template-columns: 1fr; }
        }

        /* ---- Card ---- */
        .bi-card {
            background: #fff;
            border: 1px solid var(--bi-border);
            border-radius: var(--radius);
            padding: 16px;
            box-shadow: 0 1px 3px rgba(15,23,42,.04);
        }

        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 12px;
        }

        .card-title {
            font-size: .88rem;
            font-weight: 700;
            color: var(--bi-text);
        }

        .card-sub {
            font-size: .72rem;
            color: var(--bi-muted);
            margin-top: 1px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 600;
        }
        .pill-blue { background: #eff6ff; color: #1d4ed8; }
        .pill-green { background: #f0fdf4; color: #15803d; }
        .pill-red { background: #fef2f2; color: #b91c1c; }

        /* ---- Table ---- */
        .table-bi {
            width: 100%;
            border-collapse: collapse;
        }
        .table-bi th {
            text-align: left;
            padding: 8px 10px;
            font-size: .66rem;
            color: var(--bi-muted);
            text-transform: uppercase;
            letter-spacing: .05em;
            border-bottom: 1px solid var(--bi-border);
        }
        .table-bi td {
            padding: 9px 10px;
            border-bottom: 1px solid #f1f5f9;
            font-size: .82rem;
            color: var(--bi-text);
        }
        .table-bi tr:last-child td { border-bottom: none; }
        .table-bi tbody tr:hover { background: #f8fafc; }

        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 600;
        }
        .status-ativo, .status-recebido { background: #dcfce7; color: #166534; }
        .status-suspenso, .status-programado, .status-em_aberto { background: #fef3c7; color: #92400e; }
        .status-atraso, .status-encerrado, .status-cancelado { background: #fee2e2; color: #991b1b; }

        .empty-bi {
            padding: 28px 10px;
            text-align: center;
            color: var(--bi-muted);
            font-size: .85rem;
        }

        .placeholder-view {
            background: #fff;
            border: 1px dashed var(--bi-border);
            border-radius: var(--radius);
            padding: 40px 20px;
            text-align: center;
            color: var(--bi-muted);
        }

        canvas { max-width: 100%; }

        /* Periodo buttons */
        .bi-periodo-btn {
            font-size: .74rem !important;
            font-weight: 600;
            padding: .28rem .6rem;
            border-radius: 0;
        }
        .bi-periodo-btn.active {
            background: var(--bi-blue) !important;
            border-color: var(--bi-blue) !important;
            color: #fff !important;
        }
        .btn-group .bi-periodo-btn:first-child { border-radius: .5rem 0 0 .5rem; }
        .btn-group .bi-periodo-btn:last-child { border-radius: 0 .5rem .5rem 0; }

        /* ---- Mini list items ---- */
        .mini-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: .83rem;
        }
        .mini-item:last-child { border-bottom: none; }
        .mini-item-label { color: var(--bi-muted); }
        .mini-item-val { font-weight: 700; color: var(--bi-text); }

        /* ---- Alert items ---- */
        .alert-item {
            padding: 10px 12px;
            border-radius: 10px;
            margin-bottom: 6px;
            border: 1px solid #f1f5f9;
            transition: background .15s;
        }
        .alert-item:hover { background: #f8fafc; }
        .alert-item:last-child { margin-bottom: 0; }
        .alert-item .title { font-weight: 600; font-size: .82rem; color: var(--bi-text); }
        .alert-item .desc { font-size: .74rem; color: var(--bi-muted); margin-top: 2px; }

        /* ---- DRE Table ---- */
        .dre-row-receita { background: #f0fdf4; }
        .dre-row-despesa { }
        .dre-row-resultado { background: #f8fafc; font-weight: 700; }

        /* ---- Banco item ---- */
        .banco-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .banco-item:last-child { border-bottom: none; }
        .banco-nome { font-weight: 600; font-size: .84rem; color: var(--bi-text); }
        .banco-empresa { font-size: .72rem; color: var(--bi-muted); }
        .banco-saldo { font-weight: 700; font-size: .92rem; font-family: ui-monospace, monospace; }

        /* ---- Section header ---- */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .section-header h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--bi-text);
            margin: 0;
        }
        .section-header .subtitle {
            font-size: .78rem;
            color: var(--bi-muted);
        }
    </style>
</head>

<body data-page="bi">
    <div class="d-flex" id="wrapper">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">

            <!-- Topbar -->
            <div class="bi-topbar">
                <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-outline-secondary btn-sm" id="menu-toggle">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="bi-brand">SYNC<span>-BI</span> <small class="text-muted fw-normal" style="font-size:.78rem">Financeiro</small></div>
                </div>

                <div class="bi-actions">
                    <div class="d-flex align-items-center gap-1 flex-wrap">
                        <div class="btn-group btn-group-sm" id="btnsPeriodo">
                            <button type="button" class="btn btn-outline-secondary bi-periodo-btn" data-periodo="7D">7d</button>
                            <button type="button" class="btn btn-outline-secondary bi-periodo-btn" data-periodo="15D">15d</button>
                            <button type="button" class="btn btn-outline-secondary bi-periodo-btn active" data-periodo="30D">30d</button>
                            <button type="button" class="btn btn-outline-secondary bi-periodo-btn" data-periodo="TRIM">Trim</button>
                            <button type="button" class="btn btn-outline-secondary bi-periodo-btn" data-periodo="ANO">Ano</button>
                        </div>
                        <div class="d-flex align-items-center gap-1 ms-1">
                            <input type="date" class="form-control form-control-sm" id="biDataIni" style="width:130px;border-radius:.5rem;font-size:.76rem">
                            <span class="text-muted" style="font-size:.72rem">a</span>
                            <input type="date" class="form-control form-control-sm" id="biDataFim" style="width:130px;border-radius:.5rem;font-size:.76rem">
                            <button type="button" class="btn btn-sm btn-primary" id="btnFiltrarData" style="border-radius:.5rem;font-size:.74rem;padding:.28rem .6rem">
                                <i class="fa-solid fa-filter"></i>
                            </button>
                        </div>
                    </div>

                    <div class="emp-selector" id="empSelector"></div>

                    <span class="small text-muted" style="font-size:.78rem">
                        <i class="fa-solid fa-user me-1"></i>
                        <?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?>
                    </span>
                </div>
            </div>

            <!-- Tabs -->
            <div class="bi-tabs">
                <button class="bi-tab active" data-view="overview"><i class="fa-solid fa-gauge me-1"></i>Visão Geral</button>
                <button class="bi-tab" data-view="dre"><i class="fa-solid fa-chart-column me-1"></i>DRE</button>
                <button class="bi-tab" data-view="previsao"><i class="fa-solid fa-calendar-check me-1"></i>Previsão de Contas</button>
                <button class="bi-tab" data-view="bancos"><i class="fa-solid fa-building-columns me-1"></i>Bancos</button>
                <button class="bi-tab" data-view="contratos"><i class="fa-solid fa-file-contract me-1"></i>Contratos</button>
            </div>

            <!-- Body -->
            <div class="bi-body">

                <!-- ====== VISÃO GERAL ====== -->
                <div class="bi-view active" id="view-overview">
                    <div class="section-header">
                        <div>
                            <div class="subtitle">PAINEL EXECUTIVO</div>
                            <h2 id="ovEmpLabel">Todas as empresas</h2>
                        </div>
                        <div class="small text-muted" id="ovDateLabel"></div>
                    </div>

                    <div class="kpi-grid" id="ovKpis"></div>

                    <div class="grid-2">
                        <div class="bi-card">
                            <div class="card-head">
                                <div>
                                    <div class="card-title">Receita x Despesa x Resultado</div>
                                    <div class="card-sub">Evolução mensal (12 meses)</div>
                                </div>
                                <span class="pill pill-blue" id="pillResultado"></span>
                            </div>
                            <div style="height:280px"><canvas id="chartRecDesp"></canvas></div>
                        </div>

                        <div class="bi-card">
                            <div class="card-head">
                                <div>
                                    <div class="card-title">Resultado por empresa</div>
                                    <div class="card-sub">Período selecionado</div>
                                </div>
                            </div>
                            <div style="height:280px"><canvas id="chartEmpresas"></canvas></div>
                        </div>
                    </div>

                    <div class="grid-3">
                        <div class="bi-card">
                            <div class="card-head">
                                <div>
                                    <div class="card-title"><i class="fa-solid fa-building-columns me-1 text-muted"></i>Bancos</div>
                                    <div class="card-sub">Saldo atual por conta</div>
                                </div>
                            </div>
                            <div id="listaBancos"></div>
                        </div>

                        <div class="bi-card">
                            <div class="card-head">
                                <div>
                                    <div class="card-title"><i class="fa-solid fa-file-contract me-1 text-muted"></i>Contratos</div>
                                    <div class="card-sub">Resumo da carteira</div>
                                </div>
                            </div>
                            <div id="resumoContratos"></div>
                        </div>

                        <div class="bi-card">
                            <div class="card-head">
                                <div>
                                    <div class="card-title"><i class="fa-solid fa-bell me-1 text-muted"></i>Alertas</div>
                                    <div class="card-sub">Itens que pedem ação</div>
                                </div>
                            </div>
                            <div id="listaAlertas"></div>
                        </div>
                    </div>
                </div>

                <!-- ====== DRE ====== -->
                <div class="bi-view" id="view-dre">
                    <div class="section-header">
                        <div>
                            <div class="subtitle">DRE</div>
                            <h2>Demonstrativo de Resultado</h2>
                        </div>
                        <div class="small text-muted" id="dreDateLabel"></div>
                    </div>

                    <div class="kpi-grid" id="dreKpis"></div>

                    <div class="grid-2">
                        <div class="bi-card">
                            <div class="card-head">
                                <div>
                                    <div class="card-title">Receita x Despesa x Resultado</div>
                                    <div class="card-sub">Apuração mensal</div>
                                </div>
                            </div>
                            <div style="height:280px"><canvas id="chartDreMensal"></canvas></div>
                        </div>

                        <div class="bi-card">
                            <div class="card-head">
                                <div>
                                    <div class="card-title">Despesas por categoria</div>
                                    <div class="card-sub">Plano de contas</div>
                                </div>
                            </div>
                            <div style="height:280px"><canvas id="chartDreCategorias"></canvas></div>
                        </div>
                    </div>

                    <div class="bi-card">
                        <div class="card-head">
                            <div>
                                <div class="card-title">DRE Resumida</div>
                                <div class="card-sub">Receitas e despesas do período</div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table-bi">
                                <thead>
                                    <tr>
                                        <th>Grupo</th>
                                        <th>Descrição</th>
                                        <th class="text-end">Valor</th>
                                    </tr>
                                </thead>
                                <tbody id="tbDreResumo"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ====== PREVISÃO DE CONTAS ====== -->
                <div class="bi-view" id="view-previsao">
                    <div class="section-header">
                        <div>
                            <div class="subtitle">PREVISÃO DE CONTAS</div>
                            <h2 id="prevPeriodoLabel">Projeção de débitos e créditos</h2>
                        </div>
                    </div>

                    <div class="kpi-grid" id="prevKpis"></div>

                    <div class="bi-card">
                        <div class="card-head">
                            <div>
                                <div class="card-title">Projeção de Saldo</div>
                                <div class="card-sub">Saldo acumulado dia a dia no período previsto</div>
                            </div>
                        </div>
                        <div style="height:280px"><canvas id="chartPrevSaldo"></canvas></div>
                    </div>

                    <div class="bi-card">
                        <div class="card-head">
                            <div>
                                <div class="card-title">Contas Agendadas</div>
                                <div class="card-sub">Créditos a receber e débitos a pagar, por vencimento</div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table-bi">
                                <thead>
                                    <tr>
                                        <th>Vencimento</th>
                                        <th>Tipo</th>
                                        <th>Cliente / Fornecedor</th>
                                        <th>Descrição</th>
                                        <th>Plano de Contas</th>
                                        <th class="text-end">Valor</th>
                                        <th class="text-end">Saldo projetado</th>
                                    </tr>
                                </thead>
                                <tbody id="tbPrevisao"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ====== BANCOS ====== -->
                <div class="bi-view" id="view-bancos">
                    <div class="section-header">
                        <div>
                            <div class="subtitle">BANCOS</div>
                            <h2>Saldos Bancários</h2>
                        </div>
                    </div>
                    <div class="bi-card">
                        <div id="viewBancosTabela"></div>
                    </div>
                </div>

                <!-- ====== CONTRATOS ====== -->
                <div class="bi-view" id="view-contratos">
                    <div class="section-header">
                        <div>
                            <div class="subtitle">CONTRATOS</div>
                            <h2>Carteira de Contratos</h2>
                        </div>
                    </div>

                    <div class="kpi-grid" id="ctrKpis"></div>

                    <div class="grid-2-eq">
                        <div class="bi-card">
                            <div class="card-head">
                                <div>
                                    <div class="card-title">Contratos por Status</div>
                                    <div class="card-sub">Carteira atual</div>
                                </div>
                            </div>
                            <div style="height:280px"><canvas id="chartContratoStatus"></canvas></div>
                        </div>

                        <div class="bi-card">
                            <div class="card-head">
                                <div>
                                    <div class="card-title">Parcelas por Situação</div>
                                    <div class="card-sub">Distribuição das parcelas</div>
                                </div>
                            </div>
                            <div style="height:280px"><canvas id="chartParcelasStatus"></canvas></div>
                        </div>
                    </div>

                    <div class="bi-card">
                        <div class="card-head">
                            <div>
                                <div class="card-title">Lista de Contratos</div>
                                <div class="card-sub">Empresa, cliente, valor e próxima cobrança</div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table-bi">
                                <thead>
                                    <tr>
                                        <th>Nº</th>
                                        <th>Cliente</th>
                                        <th>Empresa</th>
                                        <th class="text-end">Valor</th>
                                        <th>Status</th>
                                        <th>Próxima cobrança</th>
                                    </tr>
                                </thead>
                                <tbody id="tbContratosBi"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
    const ENDPOINT = 'endpoints/bi.php';
    let EMPRESA_ATUAL = 'TODAS';
    let PERIODO_ATUAL = '30D';
    let DATA_INI_CUSTOM = '';
    let DATA_FIM_CUSTOM = '';
    let charts = {};

    // ---- Palette ---- //
    const COLORS = {
        blue:   '#2563eb', green:  '#16a34a', red:    '#dc2626',
        amber:  '#d97706', purple: '#7c3aed', cyan:   '#0891b2',
        blue10: 'rgba(37,99,235,.10)',  green10:'rgba(22,163,106,.10)',
        red10:  'rgba(220,38,38,.10)',  amber10:'rgba(217,119,6,.10)',
    };
    const CHART_COLORS = [COLORS.blue, COLORS.red, COLORS.green, COLORS.amber, COLORS.purple, COLORS.cyan];

    // ---- Utils ---- //
    function brl(v) {
        return Number(v || 0).toLocaleString('pt-BR', { style:'currency', currency:'BRL' });
    }
    function fmtNum(v) { return Number(v || 0).toLocaleString('pt-BR'); }

    function badgeStatus(s) {
        const cls = String(s || '').toLowerCase().replaceAll(' ', '_');
        return `<span class="status-badge status-${cls}">${s || '-'}</span>`;
    }

    async function api(params = {}) {
        const url = ENDPOINT + '?' + new URLSearchParams(params).toString();
        const r = await fetch(url);
        const txt = await r.text();
        let j;
        try { j = JSON.parse(txt); } catch(e) {
            console.error('Resposta inválida para', params.acao, ':', txt.substring(0, 500));
            throw new Error('Erro ao processar resposta de "' + (params.acao || '?') + '". Verifique o console (F12).');
        }
        if (!j.ok) throw new Error(j.msg || 'Erro ao carregar BI.');
        return j;
    }

    function destroyChart(key) { if (charts[key]) { charts[key].destroy(); charts[key] = null; } }

    function setView(view) {
        document.querySelectorAll('.bi-tab').forEach(el => el.classList.toggle('active', el.dataset.view === view));
        document.querySelectorAll('.bi-view').forEach(el => el.classList.toggle('active', el.id === 'view-' + view));
    }

    // ---- Chart defaults ---- //
    Chart.defaults.font.family = "'Poppins', system-ui, sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
    Chart.defaults.plugins.legend.labels.padding = 14;
    Chart.defaults.elements.bar.borderRadius = 4;
    Chart.defaults.elements.line.tension = 0.35;
    Chart.defaults.elements.point.radius = 3;

    const chartOpts = (extra = {}) => ({
        maintainAspectRatio: false,
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 11 } } },
            tooltip: {
                backgroundColor: '#1e293b',
                cornerRadius: 8,
                padding: 10,
                titleFont: { size: 12 },
                bodyFont: { size: 11 },
                callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + brl(ctx.raw) },
            }
        },
        scales: extra.scales || undefined,
        ...extra,
    });

    const barScales = {
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: { callback: v => brl(v), font: { size: 10 } }
            },
            x: { grid: { display: false }, ticks: { font: { size: 10 } } }
        }
    };

    // ---- Empresas ---- //
    function renderEmpresas(empresas) {
        const box = document.getElementById('empSelector');
        box.innerHTML = '';
        [{ EMP_ID: 'TODAS', EMP_NOME: 'Todas' }, ...empresas].forEach(emp => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'emp-btn' + (String(emp.EMP_ID) === String(EMPRESA_ATUAL) ? ' active' : '');
            btn.textContent = emp.EMP_NOME;
            btn.addEventListener('click', async () => {
                EMPRESA_ATUAL = String(emp.EMP_ID);
                renderEmpresas(empresas);
                await refresh();
            });
            box.appendChild(btn);
        });
    }

    // ---- OVERVIEW ---- //
    function renderOverview(data) {
        document.getElementById('ovEmpLabel').textContent = data.empresa_label || 'Todas as empresas';
        document.getElementById('ovDateLabel').textContent = data.periodo_label || '';

        const k = data.kpis;
        document.getElementById('ovKpis').innerHTML = `
            <div class="kpi blue">
                <div class="kpi-lbl">Receita Prevista</div>
                <div class="kpi-val">${brl(k.receita_prevista)}</div>
                <div class="kpi-sub">Contas a receber do período</div>
            </div>
            <div class="kpi green">
                <div class="kpi-lbl">Receita Recebida</div>
                <div class="kpi-val">${brl(k.receita_recebida)}</div>
                <div class="kpi-sub">Recebimentos efetivos</div>
            </div>
            <div class="kpi red">
                <div class="kpi-lbl">Despesa Prevista</div>
                <div class="kpi-val">${brl(k.despesa_prevista)}</div>
                <div class="kpi-sub">Contas a pagar do período</div>
            </div>
            <div class="kpi purple">
                <div class="kpi-lbl">Despesa Paga</div>
                <div class="kpi-val">${brl(k.despesa_paga)}</div>
                <div class="kpi-sub">Pagamentos efetivos</div>
            </div>
            <div class="kpi ${k.resultado >= 0 ? 'green' : 'red'}">
                <div class="kpi-lbl">Resultado</div>
                <div class="kpi-val">${brl(k.resultado)}</div>
                <div class="kpi-sub">Receita - despesa</div>
            </div>
        `;

        const pillEl = document.getElementById('pillResultado');
        pillEl.className = 'pill ' + (k.resultado >= 0 ? 'pill-green' : 'pill-red');
        pillEl.innerHTML = k.resultado >= 0
            ? '<i class="fa-solid fa-arrow-trend-up"></i> Positivo'
            : '<i class="fa-solid fa-arrow-trend-down"></i> Negativo';

        // Chart receita x despesa
        destroyChart('recDesp');
        const gm = data.grafico_mensal;
        charts.recDesp = new Chart(document.getElementById('chartRecDesp'), {
            type: 'line',
            data: {
                labels: gm.labels,
                datasets: [
                    { label: 'Receita', data: gm.receita, borderColor: COLORS.green, backgroundColor: COLORS.green10, fill: true },
                    { label: 'Despesa', data: gm.despesa, borderColor: COLORS.red, backgroundColor: COLORS.red10, fill: true },
                    { label: 'Resultado', data: gm.resultado, borderColor: COLORS.blue, backgroundColor: COLORS.blue10, fill: true, borderDash: [4,3] },
                ]
            },
            options: chartOpts(barScales),
        });

        // Chart empresas
        destroyChart('empresas');
        const re = data.resultado_empresas;
        const empColors = re.valores.map(v => v >= 0 ? COLORS.green : COLORS.red);
        charts.empresas = new Chart(document.getElementById('chartEmpresas'), {
            type: 'bar',
            data: {
                labels: re.labels,
                datasets: [{ label: 'Resultado', data: re.valores, backgroundColor: empColors, borderRadius: 6 }]
            },
            options: chartOpts({ ...barScales, plugins: { legend: { display: false } } }),
        });

        // Bancos
        const bancos = data.bancos || [];
        document.getElementById('listaBancos').innerHTML = bancos.length ? bancos.map(b => `
            <div class="banco-item">
                <div>
                    <div class="banco-nome">${b.BAN_APELIDO || b.BAN_NOME || 'Banco'}</div>
                    <div class="banco-empresa">${b.EMPRESA_NOME || '-'} &middot; ${b.FCB_DATA_BR || ''}</div>
                </div>
                <div class="banco-saldo">${brl(b.SALDO_ATUAL)}</div>
            </div>
        `).join('') : `<div class="empty-bi"><i class="fa-solid fa-building-columns me-2"></i>Nenhum saldo encontrado.</div>`;

        // Contratos
        const c = data.contratos;
        document.getElementById('resumoContratos').innerHTML = `
            <div class="mini-item"><span class="mini-item-label">Contratos ativos</span><span class="mini-item-val">${fmtNum(c.ativos)}</span></div>
            <div class="mini-item"><span class="mini-item-label">Valor mensal</span><span class="mini-item-val">${brl(c.valor_ativo)}</span></div>
            <div class="mini-item"><span class="mini-item-label">Parcelas em aberto</span><span class="mini-item-val">${fmtNum(c.parcelas_abertas)}</span></div>
            <div class="mini-item"><span class="mini-item-label">Parcelas em atraso</span><span class="mini-item-val" style="color:${COLORS.red}">${fmtNum(c.parcelas_atraso)}</span></div>
        `;

        // Alertas
        const alertas = data.alertas || [];
        document.getElementById('listaAlertas').innerHTML = alertas.length ? alertas.map(a => `
            <div class="alert-item">
                <div class="title"><i class="fa-solid fa-triangle-exclamation me-1" style="color:${COLORS.amber}"></i>${a.titulo}</div>
                <div class="desc">${a.descricao}</div>
            </div>
        `).join('') : `<div class="empty-bi"><i class="fa-solid fa-circle-check me-2" style="color:${COLORS.green}"></i>Nenhum alerta.</div>`;

        // Bancos aba
        document.getElementById('viewBancosTabela').innerHTML = bancos.length ? `
            <div class="table-responsive">
                <table class="table-bi">
                    <thead><tr><th>Banco</th><th>Empresa</th><th>Data base</th><th class="text-end">Saldo atual</th></tr></thead>
                    <tbody>${bancos.map(b => `
                        <tr>
                            <td><strong>${b.BAN_APELIDO || b.BAN_NOME || '-'}</strong></td>
                            <td>${b.EMPRESA_NOME || '-'}</td>
                            <td>${b.FCB_DATA_BR || '-'}</td>
                            <td class="text-end mono"><strong>${brl(b.SALDO_ATUAL)}</strong></td>
                        </tr>
                    `).join('')}</tbody>
                </table>
            </div>
        ` : `<div class="empty-bi"><i class="fa-solid fa-building-columns me-2"></i>Nenhum saldo encontrado.</div>`;
    }

    // ---- DRE ---- //
    function renderDRE(data) {
        document.getElementById('dreDateLabel').textContent = data.periodo_label || '';

        const k = data.kpis;
        const margemFmt = Number(k.margem || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
        document.getElementById('dreKpis').innerHTML = `
            <div class="kpi green">
                <div class="kpi-lbl">Receita Bruta</div>
                <div class="kpi-val">${brl(k.receita_bruta)}</div>
                <div class="kpi-sub">Recebimentos do período</div>
            </div>
            <div class="kpi red">
                <div class="kpi-lbl">Despesas</div>
                <div class="kpi-val">${brl(k.despesas)}</div>
                <div class="kpi-sub">Pagamentos do período</div>
            </div>
            <div class="kpi ${k.resultado_operacional >= 0 ? 'green' : 'red'}">
                <div class="kpi-lbl">Resultado Operacional</div>
                <div class="kpi-val">${brl(k.resultado_operacional)}</div>
                <div class="kpi-sub">Receita - despesas</div>
            </div>
            <div class="kpi blue">
                <div class="kpi-lbl">Margem</div>
                <div class="kpi-val">${margemFmt}%</div>
                <div class="kpi-sub">Resultado / receita</div>
            </div>
        `;

        // Chart mensal
        destroyChart('dreMensal');
        const m = data.mensal;
        charts.dreMensal = new Chart(document.getElementById('chartDreMensal'), {
            type: 'bar',
            data: {
                labels: m.labels,
                datasets: [
                    { label: 'Receita', data: m.receita, backgroundColor: COLORS.green, borderRadius: 4 },
                    { label: 'Despesa', data: m.despesa, backgroundColor: COLORS.red, borderRadius: 4 },
                    { label: 'Resultado', data: m.resultado, type: 'line', borderColor: COLORS.blue, backgroundColor: COLORS.blue10, fill: true, borderDash: [4,3] },
                ]
            },
            options: chartOpts(barScales),
        });

        // Chart categorias
        destroyChart('dreCategorias');
        const cat = data.categorias;
        charts.dreCategorias = new Chart(document.getElementById('chartDreCategorias'), {
            type: 'doughnut',
            data: {
                labels: cat.labels,
                datasets: [{ data: cat.valores, backgroundColor: CHART_COLORS, borderWidth: 0 }]
            },
            options: chartOpts({ cutout: '60%' }),
        });

        // Tabela DRE
        const rows = data.resumo || [];
        document.getElementById('tbDreResumo').innerHTML = rows.length ? rows.map(r => {
            const cls = r.grupo === 'RECEITAS' ? 'dre-row-receita' : (r.grupo === 'RESULTADO' ? 'dre-row-resultado' : 'dre-row-despesa');
            const color = r.grupo === 'RECEITAS' ? COLORS.green : (r.grupo === 'RESULTADO' ? (r.valor >= 0 ? COLORS.green : COLORS.red) : COLORS.red);
            return `<tr class="${cls}">
                <td><span class="status-badge" style="background:${color}20;color:${color}">${r.grupo}</span></td>
                <td>${r.descricao}</td>
                <td class="text-end mono" style="color:${color};font-weight:600">${brl(r.valor)}</td>
            </tr>`;
        }).join('') : `<tr><td colspan="3" class="empty-bi">Sem dados para o período.</td></tr>`;
    }

    // ---- CONTRATOS ---- //
    function renderContratos(data) {
        const k = data.kpis;
        document.getElementById('ctrKpis').innerHTML = `
            <div class="kpi blue">
                <div class="kpi-lbl">Total de contratos</div>
                <div class="kpi-val">${fmtNum(k.total_contratos)}</div>
            </div>
            <div class="kpi green">
                <div class="kpi-lbl">Contratos ativos</div>
                <div class="kpi-val">${fmtNum(k.ativos)}</div>
            </div>
            <div class="kpi purple">
                <div class="kpi-lbl">Valor mensal ativo</div>
                <div class="kpi-val">${brl(k.valor_ativo)}</div>
            </div>
            <div class="kpi amber">
                <div class="kpi-lbl">Parcelas abertas</div>
                <div class="kpi-val">${fmtNum(k.parcelas_abertas)}</div>
            </div>
            <div class="kpi red">
                <div class="kpi-lbl">Parcelas em atraso</div>
                <div class="kpi-val">${fmtNum(k.parcelas_atraso)}</div>
            </div>
        `;

        // Doughnut contratos
        destroyChart('ctrStatus');
        charts.ctrStatus = new Chart(document.getElementById('chartContratoStatus'), {
            type: 'doughnut',
            data: {
                labels: data.status_contratos.labels,
                datasets: [{ data: data.status_contratos.valores, backgroundColor: CHART_COLORS, borderWidth: 0 }]
            },
            options: chartOpts({ cutout: '55%' }),
        });

        // Pie parcelas
        destroyChart('parcStatus');
        charts.parcStatus = new Chart(document.getElementById('chartParcelasStatus'), {
            type: 'doughnut',
            data: {
                labels: data.status_parcelas.labels,
                datasets: [{ data: data.status_parcelas.valores, backgroundColor: [COLORS.amber, COLORS.red, COLORS.green, '#94a3b8'], borderWidth: 0 }]
            },
            options: chartOpts({ cutout: '55%' }),
        });

        // Tabela
        const rows = data.lista || [];
        document.getElementById('tbContratosBi').innerHTML = rows.length ? rows.map(r => `
            <tr>
                <td class="mono" style="font-weight:600">${r.CTR_NUMERO}</td>
                <td>${r.CLIENTE_NOME || '-'}</td>
                <td>${r.EMPRESA_NOME || '-'}</td>
                <td class="text-end mono">${brl(r.CTR_VALOR_MENSAL)}</td>
                <td>${badgeStatus(r.CTR_STATUS)}</td>
                <td>${r.PROXIMA_COBRANCA_BR || '-'}</td>
            </tr>
        `).join('') : `<tr><td colspan="6" class="empty-bi">Nenhum contrato encontrado.</td></tr>`;
    }

    // ---- PREVISÃO DE CONTAS ---- //
    function renderPrevisao(data) {
        const lbl = document.getElementById('prevPeriodoLabel');
        if (lbl) lbl.textContent = data.periodo_label || 'Projeção de débitos e créditos';

        // KPIs
        document.getElementById('prevKpis').innerHTML = `
            <div class="kpi-card">
                <div class="kpi-lbl">Saldo atual</div>
                <div class="kpi-val">${brl(data.saldo_atual)}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-lbl">A receber (previsto)</div>
                <div class="kpi-val" style="color:${COLORS.green}">${brl(data.total_receber)}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-lbl">A pagar (previsto)</div>
                <div class="kpi-val" style="color:${COLORS.red}">${brl(data.total_pagar)}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-lbl">Resultado previsto</div>
                <div class="kpi-val" style="color:${data.resultado_previsto >= 0 ? COLORS.green : COLORS.red}">${brl(data.resultado_previsto)}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-lbl">Saldo projetado</div>
                <div class="kpi-val" style="color:${data.saldo_projetado >= 0 ? COLORS.blue : COLORS.red}">${brl(data.saldo_projetado)}</div>
            </div>
        `;

        // Gráfico de projeção de saldo
        const g = data.grafico || { labels: [], saldo: [] };
        if (charts.prevSaldo) charts.prevSaldo.destroy();
        charts.prevSaldo = new Chart(document.getElementById('chartPrevSaldo'), {
            type: 'line',
            data: {
                labels: g.labels,
                datasets: [{
                    label: 'Saldo projetado',
                    data: g.saldo,
                    borderColor: COLORS.blue,
                    backgroundColor: COLORS.blue10,
                    fill: true,
                    pointRadius: 0,
                }]
            },
            options: chartOpts({
                plugins: { legend: { display: false },
                    tooltip: { callbacks: { label: ctx => ' Saldo: ' + brl(ctx.raw) } } },
                scales: { y: { ticks: { callback: v => brl(v) } } },
            })
        });

        // Tabela com agrupamento por bucket de período
        const itens = data.itens || [];
        const tbody = document.getElementById('tbPrevisao');
        if (!itens.length) {
            tbody.innerHTML = `<tr><td colspan="7" class="empty-bi">Nenhuma conta prevista no período.</td></tr>`;
            return;
        }

        const hoje = new Date(); hoje.setHours(0,0,0,0);
        const fimSemana = new Date(hoje); fimSemana.setDate(fimSemana.getDate() + (7 - (fimSemana.getDay() || 7)));
        const fimMes = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);
        function bucket(dataStr) {
            const d = new Date(dataStr + 'T00:00:00');
            if (d <= fimSemana) return 'Esta semana';
            if (d <= fimMes) return 'Este mês';
            return 'Próximos meses';
        }
        function fmtData(s) {
            const d = new Date(s + 'T00:00:00');
            return d.toLocaleDateString('pt-BR');
        }

        let html = '';
        let grupoAtual = null;
        for (const it of itens) {
            const grupo = bucket(it.vencimento);
            if (grupo !== grupoAtual) {
                grupoAtual = grupo;
                html += `<tr><td colspan="7" style="background:#f1f5f9;font-weight:600;color:#475569">${grupo}</td></tr>`;
            }
            const isCred = it.tipo === 'CREDITO';
            const tipoBadge = isCred
                ? `<span class="status-badge" style="background:${COLORS.green10};color:${COLORS.green}">Crédito</span>`
                : `<span class="status-badge" style="background:${COLORS.red10};color:${COLORS.red}">Débito</span>`;
            const valorCor = isCred ? COLORS.green : COLORS.red;
            const sinal = isCred ? '' : '-';
            html += `
                <tr>
                    <td>${fmtData(it.vencimento)}</td>
                    <td>${tipoBadge}</td>
                    <td>${it.nome || '-'}</td>
                    <td class="text-muted">${it.descricao || '-'}</td>
                    <td class="text-muted">${it.plano || '-'}</td>
                    <td class="text-end mono" style="color:${valorCor}">${sinal}${brl(it.valor)}</td>
                    <td class="text-end mono" style="color:${it.saldo_acumulado >= 0 ? '#1e293b' : COLORS.red}">${brl(it.saldo_acumulado)}</td>
                </tr>`;
        }
        tbody.innerHTML = html;
    }

    // ---- REFRESH ---- //
    async function refresh() {
        const params = { periodo: PERIODO_ATUAL, empresa_id: EMPRESA_ATUAL };
        if (DATA_INI_CUSTOM && DATA_FIM_CUSTOM) {
            params.data_ini = DATA_INI_CUSTOM;
            params.data_fim = DATA_FIM_CUSTOM;
        }

        try {
            const [empresas, overview, dre, contratos, previsao] = await Promise.all([
                api({ acao: 'empresas' }),
                api({ acao: 'overview', ...params }),
                api({ acao: 'dre', ...params }),
                api({ acao: 'contratos', ...params }),
                api({ acao: 'previsao', ...params }),
            ]);

            renderEmpresas(empresas.rows || []);
            renderOverview(overview);
            renderDRE(dre);
            renderContratos(contratos);
            renderPrevisao(previsao);
        } catch (err) {
            console.error('BI refresh error:', err);
            Swal.fire({ icon: 'error', title: 'Erro ao carregar BI', text: err.message });
        }
    }

    // ---- Events ---- //
    document.querySelectorAll('.bi-tab').forEach(btn => {
        btn.addEventListener('click', () => setView(btn.dataset.view));
    });

    // Botões de período rápido
    document.querySelectorAll('.bi-periodo-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            // Limpar datas customizadas
            DATA_INI_CUSTOM = '';
            DATA_FIM_CUSTOM = '';
            document.getElementById('biDataIni').value = '';
            document.getElementById('biDataFim').value = '';

            // Ativar botão
            document.querySelectorAll('.bi-periodo-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            PERIODO_ATUAL = btn.dataset.periodo;
            refresh();
        });
    });

    // Filtro por data customizada
    document.getElementById('btnFiltrarData').addEventListener('click', () => {
        const ini = document.getElementById('biDataIni').value;
        const fim = document.getElementById('biDataFim').value;
        if (!ini || !fim) {
            Swal.fire({ icon: 'warning', title: 'Informe as duas datas.' });
            return;
        }
        if (ini > fim) {
            Swal.fire({ icon: 'warning', title: 'Data inicial maior que a final.' });
            return;
        }

        // Desativar botões de período
        document.querySelectorAll('.bi-periodo-btn').forEach(b => b.classList.remove('active'));

        DATA_INI_CUSTOM = ini;
        DATA_FIM_CUSTOM = fim;
        PERIODO_ATUAL = 'CUSTOM';
        refresh();
    });

    // ---- Init ---- //
    refresh();
    </script>
  <script src="assets/session_keeper.js" defer></script>
</body>
</html>
