<!doctype html>
<html lang="pt-BR">
<head>
    @include('layouts.partials.head')
    <title>@yield('title', 'Entrar') · {{ config('app.name') }}</title>
</head>
<body>
<div class="wrapper">
    <div class="section-authentication-cover">
        <div class="row g-0">
            <div class="col-12 col-xl-7 col-xxl-8 auth-cover-left align-items-center justify-content-center d-none d-xl-flex">
                <div class="card shadow-none bg-transparent rounded-0 mb-0">
                    <div class="card-body">
                        <img src="{{ asset('assets/images/login-images/'.($cover ?? 'login-cover.svg')) }}" class="img-fluid auth-img-cover-login" width="650" alt="">
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-5 col-xxl-4 auth-cover-right align-items-center justify-content-center">
                <div class="card rounded-0 m-3 shadow-none bg-transparent mb-0">
                    <div class="card-body p-sm-5">
                        <div class="mb-3 text-center"><span class="brand-badge" style="width:56px;height:56px;font-size:30px"><i class="bx bx-pen"></i></span></div>
                        <div class="text-center mb-4">
                            <h5>{{ config('app.name') }}</h5>
                            <p class="mb-0">@yield('subtitle')</p>
                        </div>
                        @include('layouts.partials.flash')
                        @yield('content')
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@include('layouts.partials.scripts')
</body>
</html>
