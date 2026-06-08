<?php
// /app/empresas.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Empresas</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .badge-soft-success {
            background: rgba(34, 197, 94, .12);
            color: #14532d;
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

        .modal-xxl {
            max-width: 92vw;
        }

        @media (min-width: 1200px) {
            .modal-xxl {
                max-width: 1100px;
            }
        }

        .nav-tabs .nav-link {
            border-radius: 12px 12px 0 0;
            font-weight: 600;
        }

        .tab-pane {
            padding-top: 14px;
        }

        .help-mini {
            font-size: .84rem;
            color: #64748b;
        }
    </style>
</head>

<body data-page="config">
    <div class="d-flex" id="wrapper">

        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">

            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Cadastro de Empresas</span>

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
                        <h5 class="mb-1 mt-1">Empresas (CNPJs)</h5>
                        <p class="help mb-0">
                            Cada empresa representa um CNPJ administrado no sistema. Os lançamentos e cadastros serão associados a uma empresa.
                        </p>
                    </div>
                    <div class="mt-2 mt-sm-0">
                        <button id="btnNovoEmpresa" type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEmpresa">
                            <i class="fa-solid fa-plus me-1"></i>Nova Empresa
                        </button>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card mb-3">
                    <div class="card-body py-3">
                        <form class="row g-2 align-items-end" id="frmFiltros">
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Buscar</label>
                                <input type="text" class="form-control form-control-sm" id="fBuscar"
                                    placeholder="Código, razão social, fantasia ou CNPJ..." />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Situação</label>
                                <select class="form-select form-select-sm" id="fStatus">
                                    <option value="">Todas</option>
                                    <option value="ATIVO">Ativa</option>
                                    <option value="INATIVO">Inativa</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Tipo</label>
                                <select class="form-select form-select-sm" id="fTipo">
                                    <option value="">Todos</option>
                                    <option value="MATRIZ">Matriz</option>
                                    <option value="FILIAL">Filial</option>
                                </select>
                            </div>
                            <div class="col-md-2 text-end">
                                <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Lista -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted">Empresas cadastradas</span>
                            <span class="small text-muted" id="lblTotal">—</span>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Cód.</th>
                                        <th>Razão Social</th>
                                        <th>Fantasia</th>
                                        <th>CNPJ</th>
                                        <th>Tipo</th>
                                        <th>Situação</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="tbEmpresas">
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

    <!-- Modal Criar/Editar -->
    <div class="modal fade" id="modalEmpresa" tabindex="-1" aria-labelledby="modalEmpresaLabel" aria-hidden="true">
        <div class="modal-dialog modal-xxl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modalEmpresaLabel">Cadastro de Empresa</h5>
                        <div class="help-mini">Preencha os dados e use as abas para informações fiscais e parâmetros.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <form id="frmEmpresa" autocomplete="off">
                        <input type="hidden" id="EMP_ID" name="EMP_ID" value="">

                        <!-- Tabs -->
                        <ul class="nav nav-tabs" id="tabsEmpresa" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tab-dados-tab" data-bs-toggle="tab" data-bs-target="#tab-dados"
                                    type="button" role="tab" aria-controls="tab-dados" aria-selected="true">
                                    <i class="fa-solid fa-id-card me-1"></i>Dados Cadastrais
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-endereco-tab" data-bs-toggle="tab" data-bs-target="#tab-endereco"
                                    type="button" role="tab" aria-controls="tab-endereco" aria-selected="false">
                                    <i class="fa-solid fa-location-dot me-1"></i>Endereço
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-fiscal-tab" data-bs-toggle="tab" data-bs-target="#tab-fiscal"
                                    type="button" role="tab" aria-controls="tab-fiscal" aria-selected="false">
                                    <i class="fa-solid fa-receipt me-1"></i>Fiscal / Tributário
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-param-tab" data-bs-toggle="tab" data-bs-target="#tab-param"
                                    type="button" role="tab" aria-controls="tab-param" aria-selected="false">
                                    <i class="fa-solid fa-sliders me-1"></i>Parâmetros Financeiros
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content" id="tabsEmpresaContent">

                            <!-- ABA 1: DADOS -->
                            <div class="tab-pane fade show active" id="tab-dados" role="tabpanel" aria-labelledby="tab-dados-tab">
                                <div class="row g-3">

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Código</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_CODIGO" name="EMP_CODIGO" placeholder="Opcional">
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label class="form-label small">Razão Social *</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_RAZAO_SOCIAL" name="EMP_RAZAO_SOCIAL" required>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Tipo</label>
                                        <select class="form-select form-select-sm" id="EMP_TIPO" name="EMP_TIPO">
                                            <option value="MATRIZ">Matriz</option>
                                            <option value="FILIAL">Filial</option>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label class="form-label small">Nome Fantasia</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_NOME_FANTASIA" name="EMP_NOME_FANTASIA">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">CNPJ *</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_CNPJ" name="EMP_CNPJ" required>
                                        <div class="help-mini mt-1">Validação ao sair do campo.</div>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Situação</label>
                                        <select class="form-select form-select-sm" id="EMP_STATUS" name="EMP_STATUS">
                                            <option value="ATIVO">Ativo</option>
                                            <option value="INATIVO">Inativo</option>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">E-mail</label>
                                        <input type="email" class="form-control form-control-sm" id="EMP_EMAIL" name="EMP_EMAIL">
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Telefone</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_TELEFONE" name="EMP_TELEFONE">
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Site</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_SITE" name="EMP_SITE" placeholder="https://">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label small">Observação</label>
                                        <textarea class="form-control form-control-sm" id="EMP_OBSERVACAO" name="EMP_OBSERVACAO" rows="2"
                                            placeholder="Anotações internas sobre a empresa..."></textarea>
                                    </div>

                                </div>
                            </div>

                            <!-- ABA 2: ENDEREÇO -->
                            <div class="tab-pane fade" id="tab-endereco" role="tabpanel" aria-labelledby="tab-endereco-tab">
                                <div class="row g-3">

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">CEP</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_CEP" name="EMP_CEP" placeholder="00000-000">
                                    </div>

                                    <div class="col-12 col-md-7">
                                        <label class="form-label small">Logradouro</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_LOGRADOURO" name="EMP_LOGRADOURO">
                                    </div>

                                    <div class="col-12 col-md-2">
                                        <label class="form-label small">Número</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_NUMERO" name="EMP_NUMERO">
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Complemento</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_COMPLEMENTO" name="EMP_COMPLEMENTO">
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Bairro</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_BAIRRO" name="EMP_BAIRRO">
                                    </div>

                                    <div class="col-12 col-md-2">
                                        <label class="form-label small">UF</label>
                                        <select class="form-select form-select-sm" id="EMP_UF" name="EMP_UF">
                                            <option value="">Selecione</option>
                                            <option value="AC">AC</option>
                                            <option value="AL">AL</option>
                                            <option value="AP">AP</option>
                                            <option value="AM">AM</option>
                                            <option value="BA">BA</option>
                                            <option value="CE">CE</option>
                                            <option value="DF">DF</option>
                                            <option value="ES">ES</option>
                                            <option value="GO">GO</option>
                                            <option value="MA">MA</option>
                                            <option value="MT">MT</option>
                                            <option value="MS">MS</option>
                                            <option value="MG">MG</option>
                                            <option value="PA">PA</option>
                                            <option value="PB">PB</option>
                                            <option value="PR">PR</option>
                                            <option value="PE">PE</option>
                                            <option value="PI">PI</option>
                                            <option value="RJ">RJ</option>
                                            <option value="RN">RN</option>
                                            <option value="RS">RS</option>
                                            <option value="RO">RO</option>
                                            <option value="RR">RR</option>
                                            <option value="SC">SC</option>
                                            <option value="SP">SP</option>
                                            <option value="SE">SE</option>
                                            <option value="TO">TO</option>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Cidade</label>
                                        <select class="form-select form-select-sm" id="EMP_CIDADE" name="EMP_CIDADE">
                                            <option value="">Selecione a UF primeiro</option>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">País</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_PAIS" name="EMP_PAIS" value="Brasil">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">IBGE</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_IBGE" name="EMP_IBGE">
                                    </div>

                                </div>
                            </div>

                            <!-- ABA 3: FISCAL -->
                            <div class="tab-pane fade" id="tab-fiscal" role="tabpanel" aria-labelledby="tab-fiscal-tab">
                                <div class="row g-3">

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">IE</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_IE" name="EMP_IE">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">IM</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_IM" name="EMP_IM">
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Regime Tributário</label>
                                        <select class="form-select form-select-sm" id="EMP_REGIME_TRIBUTARIO" name="EMP_REGIME_TRIBUTARIO">
                                            <option value="">(não informado)</option>
                                            <option value="SIMPLES_NACIONAL">Simples Nacional</option>
                                            <option value="LUCRO_PRESUMIDO">Lucro Presumido</option>
                                            <option value="LUCRO_REAL">Lucro Real</option>
                                        </select>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">CNAE Principal</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_CNAE_PRINCIPAL" name="EMP_CNAE_PRINCIPAL" placeholder="0000-0/00">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label small">Natureza Jurídica</label>
                                        <input type="text" class="form-control form-control-sm" id="EMP_NATUREZA_JURIDICA" name="EMP_NATUREZA_JURIDICA">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label small">Observação Fiscal</label>
                                        <textarea class="form-control form-control-sm" id="EMP_OBSERVACAO_FISCAL" name="EMP_OBSERVACAO_FISCAL" rows="2"></textarea>
                                    </div>

                                </div>
                            </div>

                            <!-- ABA 4: PARÂMETROS FINANCEIROS -->
                            <div class="tab-pane fade" id="tab-param" role="tabpanel" aria-labelledby="tab-param-tab">
                                <div class="row g-3">

                                    <!-- ✅ AGORA É SELECT (BANCO) -->
                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Banco padrão (boleto)</label>
                                        <select class="form-select form-select-sm" id="EMP_BANCO_PADRAO_BOLETO" name="EMP_BANCO_PADRAO_BOLETO">
                                            <option value="">Carregando...</option>
                                        </select>
                                        <div class="help-mini mt-1">Vem do cadastro de bancos/cobrança (CNAB). Os parâmetros de carteira/dias ficam no banco.</div>
                                    </div>

                                    <!-- ❌ REMOVIDOS: Carteira cobrança e Dias tolerância (já estão no cadastro do banco) -->

                                    <div class="col-12 col-md-4">
                                        <label class="form-label small">Envio de cobrança</label>
                                        <select class="form-select form-select-sm" id="EMP_ENVIO_COBRANCA" name="EMP_ENVIO_COBRANCA">
                                            <option value="EMAIL">E-mail</option>
                                            <option value="PORTAL">Portal</option>
                                            <option value="EMAIL_PORTAL">E-mail + Portal</option>
                                        </select>
                                    </div>

                                    <!-- ✅ SELECT (Plano de Contas) -->
                                    <div class="col-12 col-md-6">
                                        <label class="form-label small">Plano de contas padrão</label>
                                        <select class="form-select form-select-sm" id="EMP_PLANO_CONTAS_PADRAO" name="EMP_PLANO_CONTAS_PADRAO">
                                            <option value="">Carregando...</option>
                                        </select>
                                        <div class="help-mini mt-1">Usado como padrão ao criar lançamentos/contratos (pode ser alterado no lançamento).</div>
                                    </div>

                                    <!-- ✅ SELECT (Centro de Custo) -->
                                    <div class="col-12 col-md-6">
                                        <label class="form-label small">Centro de custo padrão</label>
                                        <select class="form-select form-select-sm" id="EMP_CENTRO_CUSTO_PADRAO" name="EMP_CENTRO_CUSTO_PADRAO">
                                            <option value="">Carregando...</option>
                                        </select>
                                        <div class="help-mini mt-1">Usado como padrão ao criar lançamentos/contratos (pode ser alterado no lançamento).</div>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label small">Texto padrão (documentos)</label>
                                        <textarea class="form-control form-control-sm" id="EMP_TEXTO_PADRAO_DOCS" name="EMP_TEXTO_PADRAO_DOCS" rows="3"
                                            placeholder="Texto padrão para boletos/recibos/documentos..."></textarea>
                                    </div>

                                </div>
                            </div>

                        </div><!-- /tab-content -->
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnSalvarEmpresa">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        const ENDPOINT = 'endpoints/empresas.php';

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

        // ✅ combos (centro de custo / plano de contas / bancos)
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

        const modalEmpresa = new bootstrap.Modal(document.getElementById('modalEmpresa'));

        function badgeStatus(s) {
            if (s === 'ATIVO') return '<span class="badge-soft-success">ATIVA</span>';
            return '<span class="badge-soft-danger">INATIVA</span>';
        }

        function safe(v) {
            return (v ?? '').toString();
        }

        function onlyDigits(v) {
            return (v || '').toString().replace(/\D/g, '');
        }

        // cache para não ficar batendo em toda abertura
        let combosCarregados = false;
        let _ccPend = ''; // centro custo
        let _plcPend = ''; // plano contas
        let _banPend = ''; // banco padrão (ID)

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

            items.forEach(it => {
                const opt = document.createElement('option');
                opt.value = (it[valueKey] ?? '').toString();
                opt.textContent = (it[labelKey] ?? '').toString();
                sel.appendChild(opt);
            });
        }

        function aplicarPendenciasCombos() {
            const selCC = document.getElementById('EMP_CENTRO_CUSTO_PADRAO');
            const selPLC = document.getElementById('EMP_PLANO_CONTAS_PADRAO');
            const selBAN = document.getElementById('EMP_BANCO_PADRAO_BOLETO');

            if (selCC && _ccPend !== null && _ccPend !== undefined) {
                selCC.value = (_ccPend ?? '').toString();
                _ccPend = '';
            }
            if (selPLC && _plcPend !== null && _plcPend !== undefined) {
                selPLC.value = (_plcPend ?? '').toString();
                _plcPend = '';
            }
            if (selBAN && _banPend !== null && _banPend !== undefined) {
                selBAN.value = (_banPend ?? '').toString();
                _banPend = '';
            }
        }

        async function carregarCombosFinanceiros(force = false) {
            if (combosCarregados && !force) return;

            const selCC = document.getElementById('EMP_CENTRO_CUSTO_PADRAO');
            const selPLC = document.getElementById('EMP_PLANO_CONTAS_PADRAO');
            const selBAN = document.getElementById('EMP_BANCO_PADRAO_BOLETO');

            if (!selCC || !selPLC || !selBAN) return;

            selCC.innerHTML = `<option value="">Carregando...</option>`;
            selPLC.innerHTML = `<option value="">Carregando...</option>`;
            selBAN.innerHTML = `<option value="">Carregando...</option>`;

            // endpoints do CRUD
            const urlCC = 'endpoints/centros_custo.php?acao=combo';
            const urlPLC = 'endpoints/plano_contas.php?acao=combo';
            // ✅ bancos: vem do cadastro de bancos/cobrança
            const urlBAN = 'endpoints/bancos.php?acao=combo';

            const [cc, plc, ban] = await Promise.all([
                apiCombo(urlCC),
                apiCombo(urlPLC),
                apiCombo(urlBAN)
            ]);

            // centros_custo: CEC_ID e CEC_LABEL
            setSelectOptions(selCC, cc.rows || [], {
                valueKey: 'CEC_ID',
                labelKey: 'CEC_LABEL',
                firstLabel: '(não definido)'
            });

            // plano_contas: PLC_ID e PLC_LABEL
            setSelectOptions(selPLC, plc.rows || [], {
                valueKey: 'PLC_ID',
                labelKey: 'PLC_LABEL',
                firstLabel: '(não definido)'
            });

            // ✅ bancos: BAN_ID e BAN_LABEL (fallback se teu endpoint usar outros nomes)
            const bancos = (ban.rows || []).map(x => ({
                BAN_ID: (x.BAN_ID ?? x.id ?? x.value ?? '').toString(),
                BAN_LABEL: (x.BAN_LABEL ?? x.label ?? x.text ?? `${(x.BAN_APELIDO ?? '').toString()}` ?? '').toString()
            }));

            setSelectOptions(selBAN, bancos, {
                valueKey: 'BAN_ID',
                labelKey: 'BAN_LABEL',
                firstLabel: '(não definido)'
            });

            combosCarregados = true;
            aplicarPendenciasCombos();
        }

        function limparForm() {
            document.getElementById('frmEmpresa').reset();
            document.getElementById('EMP_ID').value = '';
            document.getElementById('EMP_CIDADE').innerHTML = '<option value="">Selecione a UF</option>';
            document.getElementById('EMP_IBGE').value = '';
            cnpjInvalidoBloqueado = false;
            setSalvarEnabled(true);

            _ccPend = '';
            _plcPend = '';
            _banPend = '';

            const firstTab = document.querySelector('#tabsEmpresa button.nav-link.active') || document.querySelector('#tab-dados-tab');
            if (firstTab) new bootstrap.Tab(firstTab).show();
        }

        function getForm() {
            return {
                EMP_ID: (document.getElementById('EMP_ID').value || '').trim(),

                // dados
                EMP_CODIGO: document.getElementById('EMP_CODIGO').value.trim(),
                EMP_RAZAO_SOCIAL: document.getElementById('EMP_RAZAO_SOCIAL').value.trim(),
                EMP_NOME_FANTASIA: document.getElementById('EMP_NOME_FANTASIA').value.trim(),
                EMP_CNPJ: document.getElementById('EMP_CNPJ').value.trim(),
                EMP_TIPO: document.getElementById('EMP_TIPO').value,
                EMP_STATUS: document.getElementById('EMP_STATUS').value,
                EMP_EMAIL: document.getElementById('EMP_EMAIL').value.trim(),
                EMP_TELEFONE: document.getElementById('EMP_TELEFONE').value.trim(),
                EMP_SITE: document.getElementById('EMP_SITE').value.trim(),
                EMP_OBSERVACAO: document.getElementById('EMP_OBSERVACAO').value.trim(),

                // endereço
                EMP_CEP: document.getElementById('EMP_CEP').value.trim(),
                EMP_LOGRADOURO: document.getElementById('EMP_LOGRADOURO').value.trim(),
                EMP_NUMERO: document.getElementById('EMP_NUMERO').value.trim(),
                EMP_COMPLEMENTO: document.getElementById('EMP_COMPLEMENTO').value.trim(),
                EMP_BAIRRO: document.getElementById('EMP_BAIRRO').value.trim(),
                EMP_UF: document.getElementById('EMP_UF').value,
                EMP_CIDADE: document.getElementById('EMP_CIDADE').value,
                EMP_PAIS: document.getElementById('EMP_PAIS').value.trim(),
                EMP_IBGE: document.getElementById('EMP_IBGE').value.trim(),

                // fiscal
                EMP_IE: document.getElementById('EMP_IE').value.trim(),
                EMP_IM: document.getElementById('EMP_IM').value.trim(),
                EMP_REGIME_TRIBUTARIO: document.getElementById('EMP_REGIME_TRIBUTARIO').value,
                EMP_CNAE_PRINCIPAL: document.getElementById('EMP_CNAE_PRINCIPAL').value.trim(),
                EMP_NATUREZA_JURIDICA: document.getElementById('EMP_NATUREZA_JURIDICA').value.trim(),
                EMP_OBSERVACAO_FISCAL: document.getElementById('EMP_OBSERVACAO_FISCAL').value.trim(),

                // parâmetros
                // ✅ agora é SELECT (ID do banco)
                EMP_BANCO_PADRAO_BOLETO: document.getElementById('EMP_BANCO_PADRAO_BOLETO').value,
                EMP_ENVIO_COBRANCA: document.getElementById('EMP_ENVIO_COBRANCA').value,

                EMP_PLANO_CONTAS_PADRAO: document.getElementById('EMP_PLANO_CONTAS_PADRAO').value,
                EMP_CENTRO_CUSTO_PADRAO: document.getElementById('EMP_CENTRO_CUSTO_PADRAO').value,

                EMP_TEXTO_PADRAO_DOCS: document.getElementById('EMP_TEXTO_PADRAO_DOCS').value.trim(),
            };
        }

        function setForm(u) {
            document.getElementById('EMP_ID').value = u.EMP_ID || '';

            // dados
            document.getElementById('EMP_CODIGO').value = u.EMP_CODIGO || '';
            document.getElementById('EMP_RAZAO_SOCIAL').value = u.EMP_RAZAO_SOCIAL || '';
            document.getElementById('EMP_NOME_FANTASIA').value = u.EMP_NOME_FANTASIA || '';
            document.getElementById('EMP_CNPJ').value = u.EMP_CNPJ || '';
            document.getElementById('EMP_TIPO').value = u.EMP_TIPO || 'MATRIZ';
            document.getElementById('EMP_STATUS').value = u.EMP_STATUS || 'ATIVO';
            document.getElementById('EMP_EMAIL').value = u.EMP_EMAIL || '';
            document.getElementById('EMP_TELEFONE').value = u.EMP_TELEFONE || '';
            document.getElementById('EMP_SITE').value = u.EMP_SITE || '';
            document.getElementById('EMP_OBSERVACAO').value = u.EMP_OBSERVACAO || '';

            // endereço
            document.getElementById('EMP_CEP').value = u.EMP_CEP || '';
            document.getElementById('EMP_LOGRADOURO').value = u.EMP_LOGRADOURO || '';
            document.getElementById('EMP_NUMERO').value = u.EMP_NUMERO || '';
            document.getElementById('EMP_COMPLEMENTO').value = u.EMP_COMPLEMENTO || '';
            document.getElementById('EMP_BAIRRO').value = u.EMP_BAIRRO || '';
            document.getElementById('EMP_IBGE').value = u.EMP_IBGE || '';
            document.getElementById('EMP_PAIS').value = u.EMP_PAIS || 'Brasil';

            // fiscal
            document.getElementById('EMP_IE').value = u.EMP_IE || '';
            document.getElementById('EMP_IM').value = u.EMP_IM || '';
            document.getElementById('EMP_REGIME_TRIBUTARIO').value = u.EMP_REGIME_TRIBUTARIO || '';
            document.getElementById('EMP_CNAE_PRINCIPAL').value = u.EMP_CNAE_PRINCIPAL || '';
            document.getElementById('EMP_NATUREZA_JURIDICA').value = u.EMP_NATUREZA_JURIDICA || '';
            document.getElementById('EMP_OBSERVACAO_FISCAL').value = u.EMP_OBSERVACAO_FISCAL || '';

            // parâmetros
            document.getElementById('EMP_ENVIO_COBRANCA').value = u.EMP_ENVIO_COBRANCA || 'EMAIL';

            // ✅ valores pendentes (IDs) - aplica após carregar combos
            _plcPend = (u.EMP_PLANO_CONTAS_PADRAO ?? '').toString();
            _ccPend = (u.EMP_CENTRO_CUSTO_PADRAO ?? '').toString();
            _banPend = (u.EMP_BANCO_PADRAO_BOLETO ?? '').toString();

            if (combosCarregados) aplicarPendenciasCombos();

            document.getElementById('EMP_TEXTO_PADRAO_DOCS').value = u.EMP_TEXTO_PADRAO_DOCS || '';
        }

        async function listar() {
            const buscar = document.getElementById('fBuscar').value.trim();
            const status = document.getElementById('fStatus').value;
            const tipo = document.getElementById('fTipo').value;

            const j = await api({
                acao: 'listar',
                buscar,
                status,
                tipo
            }, 'GET');

            const tb = document.getElementById('tbEmpresas');
            tb.innerHTML = '';
            document.getElementById('lblTotal').textContent = `${j.total} registro(s)`;

            if (!j.rows || j.rows.length === 0) {
                tb.innerHTML = '<tr><td colspan="8" class="text-muted small">Nenhuma empresa encontrada.</td></tr>';
                return;
            }

            j.rows.forEach((r, i) => {
                const btnEdit = `<button class="btn btn-sm btn-outline-primary me-1" title="Editar" data-id="${r.EMP_ID}" data-act="editar"><i class="fa-solid fa-pen"></i></button>`;
                const btnStatus = (r.EMP_STATUS === 'ATIVO') ?
                    `<button class="btn btn-sm btn-outline-warning" title="Inativar" data-id="${r.EMP_ID}" data-act="inativar"><i class="fa-solid fa-ban"></i></button>` :
                    `<button class="btn btn-sm btn-outline-success" title="Reativar" data-id="${r.EMP_ID}" data-act="reativar"><i class="fa-solid fa-rotate"></i></button>`;

                tb.insertAdjacentHTML('beforeend', `
                    <tr>
                        <td>${i+1}</td>
                        <td>${safe(r.EMP_CODIGO)}</td>
                        <td>${safe(r.EMP_RAZAO_SOCIAL)}</td>
                        <td>${safe(r.EMP_NOME_FANTASIA)}</td>
                        <td>${safe(r.EMP_CNPJ)}</td>
                        <td>${safe(r.EMP_TIPO)}</td>
                        <td>${badgeStatus(r.EMP_STATUS)}</td>
                        <td class="text-end">${btnEdit}${btnStatus}</td>
                    </tr>
                `);
            });
        }

        async function abrirNovo() {
            limparForm();
            document.getElementById('modalEmpresaLabel').textContent = 'Nova empresa';
            await carregarCombosFinanceiros(false);
            modalEmpresa.show();
        }

        async function abrirEditar(id) {
            const j = await api({
                acao: 'get',
                id
            }, 'GET');
            const u = j.row;

            limparForm();
            setForm(u);

            // UF + cidades
            document.getElementById('EMP_UF').value = u.EMP_UF || '';
            await carregarCidadesPorUF(u.EMP_UF || '', u.EMP_CIDADE || '');

            // ✅ combos + aplica pendências (plano/cc/banco)
            await carregarCombosFinanceiros(false);

            document.getElementById('modalEmpresaLabel').textContent = `Editar empresa #${u.EMP_ID}`;
            modalEmpresa.show();
        }

        async function salvar() {
            if (cnpjInvalidoBloqueado) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Corrija o CNPJ antes de salvar.'
                });
                return;
            }

            await validarCNPJOnBlur();
            if (cnpjInvalidoBloqueado) return;

            const d = getForm();

            if (!d.EMP_RAZAO_SOCIAL || !d.EMP_CNPJ) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Informe Razão Social e CNPJ.'
                });
                return;
            }

            try {
                await api({
                    acao: 'salvar',
                    ...d
                }, 'POST');
                modalEmpresa.hide();
                Swal.fire({
                    icon: 'success',
                    title: 'Salvo',
                    text: 'Empresa salva com sucesso!',
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

        async function inativar(id) {
            const r = await Swal.fire({
                icon: 'warning',
                title: 'Inativar empresa?',
                text: 'A empresa ficará INATIVA (não exclui).',
                showCancelButton: true,
                confirmButtonText: 'Sim, inativar',
                cancelButtonText: 'Cancelar'
            });
            if (!r.isConfirmed) return;

            await api({
                acao: 'inativar',
                id
            }, 'POST');
            Swal.fire({
                icon: 'success',
                title: 'Ok',
                text: 'Empresa inativada.',
                timer: 900,
                showConfirmButton: false
            });
            await listar();
        }

        async function reativar(id) {
            const r = await Swal.fire({
                icon: 'question',
                title: 'Reativar empresa?',
                showCancelButton: true,
                confirmButtonText: 'Sim, reativar',
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
                text: 'Empresa reativada.',
                timer: 900,
                showConfirmButton: false
            });
            await listar();
        }

        // ===== CEP (ViaCEP) =====
        async function buscarCEP() {
            const cep = onlyDigits(document.getElementById('EMP_CEP').value);
            if (cep.length !== 8) return;

            try {
                const r = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
                const j = await r.json();
                if (j.erro) throw new Error('CEP não encontrado.');

                document.getElementById('EMP_LOGRADOURO').value = j.logradouro || '';
                document.getElementById('EMP_BAIRRO').value = j.bairro || '';
                document.getElementById('EMP_COMPLEMENTO').value = j.complemento || '';
                document.getElementById('EMP_UF').value = j.uf || '';
                document.getElementById('EMP_IBGE').value = j.ibge || '';

                await carregarCidadesPorUF(j.uf || '', j.localidade || '');
                document.getElementById('EMP_NUMERO').focus();
            } catch (e) {
                Swal.fire({
                    icon: 'warning',
                    title: 'CEP',
                    text: e.message
                });
            }
        }

        // ===== Cidades (IBGE) =====
        async function carregarCidadesPorUF(uf, cidadeSelecionada = '') {
            const sel = document.getElementById('EMP_CIDADE');
            sel.innerHTML = '<option value="">Carregando...</option>';

            if (!uf) {
                sel.innerHTML = '<option value="">Selecione a UF</option>';
                return;
            }

            try {
                const r = await fetch(`https://servicodados.ibge.gov.br/api/v1/localidades/estados/${uf}/municipios?orderBy=nome`);
                const arr = await r.json();

                sel.innerHTML = '<option value="">Selecione</option>';
                arr.forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = m.nome;
                    opt.textContent = m.nome;
                    sel.appendChild(opt);
                });

                if (cidadeSelecionada) sel.value = cidadeSelecionada;
            } catch (e) {
                sel.innerHTML = '<option value="">Falha ao carregar cidades</option>';
            }
        }

        // ===== Validação de CNPJ (front) =====
        function formatCNPJ(v) {
            const d = onlyDigits(v).slice(0, 14);
            if (d.length <= 2) return d;
            if (d.length <= 5) return d.replace(/^(\d{2})(\d+)/, '$1.$2');
            if (d.length <= 8) return d.replace(/^(\d{2})(\d{3})(\d+)/, '$1.$2.$3');
            if (d.length <= 12) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d+)/, '$1.$2.$3/$4');
            return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d+)/, '$1.$2.$3/$4-$5');
        }

        function isCNPJValido(cnpj) {
            cnpj = onlyDigits(cnpj);
            if (!cnpj || cnpj.length !== 14) return false;
            if (/^(\d)\1{13}$/.test(cnpj)) return false;

            const calcDv = (base) => {
                let soma = 0;
                let peso = base.length - 7;
                for (let i = 0; i < base.length; i++) {
                    soma += parseInt(base[i], 10) * peso--;
                    if (peso < 2) peso = 9;
                }
                const resto = soma % 11;
                return (resto < 2) ? 0 : (11 - resto);
            };

            const base12 = cnpj.slice(0, 12);
            const dv1 = calcDv(base12);
            const dv2 = calcDv(base12 + String(dv1));
            return cnpj === (base12 + String(dv1) + String(dv2));
        }

        function setSalvarEnabled(enabled) {
            const btn = document.getElementById('btnSalvarEmpresa');
            if (btn) btn.disabled = !enabled;
        }

        let cnpjInvalidoBloqueado = false;

        async function validarCNPJOnBlur() {
            const el = document.getElementById('EMP_CNPJ');
            if (!el) return;

            el.value = formatCNPJ(el.value);
            const val = el.value.trim();

            if (!val) {
                cnpjInvalidoBloqueado = true;
                setSalvarEnabled(false);
                return;
            }

            const ok = isCNPJValido(val);

            if (!ok) {
                cnpjInvalidoBloqueado = true;
                setSalvarEnabled(false);

                await Swal.fire({
                    icon: 'error',
                    title: 'CNPJ inválido',
                    text: 'Informe um CNPJ válido para continuar.',
                    confirmButtonText: 'OK'
                });

                el.focus();
                el.select?.();
            } else {
                cnpjInvalidoBloqueado = false;
                setSalvarEnabled(true);
            }
        }

        // binds
        document.getElementById('btnNovoEmpresa').addEventListener('click', abrirNovo);
        document.getElementById('btnSalvarEmpresa').addEventListener('click', salvar);

        document.getElementById('frmFiltros').addEventListener('submit', (e) => {
            e.preventDefault();
            listar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('tbEmpresas').addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-act]');
            if (!btn) return;
            const id = btn.dataset.id;
            const act = btn.dataset.act;

            if (act === 'editar') abrirEditar(id).catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
            if (act === 'inativar') inativar(id).catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
            if (act === 'reativar') reativar(id).catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('EMP_CEP').addEventListener('blur', buscarCEP);
        document.getElementById('EMP_UF').addEventListener('change', (e) => carregarCidadesPorUF(e.target.value, ''));

        document.getElementById('EMP_CNPJ').addEventListener('blur', () => validarCNPJOnBlur());
        document.getElementById('EMP_CNPJ').addEventListener('input', (e) => e.target.value = formatCNPJ(e.target.value));

        // init
        listar().catch(err => Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: err.message
        }));
    </script>
  <script src="assets/session_keeper.js" defer></script>
</body>

</html>