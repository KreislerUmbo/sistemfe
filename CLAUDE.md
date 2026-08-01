# Sistema POS / E-commerce — Contexto del proyecto

## Estructura del proyecto
```
sistemafe/
├── CLAUDE.md
├── api-sistema-fe/       → Backend Laravel (API REST, lógica de negocio, SUNAT/Greenter)
└── admin-start-kit/      → Frontend Vue 3 (panel admin/POS, template Rizz/Riz)
```
El portal e-commerce (Vue.js) consume la misma API de `api-sistema-fe/` pero vive en un
repositorio/carpeta separado (no incluido en este workspace).

## Estado actual del proyecto (avance)

**Completado (CRUD):**
- Roles y permisos
- Usuarios
- Categorías
- Productos
- Clientes

**Completado — Ventas: matriz de pruebas tributarias (2026-07-19):**
- Registrar, actualizar y envío a SUNAT: implementado.
- **Matriz de pruebas tributarias (Bloques A-E) resuelta y automatizada.** La matriz
  original (documento de trabajo, nunca llegó a persistirse como archivo — vivió solo en
  la conversación de esa sesión) quedó cerrada caso por caso, con hallazgos reales
  corregidos en el camino, no solo confirmada por lectura de código:
  - **Bloque A — `resolverTipAfeIgv()`**: extraída de su duplicado en
    `register.vue`/`edit.vue` a `admin-start-kit/src/utils/resolverTipAfeIgv.ts` (función
    pura, sin cambio de comportamiento) para poder testearla — no existía ningún runner
    de test en el frontend hasta esta sesión (se instaló `vitest`). 15/15 casos verdes
    (`resolverTipAfeIgv.test.ts`). La matriz original tenía mal el modelo de variables
    para este código: `destino_venta` solo tiene 2 valores reales
    (`'amazonia'`/`'nacional'` — "exterior" no existe como destino, es el flag
    `is_exportacion` aparte, independiente), y no existe `naturaleza_producto =
    'exonerado_amazonia'` como atributo de producto — la exoneración Amazonía es 100%
    dinámica por destino de venta, aplicada a cualquier producto no exonerado ya a nivel
    de producto. El caso A2 de la matriz original estaba directamente mal (decía que un
    producto gravado no cambia por destino — sí cambia: pasa a exonerado con destino
    Amazonía). IVAP (arroz pilado, código '17') agregado — la matriz original no lo
    contemplaba como categoría propia.
  - **Bloque B — `validarRegimenEspecial()`**: 14/14 casos verdes (B1-B14,
    `tests/Unit/ValidarRegimenEspecialTest.php`, invocado por reflexión — el método no
    toca BD). La preocupación original de la matriz (exoneración Ley 27037 vs Apéndice I
    con distinta sujeción a detracción según Anexo 2/subpartida) no aplica al código
    real: el guard es un chequeo ciego sobre montos agregados de la venta
    (`mto_oper_exoneradas`/`inafectas`/`is_exportacion`), sin distinción por origen de la
    exoneración — si hace falta esa distinción es una pregunta de negocio para el
    contador, no algo bloqueado en código hoy.
  - **Bloque C — carrito con códigos SPOT mixtos**: resuelto como no-aplicable.
    `codigo_detraccion` es un campo único a nivel de venta completa (un solo `<select>`,
    `condicion_especial == '2'`), no por línea de producto — el escenario que la matriz
    describía (conflicto de códigos SPOT entre ítems del mismo carrito) no es construible
    en el modelo de datos actual.
  - **Bloque D — FormaPago SUNAT (contado/crédito)**: 5/5 casos verdes (D1-D4 + D4b,
    `tests/Feature/GreenterServiceFormaPagoTest.php`, contra Postgres real). Confirmado
    que el guard 422 de `GreenterService::cuotasActivasParaCredito()` (Fase 8.0 de
    Amortizaciones) sigue vigente — nunca cae en silencio a `FormaPagoContado()`.
  - **Bloque E — condición especial simultánea**: resuelto como no-aplicable.
    `condicion_especial` es un único `<select>` excluyente
    (`0`/`anticipo`/`exportacion`/`1`/`2`/`3`) — la UI actual estructuralmente no permite
    elegir dos condiciones especiales a la vez.
- **Bugs reales encontrados y corregidos al validar el flujo completo de envío** (fuera
  del alcance original de la matriz, aparecieron al construir el entorno de prueba contra
  Postgres real):
  - `database/migrations/tenant/2026_07_14_090000_alter_products_add_codigo_detraccion.php`
    duplicaba una columna que la migración central
    `2026_07_13_090500_fix_detraction_codes_rebuild_schema.php` ya agregaba un día antes
    — invisible en producción porque central y tenant nunca corren juntas contra la misma
    base física, pero rompía cualquier intento de correr ambos conjuntos de migraciones
    sobre una sola base de test. Corregido con `Schema::hasColumn()` guard, sin tocar la
    migración central (esa pregunta —si el bloque `Schema::table('products', ...)`
    debería vivir ahí— queda abierta, ver pendientes).
  - `GreenterService::procesarRespuestaSunat()` vivía fuera del `try/catch` de
    `enviarSunat()` (`FacturacionElectronicaController.php`) — un fallo ahí (storage al
    guardar el CDR, respuesta SUNAT con forma inesperada) quemaba el correlativo ya
    reservado sin dejar ningún `sunat_error_message`, a diferencia de todos los demás
    fallos posteriores a `reservarCorrelativo()`. Corregido con un segundo `try/catch`
    aditivo que deja `sunat_error_message = "CDR recibido pero no procesado: ..."` — sin
    tocar el `try/catch` original ni el camino de éxito. Test de regresión:
    `tests/Feature/EnviarSunatCdrFailureTest.php` (2 casos, incluye uno de control que
    confirma que el `try/catch` original sigue sin el prefijo nuevo).
  - `reservarCorrelativo()` (lock atómico de correlativos SUNAT) verificado con 4 tests
    (`tests/Feature/ReservarCorrelativoTest.php`): reservas secuenciales consecutivas, el
    "hueco esperado" cuando algo falla después de reservar (confirmado como diseño
    aceptado, no bug — el correlativo ya commiteó en su propia transacción antes de que
    el resto del flujo corra), y una prueba real de bloqueo entre dos conexiones Postgres
    distintas (`lock_timeout` corto en vez de una carrera cronometrada — sin
    infraestructura de procesos/fork).
- **Infraestructura de testing nueva, reusable para el resto del proyecto**: base
  Postgres dedicada `sistemafe_test_migrations` (recreada limpia con las 76 migraciones
  reales — 16 central + 60 tenant — corriendo sin exclusiones salvo las 3 migraciones de
  stancl/tenancy que hardcodean la conexión `central`); `vitest` instalado en
  `admin-start-kit` (antes no existía ningún runner de test en el frontend). Todos los
  tests corren en transacción-por-test revertida en `tearDown()`, excepto el test de lock
  entre conexiones (necesita un commit real momentáneo + limpieza manual garantizada,
  documentado en el propio archivo). Nunca se tocó `sv_facturacion` ni ningún tenant real.
- **Pendiente, reportado y explícitamente diferido — no resuelto en esta sesión**:
  - Comunicación de baja SUNAT: sin ninguna conexión al flujo de correlativos huérfanos.
    Greenter ya la soporta a nivel de librería (`vendor/greenter/core/.../Voided/`),
    nunca se conectó a nada del proyecto.
  - La migración central `fix_detraction_codes_rebuild_schema.php` sigue alterando
    `products` — tabla que el propio mapa central/tenant del proyecto documenta como de
    tenant, no central. Funciona hoy solo porque `sv_facturacion` conserva una tabla
    `products` heredada de antes del split. Pregunta arquitectónica abierta, no una
    migración rota.
  - Producto real en `umbo` (id=37, "ANTIVIRUS ESET INTERNET SECURITY NOD32") con
    `tip_afe_igv_default='20'` (exonerado) — vendido 6 veces así, sin relación evidente
    con Ley 27037 ni Apéndice I/II. Posible error de carga de catálogo, a confirmar con
    el contador — no es un bug de código.
  - Riesgo de diseño en `reservarCorrelativo()` (documentado, no corregido): con una
    `serie` sin ninguna venta previa, `lockForUpdate()` no tiene fila que bloquear — dos
    requests concurrentes para la primera venta de una serie nueva podrían ambos calcular
    `correlativo = 1`.

**Completado — Notas de Crédito/Débito (2026-07-14/15):**
- Módulo construido de punta a punta: emisión, envío a SUNAT, PDF (A4/ticket 80mm), listado
  con filtros, reposición de stock atada a aceptación real de SUNAT (no a la creación).
- **Los 13 motivos del catálogo SUNAT (09 NC / 10 ND) están habilitados y cada uno validado
  con un comprobante real aceptado por SUNAT BETA** (`note_motivos.disponible_flujo_simple`
  controla qué motivos se muestran en el formulario — arrancó en 6/13, terminó en 13/13):
  - Total (clon 1:1 de la venta, sin recálculo de montos): NC01, NC02, NC03, NC06, NC10.
    NC03/NC10 forzados a total-only (`permite_parcial=false`) — una corrección de texto u
    "otros conceptos" no debe mover montos.
  - Parcial modo cantidad (devolución física, prorratea todos los montos por cantidad):
    NC07 — el único que reduce `quantity`.
  - Parcial modo monto (ajusta valor de una línea SIN devolver unidades — mantiene
    `quantity` original, pero recalcula `price_base`/`price_final` a partir del monto):
    NC05, NC09, ND02. SUNAT exige, sin excepción, que
    `LineExtensionAmount == precio_unitario × cantidad` — ni un `AllowanceCharge`
    documentando la diferencia lo evita (error 3271/3272, confirmado en dos rondas de
    pruebas reales). NC08 comparte el mismo código pero **no se probó en vivo todavía** —
    confirmar contra SUNAT antes de usarlo en un caso de negocio real. ICBPER/ISC
    intencionalmente en 0 en modo monto (no hay devolución física de unidades) — confirmado
    contra SUNAT BETA con un producto real con ICBPER.
  - Descuento global (clona toda la venta + aplica un único descuento prorrateado, sin tabla
    de ítems): NC04. Requirió persistir `notes.discount_global` (no vive en ninguna línea) y
    conectarlo en `GreenterService::getNote()`.
  - Concepto libre (sin línea de venta asociada, producto `is_especial_nota=1`): ND01, ND03.
- Dos entradas al formulario (`nota-create.vue`, sin duplicar componente), mismo filtro de
  motivos compartido: desde la fila de una venta (`/sale/nota/:id`) y desde un botón
  "Nueva Nota Crédito/Débito" en `/nota/list` con búsqueda de venta previa por cliente/
  DNI-RUC/serie-correlativo (`GET notas/buscar-venta`).
- **Bugs reales corregidos en el camino** (todos descubiertos recién al probar contra SUNAT
  BETA real, no por revisión de código):
  - `TotalesComprobanteCalculator::calcular()` no reducía el IGV al aplicar un descuento
    global — la base gravada bajaba pero el IGV declarado quedaba igual. Afecta también a
    ventas normales con `sales.discount_global`, no solo a notas.
  - El mismo método leía `$d->subtotal` como propiedad de objeto dentro del cálculo de IGV
    con descuento — funciona con `sale_details` (Eloquent models) pero no con notas totales
    clonadas (arrays planos), dejando el IGV en 0 en silencio. Corregido con `data_get()`.
  - El tope de "cantidad disponible para acreditar" sumaba cantidad de *todas* las NC
    aceptadas sobre una línea, incluyendo motivos de valor (04/05/08/09) que mantienen la
    cantidad original — bloqueaba notas legítimas después de una nota de valor sobre la
    misma línea. Corregido excluyendo esos motivos del conteo.
- **Pendiente:** ajuste automático de `debt`/`paid_out`/`state_payment` en `sales` cuando se
  acepta una NC/ND (NC reduce deuda con piso en 0, ND la aumenta, proporcional en notas
  parciales) — decisión pendiente de confirmar: automatizar solo para ventas sin
  retención/detracción/percepción (`retencion_igv = 0`), el resto no es trivial de calcular
  sin criterio del contador. Tabla `client_credit_movements` (saldo a favor del cliente
  cuando una NC supera lo que la venta debía, incluyendo el caso "ya estaba pagada") —
  diseño acordado (columnas, estados `pendiente`/`aplicado_a_venta`/`reembolsado_*`), sin
  construir.

