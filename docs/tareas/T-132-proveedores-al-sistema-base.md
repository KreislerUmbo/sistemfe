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
- **Commits:** `feat(compras): tabla proveedores al sistema base (T-132)` en la rama. Sin unir a `main` ni desplegar.
- **Qué se hizo:** migración `tenant/core/2026_11_24_200000_create_proveedores_table_core.php`
  (crea la tabla solo si no existe, con el esquema vigente: sin ubigeo, con `es_referencial`,
  `descripcion` y `fotos`; su `down()` no hace nada si existe `proveedor_servicios`) y guardas
  `hasTable`/`hasColumn` en cuatro migraciones de Agencia.
- **Otras tablas al base:** ninguna. `proveedores` no tiene claves foráneas; `proveedor_tipos` es
  central; `proveedor_tipos_config`, servicios, tarifas y hotel se quedan en Agencia.
- **Tests:** `tests/Feature/ProveedoresTablaBaseTest.php`, un test por criterio.
  - CA1: tienda y créditos recién provisionados tienen la tabla.
  - CA2: agencia nueva provisiona sin error; columnas e índices iguales a los de una agencia
    anterior (tabla creada y alterada por el vertical, con ubigeo y T-083 después).
  - CA3: `tenants:migrate-verticales` dos veces, en el test y en los 7 tenants locales
    (`agencia-demo` conserva sus 41 proveedores; la segunda corrida: «Nothing to migrate»).
  - CA4: `down()` conserva la tabla en agencia y la borra en tienda.
  - Suite completa: 2462 tests en verde (9791 aserciones) contra PostgreSQL, el 24-nov-2026.
  - El provisioning se reproduce con sus dos pasos de esquema (`Tenant::create()` +
    `migrarVertical()`), sin los seeders de roles y menú.
- **Diagnóstico de producción (24-nov-2026, solo lectura):**

  | Tenant | `proveedores` | sin documento | `suppliers` | Caja a proveedor |
  |---|---|---|---|---|
  | dakamu (agencia) | 9 | 3 (1 referencial) | 0 | 0 |
  | credishirley (créditos) | no existe la tabla | — | 0 | 0 |
  | market (retail) | no existe la tabla | — | 0 | 0 |

  dakamu: 0 documentos repetidos, 0 sin tipo. Para T-133 no hay datos que remapear.
- **Desviaciones de la tarea y por qué:**
  - Se tocó una cuarta migración que la tarea no nombraba, `2026_11_23_100000_quitar_columnas_sin_uso_tanda1`
    (T-083): consulta y borra las columnas de ubigeo, que una agencia nueva ya no tiene.
  - El diagnóstico incluyó también `market`.
- **Para el despliegue:** respaldo antes. En dakamu la migración de T-083 **todavía no está
  desplegada** (aún tiene ubigeo), así que correrá en el mismo deploy: primero `core/` (en dakamu
  no hace nada) y después T-083. Revisar la salida de `tenants:migrate-verticales`.

## Hallazgos fuera de alcance
- `ProveedorFiltroDestinoTest::test_sin_filtro_devuelve_todos` falló una vez en la primera corrida
  completa (contó 3 proveedores en vez de 2) y pasó aislado y en la segunda corrida. Cuenta todas
  las filas de la base de tests compartida; no se encontró cuál sobraba. Posible intermitente.
