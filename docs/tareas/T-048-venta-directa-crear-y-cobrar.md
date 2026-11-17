# T-048 — Venta directa: "Crear y cobrar" con boleta o factura (H45)

| | |
|---|---|
| **Módulo** | AGE + CAJ |
| **Depende de** | T-047 (la pantalla completa), T-011 (y por ella T-010) y **T-091** (comprobante mixto y sin confirmación de la Amazonía) |
| **Reglas** | las de cobro de T-010/T-011 |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 12, H45 y respuestas 1-2 (27-oct-2026: también por transferencia; comprobante elegido cada vez); D-AGE-034 (sin nota de venta); `agencia-tributario-simplificado.md` §2.7, C4 y C5 (07-nov-2026: se mezclan gravadas y exoneradas; **sin confirmación de la Amazonía**, reemplaza el punto 2 de D-AGE-034); D-AGE-036 (series solo por moneda); D-AGE-037 (columna «Afectación» editable, el mismo componente que al facturar; decidido para Venta directa el 16-nov) |
| **Rama** | `feat/T-048-venta-directa-crear-cobrar` |

## Contexto
Después de crear una venta directa hay que ir a la reserva, facturar y cobrar: 3 pantallas para una
venta que se paga en el momento, en la oficina o por transferencia.

## Alcance
- Seguir la **maqueta aprobada** (enlace en D-AGE-034): botones y bloque de cobro como ahí, **sin
  la casilla de la Amazonía** (C5, 07-nov).
- En el resumen de la pantalla de T-085: botón **«Crear y cobrar»** arriba y el actual pasa a
  **«Crear sin cobrar»** («a crédito, si el pago llega después o para facturar desde la reserva»).
  «Crear y cobrar» aparece solo con `AGENCIA_COBRO_AL_FACTURAR` encendido.
- Al apretarlo, el resumen muestra el **bloque de cobro de T-011** (el componente, no uno nuevo:
  formas de pago con Yape/Plin, moneda, n.º de operación, pago mixto, vuelto) y el **tipo de
  comprobante sin valor por defecto**: **boleta o factura**, según los permisos de emisión del
  usuario. **Sin nota de venta** (D-AGE-034: facturar una reserva solo emite 01 y 03).
- **Afectación** (vista previa de T-085, `facturable` ya con P7 desde T-091; recorrido 65):
  - Gravado, exonerado e inafecto van **en el mismo comprobante** (C4). **Sin confirmación de la
    Amazonía** ni motivo (C5).
  - **Columna «Afectación» editable por línea** (16-nov, D-AGE-037), con el mismo componente que
    al facturar: `components/AgenciaViajes/AfectacionLineasFactura.vue`. Hoy su prop es el tipo
    `DetalleComprobantePreview` de reservas; generalizarla a un tipo mínimo (`nombre`,
    `tip_afe_igv`, `editable`, `cambiado`, `excluido?`) sin cambiar lo que ven los modales de
    facturar. **No** reusar `useAfectacionFactura` (guarda sobre una reserva que acá no existe).
  - Línea de proveedor o concepto libre: lista Gravado / Exonerado – Amazonía / Inafecto,
    prellenada con el tratamiento que hoy resuelve `resolverLineas()`
    (`AlternativaItemController::resolverTratamientoTributario()`). Línea de **tour**: solo
    lectura (su afectación, o «Mixto» si sus grupos difieren, como en la facturación). La línea
    cambiada muestra «✎ cambiado».
  - El cambio **no se guarda aparte**: viaja como `lineas.*.tip_afe_igv` (nuevo, `nullable|in:10,20,30`;
    en una línea `tour` → 422 «La afectación de un tour se corrige en el tour», nunca ignorarlo en silencio) en
    `vista-previa` y en `store`. En `vista-previa` reemplaza el tratamiento de la línea (con el
    destino sacado del código: 20 ⇒ `amazonia`, si no `nacional`, como
    `ReservaController::overrideTratamientoTributario()`) y el resumen se recalcula.
  - Al crear: el `reserva_item` de esa línea queda con ese `tip_afe_igv`, el destino derivado y
    `fecha_override_tributario = now()` (= «corregido a mano», T-091: lo respetan
    `resolverTipAfeIgvItem()` y `repuntar()`), dentro de la misma transacción. Sin cambiar
    `tip_afe_igv`, la línea queda como hoy (sin fecha de override).
  - Resumen a la vista: op. gravadas, exoneradas, **inafectas**, IGV y total, y la línea «El IGV
    sale del precio: el cliente paga lo mismo» (como en `DetalleComprobantePreview.vue`).
  - `ok = false` solo por exportación: «Crear y cobrar» deshabilitado con el motivo; «Crear sin
    cobrar» sigue habilitado.
