# Recorrido: aviso cuando un guía o un chofer ya tiene otra salida ese día

04-nov-2026, tarea **T-044** (H38). Ola 6 (operación diaria de Agencia).

---

## 1. El problema

Se podía poner al mismo guía (en la salida o en un vehículo) o al mismo chofer en dos salidas del
mismo día sin que nadie se enterara. A veces es a propósito (tour de mañana y otro de tarde), así
que la decisión fue **avisar, no bloquear**.

| Dónde se asigna | Qué pasa ahora |
|---|---|
| Tablero de salidas, columna Guía | Guarda, y si el guía ya está en otra salida del día: toast amarillo + marca «Guía repetido ese día» en la fila |
| Vehículo de la salida (guía o teléfono del chofer) | Guarda, y toast amarillo con el aviso |

Siempre responde **200**. El aviso viaja en un campo `avisos` aparte del `message`.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Services/AgenciaViajes/CruceSalidasService.php` | toda la lógica: buscar cruces y armar el texto |
| `app/Http/Controllers/AgenciaViajes/SalidaOperativaController.php` | `update()` devuelve `avisos`; `resumenSalida()` agrega `aviso_guia_repetido` |
| `app/Http/Controllers/AgenciaViajes/SalidaVehiculoController.php` | `store()` y `update()` devuelven `avisos` |
| `admin-start-kit/src/views/agencia-viajes/salidas/index.vue` | toast + marca en la fila |
| `admin-start-kit/src/components/AgenciaViajes/SalidaServiciosVehiculos.vue` | toast al guardar un vehículo |
| `tests/Feature/AgenciaViajes/T044AvisoGuiaChoferTest.php` | 14 tests |

## 3. El recorrido

### 3.1 Un servicio porque lo usan tres

Lo llaman los dos controllers y el tablero. Si la regla «qué cuenta como cruce» viviera en cada
controller, tarde o temprano dirían cosas distintas. El servicio tiene tres preguntas públicas:

```php
avisosGuia($salida, $guiaId)            // al asignar un guía → [texto] o []
avisosChofer($salida, $telefono, $nombre) // al guardar un vehículo → [texto] o []
avisoGuiaRepetido($salida)              // para la fila del tablero → texto o null
```

### 3.2 Qué cuenta como cruce

```php
SalidaOperativa::enCurso()                     // programada o confirmada
    ->where('fecha', $salida->fecha->toDateString())
    ->where('id', '!=', $salida->id)           // nunca contra sí misma
```

- **El día, no la hora:** no hay duración confiable del tour, así que no se calcula solapamiento.
- **El chofer se compara por teléfono**, solo dígitos y sin el `51` de Perú:
  `+51 987 654 321` y `987654321` son el mismo. Sin teléfono no se compara (el nombre es texto
  libre: «Pedro», «pedro r.»… daría falsos avisos).
- **El estado se mira de los dos lados.** La otra salida tiene que estar en curso, y **la propia
  también** (`enCurso($salida)`). La primera versión solo miraba la otra: una salida cancelada
  que compartía guía con una activa mostraba «Guía repetido», y la activa no. Una salida
  cancelada ya no compite por nadie.

### 3.3 Avisar solo si cambió: `wasChanged()` y no `!==`

Si el aviso saliera en cada guardado, editar la nota de un vehículo repetiría el toast. Por eso
solo se evalúa lo que cambió. La primera versión guardaba el valor antes y comparaba:

```php
$guiaAntes = $salida->guia_id;          // 5 (int, viene de Postgres)
$salida->update($validator->validated()); // la regla 'integer' acepta "5" (texto)
$salida->guia_id !== $guiaAntes          // "5" !== 5 → true: avisa aunque no cambió
```

Ahora:

```php
$salida->update($validator->validated());
$salida->wasChanged('guia_id')           // Eloquent compara "5" y 5 como iguales
```

**Regla para recordar:** para saber si un `update()` cambió un campo, preguntarle a Eloquent
(`wasChanged()` después de guardar, `isDirty()` antes). Comparar a mano con `!==` falla en cuanto
un valor llega como texto.

El teléfono sí se compara a mano, pero **normalizado** de los dos lados: cambiar `987654321` por
`+51 987 654 321` no es un cambio.

### 3.4 La marca del tablero la calcula el servidor

`avisos` responde una pregunta chica: «¿el guía que acabas de elegir choca con algo?».
La marca de la fila responde otra más grande: «¿algún guía de esta salida (el principal o el de
cualquier vehículo) choca con algo?».

La primera versión usaba la respuesta chica para pintar la grande:

```ts
s.aviso_guia_repetido = avisos[0] ?? null;  // ✗
```

Caso: el guía de la van está repetido (la fila lo marca). Cambias el guía principal por uno libre →
`avisos` viene vacío → la marca se borra, aunque la van sigue con el guía repetido.

`update()` ya devuelve la salida entera con `aviso_guia_repetido` calculado por
`resumenSalida()`, así que se usa eso:

```ts
s.aviso_guia_repetido = res?.salida?.aviso_guia_repetido ?? null;  // ✓
```

**Regla para recordar:** si el servidor ya devuelve el dato derivado, la pantalla lo copia; no lo
reconstruye con otro dato parecido.

### 3.5 El listado: leer cada fecha una vez

Cada fila del tablero pregunta «¿mi guía está en otra salida de hoy?». La primera versión iba a
la base en cada pregunta: las salidas del día, sus tours, sus vehículos y el nombre del guía. Con
20 filas, más de 60 consultas, casi todas **iguales** (mismo día, misma lista).

La solución es recordar lo que ya se leyó, dentro de la misma instancia del servicio:

```php
private array $salidasPorFecha = [];

