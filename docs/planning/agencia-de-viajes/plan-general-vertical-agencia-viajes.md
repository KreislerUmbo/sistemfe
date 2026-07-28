# Plan General — Vertical "Agencia de Viajes" sobre backend multi-tenant

> Documento vivo. Se actualiza a medida que avanzamos en cada fase.
> Última actualización: 28-jul-2026 — v0.3 (modelo de datos completo,
> Sesiones 0-10 de `plan-hoja-de-ruta-ejecucion.md` construidas y mergeadas;
> falta API REST + frontend, Sesiones 11a-11d)
>
> Referencia de arquitectura base: `arquitectura-multitenant-backend.md`

---

## 1. Objetivo del proyecto

Habilitar el backend multi-tenant existente (`api-sistema-fe`) para soportar
un segundo giro de negocio — **agencia de viajes** — sin romper el core de
facturación electrónica que ya funciona para retail/POS, y sin obligar a
todos los tenants a cargar con tablas que no usan.

El flujo de negocio de un tenant de agencia de viajes debe terminar
apoyándose en lo que el core ya resuelve: **ventas, comprobantes (SUNAT),
cobros y cajas.** Lo nuevo es todo lo que pasa *antes* de eso: cotización,
armado de tour, pasajeros, reserva.

## 2. Alcance (qué entra y qué no, por ahora)

**Entra en el alcance de este proyecto:**
- Infraestructura para que un tenant pueda declarar su `giro` y aprovisionarse
  con las migraciones correctas (core + vertical).
- Modelado de datos del vertical agencia de viajes: cotizaciones, tours,
  salidas, pasajeros, reservas.
- Puente entre entidades del vertical y el core de ventas/facturación.
- Adaptación del frontend (plantilla Rizz) para los flujos propios del
  vertical.

**Fuera de alcance por ahora (explícitamente, para no perder foco):**
- Rediseñar el core de facturación existente.
- El panel superadmin (`plan-panel-superadmin.md`) — es un proyecto aparte;
  solo lo tocamos cuando llegue el momento de agregar el selector de
  giro/vertical en el wizard de creación de tenant.
- Otros verticales futuros (se documentan solo como precedente de patrón).

## 3. Fases macro

| Fase | Nombre | Estado | Depende de |
|---|---|---|---|
| 0 | Infraestructura core/verticals | ✅ Completa — 27-jul-2026, `4cd3944` (Sesión 0 de la hoja de ruta) | — |
| 1 | Modelado de dominio agencia de viajes | ✅ Modelo de datos completo — Sesiones 0-10 de `plan-hoja-de-ruta-ejecucion.md`, 27/28-jul-2026 (ver sección 5). Falta la capa de API REST/CRUD (Sesión 11a) | Fase 0 |
| 2 | Integración vertical → core (ventas/facturación/caja) | 🟡 Iniciada — Sesión 9 conectó `sale_detail_items`/`pago_proveedor`/`products.controla_stock` al core; falta el flujo real reserva→`Sale`→SUNAT→caja de punta a punta vía API (Sesiones 11a-11c) | Fase 1 |
| 3 | Frontend (plantilla Rizz) para agencia de viajes | 🟡 Diseño UX del cotizador validado con prototipo HTML (28-jul-2026, ver `plan-modulo-cotizaciones-reservas.md` §7.1) — construcción real sin empezar (Sesiones 11a-11d) | Fase 1 (modelo estable) |
| 4 | Provisioning end-to-end + primer tenant piloto | ⏳ Pendiente | Fases 0-3 |

La numeración es de orden lógico, no estrictamente secuencial: la Fase 3
(frontend) puede empezar en paralelo apenas el modelo de datos de la Fase 1
esté razonablemente estable, aunque no esté 100% cerrado.

---

## 3.1 Mapa de módulos

Las fases son el orden de ejecución; los módulos son las piezas
funcionales del sistema. Cada módulo madura en su propia sesión/hilo de
chat y tiene (o va a tener) su propio documento `plan-modulo-*.md`. Esta
tabla es el índice — se actualiza cuando un módulo cambia de estado, el
detalle vive en el sub-plan de cada uno, no acá.

