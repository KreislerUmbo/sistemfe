# T-140 — Reembolso del proveedor, pagos en dólares y en otra moneda, diferencia de cambio

| | |
|---|---|
| **Módulo** | COM + CAJ (todos los giros) |
| **Depende de** | **T-136** (en `main`: `pagos_proveedor` + aplicaciones) y **T-137** (en `main`: cuentas por pagar) |
| **Reglas** | No hay `docs/modulos/compras/reglas.md`. Las reglas vigentes son las de los recorridos `docs/aprendizaje/99-recorrido-pagos-a-proveedores.md` (§2-§8) y `100-recorrido-cuentas-por-pagar.md` (§2-§5). `CLAUDE.md`: «Dinero y caja» (nada silencioso, corrección con `correction`) y «Fechas y horas» |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7 «Pagos» y §3.7d punto 4 («Dólares») y «Devolución del mayorista»; decisiones del usuario del 27-nov-2026 (D1-D4) y de la preparación (D5-D11), abajo |
| **Rama** | `feat/T-140-nc-recibida-y-dolares` (desde `main`) |

## Contexto
T-136 dejó un solo modelo de pagos a proveedor, pero con tres huecos que ya se anunciaban en el
código y en los recorridos:
1. Una nota de crédito recibida **después** de pagar deja la compra con **saldo a favor**
   (`saldos()` da negativo, «A favor» en cuentas por pagar) y no hay forma de registrar que el
   proveedor devolvió el dinero.
2. Un pago en dólares no guarda su tipo de cambio, y `crearAplicaciones()` rechaza pagar una
   compra en otra moneda («todavía no se puede pagar en otra moneda»): una factura en USD pagada
   por transferencia en soles no se puede registrar.
3. La aprobación de egresos de Caja compara un monto en USD contra un umbral en soles («Por
   analizar» del backlog: «Ver con T-140»).

La compra en USD **ya** guarda su `tipo_cambio`, sugerido con la venta SUNAT de su fecha
(`GET compras/catalogos?fecha=` → `TipoCambioSunatResolver::resolverParaFecha()`). Esta tarea
reutiliza esa misma sugerencia y no crea otro módulo de tipo de cambio.

## Decisiones

**Del usuario (27-nov-2026):**
- **D1 — Saldo a favor: solo reembolso.** El proveedor devuelve el dinero y entra a Caja o al
  banco. Usar el saldo a favor de una compra para pagar otra del mismo proveedor (compensación)
  **no** entra (ver «Fuera de alcance»).
- **D2 — También se reembolsa lo «a cuenta» de un pago.** Caso: el depósito de una reserva
  cancelada que el mayorista devuelve sin emitir nota de crédito. Hoy la única salida es anular el
  pago, y así se pierden la fecha y la forma reales de la devolución.
- **D3 — Se puede pagar una compra en otra moneda** (pago en soles a factura en USD y al revés),
  con el tipo de cambio de esa aplicación. La conversión es explícita y queda guardada: nunca se
  convierte por detrás.
- **D4 — El umbral de aprobación de Caja se compara en soles.** Pago a proveedor: con su T.C.
  Egreso manual en USD: con la venta SUNAT vigente a hoy. Si no hay T.C., pide aprobación.

**De la preparación (revisables en la revisión, etapa 7):**
- **D5 — El reembolso va en una tabla propia, `reembolsos_proveedor`, y no en `pagos_proveedor`.**
  Todo lo que lee `pagos_proveedor` (listado, «a cuenta», `sinAplicarPorProveedor()`, el filtro
  `con_saldo`, aprobar o rechazar en Caja) da por hecho que el dinero **sale**. Un reembolso
  guardado ahí obligaría a filtrar por sentido en cada consulta, y una consulta sin ese filtro lo
  sumaría como pago. Lo que sí se comparte es el código del origen del dinero (Caja o Banco): se
  extrae, no se copia.
- **D6 — El reembolso de una NC apunta a la compra que tiene el saldo a favor** (la que modifica
  la NC), no a la NC. El saldo vive en esa compra (`saldos()`); una compra con dos NC tiene un
  solo saldo a favor.
- **D7 — El T.C. es «soles por dólar» y se sugiere con la venta SUNAT** de la fecha del pago o del
  reembolso (la misma consulta que usa la compra), editable. El backend **no** completa un T.C.
  que no llegó: si falta donde es obligatorio, responde 422.
- **D8 — La aplicación guarda dos montos:** `monto` en la moneda **del pago** (lo que sale del
  pago, como hoy) y `monto_compra` nuevo, en la moneda **de la compra** (lo que cancela). En la
  misma moneda son iguales.
- **D9 — Diferencia de cambio: solo se muestra.** Solo en compras en USD: por cada aplicación,
  `monto_compra × (T.C. del pago − T.C. de la compra)`, en soles. Positiva = pérdida (se pagaron
  más soles de los registrados); negativa = ganancia. Se ve en la ficha de la compra y en la del
  pago. No genera asiento ni movimiento, y no entra al Registro de Compras.
