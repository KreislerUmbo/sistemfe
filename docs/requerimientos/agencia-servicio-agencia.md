# Requerimiento — «Servicio de la agencia»: panel lateral para agregar servicios al día

| | |
|---|---|
| **Fecha** | 2026-11-25 |
| **Módulo** | AGE |
| **Pedido por** | usuario |
| **Relacionado** | `docs/requerimientos/agencia-opcion-mayorista.md` (T-155), maqueta `docs/maquetas/paquete-mayorista.html`; esta maqueta: `docs/maquetas/servicio-agencia.html` |

> El estado está solo en `docs/backlog.md` (línea «Rediseño de "Servicio de la agencia"», Por analizar).

## 1. Lo que se necesita

Rediseñar el modal «Agregar servicio» del cotizador con el mismo estilo que el panel del paquete
de mayorista: un panel lateral que se abre desde «+ Agregar al día N», filtros por tipo con
íconos (tours, traslados, hoteles, guías, otro), el detalle al costado sin cambiar de pantalla,
agregar varios servicios sin cerrar, «usados recientemente» y la misma ficha de hotel que el
mayorista. Antes de diseñar: decidir el precio del hotel Local/Nacional y el riesgo de cobrar dos
veces.

## 2. Análisis

### Lo que hay hoy en el código (leído el 25-nov-2026)

**Cómo funciona.** `cotizador/DrawerBiblioteca.vue` es un modal centrado (`modal-xl`, Teleport a
`body`) que cambia de pantalla según `pasoDrawer` (`useDrawerBiblioteca.ts::pasoDrawerDe()`):

1. **Lista** (`grid`): tres botones arriba (Servicios de la agencia · Paquete de mayorista ·
   Guías). En «Servicios de la agencia» (`BibliotecaLocal.vue`): chips Todos, Tours, Paquetes, los
   tipos de proveedor fijos y «Más ▾»; buscador; tres filtros más (destino, servicio, proveedor);
   una grilla de 3 columnas que mezcla tarifas de proveedor y tours/paquetes del catálogo,
   paginada en el cliente; abajo «Servicio sin catálogo» y «Hotel sin catálogo».
2. **Matriz de hotel** (`matrizHotel`): al hacer clic en un hotel la lista desaparece y se ve
   `HabitacionMatrixPicker.vue` (tipo de habitación, precio, noches, «Comparar varias opciones»).
3. **Modo de precio** (`modoPrecio`): cualquier otra tarifa de proveedor pregunta «¿Cómo se
   cobra?» con dos botones (tarifa fija / por persona).
4. **Manual** (`manual`): `ItemManualForm.vue` para un servicio sin catálogo.

La flecha «Volver» ejecuta los reinicios que registró cada bloque (`alVolverAGrid`).

**Qué se carga.**
- Al montar la página: los tipos de proveedor y los servicios (para chips y filtros).
- Al abrir: el filtro de destino vuelve al destino activo y se pide
  `bibliotecaCotizadorService.buscar()` (hasta ~150 filas). Cada chip, búsqueda o filtro vuelve
  a pedirlo.
- Clic en un hotel: `proveedorService.listarTarifas()` de ese hotel, **cada vez**.
- Agregar un servicio suelto: `agregarProveedor()` y actualización parcial del lienzo
  (`aplicarItemActualizado`), o la cotización completa si no alcanza.
- Agregar un tour o paquete del catálogo: `cargarDesdePlantilla()` + **recarga completa** de la
  cotización + **cierra el modal** (`clicResultadoPlantilla`).
- Comparar N hoteles: N POST seguidos, uno por tarifa (`agregarGrupoHotel`).

**Problemas confirmados.**

