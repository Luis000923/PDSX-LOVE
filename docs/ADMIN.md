# Panel de administración

Todas las páginas de `public/admin/` empiezan con `Admin::guard()` y usan `src/admin_layout.php`.

```php
admin_page_start('Título', 'clave', 'Subtítulo', '<a class="' . ADMIN_BTN_CLS . '" href="...">Acción</a>');
// contenido
admin_page_end();
```

Claves de navegación (`$current`): `index`, `payments`, `users`, `pages`, `templates`, `promos`, `settings`, `activity`.
El título ya se pinta como `<h1>` y los `flash()` se muestran solos; no repitas ninguno de los dos.

## Helpers

| Función | Uso |
|---|---|
| `admin_icon($name, $variant, $size)` | `assets/img/admin/{name}[-dark\|-accent].svg`; `light` = sin sufijo (fondo oscuro) |
| `admin_badge($label, $tone)` | tonos: slate, green, amber, rose, blue, violet |
| `admin_status_badge($status)` | estado de pago Wompi |
| `admin_stat(...)`, `admin_empty(...)` | tarjeta KPI y estado vacío |
| `admin_pager($page, $pages, 'admin/x.php', $query)` | paginación `?page=` conservando filtros |
| `admin_money($cents)`, `admin_date($utc, $withTime)` | `$1,234.50` y fecha en hora de El Salvador |
| `admin_table_open($headers, $opts)` / `admin_table_close()` | tabla limpia; `['Monto' => 'right']` alinea a la derecha; `caption` en `$opts` |
| `admin_errors($errors)` | lista de errores de validación |

Constantes de clases: `ADMIN_INPUT_CLS`, `ADMIN_BTN_CLS`, `ADMIN_BTN_GHOST_CLS`, `ADMIN_BTN_DANGER_CLS`, `ADMIN_CARD_CLS`.

## Reglas

- Cero JS inline (CSP con nonce); el menú móvil usa `<details>`.
- Los gráficos del dashboard son SVG generados en PHP, con tabla alternativa.
- Ajustes globales en `settings.php`; el registro de acciones (`admin_audit`) se ve en `activity.php`.
