# T-081 — Rol «Propietario» para el dueño de cada tenant y cuenta de soporte aparte

| | |
|---|---|
| **Módulo** | PLA (todos los giros) |
| **Depende de** | — |
| **Reglas** | CLAUDE.md: «El Super-Admin es soporte de UmboSystem; los dueños usan el rol Administrador» (esta tarea lo hace cumplir con un rol propio); nadie otorga lo que no tiene (`EscaladaPermisos`) |
| **Decisiones** | Conversación con el usuario, 03-nov-2026 (después del despliegue `e9d5e02`): en dakamu, `dakamu@gmail.com` es Super-Admin. Detalles fijados al preparar la tarea, ver «Decisiones de detalle» |
| **Rama** | `feat/T-081-rol-propietario` |

## Contexto
`TenantProvisioningService::provision()` (~185-193) crea **un solo usuario**, el del dueño, y le
da el rol **Super-Admin**. Por eso en producción los dueños son Super-Admin, aunque ese rol es la
cuenta de soporte de UmboSystem: se salta todos los permisos (`Gate::before`,
`AppServiceProvider` ~24), nadie lo puede editar y algunas pantallas lo tratan aparte. Al
desplegar T-078 se vio en dakamu: el dueño es Super-Admin y otro usuario usa «Admin Empresario»,
un rol hecho a mano sin permisos de Agencia.

El usuario decidió tres niveles: **Super-Admin** = soporte Umbo (cuenta propia en cada tenant);
**Propietario** = el dueño, con todo lo de su giro; **Administrador** y roles personalizados por
debajo, creados por el dueño. Esta tarea hace los dos primeros. Unificar el «Administrador» por
giro queda para otra tarea (ver «Fuera de alcance»).

## Alcance
- **Rol `Propietario`** en todos los tenants: tiene **asignados todos los permisos que existen en
  el tenant** (cada tenant solo tiene los de su giro), menos una lista explícita de los que son
  solo de soporte. **No** pasa por `Gate::before`.
- **Siempre al día:** un comando idempotente lo sincroniza con los permisos nuevos y corre en
  cada despliegue. Así una función nueva (como `reservas.modificar`) le llega sola al dueño.
- **Alta de tenant:** el dueño nace `Propietario` y además se crea la cuenta de soporte
  `soporte@umbosystem.com` como Super-Admin, con contraseña aleatoria que no se muestra.
- **Protección del Propietario:** solo un Super-Admin o un Propietario puede editar, desactivar,
  eliminar o asignar el rol Propietario. Nunca puede quedar el tenant **sin ningún Propietario
  activo**.
- **Tenants existentes:** comando para pasar al dueño de Super-Admin a Propietario y crear la
  cuenta de soporte, tenant por tenant, con `--simular`.
- **Panel Superadmin:** «Restablecer contraseña» apunta al Propietario (y a la cuenta de soporte
  cuando se elige su correo).

## Decisiones de detalle (03-nov-2026, al preparar la tarea)
- **Permisos reales, no un pase libre.** Si el Propietario usara `Gate::before` volveríamos al
  problema con otro nombre, y en la pantalla de Roles no se vería qué tiene. Por eso
  `syncPermissions` **solo sobre el rol Propietario** (es el único rol cuyo contenido el sistema
  administra entero; los demás roles nunca se tocan con `sync`, solo `givePermissionTo`).
- **Lista de solo soporte** (`RolPropietario::PERMISOS_SOLO_SOPORTE`): empieza **vacía**. Lo que
  hoy es exclusivo de soporte no depende de un permiso sino del rol (`hasRole('Super-Admin')`, ver
  la tabla de abajo) o del tenant de plataforma (`EnsureTenantIsPlataforma`). La constante existe
  para que el día que un permiso sea solo de soporte, se agregue ahí y el comando se lo quite al
  Propietario.
- **Cuántos:** puede haber más de un Propietario (un socio). La regla es que quede **al menos uno
  activo y no eliminado**. Quitárselo al último (cambiarle el rol, ponerlo Inactivo o eliminarlo)
  → 422 «Es el único propietario: nombra a otro antes».
