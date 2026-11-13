@extends('layouts.app')
@section('title', 'Usuários')
@section('section', 'Configurações')
@section('actions')@if ($isOwner)<button type="button" class="btn btn-primary drive-tap" data-bs-toggle="modal" data-bs-target="#modal-invite"><i class="bx bx-user-plus"></i> Convidar usuário</button>@endif @endsection
@section('content')
@php($popper = '{"strategy":"fixed"}')
@if (session('warning'))<div class="alert alert-warning border-0">{{ session('warning') }}</div>@endif
@include('partials.invite-link')
@error('team')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror
<div class="card radius-10">
    <div class="card-body">
        <h5 class="mb-1">Usuários da empresa</h5>
        <p class="text-secondary small">{{ $isOwner ? 'Convide pessoas da sua equipe para criar e acompanhar documentos. Quem for desativado perde o acesso na hora.' : 'Só o responsável da empresa pode convidar ou desativar usuários.' }}</p>
        <div class="table-responsive">
            <table class="table align-middle mb-0 drive-docs">
                <thead class="table-light"><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Situação</th>@if ($isOwner)<th aria-label="Ações"></th>@endif</tr></thead>
                <tbody>
                @foreach ($members as $m)
                    @php($state = $m->deactivated_at ? ['Desativado', 'bg-secondary'] : ($m->isPendingInvite() ? ['Convite pendente', 'bg-warning'] : ['Ativo', 'bg-success']))
                    <tr>
                        <td data-label="Nome" class="fw-semibold">{{ $m->name }}@if ($m->is($me)) <span class="small text-secondary">(você)</span>@endif</td>
                        <td data-label="E-mail">{{ $m->email }}</td>
                        <td data-label="Perfil">{{ $m->role === 'owner' ? 'Responsável' : 'Membro' }}</td>
                        <td data-label="Situação"><span class="badge {{ $state[1] }}">{{ $state[0] }}</span></td>
                        @if ($isOwner)
                            @php($canAct = ! $m->is($me) && $m->role !== 'owner')
                            <td data-label="Ações" class="text-end {{ $canAct ? '' : 'd-none d-sm-table-cell' }}">
                                @if ($canAct)
                                    <div class="dropdown">
                                        <button type="button" class="btn btn-sm btn-outline-secondary drive-tap border-0" data-bs-toggle="dropdown" data-bs-popper-config='{{ $popper }}' aria-expanded="false" aria-label="Ações do usuário {{ $m->name }}"><i class="bx bx-dots-vertical-rounded"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            @if ($m->isPendingInvite())
                                                <li><form method="post" action="{{ route('team.resend', $m) }}">@csrf<button class="dropdown-item drive-tap justify-content-start"><i class="bx bx-envelope me-2"></i>Reenviar convite</button></form></li>
                                            @endif
                                            @if ($m->deactivated_at)
                                                <li><form method="post" action="{{ route('team.reactivate', $m) }}">@csrf<button class="dropdown-item drive-tap justify-content-start"><i class="bx bx-check-circle me-2"></i>Reativar</button></form></li>
                                            @else
                                                <li><form method="post" action="{{ route('team.deactivate', $m) }}" data-confirm="Desativar o acesso de {{ $m->name }}? A pessoa sai do sistema na hora.">@csrf<button class="dropdown-item drive-tap justify-content-start"><i class="bx bx-block me-2"></i>Desativar</button></form></li>
                                            @endif
                                            <li><form method="post" action="{{ route('team.destroy', $m) }}" data-confirm="Remover {{ $m->name }}? Os documentos criados por essa pessoa continuam na empresa.">@csrf @method('DELETE')<button class="dropdown-item drive-tap justify-content-start text-danger"><i class="bx bx-trash me-2"></i>Remover</button></form></li>
                                        </ul>
                                    </div>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@if ($isOwner)
<div class="modal fade" id="modal-invite" tabindex="-1" aria-labelledby="modal-invite-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form class="modal-content" method="post" action="{{ route('team.invite') }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title" id="modal-invite-title">Convidar usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <p class="small text-secondary">A pessoa recebe um e-mail com um link para criar a senha. O link vale por {{ \App\Team\TeamService::INVITE_DAYS }} dias.</p>
                <div class="mb-2"><label class="form-label" for="t-name">Nome</label><input class="form-control" id="t-name" name="name" maxlength="150" value="{{ old('name') }}" required></div>
                <div><label class="form-label" for="t-email">E-mail</label><input type="email" class="form-control @error('email') is-invalid @enderror" id="t-email" name="email" maxlength="190" value="{{ old('email') }}" required>@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary drive-tap" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary drive-tap">Enviar convite</button></div>
        </form>
    </div>
</div>
@endif
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
    var modal = document.getElementById('modal-invite');
    if (modal) {
        modal.addEventListener('shown.bs.modal', function () { modal.querySelector('[name=name]').focus(); });
        @if ($errors->has('email') || $errors->has('name')) bootstrap.Modal.getOrCreateInstance(modal).show(); @endif
    }
})();
</script>
@endpush
