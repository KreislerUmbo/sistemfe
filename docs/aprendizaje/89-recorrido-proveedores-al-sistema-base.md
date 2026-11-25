# Recorrido: `proveedores` pasa al sistema base

24-nov-2026, tarea **T-132**. Hasta acá la tabla `proveedores` la creaba una migración del vertical Agencia,
así que solo existía en tenants de agencia. Compras, Caja y Rendiciones necesitan **un** catálogo de
proveedores en todos los giros. Esta tarea mueve la tabla; no cambia pantallas ni reglas.

Archivos:
- `api-sistema-fe/database/migrations/tenant/core/2026_11_24_200000_create_proveedores_table_core.php` — la nueva
- `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/` — cuatro migraciones con guardas
  (`2026_07_27_170000`, `2026_07_30_110400`, `2026_08_11_090100`, `2026_11_23_100000`)
- `api-sistema-fe/tests/Feature/ProveedoresTablaBaseTest.php`

**Al desplegar:** respaldo antes; `deploy.sh` corre `tenants:migrate-verticales`. Revisar su salida.

---

## 1. Por qué no basta con «mover» el archivo

Nunca se edita ni se mueve una migración que ya corrió: cada base guarda en su tabla `migrations` el
nombre de las que aplicó. Si el archivo cambia de carpeta o de nombre, Laravel lo vería como nuevo y
volvería a intentar crear una tabla que ya existe.

Por eso hay **dos** creadores de la misma tabla, y cada uno se fija primero si ya está:

| Tenant | `core/` (nueva) | Vertical de Agencia (viejas) |
|---|---|---|
| Tienda / créditos | Crea la tabla | No corre (no es su giro) |
| Agencia nueva | Crea la tabla ya en su forma final | Cada migración ve que la tabla o la columna ya existe y no hace nada |
| Agencia existente (dakamu) | Ve la tabla y no hace nada | Ya habían corrido; solo corre T-083 si faltaba |

## 2. El orden importa

`tenants:migrate-verticales` (`app/Console/Commands/MigrateVerticalesPendientes.php`) corre primero
`tenant/core/` en **todos** los tenants y después `tenant/verticals/{giro}/`. Y al crear un tenant,
`Tenant::create()` corre `core/` y `TenantProvisioningService::migrarVertical()` el vertical.

En los dos caminos `core/` va antes. Por eso la migración nueva tiene el esquema **vigente**: con
`es_referencial`, `descripcion` y `fotos`, y sin las columnas de ubigeo que quitó T-083. Las migraciones
viejas que agregaban o quitaban esas columnas ahora preguntan `Schema::hasColumn()` antes de tocar nada.

La de T-083 (`quitar_columnas_sin_uso_tanda1`) no estaba en la lista de la tarea, pero borraba el ubigeo
sin preguntar: en una agencia nueva esas columnas ya no existen y la migración fallaba. Ahora borra solo
las que encuentra (`ubigeoPresente()`).

## 3. El `down()` que no borra

En una agencia, `proveedor_servicios`, tarifas y hoteles apuntan a `proveedores`. Si alguien revierte la
migración de `core/` ahí, borrar la tabla rompería todo el vertical. El `down()` usa
`Schema::hasTable('proveedor_servicios')` como señal de «esto es una agencia» y no hace nada; en una tienda
sí la borra.

## 4. Cómo se prueba que el esquema es el mismo

`ProveedoresTablaBaseTest` crea tenants descartables con base propia en Postgres (mismo patrón que
`MigrateVerticalesPendientesTest`) y los borra al terminar:

- **CA1:** una tienda y un tenant de créditos tienen `proveedores`, pero no `proveedor_servicios`.
- **CA2:** compara una agencia nueva con una «anterior» (se le borra la tabla y se deja que el vertical la cree
  y la altere como antes). Lee columnas, tipos, largo, nulos, valores por defecto e índices de
  `information_schema` y `pg_indexes`, y exige que sean idénticos.
- **CA3:** `tenants:migrate-verticales` dos veces seguidas: no falla y no toca los datos.
- **CA4:** el `down()` conserva la tabla en agencia y la borra en tienda.

## 5. Diagnóstico de producción (antes de construir)

Solo lectura, 24-nov-2026:

| Tenant | `proveedores` | sin documento | `suppliers` | Caja a proveedor |
|---|---|---|---|---|
| dakamu (agencia) | 9 | 3 (1 referencial) | 0 | 0 |
| credishirley (créditos) | no existía la tabla | — | 0 | 0 |
| market (retail) | no existía la tabla | — | 0 | 0 |

Para T-133 (retirar `suppliers`) no hay datos que remapear.

## 6. Lo que queda abierto

Los `down()` de las migraciones viejas de Agencia no tienen guardas: revertir el vertical en una agencia
nueva borraría esquema que ahora es de `core/`. Anotado en «Por analizar» del backlog; solo importa con un
`migrate:rollback` del vertical, que no se usa.
