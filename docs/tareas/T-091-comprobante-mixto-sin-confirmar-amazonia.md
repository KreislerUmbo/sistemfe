# T-091 — Facturar una reserva con gravado, exonerado e inafecto en el mismo comprobante, viendo y corrigiendo la afectación de cada línea al facturar, sin confirmar la Amazonía ítem por ítem

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | `CLAUDE.md` → contexto tributario (revisar el flujo completo antes de tocar); D18 (en Agencia, 20 = Amazonía) sigue igual |
| **Decisiones** | D-AGE-035 (C4: se mezcla; C5: sin confirmación); D-AGE-036 (series solo por moneda: el mixto va en la serie normal); D-AGE-037 (columna «Afectación» al facturar, sin configuración nueva); `docs/requerimientos/agencia-tributario-simplificado.md` → P5 (versión simple) y P7 |
| **Rama** | `feat/T-091-comprobante-mixto` |

## Contexto
Hoy una reserva con un servicio exonerado (Amazonía) y otro gravado no se puede facturar en un
solo comprobante (salvo dentro de un mismo tour), y cada servicio de la Amazonía tiene que
confirmarlo una persona antes de facturar («Sin confirmar»). El contador dijo que se puede mezclar
(C4) y el usuario decidió quitar la confirmación (C5). En su lugar, quien factura **ve la afectación
de cada línea y la cambia ahí mismo** (D-AGE-037): Gravado (10), Exonerado – Amazonía (20) o
Inafecto (30). **Sin configuración nueva:** el valor por defecto de la agencia y la afectación de
cada tarifa de proveedor siguen como hoy. Esta tarea libera también a T-048.

## Alcance

### 1. Quitar los bloqueos (P7)
- **`CriterioTributarioService::evaluar()`** bloquea **solo exportación/extranjero** (solo o
  mezclado), con el mensaje de hoy. Se quitan:
  - el bloqueo de mezcla de destinos y `mezclaDentroDeUnaCargaTour()` (ya no hace falta la
    excepción de `carga_tour`);
  - el bloqueo «Amazonía sin confirmar», `MENSAJE_AMAZONIA_SIN_CONFIRMAR` y `sin_confirmar_ids`;
  - la clave `confirmado` de la lista neutral (y su comentario).
  Se borran las constantes que queden sin uso.
- **`ReservaFacturacionController::detectarMezclaTributaria()`** (~1504): sin `confirmado` ni
  `items_sin_confirmar_ids`. Puede renombrarse (p. ej. `detectarBloqueoTributario()`); mantener la
  forma de la respuesta para el bloqueo de exportación.
- **El comprobante mixto ya se arma bien** (verificado el 07-nov): `agruparPorCategoria()` separa
  por `tip_afe_igv`, `construirLineas()` calcula el IGV por línea, `mto_oper_gravadas`,
  `mto_oper_exoneradas` y `mto_oper_inafectas` salen por separado (~586-627) y
  `resolverDestinoVenta()` devuelve `amazonia` si hay alguna línea de la Amazonía (así
  `Sale::muestra_leyenda_amazonia` imprime la leyenda). No reescribir eso; solo probarlo (CA1, CA7).
  La serie no cambia: `resolverSerie()` sigue por sucursal + tipo + moneda (D-AGE-036).
- **`VentaDirectaController::facturable()`** (~393): se quita `requiere_confirmar_amazonia` (y el
  campo en `ventaDirectaService.ts`). `ok` es false solo por exportación.

### 2. Columna «Afectación» al facturar (P5, versión simple)
- **Vista previa:** `ReservaFacturacionController::filasDetalle()` (~1249) agrega a cada fila:
  - `tip_afe_igv`: el de `resolverTipAfeIgvItem()` (~1465); en una fila de tour (`carga_tour`)
    con servicios de afectación distinta, `'mixto'`;
  - `editable`: `false` si la fila es de tour, si está excluida o si algún ítem ya está facturado
    (`ReservaVenta::itemIdsFacturados()`); si no, `true`;
  - `cambiado`: `true` si algún ítem tiene `fecha_override_tributario`.
