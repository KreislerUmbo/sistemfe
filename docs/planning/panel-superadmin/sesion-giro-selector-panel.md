# Brief para Claude Code — Gestión completa de un tenant desde el panel (giro, edición, password del admin)

> Pégale este archivo completo a una sesión nueva de Claude Code sobre el
> repo `sistemfe`. Referencia exacta: `docs/planning/panel-superadmin/gap-selector-giro-tenant.md`
> (léelo primero, tiene toda la evidencia del gap, incluida la investigación
> de código exacta que sustenta cada punto de abajo) y
> `docs/planning/panel-superadmin/plan-panel-superadmin.md` (contexto
> general del panel, Fase D Paso 4 en particular — ahí se construyó el
> formulario de creación que este brief extiende).

## Por qué este brief es más grande que "agregar un selector"

El pedido original era solo el selector de `giro` al crear un tenant. Se
amplió el mismo día: **todo el ciclo de vida de un tenant debe manejarse
desde el panel, sin pasos manuales por CLI/SSH/tinker.** Esto significa
tres piezas, no una:

1. Elegir `giro` al crear (alcance original).
2. Editar un tenant ya creado — incluido corregir su `giro` después de
   creado, con la migración retroactiva del vertical corriendo sola.
3. Restablecer el password del usuario administrador de un tenant.

Las tres comparten archivos (`TenantAdminController.php`,
`TenantDetailView.vue`, el store de tenants), así que conviene una sola
sesión que las resuelva juntas en vez de tres sesiones separadas
pisándose los mismos archivos.

## Qué pasa hoy (confirmado leyendo código real, no asumido)

### Creación — sin `giro`

- `TenantAdminController::store()` no valida ni recibe `giro` en el
  request (solo `ruc`, `razon_social`, `razon_social_comercial`, `domain`,
  `admin_name`, `admin_email`, `admin_password`).
- `TenantAdminController::serialize()` no lo devuelve — ni el listado ni el
  detalle de un tenant lo pueden mostrar hoy.
- El formulario "Nuevo tenant" en `TenantListView.vue` no tiene el campo.
- El único lugar donde `giro` sí funciona es la CLI:
  `php artisan tenants:provision --giro=...`
  (`app/Console/Commands/ProvisionTenant.php`, lista blanca
  `GIROS_VALIDOS = ['retail', 'agencia_viajes']`).

### Edición — no existe en absoluto

- No hay ruta `PUT`/`PATCH central/tenants/{id}` en `routes/api.php`
  (bloque `central`, guard `auth:central` + `central.token`). Las únicas
  rutas de tenant son: `GET/POST tenants`, `GET tenants/{id}`,
  `POST tenants/{id}/archive`, `POST tenants/{id}/restore`,
  `DELETE tenants/{id}`.
- `TenantAdminController` no tiene `update()`/`edit()`. Métodos completos
  hoy: `index()`, `store()`, `show()`, `archive()`, `restore()`,
  `destroy()`, `resolveTenant()` (privado), `serialize()` (privado).
- `TenantDetailView.vue` (127 líneas) es de solo lectura: header + 6 tabs
  (Company, Suscripción, Backups, SunatConfig, Certificado, Test-emisión).
  Sin modo edición.
- El store `central-panel/src/stores/tenants.ts` (604 líneas) solo expone
  `createTenant` — no `updateTenant`.

### Password del admin de un tenant — no existe ningún reset

- No hay `resetPassword`/`changePassword` ni nada equivalente a nivel
  central (buscado en `routes/api.php` y en todo `TenantAdminController`).
- Referencia de cómo se crea ese usuario hoy, en
  `TenantProvisioningService::provision()`, dentro de
  `$tenant->run(function () use ($adminName, $adminEmail, $adminPassword) {...})`
  — **este es el wrapper real del repo para entrar al contexto de BD de un
  tenant, no `tenancy()->initialize()/end()` directo**:
  ```php
  $role = Role::where('name', 'Super-Admin')->where('guard_name', 'api')->first();
  $admin = User::create(['name' => $adminName, 'email' => $adminEmail, 'password' => $adminPassword]);
  $admin->assignRole($role);
  ```
  El rol del admin de un tenant es **`Super-Admin`**, guard `api`.
  `App\Models\User` tiene `password` en `casts()` como `'hashed'`, así que
  **asignar el password en texto plano + `save()` ya lo hashea solo** — no
  llamar `bcrypt()`/`Hash::make()` a mano.
