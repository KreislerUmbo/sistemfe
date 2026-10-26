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
| 6 | AGE | Cotizador · comparar hoteles | Empezar la comparación con 1 habitación (hoy pide 2 en el primer hotel) |
| 7 | AGE | PDF de cotización | Fotos de los hoteles (fachada + habitaciones), con comparativa o con un solo hotel |
| 8 | AGE | Cotizador · envío | Revisión antes de enviar: lista de pendientes que lleva a donde se corrige |

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

## AGE — Cotizador: comparar hoteles

### 6. Comparar el mismo tipo de habitación en varios hoteles

**Lo que pide el usuario (26-oct-2026):** enviar la comparativa de 2 alojamientos en la matriz,
los dos con habitación matrimonial.

**Lo que hay hoy (verificado en el código, sin probar en pantalla):**
- En Local/Nacional, la comparación se arma hotel por hotel ("Comparar varias opciones" en
  `HabitacionMatrixPicker.vue`). Para **el primer hotel** el botón exige marcar **al menos 2
  habitaciones** si el hotel tiene más de un tipo cargado (`editar.vue`,
  `:minimo-grupo="… tarifas.length <= 1 ? 1 : 2"`). Del segundo hotel en adelante basta 1.
- Resultado: comparar "matrimonial del Hotel A vs. matrimonial del Hotel B" obliga a agregar una
  habitación de más en el Hotel A y quitarla después con la ✕ de su fila en el lienzo.
- En mayorista (Internacional) no pasa: la lista muestra todos los hoteles de la opción juntos y
  se marcan las 2 matrimoniales de una vez.
- La regla de fondo ya está en el backend: no se puede **aceptar** con una comparación sin
  resolver (exactamente 1 elegida).
- **Corrección (26-oct-2026, revisión del flujo de cotizaciones):** tampoco se puede **generar el
  PDF** con una comparación sin resolver (`AlternativaPdfService::validarGruposResueltos()`,
  guardrail del 19-sep: el total salía con el hotel más barato sin decirlo). La instrucción que se
  le dio al usuario ("no marques ninguna como elegida y descarga el PDF") era incorrecta: para
  enviar la comparativa hay que **marcar una como elegida** (la recomendada); el PDF muestra la
  tabla con todos los hoteles y la elegida resaltada, y el total con esa. Ver hallazgo H4.

**Propuesta:** el primer hotel puede empezar la comparación con **1 sola habitación** (el banner
"Comparando 1 hotel(es)" invita a sumar el siguiente). Una comparación de 1 sola fila al resolverse
se comporta como un ítem normal. Solo frontend (el mínimo es del picker); revisar si el backend
pone algún mínimo al crear el grupo.

**Preguntas para el usuario:** ninguna.

### 7. Fotos de los hoteles en el PDF de la cotización, como en mayoristas

**Lo que pide el usuario (26-oct-2026):** que el PDF de la cotización con la comparativa de
hoteles muestre **3 imágenes** por hotel, igual que sale con los mayoristas.

**Lo que hay hoy (verificado):**
- El PDF (`alternativa.blade.php`, `AlternativaPdfService::hotelesInfoParaPdf()`) imprime
  "Fotos referenciales de los hoteles": por hotel, **1 foto de fachada + hasta 2 de habitación**,
  más check-in/check-out. Esas fotos salen **solo de `opciones_hotel.fotos`**, cada una marcada
  `fachada` o `habitacion`.
- **Hoteles de mayorista:** son `opciones_hotel`, y el picker de mayorista tiene subida de fotos
  por tipo → salen.
- **Hoteles registrados como Proveedor** (lo habitual en Local/Nacional): la fila de la
  comparación no trae `opcion_hotel_id`, así que **nunca salen fotos**, aunque el proveedor
  tenga galería: `proveedores.fotos` ya existe (lista de fotos, **sin** marca de fachada o
  habitación) y se carga en la ficha del proveedor.
- **Hotel "no registrado" de Local** (`opciones_hotel` sin mayorista): el PDF ya imprimiría sus
  fotos, pero el picker de Local **no ofrece subirlas** (solo el de mayorista recibe
  `permitir-gestion-hoteles`).

**¿Es posible?** Sí, y reutiliza casi todo: la sección del PDF, el recorte 4:3
(`ImagenRecorteService`) y la galería del proveedor ya existen.