- **D10 — El reembolso va en la moneda del saldo** (la de la compra o la del pago). Un reembolso
  en otra moneda no entra.
- **D11 — Sin permisos nuevos:** registrar un reembolso = `compras.pagar`; anularlo =
  `compras.anular` (los mismos del pago).

## Modelo de datos

### Migración 1 — `database/migrations/tenant/core/2026_12_02_100000_add_tipo_cambio_to_pagos_proveedor.php`
- `pagos_proveedor.tipo_cambio` `decimal(10,4)` nullable (como `compras.tipo_cambio`).
- `aplicaciones_pago_proveedor.monto_compra` `decimal(14,2)`: se crea nullable, se llena con
  `update … set monto_compra = monto` y se pasa a `not null`.
- `aplicaciones_pago_proveedor.tipo_cambio` `decimal(10,4)` nullable: solo cuando la moneda de la
  compra y la del pago son distintas.
- CHECKs: `monto_compra > 0`; `tipo_cambio is not null or monto_compra = monto` (en la misma
  moneda, los dos montos son iguales).
- No se completan T.C. de pagos en USD que ya existan: quedan en null, y su diferencia de cambio se
  muestra «sin T.C.». T-136 no está en producción (el despliegue va en `3c70e268`, 23-nov), así
  que solo afecta a datos de desarrollo.

### Migración 2 — `…/2026_12_02_100100_create_reembolsos_proveedor_table.php`
Copia la forma de `pagos_proveedor` (`2026_12_01_100300_create_pagos_proveedor_tables.php`):

| Columna | Tipo | Nota |
|---|---|---|
| `proveedor_id` | FK `proveedores` | la ficha (no el RUC) |
| `fecha` | date | fecha de negocio; Caja = `HoraPeru::hoyTexto()`, Banco = la escrita, ≤ hoy |
| `moneda`, `monto` | string(3), decimal(14,2) | CHECK `monto > 0` |
| `tipo_cambio` | decimal(10,4) nullable | obligatorio en USD (D7) |
| `compra_id` | FK `compras` nullable | destino: compra con saldo a favor (D6) |
| `pago_proveedor_id` | FK `pagos_proveedor` nullable | destino: lo «a cuenta» de un pago (D2) |
| `origen` | string(20) | `caja` / `banco`, validado en PHP, sin CHECK de lista |
| `cash_movement_id`, `payment_method_id`, `cuenta_bancaria_id`, `numero_operacion` | como en `pagos_proveedor` | |
| `estado` | string(25) default `vigente` | `vigente` / `anulado` (un ingreso no pasa por aprobación) |
| `anulado_at`, `anulado_por`, `motivo_anulacion`, `observaciones`, `user_id`, timestamps | | |

CHECKs: `num_nonnulls(compra_id, pago_proveedor_id) = 1`; Caja con movimiento; Banco con cuenta y
número de operación. Índice único parcial `(cuenta_bancaria_id, numero_operacion) where estado <>
'anulado'`. Índices en `proveedor_id`, `compra_id`, `pago_proveedor_id`.

Modelo `app/Models/Compras/ReembolsoProveedor.php` (constantes `TIPO_MOVIMIENTO =
'reembolso_proveedor'`, orígenes y estados como `PagoProveedor`), con relaciones `reembolsos()` en
`Compra` y en `PagoProveedor`.

## Cálculos (un solo lugar cada uno)

**Saldo de una compra** — `PagoProveedorService::saldos()` sigue siendo el único cálculo:
```
saldo  = total − notas de crédito vigentes − Σ monto_compra (aplicaciones activas de pagos vigentes)
         + Σ monto de reembolsos vigentes a esa compra
limite = saldo − por_aprobar          (por_aprobar = Σ monto_compra de pagos pendientes)
```
Cambian dos cosas: se suma `a.monto_compra` (antes `a.monto`) y se agrega una consulta en lote para
los reembolsos. La salida (claves y tipos) no cambia, así que `estados()`, `CuentasPorPagarService`
y la ficha no se tocan por esto.

**Lo disponible de un pago** («a cuenta»):
```
disponible = monto − Σ monto (aplicaciones activas) − Σ monto de reembolsos vigentes del pago
```
Hoy ese cálculo está escrito **cinco veces**, y las cinco tienen que restar los reembolsos:
1. `PagoProveedorService::aplicadoDe()` (uso: `crearAplicaciones()`, ~línea 332);
2. `PagoProveedorService::sinAplicarPorProveedor()` (~línea 158, una consulta);
3. `PagoProveedorController::datos()` (~línea 142);
4. `PagoProveedorController::filtrar()`, filtro `con_saldo` (~línea 124, SQL);
5. `CuentasPorPagarService::detalle()` (~línea 220, `withSum`).

Reemplazar 1, 3 y 5 por **un** método en lote, `PagoProveedorService::disponibles(array $pagoIds):
array<int, BigDecimal>`. En 2 y 4 (SQL agregado) restar los reembolsos con una subconsulta igual a
la de las aplicaciones. `test_disponible_igual_en_todas_partes` comprueba que las cinco dan lo
mismo.

