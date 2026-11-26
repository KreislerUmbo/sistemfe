# Recorrido: kárdex y servicio único de stock

26-nov-2026, tarea **T-153**. Hasta ahora, el stock de un producto era un número que cambiaba sin
dejar rastro. La venta lo bajaba con bloqueo (T-113), pero la ficha del producto lo pisaba a mano,
y nadie podía responder «¿por qué hay 7 si ayer había 5?». Ahora cada cambio de stock deja una
fila en el **kárdex** (`stock_movimientos`): tipo, cantidad, saldo, documento, motivo y usuario.

Es la misma regla que el dinero: **ningún movimiento es silencioso**. Si el stock cambia, hay una
fila que dice por qué.

Lo que se aprende acá:
- **un solo servicio que mueve el stock** y escribe su historial en la misma transacción;
- **bloquear en orden** para que dos ventas no se traben;
- **la ficha que no pisa lo vendido** mientras estaba abierta;
- **migrar con un saldo inicial** en vez de reconstruir el pasado;
- **un test de concurrencia de verdad**, con dos procesos.

Archivos:
- `api-sistema-fe/app/Services/Stock/StockService.php` — el único que mueve stock (antes
  `Services/Ventas/StockVentaService.php`, que se borró)
- `api-sistema-fe/app/Models/Product/StockMovimiento.php` — el kárdex
- `api-sistema-fe/database/migrations/tenant/core/2026_11_27_100000_create_stock_movimientos_table.php`
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` — `store()`, `update()`, `destroy()`
- `api-sistema-fe/app/Http/Controllers/Sale/NotaElectronicaController.php` — `enviarNotaSunat()`
- `api-sistema-fe/app/Http/Controllers/Product/ProductController.php` — `store()`, `update()`, `kardex()`
- `admin-start-kit/src/views/product/edit.vue` y `src/components/Product/KardexProducto.vue`
- Tests: `tests/Feature/Ventas/KardexStockTest.php`, `KardexConcurrenciaTest.php` y
  `tests/Support/vender_stock_en_proceso.php`

**Al desplegar:** `tenants:migrate-verticales`. La migración crea la tabla y deja una fila «saldo
inicial» por producto que controla stock.

---

## 1. Pantalla → tabla

| Qué pasa | Quién llama | Fila del kárdex (`tipo`) | Documento |
|---|---|---|---|
| Se guarda una venta | `SaleController::store()` → `vender()` | `venta`, cantidad negativa | `venta` / `sales.id` |
| Se edita una venta | `update()` → `cambiarVenta()` | `venta_edicion`, solo la diferencia | `venta` |
| Se elimina una venta | `destroy()` → `devolverVentaEliminada()` | `venta_eliminada`, positiva | `venta` |
| NC con «reponer stock» aceptada | `enviarNotaSunat()` → `devolverPorNota()` | `nc_venta`, positiva | `nota` / `notes.id` |
| Se crea un producto | `ProductController::store()` → `ajustar()` | `ajuste`, «stock inicial» | — |
| Se cambia el stock en la ficha | `update()` → `ajustar()` | `ajuste`, con el motivo escrito | — |
| Ficha → pestaña «Kárdex» | `GET products/{id}/kardex` (`list_product`) | lee | muestra serie-correlativo |

Quedan reservados para T-154 los tipos `compra`, `compra_anulada` y `nc_compra`, y la columna
`costo_unitario`.

**La regla que se puede comprobar:** el `saldo` de la última fila de un producto es igual a
`products.stock`. Es lo que verifica CA1.

## 2. Un solo servicio, y el kárdex dentro de su transacción

Todo pasa por `StockService::aplicar()`:

```php
ksort($delta); // orden por id: mismo orden de bloqueo en todas las ventas