- **Guardar el cambio:** se reusa `ReservaController::overrideTratamientoTributario()` (~1221), sin
  ruta nueva ni migración:
  - `motivo` pasa a **opcional** (`nullable|string|max:500`);
  - `destino_tributario` pasa a **opcional**: si no viene, se deriva del `tip_afe_igv` con
    `ExoneradoEsAmazonia` (20 ⇒ `amazonia`; si no, `nacional`). Si viene `extranjero`, se respeta
    (exportación sigue bloqueada);
  - `fecha_override_tributario` sigue con `now()` (instante UTC);
  - mensaje: «Afectación actualizada».
- **«Corregido a mano» = `fecha_override_tributario` no nula** (antes: `motivo_override_tributario`,
  que ahora puede quedar vacío). Cambiarlo en `ModificacionReservaService::repuntar()` (~362): una
  modificación de la reserva no pisa una línea cambiada. Los datos ya guardados tienen las dos
  columnas llenas juntas: no hay que migrar nada.
- **Frontend:** componente nuevo `components/AgenciaViajes/AfectacionLineasFactura.vue` que recibe
  las filas del detalle y emite el cambio; lo usan `FacturarReservaSimpleModal.vue` y
  `FacturarReservaEspecialModal.vue` (y lo reusará T-048). Por fila: nombre, monto y una lista
  `form-select-sm` Gravado / Exonerado – Amazonía / Inafecto si `editable`; si no, el texto (o
  «Mixto» en un tour). La fila cambiada muestra «✎ cambiado». Al cambiar:
  `reservaService.overrideTratamientoTributario()` con los `reserva_item_ids` de la fila y sin
  motivo → se vuelve a pedir `preparar-factura` y se refrescan los totales, sin cerrar el modal;
  mientras guarda, la lista queda deshabilitada. El resumen muestra op. gravadas, exoneradas,
  **inafectas**, IGV y total, y una línea fija: «El IGV sale del precio: el cliente paga lo mismo».

### 3. Pantallas y textos
- `reservas/detalle.vue`: fuera la insignia «Sin confirmar» (~444) y el cómputo que la usa
  (~1670). Si «Facturar» o algún aviso dependen de ese cómputo, quitarlo también.
- `FacturarReservaSimpleModal.vue` (~33-53) y `FacturarReservaEspecialModal.vue` (~102-116): la
  lista de hoy «nombre: afectación» la reemplaza la columna; fuera el botón «Confirmar tratamiento
  tributario» y el texto «Usa Facturación especial para separar los grupos con distinto tratamiento
  tributario». El aviso de exportación queda.
- Tipos: `items_sin_confirmar_ids` fuera de `reservaFacturacionService.ts`; campos nuevos de la fila
  del detalle.
- **«Corregir tratamiento tributario» de la reserva se queda** (mismo endpoint y su modal), con el
  motivo opcional; su texto deja de decir «confirmar»: el modal y el botón se llaman «Corregir
  tratamiento».
- **Comentarios del código** que digan que hace falta confirmar la Amazonía o que no se mezcla
  (`ReservaController::overrideTratamientoTributario()`, `ReservaItem`, `CriterioTributarioService`,
  `VentaDirectaController::facturable()`, `types/agencia-viajes.ts`): actualizarlos. Aprovechar
  para corregir los de `CotizacionPasajeAereo` y `AlternativaItemController` (~1472, ~1536) que
  dicen que la afectación del pasaje aplica «solo sobre el fee»: la factura la aplica al precio
  completo, y es lo correcto (D-AGE-035).

## Fuera de alcance
- **Configuración nueva** (un segundo valor por defecto, empresa calificada, régimen): no
  (D-AGE-037). El valor por defecto de la agencia y la afectación de las tarifas siguen igual.
