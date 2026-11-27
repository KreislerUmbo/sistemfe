# Recorrido: asignar a mano una compra a reservas, salidas o «general»

27-nov-2026, tarea **T-152** (giro Agencia). Hasta acá una factura de compra no decía a qué
viaje correspondía. Ahora su **total** se reparte entre reservas, salidas operativas o
«general», y cada ficha (reserva y salida) muestra las compras que le tocan. Lo van a usar
T-143 (aplicar pagos a cuenta a la factura de **sus** reservas) y T-145 (repartir la NC de
comisión).

Lo que se aprende acá:
- **guardar un reparto completo** (reemplazar, no sumar filas sueltas) y validar la suma contra un total **bloqueado**;
- **un vertical que se engancha al sistema base** sin que el base dependa de él;
- **por qué una salida con compras ya no se borra**, y qué pasa al reprogramarla;
- **orden de bloqueo** para no crear deadlocks (lo que encontró la revisión);
- **respuestas viejas en el frontend**: el contador de pedidos.

Archivos:
- Migración: `database/migrations/tenant/verticals/agencia-viajes/2026_11_30_100000_create_compra_asignaciones_table.php`
- `app/Models/AgenciaViajes/CompraAsignacion.php` (`DESTINOS`, `claveDestino()`)
- `app/Services/AgenciaViajes/CompraAsignacionService.php`: `guardar()`, `validar()`,
  `exigirDestinosExistentes()`, `detalle()`, `destinos()`, `comprasDeFicha()`,
  `reservasDeCompra()` y `comprasDeReserva()` (para T-143/T-145), `bloquearComprasDeSalida()`, `trasladarSalida()`
- `app/Http/Controllers/AgenciaViajes/CompraAsignacionController.php`
- Cambios en: `CompraController::respuesta()` (reparto en el detalle), `CompraService::exigirSinReparto()`,
  `SalidaOperativa::eliminarSiQuedoVacia()`, `SalidaOperativaController::reprogramar()`,
  `ReservaController::cancelar()` (orden de las salidas), `LimpiarOperacionPrueba`
- Frontend: `src/components/Compras/AsignarCompra.vue` (modal), `src/components/AgenciaViajes/ComprasAsignadas.vue`
  (bloque de las fichas), `src/views/compras/index.vue`, `reservas/detalle.vue`, `salidas/index.vue`, `types/compras.ts`
- Tests: `tests/Feature/AgenciaViajes/CompraAsignacionTest.php` (18), `ComprasMigracionesPorGiroTest`

**Al desplegar:** `tenants:migrate-verticales`.

---

## 1. Pantalla → tabla

| Qué pasa | Ruta | Permiso | Tablas |
|---|---|---|---|
| «Asignar» desde el listado o el detalle de una compra | `PUT compras/{id}/asignaciones` | `compras.registrar` | reemplaza las filas de `compra_asignaciones` de esa compra |
| Buscar a qué repartir (reservas por código o cliente; salidas por tour o fecha) | `GET compras/destinos?tipo=&search=` | `compras.registrar` | lee `reserva`, `salidas_operativas` |
| Detalle de la compra | `GET compras/{id}` | `compras.ver` | trae `asignaciones` (solo en Agencia) |
| Bloque «Compras» de la ficha de la reserva | `GET reservas/{id}/compras` | `compras.ver` + `ReservaPolicy::view` | lee las compras vigentes con su monto para esa reserva |
| Bloque «Compras» de la ficha de la salida | `GET salidas-operativas/{id}/compras` | `compras.ver` + `reservas.ver` | ídem para la salida |

Todas van dentro de `modulo:compras`. En otro giro el controller responde **404** (`soloAgencia()`),
porque ahí la tabla ni existe.

## 2. La tabla

`compra_asignaciones`: `compra_id`, `destino` (`reserva` | `salida` | `general`), `reserva_id` y
`salida_operativa_id` (nullable, FK **sin cascada**), `monto` en la **moneda de la compra**,
`user_id` (quién ingresó la fila), timestamps. Un `CHECK` exige que el id presente sea el del
destino, y un mismo destino no se repite en una compra.

Vive en `verticals/agencia-viajes/`, no en `core/`: los otros giros no la tienen (Q1). Puede
apuntar a `compras` porque `core/` migra antes que los verticales.

**Sin filas ≠ «general».** Sin filas es «sin asignar» (nadie la repartió todavía); «general» es
una decisión: ese gasto no es de ningún viaje (Q2).

## 3. `guardar()`: el reparto se reemplaza entero

El modal manda **todas** las filas. `guardar()`:
1. valida forma (`validar()`: destino de una lista cerrada, ids `min:1`, monto > 0 con 2 decimales,
   hasta 100 filas, sin destinos repetidos);