| # | Módulo | Qué cubre (resumen) | Estado | Sub-plan |
|---|---|---|---|---|
| 0 | Infraestructura multi-tenant | Separación `core/` vs `verticals/`, campo `giro` en `tenants`, `tenants:provision` | ✅ Completo — Sesión 0 | Sección 4 de este doc |
| 1 | Proveedores | Catálogo por tipo (hotel/transporte/restaurante/otros), tarifas (corporativa/grupal/pública/privada), márgenes + piso de descuento, precio adulto/niño/infante, versionado por temporada | ✅ Modelo de datos completo — Sesiones 1, 3, 4, 5 (`proveedor_tipos`, `proveedores`, `proveedor_servicios`, `proveedor_tarifas`, `temporadas`); retrofit `tipo_habitacion` confirmado para Sesión 11a | `plan-modulo-proveedores.md` |
| 2 | Catálogo de tours/paquetes | Plantillas de tour (`paquetes_plantilla`), itinerario por día relativo, destinos/atractivos (árbol 3 niveles) con fotos, matriz de precio por hotel×habitación | ✅ Modelo de datos completo — Sesión 6 (+ `opciones_hotel`/`opciones_hotel_tarifas` de Sesión 5) | `plan-modulo-tours-catalogo.md` |
| 3 | Cotizaciones y alternativas | Armado de cotización, hasta 5 alternativas por combinación completa, cálculo de precio, PDF comercial, paquetes internacionales vía mayorista | ✅ Modelo de datos completo — Sesión 7 (`cotizaciones`, `alternativas`, `alternativa_items`, `opcion_mayorista`, `salidas_mayorista`, `tipo_cambio_agencia`). Diseño UX del cotizador (Sesión 11b) validado con prototipo, sin construir | `plan-modulo-cotizaciones-reservas.md` |
| 4 | Reservas y pasajeros | Datos completos de pasajero, asignación pasajero↔servicio, control operativo, cancelación/reembolso, anticipos | ✅ Modelo de datos completo — Sesión 8 (`reserva`, `reserva_pasajeros`, `reserva_items`, `reserva_item_pasajero`, `reserva_ventas`, `reserva_anticipos`, `reglas_cancelacion`) + Sesión 9c (`pasajeros_catalogo`/`pasajero_documentos`) | `plan-modulo-cotizaciones-reservas.md` |
| 5 | Itinerarios | Día relativo en plantilla, resuelto a fecha real en reserva, PDF con fotos | ✅ Modelo de datos completo — Sesiones 6 (plantilla) y 8 (resolución a fecha real) | Mismo doc que 3/4 |
| 6 | Reportes operativos | Vista por fecha: pasajero, destino, hotel, guía, datos relevantes, vuelos, check-in | ✅ Modelo de datos completo — Sesión 10 (`reserva_item_pasajero.checkin_realizado`/`checkin_hora`; el reporte en sí es una consulta, no tabla nueva). Pantalla pendiente (Sesión 11d) | `plan-modulo-cotizaciones-reservas.md` §8 (nunca se separó a un doc propio) |
| 7 | Guías turísticos | Asignación (normalmente un día antes, sin control de choques de horario — son freelance) y tarifas por destino/modalidad | ✅ Resuelto — tabla propia `guias` (Sesión 2) + `guia_tarifas` (Sesión 5), no se migró a un tipo más de Proveedores (decisión confirmada 24-jul-2026) | `plan-modulo-cotizaciones-reservas.md` §5.3 |
| 8 | Integración con el core | Reserva → venta → comprobante SUNAT → caja; pagos/anticipos; recordatorios (§8bis) | 🟡 Iniciada — Sesión 9 (`sale_detail_items`, `pago_proveedor`, `products.controla_stock`) + Sesión 10 (`tipos_recordatorio`/`recordatorios`/`recordatorio_snooze_config`). Falta el flujo real reserva→`Sale` de punta a punta vía API (Sesiones 11a-11c) | Corresponde a Fase 2 (sección 6) |
| 9 | Frontend (plantilla Rizz) | Cotizador, calendario de disponibilidad, gestión de pasajeros | 🟡 Diseño UX del cotizador validado con prototipo HTML (28-jul-2026) — construcción sin empezar, dividida en Sesiones 11a-11d | Corresponde a Fase 3 (sección 7) |
| 10 | Portal web público (ventas) | Vitrina de tours/paquetes por destino/categoría, admin de contenido publicado, cotizador online de autoservicio (fase 1: leads con seguimiento por llamada/correo; fase 2 en ~1 año: pasarela de pago) | ⏳ Sin iniciar — explícitamente para el final del proyecto, pero el modelo de datos ya se preparó para no duplicar | `plan-modulo-portal-web.md` (pendiente de crear) |
| 11 | Planes y control de acceso por módulo | Habilitar/deshabilitar funcionalidad según plan contratado (económico/estándar/pro) + add-ons sueltos con costo adicional (ej. facturador +S/20) | 🟡 Sub-plan creado, con gaps identificados (ver documento) — pendiente de alimentar en otra sesión | `plan-modulo-planes-acceso.md` |

