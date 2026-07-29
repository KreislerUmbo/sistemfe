# Hoja de Ruta de Ejecución — Vertical Agencia de Viajes en Claude Code

> Este documento NO diseña nada nuevo — es la traducción del árbol de
> dependencias (`plan-modulo-maestros-iniciales.md` §3) a **sesiones
> concretas de Claude Code**, con checklist de avance.
> Vive en el repositorio de código (`docs/planning/`), no solo en Drive —
> es el documento que Claude Code lee al empezar cada sesión.
> Última actualización: 25-jul-2026 — v0.1 (primera versión)

---

## 0. Cómo usar este documento

1. Antes de abrir un chat nuevo de Claude Code, revisa la tabla de la
   sección 1 — busca la primera fila con casilla `[ ]` sin marcar. Esa es
   tu próxima sesión.
2. Al terminar una sesión (con su commit hecho), marca la casilla `[x]`
   y anota la fecha/commit en la columna "Estado".
3. Nunca saltes una fila sin marcar la anterior — cada nivel depende del
   que tiene arriba (ver `plan-modulo-maestros-iniciales.md` §3 para el
   detalle de por qué).
4. Al abrir cada chat de Claude Code, dile la ruta exacta del documento y
   la sección — no le copies el documento completo. Ejemplo de primer
   mensaje de sesión:
   > "Vamos a construir la Sesión 3 de docs/planning/plan-hoja-de-ruta-ejecucion.md.
   > Lee esa fila, y de docs/planning/plan-modulo-tours-catalogo.md lee
   > solo la sección 4."

---

## 1. Las 15 sesiones, en orden estricto

| # | Sesión | Qué construir | Documento de referencia (sección exacta) | Estado |
|---|---|---|---|---|
| 0 | Infraestructura core/verticals | Separación `database/migrations/core/` vs `verticals/agencia-viajes/`, campo `giro` en `tenants`, `tenants:provision` | `arquitectura-multitenant-backend.md`, `plan-modulo-infraestructura-multitenant.md` | [x] 27-jul-2026 — `4cd3944` |
| 1 | Catálogos centrales | `proveedor_tipos`, `temporadas` (ambos con columna `giro`) | `plan-modulo-proveedores.md` §2.6 | [x] 27-jul-2026 — `7279ec8` |
| 2 | Catálogos por tenant, sin dependencias | `destinos_atractivos` (árbol 3 niveles), `servicios`, `configuracion_agencia`, `guias` | `plan-modulo-tours-catalogo.md` completo (es corto) | [x] 27-jul-2026 — `d33bc22` |
| 3 | Puente destino↔servicio + proveedores | `destino_servicio`, `proveedores`, `proveedor_tipos_config` | `plan-modulo-tours-catalogo.md` §4, `plan-modulo-proveedores.md` §2.6 | [x] 27-jul-2026 — `78296d2` |
| 4 | Proveedor × destino | `proveedor_servicios` | `plan-modulo-proveedores.md` §2.6 | [x] 27-jul-2026 — `732534c` |
| 5 | Tarifas (la parte más grande) | `proveedor_tarifas`, `guia_tarifas`, `opciones_hotel`/`opciones_hotel_tarifas` | `plan-modulo-proveedores.md` §2.6, `plan-modulo-cotizaciones-reservas.md` §2.2, §2.4, §5.3 | [x] 27-jul-2026 — `e571fb3` |
| 6 | Catálogo de tours vendibles | `paquetes_plantilla`, `tour_itinerario_items` | `plan-modulo-cotizaciones-reservas.md` §3.7, §5.1 | [x] 27-jul-2026 — `c8581b3` |
| 7 | Motor de cotización | `cotizaciones`, `cotizacion_pasajeros`, `alternativas`, `alternativa_items`, `opcion_mayorista`, `opcion_mayorista_opcionales`, `tipo_cambio_agencia` | `plan-modulo-cotizaciones-reservas.md` §3 completo | [x] 28-jul-2026 — `b1c5a70` |
| 8 | Reserva y todo lo que dispara | `reserva`, `reserva_ventas`, `reserva_pasajeros`, `reserva_items`, `reserva_item_pasajero`, `reserva_anticipos`, `cronograma_pago_proveedor`, `reglas_cancelacion` | `plan-modulo-cotizaciones-reservas.md` §4 completo | [x] 28-jul-2026 — `0a39f76` |
| 9 | Integración con el core de ventas | Cambios en `Sale`/`SaleDetail`/`Product`, `pago_proveedor`, `sale_detail_items`, `pasajeros_catalogo`/`pasajero_documentos` | `plan-modulo-cotizaciones-reservas.md` §6 completo | [x] 28-jul-2026 — `59278e7` |
| 10 | Reporte operativo + recordatorios (backend) | Vista de `reserva_items`, `tipos_recordatorio`/`recordatorios`/`recordatorio_snooze_config` | `plan-modulo-cotizaciones-reservas.md` §8, §8bis | [x] 28-jul-2026 — `28c76f7` |
| 11a | API REST + formularios maestros | Controllers/rutas para `proveedores`+tarifas, `destinos_atractivos`+`servicios`, `temporadas`, `guias`+tarifas, `configuracion_agencia`; pantallas Vue/Bootstrap 5 (`admin-start-kit`) para cada uno | `plan-modulo-cotizaciones-reservas.md` §2, §5.2, §5.3, §7 | [x] 28-jul-2026 — `21d61f3` |
| 11b | Frontend — Cotizador | Layout de 3 columnas (biblioteca / lienzo día-por-día / precio en vivo), modo Local-Nacional vs. Internacional (comparador de mayoristas), pestañas de alternativas, `PriceEngineService`, tabla `cotizacion_pasaje_aereo` | `plan-modulo-cotizaciones-reservas.md` §2.5, §3, §7.1 | [ ] diseño UX validado con prototipo HTML (28-jul-2026), pendiente de programar |
| 11c | Frontend — Aceptación de alternativa → Reserva y pasajeros | Pantallas de reserva, datos de pasajero, asignación pasajero↔servicio | `plan-modulo-cotizaciones-reservas.md` §4, §6.5, §7 | [ ] |
| 11d | Frontend — Reporte operativo + recordatorios (pantallas) | Vista del reporte por fecha con acciones inline (asignar guía, check-in), PDF, campana de recordatorios | `plan-modulo-cotizaciones-reservas.md` §8, §8bis, §7 | [ ] |

