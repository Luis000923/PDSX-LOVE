# Plantillas — requisitos y contrato técnico

Carpeta de plantillas propias de LovePages (pdsx.org/love). Aquí viven los dos formatos
que el motor sabe renderizar. Este README es el contrato: si una plantilla no lo cumple,
no se renderiza o se rechaza en la subida.

Estado actual: **100 plantillas HTML** (`*.html`) y **1 PHP dinámica**
(`php/flores-interactivas/`).

- Motor HTML y campos: `src/Template.php`
- Motor PHP y lista blanca: `src/PhpTemplate.php`
- Fotos que puede pedir la plantilla: `src/TemplateImages.php`
- Fila del marketplace: tabla `templates` en `database/schema.sql`

> `.htaccess` de esta carpeta es `Require all denied`: **las plantillas nunca se sirven
> como archivo**. El navegador solo recibe el HTML ya renderizado por `public/view.php`.
> Todo lo que el usuario carga va a `public/uploads/` y las imágenes a
> `public/assets/tpl/<slug>/`, que sí son públicos por diseño.

---

## 1. Elegir formato

| | **A) HTML con `{{campos}}`** | **B) PHP dinámica** |
|---|---|---|
| Dónde | `templates/<slug>.html` | `templates/php/<slug>/index.php` |
| Un archivo | Sí | No: carpeta + assets |
| Campos disponibles | Los 4 de `Template::FIELDS` | Los mismos + `days_together`, `images`, `image_count` |
| Interactividad | JS inline con nonce | JS y CSS propios, sin nonce |
| Sube por el panel | No (se agrega al repo) | Sí, como `.zip` |
| Límite de peso | — | 200 archivos, 1 MiB c/u, 8 MiB total |
| Para qué | Gratuitas y de membresía | Monedas, hiper-interactivas |

**Regla de decisión:** si solo necesitas `your_name`, `partner_name`, `start_date` y
`message`, es HTML. Si necesitas interactividad en vivo, un contador calculado, un juego
de declaración o un layout que `{{campos}}` no puede dar, es PHP.

---

## 2. Requisitos comunes (obligatorios en ambos formatos)

### 2.1 Mobile-First

- Base de **360 px sin prefijo**; `sm:`/`md:`/`lg:` solo amplían.
- Nunca anchos fijos en px: `w-full max-w-*`.
- `text-base` (o mayor) en los inputs — con 16 px o menos iOS hace zoom al enfocar.
- Objetivos táctiles de **≥ 44 px**.
- Imágenes con `loading="lazy"` y `width`/`height` explícitos (evita saltos de layout).
- Toda animación pesada debe degradar bien:

  ```css
  @media (prefers-reduced-motion: reduce) { /* desactivar lo costoso */ }
  ```

### 2.2 CSP — el punto que más rompe plantillas

`src/bootstrap.php` envía esta política:

```
script-src 'self' 'nonce-<nonce>' https://cdn.tailwindcss.com
style-src  'self' 'unsafe-inline' https://fonts.googleapis.com
font-src   https://fonts.gstatic.com
img-src    'self' data: <avatar>
connect-src 'self'
base-uri   'none'
```

Consecuencias prácticas:

- **Todo `<script>` inline necesita el nonce** o no se ejecuta:
  `<script nonce="{{{nonce}}}">` en HTML, `<script nonce="<?= $nonce ?>">` en PHP.
- `<style>` inline **no** lo necesita (`style-src` lleva `'unsafe-inline'`), pero añadirle
  el nonce no hace daño.
- **No puedes meter CDN de scripts** que no sea `cdn.tailwindcss.com`. Cualquier cosa que
  no esté en la lista de arriba queda bloqueado.
- Nada de `fetch()`/XHR a dominios externos: `connect-src` es solo `'self'`.
- Nada de `<base>` ni de `<form>` con `action` externa (`base-uri 'none'`,
  `form-action 'self' https://*.wompi.sv`).

### 2.3 Nombre del archivo (solo HTML)

`Template::render()` valida el basename contra `^[a-z0-9-]+\.html$`. Solo minúsculas,
dígitos y guiones; **sin guiones bajos, sin tildes, sin espacios**. Las 100 plantillas
actuales cumplen.

