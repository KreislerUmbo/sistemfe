# Recorrido: el aviso «servicios sin reflejar» y el botón Sincronizar, un solo criterio

04-nov-2026, tarea **T-084**. Reportada por el usuario con la reserva RDKM-1126-0000004
(agencia-demo).

---

## 1. El problema

La reserva mostraba un aviso amarillo que no se iba nunca:

> Hay 1 servicio(s) nuevo(s) en la cotización sin reflejar: Quechua Hotel Cusco · doble

Al apretar **Sincronizar**, la respuesta era «No hay ítems pendientes de sincronizar».

| | Qué miraba |
|---|---|
| El aviso (`respuestaDetalle()`) | todos los servicios de la alternativa que la reserva no tiene |
| El botón (`sincronizarItems()`) | lo mismo, **menos** las opciones no elegidas de un grupo y lo que es solo para pasajeros quitados |

El «Quechua Hotel Cusco» era el `alternativa_item` 502: la opción **descartada** del grupo de hoteles
de Cusco. El botón nunca la iba a crear, y el aviso la seguía mostrando como nueva.

**Lección:** cuando dos lugares responden la misma pregunta («¿qué falta en la reserva?»), si cada
uno tiene su copia de la regla, tarde o temprano se separan. Aquí la regla del botón creció (M2,
F4) y la del aviso se quedó atrás.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Http/Controllers/AgenciaViajes/ReservaController.php` | `itemsPendientesDeSincronizar()` (nueva), `sincronizarItems()`, `respuestaDetalle()` |
| `tests/Feature/AgenciaViajes/ReservaSincronizarItemsTest.php` | 3 tests nuevos |

Tablas: `alternativa_items` (lo cotizado) → `reserva_items` (lo reservado).

## 3. El recorrido

### 3.1 La función única

```php
private static function itemsPendientesDeSincronizar(Reserva $reserva, array $mapaPasajeros): array
{
    $alternativaItemIdsEnReserva = ReservaItem::alternativaItemIdsYaEnLaReserva($reserva->id, $reserva->alternativa_id);

    $candidatos = self::filtrarItemsParaReserva(
        AlternativaItem::where('alternativa_id', $reserva->alternativa_id)
            ->whereNotIn('id', $alternativaItemIdsEnReserva)
            ->get()
    );

    [$omitidos, $pendientes] = $candidatos
        ->partition(fn (AlternativaItem $item) => self::soloParaPasajerosQuitados($item, $mapaPasajeros));

    return [$pendientes->values(), $omitidos->values()];
}
```

Tres filtros, en orden:

1. **Lo que la reserva ya tiene**, incluyendo lo representado por una versión anterior (T-005): si
   un servicio facturado subió de precio, la versión nueva no es «nueva» (se facturaría dos veces).
2. **Grupos de opciones** (M2): `filtrarItemsParaReserva()` deja los servicios sin grupo y, de cada
   grupo, solo la opción elegida (`grupo_opcion_id === null || opcion_elegida`).
3. **Pasajeros quitados** (F4): un servicio con `pax_incluidos` cuyos pasajeros ya no están en la
   reserva. Si se creara, quedaría sin asignar, que en el sistema significa «para todos».

`partition()` divide una colección en dos: los que cumplen la condición y los que no. Devuelve
`[cumplen, no cumplen]`, por eso el primero se llama `$omitidos`.

`->values()` renumera las claves (0, 1, 2…). Sin eso, al pasar a JSON una colección con huecos en
las claves sale como objeto `{"1": …, "3": …}` y no como lista.

### 3.2 Quién la usa

**El botón**, dentro de la transacción y después del lock:

```php
Reserva::where('id', $reserva->id)->lockForUpdate()->firstOrFail();
[$itemsPendientes, $omitidos] = self::itemsPendientesDeSincronizar($reserva, $mapaPasajeros);
```

Los omitidos se usan para decirle al vendedor «no se agregó X porque era para pasajeros que ya no
están».

**El aviso**, sin lock (solo lee para mostrar):

```php
[$itemsPendientesSincronizar] = self::itemsPendientesDeSincronizar($reserva, $this->reconstruirMapaPasajeros($reserva));
```

`[$x] = …` toma solo el primer elemento del arreglo: al aviso no le importan los omitidos.

El mapa de pasajeros (`cotizacion_pasajero_id → reserva_pasajero.id`) se arma con el mismo
`reconstruirMapaPasajeros()` que usa el botón. Si cada uno lo armara distinto, volveríamos al
problema de §1.

## 4. Cómo probarlo

```
php artisan test --filter=ReservaSincronizarItemsTest
```

En agencia-demo: Reservas → RDKM-1126-0000004 → no aparece el aviso amarillo. En una reserva a la
que se le agregó un servicio a la cotización después de aceptar, el aviso sí sale y Sincronizar lo
crea.

## 5. Ejercicios

1. En §3.1, cambia el orden: primero `soloParaPasajerosQuitados` y después `filtrarItemsParaReserva`.
   ¿Cambia el resultado? ¿Y el mensaje de «omitidos» del botón?
2. Quita el `->values()` de `$pendientes`. ¿Qué recibe el frontend en `items_pendientes_sincronizar`
   si el primer candidato fue omitido?
3. ¿Por qué el aviso no necesita el `lockForUpdate()` y el botón sí?
4. Busca en `ReservaController` otro lugar que responda «qué servicios van a la reserva» con su
   propia copia de la regla (pista: `crearReservaDesdeAlternativa()`). ¿Comparte algo con
   `itemsPendientesDeSincronizar()`?
