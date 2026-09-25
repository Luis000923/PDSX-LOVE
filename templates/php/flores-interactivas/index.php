<?php
/**
 * Flores Amarillas Interactivas — variables recibidas: $t (campos YA escapados por el motor:
 * your_name, partner_name, message, days_together), $nonce, $ad_slot y $assets.
 *
 * Nota de implementación: el analizador de PhpTemplate::scan() usa una lista BLANCA de funciones
 * nativas; `sin`/`cos` no están en ella, así que las "semillas" del centro de la flor (que en el
 * diseño de referencia se colocaban con una espiral áurea vía sin/cos) se sustituyen aquí por
 * anillos concéntricos posicionados con `transform="rotate(...)"`: el navegador resuelve la
 * trigonometría al dibujar el SVG, y en PHP solo usamos aritmética y `round()` (sí permitida).
 */
$d = intval($t['days_together']);
$nota = $d >= 730 ? 'Ya son varias vueltas al sol floreciendo juntos.'
      : ($d >= 365 ? 'Más de un año de girasoles.'
      : ($d >= 30 ? 'Ya son meses de jardín compartido.'
      : 'Apenas empieza el jardín.'));
// Con más de un año juntos, la flor principal luce un anillo extra de pétalos internos.
$anilloExtra = $d >= 365;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#FBF6EC">
<title><?= $t['your_name'] ?> &amp; <?= $t['partner_name'] ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">

<script nonce="<?= $nonce ?>" src="https://cdn.tailwindcss.com"></script>
<script nonce="<?= $nonce ?>">
tailwind.config = {
  theme: {
    extend: {
      colors: { crema: '#FBF6EC', arena: '#F1E7D3', tinta: '#2E2A24', suave: '#7A6F60', oro: '#C9962B', miel: '#E7B63E' },
      fontFamily: { display: ['"Cormorant Garamond"', 'serif'], sans: ['Jost', 'system-ui', 'sans-serif'] },
      letterSpacing: { amplio: '0.32em' }
    }
  }
};
</script>

<style>
  html, body { overflow-x: hidden; max-width: 100%; }
  body {
    background-color: #FBF6EC;
    background-image:
      radial-gradient(60rem 40rem at 20% 30%, rgba(247, 214, 120, 0.22), transparent 60%),
      radial-gradient(50rem 40rem at 90% 90%, rgba(201, 150, 43, 0.10), transparent 60%);
    background-attachment: fixed;
  }
  .grano::before {
    content: "";
    position: fixed; inset: 0;
    pointer-events: none;
    opacity: .35;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/%3E%3CfeColorMatrix values='0 0 0 0 0.4 0 0 0 0 0.33 0 0 0 0 0.2 0 0 0 .06 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    z-index: 0;
  }
  h1 { font-size: clamp(1.7rem, 7vw, 4rem); }
  .msg-texto { font-size: clamp(1.05rem, 4vw, 1.6rem); }

  @keyframes flotar { 0%, 100% { transform: translateY(0) rotate(-1deg); } 50% { transform: translateY(-14px) rotate(1deg); } }
  @keyframes respirar { 0%, 100% { transform: rotate(0deg) scale(1); } 50% { transform: rotate(4deg) scale(1.025); } }
  @keyframes mecer { 0%, 100% { transform: rotate(-2.5deg); } 50% { transform: rotate(2.5deg); } }
  @keyframes sombra { 0%, 100% { transform: scaleX(1); opacity: .32; } 50% { transform: scaleX(.86); opacity: .18; } }
  @keyframes caer {
    0% { transform: translate3d(0, -10vh, 0) rotate(0deg); opacity: 0; }
    10% { opacity: .55; }
    90% { opacity: .45; }
    100% { transform: translate3d(12vw, 110vh, 0) rotate(420deg); opacity: 0; }
  }
  .flotar { animation: flotar 7s ease-in-out infinite; transform-origin: 50% 90%; }
  .respirar { animation: respirar 9s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }
  .mecer { animation: mecer 8s ease-in-out infinite; transform-box: fill-box; transform-origin: 50% 100%; }
  .mecer-b { animation-duration: 10s; animation-delay: -3s; }
  .sombra { animation: sombra 7s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }

  .petalo-caida { position: fixed; top: 0; width: 14px; height: 20px; pointer-events: none; animation: caer linear infinite; z-index: 1; }

  .frase { transition: opacity .7s ease, transform .7s ease, filter .7s ease; }
  .frase.oculta { opacity: 0; transform: translateY(8px); filter: blur(3px); }

  @keyframes entrar { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
  .entrar { animation: entrar 1.2s cubic-bezier(.2,.7,.2,1) both; }
  .d1 { animation-delay: .15s; } .d2 { animation-delay: .35s; } .d3 { animation-delay: .55s; } .d4 { animation-delay: .75s; }

  @media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; }
    .petalo-caida { display: none; }
    .frase.oculta { opacity: 1; transform: none; filter: none; }
  }
