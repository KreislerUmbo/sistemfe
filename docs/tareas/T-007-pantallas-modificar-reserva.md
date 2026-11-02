# T-007 — Pantallas de "Modificar reserva"

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-005, T-006, T-072, T-073 |
| **Reglas** | R-AGE-01 a R-AGE-10 |
| **Decisiones** | D-AGE-030 a D-AGE-033 |
| **Rama** | `feat/T-007-pantallas-modificar-reserva` |

## Contexto
Conecta todo el backend (T-002 a T-006) en las pantallas. El vendedor trabaja en el cotizador
de siempre, con un panel extra que le dice qué cambia, qué falta resolver y lo lleva a cada
lugar donde se corrige.

## Alcance

**`reservas/detalle.vue`:**
- Botón "Modificar reserva": se ve solo con `reservas.modificar` y la reserva activa. Crea la
  modificación (o abre la que ya está abierta) y navega al cotizador.
- Si hay una abierta: un aviso con "Continuar modificación" y "Descartar".
- El ícono de "quitar pasajero" (bloqueado desde la etapa 1) explica que se hace desde
  "Modificar reserva" y lleva ahí.
- Historial: modificaciones aplicadas, con quién y cuándo, y el enlace a su PDF.

**`cotizador/editar.vue` con una cotización de modificación:**
- Cabecera clara: "Modificación de la reserva <código>", con el enlace de vuelta a la
  reserva. Sin las acciones que no aplican (aceptar, enviar como cotización, duplicar).
- Aviso si la respuesta trae `pasajeros_edad_estimada`.
- **Panel "Diferencia"** (de `GET …/diferencia`, refrescado al guardar cualquier cambio):
  - líneas y pasajeros con su estado, totales y la lista de bloqueos;
  - cada bloqueo **lleva al lugar donde se corrige**: resaltar el ítem en el lienzo
    (`para_revisar` + botón "Revisado"), abrir el comprobante (`requiere_nota_credito`), o
    abrir el formulario de penalidad (`falta_penalidad`).
- Formulario de penalidad por pasajero quitado: monto, motivo y tratamiento de IGV (select
  sin valor preseleccionado).
- Botones "PDF para el cliente", "Descartar" y "Aplicar a la reserva". Aplicar está
  deshabilitado mientras haya bloqueos y manda la `huella` que se mostró. Si responde 422
  porque la reserva cambió, recargar la diferencia y avisarlo.
- Al aplicar: volver a la reserva con un mensaje de éxito y la alerta de cupo, si viene.

**Estilo:** el de Agencia y Ventas (inputs `-sm`, cards con `card-header`; memoria
`feedback_estilo_ui_como_agencia_y_ventas`). Llamadas con `httpClient`. Llamadas
independientes en paralelo (`Promise.all`), no en cadena.

**Nota de la revisión de T-003 (29-oct):** un hotel o habitación (`tarifa_fija`) queda
`para_revisar` apenas cambia la cantidad de pasajeros, así que una modificación con varias
habitaciones genera varias confirmaciones. El panel debería permitir confirmarlas en bloque
(hoy la ruta `POST …/items/{itemId}/revisado` es de a uno: o se llama en paralelo o se agrega
una ruta para varios). Las líneas con `sin_pasajeros = true` no se confirman: llevan al ítem.

**Nota de la revisión de T-004 (30-oct): penalidades.** Rutas `POST/PUT/DELETE
reservas/{id}/modificacion/penalidades[/{itemId}]` (`{ reserva_pasajero_id, monto, motivo,
tip_afe_igv, destino_tributario }`; el pasajero solo en POST). Cada pasajero de la diferencia
trae `falta_penalidad`, `penalidad_alternativa_item_id` y `penalidad_sobrante_item_id`; el
bloqueo `penalidad_sobrante` trae `alternativa_item_id` para llevar a borrarla. IGV y destino
sin valor por defecto: el select empieza vacío. El motivo se guarda dentro de
`descripcion_manual` (`Penalidad por cancelación — <pasajero>: <motivo>`); al editar, mostrar
el motivo aparte (guardarlo en el formulario o recortar el prefijo) y como texto, nunca
`v-html`. Hoy el pasajero quitado y vuelto a agregar queda como uno nuevo: si se quiere que
"volver" recupere al original (y su penalidad sobre), decidirlo aquí.

