# Requerimiento — El vuelo después de la venta: boleto, check-in y tarjeta de embarque

| | |
|---|---|
| **Fecha** | 2026-11-26 |
| **Módulo** | AGE |
| **Pedido por** | usuario |
| **Relacionado** | `agencia-servicio-agencia.md` (decisión 5: el pasaje aéreo sigue fuera del panel) |

> El estado está solo en `docs/backlog.md`.

## 1. Lo que se necesita

Saber dónde se trabaja el vuelo una vez vendido: adjuntar la **tarjeta de embarque** (boarding
pass) y llevar el **check-in de la aerolínea**. Respuestas del usuario (26-nov):

- **La agencia hace el check-in** por el cliente.
- Aplica a **todos los vuelos**: el pasaje aéreo que vende la agencia, el vuelo del paquete de
  mayorista y el que el pasajero compró por su cuenta.
- La tarjeta se envía al cliente **por WhatsApp** y, más adelante, **por correo**.

## 2. Análisis

### Dónde va

El vuelo pasa por tres momentos:

| Momento | Qué se maneja | Dónde |
|---|---|---|
| Cotizar | Aerolínea, itinerario, tarifa, cargos, fee | Cotizador, botón propio (como hoy) |
| Emitir | Localizador, n.º de boleto por pasajero, vuelo confirmado | Reserva |
| Viajar | Check-in de la aerolínea, asiento, tarjeta de embarque, envío al cliente | Reserva + lista «Check-ins por hacer» |

La tarjeta de embarque **no va en el cotizador**: existe solo después de emitir y de hacer el
check-in, es por pasajero y por tramo (ida y vuelta son dos por persona) y muchas cotizaciones no
se venden. Va en la reserva, junto al vuelo de cada pasajero.

### Lo que hay hoy en el código (leído el 26-nov-2026)

- **Pasaje aéreo de la agencia:** en la cotización, `cotizacion_pasaje_aereo` (aerolínea en texto,
  `itinerario` en texto, tarifa, cargos, fee). En la reserva, `reserva_item_vuelo_pasajero`: por
  ítem y pasajero, `vuelo_numero_ida/vuelta`, `vuelo_fecha_*`, `vuelo_hora_*` y
  `vuelo_aerolinea_confirmada` (se edita en `reservas/detalle.vue`, bloque «Vuelo(s) vendido(s)
  por la agencia», `ReservaItemPasajeroController::actualizarVuelo()`). Solo ida y vuelta: un vuelo
  con escala (dos tramos por sentido) no entra. **No hay localizador, boleto, asiento ni adjuntos.**
- **Vuelo del paquete de mayorista:** `opcion_mayorista.vuelo_aerolinea` y `vuelo_detalle` (texto,
  informativo, sin precio). En la reserva no hay datos de vuelo por pasajero.
- **Vuelo propio del pasajero:** `reserva_pasajeros.vuelo_propio`, un texto libre desde T-077, que
  sale como observación en el reporte operativo.
- **Ojo con el nombre:** `reserva_item_checkins` (T-041) es el check-in del **reporte operativo**
  (el pasajero llegó al servicio). No tiene relación con el de la aerolínea. Lo nuevo se llama
  «check-in del vuelo» en pantallas, tablas y código.
- **Aerolíneas:** catálogo central `aerolineas` (nombre, `codigo_iata`), T-015. No guarda cuándo
  abre el check-in.
- **Archivos privados:** `pasajero_documentos.archivo` (DNI o pasaporte) ya se guarda en el disco
  `private`. Mismo patrón para la tarjeta.
- **Envío por WhatsApp:** `EnviarAlternativa.vue` descarga el PDF y abre `wa.me` con un texto
  armado (`enlaceWhatsappConTexto()`); `wa.me` no adjunta archivos, el vendedor arrastra el
  descargado. Mismo patrón para la tarjeta.
- **Correo:** el envío por Brevo todavía no está configurado para la agencia en producción (falta la
  clave de la API y el DNS del dominio); va en una segunda etapa.

### Propuesta

**Un solo modelo de vuelo en la reserva, por tramos**, para los tres orígenes:

- **Tramo** (`reserva_vuelo_tramos`): reserva, origen (`agencia` | `mayorista` | `propio`), ítem
  de la reserva cuando lo hay (pasaje aéreo o paquete), aerolínea (del catálogo), n.º de vuelo,
  aeropuerto de salida y de llegada, **fecha y hora de salida en hora local del aeropuerto** (no es
  un instante UTC: es lo que dice el boleto; mismo criterio que la excepción de
  `credito_pagos.fecha_pago`) y localizador.
- **Pasajero en el tramo** (`reserva_vuelo_tramo_pasajeros`): n.º de boleto, asiento (opcional),
  **check-in hecho** (instante UTC y usuario), **tarjeta de embarque** (archivo privado) y
  **enviada al cliente** (instante, usuario y medio: WhatsApp o correo).
