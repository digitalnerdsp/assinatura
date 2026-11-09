<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-shield-quarter me-1"></i> Verificação de identidade</h6></div>
    <div class="card-body">
        <p class="text-secondary">Sem nenhuma opção marcada vale só o link (assinatura simples). Com qualquer verificação, a assinatura é registrada como avançada.</p>
        @foreach ([
            'require_otp' => ['bx-envelope', 'Código de confirmação por e-mail'],
            'require_selfie' => ['bx-camera', 'Selfie (câmera)'],
            'require_document' => ['bx-id-card', 'Foto do documento (RG/CNH)'],
            'require_geolocation' => ['bx-map-pin', 'Localização (GPS)'],
        ] as $name => [$icon, $label])
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="{{ $name }}" name="{{ $name }}" value="1" @checked(old($name))>
                <label class="form-check-label" for="{{ $name }}"><i class="bx {{ $icon }} me-1"></i>{{ $label }}</label>
            </div>
        @endforeach
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-bell me-1"></i> Aviso, prazo e organização</h6></div>
    <div class="card-body">
        <label class="form-label">Enviar o convite por</label>
        @php($sel = old('channels', ['email']))
        <div class="mb-3">
            @foreach (['email' => ['bx-envelope', 'E-mail'], 'whatsapp' => ['bxl-whatsapp', 'WhatsApp'], 'sms' => ['bx-message-detail', 'SMS']] as $key => [$icon, $label])
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="ch_{{ $key }}" name="channels[]" value="{{ $key }}" @checked(in_array($key, $sel))>
                    <label class="form-check-label" for="ch_{{ $key }}"><i class="bx {{ $icon }}"></i> {{ $label }}</label>
                </div>
            @endforeach
            <div class="form-text">WhatsApp e SMS usam o telefone do signatário e dependem do provedor configurado pelo administrador da plataforma.</div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-sm-6">
                <label for="validity_days" class="form-label">Prazo para assinar (dias)</label>
                <input id="validity_days" type="number" min="1" max="365" class="form-control" name="validity_days" value="{{ old('validity_days', config('signing.default_validity_days')) }}">
            </div>
            <div class="col-sm-6">
                <label for="reminder_every_days" class="form-label">Lembrete automático</label>
                <select id="reminder_every_days" class="form-select" name="reminder_every_days">
                    @foreach (['off' => 'Não lembrar', '1' => 'A cada 1 dia', '2' => 'A cada 2 dias', '3' => 'A cada 3 dias', '5' => 'A cada 5 dias', '7' => 'A cada 7 dias', '10' => 'A cada 10 dias', '14' => 'A cada 14 dias'] as $v => $l)
                        <option value="{{ $v }}" @selected(old('reminder_every_days', (string) config('signing.default_reminder_days')) === (string) $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label for="folder_id" class="form-label">Pasta</label>
            <select id="folder_id" class="form-select" name="folder_id">
                <option value="">Sem pasta</option>
                @foreach ($folders as $f)<option value="{{ $f->id }}" @selected(old('folder_id') === $f->id)>{{ $f->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Etiquetas</label><br>
            @forelse ($tags as $t)
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="tag_{{ $t->id }}" name="tags[]" value="{{ $t->id }}" @checked(in_array($t->id, old('tags', [])))>
                    <label class="form-check-label" for="tag_{{ $t->id }}"><span class="badge bg-{{ $t->color }}">{{ $t->name }}</span></label>
                </div>
            @empty
                <span class="text-secondary small">Nenhuma etiqueta ainda. <a href="{{ route('organization.index') }}">Criar</a></span>
            @endforelse
        </div>
    </div>
</div>
