/* Passo 4: envio automático liga/desliga campos, contador da mensagem, data limite e proteção contra duplo clique. */
(function () {
  'use strict';
  var form = document.getElementById('wz-form4');
  if (!form) { return; }

  var auto = document.getElementById('wz-auto');
  var box = document.getElementById('wz-auto-box');
  var remind = document.getElementById('wz-remind');
  var remindRow = document.getElementById('wz-remind-row');
  var days = document.getElementById('wz-remind-days');
  var limit = document.getElementById('wz-limit');
  var date = document.getElementById('wz-limit-date');
  var msg = document.getElementById('wz-msg');
  var cnt = document.getElementById('wz-cnt');
  var send = document.getElementById('wz-send');

  function sync() {
    var on = auto.checked;
    box.hidden = !on;
    remindRow.style.opacity = on ? '' : '0.55';
    remind.disabled = !on;
    days.disabled = !on || !remind.checked;
    document.querySelectorAll('.wz-req').forEach(function (s) { s.textContent = on ? '*' : '(opcional)'; });
  }
  auto.addEventListener('change', sync);
  remind.addEventListener('change', sync);
  limit.addEventListener('change', function () { date.hidden = !limit.checked; });
  msg.addEventListener('input', function () { cnt.textContent = msg.value.length + ' / 500'; });
  sync();

  /* Vigência do contrato: mostra os campos e adiciona e-mails (até o limite) */
  var watchOn = document.getElementById('wz-watch-on');
  var watchBox = document.getElementById('wz-watch-box');
  var mails = document.getElementById('wz-c-emails');
  var addMail = document.getElementById('wz-c-add');
  if (watchOn && watchBox) {
    watchOn.addEventListener('change', function () {
      watchBox.hidden = !watchOn.checked;
      if (watchOn.checked) { var d = document.getElementById('wz-c-date'); if (d && !d.value) { d.focus(); } }
    });
  }
  function syncAdd() { if (addMail) { addMail.hidden = mails.querySelectorAll('input').length >= parseInt(mails.dataset.max, 10); } }
  if (addMail && mails) {
    addMail.addEventListener('click', function () {
      var n = mails.querySelectorAll('input').length;
      if (n >= parseInt(mails.dataset.max, 10)) { return; }
      var i = document.createElement('input');
      i.type = 'email'; i.name = 'contract_emails[]'; i.id = 'wz-c-mail-' + n; i.maxLength = 190; i.autocomplete = 'off';
      i.setAttribute('aria-label', 'E-mail de aviso ' + (n + 1));
      mails.appendChild(i); i.focus(); syncAdd();
    });
    syncAdd();
  }
  var help = document.getElementById('wz-help');
  if (help) { help.addEventListener('click', function (e) { e.preventDefault(); var t = document.getElementById('wz-helptext'); t.hidden = !t.hidden; }); }

  /* "Voltar" grava as opções (formaction) e leva ao passo 3 */
  form.addEventListener('click', function (e) {
    var b = e.target.closest('button[formaction]');
    if (b) { document.getElementById('wz-go').value = 'back'; }
  });
  /* Um clique só: o botão fica desabilitado depois de enviar */
  form.addEventListener('submit', function (e) {
    if (e.submitter && e.submitter.id === 'wz-send') {
      if (send.dataset.busy === '1') { e.preventDefault(); return; }
      send.dataset.busy = '1'; send.disabled = true; send.textContent = 'ENVIANDO…';
    }
  });
})();
