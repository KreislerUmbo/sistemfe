# Recorrido: reembolsos del proveedor, pagos en dólares y en otra moneda

27-nov-2026, tarea **T-140** (todos los giros). T-136 dejó un solo modelo de pagos a proveedor
(recorrido 99) con tres huecos:
1. una nota de crédito recibida **después** de pagar deja la compra con **saldo a favor**, y no
   había cómo registrar que el proveedor devolvió el dinero;
2. un pago en dólares no guardaba su tipo de cambio, y una factura en USD no se podía pagar en
   soles;
3. la aprobación de Caja comparaba un egreso en USD contra un umbral en soles.

Lo que se aprende acá:
- **una tabla aparte cuando el dinero va en sentido contrario** (y por qué no un signo);
- **extraer código compartido sin cambiar el comportamiento** (`OrigenDineroService`);
- **dos montos en una aplicación** y **un solo punto de conversión**;
- **qué traba un reembolso** para que el saldo a favor no quede sin respaldo;
- **el orden de bloqueo** entre operaciones distintas, y cómo se rompe sin que nadie lo note;
- **no completar un tipo de cambio por detrás**.

Archivos:
- `app/Services/Compras/ReembolsoProveedorService.php` (nuevo): `registrar()`, `anular()`,
  `totalesPorCompra()`, `totalesPorPago()`
- `app/Services/Compras/OrigenDineroService.php` (nuevo, extraído de `PagoProveedorService`):
  `exigirCuenta()`, `moverEnCaja()`, `revertirEnCaja()`, `normalizarOperacion()`
- `app/Services/Compras/PagoProveedorService.php`: `saldos()`, `disponibles()` (nuevo),
  `convertir()` (nuevo), `diferenciaDeCambio()` (nuevo), `crearAplicaciones()`, `anular()`,
  `desaplicar()`
- `app/Services/Compras/CompraService.php`: `pagarAhora()`, `anular()`, `guardar()`,
  `exigirQueRespeteSusReembolsos()`
- `app/Services/Cash/EgresoCajaService.php`: `estadoInicial()` + `superaUmbral()`
- `app/Models/Compras/ReembolsoProveedor.php`; `ReembolsoProveedorController.php`
- Migraciones `2026_12_02_100000` (T.C. y `monto_compra`), `100100` (`reembolsos_proveedor`),
  `100200` (T.C. de la aplicación con 6 decimales)
- Frontend: `RegistrarReembolso.vue`, `ListaReembolsos.vue`, `FichaPagoProveedor.vue` (nuevos);
  `RegistrarPago.vue`, `AplicarPagoACuenta.vue`, `compras/index.vue`, `pagos.vue`,
  `cuentas-por-pagar.vue`; `helpers/pagosProveedor.ts`, `helpers/tipoCambioSugerido.ts`
- Tests: `PagoProveedorDolaresTest`, `ReembolsoProveedorTest`, `UmbralAprobacionDolaresTest`,
  `CuentasPorPagarTest::test_aplicacion_en_otra_moneda`; base común `ComprasT140TestCase`

**Al desplegar:** `tenants:migrate-verticales` (tres migraciones en `core/`). No hay permisos ni
menú nuevos.

---

## 1. Pantalla → tabla

| Qué pasa | Ruta | Permiso | Tablas |
|---|---|---|---|
| «Registrar reembolso» (ficha de la compra con saldo a favor, ficha del pago con algo a cuenta, detalle de cuentas por pagar) | `POST compras/reembolsos` | `compras.pagar` | escribe `reembolsos_proveedor` (+ `cash_movements` si es Caja) |
| «Anular» en la lista de reembolsos | `POST compras/reembolsos/{id}/anular` | `compras.anular` | marca `reembolsos_proveedor`; Caja: `cash_movements` tipo `correction` |
| «Registrar pago» con T.C. y compras de la otra moneda | `POST compras/pagos` | `compras.pagar` | `pagos_proveedor.tipo_cambio`; `aplicaciones_pago_proveedor.monto_compra`, `.tipo_cambio` |
| «Aplicar pago a cuenta» en otra moneda | `POST compras/pagos/{id}/aplicaciones` | `compras.pagar` | las mismas |
| «Ver pago» (ficha nueva) | `GET compras/pagos/{id}` | `compras.ver` | lee pagos, aplicaciones y reembolsos |

Sin permisos nuevos (D11): registrar un reembolso es «pagar al revés», anularlo es anular.

## 2. Por qué una tabla aparte (D5)

