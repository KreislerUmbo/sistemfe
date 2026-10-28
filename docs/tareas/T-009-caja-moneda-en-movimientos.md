# T-009 — Caja, paso A: la moneda en cada movimiento

| | |
|---|---|
| **Módulo** | CAJ |
| **Depende de** | ninguna (T-010 y T-013 dependen de esta) |
| **Reglas** | regla #1 de Caja: un movimiento nunca se edita ni se borra (`plan-modulo-caja.md` §5); se corrige con `CashCorrectionService` |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Caja en dos monedas", paso A |
| **Rama** | `feat/T-009-caja-moneda-movimientos` |
| **Implementa** | otro agente (Sonnet), en **2 partes con un commit cada una**. Análisis, tests de la parte 1 y revisión: Claude Opus, 28-oct-2026 |

## Contexto (verificado contra el código el 28-oct-2026)
`cash_movements` no guarda moneda: un adelanto de US$ 1,000 en efectivo entra a la caja como si
fueran S/ 1,000. Este paso solo hace que **cada movimiento guarde la moneda real del dinero**, sin
cambiar nada de lo que se ve (arqueo, cierre, reportes siguen igual). Es la base del paso B (T-013)
y del cobro en dólares (T-010/T-011).

Los **8** lugares que crean movimientos (`grep -rn "CashMovement::create(" app`):

| Archivo | Tipo | De dónde sale la moneda |
|---|---|---|
| `Sale/SaleController.php` ~423 `crearCashMovementsPorPagos()` | `sale_payment` | `$venta->currency` |
| `Advance/AdvanceController.php` ~228 | `advance_received` | `$adelanto->currency` (validado `in:PEN,USD`). También lo usan `ReservaAnticipoController` y `CommercialQuoteAnticipoController` vía `store()` |
| `Credit/CreditPaymentController.php` ~307 (cobro de Cuentas por Cobrar) | `installment_payment` | la de las ventas del recibo (`$ventasLock`); ver regla abajo |
| `Cash/CashMovementController.php` ~90 (alta manual) y ~186 (corrección de un manual) | `manual_*` | `PEN` (el formulario no pide moneda; T-013 lo cambia) |
| `Cash/CashSessionController.php` ~146 | `opening_fund` | `PEN` |
| `CashCorrectionService.php` ~54 | `correction` | **la del original** (`$original->currency`) |
| `Creditos/CajaCredito.php` ~76 | `credito_*` | `PEN` (la tabla `creditos` no tiene moneda) |

Hallado al preparar:
- `sales.currency` tiene default `'S/.'` (migración `2026_06_01_010600`). En las bases locales solo
  hay `PEN`/`USD`, pero en producción puede haber filas viejas con `'S/.'`: **normalizar**, no
  copiar tal cual (`'PEN'`, `'S/.'`, `'S/'` → `PEN`; `'USD'`, `'$'`, `'US$'` → `USD`; otro valor → no
  adivinar).
- `CreditPaymentController` no mira la moneda en ningún lado: hoy un recibo puede aplicarse a una
  venta en USD (el movimiento entraría como soles) e incluso mezclar ventas en soles y en dólares.
- Como la columna nace con default `'PEN'`, al migrar todas las filas viejas quedan en soles; el
  comando de la parte 2 solo tiene que pasar a `USD` las que correspondan.

## Regla para el cobro de crédito (decidida)
El movimiento toma la moneda de las ventas del recibo. Si el recibo aplica a ventas en **monedas
distintas** → 422 `'Un recibo no puede mezclar ventas en soles y en dólares: regístralos por separado.'`
antes de escribir nada (mismo lugar de las otras validaciones de `store()`, dentro de la
transacción, con las ventas ya bloqueadas).

## Antes de empezar (obligatorio)
1. Leer `CLAUDE.md` (raíz) y `docs/proceso.md`. Comentarios y mensajes en español.
2. `git checkout main && git checkout -b feat/T-009-caja-moneda-movimientos`
3. Tests de la parte 1 ya escritos: `tests/Unit/ReglaMonedaCajaTest.php` y
   `tests/Feature/Cash/T009MonedaCajaTest.php`. **No cambiarlos** para que pasen; si uno parece
   incorrecto, para y anótalo en "Desviaciones".
