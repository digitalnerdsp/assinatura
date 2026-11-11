/*
 * Comportamento do layout (menu lateral, modo escuro, voltar ao topo).
 *
 * Substitui o app.js do template Rocker, que aborta logo na primeira linha
 * (new PerfectScrollbar(".app-container")) quando a página não tem os elementos de chat/e-mail do template.
 * Sem isso, nenhum dos botões abaixo era ligado.
 */
(function ($) {
    'use strict';

    var THEME_KEY = 'theme';

    function storedTheme() {
        try { return localStorage.getItem(THEME_KEY) === 'dark' ? 'dark' : 'light'; } catch (e) { return 'light'; }
    }

    function applyTheme(theme) {
        var root = document.documentElement;
        root.classList.remove('dark-theme', 'light-theme');
        root.classList.add(theme === 'dark' ? 'dark-theme' : 'light-theme');
        // O ícone mostra a ação disponível: sol = voltar ao claro, lua = ir para o escuro.
        $('.dark-mode-icon i').attr('class', theme === 'dark' ? 'bx bx-sun' : 'bx bx-moon');
        try { localStorage.setItem(THEME_KEY, theme); } catch (e) { /* navegação privada */ }
    }

    $(function () {
        var $wrapper = $('.wrapper');
        var $sidebar = $('.sidebar-wrapper');

        // Menu com submenus (se houver)
        if ($.fn.metisMenu) { $('#menu').metisMenu(); }

        // Tema: o <head> já aplicou a classe antes de pintar; aqui só sincroniza o ícone e liga o botão.
        applyTheme(storedTheme());
        $('.dark-mode').on('click', function () {
            applyTheme(document.documentElement.classList.contains('dark-theme') ? 'light' : 'dark');
        });

        // Menu lateral.
        // Telas grandes: a seta recolhe o menu (ele reabre ao passar o mouse). Telas pequenas: o botão ☰ abre
        // o menu por cima do conteúdo e a seta (ou o fundo escurecido) fecha.
        $('.mobile-toggle-menu').on('click', function () {
            $wrapper.addClass('toggled');
        });

        $('.toggle-icon').on('click', function () {
            if ($wrapper.hasClass('toggled')) {
                $wrapper.removeClass('toggled sidebar-hovered');
                $sidebar.off('mouseenter.sidebar mouseleave.sidebar');
            } else {
                $wrapper.addClass('toggled');
                $sidebar
                    .on('mouseenter.sidebar', function () { $wrapper.addClass('sidebar-hovered'); })
                    .on('mouseleave.sidebar', function () { $wrapper.removeClass('sidebar-hovered'); });
            }
        });

        // Voltar ao topo
        var $top = $('.back-to-top');
        $(window).on('scroll', function () {
            if ($(this).scrollTop() > 300) { $top.fadeIn(); } else { $top.fadeOut(); }
        });
        $top.on('click', function () {
            $('html, body').animate({ scrollTop: 0 }, 600);
            return false;
        });
    });
}(window.jQuery));
