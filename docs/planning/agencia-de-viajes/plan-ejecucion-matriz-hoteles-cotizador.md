# Plan de ejecución — Matriz de opciones de hotel en el cotizador

> Este documento traduce `plan-matriz-hoteles-cotizador.md` (diseño
> CERRADO, 6 rondas, 18 preguntas resueltas con el usuario) a sesiones
> concretas de Claude Code, mismo formato que
> `plan-ejecucion-multidestino-mayoristas.md`. No vuelve a diseñar nada —
> cada decisión ya se cerró en el documento de diseño; acá solo se
> secuencia y se detalla lo necesario para implementar sin ambigüedad.
>
> **Coordinación con otros planes (estado al 01-sep-2026):**
> - `plan-refactor-mayoristas-tramos.md` — **CERRADO, superado por
>   `alternativa_destinos`** (decisión del usuario). Su Problema C1
>   (mayorista visible en el PDF, `resolverNombreItemPdf()` línea 610)
>   ya tiene diseño y brief propio, cerrado aparte de este plan:
>   `opcion_mayorista.descripcion_publica` +
>   `PEGAR-EN-CLAUDE-CODE-fix-leak-mayorista-pdf.md` (01-sep-2026,
>   listo para ejecutar, sin dependencia de M1-M5). **Cuando M2 llegue a
>   centralizar el resolver de nombre, ese fix YA debe estar mergeado**
>   — el resolver centralizado necesita un parámetro explícito de
>   audiencia (`cliente` vs. `interno`) para no reintroducir el leak;
>   ver la nota que el propio fix deja en
>   `auditoria-arquitectonica-agencia-viajes.md` §9.3.
> - `plan-ejecucion-multidestino-mayoristas.md` (01-sep-2026) — el
>   conflicto de `opcion_hotel_tarifa_id` ya se corrigió (12a). El
>   agrupador de destino (`alternativa_destinos`) ya está decidido, sin
>   alternativa en competencia. Este plan puede avanzar sin esperar a que
>   `alternativa_destinos` termine de construirse — opera dentro de una
>   sola alternativa/destino, no entre destinos. Cuando
>   `alternativa_destinos` esté en producción, la sección del PDF de este
>   plan (M5) probablemente necesite anidarse dentro de la sección por
>   destino que agregue esa UI — ajuste menor esperado, no un rediseño.

---

## 0. Cómo usar este documento

Cada fila de la tabla de la sección 2 es una sesión propia de Claude
Code — rama nueva, un commit, un chat (regla del proyecto). El orden de
la tabla es el orden real de dependencias, no saltar filas salvo que la
columna "Depende de" diga explícitamente que es opcional/recomendado.

---

## 1. Por qué este orden (resumen de dependencias)

```
M1 (núcleo: agrupador + guard + precio en vivo, sin dependencias)
  │
  ├──▶ M2 (trazabilidad: fix escritura opcion_hotel_tarifa_id,
  │         reserva_items espejo, resolver centralizado, fixes
  │         reporte operativo)
  │
  └──▶ M3 (ad-hoc local + tributario en opciones_hotel_tarifas —
            recomendado después de M1, no depende estructuralmente)
              │
   M2 + M3 completas
              │
              ▼
        M4 (frontend: picker multi-select, selector tributario,
            atajo ad-hoc en pestaña Local, tarjeta colapsable en
            el lienzo)
              │
              ▼
        M5 (PDF: sección "Opciones de hoteles" en el blade)
```

`M2` y `M3` pueden ejecutarse en cualquier orden entre sí una vez cerrada
`M1` (tocan superficies distintas: M2 es `reserva_items`+resolución de
nombre, M3 es `opciones_hotel_tarifas`+endpoint de alta ad-hoc), pero
**no en la misma sesión**.

---

## 2. Sesiones

