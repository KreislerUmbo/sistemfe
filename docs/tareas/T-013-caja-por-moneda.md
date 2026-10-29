# T-013 — Caja, paso B: apertura, arqueo, cierre y reportes por moneda + cambio de moneda

| | |
|---|---|
| **Módulo** | CAJ |
| **Depende de** | T-009 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Caja en dos monedas", paso B y "Cambio de moneda"; respuesta 7 |
| **Rama** | `feat/T-013-caja-por-moneda` |

## Contexto
Con T-009 cada movimiento sabe su moneda, pero la caja sigue sumando todo junto. La agencia a
veces guarda los dólares aparte y a veces los cambia: la caja tiene que llevar **saldo por moneda**
y permitir registrar el cambio. **Es requisito para desplegar el cobro en dólares (T-011).**

> **Tarea grande.** Antes de codificar, quien la construya debe proponer el modelo de datos
> exacto (columnas nuevas vs. tabla por moneda) y la lista de pantallas/reportes afectados, y
> confirmarlo con el usuario. Si no cabe en un PR revisable, partirla (por ejemplo B1 datos +
> cierre, B2 reportes) y anotarlo en el backlog.

## Alcance
- Apertura por moneda (monto inicial en soles y en dólares; dólares opcional en 0).
- `ExpectedCashCalculator` por moneda (sigue contando solo formas de pago con
  `affects_cash_count`).
- `cash_session_totals` por forma de pago **y moneda** (hoy único por sesión + forma de pago).
- Cierre: contado, esperado y diferencia por moneda; conteo de billetes
  (`cash_session_denominations`) por moneda.
- Movimiento **"Cambio de moneda"**: sale una moneda y entra la otra con su tipo de cambio, dos
  movimientos enlazados; mismo permiso que los movimientos manuales.
- Movimientos manuales: elegir la moneda.
- Pantallas `cash/session.vue`, `cash/history.vue`, `cash/dashboard.vue`; PDF
  `cash_cierre_individual` y `cash_cierre_rango`; `CashMovementsExport`: separar S/ y US$.
- Sesiones ya cerradas: se siguen viendo bien (todo lo anterior queda como soles salvo lo que
  T-009 marcó en dólares; mostrar la diferencia si la hay, sin recalcular cierres pasados).

## Fuera de alcance
- Multi-caja (Fase 7 de Caja).
- Cuentas bancarias como "caja" (Compras, fase 3 de la fila 13).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/Cash/CashSessionController.php`, `CashMovementController.php`
- `api-sistema-fe/app/Services/ExpectedCashCalculator.php`, `CashVisibilityResolver.php`
- `api-sistema-fe/app/Exports/CashMovementsExport.php`
- `api-sistema-fe/resources/views/pdf/cash_cierre_*.blade.php`
- `admin-start-kit/src/views/cash/session.vue`, `history.vue`, `dashboard.vue`

## Criterios de aceptación
- [ ] **CA1** — Sesión con S/ 100 de apertura, venta efectivo S/ 500 y anticipo efectivo US$ 200
  → esperado S/ 600 y US$ 200 (hoy daría 800). → test nuevo
- [ ] **CA2** — Cierre contando S/ 600 y US$ 190 → diferencia 0 en soles y −10 en dólares. → test
- [ ] **CA3** — Cambio de moneda US$ 200 → S/ 748 a 3.74 → esperado US$ 0 y S/ 1,348. → test
- [ ] **CA4** — Una sesión cerrada antes de esta tarea se abre en el historial y su PDF sale sin
  error. → test o verificación en `umbo`/`credishirley` local
- [ ] **CA5** — Créditos (credishirley) sigue cerrando caja igual (todo en soles). → tests de Créditos en verde
- [ ] **CA6** — Prueba funcional: abrir, mover en las dos monedas, cambiar, cerrar, ver PDF y Excel.

## Seguridad y correctitud
- [ ] Permisos: "Cambio de moneda" con el permiso de movimientos manuales
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: moneda en (PEN, USD); tipo de cambio > 0
- [ ] Concurrencia: el cierre ya bloquea la sesión; mantenerlo
- [ ] Dinero: totales por moneda nunca se suman entre sí
- [ ] Fechas con `HoraPeru`: filtros por día con `inicioDiaUtc`/`finDiaUtc` (como hoy)
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: nuevas en `tenant/core/`; `tenants:migrate-verticales`

## Cómo verificar
```
php artisan test --filter=Cash
php artisan test   # suite completa, contra PostgreSQL
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
Se partió en B1 (esta rama) y B2 (T-013b), acordado con el usuario.
- **Modelo acordado:** columnas `*_usd` en `cash_sessions` (las actuales pasan a ser soles, cierres viejos intactos);
  `cash_movements.exchange_rate` + `linked_movement_id` (cambio de moneda, `type='currency_exchange'`);
  `cash_session_denominations.currency`. Migración `2026_10_29_100000_add_currency_columns_to_cash_tables`.
