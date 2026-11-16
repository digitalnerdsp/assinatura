{{-- Uma linha de observador no passo 2. $i = índice (único entre signatários e observadores); $p = ['id','name','email']. --}}
<div class="wz-orow" data-orow>
    <input type="hidden" name="people[{{ $i }}][id]" value="{{ $p['id'] ?? '' }}">
    <input type="hidden" name="people[{{ $i }}][role]" value="observer">
    <div class="wz-pgrid">
        <div class="wz-field">
            <label for="wz-o{{ $i }}-n">Nome do observador</label>
            <input type="text" id="wz-o{{ $i }}-n" name="people[{{ $i }}][name]" value="{{ $p['name'] ?? '' }}" maxlength="150" autocomplete="off">
            @error("people.$i.name")<div class="wz-err" role="alert">{{ $message }}</div>@enderror
        </div>
        <div class="wz-field">
            <label for="wz-o{{ $i }}-e">E-mail do observador</label>
            <input type="email" id="wz-o{{ $i }}-e" name="people[{{ $i }}][email]" value="{{ $p['email'] ?? '' }}" maxlength="190" autocomplete="off">
            @error("people.$i.email")<div class="wz-err" role="alert">{{ $message }}</div>@enderror
        </div>
        <button type="button" class="wz-ib wz-orm" data-oremove aria-label="Remover observador"><i class="bx bx-x" aria-hidden="true"></i></button>
    </div>
</div>
