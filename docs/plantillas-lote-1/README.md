# Lote 1 · 5 plantillas nuevas

Cinco plantillas listas para subir al panel de administración (`/admin/templates.php`). Las plantillas y sus
miniaturas viven en **volúmenes de Docker** (contenido de runtime), así que **no viajan con el despliegue de la
imagen**: en cada entorno (local y producción) se dan de alta por el panel, una sola vez.

## Contenido de esta carpeta

```
plantillas-lote-1/
├── historia-numeros.html        (HTML, un solo archivo)
├── razones-te-amo.html          (HTML)
├── sorpresa-cumple.html         (HTML)
├── quieres-ser-mi-novia.html    (HTML)
├── flores-interactivas.zip      (PHP interactiva: index.php en la RAÍZ del zip)
└── miniaturas/                  (PNG 800×480; el panel NO acepta SVG, solo JPG/PNG/WEBP)
    ├── historia-numeros.png · razones-te-amo.png · sorpresa-cumple.png
    └── quieres-ser-mi-novia.png · flores-interactivas.png
```

## Valores para cada plantilla

| Plantilla | Identificador (slug) | Categoría | Tipo | Archivo | «Premium» | «Cupo mensual» | Costo en monedas |
|---|---|---|---|---|---|---|---|
| Nuestra Historia en Números | `historia-numeros` | Romántico | HTML | `historia-numeros.html` | ✅ | ✅ | 15 |
| Razones por las que te amo | `razones-te-amo` | Aniversario | HTML | `razones-te-amo.html` | ⬜ | ⬜ | 25 |
| Sorpresa de Cumpleaños | `sorpresa-cumple` | Cumpleaños | HTML | `sorpresa-cumple.html` | ✅ | ✅ | 20 |
| ¿Quieres ser mi novia/o? | `quieres-ser-mi-novia` | Declaración | HTML | `quieres-ser-mi-novia.html` | ⬜ | ⬜ | 10 |
| Flores Amarillas Interactivas | `flores-interactivas` | Especial | PHP | `flores-interactivas.zip` | ✅ | ✅ | 30 |

Precio individual (USD): **0.00** en todas (no hay compra suelta).

**Qué significa cada combinación**

- **Solo monedas** (Razones, ¿Quieres ser mi novia/o?): sin «Premium» ni «Cupo mensual». Las puede usar cualquiera,
  pagando su costo en monedas al crear (y al renovar) la página. Ninguna membresía las regala.
- **Membresía con cupo** (las otras tres): marcadas «Premium» + «Cupo mensual». Cada plan desbloquea gratis un número
  de plantillas *distintas* al mes (Romántico 1, Pareja 3, Eterno 6); agotado el cupo (o sin membresía) se usan pagando
  su costo en monedas. Volver a usar una plantilla ya desbloqueada ese mes no gasta cupo.

Descripciones sugeridas (máx. 200 caracteres):

- Nuestra Historia en Números: *Contador en vivo de días, horas y minutos, y un jardín de Polaroids en un diseño oscuro y elegante.*
- Razones por las que te amo: *12 razones para amarte en tarjetas que se abren una a una, con corazones flotantes de fondo.*
- Sorpresa de Cumpleaños: *Una caja de regalo animada se abre con confeti y revela tu mensaje de felicitación.*
- ¿Quieres ser mi novia/o?: *El clásico botón Sí y No, donde el No se escapa. Una declaración divertida e infalible.*
- Flores Amarillas Interactivas: *Un jardín de flores amarillas que crece y florece mientras revela tu mensaje, paso a paso.*

## Cómo subirlas (una por una)

1. Entra como administrador a **Admin → Plantillas** y baja hasta el formulario **Nueva plantilla**.
2. Rellena: **Nombre**, **Identificador** (opcional; escríbelo tal cual está en la tabla para que coincida), **Categoría**, **Descripción**,
   **Precio individual** `0`, **Costo en monedas** y marca **Plantilla premium** / **Cupo mensual** según la tabla.
3. **Tipo HTML**: en *Archivo HTML* elige el `.html` correspondiente.
   **Tipo PHP** (solo Flores): elige *Tipo* = PHP y en *Carpeta PHP en .zip* sube `flores-interactivas.zip`.
   El análisis de seguridad lo revisa al subirlo; este zip ya se comprobó y pasa sin errores.
4. En *Miniatura* sube el `.png` del mismo nombre (carpeta `miniaturas/`).
5. Pulsa **Crear plantilla**. Comprueba con **ver ejemplo** y con la vista previa de la Galería.

## Estructura del ZIP de la plantilla PHP

```
flores-interactivas.zip
└── index.php        ← en la raíz, sin carpeta contenedora
```

Reglas del analizador (`src/PhpTemplate.php`): solo funciones de la lista blanca, sin llamadas dinámicas, sin
superglobales, sin `header()`, `include` solo hacia archivos hermanos, `new` solo para `DateTime`. No declares funciones
propias: sus llamadas se rechazan. Los valores de `$t` ya llegan escapados; no los vuelvas a escapar.

## Cupo mensual por plan

`membership_tiers.template_unlocks_per_month` (Romántico 1, Pareja 3, Eterno 6). Aún no hay pantalla en el panel para
cambiarlo; se ajusta por SQL, por ejemplo:

```sql
UPDATE membership_tiers SET template_unlocks_per_month = 4 WHERE slug = 'pareja';
```
