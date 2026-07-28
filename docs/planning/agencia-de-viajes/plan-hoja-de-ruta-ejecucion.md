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

## 1. Las 11 sesiones, en orden estricto

| # | Sesión | Qué construir | Documento de referencia (sección exacta) | Estado |
|---|---|---|---|---|
| 0 | Infraestructura core/verticals | Separación `database/migrations/core/` vs `verticals/agencia-viajes/`, campo `giro` en `tenants`, `tenants:provision` | `arquitectura-multitenant-backend.md`, `plan-modulo-infraestructura-multitenant.md` | [x] 27-jul-2026 — `4cd3944` |
| 1 | Catálogos centrales | `proveedor_tipos`, `temporadas` (ambos con columna `giro`) | `plan-modulo-proveedores.md` §2.6 | [x] 27-jul-2026 — `7279ec8` |
| 2 | Catálogos por tenant, sin dependencias | `destinos_atractivos` (árbol 3 niveles), `servicios`, `configuracion_agencia`, `guias` | `plan-modulo-tours-catalogo.md` completo (es corto) | [x] 27-jul-2026 — `d33bc22` |
| 3 | Puente destino↔servicio + proveedores | `destino_servicio`, `proveedores`, `proveedor_tipos_config` | `plan-modulo-tours-catalogo.md` §4, `plan-modulo-proveedores.md` §2.6 | [x] 27-jul-2026 — `78296d2` |
| 4 | Proveedor × destino | `proveedor_servicios` | `plan-modulo-proveedores.md` §2.6 | [x] 27-jul-2026 — `732534c` |
| 5 | Tarifas (la parte más grande) | `proveedor_tarifas`, `guia_tarifas`, `opciones_hotel`/`opciones_hotel_tarifas` | `plan-modulo-proveedores.md` §2.6, `plan-modulo-cotizaciones-reservas.md` §2.2, §2.4, §5.3 | [x] 27-jul-2026 — `e571fb3` |
| 6 | Catálogo de tours vendibles | `paquetes_plantilla`, `tour_itinerario_items` | `plan-modulo-cotizaciones-reservas.md` §3.7, §5.1 | [x] 27-jul-2026 — `c8581b3` |
| 7 | Motor de cotización | `cotizaciones`, `cotizacion_pasajeros`, `alternativas`, `alternativa_items`, `opcion_mayorista`, `opcion_mayorista_opcionales`, `tipo_cambio_agencia` | `plan-modulo-cotizaciones-reservas.md` §3 completo | [ ] |
| 8 | Reserva y todo lo que dispara | `reserva`, `reserva_ventas`, `reserva_pasajeros`, `reserva_items`, `reserva_item_pasajero`, `reserva_anticipos`, `cronograma_pago_proveedor`, `reglas_cancelacion` | `plan-modulo-cotizaciones-reservas.md` §4 completo | [ ] |
| 9 | Integración con el core de ventas | Cambios en `Sale`/`SaleDetail`/`Product`, `pago_proveedor`, `sale_detail_items`, `pasajeros_catalogo`/`pasajero_documentos` | `plan-modulo-cotizaciones-reservas.md` §6 completo | [ ] |
| 10 | Reporte operativo + recordatorios | Vista de `reserva_items`, `tipos_recordatorio`/`recordatorios`/`recordatorio_snooze_config` | `plan-modulo-cotizaciones-reservas.md` §8, §8bis | [ ] |
| 11 | Frontend (Fase 3) | Pantallas según criterios de UX ya definidos | `plan-modulo-cotizaciones-reservas.md` §7 | [ ] |

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
