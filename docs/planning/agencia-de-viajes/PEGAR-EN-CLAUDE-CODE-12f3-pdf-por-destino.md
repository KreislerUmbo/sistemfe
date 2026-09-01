# Brief para Claude Code — Sesión 12f-3: PDF agrupado por destino

> Referencia: `plan-ejecucion-multidestino-mayoristas.md` fila 12f,
> `auditoria-arquitectonica-agencia-viajes.md` §7.1 punto 6. Depende de
> 12f-1 (`origin/main` `84316e4`) y 12f-2 (`origin/main` `00024d4`).
> Tercera y última sub-sesión del bloque 12f — al cerrar, la fila 12f
> completa pasa a `[x]` en la hoja de ruta.

---

## 0. Decisiones tomadas antes de escribir este brief

Confirmadas con el usuario en la ronda de preguntas de esta misma sesión
(01-sep-2026), con un cambio de alcance real descubierto en el camino:

1. **Itinerario**: se agrupa por destino con encabezado de sección
   (nombre + fechas) antes de listar los días de ESE destino — esto es
   lo único que pedía explícito el punto 6 del audit
   (`itinerarioAlternativa()` ya concatenaba tours; ahora agrupa por
   `alternativa_destino_id` real en vez de inferir del orden de
   aparición de `tour_origen_id`). Offset de día (`dia_referencial`) se
   reinicia en cada bloque de destino — mismo criterio que ya rige en el
   cotizador desde 12f-2.
2. **"Incluye"**: se agrupa igual, con el mismo encabezado de destino por
   bloque (solo nombres, sigue sin precio — no cambia en eso).
3. **CAMBIO DE ALCANCE MAYOR, pedido por el usuario en esta sesión — NO
   estaba en el audit ni en el plan de ejecución original**: la tabla
   "Precio" del PDF comercial **deja de mostrar precio por ítem
   individual**. Se elimina la tabla de filas por ítem por completo — la
   sección "Precio" queda reducida al bloque de totales que ya existía
   (`Descuento aplicado` si `config->mostrar_descuento_como_linea`, +
   `Total` final), sin ninguna fila intermedia.
   - Aplica **solo a ítems normales** de `AlternativaItem` (tours,
     traslados, servicios, hoteles, guías, pasajes sueltos, manuales,
     mayorista). **NO aplica a la matriz de opciones de hotel de M5**
     (`plan-matriz-hoteles-cotizador.md`, sin construir todavía) — ahí el
     precio por opción es funcional (el cliente elige entre opciones
     comparando precio), no solo informativo. Si M5 se construye después
     y toca este mismo PDF, es una decisión de diseño aparte, a
     confirmar en su propio brief — no asumir que esta sesión ya la
     resolvió.
   - Con 2+ destinos: el total sigue siendo **uno solo para toda la
     alternativa, sin subtotal por destino** — confirmado explícito con
     el usuario, a propósito distinto del panel de precio del cotizador
     (que sí muestra subtotal por destino, 12f-2). El desglose por
     destino en el PDF vive únicamente en Itinerario/Incluye, nunca en
     dinero.
   - `formato_descuento_pdf` (`tachado`/`separado`, config existente)
     queda sin efecto en esta vista — era una configuración pensada para
     decorar el precio por ítem, que ya no existe acá. No se borra el
     campo de `ConfiguracionAgencia` (lo pueden seguir usando otras
     vistas), solo deja de leerse en `alternativa.blade.php`.
4. **Con 1 solo destino** (el caso de casi todos los datos reales hoy):
   Itinerario e Incluye se ven exactamente igual que hoy (sin encabezado
   de destino de más — mismo criterio que `mostrarChipsDestino` en el
   cotizador, no agregar ruido visual cuando no hace falta). La tabla de
   Precio por ítem desaparece siempre, sin importar cuántos destinos
   tenga la alternativa — ese cambio no es condicional a multi-destino.

---

## 1. Backend — `AlternativaController`

### `itinerarioAlternativa(Alternativa $alternativa): array`

Reescribir para agrupar por `alternativa_destinos` (ordenados por
`orden`) en vez de inferir del orden de aparición de `tour_origen_id` en
los ítems:

- Para cada `AlternativaDestino` de la alternativa (en orden), filtrar
  los ítems de la alternativa cuyo `alternativa_destino_id` resuelva a
  ese destino — **tratar `null` como perteneciente al PRIMER destino**
  (mismo fallback que `itemsDelDestinoActivo` en `editar.vue`, 12f-2, por
  los ítems legacy que 12c dejó sin `alternativa_destino_id` resuelto).
- Dentro de cada destino, misma lógica que hoy: tours distintos
  (`tour_origen_id` único, en orden de aparición dentro de ESE destino),
  offset de día acumulado que se reinicia a 0 al entrar a un destino
  nuevo.
- Devolver un array de bloques, no un array plano de pasos:
  ```php
  [
    'destino_id' => int,
    'destino_nombre' => string, // destinoAtractivo->nombre ?? destino_texto ?? 'Destino'
    'fecha_inicio' => ?Carbon,
    'fecha_fin' => ?Carbon,
    'pasos' => [ ['dia' => int, 'hora' => ?string, 'descripcion' => string, 'tour_nombre' => string], ... ],
  ]
  ```
