# T-011 — Cobro al contado al facturar una reserva

| | |
|---|---|
| **Módulo** | AGE + CAJ |
| **Depende de** | T-010 (hecha: `CobroVentaService`) |
| **Reglas** | contrato de `CobroVentaService` (`docs/tareas/T-010-servicio-cobro-compartido.md`, no cambiarlo) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 1 (respuestas 1-5, 8-10; "El cobro al facturar una reserva"; R6) |
| **Rama** | `feat/T-011-cobro-contado-reserva` |
| **Implementa** | otro agente (Sonnet), en **3 partes con un commit cada una**. Análisis y revisión: Claude Opus, 29-oct-2026. Los tests los escribe Sonnet (ver "Por qué los tests no vienen escritos") |

## Contexto (verificado contra el código el 29-oct-2026)
La venta que sale de una reserva nace "pendiente de pago" y sin pagos aunque sea al contado: no
hay control de caja. El usuario confirmó (25-oct): al contado **se cobra en el momento**, puede
ser mixto, el n.º de operación es opcional, sin caja abierta se bloquea, hay vuelto (poco), el
tipo de cambio lo pone cualquier cajero.

`ReservaFacturacionController::store()` (~328):
- Valida (~345), resuelve selección, guardia tributaria, permisos, cliente, moneda
  (`resolverMonedaFacturacion()`), serie — todo **antes** de la transacción.
- Dentro de `DB::transaction` (~468): `lockForUpdate()` de la reserva (~492), crea la venta con
  `state_payment=1`, `debt=total`, `paid_out=0`, `saldo_pendiente=total` (~610), las líneas,
  **aplica anticipos** (~683; si aplican, `total`/`debt`/`saldo_pendiente` quedan en lo que falta
  y `paid_out` = lo aplicado, ~690) y después la rama **crédito** (~709).
- `catch (HttpException $e) { throw $e; }` (~748): una 422 lanzada dentro de la transacción
  hace rollback y llega como 422. Mantenerlo.
- Respuesta: `'Reserva facturada correctamente. La venta queda pendiente de cobro.'` (~756).

`prepararFactura()` (~170) ya devuelve `saldo_a_cobrar` (total − anticipos que se aplicarían)
con la misma regla que `store()`.

## Diseño (decidido)
**Antes de la transacción** (no depende del total):
- Campos nuevos: `pagos` (array, opcional) con `method_payment`, `moneda_pago`, `monto_pagado`,
  `tipo_cambio` (si la moneda difiere), `nro_operacion`; y `vuelto_moneda` (PEN/USD, opcional).
- `condicion_pago='credito'` con `pagos` no vacío → 422 `'A crédito no se cobra al facturar: el cobro se registra en Cuentas por Cobrar.'`.
- Con `pagos`: `$cobro->prepararPagos($pagos, $moneda)` (moneda de facturación) y
  `$cobro->sesionAbierta($usuario)`.

**Dentro de la transacción**, después de aplicar anticipos y antes de la rama crédito, solo al contado:
- `$saldo = round((float) $venta->total, 2)` (ya neto de anticipos).
- Saldo > 0 y sin pagos → 422 `"Al contado registra el cobro: falta {S/|US$} X."`.
- Saldo = 0 y con pagos → 422 `'Los anticipos cubren todo el comprobante: no hay nada que cobrar.'`.
- Saldo > 0 con pagos → `$vuelto = $cobro->validarContraSaldo($pagos, $saldo, $moneda, exacto: true, monedaVuelto: …)`
  (si no calza, su 422 ya dice cuánto se cobró y cuánto era: es el "el saldo cambió" de R6),
  `$cobro->registrar($venta, $pagos, $sesion, $usuario, $vuelto)` y la venta queda
  `state_payment=3`, `debt=0`, `saldo_pendiente=0`, `paid_out = paid_out + $saldo`.
- Respuesta cuando hubo cobro: `'Reserva facturada y cobrada.'` (sin cobro, el mensaje de hoy).

**Interruptor** `config('agencia.cobro_al_facturar')` (archivo nuevo `config/agencia.php`, env
`AGENCIA_COBRO_AL_FACTURAR`, **default `true`**). En `false`, `store()` se comporta exactamente
como hoy (sin pagos; si llegan, 422 `'El cobro al facturar todavía no está habilitado.'`) y
`prepararFactura()` informa `cobro_habilitado: false` para que el modal no muestre el bloque.
**Motivo:** el cobro en dólares no se despliega sin la caja por moneda (T-013); producción
lo deja en `false` hasta entonces sin frenar otros despliegues. Documentarlo en `.env.example`.

