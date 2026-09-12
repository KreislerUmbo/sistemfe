# Plan — PDF de cotización mejorado, con marca personalizable por agencia

> Parte de: `plan-modulo-cotizaciones-reservas.md` §3.1.1/§5.4 (PDF comercial de
> alternativa). Este documento es el plan de la mejora visual acordada con el
> usuario (mockup aprobado en Artifact "Cotización DKM Xplore", 04-sep-2026) —
> AÚN NO es un brief para pegar en Claude Code, es el diseño a confirmar antes
> de escribir ese brief (mismo criterio que el resto del proyecto: plan primero,
> ejecución después).
> **✅ EJECUTADO y MERGEADO (07-sep-2026, commit `5b00fdd`)** — el brief que tradujo este
> diseño (`PEGAR-EN-CLAUDE-CODE-mejora-pdf-cotizacion-cliente.md`) ya se ejecutó y se borró
> por superado (11-sep-2026, ver `historial-archivo.md`). Este documento queda activo como
> referencia técnica del diseño acordado, no como pendiente de ejecución.

## 1. Qué problema resuelve

Hoy el PDF comercial de una alternativa (`AlternativaController::pdf()` →
`resources/views/pdf/agencia-viajes/alternativa.blade.php`) es funcional pero
plano: sin fotos de los tours, sin el membrete real que la agencia ya usa en
sus documentos Word (logo, franja de afiliaciones MINCETUR/APAVIT/PromPerú,
pie con contacto — ver los 3 documentos reales de referencia, Alto Mayo/
Cusco/Panamá), y sin diferenciar visualmente Local/Nacional/Internacional más
allá del campo `categoria` de `paquetes_plantilla`.

**Restricción nueva, clave para el diseño:** `sistemafe` es multi-tenant — el
mismo sistema sirve a distintas agencias, cada una con su propio logo, colores
y (en algunos casos) su propia hoja membretada ya diseñada por un tercero. La
solución **no puede** ser un PDF con la marca de una sola agencia hardcodeada
— tiene que ser configurable por tenant, con un resultado profesional incluso
para la agencia que no tiene todavía un diseño de membrete propio.

## 2. Alcance

**Entra:**
- Nuevo bloque de configuración de marca del PDF, por agencia (tenant), con
  vista previa en vivo.
- Fotos del tour/paquete en el PDF (portada + galería de itinerario), con
  recorte controlado (§4.5) — las fotos ya se pueden cargar en destinos hoy
  (confirmado por el usuario, 04-sep-2026), falta usarlas en el PDF sin que
  salgan estiradas.
- Matriz de precios de hoteles compacta (ya semi-resuelto en backend, falta
  el estilo), más una sección nueva "Fotos referenciales de los hoteles"
  con 3 fotos por hotel (fachada + 1-2 de habitación) — mismo patrón que ya
  usa la agencia a mano en sus documentos Word — ver §4.5.
- Cinta de categoría (Local/Nacional/Internacional) con color propio.
- Sigue siendo **un click** desde el cotizador — no se agrega ningún paso
  manual nuevo al flujo de generar el PDF, todo lo de este plan es lo que pasa
  *detrás* de ese botón.

**No entra (fuera de alcance de esta sesión):**
- Editor visual de plantillas tipo "arma tu propio layout" — eso es un
  proyecto en sí mismo (piénsese en algo como el editor de Canva). Lo que se
  ofrece es personalización por **campos** (logo, colores, textos) más un
  **override total** por imagen para el caso DKM Xplore (ver §4.2).
- PDF de itinerario operativo y PDF de políticas (`plan-modulo-cotizaciones-
  reservas.md` §5.4) — quedan con su diseño actual, esta sesión es solo el PDF
  comercial de alternativa.

## 3. Verificación previa obligatoria (antes de tocar nada)

Confirmado por el documento de diseño, **no confirmado todavía contra el
código real** — la sesión de ejecución debe abrir estos archivos primero, no
asumir:

- Fotos de destino: el usuario confirma (04-sep-2026) que ya se pueden cargar
  fotos en destinos — falta verificar el nombre real de la tabla/columna
  (¿`paquetes_plantilla.fotos`? ¿una tabla `destino_fotos` aparte, con
  relación a `paquete_plantilla` u otra entidad?) y si guarda el archivo
  original solamente o ya algo de metadata (orden, cuál es portada). El plan
  de §4.5 asume que hay que **agregar** columnas/tabla para marcar
  portada/destacadas y para las rutas de los recortes — confirmar contra el
  esquema real antes de migrar.