- **El rol Propietario no se edita ni se elimina** desde Roles, igual que Super-Admin
  (`RoleController` ~124-130 y ~188-193). Sus permisos los decide el comando.
- **Cuenta de soporte:** `soporte@umbosystem.com` en cada tenant (cada tenant tiene su propia
  base, así que el correo no choca). Nombre «Soporte UmboSystem». Contraseña aleatoria, nunca se
  muestra ni se guarda: para entrar, Umbo la restablece desde el Panel Superadmin. Si el usuario ya
  existe, no se toca.
- **Restablecer contraseña desde el panel** (`TenantAdminController::resetAdminPassword()` ~142):
  hoy busca al único Super-Admin. Ahora busca entre **Propietarios y Super-Admin**; sin
  `admin_email`, el Propietario si hay uno solo; si no hay Propietario (tenant aún sin migrar), el
  Super-Admin, como hoy. Con `admin_email` elige ese usuario si es Propietario o Super-Admin.
  Varios sin `admin_email` → el 422 de hoy.
- **Migrar tenants existentes, explícito:** `roles:asignar-propietario {tenant} {email} [--simular]`.
  No adivina quién es el dueño: lo dice quien lo corre. Al usuario dado le quita Super-Admin y le
  da Propietario (si ya era Propietario, no hace nada). Crea la cuenta de soporte si falta. Se
  niega si el usuario no existe o está eliminado. **market** es el tenant de la plataforma (de
  Umbo): se migra solo si el usuario lo decide.
- **Créditos, avisos de cobranza:** `AvisoCobranzaService` (~103-112) deja fuera al Super-Admin
  porque es soporte. Un Propietario **sí** los recibe, como cualquiera que tenga el permiso. En
  credishirley, el dueño **empezará a recibirlos** al migrarlo: es un cambio querido, avisar al
  usuario en el Resultado.
- **«Admin Empresario» en dakamu** (rol hecho a mano): no se toca en esta tarea.

### Qué hacer con cada uso de `Super-Admin`
| Archivo | Para qué | Qué hacer |
|---|---|---|
| `Providers/AppServiceProvider.php` ~24 | `Gate::before` | Nada: sigue solo para Super-Admin |
| `Http/Controllers/User/UserController.php` (`protegerPrivilegios()` ~262, `store` ~74, `update` ~121, `destroy` ~227) | protección | Sumar la del Propietario y la del último Propietario activo (ver Alcance) |
| `Http/Controllers/Role/RoleController.php` ~124, ~188 | rol no editable | Mismo trato para `Propietario` |
| `Http/Controllers/Central/TenantAdminController.php` ~137-175 | restablecer contraseña | Ver decisión de arriba |
| `Services/TenantProvisioningService.php` ~185-193 | alta del dueño | Dueño = Propietario; crear soporte; sincronizar el rol al final del alta (después de los seeders de giro) |
| `Http/Controllers/AgenciaViajes/TraspasoTrabajoController.php`, `Services/Crm/TraspasoTrabajoService.php`, `Services/Dashboard/DashboardService.php` | T-061: el trabajo de un Super-Admin solo lo ve y lo pasa otro Super-Admin | Nada: el Propietario es un usuario normal con permisos |
| `Http/Controllers/AgenciaViajes/ConfiguracionAgenciaController.php` ~115, `Models/AgenciaViajes/ConfiguracionAgencia.php` ~63 | `modo_tours`, solo soporte | Nada: sigue solo Super-Admin |
| `Http/Middleware/EnsureTenantIsPlataforma.php` | catálogo central | Nada |
| `Http/Middleware/ShadowPermissionMiddleware.php` ~38, `Services/GateBucketBPermisos.php` ~98, `Console/Commands/BackfillPermisosGateBucketA.php` | Super-Admin se salta el gate | Nada (el Propietario tiene los permisos de verdad) |
| `Services/EscaladaPermisos.php` | usa `can()` | Nada: con todos los permisos, un Propietario puede otorgar todo |
| `Services/Creditos/Avisos/AvisoCobranzaService.php` ~112 | fuera de los avisos | Nada (ver decisión) |
| `Services/Creditos/ClienteCreditoService.php` ~106, `Http/Controllers/Client/ClientController.php` ~87 | comentarios | Nada |
| `admin-start-kit/src/stores/auth.ts` ~60 | bypass de rutas en el front | Nada: el Propietario tiene los permisos en `permissions` |
| `admin-start-kit/src/views/users/index.vue` (`actorEsSuperAdmin`, `puedeGestionar` ~339) | «Protegido» | Un Propietario también ve «Protegido» a los Super-Admin; quien no es Propietario ni Super-Admin ve «Protegido» al Propietario; el rol Propietario solo se ofrece en el selector a Propietario y Super-Admin |
| `admin-start-kit/src/views/roles/index.vue` | rol no editable | Propietario sin editar ni eliminar, con un texto «Lo administra el sistema: tiene todos los permisos» |
| `central-panel/src/views/TenantDetailView.vue` ~95, ~344-354; `central-panel/src/stores/tenants.ts` ~205 | textos del restablecimiento | Decir «propietario (o soporte)» en vez de «Super-Admin» |

