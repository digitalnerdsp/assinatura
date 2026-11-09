@extends('layouts.app')
@section('title', 'Clientes')
@section('section', 'Plataforma')
@section('content')
<div class="card radius-10">
    <div class="card-body">
        <form method="get" class="row g-2 mb-3">
            <div class="col-md-6 col-lg-4"><input type="text" class="form-control" name="q" value="{{ $q }}" placeholder="Buscar por nome da empresa"></div>
            <div class="col-md-auto"><button class="btn btn-primary"><i class="bx bx-search"></i> Buscar</button></div>
        </form>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Empresa</th><th>Plano</th><th>Usuários</th><th>Envelopes</th><th>Status</th><th>Criada em</th></tr></thead>
                <tbody>
                @forelse ($tenants as $t)
                    <tr>
                        <td><a class="fw-semibold" href="{{ route('admin.tenants.show', $t) }}">{{ $t->name }}</a></td>
                        <td><span class="badge bg-secondary">{{ $t->plan }}</span></td>
                        <td>{{ $t->users_count }}</td><td>{{ $t->envelopes_count }}</td>
                        <td>@if ($t->isSuspended())<span class="badge bg-danger">suspensa</span>@else<span class="badge bg-success">ativa</span>@endif</td>
                        <td>{{ $t->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-secondary p-4">Nenhum cliente encontrado.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $tenants->links('pagination::bootstrap-5') }}</div>
    </div>
</div>
@endsection