**Conversión (D3, D7)** — un solo método, por ejemplo `PagoProveedorService::convertir(BigDecimal
$monto, string $de, string $a, BigDecimal $tc): BigDecimal`, con `BigDecimal` y
`RoundingMode::HALF_UP` a 2 decimales, nunca `float`:
- compra USD, pago PEN: `monto = monto_compra × tc`;
- compra PEN, pago USD: `monto = monto_compra ÷ tc`.

**Diferencia de cambio (D9)** — `PagoProveedorService::diferenciasDeCambio(Compra|PagoProveedor)`
o equivalente, para cada aplicación activa de un pago vigente a una compra en USD con
`tipo_cambio`:
```
tc_pago = aplicación.tipo_cambio   si las monedas difieren (pago en soles)
        = pago.tipo_cambio          si el pago es en USD
diferencia (S/) = round(monto_compra × (tc_pago − compra.tipo_cambio), 2)
```
Sin `tc_pago` → `null` («sin T.C.»). Compra en soles → no aplica.

## Alcance

### Parte A — Dólares y pago en otra moneda (D3, D7, D8, D9)
- **T.C. del pago:** `PagoProveedorService::validar()` acepta `tipo_cambio`, **obligatorio si
  `moneda = USD`** (mensaje: «En dólares se necesita el tipo de cambio.»), con las reglas de
  `compras.tipo_cambio` en `CompraService::validar()` (`gt:0`, `max:999999`, `decimal:0,4`). En
  PEN se guarda null.
- **«Pagada ahora» (`CompraService::pagarAhora()`):** el pago es en la moneda de la compra, como
  hoy, y usa el `tipo_cambio` de la compra cuando es USD (diferencia 0).
- **Aplicación en otra moneda:** `aplicaciones[*].monto` sigue siendo **lo que se cancela de la
  compra** (moneda de la compra; hoy coincide con la del pago, así que las llamadas actuales no
  cambian) y se agrega `aplicaciones[*].tipo_cambio`, obligatorio solo si las monedas difieren.
  `crearAplicaciones()`:
  - deja de rechazar la otra moneda; sin T.C. → «La compra {serie-numero} está en {moneda}: indica
    el tipo de cambio.»;
  - `monto_compra = monto` recibido; `monto` guardado = `convertir(...)` a la moneda del pago;
  - `monto_compra ≤ limite` (mensaje y `maximo` como hoy, en la moneda de la compra) y
    `monto ≤ disponible` del pago (en la moneda del pago).
- **Salida de la API:** en `PagoProveedorController::datos()` y `CompraController::aplicacionesDe()`
  (~línea 215), cada aplicación trae `monto`, `monto_compra`, `tipo_cambio` y `diferencia_cambio`;
  el pago trae `tipo_cambio`, y la compra en USD trae `diferencia_cambio_total`.
- **Pantallas:**
  - `RegistrarPago.vue`: campo «T.C.» cuando el pago es en USD (sugerido con
    `compras/catalogos?fecha=` como `form.vue::sugerirTipoCambio()`, sin pisar lo escrito). Lista
    también las compras pendientes **de la otra moneda** (deja de mandar `moneda` a
    `compras-pendientes`), en un bloque aparte, cada una con su T.C. (sugerido: el del pago si es
    USD; si no, la venta SUNAT de la fecha del pago) y el equivalente en la moneda del pago. «Queda
    a cuenta» se calcula en la moneda del pago.
  - `AplicarPagoACuenta.vue`: lo mismo al aplicar un pago a una compra de otra moneda.
  - Ficha de la compra (`compras/index.vue`, tabla de pagos ~línea 268): en una aplicación cruzada,
    «S/ 3,752.30 a T.C. 3.7523»; en compras en USD, columna «Dif. cambio» (pérdida/ganancia) y su
    total.
  - Ficha del pago (`compras/pagos.vue`): T.C. del pago y diferencia por aplicación.

### Parte B — Reembolso (D1, D2, D5, D6, D10, D11)
- `app/Services/Compras/ReembolsoProveedorService.php`: `registrar(array $entrada, User)`,
  `anular(ReembolsoProveedor, ?string $motivo, User)`, y los totales en lote que necesitan
  `saldos()` y `disponibles()`.
- **Origen del dinero sin copiar:** sacar de `PagoProveedorService` a una clase compartida (por
  ejemplo `app/Services/Compras/OrigenDineroService.php`) lo que valida la cuenta (`exigirCuenta()`,
  `operacionRegistrada()`, `yaRegistrada()`), el movimiento de Caja (`egresarDeCaja()`, con la
  dirección como parámetro: `out` para el pago, `in` para el reembolso) y su reversión
  (`revertirSalida()`). Se extrae **sin cambiar el comportamiento del pago**: `PagoProveedorTest`
  tiene que seguir pasando sin tocar sus aserciones (salvo la de la línea 219, ver CA5).