**Decisiones de arquitectura ya tomadas para el módulo 11:**
- Es una capa **distinta y ortogonal** al campo `giro`/`vertical` del
  módulo 0: `giro` decide qué tablas EXISTEN (migraciones, fijo al
  aprovisionar); `plan` decide qué funcionalidad ya existente se puede
  USAR (dinámico, sin migraciones al subir/bajar de plan).
- Vive en la base central: `planes`, `modulos` (catálogo maestro,
  opcionalmente con `giro` para módulos exclusivos de un vertical),
  `plan_modulo` (pivote), y `tenants.plan_id`.
- Soporta add-ons fuera del plan base vía `tenant_modulo_overrides`
  (tenant_id, modulo_id, habilitado true/false, precio_adicional) — un
  tenant puede sumar un módulo suelto sin subir de plan completo, o
  desactivar uno que su plan sí incluye.
- Cálculo de acceso efectivo = módulos del plan + overrides habilitados −
  overrides deshabilitados, resuelto por middleware con caché por tenant
  (se apoya en la infraestructura de Spatie Permission ya usada en el
  stack base).

**Decisiones de arquitectura ya tomadas para el módulo 10 (aunque su
desarrollo sea al final), para que ningún otro módulo lo bloquee después:**
- El itinerario mostrado en la web y el usado en cotizaciones/reservas es
  **la misma fuente**: `tour_itinerario_items` + `destinos_atractivos`. No
  se duplica registro de itinerario para la web.
- El catálogo `tours` se extiende con campos exclusivos para publicación
  (`slug`, `descripcion_comercial`, `categoria_ids` vía tabla
  `categorias`, `destino_principal_id`, `precio_desde`,
  `estado_publicacion`) — separados de la lógica de costeo real, que
  sigue viviendo en `proveedor_tarifas`.
- El cotizador web **reutiliza el mismo motor de `cotizaciones` /
  `alternativas` / `alternativa_items`** ya definido para el staff — no
  se construye un motor de cotización aparte. Se distingue el origen con
  `cotizaciones.canal` (interno | web) y se agrega
  `cotizaciones.estado_seguimiento` para el flujo de lead (fase 1: sin
  pasarela, requiere contacto humano antes de confirmar).
- La pasarela de pago (fase 2 del portal, ~1 año) no es un módulo nuevo:
  se conecta al pendiente ya anotado en el módulo 8 (pagos/anticipos).
- **Depende del módulo 11 (planes/acceso):** el cotizador online del
  portal web debe consultar los módulos efectivos del tenant antes de
  ofrecer una opción al cliente final — ej. si el plan de la agencia no
  incluye "cotizaciones con mayoristas", esa opción ni aparece en su
  cotizador público, aunque la tabla/lógica exista en el backend.

