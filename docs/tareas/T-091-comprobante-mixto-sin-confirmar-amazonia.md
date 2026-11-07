# T-091 — Facturar una reserva con gravado y exonerado en el mismo comprobante, sin confirmar la Amazonía ítem por ítem

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | `CLAUDE.md` → contexto tributario (revisar el flujo completo antes de tocar); D18 (en Agencia, 20 = Amazonía) sigue igual |
| **Decisiones** | D-AGE-035 (C4: se mezcla; C5: sin confirmación); `docs/requerimientos/agencia-tributario-simplificado.md` → P7 |
| **Rama** | `feat/T-091-comprobante-mixto` |

## Contexto
Hoy una reserva con un servicio exonerado (Amazonía) y otro gravado no se puede facturar en un
solo comprobante (salvo dentro de un mismo tour), y cada servicio de la Amazonía tiene que
confirmarlo una persona antes de facturar ("Sin confirmar"). El contador dijo que se puede mezclar
(C4) y el usuario decidió quitar la confirmación (C5): la agencia es de la Amazonía y casi todo va
exonerado. Esta tarea libera también a T-048 (Venta directa «Crear y cobrar»).

## Alcance
- **`CriterioTributarioService::evaluar()`** bloquea **solo exportación/extranjero** (solo o
  mezclado), con el mensaje de hoy. Se quitan:
  - el bloqueo de mezcla de destinos y `mezclaDentroDeUnaCargaTour()` (ya no hace falta la
    excepción de `carga_tour`);
  - el bloqueo «Amazonía sin confirmar», `MENSAJE_AMAZONIA_SIN_CONFIRMAR` y `sin_confirmar_ids`;
  - la clave `confirmado` de la lista neutral (y su comentario).
  Se borran las constantes que queden sin uso.
- **`ReservaFacturacionController::detectarMezclaTributaria()`**: sin `confirmado` ni
  `items_sin_confirmar_ids`. Puede renombrarse (p. ej. `detectarBloqueoTributario()`) si queda
  más claro; mantener la forma de la respuesta para el bloqueo de exportación.
- **El comprobante mixto ya se arma bien** (verificado el 07-nov): `agruparPorCategoria()` separa
  por `tip_afe_igv`, `construirLineas()` calcula el IGV por línea, `mto_oper_gravadas` y
  `mto_oper_exoneradas` salen por separado y `resolverDestinoVenta()` devuelve `amazonia` si hay
  alguna línea de la Amazonía (así `Sale::muestra_leyenda_amazonia` imprime la leyenda). No
  reescribir eso; solo probarlo con mezcla (CA1).
- **`VentaDirectaController::facturable()`**: se quita `requiere_confirmar_amazonia` (y el campo
  en `ventaDirectaService.ts`). `ok` es false solo por exportación.
- **Pantallas:**
  - `reservas/detalle.vue`: fuera la insignia «Sin confirmar» (~444) y el cómputo que la usa
    (~1670). Si «Facturar» o algún aviso dependen de ese cómputo, quitarlo también.
  - `FacturarReservaSimpleModal.vue` (~46-53) y `FacturarReservaEspecialModal.vue` (~116): fuera
    el botón «Confirmar» de la Amazonía y el texto «Usa Facturación especial para separar los
    grupos con distinto tratamiento tributario». El aviso de exportación queda.
  - Tipos: `items_sin_confirmar_ids` fuera de `reservaFacturacionService.ts`.
- **«Corregir tratamiento tributario» se queda** (endpoint
  `reservas/{id}/override-tratamiento-tributario` y su modal): es la forma de corregir una línea
  hasta que exista la grilla de facturar (P5). Su texto deja de decir «confirmar»: el modal y el
  botón se llaman «Corregir tratamiento». `motivo_override_tributario` sigue marcando una
  corrección a mano, y `ModificacionReservaService::repuntar()` (~362) la sigue respetando.
- **Comentarios del código** que digan que hace falta confirmar la Amazonía o que no se mezcla
  (`ReservaController::overrideTratamientoTributario()`, `ReservaItem`, `CriterioTributarioService`,
  `VentaDirectaController::facturable()`, `types/agencia-viajes.ts`): actualizarlos. Aprovechar
  para corregir los de `CotizacionPasajeAereo` y `AlternativaItemController` (~1472, ~1536) que
  dicen que la afectación del pasaje aplica «solo sobre el fee»: la factura la aplica al precio
  completo, y es lo correcto (D-AGE-035).

