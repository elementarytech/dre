<?php
// /app/includes/scripts.php
declare(strict_types=1);
?>

<!-- Loading Overlay -->
<div id="loadingOverlay">
    <div class="loading-coin">$</div>
    <div class="loading-text">Carregando dados...</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script src="assets/js/app.js"></script>

<script>
// Loading overlay control
window.showLoading = function(msg) {
    const el = document.getElementById('loadingOverlay');
    if (!el) return;
    if (msg) { const t = el.querySelector('.loading-text'); if (t) t.textContent = msg; }
    el.classList.remove('hide');
};
window.hideLoading = function() {
    const el = document.getElementById('loadingOverlay');
    if (el) el.classList.add('hide');
};

// Auto-hide when page fully loaded + small delay for AJAX
document.addEventListener('DOMContentLoaded', () => {
    // Hide after a short delay to allow initial AJAX to start
    setTimeout(() => {
        // If still showing after 15s, force hide (safety net)
        setTimeout(hideLoading, 15000);
    }, 100);
});

// Intercept fetch to auto-show/hide loading
(function() {
    let activeRequests = 0;
    const originalFetch = window.fetch;

    window.fetch = function() {
        activeRequests++;
        // window.__silentFetch === true → não mostra overlay (usado em recargas de lista por debounce).
        if (activeRequests === 1 && !window.__silentFetch) showLoading();

        return originalFetch.apply(this, arguments)
            .then(response => {
                activeRequests--;
                if (activeRequests <= 0) { activeRequests = 0; hideLoading(); }
                return response;
            })
            .catch(error => {
                activeRequests--;
                if (activeRequests <= 0) { activeRequests = 0; hideLoading(); }
                throw error;
            });
    };
})();
</script>
