# Recorrido: pagos a proveedores con un solo modelo

27-nov-2026, tarea **T-136** (todos los giros). Hasta ahora una compra decía si era al contado o
a crédito, pero no si se había pagado. Ahora el dinero que sale a un proveedor queda registrado
**una vez** (`pagos_proveedor`), y aparte se anota **cuánto de ese pago cancela qué compra**
(`aplicaciones_pago_proveedor`). Lo que un pago no aplica queda «a cuenta» del proveedor. El mismo
modelo servirá después para la detracción y la retención (T-163), las cuentas por pagar (T-137),
los dólares (T-140) y los mayoristas (T-143).

Lo que se aprende acá:
- **separar el dinero de su destino**: un pago, muchas aplicaciones;
- **un estado que no se guarda**: el saldo y «pendiente / parcial / pagado / vencido» se calculan;
- **reutilizar las reglas de Caja sin copiarlas**: `EgresoCajaService`;
- **la aprobación de egresos** y por qué un pago por aprobar «reserva» saldo;
- **anular sin borrar**: corrección en Caja, aplicaciones marcadas;
- **el orden de bloqueo** para que dos pagos no cancelen dos veces el mismo saldo.

Archivos:
- Migraciones (`database/migrations/tenant/core/`): `2026_12_01_100000_create_cuentas_bancarias_table_core`,
  `…100100_add_moneda_to_cuentas_bancarias`, `…100200_add_pagos_proveedor_permissions`,
  `…100300_create_pagos_proveedor_tables`
