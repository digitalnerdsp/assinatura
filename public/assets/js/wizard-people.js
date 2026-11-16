/* Passo 2: linhas de signatário, autenticação avançada, ordem e atalho de contatos. */
(function () {
  'use strict';
  var form = document.getElementById('wz-form2');
  if (!form) { return; }
  var wz = document.getElementById('wz');
  var holder = document.getElementById('wz-people');
  var tpl = document.getElementById('wz-person-tpl');
  var adv = document.getElementById('wz-adv');
  var me = document.getElementById('wz-me');
  var orderBox = document.getElementById('wz-orderbox');

  function rows() { return holder.querySelectorAll('[data-row]'); }
  function setAdv() { wz.classList.toggle('is-adv', adv.checked); }
  function sync() {
    var count = rows().length;
    var sender = me ? me.checked : !!form.querySelector('input[name="sender_signs"][value="1"]');
    orderBox.hidden = (count + (sender ? 1 : 0)) < 2;
    rows().forEach(function (r) { r.querySelector('[data-remove]').hidden = count < 2; });
  }
  adv.addEventListener('change', setAdv);
  if (me) { me.addEventListener('change', sync); }
  setAdv();

  function add(values) {
    var i = parseInt(holder.dataset.next, 10); holder.dataset.next = String(i + 1);
    var html = tpl.innerHTML.replace(/__I__/g, String(i));
    var d = document.createElement('div'); d.innerHTML = html.trim();
    var row = d.firstElementChild; holder.appendChild(row);
    if (values) {
      Object.keys(values).forEach(function (k) {
        var el = row.querySelector('[name="people[' + i + '][' + k + ']"]');
        if (el) { el.value = values[k] || ''; }
      });
    }
    sync();
    return row;
  }
  document.getElementById('wz-add').addEventListener('click', function () {
    var row = add(); row.querySelector('input[type=text]').focus();
  });
  holder.addEventListener('click', function (e) {
    var b = e.target.closest('[data-remove]'); if (!b) { return; }
    b.closest('[data-row]').remove(); sync();
  });
  sync();

  /* Observadores: recebem a cópia final por e-mail; linhas separadas das dos signatários */
  var obsBox = document.getElementById('wz-obsbox');
  var obsList = document.getElementById('wz-observers');
  var obsTpl = document.getElementById('wz-observer-tpl');
  var obsToggle = document.getElementById('wz-obs-toggle');
  function addObserver(values) {
    var i = parseInt(holder.dataset.next, 10); holder.dataset.next = String(i + 1);
    var d = document.createElement('div'); d.innerHTML = obsTpl.innerHTML.replace(/__I__/g, String(i)).trim();
    var row = d.firstElementChild; obsList.appendChild(row);
    if (values) {
      Object.keys(values).forEach(function (k) {
        var el = row.querySelector('[name="people[' + i + '][' + k + ']"]');
        if (el) { el.value = values[k] || ''; }
      });
    }
    return row;
  }
  function showObservers(show) {
    obsBox.hidden = !show;
    obsToggle.setAttribute('aria-expanded', show ? 'true' : 'false');
    if (show && !obsList.querySelector('[data-orow]')) { addObserver().querySelector('input[type=text]').focus(); }
  }
  obsToggle.addEventListener('click', function () { showObservers(obsBox.hidden); });
  document.getElementById('wz-add-obs').addEventListener('click', function () { addObserver().querySelector('input[type=text]').focus(); });
  obsList.addEventListener('click', function (e) {
    var b = e.target.closest('[data-oremove]'); if (!b) { return; }
    b.closest('[data-orow]').remove();
  });
  /* Contatos */
  var modal = document.getElementById('wz-contacts-modal');
  var q = document.getElementById('wz-ct-q');
  var list = document.getElementById('wz-ct-list');
  var timer = null;
  function hint(text) {
    list.textContent = '';
    var p = document.createElement('p'); p.className = 'wz-muted wz-pad'; p.textContent = text; list.appendChild(p);
  }
  function open() { modal.hidden = false; q.value = ''; hint('Digite para buscar na sua agenda.'); q.focus(); }
  function close() { modal.hidden = true; document.getElementById('wz-contacts').focus(); }
  document.getElementById('wz-contacts').addEventListener('click', open);
  document.getElementById('wz-ct-close').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { close(); } });

  function pickContact(c) {
    var first = rows()[0];
    var empty = rows().length === 1 && !first.querySelector('[name$="[name]"]').value.trim();
    if (empty) {
      first.querySelector('[name$="[name]"]').value = c.name;
      first.querySelector('[name$="[email]"]').value = c.email || '';
      first.querySelector('[name$="[phone]"]').value = c.phone || '';
    } else {
      add({ name: c.name, email: c.email, phone: c.phone });
    }
    if (c.email || c.phone) { adv.checked = true; setAdv(); }
    close();
  }
  function show(items) {
    if (!items.length) { hint('Nenhum contato encontrado.'); return; }
    list.textContent = '';
    items.forEach(function (c) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'wz-hit';
      var s = document.createElement('span');
      var n = document.createElement('strong'); n.textContent = c.name;
      var m = document.createElement('small'); m.textContent = c.email || '';
      s.appendChild(n); s.appendChild(m); b.appendChild(s);
      b.addEventListener('click', function () { pickContact(c); });
      list.appendChild(b);
    });
  }
  q.addEventListener('input', function () {
    clearTimeout(timer);
    var v = q.value.trim();
    if (v.length < 2) { hint('Digite para buscar na sua agenda.'); return; }
    timer = setTimeout(function () {
      fetch(form.dataset.suggestUrl + '?q=' + encodeURIComponent(v), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); }).then(show).catch(function () { show([]); });
    }, 250);
  });
})();
