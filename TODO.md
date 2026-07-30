# TODO

Pendientes puntuales encontrados en el camino, que no bloquean nada pero no
deben perderse. No es un plan de módulo — para eso está `docs/planning/`.

## Sesión 0 (Infraestructura core/verticals) — 27-jul-2026

- **`sandbox` tiene 7 migraciones de series/comprobantes (2026-07-19)
  pendientes de correr** (`create_serie_comprobantes_table` y las 6
  siguientes de esa fecha) — drift pre-existente, encontrado al verificar
  el refactor de `tenant/core/`, no relacionado a ese movimiento. Correr
  `php artisan tenants:migrate --tenants=sandbox` cuando se vaya a usar
  ese módulo contra `sandbox`.
- **`giro`/`tipo` quedaron opcionales en
  `TenantProvisioningService::provision()`** para no romper
  `TenantAdminController` (panel superadmin HTTP) hoy — ese controller
  todavía no los pasa, así que un tenant creado por el panel sigue
  cayendo en los defaults de la migración (`giro=retail`, `tipo=real`).
  Cuando se construya el wizard del panel superadmin, `TenantAdminController`
  va a necesitar empezar a pasarlos explícitos (mismo whitelist que ya
  valida `tenants:provision`: `retail`/`agencia_viajes` y `real`/`demo`).

## Sesión 1 (Catálogos centrales) — 27-jul-2026

- **Seeders de catálogos centrales sin mecanismo de disparo automatizado.**
  `ProveedorTipoSeeder`/`TemporadaSeeder` (nuevos, Sesión 1) quedan
  standalone — mismo problema ya documentado en `CLAUDE.md` para
  `TaxConfigSeeder`/`DetractionCodeSeeder`: corren a mano una sola vez
  contra `db_tenant_central`, no por tenant, y no hay ningún comando ni
  paso de `tenants:provision` que los dispare. No bloquea nada hoy (ya
  corridos en dev), pero no está cubierto por ninguna de las 11 sesiones
  de `plan-hoja-de-ruta-ejecucion.md` — si se agregan más catálogos
  centrales en sesiones futuras, conviene resolver el mecanismo de una
  vez para los 4 (2 viejos + 2 nuevos) en lugar de ir sumando seeders
  sueltos sin dueño.

## Sesión 2 (Catálogos por tenant, sin dependencias) — 27-jul-2026

- **Convención `giro` (snake_case) → carpeta vertical (kebab-case), ya
  formalizada como regla — ver `plan-modulo-infraestructura-multitenant.md`
  §1.1.** Anotado acá solo como recordatorio de que existe: cualquier
  vertical nuevo (Sesión de infraestructura de un rubro futuro) tiene que
  nombrar su carpeta bajo `tenant/verticals/` siguiendo esa conversión
  directa (`str_replace('_', '-', $giro)`, la misma que usa
  `TenantProvisioningService::migrarVertical()`) — si algún giro futuro no
  convierte 1:1 con esa regla, `migrarVertical()` va a necesitar un mapeo
  explícito por giro en vez de la conversión automática actual.

## Sesión 3 (Puente destino↔servicio + proveedores) — 27-jul-2026

- **`proveedor_tipos_config` se crea vacía, sin sembrado automático al
  provisionar.** El plan (`plan-modulo-proveedores.md` §2.6) dice
  "habilitado default true, sembrado al provisionar con todo el
  catálogo" — copiar todas las filas de `proveedor_tipos` (central) a
  `proveedor_tipos_config` (tenant, con `habilitado=true`) para cada
  tenant `agencia_viajes` nuevo. No implementado en esta sesión a
  propósito (decisión explícita del usuario). Cuando se resuelva, el
  lugar natural es `TenantProvisioningService::provision()`, mismo punto
  donde ya corre `migrarVertical()` — condicionado a `giro=agencia_viajes`,
  después de que la tabla exista en el tenant. Hasta entonces, un
  proveedor nuevo no tiene ningún tipo habilitado por defecto — hay que
  cargar `proveedor_tipos_config` a mano por tenant.
- **✅ RESUELTO 27-jul-2026 (rama `fix/eliminar-si-vacio-agencia-viajes`).**
  `TenantProvisioningService::eliminarSiVacio()` no conocía las 7 tablas
  del vertical agencia_viajes (`proveedores`, `destinos_atractivos`,
  `destino_servicio`, `servicios`, `guias`, `proveedor_tipos_config`,
  `configuracion_agencia`) — solo chequeaba Company/SunatConfig/Client/
  Product/Sale, código de antes de que este vertical existiera. Un
  tenant `agencia_viajes` con proveedores/destinos reales cargados pero
  sin Company/cliente/producto/venta todavía se seguía considerando
  "vacío" y se podía borrar de verdad desde el botón "Eliminar" del
  panel superadmin — riesgo real de pérdida de datos, no cosmético.
  Corregido agregando `tieneDatosVerticalAgenciaViajes()` (cuenta las 6
  tablas de catálogo/relación, con un `Schema::hasTable('configuracion_agencia')`
  como gate único para no romper contra tenants retail que nunca migraron
  ese set) + `configuracionAgenciaFueEditada()` (la fila default siempre
  existe — se compara contra los valores exactos de la migración en vez
  de solo "existe", mismo criterio ya usado para el producto placeholder
  `ADELANTO-001`). Ver docstring de `eliminarSiVacio()` para el detalle
  completo. Verificado contra dev real: tenant recién provisionado sigue
  eliminable, el mismo tenant con datos reales en 3 tablas queda
  rechazado, editar un solo campo de `configuracion_agencia` sin tocar
  ninguna otra tabla también rechaza (aislado), y `sandbox` (retail) no
  lanza ningún error de tabla inexistente.

## Sesión 5 (Tarifas) — 27-jul-2026

- **`opciones_hotel.opcion_mayorista_id`/`paquete_plantilla_id` sin FK
  real todavía.** Las tablas `opcion_mayorista` (Sesión 7) y
  `paquetes_plantilla` (Sesión 6) no existen aún — quedaron como
  `unsignedBigInteger` nullable, sin `constrained()`. Cuando cada una
  de esas sesiones aterrice, agregar la FK real vía migración
  `ALTER TABLE opciones_hotel ADD CONSTRAINT ...` (o
  `Schema::table('opciones_hotel', ...)` con `foreign()`), no dejarlo
  para "después de después".
  **`paquete_plantilla_id`: ✅ RESUELTO 27-jul-2026 (Sesión 6, migración
  `2026_07_27_200300_add_paquete_plantilla_foreign_to_opciones_hotel_table.php`)**
  — `paquetes_plantilla` ya existe, FK real cerrada vía retrofit, y
  `OpcionHotel::paquetePlantilla()` actualizado con `belongsTo` real.
  **`opcion_mayorista_id`: ✅ RESUELTO 28-jul-2026 (Sesión 7b, migración
  `2026_07_28_100400_add_opcion_mayorista_foreign_to_opciones_hotel_table.php`)**
  — `opcion_mayorista` ya existe, FK real cerrada vía retrofit, y
  `OpcionHotel::opcionMayorista()` actualizado con `belongsTo` real. Con
  esto quedan cerradas las 2 FK diferidas de `opciones_hotel`.
