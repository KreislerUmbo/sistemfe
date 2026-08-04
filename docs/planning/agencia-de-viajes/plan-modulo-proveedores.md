# Sub-plan — Módulo 1: Proveedores

> Parte de: `plan-general-vertical-agencia-viajes.md` — Módulo 1
> Estado: sin iniciar como módulo propio — este documento es el punto de
> partida, recopila todo lo que ya salió mencionado de pasada en otras
> sesiones (principalmente el hilo de cotizaciones/reservas) para no
> perder ese contexto al empezar en un chat nuevo.
> Última actualización: 23-jul-2026

---

## 0. Cómo usar este documento (si estás retomando esto en un chat nuevo)

Este documento es el **punto de entrada**. Antes de decidir nada nuevo
sobre proveedores, conviene que Claude (o quien retome esto) lea:

1. Este documento completo (contexto ya acumulado)
2. `plan-modulo-cotizaciones-reservas.md` — es donde más ha salido
   mencionado el modelo de proveedores hasta ahora, aunque de forma
   incidental, no como módulo propio
3. `plan-modulo-planes-acceso.md`, sección 3.4 — tiene dependencias de
   módulos que involucran directamente a proveedores
   (`pagos_proveedores`, `amortizaciones_proveedor`, `liquidaciones`)
4. `plan-general-vertical-agencia-viajes.md`, sección 3.1 (Fase 1) y el
   mapa de módulos (sección 3.1) para status general

## 1. Objetivo

Modelar el catálogo de proveedores de la agencia (hoteles, transporte,
restaurantes, guías, otros) con sus tarifas — de forma que el módulo de
cotizaciones/alternativas (ya bastante maduro) tenga una fuente real de
donde sacar precios, en vez de los campos sueltos que se mencionaron de
pasada mientras se diseñaba ese módulo.

**Por qué es la siguiente prioridad real** (no solo "el número 1 en la
lista"): el módulo de cotizaciones/reservas ya está maduro y depende de
`proveedor_tarifas` para calcular precios — hoy esa tabla solo existe
como nombre mencionado, sin campos definidos con cuidado. Cerrar
proveedores primero evita que cotizaciones tenga que rediseñarse cuando
proveedores madure después.

## 2. Todo lo que ya se sabe (recopilado de otras sesiones — no es nuevo, es contexto)

### 2.1 Del mapa de módulos (plan general, sección 3.1)
> Catálogo por tipo (hotel/transporte/restaurante/otros), tarifas
> (corporativa/grupal/pública), márgenes, precio adulto/niño

Estado ahí anotado: "Sin iniciar como módulo propio (algunos campos ya
salieron en el hilo de cotizaciones)".

### 2.2 De la Fase 1 del plan general (modelado de dominio)
> Proveedores manejan varias tarifas (corporativa/grupal/pública,
> compartido/privado), con margen % o fijo, y precio adulto/niño donde
> el corte de edad de "niño" varía por proveedor/servicio.

Esto es importante: **el corte de edad de "niño" no es una constante
del sistema** — varía por proveedor y hasta por servicio dentro del
mismo proveedor (ej. un hotel puede considerar niño hasta los 11 años,
una aerolínea hasta los 2). Cualquier campo de "precio niño" necesita
su propio umbral de edad asociado, no una constante global.

### 2.3 Entidades núcleo ya nombradas (sin campos detallados todavía)
```
proveedores
proveedor_tarifas
```
Mencionadas en la lista de entidades núcleo de Fase 1, junto a las ya
maduras de cotizaciones/reservas (`cotizaciones`, `alternativas`,
`reserva`, etc.) — pero a diferencia de esas, **nunca se llegó a
detallar la estructura interna de `proveedores`/`proveedor_tarifas`**.

### 2.4 Dependencias con el módulo 11 (planes/acceso) — ya resuelto ahí
Del sub-plan de planes/acceso, sección 3.4 (dependencias entre
módulos), hay 3 módulos del catálogo de feature-gating que dependen
directamente de que exista un módulo `pagos_proveedores`:
```
liquidaciones            requiere  pagos_proveedores
amortizaciones_proveedor requiere  pagos_proveedores
```
Esto no define el modelo de datos de proveedores en sí, pero sí
confirma que **debe existir un registro de pagos de la agencia hacia
sus proveedores** (mayoristas) como parte de este módulo o de uno
estrechamente relacionado — con estados que alimenten liquidaciones y
amortizaciones más adelante.

