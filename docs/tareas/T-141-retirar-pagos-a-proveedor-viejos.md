# T-141 — Retirar los pagos a proveedor viejos (`pago_proveedor`, `cronograma_pago_proveedor`)

| | |
|---|---|
| **Módulo** | AGE + COM |
| **Depende de** | ninguna |
| **Reglas** | «Compras: no construir sobre `cronograma_pago_proveedor`/`pago_proveedor`» (`CLAUDE.md`) |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7 (un solo modelo de pagos: `pagos_proveedor` + aplicaciones, revisión del 24-nov) |
| **Rama** | `refactor/T-141-retirar-pago-proveedor-viejo` |

## Contexto
Las tablas `pago_proveedor` y `cronograma_pago_proveedor` (sesiones 8b y 9b) y sus modelos
`AgenciaViajes\PagoProveedor` y `CronogramaPagoProveedor` nunca tuvieron controlador, servicio ni
pantalla. T-136 crea el `PagoProveedor` nuevo de Compras: si lo viejo sigue, choca el nombre.

## Alcance
- Borrar los dos modelos.
- Migración nueva en `tenant/verticals/agencia-viajes/` que borra las dos tablas, con salvaguarda: si
  alguna tiene filas, se detiene sin tocar nada (mismo patrón que T-083). `down()` las vuelve a crear
  vacías con el esquema original.
- Quitar las tablas de `LimpiarOperacionPrueba::TABLAS_A_BORRAR` y de `InventarioColumnasSinUso`.
- Actualizar el comentario de `TenantProvisioningService` que las nombra.

## Fuera de alcance
- Recordatorios (motor 11f): `configuracion_agencia.dias_aviso_pago_proveedor`, el tipo
  `pago_proveedor_pendiente` y `recordatorios.entidad_tipo = 'pago_proveedor'` se quedan; son la
  configuración del aviso de pago, que apuntará a las compras y cuotas nuevas cuando exista el motor.
- Migraciones existentes que las nombran en comentarios: no se editan.

## Dónde tocar
- `api-sistema-fe/app/Models/AgenciaViajes/PagoProveedor.php`, `CronogramaPagoProveedor.php` → borrar.
- `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/` → migración nueva.
- `api-sistema-fe/app/Services/AgenciaViajes/LimpiarOperacionPrueba.php` (~línea 40).
- `api-sistema-fe/app/Console/Commands/InventarioColumnasSinUso.php` (~líneas 36-38).
- `api-sistema-fe/app/Services/TenantProvisioningService.php` (~líneas 502-527, comentario).

## Criterios de aceptación
- [ ] **CA1** — En una agencia, después de migrar, no existen `pago_proveedor` ni `cronograma_pago_proveedor`.
- [ ] **CA2** — Si alguna de las dos tiene una fila, la migración lanza una excepción y no borra nada.
- [ ] **CA3** — No quedan referencias a `PagoProveedor`/`CronogramaPagoProveedor` ni a las tablas en `app/`
  (salvo los recordatorios, fuera de alcance).
- [ ] **CA4** — `LimpiarOperacionAgenciaTest` sigue pasando (ninguna tabla queda sin clasificar).

## Seguridad y correctitud
- [ ] Permisos, entrada, concurrencia, dinero, fechas, tributario, `v-html`: no aplica.
- [ ] Aislamiento de tenant: solo tenants de Agencia (migración del vertical).
- [ ] Migración nueva + `tenants:migrate-verticales`; ninguna migración existente editada.
- [ ] Producción: el conteo del 23-nov (`inventario:columnas-sin-uso`, T-083) dio 0 filas en dakamu en
  las dos tablas, y ningún código escribe en ellas. La salvaguarda cubre el resto.

## Cómo verificar
```
php artisan test --filter="RetirarPagoProveedor|LimpiarOperacion|MigrateVerticales"
php artisan tenants:migrate-verticales
```

---

## Resultado
- **Commits:** `refactor(agencia): retirar los pagos a proveedor viejos (T-141)` en la rama.
- **Qué se hizo:** modelos `PagoProveedor` y `CronogramaPagoProveedor` borrados; migración
  `verticals/agencia-viajes/2026_11_25_100000_retirar_pago_proveedor_viejo` (borra las dos tablas,
  se detiene si alguna tiene filas; `down()` las recrea vacías); las tablas salen de
  `LimpiarOperacionPrueba` e `InventarioColumnasSinUso`; comentario de `TenantProvisioningService` al día.
- **Tests:** `RetirarPagoProveedorViejoTest` (CA1 borra y `down()` recrea; CA2 con una fila no borra
  nada; CA3 sin referencias en `app/` salvo `Recordatorio`). 20 tests relacionados pasan
  (limpieza de operación, migrate-verticales, provisioning, T-132). Migración aplicada a
  `sistemafe_test_migrations` (por archivo) y a agencia-demo con `tenants:migrate-verticales`.
  Suite completa (25-nov): 2467 pasan (9816 aserciones).
- **Desviaciones de la tarea y por qué:** ninguna.

## Hallazgos fuera de alcance
- Recordatorios: `recordatorios.entidad_tipo = 'pago_proveedor'` y el tipo `pago_proveedor_pendiente`
  apuntan a algo que ya no existe. No hay backend de recordatorios todavía; cuando se construya el
  motor (11f), el aviso de pago debe apuntar a las compras o cuotas de Compras.
