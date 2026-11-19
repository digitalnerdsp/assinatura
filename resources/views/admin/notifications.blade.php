@extends('layouts.app')
@section('title', 'Avisos do WhatsApp')
@section('section', 'Plataforma')
@section('content')
@php
    $badge = ['rascunho' => 'secondary', 'enviado' => 'info', 'aprovado' => 'success', 'rejeitado' => 'danger', 'pausado' => 'warning'];
@endphp
@if (session('error'))<div class="alert alert-danger border-0">{{ session('error') }}</div>@endif
<div class="alert alert-info border-0">
    Cada aviso é uma mensagem de <strong>modelo</strong>, que a Meta precisa aprovar antes do envio (é a única forma de falar com o usuário fora da janela de 24 horas).
    Escreva o texto, clique em <strong>Enviar para aprovação</strong> e depois em <strong>Atualizar status</strong>. Só modelos <strong>aprovados</strong> e ligados enviam.
    Mantenha o tom de aviso (sem oferta nem convite) para a Meta aprovar como "utilidade", que é a categoria mais barata. O custo cobrado vem da categoria que a Meta aplicar.
    @unless ($hasWaba)<br><strong>Falta o identificador da conta (WABA)</strong> em Admin ▸ Assistente WhatsApp para enviar modelos.@endunless
</div>
<div class="d-flex justify-content-end mb-3">
    <form method="post" action="{{ route('admin.notifications.sync') }}">@csrf<button class="btn btn-outline-primary btn-sm">Atualizar status na Meta</button></form>
</div>
<div class="row">
@foreach ($catalog as $key => $def)
    @php $t = $templates[$key]; @endphp
    <div class="col-12 col-xl-6">
        <div class="card radius-10">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">{{ $def['label'] }}</h6>
                <span class="badge bg-{{ $badge[$t->status] ?? 'secondary' }}">{{ $t->status }}</span>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-2">{{ $def['when'] }}</p>
                <form method="post" action="{{ route('admin.notifications.update', $key) }}" class="row g-2">
                    @csrf @method('PUT')
                    <div class="col-md-8"><label class="form-label" for="n-{{ $key }}-name">Nome do modelo na Meta</label><input id="n-{{ $key }}-name" class="form-control" name="meta_name" value="{{ old('meta_name', $t->meta_name) }}">@error('meta_name')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                    <div class="col-md-4"><label class="form-label" for="n-{{ $key }}-lang">Idioma</label><input id="n-{{ $key }}-lang" class="form-control" name="language" value="{{ old('language', $t->language) }}">@error('language')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                    <div class="col-12">
                        <label class="form-label" for="n-{{ $key }}-body">Texto</label>
                        <textarea id="n-{{ $key }}-body" class="form-control" rows="4" name="body">{{ old('body', $t->body) }}</textarea>
                        @error('body')<div class="text-danger small">{{ $message }}</div>@enderror
                        <div class="form-text">Variáveis: @foreach ($def['vars'] as $i => $v)<code>&#123;&#123;{{ $i + 1 }}&#125;&#125;</code> {{ $v }}@if (! $loop->last), @endif @endforeach</div>
                    </div>
                    <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="n-{{ $key }}-on" name="enabled" value="1" @checked($t->enabled) @disabled($t->status !== 'aprovado')><label class="form-check-label" for="n-{{ $key }}-on">Ligado</label></div>
                        <span class="text-secondary small">Categoria pela Meta: {{ $t->category }}</span>
                        <button class="btn btn-primary btn-sm ms-auto">Salvar</button>
                    </div>
                    @if ($t->status === 'rejeitado' && $t->rejected_reason)<div class="col-12 text-danger small">Rejeitado pela Meta: {{ $t->rejected_reason }}</div>@endif
                </form>
                @if (in_array($t->status, ['rascunho', 'rejeitado'], true))
                    <form method="post" action="{{ route('admin.notifications.submit', $key) }}" class="mt-2">@csrf<button class="btn btn-outline-success btn-sm">Enviar para aprovação</button></form>
                @endif
            </div>
        </div>
    </div>
@endforeach
</div>
@endsection