### 2.5 Guías turísticos (módulo 7) — pregunta sin resolver, relacionada
Del mapa de módulos: "Asignación y disponibilidad (normalmente se
asigna un día antes)" — **ni siquiera se definió si es tabla propia o
campo simple dentro de Proveedores**. Es una decisión que toca resolver
al mismo tiempo que se modela proveedores, porque un guía podría
modelarse como un tipo más de proveedor (con su propia tarifa) o como
algo completamente aparte.

## 2.6 Decisiones tomadas en esta sesión (23-jul-2026)

### Tipos de proveedor — RESUELTO
`proveedor_tipos` es un catálogo **central** (`db_tenant_central`), compartido
por todo el rubro agencias de viajes — no varía por agencia. Sigue el mismo
patrón que otros catálogos centrales (`tipos_comprobante`), por lo tanto debe
llevar el trait `CentralConnection` (ver nota de bug recurrente en
`arquitectura-multitenant-backend.md`).

```
proveedor_tipos (central)
  id
  nombre        -- ej. "Hotel", "Transporte", "Mayorista", "Guía"
  slug
  activo        -- baja a nivel de todo el sistema, no por tenant
```

Lo que sí varía por agencia es qué tipos usa. Se resuelve con tabla
**independiente** (no se mezcla con el mecanismo de `tenant_modulo_overrides`
del módulo 11 — esa es para módulos completos, esto es granularidad de fila
de catálogo, mezclar los dos niveles complica el middleware sin necesidad):

```
proveedor_tipos_config (por tenant)
  proveedor_tipo_id   -- referencia al id central (sin FK real cross-DB)
  habilitado          -- default true, sembrado al provisionar con todo el catálogo
```

Regla de negocio: deshabilitar un tipo **solo oculta la opción al crear
proveedores nuevos**. Nunca afecta ni oculta proveedores ya existentes de ese
tipo — siguen operando con normalidad. Ej.: agencia pequeña que no trabaja
con mayoristas apaga el tipo "Mayorista" sin que eso rompa nada si en el
futuro sí llega a necesitarlo.

**RETROFIT 29-jul-2026 — CRUD del catálogo central agregado (panel superadmin):**
hasta esta sesión, `proveedor_tipos` era 100% fijo (solo `ProveedorTipoSeeder`, sin
ningún endpoint para crear/editar/desactivar tipos nuevos) — `ProveedorTipoConfigController`
(tenant) solo puede tocar `habilitado`, nunca el catálogo en sí. Se agregó
`Central\ProveedorTipoController` (`GET/POST/PUT/DELETE central/proveedor-tipos`, guard
`central`, vista `ProveedorTiposView.vue` en `central-panel`) — mismo criterio de "sin
borrado real" que `TenantPlanController` (`DELETE` desactiva, no borra la fila, porque
`proveedor_tipos` no tiene FK real hacia `Proveedor.tipo_id` cross-boundary y no hay forma
barata de confirmar que ningún tenant lo esté usando). `slug` nunca se acepta del payload —
el backend lo deriva de `nombre` una sola vez al crear y queda inmutable para siempre,
porque hay lógica de negocio atada a slugs fijos (ej. `tipo_habitacion` solo se exige si
`slug='hotel'` en `ProveedorTarifaController`) que se rompería en silencio si el slug
pudiera cambiar.

### Vigencia de tarifas en el tiempo — RESUELTO
Se versiona, nunca se sobrescribe. `proveedor_tarifas` lleva `vigente_desde` /
`vigente_hasta` (nullable = vigente indefinidamente) para el **registro**
(evitar perder historia, nunca UPDATE de un monto que ya pudo usarse en una
cotización — se cierra la fila vigente y se crea una nueva).

**Implicación directa para cotizaciones/reservas:** una cotización no debe
referenciar solo `proveedor_tarifas.id` en vivo — debe guardar el precio
"congelado" (snapshot) al momento de armarse, para que una cotización vieja
nunca cambie de precio si el proveedor sube tarifa después. Pendiente
verificar si `plan-modulo-cotizaciones-reservas.md` ya contempla este
snapshot o si falta agregarlo ahí.

