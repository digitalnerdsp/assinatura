<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="icon" href="{{ asset('assets/images/favicon-32x32.png') }}" type="image/png">
<link href="{{ asset('assets/plugins/simplebar/css/simplebar.css') }}" rel="stylesheet">
<link href="{{ asset('assets/plugins/perfect-scrollbar/css/perfect-scrollbar.css') }}" rel="stylesheet">
<link href="{{ asset('assets/plugins/metismenu/css/metisMenu.min.css') }}" rel="stylesheet">
<link href="{{ asset('assets/css/pace.min.css') }}" rel="stylesheet">
<script src="{{ asset('assets/js/pace.min.js') }}"></script>
<link href="{{ asset('assets/css/bootstrap.min.css') }}" rel="stylesheet">
<link href="{{ asset('assets/css/bootstrap-extended.css') }}" rel="stylesheet">
<link href="{{ asset('assets/css/app.css') }}" rel="stylesheet">
<link href="{{ asset('assets/css/typography.css') }}?v=1" rel="stylesheet">
<link href="{{ asset('assets/css/icons.css') }}" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('assets/css/dark-theme.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/semi-dark.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/contrast.css') }}?v=5">
<link rel="stylesheet" href="{{ asset('assets/css/drive.css') }}?v=3">
<style>
    .brand-badge{width:38px;height:38px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:22px;background:linear-gradient(135deg,#6078ea,#17c5ea)}
    .mono{font-family:ui-monospace,Consolas,monospace;font-size:.82rem;word-break:break-all}
    .chart-box{position:relative;height:280px}.chart-box.sm{height:220px}
    .steps-wizard{display:flex;gap:.5rem;flex-wrap:wrap;margin:0 0 1rem;padding:0;list-style:none}
    .steps-wizard li{flex:1 1 130px;padding:.5rem .75rem;border:1px solid rgba(0,0,0,.12);border-radius:.5rem;font-size:.9rem;opacity:.7}
    .steps-wizard li.done{opacity:1;border-color:#198754;color:#198754}
    .steps-wizard li.current{opacity:1;border-color:#0d6efd;font-weight:600;box-shadow:inset 0 0 0 1px #0d6efd}
    canvas.pad{width:100%;height:170px;border:2px dashed #adb5bd;border-radius:.5rem;background:#fff;touch-action:none}
    iframe.pdf{width:100%;height:62vh;min-height:360px;border:1px solid rgba(0,0,0,.15);border-radius:.5rem;background:#fff}
    video.cam,img.shot{width:100%;max-height:60vh;border-radius:.5rem;background:#000;object-fit:cover}
    .sign-preview{font:italic 2.2rem/1.2 "Brush Script MT","Segoe Script","Snell Roundhand",cursive;padding:.9rem;border:1px dashed #adb5bd;border-radius:.5rem;background:#fff;color:#111;min-height:70px;word-break:break-word}
    html.dark-theme .steps-wizard li{border-color:rgba(255,255,255,.22)}
    html.dark-theme .steps-wizard li.done{border-color:#2fb36d;color:#4cd18a}
    html.dark-theme .steps-wizard li.current{border-color:#4d8dff;box-shadow:inset 0 0 0 1px #4d8dff}
    html.dark-theme iframe.pdf{border-color:rgba(255,255,255,.2)}
</style>
<script>
    // Restaura o tema escolhido (claro/escuro) antes de pintar a página.
    try { if (localStorage.getItem('theme') === 'dark') document.documentElement.className = 'dark-theme'; } catch (e) {}
</script>
