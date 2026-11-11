<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>API v1 · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.min.css">
    <style>html,body{margin:0;background:#fff;color-scheme:light}.topbar-note{padding:12px 20px;background:#f5f7fb;border-bottom:1px solid #e4e7ee;font:14px system-ui,sans-serif}.topbar-note a{color:#0d6efd}</style>
</head>
<body>
<div class="topbar-note">Esta é a referência da API v1. Gere sua chave em <a href="{{ route('api-keys.index') }}">API</a> no painel e use o botão <strong>Authorize</strong> abaixo para testar as chamadas. Especificação: <a href="{{ url('/api/v1/openapi.json') }}">openapi.json</a>.</div>
<div id="swagger"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-bundle.min.js"></script>
<script>
    window.ui = SwaggerUIBundle({ url: @json(url('/api/v1/openapi.json')), dom_id: '#swagger', deepLinking: true, tryItOutEnabled: false, docExpansion: 'list' });
</script>
</body>
</html>
