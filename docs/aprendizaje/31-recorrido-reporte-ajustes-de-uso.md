# Recorrido: búsqueda, «Check-in a todos», filtros en la URL y rango controlado (reporte operativo)

04-nov-2026, tarea **T-058** (H58, H59). Ola 6 (operación diaria de Agencia).

---

## 1. El problema

El reporte operativo se usa todo el día, y le faltaban cosas de uso básico:

| Antes | Ahora |
|---|---|
| Solo «Hoy» y «Esta semana» | también **«Mañana»** |
| Para encontrar a un pasajero había que recorrer la tabla | **Buscar** por nombre, documento o código de reserva |
| Check-in uno por uno | **«Check-in a todos»** por servicio y día |
| Ir a una reserva y volver reiniciaba los filtros | Los filtros viven en la **URL** |
| Contador «x/y check-in» por fila (un pasajero con 3 servicios contaba 3) | **«x/y pasajeros con check-in»**, por pasajero distinto |
| Fecha inválida → 500; «Hasta» antes de «Desde» → vacío sin aviso; rango largo → trae todo | **422 con mensaje**; máximo 31 días |

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Http/Controllers/AgenciaViajes/ReporteOperativoController.php` | rango validado, `q`, `filtrosDisponibles()` |
| `app/Http/Controllers/AgenciaViajes/ReservaItemPasajeroController.php` | `checkinMasivo()` |
| `routes/api.php` | `POST reporte-operativo/checkin-masivo` (permiso `reservas.editar`) |
| `admin-start-kit/src/views/agencia-viajes/reporte-operativo/index.vue` | botones, búsqueda, URL, contador |
| `admin-start-kit/src/services/admin/reporteOperativoService.ts` | `q` y `checkinMasivo()` |
| `tests/Feature/AgenciaViajes/ReporteOperativoAjustesUsoTest.php` | CA1, CA2, CA3 y casos borde |
| `tests/Feature/AgenciaViajes/ReporteOperativoHotelNochesTest.php` | la búsqueda sigue al pasajero en sus noches |

## 3. El recorrido

### 3.1 El rango, validado en un solo lugar

Los cuatro endpoints del reporte (`index`, `pdf-url`, `export`, `filtros`) arman su rango con
`resolverRangoFechas()`. Basta validar ahí para que los cuatro respondan igual:

```php
if ($fechaHasta->lt($fechaDesde)) {
    throw ValidationException::withMessages(['fecha_hasta' => 'La fecha "Hasta" no puede ser anterior a "Desde".']);
}
if ($fechaDesde->diffInDays($fechaHasta, true) > self::MAX_DIAS_RANGO - 1) {
    throw ValidationException::withMessages(['fecha_hasta' => 'El rango no puede superar los 31 días.']);
}
```

- `ValidationException` la convierte Laravel en un **422** con `message`: la pantalla solo
  muestra ese texto.
- **31 días incluidos**: del 01 al 31 son 31 días, pero `diffInDays` da 30. Por eso el tope es
  `MAX_DIAS_RANGO - 1`.
- `diffInDays(..., true)`: en Carbon 3 hay que pasar `absolute: true`, si no puede dar negativo.

En la pantalla, `CampoFecha` de «Hasta» recibe `:min` (= Desde) y `:max` (= Desde + 30), y un
`watch` sobre «Desde» corrige «Hasta» si queda fuera. El servidor valida igual: la URL se puede
escribir a mano.

**Excel:** se pide como `Blob`, así que el 422 también llega como Blob y `e.response.data.message`
es `undefined`. `mensajeDeError()` lo lee (`await data.text()` + `JSON.parse`) para mostrar el
mensaje real.

### 3.2 La búsqueda `q`: se filtra por pasajero, no por fila

Lo natural sería filtrar las filas ya armadas. El problema son las **noches de hotel** (T-055):
no son ítems, se derivan de la fila de entrada con `filasNochesHotel()`. Si la búsqueda filtrara
al final, habría que decidir aparte qué noches se quedan.

Por eso el filtro va **dentro del bucle de pasajeros**, antes de `armarFila()`:

```php
foreach ($pasajeros as $pasajero) {
    if ($q !== null && ! $coincideReserva
        && ! $this->contieneTexto($pasajero->nombre, $q)
        && ! $this->contieneTexto($pasajero->documento, $q)) {
        continue;   // ni su fila ni sus noches
    }
    ...
}
```

Si el pasajero no coincide, no se arma su fila y tampoco sus noches. Si coincide el **código de
reserva**, salen todos sus pasajeros.

`contieneTexto()` compara con `Str::lower(Str::ascii(...))` de los dos lados: «Peña» se
encuentra con «pena» y «PEÑA».

La pantalla busca mientras se escribe, con una pausa de 350 ms (`buscarConPausa`) para no hacer
una petición por tecla.

### 3.3 «Check-in a todos»

**Dónde va el botón.** La pantalla agrupa por día y, dentro, por pasajero; no había un «grupo
de servicio». El botón va en la cabecera de cada día, **uno por servicio**. El `computed`
`filasPorFecha` arma esa lista (`grupo.servicios`) mientras recorre las filas:

- clave `tour-{id}` si la fila es de un tour; si no, `servicio-{nombre}`;
- `pendientes` = filas de ese servicio **sin** check-in;
- las noches y la salida de hotel no entran (no tienen check-in).

Si no quedan pendientes, en lugar del botón sale «{servicio}: todos con check-in».

**Qué se manda.** No el id del servicio, sino los pares (ítem, pasajero) que **se ven** en la
pantalla. Así respeta la búsqueda y los filtros activos: si buscaste «aldana», marca solo a
Aldana, no a todo el tour.

```ts
reporteOperativoService.checkinMasivo(
    filasAMarcar.map((f) => ({ reserva_item_id: f.reserva_item_id, reserva_pasajero_id: f.pasajero.id }))
)
```

**Qué hace el servidor** (`ReservaItemPasajeroController::checkinMasivo()`):

1. Valida la lista (`required|array|min:1|max:500`).
2. Carga los ítems con su reserva y pasajeros en **una** consulta.
3. **Autoriza cada reserva distinta** con `ReservaPolicy::update`. Si una se niega, `authorize()`
   corta con 403 **antes** de marcar nada: todo o nada.
4. Dentro de una transacción, por cada par aplica **las mismas reglas que el check-in
   individual** (`checkin()`): reserva activa, el pasajero es de esa reserva, y viaja en ese
   servicio (el asignado, o cualquiera si el servicio es «para todos»). El par que no cumple se
   **omite** y se cuenta en `omitidos`.
5. `ReservaItemCheckin::createOrFirst(...)`: si ya tenía check-in, se conserva con su hora.

Si hubo omitidos, la pantalla avisa y recarga el reporte para mostrar lo real. Si no, marca las
filas en el cliente sin recargar.

> **Ojo con `createOrFirst` dentro de una transacción en Postgres.** En Postgres, un error dentro
> de una transacción (aquí, el índice único) la deja «abortada»: toda consulta siguiente falla.
> `createOrFirst` lo resuelve con un *savepoint* (`withSavepointIfNeeded`): solo se deshace ese
> insert y la transacción sigue.

### 3.4 El contador por pasajero

```ts
const totalPasajeros = computed(() => new Set(filasReales.value.map((f) => f.pasajero.id)).size);
const pasajerosConCheckin = computed(() =>
    new Set(filasReales.value.filter((f) => f.checkin_realizado).map((f) => f.pasajero.id)).size);
