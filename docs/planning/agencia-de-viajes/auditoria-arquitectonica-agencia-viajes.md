# Auditoría Arquitectónica Profunda — Vertical Agencia de Viajes
### Sistema existente vs. modelo de dominio objetivo

> Ejecutado a partir de: (1) el brief de auditoría elaborado en ChatGPT (`AUDITORÍA ARQUITECTÓNICA PROFUNDAchatgpt.md` — en realidad un *pliego de preguntas*, no un análisis ya hecho), (2) el relevamiento factual de controllers/services de Claude Code (`auditoria-controllers-services-flujo-cotizacion-reserva.md`), y (3) el relevamiento factual de schema/modelos de Claude Code (`auditoria-schema-modelos-agencia-viajes.md`). Cruzado además contra los docs de decisiones ya guardados en el proyecto (`plan-modulo-cotizaciones-reservas.md`, `plan-modulo-proveedores.md`, `plan-hoja-de-ruta-ejecucion.md`, `plan-general-vertical-agencia-viajes_1.md`).
>
> **Nota metodológica importante:** el brief de ChatGPT pide coordinar con `plan-refactor-mayoristas-tramos.md`, `plan-matriz-hoteles-cotizador.md` y `plan-fix-moneda-cotizador.md` como contexto de negocio. Ninguno de los tres existe en la lista de documentos del proyecto — probablemente vivieron solo en una conversación de sesión (no se guardaron como doc). Esto es en sí mismo un hallazgo (ver §17 Decisiones abiertas): antes de ejecutar el plan de refactor de moneda/mayoristas/tramos, hay que reconstruir o recuperar esas conversaciones, porque el brief asume que existen y esta auditoría no pudo contrastarlas directamente. Lo que sigue se basa en lo que el código real (vía las dos auditorías factuales) y los planes sí documentados permiten confirmar.
>
> **Revisión 2 (post benchmarking de industria):** la primera versión de este documento proponía una entidad `Tramo` completa (con moneda y tipo de cambio propios) entre `Alternativa` y `AlternativaItem`. Tras contrastar con cómo resuelven multi-destino plataformas reales del rubro (Ezus, Tourwriter, Axus, Tourplan, Lemax, DMC Quote — ver evidencia citada en §7 y §13), se corrigió el diseño en dos puntos: (1) ninguna de esas plataformas expone una entidad de "tramo" con moneda propia — cotizan multi-destino en **una sola moneda de presentación al cliente por cotización completa**, convirtiendo el costo de cada proveedor en su moneda nativa; (2) sí necesitan, aunque no lo documenten como tal, un registro liviano por destino para poder ordenar destinos, fechas y comparar mayoristas *antes* de que exista ningún ítem — eso es lo que este documento llama `alternativa_destinos`, deliberadamente más chico que el `Tramo` original (sin moneda ni tipo de cambio). Todas las secciones de abajo ya reflejan esta corrección.

---

## 1. Resumen ejecutivo

El vertical Agencia de Viajes tiene un modelo de datos **maduro, funcionando de punta a punta en producción** (cotizador → alternativas → reserva → operación → facturación → anticipos), construido con una disciplina inusualmente alta para un sistema en evolución rápida: separación consistente entre snapshot congelado y referencia viva, decisiones de negocio documentadas con fecha y cita textual en el propio código, y varios bugs reales ya corregidos con su causa raíz explicada (orden de filas en Postgres, slug divergente, vuelo de pasajero compartiendo fila con checkbox de asignación).

El diagnóstico central es que **no hace falta un refactor estructural mayor**. El problema real no es "el modelo está mal diseñado", es "el modelo fue diseñado para una cotización de un solo destino y el negocio ahora necesita representar viajes de varios destinos, cada uno con su propio proveedor/mayorista de origen" — eso es una **extensión concreta y acotada** (una tabla liviana nueva, `alternativa_destinos`, sin tocar moneda ni el motor de precios), no una reconstrucción de `Cotizacion`/`Alternativa`/`AlternativaItem`/`Reserva`.

Los tres problemas reales que sí requieren decisión y trabajo, en orden de impacto:

1. **Multi-destino** no existe en el schema. `Cotizacion.destino` es texto libre singular y `fecha_viaje_desde/hasta` es un solo rango — no hay forma de representar "Tarapoto 2 días + México 10 días" como dos bloques ordenados, cada uno con sus fechas y su propio comparador de mayoristas. Esto bloquea el caso de negocio que motivó esta auditoría. (La moneda, en cambio, **no** necesita moverse de nivel — ver §13: la práctica de la industria confirma que una sola moneda de presentación por cotización, con conversión a nivel de ítem, es lo correcto y ya es como funciona hoy.)
2. **Pagos a proveedores es schema sembrado sin ningún camino de escritura** (`cronograma_pago_proveedor`/`pago_proveedor`: cero controllers, cero rutas, confirmado dos veces de forma independiente). No sirve el caso real de pago consolidado a un mismo proveedor por varias reservas porque ni siquiera está conectado a `reserva`/`reserva_item`.
3. **Un guard de congelamiento con un hueco real conocido**: `AlternativaController::update()` con `descuento_global_pct` no verifica si la alternativa ya está `aceptada`, mientras que casi todos los demás caminos de mutación sí lo bloquean — inconsistencia puntual, no un problema de diseño.

Todo lo demás — el motor de ítems polimórfico (`AlternativaItem`), la separación comercial/operativo (`AlternativaItem` vs `ReservaItem`), Proveedor-con-tipo-mayorista en vez de una entidad Mayorista aparte, el puente con facturación (`ReservaVenta`/`ReservaAnticipo`) — está **bien resuelto** y debe conservarse. La recomendación explícita es: **no reabrir esas decisiones**, extenderlas para soportar destinos múltiples.

---

## 2. Arquitectura actual

```
Cliente
  │
  ▼
Cotizacion (header: cliente, destino [texto libre], fecha_viaje_desde/hasta, pasajeros por edad)
  │
  ├── CotizacionPasajero (edad = fuente de verdad, tipo_pax derivado)
  │
  └── Alternativa (hasta 5, moneda_cotizacion + tipo_cambio_aplicado a nivel de TODA la alternativa)
         │
         └── [PROPUESTO] alternativa_destinos (destino_atractivo_id, orden, fecha_inicio/fin — ver §7)
                │
                ├── AlternativaItem (origen_tipo: proveedor | mayorista | pasaje_aereo | manual | guia)
                │      ├── proveedor_tarifa_id ─┐
                │      ├── opcion_mayorista_id  ├─ nullable, una llena según origen_tipo
                │      ├── guia_tarifa_id      ─┘
                │      ├── CotizacionPasajeAereo (1-a-1, solo si origen_tipo=pasaje_aereo)
                │      ├── costo_snapshot / precio_venta_snapshot / precio_convertido (congelados)
                │      └── pax_incluidos (subset de CotizacionPasajero, null=todos)
                │
                └── OpcionMayorista (candidata|elegida|descartada)
                       ├── OpcionMayoristaOpcional (nunca se suma automático)
                       └── OpcionHotel → OpcionHotelTarifa (matriz hotel × tipo_habitacion)
                              └── precio "en vivo" si hay proveedor_tarifa_id vinculada

  (al aceptar una Alternativa)
         ▼
Reserva (fecha_viaje_desde/hasta CONGELADA acá, nunca leída de cotizacion)
  │
  ├── ReservaPasajero (shell vacío al crear, se completa después; pasajero_catalogo_id reutilizable)
  ├── ReservaItem (1-a-1 con AlternativaItem; proveedor_tarifa_id/guia_id VIVOS y reasignables)
  │      ├── ReservaItemPasajero (pivote, checkin)
  │      └── ReservaItemVueloPasajero (vuelo propio del pasajero, tabla separada a propósito)
  ├── SalidaOperativa (agrupa ReservaItem de VARIAS reservas por tour_origen_id+fecha)
  ├── ReservaAnticipo → Advance (core)
  └── ReservaVenta → Sale (core) — N Sales por reserva, por grupo de pasajeros/pagador
```

**Congelamiento por etapa (confirmado en código, no inferido):**

| Etapa | Se congela | Sigue vivo |
|---|---|---|
| Cotización | nada — cliente/destino/fechas editables sin guard | todo |
| Alternativa | `tipo_cambio_aplicado` al crearla | `estado`, descuentos, total (recalculado) |
| AlternativaItem | costo/precio/moneda/tip_afe_igv/destino_tributario al crear el ítem | FKs de referencia (proveedor_tarifa_id, etc.) — desactivar la tarifa no reescribe ítems ya creados |
| Reserva | `fecha_viaje_desde/hasta` al aceptar (una sola vez, nunca depende de la cotización después) | — |
| ReservaItem | `fecha` (con `fecha_origen` auto/manual para no pisar una corrección manual) | `proveedor_tarifa_id`/`guia_id` — asignación operativa reasignable |
| Sale (facturación) | snapshot financiero completo e inmutable | — |

---

## 3. Problemas arquitectónicos encontrados

