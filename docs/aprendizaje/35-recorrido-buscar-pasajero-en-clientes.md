# Recorrido: «Buscar pasajero» también busca en Clientes

04-nov-2026, tarea **T-018** (Obs. 5). Ola 6 (operación diaria de Agencia). Sigue al recorrido 34
(T-017), del que reusa `PasajeroCatalogoService`.

---

## 1. El problema

En la reserva, pestaña Pasajeros, «Buscar pasajero» solo buscaba en `pasajeros_catalogo`: personas
que ya viajaron en alguna reserva. Los clientes que cotizaron pero nunca viajaron (la mayoría de
`clients`) no aparecían, y el vendedor tenía que escribirlos de nuevo.

| Antes | Ahora |
|---|---|
| Solo fichas del catálogo | Fichas y después clientes persona, con badge «Pasajero» / «Cliente» |
| Un cliente sin ficha no aparece | Aparece; al **guardar** el pasajero se crea su ficha con `cliente_id` |

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php` | `buscarCatalogo()` (busca en las dos tablas) y `update()` (acepta `cliente_id`) |
| `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue` | lista de resultados, `autocompletarDesdeCatalogo()`, `guardarPax()` |
| `admin-start-kit/src/types/agencia-viajes.ts` | `PasajeroCatalogo.id` puede ser `null`; `origen`; `ReservaPasajero.cliente_id` |
| `tests/Feature/AgenciaViajes/T018BuscarPasajeroClientesTest.php` | 8 tests |

Tablas: `clients` → `pasajeros_catalogo` (+ `pasajero_documentos`) → `reserva_pasajeros`.

## 3. El recorrido

### 3.1 La búsqueda: primero fichas, después clientes

`GET pasajeros-catalogo?search=` responde la misma clave de siempre (`pasajeros_catalogo`), pero
cada elemento trae `origen`:

```php
$limite = 15;
$pasajeros = PasajeroCatalogo::with('documentos')->where(...)->limit($limite)->get()
    ->map(fn ($p) => [...$p->toArray(), 'origen' => 'pasajero']);   // (simplificado)

$faltan = $limite - $pasajeros->count();
if ($faltan > 0) {
    $clientes = Client::query()->...->limit($faltan)->get()->map(...);   // origen 'cliente'
}
return response()->json(['pasajeros_catalogo' => $pasajeros->concat($clientes)->values()]);
```

- **15 en total**, no 15 + 15: los clientes llenan lo que sobra.
- Mantener la clave y la forma de cada elemento hace que el frontend casi no cambie: lee
  `c.nombre` y `c.documentos[0].numero_documento` igual que antes.
- Un cliente sale con `id: null` (todavía no tiene ficha) y `cliente_id`.

### 3.2 ¿Qué cliente califica? El mismo criterio de T-017, en SQL

`datosDePasajero()` (recorrido 34, §3.1) decide en PHP si un cliente es una persona que viaja. Para
filtrar en la base hay que escribir **la misma** regla en SQL, no inventar otra:

```php
->whereIn(DB::raw("upper(trim(coalesce(type_document, '')))"), ['DNI', 'CE', 'PAS'])
->whereRaw("trim(coalesce(n_document, '')) not in ('', '00000000')")
->whereRaw("(trim(coalesce(full_name, '')) <> '' or trim(coalesce(name, '') || ' ' || coalesce(surname, '')) <> '')")
```

Cada parte replica una línea del PHP: `strtoupper(trim(...))`, «número no vacío ni `00000000`»,
«nombre de `full_name` o de `name` + `surname`». `coalesce(x, '')` hace en SQL lo que
`(string) $x` hace en PHP con un `null`.

Después, para armar cada resultado, se llama al propio `datosDePasajero()`. Como el SQL ya filtró
igual, nunca devuelve `null` acá. **Si un día cambia la regla en PHP, hay que cambiar también este
SQL**: son dos copias de lo mismo.

`Client` usa `SoftDeletes`, así que `Client::query()` ya deja fuera a los clientes borrados.

### 3.3 No mostrar dos veces a la misma persona

Si el cliente ya tiene ficha, debe salir **una** vez, como pasajero. Hay dos formas de «ya tener
ficha»:

```php
->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pasajeros_catalogo')
    ->whereColumn('pasajeros_catalogo.cliente_id', 'clients.id'))          // vinculada (T-017)
->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pasajero_documentos')
    ->whereRaw('pasajero_documentos.numero_documento = trim(clients.n_document)'))  // mismo DNI