**Nota de la revisión de T-005 (30-oct): aplicar.** `POST reservas/{id}/modificacion/aplicar
{ huella }` (la `huella` es la de la última diferencia mostrada). Respuestas a manejar: 422
"la reserva cambió" (volver a pedir la diferencia), 422 con bloqueos, 422 "cambia el mayorista
elegido" (no se puede aplicar desde aquí) y `alerta_cupo_excedido = true` en el 200. La
penalidad y el "Ajuste" quedan en la reserva **sin pasajeros asignados**: al facturar no
entran solos, el vendedor los suma a la factura que quiera; la pantalla de facturar debe
dejarlo claro. Requiere T-072 hecha (la cotización original deja de verse "reservada").

**Nota de la revisión de T-006 (30-oct): totales.** De `totales` de la diferencia, mostrar
solo `vendido`, `nuevo` y `diferencia`. `ya_facturado` (un tour facturado a medias cuenta
entero) y `anticipos_aplicados` (no resta devoluciones) son aproximados: no mostrarlos como
cifras al vendedor, ni calcular un saldo en el navegador. El saldo real está en "Por analizar".
El PDF es `GET reservas/{id}/modificacion/pdf[?modificacion_id=]` (descarga; para una aplicada,
el historial pasa `modificacion_id`).

