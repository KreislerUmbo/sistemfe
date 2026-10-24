# Recorrido: nota de crédito parcial sobre un tour, cancelar mientras se factura y aceptar dos veces

Auditoría de reserva + facturación, punto 2 (23-oct-2026). Fila F3 de
`docs/planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md`.

---

## Parte A — Nota de crédito parcial sobre la línea de un tour

### 1. El problema, con números

Reserva de 3 adultos. Tour de S/ 150 por persona (S/ 450 en total) y un traslado de S/ 50.

| Paso | Qué pasa | Antes (con el bug) |
|---|---|---|
| Factura A | Pasajero 1: su parte del tour (150) + el traslado (50) | bien |
| NC parcial | Se acredita **solo la línea del tour** de la factura A | el traslado sigue facturado, bien |
| Factura B | Pasajeros 2 y 3 | cobra 300 y **"cierra" el tour**: cree que el pasajero 1 ya pagó |
| Factura C | Volver a cobrar la parte del pasajero 1 | **imposible**: el tour ya está cerrado. Se pierden S/ 150 |

Había dos causas:
1. La NC parcial sacaba el tour de `reserva_item_ids`, pero **dejaba su parte en `partes_tour`**:
   seguía contando como cobrada.
2. `partesDeTour()` preguntaba "¿quién está facturado?" en general. El pasajero 1 sigue
   facturado (por el traslado), así que para el sistema ya había pagado el tour también.

### 2. El recorrido

```
 SUNAT acepta la NC        NotaElectronicaController (envío de la nota)
     ▼
 MODELO                    ReservaVenta::liberarPorNotaDeCredito($saleId, 'parcial', $lineasAcreditadas)
                             sale_detail_items → qué servicios de la reserva tenía cada línea
                             quita esos servicios de reserva_item_ids
                             quita sus partes de partes_tour            ← NUEVO
     ▼
 Después, al facturar      ReservaFacturacionController::resolverSeleccion()
                             → partesDeTour(): por CADA tour, ¿quién lo pagó ya?
                               = pasajeros de las ventas que tienen ese tour   ← NUEVO
```

### 3. Qué hace cada pieza

- **`ReservaVenta::liberarPorNotaDeCredito()`**: antes este código estaba dentro del envío a
  SUNAT de la nota, así que solo se podía probar mandando una nota de verdad. Al moverlo al
  modelo, el test lo llama directo. Con `total`, borra la venta de la reserva; con `parcial`,
  quita lo acreditado.
- **`reject()`**: lo contrario de `filter()`. Se queda con los elementos que **no** cumplen la
  condición; aquí, las partes de tour que no se acreditaron.
- **`partesDeTour()`**: ahora calcula `$yaPagaron` dentro del `foreach`, **por tour**: las ventas
  que tienen ese tour (en `reserva_item_ids` o en `partes_tour`) y sus pasajeros. Antes era un
  único dato para todos los tours.
- **Quien llama a `partesDeTour()`**: antes, si los pasajeros elegidos ya estaban facturados, ni
  siquiera miraba los tours ("no hay pasajeros nuevos"). Ahora sí mira, porque puede haber una
  parte devuelta por una NC.

### 4. Test

`Fila13Fase1cFacturacionTest::test_nc_parcial_sobre_la_linea_del_tour_deja_volver_a_facturar_esa_parte`
recorre la tabla de arriba. **Se escribió antes de corregir y falló** en la factura B: "cierra" era
verdadero.

---

## Parte B — Cancelar mientras alguien factura

### 1. El problema

`cancelar()` revisaba "¿ya tiene factura?" **antes** de abrir la transacción y sin bloquear la
reserva. `store()` (facturar) sí bloqueaba la reserva, pero no volvía a revisar que siguiera
activa. Con dos personas en el mismo segundo:

```
 Vendedor 1 (cancelar)                 Vendedor 2 (facturar)
 ¿tiene factura? → no
                                       bloquea la reserva, crea la factura, libera
 cancela la reserva
 → reserva CANCELADA con una factura emitida
```

Con dos clics seguidos en "Cancelar", además, el cupo del mayorista se devolvía dos veces.

### 2. La corrección: revisar dentro del bloqueo

`lockForUpdate()` es como tomar la llave de la reserva: mientras uno la tiene, el otro espera.
Lo importante es **revisar las reglas después de tener la llave**, no antes.