- Modelos: `app/Models/Compras/PagoProveedor.php`, `AplicacionPagoProveedor.php`,
  `app/Models/CuentaBancaria.php` (antes en `AgenciaViajes\`)
- `app/Services/Compras/PagoProveedorService.php`: `saldos()`, `estados()`, `registrar()`,
  `aplicar()`, `desaplicar()`, `anular()`, `aprobado()` / `rechazado()`
- `app/Services/Cash/EgresoCajaService.php` (extraído de `CashMovementController`)
- `app/Services/Compras/CompraService.php`: `registrar()` / `pagarAhora()`, `anular()`,
  `exigirQueRespeteSusPagos()`
- Controladores: `Compras/PagoProveedorController.php`, `CuentaBancariaController.php`,
  `Cash/CashMovementController.php` (`approve()`, `reject()`)
- Frontend: `src/views/compras/pagos.vue`, `form.vue`, `index.vue`;
  `src/components/Compras/RegistrarPago.vue`, `AplicarPagoACuenta.vue`, `CamposOrigenPago.vue`;
  `src/components/CuentasBancarias.vue`; `src/helpers/pagosProveedor.ts`, `pedirMotivo.ts`
- Tests: `tests/Feature/Compras/PagoProveedorTest.php`, `CuentasBancariasCoreTest.php`

**Al desplegar:** `tenants:migrate-verticales`, `MenuItemsSeeder` y `roles:sync-propietario` (los
corre `deploy.sh`; revisar su salida). Las cuentas bancarias existentes quedan **sin moneda**: la
primera vez que se paguen con ellas, la pantalla pide completarla.

---

## 1. Pantalla → tabla

| Qué pasa | Ruta | Permiso | Tablas |
|---|---|---|---|
| Registrar una compra al contado con «Pagada ahora» | `POST compras` (con `pago[...]`) | `compras.registrar` + `compras.pagar` | `compras` + `pagos_proveedor` + `aplicaciones_pago_proveedor` (+ `cash_movements` si sale de Caja) |
| «Pagos a proveedores» → Nuevo pago | `POST compras/pagos` | `compras.pagar` | `pagos_proveedor`, sus aplicaciones y, desde Caja, un `cash_movements` tipo `pago_proveedor` |
| Elegir proveedor en el nuevo pago | `GET proveedores/{id}/compras-pendientes` | `compras.pagar` | lee `compras` y calcula `saldos()` |
| Ficha → «Aplicar pago a cuenta» | `POST compras/pagos/{id}/aplicaciones` | `compras.pagar` | una fila más en `aplicaciones_pago_proveedor` |
| Ficha → «Desaplicar» | `POST compras/aplicaciones/{id}/desaplicar` | `compras.pagar` | marca `desaplicada_at` (no borra) |
| Anular un pago | `POST compras/pagos/{id}/anular` | `compras.anular` | `pagos_proveedor.estado = anulado`, aplicaciones marcadas y, desde Caja, un `cash_movements` tipo `correction` |
| Aprobar / rechazar el egreso en Caja | `POST cash/movements/{id}/approve` / `reject` | `cash.approve_expenses` | el movimiento y, con él, el pago |
| Pestaña «Cuentas bancarias» | `cuentas-bancarias` | `cuentas_bancarias.gestionar` | `cuentas_bancarias` |
| Listado de compras, columna Pago | `GET compras` | `compras.ver` | calcula `saldo` y `estado_pago` en lote |

## 2. Dos tablas: el dinero y su destino

`pagos_proveedor` es el dinero que salió: proveedor, fecha, moneda, monto y **de dónde salió**
(`origen`):
- **Caja**: apunta a su `cash_movement_id` y al método de pago;
- **Banco**: apunta a la `cuenta_bancaria_id` y lleva el `numero_operacion`.

`aplicaciones_pago_proveedor` dice cuánto de ese pago cancela cada compra. Un pago de 1,500 puede
tener una aplicación de 1,000 a una factura y dejar 500 «a cuenta» para aplicarlos después.

Tres cosas de la migración para mirar:
- **`origen` no tiene CHECK de lista.** Se valida en PHP contra `PagoProveedor::ORIGENES`. Así,
  T-163 agrega `detraccion` y `retencion_4ta` sin otra migración.
- **Sí hay CHECK de coherencia:** un pago de Caja sin movimiento, o uno de Banco sin cuenta ni
  número de operación, no entra ni siquiera por error de código.
- **Índice único parcial** `(cuenta_bancaria_id, numero_operacion) where estado <> 'anulado'`: la
  misma operación de la misma cuenta no se registra dos veces, pero una anulada se puede volver a
  registrar bien. Antes de comparar, el número se pasa a mayúsculas y sin espacios: «op 0001» y
  «OP0001» son la misma operación (409 con los datos del pago existente).

`aplicaciones_pago_proveedor.compra_id` es **nullable** con `CHECK (num_nonnulls(compra_id) = 1)`:
T-143 agregará `obligacion_id` y `cuota_id` y cambiará ese CHECK para que haya exactamente un
destino.

> **Ejercicio:** ¿por qué no se pone una columna `pagado` o `saldo` en `compras`? (Pista: ¿qué
> pasaría al anular un pago o registrar una nota de crédito si alguien olvida actualizarla?)

## 3. El saldo se calcula, en un solo lugar

`PagoProveedorService::saldos(array $compraIds)` es **el único cálculo de saldo**. Lo usan el
listado, la ficha, «compras pendientes» y las validaciones al aplicar:

```
saldo = total − notas de crédito vigentes que la modifican − aplicado de pagos vigentes
```

Hace tres consultas para **todas** las compras pedidas (sin N+1) y suma con `BigDecimal`, nunca
con `float`. Devuelve también:
- `por_aprobar`: lo aplicado desde pagos cuyo egreso espera aprobación en Caja;
- `limite = saldo − por_aprobar`: lo que **todavía se puede aplicar**.

`estados()` traduce eso al badge:
- saldo ≤ 0 → **pagado** (si es negativo, una NC dejó saldo a favor: el reembolso es T-140);
- vencimiento anterior a `HoraPeru::hoyTexto()` → **vencido** (vencimiento = `fecha_vencimiento`, o
  la emisión si es al contado);
- algo aplicado → **parcial**; si no → **pendiente**.

Las anuladas y las notas de crédito no tienen estado de pago (`sePaga()`).

> **Ejercicio:** en `PagoProveedorTest`, `test_vencido_segun_la_fecha_de_peru` congela la hora.
> ¿Qué fallaría a las 20:00 de Lima si `estados()` usara `today()`? (Ver «Fechas y horas» en
> `CLAUDE.md`.)

## 4. Aplicar: las reglas y el bloqueo

`crearAplicaciones()` rechaza (422) si la compra:
- es de otro proveedor;
- está anulada o es una nota de crédito;
- está en otra moneda (pagar en soles una compra en dólares es T-140: nunca se convierte por
  detrás);
- recibiría más que su `limite`;

y también si el pago no tiene ese monto sin aplicar.

Para que dos pagos simultáneos no cancelen dos veces el mismo saldo, **siempre** se bloquea primero
el pago y después las compras, **en orden de id** (`bloquearCompras()`), y recién entonces se
calcula el saldo. Con el mismo orden en todas partes, dos transacciones no se esperan en círculo
(deadlock).

Una compra de un periodo cerrado **sí** recibe pagos: el pago no cambia el comprobante ni el
Registro de Compras.

## 5. Caja: las mismas reglas que un egreso manual

Antes, las reglas de un egreso vivían en métodos privados de `CashMovementController`. Se
extrajeron, sin cambiar su comportamiento, a `EgresoCajaService`:
- `sesionAbierta()`: la sesión abierta **de quien paga** (422 si no hay);
- `metodoPago()`: método activo;
- `estadoInicial()`: `pending_approval` si la empresa exige aprobación y el monto pasa el umbral;
  si no, valida que el efectivo esperado no quede negativo y devuelve `confirmed`;
- `validarNoNegativo()`: proyecta cada moneda por separado.

`egresarDeCaja()` las usa en orden: bloquea la sesión, **comprueba que siga abierta** (pudo
cerrarse entre la lectura y el bloqueo; lo encontró la revisión) y crea el movimiento
`pago_proveedor` con el proveedor de contraparte. El pago y su movimiento se apuntan uno al otro,
así que el id del pago se **reserva antes** con `nextval(...)`: así nunca hay que modificar un
movimiento de dinero ya creado.

Un movimiento `pago_proveedor` **no se edita ni se borra desde Caja**: `prepararCorreccion()` solo
acepta los manuales. Se corrige anulando el pago.

## 6. Aprobación (D2): el pago espera, pero reserva

Si el egreso nace `pending_approval`, el pago nace `pendiente_aprobacion`:
- **no baja el saldo mostrado** (la compra sigue «pendiente»);
- **sí cuenta en `limite`**: otro pago no puede aplicar ese mismo saldo mientras se decide.

`CashMovementController::approve()` / `reject()` aceptan ahora también `pago_proveedor` y, en la
misma transacción, llaman a `aprobado()` (pago → `vigente`) o `rechazado()` (pago → `anulado`,
motivo «Egreso rechazado en Caja», aplicaciones desaplicadas). Bloquean primero el pago y después
el movimiento: el mismo orden que al anular el pago.

## 7. Anular sin borrar

`anular()` exige motivo y:
1. **Caja confirmado** → `CashCorrectionService::revertirMovimiento()`: un `correction` de ingreso
   en la sesión abierta de quien anula; el original queda «Corregido». Si la sesión del egreso está
   cerrada, hace falta el permiso de supervisor (regla del servicio).
2. **Caja por aprobar** → el movimiento pasa a `rejected`.
3. **Banco** → solo se marca.

En todos los casos las aplicaciones se **marcan** (`desaplicada_at`, quién y por qué) y el pago
queda `anulado`. Nada se borra.

## 8. La compra respeta sus pagos

En `CompraService`:
- `anular()`: con aplicaciones activas (de pagos vigentes o por aprobar) → «Desaplica primero los
  pagos»;
- `exigirQueRespeteSusPagos()`: con pagos, la compra no cambia de proveedor ni de moneda, no se
  vuelve nota de crédito, y su total no baja de lo aplicado + por aprobar + notas;
- `registrar()` con **«Pagada ahora» (D3)**: solo al registrar, solo al contado, no en una nota,
  y solo si el usuario tiene `compras.pagar` (si no, la compra queda pendiente, sin error).
  Compra, pago y aplicación van en **una** transacción: si el pago falla (sin caja abierta, caja
  en negativo), no queda ni la compra.

> **Ejercicio:** `test_si_falla_el_pago_no_queda_la_compra`. ¿Qué pasaría si `pagarAhora()` se
> llamara después del `DB::transaction()` de `guardar()` en lugar de adentro?

## 9. Cuentas bancarias al sistema base

`cuentas_bancarias` era solo de Agencia (para el PDF de cotización); los pagos por banco la
necesitan en todos los giros. Se resolvió como `proveedores` en T-132:
- la migración de `core/` crea la tabla **solo si no existe**;
- la del vertical recibió la guarda `hasTable` (única excepción a «nunca editar una migración»: en
  las agencias existentes ya corrió y no vuelve a correr);
- `moneda` entra nullable: nunca se supone soles. Con pagos, la moneda queda fija (422 si se
  intenta cambiarla o vaciarla);
- permiso propio, `cuentas_bancarias.gestionar`, que la migración da a quien tenía
  `agencia.configuracion` (nadie pierde acceso);
- una cuenta (o un proveedor) con pagos no se borra: se desactiva.

## 10. Lo que queda afuera (ver backlog)

- Detracción, retención de 4ta y el aviso en Caja → T-163. Cuentas por pagar → T-137 (recorrido
  100). Obligaciones y cuotas de mayoristas → T-142/T-143.
- ~~Dólares y reembolso de NC~~ → hecho en T-140 (recorrido 101): T.C. del pago, aplicación en
  otra moneda (`monto_compra`), reembolsos y umbral de Caja en soles. Desde entonces `saldos()`
  suma `monto_compra` y los reembolsos, y lo disponible de un pago sale de `disponibles()`.
- `approve()` no bloquea la sesión al validar «no negativo» (ya pasaba con el egreso manual).
- Una compra cuyo pago espera aprobación se muestra «Vencido» si pasó su fecha.
- Tests que corren `tenants:migrate-verticales` sobre todos los tenants de la base de pruebas: un
  tenant huérfano los hace fallar a todos.
