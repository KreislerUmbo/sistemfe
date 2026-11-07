# Requerimiento — Tributario simplificado y series por afectación (Agencia)

| | |
|---|---|
| **Fecha** | 2026-11-05 (ordenado el 07-nov-2026) |
| **Módulo** | AGE + VEN |
| **Pedido por** | usuario (y el contador, para las series) |
| **Relacionado** | `agencia-precio-y-margen.md` (el margen real depende de la afectación) |
| **Antecedentes que se juntan acá** | obs. 10 y H26 de `observaciones-pruebas-usuario.md` (26-oct); fila F2 de `plan-hoja-de-ruta-ejecucion.md` (23-oct); IGV del pasaje aéreo (31-oct); concepto libre de Venta directa (29-oct) |

> Borrador vivo y **único lugar del tema tributario de Agencia**. El estado de cada cosa está solo
> en `docs/backlog.md`. Las preguntas al contador están en §2.8, numeradas C1-C15.

## 1. Lo que se necesita

- Que la facturación de los servicios no sea engorrosa: manejar solo la afectación del IGV y, si se
  puede, quitar el "destino", sin salirse de la norma.
- El contador pide **una serie por caso**: F001 exonerado soles, F002 gravado soles, F003
  exonerado dólares, F004 gravado dólares.
- Que no se demore más al cotizar ni al facturar.

## 2. Análisis

### 2.1 Estado en una mirada

| # | Qué | Se puede construir |
|---|---|---|
| P1 | Leyenda 2002 en el XML | **Ya.** Solo el texto del PDF espera C10 |
| P2 | Configuración: empresa calificada + dos valores por defecto | **Ya** (encenderlo en la agencia espera C2) |
| P3 | Ninguna lista tributaria en la cotización | **Ya** |
| P4 | Propuesta automática (versión simple) | **Ya** |
| P5 | Grilla de facturar con casilla de afectación | **Ya** (con el bloqueo de mezcla todavía) |
| P6 | El precio no cambia, cambia el desglose | **Ya** (es lo que hace hoy; C6 lo confirma) |
| P7 | Comprobante mixto y sin confirmación por ítem | **Ya** (07-nov: C4 sí se mezcla; C5 se quita la confirmación). Va antes de T-048 |
| P8 | Series por afectación × moneda | Espera **C11, C12** y la pregunta al usuario U1 |
| P9 | Expediente de sustento por reserva | Espera **C15** |

**C1 respondida (07-nov):** la afectación depende de **quién opera** el servicio, no de dónde se
consume (ver §2.7). Cambia los valores por defecto de P2 y P4: casi todo nace Exonerado.

### 2.2 Lo que hay hoy en el código (verificado el 05-nov-2026)

- **El destino no va a SUNAT.** El XML solo lleva la afectación por línea (cat. 07), el tipo de
  operación (cat. 51) y las leyendas (cat. 52). `destino_tributario` solo sirve por dentro.
- **En Agencia el destino repite lo que ya dice la afectación.** El trait `ExoneradoEsAmazonia`
  (y `helpers/tratamientoTributarioAgencia.ts`) hace que 20 ⇒ `amazonia`. Igual, unas 15
  pantallas piden **dos listas** (Destino + Afectación). La tarifa ofrece además **IVAP (17)**,
  que nunca aplica a una agencia.
- **Cualquier agencia puede marcar exonerado**: no hay interruptor de "empresa calificada" (fila
  F2).
- **Leyenda de Amazonía (H26):** solo va en el PDF (`Sale::muestra_leyenda_amazonia`, con el texto
  "OPERACIÓN EXONERADA DEL IGV — LEY N° 27037"). El XML de facturas y notas manda solo la 1000
  (`armarLeyendas()`, `armarLeyendasNota()`).
- **Series:** `serie_comprobantes` es `unique(branch_id, tipo_comprobante_codigo, moneda)`.
  Separar por moneda **ya se puede**; por afectación no. `note_series` es
  `unique(tipo_doc, tipo_doc_afectado)`, sin moneda ni afectación.
