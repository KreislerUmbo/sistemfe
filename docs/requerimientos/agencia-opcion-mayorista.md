# Requerimiento — Opción de mayorista: alta corta, botones por tema y condiciones propias

| | |
|---|---|
| **Fecha** | 2026-11-25 (parte del vuelo y del alta: 2026-11-04) |
| **Módulo** | AGE |
| **Pedido por** | usuario |
| **Prioridad** | sin definir |
| **Relacionado** | `docs/aprendizaje/57-recorrido-mayorista-claro-solo-adultos.md` |

> El estado está solo en `docs/backlog.md` (T-155 y T-156).

## 1. Lo que se necesita

Ordenar cómo se cargan los datos desde que se elige el mayorista y su moneda: vuelos, hoteles,
opcionales, tours, lo que incluye y no incluye, y los términos y condiciones. **Las condiciones
cambian según el mayorista.**

## 2. Punto de partida (25-nov-2026)

- `OpcionMayoristaForm.vue` pide en una sola columna: proveedor, moneda, aerolínea, detalle del
  vuelo, descripción para el cliente, contenido reutilizable, incluye y no incluye.
- `cotizador/OpcionMayoristaTarjeta.vue` tiene los botones Hoteles, Opcionales y Tours.
- Solo hay condiciones generales de la agencia
  (`configuracion_agencia.condiciones_generales_servicio`), que salen en un PDF aparte.

## 3. Alta y tarjeta (T-155)

**Alta:** solo proveedor y moneda (con la ayuda «Los precios de hoteles se cargan en esta
moneda»). Todo lo demás se completa en las pestañas; incluye y no incluye, con «Usar texto
guardado» en lugar del buscador suelto de contenido reutilizable.

**Nombre del paquete para el cliente** (`descripcion_publica`, decidido 25-nov): es el nombre del
ítem del paquete en el lienzo, el PDF y la línea de la factura (`resolverNombreItem()`), y el
cliente nunca ve la razón social. Se llena solo al crear la opción con destino + días/noches del
destino («Panamá 6D/5N»); si la alternativa tiene un nombre propio (no «Alternativa N»), se sugiere
ese. Se edita en Resumen. Vacío, se usa destino + días en lugar de «Paquete mayorista».
No se usa directamente el nombre de la alternativa porque: una alternativa puede tener un paquete
por destino; mezcla el paquete con servicios sueltos (la línea de la factura sería solo el
paquete); sus nombres suelen ser internos («Alternativa 1», «Opción económica»); y renombrarla
cambiaría el nombre de una cotización ya enviada.

**Tarjeta:** Hoteles (precio) · Vuelos · Incluye / No incluye · Tours · Opcionales · Condiciones.

- El vuelo (aerolínea y detalle) sale del alta y pasa al botón **Vuelos**.
- **Decidido (04-nov):** el vuelo del mayorista es solo informativo, sin precio ni costo. El precio
  y el costo del paquete se cargan únicamente en Hoteles. El botón debe dejar claro que no suma,
  para no confundirlo con el pasaje aéreo suelto del lienzo.
- Moneda visible en la tarjeta, indicador «completo / falta» por botón y aviso antes de «Elegir
  mayorista» si no hay precio de hotel.

Los destinos no cambian: se manejan con los chips del cotizador y cada opción pertenece a uno.

**Decidido (25-nov), sobre la maqueta `docs/maquetas/paquete-mayorista.html`:**

- Entrada propia «+ Paquete de mayorista» en la alternativa; deja de ser una pestaña de «Agregar
  servicio».
- Panel lateral sobre el cotizador, no una página aparte: el paquete siempre pertenece a una
  cotización y una alternativa. A la izquierda los mayoristas consultados (moneda, «desde», qué
  falta); a la derecha la opción con pestañas marcadas completa / falta. En la dirección de la
  página queda una marca para que al recargar se vuelva a abrir.
