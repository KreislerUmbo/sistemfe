# Recorrido: el cliente que cotiza nace como primer pasajero

04-nov-2026, tarea **T-017** (Obs. 4). Ola 6 (operación diaria de Agencia).

---

## 1. El problema

Al aceptar una cotización, los pasajeros de la reserva nacían vacíos. Pero si el cliente que
cotiza es una persona, casi siempre viaja: el vendedor tenía que volver a escribir su nombre y su
DNI en la pestaña Pasajeros.

| Antes | Ahora |
|---|---|
| Aceptar → Pasajeros: «Adulto 1» vacío | Aceptar → Pasajeros: «Adulto 1» = KREISLER UMBO RUIZ, DNI 44359286 |
| El catálogo de pasajeros no sabía de qué cliente era cada ficha | La ficha del cliente queda con `cliente_id` (T-018 la va a usar) |

El dato sigue siendo **editable**: si el cliente no viaja, el vendedor escribe a otra persona.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Services/AgenciaViajes/PasajeroCatalogoService.php` | nuevo: ¿el cliente califica? y su ficha del catálogo |
| `app/Http/Controllers/AgenciaViajes/ReservaController.php` | `aceptar()` y `crearReservaDesdeAlternativa()` |
| `app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php` | `sincronizarCatalogo()`: no pisar la ficha de otra persona |
| `tests/Feature/AgenciaViajes/T017ClientePrimerPasajeroTest.php` | 14 tests |

Tablas: `clients` → `pasajeros_catalogo` (+ `pasajero_documentos`) → `reserva_pasajeros`.

## 3. El recorrido

### 3.1 ¿El cliente es una persona que viaja?

```php
private const TIPOS_PERSONA = ['DNI', 'CE', 'PAS'];