- **Mezcla y confirmación:** `CriterioTributarioService` bloquea mezclar tratamientos (salvo
  dentro de una misma `carga_tour`) y exige confirmar a mano cada ítem Amazonía
  (`motivo_override_tributario`). Se ve en la tarjeta de la reserva ("Sin confirmar"), en
  `FacturarReservaSimpleModal` y en `FacturarReservaEspecialModal`; también lo lee
  `ModificacionReservaService:362`.
- **Precio:** siempre **final, con el IGV incluido** (`desglosarPrecioFinal.ts`,
  `construirLineas()`). El IGV se saca de adentro (÷ 1.18).
- **Valor por defecto único** en Configuración (sección 9), usado para ítems manuales, de
  **mayorista**, de guía, de pasaje aéreo y para el concepto libre de Venta directa. Si está en
  Exonerado, un paquete de mayorista a Cusco nace exonerado (H21); con la respuesta a C1 (07-nov)
  eso es correcto.
- **Pasaje aéreo** (corregido el 07-nov tras releer el código): los comentarios de
  `CotizacionPasajeAereo` y de `AlternativaItemController` dicen que la afectación aplica **solo al
  fee**, pero la factura la aplica **al precio completo**: `construirLineas()` toma el
  `total_convertido` del ítem (tarifa + cargos + fee) con la afectación de
  `resolverTipAfeIgvItem()`. El recuadro «Análisis de impuestos» de `PasajeAereoForm.vue` hace lo
  mismo. Lo que no coincide son los comentarios, no la factura; con la respuesta del 07-nov (todo
  exonerado) la factura ya hace lo correcto. Falta corregir los comentarios.
- **Ubicación:** el proveedor tiene ubigeo. La tarifa no se une directamente a un destino; la
  opción de mayorista sí cuelga de un destino de la cotización (`alternativa_destino`), y su hotel
  puede ser texto libre.
- **Retail no se toca** en P2-P7: ahí el destino sí cambia la afectación del mismo bien
  (`resolverTipAfeIgv(product, destino, isExportacion)`).

### 2.3 Relación con lo ya escrito

- **Se mantiene:**
  - **Obs. 10, opción B:** lo deduce el sistema; la persona no decide.
  - **Obs. 10, pregunta 1** (respondida el 26-oct): la exoneración depende de la agencia (art. 2)
    y de dónde se presta, no del cliente.
  - **N5** de `plan-tours-venta-simple-costo-real.md` (14-oct, **confirmada con el contador**):
    cada servicio lleva su afectación; un tour que mezcla no se factura con un solo IGV.
  - **D18** (en Agencia, 20 = Amazonía) sigue vigente; P2 le agrega la condición de empresa
    calificada.
- **Lo resuelve esta propuesta:** H21 (P2), H26 (P1), "Corregir tratamiento" que acepta
  combinaciones que contradicen D18 (P3), el interruptor de la fila F2 (P2), el concepto libre de
  Venta directa (P3/P4).
- **Cambia respecto de la obs. 10:**
  - **Cómo se deduce:** antes, una tabla de reglas sobre el árbol de destinos; ahora, dos valores
    por defecto según el tipo de ítem (la tarifa no se une directamente a un destino, y el usuario
    prefiere no cargar datos).
  - **Corregir una línea al facturar:** sin motivo obligatorio, con usuario y fecha.
- **Choca con lo escrito** (se resuelve con una decisión nueva):
  - **D-AGE-033** (penalidad): *"no se admiten valores tributarios por defecto silenciosos"*. Los
    valores por defecto de esta propuesta **no son silenciosos**: se ven y se cambian en la grilla
    de facturar (P5). Lo silencioso hoy es el `?? '10'` de `resolverTipAfeIgvItem()`, que con P5
    pasa a verse.
  - ~~**D-AGE-034 punto 2 y T-048**~~ **Resuelto el 07-nov con D-AGE-035:** reemplaza el punto 2
    (casilla de la Amazonía en Venta directa); T-048 ya está reescrita y depende de P7.
  - **N5 del plan de tours** (14-oct, contador: "según dónde se presta"): D-AGE-035 la concilia.
    "Dónde se presta" vale para lo que opera la propia agencia; lo de terceros va exonerado
    (confirmado con C1c el 07-nov).
