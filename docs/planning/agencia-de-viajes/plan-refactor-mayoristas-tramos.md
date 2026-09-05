# Refactor: clasificación local/nacional/internacional, tramos multi-destino
# y ciclo completo con mayoristas (comisión oculta al cliente + pagos a proveedor)

> **Estado: SUPERADO por `alternativa_destinos` (decisión del usuario,
> 01-sep-2026) — este documento queda como registro histórico del
> diagnóstico, no se sigue diseñando acá.** `alternativa_tramos`
> (Problema B, §2 punto 2) **no se construye** — se adoptó
> `alternativa_destinos`, el diseño de la Línea 2
> (`docs/planning/agencia-de-viajes/auditoria-arquitectonica-agencia-viajes.md`
> §7, ejecutado vía `plan-ejecucion-multidestino-mayoristas.md`). Mapa de
> dónde queda cada problema de este documento:
>
> - **Problema A** (eje local/nacional/internacional equivocado) —
>   **resuelto** por el mismo diseño de `alternativa_destinos` (§7 de la
>   auditoría, línea 159): el toggle deja de ser una decisión manual de
>   toda la alternativa, se resuelve solo según la categoría del destino
>   activo. No requiere trabajo propio de este documento.
> - **Problema B** (falta concepto de tramo) — **resuelto** por
>   `alternativa_destinos`. Ver `plan-ejecucion-multidestino-mayoristas.md`
>   para la ejecución (sesiones 12b-12g).
> - **Problema C1** (PDF revela identidad del mayorista) — **confirmado
>   real** con una relectura directa del código el 01-sep-2026
>   (`AlternativaController::resolverNombreItemPdf()` línea 610:
>   `$item->opcionMayorista?->proveedor?->razon_social` se imprime tal
>   cual). La auditoría de la Línea 2 (§9) afirmaba lo contrario ("no se
>   detectó ninguna fuga") — corregido ahí mismo (§9.3, agregado
>   01-sep-2026). **Diseño CERRADO y brief listo:**
>   `opcion_mayorista.descripcion_publica` +
>   `PEGAR-EN-CLAUDE-CODE-fix-leak-mayorista-pdf.md` — fix chico y
>   autocontenido, sin dependencias de M1-M5/12a-12h, se puede ejecutar
>   en cualquier momento.
> - **Problema C2** (pagos a proveedor) — **superado por un diseño más
>   completo** de la Línea 2 (§12 de la auditoría):
>   `ObligacionProveedor`/`CuotaProveedor`/`PagoProveedor`/
>   `AplicacionPagoProveedor` reemplaza la idea de resucitar
>   `cronograma_pago_proveedor`/`pago_proveedor` — resuelve además el
>   caso de pago consolidado cubriendo varias reservas, que la propuesta
>   original de este documento no cubría. Pendiente de ejecución en
>   `plan-ejecucion-multidestino-mayoristas.md` (fuera de alcance de su
>   ronda actual, ver nota "queda fuera a propósito" en ese documento).
> - **Problema C3** (margen automático por mayorista sin conectar) —
>   **sigue sin resolver en ningún documento**, único pendiente
>   genuinamente huérfano de este plan. Candidato a una sesión chica
>   propia cuando se retome pagos a proveedor.
>
> Ronda 1 de este documento (P1-P12) queda **sin responder** — no hace
> falta, la mayoría ya está contestada por el diseño de
> `alternativa_destinos` (ver mapa arriba); lo que no lo está (C1
> propio, C3) se seguirá desde los documentos que corresponda, no desde
> acá.
>
> **Documentos relacionados, NO se tocan, se coordinan:**
> - `plan-modulo-cotizaciones-reservas.md` — documento base del módulo
>   completo (Cotización/Alternativa/AlternativaItem/Reserva). Este plan
>   propone cambios estructurales sobre ese modelo (tramos) y sobre §2.4
>   (comparador de mayoristas) — se fusiona/referencia al cerrar diseño,
>   igual que los demás sub-planes.
> - `plan-matriz-hoteles-cotizador.md` (EN DISEÑO, abierto 29-ago-2026) —
>   resuelve la matriz de opciones de hotel (`grupo_opcion_id`/
>   `opcion_elegida`, hotel ad-hoc sin depender de Proveedor registrado,
>   tratamiento tributario por fila). Se solapa con el "Problema C" de
>   abajo — cualquier decisión de ese plan sobre `opcion_mayorista`/
>   `opciones_hotel_tarifas` aplica acá también, sin re-discutirla. Ronda 4
>   de ese plan (P11-P12) ya responde una pregunta que el usuario repitió
>   acá ("cómo cargar sin depender de proveedores registrados") — confirmado
>   que sigue vigente, no se reabre.
> - `plan-fix-moneda-cotizador.md` (EN DISEÑO, sin implementar) — bug de
>   tipo de cambio sin validar + ítems mezclados PEN/USD. §13 de la
>   auditoría (arriba) ya confirma que la moneda se queda a nivel de
>   `Alternativa`, no de destino — ese punto de coordinación con la P4
>   original de este documento queda cerrado sin necesitar más diseño acá.
> - `plan-ejecucion-multidestino-mayoristas.md` — ejecución real de
>   `alternativa_destinos` (ver mapa de arriba). Reconciliado 01-sep-2026.

