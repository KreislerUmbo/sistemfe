# T-010 — Servicio compartido de cobro, con n.º de operación y moneda de pago

| | |
|---|---|
| **Módulo** | VEN + CAJ |
| **Depende de** | T-009 (hecha). T-011 y T-014 dependen de esta |
| **Reglas** | regla #1 de Caja (un movimiento no se edita: se corrige); R7: `sale_payments.amount` sigue en la moneda de la venta |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 1, "Cómo se guarda un pago", R5/R7 |
| **Rama** | `feat/T-010-servicio-cobro` |
| **Implementa** | otro agente (Sonnet), en **2 partes con un commit cada una**. Análisis, tests de la parte 1 y revisión: Claude Opus, 28-oct-2026 |

## Contexto (verificado contra el código el 28-oct-2026)
Los pagos de una venta y sus movimientos de caja viven en métodos privados de `SaleController`:
`validarPagosPayload()` (~295), `resolverSesionCajaAbierta()` (~384),
`crearCashMovementsPorPagos()` (~408), `prepararSincronizacionCaja()` (~451),
`movimientosCajaActivos()` (~482), `sesionParaCorregirCaja()` (~499),
`aplicarSincronizacionCaja()` (~541). `store()` guarda los pagos ~962; `update()` los borra
(soft delete) y los vuelve a crear ~1323 (R5: hoy perdería el n.º de operación).
Agencia (T-011) necesita la misma regla, más pagar en otra moneda y el vuelto.

Hoy se escribe en `sale_payments` en **4** lugares: `SaleController::store()`/`update()`,
`SalePaymentController::store()` (agregar un pago desde `sale/edit.vue`) y
`AdvanceController::store()` (~209). `sale_payments.amount` es `double`; el payload de Ventas
trae `method_payment`, `amount`, `date_payment` por pago.

## El servicio (contrato fijo: lo usan los tests escritos y T-011)
`app/Services/Cobro/CobroVentaService.php`:
```php
// Antes de la transacción. Cada pago de entrada: method_payment (code), amount | monto_pagado,
// moneda_pago? (default: la de la venta), tipo_cambio? (obligatorio si la moneda difiere),
// nro_operacion?, date_payment? (default HoraPeru::hoyTexto()).
// Devuelve cada pago con: method_payment, moneda_pago, monto_pagado, tipo_cambio,
// amount (equivalente en la moneda de la venta, 2 decimales), nro_operacion, date_payment.
// 422 si: método inexistente/inactivo, método no permitido en esa moneda, monto <= 0,
// moneda fuera de PEN/USD, moneda distinta sin tipo de cambio, tipo de cambio fuera de (0, 20].
public function prepararPagos(array $pagos, string $monedaVenta): array

// Compara lo cobrado (suma de amount, en centavos) con el saldo.
// exacto=false (Ventas retail): puede faltar (pago parcial); pasarse → 422 (mensaje de hoy:
//   "La suma de pagos (S/ …) supera el total a pagar de la venta (S/ …).").
// exacto=true (Agencia, contado): lo cobrado tiene que cubrir el saldo; el exceso solo se acepta
//   como vuelto si hay algún pago en efectivo (payment_methods.affects_cash_count) y no supera
//   lo pagado en efectivo (en equivalente). Falta → 422.
// Devuelve null o ['monto' => float, 'moneda' => 'PEN'|'USD']. Vuelto en la moneda de la venta
// por defecto; en otra moneda ($monedaVuelto), se convierte con el tipo de cambio del pago en
// efectivo en esa moneda (si no hay ninguno → 422).
public function validarContraSaldo(array $pagosPreparados, float $saldo, string $monedaVenta, bool $exacto, ?string $monedaVuelto = null): ?array

// Sesión de caja abierta del usuario; si no hay → 422 "Abre tu caja para cobrar."
public function sesionAbierta(User $usuario): CashSession

// Dentro de la transacción: un sale_payments por pago (con las columnas nuevas) y un
// cash_movement 'sale_payment' por pago EN LA MONEDA Y MONTO DEL PAGO (currency = moneda_pago,
// amount = monto_pagado). Con vuelto: guarda sales.vuelto_monto/vuelto_moneda y un cash_movement
// 'sale_change', direction 'out', método EFECTIVO, en la moneda del vuelto.
public function registrar(Sale $venta, array $pagosPreparados, CashSession $sesion, User $usuario, ?array $vuelto = null): void
```
**Tipo de cambio = soles por dólar, siempre.** Venta en soles pagada en dólares: equivalente =
monto × tc; venta en dólares pagada en soles: monto ÷ tc. Un solo punto de conversión y redondeo
(este servicio), comparando en centavos.

