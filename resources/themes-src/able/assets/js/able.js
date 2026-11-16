/*
 * Able Pro adaptado: comportamento do layout sem depender do JS do template
 * (menu com submenus, menu recolhido/celular, menus suspensos do topo e tema claro/escuro).
 * O tema escolhido fica em localStorage "theme" ("dark" ou "light"), o mesmo das telas internas.
 */
(function () {
  'use strict';

  var root = document.documentElement;
  var sidebar = document.querySelector('.pc-sidebar');

  function store(key, value) {
    try { localStorage.setItem(key, value); } catch (e) { /* navegação privada */ }
  }
  function read(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }

  /* ---------- Tema claro/escuro ---------- */
  function isDark() { return root.getAttribute('data-pc-theme') === 'dark'; }

  function applyTheme(theme) {
    var dark = theme === 'dark';
    root.setAttribute('data-pc-theme', dark ? 'dark' : 'light');
    root.classList.toggle('dark-theme', dark);
    root.classList.toggle('light-theme', !dark);
    var btn = document.getElementById('ab-theme-toggle');
    if (btn) {
      var icon = btn.querySelector('i');
      if (icon) { icon.className = dark ? 'ti ti-sun' : 'ti ti-moon'; }
      btn.setAttribute('aria-label', dark ? 'Usar tema claro' : 'Usar tema escuro');
      btn.setAttribute('title', dark ? 'Usar tema claro' : 'Usar tema escuro');
    }
    store('theme', dark ? 'dark' : 'light');
    window.dispatchEvent(new CustomEvent('able:theme', { detail: { dark: dark } }));
  }

  /* ---------- Menus suspensos do topo ---------- */
  function closeDropdowns(except) {
    document.querySelectorAll('.dropdown.drp-show').forEach(function (d) {
      if (d === except) { return; }
      d.classList.remove('drp-show');
      var t = d.querySelector('[data-pc-toggle="dropdown"]');
      if (t) { t.setAttribute('aria-expanded', 'false'); }
    });
  }

  /* ---------- Menu no celular ---------- */
  function closeMobileMenu() {
    if (!sidebar) { return; }
    sidebar.classList.remove('mob-sidebar-active');
    var overlay = sidebar.querySelector('.pc-menu-overlay');
    if (overlay) { overlay.remove(); }
    var btn = document.getElementById('mobile-collapse');
    if (btn) { btn.setAttribute('aria-expanded', 'false'); }
  }

  function openMobileMenu() {
    if (!sidebar) { return; }
    sidebar.classList.add('mob-sidebar-active');
    if (!sidebar.querySelector('.pc-menu-overlay')) {
      var overlay = document.createElement('div');
      overlay.className = 'pc-menu-overlay';
      overlay.addEventListener('click', closeMobileMenu);
      sidebar.appendChild(overlay);
    }
    var btn = document.getElementById('mobile-collapse');
    if (btn) { btn.setAttribute('aria-expanded', 'true'); }
    var first = sidebar.querySelector('.pc-link');
    if (first) { first.focus({ preventScroll: true }); }
  }

  function init() {
    applyTheme(read('theme') === 'dark' ? 'dark' : 'light');

    var themeBtn = document.getElementById('ab-theme-toggle');
    if (themeBtn) {
      themeBtn.addEventListener('click', function () { applyTheme(isDark() ? 'light' : 'dark'); });
    }

    // Submenus: um aberto por vez; o grupo da página atual já vem aberto (pc-trigger).
    document.querySelectorAll('[data-ab-submenu]').forEach(function (link) {
      link.addEventListener('click', function (ev) {
        ev.preventDefault();
        var li = link.parentNode;
        var open = !li.classList.contains('pc-trigger');
        li.parentNode.querySelectorAll(':scope > .pc-hasmenu.pc-trigger').forEach(function (other) {
          if (other !== li) {
            other.classList.remove('pc-trigger');
            var l = other.querySelector('[data-ab-submenu]');
            if (l) { l.setAttribute('aria-expanded', 'false'); }
          }
        });
        li.classList.toggle('pc-trigger', open);
        link.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });

    // Menu recolhido em telas grandes (lembra a escolha).
    var hideBtn = document.getElementById('sidebar-hide');
    if (sidebar && read('able-sidebar') === 'hidden') {
      sidebar.classList.add('pc-sidebar-hide');
      if (hideBtn) { hideBtn.setAttribute('aria-expanded', 'false'); }
    }
    if (hideBtn && sidebar) {
      hideBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        var hidden = sidebar.classList.toggle('pc-sidebar-hide');
        hideBtn.setAttribute('aria-expanded', hidden ? 'false' : 'true');
        store('able-sidebar', hidden ? 'hidden' : 'shown');
        // Gráficos recalculam a largura depois da animação do menu.
        window.setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 250);
      });
    }

    var mobileBtn = document.getElementById('mobile-collapse');
    if (mobileBtn) {
      mobileBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (sidebar && sidebar.classList.contains('mob-sidebar-active')) { closeMobileMenu(); } else { openMobileMenu(); }
      });
    }

    document.querySelectorAll('[data-pc-toggle="dropdown"]').forEach(function (toggle) {
      toggle.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        var dd = toggle.closest('.dropdown');
        if (!dd) { return; }
        var open = !dd.classList.contains('drp-show');
        closeDropdowns(dd);
        dd.classList.toggle('drp-show', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
          var item = dd.querySelector('.dropdown-menu .dropdown-item');
          if (item) { item.focus({ preventScroll: true }); }
        }
      });
    });

    document.addEventListener('click', function (ev) {
      if (!ev.target.closest('.dropdown-menu')) { closeDropdowns(null); }
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Escape') { return; }
      var open = document.querySelector('.dropdown.drp-show');
      if (open) {
        closeDropdowns(null);
        var t = open.querySelector('[data-pc-toggle="dropdown"]');
        if (t) { t.focus(); }
      }
      if (sidebar && sidebar.classList.contains('mob-sidebar-active')) {
        closeMobileMenu();
        if (mobileBtn) { mobileBtn.focus(); }
      }
    });

    // Ao crescer a janela, o menu do celular some.
    window.addEventListener('resize', function () {
      if (window.innerWidth >= 1024) { closeMobileMenu(); }
    });

    // Barra de rolagem fina no menu (SimpleBar, se carregado).
    var content = document.querySelector('.pc-sidebar .navbar-content');
    if (content && typeof window.SimpleBar === 'function') {
      try { new window.SimpleBar(content); } catch (e) { /* segue com a rolagem nativa */ }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Cores atuais do tema para os gráficos das páginas.
  window.AbleTheme = {
    isDark: isDark,
    color: function (name, fallback) {
      var v = getComputedStyle(root).getPropertyValue(name).trim();
      return v || fallback;
    }
  };
})();
