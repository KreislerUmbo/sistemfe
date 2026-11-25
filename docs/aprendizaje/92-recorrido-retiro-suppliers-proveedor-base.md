# Recorrido: Caja usa `proveedores` y el proveedor pasa al sistema base

25-nov-2026, tarea **T-133**. Había dos catálogos de proveedores que no se conocían: `suppliers`
(Caja) y `proveedores` (Agencia). Ahora hay uno solo, del sistema base, que sirve para cualquier
giro. Lo que se aprende acá: **cómo se sube un modelo de un vertical al sistema base sin romper el
vertical**, **cómo se remapea una referencia sin FK** y **cómo se cambian permisos sin dejar a nadie
sin acceso ni darle de más**.

Archivos:
- `api-sistema-fe/app/Models/Proveedor.php` — modelo base y `scopeDeAlcance`
- `api-sistema-fe/app/Models/AgenciaViajes/Proveedor.php` — ahora es una subclase del base
- `api-sistema-fe/app/Http/Controllers/ProveedorController.php` — rutas `proveedores-base`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ProveedorController.php` — `index()` y `destroy()`
- `api-sistema-fe/app/Http/Controllers/Cash/CashMovementController.php` — `buscarContraparte()` y `resolverContraparte()`
- Migraciones en `database/migrations/tenant/core/`:
  `2026_11_25_200000_add_proveedores_permissions`, `2026_11_25_200100_remapear_contraparte_proveedor_de_caja`,
  `2026_11_25_200200_drop_suppliers_table`
- `admin-start-kit/src/views/cash/proveedores.vue` (antes `suppliers.vue`), `src/router/routes.ts`, `src/types/roles.ts`
- Seeders: `MenuItemsSeeder`, `PermissionsDemoSeeder`, `AgenciaViajesRolesSeeder`
- Borrados: `app/Models/Cash/Supplier.php`, `app/Http/Controllers/Cash/SupplierController.php`

**Al desplegar:** respaldo antes (la migración que borra `suppliers` no devuelve datos al revertirse);
`deploy.sh` migra, siembra el menú y corre `roles:sync-propietario`. Revisar la salida de
`tenants:migrate-verticales`.

---

## 1. Pantalla → tabla

| Pantalla | Llama a | Lee/escribe |
|---|---|---|
| Configuraciones → Proveedores (`/proveedores`) | `GET/POST/PUT proveedores-base` | `proveedores` (todos) |
| Agencia → Catálogos → Proveedores | `GET proveedores` (vertical) | `proveedores` (solo turísticos) |
| Caja → egreso con contraparte proveedor | `buscarContraparte`, luego `store` del movimiento | lee `proveedores` (todos); guarda id, nombre y documento en `cash_movements` |

En el menú de Agencia el ítem de Caja se llama ahora «Proveedores (todos)», para distinguirlo de
Catálogos → Proveedores. Su `codigo` (`ag_admin.proveedores_gastos`) no cambió, así el seeder lo
actualiza en lugar de crear otro.

## 2. El modelo sube al base, sin tocar los `use` de Agencia

Decenas de archivos de Agencia importan `App\Models\AgenciaViajes\Proveedor`. Cambiarlos todos
habría hecho la tarea enorme. La salida: el modelo base queda en `App\Models\Proveedor` y el de
Agencia **extiende** de él, sobre la misma tabla.

```php
class Proveedor extends ProveedorBase   // App\Models\AgenciaViajes\Proveedor
{
    use FotosConTipo;
    protected $fillable = [ /* los básicos + lo turístico: tipo_id, márgenes, fotos… */ ];
    // relaciones con servicios, alojamiento, amenidades, tarifas…
}
```

El base solo conoce los datos básicos (`CAMPOS_BASICOS`: razón social, documento, teléfono,
estado…). Una tienda o un prestamista usan el base; Agencia sigue usando la subclase, con todo lo suyo.

## 3. Un solo lugar decide qué es "turístico"

```php
public function scopeDeAlcance(Builder $query, ?string $alcance = null): Builder
{
    if ($alcance === self::ALCANCE_TODOS) {
        return $query;
    }
    return $query->whereIn('tipo_id',
        ProveedorTipo::where('giro', 'agencia_viajes')->pluck('id'));
}
```

- **Turístico** = su `tipo_id` es un tipo de giro `agencia_viajes` en `proveedor_tipos` (central).
  **General** = sin tipo, o con un tipo de otro giro.
- Por defecto el scope devuelve solo los turísticos: las ~9 pantallas de Agencia que cargan la lista
  no cambiaron. Caja y la pantalla base piden `ALCANCE_TODOS`.
- `proveedor_tipos` está en la base central y `proveedores` en la del tenant: no se puede hacer un
  JOIN. Por eso son dos pasos (`pluck` de ids en la central, `whereIn` en el tenant).
- No se pierde ningún proveedor viejo de Agencia: su alta siempre exigió `tipo_id`.

## 4. Caja lee `proveedores`

`buscarContraparte()` busca por razón social, nombre comercial o documento, solo los activos, y
devuelve los campos con los nombres que el frontend ya esperaba (`razon_social as name`,
`numero_documento as document`): la pantalla de Caja no cambió. `resolverContraparte()` valida que
el id exista en `proveedores` y guarda una **copia** del nombre y del documento en el movimiento.

Como `counterparty_id` no tiene FK, borrar un proveedor dejaría movimientos apuntando a nada. Por
eso `AgenciaViajes\ProveedorController::destroy()` responde 422 si el proveedor tiene movimientos de
Caja («desactívalo en su lugar»). La pantalla base no tiene botón de borrar: solo activar/desactivar.

## 5. La migración que remapea (`2026_11_25_200100`)

En producción había 0 `suppliers`, pero la migración debe ser segura si aparecen:

1. Por cada `supplier`, busca un proveedor con el mismo documento (sin espacios alrededor; si hay
   varios, gana el activo más antiguo). Si no hay coincidencia, crea un proveedor **general** (sin
   tipo). Si un texto no entra en la columna nueva, el texto completo queda en `observaciones`.
2. Lee **todos** los movimientos antes de escribir y los agrupa por id de destino. ¿Por qué? Los
   ids viejos (`suppliers`) y los nuevos (`proveedores`) pueden coincidir: si actualizaras de a un
   supplier, un movimiento ya remapeado al id 5 podría volver a remapearse cuando le toque al
   supplier 5.
3. Un `counterparty_id` que no existía en `suppliers` queda en `NULL`: conservarlo apuntaría a un
   proveedor ajeno. El nombre guardado en el movimiento no se toca, y tampoco los montos.

No tiene `down()`: después de unir no hay forma de saber cuál era el id anterior. La tabla se borra
en **otra** migración (`200200`), que corre después por el orden de los nombres.

## 6. Permisos: pasar de los viejos a los nuevos sin perder ni ganar

Permisos nuevos: `proveedores.ver` y `proveedores.gestionar`. La migración `200000` los crea y los
da según lo que cada rol (o usuario con permiso directo) ya podía hacer:

| Tenía | Recibe |
|---|---|
| `agencia.proveedores` (pantalla de Agencia) | ver + gestionar |
| `register_supplier` (podía crear en Caja) | ver + gestionar |
| solo `list_/edit_/delete_supplier` | ver |

Las rutas:
- `GET proveedores-base` y `GET proveedores-base/{id}` piden `ver` o `gestionar`.
- `POST` y `PUT` piden `gestionar`.
- Las rutas turísticas (`proveedores` del vertical) siguen pidiendo `agencia.proveedores`.

En la pantalla, `puedeGestionar` (con `authStore.isPermitedRoute('proveedores.gestionar')`) oculta
«Registrar» y la columna de acciones a quien solo puede ver. Los permisos `*_supplier` ya no se
siembran ni aparecen en Roles; sus filas siguen en las bases, sin gobernar ninguna ruta.

## 7. Cómo se prueba

- `ProveedorFiltroYCajaTest` — **CA1** (Caja encuentra a uno turístico y a uno general) y **CA3**
  (Agencia no muestra los generales).
- `RemapearContraparteProveedorTest` — **CA2**: unión por documento, alta sin coincidencia, ids que
  se pisan, id inexistente a `NULL`, nombre guardado intacto.
- `ProveedoresPermisosBackfillTest` y `GateBucketARoutesTest` — **CA5** y **CA7**.
- `ProveedorBaseTest` — **CA6** con HTTP real en un tenant de tienda (con permiso crea y busca; sin
  permiso, 403) y **CA4** (un tenant nuevo queda sin `suppliers`).

## 8. Lo que quedó para después

- Agencia → Proveedores no tiene el control «ver todos» (el backend ya acepta `?alcance=todos`).
- Los tipos generales («Insumos», «Mercadería») no se pueden crear como generales: el CRUD del
  superadmin guarda todo tipo con `giro='agencia_viajes'`.
- `TenantProvisioningService::eliminarSiVacio()` no cuenta `proveedores` en tiendas ni créditos.
- Campos nuevos del proveedor y consulta RUC: **T-134**.
