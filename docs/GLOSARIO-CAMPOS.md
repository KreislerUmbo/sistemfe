# Glosario de campos

Documento de referencia rápida: qué hace cada campo no obvio del sistema, en qué
pantalla se ve, y si hoy tiene lógica de negocio real detrás o es solo
informativo. Complementa a `CLAUDE.md` (que es la bitácora narrativa de cada
sesión) — acá el criterio es "campo → qué hace", no cronológico, para
encontrar algo rápido cuando se te olvida qué significaba una opción.

**Convención de este documento**: cada entrada dice explícitamente si el
comportamiento fue **verificado leyendo el código real** (y en qué archivo) o
si es una interpretación/sugerencia sin lógica de negocio detrás todavía. No
se documenta nada "de memoria" sin marcarlo.

Se va a ir ampliando módulo por módulo, según vayan surgiendo dudas reales —
no es un intento de cubrir todo el sistema de una sola vez.

---

## Módulo de Caja

### `cash_registers` (Configuraciones > Cajas)

| Campo | Qué hace |
|---|---|
| `type` (`fixed` / `mobile`) | **Solo informativo — sin ninguna lógica de negocio detrás todavía** (verificado: ningún controller ni vista lee este campo para cambiar comportamiento, solo se guarda y se muestra). Sugerencia de uso: "Fija" para una caja atada a un mostrador/local, "Móvil" para POS portátil fuera del local — es una etiqueta libre, úsala como te sirva a ti. |
| `blind_close` (Cierre ciego: Hereda de la empresa / Sí / No) | Controla si el cajero ve el efectivo esperado **antes** de ingresar su conteo al cerrar el turno, o si lo ve recién **después** de confirmar (verificado en `admin-start-kit/src/views/cash/session.vue:175-180` y `CashSessionController::close()`). "Ciego" = cuenta a ciegas, sin ver el número del sistema — evita que copie el esperado en vez de contar de verdad. `null` (opción "Hereda de la empresa") = esta caja no tiene opinión propia, usa `companies.blind_close_default` (ver abajo). Solo tiene sentido forzar "Sí"/"No" en una caja puntual si quieres que se comporte distinto al resto (ej. forzar "ciego" en la caja de un cajero en capacitación). |
| `default_opening_amount` | Monto sugerido de fondo de apertura para esta caja — se precarga como valor por defecto al abrir un turno nuevo, pero el cajero puede cambiarlo al momento de abrir (`opening_amount_adjusted` en `cash_sessions` registra si lo hizo). |
| `code` | Código libre, sin formato exigido — no está validado como único a nivel de base de datos. Solo para tu propia organización interna. |
| `is_active` | Al desactivar, la caja deja de estar disponible para abrir un turno nuevo — no borra nada, sesiones/movimientos históricos que ya la usaron no se ven afectados. |

### `companies` — configuración de Caja a nivel de empresa (todo el tenant)

Estos 6 campos se agregaron en la Fase 1 del módulo (2026-07-18) y hoy **no
tienen ninguna pantalla propia en el admin** para editarlos — solo son
editables directo en base de datos o desde un endpoint que los toque
indirectamente. Verificado por grep en `app/Http/Controllers/Cash/` (2026-08-20):

| Campo | ¿Tiene lógica real hoy? | Qué hace / haría |
|---|---|---|
| `blind_close_default` | **Sí** (`CashSessionController`) | Valor que hereda cualquier caja con `blind_close = null` — ver arriba. |
| `difference_tolerance` (default 2.00) | **Sí** (`CashSessionController.php:213-221`) | Al cerrar una sesión, si la diferencia entre lo contado y lo esperado supera este monto (en soles), el sistema **exige** que el cajero escriba un motivo (`difference_reason`) antes de dejarlo cerrar — sin motivo, rechaza el cierre con 422. Por debajo de la tolerancia, cierra sin pedir nada. |
| `require_expense_approval` | **Sí** (`CashMovementController.php:66-73`) | Si está en `true`, cualquier egreso manual (`manual_expense`) que supere `max_expense_without_approval` nace con estado `pending_approval` en vez de `confirmed` — no impacta el efectivo esperado hasta que alguien con el permiso `cash.approve_expenses` lo apruebe. |
| `max_expense_without_approval` | **Sí** (mismo archivo, junto con el anterior) | El monto umbral de la regla de arriba. Si es `null`, la aprobación condicional nunca se dispara aunque `require_expense_approval` esté en `true` (los dos campos trabajan juntos, ninguno solo). |
| `require_expense_concept` | **No — columna reservada, sin ningún código que la lea todavía** (verificado, cero resultados fuera de la migración y `$fillable` del modelo). Existe en la base de datos y se puede escribir, pero no cambia ningún comportamiento hoy. |
| `allow_multiple_registers_per_branch` | **No — misma situación**, reservada para la Fase 7 del módulo (multi-caja simultánea, sin fecha de activación). |

### `payment_methods.affects_cash_count`

**Sí tiene lógica real** (verificado en `ExpectedCashCalculator`/arqueo de
cierre). Determina si un método de pago cuenta como efectivo físico al
calcular el "efectivo esperado" de una sesión. Por defecto solo EFECTIVO lo
tiene en `true` — YAPE/PLIN/TARJETA/TRANSFERENCIA están en `false`: una venta
pagada por Yape no debería sumar al conteo de billetes/monedas del cajón.

### `cash_movements.type` (histórico/reportes, no editable desde un formulario)

Valores reales usados por el sistema hoy (verificado por grep de
`'type' => '...'` en los controllers que generan movimientos): `opening_fund`
(apertura), `sale_payment` (cobro de venta), `manual_income`/`manual_expense`
(ingreso/egreso manual), `correction` (reversión de un movimiento anterior,
nunca se edita/borra el original), `advance_received` (adelanto cobrado),
`installment_payment` (cuota de crédito cobrada). El campo es texto libre a
propósito (sin `enum`/`CHECK` de Postgres) — el propio código anticipa que va
a crecer con tipos nuevos (`transfer_in`/`transfer_out`, etc.) sin necesitar
una migración de esquema cada vez.

---

*Última actualización: 2026-08-20 (módulo de Caja). Próximos módulos a
documentar aquí: a definir según vayan surgiendo dudas.*
