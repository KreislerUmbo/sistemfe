# Recorrido: Venta directa con pasajeros por cantidad, residencia y el cliente como primer pasajero

05-nov-2026, tarea **T-046** (obs. 12, H43). Decisión: **D-AGE-034** (con el enlace a la maqueta
aprobada).

---

## 1. El problema

En Venta directa los pasajeros solo llevaban **edad**: un botón «+ Adulto» agregaba una fila con 30
años. Faltaban tres cosas:

- La **residencia** (nacional o extranjero). Cambia el precio de tours y entradas, y sin ella
  T-047 no puede cobrar un tour bien.
- El **cliente casi siempre viaja**, pero nadie lo ponía como pasajero: había que escribirlo después
  en la reserva.
- Los **nombres**: no había dónde escribirlos al vender.

Ahora:

- Tres tarjetas (Adultos / Niños / Infantes) con contadores − / + para nacionales y extranjeros, y el
  rango de edad de la agencia al lado («13 años o más»).
- El cliente persona natural va de **primer adulto**, con su nombre, documento y ficha del catálogo,
  igual que al aceptar una cotización (T-017). Se puede quitar con «No viaja».
- «Agregar nombres (opcional)» abre una fila por pasajero.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Services/AgenciaViajes/PasajeroCatalogoService.php` | `pasajeroPorDefecto()` y `sincronizarDesdeReserva()`, **movidos** acá (antes en dos controllers) |
| `app/Http/Controllers/AgenciaViajes/ReservaController.php` | `aceptar()` usa `pasajeroPorDefecto()` en vez de su cierre propio |
| `app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php` | Usa `sincronizarDesdeReserva()`; se borró su copia privada `sincronizarCatalogo()` |
| `app/Http/Controllers/AgenciaViajes/VentaDirectaController.php` | `store()` con residencia, nombre, documento y `cliente_viaja`; `guardarNombres()`; `umbrales()`; extranjeros en la vista previa |
| `routes/api.php` | `GET venta-directa/umbrales-edad` (permiso `reservas.crear`) |
| `admin-start-kit/src/views/agencia-viajes/venta-directa.vue` | Bloque **2 · Pasajeros** nuevo y el aviso bajo el cliente |
| `admin-start-kit/src/services/admin/ventaDirectaService.ts` | `PasajeroVentaDirecta`, `cliente_viaja`, `umbralesEdad()` |
| `tests/Feature/AgenciaViajes/VentaDirectaPasajerosTest.php` | 10 tests |

Tablas: ninguna nueva. La residencia va a `cotizacion_pasajeros.residencia` (ya existía) y de ahí
`crearReservaDesdeAlternativa()` la copia a `reserva_pasajeros`. Nombre y documento van **solo** a
`reserva_pasajeros` (`cotizacion_pasajeros` no tiene esas columnas, y no se le agregaron).

## 3. El recorrido

### 3.1 Primero, un refactor sin cambiar nada

Dos piezas que Venta directa necesitaba vivían escondidas en controllers:

- El **cierre** que arma «el cliente como pasajero» estaba dentro de `ReservaController::aceptar()`.
- `sincronizarCatalogo()` (crea o actualiza la ficha en `pasajeros_catalogo` a partir de un
  pasajero con nombre y documento) era **privado** de `ReservaPasajeroController`.

Copiarlas habría dejado dos versiones que con el tiempo se separan. Por eso el primer commit
(`8e0ca33`) solo las muda a `PasajeroCatalogoService`. La prueba de que no cambió nada: los tests
de T-017, T-018 y de pasajeros pasaron **sin tocarlos**.

`pasajeroPorDefecto($cliente)` devuelve una **función**, no los datos. ¿Por qué? Porque
`desdeCliente()` crea la ficha del catálogo, y solo hay que crearla si la reserva tiene un adulto
libre donde poner al cliente. `crearReservaDesdeAlternativa()` llama a la función recién cuando
encuentra ese lugar. Así no quedan fichas sin uso.

### 3.2 `store()`: residencia, nombres y el cliente

El payload de cada pasajero ahora puede traer:

```json
{ "edad": 30, "residencia": "extranjero", "nombre": "Ana Ríos", "documento": "45879632" }
```

Todo es opcional (sin residencia es nacional), así que el payload viejo sigue funcionando. Además
llega `cliente_viaja` (por defecto `true`).

Dentro de la misma transacción:

1. Crea los `cotizacion_pasajeros` con su residencia, y se guarda cada uno en `$cotizacionPasajeros`.
2. Decide si el cliente va de primer adulto:
   - `cliente_viaja` es `true`, **y**
   - el vendedor **no escribió** nombre ni documento en el primer adulto (si escribió a otra
     persona, manda lo escrito y no se crea la ficha del cliente).
3. Llama a `crearReservaDesdeAlternativa($alternativa, [], $pasajeroPorDefecto)`, el **mismo
   camino** que aceptar una cotización.
4. `guardarNombres()` escribe nombre y documento en cada `reserva_pasajero`, buscándolo por su
   `cotizacion_pasajero_id`, no por el orden. Si quedó con los dos datos, sincroniza el catálogo
   con `sincronizarDesdeReserva()`, el mismo método que usa la pantalla de la reserva.

### 3.3 Los rangos de edad sin abrir un permiso

Los rangos («3 a 12 años») salen de `configuracion_agencia`, pero `GET configuracion-agencia` pide
`agencia.configuracion`, que un vendedor no tiene. Primero llegaban con la vista previa, pero la
vista previa necesita un servicio, y el vendedor cuenta a los pasajeros **antes**.

Eso causó un bug (encontrado en la revisión): un niño agregado antes de conocer los rangos nacía con
3 años. En una agencia con infantes de 0 a 3, el backend lo clasificaba como **infante** y lo
cobraba como tal, aunque la pantalla decía «1 niño». La solución tiene dos partes:

- `GET venta-directa/umbrales-edad` (`VentaDirectaController::umbrales()`), con el permiso de la
  venta directa (`reservas.crear`). La pantalla lo pide al abrir.
- Un `watch(umbrales)` que lleva a cada niño ya agregado a su rango (`ajustarEdad()`), por si el
  vendedor fue más rápido que la respuesta.

### 3.4 La vista previa cuenta extranjeros

`vistaPrevia()` recibe la residencia y devuelve en el desglose de cada línea cuántos de cada tipo
son extranjeros:

```json
"desglose": { "adulto": { "cantidad": 3, "precio": 50, "extranjeros": 1 }, … }
```

Hoy nadie lo usa para el precio. Lo va a usar T-047, porque un tour cobra distinto a un extranjero.

### 3.5 La pantalla

Sigue la maqueta aprobada (enlace en D-AGE-034). La primera versión se armó leyendo solo el texto de
la tarea y quedó distinta; se rehízo en la revisión.

- Cada contador crea o quita **filas** (`filas`). Una fila es lo que viaja al backend: tipo,
  residencia, edad y, si se escribieron, nombre y documento.
- `paxOrdenados` ordena: adultos nacionales, adultos extranjeros, niños nacionales… El backend
  crea los pasajeros en ese orden, así que **el primer adulto de la pantalla es el mismo** que el
  backend usa para el cliente.
- `datosClientePasajero` aplica en el frontend la misma condición que `datosDePasajero()` en el
  backend: DNI, CE o pasaporte, con número real (no vacío ni `00000000`) y con nombre.
- La fila del cliente muestra sus datos sin guardarlos en `f.nombre`/`f.documento` (quedan `null`).
  Si el vendedor no los toca, la pantalla no manda nada y el backend completa la fila con la ficha
  del cliente. Si los vacía a mano, manda `cliente_viaja: false`.
- «−» quita primero una fila **sin datos**, para no borrar lo que el vendedor ya escribió.

## 4. Cómo probarlo

```
php artisan test --filter=VentaDirectaPasajeros
```

En agencia-demo, en `/agencia-viajes/venta-directa`:

1. Los rangos aparecen al abrir, sin servicios.
2. 2 adultos nacionales + 1 adulto extranjero + 1 niño extranjero → la cabecera dice
   «3 adultos · 1 niño (2 ext.)».
3. Elige un cliente con DNI → bajo el cliente aparece «Persona natural: viaja como primer
   pasajero.» y, en «Agregar nombres», la primera fila es «Cliente · Adulto nacional» con sus datos.
4. «No viaja» → la primera fila queda vacía.

## 5. Ejercicios

1. Un vendedor escribe en la **segunda** fila el DNI del cliente y deja la primera vacía. ¿Cuántas
   veces aparece el cliente en la reserva? (Mira el paso 2 de §3.2 y qué hace
   `sincronizarDesdeReserva()` cuando encuentra el documento.)
2. ¿Por qué `pasajeroPorDefecto()` devuelve una función y no directamente el arreglo con los datos?
3. Si la agencia cambia su rango de niño a «2 a 11 años» con la pantalla abierta, ¿qué pasa con los
   niños que ya están cargados? ¿Y con uno que se agregue después de recargar?
4. ¿Por qué `guardarNombres()` busca cada `reserva_pasajero` por `cotizacion_pasajero_id` y no por
   la posición?
