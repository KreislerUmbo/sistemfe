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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
