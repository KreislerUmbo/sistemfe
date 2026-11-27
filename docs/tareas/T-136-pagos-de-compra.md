# T-136 — Pagos de compra con un solo modelo: pagos a proveedor y sus aplicaciones

| | |
|---|---|
| **Módulo** | COM + CAJ |
| **Depende de** | T-135, T-141 (en `main`). **T-162 unida a `main` antes de empezar**: las dos tocan `CompraService` y `compras/form.vue` |
| **Reglas** | `CLAUDE.md` → «Dinero y caja» (nunca editar/borrar un `cash_movement`; corregir con `CashCorrectionService`), «Fechas y horas», «Multi-tenancy» (migraciones nuevas en `tenant/core/`) |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7 («Pagos», revisión del 24-nov), §3.7d (el mismo modelo sirve a mayoristas, T-143), §5c C2 (origen Banco sin sesión) y C8 (tablas del sistema base en todos los giros); decisiones del usuario del 27-nov-2026 (abajo) |
| **Rama** | `feat/T-136-pagos-de-compra` (desde `main`) |

## Contexto
Hoy una compra registra condición (contado/crédito) y vencimiento, pero no si se pagó. El diseño
(§3.7) fija **un solo modelo de pagos** para todo Compras: `pagos_proveedor` es el dinero que sale,
una vez; `aplicaciones_pago_proveedor` dice cuánto de ese pago cancela qué compra. Un pago sin
aplicar queda «a cuenta» del proveedor. Más adelante el mismo modelo sirve para detracción y
retención (T-163), cuentas por pagar (T-137), dólares y NC con reembolso (T-140), obligaciones y
cuotas de mayoristas (T-143) y Rendiciones: por eso el origen es un catálogo abierto y la
aplicación deja lugar a otros destinos.

Hoy Caja exige sesión abierta para cualquier egreso (§5c C2); un pago por transferencia no tiene
dónde registrarse. `cuentas_bancarias` existe solo en Agencia (para el PDF de cotización).

**Decisiones del usuario (27-nov-2026):**
- **D1 — Se parte la tarea.** Detracción, retención de 4ta y el aviso en Caja («este egreso a un
  proveedor con compras pendientes, ¿lo aplico?») pasan a **T-163**. T-136 deja el modelo listo
  para ellas (orígenes abiertos), sin construirlas.
- **D2 — La aprobación de egresos de Caja se respeta.** Si la empresa exige aprobación
  (`companies.require_expense_approval`) y el pago desde Caja supera
  `max_expense_without_approval`, el egreso queda `pending_approval` y el pago **no cuenta** para el
  saldo de la compra hasta que se aprueba. Si se rechaza, el pago queda anulado.
- **D3 — Contado se paga en el mismo formulario.** Al registrar una compra al contado aparece
  «Pagada ahora» (Caja o Banco), marcado por defecto; se puede desmarcar y pagar después. Compra,
  pago y aplicación en **una** transacción.
- **D4 — La NC recibida baja el saldo.** Saldo = total − NC vigentes que la referencian − pagos
  aplicados. Si la NC deja saldo a favor (la compra ya estaba pagada), se muestra, y el reembolso
  queda para T-140.

## Alcance

### 1. `cuentas_bancarias` al sistema base (§5c C2/C8)
- Migración `tenant/core/` que crea la tabla **solo si no existe** (mismo patrón que
  `2026_11_24_200000_create_proveedores_table_core.php`, mismo esquema que la de Agencia).
  La del vertical (`verticals/agencia-viajes/2026_08_13_110100_create_cuentas_bancarias_table.php`)
  hoy crea la tabla sin preguntar: en una agencia nueva fallaría, porque `core/` corre antes. Se le
  agrega `if (Schema::hasTable('cuentas_bancarias')) return;` al inicio de `up()`, **la misma
  excepción a «nunca editar una migración» que hizo T-132 con `proveedores` (`0772ac23`)**: en las
  agencias existentes ya corrió y no vuelve a correr, así que no cambia nada ahí. Nada más se toca
  de esa migración.
