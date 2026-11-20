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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
