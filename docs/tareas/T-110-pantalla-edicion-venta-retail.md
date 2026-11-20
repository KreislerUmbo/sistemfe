# T-110 — Pantalla de edición de venta con los componentes de la pantalla nueva

| | |
|---|---|
| **Módulo** | VEN |
| **Depende de** | T-106, T-108, T-109 |
| **Reglas** | `SaleController::update()` sincroniza venta/detalles/comprobante/caja en una transacción |
| **Decisiones** | maqueta aprobada el 18-nov-2026 (`docs/maquetas/venta-retail.html`) |
| **Rama** | `feat/T-110-pantalla-edicion-venta` |

## Contexto
`views/sale/edit.vue` (~2.300 líneas) es casi una copia de `register.vue`. Tras T-106 quedaría con
el diseño viejo y con dos pantallas que mantener. Se rearma con los componentes de T-106, para que
registrar y editar se vean y se comporten igual.

## Alcance
- `edit.vue` usa los componentes de `components/Sales/` de T-106 (buscador, líneas, cliente y
  comprobante, cobro, datos de la venta), cargando la venta guardada.
- Lo propio de editar se mantiene: carga de detalles, pagos y cronograma guardados; series y
  comprobante ya emitido (lo que hoy no se puede cambiar sigue bloqueado); bloqueos de venta
  enviada a SUNAT o de reserva de Agencia (no editable desde Ventas, F5).
- Descripción editada (T-108) y permiso de precio (T-109) también en la edición.
- **Mismo payload de `PUT sales/{id}`** que antes.
- **Heredado de T-113, T-114 y T-115 (20-nov):**
  - Stock: lo disponible para la venta = almacén + lo que ella ya tenía (`faltaStockEnEdicion()`).
  - Precio mayorista (`precioCatalogo()`, recorrido 78): las líneas **nuevas** sin precio a mano se
    recalculan al cambiar la cantidad **y al cambiar de cliente** (hoy `edit.vue` no lo hace al cambiar
    de cliente: sin el permiso de precio el backend rechaza esa línea). Las guardadas conservan su precio.
  - Cada cambio de precio recalcula la línea y la cabecera (T-114 la valida).
  - Retirar el bloque comentado de Selectr de `edit.vue` (~2131-2163, `getPriceBaseCF()`/`getPriceBaseCE()`).
- **Ojo — dos diferencias de comportamiento con `register.vue` que NO se cambian sin preguntar (20-nov):**
  - **Pagos:** `edit.vue` agrega y borra pagos **al instante** (`POST sale_payments` ~1560,
    `DELETE sale_payments/{id}` ~1594); `CobroContado.vue`/`CobroCredito.vue` arman la lista en memoria y
    se mandan al guardar. Usar esos componentes tal cual cambia cuándo se registra un pago (y su paso por
    caja). Si no encajan sin cambiar ese comportamiento, **parar y mostrar opciones** antes de codificar.
  - **Anticipo:** `edit.vue` usa `sale_anticipo` / `n_comprobante_anticipo` / `amount_anticipo` (~881,
    ~1523, ~1707); `register.vue` usa `AdelantosVenta.vue` y `advance_applications`. CA1 exige el mismo
    payload que hoy: no cambiar al mecanismo de Adelantos en esta tarea.
- Trabajar por pasos con un commit cada uno (estructura y carga → líneas → cliente/comprobante/datos →
  cobro → limpieza), comparando el payload después de cada paso.

## Fuera de alcance
- El problema de pagos que se guardan al instante sin caja en «editar venta»
  (`SalePaymentController`, línea de «Por analizar» del backlog): no se toca aquí.
- Cambios en `SaleController::update()`.

## Dónde tocar
- `admin-start-kit/src/views/sale/edit.vue` → template completo; carga de la venta; `store()` (~1610,
  payload ~1700); precio por cliente (~1193).
- Componentes de T-106.

## Criterios de aceptación
- [ ] **CA1** — Editar una venta contado, una a crédito con cronograma y una con adelanto envía el
  **mismo payload** que antes para los mismos cambios (comparar JSON contra `main`).
- [ ] **CA2** — Una venta enviada a SUNAT o de reserva sigue sin poder editarse donde hoy no se puede.
- [ ] **CA3** — Registrar y editar se ven igual (misma estructura y componentes).
- [ ] **CA4** — `edit.vue` baja de tamaño (sin bloques copiados de `register.vue`).
- [ ] **CA5** — `npm run type-check` no suma errores nuevos en los archivos tocados.

## Seguridad y correctitud
- [ ] Atomicidad de `update()`: sin cambios de backend.
- [ ] Caja: los pagos editados siguen pasando por la corrección de caja de `update()`.
- [ ] Sin `v-html` sin sanitizar.
- [ ] Lo demás: no aplica.

## Cómo verificar
Tenant retail: editar ventas de cada tipo (contado, crédito, adelanto, detracción) y comparar el
payload en DevTools contra `main`.

---

## Resultado
- **Commits** (rama `feat/T-110-pantalla-edicion-venta`, 5 pasos, payload comparado después de cada uno):
  1. `5d26eada` estructura y carga: grilla productos | panel, `AvisoVenta`, líneas `LineaVenta` con `uid` (se quita del payload).
  2. `f24f54eb` líneas: `BuscadorProductosVenta` + `TablaLineasVenta` (descripción editable T-108, permiso de precio T-109, mayorista por cantidad T-115, stock con lo que la venta ya tenía T-113).
  3. `0f587e14` cliente/comprobante/datos: `PanelClienteComprobante`, `DatosVentaPanel` (chips en la cabecera), `TotalesVenta`; al cambiar de cliente se reprecian las líneas nuevas.
  4. `7e6e9d77` cobro: nuevo `components/Sales/CobroEdicion.vue` con el cobro de `edit.vue` movido tal cual.
  5. `a02dfc79` limpieza: bloque comentado de Selectr (`getPriceBaseCF()`/`getPriceBaseCE()`), código muerto, estilos de la barra inferior.
