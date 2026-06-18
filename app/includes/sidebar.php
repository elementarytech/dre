<?php
// /app/includes/sidebar.php
$__scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$__sideBase   = rtrim(str_replace('\\', '/', dirname($__scriptPath)), '/');
$__isAdmin    = strtoupper((string)($_SESSION['user_perfil'] ?? '')) === 'ADMIN';
$__curFile    = basename($__scriptPath);
$__userNome   = (string)($_SESSION['user_nome'] ?? 'Usuário');

// $__rel = caminho relativo até a pasta /app/. Páginas em subpastas (ex: relatorios/)
// devem definir $__rel = '../'; antes de incluir esta sidebar.
$__rel = isset($__rel) ? $__rel : '';

// Detecta se estamos numa subpasta para o highlight do menu pai (ex: "Relatórios").
$__inRelatorios = (strpos($__scriptPath, '/relatorios/') !== false);

if (!function_exists('__sideLink')) {
    function __sideLink(string $href, string $icon, string $label, string $curFile, string $extra = '', bool $forceActive = false): string
    {
        $active = ($forceActive || basename($href) === $curFile) ? ' active' : '';
        return '<li class="mb-1">'
            . '<a href="' . htmlspecialchars($href) . '" class="sidebar-link' . $active . '">'
            . '<i class="' . $icon . ' me-2"></i><span class="sidebar-label">' . htmlspecialchars($label) . '</span>'
            . $extra
            . '</a></li>';
    }
}
?>
<nav id="sidebar" class="sidebar">
    <div class="sidebar-brand">
        <img src="<?= $__rel ?>assets/img/LOGOSVG.svg" alt="Assesccont" class="sidebar-logo">
        <div class="sidebar-subtitle">SYNC ERP - Gestão Financeira</div>
    </div>

    <div class="sidebar-scroll">
        <ul class="list-unstyled px-2 mt-2 mb-0">

            <?= __sideLink($__rel.'index.php',        'fa-solid fa-gauge-high',        'Dashboard',          $__curFile) ?>

            <?php if ($__isAdmin): ?>
                <?= __sideLink($__rel.'bi.php',       'fa-solid fa-chart-pie',         'BI Financeiro',      $__curFile) ?>
            <?php endif; ?>

            <li class="sidebar-section">Financeiro</li>

            <?= __sideLink($__rel.'contas_pagar.php',        'fa-solid fa-arrow-up-wide-short',   'Contas a Pagar',       $__curFile) ?>
            <?= __sideLink($__rel.'contas_receber.php',      'fa-solid fa-arrow-down-wide-short', 'Contas a Receber',     $__curFile) ?>
            <?= __sideLink($__rel.'contratos.php',           'fa-solid fa-file-contract',         'Contratos',            $__curFile) ?>
            <?= __sideLink($__rel.'fluxo_caixa.php',         'fa-solid fa-chart-line',            'Fluxo de Caixa',       $__curFile) ?>
            <?= __sideLink($__rel.'transferencia_bancaria.php','fa-solid fa-right-left',           'Transferência Bancária', $__curFile) ?>
            <?= __sideLink($__rel.'conciliacao_bancaria.php','fa-solid fa-scale-balanced',        'Conciliação Bancária', $__curFile) ?>
            <?= __sideLink($__rel.'diagnostico_contas_pagar.php','fa-solid fa-shield-halved',     'Painel de Integridade', $__curFile) ?>

            <?php if ($__isAdmin): ?>
                <?= __sideLink($__rel.'liberacao_pagamento.php', 'fa-solid fa-lock', 'Liberação Pagamento', $__curFile, ' <span class="badge bg-danger-subtle text-danger ms-auto" style="font-size:.65rem">Admin</span>') ?>
            <?php endif; ?>

            <li class="sidebar-section">Relatórios</li>

            <?= __sideLink($__rel.'relatorios/index.php', 'fa-solid fa-file-invoice-dollar', 'Relatórios Financeiros', $__curFile, '', $__inRelatorios) ?>

            <li class="sidebar-section">Cadastros</li>

            <?= __sideLink($__rel.'cadastros.php',        'fa-solid fa-layer-group',      'Cadastros',           $__curFile) ?>
            <?= __sideLink($__rel.'clientes.php',         'fa-solid fa-user-group',       'Clientes',            $__curFile) ?>
            <?= __sideLink($__rel.'empresas.php',         'fa-solid fa-building',         'Empresas',            $__curFile) ?>
            <?= __sideLink($__rel.'bancos.php',           'fa-solid fa-building-columns', 'Bancos',              $__curFile) ?>
            <?= __sideLink($__rel.'fornecedores.php',     'fa-solid fa-truck',            'Fornecedores',        $__curFile) ?>
            <?= __sideLink($__rel.'formas_pagamento.php', 'fa-solid fa-credit-card',      'Formas de Pagamento', $__curFile) ?>

            <li class="sidebar-section">Conta</li>

            <?= __sideLink($__rel.'usuarios.php',    'fa-solid fa-users',     'Usuários',      $__curFile) ?>
            <?php if ($__isAdmin): ?>
                <?= __sideLink($__rel.'auditoria.php', 'fa-solid fa-clipboard-check', 'Auditoria', $__curFile, ' <span class="badge bg-danger-subtle text-danger ms-auto" style="font-size:.65rem">Admin</span>') ?>
            <?php endif; ?>
            <?= __sideLink($__rel.'meu_perfil.php',  'fa-solid fa-id-card',   'Meu Perfil',    $__curFile) ?>
            <?= __sideLink($__rel.'atualizacoes.php','fa-solid fa-rotate',    'Atualizações',  $__curFile) ?>
            <?= __sideLink($__rel.'configuracoes.php','fa-solid fa-gear',     'Configurações', $__curFile) ?>

        </ul>
    </div>

    <div class="sidebar-user">
        <div class="sidebar-user-avatar"><?= strtoupper(substr($__userNome, 0, 1)) ?></div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name" title="<?= htmlspecialchars($__userNome) ?>"><?= htmlspecialchars($__userNome) ?></div>
            <a href="logout.php" class="sidebar-user-logout">
                <i class="fa-solid fa-right-from-bracket me-1"></i>Sair
            </a>
        </div>
    </div>
</nav>