**Propuesta:**
- Hotel Proveedor: la fila de la comparación lleva el `proveedor_id`, y `hotelesInfoParaPdf()`
  toma de `proveedores.fotos` las **3 primeras**, con el check-in/check-out de su
  `alojamientoDetalle` (ya lo hace para los de mayorista ligados a un proveedor).
- Hotel no registrado de Local: subir fotos (fachada/habitación) desde el picker de Local, igual
  que en mayorista.
- Sin fotos: el hotel no imprime bloque, como hoy.

**Respuestas del usuario (26-oct-2026):**
1. **Marcar qué foto es la fachada** (no solo "las 3 primeras"): el dato sirve para otras cosas
   más adelante, porque se puede elegir.
2. **En los dos casos:** con comparativa y con un solo hotel. Así el cliente ve cómo es el hotel
   donde se va a alojar y, si no le gusta, pide otra opción.

**Lo que hay además (verificado):** la galería del proveedor (`proveedores.fotos`) es una lista
de rutas sin tipo; la leen solo `ProveedorController` y `proveedores/detalle.vue`/`form.vue`. Las
fotos de `opciones_hotel` ya pasaron por el mismo cambio (`2026_09_05_100400_migrar_fotos_
opciones_hotel_a_tipo_foto`: de lista de rutas a `{path, tipo_foto}`, con `OpcionHotel::
normalizarFotos()`/`fotosResueltas()`). Al **promover** un hotel no registrado a Proveedor
(`OpcionHotelController::promover()`), sus fotos **no se copian** al proveedor.

**Diseño (a aprobar):**
- **Un solo formato de fotos de hotel:** `proveedores.fotos` pasa a `{path, tipo_foto}`, igual que
  `opciones_hotel`. Tipos: `fachada` (máx. 1), `habitacion`, `otra`. Migración de datos
  idempotente: las fotos ya cargadas quedan como `otra`. Los helpers de normalizar y resolver se
  comparten con `OpcionHotel` (no se duplican).
- **Ficha del proveedor:** en la galería, elegir el tipo de cada foto ("Marcar como fachada",
  "Habitación"). Marcar una fachada desmarca la anterior. Para proveedores que no son hotel, el
  selector no se muestra (todas quedan `otra`).
- **Promover un hotel no registrado** copia sus fotos con su tipo al proveedor nuevo.
- **Hotel no registrado de Local:** subir fotos con su tipo desde el picker de Local, igual que en
  mayorista.
- **PDF:** la sección "Fotos referenciales de los hoteles" incluye **todos los hoteles de la
  alternativa**: los de una comparación y los sueltos, sin repetir un hotel que aparezca dos
  veces (por ejemplo, en varios destinos). Por hotel: la fachada primero, después hasta 2 de
  habitación; si faltan, se completa con `otra` hasta 3. Más check-in/check-out del proveedor.
  Hotel sin fotos: no imprime bloque.
- Verificar el PDF real con Ghostscript (memoria `reference_pdf_visual_verification_ghostscript`):
  esta sección ya tuvo solapes con DomPDF (`page-break-before: always`).

### 8. Revisión antes de enviar: "¿esta propuesta ya está lista para el cliente?"

**Lo que pide el usuario (26-oct-2026):** antes de enviar el PDF o el correo, una revisión con
una lista breve de pendientes: tarifas por confirmar, precios que requieren revisión manual,
temporada desactualizada, datos incompletos y vigencia de la propuesta. Cada alerta lleva a la
sección donde se corrige. El vendedor tiene que poder responder rápido: "¿esta propuesta ya está
lista para que la vea el cliente?".

**Lo que hay hoy (verificado):**
- En la alternativa hay 3 acciones de envío: **Ver PDF** (solo descarga, no cambia el estado),
  **Condiciones generales** y **Enviar por correo** (`EnviarCorreoCotizacion`, que sí la marca
  `enviada` y calcula `fecha_vencimiento` = envío + `dias_vigencia_cotizacion`,
  `CotizacionEnvioController`). No hay ninguna revisión previa: se envía lo que haya.
- El único bloqueo existente es al **aceptar** (comparación de hoteles sin resolver, 422).
- Datos que ya existen y sirven para cada alerta:

