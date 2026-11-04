# Recorrido: avisos a los proveedores en cada cambio de una reserva

03-nov-2026, tareas **T-040** (H29, H37) y **T-082** (hallazgo de T-040). Ola 6 (operación diaria
de Agencia).

---

## 1. El problema

Una salida operativa junta las reservas que hacen el mismo tour el mismo día. Sus proveedores (el
restaurante del almuerzo, el transportista de la van) se contratan para **una cantidad y una fecha**.

Hasta T-040, solo **cancelar una reserva** decía qué cambió para cada proveedor (D15): «El Mirador
(Almuerzo): ahora son 6, no 9». Pero hay otras acciones que también mueven gente entre salidas, y
esas no avisaban nada:

| Acción | Qué le pasa a la salida |
|---|---|
| Reprogramar la reserva | pierde pasajeros en la vieja, los suma en la nueva |
| Cambiar la fecha de un servicio enganchado | igual que reprogramar, para ese servicio |
| Quitar un servicio | la salida pierde a esa reserva |
| Agregar un pasajero | cada salida suma uno |
| «Modificar reserva» | cualquiera de las anteriores, juntas |
| Cancelar la salida (tablero) | el proveedor tiene que saber que ya no va |

El proveedor podía quedar contratado para otra cantidad u otra fecha sin que nadie se enterara.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Services/AgenciaViajes/SalidaOperacionService.php` | `foto()`, `avisos()` y los helpers nuevos |
| `ReservaController::reprogramar()` / `cancelar()` | foto antes, cambio, `avisos_salidas` |
| `ReservaItemController::update()` / `destroy()` | cambiar fecha de un servicio, quitarlo |
| `ReservaPasajeroController::store()` | agregar pasajero |
| `ReservaModificacionController::aplicar()` | «Modificar reserva» |
| `SalidaOperativaController::cancelar()` | cancelar la salida → `avisos` |
| `app/Models/AgenciaViajes/SalidaOperativa.php` → `eliminarSiQuedoVacia()` | T-082: saca del vehículo a la reserva que se fue |
| `admin-start-kit/src/helpers/avisosProveedores.ts` | **nuevo**: el cuadro «Avisa a los proveedores» |
| `reservas/detalle.vue`, `salidas/index.vue`, `cotizador/editar.vue` | lo muestran |

## 3. El recorrido

### 3.1 La idea: una foto antes y otra después

No se intenta calcular «qué va a cambiar». Se saca una **foto** de cada salida afectada, se hace el
cambio y se compara con la salida tal como quedó:

```
foto antes  ──►  acción (transacción)  ──►  foto después  ──►  avisos(antes, después)
```

`foto($salidaId)` devuelve un arreglo con lo que le importa a un proveedor:

```php
[
  'tour' => 'Laguna Azul', 'fecha' => '15/11/2026', 'pax' => 5, 'minimo' => 4,
  'servicios' => [ id => ['proveedor' => 'El Mirador', 'nombre' => 'Almuerzo', 'cantidad' => 5, …] ],
  'vehiculos' => [ id => ['proveedor' => 'Turismo Selva', 'nombre' => 'Van 14', 'pasajeros' => 5, …] ],
]
```

o `null` si la salida ya no existe (se borró porque quedó vacía).

`avisos($antes, $despues)` compara las dos y redacta los textos:

```
después null                       → «… la salida del 15/11 ya no va.»
cantidad de un servicio distinta   → «El Mirador (Almuerzo): ahora son 2, no 5.»
servicio que no estaba antes       → «… se suma a la salida del 15/11, son 3. Hay que reservar.»
pasajeros de un vehículo distintos → «Van 14: ahora lleva 6 pasajero(s), no 5.» / «queda vacío.»
cae por debajo del mínimo          → «Quedan 2 pasajero(s): la salida queda por debajo del mínimo (4).»
```

Lo importante es que **la foto «después» se toma fuera de la transacción**, cuando el cambio ya
está guardado. Así se compara contra lo que de verdad quedó en la base.

### 3.2 Los helpers: no copiar el mismo bloque seis veces

Antes, `cancelar()` tenía ese mecanismo escrito a mano. T-040 lo sacó a `SalidaOperacionService`
para usarlo igual en las seis acciones:

```php
// 1. Antes del cambio
$fotosAntes = $operacion->fotosDeReserva($reserva->id);           // salidas donde está hoy

// 2. El cambio (transacción, lock de la reserva, como siempre)

