# Plan — Centro de Ayuda (manual operativo in-app, por giro)

> Diseño cerrado en conversación (10-sep-2026), a partir de un hallazgo real: al revisar el
> gate de permisos de Bucket A (Fase 0b) se descubrió que `manual_recursos`/`systems`/
> `system_categories` (el módulo "Recursos" del menú actual) NO es lo que parecía — es
> catálogo de marketing/onboarding (Minimarket/Botica/Hotel/Restaurante/Préstamos/Taller,
> pensado para un prospecto que todavía no compró), no ayuda operativa para un usuario ya
> logueado de un tenant. Ni siquiera incluye "Agencia de Viajes" como opción — quedó congelado
> en la era pre-vertical-split. Este documento diseña el reemplazo real: contenido de ayuda
> in-app, filtrado por `giro`, para el usuario que ya está usando el sistema.
> Estado: diseño cerrado, sin ejecutar.

---

## 1. Qué resuelve y qué NO toca

**Resuelve:** un usuario logueado de un tenant (Cajero, Vendedor, Administrador) necesita
poder consultar, dentro del propio sistema, cómo hacer las cosas que su rol le permite —
"cómo hago una factura/boleta", "cómo hago una cotización", y para `agencia_viajes`
específicamente "cómo hago una reserva", "cómo saco el reporte operativo". Contenido en
video (YouTube u otro host), PDF, o imagen.

**NO toca:**
- El catálogo de marketing (`systems`/`system_categories`/`manual_recursos` existentes) — se
  queda tal cual, con sus 3 filas reales de datos (Minimarket/Botica). Sigue sirviendo para lo
  suyo (portal/central-panel, prospecto que evalúa el producto), fuera del alcance de este plan.
- El bug ya documentado de que `ManualRecurso` sube sus archivos al disco particionado por
  tenant (CLAUDE.md, "Cómo trabajar en este proyecto") — es un problema del módulo viejo, no
  de este nuevo (que nace con el disco correcto desde el día uno, ver §4).
- El bug nuevo encontrado en Fase 0b (rutas "100% CENTRALES" autentican el JWT contra la base
  vieja `sv_facturacion`) — este plan lo EVITA (ver §3), no lo corrige en el módulo viejo.

**Decisión ya tomada, no a re-discutir:** "Recursos" (el ítem de menú actual en
`admin-start-kit`, que apunta a `manual_recursos`) **sale del menú del tenant**. Es contenido
de marketing, no operativo — no le sirve a un usuario ya logueado, y hoy ya es invisible para
todo no-Super-Admin en los 3 tenants reales (`list_recurso` nunca existió como permiso
sembrado, confirmado en Fase 0b), así que sacarlo no cambia nada para ningún usuario real. El
backend/datos del catálogo viejo quedan intactos — si algún día hace falta mostrarlo a un
prospecto, es un tema de `central-panel`/portal, aparte y sin urgencia.

## 2. Modelo de datos

```
centro_ayuda_articulos              -- central, conexión 'central' (trait CentralConnection,
                                        mismo patrón que ManualRecurso/System/SystemCategory)
- id
- giro              (string, nullable — MISMO campo/valores que tenants.giro: 'retail' |
                     'agencia_viajes' | futuros. null = visible para cualquier giro, ej.
                     "cómo hacer una factura"; con valor = exclusivo de ese giro, ej. "cómo
                     sacar el reporte operativo" solo para agencia_viajes. Sin tabla de
                     taxonomía nueva — se compara directo contra tenant('giro') al listar,
                     igual que ya se diseñó para menu_items.giro en
                     plan-modulo-menus-y-roles.md §3.1)
- categoria          (string, nullable, libre — mismo campo/criterio que manual_recursos.
                     categoria hoy: "ventas", "caja", "reservas", "reporte-operativo",
                     "configuracion". Sin normalizar a un catálogo separado en v1.)
- titulo             (string, requerido)
- descripcion        (text, nullable)
- tipo_recurso        (string — 'video' | 'documento' | 'imagen' | 'enlace')
- url                 (string, nullable — video de YouTube/otro host, o enlace externo)
- archivo             (string, nullable — path en el disco central, ver §4)
- miniatura           (string, nullable — ídem)
- orden               (integer, default 0)
- destacado           (boolean, default false)
- estado              (boolean, default true — activo/inactivo, apagar sin borrar)
- created_at / updated_at
```

Mismo shape de campos que `manual_recursos` (ya probado, sin reinventar) — la única
diferencia real es que **no tiene `sistema_id`**: se desengancha por completo del catálogo de
marketing. `giro` reemplaza esa relación.

**Consulta típica** (para el listado que ve un tenant):

```php
CentroAyudaArticulo::where('estado', true)
    ->where(fn ($q) => $q->whereNull('giro')->orWhere('giro', tenant('giro')))
    ->orderBy('orden')
    ->get();
```

## 3. Backend — rutas y permisos

**Permisos nuevos** (misma convención `{accion}_{recurso}` ya usada en Fase 0b):
`list_centro_ayuda`, `register_centro_ayuda`, `edit_centro_ayuda`, `delete_centro_ayuda`.
Ninguno se asigna a ningún rol por defecto — **solo el equipo de desarrollo autora contenido
por ahora** (decisión explícita, confirmada en conversación). Un tenant necesita que vos le
asignes `list_centro_ayuda` a mano (o a un rol) para que el ítem de menú le aparezca —
"visible siempre y cuando se le asignen los permisos", como lo pediste.

