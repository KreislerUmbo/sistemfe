# Fase 1b — Catálogo real de roles/permisos de agencia de viajes + scope de fila

> Continuación de Fase 1a (núcleo del menú dinámico, mergeada a `main` en `7994636`/
> `86dfe5b`). Ejecuta plan-modulo-menus-y-roles.md §7 Fase 1b — catálogo real de 5 roles
> (§3.2) + scope de fila por vendedor (§3.3) para el giro `agencia_viajes`. Fecha:
> 10-sep-2026.

---

## Hallazgo real, antes de escribir nada — el backend ya gatea 159 rutas, pero sin
distinguir lectura de escritura

Grep completo de `routes/api.php` confirmó que Cotizaciones/Reservas/Proveedores/
Destinos/Temporadas/Guías/Paquetes/Configuración ya estaban protegidos con
`permission:` real (no es Bucket A/B) — pero con solo 8 permisos PLANOS
(`agencia.cotizaciones`, `agencia.reservas`, etc.), cada uno gateando TODAS las
acciones de su recurso por igual (confirmado con `cotizaciones`: `GET`/`POST`/`PUT`/
`DELETE` llevaban exactamente el mismo `permission:agencia.cotizaciones`). El catálogo
de §3.2 (`cotizaciones.ver`/`ver_todas`/`crear`/`editar`) asume esa distinción — sin
ella, darle a `Contador` el permiso que necesita solo para VER también lo habilitaría a
crear/editar/eliminar vía API directa, lo opuesto de lo que pide §3.2.

**Decisión (confirmada con el usuario, 10-sep-2026): split acotado a Cotizaciones +
Reservas** — las únicas 2 áreas donde §3.2 realmente necesita la distinción ver/crear
(Contador ve_todas sin crear; Vendedor crea con alcance propio). Proveedores/Destinos/
Temporadas/Guías/Paquetes/Configuración quedan con su gate plano actual — §3.2 les da
acceso total o ninguno por rol, no necesitan más.

## Parte 1 — Catálogo de permisos y split de rutas

**91 rutas reescritas** (`routes/api.php`, comentarios `Fase 1b` en cada bloque),
mapeadas ruta por ruta (no por heurística) a `cotizaciones.ver|cotizaciones.ver_todas`
/ `cotizaciones.crear` / `cotizaciones.editar`, mismo patrón para `reservas.*`:

- GET (index/show/reportes/pdf) → `{recurso}.ver|{recurso}.ver_todas` (OR — mismo
  patrón ya usado en Caja, `cash.open_session|cash.view_all`).
- POST que crea una fila nueva del recurso (store, aceptar, duplicar, anticipos) →
  `{recurso}.crear`.
- PUT/DELETE y POST que modifican estado existente (cancelar, reprogramar, facturar,
  checkin, etc.) → `{recurso}.editar` — `eliminar` se fusionó con `editar` a propósito:
  ninguna fila de §3.2 necesita distinguirlos.

**`AgenciaViajesRolesSeeder`** (implementa `RolesCatalogProvider` de Fase 1a, reusable
con `roles:sync-permisos-nuevos`) — siembra los 4 roles de negocio (`Administrador de
agencia`, `Supervisor`, `Vendedor de agencia`, `Contador`) con los permisos exactos de
§3.2/§3.3, más los planos `agencia.cotizaciones`/`agencia.reservas` (que
`menu_items`/Fase 1a siguen usando para VISIBILIDAD del ítem de menú — separado de qué
puede HACER una vez adentro, que ahora gatean los granulares).

**Enganchado en `TenantProvisioningService::provision()`** para `giro=agencia_viajes`,
EN CAPAS ENCIMA de `PermissionsDemoSeeder` (nunca lo reemplaza — agencia_viajes también
usa las tablas 'core' retail que ese seeder cubre). Decisión explícita (confirmada con
el usuario): esto deja roles retail sin usar (`Jefe de Ventas`/`Jefe de Almacén`/
`Cajero`/`Vendedor`/`Cliente`) en tenants agencia_viajes — clutter cosmético, más seguro
que tocar `PermissionsDemoSeeder` (lo usan tenants retail reales hoy) solo para
evitarlo.

## Parte 2 — Diagnóstico real de `agencia-demo` (solo lectura)

| Rol real | Usuarios | Rol del catálogo nuevo más parecido | Nota |
|---|---|---|---|
| `Super-Admin` | 2 (`admin@agencia-demo.test`, `test.perfil.agencia@sandbox.local`) | `Super-Admin` (sin cambios) | Bypasea todo vía `Gate::before()`. |
| `Admin-General` | 1 (`admin@gmail.com`) | `Administrador de agencia` | **Ver hallazgo urgente abajo.** |
| `Contador`/`Jefe de Ventas`/`Jefe de Almacen`/`Cajero`/`Vendedor`/`Cliente` (de `PermissionsDemoSeeder`) | 0 cada uno | — | Sin usuarios reales asignados, no hay nada que migrar. |