- **CA1 (payload):** `PATCH sales/{id}` capturado con Playwright en `main` (sin dejarlo llegar al servidor) y
  comparado tras cada paso en 5 escenarios sobre agencia-demo: contado sin cambios, contado con cantidad 3,
  crédito con cronograma (pago inicial simulado), anticipo (N.º de comprobante) y detracción. **Idéntico en los 5
  tras los 5 pasos.** No hay en la base una venta real con adelanto ni con detracción: se armaron cambiando la
  condición especial de la venta 26 en pantalla, con un pago inicial simulado en la respuesta de `GET sales/26`.
  Scripts (sin commitear, en `admin-start-kit/e2e/`): `payload-edicion-venta.mjs`, `payload-edicion-venta-nueva.mjs`,
  `comparar.sh`, `verif-cobro-edicion.mjs`; la referencia está en `e2e/resultados/payload-main.json` (ignorada por git).
- **CA2:** sin cambios en `loadSale()` ni `store()` (el 405 de venta con XML/CDR y `tiene_cobros_formales` se
  manejan igual). Comprobado en pantalla que una venta con correlativo (venta 21) abre con el tipo de comprobante y la
  sucursal bloqueados y la serie fija; la venta de reserva de Agencia y la enviada a SUNAT no se abrieron (no hay
  ninguna a mano): lo cubre que el código no cambió.
- **CA3:** parcial, ver Desviaciones. **CA4:** `edit.vue` de 2.334 a ~1.290 líneas (sin la búsqueda, la tabla de
  líneas, el cliente, los totales y el cobro, que ahora viven en componentes). **CA5:** `vue-tsc` 47 errores antes y
  47 después, los mismos (el de `edit.vue`, `selectClient(sale.client)`, ya estaba en `main`).
- **Tests:** `vitest` 263/263 (src/helpers y src/utils); `vite build` correcto. Backend: sin cambios, no se corrió su
  suite. En pantalla (Playwright): agregar y quitar un pago manda `POST sale_payments` y `DELETE sale_payments/{id}`
  con el mismo cuerpo de antes. Pendiente para la revisión: probar a mano agregar un producto, cambiar de cliente con
  líneas nuevas y editar una venta de reserva/enviada.
- **Desviaciones de la tarea y por qué:**
  - **CA3 queda parcial**: mismo panel y misma posición que en registro, pero el interior del cobro es distinto
    (`CobroEdicion.vue` en vez de `CobroContado`/`CobroCredito`/`AdelantosVenta`). Decisión del usuario (20-nov): esos
    componentes arman los pagos en memoria, los mandan al guardar y reemplazan la lista de pagos al montarse; usarlos
    habría cambiado cuándo se registra un pago (y su paso por caja) y habría pisado los pagos ya guardados. Los pagos
    al instante (`POST`/`DELETE sale_payments`) y el campo propio de anticipo (`sale_anticipo` /
    `n_comprobante_anticipo` / `amount_anticipo`) se movieron tal cual, sin cambiar comportamiento.
  - **Props opcionales nuevas en componentes compartidos** (registro no las usa): `PanelClienteComprobante`
    (`tipo-bloqueado`, `serie-fija`), `DatosVentaPanel` (`sucursal-bloqueada`, `mostrar-n-transaccion` con el campo
    «N.º de documento», que el payload necesita) y `TablaLineasVenta` (`ya-descontado`: suma al stock disponible lo que la
    venta ya tenía descontado, para no marcar «Solo hay N» en líneas guardadas).
  - Se pierden «Cantidad/Precio por defecto» de la búsqueda (registro ya no los tiene) y el descuento global solo
    se ve con el permiso `cambiar_precio_venta` (el backend ya lo exigía, T-109). Sin ese permiso la casilla de precio
    de las líneas es de solo lectura.
  - Agregar un producto que ya está en la venta suma a su primera línea, salvo que esa línea tenga la descripción
    editada (T-108): ahí abre una fila nueva. No se usó `indiceLineaIntacta()` de registro para no cambiar el
    comportamiento de edición con líneas guardadas de otro precio.
  - La cantidad pasa por `normalizarCantidad()` de la tabla nueva: unidades que no admiten decimales redondean a entero.
  - La barra fija inferior se retiró; «Guardar cambios» queda en el panel. El aviso de stock insuficiente ahora es el
    aviso flotante de la pantalla nueva en vez de una ventana.
  - `sales/config` sigue devolviendo todos los clientes y productos (línea del backlog): recortarlo es backend, fuera
    de esta tarea; `edit.vue` ya no los usa.

## Hallazgos fuera de alcance
- `SalePaymentController::store()` responde 422 a las ventas a crédito (`condicion_pago = 'credito'`), así que el
  botón «Agregar pago inicial» de `edit.vue` ya falla hoy. Consecuencia: `store()` exige al menos un pago, así que una
  venta a crédito sin pago inicial no se puede guardar desde la edición.
- `edit.vue` nunca carga `sale_anticipo` ni `n_comprobante_anticipo` al abrir la venta, y el botón «buscar» del
  anticipo no hace nada (solo avisa «Función pendiente»): una venta con anticipo se edita enviando
  `n_comprobante_anticipo: ''` y `amount_anticipo: 0`.
- Al abrir la edición, `branch_id_seleccionado` queda en la primera sucursal (`branches[0]`) y no en la de la venta;
  con el permiso de cambiar sucursal se envía esa. No se verificó qué hace `update()` con ella.
