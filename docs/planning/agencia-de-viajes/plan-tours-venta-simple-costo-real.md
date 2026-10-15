# Tours: vender simple, operar por salida, medir el costo real

> **Estado: EN EJECUCIÓN — fase 1a hecha (14-oct-2026, ver §6); sigue la 1b.** Diseño del 13-oct-2026.
> Nace de una propuesta del usuario que reemplaza el enfoque de
> `plan-precio-tour-por-persona.md` (ese plan queda **descartado como alternativa**, con la
> razón en su encabezado y en §1 de este documento). Revisado contra el código y los datos de
> `agencia-demo` antes de escribirlo.
>
> **14-oct-2026:** respuestas del contador a P1 y P2, y decisión sobre P3. Cambian N5, N10 y
> §3.1-§3.3, y se agrega N14 (tour con IGV mezclado = una línea por tipo de IGV). Revisión del
> flujo de mayoristas: N15 (tours solo de itinerario) y casos de mayorista y hoteles en §5.
> Mismo día, rondas siguientes: plan de reversa (§5b), calculadora y estimado contra real
> (§3.9b), proveedores de la salida, compras a crédito y sin comprobante, rendiciones (§3.7b),
> tabla única de proveedores (§3.7c), mayoristas y comisiones (§3.7d), guía con usuario
> (§3.10), revisión de choques con procesos existentes (§5c) y endoso versión 2
> (`plan-endoso-pasajeros.md`). **Todas las preguntas P1-P7 resueltas.**

---

## 1. La idea y por qué reemplaza al plan anterior

**Idea del usuario, en tres pasos:**
1. **Vender simple.** El tour tiene un precio por persona y un costo estimado; el margen se calcula solo. El "Incluye" es informativo. En la cotización se carga el tour: precio × pasajeros, más el Incluye y las fotos.
2. **Operar por salida.** Al aceptar, las reservas se agrupan por tour (destino) y fecha. Operaciones ve "15-oct Alto Mayo: 9 pasajeros" y asigna vehículo, chofer y guía. El guía ve sus pasajeros y marca los servicios que va cumpliendo. Al terminar, el cliente califica cada servicio.
3. **Medir lo real.** Se registran las facturas reales de lo que se gastó en cada salida (restaurante, vehículo, entradas). Un reporte compara lo cobrado con lo gastado de verdad. Las compras y ventas sirven además para el contador.

