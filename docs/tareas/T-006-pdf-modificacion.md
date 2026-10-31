# T-006 — PDF de la modificación para el cliente

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-003 (T-004 para que salga la penalidad) |
| **Reglas** | R-AGE-09 |
| **Decisiones** | — (contenido acordado: requerimiento, pregunta 6) |
| **Rama** | `feat/T-006-pdf-modificacion` |

## Contexto
Antes de aplicar, el vendedor le manda al cliente qué cambia y cuánto. El PDF se arma con la
diferencia de T-003 (no recalcula nada propio) y con el mismo membrete que el PDF de
cotización.

## Alcance
- `GET reservas/{id}/modificacion/pdf`: la modificación abierta. Con `?modificacion_id=` puede
  pedir una ya aplicada; en ese caso usa `diferencia_aplicada`, no recalcula.
- Contenido:
  - membrete (`MembreteAgenciaPdfService`);
  - código de la reserva y fecha de emisión (`HoraPeru`);
  - cliente;
  - **solo las líneas que cambian** (agregado, quitado, cambio de precio, ajuste) con lo
    vendido y lo nuevo;
  - pasajeros agregados y quitados;
  - penalidades con su motivo;
  - totales: vendido, nuevo, diferencia, ya facturado o pagado (anticipos), saldo.
- Nombres de los servicios en audiencia cliente: `ReservaController::resolverNombreItem(...,
  'cliente')`, para no filtrar el mayorista (ver el fix C1).
- Logo con `StorageUrl::resolveParaPdf()` (DomPDF no carga URLs remotas).
- Nombre de archivo: `modificacion_<codigo_reserva>_<DD-MM-YYYY>.pdf`.

**Nota de la revisión de T-005 (30-oct): ajustes.** Al aplicar, el "Ajuste — <servicio>"
(`alternativa_items.ajuste_de_item_id` no nulo) queda en la versión vigente **junto** al
servicio con el precio subido: listar o sumar los dos cobraría la diferencia dos veces. En este
PDF el ajuste sale de la diferencia (`diferencia_aplicada`), no de los ítems de la alternativa.
Ojo: `AlternativaItem::calcularTotalEfectivo()` y `AlternativaPdfService` suman todos los
ítems, ajuste incluido; si se reusan, excluir los ítems con `ajuste_de_item_id`. Anotar en
Hallazgos si el PDF de cotización de la versión vigente (`…-M1`) también lo muestra doble.

## Fuera de alcance
- Enviarlo por correo (después, sobre `CotizacionEnvioController`).
- Cambiar el PDF de cotización.

## Archivos probables
- `app/Services/AgenciaViajes/ModificacionPdfService.php` (nuevo)
- `resources/views/pdf/agencia/modificacion-reserva.blade.php` (nuevo)
- `ReservaModificacionController`
- `tests/Feature/AgenciaViajes/ReservaModificacionPdfTest.php`

## Criterios de aceptación
- [ ] **CA1** — El PDF se genera (200, `application/pdf`) para una modificación abierta con un
  pasajero agregado y una penalidad.
- [ ] **CA2** — Sale solo lo que cambia; una línea `igual` no aparece.
- [ ] **CA3** — Los totales del PDF coinciden con los de `GET …/diferencia`.
- [ ] **CA4** — Con un mayorista, el PDF no muestra su nombre (`descripcion_publica`).
- [ ] **CA5** — El logo se ve. Verificación visual: rasterizar con Ghostscript y revisar la
  imagen (ver la memoria `reference_pdf_visual_verification_ghostscript`).
- [ ] **CA6** — Para una modificación aplicada, sale lo que se aplicó aunque la reserva haya
  cambiado después.
- [ ] **CA7** — Permisos: sin `reservas.modificar` o sin poder ver la reserva, 403.

## Seguridad y correctitud
- [ ] Permisos / Policy: igual que T-002
- [ ] Aislamiento de tenant: logo vía `StorageUrl::resolveParaPdf()`
- [ ] Validación de entrada: `modificacion_id` debe ser de **esta** reserva
- [ ] Concurrencia: no aplica
- [ ] Dinero en centavos: se muestra lo que da T-003, formateado una sola vez
- [ ] Fechas con `HoraPeru`: fecha de emisión e instantes con `HoraPeru::deUtc()`
- [ ] Tributario: no aplica
- [ ] `v-html` / escape: textos libres (motivo) con `{{ }}`, nunca `{!! !!}`
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=ReservaModificacionPdf
```
Generar uno real en `agencia-demo` y rasterizarlo con `gswin64c.exe` para revisarlo.

---

## Resultado
- **Commits:** en la rama `feat/T-006-pdf-modificacion` (ver `git log main..`).
- **Tests:** `ReservaModificacionPdfTest`, 11 tests (CA1-CA7, el ajuste una sola vez, modificación de
  otra reserva → 404, sin abierta → 404, descartada → 422). Suite completa: ver el aviso final.
  CA5 verificado a la vista: PDF real de `agencia-demo` (reserva `RDKM-1026-0000001`, +75 al único
  servicio, dentro de una transacción que se revirtió), rasterizado con Ghostscript a 80 dpi:
  membrete con logo, tabla, resumen y pie correctos, una sola página.
- **Desviaciones de la tarea y por qué:**
  - La vista está en `resources/views/pdf/agencia-viajes/modificacion-reserva.blade.php`, no en
    `pdf/agencia/…`: la carpeta real de los PDF de Agencia es `pdf/agencia-viajes/`.
  - El nombre del servicio se vuelve a resolver desde el ítem (`resolverNombreItem(..., 'cliente')`)
    porque la `descripcion` de la diferencia (y la guardada en `diferencia_aplicada`) es la interna,
    con el nombre del mayorista. Si el ítem ya no existe, sale «Servicio», nunca la interna.
  - **Resumen (revisión de Opus, 30-oct):** solo total anterior, total nuevo y diferencia. Se
    quitaron «Saldo» (no restaba lo cobrado en facturas al contado ni por amortizaciones),
    «Ya facturado» (un tour facturado a medias cuenta entero por R-AGE-04) y «Anticipos
    recibidos» (no resta devoluciones): en un documento para el cliente serían cifras falsas.
  - **Líneas (revisión de Opus):** se imprimen solo `agregado`, `quitado`,
    `requiere_nota_credito`, `cambio_precio` y `ajuste`. Antes salía también un `para_revisar`
    confirmado (hotel con otra cantidad de pasajeros y el mismo precio) rotulado «Por revisar»
    con diferencia 0. Test nuevo para ese caso (12 tests).
  - Las penalidades salen en su sección (concepto + motivo + monto), no repetidas en la tabla de
    cambios. Se identifican por `alternativa_items.penalidad_reserva_pasajero_id`.
  - Una modificación descartada pide 422 (no hay diferencia que imprimir).

## Hallazgos fuera de alcance
- `AlternativaPdfService` y `AlternativaItem::calcularTotalEfectivo()` no filtran
  `ajuste_de_item_id` (no hay ningún uso en esos archivos): el PDF de cotización de la versión
  vigente (`…-M1`) de una reserva con ajuste muestra y suma el «Ajuste — …» junto al servicio con
  el precio subido, o sea, la diferencia dos veces. Confirmado por lectura del código; no probado
  con un PDF real. Candidata a tarea nueva.