- **Registrar** (`POST compras/reembolsos`, `compras.pagar`). Entrada: `proveedor_id`, `compra_id`
  **o** `pago_proveedor_id`, `moneda`, `monto`, `tipo_cambio` (USD), `origen`, `fecha` (Banco),
  `cuenta_bancaria_id` + `numero_operacion` (Banco), `payment_method_id` (Caja), `observaciones`.
  En una transacción:
  - **Destino compra:** bloquear la compra (`lockForUpdate`); tiene que estar vigente, no ser NC
    (una NC se reembolsa en la compra que modifica, D6), ser de la **misma ficha**
    (`proveedor_id`) y estar en la misma moneda; `saldos()` **después** del bloqueo; `saldo < 0`
    y `monto ≤ −saldo`. Si no: «La compra {serie-numero} no tiene saldo a favor» o «Solo se pueden
    reembolsar {x}» con `maximo`. Se usa el `saldo` y no el `limite`: lo que espera aprobación
    todavía no se pagó y no genera saldo a favor.
  - **Destino pago:** bloquear el pago; tiene que estar `vigente` (ni por aprobar ni anulado), ser
    de la misma ficha y la misma moneda, y cumplir `monto ≤ disponible`.
  - **Caja:** sesión abierta de quien registra, bloqueada y comprobada (como `egresarDeCaja()`),
    método activo, movimiento `type = reembolso_proveedor`, `direction = in`, contraparte
    proveedor, `reference_type`/`reference_id` al reembolso (id reservado con `nextval`, como el
    pago), `status = confirmed` (`estadoInicial()` con `in` no pide aprobación).
  - **Banco:** cuenta activa con la misma moneda; operación repetida → 409 con los datos del
    reembolso existente (mismo formato que el pago). El número de operación se normaliza igual
    (mayúsculas, sin espacios).
- **Anular** (`POST compras/reembolsos/{id}/anular`, `compras.anular`): motivo obligatorio (≤ 255).
  Caja confirmado → `CashCorrectionService::revertirMovimiento()` (sus reglas: sesión de quien
  anula, supervisor si la sesión del ingreso está cerrada, no negativo). Banco → solo se marca.
  Nada se borra.
- **Lo que el reembolso traba** (mensaje: «… tiene reembolsos: anúlalos primero.»). Mientras haya
  un reembolso vigente:
  - el **pago** reembolsado no se anula (`PagoProveedorService::anular()`); sus aplicaciones sí se
    pueden desaplicar (lo disponible crece);
  - la **compra** reembolsada no se anula (`CompraService::anular()`) ni cambia su total, moneda o
    proveedor (`exigirQueRespeteSusPagos()` o un `exigir…` hermano);
  - las **NC que la modifican** no se anulan ni cambian lo que acreditan (`cambiaLoQueAcredita()`
    en `guardar()`, y en `anular()` cuando lo que se anula es una NC). La compra de referencia ya
    se bloquea en `guardar()` (~línea 176): no hace falta otro bloqueo.
- **Orden de bloqueo:** el de T-136 (primero el pago, después las compras por id). Un reembolso
  bloquea solo su destino (un pago **o** una compra), así que no se forma un ciclo.
- **Caja:** `reembolso_proveedor` no se edita ni se corrige desde Caja (`prepararCorreccion()` ya
  acepta solo los manuales: confirmar con un test). Etiqueta en
  `admin-start-kit/src/helpers/cash/tipoMovimiento.ts`: «Reembolso de proveedor».
- **Salida de la API:** `reembolsos` (con estado, origen, fecha, monto, quién) y `reembolsado` en
  la ficha de la compra (`CompraController::respuesta()`) y en la del pago
  (`PagoProveedorController::datos()`).
- **Pantallas:** componente nuevo `src/components/Compras/RegistrarReembolso.vue` (reutiliza
  `CamposOrigenPago.vue`; título «Reembolso del proveedor», monto sugerido = el máximo). Botón
  «Registrar reembolso» (con `compras.pagar`) en:
  - la ficha de la compra con saldo a favor;
  - la ficha del pago vigente con algo a cuenta;
  - el detalle de cuentas por pagar (`cuentas-por-pagar.vue`), en las compras con badge «A favor»
    y en los pagos con algo a cuenta. Al guardar se recarga, igual que el pago (recorrido 100 §7).

  Lista de reembolsos con «Anular» (con `compras.anular`, motivo con `pedirMotivo.ts`) en las dos
  fichas.

### Parte C — Umbral de aprobación en soles (D4)
- `EgresoCajaService::estadoInicial()` recibe un `?string $tipoCambio` opcional. Si la moneda es
  USD, la empresa exige aprobación y hay umbral: `monto_soles = monto × tc` con `BigDecimal`;
  `tc` = el recibido o, si no llega, `TipoCambioSunatResolver::resolverParaFecha(HoraPeru::hoy())?->venta`.
  **Sin T.C. → `pending_approval`** (nunca pasa sin revisión). PEN: sin cambios.