**Completo — Módulo de Amortizaciones / ventas a crédito (2026-07-15 a 2026-07-16):**
- Diseño completo en `plan-modulo-amortizaciones.md` (raíz del repo). **Fases 1-9 de 9
  completas y aprobadas — módulo cerrado**: migraciones, modelos/factories, cronograma de cuotas
  (`CreditInstallmentController`), pagos con algoritmo FIFO
  (`CreditPaymentController`/`CreditPaymentAllocator`), anulaciones de cuota/pago,
  devolución con retención parcial (§3.12), reemplazo de comprobante con traspaso de pagos
  (§3.13), y mora on-the-fly (`MoraCalculator`, conectada al flujo de pagos).
  **Fase 7 completa (2026-07-15)**: estado de cuenta consolidado —
  `GET /clients/{client}/credit-summary` (`CreditPaymentController::creditSummary()`) más
  las dos vistas de §3.11/§4 que no tenían controlador propio todavía, resueltas en la misma
  sesión: `GET /credit-sales` (vista B, listado plano) y `GET /clients/credit-summary-list`
  (vista A, agrupado por cliente), ambas en `CreditReceivablesController` nuevo. Cálculo por
  venta (saldo, mora on-the-fly, cuotas vencidas, próxima cuota, estado `al_dia`/
  `por_vencer`/`vencida`) centralizado en `CreditSummaryCalculator` (servicio nuevo,
  compartido por los 3 endpoints — mismo criterio del plan de "no duplicar el cálculo, solo
  presentarlo distinto"). Mismo filtro base que el resto del módulo: `saldo_pendiente > 0`
  sin exigir `condicion_pago='credito'` (incluye deuda informal `contado` vía
  `debt`/`paid_out`). **Umbral de `por_vencer` fijado en 7 días — asunción mía, no
  confirmada con el usuario**, el plan menciona el estado pero nunca fija el número
  (`CreditSummaryCalculator::DIAS_POR_VENCER`, fácil de mover a config de `companies` si el
  negocio quiere otro valor). `clients/credit-summary-list` registrada ANTES de
  `Route::resource("clients", ...)` en `routes/api.php` para no chocar con `clients/{client}`
  (mismo patrón ya usado con `sales/config`). Verificado con datos reales del tenant `umbo`
  dentro de una transacción revertida (`DB::rollBack()`), no hay filas de prueba persistidas.
- **Fase 8 completa (2026-07-16)** — gap cerrado: `SaleController::store()`/`update()`
  ahora sí marcan una venta como "a crédito". `type_payment==2` (selector ya existente en
  `register.vue`, sin control paralelo nuevo) es el único hecho de origen — el backend
  deriva `condicion_pago` de ahí, nunca lo acepta como campo separado del payload.
  `store()` valida (`validarConfiguracionCredito()`) y persiste `credit_type='cuotas_fijas'`
  (único valor soportado esta fase — `'libre'` rechazado con 422, se sigue creando solo a
  mano), el cronograma (`installments`, suma exacta en centavos contra `saldo_pendiente =
  $request->debt`, ya neto de adelantos/pagos iniciales) y mora opcional, todo en la misma
  transacción. `update()` NO crea/edita cronogramas (eso sigue siendo exclusivo de
  `CreditInstallmentController`, Fase 3) — solo sincroniza `condicion_pago` y bloquea
  cambiar `type_payment` 2→1 si ya hay `installments`/`payment_applications` reales (evita
  huerfanarlos). Nuevo endpoint `POST installments/schedule-preview`
  (`CreditInstallmentController::previewSchedule()`) genera el cronograma sugerido sin
  necesitar una venta ya persistida — reusa `InstallmentScheduleCalculator` (Fase 3) en vez
  de reimplementar el redondeo a centavos en TypeScript. `register.vue`: nueva tarjeta
  "Configuración de Crédito" (num_cuotas/periodicidad/cronograma editable/mora), y se quitó
  el input "Fecha de Pago" por pago (ya no alimentaba nada real, ver Fase 8.0 abajo).
  Verificado en 3 rondas: matriz de casos vía controladores reales contra `sandbox`
  (rollback), y de punta a punta con un browser real (Playwright) creando una venta a
  crédito completa a través de la UI — confirmado en BD y contra
  `GreenterService::getInvoice()`.
- **Fase 8.0 completa (2026-07-16) — fix bloqueante, hallado y cerrado ANTES de la Fase 8
  principal**: `GreenterService::getInvoice()` armaba el `FormaPagoCredito`/`Cuota[]` del
  XML SUNAT desde `sale_payments` (mecanismo previo, sin relación con `installments`), con
  fallback silencioso a `FormaPagoContado()` cuando no aplicaba. Confirmado contra datos
  reales de `umbo` (no teórico): la única venta `type_payment=2` real en dev (id=16,
  `F001-00000024`) **ya fue enviada y ACEPTADA por SUNAT como `FormaPagoContado()`** siendo
  en realidad una venta a crédito interna — no se corrige retroactivamente (XML ya
  aceptado). Ahora: `type_payment==2` con `installments` activas arma `FormaPagoCredito`
  desde ahí; `type_payment==2` sin `installments` activas (incluye legado) bloquea con 422
  explícito, nunca cae en silencio a contado — rama vieja de `sale_payments` eliminada (0
  filas reales dependían de ella). Guard duplicado en
  `FacturacionElectronicaController::enviarSunat()`, ANTES de `reservarCorrelativo()`, para
  no quemar un correlativo SUNAT real en una venta que de todos modos iba a fallar más abajo
  (mismo bug que ya había dejado un correlativo huérfano en el pasado, comentario existente
  en el código junto al try/catch de `getInvoice()`).
- **Fase 9 completa (2026-07-16) — cierre del módulo, solo frontend, cero cambios de
  backend** (todos los endpoints consumidos ya existían y estaban verificados desde Fases
  4/7): dos pantallas nuevas en `admin-start-kit` — `src/views/credit/index.vue` (Cuentas
  por Cobrar, toggle vista A "por cliente"/vista B "por venta", filtros, paginación) y
  `src/views/credit/client-detail.vue` (estado de cuenta de un cliente + formulario de pago
  general/específico con preview editable del reparto FIFO, mismo patrón de tabla editable
  que el cronograma de Fase 8). Ruta nueva `/cuentas-por-cobrar` + entrada de menú bajo
  "Ventas", sin permiso Spatie nuevo (reusa `list_sale` — ninguno de los endpoints
  consumidos exige permiso especial). **Desviaciones del plan original acordadas
  explícitamente** (§6 punto 9 no las preveía con este detalle): no existe página de
  ficha/edición de cliente en el admin (los clientes se editan por modal) — la "pestaña
  Créditos" del plan se reemplaza por una página de detalle propia
  (`credit_receivables.client`), reachable desde el listado; sin historial de recibos de
  pago (no existe endpoint de listado de `payment_receipts`, solo preview/store/anular/
  refund/replace — queda pendiente para cuando se aborde el PDF del recibo, §3.10, tampoco
  construido); sin UI de anular cuota/pago/refund/replace (operaciones de supervisor, se
  siguen operando por API directa). Verificado de punta a punta con browser real
  (Playwright) contra `sandbox`: listar cartera, entrar al detalle de un cliente, cobrar un
  pago parcial contra una venta específica, confirmar que el estado de cuenta se actualiza
  (deuda_total bajó exactamente el monto cobrado).
- **Ya corregido** (drift real encontrado y arreglado, no solo documentado):
  `SalePaymentController::store()`/`destroy()` (el flujo legado de "pago parcial de venta
  contado") ahora espeja `saldo_pendiente` simétricamente con `debt`/`paid_out`, y bloquea
  con 422 si la venta es `condicion_pago='credito'` (esas se cobran solo por el módulo
  nuevo). Antes de esta corrección hubo 4 ventas reales en dev con `debt > 0` pero
  `saldo_pendiente = 0` — backfilleadas manualmente (SQL revisado antes de correr).
- **Decisión de negocio explícita (no reabrir)**: dentro de un pago, el monto cubre
  **capital primero, mora después** (no la recomendación por defecto que se había sugerido,
  que era mora-primero). Consecuencia aceptada: si un pago salda el capital completo de una
  cuota de un tirón sin cubrir toda la mora de ese momento, esa mora queda perdonada para
  siempre (mora nunca se persiste como saldo aparte, es cálculo on-the-fly puro) — decisión
  tomada y cerrada el 2026-07-15, no es un bug pendiente.
- Permisos Spatie nuevos (`anular-cuota-credito`, `anular-pago-credito`,
  `liquidar-devolucion-credito`, `reemplazar-comprobante-credito`): creados pero sin asignar
  a ningún rol operativo por defecto (mismo criterio que Adelantos) — pendiente asignarlos
  desde la UI de Roles cuando se decida quién los tiene.
- **Historial de recibos de pago + PDF del recibo — CERRADO (2026-07-18)**: cierra el
  pendiente que había dejado la Fase 9 (ver arriba). `PaymentReceiptController` nuevo
  (`GET /clients/{client}/payment-receipts`, listado paginado real) + PDF interno en A4/
  ticket 80mm (`GET /payment-receipts-pdf-url/{id}` + `/payment-receipts-pdf/{id}`, mismo
  patrón de URL firmada que ventas/notas, **sin QR fiscal** — no es comprobante SUNAT).
  Bloque nuevo en `client-detail.vue`. Verificado contra recibos reales del tenant `umbo`
  (`REC-00001`/`REC-00002`), ambos formatos renderizan sin error.
- **Editar venta a crédito antes de enviar a SUNAT (corregir pago inicial / productos) —
  CERRADO (2026-07-18)**: `SaleController::update()` ignoraba en silencio `payments` y nunca
  tocaba `installments`/`saldo_pendiente` al editar — un pago inicial mal tipeado (monto o
  método equivocado) quedaba sin forma de corregirse. Ahora: si la venta a crédito **no**
  tiene cuotas cobradas ni un adelanto aplicado, se puede editar libremente productos y pago
  inicial, exigiendo regenerar el cronograma completo en el mismo guardado (mismo
  `validarConfiguracionCredito()` de `store()`, reutilizado). Si **ya** tiene cobros
  formales (`payment_applications` activas o `advance_applications`), la edición se bloquea
  con 422 — hay que anular esos cobros primero por sus propios flujos (Cuentas por Cobrar /
  Adelantos), no se tocan desde un PATCH de venta. `edit.vue` ganó la misma tarjeta
  "Configuración de Crédito" que ya tenía `register.vue`. Verificado con 2 simulaciones
  reales contra `umbo` en transacciones revertidas (corrección de pago inicial exitosa;
  bloqueo confirmado con una cuota ya cobrada).
- **UI para anular un recibo de pago + permisos de crédito configurables desde Roles —
  CERRADO (2026-07-18)**: cierra el gap que dejaba bloqueada la edición de una venta a
  crédito con cobros formales (punto anterior) sin ninguna forma de destrabarla desde la
  UI. Botón "Anular pago" nuevo en el historial de recibos de `client-detail.vue` (Cuentas
  por Cobrar), gateado por el permiso Spatie `anular-pago-credito` ya existente
  (`authStore.isPermitedRoute(...)`, primer uso de este método DENTRO de un botón de vista
  — antes solo se usaba a nivel de ruta/menú), con motivo de anulación obligatorio (mínimo
  5 caracteres, mismo mínimo que ya exigía el backend) capturado en un `Swal.fire` con
  textarea + `preConfirm`. Llama al endpoint `POST payment-receipts/{id}/anular` que ya
  existía (`CreditPaymentController::anular()`, Fase 5A) — cero cambios de backend
  necesarios para esta parte.
  **Hallazgo bloqueante encontrado en el camino**: los 4 permisos del módulo de crédito
  (`anular-cuota-credito`, `anular-pago-credito`, `liquidar-devolucion-credito`,
  `reemplazar-comprobante-credito`) **nunca se habían migrado al tenant `umbo`** — viven en
  `database/migrations/tenant/`, pensadas para `tenants:migrate`, pero esa migración
  específica había quedado pendiente (`sandbox` sí las tenía). En la práctica, esto
  significa que los endpoints `anular()`/`refund()`/`replace()` del módulo de crédito
  probablemente venían fallando en `umbo` para cualquier usuario no-Super-Admin desde que
  se crearon (2026-07-15) — no era solo un problema de UI. Corregido corriendo
  `php artisan tenants:migrate --tenants=umbo` (3 migraciones pendientes, confirmado que no
  había ninguna otra pendiente), con aprobación explícita antes de correr contra la base
  real.
  **Segundo gap encontrado**: aunque los permisos ya existían en el backend desde
  2026-07-15, nunca se habían agregado al catálogo hardcodeado de permisos del frontend
  (`admin-start-kit/src/types/roles.ts`, constante `PERMISOS`) — no eran asignables a
  ningún rol desde la pantalla de Roles y Permisos pese a existir. Se agregó un módulo
  nuevo "Créditos (Amortizaciones)" con los 4 permisos, mismo patrón que los módulos
  existentes (Roles, Usuarios, Productos, etc.) — se renderiza automáticamente, no requirió
  tocar `roles/index.vue`. Verificado con una simulación completa en una transacción
  revertida contra `umbo`: aplicar pago → confirmar bloqueo de edición → anular pago →
  confirmar que `saldo_pendiente` vuelve al valor original y que la edición de la venta se
  destraba.
- **`sales.state_payment` desincronizado del módulo de Amortizaciones — CERRADO
  (2026-07-18)**: el listado de ventas (`sale/index.vue`) pinta "Pendiente"/"Parcial"/
  "Pagado" leyendo `state_payment` directo — pero `CreditPaymentController` (Amortizaciones)
  siempre mantuvo `saldo_pendiente` al día sin nunca tocar `state_payment`, a diferencia del
  flujo legado de contado (`SalePaymentController`), que sí lo actualiza al cobrar/revertir.
  Consecuencia real detectada por el usuario: una venta a crédito ya cobrada 100% (con
  recibo activo en Cuentas por Cobrar) seguía apareciendo "Pendiente" en el listado de
  ventas. Decisión de negocio confirmada: `state_payment` representa estado **actual**, no
  un snapshot histórico — para reportes de "deuda a la fecha X" hace falta otra cosa
  (sumar `payment_applications`/`sale_payments` por `fecha_pago`), no este campo. Fix:
  `CreditPaymentController::actualizarStatePayment()` (helper nuevo, mismo criterio 1/2/3
  que `SalePaymentController`, pero basado en `saldo_pendiente` — este módulo no mantiene
  `debt`/`paid_out` al día más allá de la creación), conectado en los 4 puntos donde el
  módulo toca `saldo_pendiente`: `store()`, `anular()`, `refund()`, `replace()`. Verificado
  el ciclo completo (pendiente→parcial→pagado→parcial→pendiente) en una transacción
  revertida contra `umbo`. **Backfill real ejecutado** (3 ventas encontradas con el dato
  viejo, con aprobación explícita antes de correr el UPDATE): `#25`/`#29`
  (`F001-00000025`/`F001-00000028`, saldo 0 pero `state_payment=1`) → `state_payment=3`;
  `#27` (`F001-00000027`, con pagos parciales activos pero `state_payment=1`) →
  `state_payment=2`.

- **`sales.debt`/`paid_out` como snapshot congelado, no saldo actual — documentado, sin
  resolver (2026-07-21)**: mismo patrón de fondo que el bug de `state_payment` de arriba,
  pero un nivel más abajo. `CreditPaymentController` (Cuentas por Cobrar) mantiene
  `saldo_pendiente` al día en cada cobro/anulación/reembolso, pero **nunca toca `debt`ni
  `paid_out`** — esos dos solo los actualiza el flujo legado (`SaleController::store()`/
  `update()` al crear/editar, `SalePaymentController` al agregar/quitar un pago desde
  "Editar venta"). Consecuencia real: una venta pagada 100% vía Cuentas por Cobrar después
  de su creación se queda con `debt`/`paid_out` mostrando el saldo **inicial** para
  siempre — confirmado con la venta #16 (`F001-00000024`): `debt=800` permanente aunque
  esos 800 se cobraron de verdad después (2 recibos reales, `REC-00001`+`REC-00002`,
  verificados contra `payment_applications`).
  - **Gap relacionado, cerrado en la misma sesión**: `SaleController::store()`/`update()`
    fijaban `saldo_pendiente=0` para toda venta `condicion_pago='contado'` sin importar el
    `debt` real del pago inicial — rompía el filtro de Cuentas por Cobrar
    (`saldo_pendiente > 0`, diseñado a propósito para incluir deuda informal contado, ver
    `CreditSummaryCalculator`). Corregido: ambos métodos ahora guardan
    `saldo_pendiente = $request->debt` sin condicionar a `condicion_pago`. Caso real que
    destapó esto: venta #37 (`F001-00000030`, total 700, pago inicial 300, `debt=400`) no
    aparecía en Cuentas por Cobrar pese a la deuda real.
  - **Backfill real ejecutado, con un error propio corregido en el camino**: el primer
    escaneo (`condicion_pago='contado' AND debt>0 AND saldo_pendiente=0`) encontró 2 ventas
    (#16 y #37) y se les puso `saldo_pendiente = debt` a ambas — pero para la #16 eso estaba
    MAL: su `saldo_pendiente=0` ya era correcto (pagada de verdad vía recibos, ver arriba),
    no el bug. Detectado al unificar el historial de pagos (punto siguiente) y ver los
    recibos reales aplicados contra ella; revertido (`saldo_pendiente` de la #16 vuelto a
    0) antes de dar el caso por cerrado. La #37 sí era el bug real (cero
    `payment_applications` contra ella) y quedó con `saldo_pendiente=400`, correcto.
  - **Historial de pagos de cliente, unificado (`PaymentReceiptController::index()` +
    `client-detail.vue`)**: el pago inicial de una venta (contado o adelanto de crédito) se
    guarda como `sale_payment` simple y nunca generó un `PaymentReceipt` — el historial de
    la ficha del cliente en Cuentas por Cobrar mostraba "sin recibos" para un cliente que sí
    había pagado algo. Ahora el endpoint combina ambas fuentes (`sale_payments` +
    `payment_receipts`) en una sola lista ordenada por fecha, marcada con `origen: 'recibo'`
    o `'pago_venta'` — el frontend oculta anular/PDF para los `pago_venta` (no son recibos
    reales, no tienen esas acciones). Paginación en memoria (mismo patrón que
    `CreditReceivablesController::paginar()`) porque ahora la fuente son dos tablas.
  - **Pendiente, explícitamente diferido por el usuario ("documenta, ya veremos más
    adelante")**: decidir qué hacer con `debt`/`paid_out` de fondo. Ninguna pantalla del
    frontend los muestra hoy (confirmado por grep — cero usos de `.debt` en
    `admin-start-kit/src`), así que no hay bug visible en producción, pero cualquier reporte
    o consulta futura que los use ingenuamente como "saldo actual" va a leer un dato
    congelado desde la creación/última edición de la venta. Camino sugerido si se retoma:
    migrar `SalePaymentController` para que también mantenga `saldo_pendiente` (dejar un
    solo campo vivo) antes de evaluar si `debt`/`paid_out` se pueden deprecar del todo —
    eliminarlos hoy rompería `SalePaymentController::store()`/`destroy()`, que los lee y
    escribe activamente.

**En progreso — Módulo de Caja (2026-07-18):**
- Diseño completo en `plan-modulo-caja.md` (raíz del repo), principio rector: "ningún
  movimiento de dinero debe ser silencioso". **Fases 0-6 de las 7 propuestas completas y
  verificadas contra `sandbox`** (nunca contra `umbo`/dev real) — solo queda Fase 7
  (multi-caja simultánea), en espera de que el negocio abra efectivamente una segunda caja
  real (checklist de activación completo en `plan-modulo-caja.md`, sección Fase 7).
- **Fase 0 — Catálogos base (`payment_methods`, `suppliers`, `cash_concepts`)**: CRUD
  completo de los 3. Guard en `SaleController::validarPagosPayload()` que valida cada
  `method_payment` recibido (incluso en pago mixto) contra `payment_methods.is_active`, sin
  aceptar el string a ciegas — rechaza el payload COMPLETO ante un método inválido, antes de
  abrir transacción (nada se persiste). `register.vue`/`edit.vue` migrados de `<option>`
  hardcodeado a `GET payment-methods?active=1`. Seed exacto (carácter por carácter) de los 5
  valores ya usados en producción: EFECTIVO, TRANSFERENCIA, YAPE, PLIN, TARJETA DE CREDITO.
  **Drift detectado y documentado, no corregido (fuera de alcance)**: `advances.payment_method`
  (Adelantos) usa valores distintos (`TARJETA` en vez de `TARJETA DE CREDITO`, Yape/Plin
  fusionados) — anotado en plan §6 (Fase 6 de caja) para resolver cuando Adelantos se
  conecte a este catálogo. Verificado con 7 ventas reales enviadas a SUNAT BETA contra
  `sandbox` (5 métodos individuales + 1 pago mixto + 1 método inventado rechazado con 422
  sin persistir nada) — las 6 válidas con `xml`/`cdr` poblados, comprobante idéntico al de
  antes del cambio.
- **Fase 1 — Modelo de datos base**: `branches` (no existía, se creó), `cash_registers`,
  `cash_sessions` (con índice único parcial de Postgres — una sola sesión `open` por caja,
  `CREATE UNIQUE INDEX ... WHERE status='open'`, verificado en `pg_indexes` tras correr la
  migración), `cash_movements` (polimórfico manual `reference_type`/`reference_id`, sin
  `morphs()` de Eloquent — no hay precedente de `morphTo`/`morphMany` en el proyecto y las
  referencias apuntan a modelos con convención propia, `sales` ya existente),
  `cash_session_totals`, `cash_session_denominations`. Config de caja
  (`blind_close_default`, `allow_multiple_registers_per_branch`, `difference_tolerance`,
  `require_expense_concept`, `require_expense_approval`, `max_expense_without_approval`)
  agregada a `companies` (mecanismo de config por tenant ya existente, mismo patrón que los
  defaults de mora de Amortizaciones) en vez de crear una tabla `cash_settings` nueva.
- **Fase 2 — Apertura y cierre de caja**: `CashSessionController::status()/open()/close()`.
  `open()` usa `lockForUpdate()` sobre la caja + captura `UniqueConstraintViolationException`
  como backstop del índice único parcial (nunca deja escapar un 500 por doble apertura
  concurrente). Genera automáticamente 1 `cash_movement` tipo `opening_fund` (EFECTIVO,
  `direction=in`) por apertura. `close()` acepta `cash_session_id` opcional — si coincide
  con la sesión propia del usuario se trata como el flujo normal; si pertenece a otro
  cajero, exige el permiso `cash.close_others_session` (403 explícito, no 422, si falta).
  `blind_close` se resuelve de verdad (valor propio de la caja si no es `null`, si no hereda
  `companies.blind_close_default`) — expuesto como `blind_close_resolved` en la respuesta.
  - **Gap real encontrado a mitad de Fase 2, corregido antes de escribir el controller**: el
    plan original (Fase 0, §3) ya anticipaba `payment_methods.affects_cash_count` para saber
    qué métodos cuentan como efectivo físico en el arqueo — pero el prompt de Fase 1
    (redactado por el usuario) lo omitió. Se agregó vía migración propia (sin tocar la
    migración de Fase 0 ya corrida), con el backfill en `PaymentMethodSeeder` (no un
    `UPDATE` suelto) — EFECTIVO=true, los otros 4 explícitamente false.
  - **Segundo gap encontrado durante la implementación**: `expected_cash` solo se
    calculaba/persistía dentro de `close()` — el modo "no ciego" necesita mostrarlo ANTES de
    confirmar, y no había forma de previsualizarlo. Se extrajo `computeExpectedCash()`
    compartida y se agregó `expected_cash_live` (no persistido) a la respuesta de `status`,
    que de paso ya cubre el "corte X en vivo" del plan sin pantalla aparte.
  - **Tercer gap**: 3 usos de `Company::first()->campo` sin null-safe — un tenant recién
    provisionado sin fila en `companies` todavía (paso separado de `tenants:provision`, ver
    `ProvisionTenant.php`) habría producido un 500 no controlado. Corregido con `?->` en los
    3 puntos.
  - Los 5 puntos de verificación (apertura con ajuste de fondo, doble apertura bloqueada,
    `blind_close` ciego/no-ciego confirmado en ambas direcciones, tolerancia de diferencia
    con/sin motivo, cierre por terceros con/sin permiso) verificados con evidencia real (API
    directa, 2 usuarios reales) contra `sandbox` — no solo lectura de código.
  - **Estado de prueba que quedó en `sandbox`** (no revertido, mismo criterio que los datos
    de prueba de Fase 0): usuario `cajero.test@sandbox.local` (id=20, rol `Cajero`) con
    permiso `cash.close_others_session` asignado **directamente al usuario** (no al rol —
    ese permiso es de supervisor/emergencia, no algo que un cajero normal deba tener sobre
    sus compañeros; no existe todavía un rol Admin/Supervisor genérico en el proyecto). Rol
    `Cajero` real solo tiene `cash.open_session`. `branch #1 "Sede Principal"` +
    `cash_register #1 "Caja 1"` (`default_opening_amount=100`) creados vía comando puntual
    `cash:seed-sandbox-demo` (no es parte de `tenants:provision`).
- **Fase 3 — Integración con ventas (2026-07-18)**: conecta `SaleController::store()`/
  `update()` con Caja. Cambio 100% aditivo — no tocó `validarRegimenEspecial()`,
  `validarConfiguracionCredito()`, el formato de `sales.payment_method`/`sale_payments`, ni
  el envío a SUNAT, tal como exigía el prompt de esta fase por ser código fiscal sensible.
  - **Guard en `store()`** (`resolverSesionCajaAbierta()`): dispara si `payments[]` trae al
    menos un monto > 0 — no mira `type_payment` directamente, así que el pago inicial de una
    venta a crédito (`type_payment==2` con `payments` no vacío) también exige caja abierta,
    mientras que una venta 100% a crédito sin pago inicial (`payments` vacío) nunca la exige
    (regla de integridad #3 del plan: el financiamiento no genera `cash_movement` al momento
    de la venta). Sin excepción está el resultado de investigar primero, no de asumir:
    `sales` no tiene columna `channel` ni nada equivalente — el portal e-commerce no pasa
    por `SaleController` en absoluto, usa `Order`/`OrderController` (tabla `orders`)
    completamente separado, confirmado por grep. El guard aplica a **todo** `store()`/
    `update()` sin condición de canal porque no hay otro canal llegando ahí hoy.
    (`plan-modulo-caja.md` §7 asume `sales.channel = ecommerce` — dato viejo del diseño
    original que no corresponde al código real, pendiente de corregir en el plan.)
  - **Generación automática**: por cada pago inmediato se crea un `cash_movement` tipo
    `sale_payment` (soporta pago mixto — una fila por método), dentro de la misma
    transacción que la venta — nunca queda una venta persistida sin su movimiento.
  - **Sincronización en `update()` — la parte delicada de esta fase**: confirmado en el
    código real que los pagos de una venta SÍ son editables desde `update()`
    (`SalePayment::where(...)->delete()` + recreación total, sin condición, cada vez que se
    edita una venta pre-SUNAT) — esto choca directo con la regla de integridad #1 de Caja
    ("un `cash_movement` nunca se edita ni se borra a nivel de dato"). Resuelto exactamente
    como pide esa regla: si los pagos cambian, se generan `cash_movement` tipo `correction`
    (monto igual, dirección invertida, `corrected_movement_id` → el original) más un
    `sale_payment` nuevo con el dato correcto — el original nunca cambia su contenido, solo
    queda anotado (`corrected_by`/`corrected_at`). Si la sesión donde vive el movimiento
    original sigue `open`, la corrección es libre; si ya `closed` (el arqueo de ese día ya
    se hizo), exige el permiso `cash.close_others_session` (403 si no lo tiene) — mismo
    criterio que el cierre de sesión por terceros de Fase 2, tal como especifica la regla #1
    del plan ("requiere permiso de supervisor", no una prohibición absoluta). La corrección
    y/o los pagos nuevos siempre se registran en la sesión abierta ACTUAL de quien edita,
    nunca en la sesión vieja aunque siga abierta y sea la misma — mismo criterio que la
    regla #2 del plan para reembolsos de NC, extendido por consistencia.
  - **Gap real evitado antes de escribir código**: el `catch()` de `update()` es un solo
    `catch(\Throwable)` que no distingue `HttpException` de un error real — cualquier 422/403
    lanzado dentro de la transacción se hubiera convertido en un 500 silencioso al hacer
    rollback. Se resolvió con un split validación/ejecución: `prepararSincronizacionCaja()`
    corre ANTES de `DB::beginTransaction()` (toda decisión, incluido el chequeo de permiso,
    puede lanzar `HttpException` ahí con seguridad) y `aplicarSincronizacionCaja()` corre
    DENTRO de la transacción solo para ejecutar el plan ya decidido, sin volver a validar.
  - **Nota de Crédito (Paso 5 del prompt, exploratorio)**: confirmado que el módulo de NC no
    genera ningún reembolso de dinero real todavía — lo único que toca plata hoy es
    `AdvanceRefund` (módulo Adelantos, separado), que solo actualiza
    `advances.refunded_amount` como bookkeeping. Nada que enganchar en esta fase; la regla
    de integridad #6 (reembolso de NC usa el `payment_method_id` original de la venta, se
    ata a la sesión abierta actual) queda anotada para cuando NC↔Caja se conecte de verdad.
  - **6 puntos de verificación** (los 5 originales del checklist + un 6to agregado por el
    usuario específicamente para el caso nuevo de corrección sobre sesión cerrada)
    verificados con evidencia real (API directa + consultas a BD) contra `sandbox`: venta
    contado sin caja → 422 sin fila creada; venta contado con caja y pago mixto → venta +
    2 `cash_movements` exactos; venta 100% crédito sin caja → se crea, 0 `cash_movements`;
    crédito con pago inicial sin caja → 422; control cruzado sesión-vs-venta (el monto
    migra de sesión al corregirse, sin perderse ni duplicarse); `update()` sobre sesión
    cerrada sin permiso → 403, con permiso (Super-Admin) → 200 con `correction` + nuevo
    `sale_payment` generados correctamente y el contenido del movimiento original intacto
    (confirmado leyendo la fila después de la corrección). De paso, un intento de cierre con
    el monto equivocado confirmó que `affects_cash_count` sigue filtrando bien incluso
    dentro de una corrección (YAPE no cuenta para el arqueo físico, la diferencia reportada
    coincidió exactamente con lo esperado).
  - **Estado de prueba que quedó en `sandbox`**: usuario nuevo `cajero2.test@sandbox.local`
    (id=21, rol `Cajero`, sin `cash.close_others_session` — para poder probar el caso "sin
    permiso" sin tocar el permiso de `cajero.test`). Ventas reales #29 (contado, pago mixto,
    editada) y #30 (100% crédito) quedaron persistidas. Todas las sesiones de prueba se
    cerraron correctamente al final — `sandbox` queda con 0 sesiones abiertas.
- **Fase 4 — Movimientos manuales (2026-07-19)**: ingresos/egresos manuales de caja
  (comisiones, cobro de deudas, pagos a proveedores, caja chica), reutilizando explícitamente
  el patrón de corrección de Fase 3 (`type: correction`, `corrected_movement_id`,
  `corrected_by`/`corrected_at` — regla de integridad #1), no un mecanismo nuevo.
  - **Refactor previo, pedido explícitamente por el usuario antes de escribir código nuevo**:
    `computeExpectedCash()` vivía como método privado de `CashSessionController` (Fase 2) —
    se extrajo a `app/Services/ExpectedCashCalculator.php` (mismo criterio que
    `CreditSummaryCalculator`: "un solo punto de verdad, no dos que empiecen iguales").
    `CashSessionController::close()`/`serializeSession()` ahora llaman al service, el método
    privado viejo se eliminó por completo. De paso se le agregó `where('status','confirmed')`
    — la versión original no filtraba por status porque `pending_approval`/`rejected` no
    existían todavía en la práctica.
  - **`CashMovementController` nuevo** (decisión explícita: controller propio, no meter todo
    en `CashSessionController` — mismo criterio de un-controller-por-recurso que el resto del
    módulo). `store()`: exige sesión abierta propia, `concept_id` activo con `direction`
    coincidente con `type` (422 si no), `description` obligatoria, contraparte con snapshot
    real (`counterparty_id` presente → el nombre/documento SIEMPRE se resuelve de
    `Client`/`Supplier` real, ignorando lo que haya llegado en
    `counterparty_name`/`counterparty_document` del payload — evita que sea un campo de texto
    libre disfrazado). Aprobación condicional (`companies.require_expense_approval` +
    `max_expense_without_approval`) solo para egresos, vía `status: pending_approval` —
    la validación de "no negativo" (regla #4) se salta al crear un pendiente y se re-ejercita
    recién al aprobar.
  - **Adjunto real, no placeholder**: confirmado por grep que el proyecto ya tiene un patrón
    de subida de archivos repetido en 5 controladores (`Storage::disk('public')->putFile(...)`)
    — se reutilizó igual para `attachment_path`, no se dejó deshabilitado.
  - **Buscador de contraparte**: mismo patrón ya existente de `NotaElectronicaController::
    buscarVenta()` (debounce + `ILIKE` + `limit(15)`), replicado como
    `GET cash/counterparty-search` contra `clients`/`suppliers` según `type`.
  - **Editar/eliminar reusa el patrón de Fase 3, adaptado**: `prepararCorreccion()` (antes de
    abrir transacción, puede lanzar 403/422 con seguridad) + `registrarCorreccion()` (dentro
    de la transacción, solo ejecuta). Restringido a `manual_income`/`manual_expense` — un
    intento de corregir `sale_payment`, `opening_fund` o un `correction` ya existente devuelve
    422 explícito. Sobre sesión cerrada exige `cash.close_others_session` (mismo permiso
    reutilizado, sin crear uno nuevo), igual criterio que Fase 3.
  - **Validación de "no negativo" generalizada** (`validarNoNegativo()`, un solo helper): la
    misma función cubre `store()` (efecto de un movimiento nuevo), `approve()` (efecto del
    movimiento pendiente al confirmarse), `update()` (efecto combinado: reversión del
    original + el movimiento nuevo) y `destroy()` (efecto de la reversión sola) — simulando
    el proyectado ANTES de persistir nada, en vez de cuatro validaciones distintas.
  - **Bug real encontrado y corregido en el camino, no relacionado con el código de esta
    fase pero descubierto al probarla**: `Company::$fillable` nunca había incluido los 6
    campos de configuración de Caja agregados en Fase 1 (`require_expense_approval`,
    `max_expense_without_approval`, `blind_close_default`, etc.) — `Company::update()` los
    descartaba en silencio por protección de asignación masiva de Eloquent (sin excepción,
    sin log). Nadie lo había notado porque hasta Fase 4 esos campos solo se habían LEÍDO,
    nunca escrito vía el modelo. Confirmado con `var_dump()` antes (`false`/`NULL`) y después
    (`true`/`"100.00"`) del fix. Corregido agregando los 6 nombres a `$fillable` — ningún
    otro mecanismo. La primera corrida del checklist de 8 puntos falló en los puntos 4/5
    exactamente por este bug (eso fue lo que lo delató); se descartó por completo y se
    re-ejecutaron los 8 puntos desde cero después del fix, confirmado por `var_dump` que la
    configuración real ya persistía — ningún resultado reportado como válido mezcla datos
    de antes/después del fix.
  - **Permiso nuevo** `cash.approve_expenses` (mismo criterio dot-notation que el resto de
    Caja), sin asignar a ningún rol por defecto — se asignó directamente al usuario de prueba
    `cajero.test@sandbox.local`, junto con `cash.close_others_session` que ya tenía.
  - **8 puntos de verificación** confirmados con evidencia real (API + BD) contra `sandbox`:
    contraparte cliente real (snapshot correcto, ignoró datos falsos enviados a propósito en
    el payload) y proveedor no catalogado (`counterparty_id=null`, texto tal cual);
    `concept_id` con `direction` no coincidente → 422; egreso sobre el umbral → queda
    `pending_approval` sin tocar `expected_cash_live`; aprobarlo lo confirma y recién ahí
    impacta el cálculo; editar con sesión abierta corrige sin tocar el original; eliminar
    sobre sesión cerrada sin permiso → 403, con permiso → corrección en la sesión abierta
    ACTUAL del que corrige (confirmado en BD: el original nunca cambió su contenido, solo
    quedó anotado `corrected_by`); egreso directo y egreso pendiente que dejarían el efectivo
    en negativo → 422 en ambos casos (creación y aprobación, respectivamente).
- **Fase 5 — Reportes (2026-07-19)**: historial de sesiones con filtros y paginación
  (`CashSessionController::index()`), detalle de solo lectura (`show()`), dashboard admin
  con alerta de sesión abierta >24h (`dashboard()`), PDF de cierre (individual, soporta
  sesión cerrada real o vista previa de una abierta vía `expected_cash_live`; y rango
  consolidado, máx. 31 días con 422 explícito si se excede) y export Excel de movimientos —
  primera vez que el proyecto usa una librería de Excel real
  (`maatwebsite/laravel-excel`/`phpoffice/phpspreadsheet`, requirió habilitar la extensión
  `ext-zip` de PHP —deshabilitada por defecto en este XAMPP— y reiniciar Apache antes de
  poder instalarla).
  - **`CashVisibilityResolver`** (servicio nuevo): sin `cash.view_all`, el filtro
    `opened_by` que pida un usuario por otro cajero se **ignora en silencio** (nunca 403 —
    es un listado, no un recurso puntual) y se fuerza su propio user_id; con el permiso, se
    respeta lo pedido. Usado en `index()`/`pdfRangeSignedUrl()`/`CashMovementController::
    export()`. `dashboard()` deliberadamente NO lo usa — está gateado binario por
    `permission:cash.view_all` a nivel de ruta (tienes el permiso o ni entras), distinto a
    un listado filtrable parcial.
  - **Bug real encontrado y corregido, ajeno al código nuevo de esta fase**:
    `CashSessionController::serializeSession()` (existente desde Fase 2) armaba
    `totals_by_payment_method` agrupando TODOS los `cash_movements` de la sesión sin
    filtrar `status='confirmed'` — a diferencia de `ExpectedCashCalculator`, que sí lo hace
    desde Fase 4. Rastreado el impacto hacia atrás explícitamente (mismo criterio que
    cualquier fix sobre código ya verificado): sin impacto en los checklists de Fases 2/3
    (los estados `pending_approval`/`rejected` no existían todavía en el sistema en esos
    momentos); posible impacto de solo *visualización* — nunca de datos persistidos ni de
    `expected_cash`, que siempre calculó bien — en el punto del checklist de Fase 4 que
    verificaba que un egreso `pending_approval` no tocara el efectivo esperado (esa
    aserción sigue siendo correcta; no hay evidencia de que se haya inspeccionado
    específicamente `totals_by_payment_method` en ese momento).
  - **Corrección sobre una decisión propia tomada y revertida en la misma fase**: el plan
    original para los filtros de sede/caja de `history.vue` era derivarlos de las sesiones
    ya cargadas en pantalla (sin endpoint nuevo) — el usuario señaló que esto dejaría
    sedes/cajas sin sesiones invisibles en el filtro ("no encuentro mi sede"), así que se
    corrigió antes de cerrar la fase: `BranchController`/`CashRegisterController::index()`
    nuevos (solo listado `?active=1`, mismo patrón que `PaymentMethodController` — no es el
    CRUD completo, que sigue pendiente), con rutas `GET branches`/`GET cash-registers`. El
    filtro de *cajero* sí quedó derivado de las sesiones cargadas (alcance más acotado,
    aceptado explícitamente) — ver pendientes abajo.
  - **`stores/auth.ts::isPermitedRoute()`** extendido para aceptar `"a|b"` (OR de permisos,
    mismo criterio que el middleware `permission:` de Spatie en el backend) — necesario
    para que el historial sea alcanzable tanto por `cash.open_session` como por
    `cash.view_all`. Verificado con 17 casos aislados en un script Node reproduciendo la
    función exacta (parseo OR, permiso simple sin pipe sigue igual, `'all'` sigue
    universal, Super-Admin sigue bypasseando todo, strings vacíos/mal formados —
    `""`/`"undefined"`/pipes dobles o en los extremos— no abren ninguna brecha) — sin
    regresión, y confirmado por grep que ningún `permission:` anterior a esta fase usaba
    `|` (sin colisión de semántica con rutas viejas).
  - **Hallazgo real y significativo, fuera de alcance de Caja, encontrado al preparar el
    checklist de esta fase**: `AuthController::respondWithToken()` (el login) arma
    `permissions` desde `auth('api')->user()->role->permissions` — la relación **legacy**
    `role_id` (singular), no `getAllPermissions()` de Spatie (que mezcla permisos del rol +
    asignados directamente al usuario). El backend (middleware `permission:` de Spatie,
    todo `$user->can(...)`) sí usa `getAllPermissions()` correctamente — la seguridad del
    servidor nunca estuvo comprometida — pero el **frontend** (`isPermitedRoute()`, menú,
    guard de rutas) nunca ve un permiso asignado directamente a un usuario, patrón que este
    proyecto usa repetidamente (los 4 permisos de crédito, `cash.close_others_session`,
    `cash.approve_expenses`). Confirmado con evidencia real (login de `cajero.test` vía API
    real devolviendo `"permissions": []` de más). **Decisión explícita: documentar, no
    corregir en esta sesión** — el fix es chico (`getAllPermissions()->pluck('name')` en
    vez de `$role->permissions->pluck('name')`) pero su blast radius es el login de
    absolutamente todos los usuarios de todos los tenants. Anotado en
    `plan-modulo-caja.md` §12. **Separado, artefacto de datos ya corregido** (no es bug de
    código): `cajero.test`/`cajero2.test` en `sandbox` tenían `users.role_id = 1`
    ("Super-Admin") en vez de 5 ("Cajero", su rol Spatie real) — causado por haberse creado
    vía tinker en una fase anterior sin pasar por `UserController::store()` (que sí
    sincroniza `role_id` con el rol real). Verificado que usuarios reales de `umbo`
    (`umbosac@gmail.com`, `pinedo@gmail.com`, creados por el flujo normal) sí tienen
    `role_id` correctamente sincronizado — no es un problema del código de
    `UserController`, exclusivo de estos 2 fixtures. Corregido con `UPDATE` directo.
  - Menú "Caja" convertido de ítem único a padre con 2 hijos: "Turno Activo"
    (`cash.open_session` → `cash.session`) y "Historial y Reportes" (`cash.view_all` →
    `cash.dashboard`) — no son excluyentes, un usuario con ambos ve las dos entradas.
  - **7 puntos de verificación** confirmados con evidencia real (API + BD + PDF real
    extraído con `pdftotext` + Excel real parseado con `PhpSpreadsheet`) contra `sandbox`,
    usando 3 usuarios de prueba reales (`cajero.test`/`cajero2.test`/`supervisor.test`, este
    último nuevo, rol propio `Supervisor Caja (test)` con solo `cash.view_all` atado al ROL
    — no al usuario, precisamente para poder probar el frontend pese al bug de
    `respondWithToken()` de arriba): filtro `opened_by` ajeno ignorado sin 403 (verificado
    por conteo exacto: 6 sesiones propias, ninguna ajena); `cash.view_all` ve las 12
    sesiones totales y el filtro por cajero sí se respeta cuando lo pide; PDF de rango >31
    días → 422 con mensaje exacto; PDF dentro del límite generado y su total (S/ 90.00)
    coincidiendo exacto con la suma directa en BD; Excel exportado con las mismas 6 filas
    exactas (mismos IDs) que los `cash_movements` reales de esas sesiones; dashboard marcó
    correctamente `is_stale=TRUE`/`elapsed_hours≈25` en una sesión forjada a +25h
    (`opened_at` restaurado a su valor real antes de cerrarla vía API normal — `sandbox`
    quedó en 0 sesiones abiertas); acceso a `cash/dashboard` (200) vs `cash/status` (403)
    para el usuario solo-`view_all` confirma a nivel de ruta la bifurcación del menú.
  - `composer audit`: 41 advisories en el árbol completo de dependencias, pero **ninguno**
    en los 8 paquetes nuevos que trajo `maatwebsite/excel`
    (`maatwebsite/excel`, `phpoffice/phpspreadsheet`, `maennchen/zipstream-php`,
    `markbaker/complex`, `markbaker/matrix`, `ezyang/htmlpurifier`, `composer/pcre`,
    `composer/semver`) — el resto son dependencias preexistentes del proyecto, no auditadas
    a fondo (fuera de alcance de esta fase, a pedido explícito).

- **Fase 6 — Integración con Adelantos y Amortizaciones (2026-07-19)**: conecta dos flujos
  de cobro que hasta esta fase no generaban ningún movimiento de caja. Paso previo
  obligatorio (confirmación de estado real, no asumido): ambos módulos ya existían y
  estaban maduros — `AdvanceController::store()` (adelantos) crea `Sale`/`SaleDetail`/
  `SalePayment`/`Advance` directo, sin pasar por `SaleController`, así que bypaseaba el
  guard de Caja por completo; `CreditPaymentController::store()` (cobro de cuotas) arma
  **un solo `PaymentReceipt` por llamada que puede aplicar a múltiples cuotas/ventas a la
  vez** (un `medio_pago` + un `monto_total`, no 1 pago = 1 cuota) — detalle real que el
  pseudocódigo original del plan no contemplaba, encontrado leyendo el código antes de
  escribir nada.
  - **`advances/create.vue`** migrado de `<option>` hardcodeado a `GET
    payment-methods?active=1` (mismo patrón que `register.vue` desde Fase 0) — resuelve la
    inconsistencia ya documentada en Fase 0 (`TARJETA`→`TARJETA DE CREDITO`, Yape/Plin ya
    no fusionados, Plin como opción independiente). Verificado antes de tocar nada: **0
    registros históricos afectados** — los 3 adelantos reales del tenant `umbo` ya usaban
    `EFECTIVO` (valor no roto) — fix puramente hacia adelante, sin ninguna migración/
    normalización de datos existentes.
  - **`AdvanceController::store()`**: guard de sesión abierta incondicional (un adelanto
    siempre se cobra al recibirse, a diferencia de una venta) + validación de
    `payment_method` contra `payment_methods.is_active`, ambos **antes** de
    `DB::beginTransaction()` — necesario porque el `catch(\Throwable)` de este método
    convierte cualquier excepción en un 500 genérico si se lanza dentro de la transacción.
    Genera `cash_movement` tipo `advance_received`. Confirmado (lectura de código, no
    asumido) que aplicar un adelanto ya recibido a una venta futura
    (`SaleController::store()`, líneas del bloque `AdvanceApplication::create()`) **no**
    genera ningún movimiento nuevo — ya era así antes de tocar nada, coherente con el plan.
  - **`CreditPaymentController::store()`**: mismo guard incondicional. Decisión de diseño
    explícita (recomendada y confirmada por el usuario): **un solo `cash_movement` tipo
    `installment_payment` por `PaymentReceipt` completo**, no uno por cuota/aplicación —
    `amount = monto_total` del recibo entero (incluye lo que quedó como
    `saldo_a_favor`/`monto_no_aplicado`, porque ese dinero también entró físicamente a la
    caja), `reference_type='payment_receipt'`.
  - **`CreditPaymentController::anular()`** conectado a Caja — generaba reversión de
    `saldo_pendiente`/estado de cuotas/`saldo_a_favor` pero no tocaba caja.
  - **`CashCorrectionService` extraído** (`app/Services/`, nuevo — mismo criterio que
    `ExpectedCashCalculator` en Fase 5, "un solo punto de verdad"): al conectar
    `anular()`, se encontró el mismo patrón de corrección ("generar `correction` que
    revierte un movimiento + gate `cash.close_others_session` si la sesión cerró")
    replicado 3 veces (`SaleController::aplicarSincronizacionCaja()` Fase 3,
    `CashMovementController::registrarCorreccion()` Fase 4, y la implementación recién
    escrita en `CreditPaymentController`). **Convención unificada, decisión explícita**: la
    fila de corrección usa `reference_type='cash_movement'`/`reference_id=<id del
    original>` (apunta a QUÉ anula, no al recurso de negocio que lo originó — ese recurso
    sigue siendo recuperable navegando desde el original, que sí conserva su propio
    `reference_type`/`reference_id` de negocio) — 2 de 3 implementaciones ya lo hacían así;
    se ajustó `SaleController` (antes usaba `reference_type='sale'`/`venta.id` en sus
    correcciones). **Impacto rastreado hacia atrás antes de dar la extracción por
    cerrada** (mismo criterio que el fix de `totals_by_payment_method`): el checklist de 6
    puntos de Fase 3 no afirma nada sobre ese campo (solo conteos, montos que migran de
    sesión, y "contenido del original intacto") — sin regresión. Cambio aditivo sin riesgo:
    las correcciones de `SaleController` ahora también llevan `description`/`concept_id`
    (antes quedaban `null`, sin setear).
  - **`CreditPaymentController::refund()` (§3.12, liquidación de devolución de venta
    anulada por NC) queda explícitamente fuera de alcance de esta fase** — decisión del
    usuario, con evidencia concreta de por qué: es el mismo límite NC↔Caja ya anotado como
    pendiente en Fase 3 (regla de integridad #6). `medio_devolucion` es texto libre sin
    ningún cruce contra el/los método(s) de pago real(es) de la venta (que puede ser mixto,
    de varios `payment_receipts` con `medio_pago` distintos), y el propio código documenta
    que ese campo captura un hecho **ya ocurrido** — el dinero pudo haber salido por un
    canal que nunca tocó la caja física (ej. transferencia bancaria directa). Documentado
    en `plan-modulo-caja.md` §12.
  - **6 puntos de verificación + 1 adicional**, todos con evidencia real contra `sandbox`:
    adelanto sin sesión → 422 (0 `advances` creados, confirmado); adelanto con sesión →
    creado + `cash_movement` exacto (`advance_received`, EFECTIVO, `in`, 50.00); aplicar el
    adelanto a una venta nueva → `cash_movements` sin cambio (36→36 exacto) aunque
    `AdvanceApplication`/`applied_amount`/`status` sí se actualizaron (requirió setear
    `n_operacion` de prueba en la venta del propio adelanto para destrabar la precondición
    SUNAT — sin enviar nada real a SUNAT, solo para no bloquear la prueba de Caja); cuota
    sin sesión → 422 (recibo existente confirmado de fecha muy anterior, 2026-07-15, sin
    incremento); cuota con sesión → recibo `REC-00002` + `cash_movement` exacto
    (`installment_payment`, 3.33); adelantos con YAPE/PLIN/TARJETA DE CREDITO → 3
    `payment_method_id` distintos (no fusionados), y el valor viejo roto `"TARJETA"` ahora
    se **rechaza** con 422; `anular()` del recibo → fila de corrección con
    `reference_type='cash_movement'`/`reference_id=<original>` (confirmado que el original
    mantiene su propio `reference_type='payment_receipt'` sin cambios — la distinción entre
    ambos quedó explícita) — requirió otorgar `anular-pago-credito` a `cajero.test`
    (permiso de Amortizaciones que no tenía, no relacionado a Caja). `sandbox` cerrado con 0
    sesiones abiertas.
  - Estado de prueba que quedó en `sandbox`: `cajero.test` con `anular-pago-credito` sumado
    a sus permisos previos; 4 `advances`, 2 `payment_receipts` (uno anulado); ventas #31,
    #33-36 (adelantos + 1 venta real aplicando un adelanto).

- **Pendiente (Fase 7 del plan — multi-caja simultánea)**: sin fecha de activación, espera
  a que el negocio abra efectivamente una segunda caja simultánea en alguna sede —
  checklist completo de qué hacer al activarla en `plan-modulo-caja.md`, sección Fase 7
  (no requiere migraciones nuevas, el diseño ya lo soporta desde Fase 1). Deuda técnica
  relacionada, sin bloquear ninguna fase: CRUD administrable completo de
  `branches`/`cash_registers` (hoy solo existe listado de solo lectura, Fase 5); bug de
  `AuthController::respondWithToken()` (permisos directos de usuario no llegan al
  frontend, ver Fase 5); filtro de cajero en `history.vue` derivado de sesiones cargadas en
  vez de un catálogo real de usuarios (ver Fase 5).

**Completo — Módulo de series de comprobantes, con Nota de Venta interna (2026-07-19):**
Diseño completo en `plan-modulo-series-comprobantes.md` (raíz del repo, incluye esquema de
tablas, decisiones de diseño y los incidentes reales encontrados en el camino).
- Resuelve de raíz el bug de concurrencia real de `reservarCorrelativo()` (serie nueva sin
  fila previa → dos requests podían calcular ambos `correlativo=1`): ahora existe
  `serie_comprobantes` (tenant, por sucursal), con fila semilla `correlativo_actual=0` creada
  explícitamente ANTES de que exista cualquier venta, y el `lockForUpdate()` bloquea esa fila,
  no `MAX(sales.correlativo)`. Ventas creadas ANTES de este módulo (sin
  `serie_comprobante_id`, sin backfill) caen a un fallback que preserva el mecanismo viejo
  intacto — nunca se tocaron datos existentes.
- **Catálogo `tipos_comprobante`** (central, mismo criterio que `note_motivos`/
  `detraction_codes`/`tax_configs` — catálogo legal idéntico para todos los tenants):
  Catálogo 01 SUNAT sembrado SOLO del 00 al 14 (los códigos confirmados explícitamente) más
  `NV` (nota de venta, interno). **Pendiente, no adivinado a propósito**: completar el
  Catálogo 01 real del 15 en adelante (espectáculos públicos, retenciones, etc.) — se dejó
  fuera por no tener certeza carácter por carácter de una tabla de referencia legal.
  `activo_greenter=true` solo en `01`/`03`/`07`/`08` (únicos `setTipoDoc()` reales en
  `GreenterService`, confirmado por grep). Invariante `activo_greenter → es_documento_sunat`
  forzada con un CHECK real de Postgres, no solo el seeder.
- **Nota de venta**: tipo de documento interno y terminal (no se convierte en factura/boleta
  después) para productos/servicios sin sustento de compra — mueve stock/kardex y participa
  del circuito de crédito igual que cualquier venta, pero **nunca** pasa por `enviarSunat()`
  (guard por `es_documento_sunat`, no por `activo_greenter` — son campos distintos con
  distinto propósito). Como nunca tiene un paso de "envío" posterior, reserva su correlativo
  **de inmediato** en `SaleController::store()` (a diferencia de factura/boleta, que lo
  siguen reservando recién en `enviarSunat()`, sin cambios — evita quemar un correlativo
  SUNAT real en un borrador nunca enviado). Nunca setea `n_operacion` (exclusivo del envío
  SUNAT real).
- **Sucursal por usuario, no por venta**: `users.branch_id` (fijo, editable desde el form de
  usuarios ya existente) determina qué series ve `register.vue`/`edit.vue`, salvo que el
  usuario tenga el permiso nuevo `can_switch_branch` (independiente de rol, mismo criterio que
  `cash.close_others_session` — para un cajero de confianza que cubre más de una sucursal). Con
  una sola sucursal hoy, este mecanismo no se nota en la práctica.
- **Permisos de emisión por tipo de documento** (`emitir_factura`/`emitir_boleta`/
  `emitir_nota_venta`, sin entrada para `07`/`08` — esos se emiten desde
  `NotaElectronicaController`, flujo separado que este módulo no toca): filtran qué opciones
  ve cada usuario en el selector de tipo de documento Y se validan de nuevo en
  `SaleController::store()` (422 si no coincide) — nunca se confía solo en que el frontend
  filtre correctamente.
- **Editar el tipo de documento de una venta ya creada** (`SaleController::update()`,
  decisión explícita del usuario tras encontrar que `update()` nunca se había conectado al
  mecanismo nuevo): permitido con las mismas reglas que `store()` **mientras la venta no
  tenga un correlativo reservado** (`correlativo === null` — mismo momento exacto para
  fiscales, recién en `enviarSunat()`, y para NV, inmediato en `store()`). Con correlativo ya
  reservado, cambiar de tipo se bloquea con 422 explícito; el frontend refleja el mismo
  candado (`:disabled` en el selector) usando `tipo_comprobante_codigo`/`correlativo`, ambos
  agregados a `SaleResource`.
- **Bug real encontrado y corregido antes de que causara daño** (mismo patrón que el gap de
  `Company::$fillable` en Caja Fase 4): `Sale::$fillable` nunca incluyó
  `tipo_comprobante_codigo`/`serie_comprobante_id` — de haberse dejado así, `Sale::create()`/
  `update()` los habría descartado en silencio por protección de asignación masiva. Detectado
  releyendo el modelo antes de escribir los tests, no por un test que fallara.
- **CRUD de series** (`SerieComprobanteController`/`TipoComprobanteController`, sin gate de
  permiso a nivel de ruta — mismo criterio, si acaso imperfecto, ya establecido por
  `payment-methods`/`branches`): no permite editar `branch_id`/`tipo_comprobante_codigo`/
  `moneda`/`serie` una vez que la serie tiene `correlativo_actual > 0` (rompería
  trazabilidad) — solo activar/desactivar. Sin borrado real, mismo patrón que
  `PaymentMethodController`. Frontend en `views/series-comprobante/index.vue`, mismo estilo
  Bootstrap5/Rizz que el resto de catálogos de Caja.
- **Reportes/PLE**: no existe todavía ningún reporte SUNAT/PLE construido en el sistema — se
  dejó la regla estable para cuando se construya, como scope real y reusable
  (`Sale::scopeSoloDocumentosFiscales()`), no solo un comentario. Nota de venta SÍ debe
  seguir incluida en cierre de caja/kardex/listado interno — el scope es exclusivo para el
  futuro reporte fiscal.
- **Scope fuera de esta sesión, confirmado explícitamente con el usuario**: NC/ND (`07`/`08`)
  siguen usando el mecanismo viejo de `note_series`/`SerieNotaResolver` — que ya anticipaba
  en su propio comentario ("el usuario va a construir más adelante un módulo de series
  personalizables") que este módulo nuevo terminaría reemplazándolo. No se tocó en esta
  sesión; `tipos_comprobante` ya tiene `07`/`08` sembrados con `activo_greenter=true` para
  cuando se aborde esa migración.
- **Verificado con Postgres real** (`sistemafe_test_migrations`, nunca contra `sv_facturacion`/
  `sandbox`/`umbo`): las 7 migraciones nuevas corridas, verificado rollback limpio de cada una
  y vuelto a aplicar; 48 tests (29 nuevos de este módulo + 19 preexistentes, cero
  regresiones) — catálogo + invariante CHECK, `SerieComprobanteService` (resolución,
  reserva secuencial, unique constraint, lock real entre dos conexiones Postgres sobre una
  serie NV), y `SaleController::store()` de punta a punta (permiso/sucursal, venta NV a
  crédito con stock descontado + cronograma generado + correlativo inmediato + cero
  `n_operacion`). `npm run type-check` sin errores nuevos en ningún archivo tocado.
- **Gap real encontrado y cerrado el mismo día**: `AdvanceController::store()` (comprobante
  propio de un adelanto — no la venta futura que lo consume) creaba su `Sale` completamente
  al margen de este módulo, derivando `serie` con el mismo string hardcodeado de antes
  (`cod_tipo_doc_sunat === '6' ? 'F001' : 'B001'`) y sin tocar `tipo_comprobante_codigo`/
  `serie_comprobante_id` — confirmado por grep que `Sale::create()` solo se llama en dos
  lugares de todo el proyecto (`SaleController.php` y `AdvanceController.php`), así que no
  era un caso histórico: **todo adelanto nuevo** habría seguido cayendo en el fallback legado
  (mismo bug de concurrencia que este módulo existe para cerrar) indefinidamente.
  - Cerrado extrayendo la parte de resolución realmente compartida (sucursal +
    catálogo + serie activa) a `SerieComprobanteService::resolverParaUsuario()` — el permiso
    de emisión queda a cargo de cada llamador porque su semántica es distinta:
    `SaleController` valida el tipo que el usuario ELIGE en un `<select>`; `AdvanceController`
    no agrega ningún permiso nuevo (decisión explícita de alcance) porque el tipo se DERIVA
    del cliente, exactamente igual que antes (`cod_tipo_doc_sunat === '6'` → factura, si no →
    boleta — misma condición, verificada carácter por carácter, solo cambia de producir un
    string de serie hardcodeado a resolver contra el catálogo real).
  - Guard explícito y defensivo en `AdvanceController::resolverSerieComprobanteAdelanto()`:
    un adelanto nunca puede resolver a un tipo no fiscal (`es_documento_sunat=false`) — el
    IGV ya nació al recibirse el pago. Estructuralmente inalcanzable hoy (la derivación solo
    produce `'01'`/`'03'`), pero protege contra un cambio futuro que lo permita por
    accidente.
  - **Bug real del propio refactor, encontrado por el test antes de mergear**: el nuevo
    camino de `FacturacionElectronicaController::reservarCorrelativo()` (cuando
    `serie_comprobante_id` está poblado) devolvía el correlativo ya incrementado en
    `serie_comprobantes.correlativo_actual` pero nunca lo escribía de vuelta en
    `sales.correlativo` — a diferencia del camino legado, que sí lo hacía. Como
    `enviarSunat()` nunca vuelve a tocar `correlativo` en su propio `$venta->update()` de
    éxito (confía en que `reservarCorrelativo()` ya lo dejó puesto — diseño original), esto
    habría dejado `sales.correlativo` en `NULL` para siempre en cualquier venta fiscal real
    enviada a SUNAT bajo el mecanismo nuevo, con `n_operacion` bien seteado (ese sí se arma
    desde la variable local, no desde la columna) — una corrupción silenciosa de datos que
    ningún flujo manual habría notado de inmediato. Corregido antes de tocar ningún tenant
    real: el test de adelanto (`test_correlativo_del_adelanto_se_reserva_via_servicio_nuevo_no_fallback`)
    lo detectó en la primera corrida.
  - 4 tests nuevos (`AdvanceControllerSerieComprobanteTest`): adelanto RUC → factura con
    `serie_comprobante_id` poblado y `correlativo` null (fiscal, diferido); adelanto no-RUC →
    boleta; `reservarCorrelativo()` real sobre la venta del adelanto confirma que usa el
    mecanismo nuevo (no el fallback); lock real de dos conexiones sobre la serie que usaría
    un adelanto, mismo patrón que los otros dos lock tests del módulo. 52/52 tests verdes en
    total, test DB confirmada limpia (0 filas) al final.

**Panel Superadmin (gestión central de tenants) — Fases 0/A/B/C completas, Fase D con scaffold
inicial (Paso 0), Fase E cerrada en su alcance actual (2026-07-20/21):**
- Diseño completo en `plan-panel-superadmin.md` (raíz del repo) — panel separado del panel de
  cada tenant (`admin-start-kit`), guard `central` propio, conexión `central`
  (`db_tenant_central`) consolidada en Fase B.0.5 junto con `tenants`/`domains`/catálogos
  SUNAT (`sv_facturacion` ya no cumple ningún rol de infraestructura, solo conserva datos
  históricos del negocio original pre-multitenant).
- **Fase 0/A**: provisioning (`tenants:provision` CLI + `TenantAdminController` HTTP, ambos
  sobre `TenantProvisioningService` compartido) crea Tenant/Domain/roles/usuario admin.
  **`Company`/`SunatConfig` quedan como 2 pasos manuales aparte a propósito** (Fase B.3), sin
  ningún checklist que los fuerce — origen del hallazgo de Fase E, Paso 0 (ver abajo).
- **Fase B.1/B.2/B.3**: `Company`/`SunatConfig`+certificado por tenant
  (`TenantSunatController`). **B.2 (Suscripciones y pagos)**: B.2.1-B.2.5 completas
  (migraciones, middleware de suspensión, invoices mensuales, pipeline de mora
  `tenants:check-overdue-payments`, gestión manual de pagos/vouchers); B.2.6 pospuesta
  (depende de una pantalla de detalle de tenant que no existe todavía, ver Fase D).
- **Fase C (Backups) — CERRADA**: backup manual + automático (`pg_dump -Fc`), restauración
  con fricción intencional (preview con token expirable → backup de seguridad obligatorio →
  restore in-place, nunca `DROP DATABASE`), verificación de integridad automática
  (`pg_restore --list`) + bajo demanda. Atomicidad probada contra Postgres real (fallo SQL a
  mitad de restore, `kill -9` del proceso).
- **Fase D (UI del panel) — Pasos 0-5 cerrados 2026-07-21**: proyecto Vue propio en
  `central-panel/` (hermano de `api-sistema-fe/`/`admin-start-kit/`, sin reusar el template
  Rizz) — Vite+Vue3+TS, Bootstrap 5 vanilla, Vue Router, Pinia, Axios, cero imports cruzados
  con `admin-start-kit`. Login real (`POST central/auth/login`), listado real de tenants
  (`GET central/tenants`), y **vista de detalle completa** (6 tabs: Company/Suscripción/
  Backups/SunatConfig/Certificado/Test-emission). Hallazgo que simplificó el diseño inicial:
  `Route::prefix('central')` (routes/api.php) no lleva middleware `tenant`, así que el
  hostname usado para llegar a `/api/central/*` es irrelevante para `stancl/tenancy` — no
  hizo falta ningún hostname dedicado ni cambio de `config/cors.php` (default
  `allowed_origins: ['*']`). **Hallazgo real del listado (Paso 1)**: existen 4 tenants, no
  2 — `sandbox`/`umbo` (los ya conocidos) más `negocio2` (activo) y `umbo-archivado`
  (archivado, remanente de la migración de Umbo). **2 gaps de backend cerrados al construir
  la vista de detalle (Paso 2)**, ambos aditivos/solo-lectura: `GET tenants/{id}/company`
  (no existía, solo el `POST` upsert) y eager-load de `vouchers` en
  `TenantSubscriptionController::show()` (la relación existía en el modelo, nunca se
  cargaba) — el segundo verificado con un ciclo de escritura completo (subir + verificar un
  voucher real) contra `sandbox`, que de paso quedó con un invoice real marcado `pagado`.
  **Paso 3 (vista global de Audit Logs)**: `CentralAuditLogController` nuevo (antes no
  existía ningún endpoint de auditoría) + `views/AuditLogsView.vue` con filtros por
  tenant/acción — 25 acciones reales confirmadas por grep de
  `$this->auditLogger->log(` en toda la app (más de las que ya se conocían). Filtro de
  tenant resuelto en el frontend (`auditable_type=App\Models\Tenant`+`auditable_id`, sin
  parámetro nuevo de backend) — limitación conocida: no cubre logs de sub-recursos
  (backup/invoice/restore/subscription), que no tienen `tenant_id` propio en
  `central_audit_logs`. **Paso 4 (alta de tenant por UI)**: gap señalado por el usuario —
  `POST central/tenants` existía desde Fase A pero nunca tuvo formulario (los 4 tenants
  reales de hoy se crearon todos por CLI/tinker). Cero cambios de backend; formulario
  inline en `TenantListView.vue`, verificado creando y destruyendo un tenant descartable
  real (`total` de `GET tenants` pasó de 4→5→4). **Paso 5**: a pedido del usuario, probado
  de punta a punta por primera vez el flujo completo crear→Company→SunatConfig→
  certificado→test-emission (10 llamadas encadenadas sobre el mismo tenant, incluido un
  `.pfx` autofirmado real y el gate de producción) — y agregados botones
  Archivar/Restaurar (wrappean los comandos CLI `tenants:archive`/`tenants:restore`,
  nunca tocan la base física — política "archivado, no borrado" por retención legal
  SUNAT) + Eliminar, deliberadamente estrecho (solo si el tenant nunca tuvo Company/
  SunatConfig/clientes/productos/ventas). **Bug real encontrado probando el propio
  método**: todo tenant nuevo trae 1 producto placeholder sembrado por migración
  (`ADELANTO-001`, módulo Adelantos) — sin excluirlo por SKU, el chequeo de "vacío"
  bloqueaba el borrado de cualquier tenant, siempre. Fase D queda cerrada en su alcance
  actual; falta decidir si `test-emission` se vuelve gate obligatorio (pregunta abierta de
  Fase E).
- **Fase E (verificación de emisión antes de habilitar producción) — cerrada en su alcance
  actual, Pasos 0/1/2 (2026-07-20/21)**:
  - **Paso 0 (auditoría)**: confirmó con evidencia real (no hipotética) que los 2 únicos
    tenants existentes hoy (`sandbox` y `umbo` — el negocio real) emiten con el certificado
    demo público de Greenter (`certificado_path=null` en ambos). Encontró además que
    `FacturacionElectronicaController::enviarSunat()` quemaba un correlativo real de SUNAT
    aunque faltara `Company` o `SunatConfig` activo, y que el catch que envolvía ese flujo
    siempre devolvía HTTP 200 aunque el error fuera un 422 real (getSee()), ocultándolo del
    código de estado real de la respuesta.
  - **Paso 1**: `Company`/`SunatConfig`+certificado ahora se validan ANTES de
    `reservarCorrelativo()` (antes: se quemaba el correlativo igual). El código HTTP real
    (422/500 según corresponda) ahora llega hasta la respuesta. El gate de
    `modo=produccion` sin certificado propio ya existía en
    `GreenterService::resolveCertificado()` sin cambios de código — solo dejó de quedar
    atrapado en el catch que lo convertía en 200.
  - **Paso 2**: `POST tenants/{id}/test-emission` (panel central, botón informativo) —
    confirma Company/SunatConfig/certificado sin reservar ningún correlativo ni tocar SUNAT,
    auditado siempre (éxito y fallo) en `central_audit_logs`. Conectividad de red real contra
    el WSDL de SUNAT (punto 4 del diseño original) investigada y descartada a propósito:
    Greenter expone una consulta real (`ConsultCdrService::getStatus()`), pero la librería
    solo trae la URL de ese servicio para producción (sin equivalente beta, el único modo
    real hoy) y una mala interpretación de su respuesta arriesgaba un falso positivo/negativo
    en una herramienta que un superadmin usaría como fuente de verdad — documentado como
    límite conocido, no implementado.
  - **Hallazgo downstream señalado, no corregido (no bloqueante)**: 2 vistas del frontend
    POS/admin (`sale/index.vue`, `advances/show.vue`) leen el mensaje de error plano
    (`error.response.data.message`) en su `catch` — funciona bien para los 3 guards nuevos,
    pero el catch de `getInvoice()`/`$see->send()` ahora puede traer un código no-200 con el
    mensaje anidado (`response.error.message`), perdiendo el detalle específico solo para ese
    caso puntual (fallos de red/construcción del comprobante, no el rechazo normal de SUNAT
    por regla de negocio, que sigue devolviendo 200 sin cambios). Pendiente para una sesión
    futura.
  - 34/34 tests verdes (`EnviarSunatValidacionPreCorrelativoTest`,
    `VerificarListoParaEmitirTest`, más los preexistentes de la misma cadena sin regresión),
    más verificación end-to-end real vía `tinker` contra un tenant descartable provisionado y
    destruido en la misma sesión (`central_audit_logs` confirmado con las 4 filas exactas,
    sin rastro sintético permanente).
  - **Pendiente, sin decidir**: ¿el endpoint `test-emission` se vuelve gate obligatorio antes
    de permitir `modo=produccion`, o sigue siendo solo informativo/opcional? Requiere decisión
    de negocio, no solo técnica.
- Ver `plan-panel-superadmin.md` para el detalle completo fase por fase (incluye hallazgos y
  bugs reales corregidos en el camino, ej. `Company::$fillable`/permisos de crédito no
  migrados, `CentralUser` sin `Authenticatable`, Carbon 3 `diffInDays()`).

**Próximos módulos (en orden de prioridad):**

1. **Representación impresa (PDF) con impresión automática**
   - Generación de PDF con **dos formatos** (A4 y ticket térmico 80mm) **ya implementada**,
     tanto para ventas (`sales-pdf-url/{id}`) como para notas (`notas-pdf-url/{id}`), con
     selector manual de formato al momento de imprimir
     (`usePrintComprobante.ts::imprimirComprobante()`/`imprimirNota()`).
   - **Sin confirmar:** si ya existe el "formato por defecto configurable por usuario/caja"
     persistido en algún lado, o si hoy el default es fijo — verificar antes de cerrar este
     ítem.
   - Método de impresión automática **silenciosa** (sin diálogo del navegador): **pendiente
     de decidir** entre modo kiosco de Chrome (`--kiosk-printing`), servicio local de
     impresión en la PC del cajero, o SDK/ESC-POS directo a la impresora térmica. Lo que hay
     hoy (`window.open()` + `.print()`) abre el diálogo de impresión del navegador, no es
     automático/silencioso.

2. **Compras** — módulo futuro, reglas de negocio aún sin definir.

(Anticipos/Adelantos, Amortizaciones y Caja ya no están en esta lista — están construidos o
en progreso, ver sus secciones propias arriba. Este ítem de "Anticipos" describía un diseño
propuesto que quedó obsoleto en cuanto se construyó el módulo real de Adelantos —
`AdvanceController`, tabla `advances`, integrado con Caja Fase 6 y Panel Superadmin Fase E.)

## Stack técnico
- **Frontend (POS/admin):** Vue 3, template Rizz/Riz basado en Bootstrap 5
- **Frontend (e-commerce):** Portal Vue.js separado, conectado al mismo backend Laravel
- **Backend:** Laravel
- **Base de datos:** PostgreSQL
- **Facturación electrónica:** Integración con Greenter (SUNAT), generación y envío de XML

## Contexto de negocio
- Sistema orientado al mercado peruano, con cumplimiento de facturación electrónica SUNAT
  y reglas tributarias regionales.
- **Exoneración de Amazonía (Ley 27037):** aplica según combinación de naturaleza del
  producto, destino de la venta y si es exportación.
- **`resolverTipAfeIgv()`:** función central que determina el tipo de afectación del IGV
  combinando naturaleza de producto + destino + exportación. Cualquier cambio en reglas
  tributarias regionales probablemente pasa por aquí.
- **`SaleController::update()`:** usa una estrategia de sincronización con transacción DB
  de tres casos. Al modificar ventas existentes, mantener esta estructura transaccional
  para evitar inconsistencias entre la venta, sus detalles y el comprobante SUNAT asociado.

## Módulos principales
- Gestión de clientes y productos
- Registro y edición de ventas
- Generación de comprobantes electrónicos (boleta/factura) vía Greenter
- Portal e-commerce: catálogo, categorías, carrito — consume la misma API Laravel

## Convenciones y preferencias de trabajo
- **Frontend-first cuando sea suficiente:** si un problema se puede resolver de forma
  simple en el frontend (Vue), preferir esa solución antes que modificar el backend.
- **Explicar antes de corregir:** al debuggear, explicar la causa raíz del problema antes
  de aplicar la solución.
- **Comunicación entre frontend y backend:** usar el helper `httpClient` (Axios) para
  llamadas a la API Laravel.
- **Estilos:** cuidado con variables CSS personalizadas en estilos "scoped" de Vue — han
  dado problemas antes; si una variable CSS no se aplica en un componente, considerar
  moverla a `main.css` (global) en vez de depurar el scoping.
- **Timing del DOM en Vue:** evitar `document.getElementById()` directo en el montaje de
  componentes; usar `ref` de Vue + hooks de ciclo de vida (`onMounted`, etc.) para evitar
  errores de timing.
- **URL de la API en desarrollo (`admin-start-kit`):** en dev, `httpClient`/
  `publicHttpClient` calculan la URL de la API en el momento a partir del hostname actual
  (`src/helpers/apiBaseUrl.ts::resolveApiBaseUrl()`, puerto configurable vía
  `VITE_API_DEV_PORT`, default 8000) — así se puede tener abierta una pestaña por tenant
  (`http://umbo.sistemafe.test:5173`, `http://agencia-demo.sistemafe.test:5173`, etc.)
  simultáneamente contra el mismo `npm run dev`, sin editar `.env` ni reiniciar Vite para
  cambiar de tenant. En producción sigue usando `VITE_API_BASE_URL` fijo desde `.env`, sin
  cambios.

## Notas de arquitectura recientes
- Sync de ventas en edición: enfoque de tres casos dentro de una transacción DB en
  `SaleController::update()` (crear/actualizar/eliminar detalles de venta de forma atómica).
- Bugs resueltos recientemente: cálculo de impuestos, manejo de tipos de datos, renderizado
  de modales, alineación de íconos de navegación (flexbox + `<span>` dentro de
  `<router-link>`).

## Cómo trabajar en este proyecto
- Antes de tocar lógica tributaria (IGV, exoneraciones, `resolverTipAfeIgv()`), revisar
  el flujo completo: producto → destino → tipo de operación, ya que estas reglas están
  interrelacionadas y un cambio aislado puede romper otro caso.
- Antes de tocar `SaleController::update()`, confirmar que cualquier cambio mantenga la
  transacción DB atómica (todo-o-nada) entre venta, detalles y comprobante SUNAT.
- Al agregar migraciones nuevas en `database/migrations/tenant/` (`core/` o
  `verticals/*`), correr `php artisan tenants:migrate-verticales` para aplicarlas a los
  tenants ya provisionados — **NO** usar `tenants:migrate` a secas para esto, ese
  comando solo cubre `tenant/core/` (ver `config/tenancy.php`
  `migration_parameters.--path`, hardcodeado) y deja las de `verticals/` sin aplicar en
  silencio (bug real, arrastrado desde el primer vertical — ver
  `arquitectura-multitenant-backend_1.md`).

## Migración a multi-tenancy (stancl/tenancy) — estado al 2026-07-12

### 1. Drift de schema — CERRADO
El schema real de Postgres (dev) y las migraciones en disco estaban desincronizados: 16
tablas sin `Schema::create` en absoluto (`categories`, `clients`, `client_addresses`,
`companies`, `products`, `sale_details`, `sales`, `sale_payments`, `system_categories`,
`systems`, `system_features`, `system_media`, `system_modules`, `plans`, `manual_recursos`,
`services`), más `users`/`orders`/`tax_configs`/`detraction_codes` con columnas incorrectas
o de más. Causa raíz: varias tablas se crearon/editaron directo en Postgres (pgAdmin/SQL
manual) sin pasar nunca por una migración.

Resuelto con 22 migraciones correctivas nuevas (`2026_06_01_*` y `2026_07_13_*`), validadas
contra una base vacía (`sistemafe_test_migrations`) con `migrate:fresh` — 43 migraciones en
total corren sin error, y el diff de `information_schema` contra dev quedó limpio. Backup de
dev tomado antes de tocar nada: `sv_facturacion_20260712_211130.dump`.

3 mejoras aplicadas a dev (fuera de las migraciones, vía `ALTER TABLE` directo + `INSERT` en
el ledger `migrations`, batch 13): `companies.id` ahora tiene secuencia/autoincrement;
`plans.billing_period `/`description ` corregidos (tenían un espacio al final del nombre,
era un typo); `sale_payments` ahora tiene primary key sobre `id`. Las 22 migraciones nuevas
están registradas en `migrations` de dev como ya ejecutadas (su efecto ya existía en dev
antes de escribir las migraciones — nunca deben correrse literalmente contra dev, están
pensadas para `tenants:migrate` sobre bases nuevas).

### 2. Mapa central vs. tenant — CERRADO

**Nota de ubicación física (actualizada 2026-07-20):** la categorización central/tenant de
abajo sigue siendo correcta, pero las tablas centrales listadas ya no viven físicamente en
`sv_facturacion` — se migraron a `db_tenant_central` en Fase B.0.5 del panel superadmin
(`plan-panel-superadmin.md`), junto con `tenants`/`domains`. `sv_facturacion` ya no cumple
ningún rol de infraestructura; solo conserva los datos históricos del negocio original
pre-multitenant.

**Central** (compartido por todos los tenants): `systems`, `system_categories`,
`system_features`, `system_modules`, `system_media`, `plans`, `manual_recursos`
(catálogo/marketplace del SaaS); `tax_configs`, `detraction_codes`, `note_motivos`
(catálogos legales SUNAT).

**Tenant** (dato propio de cada negocio): `products`, `categories`, `clients`,
`client_addresses`, `sales`, `sale_details`, `sale_payments`, `orders`, `order_items`,
`notes`, `note_details`, `note_series` (es configuración propia del negocio, no ley),
`companies`, `users`, `roles`/`permissions`/`model_has_roles`/`model_has_permissions`/
`role_has_permissions` (Spatie), `advances`, `advance_applications`, `advance_refunds`,
`cache`/`cache_locks`, `jobs`/`job_batches`/`failed_jobs`.

**Excluida por completo**: `personal_access_tokens` (Sanctum instalado pero sin usar — 21
tokens de prueba abandonados, ninguno con `last_used_at`; no se migra a ningún lado).

**Referencias cross-boundary detectadas** (tenant → central, sin FK de Postgres posible
entre bases distintas):
- `products.codigo_detraccion` y `sales.codigo_detraccion` → `detraction_codes.codigo`
- `notes.cod_motivo` + `notes.tipo_doc` → `note_motivos.(catalogo, codigo)`
- `tax_configs` no tiene referencia persistida (se lee en vivo, el valor calculado se copia
  a `sales.*`)

### 3. Mecanismo cross-boundary — diseño aprobado

- Los 10 modelos centrales (`DetractionCode`, `NoteMotivo`, `TaxConfig`, `System`,
  `SystemCategory`, `SystemFeature`, `SystemModule`, `SystemMedia`, `Plan`,
  `ManualRecurso`) deben usar el trait `Stancl\Tenancy\Database\Concerns\CentralConnection`
  (fuerza la conexión central sin importar el contexto de tenant activo).
- `Product::belongsTo(DetractionCode::class, ...)` no necesita cambios — Eloquent resuelve
  la conexión desde el modelo relacionado.
- `NotaElectronicaController::validarMotivo()` ya valida `cod_motivo` correctamente — no
  necesita cambios, solo que `NoteMotivo` tenga el trait.
- Gap real encontrado: `codigo_detraccion` (en `products` y `sales`) no tenía ninguna
  validación en PHP — se sostenía 100% por la FK de Postgres. Se agrega un check explícito
  (mismo estilo que `validarMotivo()`) en `ProductController`/`SaleController`.
- **Orden de implementación**: el check de validación de `codigo_detraccion` debe
  implementarse y probarse **antes** de mover `detraction_codes`/`tax_configs` a la base
  central — para no dejar una ventana sin ninguna de las dos protecciones (ni FK de
  Postgres, ni validación de aplicación).

### 4. Pendientes conocidos (no resueltos, fuera del alcance de esta ronda de auditoría)
- `DatabaseSeeder`/`PermissionsDemoSeeder` no están listos para `tenants:seed` — usuario
  admin hardcodeado (`umbosac@gmail.com`/`12345678`) y `Permission::create()` no idempotente.
- `TaxConfigSeeder`/`DetractionCodeSeeder` deberían correr una sola vez contra la base
  central (no por tenant) — falta decidir el mecanismo concreto de cuándo/cómo se disparan.
- Rutas de storage `xml/`/`cdrs/` con nombre fijo para `debug_xml.xml` (bug de concurrencia
  ya detectado en single-tenant, se agrava con multi-tenant) — sin resolver. (El
  particionado por tenant del storage en sí ya funciona vía `FilesystemTenancyBootstrapper`
  para los discos `public`/`private`, esto es específicamente el nombre fijo del archivo de
  debug, no la partición.)
- **CERRADO (2026-07-20, plan-panel-superadmin.md Fase B.1):** Config de SUNAT (RUC/usuario
  SOL/clave SOL) ya no vive en `.env` — `GreenterService::getSee()` la lee de `SunatConfig`
  por tenant (`sandbox`/`umbo` migrados con las credenciales que antes eran implícitas,
  `modo='beta'`). Cada tenant sin `SunatConfig` activo falla explícito al emitir, sin
  fallback a una identidad compartida.
- **CERRADO (2026-07-20, plan-panel-superadmin.md Fase B.2):** el certificado SUNAT ya no
  vive en el disco `public` — disco `private` nuevo (`storage/app/private`, sin symlink
  público), particionado por tenant vía `FilesystemTenancyBootstrapper`. El certificado
  demo central compartido (`certificate-demo.pem`, para tenants en `beta` sin certificado
  propio todavía) sigue con `base_path()` directo a propósito, fuera de este disco.
- **CERRADO (2026-07-30, rama `fix/infra-migracion-verticals-pendientes`, mergeada a
  `main` en `3fc2c6f`):** `config/tenancy.php` `migration_parameters['--path']` está
  hardcodeado a `tenant/core/` — así que el comando genérico `php artisan tenants:migrate`
  (sin `--path` explícito), usado como mantenimiento normal para aplicar migraciones
  **nuevas** a tenants **ya provisionados**, nunca corría `tenant/verticals/*`, para
  ningún tenant, sin importar su `giro`. Bug preexistente desde el primer vertical
  (agencia de viajes, Sesión 2) — se venía compensando con migración manual cada sesión
  (ver hallazgo real de la Sesión 11b4b en
  `docs/planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md`) sin que nadie notara
  que el mecanismo automático estaba roto. Distinto del camino de **provisioning
  inicial** (`TenantProvisioningService::provision()` → `migrarVertical()`), que sí
  funciona bien porque arma un `--path` explícito por tenant según su `giro` en el
  momento de crearlo.
  **Fix**: comando nuevo `php artisan tenants:migrate-verticales`
  (`app/Console/Commands/MigrateVerticalesPendientes.php`) — reemplaza a `tenants:migrate`
  a secas como comando de mantenimiento de acá en adelante (ver checklist en "Cómo
  trabajar en este proyecto", arriba). Corre `tenant/core/` para todos los tenants, agrupa
  por `giro`, y corre `tenant/verticals/{giro}/` con `--path` explícito por grupo —
  reutiliza `TenantProvisioningService::rutaVertical()` (extraído de `migrarVertical()`,
  antes privado, para no duplicar el mapeo snake_case→kebab-case). Idempotente por diseño.
  Test de cobertura real (`tests/Feature/MigrateVerticalesPendientesTest.php`): crea 2
  tenants físicos descartables (uno `agencia_viajes`, uno `retail`), confirma que las
  tablas de vertical llegan solo al que corresponde. Corrido de verdad contra los tenants
  reales existentes (`negocio2`/`umbo`/`umbo-archivado`/`sandbox`/`agencia-demo`): puso al
  día un backlog de `tenant/core/` acumulado en varios de ellos; `agencia-demo` ya estaba
  al día en `verticals/agencia-viajes/` (venía del workaround manual de la Sesión 11b4b),
  confirmando el fix sin necesitar backfill adicional ahí. Detalle completo en
  `docs/planning/arquitectura-multitenant-backend_1.md`.
