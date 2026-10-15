# Brief para Claude Code — Fila 13, fase 1b: el tour en la cotización

> Pega este archivo completo al inicio de una sesión nueva de Claude Code sobre el repo real.
> Redactado el 14-oct-2026 y **revisado con el usuario el mismo día contra el código** (listo
> para ejecutar). La fase 1 se partió en tres sesiones: **1a** (hecha), **1b** (esta) y **1c**
> (facturación por pasajero, mezcla de IGV en la factura y conversión de tours; borrador en
> `PEGAR-EN-CLAUDE-CODE-13-fase1c-facturacion-conversion.md`).

## 0. Lectura obligatoria antes de tocar código

1. `docs/planning/agencia-de-viajes/plan-tours-venta-simple-costo-real.md`, plan vigente. Sobre todo:
   - §6, con el bloque "Fase 1a — HECHA" y sus hallazgos;
   - §2 (N5, N6, N7, N13, N14, N16);
   - §3.1-§3.4, §3.9b (snapshot), §3.11, §3.12, §5b (interruptor) y §5c (C5).
2. `CLAUDE.md` del repo (fechas con `HoraPeru`, `tenants:migrate-verticales`).
3. Qué dejó la 1a (en `main` desde `4023c99`), para reutilizarlo y **no reimplementarlo**:
   - `TourCostoEstimadoService::costoDelTour()`: costo del tour, suma del Incluye o escrito a mano.
   - `TemporadaResolverService::precioParaFecha($tour, $fecha)`: precio de temporada o `null` (= precio base). Con temporadas superpuestas gana la más corta; si la más específica no tiene precio en el tour, cae a la siguiente.
   - Columnas del tour: `precio_venta_final` (adulto nacional), `precio_venta_nino`, `precio_venta_adulto_ext`, `precio_venta_nino_ext` (vacío = nacional; niño vacío = adulto), `costo_estimado_persona[_ext]`, `moneda`, `tip_afe_igv`/`destino_tributario` (vacío = `ConfiguracionAgencia::tratamientoTributarioDefault()`), `modalidad`, `pax_minimo_salida`, `uso`.
   - Incluye: `paquete_plantilla_items` con `destino_servicio_id`, `proveedor_id`, `costo_estimado_persona[_ext]`, `tip_afe_igv`/`destino_tributario` (vacío = el del tour).
   - `paquete_plantilla_precios` (precios por temporada).
   - Pantallas: `TourPrecioVentaCampos.vue`, `TourIncluyeCostos.vue`, `TourPreciosTemporada.vue` y `MargenEnVivo.vue`.
4. **No implementar** `plan-precio-tour-por-persona.md` (descartado).

## 1. Decisiones ya tomadas con el usuario (14-oct-2026) — no reabrirlas

- **D1. Factura de un tour con IGV mezclado:** un solo comprobante con una línea exonerada y otra gravada (respuesta del contador en P1: "cada concepto con su afectación"). Se implementa en la **1c**. En la 1b, las líneas del mismo tour solo deben quedar **identificables como una misma carga** (ver §3, "Carga de tour").
- **D2. La fase 1 se parte en 1b y 1c.** La 1b no toca `ReservaFacturacionController`, salvo la clasificación de categoría (§3). Tampoco hace facturación por pasajero ni la conversión de tours.
- **D3. El modo nuevo se activa solo en `agencia-demo` hasta terminar la fase 2.** El Reporte Operativo y las Salidas Operativas no entienden la línea de tour hasta entonces. El interruptor lo cambia **solo el Super-Admin (soporte)**: el dueño de la agencia no lo ve. En producción todos los tenants siguen en `clasico`.

## 2. Antes de empezar