| # | Problema | Dónde |
|---|---|---|
| P1 | Cada paso reemplaza la lista: al entrar a un hotel se pierden de vista la lista y los filtros; para comparar otro hotel hay que volver y buscarlo de nuevo | `DrawerBiblioteca.vue` (`pasoDrawer`) |
| P2 | Un tour o paquete del catálogo **cierra** el modal; un servicio suelto lo deja abierto. Para armar un día con 3 tours se abre 3 veces | `useBibliotecaCotizador.ts::clicResultadoPlantilla` |
| P3 | Servicios, paquete de mayorista y guías están al mismo nivel. El mayorista ya sale de aquí (T-155); las guías son un tipo de servicio más | `DrawerBiblioteca.vue` líneas 16-23 |
| P4 | Los filtros «servicio» y «proveedor» **ocultan en silencio** tours y paquetes | `BibliotecaLocal.vue` (comentario de las líneas 39-42) |
| P5 | Las tarjetas muestran el nombre del proveedor, no el del servicio; un hotel muestra «desde» la habitación más barata en precio de adulto | `BibliotecaLocal.vue` líneas 74-91 |
| P6 | Paso extra «¿Cómo se cobra?» en cada traslado, restaurante, etc., siempre con cantidad 1 | `useHotelesLocales.ts::confirmarModoPrecio` |
| P7 | Hotel: dos formas distintas para las fotos (no registrado: se suben en el modal; de Proveedores: enlace a su ficha en otra pestaña). El hotel local no tiene descripción ni calificación, el del mayorista sí (T-155) | `DrawerBiblioteca.vue` líneas 43-66 |
| P8 | «Hotel sin catálogo» pide tratamiento tributario y destino **por cada tipo de habitación** (5 columnas) | `BibliotecaLocal.vue` líneas 148-186 |
| P9 | El día al que se agrega no se ve en el modal ni se puede cambiar sin cerrarlo | `diaActivoParaAgregar`, sin control en el modal |
| P10 | Las guías piden tributario y destino en cada alta | `BibliotecaGuia.vue` |
| P11 | No hay «usados recientemente»: el mismo traslado se busca en cada cotización | — |
| P12 | La comparación de hoteles es un modo escondido del picker («Comparar varias opciones») y un aviso suelto | `HabitacionMatrixPicker.vue`, `BibliotecaLocal.vue` |
| P13 | Voseo: el título «Elegí la habitación» | `useDrawerBiblioteca.ts:69` |

### El precio del hotel Local/Nacional (decisión previa)

**Qué dicen los documentos reales.** En Cusco (`docs/auxiliares/Nacional PDKM-CZ…`) y Alto Mayo
(`Local PDKM AM-04…`) el precio «por persona según el hotel» **no es el del hotel**: es el del
**programa completo** (Alto Mayo: 4 noches + desayunos + 4 tours + traslados, S/ 1,500 a 2,289
por persona según hotel y ocupación; Cusco: lo mismo más el vuelo Tarapoto – Cusco, USD 769 a 829
en doble). Es exactamente la forma del paquete de mayorista, solo que el operador es la propia
agencia.

**Qué hace hoy el sistema.**
- Hotel suelto (de Proveedores o «sin catálogo»): precio **por noche por habitación**;
  `cantidad` = noches; total = precio × noches.
- Paquete del catálogo (`paquetes_plantilla` + `paquete_plantilla_precios`): **un** precio por
  adulto y niño por temporada. No varía según el hotel: no sirve para Cusco ni para Alto Mayo.
- Paquete de mayorista: precio del paquete por adulto según hotel y ocupación. Los tours del
  paquete son itinerario, sin precio.

**Opciones.**

| | Opción | A favor | En contra |
|---|---|---|---|
| A | Cambiar el hotel local a «paquete por persona» | Un solo modelo | El mismo campo cambia de significado: las cotizaciones guardadas (precio por noche × noches) quedarían mal; y cuando se arma un viaje a medida (hotel + tours sueltos con precio) sí se necesita el precio por noche |
| B | Hotel con dos modos (por noche / paquete por persona) | Un solo lugar | Es la puerta al cobro doble: un hotel «paquete» con tours sueltos con precio en el mismo día, sin nada que los relacione |
| **C (recomendada)** | **Dos cosas distintas.** El hotel de «Servicio de la agencia» sigue **por noche**. El programa propio (Cusco, Alto Mayo) es un **paquete de la agencia** con la misma estructura y el mismo panel que el de mayorista: hoteles con precio por persona y ocupación; tours, traslados y vuelo como itinerario **sin precio** | Cada precio tiene un solo significado; las cotizaciones guardadas no cambian; se reutiliza T-155 entero (panel, ficha de hotel, pegar tabla, fee, PDF con «Opciones de hoteles») | Hay que agregar el «origen: agencia» al paquete y que el proveedor del paquete pueda ser la propia agencia |

