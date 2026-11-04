# Recorrido: el tour muestra su salida y por qué está pendiente (reporte operativo)

03-nov-2026, tarea **T-057** (H57). Ola 6 (operación diaria de Agencia).

---

## 1. El problema

La fila de un tour en el reporte operativo decía solo «Tour completo». El guía y el vehículo de
la salida ya llegaban en la respuesta (`guia`, `salida_vehiculo`), pero no se mostraban. No había
enlace a la salida y, si la fila contaba como «sin asignar» (el contador naranja de arriba), no
decía **por qué**.

| Antes | Ahora |
|---|---|
| Tour completo | Tour completo |
| | 👤 Sin guía (o el nombre del guía) |
| | 🚌 Vehículo de la salida |
| | 🔗 Ver salida |
| | ⚠ Servicio sin proveedor: Almuerzo (en ámbar, solo si está pendiente) |

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Services/AgenciaViajes/AsignacionOperativa.php` | `motivoSinAsignar()`: el porqué |
| `app/Http/Controllers/AgenciaViajes/ReporteOperativoController.php` | `armarFila()` agrega `motivo_pendiente` |
| `admin-start-kit/src/views/agencia-viajes/reporte-operativo/index.vue` | columna Asignación |
| `admin-start-kit/src/types/agencia-viajes.ts` | `motivo_pendiente?: string \| null` |
| `tests/Feature/AgenciaViajes/Fila13Fase2aSalidasTest.php` | salida cancelada (CA1) |
| `tests/Feature/AgenciaViajes/Fila13Fase2bServiciosVehiculosTest.php` | servicio sin proveedor (CA2) |

## 3. El recorrido

### 3.1 De «sí/no» a «por qué»

Antes, `AsignacionOperativa::sinAsignar()` devolvía `true`/`false`. Lo usan el reporte **y** el
Inicio (contador de pendientes), así que cambiar su criterio rompería los dos. El truco: mover
toda la lógica a una función nueva que devuelve el motivo, y dejar la vieja como una línea:

```php
public static function sinAsignar(ReservaItem $item): bool
{
    return self::motivoSinAsignar($item) !== null;
}
```

Dentro de `motivoSinAsignar()` cada `return true` pasó a ser un texto y cada `return false` un
`null`, **en el mismo orden**. Por eso el criterio no cambia (CA3) y los tests viejos siguen en
verde sin tocarlos.

### 3.2 Los motivos, por tipo de fila

| `origen_tipo` | Motivo | Cuándo |
|---|---|---|
| `tour` | Sin salida | el ítem no está enganchado a una salida |
| `tour` | Salida cancelada | `salida.estado = cancelada` |
| `tour` | Sin guía | no hay guía **y** el tour de catálogo lleva guía (`tourLlevaGuia()`) |
| `tour` | Servicio sin proveedor: {nombre} | un servicio de la salida con `proveedor_id` nulo (se nombra el primero) |
| `guia` | Sin guía / Guía referencial | sin guía efectivo, o el guía es referencial |
| `proveedor` | Sin proveedor | sin tarifa y sin hotel no registrado (`opcion_hotel_tarifa_id`) |
| `proveedor` | Proveedor referencial | el proveedor de la tarifa es referencial |

**Ojo:** un tour que no lleva guía muestra «Sin guía» en gris pero **no** cuenta como pendiente
(no sale el aviso ámbar). Es a propósito: se deja así hasta ver qué piden los usuarios.

### 3.3 El controlador

```php
'motivo_pendiente' => $sinGuia ? AsignacionOperativa::motivoSinAsignar($item) : null,
```

`$sinGuia` ya venía de `itemSinAsignacionOperativa()`, que llama a `sinAsignar()`. Solo se
pide el motivo cuando hace falta. `salidaOperativa.servicios` ya se cargaba junto con los
ítems, así que nombrar el servicio no agrega consultas.

Las filas derivadas (noches de hotel, salida del hotel, vuelos) no pasan por `armarFila()` y no
llevan el campo: por eso en el tipo es opcional (`?`).

### 3.4 La pantalla y la trampa del `v-if`

La columna Asignación es **una sola cadena** de `v-if` / `v-else-if` / `v-else`: según el tipo
de fila se muestra un bloque u otro. La primera versión puso el aviso de la fila de guía como un
elemento suelto en medio:

```html
<GuiaBuscador v-else-if="fila.origen_tipo === 'guia'" ... />
<div v-if="fila.motivo_pendiente">…</div>          <!-- ← abre una cadena NUEVA -->
<div v-else-if="fila.origen_tipo === 'tour'">…</div>  <!-- ahora cuelga del div de arriba -->
<template v-else-if="fila.origen_tipo === 'proveedor'">…</template>
<span v-else>—</span>
```

Un `v-else-if` se engancha al `v-if` hermano **más cercano**. Ese `<div v-if>` cortó la cadena
en dos, y desde ahí tour, proveedor y «—» dependían de si había motivo, no del tipo de fila:

- Tour o proveedor **pendiente** → solo el motivo; **desaparecía el buscador de proveedor**.
- Guía vía salida pendiente → el motivo dos veces.
- Guía sin salida asignado → el buscador más un «—» de sobra.

Vue no da error ni aviso, porque la plantilla es válida. `type-check` y los tests de backend no
lo ven: solo se nota mirando la pantalla con una fila pendiente.

El arreglo es agrupar sin agregar un elemento al DOM con `<template>`:

```html
<template v-else-if="fila.origen_tipo === 'guia'">
    <GuiaBuscador ... />
    <div v-if="fila.motivo_pendiente" class="text-warning">…</div>
</template>
```

**Regla para recordar:** dentro de una cadena `v-if`/`v-else-if`, todo lo que va junto a una
rama se mete en esa rama (un `<div>` o un `<template>`), nunca como hermano suelto.

### 3.5 Guía cambiado desde la pantalla

En la fila de guía sin salida, al elegir un guía en el buscador la pantalla no recarga. Por eso
`guardarGuia()` recalcula el motivo en el cliente con el mismo criterio que el backend:

```ts
f.motivo_pendiente = !nuevoGuia ? 'Sin guía' : (sinGuia ? 'Guía referencial' : null);
```

Al cambiar proveedor, la pantalla ya recargaba el reporte entero y el motivo llega del servidor.

## 4. Cómo probarlo

```
php artisan test --filter="Fila13Fase2|ReporteOperativo|Dashboard|AsignacionOperativa"
```

En agencia-demo, Reporte operativo → «Esta semana» (03 al 09-nov-2026): el Full Day Alto Mayo
del 06-nov sale con «Servicio sin proveedor: Almuerzo» en ámbar. Si se le asigna proveedor al
almuerzo en su salida, el aviso desaparece y el contador «sin asignar» baja.