```

La segunda existe por un caso real: la ficha 9 de agencia-demo tenía el DNI del cliente pero
**sin** `cliente_id` (nació de una reserva anterior a T-017).

`whereNotExists(subconsulta)` = «quédate con los clientes para los que esta subconsulta no
encuentra ninguna fila». El `select 1` es convención: no importa qué columna, solo si hay fila.

### 3.4 Elegir no guarda nada; guardar vincula

Crear la ficha en la búsqueda sería un error: la búsqueda es un `GET` que corre en cada tecla (con
debounce), y cada búsqueda crearía fichas. Por eso, al elegir un cliente, la pantalla solo llena el
formulario y recuerda el `cliente_id`:

```ts
p.pasajero_catalogo_id = c.id;                                   // null si es cliente
p.cliente_id = c.origen === 'cliente' ? c.cliente_id ?? null : null;
```

`reserva_pasajeros` no tiene columna `cliente_id`: el dato vive solo en la pantalla hasta Guardar.
`guardarPax()` lo manda y, al volver, lo limpia (`p.cliente_id = null`).

En el backend, `update()`:

```php
$clienteId = $datos['cliente_id'] ?? null;
unset($datos['cliente_id']);                 // no es columna: no va al update()

DB::transaction(function () use ($pasajero, $datos, $clienteId) {
    $pasajero->update($datos);
    if ($clienteId && $pasajero->documento) {
        $cliente = Client::find($clienteId);
        if ($cliente && trim((string) $cliente->n_document) === trim((string) $pasajero->documento)) {
            $ficha = app(PasajeroCatalogoService::class)->desdeCliente($cliente);
            if ($ficha) {
                $pasajero->update(['pasajero_catalogo_id' => $ficha->id]);
            }
        }
    }
    if ($pasajero->nombre && $pasajero->documento) {
        $this->sincronizarCatalogo($pasajero);
    }
});
```

- **El documento tiene que coincidir.** Si el vendedor eligió al cliente y después escribió a otra
  persona, el `cliente_id` se ignora: no se vincula a una ficha que no es de quien viaja.
- **La transacción.** `desdeCliente()` bloquea la fila del cliente (`lockForUpdate`, recorrido 34
  §3.2) y ese bloqueo solo dura lo que dura una transacción. Antes `update()` no abría ninguna.
  Con ella, un doble clic en Guardar no crea dos fichas: el segundo espera y encuentra la ficha por
  `cliente_id`.
- **Antes de `sincronizarCatalogo()`.** Como la ficha recién resuelta tiene el mismo documento que
  el pasajero, `sincronizarCatalogo()` la reconoce como «la misma persona» y la reusa.

### 3.5 La `:key` de la lista

```vue
v-for="c in catalogoResultados[p.id]" :key="`${c.origen}-${c.id ?? c.cliente_id}`"
```

Antes era `:key="c.id"`. Con varios clientes, todos tendrían `key = null`: Vue necesita claves
únicas para saber qué elemento es cuál al redibujar. Se arma con el origen y el id que exista
(`pasajero-9`, `cliente-12`).

## 4. Cómo probarlo

```
php artisan test --filter=T018
```

En agencia-demo, reserva → Pasajeros → «Buscar pasajero»:
- DNI o nombre de un cliente que nunca viajó → sale con badge «Cliente». Elegir y Guardar.
- Buscar de nuevo → sale una sola vez, como «Pasajero».
- Elegir un cliente, cambiar el documento a mano y Guardar → ese cliente no queda vinculado.

## 5. Ejercicios

1. En §3.2, si alguien agrega `TM` a `TIPOS_PERSONA` en `datosDePasajero()` pero no al SQL, ¿qué
   pasa con un cliente con tarjeta militar? ¿Y al revés (solo en el SQL)?
2. En §3.3, quita el segundo `whereNotExists`. Busca a la persona de la ficha 9: ¿cuántas veces
   sale y con qué badges? Al elegir el resultado «Cliente» y guardar, ¿se crea otra ficha? (pista:
   el paso 2 de `desdeCliente()`).
3. En §3.4, saca el `DB::transaction`. ¿Qué hace entonces el `lockForUpdate()` de `desdeCliente()`?
4. Un cliente «María López» tiene ficha con su DNI pero escrita «MARIA LOPEZ». Buscas «María»:
   ¿qué sale y por qué? (está anotado en «Por analizar» del backlog).
5. En §3.5, deja `:key="c.id"` y busca un texto que devuelva tres clientes. ¿Qué te avisa Vue en la
   consola?
