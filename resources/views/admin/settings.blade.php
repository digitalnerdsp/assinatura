@extends('layouts.app')
@section('title', 'Configurações')
@section('section', 'Plataforma')
@section('content')
<div class="row">
    <div class="col-12 col-lg-6">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-user-plus me-1"></i> Cadastros</h6></div>
            <div class="card-body">
                <form method="post" action="{{ route('admin.settings.update') }}">
                    @csrf @method('PUT')
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="registration_open" name="registration_open" value="1" @checked($registrationOpen)>
                        <label class="form-check-label" for="registration_open">Permitir novos cadastros de empresas em <span class="mono">/cadastro</span></label>
                    </div>
                    <p class="text-secondary">Desmarque para aceitar apenas clientes que você já cadastrou. Quem já tem conta continua entrando normalmente.</p>
                    <button class="btn btn-primary">Salvar</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-shield me-1"></i> Super administradores</h6></div>
            <div class="card-body">
                <p class="text-secondary">Definidos pela variável de ambiente <span class="mono">SUPER_ADMIN_EMAILS</span> (separe vários por vírgula).</p>
                <ul class="list-group">@foreach ($admins as $a)<li class="list-group-item">{{ $a }}</li>@endforeach</ul>
            </div>
        </div>
    </div>
</div>
@endsection
