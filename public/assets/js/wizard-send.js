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