### Temporadas dentro de la vigencia — RESUELTO
El proveedor no manda una sola tarifa al año — manda un PDF con **varias
tarifas vigentes simultáneamente**, una por temporada (regular, alta, Fiestas
Patrias, Navidad, etc.). Por eso, además del versionado del registro, se
necesita modelar a qué fecha de *servicio* aplica cada tarifa. Se usa
catálogo de temporadas reutilizable entre proveedores, separado de sus
ocurrencias anuales concretas (porque temporadas móviles como Semana Santa
cambian de fecha cada año):

```
temporadas (catálogo — nombre reutilizable entre todos los proveedores)
  id
  nombre        -- "Temporada Alta", "Fiestas Patrias", "Navidad y Año Nuevo"
  tipo          -- fija (mismo rango cada año) vs móvil (varía por año)

temporada_ocurrencias (ocurrencia concreta por año calendario)
  id
  temporada_id
  anio
  fecha_desde
  fecha_hasta

proveedor_tarifas
  ...
  temporada_id     -- nullable: null = tarifa regular/todo el año
  vigente_desde / vigente_hasta   -- versionado del registro (ver arriba)
```

Al cotizar, el sistema cruza la fecha del servicio contra
`temporada_ocurrencias` para resolver automáticamente qué tarifa aplica —
el vendedor no elige la tarifa a mano. Ventaja: "Fiestas Patrias" se define
una sola vez como concepto y todos los proveedores heredan el criterio de
fechas cada año, solo se carga la `temporada_ocurrencia` del año nuevo una
vez.

### Altas/bajas y negociación de tarifas — RESUELTO
- El proveedor envía un **PDF anual** con las tarifas de todas las
  temporadas del año (regular, alta, fiestas). No es un proceso informal
  suelto por WhatsApp para cada tarifa — el documento formal es el PDF.
- Cuando cambia una tarifa a mitad de año, el **proveedor avisa
  proactivamente** por correo o WhatsApp; la agencia actualiza a partir de
  ese aviso (no se descubre recién al cotizar).
- **Permisos:** solo rol administrador/supervisor puede cargar o editar
  tarifas (permiso vía Spatie, ya presente en el stack base). No existe un
  flujo de aprobación aparte — el gate de permiso por rol **es** la
  aprobación. Una vez cargada por el admin, queda disponible de inmediato
  para que los vendedores cotizen con ella, sin estado intermedio
  "pendiente".
- Pendiente (no bloqueante): si vale la pena guardar el PDF original como
  adjunto de la tarifa/temporada para trazabilidad/auditoría — no se ha
  decidido todavía.

### Relación con destino_servicio, costo, margen y piso de descuento — RESUELTO
`proveedor_tarifas` no cuelga directo de `proveedores` — cuelga de una tabla
intermedia `proveedor_servicios`, para que un mismo proveedor pueda ofrecer
el mismo servicio en distintos destinos con tarifas distintas sin duplicar
el proveedor:

```
proveedores 1─N proveedor_servicios N─1 destino_servicio
proveedor_servicios 1─N proveedor_tarifas
```

**Actualizado 24-jul-2026 — ver `plan-modulo-tours-catalogo.md`:**
`destino_servicio` no apunta a un destino plano — apunta a un árbol de 3
niveles (zona/lugar/atractivo) en `destinos_atractivos`, y puede colgar
de **cualquiera** de esos niveles, no solo de "lugar". Caso real: el
transporte cobra distinto por lugar (Moyobamba vs. Rioja), pero las
entradas a atractivos cobran por atractivo específico (entrada al
orquideario ≠ entrada a otro atractivo dentro del mismo lugar). Además,
`destino_servicio` ahora cruza con un catálogo `servicios` (Traslado ida
y vuelta, Traslado aeropuerto-hotel, Entrada/Boleto, etc.) — el mismo
proveedor puede tener tarifas distintas para servicios distintos hacia el
mismo destino.

