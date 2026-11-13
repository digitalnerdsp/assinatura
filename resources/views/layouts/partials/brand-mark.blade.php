@php($__b = \App\Branding\BrandService::forRequest(request()))
@if ($__b['logo_url'])<img src="{{ $__b['logo_url'] }}" alt="{{ $__b['name'] }}" style="max-height:34px;max-width:140px;object-fit:contain">@else<span class="brand-badge"><i class="bx bx-pen"></i></span>@endif
