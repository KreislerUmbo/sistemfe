# Recorrido: líneas de producto en la compra, stock y costo promedio

26-nov-2026, tarea **T-154**. Hasta T-135 una compra era solo montos. Ahora puede **detallar
productos**: esas líneas suman stock por el servicio único de T-153 y actualizan el **costo
promedio** del producto. Una compra sin líneas sigue igual que antes (gastos y todo lo de Agencia).

Lo que se aprende acá:
- **el promedio ponderado**, y por qué se calcula bajo el mismo bloqueo que el stock;
- **con o sin IGV**: el costo depende de si el IGV va a crédito fiscal;
- **deshacer en orden**: anular una compra o una NC sin dejar el costo inventado;
- **lo que queda por devolver** de una compra, calculado y no guardado;
- **por qué hay un tope de 100 líneas** (`max_input_vars` de PHP).

Archivos:
- Migraciones (`database/migrations/tenant/core/`): `2026_11_29_100000_create_compra_lineas_table.php`,
  `2026_11_29_100100_add_con_credito_fiscal_to_compras.php`, `2026_11_29_100200_add_costo_promedio_to_products.php`
- `app/Models/Compras/CompraLinea.php`, `Compra.php` (`lineas()`, `TIPOS_CON_LINEAS`)
- `app/Services/Stock/StockService.php`: `entrarPorCompra()`, `revertirCompra()`,
  `salirPorNotaCompra()`, `revertirNotaCompra()`, `aplicar()` y `costear()`
- `app/Services/Compras/CompraService.php`: `prepararLineas()`, `exigirSinCambiosDeStock()`,
  `exigirDevolvible()`, `devolvible()`, `anular()`
- `app/Http/Controllers/Compras/CompraController.php` (`respuesta()`, `aviso()`) y
  `Product/ProductController.php` (`show()`, `kardex()`)
- Frontend: `src/components/Compras/LineasCompra.vue`, `src/views/compras/form.vue` e `index.vue`,
  `src/helpers/compras.ts` (`valorVentaLinea()`, `montosDeLinea()`), `components/Product/KardexProducto.vue`,
  `views/product/edit.vue`
- Tests: `tests/Feature/CompraLineasTest.php`, `tests/Feature/Ventas/CompraConcurrenciaTest.php`
  (con `tests/Support/comprar_en_proceso.php`)

**Al desplegar:** `tenants:migrate-verticales`.

---

## 1. Pantalla → tabla

No hay rutas nuevas: son las de T-135, con `lineas[]` dentro del mismo multipart.

| Qué pasa | Ruta | Tablas que escribe |
|---|---|---|
| Registrar una compra con productos | `POST compras` | `compras`, `compra_lineas`, `products.stock` y `costo_promedio`, `stock_movimientos` (tipo `compra`) |
| Registrar una NC (07) con productos | `POST compras` | lo mismo, con tipo `nc_compra` (el stock baja) |
| Editar la cabecera | `POST compras/{id}` | solo `compras` (las líneas no se tocan) |
| Anular una compra | `POST compras/{id}/anular` | `compras.anulada_*`, `products`, `stock_movimientos` (`compra_anulada`) |
| Anular una NC con productos | `POST compras/{id}/anular` | ídem, con `nc_compra_anulada` (las unidades vuelven) |
| Ver la compra | `GET compras/{id}` | lee; trae `lineas`, `devolvible` y, si no cuadra, `aviso` |
| Ficha del producto | `GET products/{id}` y `products/{id}/kardex` | lee `costo_promedio` y `stock_movimientos.costo_unitario` |

## 2. Qué tipos llevan productos (D4)

`Compra::TIPOS_CON_LINEAS = ['01', '03', '04', '07', '12', '91']`. Los de entrada son factura,
boleta, liquidación de compra, ticket y comprobante del exterior. El 07 (NC) es la devolución al
proveedor. Cualquier otro tipo con líneas responde 422 «Ese tipo de comprobante no lleva
productos».

Un producto que **no controla stock** (un servicio) puede ir en la línea, pero no mueve stock ni
costo: `aplicar()` filtra con `controlaStock()`, igual que en las ventas.

## 3. Con o sin IGV: `con_credito_fiscal` (D1)

Si la empresa recupera el IGV de la compra (crédito fiscal), ese IGV no es parte del costo: el
producto le costó el valor de venta. Si no lo recupera, el IGV sí es costo. Por eso, en
`prepararLineas()`:

```php
$monto = $datos['con_credito_fiscal'] ? $linea['valor_venta'] : $linea['total'];
$costo = $monto × tipo_cambio ÷ (cantidad × factor);   // costo_unitario_base, en soles
```

- La pantalla propone la casilla **marcada** en 01, 07, 08 y 14, y **sin marcar** en el resto, y el
  usuario la puede cambiar. Así no hay un supuesto tributario escondido.
- **En soles siempre (D5):** en una compra en dólares se multiplica por `compras.tipo_cambio`. Los
  montos de la línea quedan en la moneda de la compra.
- **Factor:** una caja de 12 con cantidad 2 son 24 unidades base, y el costo se divide entre 24.
  La línea ya trae `presentacion_id` y `factor` para cuando existan las presentaciones.

Validaciones de cada línea: `valor_venta = cantidad × valor_unitario` al céntimo, y `total =
valor_venta + igv`. Esa segunda se agregó en la revisión, porque sin crédito fiscal el total **es**
el costo y no se podía dejar libre por la API. Las cuentas se hacen con `BigDecimal` y se redondea
una sola vez (D6).