**Cómo se usa esta tabla:** cuando un módulo se retoma en una sesión nueva
y su sub-plan se crea o actualiza, se refleja acá el cambio de estado y,
si corresponde, se separa en su propio archivo (ej. el módulo 5 —
Itinerarios — probablemente se independiza de Cotizaciones/Reservas más
adelante porque tiene bastante peso propio).

---

## 4. Fase 0 — Infraestructura core/verticals (✅ COMPLETA — 27-jul-2026)

Objetivo: que el sistema soporte múltiples giros de negocio a nivel de
provisioning, sin tocar aún lógica de negocio de viajes.

Checklist:
- [x] Mover las ~67 migraciones actuales de facturación a
      `database/migrations/tenant/core/` (refactor mecánico, sin tocar contenido)
- [x] Verificar en staging que el orden de dependencias entre migraciones
      no se rompe al moverlas de carpeta
- [x] Agregar campo `giro` (`retail`/`agencia_viajes`) + `tipo` + `sunat_modo`
      a la tabla `tenants` (central)
- [x] Crear carpeta `database/migrations/tenant/verticals/agencia-viajes/`
      (vacía en esta fase, con contenido real desde Sesión 2)
- [x] Actualizar `tenants:provision` para que reciba el `giro` y corra
      las migraciones de `core/` + `verticals/{giro}` según corresponda
- [x] Probar provisioning de un tenant de prueba con giro `agencia_viajes`
      de punta a punta

Commit final: `4cd3944`, rama `feature/sesion-0-infraestructura` mergeada a
`main` (Sesión 0 de `plan-hoja-de-ruta-ejecucion.md`). Detalle completo y
hallazgos en el historial de ese documento y en `TODO.md` (Sesión 0).

---

## 5. Fase 1 — Modelado de dominio (agencia de viajes) — ✅ MODELO DE DATOS COMPLETO

Objetivo: convertir el proceso real de la agencia (Excels, forma de
cotizar, forma de armar tours, manejo de pasajeros) en un modelo de datos.

**Estado real (28-jul-2026):** las Sesiones 0-10 de
`plan-hoja-de-ruta-ejecucion.md` están construidas y mergeadas a `main` —
el modelo de datos completo del vertical (migraciones + modelos Eloquent +
seeders standalone donde corresponde) queda de pie. Lo que falta no es
diseño ni schema: es la capa de API REST/CRUD y el frontend (Sesiones
11a-11d, ver sección 3.1 y `plan-hoja-de-ruta-ejecucion.md`).

Módulo de **Cotizaciones / Alternativas / Reservas / Itinerarios** — ver
detalle completo en `plan-modulo-cotizaciones-reservas.md` (documento vivo,
con historial completo de cada decisión). Resumen de decisiones clave:
- No se distingue "paquete" de "personalizado" a nivel de datos — todo es
  una lista de servicios atómicos; un paquete es solo una plantilla con
  precio fijado.
- Proveedores manejan varias tarifas (corporativa/grupal/pública,
  compartido/privado), con margen % o fijo + piso de descuento protegido,
  precio adulto/niño/infante (corte de edad configurable por proveedor),
  versionado por temporada (`temporadas`/`temporada_ocurrencias`).
- Cotización = header (cliente + pasajeros como conteo por edad) + hasta 5
  `alternativas` (combos completos, no mezclables entre sí) + PDF propio
  por alternativa. Paquetes internacionales vía mayorista
  (`opcion_mayorista`, matriz de precio por hotel×tipo de habitación,
  `salidas_mayorista` como catálogo de fechas fijas).
- Alternativa aceptada → crea `reserva` con pasajeros completos (nombre,
  documento, alimentación, discapacidad, perfil reutilizable vía
  `pasajeros_catalogo`/`pasajero_documentos`) y asignación
  pasajero↔servicio (`reserva_item_pasajero`, con check-in desde Sesión 10).