1. **No hay unidad de agrupación entre `Alternativa` y `AlternativaItem` para destino/fechas.** Todo un viaje de varios destinos cae en una sola `Alternativa`, sin ningún registro que diga "esto es Tarapoto, esto es México, en este orden, con estas fechas". Nota transversal del propio documento de schema: *"un viaje con 2 tramos... no tiene hoy ningún mecanismo para coexistir dentro de la misma Alternativa"*. (La moneda no es parte de este problema — ver §13: la práctica de la industria y el propio dato de que "todos los proveedores de un mismo destino cotizan en la moneda de mercado de ese destino" confirman que la moneda puede seguir a nivel de `Alternativa`/ítem como ya está, sin necesidad de moverla.)
2. **`AlternativaController::update()` no bloquea `descuento_global_pct`/`descuento_global_monto` si la alternativa ya está `aceptada`** — inconsistente con el resto de guards de la familia ("ya generó reserva, no tocar"). Es el único camino documentado donde el total de una reserva ya aceptada podría cambiar en vivo sin que nadie lo note (nota transversal #2 del audit de controllers).
3. **Pagos a proveedores es schema sin flujo funcional real** — `CronogramaPagoProveedor`/`PagoProveedor` no tienen controller ni ruta (confirmado por grep, cero resultados), y aunque los tuvieran, el modelo actual (`proveedor_id` XOR `opcion_mayorista_id`) no tiene ninguna columna que ate el pago a una `reserva`/`reserva_item` puntual — no se puede saber "esto se pagó por la Reserva A y esto por la Reserva B" dentro de un pago consolidado.
4. **Reglas "uno de N campos" nunca son CHECK constraint**, y la garantía de "solo una `OpcionMayorista` elegida por alternativa" depende enteramente de que `OpcionMayoristaController::elegir()` desmarque la anterior — sin índice único parcial. Mismo patrón de riesgo que ya causó el bug real de orden de filas (`Alternativa::items()` sin `orderBy`) antes de corregirse.
5. **Columnas muertas confirmadas y no limpiadas**: `alternativa_items.opcion_hotel_tarifa_id` y `alternativa_items.paquete_plantilla_id` — el propio código dice *"quedaron muertas — ningún código las escribe ya"*.
6. **Datos capturados pero sin uso real**: `Proveedor.margen_default_tipo/margen_default_valor` (pensado para autocalcular precio de venta de hoteles de mayorista) se guarda pero *no se lee* en el flujo real de carga de tarifa — el margen automático "por mayorista" documentado en `plan-modulo-cotizaciones-reservas.md` §2.4 no está conectado al dato que se supone lo alimenta.
7. **Orden de ítems dentro de un día del cotizador**: `AlternativaItem` no tiene columna `orden` propia (a diferencia de `PaquetePlantillaItem`/`TourItinerarioItem`, que sí la tienen), pero la hoja de ruta (sesión 11l) dice que el cotizador tiene "itinerario editable con drag&drop". No hay evidencia en las auditorías factuales de qué columna persiste ese reordenamiento dentro del mismo día — **queda como pregunta abierta para el equipo**, no como hallazgo cerrado (ver §17).
8. **`salidas_mayorista` es un catálogo diseñado sin ningún camino de escritura** — confirmado explícitamente: *"no existe ningún controller/ruta que escriba en salidas_mayorista"* — por lo que `salida_mayorista_id` en `opcion_mayorista` queda siempre null en la práctica y el control de cupo de mayorista (`cupo_ocupado`/`cupo_total`) nunca se activa para el caso internacional, que es justamente donde más se necesitaría.

---

## 4. Entidades correctamente diseñadas (conservar tal cual)

- **`Cotizacion` / `CotizacionPasajero`** — separación limpia: edad es la fuente de verdad, `tipo_pax` es solo sugerencia editable. Código autogenerado correctamente desacoplado de `Cotizacion::create()` (vía `CodigoGeneradorService`, decisión explícita post-refactor).
- **Separación snapshot vs. referencia viva en `AlternativaItem`** — patrón consistente y bien defendido: nunca hay recálculo silencioso, todo lo que no se recalcula automáticamente se reporta explícitamente (`items_para_revisar`, `lineas_fuera_de_piso`).
- **Comercial vs. operativo (`AlternativaItem` vs `ReservaItem`)** — exactamente lo que el brief de ChatGPT pedía verificar en la sección 14, y ya está resuelto: `AlternativaItem` es "qué se ofreció" (congelado), `ReservaItem` es "qué se debe ejecutar" (`proveedor_tarifa_id`/`guia_id` vivos y reasignables, con `fecha_origen` auto/manual para no pisar correcciones).
- **`Proveedor` con `tipo_id` en vez de una entidad `Mayorista` separada** — decisión ya cerrada (24-jul-2026) y correcta: un mayorista puede vender nacional e internacional a la vez, y `moneda` por tarifa ya resuelve eso sin clasificar al proveedor completo. No reabrir.
- **Puente con facturación (`ReservaVenta`, `ReservaAnticipo`, `sale_detail_items`)** — no duplica el sistema financiero del core, solo lo asocia. La regla explícita *"la factura es solo una representación distinta de los mismos datos para SUNAT, no la fuente de verdad operativa"* es exactamente el límite correcto entre comercial/operativo y financiero.
- **`PaquetePlantilla` consolidando `tour_simple`/`paquete_combo`** — decisión cerrada con evidencia real (documentos de la agencia). No reabrir salvo que el modelo multi-destino (§7, `alternativa_destinos`) revele una contradicción — evaluado abajo y no la hay.
- **`ReservaItemPasajero` vs `ReservaItemVueloPasajero` separados** — el bug real que motivó la separación (desmarcar un checkbox borraba el vuelo cargado) es la prueba de que la separación es la decisión correcta, no una sobre-normalización.

---

## 5. Entidades con responsabilidades mezcladas

- **`AlternativaItem`** — es el caso central que pide el brief (sección 6-7). Es una unión discriminada (`origen_tipo` + 5 orígenes posibles) que sostiene 5 conceptos de negocio distintos (proveedor de catálogo, oferta de mayorista, tarifa de guía, pasaje aéreo con submodelo propio, ítem manual sin proveedor). Ver veredicto detallado en §6.
- **`OpcionMayorista`** cuelga directo de `Alternativa`, pero conceptualmente pertenece a un destino específico del viaje, no a la alternativa completa — hoy es indiferenciable si el viaje tiene un solo destino, pero se rompe en cuanto hay dos (no se puede comparar mayoristas de México sin mezclarlos con los de Tarapoto en la misma alternativa).
- **`Proveedor.margen_default_tipo/valor`** — campo que representa una intención de negocio (margen automático) que nunca se conectó al flujo real. No está "mezclado", está huérfano — mover a Decisiones abiertas o completar la conexión.
- **`ConfiguracionAgencia`** es un singleton correcto en concepto, pero acumula 24+ columnas de dominios no relacionados entre sí (descuentos, cotización, hotelería, pasajeros, tributación, numeración de pagos a proveedor) — no es un defecto grave hoy, pero cada sesión nueva le agrega una columna más; vale la pena vigilar que no se convierta en un cajón de sastre.

---

## 6. Modelo de dominio recomendado

Respuesta directa a la pregunta central del brief (sección 7): **conservar `AlternativaItem` como punto común, no dividir en `ItemCatalogo`/`ItemCotizado`/`ItemManual`/`ItemMayorista` (Alternativa C) ni introducir una capa `FuenteCotizacion` genérica (Alternativa B).**

Justificación, evaluando las 3 alternativas del brief contra el uso real documentado:

- **Alternativa C (tablas separadas por origen)** obligaría a que *todo* el código downstream que hoy trata a `AlternativaItem` de forma uniforme — `recalcularTotalAlternativa()`, `crearReservaItemDesdeAlternativaItem()` (único punto de creación de `ReservaItem`, reusado por aceptar y sincronizar), `ReservaFacturacionController::resolverSeleccion()` (selección de ítems a facturar), `PriceEngineService::evaluarPiso()` — pase a hacer *joins polimórficos* contra 4-5 tablas en vez de una. Es exactamente el tipo de complejidad que el brief pide evitar ("✗ duplicación de datos", "✗ polimorfismo solamente por flexibilidad"). El costo de migración (reserva histórica, `sale_detail_items.reserva_item_id`, PDFs ya generados) es alto y el beneficio es principalmente estético.
- **Alternativa B (`FuenteCotizacion` genérica)** no resuelve nada que `origen_tipo` explícito no resuelva ya — el propio comentario del código confirma que el diseño *ya pasó* por la fase ingenua ("antes había que inferir el origen según qué FK estaba llena... frágil apenas se agregó un tercer origen") y decidió deliberadamente un discriminador explícito. Volver a una abstracción genérica sería retroceder a ese problema con otro nombre.
- **Alternativa A (la actual) es válida** — la señal "B" que el brief pide no descartar sin evidencia (§6) *sí existe* pero acotada a 2 columnas muertas confirmadas (`opcion_hotel_tarifa_id`, `paquete_plantilla_id`), no a las 5 activas. Eso es limpieza, no rediseño.

**Veredicto: CONSERVAR `AlternativaItem` con `origen_tipo`, con dos ajustes concretos (REFACTORIZAR, no REEMPLAZAR):**
1. Eliminar (tras período de gracia de migración) las 2 FKs muertas.
2. Formalizar cada `origen_tipo` como una "spec" de validación en Services (no en el modelo Eloquent) — un objeto que declare qué campos aplican, qué congela, qué guard de edición corresponde — para que agregar un sexto origen (ej. "seguro de viaje", ya listado como servicio vendible en el brief §3) no vuelva a requerir tocar 5 métodos `crearItemX()` a mano sin un contrato común. Esto es una refactorización interna de organización de código, no de schema.

---

## 7. Modelo multi-destino recomendado

Esta es la brecha real (brief §8-10, §16, §19). Respuesta a "¿Viaje o no?": **no**, por la razón que el propio brief anticipa — no hace falta una entidad `Viaje` separada de `Cotizacion` si lo que falta es un nivel intermedio entre `Alternativa` y `AlternativaItem`.

**Corrección respecto a la primera versión de este documento:** la propuesta original era una entidad `Tramo` completa, con moneda y tipo de cambio propios. Un benchmarking contra plataformas reales del rubro (Ezus, Tourwriter, Axus, Tourplan, Lemax, DMC Quote) corrigió eso en un punto importante: **ninguna de ellas modela un objeto "segmento/tramo" con moneda propia**. Ezus, por ejemplo, documenta explícitamente que convierte "supplier rates from local currency to your client billing currency in real time, with editable exchange rates per project" — es decir, el costo de cada proveedor se captura en su moneda nativa y se convierte a **una sola moneda de presentación por cotización completa**, nunca se le muestra al cliente una propuesta con dos monedas mezcladas. Eso es exactamente lo que tu `AlternativaItem` ya hace hoy (`moneda_costo` + `precio_convertido`) — no hay que tocarlo ni moverlo de nivel (ver §13).

Lo que sí necesitan estas plataformas — aunque no lo llamen "tramo" en su documentación pública, lo resuelven con "días" agrupados en "bloques de contenido" reutilizables (Tourwriter) o con vínculos entre reservas relacionadas (Axus) — es **algún registro que exista antes que cualquier ítem**, para poder declarar el destino, su orden y sus fechas, y para poder comparar mayoristas de ESE destino en particular. Sin eso no hay dónde colgar el comparador de mayoristas de México mientras todavía no se cargó ningún ítem.

**Veredicto: sí hace falta una tabla nueva, pero mucho más liviana que el `Tramo` original — sin moneda ni tipo de cambio:**

```
alternativa_destinos              (NUEVO — liviano, reemplaza al "Tramo" completo de la v1)
  - alternativa_id
  - destino_atractivo_id           (el destino de ESTE bloque, no de toda la cotización)
  - orden                          (1, 2, 3... para Tarapoto→México→Cancún...)
  - fecha_inicio / fecha_fin       (se autocalcula: fin del anterior + 1, editable)

Alternativa (moneda_cotizacion + tipo_cambio_aplicado SE QUEDAN acá, sin cambios — ver §13)
  └── alternativa_destinos
         ├── AlternativaItem (gana alternativa_destino_id; dia_referencial pasa a ser
         │                     relativo al inicio de ESE destino, no de toda la alternativa —
         │                     mismo criterio que ya usa tour_itinerario_items por tour)
         └── OpcionMayorista (gana alternativa_destino_id en vez de alternativa_id — cada
                               destino internacional tiene su propio comparador de mayoristas)
```

Con esto, la garantía "solo una `OpcionMayorista` elegida" se vuelve **más simple** que en la v1, no más compleja: un índice único parcial `(alternativa_destino_id) WHERE estado='elegida'` alcanza, porque el destino ya viene implícito en la fila (no hace falta combinarlo con nada más).

**Por qué NO "local/nacional/internacional" determina el origen del precio (brief §10, hipótesis a validar):** la hipótesis es correcta y ya se confirma en el código actual — `origen_tipo` en `AlternativaItem` es completamente independiente del destino; un ítem `origen_tipo=mayorista` con destino nacional ya es válido hoy (no hay ningún `if` que lo impida). El único acoplamiento real detectado es de UX, no de datos: el cotizador usa un *toggle* Local/Nacional vs. Internacional para decidir qué columna de biblioteca mostrar (biblioteca de tarifas vs. comparador de mayoristas) — eso es una decisión de interfaz razonable, no una regla de dominio grabada en el schema. Con `alternativa_destinos.destino_atractivo_id` explícito, ese toggle simplemente se resuelve solo según el destino activo (si su categoría es internacional, se sugiere el comparador de mayoristas por defecto, sin obligar).

**Pasajeros por destino (brief §19):** no crear una tabla `destino_pasajero` nueva. `CotizacionPasajero` permanece como roster único de la cotización (evita duplicar identidad de pasajero), y `pax_incluidos` — que ya existe en `AlternativaItem` y ya significa "subconjunto de pasajeros que aplica a este ítem, null=todos" — sigue cumpliendo la misma función a nivel de destino sin cambios de schema: si un ítem pertenece al bloque México y su `pax_incluidos` son 2 de los 3 pasajeros, ya queda expresado que esos 2 son quienes van a México. Esto es **EXTENDER**, no **NUEVO**.

**Compatibilidad con `Cotizacion.destino`/`fecha_viaje_desde/hasta` actuales:** no se eliminan. Quedan como el resumen informativo de cabecera (igual que hoy son "solo informativos" en `ReservaController::respuestaDetalle()`), calculable en migración como `MIN(alternativa_destinos.fecha_inicio)`/`MAX(alternativa_destinos.fecha_fin)` y concatenación de destinos, sin romper PDFs/reportes existentes que ya leen esos dos campos.

### 7.1 Cambios en la pantalla de cotizaciones (cotizador)

Sobre el layout actual de 3 columnas (biblioteca / lienzo día-por-día / precio en vivo, `plan-modulo-cotizaciones-reservas.md` §7.1):

1. **Un nivel nuevo por encima de las pestañas de día.** Hoy el lienzo tiene tabs "Día 1 / Día 2 / ...". Se agrega un nivel de chips de destino arriba de eso — `Tarapoto | México | + Agregar destino` — y los tabs de día quedan anidados dentro del destino activo. Clic en "+ Agregar destino" crea la fila en `alternativa_destinos` y pide destino + rango de fechas (o cantidad de días, si aún no hay fecha exacta).
2. **El toggle Local/Nacional vs. Internacional deja de ser una decisión manual de toda la alternativa** — se resuelve por defecto según la categoría del destino activo, editable igual si el vendedor necesita forzarlo (ej. un traslado local suelto dentro del bloque México).
3. **El comparador de mayoristas y el botón "Elegir"** actúan sobre el destino activo, no sobre toda la alternativa — ahora puede haber una comparación de mayoristas por cada destino internacional del viaje, no solo una por viaje completo.
4. **Panel de precio**: se agrega un subtotal colapsable por destino, además del total general de la alternativa — en una sola moneda de presentación (§13), sin split de moneda por destino.
5. **El modal "Paso 0"** (cliente + pasajeros + destino tentativo) puede seguir simple: se crea con un destino inicial, y los destinos adicionales se agregan después desde el lienzo con el botón del punto 1 — no hace falta complicar el alta inicial de la cotización.
6. **PDF comercial**: `itinerarioAlternativa()` ya concatena tours; con destino explícito se agrupa con encabezado de sección por destino (nombre + fechas) antes de listar los días — es prácticamente el mismo código, con un dato real de dónde agrupar en vez de inferirlo del `tour_origen_id`.

Frontend afectado por esta sección: `cotizaciones/editar.vue` (o el componente equivalente del lienzo), el store de cotizador, y el componente de comparador de mayoristas — no afecta las pantallas de Reserva/Operación/Facturación, que siguen leyendo de `AlternativaItem`/`ReservaItem` exactamente igual.

---

## 8. Modelo de cotización recomendado

Sin cambios estructurales en `Cotizacion`/`CotizacionPasajero`/`Alternativa` más allá de lo ya descrito en §7 (nueva tabla `alternativa_destinos`, sin tocar moneda/tipo de cambio — ver §13). El ciclo `Cotizacion → Alternativa → aceptación → Reserva` ya está correctamente separado y cada congelamiento está documentado (ver tabla §2). La única corrección de comportamiento necesaria: cerrar el hueco de `AlternativaController::update()` con `descuento_global_pct` sobre una alternativa ya `aceptada` (§3.2).

---

## 9. Modelo de mayoristas recomendado

Respuesta a la pregunta central del brief §11 (¿qué es realmente `OpcionMayorista`?): es una **oferta/cotización recibida de un proveedor-mayorista para un destino del viaje**, con estado propio de selección (`candidata|elegida|descartada`) — no es una reserva, no es un proveedor, no es una salida. Esto coincide con el patrón estándar de la industria (net rate vs. sell rate — ver DMC Quote y Ezus, §15): el vendedor ve el costo real del mayorista, decide su margen, y el cliente solo ve el resultado. El nombre y la forma actual son correctos; el único cambio es de nivel: cuelga hoy de `Alternativa`, debería colgar de `alternativa_destinos` (§7) para soportar el caso real de la sección 12 del brief (Mayorista A/B/C × Hotel 1/2/3) por cada destino internacional del viaje, no solo uno por viaje completo.

**Matriz mayorista × hotel (brief §12):** ya implementada con el diseño correcto — `OpcionHotel`/`OpcionHotelTarifa` como "un solo motor" compartido. **CONSERVAR el resto de esta pieza tal cual.**

**[CORRECCIÓN 01-sep-2026 — hallazgo C1, contradice la afirmación anterior de esta sección]** La afirmación "el mayorista nunca se imprime en el documento que ve el cliente" **era incorrecta** — no se sostuvo en la implementación real, encontrado por una sesión de ejecución de Claude Code al trabajar sobre el código real (no en las auditorías factuales originales, que no llegaron a este nivel de detalle de resolver). El leak es directo y puntual: `AlternativaController::resolverNombreItemPdf()` (líneas 601-622, invocado desde `pdf()` línea 496) resuelve así para ítems `origen_tipo=mayorista`:

```php
return $item->opcionMayorista?->proveedor?->razon_social ?? 'Paquete mayorista';
```

Es decir: cuando no hay un nombre mejor, cae directo a `Proveedor.razon_social` — la razón social legal registrada en SUNAT del mayorista — e imprime eso tal cual en el PDF comercial de la cotización (`resources/views/pdf/agencia-viajes/alternativa.blade.php`), el documento que efectivamente recibe el cliente para decidir/aceptar. Confirmado que **no** hay fuga equivalente en facturación (`Sale`/`SaleDetail` usan productos placeholder genéricos) ni en vouchers (no existe ese módulo construido todavía — lo que el brief de la sesión 12h describe como "vouchers" es una funcionalidad prevista en el diseño original que nunca se llegó a construir, no algo que exista hoy y haya que corregir).

Existe un segundo resolver hermano, `ReservaController::resolverNombreItem()` (líneas 769/778), usado únicamente por el reporte operativo interno (pantalla de staff) — ahí el fallback a `nombre_comercial`/`razon_social` **no** es un leak, es correcto: el equipo interno necesita saber con qué mayorista está operando. Los dos resolvers están duplicados con comportamiento ligeramente distinto. Diseño de la corrección en §9.3.

**Cupo de mayorista (`SalidaMayorista`)** — schema correcto pero sin ningún punto de escritura real (§3.8). Antes de construir más sobre esto, decidir: ¿se construye el flujo de alta de `SalidaMayorista` (probablemente lo más simple, dado que el resto del modelo ya lo espera) o se descarta el control de cupo para mayoristas y se documenta como decisión consciente? Ver §17.

### 9.1 `contenido_tour` — contenido reutilizable desacoplado del precio del mayorista

**El problema que resuelve.** El brief §4 plantea cuatro casos de "¿de dónde sale el precio de un ítem?": producto de catálogo (con proveedor_tarifa propio), servicio conocido sin proveedor operativo aún, servicio cotizado bajo demanda, servicio personalizado. Ninguno de los cuatro cubre el caso real que aparece al armar itinerarios internacionales día a día: **contenido que se repite cotización tras cotización (la descripción de "Zona Libre de Colón", las fotos de "Isla Taboga Full-Day"), pero cuyo precio nunca es propio — siempre lo fija el mayorista, cotización por cotización, y puede cambiar de una oferta a otra sin que cambie una coma de la descripción.**

Forzar ese contenido dentro de `PaquetePlantilla` no funciona: una plantilla exige `PaquetePlantillaItem` reales, con `proveedor_tarifa_id`/`guia_tarifa_id` propios de la agencia — exactamente lo que no existe cuando el tour lo arma y lo tarifa un mayorista externo (DKM, y equivalentes). Volver a escribir la descripción del tour a mano cada vez que aparece en una cotización nueva tampoco es aceptable operativamente.

**Propuesta:** una tabla nueva, puramente descriptiva, sin ningún campo de precio:

```
contenido_tour
  id
  destino_atractivo_id   -- a qué destino pertenece (Panamá, México, ...)
  categoria              -- 'incluido' | 'opcional' | 'excursion'
  nombre                 -- "Excursión San Blas", "City Tour Panamá"
  descripcion            -- texto largo para el PDF
  incluye / no_incluye   -- texto libre
  fotos                  -- json / relación a medios
  created_at, updated_at
  -- SIN precio, SIN moneda, SIN vigencia: eso vive siempre en la oferta del mayorista
```

`OpcionMayorista` y `OpcionMayoristaOpcional` reciben un `contenido_tour_id` **opcional** (nullable): si el vendedor ya cargó ese tour antes, lo enlaza y el PDF hereda descripción/fotos automáticamente; si es la primera vez, el campo queda vacío y el vendedor escribe el texto suelto como hoy — sin que eso bloquee nada. Es decir, `contenido_tour` no es una tabla obligatoria en el flujo: es una biblioteca que se va llenando sola a medida que se repiten destinos y excursiones, y que nunca toca el precio.

**Ejemplo real (documento DKM Xplore, PANAMÁ 6D/5N, Oct 2026, 15 pax, DAKAMU SAC):**

| Elemento del documento real | Dónde vive en el modelo |
|---|---|
| Paquete base "PDKM-INT – Panamá 6D/5N" (vuelo Copa + traslados + city tour + Isla Grande incluidos) | Un solo `AlternativaItem` con `modo_precio='tarifa_fija'`, uno por tipo de habitación — no se descompone por día |
| Matriz de hoteles (Novo Hotel USD 880 / Riande Urban USD 850 / Decapolis USD 890, por persona en base doble) | `OpcionHotel` + `OpcionHotelTarifa`, ya existente — sin cambios |
| Vuelo Copa Airlines con horarios fijos | Texto libre en `OpcionMayorista.vuelo_aerolinea` / `vuelo_detalle` — no requiere modelar itinerario de vuelo aparte |
| Tours opcionales (Zona Libre de Colón USD 85, Isla Taboga Full-Day USD 96, Excursión San Blas USD 170) | `OpcionMayoristaOpcional`, uno por tour — cada uno con su `contenido_tour_id` si la descripción ya existe en la biblioteca, o texto libre si es la primera vez que se cotiza ese tour |

Ningún dato del documento obliga a tocar `Tramo`/`alternativa_destinos` más allá de lo ya definido en §7: el paquete completo cuelga de un único `alternativa_destino_id` (Panamá), y `contenido_tour` solo resuelve la reutilización del texto/fotos dentro de ese mismo destino.

**Los cuatro casos del brief §4, actualizados con esta pieza:**

| Caso del brief §4 | Dónde vive hoy | Precio |
|---|---|---|
| Producto de catálogo | `PaquetePlantilla` | Real, de tu `proveedor_tarifa` |
| Servicio conocido (sin proveedor operativo aún) | proveedor referencial (`es_referencial`) | Real, referencial |
| Contenido conocido, precio siempre externo | `contenido_tour` **(NUEVO)** | Nunca — lo pone `OpcionMayorista`/`OpcionMayoristaOpcional` |
| Servicio personalizado | ítem manual | Ad-hoc |

**CONSERVAR el resto del modelo de mayoristas tal cual; AGREGAR `contenido_tour` como tabla opcional, de adopción gradual, sin migración de datos obligatoria ni cambio de comportamiento si no se usa.**

### 9.2 Reasignación de mayorista en vivo (post-aceptación) — hallazgo §23.1.4, diseño confirmado 01-sep-2026

**El problema.** Hoy `ReservaItem` referencia su `opcion_mayorista_id` de forma indirecta y congelada desde el momento en que la `Alternativa` se acepta: no existe un mecanismo para cambiar de mayorista una vez que la reserva ya está activa. En la práctica esto sí ocurre — un mayorista pierde cupo para las fechas confirmadas, o el precio cotizado ya no está vigente al momento de operar — y cuando pasa, a veces cambia también el costo real (vuelos, hoteles), sin que eso deba afectar lo que el cliente ya pagó (`precio_venta_snapshot` es y sigue siendo intocable). El usuario confirmó que es un caso real de operación, no hipotético.

**Diseño (mismo patrón que `reprogramar()` ya usa para fechas — nada nuevo que aprender ni en el modelo ni en la UI):**

```
ReservaItem
  opcion_mayorista_id              -- pasa a ser editable en vivo (hoy es de solo lectura tras aceptar)
  opcion_mayorista_original_id     -- se guarda la primera vez que se reasigna; nunca se sobreescribe
  motivo_reasignacion_mayorista    -- texto obligatorio, mismo campo que motivo_reprogramacion
  fecha_reasignacion_mayorista     -- timestamp de la última reasignación
  veces_reasignado_mayorista       -- contador simple, para el badge "reasignado N veces" en el resumen
```

Reglas de negocio (idénticas en espíritu a `reprogramar()`):

1. La reasignación se hace a nivel de `ReservaItem`, no de `Reserva` completa — permite mover solo el paquete y sus opcionales vinculados al mismo mayorista, dejando intactos los ítems de otros destinos u origen_tipo distintos.
2. `precio_venta_snapshot` del cliente **nunca se toca automáticamente**. Si el nuevo costo es mayor o menor, la diferencia se muestra explícitamente en la UI (ver mockup `ReasignarMayorista.dc.html`) pero cualquier ajuste al precio de venta es una acción aparte, manual, desde Facturación — el sistema no recalcula en silencio, mismo principio que rige el resto de la auditoría (§10, snapshot vs. referencia viva).
3. Motivo obligatorio en cada reasignación (no solo la primera), guardado como texto libre; un `select` con motivos frecuentes + "Otro" agiliza la carga sin perder el detalle.
4. La identidad del mayorista sigue oculta al cliente (§9, PDF arma el itinerario desde los ítems, nunca desde `proveedor_id`), pero la información pública del paquete (hotel, vuelo) sí debe actualizarse en los vouchers/documentos que ve el cliente cuando cambian junto con el mayorista.
5. `SalidaMayorista` (§9, cupo) queda fuera de este alcance: si se construye el control de cupo más adelante, la reasignación debería descontar del cupo del nuevo mayorista y liberar el del anterior, pero eso depende de la decisión pendiente en §17.

**UI:** botón "⇄ Reasignar mayorista" al mismo nivel jerárquico que "Reprogramar viaje" en el detalle de reserva, visible solo si la reserva tiene algún ítem con `origen_tipo=mayorista`; modal con el mismo lenguaje visual (plantilla Rizz, header oscuro, botones pill) que "Reprogramar viaje" ya usa. Mockup de referencia: `ReasignarMayorista.dc.html` (Artifact "Cotizador Multidestino").

**Estado:** diseño validado por el usuario, listo para brief de ejecución — pendiente asignar a sesión 12h (ver plan de ejecución y hoja de ruta).

### 9.3 Corrección del leak de mayorista en el PDF comercial (hallazgo C1, 01-sep-2026)

**Diseño:** columna nueva `descripcion_publica` (nullable) en `opcion_mayorista` — texto libre, cara al cliente, que el vendedor completa al cargar/elegir la opción (ej. "Paquete Panamá 6D/5N"). `resolverNombreItemPdf()` deja de tener cualquier camino hacia `Proveedor.razon_social` o `nombre_comercial`:

```php
// AlternativaController::resolverNombreItemPdf() — después de la corrección
return $item->opcionMayorista?->descripcion_publica ?? 'Paquete mayorista';
```

Sin fallback a ningún dato del `Proveedor` bajo ninguna condición — si el vendedor no cargó `descripcion_publica`, el cliente ve el genérico "Paquete mayorista", nunca un nombre real. Backfill de filas existentes: usar el nombre del `AlternativaItem` asociado si existe algo razonable, o dejar en null y que caiga al genérico (no hay urgencia de backfill perfecto, es un campo nuevo de adopción gradual, mismo criterio que `contenido_tour`).

**`ReservaController::resolverNombreItem()` (uso interno) no se toca en esta corrección** — su fallback a `nombre_comercial`/`razon_social` es correcto para el reporte operativo de staff.

**Nota para M2 (matriz de hoteles, `plan-ejecucion-matriz-hoteles-cotizador.md`):** si M2 centraliza los dos resolvers en una sola función, esa función **no puede ser ciega a la audiencia** — necesita un parámetro explícito (`cliente` vs. `interno`) que decida entre `descripcion_publica` (cliente, nunca el proveedor) y `nombre_comercial`/`razon_social` (interno). Centralizar sin ese parámetro reintroduce el mismo riesgo de leak que esta corrección cierra.

**Verificación mínima:** test de regresión que arme un PDF con un ítem `origen_tipo=mayorista` sin `descripcion_publica` cargada y confirme que el string de `razon_social`/`nombre_comercial` del proveedor no aparece en ningún lugar del PDF renderizado — no alcanza con revisar el campo `nombre`, porque el leak real fue justamente que ese campo terminaba conteniendo el dato prohibido.

**Estado:** diseño cerrado, listo para brief de ejecución — ver `PEGAR-EN-CLAUDE-CODE-fix-leak-mayorista-pdf.md`.

---

## 10. Modelo de reserva recomendado

Sin cambios estructurales. El diseño actual ya resuelve correctamente lo que el brief pide validar en la sección 17-18:

- La regla fija *"la fecha de una reserva se lee siempre de reserva.fecha_viaje_desde/hasta, nunca de la cadena hacia cotización"* es exactamente la garantía que el brief pide (§17: "una reserva histórica no debe depender de una cotización modificada después").
- `sincronizarItems()` como flujo explícito y no automático ("Opción C acordada") es la decisión correcta para ítems agregados después de aceptar — evita sorpresas.
- El único ajuste sugerido: cuando exista `alternativa_destinos`, `Reserva` puede seguir siendo 1-a-1 con `Alternativa` completa (todos los destinos de un viaje aceptado se reservan juntos) — **no** se recomienda "una reserva por destino" salvo que el negocio confirme que quiere poder cancelar/reprogramar un destino sin afectar los demás. Esto es una decisión de negocio, no arquitectónica pura — ver [DECISIÓN REQUERIDA] en §17.

---

## 11. Modelo operativo recomendado

`ReservaItem` + `SalidaOperativa` ya modelan correctamente lo que el brief pide en la sección 18 (agrupar reservas distintas que comparten tour+fecha bajo una sola asignación de guía, sin centralizar el proveedor de transporte porque el propio negocio confirmó que varía por reserva incluso dentro de la misma salida). **CONSERVAR sin cambios.** Con `alternativa_destinos` en el modelo, `SalidaOperativa` seguiría enganchando por `tour_origen_id + fecha` exactamente igual — no depende de la nueva entidad.

---

## 12. Modelo financiero recomendado

**Lado cliente (cobros):** ya resuelto y bien limitado — `ReservaVenta`/`ReservaAnticipo` como puente liviano sobre `Sale`/`Advance` del core, sin duplicar nada. **CONSERVAR.** La limitación conocida y documentada (reparto de un ítem `tarifa_fija` compartido entre pasajeros facturados en Sales distintos) es una decisión de negocio pendiente, no un bug — no se debe "improvisar una fórmula" como el propio código ya decidió.

**Lado proveedor (pagos):** aquí sí hace falta trabajo real. `CronogramaPagoProveedor`/`PagoProveedor` tienen schema pero cero conexión funcional, y estructuralmente no alcanzan para el caso real del brief §21 (pago consolidado de USD 6,500 cubriendo 3 reservas distintas de un mismo proveedor) porque no existe ninguna tabla que registre *cuánto de ese pago consolidado corresponde a cada reserva*.

**Veredicto: REEMPLAZAR el par actual (schema muerto, sin filas reales en producción, riesgo de migración prácticamente nulo) por una cadena que refleje exactamente el mismo patrón que el core ya usa para el lado cliente — no inventar un vocabulario nuevo:**

```
ObligacionProveedor      (qué se le debe a un proveedor — nace de una Reserva/ReservaItem
                           o de una OpcionMayorista elegida; sustituye la idea difusa de
                           "cronograma" con una fila por deuda real, no por cuota)
  └── CuotaProveedor      (si la obligación se paga en partes — sustituye a
                           cronograma_pago_proveedor.numero_cuota)

PagoProveedor            (un pago real, puede cubrir varias obligaciones/cuotas a la vez —
                           mismo rol que Advance/SalePayment en el core)
  └── AplicacionPagoProveedor  (tabla puente: cuánto de este pago se aplicó a qué
                                 ObligacionProveedor/CuotaProveedor — esto es lo que hoy
                                 NO existe y es lo único que realmente falta para el caso
                                 de pago consolidado)
```

Esto es exactamente el patrón `AdvanceApplicationService`/`ReservaVenta` ya usado para el lado cliente, espejado al lado proveedor — no es sofisticación nueva, es consistencia con lo que el propio sistema ya sabe hacer bien. Antes de construirlo, confirmar con el negocio si de verdad hace falta el nivel de "cuota" (pago programado en partes) o si alcanza con Obligación → Pago → Aplicación sin cuotas — ver [DECISIÓN REQUERIDA] §17.

---

## 13. Moneda y tipo de cambio

**Corrección respecto a la v1 de este documento:** no se recomienda mover moneda/tipo de cambio a nivel de destino. Nivel correcto: **se queda en `Alternativa` completa, como ya está hoy**, con la conversión resuelta a nivel de `AlternativaItem` (que ya existe: `moneda_costo` + `precio_convertido`).

Evidencia que sustenta este cambio de recomendación: el benchmarking contra plataformas reales del rubro (Ezus, Tourwriter y similares) muestra que ninguna fuerza una moneda distinta por segmento del viaje ni le muestra al cliente una propuesta con monedas mezcladas — capturan el costo de cada proveedor en su moneda nativa (exactamente lo que `moneda_costo` en `AlternativaItem` ya hace) y presentan **un solo total en una sola moneda de cliente por cotización completa**, con tipo de cambio editable a nivel de la cotización/proyecto, no del segmento. Ezus lo documenta explícitamente: *"convert supplier rates from local currency to your client billing currency in real time, with editable exchange rates per project"*. Mover moneda a `alternativa_destinos` habría sido sobre-ingeniería sin respaldo real de cómo lo resuelve la industria — y habría obligado a tocar el motor de precios (`PriceEngineService::convertirMoneda()`) y todo el recálculo de totales, con el mayor riesgo de todo el plan de refactor, para resolver un problema que en la práctica no existe (dentro de un mismo destino, todos los proveedores ya cotizan en la misma moneda de mercado).

`tipo_cambio_agencia` (histórico global, nunca sobrescrito) y `Alternativa.tipo_cambio_aplicado`/`tipo_cambio_origen` (snapshot al crear la alternativa) **no cambian**. Moneda de costo vs. venta: sin cambios. Redondeo: sin cambios — `ajuste_redondeo` ya está modelado como ítem manual auditable. En síntesis: **§13 ya no requiere ningún cambio de schema** — es el punto donde la arquitectura actual ya coincide con la mejor práctica de la industria.

**Importante:** esto es exactamente el terreno que el brief pide coordinar con `plan-fix-moneda-cotizador.md`, documento no encontrado en el proyecto (ver nota inicial). Aun sin ese documento, la recomendación de esta sección (no mover moneda de nivel) es la que debería prevalecer salvo que esa conversación perdida contenga una razón de negocio concreta no capturada acá — confirmar con el equipo antes de descartarla del todo.

---

## 14. Comercial vs. operativo

Ya cubierto en profundidad en §4 y §11. Es, junto con el manejo de snapshots, la parte mejor diseñada del sistema. Sin cambios.

---

## 15. Información pública vs. interna

Confirmado correcto: la identidad del mayorista nunca llega al PDF comercial ni al cliente (§9). `proveedor_sugerido_manual` está marcado explícitamente como "dato interno, nunca visible al cliente". Los documentos de pasajero (`pasajero_documentos.archivo`) usan almacenamiento privado con endpoint autenticado, nunca link directo. **No se detectaron fugas.** Esto además coincide con el estándar de la industria confirmado por benchmarking: DMC Quote documenta el mismo patrón exacto ("net rate" que el vendedor ve vs. "retail/public rate" que ve el cliente, "the wholesale rate stays between you and the system", vouchers con el branding de la agencia — nunca el del mayorista). Único punto a vigilar a futuro: cuando exista `alternativa_destinos` con `OpcionMayorista` ligada, verificar que el nuevo PDF agrupado por destino mantenga la misma disciplina de no imprimir el proveedor mayorista.

---

## 16. Comparación actual vs. objetivo

| Aspecto | Actual | Objetivo | Cambio |
|---|---|---|---|
| Multi-destino | No existe — 1 destino texto libre por cotización | `alternativa_destinos` por destino, ordenado (sin moneda) | NUEVO |
| Moneda/TC | A nivel de `Alternativa` completa | Sin cambios — se queda en `Alternativa`/ítem (confirmado por benchmarking, §13) | CONSERVAR |
| `OpcionMayorista` | Cuelga de `Alternativa` | Cuelga de `alternativa_destinos` | REFACTORIZAR (mover FK) |
| `AlternativaItem` | Discriminado por `origen_tipo`, 2 FKs muertas | Igual + `alternativa_destino_id` + limpieza de FKs muertas | REFACTORIZAR |
| Pagos a proveedor | Schema sin flujo, sin nexo a reserva | Obligación→Cuota→Pago→Aplicación | REEMPLAZAR |
| Cupo mayorista | Schema sin flujo de alta | Decisión pendiente: construir o descartar | DECISIÓN REQUERIDA |
| Resto (Reserva, Operación, Facturación puente) | Bien diseñado | Igual | CONSERVAR |

---

## 17. Decisiones abiertas

```
[DECISIÓN REQUERIDA] ¿Una reserva por viaje completo (todos los destinos juntos) o
una reserva por destino? Afecta directamente si "cancelar México" puede dejar viva
la reserva de Tarapoto. Recomendación técnica: una reserva por viaje, salvo que el
negocio confirme que necesita cancelar/reprogramar destinos de forma independiente.

[DECISIÓN REQUERIDA] ¿Un anticipo puede aplicarse a un destino específico o siempre
al viaje completo? Con alternativa_destinos introducido, ReservaAnticipo seguiría
siendo por Reserva completa salvo decisión explícita en contra.

[DECISIÓN REQUERIDA] ¿El día del itinerario (dia_referencial) continúa
numerándose de corrido entre destinos (día 1 a 12 sin reiniciar) o se reinicia por
destino (día 1-2 en Tarapoto, día 1-10 en México)? Afecta directamente el cálculo
de fecha en crearReservaItemDesdeAlternativaItem() y el PDF/itinerario. Recomendación
técnica (nueva, alineada con §7): reiniciar por destino, mismo criterio que ya usa
tour_itinerario_items dentro de cada tour — es el patrón que el propio dominio ya
conoce y usa en otro lugar, más fácil de razonar para el vendedor que un corrido
global.

[DECISIÓN REQUERIDA] ¿Se construye el flujo de alta de SalidaMayorista (control
de cupo internacional) o se descarta el control de cupo para mayoristas y se
documenta como decisión consciente? Hoy el campo existe pero nunca se llena.

[DECISIÓN REQUERIDA] Pagos a proveedor: ¿hace falta el nivel de "cuota" (pago
programado en partes, tipo cronograma) o alcanza con Obligación → Pago →
Aplicación sin cuotas para el primer lanzamiento?

[DECISIÓN REQUERIDA] Ubicar o reconstruir plan-refactor-mayoristas-tramos.md,
plan-matriz-hoteles-cotizador.md y plan-fix-moneda-cotizador.md — el brief de
ChatGPT los da por existentes como contexto de negocio y esta auditoría no pudo
contrastarlos porque no están en el proyecto.

[DECISIÓN REQUERIDA] Confirmar si el reordenamiento drag&drop del cotizador
(sesión 11l) persiste algún orden real dentro del día, o si es solo visual —
AlternativaItem no tiene columna `orden` propia hoy.

[DECISIÓN REQUERIDA] destino_tributario amazonia/extranjero sigue bloqueado en
producción a la espera de definición contable — no es una decisión arquitectónica,
pero bloquea facturar cualquier destino internacional una vez exista
alternativa_destinos. Debe resolverse en paralelo al refactor de multi-destino, no
después.

[DECISIÓN REQUERIDA] ¿Se construye contenido_tour (§9.1) desde el primer
lanzamiento o se difiere a una fase posterior? No bloquea nada — OpcionMayorista/
OpcionMayoristaOpcional funcionan hoy con texto libre — pero conviene decidir el
momento antes de acumular texto duplicado en varias cotizaciones que luego haya
que migrar a mano.
```

---

## 18. Decisiones que ya están cerradas (no reabrir)

```
DECISIÓN CERRADA — Mayorista es Proveedor con tipo, no entidad aparte (24-jul-2026).
DECISIÓN CERRADA — Guías son tabla propia `guias`, no tipo de proveedor (24-jul-2026).
DECISIÓN CERRADA — Se descarta el flag `es_internacional` en Proveedor; moneda
                    por tarifa ya resuelve el caso mixto nacional/internacional
                    (24-jul-2026).
DECISIÓN CERRADA — PaquetePlantilla consolida tour_simple y paquete_combo, no dos
                    tablas separadas (24-jul-2026, validado con documentos reales).
DECISIÓN CERRADA — `activo` en proveedor_tarifas/guia_tarifas es columna propia,
                    no se reutiliza `vigente_hasta` (que ya tiene 2 significados).
DECISIÓN CERRADA — Ajuste de redondeo del paquete es un AlternativaItem manual
                    auditable, no un campo numérico oculto.
DECISIÓN CERRADA — Reserva no tiene numeración propia, deriva del código de la
                    cotización padre.
DECISIÓN CERRADA — sincronizarItems() nunca es automático ("Opción C acordada").
DECISIÓN CERRADA — reprogramar() nunca toca cotizacion.fecha_viaje_desde/hasta
                    (fuera de alcance explícito).
DECISIÓN CERRADA — Guardia tributario de facturación solo permite destino
                    'nacional' por ahora (pausa consciente, no bug).
```

---

## 19. Plan de refactorización (por fases)

**Principio general: migración incremental, nunca destruir/recrear schema.** Cero filas reales de producción se pierden en ninguna fase — se confirma explícitamente para cada una.

### FASE 0 — Cerrar los gaps puntuales de bajo riesgo (sin dependencia de alternativa_destinos)
- Objetivo: eliminar los 3 hallazgos concretos de §3 que no requieren el modelo multi-destino.
- Cambios: (a) agregar guard de `alternativa.estado==='aceptada'` a `AlternativaController::update()` cuando llega `descuento_global_pct`/`descuento_global_monto`; (b) índice único parcial `(alternativa_id) WHERE estado='elegida'` en `opcion_mayorista` (mismo patrón ya usado en `salidas_operativas`); (c) marcar oficialmente como deprecadas `alternativa_items.opcion_hotel_tarifa_id`/`paquete_plantilla_id` (sin dropear todavía).
- Tablas/modelos: `alternativas`, `opcion_mayorista`. Controllers: `AlternativaController`. Migraciones: 1 (índice único parcial). Datos existentes: sin impacto. Riesgo: bajo. Dependencias: ninguna.

### FASE 1 — Introducir `alternativa_destinos` en paralelo, sin migrar datos todavía
- Objetivo: crear la tabla y el modelo `AlternativaDestino`, con migración de backfill que cree **un destino único por Alternativa existente** (destino = `Cotizacion.destino` actual, fechas = `Cotizacion.fecha_viaje_desde/hasta`) — compatibilidad total con datos históricos, cero pérdida. A diferencia de la v1 de este plan, **no toca moneda/tipo de cambio** — eso se queda en `Alternativa`, sin cambios (§13).
- Tablas: nueva `alternativa_destinos` (sin columnas de moneda/TC). Modelos: nuevo `AlternativaDestino`. Migraciones: 1 create + 1 backfill. Controllers/Services: ninguno tocado todavía. Riesgo: bajo (tabla aditiva, no toca el motor de precios). Dependencias: Fase 0 completa.

### FASE 2 — Mover `AlternativaItem` y `OpcionMayorista` a `alternativa_destinos`
- Objetivo: `AlternativaItem.alternativa_destino_id` (nullable inicialmente, default = el destino único creado en Fase 1), `opcion_mayorista.alternativa_destino_id` reemplazando `alternativa_id`. Mantener columnas viejas en modo "lectura de compatibilidad" durante un release. **Sin cambios en `PriceEngineService::convertirMoneda()`** (la moneda sigue resolviéndose donde ya se resuelve hoy — este es el ajuste que redujo el riesgo de esta fase respecto a la v1 del plan).
- Tablas: `alternativa_items`, `opcion_mayorista`, `alternativa_destinos`. Modelos: `AlternativaItem`, `OpcionMayorista`, `AlternativaDestino`. Controllers: `AlternativaItemController`, `OpcionMayoristaController`. Frontend: ninguno todavía (solo backend/datos). Riesgo: bajo-medio (más bajo que la v1: no toca el motor de precios). Dependencias: Fase 1.

### FASE 3 — UI de multi-destino en el cotizador
- Objetivo: implementar los cambios de UI descritos en §7.1 — chips de destino sobre las pestañas de día, comparador de mayoristas y botón "Elegir" por destino activo, subtotal por destino en el panel de precio, botón "+ Agregar destino", PDF agrupado por destino.
- Frontend: cotizador (`cotizaciones/editar.vue` o equivalente, store de cotizador, componente de comparador de mayoristas). Riesgo: medio, es la superficie más visible al vendedor y requiere capacitación del equipo de ventas. Dependencias: Fase 2.

### FASE 4 — Pagos a proveedor (Obligación/Cuota/Pago/Aplicación)
- Objetivo: reemplazar `cronograma_pago_proveedor`/`pago_proveedor` (0 filas reales, confirmado) por la cadena descrita en §12.
- Tablas: nuevas `obligaciones_proveedor`, `cuotas_proveedor` (si la decisión abierta lo confirma), `pagos_proveedor` (rediseñada), `aplicaciones_pago_proveedor`. Modelos y Controllers: nuevos, completos (CRUD real, cosa que nunca existió). Datos existentes: ninguno que migrar (tablas vacías en producción). Riesgo: bajo en migración de datos, medio en superficie nueva de negocio (contable). Dependencias: ninguna respecto a `alternativa_destinos` — puede ejecutarse en paralelo a las Fases 1-3.

### FASE 5 — Limpieza final
- Objetivo: dropear columnas deprecadas (`opcion_hotel_tarifa_id`, `paquete_plantilla_id` de `alternativa_items`); dropear `alternativa_id` de `opcion_mayorista` una vez todo lea de `alternativa_destino_id`; activar (si el negocio lo confirma) el flujo de alta de `SalidaMayorista`.
- Riesgo: bajo si las fases anteriores se validaron en producción durante al menos un ciclo de reservas completo. Dependencias: Fases 0-4 estables en producción.

---

## 20. Riesgos

- **Riesgo de negocio, no técnico, en Fase 3**: cambiar la UX del cotizador (single-destino → multi-destino) es el punto de mayor fricción con vendedores ya entrenados en el flujo actual — requiere capacitación, no solo despliegue.
- **Riesgo de doble-escritura durante la Fase 2**: mientras `AlternativaItem`/`OpcionMayorista` mantengan la FK vieja (`alternativa_id`) y la nueva (`alternativa_destino_id`) en modo compatibilidad, cualquier código nuevo que escriba solo en una de las dos desincroniza el dato — mitigar con un solo punto de escritura (Service) desde el día 1 de la Fase 2, nunca dos. (Este riesgo es menor que en la v1 del plan porque ya no involucra el motor de precios/moneda, solo FKs de agrupación.)
- **Riesgo de reventar reportes/PDFs existentes** que hoy leen `Cotizacion.destino`/`fecha_viaje_desde/hasta` como fuente única — mitigado si Fase 1 mantiene esos campos como resumen calculado, pero debe verificarse contra cada reporte real (`ReporteOperativoController`, PDFs de alternativa) antes de cerrar Fase 5.
- **Riesgo de que Pagos a Proveedor (Fase 4) se perciba como "feature nueva" cuando en realidad es deuda técnica** — comunicar internamente que esto no es opcional: sin `AplicacionPagoProveedor` no hay forma honesta de responder "¿cuánto le debemos a este proveedor hoy?", que es la pregunta que originó el pedido en el brief.
- **Riesgo bajo pero real en el índice único parcial de Fase 0**: si en producción ya existe alguna alternativa con más de una `OpcionMayorista` marcada `elegida` (por el bug de carrera que el propio código dice que puede pasar), la migración del índice fallará al crearse — correr un query de verificación antes.

---

## 21. Tests necesarios

**Dominio (precio/moneda/margen):**
- `PriceEngineService::convertirMoneda()` y `evaluarPiso()` **sin cambios de comportamiento** tras introducir `alternativa_destinos` — test de regresión explícito (a diferencia de la v1 del plan, aquí no hay caso nuevo de TC por destino, porque la moneda no se movió — ver §13).

**Cotización multi-destino:**
- Crear alternativa con 2 destinos (`alternativa_destinos`), ítems en ambos, verificar que `recalcularTotalAlternativa()` sigue sumando todos los ítems de la alternativa en la única moneda de presentación, y que el panel de precio puede desglosar el subtotal por destino sin alterar el total general.
- Backfill de Fase 1: cada alternativa existente genera exactamente 1 fila en `alternativa_destinos`, sin pérdida de fechas ni de la relación con sus `AlternativaItem`/`OpcionMayorista` existentes.

**Reserva:**
- Aceptar una alternativa con 2 destinos genera `ReservaItem` para ítems de ambos, con `dia_referencial` resuelto según la decisión tomada en el [DECISIÓN REQUERIDA] de numeración de día (corrido vs. reiniciado por destino).
- Reprogramar un destino no debe tocar `ReservaItem` de otro destino de la misma reserva (si la decisión de negocio es "una reserva por viaje").

**Operación:** sin cambios respecto a hoy — `SalidaOperativa` no depende de `alternativa_destinos`.

**Finanzas — pagos a proveedor (nuevo):**
- Una `ObligacionProveedor` puede recibir aplicaciones de más de un `PagoProveedor` (pago parcial).
- Un `PagoProveedor` puede aplicarse a obligaciones de más de una `Reserva` del mismo proveedor (el caso central del brief §21) — la suma de `AplicacionPagoProveedor` para un pago nunca excede el monto del pago.
- Marcar un pago contra un proveedor `es_referencial=true` debe bloquearse (hueco documentado, nunca implementado — implementar junto con esta fase).

**Seguridad:**
- El PDF/propuesta de un destino con `OpcionMayorista` nunca expone `proveedor_id`/razón social del mayorista al cliente — test de regresión explícito dado que es la garantía más sensible del negocio (brief §3, §12) y el estándar confirmado de la industria (net rate vs. sell rate, §15).

---

## 22. Recomendación final

**No se justifica un refactor mayor ni un reemplazo del modelo actual.** La arquitectura de `Cotizacion`/`Alternativa`/`AlternativaItem`/`Reserva`/`ReservaItem` está, en términos comparativos, mejor diseñada que la mayoría de sistemas de este dominio en esta etapa de madurez: separa snapshot de referencia viva de forma consistente, separa comercial de operativo correctamente, y su propio historial de bugs muestra un equipo que corrige causa raíz (no parches) y documenta la decisión en el código.

La recomendación explícita es: **continuar sobre la arquitectura actual, con una extensión dirigida y deliberadamente liviana (`alternativa_destinos`, sin moneda propia) y una deuda técnica real que saldar (pagos a proveedor)** — no "hacemos un refactor estructural antes de continuar". El riesgo de reescribir `AlternativaItem` en 4 tablas (Alternativa C del brief) es más alto que el problema que resolvería; el riesgo de no construir `alternativa_destinos` es que el caso de negocio que motivó esta auditoría (viaje Tarapoto+México) sigue sin poder representarse. El benchmarking contra la industria (Ezus, Tourwriter, Tourplan, DMC Quote, entre otros) confirmó además que la versión más simple de esta extensión — sin mover moneda de nivel — es también la más alineada con cómo lo resuelven plataformas reales del rubro, no solo la más barata de construir.

**Orden de prioridad sugerido para la siguiente conversación con Claude Code** (ya como plan de ejecución, no de análisis):
1. Fase 0 (gaps de bajo riesgo) — se puede ejecutar esta semana, sin esperar ninguna decisión de negocio pendiente.
2. Resolver las [DECISIÓN REQUERIDA] de negocio marcadas arriba (reserva por destino o por viaje, anticipo por destino, numeración de día, nivel de cuota en pagos) — son las que determinan el diseño exacto de Fases 1-4, no se puede construir bien sin ellas.
3. Fase 4 (pagos a proveedor) puede arrancar en paralelo apenas se resuelva su única decisión pendiente (cuotas sí/no) — no depende de `alternativa_destinos`.
4. Fases 1-3 (`alternativa_destinos`) en orden, con Fase 3 (UX del cotizador) como la de mayor riesgo de adopción — reservar tiempo de capacitación al equipo de ventas.

---

## 23. Análisis de brechas adicional (segunda pasada, post-cierre del diseño §7–§9)

Este análisis nace de volver a mirar el modelo ya cerrado (`alternativa_destinos`, reubicación de `OpcionMayorista`, `contenido_tour`) con una pregunta distinta a la de las secciones anteriores: no "¿está bien diseñado?" sino "¿qué se rompe, qué falta y qué no estamos viendo todavía?". Se organiza en tres niveles: (23.1) huecos funcionales dentro del propio diseño ya aprobado, (23.2) riesgos nuevos que el diseño introduce y que no existían antes de esta auditoría, (23.3) puntos ciegos de alcance — cosas que ni esta auditoría ni los 36 documentos del proyecto cubren, aunque forman parte del objetivo original.

### 23.1 Huecos funcionales dentro del diseño ya aprobado

1. **Solapamiento/huecos de fechas entre destinos no tiene regla.** `alternativa_destinos.fecha_inicio/fin` se autocalcula como "fin del anterior + 1" pero es editable — si el vendedor edita manualmente la fecha_fin de Tarapoto para alargarla, no hay ninguna regla que reajuste México ni que impida que ambos destinos terminen superponiéndose en el calendario. Falta: validación (a nivel de Service, no de constraint de BD) que al menos avise, y decidir si el reajuste en cascada es automático u opcional.
2. **Reordenar destinos (`orden`) no tiene protección de concurrencia.** Mismo patrón de riesgo que ya causó el bug real de `Alternativa::items()` sin `orderBy` (§3.4): si dos pestañas del vendedor reordenan a la vez, no hay índice único ni transacción que lo prevenga. Además, si el negocio elige numeración de día "corrida" (§17) en vez de "reiniciada por destino", reordenar destinos obliga a renumerar `dia_referencial` de TODOS los ítems de los destinos posteriores — no es un simple `UPDATE orden`, es una operación que toca ítems ya congelados. Vale la pena que esto incline la decisión de §17 hacia "reiniciar por destino" (que ya es la recomendación), precisamente porque hace que reordenar destinos sea una operación local y no en cascada.
3. **Borrar un destino con ítems ya cargados no tiene política definida.** ¿Qué pasa si el vendedor agrega "México" por error, carga 3 ítems y una `OpcionMayorista`, y luego quiere quitarlo? No hay una decisión de si eso bloquea el borrado (como otros guards de congelamiento del sistema), lo permite con cascade delete, o requiere un estado `anulado` en vez de borrado físico — este último es el patrón más consistente con el resto del sistema (que nunca borra, siempre marca estado), pero no está escrito en ningún lado todavía.
4. **`OpcionMayorista` no tiene un equivalente "vivo" en `ReservaItem` como sí lo tienen `proveedor_tarifa_id`/`guia_id`.** La tabla de congelamiento (§2) dice que `ReservaItem` mantiene esas dos FKs vivas y reasignables después de aceptar la alternativa — pero no menciona ningún mecanismo para reasignar la oferta de mayorista si, después de aceptada la reserva, el mayorista elegido no puede honrar el cupo o el precio (el caso más caro de fallar es justo el internacional, que es el que motivó todo este rediseño). Hoy esto probablemente se resuelve "a mano" fuera del sistema — vale la pena decidir si merece un flujo formal antes de que el volumen internacional crezca.
5. **Facturación partida por destino no está contemplada.** Todo el diseño asume que la `Reserva` (y por lo tanto la facturación vía `ReservaVenta`/`Sale`) se factura como unidad, con partición ya resuelta por *pasajero/pagador* (§12) pero no por *destino*. Un caso de negocio real y probable: un cliente corporativo quiere una factura para el tramo nacional (Tarapoto) y otra para el tramo internacional (México), con series o incluso RUC distintos. Hoy no hay ningún gancho entre `sale_detail_items` y `alternativa_destino_id` para resolver eso sin trabajo manual.
6. **`destino_tributario` mixto dentro de una misma reserva.** El guardia tributario hoy solo permite `nacional` (decisión cerrada consciente, §18) — pero en cuanto exista `alternativa_destinos` con un destino nacional y otro internacional en la MISMA alternativa/reserva, hay que confirmar que el guard evalúa por ítem (ya se captura `destino_tributario` a nivel de `AlternativaItem`, según §2) y no asume un único valor por reserva completa. No se encontró evidencia en las auditorías factuales de que este caso mixto se haya probado.
7. **`contenido_tour` no tiene noción de día/orden dentro del itinerario.** La tabla es puramente descriptiva (nombre, descripción, incluye) pero no dice "esto va el día 3". El armado del PDF día-por-día para el paquete internacional sigue dependiendo de cómo se ordenen los `OpcionMayoristaOpcional`/ítems que lo referencian — si se quiere un itinerario día-por-día real (no solo una lista de "incluye" y "opcionales"), falta un campo de orden/día en la tabla puente, no en `contenido_tour` mismo.
8. **`contenido_tour_id` como referencia viva, no snapshot — contradice la disciplina que el resto del sistema ya tiene.** Todo el sistema se distingue precisamente por congelar contenido comercial al momento de crear el ítem (§2, tabla de congelamiento). Si `contenido_tour_id` es un FK vivo, editar la descripción de "Excursión San Blas" en la biblioteca reescribiría silenciosamente el PDF de una cotización de hace 3 meses que ya se le entregó al cliente — exactamente el tipo de "recálculo silencioso" que el sistema en todos los demás lugares evita a propósito. Falta decidir: ¿se copia (snapshot) descripción/fotos al vincular, igual que se hace con precio, o se acepta que es contenido "vivo" a propósito (razonable solo si nunca se reimprime un PDF viejo)?
9. **Sin mecanismo anti-duplicado en `contenido_tour`.** Sin una búsqueda-antes-de-crear en la UI ni un índice único razonable (`destino_atractivo_id` + nombre normalizado), es previsible que dos vendedores creen "Excursión San Blas" y "San Blas Full Day" como dos filas distintas para el mismo tour — la biblioteca se ensucia en vez de consolidarse, y se pierde el beneficio principal de la tabla.
10. **Multi-tenancy de `contenido_tour` no está confirmado explícitamente.** Dado que el sistema es multi-tenant (ver `arquitectura-multitenant-backend.md`), hay que confirmar que `contenido_tour` lleva el mismo scoping (`tenant_id` / trait de tenant) que el resto de catálogos — es fácil de olvidar precisamente por ser una tabla nueva y chica.
11. **No hay camino de "promoción" de `contenido_tour` a `PaquetePlantilla`.** Si una agencia empieza a operar directamente un tour que antes solo compraba a un mayorista (consigue proveedor propio), hoy no hay forma de reutilizar la descripción/fotos ya cargadas en `contenido_tour` para poblar el nuevo `PaquetePlantilla` — habría que reescribir el contenido desde cero. No es urgente, pero vale la pena dejarlo anotado para no repetir el mismo problema que `contenido_tour` vino a resolver.

### 23.2 Riesgos nuevos que el propio diseño introduce (no existían antes de esta auditoría)

- **Reportes que agrupan por `Cotizacion.destino` (texto libre) mezclados con el nuevo dato estructurado.** Cualquier reporte histórico de "ventas por destino" quedará partido en dos períodos: antes (texto libre, formato inconsistente) y después (`destino_atractivo_id` normalizado) — hay que decidir si se re-normaliza el histórico o se acepta el corte, y comunicarlo a quien use esos reportes.
- **Adopción doble, no simple.** El vendedor tiene que aprender dos cosas a la vez: la UI de multi-destino (§7.1, ya señalado como riesgo en §20) Y la disciplina de buscar-antes-de-escribir en `contenido_tour` en vez de tipear texto libre como hace hoy. Si la segunda no se refuerza (con UX que sugiera contenido existente al escribir), es probable que la biblioteca nunca se llene y el problema que la motivó siga ahí, solo que ahora hay una tabla vacía más en el schema.
- **Índice único parcial en `alternativa_destinos.orden`** (si se decide protegerlo) puede fallar igual que el de `opcion_mayorista.elegida` en Fase 0 si ya existe algún dato de prueba con orden duplicado — correr la misma verificación previa.
- **Falsa sensación de "ya está resuelto" en pagos a proveedor + mayoristas internacionales.** La Fase 4 resuelve el registro contable del pago, pero no resuelve *cuándo* se le paga al mayorista respecto a cuándo el cliente paga a la agencia (flujo de caja) — con mayoristas internacionales normalmente exigiendo anticipo antes de confirmar cupo, ese descalce de caja es un riesgo de negocio real que ni `ObligacionProveedor` ni `contenido_tour` tocan. Vale la pena que quede explícito que esta auditoría solo cubre el registro, no la política de caja.

### 23.3 Puntos ciegos de alcance (lo que ni esta auditoría ni los 36 documentos del proyecto cubren)

La descripción original del proyecto dice el objetivo final es cubrir "control operativo y logístico hasta su facturación, liquidación y fidelidad del cliente" — post-venta. Revisando la lista completa de documentos del proyecto, ninguno de los 36 aborda estos temas:

- **Fidelización / post-venta**: no existe ningún doc de programa de puntos, referidos, encuestas de satisfacción (NPS), gestión de reclamos post-viaje, ni de re-cotización automática a clientes recurrentes. Esta auditoría (y el modelo de multi-destino/mayoristas) es 100% pre-venta y operación — el tramo "post-venta" del objetivo original sigue en cero.
- **Liquidación**: tampoco aparece ningún documento. Es además un término ambiguo en este negocio — puede significar liquidación de comisiones a vendedores, liquidación de guías/operadores tras una salida, o cierre contable de una salida operativa completa (ingresos vs. costos reales vs. presupuestados). Antes de poder auditar esa pieza hace falta que el negocio defina cuál de esas cosas (o cuáles) quiere decir con "liquidación".
- **Facturación por destino** (23.1.5) es en realidad la primera grieta concreta de este punto ciego más amplio — es donde "facturación" y "multi-destino" chocan por primera vez, y probablemente no sea la última.

**Recomendación:** antes de armar el plan de ejecución final, decidir explícitamente el alcance de esa primera etapa — si fidelización/liquidación quedan fuera de esta ronda (razonable, dado que el caso de negocio urgente es multi-destino + mayoristas) o si al menos hace falta dejar un lugar reservado en el modelo (por ejemplo, no cerrar `ReservaVenta`/`Sale` de una forma que haga imposible después enganchar un programa de fidelización sobre el cliente). No hace falta diseñarlo ahora — sí hace falta decidir conscientemente que se está postergando, no que se está ignorando.

---

## 24. Evaluación de mantenibilidad y escalabilidad del plan (01-sep-2026)

Esta sección responde a una pregunta distinta a todas las anteriores: no "¿está bien diseñado?" sino "¿qué tan bien envejece esto?" — evaluada después de cerrar el diseño de multi-destino, mayoristas, `contenido_tour`, reasignación post-venta y la matriz de hoteles (§7-§9, §23, y el canvas de mockups). Se separa en cuatro capas porque "mantenible" significa cosas distintas en cada una, y no todas están igual de sólidas.

### 24.1 Modelo de datos: sólido porque no inventa nada nuevo

Todo lo diseñado en esta ronda extiende patrones que este codebase ya probó y le funcionaron, en vez de introducir un paradigma nuevo: `alternativa_destinos` es aditivo puro con backfill de compatibilidad (mismo tipo de migración de bajo riesgo que ya ejecutaron con éxito 11r/11s para fechas); la reasignación de mayorista usa columnas de auditoría livianas en vez de una tabla de historial nueva (mismo patrón exacto que `reprogramar()`). Cuando un sistema resuelve el mismo tipo de problema siempre de la misma forma, cualquiera que lo lea después —humano o Claude Code— reconoce el patrón sin aprender uno nuevo por módulo. Ese es el activo de mantenibilidad más valioso que tiene este proyecto y esta ronda lo respeta.

**El punto más frágil de todo lo diseñado hoy es `contenido_tour` — no por el schema, que es mínimo y correcto, sino porque su valor depende enteramente de que alguien lo use bien.** Este mismo codebase ya tiene tres precedentes confirmados del mismo síntoma: `Proveedor.margen_default_tipo/valor` se guarda pero nunca se lee (§3.6), `salidas_mayorista` es un catálogo sin ningún punto de escritura real (§3.8), y dos columnas de `alternativa_items` quedaron muertas (§3.5). Se diseña una tabla razonable, nadie la termina de conectar al flujo real de uso diario, y queda como peso muerto. Si el buscador de "contenido reutilizable" no es lo bastante bueno como para que buscar sea más rápido que tipear de nuevo, `contenido_tour` se convierte en el cuarto caso de esa lista, no en el primero resuelto. **Acción concreta recomendada:** medir la adopción real después de la sesión 12e — qué porcentaje de `OpcionMayorista`/`OpcionMayoristaOpcional` nuevas terminan vinculadas a un `contenido_tour` existente vs. cuántas siguen con texto libre — y no dar por sano el diseño solo porque el schema esté bien.

### 24.2 Proceso de desarrollo: funciona, pero ya mostró sus costuras, y este plan las hereda

La hoja de ruta (`plan-hoja-de-ruta-ejecucion.md`) ya tuvo que archivarse una vez (18-ago-2026) porque se volvió demasiado larga para que una sesión nueva la lea entera — el bloque 12a-12h agregado hoy le suma 8 filas más, así que ese archivado va a tener que repetirse, y si no se hace a tiempo cada sesión nueva pierde minutos releyendo historia irrelevante. La regla de "una rama por sesión" ya se rompió una vez en la práctica (5 sesiones apiladas sin querer en una sola rama, nota (e) del 20-ago-2026) y se resolvió, pero fue trabajo extra evitable.

El ejemplo más concreto de este riesgo ya ocurrido, no hipotético: tres documentos de negocio (`plan-refactor-mayoristas-tramos.md`, `plan-matriz-hoteles-cotizador.md`, `plan-fix-moneda-cotizador.md`) que el propio brief de ChatGPT asumía que existían, se perdieron porque vivieron solo en una conversación de sesión y nunca se guardaron como doc (ver nota metodológica al inicio de este documento). Es la prueba de que "se decidió algo en una conversación" y "quedó documentado" no son la misma cosa en este proceso — y toda la conversación de diseño de mayoristas/reasignación/hoteles de esta ronda genera exactamente ese mismo tipo de contexto valioso, que solo queda a salvo si termina escrito en un doc del proyecto (como esta sección) y no solo en el historial de un chat.

### 24.3 UI: la decisión correcta hoy, con un techo que ya se ve venir

Reusar los chips y el modal "Agregar servicio" existentes en vez de inventar pantallas nuevas es la decisión correcta para mantenibilidad: menos superficie de código nueva, menos estilos que sincronizar, nada nuevo que el vendedor tenga que aprender — coincide además con el criterio que el propio negocio pidió explícitamente en esta ronda. Pero ese modal ya cargaba bastante responsabilidad (3 orígenes de tipo, biblioteca de tarifas, comparador de mayoristas) y esta ronda le agregó capas encima (contexto de destino activo, buscador de `contenido_tour`, chips de categoría dentro de una opción de mayorista). Individualmente cada agregado es razonable; en conjunto, ese componente empieza a parecerse a `ConfiguracionAgencia` a nivel de schema — que la propia auditoría marca en §5 como un "cajón de sastre" que acumula una columna más por sesión sin que sea grave todavía, pero que vale la pena vigilar. No hace falta partir el modal hoy. Sí vale la pena no asumir que seguir agregándole responsabilidades es gratis indefinidamente.

### 24.4 Escalabilidad de negocio: resuelve el caso real de hoy, con límites conscientes

El diseño de esta ronda escala bien al caso que lo motivó — 2, quizás 3 destinos por viaje, un mayorista comparado a la vez por destino. Si el negocio algún día vende paquetes de 5+ destinos (tipo vuelta al mundo), la fila de chips de destino y el subtotal por destino en el panel de precio empezarían a competir por espacio — no es un problema hoy, es el techo natural de esta versión, y está bien que lo sea: diseñar para ese caso ahora habría sido sobre-ingeniería para un negocio que no lo necesita todavía.

Lo que sí quedó pendiente y sin sesión asignada en el plan 12a-12h es la recomendación de §6 de formalizar cada `origen_tipo` como una "spec" de validación en Services antes de que llegue un sexto origen (el brief ya menciona "seguro de viaje" como servicio vendible futuro, §3). Si ese refactor de organización de código no se hace antes, agregar ese sexto tipo va a costar más de lo necesario — es candidato a una sesión propia, sin bloquear nada de 12a-12h.

Y la honestidad más importante, ya nombrada en §23.3 pero que vale la pena repetir en esta evaluación de cierre: todo lo escalado en esta ronda es pre-venta y operación. Facturación partida por destino, pagos a proveedor, fidelización y liquidación quedaron deliberadamente fuera — así que la escalabilidad del módulo en el sentido más amplio del objetivo original del proyecto ("control operativo y logístico hasta su facturación, liquidación y fidelidad del cliente") sigue con ese tramo final en cero. No es un defecto de esta ronda — es el límite consciente de su alcance, y debe seguir siendo una decisión consciente, no un olvido, a medida que el proyecto avance.

---