El `slug` de la fila en la BD no tiene por qué coincidir con el nombre del archivo
(son columnas distintas: `slug` y `file`), pero conviene que sí.

---

## 3. Formato A — HTML con `{{campos}}`

### 3.1 Sintaxis

| Escritura | Qué hace |
|---|---|
| `{{clave}}` | Inserta el valor **escapado** (HTML-safe). |
| `{{{clave}}}` | Inserta el valor **crudo**. Solo para lo que genera el servidor. |
| `{{#if img_x}}…{{/if}}` | Muestra el bloque si la foto `x` existe. |
| `{{#unless img_x}}…{{/unless}}` | Muestra el bloque si la foto `x` **no** existe. |

`{{message}}` además convierte los saltos de línea en `<br>`.

**Regla dura:** `{{{…}}}` es exclusivamente para `nonce` y `ad_slot`. Meter ahí un dato de
usuario anula el escapado y es una XSS directa.

### 3.2 Placeholders disponibles

**Escapados** (`{{...}}`):

| Placeholder | Origen | Notas |
|---|---|---|
| `your_name` | `Template::FIELDS` | máx. 60 |
| `partner_name` | `Template::FIELDS` | máx. 60 |
| `start_date` | `Template::FIELDS` | máx. 10, formato `Y-m-d` |
| `message` | `Template::FIELDS` | máx. 1000, con `\n` → `<br>` |
| `days_together` | **calculado** | días desde `start_date`; `0` si la fecha no es válida |
| `img_<clave>` | fotos de la página | URL, o **cadena vacía** si no hay |
| `img_count` | calculado | cuántas fotos hay |

**Crudos** (`{{{...}}}`): `nonce`, `ad_slot`.

No hay más campos. Si la plantilla necesita otro, no es un problema de la plantilla: es
candidata a PHP dinámica.

### 3.3 Fotos

Las fotos las sube el usuario a su página, no la plantilla. Se piden declarando un
`image_spec` en la fila (ver §5), y llegan como `img_<clave>`.

Todo placeholder que no exista se resuelve a **cadena vacía**, así que una plantilla
puede referenciar una foto sin romperse si el usuario no subió ninguna. Aun así, envuelve
las imágenes en `{{#if img_x}}` para no dejar un `<img>` roto.

```html
{{#if img_detalle}}
  <img src="{{img_detalle}}" alt="Detalle" loading="lazy" width="600" height="800">
{{/unless}}
  <div class="p-8 text-center">Sin fotos todavía</div>
{{/if}}
```

Ojo: los condicionales **no se anidan** y se resuelven en **un solo pase**, así que no
pues poner un `{{#if}}` dentro de otro.

### 3.4 Ejemplo mínimo válido

```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{your_name}} &amp; {{partner_name}}</title>
  <script nonce="{{{nonce}}}" src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-white text-gray-900">
  <main class="w-full max-w-xl mx-auto px-4 py-10 text-center">
    <h1 class="text-[clamp(1.5rem,6vw,2.25rem)] font-bold break-words">
      {{your_name}} &amp; {{partner_name}}
    </h1>
    <p class="text-2xl font-bold text-rose-600">{{days_together}} días</p>
    <p class="mt-4 break-words">{{message}}</p>
    {{{ad_slot}}}
  </main>
  <script nonce="{{{nonce}}}">
    // aquí va el JS; siempre con el nonce puesto
  </script>
</body>
</html>
```

### 3.5 Sobre el escáner de HTML

`src/HtmlScanner.php` (lista blanca de CDN, etiquetas prohibidas, reglas de JS) **no se
aplica a las plantillas de esta carpeta**: solo valida las que sube el usuario
(`kind = 'utpl'`, vía `src/UserHtml.php` y `src/Creators.php`).

Aun así, conviene quedarse dentro de esos límites porque es lo que usan el 100% de las
plantillas actuales y mantiene el porte a plantilla de usuario abierto:

- CDN permitidos: `cdn.tailwindcss.com`, `cdn.jsdelivr.net`, `cdnjs.cloudflare.com`,
  `fonts.googleapis.com`, `fonts.gstatic.com`.
- Etiquetas prohibidas: `iframe`, `object`, `embed`, `form`, `base`, y derivadas.
- Máximo 512 KB de HTML.

---

## 4. Formato B — PHP dinámica

### 4.1 Cómo se ejecuta