- Se arma en caliente: cada cambio se guarda al escribir y lo del mayorista elegido aparece en la
  cotización al instante, sin un paso de «usar en cotización».
  Dos resguardos: al cambiar de mayorista elegido, avisar en el panel cuánto cambia el total; si la
  cotización ya se envió, los cambios pasan por la revisión antes de reenviar (T-022). Una
  alternativa aceptada no admite cambios (T-030).
- **Datos internos de la opción** (no salen al cliente): n.º de cotización del mayorista, «precios
  válidos hasta» (en la lista y la comparación: «vence en N días» desde 3 días antes, «vencida»
  después; con `HoraPeru::hoyTexto()`) y el archivo original (PDF o captura del correo). Columnas
  nuevas en `opcion_mayorista`.
- **Más rápido:**
  - «Pegar tabla de precios»: se pega la tabla del correo o Excel (una fila por hotel: nombre,
    simple, doble, triple; separadas por tabulación, punto y coma o espacios), con vista previa;
    si el hotel ya está, se actualizan sus precios. Se elige si lo pegado es costo (se suma el
    fee) o venta.
  - «Fee para todos»: markup % sobre el costo o monto por adulto, aplicado a todas las ocupaciones
    con costo. El valor inicial sale del markup por defecto del mayorista cuando exista (P5 de
    `agencia-precio-y-margen.md`).
- **Aspecto:** íconos en las pestañas (los de Font Awesome que el panel ya carga: no agregan peso),
  progreso «N de 6» con barra en cada tarjeta de la lista (verde completa, ámbar si falta algo,
  rojo si un texto menciona al mayorista) y estados vacíos con ícono.
- **Descartar o eliminar una opción:** descartar es para la que se comparó y no conviene: queda
  guardada, se puede reactivar y en la lista se agrupa en «Descartados (N)», plegado. Eliminar es
  para un error (cargada por equivocación o duplicada): borra la opción con sus hoteles, tours y
  opcionales, como el tacho de hoy, con confirmación en la misma barra que dice qué se borra y,
  si era la elegida, que el paquete sale de la cotización. Ninguna de las dos en una alternativa
  aceptada (T-030).
- «Comparar lado a lado» y «Vista del cliente» (el PDF sin el nombre del mayorista).
- **Hotel:** nombre (campo corto), categoría en estrellas, régimen, descripción para el cliente
  (texto largo), calificación de los viajeros opcional (valor, escala /10 o /5 y fuente, por
  ejemplo «8.6/10 Booking») y 3 fotos (1 de fachada y 2 de habitación, como hoy). Por ocupación:
  costo del mayorista, fee y venta; se escriben dos y el tercero se calcula, y se muestra
  «Markup X % · margen Y %» (P4 de `agencia-precio-y-margen.md`).
  Hoy `opciones_hotel` tiene `nombre_hotel`, `categoria_estrellas` y `fotos`; faltan la descripción
  y la calificación. El fee no es columna: es venta menos costo de `opciones_hotel_tarifas`.
- **Tour incluido:** día, título (campo corto), descripción (texto largo) y hasta 3 fotos. Hoy
  `opcion_mayorista_tours` tiene `nombre` y `descripcion`; las fotos solo llegan si el tour viene
  del catálogo (`paquete_plantilla_id`), así que hace falta guardarlas en el propio tour.
- **Opcional:** título (campo corto), precio por adulto, «sumar al total», descripción (texto largo)
  y hasta 3 fotos. Hoy `opcion_mayorista_opcionales` tiene `nombre`, `precio_por_persona`,
  `incluye` y `no_incluye`; la descripción y las fotos solo existen como copia del contenido
  reutilizable (`contenido_tour_descripcion_snapshot`, `contenido_tour_fotos_snapshot`). Decidir al
  armar la tarea si esas columnas pasan a ser las editables o se agregan columnas propias. En el
  PDF, los opcionales sin sumar salen con sus fotos, precio y descripción.
