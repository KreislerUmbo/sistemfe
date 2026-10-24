# Requerimiento — Modificar una reserva aceptada (F4 etapa 2)

| | |
|---|---|
| **Fecha** | 2026-10-24 (decisión de fondo: 23-oct-2026) |
| **Módulo** | AGE |
| **Pedido por** | usuario |
| **Estado del documento** | en análisis — faltan respuestas a §2.5 |

> Primer requerimiento escrito con `docs/proceso.md` (piloto). Antecedentes: fila F4 de
> `docs/planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md` (etapa 1 hecha el 23-oct:
> vínculo `reserva_pasajeros.cotizacion_pasajero_id` y "quitar pasajero" bloqueado) y
> `plan-modulo-cotizaciones-reservas.md` §4.3.

## 1. Lo que se necesita

Después de que el cliente acepta una alternativa, la reserva cambia: se suma o se baja un
pasajero, se agrega un servicio, cambia una habitación. Hoy:

- agregar un pasajero en la reserva **no cambia ningún precio** (viaja gratis);
- quitar uno está bloqueado (etapa 1), porque en un tour el último grupo terminaba pagando su
  parte;
- en la reserva no se puede editar un precio ni agregar un servicio con precio.

**Decidido (23-oct, opción C):** los precios se calculan en **un solo lugar, el cotizador**.
Desde la reserva, "Modificar reserva" abre una versión nueva de la alternativa aceptada en el
cotizador. Ahí se ajustan pasajeros, servicios y habitaciones; se ve la diferencia con lo
vendido; se le manda al cliente un PDF de la modificación, y "Aplicar a la reserva" pasa solo
la diferencia. La reserva conserva lo que ya tiene: nombres, check-in, salidas, facturas y
anticipos.

**Reglas ya acordadas:**
- Lo facturado no se toca: si la modificación sube el monto, la diferencia sale en otro
  comprobante; si lo baja, se hace con nota de crédito.
- Quitar un pasajero no facturado lleva una **penalidad** según los términos y condiciones.
  Hoy esos términos son texto libre, así que la penalidad es un servicio "Penalidad por
  cancelación — pasajero" con monto y motivo que escribe el vendedor. Las reglas
  configurables por días quedan para después.
- Lo que no tiene regla de cálculo (habitación, transporte privado, mayorista, pasaje aéreo)
  queda **"para revisar"**, y el aviso lleva al lugar donde se corrige.

## 2. Análisis

### 2.1 Lo que hay hoy en el código (leído el 24-oct)

| Hecho | Dónde | Por qué importa |
|---|---|---|
| La reserva apunta a **una** alternativa (`reserva.alternativa_id`); cada `reserva_item` apunta a un `alternativa_item` | `ReservaController::crearReservaDesdeAlternativa()` | La versión nueva necesita un camino para re-apuntar |
| **El precio de un servicio de la reserva no se guarda en la reserva**: facturar lo lee en vivo de `alternativaItem->total_convertido` | `ReservaFacturacionController` (líneas ~946, 1028, 1114, 1532) | **No se puede editar la alternativa aceptada en el lugar**: cambiaría el precio de lo ya vendido y de lo facturado a medias |
| Los pasajeros son de la **cotización** (`cotizacion_pasajeros`), no de la alternativa; `alternativa_items.pax_incluidos` guarda ids de esa tabla | `CotizacionPasajero`, `CotizacionController` (pasajeros bloqueados si hay alternativa aceptada) | Una versión con otros pasajeros no puede compartir la lista con la versión 1 |
| Ya existe `AlternativaController::duplicar()` (copia destinos, hoteles ad-hoc, mayoristas, ítems) | `AlternativaController` | Base reutilizable para crear la versión |
| Ya existe `sincronizarItems()`: crea en la reserva los ítems nuevos de la alternativa, con lock | `ReservaController` | Mismo patrón para "aplicar" ítems agregados |
| Recalcular por pasajeros ya existe en el cotizador | `CotizacionController::recalcularItemsPorPersona()`, `TourLineaService` | El cálculo de la versión nueva no se reescribe |
| "Qué está facturado" ya está centralizado | `ReservaVenta::itemIdsFacturados()` / `pasajeroIdsFacturados()` | La diferencia lo usa, no lo vuelve a escribir |
| Quitar pasajero: la lógica ya existe, bloqueada | `QuitarPasajeroReservaService::quitar()` | Se reusa al aplicar |
| Al aceptar, el cupo del mayorista sube por cantidad de pasajeros | `SalidaMayorista::increment('cupo_ocupado')` en `crearReservaDesdeAlternativa()` | Aplicar debe ajustarlo si cambia la cantidad |
| `reglas_cancelacion` existe (franjas de días → % de reembolso) pero nada la usa | migración `2026_07_28_120000`, `ReglaCancelacion` | Base para las reglas por días, después |