Además, el precio no es un monto único fijo — hay que distinguir **costo**
(lo que cobra el proveedor) de **precio de venta** (lo que cobra la
agencia), con margen configurable, y un **piso protegido** que limita hasta
dónde puede bajar un vendedor al cotizar:

```
proveedor_tarifas
  ...
  costo                  -- lo que cobra el proveedor (costo real de la agencia)
  tipo_margen             -- 'porcentaje' | 'fijo'
  margen_valor             -- valor de utilidad sobre el costo
  -- precio_venta = costo + margen_valor, calculado, no se persiste dos veces
  descuento_maximo_pct     -- % máx. de descuento permitido sobre precio_venta
  margen_minimo_pct        -- % mín. de utilidad que siempre debe quedar sobre costo
```

**Regla de piso (ambas reglas a la vez, gana la más restrictiva):**
```
precio_piso_por_descuento = precio_venta × (1 − descuento_maximo_pct)
precio_piso_por_margen    = costo × (1 + margen_minimo_pct)
precio_minimo_permitido   = MAYOR(precio_piso_por_descuento, precio_piso_por_margen)
```
El vendedor no puede guardar un precio editado por debajo de
`precio_minimo_permitido` — el sistema bloquea explícitamente (nunca ajusta
en silencio, coherente con el principio transversal del proyecto).

**El precio es editable al momento de cotizar.** `alternativa_items` (en
`plan-modulo-cotizaciones-reservas.md`) debe guardar tres valores, no uno:
```
alternativa_items
  ...
  costo_snapshot          -- costo del proveedor, congelado al cotizar
  precio_venta_snapshot   -- precio de venta calculado, congelado al cotizar
  precio_editado           -- lo que el vendedor realmente cobró (puede diferir)
```
Esto da trazabilidad completa: cuánto costó, cuánto "debía" venderse según
la regla, y cuánto se vendió realmente — insumo directo para reportes de
rentabilidad más adelante. Pendiente: confirmar en el sub-plan de
cotizaciones/reservas si esta estructura de 3 campos ya existe o falta
agregarla.

### Guías turísticos — RESUELTO (revisado 24-jul-2026)
**Decisión final: tabla propia `guias`, NO tipo de proveedor.** La
propuesta inicial de esta sesión (tipo más dentro de `proveedor_tipos`)
quedó revertida al contrastar con `plan-modulo-cotizaciones-reservas.md`:
ahí ya existe una tabla `guias` (nombre, documento, teléfono, activo)
conectada a `reserva_items.guia_id`, sin manejo de disponibilidad/calendario
(se asigna normalmente un día antes, sin bloquear el resto del flujo).
Migrar a tipo de proveedor implicaba tocar esa conexión ya construida sin
un beneficio claro — se prioriza lo que ya funciona.

### Proveedores internacionales / moneda extranjera — RESUELTO
`proveedor_tarifas` necesita campo `moneda` (no asumir siempre soles).
**Actualizado 24-jul-2026:** se descarta definitivamente el flag
`es_internacional` en `proveedores` — un mayorista peruano puede manejar
servicios nacionales e internacionales al mismo tiempo (tarifas en PEN y
en USD simultáneamente), así que un flag único por proveedor modelaría mal
ese caso real. La `moneda` por tarifa individual ya resuelve esto sin
necesidad de clasificar al proveedor completo.

### Margen automático por mayorista — NUEVO 25-jul-2026 (ver `plan-modulo-cotizaciones-reservas.md` §2.4)
Los paquetes internacionales vienen de mayoristas (Nuevo Mundo Viajes,
Viajes Falabella, Inter-agencias, etc.) sin una `proveedor_tarifa`
registrada — el precio lo da el mayorista al momento de cotizar, no está
en el catálogo. Para que el margen se calcule automático (sin que el
vendedor tenga que hacerlo a mano en Excel, que es el proceso actual que
se busca agilizar), se agrega:
```
proveedores (campos nuevos, aplica sobre todo a tipo "mayorista")
  margen_default_tipo: porcentaje | fijo
  margen_default_valor
```
Al cargar los precios de costo que da el mayorista (en `opciones_hotel_tarifas`,
ver cotizaciones-reservas.md), el sistema calcula `precio_venta`
automáticamente con este default — editable línea por línea si la
negociación puntual de esa cotización fue distinta.