- Listas de la cotización (P3), leyenda 2002 en el XML (P1), series por afectación (descartadas,
  D-AGE-036).
- Editar la afectación de un servicio **dentro de un tour** desde la grilla (fila «Mixto», solo
  lectura): se corrige en el tour o con «Corregir tratamiento» de la reserva.
- Guardar **quién** cambió la línea (haría falta una migración): no en esta tarea.
- Exportación / extranjero: sigue bloqueado como hoy.
- Venta directa: la columna entra con T-048.

## Dónde tocar
- `api-sistema-fe/app/Services/AgenciaViajes/CriterioTributarioService.php` → `evaluar()`, `mezclaDentroDeUnaCargaTour()`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php` → `filasDetalle()` (~1249), `detectarMezclaTributaria()` (~1504), `resolverDestinoVenta()` (~1540, solo leer)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php` → `overrideTratamientoTributario()` (~1209-1282)
- `api-sistema-fe/app/Services/AgenciaViajes/ModificacionReservaService.php` → `repuntar()` (~362)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php` → `facturable()` (~393)
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue` (~442, ~878, ~1670, ~2269)
- `admin-start-kit/src/components/AgenciaViajes/FacturarReservaSimpleModal.vue`, `FacturarReservaEspecialModal.vue`, nuevo `AfectacionLineasFactura.vue`
- `admin-start-kit/src/services/admin/reservaFacturacionService.ts`, `reservaService.ts` (~116), `ventaDirectaService.ts`
- Tests que hoy esperan el bloqueo o el motivo: `tests/Unit/AgenciaViajes/CriterioTributarioServiceTest.php`,
  `tests/Feature/AgenciaViajes/ReservaFacturacionTest.php`, `Fila13Fase1cFacturacionTest.php`,
  `VentaDirectaLineasTest.php`, `TipoCambioSanityTest.php`, `ReservaModificacionAplicarTest.php`

## Criterios de aceptación
- [ ] **CA1** — Reserva con un hotel exonerado (20, Amazonía) de S/ 300 y un traslado gravado (10)
  de S/ 118, sin confirmar nada → **un** comprobante en la serie normal: 2 líneas,
  `mto_oper_exoneradas` 300, `mto_oper_gravadas` 100, IGV 18, total 418, `destino = amazonia` y la
  leyenda de la Amazonía en el PDF. → `ReservaFacturacionTest::test_factura_mixta_gravado_y_exonerado_en_un_comprobante`
- [ ] **CA2** — Reserva solo con servicios de la Amazonía nunca confirmados → se factura exonerada.
  → `…::test_amazonia_sin_confirmar_se_factura`
- [ ] **CA3** — Servicio de exportación/extranjero, solo o con otros → 422 con el mensaje de hoy.
  → `CriterioTributarioServiceTest` + `ReservaFacturacionTest`
- [ ] **CA4** — `preparar-factura` de una reserva mixta: sin `bloqueado_tributario` ni
  `items_sin_confirmar_ids`; cada fila trae `tip_afe_igv`, `editable` y `cambiado`; un tour con
  servicios 10 y 20 trae `'mixto'` y `editable = false`; un ítem facturado, `editable = false`. → test
- [ ] **CA5** — `override-tratamiento-tributario` sin motivo ni destino, con `tip_afe_igv = 30`:
  200, el ítem queda 30 / `nacional` con `fecha_override_tributario`; con 20 queda `amazonia`.
  Sobre un ítem facturado sigue el 422; sin `ReservaPolicy::update`, 403. → test
- [ ] **CA6** — Una modificación de la reserva (`repuntar()`) no pisa una línea cambiada sin motivo.
  → test en `ReservaModificacionAplicarTest`
