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
- [ ] **CA1** — El buscador de Caja encuentra a un proveedor creado en Agencia, y a uno general (sin tipo turístico).
- [ ] **CA2** — Un proveedor de `suppliers` con documento coincidente con uno de `proveedores` se une; uno sin coincidencia se crea. Los `counterparty_id` de movimientos viejos apuntan a un proveedor existente; el nombre guardado no cambia.
- [ ] **CA3** — Las pantallas de Agencia no muestran proveedores generales; Caja sí.
- [ ] **CA4** — No quedan referencias a `suppliers` en `app/`, `routes/` ni `src/` (salvo la migración de retiro).
- [ ] **CA5** — Los permisos de la pantalla vieja de proveedores de Caja siguen funcionando con la ruta nueva (sin dejar a un rol sin acceso ni dar acceso de más).
- [ ] **CA6** — En un tenant de tienda, un usuario con `proveedores.gestionar` crea y busca un proveedor por API; sin el permiso recibe 403. Las rutas turísticas siguen pidiendo `agencia.proveedores`.
- [ ] **CA7** — Después del backfill, ningún rol que tenía `agencia.proveedores` pierde acceso al listado ni al alta.

## Seguridad y correctitud
- [ ] Permisos / Policy: ruta de Caja a proveedores con permiso; revisar `BackfillPermisosGateBucketA`.
- [ ] Aislamiento de tenant: sí, la migración de datos corre por tenant.
- [ ] Dinero: no cambia ningún monto; solo el vínculo del contraparte.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí; **respaldo y revisar producción primero** (en local `suppliers` está vacía).
- [ ] Entrada, concurrencia, fechas, tributario, `v-html`: no aplica.

## Cómo verificar
```
php artisan test --filter=Cash
php artisan test --filter=Proveedor
```
Pantalla: crear un egreso en Caja a un proveedor de Agencia (agencia-demo).

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
