# Requerimiento — Presentaciones y listas de precios (retail)

| | |
|---|---|
| **Fecha** | 2026-11-18 |
| **Módulo** | VEN (toca también COM, COT, NOT y POR) |
| **Pedido por** | usuario |
| **Prioridad** | por definir |
| **Relacionado** | Rediseño de la pantalla de venta retail (maqueta del 18-nov): deja el selector de precio ▾ preparado para esto |

> Borrador vivo. El estado está solo en `docs/backlog.md`.

## 1. Lo que se necesita

Un producto se vende de varias formas y cada una tiene su precio: por unidad, por docena, por
millar, por caja de 24… Ejemplo del usuario (18-nov):

- Cerveza **por unidad**: un precio al público.
- Cerveza **por caja de 12**: otro precio al público.
- Si el cliente es **mayorista** y compra **más de 10 cajas**, otro precio más.

Las cajas y los paquetes tienen **su propio código de barras** (respuesta del 18-nov).

## 2. Cómo está hoy (verificado 18-nov-2026)

- `products`: **una** `unidad_medida` (código del catálogo 03 de SUNAT: NIU, KGM, LTR, MTR, ZZ),
  **un** `stock` (double), **dos** precios: `price_general` y `price_company`.
- El precio se elige por `clients.type_client` (1 normal, 2 empresa) **solo al agregar** el
  producto a la venta (`sale/register.vue:1192`, igual en `edit.vue:1160`). Si después se cambia
  el cliente, las líneas ya cargadas no se recalculan.
- `sale_details` copia `unidad_medida` y `quantity`; el XML usa
  `->setUnidad($detalle->unidad_medida)` (`GreenterService.php:658`).
- El stock se descuenta con `decrement('stock', quantity)` sin conversión, en **6 lugares**:
  `SaleController.php` (store 894; update 1162, 1216, 1219, 1230) y
  `NotaElectronicaController.php:433` (devolución por NC). No hay kárdex (historial de
  movimientos de stock).
- `ProductFormQuick.vue` solo ofrece NIU, KGM, LTR, MTR y ZZ.

## 3. Propuesta: dos conceptos separados

### 3.1 Presentaciones (en qué se vende)

| Presentación | Equivale a | Código SUNAT | Código de barras | Precio público |
|---|---|---|---|---|
| Unidad (base) | 1 | NIU | propio | 6.00 |
| Caja x12 | 12 | BX | propio | 66.00 |
| Docena | 12 | DZN | — | … |
| Millar | 1 000 | MIL | — | … |

- El **stock se guarda siempre en la unidad base**: vender 2 cajas x12 descuenta 24. Abrir una
  caja y vender suelto no requiere nada especial.
- El comprobante dice la presentación: «2 CAJA X12 (BX) × S/ 66.00».
- Cada presentación tiene su **código de barras**: al escanear la caja entra «Caja x12» directo.
- La línea de venta guarda la presentación **y una copia del factor** (si mañana la caja pasa a
  ser de 24, las ventas viejas y sus notas de crédito siguen devolviendo lo correcto).

### 3.2 Listas de precios (a quién y desde cuánto)

- La lista **Público** es la de siempre: cada presentación tiene su precio público.
- Otras listas (**Mayorista**, **Empresa**, ofertas…) dan un precio por presentación con
  **cantidad mínima** opcional y **vigencia** opcional.
- La **cantidad mínima es configurable** (respuesta del 18-nov), no un 10 fijo: la lista trae un
  mínimo por defecto (p. ej. Mayorista desde 10) y cada producto/presentación puede cambiarlo
  (Pilsen caja desde 10, Cusqueña caja desde 5, agua por unidad desde 50…). Vacío = sin mínimo.
- Cada cliente puede tener **una lista asignada**. «Empresa» (`type_client = 2` +
  `price_company`) pasa a ser una lista más.

**Regla de precio de una línea** (ejemplo del usuario):

1. Si el cliente tiene lista asignada, esa lista tiene precio para la presentación, la cantidad
   llega al mínimo y está vigente → precio de esa lista.
2. Si no → precio público de la presentación.
3. El vendedor siempre puede elegir otro precio de la lista (▾) o escribirlo a mano; la línea
   muestra cuál se usó («Público», «Mayorista», «Precio manual»).

Al cambiar la cantidad o el cliente, el precio se recalcula solo, salvo en las líneas con precio
puesto a mano.

### 3.3 En la pantalla de venta

```
Producto              Cant.   Presentación     Precio          Importe
Cerveza Pilsen 630ml  [ 12 ]  [Caja x12 ▾]     S/ 60.00 ▾      S/ 720.00
                               = 144 unidades   Mayorista (≥10)
```