- [ ] **CA7** — Hotel 20 de S/ 300 y una entrada pasada a 30 de S/ 30 desde la columna → la factura
  sale con `mto_oper_exoneradas` 300, `mto_oper_inafectas` 30, IGV 0, total 330; el XML con una
  línea 20 y otra 30 (sin enviar a SUNAT). → test + XML generado
- [ ] **CA8** — Facturación especial por grupo de pasajeros con mezcla → cada comprobante sale
  mixto si su grupo mezcla. → test
- [ ] **CA9** — Venta directa: vista previa con una línea exonerada y otra gravada → `ok = true` y
  sin `requiere_confirmar_amazonia`. → `VentaDirectaLineasTest`
- [ ] **CA10** — Los tests que esperaban el bloqueo de mezcla, la confirmación o el motivo
  obligatorio se cambian a la nueva expectativa, y cada uno se anota en «Resultado» (cuál y por qué).
- [ ] **CA11** — Pantalla en `agencia-demo`: la reserva no muestra «Sin confirmar»; en Facturar y
  Facturación especial la columna cambia una línea, se ve «✎ cambiado» y los totales (gravadas /
  exoneradas / inafectas / IGV) se actualizan sin cerrar el modal, con el mismo total; una reserva
  mixta se factura en un comprobante y el PDF muestra los totales y la leyenda. → prueba en pantalla
