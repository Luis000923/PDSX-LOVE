(function () {
  var box = document.getElementById('petals');
  for (var i = 0; i < 18; i++) {
    var p = document.createElement('span');
    p.className = 'petal';
    p.textContent = '🌼';
    p.style.left = Math.random() * 100 + 'vw';
    p.style.animationDuration = 6 + Math.random() * 8 + 's';
    p.style.animationDelay = -Math.random() * 10 + 's';
    box.appendChild(p);
  }
})();
