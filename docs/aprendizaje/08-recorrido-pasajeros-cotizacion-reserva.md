# Recorrido: de qué pasajero de la cotización viene cada pasajero de la reserva

F4 etapa 1, 23-oct-2026. Es la primera etapa de "Modificar reserva"; el diseño completo está en
la fila F4 de `docs/planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md`.

---

## 1. El problema, con un ejemplo

La cotización tiene a **Ana, Beto y Carla**. Al aceptarla, se crea la reserva con sus tres
pasajeros. Después:

1. Beto se baja y se quita de la reserva.
2. En la cotización se agrega un servicio **solo para Carla** (un masaje).
3. En la reserva se pulsa **"Sincronizar servicios"**.

El sistema tenía que saber qué pasajero de la reserva es Carla. Como no lo tenía guardado, lo
**adivinaba por posición**: el 1.º de la cotización con el 1.º de la reserva, el 2.º con el 2.º…

```
Cotización:  Ana   Beto   Carla
Reserva:     Ana   Carla          ← Beto ya no está, todos se corren un lugar
Por posición: Ana→Ana, Beto→Carla, Carla→(nadie)
```

Carla quedaba sin pareja, así que el masaje se creaba **sin pasajero asignado**, y en la reserva
"sin asignar" significa **"para todos"**: se le habría facturado a cualquiera.

## 2. La solución: guardar el vínculo

Una columna nueva en `reserva_pasajeros`: **`cotizacion_pasajero_id`**. Se llena al aceptar, y
desde ahí la relación se lee, no se adivina.

```
reserva_pasajeros
 id │ reserva_id │ cotizacion_pasajero_id │ nombre
────┼────────────┼────────────────────────┼────────
 10 │     5      │          101           │ Ana
 12 │     5      │          103           │ Carla     ← sabe que viene de Carla (103)
 13 │     5      │          NULL          │ Diego     ← agregado en la reserva, no viene de la cotización
```

## 3. El recorrido

```
 PANTALLA        reservas/detalle.vue → "Sincronizar servicios"
     ▼
 RUTA            POST /api/reservas/{id}/sincronizar-items  (routes/api.php)
     ▼
 CONTROLADOR     ReservaController::sincronizarItems()
                   $mapaPasajeros = $this->reconstruirMapaPasajeros($reserva);
                       → [101 => 10, 103 => 12]   (cotización → reserva)
                   separa con soloParaPasajerosQuitados() lo que es solo para
                   pasajeros que ya no están (no se crea; se avisa)
                   crearReservaItemDesdeAlternativaItem(..., $mapaPasajeros)
     ▼
 TABLAS          reserva_items (el servicio nuevo)
                 reserva_item_pasajero (a quién se le asigna)
```

## 4. Qué hace cada pieza

- **`crearReservaDesdeAlternativa()`** (al aceptar): al crear cada `ReservaPasajero` ahora guarda
  `'cotizacion_pasajero_id' => $cotizacionPasajero->id`.
- **`reconstruirMapaPasajeros()`**: antes recorría las dos listas por posición. Ahora es una sola
  consulta: `pluck('id', 'cotizacion_pasajero_id')`. `pluck` con dos argumentos arma un arreglo
  `clave => valor`, aquí `id del pasajero de la cotización => id del pasajero de la reserva`.
- **`soloParaPasajerosQuitados()`**: un servicio con `pax_incluidos` (para quién es) en el que
  **ninguno** de esos pasajeros sigue en la reserva. `every()` devuelve verdadero solo si la
  condición se cumple para todos.
- **`partition()`** (en `sincronizarItems`): divide una colección en dos: los que cumplen la
  condición y los que no. Los omitidos se nombran en el mensaje.
- **La migración** (`2026_10_23_100000_add_cotizacion_pasajero_id_to_reserva_pasajeros.php`): para
  las reservas que ya existían vincula por posición **solo si es seguro**, es decir, misma cantidad
  de pasajeros y mismos tipos en el mismo orden. Si no, deja `NULL` en vez de adivinar.

## 5. Quitar pasajero, bloqueado por ahora

Quitar un pasajero sin tocar el precio descuadra la reserva, y según los términos y condiciones
lleva una penalidad. Hasta que exista "Modificar reserva" (etapa 2):

- `ReservaPasajeroController::destroy()` responde 422 con la explicación;
- la limpieza que hacía (asignaciones, vuelos de agencia, cupo del mayorista) pasó **sin cambios**
  a `app/Services/AgenciaViajes/QuitarPasajeroReservaService.php`, para que la etapa 2 la use;
- en la pantalla, el basurero pasó a un ícono gris que explica el motivo al pasar el mouse.

## 6. Tests

- `ReservaPasajeroVinculoCotizacionTest.php` (nuevo): el vínculo se guarda al aceptar; el caso de
  Ana, Beto y Carla (el masaje queda para Carla); un servicio solo para Beto (ya quitado) no entra.
  **El segundo test se escribió antes de corregir y falló**: así se confirmó el bug.
- `ReservaQuitarItemsPasajerosTest.php`: los tests de quitar pasajero ahora prueban el servicio, y
  uno nuevo comprueba que el endpoint está bloqueado.

## 7. Ejercicios

1. En el ejemplo de la sección 1, ¿qué pasaba con un servicio **solo para Beto** antes de este
   cambio? (Pista: por posición, Beto→Carla.)
2. Lee `reconstruirMapaPasajeros()`. ¿Por qué tiene `whereNotNull('cotizacion_pasajero_id')`?
   ¿Qué pasaría con Diego sin esa línea?
3. En la migración, ¿por qué se comparan también los tipos (adulto, niño, infante) y no solo la
   cantidad? Inventa un caso con la misma cantidad donde vincular por posición estaría mal.
4. `QuitarPasajeroReservaService::quitar()` hace todo dentro de `DB::transaction` con
   `lockForUpdate()`. ¿Qué podría pasar si alguien factura a ese pasajero en el mismo segundo y no
   hubiera lock?
