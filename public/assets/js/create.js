// Asistente "Crea tu página": progreso, resumen, contador y vista previa en vivo. Sin dependencias.
(function () {
  var form = document.getElementById('crear');
  if (!form) return;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var radios = form.querySelectorAll('input[name="template_id"]');
  var fields = ['your_name', 'partner_name', 'start_date', 'message'];
  var summary = $('#paso-3'), balance = parseInt(summary.getAttribute('data-balance'), 10) || 0;
  var box = $('#preview-box'), frame = $('[data-preview-frame]'), empty = $('[data-preview-empty]');
  var timer;

  function selected() { return form.querySelector('input[name="template_id"]:checked'); }
  function el(id) { return document.getElementById(id); }

  function updateSummary() {
    var r = selected(), cost = r ? parseInt(r.getAttribute('data-cost'), 10) || 0 : 0;
    $('[data-sum="name"]').textContent = r ? r.getAttribute('data-name') : 'Sin elegir';
    $('[data-sum="cost"]').textContent = cost > 0 ? cost + ' monedas' : 'Gratis';
    $('[data-sum="after"]').textContent = Math.max(0, balance - cost) + ' monedas';
    $('[data-sum="short"]').classList.toggle('hidden', !(r && balance < cost));
  }

  function updateSteps() {
    var s1 = !!selected();
    var s2 = fields.every(function (f) { return el(f) && el(f).value.trim() !== ''; });
    [['paso-1', s1], ['paso-2', s2], ['paso-3', s1 && s2]].forEach(function (p) {
      var a = $('[data-step="' + p[0] + '"]');
      if (!a) return;
      if (p[1]) { a.setAttribute('data-done', ''); } else { a.removeAttribute('data-done'); }
    });
  }

  function counter() {
    var c = $('[data-counter]'), m = el('message');
    if (c && m) c.textContent = m.value.length + ' / ' + c.getAttribute('data-max');
  }

  function loadPreview() {
    var r = selected();
    if (!r || !box.open) return;
    var q = ['t=' + encodeURIComponent(r.getAttribute('data-slug')), 'embed=1'];
    fields.forEach(function (f) {
      var v = el(f) && el(f).value.trim();
      if (v) q.push(f + '=' + encodeURIComponent(v));
    });
    frame.onload = function () { frame.classList.remove('opacity-0'); empty.classList.add('hidden'); };
    frame.src = box.getAttribute('data-embed') + '?' + q.join('&');
  }
  function schedule() { clearTimeout(timer); timer = setTimeout(loadPreview, 600); }

  // Vista previa: visible solo con JS; abierta de forma fija en escritorio.
  box.hidden = false;
  var mq = window.matchMedia('(min-width: 1024px)');
  function sync() { if (mq.matches) box.open = true; }
  sync();
  if (mq.addEventListener) mq.addEventListener('change', sync);
  box.addEventListener('toggle', loadPreview);

  radios.forEach(function (r) { r.addEventListener('change', function () { updateSummary(); updateSteps(); schedule(); }); });
  fields.forEach(function (f) {
    var i = el(f);
    if (i) i.addEventListener('input', function () { updateSteps(); schedule(); if (f === 'message') counter(); });
  });

  updateSummary(); updateSteps(); counter(); loadPreview();
})();
