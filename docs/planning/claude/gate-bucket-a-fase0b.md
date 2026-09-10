# Fase 0b — Gate de permisos, Bucket A (rutas administrativas críticas)

> Continuación de Fase 0 (`docs/planning/claude/auditoria-menu-admin-start-kit.md`, 69 rutas
> mutables sin gate) y de `plan-modulo-menus-y-roles.md` §9.1. Ejecuta solo el paso 1 (Bucket
> A) de la estrategia de rollout — Bucket B (31 rutas operativas) queda sin tocar a propósito.
> Fecha: 10-sep-2026.

## Nota sobre el brief

El brief pegado citaba `docs/planning/claude/plan-modulo-menus-y-roles.md` §9.1 con "una
estrategia de rollout completa en 5 pasos" y terminología "Bucket A/B" ya definida ahí. El
plan real (que vive en `docs/planning/plan-modulo-menus-y-roles.md`, sin el subdirectorio
`claude/` — mismo desajuste de ubicación que en Fase 0) solo tenía un orden de 3 pasos escrito
(auditar → backfillear → gatear), sin la terminología Bucket A/B. Se encontró además un
archivo idéntico a este brief, sin trackear, en
`docs/planning/agencia-de-viajes/PEGAR-EN-CLAUDE-CODE-fase0b-gate-rutas-criticas.md` — evidencia
de que otra sesión en paralelo lo estaba redactando. Se siguió el brief tal como fue pegado
(autocontenido, no dependía realmente de que esa sección tuviera ese formato exacto) y se
documenta acá la discrepancia, igual que en Fase 0.

## Parte 1 — Clasificación completa (69 rutas → Bucket A / Bucket B)

### Bucket A — 38 rutas, 12 recursos (gateadas en esta sesión)

| Recurso | Rutas (store/update/destroy) | Controller | Motivo |
|---|---|---|---|
| `roles` | 3 | `Role\RoleController` | Ejemplo literal del brief — crear/editar/eliminar roles. |
| `users` | 4 (incl. update legacy por POST) | `User\UserController` | Ídem, gestión de usuarios. |
| `company` | 3 | `Client\CompanyController` | Identidad fiscal del tenant (RUC/razón social) — un `destroy` accidental rompe toda emisión SUNAT futura. |
| `branches` | 3 | `Cash\BranchController` | Catálogo estructural, bajo "Configuraciones" en el menú — sección que Vendedor/Cajero nunca ve (confirmado en Fase 0). |
| `cash-registers` | 3 | `Cash\CashRegisterController` | Ídem. |
| `payment-methods` | 3 | `Cash\PaymentMethodController` | Ídem — el cajero SELECCIONA un método existente, no crea métodos nuevos. |
| `suppliers` | 3 | `Cash\SupplierController` | Ídem, catálogo de "Configuraciones". |
| `cash-concepts` | 3 | `Cash\CashConceptController` | Ídem. |
| `series-comprobante` | 3 | `Sale\SerieComprobanteController` | Ejemplo literal del brief ("eliminar series de comprobantes") — crítico para integridad de correlativos SUNAT. |
| `systems` | 3 | `Portal\Admin\SystemController` | Contenido de plataforma ("ADMIN PORTAL" en el menú), no dato de negocio de un tenant. |
| `system_categories` | 4 (incl. update legacy por POST) | `Portal\Admin\SystemCategoryController` | Ídem. |
| `recursos` | 3 | `Portal\Admin\ManualRecursoController` | Ídem, catálogo de ayuda del marketplace. |

### Bucket B — 31 rutas, sin tocar (uso operativo diario)

`categories` (4), `products` (4), `clients` (3), `clients/{client}/payments` (2), `sales`
(4), `sale_details` (3), `sale_payments` (3), `enviarSunat` (1), `installments` (2),
`sales/{sale}/installments` (2), `notas` (3). Confirmado contra la agrupación real del menú
(Fase 0): todos viven bajo "COMERCIAL"/"Ventas", que Vendedor/Cajero/Contador sí usan a
diario. Nota aparte: `sale_details`/`sale_payments` (6 rutas) no tienen ningún caller real en
el frontend (grep completo sin resultados) — probablemente endpoints muertos, quedan en B
igual, sin gatear ni borrar en esta sesión.

## Parte 2 — Qué existe hoy de verdad (consulta real, Postgres directo)

