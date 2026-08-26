# Guía operativa para agentes

`CLAUDE.md` contiene el contexto y las decisiones detalladas. Este archivo lo complementa:
no lo reemplaza. Antes de cambios arquitectónicos, consultar `CLAUDE.md` y
`docs/planning/INDICE.md` (y los planes pertinentes).

## Fuente de verdad y seguridad

- El código y la configuración efectiva prevalecen sobre documentación histórica. Ante una
  discrepancia, señálala antes de cambiar arquitectura.
- Revisar `git status` antes de editar. Preservar cambios locales ajenos; no asumir que son
  del agente.
- No modificar `.env`, producción, migraciones/datos/configuración existentes, ni borrar
  archivos sin autorización explícita.
- No ejecutar `git reset --hard`, `git clean`, checkout destructivo, commits ni pushes salvo
  solicitud explícita.
- Para cambios significativos: identificar archivos afectados y explicar el plan; después,
  validar, revisar el diff e informar cambios, verificaciones y pendientes.

## Monorepo

- `api-sistema-fe/`: API Laravel 12; PHP mínimo declarado `^8.2` (Laravel bloqueado en
  `composer.lock` a `12.40.2`), PostgreSQL principal.
- `admin-start-kit/`: ERP tenant, portal y vertical Agencia de Viajes; Vue 3.4.x,
  TypeScript y Vite.
- `central-panel/`: superadministración; Vue 3.5.x, TypeScript y Vite.
- No hay una versión de Node fijada en el repositorio: no asumir una concreta. Sólo fijar
  versiones cuando estén declaradas en los `package.json`/lockfiles respectivos.

## Contextos, tenancy y autenticación

- JWT es la autenticación principal. No sustituirlo por Sanctum sin decisión arquitectónica
  explícita.
- Guards separados: `api` (usuarios tenant), `client` (portal) y `central`
  (superadministración). No cruzar tokens, modelos, rutas ni consultas entre contextos.
- Stancl Tenancy identifica tenants por subdominio. La conexión central es `central`, con
  base `db_tenant_central`; cada tenant usa una base PostgreSQL independiente.
- Respetar el pipeline tenant: `tenant → tenant.active → tenant.subscription → tenant.token
  → auth:api`, junto con las validaciones actuales de `tenant_id` y claim de guard.

## Migraciones y pruebas

- Migraciones centrales: `api-sistema-fe/database/migrations/`.
- Core tenant: `api-sistema-fe/database/migrations/tenant/core/`.
- Agencia de Viajes: `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/`.
- Para migraciones de vertical en tenants existentes usar
  `php artisan tenants:migrate-verticales`; no reemplazarlo por `tenants:migrate`.
- Nunca editar o eliminar una migración ya existente para corregir producción: crear una nueva.
- Backend: PHPUnit. `phpunit.xml` usa SQLite en memoria por defecto, pero determinados
  Feature Tests requieren PostgreSQL real; no afirmar que la suite pasa sin el entorno adecuado.
- Admin: usar los scripts existentes de Vitest, type-check y build. El panel central no tiene
  una suite de tests configurada actualmente. No eliminar/desactivar tests para aprobar cambios.
- No asumir Docker, `docker compose` ni Sail: no hay configuración Docker propia; los archivos
  bajo `vendor/` no cuentan como configuración del proyecto.

## Reglas críticas heredadas

- Respetar todas las reglas de `CLAUDE.md`.
- Para archivos tenant no construir URLs con `APP_URL` ni `/storage`; usar
  `StorageUrl::resolve()` / `tenant_asset()`.
- Agencia de Viajes: la fecha de una `Reserva` se lee siempre de
  `reserva.fecha_viaje_desde/hasta` (propia, congelada al aceptar la
  alternativa) — nunca de `reserva.alternativa.cotizacion.fecha_viaje_desde/
  hasta` (esa es la propuesta comercial, editable sin guard). Ver docblock de
  `App\Models\AgenciaViajes\Reserva`.
- Antes de tocar lógica tributaria, revisar el flujo completo de IGV, exoneración y exportación.
  Evitar fallbacks silenciosos en lógica fiscal.
- Mantener la transacción atómica de `SaleController::update()`.
- Si un cambio altera una decisión arquitectónica real, identificar los documentos de
  `docs/planning/` que quedarían obsoletos. No actualizar documentación histórica salvo que la
  tarea lo solicite.
