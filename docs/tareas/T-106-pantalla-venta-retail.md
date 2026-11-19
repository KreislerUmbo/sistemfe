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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
