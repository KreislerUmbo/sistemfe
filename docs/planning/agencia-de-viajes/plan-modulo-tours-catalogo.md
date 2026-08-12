# Sub-plan — Módulo 2: Catálogo de Destinos, Servicios y Tours

> Parte de: `plan-general-vertical-agencia-viajes.md` — Módulo 2
> Resuelve el bloqueante detectado en `plan-modulo-maestros-iniciales.md`:
> `proveedor_servicios` (ya diseñado en `plan-modulo-proveedores.md`)
> dependía de `destino_servicio`, que pertenece a este módulo — nunca se
> había definido a fondo, solo mencionado de pasada.
> Última actualización: 24-jul-2026 — v0.1 (primera versión, cerrada en
> una sola sesión con validación de datos reales)

---

## 0. Cómo usar este documento (si estás retomando esto en un chat nuevo)

1. Este documento completo
2. `plan-modulo-proveedores.md` §"Relación con destino_servicio" — usa
   directamente lo que se define acá
3. `plan-modulo-cotizaciones-reservas.md` §3.7 y §5.1/5.2 — ya actualizadas
   para reflejar las decisiones de este documento
4. Si necesitas más ejemplos reales de tours para validar algo, hay
   documentos originales de la agencia en Drive: "TOURS LAMAS NATIVO" y
   "FULL DAY ALTO MAYO", en la carpeta `Agencia de Viajes/`

## 1. Objetivo

Definir qué es un "destino", qué es un "servicio", y cómo se relacionan
con los tours/paquetes que vende la agencia — sin esto, `proveedor_servicios`
(ya diseñado en el módulo de Proveedores) no se podía terminar de construir,
porque dependía de una pieza sin definir.

## 2. Catálogo de destinos — árbol de 3 niveles

**Decisión validada con casos reales** (no solo teoría): un "destino" en
esta agencia no es un solo nivel de granularidad. Se necesitan 3, porque
un tour puede vender una **zona** amplia (Alto Mayo), que contiene
**lugares** específicos (Moyobamba, Rioja) donde cambia el precio del
transporte, y cada lugar puede tener **atractivos** puntuales (el
orquideario, la naciente de Tioyacu) donde cambia el precio de la
entrada — tres cosas con reglas de precio distintas, no una jerarquía
puramente descriptiva.

```
destinos_atractivos (autoreferenciada — una sola tabla para los 3 niveles)
 - id
 - parent_id   (nullable: null=zona, 1 nivel=lugar, 2 niveles=atractivo)
 - nombre
 - tipo         -- 'zona' | 'lugar' | 'atractivo'
 - descripcion
 - fotos (varias por registro)
```

### 2.1 Ejemplo real completo (Full Day Alto Mayo)

```
id:1  parent_id:null  "Alto Mayo"                 tipo:zona
id:2  parent_id:1     "Moyobamba"                 tipo:lugar
id:3  parent_id:2     "Orquideario"                tipo:atractivo
id:4  parent_id:2     "Plaza de Armas Moyobamba"    tipo:atractivo
id:5  parent_id:1     "Rioja"                       tipo:lugar
id:6  parent_id:5     "Tioyacu"                      tipo:atractivo
                                                        (naciente de agua)
```

### 2.2 Ejemplo real completo (Tours Lamas Nativo)

```
id:10 parent_id:null  "Lamas"                        tipo:lugar
                                                        (no necesita zona
                                                        padre — es un solo
                                                        pueblo, no una región
                                                        con varios lugares)
id:11 parent_id:10    "Plaza Mayor"                    tipo:atractivo
id:12 parent_id:10    "Museo Chanka"                    tipo:atractivo
id:13 parent_id:10    "Castillo de Lamas"                tipo:atractivo
id:14 parent_id:10    "Comunidad Nativa Kechwa del Wayku"  tipo:atractivo
```

**Nota:** no todo destino necesita los 3 niveles completos — Lamas no
tiene una "zona" padre porque no se vende como parte de una región más
amplia, solo como lugar único. El árbol es flexible, no obligatorio de
llenar hasta el fondo.

## 3. Catálogo de servicios (nuevo — no existía antes de esta sesión)

El "servicio" que ofrece un proveedor en un destino **no es lo mismo**
que el tipo de proveedor (Hotel/Transporte/Restaurante, ya definido en
`plan-modulo-proveedores.md`). Es más específico: el mismo proveedor de
transporte puede ofrecer servicios distintos hacia el mismo lugar, cada
uno con tarifa propia.

```
servicios (catálogo reutilizable)
 - id
 - nombre             (ej. "Traslado ida y vuelta", "Traslado
                        aeropuerto-hotel", "City tour medio día",
                        "Full day", "Hospedaje", "Entrada/Boleto")
 - tipo_proveedor_id   (opcional — filtra qué servicios tienen sentido
                         según el tipo de proveedor, ej. "Hospedaje" no
                         aplica a Transporte)
```

