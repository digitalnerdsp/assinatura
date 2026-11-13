/*
 * Sugestões de contatos no formulário de criar documento: ao digitar o nome ou o e-mail de um signatário ou
 * testemunha, mostra até 8 contatos da empresa; tocar num deles preenche nome, e-mail e telefone da mesma linha.
 */
(function () {
    'use strict';

    var form = document.querySelector('form[data-suggest-url]');
    if (!form) return;
    var url = form.getAttribute('data-suggest-url');
    var timer = null, seq = 0, box = null, current = null;

    function isField(el) {
        return el && el.tagName === 'INPUT' && /^(signers|witnesses)\[\d+\]\[(name|email)\]$/.test(el.name || '');
    }
    function rowOf(el) { return el.closest('.signer-row, .witness-row'); }
    function field(row, key) { return row.querySelector('input[name$="[' + key + ']"]'); }

    function close() {
        if (box) { box.remove(); box = null; }
        current = null;
    }

    function choose(row, c) {
        var name = field(row, 'name'), email = field(row, 'email'), phone = field(row, 'phone');
        if (name) name.value = c.name || '';
        if (email) email.value = c.email || '';
        if (phone && c.phone) phone.value = c.phone;
        [name, email, phone].forEach(function (i) { if (i) i.dispatchEvent(new Event('input', { bubbles: true })); });
        close();
    }

    function render(input, items) {
        close();
        if (!items.length) return;
        current = input;
        box = document.createElement('div');
        box.className = 'list-group shadow contact-suggest';
        box.setAttribute('role', 'listbox');
        items.forEach(function (c) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'list-group-item list-group-item-action drive-tap justify-content-start text-start';
            b.setAttribute('role', 'option');
            var strong = document.createElement('strong');
            strong.textContent = c.name;
            var small = document.createElement('small');
            small.className = 'ms-2 text-secondary';
            small.textContent = c.email + (c.phone ? ' · ' + c.phone : '');
            b.appendChild(strong);
            b.appendChild(small);
            // mousedown/touch antes do blur do campo, para o toque não se perder
            b.addEventListener('mousedown', function (e) { e.preventDefault(); choose(rowOf(input), c); });
            b.addEventListener('click', function (e) { e.preventDefault(); choose(rowOf(input), c); });
            box.appendChild(b);
        });
        var wrap = input.parentElement;
        wrap.style.position = 'relative';
        wrap.appendChild(box);
    }

    form.addEventListener('input', function (e) {
        var input = e.target;
        if (!isField(input) || e.isTrusted === false) return;
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { close(); return; }
        timer = setTimeout(function () {
            var mine = ++seq;
            fetch(url + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (items) { if (mine === seq && document.activeElement === input) render(input, items); })
                .catch(function () { /* sem sugestões: o formulário continua funcionando */ });
        }, 200);
    });
    form.addEventListener('focusout', function (e) { if (e.target === current) setTimeout(close, 150); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
