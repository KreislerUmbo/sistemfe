# T-132 — Tabla `proveedores` en el sistema base (todos los giros)

| | |
|---|---|
| **Módulo** | COM |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7c (P4) y §5c C8 |
| **Rama** | `feat/T-132-proveedores-al-sistema-base` |

## Contexto
`proveedores` hoy se crea en una migración del vertical Agencia
(`database/migrations/tenant/verticals/agencia-viajes/2026_07_27_170000_create_proveedores_table.php`),
así que solo existe en tenants de agencia. Compras, Caja y Rendiciones necesitan **un** catálogo
en todos los giros. Esta tarea mueve la tabla, no cambia pantallas ni reglas.

## Alcance
- Migración nueva en `database/migrations/tenant/core/` que crea `proveedores` (y las tablas que cuelgan de ella y no son turísticas, si aplica) **solo si no existe**, con el mismo esquema que hoy.
- Proteger las migraciones viejas de Agencia para que no fallen al crear una agencia nueva (la tabla ya existirá).
- Confirmar qué otras tablas dependen de `proveedores` y deben ir también al base (`proveedor_tipos` es central y ya existe; `proveedor_servicios`/`proveedor_tarifas`/hotel **se quedan en Agencia**).
- **Antes de empezar:** diagnóstico de solo lectura en producción (dakamu y credishirley): cuántos `proveedores` y `suppliers` hay, cuántos sin documento, cuántos movimientos de Caja con `counterparty_type='proveedor'`. Resultado a la sección Resultado.

## Fuera de alcance
- Retirar `suppliers` (T-133), campos nuevos (T-134), filtro turístico/general (T-133).

## Dónde tocar
- `api-sistema-fe/database/migrations/tenant/core/` → migración nueva (`create ... if not exists`).
- `.../verticals/agencia-viajes/2026_07_27_170000_create_proveedores_table.php` y las migraciones posteriores que alteran `proveedores` (`2026_07_30_110400…`, `2026_08_11_090100…`): guardas `hasTable`/`hasColumn`.
- `api-sistema-fe/tests/` → test que provisiona un tenant de **cada giro** (patrón de `MigrateVerticalesPendientesTest`).

## Criterios de aceptación
- [ ] **CA1** — Un tenant de tienda y uno de créditos, recién provisionados, tienen la tabla `proveedores`.
- [ ] **CA2** — Un tenant de agencia nuevo se provisiona sin error y queda con el mismo esquema.
- [ ] **CA3** — `php artisan tenants:migrate-verticales` corre dos veces seguidas sin error (idempotente) sobre agencia-demo.
- [ ] **CA4** — El `down()` de la migración nueva no borra la tabla en un tenant de agencia (la usan sus servicios y tarifas).

## Seguridad y correctitud
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí; nunca editar una existente salvo guardas, y decirlo en el commit.
- [ ] Aislamiento de tenant: la tabla vive en cada base de tenant, no en central.
- [ ] Permisos, entrada, concurrencia, dinero, fechas, tributario, `v-html`: no aplica.

## Cómo verificar
```
php artisan test --filter=Proveedor
php artisan tenants:migrate-verticales
```
Respaldo de producción antes de desplegar (ya hubo un error real con migraciones de verticales; `deploy.sh` sigue aunque falle esa migración: revisar su salida).

---

## Resultado
- **Commits:**
- **Tests:**
- **Diagnóstico de producción:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
