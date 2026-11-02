# T-078 — Roles de Agencia: permisos de Clientes, Anticipos y Manuales

| | |
|---|---|
| **Módulo** | AGE + PLA |
| **Depende de** | T-063 (menú de Agencia) |
| **Reglas** | — |
| **Decisiones** | Del usuario, 01-nov-2026: abrir tarea (no dejarlo en «Por analizar»); reparto por rol de la tabla de Alcance **confirmado**; `delete_client` para nadie |
| **Rama** | `fix/T-078-roles-agencia-permisos` |

## Contexto
Hallazgo de T-063: ninguno de los 4 roles de `AgenciaViajesRolesSeeder` (Administrador de
agencia, Supervisor, Vendedor de agencia, Contador) tiene `list_client`, `list_advance` ni
`register_recurso`. Con el menú nuevo, esos roles no ven Clientes, Anticipos ni Manuales y videos.
No se notó en agencia-demo porque su administrador es Super-Admin.

El dueño del negocio **no puede arreglarlo solo** desde Accesos → Roles y permisos:
`RoleController::store()/update()` rechaza agregar un permiso que el propio usuario no tiene
(`EscaladaPermisos`, auditoría 08-oct). Solo el Super-Admin (soporte) puede hacerlo a mano, tenant
por tenant. Por eso va al seeder, que es la fuente de verdad de los roles de Agencia.

## Alcance
- `AgenciaViajesRolesSeeder::roles()`: agregar los permisos según este reparto (confirmado por el usuario):

  | Rol | Agrega |
  |---|---|
  | Administrador de agencia | `register_client`, `list_client`, `edit_client`, `list_advance` (+ los de registrar anticipo que use la pantalla), `register_recurso` |
  | Supervisor | `register_client`, `list_client`, `edit_client`, `list_advance`, `register_recurso` |
  | Vendedor de agencia | `register_client`, `list_client`, `edit_client`, `register_recurso` |
  | Contador | `list_client`, `list_advance`, `register_recurso` |

  Confirmar en `routes/api.php` y en `views/advance/*` qué permisos usa realmente la pantalla de
  Anticipos (listar y registrar) antes de elegir nombres. `delete_client` no se da a nadie salvo
  que el usuario lo pida.
- El seeder sigue siendo solo aditivo (`givePermissionTo` de lo que falta, nunca
  `syncPermissions`): no quita lo que el dueño haya configurado a mano.
- Producción (dakamu): definir y anotar en «Resultado» el comando para aplicar el seeder a los
  tenants de Agencia existentes (ver `BackfillFase1bAgenciaViajes`, que ya lo corre por tenant;
  o `tenants:run` con `db:seed --class=AgenciaViajesRolesSeeder`). Limpiar la caché del menú.
- Al desplegar, revisar qué rol tiene el usuario dueño de dakamu. Si es un rol propio (no de los
  4), anotarlo: ese rol lo ajusta el Super-Admin a mano.

## Fuera de alcance
- Cambiar `EscaladaPermisos` o la pantalla de roles.
- Roles de retail y créditos.
- Bloquear por giro las rutas de Productos/Categorías (T-063 las dejó abiertas por URL).

## Dónde tocar
- `api-sistema-fe/database/seeders/AgenciaViajesRolesSeeder.php` → `roles()` (~línea 78-130)
- `api-sistema-fe/app/Console/Commands/BackfillFase1bAgenciaViajes.php` → referencia para el backfill
- `api-sistema-fe/routes/api.php` → permisos de clientes y anticipos (solo lectura)

## Criterios de aceptación
- [x] **CA1** — Correr el seeder en un tenant de agencia → cada rol tiene los permisos de la tabla. → test
- [x] **CA2** — Un permiso que el dueño agregó a mano a un rol se conserva al volver a correr el seeder (no hay `syncPermissions`). → test
- [x] **CA3** — Correrlo dos veces no duplica nada. → test
- [x] **CA4** — Con el menú de T-063, un Administrador de agencia (no Super-Admin) ve Clientes, Anticipos y Manuales; un Vendedor ve Clientes. → test del menú (`MenuGirosTest`) + prueba en agencia-demo con un usuario de ese rol
- [x] **CA5** — Retail y créditos: sin cambios en sus roles. → test o verificación

