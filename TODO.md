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

## Sesión 11b (Cotizador) — decisión pendiente antes de escribir migraciones — 28-jul-2026

- **`cotizacion_pasaje_aereo.aerolinea`: ¿texto libre o FK a `proveedores`
  con un tipo `Aerolínea` nuevo en `proveedor_tipos`?** Surgió al diseñar
  el motor de precios para pasajes aéreos sueltos (ver
  `plan-modulo-cotizaciones-reservas.md` §2.5). Texto libre es más simple
  y consistente con `paquetes_plantilla.vuelo_aerolinea`/
  `opcion_mayorista.vuelo_aerolinea` (ambos ya son texto libre); FK
  permite reportar comisión/volumen por aerolínea más adelante. No
  bloquea nada de Sesión 11a — se confirma antes de escribir la migración
  de `cotizacion_pasaje_aereo` en 11b.
- **`alternativa_items.cantidad` todavía no existe en ninguna migración
  corrida** (Sesiones 0-10 son anteriores a este hallazgo). Se agregó al
  documento de diseño (`plan-modulo-cotizaciones-reservas.md` §3) el
  28-jul-2026, encontrado probando el prototipo HTML del cotizador — un
  hotel se cobra por noche, un transporte privado puede pedirse en más de
  un vehículo. La migración ALTER TABLE que la agrega queda para Sesión
  11b, no es retroactiva a Sesión 7 (no hay datos reales en producción
  todavía que se vean afectados).