- Backend: crea la venta directa y, **en la misma transacción**, factura la reserva completa al
  contado con el cliente de la venta y registra el cobro, con la lógica de
  `ReservaFacturacionController::store()` + `CobroVentaService` (extraída a un servicio si hace
  falta, nunca copiada). Si algo falla, no queda ni reserva ni venta ni movimientos.
- Transferencia: n.º de operación como en T-010.
- Al terminar: la ficha de la reserva con la venta emitida y el botón de imprimir. El envío a SUNAT
  sigue siendo manual, como en el resto del sistema.
- «Crear sin cobrar» sigue igual: para pagar después (crédito, transferencia que aún no llega,
  facturar más tarde o en comprobantes separados).

## Fuera de alcance
- Enviar a SUNAT automáticamente.
- Cobro parcial con saldo pendiente (eso es «Crear sin cobrar» + facturar a crédito desde la
  reserva).
- Nota de venta (D-AGE-034).
- Emitir dos comprobantes en el mismo paso: con la mezcla permitida (C4) no hace falta.
- Series por afectación: descartadas (D-AGE-036). La serie es la normal, por sucursal + tipo + moneda.
- Cambiar la afectación de un servicio **dentro de un tour** desde Venta directa (fila de solo
  lectura, como al facturar).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php`
- `admin-start-kit/src/views/agencia-viajes/venta-directa.vue` + el componente de cobro de T-011
- `VentaDirectaController::reglasLineas()`, `resolverLineas()`, `vistaPrevia()` y `store()` (el `tip_afe_igv` por línea)
- `admin-start-kit/src/components/AgenciaViajes/AfectacionLineasFactura.vue` (generalizar la prop) y `services/admin/ventaDirectaService.ts`

## Criterios de aceptación
- [x] **CA1** — Crear y cobrar con boleta, efectivo S/ 120 → reserva, venta pagada, 1 pago y 1 movimiento de caja. → test
- [x] **CA2** — Transferencia con n.º de operación → pago con el n.º guardado. → test
- [x] **CA3** — Sin tipo de comprobante → 422. → test
- [x] **CA4** — Lo cobrado no cubre el total, o sin caja abierta → 422 y no existe la reserva. → test
- [x] **CA5** — Factura a un cliente sin RUC → 422 con el mismo mensaje que al facturar una reserva. → test
- [x] **CA6** — Nota de venta (`00`) → 422. → test
- [x] **CA7** — Línea exonerada (Amazonía) sin ninguna confirmación → venta emitida exonerada, con
  la leyenda de la Amazonía si P1 ya está hecha. → test
- [x] **CA8** — Una línea exonerada y otra gravada → **un** comprobante con las dos líneas, op.
  exoneradas y op. gravadas separadas, IGV solo de la gravada, total igual a lo cobrado. → test
- [x] **CA8b** — Exportación → 422 con el mismo motivo que la facturación de la reserva y no existe
  la reserva; con «Crear sin cobrar» la reserva se crea. → test
- [x] **CA9** — `AGENCIA_COBRO_AL_FACTURAR` apagado → el botón no aparece y el endpoint responde
  422. → test + prueba funcional
- [x] **CA10** — De punta a punta en agencia-demo (tour + traslado, crear y cobrar con Yape,
  imprimir). → prueba funcional
- [x] **CA12** — Columna «Afectación»: un traslado gravado de S/ 118 pasado a Inafecto en la vista
  previa → resumen con op. inafectas 118 e IGV 0, mismo total; al crear y cobrar, su `reserva_item`
  queda 30 / `nacional` con `fecha_override_tributario`, y la factura con `mto_oper_inafectas` 118.
  Una línea sin cambiar queda sin fecha de override. Un tour no muestra la lista. → test + prueba funcional
- [x] **CA13** — Los modales de facturar una reserva siguen igual con la prop generalizada
  (columna, «✎ cambiado», resumen). → prueba funcional rápida
- [x] **CA11** — Suite completa en verde (con T-103 ya hecha); `type-check` sin errores nuevos.

## Seguridad y correctitud
- [x] Permisos / Policy en rutas nuevas: crear venta directa + facturar reserva + permiso de emisión del tipo de comprobante
- [x] Aislamiento de tenant: no aplica
- [x] Validación de entrada / archivos: las de T-011; `lineas.*.tip_afe_igv` `nullable|in:10,20,30`
- [x] Concurrencia (`lockForUpdate`): correlativo con `SerieComprobanteService`, todo en una transacción
- [x] Dinero en centavos: como en T-011
- [x] Fechas con `HoraPeru`: fecha de emisión `HoraPeru::hoyTexto()`
- [x] Tributario sin fallback silencioso: como al facturar una reserva
- [x] Sin `v-html` sin sanitizar: no aplica
- [x] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter="VentaDirecta|ReservaFacturacion"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Rama:** `feat/T-048-venta-directa-crear-cobrar`, creada sobre `test/T-103-fechas-relativas-salidas`
  (commit `26a9ff2`, T-103). Nada unido a `main`.
- **Commits:** ver `git log main..feat/T-048-venta-directa-crear-cobrar` (backend + tests, frontend, este documento).
- **Cómo quedó:**
  - Backend: `VentaDirectaController::store()` acepta `cobrar`, `tipo_comprobante_codigo` (01/03),
    `pagos`, `vuelto_moneda`, `branch_id`. Con `cobrar`, **dentro de la misma transacción** que crea
    la reserva, `facturarYCobrar()` llama a `ReservaFacturacionController::store()` entero
    (todos los pasajeros + los servicios sin pasajero, al contado, a nombre del cliente de la venta).
    Un rechazo de la facturación (JSON 422/403 o excepción) se lanza como excepción → rollback de
    todo: ni cotización, ni reserva, ni venta, ni pagos, ni movimientos de caja.
  - **No se extrajo un servicio para facturar**: el controller ya reusa así
    `AlternativaItemController::store()` y `ReservaController::crearReservaDesdeAlternativa()`, y
    llamar a `store()` reusa todo (guardia tributario, serie con lock, permisos de emisión, RUC,
    `CobroVentaService`, lock de la reserva) sin copiar nada. Como la ruta `reservas/{id}/facturar`
    exige `reservas.editar` por middleware y acá no pasa por la ruta, se revisa a mano (403).
    Lo único extraído: `CobroVentaService::datosDelBloqueDeCobro()` (formas de pago, caja abierta,
    T.C. del día, flag), que `prepararFactura()` armaba en línea y ahora comparten las dos pantallas.
  - Columna «Afectación»: `lineas.*.tip_afe_igv` (`nullable|in:10,20,30`) en vista previa y store.
    `conAfectacionElegida()` reemplaza el tratamiento (20 ⇒ amazonia, si no nacional); un tour con
    `tip_afe_igv` → 422 «La afectación de un tour se corrige en el tour.»; elegir lo mismo que ya
    tenía no cuenta como cambio. Al crear (con o sin cobrar), el `reserva_item` de la línea
    cambiada queda con tip, destino y `fecha_override_tributario = now()`.
  - Vista previa: cada línea trae `editable`, `cambiado` y `montos` (por afectación; un tour, por
    grupo con el reparto de `TourLineaService::calcular()`; en clásico, por servicio + el ajuste de
    redondeo con el valor de la agencia). La respuesta trae `resumen` (gravadas, exoneradas,
    inafectas, IGV, total) y `cobro` (el bloque de T-011).
  - Frontend: `AfectacionLineasFactura.vue` recibe el tipo mínimo `FilaAfectacion`
    (`reservaFacturacionService.ts`); los modales de facturar le pasan lo mismo que antes. En Venta
    directa: la celda en cada línea, resumen tributario, «Crear y cobrar» / «Crear sin cobrar» y el
    panel «Cobro» de la maqueta (Boleta/Factura sin valor por defecto, filtrados por
    `emitir_boleta`/`emitir_factura`; Factura deshabilitada sin RUC; `CobroFacturaCampos`; «Emitir y
    cobrar S/ X»). Al terminar abre la ficha de la reserva (tiene el comprobante y su botón de imprimir).
- **Tests:**
  - Nuevos: `T048VentaDirectaCrearYCobrarTest` (20: CA1-CA9, CA12, permisos de emisión y de facturar,
    factura con RUC, «Crear sin cobrar» con afectación, concepto libre, 422 por código inválido) y
    3 en `VentaDirectaTourTest` (tour mezclado de solo lectura y su resumen 42.37/50/7.63; tour con
    `tip_afe_igv` → 422 en vista previa y al crear; resumen del modo clásico con ajuste de redondeo).
  - `--filter="VentaDirecta|ReservaFacturacion|T011Cobro"`: 132/132.
  - Suite completa (Postgres real, rama con T-103): **2239 passed, 0 failed** (8918 aserciones).
  - `type-check`: 47 errores, los mismos 47 previos (T-101); ninguno en archivos de esta tarea.
    `vite build` correcto.
- **Prueba en pantalla (agencia-demo, admin, Playwright):**
  - CA10: 2 adultos, «Tour Catarata de Ahuashiyacu» (S/ 120, exonerado, fila de solo lectura) +
    traslado S/ 60 gravado → resumen gravadas 50.85 / exoneradas 120 / IGV 9.15. Crear y cobrar con
    Boleta + Yape (n.º de operación) → reserva 40 (VDKM-1126-0000003), venta 23 B001 pagada, 1 pago
    YAPE, 1 movimiento de caja; «Imprimir» → PDF A4 revisado con Ghostscript: op. exoneradas 120,
    inafectas 60, IGV 0, forma de pago Yape con su n.º y la leyenda de la Ley 27037.
  - CA12: el traslado pasado a Inafecto en la línea → «✎ cambiado», inafectas 60, IGV 0, mismo total
    180; en la base, ítem 102 en 30/nacional con `fecha_override_tributario` y el tour (ítem 101)
    sin override. (Con S/ 60 en vez de S/ 118: el caso de S/ 118 está en el test.)
  - CA13: reserva 41 creada con «Crear sin cobrar»; el modal «Facturar» muestra la columna, cambiarla
    guarda, marca «✎ cambiado» y el resumen pasa a inafectas 60. Se cerró sin emitir.
  - CA9 (botón oculto con el flag apagado): probado por test (`cobro.cobro_habilitado = false` en la
    vista previa, y el botón depende de ese campo); no se apagó el flag en pantalla, porque obliga a
    tocar el `.env` y reiniciar Apache.
  - Quedan en agencia-demo la reserva 40 con la boleta B001 (sin enviar a SUNAT) y la reserva 41 sin
    facturar: datos de prueba.
- **Desviaciones de la tarea y por qué:**
  - La celda «Afectación» va **en cada tarjeta de línea** (donde estaban las etiquetas de afectación),
    no en una tabla: Venta directa no tiene tabla de líneas y el resumen es angosto. La etiqueta
    «Exportación» se conserva, porque la celda no la muestra y es lo que bloquea el cobro.
  - Sin `AGENCIA_COBRO_AL_FACTURAR` (o sin `reservas.editar`/permiso de emisión) queda un solo botón
    con su texto de siempre, «Crear venta directa»: «Crear sin cobrar» solo tiene sentido al lado de
    «Crear y cobrar».
  - El IGV del resumen se calcula sobre el total gravado; la factura lo calcula por línea (categoría
    y afectación). Con gravados de varias categorías puede diferir en un céntimo; el total no.
  - `CobroFacturaCampos` recibió la prop opcional `compacto` (dos campos por fila) para caber en la
    columna del resumen; sin ella se ve igual que antes en los modales.

## Hallazgos fuera de alcance
- `prepararFactura()` (y ahora la vista previa de Venta directa) manda `tipo_cambio_dia` como texto
  («3.3600», columna decimal), y `CobroFacturaCampos` espera un número: aviso de Vue en consola al
  abrir «Facturar» de una reserva. En Venta directa se convierte en la pantalla; en los modales de
  facturar sigue el aviso. Arreglo de una línea (castear en `datosDelBloqueDeCobro()` o en los modales).
- El número de un comprobante sin enviar se muestra «B001-0000null» en «Comprobantes emitidos» de la
  reserva (el correlativo se asigna al enviar a SUNAT): ya pasaba con las facturas de reserva.
- Al abrir Venta directa apareció una vez un 500 en `/api/destinos-atractivos`: es el intermitente
  conocido de Apache + dotenv («fe_sendauth: no password supplied»), no de esta tarea.
