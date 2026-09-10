# Auditoría — menú actual de `admin-start-kit` + gates de autorización

> Fase 0 de `docs/planning/plan-modulo-menus-y-roles.md` (Parte 2 del brief + §9.1 del
> plan). Solo inventario/evidencia — no se creó `menu_items`, no se tocó el árbol de
> navegación, no se sembró ningún rol/permiso nuevo. La Parte 1 (fix del bug de permisos
> legacy) se documentó en `docs/planning/retail-facturacion-core/plan-modulo-caja.md`,
> nota agregada 10-sep-2026 junto al hallazgo original de Fase 5.
> Fecha: 10-sep-2026.

---

## Nota sobre el brief y el plan de referencia

El brief pegado en esta sesión decía leer `docs/planning/claude/plan-modulo-menus-y-roles.md`
antes de empezar. Ese archivo no existía en el repo al momento de arrancar (ni la carpeta
`docs/planning/claude/`, ni ninguna mención a "menú" en `INDICE.md`) — apareció en
`docs/planning/plan-modulo-menus-y-roles.md` (sin el subdirectorio `claude/`) recién a los
pocos minutos de haber arrancado esta sesión, aparentemente guardado desde otra ventana en
paralelo. Se confirmó con el usuario seguir solo con el alcance de Fase 0 (Parte 1 + Parte 2
del brief) documentando esto como hallazgo, sin bloquear el trabajo. El plan completo, una
vez disponible, agrega un punto que el brief pegado no traía explícito (§9.1: auditoría de
rutas backend sin gate de permisos) — se incluyó también acá porque el propio brief pedía
leer §7 "donde está el diseño completo detrás de este brief", y ese punto queda listado ahí
como parte de Fase 0. Es solo inventario, igual que el resto de este documento — no se
corrigió ninguna ruta.

---

## 1. ¿De dónde sale el menú hoy?

Estructura estática en el frontend, tal como sospechaba el plan (§1) — **no sale de ningún
endpoint**. Tres piezas:

1. **[`src/assets/data/menu-items.ts`](../../../admin-start-kit/src/assets/data/menu-items.ts)**
   (426 líneas) — el array `MENU_ITEMS: MenuItemType[]`, hardcodeado a mano, es la única
   fuente de verdad del árbol de navegación. Editar el menú hoy significa editar este
   archivo y hacer un deploy nuevo del frontend — no hay forma de cambiarlo por tenant/rol
   sin tocar código.
2. **[`src/helpers/menu.ts`](../../../admin-start-kit/src/helpers/menu.ts)** —
   `getMenuItems()` (línea 6) recorre `MENU_ITEMS` y filtra en el CLIENTE contra
   `useAuthStore().isPermitedRoute()`, ítem por ítem. El filtrado nunca pasa por el backend.
3. **[`src/layouts/components/LeftSideBar.vue`](../../../admin-start-kit/src/layouts/components/LeftSideBar.vue)**
   línea 9 — `<AppMenu :menu-items="getMenuItems()" />`, llamado inline en el template (no en
   un `computed()` explícito, pero como el render de este componente corre dentro del efecto
   reactivo de Vue y `getMenuItems()` lee `user.value` de Pinia por transitividad, sí se
   re-evalúa cuando cambia el usuario autenticado — confirmado por lectura de código, no
   probado en vivo con un cambio de sesión real).

No hay ninguna pieza "intermedia" (sin JSON de config, sin tabla en BD) — es 100% hardcode
en TypeScript.

## 2. Inventario completo de ítems actuales

Fuente: `menu-items.ts` completo, 10 grupos de primer nivel. `permission` = string único
(match exacto); `permissions` (plural, solo en headers `isTitle:true`) = el header se
muestra si CUALQUIERA de esos permisos está presente (ver lógica en §4 de
`helpers/menu.ts`, líneas 29-39).