- ¿Con qué librería se genera el PDF hoy (`dompdf`, `snappy`/wkhtmltopdf,
  otra)? Cambia qué CSS es seguro usar (dompdf tiene soporte limitado de
  flexbox/grid y **no soporta `object-fit`** — ver §4.5, es la razón por la
  que el recorte de imágenes se resuelve en el servidor y no en el CSS del
  PDF).
- ¿Qué librería de manipulación de imágenes ya está instalada? (Intervention
  Image es lo más común en Laravel; si no está, confirmar si GD o Imagick
  están disponibles en el servidor antes de asumir cuál usar.)
- ¿`configuracion_agencia` ya tiene algún campo de logo/color? (Sabemos que
  ya tiene `formato_descuento_pdf`/`mostrar_descuento_como_linea` — revisar
  si hay algo más de branding ya empezado antes de duplicar.)
- Confirmar si existe ya alguna noción de "logo del tenant" en otro lugar del
  sistema (ej. el core de facturación/SUNAT probablemente ya tiene logo de
  empresa para el comprobante electrónico — **reusar ese dato si ya existe**,
  no crear un logo separado solo para el PDF de cotización).

## 4. Diseño de la personalización por agencia

### 4.1 Configuración estructurada (default, cubre a cualquier agencia nueva)

```
configuracion_agencia_pdf   (o campos nuevos directo en configuracion_agencia,
                              a decidir en la sesión de ejecución según cómo
                              esté hoy esa tabla)
 - logo_path                     (nullable — si no hay logo, se usa el nombre
                                   comercial en texto con la tipografía del
                                   sistema)
 - color_primario                (hex, default de sistema si no configuran)
 - color_secundario              (hex)
 - color_categoria_local         (hex, default azul)
 - color_categoria_nacional      (hex, default el color_primario)
 - color_categoria_internacional (hex, default un tercer tono distinto)
 - nombre_comercial
 - eslogan                        (nullable, ej. "¡Que comience la aventura!")
 - direccion, telefono, whatsapp, email, sitio_web
 - redes: json [{red: facebook|instagram|tiktok, usuario}]
 - mostrar_fotos_tour: boolean    (apagar si una agencia no tiene fotos
                                    cargadas y prefiere no mostrar espacio vacío)
 - mostrar_afiliaciones: boolean  (logos tipo MINCETUR/APAVIT/PromPerú — ver
                                    §4.3, es lo único realmente específico de
                                    agencias de viajes peruanas, no generalizar
                                    de más)
```

Con solo esto, cualquier agencia nueva ya tiene un PDF prolijo (logo +
colores propios + estructura del mockup aprobado) sin haber diseñado nada a
mano — mismo principio que el resto del sistema (`configuracion_agencia`
como fuente de valores por defecto, editable, nunca hardcodeado).

### 4.2 Override total por imagen (para agencias con membrete ya diseñado)

Caso real: DKM Xplore ya tiene su membrete completo diseñado por un tercero
(el que se extrajo del Word real para el mockup). Obligarlos a reconstruir
ese diseño campo por campo sería un downgrade. Para ese caso:

```
configuracion_agencia_pdf (campos adicionales)
 - imagen_header_custom   (nullable — PNG/JPG a ancho completo de página)
 - imagen_footer_custom   (nullable — idem)
```

Si estos dos campos están cargados, el PDF usa esas imágenes tal cual como
franja superior/inferior (exactamente como el mockup lo hizo con el membrete
real de DKM Xplore) y **ignora** logo/colores/contacto de §4.1 para esa zona
— el resto del documento (galería, itinerario, matriz de hoteles, precio)
sigue el mismo layout para todos, solo cambia qué corona la página. Sin
override, se generan header/footer desde los campos estructurados de §4.1.

**Por qué esta combinación y no una sola cosa:** una agencia sin diseño
previo necesita algo automático (§4.1); una agencia con inversión ya hecha en
su membrete (§4.2) necesita que ese trabajo no se pierda. No se ataca con una
sola solución porque el caso real (DKM Xplore) y el caso general (agencia
nueva del sistema) no son el mismo problema.

### 4.3 Logos de afiliación (MINCETUR/APAVIT/PromPerú)

