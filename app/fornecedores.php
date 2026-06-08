<?php
// /app/fornecedores.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Fornecedores</title>
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

        .help-mini {
            font-size: .84rem;
            color: #64748b
        }

        .modal-xl2 {
            max-width: 92vw;
        }

        @media (min-width: 1200px) {
            .modal-xl2 {
                max-width: 1100px;
            }
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
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

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Fornecedores</span>

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
                        <h5 class="mb-1 mt-1">Cadastro de fornecedores</h5>
                        <p class="help mb-0">
                            Gerencie fornecedores, dados cadastrais, endereço e contatos.
                        </p>
                    </div>
                    <div class="mt-2 mt-sm-0">
                        <button id="btnNovoFornecedor" type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalFornecedor">
                            <i class="fa-solid fa-plus me-1"></i>Novo cadastro
                        </button>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body py-3">
                        <form class="row g-2 align-items-end" id="frmFiltros">
                            <div class="col-md-5">
                                <label class="form-label small mb-1">Buscar</label>
                                <input type="text" class="form-control form-control-sm" id="fBuscar"
                                    placeholder="Razão social, fantasia, CNPJ/CPF, telefone, email..." />
                            </div>

                            <div class="col-md-3">
                                <label class="form-label small mb-1">Situação</label>
                                <select class="form-select form-select-sm" id="fStatus">
                                    <option value="">Todas</option>
                                    <option value="ATIVO">Ativo</option>
                                    <option value="INATIVO">Inativo</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label small mb-1">UF</label>
                                <input type="text" class="form-control form-control-sm" id="fUf" maxlength="2" placeholder="Ex: PE" />
                            </div>

                            <div class="col-md-2 text-end">
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
                            <span class="small text-muted">Fornecedores cadastrados</span>
                            <span class="small text-muted" id="lblTotal">—</span>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Razão Social / Nome</th>
                                        <th>Fantasia</th>
                                        <th>Tipo</th>
                                        <th>CPF / CNPJ</th>
                                        <th>Telefone</th>
                                        <th>Email</th>
                                        <th>Cidade/UF</th>
                                        <th>Situação</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="tbFornecedores">
                                    <tr>
                                        <td colspan="10" class="text-muted small">Carregando...</td>
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

    <!-- ══ Modal Fornecedor ══════════════════════════════════════════ -->
    <div class="modal fade" id="modalFornecedor" tabindex="-1" aria-labelledby="modalFornecedorLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl2 modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modalFornecedorLabel">Cadastro de fornecedor</h5>
                        <div class="help-mini">Preencha os dados cadastrais e de contato.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <form id="frmFornecedor" autocomplete="off">
                        <input type="hidden" id="FOR_CODIGO_PK" name="FOR_CODIGO_PK" value="">

                        <div class="row g-3">

                            <!-- Status -->
                            <div class="col-12 col-md-2">
                                <label class="form-label small">Status</label>
                                <select class="form-select form-select-sm" id="FOR_STATUS" name="FOR_STATUS">
                                    <option value="ATIVO">Ativo</option>
                                    <option value="INATIVO">Inativo</option>
                                </select>
                            </div>

                            <!-- Tipo de pessoa -->
                            <div class="col-12 col-md-5">
                                <label class="form-label small">Tipo de Pessoa *</label>
                                <select class="form-select form-select-sm" id="FOR_TIPO" name="FOR_TIPO">
                                    <option value="JURIDICA">Jurídica</option>
                                    <option value="FISICA">Física</option>
                                </select>
                            </div>

                            <!-- CPF / CNPJ — label e máscara trocam conforme o tipo -->
                            <div class="col-12 col-md-5">
                                <label class="form-label small" id="labelDocumento">CNPJ *</label>
                                <input type="text" class="form-control form-control-sm mono"
                                    id="FOR_CNPJ" name="FOR_CNPJ"
                                    placeholder="00.000.000/0000-00"
                                    maxlength="18" required>
                            </div>

                            <!-- Razão Social / Nome — label troca conforme o tipo -->

                            <div class="col-12 col-md-6">
                                <label class="form-label small">Nome Fantasia</label>
                                <input type="text" class="form-control form-control-sm"
                                    id="FOR_NOME_FANTASIA" name="FOR_NOME_FANTASIA">
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label small" id="labelRazaoSocial">Razão Social *</label>
                                <input type="text" class="form-control form-control-sm"
                                    id="FOR_RAZAO_SOCIAL" name="FOR_RAZAO_SOCIAL" required>
                            </div>



                            <div class="col-12 col-md-3">
                                <label class="form-label small">Telefone *</label>
                                <input type="text" class="form-control form-control-sm"
                                    id="FOR_TELEFONE" name="FOR_TELEFONE"
                                    placeholder="(00) 00000-0000" required>
                            </div>

                            <div class="col-12 col-md-9">
                                <label class="form-label small">Email</label>
                                <input type="email" class="form-control form-control-sm"
                                    id="FOR_EMAIL" name="FOR_EMAIL">
                            </div>

                            <div class="col-12">
                                <hr class="my-2">
                                <div class="small text-muted mb-1">Endereço do fornecedor</div>
                            </div>

                            <div class="col-12 col-md-3">
                                <label class="form-label small">CEP *</label>
                                <input type="text" class="form-control form-control-sm"
                                    id="FOR_CEP" name="FOR_CEP" placeholder="00000-000" required>
                            </div>

                            <div class="col-12 col-md-5">
                                <label class="form-label small">Endereço *</label>
                                <input type="text" class="form-control form-control-sm"
                                    id="FOR_ENDERECO" name="FOR_ENDERECO" required>
                            </div>

                            <div class="col-12 col-md-2">
                                <label class="form-label small">Número *</label>
                                <input type="text" class="form-control form-control-sm"
                                    id="FOR_NUMERO" name="FOR_NUMERO" required>
                            </div>

                            <div class="col-12 col-md-2">
                                <label class="form-label small">Complemento</label>
                                <input type="text" class="form-control form-control-sm"
                                    id="FOR_COMPLEMENTO" name="FOR_COMPLEMENTO">
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label small">Bairro *</label>
                                <input type="text" class="form-control form-control-sm"
                                    id="FOR_BAIRRO" name="FOR_BAIRRO" required>
                            </div>

                            <div class="col-12 col-md-2">
                                <label class="form-label small">UF *</label>
                                <select class="form-select form-select-sm" id="FOR_UF" name="FOR_UF" required>
                                    <option value="">Selecione</option>
                                </select>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label small">Cidade *</label>
                                <select class="form-select form-select-sm" id="FOR_CIDADE" name="FOR_CIDADE" required>
                                    <option value="">Selecione o estado</option>
                                </select>
                            </div>

                        </div>
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnSalvarFornecedor">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        const ENDPOINT = 'endpoints/fornecedores.php';

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
            } catch {
                console.error('NÃO JSON:', txt);
                throw new Error('Endpoint não retornou JSON.');
            }

            if (!j.ok) throw new Error(j.msg || 'Erro na requisição');
            return j;
        }

        const modalFornecedor = new bootstrap.Modal(document.getElementById('modalFornecedor'));

        function badgeStatus(s) {
            if (s === 'ATIVO') return '<span class="badge-soft-success">ATIVO</span>';
            return '<span class="badge-soft-danger">INATIVO</span>';
        }

        function badgeTipo(t) {
            if (t === 'FISICA') return '<span class="badge bg-light text-secondary border">Física</span>';
            return '<span class="badge bg-light text-secondary border">Jurídica</span>';
        }

        const safe = (v) => (v ?? '').toString();

        function onlyDigits(v) {
            return (v || '').toString().replace(/\D/g, '');
        }

        /* ── Máscaras ── */
        function maskCEP(v) {
            const d = onlyDigits(v).slice(0, 8);
            if (d.length <= 5) return d;
            return d.slice(0, 5) + '-' + d.slice(5);
        }

        function maskCNPJ(v) {
            const d = onlyDigits(v).slice(0, 14);
            return d
                .replace(/^(\d{2})(\d)/, '$1.$2')
                .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
                .replace(/\.(\d{3})(\d)/, '.$1/$2')
                .replace(/(\d{4})(\d)/, '$1-$2');
        }

        function maskCPF(v) {
            const d = onlyDigits(v).slice(0, 11);
            return d
                .replace(/^(\d{3})(\d)/, '$1.$2')
                .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
                .replace(/\.(\d{3})(\d)/, '.$1-$2');
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

        /* ── Validações no front ── */
        function validarCPF(cpf) {
            const d = onlyDigits(cpf);
            if (d.length !== 11 || /^(\d)\1{10}$/.test(d)) return false;
            let sum, rem;
            for (let t = 9; t <= 10; t++) {
                sum = 0;
                for (let i = 0; i < t; i++) sum += parseInt(d[i]) * (t + 1 - i);
                rem = (10 * sum) % 11;
                if (parseInt(d[t]) !== (rem >= 10 ? 0 : rem)) return false;
            }
            return true;
        }

        function validarCNPJ(cnpj) {
            const d = onlyDigits(cnpj);
            if (d.length !== 14 || /^(\d)\1{13}$/.test(d)) return false;
            const calc = (n, len) => {
                let sum = 0,
                    pos = len - 7;
                for (let i = len; i >= 1; i--) {
                    sum += parseInt(n[len - i]) * pos--;
                    if (pos < 2) pos = 9;
                }
                const rem = sum % 11;
                return rem < 2 ? 0 : 11 - rem;
            };
            return parseInt(d[12]) === calc(d, 12) && parseInt(d[13]) === calc(d, 13);
        }

        /* ── Lógica de tipo de pessoa ── */
        const tipoSelect = document.getElementById('FOR_TIPO');
        const docInput = document.getElementById('FOR_CNPJ');
        const labelDoc = document.getElementById('labelDocumento');
        const labelRazao = document.getElementById('labelRazaoSocial');

        function aplicarTipo(tipo) {
            if (tipo === 'FISICA') {
                labelDoc.textContent = 'CPF *';
                labelRazao.textContent = 'Nome *';
                docInput.placeholder = '000.000.000-00';
                docInput.maxLength = 14;
            } else {
                labelDoc.textContent = 'CNPJ *';
                labelRazao.textContent = 'Razão Social *';
                docInput.placeholder = '00.000.000/0000-00';
                docInput.maxLength = 18;
            }
            docInput.value = ''; // limpa ao trocar tipo
        }

        tipoSelect.addEventListener('change', () => aplicarTipo(tipoSelect.value));

        // Máscara dinâmica: CPF ou CNPJ conforme o tipo selecionado
        docInput.addEventListener('input', (e) => {
            e.target.value = tipoSelect.value === 'FISICA' ?
                maskCPF(e.target.value) :
                maskCNPJ(e.target.value);
        });

        /* ── UFs e cidades ── */
        const UFS = [
            'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
            'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
            'SP', 'SE', 'TO'
        ];

        function carregarUFs() {
            const ufSel = document.getElementById('FOR_UF');
            if (!ufSel) return;
            ufSel.innerHTML = '<option value="">Selecione</option>';
            UFS.forEach(uf => {
                ufSel.insertAdjacentHTML('beforeend', `<option value="${uf}">${uf}</option>`);
            });
        }

        async function carregarCidades(uf, cidadeSelecionada = '') {
            const cidSel = document.getElementById('FOR_CIDADE');
            if (!cidSel) return;

            cidSel.innerHTML = '<option value="">Carregando...</option>';

            if (!uf) {
                cidSel.innerHTML = '<option value="">Selecione o estado</option>';
                return;
            }

            try {
                const r = await fetch(
                    `https://servicodados.ibge.gov.br/api/v1/localidades/estados/${uf}/municipios`, {
                        cache: 'no-store'
                    }
                );
                const cidades = await r.json();

                cidSel.innerHTML = '<option value="">Selecione</option>';
                cidades.forEach(c => {
                    const sel = (cidadeSelecionada && c.nome === cidadeSelecionada) ? 'selected' : '';
                    cidSel.insertAdjacentHTML('beforeend', `<option value="${c.nome}" ${sel}>${c.nome}</option>`);
                });

                if (cidadeSelecionada) cidSel.value = cidadeSelecionada;
            } catch {
                cidSel.innerHTML = '<option value="">Erro ao carregar cidades</option>';
            }
        }

        /* ── Busca de CEP ── */
        async function buscarCepFornecedor() {
            const cep = onlyDigits(document.getElementById('FOR_CEP').value);
            if (cep.length !== 8) return;

            try {
                const r = await fetch(`https://viacep.com.br/ws/${cep}/json/`, {
                    cache: 'no-store'
                });
                const j = await r.json();
                if (j.erro) throw new Error('CEP não encontrado.');

                document.getElementById('FOR_ENDERECO').value = j.logradouro || '';
                document.getElementById('FOR_BAIRRO').value = j.bairro || '';

                const uf = (j.uf || '').toUpperCase();
                document.getElementById('FOR_UF').value = uf;
                await carregarCidades(uf, j.localidade || '');

                document.getElementById('FOR_NUMERO').focus();
            } catch (e) {
                Swal.fire({
                    icon: 'warning',
                    title: 'CEP',
                    text: e.message
                });
            }
        }

        /* ── Form helpers ── */
        function limparForm() {
            document.getElementById('frmFornecedor').reset();
            document.getElementById('FOR_CODIGO_PK').value = '';
            document.getElementById('FOR_STATUS').value = 'ATIVO';
            document.getElementById('FOR_TIPO').value = 'JURIDICA';
            aplicarTipo('JURIDICA');

            const ufSel = document.getElementById('FOR_UF');
            const cidSel = document.getElementById('FOR_CIDADE');
            if (ufSel) ufSel.value = '';
            if (cidSel) cidSel.innerHTML = '<option value="">Selecione o estado</option>';
        }

        function getForm() {
            return {
                FOR_CODIGO_PK: (document.getElementById('FOR_CODIGO_PK').value || '').trim(),
                FOR_STATUS: document.getElementById('FOR_STATUS').value,
                FOR_TIPO: document.getElementById('FOR_TIPO').value,
                FOR_CNPJ: document.getElementById('FOR_CNPJ').value.trim(),
                FOR_RAZAO_SOCIAL: document.getElementById('FOR_RAZAO_SOCIAL').value.trim(),
                FOR_NOME_FANTASIA: document.getElementById('FOR_NOME_FANTASIA').value.trim(),
                FOR_CEP: document.getElementById('FOR_CEP').value.trim(),
                FOR_ENDERECO: document.getElementById('FOR_ENDERECO').value.trim(),
                FOR_NUMERO: document.getElementById('FOR_NUMERO').value.trim(),
                FOR_COMPLEMENTO: document.getElementById('FOR_COMPLEMENTO').value.trim(),
                FOR_BAIRRO: document.getElementById('FOR_BAIRRO').value.trim(),
                FOR_UF: (document.getElementById('FOR_UF').value || '').trim().toUpperCase(),
                FOR_CIDADE: (document.getElementById('FOR_CIDADE').value || '').trim(),
                FOR_TELEFONE: document.getElementById('FOR_TELEFONE').value.trim(),
                FOR_EMAIL: document.getElementById('FOR_EMAIL').value.trim(),
            };
        }

        async function setForm(u) {
            const tipo = (u.FOR_TIPO || 'JURIDICA').toUpperCase();
            document.getElementById('FOR_TIPO').value = tipo;
            aplicarTipo(tipo);

            document.getElementById('FOR_CODIGO_PK').value = u.FOR_CODIGO_PK || '';
            document.getElementById('FOR_STATUS').value = u.FOR_STATUS || 'ATIVO';
            document.getElementById('FOR_CNPJ').value = u.FOR_CNPJ || '';
            document.getElementById('FOR_RAZAO_SOCIAL').value = u.FOR_RAZAO_SOCIAL || '';
            document.getElementById('FOR_NOME_FANTASIA').value = u.FOR_NOME_FANTASIA || '';
            document.getElementById('FOR_CEP').value = u.FOR_CEP || '';
            document.getElementById('FOR_ENDERECO').value = u.FOR_ENDERECO || '';
            document.getElementById('FOR_NUMERO').value = u.FOR_NUMERO || '';
            document.getElementById('FOR_COMPLEMENTO').value = u.FOR_COMPLEMENTO || '';
            document.getElementById('FOR_BAIRRO').value = u.FOR_BAIRRO || '';
            document.getElementById('FOR_TELEFONE').value = u.FOR_TELEFONE || '';
            document.getElementById('FOR_EMAIL').value = u.FOR_EMAIL || '';

            const uf = (u.FOR_UF || '').toUpperCase();
            document.getElementById('FOR_UF').value = uf;
            await carregarCidades(uf, u.FOR_CIDADE || '');
        }

        /* ── Listagem ── */
        async function listar() {
            const buscar = document.getElementById('fBuscar').value.trim();
            const status = document.getElementById('fStatus').value;
            const uf = document.getElementById('fUf').value.trim().toUpperCase();

            const j = await api({
                acao: 'listar',
                buscar,
                status,
                uf
            }, 'GET');

            const tb = document.getElementById('tbFornecedores');
            tb.innerHTML = '';
            document.getElementById('lblTotal').textContent = `${j.total} registro(s)`;

            if (!j.rows || j.rows.length === 0) {
                tb.innerHTML = '<tr><td colspan="10" class="text-muted small">Nenhum cadastro encontrado.</td></tr>';
                return;
            }

            j.rows.forEach((r, i) => {
                const btnEdit = `<button class="btn btn-sm btn-outline-primary me-1" title="Editar" data-id="${r.FOR_CODIGO_PK}" data-act="editar"><i class="fa-solid fa-pen"></i></button>`;
                const btnStatus = (r.FOR_STATUS === 'ATIVO') ?
                    `<button class="btn btn-sm btn-outline-warning" title="Inativar" data-id="${r.FOR_CODIGO_PK}" data-act="inativar"><i class="fa-solid fa-ban"></i></button>` :
                    `<button class="btn btn-sm btn-outline-success" title="Reativar" data-id="${r.FOR_CODIGO_PK}" data-act="reativar"><i class="fa-solid fa-rotate"></i></button>`;

                tb.insertAdjacentHTML('beforeend', `
                <tr>
                    <td>${i + 1}</td>
                    <td>${safe(r.FOR_RAZAO_SOCIAL)}</td>
                    <td>${safe(r.FOR_NOME_FANTASIA)}</td>
                    <td>${badgeTipo(r.FOR_TIPO)}</td>
                    <td>${safe(r.FOR_CNPJ)}</td>
                    <td>${safe(r.FOR_TELEFONE)}</td>
                    <td>${safe(r.FOR_EMAIL)}</td>
                    <td>${safe(r.FOR_CIDADE)}${r.FOR_UF ? '/' + safe(r.FOR_UF) : ''}</td>
                    <td>${badgeStatus(r.FOR_STATUS)}</td>
                    <td class="text-end">${btnEdit}${btnStatus}</td>
                </tr>
            `);
            });
        }

        /* ── Modal: novo / editar ── */
        async function abrirNovo() {
            limparForm();
            document.getElementById('modalFornecedorLabel').textContent = 'Novo cadastro de fornecedor';
            modalFornecedor.show();
        }

        async function abrirEditar(id) {
            const j = await api({
                acao: 'get',
                id
            }, 'GET');
            limparForm();
            await setForm(j.row);
            document.getElementById('modalFornecedorLabel').textContent = `Editar fornecedor #${j.row.FOR_CODIGO_PK}`;
            modalFornecedor.show();
        }

        /* ── Salvar ── */
        async function salvar() {
            const d = getForm();
            const tipo = d.FOR_TIPO;

            // Validação no front
            if (tipo === 'FISICA') {
                if (!validarCPF(d.FOR_CNPJ)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Atenção',
                        text: 'Informe um CPF válido.'
                    });
                    return;
                }
            } else {
                if (!validarCNPJ(d.FOR_CNPJ)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Atenção',
                        text: 'Informe um CNPJ válido.'
                    });
                    return;
                }
            }

            const labelNome = tipo === 'FISICA' ? 'nome' : 'razão social';
            if (!d.FOR_RAZAO_SOCIAL) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: `Informe o ${labelNome}.`
                });
                return;
            }

            try {
                await api({
                    acao: 'salvar',
                    ...d
                }, 'POST');

                modalFornecedor.hide();
                Swal.fire({
                    icon: 'success',
                    title: 'Salvo',
                    text: 'Cadastro salvo com sucesso!',
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

        /* ── Inativar / Reativar ── */
        async function inativar(id) {
            const r = await Swal.fire({
                icon: 'warning',
                title: 'Inativar cadastro?',
                text: 'Ele ficará INATIVO (não exclui).',
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
                text: 'Inativado.',
                timer: 900,
                showConfirmButton: false
            });
            await listar();
        }

        async function reativar(id) {
            const r = await Swal.fire({
                icon: 'question',
                title: 'Reativar cadastro?',
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
                text: 'Reativado.',
                timer: 900,
                showConfirmButton: false
            });
            await listar();
        }

        /* ── Event listeners ── */
        document.getElementById('btnNovoFornecedor').addEventListener('click', abrirNovo);
        document.getElementById('btnSalvarFornecedor').addEventListener('click', salvar);

        document.getElementById('frmFiltros').addEventListener('submit', (e) => {
            e.preventDefault();
            listar().catch(err => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: err.message
            }));
        });

        document.getElementById('tbFornecedores').addEventListener('click', (e) => {
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

        document.getElementById('FOR_TELEFONE').addEventListener('input', (e) => e.target.value = maskPhone(e.target.value));
        document.getElementById('FOR_CEP').addEventListener('input', (e) => e.target.value = maskCEP(e.target.value));
        document.getElementById('FOR_CEP').addEventListener('blur', buscarCepFornecedor);
        document.getElementById('FOR_CEP').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                buscarCepFornecedor();
            }
        });

        document.getElementById('FOR_UF').addEventListener('change', async (e) => {
            await carregarCidades((e.target.value || '').toUpperCase(), '');
        });

        document.getElementById('fUf').addEventListener('input', (e) => {
            e.target.value = e.target.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2);
        });

        /* ── Init ── */
        carregarUFs();
        document.getElementById('FOR_CIDADE').innerHTML = '<option value="">Selecione o estado</option>';
        aplicarTipo('JURIDICA');

        listar().catch(err => Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: err.message
        }));
    </script>

  <script src="assets/session_keeper.js" defer></script>
</body>

</html>