# Observaciones de las pruebas del usuario

> **Qué es:** el requerimiento vivo con lo que el usuario encuentra al probar el sistema
> (problemas y mejoras), con su número, ordenado por módulo y funcionalidad. Cada punto
> lleva lo que hay hoy (verificado en el código), el análisis y las preguntas abiertas.
> Es la etapa 1-2 de `docs/proceso.md`. **El estado de cada punto está solo en
> `docs/backlog.md`** ("Por analizar" mientras se analiza; tarea `T-…` cuando se aprueba).
> Los números son los del usuario y no se reusan.

**Índice por módulo**

| N.º | Módulo | Funcionalidad | Resumen |
|---|---|---|---|
| 1 | AGE + CAJ | Facturar reserva · cobro | Al contado: forma de pago (efectivo, Yape, Plin…) y n.º de operación, para controlar caja |
| 2 | VEN | PDF del comprobante | Mostrar condición, forma de pago y n.º de operación |
| 3 | AGE | Vuelos · aerolíneas | Catálogo de aerolíneas con buscador; aerolínea por defecto en la pestaña Pasajeros |
| 4 | AGE | Reserva · pasajeros | El cliente que cotiza como primer pasajero por defecto (editable) |
| 5 | AGE | Reserva · pasajeros | "Buscar pasajero" también encuentra a los clientes registrados |

---

## AGE + CAJ — Facturar reserva: el cobro

### 1. Al contado: forma de pago y número de operación

**Lo que pide el usuario (24-oct-2026):** cuando la condición es al contado, falta la forma de
pago con la que se controla caja (efectivo, Yape, Plin, transferencia, tarjeta, etc.) y el
número de operación.

**Lo que hay hoy (verificado):**
- La venta que sale de una reserva nace **pendiente de cobro, sin ningún pago** (decisión de
  agosto: el cobro se hacía después). Al contado o a crédito, no se registra cómo pagó el
  cliente.
- **Ventas (retail) sí pide los pagos** al registrar: método, monto y fecha (`sale_payments`), y
  cada pago inmediato genera su movimiento de caja. Por eso Caja **exige una sesión de caja
  abierta** del usuario que cobra (`SaleController`, regla de Caja fase 3).
- **`sale_payments` no tiene campo para el número de operación** (solo método, monto, fecha y
  comentario). Donde sí existe es en los cobros de Cuentas por Cobrar
  (`payment_receipts.nro_operacion`).
- Formas de pago del catálogo de Caja (`payment_methods`, configurable): Efectivo (el único que
  cuenta en el arqueo de caja), Transferencia, Yape, Plin, Tarjeta de crédito.

**Propuesta:**
- Al elegir **Contado** en "Facturar" o "Facturación especial", un bloque **"Cobro"**: uno o
  varios pagos (forma de pago + monto + n.º de operación), igual que Ventas. Al emitir se guardan
  como `sale_payments` y generan su movimiento en la caja del usuario.
- Columna nueva **`sale_payments.nro_operacion`** (sirve también para Ventas de todos los giros).
- El monto que se cobra es el **saldo a cobrar** (total menos anticipos), que la vista previa ya
  calcula.

**Respuestas del usuario (25-oct-2026):**
1. Al contado **se cobra en el momento**: el pago es obligatorio.
2. **Sí hay pago mixto** en Agencia (montos grandes): varios pagos en un mismo comprobante.
3. El n.º de operación **no es obligatorio** (tampoco en Yape, Plin, transferencia o tarjeta).
4. **Sin caja abierta se bloquea**: "Abre tu caja para cobrar".
5. **Sí en Ventas (retail)**: el n.º de operación se agrega también al registrar una venta.

**Más respuestas del usuario (25-oct-2026), tras la revisión de fallos (abajo):**
6. **Corregir una factura con NC:** el dinero ya cobrado **se traspasa al comprobante nuevo**.
7. **Dólares:** la caja tiene que manejar **las dos cosas**: a veces los dólares quedan aparte, a
   veces se cambian a soles.
8. **Vuelto:** pasa, pero poco.
9. **Tipo de cambio:** lo puede poner **cualquier cajero**.
10. **Tarjeta y transferencia:** hay **una cuenta por moneda**, así que pueden entrar en soles o
    en dólares.
11. **Ventas de reserva ya emitidas sin cobro:** son datos de prueba; no se hace nada (ver R3).

**Diseño (a aprobar):**

