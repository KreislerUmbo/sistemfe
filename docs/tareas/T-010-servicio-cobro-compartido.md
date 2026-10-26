# T-010 — Servicio compartido de cobro, con n.º de operación y moneda de pago

| | |
|---|---|
| **Módulo** | VEN + CAJ |
| **Depende de** | T-009 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 1, "Cómo se guarda un pago" y R5/R7 |
| **Rama** | `feat/T-010-servicio-cobro` |

## Contexto
Guardar los pagos de una venta y sus movimientos de caja vive hoy en métodos privados de
`SaleController` (`validarPagosPayload()`, `resolverSesionCajaAbierta()`,
`crearCashMovementsPorPagos()`). Agencia (T-011) necesita la misma regla. Además el pago gana
n.º de operación y la posibilidad de pagar en una moneda distinta a la de la venta.

## Alcance
- Migración tenant `core/`, `sale_payments`: `nro_operacion` (nullable, max 50),
  `moneda_pago` (string 3), `monto_pagado` (decimal 12,2), `tipo_cambio` (decimal 10,4). Los
  pagos ya guardados: `moneda_pago` = `sales.currency`, `monto_pagado` = `amount`,
  `tipo_cambio` = 1 (en la migración: es un cálculo dentro del mismo tenant, sin catálogo externo).
- **`amount` sigue siendo el monto en la moneda de la venta** (R7): no cambiar a nadie que lo lea.
- Migración tenant `core/`, `payment_methods.monedas` (json, default `["PEN","USD"]`); Yape y Plin
  quedan en `["PEN"]` (por `code`). Editable en la pantalla de formas de pago.
- `sales.vuelto_monto` (decimal 12,2, nullable) y `sales.vuelto_moneda` (string 3, nullable).
- Servicio nuevo (por ejemplo `App\Services\Cobro\CobroVentaService`) con:
  - validar los pagos (método activo y permitido en esa moneda, monto > 0, tipo de cambio > 0,
    equivalente = `monto_pagado` convertido a la moneda de la venta, redondeado a 2 decimales);
  - validar el total contra un saldo esperado, aceptando **vuelto** solo si hay algún pago en
    efectivo y el vuelto no supera lo pagado en efectivo (en equivalente);
  - resolver la sesión de caja abierta del usuario (422 "Abre tu caja para cobrar");
  - guardar `sale_payments` y un `cash_movement` por pago **en la moneda y monto del pago**
    (`currency` = `moneda_pago`, `amount` = `monto_pagado`), más uno de salida por el vuelto
    (tipo nuevo, por ejemplo `sale_change`, en la moneda del vuelto).
  - Validaciones que lanzan HttpException: llamables **antes** de abrir la transacción (ver el
    comentario de `prepararSincronizacionCaja()` sobre el `catch(\Throwable)`).
- `SaleController::store()` y `update()` pasan a usar el servicio, **sin cambiar su comportamiento
  actual** (Ventas retail sigue con pagos en la moneda de la venta y sin vuelto por ahora).
- `prepararSincronizacionCaja()`: la "firma" de pagos viejos vs. nuevos compara
  método + moneda + `monto_pagado` (hoy compara `amount`, que dejaría de coincidir con el monto del
  movimiento cuando la moneda difiera).
- `update()` conserva `nro_operacion` al recrear los pagos (R5).
- Frontend Ventas (`sale/register.vue`, `sale/edit.vue`): campo **n.º de operación** en cada fila
  de pago (opcional), y lo reenvía al editar.
- Pantalla de formas de pago (`cash/payment-methods.vue`): elegir las monedas que acepta.

## Fuera de alcance
- Moneda de pago distinta y vuelto **en la pantalla de Ventas** (el servicio lo soporta; la UI de
  retail queda para después).
- Agencia (T-011), PDF (T-014), caja por moneda (T-013).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php`
- `api-sistema-fe/app/Services/Cobro/CobroVentaService.php` (nuevo)
- `api-sistema-fe/app/Models/Sale/SalePayment.php`, `app/Models/Cash/PaymentMethod.php`
- `api-sistema-fe/app/Http/Controllers/Cash/PaymentMethodController.php`
- `admin-start-kit/src/views/sale/register.vue`, `edit.vue`, `cash/payment-methods.vue`

## Criterios de aceptación
- [ ] **CA1** — Registrar una venta en Ventas con un pago Yape y `nro_operacion='123456'` lo guarda;
  editar la venta sin tocar los pagos lo conserva. → test nuevo
- [ ] **CA2** — Pagos ya existentes quedan con `moneda_pago`/`monto_pagado`/`tipo_cambio` coherentes
  tras la migración. → test de migración o verificación en `umbo` local
- [ ] **CA3** — Servicio: venta S/ 3,750 pagada con US$ 1,000 a 3.75 → `sale_payments.amount=3750`,
  `monto_pagado=1000`, `moneda_pago='USD'`; movimiento de caja US$ 1,000 en `USD`. → test unitario
- [ ] **CA4** — Servicio: Yape en USD → rechazado (método no permitido en esa moneda). → test
- [ ] **CA5** — Servicio: pago de más con efectivo → vuelto guardado en la venta y movimiento de
  salida; pago de más solo con Yape → rechazado; vuelto mayor que lo pagado en efectivo → rechazado. → test
- [ ] **CA6** — Sin caja abierta → 422 "Abre tu caja para cobrar". → test
- [ ] **CA7** — Todos los tests existentes de Ventas y Caja siguen en verde sin cambiar lo que esperan.
- [ ] **CA8** — Formas de pago: Yape/Plin nacen solo en soles; se puede editar. → test + pantalla

## Seguridad y correctitud
- [ ] Permisos: editar formas de pago usa el permiso ya existente del catálogo de Caja
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: `nro_operacion` texto corto; `moneda_pago` en (PEN, USD); tipo de cambio > 0 y con tope razonable
- [ ] Concurrencia: no aplica (la venta es nueva o ya está bloqueada en `update()`)
- [ ] Dinero: un solo punto de conversión y redondeo (el servicio); comparar en centavos
- [ ] Fechas con `HoraPeru`: `date_payment` con `HoraPeru::hoyTexto()` como hoy
- [ ] Tributario: no aplica (la moneda del comprobante no cambia)
- [ ] `v-html`: no aplica
- [ ] Migraciones: nuevas en `tenant/core/`; `tenants:migrate-verticales`

## Cómo verificar
```
php artisan test --filter=Cobro
php artisan test   # suite completa, contra PostgreSQL
cd admin-start-kit && npm run type-check && npm run build
```
Prueba funcional en `umbo` local: registrar y editar una venta con n.º de operación.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
