<?php
// /app/contrato_detalhe.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

$id = (int)($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>DRE - Detalhes do Contrato</title>

    <?php include __DIR__ . '/includes/head.php'; ?>

    <style>
        /* usa o mesmo “dashboard-card”/badges do seu tema (ou do styles.css do cliente) */
        .dashboard-container {
            max-width: 1200px;
        }

        .dashboard-card {
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(16, 24, 40, .06);
            border: 1px solid rgba(17, 24, 39, .06);
        }

        .badge-soft-success {
            background: rgba(34, 197, 94, .12);
            color: #14532d;
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

        .badge-soft-secondary {
            background: rgba(100, 116, 139, .12);
            color: #0f172a;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem;
        }

        .nav-tabs-sync .nav-link {
            border-radius: 12px 12px 0 0;
            font-weight: 600;
        }

        .table-dashboard thead th {
            font-size: .78rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 1px solid rgba(17, 24, 39, .08) !important;
        }

        .table-dashboard td {
            vertical-align: middle;
        }

        .contrato-timeline li {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
        }

        .contrato-timeline-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            margin-top: 6px;
            flex: 0 0 10px;
        }
    </style>
</head>

<body data-page="contratos">
    <div class="d-flex" id="wrapper">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">

            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Detalhes do Contrato</span>

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

                <!-- Topo (igual do cliente) -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div class="d-flex flex-column">
                        <a href="contratos.php" class="small text-decoration-none mb-1">
                            <i class="fa-solid fa-arrow-left-long me-1"></i>
                            Voltar para contratos
                        </a>

                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <h5 class="mb-0" id="hdrNumero">Contrato —</h5>
                            <span class="text-muted small" id="hdrCliente">• —</span>
                            <span class="badge badge-soft-success" id="hdrStatus">—</span>
                        </div>

                        <small class="text-muted" id="hdrSub">—</small>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-secondary btn-sm" id="btnPrint">
                            <i class="fa-solid fa-print me-1"></i>Imprimir
                        </button>
                        <button class="btn btn-outline-primary btn-sm" id="btnEditar">
                            <i class="fa-solid fa-pen me-1"></i>Editar contrato
                        </button>
                        <button class="btn btn-outline-warning btn-sm" id="btnSuspender">
                            <i class="fa-solid fa-circle-pause me-1"></i>Suspender
                        </button>
                    </div>
                </div>

                <!-- Resumo do contrato (igual do cliente) -->
                <div class="card dashboard-card mb-3">
                    <div class="card-body">
                        <div class="row g-3 align-items-center">
                            <div class="col-md-4">
                                <h6 class="mb-1">Resumo financeiro</h6>
                                <div class="small">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Valor mensal</span>
                                        <strong id="rsValorMensal">—</strong>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Tipo de contrato</span>
                                        <span id="rsTipo">—</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Dia de vencimento</span>
                                        <span id="rsDia">—</span>
                                    </div>
                                    <div class="d-flex justify-content-between pt-2 mt-2 border-top">
                                        <span>Próxima cobrança</span>
                                        <span class="text-primary fw-semibold" id="rsProxima">—</span>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <h6 class="mb-1">Situação das cobranças</h6>
                                <div class="small">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Parcelas geradas</span>
                                        <span id="stTotal">0</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Recebidas</span>
                                        <span class="text-success fw-semibold" id="stRecebidas">0</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Em aberto</span>
                                        <span class="text-warning fw-semibold" id="stEmAberto">0</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Em atraso</span>
                                        <span class="text-danger fw-semibold" id="stAtraso">0</span>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <h6 class="mb-1">Atalhos</h6>
                                <div class="d-flex flex-column gap-2 small">
                                    <button class="btn btn-sm btn-outline-primary text-start" id="btnCfgParcelas">
                                        <i class="fa-solid fa-gears me-1"></i>Configurar geração de parcelas
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary text-start" id="btnGerarParcelas">
                                        <i class="fa-solid fa-file-invoice-dollar me-1"></i>Gerar próximas parcelas
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary text-start" id="btnVerCR">
                                        <i class="fa-solid fa-up-right-from-square me-1"></i>Ver títulos no Contas a Receber
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Abas (copiado fielmente do cliente) + CNAB -->
                <div class="card dashboard-card">
                    <div class="card-body">
                        <ul class="nav nav-tabs nav-tabs-sync mb-3" id="contratoTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="dados-tab" data-bs-toggle="tab"
                                    data-bs-target="#dados" type="button" role="tab"
                                    aria-controls="dados" aria-selected="true">
                                    <i class="fa-solid fa-file-lines me-1"></i>Dados gerais
                                </button>
                            </li>

                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="financeiro-tab" data-bs-toggle="tab"
                                    data-bs-target="#financeiro" type="button" role="tab"
                                    aria-controls="financeiro" aria-selected="false">
                                    <i class="fa-solid fa-coins me-1"></i>Configuração financeira
                                </button>
                            </li>

                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="parcelas-tab" data-bs-toggle="tab"
                                    data-bs-target="#parcelas" type="button" role="tab"
                                    aria-controls="parcelas" aria-selected="false">
                                    <i class="fa-solid fa-list-ol me-1"></i>Parcelas / Títulos
                                </button>
                            </li>

                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="historico-tab" data-bs-toggle="tab"
                                    data-bs-target="#historico" type="button" role="tab"
                                    aria-controls="historico" aria-selected="false">
                                    <i class="fa-solid fa-clock-rotate-left me-1"></i>Histórico & Observações
                                </button>
                            </li>

                            <!-- Mantida: CNAB -->
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="cnab-tab" data-bs-toggle="tab"
                                    data-bs-target="#cnab" type="button" role="tab"
                                    aria-controls="cnab" aria-selected="false">
                                    <i class="fa-solid fa-file-arrow-up me-1"></i>CNAB (Remessa/Retorno)
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content" id="contratoTabsContent">

                            <!-- Aba 1: Dados gerais -->
                            <div class="tab-pane fade show active" id="dados" role="tabpanel" aria-labelledby="dados-tab">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Nº do contrato</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_ID" disabled />
                                    </div>

                                    <div class="col-md-5">
                                        <label class="form-label small mb-1">Cliente</label>
                                        <input type="text" class="form-control form-control-sm" id="vCLIENTE" disabled />
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small mb-1">Empresa</label>
                                        <input type="text" class="form-control form-control-sm" id="vEMPRESA" disabled />
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Situação</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_STATUS" disabled />
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Data de início</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_DT_INICIO" disabled />
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Data de término</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_DT_FIM" disabled />
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Referência interna</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_REFERENCIA_INTERNA" disabled />
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label small mb-1">Descrição / Objeto do contrato</label>
                                        <textarea class="form-control form-control-sm" rows="3" id="vCTR_DESCRICAO" disabled></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Aba 2: Configuração financeira -->
                            <div class="tab-pane fade" id="financeiro" role="tabpanel" aria-labelledby="financeiro-tab">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Tipo de contrato</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_TIPO" disabled />
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Valor mensal</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_VALOR_MENSAL" disabled />
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Dia de vencimento</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_DIA_VENCIMENTO" disabled />
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Forma de cobrança</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_FORMA_COBRANCA" disabled />
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Banco</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_BANCO" disabled />
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small mb-1">Plano de contas</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_PLANO_CONTAS" disabled />
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small mb-1">Centro de custo</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_CENTRO_CUSTO" disabled />
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small mb-1">Carteira / Convênio</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_CARTEIRA_CONVENIO" disabled />
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Reajuste automático</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_REAJUSTE" disabled />
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Índice / Percentual</label>
                                        <input type="text" class="form-control form-control-sm" id="vCTR_REAJUSTE_TEXTO" disabled />
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small mb-1">Observações financeiras</label>
                                        <textarea class="form-control form-control-sm" rows="2" id="vCTR_OBS_FINANCEIRA" disabled></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Aba 3: Parcelas / Títulos -->
                            <div class="tab-pane fade" id="parcelas" role="tabpanel" aria-labelledby="parcelas-tab">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <h6 class="mb-0">Parcelas / Títulos gerados</h6>
                                        <small class="text-muted">Cobranças vinculadas a este contrato.</small>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button class="btn btn-sm btn-outline-primary" id="btnGerarParcelas2">
                                            <i class="fa-solid fa-file-invoice-dollar me-1"></i>Gerar próximas parcelas
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" id="btnVerCR2">
                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Ver todas em Contas a Receber
                                        </button>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table align-middle mb-0 table-dashboard">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Competência</th>
                                                <th>Vencimento</th>
                                                <th class="text-end">Valor</th>
                                                <th>Status</th>
                                                <th>Documento</th>
                                                <th class="text-end">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tbParcelas">
                                            <tr>
                                                <td colspan="7" class="text-muted small">Carregando...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Aba 4: Histórico & Observações -->
                            <div class="tab-pane fade" id="historico" role="tabpanel" aria-labelledby="historico-tab">
                                <div class="row g-3">
                                    <div class="col-md-7">
                                        <h6 class="mb-2">Linha do tempo</h6>
                                        <ul class="list-unstyled small contrato-timeline mb-0" id="timeline">
                                            <li>
                                                <span class="contrato-timeline-dot bg-success"></span>
                                                <div>
                                                    <strong>Contrato criado</strong> • <span id="tlCriadoEm">—</span><br />
                                                    <span class="text-muted">Registro automático.</span>
                                                </div>
                                            </li>
                                            <li>
                                                <span class="contrato-timeline-dot bg-primary"></span>
                                                <div>
                                                    <strong>Última atualização</strong> • <span id="tlAtualizadoEm">—</span><br />
                                                    <span class="text-muted">Alterações recentes do cadastro.</span>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>

                                    <div class="col-md-5">
                                        <h6 class="mb-2">Observações internas</h6>
                                        <textarea class="form-control form-control-sm" rows="6" id="obsInterna"
                                            placeholder="Use este espaço para registrar informações internas sobre o contrato. Não é exibido ao cliente."></textarea>
                                        <div class="text-end mt-2">
                                            <button class="btn btn-sm btn-primary" id="btnSalvarObs">
                                                <i class="fa-solid fa-floppy-disk me-1"></i>Salvar observações
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Aba CNAB (Remessa/Retorno) -->
                            <div class="tab-pane fade" id="cnab" role="tabpanel" aria-labelledby="cnab-tab">
                                <div class="row g-3">
                                    <div class="col-lg-5">
                                        <h6 class="mb-2">Enviar arquivo CNAB</h6>

                                        <div class="row g-2">
                                            <div class="col-6">
                                                <label class="form-label small mb-1">Tipo</label>
                                                <select class="form-select form-select-sm" id="cnabTipo">
                                                    <option value="REMESSA">Remessa</option>
                                                    <option value="RETORNO">Retorno</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small mb-1">Banco</label>
                                                <select class="form-select form-select-sm" id="cnabBanco">
                                                    <option value="BRADESCO">Bradesco</option>
                                                    <option value="SICREDI">Sicredi</option>
                                                </select>
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label small mb-1">Arquivo</label>
                                                <input type="file" class="form-control form-control-sm" id="cnabArquivo" />
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label small mb-1">Observação</label>
                                                <textarea class="form-control form-control-sm" rows="2" id="cnabObs"></textarea>
                                            </div>

                                            <div class="col-12 text-end">
                                                <button class="btn btn-sm btn-outline-primary" id="btnEnviarCnab">
                                                    <i class="fa-solid fa-cloud-arrow-up me-1"></i>Enviar
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-7">
                                        <h6 class="mb-2">Arquivos processados</h6>
                                        <div class="table-responsive">
                                            <table class="table table-dashboard align-middle mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Tipo</th>
                                                        <th>Banco</th>
                                                        <th>Arquivo</th>
                                                        <th>Processado em</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="tbCnab">
                                                    <tr>
                                                        <td colspan="5" class="text-muted small">Carregando...</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="small text-muted mt-2">
                                            (Por enquanto: upload + registro. Processamento CNAB a gente liga depois.)
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div><!-- tab-content -->
                    </div>
                </div>

                <footer class="text-muted small mt-4">
                    © <?= date('Y') ?> DRE - Sistema Financeiro
                </footer>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        const CONTRATO_ID = <?= (int)$id ?>;
        const ENDPOINT = 'endpoints/contratos.php';

        function brMoney(v) {
            const n = Number(v || 0);
            return n.toLocaleString('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            });
        }

        function dtBR(iso) {
            if (!iso) return '—';
            const s = String(iso).slice(0, 10);
            const [y, m, d] = s.split('-');
            if (!y || !m || !d) return String(iso);
            return `${d}/${m}/${y}`;
        }

        function parseYMD(ymd) {
            if (!ymd) return null;
            const s = String(ymd).trim().slice(0, 19).replace(' ', 'T');
            const d = new Date(s);
            return isNaN(d.getTime()) ? null : d;
        }

        function fmtDateTimeBR(ymd) {
            const d = parseYMD(ymd);
            if (!d) return '—';
            return new Intl.DateTimeFormat('pt-BR', {
                dateStyle: 'short',
                timeStyle: 'short'
            }).format(d);
        }

        function badgeStatusContrato(s) {
            s = (s || '').toUpperCase();
            if (s === 'ATIVO') return {
                txt: 'Ativo',
                cls: 'badge-soft-success'
            };
            if (s === 'SUSPENSO') return {
                txt: 'Suspenso',
                cls: 'badge-soft-warning'
            };
            if (s === 'ENCERRADO') return {
                txt: 'Encerrado',
                cls: 'badge-soft-danger'
            };
            return {
                txt: s || '—',
                cls: 'badge-soft-secondary'
            };
        }

        function badgeParcelaFromStatus(statusRaw) {
            const s = (statusRaw || '').toUpperCase();

            if (s === 'RECEBIDO') return `<span class="badge-soft-success">Recebido</span>`;
            if (s === 'EM_ABERTO') return `<span class="badge-soft-warning">Em aberto</span>`;
            if (s === 'PROGRAMADO') return `<span class="badge-soft-primary">Programado</span>`;
            if (s === 'ATRASO') return `<span class="badge-soft-danger">Atraso</span>`;
            if (s === 'CANCELADO') return `<span class="badge-soft-secondary">Cancelado</span>`;

            return `<span class="badge-soft-secondary">${s || '—'}</span>`;
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
                console.error('NÃO JSON:', txt);
                throw new Error('Endpoint não retornou JSON (veja o console).');
            }

            if (!j.ok) throw new Error(j.msg || 'Falha na requisição.');
            return j;
        }

        async function apiUploadCnab(fd) {
            const r = await fetch(ENDPOINT, {
                method: 'POST',
                body: fd
            });
            const txt = await r.text();
            let j;
            try {
                j = JSON.parse(txt);
            } catch (e) {
                console.error('NÃO JSON:', txt);
                throw new Error('Endpoint não retornou JSON.');
            }
            if (!j.ok) throw new Error(j.msg || 'Falha no upload.');
            return j;
        }

        function toISODate(d) {
            const pad = (n) => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
        }

        function calcularResumoParcelas(parcelas) {
            const total = parcelas.length;

            const recebidas = parcelas.filter(p => (p.CPA_STATUS || '').toUpperCase() === 'RECEBIDO').length;
            const emAberto = parcelas.filter(p => ['PROGRAMADO', 'EM_ABERTO'].includes((p.CPA_STATUS || '').toUpperCase())).length;
            const emAtraso = parcelas.filter(p => (p.CPA_STATUS || '').toUpperCase() === 'ATRASO').length;

            const hojeISO = toISODate(new Date());
            let prox = null;

            // primeiro tenta >= hoje
            parcelas.forEach(p => {
                const st = (p.CPA_STATUS || '').toUpperCase();
                if (st === 'RECEBIDO' || st === 'CANCELADO') return;
                const venc = String(p.CPA_VENCIMENTO || '').slice(0, 10);
                if (!venc) return;
                if (venc >= hojeISO) {
                    if (!prox || venc < prox) prox = venc;
                }
            });

            // se não achou, pega a menor pendente
            if (!prox) {
                parcelas.forEach(p => {
                    const st = (p.CPA_STATUS || '').toUpperCase();
                    if (st === 'RECEBIDO' || st === 'CANCELADO') return;
                    const venc = String(p.CPA_VENCIMENTO || '').slice(0, 10);
                    if (!venc) return;
                    if (!prox || venc < prox) prox = venc;
                });
            }

            return {
                total,
                recebidas,
                emAberto,
                emAtraso,
                prox
            };
        }

        async function carregarContrato() {
            if (!CONTRATO_ID) throw new Error('Abra a página com ?id=CTR_ID');

            // endpoint funcional usa "get"
            const j = await api({
                acao: 'get',
                id: CONTRATO_ID
            }, 'GET');
            const c = j.row;

            // header
            document.getElementById('hdrNumero').textContent = `Contrato ${c.CTR_ID || ('#' + c.CTR_ID)}`;
            document.getElementById('hdrCliente').textContent = `• ${c.CLIENTE_NOME || '—'}`;

            const b = badgeStatusContrato(c.CTR_STATUS);
            const elS = document.getElementById('hdrStatus');
            elS.className = `badge ${b.cls}`;
            elS.textContent = b.txt;

            const vencTxt = c.CTR_DIA_VENCIMENTO ? `Vencimento todo dia ${c.CTR_DIA_VENCIMENTO}` : 'Vencimento —';
            document.getElementById('hdrSub').textContent =
                `${c.EMPRESA_NOME || '—'} • Início: ${dtBR(c.CTR_DT_INICIO)} • ${vencTxt}`;

            // resumo (parciais — próxima/contagem vem das parcelas)
            document.getElementById('rsValorMensal').textContent = brMoney(c.CTR_VALOR_MENSAL);
            document.getElementById('rsTipo').textContent =
                (c.CTR_TIPO === 'RECORRENTE') ? 'Recorrente (até cancelar)' :
                (c.CTR_TIPO === 'PARCELADO') ? 'Parcelado' : 'Único';
            document.getElementById('rsDia').textContent = c.CTR_DIA_VENCIMENTO ? `Todo dia ${c.CTR_DIA_VENCIMENTO}` : '—';

            // aba dados
            document.getElementById('vCTR_ID').value = c.CTR_ID || '';
            document.getElementById('vCLIENTE').value = c.CLIENTE_NOME || '';
            document.getElementById('vEMPRESA').value = c.EMPRESA_NOME || '';
            document.getElementById('vCTR_STATUS').value = b.txt;

            document.getElementById('vCTR_DT_INICIO').value = dtBR(c.CTR_DT_INICIO);
            document.getElementById('vCTR_DT_FIM').value = c.CTR_DT_FIM ? dtBR(c.CTR_DT_FIM) : 'Indeterminado';
            document.getElementById('vCTR_REFERENCIA_INTERNA').value = c.CTR_REFERENCIA_INTERNA || '';
            document.getElementById('vCTR_DESCRICAO').value = c.CTR_DESCRICAO || '';

            // aba financeiro
            document.getElementById('vCTR_TIPO').value = document.getElementById('rsTipo').textContent;
            document.getElementById('vCTR_VALOR_MENSAL').value = brMoney(c.CTR_VALOR_MENSAL);
            document.getElementById('vCTR_DIA_VENCIMENTO').value = c.CTR_DIA_VENCIMENTO || '';
            document.getElementById('vCTR_FORMA_COBRANCA').value = c.CTR_FORMA_COBRANCA || 'BOLETO';
            document.getElementById('vCTR_BANCO').value = c.CTR_BANCO || '';
            document.getElementById('vCTR_PLANO_CONTAS').value = c.CTR_PLANO_CONTAS || '';
            document.getElementById('vCTR_CENTRO_CUSTO').value = c.CTR_CENTRO_CUSTO || '';
            document.getElementById('vCTR_CARTEIRA_CONVENIO').value =
                `${c.CTR_CARTEIRA || ''}${(c.CTR_CONVENIO ? ' / ' + c.CTR_CONVENIO : '')}`.trim();
            document.getElementById('vCTR_REAJUSTE').value =
                (c.CTR_REAJUSTE === 'ANUAL') ? 'Anual' : (c.CTR_REAJUSTE === 'MENSAL') ? 'Mensal' : 'Nenhum';
            document.getElementById('vCTR_REAJUSTE_TEXTO').value = c.CTR_REAJUSTE_TEXTO || '';
            document.getElementById('vCTR_OBS_FINANCEIRA').value = c.CTR_OBS_FINANCEIRA || '';

            // histórico (pt-BR)
            document.getElementById('tlCriadoEm').textContent = c.CTR_CRIADO_EM ? fmtDateTimeBR(c.CTR_CRIADO_EM) : '—';
            document.getElementById('tlAtualizadoEm').textContent = c.CTR_ATUALIZADO_EM ? fmtDateTimeBR(c.CTR_ATUALIZADO_EM) : '—';
            document.getElementById('obsInterna').value = c.CTR_OBSERVACAO_INTERNA || '';
            document.getElementById('cnabBanco').value = c.CTR_BANCO || 'BRADESCO';
        }

        async function carregarParcelasEResumo() {
            const j = await api({
                acao: 'parcelas_listar',
                ctrId: CONTRATO_ID
            }, 'GET');
            const parcelas = Array.isArray(j.rows) ? j.rows : [];

            // resumo (valores reais)
            const r = calcularResumoParcelas(parcelas);
            document.getElementById('stTotal').textContent = String(r.total);
            document.getElementById('stRecebidas').textContent = String(r.recebidas);
            document.getElementById('stEmAberto').textContent = String(r.emAberto);
            document.getElementById('stAtraso').textContent = String(r.emAtraso);
            document.getElementById('rsProxima').textContent = r.prox ? dtBR(r.prox) : '—';

            // tabela
            const tb = document.getElementById('tbParcelas');
            tb.innerHTML = '';

            if (parcelas.length === 0) {
                tb.innerHTML = '<tr><td colspan="7" class="text-muted small">Nenhuma parcela encontrada.</td></tr>';
                return;
            }

            parcelas.forEach(p => {
                const doc = p.CPA_SEU_NUMERO || p.CPA_NOSSO_NUMERO || '—';

                const ver = `<button class="btn btn-sm btn-outline-secondary me-1" title="Ver título" data-act="ver" data-id="${p.CPA_ID}">
              <i class="fa-solid fa-eye"></i>
            </button>`;

                const gerar = `<button class="btn btn-sm btn-outline-primary" title="Gerar boleto" data-act="gerar" data-id="${p.CPA_ID}">
              <i class="fa-solid fa-barcode"></i>
            </button>`;

                tb.insertAdjacentHTML('beforeend', `
                <tr>
                    <td>${p.CPA_NUM ?? ''}/${p.CPA_TOTAL ?? ''}</td>
                    <td>${p.CPA_COMPETENCIA ?? ''}</td>
                    <td>${dtBR(p.CPA_VENCIMENTO)}</td>
                    <td class="text-end">${brMoney(p.CPA_VALOR)}</td>
                    <td>${badgeParcelaFromStatus(p.CPA_STATUS)}</td>
                    <td>${doc}</td>
                    <td class="text-end">${ver}${gerar}</td>
                </tr>
            `);
            });
        }

        async function carregarCnab() {
            const j = await api({
                acao: 'cnab_listar',
                ctrId: CONTRATO_ID
            }, 'GET');
            const tb = document.getElementById('tbCnab');
            tb.innerHTML = '';

            if (!j.rows || j.rows.length === 0) {
                tb.innerHTML = '<tr><td colspan="5" class="text-muted small">Nenhum arquivo CNAB registrado.</td></tr>';
                return;
            }

            j.rows.forEach(r => {
                tb.insertAdjacentHTML('beforeend', `
                <tr>
                    <td>${r.CNB_ID}</td>
                    <td>${r.CNB_TIPO}</td>
                    <td>${r.CNB_BANCO}</td>
                    <td>${r.CNB_NOME_ARQUIVO}</td>
                    <td>${r.CNB_DATA_PROCESSAMENTO ? fmtDateTimeBR(r.CNB_DATA_PROCESSAMENTO) : ''}</td>
                </tr>
            `);
            });
        }

        async function salvarObsInterna() {
            const obs = document.getElementById('obsInterna').value || '';

            // NÃO pode chamar "salvar" (exige número do contrato e outros campos)
            // aqui usamos uma ação dedicada (recomendado).
            const tentativas = [{
                    acao: 'obs_salvar',
                    id: CONTRATO_ID,
                    CTR_OBSERVACAO_INTERNA: obs
                },
                {
                    acao: 'salvar_obs',
                    id: CONTRATO_ID,
                    obs: obs
                },
            ];

            let ok = false;
            let lastErr = null;

            for (const t of tentativas) {
                try {
                    await api(t, 'POST');
                    ok = true;
                    break;
                } catch (e) {
                    lastErr = e;
                }
            }

            if (!ok) {
                throw new Error(lastErr?.message || 'Falha ao salvar observações. (Crie no endpoint a ação obs_salvar.)');
            }

            Swal.fire({
                icon: 'success',
                title: 'Ok',
                text: 'Observações salvas!',
                timer: 900,
                showConfirmButton: false
            });
            await carregarContrato();
        }

        async function uploadCnab() {
            const tipo = document.getElementById('cnabTipo').value;
            const banco = document.getElementById('cnabBanco').value;
            const obs = document.getElementById('cnabObs').value || '';
            const file = document.getElementById('cnabArquivo').files[0];

            if (!file) {
                Swal.fire({
                    icon: 'warning',
                    title: 'CNAB',
                    text: 'Selecione um arquivo.'
                });
                return;
            }

            // seu endpoint antigo espera CNB_CTR_ID / CNB_TIPO / CNB_BANCO
            const fd = new FormData();
            fd.append('acao', 'cnab_upload');
            fd.append('CNB_CTR_ID', String(CONTRATO_ID));
            fd.append('CNB_TIPO', tipo);
            fd.append('CNB_BANCO', banco);
            fd.append('CNB_OBS', obs);
            fd.append('arquivo', file);

            await apiUploadCnab(fd);

            Swal.fire({
                icon: 'success',
                title: 'Ok',
                text: 'Arquivo CNAB registrado.',
                timer: 900,
                showConfirmButton: false
            });
            document.getElementById('cnabArquivo').value = '';
            document.getElementById('cnabObs').value = '';
            await carregarCnab();
        }

        // ===== binds =====
        document.getElementById('btnSalvarObs').addEventListener('click', () => {
            salvarObsInterna().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('btnEnviarCnab').addEventListener('click', () => {
            uploadCnab().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('tbParcelas').addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-act]');
            if (!btn) return;

            const act = btn.dataset.act;
            const id = btn.dataset.id;

            if (act === 'ver') {
                Swal.fire({
                    icon: 'info',
                    title: 'Ver título',
                    text: `Parcela ${id}. Vamos ligar a impressão do boleto Bradesco na próxima etapa.`
                });
            }
            if (act === 'gerar') {
                Swal.fire({
                    icon: 'info',
                    title: 'Gerar boleto',
                    text: `Parcela ${id}. Vamos ligar a geração de 2ª via (novo boleto) na próxima etapa.`
                });
            }
        });

        // botões topo (replica comportamento da listagem)
        document.getElementById('btnPrint').addEventListener('click', () => {
            window.print();
        });

        document.getElementById('btnEditar').addEventListener('click', () => {
            // padrão: voltar pra listagem e abrir o modal em edição
            // ajuste se seu JS usa outro hash/query
            window.location.href = `contratos.php#edit=${encodeURIComponent(String(CONTRATO_ID))}`;
        });

        document.getElementById('btnSuspender').addEventListener('click', async () => {
            try {
                const r = await Swal.fire({
                    icon: 'warning',
                    title: 'Suspender contrato?',
                    html: `
                        <div class="text-start small">
                            <p class="mb-2">O contrato ficará com status <strong>SUSPENSO</strong>.</p>
                            <p class="mb-0">O que deseja fazer com as <strong>parcelas ainda não pagas</strong>?</p>
                        </div>
                    `,
                    showDenyButton: true,
                    showCancelButton: true,
                    confirmButtonText: 'Eliminar parcelas não pagas',
                    denyButtonText: 'Manter parcelas',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#dc3545',
                    denyButtonColor: '#6c757d',
                    reverseButtons: true
                });
                if (r.isDismissed) return;

                const removerParcelas = r.isConfirmed ? 'SIM' : 'NAO';

                const resp = await api({
                    acao: 'suspender',
                    id: CONTRATO_ID,
                    remover_parcelas: removerParcelas
                }, 'POST');

                if (removerParcelas === 'SIM') {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Contrato suspenso',
                        html: `
                            <div class="text-start small">
                                <div><strong>Parcelas removidas:</strong> ${resp.parcelas_removidas ?? 0}</div>
                                <div><strong>Contas a receber removidas:</strong> ${resp.contas_receber_removidas ?? 0}</div>
                            </div>
                        `
                    });
                } else {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Contrato suspenso',
                        text: 'As parcelas em aberto foram mantidas no sistema.',
                        timer: 1200,
                        showConfirmButton: false
                    });
                }

                await carregarContrato();
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: err.message
                });
            }
        });

        // atalhos (por enquanto só navegação/placeholder)
        document.getElementById('btnVerCR').addEventListener('click', () => {
            Swal.fire({
                icon: 'info',
                title: 'Contas a Receber',
                text: 'Vamos ligar essa tela quando criar o módulo de CR.'
            });
        });
        document.getElementById('btnVerCR2').addEventListener('click', () => document.getElementById('btnVerCR').click());

        // init
        (async () => {
            try {
                await carregarContrato();
                await carregarParcelasEResumo();
                await carregarCnab();
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