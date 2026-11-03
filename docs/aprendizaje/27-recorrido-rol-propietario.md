# Recorrido: el rol Propietario y la cuenta de soporte

03-nov-2026, tarea **T-081**. Afecta a todos los giros y a cada alta de tenant nueva.

---

## 1. El problema

Al crear un tenant, `TenantProvisioningService::provision()` creaba un solo usuario, el del dueño,
con el rol **Super-Admin**. Pero Super-Admin es la cuenta de soporte de UmboSystem:

- se salta **todos** los permisos (`Gate::before` en `AppServiceProvider`);
- nadie más lo puede editar;
- algunas pantallas lo tratan aparte (por ejemplo, no recibe los avisos de cobranza de Créditos).

Resultado: el dueño y el soporte eran la misma cosa, y en la pantalla Roles no se veía qué podía
hacer el dueño, porque no tenía permisos asignados: los tenía todos «por regla».

## 2. Los tres niveles

```
Super-Admin    soporte de Umbo     se salta todos los permisos (Gate::before)
Propietario    el dueño            TODOS los permisos de su giro, asignados de verdad
Administrador  y demás roles       los crea el dueño, con lo que él decida
```

La diferencia clave entre Super-Admin y Propietario: el Propietario **no** usa `Gate::before`.
Tiene cada permiso guardado en `role_has_permissions`, como cualquier rol. Por eso se ve en Roles,
y si algún día un permiso tiene que ser solo de soporte, basta con quitárselo.

## 3. Archivos

| Archivo | Qué es |
|---|---|
| `app/Services/Roles/RolPropietario.php` | **nuevo**: el nombre, `sincronizar()`, `quedaOtroActivo()`, `es()` |
| `app/Services/Roles/CuentaSoporte.php` | **nuevo**: crea `soporte@umbosystem.com` si falta |
| `app/Console/Commands/SyncRolPropietario.php` | **nuevo**: `roles:sync-propietario` |
| `app/Console/Commands/AsignarRolPropietario.php` | **nuevo**: `roles:asignar-propietario` |
| `TenantProvisioningService::provision()` | el dueño nace Propietario; se crea soporte |
| `UserController` | protección del Propietario y del último activo |
| `RoleController` | el rol Propietario no se edita ni elimina |
| `TenantAdminController::resetAdminPassword()` | a quién se le restablece la contraseña |
| `deploy-ovh/scripts/deploy.sh` (paso 7) | corre `roles:sync-propietario` |
| `views/users/index.vue`, `views/roles/index.vue`, `central-panel/…/TenantDetailView.vue` | pantallas |

## 4. El recorrido

### 4.1 Siempre al día: `sincronizar()`

```php
$rol      = Role::firstOrCreate(['name' => 'Propietario', 'guard_name' => 'api']);
$deseados = Permission::where('guard_name', 'api')->whereNotIn('name', PERMISOS_SOLO_SOPORTE)->get();
// compara con los actuales; si hay diferencia:
$rol->syncPermissions($deseados);
```

Cada tenant solo tiene en su base los permisos de su giro, así que «todos los permisos del tenant»
es lo mismo que «todos los de su giro».

`syncPermissions` reemplaza la lista entera: lo que sobra se quita. Por eso **solo** se usa con el
Propietario, que es el único rol cuyo contenido decide el sistema. A los demás roles los seeders
solo les **agregan** permisos (`givePermissionTo`), para no borrar lo que el dueño configuró.

`deploy.sh` corre `roles:sync-propietario` en cada despliegue: cuando una función nueva crea su
permiso (por ejemplo, `reservas.modificar`), el dueño lo recibe solo.

### 4.2 El alta de un tenant

```
provision()
  ├─ migraciones y seeders del giro         ← acá nacen los permisos
  ├─ RolPropietario::sincronizar()          ← después, para que reciba los del giro
  ├─ User::create(dueño, role_id = Propietario) + assignRole
  └─ CuentaSoporte::asegurar()              ← soporte@umbosystem.com, Super-Admin
```

**¿Por qué `role_id` explícito?** La tabla `users` tiene una columna `role_id` con `default(1)`,
y la pantalla Usuarios y el login leen el rol de ahí, no de Spatie. Si no se fija, el usuario
aparece con el rol id 1, que es Super-Admin. Así nacían los dueños hasta ahora.

La cuenta de soporte nace con una contraseña aleatoria de 40 caracteres que no se muestra ni se
guarda. Para entrar, Umbo la restablece desde el Panel Superadmin.

### 4.3 La protección del Propietario

Dos reglas distintas en `UserController`:

**a) Quién puede tocarlo** (`protegerPrivilegios()`, 403):

```
¿el que opera es Super-Admin?  → puede todo
¿el que opera es Propietario?  → puede tocar a otro Propietario y dar ese rol
cualquier otro                 → 403 si el objetivo es Propietario o si intenta dar ese rol
```

**b) Nunca sin dueño** (`rechazoUltimoPropietario()`, 422): al último Propietario activo no se le
puede cambiar el rol, ni ponerlo Inactivo, ni eliminarlo. Rige **también** para un Super-Admin y
para él mismo: «Es el único propietario: nombra a otro antes».

```php
DB::transaction(function () {
    // quedaOtroActivo() hace SELECT … FOR UPDATE de todos los Propietarios
    if (es Propietario && ! quedaOtroActivo($user)) return 422;
    ... el cambio ...
});
```

**¿Por qué el lock?** Con dos Propietarios, A y B, si a la vez alguien desactiva a A y otro a B, sin
lock cada uno vería «queda el otro» y los dos pasarían: el tenant quedaría sin dueño. Con
`lockForUpdate()` el segundo espera a que termine el primero, y entonces ve que ya no queda nadie.

### 4.4 El arreglo de la revisión

Antes, `UserController::update()` hacía esto si el pedido no traía `role_id`:

```php
if ($user->role_id != $request->role_id) {   // 5 != null → true
    $user->removeRole($actual);               // le quita el rol
    $user->assignRole(Role::find(null));      // no le da ninguno
}
```

El usuario quedaba **sin rol**, y así se podía dejar al último Propietario sin serlo sin pasar por
el 422. Ahora, sin `role_id`, el rol no cambia.

### 4.5 Tenants que ya existen

`roles:asignar-propietario {tenant} {email} [--simular]` no adivina quién es el dueño: lo dice quien
lo corre. Le quita Super-Admin (y el rol que tenía en `role_id`), le da Propietario y crea la cuenta
de soporte si falta. Con `--simular` solo dice qué haría. Una segunda corrida dice «Nada que hacer».

## 5. Para desplegar

1. `deploy.sh` corre `roles:sync-propietario` en todos los tenants.
2. Por tenant: `roles:asignar-propietario dakamu dakamu@gmail.com --simular`, revisar, y sin
   `--simular`. Lo mismo en credishirley: **su dueño empezará a recibir los avisos de cobranza**
   (ese servicio solo deja afuera al Super-Admin). market solo si se decide.
3. Revisar si el tenant tiene otros Super-Admin: el comando solo cambia al usuario indicado.
4. Restablecer la contraseña de `soporte@umbosystem.com` desde el Panel Superadmin (con ese correo
   en «Email del admin»).

## 6. Tests

`tests/Feature/RolPropietarioTest.php` (7): la sincronización, quién puede tocar a un Propietario,
el último activo, editar sin `role_id`, el rol en Roles.
`tests/Feature/RolPropietarioTenantTest.php` (4, con tenants reales): el alta en los tres giros,
el comando de sincronización, `roles:asignar-propietario` y el restablecimiento de contraseña.
