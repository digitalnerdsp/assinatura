<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/js/jquery.min.js') }}"></script>
<script src="{{ asset('assets/plugins/simplebar/js/simplebar.min.js') }}"></script>
<script src="{{ asset('assets/plugins/metismenu/js/metisMenu.min.js') }}"></script>
<script src="{{ asset('assets/plugins/perfect-scrollbar/js/perfect-scrollbar.js') }}"></script>
<script src="{{ asset('assets/js/app.js') }}"></script>
<script>
    // Mostrar/ocultar senha (campos dentro de .js-password)
    document.querySelectorAll('.js-password').forEach(function (g) {
        var a = g.querySelector('a'), i = g.querySelector('input'), ic = g.querySelector('i');
        if (!a) return;
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var show = i.type === 'password';
            i.type = show ? 'text' : 'password';
            ic.className = show ? 'bx bx-show' : 'bx bx-hide';
        });
    });
    // Guarda o tema escolhido
    document.querySelectorAll('.dark-mode').forEach(function (b) {
        b.addEventListener('click', function () {
            setTimeout(function () {
                try { localStorage.setItem('theme', document.documentElement.classList.contains('dark-theme') ? 'dark' : 'light'); } catch (e) {}
            }, 0);
        });
    });
</script>
@stack('scripts')