**Dónde van las rutas — a propósito, para NO repetir el bug de Fase 0b:** dentro del grupo
tenant normal (`routes/api.php:246`, `Route::group(['middleware' => ['tenant',
'tenant.active', 'tenant.subscription', 'tenant.token', 'auth:api']], ...)`) — el mismo donde
ya viven `roles`/`users`/`branches`/etc. **Nunca** en el grupo "100% CENTRALES"
(`routes/api.php:129`, sin `tenant`/`tenant.token`) donde `systems`/`system_categories`/
`recursos` autentican el JWT contra `sv_facturacion` en vez de la base del tenant real (Fase
0b, hallazgo confirmado con evidencia). El modelo sigue yendo a la conexión `central` por el
trait `CentralConnection` — eso no depende de qué middleware corrió, así que no hay conflicto
entre "ruta autenticada como tenant" y "datos en la base central".

```php
Route::resource("centro-ayuda", CentroAyudaController::class)
    ->middlewareFor('store', 'permission:register_centro_ayuda')
    ->middlewareFor('update', 'permission:edit_centro_ayuda')
    ->middlewareFor('destroy', 'permission:delete_centro_ayuda');
// index/show: permission:list_centro_ayuda (o vía meta de ruta del lado frontend, a definir
// en la sesión de implementación — mismo criterio que el resto de rutas GET del proyecto).
```

## 4. Storage — nace correcto, no repite el bug de `ManualRecurso`

`ManualRecurso` sube `archivo`/`miniatura` con `Storage::putFile()` al disco `public`
particionado por tenant — inconsistente con ser dato central (CLAUDE.md, pendiente sin
resolver). `CentroAyudaArticulo` usa un disco central dedicado desde el día uno (ej.
`config/filesystems.php` → disco `central_public`, apuntando siempre a la misma carpeta sin
pasar por `FilesystemTenancyBootstrapper`) — la sesión de implementación define el nombre
exacto y confirma que `StorageUrl::resolve()` (o una variante) arma la URL correcta para un
archivo que vive fuera de la partición de cualquier tenant.

## 5. Frontend — menú y consumo

- Ítem nuevo "Centro de Ayuda" en `admin-start-kit` — reemplaza a "Recursos" en la sección
  actual (ver §1: "Recursos" sale). Mientras el menú siga siendo estático
  (`menu-items.ts`, antes de que exista `/me/menu` — `plan-modulo-menus-y-roles.md` Fase 1/2),
  se agrega como cualquier otro ítem, gateado por `list_centro_ayuda`.
- Cuando `/me/menu` exista, este ítem se siembra como una fila más de `menu_items` con su
  propio `giro` — igual que el resto, sin caso especial (`menu_items.giro` y
  `centro_ayuda_articulos.giro` son el mismo campo/valores, pero son dos tablas
  independientes: una decide si el ITEM DE MENÚ aparece, la otra qué ARTÍCULOS ve el usuario
  una vez adentro — mismo patrón de separación que ya se usó para `menu_items` vs. scope de
  fila en cotizaciones/reservas, `plan-modulo-menus-y-roles.md` §3.3).
- Pantalla nueva `centro-ayuda/index.vue` (listado filtrado por categoría, con reproductor/
  visor según `tipo_recurso`) — sin diseño de UI definido todavía, para la sesión de
  implementación.

## 6. Fases de ejecución propuestas (para la sesión que lo construya)

1. Migración `centro_ayuda_articulos` (central) + modelo `CentroAyudaArticulo`
   (`CentralConnection`).
2. Permisos nuevos (`list_/register_/edit_/delete_centro_ayuda`) — mismo mecanismo que
   `permisos:backfill-gate-bucket-a` (Fase 0b): comando idempotente, sin asignar a ningún rol.
3. `CentroAyudaController` (CRUD) + rutas en el grupo tenant correcto (§3) — nunca en "100%
   CENTRALES".
4. Disco central dedicado para `archivo`/`miniatura` (§4).
5. Frontend: quitar "Recursos" de `menu-items.ts`, agregar "Centro de Ayuda" gateado por
   `list_centro_ayuda`; pantalla de listado/consumo.
6. Sembrar contenido real inicial (mínimo: "cómo hacer una factura/boleta" sin giro = visible
   para todos; "cómo hacer una reserva"/"cómo sacar el reporte operativo" con
   `giro='agencia_viajes'`) — a coordinar con vos, es contenido, no código.
7. Tests: permiso bloquea/deja pasar (mismo patrón `GateBucketARoutesTest`), filtro por giro
   (un artículo con giro='agencia_viajes' no aparece para un tenant retail).

## 7. Pendientes explícitos, fuera de este plan

- Bug de storage particionado de `ManualRecurso` (módulo viejo) — sigue sin resolver, no es
  parte de este plan.
- Bug de auth contra base equivocada en `systems`/`system_categories`/`recursos` (Fase 0b) —
  sigue sin resolver, este plan lo evita para el módulo nuevo pero no corrige el viejo.
- Si el catálogo de marketing (`systems`/`manual_recursos`) se muda a `central-panel`/portal —
  decisión futura, no bloqueante, no diseñada acá.
