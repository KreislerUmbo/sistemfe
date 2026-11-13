# Recorrido: PDF de cotización con fotos de todos los hoteles

13-nov-2026, tarea **T-021** (depende de T-020, recorrido 61). Origen: punto 7 de las pruebas del
usuario (`docs/requerimientos/observaciones-pruebas-usuario.md`, respuesta 2, «Diseño», sección PDF).
Archivos:
- `api-sistema-fe/app/Services/AgenciaViajes/AlternativaPdfService.php` (`hotelesParaPdf`, `seleccionarFotosDeHotel`)
- `api-sistema-fe/resources/views/pdf/agencia-viajes/alternativa.blade.php` (sección «Fotos referenciales de los hoteles»)
- `api-sistema-fe/tests/Feature/AgenciaViajes/T021PdfFotosHotelesTest.php`

**Al desplegar:** nada (sin migraciones ni frontend).

---

## 1. La idea

El PDF que recibe el cliente tiene una sección «Fotos referenciales de los hoteles»: el nombre del
hotel, una tira de hasta 3 fotos y el check-in/check-out. Sirve para que el cliente vea dónde se
aloja y pida otro hotel si no le gusta.

Antes esa sección solo salía para los hoteles **de una comparación** (grupo de opciones) que
vinieran de `opciones_hotel`. Dos casos quedaban sin fotos:

| Caso | Antes | Ahora |
|---|---|---|
| Hotel de mayorista o no registrado, en una comparación | sí | sí |
| Hotel **Proveedor** (registrado), en una comparación | no | sí |
| Cualquier hotel **suelto** (sin comparación) | no | sí |

## 2. El recorrido

### 2.1 Qué ítems son hotel (`hotelesParaPdf`)

`AlternativaPdfService::construir()` arma todos los datos del PDF y llama a
`hotelesParaPdf($alternativa)`, que recorre **todos** los ítems de la alternativa (antes recorría
solo las filas de la tabla «Opciones de hoteles»). Un ítem es hotel si:

- tiene tarifa de catálogo con `tipo_habitacion` (`proveedor_tarifa.tipo_habitacion`; mismo
  criterio que `ReservaController::resolverNombreItem`), o
- tiene `opcion_hotel_tarifa_id` (matriz de un mayorista o hotel no registrado).

Las relaciones ya vienen cargadas por el `with([...])` de `construir()`: no hay una consulta por ítem.

### 2.2 Un hotel sale una sola vez: la identidad

Un mismo hotel puede aparecer en dos filas (doble y matrimonial) o en dos destinos. Para no repetir
el bloque, cada hotel recibe una **clave**:

```
tarifa de catálogo         → 'proveedor:' + proveedor_id
OpcionHotel con proveedor  → 'proveedor:' + proveedor_id   (el mismo hotel, visto desde la comparación)
OpcionHotel sin proveedor  → 'adhoc:' + opcion_hotel_id
```

**Nunca por nombre**: dos hoteles distintos pueden llamarse igual (el bug del 18-sep-2026 que
documenta `opcionesHoteles()`). El orden del PDF es el orden en que aparecen los ítems.

En un grupo **resuelto** también salen los hoteles no elegidos, igual que en la tabla «Opciones de
hoteles» (que los muestra con la elegida resaltada).

### 2.3 De dónde salen las fotos

- Hotel `proveedor:` → `proveedores.fotos` (la galería de la ficha, T-020) y el check-in/check-out
  de `proveedor.alojamientoDetalle`.
  **Respaldo** (decidido con el usuario en la revisión, 13-nov): si es un `OpcionHotel` ligado a un
  proveedor y la ficha del proveedor **no tiene ninguna foto**, se usan las fotos propias del hotel
  (`opciones_hotel.fotos`). Así el hotel no desaparece del PDF.
- Hotel `adhoc:` → `opciones_hotel.fotos`.

Las dos listas pasan por `normalizarFotos()` del trait `FotosConTipo` (recorrido 61 §2.1), nunca
se leen a mano.

### 2.4 La tira: `seleccionarFotosDeHotel`

```
1. la fachada (si hay)
2. hasta 2 de habitación
3. si todavía hay menos de 3, se completa con 'otra'
```

Cada foto pasa por `ImagenRecorteService::recortar4x3ParaPdf()`: la recorta a 4:3 y la devuelve
lista para DomPDF, desde el disco del tenant (DomPDF no carga URLs remotas). Un hotel sin ninguna
foto **no entra** a la lista: el PDF nunca imprime un casillero vacío.

### 2.5 La plantilla

Antes la sección colgaba de cada grupo de la tabla de opciones. Ahora es **una sola sección**,
después de todas las tablas:

```blade
@if (count($hotelesParaPdf) > 0)
    título «Fotos referenciales de los hoteles»
    @foreach ($hotelesParaPdf as $hotelPdf)  → nombre {{ }} + fotos + check-in/out
@endif
```

- Si ningún hotel tiene fotos, la sección no sale.
- `page-break-before: always` **solo si hay tabla de opciones arriba**. Es el arreglo del bug del
  07-sep-2026: con Poppins, la sección pisaba la última fila de la tabla y DomPDF calculaba mal el
  espacio que quedaba. Con hoteles sueltos no hay tabla que pisar y se evita una página en blanco.
- El nombre va con `{{ }}` (escapado): un `<b>` en el nombre sale como texto.

### 2.6 Lo que no se hizo

`configuracion_agencia_pdf.mostrar_fotos_tour` es solo de tours; las fotos de hotel no tienen
interruptor (ni antes ni ahora). Si se quiere uno, es una tarea aparte.

## 3. Cómo se probó

- `T021PdfFotosHotelesTest` (12 tests): hotel suelto con fachada primero y check-in (CA1),
  comparación de 2 proveedores (CA2), solo fotos `otra` (CA3), mismo hotel en dos filas (CA4),
  mayorista como antes (CA5), sección ausente sin fotos (CA6), grupo resuelto, hotel sin fotos,
  nombre escapado, y los dos casos del respaldo de fotos de un hotel ligado a un proveedor.
  `SesionM5OpcionesHotelesPdfTest` sigue en verde.
- CA7: PDF real de `agencia-demo` (alt. 92 con hotel suelto, alt. 106 con comparación de 2 hoteles)
  rasterizado con Ghostscript: sin solapes, fotos sin deformar, fachada primera.

## 4. Si algo falla

| Síntoma | Dónde mirar |
|---|---|
| Un hotel no sale en las fotos | ¿tiene alguna foto? (ficha del proveedor, o las propias si la ficha no tiene) ¿el ítem tiene `tipo_habitacion` u `opcion_hotel_tarifa_id`? |
| Un hotel sale dos veces | la clave de §2.2: dos proveedores distintos con el mismo hotel real |
| La sección pisa la tabla | se perdió el `page-break-before` cuando hay `$opcionesHoteles` |
| Foto en blanco o rota | `recortar4x3ParaPdf()` no encontró el archivo en el disco del tenant |
| Sale la foto de habitación primero | el hotel no tiene fachada marcada (ficha del proveedor, T-020) |