La tentación era guardar el reembolso en `pagos_proveedor` con un monto negativo o un campo
«sentido». Pero **todo** lo que lee esa tabla da por hecho que el dinero **sale**: el listado, lo
«a cuenta», `sinAplicarPorProveedor()`, el filtro `con_saldo`, la aprobación de Caja. Cada consulta
tendría que filtrar por sentido, y la primera que se olvidara sumaría un reembolso como un pago.
Con una tabla aparte, las consultas viejas siguen siendo correctas sin tocarlas: el error posible
se vuelve imposible.

Lo que sí se comparte es el **origen del dinero** (Caja o Banco). Ese código estaba dentro de
`PagoProveedorService`; se movió a `OrigenDineroService` con la dirección como parámetro (`out`
para el pago, `in` para el reembolso). La regla de la extracción: `PagoProveedorTest` siguió
pasando sin tocar sus aserciones.

Cada reembolso tiene **un** destino (CHECK `num_nonnulls(compra_id, pago_proveedor_id) = 1`):
- **la compra con saldo a favor** (D6), no la NC: el saldo vive en la compra, y una compra con
  dos NC tiene un solo saldo a favor;
- **lo «a cuenta» de un pago** (D2): el depósito que el mayorista devuelve sin emitir NC.

## 3. Los cálculos, cada uno en un lugar

**Saldo de una compra** — sigue siendo solo `PagoProveedorService::saldos()`:
```
saldo = total − NC vigentes − Σ monto_compra (aplicaciones activas de pagos vigentes)
        + Σ reembolsos vigentes a esa compra
```
Un saldo de −300 (a favor) reembolsado por 300 vuelve a 0 y la compra queda «pagado».

**Lo disponible de un pago** — `disponibles(array $pagoIds)`, en lote:
```
disponible = monto − Σ monto (aplicaciones activas) − Σ reembolsos vigentes del pago
```
Antes ese cálculo estaba escrito en **cinco** lugares. Tres pasaron a llamar a `disponibles()`; los
dos que son SQL agregado (`sinAplicarPorProveedor()` y el filtro `con_saldo`) restan los reembolsos
con una subconsulta igual a la de las aplicaciones. `test_disponible_igual_en_todas_partes`
comprueba que los cinco dan lo mismo.

> ¿Por qué el tope del reembolso usa el `saldo` y no el `limite` (saldo − por aprobar)? Porque lo
> que espera aprobación en Caja todavía no salió: no genera saldo a favor que devolver.

## 4. Dos montos y un solo punto de conversión (D3, D8)

Una aplicación cruzada (pago en soles a una factura en USD, o al revés) guarda:
- `monto_compra`: lo que **cancela** de la compra, en la moneda de la compra (lo que escribe la
  persona);
- `monto`: lo que **sale** del pago, en la moneda del pago = `convertir(monto_compra, …)`;
- `tipo_cambio`: soles por dólar, solo si las monedas difieren.

En la misma moneda los dos montos son iguales (CHECK `tipo_cambio is not null or monto_compra =
monto`), y la migración llenó `monto_compra = monto` en lo existente: el saldo pasa a sumar
`monto_compra` y nada cambia para lo viejo.

`convertir()` es el **único** lugar donde se pasa de una moneda a otra: `BigDecimal`, redondeo
`HALF_UP` a 2 decimales, una sola vez. USD 100.00 a 3.3333 = S/ 333.33; S/ 100.00 a 3.3333 =
USD 30.00.

**Por qué 6 decimales en el T.C. de la aplicación** (decidido en la revisión): con 4, un pago en
soles no siempre cancela exacto. USD 1,234.56 pagados con S/ 4,600.00: 3.7260 da 4,599.97 (quedan
S/ 0.03 a cuenta) y 3.7261 da 4,600.09 (no alcanza). Con 3.726027 da 4,600.00. El T.C. del pago,
de la compra y del reembolso siguen con 4: son referencias, no definen cuánto se cancela.

> Trampa vista: si la columna no se migra, Postgres **redondea en silencio** el valor a 4
> decimales, mientras el monto ya se calculó con 6. Una columna más angosta que la validación no
> da error: pierde datos.

## 5. Diferencia de cambio: solo se muestra (D9)

En una compra en USD, cada aplicación de un pago vigente tiene una diferencia en soles (positiva =
pérdida):
- pago en **soles**: `monto − monto_compra × T.C. de la compra` (los soles que de verdad salieron);
- pago en **USD**: `monto_compra × (T.C. del pago − T.C. de la compra)`.

