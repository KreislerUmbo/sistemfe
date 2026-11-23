# T-083 — Inventario y limpieza de columnas y tablas sin uso en las bases tenant

| | |
|---|---|
| **Módulo** | TRA (hoy solo Agencia) |
| **Depende de** | ninguna (la tanda 1 espera el conteo de producción) |
| **Reglas** | `CLAUDE.md` → Multi-tenancy (migraciones nuevas, nunca editar una existente; `tenants:migrate-verticales`); Agencia → Compras no se construye sobre `cronograma_pago_proveedor`/`pago_proveedor` |
| **Decisiones** | 23-nov-2026 con el usuario: el reembolso por reglas de cancelación **se va a construir** → se deja |
| **Rama** | `chore/T-083-inventario-columnas` (paso 1); la tanda 1 va en su propia rama |

## Contexto
La auditoría de Agencia del 09-nov encontró columnas y tablas que el código ya no lee ni escribe. Antes de
borrar hay que saber quién las nombra todavía (modelo, seeders, provisión, tests, frontend) y cuántos datos
tienen **en producción**. El paso 1 (esta rama) es el inventario y un comando de solo lectura para contar;
el paso 2 borra por tandas chicas, cada una con su migración y sus tests.

## Inventario (código revisado el 23-nov-2026)

| Elemento | Quién lo nombra | Local (agencia-demo) | Propuesta |
|---|---|---|---|
| `proveedores.pais_id/departamento_id/provincia_id/distrito_id` | solo `$fillable` de `Proveedor` | 0 | **Borrar** (tanda 1) |
| `salidas_mayorista.fecha_retorno` | `$fillable` de `SalidaMayorista`; un insert en `ReservaModificacionAplicarTest` (~237) | 0 | **Borrar** (tanda 1), quitarlo del test |
| `reserva_item_pasajero.checkin_realizado/checkin_hora` | solo comentarios (`ReservaItemPasajero`, provisión). La API calcula `checkin_realizado` desde `reserva_item_checkins` (T-041) | 0 | **Borrar** (tanda 1) si en producción la tabla nueva tiene lo de las viejas (línea de «Por analizar» de T-041) |
| `reserva.monto_reembolso`, `reserva.porcentaje_reembolso_aplicado`, tabla `reglas_cancelacion` | `$fillable`/casts de `Reserva`, modelo `ReglaCancelacion`, `ReglaCancelacionSeeder` (standalone), chequeo en `TenantProvisioningService` (~571) | 0 | **Dejar**: el reembolso se va a construir (23-nov) |
| `pago_proveedor` (+ `referencia_documento`), `cronograma_pago_proveedor` | modelos, `LimpiarOperacionPrueba`, provisión; `dias_aviso_pago_proveedor` en la configuración | 0 | **Dejar** hasta Compras, que las reemplaza (regla de `CLAUDE.md`) |
| `recordatorios`, `tipos_recordatorio`, `recordatorio_snooze_config` | modelos, `TipoRecordatorioSeeder` (standalone), provisión, `LimpiarOperacionPrueba` | 0 | **Dejar**: son del motor de pendientes (11f), que va con Compras |
| `sales.state_sale` | — | — | **Ya retirada** (`2026_08_25_100000_drop_state_sale_from_sales_table`) |
| `sv_facturacion` | — | — | **Dejar**: histórico pre-multitenant, no es base tenant |

## Alcance
**Paso 1 (hecho en esta rama):** comando de solo lectura `inventario:columnas-sin-uso` y este inventario.

**Paso 2 — tanda 1 (después del conteo de producción):** una migración nueva en
`database/migrations/tenant/verticals/agencia-viajes/` que quita las 4 columnas de ubigeo de `proveedores`,
`salidas_mayorista.fecha_retorno` y (si se cumple la condición) `reserva_item_pasajero.checkin_realizado/checkin_hora`;
sacarlas de `$fillable`/casts, del test y de los comentarios (`ReservaItemPasajero`, provisión); `down()` las vuelve a crear vacías.

## Fuera de alcance
- Reembolso por cancelación, Compras y recordatorios (se dejan, ver tabla).
- Buscar columnas sin uso fuera de la lista (otros verticales, core): tanda aparte si hace falta.

## Dónde tocar (tanda 1)
- `api-sistema-fe/app/Models/AgenciaViajes/Proveedor.php`, `SalidaMayorista.php`, `ReservaItemPasajero.php` (comentario) → `$fillable`/casts
- `api-sistema-fe/tests/Feature/AgenciaViajes/ReservaModificacionAplicarTest.php` (~237)
- `api-sistema-fe/app/Services/TenantProvisioningService.php` (~534, comentario)

## Criterios de aceptación
- [x] **CA1** — El comando cuenta filas (tabla) y datos (columna no nula, ni 0/false) por tenant y no escribe nada → `InventarioColumnasSinUsoTest`
- [ ] **CA2** — Conteo de producción anotado en «Resultado» (el usuario corre el comando)
- [ ] **CA3** — Tanda 1: migración aplicada con `tenants:migrate-verticales`, suite completa en verde, el comando muestra «—» en lo borrado

## Seguridad y correctitud
- [x] Aislamiento de tenant: el comando corre cada conteo dentro de `$tenant->run()`
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: tanda 1
- Permisos, validación, concurrencia, dinero, fechas, tributario, `v-html`: no aplica

## Cómo verificar
```
php artisan inventario:columnas-sin-uso                 # todos los tenants no archivados
php artisan inventario:columnas-sin-uso --tenant=dakamu # uno
php artisan test --filter=InventarioColumnasSinUso
```

---

## Resultado
- **Paso 1:** comando `inventario:columnas-sin-uso` + `InventarioColumnasSinUsoTest` (2 tests). Local: todo en 0.
- **Producción:** (pendiente: correr el comando después del deploy y anotar acá)

## Hallazgos fuera de alcance
-