1. `git worktree list` y `git status`. Trabajar en una rama nueva `feat/tours-fase1b-cotizacion` desde `main`.
2. Línea de base: suite del backend (1417 tests en verde al cerrar la 1a) y type-check del frontend (46 errores, todos anteriores).
3. Respaldar los datos de `agencia-demo` que se usen en la verificación en vivo (la 1a usó el tour #12 Laguna Azul).

## 3. Alcance

### Interruptor (§5b)
- `configuracion_agencia.modo_tours` (`clasico` | `nuevo`, por defecto `clasico`). Migración del vertical, solo aditiva.
- Se edita en Configuración de Agencia, **visible y editable solo para el Super-Admin** (D3). Validarlo también en el backend.
- `desdePlantilla()` actual **no se borra ni se modifica**: es el camino `clasico`. El camino `nuevo` es código aparte (un servicio, por ejemplo `TourLineaService`), elegido según el interruptor.

### Línea de tour en la cotización (N6)
- Nuevo `AlternativaItem::ORIGEN_TOUR = 'tour'`, con `tour_origen_id` = el tour y `modo_precio = 'por_persona'`.
- **Precio** = Σ por pasajero de la cotización del precio que le toca: adulto o niño × nacional o extranjero, con los vacíos ya definidos. El infante paga 0.
  - **Temporada:** la del **día del tour** = `fecha_viaje_desde + dia_referencial − 1`, vía `precioParaFecha()`.
  - **Sin fecha de viaje:** precio base, con aviso.
  - La línea guarda **qué temporada se aplicó** (para mostrarla y para no recalcular sola) y el vendedor puede cambiar el precio a mano.
- **Costo** = Σ por pasajero del costo estimado (nacional o extranjero); el infante no suma.
- **Moneda:** la del tour, convertida a la de la alternativa con `PriceEngineService::convertirMoneda()`, como hoy.
- **IGV:** el de los servicios del Incluye (N5). Ver "IGV mezclado".
- Un tour `solo_itinerario` **no se puede cargar** (422 con mensaje). Un tour `venta` **sin precio** tampoco: hay que completarlo antes en el catálogo.

### IGV mezclado (N14)
- Si todos los servicios del Incluye tienen el mismo IGV (lo normal): **una línea**.
- Si hay mezcla: **una línea por grupo de IGV**, con el precio repartido en proporción al costo estimado de cada grupo. Los centavos sobrantes van al grupo de mayor costo, para que la suma sea exacta.
  - Ejemplo del plan: S/ 100 con costos 60 exonerado y 20 gravado → 75.00 / 25.00.
- Si hay mezcla y algún servicio no tiene costo estimado: **bloquear la carga** con un mensaje que diga qué servicio falta.
- **Carga de tour:** las líneas de una misma carga comparten un identificador (por ejemplo, una columna `carga_tour` en `alternativa_items`, copiada a `reserva_items`). Sirve para mostrarlas juntas como un solo tour, para moverlas y quitarlas juntas, y para que la **1c** permita la mezcla de IGV en la factura **solo** entre líneas de una misma carga de tour (D1).

### Snapshot de costo (§3.9b)
- `desglose_costo_snapshot` (JSON) en la línea: ítem del Incluye, servicio, costo por persona nacional y extranjero, y cuántos pasajeros lo toman.
- Se copia a `reserva_items` al aceptar, en `ReservaController::crearReservaItemDesdeAlternativaItem()`, que es el único punto de creación de `reserva_items` (aceptar y sincronizar).

### Pasajeros
- `cotizacion_pasajeros.residencia` (`nacional` | `extranjero`, por defecto `nacional`), con selector en las pantallas de pasajeros (`cotizador/nueva.vue` y `cotizador/editar.vue`). No es la nacionalidad (§3.3).
- **Recalcular al cambiar pasajeros (hallazgo de la revisión):** hoy `CotizacionController::recalcularItemsPorPersona()` solo reprecia líneas `proveedor` con tarifa; el resto lo marca "para revisar a mano". La línea de tour debe recalcularse sola: precio y costo por pasajero, misma temporada ya aplicada y mismo reparto por IGV. Si el vendedor cambió el precio a mano, **no pisarlo**: avisar.
- Corrige de paso **E2** para las líneas por persona de proveedor: hoy actualiza la venta pero no el costo.

### Fechas de la cotización
- Si cambia `fecha_viaje_desde` de una cotización en borrador y algún tour queda en otra temporada: aviso "las fechas cambiaron de temporada" con el botón **"Recalcular precios por temporada"**. Nunca recalcular solo (§3.12).
- Reserva aceptada que se reprograma a otra temporada: el precio **no cambia**, solo aviso.

### Combos (N7, E3)
- Un combo se carga como **una línea de tour por cada tour** (cada una en su día y con la temporada de **su** día), más sus ítems sueltos como hoy.
- Precio del combo: suma de los tours menos el descuento del combo. Se reparte **entre las líneas de sus tours en proporción al precio de lista de cada tour**. Esto corrige **E3**: hoy el descuento del combo no llega a la cotización.
- Las líneas quedan agrupadas visualmente como "Combo X".

### Nombres, lienzo, reserva y PDF
- `ReservaController::resolverNombreItem()`: una línea `tour` muestra el nombre del tour (más "(exonerado)"/"(gravado)" si es una de varias líneas por IGV).
- Revisar **todos** los lugares que leen `origen_tipo` y decidir qué hacen con `tour`. Lista verificada en la revisión:
  - Backend: `AlternativaController`, `AlternativaItemController`, `CotizacionController`, `ReporteOperativoController`, `ReservaController`, `ReservaFacturacionController`, `ReservaItemPasajeroController`, `SalidaOperativaController`, `VentaDirectaController` (solo acepta `proveedor,manual`; queda igual), `AlternativaPdfService` y `AsignacionOperativa`.
  - Frontend: `alternativaItemService.ts`, `reservaItemService.ts`, `ventaDirectaService.ts`, `types/agencia-viajes.ts`, `cotizador/editar.vue`, `reporte-operativo/index.vue`, `reservas/detalle.vue` y `venta-directa.vue`.
- **Reporte Operativo** (`AsignacionOperativa`): la línea de tour sale como una fila más, por reserva. No se engancha a Salida Operativa (eso es fase 2, C3). `sinAsignar()` no debe marcarla como "sin asignar".
- **PDF (C5):** el Incluye de una línea de tour sale de la **lista del tour** (servicios del Incluye), no del nombre de la línea. Itinerario y fotos siguen igual (vía `tour_origen_id`).
- **Facturación, solo esto:** `ReservaFacturacionController::clasificarCategoria()` clasifica `origen_tipo = tour` como `'TOUR'`; hoy caería en `'OTROS'`. Un tour sin mezcla de IGV se factura entero como cualquier línea. La mezcla y la facturación por pasajero son de la 1c.

### Pantalla del tour (decidido con el usuario)
- **Unir las dos pestañas del Incluye en una sola "Incluye y costos"** (nombre elegido por el usuario). Hoy son dos pestañas sobre la misma lista (`paquete_plantilla_items`):
  - **"Agregar Costos"** (antigua): biblioteca de proveedores y guías, venta y costo de cada tarifa;
  - **"Incluye y costos"** (de la 1a): costo por persona, IGV, calculadora y servicio sin tarifa.
- La pestaña unificada permite agregar desde la biblioteca o sin tarifa, quitar y editar costo e IGV en la misma lista. La "venta de la tarifa" queda solo como referencia del modo clásico.
- **No se borra nada:** el modo clásico sigue leyendo las tarifas mientras dure el piloto.
- **"Ajuste de redondeo"** desaparece de la pantalla del tour simple. En el camino `nuevo` no se usa; en el `clasico` sigue funcionando igual. La columna y su lógica se borran recién al terminar el piloto (§5b). El filtro por nombre de `AsignacionOperativa::itemsDelRango()` se queda. Nadie lo usa hoy (0 tours en `agencia-demo` y 0 en dakamu).

### Cotizador (frontend)
- Biblioteca de tours: mostrar el precio por persona, ocultar o deshabilitar los tours `solo_itinerario` y avisar los de venta sin precio.
- La línea de tour en el lienzo: nombre, precio, temporada aplicada (badge), Incluye desplegable y precio editable. Las líneas de una misma carga se ven juntas y se mueven y quitan juntas.
- Panel de precio de la alternativa: suma la línea como cualquier otra.

## 4. No entra (1c o después)
- **1c:** factura con IGV mezclado en un comprobante (D1); facturación por subgrupo de pasajeros con la línea de tour repartida por pasajero (C1); conversión de los tours existentes, tour por tour (proponiendo el **IGV de la tarifa** en cada ítem, hallazgo 3 de la 1a).
- **Fase 2:** salida por línea de tour, Reporte Operativo por salida, `AsignacionOperativa` y el Inicio con salidas (C3), y `reprogramar()` que mueve líneas de tour (C4).

## 5. Reglas para esta sesión
- Explicar antes de corregir. Si aparece algo no previsto, **parar, mostrar el hallazgo y tratarlo como sub-paso propio**.
- **E5** (guía contado como 1 día en `resolverCostoVentaItem()`, cotizador clásico): no tocarlo salvo decisión explícita, y en un commit aparte.
- Migraciones solo aditivas, con `down()` probado, aplicadas con `tenants:migrate-verticales` y a `sistemafe_test_migrations` **por archivo**: esa base tiene un desfase anterior con `2026_09_07_120000`.
- Fechas con `HoraPeru` (hay un test que vigila `now()->toDateString()` y `whereDate()`).
- Verificación en vivo con Playwright en `agencia-demo`, con `modo_tours = nuevo` solo durante la prueba; revertir los datos al terminar. Login: `admin@agencia-demo.test` en `/admin/login`; el botón es "Iniciar sesión", no el primero del formulario. No resetear contraseñas.
- No commitear sin confirmar.

## 6. Tests mínimos
- **Interruptor:** en `clasico`, `desdePlantilla()` da exactamente lo de hoy (reusar el test de regresión de la 1a). En `nuevo`, una línea con precio por pasajero: 2 adultos + 1 niño + 1 infante nacionales, y un caso con un adulto extranjero. Solo el Super-Admin cambia el interruptor.
- **Bloqueos:** tour `solo_itinerario` y tour de venta sin precio.
- **Temporada:** fecha dentro de una temporada y fuera de todas; cotización sin fecha; combo con tours en días de temporadas distintas; cambio de fecha en borrador (aviso, sin recálculo automático).
- **IGV mezclado:** reparto exacto con centavos (100 → 75 / 25, y un caso con centavos impares); bloqueo si falta un costo; líneas con el mismo identificador de carga.
- **Pasajeros:** agregar o quitar pasajeros reprecia la línea de tour; precio editado a mano no se pisa; E2 (el costo se actualiza en las líneas por persona de proveedor).
- **Combos:** el descuento llega a la cotización (E3) y el reparto entre tours suma exacto.
- **Reserva:** al aceptar se copian `desglose_costo_snapshot`, carga de tour e IGV.
- **Lectura de los dos formatos:** cotización, reserva, Reporte Operativo y PDF con líneas viejas y de tour en la misma alternativa.
- **Facturación:** una reserva con una línea de tour sin mezcla se factura entera y la línea se clasifica `TOUR`.
- Suite completa del backend en verde; type-check del frontend sin errores nuevos (46 de base).

## 7. Cierre
- Plan §6: marcar la 1b y anotar hallazgos. Hoja de ruta: fila 13 e historial.
- Memoria: `project_precio_tour_por_persona_y_endoso`.
- Terminar el brief de la **1c** (`PEGAR-EN-CLAUDE-CODE-13-fase1c-facturacion-conversion.md`) con lo aprendido.
