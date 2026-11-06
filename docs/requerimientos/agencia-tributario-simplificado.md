# Requerimiento — Tributario simplificado y series por afectación (Agencia)

| | |
|---|---|
| **Fecha** | 2026-11-05 |
| **Módulo** | AGE + VEN |
| **Pedido por** | usuario (y el contador, para las series) |
| **Relacionado** | `agencia-precio-y-margen.md` (el margen real depende de la afectación) |
| **Antecedente** | obs. 10 de `observaciones-pruebas-usuario.md` (26-oct-2026, "opción B: lo deduce el sistema"). Este documento la concreta. |

> Borrador vivo para ir puliendo. El estado está solo en `docs/backlog.md`.

## 1. Lo que se necesita

- Que la facturación de los servicios no sea engorrosa: manejar solo la afectación del IGV
  (gravado, exonerado, inafecto, gratuito…) y, si se puede, quitar el "destino", sin salirse de la
  norma.
- El contador pide **una serie por caso**: F001 exonerado soles, F002 gravado soles, F003
  exonerado dólares, F004 gravado dólares.
- Que no se demore más al cotizar ni al facturar.

## 2. Análisis

### Lo que hay hoy en el código (verificado el 05-nov-2026)

- **El destino no va a SUNAT.** El XML solo lleva la afectación por línea (cat. 07), el tipo de
  operación (cat. 51) y las leyendas (cat. 52). `destino_tributario` solo sirve por dentro.
- **En Agencia el destino repite lo que ya dice la afectación.** El trait `ExoneradoEsAmazonia` (y
  `helpers/tratamientoTributarioAgencia.ts`) hace que 20 ⇒ `amazonia`. Igual, unas 15 pantallas
  piden **dos listas** (Destino + Afectación): tarifa de proveedor, configuración, ítem manual,
  tarifas de hotel del cotizador, penalidad, guía, confirmar tratamiento, entre otras. La tarifa
  ofrece además **IVAP (17)**, que nunca aplica a una agencia.
- **Leyenda de Amazonía:** solo va en el PDF (`Sale::muestra_leyenda_amazonia`). El XML de
  facturas y notas manda solo la 1000 (`armarLeyendas()`, `armarLeyendasNota()`). Es el **H26** de
  la obs. 10.
- **Series:** `serie_comprobantes` es `unique(branch_id, tipo_comprobante_codigo, moneda)` y
  `SerieComprobanteService::resolverSerie()` elige por sucursal + tipo + moneda. Separar por moneda
  **ya se puede**; por afectación no. `note_series` es `unique(tipo_doc, tipo_doc_afectado)`, sin
  moneda ni afectación.
- **Mezcla y confirmación:** `CriterioTributarioService` bloquea mezclar tratamientos (salvo dentro
  de una misma `carga_tour`) y exige confirmar a mano cada ítem Amazonía
  (`motivo_override_tributario`). Se ve en la tarjeta de la reserva ("Sin confirmar"), en
  `FacturarReservaSimpleModal` y en `FacturarReservaEspecialModal`; también lo lee
  `ModificacionReservaService:362`.
- **Precio:** en Agencia el precio siempre es **final, con el IGV incluido**
  (`desglosarPrecioFinal.ts`, `construirLineas()`). El IGV se saca de adentro (÷ 1.18).
- **Valor por defecto único** en Configuración (sección 9), usado para ítems manuales, de
  **mayorista**, de guía y de pasaje aéreo. Riesgo de hoy: si está en Exonerado, un paquete de
  mayorista a Cusco nace exonerado.
- **Ubicación:** el proveedor tiene ubigeo (`departamento_id`/`provincia_id`/`distrito_id`). La
  tarifa no se une directamente a un destino (tarifa → `proveedor_servicio` → servicio ↔
  `destino_servicios`, que puede tener varios destinos). La opción de mayorista sí cuelga de un
  destino de la cotización (`alternativa_destino` → `destinos_atractivos` o texto libre), y su
  hotel puede ser texto libre (`opcion_hoteles.nombre_hotel`).
- **Retail no se toca:** ahí el destino sí cambia la afectación del mismo bien
  (`resolverTipAfeIgv(product, destino, isExportacion)`).

### Relación con lo ya escrito (revisado el 05-nov-2026)

