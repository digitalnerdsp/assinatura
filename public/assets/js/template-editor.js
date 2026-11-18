/* Editor de lacunas do modelo: clique no documento para marcar onde o texto vai; arrastar, redimensionar, duplicar e remover. */
(function () {
  'use strict';
  var viewer = document.getElementById('tpl-viewer');
  var input = document.getElementById('tpl-fields-input');
  var form = document.getElementById('tpl-form');
  if (!viewer || !input || !form) { return; }

  var MAX_FIELDS = 40;
  var MAX_LABEL = 60;
  var MIN_W = 0.04, MIN_H = 0.02;
  var SIZE_ONE = [0.30, 0.032], SIZE_MULTI = [0.34, 0.09];

  var uid = 0;
  var fields = [];
  try {
    fields = JSON.parse(viewer.dataset.fields || '[]').map(function (f) {
      return { id: ++uid, key: f.key || null, label: String(f.label), page: +f.page, x: +f.x, y: +f.y, w: +f.width, h: +f.height, multiline: !!f.multiline, required: f.required !== false };
    });
  } catch (e) { fields = []; }
  var selected = null, dirty = false, submitting = false;
  var list = document.getElementById('tpl-list');
  var count = document.getElementById('tpl-count');
  var emptyNote = document.getElementById('tpl-empty');
  var mq = window.matchMedia('(max-width: 900px)');

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text) { e.textContent = text; } return e; }
  function icon(name) { var i = el('i', 'bx ' + name); i.setAttribute('aria-hidden', 'true'); return i; }
  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
  function toast(msg) {
    var t = el('div', 'tpl-toast', msg); t.setAttribute('role', 'status'); document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 2600);
  }
  function touch() { dirty = true; renderList(); }

  /* ---------- lista lateral ---------- */
  function renderList() {
    count.textContent = String(fields.length);
    emptyNote.hidden = fields.length > 0;
    list.textContent = '';
    fields.slice().sort(function (a, b) { return a.page - b.page || a.y - b.y || a.x - b.x; }).forEach(function (f) {
      var li = el('li', 'tpl-item' + (selected === f.id ? ' is-sel' : ''));
      var name = el('button', 'tpl-name'); name.type = 'button'; name.appendChild(document.createTextNode(f.label));
      name.appendChild(el('span', 'tpl-pg', ' · página ' + f.page));
      name.addEventListener('click', function () { select(f.id); scrollTo(f); });
      var ed = el('button', 'tpl-ib'); ed.type = 'button'; ed.setAttribute('aria-label', 'Editar ' + f.label); ed.appendChild(icon('bx-edit-alt'));
      ed.addEventListener('click', function (e) { openPop(f, e); });
      var rm = el('button', 'tpl-ib'); rm.type = 'button'; rm.setAttribute('aria-label', 'Remover ' + f.label); rm.appendChild(icon('bx-trash'));
      rm.addEventListener('click', function () { remove(f); });
      li.appendChild(name); li.appendChild(ed); li.appendChild(rm); list.appendChild(li);
    });
  }
  function scrollTo(f) {
    var page = viewer.querySelector('.tpl-page[data-p="' + f.page + '"]');
    if (page && page.scrollIntoView) { page.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
  }

  /* ---------- lacunas no documento ---------- */
  function box(d, f) { d.style.left = f.x * 100 + '%'; d.style.top = f.y * 100 + '%'; d.style.width = f.w * 100 + '%'; d.style.height = f.h * 100 + '%'; }
  function paintPage(page) {
    var lay = page.querySelector('.tpl-lay'); lay.textContent = '';
    fields.filter(function (f) { return f.page === +page.dataset.p; }).forEach(function (f) { lay.appendChild(fieldEl(f, page)); });
  }
  function paintAll() { viewer.querySelectorAll('.tpl-page').forEach(paintPage); }

  function fieldEl(f, page) {
    var d = el('div', 'tpl-fld' + (selected === f.id ? ' is-sel' : '')); d.dataset.id = String(f.id);
    d.tabIndex = 0; d.setAttribute('role', 'group'); d.setAttribute('aria-label', 'Lacuna ' + f.label);
    box(d, f);
    d.appendChild(el('span', 'tpl-fl', f.label));
    [['tpl-c tpl-cp', 'Duplicar lacuna', 'bx-copy'], ['tpl-c tpl-rm', 'Remover lacuna', 'bx-x'], ['tpl-c tpl-rs', 'Redimensionar lacuna', 'bx-expand-alt']].forEach(function (c) {
      var b = el('button', c[0]); b.type = 'button'; b.setAttribute('aria-label', c[1]); b.appendChild(icon(c[2])); d.appendChild(b);
    });
    d.addEventListener('click', function (e) { e.stopPropagation(); });
    d.addEventListener('dblclick', function (e) { e.stopPropagation(); openPop(f, e); });
    d.addEventListener('keydown', function (e) { key(e, f, d); });
    d.addEventListener('pointerdown', function (e) { down(e, f, d, page); });
    return d;
  }
  function select(id) {
    selected = id;
    viewer.querySelectorAll('.tpl-fld').forEach(function (x) { x.classList.toggle('is-sel', x.dataset.id === String(id)); });
    renderList();
  }
  function remove(f) { fields = fields.filter(function (x) { return x !== f; }); selected = null; touch(); paintAll(); }
  function duplicate(f) {
    if (fields.length >= MAX_FIELDS) { toast('No máximo ' + MAX_FIELDS + ' lacunas.'); return; }
    var c = { id: ++uid, key: null, label: f.label, page: f.page, w: f.w, h: f.h, multiline: f.multiline, required: f.required, x: Math.min(1 - f.w, f.x + 0.03), y: Math.min(1 - f.h, f.y + 0.03) };
    fields.push(c); selected = c.id; touch(); paintAll();
  }
  function down(e, f, d, page) {
    if (e.target.closest('.tpl-rm')) { e.preventDefault(); remove(f); return; }
    if (e.target.closest('.tpl-cp')) { e.preventDefault(); duplicate(f); return; }
    var resize = !!e.target.closest('.tpl-rs');
    select(f.id);
    var rect = page.getBoundingClientRect();
    var sx = e.clientX, sy = e.clientY, ox = f.x, oy = f.y, ow = f.w, oh = f.h, moved = false;
    try { d.setPointerCapture(e.pointerId); } catch (err) { /* ignorado */ }
    function mv(ev) {
      var dx = (ev.clientX - sx) / rect.width, dy = (ev.clientY - sy) / rect.height;
      moved = true;
      if (resize) { f.w = clamp(ow + dx, MIN_W, 1 - f.x); f.h = clamp(oh + dy, MIN_H, 1 - f.y); }
      else { f.x = clamp(ox + dx, 0, 1 - f.w); f.y = clamp(oy + dy, 0, 1 - f.h); }
      box(d, f);
    }
    function up() { d.removeEventListener('pointermove', mv); d.removeEventListener('pointerup', up); d.removeEventListener('pointercancel', up); if (moved) { touch(); } }
    d.addEventListener('pointermove', mv); d.addEventListener('pointerup', up); d.addEventListener('pointercancel', up);
    e.preventDefault();
  }
  function key(e, f, d) {
    var step = e.shiftKey ? 0.02 : 0.005, k = e.key;
    if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); remove(f); return; }
    if (k === 'Enter') { e.preventDefault(); openPop(f, null); return; }
    var dx = k === 'ArrowRight' ? step : k === 'ArrowLeft' ? -step : 0;
    var dy = k === 'ArrowDown' ? step : k === 'ArrowUp' ? -step : 0;
    if (!dx && !dy) { return; }
    e.preventDefault();
    if (e.altKey) { f.w = clamp(f.w + dx, MIN_W, 1 - f.x); f.h = clamp(f.h + dy, MIN_H, 1 - f.y); }
    else { f.x = clamp(f.x + dx, 0, 1 - f.w); f.y = clamp(f.y + dy, 0, 1 - f.h); }
    box(d, f); touch();
  }

  /* ---------- popup: nome da lacuna ---------- */
  function closePop() { var p = document.getElementById('tpl-pop'); if (p) { p.remove(); } }
  // target: lacuna existente (editar) ou { page, fx, fy } (nova, no ponto clicado).
  function openPop(target, e) {
    closePop();
    var isNew = !target.id;
    if (isNew && fields.length >= MAX_FIELDS) { toast('No máximo ' + MAX_FIELDS + ' lacunas.'); return; }
    var p = el('form', 'tpl-pop' + (mq.matches ? ' is-sheet' : '')); p.id = 'tpl-pop'; p.setAttribute('role', 'dialog'); p.setAttribute('aria-label', isNew ? 'Nova lacuna' : 'Editar lacuna');
    var x = el('button', 'tpl-popx'); x.type = 'button'; x.setAttribute('aria-label', 'Fechar'); x.appendChild(icon('bx-x')); p.appendChild(x);
    p.appendChild(el('h6', 'tpl-poph', isNew ? 'Nova lacuna' : 'Editar lacuna'));

    var lab = el('label', 'tpl-lbl', 'Nome da lacuna'); lab.setAttribute('for', 'tpl-label'); p.appendChild(lab);
    var inp = el('input'); inp.type = 'text'; inp.id = 'tpl-label'; inp.maxLength = MAX_LABEL; inp.placeholder = 'Ex.: Nome do contratante'; inp.autocomplete = 'off';
    inp.value = isNew ? '' : target.label; p.appendChild(inp);
    var err = el('div', 'tpl-err'); err.setAttribute('role', 'alert'); err.hidden = true; p.appendChild(err);

    var ml = el('label', 'tpl-chk'); var mlc = el('input'); mlc.type = 'checkbox'; mlc.checked = !isNew && target.multiline; ml.appendChild(mlc); ml.appendChild(document.createTextNode(' Texto com várias linhas (endereço, descrição)')); p.appendChild(ml);
    var rq = el('label', 'tpl-chk'); var rqc = el('input'); rqc.type = 'checkbox'; rqc.checked = isNew ? true : target.required; rq.appendChild(rqc); rq.appendChild(document.createTextNode(' Preenchimento obrigatório')); p.appendChild(rq);

    var ok = el('button', 'tpl-ok', isNew ? 'Adicionar lacuna' : 'Salvar'); ok.type = 'submit'; p.appendChild(ok);
    document.body.appendChild(p);
    if (!mq.matches && e) {
      p.style.left = Math.min(window.innerWidth - 330, Math.max(8, e.clientX)) + 'px';
      p.style.top = Math.min(window.innerHeight - 300, Math.max(8, e.clientY)) + 'px';
    } else if (!mq.matches) { p.style.left = '50%'; p.style.top = '30%'; p.style.transform = 'translateX(-50%)'; }

    p.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var name = inp.value.replace(/\s+/g, ' ').trim();
      if (!name) { err.textContent = 'Dê um nome à lacuna.'; err.hidden = false; inp.focus(); return; }
      if (isNew) {
        var sz = mlc.checked ? SIZE_MULTI : SIZE_ONE;
        var f = { id: ++uid, label: name, page: target.page, w: sz[0], h: sz[1], multiline: mlc.checked, required: rqc.checked,
          x: clamp(target.fx - sz[0] / 2, 0, 1 - sz[0]), y: clamp(target.fy - sz[1] / 2, 0, 1 - sz[1]) };
        fields.push(f); selected = f.id;
      } else {
        target.label = name; target.required = rqc.checked;
        if (target.multiline !== mlc.checked) {
          target.multiline = mlc.checked;
          var s2 = mlc.checked ? SIZE_MULTI : SIZE_ONE;
          target.h = clamp(Math.max(target.h, s2[1]) , MIN_H, 1 - target.y);
          if (!mlc.checked) { target.h = clamp(SIZE_ONE[1], MIN_H, 1 - target.y); }
        }
      }
      touch(); closePop(); paintAll();
    });
    x.addEventListener('click', closePop);
    inp.focus();
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePop(); } });
  document.addEventListener('mousedown', function (e) { var p = document.getElementById('tpl-pop'); if (p && !p.contains(e.target) && !e.target.closest('.tpl-page') && !e.target.closest('.tpl-ib')) { closePop(); } });

  /* ---------- PDF ---------- */
  function showError() {
    viewer.setAttribute('aria-busy', 'false');
    var l = document.getElementById('tpl-loading'); if (l) { l.remove(); }
    var m = el('p', 'tpl-loaderr', 'Não foi possível mostrar o PDF. Recarregue a página ou troque o arquivo do modelo.'); m.setAttribute('role', 'alert'); viewer.appendChild(m);
  }
  function buildPages(doc) {
    var l = document.getElementById('tpl-loading'); if (l) { l.remove(); }
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
        var page = el('div', 'tpl-page'); page.dataset.p = String(n);
        page.appendChild(el('div', 'tpl-lay'));
        viewer.appendChild(page);
        page.addEventListener('click', function (e) {
          if (e.target.closest('.tpl-fld')) { return; }
          select(null); paintAll();
          var rect = page.getBoundingClientRect();
          openPop({ page: n, fx: (e.clientX - rect.left) / rect.width, fy: (e.clientY - rect.top) / rect.height }, e);
        });
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
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    fetch(viewer.dataset.pdfUrl, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('pdf'); } return r.arrayBuffer(); })
      .then(function (buf) { return window.pdfjsLib.getDocument({ data: buf, isEvalSupported: false }).promise; })
      .then(buildPages)
      .catch(showError);
  }

  /* ---------- salvar ---------- */
  form.addEventListener('submit', function () {
    input.value = JSON.stringify(fields.map(function (f) {
      return { key: f.key || undefined, label: f.label, page: f.page, x: +f.x.toFixed(5), y: +f.y.toFixed(5), width: +f.w.toFixed(5), height: +f.h.toFixed(5), multiline: f.multiline, required: f.required };
    }));
    submitting = true;
  });
  window.addEventListener('beforeunload', function (e) { if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; } });

  // Trocar o PDF (formulário próprio) também sai da página: avisa se há alterações não salvas.
  var replace = document.getElementById('tpl-replace');
  if (replace) { replace.addEventListener('submit', function () { submitting = true; }); }

  viewer.setAttribute('aria-busy', 'true');
  viewer.appendChild(el('p', 'tpl-loading', 'Carregando o documento…')).id = 'tpl-loading';
  renderList();
  loadPdf();
})();