- **Bug colateral encontrado, NO arreglar en este mismo fix, solo anotarlo:**
  `app/Http/Controllers/User/UserController.php::update()` (nivel tenant,
  autenticado) tiene esta línea con la condición aparentemente invertida:
  `if (!$request->password) { $request->merge(["password" => bcrypt($request->password)]); }`
  — solo re-hashea cuando el campo viene vacío. No copiar este patrón. Si
  quieres, repórtalo aparte al usuario al terminar, pero no lo toques en
  esta sesión — no es parte del alcance.

## Qué construir

### 1. Backend — `app/Http/Controllers/Central/TenantAdminController.php`

**1a. `store()` — agregar `giro`:**
```php
'giro' => 'required|in:retail,agencia_viajes',
```
(Evalúa extraer la lista blanca a un solo lugar compartido — hoy vive
duplicada en `ProvisionTenant.php`; considera un enum o una constante en
`TenantProvisioningService` que ambos, más el `update()` nuevo, usen.)
Pasar `giro` dentro de `$data` hacia
`$this->provisioningService->provision($data)` — el servicio ya sabe qué
hacer con él (`migrarVertical()`), no debería requerir cambios ahí.

**1b. `serialize()` — agregar `giro`, `tipo`, `sunat_modo`** — hoy ninguno
de los tres se expone, y hacen falta para que el frontend los muestre.

**1c. Método nuevo `update(Request $request, $id)`:**
- Resuelve el tenant igual que `show()`/`archive()` (reusa
  `resolveTenant()` si aplica).
- Valida al menos `razon_social`, `razon_social_comercial`, `giro`
  (`sometimes|in:retail,agencia_viajes`). **`domain` queda fuera de este
  fix a propósito** — cambiar el dominio de un tenant ya provisionado toca
  el registro de `stancl/tenancy` y es un caso más delicado; no lo agregues
  acá.
- Si `giro` viene en el request y **es distinto** del valor actual
  guardado: primero actualiza la columna, y en el mismo flujo dispara la
  migración retroactiva del vertical nuevo (reusa la lógica de
  `TenantProvisioningService` que ya usa `tenants:migrate-verticales` /
  `migrarVertical()` — revisa cuál de los dos métodos aplica mejor a un
  tenant individual vs. todos; si `migrarVertical()` ya opera por tenant,
  probablemente es el que corresponde acá en vez de disparar el comando
  completo). **Sin este paso, cambiar solo la columna deja el tenant
  inconsistente** (columna correcta, tablas del vertical inexistentes) —
  es el mismo problema que motivó este brief, no lo repitas.
- Devuelve el tenant serializado actualizado.

**1d. Método nuevo `resetAdminPassword(Request $request, $id)`:**
- Valida `new_password` (`required|string|min:8`).
- Resuelve el tenant, y dentro de `$tenant->run(function () use ($newPassword) {...})`:
  busca el usuario con rol `Super-Admin` (guard `api`) — si no existe,
  responde 404/422 explícito, no un error genérico; si existe, asigna
  `$admin->password = $newPassword; $admin->save();` (el cast hashea
  solo) y confirma.
- Si el repo ya tiene un audit-log central (hay rutas de audit-log en el
  bloque `central` de `routes/api.php` — confírmalo), registra esta acción
  ahí: es un cambio sensible (contraseña de un admin de tenant), conviene
  que quede trazado quién lo hizo y cuándo.

### 2. Rutas — `routes/api.php` (bloque `central`)

Agregar:
```php
Route::put('tenants/{id}', [TenantAdminController::class, 'update']);
Route::post('tenants/{id}/reset-admin-password', [TenantAdminController::class, 'resetAdminPassword']);
```
(Ajusta el método HTTP de `update` a `PATCH` si el resto del proyecto usa
esa convención — revisa cómo están las demás rutas de este bloque antes de
decidir.)

### 3. Frontend — `central-panel/src/views/TenantListView.vue`

- Formulario "Nuevo tenant": agregar un `<select>` de `giro` con las
  opciones `retail`/`agencia_viajes` (texto legible: "Retail / Facturación"
  y "Agencia de Viajes"), campo requerido, sin default preseleccionado
  (fuerza a elegir a propósito, evita que alguien cree por error otro
  tenant `retail` sin darse cuenta).
