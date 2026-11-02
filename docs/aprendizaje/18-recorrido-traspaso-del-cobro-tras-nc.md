# Recorrido: usar lo ya cobrado al volver a facturar tras una nota de crédito (T-012)

29-oct-2026, tarea **T-012** (unida a main en `7df0bcd`; la tarea se borró al cerrar, git la conserva), revisión de fallos R2.
Se apoya en el cobro al facturar de T-011 y en la caja por moneda de T-013.

---

## 1. El problema

Corregir una factura de reserva es: **nota de crédito (NC)** → la reserva libera los servicios →
**facturar de nuevo**. Desde T-011 el comprobante nuevo exige cobrar. Pero el cliente ya pagó la
factura anulada: si se cobra otra vez, la caja cuenta el dinero dos veces.

La solución: el dinero ya cobrado **pasa al comprobante nuevo**. Se guarda como un pago del
comprobante nuevo con `origen = 'traspaso'` y **sin movimiento de caja**, porque el dinero entró a
la caja una sola vez, con el comprobante original.

## 2. Archivos

| Archivo | Nuevo o cambiado | Qué hace |
|---|---|---|
| `database/migrations/tenant/core/2026_10_30_100000_add_origen_y_traspaso_to_sale_payments_table.php` | **nuevo** | `sale_payments.origen` (`cobro` por defecto) y `traspasado_de_sale_id` (FK a `sales`) |
| `app/Services/Cobro/CobroVentaService.php` | cambiado | `disponiblesParaTraspasar()`, `prepararTraspasos()`, `guardarTraspasos()` |
| `app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php` | cambiado | `prepararFactura()` ofrece los saldos; `store()` acepta `traspasos[]` |
| `app/Models/Sale/SalePayment.php` | cambiado | los dos campos nuevos en `$fillable` |
| `admin-start-kit/src/helpers/cobroFactura.ts` | cambiado | tipos y cuentas del traspaso (solo para mostrar) |
| `admin-start-kit/src/components/AgenciaViajes/CobroFacturaCampos.vue` | cambiado | casilla "Usar lo cobrado en F001-23…" con monto editable |
| `FacturarReservaSimpleModal.vue`, `FacturarReservaEspecialModal.vue` | cambiados | pasan los traspasos al componente, a la validación y al envío |
| `tests/Feature/Cobro/T012TraspasoCobroTest.php` | **nuevo** | 9 tests |

## 3. El recorrido

```
Reserva → Facturar ──► GET reservas/{id}/preparar-factura ──► prepararFactura()
   │       └─ traspasos_disponibles: [{sale_id, comprobante, disponible}]   (solo si hay cobro habilitado)
   │
   │  El modal marca por defecto "Usar lo cobrado en B002 (disponible US$ 424.00)":
   │  lo que falta pagar con dinero nuevo baja a 0 y desaparece la fila de efectivo.
   │
   └─► POST reservas/{id}/facturar ──► ReservaFacturacionController::store()
          ├─ validación: traspasos[] (máx. 20), sale_id y monto > 0
          ├─ si hay pagos: prepararPagos() + sesión de caja abierta  (sin pagos, no hace falta caja)
          └─ transacción:
                ├─ lockForUpdate de la reserva
                ├─ crea la venta, aplica anticipos
                ├─ disponiblesParaTraspasar(..., bloquear: true)   ← bloquea las ventas de origen
                ├─ prepararTraspasos(): cada sale_id debe estar en el disponible; monto ≤ disponible
                ├─ validarContraSaldo(pagos + traspasos): tiene que cubrir el saldo (vuelto solo de efectivo)
                ├─ registrar(pagos)        → sale_payments + movimientos de caja
                └─ guardarTraspasos()      → sale_payments origen='traspaso', SIN movimientos de caja
```

**¿Cómo sabe de qué reserva era la venta anulada?** Una NC total borra la fila de `reserva_ventas`
(así la reserva queda libre para facturar). Por eso se sigue la cadena que sobrevive:
`sale_detail_items → reserva_items.reserva_id`.

**La cuenta del disponible** (por cada venta de la misma reserva y la misma moneda):

```
a favor del cliente = pagado − max(0, total − NC aceptadas)
disponible          = max(0, a favor − ya traspasado desde ella)
```

Venta de 500 pagada completa y NC de 500 → 500 − 0 = **500**. Pagada completa con NC de 200 →
500 − 300 = **200**. Pagada solo 100 con NC de 200 → debe 300, 100 − 300 → **0** (esos 100 todavía
cubren deuda). Una NC sin aceptar por SUNAT no cuenta (disponible 0).

**¿Por qué bloquear las ventas de origen?** Dos personas facturando a la vez la misma reserva
podrían usar el mismo saldo. Con `lockForUpdate()` la segunda espera, y al calcular ya ve el
disponible descontado (CA4).

## 4. Las funciones

**`disponiblesParaTraspasar($reservaId, $moneda, $bloquear)`** — devuelve, indexado por `sale_id`,
las ventas con algo a favor. Lee en centavos. `pagado` incluye traspasos que esa venta recibió, así
se puede corregir más de una vez seguida.

**`prepararTraspasos($pedidos, $disponibles, $moneda)`** — no confía en el navegador: un `sale_id`
que no está en el disponible (otra reserva, otra moneda, sin NC aceptada) da 422; sumar varias
líneas del mismo origen y pasarse del disponible también. Devuelve filas con el mismo formato que
un pago sin efectivo, para pasarlas a `validarContraSaldo()`.

**`guardarTraspasos()`** — crea el `sale_payment` con `method_payment = 'TRASPASO'`, moneda y monto
de la venta, `tipo_cambio = 1` y `traspasado_de_sale_id`. No toca `cash_movements`.

**`CobroFacturaCampos.vue`** — propone usar todo lo traspasable hasta el saldo, y mientras el
usuario no decida sigue el saldo. Si el traspaso cubre todo, quita la fila de pago nuevo. Los
números del navegador son solo para mostrar: el servidor decide.

## 5. Tests

| Test | Criterio |
|---|---|
| `ca1_nc_total_y_refacturar_con_traspaso_no_mueve_la_caja` | CA1 |
| `ca2_nc_parcial_acota_el_disponible_al_monto_de_la_nota` | CA2 |
| `ca3_traspaso_mayor_al_disponible_es_422_y_no_deja_nada` | CA3 |
| `ca4_el_segundo_traspaso_ve_el_disponible_descontado` | CA4 |
| `ca5_otra_reserva_u_otra_moneda_no_se_ofrece_y_se_rechaza` | CA5 |
| `ca6_traspaso_y_pago_nuevo_juntos_cubren_el_saldo` | CA6 |
| `ca7_nc_sin_aceptar_no_deja_disponible` | CA7 |
| `la_vista_previa_ofrece_los_saldos_traspasables` | la pantalla recibe los saldos |
| venta pagada en parte | la NC baja primero la deuda |

Además, `cobroFactura.test.ts` (Vitest, 5 tests) cubre las cuentas del navegador.

## 6. Ejercicios

1. Una venta de 500 pagada 300 + NC total de 500. ¿Cuánto se puede traspasar? (Haz la cuenta con la
   fórmula y luego compruébalo en un test.)
2. En `disponiblesParaTraspasar()`, ¿qué pasaría si se calculara `pagado` sin incluir los
   traspasos recibidos? ¿Qué caso dejaría de funcionar?
3. El traspaso no genera movimiento de caja. Si se elimina el pago de traspaso del comprobante
   nuevo (`SalePaymentController::destroy()`), ¿qué pasa con el disponible de la venta de origen?
   ¿Te parece correcto?