- Otra migración `tenant/core/` agrega `moneda` (`PEN`/`USD`, nullable: las existentes quedan sin
  moneda y la pantalla pide completarla al usarlas para pagar).
- Modelo a `App\Models\CuentaBancaria`; se actualizan sus 4 usos (`CuentaBancariaController`,
  `AlternativaPdfService`, `CondicionesGeneralesPdfService`, y el modelo viejo se borra).
- Rutas `cuentas-bancarias` salen del grupo de Agencia al sistema base, con permiso nuevo
  **`cuentas_bancarias.gestionar`**. La migración de permisos se lo da a todo rol que hoy tenga
  `agencia.configuracion` (nadie pierde acceso); Propietario lo recibe con `roles:sync-propietario`.
- `destroy()`: una cuenta con pagos no se borra (422: «desactívala»). Sin pagos, como hoy.
- Pantalla: el bloque de cuentas de `agencia-viajes/configuracion/index.vue` (~línea 321) pasa a un
  componente `components/CuentasBancarias.vue`, que se usa ahí (igual que hoy) y en la pantalla de
  pagos de Compras (punto 5). Agrega el campo moneda.

### 2. Tablas (migración nueva en `tenant/core/`)
**`pagos_proveedor`**
- `proveedor_id` (FK `proveedores`), `fecha` (date, fecha de negocio), `moneda` (`PEN`/`USD`),
  `monto` decimal(14,2) > 0.
- `origen` string: en T-136 solo `caja` y `banco`, validado en PHP contra `PagoProveedor::ORIGENES`
  (T-163 agrega `detraccion` y `retencion_4ta`; Rendiciones, `fondo_rendir`). Sin enum ni CHECK de
  lista, para que agregar un origen no pida migración.
- `cash_movement_id` (FK `cash_movements`, nullable), `payment_method_id` (nullable, el del egreso),
  `cuenta_bancaria_id` (FK, nullable), `numero_operacion` string(30) nullable.
  CHECK: `origen <> 'caja' or cash_movement_id is not null`; `origen <> 'banco' or
  (cuenta_bancaria_id is not null and numero_operacion is not null)`.
- Índice único parcial `(cuenta_bancaria_id, numero_operacion) where estado <> 'anulado'`: el mismo
  número de operación de la misma cuenta no se registra dos veces (409 con el pago existente).
- `estado`: `pendiente_aprobacion` | `vigente` | `anulado` (D2).
- `anulado_at` (UTC), `anulado_por`, `motivo_anulacion`, `observaciones`, `user_id`, timestamps.

**`aplicaciones_pago_proveedor`**
- `pago_proveedor_id` (FK), `compra_id` (FK `compras`, **nullable**) con CHECK
  `num_nonnulls(compra_id) = 1`: T-143 agrega `obligacion_id`/`cuota_id` y cambia el CHECK en su
  propia migración.
- `monto` decimal(14,2) > 0, `user_id`, `desaplicada_at` (UTC), `desaplicada_por`,
  `motivo_desaplicacion`, timestamps. **Nunca se borra**: desaplicar es marcarla.

Modelos `App\Models\Compras\PagoProveedor` y `AplicacionPagoProveedor` (el nombre ya está libre
desde T-141).

### 3. Reglas (`App\Services\Compras\PagoProveedorService`, nuevo)
- **Saldo de una compra** = `total` − Σ `total` de NC (07) vigentes que la referencian (D4)
  − Σ aplicaciones no desaplicadas de pagos `vigente`. Todo con `BigDecimal`, un solo método
  (`saldos(array $compraIds)`, en lote, sin N+1) que usan la ficha, el listado y las validaciones.
- **Límite al aplicar:** además de lo anterior, se descuentan las aplicaciones de pagos
  `pendiente_aprobacion` (reservan saldo para que no se aplique dos veces lo mismo), pero no
  cambian el estado mostrado.