**Caso real que originó esta decisión:** Transportes San Martín cobra
S/90 por "Traslado ida y vuelta" (el que va incluido dentro de un full
day) y S/25 por "Traslado aeropuerto-hotel" (servicio suelto para una
reserva de solo hotel) — mismo proveedor, mismo destino, dos servicios
distintos con tarifas distintas. Si "servicio" fuera igual a "tipo de
proveedor" (solo "Transporte"), no se podría distinguir entre ambos.

## 4. `destino_servicio` — la tabla puente que cruza ambos catálogos

```
destino_servicio
 - id
 - destino_id    → destinos_atractivos.id, CUALQUIER nivel del árbol
                    (zona, lugar o atractivo — no restringido a uno solo)
 - servicio_id    → servicios.id
```

**Por qué no se restringe a un solo nivel:** el transporte normalmente se
cotiza a nivel **lugar** (Moyobamba vs. Rioja, caso de la sección 2.1),
pero las entradas a atractivos se cotizan a nivel **atractivo** (entrada
al orquideario ≠ entrada a otro atractivo dentro del mismo lugar) — ambos
casos son reales, así que `destino_id` debe poder apuntar a cualquiera de
los 3 niveles según el tipo de servicio.

### 4.1 Cómo se conecta con Proveedores (ya diseñado, sin cambios ahí)

```
proveedor_servicios (definida en plan-modulo-proveedores.md)
 - proveedor_id
 - destino_servicio_id → esta tabla

proveedor_tarifas
 - proveedor_servicio_id → proveedor_servicios.id
 - ... (costo, margen, piso de descuento, temporada, moneda — sin cambio)
```

Ejemplo completo aplicando el caso real:
```
destino_servicio #1: Moyobamba (lugar) + "Traslado ida y vuelta"
  → proveedor_servicios: Transportes San Martín
  → proveedor_tarifas: costo S/90

destino_servicio #2: Moyobamba (lugar) + "Traslado aeropuerto-hotel"
  → proveedor_servicios: Transportes San Martín (mismo proveedor)
  → proveedor_tarifas: costo S/25

destino_servicio #3: Orquideario (atractivo, dentro de Moyobamba) + "Entrada/Boleto"
  → proveedor_servicios: (el operador del orquideario, si aplica, o la
                           agencia misma si compra las entradas directo)
  → proveedor_tarifas: costo S/15
```

## 5. Tours = `paquetes_plantilla` (confirmado, no son dos entidades)

Antes de esta sesión quedaba la duda de si "tour" (usado en
`tour_itinerario_items.tour_id`) y `paquetes_plantilla` (el catálogo
vendible ya diseñado en `cotizaciones-reservas.md` §3.7) eran la misma
tabla o dos distintas. **Se confirma que son la misma entidad**, validado
con los documentos reales de tours de la agencia — que traen ficha
completa (duración, horarios, lugar de recojo, incluye/no incluye,
recomendaciones), exactamente lo que le faltaba a `paquetes_plantilla`.

```
paquetes_plantilla (= "tour", una sola entidad)
 - nombre, descripción, fotos
 - destino_atractivo_id (zona o lugar principal del tour)
 - duracion_horas
 - hora_salida / hora_retorno
 - lugar_recojo            (texto, ej. "Hoteles ubicados dentro de la ciudad")
 - no_incluye               (texto/lista)
 - recomendaciones           (texto/lista)
 - items_incluidos        (1+ servicios/tarifas con precio ya fijado — esto
                            genera el "Incluye" del PDF automáticamente,
                            NO es texto libre)
 - precio_venta_final
 - vigencia_desde / vigencia_hasta
 - publicado_web

tour_itinerario_items
 - tour_id → paquetes_plantilla.id
 - dia_relativo (1, 2, 3...)
 - hora    (nullable — no todo tour trae hora exacta por parada)
 - orden    (NUEVO — secuencia de actividades del día cuando no hay hora)
 - destino_atractivo_id   (cualquier nivel: zona/lugar/atractivo)
 - descripción de la actividad
```

### 5.1 Ejemplo real completo — Full Day Alto Mayo

```
paquetes_plantilla:
  nombre: "Full Day Alto Mayo"
  destino_atractivo_id: 1 ("Alto Mayo", zona)
  duracion_horas: 12
  hora_salida: 07:30  |  hora_retorno: 19:30
  lugar_recojo: "Hoteles ubicados dentro de la ciudad"
  no_incluye: "Gastos extras, actividades extras, propinas, bebidas,
               otros no especificados"
  recomendaciones: "Ropa ligera y de cambio, ropa de baño, sandalias o
                     zapatos de agua, toalla, repelente, bloqueador
                     solar, gorra, agua"
  items_incluidos: [Traslado ida y vuelta, Guía de Turismo,
                     Almuerzo típico, Entradas]

tour_itinerario_items (día único, dia_relativo=1, secuenciado por orden):
  orden:1  destino_atractivo_id: 2 (Moyobamba, lugar) — "Visita orquideario"
  orden:2  destino_atractivo_id: 6 (Tioyacu, atractivo) — "Baño en la naciente"
  orden:3  destino_atractivo_id: 2 (Moyobamba, lugar) — "Almuerzo típico + Plaza de Armas + café"
  orden:4  descripción: "Retorno a Tarapoto"
```

