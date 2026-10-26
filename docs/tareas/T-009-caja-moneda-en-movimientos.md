# T-009 — Caja, paso A: la moneda en cada movimiento

| | |
|---|---|
| **Módulo** | CAJ |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Caja en dos monedas", paso A |
| **Rama** | `feat/T-009-caja-moneda-movimientos` |

## Contexto
`cash_movements` no guarda moneda: un anticipo de US$ 1,000 en efectivo entra a la caja como si
fueran S/ 1,000. Este paso solo hace que **cada movimiento guarde la moneda real del dinero**, sin
cambiar nada de lo que se ve. Es la base del paso B (T-013) y del cobro en dólares (T-010/T-011).

## Alcance
- Migración tenant `core/`: `cash_movements.currency` string(3), not null, default `'PEN'`.
- Completar los movimientos ya guardados, **en un comando aparte** (`caja:completar-moneda
  --simular`), no en la migración: por cada movimiento con `reference_type` de un documento con
  moneda (venta → `sales.currency`, anticipo → `advances.currency`), copiar esa moneda. Créditos,
  Cuentas por Cobrar, manuales, apertura: `PEN` (verificar en el código que no haya moneda que
  copiar antes de asumirlo). Correcciones: la del movimiento corregido. Registro de lo cambiado en
  una tabla (`ajustes_moneda_caja`: movimiento, valor anterior, valor nuevo, fecha), reversible.
- Llenar `currency` en **todos** los lugares que crean movimientos:
  `SaleController`, `AdvanceController`, `CreditPaymentController`, `CashMovementController`,
  `CashSessionController`, `CashCorrectionService` (copia la del original),
  `Creditos\CajaCredito`. Buscar con `grep -rn "CashMovement::create"` y confirmar que no queda
  ninguno sin moneda.
- Agregar un test de regla (estilo `tests/Unit/ReglaFechasNegocioTest.php`) que falle si aparece
  un `CashMovement::create` sin `currency`.

## Fuera de alcance
- Cualquier cambio en pantallas, arqueo, cierre, totales o reportes (T-013).
- Moneda del pago distinta de la de la venta (T-010).

## Archivos probables
- `api-sistema-fe/database/migrations/tenant/core/2026_10_xx_add_currency_to_cash_movements_table.php`
- `api-sistema-fe/app/Console/Commands/CompletarMonedaCaja.php`
- Los 7 archivos de arriba + `app/Models/Cash/CashMovement.php` (`$fillable`)

## Criterios de aceptación
- [ ] **CA1** — Una venta en USD con pago en efectivo crea su movimiento con `currency='USD'`;
  una en PEN, con `'PEN'`. → test nuevo
- [ ] **CA2** — Un anticipo en USD crea su movimiento con `'USD'`. → test nuevo
- [ ] **CA3** — La corrección de un movimiento en USD también queda en `'USD'`. → test nuevo
- [ ] **CA4** — `caja:completar-moneda --simular` no escribe nada y lista lo que cambiaría; sin
  `--simular` corrige y registra; correrlo dos veces no cambia nada la segunda. → test nuevo
- [ ] **CA5** — El test de regla falla si se agrega un `CashMovement::create` sin `currency`.
- [ ] **CA6** — Los tests existentes de Caja, Ventas, Adelantos y Créditos siguen en verde sin
  cambiar lo que esperan (este paso no cambia comportamiento visible).

## Seguridad y correctitud
- [ ] Permisos: el comando es de consola; sin rutas nuevas
- [ ] Aislamiento de tenant: el comando recorre los tenants con `tenancy()->runForMultiple` (o el
  patrón de `fechas:migrar-utc`), nunca mezcla datos entre tenants
- [ ] Validación de entrada: `currency` solo `PEN`/`USD`
- [ ] Concurrencia: no aplica
- [ ] Dinero: no se toca ningún monto
- [ ] Fechas con `HoraPeru`: el registro usa timestamps normales (UTC)
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: nueva, en `tenant/core/`; `tenants:migrate-verticales`

## Cómo verificar
```
php artisan tenants:migrate-verticales
php artisan caja:completar-moneda --simular
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
