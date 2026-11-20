# Recorrido: el stock se valida en el backend, con bloqueo, al vender, editar y eliminar

19-nov-2026, tarea **T-113**. Construida por Sonnet, revisada por Opus (dos arreglos en la revisión),
probada en el navegador. Archivos:
- `api-sistema-fe/app/Services/Ventas/StockVentaService.php`: `descontar()`, `devolver()`, `cambiar()`
- `api-sistema-fe/app/Models/Product/Product.php`: `controlaStock()`
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php`: `store()`, `update()`, `destroy()`
- `api-sistema-fe/app/Http/Controllers/Sale/NotaElectronicaController.php`: reposición de la NC
- `admin-start-kit/src/helpers/ventaRetail.ts`: `controlaStock()`, `faltaStockEnEdicion()`
- `admin-start-kit/src/views/sale/edit.vue`: `selectAndAddProduct()`, `cantidadOriginal`
- Tests: `tests/Feature/Ventas/StockVentaTest.php` (7) y `src/helpers/ventaRetail.test.ts`

**Al desplegar:** los servicios y los productos «venta sin stock» dejan de descontar stock. Medir con
una consulta los productos con `stock < 0` en cada tenant (vienen de antes: se descontaba todo) y
decidir si hace falta un ajuste de inventario (otra tarea).

---

## 1. El problema

Antes, `store()` hacía `decrement('stock')` por cada línea. `decrement` es atómico (no pierde
unidades), pero **no valida**: si quedaba 1 y dos cajeros cobraban a la vez, se guardaban las dos
ventas y el stock quedaba en −1. El único control era la pantalla, con el stock que leyó al buscar
el producto (ya viejo cuando el otro cajero cobra).

Además: se descontaba **todo**, servicios incluidos; eliminar una venta no devolvía el stock; y
dos ventas con los mismos productos en distinto orden podían trabarse entre sí (deadlock).

## 2. Un solo criterio: ¿este producto controla stock?

```php
// Product::controlaStock()
tipo_bien_servicio !== 'SERVICIO'  &&  disponiblidad !== 1 («venta sin stock»)  &&  controla_stock
```

La pantalla usa exactamente lo mismo (`ventaRetail.ts::controlaStock()`), por eso
`ProductResource` ahora devuelve `controla_stock`. Si los dos criterios fueran distintos, la
pantalla dejaría cobrar algo que el backend rechaza (o al revés).

## 3. El servicio: agrupar → bloquear en orden → validar → mover

```
StockVentaService::aplicar($delta)          $delta = [product_id => cantidad con signo]
├─ agrupar(): suma TODAS las líneas del producto (T-112: puede haber varias filas del mismo)
├─ Product::whereIn(ids)->orderBy('id')->lockForUpdate()   ← bloquea las filas hasta el commit
├─ valida: si cambio > 0 y stock − cambio < 0 → 422 «Solo quedan N unidades de «X».»
└─ decrement('stock', cambio)               (cambio negativo = devolver)
```

- **El lock** (`SELECT … FOR UPDATE`): el segundo cajero espera a que el primero termine y lee el
  stock ya descontado; ahí recibe el 422. Por eso la validación vale aunque sean simultáneas.
- **El orden por `id`**: si A bloquea 5 y espera el 9, y B bloqueó el 9 y espera el 5, se traban.
  Si todos bloquean de menor a mayor, eso no puede pasar.
- **Dentro de la transacción**: el lock dura hasta el `commit`; si algo falla, el `rollBack` deshace
  también el descuento.

Los tres usos:

| Dónde | Llamada | Qué mueve |
|---|---|---|
| `store()` | `descontar($lineas)` | todo lo vendido; va **primero**, antes de caja y correlativos |
| `update()` | `cambiar($antes, $despues)` | solo la diferencia por producto (lo nuevo − lo que ya tenía) |
| `destroy()` y NC con reposición | `devolver($lineas)` | lo de sus líneas, nunca falla |

## 4. Editar: solo la diferencia

Una venta con 4 unidades ya las descontó. Si el almacén está en 0, editarla sin cambiar cantidades
no puede exigir 4 de nuevo. `cambiar()` calcula `nuevo − antes` por producto: de 4 a 4 → 0 (nada),
de 4 a 2 → −2 (devuelve 2), de 4 a 5 → +1 (pide 1 al almacén).

`update()` además bloquea la **venta** al empezar: dos ediciones a la vez de la misma venta no
ajustan el stock dos veces.

La pantalla (`edit.vue`) hace la misma cuenta con `faltaStockEnEdicion()`: lo disponible para la
venta = stock del almacén + `cantidadOriginal` del producto (lo que tenía al abrirla).

## 5. Eliminar: devolver una sola vez (arreglo de la revisión)

`destroy()` lee la venta **antes** de la transacción. Con un doble clic llegan dos peticiones; la
segunda espera el lock y, cuando lo obtiene, la primera ya la borró (soft delete). Como las líneas
(`sale_details`) no se borran, `devolver()` las encontraría y repondría el stock otra vez. Por eso:

```php
if (!Sale::whereKey($venta->id)->lockForUpdate()->first()) {
    return;   // ya la eliminó otra petición
}
```

`first()` respeta el `SoftDeletes`: una venta borrada devuelve `null`. El test lo simula con el
evento `Sale::retrieved` (borra la venta justo después de que `destroy()` la lee).

## 6. Recorrido en la pantalla

1. Un bien con stock 1. Ventas → Registrar, vender 2 → Cobrar deshabilitado; si se fuerza, el
   backend responde «Solo quedan 1 unidades…» y no se guarda nada (ni caja ni correlativo).
2. Dos pestañas, mismo bien con stock 1, cobrar a la vez → una se guarda, la otra recibe el aviso;
   el stock queda en 0.
3. Vender un servicio → su stock no cambia.
4. Editar una venta de 4 con el almacén en 0 → puede dejarla en 4 o bajarla; subirla avisa «Solo hay
   4 unidades para esta venta». Bajarla a 2 y guardar → el almacén sube a 2.
5. Eliminar la venta → el almacén recupera sus unidades.

## 7. Para practicar

1. ¿Por qué `descontar()` va al **inicio** de la transacción de `store()` y no al final? Piensa en
   cuánto tiempo quedan bloqueados los productos y en qué se guardaría si fallara al final.
2. En `update()`, ¿qué pasa si se cambia el producto de una línea existente (mismo `id` de línea,
   otro producto)? Sigue `cambiar()`: ¿por qué agrupar por producto lo resuelve solo?
3. La reposición de la NC no está en una transacción propia. ¿Por qué igual no pierde unidades?
   (pista: `decrement` es una sola sentencia `UPDATE … SET stock = stock - x`).

**Relacionado:** recorridos 72 (pantalla de venta), 75 (varias filas del mismo producto, T-112).
