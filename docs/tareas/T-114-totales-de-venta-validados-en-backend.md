# T-114 — Totales de la venta validados en el backend contra la suma de sus líneas

| | |
|---|---|
| **Módulo** | VEN |
| **Depende de** | T-109 (las líneas ya se validan sin el permiso de precio) |
| **Reglas** | CLAUDE.md «Ventas» (atomicidad de `update()`); exoneración Amazonía (`resolverTipAfeIgv()`) |
| **Decisiones** | 19-nov-2026, el usuario: va como tarea aparte de T-109 y aplica a **todos** (con o sin permiso) |
| **Rama** | `feat/T-114-totales-venta-backend` |

## Contexto
`SaleController::store()` (~810-835) y `update()` (~1190-1215) guardan los totales de la cabecera
(`subtotal`, `igv`, `total`, `mto_oper_gravadas/exoneradas/inafectas/exportacion/gratuitas`,
`isc_total`, `icbper_total`, `ivap_total`, `total_impuestos`, `mto_imp_venta`, retención,
detracción, percepción…) tal como llegan del front. T-109 valida cada **línea** sin el permiso de
precio, pero con un request armado las líneas pueden cuadrar y la cabecera decir menos: la caja,
el PDF y el XML usan la cabecera. Revisión de T-109, 19-nov.

## Alcance
- Antes de abrir la transacción, recalcular la cabecera desde las líneas del payload con la **misma
  cuenta de `sumDetails()`** de `register.vue` (leerla entera; incluye descuento global prorrateado,
  gratuitas, exportación, IVAP, ISC, ICBPER, retención, detracción, percepción y anticipos) y
  rechazar con 422 si algún total no coincide (tolerancia de céntimos).
- Aplica con y sin el permiso `cambiar_precio_venta`: el permiso deja cambiar precios, no mandar
  totales que no salen de las líneas.
- Un servicio aparte (como `PrecioVentaValidator`), no más código dentro del controlador.
- `update()`: misma validación sobre el payload completo.
- **Ojo (20-nov):** `edit.vue` tiene su **propia** copia de `sumDetails()` (~1358), distinta de la de
  `register.vue` (~688). Antes de codificar, compararlas: si en algún régimen dan totales distintos,
  validar `update()` con la cuenta de `register.vue` rechazaría ediciones legítimas hechas desde
  `edit.vue`. Si difieren, **parar y mostrar la diferencia** (no elegir una por tu cuenta); T-110
  reemplazará `edit.vue` por los componentes de `register.vue`.

## Fuera de alcance
- Recalcular y **guardar** los totales en el backend en lugar de los del front (posible después;
  primero validar, para no cambiar documentos que hoy cuadran).
- Ventas creadas por Agencia (`ReservaFacturacionController`) y adelantos (`AdvanceController`):
  no pasan por `SaleController::store()`.