- **Manuales del usuario** (`docs/manuales/agencia-viajes/configuracion-agencia.md` §9 y
  `gestion-proveedores.md`, sub-pestaña Tributario): describen la lista Destino y el IVAP. Se
  actualizan cuando se construyan P2 y P3 (hoy están sin commitear, son del usuario).

### 2.4 Lo que dicen las normas (investigación del 05-nov-2026; fuentes de SUNAT, en su mayoría antiguas)

- **Ley 27037:** la exoneración de un servicio depende de **quién emite** ("empresa ubicada en la
  Amazonía": domicilio fiscal y sede allí, Registros Públicos, ≥70 % de activos fijos, sin
  producción fuera, comunicado a SUNAT) y de **dónde se presta**. **No depende del cliente**
  (Oficio SUNAT 114-2000). San Martín volvió al beneficio con la Ley 32317 (2025).
- **Intermediaria o revendedora:** si intermedia, factura solo su fee o comisión (gravado); si
  revende, factura el total. Las "liquidaciones de cobranza" no son comprobantes.
- **Exportación:** paquete a una agencia no domiciliada (acreditar al no domiciliado y su último
  ingreso); hospedaje y alimentación a un turista no domiciliado (hasta 60 días, con TAM y
  pasaporte).
- **Detracción:** comisión mercantil, código 024, 10 %, a partir de S/ 700.
- **Dólares:** TC venta de la fecha de emisión; la NC usa el TC del comprobante original.
- **2026:** 18 % = 15.5 % IGV + 2.5 % IPM (Ley 32387). El total no cambia.
- **Series por afectación:** son legales; es orden interno del contador, no una norma.

### 2.5 Propuesta (borrador)

| # | Qué | Detalle |
|---|---|---|
| P1 | **Leyenda en el XML** | 2002 en Agencia (servicios); en retail, 2001 o 2002 según `products.tipo_bien_servicio` (vacío ⇒ 2001); 2003 queda preparado. En facturas, boletas y NC/ND. El PDF lee lo mismo que se mandó en el XML. Todos los giros. |
| P2 | **Configuración → Tratamiento tributario** | Se quita la lista Destino. Se agregan: ☐ *Empresa calificada en la Amazonía (Ley 27037)* + fecha de comunicación a SUNAT; **dos valores por defecto** (servicios de terceros / operados por la agencia fuera de la zona; criterio de C1, 07-nov); ☐ *Series por afectación* (con P8). Sin la empresa calificada, **Exonerado no aparece en ningún lado**. Solo lo cambia el Propietario o soporte, y queda registrado quién y cuándo. |
| P3 | **Ninguna lista tributaria en la cotización** | En el cotizador, ítem manual, pasaje aéreo, hotel no registrado, guía, penalidad y concepto libre de Venta directa, la línea muestra solo una **etiqueta**. Queda **una sola lista "Afectación IGV"** en la tarifa de proveedor y en Configuración: Gravado / Exonerado – Amazonía / Inafecto. `destino_tributario` se sigue llenando solo (sin migrar datos). Fuera IVAP. Exportación y gratuitos quedan ocultos. |
| P4 | **Propuesta automática** | Valor ya guardado → valor por defecto según el tipo de ítem (locales o mayorista). Opcional para después: distrito del proveedor y zona amazónica del árbol de destinos. |
| P5 | **Grilla de facturar con columna "Afectación"** | Casilla por línea, prellenada y editable; la fila cambiada muestra "✎ cambiado" y se guarda en `reserva_items` con usuario y fecha. Resumen a la vista: op. gravadas / exoneradas / IGV / total. "Serie a emitir" en vivo. Lo ya facturado queda bloqueado. Un solo componente para Facturar simple, Facturación especial y Venta directa. |
| P6 | **Precio** | Sigue siendo final, con todo incluido. Cambiar la afectación cambia el **desglose**, no el total ("El IGV sale del precio; el cliente sigue pagando S/ X"). |
| P7 | **Comprobante mixto por línea** | Se quita el bloqueo de mezcla (y la excepción de `carga_tour`) y la **confirmación obligatoria de la Amazonía** (el modal y "Sin confirmar"); su función pasa a la casilla de P5. Queda solo el bloqueo de exportación. |
| P8 | **Series por afectación × moneda** | Exonerado / Gravado / **Mixto** × PEN / USD, en facturas **y boletas** (12 por sucursal), elegidas solas según las líneas. Opcional por empresa; al activarlo se crean todas juntas. Migración nueva para el índice único; `resolverSerie()` recibe la afectación desde `SaleController::store()`, `AdvanceController`, facturación de reservas y Venta directa. NC/ND según C12. |
| P9 | **Expediente de sustento por reserva** (fila F2, opcional) | Un PDF por reserva con itinerario, pasajeros y órdenes de servicio, para respaldar ante SUNAT que el servicio se prestó en la zona. Solo si el contador lo pide (C15). |