| Grupo | Ítem | Label | Ruta (`name`) | Ícono | Permiso |
|---|---|---|---|---|---|
| — | Dashboards | Dashboards | `dashboards.analytics` | `iconoir-home-simple` | `all` (siempre visible) |
| **ADMIN PORTAL** *(header, visible si algún permiso de la lista)* | | | | | `list_categorie_system`, `edit_categorie_system`, `list_system`, `list_plain`, `edit_plain` |
| | Categorías Sistemas | Categorias Sistemas | `system_categories.index` | `fas fa-life-ring` | `list_categorie_system` |
| | Sistemas › Registrar | Registrar | `system.register` | — | `register_system` |
| | Sistemas › Listar | Listar | `systems.index` | `fa-brands fa-windows` | `list_system` |
| **ACCESS** *(header)* | | | | | `list_role`, `list_user` |
| | Roles y Permisos | Roles y Permisos | `access.roles` | `fas fa-unlock` | `list_role` |
| | Usuarios | Usuarios | `access.users` | `fas fa-users` | `list_user` |
| **COMERCIAL** *(header)* | | | | | `list_categorie`, `list_product`, `register_product`, `list_client`, `register_sale`, `list_sale`, `register_guia_remision`, `list_guia_remision` |
| | Categorías | Categorias | `categories.index` | `fas fa-life-ring` | `list_categorie` |
| | Productos › Registrar | Registrar | `product.register` | — | `register_product` |
| | Productos › Listar | Listar | `product.index` | `fas fa-qrcode` | `list_product` |
| | Clientes | Clientes | `clients.index` | `fas fa-user-plus` | `list_client` |
| | Ventas › Mis Ventas | Mis Ventas | `sale.list` | `fas fa-money-check-alt` | `list_sale` |
| | Ventas › NC/ND | Notas de Crédito/Débito | `nota.list` | — | `list_nota_electronica` |
| | Ventas › Anticipos | Emitir Anticipos | `advances.index` | — | `list_advance` |
| | Ventas › Por Cobrar | Ventas por Cobrar | `credit_receivables.index` | — | `list_sale` *(reusa el permiso de Ventas, sin uno propio)* |
| | Ventas › Cotiz. Comerciales | Cotizaciones Comerciales | `commercial-quotes.index` | — | `list_commercial_quote` |
| — | Caja › Turno Activo | Turno Activo | `cash.session` | `fas fa-cash-register` | `cash.open_session` |
| | Caja › Historial y Reportes | Historial y Reportes | `cash.dashboard` | — | `cash.view_all` |
| **Agencia de Viajes** *(grupo, solo giro `agencia_viajes` en la práctica — no hay `giro` como campo real, se filtra solo porque ningún tenant retail tiene los permisos `agencia.*` asignados)* | Cotizador | Cotizador | `agencia.cotizador.index` | `fas fa-suitcase-rolling` | `agencia.cotizaciones` |
| | Reservas | Reservas | `agencia.reservas.index` | — | `agencia.reservas` |
| | Reporte Operativo | Reporte Operativo | `agencia.reporteOperativo.index` | — | `agencia.reservas` *(mismo permiso que Reservas)* |
| | Salidas Operativas | Salidas Operativas | `agencia.salidas.index` | — | `agencia.reservas` *(ídem)* |
| | Venta Directa | Venta Directa | `agencia.ventaDirecta` | — | `agencia.reservas` *(ídem)* |
| | Proveedores | Proveedores | `agencia.proveedores.index` | — | `agencia.proveedores` |
| | Destinos y Atractivos | Destinos y Atractivos | `agencia.destinos.index` | — | `agencia.destinos` |
| | Temporadas | Temporadas | `agencia.temporadas.index` | — | `agencia.temporadas` |
| | Guías Turísticos | Guías Turísticos | `agencia.guias.index` | — | `agencia.guias` |
| | Paquetes / Tours | Paquetes / Tours | `agencia.paquetes.index` | — | `agencia.paquetes` |
| | Configuración | Configuración | `agencia.configuracion.index` | — | `agencia.configuracion` |
| | Códigos y numeración | Códigos y numeración | `agencia.configuracion.codigos` | — | `agencia.configuracion` *(mismo permiso que Configuración)* |
| — | Guía de Remisión › Registrar | Registrar | `dashboards.ecommerce` ⚠️ | `fas fa-file-alt` | `register_guia_remision` |
| | Guía de Remisión › Listar | Listar | `dashboards.ecommerce` ⚠️ | — | `list_guia_remision` |
| — | Configuraciones › Empresa | Datos de la empresa | `company.index` | `fas fa-wrench` | `company` |
| | Configuraciones › Sucursales | Sucursales | `branches.index` | — | `list_branch` |
| | Configuraciones › Cajas | Cajas | `cash-registers.index` | — | `list_cash_register` |
| | Configuraciones › Métodos de Pago | Métodos de Pago | `payment-methods.index` | — | `list_payment_method` |
| | Configuraciones › Series | Series de Comprobantes | `series-comprobante.index` | — | `list_serie_comprobante` |
| | Configuraciones › Proveedores | Proveedores | `suppliers.index` | — | `list_supplier` |
| | Configuraciones › Conceptos Caja | Conceptos de Caja | `cash-concepts.index` | — | `list_cash_concept` |
| **RECURSOS CLIENTE** *(header)* | | | | | `list_recurso`, `register_recurso` |
| | Recursos › Listar | Listar | `recursos.index` | `fas fa-qrcode` | `list_recurso` |
| | Recursos › Registrar | Registrar | `recurso.register` | — | `register_recurso` |
| | Recursos › Manual | Manual del Sistema | `recurso.manual` | — | `register_recurso` *(mismo permiso que Registrar)* |

