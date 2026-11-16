# Recorrido: comprobante mixto y columna «Afectación» al facturar

16-nov-2026, tarea **T-091**. Origen: `docs/requerimientos/agencia-tributario-simplificado.md` (P5 versión
simple y P7) y las decisiones **D-AGE-035** (C4: se mezcla; C5: sin confirmar la Amazonía), **D-AGE-036**
(series solo por moneda) y **D-AGE-037** (la afectación se ve y se corrige al facturar, sin configuración nueva).
Archivos:
- `api-sistema-fe/app/Services/AgenciaViajes/CriterioTributarioService.php` (`evaluar()`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php` (`filasDetalle()`, `resolverTipAfeIgvItem()`, `detectarBloqueoTributario()`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php` (`overrideTratamientoTributario()`)
- `api-sistema-fe/app/Services/AgenciaViajes/ModificacionReservaService.php` (`repuntar()`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php` (`facturable()`)
- `admin-start-kit/src/components/AgenciaViajes/AfectacionLineasFactura.vue` (nuevo), `DetalleComprobantePreview.vue`, `FacturarReservaSimpleModal.vue`, `FacturarReservaEspecialModal.vue`
- `admin-start-kit/src/composables/agencia-viajes/useAfectacionFactura.ts` (nuevo)
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue` (modal «Corregir tratamiento», `tipAfeIgvItem`)
- Tests: `ReservaFacturacionTest`, `Fila13Fase1cFacturacionTest`, `CriterioTributarioServiceTest`, `VentaDirectaLineasTest`, `ReservaModificacionAplicarTest`

**Al desplegar:** nada (sin migraciones). Frontend y backend juntos: el frontend nuevo espera
`tip_afe_igv`/`editable`/`cambiado` en cada fila de la vista previa.

---

## 1. La idea

**Antes**, una reserva con un hotel de la Amazonía (exonerado, 20) y un traslado gravado (10) **no se podía
facturar en un comprobante**: el guardia tributario lo bloqueaba (salvo dentro de un mismo tour). Además, cada
servicio de la Amazonía tenía que **confirmarlo una persona**, con motivo obligatorio, antes de facturar
(insignia «Sin confirmar»).

**Ahora**:
- Gravado, exonerado e inafecto van **juntos** en un comprobante, en la serie normal. SUNAT lo acepta: cada
  línea lleva su afectación y los totales salen por separado (`mto_oper_gravadas`, `mto_oper_exoneradas`,
  `mto_oper_inafectas`).
- No se confirma nada. En su lugar, quien factura **ve la afectación de cada línea** y la cambia ahí mismo.
- Solo **exportación/extranjero** sigue bloqueado (necesita validaciones legales que este flujo no hace).

| Caso | Antes | Ahora |
|---|---|---|
| Hotel 20 de S/ 300 + traslado 10 de S/ 118 | 422: mezcla | 1 comprobante: exoneradas 300, gravadas 100, IGV 18, total 418 |
| Solo servicios de la Amazonía, nunca confirmados | 422: sin confirmar | Se factura exonerado |
| Un servicio de exportación, solo o con otros | 422 | 422, con el mensaje de exportación |
| Cambiar una entrada a Inafecto | Modal aparte, motivo obligatorio | En la columna, sin motivo |

## 2. El recorrido

### 2.1 Lo que ya funcionaba y no se tocó

El armado de un comprobante mixto **ya existía** (por los tours con IGV mezclado, fila 13 fase 1c). En
`ReservaFacturacionController::store()`:

1. `agruparPorCategoria()` separa las líneas por `tip_afe_igv`, usando `resolverTipAfeIgvItem()` en cada ítem.
2. `construirLineas()` calcula el IGV de cada línea (solo la 10 lleva IGV; el precio ya lo incluye).
3. Los `mto_oper_*` se suman por el `tip_afe_igv` de cada línea.
4. `resolverDestinoVenta()` guarda `sales.destino = 'amazonia'` si **alguna** línea lo es. Ese campo solo
   decide la leyenda de la Ley 27037 en el PDF, y `Sale::muestra_leyenda_amazonia` la imprime solo si hay
   monto exonerado.

Por eso la tarea solo tenía que **quitar el bloqueo**, no reescribir la factura.

### 2.2 El guardia: solo exportación

`CriterioTributarioService::evaluar()` recibe una lista neutral (`id`, `destino`) y devuelve el bloqueo o
`null`. Lo usan dos lugares, con el mismo criterio:

| Quién | Para qué |
|---|---|
| `ReservaFacturacionController::detectarBloqueoTributario()` (antes `detectarMezclaTributaria()`) | vista previa (`preparar-factura`, responde 200 con `bloqueado_tributario`) y `store()` (422, antes y bajo lock) |
| `VentaDirectaController::facturable()` | vista previa de Venta directa (`ok` / `motivo`) |

Ahora solo mira si algún destino es `'extranjero'`. Se borraron el bloqueo de mezcla,
`mezclaDentroDeUnaCargaTour()`, «Amazonía sin confirmar» y sus constantes; también las claves `carga_tour` y
`confirmado` de la lista neutral, y `items_sin_confirmar_ids` / `requiere_confirmar_amazonia` de las respuestas.

### 2.3 Cada fila de la vista previa dice su afectación

`prepararFactura()` arma la tabla «Qué entra en este comprobante» con `filasDetalle()`: una fila por servicio
suelto, o una por tour (sus líneas se agrupan por `carga_tour`). Cada fila trae tres campos nuevos:

| Campo | Cómo se calcula |
|---|---|
| `tip_afe_igv` | `resolverTipAfeIgvItem()` de cada línea; si las líneas del tour difieren, `'mixto'` |
| `editable` | `false` si es un tour, si está desmarcada (excluida) o si algún ítem ya está facturado (`ReservaVenta::itemIdsDe($reserva->ventas)`) |
| `cambiado` | `true` si algún ítem tiene `fecha_override_tributario` |

**Por qué un tour no se edita en la columna:** una fila de tour agrupa varias líneas que pueden tener
afectaciones distintas (hotel 20 + entrada 10). Una sola lista no puede decir cuál cambiar. Se corrige con
«Corregir tratamiento» en el detalle de la reserva (§2.6).

### 2.4 Guardar el cambio: el mismo endpoint de siempre

No hay ruta nueva. La columna llama a `POST reservas/{id}/override-tratamiento-tributario`
(`ReservaController::overrideTratamientoTributario()`), el que antes servía para confirmar la Amazonía:

- `motivo`: ahora **opcional**.
- `destino_tributario`: ahora **opcional**. Si no viene, se saca del código: 20 ⇒ `amazonia`; si no,
  `nacional` (en Agencia, exonerado = Amazonía; trait `ExoneradoEsAmazonia`). Un `extranjero` explícito se respeta.
- Graba en el `reserva_item`: `tip_afe_igv`, `destino_tributario`, `motivo_override_tributario` (puede quedar
  null) y `fecha_override_tributario = now()` (instante UTC).
- Sigue igual: `ReservaPolicy::update`, el lock de la reserva y el 422 si algún ítem ya está facturado.

### 2.5 «Corregido a mano» = `fecha_override_tributario`

Antes, «un humano lo revisó» era `motivo_override_tributario` no nulo. Como el motivo ahora puede quedar vacío,
la marca pasó a ser **la fecha**. Se usa en tres lugares:

| Dónde | Qué hace con la marca |
|---|---|
| `ModificacionReservaService::repuntar()` | Una modificación de la reserva no pisa la afectación de una línea corregida a mano |
| `resolverTipAfeIgvItem()` (y su espejo `tipAfeIgvItem` en `detalle.vue`) | La línea corregida gana sobre todo, **también sobre el pasaje aéreo** |
| `filasDetalle()` | `cambiado = true` → la columna muestra «✎ cambiado» |

**Por qué el pasaje aéreo.** La cascada normal de `resolverTipAfeIgvItem()` es:
pasaje aéreo (`cotizacion_pasaje_aereo.tip_afe_igv`) → `reserva_items.tip_afe_igv` → tarifa del proveedor → `'10'`.
El override escribe en `reserva_items`, que en un pasaje iba **después**: cambiar un pasaje en la columna no
tenía ningún efecto. Por eso, si hay fecha de override, se devuelve el `tip_afe_igv` del ítem antes de mirar el pasaje.

Los datos ya guardados tenían las dos columnas llenas juntas, así que no hizo falta migrar nada.

### 2.6 La pantalla

**En los modales de facturar** (`FacturarReservaSimpleModal` y `FacturarReservaEspecialModal`):

```
DetalleComprobantePreview  (con-afectacion)
  └─ por fila: AfectacionLineasFactura   → lista Gravado / Exonerado – Amazonía / Inafecto, o el texto («Mixto»)
                 emite 'cambiar'           (DetalleComprobantePreview lo reenvía como 'cambiar-afectacion')
  └─ resumen: op. gravadas / exoneradas / inafectas / IGV / total
              «El IGV sale del precio: el cliente paga lo mismo».
modal
  └─ useAfectacionFactura.cambiar(ids, tip)
        1. overrideTratamientoTributario(...) sin motivo ni destino
        2. alGuardar → el modal emite 'afectacion-cambiada' (detalle.vue toma la reserva nueva)
                     → refrescarPreview() (totales al día, sin cerrar el modal)
        3. version++ → la columna se redibuja con lo que de verdad quedó guardado
```

- Mientras guarda, `guardando` deshabilita **todas** las listas, así no se cruzan dos cambios.
- Si falla, hay toast con el error, se vuelve a pedir la vista previa igual, y `version++` hace que la
  lista vuelva a mostrar lo guardado en vez de lo que se eligió.
- El resumen se calcula en el frontend con `grupos_propuestos` (las líneas que armará `store()`, ya en la
  moneda del comprobante): suma `subtotal` por código, con el mismo criterio que los `mto_oper_*`.

**En el detalle de la reserva** (`reservas/detalle.vue`):
- Ya no está la insignia «Sin confirmar» ni el botón «Confirmar tratamiento tributario».
- Cada servicio no facturado de una reserva activa tiene **«Corregir tratamiento»**. En un tour se marcan las
  líneas a corregir (cada una muestra «hoy: …»). El motivo es opcional.
- El modal **no deja elegir el destino** (corregido en la revisión): antes se podía elegir Amazonía con 10 o
  30, y al abrir tomaba el destino guardado, así que un dato viejo «10 + amazonia» se volvía a guardar.
  Ahora se manda solo el código y el backend saca el destino (§2.4).

## 3. Cómo se probó

- `ReservaFacturacionTest`: factura mixta 300/100/18/418 en la serie normal con leyenda de la Amazonía (CA1);
  Amazonía sin confirmar se factura (CA2); exportación sola o mezclada sigue en 422 (CA3); vista previa con
  `tip_afe_igv`/`editable`/`cambiado` (CA4; el caso «ya facturado» por reflexión, porque por HTTP un servicio
  suelto facturado ya no entra en la vista previa); override sin motivo ni destino, 403 sin permiso (CA5);
  línea pasada a Inafecto → exoneradas 300, inafectas 30, IGV 0, total 330 y el XML con una línea 20 y otra
  30, armado como en el envío pero sin firmar ni enviar (CA7); facturación por grupo mixta (CA8); corrección a
  mano sobre un pasaje aéreo.
- `Fila13Fase1cFacturacionTest`: los casos que esperaban el bloqueo ahora facturan en un comprobante; un tour
  mezclado trae la fila `'mixto'`.
- `ReservaModificacionAplicarTest`: `repuntar()` no pisa una línea corregida sin motivo (CA6).
- `VentaDirectaLineasTest`: Amazonía + gravado es facturable, sin `requiere_confirmar_amazonia` (CA9).
- Filtro de la tarea: 256/256. En pantalla (agencia-demo, reservas 30 y 32): la columna cambia una línea, se
  ve «✎ cambiado», los totales se actualizan con el mismo total y el PDF del comprobante mixto muestra los
  totales y la leyenda.
- `type-check` sin errores nuevos (47 previos, T-101).

## 4. Si algo falla

| Síntoma | Dónde mirar |
|---|---|
| Una reserva mixta vuelve a dar 422 | `CriterioTributarioService::evaluar()`: solo `'extranjero'` debe bloquear; ver qué destino resuelve `resolverDestinoTributario()` |
| Cambio una línea en la columna y la factura no cambia | `resolverTipAfeIgvItem()`: ¿el ítem tiene `fecha_override_tributario`? Sin ella gana la cascada normal (el pasaje aéreo primero) |
| Una modificación de la reserva deshace mi corrección | `repuntar()` mira `fecha_override_tributario`, no el motivo |
| La lista no aparece en una fila | `editable` en `filasDetalle()`: tour, desmarcada o ya facturada |
| Los totales del resumen no coinciden con la factura | el resumen suma `grupos_propuestos.subtotal` por código; `store()` arma los `mto_oper_*` desde las mismas líneas |
| El PDF no muestra la leyenda de la Amazonía | `resolverDestinoVenta()` (alguna línea `amazonia`) y `Sale::muestra_leyenda_amazonia` (hay monto exonerado) |
| La insignia dice «Amazonía (exonerado)» y la línea es 10 | dato viejo «10 + amazonia» (ver «Por analizar» del backlog); corregir con «Corregir tratamiento» |
