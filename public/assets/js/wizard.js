/* Assistente de criação de documento: posição do painel no celular e passo 1 (arquivo e pasta). */
(function () {
  window.wzNotify = window.wzNotify || function (m) { if (window.ajAlert) { window.ajAlert(m, { tone: 'error' }); } };
  'use strict';
  var panel = document.getElementById('wz-panel');
  var card = document.getElementById('wz-card');
  if (!panel || !card) { return; }
  var mq = window.matchMedia('(max-width: 900px)');

  /* No celular o painel do passo atual fica logo abaixo do passo na lista vertical. */
  function place() {
    var slot = document.querySelector('.wz-stepper-v .is-cur .wz-slot');
    if (mq.matches && slot) { slot.appendChild(panel); } else { card.appendChild(panel); }
  }
  place();
  if (mq.addEventListener) { mq.addEventListener('change', place); }

  var form = document.getElementById('wz-form');
  if (!form) { return; }

  var help = document.getElementById('wz-help');
  if (help) {
    help.addEventListener('click', function (e) {
      e.preventDefault();
      var t = document.getElementById('wz-helptext'); t.hidden = !t.hidden;
    });
  }

  /* Arquivos: a lista acumula (cada escolha soma às anteriores) e cada arquivo tem o seu botão de remover. */
  var file = document.getElementById('wz-file');
  var drop = document.getElementById('wz-drop');
  var chosen = document.getElementById('wz-chosen');
  var next = document.getElementById('wz-next');
  var title = document.getElementById('wz-title');
  var hasSaved = form.dataset.hasFile === '1';
  var MAX = parseInt(form.dataset.max || '10', 10);
  var ROOM = parseInt(form.dataset.room || String(MAX), 10);
  var addInput = document.getElementById('wz-add');
  var notify = function (m) { (window.wzNotify || window.alert)(m); };

  function ext(f) { return /\.(pdf|docx)$/i.test(f.name); }
  function sizeText(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

  /* Guarda os arquivos escolhidos e devolve a lista ao <input> (que é o que o formulário envia). */
  function picker(input, limit, render) {
    var list = [];
    function push() {
      try { var dt = new DataTransfer(); list.forEach(function (f) { dt.items.add(f); }); input.files = dt.files; } catch (e) { /* navegador antigo: fica só a última escolha */ }
      render(list);
    }
    return {
      list: function () { return list; },
      add: function (files) {
        var bad = false, full = false;
        Array.prototype.slice.call(files).forEach(function (f) {
          if (!ext(f)) { bad = true; return; }
          if (list.some(function (x) { return x.name === f.name && x.size === f.size; })) { return; }
          if (list.length >= limit()) { full = true; return; }
          list.push(f);
        });
        push();
        if (bad) { notify('Envie só arquivos PDF ou DOCX.'); }
        if (full) { notify('Um envio aceita no máximo ' + limit() + ' arquivos.'); }
      },
      removeAt: function (i) { list.splice(i, 1); push(); },
      clear: function () { list = []; push(); }
    };
  }

  function row(n, f, onRemove) {
    var r = document.createElement('div'); r.className = 'wz-frow';
    var num = document.createElement('strong'); num.textContent = String(n);
    var nm = document.createElement('span'); nm.className = 'wz-fname'; nm.textContent = f.name;
    var sz = document.createElement('span'); sz.className = 'wz-muted wz-small'; sz.textContent = sizeText(f.size);
    var x = document.createElement('button'); x.type = 'button'; x.className = 'wz-ib'; x.setAttribute('aria-label', 'Remover ' + f.name);
    var i = document.createElement('i'); i.className = 'bx bx-x'; i.setAttribute('aria-hidden', 'true'); x.appendChild(i);
    x.addEventListener('click', onRemove);
    r.appendChild(num); r.appendChild(nm); r.appendChild(sz); r.appendChild(x);
    return r;
  }

  function sync() {
    var has = hasSaved || (file.files && file.files.length > 0);
    next.disabled = !has;
  }

  if (!hasSaved) {
    /* Novo documento: o próprio campo principal acumula os arquivos. */
    var listEl = document.getElementById('wz-list');
    var countEl = document.getElementById('wz-count');
    var again = document.getElementById('wz-pick2');
    var main = picker(file, function () { return MAX; }, function (files) {
      listEl.textContent = '';
      files.forEach(function (f, i) { listEl.appendChild(row(i + 1, f, function () { main.removeAt(i); })); });
      if (files.length && !title.value) { title.value = files[0].name.replace(/\.(pdf|docx)$/i, ''); }
      if (!files.length) { title.value = ''; }
      drop.hidden = files.length > 0; chosen.hidden = files.length === 0;
      countEl.textContent = files.length + ' de ' + MAX + ' arquivos';
      again.hidden = files.length >= MAX;
      sync();
    });
    file.addEventListener('change', function () { main.add(file.files || []); });
    drop.addEventListener('drop', function (e) { e.preventDefault(); if (e.dataTransfer && e.dataTransfer.files) { main.add(e.dataTransfer.files); } });
  } else {
    /* Rascunho: o campo principal só troca o primeiro arquivo; os extras acumulam no campo "Adicionar". */
    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      if (f && !ext(f)) { file.value = ''; notify('Envie só arquivos PDF ou DOCX.'); }
      sync();
    });
    if (addInput) {
      var addEl = document.getElementById('wz-add-names');
      var extra = picker(addInput, function () { return ROOM; }, function (files) {
        addEl.textContent = '';
        files.forEach(function (f, i) { addEl.appendChild(row('+', f, function () { extra.removeAt(i); })); });
      });
      addInput.addEventListener('change', function () { extra.add(addInput.files || []); });
      document.getElementById('wz-add-btn').addEventListener('click', function (e) { e.preventDefault(); addInput.click(); });
    }
  }

  function pick(e) { if (e) { e.preventDefault(); } file.click(); }
  document.getElementById('wz-pick').addEventListener('click', pick);
  document.getElementById('wz-pick2').addEventListener('click', pick);
  ['dragover', 'dragenter'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-over'); }); });
  sync();

  /* Pasta: escolher uma existente ou criar uma nova sem sair do passo */
  var modal = document.getElementById('wz-folder-modal');
  var list = document.getElementById('wz-folder-list');
  var folderId = document.getElementById('wz-folder-id');
  var folderName = document.getElementById('wz-folder-name');
  var newName = document.getElementById('wz-new-folder');
  var err = document.getElementById('wz-fm-err');
  var picked = folderId.value;
  var pickedName = folderName.textContent;

  function mark() {
    list.querySelectorAll('.wz-opt').forEach(function (o) { o.setAttribute('aria-checked', o.dataset.id === picked ? 'true' : 'false'); });
  }
  function open() { picked = folderId.value; err.hidden = true; newName.value = ''; mark(); modal.hidden = false; list.querySelector('.wz-opt[aria-checked=true]').focus(); }
  function close() { modal.hidden = true; document.getElementById('wz-change-folder').focus(); }
  document.getElementById('wz-change-folder').addEventListener('click', open);
  document.getElementById('wz-fm-cancel').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { close(); } });
  list.addEventListener('click', function (e) {
    var o = e.target.closest('.wz-opt'); if (!o) { return; }
    picked = o.dataset.id; pickedName = o.textContent.replace(/ ✓$/, '').trim(); newName.value = ''; mark();
  });
  document.getElementById('wz-fm-ok').addEventListener('click', function () {
    var name = newName.value.trim();
    function done() { folderId.value = picked; folderName.textContent = pickedName; close(); }
    if (!name) { done(); return; }
    var token = document.querySelector('meta[name="csrf-token"]');
    fetch(form.dataset.quickFolder, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' },
      credentials: 'same-origin',
      body: JSON.stringify({ name: name, parent_id: picked || null })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (res) {
      if (!res.ok) { err.textContent = res.j.message || 'Não foi possível criar a pasta.'; err.hidden = false; return; }
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'wz-opt'; b.dataset.id = res.j.id; b.setAttribute('role', 'radio'); b.textContent = res.j.path;
      list.appendChild(b); picked = res.j.id; pickedName = res.j.path; done();
    }).catch(function () { err.textContent = 'Sem conexão. Tente de novo.'; err.hidden = false; });
  });
})();