## Fuera de alcance
- **Unificar el «Administrador» por giro** (hoy «Administrador de agencia», «Administrador de
  créditos» y ninguno en retail; dakamu usa «Admin Empresario», hecho a mano). Va a «Por
  analizar».
- Módulos por plan (el Propietario recibiría solo los permisos de los módulos de su plan): idea
  anotada aparte, sin tarea.
- Cambiar roles o usuarios de producción: los comandos los corre el usuario después de desplegar.
- Tocar los demás roles con `syncPermissions`.

## Dónde tocar
**Backend** (`api-sistema-fe/`)
- Nuevo `app/Services/Roles/RolPropietario.php`: `NOMBRE = 'Propietario'`,
  `PERMISOS_SOLO_SOPORTE = []`, `sincronizar(): void` (crea el rol si falta, guard `api`, y le hace
  `syncPermissions(Permission::where('guard_name','api')->whereNotIn('name', SOLO_SOPORTE)->get())`),
  `quedaOtroActivo(User $sin): bool`.
- Nuevo comando `roles:sync-propietario {--tenant=*}` (todos los tenants no archivados por
  defecto), en `app/Console/Commands/`. Imprime por tenant cuántos permisos agregó o quitó.
- Nuevo comando `roles:asignar-propietario {tenant} {email} {--simular}`.
- `TenantProvisioningService::provision()` ~185-193: ver tabla. La sincronización va **después**
  de `AgenciaViajesRolesSeeder`/`CreditosRolesSeeder` (~133-145), para que el Propietario reciba
  también los permisos del giro.
- `UserController` y `RoleController`: ver tabla. Constante del nombre desde `RolPropietario`.
- `TenantAdminController::resetAdminPassword()`: ver decisión.
- `deploy-ovh/scripts/deploy.sh`, paso 7 (~84-87): `php artisan roles:sync-propietario` con la
  misma falla blanda que el seeder del menú.

**Frontend**
- `admin-start-kit/src/views/users/index.vue`, `views/roles/index.vue`; `central-panel/src/views/TenantDetailView.vue`, `stores/tenants.ts` (ver tabla).

## Criterios de aceptación
- [x] **CA1** — Alta de un tenant de cada giro (retail, agencia_viajes, creditos): el dueño tiene
  el rol Propietario y **todos** los permisos del tenant (comparar con `Permission::count()`), no
  es Super-Admin, y existe `soporte@umbosystem.com` como Super-Admin. → test
- [x] **CA2** — Se crea un permiso nuevo y se corre `roles:sync-propietario`: el Propietario lo
  recibe; los demás roles no cambian; correrlo dos veces no cambia nada. Un permiso en
  `PERMISOS_SOLO_SOPORTE` se le quita. → test
- [x] **CA3** — Un Administrador (sin rol Propietario) no puede editar, desactivar, eliminar ni
  asignar el rol Propietario → 403. Un Propietario sí puede nombrar a otro Propietario. → test
