# T-106 — Pantalla nueva de registro de venta retail (caja en dos columnas)

| | |
|---|---|
| **Módulo** | VEN (todos los giros que usan `sale/register.vue`) |
| **Depende de** | T-107 (toca el mismo panel del cliente en `register.vue`) |
| **Reglas** | estilo UI de Agencia/Ventas (inputs `-sm`, `CampoFecha.vue`); ayudas sin fricción |
| **Decisiones** | maqueta aprobada por el usuario el 18-nov-2026: `docs/maquetas/venta-retail.html` |
| **Rama** | `feat/T-106-pantalla-venta-retail` |

## Contexto
La pantalla de venta actual son 4 tarjetas apiladas con lo raro primero (retención, detracción,
destino) y el cobro al fondo. El usuario la ve cargada. Se rediseña como pantalla de caja:
productos a la izquierda, cobro fijo a la derecha. **Solo cambia la presentación**: el payload
de `store()`, los cálculos de IGV, la exoneración Amazonía y las series quedan como están, salvo lo
que dice el Alcance. Es la parte frontend; T-108 (descripción), T-109 (permisos y validaciones) y
T-110 (edición) van aparte.

## Alcance
Abrir la maqueta en el navegador y seguirla. Puntos que no se deducen de mirarla:

- **Componentes reutilizables** en `components/Sales/` (T-110 los usa en `edit.vue`): buscador de
  productos, tabla de líneas, panel de cliente y comprobante, panel de cobro, panel «Datos de la
  venta». `register.vue` queda como orquestador.
- **Cabecera**: etiquetas de estado (caja, fecha, moneda, sucursal, destino, condición especial)
  y «Datos de la venta ▾», que despliega arriba moneda, fecha (`CampoFecha.vue`), sucursal (solo
  con `can_switch_branch`, como hoy), destino y condición especial. El destino por defecto sale
  de la configuración, no fijo en el código. Lo no habitual se marca en ámbar.
- **Caja**: etiqueta «Caja abierta desde …» o aviso «Tu caja está cerrada» con botón a abrir caja,
  antes de cargar productos. Usar el endpoint de sesión de caja existente (ver `views/cash/`).
- **Buscador** fijo arriba de la lista, `F2` lo enfoca, flechas + Enter agregan, Enter con código
  de barras exacto agrega directo. Si el producto ya está, suma 1. «+ Crear producto o servicio»
  abre `ProductFormQuick.vue` (ya tiene Bien / Servicio) y agrega lo creado.
- **Lista con desplazamiento interno** (encabezado fijo), lleva a la línea agregada y la resalta.
  Pie: líneas y unidades. **Observaciones** debajo de la lista.
- **Línea**: cantidad con − / +; decimales solo si la unidad es KGM (u otra de peso/volumen);
  precio; **afectación IGV** editable por línea, con el valor de la configuración por defecto
  (`tip_afe_igv` que ya resuelve el front), marcada en ámbar si se cambió y con «restaurar»;
  descuento por línea solo si el producto lo permite (`is_discount`, `max_discount`); quitar con
  aviso «Deshacer» (sin confirmación). Etiquetas: servicio, poco stock, «Solo hay N» si se pasa.
- **Precio por cliente**: hoy se elige solo al agregar (`selectAndAddProduct()` ~1168). Al cambiar
  de cliente, recalcular las líneas sin precio puesto a mano y avisar cuántas cambiaron. Debajo
  del precio, «General» / «Empresa» / «Precio manual». El ▾ con los dos precios existentes queda
  preparado para las listas de precios futuras (`docs/requerimientos/retail-presentaciones-y-precios.md`).
- **Panel de cobro**: cliente (vacío = Clientes varios), tipo de comprobante (RUC → Factura
  automático, como hoy) con serie y próximo número, totales solo con las filas que aplican, total
  grande, «+ Descuento global».
  - **Contado**: métodos como botones (del catálogo `payment-methods`, como hoy); en Efectivo,
    «¿Con cuánto paga?» vacío = exacto, atajos calculados (siguiente monto de 10/20/50/100/200 que
    cubre lo que falta, máx. 4), vuelto o «Falta». N.º de operación para los demás métodos.
    «+ Pagar con dos métodos».
  - **El vuelto es solo una calculadora en pantalla**: se envía el pago por lo que cubre la venta,
    no lo recibido. El backend de retail hoy no acepta pagos mayores al total
    (`SaleController::prepararPagosVenta()`, «puede faltar pero no pasarse»).
  - **Se vende y se cobra en la moneda de la venta** (decisión del 18-nov): sin pago en otra moneda.
  - **Crédito**: `CronogramaCuotasCampos.vue` (cuotas, periodicidad, fechas) + pago inicial; mora
    detrás de un enlace.
  - **Adelantos** (condición especial «adelanto»): la tabla actual pasa al panel de cobro. Código
    de detracción: en «Datos de la venta».