// 3. En la respuesta
'avisos_salidas' => $operacion->avisosSalidas($fotosAntes, $operacion->salidasDeReserva($reserva->id)),
```

`avisosSalidas($fotosAntes, $idsDespues)` recorre **la unión** de las salidas de antes y las de
después. Para cada una:

```
¿tenía foto antes?
  sí → avisos(antes, foto ahora)       (puede salir «ya no va» si se borró)
  no → avisosSalidaNueva(foto ahora)   «salida nueva del …, son N. Hay que reservar.»
```

Al final descarta las salidas sin avisos. Si nada cambió, la respuesta trae `[]` y la pantalla no
muestra ningún cuadro. Eso cumple la regla de «ayudas sin fricción»: solo aparece cuando hay algo
que hacer.

### 3.3 La trampa: ¿salida nueva o salida que ya existía?

Supongamos que reprogramo una reserva de 3 personas al 20/11, y **ya había** una salida del 20/11
con 1 persona. Si solo tomo la foto de las salidas donde la reserva está **hoy** (la del 15), la
del 20 no tiene foto «antes». Entonces `avisosSalidas()` la trataría como nueva: «salida nueva, son
4». Es falso: el proveedor ya la tenía reservada para 1, y lo correcto es «ahora son 4, no 1».

Por eso, antes de reprogramar, se toma también la foto de las salidas **donde caerían** los servicios:

```php
$fotosAntes = $operacion->fotosDeReserva($reserva->id)
            + $operacion->fotosDondeCaeria(   // pares [tour_origen_id, fecha nueva]
                $items->map(fn ($i) => [$i->alternativaItem->tour_origen_id,
                                        $desdeNuevo->copy()->addDays($i->alternativaItem->dia_referencial - 1)])
              );
```

La fecha se calcula con **la misma fórmula** que usa `reprogramar()` para mover los servicios
(`desde + dia_referencial − 1`). Si las dos fórmulas no coincidieran, la foto saldría de otra salida.
`ReservaModificacionController::aplicar()` hace lo mismo con los ítems de la alternativa nueva,
porque `ReservaItemCreacionService::crear()` usa esa fórmula.

¿Y si la foto incluye una salida a la que al final el servicio no va? Pasa, por ejemplo, con un
ítem con fecha `manual`, que `reprogramar()` no mueve. En ese caso las fotos de antes y de después
son iguales, `avisos()` no devuelve nada y la salida se descarta. Una foto de más no hace daño; una
de menos, sí.

El `+` entre los dos arreglos une por clave (id de salida) y conserva la primera. Así una salida
que aparece en los dos lados no se fotografía dos veces.

### 3.4 Cancelar la salida (H37)

Al cancelar la salida desde el tablero, la salida **no se borra**: queda con `estado='cancelada'`.
Por eso `avisos($antes, $despues)` no detecta nada, porque los servicios y las cantidades son los
mismos. Para este caso hay un helper propio:

```php
$foto = $operacion->foto($salida->id);
'avisos' => $operacion->avisosYaNoVa($foto),   // «El Mirador (Almuerzo): la salida del 08/11 ya no va.»
```

`avisos()` lo reutiliza en el caso `después === null`, así que el texto es uno solo.

### 3.5 El vehículo (T-082)

Una van tiene asignadas reservas en la tabla `salida_vehiculo_reservas`. `cancelar()` borraba ahí
la fila de la reserva, pero reprogramar, quitar un servicio o «Modificar reserva» no lo hacían. La
reserva salía de la salida y su fila en la van quedaba. Si después volvía a esa salida, **reaparecía
sola en la van**.

En vez de agregar el borrado en cinco lugares, se buscó el punto por donde pasan todos:
`SalidaOperativa::eliminarSiQuedoVacia()`, que se llama **cada vez** que un ítem deja una salida.

```php
DB::table('salida_vehiculo_reservas')
    ->whereIn('salida_vehiculo_id', SalidaVehiculo::where('salida_operativa_id', $salidaId)->select('id'))
    ->whereNotIn('reserva_id', ReservaItem::where('salida_operativa_id', $salidaId)->select('reserva_id'))
    ->delete();