- [x] **CA4** — Último Propietario activo: cambiarle el rol, ponerlo Inactivo o eliminarlo → 422,
  también si lo intenta un Super-Admin o él mismo. Con dos Propietarios, se puede quitar uno. → test
- [x] **CA5** — El rol Propietario no se edita ni se elimina desde Roles → 403/422 como Super-Admin. → test
- [x] **CA6** — `roles:asignar-propietario dakamu dueno@x.com --simular` no escribe nada y dice
  qué haría; sin `--simular` el usuario pasa de Super-Admin a Propietario, se crea la cuenta de
  soporte, y una segunda corrida no cambia nada. Usuario inexistente o eliminado → error. → test
- [x] **CA7** — Restablecer contraseña desde el panel: tenant con un Propietario → a él; con
  `admin_email` de la cuenta de soporte → a ella; tenant sin Propietario → al Super-Admin, como
  hoy. → test
- [x] **CA8** — Prueba funcional en agencia-demo (local): migrar al admin con
  `roles:asignar-propietario`, entrar como Propietario y recorrer Inicio, Cotizador, Reservas,
  Caja, Ventas y Configuración sin ningún 403; en Usuarios, un Administrador ve «Protegido» al
  Propietario. Suite completa en verde; `type-check` sin errores nuevos (base 47) en admin-start-kit
  y central-panel.

## Seguridad y correctitud
- [x] Permisos / Policy en rutas nuevas: no hay rutas nuevas; las protecciones van en `UserController`/`RoleController`
- [x] Aislamiento de tenant: los comandos corren cada tenant con `$tenant->run()`; el rol y la cuenta de soporte son por tenant
- [x] Validación de entrada / archivos: el comando valida tenant y correo
- [x] Concurrencia (`lockForUpdate`): «al menos un Propietario» se comprueba dentro de la transacción del cambio, con lock de los usuarios Propietario
- [x] Dinero en centavos: no aplica
- [x] Fechas con `HoraPeru`: no aplica
- [x] Tributario sin fallback silencioso: no aplica
- [x] Sin `v-html` sin sanitizar: no aplica
- [x] Migraciones nuevas + `tenants:migrate-verticales`: no aplica (roles y permisos por seeder/comando, sin columnas nuevas)

## Cómo verificar
```
php artisan test --filter="Propietario|Provision|UserController|Role|TenantAdmin|EscaladaPermisos|SeguridadRegistro"
cd admin-start-kit && npm run type-check && npx vitest run
cd central-panel && npm run type-check
```
Tests nuevos en `tests/Feature/RolPropietarioTest.php`. Prueba funcional: agencia-demo,
`admin@agencia-demo.test` (memoria `reference_agencia_demo_admin_login`; hoy es Super-Admin, es el
caso a migrar).

## Para desplegar (anotar en el Resultado)
`deploy.sh` corre `roles:sync-propietario` en todos los tenants. Después, el usuario decide por
tenant: `roles:asignar-propietario dakamu dakamu@gmail.com --simular`, revisar, y sin `--simular`;
lo mismo para credishirley con el correo de su dueño (empieza a recibir avisos de cobranza); market
solo si lo decide. Luego restablecer la contraseña de soporte desde el panel para tener acceso.

---