## Fuera de alcance
- Grilla de facturar con casilla de afectación (P5), listas de la cotización (P3), empresa
  calificada (P2), leyenda 2002 en el XML (P1), series por afectación (P8).
- Exportación / extranjero: sigue bloqueado como hoy.
- Borrar `motivo_override_tributario` / `fecha_override_tributario` o migrar datos: las columnas
  se quedan.
- T-048 (se hace después, sobre esta).

## Dónde tocar
- `api-sistema-fe/app/Services/AgenciaViajes/CriterioTributarioService.php` → `evaluar()`, `mezclaDentroDeUnaCargaTour()`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php` → `detectarMezclaTributaria()` (~1504), `resolverDestinoVenta()` (~1540, solo leer)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php` → `facturable()` (~393)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php` → comentario de `overrideTratamientoTributario()` (~1209)
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue` (~442, ~878, ~1670, ~2269)
- `admin-start-kit/src/components/AgenciaViajes/FacturarReservaSimpleModal.vue`, `FacturarReservaEspecialModal.vue`
- `admin-start-kit/src/services/admin/reservaFacturacionService.ts`, `ventaDirectaService.ts`
- Tests que hoy esperan el bloqueo: `tests/Unit/AgenciaViajes/CriterioTributarioServiceTest.php`,
  `tests/Feature/AgenciaViajes/ReservaFacturacionTest.php`, `Fila13Fase1cFacturacionTest.php`,
  `VentaDirectaLineasTest.php`, `TipoCambioSanityTest.php`, `ReservaModificacionAplicarTest.php`

## Criterios de aceptación
- [ ] **CA1** — Reserva con un hotel exonerado (20, Amazonía) de S/ 300 y un traslado gravado (10)
  de S/ 118, sin confirmar nada → **un** comprobante: 2 líneas, `mto_oper_exoneradas` 300,
  `mto_oper_gravadas` 100, IGV 18, total 418, `destino = amazonia` y la leyenda de la Amazonía en el
  PDF. → `ReservaFacturacionTest::test_factura_mixta_gravado_y_exonerado_en_un_comprobante`
- [ ] **CA2** — Reserva solo con servicios de la Amazonía nunca confirmados → se factura exonerada.
  → `…::test_amazonia_sin_confirmar_se_factura`
- [ ] **CA3** — Servicio de exportación/extranjero, solo o con otros → 422 con el mensaje de hoy.
  → `CriterioTributarioServiceTest` + `ReservaFacturacionTest`
- [ ] **CA4** — `preparar-factura` de una reserva mixta: sin `bloqueado_tributario` ni
  `items_sin_confirmar_ids`; la vista previa muestra las dos líneas con su IGV. → test
- [ ] **CA5** — Facturación especial por grupo de pasajeros con mezcla → cada comprobante sale
  mixto si su grupo mezcla. → test
- [ ] **CA6** — Venta directa: vista previa con una línea exonerada y otra gravada → `ok = true` y
  sin `requiere_confirmar_amazonia`. → `VentaDirectaLineasTest`
- [ ] **CA7** — «Corregir tratamiento» sigue funcionando (cambia `tip_afe_igv` con motivo) y una
  modificación de la reserva no pisa esa corrección. → tests existentes en verde
- [ ] **CA8** — Los tests que esperaban el bloqueo de mezcla o de confirmación se cambian a la
  nueva expectativa, y cada uno se anota en «Resultado» (cuál y por qué).
- [ ] **CA9** — Pantalla en `agencia-demo`: la reserva no muestra «Sin confirmar»; Facturar y
  Facturación especial no tienen el botón de confirmar; una reserva mixta se factura en un
  comprobante; el PDF muestra op. exoneradas, op. gravadas, IGV y la leyenda. El XML se genera con
  una línea 10 y otra 20 (sin enviar a SUNAT). → prueba en pantalla
- [ ] **CA10** — Suite completa en verde; `npm run type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: sin rutas nuevas; «Corregir tratamiento» sigue con `ReservaPolicy::update`
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no cambia (la facturación ya bloquea la reserva)
- [ ] Dinero en centavos: no cambia el cálculo; CA1 comprueba que los totales cuadran
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: el `?? '10'` de `resolverTipAfeIgvItem()` no se toca
  (lo vuelve visible P5); no agregar ningún otro valor por defecto
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter="CriterioTributario|ReservaFacturacion|Fila13Fase1c|VentaDirecta|TipoCambioSanity|ReservaModificacion"
cd admin-start-kit && npm run type-check
```
Prueba en `agencia-demo`: una reserva con un servicio exonerado y otro gravado → Facturar →
revisar el PDF y el XML generado.

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