## Seguridad y correctitud
- [x] Permisos / Policy en rutas nuevas: no hay rutas nuevas; revisar que ningún rol reciba más de lo que su trabajo pide
- [x] Aislamiento de tenant: roles por tenant; el backfill corre solo en tenants de giro agencia
- [x] Validación de entrada / archivos: no aplica
- [x] Concurrencia (`lockForUpdate`): no aplica
- [x] Dinero en centavos: no aplica
- [x] Fechas con `HoraPeru`: no aplica
- [x] Tributario sin fallback silencioso: no aplica
- [x] Sin `v-html` sin sanitizar: no aplica
- [x] Migraciones nuevas + `tenants:migrate-verticales`: no aplica (seeder); comando de producción anotado

## Cómo verificar
```
php artisan test --filter='AgenciaViajesRoles|MenuGiros'
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:** `e020bee` (rama `fix/T-078-roles-agencia-permisos`, unida a `main`).
- **Tests:** `MenuGirosTest` con 3 tests nuevos (CA1 reparto por rol, CA2+CA3 aditivo/idempotente, CA4 menú de
  Administrador y Vendedor sin ser Super-Admin) y el test del Vendedor actualizado (ahora ve Clientes y Ayuda).
  Suite completa: 1809 verdes, 1 falla en `Fila13Fase1cFacturacionTest` (orden de un arreglo; la intermitencia
  conocida de ese archivo) — vuelta a correr sola, pasa 32/32. CA5: los fixtures de retail y créditos de
  `MenuGirosTest` siguen verdes y el seeder solo toca los 4 roles de Agencia.
  **CA4 en agencia-demo** (API real vía Apache): usuarios `t078-admin@…` (Administrador de agencia) y `t078-vend@…`
  (Vendedor de agencia), ninguno Super-Admin. El Administrador ve Clientes, Anticipos y Manuales y videos; el
  Vendedor ve Clientes y Manuales y videos (sin Anticipos). Usuarios borrados al terminar (0 restantes).
- **Desviaciones de la tarea y por qué:**
  - Permisos de Anticipos confirmados en `routes/api.php` y `routes.ts`: `list_advance` (lista/detalle),
    `register_advance` (crear y «corregir») y `refund_advance` (reembolsar). La tabla no mencionaba
    `refund_advance`; el usuario decidió (01-nov-2026): **solo Administrador de agencia**. Por eso el
    Administrador recibe `list_advance`, `register_advance` y `refund_advance`; Supervisor y Contador solo `list_advance`.
  - Se pidió y se dio `register_recurso` (no `list_recurso`): es lo que usan el menú y la ruta `recurso.manual`.
    `list_recurso` y `delete_client` no se dan a nadie.
  - El seeder también declara en `PERMISOS` los permisos nuevos (`firstOrCreate`) para que existan en tenants viejos.
- **Aplicar en producción (solo dakamu, único tenant de agencia):**
  ```
  cd /var/www/html/sistemfe/api-sistema-fe
  php artisan permisos:backfill-fase1b-agencia-viajes dakamu
  ```
  Corre `AgenciaViajesRolesSeeder` (solo agrega, nunca quita) y es idempotente; el segundo paso del comando
  (roles ajenos al catálogo con permiso plano) ya se aplicó antes y no cambia nada. **No correrlo en
  market ni credishirley.**
  **Caché del menú** (24 h de TTL; el seeder no la invalida): limpiarla con
  ```
  php artisan tenants:run cache:clear --tenants=dakamu
  ```
  o, si no limpia la tabla del tenant, el equivalente exacto que usé en agencia-demo:
  `App\Models\Tenant::find('dakamu')->run(fn () => app(App\Services\MenuResolver::class)->invalidarTenantActivo());`
  (en `php artisan tinker`). Los usuarios ven el cambio al recargar.
  **Revisar al desplegar** qué rol tiene el usuario dueño de dakamu: si es un rol propio (no de los 4),
  ese rol lo ajusta el Super-Admin a mano; el seeder no lo toca.

## Hallazgos fuera de alcance
- `refund_advance` quedó solo en Administrador; si Supervisor debe reembolsar, es un cambio de una línea.
