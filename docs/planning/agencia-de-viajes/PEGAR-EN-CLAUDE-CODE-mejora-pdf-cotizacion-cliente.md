# Mejora del PDF de cotización — fotos, membrete real y marca por agencia

Pegar este brief completo en una sesión nueva de Claude Code. Rama nueva
(`feature/mejora-pdf-cotizacion-cliente`). Es una mejora de varias partes —
razonable dividirla en 2-3 commits (branding/config, fotos, blade/vista) en
vez de uno solo, pero un solo chat/sesión.

Diseño completo y decisiones ya tomadas en
`plan-mejora-pdf-cotizacion-cliente.md` (proyecto "Agencia de Viajes",
carpeta `agencia-de-viajes/`) — este brief resume qué construir; si algo acá
no queda claro, ese documento tiene el razonamiento completo.

## Paso 0 — Verificar contra el código real (obligatorio, no asumir)

El diseño se hizo sin acceso al repo. Antes de escribir una sola línea,
confirmar esto y ajustar el resto del brief según lo que se encuentre —
**no** proceder con los nombres de abajo si el código dice otra cosa:

1. **Fotos de destino/tour:** ¿en qué tabla/columna viven hoy? (candidatos:
   `paquetes_plantilla.fotos`, una tabla `destino_fotos`/`paquete_fotos`
   aparte). ¿Guardan algo más que la ruta del archivo (orden, cuál es
   portada)? El diseño de §4.5 del plan asume que hace falta agregar: una
   marca de "portada" (una por tour) y una marca de "destacada para PDF"
   (hasta 4) — confirmar si ya existe algo parecido antes de migrar de más.
2. **Motor de generación de PDF:** ¿`dompdf`, `snappy`/wkhtmltopdf, otro?
   Busca en `composer.json` y en `AlternativaController::pdf()`. Esto decide
   si el CSS grid/flex de la vista de referencia (ver §8 del plan, Artifact
   "Cotización DKM Xplore") se puede usar tal cual o hay que reescribirlo
   con tablas + posicionamiento absoluto (dompdf no soporta bien flex/grid
   ni `object-fit` — ver punto 4).
3. **Librería de imágenes disponible:** ¿Intervention Image ya instalado?
   Si no, ¿GD o Imagick disponibles en el servidor? Necesario para el
   recorte server-side de fotos (punto 4).
4. **¿`object-fit` funciona en el motor de PDF real?** Si es dompdf, no.
   Confirmar antes de decidir si el recorte se hace 100% server-side (plan
   §4.5) o si el motor real sí soporta recorte por CSS y se puede simplificar.
5. **`configuracion_agencia`:** columnas actuales — evitar duplicar si ya
   hay algo de logo/color. Buscar también si el core de facturación/SUNAT ya
   tiene un logo de tenant guardado en otro lado (reusar si existe).
6. **Otros consumidores de `alternativa.blade.php`:** grep del nombre del
   archivo/vista en el proyecto — confirmar que ningún voucher o reporte
   interno depende de su layout actual antes de reescribirlo.

Si algún punto de arriba contradice el diseño de este brief, seguir lo que
diga el código y avisar el cambio en el PR — no forzar el diseño original
sobre una realidad distinta.

## Qué construir

### 1. Migraciones — configuración de marca por tenant

```php
// campos nuevos en configuracion_agencia, o tabla configuracion_agencia_pdf
// si la tabla actual ya está muy cargada — decidir según el Paso 0.5
$table->string('logo_path')->nullable();
$table->string('color_primario', 7)->nullable();
$table->string('color_secundario', 7)->nullable();
$table->string('color_categoria_local', 7)->nullable();
$table->string('color_categoria_nacional', 7)->nullable();
$table->string('color_categoria_internacional', 7)->nullable();
$table->string('eslogan')->nullable();
$table->json('redes_sociales')->nullable(); // [{red, usuario}]
$table->boolean('mostrar_fotos_tour')->default(true);
$table->boolean('mostrar_afiliaciones')->default(false);
$table->string('imagen_header_custom')->nullable();
$table->string('imagen_footer_custom')->nullable();
```

`nombre_comercial`, `direccion`, `telefono`, `email`, `sitio_web` — usar los
que ya existan en `configuracion_agencia`/`tenants`, no duplicar si ya están.

```php
// catálogo de afiliaciones de turismo (Mincetur, Apavit, PromPerú...)
Schema::create('afiliaciones_turismo', function (Blueprint $table) {
    $table->id();
    $table->string('codigo')->unique(); // mincetur|apavit|promperu|otro
    $table->string('nombre');
    $table->string('logo_path');
    $table->timestamps();
});

Schema::create('configuracion_agencia_afiliaciones', function (Blueprint $table) {
    $table->id();
    $table->foreignId('configuracion_agencia_id')->constrained()->cascadeOnDelete();
    $table->foreignId('afiliacion_id')->constrained('afiliaciones_turismo');
    $table->string('numero_registro')->nullable();
    $table->timestamps();
});
```