`PhpTemplate::render()` **no** incluye el archivo con el contexto normal. Lo ejecuta
dentro de un ámbito cerrado con exactamente esta firma:

```php
function (string $__entry, array $t, string $nonce, string $ad_slot, string $assets): void
```

Eso significa que en `index.php` **solo existen esas cuatro variables**. Nada de
`$_GET`, ni funciones globales no permitidas, ni clases fuera de la lista blanca. El
análisis se hace sobre los *tokens* de PHP, no sobre el texto, para que no se burle un
comentario ni una cadena.

> Esto es defensa en profundidad, **no un sandbox**. Solo un admin puede subirlas, pero
> el código se revisa antes de subirlo.

### 4.2 ⚠️ `$t` ya viene escapado — no lo escapes otra vez

Este es el error más fácil de cometer al trabajar en este formato.

`PhpTemplate::render()` aplica `htmlspecialchars(…, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`
a los campos **antes** de ponerlos en `$t`, y convierte `\n` en `<br>` en `message`. Las
URLs de `$t['images']` también vienen escapadas.

**Correcto:**

```php
<h1><?= $t['your_name'] ?></h1>
<p><?= $t['message'] ?></p>
```

**Incorrecto — produce doble escapado** (`Tomás` → `Tom&aacute;s` → `Tom&amp;aacute;s`):

```php
<h1><?= htmlspecialchars($t['your_name']) ?></h1>
```

La plantilla de referencia `php/flores-interactivas/index.php` lo confirma: usa
`<?= $t['your_name'] ?>` y **cero** llamadas a `htmlspecialchars`.