public function datosDePasajero(Client $cliente): ?array
{
    $tipo = strtoupper(trim((string) $cliente->type_document));
    $documento = trim((string) $cliente->n_document);

    if (! in_array($tipo, self::TIPOS_PERSONA, true) || $documento === '' || $documento === '00000000') {
        return null;
    }
    // nombre: full_name, si no name + surname; si sigue vacío → null
}
```

- Se mira **`type_document`**, no `type_client` (las empresas con RUC también traen `1`) ni
  `cod_tipo_doc_sunat` (viene `null` en algunas filas). Se verificó en agencia-demo antes de
  construir.
- `SND` (sin documento) y `TM` (tarjeta militar) quedan fuera aunque traigan número.
- `00000000` es el «cliente varios» de Ventas: no es una persona real.

Devolver `null` cuando no califica permite que quien llama haga `if ($datos)` sin saber las reglas.

### 3.2 La ficha: buscar antes de crear

`desdeCliente()` busca en este orden:

1. Una ficha con `cliente_id` = este cliente → esa.
2. Una ficha con el mismo número en `pasajero_documentos` → esa, y si no tenía `cliente_id` se lo
   completa (si ya tenía **otro** cliente, no se le cambia).
3. Si no hay ninguna, la crea con su documento (`DNI`, `CE` o `PAS`).

En la prueba en agencia-demo pasó el caso 2: tu DNI ya estaba en la ficha 9 (de una reserva
anterior, sin cliente), y se reusó en vez de duplicarla.

**El lock.** Dos vendedores aceptan a la vez dos cotizaciones del **mismo** cliente: los dos
buscan, no encuentran ficha y los dos la crean → dos fichas. Por eso, antes de buscar:

```php
Client::where('id', $cliente->id)->lockForUpdate()->first();
```

La segunda aceptación espera en esa línea hasta que la primera termina su transacción; cuando
pasa, ya encuentra la ficha por `cliente_id`. El resultado no se usa: la línea está solo para hacer
esperar. Por eso `desdeCliente()` tiene que llamarse **dentro** de una transacción (`aceptar()` ya
abre una).

### 3.3 Pasar una función en vez del dato

`crearReservaDesdeAlternativa()` la usan `aceptar()` **y** Venta Directa. El cliente solo se carga
al aceptar, así que se agregó un parámetro opcional:

```php
public function crearReservaDesdeAlternativa(Alternativa $alternativa, array $pasajeroCatalogoIds = [], ?\Closure $pasajeroPorDefecto = null): array
```

Venta Directa no lo pasa → `null` → todo igual que antes.

¿Por qué una **función** (`Closure`) y no el arreglo `['nombre' => …]` ya armado? Porque armarlo
**crea la ficha**. Si la cotización solo tiene niños, no hay dónde poner al cliente y quedaría una
ficha sin usar. Con la función, la creación primero busca el lugar y solo si lo encuentra la llama:

```php
foreach ($cotizacionPasajeros as $index => $candidato) {
    if ($candidato->tipo_pax === 'adulto' && empty($pasajeroCatalogoIds[$index])) {
        $indiceDelCliente = $index;   // primer adulto que el vendedor no eligió
        break;
    }
}
if ($indiceDelCliente !== null) {
    $datosPorDefecto = $pasajeroPorDefecto();   // recién acá se crea/busca la ficha
}
```

**Regla para recordar:** si calcular un dato tiene efectos (escribe en la base), pásalo como función
y que lo llame quien sabe si de verdad lo necesita.

### 3.4 No repetir a la misma persona

Al aceptar, el vendedor puede elegir pasajeros del catálogo para cada lugar
(`pasajero_catalogo_ids`, alineado por orden con los pasajeros de la cotización). Si ya eligió al
propio cliente para el 2.º lugar, ponerlo también en el 1.º lo duplicaría:

```php
if ($datosPorDefecto && in_array($datosPorDefecto['pasajero_catalogo_id'],
        array_map('intval', array_filter($pasajeroCatalogoIds)), true)) {
    $datosPorDefecto = null;
}
```

- `array_filter` quita los lugares vacíos (`null`).
- `array_map('intval', …)` porque del request los ids pueden llegar como texto (`"9"`), y el
  `true` final de `in_array` compara con `===`: `"9" === 9` es falso.

### 3.5 El riesgo que T-017 volvía común: pisar la ficha del cliente

`sincronizarCatalogo()` corre cada vez que se guarda un pasajero con nombre y documento. Busca la
ficha por `pasajero_catalogo_id` y le copia los datos del pasajero.

El problema: en la pantalla, al escribir a otra persona (a mano o con RENIEC) cambian `nombre` y
`documento`, pero **el `pasajero_catalogo_id` anterior se queda**. Antes casi no pasaba porque los
pasajeros nacían vacíos. Con T-017 el 1.º nace con la ficha del cliente, y si el cliente no viaja:

```
ficha 9 (cliente_id = 1): KREISLER, DNI 44359286
el vendedor escribe: MARÍA, DNI 70000001 → guarda
sincronizarCatalogo(): ficha 9 = MARÍA, DNI 70000001, cliente_id = 1   ✗
```

Y después Facturar sugeriría al cliente equivocado. La corrección:

```php
if ($catalogo) {
    $documentoDeLaFicha = $catalogo->documentos()->orderBy('id')->value('numero_documento');
    if ($documentoDeLaFicha !== null && $documentoDeLaFicha !== $pasajero->documento) {
        $catalogo = null;   // es otra persona: esta ficha no se toca
    }
}
// sigue el código de siempre: buscar por documento o crear una ficha nueva
```

- **Mismo documento, otro nombre** → es la misma persona con el nombre corregido → se actualiza.
- **Otro documento** → otra persona → otra ficha.

Efecto: corregir un DNI mal escrito ya no arregla la ficha vieja, apunta a una nueva. La vieja
queda en el catálogo. Es lo que pidió la tarea (mejor una ficha de más que una ficha pisada).

## 4. Cómo probarlo

```
php artisan test --filter=T017
```

En agencia-demo: cotización de un cliente con DNI y al menos un adulto → Aceptar → reserva →
Pasajeros: el primer adulto ya tiene nombre y DNI. Con un cliente con RUC no se carga nada.

Para una cotización cuya reserva se anuló: «Duplicar alternativa» y aceptar la copia (aceptar
descarta las demás, incluida la vieja, y la cotización vuelve a verse «reservada»).

## 5. Ejercicios

1. Si `datosDePasajero()` usara `type_client === 1` para decidir «es persona», ¿qué cliente de
   agencia-demo entraría como pasajero sin serlo?
2. En §3.2, quita el `lockForUpdate()`. Describe paso a paso cómo dos aceptaciones simultáneas
   terminan con dos fichas del mismo cliente.
3. En §3.3, si se pasara el arreglo ya resuelto en vez de la función, ¿qué test fallaría o qué
   basura quedaría en la base? (pista: CA5).
4. En §3.4, quita `array_map('intval', …)` y manda `pasajero_catalogo_ids: ["9", null]`. ¿Se
   repite el cliente? ¿Por qué?
5. En §3.5, el vendedor corrige solo una letra del nombre del cliente. ¿Qué ficha se actualiza?
   ¿Y si corrige un dígito del DNI?