4. **No correr** `composer install`/`composer update`. No correr dos `php artisan test` completos a la vez.
5. Migración nueva: aplicarla a la base de tests con
   `DB_DATABASE=sistemafe_test_migrations php artisan migrate --path=database/migrations/tenant/core/<archivo>.php --force`
   y a los tenants locales con `php artisan tenants:migrate-verticales` (**nunca** `tenants:migrate` a secas).
6. Fechas: solo `HoraPeru`; nunca `now()`/`today()` para fechas de negocio (hay un test que falla).

## Parte 1 — la columna y los 8 lugares (commit 1)

**1. Migración** `database/migrations/tenant/core/2026_10_28_130000_add_currency_to_cash_movements_table.php`:
`currency` string(3), not null, default `'PEN'`, después de `amount`. Comentario de cabecera como
las otras migraciones de esa carpeta (qué y por qué, T-009). Agregar `'currency'` a `$fillable` de
`app/Models/Cash/CashMovement.php`.

**2. Una sola forma de normalizar**: método estático en `CashMovement`
```php
// T-009: moneda de un documento ('PEN', 'S/.', 'USD', '$'…) → 'PEN' | 'USD'; null si no se reconoce.
public static function normalizarMoneda(?string $moneda): ?string
```
Lo usan los creadores y el comando. Si un creador recibe una moneda que no se reconoce → excepción
(500 con mensaje claro), nunca `PEN` en silencio.

**3. Los 8 lugares**, según la tabla de arriba. En `CreditPaymentController::store()` aplicar la
"Regla para el cobro de crédito".

**4. Tests que agrega Sonnet** en `T009MonedaCajaTest.php` (al final; no tocar los existentes).
Para armar ventas y adelantos, copiar el armado de `tests/Feature/AdvanceIntegridadTest.php`
(`branchConSerieNv()`, `payloadVentaNv()`, `crearAdelantoDisponible()`):
- **CA1** venta NV en USD con pago en efectivo → movimiento `USD`; en PEN → `PEN`.
- **CA2** adelanto (`AdvanceController::store()`) en USD → movimiento `USD`.
- Cobro de Cuentas por Cobrar sobre una venta a crédito en USD → `USD`; un recibo con una venta en
  PEN y otra en USD → 422 y no se crea recibo ni movimiento.
- Apertura de caja (`CashSessionController`) → `PEN`.

Verificar (`php artisan test --filter="Moneda|Cash|Advance|Credit|Sale"` y la suite completa) y
hacer **commit 1**: `feat(caja): cada movimiento guarda la moneda del dinero (T-009)`.

## Parte 2 — datos ya existentes (commit 2)

**5. Servicio** `app/Services/Cash/CompletarMonedaCajaService.php` + **comando**
`app/Console/Commands/CompletarMonedaCaja.php` (`caja:completar-moneda {--simular} {--tenant=*}`).
**Copiar el patrón** de `PlantillasATarifaVigenteService` / `PlantillasATarifaVigente` (T-028): el
servicio hace el trabajo y devuelve `['cambios' => [...], 'revisar' => [...]]`; el comando recorre
**todos** los tenants no archivados (Caja es de todos los giros) con `$tenant->run()`, se salta los
que no tienen la tabla `ajustes_moneda_caja` y muestra tablas.
- Recorre `cash_movements` en orden de `id` y calcula la moneda que debería tener:
  - `reference_type='sale'` → `sales.currency` normalizada (incluye ventas con `deleted_at`).
  - `'advance'` → `advances.currency` normalizada.
  - `'payment_receipt'` → la moneda de las ventas de sus `payment_applications`; si son de monedas
    distintas → "revisar" ("recibo con ventas en monedas distintas").
  - `'cash_movement'` (correcciones) → la que quedó para el movimiento corregido
    (`corrected_movement_id`); por eso el orden por `id`.
  - `credito_*`, manuales y apertura (`reference_type` nulo) → `PEN`.
  - Documento que ya no existe o moneda que no se reconoce → "revisar", sin tocar.
