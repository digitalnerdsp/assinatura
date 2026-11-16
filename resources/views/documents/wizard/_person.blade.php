{{-- Uma linha de signatário no passo 2. $i = índice; $p = ['id','name','email','phone','cpf','role']. --}}
<div class="wz-prow" data-row>
    <input type="hidden" name="people[{{ $i }}][id]" value="{{ $p['id'] ?? '' }}">
    <div class="wz-pgrid">
        <div class="wz-field">
            <label for="wz-p{{ $i }}-n">Nome do signatário</label>
            <input type="text" id="wz-p{{ $i }}-n" name="people[{{ $i }}][name]" value="{{ $p['name'] ?? '' }}" maxlength="150" autocomplete="off">
            @error("people.$i.name")<div class="wz-err" role="alert">{{ $message }}</div>@enderror
        </div>
        <div class="wz-adv-only wz-pextra">
            <div class="wz-field">
                <label for="wz-p{{ $i }}-e">E-mail do signatário <span class="wz-muted">(opcional)</span></label>
                <input type="email" id="wz-p{{ $i }}-e" name="people[{{ $i }}][email]" value="{{ $p['email'] ?? '' }}" maxlength="190" autocomplete="off">
                @error("people.$i.email")<div class="wz-err" role="alert">{{ $message }}</div>@enderror
            </div>
            <div class="wz-field">
                <label for="wz-p{{ $i }}-r">Função do signatário</label>
                <select id="wz-p{{ $i }}-r" name="people[{{ $i }}][role]">
                    <option value="signer" @selected(($p['role'] ?? 'signer') === 'signer')>Assinar</option>
                    <option value="witness" @selected(($p['role'] ?? '') === 'witness')>Assinar como testemunha</option>
                </select>
            </div>
            <div class="wz-field">
                <label for="wz-p{{ $i }}-t">Celular <span class="wz-muted">(opcional)</span></label>
                <input type="tel" id="wz-p{{ $i }}-t" name="people[{{ $i }}][phone]" value="{{ $p['phone'] ?? '' }}" maxlength="30" placeholder="(11) 99999-8888">
            </div>
            <div class="wz-field">
                <label for="wz-p{{ $i }}-c">CPF <span class="wz-muted">(opcional)</span></label>
                <input type="text" id="wz-p{{ $i }}-c" name="people[{{ $i }}][cpf]" value="{{ $p['cpf'] ?? '' }}" maxlength="14">
            </div>
        </div>
        <button type="button" class="wz-ib wz-rm" data-remove aria-label="Remover signatário"><i class="bx bx-x" aria-hidden="true"></i></button>
    </div>
</div>