| Alerta pedida | Dato real que la detecta |
|---|---|
| Tarifas por confirmar | Ítem **manual** (sin tarifa, `origen_tipo='manual'`); hotel con precio **referencial** cargado a mano; proveedor o guía **de referencia** (`es_referencial`: precio de lista, operador sin definir); pasaje aéreo con `tarifa_valida_hasta` |
| Precios que requieren revisión | Venta menor que el costo; margen bajo el `margen_minimo_pct` de la tarifa; descuento sobre `descuento_maximo_pct`; precio 0 |
| Temporada desactualizada | La tarifa usada **vence** antes del viaje (`vigente_hasta`) o fue **desactivada** (`activo=false`); su **temporada** no cubre la fecha de viaje, o no hay ocurrencia cargada para ese año (`temporada_ocurrencias`, central); el precio de la tarifa **cambió** desde que se agregó (snapshot ≠ ficha actual); tour con precio por temporada (`paquete_plantilla_precios`) |
| Datos incompletos | Sin fecha de viaje; alternativa sin ítems; edades de niños faltantes si la tarifa distingue niño; cliente sin correo (solo para "Enviar por correo"); tipo de cambio aplicado viejo (pendiente ya conocido: "refrescar tasa en borrador") |
| Vigencia de la propuesta | Fecha en que vencerá si se envía hoy; si ya se envió y **venció** (`estado_resumen = vencida`); la vigencia pasa la fecha de viaje; pasaje aéreo que vence antes que la propuesta o en menos de 24 h |

**Propuesta inicial** (reemplazada por el diseño de abajo, que recorta la lista a lo crítico):
un cálculo único en el backend, un panel "Lista para el cliente" con enlaces y un modal antes de
enviar.

**Diseño (a aprobar) — solo lo crítico:**

*Las únicas 6 revisiones* (todo lo demás de la tabla de arriba queda fuera a propósito):

| # | Revisión | Nivel | Por qué es crítica | Lleva a |
|---|---|---|---|---|
| 1 | Alternativa sin servicios | **Bloquea** | Se enviaría una propuesta vacía | Lienzo |
| 2 | Servicio sin precio o en 0 | **Bloquea** | El cliente vería un precio que no existe | El servicio |
| 3 | Venta por debajo del costo, o margen bajo el mínimo general | Aviso | Se pierde dinero | El servicio |
| 4 | Hay un **precio nuevo** para la tarifa usada (se editó en la ficha) o la tarifa ya **no vale para la fecha del viaje** (vencida, desactivada u otra temporada) | Aviso | Se cotiza un precio que ya no existe | El servicio, con "Actualizar al precio nuevo" |
| 5 | Pasaje aéreo con la tarifa **vencida** o que vence antes que la propuesta | Aviso | La aerolínea ya no sostiene ese precio | El pasaje |
| 6 | La propuesta ya se envió y **venció** | Aviso | Se reenviarían precios viejos | Datos de la cotización |

Fuera, por triviales o por ser decisiones del vendedor: ítem manual, precio puesto a mano,
proveedor o guía de referencia, edades de niños, cliente sin correo (el formulario de envío ya
lo pide), tipo de cambio, fecha de viaje vacía (sin fecha, las revisiones 4 y 5 simplemente no
se evalúan).

*Cómo se ve, sin agregar pasos:*
- **Todo bien = silencio:** junto a los botones de envío solo aparece un ✓ "Lista para el
  cliente". Sin modal, sin clics extra.
- **Con pendientes:** una insignia "N pendientes" que despliega una lista corta. **Avisos del mismo
  tipo se agrupan** en una línea ("3 servicios con precio nuevo"), que al hacer clic lleva al
  primero y permite ir al siguiente.
- **Ver PDF:** nunca se frena; si hay pendientes, la lista queda visible al lado.
- **Enviar por correo:** con bloqueos, el botón de enviar del formulario queda deshabilitado con
  el motivo; con solo avisos, la lista aparece dentro del mismo formulario de envío con "Enviar
  igual" (no es un modal aparte).
- Se recalcula sola al cambiar la alternativa.

*"Actualizar al precio nuevo" (respuesta 3):*
- Editar el precio de una tarifa ya usada crea una **versión nueva** y cierra la anterior
  (`ProveedorTarifaController::update()`: `vigente_hasta` = hoy). **Hallazgo al armar la tarea:**
  el vínculo entre versiones **no se guarda** (`version_anterior_id` solo viaja en la respuesta
  JSON). Se agrega la columna `proveedor_tarifas.version_anterior_id` y se llena desde ahora; las
  versiones viejas sin vínculo no generan el aviso de precio nuevo (solo el de tarifa vencida).
  La alerta detecta que la tarifa del servicio tiene una versión siguiente activa.
