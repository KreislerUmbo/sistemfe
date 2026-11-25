# T-133 — Retirar `suppliers`: Caja usa `proveedores`, con filtro turístico/general

| | |
|---|---|
| **Módulo** | COM + CAJ |
| **Depende de** | T-132 |
| **Reglas** | `CLAUDE.md` (Dinero y caja: ningún movimiento silencioso) |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7c |
| **Rama** | `feat/T-133-retirar-suppliers` |

## Contexto
Hay dos catálogos que no se conocen: `suppliers` (Caja) y `proveedores` (Agencia). El buscador
de contraparte de Caja no encuentra a los proveedores de Agencia. Compras necesita que una
factura apunte a **un** proveedor.

Desde T-132 la tabla `proveedores` existe en todos los giros (`tenant/core/`). Diagnóstico de
producción del 24-nov-2026 (solo lectura): 0 `suppliers` y 0 movimientos de Caja con
`counterparty_type='proveedor'` en dakamu, credishirley y market. No hay datos que remapear hoy,
pero la migración igual debe ser segura si aparecen.

## Alcance
- `CashMovementController`: el buscador de contraparte y la validación `counterparty_type='proveedor'` pasan a leer `proveedores` (todos, turísticos y generales).
- Migración que **remapea** `cash_movements.counterparty_id` de `suppliers` a `proveedores` (unión por documento; si no hay coincidencia, crear el proveedor desde `suppliers`). Los movimientos ya guardan copia del nombre y documento: no se pierde nada.
- Un solo punto del backend con el filtro: por defecto **turísticos** (tienen tipo turístico en `proveedor_tipos`), parámetro para pedir **todos**. Las ~9 pantallas de Agencia que cargan la lista siguen igual (turísticos); Caja, Compras y Rendiciones piden todos.
- La pantalla `views/cash/suppliers.vue` y su ruta pasan a `proveedores`.
- Retirar `Supplier`, `SupplierController`, la ruta `suppliers` y (al final, en migración aparte) la tabla.
- **El proveedor pasa al sistema base, no solo su tabla** (revisión del 24-nov, §3.7c del plan): hoy el modelo es `App\Models\AgenciaViajes\Proveedor` y todas las rutas de `ProveedorController` piden `agencia.proveedores`, así que una tienda no podría usarlo.
  - Modelo a `App\Models\Proveedor`. Para no tocar decenas de `use` en esta tarea, `App\Models\AgenciaViajes\Proveedor` puede quedar como subclase que extiende al base y conserva lo propio de Agencia (relaciones con alojamiento, servicios, tarifas, fotos); decirlo en el commit.
  - Controlador del sistema base con listar/buscar, ver, crear y editar los datos básicos, bajo permisos nuevos **`proveedores.ver`** y **`proveedores.gestionar`** (sembrados en `PermissionsDemoSeeder`, en el menú y sincronizados al Propietario con `roles:sync-propietario`). Lo turístico (tipo, servicios, tarifas, hotel, fotos) sigue en `AgenciaViajes\ProveedorController` con `agencia.proveedores`.
  - Backfill: todo rol con `agencia.proveedores` recibe `proveedores.ver` y `proveedores.gestionar`; el rol con el permiso de la pantalla vieja de proveedores de Caja recibe `proveedores.ver` (y `gestionar` si podía crear).

## Fuera de alcance
- Campos nuevos del proveedor y consulta RUC (T-134). Compras.

