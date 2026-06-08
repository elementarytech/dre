<?php
// /app/contratos.php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Contratos</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .table thead th {
            font-size: .78rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 1px solid rgba(17, 24, 39, .08) !important
        }

        .help {
            font-size: .86rem;
            color: #64748b
        }

        .badge-soft-success {
            background: rgba(34, 197, 94, .12);
            color: #14532d;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem
        }

        .badge-soft-warning {
            background: rgba(245, 158, 11, .14);
            color: #92400e;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem
        }

        .badge-soft-danger {
            background: rgba(239, 68, 68, .12);
            color: #991b1b;
            border-radius: 999px;
            padding: .35rem .6rem;
            font-size: .78rem
        }

        .modal-xxl {
            max-width: 92vw
        }

        @media (min-width: 1200px) {
            .modal-xxl {
                max-width: 1150px
            }
        }

        .nav-tabs .nav-link {
            border-radius: 12px 12px 0 0;
            font-weight: 600
        }

        .tab-pane {
            padding-top: 14px
        }

        .help-mini {
            font-size: .84rem;
            color: #64748b
        }

        .autocomplete-item {
            cursor: pointer;
            font-size: .875rem;
            line-height: 1.25rem;
        }

        .autocomplete-item:hover {
            background: #f8fafc;
        }

        .autocomplete-item strong {
            font-weight: 600;
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

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Contratos de Clientes</span>

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
                        <h5 class="mb-1 mt-1">Contratos</h5>
                        <p class="help mb-0">Cadastre contratos e gere parcelas (boletos) para envio por remessa/retorno.</p>
                    </div>
                    <div class="mt-2 mt-sm-0 d-flex gap-2">
                        <button id="btnVerLogsSuspensao" type="button" class="btn btn-sm btn-outline-warning">
                            <i class="fa-solid fa-circle-pause me-1"></i>Log de suspensões
                        </button>
                        <button id="btnVerLogsExclusao" type="button" class="btn btn-sm btn-outline-dark">
                            <i class="fa-solid fa-clock-rotate-left me-1"></i>Log de exclusões
                        </button>
                        <button id="btnNovoContrato" type="button" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-file-circle-plus me-1"></i>Novo contrato
                        </button>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body py-3">
                        <form class="row g-2 align-items-end" id="frmFiltros">
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Buscar</label>
                                <input type="text" class="form-control form-control-sm" id="fBuscar" placeholder="ID, cliente, empresa..." />
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-1">Situação</label>
                                <select class="form-select form-select-sm" id="fStatus">
                                    <option value="">Todos</option>
                                    <option value="ATIVO">Ativo</option>
                                    <option value="SUSPENSO">Suspenso</option>
                                    <option value="ENCERRADO">Encerrado</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-1">Tipo</label>
                                <select class="form-select form-select-sm" id="fTipo">
                                    <option value="">Todos</option>
                                    <option value="RECORRENTE">Recorrente</option>
                                    <option value="PARCELADO">Parcelado</option>
                                    <option value="UNICO">Único</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-1">Parcelas</label>
                                <select class="form-select form-select-sm" id="fParcelas" title="Filtra por quantidade de parcelas geradas">
                                    <option value="">Todas</option>
                                    <option value="unica">Única (1 parcela)</option>
                                    <option value="multipla">Parcelado (2+ parcelas)</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-1">Empresa</label>
                                <select class="form-select form-select-sm" id="fEmpresa">
                                    <option value="0">Todas</option>
                                </select>
                            </div>
                            <div class="col-md-1 text-end">
                                <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted">Contratos cadastrados</span>
                            <span class="small text-muted" id="lblTotal">—</span>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>ID</th>
                                        <th>Cliente</th>
                                        <th>Empresa</th>
                                        <th class="text-end">Valor</th>
                                        <th>Início</th>
                                        <th>Status</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="tbContratos">
                                    <tr>
                                        <td colspan="8" class="text-muted small">Carregando...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>

                <footer class="text-muted small mt-4">
                    © <?= date('Y') ?> DRE - Sistema Financeiro
                </footer>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalContrato" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xxl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modalContratoTitulo">Novo contrato</h5>
                        <div class="help-mini">Use as abas para dados gerais, configuração financeira e observações.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <form id="frmContrato" autocomplete="off">
                        <input type="hidden" id="CTR_ID" value="">

                        <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-ctr-dados" type="button" role="tab">
                                    <i class="fa-solid fa-file-lines me-1"></i>Dados gerais
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ctr-fin" type="button" role="tab">
                                    <i class="fa-solid fa-coins me-1"></i>Configuração financeira
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ctr-obs" type="button" role="tab">
                                    <i class="fa-solid fa-clock-rotate-left me-1"></i>Histórico & Observações
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="tab-ctr-dados" role="tabpanel">
                                <div class="row g-3">
                                    <div class="col-md-6 position-relative">
                                        <label class="form-label small">Cliente *</label>

                                        <input type="hidden" id="CTR_CLIENTE_ID" value="0">

                                        <input type="text"
                                            class="form-control form-control-sm"
                                            id="CTR_CLIENTE_NOME"
                                            placeholder="Digite para buscar e selecione o cliente da lista"
                                            autocomplete="off"
                                            required>

                                        <div id="autocompleteCliente"
                                            class="list-group position-absolute w-100 shadow-sm d-none"
                                            style="z-index: 1065; max-height: 260px; overflow-y: auto; top: 100%;">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small">Empresa gestora *</label>
                                        <select class="form-select form-select-sm" id="CTR_EMPRESA_ID" required>
                                            <option value="0">Carregando...</option>
                                        </select>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small">Tipo</label>
                                        <select class="form-select form-select-sm" id="CTR_TIPO">
                                            <option value="RECORRENTE">Recorrente</option>
                                            <option value="PARCELADO">Parcelado</option>
                                            <option value="UNICO">Único</option>
                                        </select>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small">Status</label>
                                        <select class="form-select form-select-sm" id="CTR_STATUS">
                                            <option value="ATIVO">Ativo</option>
                                            <option value="SUSPENSO">Suspenso</option>
                                            <option value="ENCERRADO">Encerrado</option>
                                        </select>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small">Início *</label>
                                        <input type="date" class="form-control form-control-sm" id="CTR_DT_INICIO" required>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small">Fim</label>
                                        <input type="date" class="form-control form-control-sm" id="CTR_DT_FIM">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small">Valor mensal *</label>
                                        <input type="text"
                                            class="form-control form-control-sm"
                                            id="CTR_VALOR_MENSAL"
                                            placeholder="0,00"
                                            inputmode="numeric"
                                            autocomplete="off">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small">Dia vencimento (1..31)</label>
                                        <input type="number" class="form-control form-control-sm" id="CTR_DIA_VENCIMENTO" min="1" max="31" value="10">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small">Reajuste</label>
                                        <select class="form-select form-select-sm" id="CTR_REAJUSTE">
                                            <option value="NENHUM">Nenhum</option>
                                            <option value="ANUAL">Anual</option>
                                            <option value="MENSAL">Mensal</option>
                                        </select>
                                    </div>

                                    <div class="col-md-12">
                                        <label class="form-label small">Texto do reajuste</label>
                                        <input type="text" class="form-control form-control-sm" id="CTR_REAJUSTE_TEXTO" placeholder="Ex: IGP-M, IPCA, cláusula 6...">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label small">Descrição</label>
                                        <textarea class="form-control form-control-sm" id="CTR_DESCRICAO" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="tab-ctr-fin" role="tabpanel">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label small">Banco (cobrança / boleto) *</label>
                                        <select class="form-select form-select-sm" id="CTR_BANCO" required>
                                            <option value="">Carregando...</option>
                                        </select>
                                        <div class="help-mini mt-1">Carteira/Dias tolerância serão usados a partir do cadastro do banco.</div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small">Convênio</label>
                                        <input type="text" class="form-control form-control-sm" id="CTR_CONVENIO">
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small">Agência</label>
                                        <input type="text" class="form-control form-control-sm" id="CTR_AGENCIA">
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small">Conta</label>
                                        <input type="text" class="form-control form-control-sm" id="CTR_CONTA">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small">Plano de contas <span class="text-danger" title="Obrigatório quando Gerar parcelas = Sim">*</span></label>
                                        <select class="form-select form-select-sm" id="CTR_PLANO_CONTAS">
                                            <option value="">Carregando...</option>
                                        </select>
                                        <div class="help-mini mt-1">Define a classificação contábil padrão das parcelas geradas.</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small">Centro de custo <span class="text-danger" title="Obrigatório quando Gerar parcelas = Sim">*</span></label>
                                        <select class="form-select form-select-sm" id="CTR_CENTRO_CUSTO">
                                            <option value="">Carregando...</option>
                                        </select>
                                        <div class="help-mini mt-1">Define o centro de custo padrão das parcelas geradas.</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small">Referência interna</label>
                                        <input type="text" class="form-control form-control-sm" id="CTR_REFERENCIA_INTERNA">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small">Gerar parcelas ao salvar?</label>
                                        <select class="form-select form-select-sm" id="GERAR_PARCELAS">
                                            <option value="SIM">Sim</option>
                                            <option value="NAO">Não</option>
                                        </select>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small">Qtd parcelas (padrão 12)</label>
                                        <input type="number" class="form-control form-control-sm" id="QTD_PARCELAS" min="1" max="120" value="12">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label small">Observação financeira</label>
                                        <textarea class="form-control form-control-sm" id="CTR_OBS_FINANCEIRA" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="tab-ctr-obs" role="tabpanel">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label small">Observação interna</label>
                                        <textarea class="form-control form-control-sm" id="CTR_OBSERVACAO_INTERNA" rows="4"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnSalvarContrato">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- Modal log de exclusões -->
    <div class="modal fade" id="modalLogExclusoes" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xxl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Histórico de exclusões</h5>
                        <div class="help-mini">Auditoria de contratos excluídos com usuário logado, admin autorizador e data/hora.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <form id="frmFiltrosLogExclusao" class="row g-2 align-items-end mb-3">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Buscar</label>
                            <input type="text" class="form-control form-control-sm" id="fLogBuscar" placeholder="Contrato, cliente, admin, usuário..." />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Data inicial</label>
                            <input type="date" class="form-control form-control-sm" id="fLogDataIni" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Data final</label>
                            <input type="date" class="form-control form-control-sm" id="fLogDataFim" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Usuário</label>
                            <input type="text" class="form-control form-control-sm" id="fLogUsuario" placeholder="Quem excluiu" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Admin autorizador</label>
                            <input type="text" class="form-control form-control-sm" id="fLogAdmin" placeholder="E-mail ou nome" />
                        </div>
                        <div class="col-md-1 text-end">
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button>
                        </div>
                    </form>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted">Registros encontrados</span>
                        <span class="small text-muted" id="lblTotalLogsExclusao">—</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Data</th>
                                    <th>Contrato</th>
                                    <th>Cliente</th>
                                    <th>Usuário logado</th>
                                    <th>Admin autorizador</th>
                                    <th class="text-end">Parcelas</th>
                                    <th class="text-end">Contas Rec.</th>
                                    <th class="text-end">Valor</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="tbLogExclusoes">
                                <tr>
                                    <td colspan="10" class="text-muted small">Carregando...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>


    <!-- Modal log de suspensões -->
    <div class="modal fade" id="modalLogSuspensoes" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xxl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Histórico de suspensões</h5>
                        <div class="help-mini">Auditoria de contratos suspensos com usuário, data/hora e se foram removidas parcelas em aberto.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <form id="frmFiltrosLogSuspensao" class="row g-2 align-items-end mb-3">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Buscar</label>
                            <input type="text" class="form-control form-control-sm" id="fLogSuspBuscar" placeholder="Contrato, cliente, usuário..." />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Data inicial</label>
                            <input type="date" class="form-control form-control-sm" id="fLogSuspDataIni" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Data final</label>
                            <input type="date" class="form-control form-control-sm" id="fLogSuspDataFim" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Usuário</label>
                            <input type="text" class="form-control form-control-sm" id="fLogSuspUsuario" placeholder="Quem suspendeu" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Removeu parcelas?</label>
                            <select class="form-select form-select-sm" id="fLogSuspModo">
                                <option value="">Todos</option>
                                <option value="SIM">Sim</option>
                                <option value="NAO">Não</option>
                            </select>
                        </div>
                        <div class="col-md-1 text-end">
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button>
                        </div>
                    </form>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted">Registros encontrados</span>
                        <span class="small text-muted" id="lblTotalLogsSuspensao">—</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Data</th>
                                    <th>Contrato</th>
                                    <th>Cliente</th>
                                    <th>Usuário</th>
                                    <th>Removeu?</th>
                                    <th class="text-end">Parcelas</th>
                                    <th class="text-end">Contas Rec.</th>
                                    <th class="text-end">Valor</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="tbLogSuspensoes">
                                <tr>
                                    <td colspan="10" class="text-muted small">Carregando...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>


    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        const ENDPOINT = 'endpoints/contratos.php';

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

        async function apiCombo(url) {
            const r = await fetch(url, {
                method: 'GET'
            });
            const txt = await r.text();

            let j;
            try {
                j = JSON.parse(txt);
            } catch (e) {
                console.error('NÃO JSON (combo):', txt);
                throw new Error('Combo não retornou JSON (veja o console).');
            }

            if (!j.ok) throw new Error(j.msg || 'Falha ao carregar combo.');
            return j;
        }

        const modalContrato = new bootstrap.Modal(document.getElementById('modalContrato'));
        const modalLogExclusoes = new bootstrap.Modal(document.getElementById('modalLogExclusoes'));
        const modalLogSuspensoes = new bootstrap.Modal(document.getElementById('modalLogSuspensoes'));
        const safe = v => (v ?? '').toString();

        function badgeStatus(s) {
            if (s === 'ATIVO') return '<span class="badge-soft-success">ATIVO</span>';
            if (s === 'SUSPENSO') return '<span class="badge-soft-warning">SUSPENSO</span>';
            return '<span class="badge-soft-danger">ENCERRADO</span>';
        }

        let combosFinanceirosCarregados = false;
        let _plcPend = '';
        let _ccPend = '';
        let _bancoPend = '';
        let clienteTimer = null;

        function setSelectOptions(sel, items, {
            valueKey,
            labelKey,
            firstLabel
        }) {
            sel.innerHTML = '';
            const opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = firstLabel || '(não definido)';
            sel.appendChild(opt0);

            (items || []).forEach(it => {
                const v = (it[valueKey] ?? '').toString();
                const t = (it[labelKey] ?? '').toString();
                if (!v) return;

                const opt = document.createElement('option');
                opt.value = v;
                opt.textContent = t || v;
                sel.appendChild(opt);
            });
        }

        function normalizarBancos(rows) {
            return (Array.isArray(rows) ? rows : [])
                .filter(r => r.BAN_ID)
                .map(r => ({
                    ID: String(r.BAN_ID),
                    LABEL: r.BAN_LABEL || r.BAN_APELIDO || r.BAN_NOME || String(r.BAN_ID)
                }));
        }

        function aplicarPendenciasFinanceiras() {
            const selPLC = document.getElementById('CTR_PLANO_CONTAS');
            const selCC = document.getElementById('CTR_CENTRO_CUSTO');
            const selBanco = document.getElementById('CTR_BANCO');

            if (selPLC && _plcPend !== '') {
                selPLC.value = _plcPend;
                _plcPend = '';
            }

            if (selCC && _ccPend !== '') {
                selCC.value = _ccPend;
                _ccPend = '';
            }

            if (selBanco && _bancoPend !== '') {
                selBanco.value = _bancoPend;
                _bancoPend = '';
            }
        }

        async function carregarCombosFinanceiros(force = false) {
            if (combosFinanceirosCarregados && !force) return;

            const selPLC = document.getElementById('CTR_PLANO_CONTAS');
            const selCC = document.getElementById('CTR_CENTRO_CUSTO');
            const selBanco = document.getElementById('CTR_BANCO');
            if (!selPLC || !selCC || !selBanco) return;

            selPLC.innerHTML = '<option value="">Carregando...</option>';
            selCC.innerHTML = '<option value="">Carregando...</option>';
            selBanco.innerHTML = '<option value="">Carregando...</option>';

            const urlPLC = 'endpoints/plano_contas.php?acao=combo';
            const urlCC = 'endpoints/centros_custo.php?acao=combo';
            const urlBancos = 'endpoints/bancos.php?acao=combo&_=' + Date.now();

            const [plc, cc, bancos] = await Promise.all([
                apiCombo(urlPLC),
                apiCombo(urlCC),
                apiCombo(urlBancos)
            ]);

            setSelectOptions(selPLC, plc.rows || [], {
                valueKey: 'PLC_ID',
                labelKey: 'PLC_LABEL',
                firstLabel: '(não definido)'
            });

            setSelectOptions(selCC, cc.rows || [], {
                valueKey: 'CEC_ID',
                labelKey: 'CEC_LABEL',
                firstLabel: '(não definido)'
            });

            setSelectOptions(selBanco, normalizarBancos(bancos.rows || []), {
                valueKey: 'ID',
                labelKey: 'LABEL',
                firstLabel: '(não definido)'
            });

            combosFinanceirosCarregados = true;
            aplicarPendenciasFinanceiras();
        }

        function limparForm() {
            document.getElementById('frmContrato').reset();
            document.getElementById('CTR_ID').value = '';
            document.getElementById('CTR_CLIENTE_ID').value = '0';
            document.getElementById('CTR_CLIENTE_NOME').value = '';
            document.getElementById('CTR_STATUS').value = 'ATIVO';
            document.getElementById('CTR_TIPO').value = 'RECORRENTE';
            document.getElementById('GERAR_PARCELAS').value = 'SIM';
            document.getElementById('QTD_PARCELAS').value = '12';
            document.getElementById('CTR_VALOR_MENSAL').value = '0,00';

            const ac = document.getElementById('autocompleteCliente');
            ac.innerHTML = '';
            ac.classList.add('d-none');

            _plcPend = '';
            _ccPend = '';
            _bancoPend = '';

            document.getElementById('CTR_PLANO_CONTAS').innerHTML = '<option value="">Carregando...</option>';
            document.getElementById('CTR_CENTRO_CUSTO').innerHTML = '<option value="">Carregando...</option>';
        }

        function moedaBRParaDecimal(valor) {
            if (!valor) return 0;
            return parseFloat(String(valor).replace(/\./g, '').replace(',', '.')) || 0;
        }

        function decimalParaMoedaBR(valor) {
            const n = Number(valor || 0);
            return n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function getForm() {
            return {
                CTR_ID: document.getElementById('CTR_ID').value,
                CTR_CLIENTE_ID: document.getElementById('CTR_CLIENTE_ID').value,
                CTR_EMPRESA_ID: document.getElementById('CTR_EMPRESA_ID').value,
                CTR_TIPO: document.getElementById('CTR_TIPO').value,
                CTR_STATUS: document.getElementById('CTR_STATUS').value,
                CTR_VALOR_MENSAL: moedaBRParaDecimal(document.getElementById('CTR_VALOR_MENSAL').value),
                CTR_DIA_VENCIMENTO: document.getElementById('CTR_DIA_VENCIMENTO').value,
                CTR_DT_INICIO: document.getElementById('CTR_DT_INICIO').value,
                CTR_DT_FIM: document.getElementById('CTR_DT_FIM').value,
                CTR_BANCO: document.getElementById('CTR_BANCO').value,
                CTR_CONVENIO: document.getElementById('CTR_CONVENIO').value.trim(),
                CTR_AGENCIA: document.getElementById('CTR_AGENCIA').value.trim(),
                CTR_CONTA: document.getElementById('CTR_CONTA').value.trim(),
                CTR_PLANO_CONTAS: document.getElementById('CTR_PLANO_CONTAS').value,
                CTR_CENTRO_CUSTO: document.getElementById('CTR_CENTRO_CUSTO').value,
                CTR_REFERENCIA_INTERNA: document.getElementById('CTR_REFERENCIA_INTERNA').value.trim(),
                CTR_REAJUSTE: document.getElementById('CTR_REAJUSTE').value,
                CTR_REAJUSTE_TEXTO: document.getElementById('CTR_REAJUSTE_TEXTO').value.trim(),
                CTR_DESCRICAO: document.getElementById('CTR_DESCRICAO').value.trim(),
                CTR_OBS_FINANCEIRA: document.getElementById('CTR_OBS_FINANCEIRA').value.trim(),
                CTR_OBSERVACAO_INTERNA: document.getElementById('CTR_OBSERVACAO_INTERNA').value.trim(),
                GERAR_PARCELAS: document.getElementById('GERAR_PARCELAS').value,
                QTD_PARCELAS: document.getElementById('QTD_PARCELAS').value
            };
        }

        function setForm(u) {
            document.getElementById('CTR_ID').value = u.CTR_ID || '';
            document.getElementById('CTR_CLIENTE_ID').value = u.CTR_CLIENTE_ID || '0';
            document.getElementById('CTR_CLIENTE_NOME').value = u.CLIENTE_NOME || u.CLI_NOME_RAZAO || '';
            document.getElementById('CTR_EMPRESA_ID').value = u.CTR_EMPRESA_ID || '0';
            document.getElementById('CTR_TIPO').value = u.CTR_TIPO || 'RECORRENTE';
            document.getElementById('CTR_STATUS').value = u.CTR_STATUS || 'ATIVO';

            const valor = parseFloat(u.CTR_VALOR_MENSAL || 0).toFixed(2).replace('.', ',');
            document.getElementById('CTR_VALOR_MENSAL').value = valor;

            document.getElementById('CTR_DIA_VENCIMENTO').value = u.CTR_DIA_VENCIMENTO || 10;
            document.getElementById('CTR_DT_INICIO').value = u.CTR_DT_INICIO || '';
            document.getElementById('CTR_DT_FIM').value = u.CTR_DT_FIM || '';

            _bancoPend = (u.CTR_BANCO_FK ?? '').toString();
            _plcPend = (u.CTR_PLANO_CONTAS ?? '').toString();
            _ccPend = (u.CTR_CENTRO_CUSTO ?? '').toString();

            document.getElementById('CTR_CONVENIO').value = u.CTR_CONVENIO || '';
            document.getElementById('CTR_AGENCIA').value = u.CTR_AGENCIA || '';
            document.getElementById('CTR_CONTA').value = u.CTR_CONTA || '';
            document.getElementById('CTR_REFERENCIA_INTERNA').value = u.CTR_REFERENCIA_INTERNA || '';
            document.getElementById('CTR_REAJUSTE').value = u.CTR_REAJUSTE || 'NENHUM';
            document.getElementById('CTR_REAJUSTE_TEXTO').value = u.CTR_REAJUSTE_TEXTO || '';
            document.getElementById('CTR_DESCRICAO').value = u.CTR_DESCRICAO || '';
            document.getElementById('CTR_OBS_FINANCEIRA').value = u.CTR_OBS_FINANCEIRA || '';
            document.getElementById('CTR_OBSERVACAO_INTERNA').value = u.CTR_OBSERVACAO_INTERNA || '';
        }

        async function carregarCombos() {
            const emp = await api({
                acao: 'combo_empresas'
            }, 'GET');

            const fEmp = document.getElementById('fEmpresa');
            fEmp.innerHTML = '<option value="0">Todas</option>';

            const selEmp = document.getElementById('CTR_EMPRESA_ID');
            selEmp.innerHTML = '<option value="0">Selecione...</option>';

            (emp.rows || []).forEach(e => {
                const optFiltro = document.createElement('option');
                optFiltro.value = e.EMP_ID;
                optFiltro.textContent = e.EMP_NOME;
                fEmp.appendChild(optFiltro);

                const optModal = document.createElement('option');
                optModal.value = e.EMP_ID;
                optModal.textContent = e.EMP_NOME;
                selEmp.appendChild(optModal);
            });
        }

        async function listar() {
            const buscar = document.getElementById('fBuscar').value.trim();
            const status = document.getElementById('fStatus').value;
            const tipo = document.getElementById('fTipo').value;
            const empresaId = document.getElementById('fEmpresa').value;
            const parcelas = document.getElementById('fParcelas')?.value || '';

            const j = await api({
                acao: 'listar',
                buscar,
                status,
                tipo,
                empresaId,
                parcelas
            }, 'GET');

            const tb = document.getElementById('tbContratos');
            tb.innerHTML = '';
            document.getElementById('lblTotal').textContent = `${j.total} registro(s)`;

            if (!j.rows || j.rows.length === 0) {
                tb.innerHTML = '<tr><td colspan="8" class="text-muted small">Nenhum contrato encontrado.</td></tr>';
                return;
            }

            j.rows.forEach((r, i) => {
                const btnDet = `<a class="btn btn-sm btn-outline-secondary me-1" title="Detalhes" href="contrato_detalhe.php?id=${r.CTR_ID}"><i class="fa-solid fa-eye"></i></a>`;
                const btnEd = `<button class="btn btn-sm btn-outline-primary me-1" title="Editar" data-id="${r.CTR_ID}" data-act="editar"><i class="fa-solid fa-pen"></i></button>`;
                const btnEx = `<button class="btn btn-sm btn-outline-danger me-1" title="Excluir" data-id="${r.CTR_ID}" data-cliente="${encodeURIComponent(safe(r.CLIENTE_NOME))}" data-act="excluir"><i class="fa-solid fa-trash"></i></button>`;
                const btnSt = r.CTR_STATUS === 'ATIVO' ?
                    `<button class="btn btn-sm btn-outline-warning" title="Suspender" data-id="${r.CTR_ID}" data-act="suspender"><i class="fa-solid fa-circle-pause"></i></button>` :
                    `<button class="btn btn-sm btn-outline-success" title="Reativar" data-id="${r.CTR_ID}" data-act="reativar"><i class="fa-solid fa-play"></i></button>`;

                tb.insertAdjacentHTML('beforeend', `
                    <tr>
                        <td>${i + 1}</td>
                        <td>${safe(r.CTR_ID)}</td>
                        <td>${safe(r.CLIENTE_NOME)}</td>
                        <td>${safe(r.EMPRESA_NOME)}</td>
                        <td class="text-end">R$ ${decimalParaMoedaBR(r.CTR_VALOR_MENSAL || 0)}</td>
                        <td>${safe(r.CTR_DT_INICIO)}</td>
                        <td>${badgeStatus(r.CTR_STATUS)}</td>
                        <td class="text-end">${btnDet}${btnEd}${btnEx}${btnSt}</td>
                    </tr>
                `);
            });
        }

        async function abrirNovo() {
            limparForm();
            document.getElementById('modalContratoTitulo').textContent = 'Novo contrato';
            await carregarCombosFinanceiros(true);
            modalContrato.show();
        }

        async function abrirEditar(id) {
            const j = await api({
                acao: 'get',
                id
            }, 'GET');

            limparForm();
            setForm(j.row);
            await carregarCombosFinanceiros(true);
            aplicarPendenciasFinanceiras();

            document.getElementById('modalContratoTitulo').textContent = `Editar contrato #${j.row.CTR_ID}`;
            modalContrato.show();
        }

        async function salvar() {
            const d = getForm();

            if (Number(d.CTR_CLIENTE_ID) <= 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Selecione um cliente.'
                });
                document.getElementById('CTR_CLIENTE_NOME').focus();
                return;
            }

            if (Number(d.CTR_EMPRESA_ID) <= 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Selecione a empresa gestora.'
                });
                document.getElementById('CTR_EMPRESA_ID').focus();
                return;
            }

            if (!d.CTR_DT_INICIO) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Informe a data de início do contrato.'
                });
                document.getElementById('CTR_DT_INICIO').focus();
                return;
            }

            if (!d.CTR_VALOR_MENSAL || Number(d.CTR_VALOR_MENSAL) <= 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Informe o valor mensal do contrato.'
                });
                document.getElementById('CTR_VALOR_MENSAL').focus();
                return;
            }

            if (Number(d.CTR_DIA_VENCIMENTO) < 1 || Number(d.CTR_DIA_VENCIMENTO) > 31) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'O dia de vencimento deve estar entre 1 e 31.'
                });
                document.getElementById('CTR_DIA_VENCIMENTO').focus();
                return;
            }

            if (!d.CTR_BANCO || Number(d.CTR_BANCO) <= 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Selecione o banco.'
                });
                document.getElementById('CTR_BANCO').focus();
                return;
            }

            try {
                await api({
                    acao: 'salvar',
                    ...d
                }, 'POST');
                modalContrato.hide();

                Swal.fire({
                    icon: 'success',
                    title: 'Ok',
                    text: 'Contrato salvo!',
                    timer: 900,
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

        async function suspender(id) {
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

            if (r.isDismissed) {
                excluindoContratoAgora = false;
                return;
            }

            const removerParcelas = r.isConfirmed ? 'SIM' : 'NAO';

            const resp = await api({
                acao: 'suspender',
                id,
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

            await listar();
            excluindoContratoAgora = false;
        }

        async function reativar(id) {
            const r = await Swal.fire({
                icon: 'question',
                title: 'Reativar contrato?',
                showCancelButton: true,
                confirmButtonText: 'Sim',
                cancelButtonText: 'Cancelar'
            });

            if (!r.isConfirmed) return;

            await api({
                acao: 'reativar',
                id
            }, 'POST');

            Swal.fire({
                icon: 'success',
                title: 'Ok',
                text: 'Contrato reativado.',
                timer: 900,
                showConfirmButton: false
            });

            await listar();
            excluindoContratoAgora = false;
        }

        let excluindoContratoAgora = false;

        async function excluirContrato(id, clienteNome = '') {
            if (excluindoContratoAgora) return;
            excluindoContratoAgora = true;

            const nomeDecodificado = decodeURIComponent(clienteNome || '');

            const confirm = await Swal.fire({
                icon: 'warning',
                title: 'Excluir contrato?',
                html: `
                    <div class="text-start small">
                        <div><strong>Contrato:</strong> #${id}</div>
                        <div><strong>Cliente:</strong> ${nomeDecodificado || '-'}</div>
                        <hr>
                        <div class="text-danger"><strong>Atenção:</strong> esta ação excluirá o contrato, as parcelas e os lançamentos vinculados no contas a receber.</div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Continuar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc3545'
            });

            if (!confirm.isConfirmed) {
                excluindoContratoAgora = false;
                return;
            }

            const senhaStep = await Swal.fire({
                icon: 'question',
                title: 'Autorização ADMIN',
                html: `
                    <div class="text-start small mb-2">Informe o <strong>e-mail</strong> e a <strong>senha</strong> de qualquer perfil <strong>ADMIN</strong> para autorizar a exclusão.</div>
                    <input type="email" id="swalUsuarioAdmin" class="swal2-input" placeholder="E-mail do ADMIN" autocomplete="off">
                    <input type="password" id="swalSenhaMaster" class="swal2-input" placeholder="Senha do ADMIN" autocomplete="off">
                `,
                focusConfirm: false,
                showCancelButton: true,
                confirmButtonText: 'Excluir contrato',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc3545',
                showLoaderOnConfirm: true,
                allowOutsideClick: () => !Swal.isLoading(),
                allowEscapeKey: () => !Swal.isLoading(),
                preConfirm: async () => {
                    const usuario = document.getElementById('swalUsuarioAdmin').value || '';
                    const senha = document.getElementById('swalSenhaMaster').value || '';

                    if (!usuario.trim()) {
                        Swal.showValidationMessage('Informe o e-mail do ADMIN.');
                        return false;
                    }

                    if (!senha.trim()) {
                        Swal.showValidationMessage('Informe a senha do ADMIN.');
                        return false;
                    }

                    try {
                        return await api({
                            acao: 'excluir',
                            id,
                            usuario_admin: usuario,
                            senha_master: senha
                        }, 'POST');
                    } catch (e) {
                        Swal.showValidationMessage(e.message || 'Não foi possível excluir o contrato.');
                        return false;
                    }
                }
            });

            if (!senhaStep.isConfirmed || !senhaStep.value) {
                excluindoContratoAgora = false;
                return;
            }

            const resposta = senhaStep.value;

            await Swal.fire({
                icon: 'success',
                title: 'Excluído',
                html: `
                    <div class="text-start small">
                        <div>${resposta.msg || 'Contrato excluído com sucesso.'}</div>
                        <div class="mt-2"><strong>Parcelas excluídas:</strong> ${resposta.parcelas_excluidas ?? 0}</div>
                        <div><strong>Contas a receber excluídas:</strong> ${resposta.contas_receber_excluidas ?? 0}</div>
                    </div>
                `
            });

            await listar();
            excluindoContratoAgora = false;
        }


        function formatarDataHoraBR(v) {
            if (!v) return '-';
            const s = String(v).replace(' ', 'T');
            const d = new Date(s);
            if (!isNaN(d.getTime())) {
                return d.toLocaleString('pt-BR');
            }
            return safe(v);
        }

        async function carregarLogsExclusao() {
            const buscar = document.getElementById('fLogBuscar').value.trim();
            const data_ini = document.getElementById('fLogDataIni').value;
            const data_fim = document.getElementById('fLogDataFim').value;
            const usuario = document.getElementById('fLogUsuario').value.trim();
            const admin = document.getElementById('fLogAdmin').value.trim();

            const j = await api({
                acao: 'log_exclusoes_listar',
                buscar,
                data_ini,
                data_fim,
                usuario,
                admin
            }, 'GET');

            const tb = document.getElementById('tbLogExclusoes');
            tb.innerHTML = '';
            document.getElementById('lblTotalLogsExclusao').textContent = `${j.total || 0} registro(s)`;

            if (!j.rows || j.rows.length === 0) {
                tb.innerHTML = '<tr><td colspan="10" class="text-muted small">Nenhum log encontrado.</td></tr>';
                return;
            }

            j.rows.forEach((r, i) => {
                const payload = encodeURIComponent(JSON.stringify(r));
                tb.insertAdjacentHTML('beforeend', `
                    <tr>
                        <td>${i + 1}</td>
                        <td>${formatarDataHoraBR(r.LOG_DATA)}</td>
                        <td>#${safe(r.LOG_CTR_ID)}</td>
                        <td>${safe(r.LOG_CLIENTE_NOME)}</td>
                        <td>${safe(r.LOG_USUARIO_NOME)}</td>
                        <td>
                            <div>${safe(r.LOG_ADMIN_AUTORIZADOR_NOME)}</div>
                            <div class="small text-muted">${safe(r.LOG_ADMIN_AUTORIZADOR_EMAIL)}</div>
                        </td>
                        <td class="text-end">${safe(r.LOG_PARCELAS_EXCLUIDAS)}</td>
                        <td class="text-end">${safe(r.LOG_CRE_EXCLUIDAS)}</td>
                        <td class="text-end">R$ ${decimalParaMoedaBR(r.LOG_VALOR || 0)}</td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-act="ver-log-exclusao" data-row="${payload}">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                `);
            });
        }

        async function carregarLogsSuspensao() {
            const buscar = document.getElementById('fLogSuspBuscar').value.trim();
            const data_ini = document.getElementById('fLogSuspDataIni').value;
            const data_fim = document.getElementById('fLogSuspDataFim').value;
            const usuario = document.getElementById('fLogSuspUsuario').value.trim();
            const modo = document.getElementById('fLogSuspModo').value;

            const j = await api({
                acao: 'log_suspensoes_listar',
                buscar,
                data_ini,
                data_fim,
                usuario,
                modo
            }, 'GET');

            const tb = document.getElementById('tbLogSuspensoes');
            tb.innerHTML = '';
            document.getElementById('lblTotalLogsSuspensao').textContent = `${j.total || 0} registro(s)`;

            if (!j.rows || j.rows.length === 0) {
                tb.innerHTML = '<tr><td colspan="10" class="text-muted small">Nenhum log encontrado.</td></tr>';
                return;
            }

            j.rows.forEach((r, i) => {
                const payload = encodeURIComponent(JSON.stringify(r));
                const modoBadge = r.LOG_REMOVER_PARCELAS === 'SIM'
                    ? '<span class="badge-soft-danger">Sim</span>'
                    : '<span class="badge-soft-success">Não</span>';

                tb.insertAdjacentHTML('beforeend', `
                    <tr>
                        <td>${i + 1}</td>
                        <td>${formatarDataHoraBR(r.LOG_DATA)}</td>
                        <td>#${safe(r.LOG_CTR_ID)}</td>
                        <td>${safe(r.LOG_CLIENTE_NOME)}</td>
                        <td>${safe(r.LOG_USUARIO_NOME)}</td>
                        <td>${modoBadge}</td>
                        <td class="text-end">${safe(r.LOG_PARCELAS_REMOVIDAS)}</td>
                        <td class="text-end">${safe(r.LOG_CRE_REMOVIDAS)}</td>
                        <td class="text-end">R$ ${decimalParaMoedaBR(r.LOG_VALOR || 0)}</td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-act="ver-log-suspensao" data-row="${payload}">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                `);
            });
        }

        async function abrirLogSuspensoes() {
            document.getElementById('tbLogSuspensoes').innerHTML = '<tr><td colspan="10" class="text-muted small">Carregando...</td></tr>';
            document.getElementById('lblTotalLogsSuspensao').textContent = '—';
            modalLogSuspensoes.show();
            await carregarLogsSuspensao();
        }

        async function verDetalheLogSuspensao(rowEnc) {
            let row = {};
            try {
                row = JSON.parse(decodeURIComponent(rowEnc || ''));
            } catch (e) {}

            const modoTxt = row.LOG_REMOVER_PARCELAS === 'SIM'
                ? '<span class="text-danger"><strong>Sim</strong> — parcelas/títulos em aberto foram removidos</span>'
                : '<span class="text-success"><strong>Não</strong> — parcelas em aberto foram mantidas</span>';

            await Swal.fire({
                icon: 'info',
                title: `Suspensão do contrato #${row.LOG_CTR_ID || ''}`,
                width: 760,
                html: `
                    <div class="text-start small">
                        <div class="row g-2">
                            <div class="col-md-6"><strong>Data:</strong><br>${formatarDataHoraBR(row.LOG_DATA)}</div>
                            <div class="col-md-6"><strong>Cliente:</strong><br>${safe(row.LOG_CLIENTE_NOME) || '-'}</div>
                            <div class="col-md-6"><strong>Usuário:</strong><br>${safe(row.LOG_USUARIO_NOME) || '-'}</div>
                            <div class="col-md-6"><strong>Removeu parcelas?</strong><br>${modoTxt}</div>
                            <div class="col-md-4"><strong>Contrato:</strong><br>#${safe(row.LOG_CTR_ID)}</div>
                            <div class="col-md-4"><strong>Empresa:</strong><br>${safe(row.LOG_EMPRESA_ID)}</div>
                            <div class="col-md-4"><strong>Valor mensal:</strong><br>R$ ${decimalParaMoedaBR(row.LOG_VALOR || 0)}</div>
                            <div class="col-md-4"><strong>Parcelas removidas:</strong><br>${safe(row.LOG_PARCELAS_REMOVIDAS)}</div>
                            <div class="col-md-4"><strong>Contas a receber removidas:</strong><br>${safe(row.LOG_CRE_REMOVIDAS)}</div>
                            <div class="col-md-4"><strong>IP:</strong><br>${safe(row.LOG_IP) || '-'}</div>
                            <div class="col-12"><strong>User Agent:</strong><br><span class="text-muted">${safe(row.LOG_USER_AGENT) || '-'}</span></div>
                        </div>
                    </div>
                `
            });
        }

        async function abrirLogExclusoes() {
            document.getElementById('tbLogExclusoes').innerHTML = '<tr><td colspan="10" class="text-muted small">Carregando...</td></tr>';
            document.getElementById('lblTotalLogsExclusao').textContent = '—';
            modalLogExclusoes.show();
            await carregarLogsExclusao();
        }

        async function verDetalheLogExclusao(rowEnc) {
            let row = {};
            try {
                row = JSON.parse(decodeURIComponent(rowEnc || ''));
            } catch (e) {}

            await Swal.fire({
                icon: 'info',
                title: `Exclusão do contrato #${row.LOG_CTR_ID || ''}`,
                width: 760,
                html: `
                    <div class="text-start small">
                        <div class="row g-2">
                            <div class="col-md-6"><strong>Data:</strong><br>${formatarDataHoraBR(row.LOG_DATA)}</div>
                            <div class="col-md-6"><strong>Cliente:</strong><br>${safe(row.LOG_CLIENTE_NOME) || '-'}</div>
                            <div class="col-md-6"><strong>Usuário logado:</strong><br>${safe(row.LOG_USUARIO_NOME) || '-'}</div>
                            <div class="col-md-6"><strong>Admin autorizador:</strong><br>${safe(row.LOG_ADMIN_AUTORIZADOR_NOME) || '-'}<br><span class="text-muted">${safe(row.LOG_ADMIN_AUTORIZADOR_EMAIL) || ''}</span></div>
                            <div class="col-md-4"><strong>Contrato:</strong><br>#${safe(row.LOG_CTR_ID)}</div>
                            <div class="col-md-4"><strong>Empresa:</strong><br>${safe(row.LOG_EMPRESA_ID)}</div>
                            <div class="col-md-4"><strong>Valor:</strong><br>R$ ${decimalParaMoedaBR(row.LOG_VALOR || 0)}</div>
                            <div class="col-md-4"><strong>Parcelas excluídas:</strong><br>${safe(row.LOG_PARCELAS_EXCLUIDAS)}</div>
                            <div class="col-md-4"><strong>Contas a receber excluídas:</strong><br>${safe(row.LOG_CRE_EXCLUIDAS)}</div>
                            <div class="col-md-4"><strong>IP:</strong><br>${safe(row.LOG_IP) || '-'}</div>
                            <div class="col-12"><strong>User Agent:</strong><br><span class="text-muted">${safe(row.LOG_USER_AGENT) || '-'}</span></div>
                        </div>
                    </div>
                `
            });
        }

        function esconderAutocompleteCliente() {
            const box = document.getElementById('autocompleteCliente');
            box.classList.add('d-none');
            box.innerHTML = '';
        }

        function selecionarCliente(item) {
            document.getElementById('CTR_CLIENTE_ID').value = item.CLI_ID || 0;
            document.getElementById('CTR_CLIENTE_NOME').value = item.CLI_NOME_RAZAO || '';
            esconderAutocompleteCliente();
        }

        function renderAutocompleteCliente(rows) {
            const box = document.getElementById('autocompleteCliente');

            if (!rows || rows.length === 0) {
                box.innerHTML = '<div class="list-group-item small text-muted">Nenhum cliente encontrado.</div>';
                box.classList.remove('d-none');
                return;
            }

            box.innerHTML = rows.map(item => {
                const nome = (item.CLI_NOME_RAZAO || '').toString();
                const doc = (item.CLI_DOCUMENTO || '').toString();
                return `
                    <button type="button"
                            class="list-group-item list-group-item-action autocomplete-item"
                            data-id="${item.CLI_ID}"
                            data-nome="${nome.replace(/"/g, '&quot;')}">
                        <div><strong>${nome}</strong></div>
                        <div class="small text-muted">${doc || 'Sem documento'}</div>
                    </button>
                `;
            }).join('');

            box.classList.remove('d-none');

            box.querySelectorAll('.autocomplete-item').forEach(btn => {
                btn.addEventListener('click', () => {
                    selecionarCliente({
                        CLI_ID: btn.dataset.id,
                        CLI_NOME_RAZAO: btn.dataset.nome
                    });
                });
            });
        }

        async function buscarClientesAutocomplete(q) {
            const j = await api({
                acao: 'combo_clientes',
                q: q || ''
            }, 'GET');
            renderAutocompleteCliente(j.rows || []);
        }

        function calcularQtdParcelas() {
            const dtInicio = document.getElementById('CTR_DT_INICIO');
            const dtFim = document.getElementById('CTR_DT_FIM');
            const qtdParcelas = document.getElementById('QTD_PARCELAS');
            const tipoContrato = document.getElementById('CTR_TIPO');

            const inicio = dtInicio.value;
            const fim = dtFim.value;

            if (tipoContrato.value === 'UNICO') {
                qtdParcelas.value = 1;
                return;
            }

            if (!inicio || !fim) return;

            const dataInicio = new Date(inicio + 'T00:00:00');
            const dataFim = new Date(fim + 'T00:00:00');

            if (dataFim < dataInicio) {
                qtdParcelas.value = 1;
                return;
            }

            let totalMeses = ((dataFim.getFullYear() - dataInicio.getFullYear()) * 12) +
                (dataFim.getMonth() - dataInicio.getMonth());

            if (dataFim.getDate() < dataInicio.getDate()) {
                totalMeses--;
            }

            qtdParcelas.value = Math.max(1, totalMeses || 1);
        }

        function aplicarMascaraMoeda(input) {
            let valor = input.value.replace(/\D/g, '');

            if (!valor) {
                input.value = '0,00';
                return;
            }

            valor = (parseInt(valor, 10) / 100).toFixed(2);
            valor = valor.replace('.', ',');
            valor = valor.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            input.value = valor;
        }

        document.getElementById('btnVerLogsExclusao').addEventListener('click', () => {
            abrirLogExclusoes().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('btnVerLogsSuspensao').addEventListener('click', () => {
            abrirLogSuspensoes().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('frmFiltrosLogSuspensao').addEventListener('submit', (e) => {
            e.preventDefault();
            carregarLogsSuspensao().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('tbLogSuspensoes').addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-act="ver-log-suspensao"]');
            if (!btn) return;

            verDetalheLogSuspensao(btn.dataset.row || '').catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('btnNovoContrato').addEventListener('click', () => {
            abrirNovo().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('btnSalvarContrato').addEventListener('click', () => {
            salvar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('frmFiltros').addEventListener('submit', (e) => {
            e.preventDefault();
            listar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('frmFiltrosLogExclusao').addEventListener('submit', (e) => {
            e.preventDefault();
            carregarLogsExclusao().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('tbContratos').addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-act]');
            if (!btn) return;

            const id = btn.dataset.id;
            const act = btn.dataset.act;

            if (act === 'editar') abrirEditar(id).catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
            if (act === 'suspender') suspender(id).catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
            if (act === 'reativar') reativar(id).catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
            if (act === 'excluir') excluirContrato(id, btn.dataset.cliente || '').catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('tbLogExclusoes').addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-act="ver-log-exclusao"]');
            if (!btn) return;

            verDetalheLogExclusao(btn.dataset.row || '').catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        const inpCliente = document.getElementById('CTR_CLIENTE_NOME');
        inpCliente.addEventListener('input', () => {
            const q = inpCliente.value.trim();
            document.getElementById('CTR_CLIENTE_ID').value = '0';
            clearTimeout(clienteTimer);

            if (q.length < 2) {
                esconderAutocompleteCliente();
                return;
            }

            clienteTimer = setTimeout(async () => {
                try {
                    await buscarClientesAutocomplete(q);
                } catch (e) {
                    console.error(e);
                    esconderAutocompleteCliente();
                }
            }, 250);
        });

        inpCliente.addEventListener('focus', () => {
            const q = inpCliente.value.trim();
            if (q.length < 2) return;

            clearTimeout(clienteTimer);
            clienteTimer = setTimeout(async () => {
                try {
                    await buscarClientesAutocomplete(q);
                } catch (e) {
                    console.error(e);
                }
            }, 150);
        });

        inpCliente.addEventListener('blur', () => {
            setTimeout(esconderAutocompleteCliente, 200);
        });

        document.getElementById('CTR_EMPRESA_ID').addEventListener('change', async () => {
            document.getElementById('CTR_PLANO_CONTAS').innerHTML = '<option value="">Carregando...</option>';
            document.getElementById('CTR_CENTRO_CUSTO').innerHTML = '<option value="">Carregando...</option>';
            _plcPend = '';
            _ccPend = '';

            try {
                await carregarCombosFinanceiros(true);
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: e.message
                });
            }
        });

        document.getElementById('CTR_DT_INICIO').addEventListener('change', calcularQtdParcelas);
        document.getElementById('CTR_DT_FIM').addEventListener('change', calcularQtdParcelas);
        document.getElementById('CTR_TIPO').addEventListener('change', calcularQtdParcelas);

        const valorMensal = document.getElementById('CTR_VALOR_MENSAL');
        valorMensal.addEventListener('input', function() {
            aplicarMascaraMoeda(this);
        });
        valorMensal.addEventListener('focus', function() {
            if (this.value.trim() === '') this.value = '0,00';
        });
        valorMensal.addEventListener('blur', function() {
            if (this.value.trim() === '') this.value = '0,00';
        });

        document.addEventListener('DOMContentLoaded', async () => {
            if (valorMensal.value.trim() === '') {
                valorMensal.value = '0,00';
            }

            try {
                await carregarCombos();
                await carregarCombosFinanceiros(true);
                await listar();
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: e.message
                });
            }
        });
    </script>
  <script src="assets/session_keeper.js" defer></script>
</body>

</html>