### 2.2 Hallazgo fuera de alcance

`AlternativaItemController::update()` (cambiar el precio o el descuento de un servicio) **no
revisa si la alternativa ya está aceptada**. Los demás endpoints sí lo revisan (`precioCarga`,
`reasignarDia`, `moverBloque`, descuento global). La pantalla oculta la edición, pero con una
llamada directa a la API se cambia el precio de un servicio ya vendido, y como facturar lee el
precio en vivo (§2.1), el cambio llega a lo que falta facturar. Lo vi leyendo el código, no lo
probé. Va al backlog como tarea independiente y conviene hacerla antes que este
requerimiento.

### 2.3 Casos

| # | Situación | Qué debe pasar |
|---|---|---|
| 1 | Sin facturar, se **agrega** un adulto a un tour por persona | La versión 2 recalcula el tour; diferencia `+precio adulto`; al aplicar, se crea el pasajero en la reserva, asignado a los servicios que correspondan |
| 2 | Sin facturar, se **quita** un pasajero | Diferencia `−su parte`; el vendedor carga la penalidad (monto + motivo); al aplicar, se quita el pasajero (`QuitarPasajeroReservaService`) y se crea el servicio de penalidad |
| 3 | Se quita un pasajero **ya facturado** | No se quita directo: la diferencia indica "requiere nota de crédito" con enlace al comprobante; no se aplica hasta resolverlo (o se aplica todo menos eso, ver pregunta 4) |
| 4 | Se **agrega un servicio** nuevo | Diferencia `+servicio`; al aplicar, se crea el `reserva_item` (como hace `sincronizarItems()`) |
| 5 | Sube el precio de un servicio **ya facturado** | No se toca el facturado; la diferencia queda como un cargo adicional por facturar (ver pregunta 3) |
| 6 | Baja el precio de un servicio ya facturado | "Requiere nota de crédito", con enlace |
| 7 | Cambia la cantidad en una habitación, transporte privado, mayorista o pasaje aéreo | No se recalcula solo: queda "para revisar" con enlace al servicio en la versión 2; no se aplica mientras haya algo "para revisar" sin confirmar |
| 8 | Mayorista con salida y cambia la cantidad de pasajeros | Al aplicar se ajusta `cupo_ocupado` por la diferencia; si se pasa del cupo, mismo aviso que al aceptar |
| 9 | Servicio enganchado a una salida operativa compartida | Al quitar un pasajero no se toca la salida (sigue compartida); al agregar, el pasajero se suma a la salida que ya tiene el servicio |
| 10 | Dos personas aplican la misma modificación, o se factura mientras está abierta | Aplicar bloquea la reserva, recalcula la diferencia bajo lock y, si cambió desde que el usuario la vio, no aplica y lo dice |
| 11 | Se abre una modificación y se abandona | Se puede descartar; la reserva no cambia. Solo una modificación abierta por reserva |
| 12 | Reserva cancelada | No se puede modificar |
| 13 | Aplicar sin cambios | No hace nada y lo dice |

### 2.4 Reglas propuestas

Llevan ID definitivo cuando se cree `docs/modulos/agencia/reglas.md`.

1. Una reserva activa tiene como máximo **una modificación abierta**.
2. La modificación es una **versión nueva**: la versión vendida no se edita nunca (ver la
   razón en §2.1).
3. La diferencia se calcula por línea, comparando lo vendido (versión vigente) con lo
   propuesto (versión nueva): `agregado`, `quitado`, `cambió de precio`, `igual`, `para
   revisar`, `requiere nota de crédito`.
4. Una línea o pasajero **facturado** nunca se modifica ni se quita al aplicar.
5. Quitar un pasajero no facturado exige una penalidad (el monto puede ser 0, con motivo
   escrito).
6. No se aplica mientras haya líneas "para revisar" sin confirmar.
7. Aplicar es todo o nada, en una transacción, con lock de la reserva y la diferencia
   recalculada adentro.
8. Al aplicar, la reserva conserva sus datos operativos: nombres, documentos, check-in,
   salidas, fechas manuales y vuelos de agencia.
9. Toda modificación aplicada queda registrada: quién, cuándo, la diferencia y la penalidad.

### 2.5 Preguntas abiertas (cada una con su recomendación)

**1. ¿Dónde vive la versión nueva?** Es la decisión central, porque los pasajeros son de la
cotización y no de la alternativa (§2.1).
- **A (recomendada) — una cotización de modificación:** una cotización nueva, ligada a la
  reserva (`origen = 'modificacion'`, oculta del listado comercial como ya pasa con venta
  directa), con copia de los pasajeros (cada uno sabe de cuál viene) y copia de la
  alternativa aceptada. Se usa el cotizador **tal cual**: agregar o quitar pasajeros
  recalcula con lo que ya existe, y la versión vendida no se toca.
