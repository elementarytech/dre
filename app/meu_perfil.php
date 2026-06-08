<?php
// /app/meu_perfil.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Meu Perfil</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .card-soft {
            border: 1px solid rgba(17, 24, 39, .08);
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, .06);
            background: #fff;
        }

        .help {
            font-size: .86rem;
            color: #64748b;
        }

        .pw-meter {
            user-select: none;
        }

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

<body data-page="perfil">
    <div class="d-flex" id="wrapper">

        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">
            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Meu Perfil</span>

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
                <div class="card-soft p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Meu Perfil</h6>
                        <span class="badge bg-light text-dark border" id="lblPerfil">—</span>
                    </div>
                    <div class="help mb-3">
                        Atualize seus dados. O e-mail é usado como login.
                        Para alterar a senha, preencha os 3 campos abaixo.
                    </div>

                    <form id="frmPerfil" autocomplete="off">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label small">Nome</label>
                                <input type="text" class="form-control form-control-sm" id="USU_NOME" required>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label small">E-mail</label>
                                <input type="email" class="form-control form-control-sm" id="USU_EMAIL" required>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label small">Status</label>
                                <input type="text" class="form-control form-control-sm" id="USU_STATUS" disabled>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label small">Último login</label>
                                <input type="text" class="form-control form-control-sm" id="USU_ULTIMO_LOGIN" disabled>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label small">ID</label>
                                <input type="text" class="form-control form-control-sm" id="USU_ID" disabled>
                            </div>

                            <div class="col-12">
                                <hr class="my-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-semibold">Segurança</div>
                                        <div class="help">Deixe em branco se não quiser alterar a senha.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-lg-4">
                                <label class="form-label small">Senha atual</label>
                                <input type="password" class="form-control form-control-sm" id="SENHA_ATUAL" autocomplete="current-password">
                            </div>

                            <div class="col-12 col-lg-4">
                                <label class="form-label small">Nova senha</label>
                                <input type="password" class="form-control form-control-sm" id="SENHA_NOVA" autocomplete="new-password">

                                <!-- Medidor fica DENTRO da coluna da nova senha -->
                                <div class="pw-meter mt-2" id="pwMeter" style="display:none;">
                                    <div class="pw-bar">
                                        <span id="pwBar"></span>
                                    </div>
                                    <div class="pw-text" id="pwText">Força: —</div>
                                </div>
                            </div>

                            <div class="col-12 col-lg-4" id="colConfirmarSenha" style="display:none;">
                                <label class="form-label small">Confirmar nova senha</label>
                                <input type="password" class="form-control form-control-sm" id="SENHA_NOVA2" autocomplete="new-password">
                            </div>

                            <div class="col-12 d-flex justify-content-end gap-2 mt-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRecarregar">
                                    <i class="fa-solid fa-rotate me-1"></i>Recarregar
                                </button>
                                <button type="button" class="btn btn-sm btn-primary" id="btnSalvarTudo">
                                    <i class="fa-solid fa-floppy-disk me-1"></i>Salvar alterações
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        </div><!-- page-content -->
    </div><!-- wrapper -->

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        const ENDPOINT = 'endpoints/meu_perfil.php';

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
                console.error('Resposta NÃO JSON:', txt);
                throw new Error('Endpoint não retornou JSON. Veja o console (F12).');
            }

            if (!j.ok) throw new Error(j.msg || 'Falha na requisição.');
            return j;
        }

        function fmtDateTimeBR(isoOrNull) {
            if (!isoOrNull) return '-';
            const d = new Date(String(isoOrNull).replace(' ', 'T'));
            if (isNaN(d.getTime())) return String(isoOrNull);
            const pad = n => String(n).padStart(2, '0');
            return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
        }

        async function carregarPerfil() {
            const j = await api({
                acao: 'get'
            }, 'GET');
            const u = j.user;

            document.getElementById('USU_ID').value = u.USU_ID;
            document.getElementById('USU_NOME').value = u.USU_NOME;
            document.getElementById('USU_EMAIL').value = u.USU_EMAIL;
            document.getElementById('USU_STATUS').value = u.USU_STATUS;
            document.getElementById('USU_ULTIMO_LOGIN').value = fmtDateTimeBR(u.USU_ULTIMO_LOGIN);
            document.getElementById('lblPerfil').textContent = u.USU_PERFIL;
        }

        function resetPwUI() {
            const col = document.getElementById('colConfirmarSenha');
            const meter = document.getElementById('pwMeter');
            const bar = document.getElementById('pwBar');
            const txt = document.getElementById('pwText');

            if (col) col.style.display = 'none';
            if (meter) meter.style.display = 'none';
            if (bar) {
                bar.style.width = '0%';
                bar.style.background = '#64748b';
            }
            if (txt) {
                txt.textContent = 'Força: —';
                txt.style.color = '#64748b';
            }
        }

        async function salvarTudo() {
            const nome = document.getElementById('USU_NOME').value.trim();
            const email = document.getElementById('USU_EMAIL').value.trim();

            const atual = document.getElementById('SENHA_ATUAL').value;
            const nova = document.getElementById('SENHA_NOVA').value;
            const nova2 = document.getElementById('SENHA_NOVA2').value;

            if (!nome || !email) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Informe Nome e E-mail.'
                });
                return;
            }

            // regra: senha só mexe se algum deles estiver preenchido
            const querTrocarSenha = (atual || nova || nova2);

            if (querTrocarSenha) {
                if (!atual || !nova || !nova2) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Senha',
                        text: 'Para trocar a senha, preencha os 3 campos.'
                    });
                    return;
                }
                if (nova.length < 6) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Senha fraca',
                        text: 'A nova senha deve ter no mínimo 6 caracteres.'
                    });
                    return;
                }
                if (nova !== nova2) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Confirmação',
                        text: 'A confirmação da nova senha não confere.'
                    });
                    return;
                }
            }

            try {
                await api({
                    acao: 'salvar_tudo',
                    USU_NOME: nome,
                    USU_EMAIL: email,
                    senha_atual: atual,
                    senha_nova: nova
                }, 'POST');

                // limpa campos de senha após salvar
                document.getElementById('SENHA_ATUAL').value = '';
                document.getElementById('SENHA_NOVA').value = '';
                document.getElementById('SENHA_NOVA2').value = '';
                resetPwUI();

                Swal.fire({
                    icon: 'success',
                    title: 'Salvo',
                    text: 'Alterações salvas com sucesso!',
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

        // ====== Força da senha + mostrar confirmar ======
        (function() {
            const elNova = document.getElementById('SENHA_NOVA');
            const elConfCol = document.getElementById('colConfirmarSenha');
            const elMeter = document.getElementById('pwMeter');
            const elBar = document.getElementById('pwBar');
            const elText = document.getElementById('pwText');

            if (!elNova || !elConfCol || !elMeter || !elBar || !elText) return;

            function scorePassword(pw) {
                let s = 0;
                if (!pw) return 0;
                if (pw.length >= 6) s += 1;
                if (pw.length >= 10) s += 1;
                if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s += 1;
                if (/\d/.test(pw)) s += 1;
                if (/[^A-Za-z0-9]/.test(pw)) s += 1;
                return Math.min(s, 5);
            }

            function renderStrength(pw) {
                const show = (pw && pw.length > 0);
                elConfCol.style.display = show ? '' : 'none';
                elMeter.style.display = show ? '' : 'none';

                const s = scorePassword(pw || '');
                const pct = (s / 5) * 100;
                elBar.style.width = `${pct}%`;

                let label = '—';
                let color = '#64748b';
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

                elBar.style.background = color;
                elText.textContent = `Força: ${label}`;
                elText.style.color = color;
            }

            elNova.addEventListener('input', () => renderStrength(elNova.value));
            renderStrength(elNova.value);
        })();

        // binds (SEM duplicar!)
        document.getElementById('btnRecarregar').addEventListener('click', () =>
            carregarPerfil().catch(e => Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: e.message
            }))
        );
        document.getElementById('btnSalvarTudo').addEventListener('click', salvarTudo);

        carregarPerfil().catch(e => Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: e.message
        }));
    </script>

  <script src="assets/session_keeper.js" defer></script>
</body>

</html>