<?php
declare(strict_types=1);

/**
 * Compra confirmada: correo de confirmación (a soporte y al comprador) y animación de celebración.
 *
 * SEGURIDAD: nada de esto concede beneficios. Solo LEE pagos que el servidor ya dio por APPROVED y aplicados
 * (`fulfilled_at`), tras confirmarlos con Wompi. Ni la animación ni el correo se pueden provocar con parámetros
 * de URL, cookies ni almacenamiento del navegador; el «ya mostrado / ya enviado» se guarda en la BD.
 */
final class PurchaseNotice
{
    /** Ventana en la que una compra recién aplicada se celebra (después ya no tiene sentido). */
    private const CELEBRATE_WINDOW = '10 MINUTE';

    /** @return array<string,mixed>|null datos del pago + nombres legibles, o null */
    private static function describe(PDO $pdo, int $paymentId): ?array
    {
        $st = $pdo->prepare('SELECT p.id, p.user_id, p.reference, p.amount_in_cents, p.coins, p.template_id, p.tier_id, p.wompi_transaction_id, p.method,
                                    u.email, t.name AS tier_name, tp.name AS template_name
                               FROM payments p JOIN users u ON u.id = p.user_id
                          LEFT JOIN membership_tiers t ON t.id = p.tier_id
                          LEFT JOIN templates tp ON tp.id = p.template_id
                              WHERE p.id = ?');
        $st->execute([$paymentId]);
        $p = $st->fetch();
        if (!$p) {
            return null;
        }
        $kind = Payments::kind($p);
        $p['kind'] = $kind;
        $p['what'] = match ($kind) {
            'coins'    => (int) $p['coins'] . ' monedas',
            'template' => 'la plantilla «' . (string) $p['template_name'] . '»',
            default    => 'la membresía ' . (string) $p['tier_name'],
        };
        $p['usd'] = wompi_format_usd((int) $p['amount_in_cents']);
        return $p;
    }

    /** Envía (una sola vez por pago) el correo de confirmación a soporte y al comprador. Nunca lanza. */
    public static function email(PDO $pdo, int $paymentId): void
    {
        try {
            if (!Mailer::configured()) {
                return;
            }
            // Reclamo atómico: solo un proceso (webhook, retorno del usuario…) envía; si falla, se libera para reintentar.
            $claim = $pdo->prepare("UPDATE payments SET notified_at = UTC_TIMESTAMP()
                                     WHERE id = ? AND status = 'APPROVED' AND fulfilled_at IS NOT NULL AND notified_at IS NULL");
            $claim->execute([$paymentId]);
            if ($claim->rowCount() !== 1 || ($p = self::describe($pdo, $paymentId)) === null) {
                return;
            }
            $ok = true;
            $support = Mailer::supportEmail();
            if ($support !== '') {
                $ok = Mailer::send($support, 'Compra confirmada · ' . $p['reference'],
                    "Se confirmó una compra en LovePages.\n\n"
                    . "Referencia:   {$p['reference']}\n"
                    . "Comprador:    {$p['email']} (usuario #{$p['user_id']})\n"
                    . "Producto:     {$p['what']}\n"
                    . "Monto:        \${$p['usd']} USD\n"
                    . 'Transacción:  ' . ($p['wompi_transaction_id'] ?: '-') . "\n"
                    . "Método:       {$p['method']}\n");
            }
            Mailer::send((string) $p['email'], '¡Gracias por tu compra en LovePages!',
                "¡Gracias por tu compra!\n\n"
                . "Ya está lista: {$p['what']}.\n"
                . "Monto: \${$p['usd']} USD · Referencia: {$p['reference']}\n\n"
                . "Ahora puedes crear tu página en " . url('create.php') . "\n\n"
                . "Con cariño,\nEl equipo de LovePages\n");
            if (!$ok) {
                $pdo->prepare('UPDATE payments SET notified_at = NULL WHERE id = ?')->execute([$paymentId]);   // reintento en la próxima ocasión
            }
        } catch (Throwable $e) {
            error_log('PurchaseNotice::email: ' . $e->getMessage());
        }
    }

    /** Reintenta correos pendientes de compras recientes del usuario (SMTP caído la vez anterior). */
    public static function retryEmails(PDO $pdo, int $userId): void
    {
        $st = $pdo->prepare("SELECT id FROM payments WHERE user_id = ? AND status = 'APPROVED' AND fulfilled_at > UTC_TIMESTAMP() - INTERVAL 1 DAY
                              AND notified_at IS NULL ORDER BY id DESC LIMIT 3");
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            self::email($pdo, (int) $id);
        }
    }

    /**
     * HTML de la celebración de la última compra aplicada del usuario que aún no se mostró; '' si no corresponde.
     * Se marca como mostrada al renderizar (en la BD), así recargar no la repite.
     */
    public static function celebration(PDO $pdo, int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $st = $pdo->prepare("SELECT id FROM payments WHERE user_id = ? AND status = 'APPROVED' AND fulfilled_at IS NOT NULL
                               AND fulfilled_at > UTC_TIMESTAMP() - INTERVAL " . self::CELEBRATE_WINDOW . ' AND celebrated_at IS NULL
                             ORDER BY id DESC LIMIT 1');
        $st->execute([$userId]);
        $id = (int) $st->fetchColumn();
        if ($id === 0) {
            return '';
        }
        $claim = $pdo->prepare('UPDATE payments SET celebrated_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ? AND celebrated_at IS NULL');
        $claim->execute([$id, $userId]);
        if ($claim->rowCount() !== 1 || ($p = self::describe($pdo, $id)) === null) {
            return '';
        }
        return self::markup($p);
    }

    /** @param array<string,mixed> $p */
    private static function markup(array $p): string
    {
        $n = e(csp_nonce());
        $order = e('LP·' . strtoupper(substr((string) $p['reference'], -8)));
        $what = e((string) $p['what']);
        [$cta, $href] = $p['kind'] === 'coins' ? ['Explorar plantillas', 'index.php'] : ['Crear mi página', 'create.php'];
        $href = e(url($href));
        $cta = e($cta);
        $title = $p['kind'] === 'coins' ? '¡Tus monedas ya están contigo!' : '¡Tu compra se conectó con éxito!';
        $title = e($title);
        return <<<HTML
<style>
#lp-cel{position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;background:radial-gradient(circle at 50% 30%,#fff1f2,#ffe4e6 60%,#fecdd3);animation:lp-fade .5s ease both}
#lp-cel .lp-card{position:relative;z-index:2;width:100%;max-width:26rem;padding:2rem 1.5rem 1.75rem;border-radius:1.75rem;text-align:center;background:rgba(255,255,255,.72);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.9);box-shadow:0 20px 60px -20px rgba(225,29,72,.35);animation:lp-rise .7s cubic-bezier(.2,.9,.3,1.2) both}
#lp-cel .lp-icon{width:5.5rem;height:5.5rem;margin:0 auto 1.1rem;animation:lp-pop .9s cubic-bezier(.34,1.56,.64,1) .2s both;filter:drop-shadow(0 8px 14px rgba(225,29,72,.35))}
#lp-cel .lp-check{stroke-dasharray:32;stroke-dashoffset:32;animation:lp-draw .6s ease .95s forwards}
#lp-cel h2{font-size:1.35rem;font-weight:700;color:#881337;line-height:1.3}
#lp-cel .lp-what{margin-top:.35rem;font-size:.9rem;color:#9f1239}
#lp-cel .lp-phrase{margin:1rem 0 .25rem;min-height:3rem;font-size:.95rem;font-style:italic;color:#64748b;transition:opacity .5s}
#lp-cel .lp-order{display:inline-block;margin-top:.5rem;padding:.3rem .8rem;border-radius:999px;font:600 .75rem/1 ui-monospace,monospace;letter-spacing:.12em;color:#92400e;background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #fcd34d}
#lp-cel .lp-cta{display:flex;align-items:center;justify-content:center;min-height:48px;margin-top:1.25rem;border-radius:.9rem;background:#e11d48;color:#fff;font-weight:600;text-decoration:none;box-shadow:0 8px 20px -8px rgba(225,29,72,.6);transition:transform .2s,background .2s,box-shadow .2s}
#lp-cel .lp-cta:hover,#lp-cel .lp-cta:focus-visible{background:#be123c;transform:translateY(-2px);box-shadow:0 12px 24px -8px rgba(225,29,72,.7);outline:none}
#lp-cel .lp-close{display:block;margin:.75rem auto 0;min-height:44px;padding:0 1rem;background:none;border:0;color:#64748b;font-size:.85rem;cursor:pointer;text-decoration:underline}
#lp-cel .lp-p{position:absolute;bottom:-40px;z-index:1;pointer-events:none;opacity:0;animation:lp-float linear infinite}
@keyframes lp-fade{from{opacity:0}}
@keyframes lp-rise{from{opacity:0;transform:translateY(24px) scale(.96)}}
@keyframes lp-pop{0%{transform:scale(0) rotate(-12deg)}70%{transform:scale(1.12) rotate(3deg)}100%{transform:scale(1) rotate(0)}}
@keyframes lp-draw{to{stroke-dashoffset:0}}
@keyframes lp-float{0%{transform:translateY(0) rotate(0);opacity:0}10%{opacity:.85}100%{transform:translateY(-115vh) rotate(40deg);opacity:0}}
@media (prefers-reduced-motion:reduce){#lp-cel,#lp-cel *{animation-duration:.01ms!important;animation-iteration-count:1!important}#lp-cel .lp-p{display:none}#lp-cel .lp-check{stroke-dashoffset:0}}
</style>
<div id="lp-cel" role="dialog" aria-modal="true" aria-labelledby="lp-cel-t">
  <div class="lp-card">
    <svg class="lp-icon" viewBox="0 0 64 64" aria-hidden="true"><defs><linearGradient id="lpg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fb7185"/><stop offset="1" stop-color="#e11d48"/></linearGradient></defs><path d="M32 57S6 41.500 6 22.500C6 14 12.500 8 20 8c5 0 9 2.500 12 7 3-4.500 7-7 12-7 7.500 0 14 6 14 14.500C58 41.500 32 57 32 57z" fill="url(#lpg)"/><path class="lp-check" d="M21 31l8 8 15-16" fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <h2 id="lp-cel-t">{$title}</h2>
    <p class="lp-what">Ya tienes {$what}</p>
    <p class="lp-phrase" id="lp-cel-phrase" aria-live="polite">Gracias por hacer latir este proyecto.</p>
    <span class="lp-order" aria-label="Número de orden">ORDEN {$order}</span>
    <a class="lp-cta" href="{$href}">{$cta}</a>
    <button type="button" class="lp-close" id="lp-cel-close">Cerrar</button>
  </div>
</div>
<script nonce="{$n}">
(function(){
  var box=document.getElementById('lp-cel'); if(!box)return;
  var still=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;
  var prev=document.activeElement, cta=box.querySelector('.lp-cta'), close=document.getElementById('lp-cel-close');
  function shut(){box.remove();document.body.style.overflow='';if(prev&&prev.focus)prev.focus();}
  document.body.style.overflow='hidden'; cta.focus();
  close.addEventListener('click',shut);
  box.addEventListener('keydown',function(e){
    if(e.key==='Escape'){shut();return;}
    if(e.key==='Tab'){var f=[cta,close],i=f.indexOf(document.activeElement);e.preventDefault();f[(i+(e.shiftKey?-1:1)+f.length)%f.length].focus();}
  });
  var phrases=['Gracias por hacer latir este proyecto.','Un detalle especial para alguien especial.','El amor se demuestra con pequeños grandes detalles.','Lo bonito se cuida, y tú lo estás haciendo.','Tu historia ya tiene un lugar donde brillar.'],i=0,ph=document.getElementById('lp-cel-phrase');
  if(!still){
    setInterval(function(){ph.style.opacity='0';setTimeout(function(){i=(i+1)%phrases.length;ph.textContent=phrases[i];ph.style.opacity='1';},500);},3600);
    var sy=['♥','♡','✦','♥'];
    for(var k=0;k<22;k++){var s=document.createElement('span');s.className='lp-p';s.textContent=sy[k%sy.length];
      s.style.left=(Math.random()*96)+'%';s.style.fontSize=(12+Math.random()*20)+'px';s.style.color=k%4===2?'#f59e0b':(k%2?'#fb7185':'#f9a8d4');
      s.style.animationDuration=(6+Math.random()*6)+'s';s.style.animationDelay=(Math.random()*6)+'s';box.appendChild(s);}
  }
})();
</script>
HTML;
    }
}