*El cobro al facturar una reserva*
- Al contado, el modal de facturar (simple y especial) muestra el bloque **"Cobro"**: filas de
  forma de pago + moneda + monto + n.º de operación (opcional), con "Agregar pago". Si la moneda
  del pago no es la de la venta, la fila pide el **tipo de cambio** (se propone el del día del
  módulo de tipo de cambio; el cajero lo puede cambiar; si se aleja mucho del oficial sale un
  aviso, sin bloquear) y muestra cuánto equivale en la moneda de la venta.
- **Lo cobrado (en equivalente) tiene que cubrir exactamente el saldo a cobrar** (total menos
  anticipos aplicados), con una sola excepción: el **vuelto**. Si se paga de más y hay al menos
  un pago en efectivo, el exceso se registra como vuelto, en la moneda que elija el cajero, y
  sale de la caja como movimiento propio. El vuelto nunca puede ser mayor que lo pagado en
  efectivo.
- Si el saldo es 0 (todo cubierto por anticipos) no se piden pagos ni caja.
- La comprobación del saldo se hace **al emitir, con la reserva bloqueada** (el total
  definitivo se calcula ahí), redondeando a 2 decimales. Si no coincide con lo cobrado: 422 "el
  saldo cambió, revisa el cobro", y no se emite nada.
- Validado en el backend: forma de pago activa y permitida en esa moneda, tipo de cambio > 0,
  sesión de caja abierta del usuario (si no, 422 "Abre tu caja para cobrar").
- Al emitir, en la misma transacción: `sale_payments` + un movimiento de caja por pago (y uno de
  salida por el vuelto). La venta queda **pagada** (`state_payment`, `paid_out`, `debt`).
- A crédito no cambia nada (sin cobro inicial, no pide caja).
- Facturar emite un comprobante por llamada, así que en la facturación múltiple cada
  comprobante lleva su propio cobro.

*Cómo se guarda un pago*
- `sale_payments` gana: `nro_operacion` (opcional), `moneda_pago`, `monto_pagado` (en la moneda
  del pago) y `tipo_cambio`. **`amount` sigue siendo el monto en la moneda de la venta**: todo lo
  que hoy lee `amount` (totales pagados, PDF, Cuentas por Cobrar) sigue funcionando igual. Los
  pagos ya guardados quedan con `moneda_pago` = moneda de la venta, `monto_pagado` = `amount` y
  `tipo_cambio` = 1.
- El vuelto se guarda en la venta (`vuelto_monto`, `vuelto_moneda`).
- `payment_methods` gana las monedas que acepta cada forma de pago: Efectivo, Transferencia y
  Tarjeta en soles y dólares; Yape y Plin solo soles. Configurable.
- Guardar pagos y movimientos de caja sale de `SaleController` (hoy métodos privados) a un
  **servicio compartido**, para que Ventas y Agencia usen la misma regla.
- Ventas (retail): por ahora solo gana el campo **n.º de operación** en pantalla. Al editar una
  venta, el n.º de operación se reenvía y se conserva (hoy editar borra y recrea los pagos, ver
  R5).

*Traspaso del cobro tras una NC (respuesta 6)*
- Al facturar servicios de una reserva que una NC liberó, el bloque "Cobro" ofrece **"Usar lo
  cobrado en F001-23 (disponible S/ 500.00)"** en vez de pedir dinero nuevo.
- Disponible para traspasar = lo pagado en el comprobante anulado, hasta el monto de sus NC
  aceptadas, menos lo ya traspasado. Solo dentro de la misma reserva y la misma moneda; el
  cliente puede ser otro (el caso típico es corregir el RUC).
- El traspaso se guarda como pago del comprobante nuevo con origen "traspaso" (y de qué venta
  viene), **sin movimiento de caja**: el dinero ya entró una vez. Se bloquea la fila de la venta
  original al calcular el disponible (dos personas no pueden usar el mismo saldo).

*Eliminar una venta (R1)*
- Al eliminar una venta todavía no enviada a SUNAT, sus movimientos de caja se anulan con
  movimientos de corrección (`CashCorrectionService`), con las mismas reglas que ya usa editar los
  pagos de una venta (sesión abierta de quien elimina; permiso de supervisor si la sesión
  original ya cerró). Vale para todos los giros: hoy en retail ya pasa.

**Relacionado:** "Cobrar y emitir en el mismo paso" de Venta Directa (bloque 3 de la hoja de
ruta de Agencia).

