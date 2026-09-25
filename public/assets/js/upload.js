// «Subir mi plantilla»: muestra solo los campos de foto necesarios, vista previa y aviso de >5 MB. Sin dependencias; el formulario funciona sin JS.
(function () {
  var n = document.querySelector('[data-photo-count]');
  var slots = document.querySelectorAll('[data-slot]');
  if (!n || !slots.length) return;
  var MAX = 5 * 1024 * 1024;
  function sync() {
    var c = Math.max(0, Math.min(12, parseInt(n.value, 10) || 0));
    slots.forEach(function (s) { s.hidden = parseInt(s.getAttribute('data-slot'), 10) > c; });
  }
  n.addEventListener('input', sync);
  sync();
  slots.forEach(function (s) {
    var inp = s.querySelector('input[type=file]'), img = s.querySelector('[data-prev]'), warn = s.querySelector('[data-warn]');
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      warn.hidden = !(f && f.size > MAX);
      if (!f || !/^image\//.test(f.type)) { img.classList.add('hidden'); return; }
      var r = new FileReader();
      r.onload = function () { img.src = r.result; img.classList.remove('hidden'); };
      r.readAsDataURL(f);
    });
  });
})();
