@extends('layouts.app')
@section('title', 'Meu perfil')
@section('section', 'Perfil')

@section('content')
<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-3">Meu perfil</h5>

                <form method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data">
                    @csrf @method('PUT')

                    <div class="d-flex align-items-center gap-3 mb-4">
                        <img src="{{ $user->avatarUrl() }}" alt="Sua foto" class="rounded-circle" style="width:96px;height:96px;object-fit:cover">
                        <div class="flex-grow-1">
                            <label class="form-label" for="photo">Foto de perfil</label>
                            <input type="file" name="photo" id="photo" class="form-control @error('photo') is-invalid @enderror" accept="image/jpeg,image/png,image/webp">
                            <div class="form-text">JPG, PNG ou WebP, até 5 MB. A imagem é recortada em quadrado.</div>
                            @error('photo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="name">Nome</label>
                        <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required maxlength="120">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input type="text" class="form-control" value="{{ $user->email }}" disabled>
                    </div>

                    <button class="btn btn-primary">Salvar</button>
                </form>

                @if ($user->avatar_path)
                    <form method="post" action="{{ route('profile.photo.destroy') }}" class="mt-3" onsubmit="return confirm('Remover a foto de perfil?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger btn-sm">Remover foto</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