## Antes de empezar (obligatorio)
1. Leer `CLAUDE.md` (raíz) y `docs/proceso.md`. Comentarios y mensajes en español.
2. `git checkout main && git checkout -b feat/T-010-servicio-cobro`
3. Tests de la parte 1 ya escritos: `tests/Feature/Cobro/CobroVentaServiceTest.php`. **No
   cambiarlos** para que pasen; si uno parece incorrecto, para y anótalo en "Desviaciones".
4. **No correr** `composer install`/`composer update`. No correr dos `php artisan test` completos a la vez.
5. Migraciones nuevas en `tenant/core/`: aplicarlas a la base de tests con
   `DB_DATABASE=sistemafe_test_migrations php artisan migrate --path=database/migrations/tenant/core/<archivo>.php --force`
   y a los tenants locales con `php artisan tenants:migrate-verticales` (**nunca** `tenants:migrate` a secas).
6. Fechas: solo `HoraPeru`; nunca `now()`/`today()` para fechas de negocio (hay un test que falla).

## Parte 1 — backend (commit 1)

**1. Migraciones** (`tenant/core/`, fecha `2026_10_29_…`, comentario de cabecera como las demás):
- `sale_payments`: `nro_operacion` string(50) nullable, `moneda_pago` string(3), `monto_pagado`
  decimal(12,2), `tipo_cambio` decimal(10,4). Completar los ya guardados **en la misma migración**
  (dato del mismo tenant, sin catálogo externo): `moneda_pago` = moneda de la venta normalizada
  (`'USD'`, `'$'`, `'US$'` → `USD`; lo demás → `PEN`), `monto_pagado` = `amount`, `tipo_cambio` = 1.
  Después de completar, `moneda_pago`/`monto_pagado`/`tipo_cambio` quedan not null.
- `payment_methods.monedas` json, default `["PEN","USD"]`; `YAPE` y `PLIN` (por `code`) → `["PEN"]`.
- `sales.vuelto_monto` decimal(12,2) nullable, `sales.vuelto_moneda` string(3) nullable.
- Modelos: `$fillable`/`$casts` en `SalePayment` (también quitar `user_id` de `$fillable`: la
  columna no existe), `PaymentMethod` (`monedas` → `array`), `Sale`.

**2. `CobroVentaService`** con el contrato de arriba.

**3. `SaleController` usa el servicio, sin cambiar su comportamiento:**
- `store()`/`update()`: `prepararPagos()` + `validarContraSaldo(…, exacto: false)` reemplazan a
  `validarPagosPayload()` (saldo = total − adelantos aplicados, como hoy); `sesionAbierta()`
  reemplaza a `resolverSesionCajaAbierta()` cuando hay pagos; `registrar()` reemplaza al alta de
  `SalePayment` + `crearCashMovementsPorPagos()`. Las validaciones siguen **antes** de
  `DB::beginTransaction()` (ver el comentario de `prepararSincronizacionCaja()` sobre el
  `catch(\Throwable)`). Borrar los métodos privados que queden sin uso.
- `update()` conserva `nro_operacion` (viene en el payload de cada pago, R5).
- `prepararSincronizacionCaja()`: la firma compara `método|moneda|monto_pagado` del pago con
  `método|currency|amount` del movimiento (hoy compara `amount`, que deja de coincidir cuando la
  moneda difiere).
- `movimientosCajaActivos()`: incluir también `sale_change` para que eliminar una venta (T-008)
  revierta el vuelto. La firma de `update()` sigue mirando solo los `sale_payment` (Ventas no
  tiene vuelto).
- El mensaje sin caja pasa a ser el del servicio ("Abre tu caja para cobrar."): ningún test
  depende del texto viejo (verificado).

**4. Los otros dos lugares que escriben `sale_payments`** llenan las columnas nuevas (moneda de
la venta, `monto_pagado` = `amount`, `tipo_cambio` = 1): `SalePaymentController::store()`
(acepta además `nro_operacion`, max 50) y `AdvanceController::store()` (su `payment_reference`
ya existente va también a `nro_operacion`).

**5. `PaymentMethodController`** (`store`/`update`): acepta `monedas` (arreglo no vacío, valores en
`PEN`/`USD`, sin repetir). Sin permisos nuevos.

**6. Tests que agrega Sonnet** (archivo nuevo `tests/Feature/Cobro/T010VentasCobroTest.php`,
armado de venta copiado de `T009MonedaCajaTest::payloadVentaNvConPago()`):
- **CA1**: venta con pago Yape y `nro_operacion='123456'` lo guarda; `update()` con los mismos
  pagos lo conserva y **no** crea movimientos de caja nuevos (firma igual).