| # | Sesión | Qué construye | Depende de | Brief |
|---|---|---|---|---|
| M1 | Núcleo — agrupador de opciones + guard + precio en vivo | `alternativa_items.grupo_opcion_id`/`opcion_elegida`, guard 422 en `ReservaController::aceptar()` si queda un grupo sin resolver, `PriceEngineService`/panel de precio muestran "desde $X" mientras el grupo esté abierto, reparto de `descuento_global_pct` excluye grupos abiertos | Ninguna | **`[x]` 02-sep-2026, commit `962dcd9`, mergeado a `origin/main`.** `AlternativaItem::agruparPorGrupoOpcion()`/`calcularTotalEfectivo()` (helpers estáticos compartidos, único punto de esta lógica). Guard en `ReservaController::aceptar()`: exactamente 1 `opcion_elegida=true` por grupo, 0 y 2+ bloquean igual con 422. `AlternativaItemController::recalcularTotalAlternativa()` y `AlternativaController::aplicarDescuentoGlobal()`/`aplicarDescuentoGlobalMonto()` reescritos: un grupo abierto suma su mínimo una sola vez sin descuento en ninguna fila; un grupo resuelto suma solo la elegida, que sí entra al reparto de descuento respetando su propio piso — el resto del grupo queda a precio de lista siempre. `Alternativa::tiene_grupos_sin_resolver` (accessor, `$appends`) para que el frontend (M4) sepa mostrar "desde $X". 13 tests nuevos, 440/440 en la suite. Verificado contra `agencia-demo` real (3 tarifas de hotel reales, grupo abierto/resuelto, guard de aceptar, reparto de descuento — todos los números exactos esperados), datos de prueba revertidos |
| M2 | Trazabilidad — de la cotización a la reserva sin perder el hotel | Fix del gap heredado (`crearItemMayorista()` nunca escribía `alternativa_items.opcion_hotel_tarifa_id`); `reserva_items.opcion_hotel_tarifa_id` espejo; filtro de opciones rechazadas de un grupo en `crearReservaDesdeAlternativa()`; resolver de nombre centralizado con parámetro de audiencia | M1 | **`[x]` 02-sep-2026, commit `d82d42a`, mergeado a `origin/main`.** Brief: `PEGAR-EN-CLAUDE-CODE-matriz-hoteles-m2-trazabilidad.md`. **Hallazgo real encontrado al arrancar la sesión, antes de escribir el brief:** `crearReservaDesdeAlternativa()` creaba un `ReservaItem` por CADA fila de `alternativa_items`, sin filtrar por `opcion_elegida` — con un grupo de M1 ya resuelto, generaba reserva también para las opciones RECHAZADAS del grupo, no solo la elegida. El brief original de M2 (escrito antes de que M1 existiera) no lo contemplaba — se agregó como punto propio, no opcional. Nuevo helper `ReservaController::filtrarItemsParaReserva()`, aplicado también en `sincronizarItems()` por defensa en profundidad. `AlternativaItemController::crearItemMayorista()` ahora escribe `opcion_hotel_tarifa_id` (gap real confirmado por 2 auditorías independientes, columna existía desde antes sin que nada la escribiera). Migración `reserva_items.opcion_hotel_tarifa_id` + copiado en `crearReservaItemDesdeAlternativaItem()`. **Resolver de nombre centralizado de verdad** (Ronda 5/P14): `AlternativaController::resolverNombreItemPdf()` se ELIMINÓ — `pdf()` llama directo a `ReservaController::resolverNombreItem($item, null, 'cliente')` (nuevo parámetro de audiencia, usa `descripcion_publica` de C1 sin reintroducir el leak). Al fusionar ambos resolvers se encontró y cerró una segunda divergencia real que el diseño original no había detallado: `resolverNombreItem()` nunca tuvo rama para `origen_tipo=guia` (caía al genérico "Servicio", perdiendo el nombre del guía en reporte operativo/facturación) — incorporada al centralizar. Nueva rama `opcionHotelTarifa` como fallback final (antes del genérico) — hoy no la alcanza ningún ítem real (solo `origen_tipo=mayorista` escribe `opcion_hotel_tarifa_id`, y ese origen siempre resuelve antes por su propia rama mostrando el mayorista/`descripcion_publica`), pero deja el terreno listo para M3 (hotel ad-hoc LOCAL con `origen_tipo=proveedor` + `opcion_hotel_tarifa_id`, sin `proveedor_tarifa_id`). `itemSinAsignacionOperativa()`/`filtrosDisponibles()` (`ReporteOperativoController`, no `ReservaController` como decía el diseño original) revisados contra el código real: `itemSinAsignacionOperativa()` no tiene bug hoy (los ítems `mayorista` nunca entran a su chequeo de `proveedor_tarifa_id`, caen a `return false` = ya asignado) — sin cambios, verificado antes de tocar nada, no asumido del diseño. `filtrosDisponibles()` (catálogo "hoteles" del dropdown de filtro) sí tiene el gap real, pero arreglarlo bien requiere también extender la query de filtrado real (`hotel_proveedor_id`) — **diferido a propósito**: hoy no hay ningún ítem `mayorista` real en `agencia-demo` ni forma de crear uno desde la UI (M4 no existe), así que no hay caso real contra el que validar el fix — retomar cuando M4 haga alcanzable este camino. `reservas/detalle.vue::nombreItem()` (TS) actualizado con las mismas 2 ramas nuevas (guía + `opcionHotelTarifa`) — se mantiene la duplicación frontend/backend a propósito (exponer el nombre ya resuelto en el JSON de `show()` para que el frontend deje de recalcularlo se dejó fuera de esta sesión, es una mejora de code-quality, no una corrección de bug). 8 tests nuevos, 452/452 en la suite. Verificado con el flujo real completo contra `agencia-demo`: `crearItemMayorista()` real (no fixture directa) escribiendo `opcion_hotel_tarifa_id`, grupo de 2 hoteles agrupado, `aceptar()` real generando exactamente 1 `ReservaItem` (la elegida, no las 2), `opcion_hotel_tarifa_id` copiado correcto, ambas audiencias del resolver con los valores esperados — datos de prueba revertidos |
| M3 | Ad-hoc local + tributario en `opciones_hotel_tarifas` | Endpoint nuevo (espejo de `OpcionMayoristaController::hoteles()`, sin exigir `opcion_mayorista_id`/`paquete_plantilla_id`) para dar de alta un hotel no registrado + su matriz de precios; función "promover matriz completa a Proveedor"; migración `opciones_hotel_tarifas.tip_afe_igv`/`destino_tributario` + snapshot al materializar el `alternativa_item` | M1 (recomendado, no estructural) | **`[x]` 02-sep-2026, commit `98c4fae`, mergeado a `origin/main`.** Brief: `PEGAR-EN-CLAUDE-CODE-matriz-hoteles-m3-adhoc-local.md`. **Backend puro, sin UI** (M4 construye el picker/atajo local — esta sesión se probó por Tinker/tests, no por pantalla). Confirmado leyendo las migraciones reales antes de escribir el brief: `opciones_hotel.opcion_mayorista_id` ya era nullable a nivel de schema (y `paquete_plantilla_id` ya se había dropeado en la consolidación de hoteles) — **no hizo falta migración para permitir un `OpcionHotel` standalone**, el schema ya lo soportaba. Controller nuevo `OpcionHotelController` (`POST opciones-hotel` alta standalone, `POST opciones-hotel/{id}/promover`). `AlternativaItemController::crearItemProveedor()` extendido (no un método paralelo) con una TERCERA vía de precio (`opcion_hotel_tarifa_id`, mutuamente excluyente con `proveedor_tarifa_id` y el "precio de referencia" manual ya existentes) — mismo criterio de snapshot que `crearItemMayorista()`, `modo_precio` forzado a `tarifa_fija` sin importar qué pidió el request (un hotel siempre es por habitación). Migración `opciones_hotel_tarifas.tip_afe_igv`/`destino_tributario` (Ronda 6/P16) + `opciones_hotel.proveedor_promovido_id` (guard contra promover dos veces, mismo patrón que `alternativa_items.proveedor_promovido_id`). Promoción: `promover()` es DEFENSIVO — resuelve tributario contra el default de agencia si una `OpcionHotelTarifa` de antes de esta migración tuviera `null` todavía (hallazgo real encontrado por un test que lo reprodujo con NOT NULL violation antes de corregirlo). Sin relink retroactivo, verificado explícito. 6 tests nuevos, 458/458 en la suite. Verificado con el flujo real completo contra `agencia-demo`: alta de hotel ad-hoc real (tributario tomó el default REAL del tenant, `20`/`amazonia`, no un `10`/`nacional` genérico — confirma que lee la configuración real, no un hardcode), `AlternativaItem` creado con `opcion_hotel_tarifa_id`, `resolverNombreItem()` (de M2) resolviendo por primera vez un caso REAL vía la rama `opcionHotelTarifa` ("Hotel VERIF M3 · doble"), promoción real creando un `Proveedor` de verdad, ítem confirmado SIN relink — datos de prueba revertidos |
| M4 | Frontend — picker, lienzo, atajo local | `HabitacionMatrixPicker.vue`: checkboxes múltiples + "Agregar N opciones como grupo" (fija noches/pax una sola vez), selector de tratamiento tributario por fila ad-hoc; pestaña Local del cotizador gana el mismo formulario inline "+ Agregar hotel no registrado" que ya existe en Internacional (consume M3); lienzo: tarjeta colapsable de grupo con acción "marcar como elegida" por fila | M2 + M3 | **`[x]` 02-sep-2026, commit `PENDIENTE-HASH`, mergeado a `origin/main`.** Sin brief propio (M1/M2/M3 lo tenían, esta sesión arrancó directo con el plan de esta fila — gap de proceso reconocido, sin impacto real). `HabitacionMatrixPicker.vue`: modo opt-in "Comparar varias opciones" — checkboxes + botón "Agregar N opciones como grupo" (nuevo evento `agregarGrupo`), el flujo de selección única original queda intacto (sin tocar su comportamiento). Wireado en AMBOS call-sites (mayorista/Internacional y el nuevo picker Local/ad-hoc). Endpoint nuevo `PUT alternativa-items/{id}/elegir-grupo` (`AlternativaItemController::elegirOpcionGrupo()`): exclusividad transaccional dentro del grupo + reaplica `descuento_global_pct` vigente automáticamente (si hay uno seteado) para que la fila recién elegida no quede a precio de lista hasta que el vendedor retoque el campo a mano. `grupo_opcion_id` (nullable/uuid, generado client-side) aceptado en `crearItemProveedor()`/`crearItemMayorista()`. Pestaña Local gana "+ Agregar hotel no registrado" (formulario inline, consume `OpcionHotelController::store()` de M3) — nuevo `opcionHotelService.ts`. Lienzo: `filasDelBloque()` agrupa ítems CONSECUTIVOS por `grupo_opcion_id` en una tarjeta compacta (asume adyacencia — ver comentario en el código, caso no contemplado si un grupo queda separado por otro ítem en medio) con badge "Sin resolver"/"Elegida" y acción "Marcar como elegida" por fila; ítems sueltos siguen con su tarjeta completa de edición sin cambios. **4 bugs reales encontrados y corregidos en la verificación en vivo contra `agencia-demo` (ninguno cubierto por los tests unitarios de M1-M3, todos confirmados con datos reales antes de arreglar):** (1) `crypto.randomUUID()` no existe en contexto no-seguro — el dev de este proyecto navega por `http://*.sistemafe.test` (no HTTPS, no localhost), rompía el alta de cualquier grupo con `TypeError`; fallback manual con `Math.random()` (`generarUuidGrupo()`, el id es solo un agrupador liviano client-side, no necesita ser criptográficamente fuerte). (2) `CotizacionController::show()` no eager-cargaba `items.opcionHotelTarifa.opcionHotel` (sí lo tenía `AlternativaController::pdf()` desde M2, pero no el endpoint real que carga la pantalla del cotizador) — todo ítem enganchado por `opcion_hotel_tarifa_id` se mostraba como "Servicio" genérico en vez de "Hotel · tipo de habitación". (3) `totalLocal`/`subtotalGrupo` (computeds del frontend, escritos antes de M1) sumaban TODAS las filas de un grupo como si fueran sueltas — mostraban PEN 280 (120+160) en vez de PEN 120 apenas se resolvía un grupo de 2 opciones, mientras el backend (`AlternativaItem::calcularTotalEfectivo()`) ya tenía el total correcto guardado; unificados en un solo helper `totalEfectivoLocal()` con la misma regla que el backend. (4) `AlternativaController::eliminarCascada()` nunca borraba `alternativa_destinos` (bug cruzado de 12f-2, no de esta sesión, pero bloqueaba borrar CUALQUIER alternativa con un destino asociado con un 500 de FK) — encontrado al limpiar los datos de prueba de esta misma sesión, corregido y cubierto con test de regresión. 7 tests nuevos, 465/465 en la suite. Verificado en vivo con Playwright contra `agencia-demo`: alta de hotel ad-hoc con 2 tarifas desde la pestaña Local, grupo de 2 opciones creado con el picker multi-select, tarjeta de grupo en el lienzo con badge "Sin resolver", "Marcar como elegida" resolviendo el grupo (badge pasa a "Elegida", total baja de 280 a 120 correctamente), nombre resuelto "Hotel M4 Grupo Test 2 · doble/triple" en vez de "Servicio" — datos de prueba revertidos (alternativa + hoteles ad-hoc eliminados) |
| M5 | PDF — sección "Opciones de hoteles" | Bloque nuevo en `alternativa.blade.php`, aparte, después de la lista de ítems normales — agrupa por `grupo_opcion_id`, dibuja la tabla matriz (hotel × tipo de habitación × precio) igual formato que los 3 documentos reales que originaron este plan (`docs/auxiliares/`). Usa el resolver de M2, no reimplementa nombres | M2 (estructural), M4 (recomendado, para probar con datos reales) | Pendiente de escribir |

