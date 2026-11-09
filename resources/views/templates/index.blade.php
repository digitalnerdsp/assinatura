@extends('layouts.app')
@section('title', 'Modelos')
@section('section', 'Assinaturas')
@section('actions')<a href="{{ route('templates.create') }}" class="btn btn-primary"><i class="bx bx-plus"></i> Novo modelo</a>@endsection
@section('content')
<div class="card radius-10">
    <div class="card-body">
        <p class="text-secondary">Modelos são contratos com campos variáveis, como <code>@{{nome}}</code> ou <code>@{{valor}}</code>. Você escreve uma vez e gera um PDF novo para cada cliente, já pronto para assinatura.</p>
        @if ($templates->isEmpty())
            <div class="text-center py-4">
                <i class="bx bx-copy-alt fs-1 text-secondary"></i>
                <p class="text-secondary mt-2">Nenhum modelo ainda.</p>
                <a class="btn btn-primary" href="{{ route('templates.create') }}">Criar o primeiro modelo</a>
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Nome</th><th>Variáveis</th><th>Atualizado</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($templates as $t)
                        <tr>
                            <td><a class="fw-semibold" href="{{ route('templates.edit', $t) }}">{{ $t->name }}</a></td>
                            <td>@foreach ($t->variables() as $v)<span class="badge bg-light text-dark border me-1">{{ $v }}</span>@endforeach</td>
                            <td>{{ $t->updated_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                            <td class="text-end"><a class="btn btn-sm btn-primary" href="{{ route('templates.use', $t) }}"><i class="bx bx-file"></i> Usar</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
