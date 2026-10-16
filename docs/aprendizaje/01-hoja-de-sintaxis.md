# Hoja de sintaxis PHP (con ejemplos del proyecto)

Guía de consulta rápida. Cada tema tiene un ejemplo real del sistema, casi siempre de
`api-sistema-fe/app/Services/AgenciaViajes/TourLineaService.php` (el cálculo de precio de los
tours). No hace falta leerla de corrido: vuelve a ella cuando un símbolo no se entienda.

Para practicar cualquier línea: abre una terminal en `api-sistema-fe`, escribe
`php artisan tinker` y pega el código. Para salir: `exit`.

---

## 1. Variables

Todo lo que empieza con `$` es una **variable**: una caja con nombre que guarda un valor.

```php
$paxNac = 3;            // un número entero
$precio = 150.50;       // un número con decimales
$nombre = 'Laguna Azul'; // un texto (entre comillas)
$combo = null;          // "vacío", no hay nada
```

- PHP distingue mayúsculas: `$paxNac` y `$paxnac` son variables distintas.
- Si usas una variable que no existe, PHP avisa: **`Undefined variable $x`**.

## 2. El signo `=` y las comparaciones

| Símbolo | Significa | Ejemplo |
|---|---|---|
| `=` | **guarda** lo de la derecha en la variable de la izquierda | `$listas = [470];` |
| `===` | ¿es **igual**? (mismo valor y mismo tipo) | `$tipo === 'monto'` |
| `!==` | ¿es **distinto**? | `$combo !== null` |
| `>` `<` `>=` `<=` | mayor, menor, mayor o igual, menor o igual | `array_sum($costos) > 0` |
| `+=` | súmale a lo que ya tiene | `$partes[1] += 1;` |

Ojo: `=` guarda, `===` pregunta. Confundirlos es un error clásico.

## 3. Arrays: listas y fichas

Un **array** puede ser una **lista** (posiciones 0, 1, 2…) o una **ficha** (campos con nombre).

```php
$listas = [200, 400];                         // lista: posición 0 vale 200, posición 1 vale 400
$precios = ['adulto' => 150, 'nino' => 130];  // ficha: campo 'adulto' vale 150
```

Para leer un valor:

```php
$listas[0];          // 200   (las listas se cuentan desde 0)
$precios['adulto'];  // 150
```

Se pueden anidar: una ficha dentro de otra.

```php
$spec = ['precios' => ['adulto' => 150, 'nino' => 130]];
$spec['precios']['adulto'];  // 150
```

Agregar al final de una lista:

```php
$lineas = [];
$lineas[] = ['precio' => 75];  // ahora $lineas tiene 1 elemento
```

## 4. Funciones: recetas con ingredientes

Una **función** es un bloque de código con nombre que recibe datos, hace algo y devuelve un
resultado.

```php
public function calcular(array $specs, array $conteo, ?array $combo): array
{
    // ... cuentas ...
    return $resultado;
}
```

| Parte | Significa |
|---|---|
| `calcular` | el nombre de la función |
| `(array $specs, array $conteo, ?array $combo)` | los **parámetros**: los datos que recibe ("ingredientes") |
| `array` antes de `$specs` | el **tipo** del dato que espera |
| `?array` | puede ser un array **o** `null` |
| `: array` al final | el tipo de lo que **devuelve** |
| `return $resultado;` | entrega el resultado a quien llamó a la función |

**Quien llama** pone los datos, y se emparejan **por posición**, no por nombre:

```php
// TourLineaService::cargar(), línea ~117
$calculo = $this->calcular($specs, $this->conteo($alternativa->cotizacion_id), $combo);
```

Tipos que vas a ver seguido: `int` (entero), `float` (decimal), `string` (texto),
`bool` (verdadero/falso: `true`/`false`), `array`, `void` (no devuelve nada).

## 5. Alcance (*scope*)

Una variable creada **dentro** de una función solo existe ahí. Desde afuera no se puede usar.

```php
// Dentro de calcular() existe $paxNac.
// En tinker, escribir $paxNac da: Undefined variable $paxNac
```

## 6. `if`: preguntar antes de hacer

```php
if ($combo && $combo['descuento_tipo'] !== null) {
    // solo se ejecuta si las condiciones se cumplen
}
```

| Símbolo | Significa |
|---|---|
| `&&` | "y además" (deben cumplirse las dos) |
| `\|\|` | "o" (basta con que se cumpla una) |
| `!` | "no" (invierte: `! $tour->activo` = "el tour no está activo") |

Valores que cuentan como "falso": `null`, `0`, `''` (texto vacío), `[]` (array vacío), `false`.

## 7. El "if corto" (ternario) y `??`

**Ternario:** `pregunta ? si_es_sí : si_es_no`

```php
$valor = $tipo === 'monto' ? 10 * 3 : 10;
// si $tipo es 'monto', $valor = 30; si no, $valor = 10
```

