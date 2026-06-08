// /app/assets/js/app.js
document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("menu-toggle");
  const sidebar = document.getElementById("sidebar");

  if (!btn || !sidebar) return;

  // restaura estado
  const saved = localStorage.getItem("dre_sidebar_collapsed") === "1";
  document.body.classList.toggle("sidebar-collapsed", saved);
  sidebar.classList.toggle("collapsed", saved);

  btn.addEventListener("click", (e) => {
    e.preventDefault();
    const isCollapsed = document.body.classList.toggle("sidebar-collapsed");
    sidebar.classList.toggle("collapsed", isCollapsed);
    localStorage.setItem("dre_sidebar_collapsed", isCollapsed ? "1" : "0");
  });
});


// ===== Tooltips do Sidebar (Bootstrap 5) =====
(function () {
  function getLinkLabel(a) {
    // pega o texto do link sem o ícone
    // ex: "<i...></i>Dashboard" => "Dashboard"
    const txt = (a.textContent || "").replace(/\s+/g, " ").trim();
    return txt || "Menu";
  }

  function enableSidebarTooltips() {
    const sidebar = document.getElementById("sidebar");
    if (!sidebar) return;

    const isCollapsed = sidebar.classList.contains("collapsed");

    // pega todos os links do menu
    const links = sidebar.querySelectorAll("a.sidebar-link");

    // se não está colapsado, destrói tooltips e remove atributos
    if (!isCollapsed) {
      links.forEach((a) => {
        const inst = bootstrap.Tooltip.getInstance(a);
        if (inst) inst.dispose();
        a.removeAttribute("data-bs-toggle");
        a.removeAttribute("data-bs-placement");
        a.removeAttribute("data-bs-custom-class");
        a.removeAttribute("title");
      });
      return;
    }

    // colapsado: cria tooltips
    links.forEach((a) => {
      const label = getLinkLabel(a);

      // configura atributos
      a.setAttribute("title", label);
      a.setAttribute("data-bs-toggle", "tooltip");
      a.setAttribute("data-bs-placement", "right");
      a.setAttribute("data-bs-custom-class", "sidebar-tooltip");

      // cria (ou recria) tooltip
      const inst = bootstrap.Tooltip.getInstance(a);
      if (inst) inst.dispose();
      new bootstrap.Tooltip(a, { trigger: "hover", container: "body" });
    });
  }

  // roda no load
  document.addEventListener("DOMContentLoaded", enableSidebarTooltips);

  // roda quando clica no botão de colapse
  document.addEventListener("click", (e) => {
    const btn = e.target.closest("#menu-toggle");
    if (!btn) return;
    // espera a classe collapsed ser aplicada pelo seu handler
    setTimeout(enableSidebarTooltips, 0);
  });

  // se você colapsa por outro lugar (ex: localStorage), isso garante
  window.addEventListener("resize", () => {
    // opcional: só reprocessa se estiver colapsado
    setTimeout(enableSidebarTooltips, 0);
  });
})();
