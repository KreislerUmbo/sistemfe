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
| M1 | Núcleo — agrupador de opciones + guard + precio en vivo | `alternativa_items.grupo_opcion_id`/`opcion_elegida`, guard 422 en `ReservaController::aceptar()` si queda un grupo sin resolver, `PriceEngineService`/panel de precio muestran "desde $X" mientras el grupo esté abierto, reparto de `descuento_global_pct` excluye grupos abiertos | Ninguna | **`[x]` 02-sep-2026, commit `<pendiente>`, mergeado a `origin/main`.** `AlternativaItem::agruparPorGrupoOpcion()`/`calcularTotalEfectivo()` (helpers estáticos compartidos, único punto de esta lógica). Guard en `ReservaController::aceptar()`: exactamente 1 `opcion_elegida=true` por grupo, 0 y 2+ bloquean igual con 422. `AlternativaItemController::recalcularTotalAlternativa()` y `AlternativaController::aplicarDescuentoGlobal()`/`aplicarDescuentoGlobalMonto()` reescritos: un grupo abierto suma su mínimo una sola vez sin descuento en ninguna fila; un grupo resuelto suma solo la elegida, que sí entra al reparto de descuento respetando su propio piso — el resto del grupo queda a precio de lista siempre. `Alternativa::tiene_grupos_sin_resolver` (accessor, `$appends`) para que el frontend (M4) sepa mostrar "desde $X". 13 tests nuevos, 440/440 en la suite. Verificado contra `agencia-demo` real (3 tarifas de hotel reales, grupo abierto/resuelto, guard de aceptar, reparto de descuento — todos los números exactos esperados), datos de prueba revertidos |
| M2 | Trazabilidad — de la cotización a la reserva sin perder el hotel | Fix del gap heredado (`crearItemMayorista()`/el flujo de creación de ítem desde `HabitacionMatrixPicker` nunca escribía `alternativa_items.opcion_hotel_tarifa_id` — confirmado muerto por 2 auditorías independientes); `reserva_items.opcion_hotel_tarifa_id` espejo + copiarlo en `crearReservaItemDesdeAlternativaItem()` filtrando por `opcion_elegida=true` dentro de un grupo; resolver de nombre centralizado (`ItemNombreResolver` o similar) con rama `opcionHotelTarifa`, usado por `ReservaController` y expuesto en el JSON de `show()`; fix de `itemSinAsignacionOperativa()`/`tieneAsignacionAplicable()`/`filtrosDisponibles()` para no tratar un hotel ad-hoc como "sin asignar" | M1 | Pendiente de escribir |
| M3 | Ad-hoc local + tributario en `opciones_hotel_tarifas` | Endpoint nuevo (espejo de `OpcionMayoristaController::hoteles()`, sin exigir `opcion_mayorista_id`/`paquete_plantilla_id`) para dar de alta un hotel no registrado + su matriz de precios desde la pestaña Local; función "promover matriz completa a Proveedor" (crea `Proveedor`+`ProveedorServicio`+N `ProveedorTarifa`, una por `tipo_habitacion`, sin relink retroactivo); migración `opciones_hotel_tarifas.tip_afe_igv`/`destino_tributario` + snapshot al materializar el `alternativa_item` | M1 (recomendado, no estructural) | Pendiente de escribir |
| M4 | Frontend — picker, lienzo, atajo local | `HabitacionMatrixPicker.vue`: checkboxes múltiples + "Agregar N opciones como grupo" (fija noches/pax una sola vez), selector de tratamiento tributario por fila ad-hoc; pestaña Local del cotizador gana el mismo formulario inline "+ Agregar hotel no registrado" que ya existe en Internacional (consume M3); lienzo: tarjeta colapsable de grupo con acción "marcar como elegida" por fila | M2 + M3 | Pendiente de escribir |
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
| 02-sep-2026 | M1 cerrada — ver fila M1 de la tabla arriba para el detalle completo. Backend puro, sin UI (M4 la construye), sin dependencias. |
| 01-sep-2026 | Documento creado al cerrar el diseño de `plan-matriz-hoteles-cotizador.md` (6 rondas, 18 preguntas). En el camino se encontró y corrigió un conflicto real con `plan-ejecucion-multidestino-mayoristas.md` (su sesión 12a planeaba deprecar `alternativa_items.opcion_hotel_tarifa_id`, columna que este plan necesita viva — corregido en ambos documentos el mismo día). Secuencia M1-M5 propuesta; M1 con brief completo, M2-M5 pendientes de escribir al llegar a cada sesión (mismo criterio que usa `plan-ejecucion-multidestino-mayoristas.md`). |
