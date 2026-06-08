<?php
// /app/parametros.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Parâmetros do Sistema</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .param-group-title {
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: .4rem;
            margin-bottom: 1rem;
        }

        .input-password-wrap {
            position: relative;
        }

        .input-password-wrap .toggle-vis {
            position: absolute;
            right: .75rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            background: none;
            border: none;
            padding: 0;
            line-height: 1;
        }

        .input-password-wrap .toggle-vis:hover {
            color: #374151;
        }

        .param-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            background: #fff;
        }

        label.form-label {
            font-size: .84rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: .25rem;
        }

        .help-text {
            font-size: .78rem;
            color: #9ca3af;
            margin-top: .2rem;
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
                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">
                    Parâmetros do Sistema
                </span>
                <div class="collapse navbar-collapse justify-content-end">
                    <ul class="navbar-nav mb-2 mb-lg-0 align-items-center">
                        <li class="nav-item me-3">
                            <span class="text-muted small">Hoje: <span id="dataAtualTopo"></span></span>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#"
                                id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fa-solid fa-circle-user fa-lg me-1"></i>
                                <span class="small"><?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li><a class="dropdown-item" href="meu_perfil.php">Meu Perfil</a></li>
                                <li><hr class="dropdown-divider" /></li>
                                <li><a class="dropdown-item" href="logout.php">Sair</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="container-fluid py-4">

                <div class="row mb-3 align-items-center">
                    <div class="col">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-1 small">
                                <li class="breadcrumb-item">
                                    <a href="configuracoes.php" class="text-decoration-none text-muted">Configurações</a>
                                </li>
                                <li class="breadcrumb-item active">Parâmetros do Sistema</li>
                            </ol>
                        </nav>
                        <h5 class="mb-0">Parâmetros do Sistema</h5>
                        <p class="text-muted small mb-0">Configure integrações e comportamentos do sistema.</p>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary" id="btnSalvarTudo">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Salvar Alterações
                        </button>
                    </div>
                </div>

                <!-- Skeleton enquanto carrega -->
                <div id="loadingParams" class="text-center py-5 text-muted">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                    Carregando parâmetros...
                </div>

                <!-- Grupos de parâmetros renderizados via JS -->
                <div id="containerParams" class="row g-4" style="display:none!important"></div>

            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
    (function () {
        // ── helpers ──────────────────────────────────────────────────────────
        const pad = n => String(n).padStart(2, '0');
        const now = new Date();
        const dataBR = `${pad(now.getDate())}/${pad(now.getMonth()+1)}/${now.getFullYear()}`;
        const elTopo = document.getElementById('dataAtualTopo');
        if (elTopo) elTopo.textContent = dataBR;

        const labels = {
            banco_brasil: { titulo: 'Banco do Brasil – API Cobrança', icone: 'fa-solid fa-landmark' },
            geral:        { titulo: 'Geral',                          icone: 'fa-solid fa-sliders'  },
        };

        const descrMap = {
            bb_ambiente:          'Define se as chamadas serão feitas para o ambiente de testes (Homologação) ou produção.',
            bb_app_key:           'App Key do portal BB — usada no parâmetro gw-dev-app-key das chamadas à API.',
            bb_client_id:         'Client ID OAuth — usado na autenticação Basic para obter o access token.',
            bb_client_secret:     'Client Secret OAuth — usado na autenticação Basic para obter o access token.',
            bb_numero_convenio:   'Número do convênio de cobrança registrada (7 dígitos) cadastrado no BB.',
            bb_carteira:          'Número da carteira de cobrança — consulte seu contrato BB (geralmente 17).',
            bb_variacao_carteira: 'Variação da carteira — consulte no BB Digital PJ em Cobranças → Convênios.',
        };

        // Opções para campos do tipo select
        const selectOpts = {
            bb_ambiente: [
                { value: 'homologacao', label: 'Homologação (testes)',  badge: 'warning' },
                { value: 'producao',    label: 'Produção',              badge: 'success' },
            ],
        };

        // ── renderizar grupos ─────────────────────────────────────────────────
        function buildForm(rows) {
            const grupos = {};
            rows.forEach(r => {
                if (!grupos[r.CFG_GRUPO]) grupos[r.CFG_GRUPO] = [];
                grupos[r.CFG_GRUPO].push(r);
            });

            const container = document.getElementById('containerParams');
            container.innerHTML = '';

            for (const [grupo, params] of Object.entries(grupos)) {
                const meta  = labels[grupo] || { titulo: grupo, icone: 'fa-solid fa-gear' };
                const col   = document.createElement('div');
                col.className = 'col-12 col-xl-8';

                const fields = params.map(p => buildField(p)).join('');

                col.innerHTML = `
                    <div class="param-card">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="${meta.icone} text-primary"></i>
                            <span class="param-group-title mb-0 border-0 pb-0">${meta.titulo}</span>
                        </div>
                        <hr class="mt-0 mb-3">
                        ${fields}
                    </div>`;

                container.appendChild(col);
            }

            // atualiza badge do select de ambiente ao mudar seleção
            container.querySelectorAll('select.cfg-input').forEach(sel => {
                sel.addEventListener('change', () => {
                    const opts = selectOpts[sel.dataset.chave];
                    if (!opts) return;
                    const current = opts.find(o => o.value === sel.value);
                    const label   = sel.closest('.mb-3').querySelector('.form-label');
                    const badge   = label.querySelector('.badge');
                    if (badge) badge.remove();
                    if (current) {
                        label.insertAdjacentHTML('beforeend',
                            `<span class="badge bg-${current.badge}-subtle text-${current.badge}-emphasis ms-2 fw-normal">${current.label}</span>`);
                    }
                });
            });

            // toggle visibilidade de senhas
            container.querySelectorAll('.toggle-vis').forEach(btn => {
                btn.addEventListener('click', () => {
                    const inp = btn.closest('.input-password-wrap').querySelector('input');
                    if (inp.type === 'password') {
                        inp.type = 'text';
                        btn.innerHTML = '<i class="fa-solid fa-eye-slash"></i>';
                    } else {
                        inp.type = 'password';
                        btn.innerHTML = '<i class="fa-solid fa-eye"></i>';
                    }
                });
            });
        }

        function buildField(p) {
            const descr = descrMap[p.CFG_CHAVE] || (p.CFG_DESCRICAO || '');
            const val   = p.CFG_VALOR || '';

            if (p.CFG_TIPO === 'password') {
                return `
                <div class="mb-3">
                    <label class="form-label" for="cfg_${p.CFG_CHAVE}">${p.CFG_DESCRICAO || p.CFG_CHAVE}</label>
                    <div class="input-password-wrap">
                        <input type="password" class="form-control cfg-input"
                            id="cfg_${p.CFG_CHAVE}" data-chave="${p.CFG_CHAVE}"
                            value="${escHtml(val)}" autocomplete="off">
                        <button type="button" class="toggle-vis" tabindex="-1">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    ${descr ? `<div class="help-text">${descr}</div>` : ''}
                </div>`;
            }

            if (p.CFG_TIPO === 'select' && selectOpts[p.CFG_CHAVE]) {
                const opts = selectOpts[p.CFG_CHAVE].map(o => {
                    const sel = o.value === val ? ' selected' : '';
                    return `<option value="${o.value}"${sel}>${o.label}</option>`;
                }).join('');

                const current = selectOpts[p.CFG_CHAVE].find(o => o.value === val);
                const badge = current
                    ? `<span class="badge bg-${current.badge}-subtle text-${current.badge}-emphasis ms-2 fw-normal">${current.label}</span>`
                    : '';

                return `
                <div class="mb-3">
                    <label class="form-label" for="cfg_${p.CFG_CHAVE}">
                        ${p.CFG_DESCRICAO || p.CFG_CHAVE}${badge}
                    </label>
                    <select class="form-select cfg-input" id="cfg_${p.CFG_CHAVE}" data-chave="${p.CFG_CHAVE}">
                        ${opts}
                    </select>
                    ${descr ? `<div class="help-text">${descr}</div>` : ''}
                </div>`;
            }

            const inputType = p.CFG_TIPO === 'number' ? 'number' : 'text';
            return `
            <div class="mb-3">
                <label class="form-label" for="cfg_${p.CFG_CHAVE}">${p.CFG_DESCRICAO || p.CFG_CHAVE}</label>
                <input type="${inputType}" class="form-control cfg-input"
                    id="cfg_${p.CFG_CHAVE}" data-chave="${p.CFG_CHAVE}"
                    value="${escHtml(val)}">
                ${descr ? `<div class="help-text">${descr}</div>` : ''}
            </div>`;
        }

        function escHtml(s) {
            return String(s)
                .replace(/&/g,'&amp;').replace(/"/g,'&quot;')
                .replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }

        // ── carregar ──────────────────────────────────────────────────────────
        async function carregar() {
            const res  = await fetch('endpoints/parametros.php?action=listar');
            const json = await res.json();
            document.getElementById('loadingParams').style.display = 'none';
            const container = document.getElementById('containerParams');
            container.style.removeProperty('display');
            if (json.ok) buildForm(json.data);
        }

        // ── salvar ────────────────────────────────────────────────────────────
        document.getElementById('btnSalvarTudo').addEventListener('click', async () => {
            const inputs = document.querySelectorAll('.cfg-input');
            const parametros = {};
            inputs.forEach(inp => { parametros[inp.dataset.chave] = inp.value; });

            const btn = document.getElementById('btnSalvarTudo');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...';

            try {
                const res  = await fetch('endpoints/parametros.php?action=salvar', {
                    method : 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body   : JSON.stringify({ parametros }),
                });
                const json = await res.json();

                if (json.ok) {
                    Swal.fire({ icon: 'success', title: 'Salvo!', text: json.msg, timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Erro', text: json.msg });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Erro', text: 'Falha na comunicação com o servidor.' });
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Salvar Alterações';
            }
        });

        carregar();
    })();
    </script>
    <script src="assets/session_keeper.js" defer></script>
</body>
</html>
