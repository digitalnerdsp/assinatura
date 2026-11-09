@if (session('status'))
    <div class="alert alert-success border-0 d-flex align-items-center gap-2 alert-dismissible fade show" role="alert">
        <i class="bx bx-check-circle fs-4"></i><div>{{ session('status') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
    </div>
@endif
@if (session('warning'))
    <div class="alert alert-warning border-0 d-flex align-items-center gap-2" role="alert"><i class="bx bx-error fs-4"></i><div>{{ session('warning') }}</div></div>
@endif
@if ($errors->any())
    <div class="alert alert-danger border-0 d-flex gap-2" role="alert">
        <i class="bx bx-error-circle fs-4"></i>
        <div>@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    </div>
@endif
