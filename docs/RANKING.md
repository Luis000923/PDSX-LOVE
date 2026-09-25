# Top de donadores, alias y premios

Código: `src/Ranking.php` (clasificación y alias), `src/Awards.php` (premios e insignias), `public/top.php` (página pública),
`public/admin/awards.php` (panel), `bin/close_awards.php` (cierre por cron). Los premios de creadores se describen en `docs/CREADORES.md`.

## Qué cuenta como apoyo

Pagos con `status = APPROVED`, `fulfilled_at` no nulo, monto > 0 y método `WOMPI` o `MANUAL`. **No** cuentan cupones (PROMO / monto 0), pendientes, rechazados,
anulados ni no cumplidos. Los usuarios suspendidos no aparecen.

## Periodos y desempates

- **Mes**: mes calendario en hora de El Salvador (UTC-6 fijo, `Access::monthBounds`). **Histórico**: todo el tiempo.
- Orden: mayor total → primer pago cumplido más antiguo del periodo → id de usuario menor.

## Privacidad

Público (`Ranking::publicTop`, `standing`): nunca correo ni id; solo el **alias** de quien activó `show_in_rankings`; si no, «Donador anónimo».
Cada usuario ve su propia posición aunque no esté en el top. El correo real solo se ve en el panel admin (`Ranking::rows(..., withEmail: true)`).
Ocultarse es siempre gratis. El alias (`users.display_name`) es único (índice `uq_users_display_name`), no puede parecer un enlace ni suplantar a la plataforma.
Cambiar un alias existente cuesta `Ranking::aliasChangeCost()` monedas (ajuste `alias_change_cost`); el primero y los cambios solo de mayúsculas/tildes son gratis.

## Premios

Configuración en `settings.awards_config` (JSON, validado por `Awards::validateConfig`, defecto `Awards::DEFAULTS`, editable en `admin/awards.php`):

| Clave | Defecto |
|---|---|
| `top_n` | 3 (máx. 10) |
| `month_coins` | 100 / 50 / 25 |
| `min_month_cents` | 100 ($1.00) |
| `milestones` | $10→10, $25→30, $50→70, $100→160 monedas (crecientes, máx. 6) |

- Cierre mensual (`Awards::closeMonth`): top N del mes con al menos `min_month_cents`; insignias `mecenas_1/2/3` o `top_mes`.
- Hitos de apoyo acumulado (`Awards::evaluateMilestones`): al cumplirse un pago (`Payments::fulfill` → `onPaymentFulfilled`, misma transacción, con SAVEPOINT para no romper el pago) y al abrir `top.php`.
- **Idempotencia**: todo pasa por `Awards::grant()`: `INSERT IGNORE` en `award_grants` (`UNIQUE user_id + grant_key`) y solo si la fila es nueva se acreditan monedas (`Coins::credit`) e insignia (`user_badges`).
- **No retroactivo**: solo meses desde `settings.awards_launch_month`.

## Cierre perezoso y cron

`Awards::closePendingMonths()` (una consulta si no hay nada pendiente) se ejecuta al visitar `top.php`, `colaboradores.php` y el resumen de admin; protegido con `GET_LOCK` y
`awards_closed_months`; cada mes se cierra en su propia transacción (donadores + gancho de creadores). Cron recomendado: `5 6 1 * * php bin/close_awards.php`
(el mes de El Salvador termina a las 06:00 UTC). Ejecutarlo varias veces no duplica nada.