- Al hacer clic: confirmación "Precio actual S/ X → nuevo S/ Y. ¿Actualizar?". Al confirmar se
  re-engancha el servicio a la versión nueva, con su costo y su precio, respetando el descuento
  del servicio y recalculando el total. Uno por uno (sin "actualizar todos", para no cambiar
  precios sin mirarlos).
- Solo en alternativas no aceptadas (T-001 ya bloquea cambiar precios de una aceptada).

*Margen mínimo general (respuesta 5):* `configuracion_agencia.margen_minimo_alerta_pct`; vacío =
no se avisa margen (la venta bajo costo se avisa siempre).

*Un solo cálculo:* el backend arma la lista (`GET alternativas/{id}/revision`) y el envío por
correo la vuelve a correr antes de enviar (los bloqueos se respetan aunque se llame a la API
directo).

**Respuestas del usuario (26-oct-2026):**
1. Bloquean **solo** "alternativa sin servicios" y "servicio sin precio o en 0"; el resto avisa.
2. Pidió ayuda para decidir → decidido abajo (D2).
3. Precio que cambió en la ficha: **avisar y ofrecer "Actualizar al precio nuevo"** desde la
   alerta, **con confirmación** antes de aplicar.
4. **Ver PDF** muestra el panel con las alertas pero **siempre** deja generar el PDF (es también
   para revisar internamente). **Enviar por correo** ejecuta la revisión: con bloqueos hay que
   corregir primero; con solo avisos ofrece "Enviar igual".
5. Margen: **solo un margen mínimo general** de la agencia (Configuración), no el de cada tarifa.
6. **Regla de fondo (la más importante):** que no se vuelva espeso. La revisión tiene que acelerar
   el envío, no frenarlo: **solo puntos críticos de valor, nada trivial.**

**D2 — "Tarifas por confirmar": no se crea un estado nuevo.** Una marca "por confirmar" en cada
servicio obligaría al vendedor a ponerla y quitarla a mano: un paso más en cada cotización, y si
se olvida de quitarla la alerta se vuelve ruido que se aprende a ignorar. Tampoco se avisa de un
servicio manual o de un precio puesto a mano: es una decisión que el vendedor ya tomó, avisarla
en cada envío sería trivial. Lo único que de verdad está "por confirmar" por un tercero y puede
hacer quedar mal a la agencia es el **plazo de la tarifa aérea** (la aerolínea la sostiene hasta
una fecha y hora): esa sí entra.

---

## AGE — Revisión del flujo de cotizaciones (26-oct-2026)

Pedido del usuario: buscar fallos en todo el flujo de cotizaciones. Revisado leyendo el código de
`CotizacionController`, `AlternativaController`, `AlternativaItemController`,
`CotizacionEnvioController`, `OpcionMayoristaController`, `AlternativaPdfService`,
`BibliotecaCotizadorController` y `ReservaController::aceptar()`. Los 706 tests de Agencia pasan:
estos casos no están cubiertos. **Nada reproducido todavía en pantalla**: confirmar cada uno con
un test que falle antes de corregirlo. Lo que ya es tarea no se repite (T-001: cambiar precio en
alternativa aceptada).

