# Módulo nuevo: series de comprobantes, asignadas por sucursal

Series de comprobantes asignadas por sucursal (no por usuario). Esto resuelve
de raíz el bug de concurrencia que encontramos en `reservarCorrelativo()`
(serie nueva sin fila previa → dos requests concurrentes podían generar el
mismo correlativo=1) — la serie se crea explícitamente aquí, con su fila
semilla, antes de que exista ninguna venta.

Además, este módulo incorpora un tipo de documento interno nuevo: **nota de
venta**, para productos/servicios sin sustento de compra (ej. insumos
naturales comprados sin factura, servicios informales) que no pueden
facturarse ante SUNAT pero sí necesitan control de stock y de crédito a
clientes.

## Contexto de diseño (ya decidido, no lo cuestiones)

- La serie se determina por sucursal, no por usuario/cajero individual.
- Una sucursal puede tener más de una serie activa para el mismo tipo de
  comprobante si la moneda es distinta (ej. factura en soles y factura en
  dólares son dos series separadas). Esto es práctica contable interna, no
  requisito de SUNAT — no lo presentes como si fuera obligación normativa en
  ningún comentario o mensaje de UI.
- No hay datos reales que migrar — se empieza limpio. No escribas script de
  migración de datos existentes.
- SUNAT sí exige el prefijo correcto por tipo de comprobante (F### factura,
  B### boleta, FC##/BC## notas de crédito/débito) — valida esto en el form
  **solo cuando el tipo de comprobante es fiscal** (ver `es_documento_sunat`
  abajo). Para nota de venta el prefijo es libre (ej. `NV001`), sin
  restricción normativa.
- **Nota de venta es un tipo de documento interno, terminal**: no se convierte
  después en factura/boleta (eso se maneja con cotizaciones, un flujo
  separado que no es parte de esta tarea). Sí mueve stock/kardex igual que
  una venta normal. Sí participa del circuito de crédito (`type_payment`,
  `condicion_pago`, `installments`) igual que una boleta o factura a crédito.
  Lo único que NO hace es pasar por `enviarSunat()` — no genera XML, no
  consume Greenter, no setea `n_operacion`.
- Nota de venta usa el mismo mecanismo de correlativo con
  `lockForUpdate()` que los documentos fiscales — no hay una vía alterna más
  simple para su numeración.

## Paso 0 — Auditar impacto en `register.vue` / `edit.vue` (antes de tocar nada)

Este módulo nuevo va a afectar directamente el formulario de ventas — necesito
entender el estado actual antes de diseñar las tablas. Lee `register.vue` y
`edit.vue` y repórtame:

- ¿Cómo se determina hoy la serie/correlativo de una venta? ¿Es un valor fijo
  hardcodeado, viene de un `.env`/config, lo elige el usuario en un `<select>`,
  o lo calcula el backend sin que el frontend lo vea?
- ¿El formulario ya tiene algún concepto de "sucursal" (sea un selector, un
  valor de sesión del usuario logueado, o algo implícito)? Dado que ya existe
  `branches` del Módulo de Caja, ¿el form de ventas ya lo usa para algo hoy
  (ej. filtrar caja/registro), o sería la primera vez que ventas conoce el
  concepto de sucursal?
- ¿Dónde exactamente se decide el `tipo_comprobante` de la venta hoy (factura
  vs. boleta)? ¿Es el mismo lugar donde tendría que conectarse la nueva serie,
  o son cosas separadas? Este es también el lugar donde tendría que aparecer
  nota de venta como opción adicional — repórtame si ese selector está
  acoplado a la lógica de Greenter/SUNAT de alguna forma que complique
  agregar un tipo no-fiscal ahí.
- ¿`type_payment`/moneda de la venta ya existe como campo, o solo manejas
  soles hoy? Si no hay concepto de moneda en el form actual, agregar series
  por moneda implica agregar el selector de moneda también — dime si eso ya
  existe o es trabajo adicional no contemplado.

No propongas el diseño de tablas todavía. Solo reporta el estado actual de
estos cuatro puntos, con archivo y línea aproximada de cada uno. Con esa
información yo decido si el diseño de abajo necesita ajustarse antes de que
lo implementes.

---

## Paso 1 — Diseño de tablas, antes de migrar nada

Dos tablas, no una. **No las fusiones** — `tipos_comprobante` es catálogo de
referencia (existe sin que haya ninguna venta ni serie creada); `serie_comprobantes`
es instancia operativa por sucursal. Mezclar ambas obliga a inventar filas de
catálogo disfrazadas de series (`branch_id=null`) y duplica nombre/flags del
catálogo en cada serie creada.

### 1a. `tipos_comprobante` — catálogo SUNAT + documentos internos

Tabla de referencia, seed-only (no editable desde UI). Columnas:

| Columna | Tipo | Notas |
|---|---|---|
| `codigo` | string (PK natural) | "01", "03", "07", "08"... para SUNAT. `NV` para nota de venta — código no-numérico, claramente fuera del rango del Catálogo 01 real. |
| `nombre` | string | "Factura", "Boleta de Venta", "Nota de venta (interna)", etc. |
| `es_documento_sunat` | boolean | `true` para todo el Catálogo 01 real. `false` solo para nota de venta y futuros documentos internos. **Este es el campo que usa `SaleController` para decidir si llama a `enviarSunat()`** — no `activo_greenter`. |
| `activo_greenter` | boolean | `true` únicamente en los tipos SUNAT que `GreenterService` soporta hoy (probablemente 01, 03, 07, 08 — confirma cuáles antes de marcar, no asumas). Para nota de venta siempre `false`. |

**Invariante a validar** (en el seeder o como check constraint):
`activo_greenter=true` implica `es_documento_sunat=true`. Nunca debe poder
crearse una fila con `activo_greenter=true` y `es_documento_sunat=false` —
sería el caso exacto que rompería el guard de `enviarSunat()`.

Poblar el catálogo completo vía seeder idempotente (mismo patrón
`firstOrCreate()` que ya usas en `PermissionsDemoSeeder`):

Lista completa a sembrar (Catálogo 01 SUNAT): 00 Otros, 01 Factura,
02 Recibo por Honorarios, 03 Boleta de Venta, 04 Liquidación de compra,
05 Boleto de compañía de aviación comercial, 06 Carta de porte aéreo,
07 Nota de crédito, 08 Nota de débito, 09 Guía de remisión - Remitente,
10 Recibo por Arrendamiento, 11 Póliza de Bolsa de Valores/Productos,
12 Ticket o cinta de máquina registradora, 13 Documento de bancos/financieras/seguros,
14 Recibo por servicios públicos. Si conoces el resto del catálogo (15 en
adelante) del propio Greenter o su documentación, complétalo; si no, deja esos
14 como base y anótalo como pendiente de completar. Todos estos con
`es_documento_sunat=true`, y `activo_greenter` según lo que confirmes que
`GreenterService` soporta hoy.

Además, sembrar una fila adicional: `codigo='NV'`, `nombre='Nota de venta'`,
`es_documento_sunat=false`, `activo_greenter=false`.

### 1b. `serie_comprobantes` — series reales por sucursal

`unique(branch_id, tipo_comprobante_codigo, moneda)`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint (PK) | |
| `branch_id` | FK → `branches` | |
| `tipo_comprobante_codigo` | FK → `tipos_comprobante.codigo` | Acepta códigos SUNAT y `NV`. |
| `moneda` | string (`PEN`/`USD`) | |
| `serie` | string | Ej. `F001`, `B001`, `NV001`. Validar prefijo (F/B/FC/BC) **solo si** `tipos_comprobante.es_documento_sunat=true` para ese código; libre si es `NV`. |
| `correlativo_actual` | integer | Arranca en 0 — fila semilla que resuelve el bug de lock. Igual para nota de venta. |
| `correlativo_inicial` | integer | Valor de arranque configurado (normalmente 1). |
| `fecha_inicio` | date | |
| `activo` | boolean | |

Muéstrame ambas migraciones antes de aplicarlas — no las corras todavía.

## Paso 1c — Columna nueva en `sales`

Agregar `tipo_comprobante_codigo` (FK → `tipos_comprobante.codigo`) directo en
`sales`. No inferir el tipo desde el prefijo de `serie` — eso se rompe en
cuanto una serie de nota de venta use un prefijo distinto a `NV` (el prefijo
de `NV` es libre, no normado). `sales` ya guarda `serie`/`correlativo`
directamente (confirmado en la estructura actual); esta columna nueva se
llena en el mismo momento en que `SaleController::store()` reserva el
correlativo — no se infiere después ni se calcula on-the-fly en reportes.

Sin backfill de datos existentes (no hay datos reales que migrar en este
módulo). Muéstrame esta migración junto con las del Paso 1a/1b, antes de
aplicarla.

## Paso 2 — Conectar con `reservarCorrelativo()` y `SaleController::store()`

Modifica `reservarCorrelativo()` para que:
- Ya no dependa de que exista una venta previa para tener una fila que
  bloquear — ahora siempre hay una fila semilla desde la creación de la serie.
- El `lockForUpdate()` se hace sobre la fila de `serie_comprobantes`
  correspondiente a `(branch_id, tipo_comprobante_codigo, moneda)`. Mismo
  mecanismo para `NV` que para tipos fiscales.
- Si se intenta reservar un correlativo para una combinación
  `(branch_id, tipo_comprobante_codigo, moneda)` que no tiene serie activa
  creada, debe fallar con 422 explícito — nunca crear la serie implícitamente
  sobre la marcha.

Modifica `SaleController::store()` para que:
- Al reservar el correlativo, escriba también `sales.tipo_comprobante_codigo`
  con el código correspondiente (viene de la serie seleccionada) — no lo
  dejes para inferir después desde `serie`.
- Si `tipos_comprobante.es_documento_sunat=false` para el tipo elegido, se
  ejecuta el flujo completo normal (reserva de correlativo, movimiento de
  stock/kardex, registro de caja, `type_payment`/`condicion_pago`/`installments`
  si es a crédito) **excepto** la llamada a `enviarSunat()` — esa se salta
  por completo. No debe generarse XML, no debe llamarse a Greenter, no debe
  setearse `n_operacion`.
- Este guard debe leer `es_documento_sunat`, no `activo_greenter` — son
  campos distintos con distinto propósito, no los uses indistintamente.

Muéstrame el diff antes de aplicarlo.

## Paso 3 — CRUD del módulo (formulario)

Backend: controller + rutas para crear/listar/desactivar series por sucursal
(no permitir editar `serie` una vez que tiene correlativos usados — eso
rompería trazabilidad; sí permitir desactivar).

El formulario de creación de series debe ofrecer como opción de
`tipo_comprobante`:
- Todos los códigos con `activo_greenter=true` (tipos fiscales soportados
  hoy), y
- El código `NV` (nota de venta), aunque tenga `activo_greenter=false` — no
  lo filtres junto con el resto del catálogo pendiente. La condición para
  aparecer en este form es "`activo_greenter=true` O `codigo='NV'`", no
  simplemente "`activo_greenter=true`".

Backend también debe validar esto en el controller de creación de series, no
confiar solo en que el frontend filtre correctamente.

Frontend: formulario en `admin-start-kit` siguiendo el patrón de tus otros
CRUDs ya completados (roles, usuarios, categorías, productos, clientes) —
Bootstrap 5 + Rizz, mismo estilo.

## Paso 3.5 — Ajustes en `register.vue` / `edit.vue`

### Resolución de sucursal (prerequisito para el selector de serie)

El form de ventas no tiene hoy ningún concepto de sucursal. Antes de que el
selector de serie pueda saber qué series ofrecer, hay que resolver la
sucursal de la venta así:

- Agregar `branch_id` a `users` (FK → `branches`). Cada usuario tiene una
  sucursal fija asignada en su perfil — sin selector visible en el form para
  el caso general.
- Crear permiso nuevo en Spatie Permission: `can_switch_branch`. Si el
  usuario autenticado tiene ese permiso, el form de ventas muestra un
  `<select>` de sucursal (todas las `branches` activas) en vez de usar el
  `branch_id` fijo del perfil. Si no lo tiene, no se muestra ningún selector
  — se usa directo el `branch_id` del usuario.
- Este permiso no está atado al rol admin — es asignable independientemente,
  para el caso de un cajero de confianza que cubre turno en más de una
  sucursal, sin necesidad de subirlo a un rol con más alcance.
- Con una sola sucursal (Principal) hoy, este mecanismo no se nota en la
  práctica — todos los usuarios apuntan a la misma `branch_id` y nadie
  necesita el selector. Queda preparado para cuando exista una segunda
  sucursal real.

Con la sucursal resuelta (fija o elegida), el selector de tipo de
documento/serie del punto siguiente filtra las series disponibles por esa
`branch_id`.

### Control de qué tipos de documento puede emitir cada usuario

Además de la sucursal, un admin necesita poder restringir qué tipos de
documento puede emitir cada usuario (ej. "este cajero solo emite nota de
venta", "este solo factura/boleta, nunca nota de venta").

- Crear un permiso Spatie por cada tipo de comprobante relevante:
  `emitir_factura`, `emitir_boleta`, `emitir_nota_venta` (y los que
  correspondan si activas más tipos fiscales a futuro). Seedear estos
  permisos junto con el resto del seeder de permisos del proyecto
  (`PermissionsDemoSeeder` o el que corresponda), mismo patrón idempotente.
- El selector de tipo de documento en `register.vue`/`edit.vue` solo muestra
  las opciones para las que el usuario logueado tiene el permiso
  correspondiente — si un usuario solo tiene `emitir_nota_venta`, ni siquiera
  ve factura/boleta como opción.
- **Validación también en backend, no solo en el form**: `SaleController::store()`
  debe rechazar (422) si el usuario autenticado no tiene el permiso
  correspondiente al `tipo_comprobante_codigo` que está intentando emitir —
  mismo principio de "nunca confiar solo en que el frontend filtre
  correctamente" que ya aplicaste en el Paso 3 para el catálogo de tipos.
- Al usuario admin (o quien tenga todos los permisos de emisión asignados) no
  le cambia nada en la práctica — ve todas las opciones, como hoy.

### Selector de tipo de documento y campos fiscales

- **Selector de tipo de documento**: agregar "Nota de venta" junto a
  Factura/Boleta, listado solo si `activo_greenter=true` o `codigo='NV'`
  (mismo criterio que ya aplicaste en el form de creación de series — no
  dupliques la regla, considera exponerla desde el mismo endpoint/catálogo).
- **Al seleccionar nota de venta, ocultar dinámicamente (no solo
  deshabilitar)** los campos exclusivamente fiscales: régimen especial,
  detracción/retención/percepción, y forma de pago SUNAT si es un control
  distinto de `type_payment` interno. Ocultar, no deshabilitar — un campo
  deshabilitado pero visible sigue mostrando un valor que puede confundir al
  cajero sobre qué se está enviando.
- **Mantener visibles** siempre: cliente (opcional), productos, stock,
  métodos de pago, caja, y todo el bloque de crédito/`installments` que ya
  existe — nota de venta participa del circuito de crédito igual que boleta.
- **Regla de limpieza al cambiar de tipo en caliente** (decidido, no lo
  cuestiones): si el usuario cambia de boleta/factura → nota de venta a mitad
  de captura, y luego regresa a boleta/factura, **los campos fiscales que
  había llenado antes se limpian por completo al ocultarse** — no se
  conservan en memoria. Si vuelve a un tipo fiscal, los campos aparecen
  vacíos y debe volver a llenarlos. Mismo principio que ya aplicás en
  "Configuración de Crédito" (regenerar en vez de conservar estado
  potencialmente inconsistente) — aplicá el mismo patrón aquí, no inventes
  uno nuevo. Esto previene el caso real de riesgo: un campo fiscal oculto que
  conserva un valor viejo y termina viajando a `store()` sin que el cajero lo
  vea en pantalla.
- El bloque de crédito (`installments`) **no se toca** por este cambio de
  tipo de documento — su propia lógica de regeneración (por cambio de total
  del carrito) sigue funcionando igual, independiente de si el documento es
  fiscal o `NV`.

Muéstrame el diff de `register.vue`/`edit.vue` antes de aplicarlo, igual que
el resto de los pasos.

## Paso 3.6 — Reportes / listados

Con la columna `sales.tipo_comprobante_codigo` agregada en el Paso 1c, los
reportes pueden filtrar directo sobre `sales` sin join con
`serie_comprobantes`. Aún no existe un reporte PLE/Registro de Ventas SUNAT
construido en el sistema, así que no hay que auditar ni migrar nada
existente, pero sí dejar la regla establecida desde ahora para que no se
rompa cuando se construya. **No infieras el tipo de documento parseando el
prefijo de `serie` en ningún reporte nuevo** — usa siempre la columna
directa.

- **Cualquier query que alimente reportes SUNAT/PLE** (Registro de Ventas u
  otro que se construya a futuro) debe filtrar explícitamente
  `tipo_comprobante_codigo <> 'NV'` (o, más robusto a futuro si aparecen más
  tipos internos, filtrar por `tipos_comprobante.es_documento_sunat=true` vía
  join). Documentalo como comentario en el query/scope para que quede
  explícito por qué se excluye.
- **Nota de venta SÍ debe incluirse** en: cierre de caja (el monto es dinero
  real que entró a caja, aunque no sea declarable), kardex/stock (ya
  descuenta inventario igual que cualquier venta), y en un listado general de
  ventas internas separado del Registro de Ventas fiscal.
- **Dashboard de "ventas del día"** (si ya existe uno que mezcla todo tipo de
  venta): agregar un badge/indicador visual que distinga nota de venta de
  comprobantes fiscales en el mismo listado, para que no se lea como venta
  fiscal por error al revisar el día. Si el dashboard hoy no existe todavía,
  no construirlo en esta tarea — solo dejar anotado que cuando se construya,
  debe distinguir ambos tipos visualmente.

Si en el Paso 0 encuentras que ya existe algún reporte o dashboard que no
mencioné aquí y que consulta `sales` sin filtrar por tipo, repórtalo antes de
tocarlo — no lo modifiques sin mostrarme primero qué filtro le vas a agregar.

## Paso 4 — Tests

Reusa `sistemafe_test_migrations`. Cubre:
- Seeder de `tipos_comprobante` corre limpio e idempotente, incluyendo la
  fila `NV`.
- Invariante `activo_greenter=true` implica `es_documento_sunat=true` se
  respeta en el seed (ningún tipo SUNAT queda mal marcado).
- Crear serie sobre un `tipo_comprobante_codigo` con `activo_greenter=false`
  y `es_documento_sunat=true` (tipo fiscal no soportado aún) → debe
  rechazarse (422). Crear serie con `codigo='NV'` → debe aceptarse aunque
  `activo_greenter=false`.
- Crear serie → fila semilla con `correlativo_actual=0`.
- Reservar sobre serie sin crear → 422.
- Dos reservas secuenciales sobre la misma serie → consecutivos. Repetir este
  caso también para una serie `NV`.
- El test de concurrencia real (dos conexiones Postgres, `lock_timeout` corto)
  que ya escribiste para el caso anterior — repítelo aquí, y una vez más
  sobre una serie `NV`, verificando que SÍ hay fila desde el inicio.
- Intento de crear dos series con la misma
  `(branch_id, tipo_comprobante_codigo, moneda)` → debe fallar por el `unique`
  constraint.
- Crear venta con `tipo_comprobante=NV` a crédito → confirma que descuenta
  stock, genera `installments` correctamente, y **no** invoca `enviarSunat()`
  ni genera XML (mockear/espiar `GreenterService` para verificar que no se
  llamó).
- Usuario sin permiso `emitir_factura` intenta crear venta con
  `tipo_comprobante_codigo='01'` → 422. Mismo caso con `emitir_nota_venta`
  para tipo `NV`. Usuario con el permiso correspondiente → pasa normal.

Adicional (frontend, fuera de `sistemafe_test_migrations`, verificar manual o
con Playwright si ya tienes suite E2E corriendo contra `sandbox.sistemafe.test`):
- En `register.vue`, llenar campos fiscales con tipo boleta, cambiar a nota de
  venta, confirmar que los campos fiscales desaparecen del DOM (no solo
  `disabled`). Volver a boleta, confirmar que los campos reaparecen vacíos,
  no con el valor anterior.

## Regla general para toda esta tarea

Ve paso por paso, muéstrame cada migración/diff antes de aplicarlo — no
apliques todo de corrido. Esto toca la fuente de verdad de correlativos, así
que quiero revisar cada pieza.