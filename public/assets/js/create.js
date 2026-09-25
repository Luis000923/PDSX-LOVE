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
  // Fotos de la plantilla: cada radio trae su lista (data-images) y aquí se dibujan los campos de archivo.
  var photos = $('[data-photos]'), MAX_BYTES = 5 * 1024 * 1024, OK_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

  function photoField(f) {
    var id = 'img_' + f.key, wrap = document.createElement('div'), lab = document.createElement('label'), row = document.createElement('div');
    wrap.setAttribute('data-photo', '');
    lab.className = 'block text-sm font-semibold mb-1'; lab.htmlFor = id;
    lab.appendChild(document.createTextNode(f.label + ' '));
    var mark = document.createElement('span');
    if (f.required) { mark.className = 'text-rose-700'; mark.textContent = '* (obligatoria)'; } else { mark.className = 'font-normal text-slate-600'; mark.textContent = '(opcional)'; }
    lab.appendChild(mark);
    row.className = 'flex items-center gap-3';
    var th = document.createElement('img');
    th.setAttribute('data-photo-thumb', ''); th.hidden = true; th.alt = ''; th.width = 64; th.height = 64;
    th.className = 'h-16 w-16 shrink-0 rounded-lg object-cover border border-rose-100';
    var inp = document.createElement('input');
    inp.type = 'file'; inp.id = id; inp.name = id; inp.required = !!f.required;
    inp.accept = 'image/jpeg,image/png,image/webp';
    inp.setAttribute('aria-describedby', id + '-help ' + id + '-err');
    inp.className = 'min-h-[44px] w-full min-w-0 text-sm text-slate-700 file:mr-3 file:min-h-[44px] file:rounded-xl file:border-0 file:bg-rose-100 file:px-4 file:font-semibold file:text-rose-800';
    row.appendChild(th); row.appendChild(inp);
    var help = document.createElement('p'); help.id = id + '-help'; help.className = 'mt-1 text-xs text-slate-600'; help.textContent = 'JPG, PNG o WebP, máx. 5 MB';
    var err = document.createElement('p'); err.id = id + '-err'; err.className = 'text-sm text-rose-700 mt-1'; err.setAttribute('data-photo-err', ''); err.setAttribute('role', 'alert');
    wrap.appendChild(lab); wrap.appendChild(row); wrap.appendChild(help); wrap.appendChild(err);
    return wrap;
  }

  function renderPhotos() {
    if (!photos) return;
    var r = selected(), list = [];
    try { list = JSON.parse((r && r.getAttribute('data-images')) || '[]') || []; } catch (e) { list = []; }
    var h3 = photos.querySelector('h3');
    Array.prototype.slice.call(photos.querySelectorAll('[data-photo]')).forEach(function (n) { n.remove(); });
    list.forEach(function (f) { photos.appendChild(photoField(f)); });
    photos.hidden = list.length === 0;
    if (h3) h3.hidden = list.length === 0;
  }

  // Vista previa en miniatura (data: URL; la CSP no permite blob:) y aviso inmediato; el servidor revalida todo.
  function onPhoto(ev) {
    var inp = ev.target;
    if (!inp || inp.type !== 'file' || !inp.closest('[data-photo]')) return;
    var box = inp.closest('[data-photo]'), th = box.querySelector('[data-photo-thumb]'), err = box.querySelector('[data-photo-err]'), file = inp.files && inp.files[0];
    err.textContent = ''; th.hidden = true; th.removeAttribute('src'); inp.removeAttribute('aria-invalid');
    if (!file) return;
    var msg = OK_TYPES.indexOf(file.type) < 0 ? 'Solo se admiten fotos JPG, PNG o WebP.' : (file.size > MAX_BYTES ? 'La foto pesa más de 5 MB.' : '');
    if (msg) { err.textContent = msg; inp.setAttribute('aria-invalid', 'true'); inp.value = ''; return; }
    var fr = new FileReader();
    fr.onload = function () { th.src = String(fr.result); th.hidden = false; };
    fr.readAsDataURL(file);
    updateSteps();
  }
  form.addEventListener('change', onPhoto);

  function schedule() { clearTimeout(timer); timer = setTimeout(loadPreview, 600); }

  // Vista previa: visible solo con JS; abierta de forma fija en escritorio.
  box.hidden = false;
  var mq = window.matchMedia('(min-width: 1024px)');
  function sync() { if (mq.matches) box.open = true; }
  sync();
  if (mq.addEventListener) mq.addEventListener('change', sync);
  box.addEventListener('toggle', loadPreview);

  radios.forEach(function (r) { r.addEventListener('change', function () { renderPhotos(); updateSummary(); updateSteps(); schedule(); }); });
  fields.forEach(function (f) {
    var i = el(f);
    if (i) i.addEventListener('input', function () { updateSteps(); schedule(); if (f === 'message') counter(); });
  });

  updateSummary(); updateSteps(); counter(); loadPreview();
  // Si el servidor ya pintó los campos (modo sin JS), se conservan mientras no cambie la plantilla.
})();