### 5.2 Ejemplo real completo — Tours Lamas Nativo

```
paquetes_plantilla:
  nombre: "Tours Lamas Nativo"
  destino_atractivo_id: 10 ("Lamas", lugar)
  duracion_horas: 4
  hora_salida: 15:00  |  hora_retorno: 19:00
  lugar_recojo: "Hoteles ubicados dentro de la ciudad"
  items_incluidos: [Traslado ida y vuelta, Guía de Turismo,
                     Boletos y Entradas]

tour_itinerario_items:
  orden:1  destino_atractivo_id: 11 (Plaza Mayor) — "Visita plaza mayor"
  orden:2  destino_atractivo_id: 12 (Museo Chanka) — "Visita museo"
  orden:3  destino_atractivo_id: 13 (Castillo de Lamas) — "Visita castillo"
  orden:4  destino_atractivo_id: 14 (Comunidad Wayku) — "Danzas típicas en vivo"
```

## 6. Pendiente no bloqueante

- **RESUELTO 29-jul-2026** (Sesión 11b2 — `TourItinerarioItemController`):
  confirmado con la propia base de datos, no solo teoría —
  `destino_atractivo_id` nullable funciona sin problema para pasos
  puramente de traslado/cierre. Verificado con el mismo caso del ejemplo
  Alto Mayo ("Retorno a Tarapoto", sin atractivo asociado).
- **RESUELTO 25-jul-2026** (ver `plan-modulo-cotizaciones-reservas.md`
  §5.3): "Guía de Turismo" en `items_incluidos` **sí necesita tarifa
  propia** — se agrega `guia_tarifas` (costo/margen por guía × destino ×
  modalidad), no se queda como tabla `guias` simple sin precio. Los
  guías son freelance (no exclusivos), así que no se controla su
  calendario completo, solo quién está asignado.

## 7. Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 24-jul-2026 | Primera versión: se resuelve el bloqueante detectado en `plan-modulo-maestros-iniciales.md`. Árbol de destinos de 3 niveles (zona/lugar/atractivo), catálogo `servicios` nuevo, `destino_servicio` como puente que puede apuntar a cualquier nivel. Confirmado que "tour" y `paquetes_plantilla` son la misma entidad. Todo validado con documentos reales de tours de la agencia (Full Day Alto Mayo, Tours Lamas Nativo) en vez de solo teoría. Se actualizan `plan-modulo-cotizaciones-reservas.md` (§3.7, §5.1, §5.2) y `plan-modulo-proveedores.md` (nota sobre destino_servicio) para reflejar esto. |
| 25-jul-2026 | Se cierra el pendiente sobre "Guía de Turismo" en `items_incluidos`: sí necesita tarifa propia (`guia_tarifas`, ver `plan-modulo-cotizaciones-reservas.md` §5.3), confirmado que varía por destino y modalidad (día local vs. grupo multidía a otra región). |
| 12-ago-2026 | **§4 extendida — corregir una fila `destino_servicio` mal asociada, sin perder proveedores.** El diseño original de §4 no contemplaba el caso de un servicio asociado al destino equivocado con `proveedor_servicios`/tarifas ya reales enganchados (el guard de integridad bloqueaba correctamente el borrado, pero no daba salida para corregir). Se agregan dos endpoints sobre `DestinoServicioController` (rama `fix/destino-servicio-mover-y-catalogo-servicios`, mergeada a `main` en `62f8b69`): `mover()` (reasigna `destino_atractivo_id`, bloquea 422 si el destino elegido ya tiene ese servicio) y `fusionar()` (para cuando sí lo tiene — reasigna los `proveedor_servicios` de la fila origen a la fila destino existente y borra la origen; si el mismo proveedor está en ambas, no fusiona nada y lo nombra explícito, resuelve una persona a mano). De paso, `ServicioController::index()` gana `per_page` (antes truncaba siempre a 15 en el selector del modal de §3, escondiendo el resto del catálogo). Detalle completo en `CLAUDE.md` y `docs/planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md` (historial, entrada 12-ago-2026). |
| 29-jul-2026 | **Sesión 11b2 — CRUD admin construido** (`feature/sesion-11b2-paquetes-plantilla`): `PaquetePlantillaController`/`PaquetePlantillaItemController`/`TourItinerarioItemController`, pantallas en `admin-start-kit` (`/agencia-viajes/paquetes`). Cierra el hueco real que había quedado en la hoja de ruta desde Sesión 6 (tablas/modelos sin API/pantalla propia). Resuelve a nivel de aplicación la regla "uno de `proveedor_tarifa_id`/`guia_tarifa_id`, nunca ambos ni ninguno" que la migración de Sesión 6 había dejado pendiente. Ver `TODO.md` para el detalle completo, incluido un bug real de `codigo` sin validar `unique()` (500 crudo en vez de 422). Pendiente explícito, ya reservado como fila propia (11b3): conectar esto al cotizador ("Cargar desde plantilla"). |
