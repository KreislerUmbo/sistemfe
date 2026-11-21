# Recorrido: editar una venta — pagos, anticipo y cronograma

20 y 21-nov-2026, tarea **T-116**. Sigue al recorrido 79 (T-110), que pasó `edit.vue` a los
componentes de registro y dejó anotados los errores que esta tarea corrige.
Archivos:
- `admin-start-kit/src/views/sale/edit.vue` — `loadSale()`, `config()`, `store()`, `totalACobrar()`
- `admin-start-kit/src/components/Sales/CobroEdicion.vue` — pagos en memoria o al instante
- `admin-start-kit/src/components/CronogramaCuotasCampos.vue` — lo usan también registro y facturar reserva
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` — `show()`, `update()`,
  `anticipoDeLaEdicion()`, `prepararPagosVenta()`

**Al desplegar:** frontend (`npm run build`) y backend. Sin migraciones.

---

## 1. Dos formas de guardar un pago

Al editar, el botón «Agregar pago» hace **dos cosas distintas** según la venta:

| La venta… | El pago… | Por qué |
|---|---|---|
| está guardada al contado y sigue al contado | se registra **al instante** (`POST sale_payments`) | decisión del 20-nov (recorrido 79 §4) |
| está a crédito en pantalla, o **guardada** a crédito | queda **en memoria** y lo guarda «Guardar cambios» | `sale_payments` rechaza con 422 las ventas a crédito; `PATCH sales/{id}` reemplaza todos los pagos con los del payload |

Lo decide `pagosEnMemoria()` en `CobroEdicion.vue`. Como desde afuera no se nota, la pantalla lo
dice bajo el botón («se registra al instante» / «se guarda al pulsar Guardar cambios») y los pagos en
memoria llevan la marca **«Sin guardar»** (no tienen `id`).

## 2. El anticipo resta de todo

Una venta con anticipo (comprobante previo, `n_comprobante_anticipo` + `amount_anticipo`) ya tiene
esa parte pagada. Por eso todo se mide contra **lo que queda por cobrar**:

```
totalACobrar()    = total − anticipo            (anticipo = 0 si no hay comprobante)
montoAFinanciar() = totalACobrar() − pagos       → lo que reparte el cronograma
debt              = totalACobrar() − pagos       → el backend valida el cronograma contra esto
```

El backend hace la misma cuenta: `prepararPagosVenta($request, $anticipo)` no deja que los pagos
pasen de `total − adelantos − anticipo`, y `anticipoDeLaEdicion()` guarda el anticipo (sin
comprobante → 0; mayor que el total o negativo → 422; si el payload no trae los campos, conserva el
guardado).

## 3. El cronograma al abrir y al cambiar

- `GET sales/{id}` devuelve `cronograma` (las cuotas **vigentes**, no las anuladas) y `branch_id`
  (la sucursal de la serie). No van en `SaleResource` para no sumar consultas al listado.
- `loadSale()` carga las cuotas, su número y la periodicidad **deducida** de los días entre fechas
  (`periodicidadDe()`: 7 → semanal, 14-16 → quincenal, 28-31 → mensual; si no, personalizada).
- Cada «Guardar cambios» de una venta a crédito **anula** las cuotas vigentes y crea las del payload
  (`update()`, paso 4). Las anuladas quedan en `installments` con su motivo.

`CronogramaCuotasCampos` ayuda a que la suma calce (el backend la exige al céntimo,
`CronogramaCreditoValidator`):

| Situación | Qué muestra |
|---|---|
| La suma calza | «Las cuotas suman S/ X, igual al monto a financiar» |
| No calza (cambió un pago, un producto…) | «Faltan / Sobran S/ X» con **Regenerar** y **Ajustar última cuota** |
| Cambió N° de cuotas o periodicidad | «pulsa Regenerar» y el botón resaltado. **No** regenera solo: borraría fechas puestas a mano |
| Una cuota sobra | papelera en la fila: se renumeran y baja el N° de cuotas |

`armadoCon` guarda con qué N° y periodicidad se armó la tabla: así sabe si quedó desactualizada.

## 4. De crédito a contado

Antes `update()` lo bloqueaba si existía **cualquier** cuota, y toda venta a crédito guardada tiene
cuotas: nunca podía volver a contado. Ahora (decisión del usuario, 21-nov):

- **Con cobros activos** (`payment_applications.estado = 'activo'`) → 422 «ya tiene cuotas cobradas…
  Anula primero esos cobros desde Cuentas por Cobrar».
- **Con un adelanto aplicado** → 422, como antes (bloqueo aparte).
- **Sin cobros** → se guarda como contado, las cuotas vigentes quedan `anulada` («La venta pasó de
  crédito a contado…») y la mora se apaga. Lo que no se pague queda en `saldo_pendiente` (deuda de
  contado, la ve Cuentas por Cobrar).

## 5. De la pantalla a la tabla

| En pantalla | Petición | Qué escribe |
|---|---|---|
| Abrir la edición | `GET sales/{id}` + 4 catálogos **en paralelo** (`config()`) | nada |
| «Agregar pago» al contado | `POST sale_payments` | `sale_payments` |
| «Agregar pago» a crédito | ninguna (memoria) | — |
| «Guardar cambios» | `PATCH sales/{id}` | venta (+ anticipo), detalles, `sale_payments`, caja, `installments` (anula y crea), en una transacción |

## 6. Cómo se probó

- Backend: `AnticipoEdicionVentaTest` (7), `CronogramaEdicionVentaTest` (4: cuotas vigentes, contado
  sin cronograma, crédito → contado sin cobros, con cuota cobrada → 422), `SaleControllerSerieComprobanteTest`.
- Frontend: `useProductPricing.test.ts` (precio base a 6 decimales), Vitest completo, `vite build`.
- Navegador (agencia-demo, venta 35 simulada, PATCH interceptado): `e2e/verif-t116-*.mjs`.

## 7. Ejercicios

1. En `CobroEdicion.vue`, ¿qué pasa si una venta guardada **a crédito** se pasa a «Contado» en
   pantalla y se agrega un pago? ¿Va a `POST sale_payments`? (pista: `guardadaACredito`).
2. Una venta de S/ 300 con anticipo de S/ 100 y pago inicial de S/ 50: ¿cuánto debe sumar el
   cronograma? ¿Qué `debt` manda `store()`?
3. Escribe «31/11/2026» en una cuota. ¿Qué fecha queda? (ver T-117).
4. Busca en `update()` dónde se anulan las cuotas al pasar a contado y explica por qué no se
   borran con `DELETE`.