- Un archivo de tarjeta puede valer para **varios pasajeros** del mismo tramo: muchas aerolíneas
  entregan un solo PDF para todo el grupo.
- `reserva_item_vuelo_pasajero` pasa a tramos (ida → un tramo, vuelta → otro) sin perder datos; el
  bloque de `reservas/detalle.vue` se reemplaza por el nuevo. `vuelo_propio` queda como nota; los
  tramos del vuelo propio se cargan aparte.

**Cuándo abre el check-in:** campo «horas antes» por aerolínea (en el catálogo, con un valor por
defecto de la agencia, por ejemplo 48 h). Con la salida del tramo se calcula «abre el…».

**Lista «Check-ins por hacer»** (menú Operación): tramos que salen en los próximos días, con
pasajeros, aerolínea, localizador, «abre el…» y estado por pasajero:

`aún no abre` → `abierto` → `hecho` → `tarjeta adjunta` → `enviada`

Desde la lista: marcar hecho, adjuntar la tarjeta, enviar por WhatsApp. Un contador en el menú con
los que ya abrieron y no están hechos; sin notificaciones emergentes (preferencia del usuario:
pocas alertas).

**Enviar al cliente:** por WhatsApp, como `EnviarAlternativa.vue` (descarga las tarjetas y abre
`wa.me` con «Hola, te envío tus tarjetas de embarque del vuelo LA2203 del 12-dic…»), al teléfono
del cliente o del pasajero; queda registrado quién y cuándo. Por correo, cuando esté Brevo: las
tarjetas como adjuntos.

### Casos

| # | Situación | Qué debe pasar |
|---|---|---|
| 1 | Pasaje de la agencia Tarapoto – Lima – Cusco (escala) | Dos tramos de ida, cada uno con su check-in y su tarjeta |
| 2 | Paquete de mayorista con vuelo | El vendedor carga los tramos con los datos que manda el mayorista; aerolínea propuesta desde el paquete |
| 3 | Pasajero con vuelo propio | Se cargan sus tramos (origen `propio`); entra a la lista igual |
| 4 | Una tarjeta en PDF para 3 pasajeros | Se adjunta una vez y se marca para los 3 |
| 5 | Vuelo que sale mañana, check-in no hecho | Aparece en la lista como «abierto» y suma al contador |
| 6 | Reprogramación de la reserva (`reservas/{id}/reprogramar`) | Los tramos no se mueven solos: aviso para revisarlos |
| 7 | Quitar un pasajero (`QuitarPasajeroReservaService`) | Sus filas de tramo se quitan; la tarjeta compartida queda para los demás |
| 8 | Reserva cancelada | Sus tramos salen de la lista |
| 9 | Enviar por WhatsApp | Descarga, abre `wa.me` con el texto y registra el envío |

### Reglas afectadas

- Nueva: el vuelo de la reserva se guarda por tramos, para los tres orígenes.
- Nueva: «check-in del vuelo» ≠ check-in del reporte operativo (`reserva_item_checkins`).
- Fechas: la salida del tramo es fecha-hora local del aeropuerto; el check-in hecho y el envío son
  instantes UTC. «Hoy» y «mañana» de la lista, con `HoraPeru::hoyTexto()`.
- Archivos: disco `private`, se descargan por una ruta con permiso (`ReservaPolicy`); nunca por
  `public/storage` ni con URL armada a mano.

### Decidido (26-nov), con las recomendaciones

1. **Datos del mayorista:** localizador y boletos son
   campos opcionales; con solo aerolínea, n.º de vuelo y horario ya entra a la lista.
2. **Horas antes del check-in:** valor de la agencia (48 h) que cada aerolínea puede cambiar.
3. **Quién ve la lista:** un permiso propio («Check-ins del vuelo»), dado al rol de
   operaciones y al Propietario.
4. **Leer el vuelo pegado** (en «Por analizar»): cargar los tramos pegando el texto del boleto
   ahorraría tipeo aquí también: después; esta tarea sale con carga manual.
5. **Recordatorio al cliente** («tu check-in abre mañana»): no por ahora, porque la agencia hace
   el check-in.

### Riesgos

- **Datos existentes:** pasar `reserva_item_vuelo_pasajero` a tramos; revisar antes en producción
  cuántas filas tienen datos.
- **Privacidad:** la tarjeta lleva nombre, localizador y código de barras: archivo privado, ruta
  con permiso, sin enlaces públicos.
- **Fechas:** confundir la hora local del aeropuerto con un instante UTC movería el «abre el…».
- **Operación:** la lista depende de que los tramos se carguen; sin «leer vuelo pegado», cargarlos
  es tipeo.

## 3. Resultado

- **Decisiones:** §2, «Decidido (26-nov)».
- **Tareas:** T-161 en `docs/backlog.md` (envío por correo en una tarea posterior, cuando esté Brevo).
