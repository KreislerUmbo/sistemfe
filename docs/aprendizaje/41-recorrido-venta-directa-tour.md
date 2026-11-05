# Recorrido: vender un tour del catálogo desde Venta directa

05-nov-2026, tarea **T-047** (H41). Decisión: **D-AGE-034** (maqueta). Reglas de tours: D6-D18 en
`plan-tours-venta-simple-costo-real.md` §6.

---

## 1. El problema

Venta directa solo vendía una tarifa de proveedor o un concepto libre. El tour del catálogo, que es
lo que más se vende en el día a día, obligaba a pasar por el cotizador. Además, un servicio suelto
no tiene tour, así que la venta directa nunca entraba en la **salida operativa**: no contaba para el
almuerzo ni para el vehículo.

La idea central de T-047: **no reimplementar nada**. El tour entra a la alternativa por el mismo
método que usa el cotizador, y la reserva se engancha a la salida con el mismo servicio que usa
«Aceptar cotización».

## 2. Archivos

| Archivo | Qué cambió |
|---|---|
| `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php` | Línea `origen_tipo='tour'`: `resolverTour()`, `vistaPreviaTour()`, `totalTourClasico()`; `store()` llama a `desdePlantilla()`; `facturable()` entiende las cargas de tour; la respuesta trae `avisos_salida_cancelada` y `avisos` |
| `api-sistema-fe/app/Http/Controllers/AgenciaViajes/BibliotecaCotizadorController.php` | Los tours traen su `destinoAtractivo` (para llenar el destino de la venta) |
| `admin-start-kit/src/views/agencia-viajes/venta-directa.vue` | Pestaña **Tours** (la primera), tarjetas, línea con etiqueta Tour, detalle por residencia, toast con los avisos al crear |
| `admin-start-kit/src/services/admin/ventaDirectaService.ts` | Tipos: línea `tour`, `afectaciones`, `tour.detalle`, avisos de la respuesta |
| `api-sistema-fe/tests/Feature/AgenciaViajes/VentaDirectaTourTest.php` | 12 tests (CA1-CA6 y casos de borde) |

Tablas: ninguna nueva. Se escriben las mismas que en el cotizador: `alternativa_items` (con
`carga_tour`, `tour_origen_id`), `reserva_items` (con `fecha` y `salida_operativa_id`) y
`salidas_operativas`.

## 3. El recorrido

### 3.1 Pantalla: la pestaña Tours

`cargarCatalogo()` pide en paralelo (`Promise.all`) las tarifas, los tours y los combos a la
biblioteca del cotizador. Los combos se muestran, pero `estadoTour()` les pone el botón «Solo
desde el cotizador» deshabilitado (D-AGE-034). El mismo `estadoTour()` explica por qué no se puede
agregar un tour: «Sin precio», «Está en dólares» o «Agregado».

`agregarTour()` crea una línea `{ origen: 'tour', tour }`. Si la venta todavía no tiene destino,
toma el del tour: por eso `BibliotecaCotizadorController` ahora carga `destinoAtractivo`.

Al servidor solo viaja `{ origen_tipo: 'tour', dia, paquete_plantilla_id }`. El precio **no** sale
de la pantalla: lo calcula el backend con el catálogo.

### 3.2 Backend: validar la línea (`resolverTour()`)

1. `exists:paquetes_plantilla,id` en las reglas (base del tenant).
2. Combo → 422 «Los combos se venden desde el cotizador.»; tour desactivado o solo de itinerario →
   422.
3. Según `ConfiguracionAgencia::modoToursNuevo()`:
   - **nuevo**: `TourLineaService::specDelTour($tour, fecha)`, con la **fecha de la línea** (inicio +
     día − 1), no la de inicio. De ahí salen el precio por residencia y la temporada.
   - **clásico**: `ComboExplosionService::explotarTourSimple()` desarma el tour en sus servicios
     (cada uno con su tarifa, moneda y afectación).
4. `dias_extra`: un tour de varios días alarga `fecha_viaje_hasta` de la reserva.

### 3.3 Vista previa (sin guardar)

`vistaPreviaTour()` en modo nuevo cuenta los pasajeros por residencia (`adulto_nac`, `adulto_ext`,
`nino_nac`, `nino_ext`, `infante`) y llama a `TourLineaService::calcular()`. Devuelve el total, el
detalle («2 adultos nac. × 60 + 1 adulto ext. × 60») y la temporada.

En modo clásico no hay un camino «sin guardar» en el cotizador, así que `totalTourClasico()` repite
la cuenta de `crearItemClasicoDesdeEntrada()`. El test CA4 comprueba que vista previa, venta creada
y cotizador dan el mismo total: si uno cambia, el test avisa.

**Tributario:** un tour de IGV mezclado son varias líneas de la **misma carga**. `facturable()` les
da `carga_tour = "tour-{i}"` para que `CriterioTributarioService` las acepte juntas en un
comprobante.

### 3.4 Crear (`store()`)

Dentro de la misma transacción de siempre, cada línea de tour va a
`AlternativaItemController::desdePlantilla()`, el mismo método del botón «Agregar tour» del
cotizador. Después, `crearReservaDesdeAlternativa()` crea la reserva y `SalidaEngancheService` la
engancha a la salida del tour **de su fecha**. Si la salida ya existía, suma las cantidades; si
estaba cancelada, la respuesta trae `avisos_salida_cancelada` (T-042).

La pantalla muestra esos avisos (y los del tour, p. ej. «no tiene costo estimado») con
`toast.warning()` antes de ir a la reserva, como hace el cotizador al aceptar. Este paso se agregó en
la revisión: al principio la respuesta los traía, pero nadie los mostraba.

## 4. Cómo probarlo

En agencia-demo, `/agencia-viajes/venta-directa`:

1. Pestaña Tours abierta, con tarjetas «Desde S/ X p/p» y el combo en «Solo desde el cotizador».
2. 2 adultos nacionales + 1 extranjero; agrega «Tour Catarata de Ahuashiyacu» → el destino se llena
   con Tarapoto, la línea dice «2 adultos nac. × 60 + 1 adulto ext. × 60», Exonerado · Amazonía,
   S/ 180.00.
3. Elige un cliente y crea → toast «no tiene costo estimado» y la reserva con el tour en su fecha.
   En la base, `reserva_items.salida_operativa_id` apunta a la salida de ese día (probado: reserva
   VDKM-1126-0000002, salida 53).

## 5. Ejercicios

1. En `resolverTour()`, ¿por qué la fecha que se le pasa a `specDelTour()` es `inicio + día − 1` y
   no `fecha_servicio`? Arma un ejemplo con un tour el día 3 que cae en otra temporada.
2. Sigue `store()` hasta `SalidaEngancheService`: ¿en qué línea se decide si se crea una salida
   nueva o se suma a una existente? ¿Qué protege contra dos ventas del mismo tour y día creadas a la
   vez? (pista: el comentario junto a `firstOrCreate()`).
3. ¿Qué pasaría si `facturable()` no le diera `carga_tour` a las líneas de un tour de IGV mezclado?
   Prueba con un tour que tenga una entrada gravada y otra exonerada.
4. `estadoTour()` marca «Agregado» un tour ya elegido. ¿Qué habría que cambiar (pantalla y
   backend) para vender el mismo tour en dos días distintos?