Ejemplo de series: F001/B001 exonerado PEN · F002/B002 gravado PEN · F003/B003 exonerado USD ·
F004/B004 gravado USD · F005/B005 mixto PEN · F006/B006 mixto USD.

### 2.6 Flujo completo (con la propuesta)

Ejemplo: agencia de Tarapoto que vende Tarapoto + Cusco.

> Ajustado el 07-nov con la respuesta a C1: el valor depende de quién opera el servicio.

1. **Configuración (una vez):** empresa calificada ☑; valores por defecto: servicios de terceros =
   Exonerado, operados por la agencia fuera de la zona = Gravado; series por afectación ☑ (se crean
   las 12).
2. **Tarifa de proveedor (una vez por tarifa):** una sola lista, prellenada con Exonerado (es un
   tercero, aunque esté en Cusco).
3. **Cotizador:** sin listas, solo etiquetas. Las líneas sin tarifa (mayorista, manual, pasaje)
   toman el valor de terceros. Un tour propio toma Exonerado o Gravado según dónde lo opera la
   agencia (C1a).
4. **Reserva:** la afectación se copia a cada ítem. Sin "Sin confirmar" ni modal de confirmación.
5. **Facturar:** grilla prellenada; normalmente no se toca nada. Si hay líneas exoneradas y
   gravadas, sale **un comprobante mixto** (F005); si solo hay exoneradas, F001. El total no cambia
   al corregir una línea. Al contado, el cobro de siempre.
6. **Enviar a SUNAT:** cada línea con su código (20/10), los totales separados, la leyenda 1000 y
   la **2002**. El PDF imprime la misma leyenda.
7. **Nota de crédito:** hereda la afectación y la leyenda de cada línea; la serie según C12.

| Momento | Quién | Qué decide |
|---|---|---|
| Configuración | Propietario o soporte | Empresa calificada, valores por defecto, series (una vez) |
| Tarifa | Quien carga proveedores | La afectación del servicio (una vez) |
| Cotizador y reserva | Vendedor | **Nada tributario** |
| Facturar | Quien factura | Solo corrige un caso raro |
| Serie, leyenda, totales | El sistema | Todo |

### 2.7 Respuestas del usuario

**05-nov-2026:**
1. La leyenda de Amazonía **va en el XML** (cat. 52: 2001 bienes, 2002 servicios, 2003
   construcción).
2. **Mixto por línea:** sí, cada ítem con su afectación en el mismo comprobante.
3. **Boletas** también separadas por serie. NC/ND: lo decide el contador (C12).
4. **Exportación y gratuitos:** para más adelante. No se muestran; lo guardado se conserva.
5. **Exonerado que no sea por la Amazonía** (p. ej. transporte terrestre, Apéndice II): no se sabe;
   queda fuera por ahora (se mantiene 20 = Amazonía).
6. La agencia está constituida en **Tarapoto (San Martín)**.
7. **Primero la versión simple** (dos valores por defecto, sin cargar datos).
8. **Casilla de afectación en la grilla de facturar**, prellenada y editable: sí.

**07-nov-2026 — respuesta a C1** (la transmitió el usuario):
> Si la agencia compra paquetes a un proveedor (en destino o fuera de la región) y se los vende a
> su cliente, es **exonerado**, porque la agencia no opera en destino. Si la agencia **opera en
> destino**, ahí sí grava IGV.

Lo que cambia:
- **El criterio es quién opera, no dónde se consume.** Vender servicios de terceros fuera de la
  zona (Cusco, internacional) no pone en riesgo la exoneración ni se grava: la agencia presta su
  servicio desde Tarapoto.