**`??` (valor por defecto):** "usa lo de la izquierda; si no existe o es `null`, usa lo de la derecha".

```php
$nino = $precios['nino'] ?? $precios['adulto'];   // si no hay precio de niño, usa el de adulto
$infante = $precios['infante'] ?? 0;              // si no existe el campo, 0
```

En el proyecto se usa muchísimo para los "vacío = otro valor" (niño vacío = adulto).

**`??=`:** "si está vacío, guárdale esto".

```php
$grupos[$clave] ??= ['items' => []];   // solo lo crea si todavía no existe
```

## 8. `foreach`: repetir por cada elemento

```php
foreach ($specs as $t => $spec) {
    // $spec = el tour de turno
    // $t    = su posición (0, 1, 2…)
}
```

Si no necesitas la posición: `foreach ($specs as $spec) { ... }`.

## 9. `array_map` y `fn`: transformar una lista

`array_map(función, lista)` aplica la función a **cada** elemento y devuelve una lista nueva
del mismo tamaño.

```php
$dobles = array_map(fn ($x) => $x * 2, [1, 2, 3]);   // [2, 4, 6]
```

`fn ($x) => ...` es una **función corta sin nombre**: "recibe `$x` y devuelve lo de la derecha".

## 10. Funciones de PHP que aparecen seguido

| Función | Qué hace | Ejemplo | Resultado |
|---|---|---|---|
| `array_sum($lista)` | suma | `array_sum([200, 400])` | `600` |
| `count($lista)` | cuenta elementos | `count([1, 2, 3])` | `3` |
| `max($lista)` | el mayor | `max([2.0, 1.0])` | `2.0` |
| `round($n, 2)` | redondea a 2 decimales | `round(1666.66)` | `1667` |
| `floor($n)` | redondea **hacia abajo** | `floor(1666.66)` | `1666` |
| `array_search($v, $lista, true)` | posición del **primer** elemento igual a `$v` | `array_search(2.0, [2.0, 1.0], true)` | `0` |
| `array_column($lista, 'campo')` | saca un campo de cada ficha | `array_column($grupos, 'costo_persona')` | lista de costos |
| `(int)` `(float)` `(string)` | convierte el tipo | `(int) round(50 * 100)` | `5000` |

## 11. Clases, `->` y `::`

Una **clase** agrupa funciones y datos relacionados. `TourLineaService` es una clase; sus
funciones (`calcular`, `repartir`…) se llaman **métodos**.

| Escritura | Significa | Ejemplo |
|---|---|---|
| `$objeto->metodo()` | usar un método de un objeto ya creado | `app(TourLineaService::class)->calcular(...)` |
| `Clase::metodo()` | usar un método **`static`** (no necesita objeto) | `TourLineaService::repartir(50, [2, 1])` |
| `$this->metodo()` | "de esta misma clase" (dentro de ella) | `$this->conteo(...)` |
| `self::metodo()` | un `static` de esta misma clase | `self::repartir($neto, $listas)` |
| `app(Clase::class)` | "Laravel, dame un objeto de esta clase" | `app(TourLineaService::class)` |

`static` = no depende de nada guardado en el objeto, solo de lo que le pasas. Por eso es lo más
fácil de probar.

## 12. Modelos y tablas (Eloquent)

- La **tabla** está en la base de datos: `cotizacion_pasajeros`.
- El **modelo** es la clase PHP que la representa: `App\Models\AgenciaViajes\CotizacionPasajero`
  (carpeta `app/Models/`). La línea `protected $table = 'cotizacion_pasajeros';` los une.

| Código | SQL equivalente |
|---|---|
| `CotizacionPasajero::where('cotizacion_id', 50)->get()` | `SELECT * FROM cotizacion_pasajeros WHERE cotizacion_id = 50` |
| `PaquetePlantilla::find(12)` | `SELECT * FROM paquetes_plantilla WHERE id = 12` (uno solo) |
| `->first()` | solo el primer resultado |
| `->count()` | cuántos hay |
| `Modelo::create([...])` | `INSERT` |
| `$modelo->update([...])` | `UPDATE` |
| `$modelo->delete()` | `DELETE` |

**En tinker con datos de una agencia:** cada agencia tiene su propia base de datos. Antes de
consultar hay que decir cuál:

```php
tenancy()->initialize(App\Models\Tenant::find('agencia-demo'));
```

Después ya puedes usar los modelos. **Cuidado:** `create`, `update` y `delete` cambian datos de
verdad. Para aprender, quédate con `where`, `find`, `get`, `first` y `count`, que solo leen.

## 13. Comentarios

```php
// esto es un comentario de una línea: PHP lo ignora
/* esto es un comentario
   de varias líneas */
```

En el proyecto los comentarios explican **por qué** se hizo algo (fechas, decisiones,
hallazgos). Leerlos ayuda mucho.

---

## Ejercicios

