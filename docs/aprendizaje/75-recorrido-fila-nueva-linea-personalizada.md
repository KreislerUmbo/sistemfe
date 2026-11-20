# Recorrido: elegir de nuevo un producto ya personalizado abre otra fila

19-nov-2026, tarea **T-112**. Construida por Sonnet, revisada por Opus (un arreglo en la revisión),
probada en el navegador en agencia-demo. Solo frontend, sin cambios en el backend ni en el payload.
Archivos:
- `admin-start-kit/src/helpers/ventaRetail.ts`: `indiceLineaIntacta()`, `cantidadEnVenta()`, `productoSinStock()`
- `admin-start-kit/src/views/sale/register.vue`: `agregarProducto()`, `motivoCobro`
- `admin-start-kit/src/components/Sales/TablaLineasVenta.vue`: `sinStock()` (insignia «Solo hay N»)
- Tests: `src/helpers/ventaRetail.test.ts` (bloque «línea intacta y stock por producto»)

**Al desplegar:** nada especial (solo el build del frontend).

---

## 1. La idea

Antes, elegir un producto que ya estaba en la venta **siempre** sumaba 1 a su línea. Desde T-108
cada línea puede llevar su descripción, y desde T-109 su precio a mano. Así que si el cajero vende el
mismo servicio dos veces con textos y precios distintos («Mantenimiento de 2 PC + pasta térmica»,
2 × 80, y «Mantenimiento simple», 2 × 60), sumar a la primera línea las mezclaría.

La regla ahora: **se suma solo a una línea «intacta»; si no hay, se abre una fila nueva.**

## 2. ¿Qué es una línea intacta?

Una línea que está como la habría puesto el sistema:
- sin `descripcion_linea` (o en blanco),
- sin `precio_manual`,
- con `price_base` igual al precio del sistema para ese cliente (`precioBaseDe(product, cliente)`).

```ts
// helpers/ventaRetail.ts
indiceLineaIntacta(lineas, productId, precioSistema)  // índice de la primera intacta, o -1
```

Si hay una personalizada y una intacta del mismo producto, se suma a la intacta. Los ítems que
llegan desde una cotización (`opciones.precio`) traen un precio puesto a mano: **siempre** abren su
propia fila (antes se sumaban y se perdía el segundo precio).

## 3. El stock cuenta el producto, no la fila

Con dos filas del mismo bien, comparar cada fila con el stock dejaría pasar 3 + 3 con stock 5. Por
eso todo control de stock suma las líneas del producto:

```
cantidadEnVenta(lineas, productId)   → suma de cantidades del producto en toda la venta
├─ agregarProducto(): aviso «Solo hay N unidades» si suma + lo que se agrega > stock  (antes de decidir la fila)
├─ motivoCobro → productoSinStock(lineas): deshabilita Cobrar con «No hay stock suficiente de …»
└─ TablaLineasVenta.sinStock(): insignia roja «Solo hay N» en TODAS las filas del producto
```

Solo cuentan los productos que controlan stock: `controlaStock()` = bien y `disponiblidad ≠ 1`.

## 4. Recorrido en la pantalla

1. Ventas → Registrar. Escanear un producto tres veces → una fila × 3.
2. «Editar descripción» en esa fila y elegir el producto otra vez → fila nueva con el nombre del
   catálogo, 1 u. y el precio del sistema.
3. Cambiar el precio de esa fila nueva y elegir el producto de nuevo → otra fila.
4. Elegirlo una vez más → se suma a la última (la única intacta).
5. Con un bien de stock 5 en dos filas (3 + 2): elegirlo otra vez → aviso y no agrega; subir una
   fila a 3 → las dos filas en rojo y Cobrar deshabilitado.
6. Guardar: `sale_details` recibe una fila por línea (ya admitía varias del mismo producto); el PDF
   y el XML muestran cada línea con su descripción.

## 5. Para practicar

1. ¿Qué pasa si cambias de cliente y el precio del sistema baja? Mira `recalcularPreciosPorCliente()`:
   ¿la línea sigue siendo intacta? ¿Por qué eso es lo correcto?
2. Agrega a `ventaRetail.test.ts` un caso: dos líneas intactas del mismo producto (¿cómo se llega
   a eso?) → ¿a cuál suma `indiceLineaIntacta()`?
3. T-113 va a validar el stock en el backend: ¿por qué tiene que sumar por `product_id` igual que
   `cantidadEnVenta()`?

**Relacionado:** recorridos 72 (pantalla de venta), 73 (descripción editable, T-108), 74 (precio, T-109).
Siguiente: T-113 (stock en el backend) y T-115 (precio mayorista; reemplaza la regla de precio por cliente).