- **Valores por defecto (P2, P4):** ya no son "locales / mayoristas", sino:
  - **servicio de un tercero** (tarifa de proveedor, mayorista, hotel, pasaje, ítem manual) →
    Exonerado;
  - **servicio que opera la propia agencia** (tour propio, guía propio, movilidad propia) →
    Exonerado si se opera en la Amazonía; **Gravado si se opera fuera**.
  - En la práctica, hoy casi todo nace Exonerado. Gravado solo aparece si la agencia opera un tour
    propio fuera de la zona.
- **H21 deja de ser un riesgo:** que un paquete de mayorista a Cusco nazca exonerado es correcto
  con este criterio.
- **C3 queda en parte respondida:** aunque revenda (factura el total), lo de terceros va
  exonerado. Sigue abierto si en algún caso intermedia (solo fee).
- **Riesgo a dejar por escrito:** las fuentes de SUNAT revisadas (§2.4) hablan de servicios
  "prestados en la Amazonía". Conviene que el contador confirme el criterio **por escrito**, y si se
  apoya en un informe u oficio de SUNAT, anotarlo aquí. Si SUNAT lo discute, lo que se reclamaría es
  el IGV de lo vendido fuera de la zona.
- **Preguntas nuevas que salieron de la respuesta** (respondidas el mismo día):
  - **C1a.** ¿Qué cuenta como "operar en destino"? → **Guía propio y vehículo propio, los dos.**
    Con solo uno, sigue siendo servicio de un tercero (Exonerado).
  - **C1b.** El **fee** sobre un servicio de un tercero fuera de la zona (p. ej. el del pasaje
    aéreo), ¿también va exonerado? → **Sí, exonerado.** Responde C7 y la parte del fee de C8.

**07-nov-2026 — condición de la agencia** (la transmitió el usuario): todo lo anterior vale
**siempre que la agencia sea de la Amazonía**, esté inscrita en los **Registros Públicos de la
región** y esté en el **Régimen MYPE Tributario o en el Régimen General**. Si no cumple, no hay
exoneración: todo va gravado.

Lo que esto cambia en la propuesta:
- **P2 (empresa calificada):** el interruptor se acompaña de las tres condiciones a la vista
  (domicilio en la Amazonía, inscripción en Registros Públicos de la región, régimen MYPE o
  General). Si la empresa tiene registrado otro régimen, el sistema no deja encenderlo.
  **El régimen de la empresa no está guardado hoy** (revisado el 07-nov: solo existe
  `clients.regimen_tributario`, el del cliente). P2 lo agrega a la configuración de la empresa.
- **"Operado por la agencia" (P4):** no basta con que el tour sea propio; es gravado solo si se
  opera **fuera de la zona con guía y vehículo propios**. En el catálogo de tours eso es un dato
  del tour (opera la agencia / opera un tercero), no algo que el vendedor elija.
- **Pasaje aéreo (C8):** ver la respuesta siguiente.

**07-nov-2026 — respuesta a C8, pasaje aéreo** (la transmitió el usuario): la agencia **compra** el
boleto a la aerolínea (la aerolínea opera el vuelo y le cobra el IGV a la agencia), le suma su
margen y **lo revende exonerado**. La factura lleva **el precio completo** (tarifa + cargos + fee),
todo exonerado; no es una intermediación por fee.

Lo que cambia:
- **La factura ya lo hace así** (ver §2.2): no hay que cambiar el cálculo, solo los comentarios del
  código que dicen "solo sobre el fee".
- **Costo para el margen:** el IGV que cobra la aerolínea no se recupera, porque la venta es
  exonerada; el costo del pasaje es **con IGV**. Probablemente vale para todo proveedor que factura
  con IGV a una venta exonerada; es la C14, que queda casi respondida (confirmar).