Intenta cada uno solo, en papel primero. Después compruébalo en tinker. Las soluciones están al
final, ocultas: ábrelas solo para comparar.

### Nivel 1 (básico): leer y predecir

¿Qué valor queda en cada variable? Responde en papel.

```php
$p = ['adulto' => 150, 'nino' => 130];
$a = $p['nino'] ?? $p['adulto'];
$b = $p['infante'] ?? 0;
$tipo = 'monto';
$c = $tipo === 'monto' ? 10 * 3 : 10;
$d = count([200, 400, 600]);
$e = floor(1666.66);
$f = round(1666.66);
```

Después pega todo en tinker y escribe cada variable sola (`$a`, Enter) para ver su valor.

### Nivel 2 (intermedio): seguir `repartir()` a mano

Sin ejecutar, sigue los pasos de `repartir()` (céntimos, `floor`, el sobrante al de mayor peso)
para estos dos casos:

1. `repartir(100, [1, 1, 1])`: si los tres pesos son iguales, ¿quién se lleva el céntimo que
   sobra? (pista: `array_search` devuelve el **primero** que encuentra)
2. `repartir(10, [1, 2, 3, 4])`

Comprueba:

```php
use App\Services\AgenciaViajes\TourLineaService as T;
T::repartir(100, [1, 1, 1]);
T::repartir(10, [1, 2, 3, 4]);
```

### Nivel 3 (avanzado): escribir tu propio cálculo

Escribe en tinker, **tú mismo**, código que calcule el precio de lista de este grupo, usando un
`foreach` y un ternario:

- pasajeros: 1 adulto nacional, 1 adulto extranjero, 1 niño nacional y 1 infante;
- precios: adulto 150, niño 130, adulto extranjero 170, niño extranjero 150, infante 0.

Puedes partir de estos datos:

```php
$pax = [['tipo' => 'adulto', 'res' => 'nacional'], ['tipo' => 'adulto', 'res' => 'extranjero'], ['tipo' => 'nino', 'res' => 'nacional'], ['tipo' => 'infante', 'res' => 'nacional']];
$precios = ['adulto' => 150, 'nino' => 130, 'adulto_ext' => 170, 'nino_ext' => 150, 'infante' => 0];
```

Pista: para cada pasajero hay que armar la **clave** del precio (`'adulto'`, `'adulto_ext'`,
`'nino'`…) y sumar. Después compara tu resultado con lo que da `calcular()` para el mismo grupo
(ver la lección de `calcular()`).

---

## Soluciones

<details>
<summary>Nivel 1</summary>

| Variable | Valor | Por qué |
|---|---|---|
| `$a` | `130` | el campo `'nino'` existe, así que `??` no usa el de la derecha |
| `$b` | `0` | el campo `'infante'` no existe, entonces usa el 0 |
| `$c` | `30` | `$tipo` es `'monto'`: se toma la parte "si es sí" (10 × 3) |
| `$d` | `3` | la lista tiene 3 elementos |
| `$e` | `1666` | `floor` siempre baja |
| `$f` | `1667` | `round` redondea al más cercano |

</details>

<details>
<summary>Nivel 2</summary>

1. `repartir(100, [1, 1, 1])` → **`[33.34, 33.33, 33.33]`**.
   10000 céntimos ÷ 3 = 3333.33 → `floor` da 3333 para cada uno (suma 9999). Sobra 1 céntimo.
   Los tres pesos son iguales: `array_search(1.0, ...)` devuelve el **primero** que encuentra
   (posición 0), así que el primero se lleva el céntimo.

2. `repartir(10, [1, 2, 3, 4])` → **`[1, 2, 3, 4]`**.
   1000 céntimos; suma de pesos 10. Cada parte: 1000 × 1/10 = 100, × 2/10 = 200, × 3/10 = 300,
   × 4/10 = 400. Todo exacto, no sobra nada: 1.00, 2.00, 3.00 y 4.00.

</details>

<details>
<summary>Nivel 3</summary>

Una forma de hacerlo (hay otras igual de válidas):

```php
$total = 0;
foreach ($pax as $p) { $clave = $p['tipo'] === 'infante' ? 'infante' : $p['tipo'] . ($p['res'] === 'extranjero' ? '_ext' : ''); $total += $precios[$clave]; }
$total;
```

Resultado: **450** (150 + 170 + 130 + 0).

- El primer ternario separa al infante (su clave es solo `'infante'`).
- El punto `.` **une textos**: `'adulto' . '_ext'` da `'adulto_ext'`.
- El segundo ternario agrega `'_ext'` solo si el pasajero es extranjero.
- `$total += ...` va acumulando la suma.

`calcular()` hace lo mismo, pero en vez de recorrer pasajero por pasajero, multiplica la
**cantidad** de cada tipo por su precio: 1 × 150 + 1 × 130 + 1 × 170 + 0 × 150 = 450.

</details>