**Riesgo de cobrar dos veces.** Con C, dentro de un paquete (de agencia o de mayorista) los tours
no tienen precio: el doble cobro no puede pasar *dentro* del paquete. Puede pasar *fuera*: un
paquete con «City Tour» incluido y, además, el City Tour agregado desde «Servicio de la agencia»
con precio en el mismo destino. Resguardo propuesto, sin bloquear (preferencia del usuario: pocas
alertas): al agregar un servicio con precio en un destino que ya tiene paquete, si coincide con un
tour incluido (mismo `paquete_plantilla_id` o nombre parecido), el detalle muestra «Ya incluido en
el paquete … ¿agregarlo igual?»; un hotel suelto en un destino con paquete muestra «El paquete ya
incluye hotel: este se cobra aparte». En el lienzo, la misma marca junto al ítem.

**Cotizaciones ya guardadas.** Con C no se migra nada: los hoteles por noche siguen igual, los
PDF enviados no cambian (las copias del ítem ya guardan su precio). Un programa propio cargado hoy
como hotel por noche + tours con precio sigue siendo válido; pasarlo a paquete es manual (no
conviene convertir en lote: no se sabe qué hotel «incluye» qué tours). **Antes de la tarea:**
contar en producción las alternativas que tienen a la vez un hotel por noche y tours de su mismo
destino (para estimar si alguien ya cargó un programa propio así); según la memoria del 07-sep,
en agencia-demo no había ninguno.

### El mismo hotel, dos formas de venderlo (respuestas del usuario, 25-nov)

El hotel de la agencia se vende de dos formas: **dentro de un programa propio**, como el
mayorista, con precio por persona según el hotel y la habitación para que el cliente compare, o
**suelto**, con precio por habitación por noche. La forma la decide **por dónde se agrega**, no un
interruptor en el hotel: «Servicio de la agencia» cobra por noche y el «paquete de la agencia»
cobra por persona con todo el programa incluido. La **ficha del hotel es una sola** (en
Proveedores) y la usan las dos.

Respuestas del usuario:

1. **Los tipos de habitación cambian según el hotel** («Superior matrimonial», «Twin», «Familiar
   2 + 2», «Junior suite»…), y matrimonial y doble pueden tener precios distintos. Hace falta una
   plantilla de tipos de habitación por hotel.
2. **Los precios cambian por temporada.**
3. **Los niños varían por hotel:** el precio y también las edades.

**Qué hay hoy en el código** (leído el 25-nov):

- `proveedor_tarifas.tipo_habitacion` y `opciones_hotel_tarifas.tipo_habitacion`: una **lista
  fija** (`simple | matrimonial | doble | triple | familiar`, en `types/agencia-viajes.ts` y
  `AlternativaPdfService::ORDEN_TIPO_HABITACION`). `tipo_cama` (texto libre) y `descripcion` (corta,
  «vista al mar») van por tarifa. Dos tipos del mismo hotel con la misma categoría (dos dobles
  distintas) no se pueden cargar.
- Temporadas: catálogo central `temporadas` + `temporada_ocurrencias`;
  `proveedor_tarifas.temporada_id` y `vigente_desde/hasta`; `paquete_plantilla_precios` tiene un
  precio por temporada.
- Niños: `proveedor_tarifas` ya tiene `precio_venta_nino`, `precio_venta_infante`, cama adicional
  (costo y venta) y `edad_min_nino` / `edad_max_nino` / `edad_max_infante`. La ficha de alojamiento
  (`ProveedorAlojamientoDetalle`) y `opciones_hotel` tienen `edad_max_infante_gratis` y
  `edad_max_nino_cama_adicional`, con valor por defecto en `configuracion_agencia`.
  `cotizacion_pasajeros.edad` guarda la edad real de cada pasajero.

**Propuesta:**

- **Tipos de habitación por hotel** (la plantilla): cada hotel tiene su lista con el **nombre que
  usa el hotel** (sale en el PDF), una **categoría** (simple, doble, triple, familiar, suite; sirve
  para ordenar, comparar entre hoteles y proponer la ocupación según los pasajeros), capacidad
  (adultos y niños) y tipo de cama. Las tarifas por noche y los precios del paquete apuntan a esos
  tipos. La lista fija de hoy pasa a ser la categoría: las tarifas guardadas quedan con un tipo por
  categoría, con el mismo nombre de hoy, y se migra sin cambiar precios.
- **Temporadas:**
  - *Hotel suelto:* la temporada sale **de la fecha de cada noche**. Un viaje del 12 al 16-dic con
    la temporada alta desde el 15-dic cobra 3 noches regulares y 1 alta. El detalle lo muestra.
  - *Paquete de la agencia:* la tabla es hotel × tipo de habitación × temporada, y la temporada sale
    de la **fecha de salida** del viaje (`fecha_viaje_desde` de la cotización).