**07-nov-2026 — C5, decisión del usuario** (no era pregunta para el contador): **se quita la
confirmación ítem por ítem** de la Amazonía (el "Sin confirmar", el modal "Confirmar tratamiento
tributario" y la casilla prevista en Venta directa). Funciona porque la agencia es de la Amazonía y
casi todo va exonerado; la grilla de facturar (P5) muestra y deja corregir cada línea.

**07-nov-2026 — alcance: por ahora solo empresas de la Amazonía.** El sistema se arma para una
agencia calificada (Ley 27037). Una empresa **fuera de la Amazonía**, o un cambio en la ley, cambia
mucho la forma de facturar y se prepara después. Ejemplo que dio el usuario: en un pasaje aéreo,
una agencia fuera de la zona tendría en el mismo comprobante una parte **gravada** (tarifa base y
asiento) y otra sin IGV (la **TUA**; el usuario la llamó exonerada, confirmar con el contador si es
exonerada o inafecta). Lo que ya deja preparado esta propuesta: el interruptor de empresa calificada
(P2), el comprobante mixto (P7) y la afectación por línea (P5). Lo que faltaría: partir un pasaje en
varias líneas (tarifa + asiento / TUA) y los valores por defecto de una empresa no calificada.

**Pendiente del usuario:**
- **U1.** ¿Las series por afectación (P8) son **solo para Agencia** o también para las **ventas de
  retail** de cualquier giro? (El pedido del contador vino por la agencia.)

### 2.8 Preguntas para el contador (ordenadas por importancia)

Versión para entregarle, sin términos del sistema: la hoja "Preguntas para el contador" (ver §3).

**A. De fondo: definen todo lo demás**
- ~~**C1.**~~ **Respondida el 07-nov** (§2.7): exonerado salvo que la agencia opere en destino.
  C1a y C1b también respondidas; la condición de régimen MYPE o General quedó en §2.7. Texto
  original: si la agencia también vende servicios **fuera de la Amazonía** (Cusco, internacional),
  ¿eso cuenta como "producción fuera de la Amazonía" (art. 2 del D.S. 103-99-EF) y pone en riesgo
  la exoneración de todo lo demás? ¿Cambia si en esos casos solo intermedia (comisión)?
- ~~**C1c**~~ **Respondida el 07-nov (usuario):** sí, si la agencia opera el tour fuera de la zona, todo lo de adentro (también las entradas y el almuerzo de terceros) va **gravado**. Texto original: en un tour que la agencia **opera** fuera de la Amazonía (guía y
  vehículo propios), los servicios de terceros que van dentro (entradas, almuerzo), ¿van gravados
  como parte del tour o exonerados? En la práctica hoy no pasa (todos los tours propios son de la
  zona); concilia D-AGE-035 con N5 del plan de tours.
- **C2.** ¿La agencia ya **comunicó a SUNAT** que es empresa ubicada en la Amazonía, y cumple los
  requisitos (domicilio, Registros Públicos, 70 % de activos)?
- ~~**C3.**~~ **Respondida en la práctica el 07-nov** (C1 y C8): la agencia **revende** (compra y
  factura el total con su margen), en paquetes, servicios de terceros y pasajes. Texto original:
  hoteles y tours: ¿la agencia **revende** (factura el total) o **intermedia** (factura solo
  su comisión o fee)? ¿Cambia según el servicio?

**B. Cómo se arma el comprobante**
- ~~**C4.**~~ **Respondida el 07-nov:** sí, se pueden mezclar líneas gravadas y exoneradas en el
  mismo comprobante, en cualquier combinación. Texto original: ¿un comprobante puede llevar líneas exoneradas y gravadas **en cualquier combinación**, o
  solo dentro de un mismo tour (ya confirmado, N5)? *Recomendado: en cualquiera.*
- ~~**C5.**~~ **Decidida por el usuario el 07-nov** (§2.7): se quita. No va en la hoja del
  contador. Texto original: ¿se puede quitar la **confirmación ítem por ítem** de la Amazonía antes
  de facturar?
- **C6.** Al pasar una línea a gravado, ¿el IGV **sale del precio** (el cliente paga lo mismo) o
  **se suma**? *Recomendado: sale del precio.*
- ~~**C7.**~~ **Respondida con C1b (07-nov):** el fee va exonerado. Texto original: ¿el **fee** de
  la agencia sobre un servicio prestado en la zona va exonerado o gravado?
- ~~**C8.**~~ **Respondida el 07-nov** (§2.7): se revende el boleto completo, exonerado. Texto
  original: **pasaje aéreo:** ¿qué parte es gravable (tarifa de la aerolínea, cargos, fee)?
- **C9.** **Penalidad por cancelación:** ¿con el IGV del servicio que se penaliza o se elige cada
  vez? *Recomendado: el del servicio, corregible al facturar.*
- **C10.** ¿Qué **texto** lleva la leyenda en el PDF: el del catálogo 52 ("SERVICIOS PRESTADOS EN
  LA AMAZONÍA REGIÓN SELVA PARA SER CONSUMIDOS EN LA MISMA") o el de hoy ("OPERACIÓN EXONERADA DEL
  IGV — LEY N° 27037")?

**C. Series**
- **C11.** Un comprobante mixto, ¿va en una **serie propia** (F005/F006) o en la gravada?
  *Recomendado: propia.*
- **C12.** Las **notas de crédito y débito**, ¿también llevan series separadas por afectación y
  moneda?

**D. Otros**
- **C13.** ¿Las facturas a empresas llevan **detracción** (comisión mercantil, 10 %, desde
  S/ 700)?
- **C14.** Para calcular la ganancia: el costo de un proveedor que factura **con IGV**, ¿se cuenta
  con IGV o sin él? ¿Cambia si la venta es exonerada? (Es la pregunta 2 de
  `agencia-precio-y-margen.md`.)
- **C15.** ¿Hace falta un **expediente de sustento** por reserva (itinerario, pasajeros, órdenes
  de servicio) para respaldar ante SUNAT que el servicio se prestó en la zona?

### 2.9 Riesgos

- **Datos existentes:** tarifas con 20 en una empresa que no está calificada (contarlas antes de
  P2). Las reservas sin confirmar ya no son riesgo: en producción hay una sola (07-nov).
- **Series que faltan:** con P8 activo, si falta una serie la venta falla. Hay que crear las 12
  juntas.
- ~~**El valor por defecto único de hoy** puede hacer nacer exonerados los ítems de mayorista.~~
  Con la respuesta a C1 (07-nov) eso es correcto: deja de ser riesgo.
- **El criterio de C1 no está por escrito:** si SUNAT entiende que el servicio se presta donde se
  consume, lo vendido fuera de la zona quedaría gravado. Pedir la confirmación escrita del contador.
- **T-048** reescrita el 07-nov sin la casilla de la Amazonía; depende de P7. D-AGE-034 punto 2
  queda reemplazado por C4 y C5 (decisión nueva al cerrar P7).
- ~~**Reservas con ítems "Sin confirmar"** pasan a ser facturables al quitar la confirmación (P7).~~
  **Sin riesgo real (07-nov, usuario):** en producción hay una sola reserva, y se pueden borrar los
  datos de prueba para empezar de nuevo. La limpieza se decide aparte y no la hace P7.
- **T-029:** desbloqueada el 07-nov. Con D-AGE-035 el valor por defecto de hoy (Exonerado en una
  agencia de la Amazonía) ya es el correcto para el guía y el ajuste, así que se puede hacer sin
  esperar a P2. H19 va en la misma tarea.
- **D-AGE-033:** decisión nueva sobre "valor por defecto visible no es silencioso" y la penalidad
  (C9).

### 2.10 Orden sugerido

1. **Ya, sin el contador:** P1 → P2 + P3 + P4 simple → P5 + P6. (Y H19 fuera de T-029.)
2. **P7** (C4 y C5 ya respondidas) → T-048 (ya reescrita).
3. **Con C11, C12 y U1:** P8.
4. **Con C15:** P9.
5. **Después:** distrito o zona en P4, exportación, gratuitos, exonerado no Amazonía, y **empresa
   fuera de la Amazonía** (pasaje partido en gravado + TUA, valores por defecto gravados; §2.7).

## 3. Resultado

- **Hoja para el contador:** preguntas C1-C15 en lenguaje simple (07-nov-2026),
  https://claude.ai/artifact/7VXN8CKdjoPesnxp6BDRSP. Sus respuestas se pasan a §2.7.
- **Decisiones:** D-AGE-035 (07-nov-2026): quién opera decide la afectación, comprobante mixto,
  sin confirmar la Amazonía; reemplaza el punto 2 de D-AGE-034.
- **Tareas:** T-091 (P7, 07-nov-2026); T-048 reescrita sobre ella.