Sin T.C. del pago (pagos en USD anteriores a T-140) es `null` → «sin T.C.», y entonces el
**total** de la ficha también es «sin T.C.»: un total que suma solo las filas completas parecería
completo. No genera asiento, movimiento ni entra al Registro de Compras.

## 6. Qué traba un reembolso

Un reembolso de saldo a favor se apoya en tres cosas: el total de la compra, las NC que la
modifican y los pagos aplicados. Si cualquiera cambia, el reembolso queda devolviendo un dinero que
ya no se debía. Por eso, con un reembolso vigente, todo esto responde «…tiene reembolsos: anúlalos
primero.»:
- la compra no se anula ni cambia total, moneda, proveedor o tipo
  (`exigirQueRespeteSusReembolsos()`);
- la NC que la modifica no se anula ni cambia lo que acredita (`cambiaLoQueAcredita()`);
- ningún pago aplicado a esa compra se desaplica ni se anula (decisión del usuario al construir:
  si no, anular el pago dejaba la compra debiendo el total y el reembolso sin respaldo);
- el pago cuyo «a cuenta» se reembolsó no se anula (sí se puede desaplicar: lo disponible crece).

Anulado el reembolso, todo vuelve a poder hacerse. Anular un reembolso por Caja usa
`CashCorrectionService` (un `correction` de salida); antes se valida que la sesión de quien anula
no quede en negativo, porque revertir un ingreso **saca** dinero.

## 7. El orden de bloqueo (lo que encontró la revisión)

Dos operaciones que bloquean las mismas filas en orden distinto pueden esperarse una a la otra;
Postgres corta una (error 40P01) y el usuario ve un 500. La regla de Compras:
- **pago:** cuenta o sesión de caja → (pago) → compras en orden de id;
- **reembolso:** cuenta o sesión → su destino (una compra o un pago). Al construirlo bloqueaba
  primero el destino: un pago y un reembolso a la vez sobre la misma cuenta y la misma compra se
  trababan. `/code-review` lo encontró;
- **compra:** compras → periodos → productos. `anular()` de una NC bloqueaba la compra de
  referencia **después** del periodo; ahora va antes.

> Ningún test lo detectó: un deadlock necesita dos transacciones a la vez. Por eso el orden se
> escribe en un comentario y se revisa leyendo, no probando.

## 8. Tipo de cambio: sugerido, nunca inventado (D4, D7)

- En pantalla se **sugiere** con la venta SUNAT de la fecha del pago o del reembolso
  (`helpers/tipoCambioSugerido.ts` → `GET compras/catalogos?fecha=`, la misma consulta que usa la
  compra) y se puede editar.
- En el backend, si falta donde es obligatorio: **422**. Nunca se completa por detrás.
- **Umbral de Caja en soles:** `EgresoCajaService::superaUmbral()` multiplica el USD por el
  **mayor** entre el T.C. del pago y la venta SUNAT de hoy (`HoraPeru::hoy()`); el egreso manual en
  USD, por la venta SUNAT. **Sin venta SUNAT, pide aprobación**: lo que no se puede contrastar no
  pasa sin revisión.

> Por qué el mayor (lo encontró `/security-review`, decidido por el usuario): al principio se
> usaba el T.C. del pago tal cual. Pero ese número lo escribe quien paga, y con T.C. 0.0001 un
> pago de USD 9,000 medía S/ 0.90 y salía de caja sin aprobación. Un dato que viene del usuario no
> puede decidir solo si el usuario necesita permiso.
- «Pagada ahora» en USD usa el T.C. de la compra: diferencia 0.

## 9. Lo que queda afuera (ver backlog)

- Compensar el saldo a favor con otra compra del mismo proveedor (D1): tarea nueva si se pide.
- Reembolso en otra moneda que la del saldo (D10); diferencia de cambio de la NC y del reembolso.
- Reporte de diferencias de cambio por periodo, o su paso al Registro de Compras: fase 5.
- Totales de cuentas por pagar convertidos a soles: siguen por moneda.
- Cuentas por pagar a una fecha de corte → T-164 (tendrá que contar reembolsos y usar
  `monto_compra`).
- Desde la ficha de una compra, «Registrar pago» deja la moneda fija: para pagarla en la otra
  moneda se entra por «Nuevo pago» o «Aplicar pago a cuenta».
- El T.C. exacto para cancelar en la otra moneda hay que calcularlo a mano (no hay botón «ajustar
  al saldo»).
- `LimpiarOperacionPrueba` no clasifica `reembolsos_proveedor` (línea existente en «Por analizar»).
