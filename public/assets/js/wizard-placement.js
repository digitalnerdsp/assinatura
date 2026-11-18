/* Passo 3: posicionar assinaturas no PDF. Clique ou toque abre o popup; os campos arrastam, redimensionam, duplicam e removem. */
(function () {
  'use strict';
  var form = document.getElementById('wz-form3');
  if (!form) { return; }

  var LABEL = { signature: 'Assinatura', initials: 'Visto', text: 'Texto' };
  var ICON = { signature: 'bx-pen', initials: 'bx-edit-alt', text: 'bx-text' };
  var SIZE = { signature: [0.32, 0.075], initials: [0.15, 0.075], text: [0.32, 0.04] };
  var MAX_FIELDS = 60;

  var people = JSON.parse(form.dataset.people || '[]');
  var types = JSON.parse(form.dataset.types || '[]');
  var docs = JSON.parse(form.dataset.docs || '[]');
  if (!docs.length) { docs = [{ n: 1, id: null, name: '', url: form.dataset.pdf }]; }
  var curDoc = 0;
  function curId() { return docs[curDoc].id || null; }
  var uid = 0;
  var fields = JSON.parse(form.dataset.fields || '[]').map(function (f) {
    return { id: ++uid, signer: f.signer_id, type: f.type, page: f.page, x: f.x, y: f.y, w: f.width, h: f.height, doc: f.document_id || null };
  });
  var selected = null, dirty = false, submitting = false;
  var viewer = document.getElementById('wz-viewer');
  var mq = window.matchMedia('(max-width: 900px)');
  var saveBtns = [document.getElementById('wz-save'), document.getElementById('wz-save2')];

  function nameOf(id) { for (var i = 0; i < people.length; i++) { if (people[i].id === id) { return people[i].name; } } return '—'; }
  function isSender(id) { for (var k = 0; k < people.length; k++) { if (people[k].id === id) { return !!people[k].sender; } } return false; }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text) { e.textContent = text; } return e; }

  function toast(msg) {
    var t = el('div', 'wz-toast', msg); t.setAttribute('role', 'status'); document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 2600);
  }
  function refreshButtons() { saveBtns.forEach(function (b) { b.disabled = fields.length === 0; }); }
  function touch() { dirty = true; refreshButtons(); }

  /* ---------- campos ---------- */
  function paintPage(page) {
    var lay = page.querySelector('.wz-lay'); lay.textContent = '';
    fields.filter(function (f) { return f.page === +page.dataset.p && (f.doc || null) === curId(); }).forEach(function (f) { lay.appendChild(fieldEl(f, page)); });
  }
  function paintAll() { viewer.querySelectorAll('.wz-page').forEach(paintPage); }
  function box(d, f) { d.style.left = f.x * 100 + '%'; d.style.top = f.y * 100 + '%'; d.style.width = f.w * 100 + '%'; d.style.height = f.h * 100 + '%'; }

  function fieldEl(f, page) {
    var d = el('div', 'wz-fld' + (selected === f.id ? ' is-sel' : '')); d.dataset.id = String(f.id);
    d.tabIndex = 0; d.setAttribute('role', 'group');
    d.setAttribute('aria-label', 'Campo ' + (LABEL[f.type] || f.type).toLowerCase() + ' de ' + nameOf(f.signer));
    box(d, f);
    d.appendChild(el('span', 'wz-who', nameOf(f.signer)));
    d.appendChild(el('span', f.type === 'text' ? 'wz-ftxt' : 'wz-fsg', LABEL[f.type] || f.type));
    if (f.type === 'signature') { d.appendChild(el('span', 'wz-leg', 'Assinado digitalmente por: ' + nameOf(f.signer))); }
    [['wz-c wz-cp', 'Duplicar campo', 'bx-copy'], ['wz-c wz-rm2', 'Remover campo', 'bx-x'], ['wz-c wz-rs', 'Redimensionar campo', 'bx-expand-alt']].forEach(function (c) {
      var b = el('button', c[0]); b.type = 'button'; b.setAttribute('aria-label', c[1]); var i = el('i', 'bx ' + c[2]); i.setAttribute('aria-hidden', 'true'); b.appendChild(i); d.appendChild(b);
    });
    d.addEventListener('click', function (e) { e.stopPropagation(); });
    d.addEventListener('keydown', function (e) { key(e, f, d, page); });
    d.addEventListener('pointerdown', function (e) { down(e, f, d, page); });
    return d;
  }
  function select(id) {
    selected = id;
    viewer.querySelectorAll('.wz-fld').forEach(function (x) { x.classList.toggle('is-sel', x.dataset.id === String(id)); });
  }
  function remove(f) { fields = fields.filter(function (x) { return x !== f; }); selected = null; touch(); paintAll(); }
  function duplicate(f) {
    if (fields.length >= MAX_FIELDS) { toast('No máximo ' + MAX_FIELDS + ' campos.'); return; }
    var c = { id: ++uid, signer: f.signer, type: f.type, page: f.page, doc: f.doc || null, w: f.w, h: f.h, x: Math.min(1 - f.w, f.x + 0.03), y: Math.min(1 - f.h, f.y + 0.03) };
    fields.push(c); selected = c.id; touch(); paintAll();
  }
  function down(e, f, d, page) {
    if (e.target.closest('.wz-rm2')) { e.preventDefault(); remove(f); return; }
    if (e.target.closest('.wz-cp')) { e.preventDefault(); duplicate(f); return; }
    var resize = !!e.target.closest('.wz-rs');
    select(f.id);
    var rect = page.getBoundingClientRect();
    var sx = e.clientX, sy = e.clientY, ox = f.x, oy = f.y, ow = f.w, oh = f.h, moved = false;
    try { d.setPointerCapture(e.pointerId); } catch (err) { /* ignorado */ }
    function mv(ev) {
      var dx = (ev.clientX - sx) / rect.width, dy = (ev.clientY - sy) / rect.height;
      moved = true;
      if (resize) { f.w = Math.max(0.06, Math.min(1 - f.x, ow + dx)); f.h = Math.max(0.025, Math.min(1 - f.y, oh + dy)); }
      else { f.x = Math.max(0, Math.min(1 - f.w, ox + dx)); f.y = Math.max(0, Math.min(1 - f.h, oy + dy)); }
      box(d, f);
    }
    function up() { d.removeEventListener('pointermove', mv); d.removeEventListener('pointerup', up); d.removeEventListener('pointercancel', up); if (moved) { touch(); } }
    d.addEventListener('pointermove', mv); d.addEventListener('pointerup', up); d.addEventListener('pointercancel', up);
    e.preventDefault();
  }
  function key(e, f, d) {
    var step = e.shiftKey ? 0.02 : 0.005, k = e.key;
    if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); remove(f); return; }
    var dx = k === 'ArrowRight' ? step : k === 'ArrowLeft' ? -step : 0;
    var dy = k === 'ArrowDown' ? step : k === 'ArrowUp' ? -step : 0;
    if (!dx && !dy) { return; }
    e.preventDefault();
    if (e.altKey) { f.w = Math.max(0.06, Math.min(1 - f.x, f.w + dx)); f.h = Math.max(0.025, Math.min(1 - f.y, f.h + dy)); }
    else { f.x = Math.max(0, Math.min(1 - f.w, f.x + dx)); f.y = Math.max(0, Math.min(1 - f.h, f.y + dy)); }
    box(d, f); touch();
  }

  /* ---------- popup de tipo de campo ---------- */
  function closePop() { var p = document.getElementById('wz-pop'); if (p) { p.remove(); } }
  function openPop(page, e) {
    closePop();
    if (!people.length) { toast('Adicione um signatário no passo 2.'); return; }
    if (fields.length >= MAX_FIELDS) { toast('No máximo ' + MAX_FIELDS + ' campos.'); return; }
    var rect = page.getBoundingClientRect();
    var fx = (e.clientX - rect.left) / rect.width, fy = (e.clientY - rect.top) / rect.height;
    var p = el('div', 'wz-pop' + (mq.matches ? ' is-sheet' : '')); p.id = 'wz-pop'; p.setAttribute('role', 'dialog'); p.setAttribute('aria-label', 'Novo campo');
    var x = el('button', 'wz-ib wz-popx'); x.type = 'button'; x.setAttribute('aria-label', 'Fechar'); var xi = el('i', 'bx bx-x'); x.appendChild(xi); p.appendChild(x);
    var row = el('div', 'wz-poprow'); var sel = el('select'); sel.setAttribute('aria-label', 'Signatário');
    people.forEach(function (pp) { var o = el('option', '', pp.name); o.value = pp.id; sel.appendChild(o); });
    row.appendChild(sel); p.appendChild(row);
    var grid = el('div', 'wz-types');
    types.forEach(function (t) {
      var b = el('button'); b.type = 'button'; b.dataset.t = t;
      var i = el('i', 'bx ' + (ICON[t] || 'bx-pen')); i.setAttribute('aria-hidden', 'true'); b.appendChild(i); b.appendChild(document.createTextNode(LABEL[t] || t));
      if (t === 'text') { b.title = 'Utilize para escrever sobre o documento. É aceito qualquer tipo de texto. O documento será editado.'; }
      b.addEventListener('click', function () {
        var sz = SIZE[t] || SIZE.signature;
        var f = { id: ++uid, signer: sel.value, type: t, page: +page.dataset.p, doc: curId(), w: sz[0], h: sz[1],
          x: Math.max(0, Math.min(1 - sz[0], fx - sz[0] / 2)), y: Math.max(0, Math.min(1 - sz[1], fy - sz[1] / 2)) };
        fields.push(f); selected = f.id; touch(); closePop(); paintAll();
        toast('Posicione o item selecionado no(s) seu(s) documento(s)');
      });
      grid.appendChild(b);
    });
    p.appendChild(grid);
    var note = el('p', 'wz-muted wz-small', 'Visto e Texto são preenchidos por quem recebe o link; o remetente só tem assinatura.'); note.hidden = true; p.appendChild(note);
    function limitTypes() {
      var snd = isSender(sel.value);
      grid.querySelectorAll('button').forEach(function (b) { var off = snd && b.dataset.t !== 'signature'; b.disabled = off; b.setAttribute('aria-disabled', off ? 'true' : 'false'); });
      note.hidden = !snd;
    }
    sel.addEventListener('change', limitTypes); limitTypes();
    document.body.appendChild(p);
    if (!mq.matches) {
      p.style.left = Math.min(window.innerWidth - 310, Math.max(8, e.clientX)) + 'px';
      p.style.top = Math.min(window.innerHeight - 260, Math.max(8, e.clientY)) + 'px';
    }
    x.addEventListener('click', closePop);
    sel.focus();
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePop(); } });
  document.addEventListener('click', function (e) { var p = document.getElementById('wz-pop'); if (p && !p.contains(e.target) && !e.target.closest('.wz-page')) { closePop(); } });

  /* ---------- PDF ---------- */
  function showError() {
    viewer.setAttribute('aria-busy', 'false');
    var l = document.getElementById('wz-loading'); if (l) { l.remove(); }
    document.getElementById('wz-loaderr').hidden = false;
  }
  var loadGen = 0; // cada carga tem um número: respostas de abas já trocadas são descartadas
  function buildPages(doc, gen) {
    if (gen !== loadGen) { return; }
    var l = document.getElementById('wz-loading'); if (l) { l.remove(); }
    viewer.setAttribute('aria-busy', 'false');
    var io = ('IntersectionObserver' in window) ? new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { io.unobserve(en.target); render(en.target); } });
    }, { root: viewer, rootMargin: '600px 0px' }) : null;

    function render(pageEl) {
      return doc.getPage(+pageEl.dataset.p).then(function (pg) {
        var vp = pg.getViewport({ scale: 1 });
        var scale = Math.min(3, (pageEl.clientWidth * (window.devicePixelRatio || 1)) / vp.width) || 1.4;
        var svp = pg.getViewport({ scale: scale });
        var c = document.createElement('canvas'); c.width = svp.width; c.height = svp.height;
        return pg.render({ canvasContext: c.getContext('2d'), viewport: svp }).promise.then(function () { pageEl.insertBefore(c, pageEl.firstChild); });
      });
    }
    for (var n = 1; n <= doc.numPages; n++) {
      (function (n) {
        var page = el('div', 'wz-page'); page.dataset.p = String(n);
        page.appendChild(el('div', 'wz-lay'));
        viewer.appendChild(page);
        page.addEventListener('click', function (e) { if (e.target.closest('.wz-fld')) { return; } select(null); paintAll(); openPop(page, e); });
        doc.getPage(n).then(function (pg) {
          var vp = pg.getViewport({ scale: 1 });
          page.style.aspectRatio = vp.width + ' / ' + vp.height;
          paintPage(page);
          if (io) { io.observe(page); } else { render(page); }
        });
      })(n);
    }
  }
  function loadPdf() {
    if (!window.pdfjsLib) { showError(); return; }
    document.getElementById('wz-loaderr').hidden = true;
    var gen = ++loadGen;
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    fetch(docs[curDoc].url, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('pdf'); } return r.arrayBuffer(); })
      .then(function (buf) { return window.pdfjsLib.getDocument({ data: buf, isEvalSupported: false }).promise; })
      .then(function (doc) { buildPages(doc, gen); })
      .catch(function () { if (gen === loadGen) { showError(); } });
  }

  /* ---------- salvar e continuar ---------- */
  function submit(skipped) {
    var box = document.getElementById('wz-fields-inputs'); box.textContent = '';
    if (!skipped) {
      fields.forEach(function (f, i) {
        [['signer_id', f.signer], ['document_id', f.doc || ''], ['type', f.type], ['page', f.page], ['x', f.x.toFixed(5)], ['y', f.y.toFixed(5)], ['width', f.w.toFixed(5)], ['height', f.h.toFixed(5)]].forEach(function (kv) {
          var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'fields[' + i + '][' + kv[0] + ']'; inp.value = kv[1]; box.appendChild(inp);
        });
      });
    }
    document.getElementById('wz-skipped').value = skipped ? '1' : '0';
    submitting = true; form.submit();
  }
  saveBtns.forEach(function (b) { b.addEventListener('click', function () { if (fields.length) { submit(false); } }); });

  var skipModal = document.getElementById('wz-skip-modal');
  document.getElementById('wz-skip').addEventListener('click', function () {
    if (fields.length) { submit(false); return; }
    skipModal.hidden = false; document.getElementById('wz-sk-no').focus();
  });
  document.getElementById('wz-sk-no').addEventListener('click', function () { skipModal.hidden = true; });
  document.getElementById('wz-sk-yes').addEventListener('click', function () { skipModal.hidden = true; submit(true); });
  skipModal.addEventListener('click', function (e) { if (e.target === skipModal) { skipModal.hidden = true; } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !skipModal.hidden) { skipModal.hidden = true; } });
  window.addEventListener('beforeunload', function (e) { if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; } });

  var help = document.getElementById('wz-help');
  if (help) { help.addEventListener('click', function (e) { e.preventDefault(); var t = document.getElementById('wz-helptext'); t.hidden = !t.hidden; }); }

  /* ---------- abas: um arquivo por vez ---------- */
  var tabs = document.getElementById('wz-tabs');
  function countIn(i) { return fields.filter(function (f) { return (f.doc || null) === (docs[i].id || null); }).length; }
  function paintTabs() {
    if (!tabs || docs.length < 2) { return; }
    tabs.textContent = '';
    docs.forEach(function (d, i) {
      var b = el('button', 'wz-tab' + (i === curDoc ? ' is-cur' : ''), (i + 1) + '. ' + d.name + ' (' + countIn(i) + ')');
      b.type = 'button'; b.setAttribute('role', 'tab'); b.setAttribute('aria-selected', i === curDoc ? 'true' : 'false');
      b.addEventListener('click', function () { switchTo(i); });
      tabs.appendChild(b);
    });
  }
  function switchTo(i) {
    if (i === curDoc) { return; }
    curDoc = i; selected = null; closePop();
    viewer.textContent = ''; viewer.setAttribute('aria-busy', 'true');
    viewer.appendChild(el('p', 'wz-muted wz-pad', 'Carregando o documento…')).id = 'wz-loading';
    paintTabs(); loadPdf();
  }
  var baseTouch = touch;
  touch = function () { baseTouch(); paintTabs(); };

  refreshButtons();
  paintTabs();
  loadPdf();
})();