- `PagoProveedorService` (o la clase extraída) le pasa el `tipo_cambio` del pago; el egreso manual
  (`CashMovementController::store()`, ~línea 69) no le pasa nada.
- Al cerrar, sacar de «Por analizar» la línea «La aprobación de egresos compara el monto en su
  moneda…» (la resuelve esta tarea).

## Fuera de alcance
- **Compensación** del saldo a favor con otra compra del mismo proveedor (D1). Si se pide,
  tarea nueva: la cubriría una aplicación cuyo origen es una NC.
- **Reembolso en otra moneda** que la del saldo (D10).
- **Diferencia de cambio de la NC** (su T.C. contra el de la compra) y del reembolso: solo se
  guarda el T.C. del reembolso.
- **Reporte de diferencias de cambio por periodo** o su paso al Registro de Compras: fase 5
  («Registros para el contador»). Ningún asiento contable.
- **Convertir a soles** los totales de cuentas por pagar: siguen separados por moneda (recorrido 100
  §5).
- **Cuentas por pagar a una fecha de corte (T-164, diferida):** cuando se construya, tendrá que
  contar también los reembolsos (`created_at`/`anulado_at`) y usar `monto_compra`. Anotarlo en
  «Hallazgos» al cerrar, para pasarlo a T-164.
- **«Pagada ahora» en otra moneda:** sigue siendo en la moneda de la compra.
- **NC repartida entre reservas y comisiones** → T-145. Detracción y retención → T-163.
- `LimpiarOperacionPrueba` tampoco clasificará `reembolsos_proveedor`: va en la línea de «Por
  analizar» que ya existe para `pagos_proveedor`, no se arregla acá.
- Un listado propio de reembolsos (`GET compras/reembolsos`): no hace falta, se ven en las fichas.

## Dónde tocar
- `api-sistema-fe/database/migrations/tenant/core/` → las dos migraciones de arriba.
- `app/Models/Compras/ReembolsoProveedor.php` (nuevo); `AplicacionPagoProveedor.php` (fillable y
  casts de `monto_compra`, `tipo_cambio`); `PagoProveedor.php` (`tipo_cambio`, `reembolsos()`);
  `Compra.php` (`reembolsos()`).
- `app/Services/Compras/PagoProveedorService.php` → `saldos()` (~45), `aplicadoDe()` (~146) →
  `disponibles()`, `sinAplicarPorProveedor()` (~158), `anular()` (~256), `crearAplicaciones()`
  (~328), `egresarDeCaja()`/`exigirCuenta()`/`revertirSalida()` (~384-457, a extraer), `validar()`
  (~483), `validarAplicaciones()` (~522).
- `app/Services/Compras/ReembolsoProveedorService.php` y la clase de origen del dinero (nuevas).
- `app/Services/Compras/CompraService.php` → `pagarAhora()` (~71), `anular()` (~99),
  `guardar()` (~146-212), `exigirQueRespeteSusPagos()` (~410), `cambiaLoQueAcredita()` (~674).
- `app/Services/Compras/CuentasPorPagarService.php` → `detalle()` (~220).
- `app/Services/Cash/EgresoCajaService.php` → `estadoInicial()`.
- `app/Http/Controllers/Compras/PagoProveedorController.php` → `filtrar()` (~124), `datos()` (~139);
  `CompraController.php` → `respuesta()` (~191), `aplicacionesDe()` (~215); controlador nuevo
  `ReembolsoProveedorController.php` (`store`, `anular`).
- `routes/api.php` (~429-465): las dos rutas nuevas junto a las de pagos, con los mismos
  middlewares de permiso.
- `admin-start-kit/src/components/Compras/RegistrarPago.vue`, `AplicarPagoACuenta.vue`,
  `RegistrarReembolso.vue` (nuevo); `src/views/compras/index.vue` (ficha), `pagos.vue`,
  `cuentas-por-pagar.vue` (detalle); `src/types/pagosProveedor.ts`;
  `src/helpers/cash/tipoMovimiento.ts`.

## Criterios de aceptación
Tests nuevos: `tests/Feature/Compras/PagoProveedorDolaresTest.php`,
`tests/Feature/Compras/ReembolsoProveedorTest.php` y `tests/Feature/Cash/UmbralAprobacionDolaresTest.php`.
Para el T.C. SUNAT, crear filas en `tipo_cambio_sunat` (central) dentro del test y borrarlas al
terminar, o simular `TipoCambioSunatResolver`.

**Parte A**
- [ ] **CA1** — Un pago en USD sin `tipo_cambio` → 422 «En dólares se necesita el tipo de cambio.»;
  uno en PEN que trae `tipo_cambio` se guarda con null → `PagoProveedorDolaresTest::test_tipo_cambio_del_pago`
- [ ] **CA2** — Compra USD 500.00 a T.C. 3.7000 registrada con «Pagada ahora» → pago USD con
  T.C. 3.7000, diferencia 0.00 → `…::test_pagada_ahora_en_dolares`