---

## 0. Disparador

El usuario compartió un caso real operativo que el diseño actual no puede
representar:

> Agencia en Tarapoto. Cliente de Cusco quiere: (1) un paquete de 2 días a
> Tarapoto con salida desde Cusco (vuelos, traslados, tours locales,
> alojamiento — la agencia lo arma sola, sin mayorista) y (2), a
> continuación, un paquete de 10 días a México (Cancún, Valladolid, Mérida,
> Bacalar) con salida desde Tarapoto. Para el segundo tramo la agencia llama
> a 2-3 mayoristas (ej. CIC Travel, Costamar, Chek-in, Mundo Reps, Destinos
> Mundiales — empresas registradas en Perú, con alianzas de aerolíneas/
> hoteles/tours tanto nacionales como internacionales), cada uno devuelve
> "todo incluido" (vuelos desde Lima, hoteles, traslados, tours) con al
> menos 3 opciones de hotel en una matriz de precios. Se elige la mejor
> propuesta (no siempre la más barata) y se le manda al cliente **sin que
> sepa qué mayorista es**. El cliente acepta, hace un adelanto, se genera
> la reserva, y la agencia necesita registrar con qué mayorista trabajó
> para armar **su propio cronograma de pago a ese proveedor** (a veces a
> crédito) — módulo que hoy no existe.