**`prepararFactura()`** agrega: `cobro_habilitado` (bool), `caja_abierta` (bool, del usuario),
`formas_pago` (activas: `code`, `name`, `monedas`, `affects_cash_count`, ordenadas por `sort_order`),
`tipo_cambio_dia` (`TipoCambioSunatResolver::resolverParaFecha(HoraPeru::ahora())?->venta`, o null).

## Por qué los tests no vienen escritos
Armar una reserva facturable ocupa ~200 líneas de helpers privados en `ReservaFacturacionTest`.
Y el cambio rompe a propósito los tests que facturan al contado sin cobro: **43 llamadas a
`store()` en 6 archivos** (`ReservaFacturacionTest`, `Fila13Fase1cFacturacionTest`,
`ReservaAnticipoTest`, `ReservaEstadoFacturacionTest`, `ReservaPolicyAccionesTest`,
`Fila13Fase1bTourCotizacionTest`). Por eso la parte 1 es el arreglo de esos tests.

## Antes de empezar (obligatorio)
1. Leer `CLAUDE.md` (raíz), `docs/proceso.md`, esta tarea y la de T-010 (contrato del servicio).
2. `git checkout main && git checkout -b feat/T-011-cobro-contado-reserva`
3. **No correr** `composer install`/`composer update`. No correr dos `php artisan test` completos a la vez.
4. Fechas: solo `HoraPeru`; nunca `now()`/`today()` para fechas de negocio (también en tests de
   tarifas: `vigente_desde` con `HoraPeru::hoyTexto()`, ver `c02f217`).
5. No hay migraciones en esta tarea.

## Parte 1 — backend + tests existentes al día (commit 1)
1. `config/agencia.php` + `.env.example`.
2. `ReservaFacturacionController::store()` y `prepararFactura()` según el diseño. Inyectar
   `CobroVentaService` en el constructor.
3. **Trait de test** `tests/Feature/AgenciaViajes/Concerns/FacturaCobrando.php` con
   `facturarCobrando(Request $pedido, string $reservaId)`: si el pedido es crédito o ya trae
   `pagos`, llama a `store()` tal cual. Si no: llama a `prepararFactura()` con los mismos datos; si
   responde 200 y `saldo_a_cobrar > 0`, asegura una caja abierta del usuario autenticado (crear
   `CashRegister` en su `branch_id` + `CashSession` abierta, como `ReservaFacturacionTest` ~730) y
   agrega `pagos = [['method_payment' => 'EFECTIVO', 'moneda_pago' => <moneda de facturación>, 'monto_pagado' => saldo]]`;
   después llama a `store()`. Si `prepararFactura()` falla, llama a `store()` tal cual (así los
   tests que esperan un 422 lo siguen recibiendo de `store()`).
4. En los 6 archivos, cambiar las llamadas a `store()` que facturan al contado por
   `facturarCobrando(...)`. **No cambiar ninguna aserción**: si un test deja de pasar por otra
   razón que el cobro, parar y anotarlo en "Desviaciones".
5. Verificar con `php artisan test --filter="Reserva|Fila13|Cobro"` y la suite completa.
   Commit: `feat(agencia): cobro al contado al facturar una reserva (T-011)`.

## Parte 2 — tests nuevos (commit 2)
Archivo nuevo `tests/Feature/AgenciaViajes/T011CobroFacturarReservaTest.php`, copiando de
`ReservaFacturacionTest` el `setUp()`/`tearDown()`, `setUpTenantFixture()`, `usuarioConPermisos()`,
`branchConSerie()`, `serieAdicionalMoneda()` y `crearReservaConUnPasajeroYUnItemEnMoneda()` (un
pasajero, un ítem de precio conocido: facil de cuadrar el saldo). Casos:
- **CA1** contado, saldo S/ 500, efectivo S/ 500 → venta `state_payment=3`, `debt=0`,
  `paid_out=500`, 1 `sale_payments`, 1 movimiento `sale_payment` en la caja del usuario, mensaje "facturada y cobrada".
- **CA2** mixto S/ 200 efectivo + S/ 300 Yape con `nro_operacion='987654'` → 2 pagos, 2 movimientos, n.º guardado.
- **CA3** venta en USD 1,000 pagada con US$ 600 + S/ 1,500 a 3.75 → `sale_payments.amount` 600 y
  400; movimientos US$ 600 en `USD` y S/ 1,500 en `PEN`.
- **CA4** lo cobrado no cubre el saldo → 422 y **no existe** venta, `sale_payments` ni movimientos
  de esa reserva (el rollback borra todo).