---

## 3. Verificación transversal (aplica a M1-M5, no repetir la lista en cada brief)

- Test de regresión explícito: un `alternativa_item` sin `grupo_opcion_id`
  (el 100% de los ítems existentes hoy) se comporta exactamente igual que
  antes en cada sesión — precio, descuento, PDF, reporte operativo,
  reserva. Este plan es aditivo, no debe cambiar nada del camino sin
  grupo.
- `PriceEngineService::convertirMoneda()`/`evaluarPiso()` sin cambios de
  comportamiento fuera del reparto de descuento sobre grupos (M1) — test
  de regresión explícito, mismo criterio que usa
  `plan-ejecucion-multidestino-mayoristas.md` para su propia superficie.
- Ningún reporte/PDF/pantalla existente que hoy lea
  `alternativa_item.proveedor_tarifa_id`/`reserva_item.proveedor_tarifa_id`
  se rompe con un ítem que en cambio tiene `opcion_hotel_tarifa_id` — cada
  sesión que toque una de esas lecturas confirma el camino con AMBOS
  orígenes, no solo el registrado.
- El PDF comercial nunca imprime el nombre legal de un mayorista, ni antes
  ni después de este plan — mismo test de regresión que ya exige
  `plan-ejecucion-multidestino-mayoristas.md §4` para su propia superficie
  (comparten el mismo resolver desde M2).
