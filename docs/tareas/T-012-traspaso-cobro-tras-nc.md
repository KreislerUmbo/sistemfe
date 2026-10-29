# T-012 — Traspaso del cobro tras una nota de crédito

| | |
|---|---|
| **Módulo** | AGE + CAJ |
| **Depende de** | T-011 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 1, respuesta 6 y "Traspaso del cobro tras una NC" (R2) |
| **Rama** | `feat/T-012-traspaso-cobro-nc` |

## Contexto
Corregir una factura de reserva es: NC (por ejemplo motivo 01) → `ReservaVenta::
liberarPorNotaDeCredito()` libera los servicios → volver a facturar. Con T-011 el nuevo
comprobante exigiría cobrar otra vez, y la caja contaría el dinero dos veces. El usuario decidió:
el dinero ya cobrado **pasa al comprobante nuevo**.

## Alcance
- Cálculo de **disponible para traspasar** de una venta de la reserva: lo pagado
  (`sale_payments.amount`, sin contar traspasos que ella misma recibió dos veces) acotado al monto
  de sus NC **aceptadas**, menos lo ya traspasado desde ella. Solo ventas de la **misma reserva** y
  **misma moneda** que el comprobante nuevo; el cliente puede ser otro.
- `sale_payments`: `origen` (`cobro` por defecto / `traspaso`) y `traspasado_de_sale_id`
  (nullable, FK a `sales`). Migración tenant `core/`.
- `ReservaFacturacionController`: `prepararFactura()` devuelve los saldos traspasables;
  `store()` acepta `traspasos[]` (`sale_id`, `monto`) que cuentan para cubrir el saldo junto con
  `pagos[]`. Bajo el `lockForUpdate()` de la reserva, bloquear también las ventas de origen y
  recalcular el disponible (dos personas no usan el mismo saldo).
- Un traspaso crea un `sale_payment` con `origen='traspaso'` y **ningún movimiento de caja**.
- Frontend (bloque "Cobro" de T-011): "Usar lo cobrado en F001-23 (disponible S/ 500.00)" con
  monto editable hasta el disponible.

## Fuera de alcance
- Devolver dinero al cliente por una NC sin volver a facturar ("Por analizar").
- Traspaso entre monedas distintas.
- Traspaso fuera de Agencia (retail).

## Archivos probables
Leer por rango o con `grep`, no enteros (el controlador tiene ~1800 líneas). Líneas al 29-oct.
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php`:
  - `prepararFactura()` (l. 172): el bloque de cobro de T-011 está en l. ~323 (`cobro_habilitado`);
    ahí se agregan los saldos traspasables.
  - `store()` (l. 342): validación de `pagos.*` en l. ~395; `prepararPagos()`/`sesionAbierta()` en
    l. ~491-507; `lockForUpdate()` de la reserva en l. ~534; saldo a cobrar, `validarContraSaldo()`
    y `registrar()` en l. ~750-761. Ahí entran `traspasos[]`.
- `api-sistema-fe/app/Services/Cobro/CobroVentaService.php`: `validarContraSaldo()` (l. 102),
  `guardarPagos()` (l. 200), `crearMovimientos()` (l. 217), `centavos()` (l. 235). El traspaso
  guarda `sale_payment` pero **no** pasa por `crearMovimientos()`.
- Cálculo del disponible: método nuevo en `CobroVentaService` (o junto a
  `ReservaVenta::itemIdsFacturados()`, l. 61 de `app/Models/AgenciaViajes/ReservaVenta.php`).
- NC aceptada: `notes.status = 'aceptado'` y `tipo_doc = '07'` (ver
  `NotaElectronicaController.php` l. ~662, solo leer).
- Migración: `database/migrations/tenant/core/`; el último cambio a `sale_payments` es
  `2026_10_29_100000_add_nro_operacion_y_moneda_pago_to_sale_payments_table.php` (T-011).
- Frontend: `admin-start-kit/src/components/AgenciaViajes/CobroFacturaCampos.vue` (bloque "Cobro"
  de T-011), usado por `FacturarReservaSimpleModal.vue` y `FacturarReservaEspecialModal.vue`.
- Tests: tomar como base `tests/Feature/Cobro/T011CobroFacturarReservaTest.php` (arma reserva,
  caja y cobro); test nuevo `tests/Feature/Cobro/T012TraspasoCobroTest.php`.

**Rama:** crearla desde `main` **después** de unir T-013 (cambia el cálculo de caja por moneda que
usa CA1).

## Criterios de aceptación
- [ ] **CA1** — Factura S/ 500 cobrada → NC total aceptada → nueva factura S/ 500 con traspaso de
  S/ 500 → venta pagada, 0 movimientos de caja nuevos, esperado de caja sin cambio. → test nuevo
- [ ] **CA2** — NC parcial de S/ 200 sobre una factura cobrada S/ 500 → disponible S/ 200. → test
- [ ] **CA3** — Traspaso mayor que el disponible → 422. → test
- [ ] **CA4** — Dos traspasos desde la misma venta en dos facturas seguidas: el segundo ve el
  disponible descontado. → test
- [ ] **CA5** — Venta de otra reserva o en otra moneda → no se ofrece y el backend la rechaza. → test
- [ ] **CA6** — Traspaso + pago nuevo combinados cubren el saldo. → test
- [ ] **CA7** — NC todavía no aceptada → disponible 0. → test

## Seguridad y correctitud
- [ ] Permisos: `ReservaPolicy`; sin rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: `sale_id` debe pertenecer a la reserva (no confiar en el frontend)
- [ ] Concurrencia: `lockForUpdate()` sobre las ventas de origen dentro de la transacción
- [ ] Dinero: centavos al comparar
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: nueva en `tenant/core/`; `tenants:migrate-verticales`

## Cómo verificar
```
php artisan test --filter=Traspaso
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:** ver `git log feat/T-012-traspaso-cobro-nc`.
- **Tests:** `tests/Feature/Cobro/T012TraspasoCobroTest.php` (CA1–CA7 + vista previa + venta pagada en parte): 9 pasan.
  Suite completa del backend contra PostgreSQL: 1670 pasan, 0 fallan (corrida antes de agregar el
  test de la vista previa, que se corrió aparte). Frontend: `vue-tsc` sin errores en lo tocado (los
  errores previos son de otros archivos); `vite build` OK; Vitest nuevo `cobroFactura.test.ts` (5).
