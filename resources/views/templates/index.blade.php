@extends('layouts.app')
@section('title', 'Modelos')
@section('section', 'Assinaturas')
@section('actions')<a href="{{ route('templates.create') }}" class="btn btn-primary"><i class="bx bx-plus"></i> Novo modelo</a>@endsection
@section('content')
<div class="card radius-10">
    <div class="card-body">
        <p class="text-secondary">Um modelo é o seu PDF com lacunas marcadas (nome, valor, data…). Você sobe o documento uma vez e, a cada uso, só preenche as lacunas: o sistema escreve os dados no PDF e segue para escolher quem assina.</p>
        @if ($templates->isEmpty())
            <div class="text-center py-4">
                <i class="bx bx-copy-alt fs-1 text-secondary"></i>
                <p class="text-secondary mt-2">Nenhum modelo ainda. Comece subindo um PDF, como um contrato com espaços em branco.</p>
                <a class="btn btn-primary" href="{{ route('templates.create') }}">Criar o primeiro modelo</a>
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Nome</th><th>Lacunas</th><th>Páginas</th><th>Atualizado</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($templates as $t)
                        <tr>
                            <td><a class="fw-semibold" href="{{ route('templates.edit', $t) }}">{{ $t->name }}</a></td>
                            <td>@if ($t->fields_count > 0){{ $t->fields_count }} {{ $t->fields_count === 1 ? 'lacuna' : 'lacunas' }}@else<span class="text-secondary">nenhuma ainda</span>@endif</td>
                            <td>{{ $t->page_count ?? '—' }}</td>
                            <td>{{ $t->updated_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                            <td class="text-end text-nowrap">
                                @if ($t->fields_count > 0)
                                    <a class="btn btn-sm btn-primary" href="{{ route('templates.use', $t) }}"><i class="bx bx-file"></i> Usar</a>
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('templates.edit', $t) }}">Editar</a>
                                @else
                                    <a class="btn btn-sm btn-primary" href="{{ route('templates.edit', $t) }}"><i class="bx bx-edit"></i> Marcar lacunas</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
