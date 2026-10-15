# Brief para Claude Code — Fila 13, fase 1b: el tour en la cotización

> Pega este archivo completo al inicio de una sesión nueva de Claude Code sobre el repo real.
> Redactado el 14-oct-2026, al cerrar la fase 1a. **Borrador:** revísalo con el usuario antes
> de ejecutar. Es la segunda mitad de la fase 1 del plan.

## 0. Lectura obligatoria antes de tocar código

1. `docs/planning/agencia-de-viajes/plan-tours-venta-simple-costo-real.md`, plan vigente. Sobre todo:
   - §6, con el bloque "Fase 1a — HECHA" y sus **hallazgos**;
   - §2 (N5, N6, N7, N13, N14, N16);
   - §3.1-§3.4, §3.9b (snapshot), §3.11, §3.12, §5b (interruptor) y §5c (C1, C5).
2. `CLAUDE.md` del repo (fechas con `HoraPeru`, `tenants:migrate-verticales`).
3. Qué dejó la 1a, para reutilizarlo:
   - `TourCostoEstimadoService`: calculadora y costo del tour.
   - `TemporadaResolverService::precioParaFecha()`: ya resuelve el precio por fecha, con temporadas superpuestas.
   - `DiagnosticoToursService` y el comando `agencia-viajes:diagnosticar-tours`: propuesta de conversión.
   - `paquete_plantilla_items.destino_servicio_id`, costos e IGV por ítem; `paquete_plantilla_precios`.
4. **No implementar** `plan-precio-tour-por-persona.md` (descartado).

## 1. Antes de empezar

1. `git worktree list` y `git status`. Confirmar si la rama `feat/tours-fase1a-catalogo` ya se unió a `main`; si no, preguntar si la 1b sale de ella o de `main`.
2. **El diagnóstico de dakamu debe estar hecho** (§7 del plan). Si no lo está, pedírselo al usuario antes de diseñar la conversión.

## 2. Alcance

**Interruptor y línea de tour:**
- `modo_tours` por tenant (`clasico` | `nuevo`, por defecto `clasico`, §5b) en la configuración de la agencia. `desdePlantilla()` clásico **no se borra**: queda detrás del interruptor.
- **Línea de tour** en la cotización: nuevo `origen_tipo`.
  - Precio = Σ por pasajero (adulto/niño × nacional/extranjero), con la temporada del **día del tour** (`fecha_viaje_desde + dia_referencial − 1`).
  - Sin fecha de viaje: precio base, con aviso.
  - Se muestra qué temporada se aplicó.
  - Un tour `solo_itinerario` no se puede cargar.

**IGV mezclado y snapshot:**
- IGV mezclado (N14): una línea por grupo de IGV, con el precio repartido en proporción al costo estimado del grupo; los centavos van al grupo de mayor costo.
- Si falta algún costo en el Incluye y hay mezcla de IGV, se bloquea con un mensaje.
- `desglose_costo_snapshot` en la línea (§3.9b), copiado a la reserva al aceptar.

**Pasajeros y combos:**
- Residencia `nacional`/`extranjero` en `cotizacion_pasajeros` (§3.3), con selector en la pantalla de pasajeros.
- Combos: una línea por tour, cada una en su día, con su temporada. El precio del combo (suma menos descuento, o precio fijo) se reparte en proporción al precio de lista de cada tour. Corrige **E3**: el descuento hoy no llega a la cotización.

**Documentos y facturación:**
- PDF: el Incluye de una línea de tour sale de la lista del tour (C5).
- Facturación por subgrupo de pasajeros (C1): desglose de precio por pasajero en la línea.
- Lectura de los dos formatos en todas las pantallas (§3.11).

**Pantalla del tour (decidido con el usuario, 14-oct-2026):**
- **Unir las dos pestañas del Incluye en una sola llamada "Incluye y costos"** (nombre elegido por el usuario: más informativo e intuitivo). Hoy son dos pestañas sobre la misma lista (`paquete_plantilla_items`):
  - **"Agregar Costos"** (antigua): biblioteca de proveedores y guías, venta y costo de cada tarifa;
  - **"Incluye y costos"** (fase 1a): costo por persona, IGV, calculadora y servicio sin tarifa.
- La pestaña unificada permite agregar desde la biblioteca o sin tarifa y editar costo e IGV en la misma lista. La "venta de la tarifa" queda solo como referencia del modo clásico.
- **No se borra nada:** la tabla se reutiliza (la tarifa pasa a ser referencia de la calculadora) y el modo clásico sigue leyendo las tarifas mientras dure el piloto (§5b).
- El campo **"Ajuste de redondeo"** del tab Datos se retira de la pantalla junto con esta unificación. El usuario prefirió esperar a la 1b en vez de ocultarlo antes. Nadie lo usa: 0 tours en `agencia-demo` y 0 en dakamu.

**Retiro y conversión:**
- Retiro de `ajuste_redondeo`, manteniendo el filtro por nombre de `AsignacionOperativa::itemsDelRango()`. La columna y la lógica del modo clásico se borran recién al terminar el piloto con éxito (§5b); en la 1b solo dejan de usarse en el camino nuevo y desaparecen de la pantalla.
- Conversión de los tours existentes, **confirmada tour por tour por el usuario**, partiendo de `diagnosticar-tours`.
- **Hallazgo 3 de la 1a:** la conversión debe proponer el **IGV de la tarifa** en cada ítem del Incluye, no dejarlo vacío.

## 3. Reglas para esta sesión

- Explicar antes de corregir. Si aparece algo no previsto, **parar, mostrar el hallazgo y tratarlo como sub-paso propio**.
- **E5** (guía contado como 1 día en `resolverCostoVentaItem()`, cotizador clásico): solo si se decide tocarlo, en un commit aparte y explicado.
- Migraciones solo aditivas, con `down()` probado, aplicadas con `tenants:migrate-verticales` y a `sistemafe_test_migrations` **por archivo**: esa base tiene un desfase anterior con `2026_09_07_120000`.
- Verificación en vivo con Playwright en `agencia-demo`, revirtiendo los datos de prueba (respaldarlos antes).
- No commitear sin confirmar.

## 4. Tests mínimos

- **Interruptor:** en `clasico`, `desdePlantilla()` da exactamente lo de hoy; en `nuevo`, una línea con el precio por pasajero (adulto, niño, infante 0, extranjero).
- **Temporada:**
  - fecha dentro de una temporada y fuera de todas;
  - cotización sin fecha;
  - combo con tours en días de temporadas distintas.
- **IGV mezclado:**
  - reparto exacto con centavos (ejemplo del plan: 100 → 75 / 25);
  - bloqueo si falta un costo.
- **Combos:** descuento que llega a la cotización (E3).
- **Facturación por subgrupo** con línea de tour: toma exactamente la parte de los pasajeros elegidos.
- **Lectura de los dos formatos:** reserva, Reporte Operativo, facturación y PDF con líneas viejas y nuevas mezcladas en la misma alternativa.
- Suite completa del backend en verde; type-check del frontend sin errores nuevos (46 de base).

## 5. Cierre

- Plan §6: marcar la 1b. Hoja de ruta: fila 13 e historial.
- Memoria: `project_precio_tour_por_persona_y_endoso`.
- Brief de la **fase 2** (la salida como centro).