- Tabla de listado: agregar columna o badge de giro (mismo patrón visual
  que el badge de `status` que ya existe ahí).

### 4. Frontend — `central-panel/src/views/TenantDetailView.vue`

- Agregar un modo edición (botón "Editar" en el header, o un tab nuevo
  "Datos generales" si el layout de tabs actual se presta mejor — decide
  según cómo esté armado hoy) con los campos editables del punto 1c:
  `razon_social`, `razon_social_comercial`, `giro`. Si el usuario cambia el
  `giro`, muestra una confirmación explícita antes de guardar (algo como
  "Esto va a aplicar retroactivamente las migraciones del vertical X a
  este tenant. ¿Confirmas?") — es una operación con efecto real sobre la
  base de datos del tenant, no debería poder pasar por accidente con un
  solo click.
- Agregar una sección/botón separado — **no mezclado con el formulario de
  edición anterior** — para "Restablecer contraseña del administrador":
  input de password nuevo + botón "Generar" (reusa el mismo patrón que ya
  existe en el formulario de creación de `TenantListView.vue`) + botón
  "Guardar", con su propia confirmación. Mantenerlo separado es a
  propósito: es una acción más sensible y así queda más fácil de auditar
  qué se hizo y cuándo.

### 5. Store — `central-panel/src/stores/tenants.ts`

Agregar `updateTenant(id, payload)` y
`resetTenantAdminPassword(id, newPassword)`, siguiendo el mismo patrón que
ya usa `createTenant`.

### 6. Tipos TypeScript

Actualizar la interfaz de tenant en `central-panel/src/types/` (donde sea
que viva hoy el tipo que usa `store.tenants`) para incluir `giro`, `tipo`,
`sunat_modo`.

## Verificación esperada (mismo estándar que el resto del proyecto — HTTP real, no solo lectura de código)

- Crear un tenant descartable real vía HTTP con `giro=agencia_viajes`,
  confirmar que `tenants.giro` en la base central quedó bien, y que la
  migración del vertical corrió de verdad sobre la base física de ese
  tenant (confirmar con `\d` o consultando `information_schema` que las
  tablas del vertical existen ahí).
- Confirmar que un `giro` inválido (ej. `hoteles`, que no existe en la
  lista blanca) da 422 explícito, no error genérico, tanto en `store()`
  como en `update()`.
- **Editar el `giro` de ese mismo tenant descartable** de `agencia_viajes`
  a `retail` (o viceversa) vía el endpoint nuevo, y confirmar que las
  tablas del vertical correspondiente aparecen retroactivamente en su base
  física — este es el caso que más importa probar, es el que resuelve el
  problema real de `market`.
- Restablecer el password del admin de ese tenant descartable vía el
  endpoint nuevo, y confirmar con un login real (o consultando el hash en
  la BD del tenant) que efectivamente cambió.
- Confirmar que el listado y el detalle muestran el giro correcto para
  tenants existentes de ambos giros.
- Destruir el tenant descartable al final (mismo criterio que el resto del
  proyecto — no dejar artefactos de prueba).
- `vue-tsc -b` limpio en `central-panel/`.

## Pendiente aparte, NO parte de este fix — decidir con el usuario antes de tocar nada

El tenant `market.umbosystem.com` **confirmado** creado desde el
formulario del panel (no por CLI) — dato ya verificado con el usuario, no
es una sospecha. Es candidato altamente probable a tener `giro='retail'`
por defecto aunque el negocio real sea otro.

**Una vez que este fix esté implementado y probado**, el camino correcto
para corregir `market` (si hace falta) es usar el propio flujo de edición
nuevo del punto 1c/4 — entrar a su detalle en el panel y cambiarle el giro
ahí, dejando que el propio backend corra la migración retroactiva. **No
hace falta SQL manual una vez que este fix exista** — es exactamente el
problema que este brief resuelve. Antes de hacerlo:

1. Confirmar cuál es el giro real de `market.umbosystem.com` (chequeo
   directo, o simplemente mirándolo ya en el panel una vez que el punto 1b
   esté implementado y muestre el campo).
2. Confirmar con el usuario que sí corresponde cambiarlo antes de hacer
   clic en "Guardar" en el flujo de edición — es un tenant con datos
   reales, el mismo cuidado de siempre aplica aunque ahora el mecanismo
   sea un botón en vez de SQL.