- Cero filas de producción perdidas en cada migración — confirmar con
  conteo antes/después.
- Correr `php artisan tenants:migrate-verticales` contra los tenants
  reales después de mergear cada sesión con migración nueva — **no**
  `tenants:migrate` a secas (bug conocido, ver `CLAUDE.md`).

---

## 4. Historial

| Fecha | Cambio |
|---|---|
| 02-sep-2026 | M4 cerrada — ver fila M4 de la tabla arriba para el detalle completo. Primera sesión visible en pantalla de todo el bloque (M1-M3 eran backend puro); 4 bugs reales encontrados en la verificación en vivo, todos corregidos. |
| 02-sep-2026 | M1 cerrada — ver fila M1 de la tabla arriba para el detalle completo. Backend puro, sin UI (M4 la construye), sin dependencias. |
| 01-sep-2026 | Documento creado al cerrar el diseño de `plan-matriz-hoteles-cotizador.md` (6 rondas, 18 preguntas). En el camino se encontró y corrigió un conflicto real con `plan-ejecucion-multidestino-mayoristas.md` (su sesión 12a planeaba deprecar `alternativa_items.opcion_hotel_tarifa_id`, columna que este plan necesita viva — corregido en ambos documentos el mismo día). Secuencia M1-M5 propuesta; M1 con brief completo, M2-M5 pendientes de escribir al llegar a cada sesión (mismo criterio que usa `plan-ejecucion-multidestino-mayoristas.md`). |