**Verificado en el código, no asumido:** `Gate::before()` en `AppServiceProvider.php:23-25`
(`$user->hasRole('Super-Admin') ? true : null`) SÍ intercepta cualquier chequeo de
`permission:` — confirmado leyendo `PermissionMiddleware::handle()`
(`vendor/spatie/laravel-permission/.../PermissionMiddleware.php:33`), que llama
`$user->canAny($permissions)`, y `canAny()` (Laravel base) pasa por `Gate::forUser()->any()`,
que sí ejecuta `Gate::before`. Super-Admin nunca se bloquea, sin importar qué permiso falte.

**Catálogo de permisos idéntico en `sandbox`/`umbo`/`negocio2`** (71 permisos cada uno, antes
del backfill de esta sesión). Consulta directa a Postgres (no a `PermissionsDemoSeeder.php`,
que es solo un snapshot de dev y puede estar desactualizado):

| Recurso | Permisos store/update/destroy | ¿Existían ya (los 3 tenants)? | ¿Algún rol real los tenía asignados? |
|---|---|---|---|
| `roles` | `register_role`/`edit_role`/`delete_role` | ✅ Sí | Nadie (ni un rol "Administrador" — no existe en ningún tenant real hoy) |
| `users` | `register_user`/`edit_user`/`delete_user` | ✅ Sí | Nadie |
| `branches` | `register_branch`/`edit_branch`/`delete_branch` | ✅ Sí | Nadie |
| `cash-registers` | `register_cash_register`/`edit_cash_register`/`delete_cash_register` | ✅ Sí | Nadie |
| `payment-methods` | `register_payment_method`/`edit_payment_method`/`delete_payment_method` | ✅ Sí | Nadie |
| `suppliers` | `register_supplier`/`edit_supplier`/`delete_supplier` | ✅ Sí | Nadie |
| `cash-concepts` | `register_cash_concept`/`edit_cash_concept`/`delete_cash_concept` | ✅ Sí | Nadie |
| `series-comprobante` (store/update) | `register_serie_comprobante`/`edit_serie_comprobante` | ✅ Sí | **`Contador` en `umbo` sí los tiene** (customización real, no en sandbox/negocio2) |
| `series-comprobante` (destroy) | `delete_serie_comprobante` | ❌ No — **creado nuevo** | — |
| `company` | `company` (permiso plano, el que ya usaba el menú) | ❌ No — **creado nuevo**. El ítem "Datos de la empresa" era invisible para todo no-Super-Admin en los 3 tenants antes de este cambio (permiso referenciado por el frontend pero nunca sembrado). | — |
| `systems`/`system_categories`/`recursos` | 9 permisos (3 c/u) | ❌ Ninguno — **creados nuevos**. Toda la sección "ADMIN PORTAL" del menú era invisible para todo no-Super-Admin en los 3 tenants. | — |

**Usuarios reales revisados — los 8 que existen en los 3 tenants, completo:**

- `sandbox`: Sandbox Admin (Super-Admin), Cajero Test (Cajero), Cajero Test 2 (Cajero),
  Supervisor (rol de prueba "Supervisor Caja (test)").
- `umbo`: Kreisler (Super-Admin), Jose Ricardo (Contador).
- `negocio2`: Admin Negocio Dos (Super-Admin).

Ninguno de los usuarios no-Super-Admin tiene asignado ningún permiso de creación/edición/
borrado de los 12 recursos de Bucket A. El único que administra roles/usuarios/sucursales/
cajas/etc. en cualquiera de los 3 tenants reales es el propio Super-Admin — que bypasea el
gate. **Conclusión de riesgo: gatear con los permisos existentes, sin asignarlos a ningún rol
nuevo, no bloquea a nadie real hoy.**

## Parte 3 — Qué se implementó

1. **`app/Console/Commands/BackfillPermisosGateBucketA.php`** — comando nuevo
   `permisos:backfill-gate-bucket-a {tenant}`, crea (idempotente, `Permission::firstOrCreate`)
   los 11 permisos nuevos, **sin tocar `role_has_permissions` de nadie**. Deliberadamente
   separado de `PermissionsDemoSeeder` — ese seeder usa `syncPermissions()` por rol contra un
   mapa fijo, y correrlo contra un tenant real ya customizado (ej. `umbo`, cuyo `Contador`
   tiene permisos reales que el mapa fijo no conoce: `can_switch_branch`, `edit_sale`,
   `emitir_boleta/factura/nota_venta`, `list_serie_comprobante`, `register_serie_comprobante`)
   **borraría esa customización real**. Confirmado que `PermissionsDemoSeeder` SÍ es el
   seeder real usado por `TenantProvisioningService::provision()` para tenants nuevos (no solo
   un artefacto de dev) — por eso los 11 permisos nuevos también se agregaron a su array
   `PERMISSIONS` (sin tocar el mapa `ROLES`), para que un tenant nuevo nazca con el catálogo
   completo.
