# Plan General — Vertical "Agencia de Viajes" sobre backend multi-tenant

> Documento vivo. Se actualiza a medida que avanzamos en cada fase.
> Última actualización: 22-jul-2026 — v0.2 (módulo cotizaciones/reservas en desarrollo activo)
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
| 0 | Infraestructura core/verticals | 🔜 Por iniciar | — |
| 1 | Modelado de dominio agencia de viajes | 🟡 En desarrollo activo — módulo cotizaciones/reservas avanzado, ver sub-plan | Fase 0 |
| 2 | Integración vertical → core (ventas/facturación/caja) | ⏳ Pendiente | Fase 1 |
| 3 | Frontend (plantilla Rizz) para agencia de viajes | ⏳ Pendiente, puede solaparse parcialmente con Fase 1 avanzada | Fase 1 (modelo estable) |
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
| 0 | Infraestructura multi-tenant | Separación `core/` vs `verticals/`, campo `giro` en `tenants`, `tenants:provision` | 🔜 Por iniciar | Sección 4 de este doc |
| 1 | Proveedores | Catálogo por tipo (hotel/transporte/restaurante/otros), tarifas (corporativa/grupal/pública), márgenes, precio adulto/niño | ⏳ Sin iniciar como módulo propio (algunos campos ya salieron en el hilo de cotizaciones) | `plan-modulo-proveedores.md` (pendiente de crear) |
| 2 | Catálogo de tours/paquetes | Plantillas de tour, itinerario por día relativo, destinos/atractivos con fotos | 🟡 Parcialmente definido dentro del módulo de cotizaciones | Se separará a `plan-modulo-tours-catalogo.md` cuando corresponda |
| 3 | Cotizaciones y alternativas | Armado de cotización, hasta 5 alternativas por combinación completa, cálculo de precio, PDF comercial | 🟡 En maduración — **sesión aparte** | `plan-modulo-cotizaciones-reservas.md` |
| 4 | Reservas y pasajeros | Datos completos de pasajero, asignación pasajero↔servicio, control operativo | 🟡 En maduración — **sesión aparte**, mismo documento que el módulo 3 | `plan-modulo-cotizaciones-reservas.md` |
| 5 | Itinerarios | Día relativo en plantilla, resuelto a fecha real en reserva, PDF con fotos | 🟡 Definido a nivel de diseño dentro del módulo 3/4 | Mismo doc que 3/4 por ahora |
| 6 | Reportes operativos | Vista por fecha: pasajero, destino, hotel, guía, datos relevantes, vuelos | ⏳ Sin iniciar | `plan-modulo-reportes-operativos.md` (pendiente de crear) |
| 7 | Guías turísticos | Asignación y disponibilidad (normalmente se asigna un día antes) | ⏳ Sin iniciar, ni siquiera se definió si es tabla propia o campo simple | Pendiente decidir si va en módulo propio o dentro de Proveedores |
| 8 | Integración con el core | Reserva → venta → comprobante SUNAT → caja; pagos/anticipos | ⏳ Sin iniciar | Corresponde a Fase 2 (sección 6) |
| 9 | Frontend (plantilla Rizz) | Cotizador, calendario de disponibilidad, gestión de pasajeros | ⏳ Sin iniciar | Corresponde a Fase 3 (sección 7) |
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

## 4. Fase 0 — Infraestructura core/verticals (EN CURSO)

Objetivo: que el sistema soporte múltiples giros de negocio a nivel de
provisioning, sin tocar aún lógica de negocio de viajes.

Checklist:
- [ ] Mover las ~76 migraciones actuales de facturación a
      `database/migrations/core/` (refactor mecánico, sin tocar contenido)
- [ ] Verificar en staging que el orden de dependencias entre migraciones
      no se rompe al moverlas de carpeta
- [ ] Agregar campo `giro` (o `vertical`) a la tabla `tenants` (central)
- [ ] Crear carpeta vacía `database/migrations/verticals/agencia-viajes/`
- [ ] Actualizar `tenants:provision` para que reciba el `giro` y corra
      `--path=database/migrations/core` + `--path=.../verticals/{giro}`
      según corresponda
- [ ] Probar provisioning de un tenant de prueba con giro `agencia_viajes`
      (aunque la carpeta del vertical esté vacía todavía) para validar que
      el mecanismo funciona de punta a punta

Sub-plan detallado: se genera cuando arranquemos la ejecución (siguiente
paso de esta conversación).

