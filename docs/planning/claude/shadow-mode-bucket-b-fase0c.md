# Fase 0c — Modo "sombra", Bucket B (rutas operativas)

> Continuación directa de Fase 0b (`docs/planning/claude/gate-bucket-a-fase0b.md`, Bucket A
> ya gateado y mergeado a `main` en `2881c63`) y de `plan-modulo-menus-y-roles.md` §9.1, pasos
> 2-4 de la estrategia de rollout. Ejecuta solo la **Parte 1 (modo sombra)** de esas 31 rutas
> — Partes 2 (backfill) y 3 (gate real) quedan sin ejecutar a propósito, esperando un período
> real de observación y autorización explícita. Fecha: 10-sep-2026.
> **✅ Parte 1 MERGEADA a `main`** (rama `feat/shadow-mode-bucket-b-rutas-operativas`, merge
> `fecf4d1`, 10-sep-2026, sin conflictos pese a solaparse con Fase 1b en `routes/api.php`).
> Confirmado 11-sep-2026: `permission_shadow_logs` sigue en 0 filas en `umbo`/`agencia-demo` —
> Partes 2/3 siguen sin arrancar, esperando que pase una ventana de uso real (ver
> `project_shadow_mode_bucket_b_fase0c` en memoria antes de asumir que ya hay datos).

## Nota sobre el brief

Mismo desajuste de ubicación que en Fase 0/0b: el brief citaba
`docs/planning/claude/plan-modulo-menus-y-roles.md` §9.1 — el plan real vive en
`docs/planning/plan-modulo-menus-y-roles.md` (sin el subdirectorio `claude/`). Se siguió el
brief tal como fue pegado (autocontenido) y se documenta acá la discrepancia, igual que las
2 veces anteriores.

## Parte 1 — Confirmación de las 31 rutas de Bucket B

Se releyó `routes/api.php` línea por línea contra la clasificación de
`gate-bucket-a-fase0b.md` (no se asumió que seguía igual) — **sin drift**: las 31 rutas están
exactamente donde y como se documentaron hace un día. Ningún cambio de firma, ninguna ruta
movida o eliminada.

## Parte 2 — Mapeo ruta → permiso Spatie

Criterio de prioridad: (1) reutilizar un permiso que YA existe y que el frontend ya referencia
para esa acción exacta (`admin-start-kit/src/types/roles.ts`, catálogo real de Roles) — mismo
criterio que Fase 0b; (2) si no existe ninguno, crear uno nuevo siguiendo el estilo ya usado en
el módulo más cercano (snake `register_X`/`edit_X`/`delete_X` para recursos CRUD clásicos,
hyphenated `accion-recurso-credito` para el módulo de Créditos/Amortizaciones, ya establecido
por `anular-cuota-credito`/`anular-pago-credito`/etc.).

Confirmado contra Postgres real (`sandbox`/`umbo`/`agencia-demo`, no supuesto) qué permisos
existían antes de esta fase — ver consulta completa más abajo.

| Recurso/ruta(s) | Permiso | ¿Existía antes? |
|---|---|---|
| `categories` (4) | `register_categorie`/`edit_categorie`/`delete_categorie` | Sí, reutilizado |
| `products` (4) | `register_product`/`edit_product`/`delete_product` | Sí, reutilizado |
| `clients` (3) | `register_client`/`edit_client`/`delete_client` | Sí, reutilizado |
| `clients/{client}/payments` (2: preview+store) | `registrar-pago-credito` | **Nuevo** |
| `sales/index` (1, filtro vía POST) | `list_sale` | Sí, reutilizado |
| `sales` store/update/destroy (3) | `register_sale`/`edit_sale`/`delete_sale` | Sí, reutilizado |
| `sale_details` (3) | `register_sale_detail`/`edit_sale_detail`/`delete_sale_detail` | **Nuevo** — sin caller real en el frontend (confirmado en Fase 0b), permisos dedicados igual, mismo criterio que el resto |
| `sale_payments` (3) | `register_sale_payment`/`edit_sale_payment`/`delete_sale_payment` | **Nuevo** — ídem |
| `enviarSunat` (1) | `enviar_sunat` | **Nuevo** — distinto de `emitir_boleta`/`emitir_factura`/`emitir_nota_venta` (esos gatean qué TIPO de documento se puede crear, validado en `SaleController::store()`; el ENVÍO a SUNAT en sí nunca tuvo permiso propio) |
| `installments/schedule-preview` (1, sin venta persistida todavía) | `register_sale` | Reutilizado — mismo flujo de "registrar una venta a crédito" en `register.vue` |
| `installments/{installment}` update (1) | `editar-cuota-credito` | **Nuevo** — distinto de `anular-cuota-credito` (esa ya existe y anula la cuota; esta edita fecha/monto sin anularla) |
| `sales/{sale}/installments` preview+store (2, sobre venta YA existente) | `registrar-cronograma-credito` | **Nuevo** |
| `notas` store/preview/enviar-sunat (3) | `nota_electronica` | Sí, reutilizado — las 3 son la misma acción de negocio en 3 pasos de la misma pantalla |