Específico de cómo se presentan las agencias de viajes peruanas — no es
parte de "la marca de la agencia" sino credenciales del rubro. Se resuelve
como catálogo simple, no campo de texto libre:

```
afiliaciones_turismo (catálogo central, igual patrón que proveedor_tipos)
 - codigo: mincetur | apavit | promperu | otro
 - nombre, logo_path (logo oficial, mismo para todos los tenants)

configuracion_agencia_afiliaciones (tabla puente)
 - configuracion_agencia_id
 - afiliacion_id
 - numero_registro   (nullable, ej. "AGENCIA DE VIAJES Y TURISMO REGISTRADA")
```

Cada agencia marca cuáles tiene (checkbox), el sistema arma la franja con los
logos oficiales — no hay que subir esos logos por agencia, son del catálogo.

### 4.4 Pantalla de configuración (admin, un lugar nuevo o sección nueva)

`configuracion_agencia` ya es una pantalla existente (sección de la agencia)
— esto es una pestaña nueva ahí, **"Marca del PDF"**, no una pantalla aparte:
formulario de los campos de §4.1 + selector de afiliaciones de §4.3 + upload
opcional de §4.2, con **vista previa en vivo** (mismo mockup del Artifact
aprobado, pero renderizado con los valores reales que el usuario va
completando — recalcula al vuelo, sin guardar todavía). Esto es lo que hace
que "personalizar" sea real y no una promesa: el vendedor/admin ve el PDF
completo antes de generarlo por primera vez.

### 4.5 Fotos: recorte, cantidad y posición (decisión 04-sep-2026)

**Por qué no se resuelve con CSS:** en HTML normal `object-fit: cover`
recorta una imagen sin deformarla dentro de un tamaño fijo. dompdf (motor de
PDF más probable de este proyecto, a confirmar en §3) **no soporta
`object-fit`** — si se le manda una foto vertical dentro de un `<img>` con
ancho/alto fijos, la estira. Por eso el recorte se hace **en el servidor, al
momento de subir la foto**, no en el CSS de la plantilla del PDF.

**Mecánica:** al guardar una foto de un destino/tour, además del original se
generan variantes ya recortadas (Intervention Image/GD/Imagick, según lo que
confirme §3) a las proporciones fijas de abajo — recorte centrado por
defecto (crop desde el centro, sin importar si la foto es más alta que ancha
o al revés). El PDF consume siempre la variante recortada correspondiente,
nunca el original — así nunca hay estiramiento sin importar cómo suba la
foto el vendedor.

**Espacios fijos de la plantilla** (fijos en el sistema, no configurables
por agencia — es lo que mantiene el PDF viéndose profesional en todos los
tenants; lo único configurable es *qué fotos* entran, no el tamaño/cantidad):

| Espacio | Proporción del recorte | Cuántas fotos | Posición respecto al texto |
|---|---|---|---|
| Portada del tour | 4:3 (una grande) + 2 chicas apiladas | 1 principal + 2 secundarias (3 total) | Bloque de imagen a ancho completo, debajo del título/antes de los datos de la cotización — no al costado del texto |
| Galería de itinerario | 4:3, en fila de 4 | hasta 4 | Bloque aparte después de listar los días — **no** intercalada foto-por-día: un día con poco texto y foto alta queda desbalanceado si se mezclan |
| Cada hotel, en la sección de fotos | 4:3, tira de 3 iguales | **3: fachada + 1-2 de habitación** | En un bloque aparte, **después** de la tabla de precios — no dentro de la fila (ver diseño final abajo) |

**Cómo se elige qué fotos entran (tour/destino):** en la misma pantalla donde
ya se cargan fotos del destino, se marca una como **portada** (radio, una
sola) y hasta **4 como destacadas para PDF** (checkbox, límite duro de 4 en
el formulario) — el resto de las fotos cargadas queda disponible en el
sistema pero no se usa automáticamente en el documento. Esto evita que un
tour con 20 fotos genere un PDF de tamaño/páginas impredecible.

**Cómo se elige qué fotos entran (hotel):** entidad distinta — las fotos de
un hotel cuelgan del proveedor/`OpcionHotel`, no del tour. Se etiquetan por
**tipo** al subir, no solo se marcan como destacadas: `fachada` (máx. 1) y
`habitacion` (máx. 2) — el formulario de carga de fotos del hotel pide ese
tipo explícito porque el orden en el PDF es siempre el mismo (fachada
primero, habitaciones después) y no tiene sentido dejarlo a elección libre
del vendedor cada vez. Si un hotel solo tiene 1 o 2 fotos cargadas (no las 3
completas), la tira se arma con las que haya — nunca se deja un casillero en
blanco ni se estira una sola foto para llenar el espacio de tres.