| # | Prioridad | Hallazgo | Dónde |
|---|---|---|---|
| H1 | **Alta** | **Duplicar + "Eliminar todo este bloque" borra la comparación de hoteles en las dos alternativas.** Duplicar copia el mismo `grupo_opcion_id`; `eliminarGrupo()` busca solo por ese id, sin alternativa. Además el total de la otra alternativa no se recalcula, y si la original está aceptada la copia no puede borrar su grupo (422). | `AlternativaController::duplicar()`, `AlternativaItemController::eliminarGrupo()` |
| H2 | **Alta** | **Un vendedor puede cambiar o borrar alternativas, servicios, hoteles y mayoristas de cotizaciones de otro vendedor, y bajar su PDF.** La regla "cada vendedor ve solo lo suyo" (`CotizacionPolicy`) se revisa al ver la cotización, al aceptar y al enviar por correo, pero no en esos controladores (solo piden el permiso general `cotizaciones.editar`). Tampoco en `actualizarPasajeros()`. Mismo tipo de hueco que se cerró en Reservas con `ReservaPolicy` (F3). | `AlternativaController`, `AlternativaItemController`, `OpcionMayoristaController`, `OpcionHotelController`, `AlternativaDestinoController`, `CotizacionController::actualizarPasajeros()` |
| H3 | Media | **Una alternativa aceptada (con reserva) se puede pasar a borrador, enviada o descartada por API.** Solo se bloquea pasarla a "aceptada". En borrador se desbloquea la edición de precios y la reserva queda desalineada. | `AlternativaController::update()` |
| H4 | Media (decisión) | **No se puede generar el PDF con una comparación de hoteles abierta** (guardrail del 19-sep, porque el total salía con el más barato sin decirlo). Choca con enviar la comparativa para que el cliente elija (obs. 6). Opciones: dejarlo así (se marca la recomendada) o permitir el PDF abierto mostrando "Total desde X, según el hotel que elijas" y la tabla sin elegida. | `AlternativaPdfService::validarGruposResueltos()` |
| H5 | Media | **El día que se cambia el precio de una tarifa, la biblioteca muestra la versión vieja y la nueva.** La vieja se cierra con `vigente_hasta` = hoy y la nueva empieza hoy; la búsqueda incluye las dos. El vendedor puede elegir el precio viejo. | `ProveedorTarifaController::update()`, `BibliotecaCotizadorController::buscarProveedorTarifas()` |
| H6 | Media (a confirmar) | **Mayoristas en dos destinos de la misma alternativa:** "Elegir" desmarca la elegida de toda la alternativa (no por destino), y sigue el índice único viejo por alternativa (limpieza 12g pendiente). Con 2 destinos internacionales con mayoristas distintos, solo uno puede quedar elegido. ¿Se cotiza así en la práctica? | `OpcionMayoristaController::elegir()`, índice `opcion_mayorista_alternativa_elegida_unique` |
| | | **Decisión del usuario (26-oct-2026) sobre H6:** se queda como está — **un mayorista por alternativa**. No se toca. (Cuando se haga la limpieza 12g, tener en cuenta que el índice que manda es el de por alternativa.) | |
| H7 | Media | **Tarifa de proveedor sin precio de niño = niño gratis, pero el costo sí lo cuenta** → margen negativo sin aviso. En tours, vacío significa "igual al adulto". Dos reglas distintas para el mismo campo vacío. | `AlternativaItemController::crearItemProveedor()`, `CotizacionController::recalcularItemsPorPersona()`, `ComboExplosionService` |
| | | **H4 — propuesta (a aprobar), 26-oct-2026:** permitir el PDF con la comparación abierta, pero sin esconder nada (que era el problema que motivó el bloqueo): **(a)** con **una** comparación abierta, el resumen de precio muestra **un total por cada opción** ("Con Hotel A: S/ 2,100 · Con Hotel B: S/ 2,450"), calculado como el resto de la alternativa + esa opción; **(b)** con **dos o más** comparaciones abiertas (combinaciones), "Desde S/ X — con las opciones más económicas; el total final depende de los hoteles que elijas" + las tablas; **(c)** la tabla "Opciones de hoteles" no resalta ninguna fila mientras esté abierta; **(d)** marcar una como elegida sigue funcionando igual que hoy (PDF con la recomendada resaltada); **(e)** aceptar sigue exigiendo una elegida. Motivo: en la práctica de las agencias (los 3 Word reales de `docs/auxiliares/`) la tabla de opciones **es** la propuesta y el cliente elige; el vendedor no debería tener que adivinar ni mandar un total que no es. | |
| | | **H4 aprobado por el usuario (26-oct-2026)** tal cual la propuesta → T-023. | |
| H10 | **Alta** | **El PDF puede decir "Incluye descuento aplicado: -S/ X" sin que haya descuento.** El monto "antes del descuento" suma todas las opciones de una comparación (no solo la elegida), no convierte la moneda de cada servicio y multiplica por cantidad también los "por persona". Visible para el cliente. Encontrado al preparar T-023 → incluido ahí. | `AlternativaPdfService` (`$totalOriginal`, `$descuentoMonto`, `hayDescuento`) |
| | | **Pantalla del cotizador (`cotizador/editar.vue`), revisada el 26-oct-2026:** | |
| H11 | Media | **Al editar un destino, el campo de fecha muestra un día antes.** El servidor manda las fechas como `2026-08-20T00:00:00Z`; `CampoFecha` (flatpickr) interpreta la "Z" como medianoche UTC = 19:00 del día anterior en Perú. La cabecera ya lo corregía a mano (`slice(0, 10)`); el formulario de destino no. Confirmado leyendo flatpickr (`utils/dates.js`). Arreglo de fondo: que `CampoFecha` recorte a `YYYY-MM-DD` cualquier valor que reciba, para todas las pantallas. | `abrirFormEditarDestinoActivo()`, `components/CampoFecha.vue` |
| H12 | Media | **Eliminar un tour clásico (bloque) dispara un borrado por servicio en paralelo**, y cada uno recalcula y guarda el total de la alternativa. El último en escribir puede haber calculado con servicios que otro todavía no había borrado: el **total guardado** (el de las pestañas y el del PDF) puede quedar mal aunque la pantalla muestre bien el suyo. Sin reproducir. | `eliminarBloque()` (`Promise.all`), `AlternativaItemController::destroy()` |
| H13 | Baja-media | **Edición de precio en línea:** al guardar un precio se recarga toda la cotización y se reinician todos los campos; si en ese momento se está escribiendo otro precio (o se sigue escribiendo el mismo), el valor tipeado puede perderse o el campo "saltar". Sin reproducir; probar en pantalla. | `enviarEdicion()` → `cargarCotizacion()` → `inicializarEdicionItems()` |
| H14 | Baja | Con varios destinos, el **subtotal por destino** suma todas las opciones de una comparación de hoteles (el total general sí está bien). | `gruposPrecioPorDestino` (usa `totalConvertidoLocal` en vez de `totalEfectivoLocal`) |
| H15 | Baja | Al editar pasajeros, la etiqueta Infante/Niño/Adulto usa edades fijas (2 y 12); el servidor usa las de Configuración de la agencia. Si la agencia las cambió, la etiqueta no coincide con lo que se cobra. Los avisos con nombres (`Swal` con `html`) arman HTML sin escapar (datos internos, riesgo bajo). | `tipoPorEdadEdicion()`, `guardarPasajeros()`, `recalcularTemporadas()` |
| | | **Agregar servicios, hoteles y tours desde la biblioteca, revisado el 26-oct-2026:** | |
| H16 | **Alta** | **La matriz de habitaciones de un hotel muestra todas sus tarifas, incluidas las versiones viejas y las desactivadas.** Para abrir la matriz se usa `GET proveedor-servicios/{id}/tarifas` (`ProveedorTarifaController::index()`), que devuelve todo sin filtrar. Cada cambio de precio de una tarifa ya usada crea una versión nueva, así que la matriz muestra varias "matrimonial" con precios distintos y el vendedor puede elegir la vieja; el alta solo rechaza las desactivadas, **no las vencidas**: el precio viejo entra a la cotización. Lo mismo en "usar tarifa registrada" del hotel de un mayorista (`ProveedorController::tarifasHotel()`). Agrava H5 (que solo pasaba el día del cambio). | `clicBibliotecaItem()`, `onCambiarProveedorHotel()`, `ProveedorTarifaController::index()`, `ProveedorController::tarifasHotel()`, `AlternativaItemController::crearItemProveedor()` |
| H17 | **Alta** | **Los tours del catálogo siguen cotizando con el precio viejo de una tarifa después de editarla.** Si la tarifa ya se usó en alguna cotización, editarla crea una versión nueva, pero las plantillas de tours (`paquete_plantilla_items.proveedor_tarifa_id`) siguen apuntando a la versión vieja (cerrada); `ComboExplosionService` lee esa. Afecta al modo clásico, el que usan todos los tenants hoy. Confirmado leyendo el código; ¿hay algún motivo para que la plantilla conserve la versión vieja? (no se encontró ninguno documentado). | `ProveedorTarifaController::update()`, `ComboExplosionService::resolverCostoVentaItem()` |
| H18 | Media (tributario) | **Tour clásico desde plantilla: el guía y el "Ajuste de redondeo" nacen sin tratamiento tributario.** Al facturar, `resolverTipAfeIgvItem()`/`resolverDestinoTributario()` caen al fijo `'10'`/`'nacional'`, no al default de la agencia (el guía agregado suelto sí usa el default). En una agencia de la Amazonía el guía se factura gravado y la mezcla con servicios exonerados puede **bloquear la facturación**. Sin reproducir. | `AlternativaItemController::crearItemClasicoDesdeEntrada()` y el ítem de ajuste en `desdePlantilla()` |
| H19 | Baja | Tour clásico desde plantilla, servicio compartido: el costo cuenta también a los infantes; el alta normal (y el recálculo al cambiar pasajeros) no los cuenta. El margen cambia al editar pasajeros. | `crearItemClasicoDesdeEntrada()` vs. `crearItemProveedor()` |
| | | **Decisión del usuario sobre H7 (26-oct-2026):** en **servicios de tour**, si la tarifa no tiene precio de niño, **el niño paga como adulto** (misma regla que los tours); **en hoteles no**, porque tienen sus propias tarifas. → T-031 | |
| | | **Encontrados en la medición en pantalla (obs. 9), 26-oct-2026:** | |
| H20 | Media | **PDF: la tabla "Opciones de hoteles" dice "tarifa por persona"** pero en Local/Nacional muestra el total por habitación y noches (PEN 1,020 = 340 × 3). El cliente lo lee mal. Confirmado en el PDF real. | `alternativa.blade.php` (encabezado de la tabla) |
| H21 | **Alta (tributario, decisión)** | **Un paquete internacional de mayorista nace "Exonerado · Amazonía"**: toma el tratamiento por defecto de la agencia. Un viaje a Panamá no se consume en la Amazonía (Ley 27037). Al facturar saldría exonerado. Confirmado en pantalla. Falta decidir con el contador qué tratamiento le corresponde al paquete internacional (y si cambia entre la comisión y el resto). | `AlternativaItemController::crearItemMayorista()` → `resolverTratamientoTributario()` |
| H22 | **Alta** | **Mayorista: el niño no se cobra.** El precio del paquete se multiplica por la cantidad de **adultos**; con 2 adultos + 1 niño el total fue USD 1,900 (950 × 2) y el panel dice "Total (2 adultos + 1 niño)". No hay precio de niño para el paquete ni aviso. Confirmado en pantalla. | `cantidadAdultosCotizacion` (editar.vue), `crearItemMayorista()` |
| H23 | Media | Crear una alternativa sin escribir nombre: error en inglés en ventana ("The nombre field is required."); el texto de ayuda dice "Alternativa B" en la primera. → R1/R7 | `AlternativaController::store()`, `editar.vue` |
| H24 | Baja | "Alternativa A" se guarda como "Alternativa a" (la capitalización baja la letra suelta) y el panel muestra "Alternativa Alternativa a". | `TextoFormatoService::capitalizarNombrePropio()`, panel de precio |
| H25 | Verificar | El tipo de cambio "del día" sugerido era del 9-sep (en local). Confirmar que la sincronización diaria corre en producción. | `tipo-cambio:sincronizar` (cron) |
| H8 | Baja | Elegir, descartar o editar una opción de mayorista de una alternativa aceptada no está bloqueado (eliminar sí). | `OpcionMayoristaController` |
| H9 | Baja | Solo por API: `pax_incluidos` acepta pasajeros de otra cotización (el precio sale 0); el pasaje aéreo acepta cualquier código de IGV de 2 caracteres. | `AlternativaItemController` |