- **Estado derivado** (no se guarda): `pendiente` (nada aplicado), `parcial`, `pagado`
  (saldo ≤ 0), y `vencido` si saldo > 0 y el vencimiento es anterior a `HoraPeru::hoyTexto()`
  (vencimiento = `fecha_vencimiento`, o `fecha_emision` si es al contado). Si saldo < 0: `pagado`
  con «saldo a favor» (ver T-140). Las anuladas y las NC (07) no tienen estado de pago; la ND (08)
  se paga como cualquier compra.
- **Aplicar** (al crear el pago o después, desde lo «a cuenta»): misma `proveedor_id`, **misma
  moneda** (pagar una compra en USD con soles es T-140: 422 claro), compra vigente y no 07, monto ≤
  límite de la compra y ≤ disponible del pago (monto − aplicaciones no desaplicadas). Bloqueo:
  `lockForUpdate` del pago y de las compras involucradas, **en orden de id**.
- Aplicar a una compra de un **periodo cerrado** se permite: el pago no cambia el comprobante ni el
  Registro de Compras.
- **Desaplicar:** con motivo; el monto vuelve a quedar «a cuenta» del pago.
- **Origen Caja:** el egreso se registra en la sesión abierta del usuario (422 si no hay), tipo
  nuevo `pago_proveedor`, `reference_type='pago_proveedor'`, contraparte = el proveedor, con la
  validación de «no negativo» y la regla de aprobación de los egresos manuales (D2). Esa lógica hoy
  es privada de `CashMovementController` (`store()` ~línea 41, `validarNoNegativo()` ~línea 499):
  **extraerla** a un servicio de Caja (p. ej. `App\Services\Cash\EgresoCajaService`) que usen los
  dos, sin cambiar el comportamiento del egreso manual (sus tests siguen en verde). Fecha del pago =
  `HoraPeru::hoyTexto()`.
- **Origen Banco:** sin sesión de caja; cuenta bancaria activa y con la moneda del pago (si la cuenta
  no tiene moneda, 422 pidiendo completarla); `fecha` ≤ hoy en Perú; número de operación obligatorio.
- **Aprobación (D2):** `CashMovementController::approve()`/`reject()` (~líneas 191 y 210) aceptan
  también el tipo `pago_proveedor`. Aprobar → pago `vigente`. Rechazar → pago `anulado` (motivo «Egreso
  rechazado en Caja») y sus aplicaciones desaplicadas, en la misma transacción.
- Un movimiento `pago_proveedor` **no se edita ni se elimina desde Caja**
  (`prepararCorreccion()` ya rechaza lo que no es manual: cubrirlo con un test). Se corrige anulando
  el pago.
- **Anular un pago:** con motivo, `compras.anular`. Desaplica todas sus aplicaciones. Origen Caja:
  si el egreso está confirmado, `CashCorrectionService::revertirMovimiento()` (las reglas de sesión
  cerrada y sesión abierta de quien anula son las de ese servicio); si estaba pendiente de
  aprobación, el movimiento pasa a `rejected`. Origen Banco: solo se marca. Nunca se borra nada.
- **Compra (`CompraService`):**
  - `anular()` (~línea 82, donde está el comentario «T-136: aquí va…»): con aplicaciones no
    desaplicadas (de pagos vigentes o pendientes) → `CompraException` «Desaplica primero los pagos».
  - `guardar()`: con aplicaciones, no cambia proveedor ni moneda, y el total no baja de lo aplicado
    + NC (mismo estilo que `exigirSinReparto()`, ~línea 355).
  - Registrar una NC que deja saldo negativo en la compra original se permite (D4).
  - `registrar()` con «Pagada ahora» (D3): crea compra, pago y aplicación por el total en la misma
    transacción. Solo al registrar (no al editar), solo condición contado, no en 07, y solo si el
    usuario tiene `compras.pagar` (si no, la compra queda pendiente, sin error). Si el pago falla
    (sin sesión, caja en negativo), no se guarda nada.