- [ ] **CA3** — Compra USD 1,000.00 (T.C. 3.7000); pago Banco PEN 3,752.30 aplicado con
  `monto = 1000.00`, `tipo_cambio = 3.7523` → aplicación `monto = 3752.30`, `monto_compra = 1000.00`;
  compra «pagado»; pago con 0.00 a cuenta; diferencia +52.30 (pérdida) → `…::test_pago_en_soles_a_compra_en_dolares`
- [ ] **CA4** — Compra PEN 375.00; pago USD 100.00 (T.C. 3.7500) aplicado con `monto = 375.00`,
  `tipo_cambio = 3.7500` → `monto = 100.00`; sin diferencia de cambio (compra en soles) →
  `…::test_pago_en_dolares_a_compra_en_soles`
- [ ] **CA5** — Aplicación en otra moneda sin T.C. → 422; con `monto_compra` > límite → 422 con
  `maximo` en la moneda de la compra; con equivalente > disponible del pago → 422. En
  `PagoProveedorTest` (~línea 219) el rechazo de la compra en USD sigue, pero ahora por falta de
  T.C.: se cambia **solo** el texto esperado → `…::test_rechazos_en_otra_moneda`
- [ ] **CA6** — Redondeo en un solo punto: compra USD 100.00 pagada en PEN a T.C. 3.3333 →
  `monto = 333.33`; compra PEN 100.00 pagada en USD a T.C. 3.3333 → `monto = 30.00` (HALF_UP) →
  `…::test_conversion_redondea_una_vez`
- [ ] **CA7** — Diferencia de cambio: compra USD 1,000.00 a 3.7000; pago USD a 3.7500 → +50.00;
  otro caso, pago PEN a 3.6500 → −50.00; pago USD sin T.C. (dato anterior) → `null`, y la ficha
  muestra «sin T.C.» → `…::test_diferencia_de_cambio`
- [ ] **CA8** — En una aplicación cruzada, cuentas por pagar baja lo pendiente en USD por
  `monto_compra` y lo «a cuenta» en PEN por `monto`, sin mezclar monedas →
  `CuentasPorPagarTest::test_aplicacion_en_otra_moneda` (nuevo)
- [ ] **CA9** — La migración deja `monto_compra = monto` en las aplicaciones existentes, y
  `PagoProveedorTest` y `CuentasPorPagarTest` pasan sin otros cambios → suite + `…::test_migracion_conserva_lo_aplicado`

**Parte B**
- [ ] **CA10** — Compra 1,000.00 pagada, NC 300.00 → «A favor» 300.00; reembolso Banco 300.00 →
  saldo 0.00, estado «pagado», cuentas por pagar con «A favor» 0 → `ReembolsoProveedorTest::test_reembolso_de_nota_de_credito_por_banco`
- [ ] **CA11** — Reembolso por Caja → `cash_movements` `reembolso_proveedor`, `in`, `confirmed`,
  contraparte proveedor, en la sesión abierta de quien registra; el efectivo esperado sube; sin
  sesión abierta → 422 → `…::test_reembolso_por_caja`
- [ ] **CA12** — Topes: reembolso > saldo a favor → 422 con `maximo`; compra sin saldo a favor →
  422; compra con pago por aprobar y saldo positivo → 422; un segundo reembolso que pasa lo que
  queda → 422 (el saldo se calcula después del bloqueo) → `…::test_topes_del_reembolso`
- [ ] **CA13** — Pago a cuenta 500.00, reembolso 200.00 → 300.00 a cuenta en la ficha, el listado,
  el filtro `con_saldo`, `compras-pendientes` y cuentas por pagar; aplicar 400.00 → 422 →
  `…::test_reembolso_de_lo_a_cuenta` y `…::test_disponible_igual_en_todas_partes`
- [ ] **CA14** — Pago `pendiente_aprobacion` o anulado → no se reembolsa (422) → `…::test_pago_no_reembolsable`
- [ ] **CA15** — Otra ficha del proveedor, otra moneda o una NC como destino → 422; los dos
  destinos a la vez, o ninguno → 422 → `…::test_destino_invalido`
- [ ] **CA16** — Operación bancaria repetida (también «op 01» contra «OP01») → 409 con los datos
  del existente; una anulada se puede volver a registrar → `…::test_operacion_repetida`
- [ ] **CA17** — Anular: sin motivo → 422; por Caja → `correction` de salida y el original
  «Corregido»; por Banco → marcado; el saldo vuelve a −300.00 → `…::test_anular_reembolso`
- [ ] **CA18** — Con un reembolso vigente: la compra no se anula ni cambia total, moneda o
  proveedor; la NC que la modifica no se anula ni cambia su total; el pago reembolsado no se anula
  (todo 422 «…anúlalos primero»). Anulado el reembolso, todo vuelve a poder hacerse →
  `…::test_el_reembolso_traba_lo_que_lo_sostiene`
- [ ] **CA19** — Sin `compras.pagar` → 403 al registrar; sin `compras.anular` → 403 al anular →
  `…::test_permisos`