**⚠️ Hallazgo real (no corregido, solo documentado):** los dos ítems de "Guía de Remisión"
(`menu-items.ts` líneas 305-326) apuntan a `route: { name: "dashboards.ecommerce" }` — el
módulo de Guía de Remisión de SUNAT no está implementado, el ítem del menú existe pero
navega al dashboard de e-commerce, no a ninguna pantalla real de Guía de Remisión.
Presumiblemente un placeholder dejado a propósito desde antes de este proyecto (no hay
ninguna vista `guia-remision/*` en `router/routes.ts`) — nunca se corrigió y sigue en el
menú de "COMERCIAL" hoy.

## 3. Rutas registradas en Vue Router que NO aparecen en el menú

95 nombres de ruta registrados en `router/routes.ts` contra 42 nombres usados en
`menu-items.ts` (ver `route.name`). La diferencia, filtrada:

**Categoría A — rutas de detalle/creación/edición, alcanzadas por botón desde una pantalla
que SÍ está en el menú (esperado, no es un hueco real):**
`advances.show`, `agencia.cotizador.editar`, `agencia.cotizador.nueva`,
`agencia.destinos.create`, `agencia.destinos.edit`, `agencia.guias.show`,
`agencia.paquetes.create/edit/show`, `agencia.proveedores.create/edit/show`,
`agencia.reservas.detalle`, `agencia.salidas.detalle`, `commercial-quotes.create/edit/show`,
`credit_receivables.client`, `notas.create`, `product.edit`, `sale.edit`, `sale.nota`,
`sistemas.detalle`.

**Categoría B — infraestructura de auth/errores, no son ítems de navegación:**
`auth.lock-screen`, `auth.maintenance`, `auth.register`, `auth.reset-pass`, `auth.sign-in`,
`error.404`, `error.500`.

**Categoría C — portal e-commerce, es otra app (fuera del alcance de este menú admin):**
`portal.carrito`, `portal.catalogo`, `portal.checkout`, `portal.contactos`,
`portal.favoritos`, `portal.home`, `portal.login`, `portal.micuenta*` (6 rutas),
`portal.nosotros`, `portal.ofertas`, `portal.producto`, `portal.register`,
`portal.serviciotecnico`, `portal.sistemas`.