- **Botón Cobrar** con el monto; deshabilitado con el motivo debajo: sin productos, sin stock,
  factura sin RUC, boleta > S/ 700 sin DNI, crédito con Clientes varios, caja cerrada (contado),
  falta dinero. `Ctrl + Enter` cobra. (T-109 agrega las mismas validaciones en el backend.)
- **Al guardar**: imprime en `formato_impresion_default` del usuario (como hoy), deja la pantalla
  limpia con el foco en el buscador y muestra un aviso «Boleta B001-… guardada» con «Reimprimir»
  y «Ver PDF A4». Sin ventana de éxito que cerrar.
- Corregir textos: «Soles», «Dólares», «Total» (no «Total Factura» en una boleta).
- Funciona en celular: las columnas se apilan; la lista con alto máximo 60 % de la pantalla.

## Fuera de alcance
- Descripción editable de la línea → T-108. Permiso de precio y validaciones del backend → T-109.
  Pantalla de edición → T-110.
- Presentaciones, listas de precios, regla mayorista (lo que la maqueta marca «nuevo»).
- Ventas en espera; pago en otra moneda; vuelto registrado en caja o impreso en el ticket.
- Cambiar cálculos de IGV, `resolverTipAfeIgv()`, series o el payload de `store()`.

## Dónde tocar
- `admin-start-kit/src/views/sale/register.vue` → template completo (1-735); `selectAndAddProduct()`
  ~1168; `updateItem()` ~1243; `addPayment()` ~1571; `store()` ~1607 (payload ~1755); `resetData()`.
- `admin-start-kit/src/components/Sales/ProductFormQuick.vue`, `ClientFormQuick.vue` (reusar).
- `admin-start-kit/src/components/CronogramaCuotasCampos.vue` (reusar).
- Nuevos en `admin-start-kit/src/components/Sales/`.

## Criterios de aceptación
- [ ] **CA1** — Una venta contado en boleta, una factura a RUC, una a crédito con cuotas, una con
  adelanto y una con detracción se guardan con **el mismo payload** que antes (comparar el JSON de
  `POST sales` antes y después para los 5 casos) → prueba manual en un tenant retail.
- [ ] **CA2** — Con destino Amazonía, los bienes nacen exonerados y los servicios gravados, igual que
  hoy; cambiar la afectación de una línea la marca y «restaurar» vuelve al valor por defecto.
- [ ] **CA3** — Productos agregados con un cliente normal pasan a precio empresa al elegir un
  cliente empresa (salvo los de precio manual), y vuelven al cambiar de nuevo.
- [ ] **CA4** — Efectivo: vacío cobra exacto; con 170 sobre un total de 151.30 muestra vuelto
  18.70 y el pago enviado es 151.30; con 100 muestra «Falta» y no deja cobrar.
- [ ] **CA5** — El botón Cobrar se deshabilita con el motivo correcto en cada caso de la lista.
- [ ] **CA6** — Con 30 líneas la página no crece: la lista se desplaza por dentro y el buscador y
  el panel de cobro siguen visibles.
- [ ] **CA7** — `npm run type-check` no suma errores nuevos en los archivos tocados (T-101).

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas).
- [ ] Aislamiento de tenant: no aplica.
- [ ] Validación de entrada / archivos: el backend sigue validando; T-109 agrega lo nuevo.
- [ ] Concurrencia: no aplica (series las resuelve el backend).
- [ ] Dinero: redondeo a 2 decimales igual que hoy.
- [ ] Fechas con `HoraPeru`: fecha por defecto con `hoyPeru()`.
- [ ] Tributario sin fallback silencioso: la afectación por defecto es la que ya calcula la pantalla.
- [ ] Sin `v-html` sin sanitizar.
- [ ] Migraciones: no aplica.

## Cómo verificar
Tenant retail en dev (`http://<tenant>.sistemafe.test:5173/admin/login`), con caja abierta y
cerrada. Comparar el payload en DevTools contra `main` para los casos de CA1.

---

## Resultado
- **Commits:** rama `feat/T-106-pantalla-venta-retail` (ver `git log main..`): helpers + tests, componentes
  de `components/Sales/`, y `register.vue` como orquestador.
- **Tests:** `npx vitest run` 323/323 (16 nuevos en `helpers/ventaRetail.test.ts`: vuelto, atajos, cantidades,
  precio por cliente, motivos del botón). `vite build` ok. `vue-tsc`: 47 errores antes y 47 después, ninguno en
  los archivos tocados (CA7). Backend sin cambios (no se corrió su suite).