- **✅ RESUELTO 27-jul-2026 (rama `fix/eliminar-si-vacio-opciones-hotel`).**
  Recurrencia del mismo gap ya resuelto una vez (Sesión 3):
  `TenantProvisioningService::eliminarSiVacio()` tampoco conocía las
  tablas nuevas de esta sesión (`proveedor_tarifas`, `guia_tarifas`,
  `opciones_hotel`, `opciones_hotel_tarifas` — `temporada_ocurrencias`
  es central, no aplica). `proveedor_tarifas`/`guia_tarifas` quedaban
  cubiertos indirectamente (su FK exige que ya exista un
  `Proveedor`/`Guia` real, que sí se chequea), **pero `opciones_hotel`
  NO** — `proveedor_id` es nullable ahí, así que un tenant podía tener
  filas reales en `opciones_hotel`/`opciones_hotel_tarifas` sin ningún
  `Proveedor` real, y seguía considerándose "vacío". Corregido
  agregando `OpcionHotel::count() > 0` a
  `tieneDatosVerticalAgenciaViajes()` (`opciones_hotel_tarifas` queda
  cubierta transitivamente por su FK obligatoria a `opciones_hotel`).
  Atacado apenas se detectó, mismo criterio que la vez pasada — no
  diferido. Verificado contra dev real: tenant recién provisionado
  sigue eliminable; el mismo tipo de tenant con una fila real en
  `opciones_hotel` pero CERO `Proveedor` cargados (caso exacto que
  describía el gap, aislado a propósito) queda rechazado; `sandbox`
  (retail) sigue sin ningún error de tabla inexistente.

## Sesión 6/7a/7b — recurrencia del gap de `eliminarSiVacio()` — 28-jul-2026

- **✅ RESUELTO 28-jul-2026 (Sesión 7b).** `tieneDatosVerticalAgenciaViajes()`
  no conocía ninguna de las tablas agregadas desde Sesión 6 en adelante —
  mismo patrón ya resuelto dos veces (Sesión 3, Sesión 5). La nota
  original de Sesión 7a (abajo, tachada por la corrección) asumía que
  `paquetes_plantilla` y `cotizaciones` eran "raíces obligadas" sin
  trazar su propia cadena de FK hacia arriba — al hacerlo en Sesión 7b
  (necesario para decidir qué chequear de las tablas nuevas de 7b) se
  encontró que esa asunción estaba mal: **`paquetes_plantilla.destino_atractivo_id`
  y `cotizaciones.cliente_id` son NOT NULL**, así que cualquier fila real
  en cualquiera de las dos ya implica una fila real en `DestinoAtractivo`
  (chequeada en `tieneDatosVerticalAgenciaViajes()`) o en `Client`
  (chequeada en `eliminarSiVacio()`, un nivel arriba) — quedan cubiertas
  transitivamente sin chequeo propio. La única tabla nueva sin ningún
  ancestro ya cubierto resultó ser `tipo_cambio_agencia` (su única FK,
  `registrado_por`→`users`, no cuenta porque `users` nunca se chequea acá
  — todo tenant tiene su admin de provisioning). Corregido agregando
  únicamente `TipoCambioAgencia::count() > 0`. El resto de las tablas
  nuevas (`cotizacion_pasajeros`, `alternativas`, `alternativa_items`,
  `opcion_mayorista`, `opcion_mayorista_opcionales`, `salidas_mayorista`,
  `tour_itinerario_items`, `paquete_plantilla_items`) quedan cubiertas
  transitivamente por sus propias FK NOT NULL. Verificado con 3 casos
  reales (tenants descartables): tenant con solo una `Cotizacion` →
  rechazado (vía `Client::count()`, no vía este fix); tenant con solo un
  `tipo_cambio_agencia` → rechazado (prueba directa del fix); tenant
  recién provisionado sin ningún dato → sigue eliminable, sin regresión.
  Ver docstring de `tieneDatosVerticalAgenciaViajes()` para el detalle
  completo de la cadena de FK de cada tabla.

## Sesión 9a — `controla_stock` sin conectar a `SaleController` — 28-jul-2026

- **`products.controla_stock` (Sesión 9a, `2026_07_28_130000_alter_products_add_controla_stock.php`)
  existe en schema pero `SaleController::store()`/`update()` todavía
  decrementan/incrementan `stock` sin condición** — confirmado por grep,
  ningún punto de esos dos métodos lee `controla_stock` todavía. Los 5
  productos genéricos de viaje (`ProductoGenericoViajeSeeder`) van a ir
  quedando con `stock` cada vez más negativo apenas un tenant
  `agencia_viajes` empiece a facturar servicios reales contra ellos — es
  drift numérico silencioso en la columna, no un bug de cálculo (el
  `decrement()`/`increment()` en sí son correctos, solo no deberían
  correr para estos productos).
  - **Matizado, no confirmado el riesgo tal como se planteó**: no bloquea
    ventas HOY a través del flujo normal (`register.vue`/`edit.vue`) —
    ambos frontends solo bloquean por stock insuficiente cuando
    `product.disponiblidad !== 1`
    (`admin-start-kit/src/views/sale/register.vue:1214`/`1223`, mismo
    guard en `edit.vue`), y `ProductoGenericoViajeSeeder` sembró los 5
    productos con `disponiblidad=1` ("Vender sin stock") a propósito, por
    ser justamente la opción coherente con "sin inventario real". El
    riesgo real y sí vigente: (1) cualquier flujo que llame a
    `SaleController::store()`/`update()` directo por API sin pasar por
    ese chequeo de frontend (ej. la futura generación reserva→Sale, §6.2)
    no tiene ningún guard, ni de frontend ni de backend; (2) si algún día
    se agrega una validación de stock del lado del backend (razonable,
    todavía no existe), estos 5 productos empezarían a bloquear ventas de
    inmediato por el stock negativo ya acumulado, con causa raíz no obvia
    para quien lo debuguee entonces; (3) si un admin de tenant edita
    manualmente uno de los 5 productos y le cambia `disponiblidad` a `2`
    sin saber por qué estaba en `1`, el bloqueo del punto anterior pasa a
    ser inmediato incluso sin cambios de backend.
  - **Pendiente, explícitamente para Sesión 11 (CRUD real)**: conectar
    `controla_stock=false` a `SaleController::store()`/`update()` para
    saltar el `decrement()`/`increment()` de stock en esos 2 puntos (y
    los puntos equivalentes de `update()`, líneas ~1270/~1324/~1338) —
    mismo criterio ya usado para otras columnas "schema listo, lógica
    después" del vertical (`reserva.motivo_cancelacion` y relacionados,
    Sesión 8a; `reglas_cancelacion`, Sesión 8b).

## Sesión 9c — última FK diferida del vertical, cerrada — 28-jul-2026

