# Matriz de opciones de hotel dentro de una misma Alternativa

> **Estado: DISEÑO CERRADO (29-ago-2026, 6 rondas / 18 preguntas) —
> EJECUCIÓN EN CURSO.** Traducido a sesiones concretas en
> `docs/planning/agencia-de-viajes/plan-ejecucion-matriz-hoteles-cotizador.md`
> (sesiones M1-M5). Este documento queda como referencia de diseño — no
> se vuelve a editar salvo que aparezca un caso nuevo durante la
> implementación que obligue a revisar una decisión ya cerrada.
> Documento base: `plan-modulo-cotizaciones-reservas.md` (no se toca —
> este es un sub-plan aparte).
>
> **Nota 01-sep-2026:** en el camino a cerrar este diseño se encontró y
> corrigió un conflicto real con otro plan paralelo
> (`plan-ejecucion-multidestino-mayoristas.md`, Línea 2 — auditoría hecha
> sin ver este documento, planeaba deprecar
> `alternativa_items.opcion_hotel_tarifa_id`, columna que este plan
> necesita viva). Ver el historial de
> `plan-ejecucion-matriz-hoteles-cotizador.md` para el detalle completo.

---

## 0. Disparador

El usuario compartió 3 cotizaciones reales de la agencia en Word
(`docs/auxiliares/`: Local Alto Mayo, Nacional Cusco, Internacional
Panamá). Las 3 comparten el mismo patrón: una tabla **"Opciones de
hoteles"** (filas = hotel + categoría + régimen, columnas = tipo de
habitación) con un precio por celda — todo lo demás del itinerario (tours,
traslados, guía) es idéntico sin importar qué hotel elija el cliente.

## 1. Diagnóstico (cerrado 29-ago-2026)

**Lo que el sistema ya tiene, y no hace falta tocar:**
- `proveedor_tarifas` ya modela una celda de esa matriz completa:
  `tipo_habitacion`, `moneda`, `regimen_comida`, `precio_venta_adulto/
  nino/infante`, cama adicional, vigencia con versionado real, `activo`.
- `proveedor_alojamiento_detalle` (1:1 con el proveedor) ya guarda
  `hora_checkin`/`hora_checkout`/`edad_max_infante_gratis`/
  `edad_max_nino_cama_adicional` — el mismo dato fijo por hotel que
  aparece repetido en cada ficha de los 3 documentos.
- Ya existe un componente que dibuja la matriz completa:
  `HabitacionMatrixPicker.vue` (filas = tipo de habitación, precio por
  fila) — pero está diseñado para que el **vendedor** compare y elija
  UNA sola opción al armar la cotización (colapsa la matriz a un único
  `alternativa_item` y descarta el resto al hacer clic en "Agregar").

**El gap real, uno solo pero estructural:**
`alternativa_items.proveedor_tarifa_id` es una FK singular — un ítem de
cotización = una tarifa fija = un precio ya decidido. El PDF
(`alternativa.blade.php`) renderiza una lista plana, una línea por ítem,
un precio cada una — no existe ningún bloque que itere hotel→habitación
como tabla. El único mecanismo que sí preserva una matriz completa
(`opciones_hotel`/`opciones_hotel_tarifas`) está atado exclusivamente a
`paquete_plantilla_id`/`opcion_mayorista_id` (catálogo de tours/
mayoristas) — nunca a un `alternativa_item` de una cotización armada a
mano en el cotizador.

**El workaround que ofrece el sistema hoy (crear varias Alternativas, una
por hotel) queda descartado** — confirmado con el usuario: duplica todo
el itinerario/tours/traslados solo para variar el hotel, y le manda al
cliente 5 PDFs con información repetida donde la única diferencia real es
el hotel. No es lo que la agencia hace hoy en Word ni lo que se quiere
replicar.

**Gaps menores, aparte del diseño central (bajan de prioridad hasta
cerrar lo estructural):**
- `categoria_estrellas` solo existe en `opciones_hotel` (mecanismo de
  paquetes), no en `proveedores`/`proveedor_alojamiento_detalle`
  (proveedor local) — inconsistente si la matriz nueva debe mostrar
  estrellas.
- `tipo_habitacion` es un string validado por separado en dos
  controllers (`ProveedorTarifaController` y el de `OpcionHotelTarifa`)
  sin catálogo compartido — mismo patrón de riesgo que el bug ya
  conocido de `proveedor_tipos.slug` desalineado (ver memoria de
  proyecto).