- **Política de niños por hotel:** franjas de edad (desde, hasta), cada una con su forma de cobro:
  gratis compartiendo cama, cama adicional con precio por noche, precio de niño en la habitación,
  o paga como adulto. Nace con las edades por defecto de la agencia y cada hotel las cambia. Con
  `cotizacion_pasajeros.edad`, el sistema elige la franja de cada niño:
  - Si **falta la edad**, la pide antes de calcular.
  - Si el niño **paga como adulto**, avisa que hay que elegir una habitación con lugar para todos.
  - *En el paquete,* cada franja tiene su precio por persona del programa. Puede depender del tipo
    de habitación: niño en la familiar, distinto de niño con cama adicional en una doble.
- **En la cotización se copia** lo que se usa (tipo, precio, franja), igual que hoy: cambiar la
  ficha del hotel o el catálogo del paquete no cambia una cotización ya armada ni un PDF enviado.

**Orden de tareas** (en el backlog, T-158 a T-160):

1. **Ficha del hotel:** tipos de habitación propios, temporadas y política de niños, en
   Proveedores. Es la base de las otras dos, así que va primero.
2. **«Servicio de la agencia»:** el panel de esta maqueta, con el precio por noche según la
   temporada y los niños según el hotel.
3. **Paquete de la agencia:** el panel de T-155 con la tabla hotel × habitación × temporada ×
   niños en el catálogo de paquetes. Va después de T-155.

### Propuesta de pantalla (sobre la maqueta)

- **Entrada:** «+ Agregar al día N» en cada día del lienzo (y «+ Servicio de la agencia» en la
  alternativa, que abre en el día activo). El paquete de mayorista y el paquete propio tienen su
  propia entrada (T-155).
- **Panel lateral** sobre el cotizador, en dos columnas, como el del mayorista. Arriba: destino y
  **selector de día** («Agregando al día 3 ▾»): se cambia sin cerrar.
- **Izquierda, la lista:** buscador; filtros por tipo con ícono y conteo: Todos · Tours ·
  Traslados · Hoteles · Guías · Otro (restaurantes, entradas, tipos de proveedor sin chip y
  «Sin catálogo»). El filtro de destino es un chip quitable con el destino activo; servicio y
  proveedor pasan a «Más filtros» y, si se usan, lo dicen («Filtrando por proveedor: los tours no
  aparecen»). Arriba de la lista, **Usados recientemente** (los últimos 8 de este destino,
  `localStorage` por usuario y tenant: no necesita backend). Cada fila: ícono, **nombre del
  servicio**, proveedor en gris, precio con su unidad («por persona», «por noche», «por servicio»,
  «por día»), y una marca «✓ en el día 3» si ya está agregado.
- **Derecha, el detalle** del servicio elegido, sin reemplazar la lista:
  - *Tour:* fotos, descripción, qué incluye, días que ocupa, precio por persona; pasajeros
    (todos marcados); «Agregar al día 3».
  - *Hotel:* la **misma ficha** que el mayorista (nombre, estrellas, régimen, calificación con
    fuente, descripción, 1 foto de fachada y 2 de habitación); debajo, las habitaciones con
    **precio por noche**, noches (las del destino), cantidad de habitaciones y pasajeros.
    «Agregar» o «Comparar con otro hotel» (el hotel queda en una bandeja de comparación arriba
    del detalle y se elige el siguiente en la lista sin perderla).
  - *Traslado, otros:* el modo de precio se elige **en el detalle** (por servicio / por persona),
    con el que tenga la tarifa ya marcado, y la cantidad; sin paso aparte.
  - *Guía:* sus tarifas por destino y modalidad, días.
  - *Sin catálogo:* el formulario manual en el detalle; un hotel nuevo usa la ficha de hotel y
    «Registrar en Proveedores» (mismo patrón que T-155).
  - El tratamiento tributario sale del valor por defecto de la agencia y del servicio; se cambia
    en «Tributario ▾», plegado (P8, P10).
- **Agregar varios sin cerrar:** cada «Agregar» guarda, actualiza el lienzo de fondo y deja el
  panel abierto. Abajo, una barra: «3 agregados al día 3 · total USD 412 · Deshacer el último» y
  «Listo». Un tour de varios días también deja el panel abierto y avisa «ocupa los días 3 y 4».