**Nota de la revisión de T-073 (30-oct): "para revisar" por pasajeros.** Ahora también
quedan `para_revisar` el pasaje aéreo, los servicios manuales y de mayorista "por persona" y el
tour con precio a mano cuando cambian sus pasajeros, y cualquier servicio que dependa de ellos
cuando cambia el **tipo** (un adulto por un niño) aunque la cantidad sea la misma. La línea trae
`composicion_vendida` / `composicion_nueva` (`{"adulto": 2, "nino": 1}`): mostrarla ("2 adultos
y 1 niño → 3 adultos"), porque con la misma cantidad el vendedor no ve por qué se le pide
revisar. Habrá más confirmaciones por modificación: refuerza la confirmación en bloque.

**Nota de la revisión de T-072 (30-oct): el ajuste en el cotizador.** El backend ya no suma
el «Ajuste — …» (`ajuste_de_item_id` no nulo) en el total de una alternativa, pero
`totalEfectivoLocal()` de `cotizador/editar.vue` (~3727) suma en el navegador todos los ítems:
abrir la cotización `…-M1` de una reserva con ajuste mostraría la diferencia dos veces.
Excluirlo ahí (mismo criterio que `AlternativaItem::calcularTotalEfectivo()`) y no listarlo
en el lienzo como un servicio más (o mostrarlo aparte, sin sumar). Revisar otras sumas de
`total_convertido` en esa pantalla.

## Fuera de alcance
- Cambios en el backend, salvo un ajuste chico de forma de respuesta. Si hace falta más,
  anotarlo en "Hallazgos".
- "Agregar pasajero rápido" en la reserva para tours (viene después de probar esto).

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `admin-start-kit/src/components/agencia-viajes/ModificacionDiferenciaPanel.vue` (nuevo)
- `admin-start-kit/src/components/agencia-viajes/PenalidadPasajeroModal.vue` (nuevo)
- `admin-start-kit/src/composables/useModificacionReserva.ts` (nuevo)

## Criterios de aceptación
- [ ] **CA1** — Sin `reservas.modificar` el botón no se ve; con el permiso y la reserva
  cancelada, tampoco.
- [ ] **CA2** — Recorrido completo en `agencia-demo` (Playwright): crear la modificación,
  agregar un pasajero, ver la diferencia, descargar el PDF, aplicar y ver la reserva con el
  pasajero y el total nuevo.
- [ ] **CA3** — Recorrido con un pasajero quitado: el panel bloquea, el bloqueo abre el
  formulario de penalidad, y al cargarla se puede aplicar.
- [ ] **CA4** — Un `para_revisar` resalta el ítem en el lienzo; "Revisado" lo saca de los
  bloqueos.
- [ ] **CA5** — Abrir la misma modificación en dos pestañas, aplicar en una e intentar en la
  otra: la segunda avisa que la reserva cambió.
- [ ] **CA6** — Descartar vuelve a la reserva sin cambios.
- [ ] **CA7** — `npm run type-check` y `npm run build` sin errores; Vitest en verde si se agregó
  lógica en el composable.
- [ ] **CA8** — Los datos de prueba de `agencia-demo` quedan revertidos al terminar.

## Seguridad y correctitud
- [ ] Permisos: el botón se oculta sin permiso, pero la protección real es del backend (ya
  probada en T-002 a T-006)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: el formulario valida, y el backend también
- [ ] Concurrencia: se manda la `huella`; se maneja el 422 de "cambió"
- [ ] Dinero: se muestra lo que da el backend, sin recalcular en el navegador
- [ ] Fechas: `formatFechaHora()` para los instantes del historial
- [ ] Tributario: el IGV de la penalidad, sin valor preseleccionado
- [ ] `v-html`: no se usa para el motivo ni para los nombres
- [ ] Migraciones: no aplica

## Cómo verificar
```
cd admin-start-kit && npm run type-check && npm run build
```
Y el recorrido con Playwright contra `agencia-demo` (memoria
`reference_playwright_browser_testing_windows`; login en
`reference_agencia_demo_admin_login`).

---

## Resultado
- **Commits:** rama `feat/T-007-pantallas-modificar-reserva` (ver `git log`).
- **Tests:** `npm run build` sin errores; Vitest del composable (4 en verde: composición, monto, motivo); `vue-tsc` sin errores en los archivos tocados. `npm run type-check` (`vue-tsc --build --force`) ya falla en 19 archivos que esta tarea no toca (portal, recursos, users, sale…). Backend: ReservaModificacionCrearTest, EscopablePorVendedorTest y ReservaAnticipoTest en verde (33). En la revisión se agregaron 3 tests de la forma de respuesta (`test_t007_*` en ReservaModificacionCrearTest y ReservaModificacionAplicarTest): `modificacion_abierta`/`modificaciones_aplicadas` solo con `reservas.modificar` (lado backend de CA1), historial con quién y diferencia, y `modificacion` en el show de la cotización.
  Playwright contra agencia-demo (reserva 29): CA2 (crear, agregar pasajero, composición «1 adulto → 2 adultos», PDF, aplicar, historial), CA3 (quitar pasajero → bloqueo → penalidad con selects vacíos → aplicar; un motivo con HTML se ve como texto; editar precarga), CA4 (resalta el ítem; «Revisado» / «Confirmar todas» saca el bloqueo), CA5 (segunda pestaña: «La reserva cambió», recarga y queda de solo lectura), CA6 (descartar). CA8: respaldo con pg_dump, restaurado y conteos iguales. CA1 solo por código (`puedeModificar` = reserva activa + `reservas.modificar`): la demo no tiene reserva cancelada ni un usuario sin permiso. En la revisión (30-oct) el usuario aceptó CA1 así: test del backend (`test_t007_el_detalle_de_la_reserva_trae_la_abierta_solo_con_reservas_modificar`) + revisión del código; el backend además rechaza crear sin permiso o sobre una reserva cancelada (tests de T-002). El usuario la prueba a mano.
- **Desviaciones de la tarea y por qué:**
  - Ajuste de backend (forma de respuesta): `GET reservas/{id}` trae `modificacion_abierta` y `modificaciones_aplicadas` (solo con `reservas.modificar`), y `GET cotizaciones/{id}` trae `modificacion` (reserva y estado) si la cotización es de modificación. Sin eso el cotizador no sabía a qué reserva volver y la reserva no podía listar el historial.
  - Componentes en `components/AgenciaViajes/` (la carpeta que ya existe, no `agencia-viajes`); composable en `composables/agencia-viajes/`; se agregó `services/admin/reservaModificacionService.ts`.
  - Confirmar en bloque: llamadas en paralelo a la ruta de a uno y una sola recarga de la diferencia; no se agregó ruta.
  - El aviso de edad estimada y el mensaje de éxito/cupo viajan entre pantallas por `sessionStorage` (esa respuesta solo existe en el momento de crear/aplicar).
  - `components.d.ts` se regeneró solo con los dos componentes nuevos.
  - Un pasajero quitado y vuelto a agregar sigue siendo uno nuevo (no se decidió recuperar al original).
  - Sub-fase de la prueba del usuario (31-oct): el pasaje aéreo guardaba `pax_incluidos = null` («todos»), así que al agregar un adulto el boleto vendido lo absorbía (2 pasajeros, precio de 1) y el boleto nuevo se cobraba por todos. Ahora el pasaje aéreo guarda siempre su lista de pasajeros; al crear la modificación, la copia queda con los pasajeros que lo tenían en la reserva; el formulario marca por defecto solo a los nuevos. Test: `test_t007_pasaje_aereo_vendido_no_cubre_al_pasajero_agregado_y_su_boleto_se_cobra_aparte`.

## Hallazgos fuera de alcance
(Pasados a «Por analizar» de `docs/backlog.md` en la revisión, más uno de la revisión: `html:` sin escapar en `guardarPasajeros()`.)
- Tras aplicar una modificación con penalidad, la reserva queda con «Servicios: 1 pendiente(s)» y la penalidad sin pasajeros (R-AGE-05); la pantalla de facturar todavía no lo explica, como pedía la nota de T-005.
- Un pasajero sin nombre cargado sale como «pasajero #35» en el bloqueo y en el texto de la penalidad.
- El aviso «Pasajeros actualizados» del cotizador repite «(modificación)» en el nombre de la alternativa desde la segunda modificación.