---

## 3. Estado de las 6 preguntas originales

Las 6 preguntas que abrieron este módulo quedaron **todas resueltas** en la
sesión del 23-jul-2026 (detalle completo en 2.6). Resumen:

1. ✅ Tipos de proveedor → catálogo central + config on/off por tenant.
2. ✅ Vigencia de tarifas → versionado (`vigente_desde`/`vigente_hasta`),
   nunca se sobrescribe, más el modelo de temporadas.
3. ✅ Relación con `destino_servicio` → **confirmado 24-jul-2026** tras
   cruce con `plan-modulo-cotizaciones-reservas.md`: se usa
   `proveedor_servicios`, reemplazando el filtro "sugerido no bloqueante"
   que tenía ese documento — caso real que lo justificó: un mismo
   proveedor de transporte cobra distinto por destino (Lamas vs.
   Moyobamba), no se puede resolver solo con UI. Extendido además con
   modelo de costo/margen/piso de descuento.
4. ✅ Altas/bajas y negociación → PDF anual del proveedor, aviso proactivo
   ante cambios, carga exclusiva de admin/supervisor sin flujo de
   aprobación aparte.
5. ✅ Guías turísticos → **revisado 24-jul-2026**: se mantiene tabla propia
   `guias` (ya construida y conectada a `reserva_items.guia_id` en
   cotizaciones/reservas), no se migra a tipo de proveedor.
6. ✅ Proveedores internacionales/moneda extranjera → campo `moneda` en
   `proveedor_tarifas` + posible flag `es_internacional`.

### Pendientes puntuales que quedaron colgando de estas decisiones

- **Precio adulto/niño → RESUELTO.** Ya modelado en
  `plan-modulo-cotizaciones-reservas.md` (sección 2.2):
  `precio_venta_adulto`/`precio_venta_nino`/`precio_venta_infante` +
  `edad_min_nino`/`edad_max_nino`/`edad_max_infante` por tarifa.
- **Temporadas → RESUELTO 24-jul-2026.** Catálogo `temporadas` +
  `temporada_ocurrencias` aplicado en `cotizaciones-reservas.md`,
  reemplazando `fecha_inicio_vigencia`/`fecha_fin_vigencia`.
- **Piso de descuento → RESUELTO 24-jul-2026.** `descuento_maximo_pct` y
  `margen_minimo_pct` ya están en la estructura real de
  `proveedor_tarifas`, con validación en vivo al cotizar (ver modelo de
  descuento ágil, sesión del 24-jul).
- **Snapshot de precio en cotización → RESUELTO 24-jul-2026.**
  `alternativa_items` ahora separa `costo_snapshot` /
  `precio_venta_snapshot` / `descuento_pct` / `precio_convertido`,
  sincronizados bidireccionalmente (editar % o monto, el otro se
  recalcula), con validación del piso en vivo.
- **Cómo se ve el descuento en el PDF al cliente → RESUELTO
  24-jul-2026.** Configurable por agencia vía `configuracion_agencia`
  (`formato_descuento_pdf`, `mostrar_descuento_como_linea`) — no excluyente
  entre variantes, es solo plantilla sobre el mismo dato.

### Lo que sigue pendiente de verdad

Ninguno — los 3 quedaron resueltos el 24-jul-2026:

- **`temporadas`/`proveedor_tipos` en central, con columna `giro` —
  RESUELTO.** Ambos catálogos son exclusivos del vertical agencia de
  viajes, no universales como `tipos_comprobante` (que sí aplica a todo
  tenant). Se agrega `giro` a ambas tablas, mismo patrón ya usado en el
  catálogo `modulos` del módulo 11 (planes/acceso):
  ```
  proveedor_tipos (central)
    ...
    giro   -- 'agencia_viajes'

  temporadas (central)
    ...
    giro   -- 'agencia_viajes'
  ```
  Deja explícito en el dato mismo (no solo implícito por qué código se
  ejecuta) que estos catálogos no deben aparecer para tenants de otro
  giro (ej. abarrotes) — y permite que otro vertical futuro tenga su
  propio concepto de "temporadas" sin pisarse, filtrando por `giro`.