private function otrasDelDia(SalidaOperativa $salida): Collection
{
    $fecha = $salida->fecha->toDateString();
    $this->salidasPorFecha[$fecha] ??= SalidaOperativa::enCurso()->where('fecha', $fecha)
        ->with(['tourOrigen', 'vehiculos'])->orderBy('hora')->get();   // va a la base solo la 1.ª vez

    return $this->salidasPorFecha[$fecha]->where('id', '!=', $salida->id)->values(); // filtra en memoria
}
```

- `??=` asigna solo si la clave no existe: la segunda fila del mismo día ya no consulta.
- «Nunca contra sí misma» pasó de SQL (`where('id', '!=', …)` en la consulta) a la colección,
  porque la lista guardada sirve a todas las filas del día.
- **La instancia importa.** `app(CruceSalidasService::class)` crea una nueva cada vez, con la
  memoria vacía. Por eso el controller la guarda en una propiedad:

```php
private ?CruceSalidasService $cruce = null;

private function cruce(): CruceSalidasService
{
    return $this->cruce ??= app(CruceSalidasService::class);
}
```

- **Por qué no un singleton** (`app()->singleton(...)`): viviría más que el request (en los tests,
  entre un test y otro) y devolvería salidas viejas. Una instancia por controller vive lo que vive
  el request: ni más ni menos.

El test `test_el_listado_lee_cada_fecha_una_sola_vez` lo comprueba con `DB::enableQueryLog()`:
cuatro salidas del mismo día → una sola lectura, y las cuatro marcadas.

### 3.6 El texto con lo que haya

El aviso nombra el tour y la hora de la otra salida. La primera versión, si faltaba **uno** de los
dos, caía al texto genérico y perdía el otro. Ahora cada dato va si existe:

```php
return match (true) {
    $tour && $hora => "{$quien} ya está en la salida de {$tour} de las {$hora} de ese día.",
    (bool) $tour   => "{$quien} ya está en la salida de {$tour} de ese día.",
    (bool) $hora   => "{$quien} ya tiene otra salida a las {$hora} ese día.",
    default        => "{$quien} ya tiene otra salida ese día.",
};
```

`match (true)` toma el **primer** brazo verdadero, de arriba abajo: por eso el caso más completo va
primero. El `(bool)` hace falta porque `match` compara con `===` y `'Alto Mayo' === true` es falso.

### 3.7 Lo que queda pendiente (anotado en la tarea)

- La **otra** salida no se marca hasta recargar la lista (solo se actualiza la fila que tocaste).
- Reprogramar una salida a un día ocupado no avisa: el aviso solo sale al **asignar**.

## 4. Cómo probarlo

```
php artisan test --filter=T044
```

En agencia-demo: dos salidas del mismo día, mismo guía en las dos. Al elegirlo en la segunda sale
el toast amarillo y la fila dice «Guía repetido ese día» (pasa el mouse para ver el texto). Cancela
la primera y recarga: la marca desaparece.

## 5. Ejercicios

1. ¿Por qué `"5" !== 5` es `true` en PHP y `"5" != 5` es `false`? ¿Por qué igual conviene
   `wasChanged()` en vez de `!=`?
2. Si mañana se pide avisar también por el **nombre** del chofer cuando no hay teléfono, ¿qué
   función del servicio tocarías y qué riesgo trae?
3. En §3.4, ¿qué otra situación (además de cambiar el guía principal) habría borrado la marca con
   la primera versión?
4. En §3.6, si quitas el `(bool)` del segundo brazo, ¿qué texto sale para una salida con tour y sin
   hora? ¿Por qué?
5. En §3.5, si el controller siguiera llamando `app(CruceSalidasService::class)` en cada fila, ¿el
   test de consultas pasaría o fallaría? ¿Por qué?
