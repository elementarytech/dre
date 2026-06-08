<?php
// /app/includes/head.php
declare(strict_types=1);

// $__rel = caminho relativo até a pasta /app/. Páginas em subpastas
// (ex: relatorios/) devem definir $__rel = '../'; antes do include.
$__rel = isset($__rel) ? $__rel : '';

// Mantido para compatibilidade — alguns usos antigos dependem dele.
$__base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($__base === '') $__base = '';
?>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />

<!-- Poppins -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

<!-- SweetAlert2 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<!-- CSS do projeto -->
<link rel="stylesheet" href="<?= $__rel ?>assets/css/styles.css" />

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    html,
    body {
        font-family: "Poppins", sans-serif !important;
    }

    /* Loading overlay global */
    #loadingOverlay {
        position: fixed;
        inset: 0;
        background: rgba(255,255,255,.85);
        backdrop-filter: blur(2px);
        z-index: 9999;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        transition: opacity .3s;
    }
    #loadingOverlay.hide {
        opacity: 0;
        pointer-events: none;
    }
    .loading-coin {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: linear-gradient(135deg, #16a34a, #2563eb);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1.6rem;
        font-weight: 900;
        font-family: ui-monospace, monospace;
        animation: coinSpin 1s ease-in-out infinite;
        box-shadow: 0 4px 20px rgba(37,99,235,.25);
    }
    .loading-text {
        margin-top: 12px;
        font-size: .82rem;
        font-weight: 600;
        color: #64748b;
        letter-spacing: .03em;
    }
    @keyframes coinSpin {
        0%   { transform: rotateY(0deg); }
        50%  { transform: rotateY(180deg); }
        100% { transform: rotateY(360deg); }
    }
</style>