**Diseño final del bloque de hoteles (decisión 05-sep-2026, reemplaza la
versión anterior de "tarjeta por hotel"):** se probó primero meter las 3
fotos dentro de la fila de la tabla de precios y no entra bien — 3 fotos
angostan demasiado la fila. La solución no es una tarjeta nueva por hotel,
es **separar lo que ya está separado en los documentos reales de la
agencia**: los 3 documentos de referencia (Alto Mayo/Cusco/Panamá) ya traen
primero la tabla de precios por hotel×habitación, compacta, y **después**
una sección aparte titulada *"Fotos referenciales de los hoteles"* con el
nombre de cada hotel y sus fotos debajo — más datos prácticos (check-in,
check-out, política de infantes). Eso es exactamente lo que se necesita, ya
resuelto por la propia agencia, solo que hoy se arma a mano en Word:

```
Sección "Opciones de hotel"
 1. Tabla de precios (como ya está en el mockup) — sin fotos, compacta,
    para comparar de un vistazo: hotel × doble/triple/familiar.
 2. Sección nueva "Fotos referenciales de los hoteles" (mismo nombre que ya
    usan en sus Word reales — no hace falta inventar uno):
    por cada hotel de la tabla de arriba, en el mismo orden:
      - nombre del hotel (encabezado chico)
      - tira de 3 fotos 4:3 (fachada + 1-2 de habitación)
      - línea de datos prácticos si están cargados (check-in/check-out,
        política de niños/infantes) — mismo dato que ya escriben a mano
        hoy, ahora como campo de `configuracion_hotel`/`proveedor_tarifa`
        en vez de texto libre repetido en cada Word.
```

Con esto la tabla de precios sigue siendo compacta y fácil de comparar (su
único trabajo), y las fotos van donde el cliente las espera ver en detalle
— igual que en los documentos que la agencia ya entrega hoy, pero generado
automáticamente en vez de armado a mano tour por tour. El Artifact de
referencia (§8) solo tenía la tabla de precios sin esta segunda sección —
falta agregarla ahí para que el mockup quede alineado con esta decisión.

**Nice-to-have, no v1:** reposicionar el punto focal del recorte por foto
(útil si el recorte centrado corta mal una cara o un letrero) — se deja para
una iteración futura si el recorte centrado automático resulta insuficiente
en la práctica; no bloquea esta implementación.

## 5. Flujo de generación (sigue siendo un click)

```
Cotizador → botón "Generar PDF" (ya existe)
  → AlternativaController::pdf($alternativaId)
    → resuelve configuracion_agencia_pdf del tenant actual (ya resuelto por
      middleware de tenancy, no hay que pasar tenant_id a mano)
    → arma header/footer: imagen_header_custom si existe, si no genera desde
      campos estructurados (logo + colores + contacto + afiliaciones)
    → arma cinta de categoría con el color de configuracion_agencia_pdf según
      paquetes_plantilla.categoria del origen del ítem (o de la alternativa,
      a definir si una alternativa puede mezclar categorías — ver §7)
    → arma portada + galería desde las fotos marcadas portada/destacadas
      (§4.5), usando las variantes ya recortadas — si mostrar_fotos_tour=true
      y hay fotos marcadas; si no hay ninguna marcada, la sección no se
      muestra (no deja espacio vacío)
    → arma tabla de precios desde opciones_hotel/opciones_hotel_tarifas
      (ya existe el dato, falta el estilo de tabla) + sección "Fotos
      referenciales de los hoteles" debajo, con tira de hasta 3 fotos
      recortadas 4:3 por hotel (fachada + habitación/es) si existen —
      sección que no se muestra si ningún hotel de la alternativa tiene
      fotos cargadas
    → renderiza alternativa.blade.php con todo lo anterior → PDF
```

Nada de esto agrega un paso al vendedor — sigue siendo el mismo botón de
siempre. Toda la personalización vive en la configuración de la agencia, se
resuelve una sola vez y aplica a todas las cotizaciones futuras de ese tenant.

## 6. Compatibilidad hacia atrás