### 4. API (todas en `modulo:compras`)
| Ruta | Permiso |
|---|---|
| `GET compras/pagos` (filtros: proveedor, fechas, origen, estado, «con saldo a cuenta») | `compras.ver` |
| `GET compras/pagos/{id}` (con aplicaciones) | `compras.ver` |
| `POST compras/pagos` (pago + aplicaciones opcionales) | `compras.pagar` |
| `POST compras/pagos/{id}/aplicaciones` | `compras.pagar` |
| `POST compras/aplicaciones/{id}/desaplicar` | `compras.pagar` |
| `POST compras/pagos/{id}/anular` | `compras.anular` |
| `GET proveedores/{id}/compras-pendientes` (saldo y estado; lo reusa T-163 en Caja) | `compras.pagar` |

- `GET compras` y `GET compras/{id}` agregan `saldo` y `estado_pago` (con `saldos()` en lote); la
  ficha agrega sus aplicaciones.
- `catalogos` agrega cuentas bancarias activas y métodos de pago de Caja.
- Permiso nuevo **`compras.pagar`** en una migración nueva (como
  `2026_11_28_100100_add_compras_permissions.php`), sembrado en `PermissionsDemoSeeder`;
  Propietario lo recibe con `roles:sync-propietario`. Ningún rol operativo lo recibe (igual que el
  resto de `compras.*`).

### 5. Pantallas
- **`compras/form.vue`** (condición ~línea 117): con contado y compra nueva, bloque **«Pagada
  ahora»** marcado por defecto: origen Caja (método de pago) o Banco (cuenta, número de operación,
  fecha). Oculto sin `compras.pagar`.
- **`compras/index.vue`** (tabla ~línea 51): columna **Pago** (badge pendiente / parcial / pagado /
  vencido, y el saldo).
- **Ficha de la compra** (donde se muestre hoy el detalle): sección **Pagos** con sus aplicaciones,
  «Registrar pago» (precarga el saldo), «Aplicar pago a cuenta» y «Desaplicar».
- **`compras/pagos.vue`** nueva, ítem de menú **«Pagos a proveedores»** bajo Compras
  (`MenuItemsSeeder`, con `modulo_id` de compras): listado, nuevo pago (elige proveedor → lista
  sus compras pendientes de la misma moneda con su saldo, para repartir el monto; lo que no se
  reparte queda a cuenta), anular, y una pestaña con `CuentasBancarias.vue`.
- `helpers/cash/tipoMovimiento.ts`: `pago_proveedor: 'Pago a proveedor'`.
- Estilo de Agencia/Ventas: inputs `-sm`, `card-header`, fechas con `CampoFecha.vue`.

## Fuera de alcance
- Detracción, retención de 4ta y el aviso al registrar un egreso a proveedor en Caja → **T-163**.
- Listado de cuentas por pagar por proveedor, por vencer en N días → T-137.
- Pagar en una moneda una compra de otra, diferencia de cambio, reembolso de una NC → T-140.
- Obligaciones y cuotas por reserva → T-142/T-143. Fondo a rendir → Rendiciones.
- Saldo de una cuenta bancaria, conciliación bancaria, extractos.
- Clasificar las tablas nuevas en `LimpiarOperacionPrueba`: va con la línea ya abierta en «Por
  analizar» sobre las tablas de Compras (agregar estas dos a esa línea).

## Dónde tocar
- `api-sistema-fe/database/migrations/tenant/core/` → 4 migraciones nuevas (cuentas al core,
  moneda de la cuenta, pagos + aplicaciones, permisos).
- `app/Models/CuentaBancaria.php` (movido), `app/Models/Compras/PagoProveedor.php`,
  `AplicacionPagoProveedor.php`; relación `pagos`/`aplicaciones` en `Compra.php`.