- Itinerario en dos niveles: día relativo en la plantilla del tour
  (`tour_itinerario_items`), resuelto a fecha real en la reserva. Catálogo
  de destinos/atractivos en árbol de 3 niveles (zona/lugar/atractivo), con
  fotos, reutilizable entre tours.
- Guías turísticos como tabla propia `guias` + `guia_tarifas` (freelance,
  sin control de choques de horario).
- Cancelación/reembolso: `reglas_cancelacion` por franja de días, y
  liberación de cupo en `salidas_mayorista` al cancelar (esto sí entró en
  el primer lanzamiento — el resto de la lógica de cancelación es Fase 2
  del sub-plan, no de este documento).
- Sistema transversal de recordatorios en-app (§8bis del sub-plan):
  `tipos_recordatorio`/`recordatorios`/`recordatorio_snooze_config`, con
  snooze y flag `forzado` por el admin.

**Insumos que estaban pendientes en la versión anterior de este documento —
resueltos:**
- ~~Pagos/anticipos~~ → resuelto: `reserva_anticipos` (etiqueta un
  `Advance` del core contra una reserva antes de facturar, Sesión 8b) +
  `cronograma_pago_proveedor`/`pago_proveedor` para lo que la agencia paga
  a sus proveedores (Sesiones 8b/9b).
- ~~Asignación de guías (tabla propia vs. campo simple)~~ → resuelto:
  tabla propia (ver arriba).
- ~~Proceso de compra de pasajes/hoteles internacionales~~ → resuelto a
  nivel de modelo: `opcion_mayorista`/`salidas_mayorista` (Sesión 7b). El
  motor de precios para pasajes aéreos SUELTOS (`cotizacion_pasaje_aereo`,
  `PriceEngineService`) quedó diseñado (28-jul-2026) pero no construido —
  va en Sesión 11b.
- **Sigue pendiente, no bloquea nada:** módulo de proveedores a fondo
  (altas/bajas, negociación de tarifas) como flujo operativo — el modelo
  de datos ya existe, falta la UI de gestión (parte de Sesión 11a).
  Contrastar contra Excel(s) de cotización reales, si existen — el proceso
  se levantó por conversación directa y se validó con 3 documentos reales
  de la agencia (Alto Mayo, Cusco, Panamá), no se descartó por completo.

**Entidades núcleo** (lista larga — detalle completo en cada sub-plan, no
se repite acá): `proveedores`, `proveedor_tarifas`, `proveedor_servicios`,
`guias`, `guia_tarifas`, `destinos_atractivos`, `servicios`,
`destino_servicio`, `paquetes_plantilla`, `tour_itinerario_items`,
`opciones_hotel`/`opciones_hotel_tarifas`, `cotizaciones`,
`cotizacion_pasajeros`, `alternativas`, `alternativa_items`,
`opcion_mayorista`, `opcion_mayorista_opcionales`, `salidas_mayorista`,
`tipo_cambio_agencia`, `reserva`, `reserva_ventas`, `reserva_pasajeros`,
`reserva_items`, `reserva_item_pasajero`, `reserva_anticipos`,
`cronograma_pago_proveedor`, `reglas_cancelacion`, `pago_proveedor`,
`sale_detail_items`, `pasajeros_catalogo`, `pasajero_documentos`,
`tipos_recordatorio`, `recordatorios`, `recordatorio_snooze_config`.

Puente hacia el core: resuelto como `reserva_ventas` (tabla puente real con
`sale_id`, no la hipótesis `origen_type`/`origen_id` que este documento
mencionaba antes) — ver sección 6.