`sandbox`/`umbo`/`negocio2`: **cero** roles con algún permiso `agencia.*` (confirmado
con query directa) — el split de rutas no los afecta en absoluto.

### ⚠️ Hallazgo urgente: mergear el split de rutas tal cual rompe a `Admin-General`

`Admin-General` (el único admin operativo real de `agencia-demo`) tiene los 8 permisos
planos `agencia.*` pero NINGUNO de los granulares nuevos — como las rutas ya no chequean
el plano, mergear esto sin backfill le bloquearía Cotizaciones y Reservas por completo.
Mismo tipo de regresión que Fase 0b encontró con este mismo rol antes de mergear.

**Preparado, NO ejecutado contra `agencia-demo`:** `permisos:backfill-fase1b-agencia-
viajes {tenant}` — corre `AgenciaViajesRolesSeeder` + agrega el set granular completo
(`ver`/`ver_todas`/`crear`/`editar`) a cualquier ROL AJENO al catálogo que ya tuviera el
permiso plano (preserva el acceso que ya tenía, sin restringir su alcance — decidir un
alcance más fino para un rol real es decisión de negocio, no algo que el comando deba
asumir).

**Bug real encontrado y corregido en el propio comando** (verificación en vivo contra
`sandbox`, no por ningún test): el backfill escaneaba TODOS los roles con el permiso
plano, incluidos `Contador`/`Vendedor de agencia` que el propio `AgenciaViajesRolesSeeder`
acaba de sembrar con un alcance deliberadamente restringido — les daba el set completo
(`ver_todas`/`crear`/`editar`), pisando la restricción de §3.2. Corregido: el backfill
ahora EXCLUYE explícitamente los roles que el catálogo ya administra. Test de regresión
agregado.

## Parte 3 — Scope de fila (§3.3)

### Hallazgo real, bloqueante hasta que se resolvió: `vendedor_id` no existía en
ninguna tabla

Ni `cotizaciones` ni `reserva` tenían ninguna columna que registrara quién creó la
fila — confirmado con grep completo de `app/Models/AgenciaViajes/`. El diseño de §3.3
(`where('vendedor_id', auth()->id())`) asumía una columna que no existía en el código
real. **Decisión (confirmada con el usuario): migración aditiva.**

- `2026_09_10_140000_add_vendedor_id_to_cotizaciones_table.php` — `vendedor_id`
  nullable, FK a `users`. Nullable a propósito: cotizaciones ya creadas antes de esta
  fase quedan sin dueño (visibles solo para roles con `ver_todas`), no se inventa un
  valor retroactivo.
- `Reserva` NO recibe columna propia — hereda el vendedor de su Cotización padre vía
  join (`alternativa.cotizacion.vendedor_id`), mismo criterio que ya usa el proyecto
  para no duplicar un dato que ya vive arriba en la cadena.
- `CotizacionController::store()`/`VentaDirectaController::store()` setean
  `vendedor_id = auth()->id()` al crear.

### Segundo hallazgo real, más serio: un Global Scope rompe lógica interna real

El diseño original de §3.3 pedía un Global Scope. Implementado así, **rompió 38 tests
reales** (`ReservaController::aceptar()`/facturación/anticipos leen `$alternativa
->cotizacion` como parte de su lógica de negocio interna — generar el código
correlativo de la reserva, por ejemplo — no como una consulta de visibilidad de
usuario). Con Global Scope, esa relación devolvía `null` para cualquier usuario sin
`ver_todas`, aunque la fila fuera legítimamente la que el usuario ya estaba operando.

**Decisión (confirmada con el usuario): scope LOCAL, no global.** `EscopablePorVendedor`
expone `propias()` como scope local (`Model::propias()->...`) — SOLO
`CotizacionController::index()`/`ReservaController::index()` lo llaman explícitamente.
El resto del código (show/update/delete, acciones internas, servicios) ve la fila real
sin filtrar; la protección de fila para esos casos es la Policy, que en el diseño
original era la "segunda barrera" y ahora es la barrera PRINCIPAL para todo lo que no
es listado.

### `CotizacionPolicy`/`ReservaPolicy` — segunda barrera explícita

Wireadas en `CotizacionController::show()/update()/destroy()` y
`ReservaController::show()/cancelar()`. Grep completo confirmó que hoy NO existe ningún
`withoutGlobalScopes()` ni `DB::table('cotizaciones'|'reserva')` crudo en todo el
código — la cobertura de la Policy es hoy puramente defensiva (nada la dispara en la
práctica), no un hueco real que estuviera abierto.

