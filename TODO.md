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
- **`TenantProvisioningService::eliminarSiVacio()` no conoce las tablas
  del vertical agencia_viajes** (`proveedores`, `destinos_atractivos`,
  `destino_servicio`, `servicios`, `guias`, `proveedor_tipos_config`,
  `configuracion_agencia`) — solo chequea Company/SunatConfig/Client/
  Product/Sale (código de antes de que este vertical existiera). Un
  tenant `agencia_viajes` con proveedores/destinos reales cargados pero
  sin Company/cliente/producto/venta todavía se sigue considerando
  "vacío" y se puede borrar de verdad desde el botón "Eliminar" del
  panel superadmin — pérdida de datos real, no solo cosmética. Detectado
  al usar el método para limpiar un tenant de prueba en esta sesión (ahí
  es el comportamiento correcto — es lo que hace justamente inseguro el
  caso real). Pendiente: agregar esos conteos a la condición de
  `eliminarSiVacio()` cuando se retome el panel superadmin o alguna
  sesión de agencia_viajes toque este método.