## Resultado
- **Commits:** en la rama `feat/T-081-rol-propietario` (`git log main..feat/T-081-rol-propietario`).
- **Tests:**
  - Nuevos: `tests/Feature/RolPropietarioTest.php` (7 tests: CA2 lógica, CA3, CA4, CA5) y
    `tests/Feature/RolPropietarioTenantTest.php` (4 tests con tenants físicos: CA1 los tres giros,
    CA2 el comando, CA6, CA7).
  - Filtro de la tarea (+ `SuperAdmin|MatrizPermisos|EscopablePorVendedor`): 144 en verde.
  - Suite completa (PostgreSQL real): 1914 en verde (7692 aserciones).
  - admin-start-kit `type-check`: 47 errores (la base, ninguno nuevo). central-panel `vue-tsc -b`:
    sin errores. Vitest no se corrió: no se tocó lógica con tests.
  - CA8 (agencia-demo local, Playwright): `roles:sync-propietario --tenant=agencia-demo` (+128),
    `roles:asignar-propietario agencia-demo admin@agencia-demo.test --simular` (dijo qué haría),
    sin `--simular` (hecho), segunda corrida «Nada que hacer». Como Propietario: 34 rutas del menú
    (Inicio, Seguimiento, Cotizador y una cotización, Reservas y una reserva, Salidas, Ventas,
    Notas, Cuentas por cobrar, Adelantos, Caja, catálogos de Agencia, Configuración, Usuarios,
    Roles, Manual…) sin ningún 403 ni redirección; en Roles, Propietario sin editar/eliminar con
    «Lo administra el sistema»; en Usuarios ve «Protegido» a los Super-Admin. Con un usuario
    temporal «Administrador de agencia» (creado y borrado para la prueba): ve «Protegido» al
    Propietario y el selector no le ofrece Propietario ni Super-Admin.
  - **Estado local que queda:** en agencia-demo, `admin@agencia-demo.test` ya es Propietario y
    existe `soporte@umbosystem.com` (Super-Admin, contraseña aleatoria).
- **Desviaciones de la tarea y por qué:**
  - `RolPropietario::sincronizar()` devuelve `{agregados, quitados}` en vez de `void` (el comando
    imprime cuántos), y recibe la lista de solo soporte como parámetro con
    `PERMISOS_SOLO_SOPORTE` por defecto, para probar CA2 sin tocar la constante.
  - Tests en dos archivos: los de tenant físico no pueden ir en la transacción que usan los de
    controlador.
  - La cuenta de soporte vive en su propia clase, `app/Services/Roles/CuentaSoporte.php` (la usan
    el alta y `roles:asignar-propietario`).
  - `users.role_id` se fija explícito (dueño → Propietario, soporte → Super-Admin): la columna tiene
    `default(1)` y la pantalla Usuarios y el login leen el rol de ahí. Por eso
    `roles:asignar-propietario` reemplaza el rol de `role_id` además de Super-Admin (el usuario queda
    con un solo rol, como lo maneja la pantalla Usuarios); la simulación lo dice.
  - Un Propietario inactivo no cuenta como «otro activo»: con el dueño como único activo, no se le
    puede quitar aunque exista otro Propietario inactivo.
  - `roles:asignar-propietario` se niega con `soporte@umbosystem.com`; `roles:sync-propietario
    --tenant=x` falla si el tenant no existe.
- **Para desplegar:** `deploy.sh` (paso 7) corre `roles:sync-propietario` en todos los tenants no
  archivados. Después, por tenant: `roles:asignar-propietario dakamu dakamu@gmail.com --simular`,
  revisar, y sin `--simular`; lo mismo en credishirley con el correo de su dueño — **ese dueño
  empezará a recibir los avisos de cobranza** (`AvisoCobranzaService` solo excluye al Super-Admin);
  market solo si se decide. Luego restablecer la contraseña de `soporte@umbosystem.com` desde el
  Panel Superadmin (con ese correo en «Email del admin») para tener acceso. Revisar también si el
  tenant tiene **otros** Super-Admin además del dueño: el comando solo migra al que se le indica.

## Hallazgos fuera de alcance
- `users.role_id` tiene `default(1)` (`2026_07_13_090100_fix_users_add_missing_columns.php`): un
  usuario creado sin `role_id` aparece con el rol id 1 (Super-Admin en los tenants provisionados)
  en la pantalla Usuarios y en el login, aunque su rol de Spatie sea otro. Así nacían los dueños
  hasta T-081; cualquier otro `User::create` sin `role_id` repite el problema.
- El Manual (`/recursos/manual`) pide `GET api/recursos/publicos`, que no existe: cae en
  `recursos/{id}` y responde 500 («sintaxis no válida para tipo bigint: publicos»). Visto en
  agencia-demo durante CA8.
- agencia-demo (local) tiene otro Super-Admin, `test.perfil.agencia@sandbox.local`, sobrante de
  pruebas; no se tocó.