- **Desviaciones de la tarea y por qué:**
  - **Prueba funcional en agencia-demo (29-oct, Playwright):** reserva 29 en US$: facturar con
    cobro en efectivo (venta 19, 1 movimiento de caja) → NC total aceptada → "Facturar" muestra
    "Usar lo cobrado en B002 (disponible US$ 424.00)" marcado y con 424 → emitir: venta 20 pagada
    con un pago `TRASPASO` de 424 desde la venta 19 y **0 movimientos de caja**. La NC aceptada se
    insertó directo en la base del demo (no hay SUNAT en local) y se llamó a
    `liberarPorNotaDeCredito()`. Quedan en agencia-demo la reserva 29, las ventas 19-20, esa NC y
    una caja abierta de admin@agencia-demo.test.
  - **Reserva de la venta de origen:** una NC total borra su fila de `reserva_ventas`, así que
    "misma reserva" se reconoce por `sale_detail_items → reserva_items.reserva_id` (sobrevive a la NC).
  - **"Pagado" del origen** = todos sus `sale_payments`, incluidos traspasos que ella recibió
    (permite encadenar corrección tras corrección); el dinero no se cuenta dos veces porque lo que
    ya salió se resta (`traspasado_de_sale_id`). Interpretación mía del texto "sin contar traspasos…
    dos veces"; si se quería otra, cambia una línea en `disponiblesParaTraspasar()`.
  - **Fórmula del disponible (revisión, 29-oct):** `max(0, pagado − (total − NC aceptadas)) −
    traspasado`, no `min(pagado, NC)`. Dan lo mismo si el origen estaba pagado completo; pagado en
    parte (crédito o pendiente de cobro), la NC baja primero la deuda: 500 con 100 pagados y NC de
    200 → debe 300, nada a favor (con `min` ofrecía 100 que siguen cubriendo deuda).
  - **Revisión de código (29-oct), descartado tras verificar:** el modal no arrastra la decisión de
    traspaso (`CobroFacturaCampos` se vuelve a montar con cada vista previa: `v-if` del modal y
    `v-if="cargando"`); la FK `traspasado_de_sale_id` sin ON DELETE es a propósito (no se borra un
    origen cuyo dinero ya pasó a otra venta; `LimpiarOperacionPrueba` borra `sale_payments` antes
    que `sales`); `SaleController::update()` no llega a estas ventas (destino con `reserva_ventas`,
    origen ya enviado a SUNAT); anular el destino con NC no pierde el dinero: pasa a ser origen
    (cubierto por CA4). `traspasos[]` quedó con `max:20`.
  - `method_payment` del traspaso se guarda como `'TRASPASO'` (columna de texto libre).
  - El frontend propone usar todo lo traspasable (hasta el saldo) sin que el usuario lo marque, para
    evitar cobrar dos veces; puede destildarlo o editar el monto.
  - Migración `2026_10_30_100000_…` aplicada a la base de tests y a los tenants locales con
    `tenants:migrate-verticales`; falta en producción al desplegar.

## Hallazgos fuera de alcance
- `SaleController::update()` borra y recrea los `sale_payments`. Hoy no alcanza a ventas con
  traspaso (ver Desviaciones), pero si se afloja alguno de sus candados, perdería `origen` y
  `traspasado_de_sale_id` (relacionado con R5).
- `SalePaymentController::destroy()` puede borrar un pago de la venta de origen después de
  traspasarlo: el destino queda con un traspaso de dinero que ya no figura en el origen. Bloquearlo
  si lo traspasado desde ella (`traspasado_de_sale_id`) supera lo que le quedaría pagado.
- El rótulo del origen usa `n_operacion` o serie-correlativo; mientras el comprobante no se envió a
  SUNAT no tiene correlativo y sale solo la serie ("B002"). En la práctica una NC aceptada ya tiene
  correlativo, pero el caso de prueba lo mostró así.
- Otros lectores de `sale_payments` (Cuentas por Cobrar, reportes por forma de pago) no se revisaron
  para ver cómo tratan filas `origen='traspaso'` (no son dinero nuevo).