Stock en la unidad que entiende el cajero: «Quedan 3 cajas y 5 unidades».

## 4. Fases

| Fase | Contenido |
|---|---|
| **A. Presentaciones** | Tabla de presentaciones (nombre, factor, código SUNAT, código de barras, precio público, activo). Stock en unidad base. **Un solo servicio de stock** que reemplaza los 6 `increment/decrement`. `sale_details` con presentación + factor. Ficha del producto e importación Excel. Ampliar el catálogo 03 en el formulario (DZN, BX, MIL, PK…) |
| **B. Listas de precios** | Listas con cantidad mínima y vigencia, lista por cliente, migrar «Empresa» a lista |

Migración: cada producto recibe su presentación base con su `unidad_medida` y precios actuales; la
lista Empresa nace de `price_company`. Un negocio que no usa presentaciones no ve nada distinto.

## 5. Lo que se toca además de Ventas

- **Notas de crédito**: devolver al stock cantidad × factor guardado.
- **Edición de venta** (`edit.vue`, `SaleController::update()` y su atomicidad).
- **Cotizaciones comerciales** (convertir en venta).
- **Portal e-commerce**: hoy lee `price_general`; debe seguir funcionando.
- **Compras** (diseñado, sin construir; `plan-tours-venta-simple-costo-real.md` §3.7-§3.8): debe
  comprar por presentación («10 cajas») y sumar en unidades. **Alineado el 25-nov (T-151,
  §3.7f del plan):** las líneas de compra (T-154) nacen con `presentacion_id` y copia del `factor`,
  y el stock pasa por un solo servicio con kárdex (T-153). La fase A debe usar ese servicio y su
  kárdex en vez de crear otro.
- **Importación de productos** desde Excel.

## 6. Respuestas del usuario (18-nov-2026)

| # | Pregunta | Respuesta | Cómo queda |
|---|---|---|---|
| 1 | ¿Fracciones de una presentación (media docena)? | Solo si esa cantidad tiene un precio propio; si no, se aumenta la cantidad de unidades | **No hay fracciones.** «Media docena» con precio propio = otra presentación (factor 6). Sin precio propio se venden 6 unidades |
| 2 | ¿El portal vende por presentaciones? | Por ahora solo la unidad base | El portal lee la presentación base; sin cambios visibles |
| 3 | ¿«Más de 10» o «desde 10»? | El mayorista es un precio que se ofrece («te dejo la caja en 56»); si se regatea a 55, se cambia en la venta | El precio mayorista es el **precio de referencia** de la lista. La cantidad mínima es un número configurable que se lee como «desde» (≥); «más de 10» se configura como 11. El regateo es un **precio manual** en la línea |
| 5 | ¿Cajas mezcladas suman para el mínimo? | Se cuenta por producto: cada marca tiene su precio (Pilsen 56 la caja, Cusqueña 60) | **El mínimo se cuenta por producto y presentación**, nunca sumando productos distintos. 6 Pilsen + 5 Cusqueña con mínimo 10 → ninguna llega |
| 4 | ¿Cambiar el precio pide permiso? | Sí: solo puede cambiar el precio en la venta quien tenga el permiso | Permiso nuevo para cambiar el precio en la venta (hoy no existe: cualquiera con `register_sale` edita el precio). Ver §7 |

## 7. Permiso para cambiar el precio (verificado 18-nov)

Hoy no hay ningún permiso sobre el precio: los de ventas son `register_sale`, `edit_sale`,
`*_sale_detail`, `*_sale_payment`, `emitir_*`, `list_sale`, `delete_sale`. Propuesta:

- Permiso nuevo (p. ej. `cambiar_precio_venta`). El Propietario lo recibe solo
  (`roles:sync-propietario`).
- **Sin el permiso** (respuesta del 18-nov): el precio lo pone el sistema según la regla de §3.2
  (público, o el de la lista del cliente si llega al mínimo). Casilla de solo lectura y **sin ▾**:
  no elige otro precio de lista ni escribe uno.
- **Con el permiso**: elige con ▾ cualquier precio de lista o escribe otro (regateo); la línea
  queda como «Mayorista», «Público»… o «Precio manual».
- **El backend también lo valida**: si llega un precio distinto de los de lista sin el permiso, se
  rechaza (no basta con bloquear la casilla en pantalla).
- No necesita presentaciones ni listas: puede ir con el rediseño de la pantalla de venta, con los
  dos precios que ya existen (general y empresa).

## 8. Preguntas abiertas

1. Un mayorista que compra **menos del mínimo**, ¿paga público? (supuesto actual: sí)