- Solo escribe cuando la moneda calculada es distinta de la guardada (en la práctica, las que pasan
  a `USD`). Escribir directo con `DB::table('cash_movements')->where('id', …)->update(['currency' => …])`
  — es un completado de dato faltante, no una edición de un movimiento (la regla #1 sigue intacta:
  monto, método y dirección nunca se tocan).
- Registro: tabla `ajustes_moneda_caja` (`cash_movement_id`, `moneda_anterior`, `moneda_nueva`,
  `created_at`), migración `2026_10_28_140000_create_ajustes_moneda_caja_table.php` en `tenant/core/`
  (copiar `2026_10_28_120000_create_ajustes_plantilla_tarifa_table.php`).
- **Tests** (Sonnet), archivo nuevo `tests/Feature/Cash/T009CompletarMonedaCajaTest.php`, estilo de
  `T028PlantillasATarifaVigenteTest`: un movimiento de una venta USD que quedó en `PEN` pasa a `USD` y
  queda registrado; una corrección de ese movimiento también; uno de una venta en PEN no cambia; un
  recibo con ventas mezcladas va a "revisar"; `simular: true` no escribe; correrlo dos veces no
  cambia nada. Filtrar los resultados por los ids del test (la base de pruebas tiene otros datos).
- Correrlo en local con `--simular` (todos los tenants) y anotar en "Resultado" cuántos cambiaría
  y cuántos quedan para revisar.

Verificar y hacer **commit 2**: `feat(caja): comando para completar la moneda de los movimientos viejos (T-009)`.

## Fuera de alcance (no tocar)
- Pantallas, arqueo, cierre, totales, reportes, Excel/PDF de caja (T-013).
- Moneda del pago distinta de la de la venta, n.º de operación (T-010).
- Que el formulario de movimiento manual pida moneda (T-013).

## Criterios de aceptación
- [ ] **CA1** — Venta en USD con pago en efectivo → movimiento `USD`; en PEN → `PEN`. → test (Sonnet)
- [ ] **CA2** — Adelanto en USD → movimiento `USD`. → test (Sonnet)
- [ ] **CA3** — La corrección de un movimiento en USD queda en `USD`. → test `test_ca3_…` (Opus)
- [ ] **CA4** — `caja:completar-moneda --simular` no escribe y lista; sin `--simular` corrige y
  registra; dos veces no cambia nada. → test (Sonnet)
- [ ] **CA5** — `ReglaMonedaCajaTest` falla si aparece un `CashMovement::create` sin `currency`. → test (Opus)
- [ ] **CA6** — Recibo de crédito con ventas en soles y dólares → 422 sin escribir. → test (Sonnet)
- [ ] **CA7** — Los tests existentes de Caja, Ventas, Adelantos y Créditos siguen en verde **sin
  cambiar lo que esperan** (este paso no cambia nada visible).

## Seguridad y correctitud
- [ ] Permisos: el comando es de consola; sin rutas nuevas
- [ ] Aislamiento de tenant: el comando corre dentro de `$tenant->run()`, un tenant a la vez
- [ ] Validación de entrada: `currency` solo `PEN`/`USD` (vía `normalizarMoneda()`)
- [ ] Concurrencia: no aplica (el cobro de crédito ya bloquea las ventas)
- [ ] Dinero: no se toca ningún monto
- [ ] Fechas: el registro usa timestamps normales (UTC)
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: 2 nuevas en `tenant/core/`; `tenants:migrate-verticales`

## Cómo verificar
```
cd api-sistema-fe
php artisan tenants:migrate-verticales
php artisan test --filter="Moneda|Cash|Advance|Credit"
php artisan test        # suite completa; intermitente conocido: Fila13Fase1cFacturacionTest::descuento_del_combo… (ver backlog)
php artisan caja:completar-moneda --simular
```

## Al terminar
Llenar "Resultado" (salida de los comandos, desviaciones, hallazgos). Commits con `Co-Authored-By:` de tu sesión.
**No** unir a `main` ni pushear: lo revisa Opus.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
