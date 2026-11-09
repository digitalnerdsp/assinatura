@php
    $map = [
        'draft' => 'secondary', 'sent' => 'info', 'in_progress' => 'primary', 'sealing' => 'warning', 'completed' => 'success',
        'refused' => 'danger', 'expired' => 'dark', 'cancelled' => 'dark', 'pending' => 'secondary', 'viewed' => 'info', 'signed' => 'success',
    ];
@endphp
<span class="badge bg-{{ $map[$status] ?? 'secondary' }}">{{ __('status.'.$status) }}</span>