**Categoría D — hallazgos reales, sin ningún botón que las enlace en todo el código
frontend (confirmado con grep, cero referencias fuera de su propia definición de ruta):**

- **`categorie_systems.edit`** (`router/routes.ts:168`, componente
  `views/portal/admin/categories/edit.vue`, permiso `edit_categorie_system`) — huérfana. Solo
  alcanzable escribiendo la URL a mano con un `id` válido. `system_categories/index.vue` (el
  listado, sí en el menú) no tiene ningún link/router-push hacia esta ruta.
- **`cash.history`** (`router/routes.ts:291`, componente `views/cash/history.vue`, permiso
  `cash.open_session|cash.view_all` — más permisivo que `cash.dashboard`, que exige
  únicamente `cash.view_all`) — **inconsistencia real, ver §5**.

## 4. `isPermitedRoute()` — nombre, firma y fuente real

**[`src/stores/auth.ts:46`](../../../admin-start-kit/src/stores/auth.ts#L46)**,
Pinia store `auth_store`:

```ts
const isPermitedRoute = (permission: string) => {
  let USER = user.value;
  if (USER && USER.role?.name != 'Super-Admin') {
    let permissions = USER.permissions;
    if (permission == 'all') { return true; }
    let opciones = permission.split('|');           // soporte "a|b" = OR
    return opciones.some((p) => permissions?.includes(p));
  }
  return true;  // Super-Admin bypasea todo, o no hay usuario → true por defecto
}
```

Recibe un string (un permiso, o varios separados por `|` para OR — el mismo criterio que ya
usa el middleware `permission:` de Spatie en el backend). Lee `permissions` de
`USER = user.value`, que es exactamente el objeto guardado en `localStorage`/Pinia por
`saveSession()` al momento del login — **el mismo array `user.permissions` que arma
`AuthController::respondWithToken()`** (confirmado, `types/auth.ts:9` declara
`permissions?: Array<string>`, mismo shape que el `->pluck('name')` del backend). Esto
confirma la premisa del brief: el bug de la Parte 1 SÍ afectaba directamente a este guard —
ya corregido.

Tres consumidores de `isPermitedRoute()`:
1. `router/index.ts:53` — guard global `router.beforeEach()`, bloquea la navegación si
   `!isPermitedRoute(routeTo.meta.permission)` (redirige a `error.500` — ver hallazgo en §5).
2. `helpers/menu.ts` (líneas 12, 19, 31) — filtra qué ítems se pintan en el sidebar.
3. Directo en 4 vistas (`sale/register.vue`, `sale/edit.vue`, `cash/session.vue`,
   `cash/history.vue`, `credit/client-detail.vue`) para mostrar/ocultar botones puntuales
   dentro de una pantalla (no navegación, ej. "¿puedo aprobar este gasto?").

**Nota aparte, no bloqueante:** existe un archivo
`src/stores/auth copy.ts` con una copia casi idéntica de este store (misma función
`isPermitedRoute`, línea 29) — confirmado con grep que **no se importa desde ningún lugar**
del código (`grep -r "auth copy"` no devuelve nada fuera del propio archivo). Es un archivo
huérfano, probablemente un backup manual dejado en el árbol de `src/` — no rompe nada hoy,
pero si alguien lo edita pensando que es el store real, el cambio no tendría ningún efecto.

## 5. Inconsistencias reales encontradas (menú vs. lo que el backend autoriza)

1. **`cash.history` es más permisivo que su único punto de entrada en el menú, y queda sin
   forma de llegar para una parte real de los usuarios.** El guard de la ruta
   (`router/routes.ts:295`) exige `cash.open_session|cash.view_all` — cualquiera de los dos
   alcanza. Pero el único link hacia esa ruta en todo el frontend está en
   `views/cash/dashboard.vue:11` (un botón "ver historial completo" dentro del Dashboard), y
   **el Dashboard mismo exige `cash.view_all`** (más estricto) tanto en su propia ruta
   (`meta.permission: 'cash.view_all'`, `routes.ts:307`) como en el ítem de menú que lo
   enlaza (`menu-items.ts:205`). Consecuencia real: un Cajero con solo `cash.open_session`
   (sin `cash.view_all`) está autorizado por el propio guard de rutas a ver
   `/caja/historial` — pero no tiene ningún botón ni entrada de menú que lo lleve ahí. Solo
   llega escribiendo la URL a mano. El comentario en `stores/auth.ts:41-45` (agregado en
   Módulo Caja Fase 5, soporte `|`) deja ver que la intención SÍ era que un cajero pudiera
   ver su propio historial — el guard de ruta lo permite, pero el menú/UI nunca lo expone.
2. **Guía de Remisión — ítem de menú roto** (ver hallazgo en §2): apunta a
   `dashboards.ecommerce` en vez de a una pantalla real. El permiso (`register_guia_remision`/
   `list_guia_remision`) sí existe como concepto en el menú, pero no hay ninguna ruta ni
   controller de Guía de Remisión SUNAT construido — módulo entero es un placeholder.
3. **`redirectToNoAuthorize()` (`router/index.ts:71-73`) redirige a `error.500`** cuando un
   usuario autenticado sin el permiso intenta acceder a una ruta protegida — no hay una
   página `403`/"no autorizado" real, un acceso denegado por falta de permiso se ve
   idéntico a un error real de servidor. No es un bug de seguridad (sí bloquea), pero es una
   señal de UX confusa que vale la pena que la sesión de menús dinámicos revise (`error.500`
   debería reservarse para errores reales).
4. **Ningún ítem de `menu-items.ts` usa `giro` como concepto explícito.** El grupo "Agencia
   de Viajes" no se oculta por ningún campo de tenant — se oculta solo porque, en la
   práctica, ningún tenant `retail` tiene asignado ninguno de los 6 permisos `agencia.*` a
   ningún usuario. Esto funciona hoy por convención, no por diseño explícito — confirma
   exactamente el diagnóstico del plan (§3.1, campo `giro` en `menu_items` como algo que no
   existe todavía).

## 6. §9.1 — Auditoría de rutas backend mutables sin gate de permisos

Fuera del alcance original del brief pegado, pero listado en §7 del plan completo
(`plan-modulo-menus-y-roles.md`) como parte de esta misma Fase 0. Solo inventario — **no se
corrigió ninguna ruta**, ninguna acción de código en esta sección.

**Método:** `php artisan route:list --json`, filtrado a rutas con método `POST`/`PUT`/
`PATCH`/`DELETE` bajo `api/`, cruzado contra la presencia de
`Spatie\Permission\Middleware\PermissionMiddleware` (o `RoleMiddleware`/`can:`) en el stack
de middleware ya resuelto por Laravel (incluye lo aplicado a nivel de ruta Y de grupo). Se
excluyeron `api/auth/*` (login/registro/refresh — necesariamente públicos/self-service antes
de tener un token), `api/portal/*` (portal e-commerce, su propio guard) y `api/central/*`
(usan `Authenticate:central` + `EnsureTokenIsCentralGuard`, un guard completamente distinto
al de tenant — arquitectura separada del panel superadmin, no es el mismo bug).

**Resultado — 69 rutas mutables de tenant sin ningún gate de permiso Spatie, solo
`auth:api`** (cualquier usuario autenticado del tenant, sin importar su rol, puede llamarlas
directo por API):

Confirmado leyendo `routes/api.php:246-364` (y equivalentes más abajo en el archivo): están
todas dentro de `Route::group(['middleware' => ['tenant', 'tenant.active',
'tenant.subscription', 'tenant.token', 'auth:api']], ...)` (línea 246-247) — sin
`permission:` a nivel de grupo, y cada `Route::resource(...)` individual tampoco agrega
`->middleware('permission:...')` por acción (a diferencia del Módulo Caja, que sí lo hace
ruta por ruta — ver `routes/api.php:286-332`, comentario explícito "defensa en profundidad,
no solo gateo de menú en el frontend"). Se confirmó además que ninguno de los controllers
afectados compensa esto con un `authorizeResource()`/`Gate::authorize()` en su constructor
(grep de `__construct|authorize|middleware|can(` vacío en los 4 controllers revisados:
`ProductController`, `CompanyController`, `UserController`, `RoleController`).

| Recurso | Controller | Rutas afectadas |
|---|---|---|
| `roles` | `Role\RoleController` | store, update, destroy |
| `users` | `User\UserController` | store, update ×2, destroy |
| `categories` | `Product\CategorieController` | store, update ×2, destroy |
| `products` | `Product\ProductController` | store, update ×2, destroy |
| `clients` | `Client\ClientController` | store, update, destroy |
| `company` | `Client\CompanyController` | store, update, destroy |
| `sales` | `Sale\SaleController` | store, index (por POST), update, destroy |
| `sale_details` | `Sale\SaleDetailController` | store, update, destroy |
| `sale_payments` | `Sale\SalePaymentController` | store, update, destroy |
| `enviarSunat` | `Sale\FacturacionElectronicaController` | envío de comprobante a SUNAT |
| `notas` | `Sale\NotaElectronicaController` | store, preview, enviar-sunat |
| `series-comprobante` | `Sale\SerieComprobanteController` | store, update, destroy |
| `branches` | `Cash\BranchController` | store, update, destroy |
| `cash-registers` | `Cash\CashRegisterController` | store, update, destroy |
| `payment-methods` | `Cash\PaymentMethodController` | store, update, destroy |
| `suppliers` | `Cash\SupplierController` | store, update, destroy |
| `cash-concepts` | `Cash\CashConceptController` | store, update, destroy |
| `installments` | `Credit\CreditInstallmentController` | preview, update, store (bajo `sales/{sale}/installments`) |
| `clients/{client}/payments` | `Credit\CreditPaymentController` | store, preview |
| `systems` | `Portal\Admin\SystemController` | store, update, destroy |
| `system_categories` | `Portal\Admin\SystemCategoryController` | store, update ×2, destroy |
| `recursos` | `Portal\Admin\ManualRecursoController` | store, update, destroy |

**Severidad — por qué esto importa más allá del menú:** el plan (§9.1) ya advertía
exactamente este riesgo en abstracto ("el menú y el frontend NUNCA son el límite de
seguridad real"). Esta auditoría lo confirma con evidencia concreta: hoy, cualquier usuario
autenticado de un tenant — el rol más bajo que exista, ej. un Vendedor sin ningún permiso
Spatie asignado — puede, con una llamada directa a la API (curl/Postman, sin pasar por el
frontend en absoluto):
- Crear o eliminar **roles y usuarios** del tenant (`POST/DELETE /api/roles`,
  `/api/users`).
- Eliminar **productos, clientes, ventas, comprobantes de NC/ND** ya emitidos.
- **Emitir un comprobante a SUNAT** (`POST /api/enviarSunat`) sin tener ningún permiso de
  facturación.
- Modificar **series de comprobantes, sucursales, cajas, métodos de pago** — configuración
  que hoy solo debería tocar un Administrador.

Esto es priorizable por encima del trabajo de menú dinámico en sí — un menú dinámico bien
diseñado (`menu_items` + `/me/menu`) sigue sin protegiendo nada si las rutas de abajo no
tienen su propio gate, exactamente el punto que el plan ya señalaba. No se corrigió acá
(fuera de alcance de esta fase, y el fix real — agregar `permission:` por ruta o por grupo —
depende de que primero exista el catálogo de permisos que el plan diseña en §3.2, para no
inventar nombres de permiso sueltos ruta por ruta). Queda documentado con prioridad para la
Fase 1 de ejecución.
