# Recorrido: cotizador sin recargas, con indicadores y sin pisar lo que se escribe

13-nov-2026, tarea **T-032**. Origen: medición en pantalla del cotizador
(`docs/requerimientos/observaciones-pruebas-usuario.md`, obs. 9, propuestas **R4** y **R5**, y hallazgo **H13**).
Archivos:
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue` (bloque «T-032 (R5): actualización parcial», `enviarEdicion`, `inicializarEdicionItems`, `cargarBiblioteca`)
- `admin-start-kit/src/components/AgenciaViajes/ItemManualForm.vue`, `PasajeAereoForm.vue` (pasan la alternativa al emitir `agregado`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` (`store`, `update`, `destroy`, `RELACIONES_LIENZO`)
- `api-sistema-fe/tests/Feature/AgenciaViajes/T032RespuestasParaActualizacionParcialTest.php`

**Al desplegar:** nada (sin migraciones). El frontend y el backend van juntos: el frontend nuevo aprovecha
los campos nuevos de las respuestas, y si no llegan, recarga como antes.

---

## 1. La idea

Antes, casi todas las acciones del cotizador terminaban en `cargarCotizacion()`: pedía de nuevo **toda** la
cotización con todas sus relaciones y reiniciaba los campos de edición. Por eso cada acción tardaba 1–3 s
sin ningún aviso, y lo que el vendedor estaba escribiendo en otro precio podía borrarse (H13).

Ahora, cuando el servidor ya devuelve lo que cambió, la pantalla actualiza **solo eso**.

| Acción | Antes | Después |
|---|---|---|
| Editar precio (incluye 400 ms de espera mientras se escribe) | 2,1 s | 1,1 s |
| Descuento global | 1,2 s | 0,7 s |
| Elegir opción de un grupo de hoteles | 1,2 s | 0,8 s |
| Eliminar ítem | 1,8 s | 1,4 s |

(Una corrida en el entorno local: tómalo como orden de magnitud.)

## 2. El recorrido

### 2.1 Backend: las respuestas traen lo necesario

No cambió ninguna lógica, solo **qué devuelven** tres métodos de `AlternativaItemController`:

| Método | Antes | Ahora agrega |
|---|---|---|
| `update` (editar precio) | el ítem | `alternativa` (con su `total`) |
| `destroy` (eliminar) | solo un mensaje | `alternativa` con los ítems que quedan |
| `store` (agregar, cualquier origen) | el ítem sin relaciones | el ítem con `RELACIONES_LIENZO` + `alternativa` |

`RELACIONES_LIENZO` son las relaciones que el lienzo lee de cada ítem (proveedor, servicio, hotel,
mayorista, guía, pasaje…). Es **una copia** de la lista de `CotizacionController::show()`. Sin ellas, un ítem
recién agregado aparecía como «Servicio» sin nombre (ya pasó en la sesión M4). Las dos listas tienen un
comentario que avisa: si cambias una, cambia la otra.

`elegirOpcionGrupo()` y `AlternativaController::update()` (descuento global) ya devolvían la alternativa con
sus ítems.

### 2.2 Frontend: dos funciones, un solo lugar

En `editar.vue`, justo después de `cargarCotizacion()`, hay un bloque con dos funciones. Las acciones solo
llaman a estas; ninguna arma el reemplazo por su cuenta:

```ts
aplicarItemActualizado(item, alternativa)   // un ítem: lo reemplaza por id, o lo agrega si es nuevo
aplicarAlternativaActualizada(alternativa)  // la alternativa: sus columnas (total…) y, si trae, sus ítems
```

Las dos devuelven `false` si no pueden aplicar (respuesta incompleta, alternativa que ya no está). En ese
caso la acción cae a `cargarCotizacion()`: la recarga completa sigue siendo la red de seguridad.

**Por qué a veces hay que reemplazar todos los ítems.** Elegir una opción de grupo y el descuento global
reescriben el precio de **todos** los ítems de la alternativa (el descuento se reparte entre todos; ver
recorrido 63 §2). Por eso esas acciones usan `aplicarAlternativaActualizada` con la lista completa.

**Por qué no se pierden los nombres.** Esa lista llega sin relaciones. `aplicarAlternativaActualizada`
**mezcla** cada ítem nuevo sobre el que ya estaba en memoria (`Object.assign(previo, nuevo)`): actualiza los
precios y conserva las relaciones que ya tenía.