- **Reutilizar**, con un solo patrón en hoteles, tours y opcionales:
  - «Buscar en…» abre lo guardado, filtrado por el destino activo: hoteles de Proveedores y los
    cargados en otras cotizaciones; tours del catálogo (`paquetes_plantilla`) y contenido guardado
    (`contenido_tour`); opcionales del contenido guardado.
  - Al escribir el nombre aparecen coincidencias («buscar antes de crear», como hoy en
    `TourIncluidoForm`), para no duplicar.
  - Lo nuevo se guarda con «Registrar en Proveedores» (hotel, el `promover()` de hoy) o «Guardar
    para reutilizar» (tour, opcional).
  - Siempre se copia: nombre, descripción, fotos y, en hoteles, categoría y calificación. Editar
    la copia no cambia la ficha guardada ni un PDF ya enviado (criterio de la sesión 12e). Los
    precios del mayorista no se reutilizan: son de cada cotización.
  - «Copiar el paquete de otra cotización» en el alta: trae la opción de mayorista completa
    (hoteles, vuelo, incluye, tours, opcionales, condiciones) menos los precios. Distinto de
    «Duplicar alternativa», que ya existe y copia la alternativa dentro de la misma cotización.
  Hoy esto existe en tres formas distintas (promover hotel, buscar tour ya cargado y contenido
  reutilizable en el alta); se unifica.
- **PDF:** debajo de las fotos de cada hotel, «Nombre · Hotel N estrellas · Calificación de los
  viajeros: 8.6/10 (Booking)» y su descripción. Las estrellas van como texto: DomPDF no dibuja
  algunos símbolos (pasó con ✓). En el itinerario, cada tour con su descripción y sus fotos.
- En el lienzo, el paquete es un solo bloque con sus opciones de hotel y opcionales.

**Mejoras posteriores** (revisión del 25-nov, fuera de T-155): precio de niño en el paquete (ya en
«Por analizar», obs. 9); proponer la ocupación según los pasajeros (2 adultos + 1 niño → triple);
comparar en una sola moneda con el tipo de cambio del día; notas internas por opción; total del
grupo en la comparación; duplicar un hotel (otro régimen); ordenar hoteles arrastrando (orden del
PDF); Enter en el último campo crea la fila siguiente; pestañas fijas al desplazar; miniaturas con
`loading="lazy"` y datos del panel pedidos al abrirlo.

**Decidido (25-nov), aprobado sobre la maqueta:**

1. Casilla «Ofrecer» por hotel para elegir cuáles ve el cliente.
2. Se cargan hoteles sin elegir antes el mayorista; elegir sin precios avisa con «Elegir igual».
3. El hotel que elige el cliente se marca en el lienzo.
4. Opcionales con precio por adulto, como el paquete.
5. «Copiar el paquete de otra cotización» no copia los precios.

## 4. Condiciones del paquete (T-156)

**Decidido (25-nov):**

1. Cada opción de mayorista tiene su campo «Condiciones del paquete» (texto con formato,
   opcional), con su botón en la tarjeta. Se escribe o pega en cada cotización. No hay, por ahora,
   texto por defecto en la ficha del proveedor.
2. Salen **dentro del PDF de cotización**, en una sección «Condiciones del paquete», solo las de la
   opción elegida; con varios destinos, una por destino.
3. El PDF de condiciones generales de la agencia se mantiene. Una frase fija dice que, para el
   paquete, prevalecen sus condiciones propias.
4. Confidencialidad: al guardar, avisar si el texto contiene la razón social o el nombre comercial
   del mayorista. El aviso no detecta todo (teléfonos, correos, logos): hay que revisar el texto.
5. En la reserva se conservan las condiciones que vio el cliente. «Reasignar mayorista» avisa que
   las del nuevo pueden ser distintas y no reemplaza en silencio las aceptadas.

**Fuera de alcance:** las condiciones de pago al mayorista (T-142), que el cliente no ve.

**Para revisar al armar la tarea:**

- Una alternativa aceptada no admite cambios en la opción (T-030): el campo nuevo debe quedar
  incluido en ese bloqueo.
- `AlternativaController::duplicar()` debe copiar el campo.
- Si el texto pegado se imprime en el PDF, sanear el HTML como en los demás campos con formato.
- Definir dónde quedan en la reserva las condiciones aceptadas cuando se reasigna el mayorista.
