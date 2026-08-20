# Brief para Claude Code — Auditoría y fix: resolución de tarifa por temporada al cargar una cotización desde `paquete_plantilla`

> Pégale este archivo completo a una sesión nueva de Claude Code sobre el
> repo real del vertical Agencia de Viajes. Referencias exactas a leer
> primero: `docs/planning/agencia-de-viajes/plan-modulo-cotizaciones-reservas.md`
> §3.7 (párrafo "Requisito explícito para 11b3", agregado 17-ago-2026) y
> §2.2/§2.5 (modelo de temporada y `PriceEngineService`), más
> `docs/planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md`, filas
> **11b3, 11h, 11k, 11m, 11o** (son las que tocaron el flujo de "cargar
> desde plantilla" en distintos momentos).

## Por qué este brief existe (léelo, cambia lo que hay que hacer)

Este brief nació de una sesión de análisis crítico del modelo de datos
(17-ago-2026). En esa sesión se detectó, leyendo solo el documento de
planificación, que `paquete_plantilla_items`/`items_incluidos` fija cada
ítem a un `proveedor_tarifa_id`/`guia_tarifa_id` **específico** ("precio
ya fijado" — la plantilla no tiene fecha propia, es una ficha de
catálogo). El riesgo: si al cargar esa plantilla en una cotización real
el sistema copia esa FK literal en vez de volver a resolver la tarifa
vigente por temporada según la fecha real del viaje, cualquier tour
cargado desde plantilla para Fiestas Patrias o temporada alta saldría
cotizado al precio de temporada regular — error silencioso, se descubre
recién en la liquidación.

**En ese momento se pensó que esto era prevenible porque 11b3 ("cargar
desde plantilla") todavía no estaba construida.** Al revisar
`plan-hoja-de-ruta-ejecucion.md` se confirmó que **eso es incorrecto**:
11b3 está construida y mergeada desde el **01-ago-2026 (commit
`5d9d152`)**, y el mismo flujo siguió evolucionando en **diez sesiones
más** hasta el 12-ago-2026 (11h, 11i, 11j, 11k, 11l v2, 11m, 11n, 11o,
11q), incluyendo un cambio arquitectónico grande (commit `68414a1`: los
hoteles dejaron de vivir en `opciones_hotel`/`opciones_hotel_tarifas`
atados a un `paquete_plantilla` puntual, y pasaron a ser una
`proveedor_tarifa` más, buscable libremente en cualquier cotización).

**Conclusión: este brief es una auditoría de código que ya está en
producción/main, no una prevención sobre algo por construirse.** No
asumas que hay un bug — solo hay una sospecha fundada en la lectura del
documento de diseño, sin haber leído todavía el código real. Puede que ya
esté bien resuelto (ver más abajo por qué hay una pista a favor de eso).

## Qué está confirmado (por el documento) vs. qué hay que confirmar (por el código)

**Confirmado por el documento, no requiere que lo repitas:**
- `paquete_plantilla_items`/`items_incluidos` fija `proveedor_tarifa_id` o
  `guia_tarifa_id` específico, sin fecha propia.
- El modelo de temporada existe y es real: `proveedor_tarifas.temporada_id`
  (nullable, null = tarifa regular todo el año) contra un catálogo
  `temporadas`/`temporada_ocurrencias`, con versionado propio
  (`vigente_desde`/`vigente_hasta`) independiente del versionado por
  temporada.
- Existe una resolución por temporada ya probada para ítems armados desde
  cero en el cotizador (mencionada en el historial del proyecto como
  `AlternativaItemController::crearItemProveedor()` — **confirma el
  nombre real en el código, puede haber cambiado con el rediseño 11h**).
- La fila 11b3 de la hoja de ruta describe la carga desde plantilla como
  resuelta "en vivo igual que un ítem suelto" (mismo flujo que
  `clicBibliotecaItem()`, en bucle) — **esto es una pista a favor de que
  ya podría estar bien**, pero la descripción de la fila solo dice
  explícitamente que resuelve *precio/cantidad/modo_precio* en vivo, no
  confirma que también vuelva a cruzar la fecha contra
  `temporada_ocurrencias`. No lo des por sentado en ningún sentido.
- `guia_tarifas` (según el documento) NO tiene `temporada_id` en su
  modelo — solo `destino_id`/`modalidad`/`vigente_desde`/`vigente_hasta`.
  Si es así, el problema de temporada no aplica a ítems de guía — pero
  confírmalo en el schema real antes de excluirlos del análisis.

**Sin confirmar — esto es lo que tienes que averiguar leyendo código real:**
1. ¿Cuál es el método real detrás del botón "Cargar desde plantilla" hoy?
   (Buscar `desdePlantilla()` — nombrado explícitamente en las filas 11k y
   11m de la hoja de ruta — y confirmar en qué controller vive y si
   sigue llamándose así después de los rediseños 11h/11l v2.)
2. Leyendo ese método completo y todo lo que llama en cadena: cuando
   resuelve el `proveedor_tarifa_id` real de un ítem que viene de
   `paquete_plantilla_items`, ¿vuelve a consultar `proveedor_tarifas`
   filtrando por el `proveedor_servicio_id` correspondiente y la
   `temporada_ocurrencia` que matchea la fecha real de viaje de la
   cotización? ¿O usa directamente la fila que ya tenía fija el ítem de
   la plantilla?
3. ¿Ese método reutiliza la misma función/lógica que
   `crearItemProveedor()` (o su nombre actual), o duplica el cálculo por
   su cuenta? (Si ya la reutiliza, probablemente el problema no existe —
   pero confírmalo leyendo la llamada real, no por el nombre parecido.)
4. Revisa `ComboExplosionService::explotarUnTour()` (mencionado en la fila
   11o) por separado — es el camino que resuelve los ítems cuando se
   carga un `paquete_combo`, distinto al de un `tour_simple`. Puede tener
   el mismo problema, el problema inverso, o ninguno — no asumas que
   comparte código con el punto 2 sin confirmarlo.
5. ¿Qué pasa hoy si `cotizaciones.fecha_viaje_desde` es `null` en el
   momento de cargar la plantilla? (El campo es nullable desde el
   retrofit del 28/29-jul.) ¿Usa la tarifa regular sin avisar, bloquea la
   carga, o hace otra cosa?
6. Estado real de `opciones_hotel_tarifas` después del cambio
   arquitectónico `68414a1`: ¿algún `paquete_plantilla` todavía tiene
   ítems de hotel armados con el modelo viejo (atado a
   `paquete_plantilla_id`), o ya todo pasa por `proveedor_tarifas` normal
   con `tipo_habitacion`? Si queda algo del modelo viejo, decide si
   también le aplica este mismo problema de temporada.

## Paso 1 — Solo investigar, no toques código todavía

Ejecuta los 6 puntos de arriba con lectura de código real (grep +
lectura completa de los métodos relevantes, no solo el nombre). Para
cada uno, anota archivo y línea concreta — mismo estándar que el resto
de los briefs de este proyecto ("confirmado por grep, no asumido").

## Paso 2 — Repórtame antes de corregir nada

Antes de cambiar una sola línea, resume en el chat qué encontraste para
cada uno de los 6 puntos. Dime explícitamente si el problema existe, si
ya estaba resuelto, o si es distinto a como lo planteo acá (es una
auditoría — el objetivo es la verdad del código, no confirmar mi
sospecha a la fuerza). Yo confirmo contigo antes de que sigas al paso 3.

## Paso 3 — Si se confirma el gap, qué corregir

- Para cada ítem que venga de un `paquete_plantilla_item` con
  `proveedor_tarifa_id`, resolver la tarifa vigente del **mismo
  `proveedor_servicio`** (mismo proveedor, mismo destino_servicio — NO
  cambiar de proveedor) para la fecha real del viaje de la cotización,
  cruzando contra `temporada_ocurrencias` — misma lógica que ya usa el
  camino de ítems sueltos.
- Reutiliza la función/lógica que ya existe para esto (principio de
  motor único que ya sigue el proyecto — `PriceEngineService`, ver §2.5
  del plan). No dupliques la consulta de resolución de temporada en un
  método aparte.
- Aplica el fix tanto al camino de `tour_simple` como al de
  `paquete_combo` (`ComboExplosionService`) — son caminos de código
  distintos, confirmado en el paso 1.
- Decide explícitamente qué pasa si `fecha_viaje_desde` es `null` al
  cargar la plantilla — **recomendación: usar la tarifa regular
  (`temporada_id` null) y marcar el ítem cargado con un aviso visible
  ("sin fecha de viaje aún, se usó tarifa regular — revisar al confirmar
  fecha")**, nunca fallar en silencio ni bloquear la carga completa de
  la plantilla por esto. Si prefieres otro comportamiento, dilo antes de
  implementarlo — es una decisión de producto, no solo técnica.
- `guia_tarifas` queda fuera del fix si confirmas en el paso 1 que
  efectivamente no tiene `temporada_id` en el schema real.

## Verificación esperada (mismo estándar que el resto del proyecto — test real, no solo lectura)

- Escribe o extiende un test tipo `PaqueteComboTest.php`: crea un
  `proveedor_servicio` con dos `proveedor_tarifas` (una regular, una de
  temporada alta con `temporada_id` real y su `temporada_ocurrencia`
  vigente en un rango de fechas concreto), un `paquete_plantilla_item`
  apuntando a la tarifa regular, carga ese paquete en una `alternativa`
  cuya cotización tiene `fecha_viaje_desde` dentro del rango de
  temporada alta, y confirma que el `alternativa_item` resultante quedó
  con el precio/costo de temporada alta, no el regular.
- Repite el mismo caso para un `paquete_combo` que incluya ese mismo
  `tour_simple`.
- Agrega un caso con `fecha_viaje_desde = null`, confirmando el
  comportamiento explícito que decidiste en el paso 3.
- Si el problema NO existía (ítem 3 del paso 1 confirmó que ya reutiliza
  la lógica correcta), igual deja un test que lo demuestre — hoy no hay
  ningún test que cubra este caso específico, y sin uno, una futura
  sesión podría romperlo sin que nadie lo note.

## Nota final

La hoja de ruta describe varios rediseños del cotizador entre el 01-ago
y el 12-ago (drawer de biblioteca, `hoteles_disponibles`, itinerario
editable, precio por pasajero compartido). Si al leer el código real
algo de lo que describe este brief ya cambió de nombre o de estructura
desde entonces, confía en el código real por sobre esta descripción, y
avísame la discrepancia cuando la reportes en el Paso 2.