**Regla de oro:** una sesión = un chat de Claude Code = un commit (o varios
commits pequeños dentro de la misma rama, ver sección 2). No mezcles dos
filas en un mismo chat aunque parezcan rápidas — el costo de contexto
acumulado sale más caro que abrir un chat nuevo.

---

## 2. Vínculo con Git

Ver la guía completa de flujo de trabajo en la conversación donde se creó
este documento (o pídele a Claude que la repita) — resumen aplicado a
esta tabla:

- Una rama por sesión: `feature/sesion-0-infraestructura`,
  `feature/sesion-1-catalogos-centrales`, etc.
- Al terminar la sesión y verificar que corre, merge a `main` (o la rama
  base del proyecto) y **recién ahí** se marca `[x]` en la tabla de
  arriba.
- El mensaje de commit referencia la sesión y el documento fuente, ej.:
  `feat(sesion-1): catálogo central proveedor_tipos y temporadas (plan-modulo-proveedores.md §2.6)`

---

## 3. Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 25-jul-2026 | Primera versión: 11 sesiones desglosadas del árbol de dependencias de `plan-modulo-maestros-iniciales.md`, con checklist de avance y convención de commits. |
| 27-jul-2026 | Sesión 0 cerrada y verificada end-to-end contra dev real (commit final `4cd3944`, rama `feature/sesion-0-infraestructura` mergeada a `main`): 67 migraciones movidas a `tenant/core/`, carpeta `tenant/verticals/agencia-viajes/` creada, `giro`/`tipo`/`sunat_modo` agregados a `tenants` (central), `tenants:provision` actualizado. Ver `TODO.md` (raíz del repo) para 2 pendientes menores anotados durante la verificación. |
| 27-jul-2026 | Sesión 1 cerrada y verificada contra dev real (commit final `7279ec8`, rama `feature/sesion-1-catalogos-centrales` mergeada a `main`): catálogos centrales `proveedor_tipos`/`temporadas` (`plan-modulo-proveedores.md` §2.6), namespace nuevo `App\Models\AgenciaViajes`, seeders standalone con datos de ejemplo (`giro=agencia_viajes`). Ver `TODO.md` para el pendiente de automatizar cuándo/cómo corren estos seeders centrales. |
| 27-jul-2026 | Sesión 2 cerrada y verificada contra dev real (commit final `d33bc22`, rama `feature/sesion-2-catalogos-tenant` mergeada a `main`): primer contenido real de `tenant/verticals/agencia-viajes/` — `destinos_atractivos`, `servicios`, `guias`, `configuracion_agencia` (singleton con fila default). Incluye fix de un bug real de Sesión 0 (mapeo `giro`→carpeta), ver `plan-modulo-infraestructura-multitenant.md` §1.1 y su historial. |
| 27-jul-2026 | Sesión 3 cerrada y verificada contra dev real (commit final `78296d2`, rama `feature/sesion-3-puente-proveedores` mergeada a `main`): `destino_servicio` (FK reales a destinos_atractivos/servicios), `proveedores` (schema validado por el negocio), `proveedor_tipos_config` (vacía, sin sembrado automático a propósito). Con esto quedan construidas las 7 tablas del "bloque catálogos/proveedores" (Sesiones 2-3). Ver `TODO.md` — marcado como prioridad no diferible el gap encontrado en `eliminarSiVacio()` (no conoce estas 7 tablas, riesgo real de borrado de datos vía panel superadmin). |
| 27-jul-2026 | Sesión 4 cerrada y verificada contra dev real (commit final `732534c`, rama `feature/sesion-4-proveedor-servicios` mergeada a `main`): `proveedor_servicios`, tabla puente pura con 2 FK reales a `proveedores`/`destino_servicio`. Entre Sesión 3 y esta, se corrigió aparte (rama `fix/eliminar-si-vacio-agencia-viajes`, no es fila de esta tabla, ver `TODO.md`) el gap de `eliminarSiVacio()` — confirmado que sigue funcionando bien al limpiar el tenant de prueba de esta sesión. |
| 27-jul-2026 | Sesión 5 cerrada y verificada contra dev real (commit final `e571fb3`, rama `feature/sesion-5-tarifas` mergeada a `main`): `proveedor_tarifas`, `guia_tarifas`, `opciones_hotel`/`opciones_hotel_tarifas`, más `temporada_ocurrencias` (CENTRAL, gap real que no tenía fila propia en esta tabla — sumada acá por ser dependencia dura de `proveedor_tarifas.temporada_id`). `opciones_hotel.opcion_mayorista_id`/`paquete_plantilla_id` quedan sin FK real hasta que aterricen Sesiones 7/6. Recurrencia del gap de `eliminarSiVacio()` encontrada (esta vez sobre `opciones_hotel`) — ver `TODO.md`, se resuelve en su propia mini-sesión aparte. |
| 27-jul-2026 | Sesión 6 cerrada y verificada contra dev real (commit final `c8581b3`, rama `feature/sesion-6-tours-vendibles` mergeada a `main`): `paquetes_plantilla` (= "tour", confirmado misma entidad), `tour_itinerario_items` (`destino_atractivo_id` nullable para pasos sin atractivo específico), y `paquete_plantilla_items` (nombre de tabla decidido en esta sesión para el concepto `items_incluidos` del plan). Retrofit cierra la mitad `paquete_plantilla_id` de la FK diferida de `opciones_hotel` (Sesión 5) — la otra mitad, `opcion_mayorista_id`, sigue pendiente para Sesión 7. Reconstruido el ejemplo real completo "Full Day Alto Mayo" para verificar. |
| 28-jul-2026 | Sesión 7 cerrada y verificada contra tenants descartables reales (commit final `b1c5a70`, rama `feature/sesion-7-motor-cotizacion` mergeada a `main` — hecha en dos mitades, 7a y 7b, dentro de la misma rama, sin merge intermedio). **7a**: `tipo_cambio_agencia` (histórico, nunca sobrescrito), `cotizaciones` (correlativo por prefijo resuelto en el modelo vía `creating()`, no columna generada), `cotizacion_pasajeros` (edad obligatoria, fuente de verdad del precio), `alternativas`, `alternativa_items`. **7b**: `salidas_mayorista` (sin fila propia en la hoja de ruta original, agregada por dependencia dura, mismo criterio que `temporada_ocurrencias` en Sesión 5), `opcion_mayorista`, `opcion_mayorista_opcionales`, más el retrofit que cierra las 2 FK diferidas que venían acumulándose desde Sesión 5 (`opciones_hotel.opcion_mayorista_id`) y desde 7a (`alternativa_items.opcion_mayorista_id`) — con esto no queda ninguna FK diferida pendiente en el vertical. Al cerrar la sesión completa se resolvió también la recurrencia #3 del gap de `eliminarSiVacio()` (Sesiones 3 y 5 ya lo habían resuelto dos veces antes) — trazar la cadena de FK real corrigió una asunción equivocada hecha al planificar (`paquetes_plantilla`/`cotizaciones` no necesitaban chequeo propio, ya cubiertas transitivamente; la única raíz real sin cubrir era `tipo_cambio_agencia`). Con Sesión 7 completa, el modelo de datos entero del motor de cotización queda de pie sobre la cadena completa de catálogos de Sesiones 1-6 — mitad del camino de las 11 sesiones, y la más compleja ya construida. |
| 28-jul-2026 | Sesión 8 cerrada y verificada contra tenants descartables reales (commit final `0a39f76`, rama `feature/sesion-8-reserva` mergeada a `main` — 8a y 8b dentro de la misma rama, sin merge intermedio). **8a**: `reserva` (schema de cancelación listo, lógica Fase 2), `reserva_pasajeros` (`pasajero_catalogo_id` sin FK, diferida a Sesión 9), `reserva_items` (copia costo/precio vía FK a `alternativa_items`, no duplica columnas), `reserva_item_pasajero`, `reserva_ventas` (tabla puente real con `sale_id`, resuelve §4.3/§4.4 sin estructura nueva). **8b**: `reglas_cancelacion` (+ `ReglaCancelacionSeeder` standalone con la carga inicial del plan), `reserva_anticipos` (etiqueta un `Advance` del core contra una reserva), `cronograma_pago_proveedor`. Al cerrar la sesión completa, mismo ejercicio de trazar la cadena de FK contra `eliminarSiVacio()`: de las 8 tablas nuevas solo `reglas_cancelacion` necesitó chequeo propio (su seeder no corre automático al provisionar, a diferencia de `configuracion_agencia`) — el resto, incluido `cronograma_pago_proveedor` pese a tener ambas FK nullable, quedó cubierto transitivamente. Con Sesión 8 completa queda de pie todo el modelo de datos del flujo cotización→reserva de punta a punta — quedan 3 sesiones, las últimas dos (10, 11) ya no son puro modelado de datos. |
| 28-jul-2026 | Sesión 9 cerrada y verificada contra tenants descartables reales + `sandbox` real (commit final `59278e7`, rama `feature/sesion-9-integracion-core` mergeada a `main` — 9a, 9b y 9c dentro de la misma rama, sin merge intermedio). **9a**: única parte de todo el vertical que toca tablas CORE compartidas (`products.controla_stock`, `sale_details.descripcion_detalle`, en `tenant/core/` en vez de `verticals/`) — corrida y verificada contra `sandbox` real (retail) sin tocar datos existentes, más `ProductoGenericoViajeSeeder` (5 productos genéricos, standalone). **9b**: `sale_detail_items` (puente hacia `reserva_items`, reportes siguen leyendo de ahí directo, nunca de la factura) y `pago_proveedor` (contraparte de `cronograma_pago_proveedor`, Sesión 8b). **9c**: `pasajeros_catalogo`/`pasajero_documentos` (perfil reutilizable de pasajero) + retrofit que cierra `reserva_pasajeros.pasajero_catalogo_id` — **última FK diferida de todo el vertical, barrido completo confirmado, ninguna queda pendiente**. Mismo ejercicio de `eliminarSiVacio()` en cada cierre: solo `pasajeros_catalogo` necesitó chequeo propio (única FK nullable sin ancestro); `sale_detail_items`/`pago_proveedor` quedaron cubiertas transitivamente. Hallazgo real en 9c: `configuracion_agencia.meses_margen_vencimiento_documento` (pedida en el prompt) ya existía desde Sesión 2 — no se creó una migración duplicada. Con Sesión 9 completa, el modelo de datos entero del vertical queda cerrado e integrado al core de ventas — quedan 2 sesiones, de naturaleza distinta a las 9 anteriores (ya no puro modelado de datos). |
| 28-jul-2026 | Sesión 10 cerrada y verificada contra tenants descartables reales (commit final `28c76f7`, rama `feature/sesion-10-reporte-recordatorios` mergeada a `main`, fast-forward, 3 commits). El reporte operativo (§8) confirmado como NO tabla nueva — solo ALTER `reserva_item_pasajero` (`checkin_realizado`/`checkin_hora`); la pantalla/PDF/acciones inline quedan para Sesión 11. §8bis: `tipos_recordatorio` (catálogo tenant, 5 códigos, `TipoRecordatorioSeeder` standalone), `recordatorios` (`entidad_id` deliberadamente polimórfico SIN FK real — no una FK diferida más, documentado como distinto del patrón de retrofit que este vertical venía cerrando desde Sesión 9c), `recordatorio_snooze_config`. Hallazgo confirmado, no bug: `dias_aviso_pago_proveedor`/`dias_cotizacion_estancada` ya existían desde Sesión 2 — sin migración duplicada. `eliminarSiVacio()` actualizado (`tipos_recordatorio` única raíz nueva sin ancestro cubierto); verificado con control cruzado (tenant vacío eliminable, tenant con `tipos_recordatorio` sembrado rechazado). Con Sesión 10 completa, el modelo de datos entero de los 11 niveles del vertical queda de pie — solo queda Sesión 11 (frontend). |
| 28-jul-2026 | La Sesión 11 original ("Frontend, pantallas según §7") se divide en **11a/11b/11c/11d** al confirmarse que el alcance real es mucho mayor de lo que una fila sugería: (1) no existe ninguna capa API REST para el vertical todavía (Sesiones 0-10 solo migraciones/modelos/seeders) — **11a** cubre controllers/rutas + pantallas CRUD de los maestros (proveedores, destinos, temporadas, guías, configuración); (2) el cotizador (**11b**) tiene su propio diseño UX validado por separado (ver `plan-modulo-cotizaciones-reservas.md` §7.1 y su historial) con prototipo HTML probado antes de programar — layout de biblioteca/lienzo/precio en vivo, comparador de mayoristas, y un motor de precios nuevo (`PriceEngineService` + tabla `cotizacion_pasaje_aereo` para pasajes sueltos, ver §2.5); (3) reserva/pasajeros (**11c**) y reporte/recordatorios — pantallas (**11d**) quedan como sub-sesiones separadas del mismo tamaño que las anteriores. Ninguna de las 4 se construyó todavía. |
| 28-jul-2026 | Cerradas las 2 preguntas abiertas que quedaban de la sesión de diseño anterior (ver `plan-modulo-cotizaciones-reservas.md`, entrada de historial del mismo día): `cotizacion_pasaje_aereo.aerolinea` queda texto libre (no FK — la agencia no es IATA), y sin alerta automática de recordatorio para `reserva_items` sin proveedor asignado (queda solo en el reporte operativo). Filas 11a-11d listas para ejecutarse, sin decisiones de diseño pendientes conocidas. |
| 28-jul-2026 | Sesión 11a cerrada y verificada contra un tenant descartable real (commit final `21d61f3`, rama `feature/sesion-11a-api-maestros` mergeada a `main`, fast-forward, 4 commits). Primera capa API real del vertical: 12 controllers en `App\Http\Controllers\AgenciaViajes` (Proveedores/Servicios/Tarifas con versionado real — nunca UPDATE directo si la tarifa ya se usó en `alternativa_items` —, Destinos en árbol con guard de `destroy()` contra hijos/servicios/itinerarios/tarifas de guía siempre 422 nunca 500, Servicios, Temporadas centrales, Guías/Tarifas, Configuración singleton), 5 permisos `agencia.*` gateando cada ruta, 8 pantallas Vue + 6 services + `DestinoTreeSelect.vue` reutilizable (pensado para 11b). Parte 0: retrofit `proveedor_tarifas.tipo_habitacion` (columna real + backfill, mismo enum que `opciones_hotel_tarifas` desde Sesión 5). Verificado con 33 checks de HTTP real en proceso (kernel completo, incluyendo el middleware `permission:*` — confirmado un 403 real para un usuario sin permisos, no solo que las rutas responden). `eliminarSiVacio()` sin cambios de código (todas las tablas nuevas ya estaban cubiertas desde Sesiones 1-5), confirmado con control cruzado real. Quedan 11b/11c/11d (frontend real: cotizador, reserva/pasajeros, reporte/recordatorios). |