- **B — versión dentro de la misma cotización:** una alternativa nueva en la misma
  cotización, con su propia lista de pasajeros. Obliga a mover los pasajeros de la cotización
  a la alternativa, lo que toca casi todo el cotizador. Más riesgo.

**2. Al aplicar, ¿cómo se re-apuntan los servicios de la reserva?** Recomendación: cada
servicio de la versión nueva recuerda de cuál de la versión vendida viene
(`alternativa_items.item_origen_id`). Al aplicar:
- lo **no facturado** pasa a apuntar a la versión nueva (toma el precio nuevo);
- lo **facturado** sigue apuntando a la versión vendida (su precio no cambia);
- lo nuevo se crea y lo quitado se borra.

La reserva pasa a `alternativa_id` = versión nueva. La alternativa anterior queda como
historial, con un estado nuevo (`reemplazada`).
*Alternativa descartada:* guardar el precio en cada `reserva_item`. Es más robusto a largo
plazo, pero cambia la facturación entera; no conviene mezclarlo con este requerimiento.

**3. Sube el precio de algo ya facturado (caso 5): ¿cómo se cobra la diferencia?**
Recomendación: un servicio "Ajuste — <nombre del servicio>" por el monto de la diferencia,
que se factura como cualquier otro (camino 2 de `plan-modulo-cotizaciones-reservas.md`
§4.3, "documento adicional"). El camino 1 (anular todo con nota de crédito y refacturar)
queda para después.

**4. Si hay una línea que "requiere nota de crédito", ¿se bloquea todo o se aplica lo demás?**
Recomendación: **bloquear y avisar**. El vendedor primero emite la nota de crédito, con
enlace directo, y después aplica. Es más simple y no deja reservas a medio modificar.

**5. ¿Qué tratamiento de IGV lleva la penalidad?** Una penalidad por cancelación puede
considerarse indemnización (sin IGV) o servicio gravado; **hay que confirmarlo con el
contador**. Recomendación: el vendedor elige el tratamiento al cargarla, sin valor por
defecto silencioso (regla tributaria del proyecto), hasta que el contador defina uno.

**6. ¿Qué lleva el PDF de modificación?** Recomendación:
- lo vendido y lo nuevo, solo las líneas que cambian;
- la penalidad;
- el total nuevo, lo ya facturado o pagado (anticipos) y el saldo;
- el mismo membrete del PDF de cotización (`MembreteAgenciaPdfService`).

**7. ¿Permiso propio?** Recomendación: un permiso nuevo `reservas.modificar`, además de
`ReservaPolicy::update` (que ya revisa a quién pertenece la reserva).

### 2.6 Riesgos

- **Dinero:** es el punto más delicado. La diferencia debe cuadrar al céntimo con lo que
  después se factura, sobre todo en tours repartidos en varias líneas de IGV y en
  `partes_tour`. Necesita tests de cuadre explícitos.
- **Tributario:** la penalidad (pregunta 5). Una línea nueva con un destino tributario
  distinto pasa por el mismo guardia de mezcla de `ReservaFacturacionController`.
- **Concurrencia:** aplicar contra facturar al mismo tiempo (caso 10). Mismo orden de locks
  que facturar y cancelar, para que no se bloqueen entre sí.
- **Datos existentes:** las reservas actuales no tienen versión; la versión vendida es su
  alternativa actual. No hace falta migrar datos.
- **Alcance:** es grande. Por eso va partido en tareas que se revisan una por una (§2.7).

### 2.7 Desglose propuesto (se confirma al aprobar)

| Tarea | Qué | Depende de |
|---|---|---|
| T-001 | Guard de alternativa aceptada en `AlternativaItemController::update()` (hallazgo §2.2) | — |
| T-002 | Crear y descartar una modificación: cotización de modificación + copia de pasajeros y alternativa + `item_origen_id`; una sola abierta | pregunta 1 |
| T-003 | Calcular la diferencia (servicio puro + endpoint), con todos los casos de §2.3 y tests de cuadre | T-002 |
| T-004 | Penalidad por pasajero quitado (servicio de penalidad, monto, motivo, IGV elegido) | T-003, pregunta 5 |
| T-005 | Aplicar a la reserva: re-apuntar, crear, quitar, pasajeros, cupo del mayorista, salidas, registro; lock y recálculo | T-003, T-004 |
| T-006 | PDF de modificación | T-003 |
| T-007 | Pantallas: botón en la reserva, cotizador en modo "modificación", panel de diferencia, aplicar | T-005, T-006 |

## 3. Resultado

Se completa al aprobar.

- **Decisiones:**
- **Tareas:**
