# Recorrido: en la edición, los pagos se guardan con «Guardar cambios»

21 y 23-nov-2026, tarea **T-119**. Sigue a los recorridos 79 (T-110) y 80 (T-116).
Archivos:
- `admin-start-kit/src/components/Sales/CobroEdicion.vue` — `addPayment()`, `removePayment()`,
  `sugerirCobro()`, `agregarPendiente()`
- `admin-start-kit/src/views/sale/edit.vue` — `store()` llama a `agregarPendiente()`
- `api-sistema-fe/app/Http/Controllers/Sale/SalePaymentController.php` — `destroy()` (estado de pago)

**Al desplegar:** frontend y backend. Sin migraciones.

---

## 1. Qué pasó en la venta 41 de umbo

Al contado, la edición registraba cada pago **al instante** (`POST sale_payments`) y lo borraba al instante
(`DELETE sale_payments/{id}`), por la decisión del 20-nov (T-110). Esos dos endpoints **no mueven la caja**. Al
borrar un Yape de S/ 120 con la papelera, el pago desapareció pero el ingreso siguió vivo en caja, y la venta
quedó en «pago parcial» con 0 pagado (estado invertido en `destroy()`).

La caja solo se cuadra en `PATCH sales/{id}` (`SaleController::update()`):

```
prepararSincronizacionCaja()   compara la «firma» de los movimientos vivos (método|moneda|monto)
                               con la de los pagos del payload
aplicarSincronizacionCaja()    si difieren: corrige los viejos (cash_movement tipo correction)
                               y registra los nuevos — en la misma transacción que la venta
```

## 2. Lo que cambió

| Antes | Ahora |
|---|---|
| Al contado, «Agregar pago» hacía `POST` al instante | todo pago queda en la lista («Sin guardar») y lo guarda «Guardar cambios» |
| La papelera hacía `DELETE` con confirmación | quita de la lista y avisa «Quitaste un pago…» |
| Formulario vacío | al contado sin pagos: Efectivo y el saldo (`sugerirCobro()`) |
| El pago sugerido no contaba si no se pulsaba «Agregar» | «Guardar cambios» lo agrega (`agregarPendiente()`): lo que se ve es lo que se cobra |
| `destroy()` sin nada pagado → estado 2 | → estado 1 (pendiente) |

Ya nada del panel llama a `POST`/`DELETE sale_payments`; siguen existiendo (el portal e-commerce está fuera del
repo) y quedaron en «Por analizar» para retirarlos.

## 3. De la pantalla a la tabla

| En pantalla | Petición | Qué escribe |
|---|---|---|
| Agregar / quitar pago | ninguna | — |
| «Guardar cambios» | `PATCH sales/{id}` con `payments` | `sale_payments` (reemplazo total), `cash_movements` (corrección + nuevos), venta |

## 4. Cómo se probó

`CronogramaEdicionVentaTest`: quitar el pago al editar corrige la caja; `destroy()` del único pago deja estado 1.
`e2e/verif-t119-cobro-edicion.mjs` (venta 35, PATCH interceptado): quitar, sugerido, agregar, guardar sin
«Agregar», formulario vacío; ninguna petición a `sale_payments`. La venta 41 quedó: Efectivo S/ 120, pagada, un
solo ingreso vivo en caja.

## 5. Ejercicios

1. Quita el pago de una venta al contado y sal de la pantalla sin guardar. ¿Cambió algo en la base o en la caja?
2. En `prepararSincronizacionCaja()`, ¿por qué una venta cuyo pago pasa de Efectivo 120 a Yape 120 sí mueve la caja?
3. ¿Qué devuelve `agregarPendiente()` si el monto escrito supera el saldo, y qué hace `store()` con eso?
