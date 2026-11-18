/* Assistente de criação de documento: posição do painel no celular e passo 1 (arquivo e pasta). */
(function () {
  window.wzNotify = window.wzNotify || function (m) { window.alert(m); };
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

  /* Arquivo */
  var file = document.getElementById('wz-file');
  var drop = document.getElementById('wz-drop');
  var chosen = document.getElementById('wz-chosen');
  var next = document.getElementById('wz-next');
  var fname = document.getElementById('wz-fname');
  var more = document.getElementById('wz-more');
  var title = document.getElementById('wz-title');
  var remove = document.getElementById('wz-remove');
  var hasSaved = form.dataset.hasFile === '1';
  var addInput = document.getElementById('wz-add');
  var addNames = document.getElementById('wz-add-names');

  function sync() {
    var has = hasSaved || (file.files && file.files.length > 0);
    next.disabled = !has;
  }
  function pick(e) { if (e) { e.preventDefault(); } file.click(); }
  document.getElementById('wz-pick').addEventListener('click', pick);
  document.getElementById('wz-pick2').addEventListener('click', pick);

  function show(files) {
    if (fname) { fname.textContent = files[0].name; }
    if (more) {
      more.textContent = '';
      for (var i = 1; i < files.length; i++) {
        var row = document.createElement('div'); row.className = 'wz-frow';
        var n = document.createElement('strong'); n.textContent = String(i + 1);
        var nm = document.createElement('span'); nm.className = 'wz-fname'; nm.textContent = files[i].name;
        row.appendChild(n); row.appendChild(nm); more.appendChild(row);
      }
    }
    if (!title.value) { title.value = files[0].name.replace(/\.(pdf|docx)$/i, ''); }
    drop.hidden = true; chosen.hidden = false;
    sync();
  }
  function valid(files) {
    for (var i = 0; i < files.length; i++) { if (!/\.(pdf|docx)$/i.test(files[i].name)) { return false; } }
    return true;
  }
  file.addEventListener('change', function () {
    var files = file.files ? Array.prototype.slice.call(file.files) : [];
    if (!files.length) { sync(); return; }
    if (!valid(files)) { file.value = ''; window.wzNotify('Envie só arquivos PDF ou DOCX.'); sync(); return; }
    if (hasSaved) { if (fname) { fname.textContent = files[0].name; } sync(); return; }
    show(files);
  });
  if (remove) {
    remove.addEventListener('click', function () {
      file.value = ''; title.value = ''; if (more) { more.textContent = ''; } drop.hidden = false; chosen.hidden = true; sync();
    });
  }
  if (addInput) {
    document.getElementById('wz-add-btn').addEventListener('click', function (e) { e.preventDefault(); addInput.click(); });
    addInput.addEventListener('change', function () {
      var files = Array.prototype.slice.call(addInput.files || []);
      if (!valid(files)) { addInput.value = ''; addNames.textContent = ''; window.wzNotify('Envie só arquivos PDF ou DOCX.'); return; }
      addNames.textContent = files.length ? 'Serão acrescentados: ' + files.map(function (f) { return f.name; }).join(', ') : '';
    });
  }
  ['dragover', 'dragenter'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-over'); }); });
  drop.addEventListener('drop', function (e) {
    e.preventDefault();
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { file.files = e.dataTransfer.files; file.dispatchEvent(new Event('change')); }
  });
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
