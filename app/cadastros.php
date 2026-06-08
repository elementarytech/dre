<?php
// /app/cadastros.php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php'; // exige login
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <title>DRE - Cadastros do Sistema</title>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        /* Mantém o visual “cards de configuração” sem depender de outro arquivo */
        .settings-card {
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, .06);
            border: 1px solid rgba(17, 24, 39, .08) !important;
            transition: transform .15s ease, box-shadow .15s ease;
            overflow: hidden;
        }

        .settings-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 34px rgba(15, 23, 42, .10);
        }

        .settings-card i {
            color: #6d28d9;
            /* roxinho do tema */
        }
    </style>
</head>

<!-- marca Configurações como ativo no sidebar -->

<body data-page="config">
    <div class="d-flex" id="wrapper">

        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div id="page-content-wrapper" class="flex-grow-1">
            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
                <button class="btn btn-outline-secondary me-2" id="menu-toggle">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <span class="navbar-brand mb-0 h6 d-none d-sm-inline">Cadastros do Sistema</span>

                <div class="collapse navbar-collapse justify-content-end">
                    <ul class="navbar-nav mb-2 mb-lg-0 align-items-center">
                        <li class="nav-item me-3">
                            <span class="text-muted small">Hoje: <span id="dataAtualTopo">—</span></span>
                        </li>

                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#"
                                id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fa-solid fa-circle-user fa-lg me-1"></i>
                                <span class="small"><?= htmlspecialchars($_SESSION['user_nome'] ?? 'Usuário') ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li><a class="dropdown-item" href="meu_perfil.php">Meu Perfil</a></li>
                                <li><a class="dropdown-item" href="#" onclick="return false;">Preferências</a></li>
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
                        <h5 class="mb-1">Cadastros</h5>
                        <p class="text-muted small mb-0">
                            Selecione um cadastro para gerenciar os dados básicos utilizados no financeiro.
                        </p>
                    </div>
                </div>

                <div class="row g-3">

                    <!-- Clientes -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa-users fa-2x mb-2"></i>
                                    <h6 class="mb-1">Clientes</h6>
                                    <p class="text-muted small mb-0">
                                        Cadastro de clientes para faturamento, contratos e cobranças.
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="clientes.php" class="btn btn-sm btn-primary w-100">Abrir</a>
                                    <!-- quando existir a tela:
                  <a href="clientes.php" class="btn btn-sm btn-primary w-100">Abrir</a>
                  -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Usuários -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa-user-tie fa-2x mb-2"></i>
                                    <h6 class="mb-1">Usuários</h6>
                                    <p class="text-muted small mb-0">
                                        Colaboradores para vínculos, acessos e lançamentos.
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="usuarios.php" class="btn btn-sm btn-primary w-100">Abrir</a>
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
                                        Empresas/unidades para controlar múltiplos CNPJs.
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="empresas.php" class="btn btn-sm btn-primary w-100">Abrir</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bancos -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa-building-columns fa-2x mb-2"></i>
                                    <h6 class="mb-1">Bancos</h6>
                                    <p class="text-muted small mb-0">
                                        Contas bancárias para conciliação, recebimentos e pagamentos.
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="bancos.php" class="btn btn-sm btn-primary w-100">Abrir</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Centros de Custo -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa-diagram-project fa-2x mb-2"></i>
                                    <h6 class="mb-1">Centros de Custo</h6>
                                    <p class="text-muted small mb-0">
                                        Estrutura de centros de custo para análise gerencial.
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="centros_custo.php" class="btn btn-sm btn-primary w-100">Abrir</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Plano de Contas -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa-table-list fa-2x mb-2"></i>
                                    <h6 class="mb-1">Plano de Contas</h6>
                                    <p class="text-muted small mb-0">
                                        Contas contábeis e gerenciais para o seu financeiro.
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="plano_contas.php" class="btn btn-sm btn-primary w-100">Abrir</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Fornecedores -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa-truck fa-2x mb-2"></i>
                                    <h6 class="mb-1">Fornecedores</h6>
                                    <p class="text-muted small mb-0">
                                        Cadastro de Fornecedores
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="fornecedores.php" class="btn btn-sm btn-primary w-100">Abrir</a>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- Formas de Pagamento -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa fa-credit-card fa-2x mb-2"></i>
                                    <h6 class="mb-1">Formas de Pagamento</h6>
                                    <p class="text-muted small mb-0">
                                        Cadastro de Formas de Pagamento
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="formas_pagamento.php" class="btn btn-sm btn-primary w-100">Abrir</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Fluxo Caixa -->
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <div class="card shadow-sm border-0 h-100 settings-card">
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <i class="fa-solid fa-cash-register fa-2x mb-2"></i>
                                    <h6 class="mb-1">Fluxo de Caixa</h6>
                                    <p class="text-muted small mb-0">
                                        Acompanhe os lançamentos
                                    </p>
                                </div>
                                <div class="mt-auto">
                                    <a href="fluxo_caixa.php" class="btn btn-sm btn-primary w-100">Abrir</a>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <footer class="text-muted small mt-4">
                    © <span id="anoAtualFooter"><?= date('Y') ?></span> DRE - Sistema Financeiro
                </footer>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/scripts.php'; ?>

    <script>
        // Data topo (pt-BR)
        (function() {
            const el = document.getElementById('dataAtualTopo');
            if (!el) return;
            const d = new Date();
            const pad = n => String(n).padStart(2, '0');
            el.textContent = `${pad(d.getDate())}/${pad(d.getMonth()+1)}/${d.getFullYear()}`;
        })();
    </script>
  <script src="assets/session_keeper.js" defer></script>
</body>

</html>