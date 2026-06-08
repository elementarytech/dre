<?php
// /app/clientes.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php'; // exige login
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Clientes</title>
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

        .modal-xxl {
            max-width: 92vw
        }

        @media (min-width:1200px) {
            .modal-xxl {
                max-width: 1100px
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
    </style>
</head>

<body data-page="clientes">
    <div class="d-flex" id="wrapper">

        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">
            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Cadastro de Clientes</span>

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
                        <h5 class="mb-1 mt-1">Clientes</h5>
                        <p class="help mb-0">Cadastro de clientes PF/PJ com endereço, observações, e abas para contratos e anexos (stub).</p>
                    </div>
                    <div class="mt-2 mt-sm-0">
                        <button id="btnNovoCliente" type="button" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-plus me-1"></i>Novo Cliente
                        </button>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <form id="frmFiltros" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Buscar</label>
                                <input id="fBusca" type="text" class="form-control form-control-sm" placeholder="Nome, documento, e-mail..." />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Situação</label>
                                <select id="fStatus" class="form-select form-select-sm">
                                    <option value="">Todos</option>
                                    <option value="ATIVO">Ativo</option>
                                    <option value="INATIVO">Inativo</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Tipo de Pessoa</label>
                                <select id="fTipoPessoa" class="form-select form-select-sm">
                                    <option value="">Todos</option>
                                    <option value="F">Pessoa Física</option>
                                    <option value="J">Pessoa Jurídica</option>
                                </select>
                            </div>
                            <div class="col-md-2 text-md-end">
                                <button class="btn btn-sm btn-outline-secondary me-2" type="submit">
                                    <i class="fa-solid fa-magnifying-glass me-1"></i>Filtrar
                                </button>
                                <button class="btn btn-sm btn-primary" type="button" id="btnNovo2">
                                    <i class="fa-solid fa-plus me-1"></i>Novo
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Lista -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Clientes Cadastrados</h6>
                        <span class="small text-muted" id="lblTotal">—</span>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Nome / Razão Social</th>
                                        <th>Tipo</th>
                                        <th>Documento</th>
                                        <th>Telefone</th>
                                        <th>E-mail</th>
                                        <th>Cidade/UF</th>
                                        <th class="text-center">Situação</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="tbClientes">
                                    <tr>
                                        <td colspan="9" class="text-muted small">Carregando...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <footer class="text-muted small mt-4">© <?= date('Y') ?> DRE - Sistema Financeiro</footer>

            </div>
        </div>
    </div>

    <!-- Modal Cliente -->
    <div class="modal fade" id="modalCliente" tabindex="-1" aria-labelledby="modalClienteLabel" aria-hidden="true">
        <div class="modal-dialog modal-xxl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modalClienteLabel">Cadastro de Cliente</h5>
                        <div class="help-mini">Use as abas para endereço, contratos e anexos.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <form id="frmCliente" autocomplete="off">
                        <input type="hidden" id="CLI_ID" name="CLI_ID" value="">

                        <ul class="nav nav-tabs" id="clienteTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="dados-tab" data-bs-toggle="tab" data-bs-target="#aba-dados" type="button" role="tab">
                                    <i class="fa-solid fa-id-card me-1"></i>Dados do Cliente
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="endereco-tab" data-bs-toggle="tab" data-bs-target="#aba-endereco" type="button" role="tab">
                                    <i class="fa-solid fa-location-dot me-1"></i>Endereço
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="contratos-tab" data-bs-toggle="tab" data-bs-target="#aba-contratos" type="button" role="tab">
                                    <i class="fa-solid fa-file-contract me-1"></i>Contratos
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="anexos-tab" data-bs-toggle="tab" data-bs-target="#aba-anexos" type="button" role="tab">
                                    <i class="fa-solid fa-paperclip me-1"></i>Anexos
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content pt-3">
                            <!-- ABA DADOS -->
                            <div class="tab-pane fade show active" id="aba-dados" role="tabpanel" aria-labelledby="dados-tab">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label small">Código</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_ID_VIEW" value="(novo)" disabled>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small">Nome / Razão Social *</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_NOME_RAZAO" required>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small">Tipo de Pessoa</label>
                                        <select class="form-select form-select-sm" id="CLI_TIPO_PESSOA">
                                            <option value="F">Pessoa Física</option>
                                            <option value="J">Pessoa Jurídica</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small">CPF / CNPJ *</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_DOCUMENTO" placeholder="(validação ao sair)" required>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small">Inscrição Estadual</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_IE">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small">Data Nasc. / Fundação</label>
                                        <input type="date" class="form-control form-control-sm" id="CLI_DATA_NASC_FUNDACAO">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small">Telefone *</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_TELEFONE" placeholder="(00) 00000-0000" required>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small">Celular / WhatsApp</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_WHATSAPP">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small">E-mail *</label>
                                        <input type="email" class="form-control form-control-sm" id="CLI_EMAIL" required>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label small">Situação</label>
                                        <select class="form-select form-select-sm" id="CLI_STATUS">
                                            <option value="ATIVO">Ativo</option>
                                            <option value="INATIVO">Inativo</option>
                                        </select>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label small">Observações</label>
                                        <textarea class="form-control form-control-sm" id="CLI_OBSERVACAO" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- ABA ENDEREÇO -->
                            <div class="tab-pane fade" id="aba-endereco" role="tabpanel" aria-labelledby="endereco-tab">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label small">CEP *</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_CEP" placeholder="00000-000" required>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small">Endereço *</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_ENDERECO" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">Número *</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_NUMERO" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">Complemento</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_COMPLEMENTO">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small">Bairro *</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_BAIRRO" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">Cidade *</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_CIDADE" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">UF *</label>
                                        <input type="text" class="form-control form-control-sm" id="CLI_UF" maxlength="2" placeholder="UF" required>
                                    </div>
                                </div>
                            </div>

                            <!-- ABA CONTRATOS (stub) -->
                            <div class="tab-pane fade" id="aba-contratos" role="tabpanel" aria-labelledby="contratos-tab">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <p class="small text-muted mb-1">Aqui você visualiza contratos vinculados a este cliente.</p>
                                        <p class="small text-muted mb-0">Nesta etapa, a aba funciona como “placeholder” (endpoint retorna lista vazia).</p>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-primary" id="btnVincularContrato">
                                        <i class="fa-solid fa-link me-1"></i>Vincular contrato
                                    </button>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Nº Contrato</th>
                                                <th>Descrição</th>
                                                <th>Início</th>
                                                <th>Fim</th>
                                                <th>Status</th>
                                                <th class="text-end">Valor Mensal</th>
                                                <th class="text-end">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tbContratosCliente">
                                            <tr>
                                                <td colspan="7" class="text-muted small">Sem dados (stub).</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- ABA ANEXOS (stub) -->
                            <div class="tab-pane fade" id="aba-anexos" role="tabpanel" aria-labelledby="anexos-tab">
                                <div class="card border-0 shadow-sm mb-3">
                                    <div class="card-body">
                                        <div class="row g-3 align-items-end">
                                            <div class="col-md-4">
                                                <label class="form-label small">Tipo de Anexo</label>
                                                <select class="form-select form-select-sm" id="ANX_TIPO" disabled>
                                                    <option value="contrato">Contrato assinado</option>
                                                    <option value="documentos">Documentos do cliente</option>
                                                    <option value="comprovante">Comprovante de endereço</option>
                                                    <option value="outros">Outros</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small">Arquivo</label>
                                                <input type="file" class="form-control form-control-sm" disabled>
                                            </div>
                                            <div class="col-md-2 text-md-end">
                                                <button type="button" class="btn btn-sm btn-primary w-100" id="btnUploadAnexo" disabled>
                                                    <i class="fa-solid fa-upload me-1"></i>Incluir
                                                </button>
                                            </div>
                                            <div class="col-12">
                                                <div class="help-mini">Stub nesta etapa (sem upload ainda).</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Tipo</th>
                                                <th>Arquivo</th>
                                                <th>Data</th>
                                                <th>Usuário</th>
                                                <th class="text-end">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tbAnexosCliente">
                                            <tr>
                                                <td colspan="5" class="text-muted small">Sem dados (stub).</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div><!-- tab-content -->
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnSalvarCliente">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        const ENDPOINT = 'endpoints/clientes.php';

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

        const modalCliente = new bootstrap.Modal(document.getElementById('modalCliente'));

        function escapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, m => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            } [m]));
        }

        function badgeStatus(status) {
            if (status === 'ATIVO') return '<span class="badge bg-success">Ativo</span>';
            return '<span class="badge bg-secondary">Inativo</span>';
        }

        function labelTipo(t) {
            return t === 'J' ? 'PJ' : 'PF';
        }

        function onlyDigits(v) {
            return (v || '').toString().replace(/\D/g, '');
        }

        function maskPhone(v) {
            const d = onlyDigits(v).slice(0, 11);
            if (d.length <= 10) {
                return d
                    .replace(/^(\d{2})(\d)/g, '($1) $2')
                    .replace(/(\d{4})(\d)/, '$1-$2');
            }
            return d
                .replace(/^(\d{2})(\d)/g, '($1) $2')
                .replace(/(\d{5})(\d)/, '$1-$2');
        }

        function maskCEP(v) {
            const d = onlyDigits(v).slice(0, 8);
            if (d.length <= 5) return d;
            return d.slice(0, 5) + '-' + d.slice(5);
        }

        // ===== CPF/CNPJ: máscara + validação =====
        function formatCPF(v) {
            const d = onlyDigits(v).slice(0, 11);
            if (d.length <= 3) return d;
            if (d.length <= 6) return d.replace(/^(\d{3})(\d+)/, '$1.$2');
            if (d.length <= 9) return d.replace(/^(\d{3})(\d{3})(\d+)/, '$1.$2.$3');
            return d.replace(/^(\d{3})(\d{3})(\d{3})(\d+)/, '$1.$2.$3-$4');
        }

        function isCPFValido(cpf) {
            cpf = onlyDigits(cpf);
            if (!cpf || cpf.length !== 11) return false;
            if (/^(\d)\1{10}$/.test(cpf)) return false;

            let soma = 0;
            for (let i = 0; i < 9; i++) soma += parseInt(cpf[i], 10) * (10 - i);
            let dv1 = (soma * 10) % 11;
            if (dv1 === 10) dv1 = 0;
            if (dv1 !== parseInt(cpf[9], 10)) return false;

            soma = 0;
            for (let i = 0; i < 10; i++) soma += parseInt(cpf[i], 10) * (11 - i);
            let dv2 = (soma * 10) % 11;
            if (dv2 === 10) dv2 = 0;
            return dv2 === parseInt(cpf[10], 10);
        }

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
            const btn = document.getElementById('btnSalvarCliente');
            if (btn) btn.disabled = !enabled;
        }
        let docInvalidoBloqueado = false;

        async function validarDocumentoOnBlur() {
            const tipo = document.getElementById('CLI_TIPO_PESSOA').value || 'F';
            const el = document.getElementById('CLI_DOCUMENTO');
            if (!el) return;

            const raw = el.value.trim();
            if (!raw) {
                // documento não obrigatório aqui (pode ser), então libera
                docInvalidoBloqueado = false;
                setSalvarEnabled(true);
                return;
            }

            if (tipo === 'J') {
                el.value = formatCNPJ(raw);
                if (!isCNPJValido(el.value)) {
                    docInvalidoBloqueado = true;
                    setSalvarEnabled(false);
                    await Swal.fire({
                        icon: 'error',
                        title: 'CNPJ inválido',
                        text: 'Informe um CNPJ válido para continuar.'
                    });
                    el.focus();
                    el.select?.();
                } else {
                    docInvalidoBloqueado = false;
                    setSalvarEnabled(true);
                }
            } else {
                el.value = formatCPF(raw);
                if (!isCPFValido(el.value)) {
                    docInvalidoBloqueado = true;
                    setSalvarEnabled(false);
                    await Swal.fire({
                        icon: 'error',
                        title: 'CPF inválido',
                        text: 'Informe um CPF válido para continuar.'
                    });
                    el.focus();
                    el.select?.();
                } else {
                    docInvalidoBloqueado = false;
                    setSalvarEnabled(true);
                }
            }
        }

        // ===== CEP ViaCEP (igual padrão empresas) =====
        async function buscarCEP() {
            const cep = onlyDigits(document.getElementById('CLI_CEP').value);
            if (cep.length !== 8) return;

            try {
                const r = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
                const j = await r.json();
                if (j.erro) throw new Error('CEP não encontrado.');

                document.getElementById('CLI_ENDERECO').value = j.logradouro || '';
                document.getElementById('CLI_BAIRRO').value = j.bairro || '';
                document.getElementById('CLI_COMPLEMENTO').value = j.complemento || '';
                document.getElementById('CLI_CIDADE').value = j.localidade || '';
                document.getElementById('CLI_UF').value = (j.uf || '').toUpperCase();

                document.getElementById('CLI_NUMERO').focus();
            } catch (e) {
                Swal.fire({
                    icon: 'warning',
                    title: 'CEP',
                    text: e.message
                });
            }
        }

        // ===== CRUD =====
        function resetForm() {
            document.getElementById('frmCliente').reset();
            document.getElementById('CLI_ID').value = '';
            document.getElementById('CLI_ID_VIEW').value = '(novo)';
            document.getElementById('CLI_STATUS').value = 'ATIVO';
            document.getElementById('CLI_TIPO_PESSOA').value = 'F';
            docInvalidoBloqueado = false;
            setSalvarEnabled(true);

            // volta pra aba dados
            const firstTab = document.querySelector('#clienteTabs button[data-bs-target="#aba-dados"]');
            if (firstTab) new bootstrap.Tab(firstTab).show();
        }

        function getForm() {
            return {
                CLI_ID: (document.getElementById('CLI_ID').value || '').trim(),
                CLI_NOME_RAZAO: document.getElementById('CLI_NOME_RAZAO').value.trim(),
                CLI_TIPO_PESSOA: document.getElementById('CLI_TIPO_PESSOA').value || 'F',
                CLI_DOCUMENTO: document.getElementById('CLI_DOCUMENTO').value.trim(),
                CLI_IE: document.getElementById('CLI_IE').value.trim(),
                CLI_DATA_NASC_FUNDACAO: document.getElementById('CLI_DATA_NASC_FUNDACAO').value || '',
                CLI_TELEFONE: document.getElementById('CLI_TELEFONE').value.trim(),
                CLI_WHATSAPP: document.getElementById('CLI_WHATSAPP').value.trim(),
                CLI_EMAIL: document.getElementById('CLI_EMAIL').value.trim(),
                CLI_STATUS: document.getElementById('CLI_STATUS').value || 'ATIVO',
                CLI_OBSERVACAO: document.getElementById('CLI_OBSERVACAO').value.trim(),

                CLI_CEP: document.getElementById('CLI_CEP').value.trim(),
                CLI_ENDERECO: document.getElementById('CLI_ENDERECO').value.trim(),
                CLI_NUMERO: document.getElementById('CLI_NUMERO').value.trim(),
                CLI_COMPLEMENTO: document.getElementById('CLI_COMPLEMENTO').value.trim(),
                CLI_BAIRRO: document.getElementById('CLI_BAIRRO').value.trim(),
                CLI_CIDADE: document.getElementById('CLI_CIDADE').value.trim(),
                CLI_UF: document.getElementById('CLI_UF').value.trim().toUpperCase(),
            };
        }

        function setForm(c) {
            document.getElementById('CLI_ID').value = c.CLI_ID || '';
            document.getElementById('CLI_ID_VIEW').value = c.CLI_ID || '';
            document.getElementById('CLI_NOME_RAZAO').value = c.CLI_NOME_RAZAO || '';
            document.getElementById('CLI_TIPO_PESSOA').value = c.CLI_TIPO_PESSOA || 'F';
            document.getElementById('CLI_DOCUMENTO').value = c.CLI_DOCUMENTO || '';
            document.getElementById('CLI_IE').value = c.CLI_IE || '';
            document.getElementById('CLI_DATA_NASC_FUNDACAO').value = c.CLI_DATA_NASC_FUNDACAO || '';
            document.getElementById('CLI_TELEFONE').value = c.CLI_TELEFONE || '';
            document.getElementById('CLI_WHATSAPP').value = c.CLI_WHATSAPP || '';
            document.getElementById('CLI_EMAIL').value = c.CLI_EMAIL || '';
            document.getElementById('CLI_STATUS').value = c.CLI_STATUS || 'ATIVO';
            document.getElementById('CLI_OBSERVACAO').value = c.CLI_OBSERVACAO || '';

            document.getElementById('CLI_CEP').value = c.CLI_CEP || '';
            document.getElementById('CLI_ENDERECO').value = c.CLI_ENDERECO || '';
            document.getElementById('CLI_NUMERO').value = c.CLI_NUMERO || '';
            document.getElementById('CLI_COMPLEMENTO').value = c.CLI_COMPLEMENTO || '';
            document.getElementById('CLI_BAIRRO').value = c.CLI_BAIRRO || '';
            document.getElementById('CLI_CIDADE').value = c.CLI_CIDADE || '';
            document.getElementById('CLI_UF').value = c.CLI_UF || '';
        }

        async function listar() {
            const q = document.getElementById('fBusca').value.trim();
            const status = document.getElementById('fStatus').value;
            const tipo = document.getElementById('fTipoPessoa').value;

            const j = await api({
                acao: 'listar',
                q,
                status,
                tipo
            }, 'GET');

            const tb = document.getElementById('tbClientes');
            tb.innerHTML = '';
            document.getElementById('lblTotal').textContent = `${j.total} registro(s)`;

            if (!j.rows || j.rows.length === 0) {
                tb.innerHTML = '<tr><td colspan="9" class="text-muted small">Nenhum cliente encontrado.</td></tr>';
                return;
            }

            j.rows.forEach((r, i) => {
                const cidadeUf = `${escapeHtml(r.CLI_CIDADE || '')}/${escapeHtml(r.CLI_UF || '')}`.replace(/^\/$/, '-');

                const btnEdit = `<button class="btn btn-sm btn-outline-secondary me-1" data-act="editar" data-id="${r.CLI_ID}" title="Editar"><i class="fa-solid fa-pen"></i></button>`;
                const btnStatus = (r.CLI_STATUS === 'ATIVO') ?
                    `<button class="btn btn-sm btn-outline-danger" data-act="inativar" data-id="${r.CLI_ID}" title="Inativar"><i class="fa-solid fa-user-slash"></i></button>` :
                    `<button class="btn btn-sm btn-outline-success" data-act="reativar" data-id="${r.CLI_ID}" title="Reativar"><i class="fa-solid fa-user-check"></i></button>`;

                tb.insertAdjacentHTML('beforeend', `
            <tr>
                <td>${i+1}</td>
                <td>${escapeHtml(r.CLI_NOME_RAZAO)}</td>
                <td>${labelTipo(r.CLI_TIPO_PESSOA)}</td>
                <td>${escapeHtml(r.CLI_DOCUMENTO || '')}</td>
                <td>${escapeHtml(r.CLI_TELEFONE || '')}</td>
                <td>${escapeHtml(r.CLI_EMAIL || '')}</td>
                <td>${cidadeUf}</td>
                <td class="text-center">${badgeStatus(r.CLI_STATUS)}</td>
                <td class="text-end">${btnEdit}${btnStatus}</td>
            </tr>
        `);
            });
        }

        async function abrirNovo() {
            resetForm();
            document.getElementById('modalClienteLabel').textContent = 'Cadastro de Cliente';
            modalCliente.show();
        }

        async function abrirEditar(id) {
            resetForm();
            const j = await api({
                acao: 'get',
                id
            }, 'GET');
            setForm(j.row);

            document.getElementById('modalClienteLabel').textContent = `Editar Cliente #${j.row.CLI_ID}`;
            modalCliente.show();

            // carrega stubs (contratos/anexos)
            carregarContratosStub(j.row.CLI_ID).catch(() => {});
            carregarAnexosStub(j.row.CLI_ID).catch(() => {});
        }

        async function salvar() {
            if (docInvalidoBloqueado) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Corrija o documento antes de salvar.'
                });
                return;
            }

            await validarDocumentoOnBlur();
            if (docInvalidoBloqueado) return;

            const d = getForm();
            if (!d.CLI_NOME_RAZAO) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Informe o Nome / Razão Social.'
                });
                return;
            }

            try {
                await api({
                    acao: 'salvar',
                    ...d
                }, 'POST');
                modalCliente.hide();
                Swal.fire({
                    icon: 'success',
                    title: 'Salvo',
                    text: 'Cliente salvo com sucesso!',
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
                title: 'Inativar cliente?',
                text: 'O cliente ficará INATIVO (não exclui).',
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
                text: 'Cliente inativado.',
                timer: 800,
                showConfirmButton: false
            });
            await listar();
        }

        async function reativar(id) {
            const r = await Swal.fire({
                icon: 'question',
                title: 'Reativar cliente?',
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
                text: 'Cliente reativado.',
                timer: 800,
                showConfirmButton: false
            });
            await listar();
        }

        // ===== Stubs (contratos/anexos) =====
        async function carregarContratosStub(cliId) {
            const tb = document.getElementById('tbContratosCliente');
            tb.innerHTML = '<tr><td colspan="7" class="text-muted small">Sem dados (stub).</td></tr>';
        }
        async function carregarAnexosStub(cliId) {
            const tb = document.getElementById('tbAnexosCliente');
            tb.innerHTML = '<tr><td colspan="5" class="text-muted small">Sem dados (stub).</td></tr>';
        }

        // ===== binds =====
        document.getElementById('btnNovoCliente').addEventListener('click', abrirNovo);
        document.getElementById('btnNovo2').addEventListener('click', abrirNovo);
        document.getElementById('btnSalvarCliente').addEventListener('click', salvar);

        document.getElementById('frmFiltros').addEventListener('submit', (e) => {
            e.preventDefault();
            listar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('tbClientes').addEventListener('click', (e) => {
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

        document.getElementById('CLI_CEP').addEventListener('blur', buscarCEP);
        document.getElementById('CLI_CEP').addEventListener('input', (e) => { e.target.value = maskCEP(e.target.value); });
        document.getElementById('CLI_TELEFONE').addEventListener('input', (e) => { e.target.value = maskPhone(e.target.value); });
        document.getElementById('CLI_WHATSAPP').addEventListener('input', (e) => { e.target.value = maskPhone(e.target.value); });
        document.getElementById('CLI_DOCUMENTO').addEventListener('blur', () => {
            validarDocumentoOnBlur().catch(() => {});
        });
        document.getElementById('CLI_DOCUMENTO').addEventListener('input', (e) => {
            const tipo = document.getElementById('CLI_TIPO_PESSOA').value || 'F';
            e.target.value = (tipo === 'J') ? formatCNPJ(e.target.value) : formatCPF(e.target.value);
        });
        document.getElementById('CLI_TIPO_PESSOA').addEventListener('change', () => {
            // ao mudar PF/PJ, reformatar doc e revalidar
            const el = document.getElementById('CLI_DOCUMENTO');
            el.value = el.value.trim();
            validarDocumentoOnBlur().catch(() => {});
        });

        // init
        listar().catch(err => Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: err.message
        }));

        // stub botão contrato
        document.getElementById('btnVincularContrato').addEventListener('click', () => {
            Swal.fire({
                icon: 'info',
                title: 'Em breve',
                text: 'Vínculo de contratos será implementado na próxima etapa.'
            });
        });
    </script>
  <script src="assets/session_keeper.js" defer></script>
</body>

</html>