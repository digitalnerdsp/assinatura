@if (! empty($brand['active']))
<p style="margin:0 0 16px">@if (! empty($brand['logo_url']))<img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}" style="max-height:40px;max-width:180px;vertical-align:middle"> @endif<strong style="font-size:16px;vertical-align:middle">{{ $brand['name'] }}</strong></p>
@endif