- **Doble cobro:** el aviso «Ya incluido en el paquete» del punto anterior.

### Casos

| # | Situación | Qué debe pasar |
|---|---|---|
| 1 | Armar el día 2 con un tour, un traslado y un almuerzo | Se agregan los tres sin cerrar el panel; la barra suma 3 |
| 2 | Cambiar al día 3 sin cerrar | Selector de día; lo siguiente va al día 3 |
| 3 | Comparar 3 hoteles | Cada uno se suma a la bandeja; «Agregar comparación» crea el grupo (`grupo_opcion_id`) como hoy |
| 4 | Hotel de Proveedores sin descripción | La ficha muestra los campos vacíos con «Completar en Proveedores» |
| 5 | Destino con paquete que incluye City Tour; se agrega el City Tour suelto | Aviso «Ya incluido en el paquete», no bloquea |
| 6 | Filtro por proveedor activo | Mensaje de que tours y paquetes no aparecen |
| 7 | Alternativa aceptada (T-030) o cotización enviada (T-022) | Igual que hoy: solo lectura / pasa por la revisión |
| 8 | Servicio usado en otra cotización | Aparece en «Usados recientemente» |

### Reglas afectadas

- Nueva: el precio del hotel de «Servicio de la agencia» es por noche por habitación; el precio
  por persona según hotel existe solo dentro de un paquete (de mayorista o de la agencia), cuyos
  tours no tienen precio.
- Nueva: agregar un servicio no cierra el panel, sea suelto, tour o paquete del catálogo (cambia
  el comportamiento de `clicResultadoPlantilla`).
- Sin cambio: comparación de hoteles por `grupo_opcion_id`; bloqueo T-030; revisión T-022.

### Decidido (25-nov)

1. **Precio del hotel Local/Nacional: opción C.** El hotel suelto cobra por noche; el programa
   propio es un paquete de la agencia con el panel de T-155.
2. **Temporada del paquete de la agencia:** sale de la **fecha de salida** del viaje. Repartirla
   por noche dentro del paquete se construye más adelante, si hace falta. El hotel suelto sí
   reparte por noche.
3. **Niño en el paquete:** como en la maqueta. Cada franja de edad tiene su precio por persona y
   puede tener uno distinto según el tipo de habitación (niño en la familiar ≠ niño con cama
   adicional), opcional; vacío, vale el precio general de la franja. Se pule al armar la tarea.

**Decidido (26-nov)**, con las recomendaciones de la propuesta:

4. **El paquete de la agencia es una tarea propia** (T-160), después de T-155: reutiliza su panel
   y T-155 no se agranda.
5. **El pasaje aéreo suelto no entra al panel:** sigue independiente, con su botón propio en el
   lienzo, como hoy (corrección del usuario, 26-nov). Dónde vive el vuelo después de la venta
   (boleto, check-in de la aerolínea, tarjeta de embarque) se analiza aparte en
   `agencia-vuelo-despues-de-la-venta.md`.
6. **«Usados recientemente» es por usuario**, guardado en su navegador (`localStorage`, por
   usuario y tenant), sin backend.
7. **Los paquetes del catálogo (`paquetes_plantilla`) siguen en este panel**, tours y combos de
   varios días, pero sin cerrar el panel al agregarlos.
8. **Los tipos fijos de habitación pasan a ser la categoría**; se crea un tipo por cada categoría
   ya usada, sin tocar precios. Antes de la tarea, contar en producción las tarifas de hotel por
   categoría.

### Mejoras de diseño del lienzo (26-nov)

Revisando otras herramientas de agencias (organizan la cotización por tipo de servicio: Vuelos,
Hoteles, Tours, Traslados… con el itinerario por día al final, y con pestañas de tipo de
cotización arriba), se decidió:

- **El día sigue siendo la vista principal** del lienzo: los programas propios (Cusco, Alto Mayo)
  se arman por día. Se agrega el botón **«Por día / Por tipo»**: la vista por tipo agrupa los
  mismos ítems en Vuelos, Hoteles, Tours, Traslados y servicios, y Otros, cada grupo plegable con
  cantidad y total («2 tours · USD 160»). Solo frontend; además deja a la vista un tour repetido.
- **Encabezados con resumen:** cada día, plegable, con «3 servicios · USD 120»; el bloque del
  paquete, con «4 hoteles · desde USD 779 por persona · falta Condiciones», sin totales por rubro
  (en el paquete los tours y el vuelo no tienen precio).