## 2. Dirección de diseño tentativa (sujeta a las rondas de preguntas)

Reutilizar `alternativa_items` tal cual existe hoy (cada opción sigue
siendo un ítem normal, con su `proveedor_tarifa_id`/precio/moneda reales)
y agregar solo un **agrupador ligero**, sin tablas nuevas:

- `alternativa_items.grupo_opcion_id` (nullable) — varias filas con el
  mismo valor son opciones intercambiables entre sí para el mismo
  concepto (ej. "hospedaje de estas 4 noches en Cusco").
- `alternativa_items.opcion_elegida` (boolean, default `false`) — ver
  Ronda 2/P6 para la mecánica completa (reemplaza el `es_opcion_base`
  de la primera versión de este párrafo). Mientras ninguna fila del
  grupo la tiene en `true`, el grupo está "abierto": precio en vivo =
  mínimo de lista del grupo ("desde $X"), sin descuento aplicado a
  ninguna opción. Al marcarla en una fila (resolver el grupo, requerido
  antes de aceptar), esa línea se comporta como cualquier ítem normal
  (entra al reparto de descuento, genera `reserva_item` al aceptar); las
  demás quedan en `false` como historial de lo ofrecido, sin reserva.
- Bloque nuevo en el blade del PDF que agrupe por `grupo_opcion_id` y
  dibuje la tabla matriz (igual a la de los 3 docx) en vez de la línea
  plana actual — los ítems sin grupo siguen exactamente como hoy.

Alternativa descartada por complejidad: extender `opciones_hotel`/
`opciones_hotel_tarifas` con FK a `alternativa_items` — mezclaría dos
mecanismos (catálogo de paquetes vs. cotización ad-hoc) hoy limpiamente
separados.

**Esto es punto de partida, no decisión cerrada** — las rondas de abajo
lo van a confirmar, ajustar o descartar según lo que salga.

## 3. Rondas de preguntas/casos (se resuelven una a la vez)

### Ronda 1 — CERRADA (29-ago-2026)

**P1. ¿Cómo se entera el sistema de cuál opción eligió el cliente, si hoy
no hay portal de cliente?**
**Decidido:** el vendedor la marca a mano dentro de la cotización (el
cliente avisa por WhatsApp/llamada/email, fuera del sistema). Queda
abierto para la Ronda 2 qué pasa si el vendedor hace clic en "Aceptar"
sin haber marcado ninguna — necesita un default explícito o un guard,
no puede quedar ambiguo.

**P2. ¿El agrupador debe ser específico de Hotel o genérico para
cualquier tipo de ítem?**
**Decidido:** solo Hotel — `origen_tipo=proveedor` con proveedor tipo
Hotel/alojamiento. Sin necesidad de resolver cómo se vería una "matriz"
para otros tipos de servicio.

**P3. ¿Puede haber más de un grupo de opciones dentro de la misma
alternativa (ej. 2 ciudades, cada una con su propia matriz)?**
**Decidido:** sí, sin límite — `grupo_opcion_id` ya lo soporta de forma
natural, cada grupo se dibuja como su propia tabla matriz en el PDF.

### Ronda 2 — CERRADA (29-ago-2026)

**P4. Si el vendedor hace clic en "Aceptar" con un grupo sin marcar,
¿qué pasa?**
**Decidido:** se bloquea con 422 — mismo patrón que otros guards del
módulo (ej. tratamiento tributario mixto). No se puede aceptar una
alternativa con algún grupo de opciones de hotel sin resolver.

**P5. ¿Qué total se muestra (panel en vivo + PDF) mientras el grupo no
está resuelto?**
**Decidido:** rango "desde $X" usando el precio de lista más barato
entre las opciones del grupo — igual criterio que los 3 documentos
reales (tabla de precios aparte, sin un total único consolidado).

**P6. ¿Cómo se reparte `descuento_global_pct` sobre un grupo de
opciones, y se puede aplicar descuento después según cuál elija el
pasajero?**
**Decidido, con un ajuste sobre el diseño tentativo de §2:** mientras el
grupo esté abierto (nadie eligió), NINGUNA opción del grupo lleva
descuento — se muestran a precio de lista, porque el piso de descuento
(`descuento_maximo_pct`/`margen_minimo_pct`) es por `proveedor_tarifa`,
no hay una sola línea válida sobre la que aplicar % hasta saber cuál es.
Cuando el vendedor **resuelve** el grupo (marca la opción que el
pasajero eligió — paso obligatorio antes de aceptar, por P4), esa línea
pasa a comportarse como cualquier `alternativa_item` normal: entra al
reparto de `descuento_global_pct` y admite descuento propio, respetando
el piso de **esa** tarifa específica — sin importar cuál de las opciones
haya sido. El descuento nunca queda atado a la que parecía más probable
al principio.