Después de aplicar, `despuesDeAplicarParcial()` hace lo que antes hacía la recarga: avisa a la revisión
antes de enviar (`versionCotizacion`), reinicia los campos de edición y los días, y vuelve a pedir la
diferencia si hay una modificación de reserva abierta.

**Siguen con recarga completa** (a propósito): las altas en bucle (una comparación de hoteles agrega varios
ítems), quitar una línea de tour o un grupo (esas respuestas no traen la alternativa) y los cambios de
estructura (alternativa, pasajeros, destinos, aceptar).

### 2.3 H13: no pisar lo que se está escribiendo

`inicializarEdicionItems()` copia el precio de cada ítem a los campos (`edicionItems`). Ahora **salta** un ítem si:

| Caso | Cómo se sabe |
|---|---|
| Escribió y todavía no se envió (los 400 ms de espera) | `edicionPendiente` (un `Set` de ids) |
| Su guardado está en vuelo | `guardandoEdicion` |
| Tiene el foco | `itemConFoco()`: busca el `data-item-id` más cercano al elemento enfocado |

Un bloque de tour lleva varios ids en `data-item-id` (`"12 13 14"`), por eso `itemConFoco()` los separa con
`split(/\s+/)`. Al principio decía `/s+/` (separaba por la letra «s») y el bloque de tour quedaba sin protección.

**El precio de un tour** no usa `edicionItems`: es un `:value` que Vue vuelve a escribir en cada redibujado.
Lo que se está escribiendo se guarda en `borradorPrecioCarga` y se descarta al confirmar el campo.

**Cuando termina el guardado del propio ítem**, `enviarEdicion()` pone en sus campos lo que guardó el
servidor (escribes 340.555 → queda 340.56), **salvo** que el vendedor haya vuelto a escribir o haya otro
guardado suyo en vuelo. Esto se agregó en la revisión: al proteger el ítem en vuelo, sus campos habían dejado
de tomar el valor guardado.

### 2.4 R4: indicadores

- **Biblioteca** (`cargarBiblioteca`): mientras carga, la lista se atenúa, muestra un spinner y no se puede
  hacer clic (`pointer-events:none`). Cada petición lleva un número (`bibliotecaPeticion`); si llega una
  respuesta que no es la última, se descarta. Así, cambiar dos veces de categoría rápido no muestra resultados
  cruzados.
- **Lienzo:** el descuento global se deshabilita con spinner mientras se aplica, y cada precio muestra un
  spinner mientras se guarda (el campo sigue editable, para no perder lo escrito). Eliminar, quitar grupo,
  elegir opción y el precio del tour ya tenían su indicador.

## 3. Cómo se probó

- `T032RespuestasParaActualizacionParcialTest` (4): qué devuelven `update`, `destroy` y `store`, y que un alta
  rechazada no agrega nada.
- En el navegador (agencia-demo): sin `GET cotizaciones/{id}` después de editar, agregar, eliminar, elegir
  opción y descuento global (CA1, CA2); escribir en un ítem mientras se guarda otro, en un ítem suelto y en
  un tour (CA3); biblioteca (CA4); totales y precios iguales al backend con descuento global en monto y en %
  y con un grupo de hoteles (CA5); campo con 340.56 tras escribir 340.555 (revisión).
- `type-check` sin errores nuevos (47 previos, de T-101) y `build` en verde.

## 4. Si algo falla

| Síntoma | Dónde mirar |
|---|---|
| Un ítem recién agregado sale como «Servicio» sin nombre | `RELACIONES_LIENZO` no coincide con `show()` |
| El total o un precio no coincide con el backend tras una acción | la acción reemplaza un ítem cuando la respuesta cambió todos: usar `aplicarAlternativaActualizada` |
| Lo que escribo en un precio se borra solo | `inicializarEdicionItems()` y los tres casos de §2.3; en un tour, `borradorPrecioCarga` |
| El campo no muestra lo guardado (decimales de más) | `enviarEdicion()`, la condición que deja aplicar la respuesta propia |
| La biblioteca muestra resultados de la categoría anterior | `bibliotecaPeticion` en `cargarBiblioteca()` |
| Una acción vuelve a tardar 2 s | ¿su respuesta dejó de traer `alternativa`? Entonces cae a `cargarCotizacion()` |
