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

## Panel superadmin (en construcción, proyecto aparte)
Existe un plan (`plan-panel-superadmin.md`) para una UI central de gestión
de tenants (creación, `Company`, `SunatConfig`, backups, suscripciones).
El wizard de creación de tenant ahí es el lugar natural para agregar el
selector de "giro/vertical" una vez exista.

## Principio general a mantener
- Nunca fallback silencioso en lógica fiscal/tributaria — eso aplica solo
  al core de facturación, no a los verticales nuevos, pero el principio de
  "fallar explícito, nunca silencioso" es transversal a todo el backend.
- Diseñar entidades nuevas (ej. `cotizaciones` en agencia de viajes) con
  estados/flujo que puedan extenderse sin rediseño cuando el alcance del
  negocio crezca (ej. de "solo cotización" a "reserva de pasajero").