```

En palabras: de los vehículos de esta salida, saca a las reservas que ya no tienen ningún ítem en
ella. Las dos subconsultas (`->select(...)` sin `get()`) van dentro del mismo `DELETE`: es **una**
consulta a la base. Una reserva con varias líneas del mismo tour (IGV mezclado) que deja una sola
sigue en la van mientras le quede otra.

### 3.6 El frontend: un cuadro y dos bugs de SweetAlert

`helpers/avisosProveedores.ts`:

```ts
export const avisarProveedores = async (salidas?: AvisoSalida[] | null) => {
  const conAvisos = (salidas ?? []).filter((s) => s.avisos.length > 0)
  if (conAvisos.length === 0) return                     // sin cambios, sin cuadro
  await Swal.fire({ title: 'Avisa a los proveedores', icon: 'info', html: htmlAvisosProveedores(conAvisos) })
}
```

Los textos llevan nombres de proveedores y tours, que escribe un usuario. `htmlAvisosProveedores()`
escapa `& < > " '` antes de armar el HTML, y lo cubre un test de Vitest.

Lo usan la ficha de la reserva (cancelar, reprogramar, guardar o quitar un servicio), el tablero
(cancelar la salida) y la vuelta de «Modificar reserva». Este último caso es especial: la respuesta
llega en el cotizador, pero el cuadro se tiene que ver en la ficha. `cotizador/editar.vue` guarda
`avisos_salidas` en `sessionStorage` con el resto del resultado, y `detalle.vue` lo lee al montar.

**SweetAlert2 muestra un solo cuadro a la vez por instancia.** Cada `Swal.fire()` cierra el que
está abierto. De ahí salieron los dos bugs:

1. **El cuadro se cerraba solo a los ~3 s.** El proyecto importa SweetAlert de dos maneras:
   `'sweetalert2'` y `'sweetalert2/dist/sweetalert2.js'`. Para el bundler son dos módulos, o sea dos
   copias de la librería, pero las dos comparten el mismo contenedor en la página. El temporizador
   del toast de éxito (`useToast()`, 2,8 s) cerraba el cuadro. Arreglo: el helper importa la misma
   copia que `useToast()`.
2. **El cuadro tapaba el aviso de salida cancelada** (encontrado en la revisión). Con la misma copia,
   ahora era el cuadro el que cerraba el toast de T-042 («cayó en una salida cancelada») apenas se
   mostraba. Arreglo: ese aviso se muestra **después** de `await avisarProveedores(...)`.

```ts
toast.success(res.message);
await avisarProveedores(res.avisos_salidas);            // espera a que el usuario lo cierre
avisarSalidasCanceladas(res.avisos_salida_cancelada);   // recién entonces el toast
```

## 4. Lo que no se hizo

- **Reasignar proveedor u hotel no avisa.** Los proveedores de una salida salen de sus propios
  servicios (`salida_servicios`, armados desde el Incluye del tour) y de sus vehículos, no del ítem
  de la reserva. La ficha no muestra selector de proveedor en las líneas de tour, así que ese
  proveedor solo se cambia en la salida. Los hoteles no van en salidas.
- **Agregar pasajero** responde `avisos_salidas`, pero la ficha no tiene un botón que lo llame
  (se agrega por «Modificar reserva»). Está cubierto solo por test.
- Pendientes en «Por analizar» del backlog: la salida cancelada que suma pasajeros sale en el cuadro
  sin decir que está cancelada, y si hay dos avisos de salida cancelada, el segundo toast tapa al
  primero.

## 5. Tests

- `tests/Feature/AgenciaViajes/T040AvisosSalidasTest.php` (8): reprogramar a una salida nueva y a
  una que ya existía, cambiar la fecha de un servicio (y editarlo sin cambiar la fecha no avisa),
  quitar un servicio, agregar un pasajero con vehículo, cancelar la salida, reserva sin salidas.
- `ReservaModificacionAplicarTest` (2 casos `test_t040_…`): «Modificar reserva» quitando un pasajero
  y sin salidas.
- `tests/Feature/AgenciaViajes/T082VehiculoReservaQueSaleTest.php` (4): reprogramar e ir y volver,
  cambiar la fecha, quitar el servicio, y la reserva que conserva otra línea sigue en la van.
- `admin-start-kit/src/helpers/avisosProveedores.test.ts` (2): arma la lista y escapa los textos.

## 6. Para practicar

1. En `avisosSalidas()`, ¿qué pasaría si se tomara la foto «después» **dentro** de la transacción,
   antes del `commit`? Pista: ¿quién más puede estar escribiendo en esa salida?
2. Una reserva reprogramada tiene un servicio con `fecha_origen='manual'`. Sigue con lápiz y papel
   qué salidas fotografía `fotosDondeCaeria()` y por qué el resultado es igual de correcto.
3. Busca con `grep` todas las llamadas a `eliminarSiQuedoVacia(`. ¿Hay algún camino que saque un
   ítem de una salida sin pasar por ahí? Si lo hubiera, ¿qué vería el operador en la van?