Ningún tenant existente tiene hoy `configuracion_agencia_pdf` cargada. Con
todos los campos nullable y defaults de sistema (colores neutros + nombre
comercial ya existente del tenant + afiliaciones ninguna marcada +
`mostrar_fotos_tour=true` pero sin romper si no hay fotos — simplemente no
se muestra la sección de galería), el PDF sale mejor que el actual desde el
día uno, sin que nadie tenga que configurar nada — la personalización es una
mejora opcional encima de un default ya decente, no un requisito para que el
PDF funcione.

## 7. Decisiones de cierre (05-sep-2026)

- **Categoría mixta en una alternativa — decidido:** la cinta usa la
  categoría "más alta" presente en la alternativa, con el orden
  internacional > nacional > local (si hay un solo ítem internacional entre
  varios locales, la alternativa completa se presenta como internacional —
  es la lectura comercial correcta: es el componente que más define el
  precio/complejidad del viaje). No se omite la cinta en mezcla, para que
  toda alternativa tenga siempre una cinta.
- **Todo lo demás que quedaba abierto (esquema real de fotos, motor de PDF,
  otros consumidores de `alternativa.blade.php`) no se decide en el chat —
  se verifica contra el código real** en el Paso 0 del brief de ejecución
  (`PEGAR-EN-CLAUDE-CODE-mejora-pdf-cotizacion-cliente.md`), mismo criterio
  que el resto del proyecto: estas preguntas tienen una sola respuesta
  correcta que está en el repo, no algo para decidir de antemano.

## 8. Referencia visual

Mockup aprobado por el usuario: Artifact "Cotización DKM Xplore"
(04-sep-2026) — dos páginas (portada + itinerario/precio), membrete real de
DKM Xplore extraído de sus documentos Word (`Nacional PDKM-CZ - 03 CUSCO...
.docx`, carpeta de Google Drive del proyecto), fotos reales del tour Cusco.
Sirve como referencia de layout/tono, no como el HTML final — el PDF real se
construye en Blade contra el motor de PDF que ya usa el sistema (a confirmar,
§3).

---

## Historial

| Fecha | Cambio |
|---|---|
| 04-sep-2026 | Primera versión. Mockup visual aprobado por el usuario en Artifact "Cotización DKM Xplore". Se agrega el requisito de personalización por agencia (multi-tenant) — no estaba en el pedido original, surgió al preguntar cómo se construye en código. Diseño de configuración híbrida: campos estructurados (§4.1) + override por imagen completa (§4.2) + catálogo de afiliaciones de turismo (§4.3). Quedan abiertos 4 puntos (§7) antes de poder escribir el brief de ejecución para Claude Code. |
| 04-sep-2026 | Se agrega §4.5: manejo de fotos (recorte, cantidad, posición). Usuario confirma que las fotos de destino ya se pueden cargar — el problema pendiente era el estiramiento por proporción. Decisión: recorte server-side (no CSS, dompdf no soporta `object-fit`) a proporciones fijas por espacio de la plantilla (portada 4:3 x3, galería 4:3 x4, hotel 4:3 x1), con selección de portada + hasta 4 destacadas por tour. Cierra el punto de fotos en §7; queda abierto solo confirmar el esquema real de la tabla de fotos. |
| 04-sep-2026 | Corrección de §4.5: el hotel lleva **3 fotos** (fachada + 1-2 de habitación), no 1 sola. Primer intento de solución: tarjeta por hotel (fotos arriba, precio debajo). |
| 05-sep-2026 | Diseño final del bloque de hoteles: se descarta la tarjeta por hotel — en vez de eso, la tabla de precios se mantiene compacta tal cual estaba, y se agrega una sección nueva **"Fotos referenciales de los hoteles"** después de la tabla (nombre + tira de 3 fotos + datos prácticos por hotel). Es el mismo patrón que la agencia ya arma a mano en sus documentos Word reales — se automatiza en vez de inventar un layout nuevo. Mockup del Artifact actualizado a 3 páginas reflejando esto. |
| 05-sep-2026 | Cierre de §7: decidido el criterio de categoría mixta (internacional > nacional > local). El resto de los puntos abiertos (esquema real de fotos, motor de PDF, otros consumidores del blade) pasan al Paso 0 del brief de ejecución `PEGAR-EN-CLAUDE-CODE-mejora-pdf-cotizacion-cliente.md` — se verifican contra el código, no se deciden de antemano. Brief escrito y listo para pegar en una sesión nueva de Claude Code. |
