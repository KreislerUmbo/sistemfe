# Recorrido: la pantalla de edición de venta (misma caja que registro)

20-nov-2026, tarea **T-110**. Sigue a T-106 (recorrido 72), que armó la pantalla de registro con
componentes. Construida por Sonnet, revisada por Opus.
Archivos:
- `admin-start-kit/src/views/sale/edit.vue` — orquestador, de ~2.330 a ~1.290 líneas
- `admin-start-kit/src/components/Sales/CobroEdicion.vue` (nuevo): el cobro propio de la edición
- Props opcionales nuevas (registro no las usa): `PanelClienteComprobante` (`tipo-bloqueado`,
  `serie-fija`), `DatosVentaPanel` (`sucursal-bloqueada`, `mostrar-n-transaccion`),
  `TablaLineasVenta` (`ya-descontado`)

**Al desplegar:** solo frontend (`npm run build`). Sin migraciones ni cambios de backend.

---

## 1. La idea

Antes, `edit.vue` era una copia vieja de `register.vue`: dos pantallas que mantener por separado.
Ahora editar usa los **mismos componentes** que registrar (recorrido 72 §2): buscador, tabla de
líneas, cliente y comprobante, datos de la venta y totales. Se ve y se maneja igual.

Igual que en T-106, **solo cambió la presentación**: el JSON que se manda a `PATCH sales/{id}` es el
mismo que antes. Se comprobó capturando el payload en `main` y en cada uno de los 5 commits, en 5
casos (contado sin cambios, contado con otra cantidad, crédito con cronograma, anticipo, detracción).
`store()` y `loadSale()` son los de antes; la única diferencia es que cada línea lleva un `uid`
(para identificarla en pantalla) que se quita antes de enviar, igual que `precio_manual`.

## 2. Quién hace qué

```
edit.vue  (orquestador: carga la venta, calcula, arma el payload y llama a store())
├── DatosVentaPanel          + «N.º de documento»; sucursal bloqueada si la venta ya tiene correlativo
├── BuscadorProductosVenta   igual que en registro
├── TablaLineasVenta         + ya-descontado: el stock disponible suma lo que la venta ya tenía
├── PanelClienteComprobante  tipo de comprobante bloqueado y serie fija si ya tiene correlativo
├── TotalesVenta             descuento global solo con el permiso cambiar_precio_venta
├── CobroEdicion             el cobro de la edición (ver §4), NO CobroContado/CobroCredito
└── AvisoVenta               avisos (stock, precio actualizado por cliente)
```

## 3. Lo propio de editar

| Qué | Dónde | Por qué |
|---|---|---|
| Al cargar no se recalcula nada | `loadSale()` con `isHydrating` | Los montos guardados pueden ser de otra tasa o regla; recalcularlos cambiaría lo ya emitido |
| Tipo, sucursal y serie fijos | `yaReservoCorrelativo` (`sale.correlativo` no nulo) | Espeja `resolverSerieComprobanteUpdate()` del backend: con correlativo reservado no se cambia de tipo |
| Stock | `cantidadOriginal` → `faltaStockEnEdicion()` y `ya-descontado` | La venta ya descontó sus unidades; el backend (T-113) valida solo la diferencia |
| Precio de líneas guardadas | `recalcularPreciosNuevos()` salta las que tienen `id` | Conservan su precio: `PrecioVentaValidator` (T-109) las acepta mientras no cambien |
| Precio de líneas nuevas | `recalcularPreciosNuevos()` al cambiar cantidad, quitar líneas y **cambiar de cliente** | Mayorista por cliente o por cantidad (T-115); sin recalcular, el backend las rechaza sin el permiso de precio |
| Producto repetido | `agregarProducto()` suma a la primera línea, salvo que tenga descripción editada (T-108) | No usa `indiceLineaIntacta()` de registro, para no cambiar el comportamiento con líneas guardadas de otro precio |

**Qué no se puede editar** (lo decide el backend en `SaleController::update()`, antes de abrir la
transacción): una venta con XML o CDR (responde `code: 405`) y una venta que salió de una reserva de
Agencia (422 «Esta venta salió de la reserva…»; se corrige eliminándola y volviendo a facturar).

## 4. Por qué el cobro no es el de registro

`CobroContado`/`CobroCredito` arman la lista de pagos **en memoria** y la mandan al guardar. La
edición, en cambio, registra cada pago **al instante**. Usar los componentes de registro habría
cambiado cuándo se registra un pago (y su paso por caja), y habría pisado los pagos ya guardados al
montarse. Decisión del usuario (20-nov): mover el cobro de `edit.vue` tal cual a `CobroEdicion.vue`.

Lo mismo con el anticipo: la edición usa sus campos propios (`n_comprobante_anticipo`,
`amount_anticipo`), no el mecanismo de Adelantos (`AdelantosVenta`, `advance_applications`).

## 5. De la pantalla a la tabla

| En pantalla | Petición | Qué escribe |
|---|---|---|
| «Agregar pago» | `POST sale_payments` (al instante) | una fila en `sale_payments` |
| Papelera de un pago | `DELETE sale_payments/{id}` (al instante) | borra esa fila |
| «Guardar cambios» | `PATCH sales/{id}` | `SaleController::update()`: venta, detalles, comprobante y caja en **una** transacción; stock por diferencia (T-113) |
| Tras guardar | `imprimirComprobante()` | nada (solo el PDF) |

## 6. Cómo se probó

- Payload idéntico a `main` en los 5 casos y los 5 pasos (scripts en `admin-start-kit/e2e/`,
  sin commitear: `payload-edicion-venta*.mjs`, `comparar.sh`).
- En la revisión (`e2e/verif-pendientes-t110.mjs`, agencia-demo, sin guardar nada):
  agregar un producto suma una línea; cambiar a un cliente mayorista (simulado en la búsqueda) avisa
  «Se actualizó el precio de 1 producto» y la línea nueva pasa de 50 a 45 de base, mientras las
  guardadas no cambian; la venta 22 (reserva) responde 422 con su mensaje; la venta 19 (enviada a
  SUNAT) responde `code: 405`.
- `vue-tsc`: 47 errores antes y después (ninguno nuevo); `vitest` 263/263; `vite build` correcto.

## 7. Lo que quedó en el backlog («Por analizar», 20-nov)

Pago inicial en crédito con 422, anticipo que no se carga al abrir, sucursal que arranca en la
primera, destino que se pisa con un cliente de Amazonía, guardar una venta enviada a SUNAT sin
mensaje, etiqueta de precio de líneas guardadas y el `45.00000000000001` de `getPrecioBaseSinIgv()`.