- **Se mantiene:**
  - **Obs. 10, opción B:** lo deduce el sistema; la persona no decide.
  - **Obs. 10, pregunta 1** (respondida el 26-oct): la exoneración depende de la agencia (art. 2)
    y de dónde se presta, no del cliente.
  - **N5** de `plan-tours-venta-simple-costo-real.md` (14-oct, **confirmada con el contador**):
    cada servicio lleva su afectación, y un tour que mezcla no se factura con un solo IGV. Esto
    respalda el comprobante mixto por línea.
  - **D18** (en Agencia, 20 = Amazonía) sigue vigente; P2 le agrega la condición de empresa
    calificada.
- **Lo resuelve esta propuesta:**
  - **H21** (el paquete internacional de mayorista nace exonerado), con los dos valores por
    defecto de P2.
  - **H26** (la leyenda no va en el XML), con P1.
  - "Corregir tratamiento" acepta combinaciones que contradicen D18 (obs. 10), con P3.
- **Cambia respecto de la obs. 10:**
  - **Cómo se deduce:** antes era una tabla de reglas aplicada al árbol de destinos; ahora son
    dos valores por defecto según el tipo de ítem. Motivos: la tarifa no se une directamente a un
    destino, y el usuario prefiere no cargar datos (respuesta 7).
  - **Corregir una línea al facturar:** la obs. 10 la pedía "con motivo"; ahora queda sin motivo
    obligatorio, con usuario y fecha. Es para que haya menos fricción.
- **Choca con lo escrito** (se resuelve con una decisión nueva):
  - **D-AGE-033** (penalidad): *"el proyecto no admite valores tributarios por defecto
    silenciosos"*. La checklist de `proceso.md` dice lo mismo: "sin fallback silencioso". Los
    valores por defecto de esta propuesta **no son silenciosos**, porque se ven y se pueden
    cambiar en cada línea de la grilla de facturar (P5). Lo que sí es silencioso hoy es el
    `?? '10'` de `resolverTipAfeIgvItem()`, y con P5 pasa a verse.
  - **D-AGE-034 punto 2 y T-048:** la casilla "Confirmo que estos servicios se realizan en la
    Amazonía" en Venta directa, y "Crear y cobrar" deshabilitado cuando hay mezcla. P7 los quita.
    Si se aprueba, D-AGE-034 punto 2 se reemplaza y T-048 se reescribe (la propia tarea ya lo
    prevé si el contador no acepta la casilla).

### Lo que dicen las normas (investigación del 05-nov-2026; fuentes de SUNAT, en su mayoría antiguas)

- **Ley 27037:** la exoneración de un **servicio** depende de **quién emite** (una "empresa ubicada
  en la Amazonía": domicilio fiscal y sede allí, Registros Públicos, ≥70 % de activos fijos, sin
  producción fuera, y comunicado a SUNAT) y de **dónde se presta**. **No depende del cliente**
  (Oficio SUNAT 114-2000). Por eso los datos del cliente que trae la consulta del RUC no hacen
  falta. San Martín volvió al beneficio con la Ley 32317 (2025).
  - Una agencia de Lima que vende un lodge amazónico factura **gravado**.
  - La agencia de Tarapoto factura exonerado lo que se presta en la zona, y gravado lo de fuera
    (Cusco, internacional).
- **Intermediaria o revendedora:**
  - Si actúa como intermediaria, factura solo su fee o comisión, gravado.
  - Si revende, factura el total.
  - Las "liquidaciones de cobranza" no son comprobantes.
  - El pasaje aéreo ya se trata como intermediación (`tip_afe_igv` aplica solo a
    `fee_agencia_monto`).
- **Exportación:**
  - Paquete a una agencia u operador no domiciliado: hay que acreditar al no domiciliado y su
    último ingreso.
  - Hospedaje y alimentación a un turista no domiciliado: hasta 60 días, con TAM y pasaporte.
  - Hace falta un formulario propio, no solo el código 40.
- **Detracción:** comisión mercantil, código 024, 10 %, a partir de S/ 700.
- **Dólares:** TC venta SUNAT/SBS de la fecha de emisión. La NC usa el TC del comprobante original.
- **2026:** 18 % = 15.5 % IGV + 2.5 % IPM (Ley 32387). El total no cambia.
- **Series por afectación:** es legal (varias series por establecimiento). No es una norma, es
  orden interno del contador.

