@php($__b = \App\Branding\BrandService::forRequest(request()))
@if ($__b['logo_url'])<img src="{{ $__b['logo_url'] }}" alt="{{ $__b['name'] }}" style="max-height:34px;max-width:140px;object-fit:contain">@else<img src="{{ asset('assets/images/logo-assinaja-mark.svg') }}" alt="" width="40" height="32" style="height:32px;width:auto">@endif
