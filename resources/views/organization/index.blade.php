@extends('layouts.app')
@section('title', 'Pastas e etiquetas')
@section('section', 'Assinaturas')
@section('content')
<div class="row">
    <div class="col-12 col-lg-6">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-folder me-1"></i> Pastas</h6></div>
            <div class="card-body">
                <form method="post" action="{{ route('folders.store') }}" class="row g-2 mb-3">
                    @csrf
                    <div class="col"><input type="text" class="form-control" name="name" placeholder="Nome da pasta" required maxlength="120"></div>
                    <div class="col-auto"><button class="btn btn-primary"><i class="bx bx-plus"></i> Criar</button></div>
                </form>
                <ul class="list-group">
                    @forelse ($folders as $f)
                        <li class="list-group-item d-flex align-items-center">
                            <i class="bx bx-folder me-2"></i>
                            <a href="{{ route('envelopes.index', ['folder' => $f->id]) }}">{{ $f->name }}</a>
                            <span class="badge bg-light text-dark border ms-2">{{ $f->envelopes_count }}</span>
                            <form class="ms-auto" method="post" action="{{ route('folders.destroy', $f) }}" onsubmit="return confirm('Remover a pasta? Os envelopes dela ficam sem pasta.')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bx bx-trash"></i></button></form>
                        </li>
                    @empty
                        <li class="list-group-item text-secondary">Nenhuma pasta ainda.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-purchase-tag me-1"></i> Etiquetas</h6></div>
            <div class="card-body">
                <form method="post" action="{{ route('tags.store') }}" class="row g-2 mb-3">
                    @csrf
                    <div class="col"><input type="text" class="form-control" name="name" placeholder="Nome da etiqueta" required maxlength="60"></div>
                    <div class="col-auto"><select class="form-select" name="color">@foreach ($colors as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></div>
                    <div class="col-auto"><button class="btn btn-primary"><i class="bx bx-plus"></i> Criar</button></div>
                </form>
                <ul class="list-group">
                    @forelse ($tags as $t)
                        <li class="list-group-item d-flex align-items-center">
                            <a href="{{ route('envelopes.index', ['tag' => $t->id]) }}"><span class="badge bg-{{ $t->color }}">{{ $t->name }}</span></a>
                            <span class="badge bg-light text-dark border ms-2">{{ $t->envelopes_count }}</span>
                            <form class="ms-auto" method="post" action="{{ route('tags.destroy', $t) }}" onsubmit="return confirm('Remover a etiqueta?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bx bx-trash"></i></button></form>
                        </li>
                    @empty
                        <li class="list-group-item text-secondary">Nenhuma etiqueta ainda.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
