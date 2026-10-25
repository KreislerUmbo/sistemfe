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

**Preguntas para el usuario:**
1. **¿Al contado siempre se cobra en el momento?** ¿O puede quedar "al contado pendiente" (el
   cliente paga más tarde ese mismo día)? Si siempre se cobra, el pago sería obligatorio.
2. **¿Pago mixto?** Por ejemplo, S/ 200 en efectivo y S/ 300 por Yape en el mismo comprobante.
3. **¿El n.º de operación es obligatorio** para Yape, Plin, transferencia y tarjeta? (En
   efectivo no aplica.)
4. **Sin caja abierta:** ¿se bloquea el cobro ("abre tu caja") o se permite emitir y cobrar
   después?
5. **¿También en Ventas (retail)** se agrega el n.º de operación al registrar una venta?

**Relacionado:** "Cobrar y emitir en el mismo paso" de Venta Directa (bloque 3 de la hoja de
ruta de Agencia).

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

**Preguntas para el usuario:**
1. **¿Catálogo por agencia o uno común** para todas las agencias del sistema? (Común: todos ven la
   misma lista y nadie la carga de cero.)
2. En la pestaña Pasajeros hay dos vuelos distintos: el **vuelo por cuenta propia** del pasajero
   y el **vuelo vendido por la agencia**. ¿A cuál te refieres con "cargue por defecto"?

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

**Preguntas para el usuario:**
1. ¿Que se cargue **solo al aceptar**, o mejor un botón **"Usar datos del cliente"** en cada
   pasajero (para elegir a quién se le pone)?

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