- [ ] **CA20** — Un movimiento `reembolso_proveedor` no se edita ni se corrige desde Caja (422) →
  `…::test_caja_no_lo_edita`

**Parte C**
- [ ] **CA21** — Empresa con aprobación y umbral S/ 1,000; venta SUNAT de hoy 3.7500: egreso
  manual USD 300 (S/ 1,125) → `pending_approval`; USD 250 (S/ 937.50) → `confirmed` →
  `UmbralAprobacionDolaresTest::test_egreso_manual_en_dolares`
- [ ] **CA22** — Pago a proveedor por Caja USD 300 con T.C. del pago 3.3000 (S/ 990) →
  `confirmed`: usa el T.C. del pago, no el SUNAT → `…::test_pago_usa_su_tipo_de_cambio`
- [ ] **CA23** — Sin ningún T.C. SUNAT → egreso manual USD → `pending_approval`; en PEN nada cambia
  → `…::test_sin_tipo_de_cambio_pide_aprobacion`

**Pantalla**
- [ ] **CA24** — Prueba funcional en `agencia-demo` (Playwright o a mano): (a) factura USD pagada
  en soles desde «Nuevo pago», con T.C. sugerido y el equivalente; ficha con diferencia de cambio;
  (b) NC después de pagar → «Registrar reembolso» desde la ficha y desde cuentas por pagar; (c) pago
  a cuenta reembolsado en parte; (d) anular un reembolso por Caja y verlo en el turno como
  «Reembolso de proveedor».

## Seguridad y correctitud
- [ ] **Permisos:** las dos rutas nuevas con `compras.pagar` / `compras.anular` (D11), con el mismo
  middleware que las de pagos; CA19 lo comprueba.
- [ ] **Tenancy:** todo en la base del tenant; `tipo_cambio_sunat` es central y solo se **lee** con
  su modelo (`CentralConnection`).
- [ ] **Entrada:** validación explícita como `PagoProveedorService::comprobar()`; `compra_id` y
  `pago_proveedor_id` enteros, que existan y sean de la ficha.
- [ ] **Concurrencia:** `lockForUpdate` del destino antes de calcular saldo o disponible; sesión de
  Caja bloqueada y comprobada; el orden de bloqueo de T-136.
- [ ] **Dinero:** `BigDecimal` en saldos, conversión y diferencia; un solo método de conversión y
  un solo cálculo de disponible; nada silencioso en Caja (anular = `correction`).
- [ ] **Fechas:** `HoraPeru::hoyTexto()` para la fecha de Caja y el tope «no futura»;
  `HoraPeru::hoy()` para el T.C. de hoy.
- [ ] **Tributario:** sin fallback silencioso de T.C.: en USD, sin T.C. → 422 (pago, aplicación,
  reembolso) o pide aprobación (Caja).
- [ ] **Salida:** sin `v-html`; los 409 devuelven solo fecha, monto, moneda y proveedor.
- [ ] **Migraciones:** dos nuevas en `core/`, ninguna editada; `php artisan tenants:migrate-verticales`.

## Cómo verificar
```
php artisan tenants:migrate-verticales
php artisan test --filter='PagoProveedorDolaresTest|ReembolsoProveedorTest|UmbralAprobacionDolaresTest|PagoProveedorTest|CuentasPorPagarTest'
php artisan test            # suite completa contra PostgreSQL (una sola a la vez)
# frontend (desde C:/, no c:/ — ver memoria de vitest)
npx vue-tsc --noEmit && npm run build
```
Antes de la suite, borrar los tenants `test-*` huérfanos de `sistemafe_test_migrations` («Por
analizar», hallazgo de T-136). Prueba funcional: `http://agencia-demo.sistemafe.test:5173`
(credencial en la memoria), con una sesión de caja abierta y una cuenta bancaria en USD y otra en
PEN.

**Al desplegar:** `tenants:migrate-verticales` (lo corre `deploy.sh`; revisar su salida). No hay
permisos ni menú nuevos.

---

## Resultado
<!-- Lo completa quien construye, al terminar. -->
- **Commits:** `ff063959` (backend), `d7a289cc` (frontend), en `feat/T-140-nc-recibida-y-dolares`.
  La rama sale de `docs/T-140-preparar` (`bc3be319`), porque la tarea todavía no está en `main`.
- **Tests:**
  - Nuevos: `PagoProveedorDolaresTest` (CA1-CA7, CA9), `ReembolsoProveedorTest` (CA10-CA20 y «anular
    no deja la caja en negativo»), `UmbralAprobacionDolaresTest` (CA21-CA23),
    `CuentasPorPagarTest::test_aplicacion_en_otra_moneda` (CA8); base común `ComprasT140TestCase`.
  - Suite completa contra PostgreSQL: **2619 pasan y 1 falló**
    (`CompraTest::test_ca1_y_ca9…`, inventario de rutas sin las dos nuevas). Se anotaron las rutas
    y se volvió a correr solo `CompraTest` (10/10) y los tests de la tarea (68/68), no la suite
    entera.
  - Frontend: `vue-tsc` sin errores, `build` correcto, Vitest 47 archivos en verde.
  - CA24, Playwright en `agencia-demo`: (a) factura USD pagada en soles con T.C. sugerido y
    «Pérdida S/ 62.00» en las dos fichas; (b) reembolso parcial por Caja desde la ficha y el resto por
    banco desde cuentas por pagar; (c) pago a cuenta reembolsado en parte; (d) reembolso por Caja
    anulado, visto en el turno como «Reembolso de proveedor» con su corrección. Además, «Aplicar
    pago a cuenta» de soles a dólares. Consola sin errores. Quedaron datos de prueba en
    `agencia-demo`: compras FT40-1 a FT40-3 y FC40-1, cuenta «BCP T140 USD-T140» y sus pagos.