**Por qué es mejor que el plan anterior:** el plan anterior buscaba un costo **exacto al
cotizar** (reparto entre servicios, mínimo como divisor, vehículo de referencia, "se cobra
por", vehículo por confirmar…). Era mucha complejidad para estimar algo que cambia el día del
tour: qué vehículo sale, cuántos pasajeros van, cuánto cobró de verdad el restaurante. Este
modelo es el de **presupuesto contra real**, el habitual en operadoras de turismo: estima de
forma simple al vender y mide el margen que importa, **el real**, después de operar.

**Costo de este cambio:** no es solo el precio. Hoy el tour se "desarma" en líneas con
proveedor dentro de la cotización y la reserva, y sobre esas líneas funcionan el Reporte
Operativo, el enganche a la Salida Operativa, la reasignación de proveedor, el check-in y la
agrupación de la factura. En este modelo el tour es **una línea**, y la operación pasa a
trabajarse **por salida**. Además suma módulos nuevos (Compras, registros contables,
rentabilidad). Es más grande que el plan anterior, pero resuelve el problema de fondo.

## 2. Decisiones

| # | Decisión |
|---|---|
| N1 | **Tour = precio por persona + costo estimado por persona.** Precio adulto (obligatorio) y niño (opcional; vacío = adulto). El infante paga 0. Margen calculado. Moneda propia del tour. |
| N2 | **Precio extranjero opcional** (respuesta del usuario: "en algunos casos" las entradas cuestan distinto). El tour puede tener precio adulto/niño **extranjero**; si está vacío, se usa el nacional. Igual para el costo estimado. Ver §3.3. |
| N3 | **El "Incluye" es una lista estructurada, no texto.** Cada ítem es un servicio del catálogo (transporte, guía, almuerzo, entrada, bote) con **proveedor sugerido opcional, tipo de IGV y costo estimado opcional, sin precio de venta propio** (el precio es el del tour). Una sola lista que sirve para cuatro cosas: el Incluye del PDF, qué reservar en la salida, los checks del guía, y las categorías de calificación y de gasto. |
| N4 | **Calculadora opcional del costo estimado** a partir de las tarifas del catálogo (botón "calcular desde tarifas"). Sugiere, no obliga. |
| N5 | *(corregida 14-oct con el contador, P1-P2)* **El IGV va por servicio del Incluye, no por tour.** Cada servicio lleva su tipo de afectación según **dónde se presta** (Amazonía, Ley 27037 → exonerado, código 20; fuera de zona exonerada → gravado 18 %, código 10). **El contador confirmó que no se puede facturar un tour con un solo tipo de IGV si mezcla servicios gravados y exonerados**: SUNAT exige cada concepto con su afectación. El IGV **no depende de si el cliente es extranjero**: aunque sea no domiciliado, si el servicio se presta en zona gravada, lleva IGV. |
| N6 | **En la cotización, el tour es una sola línea:** precio × pasajeros según tipo y residencia. Los servicios sueltos, hoteles, pasajes aéreos, mayoristas, opcionales y líneas manuales **no cambian** (§3.5). |
| N7 | **Combos:** se cargan como **una línea por tour** (cada tour va a su propia salida, en su día) más los hoteles como líneas aparte. El hotel **nunca** va dentro del precio por persona del combo (§3.4). |
| N8 | **Operación por salida** para los tours (agrupados por tour + fecha) y **por reserva** para todo lo demás (sueltos, hoteles, privados). §3.6. |
| N9 | **Los gastos reales se registran en un módulo de Compras del sistema base** (sirve a todos los giros), con una asignación a **centro de costo** propia de Agencia: salida, reserva o gasto general. §3.7. |
| N10 | **Registros para el contador:** Registro de Ventas y Registro de Compras por periodo (respuesta del usuario: "desde luego para contabilidad"). *(14-oct, P3)* La empresa hoy no usa ni SIRE ni PLE; **se elige SIRE** (§3.8). §3.8. |
| N11 | **Rentabilidad:** cobrado contra gastado real, por salida, reserva, tour y mes, más estimado contra real. Siempre **sin IGV** en ambos lados. §3.9. |
| N12 | **Vista del guía y calificación del cliente:** fases posteriores (§3.10). |
| N14 | *(14-oct)* **Tour con IGV mezclado = una línea por tipo de IGV.** Si todos los servicios del Incluye tienen el mismo tipo (lo normal), el tour es una línea. Si hay mezcla, se carga como **una línea por tipo de IGV**, con el precio repartido **en proporción al costo estimado de cada grupo** (el criterio del reparto "por costo" del plan anterior, aplicado solo entre grupos de IGV). En pantalla, en la salida y en la rentabilidad se ve como un solo tour; la factura sale separada **sin cambiar `ReservaFacturacionController`**, que ya agrupa por categoría y tipo de IGV. §3.1. |
| N15 | *(14-oct, revisión de mayoristas)* **El tour tiene un campo "Uso": `venta` o `solo_itinerario`.** Los paquetes de mayorista usan tours del catálogo **solo como itinerario y fotos** (`opcion_mayorista_tours.paquete_plantilla_id`, leído por `AlternativaPdfService` vía `paqueteItinerario`, nunca vía sus servicios ni su precio). En `agencia-demo` hay 6 así (Arribo a Cusco, Valle Sagrado, Machupicchu, City Tour Cusco, Retorno, City Tour + Canal de Panamá), sin precio ni servicios. Un tour `solo_itinerario` no exige precio, costo ni IGV, **no se puede cargar como línea en el cotizador** (no genera salida) y queda **fuera de la conversión** de §3.11. Backfill: vinculado a `opcion_mayorista_tours` y sin `precio_venta_final` → `solo_itinerario`; el resto → `venta`. Campo explícito (no deducido de "no tiene precio") para no confundir un tour nuevo aún sin precio con uno de mayorista. Etiqueta y filtro en el catálogo. |
| N16 | *(14-oct, revisión final)* **El precio del tour varía por temporada** (respuesta del usuario: sí, y las temporadas ya existen en el sistema). Precio base + precios opcionales por temporada; el cotizador elige el que corresponde **según la fecha del día del tour**. §3.12. |
| N17 | *(14-oct, revisión final)* **Cuatro mejoras de operación aprobadas:** órdenes de servicio a proveedores (y lista de pasajeros al operador de un endoso), avisos al cancelar una reserva, reprogramar una salida completa, y una bandeja única de pendientes. §3.13-§3.14. |
| N13 | **Convivencia:** las cotizaciones y reservas existentes (con el tour desarmado en líneas) siguen funcionando como hoy. El modelo nuevo aplica a lo que se cargue desde que se despliegue. §3.11. |

## 3. Diseño

### 3.1 El tour (`paquetes_plantilla`)

Columnas nuevas o reutilizadas:

| Columna | Uso |
|---|---|
| `precio_venta_final` | Se reutiliza como **precio adulto nacional** |
| `precio_venta_nino` | Niño nacional (vacío = adulto) |
| `precio_venta_adulto_ext`, `precio_venta_nino_ext` | Extranjero (vacío = nacional) — N2 |
| `costo_estimado_persona`, `costo_estimado_persona_ext` | Costo estimado por persona (ext vacío = nacional). **Se calcula como la suma de los costos del Incluye** (§3.2) cuando sus servicios los tienen; si no, se escribe a mano. Un solo costo para adulto y niño; si hace falta distinguir, se agrega después. |
| `moneda` | `PEN` \| `USD`, por defecto `PEN` |
| `tip_afe_igv`, `destino_tributario` | IGV **por defecto** del tour: lo heredan los servicios del Incluye que no tengan uno propio, y es el único IGV de un tour sin servicios detallados (N5) |
| `modalidad` | `compartido` \| `privado`. Un privado genera su propia salida (§3.6); su precio por rango de pasajeros queda para después (§5). |
| `pax_minimo_salida` | Mínimo para salir: indicador de la salida ("7 / 6 mínimo"), ya no divisor de costos |
| `uso` | `venta` \| `solo_itinerario` (N15). Las validaciones de precio, costo e IGV aplican solo a `venta`. |

Pantalla del tour: precios y costos (nacional/extranjero), **margen en vivo**, IGV,
modalidad, mínimo, y la lista del Incluye (§3.2) con la calculadora opcional (N4).

`ajuste_redondeo` se retira (se mantiene la regla del plan anterior: el filtro por nombre de
`AsignacionOperativa::itemsDelRango()` se queda para las líneas antiguas).

### 3.2 El Incluye estructurado (N3)

Hoy existe `paquete_plantilla_items` (servicio con `proveedor_tarifa_id` o `guia_tarifa_id`).
Se reutiliza como lista del Incluye:

| Campo | Uso |
|---|---|
| servicio | Del catálogo de servicios (`servicios`/`destino_servicio`), obligatorio |
| `proveedor_id` sugerido | Opcional. Se propone al operar la salida; se puede cambiar. |
| `proveedor_tarifa_id` / `guia_tarifa_id` | Opcional, solo para la calculadora (N4). **No define el precio.** |
| `costo_estimado_persona`, `costo_estimado_persona_ext` | Opcional. Costo estimado por persona de este servicio (la calculadora lo sugiere desde la tarifa; para servicios por grupo, costo ÷ mínimo para salir). Sirve para el costo del tour, para el reparto entre grupos de IGV (N14) y para comparar estimado contra real **por servicio** en la rentabilidad. |
| `tip_afe_igv`, `destino_tributario` | Tipo de IGV de este servicio según dónde se presta (N5). Vacío = el del tour. |
| `orden` | Orden en el PDF |

**Cómo se carga un tour en la cotización (N14):**
1. Se agrupan los servicios del Incluye por tipo de IGV.
2. **Un solo grupo** (lo normal): una línea con el precio completo y ese IGV.
3. **Varios grupos:** una línea por grupo. El precio total (según pasajeros, §3.3) se reparte en proporción al costo estimado de cada grupo; los centavos sobrantes van al grupo de mayor costo, para que la suma sea exacta. Si algún servicio no tiene costo estimado, el tour no puede usarse con IGV mezclado hasta completarlo (la pantalla del tour lo avisa).
4. Las líneas del mismo tour comparten la carga (mismo tour, día y salida) y se muestran juntas como un solo tour.

Ejemplo: tour de S/ 100 con servicios exonerados que cuestan 60 y gravados que cuestan 20 →
línea exonerada S/ 75.00 y línea gravada S/ 25.00 (con IGV incluido).

Usos:
- **PDF:** "Incluye: transporte, guía, almuerzo…".
- **Salida:** cada ítem es algo que operaciones tiene que reservar o asignar para ese día, con cuántos pasajeros.
- **Guía:** cada ítem es un check por pasajero.
- **Gasto y calificación:** cada ítem es una categoría ("almuerzo de la salida X" → factura del restaurante Y → 5 estrellas).

**Excluir un servicio para una reserva** (por ejemplo, "no quieren el almuerzo"): en la
cotización se marca ese ítem como no incluido para esa línea y se ajusta el precio a mano. La
salida descuenta a esos pasajeros del conteo del almuerzo.

### 3.3 Extranjeros (N2)

- `cotizacion_pasajeros` hoy solo tiene `tipo_pax` y `edad`. **Se agrega `residencia`** (`nacional`/`extranjero`), por defecto nacional. No es la nacionalidad: un extranjero residente suele pagar como nacional. `reserva_pasajeros.nacionalidad` sigue existiendo para los datos del pasajero.
- Precio de la línea del tour = Σ por pasajero del precio que le toca (adulto/niño × nacional/extranjero). Mismo criterio para el costo estimado.
- **Impuestos (resuelto con el contador, P2):** la residencia **no cambia el IGV** de los tours. El tipo de afectación depende de dónde se presta el servicio (N5): en la Amazonía, exonerado; fuera de zona exonerada, 18 %, aunque el cliente sea extranjero no domiciliado. La residencia solo cambia el **precio**. Los hoteles siguen con su propio tratamiento en sus líneas, como hoy.

### 3.4 Combos y hoteles (N7)

**Combo sin hotel** (Lamas + Laguna Azul + Alto Mayo):
- Al cargarlo se crea **una línea por tour**, cada una en su día; cada una va a la salida de su tour y fecha.
- Precio del combo, una de dos:
  - **Suma de tours − descuento** (como hoy en el catálogo; hoy el descuento **no llega** a la cotización, se corrige: E3 del plan anterior).
  - **Precio fijo por persona del combo.**
- En ambos casos el precio del combo se reparte **entre las líneas de sus tours en proporción al precio de lista de cada tour**. Es el único reparto que queda, y hace falta para que cada salida sepa cuánto se cobró por ella (rentabilidad). Las líneas quedan agrupadas visualmente como "Combo X".

**Combo con hotel** (por ejemplo, 3 días con 2 noches):
- **En el cotizador:** el combo se vende "por persona, sin alojamiento" y el hotel entra **aparte**, desde la matriz de hoteles (como hoy), con su categoría, tipo de habitación y noches. Al cliente se le puede mostrar el total junto, pero internamente son líneas distintas.
- **Por qué nunca dentro del mismo precio:** el hotel tiene otro proveedor, otra factura de compra, otra operación (no va a una salida) y posiblemente otro IGV (hospedaje a extranjeros). Mezclarlo rompe la rentabilidad, la contabilidad y la factura.
- **Enlatado para la web** (fase web): precio por persona **según tipo de habitación** ("desde S/ X en doble"), guardado como **dos componentes** (combo + hotel por tipo de habitación) que se muestran sumados. Así nunca hay que repartir un precio entre hotel y tours.

### 3.5 Servicios sueltos (cotización sin plantilla)

Respuesta a "si quiero armar mi cotización con servicios sueltos con precio de proveedor, ¿cómo
queda?": **como hoy.**

| Tipo de línea | Precio | Costo estimado | Operación | Costo real |
|---|---|---|---|---|
| **Tour (plantilla)** | Precio del tour × pasajeros | Costo estimado del tour | En la **salida** (tour + fecha) | Gastos asignados a la salida |
| **Servicio suelto** (transfer, almuerzo, entrada…) | Venta de la tarifa del proveedor, como hoy | Costo de la tarifa | En la **reserva** (Reporte Operativo por línea, como hoy) | Gastos asignados a la reserva |
| **Hotel** (matriz) | Como hoy | Como hoy | En la reserva | Gastos asignados a la reserva |
| **Pasaje aéreo, mayorista, opcional, manual** | Como hoy | Como hoy | Como hoy | Gastos asignados a la reserva |

Es decir, **todas las líneas tienen un costo estimado** (la del tour, el del tour; la suelta,
el de su tarifa) y **todas pueden recibir costo real** desde Compras. La diferencia es dónde
se operan: el tour, en su salida compartida; lo demás, en la reserva.

Se mantiene la corrección E2 del plan anterior, que afecta justamente a los sueltos: al
cambiar los pasajeros se actualiza la venta pero no el costo de las líneas por persona.

### 3.6 Operación: la salida como centro (N8)

`salidas_operativas` ya existe (tour + fecha, guía, cupo, vehículo como texto, estado).
Cambios:

- **Qué agrupa:** las **líneas de tour** de las reservas de ese tour y fecha (tours `compartido`). Un tour `privado` crea su propia salida con una sola reserva. Hoy agrupa líneas de proveedor `compartido`; las reservas antiguas siguen así (§3.11).
- **Pasajeros:** todos los de las reservas enganchadas, con infante, residencia y datos de la reserva. Indicador **"7 / 6 mínimo"**.
- **Servicios a reservar:** el Incluye del tour, cada ítem con el proveedor asignado para esa salida (por defecto el sugerido) y la cantidad de pasajeros que lo toman (descontando las exclusiones de §3.2).
- **Vehículos:** **varios por salida** (las reservas pueden ir en vehículos y zonas de recojo distintas), cada uno con proveedor o vehículo propio, chofer, capacidad y las reservas que lleva.
- **Guía:** uno por salida (o más, si el grupo es grande).
- **Estado:** programada → confirmada → realizada / cancelada. (Una salida endosada no es un estado: es **otra salida**, operada por un tercero; ver el punto Endoso.)
- **Endoso:** una salida puede estar **operada por un tercero** (`salidas_operativas.operador_proveedor_id`). Endosar = mover líneas de tour a esa salida; revertir = devolverlas. El costo real es la factura del operador en Compras, asignada a esa salida. Recibir endosos = reservas con origen `endoso_recibido` que suman a la salida propia. Diseño completo (versión 2, 14-oct): `plan-endoso-pasajeros.md`, fases E1-E2.
- **Quién dio cada servicio (pedido del usuario, 14-oct):** pestaña "Proveedores" de la salida, por servicio del Incluye y por vehículo: **asignado** (planificado en la salida) contra **facturado** (proveedor de las compras asignadas a ese servicio de la salida, §3.9b). Marca ⚠️ si el que facturó es otro proveedor, o si un servicio no tiene ninguna factura (falta cargarla). Más adelante suma el check del guía (§3.10) como confirmación de que el servicio se dio. **Ficha del proveedor:** historial de salidas y reservas atendidas, monto facturado por periodo y, cuando exista, calificación promedio.
- **Reporte Operativo:** muestra los tours **por salida** (una fila por salida con sus servicios, vehículos y guía) y los sueltos **por línea**, como hoy.

### 3.7 Compras y gastos (N9)

**Compras es un módulo del sistema base**, para todos los giros: es el módulo "Compras" que ya
está en la lista de próximos módulos de `CLAUDE.md`.

`compras` (comprobante recibido):
- Proveedor (de la tabla única `proveedores`, §3.7c).
- Tipo (factura, boleta, ticket, recibo por honorarios, nota de crédito recibida).
- Serie, número, fecha de emisión, moneda y tipo de cambio.
- Base gravada, IGV, exonerado, inafecto, otros y total.
- Adjuntos (PDF/XML).
- Estado de validación SUNAT.
- **Condición: contado o crédito** (pedido del usuario, 14-oct). A crédito lleva fecha de vencimiento (o cuotas).
- **Pagos** (`compra_pagos`): uno o varios por comprobante (pagos parciales), cada uno con fecha, monto y **origen**: **Caja** (efectivo, con su egreso y sesión abierta, como hoy) o **Banco** (transferencia o depósito, **sin** sesión de caja, con cuenta bancaria de origen y número de operación; ver C2 en §5c). Estado derivado: **pendiente / parcial / pagado**, y **vencido** si pasó la fecha sin pagar.
- **Cuentas por pagar:** listado por proveedor con lo pendiente, lo vencido y lo que vence en los próximos días.
- **Regla para la rentabilidad:** el costo cuenta en la fecha del comprobante, **esté pagado o no**. Pagar no cambia el margen, solo la deuda con el proveedor. (Si contara solo lo pagado, una salida con facturas a crédito parecería más rentable de lo que es.)

**Gasto sin comprobante** (pedido del usuario, 14-oct: paseo a caballo con una persona de la
comunidad, propina, gasto extra en el lugar): se registra igual, marcado **"sin
comprobante"**, con:
- quién recibió el pago (nombre, y DNI si lo tiene), descripción, monto, fecha;
- **foto opcional** de un vale o recibo interno firmado por quien cobró (respaldo interno);
- salida o reserva y servicio, como cualquier gasto.

Cuenta para la rentabilidad, **no** entra al Registro de Compras y **no da crédito fiscal**.
A confirmar con el contador: (a) cómo trata él estos gastos (normalmente no son deducibles para
el impuesto a la renta); (b) si en algún caso conviene que la agencia emita una **liquidación de
compra** (comprobante que el propio comprador emite cuando el vendedor no tiene RUC) y si aplica
a servicios como el paseo a caballo. Si aplica, se registra como compra con comprobante.

`compra_asignaciones` (propia de Agencia, centro de costo):
- `compra_id`, a qué se carga (**salida**, **reserva** o **general**), el ítem del Incluye o categoría (transporte, almuerzo…), monto y pasajeros.
- **Un comprobante puede repartirse** entre varias salidas o reservas. Por ejemplo: la factura **mensual** del transportista, o una factura del restaurante por **dos grupos**. La suma de las asignaciones tiene que dar el total del comprobante.

**Reparto de un comprobante (P6, resuelta 14-oct):**
- **Repartir por pasajeros no sirve como regla general:** el transporte se cobra por vehículo y día (una salida de 4 pax y otra de 10 cuestan una minivan cada una), el almuerzo por persona, el guía por día.
- **Criterio por defecto: según el costo estimado** de cada salida para ese servicio (la copia guardada al cotizar, §3.9b), que ya refleja cómo cobra cada servicio. Si el total real difiere de la suma estimada, la diferencia se reparte en la misma proporción.
- **Salidas sugeridas automáticamente:** las del periodo del comprobante en las que ese proveedor estuvo **asignado** (pestaña "Proveedores" de la salida, §3.6); se pueden quitar o agregar.
- **Otros criterios:** por pasajeros, partes iguales o manual. Cada monto se puede corregir a mano.
- Mismo mecanismo para repartir entre **reservas** (factura de un mayorista por varias reservas, liquidación de comisiones de la forma B, §3.7d).

**Ayudas para registrar:**

| Ayuda | Factibilidad |
|---|---|
| Escribir el RUC → razón social | Fácil (consulta de RUC) |
| Validar que el comprobante existe y está aceptado en SUNAT | Factible (consulta de validez de comprobantes; usa credenciales de la empresa, que el sistema ya guarda por tenant en `SunatConfig`) |
| Traer los montos solos | **Factible pero a verificar:** vía **SIRE** (propuesta del Registro de Compras electrónico, que SUNAT arma con los comprobantes recibidos). La idea final: importar la propuesta del periodo, y el usuario solo asigna cada comprobante a su salida o reserva. **Antes de comprometerlo hay que revisar el API de SIRE** (P3). |
| Traer el detalle (qué platos, cuántos) | Solo con el XML que manda el proveedor o que sube el usuario. SUNAT no lo da. |

### 3.7b Rendiciones: dinero entregado a rendir cuenta (14-oct-2026)

Caso real del usuario: la dueña entrega dinero al guía para gastos del tour; al final el guía
reporta lo gastado, con sustento, y devuelve el vuelto. Módulo **Rendiciones** ("entrega a
rendir cuenta"), del sistema base (sirve para guía, chofer, la propia dueña o cualquier
responsable), con vínculo opcional a salidas o reservas de Agencia.

**Principio:** el dinero entregado **no es un gasto**. Sigue siendo de la agencia, en manos del
responsable. El gasto aparece cuando se usa y se sustenta.

**Flujo:**
1. **Entrega:** egreso de **Caja** tipo "entrega a rendir" → abre una `rendicion` (responsable, monto entregado, salida/s o reserva/s vinculadas, estado `abierta`).
2. **Gastos:** se registran **contra la rendición** y son **los mismos registros de Compras** (§3.7): con comprobante (factura, boleta, ticket: entra a Compras como pagado "con fondo a rendir") o **sin comprobante** (con foto del vale). Cada uno se asigna a su salida o reserva y servicio, así que llegan solos a la rentabilidad y, si tienen comprobante, al Registro de Compras. Los registra el responsable desde el celular (si tiene acceso, P5) o la oficina con los papeles.
3. **Liquidación:** entregado − gastado =
   - **vuelto** → ingreso de Caja "devolución de rendición";
   - **exceso** → egreso de Caja "reembolso al responsable".
4. **Aprobación y cierre:** la dueña (o quien tenga el permiso) revisa el sustento y aprueba. No se cierra con saldo pendiente.

**Control:** listado de rendiciones abiertas con antigüedad ("Carlos: S/ 500 sin rendir hace
5 días") e historial por responsable. Una rendición puede cubrir varias salidas (tour de
varios días).

**Reglas:**
- Las facturas deben emitirse **a nombre de la agencia** (su RUC), no del responsable; si no, no dan crédito fiscal.
- Un gasto sin sustento lo decide quien aprueba: se acepta como gasto sin comprobante, o se descuenta al responsable (queda como saldo por devolver).
- Reutiliza la Caja existente (movimientos con aprobación y correcciones auditadas vía `CashCorrectionService`); ningún movimiento de dinero es silencioso.

Ejemplo: entregado S/ 500; gastos: almuerzo 168 (factura), paseo a caballo 60 (vale), entradas
60 (tickets), agua y snacks 42 (boleta) = 330 → vuelto S/ 170 devuelto a Caja; rendición
aprobada y cerrada.

### 3.7c Una sola tabla de proveedores (P4, idea del usuario, 14-oct-2026)

**Problema:** hoy hay dos catálogos que no se conocen. `suppliers` (sistema base, Caja: nombre,
documento, teléfono) y `proveedores` (Agencia, completo: razón social, RUC, dirección, tipo,
servicios, tarifas; 41 en `agencia-demo`). El buscador de Caja no encuentra a los proveedores de
Agencia. Compras necesita que una factura apunte a **un** proveedor.

**Decisión:** **una sola tabla `proveedores` en el sistema base**, con el esquema actual de
Agencia, disponible en todos los tenants. Se retira `suppliers`; Caja, Compras, Rendiciones y
todo Agencia usan `proveedores`. Un dato existe una sola vez: **nada que sincronizar**.
(Se descartó la alternativa de dos tablas vinculadas, base + perfil de Agencia, porque obligaba
a sincronizar siempre.)

Es viable porque `proveedores` no depende de ninguna tabla exclusiva de Agencia (servicios y
tarifas apuntan hacia ella; `tipo_id` usa el catálogo central `proveedor_tipos`, que ya tiene
`giro`).

**Sin identificador de giro ni de origen por fila:**
- Cada tenant tiene su propia base y un solo giro: un campo "giro" por fila repetiría lo que el sistema ya sabe.
- No se marca "dónde se creó" (Compras o Agencia): separaría de nuevo las listas y duplicaría proveedores. **Un proveedor es uno**, se cree desde donde se cree.

**Separación por lo que ofrece (tipo), no por origen:**
- **Turístico** = tiene un tipo turístico de `proveedor_tipos` (Hotel, Transporte, Mayorista, Guía, Operador de turismo, Atractivo/Actividad local). Hoy los 41 de `agencia-demo` tienen tipo.
- **General** = sin tipo turístico, o con un tipo general ("Insumos", "Mercadería"). Ejemplo: la distribuidora de agua y gaseosas.
- Si un proveedor general empieza a dar servicios para los tours, se le asigna un tipo turístico y aparece en el costeo, sin crearlo de nuevo.

| Pantalla | Proveedores que muestra |
|---|---|
| Cotizador, tours y costeo, reservas, Reporte Operativo, Venta Directa, opciones de mayorista | Solo turísticos |
| Agencia → Proveedores | Turísticos por defecto, con "ver todos" |
| Compras, Caja, Rendiciones | Todos |

El filtro vive **en un solo punto del backend** (por defecto turísticos; Compras, Caja y
Rendiciones piden todos), no pantalla por pantalla (hoy unas 9 pantallas de Agencia cargan la
lista).

**Formularios, misma tabla:**
- **Agencia → Proveedores:** el formulario completo de hoy.
- **Compras → Nuevo proveedor:** rápido: RUC → razón social y dirección automáticas (consulta RUC).
- En otros giros, el formulario muestra solo lo que les sirve.

**Desventajas y mitigaciones:**

| Desventaja | Mitigación |
|---|---|
| **Un RUC puede ser varios proveedores** (cadena de hoteles: un RUC, varios establecimientos) | El RUC **no es único** (por ahora; ver "Establecimientos" abajo). Al crear uno con RUC existente, aviso con los existentes, su ciudad y dirección: "¿es otro establecimiento o el mismo?". Cuentas por pagar y Registro de Compras agrupan por RUC. |
| **Proveedores sin documento** (17 de 41 en `agencia-demo`): no se unen por RUC y pueden duplicarse por nombre | Al registrar su **primera compra**, se pide completar el RUC. Listado "proveedores sin documento" para limpiar. Los referenciales (`es_referencial`, solo para costear) quedan fuera. |
| **Datos incompletos entrando al catálogo de Agencia** (proveedor creado rápido desde Caja o Compras) | Filtro por tipo turístico, etiqueta "datos incompletos" y permisos claros de quién crea proveedores y desde dónde. |
| **Movimientos de Caja pasados apuntan a `suppliers`** (`counterparty_type = proveedor`, `counterparty_id`) | La migración remapea los ids (unión por documento). Caja ya guarda copia del nombre y documento en cada movimiento. Local: `suppliers` vacía; **revisar producción**. |
| **Mover una tabla de Agencia al sistema base** (riesgo de migración; ya hubo un error real con migraciones de verticales) | Migración del sistema base que crea la tabla **solo si no existe** (tiendas, créditos); migraciones viejas de Agencia protegidas para no fallar al crear una agencia nueva. Test que provisione un tenant de cada giro (patrón de `MigrateVerticalesPendientesTest`). Respaldo antes del despliegue. |
| **Cambios para Agencia afectan a todos los giros** | Columnas opcionales que otros giros no muestran; más cuidado al probar. |
| **Columnas que otros giros no usan** (margen por defecto, fotos, redes) | El formulario de cada giro muestra solo lo suyo. |

**Establecimientos de un mismo RUC (decidido 14-oct, en dos pasos):**

El usuario propuso el modelo más correcto: un proveedor = un RUC con su dirección fiscal, y
debajo sus **sucursales** (establecimientos anexos), cada una con dirección y ciudad. Se
evaluó y se decidió **no hacerlo todavía**, por costo: el módulo de hoteles está armado sobre el
proveedor (`proveedor_alojamiento_detalle` es uno por proveedor; amenidades, fotos, tarifas,
`OpcionHotel`, reglas de cancelación, pagos y cronograma de pagos cuelgan de `proveedor_id`).
Pasar a sucursales obliga a mover todo eso a la sucursal (matriz de hoteles, fotos del PDF,
reservas, reasignación de hotel): varios días sobre algo que funciona. Además, el caso es poco
frecuente hoy: 0 RUC repetidos en `agencia-demo`; los hoteles de cadena de los paquetes de
mayorista no son proveedores (`OpcionHotel` con nombre propio); y la ciudad de cada servicio ya
se sabe por su destino (`destino_servicio`).

- **Paso 1 (fase 3):**
  - el proveedor guarda la **dirección fiscal** (de la consulta RUC) y la **dirección del local** si es distinta;
  - dos hoteles de una cadena siguen siendo dos proveedores con el mismo RUC, con el aviso al crearlos y la **ciudad visible** en listas y buscadores ("Casa Andina — Tarapoto");
  - en Compras, al escribir un RUC con varios establecimientos, se elige cuál o **"General"** (factura de la oficina central);
  - Compras y el Registro de Compras agrupan por RUC.
- **Paso 2 (solo si las cadenas se vuelven frecuentes):** modelo de sucursales del usuario (proveedor único por RUC + establecimientos con hoteles, tarifas y fotos por establecimiento). El paso 1 no lo impide: los proveedores con el mismo RUC se convierten en sucursales de uno solo.

**El destino no se usa como regla de unicidad** (se evaluó "RUC + destino"): el destino es de
cada servicio, no del proveedor; hay proveedores en varios destinos (Turismo Selva: 3, Shamiro
Tours: 2 en `agencia-demo`); puede haber dos establecimientos del mismo RUC en el mismo destino;
y los proveedores generales de Compras no tienen destino. Sirve solo para mostrar.

**Guías:** el recibo por honorarios de un guía es una compra, así que el guía necesita
existir como proveedor. Vínculo opcional `guias.proveedor_id`, que se crea con los datos del
guía al registrar su primer recibo.

**Personas sin RUC** (paseo a caballo con alguien de la comunidad): no se crean como
proveedor; el gasto sin comprobante guarda nombre y DNI en el propio gasto (§3.7).

**Cambios en Caja:** `CashMovementController` (buscador de contraparte y validación del
proveedor) y la pantalla de catálogo de proveedores de Caja pasan a `proveedores`.

**Estimado:** ~1.5 días al inicio de la fase 3.

### 3.7d Mayoristas: pagos, factura posterior e incentivos (14-oct-2026)

**Lo que no cambia:** el mayorista es un proveedor de tipo "Mayorista" en la tabla única
(turístico); venta (opciones, hoteles por tipo de habitación en `OpcionHotelTarifa`, salidas
con cupo, mayorista elegido, reasignación 12h, itinerario del PDF) y operación (lo opera el
mayorista: sin salida propia, se sigue por reserva).

**Modelo de negocio (respuesta del usuario):** **neto**. El mayorista factura a la agencia el
precio neto; la agencia factura al cliente el precio completo, con impuesto e incluyendo su fee
o ganancia. El modelo comisionable (el mayorista factura al pasajero) **no** se usa por ahora.

**Tablas existentes que se retiran:** `cronograma_pago_proveedor` (programado) y
`pago_proveedor` (pagado) se crearon en julio (fila 11g) para esto, pero **ningún código las
usa y están vacías** en `agencia-demo` (verificar producción). Además están atadas a
`opcion_mayorista` (cotización), cuando lo que se paga es la **reserva**. Compras las absorbe;
se retiran sin riesgo de datos.

**Cómo se paga a un mayorista (respuesta del usuario):** en partes antes del viaje y **la
factura llega días después de terminar de pagar**. Siempre hay pagos sin factura:

```
Reserva aceptada (viaje 20-dic, USD 2,400)
  ├─ 15-oct  Depósito ............. USD 720    ← sin factura
  ├─ 20-nov  Saldo ................ USD 1,680  ← sin factura
  └─ 25-nov  Llega la factura ..... USD 2,400  ← absorbe los 2 pagos
```

**Estructura: la de la auditoría arquitectónica §12 (01-sep-2026), integrada a Compras.** La
auditoría ya había decidido reemplazar las dos tablas muertas por la cadena espejo del lado
cliente (mismo patrón que `ReservaVenta`/`AdvanceApplicationService`). Se adopta con sus
nombres, para que haya un solo diseño:

```
ObligacionProveedor        lo que se le debe a un proveedor por una reserva (nace al aceptar)
  └── CuotaProveedor        partes programadas (depósito, saldo) con vencimiento
PagoProveedor              un pago real (Caja o Banco); PUEDE CUBRIR VARIAS RESERVAS
  └── AplicacionPagoProveedor   cuánto de ese pago va a cada obligación o cuota
Compra (comprobante)       la factura que llega después; se vincula a las obligaciones que cubre
```

- **Decisión pendiente de la auditoría (§17, "¿hace falta el nivel de cuota?") — resuelta:** sí. El usuario confirmó que paga en partes antes del viaje.
- **Pago consolidado** (caso del brief §21 de la auditoría: un pago de USD 6,500 que cubre 3 reservas del mismo proveedor): un `PagoProveedor` con varias `AplicacionPagoProveedor`.
- Esta sección reemplaza la "Fase 4 — Pagos a proveedor" de la auditoría (§19), que pasa a ejecutarse dentro de la fase 3 de este plan.
- **Choque de nombres:** ya existe un modelo `PagoProveedor` (tabla vieja `pago_proveedor`, sin uso). Al implementar, **retirar primero** ese modelo y las dos tablas viejas, y recién después crear el `PagoProveedor` nuevo (tabla `pagos_proveedor`). El modelo viejo tiene un comentario que lo advierte.

Lo que se agrega a Compras, en esos términos:
1. **Cronograma de pagos por reserva y proveedor** (`ObligacionProveedor` + `CuotaProveedor`): cuotas con monto, moneda y vencimiento. El proveedor puede tener **condiciones por defecto** (por ejemplo, "30 % al reservar, saldo 30 días antes del viaje") para generarlo al aceptar la reserva; siempre editable. Alertas de vencimiento.
2. **Pagos a cuenta (sin factura todavía)** (`PagoProveedor` + `AplicacionPagoProveedor`): contra una o varias reservas del mismo proveedor, desde Caja o banco, en su moneda y con tipo de cambio. Quedan "a cuenta" del proveedor.
3. **Aplicación a la factura:** cuando llega la factura (la compra), se le aplican los pagos a cuenta de esa reserva; el estado de la compra queda pagada, parcial o pendiente. Una factura que llega sin pagos previos funciona como cualquier compra (§3.7).
4. **Dólares:** compra y pagos en USD con su tipo de cambio (módulo de tipo de cambio SUNAT/SBS existente). La rentabilidad usa el tipo de cambio de la factura; la **diferencia de cambio** respecto a los pagos queda visible para el contador, sin contabilizarla.

**Vista "cobrado contra pagado" por reserva:** cobrado al cliente (adelantos y ventas ya
existentes) junto a lo pagado y lo pendiente con el mayorista, con aviso si un pago al mayorista
vence antes de que el cliente termine de pagar.

**Casos que encajan sin nada extra:**
- **Cancelación con penalidad:** la penalidad del mayorista es una compra asignada a la reserva.
- **Devolución del mayorista:** nota de crédito recibida; el dinero vuelve a Caja o banco.
- **Reasignación de mayorista:** la penalidad del primero y la factura del segundo quedan en la misma reserva; el margen real muestra lo que costó el cambio.

**Comisiones e incentivos de mayoristas y aerolíneas** (respuestas del usuario, 14-oct). **Las
dos formas existen en la práctica**, según el proveedor:
- **A. El proveedor emite una nota de crédito a la agencia:** compra de tipo nota de crédito recibida, asignada a la reserva o reservas que la generaron → **baja el costo** y sube el margen real.
- **B. La agencia emite una factura de comisión al proveedor:** confirmado con **algunos mayoristas y con aerolíneas o consolidadores**. Es una **venta** de la agencia → **ingreso aparte**.

Configuración en la ficha del proveedor: **"Comisiones: me emite nota de crédito / yo le
facturo"**, para proponer el camino correcto.

**Detalle de la forma B:**
0. **Tipo de IGV configurable (decidido 14-oct):** en la ficha del proveedor, **tipo de afectación por defecto** de la factura de comisión (gravado 18 %, exonerado, inafecto o exportación de servicios) y si el monto que informa el proveedor **incluye IGV o no**. Al emitir, el selector viene con ese valor elegido, pero **siempre muestra todas las opciones** para cambiarlo en un caso puntual. Sin valor en el proveedor, se usa un valor por defecto de la configuración de la agencia. El contador solo define qué valor dejar en cada proveedor.
1. **Se emite con la facturación electrónica existente** (venta enviada a SUNAT) a nombre del proveedor como **cliente**. Clientes y proveedores siguen siendo tablas distintas: al emitir desde la ficha del proveedor, se busca el cliente por RUC y, si no existe, se crea con los datos del proveedor.
2. **Una factura puede cubrir varias reservas** (liquidación mensual del mayorista o consolidador): se **reparte entre las reservas**, igual que `compra_asignaciones`, y la rentabilidad de cada reserva muestra su comisión.
3. **Cobro:** si se cobra después, queda en **Cuentas por Cobrar** (existente, módulo de Amortizaciones).
4. **Compensación:** el proveedor puede descontar la comisión de lo que se le debe (factura del proveedor 2,400, factura de comisión 120, pago real 2,280). Hay que poder **cruzar** el cobro de la comisión con el pago al proveedor, de modo que ambos queden saldados con el pago neto.
5. **Aéreos:** distinto del **fee de la agencia** que ya existe en el pasaje aéreo suelto (`cotizacion_pasaje_aereo.fee_agencia_monto`, cobrado al cliente, único concepto con IGV). La comisión de la aerolínea es un ingreso facturado a la aerolínea o consolidador, con el mismo mecanismo, repartido entre las reservas con esos pasajes.

En ambas formas, la rentabilidad por reserva lo refleja.

### 3.8 Registros para el contador (N10)

Por periodo (mes), con exportación a Excel y al formato que pide SUNAT:
- **Registro de Ventas:** desde los comprobantes emitidos (`Sale::scopeSoloDocumentosFiscales()`, nunca inferido por prefijo de serie, como ya indica `CLAUDE.md`), con notas de crédito y débito.
- **Registro de Compras:** desde `compras`, sin los gastos sin comprobante.

Son módulos del sistema base (sirven a retail también).

**SIRE, no PLE (decisión 14-oct, P3).** La empresa hoy no lleva ninguno de los dos. SUNAT está
reemplazando el PLE por **SIRE** para los registros de ventas (RVIE) y compras (RCE), así que
construir PLE ahora sería construir algo que se está retirando. Además, SIRE trabaja con una
**propuesta** que SUNAT arma con los comprobantes electrónicos de la empresa: el contribuyente
la acepta o la complementa. Eso encaja con este plan:
1. **Siempre:** reportes de ventas y compras en Excel para el contador (sirven en cualquier régimen, aunque la empresa no esté obligada a registros electrónicos).
2. **SIRE ventas:** comparar la propuesta de ventas de SUNAT con lo emitido en el sistema y mostrar diferencias.
3. **SIRE compras:** importar la propuesta de compras (§3.7); el usuario solo asigna cada comprobante a su salida o reserva.

**Confirmado con el contador (14-oct):** la obligación de llevar registros electrónicos
depende del **régimen tributario y del nivel de ingresos**, y **SUNAT no los presenta por la
empresa**: la empresa (o su contador) genera los archivos desde **su propio sistema**. Matiz
anotado: eso describe el PLE; en SIRE, para ventas y compras, SUNAT entrega una propuesta que
la empresa acepta o complementa, pero la responsabilidad sigue siendo de la empresa. El
contador debe confirmar en la página del SIRE de SUNAT qué aplica a cada empresa, porque la
obligación ha entrado por etapas.

**Alcance que queda:**
- **El sistema genera** el Registro de Ventas y el Registro de Compras: Excel siempre, y el archivo en formato SUNAT para la empresa que esté obligada.
- **El sistema no genera** diario, mayor ni el resto de libros contables: siguen en el sistema del contador, que usa nuestro Excel como insumo.
- **Configuración por tenant:** régimen tributario y "lleva registros electrónicos (sí/no)". Una empresa no obligada ve solo los Excel; una obligada ve además los archivos SUNAT y, cuando exista, la importación SIRE.

**A verificar antes de construir 2 y 3:** el API de SIRE (credenciales, periodos, qué datos
devuelve).

### 3.9 Rentabilidad (N11)

**Siempre sin IGV** en ambos lados: base imponible + exonerado + inafecto de las ventas, contra
lo mismo de las compras. El IGV de las compras es crédito fiscal, no costo. Sin esto, el
margen sale mal.

| Vista | Ingreso | Costo real | Además |
|---|---|---|---|
| **Por salida** | Líneas de tour de las reservas de esa salida (netas de NC) | Gastos asignados a la salida | Estimado contra real por ítem del Incluye; costo real por pasajero |
| **Por reserva** | Todas sus líneas | Gastos asignados a la reserva + su parte de cada salida (por pasajeros) | — |
| **Por tour / destino / mes** | Suma de salidas | Suma de gastos | Tendencia del margen real; qué tours conviene subir de precio |

### 3.9b Calculadora y estimado contra real: dónde y cómo (14-oct-2026)

**Calculadora (N4)** — ficha del tour, pestaña "Incluye y costos". Una fila por servicio del
Incluye, con tarifa de referencia opcional:
- Botón "Calcular desde tarifas": por persona usa el costo tal cual; por vehículo o día lo divide entre `pax_minimo_salida`; el guía se multiplica por los días del tour.
- Cada costo se puede corregir a mano; sin tarifa, se escribe directo.
- Costo del tour = suma de las filas; margen en vivo. Columna extranjero si el tour tiene precio extranjero.
- **Aviso de desactualización:** si la tarifa vinculada cambió después, el listado de tours marca "costo desactualizado" y la fila ofrece "Actualizar".

**Copia del costo por servicio (snapshot).** Al cargar el tour en una cotización, la línea
guarda `desglose_costo_snapshot` (JSON: ítem del Incluye, servicio, costo por persona
nacional/extranjero y pasajeros que lo toman). Se copia a la reserva al aceptar. **Sin esta
copia, el estimado de las salidas pasadas cambiaría cada vez que se actualiza el costo del
tour.**

**Gasto por servicio.** `compra_asignaciones` lleva, además de la salida o reserva, el
**ítem del Incluye** (o "otros"). Así el real se compara servicio por servicio.

**Estimado contra real en la salida** — detalle de la salida, pestaña "Costos": por servicio,
estimado (suma de las copias de sus reservas) contra real (compras asignadas), diferencia con
⚠️ sobre un umbral configurable (por defecto 10 %), comprobantes vinculados, y aviso de
servicios sin ningún gasto registrado (factura que falta cargar). Abajo: cobrado, margen
estimado y margen real.

**Reporte "Rentabilidad de tours"** — por periodo, tour y destino: salidas, pasajeros,
cobrado, gastado real, margen real y costo por pasajero estimado contra real. Al entrar a un
tour, el detalle por servicio con el promedio real por persona y el botón **"Actualizar costo
estimado del tour"**: pide confirmación, afecta solo a las cotizaciones nuevas y registra
quién y cuándo. Todo sin IGV (§3.9).

### 3.10 Guía y calificación (N12, fases posteriores)

- **Vista del guía:** desde el celular, sus salidas, los pasajeros (con alimentación especial y discapacidad, que ya existen en `reserva_pasajeros`) y un check por pasajero y por servicio del Incluye, más incidencias, y sus gastos y rendiciones (§3.7b) con foto del comprobante. **Acceso con usuario propio (P5, resuelta 14-oct):**
  - **La tabla `guias` queda como está** (tarifas, guía referencial `es_referencial`, referencias desde cotizador, reservas y salidas). No se reemplaza por `users`: hay guías que no son personas (el referencial, solo para costear), guías ocasionales sin cuenta, y `users` es del sistema base mientras `guias` es de Agencia.
  - Se agrega `guias.user_id` (nullable, único). Guía (perfil profesional) y usuario (cuenta de acceso) son cosas distintas, mismo patrón que Proveedor o Cliente.
  - Ficha del guía: **"Dar acceso al sistema"** crea el usuario con los datos del guía ya cargados (solo pide el correo) y rol **"Guía"**; **"Quitar acceso"** desactiva el usuario sin borrar al guía ni su historial. Respeta la regla existente de no otorgar permisos que uno no tiene.
  - Rol "Guía" con permisos mínimos: no ve precios de venta, márgenes, otras reservas ni clientes.
  - **Solo lo suyo:** filtro por registro (salidas con `guia_id` = su guía, sus rendiciones), mismo criterio que la cartera por asesor de Créditos.
  - Pantallas pensadas para celular (Mis salidas, Pasajeros y checks, Mis gastos, Mi rendición). **Registro posterior permitido** (en la Amazonía puede no haber señal): cada check y gasto con su fecha y hora reales. Modo sin conexión, solo si hace falta más adelante.
  - Los guías sin acceso siguen igual que hoy; la oficina registra por ellos.
  - Aviso al celular de salida asignada: reutiliza el push de Créditos (Fase 5).
  - Pregunta de negocio para UmboSystem: si un plan comercial limita usuarios, ¿cuentan los guías?
- **Calificación del cliente:** al cerrar la salida, un link por WhatsApp a la reserva: estrellas por destino, guía, vehículo y cada proveedor del Incluye, más un comentario. Se acumula en la ficha del proveedor y del guía.

### 3.12 Precio del tour por temporada (N16)

**Lo que ya existe:** catálogo central `temporadas` (exclusivo del giro agencia, con
`CentralConnection`), de tipo `fija` o `movil`, con sus fechas por año en
`temporada_ocurrencias` (en `agencia-demo`: Temporada Alta, Fiestas Patrias, Navidad y Año
Nuevo, Semana Santa). `proveedor_tarifas.temporada_id` ya permite tarifas por temporada.

**Hallazgo:** al cargar un tour en la cotización, hoy **no se consulta la temporada**
(`AlternativaItemController` y `ComboExplosionService` no la leen). Es la sospecha del brief
`PEGAR-EN-CLAUDE-CODE-temporada-plantilla.md` (17-ago), nunca cerrada. En `agencia-demo`
ninguna tarifa tiene temporada todavía (0 de 26), así que hoy no produce errores visibles, pero
lo haría en cuanto se carguen.

**Diseño:**
- Tabla `paquete_plantilla_precios`: tour, `temporada_id` y los mismos precios del tour (adulto/niño, nacional/extranjero) más costo estimado opcional. **El precio base** (columnas de §3.1) aplica cuando ninguna temporada cubre la fecha.
- **Al cargar el tour en la cotización:** fecha del día del tour = `fecha_viaje_desde` de la cotización + `dia_referencial` − 1. Se busca qué ocurrencia de temporada cubre esa fecha y se usa su precio; si no hay, el precio base.
- **Temporadas superpuestas** (Fiestas Patrias dentro de Temporada Alta): gana la **más específica**, es decir, la de rango de fechas más corto. Se muestra qué temporada se aplicó ("Precio Fiestas Patrias") y se puede cambiar a mano.
- **Cotización sin fecha de viaje:** precio base, con aviso "sin fecha: se usó el precio base".
- **Combos:** cada línea de tour toma la temporada de **su** día.
- **Cambio de fecha de una cotización en borrador:** aviso "las fechas cambiaron de temporada" con botón **"recalcular precios por temporada"**. No se recalcula solo, para no pisar una negociación.
- **Reserva ya aceptada que se reprograma a otra temporada:** el precio **no cambia** (ya se vendió); solo aviso.
- **Calculadora (§3.9b):** si el Incluye usa tarifas con temporada, calcula el costo estimado de cada temporada con las tarifas de esa temporada.
- **Web (fase posterior):** "desde S/ X" (el menor precio vigente) y la tabla de precios por temporada.

**Pendiente conocido, fuera de este plan:** los **servicios sueltos** tampoco resuelven la
temporada al agregarse (el vendedor elige la tarifa a mano en la biblioteca, donde se ve su
temporada). Se puede aplicar la misma regla más adelante.

**Nota:** `temporadas` es un catálogo **central**: todas las agencias comparten las mismas
temporadas y fechas. Si una agencia necesita temporadas propias, es un cambio aparte.

### 3.13 Mejoras de operación (N17)

**1. Órdenes de servicio a proveedores y lista de pasajeros al operador.** Por cada salida
confirmada, un PDF o mensaje por proveedor con lo que tiene que preparar:
- Restaurante: fecha, cantidad, **alimentación especial** ("9 almuerzos, 1 vegetariano"), hora de llegada.
- Transportista: lista de recojo por zona y hora, por vehículo.
- Entradas o actividades: cantidad por tipo (nacional/extranjero, adulto/niño).
- **Operador de un endoso:** lista completa de pasajeros (nombre, documento, alimentación especial, discapacidad, punto de encuentro). Ver `plan-endoso-pasajeros.md`.

Los datos ya existen (`reserva_pasajeros` con alimentación especial y discapacidad). Envío:
descarga del PDF y botón para compartir por WhatsApp; el envío por correo se apoya en el plan
de envío por correo (en pausa). **Datos personales:** solo lo necesario para el servicio
(Ley 29733), con el consentimiento en las condiciones generales.

**2. Avisos al cancelar una reserva.** El conteo de la salida ya excluye las reservas
canceladas (scope existente). Lo que falta: al cancelar, avisar qué ajustar con los
proveedores ya asignados ("El Mirador: ahora son 6, no 9"; "minivan 2: queda vacía"), y regenerar
las órdenes de servicio. Si un proveedor igual cobra (no-show), ese gasto se asigna a la
reserva cancelada y su rentabilidad muestra la pérdida. Mismo aviso que el endoso.

**3. Reprogramar una salida completa** (lluvia, derrumbe, fuerza mayor). Acción en la salida:
para cada reserva, elegir **reprogramar** a otra fecha (todas juntas por defecto), **endosar** o
**cancelar**. Reutiliza `ReservaController::reprogramar()` por reserva (mueve la línea de tour
a la salida de la nueva fecha, C4), con un motivo común. Avisos a proveedores como en el punto 2.

### 3.14 Bandeja única de pendientes (N17)

El plan genera muchos avisos sueltos. En vez de dejarlos en cada pantalla, se juntan en
**una bandeja de pendientes** (la campana de recordatorios, fila 11f de la hoja de ruta,
pendiente), filtrada por los permisos de cada usuario:

| Pendiente | Origen |
|---|---|
| Salida bajo el mínimo en los próximos días | §3.6 |
| Vehículo, guía o proveedor sin asignar en una salida próxima | §3.6 |
| Pago a mayorista o proveedor por vencer o vencido | §3.7, §3.7d |
| Factura que falta cargar en una salida realizada | §3.6, §3.9b |
| Rendición abierta hace más de N días | §3.7b |
| Costo estimado desactualizado en un tour | §3.9b |
| Cupo excedido (endoso recibido) | `plan-endoso-pasajeros.md` |
| Ajustes pendientes con proveedores (cancelación, endoso, reprogramación) | §3.13 |

Lo urgente también llega al celular, con el aviso push que ya existe en Créditos (Fase 5).
Cada pendiente enlaza a la pantalla donde se resuelve y desaparece solo al resolverse.

### 3.15 Siguiente etapa (anotado, no incluido en las fases)

Útiles, para después de la pausa de evaluación:
1. **Confirmación al cliente:** voucher con hora, punto de recojo y contacto del guía; aviso automático si cambia algo (endoso, reprogramación). Se apoya en el plan de envío por correo.
2. **Inicio con números del negocio:** margen real del mes, cuentas por pagar y por cobrar, salidas de la semana bajo el mínimo.
3. **Estado de cuenta por agencia aliada:** lo que te deben, lo que debes y el saldo compensado (endosos).
4. **Historial de cambios de precio y costo del tour:** quién cambió qué y cuándo.
5. **Temporada en servicios sueltos** (§3.12) y **temporadas propias por agencia** si alguna lo necesita.

### 3.11 Convivencia con lo existente (N13)

- **Cotizaciones y reservas existentes** (65 líneas desde tours en `agencia-demo`): quedan como están, con el tour desarmado en líneas, y siguen operándose como hoy. No se migran.
- **Tours existentes** (14 en `agencia-demo`): primero se marca su `uso` (N15; 6 quedan `solo_itinerario` y no se tocan). Los de `venta` se convierten:
  - el Incluye se arma desde sus `paquete_plantilla_items` actuales (servicio + proveedor sugerido);
  - el costo estimado se propone con la calculadora;
  - el precio es su `precio_venta_final` si lo tiene (6 tours) o la suma de ventas de sus tarifas.

  Un listado muestra la propuesta tour por tour y **el usuario confirma** (como D20 del plan anterior).
- **Reporte Operativo y Salida Operativa** soportan los dos tipos durante la transición: salidas de líneas de tour (nuevo) y líneas de proveedor enganchadas (antiguo).

## 4. Qué se conserva del plan anterior

| Se conserva | Se descarta |
|---|---|
| Precio adulto/niño, infante 0, moneda e IGV del tour | Reparto del precio entre servicios (salvo en combos, entre tours) |
| Corrección del descuento de combos (E3) | Bloques de tour, divisor por línea, `cobro_por`, capacidad |
| Retiro de `ajuste_redondeo` (con el filtro por nombre) | Vehículo de referencia y "vehículo por confirmar" |
| Corrección del costo al cambiar pasajeros en sueltos (E2) | Mínimo para salir como divisor de costos |
| Conversión de los 6 tours con precio, decidida por el usuario (D20) | |
| Indicador "pax / mínimo" en la salida (D26) | |
| Guía costeado por días y costo niño en tarifas (E5, D25), **solo para la calculadora** | |

## 5. Casos revisados

| Caso | Cómo queda |
|---|---|
| Tour compartido | Una línea; operación en su salida |
| Tour privado | Una línea; salida propia. Precio por rango de pasajeros (2 pax: S/ 250 c/u; 6 pax: S/ 150 c/u) **pendiente**, fase posterior |
| Combo sin hotel | Una línea por tour; precio repartido entre tours |
| Combo con hotel | Tours como arriba + hotel aparte desde la matriz |
| Enlatado web con hotel | Dos componentes por tipo de habitación (fase web) |
| Servicios sueltos | Como hoy; operación y costo real por reserva |
| Extranjeros | Precio y costo extranjero opcional por tour; residencia por pasajero |
| Cliente que excluye un servicio | Ítem excluido en su línea + precio ajustado a mano; la salida lo descuenta |
| No llega al mínimo | Indicador "4 / 6"; salir con menos margen o endosar |
| Endoso | Las reservas pasan a una salida operada por un tercero (`operador_proveedor_id`); su factura en Compras es el gasto de esa salida (`plan-endoso-pasajeros.md`) |
| Agencia que no opera (dakamu) | Encaja: el gasto real es la factura del operador por esos pasajeros |
| **Paquete de mayorista** (con sus hoteles por tipo de habitación) | **Sin cambios:** precio en `OpcionHotelTarifa` (con su IGV), salida del mayorista y cupo, mayorista elegido y reasignación (12h), itinerario y fotos en el PDF. No genera Salida Operativa (lo opera el mayorista). Costo real = factura del mayorista en Compras, asignada a la reserva. |
| **Tours usados solo como itinerario de mayorista** | `uso = solo_itinerario` (N15): sin precio, no se cargan en el cotizador, fuera de la conversión |
| **Cotización con mayorista y tour propio** (multi-destino) | El paquete del mayorista se maneja por reserva; el tour propio, por su salida. Conviven sin cruzarse. |
| **Hoteles de Local/Nacional** (matriz) | Sin cambios; siguen como líneas aparte (§3.4) |
| Factura mensual de un proveedor | Se reparte entre salidas en `compra_asignaciones` |
| Gasto sin comprobante | Cuenta para rentabilidad, no para el Registro de Compras |

## 5b. Plan de reversa (cómo volver atrás si no sale como se pensó)

Pedido del usuario (14-oct-2026): poder regresar al funcionamiento anterior si el modelo nuevo
no funciona en la práctica.

**1. Interruptor por tenant: `modo_tours` = `clasico` | `nuevo`** (configuración de la agencia, por defecto `clasico`).
- `clasico`: `desdePlantilla()` desarma el tour en líneas de servicio, como hoy.
- `nuevo`: el tour entra como línea de tour (N6), con salida y costo real.
- **El código actual no se borra:** queda detrás del interruptor. Volver es cambiar la configuración, sin desplegar.
- **El interruptor solo controla cómo se CREA lo nuevo.** Las pantallas, la salida, el Reporte Operativo, la facturación y la rentabilidad **leen siempre los dos formatos** (ya es obligatorio por la convivencia de §3.11). Así, al volver a `clasico`, lo creado en modo nuevo se sigue viendo, operando y facturando sin corromperse.
- Compras, registros contables y rentabilidad no dependen del interruptor: son módulos aditivos que se pueden ocultar por permisos o menú.

**2. Migraciones solo aditivas.** Nada se transforma ni se elimina durante el piloto: columnas y tablas nuevas, `precio_venta_final` reutilizado sin transformarlo, `ajuste_redondeo` y el código viejo se retiran **solo al terminar el piloto con éxito** (mismo criterio que 12g). Cada migración con `down()` probado.

**3. Git por fases.** Una rama por fase, merge a `main` solo verificada. Una fase fallida se revierte sin tocar las demás.

**4. Respaldos.** Además del respaldo diario y la copia fuera del servidor que ya existen en producción, **respaldo manual antes de cada despliegue** de este plan.

**Límite importante:** una factura enviada a SUNAT no se deshace (solo se corrige con nota de
crédito). Por eso **restaurar un respaldo no es el mecanismo de reversa** una vez que hubo
ventas reales: se perdería todo lo demás de esos días. El mecanismo es el interruptor +
lectura de ambos formatos.

**Despliegue en piloto:** `agencia-demo` → un solo tenant real durante 2 a 4 semanas → resto.
Recién después se limpia el código y las columnas viejas.

## 5c. Revisión de choques con procesos existentes (14-oct-2026)

Revisión pedida por el usuario: qué procesos chocan, qué se escapa y si el resultado es más
fácil o más complejo de usar. Cada punto se verificó en el código.

### Choques reales y cómo se resuelven

| # | Proceso existente | Choque | Resolución | Fase |
|---|---|---|---|---|
| C1 | **Facturación múltiple por pasajeros** (`ReservaFacturacionController`: un Sale por subgrupo de pasajeros) | Nunca parte un ítem compartido entre dos Sales; no existe fórmula de reparto (documentado en el propio controller). Una línea de tour cubre a todos los pasajeros: si dos familias de una misma reserva piden factura aparte, la línea del tour no se puede facturar por separado. | La línea de tour **conoce el precio de cada pasajero** (adulto/niño × nacional/extranjero, §3.3): se guarda ese desglose por pasajero y la facturación por subgrupo toma **exactamente** la parte de los pasajeros elegidos. Es una **mejora** sobre hoy (las líneas por persona actuales tampoco se pueden partir). | 1 |
| C2 | **Caja exige sesión abierta del propio usuario** (`CashMovementController::resolverSesionCajaPropia()`, 422 si no hay) | Pagar a un mayorista por **transferencia** desde la oficina o desde casa, o que la dueña entregue dinero a rendir sin abrir caja, queda bloqueado. Además `cuentas_bancarias` es tabla de Agencia (solo para el PDF), no del sistema base. | Los pagos de Compras y Rendiciones admiten **dos orígenes**: **Caja** (efectivo, con sesión, como hoy) o **Banco** (transferencia o depósito, **sin** sesión de caja, con cuenta bancaria de origen y número de operación). Se lleva `cuentas_bancarias` al sistema base, mismo criterio que proveedores (§3.7c). | 3 |
| C3 | **Inicio (Dashboard) y Reporte Operativo** leen `AsignacionOperativa::itemsDelRango()` ("próximos 7 días") | Las líneas de tour del modo nuevo no aparecerían en el Inicio ni en el Reporte Operativo. | `AsignacionOperativa` incluye las salidas de líneas de tour; el Inicio muestra "salidas de la semana". | 2 |
| C4 | **Reprogramar reserva** (`ReservaController::reprogramar()` re-engancha `SalidaOperativa` solo para ítems de proveedor compartido) | La línea de tour no se movería de salida al cambiar la fecha. | `reprogramar()` mueve también las líneas de tour a la salida de la nueva fecha (desengancha de la vieja sin borrarla, igual que hoy). | 2 |
| C5 | **PDF de cotización**: el "Incluye" se arma con los nombres de las líneas (`AlternativaPdfService::incluyePorDestino()`) | Con una sola línea por tour, el Incluye mostraría solo "Laguna Azul Full Day". | Para líneas de tour, el Incluye sale de la **lista estructurada del tour** (§3.2), descontando servicios excluidos. Itinerario y fotos siguen igual (vía `tour_origen_id`). | 1 |
| C6 | **Notas de crédito parciales** (devolución de un pasajero o de una parte) | La rentabilidad por salida tiene que restar la NC de la línea correcta. | La rentabilidad toma el ingreso de cada línea **neto de sus NC**. | 4 |
| C7 | **Ingreso de la salida**: muchas reservas solo tienen adelantos, sin factura aún | "Cobrado" es ambiguo: ¿lo facturado, lo cobrado o lo vendido? | **Ingreso de la salida = precio vendido de sus líneas de tour** (reservas aceptadas, netas de NC), facture o no. Lo cobrado aparece aparte en la vista "cobrado contra pagado" (§3.7d). | 4 |
| C8 | **Módulos nuevos del sistema base en todos los giros** (Compras, Rendiciones, Registros, `proveedores`, `cuentas_bancarias`) | Las migraciones llegan a tiendas y a créditos (`credishirley` en producción). | Migraciones protegidas y probadas con un tenant de cada giro; menús y permisos según giro y plan. | 3, 5 |

### Lo que se estaba escapando

- **Permisos, menú y roles de cada módulo nuevo:** salidas, compras, rendiciones, rentabilidad, registros y rol "Guía". Hay que crear los permisos, sembrarlos en `PermissionsDemoSeeder`, agregarlos al menú dinámico (`menu_items`), asignarlos a los roles por defecto, respetar el gate de permisos y decidir si son módulos contratables del catálogo de plataforma. **Se suma a cada fase** (~0.5 día por fase).
- **Cotizaciones en borrador al activar el modo nuevo:** conservan sus líneas desarmadas; si se les agrega un tour nuevo, conviven los dos formatos en la misma alternativa. Se permite (la lectura soporta ambos), sin conversión automática.
- **Venta Directa:** no se rompe (no usa tours). Oportunidad futura: vender una línea de tour directo, ahora que el tour tiene precio (encaja con los bloques 2-5 pendientes de Venta Directa).
- **Ayuda para el usuario:** módulos nuevos sin guía de uso. Conviene alinearlo con el Centro de Ayuda (diseño cerrado, sin implementar).

### ¿Más fácil o más complejo para el usuario? (evaluación honesta)

| Quién | Cambio | Veredicto |
|---|---|---|
| **Vendedor** | Escribe un precio y carga un tour como una línea; precio para extranjeros; ya no corrige líneas a mano | **Más fácil** |
| **Operaciones** | Una fila por salida con todo lo que hay que reservar; mínimo visible; proveedores asignados contra facturados | **Más fácil** |
| **Guía** | Ve sus salidas y pasajeros, marca checks, registra gastos con foto | Nuevo, simple |
| **Administración / dueña** | Registrar facturas, asignarlas a salidas, pagos a mayoristas, rendiciones, comisiones | **Más trabajo nuevo**: hoy no se hace en el sistema (se hace en Excel, con el contador o no se hace). Es el precio de conocer el margen real y de entregarle los registros al contador. |
| **Sistema en general** | Más módulos y pantallas | **Más grande** |

**Mitigaciones del trabajo administrativo:** reparto sugerido automáticamente (P6),
proveedores y montos que se proponen solos, el guía registra sus propios gastos, y más
adelante la importación SIRE (los comprobantes llegan solos y solo hay que asignarlos).

**Recomendación de adopción:**
1. **Los módulos de la parte "medir lo real" se activan por tenant.** Una agencia puede usar solo las fases 1-2 (precio simple y operación por salida) sin Compras; la rentabilidad muestra claramente "faltan gastos por registrar" en vez de márgenes engañosos.
2. **Entregar por valor y evaluar antes de seguir:** fase 1 (resuelve el problema original) → fase 2 → **pausa para evaluar con uso real** → fases 3-5.

## 6. Fases (estimados gruesos, a afinar al empezar cada una)

> **14-oct-2026:** la fase 1 se ejecuta en **dos sesiones**:
> - **1a — el tour en el catálogo** (precio, Incluye con costo e IGV, calculadora, temporadas, uso, diagnóstico; sin tocar la cotización). Brief: `PEGAR-EN-CLAUDE-CODE-13-fase1a-tour-catalogo.md`.
> - **1b — el tour en la cotización** (interruptor `modo_tours`, línea de tour con temporada, IGV mezclado en líneas, snapshot, residencia, recálculo al cambiar pasajeros, combos con E3, PDF C5, pestaña unificada "Incluye y costos", ajuste de redondeo fuera de la pantalla). Brief: `PEGAR-EN-CLAUDE-CODE-13-fase1b-tour-cotizacion.md` (revisado contra el código, listo).
> - **1c — facturar la línea de tour y convertir los tours** (mezcla de IGV en un comprobante, facturación por subgrupo de pasajeros C1, conversión tour por tour). Borrador: `PEGAR-EN-CLAUDE-CODE-13-fase1c-facturacion-conversion.md`.
>
> **Revisión del brief de la 1b contra el código (14-oct-2026) — hallazgos y decisiones del usuario:**
> - `ReservaFacturacionController::detectarMezclaTributaria()` **bloquea** un comprobante con más de un destino tributario (Amazonía + nacional). N14 suponía que "ya agrupa por IGV": eso solo pasa dentro de un mismo destino. **D1:** un solo comprobante con una línea exonerada y otra gravada, permitido solo entre líneas de una misma carga de tour (se implementa en la 1c).
> - La facturación por subgrupo **nunca parte una línea** entre comprobantes (decisión documentada en ese controlador), así que C1 es un cambio de diseño: pasa a la **1c (D2)**.
> - `CotizacionController::recalcularItemsPorPersona()` solo reprecia líneas `proveedor` con tarifa. La línea de tour debe recalcularse al cambiar pasajeros (agregado a la 1b, junto con E2).
> - El Reporte Operativo y las Salidas no operan la línea de tour hasta la fase 2. **D3:** el modo nuevo se activa solo en `agencia-demo` hasta terminar la fase 2, y el interruptor lo cambia solo el Super-Admin.
> - `clasificarCategoria()` clasificaría la línea de tour como `OTROS`: corregido en la 1b (`TOUR`).
>
> **Fase 1a — HECHA (14-oct-2026, rama `feat/tours-fase1a-catalogo`, sin commitear ni unir a `main`).**
> Qué quedó:
> - **Migraciones** (vertical, solo aditivas, `down()` probado en `sistemafe_test_migrations`):
>   - `paquetes_plantilla`: precios niño y extranjero, costo estimado (nacional/extranjero), moneda, IGV por defecto, modalidad, mínimo para salir, `uso`. Backfill de `uso` (N15).
>   - `paquete_plantilla_items`: **`destino_servicio_id`** (ver hallazgo 1), `proveedor_id` sugerido (`nullOnDelete`), costo estimado (nacional/extranjero), IGV por servicio. Backfill de servicio y proveedor desde la tarifa.
>   - Tabla `paquete_plantilla_precios` (§3.12).
>   - Aplicadas a `agencia-demo` con `tenants:migrate-verticales`.
> - **Servicios:**
>   - `TourCostoEstimadoService`: calculadora (§3.9b) y costo del tour (suma del Incluye o el escrito a mano).
>   - `TemporadaResolverService`: elige la ocurrencia que cubre una fecha; si se superponen, gana la más corta y, en empate, la de menor id. `precioParaFecha()` queda listo para la 1b y, si la temporada más específica no tiene precio en el tour, cae a la siguiente que sí lo tenga.
>   - `DiagnosticoToursService`.
> - **Endpoints:**
>   - `GET paquetes-plantilla/{id}/calculadora-costos?temporada_id=` (solo sugiere).
>   - `GET …/precio-para-fecha?fecha=` (vista previa).
>   - `POST/PUT/DELETE …/precios[/{precioId}]`.
>   - `PUT paquete-plantilla-items/{id}` (costo, IGV y proveedor sugerido; la tarifa no se cambia por acá).
>   - `?uso=` en el listado.
>   - `show()` devuelve además `costo_estimado`, `margen_minimo_agencia_pct` y `tratamiento_default`.
> - **Comando** `agencia-viajes:diagnosticar-tours [--tenant=] [--json]`, de solo lectura. En `agencia-demo`:
>   - 6 `solo_itinerario` y 6 tours con precio.
>   - Lamas mezcla IGV 10/20.
>   - Laguna Azul y Encañada tienen tarifas privadas: falta el mínimo para salir.
>   - #16 y #19 no tienen Incluye; #29 no tiene precio ni tarifas.
> - **Frontend:**
>   - `TourPrecioVentaCampos.vue` (bloque "Precio y venta", en `form.vue` y en la edición del tab Datos).
>   - `MargenEnVivo.vue` (S/ y % sobre el costo, contra el mínimo del tour o el de la agencia).
>   - Pestaña "Incluye y costos" (`TourIncluyeCostos.vue`: costo e IGV por servicio, calculadora, servicio sin tarifa, aviso de IGV mezclado e IGV de la tarifa como referencia).
>   - `TourPreciosTemporada.vue`, con "Probar una fecha".
>   - Etiqueta y filtro "Solo itinerario" en el catálogo.
>   - `TourIncluidoForm.vue` (tours de mayorista) crea con `uso=solo_itinerario`.
> - **Pruebas:**
>   - 25 tests nuevos (`Fila13Fase1aTourCatalogoTest`), entre ellos uno de regresión: `desdePlantilla()` da el mismo resultado con todo lo nuevo cargado.
>   - Type-check del frontend: 46 errores, todos anteriores; 0 nuevos.
>   - Verificado en vivo con Playwright en `agencia-demo` (tour #12, revertido a su estado original).
>
> **Hallazgos de la sesión:**
> 1. **El Incluye no tenía columna de servicio** (se deducía de la tarifa), así que no podía existir "un servicio sin tarifa con costo a mano" (§3.2). Se agregó `destino_servicio_id`, con aprobación del usuario. Consecuencia que se corrigió:
>    - `DestinoServicioController::fusionar()` habría dado error 500 por la clave foránea; ahora lleva también los ítems del Incluye.
>    - `destroy()` bloquea con 422 si un tour lo usa.
> 2. **Exigir precio a todo tour `venta` rompía pantallas existentes:**
>    - `TourIncluidoForm.vue` crea tours sin precio.
>    - `detalle.vue` desactiva un tour (y guarda el combo) reenviando el objeto completo con `precio_venta_final: null`.
>
>    Regla final: el precio se exige al **crear**, al **pasar a `venta`** o al **borrar un precio que existía**. Los tours viejos sin precio se siguen editando, con aviso, hasta su conversión (1b). El IGV del tour no es obligatorio: vacío = el default de la agencia.
> 3. **IGV del Incluye contra el de la tarifa:** en `agencia-demo` las tarifas de Laguna Azul son gravadas (10), pero el default de la agencia es exonerado. Un ítem sin IGV propio hereda el del tour (regla del plan), así que la pantalla muestra "La tarifa es Gravado · usar". **Para la 1b:** la conversión debe proponer el IGV de la tarifa por ítem, no dejarlo vacío.
> 4. **E5 sin corregir a propósito:** `resolverCostoVentaItem()` (cotizador clásico) sigue contando el guía como 1 día. La calculadora sí multiplica por los días. Queda para la 1b, en un commit aparte, si se decide tocar el clásico.
> 5. La capitalización automática de nombres (`capitalizarNombrePropio`) convierte "-Full Day" en "-full Day" al guardar. Es anterior a esta sesión y no se tocó; anotado.
> 6. `sistemafe_test_migrations` tiene un desfase anterior: `2026_09_07_120000_add_opcion_mayorista_opcional…` está aplicado pero no figura en el registro de migraciones. Correr el directorio completo falla; las migraciones nuevas se aplican por archivo.
>
> **Diagnóstico de dakamu (§7): hecho el 14-oct-2026, sin riesgo** (3 tours sin precio, 0 vinculados a mayoristas).

| Fase | Contenido | Estimado |
|---|---|---|
| 1 | **Tour simple:** columnas de §3.1 (incluido `uso`, N15, con su backfill y el bloqueo de carga en el cotizador para `solo_itinerario`), Incluye estructurado (con costo e IGV por servicio), calculadora, una línea por tipo de IGV cuando hay mezcla (N14), extranjeros (residencia en cotización), cotización con línea de tour, combos (una línea por tour, descuento E3), facturación con la línea de tour, retiro de `ajuste_redondeo`, conversión de los tours existentes con confirmación del usuario.  Además (§5c): desglose de precio por pasajero para facturar por subgrupo (C1), Incluye del PDF desde la lista del tour (C5), permisos y menú. **Precio por temporada (§3.12, N16)**. | 6.5–7.5 días |
| 2 | **Salida como centro:** agrupación por línea de tour, servicios a reservar, varios vehículos, guía, mínimo, estados, Reporte Operativo con los dos tipos.  Además (§5c): Inicio y `AsignacionOperativa` con salidas de tour (C3), `reprogramar()` mueve líneas de tour (C4), permisos y menú. **Mejoras de operación (§3.13):** órdenes de servicio a proveedores, avisos al cancelar, reprogramar salida completa. **Bandeja de pendientes (§3.14)** con los avisos de salidas. **Al terminar: pausa para evaluar con uso real antes de la fase 3.** | 8.5–9.5 días |
| 3 | **Compras (sistema base):** registro de comprobantes, contado o crédito, pagos parciales vía Caja, cuentas por pagar, gastos sin comprobante con respaldo, RUC automático, validación SUNAT, adjuntos, tabla única de proveedores al inicio de la fase (§3.7c). Mayoristas (§3.7d): cronograma de pagos por reserva con condiciones por defecto del proveedor, pagos a cuenta sin factura y su aplicación a la factura posterior, USD con tipo de cambio, retiro de `cronograma_pago_proveedor`/`pago_proveedor`. Comisiones (§3.7d): configuración por proveedor, notas de crédito recibidas, factura de comisión al proveedor (cliente creado desde el proveedor) repartida entre reservas, compensación con el pago al proveedor.  Además (§5c): pagos con origen Caja o **Banco sin sesión de caja** y `cuentas_bancarias` al sistema base (C2), migraciones probadas en todos los giros (C8), módulo activable por tenant, permisos y menú. Avisos de pagos por vencer en la bandeja (§3.14). | 10–11 días |
| 3b | **Rendiciones (§3.7b):** entrega a rendir desde Caja, gastos contra la rendición (reusa Compras y gastos sin comprobante), liquidación con vuelto o reembolso, aprobación y cierre, listado de rendiciones abiertas. Depende de la fase 3. | 2–3 días |
| 4 | **Centro de costo + Rentabilidad:** asignaciones (con reparto de un comprobante), reportes por salida, reserva y tour, estimado contra real. Vista "cobrado contra pagado" por reserva (§3.7d).  Además (§5c): ingreso de la salida = precio vendido neto de NC (C6, C7), aviso "faltan gastos por registrar", permisos y menú. Avisos de facturas faltantes, rendiciones abiertas y costos desactualizados en la bandeja (§3.14). | 4.5–5.5 días |
| 5 | **Registros de Ventas y Compras** para el contador (Excel + formato SUNAT), con la configuración por tenant de régimen y "lleva registros electrónicos".  Permisos y menú. | 2.5–3.5 días |
| 6 | Vista del guía | ~3 días |
| 7 | Calificación del cliente | ~2 días |
| 8 | SIRE: importar la propuesta de compras | A estimar tras P3 |

**Núcleo (fases 1-5, con 3b): 34–40 días** (incluye la revisión de choques §5c y las mejoras N16-N17), incluidos 1-2 días para el interruptor `modo_tours` y para
mantener los dos caminos (§5b), repartidos en las fases 1 y 2. Es bastante más que el plan anterior (6–7 días), porque
incluye módulos nuevos (Compras, contabilidad, rentabilidad) que el plan anterior no tenía. La
fase 1 sola ya resuelve el problema original (precio del tour en la cotización).

Antes de la fase 1: diagnóstico de solo lectura en producción (`dakamu`), que incluya
**cuántos tours están vinculados a opciones de mayorista** y cuáles tienen precio (N15): según
un comentario del código, el uso de mayoristas es el 100 % de un tenant real, así que ahí
pueden ser la mayoría. Migraciones con
`php artisan tenants:migrate-verticales`; las de Compras y registros, en `tenant/core/`.

## 7. Preguntas abiertas

**Diagnóstico de producción (dakamu) — HECHO 14-oct-2026, sin riesgo para el despliegue.**
- 3 tours simples, 0 con precio, 0 con ajuste de redondeo, 0 combos.
- **0 tours vinculados a mayoristas** (`mayorista_con_precio` = 0): el backfill N15 no marca ninguno como `solo_itinerario`, y los 3 quedan `venta` sin precio (se editan con aviso hasta la conversión de la 1b).
- 4 tarifas: compartido 1 (sin hotel), privado 3 (hotel). 0 con temporada.
- 1 solo ítem en el Incluye (con tarifa de proveedor, en PEN). 0 IGV mezclado, 0 hoteles dentro de tours.
- La suposición de §6 ("el uso de mayoristas es el 100 % de un tenant real, ahí los tours vinculados pueden ser mayoría") **no se cumple en dakamu**: hoy no tiene tours vinculados a opciones de mayorista.

Corrida en producción con `php artisan tinker --execute="$(cat archivo.php)"`: pegar el bloque directo en `tinker` falla porque evalúa línea por línea.

Referencia local (`agencia-demo`, 14-oct-2026):
- 13 tours simples, 6 con precio, 0 con ajuste; 1 combo.
- 6 tours vinculados a mayoristas, 0 de ellos con precio.
- Tarifas: privado 5 y compartido 12 (sin hotel); compartido 2 y privado 7 de hotel. 0 con temporada.
- 1 tour con IGV mezclado.
- 12 ítems en el Incluye: 9 con tarifa de proveedor, 0 de guía y 3 tours hijo. Todas las tarifas usadas en tours están en PEN.

Si en dakamu hay tours vinculados a mayoristas **con** precio, la regla N15 los deja en `venta`: revisarlos uno por uno antes de la 1b.

- ~~P1 — IGV del tour~~ **Resuelta (14-oct, contador):** no se puede un solo tipo si hay mezcla; cada concepto con su afectación (código 10 gravado, código 20 exonerado). → N5, N14.
- ~~P2 — Extranjeros~~ **Resuelta (14-oct, contador):** en tours, el IGV depende de dónde se presta el servicio, no de la residencia del cliente. → N5, §3.3. *(No cubierto por la respuesta: hospedaje y alimentación a no domiciliados. Sigue como hoy en las líneas de hotel; revisar con el contador si se toca esa parte.)*
- ~~P3 — SIRE o PLE~~ **Resuelta (14-oct):** SIRE (§3.8). El contador confirmó que la obligación depende del régimen e ingresos y que los archivos los genera la empresa desde su sistema → configuración por tenant y el sistema genera ventas y compras (no diario ni mayor). Queda verificar el API de SIRE antes de la fase 8.
- ~~P4 — Proveedores~~ **Resuelta (14-oct, idea del usuario):** una sola tabla `proveedores` en el sistema base. → §3.7c.
- ~~P5 — Guía~~ **Resuelta (14-oct):** usuario propio, vinculado al guía existente (`guias.user_id`), creado desde la ficha del guía con rol "Guía"; la tabla `guias` no se reemplaza. → §3.10.
- ~~P6 — Factura mensual~~ **Resuelta (14-oct):** reparto propuesto **según el costo estimado** de cada salida para ese servicio, con las salidas sugeridas automáticamente; otros criterios y edición manual disponibles. → §3.7 ("Reparto de un comprobante").
- ~~P7 — Comisiones e incentivos~~ **Resuelta (14-oct):** existen las dos formas según el proveedor → configuración por proveedor; el tipo de IGV de la factura de comisión y si el monto incluye IGV son **valores por defecto configurables por proveedor**, con todas las opciones siempre disponibles al emitir (§3.7d, punto 0). El contador solo indica qué valor dejar en cada proveedor; no bloquea el desarrollo.

---

## Anexo A — Ejercicio de punta a punta con este modelo

> Datos ficticios. Laguna Azul está en San Martín (Amazonía): todos sus servicios son
> **exonerados** (N5, confirmado por el contador), así que el tour es una sola línea y
> total = base (la rentabilidad real siempre se calcula sin IGV, §3.9). El caso con IGV
> mezclado está en el ejemplo de §3.2.

**A.1 Tour.** "Laguna Azul Full Day", compartido, mínimo 6. Incluye: transporte (Transportes
Selva), guía, almuerzo (El Mirador), entrada, paseo en bote.
- Nacional: adulto **S/ 150**, niño **S/ 130**.
- Extranjero: adulto **S/ 170**, niño **S/ 150** (la entrada cuesta más).
- Costo estimado por persona: **S/ 120** nacional (la calculadora lo sugiere: minivan 300 ÷ 6 + guía 120 ÷ 6 + 25 + 10 + 15) y **S/ 140** extranjero.
- Margen estimado: adulto nacional 25 %.

**A.2 Cotizaciones.**
- **Rojas:** 2 adultos + 1 niño + 1 infante, nacionales → una línea: 150 × 2 + 130 = **S/ 430**. Costo estimado 360. Además, un **transfer al aeropuerto suelto** de Transportes Selva a S/ 60 (costo 40), con el precio de su tarifa, como hoy.
- **Pérez:** 2 adultos nacionales + 1 adulto extranjero → 150 × 2 + 170 = **S/ 470**. Costo estimado 380.

PDF de cada uno: total, itinerario, Incluye y fotos.

**A.3 Reservas → salida del 15-nov.** La salida agrupa las dos líneas de tour: **6 / 6 mínimo** ✅
(más 1 infante). Servicios a reservar: almuerzo para 6, entradas 5 nacionales + 1 extranjero,
bote para 6. Vehículos: minivan de Transportes Selva para los 6, recojo en dos zonas. Guía
Carlos. El transfer de los Rojas se opera aparte, en su reserva.

**A.4 Gastos reales (Compras).**

| Comprobante | Total | Asignación |
|---|---|---|
| Factura mensual de Transportes Selva (noviembre) | S/ 4,200 | **S/ 300** a esta salida + **S/ 40** a la reserva Rojas (transfer) + el resto a otras salidas |
| Recibo por honorarios del guía Carlos | S/ 120 | Salida |
| Factura de El Mirador (6 almuerzos a S/ 28, no a S/ 25) | S/ 168 | Salida |
| Tickets de entrada (5 × 10 + 1 × 30) | S/ 80 | Salida |
| Boleta de la asociación de boteros | S/ 90 | Salida |

**A.5 Rentabilidad de la salida.**
- Ingreso: 430 + 470 = **S/ 900**.
- Costo real: 300 + 120 + 168 + 80 + 90 = **S/ 758**.
- **Margen real: S/ 142** (15.8 % sobre el ingreso).

Estimado contra real:
- Costo estimado de la salida: 360 + 380 = 740.
- Real: 758 → **+18**.
- Por ítem: almuerzo +18 (el restaurante subió a S/ 28); los demás según lo estimado.

→ El reporte señala que conviene **actualizar el costo estimado del almuerzo** en el tour.

**A.6 Reserva Rojas.**
- Ingreso: 430 + 60 = 490.
- Costo: su parte de la salida (3 de 6 pax: 758 × 3 ÷ 6 = 379) + transfer 40 = 419.
- Margen: S/ 71.

**A.7 Para el contador.** En noviembre, el Registro de Ventas lleva las facturas o boletas de
Rojas y Pérez; el Registro de Compras lleva la factura de Transportes Selva, la de El Mirador,
el recibo del guía y la boleta de los boteros. Los tickets de entrada entran según el tipo de
comprobante (a confirmar con el contador).

**A.8 Variante: no llega al mínimo** (solo los Rojas). La salida muestra **"3 / 6"** ⚠️. Si se
sale igual, el reporte mostrará el margen real bajo. Si se endosa, las reservas pasan a la salida "operada por
operador X" y su factura es el único gasto.

---

## Anexo B — Comparación con un ejemplo: modo clásico (hoy) contra modo nuevo

> 14-oct-2026. Mismo caso en los dos modos (§5b, interruptor `modo_tours`). Tour Laguna Azul
> Full Day con precio escrito adulto S/ 150 y niño S/ 130. Familia Rojas: 2 adultos, 1 niño y
> 1 infante. Familia Pérez: 3 adultos. Salen juntos el 15-nov. Los números del modo clásico
> siguen el código actual (`desdePlantilla()`: costo compartido × adultos + niños + infantes;
> guía como tarifa fija, cantidad 1).

Tarifas de los proveedores:

| Servicio | Costo | Venta de la tarifa | Cómo cobra |
|---|---|---|---|
| Transporte | 50 | 60 | por persona |
| Guía | 120 | 150 | por día (fijo) |
| Almuerzo | 25 | 32 | por persona |
| Entrada | 10 | 12 | por persona |
| Bote | 15 | 20 | por persona |

### B.1 Cotización Rojas

**Clásico:** 5 líneas con el precio de cada tarifa. **El precio escrito en el tour no se usa.**

| Línea | Venta | Costo |
|---|---|---|
| Transporte (60 × 3) | 180 | 200 (50 × 4, cuenta al infante) |
| Guía (fijo) | 150 | 120 (completo, solo para esta familia) |
| Almuerzo (32 × 3) | 96 | 100 |
| Entrada (12 × 3) | 36 | 40 |
| Bote (20 × 3) | 60 | 60 |
| **Total** | **522** | **520** → margen S/ 2 |

**Nuevo:** una línea: 2 × 150 + 1 × 130 = **S/ 430**; costo estimado 120 × 3 = 360 (el infante no
suma) → margen estimado S/ 70.

### B.2 Cotización Pérez

| | Clásico | Nuevo |
|---|---|---|
| Precio | 522 (con el guía completo otra vez) | 450 |
| Costo | 420 | 360 estimado |

En el clásico, **cada familia paga el guía entero**; en el nuevo, el guía ya está repartido en el
costo por persona del tour.

### B.3 PDF

Igual en los dos: total, itinerario, Incluye y fotos.

### B.4 Operación del día anterior

- **Clásico:** el Reporte Operativo muestra una fila por servicio y reserva (10 filas); la Salida Operativa agrupa algunos servicios y ahí se asigna el guía.
- **Nuevo:** una fila por salida: "Laguna Azul — 15-nov — 6 pax / 6 mínimo (+1 infante). Reservar: almuerzo 6, entradas 6, bote 6. Vehículos: minivan T. Selva. Guía: Carlos."

### B.5 Facturación

Prácticamente igual: el clásico agrupa sus líneas por categoría y tipo de IGV; el nuevo emite la
línea del tour (dos si mezcla IGV, N14).

### B.6 Después del tour

- **Clásico:** solo el margen estimado (2 + 102 = S/ 104), mal calculado (guía contado dos veces, costo del infante). El costo real no se registra.
- **Nuevo:** compras asignadas a la salida: 300 + 120 + 168 + 60 + 90 = **738**. Cobrado 880 → **margen real S/ 142**. Aviso: almuerzo +18 sobre lo estimado → actualizar el costo del tour (§3.9b).

### B.7 Resumen

| | Clásico (hoy) | Nuevo |
|---|---|---|
| Precio escrito en el tour | No se usa | Es el que se cobra |
| Líneas en la cotización | Una por servicio | Una por tour |
| Guía compartido entre familias | Cada una lo paga entero | Repartido en el costo por persona |
| Infante | Suma costo | No suma |
| Precio para extranjeros | No existe | Opcional |
| Operación | Por servicio y reserva | Por salida |
| Margen | Solo estimado, con errores | Estimado y real |
| Contabilidad | No | Registro de Compras y Ventas |
| Desventaja | — | El costo al cotizar es una estimación; se compensa con la calculadora y el estimado contra real |
