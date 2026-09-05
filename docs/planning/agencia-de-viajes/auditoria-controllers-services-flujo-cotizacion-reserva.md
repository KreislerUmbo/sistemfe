# Auditoría factual — Controllers/Services del flujo Cotización → Alternativa → Reserva → Operación → Facturación

> **Naturaleza de este documento:** relevamiento FACTUAL en bruto (qué hace cada método,
> qué modelos toca, qué valida, qué congela vs. qué queda vivo — con citas textuales del
> código), sin opiniones ni clasificaciones. Es insumo de la auditoría arquitectónica
> pedida en la sesión del 31-ago-2026 (ver `plan-refactor-mayoristas-tramos.md` para el
> contexto de negocio que la disparó). El análisis/síntesis/clasificación
> ([CONSERVAR]/[REFACTORIZAR]/[EXTENDER]/[REEMPLAZAR]/[NUEVO]/[ELIMINAR]) se hace en un
> documento aparte, este es el material crudo para que cualquier agente/persona lo pueda
> auditar de forma independiente.
>
> Generado por un agente de exploración de código (read-only) sobre
> `api-sistema-fe/app/Http/Controllers/AgenciaViajes/*.php` y
> `api-sistema-fe/app/Services/**/*.php`. Todos los paths son absolutos bajo
> `c:\xampp\htdocs\sistemfe\api-sistema-fe\`.

---

## 1. `app/Http/Controllers/AgenciaViajes/CotizacionController.php`

Header de cotización. Código autogenerado ({prefijo}-{año}-{correlativo}) vía `CodigoGeneradorService`.

- **`index()`** — lista con filtro `?estado=` que en realidad filtra por `whereHas('alternativas', ...)`. Comentario textual: *"Cotizacion no tiene columna 'estado' propia (vive en cada alternativa) — ?estado= filtra cotizaciones que tengan AL MENOS una alternativa en ese estado, no un estado propio del header."*
- **`store()`** — crea `Cotizacion` + N `CotizacionPasajero` (edad → `tipo_pax` derivado por umbrales de `ConfiguracionAgencia.edad_max_infante/edad_max_nino`, default 2/12). Todo en una transacción.
- **`update()`** — corrige cliente/destino/fechas del header **sin ningún guard de estado**, incluso con alternativa aceptada o reserva ya generada. Cita: *"corregirlos no rompe nada aunque la cotización ya tenga alternativas o incluso una reserva aceptada — mismo criterio de simplicidad que actualizarPasajeros()"* (nota: esa afirmación sobre `actualizarPasajeros()` queda contradicha por el propio bloqueo que ese método sí tiene, ver abajo).
- **`actualizarPasajeros()`** — diff por id de `cotizacion_pasajeros` (ya no borra/recrea todo). **Bloqueo 422 duro** si alguna alternativa de la cotización está `estado === 'aceptada'` (ya generó reserva): *"Tocar la cotización después de eso no cambia la reserva, solo la desincroniza en silencio... Se bloquea entero (no solo el recálculo)."*
  - Al eliminar pasajeros, limpia sus ids de `alternativa_items.pax_incluidos` (`limpiarPaxIncluidosEliminados()`).
  - **Recalcula automáticamente** `precio_venta_snapshot`/`precio_convertido` de ítems `modo_precio='por_persona'`, pero **solo** en alternativas `borrador`/`enviada` y **solo** si `origen_tipo=proveedor` con `proveedor_tarifa_id` real (`recalcularItemsPorPersona()`). Todo lo demás (mayorista/guía/pasaje_aereo/manual o tarifa borrada) se reporta en `items_para_revisar` sin tocar nada — cita: *"nunca fallback silencioso"*. Preserva `descuento_pct` manual ya aplicado.
- **`destroy()`** — 422 si cualquier alternativa de la cotización ya tiene `Reserva`. Si no, cascada transaccional vía `AlternativaController::eliminarCascada()` por cada alternativa + borra `cotizacion_pasajeros` + header.

**Snapshot vs vivo**: `Cotizacion` en sí (cliente/destino/fechas) es **siempre editable/vivo**, sin congelar nada — es la reserva (`Reserva.fecha_viaje_desde/hasta`) la que congela.

---

## 2. `app/Http/Controllers/AgenciaViajes/AlternativaController.php`

- **`store()`** — máx. 5 alternativas por cotización (`MAX_ALTERNATIVAS_POR_COTIZACION`). Resuelve tipo de cambio (`resolverTipoCambio()`): si se manda `tipo_cambio_valor`, crea un `TipoCambioAgencia` nuevo; si no, reusa el último registrado del mismo `origen` (`dia`/`agencia`) — 422 si no hay ninguno. Nombre autogenerado "Alternativa A/B/C..." si no se especifica.
- **`update()`** — actualiza `nombre`/`estado`/`descuento_global_pct`/`descuento_global_monto`. Si pasa a `estado='enviada'` sin `fecha_envio` previa, la setea. **Si pasa a `'aceptada'`, llama `descartarOtras()`** (descarta el resto de alternativas de la misma cotización) — pero el comentario aclara que esto **ya no dispara creación de reserva** (eso lo hace `ReservaController::aceptar()`, POST separado).
  - `descuento_global_pct`/`descuento_global_monto` (mutuamente excluyentes, decidido por el frontend, sin validación server-side de exclusión) reparten el descuento a **cada** `AlternativaItem` vía `aplicarDescuentoGlobal()`/`aplicarDescuentoGlobalMonto()`, usando `PriceEngineService::evaluarPiso()` por ítem (solo si tiene `proveedorTarifa`). **El piso NUNCA bloquea** — solo se informa en `lineas_fuera_de_piso`. Ítems sin tarifa (manual/referencia) reciben igual el descuento.
- **`destroy()`** — 422 si `Reserva::where('alternativa_id', ...)->exists()`. Si no, `eliminarCascada()` transaccional.
- **`eliminarCascada()`** (static, reusado por `CotizacionController::destroy()`) — borra `AlternativaItem` + `CotizacionPasajeAereo` asociado, y el árbol completo de `OpcionMayorista` → `OpcionMayoristaOpcional` + `OpcionHotel` → `OpcionHotelTarifa`.
- **`duplicar()`** — clona alternativa completa (ítems, `CotizacionPasajeAereo`, árbol de `OpcionMayorista`/`OpcionHotel`/`OpcionHotelTarifa`/`OpcionMayoristaOpcional`) en una alternativa nueva `borrador`, con `" (copia)"`. Cita: *"la idea de 'duplicar' es poder editar la copia libremente sin afectar la alternativa original, y un ítem origen_tipo=mayorista que siguiera apuntando a la opción del original rompería esa independencia"*. Respeta el mismo límite de 5.
- **`pdf()`** — PDF comercial de UNA alternativa vía DomPDF, usa `StorageUrl::resolveParaPdf()` (no `resolve()`, porque DomPDF corre con `enable_remote=false`). Arma itinerario narrativo concatenando tours distintos presentes en los ítems (`itinerarioAlternativa()`), y solo devuelve "el tour" único (`tourUnicoDeAlternativa()`) si **todos** los ítems comparten el mismo `tour_origen_id` — nunca concatena texto de varios tours.
- **`descartarOtras()`** (static, compartido con `ReservaController::aceptar()` y `VentaDirectaController::store()`) — marca `estado='descartada'` a todas las demás alternativas de la misma cotización.

**Snapshot vs vivo**: `Alternativa.tipo_cambio_aplicado` se congela al crearla (de `TipoCambioAgencia` en ese momento); ediciones de tipo de cambio posteriores no la afectan.

---

## 3. `app/Http/Controllers/AgenciaViajes/AlternativaItemController.php`

El motor de creación de ítems de cotización — 5 `origen_tipo`: `proveedor`, `mayorista`, `pasaje_aereo`, `manual`, `guia`.

### `store()` — dispatcher por `origen_tipo` (usa `$request->input()`, no `get()` — comentario: bug real de que `get()` es el ParameterBag de Symfony, no lee JSON body).

### `crearItemProveedor()`
- 422 si `proveedor_tarifa_id` apunta a tarifa `activo=false` (comentario: *"No aplica a ítems ya existentes (el precio ya está congelado, no se re-valida al editar cantidad/pax_incluidos de un ítem viejo)"*).
- `modo_precio='por_persona'`: reparte `precio_venta_adulto/nino/infante` según conteo real de pasajeros (`contarPasajerosPorTipo()`, respetando `pax_incluidos` o todos).
- `modo_precio='tarifa_fija'`: un solo precio (`precio_venta_adulto`), más lógica de **cama adicional** (`aplicarCamaAdicional()`) — valida edad real del pasajero (no `tipo_pax`) contra `proveedor.alojamientoDetalle.edad_max_infante_gratis/edad_max_nino_cama_adicional`; 422 si no hay ningún pax en ese tramo o si la tarifa no tiene precio de cama adicional configurado.
- Sin `proveedor_tarifa_id` ("precio de referencia"): `costo_snapshot` sentinel = 0 si no se manda; `precio_venta_snapshot` obligatorio.
- **Congela**: `costo_snapshot`, `precio_venta_snapshot`, `moneda_costo`, `precio_convertido`, `tip_afe_igv`/`destino_tributario` (resuelto en el momento, con fallback a default de agencia — nunca se recalcula después salvo por `CotizacionController::recalcularItemsPorPersona()`).

### `crearItemMayorista()`
- Requiere que la `OpcionMayorista` pertenezca a la misma alternativa y esté `estado='elegida'` (422 si no). Valida que la `OpcionHotelTarifa` pertenezca a esa opción.
- `modo_precio` siempre `'tarifa_fija'` (comentario: *"paquete internacional, siempre por habitación"*).

### `crearItemGuia()`
- 422 si `GuiaTarifa.activo=false`.
- Usa `PriceEngineService::calcular()` sobre `costo_diario` con `tipo_margen`/`margen_valor` de la tarifa — **monto fijo del día, no se reparte por pasajero** (a diferencia de `modo_precio='por_persona'` de proveedor).

### `crearItemPasajeAereo()` / `validarPasajeAereo()` / `calcularPasajeAereo()`
- Reparte `tarifa_base_adulto/nino/infante` por conteo real de pasajeros; `cargos` son pass-through ya totalizados (no se multiplican); `fee_agencia_monto` es lo único que la agencia vende — se modela como margen **fijo** del `PriceEngineService`. Cita: *"fee_agencia_monto es lo único que la agencia realmente vende (§2.5)"*.
- Crea `AlternativaItem` (`modo_precio='por_persona'`, `cantidad=1`) + `CotizacionPasajeAereo` (submodelo con el detalle: aerolínea, tarifas base, cargos, `tua_incluida_en_tarifa`, `fee_agencia_monto`, `tip_afe_igv` propio **específico** — ver nota de precedencia en `ReservaFacturacionController` abajo).
- **`actualizarPasajeAereo()`**: edición estructural completa. Bloqueada (422) si ya existe `ReservaItem` para este `alternativa_item_id`.
- **`previewPasajeAereo()`**: mismo cálculo, sin persistir — "no reimplementes la suma en el frontend, pedísela al backend".

### `crearItemManual()`
- `costo_snapshot`/`precio_venta_snapshot`/`cantidad`/`pax_incluidos` son **reales** desde Sesión 11q (antes eran sentinels). `cantidad` sí multiplica (`AlternativaItem::getTotalAttribute()`).
- `proveedor_sugerido_manual`: dato interno, nunca visible al cliente, solo para prellenar `promoverAProveedor()`.
- **`actualizarManual()`**: edición estructural, misma familia de bloqueo (422 si ya tiene `ReservaItem`). Si el ítem ya fue promovido a proveedor (`proveedor_promovido_id`), igual se puede editar sin relinkear nada — *"son independientes por diseño"*.

### `promoverAProveedor()`
- Solo para ítems `origen_tipo=manual`, no promovidos (`proveedor_promovido_id` nulo). Crea `Proveedor` + `ProveedorServicio` + `ProveedorTarifa` real (`margen_tipo='fijo'`, `margen_valor = precio_venta_adulto - costo`). **El ítem de esta cotización NO se toca** (sigue `origen_tipo=manual`, snapshot ya congelado) — solo se anota `proveedor_promovido_id`. Cita: *"Sin relink retroactivo — decisión confirmada: el proveedor creado queda disponible para próximas cotizaciones, esta no se mueve."*
- Requiere que `tipo_proveedor_id` del servicio esté habilitado en `ProveedorTipoConfig.habilitado`.

### `destroy()` (ítem)
- 422 si `ReservaItem::where('alternativa_item_id', ...)->exists()` — cita: *"reserva_items.alternativa_item_id es una FK real sin onDelete (RESTRICT en Postgres)... el frontend... no mostraba nada"*.

### `update()` (edición en vivo de precio)
- Acepta `descuento_pct` **o** `precio_convertido`, recalcula el que falta y devuelve ambos + `alerta_piso`/`precio_minimo_permitido` vía `PriceEngineService::evaluarPiso()`. Piso solo evaluado si `proveedorTarifa` existe. **El piso nunca bloquea, solo alerta.**

### `desdePlantilla()` — "cargar desde plantilla"
- Explota TODOS los ítems de un `tour_simple`/`paquete_combo` vía `ComboExplosionService`. Para combos, cada tour-hijo ocupa su propio día de inicio (offset acumulado); ítems sueltos del combo (sin tour-hijo) ocupan el día de inicio del combo.
- `modalidad='compartido'` sin `tipo_habitacion` (no hotel): se cobra por pasajero real (mismo cálculo que `crearItemProveedor` por_persona). `'privado'`: tarifa plana tal cual viene de la explosión.
- Guías detectados en el combo generan **AlternativaItem real con costo** (fix "guia-como-item-real") pero además se listan en `guias_pendientes` porque *"esa asignación es a nivel reserva, reserva_items.guia_id"* — el costo entra desde ya, la asignación puntual del guía queda pendiente.
- `ajuste_redondeo` del paquete (si existe) se materializa como un `AlternativaItem` manual adicional (costo=0) — auditable, no un número mágico. Solo aplica el del paquete de nivel superior, no se propaga por tour-hijo.

### `reasignarDia()` / `moverBloque()`
- Ambos **422 si `alternativa.estado === 'aceptada'`** — cita: *"mover el día acá después desincroniza en silencio esa reserva ya congelada... El punto de corrección correcto para una reserva ya aceptada es ReservaController::reprogramar()"*.
- `reasignarDia()` (ítem suelto): 422 si el ítem tiene `tour_origen_id` (pertenece a un bloque, debe moverse como bloque).
- `moverBloque()`: mueve todos los ítems de un `tour_origen_id` juntos.

### `resolverTratamientoTributario()`
- Usa lo que mandó el usuario si viene completo; rellena con default de `ConfiguracionAgencia::tratamientoTributarioDefault()` lo que falte. **Snapshot en creación** — el default de agencia no se recalcula después.

### `recalcularTotalAlternativa()` — suma `total_convertido` de todos los ítems, actualiza `Alternativa.total`. Llamado tras cada mutación de ítems.

**Snapshot vs vivo**: `costo_snapshot`/`precio_venta_snapshot`/`precio_convertido`/`tip_afe_igv`/`destino_tributario` son snapshots congelados al crear el ítem. `proveedor_tarifa_id`/`opcion_mayorista_id`/`guia_tarifa_id` son FKs vivas (referencia, pueden desactualizarse — desactivar la tarifa no afecta ítems ya creados).

---

## 4. `OpcionMayoristaController.php`

(Ya cubierto en detalle en sesión previa de la conversación, no repetido acá.) Confirmado adicionalmente: `Alternativa::opcionMayoristaElegida()` es `hasOne(OpcionMayorista)->where('estado','elegida')`, garantizado únicamente por lógica de aplicación en `OpcionMayoristaController::elegir()`, sin constraint de BD.

---

## 5. `app/Http/Controllers/AgenciaViajes/ReservaController.php`

Comentario de cabecera — **REGLA FIJA** citada textual: *"la fecha de una reserva se lee siempre de reserva.fecha_viaje_desde/hasta — NUNCA de reserva.alternativa.cotizacion.fecha_viaje_desde/hasta."*

### `aceptar()` (POST `alternativas/{id}/aceptar`)
- 422 si `alternativa.estado` no está en `['borrador','enviada']`.
- 422 si ya existe una `Reserva` `estado='activa'` para **cualquier** alternativa de la misma cotización (`existeReservaActiva`).
- Transacción: `alternativa.estado='aceptada'` → `AlternativaController::descartarOtras()` → `crearReservaDesdeAlternativa()`.
- Acepta `pasajero_catalogo_ids` opcional (array alineado por orden con `cotizacion_pasajeros`) para prellenar `ReservaPasajero.pasajero_catalogo_id`.

### `crearReservaDesdeAlternativa()` (público, reusado por `VentaDirectaController`)
- Código de reserva vía `CodigoGeneradorService::generarParaReserva()` (deriva del código de cotización padre).
- `Reserva.mayorista_elegida_id` = `opcionMayoristaElegida()->id` (si existe); `estado_reserva_mayorista='pendiente'` en ese caso.
- **`fecha_viaje_desde/hasta` se copian de `cotizacion` UNA SOLA VEZ acá** — de ahí en más, la reserva no depende de que la cotización se edite después.
- Crea `ReservaPasajero` por cada `CotizacionPasajero` (mismo orden, `id asc`), mapea `pasajero_catalogo_id` posicionalmente si vino en `pasajeroCatalogoIds`.
- Por cada `AlternativaItem`, llama `crearReservaItemDesdeAlternativaItem()`.
- **Cupo mayorista**: si hay `opcionElegida->salida_mayorista_id`, incrementa `SalidaMayorista.cupo_ocupado` en `totalPax` (total de `cotizacion_pasajeros`). Si supera `cupo_total`, retorna `alerta_cupo_excedido=true` (no bloquea, solo alerta).

### `crearReservaItemDesdeAlternativaItem()` (privado, reusado por `sincronizarItems()`)
- `fecha` calculada = `fechaViajeDesde + (dia_referencial - 1)` días, si ambos existen.
- `fecha_origen = 'auto'` (constante): *"este método es el único punto de creación de reserva_items (aceptar y sincronizar), así que todo lo que nace acá es 'auto' por definición"*.
- Copia `tour_origen_id`, `tip_afe_igv`, `destino_tributario` desde el `AlternativaItem` (snapshot al momento de crear la reserva — no se re-sincroniza después).
- Llama `engancharSalidaOperativa()`.
- Crea `ReservaItemPasajero` por cada id en `alternativaItem.pax_incluidos` que tenga mapeo (null/vacío = aplica a todos, sin fila).

### `engancharSalidaOperativa()`
- Solo engancha automáticamente si: `fechaCalculada` existe, `tour_origen_id` existe, `origen_tipo === ORIGEN_PROVEEDOR`, y `proveedorTarifa.modalidad === 'compartido'`. Ítems `origen_tipo=guia` **nunca se auto-enganchan** — cita: *"guia_tarifas.modalidad ('dia_local'/'grupo_multidia') es un eje de duración de contrato, sin ninguna señal confiable de si un ítem de guía puntual es exclusivo de una reserva o compartible con otras"*.
- `SalidaOperativa::firstOrCreate(['tour_origen_id', 'fecha'], ['estado'=>'activa'])`, con manejo de condición de carrera (índice único parcial) capturando `QueryException`.

### `sincronizarItems()` (POST `reservas/{id}/sincronizar-items`)
- **Nunca automático** ("Opción C acordada"): crea `ReservaItem` solo para `AlternativaItem` agregados a la cotización DESPUÉS de aceptada la reserva, que aún no tengan `ReservaItem`. 422 si `reserva.estado !== 'activa'` o si no hay pendientes.
- Usa `reserva.fecha_viaje_desde` (base propia, no la cotización en vivo).

### `reprogramar()` (POST `reservas/{id}/reprogramar`, Fase 2 del fix Cotización↔Reserva)
- 422 si `reserva.estado !== 'activa'`. Requiere `motivo`.
- Guarda `fecha_viaje_desde_original`/`fecha_viaje_hasta_original` (**auditoría simple, no historial** — una reprogramación repetida pisa el "_original" con el estado tras la última, no el de creación real).
- Para cada `ReservaItem`: si `fecha_origen === 'manual'`, se preserva intacto (listado en `items_no_tocados`, motivo `'manual'`). Si `alternativaItem.dia_referencial` es null, tampoco se toca (motivo `'sin_dia_referencial'` — antes se saltaba en silencio, ahora se informa). Si no, recalcula `fecha` y re-engancha `SalidaOperativa` (desengancha la vieja sin borrarla — puede seguir compartida por otras reservas — y re-evalúa con las mismas reglas de `engancharSalidaOperativa()`).
- **`SalidaMayorista.cupo_ocupado` NO se toca en reprogramar** — cita explícita: *"confirmado leyendo crearReservaDesdeAlternativa()/cancelar() antes de escribir esto (no asumido del brief): ese contador es por RESERVA completa..., nunca por reserva_item"*.
- Nunca toca `cotizacion.fecha_viaje_desde/hasta` — fuera de alcance, decisión explícita.

### `cancelar()` (POST `reservas/{id}/cancelar`)
- 422 si ya `estado='cancelada'`. **422 si `ReservaVenta::where('reserva_id',...)->exists()`** (ya tiene venta/comprobante asociado) — no se puede cancelar una reserva ya facturada.
- Requiere `motivo_cancelacion` in `[voluntaria, fuerza_mayor, clima, falta_pago_cuotas]`.
- Libera cupo: `SalidaMayorista::decrement('cupo_ocupado', totalPax)` (mismo movimiento que el incremento al aceptar, en reversa) si había `opcionElegida->salida_mayorista_id`.

### `actualizarFacturacionExterna()` (PUT `reservas/{id}/facturacion-externa`)
- 422 si `reserva.estado !== 'activa'` o si `reserva.ventas()->exists()` (ya tiene Sale asociado en la plataforma) — **editable solo mientras no haya ningún `ReservaVenta`**.
- Usa `lockForUpdate()` dentro de una transacción para cerrar la ventana de carrera con `ReservaFacturacionController::store()` concurrente sobre la misma reserva.
- Al desmarcar, limpia `referencia_externa`/`fecha_facturacion_externa` — *"es anotación de estado actual, no un historial"*.

### `respuestaDetalle()` (privado, usado por `show()`, `aceptar()`, `sincronizarItems()`, `reprogramar()`)
- `resumen`: nombre/fecha/`precio_venta_snapshot`/`total_convertido`/`tour_origen_id` por `ReservaItem` (lee del `alternativaItem`, no de la reserva).
- `total` = suma de `alternativaItem.total_convertido` de todos los `reserva.items` — **el total de la reserva se lee de la cotización viva vía el ítem**, no de un campo propio de `Reserva`.
- `items_pendientes_sincronizar`: `AlternativaItem` de la alternativa que aún no tienen `ReservaItem`.
- `items_facturados_ids`/`pasajeros_facturados_ids`: derivados de `reserva.ventas.*.reserva_item_ids`/`reserva_pasajero_ids` (arrays JSON en `ReservaVenta`).
- `anticipos`: de `reserva.anticipos` (tabla `ReservaAnticipo`), con `disponible = advance.availableBalance()`.
- `facturacion_habilitada_tenant` = `tenant('facturacion_habilitada')`; `facturacion_externa_editable` = `reserva.ventas.isEmpty()`.
- `cabecera.fecha_viaje_desde/hasta` viene de **`reserva`** (congelada), no de la cotización — cliente/destino sí de la cotización (informativos).

### `resolverNombreItem()` (public static, reusado por `ReservaFacturacionController` y `ReporteOperativoController`)
- Resuelve nombre visible por `origen_tipo`: manual → `descripcion_manual`; pasaje_aereo → aerolínea; mayorista → `nombre_comercial ?: razon_social` del proveedor de la opción; hotel (si `proveedorTarifa.tipo_habitacion`) → `"{proveedor} · {tipo_habitacion}"`; default → nombre del servicio.

**Snapshot vs vivo**: `Reserva.fecha_viaje_desde/hasta` = snapshot congelado al aceptar. `ReservaItem.fecha` = snapshot calculado (`auto`) o editado manual (`manual`), nunca vivo. `ReservaItem.tip_afe_igv`/`destino_tributario` = snapshot copiado al crear. `ReservaItem.proveedor_tarifa_id`/`guia_id` = **referencia viva, reasignable** ("quién opera" se confirma cerca de la fecha). El total mostrado en `respuestaDetalle()` **no** es un snapshot — se recalcula en vivo desde `alternativaItem.total_convertido` cada vez, lo cual significa que si se editara un `AlternativaItem` después de aceptada la alternativa (bloqueado por varios guards, pero no todos), el total de la reserva reflejaría ese cambio.

---

## 6. `ReservaItemController.php`, `ReservaPasajeroController.php`, `ReservaItemPasajeroController.php`

### `ReservaItemController::update()`
- 422 si `reserva.estado !== 'activa'`.
- **422 si el ítem ya está cubierto por algún `ReservaVenta.reserva_item_ids`** (ya facturado).
- Campos editables: `guia_id`, `proveedor_tarifa_id`, `fecha`, `hora` — todos nullable, y **null explícito debe persistir** (a diferencia de `AlternativaController::update()` que descarta nulls con `array_filter`).
- Si `fecha` viene explícita y difiere de la actual, marca `fecha_origen = 'manual'` (`array_key_exists`, no `isset` — un null explícito también cuenta como edición manual).

### `ReservaItemController::destroy()`
- 422 si `reserva.estado !== 'activa'`, si es el **último ítem** de la reserva (`count() <= 1`), o si ya está facturado.
- Doble chequeo bajo `lockForUpdate()` dentro de la transacción (cierra ventana de carrera con facturación concurrente). Borra `ReservaItemPasajero` asociados antes (FK sin cascade).
- Comentario: *"existía desde Fase D pero nunca tuvo botón en el frontend"* — endpoint muerto reconectado en auditoría 2026-08-27.

### `ReservaPasajeroController::update()`
- 422 si `reserva.estado !== 'activa'`.
- Si `nombre` y `documento` quedan completos, sincroniza `PasajeroCatalogo`/`PasajeroDocumento` (`sincronizarCatalogo()`) — busca por `numero_documento` existente antes de crear duplicado.
- `buscarCatalogo()` — autocompletar (min 2 caracteres).

### `ReservaPasajeroController::destroy()`
- 422 si `reserva.estado !== 'activa'`, si es el último pasajero, o si `ReservaVenta::exists()` (reserva ya facturada — **corrección de composición de pasajeros queda fuera de alcance para reserva ya facturada**).
- Bajo lock: borra `ReservaItemPasajero` asociados, borra el pasajero, y **decrementa `SalidaMayorista.cupo_ocupado` en 1** (mismo movimiento que `cancelar()`, por 1 pasajero).

### `ReservaItemPasajeroController` — tabla puente pasajero↔ítem (`reserva_item_pasajero`)
- `index()`/`store()`/`destroy()`: todos bloqueados (422) si `reserva.estado !== 'activa'` o si `itemYaFacturado()` (ítem cubierto por algún `ReservaVenta`).
- `store()`: valida que el pasajero pertenezca a la misma reserva que el ítem. `firstOrCreate` (idempotente).
- **`checkin()`**: distinto — **no** bloqueado por `itemYaFacturado()` (*"el check-in es operativo, no toca nada financiero"*). Si el ítem no tiene ningún vínculo específico todavía (`item->pasajeros->isEmpty()` = "aplica a todos"), la primera vez que se marca check-in **materializa** los vínculos de TODOS los pasajeros de la reserva (sin marcarles check-in a los demás) — para no "promover" en silencio el ítem a vínculo específico y excluir al resto.
- **`actualizarVuelo()`**: PUT vuelo vendido por la agencia — vive en tabla separada `ReservaItemVueloPasajero`, **independiente** de `reserva_item_pasajero`. Comentario: corrección de bug real donde compartir fila con `reserva_item_pasajero` hacía que desmarcar el checkbox de Asignación borrara el vuelo ya cargado. Solo aplica a ítems `origen_tipo=ORIGEN_PASAJE_AEREO`.

---

## 7. `app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php`

Convierte una `Reserva` en uno o más `Sale` reales (SUNAT). Comentario de cabecera cita el diseño de "Facturación múltiple por grupo de pasajeros": *"N Sales por reserva, cada uno cubriendo un subconjunto de pasajeros elegido por el vendedor, con su propio client_id... Un anticipo solo puede aplicarse a una sub-factura del MISMO cliente que lo pagó."*

### Guards previos a cualquier facturación (`prepararFactura()` y `store()`)
- 422 si `reserva.estado !== 'activa'`.
- **403** si `! tenant('facturacion_habilitada')` (flag central del tenant, deny-by-default si null).
- **403** si `reserva.facturacion_externa` (override por reserva).

### `resolverSeleccion()` (compartida por preview y store) — reglas de selección de ítems
- `pasajero_ids` deben pertenecer a la reserva; no pueden repetir pasajeros ya facturados en otro `ReservaVenta` (**excepto** si TODOS los solicitados ya están facturados Y se está agregando solo `reserva_item_ids_manual` nuevos — caso de re-facturar ítems sin asignar sueltos a pasajeros ya cubiertos).
- Ítem **con** pasajeros vinculados en `reserva_item_pasajero`: se auto-incluye **solo si TODOS** sus pasajeros vinculados están en el subconjunto seleccionado — *"nunca se fragmenta un ítem compartido entre dos Sales distintos"*. Si hay overlap parcial, se reporta en `items_pendientes_por_pasajero_faltante`, sin incluirlo.
- Ítem **sin** ningún pasajero vinculado (el caso mayoritario real, confirmado con datos: 11/37 en tenant demo tenían vínculo): se ofrece en `items_sin_asignar_disponibles`; el vendedor debe elegirlo explícitamente vía `reserva_item_ids_manual`.
- Cita explícita sobre limitación no resuelta: *"reparto de un ítem tarifa_fija compartido (ej. habitación doble) entre pasajeros que terminan en Sales DISTINTOS — no existe ningún mecanismo en el proyecto que sepa cuánto le toca a cada uno... No se improvisó ninguna fórmula de reparto."*
- `prepararFactura()` (GET, solo lectura) puede devolver 0 ítems sin bloquear (para que el modal no falle apenas se abre); `store()` sí bloquea con 422 si `items->isEmpty()`.

### Guardia tributario — `detectarMezclaTributaria()`
- Todos los ítems del Sale deben compartir el **mismo `destino_tributario` efectivo** (resuelto vía `resolverDestinoTributario()`, cascada: `reserva_item.destino_tributario` → `proveedorTarifa.destino_tributario` → default `'nacional'`). Si hay mezcla → bloqueo con mensaje `MENSAJE_MEZCLA_TRIBUTARIA`.
- Actualmente **solo se permite `destino_tributario === 'nacional'`** — cualquier otro valor (`amazonia`/`extranjero`) bloquea con `MENSAJE_TRATAMIENTO_TRIBUTARIO_NO_NACIONAL`: *"La facturación de estos casos está pausada hasta definir el cálculo correcto con el contador."*
- `tip_afe_igv` (a nivel línea, dentro del mismo destino homogéneo) **sí puede variar** — no se bloquea, se sub-agrupa por él en `agruparPorCategoria()`.

### `store()` — creación real del `Sale`
- Requiere permiso Laravel (`emitir_factura`/`emitir_boleta`) según `tipo_comprobante_codigo`. 422 si Factura ('01') a cliente sin RUC (`cod_tipo_doc_sunat !== '6'`).
- Serie resuelta vía `SerieComprobanteService::resolverParaUsuario()`.
- **Toda la creación real corre dentro de una transacción con `lockForUpdate()` sobre la `Reserva`**, re-validando TODO (selección, mezcla tributaria, productos placeholder) bajo el lock — cierra la ventana de carrera con `actualizarFacturacionExterna()` concurrente.
- Agrupa ítems por categoría (`clasificarCategoria()`: HOTEL si `tipo_habitacion`; VUELO si `pasaje_aereo`; TRANSPORTE/TOUR por nombre del servicio conteniendo esas palabras; OTROS catch-all) × sub-agrupado por `tip_afe_igv`.
- `SKU_POR_CATEGORIA` mapea a 5 productos placeholder sembrados por `ProductoGenericoViajeSeeder` — **500 si no existen** en el tenant.
- Crea 1 `Sale` + N `SaleDetail` (uno por grupo categoría×tip_afe_igv) + N `SaleDetailItem` (uno por `ReservaItem` incluido, para trazabilidad).
- `porcentaje_igv`: 18% si `tip_afe_igv==='10'` (gravado), 0% si exonerado/inafecto.
- `mto_oper_gravadas/exoneradas/inafectas` calculados por línea según `tip_afe_igv` — antes hardcodeado, corregido en el "Análisis de impuestos 28-ago-2026".
- **Aplicación de anticipos**: si `advance_applications` viene vacío → auto-aplica el 100% de anticipos disponibles del **mismo cliente** de esta sub-factura, en orden, hasta cubrir el total (sin pasarse, resto queda disponible para otra sub-factura). Si viene poblado → validación estricta: cada `advance_id` debe pertenecer a `reserva.anticipos` filtrados por `client_id`, y la suma no puede superar el total.
- Tras aplicar anticipos: solo se reduce `total`/`mto_imp_venta`/`debt`/`saldo_pendiente`/`paid_out` — **no** `mto_oper_gravadas`/`igv`/`valor_venta` (deben reflejar el valor íntegro; `enviarSunat()` recalcula independiente).
- Crea `ReservaVenta` con `reserva_item_ids` (los realmente facturados) y `reserva_pasajero_ids` (los **solicitados**, no necesariamente todos con ítems reales).

### Resolución de tratamiento tributario por ítem
- `resolverDestinoTributario()`: `item.destino_tributario ?? item.proveedorTarifa.destino_tributario ?? 'nacional'`.
- `resolverTipAfeIgvItem()`: caso especial — para `pasaje_aereo`, `cotizacionPasajeAereo.tip_afe_igv` (que aplica **solo sobre `fee_agencia_monto`**) tiene prioridad sobre el campo genérico del ítem; si no, `item.tip_afe_igv ?? proveedorTarifa.tip_afe_igv ?? '10'`.

**Snapshot vs vivo**: El `Sale` creado es un snapshot financiero completo e inmutable de ese momento — no se re-sincroniza con cambios posteriores en `AlternativaItem`/`ReservaItem`. Una vez un `ReservaItem` aparece en `reserva_item_ids` de algún `ReservaVenta`, queda protegido contra edición/borrado en cascada (guards en `ReservaItemController`, `ReservaPasajeroController`, `ReservaItemPasajeroController`).

---

## 8. `app/Http/Controllers/AgenciaViajes/ReporteOperativoController.php`

Vista operativa agregada por rango de fecha (no por reserva individual), consumida por index/pdf/export.

### `itemSinAsignacionOperativa()` — resuelve "asignación operativa pendiente"
- Solo `origen_tipo` `guia` y `proveedor` tienen campo de asignación operativa real.
- `guia`: sin asignación si `resolverGuiaEfectivo()` es null **o** el guía tiene `es_referencial=true` (un guía referencial/placeholder cuenta como pendiente, no como asignado).
- `proveedor`: sin asignación si no hay `proveedor_tarifa_id`, o si el proveedor de esa tarifa tiene `es_referencial=true`.
- Todo lo demás (`manual`, `mayorista`, `pasaje_aereo`) nunca cuenta como "sin asignar" (`return false`).

### `resolverGuiaEfectivo()`
- Si el `ReservaItem` está enganchado a `salida_operativa_id`, el guía real es el de la `SalidaOperativa` (compartido entre reservas) — el `guia_id` propio del ítem queda huérfano/sin usar en ese caso.

### `resolverPasajerosDelItem()`
- Si `item.pasajeros` (vínculo específico via `reserva_item_pasajero`) no está vacío, usa esos. Si está vacío, **cae a TODOS los pasajeros de la reserva** — comentario de cabecera: *"esa tabla está vacía en la inmensa mayoría de reservas reales... un tour grupal no se reparte por persona"*. Esta es una desviación confirmada del spec original.

### Filtros disponibles
`fecha_desde`/`fecha_hasta` (default: hoy), `pendiente_asignar` (boolean, filtra por `itemSinAsignacionOperativa()`), `destino_atractivo_id`, `servicio_id`, `tour_id`, `hotel_proveedor_id` — aplicados en `aplicarFiltrosDimension()`. La query base (`queryItemsDelRango()`) **excluye** `estado='cancelada'` en la reserva, y excluye explícitamente los ítems manuales "Ajuste de redondeo" (no son servicio operativo real).

### `pdf()`/`export()`/`armarVistaAgrupada()`
- Reestructura filas planas en jerarquía Día → Tour (o "Servicios sueltos · categoría") → Pasajero → sub-filas de servicio, con lógica de deduplicación de la fila de Alojamiento cuando el mismo hospedaje ya aparece implícito en la columna "Hotel" de otro grupo del mismo día (`suprimirAlojamientoDuplicado()`), con guard de seguridad si el pasajero tiene más de una reserva de hotel ese día (no se suprime nada en ese caso ambiguo).
- Vuelos (agencia y propios del pasajero) se extraen como filas sintéticas separadas por fecha real del vuelo (`extenderConFilasDeVuelo()`), y el ítem `pasaje_aereo` en sí se excluye de las filas normales para no duplicar.
- `pdfSignedUrl()`: URL firmada de 10 min porque la ruta PDF se abre sin Bearer token (mismo patrón que `CashSessionController`).

---

## 9. `app/Http/Controllers/AgenciaViajes/VentaDirectaController.php`

Atajo de un solo paso: `Cotizacion` + `CotizacionPasajero` + 1 `Alternativa` directo a `aceptada` + 1 `AlternativaItem` + `Reserva` completa, todo en una transacción.

- Solo soporta `origen_tipo` `proveedor` y `manual` — comentario: `mayorista` necesita opción elegida de antemano y `pasaje_aereo` su propio submodelo, "ninguno de los dos cabe en un atajo de un solo paso".
- **Reusa literalmente** `AlternativaItemController::store()` invocándolo con `Request::create('/', 'POST', $validado)` y `app(AlternativaItemController::class)->store(...)` — no reimplementa la validación/cálculo.
- `Alternativa.moneda_cotizacion` = moneda de la tarifa (proveedor) o `moneda_costo` (manual); `tipo_cambio_aplicado = 1` siempre (misma moneda, sin conversión real).
- Tras crear el ítem: `alternativa.estado='aceptada'` → `AlternativaController::descartarOtras()` (no-op, única alternativa) → `ReservaController::crearReservaDesdeAlternativa()`.
- Cualquier `HttpException` lanzada por `AlternativaItemController::store()` (vía `abort()`) se recaptura y reformatea al contrato `{code, message}` del resto de la API.

---

## 10. `ReservaAnticipoController.php` — específico del vertical Agencia de Viajes (existe, distinto del genérico)

Ubicado en `AgenciaViajes/`, envuelve al `App\Http\Controllers\Advance\AdvanceController` genérico (core) sin duplicar su lógica — mismo patrón que `AdvanceController::refund()` usa para `NotaElectronicaController`.

### `store()` (POST cobrar anticipo desde la reserva)
- 422 si `reserva.estado !== 'activa'`.
- `client_id`/`moneda` se **derivan de la reserva** (nunca del payload) — *"el anticipo es de ESTA reserva, no de un cliente/moneda a elección libre (evita de raíz el guard de moneda distinta)"*.
- Construye un `Request` sintético y llama `app(AdvanceController::class)->store($requestAdelanto)` dentro de una transacción; con la respuesta crea `ReservaAnticipo` (`reserva_id`, `advance_id`, `monto_asignado`, `fecha_asignacion`).
- Contexto histórico citado: *"reserva_anticipos existía desde Sesión 8b... pero nunca tuvo ningún controller/ruta que la usara. Sin esto, ReservaFacturacionController::store() no tenía forma de saber que un cliente ya había pagado anticipos hacia una reserva, y generaba la venta pidiendo el 100% del total otra vez."*

### `destroy()` — desvincula el `ReservaAnticipo` (el `Advance` en sí no se toca, sigue existiendo con su plata). 422 si el `Advance` ya tiene `applications` (ya se consumió en una venta real) — no se puede destaggear después de usado.

---

## 11. Servicios

- **`app/Services/AgenciaViajes/PriceEngineService.php`** — motor de precios puro (sin Eloquent). `calcular()`: costo base + cargos pass-through (sin margen) + margen (`'fijo'` o `'porcentaje'`) → `venta_total`/`costo_total`. `evaluarPiso()`: piso = el **mayor** entre `costoBase*(1+margenMinimoPct/100)` y `ventaBase*(1-descuentoMaximoPct/100)`; null si ninguno configurado; tolerancia de 0.005 para evitar falsos positivos de redondeo. `calcularCombo()`: dirección inversa (venta bruta conocida menos descuento), separa `margen_resultante_pct` (mide solo sobre `venta_neta_combo`, sin ajuste de redondeo — el ajuste es cosmético, no de rentabilidad) de `venta_final_combo` (post-ajuste). `convertirMoneda()`: `tipo_cambio_agencia.valor` = cuántos PEN equivalen a 1 USD; USD→PEN multiplica, PEN→USD divide.
- **`app/Services/AgenciaViajes/ComboExplosionService.php`** — resuelve un `paquete_combo` contra sus `tours_simple` reales: `totalesTour()`/`totalesCombo()` (precio "desde" de catálogo), `explotarItems()`/`explotarTourSimple()` (líneas reales para cargar en el cotizador, con `modalidad` y precios crudos por tipo de pax), `itinerarioDerivado()` (concatena itinerarios con offset de día). Nota: líneas de guía **no se deduplican** entre tours del mismo combo — cada tour puede terminar con guía operativo distinto.
- **`app/Services/AgenciaViajes/CodigoGeneradorService.php`** — genera códigos correlativos por tipo (`cotizacion`, `venta_directa`) y `generarParaReserva()` (deriva del código de la cotización padre).
- **`app/Services/TextoFormatoService.php`** — capitaliza tipo-título en español (no cada palabra, artículos/preposiciones en minúscula) solo al escribir 7 campos de texto libre (nombres/títulos), nunca `razon_social` ni campos de clientes, nunca reescribe lo ya guardado.
- **`app/Services/StorageUrl.php`** — arma URLs de archivos tenant-aware vía `tenant_asset()` (nunca `/storage/` estático, que no es tenant-aware); `resolveParaPdf()` variante usada dentro de DomPDF porque `enable_remote=false` no puede cargar la URL HTTP normal de `resolve()`.
- **`app/Services/AdvanceApplicationService.php`** (genérico, core) — usado por `ReservaFacturacionController::store()` para aplicar `advance_applications` sobre el `Sale` recién creado.

---

## Notas transversales de arquitectura encontradas en el código (citas textuales relevantes para auditoría)

1. **Snapshot congelado vs referencia viva** — patrón consistente: `AlternativaItem.costo_snapshot/precio_venta_snapshot/precio_convertido/tip_afe_igv/destino_tributario` se congelan al crear el ítem y nunca se recalculan automáticamente salvo el caso puntual y acotado de `CotizacionController::recalcularItemsPorPersona()`. Las FKs (`proveedor_tarifa_id`, `guia_tarifa_id`, `opcion_mayorista_id`) permanecen como referencia viva — desactivar/editar la tarifa de catálogo no reescribe ítems ya creados.
2. **El total de una `Reserva` no es un snapshot propio** — se recalcula en vivo en `ReservaController::respuestaDetalle()` sumando `alternativaItem.total_convertido` de cada `ReservaItem`, es decir depende de que el `AlternativaItem` subyacente no cambie tras aceptada la alternativa (protegido por varios guards de "ya generó reserva", pero no por todos los caminos — p.ej. `AlternativaController::update()` con `descuento_global_pct` no verifica si la alternativa ya está aceptada).
3. **Doble capa de reasignación**: `fecha` de un `ReservaItem` tiene `fecha_origen` (`auto`/`manual`) explícito para que la reprogramación automática nunca pise una corrección manual — patrón repetido también implícitamente en `proveedor_tarifa_id`/`guia_id` de `ReservaItem` (reasignables, "quién opera" vivo) vs. el de `AlternativaItem` (congelado, "propuesta comercial").
4. **`cupo_ocupado` de `SalidaMayorista`** se mueve en exactamente 3 puntos: `+totalPax` al aceptar (`crearReservaDesdeAlternativa`), `-totalPax` al cancelar reserva completa, `-1` al quitar un pasajero individual (`ReservaPasajeroController::destroy`). Explícitamente **no** se toca en `reprogramar()`.
5. **Guardia tributario de facturación** actualmente solo permite `destino_tributario='nacional'` — Amazonía/extranjero están bloqueados en producción a la espera de definición contable, aunque el dato ya se captura y transporta desde la cotización.
6. **Facturación parcial por pasajero** deja explícitamente sin resolver el reparto de un ítem `tarifa_fija` compartido (ej. habitación doble) entre pasajeros facturados en `Sale`s distintos — documentado como limitación conocida, no bug oculto.

---

## Documento hermano

Este relevamiento cubre **controllers/services**. El relevamiento equivalente de
**schema/modelos Eloquent** (tablas, columnas, FKs, relaciones, con las mismas citas
textuales de diseño) vive en `auditoria-schema-modelos-agencia-viajes.md`, en esta misma
carpeta. Ambos documentos son el insumo crudo para la síntesis/clasificación final de la
auditoría arquitectónica pedida por el usuario (31-ago-2026) — todavía sin escribir.