Total: 4+4+3+2+1+3+3+3+1+1+1+2+3 = 31. ✅

**Deliberadamente NO se agregaron los 8 permisos nuevos a `PermissionsDemoSeeder.php` ni al
catálogo `roles.ts` del frontend en esta fase** — a diferencia de Fase 0b, que sí los agregó
en la misma sesión donde activaba el gate real. Acá el gate real todavía no existe (Parte 3,
pendiente), así que no hace falta que sean asignables desde la UI de Roles todavía; agregarlos
ahora solo generaría checkboxes nuevos en pantalla que no hacen nada. Quedan documentados acá
y se agregan recién en la Parte 3, junto con la activación real.

## Parte 3 — Middleware "modo sombra"

**`app/Http/Middleware/ShadowPermissionMiddleware.php`** (alias `shadow.permission`, registrado
en `bootstrap/app.php`) — nunca bloquea, nunca lanza excepción por un permiso faltante:

- Resuelve el usuario con `Auth::guard('api')->user()`, no `$request->user('api')` — mismo
  mecanismo que usa `Spatie\Permission\Middleware\PermissionMiddleware::handle()` de verdad
  (confirmado leyendo su código). Importante: `$request->user()` depende de un resolver que
  solo se registra sobre la instancia de Request bindeada al contenedor durante el ciclo HTTP
  real — no sobre un `Request::create()` construido a mano en tests, ni necesariamente en
  todos los contextos. `Auth::guard('api')->user()` es robusto en ambos casos.
- Compara contra `$user->getAllPermissions()->contains('name', $permiso)`, no
  `hasPermissionTo()`/`can()` — esos métodos de Spatie lanzan `PermissionDoesNotExist` si el
  nombre no existe todavía como fila en `permissions` (8 de los 13 permisos de este mapeo son
  nuevos, sin fila creada en ningún tenant real todavía). Un throw ahí rompería la request real
  que el modo sombra no debe tocar. `getAllPermissions()` nunca lanza.
- **Excluye a Super-Admin del log a propósito**: bypasea cualquier gate real vía
  `Gate::before()` (`AppServiceProvider`) — loguearlo sería ruido, nunca representa un bloqueo
  que vaya a ocurrir de verdad en la Parte 3.
- Loguea en `permission_shadow_logs` (tabla nueva, `App\Models\PermissionShadowLog`,
  migración `database/migrations/tenant/core/2026_09_10_120000_create_permission_shadow_logs_table.php`,
  aplicada a los 5 tenants existentes — `sandbox`/`umbo`/`agencia-demo`/`negocio2`/
  `umbo-archivado`). Campos: `tenant_id`, `user_id`, `user_email` (denormalizado, sobrevive si
  el usuario se borra), `metodo_http`, `ruta` (patrón, ej. `api/sales/{sale}`, no la URL
  resuelta), `permiso_faltante`, timestamps.
- El propio `create()` está envuelto en `try/catch` — si loguear falla (ej. migración no
  corrida todavía en algún tenant), la request real sigue de largo igual, con un
  `Log::warning()` para no perder la señal de que el log falló.

Aplicado a las 31 rutas de Bucket B en `routes/api.php` — cada una lleva
`shadow.permission:X` (nunca `permission:` real, eso es exclusivo de Bucket A todavía).

## Parte 4 — Test de regresión

**`tests/Feature/ShadowPermissionMiddlewareTest.php`**, mismo criterio de doble capa que
`GateBucketARoutesTest` (Fase 0b) más una tercera capa específica de "nunca bloquea":

1. Por cada una de las 31 rutas: la ruta REAL registrada lleva `shadow.permission:X` — y
   explícitamente NO lleva ningún `permission:` real (falla si alguien mezcla modo sombra con
   gate real antes de la Parte 3).
2. Por cada una de las 31 rutas: con permiso y sin permiso, `$next()` devuelve exactamente el
   mismo valor — el resultado observable de la ruta NUNCA cambia.
