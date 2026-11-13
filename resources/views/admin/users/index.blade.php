@extends('layouts.app')
@section('title', 'Usuários da plataforma')
@section('section', 'Plataforma')
@section('actions')<div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-outline-secondary drive-tap" data-bs-toggle="modal" data-bs-target="#modal-promote"><i class="bx bx-user-check"></i> Promover conta existente</button><button type="button" class="btn btn-primary drive-tap" data-bs-toggle="modal" data-bs-target="#modal-invite"><i class="bx bx-user-plus"></i> Convidar</button></div>@endsection
@section('content')
@php
    $popper = '{"strategy":"fixed"}';
@endphp
@if (session('warning'))<div class="alert alert-warning border-0">{{ session('warning') }}</div>@endif
@error('team')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror
<div class="card radius-10">
    <div class="card-body">
        <h5 class="mb-1">Equipe da plataforma</h5>
        <p class="text-secondary small mb-3"><strong>Administrador:</strong> opera clientes, saúde do sistema e selagem. <strong>Super administrador:</strong> faz tudo isso e ainda gerencia esta equipe, planos, e-mail e configurações. Acessos marcados como <strong>Fixo</strong> vêm do servidor e não mudam por aqui.</p>
        <div class="table-responsive">
            <table class="table align-middle mb-0 drive-docs drive-docs-md">
                <thead class="table-light"><tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Situação</th><th>Concedido por</th><th aria-label="Ações"></th></tr></thead>
                <tbody>
                @foreach ($members as $m)
                    @php
                        $root = $m->isRootAdmin();
                        $isSuper = $root || $m->platform_role === 'super_admin';
                        $state = $m->deactivated_at ? ['Desativada', 'bg-secondary'] : ($m->isPendingInvite() ? ['Convite pendente', 'bg-warning'] : ['Ativa', 'bg-success']);
                        $canAct = ! $root && ! $m->is($me);
                        $granted = $root ? 'Servidor' : ($m->grantedBy ? $m->grantedBy->name.($m->platform_granted_at ? ', '.$m->platform_granted_at->timezone('America/Sao_Paulo')->format('d/m/Y') : '') : '—');
                    @endphp
                    <tr>
                        <td data-label="Nome" class="fw-semibold">{{ $m->name }}@if ($m->is($me)) <span class="small text-secondary">(você)</span>@endif</td>
                        <td data-label="E-mail">{{ $m->email }}</td>
                        <td data-label="Papel">{{ $isSuper ? 'Super administrador' : 'Administrador' }} @if ($root)<span class="badge bg-secondary">Fixo</span>@endif</td>
                        <td data-label="Situação"><span class="badge {{ $state[1] }}">{{ $state[0] }}</span></td>
                        <td data-label="Concedido por">{{ $granted }}</td>
                        <td data-label="Ações" class="text-end {{ $canAct ? '' : 'd-none d-sm-table-cell' }}">
                            @if ($canAct)
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-secondary drive-tap border-0" data-bs-toggle="dropdown" data-bs-popper-config='{{ $popper }}' aria-expanded="false" aria-label="Ações de {{ $m->name }}"><i class="bx bx-dots-vertical-rounded"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <form method="post" action="{{ route('admin.users.role', $m) }}" data-confirm="Mudar {{ $m->name }} para {{ $isSuper ? 'administrador' : 'super administrador' }}?">
                                                @csrf @method('PUT')<input type="hidden" name="role" value="{{ $isSuper ? 'admin' : 'super_admin' }}">
                                                <button class="dropdown-item drive-tap justify-content-start"><i class="bx bx-transfer me-2"></i>Tornar {{ $isSuper ? 'administrador' : 'super administrador' }}</button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="post" action="{{ route('admin.users.revoke', $m) }}" data-confirm="Retirar o acesso de {{ $m->name }} à plataforma? A conta e a empresa dela continuam normais.">
                                                @csrf @method('DELETE')
                                                <button class="dropdown-item drive-tap justify-content-start text-danger"><i class="bx bx-user-minus me-2"></i>Retirar acesso</button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-promote" tabindex="-1" aria-labelledby="modal-promote-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form class="modal-content" method="post" action="{{ route('admin.users.promote') }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title" id="modal-promote-title">Promover conta existente</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <p class="small text-secondary">A pessoa já precisa ter conta no sistema. Ela passa a ver o painel da plataforma na próxima página que abrir.</p>
                <div class="mb-2"><label class="form-label" for="p-email">E-mail da conta</label><input type="email" class="form-control @error('email') is-invalid @enderror" id="p-email" name="email" maxlength="190" value="{{ old('email') }}" required>@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="p-role">Papel</label><select class="form-select" id="p-role" name="role"><option value="admin">Administrador</option><option value="super_admin">Super administrador</option></select></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary drive-tap" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary drive-tap">Promover</button></div>
        </form>
    </div>
</div>
<div class="modal fade" id="modal-invite" tabindex="-1" aria-labelledby="modal-invite-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form class="modal-content" method="post" action="{{ route('admin.users.invite') }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title" id="modal-invite-title">Convidar para a plataforma</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <p class="small text-secondary">A pessoa recebe um e-mail com um link para criar a senha (vale {{ \App\Team\TeamService::INVITE_DAYS }} dias). O acesso só começa depois que ela aceitar.</p>
                <div class="mb-2"><label class="form-label" for="i-name">Nome</label><input class="form-control" id="i-name" name="name" maxlength="150" value="{{ old('name') }}" required></div>
                <div class="mb-2"><label class="form-label" for="i-email">E-mail</label><input type="email" class="form-control" id="i-email" name="email" maxlength="190" value="{{ old('email') }}" required></div>
                <div><label class="form-label" for="i-role">Papel</label><select class="form-select" id="i-role" name="role"><option value="admin">Administrador</option><option value="super_admin">Super administrador</option></select></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary drive-tap" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary drive-tap">Enviar convite</button></div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    document.addEventListener('submit', function (e) {
        var msg = e.target.dataset.confirm;
        if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
        var btn = e.target.querySelector('button:not([data-bs-dismiss])');
        if (btn) { if (btn.dataset.sent) { e.preventDefault(); return; } btn.dataset.sent = '1'; }
    });
    @if ($errors->has('email') && old('role') !== null)
    bootstrap.Modal.getOrCreateInstance(document.getElementById('{{ old('name') !== null ? 'modal-invite' : 'modal-promote' }}')).show();
    @endif
})();
</script>
@endpush
