# Arquitectura Multi-Tenant — Backend compartido (sistemafe)

> Contexto para cualquier proyecto nuevo que corra sobre el mismo backend Laravel
> (`api-sistema-fe`). Esto describe la infraestructura, no la lógica de negocio
> de facturación electrónica.

## Stack base
- Laravel 12, PostgreSQL, `stancl/tenancy` v3 (database-per-tenant)
- `php-open-source-saver/jwt-auth` con claim `tenant_id`
- Spatie Permission con namespacing de cache por tenant

## Cómo funciona el multi-tenancy
- **Un solo código Laravel, N bases de datos.** No se despliega una app por
  cliente. Cada tenant es un registro en la tabla central `tenants` y tiene
  su propia base de datos Postgres, creada automáticamente al provisionar
  (`CreateDatabase` job de `stancl/tenancy`, disparado por el evento
  `TenantCreated`).
- El usuario de Postgres que usa Laravel necesita privilegio `CREATEDB` para
  que esto funcione (verificar en producción si se usa un usuario restringido).
- Al resolver una request por subdominio/header, `stancl/tenancy` cambia la
  conexión de BD automáticamente. El resto del código no necesita saber en
  qué tenant está.
- **Base central (`db_tenant_central`):** contiene la tabla `tenants`,
  dominios, y catálogos compartidos entre todos los tenants (ej.
  `tipos_comprobante`, códigos SUNAT). Los modelos que leen de acá deben
  llevar el trait `CentralConnection` — si falta, el modelo termina
  consultando la BD del tenant por error (bug recurrente ya visto).

## Patrón core + módulos verticales
No todos los tenants tienen el mismo giro de negocio (retail con
facturación electrónica, agencia de viajes, etc.), así que las migraciones
se organizan en dos grupos:

```
database/migrations/
├── core/              ← se corre en TODOS los tenants sin excepción
│   └── ... (ventas, comprobantes, SUNAT/Greenter, caja, etc.)
└── verticals/
    ├── agencia-viajes/
    └── ... (futuros giros)
```

- Se corren con `--path`:
  `php artisan migrate --path=database/migrations/core`
  `php artisan migrate --path=database/migrations/verticals/agencia-viajes`
- La tabla `tenants` (central) tiene un campo `giro`/`vertical` que le dice
  al comando de provisioning (`tenants:provision`) qué carpetas de
  migraciones correr además de `core/`.
- **Por qué correr `core/` siempre, aunque el tenant no lo use todavía:**
  más barato provisionar de más ahora que migrar en caliente después sobre
  una BD de tenant con datos operativos reales ya en producción.

### Bug real (2026-07-30): `tenants:migrate` a secas nunca aplica `verticals/`
`config/tenancy.php` → `migration_parameters['--path']` está hardcodeado a
`database_path('migrations/tenant/core')`. Ese `migration_parameters` es lo
que usa el comando genérico `php artisan tenants:migrate` (sin `--path`
explícito) cuando se corre como mantenimiento normal, para aplicar
migraciones **nuevas** a tenants **ya provisionados** — así que ese comando
nunca corre `tenant/verticals/*`, para ningún tenant, sin importar su
`giro`. Viene pasando desde el primer vertical (agencia de viajes, Sesión
2) y se venía compensando con migración manual cada sesión (`migrate
--path=database/migrations/tenant/verticals/agencia-viajes --realpath`
contra cada tenant, a mano) sin que nadie notara que el mecanismo
automático estaba roto — confirmado real en `agencia-demo` al cerrar la
Sesión 11b4b (ver `plan-hoja-de-ruta-ejecucion.md`).

Es distinto del camino de **provisioning inicial**
(`TenantProvisioningService::provision()` → `migrarVertical()`), que sí
funciona bien porque arma un `--path` explícito por tenant según su `giro`
en el momento de crearlo — el bug es específico al camino de "agregar
migraciones nuevas a un tenant que ya existe".

**Fix**: comando nuevo `php artisan tenants:migrate-verticales`
(`app/Console/Commands/MigrateVerticalesPendientes.php`) — reemplaza a
`tenants:migrate` a secas como comando de mantenimiento de acá en
adelante. Corre `tenant/core/` para todos los tenants (paso 1, sin
cambios), agrupa los tenants centrales por `giro`, y por cada grupo con
carpeta de vertical real (`TenantProvisioningService::rutaVertical()`,
extraído de `migrarVertical()` para no duplicar el mapeo
snake_case→kebab-case) corre `tenants:migrate` con `--path` explícito
sobre ese grupo. Idempotente por diseño (la tabla `migrations` de cada
tenant ya trackea qué corrió). El comando genérico `tenants:migrate`
(de `stancl/tenancy`) sigue existiendo sin tocar — solo se deja de
depender de él para este caso de uso.

