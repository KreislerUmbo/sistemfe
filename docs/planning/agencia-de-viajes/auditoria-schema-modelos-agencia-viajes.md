# Auditoría factual — Schema + Modelos Eloquent del vertical "Agencia de Viajes"

> **Naturaleza de este documento:** relevamiento FACTUAL en bruto (tablas, columnas, FKs,
> casts, relaciones Eloquent — con citas textuales de comentarios de migraciones/modelos),
> sin opiniones ni clasificaciones. Es insumo de la auditoría arquitectónica pedida en la
> sesión del 31-ago-2026 (ver `plan-refactor-mayoristas-tramos.md` para el contexto de
> negocio que la disparó). Documento hermano:
> `auditoria-controllers-services-flujo-cotizacion-reserva.md` (lógica de negocio). El
> análisis/síntesis/clasificación ([CONSERVAR]/[REFACTORIZAR]/[EXTENDER]/[REEMPLAZAR]/
> [NUEVO]/[ELIMINAR]) se hace en un documento aparte — este es el material crudo para que
> cualquier agente/persona lo pueda auditar de forma independiente.
>
> Generado por un agente de exploración de código (read-only) sobre
> `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/**/*.php` (96
> archivos, todas planas en ese directorio, sin subcarpetas `core/`) +
> `api-sistema-fe/app/Models/AgenciaViajes/*.php` (48 modelos). Todos los paths son
> absolutos bajo `c:\xampp\htdocs\sistemfe\api-sistema-fe\`.

Nota general repetida en casi toda migración: todas viven en la conexión tenant normal
(sin `$connection`), salvo 4 modelos que usan trait `CentralConnection` (`ProveedorTipo`,
`Amenidad`, `Temporada`, `TemporadaOcurrencia`) — catálogos centrales exclusivos del giro
`agencia_viajes` (columna `giro`), referenciados desde tablas tenant SIN FK real de
Postgres (cross-DB), ej. `proveedores.tipo_id`, `servicios.tipo_proveedor_id`,
`proveedor_tarifas.temporada_id`, `proveedor_amenidad.amenidad_id`.

---

## 1. COTIZACIÓN

### Tabla `cotizaciones` — Modelo `Cotizacion`
Columnas:
- `id` PK
- `codigo_prefijo` string, NOT NULL
- `codigo` string, UNIQUE, NOT NULL
- `cliente_id` FK → `clients` (NOT NULL)
- `destino` string, NOT NULL (texto libre, "no es lo mismo que destino_atractivo_id")
- `fecha_viaje_desde` date, nullable (reemplazó `fecha_viaje_tentativa` en migración `2026_07_29_090000_replace_fecha_viaje_tentativa_con_rango`)
- `fecha_viaje_hasta` date, nullable
- `reservas_generadas` unsignedInteger, default 0 (add `2026_08_26_160300`)
- `timestamps`

FK: `cliente_id → clients.id` (real, core `App\Models\Client\Client`).

Comentario textual clave (migración): *"codigo: calculado {codigo_prefijo}-{año}-{correlativo}, correlativo propio POR PREFIJO (no global) — se resuelve en App\Models\AgenciaViajes\Cotizacion ... NO como columna generada de Postgres"*. Actualizado luego (modelo, comentario 26-ago-2026): *"Eso se retiró: ahora cada caller ... pide su código explícitamente a App\Services\AgenciaViajes\CodigoGeneradorService::generar(...) ANTES de Cotizacion::create()"*.

Modelo `Cotizacion`:
- `$fillable`: `codigo_prefijo, codigo, reservas_generadas, cliente_id, destino, fecha_viaje_desde, fecha_viaje_hasta`
- `$casts`: `fecha_viaje_desde => date`, `fecha_viaje_hasta => date`
- Relaciones: `cliente()` belongsTo `Client::class` (`cliente_id`); `pasajeros()` hasMany `CotizacionPasajero::class` (`cotizacion_id`); `alternativas()` hasMany `Alternativa::class` (`cotizacion_id`)

### Tabla `cotizacion_pasajeros` — Modelo `CotizacionPasajero`
Columnas:
- `id` PK
- `cotizacion_id` FK → `cotizaciones` NOT NULL
- `tipo_pax` string NOT NULL — `'adulto' | 'nino' | 'infante'`
- `edad` unsignedTinyInteger NOT NULL
- `timestamps`

Comentario clave: *"edad: OBLIGATORIA (NOT NULL) — es la fuente de verdad para el precio en alternativa_items ... no tipo_pax. tipo_pax se sugiere automáticamente desde la edad ... pero queda como columna real editable, no recalculada en cada lectura."*

Modelo: `$fillable`: `cotizacion_id, tipo_pax, edad`; `$casts`: `edad => integer`; relación `cotizacion()` belongsTo `Cotizacion::class`.

### Tabla `cotizacion_pasaje_aereo` — Modelo `CotizacionPasajeAereo`
Columnas:
- `id` PK
- `alternativa_item_id` FK → `alternativa_items`, UNIQUE, NOT NULL (1-a-1)
- `aerolinea` string NOT NULL (texto libre, sin FK)
- `itinerario` text nullable
- `moneda` string NOT NULL — `'PEN'|'USD'`
- `tarifa_base_adulto` decimal(10,2) NOT NULL
- `tarifa_base_nino` decimal(10,2) nullable
- `tarifa_base_infante` decimal(10,2) nullable
- `cargos` json nullable — `[{codigo, nombre, monto, tipo: impuesto|tasa_aeropuerto|fee_agencia}]`
- `tua_incluida_en_tarifa` boolean default false
- `fee_agencia_monto` decimal(10,2) default 0
- `tip_afe_igv` string(2) nullable
- `fecha_cotizado` timestamp NOT NULL
- `costo_total` decimal(10,2) NOT NULL
- `precio_venta_total` decimal(10,2) NOT NULL
- `timestamps`

Comentario clave: *"tip_afe_igv: aplica SOLO sobre fee_agencia_monto (lo único que la agencia vende como servicio propio) — tarifa_base + cargos son pass-through de costo de terceros"* y *"costo_total/precio_venta_total: calculados por PriceEngineService pero PERSISTIDOS, no recalculados al vuelo en cada request."*

Modelo: `$fillable`: `alternativa_item_id, aerolinea, itinerario, moneda, tarifa_base_adulto, tarifa_base_nino, tarifa_base_infante, cargos, tua_incluida_en_tarifa, fee_agencia_monto, tip_afe_igv, fecha_cotizado, costo_total, precio_venta_total`; `$casts`: decimales varios `decimal:2`, `cargos => array`, `tua_incluida_en_tarifa => boolean`, `fecha_cotizado => datetime`; relación `alternativaItem()` belongsTo `AlternativaItem::class`.

---

## 2. ALTERNATIVA / ALTERNATIVA ITEM

### Tabla `alternativas` — Modelo `Alternativa`
Columnas base + retrofits acumulados:
- `id` PK
- `cotizacion_id` FK → `cotizaciones` NOT NULL
- `nombre` string NOT NULL
- `estado` string default `'borrador'` — `'borrador'|'enviada'|'aceptada'|'descartada'`
- `moneda_cotizacion` string NOT NULL — `'PEN'|'USD'`
- `tipo_cambio_aplicado` decimal(10,4) NOT NULL (snapshot)
- `tipo_cambio_origen` string NOT NULL — `'dia'|'agencia'`
- `fecha_envio` timestamp nullable
- `fecha_vencimiento` timestamp nullable
- `descuento_global_pct` decimal(5,2) nullable
- `total` decimal(10,2) default 0
- `timestamps`

Comentarios clave: *"Máximo 5 alternativas por cotización es regla de negocio ... NO constraint de BD."*; *"total: mantenido por aplicación, recalculado al guardar cada alternativa_item — no trigger de BD."*

Modelo `$fillable`: todos los anteriores excepto id/timestamps. `$casts`: `tipo_cambio_aplicado => decimal:4`, `fecha_envio/fecha_vencimiento => datetime`, `descuento_global_pct => decimal:2`, `total => decimal:2`.

Relaciones:
- `cotizacion()` belongsTo `Cotizacion::class`
- `items()` hasMany `AlternativaItem::class` (`alternativa_id`) — **con `orderBy('id')` explícito**. Comentario textual: *"sin esto, Postgres no garantiza el orden de fila sin ORDER BY, y un UPDATE (ej. editar precio) puede reubicar físicamente la fila — el ítem 'saltaba' de posición en el lienzo del cotizador justo después de guardar su precio (bug real reportado por el usuario 2026-08-28)."*
- `opcionMayoristaElegida()` hasOne `OpcionMayorista::class` (`alternativa_id`) `->where('estado','elegida')`. Comentario: *"No hay CHECK ni índice único que garantice como máximo una 'elegida' por alternativa — la garantía la da OpcionMayoristaController::elegir() (desmarca la anterior antes de marcar la nueva)."*

### Tabla `alternativa_items` — Modelo `AlternativaItem`
Columnas acumuladas (create + 8 migraciones ALTER):
- `id` PK
- `alternativa_id` FK → `alternativas` NOT NULL
- `proveedor_tarifa_id` FK → `proveedor_tarifas` nullable
- `opcion_mayorista_id` FK → `opcion_mayorista` nullable (retrofit cerrado en `2026_07_28_100300`)
- `opcion_hotel_tarifa_id` FK → `opciones_hotel_tarifas` nullable, `nullOnDelete` (add `2026_08_04_090000`) — **"quedaron muertas — ningún código las escribe ya"** según comentario del modelo (post consolidación de hoteles)
- `paquete_plantilla_id` FK → `paquetes_plantilla` nullable, `nullOnDelete` (add `2026_08_04_090000`) — misma nota de columna muerta
- `guia_tarifa_id` FK → `guia_tarifas` nullable, `nullOnDelete` (add `2026_08_11_090000`)
- `tour_origen_id` FK → `paquetes_plantilla` nullable (add `2026_07_30_110200`)
- `dia_referencial` unsignedSmallInteger nullable (add `2026_08_01_150452`)
- `modo_precio` string NOT NULL — `'por_persona'|'tarifa_fija'`
- `pax_incluidos` json nullable — array de `cotizacion_pasajeros.id`
- `moneda_costo` string NOT NULL
- `costo_snapshot` decimal(10,2) NOT NULL
- `precio_venta_snapshot` decimal(10,2) NOT NULL
- `descuento_pct` decimal(5,2) nullable
- `precio_convertido` decimal(10,2) NOT NULL
- `origen_tipo` string NOT NULL (retrofit `2026_07_28_180000`, backfill 2 pasos nullable→NOT NULL) — `'proveedor'|'mayorista'|'pasaje_aereo'|'manual'|'guia'` (constantes en modelo)
- `cantidad` unsignedInteger default 1 (retrofit `2026_07_28_180000`)
- `descripcion_manual` text nullable
- `proveedor_sugerido_manual` string(250) nullable (add `2026_08_12_090000`)
- `proveedor_promovido_id` FK → `proveedores` nullable, `nullOnDelete` (add `2026_08_12_090000`)
- `tip_afe_igv` string(2) nullable (add `2026_08_28_090000`)
- `destino_tributario` string nullable (add `2026_08_28_090000`)
- `timestamps`

Comentarios clave:
- *"proveedor_tarifa_id es nullable — un ítem internacional/manual no siempre viene de una tarifa registrada"*
- *"pax_incluidos: null/vacío = todos los pasajeros de la cotización por defecto"*
- *"precio_minimo_permitido NO es columna — se calcula en aplicación (mayor entre proveedor_tarifas.descuento_maximo_pct y proveedor_tarifas.margen_minimo_pct)"*
- origen_tipo backfill: *"proveedor_tarifa_id lleno → 'proveedor', opcion_mayorista_id lleno → 'mayorista'. Las filas SIN ninguna de las 2 FK llena ... quedan como 'proveedor'"*
- `cantidad`: *"default 1 desde el arranque — no rompe ningún total ya calculado"*

Modelo `AlternativaItem`:
- Constantes: `ORIGEN_PROVEEDOR='proveedor'`, `ORIGEN_MAYORISTA='mayorista'`, `ORIGEN_PASAJE_AEREO='pasaje_aereo'`, `ORIGEN_MANUAL='manual'`, `ORIGEN_GUIA='guia'`, array `ORIGENES`
- `$fillable`: `alternativa_id, origen_tipo, proveedor_tarifa_id, opcion_mayorista_id, guia_tarifa_id, tour_origen_id, dia_referencial, descripcion_manual, proveedor_sugerido_manual, proveedor_promovido_id, modo_precio, cantidad, pax_incluidos, moneda_costo, costo_snapshot, precio_venta_snapshot, descuento_pct, precio_convertido, tip_afe_igv, destino_tributario`
- `$casts`: `pax_incluidos => array`, `cantidad => integer`, `dia_referencial => integer`, `costo_snapshot/precio_venta_snapshot/descuento_pct/precio_convertido => decimal:2`
- `$appends`: `['total', 'total_convertido']`
- Relaciones: `alternativa()` belongsTo `Alternativa::class`; `proveedorTarifa()` belongsTo `ProveedorTarifa::class`; `opcionMayorista()` belongsTo `OpcionMayorista::class`; `guiaTarifa()` belongsTo `GuiaTarifa::class`; `tourOrigen()` belongsTo `PaquetePlantilla::class` (`tour_origen_id`); `cotizacionPasajeAereo()` hasOne `CotizacionPasajeAereo::class`; `reservaItem()` hasOne `ReservaItem::class` (`alternativa_item_id`); `proveedorPromovido()` belongsTo `Proveedor::class` (`proveedor_promovido_id`)
- Accessors: `getTotalAttribute()` — *"solo modo_precio='tarifa_fija' multiplica por cantidad — 'por_persona' ya viene resuelto en precio_venta_snapshot ... multiplicar de nuevo sería duplicar el cálculo. Sesión 11q: 'manual' dejó de ser una excepción"*; `getTotalConvertidoAttribute()` análogo sobre `precio_convertido`.

---

## 3. OPCION MAYORISTA / OPCION HOTEL / SALIDA MAYORISTA

### Tabla `salidas_mayorista` — Modelo `SalidaMayorista`
Columnas:
- `id` PK
- `proveedor_id` FK → `proveedores` NOT NULL
- `nombre` string NOT NULL
- `fecha_salida` date NOT NULL
- `fecha_retorno` date NOT NULL
- `cupo_total` unsignedSmallInteger nullable
- `cupo_ocupado` unsignedSmallInteger default 0
- `precio_costo` decimal(10,2) nullable
- `moneda` string nullable
- `incluye` text nullable
- `estado` string default `'disponible'` — `'disponible'|'agotado'|'cancelado'`
- `timestamps`

Comentario: *"cupo_ocupado: control interno ... no trigger de BD, solo informativo, no bloquea vender de más."*

Modelo: `$fillable` = todas las anteriores; `$casts`: `fecha_salida/fecha_retorno => date`, `precio_costo => decimal:2`. Relaciones: `proveedor()` belongsTo `Proveedor::class`; `opcionesMayorista()` hasMany `OpcionMayorista::class` (`salida_mayorista_id`).

**Nota transversal (confirmada por el usuario en sesión previa):** no existe ningún controller/ruta que escriba en `salidas_mayorista` — el catálogo está diseñado pero no hay forma de crear una fila desde el frontend, por lo que `salida_mayorista_id` en `opcion_mayorista` queda siempre null en la práctica.

### Tabla `opcion_mayorista` — Modelo `OpcionMayorista`
Columnas:
- `id` PK
- `alternativa_id` FK → `alternativas` NOT NULL
- `proveedor_id` FK → `proveedores` NOT NULL (tipo "mayorista")
- `salida_mayorista_id` FK → `salidas_mayorista` nullable
- `moneda` string NOT NULL — `'PEN'|'USD'`
- `incluye` text nullable
- `notas` text nullable
- `vuelo_aerolinea` string nullable
- `vuelo_detalle` text nullable
- `estado` string default `'candidata'` — `'candidata'|'elegida'|'descartada'`
- `timestamps`

Modelo: `$fillable` = todas las anteriores (sin casts declarados). Relaciones: `alternativa()` belongsTo; `proveedor()` belongsTo; `salidaMayorista()` belongsTo; `opcionales()` hasMany `OpcionMayoristaOpcional::class`; `opcionesHotel()` hasMany `OpcionHotel::class` (`opcion_mayorista_id`); `alternativaItems()` hasMany `AlternativaItem::class` (`opcion_mayorista_id`).

### Tabla `opcion_mayorista_opcionales` — Modelo `OpcionMayoristaOpcional`
Columnas:
- `id` PK
- `opcion_mayorista_id` FK → `opcion_mayorista` NOT NULL
- `nombre` string NOT NULL
- `precio_por_persona` decimal(10,2) NOT NULL
- `moneda` string NOT NULL
- `incluye` text nullable
- `no_incluye` text nullable
- `timestamps`

Comentario: *"Nunca se suman automáticamente al total (regla de aplicación, Sesión 11) — el cliente debe pedirlo explícito. No modelado acá, a propósito."*

Modelo: `$fillable` = todas; `$casts`: `precio_por_persona => decimal:2`. Relación: `opcionMayorista()` belongsTo.

### Tabla `opciones_hotel` — Modelo `OpcionHotel`
Columnas (create + alters, incl. drop):
- `id` PK
- `opcion_mayorista_id` FK → `opcion_mayorista` nullable (retrofit cerrado `2026_07_28_100400`)
- ~~`paquete_plantilla_id`~~ — **DROPEADA** en `2026_08_11_090500_drop_paquete_plantilla_id_from_opciones_hotel_table` (consolidación de hoteles; verificado 0 filas reales)
- `proveedor_id` FK → `proveedores` nullable
- `nombre_hotel` string NOT NULL
- `categoria_estrellas` unsignedTinyInteger nullable
- `moneda` string(3) default `'PEN'` (add `2026_08_04_090100`)
- `edad_max_infante_gratis` unsignedTinyInteger default 4 (add `2026_08_06_090000`)
- `edad_max_nino_cama_adicional` unsignedTinyInteger default 12 (add `2026_08_06_090000`)
- `timestamps`

Comentario clave (drop): *"un hotel deja de poder atarse a un paquete_plantilla puntual ... ahora es una tarifa más de proveedor_tarifas ... opciones_hotel/opciones_hotel_tarifas se mantienen exclusivamente para opcion_mayorista"*.

Modelo: `$fillable`: `opcion_mayorista_id, proveedor_id, nombre_hotel, categoria_estrellas, moneda, edad_max_infante_gratis, edad_max_nino_cama_adicional`. Relaciones: `proveedor()` belongsTo; `opcionMayorista()` belongsTo; `opcionesHotelTarifas()` hasMany `OpcionHotelTarifa::class`.

### Tabla `opciones_hotel_tarifas` — Modelo `OpcionHotelTarifa`
Columnas:
- `id` PK
- `opcion_hotel_id` FK → `opciones_hotel` NOT NULL
- `tipo_habitacion` string NOT NULL — `'matrimonial'|'doble'|'triple'|'familiar'`
- `precio_costo` decimal(10,2) NOT NULL
- `precio_venta` decimal(10,2) NOT NULL
- `proveedor_tarifa_id` FK → `proveedor_tarifas` nullable, `nullOnDelete` (add `2026_08_04_090100`)
- `precio_costo_cama_adicional` decimal(10,2) nullable (add `2026_08_06_090000`)
- `precio_venta_cama_adicional` decimal(10,2) nullable (add `2026_08_06_090000`)
- `timestamps`

Modelo: `$fillable` = todas anteriores; `$casts`: `precio_costo, precio_venta, precio_costo_cama_adicional, precio_venta_cama_adicional => decimal:2`.
Relaciones: `opcionHotel()` belongsTo; `proveedorTarifa()` belongsTo `ProveedorTarifa::class`.
**Accessors con lógica de negocio**: `getPrecioCostoAttribute($value)` y `getPrecioVentaAttribute($value)` — comentario textual: *"Precio 'en vivo': mientras haya una tarifa real vinculada, el precio mostrado es el de esa tarifa, no el valor guardado en esta fila ... nunca se muestra un valor stale si hay una fuente de verdad viva."* → `return $this->proveedorTarifa?->precio_costo ?? $value;` / `return $this->proveedorTarifa?->precio_venta_adulto ?? $value;` (**nótese que sobreescribe el atributo de BD en tiempo de lectura**).

---

## 4. PROVEEDOR / PROVEEDOR TIPO / PROVEEDOR SERVICIO / PROVEEDOR TARIFA / ALOJAMIENTO

### Tabla `proveedores` — Modelo `Proveedor`
Columnas acumuladas:
- `id` PK
- `codigo` string(20) UNIQUE nullable
- `razon_social` string(250) NOT NULL
- `nombre_comercial` string(250) nullable
- `descripcion` text nullable (add `2026_08_11_090100`)
- `tipo_persona` string(20) nullable
- `tipo_documento` string(20) nullable
- `numero_documento` string(30) nullable
- `direccion` text nullable
- `pais_id` unsignedBigInteger nullable — **sin FK real** (no existe catálogo ubigeo)
- `departamento_id` unsignedBigInteger nullable — sin FK real
- `provincia_id` unsignedBigInteger nullable — sin FK real
- `distrito_id` unsignedBigInteger nullable — sin FK real
- `telefono` string(50) nullable
- `celular` string(50) nullable
- `whatsapp` string(50) nullable
- `email` string(150) nullable
- `pagina_web` string(250) nullable
- `facebook` string(250) nullable
- `instagram` string(250) nullable
- `tiktok` string(250) nullable
- `linkedin` string(250) nullable
- `logo` string(300) nullable
- `fotos` json nullable (add `2026_08_11_090100`)
- `observaciones` text nullable
- `estado` boolean default true
- `tipo_id` unsignedBigInteger nullable — `proveedor_tipos.id` (CENTRAL) sin FK real cross-DB
- `margen_default_tipo` string nullable — `'porcentaje'|'fijo'`
- `margen_default_valor` decimal(10,2) nullable
- `es_referencial` boolean default false (add `2026_07_30_110400`)
- `timestamps`

Comentario clave: *"margen_default_tipo/margen_default_valor: agregados 25-jul-2026 ('Margen automático por mayorista') — precio de costo del mayorista sin proveedor_tarifa registrada, margen aplicado automático, editable línea por línea si la negociación puntual difiere."* (**confirmado en sesión previa: este campo se guarda pero NO se lee en ningún punto del flujo real de carga de tarifa de hotel del mayorista — gap ya documentado**); *"es_referencial: representa el precio de lista de la agencia cuando todavía no se sabe qué empresa/persona específica va a operar el servicio ... NOTA DE ALCANCE: esta migración solo agrega el flag. El bloqueo duro 'no se puede marcar un pago como realizado contra un proveedor/guía referencial' ... NO se implementan en esta sesión"*.

Modelo `Proveedor`: `$fillable` = lista completa arriba (incluye `descripcion`, `fotos`, `es_referencial`); `$casts`: `estado => boolean`, `margen_default_valor => decimal:2`, `es_referencial => boolean`, `fotos => array`.
Relaciones: `proveedorServicios()` hasMany `ProveedorServicio::class`; `alojamientoDetalle()` hasOne `ProveedorAlojamientoDetalle::class`; método `amenidades()` — **NO es relación Eloquent real**, comentario: *"NO es un belongsToMany real a propósito: Eloquent arma el JOIN contra la tabla pivote usando la conexión del modelo RELACIONADO (Amenidad, central), pero proveedor_amenidad vive en la BD del tenant — el JOIN buscaría la tabla en la base equivocada. Resuelto en dos pasos (ids del pivote, tenant → Amenidad::whereIn, central)"* — implementación manual vía `DB::table('proveedor_amenidad')->...pluck('amenidad_id')` luego `Amenidad::whereIn('id', $amenidadIds)->get()`.

### Tabla `proveedor_tipos` (CENTRAL) — Modelo `ProveedorTipo`
Usa `CentralConnection`. Columnas (no migración en este vertical, catálogo central de Sesión 1 — no listada acá pero referenciada):
Modelo: `protected $table = 'proveedor_tipos'`; constantes `SLUG_MAYORISTA = 'agencia-mayorista'`, `SLUG_ALOJAMIENTO = 'alojamiento-hoteles'`; `$fillable`: `nombre, slug, giro, activo`; `$casts`: `activo => boolean`. Sin relaciones declaradas.

Comentario textual importante (bug real documentado): *"SLUG_ALOJAMIENTO... Bug real encontrado 2026-08-28: producción tenía el código de check-in/check-out/tipo de habitación desplegado, pero la UI quedaba oculta porque esta comparación nunca daba true ahí"* (por mismatch entre slug generado por seeder vs slug editado a mano en producción).

### Tabla `proveedor_tipos_config` — Modelo `ProveedorTipoConfig`
Columnas:
- `id` PK
- `proveedor_tipo_id` unsignedBigInteger nullable — sin FK real cross-DB
- `habilitado` boolean default true
- `timestamps`

Comentario: *"deshabilitar un tipo solo oculta la opción al crear proveedores nuevos, nunca afecta proveedores ya existentes"*; *"Se crea VACÍA a propósito en esta sesión — el sembrado automático al provisionar ... queda pendiente"*.

Modelo: `$fillable`: `proveedor_tipo_id, habilitado`; `$casts`: `habilitado => boolean`. Sin relaciones.

### Tabla `proveedor_servicios` — Modelo `ProveedorServicio`
Columnas:
- `id` PK
- `proveedor_id` FK → `proveedores` NOT NULL
- `destino_servicio_id` FK → `destino_servicio` NOT NULL
- `timestamps`

Modelo: `$fillable`: `proveedor_id, destino_servicio_id`. Relaciones: `proveedor()` belongsTo; `destinoServicio()` belongsTo `DestinoServicio::class`; `proveedorTarifas()` hasMany `ProveedorTarifa::class` (`proveedor_servicio_id`).

### Tabla `proveedor_tarifas` — Modelo `ProveedorTarifa`
Columnas acumuladas (create + 4 alters):
- `id` PK
- `proveedor_servicio_id` FK → `proveedor_servicios` NOT NULL
- `tipo_tarifa` string NOT NULL — `'corporativa'|'grupal'|'publica'`
- `modalidad` string NOT NULL — `'compartido'|'privado'`
- `moneda` string NOT NULL — `'PEN'|'USD'`
- `diferenciador` json nullable
- `tipo_habitacion` string nullable (add `2026_07_28_170000`, backfill desde `diferenciador['tipo_habitacion']`)
- `descripcion` string nullable (add `2026_08_11_090000`)
- `regimen_comida` string nullable (add `2026_08_11_090000`) — `'solo_alojamiento'|'desayuno'|'media_pension'|'pension_completa'`
- `tipo_cama` string nullable (add `2026_08_11_090000`) — sin enum, mucha variedad
- `precio_costo` decimal(10,2) NOT NULL
- `margen_tipo` string NOT NULL — `'porcentaje'|'fijo'`
- `margen_valor` decimal(10,2) NOT NULL
- `descuento_maximo_pct` decimal(5,2) nullable
- `margen_minimo_pct` decimal(5,2) nullable
- `precio_venta_adulto` decimal(10,2) NOT NULL
- `precio_venta_nino` decimal(10,2) nullable
- `precio_venta_infante` decimal(10,2) nullable
- `precio_costo_cama_adicional` decimal(10,2) nullable (add `2026_08_11_090000`)
- `precio_venta_cama_adicional` decimal(10,2) nullable (add `2026_08_11_090000`)
- `edad_min_nino` unsignedSmallInteger nullable
- `edad_max_nino` unsignedSmallInteger nullable
- `edad_max_infante` unsignedSmallInteger nullable
- `temporada_id` unsignedBigInteger nullable — `temporadas.id` (central) sin FK real, null = todo el año
- `vigente_desde` date NOT NULL
- `vigente_hasta` date nullable
- `tip_afe_igv` string(2) NOT NULL — Catálogo 07 SUNAT, mismo tipo que `sale_details.tip_afe_igv`
- `destino_tributario` string NOT NULL — `'amazonia'|'nacional'|'extranjero'`
- `activo` boolean default true (add `2026_08_26_170000`)
- `timestamps`

Comentarios clave:
- *"reconciliación real: proveedor_tarifas cuelga de proveedor_servicio_id, NO de proveedor_id directo"*
- *"edad_max_infante: nullable a propósito, sin default de columna — el default real (configuracion_agencia.edad_max_infante) se resuelve a nivel de aplicación al crear la tarifa"*
- Sobre `activo`: *"Se decidió NO reusar vigente_hasta para esto: esa columna ya tiene DOS significados propios en este código (vencimiento natural por fecha, y 'versión cerrada' cuando ProveedorTarifaController::update() edita una tarifa ya usada y crea una fila nueva) ... Superponerle un tercer significado ('el admin la apagó a mano') haría imposible para cualquier reporte futuro distinguir por qué una tarifa dejó de estar vigente."*
- *"tip_afe_igv: mismo tipo de columna que sale_details.tip_afe_igv ... string(2), Catálogo 07 SUNAT ('10' gravado, '17' IVAP, '20' exonerado, '30' inafecto, '40' exportación)"*

Modelo `ProveedorTarifa`: `$fillable` = lista completa arriba (nota: el comentario del `activo` en `$fillable` explica el trade-off nuevamente). `$casts`: `activo => boolean`, `diferenciador => array`, decimales `=> decimal:2` (precio_costo, margen_valor, descuento_maximo_pct, margen_minimo_pct, precio_venta_adulto/nino/infante, precio_costo/venta_cama_adicional), `vigente_desde/vigente_hasta => date`.
Relaciones: `proveedorServicio()` belongsTo; `paqueteItems()` hasMany `PaquetePlantillaItem::class` (`proveedor_tarifa_id`).

### Tabla `proveedor_alojamiento_detalle` — Modelo `ProveedorAlojamientoDetalle`
Columnas:
- `id` PK
- `proveedor_id` FK → `proveedores` UNIQUE, `cascadeOnDelete`
- `hora_checkin` time nullable
- `hora_checkout` time nullable
- `edad_max_infante_gratis` unsignedTinyInteger default 4
- `edad_max_nino_cama_adicional` unsignedTinyInteger default 12
- `timestamps`

Comentario: *"1:1 con proveedores. Solo se crea una fila acá para proveedores de ese tipo — no se fuerza su existencia para el resto"*.

Modelo: `$fillable`: `proveedor_id, hora_checkin, hora_checkout, edad_max_infante_gratis, edad_max_nino_cama_adicional` (sin casts). Relación: `proveedor()` belongsTo.

### Tabla `proveedor_amenidad` (pivote) — sin modelo Eloquent propio
Columnas:
- `id` PK
- `proveedor_id` FK → `proveedores`, `cascadeOnDelete`
- `amenidad_id` unsignedBigInteger — sin FK real cross-DB (central)
- `timestamps`
- UNIQUE compuesto (`proveedor_id`,`amenidad_id`)

Consumida manualmente por `Proveedor::amenidades()` (ver arriba), no vía modelo pivote.

### Modelo `Amenidad` (CENTRAL, sin migración en este vertical listada — catálogo central)
`use CentralConnection`; `$table = 'amenidades'`; `$fillable`: `nombre, icono, slug`. Sin relaciones declaradas.

---

## 5. DESTINO ATRACTIVO / DESTINO SERVICIO / SERVICIO

### Tabla `destinos_atractivos` — Modelo `DestinoAtractivo`
Columnas:
- `id` PK
- `parent_id` FK → `destinos_atractivos` (self), nullable, `nullOnDelete`
- `nombre` string NOT NULL
- `tipo` string NOT NULL — `'zona'|'lugar'|'atractivo'`
- `descripcion` text nullable
- `fotos` json nullable
- `timestamps`

Comentario clave: *"null = zona/raíz. Self-reference dentro del mismo Schema::create() es válido en Postgres"*; *"No todo destino necesita los 3 niveles completos (ej. 'Lamas' es tipo=lugar sin zona padre)."*

Modelo: `$fillable`: `parent_id, nombre, tipo, descripcion, fotos`; `$casts`: `fotos => array`.
Relaciones: `parent()` belongsTo self; `hijos()` hasMany self (`parent_id`); `destinoServicios()` hasMany `DestinoServicio::class` — comentario: *"Usado con withCount() en DestinoAtractivoController::index() para el filtro 'sin servicios asociados'"*.
Método estático (no relación Eloquent): `idsConDescendientes(int $destinoAtractivoId): array` — BFS nivel por nivel sobre `parent_id`. Comentario: *"Centralizado acá (antes vivía duplicado como método privado en ProveedorTarifaController) para que cualquier filtro por destino en el vertical ... use la misma lógica."*

### Tabla `destino_servicio` — Modelo `DestinoServicio`
Columnas:
- `id` PK
- `destino_atractivo_id` FK → `destinos_atractivos` NOT NULL
- `servicio_id` FK → `servicios` NOT NULL
- `timestamps`

Comentario: *"destino_atractivo_id puede apuntar a CUALQUIER nivel del árbol (zona/lugar/atractivo, ej. el transporte se cotiza a nivel lugar pero las entradas a nivel atractivo) — no se restringe por tipo a nivel de schema"*.

Modelo: `$fillable`: `destino_atractivo_id, servicio_id`. Relaciones: `destinoAtractivo()` belongsTo; `servicio()` belongsTo `Servicio::class`; `proveedorServicios()` hasMany `ProveedorServicio::class`.

### Tabla `servicios` — Modelo `Servicio`
Columnas:
- `id` PK
- `nombre` string NOT NULL
- `tipo_proveedor_id` unsignedBigInteger nullable — `proveedor_tipos.id` (central), sin FK real
- `timestamps`

Comentario: *"no es lo mismo que 'tipo de proveedor': el mismo proveedor de transporte puede ofrecer servicios distintos hacia el mismo destino, cada uno con tarifa propia."*

Modelo: `$fillable`: `nombre, tipo_proveedor_id`. Sin relaciones (cross-boundary, se valida en aplicación).

---

## 6. PAQUETE PLANTILLA / ITEMS / ITINERARIO

### Tabla `paquetes_plantilla` — Modelo `PaquetePlantilla`
Columnas acumuladas (create + 3 alters):
- `id` PK
- `codigo` string UNIQUE nullable
- `categoria` string NOT NULL — `'local'|'nacional'|'internacional'`
- `tipo` string default `'tour_simple'` (add `2026_07_30_110000`) — `'tour_simple'|'paquete_combo'`
- `nombre` string NOT NULL
- `descripcion` text nullable
- `fotos` json nullable
- `destino_atractivo_id` FK → `destinos_atractivos` NOT NULL
- `duracion_horas` unsignedSmallInteger NOT NULL
- `hora_salida` time nullable
- `hora_retorno` time nullable
- `lugar_recojo` text nullable
- `no_incluye` text nullable
- `recomendaciones` text nullable
- `vuelo_incluido` boolean default false
- `vuelo_aerolinea` string nullable
- `vuelo_detalle` text nullable
- `precio_venta_final` decimal(10,2) nullable — precio "desde" para listados, no el único precio real
- `vigencia_desde` date nullable
- `vigencia_hasta` date nullable
- `publicado_web` boolean default false (no funcional aún)
- `activo` boolean default true (add `2026_07_30_110000`)
- `descuento_tipo` string nullable (add `2026_07_30_110000`) — `'porcentaje'|'monto'`, solo si tipo=paquete_combo
- `descuento_valor` decimal(10,2) nullable (add `2026_07_30_110000`)
- `margen_minimo_pct` decimal(5,2) nullable (add `2026_07_30_110000`)
- `ajuste_redondeo` decimal(10,2) nullable (add `2026_08_20_090000`)
- `timestamps`

Comentarios clave:
- *"confirmado 24-jul-2026: 'tour' y paquetes_plantilla son la misma entidad, validado con documentos reales de la agencia — Full Day Alto Mayo, Tours Lamas Nativo"*
- Sobre `tipo`/combo: *"REEMPLAZA el diseño original de esta fila (tabla `tours` separada + proveedor_tarifas.tour_id, nunca implementado)."*
- Sobre `ajuste_redondeo`: *"el vendedor arma un tour/combo con ítems reales cuya suma da un número no redondo (ej. S/93.66) pero el negocio quiere cobrar un número redondo (ej. S/100) ... Se descartó modelarlo como una fila más en paquete_plantilla_items (un 'servicio suelto falso' sin proveedor/guía/tour real detrás) porque rompería ComboValidationService::validarExclusividadMutua() ... y contaminaría cualquier reporte futuro de 'qué proveedores incluye este paquete'."*

Modelo `PaquetePlantilla`:
- Constantes: `TIPO_TOUR_SIMPLE='tour_simple'`, `TIPO_PAQUETE_COMBO='paquete_combo'`
- `$fillable`: lista completa arriba
- `$casts`: `fotos => array`, `vuelo_incluido => boolean`, `precio_venta_final => decimal:2`, `vigencia_desde/hasta => date`, `publicado_web => boolean`, `activo => boolean`, `descuento_valor => decimal:2`, `margen_minimo_pct => decimal:2`, `ajuste_redondeo => decimal:2`
- **Accessors (Attribute) sobre `hora_salida`/`hora_retorno`**: normalizan `HH:MM:SS` (Postgres) a `HH:MM`. Comentario: *"un fix anterior (dfcdf92) solo normalizaba en el punto de carga de form.vue y volvió a romperse en otro flujo"*.
- Relaciones: `destinoAtractivo()` belongsTo; `paqueteItinerario()` hasMany `TourItinerarioItem::class` (`tour_id`); `items()` hasMany `PaquetePlantillaItem::class` (`paquete_plantilla_id`); `itemsComoHijo()` hasMany `PaquetePlantillaItem::class` (`paquete_plantilla_hijo_id`) — comentario: *"usado por ComboValidationService::bloqueosPorDesactivar() para listar qué combos activos se verían afectados al desactivar un tour_simple"*
- Métodos: `esTourSimple(): bool`, `esPaqueteCombo(): bool`

### Tabla `paquete_plantilla_items` — Modelo `PaquetePlantillaItem`
Columnas:
- `id` PK
- `paquete_plantilla_id` FK → `paquetes_plantilla` NOT NULL
- `proveedor_tarifa_id` FK → `proveedor_tarifas` nullable
- `guia_tarifa_id` FK → `guia_tarifas` nullable
- `paquete_plantilla_hijo_id` FK → `paquetes_plantilla` (self) nullable (add `2026_07_30_110100`)
- `orden` unsignedSmallInteger nullable — orden en PDF, y (con doble propósito posterior) qué día del combo ocupa el tour-hijo
- `timestamps`

Comentario clave: *"esto es lo que genera el 'Incluye' del PDF automáticamente — NO es texto libre, cada ítem es un destino_servicio + proveedor_tarifa real"*; *"proveedor_tarifa_id cubre destino_servicio IMPLÍCITAMENTE (cadena proveedor_tarifa → proveedor_servicio → destino_servicio) — no hace falta una FK redundante"*; *"Regla de negocio 'uno de los dos entre proveedor_tarifa_id/guia_tarifa_id, no ambos' NO se modela como CHECK constraint"* (luego pasa a "uno de los tres" con `paquete_plantilla_hijo_id`, validado en `ComboValidationService`).

Modelo: `$fillable`: `paquete_plantilla_id, proveedor_tarifa_id, guia_tarifa_id, paquete_plantilla_hijo_id, orden`; `$casts`: `orden => integer`. Relaciones: `paquetePlantilla()` belongsTo; `proveedorTarifa()` belongsTo; `guiaTarifa()` belongsTo; `paquetePlantillaHijo()` belongsTo `PaquetePlantilla::class` (`paquete_plantilla_hijo_id`).

### Tabla `tour_itinerario_items` — Modelo `TourItinerarioItem`
Columnas:
- `id` PK
- `tour_id` FK → `paquetes_plantilla` NOT NULL
- `dia_relativo` unsignedSmallInteger NOT NULL
- `hora` time nullable
- `orden` unsignedSmallInteger nullable
- `destino_atractivo_id` FK → `destinos_atractivos` nullable
- `descripcion` text NOT NULL
- `timestamps`

Comentario clave: *"por día RELATIVO (1, 2, 3...), no fecha calendario ... destino_atractivo_id NULLABLE a propósito: pasos puramente de traslado/cierre sin atractivo específico (ej. 'Retorno a Tarapoto')"*.

Modelo: `$fillable`: `tour_id, dia_relativo, hora, orden, destino_atractivo_id, descripcion`. Relaciones: `tour()` belongsTo `PaquetePlantilla::class`; `destinoAtractivo()` belongsTo.

---

## 7. RESERVA / RESERVA ITEM / RESERVA PASAJERO / SALIDA OPERATIVA

### Tabla `reserva` — Modelo `Reserva`
Columnas acumuladas (create + 6 alters):
- `id` PK
- `alternativa_id` FK → `alternativas` NOT NULL
- `mayorista_elegida_id` FK → `opcion_mayorista` nullable
- `estado_reserva_mayorista` string nullable — `'pendiente'|'confirmada'`, solo si mayorista_elegida_id no null
- `estado` string default `'activa'` — `'activa'|'cancelada'`
- `fecha_viaje_desde` date nullable (add `2026_08_18_090000`)
- `fecha_viaje_hasta` date nullable (add `2026_08_18_090000`)
- `fecha_viaje_desde_original` date nullable (add `2026_08_19_090000`)
- `fecha_viaje_hasta_original` date nullable (add `2026_08_19_090000`)
- `fecha_reprogramacion` timestamp nullable (add `2026_08_19_090000`)
- `motivo_reprogramacion` string nullable (add `2026_08_19_090000`)
- `facturacion_externa` boolean default false (add `2026_08_20_150100`)
- `referencia_externa` string nullable (add `2026_08_20_150100`)
- `fecha_facturacion_externa` date nullable (add `2026_08_20_150100`)
- `fecha_cancelacion` timestamp nullable — **Fase 2, sin usar**
- `motivo_cancelacion` string nullable — `'voluntaria'|'fuerza_mayor'|'clima'|'falta_pago_cuotas'` — Fase 2
- `porcentaje_reembolso_aplicado` decimal(5,2) nullable — Fase 2
- `monto_reembolso` decimal(10,2) nullable — Fase 2
- `codigo` string UNIQUE nullable (add `2026_08_26_160400`)
- `timestamps`

Comentarios clave (muy densos, ver textual):
- *"la LÓGICA de cálculo automático (reglas_cancelacion, tabla de %, jobs de mora) es Fase 2 ... lo único que SÍ entra en el primer lanzamiento es `estado: activa|cancelada`"*
- REGLA FIJA sobre fechas: *"la fecha de una reserva se lee siempre de acá (reserva.fecha_viaje_desde/hasta) — NUNCA de reserva.alternativa.cotizacion.fecha_viaje_desde/hasta. Esa cadena sigue existiendo ... pero su fecha_viaje_desde/hasta refleja la PROPUESTA comercial vigente hoy, editable libremente sin ningún guard ... no el compromiso operativo ya congelado de esta reserva."*
- Reprogramación: *"Columnas de auditoría SIMPLE ... no una tabla `reserva_reprogramaciones` aparte. Si la reserva se reprograma más de una vez, solo queda visible la reprogramación MÁS RECIENTE"*
- `codigo`: *"Reserva no tiene numeración propia: el código se deriva del de su cotización padre ... cambiando solo el prefijo (C→R) y agregando un sufijo si es la 2da+ reserva"*

Modelo `Reserva`: `$fillable` = lista completa (incluye `codigo`). `$casts`: `fecha_viaje_desde/hasta => date`, `fecha_viaje_desde_original/hasta_original => date`, `fecha_reprogramacion => datetime`, `fecha_cancelacion => datetime`, `porcentaje_reembolso_aplicado => decimal:2`, `monto_reembolso => decimal:2`, `facturacion_externa => boolean`, `fecha_facturacion_externa => date`.
Relaciones: `alternativa()` belongsTo; `mayoristaElegida()` belongsTo `OpcionMayorista::class`; `pasajeros()` hasMany `ReservaPasajero::class`; `items()` hasMany `ReservaItem::class`; `ventas()` hasMany `ReservaVenta::class`; `anticipos()` hasMany `ReservaAnticipo::class` — comentario: *"tabla reserva_anticipos, existía desde Sesión 8b sin ningún controller que la usara (hallazgo de auditoría del módulo Adelantos, 2026-08-21)"*.

### Tabla `reserva_items` — Modelo `ReservaItem`
Columnas acumuladas (create + 6 alters):
- `id` PK
- `reserva_id` FK → `reserva` NOT NULL
- `alternativa_item_id` FK → `alternativa_items` NOT NULL
- `fecha` date nullable (originalmente NOT NULL, relajada vía SQL crudo en `2026_07_30_100000_retrofit_reserva_para_sesion_11c` — `ALTER TABLE ... DROP NOT NULL`)
- `fecha_origen` string default `'auto'` (add `2026_08_18_090000`) — `'auto'|'manual'`
- `hora` time nullable
- `guia_id` FK → `guias` nullable
- `proveedor_tarifa_id` FK → `proveedor_tarifas` nullable (retrofit `2026_07_30_100000`)
- `tour_origen_id` FK → `paquetes_plantilla` nullable (add `2026_07_30_110300`)
- `salida_operativa_id` FK → `salidas_operativas` nullable, `nullOnDelete` (add `2026_08_13_100100`)
- `tip_afe_igv` string(2) nullable (add `2026_08_28_090100`)
- `destino_tributario` string nullable (add `2026_08_28_090100`)
- `timestamps`

Comentarios clave:
- *"alternativa_item_id: FK real ... costo/precio se leen de ahí, NO se duplican columnas de precio en esta tabla."*
- *"guia_id: nullable ... se asigna normalmente un día antes del viaje, debe poder quedar vacío sin bloquear el resto del flujo."*
- `fecha_origen`: *"'auto' = fecha calculada por la fórmula ... 'manual' = un operador la editó a mano ... Ningún recálculo automático futuro (Fase 2, reprogramación) debe tocar un ítem 'manual' sin decisión explícita"*
- Retrofit Sesión 11c: *"0 filas reales en reserva_items/reserva_pasajeros en cualquier tenant"* confirmado antes de relajar constraints vía SQL crudo (sin doctrine/dbal instalado).

**Nota (confirmada en investigación previa de esta sesión): `proveedor_tarifa_id`/`guia_id` en esta tabla son la "asignación operativa" — nullable, reasignable, con un tab dedicado en `reservas/detalle.vue` ("Sin asignar todavía — no bloquea el resto de la reserva"). NO existe campo equivalente para `origen_tipo=mayorista/manual/pasaje_aereo` — `tieneAsignacionAplicable()` (backend y frontend) los excluye explícitamente.**

Modelo `ReservaItem`: constantes `FECHA_ORIGEN_AUTO='auto'`, `FECHA_ORIGEN_MANUAL='manual'`. `$fillable`: `reserva_id, alternativa_item_id, fecha, fecha_origen, hora, guia_id, proveedor_tarifa_id, tour_origen_id, salida_operativa_id, tip_afe_igv, destino_tributario`. `$casts`: `fecha => date`.
Relaciones: `reserva()` belongsTo; `alternativaItem()` belongsTo; `guia()` belongsTo `Guia::class`; `proveedorTarifa()` belongsTo; `tourOrigen()` belongsTo `PaquetePlantilla::class`; `salidaOperativa()` belongsTo `SalidaOperativa::class`; `pasajeros()` **belongsToMany** `ReservaPasajero::class` vía pivote `reserva_item_pasajero` (`reserva_item_id`,`reserva_pasajero_id`); `vueloPasajeros()` hasMany `ReservaItemVueloPasajero::class` — comentario: *"tabla propia, sin relación con pasajeros()/reserva_item_pasajero de arriba"*.

### Tabla `reserva_pasajeros` — Modelo `ReservaPasajero`
Columnas acumuladas:
- `id` PK
- `reserva_id` FK → `reserva` NOT NULL
- `tipo_pax` string nullable (add `2026_07_30_100000`, retrofit)
- `nombre` string nullable (originalmente NOT NULL, relajado vía SQL crudo en retrofit)
- `documento` string nullable (idem, relajado)
- `nacionalidad` string nullable
- `alimentacion_especial` text nullable
- `discapacidad` text nullable
- `vuelo_aerolinea_ida` string nullable
- `vuelo_fecha_ida` date nullable (add `2026_08_13_090000`)
- `vuelo_hora_ida` time nullable
- `vuelo_aerolinea_vuelta` string nullable
- `vuelo_fecha_vuelta` date nullable (add `2026_08_13_090000`)
- `vuelo_hora_vuelta` time nullable
- `pasajero_catalogo_id` FK → `pasajeros_catalogo` nullable (retrofit `2026_07_28_150200`, última FK diferida cerrada)
- `timestamps`

Comentario clave: *"esta es la etapa donde se llenan los pasajeros reales que van a viajar, para control operativo"*; *"vuelo_fecha_ida/vuelta: ... este campo es para el vuelo que el PASAJERO compró por su cuenta, ajeno al pasaje aéreo que vende la agencia"*; retrofit 11c: *"el shell se crea vacío al aceptar la alternativa (ReservaController::crearReservaDesdeAlternativa()) y se completa después"*.

Modelo `ReservaPasajero`: `$fillable`: `reserva_id, tipo_pax, nombre, documento, nacionalidad, alimentacion_especial, discapacidad, vuelo_aerolinea_ida, vuelo_fecha_ida, vuelo_hora_ida, vuelo_aerolinea_vuelta, vuelo_fecha_vuelta, vuelo_hora_vuelta, pasajero_catalogo_id`. **Sin `$casts` declarados** — comentario en `ReservaItemVueloPasajero` aclara: *"mismo criterio que ReservaPasajero.vuelo_fecha_ida/vuelta (sin cast ahí tampoco): así llega como string plano 'Y-m-d' desde Postgres, sin el problema de timestamp ISO completo que si tuviera cast 'date' rompería `<input type="date">` en el frontend"*.
Relaciones: `reserva()` belongsTo; `pasajeroCatalogo()` belongsTo `PasajeroCatalogo::class`; `reservaItems()` belongsToMany `ReservaItem::class` vía pivote `reserva_item_pasajero` (`reserva_pasajero_id`,`reserva_item_id`).

### Tabla `reserva_item_pasajero` (pivote) — Modelo `ReservaItemPasajero`
Columnas:
- `id` PK
- `reserva_item_id` FK → `reserva_items` NOT NULL
- `reserva_pasajero_id` FK → `reserva_pasajeros` NOT NULL
- `checkin_realizado` boolean default false (add `2026_07_28_160000`)
- `checkin_hora` timestamp nullable (add `2026_07_28_160000`)
- ~~`vuelo_numero_ida`, `vuelo_fecha_ida`, `vuelo_hora_ida`, `vuelo_numero_vuelta`, `vuelo_fecha_vuelta`, `vuelo_hora_vuelta`, `vuelo_aerolinea_confirmada`~~ — agregadas en `2026_08_27_090000` y **luego dropeadas y migradas** a tabla propia `reserva_item_vuelo_pasajero` en `2026_08_27_110000` (mismo día, fix de bug).
- `timestamps`

Comentario clave (bug real, cita textual completa): *"Corrige un bug real encontrado en pruebas en vivo (2026-08-27) ... guardar el vuelo de agencia como columnas de reserva_item_pasajero lo dejaba en la MISMA fila que edita el checkbox del tab 'Asignación pasajero↔ítem' ... Consecuencia real: desmarcar un pasajero en Asignación borraba la fila entera — incluido el vuelo de agencia ya cargado — y marcar checkboxes ahí podía hacer desaparecer sin querer el bloque de vuelo de pasajeros que seguían siendo del mismo vuelo."*

Modelo `ReservaItemPasajero` (schema actual, post-fix): `$fillable`: `reserva_item_id, reserva_pasajero_id, checkin_realizado, checkin_hora`. `$casts`: `checkin_realizado => boolean`, `checkin_hora => datetime`. Relaciones: `reservaItem()` belongsTo; `reservaPasajero()` belongsTo.

### Tabla `reserva_item_vuelo_pasajero` — Modelo `ReservaItemVueloPasajero`
Columnas:
- `id` PK
- `reserva_item_id` FK → `reserva_items` NOT NULL
- `reserva_pasajero_id` FK → `reserva_pasajeros` NOT NULL
- `vuelo_numero_ida` string nullable
- `vuelo_fecha_ida` date nullable
- `vuelo_hora_ida` time nullable
- `vuelo_numero_vuelta` string nullable
- `vuelo_fecha_vuelta` date nullable
- `vuelo_hora_vuelta` time nullable
- `vuelo_aerolinea_confirmada` string nullable
- `timestamps`
- UNIQUE (`reserva_item_id`,`reserva_pasajero_id`)

Comentario: *"Sin cast 'date' en vuelo_fecha_ida/vuelta a propósito"* (mismo motivo que arriba). *"Fix: tabla propia, sin ninguna relación con reserva_item_pasajero ni con el checkbox de Asignación."*

Modelo: `$fillable` = lista completa. Sin `$casts`. Relaciones: `reservaItem()` belongsTo; `reservaPasajero()` belongsTo.

### Tabla `salidas_operativas` — Modelo `SalidaOperativa`
Columnas:
- `id` PK
- `tour_origen_id` FK → `paquetes_plantilla` nullable, `nullOnDelete`
- `fecha` date NOT NULL
- `hora` time nullable
- `guia_id` FK → `guias` nullable, `nullOnDelete`
- `cupo_maximo` integer nullable
- `vehiculo_descripcion` string nullable
- `estado` **enum nativo Postgres** `['activa','cancelada']` default `'activa'` (única tabla del vertical con `$table->enum(...)`, resto usa `string`)
- `notas` text nullable
- `timestamps`
- Índice único parcial (SQL crudo): `CREATE UNIQUE INDEX salidas_operativas_tour_fecha_unique ON salidas_operativas (tour_origen_id, fecha) WHERE tour_origen_id IS NOT NULL`

Comentario clave (completo): *"agrupa reserva_items de DISTINTAS reservas que comparten el mismo tour_origen_id + fecha y son modalidad='compartido'. El guía se asigna acá UNA vez, no por reserva. El proveedor de transporte NO se centraliza (confirmado que varía por reserva incluso dentro de la misma salida) — sigue viviendo en reserva_items."*; *"Índice único parcial: evita duplicados por condición de carrera si dos reservas del mismo tour_origen_id/fecha se aceptan casi al mismo tiempo."*

Modelo: `$fillable`: `tour_origen_id, fecha, hora, guia_id, cupo_maximo, vehiculo_descripcion, estado, notas`; `$casts`: `fecha => date`. Relaciones: `tourOrigen()` belongsTo `PaquetePlantilla::class`; `guia()` belongsTo `Guia::class`; `reservaItems()` hasMany `ReservaItem::class` (`salida_operativa_id`).

### Tabla `reserva_ventas` (pivote reserva↔Sale) — Modelo `ReservaVenta`
Columnas:
- `id` PK
- `reserva_id` FK → `reserva` NOT NULL
- `sale_id` FK → `sales` NOT NULL (core)
- `reserva_item_ids` json NOT NULL
- `reserva_pasajero_ids` json NOT NULL
- `timestamps`

Comentario: *"NO es un campo simple sale_id — una reserva puede terminar con más de una venta (servicio agregado después de facturada por 'documento adicional' ...; o pagos por pasajero con varios responsables)"*; *"json, ... no FK real a un array ... se valida en aplicación que los IDs referenciados existan y pertenezcan a la misma reserva"*.

Modelo: `$fillable`: `reserva_id, sale_id, reserva_item_ids, reserva_pasajero_ids`; `$casts`: ambos json `=> array`. Relaciones: `reserva()` belongsTo; `sale()` belongsTo `App\Models\Sale\Sale::class`.

---

## 8. GUIA / GUIA TARIFA

### Tabla `guias` — Modelo `Guia`
Columnas:
- `id` PK
- `nombre` string NOT NULL
- `documento` string NOT NULL
- `telefono` string NOT NULL
- `activo` boolean default true
- `es_referencial` boolean default false (add `2026_07_30_110400`)
- `timestamps`

Comentario: *"Catálogo simple ... sin manejo de disponibilidad/calendario (freelance, trabajan con varias agencias a la vez)."*

Modelo: `$fillable`: `nombre, documento, telefono, activo, es_referencial`; `$casts`: `activo => boolean`, `es_referencial => boolean`. Relación: `guiaTarifas()` hasMany `GuiaTarifa::class`.

### Tabla `guia_tarifas` — Modelo `GuiaTarifa`
Columnas:
- `id` PK
- `guia_id` FK → `guias` NOT NULL
- `destino_id` FK → `destinos_atractivos` NOT NULL
- `modalidad` string NOT NULL — `'dia_local'|'grupo_multidia'`
- `costo_diario` decimal(10,2) NOT NULL
- `tipo_margen` string NOT NULL — `'porcentaje'|'fijo'`
- `margen_valor` decimal(10,2) NOT NULL
- `moneda` string NOT NULL
- `vigente_desde` date NOT NULL
- `vigente_hasta` date nullable
- `activo` boolean default true (add `2026_08_29_090000`)
- `timestamps`

Comentario clave: *"Sin descuento_maximo_pct/margen_minimo_pct a propósito (explícitamente excluido del plan para guías, a diferencia de proveedor_tarifas)."* Sobre `activo`: *"Mismo gap que proveedor_tarifas tenía hasta el 26-ago-2026 ... GuiaTarifaController no tenía update()/destroy() a propósito ('el plan solo pide GET/POST anidado bajo guía')"*.

Modelo: `$fillable`: `activo, guia_id, destino_id, modalidad, costo_diario, tipo_margen, margen_valor, moneda, vigente_desde, vigente_hasta`; `$casts`: `activo => boolean`, `costo_diario => decimal:2`, `margen_valor => decimal:2`, `vigente_desde/hasta => date`. Relaciones: `guia()` belongsTo; `destino()` belongsTo `DestinoAtractivo::class` (`destino_id`); `paqueteItems()` hasMany `PaquetePlantillaItem::class` (`guia_tarifa_id`).

---

## 9. CRONOGRAMA PAGO PROVEEDOR / PAGO PROVEEDOR

### Tabla `cronograma_pago_proveedor` — Modelo `CronogramaPagoProveedor`
Columnas:
- `id` PK
- `proveedor_id` FK → `proveedores` nullable
- `opcion_mayorista_id` FK → `opcion_mayorista` nullable
- `numero_cuota` unsignedSmallInteger NOT NULL
- `monto_programado` decimal(10,2) NOT NULL
- `fecha_vencimiento` date NOT NULL
- `estado` string default `'pendiente'` — `'pendiente'|'pagado'|'vencido'`
- `timestamps`

Comentario: *"lo que la agencia DEBE pagar y cuándo (cronograma), a diferencia de pago_proveedor que solo registra pagos YA realizados"*; regla "uno de los dos" (proveedor_id/opcion_mayorista_id) *"NO se modela como CHECK constraint"*.

**Nota transversal (confirmada en investigación previa): modelo Eloquent existe, pero CERO controller/ruta/UI — tabla sin ningún camino de escritura desde la aplicación.**

Modelo: `$fillable`: `proveedor_id, opcion_mayorista_id, numero_cuota, monto_programado, fecha_vencimiento, estado`; `$casts`: `monto_programado => decimal:2`, `fecha_vencimiento => date`. Relaciones: `proveedor()` belongsTo; `opcionMayorista()` belongsTo.

### Tabla `pago_proveedor` — Modelo `PagoProveedor`
Columnas:
- `id` PK
- `proveedor_id` FK → `proveedores` nullable
- `opcion_mayorista_id` FK → `opcion_mayorista` nullable
- `monto` decimal(10,2) NOT NULL
- `moneda` string NOT NULL — `'PEN'|'USD'`
- `fecha` date NOT NULL
- `tipo` string NOT NULL — `'adelanto_reserva'|'pago_final'`
- `referencia_documento` string nullable
- `timestamps`

Comentario: *"el core de Sale/SalePayment/Advance/Installment es solo para lo que la agencia le COBRA al cliente ... pago_proveedor cubre [pagar a proveedores/mayoristas], sin reimplementar nada del core."*; *"Contraparte de cronograma_pago_proveedor: ese es lo PROGRAMADO, este es lo YA PAGADO. Al registrar un pago acá se vincula (referencia informativa, no FK — el plan no la pide) a la cuota correspondiente"*; *"referencia_documento: ... número de factura que da el proveedor, NO es un comprobante que la agencia emite (no pasa por Greenter/SUNAT)."*

**Misma nota que arriba: sin controller/ruta/UI, tabla sin ningún camino de escritura.**

Modelo: `$fillable`: `proveedor_id, opcion_mayorista_id, monto, moneda, fecha, tipo, referencia_documento`; `$casts`: `monto => decimal:2`, `fecha => date`. Relaciones: `proveedor()` belongsTo; `opcionMayorista()` belongsTo.

### Tabla `reglas_cancelacion` — Modelo `ReglaCancelacion`
Columnas:
- `id` PK
- `proveedor_id` FK → `proveedores` nullable — null = regla general de la agencia
- `dias_min_antes` unsignedSmallInteger NOT NULL
- `dias_max_antes` unsignedSmallInteger nullable — sin tope
- `porcentaje_reembolso` decimal(5,2) NOT NULL
- `timestamps`

Comentario: *"Solo el schema y la carga inicial de la regla general entran en el primer lanzamiento — la LÓGICA que consume esta tabla (calcular porcentaje_reembolso_aplicado al cancelar una reserva) es Fase 2"*.

Modelo: `$fillable`: `proveedor_id, dias_min_antes, dias_max_antes, porcentaje_reembolso`; `$casts`: `porcentaje_reembolso => decimal:2`. Relación: `proveedor()` belongsTo.

---

## 10. "ADVANCE"/"ANTICIPO" DEL VERTICAL

### Tabla `reserva_anticipos` — Modelo `ReservaAnticipo`
Columnas:
- `id` PK
- `reserva_id` FK → `reserva` NOT NULL
- `advance_id` FK → `advances` NOT NULL (core, `App\Models\Advance\Advance`)
- `monto_asignado` decimal(10,2) NOT NULL
- `fecha_asignacion` date NOT NULL
- `timestamps`

Comentario clave: *"etiqueta un adelanto (Advance, core) contra una reserva específica, ANTES de que exista el Sale final. El Advance sigue siendo la única fuente de verdad del dinero — esta tabla solo lo asocia a una reserva puntual, sin duplicar el registro."* En `Reserva::anticipos()`: *"tabla reserva_anticipos, existía desde Sesión 8b sin ningún controller que la usara (hallazgo de auditoría del módulo Adelantos, 2026-08-21)."* — **CERRADO** desde entonces, `ReservaAnticipoController` sí existe hoy (ver documento hermano de controllers).

Modelo: `$fillable`: `reserva_id, advance_id, monto_asignado, fecha_asignacion`; `$casts`: `monto_asignado => decimal:2`, `fecha_asignacion => date`. Relaciones: `reserva()` belongsTo; `advance()` belongsTo `Advance::class`.

---

## 11. CONFIGURACIÓN AGENCIA (singleton)

### Tabla `configuracion_agencia` — Modelo `ConfiguracionAgencia`
Columnas acumuladas (create + 9 alters), fila única insertada en `up()` de la migración de creación:
- `id` PK
- `sigla_comercial` string nullable, `after('id')` (add `2026_08_26_160000`)
- `edad_max_infante` unsignedSmallInteger default 2
- `edad_max_nino` unsignedSmallInteger default 12
- `formato_descuento_pdf` string default `'solo_final'` — `'solo_final'|'tachado'|'separado'`
- `mostrar_descuento_como_linea` boolean default false
- `dias_vigencia_cotizacion` unsignedSmallInteger nullable — **sin default fijo**, "cada agencia los configura"
- `dias_limpieza_alternativas_descartadas` unsignedSmallInteger nullable — sin default fijo
- `max_pax_reserva_con_vuelo` unsignedSmallInteger default 15
- `max_pax_reserva_grupo` unsignedSmallInteger default 50
- `meses_margen_vencimiento_documento` unsignedSmallInteger default 6
- `dias_aviso_pago_proveedor` unsignedSmallInteger default 2
- `dias_cotizacion_estancada` unsignedSmallInteger default 15
- `dias_aviso_vencimiento_cotizacion` unsignedSmallInteger default 2 (add `2026_07_30_110500`)
- `margen_minimo_aceptable_pct` decimal(5,2) default 20.00 (add `2026_08_04_121532`)
- `permitir_descuento_item` boolean default true (add `2026_08_03_090000`)
- `modo_descuento_item` string default `'porcentaje'` (add `2026_08_03_090000`) — `'porcentaje'|'monto'`
- `modo_descuento_global` string default `'porcentaje'` (add `2026_08_03_090000`)
- `edad_max_infante_gratis_hotel_default` unsignedTinyInteger default 4 (add `2026_08_06_090100`)
- `edad_max_nino_cama_adicional_hotel_default` unsignedTinyInteger default 12 (add `2026_08_06_090100`)
- `condiciones_generales_servicio` text nullable (add `2026_08_13_110200`)
- `tip_afe_igv_default` string(2) default `'10'` (add `2026_08_28_090200`)
- `destino_tributario_default` string default `'nacional'` (add `2026_08_28_090200`)
- `timestamps`

Comentarios clave (varios, textuales):
- *"Tabla singleton: una sola fila por tenant, nunca un catálogo de muchas filas — la migración inserta la fila default directamente en up() para que todo tenant agencia_viajes provisionado la tenga sin pasos manuales aparte (a diferencia de Company/SunatConfig, que son 2 pasos manuales a propósito)."*
- *"dias_vigencia_cotizacion y dias_limpieza_alternativas_descartadas quedan NULL a propósito — el plan los define explícitamente 'sin default fijo'."*
- Sobre `permitir_descuento_item`: *"permitir_descuento_item=false apaga el lenguaje de 'descuento' en el lienzo por completo (el vendedor edita precio_convertido directo, sin que se le muestre % ni monto)"*.
- Sobre `edad_max_infante_gratis_hotel_default`: *"éstos son el default específico que se precarga al crear un OpcionHotel nuevo — no son el mismo concepto [que edad_max_infante/edad_max_nino], valores por defecto iguales por coincidencia de negocio, no por ser el mismo campo"*.
- Sobre `tip_afe_igv_default`: *"pensado para agencias en Amazonía, donde el caso común es exonerado, sin dejar de permitir el caso ocasional fuera de la región"*.

Modelo `ConfiguracionAgencia`: `$fillable` = lista completa arriba (con comentarios inline por bloque de sesión). `$casts`: `mostrar_descuento_como_linea => boolean`, `permitir_descuento_item => boolean` (**nota: la mayoría de los booleans NO tienen cast explícito pese a ser boolean en BD** — solo estos 2 están casteados).
Método estático `tratamientoTributarioDefault(): array` — comentario: *"Único punto de lectura del default de agencia — reutilizado por los 4 orígenes de AlternativaItem sin proveedor_tarifa propia. Devuelve el default legado ('10'/'nacional') si el tenant nunca guardó configuracion_agencia (no debería pasar, es singleton, pero evita un null-pointer en tenants de prueba sin seed)."*

---

## 12. CUENTA BANCARIA / COMPANY

### Tabla `cuentas_bancarias` — Modelo `CuentaBancaria`
Columnas (sin comentarios de diseño en la migración — archivo sin cabecera explicativa):
- `id` PK
- `banco` string NOT NULL
- `titular` string NOT NULL
- `numero_cuenta` string NOT NULL
- `cci` string nullable
- `alias` string nullable — ej. "Yape/Plin"
- `activo` boolean default true
- `orden` integer default 0
- `timestamps`

Sin FK. Modelo: `$fillable`: `banco, titular, numero_cuenta, cci, alias, activo, orden`; `$casts`: `activo => boolean`. Sin relaciones.

### `Company` (`app/Models/Company.php`)
Grep sobre `moneda|tipo_cambio|currency` en `Company.php`: **0 coincidencias** — el modelo `Company` (core) no tiene columnas de moneda/tipo de cambio propias visibles en el modelo. No se detectó relación con este vertical más allá de ser el tenant/empresa genérico del core.

---

## 13. TIPO DE CAMBIO AGENCIA

### Tabla `tipo_cambio_agencia` — Modelo `TipoCambioAgencia`
Columnas:
- `id` PK
- `fecha` date NOT NULL
- `origen` string NOT NULL — `'dia'|'agencia'`
- `valor` decimal(10,4) NOT NULL
- `registrado_por` FK → `users` NOT NULL
- `timestamps`

Comentario clave: *"histórico completo de tipo de cambio, 'dia' (mercado) u 'agencia' (fijado internamente) — nunca se sobrescribe, se inserta una fila nueva cada vez que se usa un valor distinto. alternativas.tipo_cambio_aplicado/tipo_cambio_origen guardan el snapshot usado por cada alternativa."*

Modelo: `$fillable`: `fecha, origen, valor, registrado_por`; `$casts`: `fecha => date`, `valor => decimal:4`. Relación: `registradoPor()` belongsTo `App\Models\User::class`.

Nota semántica (comentario en `PriceEngineService::convertirMoneda`): *"tipo_cambio_agencia.valor = cuántos PEN equivalen a 1 USD (cotización estándar en Perú, ej. 3.75) — documentado acá porque el plan no fija la dirección explícita de la fórmula."*

**Nota transversal para la auditoría (P4 de `plan-refactor-mayoristas-tramos.md`): la moneda/tipo de cambio vive a nivel de `Alternativa` completa (`moneda_cotizacion`/`tipo_cambio_aplicado`), no por tramo ni por ítem — un viaje con 2 tramos en monedas distintas (PEN local + USD internacional) no tiene hoy ningún mecanismo para coexistir dentro de la misma Alternativa.**

---

## MÓDULO 12 — CÓDIGOS/NUMERACIÓN (satélite de Cotizacion/Reserva)

### Tabla `configuracion_codigos` — Modelo `ConfiguracionCodigo`
Columnas:
- `id` PK
- `tipo` string UNIQUE — `tour|paquete|cotizacion|reserva|venta_directa`
- `prefijo` string
- `deriva_de` string nullable — null, o `'cotizacion'` para reserva (indica que reserva no usa `codigo_secuencias`, reusa el correlativo del padre)
- `incluye_periodo` boolean default false
- `formato_periodo` string default `'MMAA'` (constante hoy, no editable desde UI)
- `separador` char(1) default `'-'`
- `longitud_correlativo` unsignedSmallInteger default 4
- `reinicio_correlativo` string default `'nunca'` — `nunca|mensual|anual`
- `activo` boolean default true
- `updated_by` unsignedBigInteger nullable — sin FK real cross-boundary a users
- `timestamps`

Comentario: *"reinicio_correlativo se fuerza a 'nunca' en el backend (ConfiguracionCodigosController::update()) cuando incluye_periodo=false — regla explícita del plan §6.2, no modelada como CHECK constraint"*.

Modelo: `$fillable`: `tipo, prefijo, deriva_de, incluye_periodo, formato_periodo, separador, longitud_correlativo, reinicio_correlativo, activo, updated_by`; `$casts`: `incluye_periodo => boolean`, `activo => boolean`. Sin relaciones declaradas.

### Tabla `codigo_secuencias` — Modelo `CodigoSecuencia`
Columnas:
- `id` PK
- `tipo` string — `tour|paquete|cotizacion|venta_directa` (reserva NO tiene fila)
- `periodo` string nullable — siempre null hoy
- `ultimo_correlativo` unsignedInteger default 0
- `timestamps`
- UNIQUE (`tipo`,`periodo`)

Comentario: *"El siguiente correlativo se obtiene con lockForUpdate() dentro de una transacción que el caller ya abrió — nunca con MAX(correlativo) sobre la tabla del documento, mismo mecanismo (con el mismo bug de concurrencia ya resuelto en su momento) que serie_comprobantes/SerieComprobanteService."*

Modelo: `$fillable`: `tipo, periodo, ultimo_correlativo`. Sin casts, sin relaciones.

---

## RECORDATORIOS (satélite operativo)

### Tabla `tipos_recordatorio` — Modelo `TipoRecordatorio`
- `id` PK, `codigo` string UNIQUE (`pago_proveedor_pendiente|cumpleanos_cliente|cotizacion_estancada|documento_por_vencer|personalizado`), `nombre` string, `automatico` boolean, `timestamps`.
Modelo: `$fillable`: `codigo, nombre, automatico`; `$casts`: `automatico => boolean`. Relaciones: `recordatorios()` hasMany `Recordatorio::class`; `snoozeConfigs()` hasMany `RecordatorioSnoozeConfig::class`.

### Tabla `recordatorios` — Modelo `Recordatorio`
- `id` PK, `tipo_id` FK→`tipos_recordatorio`, `entidad_tipo` string (`reserva|cotizacion|cliente|pago_proveedor|libre`), `entidad_id` unsignedBigInteger nullable **SIN FK** (polimórfico genuino, sin columna discriminadora de tipo Eloquent morph estándar), `titulo` string, `mensaje` text, `fecha_disparo` timestamp, `usuario_id` FK→`users` nullable, `rol_destino` string (`vendedor|admin|todos`), `creado_por` FK→`users` NOT NULL, `forzado` boolean default false, `estado` string default `'pendiente'` (`pendiente|visto|pospuesto|descartado`), `timestamps`.

Comentario clave: *"entidad_id ... es genuinamente polimórfico ... ninguna FK real de Postgres puede expresar 'apunta a una de 4 tablas distintas según otra columna'. Se valida en aplicación qué tabla corresponde según entidad_tipo."*

Modelo: `$fillable` lista completa; `$casts`: `fecha_disparo => datetime`, `forzado => boolean`. Relaciones: `tipo()` belongsTo `TipoRecordatorio::class`; `usuario()` belongsTo `User::class`; `creadoPor()` belongsTo `User::class` (`creado_por`).

**Nota transversal (confirmada en investigación previa, ver `project_agencia_viajes_11b4_gaps_reales`): el schema de recordatorios existe, pero el disparador automático (job que evalúa `cotizacion_estancada`/`pago_proveedor_pendiente`/etc. y crea filas) no está construido — mismo patrón de "schema completo, lógica pendiente" que `cronograma_pago_proveedor`/`pago_proveedor`.**

### Tabla `recordatorio_snooze_config` — Modelo `RecordatorioSnoozeConfig`
- `id` PK, `usuario_id` FK→`users`, `tipo_id` FK→`tipos_recordatorio`, `snooze_minutos` unsignedSmallInteger, `omitir` boolean default false, `timestamps`.
Modelo: `$fillable`: `usuario_id, tipo_id, snooze_minutos, omitir`; `$casts`: `omitir => boolean`. Relaciones: `usuario()` belongsTo `User::class`; `tipo()` belongsTo `TipoRecordatorio::class`.

---

## FACTURACIÓN PUENTE

### Tabla `sale_detail_items` — Modelo `SaleDetailItem`
- `id` PK, `sale_detail_id` FK→`sale_details` (core), `reserva_item_id` FK→`reserva_items`, `timestamps`.

Comentario clave (importante para auditoría): *"IMPORTANTE (documentado explícitamente por el plan): el reporte operativo (Sesión 10) y los itinerarios siguen leyendo de reserva_items DIRECTAMENTE, nunca de sale_detail_items/sale_details — la factura es solo una representación distinta de los mismos datos para SUNAT, no la fuente de verdad operativa. No usar esta tabla como atajo para evitar un JOIN contra reserva_items en reportes futuros."* (repetido casi textual en el modelo).

Modelo: `$fillable`: `sale_detail_id, reserva_item_id`. Relaciones: `saleDetail()` belongsTo `App\Models\Sale\SaleDetail::class`; `reservaItem()` belongsTo `ReservaItem::class`.

---

## PASAJEROS CATÁLOGO

### Tabla `pasajeros_catalogo` — Modelo `PasajeroCatalogo`
- `id` PK, `cliente_id` FK→`clients` nullable, `nombre` string, `nacionalidad` string nullable, `fecha_nacimiento` date nullable — *"permite derivar adulto/niño/infante automático"*, `timestamps`.
Modelo: `$fillable`: `cliente_id, nombre, nacionalidad, fecha_nacimiento`; `$casts`: `fecha_nacimiento => date`. Relaciones: `cliente()` belongsTo `Client::class`; `documentos()` hasMany `PasajeroDocumento::class`; `reservaPasajeros()` hasMany `ReservaPasajero::class` (`pasajero_catalogo_id`).

### Tabla `pasajero_documentos` — Modelo `PasajeroDocumento`
- `id` PK, `pasajero_catalogo_id` FK→`pasajeros_catalogo`, `tipo_documento` string (`dni|pasaporte|carne_extranjeria|otro`), `numero_documento` string, `fecha_vencimiento` date nullable, `archivo` string nullable — path en disco privado, `fecha_registro` date, `timestamps`.

Comentario: *"archivo: SOLO el path de almacenamiento PRIVADO (fuera de carpetas públicas/servidas directo) — mismo criterio ya usado para el certificado SUNAT ... El endpoint autenticado que sirve el archivo (verifica permiso, nunca un link directo descargable/indexable) es Sesión 11, NO se modela acá."*

Modelo: `$fillable`: `pasajero_catalogo_id, tipo_documento, numero_documento, fecha_vencimiento, archivo, fecha_registro`; `$casts`: `fecha_vencimiento => date`, `fecha_registro => date`. Relación: `pasajeroCatalogo()` belongsTo.

---

## TEMPORADA / TEMPORADA OCURRENCIA (CENTRAL, referenciadas sin FK real desde `proveedor_tarifas.temporada_id`)

### Modelo `Temporada` (CENTRAL)
`use CentralConnection`; `$table='temporadas'`; `$fillable`: `nombre, tipo, giro`. Relación: `temporadaOcurrencias()` hasMany `TemporadaOcurrencia::class`.

### Modelo `TemporadaOcurrencia` (CENTRAL)
`use CentralConnection`; `$table='temporada_ocurrencias'`; `$fillable`: `temporada_id, anio, fecha_desde, fecha_hasta`; `$casts`: `fecha_desde/hasta => date`. Relación: `temporada()` belongsTo `Temporada::class` (FK real, misma base central).

---

## PERMISOS SPATIE (no tablas de dominio, pero listados por completitud del vertical)
Permisos creados vía migraciones (guard `api`): `agencia.proveedores`, `agencia.destinos`, `agencia.temporadas`, `agencia.guias`, `agencia.configuracion` (`2026_07_28_170100`); `agencia.cotizaciones` (`2026_07_28_180200`) — comentario: *"Cubre Cotizacion/Alternativa/AlternativaItem/OpcionMayorista — todo el motor del cotizador"*; `agencia.paquetes` (`2026_07_29_190000`); `agencia.reservas` (`2026_07_30_100100`) — comentario: *"reserva es un paso posterior y distinto (control operativo de pasajeros/ítems, no armado de precio)"*.

---

## SERVICIOS DE DOMINIO (`app/Services/AgenciaViajes`)

### `PriceEngineService` (`app/Services/AgenciaViajes/PriceEngineService.php`)
Comentario de cabecera: *"Motor de precios único del vertical ... Servicio de dominio puro (sin Request/Response), para que sea testeable y reusable entre AlternativaItemController (proveedor/pasaje_aereo) y cualquier otro punto que necesite aplicar margen + validar el piso, sin duplicar la fórmula."*
Nota adicional: *"Revisado antes de escribir esto: ProveedorTarifaController (Sesión 11a) NO calcula margen inline — precio_venta_adulto/nino/infante los ingresa el admin directo al cargar la tarifa de catálogo (son precios de venta ya decididos, no una cotización puntual). Nada que refactorizar ahí."*

Métodos públicos:
1. `calcular(float $costoBase, array $cargos, string $margenTipo, float $margenValor, ?float $descuentoMaximoPct, ?float $margenMinimoPct): array` → retorna `{venta_base, venta_total, costo_total, desglose[], margen_aplicado, precio_minimo_permitido, alerta_piso}`. Cargos: *"pass-through de terceros (impuestos, tasas), NUNCA se les aplica margen."*
2. `evaluarPiso(float $precioEditado, float $costoBase, float $ventaBase, ?float $descuentoMaximoPct, ?float $margenMinimoPct): array` → `{precio_minimo_permitido, alerta_piso}`. Comentario: *"usado por AlternativaItemController::update() para la validación en vivo, sin recalcular todo el margen de nuevo."*
3. `calcularCombo(array $tourTotales, ?string $descuentoTipo, ?float $descuentoValor, ?float $ajusteRedondeo = null): array` → `{costo_total_combo, venta_bruta_combo, venta_neta_combo, descuento_aplicado, margen_resultante_pct, ajuste_redondeo, venta_final_combo}`. Comentario: *"Dirección inversa a calcular(): acá se parte de una venta BRUTA ya conocida ... y se le resta un descuento, en vez de partir de un costo y sumarle margen."*
4. `aplicarDescuento(float $ventaBruta, ?string $descuentoTipo, ?float $descuentoValor): float`. Comentario: *"'monto' resta un valor fijo; 'porcentaje' resta sobre venta_bruta. Sin tipo/valor configurado, no hay descuento."*
5. `convertirMoneda(float $monto, string $monedaOrigen, string $monedaDestino, float $tipoCambio): float`. Comentario: *"tipo_cambio_agencia.valor = cuántos PEN equivalen a 1 USD (cotización estándar en Perú, ej. 3.75) ... Antes duplicado en AlternativaItemController — Sesión 11b3 lo movió acá para que AlternativaController (descuento_global_pct) lo reuse sin repetir la fórmula."*

Métodos privados de soporte (no públicos, listados por contexto): `calcularPrecioMinimoPermitido()` — *"precio_minimo_permitido = el MAYOR entre el piso por margen_minimo_pct (sobre costo_base) y el piso por descuento_maximo_pct (sobre venta_base) ... null cuando ninguno de los 2 pisos está configurado"*; `cruzaPiso()` — *"Tolerancia de centavo para evitar falsos positivos por redondeo decimal"* (`< precioMinimoPermitido - 0.005`).

### Otros servicios detectados en `app/Services/AgenciaViajes/` (nombres, referenciados constantemente en comentarios de arriba):
- `CodigoGeneradorService` — generación de códigos (`generar('cotizacion'|'venta_directa'|...)`, `generarParaReserva()`)
- `ComboExplosionService` — explota un `paquete_combo` en ítems reales de cotización (sí toca Eloquent, a diferencia de PriceEngineService)
- `ComboValidationService` — validaciones de negocio de combos (`validarExclusividadMutua()`, `validarMargenMinimo()`, `bloqueosPorDesactivar()`)

---

## RESUMEN DE PATRONES TRANSVERSALES DETECTADOS (hechos observados, no opinión)

1. **Referencias cross-DB (central↔tenant) sin FK real de Postgres**, siempre documentadas con el mismo criterio textual: `proveedores.tipo_id`, `servicios.tipo_proveedor_id`, `proveedor_tipos_config.proveedor_tipo_id`, `proveedor_tarifas.temporada_id`, `proveedor_amenidad.amenidad_id`.
2. **"Retrofit" es el patrón dominante de evolución de schema**: 6+ migraciones explícitamente nombradas `add_*_foreign_to_*` que cierran FKs diferidas de tablas creadas en sesiones anteriores cuando la tabla destino aún no existía (`opciones_hotel.paquete_plantilla_id`, `opciones_hotel.opcion_mayorista_id`, `alternativa_items.opcion_mayorista_id`, `reserva_pasajeros.pasajero_catalogo_id`).
3. **Reglas de negocio "uno de N campos, no varios" nunca se modelan como CHECK constraint** — repetido textualmente en `opciones_hotel` (opcion_mayorista_id/paquete_plantilla_id), `alternativa_items` (proveedor_tarifa_id/opcion_mayorista_id), `paquete_plantilla_items` (proveedor_tarifa_id/guia_tarifa_id/paquete_plantilla_hijo_id), `cronograma_pago_proveedor` y `pago_proveedor` (proveedor_id/opcion_mayorista_id).
4. **Enums de dominio son `string` con comentario inline, no `enum` de Postgres** — única excepción: `salidas_operativas.estado` (sí usa `$table->enum()`).
5. **Columnas "muertas" documentadas explícitamente en el código**: `alternativa_items.opcion_hotel_tarifa_id`/`paquete_plantilla_id` — el modelo dice textualmente que "quedaron muertas — ningún código las escribe ya" tras la consolidación de hoteles (proveedor_tarifas absorbió el caso de uso).
6. **Snapshots de precio/tipo de cambio persistidos, nunca recalculados en lectura** (excepto `OpcionHotelTarifa`, que sí sobreescribe en accessor si hay `proveedor_tarifa_id` vivo — caso atípico documentado).
7. **Bugs reales de producción documentados en comentarios de migración/modelo** (material de auditoría de alto valor): orden de filas en Postgres sin `ORDER BY` (`Alternativa::items()`), slug hardcodeado divergente entre seeder y producción (`ProveedorTipo::SLUG_ALOJAMIENTO`), y el vuelo de agencia compartiendo fila con el checkbox de asignación (`reserva_item_pasajero` → separado a `reserva_item_vuelo_pasajero`).
8. **Tablas con schema completo y cero camino de escritura desde la aplicación**: `salidas_mayorista` (sin controller/ruta), `cronograma_pago_proveedor`/`pago_proveedor` (sin controller/ruta), disparador automático de `recordatorios` (schema + snooze config completos, sin job que los genere).