- Omitir del array los destinos sin ningún paso de itinerario (mismo
  criterio que hoy: sin `tour_origen_id` en sus ítems, no hay nada que
  narrar para ese destino).

### `pdf(string $id)`

- Construir también `$incluyePorDestino` con la misma agrupación
  (reutilizar el filtro de arriba o extraerlo a un helper privado común
  si queda limpio) — un array de bloques `{ destino_nombre, fecha_inicio,
  fecha_fin, nombres: string[] }`.
- `$items` (el array plano actual con nombre/precio/etc.) se puede seguir
  computando igual que hoy para los cálculos server-side de
  `$totalOriginal`/`$total`/`$descuentoMonto` (la tabla de totales sigue
  necesitando esos números) — solo deja de pasarse a la vista para
  render de filas por ítem. Confirmar si conviene seguir mandando
  `'items' => $items` a la vista (por si `formato_descuento_pdf` u otra
  cosa lo lee en otro punto del blade) o si ya no hace falta en absoluto
  una vez reescrito el blade — decidir al tocar el archivo, no dejarlo
  "por si acaso" si de verdad no se usa.
- Pasar `'itinerario' => $bloques` (nueva forma) e
  `'incluyePorDestino' => $incluyePorDestino` al `loadView()`.

---

## 2. Frontend — `resources/views/pdf/agencia-viajes/alternativa.blade.php`

- Sección **Itinerario**: iterar `$itinerario` (ahora array de bloques).
  Si `count($itinerario) > 1`, imprimir un encabezado por bloque (nombre
  del destino + rango de fechas si existen) antes de sus días; si es 1
  solo bloque, omitir el encabezado y listar los días igual que hoy (sin
  el `foreach` externo agregando ruido visual).
- Sección **Incluye**: iterar `$incluyePorDestino` con el mismo criterio
  (encabezado solo si hay más de 1 bloque con ítems).
- Sección **Precio**: eliminar la tabla `<table class="items">` completa
  (filas por ítem). Dejar solo el `<table class="totales">` que ya
  existe, sin cambios en su lógica (`mostrar_descuento_como_linea`,
  `Total` final). Puede convenir fusionar el título de sección
  "Precio"/"Total" — usar criterio propio al tocar el HTML, no hace
  falta preguntar de nuevo por el copy exacto.
- Revisar que ningún estilo CSS quede huérfano (`.precio-tachado`,
  `.descuento-nota` — si ya no se usan en ningún lado del blade,
  quitarlos; si `formato_descuento_pdf` de verdad deja de leerse acá,
  esas clases no tienen dónde aplicarse).

---

## 3. Tests

- Test de regpresión explícito (ya lo pide §16 del plan de ejecución):
  con 1 solo destino, el PDF generado no debe tener ningún encabezado de
  destino visible en Itinerario/Incluye — mismo output narrativo que
  antes de 12f-3 (además de ya no tener precio por ítem, que aplica
  siempre).
- Test con 2+ destinos reales (alternativa con `alternativa_destinos` >
  1, ítems reales repartidos): confirmar que `itinerarioAlternativa()`
  devuelve un bloque por destino, con offset de día reiniciado en cada
  uno, y que el bloque del segundo destino no arrastra el offset del
  primero.
- Test de regresión ya existente en el plan (§16, "El PDF comercial
  nunca imprime el proveedor mayorista") — confirmar que sigue en verde,
  no se toca `resolverNombreItemPdf()` en esta sesión (el leak C1 es un
  frente aparte, `PEGAR-EN-CLAUDE-CODE-fix-leak-mayorista-pdf.md`, no
  mezclar).
- Confirmar que la tabla de Precio por ítem ya no aparece en el PDF
  generado (buscar ausencia de la clase `items` o de montos por fila en
  el HTML/texto extraído, según cómo se pruebe DomPDF en este proyecto).

---

## 4. Verificación contra datos reales (`agencia-demo`)

- Generar el PDF de al menos 1 alternativa real de `agencia-demo` con 1
  solo destino — confirmar visualmente que no aparece ningún encabezado
  de destino de más, y que la tabla de precio por ítem ya no está.
- Crear (y luego revertir) una alternativa de prueba con 2 destinos
  reales + ítems repartidos entre ambos, generar su PDF, confirmar
  encabezados de Itinerario/Incluye por destino con offset de día
  correcto, y un único total al final sin desglose por destino.
- Revertir cualquier dato de prueba creado para la verificación antes de
  cerrar la sesión (mismo criterio que 12e/12f-2).

---

## 5. Al cerrar

- Actualizar `plan-hoja-de-ruta-ejecucion.md`: sub-estado de 12f-3 a
  `[x]`, y la fila 12f completa pasa a `[x]` (las 3 sub-sesiones
  cerradas).
- Commit, merge fast-forward a `main`, suite completa en verde, push a
  `origin/main`, borrar rama local.
- Actualizar memoria (`project_agencia_viajes_multidestino_mayoristas_roadmap.md`
  + `MEMORY.md`) con el cierre de 12f-3 y de la fila 12f completa, y con
  el cambio de alcance de "Precio sin desglose por ítem" documentado —
  es un cambio de comportamiento visible para cualquier cliente que ya
  reciba estos PDFs, vale la pena que quede memorizado con claridad para
  no reabrirlo por error en una sesión futura (ej. M5).
