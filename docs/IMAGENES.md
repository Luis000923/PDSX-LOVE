# Fotos de usuario en plantillas

Cada plantilla puede pedir hasta 12 fotos. El usuario las sube en `create.php`; se validan, se recodifican a WebP
(sin metadatos, máx. 1600 px) y se sirven desde `public/uploads/sites/{slug}/{16hex}.webp`.

## Declaración (`templates.image_spec`, JSON)

```json
{"max":6,"min":0,
 "slots":[{"key":"principal","label":"Foto principal","required":true},
          {"key":"linea_1","label":"Foto de la línea de tiempo 1"}],
 "repeat":{"prefix":"foto","label":"Foto {n}","min":0,"max":12}}
```

- `slots`: fotos fijas; `key` debe cumplir `^[a-z][a-z0-9_]{0,29}$`, `label` 1-80 caracteres, `required` opcional.
- `repeat`: genera `foto_1..foto_N` (`min` = cuántas son obligatorias). Claves desconocidas o repetidas invalidan la spec.
- Tope duro: 12 fotos por página; `max` no puede ser menor que las fotos declaradas.
- JSON inválido = la plantilla no pide fotos (y se registra en el log). El panel admin rechaza el guardado.

### Plantilla HTML
Admin > Plantillas > campo «Fotos que pide (JSON)» (alta y edición). Producción para `historia-numeros`:

```sql
UPDATE templates SET image_spec = '{"max":5,"repeat":{"prefix":"foto","label":"Foto {n}","min":0,"max":5}}' WHERE slug = 'historia-numeros';
```

Marcadores (mismo escape que el resto): `{{img_<clave>}}` (URL, vacío si no hay foto) y `{{img_count}}`.
Condicionales sin anidar, un solo pase, sin evaluar nada: `{{#if img_foto_1}}...{{/if}}` y `{{#unless img_foto_1}}...{{/unless}}`.

### Plantilla PHP
`manifest.json` en la RAÍZ del .zip con la clave `images` (mismo formato). Se lee al instalar y al reemplazar el bundle.
En `index.php`: `$t['images']` (clave => URL ya escapada) y `$t['image_count']`.

## Seguridad
`finfo` + `getimagesize` coherentes (JPEG/PNG/WebP), <= 5 MB, <= 25 MP y lado <= 10000 antes de decodificar, decodificación
GD y recodificación siempre; nombre aleatorio, nunca del cliente. Total procesado <= 30 MB por página. Las URLs las genera
solo el servidor (slug y archivo validados por regex). Borrar página/cuenta llama a `Sites::purgeFiles($slug)`.

## Despliegue
- `docker/php.ini`: `upload_max_filesize=6M`, `post_max_size=40M`, `max_file_uploads=24` (en hosting compartido, equivalente en `.user.ini`).
- PHP con GD + WebP y `fileinfo`. Sin `exif`: la orientación se lee con un parser propio.
- `public/uploads` y `storage/` escribibles por el usuario del servidor (`storage/tmp_uploads` se crea solo, 0700; si no puede, usa `sys_get_temp_dir()`).
- Contrato para B/`view.php`: la consulta debe traer `s.id AS site_id, s.slug AS site_slug`; `Template::renderRow` carga las fotos con esas claves.
