# Recorrido: precio mayorista por cantidad o por cliente mayorista

20-nov-2026, tarea **T-115**. Construida por Sonnet, revisada por Opus, probada en pantalla con un
script de Playwright y el PDF con Ghostscript. Archivos:
- `api-sistema-fe/database/migrations/tenant/core/2026_11_20_100000_add_cantidad_mayorista_y_es_mayorista.php`
- `api-sistema-fe/app/Services/Ventas/PrecioVentaValidator.php`: `validar()` (suma por producto),
  `precioBaseCatalogo()`
- `admin-start-kit/src/helpers/ventaRetail.ts`: `precioCatalogo()` (reemplaza a `precioCatalogoPorCliente()`)
- `admin-start-kit/src/views/sale/register.vue`: `precioBaseDe()`, `recalcularPrecios()`, `alCambiarLinea()`,
  `agregarProducto()`, `removeItem()`
- `admin-start-kit/src/components/Sales/TablaLineasVenta.vue`: `marcarPrecioManual()` (`@input`), etiqueta de la línea
- `admin-start-kit/src/views/sale/edit.vue`: `recalcularPreciosNuevos()`, `alCambiarCantidad()`, `alCambiarPrecio()`
- Formularios: `product/register.vue`, `product/edit.vue`, `ProductFormQuick.vue` («Mayorista desde»),
  `ClienteDatosBase.vue` + `clients/ficha.vue` (casilla «Cliente mayorista»)
- Tests: `ventaRetail.test.ts` y `T109PrecioYValidacionesVentaTest.php`

**Al desplegar:** `php artisan tenants:migrate-verticales`. La migración no rellena nada: nadie queda
mayorista y ningún producto tiene mínimo. Consecuencia: los clientes empresa (`type_client = 2`) que
hoy reciben `price_company` pasan a pagar el **general** hasta que el dueño cargue «Mayorista desde» en
sus productos o marque clientes mayoristas. Avisarlo antes.

---

## 1. El problema (venta 41 de umbo)

El cajero agregó un producto a S/ 50, eligió un cliente con RUC y la línea bajó sola a S/ 30: el
precio «empresa» se aplicaba a todo `type_client = 2`. Pero tener RUC solo decide factura o boleta.
El precio menor es para **quien lleva bastante** o para un **cliente mayorista** con precio negociado.

## 2. La regla (una sola, en los dos lados)

```
mayorista  si  price_company > 0  y  ( cliente.es_mayorista
                                       o (cantidad_mayorista > 0  y  suma de líneas del producto ≥ cantidad_mayorista) )
si no      →  price_general
```

| Dónde | Función |
|---|---|
| Pantalla | `ventaRetail.ts::precioCatalogo(product, cliente, cantidadTotal)` → `{ valor, nombre, motivo }` |
| Backend | `PrecioVentaValidator::precioBaseCatalogo($producto, $cliente, $cantidadTotal)` |

Las dos suman **todas** las líneas del producto (T-112 permite varias filas del mismo). Si la pantalla
y el backend no usaran la misma regla, sin el permiso de precio el backend rechazaría lo que la
pantalla calculó.

## 3. Cuándo se recalcula

`register.vue::recalcularPrecios()` pasa por las líneas **sin precio a mano** y, si el precio cambió,
llama a `updateItem()` (importes de la línea → `sumDetails()` → cabecera; T-114 valida esa cabecera).
Se llama:
- al elegir o cambiar el cliente (con aviso «Se actualizó el precio de N productos…»);
- al cambiar una línea (`alCambiarLinea`) y al agregar un producto (sin aviso: basta la etiqueta);
- al quitar una línea y al deshacer (la suma baja y puede dejar de ser mayorista).

La etiqueta bajo el precio dice el motivo: «General», «Mayorista (desde 12 u.)» o «Mayorista (cliente)».

**Detalle de `agregarProducto()`:** para decidir si suma a una fila «intacta» (T-112) compara contra
el precio de la cantidad que el producto lleva **antes** de sumar; recién después recalcula todo. Si
comparara con el precio ya sumado, al cruzar el mínimo (11 → 12) la fila dejaría de parecer intacta y
abriría una fila nueva.

## 4. El precio a mano nunca se pisa

Escribir en la casilla marca la línea como precio a mano en el evento `@input`, no solo en `@change`.
¿Por qué? El navegador dispara `change` solo si el valor final es distinto al que había al enfocar: si
el cajero escribe el **mismo** número (confirma el precio), `change` no llega y la línea seguiría
«del sistema», así que un cambio de cantidad se lo pisaría.

## 5. La edición (`edit.vue`)

Solo las líneas **nuevas** (sin `id`) y sin precio a mano se recalculan por cantidad. Las guardadas
conservan su precio: `PrecioVentaValidator` acepta una línea guardada mientras su precio no cambie.
Pendiente para T-110: recalcular también al cambiar de cliente.

## 6. Cómo se probó

Datos temporales en agencia-demo (producto general 59 / mayorista 35,40 con IGV desde 12 u.; un
cliente empresa no mayorista y otro mayorista). Empresa 1 u. → 50 «General»; 12 u. → 30 «Mayorista
(desde 12 u.)»; cliente mayorista 1 u. → 30 «Mayorista (cliente)»; mismo número tecleado → «Precio
manual» que no cambia a 12 u. Venta real de 12 u.: el PDF dice V.U. 30,000 / total S/ 424,80, igual
que la pantalla.

## 7. Para practicar

1. Un producto con precio mayorista y **sin** «desde N»: ¿quién lo recibe? Sigue `precioCatalogo()`.
2. Agrega 7 u. de un producto, edita la descripción (fila nueva) y agrega 5 más: ¿qué precio queda en
   cada fila y por qué? ¿Qué cuenta el backend?
3. ¿Por qué `edit.vue` no recalcula las líneas guardadas? Mira `precioValido()` del validador y la
   línea `$guardada`.

**Relacionado:** recorridos 74 (precio de venta, T-109), 75 (varias filas del mismo producto, T-112),
77 (totales validados, T-114).
