<?php
// /app/configuracoes.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php'; // exige login
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Configurações</title>
    <?php include __DIR__ . '/includes/head.php'; ?>

    <style>
        /* cards iguais ao seu HTML */
        .settings-card .fa-2x {
            opacity: .9;
        }

        .settings-card:hover {
            transform: translateY(-1px);
            transition: transform .15s ease, box-shadow .15s ease;
            box-shadow: 0 10px 22px rgba(17, 24, 39, .08);
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
                    Configurações do Sistema
                </span>

                <div class="collapse navbar-collapse justify-content-end">
                    <ul class="navbar-nav mb-2 mb-lg-0 align-items-center">
                        <li class="nav-item me-3">
                            <span class="text-muted small">Hoje: <span id="dataAtualTopo"></span></span>
                        </li>

                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown"
                                role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fa-solid fa-circle-user fa-lg me-1"></i>
                                <span class="small"><?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li><a class="dropdown-item" href="#">Meu Perfil</a></li>
                                <li><a class="dropdown-item" href="#">Preferências</a></li>
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

                <div class="row mb-3">
                    <div class="col">
                        <h5 class="mb-1">Configurações Gerais</h5>
                        <p class="text-muted small mb-0">
                            Selecione uma área para configurar o sistema.
                        </p>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Cadastros -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa-folder-open fa-2x mb-2"></i>
                                    <h6 class="mb-1">Cadastros</h6>
                                    <p class="text-muted small mb-0">
                                        Clientes, bancos, centros de custo, plano de contas e demais cadastros base.
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="cadastros.php" class="btn btn-sm btn-primary w-100">Abrir cadastros</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Usuários & Acessos -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa-user-shield fa-2x mb-2"></i>
                                    <h6 class="mb-1">Usuários & Acessos</h6>
                                    <p class="text-muted small mb-0">
                                        Perfis de acesso, permissões e controle de login dos usuários.
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="usuarios.php" class="btn btn-sm btn-outline-primary w-100">Abrir usuários</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Parâmetros -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa-sliders fa-2x mb-2"></i>
                                    <h6 class="mb-1">Parâmetros do Sistema</h6>
                                    <p class="text-muted small mb-0">
                                        Moedas, formatos, numeração de documentos e integrações contábeis.
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="parametros.php" class="btn btn-sm btn-outline-primary w-100">Abrir parâmetros</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Empresas -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa-building fa-2x mb-2"></i>
                                    <h6 class="mb-1">Empresas</h6>
                                    <p class="text-muted small mb-0">
                                        Cadastre CNPJs, dados fiscais, parâmetros financeiros e endereço.
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="empresas.php" class="btn btn-sm btn-outline-primary w-100">Abrir empresas</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <footer class="text-muted small mt-4">
                    © <span id="anoAtualFooter"></span> DRE - Sistema Financeiro
                </footer>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        (function() {
            const pad = n => String(n).padStart(2, '0');
            const now = new Date();
            const dataBR = `${pad(now.getDate())}/${pad(now.getMonth()+1)}/${now.getFullYear()}`;

            const elTopo = document.getElementById('dataAtualTopo');
            if (elTopo) elTopo.textContent = dataBR;

            const elAno = document.getElementById('anoAtualFooter');
            if (elAno) elAno.textContent = String(now.getFullYear());
        })();
    </script>
  <script src="assets/session_keeper.js" defer></script>
</body>

</html>