### Propuesta (borrador)

| # | Qué | Detalle |
|---|---|---|
| P1 | **Leyenda en el XML** | 2002 en Agencia (servicios); en retail, 2001 o 2002 según `products.tipo_bien_servicio` (vacío ⇒ 2001); 2003 queda preparado. En facturas, boletas y NC/ND. El PDF lee lo mismo que se mandó en el XML. Todos los giros. |
| P2 | **Configuración → Tratamiento tributario** | Se quita la lista Destino. Se agregan: ☐ *Empresa calificada en la Amazonía (Ley 27037)* + fecha de comunicación a SUNAT; **dos valores por defecto** (servicios locales / mayoristas); ☐ *Series por afectación*. Sin la empresa calificada, **Exonerado no aparece en ningún lado**. Solo lo cambia el Propietario o soporte, y queda registrado quién y cuándo. |
| P3 | **Ninguna lista tributaria en la cotización** (como pedía la obs. 10) | En el cotizador, ítem manual, pasaje aéreo, hotel no registrado, guía y penalidad, la línea muestra solo una **etiqueta** (`Gravado`, `Exonerado · Amazonía`), tomada de la tarifa o del valor por defecto. Lo que esté mal se corrige al facturar (P5). Queda **una sola lista "Afectación IGV"** únicamente en la tarifa de proveedor y en Configuración: Gravado / Exonerado – Amazonía / Inafecto. `destino_tributario` se sigue llenando solo (sin migrar datos). Fuera IVAP. Exportación y gratuitos quedan ocultos por ahora. |
| P4 | **Propuesta automática** | Orden: valor ya guardado → valor por defecto según el tipo de ítem (locales o mayorista). Opcional para después: distrito del proveedor (catálogo central de distritos amazónicos) y zona amazónica marcada en el árbol de destinos (para el mayorista). |
| P5 | **Grilla de facturar con columna "Afectación"** | Casilla por línea, prellenada y editable. La fila cambiada muestra "✎ cambiado", se guarda en `reserva_items` con usuario y fecha, sin motivo obligatorio. Resumen a la vista con op. gravadas / exoneradas / IGV / total. "Serie a emitir" se recalcula en vivo. Lo ya facturado queda bloqueado (se corrige con NC). Un solo componente para Facturar simple, Facturación especial y Venta directa. |
| P6 | **Precio** | Sigue siendo final, con todo incluido. Cambiar la afectación cambia el **desglose**, no el total ("El IGV sale del precio; el cliente sigue pagando S/ X"). El precio con IGV se decide al cotizar. |
| P7 | **Comprobante mixto por línea** | Se quita el bloqueo de mezcla (y con él, la excepción de `carga_tour`). Se quita la **confirmación obligatoria de la Amazonía**: el modal y la etiqueta "Sin confirmar" desaparecen, y su función pasa a la casilla de P5. Queda solo el bloqueo de exportación. |
| P8 | **Series por afectación × moneda** | Exonerado / Gravado / **Mixto** × PEN / USD, en facturas **y boletas** (12 por sucursal), elegidas solas según las líneas. Opcional por empresa; al activarlo se crean todas juntas. Hay que cambiar el índice único (migración nueva) y pasar la afectación a `resolverSerie()` desde `SaleController::store()`, `AdvanceController`, facturación de reservas y Venta directa. Las NC/ND quedan como están. |

Ejemplo de series (borrador): F001/B001 exonerado PEN · F002/B002 gravado PEN · F003/B003 exonerado
USD · F004/B004 gravado USD · F005/B005 mixto PEN · F006/B006 mixto USD.

### Respuestas del usuario (05-nov-2026)

1. La leyenda de Amazonía **va en el XML** (cat. 52: 2001 bienes, 2002 servicios, 2003
   construcción).
2. **Mixto por línea:** sí, cada ítem con su afectación en el mismo comprobante.
3. **Boletas** también separadas por serie. NC/ND: falta que responda el contador.
4. **Exportación y gratuitos:** para más adelante. No se muestran, y lo que ya está guardado se
   conserva.
5. **Exonerado que no sea por la Amazonía** (p. ej. transporte terrestre, Apéndice II): no se sabe;
   queda fuera por ahora (se mantiene 20 = Amazonía).