2. **`routes/api.php`** — `->middlewareFor('store'|'update'|'destroy', 'permission:X')` en los
   12 `Route::resource(...)` de Bucket A (API de Laravel 11+/12, `PendingResourceRegistration::
   middlewareFor()`), más `->middleware('permission:X')` en las 2 rutas legacy de update por
   POST (`users/{id}`, `system_categories/{id}`).
3. **`database/seeders/PermissionsDemoSeeder.php`** — 11 nombres nuevos agregados a
   `PERMISSIONS`, sin agregarlos a ningún rol en `ROLES`.
4. **`tests/Feature/GateBucketARoutesTest.php`** — 77 tests (38 rutas × 2 capas + 1): capa 1
   confirma que la ruta REAL registrada exige el permiso esperado (`Route::getRoutes()`); capa
   2 invoca `PermissionMiddleware::handle()` real (no una réplica) con un usuario sin el
   permiso (→ `UnauthorizedException`) y con el permiso (→ pasa) — para cada una de las 38
   rutas, no un solo caso genérico. Más 1 test de que Super-Admin bypasea sin tener el permiso.
   Suite completa: 641/647 verde (6 fallos pre-existentes de `TipoCambioSunat*`, confirmados no
   relacionados — fallan igual con este cambio revertido).
5. **Verificación real contra `sandbox`** (login JWT real, generado vía tinker contra el
   usuario real — no reseteo de password, ver `feedback_no_resetear_passwords_sin_verificar`):
   - Cajero Test (sin ningún permiso de Bucket A) → `POST cash-concepts` 403, `DELETE
     roles/999` 403, `DELETE series-comprobante/999999` 403 (bloqueado ANTES de llegar a
     buscar el registro — confirma que el permiso nuevo `delete_serie_comprobante` funciona).
   - Sandbox Admin (Super-Admin) → `POST cash-concepts` con body vacío → 422 (pasó el gate,
     falló validación — nunca 403); `DELETE series-comprobante/999999` (inexistente) → 404
     (pasó el gate, el controller no encontró el registro — nunca 403). Ninguna mutación real
     quedó aplicada (422/404 no crean/borran nada).

## Backfill pendiente para `umbo`/`negocio2` (NO ejecutado — requiere tu autorización explícita)

```bash
php artisan permisos:backfill-gate-bucket-a umbo
php artisan permisos:backfill-gate-bucket-a negocio2
```

Mismo comando ya corrido contra `sandbox` en esta sesión. Es seguro de correr contra tenants
reales (no toca `role_has_permissions`, solo `Permission::firstOrCreate`) — pero se deja sin
ejecutar por regla explícita del brief ("ninguna acción irreversible ni cambio de control de
acceso sobre tenants reales sin pedirlo antes"). El deploy del código (rama mergeada) por sí
solo NO rompe nada en `umbo`/`negocio2` aunque el backfill no se corra todavía: los 33
permisos reutilizados (roles/users/branches/etc.) ya existen ahí, y los 11 nuevos (company/
systems/system_categories/recursos/delete_serie_comprobante) solo bloquean acciones que hoy
nadie real ejecuta en esos 2 tenants (mismo argumento de la Parte 2) — Super-Admin sigue
pasando igual vía `Gate::before()` aunque el permiso ni siquiera exista todavía como fila en
la tabla (`canAny()` sobre un permiso inexistente simplemente no lo encuentra, pero
`Gate::before` corta antes de que eso importe).

## Hallazgo nuevo, fuera de alcance — prioridad alta

Ver la nota completa agregada en `plan-modulo-menus-y-roles.md` §9.1: las rutas "100%
CENTRALES" (`systems`/`system_categories`/`recursos`, sin `InitializeTenancyBySubdomain`)
autentican el JWT contra la base Postgres DEFAULT (`sv_facturacion`, pre-multitenant) en vez
de contra la base del tenant real — confirmado con evidencia real (un token de "Sandbox
Admin" autenticó como "Kreisler", usuario no relacionado, por coincidencia de ID). No
corregido acá — es un problema de arquitectura de tenancy, no de permisos, y mi gate de
Bucket A corre después en el pipeline (no lo causa ni lo tapa).
