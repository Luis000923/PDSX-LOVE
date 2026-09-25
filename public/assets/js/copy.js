// Copiar enlace: delega clic en [data-copy] (URL en el atributo). Sin dependencias.
(function () {
  function fallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    return ok ? Promise.resolve() : Promise.reject();
  }
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest && ev.target.closest('[data-copy]');
    if (!btn) return;
    ev.preventDefault();
    var text = btn.getAttribute('data-copy');
    var label = btn.querySelector('[data-copy-label]') || btn;
    var orig = label.getAttribute('data-orig') || label.textContent;
    label.setAttribute('data-orig', orig);
    var p = (navigator.clipboard && window.isSecureContext) ? navigator.clipboard.writeText(text).catch(function () { return fallback(text); }) : fallback(text);
    p.then(function () { label.textContent = '¡Copiado!'; }, function () { label.textContent = 'Copia el enlace a mano'; })
      .then(function () { clearTimeout(btn._t); btn._t = setTimeout(function () { label.textContent = orig; }, 2000); });
  });
})();