**Simplificación de diseño resultante (reemplaza `es_opcion_base` de
§2):** un solo flag `alternativa_items.opcion_elegida` (boolean, default
`false`). Arranca en `false` en todas las filas del grupo. Se marca
`true` en exactamente una al resolver el grupo. No hace falta un
concepto de "opción base" para edición en vivo — mientras el grupo está
abierto, el precio en vivo usa el mínimo de lista (P5) y no hay
descuento que sincronizar (este párrafo, arriba).

**P7. Dentro de un mismo grupo, ¿noches/pax deben ser iguales para
todas las opciones?**
**Decidido:** sí, iguales para todo el grupo — se fija una sola vez al
crear el grupo (mismo servicio, mismas noches, mismos pasajeros; solo
cambia el proveedor/tarifa entre opciones).

### Ronda 3 — CERRADA (29-ago-2026)

**P8. ¿Cómo arma el vendedor el grupo desde el picker?**
**Decidido:** selección múltiple en un solo paso — checkboxes sobre
varias filas de `HabitacionMatrixPicker.vue` + botón "Agregar N opciones
como grupo". Noches/pax se fijan una sola vez para todo el grupo (ya
resuelto en P7), las N filas se insertan juntas con el mismo
`grupo_opcion_id` recién generado.

**P9. ¿Cómo se ve el grupo en el lienzo del cotizador?**
**Decidido:** una sola tarjeta colapsable ("Hospedaje 4 noches — 4
opciones, desde $769"), expandible para ver/editar las filas — no 4
tarjetas sueltas. La acción de "marcar como elegida" (resolver el
grupo, P4/P6) vive dentro de esa misma tarjeta expandida, sobre cada
fila — no hace falta una pantalla aparte.

**P10. ¿Dónde va la tabla matriz en el PDF respecto a la lista de
ítems?**
**Decidido:** aparte, como sección propia después de la lista de ítems
— igual formato que los 3 documentos reales (itinerario + incluye
primero, "Opciones de hoteles" como bloque propio más abajo). No se
mezcla línea por línea con el resto de ítems.

**Notas de diseño resueltas sin necesidad de pregunta (consecuencia
directa de decisiones ya tomadas, no una decisión de negocio nueva):**
- El agrupador aplica a items de origen `proveedor` (hotel local,
  `proveedor_tarifa_id`) **y** de origen `mayorista`
  (`opcion_hotel_tarifa_id`) por igual — `HabitacionMatrixPicker.vue` ya
  es compartido entre ambos flujos (confirmado en el diagnóstico, §1);
  el agrupador es ortogonal a de dónde salió cada fila, solo exige que
  sea un ítem de hotel/alojamiento (consistente con P2, "solo Hotel").
- Un grupo se puede seguir editando (agregar/quitar opción, cambiar
  `opcion_elegida`) mientras la alternativa siga en `borrador`/
  `enviada` — mismas reglas que cualquier `alternativa_item` hoy. Al
  aceptar la alternativa (que ya exige el grupo resuelto, P4), la
  alternativa se congela igual que el resto del sistema — sin
  excepción nueva para este mecanismo.
- Nombre del grupo (ej. "Hospedaje 4 noches en Cusco", usado en la
  tarjeta del lienzo y como título de la sección del PDF): se
  autogenera con un formato simple ("Hospedaje · N noches") y queda
  editable por el vendedor, mismo criterio que el nombre de una
  Alternativa (editable desde Sesión 14-ago, ver historial de
  `plan-modulo-cotizaciones-reservas.md`).

---

### Ronda 4 — CERRADA (29-ago-2026)

**Disparador:** el usuario preguntó qué pasa si un hotel (sobre todo
internacional) no está registrado como Proveedor — ¿hay que salir del
cotizador a darlo de alta antes de poder cotizarlo?

**Hallazgo (asimetría real entre los dos flujos existentes, no una
pregunta de negocio):**
- **Internacional/mayorista:** YA resuelto sin fricción. Dentro del
  mismo drawer, "Agregar hotel a esta opción" abre un formulario inline
  (`cotizador/editar.vue` líneas 681-724) donde el vendedor tipea nombre
  de hotel + arma la matriz de precios por tipo de habitación a mano —
  `<select>` de proveedor con "Hotel manual/referencial (sin
  proveedor)" como opción válida y default (`OpcionMayoristaController
  ::hoteles()`, `proveedor_id` nullable de verdad tanto en schema como
  en el controller). Solo el **mayorista** necesita ser un Proveedor
  registrado, nunca el hotel en sí.
- **Local/Nacional:** sin atajo — la biblioteca solo lee
  `ProveedorTarifa` ya existentes; el único fallback (ítem manual) da
  un solo precio sin estructura de `tipo_habitacion`, no una matriz.

**P11. ¿Debe la pestaña Local/Nacional tener el mismo atajo ad-hoc que
ya existe en Internacional?**
**Decidido:** sí, mismo mecanismo — cierra la asimetría real.

**P12. ¿Un hotel local ad-hoc debe poder promoverse después a Proveedor
real reutilizable?**
**Decidido:** sí, mismo patrón que "Promover a proveedor" del ítem
manual — pero promoviendo **toda la matriz** tipeada (una
`ProveedorTarifa` por cada `tipo_habitacion` cargado), no una sola
línea como hace hoy el promotor del ítem manual genérico.

**Ajuste de diseño resultante (extiende §2, no lo reemplaza):**
- El mecanismo ad-hoc de `opciones_hotel`/`opciones_hotel_tarifas` (hoy
  exclusivo de `opcion_mayorista_id`/`paquete_plantilla_id`) se
  extiende para admitir un tercer caso: una fila de `opciones_hotel`
  **standalone**, sin `opcion_mayorista_id` ni `paquete_plantilla_id`
  (ambos null) — nace suelta en el momento en que el vendedor la tipea
  en la pestaña Local, se consume de inmediato vía
  `alternativa_items.opcion_hotel_tarifa_id` (columna que ya existe
  desde el 04-ago) al agregarla al grupo. No necesita FK propia hacia
  la alternativa — su única referencia hacia adelante es a través de
  los `alternativa_items` que la usan, igual que cualquier
  `ProveedorTarifa` real.
- `HabitacionMatrixPicker.vue` ya normaliza ambos orígenes (`registrada:
  true/false`) a la misma forma de fila — mezclar tarifas reales y
  ad-hoc en el mismo grupo de opciones (P1-P10) no requiere cambios ahí.
- La promoción de matriz completa es una función nueva (no reutiliza
  tal cual `AlternativaItemController::promoverAProveedor()`, que solo
  promueve una línea) — crea un `Proveedor` + `ProveedorServicio` + una
  `ProveedorTarifa` por cada fila de `opciones_hotel_tarifas` bajo el
  mismo `opciones_hotel.id`, en una sola acción.
- Mismo criterio que la promoción de ítem manual ya construida: **sin
  relink retroactivo** — la cotización actual sigue apuntando a las
  filas ad-hoc (`opcion_hotel_tarifa_id`), el Proveedor nuevo queda
  disponible recién para la próxima cotización. No se reabre esto como
  pregunta nueva, es consistencia con un patrón ya decidido.
- `opciones_hotel.categoria_estrellas` (ya existe en esa tabla) queda
  disponible gratis para hoteles ad-hoc locales — el gap menor de §1
  ("categoria_estrellas no existe para proveedor local registrado")
  sigue abierto solo para el camino de `ProveedorTarifa` real, no para
  este ad-hoc.

### Ronda 5 — CERRADA (29-ago-2026)

**Disparador:** el usuario preguntó explícitamente qué pasa en el
reporte operativo, en la pantalla de reserva, y con pagos a proveedor
cuando el hotel elegido fue uno ad-hoc (Ronda 4) sin promover.

**Hallazgo (gap real, confirmado con investigación de código — no
hipotético):** si la Ronda 4 se implementa tal cual quedó, el hotel
ad-hoc se **pierde silenciosamente** al aceptar la alternativa. Causa
raíz única: todo lo que hoy resuelve "qué hotel es este" navega la
cadena `proveedor_tarifa_id → proveedor_tarifas → proveedor_servicio →
proveedor`; un ad-hoc no tiene `proveedor_tarifa_id`, rompe en el
primer eslabón.

- `reserva_items` solo copia `proveedor_tarifa_id` desde
  `alternativa_items` al aceptar
  (`ReservaController::crearReservaItemDesdeAlternativaItem()`, línea
  284) — nunca `opcion_hotel_tarifa_id`. El nombre del hotel ad-hoc se
  pierde para siempre en ese paso.
- `resolverNombreItem()` está **triplicada**: PHP en
  `ReservaController` (reporte operativo, líneas 758-784), TypeScript
  casi idéntico en `reservas/detalle.vue::nombreItem()` (líneas
  1272-1287), y PHP de nuevo en
  `AlternativaController::resolverNombreItemPdf()` (líneas 601-622,
  PDF de cotización). Ninguna de las 3 tiene rama para
  `opcion_hotel_tarifa_id` — degradan en silencio al string genérico
  `'Servicio'`.
- `itemSinAsignacionOperativa()` (`ReservaController.php` línea
  356-362) y su réplica en `reservas/detalle.vue`
  (`tieneAsignacionAplicable()`) cuentan cualquier ítem sin
  `proveedor_tarifa_id` como "sin asignar" — un hotel ad-hoc ya elegido
  aparecería como pendiente, inflando contadores y filtros.
- `filtrosDisponibles()` del reporte operativo arma el catálogo
  "hoteles" solo desde `proveedorTarifa` — un hotel ad-hoc nunca
  aparecería en ese filtro.
- Pagos a proveedor (`cronograma_pago_proveedor`/`pago_proveedor`):
  schema existe, **cero lógica construida** (sin controller, sin ruta,
  sin UI) — confirmado que esto ya era así ANTES de este plan, no algo
  que la matriz de hoteles rompa. Sus únicas 2 FK posibles son
  `proveedor_id`/`opcion_mayorista_id`, ninguna sirve para un ad-hoc
  local sin promover.

**P13. ¿`reserva_items` gana `opcion_hotel_tarifa_id` espejo?**
**Decidido:** sí — columna nueva, nullable, copiada junto con
`proveedor_tarifa_id` en `crearReservaItemDesdeAlternativaItem()`. Sin
esto, la Ronda 4 queda rota en la práctica.

**P14. ¿Se centraliza `resolverNombreItem()` en un solo lugar del
backend en vez de mantener 3 copias?**
**Decidido:** sí — un solo método backend resuelve el nombre y lo manda
ya armado en el JSON de cada endpoint relevante; el frontend
(`reservas/detalle.vue`) deja de duplicar la lógica en TS. Mismo tipo
de riesgo de desalineación que ya quemó una vez al proyecto
(`proveedor_tipos.slug`, ver memoria) — se resuelve de raíz
aprovechando que ya hay que tocar este código para la rama nueva.

**P15. ¿Pagos a proveedor entra en el alcance de este plan?**
**Decidido:** no, fuera de alcance — se documenta como pendiente
conocido (ya lo era antes de este plan). Un hotel ad-hoc sin promover
simplemente no será pagable ahí hasta promoverse a Proveedor real (P12)
— razón de negocio adicional para promover, no un bloqueo de este plan.

**Diseño resultante (extiende §2/Ronda 4):**
- Migración nueva: `reserva_items.opcion_hotel_tarifa_id` (nullable,
  FK a `opciones_hotel_tarifas`, mismo patrón que la de
  `alternativa_items` del 04-ago).
- Servicio/método nuevo centralizado (ej. `ItemNombreResolver` o
  método estático reutilizable) que resuelve nombre + hotel + destino
  de un ítem (`alternativa_item` o `reserva_item`) contemplando 4
  ramas: manual / pasaje_aereo / mayorista / proveedor — y dentro de
  "proveedor", primero intenta `proveedorTarifa`, si es null intenta
  `opcionHotelTarifa` (nombre del hotel ad-hoc + tipo de habitación),
  recién al final cae a `'Servicio'`. Usado por
  `ReservaController` (reporte operativo), `AlternativaController`
  (PDF) y expuesto en el JSON de `ReservaController::show()` para que
  `reservas/detalle.vue` deje de tener su propia copia en TS.
- `itemSinAsignacionOperativa()`/`tieneAsignacionAplicable()`: pasan a
  considerar "asignado" tanto `proveedor_tarifa_id` como
  `opcion_hotel_tarifa_id` presentes — un hotel ad-hoc elegido SÍ es
  una asignación real, solo que no viene del maestro de proveedores.
- `filtrosDisponibles()`: el catálogo de "hoteles" del reporte
  operativo suma también los nombres de `opciones_hotel.nombre_hotel`
  usados en reservas activas, no solo los de `ProveedorTarifa`.

### Ronda 6 — CERRADA (29-ago-2026)

**Disparador:** el usuario preguntó cómo se toman los impuestos para un
hotel internacional cuando la agencia está domiciliada en la Amazonía.

**No es un gap nuevo — conecta con un pendiente ya documentado antes de
este plan** (ver memoria `project_agencia_viajes_impuestos_captura_
propagacion`, sesión 28-ago-2026): `detectarMezclaTributaria()` bloquea
HOY por completo facturar cualquier reserva con algún ítem
`destino_tributario≠'nacional'` (Amazonía o extranjero). El caso
"extranjero"/exportación de servicios (requisitos legales propios:
cliente no domiciliado, consumo fuera del país, medios bancarizados)
quedó explícitamente sin diseñar. Hay 2 preguntas pendientes de
respuesta del contador (Caso Amazonía: ¿la reventa de un servicio
exonerado hereda la exoneración?, Caso mezcla: ¿un comprobante puede
mezclar tratamientos o se mantienen separados?) — **este plan no las
responde**, no es su alcance ni el conocimiento para hacerlo.

**Lo que SÍ toca directamente a este plan:** `opciones_hotel_tarifas`
(la matriz ad-hoc de Ronda 4) no tenía ninguna columna de impuesto —
sin ajuste, un hotel internacional agregado ahí heredaría el default
neutral de la agencia (`'10'`/`'nacional'`), mal clasificado en
silencio. Y `HabitacionMatrixPicker.vue` quedó sin selector de
tratamiento tributario a propósito el 28-ago — con la matriz de
opciones nueva se va a usar mucho más seguido justo para hoteles
internacionales.

**P16. ¿`opciones_hotel_tarifas` gana `tip_afe_igv`/`destino_tributario`,
mismo patrón que `proveedor_tarifas`?**
**Decidido:** sí.

**P17. ¿`HabitacionMatrixPicker.vue` gana el selector de tratamiento
tributario en este plan?**
**Decidido:** sí — coherente con P16, sin selector las columnas nuevas
no tendrían de dónde llenarse en la pantalla real donde se arma la
matriz.

**P18. ¿Facturación bloqueada para 'extranjero'/mezcla se queda como
está (aceptar reserva sí, facturar no) hasta que el contador responda,
o se sube de prioridad ahora?**
**Decidido:** se queda como está — este plan no depende de resolver
eso para funcionar (aceptar/reservar con un hotel internacional elegido
funciona igual; facturar esa reserva específica queda bloqueado por el
guard ya existente, sin cambios, hasta que el contador responda las 2
preguntas ya documentadas).

**Diseño resultante (extiende Ronda 4):**
- Migración: `opciones_hotel_tarifas.tip_afe_igv`/`destino_tributario`
  (nullable, mismo tipo/enum que `proveedor_tarifas`) — aplica a las 3
  formas de uso de esta tabla (paquete_plantilla, opcion_mayorista, y
  el ad-hoc standalone nuevo de Ronda 4).
- `HabitacionMatrixPicker.vue`: selector de tratamiento tributario por
  fila al tipear una tarifa ad-hoc (mismo patrón/opciones que ya existe
  en `ItemManualForm.vue`/`PasajeAereoForm.vue`), prellenado desde el
  default de agencia, editable. Al elegir una tarifa YA registrada
  (`registrada=true`), el tratamiento se toma de la tarifa real, sin
  selector (ya está definido en `proveedor_tarifas`).
- Cuando se materializa el `alternativa_item` desde una fila de
  `opciones_hotel_tarifas` (ad-hoc o de mayorista), su
  `tip_afe_igv`/`destino_tributario` se copian desde ahí — mismo
  patrón "snapshot en creación" que ya usa el resto del vertical
  (`costo_snapshot`, etc.), no cálculo en vivo.
- Sin cambios en `detectarMezclaTributaria()` ni en el guard de
  facturación — se queda exactamente como está hasta que el contador
  responda las 2 preguntas ya documentadas en Pasos 3-4 (fuera de
  alcance de este plan).

## 9. Historial de la sesión de diseño

| Fecha | Cambio |
|---|---|
| 01-sep-2026 | Diseño confirmado como maduro (6 rondas cerradas, sin más casos nuevos del usuario). Se descubre y corrige un conflicto real con `plan-ejecucion-multidestino-mayoristas.md` (Línea 2, sesión hecha en otra herramienta/conversación): su sesión 12a planeaba deprecar `alternativa_items.opcion_hotel_tarifa_id`/`paquete_plantilla_id`, basándose en una auditoría que afirmó (incorrectamente) que este documento y `plan-refactor-mayoristas-tramos.md` no existían en el proyecto. Corregido el mismo día. El usuario además resuelve el conflicto más profundo (`alternativa_destinos` vs. `alternativa_tramos`) a favor de `alternativa_destinos` — `plan-refactor-mayoristas-tramos.md` queda cerrado. Se crea `plan-ejecucion-matriz-hoteles-cotizador.md` (sesiones M1-M5) con el brief completo de M1 listo (`PEGAR-EN-CLAUDE-CODE-matriz-hoteles-m1-nucleo.md`). |
| 29-ago-2026 | Documento abierto. Diagnóstico cerrado (§1) a partir de 3 cotizaciones reales + análisis directo del código (`proveedor_tarifas`, `alternativa_items`, `HabitacionMatrixPicker.vue`, `AlternativaController::pdf()`). Confirmado con el usuario: el workaround de "varias Alternativas" queda descartado por sobrecargar al cliente con información repetida. Dirección tentativa de diseño (§2) propuesta, arranca Ronda 1 de preguntas. |
| 29-ago-2026 | Ronda 6 cerrada (P16-P18), a partir de una pregunta real del usuario sobre impuestos de hoteles internacionales vendidos desde una agencia amazónica. Conecta con un pendiente ya documentado antes de este plan (`detectarMezclaTributaria()` bloquea hoy facturar cualquier destino≠nacional, 2 preguntas sin responder del contador) — no se resuelve acá. Lo que sí se decide: `opciones_hotel_tarifas` gana `tip_afe_igv`/`destino_tributario`, y `HabitacionMatrixPicker.vue` gana el selector correspondiente, para que un hotel internacional ad-hoc no quede mal clasificado por el default neutral de la agencia. Facturación de reservas con destino≠nacional se queda bloqueada como está, sin subir de prioridad. |
| 29-ago-2026 | Ronda 5 cerrada (P13-P15), a partir de una pregunta real del usuario sobre reporte operativo/reserva/pagos a proveedor. Hallazgo serio: sin ajuste, un hotel ad-hoc elegido y aceptado se pierde silenciosamente (degrada a "Servicio" genérico) en reporte operativo, pantalla de reserva y PDF, porque `reserva_items` solo copia `proveedor_tarifa_id` y la resolución de nombre está triplicada sin rama para ad-hoc. Se decide agregar `reserva_items.opcion_hotel_tarifa_id`, centralizar la resolución de nombre en un solo método backend, y dejar pagos a proveedor fuera de alcance (módulo sin lógica propia, preexistente). |
| 29-ago-2026 | Ronda 4 cerrada (P11-P12), a partir de una pregunta real del usuario sobre hoteles internacionales no registrados. Hallazgo: asimetría real entre el flujo Internacional (ya resuelto — hotel ad-hoc tipeado inline, sin proveedor) y Local/Nacional (sin atajo, exige Proveedor+ProveedorTarifa real antes de cotizar). Se decide extender el mismo mecanismo ad-hoc a Local/Nacional + promoción de matriz completa a Proveedor real. |
| 29-ago-2026 | Rondas 1-3 cerradas (P1-P10). Decisiones clave: el vendedor resuelve manualmente qué opción eligió el cliente (sin portal de cliente); agrupador exclusivo de Hotel/alojamiento, sin límite de grupos por alternativa; "Aceptar" bloquea con 422 si queda algún grupo sin resolver; precio en vivo/PDF muestra "desde $X" mientras el grupo esté abierto, sin descuento en ninguna opción hasta resolver; noches/pax fijos por grupo; picker con selección múltiple en un solo paso; grupo = una tarjeta colapsable en el lienzo; tabla matriz como sección propia del PDF, después de la lista de ítems. Diseño de §2 simplificado de `es_opcion_base` a un solo flag `opcion_elegida` (más limpio, sin necesidad de un concepto de "base" para edición en vivo). Pendiente: confirmar con el usuario si quedan más casos por resolver antes de escribir el prompt de implementación. |