- **✅ RESUELTO 28-jul-2026 (Sesión 9c, migración
  `2026_07_28_150200_add_pasajero_catalogo_foreign_to_reserva_pasajeros_table.php`).**
  `reserva_pasajeros.pasajero_catalogo_id` (Sesión 8a, sin FK porque
  `pasajeros_catalogo` — Sesión 9c — no existía todavía) ya tiene FK real,
  y `ReservaPasajero::pasajeroCatalogo()` actualizado con `belongsTo` real.
  **Con esto NO queda ninguna FK diferida pendiente en todo el vertical
  Agencia de Viajes** — barrido completo confirmado por grep sobre
  `database/migrations/tenant/verticals/agencia-viajes/` (patrón "sin FK
  todavía"): las únicas 4 coincidencias son la migración original de
  `opciones_hotel` (Sesión 5, sus 2 FK ya cerradas arriba), su propio
  comentario de retrofit, y `alternativa_items.opcion_mayorista_id`
  (Sesión 7a→7b, ya cerrada) — ninguna otra tabla del vertical quedó con
  una FK sin cerrar.
- **Hallazgo, no un bug — `configuracion_agencia.meses_margen_vencimiento_documento`
  YA EXISTÍA desde Sesión 2** (`2026_07_27_160300_create_configuracion_agencia_table.php`,
  con default 6 y ya en `ConfiguracionAgencia::$fillable`/la fila seed). El
  prompt de Sesión 9c pedía agregarla vía ALTER TABLE nueva — no se creó
  esa migración duplicada al confirmar que la columna ya existía completa
  (mismo nombre, mismo default, misma semántica del plan §6.5). Ver
  comentario de Sesión 2 en `plan-modulo-cotizaciones-reservas.md` §3.1 (la
  columna se agregó ahí porque varias secciones del plan la mencionaban
  sueltas, compiladas en una sola tabla de configuración desde el
  principio) — no hace falta ninguna acción, la lógica de la alerta sigue
  pendiente para Sesión 11 tal como estaba previsto.

## Sesión 10 — reporte operativo (§8) + recordatorios (§8bis) — 28-jul-2026

- **Hallazgo, no un bug** — `configuracion_agencia.dias_aviso_pago_proveedor`/
  `dias_cotizacion_estancada` YA EXISTÍAN desde Sesión 2 (mismo caso que
  `meses_margen_vencimiento_documento` en Sesión 9c), con sus defaults
  correctos (2 y 15) y ya en `ConfiguracionAgencia::$fillable`. No se creó
  ninguna migración duplicada para esas 2 columnas.
- **`tieneDatosVerticalAgenciaViajes()` actualizada** con `TipoRecordatorio::count() > 0`
  (única raíz nueva sin ancestro cubierto — mismo criterio que
  `ReglaCancelacion`/`TipoCambioAgencia`, carga inicial vía seeder standalone
  `TipoRecordatorioSeeder`, NO en `tenants:provision`). `recordatorios`/
  `recordatorio_snooze_config` quedan cubiertas transitivamente por su FK
  NOT NULL a `tipos_recordatorio`; las columnas nuevas de
  `reserva_item_pasajero` (checkin) no agregan ninguna raíz. Ver docstring
  actualizado de `tieneDatosVerticalAgenciaViajes()` para el detalle.
- **`recordatorios.entidad_id` es polimórfico SIN FK a propósito** (no una
  FK diferida más a cerrar en una sesión futura, a diferencia de las 4 que
  este vertical fue cerrando — ver Sesión 9c arriba, "NO queda ninguna FK
  diferida pendiente"): `entidad_tipo` puede apuntar a `reserva`,
  `cotizaciones`, `clients` (core) o `pago_proveedor` según el valor de esa
  misma columna — ninguna FK real de Postgres puede expresar eso. Se valida
  en aplicación qué tabla corresponde, Sesión 11.
- **Pendiente para Sesión 11 (frontend/CRUD)**: pantalla del reporte
  operativo (tabla filtrable + acciones inline: asignar guía, marcar
  check-in), su versión PDF de solo lectura, generación automática de
  `recordatorios` desde los 4 disparadores del plan (pago a proveedor
  próximo, cumpleaños, cotización estancada, documento por vencer), y la
  UI de snooze/omitir con la excepción de `forzado`.

## Sesión 11b (Cotizador) — retrofits confirmados sobre `alternativa_items` — 28-jul-2026

- **`cotizacion_pasaje_aereo.aerolinea`: DECIDIDO 28-jul-2026 — texto
  libre, no FK.** Mismo criterio que `paquetes_plantilla.vuelo_aerolinea`/
  `opcion_mayorista.vuelo_aerolinea` (ambos ya texto libre, y en
  `opcion_mayorista` el proveedor con FK real es el mayorista, no la
  aerolínea). Confirmado con el usuario: la agencia no es agencia IATA,
  sin relación comercial directa con aerolíneas que reportar. Ver
  `plan-modulo-cotizaciones-reservas.md` §2.5 para el detalle completo.
- **`alternativa_items.cantidad` todavía no existe en ninguna migración
  corrida** (Sesiones 0-10 son anteriores a este hallazgo). Se agregó al
  documento de diseño (`plan-modulo-cotizaciones-reservas.md` §3) el
  28-jul-2026, encontrado probando el prototipo HTML del cotizador — un
  hotel se cobra por noche, un transporte privado puede pedirse en más de
  un vehículo. La migración ALTER TABLE que la agrega queda para Sesión
  11b, no es retroactiva a Sesión 7 (no hay datos reales en producción
  todavía que se vean afectados).
- **Confirmado con el usuario 28-jul-2026 — `alternativa_items.origen_tipo`
  + ítem manual/libre.** Mismo retrofit que `cantidad` de arriba, misma
  migración (Sesión 11b, sobre `alternativa_items` ya mergeada en Sesión
  7). Se agrega `origen_tipo` (proveedor\|mayorista\|pasaje_aereo\|manual)
  como discriminador explícito del origen del ítem, en vez de inferirlo
  por qué FK nullable está llena. Se agrega `descripcion_manual` para el
  4to origen — un ítem sin proveedor registrado, precio a mano, sin
  restricción de rol, sin validación de piso de descuento (no hay
  `proveedor_tarifa` de la que derivarlo). Ver
  `plan-modulo-cotizaciones-reservas.md` §3 y §7.1 para el detalle
  completo.

## Retrofit confirmado para Sesión 11a — `proveedor_tarifas.tipo_habitacion` — 28-jul-2026

- **Decidido (no es pregunta abierta como las 2 anteriores):** agregar
  columna `tipo_habitacion` (matrimonial | doble | triple | familiar,
  nullable) a `proveedor_tarifas`, mismo enum que ya usa
  `opciones_hotel_tarifas` (Sesión 5). Hoy vive metida dentro de
  `diferenciador` (JSON libre) — el motor de precios de 11b necesita
  tratar "Hotel" igual sin importar si el ítem viene de un proveedor
  local o de un paquete/mayorista.
- Es un **retrofit sobre Sesión 5, ya mergeada** — la migración ALTER
  TABLE + backfill de los datos ya cargados en `diferenciador` va como
  parte de Sesión 11a (que de todas formas construye el CRUD de
  `proveedor_tarifas`), no una migración suelta aparte.
- Ver `plan-modulo-cotizaciones-reservas.md` §2.2 y §7.1 para el detalle
  completo de la decisión.

## Retrofit confirmado para Sesión 11c — `reserva_items.proveedor_tarifa_id` reasignable — 28-jul-2026

- **Decidido:** agregar `proveedor_tarifa_id` (nullable, FK a
  `proveedor_tarifas`) a `reserva_items` — mismo patrón que `guia_id`
  (§5.3, ya existe desde Sesión 8). Caso real confirmado por el usuario:
  en actividades locales muchas veces se cotiza sin saber todavía qué
  proveedor específico va a operar, se asigna recién al reservar o días
  antes de la fecha del servicio.
- Se copia de `alternativa_items.proveedor_tarifa_id` al aceptar la
  alternativa SI ya venía asignado en cotización; queda NULL si se
  cotizó con precio de referencia. Reasignable después, sin restricción.
- Es un **retrofit sobre Sesión 8, ya mergeada** — va en Sesión 11c
  (reserva/pasajeros), no en 11a ni 11b.
- **DECIDIDO 28-jul-2026:** sin alerta automática de recordatorio
  (§8bis) para `reserva_items` sin proveedor asignado. Queda visible
  solo en el reporte operativo (§8) — no se agrega un 5to
  `tipos_recordatorio` para esto.
- Ver `plan-modulo-cotizaciones-reservas.md` §3 y §4 para el detalle
  completo.

## Sesión 11a — API REST + formularios maestros — CERRADA — 28-jul-2026

- **Primera capa API real del vertical** (Sesiones 0-10 eran solo
  migraciones/modelos/seeders, confirmado por grep antes de empezar).
  Namespace `App\Http\Controllers\AgenciaViajes`, 12 controllers: catálogo
  `ProveedorTipoConfig` (solo lectura + toggle), CRUD completo de
  `Proveedor`/`ProveedorServicio`/`ProveedorTarifa` (esta última con
  versionado real — nunca UPDATE directo si la tarifa ya se usó en
  `alternativa_items`, cierra `vigente_hasta` y crea fila nueva),
  `DestinoAtractivo` (árbol + guard de `destroy()` contra hijos/
  `destino_servicio`/`tour_itinerario_items`/`guia_tarifas`, siempre 422
  explícito, nunca 500), `Servicio`, `DestinoServicio`, `Temporada`/
  `TemporadaOcurrencia` (central — editar/eliminar afecta a TODO el rubro,
  no solo el tenant que llama, documentado en el controller), `Guia`/
  `GuiaTarifa`, `ConfiguracionAgencia` (singleton, GET con defaults del
  plan como backstop). 5 permisos Spatie nuevos (`agencia.*`, dot-notation
  igual que `cash.*`) gateando cada grupo de rutas a nivel de ruta (defensa
  en profundidad, no solo menú) — migración propia en
  `tenant/verticals/agencia-viajes/` (no `tenant/core/`, a diferencia de
  los permisos de Caja/Amortizaciones) porque son exclusivos de este giro.
- **RETROFIT Parte 0 cerrado**: `proveedor_tarifas.tipo_habitacion`
  agregada como columna real (mismo enum que `opciones_hotel_tarifas`
  desde Sesión 5), con backfill desde `diferenciador` (JSON) — 0 filas
  reales afectadas, confirmado antes de correr (no existía ningún CRUD de
  `proveedor_tarifas` hasta esta sesión). `ProveedorTarifaController`
  exige `tipo_habitacion` solo cuando el proveedor padre es tipo Hotel
  (resuelto contra el catálogo central `proveedor_tipos`, slug='hotel'),
  verificado con un `WHERE tipo_habitacion=...` real contra la columna
  (no `->>` sobre el JSON).
- **Frontend**: 8 vistas en `views/agencia-viajes/` (proveedores index/
  form/detalle con tabs, destinos árbol expandible con alta rápida de
  servicios inline, temporadas, guías index/detalle, configuración
  singleton), 6 services TS, componente reutilizable `DestinoTreeSelect.vue`
  (pensado para que 11b lo reuse en el cotizador, acepta `nivel-min`/
  `nivel-max`). Router + menú lateral nuevos (sección "Agencia de Viajes"),
  y los 5 permisos sumados a `types/roles.ts` — agregado desde el día 1
  esta vez, Caja (Fase 5) había dejado documentado que omitir este paso
  deja el permiso inasignable desde la UI pese a existir en el backend.
  `npm run type-check` sin errores nuevos en ningún archivo de esta sesión
  (los preexistentes en otras vistas no tocadas siguen igual).
- **Verificado con HTTP real en proceso** (kernel completo — routing +
  `InitializeTenancyBySubdomain` + `auth:api` + `permission:*` + controller
  + validación —, sin necesitar hosts file ni servidor corriendo) contra un
  tenant `agencia_viajes` descartable: 33 checks — los 4 tipos de proveedor
  habilitados y 1 proveedor de cada uno, árbol de 3 niveles con 2 servicios
  ligados, proveedor Hotel con 2 tarifas `tipo_habitacion` distintas
  (confirmado el filtro por columna), temporada con 1 ocurrencia, guía con
  2 tarifas, `configuracion-agencia` editado y reflejado en el GET
  siguiente, `DestinoAtractivoController#destroy` rechazando con
  hijos/servicios (422, nunca 500), y un usuario sin ningún permiso
  `agencia.*` recibiendo 403 real en `GET proveedores` (confirma que el
  middleware `permission:` de las rutas nuevas funciona de verdad, no solo
  que las rutas existen). Los 33/33 pasaron.
- **`eliminarSiVacio()` — sin cambios de código, confirmado explícito**:
  todas las tablas nuevas de esta sesión (`ProveedorTarifa` con la columna
  agregada, más las rutas nuevas sobre `Proveedor`/`ProveedorServicio`/
  `DestinoAtractivo`/`Servicio`/`DestinoServicio`/`Guia`/`GuiaTarifa`) ya
  existían desde Sesiones 1-5 y ya estaban cubiertas en
  `tieneDatosVerticalAgenciaViajes()` — Sesión 11a no agregó tablas nuevas,
  solo la capa API sobre tablas ya chequeadas (`Temporada`/
  `TemporadaOcurrencia` son centrales, fuera del alcance de ese método).
  Verificado con control cruzado real: tenant recién provisionado sigue
  eliminable; el mismo tenant después de correr el checklist completo de
  arriba (con datos reales en `Proveedor`/`DestinoAtractivo`/etc.) queda
  rechazado. Sin regresión.

## Sesión 11b — Cotizador (motor de precios, pasaje aéreo, comparador de mayoristas) — CERRADA — 28-jul-2026

- **RETROFIT Parte 0 cerrado**: `alternativa_items.origen_tipo`/`cantidad`/
  `descripcion_manual` agregadas con backfill (mismo patrón de 2 pasos que
  `tipo_habitacion` en 11a — columna nullable, backfill, luego
  `NOT NULL` vía `->change()`). Tabla nueva `cotizacion_pasaje_aereo`
  (1-a-1 con `alternativa_items`, `aerolinea` texto libre sin FK — decisión
  ya cerrada en la sesión de diseño previa).
- **`PriceEngineService`** (`app/Services/AgenciaViajes/`, servicio de
  dominio puro): `calcular()` para margen+cargos+piso, `evaluarPiso()`
  para la edición en vivo sin recalcular todo. Revisado antes de tocar
  nada: `ProveedorTarifaController` (11a) NO calculaba margen inline
  (`precio_venta_adulto`/etc. los ingresa el admin directo al catálogo) —
  nada que refactorizar ahí, documentado en el propio servicio.
- **4 controllers nuevos**: `CotizacionController` (header + pasajeros,
  reusa el `codigo` auto-generado que ya vivía en el modelo `Cotizacion`
  desde Sesión 7a), `AlternativaController` (máximo 5 por cotización
  —regla de negocio que Sesión 7a había dejado documentada como pendiente
  de esta sesión—, aceptar descarta las demás automático, tipo de cambio
  resuelto o registrado al vuelo si no existe uno previo),
  `AlternativaItemController` (los 4 `origen_tipo` con su propia forma de
  snapshot, edición en vivo de `descuento_pct`/`precio_convertido`
  bidireccional con piso comparado en la MISMA moneda que el precio
  editado — costo y venta base se convierten antes de comparar, para no
  mezclar monedas), `OpcionMayoristaController` (comparador, marcar
  elegida no descarta las demás, matriz de hoteles).
- **Endpoint adicional no pedido explícitamente pero necesario para
  cumplir la spec al pie de la letra**: `POST alternativas/{id}/items/
  preview-pasaje-aereo` — la spec de `PasajeAereoForm.vue` decía "no
  reimplementes la suma en el frontend, pedísela al backend", pero no
  existía ningún endpoint de solo-cálculo sin persistir. Se extrajo la
  validación+cálculo de `crearItemPasajeAereo()` a 2 helpers privados
  compartidos (`validarPasajeAereo()`/`calcularPasajeAereo()`) para que
  preview y creación real usen exactamente la misma lógica, sin duplicar.
- **Endpoint adicional #2, mismo motivo**: `GET proveedor-tarifas`
  (`ProveedorTarifaController::biblioteca()`) — la "biblioteca" del
  cotizador (§7.1) necesita listar tarifas de TODOS los proveedores, y
  11a solo tenía un índice anidado bajo un `proveedor_servicio_id`
  puntual. **Limitación conocida, documentada en el propio controller**:
  el plan pedía filtrar por "destino_servicio de la cotización", pero
  `cotizaciones.destino` es texto libre (§3.1), nunca fue FK a
  `destinos_atractivos` — no hay forma de filtrar por destino a nivel de
  query sin cambiar ese schema. La biblioteca lista todo el catálogo
  vigente con búsqueda de texto en su lugar; cambiar `destino` a FK queda
  fuera de esta sesión.
- **Permiso decidido**: `agencia.cotizaciones` (nuevo, NO se reusó
  `agencia.proveedores`) — cotizar es una operación de venta diaria de
  cualquier vendedor, el catálogo de proveedores es más admin-level.
  Migración propia en `tenant/verticals/agencia-viajes/`, agregado a
  `types/roles.ts` desde el día 1 (mismo criterio que 11a).
- **2 bugs reales encontrados corriendo la verificación, no por
  inspección de código**:
  1. `AlternativaItemController::store()` leía `$request->get('origen_tipo')`
     — `get()` es el `ParameterBag` de Symfony (query string/route/
     POST-form), **no lee body JSON crudo**. Cualquier request real con
     `Content-Type: application/json` (como manda el frontend) habría
     recibido siempre 422 "origen_tipo inválido" sin importar qué
     mandara. Corregido a `$request->input('origen_tipo')`. El resto de
     usos de `->get()` en los controllers de esta sesión SÍ eran
     correctos (todos leen query string en un `GET`, donde si funciona).
  2. `alternativa_items.costo_snapshot` es `NOT NULL` desde Sesión 7a —
     los ítems `manual` y "proveedor sin tarifa asignada todavía"
     (`proveedor_tarifa_id=null`, §3) no tienen ningún costo real del que
     derivarlo, y el controller los dejaba en `null`, violando la
     constraint. Corregido con `0` como sentinel explícito en ambos casos
     (documentado en el código — mismo espíritu que "sin piso protegido"
     ya establecido para estos 2 casos: no hay costo de terceros
     rastreable).
- **Verificado con 27 checks de HTTP real en proceso** (mismo patrón que
  11a — kernel completo, sin hosts file) contra un tenant descartable:
  cotización con 4 pasajeros (3 adultos + 1 niño), Alternativa A con
  hotel local (matriz, 2 noches, `total=320` confirmado = 160×2), tour
  por_persona (`total=255` = 3×70 + 1×45), pasaje aéreo con 2 cargos +
  fee (`total=1300`, verificado el cálculo completo: costo repartido +
  cargos + fee), ítem manual — **total de la alternativa = 1920.00,
  coincide exacto con la suma manual esperada**. Alternativa B con
  comparador de mayorista: bloqueado agregar el ítem antes de marcar
  "elegida" (422), permitido después. Piso en vivo: descuento 50% sobre
  una tarifa con piso 10% → `alerta_piso=true`; 5% → `false`; 99% sobre
  un ítem MANUAL → siempre `false` (sin tarifa, sin piso). Aceptar
  Alternativa A descartó sola a la B. Tope de 5 alternativas respetado
  (6ta rechazada).
- **`eliminarSiVacio()` — sin cambios de código, CONTRADICE la
  suposición del prompt de esta sesión** (que decía "sí necesita" chequeo
  propio para `cotizacion_pasaje_aereo` por ser "tabla nueva, raíz
  propia") — trazando la cadena real: `alternativa_item_id` (NOT NULL) →
  `alternativa_items.alternativa_id` (NOT NULL) → `alternativas.
  cotizacion_id` (NOT NULL) → `cotizaciones.cliente_id` (NOT NULL) →
  `Client::count()`, ya chequeado en `eliminarSiVacio()` desde antes de
  este vertical existir. No es una raíz nueva, queda cubierta
  transitivamente — mismo patrón de "asunción de planificación corregida
  al trazar la cadena real" que ya pasó en Sesión 7b. Confirmado
  empíricamente, no solo por lectura de código: un tenant con una fila
  real de `cotizacion_pasaje_aereo` (creada en la verificación de arriba)
  quedó rechazado por `Client::count()` del nivel superior, no por
  ningún chequeo nuevo — porque no se agregó ninguno.
- **Pendiente, explícitamente diferido**: `descuento_global_pct` de
  `alternativas` se guarda como campo simple (`AlternativaController::
  update()`), pero NO cascadea a cada `alternativa_item` respetando su
  piso individual — el plan (§3.1) sí describe ese comportamiento, pero
  la lista de rutas de esta sesión nunca pidió ese endpoint
  explícitamente. Documentado, no construido.

## RETROFIT — `cotizaciones.fecha_viaje_tentativa` → rango desde/hasta — 29-jul-2026

- Sobre la misma rama `feature/sesion-11b-cotizador` (sin mergear todavía).
  `fecha_viaje_tentativa` (una sola fecha) no alcanzaba para cotizar con
  fecha de ida y vuelta ya conocidas — reemplazada por
  `fecha_viaje_desde`/`fecha_viaje_hasta` (ambas nullable, migración
  `2026_07_29_090000_replace_fecha_viaje_tentativa_con_rango.php` con
  backfill de la columna vieja a `fecha_viaje_desde` antes de dropearla).
  Validación nueva: `fecha_viaje_hasta` exige `after_or_equal:
  fecha_viaje_desde` cuando ambas vienen cargadas — confirmado en tinker
  que el caso "hasta sin desde" no rompe la regla (Laravel la trata como
  ausente, no como fecha inválida).
- **Confirmado antes de migrar, no asumido**: los 4 tenants reales
  (`sandbox`/`umbo`/`negocio2`/`umbo-archivado`) todavía no tienen la
  tabla `cotizaciones` — la rama de Sesión 11b nunca llegó a mergearse,
  así que no hubo ningún dato real en riesgo por el backfill.
- `CotizacionController` solo tiene `store()`/`actualizarPasajeros()`
  para el header (no existe ningún `update()` de
  `cliente_id`/`destino`/fecha — el prompt de este retrofit lo mencionaba
  pero no existe en el código real); se actualizó únicamente `store()`.
  Frontend (`nueva.vue`): 2 inputs `<input type="date">` nativos lado a
  lado (sin librería de rango), el checkbox "Todavía no tiene fecha
  exacta" ahora deshabilita ambos.
- Verificado con Postgres real (`sistemafe_test_migrations`, backfill +
  migración aplicada sobre una fila de prueba con `fecha_viaje_tentativa`
  cargada, confirmado `fecha_viaje_desde` poblado y `fecha_viaje_hasta`
  null tras el `up()`) y contra el flujo HTTP completo de
  `CotizacionController::store()`/`show()` en un tenant descartable: caso
  con ambas fechas, caso sin fecha exacta (las 2 en null), y caso solo
  "desde" sin "hasta" — los 3 persisten y el GET los devuelve completos.

## Panel Superadmin — CRUD de `proveedor_tipos` agregado — 29-jul-2026

- Gap real señalado por el usuario: el catálogo central `proveedor_tipos`
  (Hotel/Transporte/Mayorista/Guía, vertical Agencia de Viajes) era
  100% fijo — solo `ProveedorTipoSeeder`, sin ningún CRUD.
  `ProveedorTipoConfigController` (tenant) solo puede tocar `habilitado`
  por tenant, nunca crear/editar/desactivar el catálogo en sí.
- Confirmado explícitamente con el usuario antes de construir: el CRUD
  vive en el **panel central** (superadmin), catálogo compartido por
  todas las agencias — no un CRUD por tenant (que hubiera roto el
  supuesto de "catálogo fijo compartido" del que depende lógica de
  negocio existente, ej. `tipo_habitacion` solo exigido si
  `slug='hotel'` en `ProveedorTarifaController`).
- **Backend**: `Central\ProveedorTipoController` nuevo
  (`GET/POST/PUT/DELETE central/proveedor-tipos`, guard `central`, mismo
  patrón que `TenantPlanController`). `slug` nunca viaja en el payload —
  se deriva de `nombre` con `Str::slug()` una sola vez al crear y queda
  inmutable para siempre (mismo motivo que arriba: no romper lógica atada
  a slugs fijos). Sin borrado real: `destroy()` pone `activo=false`, la
  fila nunca se borra (mismo criterio que `TenantPlanController` —
  `proveedor_tipos` no tiene FK real hacia `Proveedor.tipo_id`, cross-
  boundary tenant↔central, no hay forma barata de confirmar que ningún
  tenant lo esté usando antes de borrar de verdad). Auditado
  (`proveedor_tipo.created`/`updated`/`deactivated`) igual que el resto
  del panel central.
- **Frontend** (`central-panel`): `stores/proveedorTipos.ts` +
  `views/ProveedorTiposView.vue`, calcados de `stores/plans.ts`/
  `PlansView.vue` (mismo patrón de formulario inline reusado para alta/
  edición). Ruta `/tipos-proveedor` + link nuevo en `NavBar.vue`.
  `vue-tsc --noEmit` sin errores.
- Verificado contra Postgres real (conexión `central`, el catálogo de
  producción real — no un tenant descartable, esta tabla es compartida):
  crear tipo nuevo, nombre duplicado rechazado (422), editar nombre
  confirma que el slug NO cambia, `destroy()` confirma que la fila sigue
  existiendo con `activo=false` (no un borrado real), y los 3
  `audit_logs` generados. Fila de prueba (`id=5`, "Restaurante Test
  Retrofit") y sus audit logs eliminados al cerrar la verificación — el
  catálogo real quedó con los mismos 4 tipos de antes
  (Hotel/Transporte/Mayorista/Guía).
- Documentado en `plan-modulo-proveedores.md` §2.6.

## Toggle de tipos de proveedor sin UI conectada — CERRADO — 29-jul-2026

- Gap real encontrado al probar el flujo completo en `agencia-demo`: el
  backend (`ProveedorTipoConfigController::toggle()`) y el service de
  frontend (`proveedorTipoService.toggle()`) ya existían desde Sesión
  11a, pero ningún componente los llamaba — `views/agencia-viajes/
  proveedores/index.vue` solo usaba `.listar()` para poblar el filtro.
  El hint del formulario de Nuevo Proveedor ("habilítalos en
  Configuración de tipos") apuntaba a esa misma página, que no tenía
  ningún switch. Como `proveedor_tipos_config` arranca vacía a propósito
  en cada tenant nuevo, esto dejaba el combo de tipo de proveedor
  siempre vacío hasta tocar el toggle a mano por API.
- Agregado un panel de switches en `index.vue` (arriba del buscador),
  reusando el service ya existente — sin cambios de backend. Verificado
  contra `agencia-demo` real: arrancaba con los 5 tipos en
  `habilitado=false`, el toggle de "Hotel" funcionó.

## `tipo_habitacion` sin "Simple" + bug real en asociar servicio↔destino — CERRADO — 29-jul-2026

- Encontrado ayudando al usuario a registrar Hotel Cumbaza (Alto Mayo)
  con sus tipos de habitación reales.
- **Fix 1 — enum incompleto**: `tipo_habitacion` (`proveedor_tarifas`/
  `opciones_hotel_tarifas`) solo aceptaba matrimonial/doble/triple/
  familiar — sin "simple" (habitación de 1 sola cama/persona). Columna
  es `string` plano (sin CHECK de Postgres), así que no hizo falta
  migración — solo agregar `simple` a las validaciones `in:` de
  `ProveedorTarifaController`/`OpcionMayoristaController`, al union type
  de `agencia-viajes.ts` (`ProveedorTarifa`/`OpcionHotelTarifa`), y a los
  `<select>` de `proveedores/detalle.vue` y `cotizador/editar.vue`.
- **Fix 2 — bug real en "Asociar servicio a destino" (`detalle.vue`)**:
  el `<select>` de Servicio listaba el catálogo GLOBAL de `Servicio`
  (`servicioService.listar({})`) y mandaba ese `id` directo como
  `destino_servicio_id` al asociar — el destino elegido
  (`nuevoDestinoId`) nunca se usaba para nada. `ProveedorServicioController
  ::store()` exige un `destino_servicio_id` real (`exists:destino_servicio,id`),
  así que esto rompía la asociación en la práctica (confirmado: el
  usuario tenía 0 `destino_servicio`/`proveedor_servicio` reales pese a
  haber creado proveedor y servicio). Corregido: al elegir el destino,
  ahora se cargan los `destino_servicio` YA asociados a ESE destino
  (`destinoAtractivoService.listarServicios()`, endpoint que ya existía
  y se usaba en `destinos/index.vue` pero nunca se conectó acá) y el
  `<select>` de Servicio pasa a listar esos, usando el id correcto de la
  tabla puente.
- **Aclaración conceptual dada al usuario** (no un bug de código): el
  catálogo `Servicio` representa una categoría general de oferta
  ("Alojamiento", "Transporte"), no un tipo de habitación — los tipos de
  habitación van en `tipo_habitacion` dentro de las tarifas de ESE
  servicio, no como servicios separados. El usuario había creado un
  `Servicio` llamado "Habitación Simple"; se renombró a "Alojamiento" a
  pedido suyo, confirmado antes de tocar el dato real.
- Verificado con Postgres real contra `agencia-demo`, completando el
  caso real del usuario (no datos descartables): Hotel "SERVICIOS
  TURISTICOS CUMBAZA SRL" con servicio "Alojamiento" en destino
  Tarapoto, y 5 tarifas reales (simple/matrimonial/doble/triple/
  familiar) con precios de ejemplo — a reemplazar por el usuario con los
  precios reales desde la UI.
- Ya sabían los usuarios que el catálogo `Servicio` en sí no necesita
  código para extenderse (Traslados/Alimentación/Entradas, etc.) — CRUD
  completo ya existente vía alta rápida inline en `destinos/index.vue`
  (modal "Servicios asociados" de cada destino), sin ningún enum de por
  medio. Aclarado al usuario, sin cambios de código.

## Sesión 11b2 — Catálogo de Paquetes/Tours de plantilla — 29-jul-2026

- Hueco real de la hoja de ruta cerrado (ver entrada del 29-jul-2026
  anterior): Sesión 6 solo había construido tablas/modelos de
  `paquetes_plantilla`, nunca su API/pantalla. Rama
  `feature/sesion-11b2-paquetes-plantilla`.
- **Backend**: `PaquetePlantillaController` (header CRUD + matriz de hotel
  `hoteles()`/`eliminarHotel()`, mismo motor que
  `OpcionMayoristaController::hoteles()` escopeado a
  `paquete_plantilla_id` — sin extraer a un service compartido, la
  validación de "proveedor debe ser tipo Mayorista" de ese sibling no
  aplica acá). `PaquetePlantillaItemController` resuelve la regla de
  negocio que la migración de Sesión 6 había dejado explícitamente
  pendiente ("uno de los dos entre `proveedor_tarifa_id`/`guia_tarifa_id`,
  nunca ambos ni ninguno", sin CHECK de Postgres, validado a nivel de
  aplicación). `TourItinerarioItemController` para el itinerario
  día-por-día. Permiso nuevo `agencia.paquetes` (mismo criterio admin-level
  que `agencia.proveedores`/`agencia.destinos`, no `agencia.cotizaciones`).
  `destroy()` hace cascada real (items/itinerario/hoteles+tarifas propios)
  en transacción — sin guard externo porque nada fuera del propio árbol
  referencia `paquete_plantilla_id` todavía (11b3 no está construida).
- **Bug real encontrado y corregido probando el flujo completo en
  `agencia-demo`, no por lectura de código**: `paquetes_plantilla.codigo`
  es `unique()` a nivel de Postgres, pero el controller no lo validaba —
  un código repetido tiraba una excepción de BD sin capturar (500 crudo)
  en vez de un 422 limpio. Confirmado creando el mismo paquete dos veces
  vía la UI real. Corregido con `Rule::unique(...)->ignore($id)` en
  `validarPayload()`, reusado por `store()`/`update()`.
- **Frontend**: `views/agencia-viajes/paquetes/` (index con filtro
  categoría, form de alta/edición del header, detalle con 4 tabs — Datos/
  Itinerario/Incluye/Hoteles). `fotos` (JSON array) queda soportado en el
  backend pero sin UI de carga — mismo estado que `destinos_atractivos`,
  que tampoco tiene la subida de fotos conectada en ninguna pantalla
  existente; no se inventó un patrón nuevo de upload sin precedente en
  el resto de este vertical.
  - Tab "Incluye": ítems buscados/agregados por separado según sean de
    proveedor (biblioteca por texto, mismo patrón que el cotizador — pero
    a propósito SIN agrupar tarifas de hotel por tipo de habitación, a
    diferencia de `editar.vue`: acá el admin necesita elegir UNA tarifa
    específica para el "Incluye" del paquete, no cualquiera del rango) o
    de guía (selector de guía → selector de sus tarifas).
- **Verificado dos veces**: contra un tenant descartable real
  (`test-11b2-paquetes`, vía llamadas directas a los controllers —
  creación, los 2 casos válidos de ítem + los 2 casos 422 de la regla
  exactamente-uno, itinerario con y sin destino, hotel con 2 tarifas,
  `show()` con la cadena completa de eager loads, `index()` con filtro,
  `update()`, `eliminarHotel()` standalone, `destroy()` con cascada
  confirmada contando filas antes/después — tenant destruido al cerrar) y
  con captura de pantalla real (Playwright, login real) contra
  `agencia-demo`: creación de un paquete completo desde el formulario,
  las 4 pestañas mostrando datos reales guardados. Migración nueva
  corrida contra `agencia-demo` con
  `tenants:migrate --path=.../verticals/agencia-viajes`. Paquete de
  prueba eliminado al cerrar — `agencia-demo` queda con 0 paquetes.
- `npm run type-check`/`vue-tsc --noEmit` sin errores nuevos, `php -l`
  limpio en los 3 controllers + la migración.
- Pendiente explícito, ya reservado como su propia fila (11b3): conectar
  esto al cotizador ("Cargar desde plantilla").

## Gap real en el diseño de `paquetes_plantilla` — reservado como 11b4 — 29-jul-2026

- Repasando con el usuario, después de cerrar 11b2, la jerarquía original
  de diseño (atractivo → tour → paquete): un paquete debía poder incluir
  varios *tours* como sub-ítems reutilizables ("Paquete = Tour A + Tour B
  + Hotel"). No es construible hoy — `paquete_plantilla_items` solo
  referencia `proveedor_tarifa_id`/`guia_tarifa_id`, nunca otro
  `paquete_plantilla_id`. Como "tour" y "paquete" son la misma entidad
  desde Sesión 6 (`tour_itinerario_items.tour_id` → `paquetes_plantilla.id`),
  esto significa que un paquete no puede incluir otro paquete/tour.
- Solución propuesta por el usuario, confirmada como la correcta (mejor
  que la alternativa que yo había sugerido, self-reference en
  `paquete_plantilla_items`): separar `tours` en tabla propia +
  `proveedor_tarifas.tour_id` (nullable). Reutiliza el patrón que ya usa
  todo el vertical ("lo que se vende siempre es una `proveedor_tarifa`")
  en vez de inventar recursión de paquetes-dentro-de-paquetes.
- Reservado como fila **11b4** en `plan-hoja-de-ruta-ejecucion.md`
  (RETROFIT sobre Sesión 6/11b2 ya mergeadas — sin dependencia dura con
  11b3). Confirmado antes de anotarla: 0 datos reales en cualquier
  tenant (`agencia-demo` es el único con la tabla `paquetes_plantilla`,
  0 filas) — retrofit limpio, sin backfill que proteger.
- Aclarado de paso (no era un gap, ya estaba cubierto): un tour
  improvisado para un cliente puntual que nunca se repite no necesita
  pasar por ningún catálogo — ya lo cubre
  `alternativa_items.origen_tipo='manual'` desde Sesión 11b.
- Sin código tocado en esta conversación — solo diseño y documentación
  (`plan-hoja-de-ruta-ejecucion.md`, esta entrada).

## Prompt de Sesión 11c pre-verificado contra el schema real — 30-jul-2026

- El usuario preparó un prompt completo para 11c (reserva/pasajeros) en
  Claude web, más un patch de docs y un prototipo HTML (`prototipo-
  reserva.html`, layout de 2 columnas: tabs Pasajeros/Ítems/Asignación a
  la izquierda + resumen fijo a la derecha con 2 barras de progreso).
  Antes de guardarlo para cuando se ejecute la sesión, se verificó cada
  asunción contra las migraciones/modelos reales de Sesión 8 — no se
  tomó nada del prompt a ciegas. 6 correcciones reales encontradas,
  todas ya cerradas:
  1. **`cupo_ocupado` no tiene ninguna implementación previa** — el
     prompt pedía "seguir el mismo patrón" de incremento de cupo al
     elegir mayorista, pero `OpcionMayoristaController::elegir()` nunca
     tocó esa columna, es diseño 100% nuevo de 11c. Confirmado además
     que el comentario de la migración de `salidas_mayorista` (Sesión
     7b) ya preveía esto exacto ("mantenido por aplicación cuando una
     opcion_mayorista de una alternativa ACEPTADA se vincula acá —
     Sesión 8/11 — no bloquea vender de más"). Diseño final: `increment()`/
     `decrement()` atómico (evita race condition entre 2 reservas
     aceptándose en simultáneo), `alerta_cupo_excedido: true` como aviso
     no bloqueante en la respuesta (mismo patrón que `alerta_piso` de
     `PriceEngineService`). Pendiente al construir: `Alternativa` no
     tiene relación `opcionMayoristaElegida()` (hay que agregarla o
     consultar `OpcionMayorista::where('alternativa_id',...)
     ->where('estado','elegida')` directo), y `cupo_total` es nullable
     — la comparación debe tratar `null` como "sin límite", no comparar
     `>` a ciegas.
  2. **`reserva_items.fecha` es `NOT NULL`** en la migración real (solo
     `hora` es nullable) — el prompt asumía que ambas podían quedar en
     `null` al crear la reserva. Se agrega `DROP NOT NULL` de `fecha` a
     la migración retrofit de PARTE 0 (junto con `proveedor_tarifa_id`).
  3. **Nombres de columna corregidos**: es `reserva_items.fecha`/`hora`,
     no `fecha_servicio`/`hora_servicio` como decía el prompt original
     (esos nombres no existen en la migración real).
  4. **Sin `doctrine/dbal` instalado** (confirmado: no está en
     `vendor/`) — la migración retrofit NO puede usar `->change()` de
     Blueprint. Mismo patrón ya usado en el proyecto
     (`fix_sales_relax_columns.php`, motivo documentado ahí mismo): SQL
     crudo, `DB::statement('ALTER TABLE reserva_items ALTER COLUMN
     fecha DROP NOT NULL')`. La columna `proveedor_tarifa_id` sí puede
     agregarse con Blueprint normal (no necesita `->change()`).
  5. **`reserva_pasajeros.nombre`/`.documento` son `NOT NULL`** — mismo
     problema que el punto 2, pero en esta tabla. El prompt planea crear
     "shells" vacíos por cada `cotizacion_pasajero` al aceptar — hace
     falta `DROP NOT NULL` de ambas columnas (misma migración retrofit,
     mismo motivo de SQL crudo que el punto 4).
  6. **No existe columna `tipo_pax` en `reserva_pasajeros`** — el
     prompt decía "tipo_pax copiado" al crear los shells, pero no hay
     dónde copiarlo. Se agrega en la misma migración retrofit.
  - Confirmado, sin cambio necesario: `discapacidad` en
    `reserva_pasajeros` es `text` nullable en el schema real — el
    prototipo la muestra como checkbox booleano por simplicidad de
    prueba, pero el form real debe mantenerla como texto libre (permite
    decir QUÉ discapacidad, no solo sí/no) — no copiar el checkbox
    literal.
  - Pendiente de decidir al construir (no bloqueante): el prototipo
    muestra el select de Guía solo en los ítems que "lo necesitan"
    (`necesitaGuia` por ítem, dato inventado del prototipo) —
    `reserva_items` no tiene ninguna columna que distinga eso. Resolución
    más simple: mostrar siempre los 2 selects (Proveedor y Guía) para
    todo ítem, nada en el schema impide que cualquier ítem tenga guía.
  - Confirmado sin problema (el prompt acertó): `AlternativaController::
    update()` ya maneja `estado='aceptada'` (descarta las demás,
    comentario dice literal "eso es Sesión 11c"); `reserva_items.
    alternativa_item_id` existe como FK directa (el backfill de
    `proveedor_tarifa_id` en la migración retrofit SÍ es posible tal
    como lo planteaba el prompt); `PasajeroCatalogo`/`PasajeroDocumento`
    existen igual que los describía; `TenantProvisioningService::
    tieneDatosVerticalAgenciaViajes()` ya cubre transitivamente todas
    las tablas de reserva, sin necesitar chequeo propio nuevo; los 4
    motivos de cancelación del prototipo coinciden exacto con el
    comentario de `reserva.motivo_cancelacion`.
  - Confirmado antes de anotar todo esto: 0 filas reales en
    `reserva_items`/`reserva_pasajeros` en cualquier tenant
    (`agencia-demo` es el único con las tablas) — retrofit limpio.
  - El patch de docs que venía junto (confirma que `guia_id` es
    asignación operativa, no ítem cobrable) tenía el encoding roto
    (UTF-8 doble-codificado, no aplicaba con `git apply`) — se aplicó a
    mano con el contenido verificado, commit `fa9fa01`.
  - `prototipo-reserva.html` guardado fuera del repo (scratchpad de la
    sesión), mismo criterio ya usado para el prototipo de 11b — no se
    commitea al repo.
  - Sin código de la sesión 11c tocado todavía — el prompt corregido
    queda listo para cuando se abra la rama
    `feature/sesion-11c-reserva-pasajeros`.