## 4. El promedio ponderado (D7)

Con S = stock antes, P = promedio antes, q = unidades que entran y c = su costo unitario:

```
nuevo P = (S·P + q·c) / (S + q)        y si S ≤ 0 o P es null, nuevo P = c
```

Ejemplo de CA1: hay 10 unidades a S/ 5 y entran 10 a S/ 6, así que (10·5 + 10·6) / 20 = **5.500000**.

**Por qué va dentro de `aplicar()`:** S y P se leen de la fila del producto que `aplicar()` ya
bloqueó con `lockForUpdate`. Si el promedio se calculara afuera, dos compras simultáneas leerían
el mismo S y P, y la segunda pisaría a la primera. `CompraConcurrenciaTest` lo comprueba con dos
procesos de PHP de verdad, como `KardexConcurrenciaTest` en T-153.

`costear()` recibe el producto bloqueado, cambia `costo_promedio` en el modelo (lo guarda el mismo
`save()` del stock) y devuelve el costo que va en la fila del kárdex. Para una venta no hace nada:
su `costo_unitario` sigue en null.

## 5. Deshacer: NC, anular la NC y anular la compra

| Movimiento | Stock | Promedio | `costo_unitario` del kárdex |
|---|---|---|---|
| NC de compra (`nc_compra`) | baja q | no cambia | el P vigente |
| Anular la NC (`nc_compra_anulada`) | sube q | entra como una compra | el costo con que salió (se lee de su fila `nc_compra`) |
| Anular la compra (`compra_anulada`) | baja q | (S·P − q·c) / (S − q) | el c de la compra |

Al anular una compra pueden pasar dos cosas:
- **Si ya se vendió,** no alcanza el stock (S < q) y responde 422: «registra una nota de crédito del
  proveedor (devolución) o haz un ajuste de stock». No se deja el stock en negativo.
- **Si S − q = 0, o el resultado sale ≤ 0,** el promedio **no cambia**, para no inventar un costo
  negativo o dividir entre cero.

**Solo se revierte lo que de verdad se movió:** `revertirCompra()` cruza las líneas con las filas
`compra` del kárdex de ese documento (`movidos()`). Si un producto empezó a controlar stock después
de la compra, no se le resta lo que nunca entró.

**En orden (D8):** una compra con una NC **con productos** vigente no se anula: «Anula primero la
nota de crédito F001-…». Si no fuera así, la NC quedaría devolviendo unidades de una compra que ya
no existe.

## 6. Lo que queda por devolver (D3)

`CompraService::devolvible($compraId)` no se guarda en ninguna columna: suma lo comprado por
producto y le resta lo de las NC vigentes de esa compra. Se usa en dos lugares:
- `exigirDevolvible()`, al registrar la NC: un producto que no estaba, o una cantidad mayor, da 422
  con el producto y el máximo;
- `GET compras/{id}` lo devuelve como `devolvible`, y la pantalla de la NC arma las filas con su
  «máx.».

Para que dos NC de la misma compra no devuelvan lo mismo a la vez, `guardar()` **bloquea la compra
de referencia** antes que el periodo y los productos. Además, la valida como vigente *después* de
bloquearla, así que tampoco se cruza con una anulación. El orden de bloqueo es siempre compra →
periodo → productos por id.

## 7. Editar una compra con productos (D2)

Se puede cambiar la cabecera: serie, número, fechas, periodo, condición, montos, adjuntos y
observaciones. **Quedan fijos** las líneas, el proveedor, el tipo, la moneda, el tipo de cambio,
`con_credito_fiscal` y la compra que modifica una NC. Todos esos cambiarían el stock o el costo.
`exigirSinCambiosDeStock()` los compara, y para las líneas usa `firma()`, que normaliza cada
número a 6 decimales para que «6» y «6.000000» sean lo mismo. La pantalla los deshabilita, y si
llega un cambio por la API responde 422 «Esta compra tiene productos: anúlala y regístrala de
nuevo».

## 8. Detalles que se aprenden

- **`max_input_vars`:** PHP corta los campos de un formulario pasados 1000, sin error. Cada línea
  son 7 campos, así que con unas 140 líneas las últimas se perderían en silencio. Por eso
  `lineas` tiene `max:100`.
- **El cuadre con el comprobante es aviso, no bloqueo (D9):** si la suma de `valor_venta` no da
  `base_gravada + exonerado + inafecto`, la compra se guarda igual y la respuesta trae `aviso`.
  `form.vue` lo muestra con un Swal amarillo, y el detalle en `index.vue` también.
- **El costo solo en la ficha:** `costo_promedio` sale en `GET products/{id}`, no en
  `ProductResource`, porque el listado de productos también lo usa quien vende.
- **En pantalla, centavos sin coma flotante:** `valorVentaLinea()` multiplica en millonésimas con
  `BigInt` y redondea mitad hacia arriba, igual que el backend. Así la pantalla y la API dan el
  mismo céntimo.

## 9. Lo que no hace (todavía)

- Guardar el costo en cada línea de venta, la ganancia por venta y la valorización del inventario
  (está en «Por analizar» del backlog).
- Presentaciones: la línea queda preparada con `presentacion_id` y `factor`.
- Pagos (T-136) y asignación a una reserva o salida (T-152).
