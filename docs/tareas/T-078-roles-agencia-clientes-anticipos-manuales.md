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
- [ ] **CA1** — Correr el seeder en un tenant de agencia → cada rol tiene los permisos de la tabla. → test
- [ ] **CA2** — Un permiso que el dueño agregó a mano a un rol se conserva al volver a correr el seeder (no hay `syncPermissions`). → test
- [ ] **CA3** — Correrlo dos veces no duplica nada. → test
- [ ] **CA4** — Con el menú de T-063, un Administrador de agencia (no Super-Admin) ve Clientes, Anticipos y Manuales; un Vendedor ve Clientes. → test del menú (`MenuGirosTest`) + prueba en agencia-demo con un usuario de ese rol
- [ ] **CA5** — Retail y créditos: sin cambios en sus roles. → test o verificación

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas; revisar que ningún rol reciba más de lo que su trabajo pide
- [ ] Aislamiento de tenant: roles por tenant; el backfill corre solo en tenants de giro agencia
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica (seeder); comando de producción anotado

## Cómo verificar
```
php artisan test --filter='AgenciaViajesRoles|MenuGiros'
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