6. La agencia está constituida en **Tarapoto (San Martín)**.
7. **Primero la versión simple** (dos valores por defecto, sin cargar datos). El distrito del
   proveedor y la zona del destino quedan como opcionales.
8. La **casilla de afectación en la grilla de facturar**, prellenada y editable: sí.

### Preguntas abiertas (para el contador)

Cada una con su recomendación.

1. ¿La agencia ya **comunicó a SUNAT** que es empresa ubicada en la Amazonía? Es requisito para
   P2. (Que en San Martín se emita exonerado hoy ya lo dejó dicho la obs. 10.)
2. ¿Mixto **en cualquier combinación** o solo dentro de un tour? *Recomendado: en cualquiera.*
   (Dentro de un tour ya lo confirmó: N5.)
3. ¿Los mixtos tienen **serie propia** o van en la gravada? *Recomendado: propia (F005/F006).*
4. ¿Las **NC/ND** también se separan por afectación y moneda?
5. Al pasar una línea a gravado, ¿el IGV **sale del precio** o **se suma** al cliente?
   *Recomendado: sale del precio (es lo que hace hoy el sistema).*
6. ¿Se puede quitar la **confirmación ítem por ítem** de la Amazonía (P7)? Es un control que
   pusimos nosotros, no él.
7. ¿El **fee** de la agencia sobre un servicio prestado en la zona va exonerado?
8. Hoteles y tours: ¿la agencia **revende** (factura el total) o **intermedia** (solo el fee)?
9. ¿Las facturas a empresas llevan **detracción**?
10. ¿Qué **texto** de la leyenda quiere? (el del cat. 52 o el que imprime hoy el PDF: "OPERACIÓN
    EXONERADA DEL IGV — LEY N° 27037").
11. **(La más importante; es la pregunta 6 de la obs. 10.)** Si la agencia también vende
    servicios fuera de la Amazonía (Cusco, internacional), ¿eso cuenta como "producción fuera de
    la Amazonía" (art. 2 del D.S. 103-99-EF) y pone en riesgo la exoneración de todo lo demás?
    ¿Cambia si en esos casos solo intermedia (comisión)?
12. **Pasaje aéreo** (pregunta 4 de la obs. 10): ¿la tarifa de la aerolínea y el fee de la agencia
    van con afectaciones distintas?
13. ¿La **penalidad** sigue pidiendo el IGV a mano (D-AGE-033) o toma el del servicio que
    penaliza, como el ajuste de D-AGE-032? *Recomendado: el del servicio, corregible en P5.*

### Riesgos

- **Datos existentes:** tarifas con 20 en una empresa que no está calificada; reservas con ítems
  sin confirmar que pasarían a ser facturables de inmediato. Contarlos antes de P2 y P7.
- **Series que faltan:** con P8 activo, si falta una serie la venta falla ("No hay una serie
  activa…"). Hay que crear las 12 juntas.
- **El valor por defecto único de hoy** puede hacer nacer exonerados los ítems de mayorista. P2 lo
  corrige y conviene hacerlo pronto.
- **Retail** no cambia (P3 a P8 son solo de Agencia, salvo P1 y P8, que son de todos los giros).
- **T-048** (confirmación de la Amazonía en Venta directa): P7 la reescribe, y D-AGE-034 punto 2
  se reemplaza. Hay que mostrarle al contador las dos cosas juntas.
- **T-029** (bloqueada por la obs. 10): con este enfoque, **H18** (el guía y el ajuste nacen sin
  tratamiento) se resuelve con el valor por defecto de "servicios locales", así que se
  desbloquea apenas esté P2. **H19** (el costo del servicio compartido cuenta infantes) no es
  tributario: se puede sacar a una tarea propia y hacer ya.
- **D-AGE-033:** hay que escribir una decisión nueva que diga que un valor por defecto **visible y
  corregible al facturar** no es "silencioso", y qué pasa con la penalidad (pregunta 13).

### Orden sugerido (borrador)

P1 (no depende de nada) → P2 + P3 + P4 simple → P5 + P6 → P7 → P8 (después de las preguntas 2-4)
→ opcionales: distrito o zona en P4, NC/ND, exportación, gratuitos.

## 3. Resultado

- **Decisiones:**
- **Tareas:**