- `app/Services/Compras/PagoProveedorService.php` (nuevo); `CompraService::registrar()`,
  `guardar()` (~109), `anular()` (~65).
- `app/Services/Cash/EgresoCajaService.php` (nuevo, extraído de `CashMovementController::store()`
  y `validarNoNegativo()`); `CashMovementController::approve()`/`reject()`.
- `app/Http/Controllers/Compras/PagoProveedorController.php` (nuevo), `CompraController::index()`/
  `show()`/`catalogos()`; `app/Http/Controllers/CuentaBancariaController.php` (movido).
- `routes/api.php` → grupo `modulo:compras` (~línea 417) y rutas `cuentas-bancarias` (~línea 998).
- `admin-start-kit/src/views/compras/` (`form.vue`, `index.vue`, `pagos.vue`),
  `components/CuentasBancarias.vue`, `views/agencia-viajes/configuracion/index.vue`,
  `services/admin/cuentaBancariaService.ts`, `helpers/cash/tipoMovimiento.ts`, router.

Orden sugerido de commits: (1) cuentas bancarias al base; (2) tablas, modelos y servicio con sus
tests; (3) Caja: servicio de egreso extraído + aprobación; (4) reglas en `CompraService`;
(5) API; (6) pantallas.

## Criterios de aceptación
Tests en `tests/Feature/Compras/PagoProveedorTest.php` salvo que se indique otro.
- [ ] **CA1** — En un tenant de cada giro (retail, créditos, agencia **nueva**, donde corren `core/`
  y después el vertical) existe `cuentas_bancarias` con `moneda`, y en agencia-demo las cuentas existentes siguen en el PDF de cotización →
  `CuentasBancariasCoreTest` (como `ComprasMigracionesPorGiroTest`).
- [ ] **CA2** — Un rol con `agencia.configuracion` puede gestionar cuentas tras la migración; una
  cuenta con pagos no se borra (422) → `CuentasBancariasCoreTest`.
- [ ] **CA3** — Pago desde Caja con sesión abierta: crea el pago `vigente`, un `cash_movement`
  `pago_proveedor` de salida por el monto, con el proveedor como contraparte, y la caja esperada
  baja → `test_pago_desde_caja_registra_egreso`.
- [ ] **CA4** — Pago desde Caja sin sesión abierta → 422 y nada guardado.
- [ ] **CA5** — Pago desde Caja que dejaría la caja en negativo → 422 (misma regla que el egreso manual).
- [ ] **CA6** — Pago desde Banco sin sesión de caja: se guarda con cuenta y número de operación, sin
  `cash_movement`; el mismo número en la misma cuenta, vigente, → 409; cuenta sin moneda o de otra
  moneda → 422.
- [ ] **CA7** — Compra de 1,000 con pago de 400 aplicado → `parcial`, saldo 600; un segundo pago de
  600 → `pagado`; aplicar 1 más → 422.
- [ ] **CA8** — Pago de 1,500 aplicado 1,000 a una compra → quedan 500 a cuenta; después se aplican a
  otra compra del mismo proveedor. A una compra de **otro** proveedor o de otra moneda → 422.
- [ ] **CA9** — Compra a crédito con vencimiento ayer (hora Perú) y saldo → `vencido`; al contado
  sin pagar, con emisión anterior a hoy → `vencido`.
- [ ] **CA10** — NC vigente de 200 sobre una compra de 1,000 sin pagos → saldo 800 (D4); NC sobre
  una compra pagada → `pagado` con saldo a favor −200.
- [ ] **CA11** — Egreso sobre el umbral de aprobación: pago `pendiente_aprobacion`, la compra sigue
  `pendiente`, y otro pago no puede aplicar el mismo saldo; aprobar → `vigente` y la compra
  `pagado`; rechazar → pago `anulado` con aplicaciones desaplicadas (D2).
