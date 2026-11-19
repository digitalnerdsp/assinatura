/* Assine Já — avisos e confirmações próprios (no lugar de alert/confirm do navegador).
 *   ajAlert(mensagem, { tone: 'error' | 'success' | 'info', title })   aviso que some sozinho
 *   ajConfirm(mensagem, { title, ok, cancel, danger })                  devolve uma Promise<boolean>
 *   <form data-confirm="Remover esta chave?">                           pede confirmação antes de enviar
 * window.alert também passa a mostrar o aviso próprio (o que ainda chamar alert não abre a caixa do navegador). */
(function () {
  'use strict';

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text) { e.textContent = text; } return e; }

  /* ---------- aviso ---------- */
  var stack = null;
  function toastStack() {
    if (!stack) {
      stack = el('div', 'aj-toasts'); stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    return stack;
  }
  function ajAlert(message, opts) {
    opts = opts || {};
    var tone = opts.tone || 'error';
    var t = el('div', 'aj-toast aj-' + tone); t.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    var body = el('div', 'aj-toast-body');
    if (opts.title) { body.appendChild(el('strong', 'aj-toast-title', opts.title)); }
    body.appendChild(el('span', '', String(message)));
    var x = el('button', 'aj-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Fechar aviso');
    function close() { if (t.parentNode) { t.parentNode.removeChild(t); } }
    x.addEventListener('click', close);
    t.appendChild(body); t.appendChild(x);
    toastStack().appendChild(t);
    setTimeout(close, opts.ms || (tone === 'error' ? 8000 : 5000));
    return t;
  }

  /* ---------- confirmação ---------- */
  function ajConfirm(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var previous = document.activeElement;
      var back = el('div', 'aj-back');
      var box = el('div', 'aj-dialog'); box.setAttribute('role', 'alertdialog'); box.setAttribute('aria-modal', 'true');
      var tid = 'aj-t' + Math.random().toString(36).slice(2, 8), did = tid + 'd';
      box.setAttribute('aria-labelledby', tid); box.setAttribute('aria-describedby', did);
      box.appendChild(el('h3', 'aj-title', opts.title || 'Confirmar')).id = tid;
      box.appendChild(el('p', 'aj-msg', String(message))).id = did;
      var row = el('div', 'aj-actions');
      var no = el('button', 'aj-btn aj-sec', opts.cancel || 'Cancelar'); no.type = 'button';
      var yes = el('button', 'aj-btn ' + (opts.danger ? 'aj-danger' : 'aj-pri'), opts.ok || 'Confirmar'); yes.type = 'button';
      row.appendChild(no); row.appendChild(yes); box.appendChild(row);
      back.appendChild(box); document.body.appendChild(back);
      document.body.classList.add('aj-open');

      function done(v) {
        document.removeEventListener('keydown', key, true);
        if (back.parentNode) { back.parentNode.removeChild(back); }
        document.body.classList.remove('aj-open');
        if (previous && previous.focus) { try { previous.focus(); } catch (e) { /* ignorado */ } }
        resolve(v);
      }
      function key(e) {
        if (e.key === 'Escape') { e.preventDefault(); done(false); }
        if (e.key === 'Tab') { // mantém o foco dentro da janela
          var f = [no, yes], i = f.indexOf(document.activeElement);
          e.preventDefault(); f[(i + (e.shiftKey ? -1 : 1) + f.length) % f.length].focus();
        }
      }
      document.addEventListener('keydown', key, true);
      no.addEventListener('click', function () { done(false); });
      yes.addEventListener('click', function () { done(true); });
      back.addEventListener('mousedown', function (e) { if (e.target === back) { done(false); } });
      (opts.danger ? no : yes).focus(); // ação destrutiva: o foco começa em "Cancelar"
    });
  }

  /* ---------- formulários com data-confirm ---------- */
  var DANGER = /remov|apag|exclu|revog|cancel|suspend|delet|limpar|parar de/i;
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!(f instanceof HTMLFormElement)) { return; }
    var msg = f.getAttribute('data-confirm');
    if (!msg || f.dataset.ajOk === '1') { return; }
    e.preventDefault(); e.stopImmediatePropagation();
    var submitter = e.submitter || null;
    ajConfirm(msg, { danger: DANGER.test(msg), ok: DANGER.test(msg) ? 'Sim, continuar' : 'Confirmar' }).then(function (ok) {
      if (!ok) { return; }
      f.dataset.ajOk = '1';
      if (f.requestSubmit) { f.requestSubmit(submitter || undefined); } else { f.submit(); }
    });
  }, true);

  window.ajAlert = ajAlert;
  window.ajConfirm = ajConfirm;
  window.alert = function (m) { ajAlert(m, { tone: 'error' }); }; // nada abre a caixa do navegador
})();
