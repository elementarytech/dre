<?php
// /app/relatorios/index.php — Hub de relatórios financeiros
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$__rel = '../'; // estamos em /app/relatorios/
$__userNome = (string)($_SESSION['user_nome'] ?? 'Usuário');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <title>Relatórios Financeiros</title>
  <?php include __DIR__ . '/../includes/head.php'; ?>
  <style>
    body { background:#f4f6f9; font-family:'Inter',system-ui,sans-serif; }
    .page-title { font-size:1.35rem; font-weight:700; color:#0f172a; margin:0; }
    .rep-card {
      display:block; text-decoration:none; color:inherit;
      background:#fff; border:1px solid #e5e7eb; border-radius:.875rem;
      padding:1.25rem; box-shadow:0 1px 3px rgba(15,23,42,.06);
      transition:transform .15s, box-shadow .15s, border-color .15s;
      height:100%;
    }
    .rep-card:hover {
      transform:translateY(-2px);
      box-shadow:0 8px 24px rgba(15,23,42,.10);
      border-color:#2563eb;
      color:inherit;
    }
    .rep-icon {
      width:48px; height:48px; border-radius:12px;
      display:flex; align-items:center; justify-content:center;
      font-size:1.4rem; margin-bottom:.85rem;
    }
    .rep-icon.blue   { background:#eff6ff; color:#2563eb; }
    .rep-icon.green  { background:#f0fdf4; color:#16a34a; }
    .rep-icon.orange { background:#fff7ed; color:#ea580c; }
    .rep-icon.purple { background:#faf5ff; color:#9333ea; }
    .rep-title { font-weight:700; font-size:1rem; color:#0f172a; margin-bottom:.25rem; }
    .rep-desc  { font-size:.85rem; color:#64748b; line-height:1.4; }
    .rep-tag {
      display:inline-block; font-size:.65rem; font-weight:700;
      letter-spacing:.05em; text-transform:uppercase;
      padding:.15rem .5rem; border-radius:99px;
      background:#eff6ff; color:#2563eb;
      margin-top:.6rem;
    }
    .rep-tag.soon { background:#f3f4f6; color:#6b7280; }
  </style>
</head>
<body>
  <div class="d-flex">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-grow-1" style="min-width:0;">
      <nav class="navbar bg-white border-bottom px-3 py-2 sticky-top">
        <span class="navbar-brand mb-0 h6">Relatórios Financeiros</span>
        <div class="d-flex align-items-center gap-2">
          <span class="small text-muted">
            <?= htmlspecialchars($__userNome) ?>
            (<?= htmlspecialchars($_SESSION['user_perfil'] ?? 'USER') ?>)
          </span>
          <a class="btn btn-sm btn-outline-danger" href="<?= $__rel ?>logout.php">
            <i class="fa-solid fa-right-from-bracket me-1"></i>Sair
          </a>
        </div>
      </nav>

      <div class="container-fluid py-4">
        <div class="d-flex align-items-center mb-4">
          <div>
            <h1 class="page-title">Relatórios Financeiros</h1>
            <div class="small text-muted" style="font-size:.8rem;">Selecione um relatório abaixo para começar.</div>
          </div>
        </div>

        <div class="row g-3">

          <div class="col-md-4">
            <a href="lancamentos_pagos.php" class="rep-card">
              <div class="rep-icon blue"><i class="fa-solid fa-money-bill-transfer"></i></div>
              <div class="rep-title">Lançamentos Pagos</div>
              <div class="rep-desc">Lista de contas a pagar quitadas, com filtros por banco, período, fornecedor, plano de contas e valor. Exporta CSV/XLSX.</div>
              <span class="rep-tag">Disponível</span>
            </a>
          </div>

          <div class="col-md-4">
            <a href="recebimentos.php" class="rep-card">
              <div class="rep-icon green"><i class="fa-solid fa-money-bill-trend-up"></i></div>
              <div class="rep-title">Recebimentos</div>
              <div class="rep-desc">Lista de contas a receber recebidas, com filtros por banco, período, cliente, origem (contrato/avulso/empréstimo/aporte) e contrato.</div>
              <span class="rep-tag">Disponível</span>
            </a>
          </div>

          <div class="col-md-4">
            <div class="rep-card" style="cursor:not-allowed; opacity:.6;">
              <div class="rep-icon orange"><i class="fa-solid fa-chart-line"></i></div>
              <div class="rep-title">DRE Detalhado</div>
              <div class="rep-desc">Receita bruta, despesas por categoria, resultado operacional e margem por período.</div>
              <span class="rep-tag soon">Em breve</span>
            </div>
          </div>

          <div class="col-md-4">
            <a href="pendencias_conciliacao.php" class="rep-card">
              <div class="rep-icon purple"><i class="fa-solid fa-scale-balanced"></i></div>
              <div class="rep-title">Pendências de Conciliação</div>
              <div class="rep-desc">Movimentos do banco (OFX) que ainda precisam ser conciliados, por período e banco. Não inclui internos (transferência/aplicação/tarifa). Exporta CSV.</div>
              <span class="rep-tag">Disponível</span>
            </a>
          </div>

        </div>
      </div>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?= $__rel ?>assets/session_keeper.js" defer></script>
</body>
</html>