</style>
</head>
<body class="grano min-h-screen font-sans text-tinta antialiased overflow-x-hidden">

<div aria-hidden="true">
  <?php
  $caida = [[8, 19, 0], [22, 24, -7], [41, 21, -3], [63, 26, -12], [78, 22, -5], [91, 28, -16]];
  foreach ($caida as $gota):
      $left = $gota[0]; $dur = $gota[1]; $delay = $gota[2];
  ?>
  <svg class="petalo-caida" style="left:<?= $left ?>vw;animation-duration:<?= $dur ?>s;animation-delay:<?= $delay ?>s" viewBox="0 0 14 20">
    <path d="M7 0 C 13 5, 13 14, 7 20 C 1 14, 1 5, 7 0 Z" fill="#EDBE45" opacity=".7"></path>
  </svg>
  <?php endforeach; ?>
</div>

<main class="relative z-10 mx-auto flex min-h-screen max-w-6xl flex-col px-4 sm:px-10">

  <header class="flex items-center justify-between pt-6 sm:pt-10 entrar">
    <span class="text-[11px] font-medium uppercase tracking-amplio text-suave"><?= $d ?> días juntos</span>
    <span class="text-[11px] font-medium uppercase tracking-amplio text-suave">Para <?= $t['partner_name'] ?></span>
  </header>

  <section class="grid flex-1 items-center gap-4 py-4 sm:gap-10 sm:py-10 md:grid-cols-2 md:gap-16 lg:gap-24">

    <figure class="relative mx-auto w-full max-w-[220px] sm:max-w-[340px] md:max-w-[400px] entrar d1" aria-hidden="true">
      <svg viewBox="0 0 400 540" class="w-full overflow-visible" role="img" aria-label="Ramo de flores amarillas">
        <defs>
          <linearGradient id="gPetalo" x1="0" y1="1" x2="0" y2="0">
            <stop offset="0" stop-color="#D99A1E"></stop>
            <stop offset=".45" stop-color="#F2C23E"></stop>
            <stop offset="1" stop-color="#FCE38A"></stop>
          </linearGradient>
          <linearGradient id="gPetaloIn" x1="0" y1="1" x2="0" y2="0">
            <stop offset="0" stop-color="#C98314"></stop>
            <stop offset=".6" stop-color="#EDB42F"></stop>
            <stop offset="1" stop-color="#F9D867"></stop>
          </linearGradient>
          <radialGradient id="gCentro" cx=".42" cy=".38" r=".7">
            <stop offset="0" stop-color="#B8792A"></stop>
            <stop offset=".55" stop-color="#7A4A17"></stop>
            <stop offset="1" stop-color="#4E2E0E"></stop>
          </radialGradient>
          <linearGradient id="gTallo" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#8FAF4E"></stop>
            <stop offset="1" stop-color="#4F7331"></stop>
          </linearGradient>
          <linearGradient id="gHoja" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#A9C66A"></stop>
            <stop offset="1" stop-color="#557B35"></stop>
          </linearGradient>
          <radialGradient id="gHalo" cx=".5" cy=".5" r=".5">
            <stop offset="0" stop-color="#F7D46E" stop-opacity=".45"></stop>
            <stop offset="1" stop-color="#F7D46E" stop-opacity="0"></stop>
          </radialGradient>
          <filter id="sombraSuave" x="-30%" y="-30%" width="160%" height="160%">
            <feDropShadow dx="0" dy="6" stdDeviation="7" flood-color="#8A5A12" flood-opacity=".18"></feDropShadow>
          </filter>
          <filter id="desenfoque" x="-20%" y="-200%" width="140%" height="500%">
            <feGaussianBlur stdDeviation="5"></feGaussianBlur>
          </filter>
          <filter id="sombraPetalo" x="-50%" y="-50%" width="200%" height="200%">
            <feDropShadow dx="0" dy="1.5" stdDeviation="1.6" flood-color="#9A6410" flood-opacity=".22"></feDropShadow>
          </filter>

          <path id="petalo" d="M0 0 C -22 -18, -30 -62, -14 -100 C -8 -114, 8 -114, 14 -100 C 30 -62, 22 -18, 0 0 Z" fill="url(#gPetalo)" stroke="#E0A82C" stroke-width=".6" stroke-opacity=".5"></path>
          <path id="petaloIn" d="M0 0 C -20 -16, -26 -56, -11 -90 C -6 -101, 6 -101, 11 -90 C 26 -56, 20 -16, 0 0 Z" fill="url(#gPetaloIn)"></path>
          <path id="nervio" d="M0 -8 C -1 -40, 1 -70, 0 -98" fill="none" stroke="#FFF3C4" stroke-width="1" stroke-opacity=".55" stroke-linecap="round"></path>

          <g id="flor">
            <?php
            // Anillo de pétalos exteriores: repite <use> rotando alrededor del centro
            // (aritmética + round(); la rotación la resuelve el SVG del navegador).
            $cantidad = 14;
            $paso = 360 / $cantidad;
            ?>
            <g filter="url(#sombraPetalo)">
              <?php for ($i = 0; $i < $cantidad; $i++): $ang = round($i * $paso, 2); ?>
                <use href="#petalo" transform="rotate(<?= $ang ?>) scale(1)"></use>
              <?php endfor; ?>
            </g>
            <g opacity=".9">
              <?php for ($i = 0; $i < $cantidad; $i++): $ang = round($i * $paso, 2); ?>
                <use href="#nervio" transform="rotate(<?= $ang ?>) scale(1)"></use>
              <?php endfor; ?>
            </g>
            <?php $desfase = round(360 / ($cantidad * 2), 3); ?>
            <g filter="url(#sombraPetalo)">
              <?php for ($i = 0; $i < $cantidad; $i++): $ang = round($i * $paso + $desfase, 2); ?>
                <use href="#petaloIn" transform="rotate(<?= $ang ?>) scale(.62)"></use>
              <?php endfor; ?>
            </g>
            <?php if ($anilloExtra): ?>
            <g filter="url(#sombraPetalo)" opacity=".9">
              <?php for ($i = 0; $i < $cantidad; $i++): $ang = round($i * $paso + $desfase * 2, 2); ?>
                <use href="#petaloIn" transform="rotate(<?= $ang ?>) scale(.4)"></use>
              <?php endfor; ?>
            </g>
            <?php endif; ?>
            <circle r="30" fill="url(#gCentro)"></circle>
            <circle r="30" fill="none" stroke="#E9B544" stroke-width="2" stroke-opacity=".6"></circle>
            <g fill="#F3C85A" fill-opacity=".75">
              <?php
              // "Semillas" del centro: anillos concéntricos posicionados con rotate() en vez de
              // una espiral áurea con sin/cos (no disponibles en la lista blanca del analizador).
              $anillosSemillas = [
                  ['n' => 8,  'r' => 6,  'tam' => 1.3],
                  ['n' => 12, 'r' => 12, 'tam' => 1.6],
                  ['n' => 16, 'r' => 18, 'tam' => 1.9],
                  ['n' => 20, 'r' => 24, 'tam' => 2.2],
              ];
              foreach ($anillosSemillas as $idx => $anillo):
                  $n = $anillo['n']; $r = $anillo['r']; $tam = $anillo['tam'];
                  $pasoSem = 360 / $n;
                  $desfaseSem = round($idx * ($pasoSem / 2), 2);
                  for ($k = 0; $k < $n; $k++):
                      $angSem = round($k * $pasoSem + $desfaseSem, 2);
              ?>
                <g transform="rotate(<?= $angSem ?>)"><circle cx="<?= $r ?>" cy="0" r="<?= $tam ?>"></circle></g>
              <?php
                  endfor;
              endforeach;
              ?>
            </g>
            <ellipse cx="-9" cy="-11" rx="10" ry="6" fill="#FFF6D8" opacity=".18"></ellipse>
          </g>
        </defs>

        <ellipse class="sombra" cx="200" cy="522" rx="92" ry="8" fill="#8A6A2E" opacity=".28" filter="url(#desenfoque)"></ellipse>

        <g class="flotar">
          <circle cx="200" cy="170" r="175" fill="url(#gHalo)"></circle>

          <g class="mecer mecer-b">
            <path d="M200 505 C 180 430, 120 380, 96 300" fill="none" stroke="url(#gTallo)" stroke-width="4.5" stroke-linecap="round"></path>
            <path d="M150 392 C 118 392, 92 372, 78 344 C 110 350, 136 362, 150 392 Z" fill="url(#gHoja)" filter="url(#sombraSuave)"></path>
            <path d="M150 392 C 128 378, 104 362, 84 348" fill="none" stroke="#E9F0CF" stroke-width=".8" stroke-opacity=".6"></path>
            <g transform="translate(96 296) rotate(-18) scale(.52)" filter="url(#sombraSuave)">
              <g class="respirar"><use href="#flor"></use></g>
            </g>
          </g>

          <g class="mecer">
            <path d="M200 505 C 222 440, 272 400, 300 338" fill="none" stroke="url(#gTallo)" stroke-width="4" stroke-linecap="round"></path>
            <g transform="translate(300 334) rotate(22)" filter="url(#sombraSuave)">
              <path d="M0 4 C -16 -6, -16 -34, 0 -46 C 16 -34, 16 -6, 0 4 Z" fill="url(#gPetaloIn)"></path>
              <path d="M0 4 C -9 -8, -8 -30, 2 -42 C 3 -26, 4 -10, 0 4 Z" fill="#FCE38A" opacity=".55"></path>
              <path d="M0 6 C -14 2, -18 -14, -14 -24 C -8 -12, -4 -4, 0 6 Z" fill="#6E9440"></path>
              <path d="M0 6 C 14 2, 18 -14, 14 -24 C 8 -12, 4 -4, 0 6 Z" fill="#5E8538"></path>
            </g>
          </g>

          <g>
            <path d="M200 505 C 206 420, 190 320, 200 196" fill="none" stroke="url(#gTallo)" stroke-width="6" stroke-linecap="round"></path>
            <path d="M201 372 C 232 340, 280 334, 318 300 C 300 352, 252 378, 201 372 Z" fill="url(#gHoja)" filter="url(#sombraSuave)"></path>
            <path d="M203 370 C 240 356, 280 330, 312 306" fill="none" stroke="#E9F0CF" stroke-width="1" stroke-opacity=".6"></path>
            <path d="M199 436 C 168 414, 130 418, 102 396 C 118 440, 162 456, 199 436 Z" fill="url(#gHoja)" filter="url(#sombraSuave)"></path>
            <path d="M197 434 C 166 428, 134 416, 108 400" fill="none" stroke="#E9F0CF" stroke-width="1" stroke-opacity=".6"></path>
            <g transform="translate(200 172)" filter="url(#sombraSuave)">
              <g class="respirar"><use href="#flor"></use></g>
            </g>
          </g>
        </g>
      </svg>
    </figure>

    <div class="mx-auto w-full max-w-md text-center md:mx-0 md:text-left px-1">
      <p class="mb-4 sm:mb-6 text-[11px] font-medium uppercase tracking-amplio text-oro entrar d2">Flores amarillas para ti</p>

      <h1 class="font-display font-light leading-[1.05] text-tinta entrar d2 break-words">
        <?= $t['your_name'] ?> <em class="font-normal italic text-oro">&amp;</em> <?= $t['partner_name'] ?>
      </h1>

      <div class="mx-auto my-6 sm:my-10 h-px w-16 bg-oro/50 md:mx-0 entrar d3"></div>

      <blockquote class="min-h-[6.5rem] sm:min-h-[8.5rem] entrar d3">
        <p id="mensaje" class="frase oculta msg-texto font-display font-light italic leading-snug text-tinta/90" aria-live="polite">
          <?= $t['message'] ?>
        </p>
      </blockquote>

      <p class="mt-4 sm:mt-6 text-[12px] font-medium uppercase tracking-amplio text-suave entrar d3"><?= $nota ?></p>
    </div>
  </section>

  <footer class="pb-6 sm:pb-8 text-center md:text-right entrar d4">
    <p class="font-display text-lg italic text-suave">— <?= $t['your_name'] ?></p>
  </footer>
</main>

<?= $ad_slot ?>

<script nonce="<?= $nonce ?>">
(function () {
  "use strict";
  var mensaje = document.getElementById("mensaje");
  if (!mensaje) { return; }
  var reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (reduceMotion) {
    mensaje.classList.remove("oculta");
    return;
  }
  window.setTimeout(function () {
    mensaje.classList.remove("oculta");
  }, 900);
})();
</script>
</body>
</html>