> Nota: `.claude/agents/template_generator_agent.md` dice lo contrario ("aún sin
> escapar por ti"). Esa instrucción está desactualizada respecto a `src/PhpTemplate.php`.
> Si la estás siguiendo y ves `&amp;` duplicado en producción, ahí está el motivo.

### 4.3 Contenido de `$t`

| Clave | Tipo | Notas |
|---|---|---|
| `your_name` | `string` | escapado, máx. 60 |
| `partner_name` | `string` | escapado, máx. 60 |
| `start_date` | `string` | `Y-m-d` |
| `message` | `string` | escapado, `\n` ya convertido a `<br>` |
| `days_together` | `string` | días transcurridos; usa `intval()` si comparas |
| `images` | `array<string,string>` | `clave => URL`, ambas escapadas |
| `image_count` | `int` | número de fotos |

Las demás variables son **texto ya escapado y no reinterpretables**, porque el análisis
bloquea llamadas dinámicas:

| Variable | Qué es | Uso |
|---|---|---|
| `$nonce` | nonce CSP del request | `nonce="<?= $nonce ?>"` en cada `<script>` |
| `$ad_slot` | bloque de anuncios ya resuelto | `<?= $ad_slot ?>` tal cual, sin envolverlo |
| `$assets` | URL base a `public/assets/tpl/<slug>/` | `<?= $assets ?>js/main.js` — **termina en `/`** |

Para assets propios, siempre `$assets . 'archivo.js'`. Nunca `__DIR__` (no es una URL) ni
rutas absolutas.

### 4.4 Lista blanca de funciones (modelo de *allowlist*)

**Lo que no está en la lista se rechaza**, aunque no esté prohibido explícitamente. No
añadir una función a una lista negra: se añade a `ALLOWED_FUNCTIONS`, tras revisar que sea
pura (sin E/S, sin red, sin proceso, sin acceso a sesión).

Funciones permitidas, por grupo:

- **Cadenas**: `strlen`, `strtolower`, `strtoupper`, `ucfirst`, `ucwords`, `lcfirst`, `trim`, `ltrim`, `rtrim`, `str_repeat`, `str_pad`, `str_replace`, `str_ireplace`, `substr`, `substr_count`, `str_contains`, `str_starts_with`, `str_ends_with`, `str_split`, `wordwrap`, `sprintf`, `number_format`, `nl2br`, `htmlspecialchars`, `htmlentities`, `html_entity_decode`, `htmlspecialchars_decode`, `strip_tags`, `rawurlencode`, `rawurldecode`, `urlencode`, `urldecode`, `mb_*` (`mb_strlen`, `mb_substr`, `mb_strtolower`, `mb_strtoupper`, `mb_convert_case`, `mb_str_split`), `implode`, `explode`, `join`, `preg_*` (`preg_match`, `preg_match_all`, `preg_replace`, `preg_quote`, `preg_split`), `strcmp`, `str_word_count`, `ctype_*`
- **Arrays** (sin parámetro *callable*): `count`, `in_array`, `array_key_exists`, `array_keys`, `array_values`, `array_merge`, `array_unique`, `array_slice`, `array_reverse`, `array_flip`, `array_sum`, `array_product`, `array_fill`, `array_combine`, `array_pad`, `array_chunk`, `array_diff*`, `array_intersect*`, `array_column`, `array_key_first`, `array_key_last`, `range`, `end`, `reset`, `current`, `key`
- **Tipos**: `is_array`, `is_string`, `is_int`, `is_float`, `is_bool`, `is_numeric`, `is_null`, `is_scalar`, `gettype`, `intval`, `floatval`, `strval`, `boolval`
- **Matemáticas**: `abs`, `ceil`, `floor`, `round`, `intdiv`, `fmod`, `pow`, `sqrt`, `max`, `min`
- **Fecha**: `date`, `gmdate`, `time`, `checkdate`
- **JSON**: `json_encode`, `json_decode`

### 4.5 Prohibido dentro de `index.php`

- **Superglobales**: `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_SESSION`, `$_SERVER`,
  `$_FILES`, `$_ENV`, `$GLOBALS`.
- **Llamadas dinámicas**: `$f()`, `$$v`, backticks, `eval`.
- **Funciones con estos prefijos**: `curl_`, `stream_`, `socket_`, `mysqli_`, `pg_`,
  `sqlite_`, `ftp_`, `ssh2_`, `posix_`, `pcntl_`, `proc_`, `apache_`, `opcache_`,
  `session_`. Y también `exec`, `system`, `file_get_contents`, `unserialize`, `assert`.
- **`new`** con nada más que `DateTime`, `DateTimeImmutable`, `DateTimeZone`, `DateInterval`.
- **`include`/`require`** fuera de `__DIR__ . '/ruta/literal.php'` dentro de la propia
  carpeta.

### 4.6 Estructura y límites del paquete

```
slug.zip
├── index.php                # obligatorio, en la raíz
├── style.css                # opcional
├── assets/
│   ├── main.js
│   └── img/foto.webp
└── includes/ayuda.php       # opcional, solo literal
```

Si el zip trae **una sola carpeta raíz** que lo envuelve todo, se quita automáticamente;
aun así, deja `index.php` en la raíz.

- `index.php` en la raíz: obligatorio, o falla con *"Falta index.php en la raíz de la plantilla."*
- `slug` conforme a `^[a-z0-9][a-z0-9_-]{2,39}$` (3–40 caracteres).
- Máximo **200 archivos**, **1 MiB** por archivo, **8 MiB** descomprimido.
- Extensiones permitidas: `css, js, png, jpg, jpeg, webp, gif, ico, woff, woff2, json, txt`.
  Cualquier otra extensión (`.php` aparte del `index.php`, `.svg`, `.html`…) se rechaza.
- Sin rutas con `..`, sin rutas absolutas, sin archivos ocultos, sin enlaces simbólicos.

Al subirlo por el panel, los assets estáticos se copian a `public/assets/tpl/<slug>/`, que
es de donde los sirve `$assets`.

---

## 5. La fila en la base de datos

Toda plantilla necesita su fila en `templates` (`database/schema.sql`). Clasificación:

| Tipo | `is_premium` | `membership_unlocks` | `price_coins` | Cómo se desbloquea |
|---|---|---|---|---|
| **Gratis** | `0` | `0` | `0` | Cualquiera, sin costo. |
| **Membresía** | `1` | `1` | > 0 (respaldo) | Gratis dentro del cupo mensual del plan; al agotarlo, cae a `price_coins` con `template_discount_pct` aplicado. |
| **Monedas** | `1` | `0` | > 0 | Cualquiera paga con monedas; la membresía **nunca** la regala, solo es gasto. |

Columnas que importan:

- `kind` — `html` (archivo con `{{campos}}`) o `php` (carpeta en `templates/php/<slug>`).
- `category` — una de `Template::CATEGORIES`: `romantico`, `aniversario`, `cumpleanos`,
  `declaracion`, `especial`.
- `file` — **basename** del archivo HTML (no la ruta). Para `kind = 'php'` se usa el slug.
- `description` (máx. 500), `thumbnail` (basename en `public/assets/thumbs/`),
  `is_active`, `price_usd`.

**Bug de negocio a evitar:** nunca dejes `price_coins = 0` con `is_premium = 1` y
`membership_unlocks = 0`; sería una plantilla premium que además es gratis.

### `image_spec` (JSON, opcional)

Declara qué fotos pide la plantilla. `NULL` = no pide ninguna.

```json
{
  "slots": [
    { "key": "detalle", "label": "Una foto de detalle", "required": true },
    { "key": "juntos", "label": "Los dos juntos", "required": false }
  ],
  "repeat": { "prefix": "recuerdo", "label": "Recuerdo {n}", "min": 3, "max": 6 }
}
```

- `key` conforme a `^[a-z][a-z0-9_]{0,29}$`; sin repetir.
- `repeat.prefix` conforme a `^[a-z][a-z0-9_]{0,24}$`.
- Tope global: **12 fotos** por página (`TemplateImages::HARD_MAX`).

Cada slot declarado aparece como `img_<key>` en la plantilla, más `img_count`.

---

## 6. Checklist antes de dar por buena una plantilla

```bash
# 1. Sintaxis PHP válida (solo formato PHP)
php -l templates/php/<slug>/index.php

# 2. Sin superglobales — debe salir vacío
grep -nE '\$_(GET|POST|REQUEST|COOKIE|SESSION|SERVER|FILES|ENV)\b|\$GLOBALS' \
  templates/php/<slug>/index.php

# 3. Sin funciones peligrosas — debe salir vacío
grep -nE '\b(exec|system|eval|assert|unserialize)\(|file_get_contents\(|curl_|mysqli_|session_' \
  templates/php/<slug>/index.php

# 4. Nada de htmlspecialchars sobre $t (ya viene escapado) — debe salir vacío
grep -n 'htmlspecialchars(\$t' templates/php/<slug>/index.php

# 5. Todo <script> inline lleva nonce
grep -c 'nonce="<?= \$nonce ?>"' templates/php/<slug>/index.php

# 6. Nombre del HTML válido
echo "<slug>.html" | grep -qE '^[a-z0-9-]+\.html$' && echo ok

# 7. Nada de datos de usuario en las triple llaves
grep -nE '\{\{\{[^}]*(your_name|partner_name|message|start_date)' templates/<slug>.html
```

Luego, prueba visual con datos demo vía `public/preview.php` a 390 px de ancho. Para
comprobar el resultado, `marionette` ya está en el PATH:

```bash
marionette browser --headless goto http://localhost:8080/preview.php --wait-loaded
marionette browser --headless snapshot
```

---

## 7. Errores frecuentes

| Síntoma | Causa |
|---|---|
| El JS inline no corre | Le falta `nonce` en el `<script>`. |
| Aparecen `&amp;amp;` o `Tom&aacute;s` | Doble escapado: `htmlspecialchars()` sobre un `$t` que ya viene escapado. |
| `&amp;` visible en pantalla | Se usó `&` literal en vez de `&amp;` en el HTML. |
| `{{mi_variable}}` aparece tal cual en la página | Placeholder inexistente: solo hay los 7 de §3.2. |
| `Plugin Tailwind no carga` | Falta `src="https://cdn.tailwindcss.com"` o el `nonce` en esa etiqueta. |
| El bloque `{{#if img_x}}` no se oculta | Los condicionales no se anidan y se resuelven en un solo pase. |
| `Falta index.php en la raíz de la plantilla` | El zip lo anidaba en una carpeta y con más de una carpeta raíz. |
| `Plantilla no encontrada` (PHP) | El slug no cumple `^[a-z0-9][a-z0-9_-]{2,39}$` o falta `templates/php/<slug>/index.php`. |
| `Plantilla inválida` (HTML) | El `file` de la fila no cumple `^[a-z0-9-]+\.html$`. |
| Falla en el panel de subida | Enlace en un CDN fuera de la lista blanca, etiqueta prohibida, o HTML de más de 512 KB (esto solo aplica a plantillas de usuario). |