### Caja en dos monedas (sale del punto 1)

**Lo que hay hoy (verificado):** la caja no guarda la moneda en ningún lado (apertura,
movimientos, totales por forma de pago, conteo de billetes, cierre). Todo se suma como un solo
dinero. Los anticipos en dólares **ya entran hoy** a la caja como si fueran soles. Escriben en
la caja 7 lugares: ventas, anticipos, Cuentas por Cobrar, Créditos, movimientos manuales,
correcciones y la apertura; la leen unas 15 piezas (cierre, arqueo, reportes PDF/Excel, panel,
historial).

**Diseño (a aprobar), en dos pasos:**
- **Paso A — la moneda en cada movimiento.** `cash_movements.currency` (PEN por defecto); los 7
  lugares que escriben la llenan con la moneda real del dinero. Los movimientos ya guardados se
  completan con la moneda del documento al que pertenecen (venta, anticipo; créditos y manuales
  en soles), con registro. No cambia nada en pantalla: solo deja de empeorar el dato.
- **Paso B — la caja por moneda.** Apertura, esperado, contado y diferencia **por moneda**; el
  arqueo cuenta billetes de soles y de dólares por separado; totales por forma de pago y moneda;
  reportes, panel e historial separan S/ y US$.
- **Cambio de moneda** (paso B): movimiento de caja que saca una moneda y mete la otra con su tipo
  de cambio (por ejemplo, sale US$ 1,000 y entra S/ 3,740), para cuando la agencia cambia los
  dólares juntados. Mismo permiso que los movimientos manuales.
- **Regla de salida:** el cobro en dólares puede construirse y probarse antes, pero **no se
  despliega a producción sin el paso B**.

### Revisión de fallos (25-oct-2026)

Revisado contra el código, buscando lo que podría interrumpir un proceso que ya funciona:

| # | Riesgo | Dónde | Cómo queda |
|---|---|---|---|
| R1 | Eliminar una venta no anula sus movimientos de caja: la caja espera un dinero que ya no está | `SaleController::destroy()` | Corrección al eliminar (arriba). Ya pasa hoy en retail |
| R2 | Corregir con NC y volver a facturar cobraría dos veces | `ReservaVenta::liberarPorNotaDeCredito()` no toca la caja | Traspaso del cobro (respuesta 6) |
| R3 | Ventas de reserva al contado ya emitidas quedan "Pendiente" sin forma de cobrarse (Ventas no las deja editar desde F5) | — | No se hace nada (datos de prueba). Antes de desplegar, contar en producción las ventas de reserva al contado; si hay alguna, se ve ahí |
| R4 | La caja suma soles y dólares como un solo dinero | `cash_movements` sin moneda | Caja en dos monedas (arriba) |
| R5 | Editar una venta borra y recrea los pagos: perdería el n.º de operación | `SaleController::update()` | Se reenvía y se conserva |
| R6 | El saldo puede cambiar entre la vista previa y la emisión | `ReservaFacturacionController::store()` | Se compara al emitir, bajo el bloqueo |
| R7 | Si se agregara la moneda reemplazando el significado de `sale_payments.amount`, se romperían los totales pagados, el PDF y Cuentas por Cobrar | lectores de `amount` | `amount` sigue en la moneda de la venta; lo pagado en otra moneda va en columnas nuevas |

**Fuera de alcance, para "Por analizar":** devolver dinero al cliente por una NC **sin** volver a
facturar (hoy la NC no mueve la caja en ningún giro).

---

## VEN — PDF del comprobante (todos los giros)

### 2. Condición, forma de pago y número de operación en el PDF

**Lo que pide el usuario (24-oct-2026):** la representación impresa debe mostrar la condición,
la forma de pago y el número de operación.

**Lo que hay hoy (verificado):**
- **Condición de pago:** ya sale en el PDF A4 y ticket desde la fila F5 (24-oct-2026): "Contado",
  o "Crédito" con sus cuotas y el vencimiento de la última.
- **Forma de pago y n.º de operación:** no salen. El PDF solo muestra los totales "Pagado" y
  "Saldo".

**Propuesta:** en el A4 y en el ticket, debajo de la condición: **"Forma de pago: Yape S/ 300.00
(op. 123456) · Efectivo S/ 200.00"**, leído de `sale_payments`. Si no hay pagos registrados, no
sale la línea.

**Depende de:** el punto 1 (el campo `nro_operacion`).

---