3. El log se crea solo cuando falta el permiso, con los campos esperados; con el permiso, cero
   filas nuevas.
4. Super-Admin nunca genera log aunque le falte el permiso.

64 tests nuevos, suite completa **705/711 verde** — los 6 fallos son
`TipoCambioSunat*`/`SincronizarTipoCambioSunatCommandTest`, ya documentados como pre-existentes
y no relacionados (fallan igual sin este cambio, confirmado también así en Fase 0b).

## Parte 5 — Despliegue y verificación con tráfico real

Migración aplicada primero a `sandbox`, verificada, recién después a `umbo`/`agencia-demo`
(más `negocio2`/`umbo-archivado` por completitud, aunque no son tenants reales de negocio).

**Verificación real contra los 3 tenants** (JWT generado vía tinker para usuarios reales
existentes — nunca reseteo de password, ver `feedback_no_resetear_passwords_sin_verificar`),
con payloads vacíos elegidos a propósito para no crear ningún dato real:

- **`sandbox`**, Cajero Test 2 (`cajero2.test@sandbox.local`, rol Cajero, permisos reales:
  `cash.open_session,dashboard,delete_sale,edit_sale,list_sale,register_sale` — ya diverge del
  snapshot de `PermissionsDemoSeeder`, confirmando el valor real de auditar contra Postgres en
  vez de contra el seeder): `POST /api/sales` (tiene `register_sale`) → 422, sin log nuevo.
  `POST /api/products` (no tiene `register_product`) → 500 (bug preexistente no relacionado:
  `products.title` NOT NULL sin validación previa, confirmado en `laravel.log`, no causado por
  este cambio) — log nuevo con `permiso_faltante=register_product`, exactamente 1 fila.
- **`umbo`**, Jose Ricardo/Contador (`pinedo@gmail.com`, permisos reales incluyen
  `register_sale`/`nota_electronica` pero no `register_client`): `POST /api/clients` → 500 (sin
  crear ningún cliente real, confirmado con query directa) + 1 log con
  `permiso_faltante=register_client`; `POST /api/notas/preview` (sí tiene `nota_electronica`) →
  404, sin log nuevo.
- **`agencia-demo`**, Admin-General (`admin@gmail.com`, permisos reales incluyen
  `register_client`/`register_sale` pero no `register_product`): `POST /api/products` → 500 +
  1 log con `permiso_faltante=register_product`; `POST /api/clients` (sí tiene
  `register_client`) → 500 también (mismo tipo de bug preexistente, columna NOT NULL sin
  validar) pero **sin log nuevo** — confirma que el modo sombra distingue correctamente "tiene
  permiso" de "no tiene permiso" incluso cuando ambas respuestas HTTP terminan siendo el mismo
  código de error por otra razón.

En los 3 tenants: la respuesta HTTP fue exactamente la que hubiera sido sin este cambio (nunca
403), no se creó ningún registro real, y el log de modo sombra distinguió correctamente los 2
casos. Las filas de esta verificación se truncaron después de confirmar (`PermissionShadowLog::
truncate()` en los 3 tenants) — la ventana de observación real arranca en 0 filas, sin ruido de
tráfico sintético de esta sesión.

## Estado al cierre de esta sesión

**Modo sombra ACTIVO en los 5 tenants** (incluido `umbo`/`agencia-demo`), registrando sin
bloquear desde 2026-09-10. **No se ejecutó la Parte 2 (backfill) ni la Parte 3 (gate real)** —
requieren, en ese orden: (a) que pase un período real de uso representativo (no unas horas —
ver nota del brief sobre cierres de mes/fines de semana con patrón de uso distinto), (b)
consultar `permission_shadow_logs` de `umbo`/`agencia-demo` y armar la tabla completa de
usuario/ruta/permiso faltante, (c) mi autorización explícita antes de otorgar cualquier permiso
o activar cualquier gate real contra esos 2 tenants.

**Cómo consultar el log en cualquier momento** (ejemplo, sombra tenant):

```php
$tenant = \App\Models\Tenant::find('umbo'); // o 'agencia-demo'
tenancy()->initialize($tenant);
\App\Models\PermissionShadowLog::select('user_email', 'ruta', 'permiso_faltante')
    ->selectRaw('count(*) as veces')
    ->groupBy('user_email', 'ruta', 'permiso_faltante')
    ->orderByDesc('veces')
    ->get();
tenancy()->end();
```