- **Decisión del usuario al construir:** con un reembolso vigente en una compra **tampoco se
  desaplica ni se anula** ningún pago vigente aplicado a ella. Sin esa traba, anular el pago dejaba
  la compra debiendo el total y el reembolso sin respaldo.
- **Desviaciones de la tarea y por qué:**
  - `pagos.vue` no tenía ficha del pago: se creó (`FichaPagoProveedor.vue`, botón «Ver pago»).
    También `ListaReembolsos.vue` y `helpers/tipoCambioSugerido.ts` (aparte, para que los helpers
    puros sigan probándose sin navegador).
  - `aplicadoDe()` se conserva (devuelve lo aplicado y lo usa un test existente); lo disponible sale
    de `disponibles()`.
  - `CashCorrectionService` no valida «no negativo»: se valida en
    `OrigenDineroService::revertirEnCaja()` al revertir un ingreso.
  - `EgresoCajaService` pide `TipoCambioSunatResolver` con `app()` y no por constructor: un test
    existente lo construye a mano.
  - El origen del dinero quedó en `moverEnCaja()` / `revertirEnCaja()` / `exigirCuenta()` de
    `OrigenDineroService` (sirven para los dos sentidos).
  - `guardar()` bloquea además la compra que la nota modificaba hasta ahora, y anular una NC bloquea
    la compra de referencia: sin eso, un reembolso podía registrarse a la vez.
  - Una aplicación cruzada cuyo equivalente redondea a 0.00 se rechaza (422).
  - `compras-pendientes` no devuelve ninguna cifra «a cuenta» (CA13): se comprueba el tope al aplicar.
  - Tests existentes tocados, además del texto de CA5: en `PagoProveedorTest`, el pago en USD sin
    T.C. de «no deja el efectivo en negativo» ahora envía T.C. (la aserción no cambia);
    `CompraTest` (rutas); `ComprasMigracionesPorGiroTest` y `ProveedoresTablaBaseTest` (la tabla
    nueva apunta a compras y pagos).
  - En pantalla, `quedaACuenta()` se quitó junto con sus dos aserciones de Vitest (sin uso).

## Revisión (etapa 7, 27-nov-2026)
- **Orden de bloqueo al anular una NC:** `CompraService::anular()` bloqueaba la NC, el periodo y
  después la compra que modifica; `guardar()` bloquea compras y después periodos. Anular la nota y
  editar esa compra a la vez podían trabarse. Ahora la compra de referencia se bloquea antes del
  periodo.
- **T.C. de la aplicación con 6 decimales (decisión del usuario, opción b):** con 4, un pago en soles
  no siempre cancelaba exacto una factura en dólares (USD 1,234.56 con S/ 4,600.00 daba 4,599.97 o
  4,600.09). Migración nueva `2026_12_02_100200_tipo_cambio_aplicacion_seis_decimales.php`
  (`numeric(12,6)`), cast `decimal:6`, validación `decimal:0,6` e inputs con `step="0.000001"` en
  `RegistrarPago.vue` y `AplicarPagoACuenta.vue`. El T.C. del pago, de la compra y del reembolso
  sigue con 4. Test: `PagoProveedorDolaresTest::test_seis_decimales_cancelan_exacto`; CA3 y CA4
  esperan ahora `3.752300` / `3.750000`.
- **Trampa vista:** si la columna no se migra, Postgres redondea el T.C. a 4 decimales en silencio,
  mientras el monto ya se calculó con 6. La base de tests (`sistemafe_test_migrations`) se migra
  aparte: `migrate --database=pgsql --path=…` con `DB_DATABASE=sistemafe_test_migrations`.

## Hallazgos fuera de alcance
<!-- Problemas vistos y NO arreglados. Se convierten en tareas o en "Por analizar". -->
- T-164 (cuando se construya) tiene que contar los reembolsos y usar `monto_compra` (ver «Fuera de alcance»).
- Desde la ficha de una compra, «Registrar pago» deja la moneda fija en la de la compra: para
  pagarla en la otra moneda hay que entrar por «Nuevo pago» o «Aplicar pago a cuenta».
- Al cerrar quedan: sacar de «Por analizar» la línea del umbral en su moneda, el recorrido y el
  §10 del recorrido 99 y §8 del 100.