- **Guardar el PDF original del proveedor — RESUELTO.** Sí se guarda,
  como adjunto de la tarifa/temporada, para tener todo ordenado en el
  sistema en vez de buscar en carpetas de correo/WhatsApp. Estructura
  sugerida (mismo patrón de almacenamiento privado ya usado para
  documentos de pasajero en `cotizaciones-reservas.md`):
  ```
  proveedor_tarifa_documentos
    proveedor_tarifa_id  (o temporada_id, si aplica a varias tarifas del
                            mismo PDF anual)
    archivo               -- almacenamiento PRIVADO, acceso vía endpoint
                              autenticado, nunca link directo
    fecha_registro
  ```

- **Flag `es_internacional` en `proveedores` — DESCARTADO
  definitivamente.** No solo es redundante frente a
  `proveedor_tarifas.moneda`, sino que **modelaría mal** el caso real de
  un mayorista peruano con tarifas nacionales (soles) e internacionales
  (dólares) al mismo tiempo — un flag único por proveedor no puede
  describir un proveedor mixto. La moneda por tarifa individual ya
  resuelve esto sin necesidad de clasificar al proveedor completo.

## 4. Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 23-jul-2026 | Primera versión: documento de arranque para retomar en chat nuevo, recopila todo el contexto disperso de proveedores mencionado en otras sesiones (mapa de módulos, Fase 1, dependencias del módulo 11, guías turísticos). Ninguna decisión nueva tomada todavía — es punto de partida, no resultado. |
| 23-jul-2026 | Sesión de trabajo: se resolvieron las 6 preguntas abiertas originales (ver sección 2.6). Tipos de proveedor como catálogo central + config por tenant; vigencia de tarifas versionada más modelo de temporadas (catálogo + ocurrencias anuales); relación con destino_servicio vía `proveedor_servicios`, extendido con modelo de costo/margen/piso de descuento y snapshot de precio editable en cotización; altas/bajas vía PDF anual con carga exclusiva de admin/supervisor; guías turísticos como tipo de proveedor con tabla de disponibilidad; moneda extranjera con campo `moneda` y flag `es_internacional` propuesto. Quedan pendientes puntuales listados en sección 3. |
| 24-jul-2026 | Cruce con `plan-modulo-cotizaciones-reservas.md` (ese documento ya tenía su propio modelo maduro de `proveedor_tarifas`, del 22-jul, no coincidía en todo con lo decidido el 23-jul). Precio adulto/niño/infante: resuelto, ya estaba modelado ahí. Guías turísticos: se revierte la decisión del 23-jul — se mantiene tabla propia `guias` ya conectada, no se migra a tipo de proveedor. Tipos de proveedor: se confirma reemplazar el enfoque simple de cotizaciones (`tipo_proveedor: regular\|mayorista`) por el catálogo formal central. Destino: se confirma `proveedor_servicios` reemplazando el filtro no bloqueante que tenía cotizaciones, por caso real de transporte con precio distinto por destino. Quedan sin resolver: conflicto de modelo de temporadas (catálogo reutilizable vs. fecha libre por fila) y falta agregar el piso de descuento a la estructura real de `proveedor_tarifas` en cotizaciones. |
| 24-jul-2026 | Cierre de los dos pendientes restantes: se confirma catálogo `temporadas`+`temporada_ocurrencias` sobre fecha libre por fila (aplicado en `cotizaciones-reservas.md`); se agrega `descuento_maximo_pct`/`margen_minimo_pct` a la estructura real de `proveedor_tarifas` ahí mismo. Con esto queda cerrada la reconciliación completa entre ambos documentos. |
| 24-jul-2026 | Limpieza: se actualiza el estado de pendientes reflejando el modelo de descuento ágil resuelto en `cotizaciones-reservas.md` (snapshot costo/venta separado, descuento sincronizado %/monto, formato de PDF configurable por agencia). Quedan solo 3 pendientes reales: temporadas central vs. tenant, adjuntar PDF del proveedor como respaldo, y confirmar si hace falta el flag `es_internacional`. |
| 24-jul-2026 | Cierre de los 3 últimos pendientes: `proveedor_tipos`/`temporadas` llevan columna `giro='agencia_viajes'` (exclusivos del vertical, no universales como `tipos_comprobante`); se guarda el PDF original del proveedor como adjunto privado (`proveedor_tarifa_documentos`); se descarta definitivamente el flag `es_internacional` — un mayorista puede tener tarifas nacionales e internacionales simultáneamente, la `moneda` por tarifa ya lo resuelve sin clasificar al proveedor completo. **Módulo de Proveedores queda completo a nivel de modelo de datos.** |
| 24-jul-2026 | Se resuelve el bloqueante real detectado en `plan-modulo-maestros-iniciales.md`: `destino_servicio` (usado por `proveedor_servicios`) ahora se apoya en el árbol de 3 niveles y catálogo `servicios` definidos en `plan-modulo-tours-catalogo.md` — puede apuntar a cualquier nivel (zona/lugar/atractivo), no solo a un destino plano. Con esto, `proveedor_servicios` queda listo para implementarse de verdad, no solo en papel. |
| 25-jul-2026 | Se agrega `margen_default_tipo`/`margen_default_valor` a `proveedores` (aplica sobre todo a mayoristas) — permite calcular automáticamente el precio de venta de paquetes internacionales sin que el vendedor tenga que hacerlo a mano en Excel (ver detalle completo del flujo y la matriz de precios por hotel en `plan-modulo-cotizaciones-reservas.md` §2.4). |
| 03-ago-2026 | **Sesión ad-hoc de UX (no es fila de `plan-hoja-de-ruta-ejecucion.md`), rama `feature/ux-catalogo-proveedores-tours`, mergeada a `main` (`c15890d`).** Sin cambios de modelo de datos — todos los campos usados (`es_referencial`, `descuento_maximo_pct`, `edad_min_nino`/`edad_max_nino`/`edad_max_infante`) ya existían desde Sesiones 5/11b4a y ya estaban validados en `ProveedorTarifaController::validarPayload()`; solo `Proveedor.es_referencial` (Sesión 11b4a) le faltaba la línea en `ProveedorController::validarPayload()` — se descartaba en silencio pese a estar en `$fillable`, corregido. Trabajo puramente de interfaz sobre `admin-start-kit`: toggle "Solo referencial" en `proveedores/form.vue`; modal de tarifa (`proveedores/detalle.vue`) reorganizado en tabs Comercial/Tributario SUNAT, con cálculo bidireccional costo↔margen↔precio de venta (editar `margen_valor` recalcula los 3 precios de venta salvo que el vendedor ya haya tocado niño/infante a mano; editar precio adulto recalcula el margen solo), precarga de `edad_max_nino`/`edad_max_infante` desde `configuracion_agencia` como sugerencia editable (no fija), badge de margen resultante en vivo (umbral 20%, mismo criterio que el resto del vertical) y columnas Margen/Vigencia (Vigente hoy/Programada/Vencida) en la tabla de tarifas por servicio. `margen_minimo_pct` (ver §2.6 "Relación con destino_servicio, costo, margen y piso de descuento" arriba) queda deliberadamente sin campo en el formulario — decisión explícita de esta sesión, no hueco: el piso de descuento protegido se aplica hoy solo en `paquete_combo` (`preview.margenResultante`/`margenOk`, `paquetes/detalle.vue`), no existe todavía UI de piso por tarifa individual. Verificado con Playwright real contra `agencia-demo` (proveedor referencial creado y confirmado tras editar; 3 escenarios de cálculo bidireccional confirmados exactos; datos de prueba borrados al cerrar). **Gap preexistente re-confirmado, no corregido**: `esHotel` (`proveedores/detalle.vue`) sigue filtrando por `slug === 'hotel'`, pero el catálogo real usa `'alojamiento'` — la columna "Tipo de habitación" nunca aparece pese a que el proveedor de prueba (tipo Alojamiento) sí tenía tarifas con `tipo_habitacion` cargado. Mismo patrón exacto que el gap `'mayorista'`/`'agencia-mayorista'` ya documentado en `project_agencia_viajes_vertical_progreso.md` (memoria) — sugiere resolver ambos slugs juntos en una sesión dedicada. Ver también `plan-modulo-cotizaciones-reservas.md` (historial) para la mitad de esta sesión que tocó `paquetes/detalle.vue`. |