Sub-planes de esta fase (los 3 ya existen, ninguno queda "pendiente de
crear"):
- `plan-modulo-cotizaciones-reservas.md` — el más grande, cubre
  cotizaciones/alternativas/reservas/itinerarios/reportes/recordatorios
- `plan-modulo-proveedores.md`
- `plan-modulo-tours-catalogo.md`

---

## 6. Fase 2 — Integración con el core (🟡 iniciada en Sesión 9)

Objetivo: que una reserva confirmada genere una venta real, con su
comprobante SUNAT y su registro de caja, usando el core existente sin
duplicar lógica fiscal.

Puntos que este documento dejaba abiertos — respondidos en
`plan-modulo-cotizaciones-reservas.md` §6 (Sesión 9) y §4.3/§4.4:
- **¿Una reserva genera una sola venta, o puede facturarse en partes?**
  Puede ser N — `reserva_ventas` es una tabla puente (no un `sale_id`
  simple) que soporta tanto un solo responsable pagando todo, como cada
  familia/pasajero con su propia venta y su propio cronograma de crédito
  (caso colegios). También soporta "documento adicional" vs. "todo en un
  solo documento" (NC + reemplazo) cuando se agrega un servicio después de
  facturar.
- **Pasajero individual dentro de un comprobante:** se resuelve por
  `sale_detail_items` (tabla puente entre `sale_details` y
  `reserva_items`) + `sale_details.descripcion_detalle` (texto concatenado
  de qué incluye la línea) — no se crea una línea de factura por
  pasajero, se agrupa por tipo de servicio con restricción de no mezclar
  tratamientos tributarios distintos en una misma línea.
- **Reutilización de cajas/cobros:** productos genéricos por tipo de
  servicio (`products.controla_stock=false`) para no tocar `SaleDetail`
  (que exige `product_id` con stock/ISC/categoría). El módulo de Caja (ver
  "Estado actual del proyecto" en `CLAUDE.md` raíz) es genérico, no
  vertical-específico — no necesitó cambios propios para viajes.

**Lo que SÍ sigue sin resolver (bloquea Sesiones 11a-11c, no bloquea nada
de lo ya construido):** no existe todavía ningún controller/endpoint que
efectivamente tome una `alternativa` aceptada y dispare la creación real de
`reserva` + `Sale` + envío a SUNAT — todo lo de arriba es modelo de datos y
diseño, verificado con datos reales en tenants descartables, pero nunca
ejecutado a través de una API real. Esa es la Sesión 11a/11c.

---

## 7. Fase 3 — Frontend (plantilla Rizz) — 🟡 diseño UX validado, sin construir

Objetivo: extender la plantilla Rizz (ya usada para facturación) con las
pantallas propias del vertical: cotizador, calendario de salidas, gestión
de pasajeros.

**Modelo de datos de Fase 1 ya estable (Sesiones 0-10) — el diseño de esta
fase arrancó el 28-jul-2026**, resuelto en `plan-modulo-cotizaciones-reservas.md`
§7/§7.1, no acá (este documento no repite el detalle de layout):
- **Descartado** el wizard de tarjetas numeradas de `sale/register.vue`
  (Ventas) — cotizar es exploratorio (probar combinaciones, comparar
  precio), no una transacción lineal. Se investigó software real del
  rubro (Travefy, Ezus, Tourwriter) antes de diseñar.
- **Layout elegido:** 3 columnas — biblioteca de tarifas (filtrada por
  destino) / lienzo día-por-día con pestañas de alternativas / precio en
  vivo editable. Toggle Local-Nacional (biblioteca) vs. Internacional
  (comparador de cotizaciones de mayorista). Validado con un prototipo
  HTML clickeable (fuera del repo, en la conversación de diseño) antes de
  programar componentes Vue reales.
- El "cotizador tipo carrito" y el "checklist de documentación por
  pasajero" de la versión anterior de este documento **sí se confirmaron**
  como parte del diseño (ver detalle en el sub-plan); la "vista de
  calendario/disponibilidad por salida" quedó fuera — el negocio no pidió
  control de cupo salvo el contador informativo de `salidas_mayorista`
  (sección 3.6 del sub-plan, no bloqueante).

**Construcción real:** dividida en Sesiones 11a (API REST + maestros),
11b (cotizador), 11c (reserva/pasajeros), 11d (reporte + recordatorios) —
ver `plan-hoja-de-ruta-ejecucion.md`. Ninguna empezada todavía.

---

## 8. Fase 4 — Piloto

Objetivo: un tenant real (o de prueba con datos reales) corriendo el
vertical completo de punta a punta, antes de ofrecerlo como opción general
en el wizard del panel superadmin.

---

## 9. Principios transversales (heredados de la arquitectura base)

- **Nunca fallback silencioso** en lógica fiscal/tributaria — se mantiene
  aunque el vertical sea distinto de retail.
- **Diseñar para crecer sin rediseño**: ej. `cotizaciones` con estados que
  puedan extenderse de "solo cotización" a "reserva de pasajero" sin migrar
  tablas después.
- **Correr `core/` siempre en todo tenant**, aunque no use ese módulo
  todavía — más barato provisionar de más que migrar en caliente después.

---

## 10. Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 21-jul-2026 | Versión inicial del plan general, fases definidas a alto nivel |
| 22-jul-2026 | Fase 1 actualizada: modelo de cotizaciones/alternativas/reservas/itinerarios consolidado en sub-plan dedicado |
| 22-jul-2026 | Agregado mapa de módulos (sección 3.1) como índice general — desarrollo detallado de cada módulo continúa en sesiones separadas |
| 22-jul-2026 | Agregado módulo 10 (Portal web) al mapa, con decisiones de arquitectura para evitar duplicar itinerarios y reutilizar el motor de cotizaciones/alternativas ya definido |
| 22-jul-2026 | Agregado módulo 11 (Planes y control de acceso por módulo): planes base + add-ons, separado del campo giro/vertical |
| 22-jul-2026 | Creado sub-plan `plan-modulo-planes-acceso.md` con evaluación de gaps (ciclo de vida de suscripción, límites de uso, dependencias entre módulos) |
| 25-jul-2026 | Creado `plan-hoja-de-ruta-ejecucion.md` — traduce el árbol de dependencias del modelo de datos a 11 sesiones concretas de ejecución (luego 15, ver 28-jul-2026), con checklist de avance. A partir de acá el detalle sesión-por-sesión vive en ese documento, no en este — este documento pasa a ser el índice/resumen de alto nivel. |
| 27/28-jul-2026 | **Fase 0 cerrada** (Sesión 0, commit `4cd3944`) y **Fase 1 — modelo de datos completo** (Sesiones 1-10, hasta `28c76f7`): las 10 primeras sesiones de la hoja de ruta construidas y mergeadas a `main` — catálogos centrales/tenant, proveedores y tarifas, catálogo de tours, motor de cotización completo, reserva y todo lo que dispara, integración parcial con el core de ventas (Sesión 9), y reporte operativo + recordatorios (Sesión 10). Los 3 sub-planes de Fase 1 (`plan-modulo-proveedores.md`, `plan-modulo-tours-catalogo.md`, `plan-modulo-cotizaciones-reservas.md`) ya existen — ninguno queda "pendiente de crear". Detalle sesión por sesión, con hallazgos y bugs reales corregidos en el camino, en el historial de `plan-hoja-de-ruta-ejecucion.md` y en `TODO.md` (raíz del repo). Secciones 3, 3.1, 4, 5, 6 y 7 de este documento actualizadas para reflejar el estado real (antes decían "por iniciar"/"pendiente" pese a estar avanzado). |
| 28-jul-2026 | Diseño (sin construir) de la Sesión 11 original ("Frontend"), dividida en **11a/11b/11c/11d** por alcance real mayor al esperado — ver sección 7. Motor de precios único (`PriceEngineService`) y tabla `cotizacion_pasaje_aereo` para pasajes aéreos sueltos diseñados y validados contra normativa MTC 2026. 2 retrofits confirmados sobre tablas ya mergeadas (`proveedor_tarifas.tipo_habitacion`; `alternativa_items.origen_tipo`/`cantidad`/`descripcion_manual` + `reserva_items.proveedor_tarifa_id`). Las 2 preguntas de diseño que quedaban abiertas se cerraron el mismo día (aerolínea = texto libre, sin recordatorio automático por proveedor sin asignar). Detalle completo en el historial de `plan-modulo-cotizaciones-reservas.md` y `TODO.md`. |