- [ ] **CA12** — Anular un pago de Caja confirmado: corrección con `CashCorrectionService` (el
  original queda marcado, no se borra), aplicaciones desaplicadas, la compra vuelve a `pendiente`.
  Anular un pago de Banco solo lo marca.
- [ ] **CA13** — Editar o eliminar desde Caja un movimiento `pago_proveedor` → 422.
- [ ] **CA14** — Anular una compra con pagos aplicados → error «Desaplica primero los pagos»;
  desaplicados, se anula. Editar una compra con pagos: cambiar proveedor o moneda, o bajar el total
  por debajo de lo aplicado → error.
- [ ] **CA15** — Registrar una compra al contado con «Pagada ahora» crea compra, pago y aplicación
  (`pagado`); si falla el pago (sin sesión de caja), no queda la compra (D3). Sin `compras.pagar`
  la compra se registra `pendiente`.
- [ ] **CA16** — Dos pagos simultáneos sobre el mismo saldo no lo superan (bloqueo en orden de id):
  test de las validaciones bajo bloqueo, como los de `CompraAsignacionTest`.
- [ ] **CA17** — Sin `compras.pagar` las rutas de escritura dan 403; sin el módulo `compras`
  encendido, 404/403 según el middleware `modulo:`.
- [ ] **CA18** — Los tests existentes de Caja (egreso manual, aprobación, corrección) siguen en
  verde tras extraer el servicio.
- [ ] **CA19** — Pantalla: en `agencia-demo` y en un tenant retail, registrar una compra al contado
  pagada desde Caja, otra a crédito pagada en dos partes por Banco, ver los badges en el listado,
  anular un pago y ver el egreso corregido en la sesión de caja (Playwright o a mano).

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: `compras.ver` / `compras.pagar` / `compras.anular` y
  `cuentas_bancarias.gestionar`; todas las de pagos en `modulo:compras`. Aprobar sigue en
  `cash.approve_expenses`.
- [ ] Aislamiento de tenant: tablas solo de tenant; sin consultas a central.
- [ ] Validación de entrada: FormRequest o `validate()` explícito; montos > 0 con dos decimales;
  origen contra `PagoProveedor::ORIGENES`; ids de compra, cuenta y método existentes y activos.
- [ ] Concurrencia: `lockForUpdate` del pago y las compras (orden de id) al aplicar, desaplicar y
  anular; la compra se bloquea también en `anular()`/`guardar()` como hoy.
- [ ] Dinero: `BigDecimal` en todo cálculo de saldo; un solo método de saldo.
- [ ] Fechas: `HoraPeru::hoyTexto()` para la fecha del pago de Caja, el tope del de Banco y el
  vencido; `anulado_at`/`desaplicada_at` en UTC.
- [ ] Tributario sin fallback silencioso: moneda distinta → 422, nunca convertir.
- [ ] Sin `v-html` sin sanitizar.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`; la única existente que se toca es la guarda
  `hasTable` de `cuentas_bancarias` del vertical (punto 1, precedente T-132).

## Cómo verificar
```
php artisan test --filter=PagoProveedorTest
php artisan test --filter=CuentasBancariasCoreTest
php artisan test --filter=Cash
php artisan test   # suite completa contra Postgres, al final
cd admin-start-kit && yarn type-check && yarn build && yarn vitest run
```
Prueba funcional: `agencia-demo` (admin@agencia-demo.test) y un tenant retail, módulo «Compras»
encendido en el Panel Superadmin; caja abierta con fondo; empresa con aprobación de egresos sobre
S/ 500 para probar CA11.

**Al desplegar:** `tenants:migrate-verticales`, `MenuItemsSeeder` y `roles:sync-propietario` (los
corre `deploy.sh`; revisar su salida).

---

## Resultado
<!-- Lo completa quien construye, al terminar. -->
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
<!-- Problemas vistos y NO arreglados. Se convierten en tareas o en "Por analizar". -->
- 