- **Hecho en B1:** `ExpectedCashCalculator::computePorMoneda()`; apertura con fondo en US$; cierre con contado,
  esperado y diferencia por moneda (motivo obligatorio si cualquiera pasa la tolerancia); conteo de billetes por
  moneda; `POST cash/currency-exchange` (permiso `cash.open_session`, igual que los manuales, con lock de la
  sesión); movimientos manuales con moneda y "no negativo" por moneda; `totals_by_payment_method` por método y
  moneda; `session.vue` (apertura, cierre, cambio, manuales, tabla de movimientos y totales).
- **Commits:** ver `git log feat/T-013-caja-por-moneda`.
- **Tests:** `tests/Feature/Cash/T013CajaPorMonedaTest.php` (CA1, CA2, CA3, validaciones, permiso, apertura US$).
  Suite completa: 1650 pasan, 1 falla: `Fila13Fase1cFacturacionTest` (intermitente conocido, ajeno).
  CA5: tests de Créditos en verde. `type-check` sin errores nuevos en Caja (46 previos en otros archivos, mismos
  con y sin cambios); `vite build` OK. Test DB migrada con `migrate --path` a `sistemafe_test_migrations`.
- **B2 (hecho):** `history.vue` (lista y detalle con líneas US$ solo si la sesión tuvo dólares), `dashboard.vue`
  (el cierre pide también el contado en US$; sin eso toda sesión con dólares cerraría con diferencia negativa),
  PDF individual y de rango (líneas US$, totales y billetes rotulados con la moneda), Excel con columna "Moneda".
  Tests: `T013bReportesPorMonedaTest` (CA4: sesión cerrada antes de T-013 se abre y su PDF sale; PDF con dólares;
  Excel). `--filter="Cash|Credito|T013"`: 440 pasan; `type-check` sin errores nuevos en Caja; `vite build` OK.
- **Correcciones de la revisión (29-oct):** (1) un cierre anterior a T-013 con movimientos en US$ (marcados por
  T-009) guardó un `expected_cash` que sumaba S/ y US$; ahora se marca `esperado_mezclado` y el historial y el PDF
  individual muestran una nota con el esperado por moneda como referencia (no se recalcula ni se guarda); el PDF de
  rango lo rotula "(incluye US$)" en vez de líneas US$ en 0. (2) Tipo de cambio mínimo 0.0001: uno menor redondeaba
  a 0 y el cambio S/ → US$ daba error 500. Tests: `--filter="Cash|Credito|T013"` 441 pasan.
- **Desviaciones de la tarea y por qué:**
  - **CA6 sin hacer:** la prueba funcional en pantalla real (abrir, mover en dos monedas, cambiar, cerrar, ver PDF y
    Excel) no la hice; los PDF solo se comprobaron por test (renderizan), no a la vista con Ghostscript. Falta antes
    de desplegar T-011 con dólares. La suite completa no se volvió a correr tras B2 (sí tras B1).
  - `cash_session_totals` no se tocó: ninguna parte del código la llena (tabla sin uso); no se agregó moneda.
  - Un cambio de moneda no se edita ni elimina desde movimientos manuales (corregirlo = tarea aparte).
  - Tolerancia de diferencia: el mismo número se aplica a soles y a dólares.

## Hallazgos fuera de alcance
- `cash_session_totals` / modelo `CashSessionTotal` sin uso: candidatos a retirar.
- No se puede corregir un cambio de moneda (sus dos movimientos enlazados); decidir si hace falta.
