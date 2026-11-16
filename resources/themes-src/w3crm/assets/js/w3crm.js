/*
 * Tema W3 CRM — comportamento da casca (sem jQuery: o sistema já carrega o dele em $legacyScripts).
 * Menu lateral (recolher no desktop, gaveta no celular/tablet), submenus, menu do usuário e modo claro/escuro.
 */
(function () {
    'use strict';

    var KEY = 'theme';
    var MOBILE = 1024;
    var html = document.documentElement;
    var body = document.body;
    var wrapper = document.getElementById('main-wrapper');
    if (!wrapper) { return; }
    var toggle = wrapper.querySelector('.w3-nav-control');
    var backdrop = wrapper.querySelector('.w3-backdrop');

    /* ---------- Modo claro/escuro ---------- */
    function stored() {
        try { return localStorage.getItem(KEY) === 'dark' ? 'dark' : 'light'; } catch (e) { return 'light'; }
    }

    function applyTheme(theme, save) {
        var dark = theme === 'dark';
        body.setAttribute('data-theme-version', dark ? 'dark' : 'light');
        html.classList.toggle('dark-theme', dark);
        html.classList.toggle('light-theme', !dark);
        document.querySelectorAll('.w3-theme-toggle').forEach(function (b) {
            b.setAttribute('aria-pressed', dark ? 'true' : 'false');
            var i = b.querySelector('i');
            if (i) { i.className = 'las ' + (dark ? 'la-sun' : 'la-moon'); }
        });
        if (save) {
            try { localStorage.setItem(KEY, dark ? 'dark' : 'light'); } catch (e) { /* navegação privada */ }
        }
        document.dispatchEvent(new CustomEvent('w3:theme', { detail: { theme: dark ? 'dark' : 'light' } }));
    }

    applyTheme(stored(), false);
    document.querySelectorAll('.w3-theme-toggle').forEach(function (b) {
        b.addEventListener('click', function () {
            applyTheme(body.getAttribute('data-theme-version') === 'dark' ? 'light' : 'dark', true);
        });
    });

    /* ---------- Menu lateral ---------- */
    function isMobile() { return window.innerWidth < MOBILE; }

    function setOpen(open) {
        wrapper.classList.toggle('w3-open', open);
        if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    function syncToggleState() {
        if (!toggle) { return; }
        var expanded = isMobile() ? wrapper.classList.contains('w3-open') : !wrapper.classList.contains('w3-collapsed');
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (isMobile()) {
                setOpen(!wrapper.classList.contains('w3-open'));
            } else {
                wrapper.classList.toggle('w3-collapsed');
                try { localStorage.setItem('w3crm-collapsed', wrapper.classList.contains('w3-collapsed') ? '1' : '0'); } catch (e) { /* ok */ }
                syncToggleState();
            }
        });
    }
    try { if (localStorage.getItem('w3crm-collapsed') === '1') { wrapper.classList.add('w3-collapsed'); } } catch (e) { /* ok */ }
    syncToggleState();

    if (backdrop) { backdrop.addEventListener('click', function () { setOpen(false); }); }
    window.addEventListener('resize', function () {
        if (!isMobile() && wrapper.classList.contains('w3-open')) { setOpen(false); }
        syncToggleState();
    });

    /* Submenus (comportamento do metisMenu, sem a dependência) */
    document.querySelectorAll('#w3menu .has-arrow').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            /* Pai com endereço próprio (ex.: Documentos): navega; a página nova já abre o grupo. */
            var target = a.getAttribute('href');
            if (target && target !== '#') { return; }
            ev.preventDefault();
            var li = a.parentElement;
            var willOpen = !li.classList.contains('mm-active');
            li.parentElement.querySelectorAll(':scope > li.mm-active').forEach(function (other) {
                if (other !== li && other.querySelector(':scope > .has-arrow')) {
                    other.classList.remove('mm-active');
                    var ul = other.querySelector(':scope > ul');
                    if (ul) { ul.classList.remove('mm-show'); }
                    other.querySelector(':scope > .has-arrow').setAttribute('aria-expanded', 'false');
                }
            });
            li.classList.toggle('mm-active', willOpen);
            var sub = li.querySelector(':scope > ul');
            if (sub) { sub.classList.toggle('mm-show', willOpen); }
            a.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            /* Menu recolhido: abrir um submenu expande o menu */
            if (willOpen && !isMobile() && wrapper.classList.contains('w3-collapsed')) {
                wrapper.classList.remove('w3-collapsed');
                syncToggleState();
            }
        });
    });

    /* ---------- Menus suspensos (menu do usuário) ---------- */
    function closeAll(except) {
        document.querySelectorAll('[data-w3-dropdown]').forEach(function (btn) {
            var menu = document.getElementById(btn.getAttribute('data-w3-dropdown'));
            if (menu && menu !== except) {
                menu.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    document.querySelectorAll('[data-w3-dropdown]').forEach(function (btn) {
        var menu = document.getElementById(btn.getAttribute('data-w3-dropdown'));
        if (!menu) { return; }
        btn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var open = menu.hidden;
            closeAll(menu);
            menu.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                var first = menu.querySelector('a, button');
                if (first) { first.focus(); }
            }
        });
    });

    document.addEventListener('click', function (ev) {
        if (!ev.target.closest('.w3-dd-menu')) { closeAll(null); }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') { return; }
        var openBtn = document.querySelector('[data-w3-dropdown][aria-expanded="true"]');
        closeAll(null);
        if (openBtn) { openBtn.focus(); }
        if (wrapper.classList.contains('w3-open')) {
            setOpen(false);
            if (toggle) { toggle.focus(); }
        }
    });
})();
