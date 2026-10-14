# Brief para Claude Code — Fila 13, fase 1a: el tour en el catálogo (precio, Incluye, temporadas)

> Pega este archivo completo al inicio de una sesión nueva de Claude Code sobre el repo real.
> Redactado el 14-oct-2026 al cerrar la sesión de diseño. **La fase 1 del plan se partió en
> dos sesiones** (1a y 1b) porque completa son 6.5–7.5 días: esta es la **1a**.

## 0. Lectura obligatoria antes de tocar código

1. `docs/planning/agencia-de-viajes/plan-tours-venta-simple-costo-real.md` — **plan vigente**.
   Para esta sesión, sobre todo: §1, §2 (N1-N5, N13-N16), §3.1, §3.2, §3.3, §3.9b
   (calculadora), §3.11, §3.12 (temporadas), §5b (reversa), §5c (choques) y §6.
2. `CLAUDE.md` del repo: regla de fechas (`HoraPeru`), `tenants:migrate-verticales`, estilo de
   trabajo.
3. **No implementar** `plan-precio-tour-por-persona.md`: está descartado.

## 1. Qué resuelve esta sesión

Hoy el tour tiene un precio escrito (`precio_venta_final`) que el cotizador nunca usa, y su
"Incluye" son líneas atadas a tarifas. Esta sesión deja **el tour completo en el catálogo**,
listo para que la sesión 1b lo cargue en la cotización:

- Precio por persona: adulto y niño, nacional y extranjero (opcional), y **por temporada**.
- Costo estimado: por servicio del Incluye y total, con **calculadora** desde las tarifas.
- IGV **por servicio del Incluye** (el contador confirmó que un tour con servicios gravados y
  exonerados no puede facturarse con un solo tipo).
- Campo **"Uso"**: `venta` o `solo_itinerario` (tours que solo sirven de itinerario a los
  paquetes de mayorista).
- Diagnóstico de los tours existentes, con la propuesta de conversión **para que el usuario
  decida tour por tour**.

**Esta sesión NO toca el cotizador ni la cotización:** todo lo que hoy se cotiza sigue igual.
Es aditivo y no cambia ningún comportamiento visible fuera de la pantalla del tour.

## 2. Alcance

### Entra

**Migraciones (vertical agencia-viajes, solo aditivas, con `down()` probado):**
- `paquetes_plantilla`: `precio_venta_nino`, `precio_venta_adulto_ext`, `precio_venta_nino_ext`,
  `costo_estimado_persona`, `costo_estimado_persona_ext`, `moneda` (default `PEN`),
  `tip_afe_igv`, `destino_tributario`, `modalidad` (`compartido`|`privado`, default
  `compartido`), `pax_minimo_salida`, `uso` (`venta`|`solo_itinerario`, default `venta`).
  `precio_venta_final` se reutiliza como **precio adulto nacional** (no se renombra ni se
  transforma).
- `paquete_plantilla_items` (es el Incluye): `costo_estimado_persona`,
  `costo_estimado_persona_ext`, `tip_afe_igv`, `destino_tributario`, `proveedor_id` sugerido
  (opcional). `proveedor_tarifa_id`/`guia_tarifa_id` se quedan, solo para la calculadora.
- Tabla nueva `paquete_plantilla_precios` (§3.12): tour, `temporada_id`, precios
  (adulto/niño × nacional/extranjero) y costo estimado opcional.
- **Backfill de `uso`** (N15): vinculado a `opcion_mayorista_tours.paquete_plantilla_id` y sin
  `precio_venta_final` → `solo_itinerario`; el resto → `venta`.

**Backend:**
- `PaquetePlantillaController`: validar y guardar los campos nuevos. Las validaciones de
  precio, costo e IGV aplican **solo** a `uso = venta`.
- Calculadora (§3.9b): endpoint que sugiere el costo por persona de cada ítem del Incluye a
  partir de su tarifa: por persona tal cual; por vehículo o día ÷ `pax_minimo_salida`; **guía ×
  días del tour** (máximo `dia_relativo` del itinerario `?? 1`; hoy `resolverCostoVentaItem()`
  lo cuenta como 1 día: es el error E5). Con temporada, usa las tarifas de esa temporada.
- Costo del tour = suma de los costos del Incluye cuando todos los tienen; si no, el escrito
  a mano.
- Servicio de **resolución de temporada** (§3.12): dada una fecha, qué ocurrencia de
  `temporadas`/`temporada_ocurrencias` la cubre; si se superponen, gana la de **rango más
  corto**. Lo usará la sesión 1b al cargar el tour; acá se deja hecho y probado.
- Comando de diagnóstico **de solo lectura**, por ejemplo `agencia-viajes:diagnosticar-tours`:
  por tour, uso propuesto, precio cargado, suma de costos y ventas de sus tarifas, si mezcla
  tipos de IGV, si tiene guía o servicios privados, si está vinculado a mayoristas.

**Frontend (`paquetes/form.vue`, `paquetes/detalle.vue`):**
- Selector de uso, modalidad y moneda; precios adulto/niño nacional y, plegable, extranjero.
- Pestaña o bloque **"Incluye y costos"**: una fila por servicio con tarifa de referencia
  opcional, costo por persona editable, tipo de IGV y botón **"Calcular desde tarifas"**.
- **Margen en vivo** (S/ y %) contra `margen_minimo_pct` del tour y el de la agencia.
- **Precios por temporada**: tabla para agregar, editar y quitar.
- Catálogo: etiqueta y filtro para los tours `solo_itinerario`.
- Estilo: el de Agencia y Ventas (inputs `-sm`, cards con `card-header`; ver memoria
  `feedback_estilo_ui_como_agencia_y_ventas`).