- [ ] **CA12** — Suite completa en verde; `npm run type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: sin rutas nuevas; el cambio sigue con `ReservaPolicy::update`, la vista previa con `view`
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: `tip_afe_igv in:10,20,30`; `reserva_item_ids` de **esa** reserva (ya está)
- [ ] Concurrencia (`lockForUpdate`): el override ya bloquea la reserva y revisa lo facturado bajo lock; no cambiar
- [ ] Dinero en centavos: no cambia el cálculo; CA1 y CA7 comprueban que los totales cuadran
- [ ] Fechas con `HoraPeru`: `fecha_override_tributario` es instante UTC; si se muestra, con `formatFechaHora()`
- [ ] Tributario sin fallback silencioso: el `?? '10'` de `resolverTipAfeIgvItem()` no se toca (ahora se **ve** en la columna); no agregar otro valor por defecto
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica (sin migraciones)

## Cómo verificar
```
php artisan test --filter="CriterioTributario|ReservaFacturacion|Fila13Fase1c|VentaDirecta|TipoCambioSanity|ReservaModificacion"
cd admin-start-kit && npm run type-check
```
Prueba en `agencia-demo`: una reserva con un servicio exonerado y otro gravado → Facturar → cambiar
una línea a Inafecto en la columna → emitir → revisar el PDF y el XML generado.

---

## Resultado
- **Commits:** `1d36ab9` backend + tests · `db7f12c` frontend (rama `feat/T-091-comprobante-mixto`).
- **Tests nuevos** (`ReservaFacturacionTest`):
  - CA1 `test_factura_mixta_gravado_y_exonerado_en_un_comprobante` (serie normal, 2 líneas, 300/100/18/418, `amazonia`, `resumenImpresion()['muestra_leyenda_amazonia']`).
  - CA2 `test_amazonia_sin_confirmar_se_factura`.
  - CA3 `test_exportacion_sigue_bloqueada_sola_o_mezclada` (+ `CriterioTributarioServiceTest` y `VentaDirectaLineasTest::test_exportacion_no_es_facturable`).
  - CA4 `test_preparar_factura_mixta_trae_la_afectacion_de_cada_fila`; la fila «mixto» de un tour, en `Fila13Fase1cFacturacionTest::test_tour_mezclado_se_factura_sin_confirmar_y_su_fila_es_mixta`. El caso «ítem facturado ⇒ `editable=false`» se prueba llamando a `filasDetalle()` por reflexión: desde «Facturar» un servicio suelto ya facturado no vuelve a entrar en la vista previa, así que no se puede llegar a él por HTTP.
  - CA5 `test_override_sin_motivo_ni_destino_deriva_el_destino` (30 ⇒ `nacional`, 20 ⇒ `amazonia`, 20→10 vuelve a `nacional`, `extranjero` explícito se respeta) y `test_override_sin_permiso_sobre_la_reserva_da_403`; el 422 sobre un ítem facturado sigue en `test_override_rechaza_item_ya_facturado`.
  - CA7 `test_linea_pasada_a_inafecto_sale_en_la_factura_y_en_el_xml`: 300/30/IGV 0/330 y el XML armado como en el envío (`calcularTotalesComprobante()` + `GreenterService::getInvoice()` + `InvoiceBuilder`, sin firmar ni enviar), con un `TaxExemptionReasonCode` 20 y uno 30.
  - CA8 `test_facturacion_por_grupo_cada_comprobante_sale_mixto_si_su_grupo_mezcla`.
  - `test_correccion_a_mano_gana_sobre_la_afectacion_del_pasaje_aereo` (desviación 1).
- **Tests cambiados (CA10), cuál y por qué:**
  - `CriterioTributarioServiceTest`: fuera los casos de mezcla y de «Amazonía sin confirmar» (ya no bloquean); exportación mezclada ahora da el mensaje de exportación.
  - `Fila13Fase1cFacturacionTest`: `…_sigue_exigiendo_confirmar_la_linea_amazonia`, `servicios_sueltos_de_distinto_destino_siguen_bloqueados`, `tour_mezclado_con_sueltos_…_sigue_bloqueado`, `dos_cargas_mezcladas_…_siguen_bloqueadas` y `combo_…_no_cuenta_como_una_carga` esperaban el bloqueo: ahora facturan en **un** comprobante con destino `amazonia`. Se quitó el helper `confirmarAmazonia()` (ya no hace falta).
  - `ReservaFacturacionTest`: `test_bloquea_facturar_reserva_con_destino_tributario_mezclado` → CA1; `test_guardia_tributario_es_por_subgrupo_…` (esperaba el 422 de Amazonía sin confirmar) → CA2; `test_override_confirma_amazonia_y_permite_facturar` y `test_override_exige_motivo` → CA5.
  - `ReservaModificacionAplicarTest::test_ca4_los_datos_operativos_se_conservan`: la línea corregida a mano se marcaba con el motivo; ahora con `fecha_override_tributario` y sin motivo (CA6).
  - `VentaDirectaLineasTest`: `test_ca7_amazonia_mas_gravado_no_es_facturable_…` → `test_ca9_amazonia_mas_gravado_es_facturable_igual_que_en_la_facturacion`; `test_ca7_solo_amazonia_…_pide_confirmar` y `test_solo_gravado_…_no_pide_confirmar` sin `requiere_confirmar_amazonia`.
  - `TipoCambioSanityTest`: no hizo falta tocarlo (sus «sin confirmar» son del tipo de cambio, no del tributario).
- **Suite:** filtro de la tarea (`CriterioTributario|ReservaFacturacion|Fila13Fase1c|VentaDirecta|TipoCambioSanity|ReservaModificacion`) 256/256 en verde; suite completa: 2212 pasan, 4 fallan, ninguno por esta tarea: `Fila13Fase1cFacturacionTest::descuento_del_combo_se_reparte_entre_los_grupos` (el intermitente conocido; pasa solo) y tres de salidas operativas que fallan también solos, al parecer por una fecha fija que venció (ver Hallazgos). Frontend: `vite build` OK; `npm run type-check` con 47 errores, todos previos y en archivos que esta tarea no toca (portal, `fake-backend.ts`, `paquetes/form.vue`, `TourIncluidoForm.vue`…), ninguno nuevo.
- **Prueba en pantalla (CA11), `agencia-demo`, 16-nov:** la reserva 30 (RDKM-1026-0000002) ya no muestra «Sin confirmar» y cada servicio tiene «Corregir tratamiento». En «Facturar», el hotel pasó de Exonerado a Gravado en la columna: «✎ cambiado», totales a op. gravadas 593.22 / exoneradas 870.00 / IGV 106.78 con el mismo total 1570.00, sin cerrar el modal; se emitió **un** comprobante (B001, venta 22, sin enviar a SUNAT) con una línea 10 y otra 20, y el PDF muestra op. gravadas, exoneradas, IGV, total y la leyenda de la Amazonía. En «Facturación especial» (reserva 32) la línea pasó a Inafecto y los totales cambiaron igual; después se devolvió a Exonerado (queda marcada «cambiado»). El modal «Corregir tratamiento» abre con motivo opcional. Quedan en `agencia-demo`: la venta 22 con su cobro en la caja abierta de admin, y la marca de corrección en el hotel de la reserva 30 y en el de la 32.
- **Desviaciones de la tarea y por qué** (las cuatro, consultadas antes de codificar):
  1. `resolverTipAfeIgvItem()` (y su espejo en `detalle.vue`): si la línea tiene `fecha_override_tributario`, gana el `tip_afe_igv` del `reserva_item`, también en un pasaje aéreo. Sin esto, cambiar un pasaje en la columna no tenía efecto, porque `cotizacion_pasaje_aereo.tip_afe_igv` iba primero. El `?? '10'` no se tocó.
  2. Exportación mezclada con otro destino da el mensaje de exportación (antes daba el de mezcla, que ya no es cierto); `MENSAJE_MEZCLA_TRIBUTARIA` se borró.
  3. «Corregir tratamiento» queda en **cada** servicio no facturado de una reserva activa (antes solo aparecía con «Sin confirmar»). En un tour se eligen las líneas con casillas, para poder corregir una sola sin pisar la mezcla. Al cambiar el IGV, el destino se propone solo (20 ⇒ Amazonía; si no, Nacional), para no guardar «10 + amazonia».
  4. La columna va **dentro** de la tabla «Qué entra en este comprobante» (`DetalleComprobantePreview`, prop `con-afectacion`); `AfectacionLineasFactura.vue` dibuja la celda de cada fila y el resumen va debajo de la tabla. El guardado vive en `composables/agencia-viajes/useAfectacionFactura.ts`, que comparten los dos modales (y podrá usar T-048).
  - Además: los modales ya no exponen `refrescarPreview` ni emiten `confirmar-tributario`; emiten `afectacion-cambiada` y la pantalla toma la reserva actualizada (insignias al día).

## Hallazgos fuera de alcance
- **Tests con fecha que venció el 16-nov-2026** (fallan también solos; no tocan facturación): `Fila13Fase2aSalidasTest::test_estados_avanzan_un_paso_y_realizada_no_puede_ser_futura` («La salida es del 15-nov-2026, en el futuro»), `Fila13Fase2aSalidasTest::test_una_salida_cancelada_se_reactiva_y_sus_servicios_vuelven_a_por_reservar` (422 en vez de 200) y `GananciaSalidaTest::test_tarifa_de_temporada` (450 en vez de 550: no toma la tarifa de temporada; probablemente por la misma salida del 15-nov, no lo verifiqué). Hay que fijar sus fechas relativas a `HoraPeru::hoy()`.
- `CobroFacturaCampos`: la prop `tipoCambioDia` llega como texto (`"3.3600"`) y Vue avisa en consola en cada vista previa (`prepararFactura` devuelve `TipoCambioSunat->venta` sin convertir a número).
- La insignia de la reserva muestra «Amazonía (exonerado)» según `destino_tributario` aunque la línea sea 10 (un dato viejo «10 + amazonia»); desde esta tarea el override ya no guarda esa combinación, pero los datos viejos pueden tenerla.
- El PDF de una venta no enviada muestra un número (`B001-00001003`) aunque `correlativo` es null; no es de esta tarea, conviene revisar de dónde sale.