## AGE — Vuelos: aerolíneas

### 3. Catálogo de aerolíneas con buscador, y aerolínea por defecto en Pasajeros

**Lo que pide el usuario (24-oct-2026):** al agregar un vuelo, la aerolínea se escribe en un
campo libre; propone guardar las aerolíneas y que el campo funcione como buscador que sugiere
mientras se escribe. Ese dato podría cargarse por defecto en la pestaña Pasajeros de la reserva.

**Lo que hay hoy (verificado):**
- La aerolínea es **texto libre en varios lugares**: el pasaje aéreo suelto del cotizador
  (`cotizacion_pasaje_aereo.aerolinea`), el vuelo propio del pasajero
  (`reserva_pasajeros.vuelo_aerolinea_ida` / `_vuelta`), el vuelo de la opción de mayorista
  (`vuelo_aerolinea`) y el vuelo confirmado (`vuelo_aerolinea_confirmada`, en tres tablas).
- **Datos reales inconsistentes** en agencia-demo: la misma aerolínea guardada como "Latam",
  "LATAM" y "latam". Eso impide buscar o agrupar por aerolínea.

**Propuesta:**
- Un **catálogo de aerolíneas** (nombre, código IATA opcional, activa) que nace con las más
  usadas en Perú (LATAM, Sky, JetSMART, Star Perú, ATSA…) y crece solo: si se escribe una que no
  está, se ofrece agregarla.
- Un **componente buscador** (sugiere mientras se escribe) que reemplaza el campo libre en todos
  esos lugares, guardando siempre el nombre tal como está en el catálogo.
- Normalizar los datos ya guardados ("latam" → "LATAM"), con registro y reversible.
- **Por defecto en Pasajeros:** si la reserva tiene un pasaje aéreo, su aerolínea se propone en
  el vuelo de cada pasajero.

**Respuestas del usuario (25-oct-2026):**
1. **Un catálogo común** para todas las agencias. Si una agencia escribe una que no está, **se
   agrega para todos** (sin distinguir mayúsculas ni espacios: "latam" encuentra "LATAM"); el
   Super-Admin puede corregir, unir o desactivar desde el panel central.
2. El valor por defecto va en el **vuelo vendido por la agencia**.

**Diseño que sale de las respuestas (a aprobar):**
- Tabla **central** `aerolineas` (nombre único sin distinguir mayúsculas, código IATA opcional,
  activa, tenant que la creó), con seeder inicial. Modelo con `CentralConnection`.
- API: buscar (cualquier usuario de Agencia) y agregar (con permiso de editar cotizaciones o
  reservas); si el nombre ya existe, devuelve el existente en vez de duplicar.
- Componente buscador (`AerolineaSelect`) en los lugares de texto libre; guarda el nombre tal
  como está en el catálogo (las columnas siguen siendo texto: no hay FK entre bases).
- Normalizar los datos ya guardados en cada tenant, con tabla de registro y reversible.
- Pasajeros: en el vuelo de agencia, la aerolínea del pasaje cotizado **se carga como valor**
  (hoy solo aparece como texto gris de ayuda y el campo queda vacío); se puede cambiar.
- Panel central: lista de aerolíneas con editar, unir y desactivar. **Unir** también corrige el
  nombre ya guardado en cada agencia (las columnas son texto, no se actualizan solas).
- El buscador y el alta responden solo a tenants de Agencia. Índice único sin mayúsculas ni
  espacios: si dos agencias agregan la misma a la vez, la segunda recibe la existente.
- Aceptado: cada agencia ve los nombres que agregaron las otras.

**Despliegue (revisión de fallos):** `deploy.sh` corre las migraciones centrales, después las de
los tenants y recién al final los seeders, y sigue aunque fallen las de los tenants. Por eso:
- La lista inicial de aerolíneas se carga **dentro de la migración central**, no en un seeder.
- La corrección de los nombres ya guardados es un **comando aparte** (`--simular` primero), no
  una migración de tenant: así no corre con el catálogo vacío ni falla en silencio.

---

## AGE — Reserva: pasajeros

### 4. El cliente que cotiza como primer pasajero por defecto

**Lo que pide el usuario (24-oct-2026):** en la pestaña Pasajeros, que venga por defecto el
nombre del cliente que cotiza, pero que se pueda cambiar, para ahorrar tiempo de ingreso.

**Lo que hay hoy (verificado):** al aceptar la cotización, los pasajeros de la reserva nacen
**sin datos** (solo adulto/niño/infante y la residencia); todo se escribe a mano.