### No entra (sesión 1b o posteriores)

Interruptor `modo_tours`, línea de tour en la cotización (una por tipo de IGV), residencia de
los pasajeros en la cotización, combos con una línea por tour y el descuento E3, el Incluye del
PDF desde la lista del tour (C5), la facturación por pasajero (C1), el retiro de
`ajuste_redondeo`, y **la conversión real de los tours existentes** (aquí solo el diagnóstico).

## 3. Antes de empezar

1. `git worktree list` y `git status`: confirmar en qué rama y carpeta se trabaja (la topología
   de worktrees cambió antes). Crear la rama `feat/tours-fase1a-catalogo` desde `main`.
2. **Diagnóstico en producción (dakamu), solo lectura.** Lo corre el usuario (no hay acceso a
   producción desde esta máquina). Pasarle este bloque para `php artisan tinker`:

   ```php
   tenancy()->initialize(App\Models\Tenant::find('dakamu'));
   echo json_encode([
     'tours' => DB::table('paquetes_plantilla')->selectRaw("tipo, count(*) n, count(precio_venta_final) con_precio, count(*) filter (where coalesce(ajuste_redondeo,0)<>0) con_ajuste")->groupBy('tipo')->get(),
     'tours_en_mayoristas' => DB::table('opcion_mayorista_tours')->whereNotNull('paquete_plantilla_id')->distinct()->count('paquete_plantilla_id'),
     'tarifas' => DB::select("select modalidad, (tipo_habitacion is not null) hotel, count(*) n from proveedor_tarifas group by 1,2"),
     'tarifas_con_temporada' => DB::table('proveedor_tarifas')->whereNotNull('temporada_id')->count(),
     'tours_igv_mezclado' => DB::select("select count(*) n from (select pi.paquete_plantilla_id from paquete_plantilla_items pi join proveedor_tarifas pt on pt.id=pi.proveedor_tarifa_id group by 1 having count(distinct coalesce(pt.tip_afe_igv,'x'))>1) s")[0]->n,
   ], JSON_PRETTY_PRINT);
   ```
   Anotar el resultado en el plan (§7) antes de escribir migraciones: si algo no coincide con
   lo esperado (por ejemplo, muchos tours vinculados a mayoristas con precio cargado), **parar y
   consultar**.

## 4. Reglas para esta sesión

- **Explicar antes de corregir** y, si aparece algo no previsto en el plan, **parar, mostrar el
  hallazgo y tratarlo como sub-paso propio** (memoria `feedback_plan_verification_discipline`).
- Fechas: `HoraPeru` para "hoy" y para filtrar por día; nunca `now()->toDateString()` ni
  `whereDate()` (hay un test que lo vigila).
- Migraciones del vertical: aplicar con `php artisan tenants:migrate-verticales`, **nunca**
  `tenants:migrate` a secas. Aplicarlas también a `sistemafe_test_migrations` (con
  `DB_DATABASE=sistemafe_test_migrations php artisan migrate --path=...`) y probar el `down()`.
- `agencia-demo`: verificación en vivo con Playwright (login `admin@agencia-demo.test`; no
  resetear contraseñas). **Revertir** cualquier dato de prueba al terminar.
- No commitear sin confirmar; si hay archivos editados a mano por el usuario, preguntar si van.

## 5. Tests mínimos

- Validaciones por `uso`: `solo_itinerario` no exige precio, costo ni IGV.
- Backfill de `uso` con un tour vinculado a mayorista sin precio, otro con precio y otro suelto.
- Calculadora: por persona; por vehículo ÷ mínimo; **guía × días** (tour de 3 días); con
  temporada; sin tarifa (queda vacío, no inventa un número).
- Resolución de temporada: fecha dentro de una, fuera de todas, dos superpuestas (gana la más
  corta), bordes de inicio y fin.
- Costo del tour = suma del Incluye; con un ítem sin costo, el escrito a mano.
- Diagnóstico: no escribe nada (comparar la base antes y después).
- Regresión: la cotización desde plantilla (`desdePlantilla()`) da **exactamente** el mismo
  resultado que antes (esta sesión no debe cambiarla). Excepción esperada: el guía de un tour
  de varios días, solo si se corrige E5 en `resolverCostoVentaItem()`; si se corrige, que sea
  un commit aparte y explicado.
- Suite completa del backend en verde; type-check del frontend sin errores nuevos frente a la
  base (al cerrar la sesión anterior eran 46 preexistentes).

## 6. Cierre de la sesión

- Plan: marcar lo hecho de la fase 1a y anotar los hallazgos.
- Hoja de ruta: actualizar la fila 13 y su historial.
- Memoria del proyecto: `project_precio_tour_por_persona_y_endoso`.
- Dejar preparado (o redactado) el brief de la **1b**.

## Anexo — esbozo de la sesión 1b (para el brief siguiente)

Interruptor `modo_tours` por tenant (`clasico` por defecto, §5b); línea de tour en la
cotización (nuevo `origen_tipo`, una línea por grupo de IGV con reparto por costo, N14) con la
copia del costo por servicio (`desglose_costo_snapshot`, §3.9b); residencia nacional/extranjero
en `cotizacion_pasajeros` (§3.3); precio por temporada aplicado según la fecha del día del tour
(§3.12); combos con una línea por tour y el descuento que hoy no llega (E3, §3.4); Incluye del
PDF desde la lista del tour (C5); desglose de precio por pasajero para facturar por subgrupo
(C1); lectura de los dos formatos en todas las pantallas (§3.11); retiro de `ajuste_redondeo`
manteniendo el filtro por nombre de `AsignacionOperativa::itemsDelRango()` (§6 del plan
anterior); conversión de los tours existentes con confirmación del usuario tour por tour.
