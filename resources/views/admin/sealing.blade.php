@extends('layouts.app')
@section('title', 'Selagem do PDF')
@section('section', 'Plataforma')
@section('content')
<div class="row">
    <div class="col-12 col-xl-6">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-shield-quarter me-1"></i> Configuração atual</h6></div>
            <div class="card-body">
                <table class="table table-sm mb-3">
                    <tr><th style="width:45%">Provider da plataforma</th><td><span class="badge bg-secondary">{{ $provider }}</span></td></tr>
                    <tr><th>Certificado de teste permitido</th><td>{{ $allowLocalTest ? 'sim (SIGNING_ALLOW_LOCAL_TEST)' : 'não' }}</td></tr>
                    <tr><th>Endereço do serviço</th><td class="mono">{{ $sealerUrl }}</td></tr>
                    <tr><th>SEALER_TOKEN definido</th><td>{{ $hasToken ? 'sim' : 'NÃO — defina no ambiente' }}</td></tr>
                </table>
                <form method="post" action="{{ route('admin.sealing.test') }}">@csrf
                    <button class="btn btn-primary"><i class="bx bx-plug"></i> Testar conexão e certificado</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0">Resultado do teste</h6></div>
            <div class="card-body">
                @if (! $result)
                    <p class="text-secondary mb-0">Clique em "Testar" para verificar se o serviço de selagem responde e se o certificado da plataforma é lido.</p>
                @else
                    @foreach ($result as $step)
                        <div class="d-flex gap-2 mb-3">
                            <i class="bx {{ $step['ok'] ? 'bx-check-circle text-success' : 'bx-x-circle text-danger' }} fs-3"></i>
                            <div><strong>{{ $step['label'] }}</strong><div class="small {{ $step['ok'] ? 'text-secondary' : 'text-danger' }}">{{ $step['detail'] }}</div></div>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
