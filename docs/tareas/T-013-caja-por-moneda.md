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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
