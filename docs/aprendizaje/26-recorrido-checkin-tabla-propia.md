# Recorrido: el check-in en su propia tabla

03-nov-2026, tarea **T-041** (H30). Abre la ola 6 (operación diaria de Agencia).

---

## 1. El problema

En el reporte operativo se marca qué pasajero ya hizo check-in en cada servicio. Ese dato se
guardaba en `reserva_item_pasajero`, que es **la asignación**: qué pasajeros van en qué servicio.
Esa tabla tiene una regla implícita:

```
servicio sin filas en reserva_item_pasajero  → va para TODOS los pasajeros
servicio con filas                           → va SOLO para esos
```

Marcar el check-in de un pasajero en un servicio «para todos» creaba su fila, y entonces el servicio
pasaba a ser «solo para él». Para evitarlo, `checkin()` creaba la fila de **todos** los pasajeros.
Así el reporte quedaba bien, pero la Facturación especial (por grupos) veía un servicio con
asignación, sin ninguna factura que lo cubriera, y lo mostraba como «pendiente: faltan pasajeros».

Es el mismo error que ya tuvo el vuelo de la agencia (ver recorrido de reservas, 27-ago): **una
tabla puente no se reutiliza para un dato que no tiene que ver con ella**.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `migrations/tenant/verticals/agencia-viajes/2026_11_03_200000_create_reserva_item_checkins_table.php` | **nueva**: la tabla |
| `…/2026_11_03_200100_copiar_checkins_a_reserva_item_checkins.php` | **nueva**: copia los check-ins viejos |
| `app/Models/AgenciaViajes/ReservaItemCheckin.php` | **nuevo**: el modelo |
| `ReservaItemPasajeroController::checkin()` | marca y desmarca en la tabla nueva |
| `ReporteOperativoController::obtenerFilas()` | lee la tabla nueva (pantalla, Excel y PDF) |
| `LimpiarOperacionPrueba` | conoce la tabla nueva |

## 3. El recorrido

### 3.1 La tabla

```
reserva_item_checkins
  reserva_item_id      → reserva_items      (ON DELETE CASCADE)
  reserva_pasajero_id  → reserva_pasajeros  (ON DELETE CASCADE)
  checkin_hora         instante en UTC
  UNIQUE (reserva_item_id, reserva_pasajero_id)
```

**Una fila = ese pasajero hizo check-in en ese servicio.** No hay columna `checkin_realizado`: que
la fila exista ya dice «sí». Desmarcar es borrar la fila.

Las dos FK con cascada hacen que al quitar un servicio o un pasajero sus check-ins se borren solos,
sin código extra en `ReservaItemQuitarService` ni en `QuitarPasajeroReservaService`.

### 3.2 Marcar y desmarcar

```
POST reserva-items/{id}/pasajeros/{pasajeroId}/checkin   { checkin_realizado: true|false }

checkin()
  ├─ authorize('update', reserva)                ← ReservaPolicy, como toda acción de reserva
  ├─ reserva activa, si no → 422
  ├─ el pasajero es de esa reserva, si no → 422
  ├─ ¿viaja en el servicio?
  │     servicio sin asignación → sí, todos viajan
  │     servicio con asignación → solo los asignados; otro → 422
  └─ true  → ReservaItemCheckin::createOrFirst(...)
     false → ReservaItemCheckin::where(...)->delete()
```

El paso «¿viaja en el servicio?» usa el mismo criterio que
`ReporteOperativoController::resolverPasajerosDelItem()`: el reporte solo deja marcar a quien
muestra.

**`createOrFirst` y el doble clic.** Primero intenta el `INSERT`. Si dos clics llegan juntos, el
segundo choca con el índice único; Laravel atrapa ese error y devuelve la fila que ya estaba. En
Postgres un error dentro de una transacción la invalida entera, por eso Laravel 12 hace el intento
dentro de un *savepoint*. Con `firstOrCreate` (buscar y después crear) los dos clics podían no
encontrar nada e intentar crear los dos: el segundo daba 500.

### 3.3 El reporte

```php
$checkins = ReservaItemCheckin::whereIn('reserva_item_id', $items->pluck('id'))
    ->get()
    ->keyBy(fn ($r) => $r->reserva_item_id . '_' . $r->reserva_pasajero_id);
```

Una sola consulta para todos los servicios del rango (sin N+1). En cada fila del reporte,
`'checkin_realizado' => $checkin !== null`. El Excel y el PDF arman sus filas a partir de estas, así
que no hubo que tocarlos.

### 3.4 Los datos viejos

La migración de copia hace un solo `INSERT … SELECT` desde `reserva_item_pasajero` donde
`checkin_realizado = true`, con `ON CONFLICT DO NOTHING`. Si se corre dos veces no duplica nada.

**Las asignaciones «para todos» que creó el check-in viejo no se tocan** (decisión H30, opción a):
esos servicios siguen «pendientes» al facturar por grupos hasta que alguien quite la asignación a
mano en la pestaña Asignación. Las columnas viejas (`checkin_realizado`, `checkin_hora`) quedan sin
uso y se quitarán con otra migración cuando esto esté en producción.

## 4. Lo que quedó abierto

- Si se quita a un pasajero de un servicio, su check-in queda guardado: no se ve en el reporte y
  reaparece si se lo vuelve a asignar. Limpiarlo no es simple, porque pasar de «para todos» a
  «solo algunos» deja afuera a los demás sin borrar ninguna fila. Anotado en «Por analizar».

## 5. Tests

`tests/Feature/AgenciaViajes/ReporteOperativoCheckinTest.php` (12 casos): el servicio «para todos»
sigue sin asignación y la facturación lo ofrece para elegir a mano; desmarcar no toca la
asignación; un pasajero no asignado da 422; doble marcado; la copia de datos (corrida dos veces);
quitar el servicio y quitar al pasajero.
