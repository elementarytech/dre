// =============================================================
// session_keeper.js
// Faz logout automático após 1h de inatividade real do usuário.
// "Inatividade" = sem mousemove, mousedown, keydown, scroll, touch
// e sem o tab estar visível mais nada.
//
// Também intercepta respostas 401 de fetch: se o servidor já
// invalidou a sessão (definido em app/config/auth.php), redireciona
// para a tela de login.
// =============================================================
(function () {
  'use strict';

  // Tem que bater com SESSION_IDLE_SECONDS no auth.php.
  var IDLE_LIMIT_MS  = 60 * 60 * 1000;   // 1h
  var WARN_BEFORE_MS =  2 * 60 * 1000;   // alerta 2min antes
  var CHECK_EVERY_MS = 30 * 1000;        // checa a cada 30s
  var LOGOUT_URL     = 'logout.php';
  var STORAGE_KEY    = '__session_last_activity__';

  function now() { return Date.now(); }

  function bump() {
    try { localStorage.setItem(STORAGE_KEY, String(now())); } catch (e) {}
  }

  function lastActivity() {
    try {
      var v = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10);
      return isFinite(v) && v > 0 ? v : now();
    } catch (e) { return now(); }
  }

  // Eventos que contam como atividade (compartilhada entre abas via storage)
  var EVENTS = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'];
  EVENTS.forEach(function (ev) {
    window.addEventListener(ev, bump, { passive: true });
  });
  window.addEventListener('storage', function (e) {
    if (e.key === STORAGE_KEY) {/* outra aba marcou atividade — nada a fazer */}
  });

  // Se o usuário acabou de abrir, registra atividade.
  bump();

  var warnedShown = false;

  function doLogout(motivo) {
    try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
    // SweetAlert2 se disponível, senão alert nativo.
    if (window.Swal && typeof Swal.fire === 'function') {
      Swal.fire({
        icon: 'info',
        title: 'Sessão encerrada',
        text: motivo || 'Você ficou inativo por mais de 1 hora.',
        confirmButtonText: 'Entrar novamente'
      }).then(function () { window.location.href = LOGOUT_URL; });
    } else {
      alert(motivo || 'Sessão encerrada por inatividade. Faça login novamente.');
      window.location.href = LOGOUT_URL;
    }
  }

  function maybeWarn(remaining) {
    if (warnedShown) return;
    warnedShown = true;
    if (window.Swal && typeof Swal.fire === 'function') {
      Swal.fire({
        icon: 'warning',
        title: 'Sua sessão vai expirar',
        text: 'Movimente o mouse ou clique em qualquer lugar para continuar.',
        timer: Math.max(5000, remaining),
        showConfirmButton: false,
        timerProgressBar: true
      });
    }
  }

  setInterval(function () {
    var idle = now() - lastActivity();
    if (idle >= IDLE_LIMIT_MS) {
      doLogout('Você ficou inativo por mais de 1 hora. Faça login novamente.');
    } else if (idle >= IDLE_LIMIT_MS - WARN_BEFORE_MS) {
      maybeWarn(IDLE_LIMIT_MS - idle);
    } else {
      warnedShown = false;
    }
  }, CHECK_EVERY_MS);

  // Intercepta fetch para detectar 401 vindo do auth.php (sessão derrubada).
  if (window.fetch) {
    var origFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
      return origFetch(input, init).then(function (resp) {
        if (resp && resp.status === 401) {
          // Tenta peek do JSON sem consumir o body original.
          resp.clone().json().then(function (j) {
            if (j && j.expired) doLogout('Sua sessão expirou. Faça login novamente.');
          }).catch(function () { /* não-JSON, ignora */ });
        }
        return resp;
      });
    };
  }
})();
