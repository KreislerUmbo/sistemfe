# T-120 — Blindaje de ventas y usuarios: línea ajena, descuento mayor al subtotal y rol por defecto Super-Admin

| | |
|---|---|
| **Módulo** | VEN + PLA |
| **Depende de** | ninguna |
| **Reglas** | CLAUDE.md «Dinero y caja», «Ventas» (atomicidad de `update()`) |
| **Decisiones** | ninguna (las de abajo, aprobadas al armar la tarea el 23-nov-2026) |
| **Rama** | `fix/T-120-blindaje-ventas-y-usuarios` |

## Contexto
Tres hallazgos de «Por analizar» (T-113, T-114 y T-109) que pueden dañar datos o
seguridad en producción, en todos los giros. Son independientes entre sí; van juntos porque
cada uno es chico. Un commit por parte (A-C). La parte D original (rutas `sale_payments`/
`sale_details` sin caja) pasó a T-121 (decisión del usuario, 23-nov-2026).

## Alcance

### A. `update()` de una venta modifica una línea de otra venta (T-113)
`SaleController::update()` toma cada línea existente con `SaleDetail::find($detalle['id'])`
sin comprobar que sea de esa venta: un `id` de otra venta modifica la línea ajena (y si no
existe, se ignora en silencio).
- Validar **antes** de `DB::beginTransaction()` (mismo patrón que `validarTotalesDeVenta()`)
  que todo `sale_details[*].id` no vacío pertenezca a la venta `$id`; si alguno no, 422 y no se
  toca nada.
- Dentro de la transacción, buscar con `SaleDetail::where('sale_id', $venta->id)->find(...)`
  (defensa en profundidad).

### B. Descuento global mayor que el subtotal (T-114)
Con `cambiar_precio_venta`, un `discount_global` mayor que el subtotal deja una venta con total
negativo que pasa `TotalesVentaValidator` (es coherente consigo misma).
- En `TotalesVentaValidator::calcular()` (o `validar()`), rechazar si `discount_global < 0` o
  `discount_global > bruto` (suma de `subtotal` de las líneas que cobran, sin las `11`).
  Mensaje en español, mismo estilo que los rechazos existentes (`$this->rechazo(...)`).
- Vale para `store()` y `update()` (los dos llaman a `validarTotalesDeVenta()`).

### C. Usuario sin rol: la pantalla lo trata como Super-Admin (T-109)
`users.role_id` tiene `default(1)` (migración `2026_07_13_090100_fix_users_add_missing_columns`)
y el rol 1 es Super-Admin. `POST /api/users` sin `role_id` hoy: `protegerPrivilegios()` no lo
frena (mira `filled('role_id')`), `User::create($request->all())` lo guarda con `role_id=1`,
`assignRole(null)` no asigna nada. El backend (Spatie) no le da permisos, pero el login
devuelve `role.name = 'Super-Admin'` y `auth.ts::isPermitedRoute()` le abre todas las pantallas.
- `UserController::store()`: `role_id` obligatorio y existente (`required|exists:roles,id`,
  guard `api`), antes de `protegerPrivilegios()`.
- Migración nueva en `database/migrations/tenant/core/`: quitar el default de `users.role_id`
  (`->default(null)->change()` o `DB::statement('ALTER TABLE users ALTER COLUMN role_id DROP DEFAULT')`).
  No editar la migración vieja.
- `AuthController` (login, ~línea 99) y `UserResource` (~línea 29): que no revienten si
  `role` es null (`role?->id`, `role?->name`). Un usuario sin rol entra sin permisos de pantalla.
- Revisar en el tenant de prueba que no haya usuarios con `role_id = 1` sin el rol Spatie
  `Super-Admin` (consulta en «Cómo verificar»). Producción: lo corre el usuario y se reporta;
  no se corrigen datos en esta tarea.


## Fuera de alcance
- Recalcular `debt`/`paid_out`/`state_payment` en el backend (otra línea de «Por analizar»).
- Cambiar cómo el frontend decide Super-Admin (`isPermitedRoute` sigue leyendo `role.name`):
  con C ya no llega un Super-Admin falso.
- Corregir usuarios existentes en producción con `role_id=1` sin rol Spatie (si aparecen,
  se decide aparte).
- Retirar `sale_payments`/`sale_details`: ver T-121.