### 9. Rapidez y facilidad del cotizador — medición real (26-oct-2026)

**Lo que pide el usuario:** le preocupa la rapidez y la facilidad de uso (puntaje 6/10 en ambas).
Se acordó medir antes de rediseñar, con los 3 tipos de cotización más usados.

**Cómo se midió:** recorridos completos en `agencia-demo` con el navegador automatizado
(Playwright), contando cada acción del vendedor (clic, campo, marca) y el tiempo de espera de cada
paso. Entorno local (Apache en Windows): los tiempos de producción pueden ser distintos. Datos de
prueba borrados al terminar.

| Recorrido | Acciones hoy | Esperas | Acciones posibles con las mejoras |
|---|---|---|---|
| 1. Tour nacional + hotel (2 adultos + 1 niño, 3 noches) | **31** (+2 por un error) | 1–3 s por acción; 3,6 s hasta ver la matriz del hotel | **~20** |
| 2. Comparativa de 2 hoteles | **20** | ídem | **~12** |
| 3. Internacional con mayorista (2 hoteles) | **28** (16 son datos que el vendedor tiene que escribir sí o sí) | ídem | **~23** |

**Fricciones vistas en pantalla (en orden de recorrido):**
1. Después de crear la cotización la pantalla queda vacía: hay que crear la alternativa a mano
   (nombre, moneda, tipo de cambio). Si no se escribe el nombre: error **en inglés** ("The nombre
   field is required.") en una ventana; el texto gris "Alternativa B" es solo ayuda (y dice B en la
   primera). → H23