## Estado actual (referencia)
- El core de facturación electrónica (retail/POS) ya tiene ~76 migraciones
  funcionando, pero **todavía viven juntas sin separar en `core/` vs
  `verticals/`** — esa separación es trabajo pendiente, no algo ya hecho.
- Antes de crear el primer vertical nuevo (ej. agencia de viajes), el primer
  paso es:
  1. Mover las migraciones actuales a `database/migrations/core/`
     (refactor mecánico, sin tocar contenido)
  2. Agregar el campo `giro`/`vertical` a la tabla `tenants`
  3. Crear la carpeta del vertical nuevo
  4. Actualizar `tenants:provision` para que reciba el vertical y corra el
     `--path` correspondiente

## Panel superadmin (proyecto aparte, mayormente construido)
UI central de gestión de tenants (creación, `Company`, `SunatConfig`,
backups, suscripciones) — Fases 0/A/B/B.0.5/B.2/C/D/E cerradas en su
alcance actual al 20-ago-2026 (ver `CLAUDE.md` para el detalle y
`panel-superadmin/historial-archivo.md` para el diseño original, ya
archivado — `plan-panel-superadmin.md` quedó como stub corto). El
wizard de creación de tenant ya tiene el selector de "giro/vertical"
(cerrado 15/16-ago-2026).

- **Por qué el superadmin ve todos los módulos de todos los giros:** no es
  un rol con más permisos dentro de un tenant. El superadmin opera a nivel
  central, fuera del contexto de cualquier tenant específico — su menú sale
  de recorrer el catálogo maestro `modulos` (base central) sin filtrar por
  `giro` ni por `plan_id` de ningún tenant en particular, porque no está
  operando dentro de uno. Ver sección siguiente para el contraste con cómo
  se arma el menú de un usuario normal.

## Menú lateral y control de acceso (3 capas: giro + plan + roles)
Qué ve un usuario normal en el menú lateral (dentro de su tenant) no se
resuelve en un solo nivel — es la intersección de tres capas, cada una ya
decidida en otra parte de la arquitectura:

1. **`giro`/`vertical` del tenant (fija, se decide al aprovisionar).**
   Determina qué tablas/módulos EXISTEN físicamente para ese tenant (ver
   "Patrón core + módulos verticales" arriba). Un tenant con giro
   `agencia_viajes` no tiene en su base de datos las tablas de boticas,
   hoteles o ecommerce — no están ocultas, no existen. Por esto un usuario
   de ese tenant nunca puede ver esos módulos en el menú: el backend no
   tiene de dónde traerlos. Esta capa es la que explica la diferencia
   frente al superadmin (que sí recorre el catálogo completo, sin este
   filtro).
2. **Plan y control de acceso por módulo (módulo 11 del vertical agencia
   de viajes, capa ortogonal al giro — ver
   `agencia-de-viajes/plan-modulo-planes-acceso.md`).** Dentro de los
   módulos que el giro habilita que existan, el plan contratado
   (económico/estándar/pro) + `tenant_modulo_overrides` deciden cuáles
   están habilitados para usarse, de forma dinámica y sin migraciones.
   Resuelto por middleware con caché por tenant.
3. **Roles y permisos del usuario dentro del tenant (Spatie Permission,
   namespacing por tenant).** Dentro de lo que el giro permite que exista
   y el plan permite que se use, el rol asignado a cada usuario (ej.
   "Vendedor de agencia", "Administrador de agencia", "Contador") decide
   qué se le muestra a él en particular — esta es la capa que resuelve
   casos como "este usuario solo debe ver ventas y notas de crédito,
   además de agencia". Como Spatie Permission ya está namespaced por
   tenant, un rol "Vendedor" del tenant A es un registro distinto al
   "Vendedor" del tenant B; no hay cruce entre tenants.

**Cómo se arma el menú en la práctica:** no debería salir de un archivo de
configuración estático en el frontend. El backend expone un endpoint (ej.
`/me/menu`) que calcula la intersección de las tres capas — módulos que el
giro habilita ∩ módulos que el plan/overrides habilitan ∩ módulos/acciones
que los permisos del usuario autorizan — y el frontend (plantilla Rizz)
solo pinta lo que ese endpoint devuelve.

Pendiente de definir cuando se implemente: catálogo exacto de roles base
por vertical (ej. para agencia de viajes: Vendedor, Administrador de
agencia, Contador) y su mapeo a permisos Spatie por módulo/acción.

## Principio general a mantener
- Nunca fallback silencioso en lógica fiscal/tributaria — eso aplica solo
  al core de facturación, no a los verticales nuevos, pero el principio de
  "fallar explícito, nunca silencioso" es transversal a todo el backend.
- Diseñar entidades nuevas (ej. `cotizaciones` en agencia de viajes) con
  estados/flujo que puedan extenderse sin rediseño cuando el alcance del
  negocio crezca (ej. de "solo cotización" a "reserva de pasajero").