**Bug real encontrado al testear** (no por asunción): `ReservaPolicy::view()` leía
`$reserva->alternativa->cotizacion->vendedor_id` — pero `cotizacion` es una relación
Eloquent normal, y como `Cotizacion` SÍ tenía en ese momento su scope, la relación
devolvía `null` para el mismo usuario que la Policy tenía que poder BLOQUEAR. Corregido
con `Cotizacion::withoutGlobalScope('vendedor')` explícito dentro de la Policy — el
chequeo de dueño necesita ver la fila real sin importar si el scope normal la
escondería (irónico dado que el Global Scope se retiró después, pero el fix sigue
siendo necesario porque `withoutGlobalScope()` en un scope que ya no existe es un no-op
inofensivo — se deja documentado por si `propias()` alguna vez se registra como global
de nuevo).

### Reportes/dashboards agregados — inventario (no resuelto, a propósito)

`ReporteOperativoController`/`SalidaOperativaController::resumenSalida()` usan Eloquent
puro (sin queries crudas) pero NO llaman `->propias()` — cualquier usuario con
`reservas.ver` (incluido `Vendedor de agencia`) ve TODAS las reservas/salidas en esos
reportes, no solo las propias. Es plausible que sea el comportamiento CORRECTO (son
herramientas operativas/logística — ¿un Vendedor necesita ver el cronograma completo de
buses aunque no sean sus clientes?), no un descuido — pero es una decisión de negocio,
no algo que este brief deba asumir. Queda documentado, sin resolver.

## Parte 4 — Test de matriz de permisos

`MatrizPermisosAgenciaViajesTest` — 64 filas (4 roles × 16 combinaciones de
recurso/acción), parametrizado vía `DataProvider`, contra el catálogo REAL sembrado por
`AgenciaViajesRolesSeeder` (no una copia paralela) — un cambio futuro que rompa §3.2/
§3.3 se detecta acá antes de mergear.

## Parte 5 — Aplicación real (✅ EJECUTADA, 10-sep-2026, autorizada explícitamente)

Orden real: migración `vendedor_id` primero, después el backfill.

1. `php artisan tenants:migrate --tenants=agencia-demo --path=.../2026_09_10_140000_add_vendedor_id_to_cotizaciones_table.php` —
   columna confirmada presente después (`Schema::getColumnListing('cotizaciones')`).
2. `php artisan permisos:backfill-fase1b-agencia-viajes agencia-demo` — salida real:
   ```
   Admin-General: +cotizaciones.ver,cotizaciones.ver_todas,cotizaciones.crear,cotizaciones.editar (tenía agencia.cotizaciones)
   Admin-General: +reservas.ver,reservas.ver_todas,reservas.crear,reservas.editar (tenía agencia.reservas)
   ```
   Ningún otro rol tocado (confirma que el fix del bug de sobre-otorgamiento sostiene
   contra datos reales, no solo en `sandbox`).

**Verificación real posterior** (evidencia, no "debería funcionar"):
- `Admin-General`: 58 → 66 permisos (ganó exactamente los 8 granulares, nada se le
  quitó). `Contador`/`Vendedor de agencia`/`Supervisor`/`Administrador de agencia`
  (roles nuevos, recién sembrados) quedaron con el alcance EXACTO de §3.2/§3.3 —
  `Contador` sin `cotizaciones.crear`/`reservas.crear`, `Vendedor de agencia` sin
  `*.ver_todas` ni `reservas.editar`.
- **Ninguna asignación de usuario→rol cambió** — los 3 usuarios reales
  (`admin@agencia-demo.test`/`test.perfil.agencia@sandbox.local` = `Super-Admin`,
  `admin@gmail.com` = `Admin-General`) siguen con el mismo rol que tenían antes.
- `GET /api/cotizaciones` y `GET /api/reservas` con JWT real de `admin@gmail.com` (vía
  tinker, sin resetear password) contra el servidor real: **200** en ambas — confirma
  que el split de rutas + el backfill dejaron a `Admin-General` con acceso real
  funcionando, no solo el permiso en la tabla.
- `sandbox`/`umbo`/`negocio2`: no tocados (ningún permiso `agencia.*` ahí, confirmado
  antes de empezar).

## Verificación

97 tests nuevos (`AgenciaViajesRolesSeederTest` 3, `BackfillFase1bAgenciaViajesCommandTest`
4, `EscopablePorVendedorTest` 5, `MatrizPermisosAgenciaViajesTest` 64, más ajustes a 3
tests preexistentes que nunca habían necesitado autenticar un usuario antes de esta
fase). Suite completa verde salvo la familia `TipoCambioSunat*` (6-7 fallos, varían
entre corridas — confirmado no relacionado, depende de HTTP externo a SUNAT/Decolecta,
mismo hallazgo ya documentado en Fase 0b/0c/1a).