DB::transaction(function () use ($delta, ...) {
    $productos = Product::withTrashed()->whereIn('id', array_keys($delta))
        ->orderBy('id')->lockForUpdate()->get()...;
    // 1) si algún producto no alcanza → 422, y no se movió nada
    // 2) para cada producto: nuevo stock = stock bloqueado − cambio; guardar; anotar la fila
});
```

Tres cosas a mirar:
- **El saldo sale del stock bloqueado**, no de un cálculo aparte. Mientras esta transacción tiene
  el bloqueo, nadie más puede cambiar ese producto, así que el saldo anotado es el real.
- **Primero se valida todo y después se escribe.** Si el tercer producto no alcanza, los dos
  primeros no quedan descontados.
- **Bloquear siempre en orden de id** evita el *deadlock*: si una venta bloquea A y luego B, y otra
  B y luego A, cada una espera a la otra para siempre. Con el mismo orden, la segunda solo espera.

`DB::transaction()` dentro de otra transacción abre un *savepoint*. Por eso el servicio se puede
llamar desde la transacción de la venta y también desde la NC, que antes llamaba sin ninguna
transacción: ahí el `lockForUpdate` no protegía nada.

**Un cambio de orden en `store()`:** el stock se descuenta **después** de crear la cabecera de la
venta, porque la fila del kárdex necesita el `sales.id`. Sigue en la misma transacción y antes del
correlativo y de la caja: si no alcanza, el rollback deshace también la cabecera.

## 3. La ficha que no pisa lo vendido

Antes, guardar la ficha mandaba siempre `stock`. Si abrías un producto con 5, alguien vendía 2 y
después guardabas un cambio de precio, el stock volvía a 5. Ahora:

```ts
const stockCambiado = computed(() => Number(stock.value) !== Number(stockCargado.value));
if (stockCambiado.value) {
  formData.append('stock', stock.value.toString());
  formData.append('motivo_stock', motivoStock.value.trim());
}
```

Y el backend saca `stock` de `allowedFields()`: solo lo cambia `StockService::ajustar()`, con motivo
obligatorio si el producto controla stock (422 si falta). El ajuste y el resto de la ficha van en
una transacción. La imagen nueva se sube **después** del ajuste, así un 422 no deja un archivo
subido de más.

**Un caso borde:** un producto que **empieza** a controlar stock deja una fila automática
(«empieza a controlar stock»), aunque la cantidad no cambie. Sin ella, el último saldo del kárdex
no coincidiría con `products.stock`.

## 4. La migración: saldo inicial, no historia

Reconstruir el kárdex de meses de ventas pasadas sería caro y poco confiable. La migración hace lo
simple: una fila `ajuste` «saldo inicial» por producto que controla stock, con su stock de hoy.
El kárdex empieza el día de la migración.

La condición de la SQL repite la de `Product::controlaStock()` (no es servicio, no se vende sin
stock y tiene `controla_stock`). Incluye los productos eliminados, porque eliminar una venta vieja
todavía puede devolverles stock.

## 5. Probar la concurrencia de verdad

Un test normal corre en un solo proceso y no puede demostrar que dos ventas simultáneas no se
pisan. `KardexConcurrenciaTest` lanza **dos procesos PHP** (`tests/Support/vender_stock_en_proceso.php`)
que venden el mismo producto contra Postgres al mismo tiempo, y comprueba que los saldos sean
consecutivos: ni repetidos ni con saltos. Si se quita el `lockForUpdate`, el test falla con dos
saldos iguales (7 y 8). Así se sabe que el test sí mide lo que dice.

## 6. Los tests

`tests/Feature/Ventas/KardexStockTest.php`:
- `ca1_…` — venta, edición, eliminación y NC dejan su fila con documento y saldo (la NC pasa por `enviarNotaSunat()` con SUNAT simulado);
- `ca2_…` — cambiar el stock en la ficha exige motivo y deja un ajuste con usuario;
- `ca3_…` — un producto que no controla stock no escribe kárdex;
- `ca5_…` — la migración deja el saldo inicial.

`KardexConcurrenciaTest::ca4_…` — dos ventas simultáneas dejan saldos consecutivos. Los tests de
T-113 (`StockVentaTest`) siguen pasando con el servicio nuevo.

## Ejercicios

1. En agencia-demo, abre la ficha del producto 10 en dos pestañas. Vende una unidad y después
   guarda la ficha de la otra pestaña cambiando solo el precio. ¿Qué stock queda? ¿Y si en esa
   pestaña también cambias el stock?
2. ¿Por qué `aplicar()` valida **todos** los productos antes de escribir el primero? Piensa en una
   venta de tres productos donde el tercero no alcanza.
3. Lee los hallazgos de T-153 en el backlog. ¿Qué pasaría con el kárdex si la reposición de una
   NC fallara después de marcarla como aceptada?
4. T-154 va a sumar las compras al kárdex. ¿Qué método nuevo le agregarías a `StockService`, y
   con qué tipo y documento?