- **CA2**: la migración completa los pagos viejos (insertar un `sale_payments` sin las columnas
  nuevas antes no es posible después de migrar: probar con una venta en USD creada vía factory +
  `DB::table('sale_payments')->insert` con solo las columnas viejas y luego correr el bloque de
  completado — o verificarlo en `umbo` local y anotarlo en "Resultado").
- **CA8**: `payment_methods`: `YAPE`/`PLIN` quedan en `["PEN"]`; `update` con `monedas` vacío → 422.
- Eliminar una venta con vuelto (factory + `registrar()` con vuelto + `destroy()`) corrige también
  el movimiento `sale_change`.

Verificar (`php artisan test --filter="Cobro|Sale|Cash|Advance"` y la suite completa) y hacer
**commit 1**: `feat(ventas): servicio compartido de cobro con n.º de operación y moneda de pago (T-010)`.

## Parte 2 — pantallas (commit 2)
- `sale/register.vue` y `sale/edit.vue`: campo **N.º de operación** (opcional, max 50) junto a
  método/monto/fecha al agregar un pago; columna en la tabla de pagos; se envía en `payments[]`.
  En `edit.vue`, el alta inmediata (`httpClient.post('sale_payments', …)`) también lo envía, y al
  guardar la venta cada pago reenvía su `nro_operacion`.
- `cash/payment-methods.vue`: en el formulario, casillas **Soles** / **Dólares** (al menos una) y
  la columna "Monedas" en la tabla.
- Estilo: el de Ventas (inputs `-sm`, cards con `card-header`), ver `feedback_estilo_ui_como_agencia_y_ventas`.
- `npm run type-check` (46 errores preexistentes; ninguno nuevo) y `npm run build`.
- Prueba funcional en `umbo` local (o `sandbox`): registrar una venta con Yape + n.º de operación,
  editarla sin tocar pagos (se conserva y la caja no cambia), agregar un pago con n.º de operación
  desde editar; en Formas de pago, Yape solo en soles.

Verificar y hacer **commit 2**: `feat(ventas): n.º de operación en los pagos y monedas por forma de pago (T-010)`.

## Fuera de alcance (no tocar)
- Moneda de pago distinta y vuelto **en la pantalla de Ventas** (el servicio lo soporta; la UI de
  retail queda para después).
- Agencia (T-011), PDF (T-014), caja por moneda (T-013).
- `SalePaymentController` (agregar/quitar un pago desde editar) no toca la caja: el movimiento
  recién se crea al guardar la venta. Ya pasaba antes; está en "Por analizar" del backlog.

## Criterios de aceptación
- [ ] **CA1** — Venta con Yape y `nro_operacion='123456'` lo guarda; editar sin tocar pagos lo conserva y no mueve la caja. → test (Sonnet)
- [ ] **CA2** — Pagos ya existentes quedan con `moneda_pago`/`monto_pagado`/`tipo_cambio` coherentes. → test o verificación local (Sonnet)
- [ ] **CA3** — S/ 3,750 pagado con US$ 1,000 a 3.75 → `amount=3750`, `monto_pagado=1000`, `moneda_pago='USD'`; caja US$ 1,000 en `USD`. → tests `test_ca3_…` (Opus)
- [ ] **CA4** — Yape en USD → 422. → test `test_ca4_…` (Opus)
- [ ] **CA5** — Pago de más en efectivo → vuelto en la venta + salida de caja; de más solo con Yape → 422; vuelto mayor que el efectivo → 422. → tests `test_ca5_…` (Opus)
- [ ] **CA6** — Sin caja abierta → 422 "Abre tu caja para cobrar.". → test `test_ca6_…` (Opus)
- [ ] **CA7** — Los tests existentes de Ventas, Caja y Adelantos siguen en verde sin cambiar lo que esperan.
- [ ] **CA8** — Yape/Plin nacen solo en soles; editable; `monedas` vacío → 422. → test (Sonnet) + pantalla

## Seguridad y correctitud
- [ ] Permisos: formas de pago usa la ruta y permiso que ya tiene; sin rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: `nro_operacion` ≤ 50; `moneda_pago` en (PEN, USD); tipo de cambio en (0, 20]
- [ ] Concurrencia: no aplica (venta nueva, o ya bloqueada en `update()`)
- [ ] Dinero: un solo punto de conversión y redondeo (el servicio); comparar en centavos
- [ ] Fechas con `HoraPeru`: `date_payment` por defecto `HoraPeru::hoyTexto()`
- [ ] Tributario: no aplica (la moneda del comprobante no cambia)
- [ ] `v-html`: no aplica
- [ ] Migraciones: 3 nuevas en `tenant/core/`; `tenants:migrate-verticales`