## Dónde tocar
- `api-sistema-fe/app/Http/Controllers/Cash/CashMovementController.php` → buscador (~línea 316-345) y validación de contraparte (~línea 450-490).
- `api-sistema-fe/app/Http/Controllers/Cash/SupplierController.php`, `app/Models/Cash/Supplier.php`, `routes/api.php:399`.
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ProveedorController.php` → filtro único (turístico por defecto).
- `app/Console/Commands/BackfillPermisosGateBucketA.php` y `app/Services/AgenciaViajes/LimpiarOperacionPrueba.php` mencionan `suppliers`.
- `admin-start-kit/src/views/cash/suppliers.vue`, `src/types/cash.ts`, `src/router/routes.ts`.
- `api-sistema-fe/app/Models/AgenciaViajes/Proveedor.php` → `app/Models/Proveedor.php`; rutas de proveedores en `routes/api.php` (~línea 776-810, hoy todas con `permission:agencia.proveedores`); `database/seeders/PermissionsDemoSeeder.php`.

## Criterios de aceptación
- [x] **CA1** — El buscador de Caja encuentra a un proveedor creado en Agencia, y a uno general (sin tipo turístico).
- [x] **CA2** — Un proveedor de `suppliers` con documento coincidente con uno de `proveedores` se une; uno sin coincidencia se crea. Los `counterparty_id` de movimientos viejos apuntan a un proveedor existente; el nombre guardado no cambia.
- [x] **CA3** — Las pantallas de Agencia no muestran proveedores generales; Caja sí.
- [x] **CA4** — No quedan referencias a `suppliers` en `app/`, `routes/` ni `src/` (salvo la migración de retiro).
- [x] **CA5** — Los permisos de la pantalla vieja de proveedores de Caja siguen funcionando con la ruta nueva (sin dejar a un rol sin acceso ni dar acceso de más).
- [x] **CA6** — En un tenant de tienda, un usuario con `proveedores.gestionar` crea y busca un proveedor por API; sin el permiso recibe 403. Las rutas turísticas siguen pidiendo `agencia.proveedores`.
- [x] **CA7** — Después del backfill, ningún rol que tenía `agencia.proveedores` pierde acceso al listado ni al alta.

## Seguridad y correctitud
- [x] Permisos / Policy: ruta de Caja a proveedores con permiso; revisar `BackfillPermisosGateBucketA`.
- [x] Aislamiento de tenant: sí, la migración de datos corre por tenant.
- [x] Dinero: no cambia ningún monto; solo el vínculo del contraparte.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: aplicadas en local; **respaldo y revisar producción antes de desplegar**.
- [x] Entrada, concurrencia, fechas, tributario, `v-html`: no aplica (la entrada del controlador base se valida).

## Cómo verificar
```
php artisan test --filter=Cash
php artisan test --filter=Proveedor
```
Pantalla: crear un egreso en Caja a un proveedor de Agencia (agencia-demo).

---

## Resultado
- **Commits** (rama `feat/T-133-retirar-suppliers`):
  1. `67b0f143` proveedor al sistema base (modelo, `proveedores-base`, permisos, backfill).
  2. `e3bad0c1` filtro turístico/todos y Caja leyendo `proveedores`.
  3. `e139fa66` migración que remapea `counterparty_id`.
  4. `8be3388d` retiro de `Supplier` y pantalla `/proveedores`.
  5. `7a08a33c` migración que borra `suppliers`.
- **Tests:** suite completa del backend 2477 de 2478. El que falla,
  `VentaDirectaLineasTest::test_ca1_dos_lineas_en_dias_distintos…`, es intermitente (pasa 3 de 3
  solo) y la rama no toca ese código. Frontend: `type-check` y `build` en verde.
  - CA1: `ProveedorFiltroYCajaTest` · CA2: `RemapearContraparteProveedorTest` ·
    CA3: `ProveedorFiltroYCajaTest` · CA4: `grep` de `suppliers` en `app/`, `routes/` y `src/` sin
    resultados, y `ProveedorBaseTest` (tenant nuevo sin la tabla) ·
    CA5: `ProveedoresPermisosBackfillTest` y `GateBucketARoutesTest` ·
    CA6: `ProveedorBaseTest` (HTTP real en un tenant de tienda) · CA7: `ProveedoresPermisosBackfillTest`.
- **Prueba en pantalla** (agencia-demo, servidores del worktree): alta de un proveedor general en
  `/proveedores`; no aparece en Agencia → Proveedores; egreso de S/ 1 en Caja a un proveedor de
  Agencia, guardado con su id, nombre y RUC. El proveedor de prueba se borró; el egreso quedó
  (movimiento 48, «Prueba T-133»).
- **Desviaciones de la tarea y por qué:**
  - Las rutas del sistema base son `proveedores-base`: `proveedores` sigue siendo la de Agencia.
  - No se puede eliminar desde Agencia un proveedor con movimientos de Caja (no hay FK y el id
    quedaría colgado; antes `suppliers` solo se desactivaba).
  - Backfill: `edit_supplier`/`delete_supplier` sin `register_supplier` reciben solo
    `proveedores.ver` (regla «gestionar si podía crear»). Ningún rol sembrado está en ese caso.
  - La migración deja en `NULL` un `counterparty_id` que no existía en `suppliers`.
  - Los permisos `*_supplier` dejaron de sembrarse en roles y de verse en la pantalla de Roles.
  - El ítem de menú de Agencia pasa a llamarse «Proveedores (todos)», para distinguirlo de
    CATÁLOGOS → Proveedores.
- **Al desplegar:** respaldo antes; `deploy.sh` migra, siembra el menú y corre
  `roles:sync-propietario`. Revisar la salida de `tenants:migrate-verticales`.
- **Entorno local:** los tenants locales ya están migrados (sin `suppliers`), así que en `main` la
  pantalla vieja de proveedores y el buscador de proveedor de Caja fallan hasta unir la rama. Al
  unir: `php artisan db:seed --class=MenuItemsSeeder --force` y `php artisan cache:clear` (sin eso
  el menú apunta a `suppliers.index`, que ya no existe).
- **Pendiente del cierre:** revisión, recorrido y borrado de esta tarea.

## Hallazgos fuera de alcance
- Agencia → Proveedores no tiene el «ver todos» que dice la tabla de §3.7c: el backend ya acepta
  `?alcance=todos`, falta el control en pantalla. Sin él, para ponerle tipo turístico a un
  proveedor general hay que entrar a su ficha por la URL.
- Los tipos generales («Insumos», «Mercadería») no se pueden crear como generales: el CRUD
  del superadmin guarda todo tipo con `giro='agencia_viajes'`, que es lo que define «turístico».
- Las filas de permisos `*_supplier` siguen en las bases (las crea una migración vieja); ya no
  gobiernan ninguna ruta.
- `TenantProvisioningService::eliminarSiVacio()` no cuenta `proveedores` en tiendas ni créditos
  (tampoco contaba `suppliers`).
- `VentaDirectaLineasTest` CA1 intermitente: las dos fechas llegaron invertidas en una corrida de
  la suite completa.
