@extends('layouts.app')
@section('title', 'Assinatura da empresa')
@section('section', 'Configurações')
@section('content')
<div class="row">
    <div class="col-12 col-xl-8">
        @unless ($isOwner)
            <div class="alert alert-info border-0">Só o dono da conta altera estas configurações.</div>
        @endunless

        <form method="post" action="{{ route('company.signing.update') }}">
            @csrf @method('PUT')
            <fieldset @disabled(! $isOwner)>
                <div class="card radius-10">
                    <div class="card-header"><h6 class="mb-0"><i class="bx bx-badge-check me-1"></i> A empresa assina os documentos que envia?</h6></div>
                    <div class="card-body">
                        @foreach (['always' => ['Sempre', 'Todo documento sai assinado por quem o enviou (padrão).'], 'optional' => ['Opcional', 'Quem cria escolhe, documento a documento.'], 'never' => ['Nunca', 'A empresa não assina; só os signatários.']] as $value => [$label, $help])
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="sender_policy" id="policy_{{ $value }}" value="{{ $value }}" @checked(old('sender_policy', $policy) === $value)>
                                <label class="form-check-label fw-semibold" for="policy_{{ $value }}">{{ $label }}</label>
                                <div class="form-text">{{ $help }}</div>
                            </div>
                        @endforeach
                        @error('sender_policy')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="card radius-10">
                    <div class="card-header"><h6 class="mb-0"><i class="bx bx-user-check me-1"></i> Representante da empresa (assinatura pela API)</h6></div>
                    <div class="card-body">
                        <p class="text-secondary">Quando um documento é criado pela API, não há ninguém logado. A empresa assina por este representante, se uma chave de API tiver a permissão <strong>Pode assinar em nome da empresa</strong>.</p>
                        <div class="row g-2">
                            <div class="col-md-6"><label class="form-label" for="representative_name">Nome</label><input type="text" class="form-control @error('representative_name') is-invalid @enderror" id="representative_name" name="representative_name" value="{{ old('representative_name', $setting->representative_name) }}" maxlength="150">@error('representative_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-md-6"><label class="form-label" for="representative_email">E-mail</label><input type="email" class="form-control @error('representative_email') is-invalid @enderror" id="representative_email" name="representative_email" value="{{ old('representative_email', $setting->representative_email) }}" maxlength="190">@error('representative_email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-md-6"><label class="form-label" for="representative_cpf">CPF</label><input type="text" class="form-control" id="representative_cpf" name="representative_cpf" value="{{ old('representative_cpf', $setting->representative_cpf) }}" maxlength="14"></div>
                        </div>

                        <hr>
                        <h6>Termo de delegação</h6>
                        <p class="border rounded p-3 small">Autorizo que as chaves de API habilitadas pela minha empresa apliquem, em nome da empresa e por meio do(a) representante acima, a assinatura eletrônica nos documentos criados por integração. Estou ciente de que cada assinatura registra a chave usada, o endereço IP e o horário, e de que posso revogar esta autorização trocando o representante ou desligando a permissão das chaves.</p>
                        <p class="text-secondary small">Texto-base: revise com o seu advogado antes de usar em produção.</p>
                        @if ($setting->delegation_accepted_at)
                            <div class="alert alert-success border-0 py-2"><i class="bx bx-check-circle"></i> Termo aceito em {{ $setting->delegation_accepted_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} (IP {{ $setting->delegation_accepted_ip }}). Trocar o representante pede um novo aceite.</div>
                        @else
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="accept_delegation" id="accept_delegation" value="1">
                                <label class="form-check-label" for="accept_delegation">Li e aceito o termo de delegação para este representante</label>
                            </div>
                        @endif
                    </div>
                </div>

                @if ($isOwner)<button class="btn btn-primary">Salvar</button>@endif
            </fieldset>
        </form>
    </div>
</div>
@endsection