Disparador original de la conversación: auditoría de la pestaña
"Internacional" del cotizador (ver historial de esta sesión) — reveló que
el margen automático por mayorista, el cupo de salidas de catálogo, los
tours opcionales y el detalle de vuelo nunca llegan a conectarse, y que el
PDF final **revela la razón social del mayorista al cliente** en vez de
ocultarla. El usuario planteó que el modelo original ("un campo de texto
libre + cambiar el hotel + precio total") no es funcional, y que
local/nacional/internacional puede no ser el eje correcto de clasificación
porque los mismos mayoristas venden ambos.

## 1. Diagnóstico (cerrado 31-ago-2026)

Se identifican **3 problemas distintos**, hoy mezclados en una sola
pregunta ("cómo mejoro Internacional"). Separarlos es la base de este plan.

### Problema A — "Local/Nacional/Internacional" es el eje de clasificación equivocado

El drawer del cotizador usa `modo: 'local' | 'intl' | 'guia'`
(`cotizador/editar.vue:827`) y el comparador de mayoristas solo aparece
bajo el modo `'intl'` — el sistema asume implícitamente "mayorista =
internacional". El caso real del usuario lo contradice: los mismos
mayoristas (CIC Travel, Costamar, etc.) venden paquetes nacionales e
internacionales por igual, y hoy no hay forma de usar el comparador de
mayoristas para un paquete nacional sin fingir que es internacional.

**El eje real no es el destino, es cómo se consiguió el precio:**
- **Catálogo propio** (`proveedor_tarifas`) — tarifa propia, versionada,
  reutilizable mes a mes.
- **Cotización puntual** (`opcion_mayorista`, y por el mismo criterio ya
  usado en el propio código, `cotizacion_pasaje_aereo`) — te la dieron
  ahora, para este viaje, no es reusable tal cual.

El destino (local/nacional/internacional) debería ser un **dato del
ítem/tramo** (`DestinoTreeSelect` ya existe para eso), no el interruptor
que decide qué formulario ves.

### Problema B — no existe el concepto de "tramo" dentro de una cotización

Verificado en schema:
- `cotizaciones.destino` — un solo `string` para toda la cotización
  (`2026_07_28_090100_create_cotizaciones_table.php`).
- `cotizaciones.fecha_viaje_tentativa` — una sola fecha.
- `alternativa_items.dia_referencial` — solo un entero, sin nombre ni
  destino propio (`AlternativaItem.php:47`).

El caso del usuario (Cusco→Tarapoto 2 días local, luego Tarapoto→México 10
días con mayorista) **no se puede modelar como una sola cotización
coherente hoy**. Las dos alternativas actuales son malas: dos Cotizaciones
separadas (rompe continuidad: mismo cliente/pasajeros pero dos códigos, dos
aceptaciones, dos adelantos administrados aparte) o una sola Cotización con
"Día 1...Día 12" en una lista plana (el cliente no distingue qué paquete es
cuál, y el sistema no sabe que son dos fechas de viaje y dos destinos
distintos — con impacto directo en Reporte Operativo, que ya trabaja por
fecha/destino real de la reserva).

Mismo patrón que usan sistemas de tour operador (Tourwriter, TravelStudio y
similares): el itinerario no es una lista plana de días, es una
**secuencia de tramos/segmentos**, cada uno con su propio destino, rango de
fechas y servicios adentro.

### Problema C — el comparador de mayoristas: parcialmente resuelto en otro plan, con 2 gaps nuevos reales

`plan-matriz-hoteles-cotizador.md` (Ronda 4, P11-P12, cerrada 29-ago) ya
resuelve la pregunta "cómo cargar sin depender de proveedores registrados":
hotel ad-hoc tipeado inline (nombre + matriz de precios por tipo de
habitación), sin necesidad de que el hotel exista como Proveedor. También
ya resuelve agrupar varias opciones de hotel bajo una tarjeta colapsable en
vez de una Alternativa por hotel.

Lo que ese plan **no cubre**, y que este caso agrega como requisito nuevo y
real:

**C1 — el PDF revela la identidad del mayorista al cliente (bug real,
verificado).** `AlternativaController::resolverNombreItemPdf()` (líneas
594-596) devuelve `$item->opcionMayorista?->proveedor?->razon_social` para
un ítem de mayorista — el nombre legal completo, tal cual. Esto contradice
directamente el requisito de negocio ("el cliente no tiene que saber qué
mayorista es") y es un riesgo comercial concreto: revelar el mayorista deja
al cliente en condiciones de saltear a la agencia la próxima vez.

**C2 — pagos a proveedor con cronograma de crédito: schema existe, cero
lógica construida.** Ya documentado como pendiente en el roadmap general
("Compras — módulo futuro"). Confirmado en código:
`cronograma_pago_proveedor` (cuota, monto programado, fecha de vencimiento,
estado pendiente/pagado/vencido) y `pago_proveedor` (pagos ya realizados,
moneda, tipo adelanto/pago final) — ambas con FK a `proveedor_id`/
`opcion_mayorista_id`, exactamente el caso del usuario. Pero solo existen
los modelos Eloquent (`PagoProveedor.php`, `CronogramaPagoProveedor.php`) —
sin controller, sin ruta, sin pantalla. Es el "espejo" de Amortizaciones
(lo que el cliente le debe a la agencia) para el otro lado del negocio (lo
que la agencia le debe al proveedor).

**C3 (heredado de la auditoría anterior, sigue vigente) — margen
automático por mayorista no está conectado.** Con el volumen real descrito
(2-3 mayoristas × 3-4 opciones de hotel = hasta 12 pares costo/venta por
cotización), no automatizar el margen default del proveedor es el cuello
de botella que el módulo debía eliminar.

## 2. Dirección de diseño tentativa (sujeta a las rondas de preguntas)

**Punto de partida, no decisión cerrada.**

1. **Problema A:** el toggle "Local/Nacional vs Internacional" del drawer
   deja de decidir el flujo. Se reemplaza el modo `'intl'` por algo como
   **"Cotización de mayorista/consolidador"**, disponible sin importar el
   destino del ítem — mismo formulario para Destinos Mundiales-Tarapoto que
   para Destinos Mundiales-México, cambia el destino que se le asigna al
   ítem/tramo, no el mecanismo de carga.

2. **Problema B:** agregar un agrupador liviano arriba de "día" —
   `alternativa_tramos` (nombre, destino, fecha_desde, fecha_hasta, orden)
   — sin reemplazar `alternativa_items.dia_referencial`, que pasa a ser
   relativo a su tramo. Un tramo es su propio mini-itinerario; varios
   tramos conviven en una Alternativa/Cotización/cliente/PDF, con sección
   propia por tramo en el PDF (mismo criterio ya decidido para la tabla
   matriz de hoteles: "aparte, como sección propia").

3. **Problema C1:** agregar `descripcion_publica` (nullable) a
   `opcion_mayorista` — lo que ve el cliente en cualquier documento, en vez
   de `proveedor.razon_social`. Ajustar `resolverNombreItemPdf()` (y
   cualquier otro punto que resuelva nombre de ítem de mayorista, ver la
   triplicación ya detectada en `plan-matriz-hoteles-cotizador.md` Ronda 5)
   para usar este campo con fallback genérico ("Paquete todo incluido"),
   nunca la razón social.

4. **Problema C2:** construir el CRUD real de `cronograma_pago_proveedor`/
   `pago_proveedor`, activado desde el momento de aceptar una reserva con
   una `opcion_mayorista` elegida — mismo patrón que ya funciona para
   Amortizaciones, espejado hacia proveedores.

5. **Problema C3:** conectar `Proveedor.margen_default_tipo`/
   `margen_default_valor` al formulario de carga de tarifa de hotel
   (autocompletar "Venta" desde "Costo" + margen, editable línea por
   línea) — mismo criterio que ya se declaró en el plan base y nunca se
   implementó.

## 3. Rondas de preguntas/casos (se resuelven una a la vez)

### Ronda 1 — ABIERTA

**Problema A — reclasificación:**
- P1. ¿El toggle "Local/Nacional vs Internacional" desaparece del todo, o
  queda como filtro rápido dentro de un único modo "Cotización de
  mayorista"?
- P2. ¿Un mismo Proveedor tipo "mayorista" puede tener ítems para destinos
  locales Y internacionales dentro de la misma Alternativa/tramo, o un
  tramo siempre trabaja con un solo mayorista?
- P3. El modo "Guías" ya funciona bien aparte — ¿se mantiene como está o
  también se reconsidera dentro de este rediseño?

**Problema B — tramos:**
- P4. ¿Un tramo puede tener su propia moneda de cotización/tipo de cambio
  distinto del resto de la Alternativa (ej. México en USD, Tarapoto en
  PEN)? Hoy `moneda_cotizacion`/`tipo_cambio_aplicado` son de la
  Alternativa completa, no del ítem ni de un tramo — interactúa con
  `plan-fix-moneda-cotizador.md`, coordinar antes de decidir.
- P5. ¿La reserva se genera UNA por tramo (2 reservas: Tarapoto + México) o
  UNA sola con 2 tramos adentro? Pega directo en Reporte Operativo,
  check-in y facturación (la fila 11w ya factura por subgrupos de
  pasajeros/cliente — un tramo podría ser un subgrupo natural más).
- P6. El adelanto del cliente (`reserva_anticipos`) — ¿uno por tramo o uno
  solo para todo el viaje?
- P7. ¿Puede un pasajero ir en un tramo y no en el otro (ej. toda la
  familia a Tarapoto, solo 2 a México)? Afecta cómo se resuelve
  `pax_incluidos` a nivel de tramo.
- P8. ¿El contador de "Día N" se resetea en cada tramo (Día 1 de México en
  vez de Día 3) o sigue corriendo de forma continua para todo el viaje?

**Problema C — mayorista oculto + pagos a proveedor:**
- P9. `descripcion_publica` en `opcion_mayorista` — ¿la escribe el
  vendedor a mano cada vez, o conviene un default reusable por mayorista
  (ej. en `Proveedor`) para no re-tipearla en cada cotización?
- P10. Cronograma de pago a proveedor — ¿lo arma el vendedor a mano (como
  las cuotas fijas de Amortizaciones) o el mayorista ya manda un
  cronograma que solo se transcribe?
- P11. ¿El cronograma se vincula 1:1 con una reserva aceptada, o puede
  cubrir varias reservas del mismo mayorista a la vez (ej. pago
  consolidado mensual a Destinos Mundiales por todas las reservas del
  mes)?
- P12. ¿Hace falta moneda de crédito separada (mayorista cobra en USD,
  agencia paga en PEN al tipo de cambio del día de pago) — mismo patrón
  que ya existe en Amortizaciones/Caja, o alcanza con un solo campo
  moneda como hoy tiene `pago_proveedor`?

## 9. Historial de la sesión de diseño

| Fecha | Cambio |
|---|---|
| 31-ago-2026 | Documento abierto a partir de un caso real del usuario (Cusco→Tarapoto local + Tarapoto→México con mayorista, ocultar identidad del mayorista, cronograma de pago a proveedor a crédito). Diagnóstico cerrado (§1): 3 problemas separados — clasificación local/nacional/internacional equivocada como eje (Problema A), falta de concepto de tramo multi-destino dentro de una cotización (Problema B), y 3 gaps del comparador de mayoristas no cubiertos por `plan-matriz-hoteles-cotizador.md` (revelar mayorista al cliente en el PDF — bug real verificado en `AlternativaController::resolverNombreItemPdf()` líneas 594-596 —, módulo de pagos a proveedor sin lógica construida pese a tener schema, margen automático sin conectar). Dirección tentativa (§2) propuesta, arranca Ronda 1 de preguntas — ninguna respondida todavía. |
| 01-sep-2026 | **Documento cerrado — superado por `alternativa_destinos`.** El usuario decidió explícitamente: "alternativa_tramos no va, estamos trabajando con alternativa_destinos" (el diseño de la Línea 2, `auditoria-arquitectonica-agencia-viajes.md` §7, ejecutado en `plan-ejecucion-multidestino-mayoristas.md`). Se mapean los 3 problemas de este documento contra ese diseño: A y B quedan resueltos sin trabajo propio; C2 (pagos a proveedor) queda superado por un diseño más completo (`ObligacionProveedor`/`CuotaProveedor`/`PagoProveedor`/`AplicacionPagoProveedor`, §12 de la auditoría); C1 (mayorista visible en PDF) se **reconfirma real** con lectura directa del código (línea 610 actual, no las 594-596 originales — el archivo se movió desde que se abrió este documento), contradiciendo la afirmación de la Línea 2 de que "no hay fuga". C3 (margen automático) queda como único pendiente huérfano. Ronda 1 (P1-P12) nunca se respondió — no hizo falta. |
| 01-sep-2026 (más tarde) | **C1 cerrado el mismo día.** Diseño agregado a `auditoria-arquitectonica-agencia-viajes.md` §9.3 y brief `PEGAR-EN-CLAUDE-CODE-fix-leak-mayorista-pdf.md` — `opcion_mayorista.descripcion_publica`, sin ningún fallback a datos del `Proveedor`. Se corrigen 2 errores encontrados en el brief antes de darlo por listo: referencia rota a un archivo inexistente (`auditoria-arquitectonica-profunda-sintesis.md` → el nombre real es `auditoria-arquitectonica-agencia-viajes.md`, mismo error ya visto 2 veces en otros documentos de la Línea 2) y una migración que habría fallado (`->after('nombre')` sobre una columna que `opcion_mayorista` no tiene — corregido a `->after('incluye')`, columna real confirmada contra la migración de creación). Único pendiente huérfano restante de todo este documento: C3 (margen automático por mayorista sin conectar). |