Seed de `afiliaciones_turismo` con Mincetur/Apavit/PromPerú (logos oficiales
— pedir los archivos si no están ya en el repo en algún lado; no inventar
diseños de esos logos).

### 2. Fotos de tour — portada y destacadas (según lo que confirme Paso 0.1)

Si la tabla real de fotos no tiene ya un lugar para esto, agregar:
```php
$table->boolean('es_portada')->default(false);   // una sola por tour
$table->boolean('destacada_pdf')->default(false); // hasta 4 por tour, validar en el controller
$table->string('ruta_recorte_portada')->nullable();  // 4:3
$table->string('ruta_recorte_galeria')->nullable();  // 4:3
```
Al subir/editar una foto: generar los recortes 4:3 centrados con la librería
que confirme el Paso 0.3, guardar las rutas. Validar en el form request que
no se marquen más de 4 `destacada_pdf` por tour ni más de 1 `es_portada`.

### 3. Fotos de hotel — fachada + habitación

Entidad distinta (cuelga de `OpcionHotel`/proveedor de alojamiento, no del
tour):
```php
$table->enum('tipo_foto', ['fachada', 'habitacion'])->default('habitacion');
$table->string('ruta_recorte')->nullable(); // 4:3
```
Regla de negocio en el controller/form request: máximo 1 `fachada`, máximo 2
`habitacion` por hotel — no dejar subir una cuarta si ya hay 3.

### 4. Vista `alternativa.blade.php` — rediseño

Reconstruir contra el layout de referencia (Artifact "Cotización DKM
Xplore", 3 páginas — pedir el HTML de referencia si hace falta, está en el
historial de la conversación de diseño) traducido a lo que el motor real
soporte (Paso 0.2/0.4):

- **Header/footer:** si `imagen_header_custom`/`imagen_footer_custom` están
  cargadas, usarlas tal cual a ancho completo. Si no, generar header/footer
  desde campos estructurados (logo o nombre comercial, colores, contacto,
  franja de afiliaciones si `mostrar_afiliaciones=true`).
- **Cinta de categoría:** color según `categoria` de los ítems de la
  alternativa — internacional > nacional > local si hay mezcla (decidido en
  el plan §7), tomando el color de `configuracion_agencia_pdf` según
  corresponda.
- **Portada:** foto marcada `es_portada` + hasta 2 `destacada_pdf` restantes
  como secundarias. Si `mostrar_fotos_tour=false` o no hay ninguna marcada,
  omitir el bloque completo (no dejar espacio vacío).
- **Galería de itinerario:** hasta 4 fotos `destacada_pdf`, en bloque aparte
  después de los días — no intercalada por día.
- **Tabla de precios de hotel:** como está hoy en estructura de datos
  (`opciones_hotel`/`opciones_hotel_tarifas`), solo mejorar el estilo.
- **Sección "Fotos referenciales de los hoteles":** después de la tabla de
  precios, un bloque por cada hotel de la tabla (mismo orden) con su nombre
  + tira de sus fotos (`fachada` primero, luego `habitacion`) + check-in/
  check-out si están cargados en el proveedor/tarifa. Si un hotel no tiene
  ninguna foto cargada, omitir su bloque (no dejar el hueco de 3 fotos
  vacío).
- El resto (incluye/no incluye, resumen de precio, condiciones) sigue el
  contenido actual, solo se actualiza el estilo visual.

### 5. Pantalla "Marca del PDF" (frontend, `admin-start-kit`)

Pestaña nueva dentro de la sección de configuración de la agencia ya
existente:
- Formulario de los campos de branding (punto 1) + checkboxes de
  afiliaciones + upload opcional de header/footer custom.
- Vista previa en vivo: recalcula el layout de referencia con los valores
  que el usuario va completando (no hace falta que sea el PDF real
  renderizado — un preview HTML equivalente alcanza, guardado para no
  bloquear la implementación en tener el PDF real generándose en vivo).

### 6. Compatibilidad hacia atrás

Todos los campos nuevos nullable/con default. Ningún tenant existente tiene
`configuracion_agencia_pdf` cargada — el PDF debe salir correcto con todos
los defaults del sistema (colores neutros, sin fotos si no hay marcadas, sin
afiliaciones) sin que nadie configure nada.

## Verificación mínima antes de dar por cerrado

- Generar el PDF de una alternativa real de un tenant **sin** ninguna
  configuración de marca cargada — debe salir prolijo con los defaults, sin
  errores por campos null.
- Generar el PDF de una alternativa con fotos de tour cargadas (una
  vertical, una horizontal) — confirmar que ninguna sale estirada.
- Generar el PDF de una alternativa con un hotel con 1 sola foto cargada
  (no las 3) — confirmar que la tira se ve bien con 1 sola, sin huecos.
- Test de regresión sobre el fix de leak de mayorista
  (`PEGAR-EN-CLAUDE-CODE-fix-leak-mayorista-pdf.md`) — confirmar que el
  rediseño de la vista no reintrodujo el dato del proveedor real en el PDF
  del cliente.