## Dónde tocar
- `admin-start-kit/src/views/sale/register.vue` → `sumDetails()` (solo leer: es la referencia)
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` → `store()` (~760), `update()` (~1032)
- `api-sistema-fe/app/Services/Ventas/` → servicio nuevo

## Criterios de aceptación
- [ ] **CA1** — Una venta armada por la pantalla (cada régimen: gravada, exonerada Amazonía,
  exportación, gratuita, IVAP, ISC, ICBPER, retención, detracción, percepción, con anticipo) → 200
  → un test por caso.
- [ ] **CA2** — Mismas líneas con `total` o `igv` rebajados → 422, con y sin el permiso de precio.
- [ ] **CA3** — Descuento global prorrateado: la cabecera cuadra → 200.
- [ ] **CA4** — `update()` con totales que no cuadran → 422 y nada cambia.
- [ ] **CA5** — La suite de ventas existente sigue en verde (si un fixture traía totales que no
  cuadran, corregir el fixture y anotarlo).

## Seguridad y correctitud
- [ ] Validación de entrada: totales recalculados en el backend.
- [ ] Dinero: `BigDecimal`, tolerancia de céntimos.
- [ ] Tributario sin fallback silencioso: un régimen que no se pueda reproducir → preguntar, no dejarlo pasar.
- [ ] `update()` sigue en una sola transacción.
- [ ] Lo demás: no aplica.

## Cómo verificar
```
php artisan test --filter=Sale
```
Ventas reales desde la pantalla en agencia-demo (boleta Amazonía, factura con detracción, venta con anticipo).

---

## Resultado
- **Commits:** en la rama `feat/T-114-totales-venta-backend` (`git log main..feat/T-114-totales-venta-backend`).
- **Tests:** `T114TotalesVentaTest` (20 tests: CA1 por régimen — gravada, exonerada Amazonía, exportación,
  gratuita, IVAP, ISC 01/02/03, ICBPER, retención, detracción, percepción, anticipo —, CA2 con y sin
  permiso, CA3, CA4). Suite completa contra Postgres real: **2412 pasan, 1 falla**
  (`Fila13Fase2cOperacionTest › en un viaje de varios días solo se mueve el tour`): falla igual en `main`
  sin estos cambios, tiene la fecha `2026-11-18` fija y hoy es 20-nov ("La nueva fecha no puede ser
  anterior a hoy"). No es de T-114.
- **Prueba en pantalla (agencia-demo):** **pendiente**. El MCP de Playwright no conectó en esta sesión;
  hay que hacer a mano una boleta Amazonía, una factura con detracción y una venta con anticipo.
- **Comparación de `sumDetails()` (pedida por la tarea):** `register.vue` (~688) y `edit.vue` (~1358)
  dan **la misma cabecera en todos los regímenes**: mismo prorrateo del descuento global, IGV, ISC,
  ICBPER, retención, detracción y percepción; `updateItem()` de línea idéntico; los `mto_oper_*`, `ivap_total`,
  `total_impuestos`, `mto_imp_venta` y `redondeo` de `store()` también. Por eso no paré. Lo único que
  difiere no entra en los totales validados: (a) suma de pagos (`Number(p.amount || 0)` en edit); (b) el
  anticipo — register usa Adelantos (`advance_applications`), edit usa `sale_anticipo` /
  `n_comprobante_anticipo` —, que solo afecta `debt` y `paid_out`: el `total` de la cabecera es bruto en
  ambas; (c) edit manda `sale_details` completo (register quita `uid` y `precio_manual`) y no manda
  `commercial_quote_id`.
- **Desviaciones de la tarea y por qué:**
  - Servicio `app/Services/Ventas/TotalesVentaValidator.php`; se llama desde `store()` y `update()` justo
    después de `validarPrecioDeVenta()`, antes de abrir la transacción (en `update()` un 422 no toca nada).
  - **Un campo ausente cuenta como 0** (es lo que `store()` guarda con `?? 0`), así que una cabecera sin
    `mto_oper_*`, `total_impuestos`, `valor_venta`, `mto_imp_venta` o `redondeo` se rechaza. La pantalla
    siempre los manda. **CA5:** 9 fixtures traían la cabecera incompleta; se completaron
    (`AdvanceIntegridadTest`, `T009MonedaCajaTest`, `T010VentasCobroTest`, `DescripcionLineaVentaTest`,
    `DireccionClienteTest`, `SaleControllerSerieComprobanteTest`, `StockVentaTest`, y `T109…` vía el trait
    nuevo `Concerns/CabeceraDePantalla`, que arma la cabecera como la pantalla, en floats y aparte del
    validador). Una línea de `DescripcionLineaVentaTest` cambiaba la cantidad sin tocar la cabecera: ahora
    la cabecera acompaña.
  - **Afectación de línea fuera de 10/11/17/20/30/40 → 422.** La pantalla no genera otras; con otra, un
    `mto_oper_*` quedaría sin reproducir (regla "tributario sin fallback silencioso").
  - **Tasas de régimen:** retención y percepción salen de `tax_configs` (cualquier tasa activa; sin
    configuración, 3 % y 2 % como el front) y detracción del código elegido. El mínimo es S/ 700 fijo para
    retención y percepción porque `tax_configs` no tiene `monto_minimo` (el front también cae siempre en
    700), y `detraction_codes.monto_minimo` para detracción.
  - `mto_imp_venta` se acepta a ±0,06 de la base (el redondeo a 0,1 puede desempatar distinto en JS y en
    PHP), y `redondeo` debe ser la diferencia. El resto, ±0,01 (`total` ±0,02).
  - Usa los datos de la línea y de `product` (`is_isc`, `percentage_isc`) **del payload**, igual que
    `sumDetails()`; la cabecera tiene que cuadrar con las líneas enviadas, no con el catálogo.

## Hallazgos fuera de alcance
- `Fila13Fase2cOperacionTest` (Agencia) tiene la fecha `2026-11-18` fija: falla desde que pasó esa fecha,
  también en `main`.
- `sales` **no tiene** `porcentaje_retencion` y `store()` tampoco lo guarda (sí guarda el de detracción y
  el de percepción): no se puede saber con qué tasa se retuvo una venta ya emitida.
- `SaleController::config()` no manda `es_default` ni `monto_minimo` en `parametros_tributarios`, así que
  el front siempre usa IGV 18 % y mínimo 700: esas dos columnas/configuraciones no hacen nada en la pantalla.
- `debt`, `paid_out` y `state_payment` siguen viniendo del front sin recalcular (no son totales de la
  cabecera, fuera de esta tarea).
- Con el permiso de precio nada valida que `discount_global` esté entre 0 y el subtotal: un descuento
  mayor da un total negativo pero coherente, así que esta tarea lo deja pasar.
- Sin el permiso de precio, `PrecioVentaValidator` sigue revisando cada línea; con el permiso las líneas
  siguen sin revisión (es el diseño de T-109), y la cabecera ahora solo se exige coherente con ellas.