## Cómo verificar
```
cd api-sistema-fe
php artisan tenants:migrate-verticales
php artisan test --filter="Cobro|Sale|Cash|Advance"
php artisan test        # suite completa; intermitente conocido: Fila13Fase1cFacturacionTest::descuento_del_combo… (ver backlog)
cd ../admin-start-kit && npm run type-check && npm run build
```

## Al terminar
Llenar "Resultado" (salida de los comandos, desviaciones, hallazgos). Commits con `Co-Authored-By:` de tu sesión.
**No** unir a `main` ni pushear: lo revisa Opus.

---

## Resultado
- **Commits:** `b2c9017` (parte 1, backend), `80a08cf` (parte 2, pantallas), en `feat/T-010-servicio-cobro`. Sin unir ni pushear.
- **Tests:** suite completa backend **1621 en verde** (incluye `CobroVentaServiceTest` 15/15 sin tocar y `T010VentasCobroTest`
  4/4: CA1, pago de más sigue rechazado, CA8, vuelto revertido al eliminar). Frontend: `npm run build` OK; `type-check`
  46 errores, los mismos 46 de antes (verificado con `git stash`), ninguno nuevo.
  CA2 verificado en `umbo` local: 19 pagos, todos `moneda_pago=PEN`, `monto_pagado=amount`, `tipo_cambio=1`; `payment_methods`
  con Yape/Plin en `["PEN"]` y el resto en `["PEN","USD"]`. Local no tiene ventas en USD, así que la rama USD de la
  migración (`'USD'`, `'$'`, `'US$'`) solo está revisada por lectura.
- **Prueba funcional en pantalla: NO hecha.** El dev server está arriba pero no tengo credenciales de `umbo`/`sandbox` y la
  memoria indica no resetear contraseñas. Pendiente probar a mano: venta con Yape + n.º de operación, editarla sin tocar
  pagos, agregar un pago con n.º desde editar, y Yape solo en soles en Formas de pago.
- **Desviaciones de la tarea y por qué:**
  - `SaleResource` (sale_payments) ahora devuelve `nro_operacion`, `moneda_pago`, `monto_pagado`, `tipo_cambio`, y
    `SalePaymentController::store()` devuelve `nro_operacion`. Sin eso `edit.vue` no podía reenviar el n.º de operación al
    guardar (R5). Es backend en la parte 2.
  - El servicio expone además `guardarPagos()` y `crearMovimientos()` (públicos): `update()` de Ventas debe rehacer las filas de
    `sale_payments` (para conservar el n.º) aunque la caja no cambie; `registrar()` los usa por dentro.
  - Los pagos preparados traen dos claves extra (`payment_method_id`, `afecta_efectivo`) además de las del contrato.
  - Mensaje de "pagarse de más": usa `S/` o `US$` según la moneda de la venta (antes siempre `S/`; igual para soles).
  - `AdvanceController`: `payment_reference` va a `nro_operacion` cortado a 50 caracteres (el campo original admite 255).
- **Ojo para la revisión:** la firma de `update()` ahora incluye la moneda. Un movimiento viejo de una venta en USD que
  quedó como `PEN` antes de T-009 no coincide con el nuevo `USD` → al editar esa venta se generaría una corrección. Correr
  `caja:completar-moneda` en producción antes de desplegar esto evita ese caso.

- **Revisión (Opus, 29-oct-2026):** sin cambios de fondo. Dos ajustes:
  - `PaymentMethodSeeder`: en un tenant nuevo la migración corre antes de que existan Yape/Plin y
    el seeder los creaba con el default (soles y dólares). Ahora los crea solo en soles; re-correrlo
    no pisa lo que el tenant edite. Test nuevo en `T010VentasCobroTest`.
  - `SalePaymentController::store()`: `monedaOFalla()` en vez de `?? 'PEN'` (sin caer en soles en silencio).
  - Pantallas revisadas en agencia-demo: Formas de pago (columna y casillas; Yape/Plin solo soles) y
    el campo "N.º de operación" en Registrar venta. No se registró una venta real.
  - Suite completa 1621 en verde + el intermitente conocido (`Fila13Fase1c…`, línea 304).
  - **Antes de desplegar:** correr `caja:completar-moneda` (T-009) en producción, porque la firma de
    `update()` ahora compara la moneda (ver la nota de Sonnet arriba).
  - Comportamiento nuevo a tener en cuenta: una venta en USD ya no acepta un pago con Yape/Plin
    (422 "no acepta pagos en dólares"); y si al editar se cambia la moneda de la venta con pagos ya
    cargados, esos pagos quedan en la moneda vieja y piden tipo de cambio.

## Hallazgos fuera de alcance
- Dos errores de tipado de `type-check` ya conocidos siguen (46); no se tocaron.
- `SalePaymentController::destroy()` no corrige la caja al borrar un pago (ya estaba en "Por analizar").