## Dónde tocar
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` → `update()` (~1087; validador
  nuevo junto a `validarTotalesDeVenta($request)` ~1203; `SaleDetail::find` ~1388)
- `api-sistema-fe/app/Services/Ventas/TotalesVentaValidator.php` → `calcular()` (~105)
- `api-sistema-fe/app/Http/Controllers/User/UserController.php` → `store()` (~78)
- `api-sistema-fe/app/Http/Controllers/AuthController.php` (~99) y
  `app/Http/Resources/User/UserResource.php` (~29)
- `api-sistema-fe/database/migrations/tenant/core/2026_11_24_000000_quitar_default_role_id_users.php` (nueva)

## Criterios de aceptación
- [x] **CA1** — Dada una venta A y una línea de la venta B, cuando se hace `PATCH sales/A` con
  `sale_details[].id` de la línea de B, entonces 422 y ni A ni la línea de B cambian →
  test: `Ventas/T120BlindajeVentasTest::update_rechaza_una_linea_de_otra_venta`
- [x] **CA2** — `PATCH` normal con sus propias líneas sigue funcionando → los tests de edición
  existentes (`CronogramaEdicionVentaTest`, `AnticipoEdicionVentaTest`, `DescripcionLineaVentaTest`) en verde
- [x] **CA3** — Con `cambiar_precio_venta`, `discount_global` mayor que el subtotal → 422 en
  `store()` y en `update()`; igual al subtotal → pasa; negativo → 422 →
  test: `T120BlindajeVentasTest::descuento_global_*`
- [x] **CA4** — `POST users` sin `role_id` → 422 y no se crea el usuario; con `role_id`
  inexistente → 422 → test: `T120BlindajeUsuariosTest::store_exige_role_id`
- [x] **CA5** — Tras la migración, un `User::create()` sin `role_id` queda con `role_id` null
  y el login responde 200 con `role` null (sin 500) → test: `T120BlindajeUsuariosTest::usuario_sin_rol_no_es_super_admin`

## Seguridad y correctitud
- [x] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [x] Aislamiento de tenant: A cierra un cruce entre ventas del mismo tenant
- [x] Validación de entrada / archivos: A, B y C son validaciones nuevas
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [x] Dinero en centavos: B compara con `BigDecimal`, como el resto del validador
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [x] Migraciones nuevas + `tenants:migrate-verticales`: C (en `core/`)

## Cómo verificar
```
php artisan test --filter='T120|T114TotalesVenta|UserControllerFase2d|UsuariosSuperAdmin|RolPropietario|CronogramaEdicionVenta|AnticipoEdicionVenta|DescripcionLineaVenta'
php artisan test   # suite completa al final (Postgres real)
php artisan tenants:migrate-verticales
```
Usuarios con `role_id=1` sin el rol Spatie (en cada tenant; en producción lo corre el usuario):
```sql
SELECT u.id, u.email FROM users u
WHERE u.role_id = 1 AND NOT EXISTS (
  SELECT 1 FROM model_has_roles m JOIN roles r ON r.id = m.role_id
  WHERE m.model_id = u.id AND r.name = 'Super-Admin');
```
Prueba funcional: crear y editar una venta en el panel (contado y con
descuento global) y crear un usuario desde Usuarios.

---

## Resultado
- **Commits:** `613adbc6` (A), `4c759c5a` (B), `4399c0d5` (C)
- **Tests:** nuevos `Ventas/T120BlindajeVentasTest` (5) y `T120BlindajeUsuariosTest` (3).
  Filtro de «Cómo verificar» + `GateBucketBRoutes`, `SeguridadRegistroYUsuarioInactivo`,
  `UsuarioEdicionPassword`: 147 en verde (revisión del 23-nov). Suite completa (Postgres):
  2448 en verde y 1 falla, el intermitente conocido `Fila13Fase1cFacturacionTest::descuento_del_combo…`
  (pasa 3 de 3 corrido solo).
- **Prueba funcional** (`admin-start-kit/e2e/verif-t120-blindaje.mjs`, agencia-demo, 23-nov):
  editar la venta 35 con descuento global 999999 → el PATCH real da 422 y la venta no cambia;
  en la edición lo frena antes la regla del anticipo (`anticipoDeLaEdicion()`: anticipo 0 > total
  negativo), así que el mensaje de B se ve en `store()` y en payloads sin los campos del anticipo
  (cubierto por los tests). Usuarios: la lista carga, sin rol la pantalla frena, con rol «Cajero»
  se crea (200, `role` correcto) y se borró al final.
- **Desviaciones de la tarea y por qué:** la parte D pasó a T-121 (decisión del usuario,
  23-nov): falta confirmar en los logs de producción que nadie usa esas rutas.

## Hallazgos fuera de alcance
- A: si el payload trae dos veces el mismo `id` de línea, pasa la validación y la línea se
  actualiza dos veces (gana la última). Sin daño; no se arregla.
- Pantalla de venta (`TotalesVenta.vue::cambiar()`): el descuento global solo se limita a ≥ 0;
  acepta uno mayor que el subtotal, muestra «Monto a pagar» negativo y el aviso que sale al
  guardar no habla del descuento («los pagos superan…» o «el anticipo no puede ser mayor…»).
  Limitarlo al subtotal en la pantalla con un aviso claro. → «Por analizar».