**Propuesta:** al aceptar, si el cliente de la cotización es una **persona** (DNI, carné de
extranjería o pasaporte), su nombre y documento se cargan en el **primer pasajero adulto**, y se
pueden cambiar. Si el cliente es una **empresa (RUC)**, no se carga nada (la empresa no viaja).

**Respuesta del usuario (25-oct-2026):** que se cargue **al aceptar** la cotización.

**Diseño (a aprobar):** al aceptar, si el cliente es persona, se copian nombre y documento al
primer pasajero adulto y se vincula con su ficha del catálogo de pasajeros
(`pasajeros_catalogo.cliente_id`, la misma ficha que usa el punto 5). Solo hacia adelante: no
toca reservas ya aceptadas. No se carga nada si:
- el cliente es RUC;
- el cliente no tiene documento real (el alta de clientes guarda `00000000` cuando falta,
  `ClienteRequest`);
- el vendedor ya eligió a alguien del catálogo para ese lugar al aceptar
  (`pasajero_catalogo_ids`, por posición): lo elegido manda;
- no hay ningún pasajero adulto.

El tipo de documento del cliente se traduce al del pasajero (`dni`, `carne_extranjeria`,
`pasaporte`). **Solo al aceptar una cotización:** Venta Directa crea su reserva por el mismo
camino (`crearReservaDesdeAlternativa()`), pero queda fuera hasta afinar su diseño, así que el
dato se pasa solo desde `aceptar()`, sin tocar la parte común.

### 5. "Buscar pasajero" también encuentra a los clientes

**Lo que pide el usuario (24-oct-2026):** "Buscar pasajero (DNI o nombre)" no encuentra a las
personas registradas en Clientes; propone buscar en las dos tablas y, si no aparece, agregarlo.

**Lo que hay hoy (verificado):**
- El buscador (`ReservaPasajeroController::buscarCatalogo()`) busca **solo en el catálogo de
  pasajeros** (`pasajeros_catalogo`, por nombre o documento). En agencia-demo hay 7 pasajeros
  contra 18 clientes.
- El catálogo ya tiene un vínculo con Clientes (`pasajeros_catalogo.cliente_id`), pero **no se usa**
  (0 pasajeros vinculados).

**Propuesta:**
- El buscador mira **pasajeros y clientes** a la vez; cada resultado dice de dónde viene
  ("Pasajero" o "Cliente").
- Al elegir un cliente que todavía no es pasajero, se crea su ficha de pasajero **vinculada al
  cliente** (`cliente_id`), así no se duplica la persona.
- Los clientes empresa (RUC) no aparecen (no viajan).
- Si no aparece en ninguno, se escribe a mano como hoy y queda en el catálogo de pasajeros.

**Preguntas para el usuario:** ninguna por ahora (si quieres que un cliente empresa sí aparezca
por algún caso, avísame).

---

## Orden de construcción propuesto (a aprobar)

Cada línea será una tarea `T-…` al aprobarse el diseño. El orden sigue las dependencias reales:

1. **VEN + CAJ** — Eliminar una venta anula sus movimientos de caja (R1, todos los giros).
2. **CAJ** — Caja, paso A: moneda en cada movimiento + completar los ya guardados.
3. **VEN + CAJ** — Servicio compartido de cobro; `sale_payments` con n.º de operación, moneda de
   pago, monto pagado y tipo de cambio; monedas por forma de pago; n.º de operación en Ventas.
4. **AGE** — Cobro al contado al facturar una reserva (con tipo de cambio y vuelto). Depende de 3.
5. **AGE** — Traspaso del cobro tras una NC. Depende de 4.
6. **CAJ** — Caja, paso B: apertura, arqueo, cierre y reportes por moneda + cambio de moneda.
   **Requisito para desplegar 4 con cobros en dólares.**
7. **VEN** — Forma de pago y n.º de operación en el PDF (punto 2). Depende de 3.
8. **PLA + AGE** — Catálogo central de aerolíneas + lista en el panel central (punto 3).
9. **AGE** — Buscador de aerolíneas, corrección de nombres guardados y aerolínea por defecto en
   el vuelo de agencia (punto 3). Depende de 8.
10. **AGE** — El cliente como primer pasajero al aceptar (punto 4).
11. **AGE** — "Buscar pasajero" también en Clientes (punto 5). Comparte la vinculación de 10.