---

## 5. Fase 1 — Modelado de dominio (agencia de viajes)

Objetivo: convertir el proceso real de la agencia (Excels, forma de
cotizar, forma de armar tours, manejo de pasajeros) en un modelo de datos.

**Estado real (a partir de conversación directa con el usuario sobre cómo
opera la agencia — no se llegó a usar Excel, se levantó el proceso
directamente):**

Módulo de **Cotizaciones / Alternativas / Reservas / Itinerarios** ya tiene
modelo de datos consolidado. Ver detalle completo en
`plan-modulo-cotizaciones-reservas.md`. Resumen de decisiones clave:
- No se distingue "paquete" de "personalizado" a nivel de datos — todo es
  una lista de servicios atómicos; un paquete es solo una plantilla con
  precio fijado.
- Proveedores manejan varias tarifas (corporativa/grupal/pública,
  compartido/privado), con margen % o fijo, y precio adulto/niño donde el
  corte de edad de "niño" varía por proveedor/servicio.
- Cotización = header (cliente + pasajeros como conteo) + hasta 5
  `alternativas` (combos completos, no mezclables entre sí) + PDF propio
  por alternativa.
- Alternativa aceptada → crea `reserva` con pasajeros completos (nombre,
  documento, alimentación, discapacidad) y asignación pasajero↔servicio.
- Itinerario en dos niveles: día relativo en la plantilla del tour,
  resuelto a fecha real en la reserva. Catálogo de destinos/atractivos
  con fotos, reutilizable entre tours.

**Insumos que siguen pendientes (no bloquean lo ya definido, pero faltan
para cerrar la fase por completo):**
- Módulo de proveedores a fondo (altas/bajas, negociación de tarifas)
- Pagos/anticipos (impacta directamente la Fase 2)
- Asignación de guías (tabla propia vs. campo simple)
- Proceso de compra de pasajes/hoteles internacionales con proveedores
  aliados
- Excel(s) de cotización actual, si existen, para contrastar contra el
  modelo ya levantado por conversación

**Entidades núcleo ya definidas** (detalle completo en el sub-plan):
`proveedores`, `proveedor_tarifas`, `cotizaciones`, `cotizacion_pasajeros`,
`alternativas`, `alternativa_items`, `reserva`, `reserva_pasajeros`,
`reserva_items`, `reserva_item_pasajero`, `tour_itinerario_items`,
`destinos_atractivos`, `destino_servicio`.

Pendiente de definir: la tabla puente exacta hacia el core
(`ventas.origen_type`/`origen_id` sigue como hipótesis, se confirma en
Fase 2).

Sub-planes de esta fase:
- `plan-modulo-cotizaciones-reservas.md` ← **en desarrollo activo, ya
  consolidado con lo definido hasta ahora**
- `plan-modulo-proveedores.md` (pendiente de crear)
- `plan-modulo-tours-salidas.md` (pendiente de crear)

---

## 6. Fase 2 — Integración con el core

Objetivo: que una reserva confirmada genere una venta real, con su
comprobante SUNAT y su registro de caja, usando el core existente sin
duplicar lógica fiscal.

Puntos a definir aquí (todavía abiertos):
- ¿Una reserva genera una sola venta, o puede facturarse en partes (ej.
  anticipo + saldo)?
- Cómo se refleja un pasajero individual dentro de un comprobante que
  factura a un titular/cliente.
- Reutilización de `cajas`/cobros existentes sin cambios, o necesitan
  algún campo adicional para distinguir cobros de viajes (ej. moneda
  extranjera, anticipos).

Se detalla cuando cerremos Fase 1.

---

## 7. Fase 3 — Frontend (plantilla Rizz)

Objetivo: extender la plantilla Rizz (ya usada para facturación) con las
pantallas propias del vertical: cotizador, calendario de salidas, gestión
de pasajeros.

Ideas a explorar (de patrones comunes en sistemas de agencias, para
validar cuáles aplican a este negocio en particular):
- Cotizador tipo "carrito" donde se arma la cotización agregando
  pasajeros/servicios antes de calcular el total
- Vista de calendario/disponibilidad por salida (cupos ocupados vs
  disponibles)
- Checklist de documentación por pasajero (¿DNI/pasaporte completo?
  ¿seguro contratado?) como parte del flujo de reserva, no como
  formulario aparte

Se detalla cuando el modelo de datos de Fase 1 esté estable.

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