- **CA5** sin caja abierta → 422 "Abre tu caja para cobrar" y no se emite nada.
- **CA6** anticipo que cubre todo el saldo → se emite sin pagos y sin caja; y con pagos → 422 "no hay nada que cobrar".
- **CA7** crédito con `pagos` → 422; crédito sin pagos, igual que hoy.
- **CA8** vuelto: venta S/ 3,700 pagada con US$ 1,000 a 3.75 en efectivo, `vuelto_moneda='PEN'`
  → `sales.vuelto_monto=50`, movimiento `sale_change` de salida S/ 50.
- **CA9** contado, saldo > 0 y sin `pagos` → 422 "Al contado registra el cobro".
- **CA10** interruptor en `false` (`config(['agencia.cobro_al_facturar' => false])`): sin pagos
  emite como hoy (pendiente de cobro, sin movimientos); con pagos → 422; `prepararFactura()`
  devuelve `cobro_habilitado=false`.
- `prepararFactura()` devuelve `formas_pago` (Yape solo `PEN`), `caja_abierta` y `tipo_cambio_dia`.
Commit: `test(agencia): cobro al facturar una reserva (T-011)`.

## Parte 3 — pantallas (commit 3)
- Componente nuevo `components/AgenciaViajes/CobroFacturaCampos.vue`, usado por
  `FacturarReservaSimpleModal.vue` y `FacturarReservaEspecialModal.vue` (mismo patrón que
  `CondicionPagoCampos.vue`), visible solo si `cobro_habilitado` y la condición es contado y el
  saldo a cobrar > 0:
  - Filas: forma de pago (solo las que aceptan la moneda elegida), moneda (soles/dólares),
    monto, tipo de cambio (solo si la moneda difiere de la de la venta; precargado con
    `tipo_cambio_dia`, editable), n.º de operación (opcional). "Agregar pago" y quitar fila.
  - Al pie, en vivo: equivalente de cada fila, **total cobrado**, **falta** o **vuelto** (con
    selector de moneda del vuelto si hay efectivo en dólares). El cálculo en el navegador es solo
    para mostrar: el backend decide. Misma fórmula: soles por dólar.
  - Aviso amarillo (sin bloquear) si el tipo de cambio se aleja más de 3% de `tipo_cambio_dia`.
  - Sin caja abierta (`caja_abierta=false`): aviso "Abre tu caja para cobrar" con enlace a la
    pantalla de Caja, y el botón de emitir deshabilitado.
  - Al abrir el modal, una fila de efectivo en la moneda de la venta por el saldo completo (el caso más común).
- El modal envía `pagos` y `vuelto_moneda` en el payload (`reservaFacturacionService.facturar()`,
  tipos en `FacturarReservaPayload`).
- Estilo de Ventas/Agencia (inputs `-sm`, `card-header`); ver `feedback_estilo_ui_como_agencia_y_ventas`.
- `npm run type-check` (46 preexistentes; ninguno nuevo) y `npm run build`.
Commit: `feat(agencia): bloque de cobro en los modales de facturar (T-011)`.

## Fuera de alcance (no tocar)
- Traspaso del cobro tras una NC (T-012). Caja por moneda (T-013). PDF (T-014).
- Venta Directa "Crear y cobrar" (T-048, usará este mismo cobro).
- Ventas ya emitidas pendientes (R3: no se hace nada).
- El contrato de `CobroVentaService`: si hace falta algo más, anotarlo en "Desviaciones".

## Criterios de aceptación
- [ ] **CA1-CA10** — tests de la parte 2.
- [ ] **CA11** — los tests existentes de facturación siguen en verde **sin cambiar aserciones**, solo cobrando.
- [ ] **CA12** — Prueba funcional en `agencia-demo` (la hace Opus): facturar simple y especial al
  contado con pago mixto, ver los movimientos en la sesión de caja.

## Seguridad y correctitud
- [ ] Permisos: sin rutas nuevas; `ReservaPolicy` ya aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: `pagos[]` con reglas explícitas en el validador (array, campos tipados) + `prepararPagos()`
- [ ] Concurrencia: la comparación con el saldo va bajo el `lockForUpdate()` de la reserva
- [ ] Dinero: comparar en centavos; conversión solo en `CobroVentaService`
- [ ] Fechas con `HoraPeru`: `date_payment` por defecto del servicio
- [ ] Tributario: no aplica (la moneda del comprobante no cambia por cómo paga el cliente)
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
cd api-sistema-fe
php artisan test --filter="Reserva|Fila13|Cobro"
php artisan test        # suite completa; intermitente conocido: Fila13Fase1cFacturacionTest::descuento_del_combo… (ver backlog)
cd ../admin-start-kit && npm run type-check && npm run build
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