- `ReservaController::cancelar()`: dentro de la transacción toma la llave
  (`Reserva::...->lockForUpdate()`) y vuelve a preguntar: ¿ya está cancelada?, ¿ya tiene factura?
- `ReservaFacturacionController::store()`: con la llave tomada, pregunta otra vez si la reserva
  sigue activa.

Las dos usan la misma llave (la fila de la reserva), así que una siempre espera a la otra.

Este caso no tiene test: haría falta que dos peticiones se intercalen en el mismo instante, y un
test corre una sola a la vez. Se sigue el mismo patrón que ya usan las otras acciones de la reserva.

---

## Parte C — Aceptar la misma cotización dos veces

Cambio hecho por el usuario y revisado el 23-oct-2026.

### 1. El problema

Una cotización puede tener varias alternativas, pero **solo una reserva activa**. `aceptar()`
revisaba "¿ya tiene reserva?" sin bloquear nada. Con dos pestañas abiertas:

```
 Pestaña 1 (acepta Alternativa 1)         Pestaña 2 (acepta Alternativa 2)
 ¿la cotización tiene reserva? → no
                                           ¿la cotización tiene reserva? → no
 crea la reserva A
                                           crea la reserva B
 → DOS reservas activas para la misma cotización
```

### 2. La corrección: la llave es la cotización

La pregunta clave es **qué fila bloquear**. Si se bloqueara la alternativa, las dos pestañas
bloquearían filas distintas (Alternativa 1 y Alternativa 2) y ninguna esperaría a la otra. Como
la regla es por **cotización**, se bloquea la fila de la cotización: las dos pestañas piden la
misma llave y una espera.

```
 ReservaController::aceptar()
   revisión rápida (sin llave): responde enseguida en el caso común
   DB::transaction:
     Cotizacion::...->lockForUpdate()        ← toma la llave de la cotización
     vuelve a leer la alternativa             ← pudo cambiar mientras esperaba
     alternativaAceptable()?          si no → HttpException 422
     cotizacionTieneReservaActiva()?  si sí → HttpException 422
     acepta, descarta las otras, crea la reserva
   catch (HttpException) → respuesta 422 con el mensaje
```

- **`alternativaAceptable()` y `cotizacionTieneReservaActiva()`**: las dos revisiones se hacen
  dos veces (antes y dentro de la llave). Por eso son funciones y los mensajes son constantes: así
  no pueden quedar distintas.
- **`HttpException` en vez de `return`**: lanzar la excepción deshace la transacción. Hoy no hay
  nada escrito antes de la revisión, pero si mañana alguien agrega una escritura, con `return`
  quedaría guardada.
- **El orden de las llaves:** al generar el código de la reserva, `CodigoGeneradorService` bloquea
  la misma fila de la cotización. Como es la misma llave y en el mismo orden, no hay riesgo de que
  dos operaciones se queden esperándose una a la otra.

### 3. Test

`ReservaAceptarConcurrencyTest`:
- **Dos conexiones reales**: una "pestaña" tiene tomada la cotización; `aceptar()` espera y
  Postgres corta la espera con el código `55P03`. Prueba que `aceptar()` sí pide la llave.
- **Dos alternativas de la misma cotización**: la segunda ya no genera otra reserva.
- Para que el bloqueo se vea entre dos conexiones, el primer test **guarda los datos de verdad**
  (`DB::commit()`), así que los borra él mismo en el `finally`, incluido el usuario de prueba.

---

## Ejercicios

1. En la tabla de la Parte A, ¿cuánto cobra ahora la factura B? ¿Y la C? ¿Suman 450?
2. Lee `liberarPorNotaDeCredito()`. Si la NC parcial acredita **el traslado** y no el tour, ¿qué
   cambia en `partes_tour`?
3. En `partesDeTour()`, ¿por qué `$yaPagaron` mira también `reserva_item_ids` y no solo
   `partes_tour`?
4. En `cancelar()`, mueve mentalmente el chequeo "¿ya tiene factura?" fuera de la transacción
   otra vez. Dibuja con dos columnas, como arriba, cómo se rompe.
5. En `aceptar()`, ¿qué pasaría si en vez de bloquear la cotización se bloqueara la alternativa?
   Dibuja las dos pestañas aceptando alternativas distintas.
6. En `ReservaAceptarConcurrencyTest`, ¿por qué el primer test necesita `DB::commit()` y el
   segundo no?
