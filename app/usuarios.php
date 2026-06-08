<?php
// /app/usuarios.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php'; // exige login
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Usuários</title>
    <?php include __DIR__ . '/includes/head.php'; ?>


    <style>
        .pw-bar {
            height: 8px;
            background: rgba(100, 116, 139, .18);
            border-radius: 999px;
            overflow: hidden;
        }

        .pw-bar span {
            display: block;
            height: 100%;
            width: 0%;
            border-radius: 999px;
            transition: width .18s ease;
        }

        .pw-text {
            margin-top: 6px;
            font-size: .82rem;
            color: #64748b;
        }
    </style>
</head>

<body data-page="usuarios">
    <div class="d-flex" id="wrapper">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">
            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Cadastro de Usuários</span>

                <div class="collapse navbar-collapse justify-content-end">
                    <ul class="navbar-nav mb-2 mb-lg-0 align-items-center">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown"
                                role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fa-solid fa-circle-user fa-lg me-1"></i>
                                <span class="small"><?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li><a class="dropdown-item" href="meu_perfil.php">Meu Perfil</a></li>
                                <li>
                                    <hr class="dropdown-divider" />
                                </li>
                                <li><a class="dropdown-item" href="logout.php">Sair</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="container-fluid py-4">

                <!-- Filtros -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Buscar</label>
                                <input id="fBusca" type="text" class="form-control form-control-sm" placeholder="Nome, e-mail, cargo..." />
                            </div>

                            <div class="col-md-2">
                                <label class="form-label small mb-1">Perfil</label>
                                <select id="fPerfil" class="form-select form-select-sm">
                                    <option value="">Todos</option>
                                    <option value="ADMIN">Administrador</option>
                                    <option value="USER">Usuário</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label small mb-1">Situação</label>
                                <select id="fStatus" class="form-select form-select-sm">
                                    <option value="">Todos</option>
                                    <option value="ATIVO">Ativo</option>
                                    <option value="INATIVO">Inativo</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label small mb-1">Empresa</label>
                                <select id="fEmpresa" class="form-select form-select-sm">
                                    <option value="">Todas</option>
                                </select>
                            </div>

                            <div class="col-md-1 text-md-end">
                                <button id="btnFiltrar" class="btn btn-sm btn-outline-secondary me-2">
                                    <i class="fa-solid fa-magnifying-glass me-1"></i>
                                </button>
                                <button id="btnNovo" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalUsuario">
                                    <i class="fa-solid fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lista -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Usuários Cadastrados</h6>
                        <span class="small text-muted" id="lblTotal">—</span>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Nome</th>
                                        <th>E-mail</th>
                                        <th>Cargo</th>
                                        <th>Empresa</th>
                                        <th>Perfil</th>
                                        <th class="text-center">Situação</th>
                                        <th class="text-end">Último Acesso</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="tbUsuarios"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Modal Cadastro/Edição -->
                <div class="modal fade" id="modalUsuario" tabindex="-1" aria-labelledby="modalUsuarioLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="modalUsuarioLabel">Cadastro de Usuário</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>

                            <div class="modal-body">
                                <form id="frmUsuario" autocomplete="off">
                                    <input type="hidden" id="USU_ID" name="USU_ID" value="">

                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label small">Código do Usuário</label>
                                            <input type="text" class="form-control form-control-sm" id="USU_ID_VIEW" value="(novo)" disabled>
                                        </div>

                                        <div class="col-md-5">
                                            <label class="form-label small">Nome</label>
                                            <input type="text" class="form-control form-control-sm" id="USU_NOME" name="USU_NOME" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label small">Cargo</label>
                                            <input type="text" class="form-control form-control-sm" id="USU_CARGO" name="USU_CARGO"
                                                placeholder="Ex: Financeiro, Gerente, Diretor">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label small">CPF / CNPJ *</label>
                                            <input type="text" class="form-control form-control-sm mono" id="USU_CPF_CNPJ" name="USU_CPF_CNPJ" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label small">Telefone *</label>
                                            <input type="text" class="form-control form-control-sm" id="USU_TELEFONE" name="USU_TELEFONE" placeholder="(00) 00000-0000" required>
                                        </div>

                                        <div class="col-12"><hr class="my-1"><div class="small text-muted">Endereço</div></div>

                                        <div class="col-md-3">
                                            <label class="form-label small">CEP *</label>
                                            <input type="text" class="form-control form-control-sm" id="USU_CEP" name="USU_CEP" placeholder="00000-000" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small">Endereço *</label>
                                            <input type="text" class="form-control form-control-sm" id="USU_ENDERECO" name="USU_ENDERECO" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small">Número *</label>
                                            <input type="text" class="form-control form-control-sm" id="USU_NUMERO" name="USU_NUMERO" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small">Complemento</label>
                                            <input type="text" class="form-control form-control-sm" id="USU_COMPLEMENTO" name="USU_COMPLEMENTO">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small">Bairro *</label>
                                            <input type="text" class="form-control form-control-sm" id="USU_BAIRRO" name="USU_BAIRRO" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small">UF *</label>
                                            <input type="text" class="form-control form-control-sm" id="USU_UF" name="USU_UF" maxlength="2" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small">Cidade *</label>
                                            <input type="text" class="form-control form-control-sm" id="USU_CIDADE" name="USU_CIDADE" required>
                                        </div>
                                        <div class="col-12"><hr class="my-1"></div>

                                        <div class="col-md-4">
                                            <label class="form-label small">Login</label>
                                            <input type="text" class="form-control form-control-sm" disabled placeholder="(vamos usar o e-mail)">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label small">E-mail</label>
                                            <input type="email" class="form-control form-control-sm" id="USU_EMAIL" name="USU_EMAIL" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label small">Perfil de Acesso</label>
                                            <select class="form-select form-select-sm" id="USU_PERFIL" name="USU_PERFIL">
                                                <option value="ADMIN">Administrador</option>
                                                <option value="USER">Usuário</option>
                                            </select>
                                        </div>

                                        <div class="col-12 col-md-6">
                                            <label class="form-label small">Empresa</label>
                                            <select class="form-select form-select-sm" id="USU_EMPRESA_ID" name="USU_EMPRESA_ID" required>
                                                <option value="">Selecione a empresa</option>
                                            </select>
                                        </div>

                                        <div class="col-md-6 d-flex align-items-end">
                                            <div class="form-check mt-3">
                                                <input class="form-check-input" type="checkbox" id="acessoTodasEmpresas">
                                                <label class="form-check-label small" for="acessoTodasEmpresas">
                                                    Usuário com acesso a <strong>todas as empresas</strong> do sistema
                                                </label>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label small">Senha</label>
                                            <input type="password" class="form-control form-control-sm" id="SENHA" name="SENHA" placeholder="(deixe em branco para não alterar)">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small">Confirmar Senha</label>
                                            <input type="password" class="form-control form-control-sm" id="SENHA2" name="SENHA2" placeholder="(confirme a senha)">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label small">Situação</label>
                                            <select class="form-select form-select-sm" id="USU_STATUS" name="USU_STATUS">
                                                <option value="ATIVO">Ativo</option>
                                                <option value="INATIVO">Inativo</option>
                                            </select>
                                        </div>

                                        <div class="col-12" id="pwMeterWrap" style="display:none;">
                                            <div class="pw-bar">
                                                <span id="pwBar"></span>
                                            </div>
                                            <div class="pw-text" id="pwText">Força: —</div>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label small">Observações</label>
                                            <textarea class="form-control form-control-sm" rows="2" id="USU_OBSERVACAO" name="USU_OBSERVACAO"
                                                placeholder="Observações do usuário..."></textarea>

                                        </div>
                                    </div>
                                </form>
                                <div class="small text-muted mt-2" id="lblTravaAdmin" style="display:none;">
                                    <i class="fa-solid fa-lock me-1"></i>Usuários ADMIN não podem ser alterados por usuários comuns.
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                                <button type="button" class="btn btn-primary btn-sm" id="btnSalvar">
                                    <i class="fa-solid fa-floppy-disk me-1"></i>Salvar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- container -->
        </div><!-- content -->
    </div><!-- wrapper -->

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        const IS_ADMIN = <?= json_encode(($_SESSION['user_perfil'] ?? '') === 'ADMIN') ?>;
        const MEU_ID = <?= json_encode((int)($_SESSION['user_id'] ?? 0)) ?>;
    </script>

    <script>
        if (!IS_ADMIN) {
            const btnNovo = document.getElementById('btnNovo');
            if (btnNovo) btnNovo.style.display = 'none';
        }

        const ENDPOINT = 'endpoints/usuarios.php';
        const ENDPOINT_EMPRESAS = 'endpoints/empresas.php';

        async function carregarEmpresasSelect(selectEl, selectedId = '') {
            const r = await fetch(`${ENDPOINT_EMPRESAS}?acao=listar&status=ATIVO`);
            const j = await r.json();
            if (!j.ok) throw new Error(j.msg || 'Erro ao carregar empresas.');

            selectEl.innerHTML = '<option value="">Selecione a empresa</option>';

            (j.rows || []).forEach(emp => {
                const opt = document.createElement('option');
                opt.value = emp.EMP_ID;
                opt.textContent = emp.EMP_NOME_FANTASIA || emp.EMP_RAZAO_SOCIAL;
                if (String(emp.EMP_ID) === String(selectedId)) opt.selected = true;
                selectEl.appendChild(opt);
            });
        }

        function fmtDateTimeBR(isoOrNull) {
            if (!isoOrNull) return '-';
            const d = new Date(String(isoOrNull).replace(' ', 'T'));
            if (isNaN(d.getTime())) return String(isoOrNull);
            const pad = n => String(n).padStart(2, '0');
            return `${pad(d.getDate())}/${pad(d.getMonth()+1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
        }

        function badgeStatus(status) {
            if (status === 'ATIVO') return '<span class="badge bg-success">Ativo</span>';
            return '<span class="badge bg-secondary">Inativo</span>';
        }

        function labelPerfil(p) {
            return p === 'ADMIN' ? 'Administrador' : 'Usuário';
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
                console.error('Resposta NÃO é JSON:', txt);
                throw new Error('Endpoint não retornou JSON. Veja o console (F12).');
            }

            if (!j.ok) throw new Error(j.msg || 'Falha na requisição.');
            return j;
        }

        async function carregar() {
            const q = document.getElementById('fBusca').value.trim();
            const perfil = document.getElementById('fPerfil').value;
            const status = document.getElementById('fStatus').value;
            const empresaId = document.getElementById('fEmpresa').value;

            const j = await api({
                acao: 'listar',
                q,
                perfil,
                status,
                empresaId
            }, 'GET');
            const rows = j.rows || [];

            document.getElementById('lblTotal').textContent = `${rows.length} registro(s)`;

            const tb = document.getElementById('tbUsuarios');
            tb.innerHTML = rows.map(r => {

                const isAdminTarget = (r.USU_PERFIL === 'ADMIN');
                const podeMexer = IS_ADMIN; // ações só pra ADMIN

                const acoesHtml = podeMexer ? `
                    <button class="btn btn-sm btn-outline-secondary me-1 btn-edit" data-id="${r.USU_ID}">
                        <i class="fa-solid fa-pen"></i>
                    </button>

                    ${
                        r.USU_STATUS === 'ATIVO'
                        ? `
                            <button class="btn btn-sm btn-outline-danger me-1 btn-inativar"
                                    data-id="${r.USU_ID}"
                                    data-nome="${escapeHtmlAttr(r.USU_NOME)}"
                                    data-perfil="${r.USU_PERFIL}">
                                <i class="fa-solid fa-user-slash"></i>
                            </button>
                        `
                        : `
                            <button class="btn btn-sm btn-outline-success me-1 btn-reativar"
                                    data-id="${r.USU_ID}"
                                    data-nome="${escapeHtmlAttr(r.USU_NOME)}"
                                    data-perfil="${r.USU_PERFIL}">
                                <i class="fa-solid fa-user-check"></i>
                            </button>
                        `
                    }

                    <button class="btn btn-sm btn-outline-warning btn-reset"
                            data-id="${r.USU_ID}"
                            data-nome="${escapeHtmlAttr(r.USU_NOME)}"
                            data-perfil="${r.USU_PERFIL}">
                        <i class="fa-solid fa-key"></i>
                    </button>
                ` : `<span class="text-muted small">—</span>`;

                return `
                    <tr>
                        <td>${r.USU_ID}</td>
                        <td>${escapeHtml(r.USU_NOME)}</td>
                        <td>${escapeHtml(r.USU_EMAIL)}</td>
                        <td>${escapeHtml(r.USU_CARGO || '-')}</td>
                        <td>${r.USU_ACESSO_TODAS_EMPRESAS === 'SIM' ? '<span class="badge bg-dark">Todas</span>' : escapeHtml(r.EMP_NOME || '-')}</td>
                        <td>${labelPerfil(r.USU_PERFIL)}</td>
                        <td class="text-center">${badgeStatus(r.USU_STATUS)}</td>
                        <td class="text-end">${fmtDateTimeBR(r.USU_ULTIMO_LOGIN)}</td>
                        <td class="text-end">${acoesHtml}</td>
                    </tr>
                `;
            }).join('');
        }

        function resetModal() {
            document.getElementById('lblTravaAdmin').style.display = 'none';
            document.getElementById('modalUsuarioLabel').textContent = 'Cadastro de Usuário';
            document.getElementById('USU_ID').value = '';
            document.getElementById('USU_ID_VIEW').value = '(novo)';
            document.getElementById('USU_NOME').value = '';
            document.getElementById('USU_EMAIL').value = '';
            document.getElementById('USU_CARGO').value = '';
            document.getElementById('USU_PERFIL').value = 'USER';
            document.getElementById('USU_STATUS').value = 'ATIVO';
            document.getElementById('SENHA').value = '';
            document.getElementById('SENHA2').value = '';
            document.getElementById('USU_EMPRESA_ID').value = '';
            document.getElementById('acessoTodasEmpresas').checked = false;
            document.getElementById('USU_OBSERVACAO').value = '';
            document.getElementById('USU_EMPRESA_ID').disabled = false;
            ['USU_CPF_CNPJ','USU_TELEFONE','USU_CEP','USU_ENDERECO','USU_NUMERO','USU_COMPLEMENTO','USU_BAIRRO','USU_CIDADE','USU_UF']
                .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });

            // habilita campos (pode travar depois)
            ['USU_NOME', 'USU_EMAIL', 'USU_CARGO', 'USU_PERFIL', 'USU_STATUS', 'USU_EMPRESA_ID', 'SENHA', 'SENHA2', 'USU_OBSERVACAO', 'acessoTodasEmpresas',
             'USU_CPF_CNPJ','USU_TELEFONE','USU_CEP','USU_ENDERECO','USU_NUMERO','USU_COMPLEMENTO','USU_BAIRRO','USU_CIDADE','USU_UF']
            .forEach(id => {
                const el = document.getElementById(id);
                if (el) el.disabled = false;
            });

        }

        function travarEdicaoAdmin() {
            document.getElementById('lblTravaAdmin').style.display = '';
            ['USU_NOME', 'USU_EMAIL', 'USU_CARGO', 'USU_PERFIL', 'USU_STATUS', 'USU_EMPRESA_ID', 'SENHA', 'SENHA2']
            .forEach(id => {
                const el = document.getElementById(id);
                if (el) el.disabled = true;
            });
        }

        async function abrirEdicao(id) {
            resetModal();
            const j = await api({
                acao: 'obter',
                id
            }, 'GET');
            const r = j.row;

            document.getElementById('modalUsuarioLabel').textContent = 'Editar Usuário';
            document.getElementById('USU_ID').value = r.USU_ID;
            document.getElementById('USU_ID_VIEW').value = r.USU_ID;
            document.getElementById('USU_NOME').value = r.USU_NOME;
            document.getElementById('USU_EMAIL').value = r.USU_EMAIL;
            document.getElementById('USU_CARGO').value = r.USU_CARGO || '';
            document.getElementById('USU_PERFIL').value = r.USU_PERFIL;
            document.getElementById('USU_STATUS').value = r.USU_STATUS;
            document.getElementById('USU_OBSERVACAO').value = r.USU_OBSERVACAO || '';
            document.getElementById('acessoTodasEmpresas').checked = (r.USU_ACESSO_TODAS_EMPRESAS === 'SIM');

            // Novos campos cadastrais (A.4) — podem vir vazios em usuários legados
            document.getElementById('USU_CPF_CNPJ').value    = r.USU_CPF_CNPJ    || '';
            document.getElementById('USU_TELEFONE').value    = r.USU_TELEFONE    || '';
            document.getElementById('USU_CEP').value         = r.USU_CEP         || '';
            document.getElementById('USU_ENDERECO').value    = r.USU_ENDERECO    || '';
            document.getElementById('USU_NUMERO').value      = r.USU_NUMERO      || '';
            document.getElementById('USU_COMPLEMENTO').value = r.USU_COMPLEMENTO || '';
            document.getElementById('USU_BAIRRO').value      = r.USU_BAIRRO      || '';
            document.getElementById('USU_CIDADE').value      = r.USU_CIDADE      || '';
            document.getElementById('USU_UF').value          = r.USU_UF          || '';


            // carrega empresas e seleciona a do usuário
            await carregarEmpresasSelect(document.getElementById('USU_EMPRESA_ID'), r.USU_EMPRESA_ID || '');

            const ck = document.getElementById('acessoTodasEmpresas');
            const sel = document.getElementById('USU_EMPRESA_ID');
            if (ck && sel) {
                if (ck.checked) {
                    sel.disabled = true;
                    sel.value = '';
                } else sel.disabled = false;
            }


            // trava: ADMIN só pode ser mexido por ADMIN (frontend)
            // (backend também bloqueia)
            if (!IS_ADMIN && r.USU_PERFIL === 'ADMIN') {
                travarEdicaoAdmin();
            }

            new bootstrap.Modal(document.getElementById('modalUsuario')).show();
        }

        async function salvar() {
            const id = (document.getElementById('USU_ID')?.value || '').trim();
            const nome = (document.getElementById('USU_NOME')?.value || '').trim();
            const email = (document.getElementById('USU_EMAIL')?.value || '').trim();
            const perfil = document.getElementById('USU_PERFIL')?.value || 'USER';
            const status = document.getElementById('USU_STATUS')?.value || 'ATIVO';

            const cargo = (document.getElementById('USU_CARGO')?.value || '').trim();
            const obs = (document.getElementById('USU_OBSERVACAO')?.value || '').trim();

            const selEmp = document.getElementById('USU_EMPRESA_ID');
            const empresaId = selEmp ? String(selEmp.value || '').trim() : '';

            const ckTodas = document.getElementById('acessoTodasEmpresas');
            const acessoTodas = (ckTodas && ckTodas.checked) ? 'SIM' : 'NAO';

            console.log('DEBUG', {
                acessoTodas,
                empresaId
            });


            const senha = document.getElementById('SENHA')?.value || '';
            const senha2 = document.getElementById('SENHA2')?.value || '';

            // novos campos cadastrais (A.4)
            const cpfCnpj    = (document.getElementById('USU_CPF_CNPJ')?.value    || '').trim();
            const telefone   = (document.getElementById('USU_TELEFONE')?.value    || '').trim();
            const cep        = (document.getElementById('USU_CEP')?.value         || '').trim();
            const endereco   = (document.getElementById('USU_ENDERECO')?.value    || '').trim();
            const numero     = (document.getElementById('USU_NUMERO')?.value      || '').trim();
            const complemento= (document.getElementById('USU_COMPLEMENTO')?.value || '').trim();
            const bairro     = (document.getElementById('USU_BAIRRO')?.value      || '').trim();
            const cidade     = (document.getElementById('USU_CIDADE')?.value      || '').trim();
            const uf         = (document.getElementById('USU_UF')?.value          || '').trim().toUpperCase();

            // validações básicas
            if (!nome || !email) {
                Swal.fire({ icon: 'warning', title: 'Atenção', text: 'Informe Nome e E-mail.' });
                return;
            }
            const cpfDigits = cpfCnpj.replace(/\D/g, '');
            if (cpfDigits.length !== 11 && cpfDigits.length !== 14) {
                Swal.fire({ icon: 'warning', title: 'CPF/CNPJ', text: 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.' });
                return;
            }
            if (telefone.replace(/\D/g, '').length < 10) {
                Swal.fire({ icon: 'warning', title: 'Telefone', text: 'Informe um telefone válido.' });
                return;
            }
            if (cep.replace(/\D/g, '').length !== 8) {
                Swal.fire({ icon: 'warning', title: 'CEP', text: 'Informe um CEP válido.' });
                return;
            }
            if (!endereco || !numero || !bairro || !cidade || uf.length !== 2) {
                Swal.fire({ icon: 'warning', title: 'Endereço', text: 'Preencha o endereço completo (Endereço, Número, Bairro, Cidade e UF).' });
                return;
            }

            // validação empresa x todas
            if (acessoTodas === 'NAO' && !empresaId) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Empresa',
                    text: 'Selecione a empresa do usuário ou marque acesso a todas.'
                });
                return;
            }

            // validação senha (opcional)
            if (senha || senha2) {
                if (senha.length < 6) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Senha fraca',
                        text: 'A senha deve ter pelo menos 6 caracteres.'
                    });
                    return;
                }
                if (senha !== senha2) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Confirmação',
                        text: 'As senhas não conferem.'
                    });
                    return;
                }

                // força mínima (cadastro e edição)
                const st = (window.__drePasswordStrength ? window.__drePasswordStrength() : {
                    score: 0
                });
                if (st.score <= 1) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Senha fraca',
                        text: 'Use uma senha mais forte (mín. 6, com letras maiúsculas/minúsculas, números e/ou símbolo).'
                    });
                    return;
                }
            }

            // monta payload
            const payload = {
                acao: id ? 'editar' : 'criar',
                USU_ID: id,
                USU_NOME: nome,
                USU_EMAIL: email,
                USU_PERFIL: perfil,
                USU_STATUS: status,
                USU_CARGO: cargo,
                USU_OBSERVACAO: obs,
                USU_ACESSO_TODAS_EMPRESAS: acessoTodas,
                USU_EMPRESA_ID: (acessoTodas === 'SIM') ? '' : empresaId,
                SENHA: senha,
                USU_CPF_CNPJ: cpfCnpj,
                USU_TELEFONE: telefone,
                USU_CEP: cep,
                USU_ENDERECO: endereco,
                USU_NUMERO: numero,
                USU_COMPLEMENTO: complemento,
                USU_BAIRRO: bairro,
                USU_CIDADE: cidade,
                USU_UF: uf
            };

            try {
                await api(payload, 'POST');
                Swal.fire({
                    icon: 'success',
                    title: 'Ok',
                    text: 'Salvo com sucesso!',
                    timer: 900,
                    showConfirmButton: false
                });
                bootstrap.Modal.getInstance(document.getElementById('modalUsuario')).hide();
                await carregar();
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: e.message
                });
            }
        }


        async function inativar(id, nome, perfilAlvo) {
            if (String(id) === String(MEU_ID)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Você não pode inativar seu próprio usuário.'
                });
                return;
            }
            if (perfilAlvo === 'ADMIN') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Bloqueado',
                    text: 'Não é permitido inativar um ADMIN por aqui.'
                });
                return;
            }

            const r = await Swal.fire({
                icon: 'warning',
                title: 'Inativar usuário?',
                text: `Deseja inativar "${nome}"? Ele não poderá mais acessar o sistema.`,
                showCancelButton: true,
                confirmButtonText: 'Sim, inativar',
                cancelButtonText: 'Cancelar'
            });
            if (!r.isConfirmed) return;

            try {
                await api({
                    acao: 'excluir',
                    id
                }, 'POST');
                Swal.fire({
                    icon: 'success',
                    title: 'Ok',
                    timer: 800,
                    showConfirmButton: false
                });
                await carregar();
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: e.message
                });
            }
        }

        async function reativar(id, nome, perfilAlvo) {
            if (perfilAlvo === 'ADMIN') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Bloqueado',
                    text: 'Reativação de ADMIN é restrita.'
                });
                return;
            }

            const r = await Swal.fire({
                icon: 'question',
                title: 'Reativar usuário?',
                text: `Deseja reativar "${nome}"? Ele poderá acessar o sistema novamente.`,
                showCancelButton: true,
                confirmButtonText: 'Sim, reativar',
                cancelButtonText: 'Cancelar'
            });
            if (!r.isConfirmed) return;

            try {
                await api({
                    acao: 'reativar',
                    id
                }, 'POST');
                Swal.fire({
                    icon: 'success',
                    title: 'Ok',
                    timer: 800,
                    showConfirmButton: false
                });
                await carregar();
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: e.message
                });
            }
        }

        async function resetSenha(id, nome, perfilAlvo) {
            if (perfilAlvo === 'ADMIN') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Bloqueado',
                    text: 'Reset de senha de ADMIN é restrito.'
                });
                return;
            }

            const {
                value: senha
            } = await Swal.fire({
                title: 'Redefinir senha',
                html: `<div class="text-muted small mb-2">Usuário: <b>${escapeHtml(nome)}</b></div>`,
                input: 'password',
                inputLabel: 'Nova senha (mín. 6 caracteres)',
                inputPlaceholder: 'Digite a nova senha',
                inputAttributes: {
                    autocomplete: 'new-password'
                },
                showCancelButton: true,
                confirmButtonText: 'Salvar',
                cancelButtonText: 'Cancelar',
                preConfirm: (v) => {
                    if (!v || v.length < 6) {
                        Swal.showValidationMessage('A senha deve ter no mínimo 6 caracteres.');
                        return false;
                    }
                    return v;
                }
            });

            if (!senha) return;

            try {
                await api({
                    acao: 'reset_senha',
                    id,
                    senha
                }, 'POST');
                Swal.fire({
                    icon: 'success',
                    title: 'Senha redefinida',
                    timer: 900,
                    showConfirmButton: false
                });
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: e.message
                });
            }
        }

        // helpers XSS
        function escapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, m => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            } [m]));
        }

        function escapeHtmlAttr(s) {
            return escapeHtml(s).replace(/"/g, '&quot;');
        }

        // binds tabela
        document.getElementById('tbUsuarios').addEventListener('click', (e) => {
            const btnEdit = e.target.closest('.btn-edit');
            if (btnEdit) return abrirEdicao(btnEdit.dataset.id);

            const btnInativar = e.target.closest('.btn-inativar');
            if (btnInativar) return inativar(btnInativar.dataset.id, btnInativar.dataset.nome, btnInativar.dataset.perfil);

            const btnReativar = e.target.closest('.btn-reativar');
            if (btnReativar) return reativar(btnReativar.dataset.id, btnReativar.dataset.nome, btnReativar.dataset.perfil);

            const btnReset = e.target.closest('.btn-reset');
            if (btnReset) return resetSenha(btnReset.dataset.id, btnReset.dataset.nome, btnReset.dataset.perfil);
        });


        (function() {
            const elPw = document.getElementById('SENHA');
            const elPw2 = document.getElementById('SENHA2');
            const wrap = document.getElementById('pwMeterWrap');
            const bar = document.getElementById('pwBar');
            const text = document.getElementById('pwText');

            if (!elPw || !elPw2 || !wrap || !bar || !text) return;

            function scorePassword(pw) {
                let s = 0;
                if (!pw) return 0;
                if (pw.length >= 6) s += 1;
                if (pw.length >= 10) s += 1;
                if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s += 1;
                if (/\d/.test(pw)) s += 1;
                if (/[^A-Za-z0-9]/.test(pw)) s += 1;
                return Math.min(s, 5); // 0..5
            }

            function renderStrength() {
                const pw = elPw.value || '';
                const show = pw.length > 0;

                wrap.style.display = show ? '' : 'none';

                const s = scorePassword(pw);
                const pct = (s / 5) * 100;
                bar.style.width = `${pct}%`;

                let label = '—';
                let color = '#64748b';

                if (!show) {
                    bar.style.width = '0%';
                    bar.style.background = color;
                    text.textContent = 'Força: —';
                    text.style.color = color;
                    return;
                }

                if (s <= 1) {
                    label = 'Fraca';
                    color = '#ef4444';
                } else if (s <= 3) {
                    label = 'Média';
                    color = '#f59e0b';
                } else {
                    label = 'Forte';
                    color = '#22c55e';
                }

                bar.style.background = color;
                text.textContent = `Força: ${label}`;
                text.style.color = color;
            }

            // expõe uma função pro salvar() reutilizar (sem ficar duplicando regra)
            window.__drePasswordStrength = function() {
                const pw = (elPw.value || '');
                const s = scorePassword(pw);
                return {
                    pw,
                    score: s
                };
            };

            elPw.addEventListener('input', renderStrength);

            // quando confirmar muda, só pra UX (não precisa recalcular força)
            elPw2.addEventListener('input', () => {});

            // estado inicial
            renderStrength();

            // ao abrir modal/limpar campos, você chama resetModal() — então vamos “escutar”
            // (se resetModal limpar SENHA, o meter some automaticamente no próximo input; aqui garantimos)
            const _oldResetModal = window.resetModal;
            if (typeof _oldResetModal === 'function') {
                window.resetModal = function() {
                    _oldResetModal();
                    // depois de limpar, atualiza
                    setTimeout(renderStrength, 0);
                };
            }
        })();




        // eventos
        document.getElementById('btnFiltrar').addEventListener('click', (e) => {
            e.preventDefault();
            carregar();
        });
        document.getElementById('btnNovo').addEventListener('click', async () => {
            resetModal();
            await carregarEmpresasSelect(document.getElementById('USU_EMPRESA_ID'), '');
        });
        document.getElementById('btnSalvar').addEventListener('click', salvar);

        // ===== A.4: máscaras + ViaCEP nos novos campos do cadastro de usuário =====
        (function initMascarasUsuario() {
            const onlyDigits = v => (v || '').toString().replace(/\D/g, '');
            const maskPhone = v => {
                const d = onlyDigits(v).slice(0, 11);
                if (d.length <= 10) return d.replace(/^(\d{2})(\d)/g, '($1) $2').replace(/(\d{4})(\d)/, '$1-$2');
                return d.replace(/^(\d{2})(\d)/g, '($1) $2').replace(/(\d{5})(\d)/, '$1-$2');
            };
            const maskCEP = v => {
                const d = onlyDigits(v).slice(0, 8);
                return d.length <= 5 ? d : d.slice(0, 5) + '-' + d.slice(5);
            };
            const maskCPF = v => {
                const d = onlyDigits(v).slice(0, 11);
                return d
                    .replace(/^(\d{3})(\d)/, '$1.$2')
                    .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
                    .replace(/\.(\d{3})(\d)/, '.$1-$2');
            };
            const maskCNPJ = v => {
                const d = onlyDigits(v).slice(0, 14);
                return d
                    .replace(/^(\d{2})(\d)/, '$1.$2')
                    .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
                    .replace(/\.(\d{3})(\d)/, '.$1/$2')
                    .replace(/(\d{4})(\d)/, '$1-$2');
            };
            const maskDoc = v => {
                const d = onlyDigits(v);
                return d.length <= 11 ? maskCPF(v) : maskCNPJ(v);
            };

            const bind = (id, fn) => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', e => { e.target.value = fn(e.target.value); });
            };
            bind('USU_CPF_CNPJ', maskDoc);
            bind('USU_TELEFONE', maskPhone);
            bind('USU_CEP', maskCEP);

            const cepEl = document.getElementById('USU_CEP');
            if (cepEl) {
                cepEl.addEventListener('blur', async () => {
                    const cep = onlyDigits(cepEl.value);
                    if (cep.length !== 8) return;
                    try {
                        const resp = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
                        const j = await resp.json();
                        if (j && !j.erro) {
                            const set = (id, val) => { const e = document.getElementById(id); if (e && !e.value) e.value = val || ''; };
                            set('USU_ENDERECO', j.logradouro);
                            set('USU_BAIRRO',   j.bairro);
                            set('USU_CIDADE',   j.localidade);
                            set('USU_UF',       (j.uf || '').toUpperCase());
                        }
                    } catch (_) { /* silencioso */ }
                });
            }

            const ufEl = document.getElementById('USU_UF');
            if (ufEl) ufEl.addEventListener('input', e => { e.target.value = e.target.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2); });
        })();

        // enter no buscar
        document.getElementById('fBusca').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                carregar();
            }
        });

        // carregar filtros de empresa uma vez
        (async function init() {
            try {
                await carregarEmpresasSelect(document.getElementById('fEmpresa'), '');
                // ajusta placeholder do filtro
                document.getElementById('fEmpresa').options[0].textContent = 'Todas';
                await carregar();
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: err.message
                });
            }
        })();


        (function() {
            const ck = document.getElementById('acessoTodasEmpresas');
            const sel = document.getElementById('USU_EMPRESA_ID');
            if (!ck || !sel) return;

            function apply() {
                if (ck.checked) {
                    sel.disabled = true;
                    sel.value = '';
                } else {
                    sel.disabled = false;
                }
            }
            ck.addEventListener('change', apply);
            apply();
        })();
    </script>
  <script src="assets/session_keeper.js" defer></script>
</body>

</html>