- **Prueba funcional (agencia-demo, Playwright, `POST sales` interceptado donde se indica):**
  - CA1: boleta contado → el JSON es **idéntico** al de la pantalla vieja (capturado antes y después, mismos
    campos y valores; `uid` y `precio_manual` se quitan de `sale_details`). Crédito con cuotas: mismas claves
    (`credit_type`, `cronograma`, `aplica_mora`, `tasa_mora`, `tipo_mora`, `debt`, `payments`). **Sin comparar
    JSON antes/después:** factura a RUC, adelanto y detracción (agencia-demo casi no tiene datos para armarlos;
    sus cálculos no se tocaron). Falta correrlos en umbo.
  - CA2: afectación cambiada se marca ámbar con «restaurar» y vuelve al valor por defecto.
  - CA3 (con productos/clientes simulados): general 18.56 → empresa 16.53 al elegir cliente empresa → vuelve; la
    línea con precio manual no cambia; avisa «Se actualizó el precio de N productos».
  - CA4: total 151.30, «170» → vuelto 18.70 y `payments[0].amount = 151.3`; «100» → «Falta S/ 51.30» y botón
    deshabilitado.
  - CA5: motivos probados: sin productos, sin cliente, factura sin RUC, caja cerrada (contado), crédito sin
    cronograma, detracción sin código, falta dinero. Sin stock y boleta > S/ 700 solo con tests unitarios.
  - CA6: con 30 líneas (simuladas en el DOM) la página no crece (1326 px antes y después) y la lista se desplaza
    por dentro (300 px visibles).
  - Dirección: con una sucursal elegida la venta guarda `client_address_id = 4` y el PDF A4 imprime esa dirección.
  - Guardar: imprime directo en el formato por defecto, deja la pantalla limpia con foco en el buscador y muestra
    «Boleta de Venta B001 guardada…» con Reimprimir / Ver PDF A4.
  - Datos de prueba que quedaron en agencia-demo: venta id 28 (boleta, S/ 100) y la dirección «Sucursal Tarapoto
    T106» de la cliente KAREN YARITZA RIVERA GRANDEZ.
- **Desviaciones de la tarea y por qué:**
  - **«Clientes varios» no existe** (ni en código ni en migraciones) y `store()` exige cliente: se mantuvo el
    cliente obligatorio («Elige un cliente» como motivo del botón). Decisión del usuario en esta sesión.
  - **Destino por defecto**: no hay configuración de dónde leerlo; sigue `amazonia` fijo en el front, como hoy.
    Decisión del usuario en esta sesión (ver hallazgos).
  - **Aviso al guardar** muestra la serie («B001 guardada»), no el número: el correlativo de un documento fiscal
    recién se asigna al enviarlo a SUNAT (`SaleController::store()`).
  - **Columna de precio** rotulada «Precio s/ IGV» y el importe de la línea incluye IGV: el sistema guarda el
    precio base sin IGV (la maqueta lo muestra con IGV). El cálculo no cambió.
  - **«Abrir caja»** abre la pantalla de caja en otra pestaña (para no perder la venta armada) y al volver se
    consulta de nuevo la sesión.
  - Si el usuario no tiene acceso a `cash/status`, el estado de caja queda «desconocido» y no se bloquea
    (lo valida el backend).
  - Boleta > S/ 700 sin DNI solo se valida en soles; el criterio en dólares queda para T-109.
  - Buscar un cliente por DNI/RUC ahora consulta `clients?search=` en vez de la lista completa que traía
    `sales/config` (que sigue enviándose: `register.vue` aún la guarda en `clients`).
  - `ProductFormQuick.vue` ganó una prop opcional `initialData` (nombre inicial desde el buscador).
  - `usePrintComprobante.ts`: nuevas `imprimirDirecto()` y `verPdfComprobante()` (sin diálogo de formato).

## Hallazgos fuera de alcance
- Falta crear el cliente genérico «Clientes varios» por tenant (backend + migración) para poder vender sin
  identificar al cliente; hoy se necesita un cliente con documento.
- Falta un campo de configuración para el destino por defecto (`amazonia`/`nacional`) en la empresa.
- `sales/config` devuelve todos los clientes y productos activos: ya no hacen falta para esta pantalla
  (buscan por API); sirve recortarlo cuando `edit.vue` (T-110) use los mismos componentes.
- `vue-tsc` tiene 47 errores previos en otros archivos (no de esta tarea).
- `sale_details` se manda con el objeto `product` completo (como siempre): se puede aligerar en T-109/T-110.