2. La biblioteca no se filtra por el destino de la cotización (Cusco → muestra Alto Mayo y Lamas);
   en Local/Nacional, 6 de 12 tours visibles son "Solo itinerario (mayorista)" o internacionales y
   no se pueden cargar.
3. Al cambiar de categoría (Tours, Alojamiento) la lista tarda ~2 s **sin indicador de carga** y
   mientras tanto muestra los resultados anteriores: se puede hacer clic en el equivocado.
4. Hotel: **noches = 1** por defecto aunque las fechas digan 3; **ningún ocupante marcado** (hay
   que marcar a cada pasajero).
5. Comparativa: con una sola habitación marcada, "Sumar a la comparación" queda **deshabilitado sin
   explicación** (T-019); el PDF se bloquea si no se marca una elegida (T-023).
6. Después de cada acción la pantalla tarda **2–3 s** en mostrar el cambio, sin indicador (recarga
   toda la cotización en cada paso; ver también H13).
7. Mayorista: formulario sin etiquetas (solo texto gris dentro de cada campo); hay que pulsar
   "Hoteles" para ver dónde se cargan y "Elegir mayorista" antes de poder confirmar una habitación,
   aunque sea la única opción.
8. El PDF se descarga como "cotizacion-Alternativa a.pdf": sin código ni cliente, difícil de
   encontrar después.