```

Un `Set` se queda con los valores distintos. Un pasajero cuenta como «con check-in» si tiene
**al menos uno** de sus servicios del rango marcado. El `id` de `reserva_pasajeros` es único en
toda la tabla, así que dos personas de reservas distintas no se confunden.

### 3.5 Los filtros en la URL

Antes los filtros vivían solo en un `ref`: al ir a la reserva el componente se destruía, y al
volver nacía con los valores por defecto. Ahora:

- **Al cargar:** `filtros` toma sus valores iniciales de `route.query` (`fechaQuery`, `idQuery`,
  `textoQuery`, cada uno validando el formato).
- **Al buscar:** `cargar()` llama a `recordarFiltrosEnUrl()`, que hace `router.replace({ query })`.

`replace` y no `push`: cambia la URL **sin agregar una entrada al historial**. Con `push`, el botón
«Atrás» del navegador pasaría por cada búsqueda en vez de volver a la pantalla anterior.

### 3.6 `filtrosDisponibles()` sin cargar los ítems

Antes cargaba todos los ítems del rango con una docena de relaciones, solo para sacar la lista
de destinos, servicios, tours y hoteles. Ahora son consultas de ids con subconsultas:

```php
$tours = PaquetePlantilla::whereIn('id', $base()->whereNotNull('tour_origen_id')->select('tour_origen_id'))
    ->orderBy('nombre')->get(['id', 'nombre', 'codigo']);
```

`whereIn('id', <query con select>)` genera un `WHERE id IN (SELECT ...)`: Postgres lo resuelve
en una sola consulta y PHP no carga ningún ítem. El criterio no cambia: primero la tarifa real
del ítem y, si se le quitó el proveedor, la cotizada.

## 4. Cómo probarlo

```
php artisan test --filter=ReporteOperativo
```

En agencia-demo, Reporte operativo:

- «Mañana» → Desde y Hasta pasan al día siguiente.
- Buscar «aldana» con el rango 04 al 10-nov → solo sus filas; entrar a la reserva y volver
  conserva fechas, búsqueda y resultado.
- «Check-in a todos» en un servicio → los pendientes quedan marcados y el contador sube;
  recargar la página lo confirma.
- Cambiar a mano la URL a un rango de 40 días → aviso «El rango no puede superar los 31 días.»

## 5. Ejercicios

1. ¿Por qué el botón manda pares (ítem, pasajero) y no el id del servicio? ¿Qué pasaría con la
   búsqueda activa si mandara el id?
2. Cambia `router.replace` por `router.push`, busca tres veces y pulsa «Atrás» en el navegador.
   ¿Qué ves?
3. Un pasajero sin nombre se muestra como «Pasajero 35». ¿Lo encuentra la búsqueda por
   «pasajero 35»? ¿Por qué? (Pista: ¿contra qué campo compara `contieneTexto()`?)