2. **bloquea la compra** con `lockForUpdate()`: así no puede cambiar el total mientras se compara;
3. comprueba que las reservas y salidas existan (`exigirDestinosExistentes()`);
4. suma con `BigDecimal` y exige que sea **igual** al total bloqueado (ni un céntimo de más o de menos);
5. borra las filas que ya no están, crea las nuevas y actualiza solo las que cambiaron de monto.
   Una fila sin cambios **conserva a quien la ingresó** (Q12).

Lista vacía = vuelve a «sin asignar».

¿Por qué bloquear la compra y no las filas? Porque el que compite es `CompraService::editar()`:
si alguien cambia el total a la vez, uno espera al otro. Y del otro lado, `editar()` rechaza
cambiar el total o la moneda si la compra ya tiene reparto (`exigirSinReparto()`, Q5 y Q13):
primero se quita el reparto.

## 4. El vertical se engancha al base, no al revés

`CompraService` es del sistema base y no sabe de reservas. Para `exigirSinReparto()` y para el
reparto en el detalle pregunta primero el giro (`GiroActual`): en una tienda no hay tabla que
consultar. Es la misma idea que `TIPOS_CON_LINEAS` en T-154: el base ofrece el punto, el
vertical lo llena.

## 5. Salidas que ya no se borran (Q9)

Una salida operativa se borraba sola al quedarse sin líneas (`eliminarSiQuedoVacia()`). Si tiene
compras cargadas (aunque estén anuladas) **ya no se borra**: si no, la FK lo impediría o la
compra perdería su destino. Por eso puede quedar en el tablero una salida con 0 pasajeros
(hallazgo anotado).

Al **reprogramar la salida completa** (`reprogramar()`), si alguna reserva llegó de verdad a la
salida nueva, `trasladarSalida()` pasa las asignaciones a la nueva; si la compra ya tenía fila
para la nueva, suma los montos. Recién entonces la vieja puede borrarse.

## 6. Orden de bloqueo (lo que encontró la revisión)

`reprogramar()` bloquea **compras primero y después la salida**, igual que `guardar()`. Dos
transacciones que toman los mismos recursos en distinto orden pueden esperarse una a la otra
para siempre (deadlock) y Postgres corta una con error.

La primera versión de `eliminarSiQuedoVacia()` bloqueaba la salida **en cada llamada**, y
`ReservaController::cancelar()` recorría las salidas de la reserva sin orden: cancelar a la vez
dos reservas que comparten las salidas S1 y S2 podía cruzarse (una toma S2 y espera S1, la otra
al revés). Corrección:
- si la salida todavía tiene ítems, `eliminarSiQuedoVacia()` sale **sin bloquear** (no hay nada que borrar);
- si parece vacía, bloquea y **vuelve a mirar** antes de borrar;
- `cancelar()` recorre las salidas **ordenadas por id**.

## 7. Frontend

- **`AsignarCompra.vue`**: carga la compra, muestra sus filas o, si es una NC/ND sin reparto,
  **propone** los destinos de la compra que modifica, con montos vacíos (con un solo destino, el
  total de la nota) — Q7 y Q14. La propuesta es una ayuda: si no se puede leer la compra de
  origen, el modal abre igual para repartir a mano. La suma en pantalla va en céntimos y el botón
  queda bloqueado mientras no cuadre.
- **`ComprasAsignadas.vue`**: el bloque de las dos fichas. Usa un **contador de pedidos**: al pasar
  rápido de la salida 10 a la 11, si la respuesta de la 10 llega después, se descarta (sin esto,
  la ficha de la 11 mostraba las compras de la 10). Un 403 (módulo apagado o sin permiso) oculta
  el bloque en silencio.
- **`compras/index.vue`**: un modal a la vez. «Asignar» cierra el detalle y, al guardar **o al
  cancelar**, lo vuelve a abrir si se había llegado desde él.

## 8. Permisos y quién ve qué (Q10, Q12)

- Repartir: `compras.registrar`, entre **cualquier** reserva (también de otro vendedor), para ganar
  tiempo. El detalle de la compra muestra código y cliente de la reserva y quién ingresó cada fila.
- La ficha de una reserva ajena sigue cerrada: `deReserva()` llama a `authorize('view', $reserva)`.
  La seguridad de fila depende de **esa** llamada en el controller.
- Las salidas no tienen dueño: basta `reservas.ver` (como su ficha).

## 9. Para probar

```
php artisan test --filter=CompraAsignacionTest
php artisan test --filter=ComprasMigracionesPorGiroTest
```
En `agencia-demo` (con el módulo Compras encendido desde el Panel Superadmin): registrar una
factura, «Asignar» entre una reserva, una salida y «General», y verla en las dos fichas.