**Mejoras rápidas propuestas (a aprobar), por ahorro:**

| # | Mejora | Ahorra |
|---|---|---|
| R1 | Al crear la cotización nace sola la "Alternativa A" (moneda por defecto y tipo de cambio del día); el formulario de nueva alternativa usa el nombre sugerido si se deja vacío | 3–5 acciones y un error |
| R2 | Hotel: noches calculadas de las fechas (del destino o de la cotización) y ocupantes marcados por defecto (todos) | 2–5 acciones por hotel |
| R3 | Biblioteca abierta ya filtrada por el **destino activo** de la alternativa (el chip seleccionado; con varios destinos, el que se está armando, que es adonde va lo que se agregue), incluidos sus lugares (`idsConDescendientes`). Destino escrito a mano sin catálogo: sin filtro. El filtro se ve como "Mostrando: Cusco ✕" y se quita con un clic; si el vendedor lo cambia, se respeta mientras la biblioteca siga abierta. En Local/Nacional no se listan tours "solo itinerario" (pregunta del usuario sobre 2 destinos, 26-oct-2026) | búsqueda y errores de selección |
| R4 | Indicador de carga en la biblioteca y en cada acción; la lista vieja se atenúa mientras carga | clics equivocados; sensación de lentitud |
| R5 | Actualizar en pantalla solo lo que cambió (el ítem y el total) en vez de recargar toda la cotización | 1–2 s por acción (≈ 30–60 s por cotización) |
| R6 | Mayorista: etiquetas visibles en el formulario; si hay una sola opción, queda elegida sola | 1–2 acciones y dudas |
| R7 | Errores de validación en español y junto al campo, no en ventana | interrupciones |
| R8 | Nombre del PDF con código y cliente (`CDKM-1026-0000015 - Rivera.pdf`) | encontrar el archivo |

Junto con T-019 y T-023 (comparativa), llevarían los recorridos a los números de la última columna.

**Respuestas del usuario (26-oct-2026):**
1. **R1–R8 aprobadas**, en el orden propuesto. → T-032 (R5, R4), T-033 (R1, R7), T-034 (R2),
   T-035 (R3), T-036 (R6 + H22), T-037 (R8 + H20).
2. R1: la alternativa nace sola, pero **el nombre (título que ve el cliente) y la moneda se tienen
   que poder cambiar** → se eligen ya en "Nueva cotización" (prellenados) y la moneda se puede
   cambiar mientras la alternativa no tenga servicios.
3. H22: **por ahora el paquete de mayorista se cotiza solo por adultos** → se hace visible en
   pantalla (T-036); el precio de niño del paquete queda para más adelante.

---

## Orden de construcción propuesto (a aprobar)

Aprobado el 25-oct-2026: son las tareas T-008 a T-018, en este orden (estado en `docs/backlog.md`):

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
