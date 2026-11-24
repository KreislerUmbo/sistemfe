# Recorrido: blindaje de ventas y usuarios

23-nov-2026, tarea **T-120**. Tres huecos chicos que venían de pruebas anteriores. Los tres tienen la misma
causa de fondo: **el backend confiaba en un dato que llega del cliente o en un valor por defecto**. El arreglo
es el mismo en los tres casos: validar antes de escribir y no dejar que un valor por defecto decida algo
importante.

Archivos:
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` → `validarLineasDeLaVenta()`, `update()`
- `api-sistema-fe/app/Services/Ventas/TotalesVentaValidator.php` → `calcular()`
- `api-sistema-fe/app/Http/Controllers/User/UserController.php` → `store()`
- `api-sistema-fe/app/Http/Controllers/AuthController.php`, `app/Http/Resources/User/UserResource.php`
- `api-sistema-fe/database/migrations/tenant/core/2026_11_24_000000_quitar_default_role_id_users.php`
- Tests: `tests/Feature/Ventas/T120BlindajeVentasTest.php`, `tests/Feature/T120BlindajeUsuariosTest.php`;
  prueba en el panel: `admin-start-kit/e2e/verif-t120-blindaje.mjs`

**Al desplegar:** backend; `deploy.sh` corre `tenants:migrate-verticales` (la migración está en `core/`, afecta
a todos los tenants). Después, en producción, correr la consulta de «usuarios con `role_id = 1` sin el rol
Super-Admin» (sección 3).

---

## 1. Una venta que modificaba la línea de otra (parte A)

**Pantalla:** Ventas → editar → «Guardar cambios» → `PATCH /api/sales/{id}` → `SaleController::update()`.

El payload trae `sale_details[]`; las líneas que ya existen vienen con su `id`. Antes, `update()` hacía:

```php
$detalle_bd = SaleDetail::find($detalle['id']);   // busca en TODA la tabla
```

`find()` no sabe de qué venta se trata: si alguien manda el `id` de una línea de **otra** venta, la modifica.
Ahora hay dos capas:

1. `validarLineasDeLaVenta()`, **antes** de `DB::beginTransaction()`: cuenta cuántos de los `id` enviados son
   de esta venta (`where('sale_id', $venta->id)->whereIn('id', $ids)`). Si no son todos, 422 y no se toca nada.
2. Dentro de la transacción, `SaleDetail::where('sale_id', $venta->id)->find(...)` (defensa en profundidad:
   si mañana alguien quita el paso 1, el 2 sigue protegiendo).

**Patrón para recordar:** cada vez que se busca algo por un `id` que manda el cliente, filtrar también por el
«dueño» (la venta, el usuario, la reserva). Es el mismo problema que resuelve `ReservaPolicy` en Agencia.

**¿Por qué antes de la transacción?** Los validadores de `SaleController` van todos antes de
`DB::beginTransaction()`: un 422 ahí no deja nada a medias, y el `catch` de `update()` no tiene que distinguir
un rechazo de un error real.

## 2. Un descuento global mayor que el subtotal (parte B)

**Tablas:** `sales.discount_global` → se reparte entre las líneas en proporción a su subtotal.

`TotalesVentaValidator` (T-114) recalcula los totales a partir de las líneas y los compara con la cabecera. Con
el permiso `cambiar_precio_venta`, un descuento de 999 999 daba una venta con total negativo, pero **coherente
consigo misma**: las cuentas cuadraban, así que pasaba. Ahora `calcular()` rechaza antes:

- `discount_global` negativo → 422.
- `discount_global` mayor que el subtotal de las líneas que cobran (sin las gratuitas `11`) → 422.
- Igual al subtotal → pasa (la venta queda en 0).

Se compara con `BigDecimal`, como el resto del validador: nada de `float` para dinero.

**Lo que mostró la prueba en el panel:** en la **edición**, el 422 llega por otra regla que ya existía,
`anticipoDeLaEdicion()`: con el total negativo, «anticipo 0 > total». El mensaje propio de B se ve al **crear**
una venta. Además, la pantalla deja escribir ese descuento y muestra «Monto a pagar» negativo; quedó en
«Por analizar» del backlog.

## 3. Un usuario sin rol que la pantalla trataba como Super-Admin (parte C)

Aquí hay **dos** fuentes del rol, y es clave entender la diferencia:

| Dónde | Qué es | Quién lo usa |
|---|---|---|
| `users.role_id` → `roles` | Una columna simple | La pantalla: el login devuelve `role.name` y `auth.ts::isPermitedRoute()` deja pasar todo si es `'Super-Admin'` |
| `model_has_roles` (Spatie) | El rol «de verdad» | El backend: `hasRole()`, `can()`, `Gate::before` |

`users.role_id` tenía `default(1)`, y el rol 1 es Super-Admin. Un `POST /api/users` sin `role_id` pasaba
`protegerPrivilegios()` (solo mira `filled('role_id')`), se guardaba con `role_id = 1` y `assignRole(null)` no
asignaba nada. Resultado: **el backend no le daba permisos, pero la pantalla le abría todas las opciones.**

Arreglo en tres piezas:
1. `UserController::store()`: `role_id` obligatorio y existente (`Rule::exists('roles','id')->where('guard_name','api')`).
2. Migración nueva que **quita el default** (`ALTER COLUMN role_id DROP DEFAULT`). Nunca se edita la vieja.
3. `AuthController` y `UserResource` con `role?->id` / `role?->name`: un usuario sin rol entra con
   `role.name = null`, sin permisos, y no da un error 500.

Consulta para producción (en cada tenant):
```sql
SELECT u.id, u.email FROM users u
WHERE u.role_id = 1 AND NOT EXISTS (
  SELECT 1 FROM model_has_roles m JOIN roles r ON r.id = m.role_id
  WHERE m.model_id = u.id AND r.name = 'Super-Admin');
```
Si sale alguien, es un usuario que la pantalla trata como Super-Admin sin serlo: se decide aparte qué rol darle.

## 4. Lo que quedó para otra tarea

Las rutas `POST`/`DELETE` de `sale_payments` y `sale_details` cambian la deuda o el total de una venta **sin
movimiento de caja**. Ninguna pantalla las usa, pero antes de retirarlas hay que confirmar en los logs de
producción que el portal tampoco: ver **T-121**.

## 5. Ejercicios

1. En la parte A, ¿por qué no basta con el paso 2 (el `where('sale_id')` dentro de la transacción)? (Pista: ¿qué
   pasa hoy si el `find` no encuentra la línea?)
2. ¿Qué otro endpoint del sistema busca algo por un `id` que manda el cliente sin filtrar por su dueño? Busca
   `::find($request->` en `app/Http/Controllers`.
3. Un usuario creado por tinker sin `role_id`, después de esta tarea: ¿qué ve en la pantalla y qué puede hacer
   en el backend?
4. ¿Por qué la migración de la parte C va en `tenant/core/` y no en `verticals/`?