- **«Resumen económico»** (antes «Precio») al costado y fijo; en pantallas angostas pasa abajo.
  Ver «Revisión de la maqueta (26-nov)».
- **Sin pestañas de tipo de cotización.** Una cotización cambia de forma («solo hotel» que después
  suma tours). Más adelante, como atajos al crear («¿Qué vas a cotizar?»): solo vuelo abre el
  pasaje aéreo, solo hotel abre el panel en Hoteles, paquete abre el del mayorista. El tipo no se
  guarda: se deduce del contenido.
- **Leer un vuelo pegado** (texto del sistema de reservas): requerimiento aparte, en «Por
  analizar» del backlog (privacidad y costo si se usa un servicio externo).

Van en T-159, salvo los atajos al crear (sin tarea todavía).

### Revisión de la maqueta (26-nov)

Con la maqueta a la vista (`docs/maquetas/servicio-agencia.html`), el usuario pidió y aprobó:

**Lista del panel**
- **Tres franjas:** arriba, fija, el buscador con «Filtros ▾» al lado (proveedor), los botones de
  tipo con ícono (se mantienen como estaban: en pastillas parecían etiquetas de un hotel, «wifi»,
  «parqueo») y una línea con el conteo y los filtros activos, cada uno con su ✕; en el medio,
  recientes y resultados con scroll propio; abajo, fijos, **+ Sin catálogo** y **+ Hotel nuevo**
  (antes había que bajar hasta el final de la lista).
- **Búsqueda sin resultados:** ofrece «+ Crear «texto» sin catálogo» con el nombre ya puesto.
- **Por tandas, no por páginas:** el servidor devuelve 30 por vez, con la búsqueda y los filtros
  resueltos allí; arriba «Mostrando 30 de 1.000», al final «Ver 30 más». Cambiar búsqueda, tipo o
  filtro vuelve a empezar; «Ver más» no mueve el scroll.

**Lienzo**
- **Una línea por servicio:** ícono del tipo, nombre, detalle corto, marcas («A elegir», «Sin
  costo», «En el paquete», «Repetido») y total. Clic: proveedor, costo (si se ve lo interno),
  «Mover a día ▾» y Quitar. Arrastrar entre días, más adelante y solo como extra.
- **Días plegables** con «N servicios · USD» en el encabezado; los días vacíos, en una línea con
  «Agregar». «Expandir todo / Plegar todo».
- **Barra de vistazo:** «7 servicios · 5 días · 3 pasajeros · ⚠ 1 por resolver», junto a
  «Por día / Por tipo».

**Resumen económico**
- **Para el cliente:** desglose **siempre por tipo de servicio** (Hoteles, Tours, Traslados, Guías,
  Otros), aunque el lienzo esté por día; clic en una línea pasa el lienzo a «Por tipo» y lleva a
  ese grupo. Total y promedio por pasajero.
- **Interno · solo la agencia:** costo, margen (USD y %), gravado y exonerado (Amazonía), con
  **Ocultar** para compartir pantalla con el cliente. Aviso si hay servicios sin costo (el margen
  sale más alto de lo real).
- **Por resolver:** hotel a elegir, servicio sin costo, cobro doble con el paquete, servicio
  repetido; cada aviso lleva a su servicio. Sin alertas emergentes.

Todo va en T-159.

### Riesgos

- **Dinero:** el aviso de doble cobro no detecta todo (nombres distintos para el mismo tour).
- **Datos existentes:** C no cambia el significado de ningún precio guardado (con A sí, y sería
  alto riesgo). La única migración es la de los tipos fijos de habitación a categoría (decisión
  8), sin tocar precios.
- **Tributario:** plegar el tratamiento no cambia cómo se resuelve; sigue saliendo del servicio y
  del valor por defecto de la agencia (`resolverTipAfeIgv()` no cambia).
- **Rendimiento:** la ficha del hotel se pide al elegirlo; se guarda en memoria mientras el panel
  está abierto (hoy se pide en cada clic).

## 3. Resultado

- **Decisiones:** §2, «Decidido (25-nov)», «Decidido (26-nov)», «Mejoras de diseño del lienzo» y «Revisión de la maqueta».
- **Tareas:** T-158 (ficha del hotel), T-159 («Servicio de la agencia»), T-160 (paquete de la
  agencia), en `docs/backlog.md`.
