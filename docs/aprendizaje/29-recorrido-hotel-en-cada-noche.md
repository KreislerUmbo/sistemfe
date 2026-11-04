# Recorrido: el hotel en cada noche de la estadía (reporte operativo)

03-nov-2026, tarea **T-055** (H54). Ola 6 (operación diaria de Agencia).

---

## 1. El problema

Una estadía de hotel es **un solo ítem** de la reserva: `reserva_items.fecha` es el día de entrada
y la cantidad de noches vive en el ítem cotizado (`alternativa_items.cantidad`). El reporte
operativo lista ítems por fecha, así que el hotel salía **solo el día de entrada**:

| Día | Antes | Ahora |
|---|---|---|
| 10/11 (entrada) | Hotel Cumbaza · doble | igual |
| 11/11 | (nada; columna Hotel vacía) | Estadía · noche 2 de 3 |
| 12/11 | (nada) | Estadía · noche 3 de 3 |
| 13/11 | (nada) | **Salida del hotel**, 11:00 |

El guía no sabía dónde recoger al pasajero los días siguientes ni que el 13 dejaba el hotel.

**Ojo con noches y días:** 3 noches desde el 10 son 4 días (10 al 13). Un viaje de 3 días son
2 noches. El reporte usa lo que el vendedor puso en el stepper «Noches»; no lo calcula.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Http/Controllers/AgenciaViajes/ReporteOperativoController.php` | todo el cambio de backend |
| `app/Exports/ReporteOperativoDatosSheet.php` | hoja «Datos» del Excel: check-in «—» en noches |
| `admin-start-kit/src/views/agencia-viajes/reporte-operativo/index.vue` | contador x/y y casilla de check-in |
| `admin-start-kit/src/types/agencia-viajes.ts` | `es_noche_hotel`, `es_salida_hotel` |
| `tests/Feature/AgenciaViajes/ReporteOperativoHotelNochesTest.php` | 9 tests |

`AsignacionOperativa::itemsDelRango()` (la consulta compartida con el Inicio) **no** se tocó.

## 3. El recorrido

### 3.1 ¿De dónde salen las noches?

```php
private function nochesDeEstadia(ReservaItem $item): int
{
    if ($item->alternativaItem?->origen_tipo !== AlternativaItem::ORIGEN_PROVEEDOR
        || $this->hotelDelItem($item) === null) {
        return 0;
    }
    return max(0, (int) $item->alternativaItem->cantidad);
}
```

- `reserva_items` no tiene `cantidad`: se lee la del ítem cotizado.
- En hotel de catálogo y de matriz (Local), `cantidad` es el stepper «Noches».
- En **mayorista** la misma columna son **adultos** (`agregarMayorista`), por eso se exige
  `origen_tipo = 'proveedor'`.
- `hotelDelItem()` decide si es hotel: tarifa con `tipo_habitacion` o un hotel no registrado
  (`opcion_hotel_tarifa_id`). Es el mismo criterio que la columna Hotel desde T-054.

### 3.2 Filas derivadas, no servicios

`filasNochesHotel()` copia la fila del día de entrada y la reescribe para cada día
`fecha + 1 … fecha + noches`. Solo quedan las que caen dentro del rango:

```php
for ($n = 1; $n <= $noches; $n++) {
    $dia = $entrada->copy()->addDays($n);
    if ($dia->lt($desde) || $dia->gt($hasta)) continue;
    $esSalida = $n === $noches;
    // 'Estadía · noche N de M' o 'Salida del hotel' (con hora_checkout)
}
```

Esas filas llevan `origen_tipo = 'hotel_noche'`, `es_noche_hotel = true`, sin guía ni check-in.
Por eso:
- no suman en `total_items` ni en el contador «x/y check-in» (`filasReales` en `index.vue`);
- no tienen casilla de check-in en pantalla;
- en PDF y Excel su check-in sale «—» (`checkin => null`), no «No».

La hora de salida sale de `proveedor_alojamiento_detalle.hora_checkout`. Un hotel no registrado no
tiene ese dato, así que su salida va sin hora.

### 3.3 La estadía que entró antes del rango

Si pides el reporte del 11 al 12, el ítem del día 10 no entra en la consulta normal. Por eso
`itemsEstadiaPrevia()` hace una segunda consulta: ítems de hotel con entrada **hasta 30 días antes**
de «Desde», con los mismos filtros de destino, servicio, tour y hotel. De esos ítems solo se usan
sus noches y su salida, no la fila de entrada (que está fuera del rango).

```php
foreach ($items->concat($previos) as $item) {
    $esPrevio = $previos->contains($item);   // Eloquent compara por id
    ...
    if (! $esPrevio) $filas[] = $fila;
    array_push($filasNoches, ...$this->filasNochesHotel($item, $fila, $desde, $hasta));
}
```

### 3.4 La trampa del orden (encontrada en la revisión)

Las filas derivadas se agregaban **al final**. El PDF ordena los días (`ksort`), pero la pantalla
arma una tarjeta por día **en el orden en que llegan las filas**. Con un hotel desde el 10 y otro
servicio el 12, la pantalla mostraba 10, 12, 11, 13. Se arregló en el backend, una sola vez para
pantalla y Excel:

```php
private function ordenarPorFecha(array $filas): array
{
    usort($filas, fn (array $a, array $b) => strcmp($a['fecha'] ?? '', $b['fecha'] ?? ''));
    return $filas;
}
```

`usort` es estable desde PHP 8: dentro de un mismo día se respeta el orden por hora que ya traía
la consulta.

### 3.5 PDF y Excel: la columna Hotel

`armarVistaAgrupada()` agrupa por día → grupo (Tour o «Servicios sueltos · …») → pasajero.
`hotelDelDiaPorPasajero()` arma el mapa `fecha_pasajero → hotel` que llena la columna Hotel de los
otros grupos. Como ahora hay una fila de hotel en cada noche, la columna se llena todos los días.
Las filas derivadas guardan `servicio_hotel` (nombre y habitación) porque su `servicio` dice
«noche 2 de 3».

`suprimirAlojamientoDuplicado()` sigue quitando la fila de Alojamiento cuando el pasajero ya sale
en otro grupo ese día, porque la columna Hotel ya la muestra. La excepción es la **salida**: esa no
se suprime, porque trae la hora de checkout.

## 4. Lo que no se hizo

- Las noches **no se guardan** en la base: se calculan al armar el reporte.
- Una estadía de más de 30 noches que entró antes de esa ventana no aparece en el reporte.
- Si el vendedor pone días en vez de noches en el stepper, el reporte muestra una noche de más.

## 5. Tests

`ReporteOperativoHotelNochesTest` (9): CA1 (10, 11, 12 + salida el 13), CA2 (entró antes del
rango), CA3 (hora de checkout), salida sin hora, filtro por hotel, estadía ya terminada, vista
agrupada de PDF/Excel (incluye check-in «—»), PDF que se genera, orden por fecha con noches
intercaladas.

## 6. Para practicar

1. Comenta `ordenarPorFecha()` y corre `--filter=test_las_filas_salen_ordenadas`. ¿Qué fechas
   devuelve y por qué el PDF no tenía el problema?
2. Un hotel de mayorista con 2 adultos: ¿qué pasaría si se quitara el chequeo de `origen_tipo` en
   `nochesDeEstadia()`?
3. ¿Por qué `itemsEstadiaPrevia()` aplica `aplicarFiltrosDimension()`? Prueba el filtro por hotel
   con una estadía que entró el día anterior al rango.
