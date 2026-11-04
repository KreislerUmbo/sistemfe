# Recorrido: el vuelo propio del pasajero en un solo texto (reserva y reporte operativo)

04-nov-2026, tarea **T-077**. Ola 6 (operación diaria de Agencia).

---

## 1. El problema

En Pasajeros de la reserva, el vuelo que el pasajero compró **por su cuenta** eran 6 campos:
aerolínea, fecha y hora de ida y de vuelta. Llenarlos tomaba tiempo y se confundía con el bloque
de abajo, que es el vuelo que **vende la agencia**. Además, el reporte operativo los convertía en
filas «Vuelo ida» / «Vuelo vuelta», como si fueran un servicio.

Decisión del usuario: el vuelo propio es un dato para **avisarle** al pasajero, no un servicio.
Va en un texto corto y sale como observación en todas sus filas.

| Antes | Ahora |
|---|---|
| 6 campos (aerolínea, fecha, hora × ida y vuelta) | 1 textarea: «LATAM LA2203, llega 30/10 08:40 · regresa 02/11 19:10» |
| Filas «Vuelo ida» / «Vuelo vuelta» en el reporte | Observación del pasajero en todas sus filas |
| 6 columnas en el Excel | 1 columna «Vuelo propio» |
| 6 columnas en `reserva_pasajeros` | 1 columna `vuelo_propio`; las 6 viejas se **borraron** |

El vuelo de la agencia (`reserva_item_vuelo_pasajero`, filas «Vuelo ida (agencia)») no cambió.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `database/migrations/tenant/verticals/agencia-viajes/2026_11_04_100000_vuelo_propio_texto_en_reserva_pasajeros.php` | agrega, copia y borra |
| `app/Models/AgenciaViajes/ReservaPasajero.php` | `$fillable` con `vuelo_propio` |
| `app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php` | valida `vuelo_propio` (`nullable\|string\|max:500`) |
| `app/Http/Controllers/AgenciaViajes/ReporteOperativoController.php` | `armarFila()` y `extenderConFilasDeVuelo()` |
| `app/Exports/ReporteOperativoDatosSheet.php` | hoja «Datos»: 1 columna en vez de 6 |
| `app/Exports/ReporteOperativoVistaOperativaSheet.php` | hoja «Vista operativa»: columna G |
| `resources/views/pdf/agencia-viajes/reporte-operativo.blade.php` | PDF: línea «Vuelo propio: …» |
| `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue` | el textarea |
| `admin-start-kit/src/views/agencia-viajes/reporte-operativo/index.vue` | columna «Vuelo propio» |
| `tests/Feature/AgenciaViajes/ReporteOperativoTest.php` | CA1, CA2, CA4, CA6 y PDF/Vista operativa |

## 3. El recorrido

### 3.1 Una migración que agrega, copia y borra

Nunca se edita una migración vieja: se escribe una nueva que hace tres pasos en orden.

```php
public function up(): void
{
    // 1. la columna nueva
    Schema::table('reserva_pasajeros', fn (Blueprint $t) => $t->text('vuelo_propio')->nullable());

    // 2. copiar lo que hubiera en las viejas, armado como texto
    $filas = DB::table('reserva_pasajeros')->where(/* alguna vieja no nula */)->get(...);
    foreach ($filas as $fila) {
        DB::table('reserva_pasajeros')->where('id', $fila->id)
            ->update(['vuelo_propio' => $this->armarTexto($fila)]);
    }

    // 3. recién ahora, borrar las 6 viejas
    Schema::table('reserva_pasajeros', fn (Blueprint $t) => $t->dropColumn(self::COLUMNAS_VIEJAS));
}
```

**Por qué copiar si dakamu no tenía datos:** no sabemos qué cargó cada tenant. Copiar cuesta unas
líneas; perder un dato no se recupera.

**Por qué `DB::table` y no el modelo:** la migración corre una sola vez, con el esquema de *ese*
momento. Si usara `ReservaPasajero`, dependería de cómo esté el modelo el día que se corra (y el
modelo ya no conoce las columnas viejas).

**`down()`** vuelve a crear las 6 columnas, **vacías**: el dato ya vive en el texto y no se puede
partir de vuelta en aerolínea/fecha/hora. Un `down()` sirve para deshacer el esquema, no siempre
los datos.

### 3.2 Cuidado: los mismos nombres en otras tablas

`vuelo_fecha_ida`, `vuelo_hora_ida`… también existen en `reserva_item_vuelo_pasajero` (el vuelo
de la agencia), y `vuelo_ida`/`vuelo_vuelta` en paquetes, mayoristas y pasajes cotizados. Por eso
la tarea pedía revisar cada uso **uno por uno**: un buscar y reemplazar habría roto el vuelo de la
agencia. Después de la tarea, un `grep` de las 6 columnas solo encuentra esos otros usos.

### 3.3 Del dato a la fila del reporte

`armarFila()` arma el bloque `pasajero` de cada fila. Antes armaba además `vuelo_ida` y
`vuelo_vuelta` con las columnas viejas; ahora solo agrega:

```php
'vuelo_propio' => $pasajero->vuelo_propio,
```

Como va dentro de `pasajero`, viaja con el pasajero a **todas** sus filas sin trabajo extra, igual
que la alimentación especial y la discapacidad. Y `extenderConFilasDeVuelo()` deja de fabricar las
filas sintéticas del vuelo propio (las de la agencia siguen).

### 3.4 Lo que la revisión encontró: cuatro salidas, no dos

El reporte operativo tiene **cuatro** salidas, y cada una pinta el dato por su cuenta:

| Salida | Dónde se pinta |
|---|---|
| Pantalla | `reporte-operativo/index.vue` |
| Excel, hoja «Datos» | `ReporteOperativoDatosSheet` |
| Excel, hoja «Vista operativa» | `ReporteOperativoVistaOperativaSheet` |
| PDF | `reporte-operativo.blade.php` |

La primera versión puso el texto en las dos primeras. Las otras dos (las que se **reparten al
equipo de campo**) recibían el vuelo propio solo a través de las filas sintéticas; al quitarlas, el
dato desapareció de ahí sin ningún error. Los tests no lo vieron porque solo miraban la hoja
«Datos».

**Regla para recordar:** al quitar o mover un dato del reporte operativo, revisar las cuatro
salidas. El test nuevo (`test_pdf_y_vista_operativa_muestran_el_vuelo_propio`) renderiza el PDF y
la hoja «Vista operativa» y busca el texto.

### 3.5 Agregar una columna a la hoja «Vista operativa»

Esa hoja combina celdas (`mergeCells`) por letra de columna. Para meter «Vuelo propio» después de
«Discapacidad» hubo que tocar tres cosas juntas:

1. `encabezados()`: el título nuevo.
2. El valor en cada fila, en la misma posición.
3. `ULTIMA_COLUMNA` de `'K'` a `'L'` (las filas de fecha y de grupo se combinan de A a la última) y
   la lista de columnas que se combinan por pasajero (`A`…`G`).

Si se olvida el 3, las cabeceras de fecha quedan una columna más cortas que la tabla.

## 4. Cómo probarlo

```
php artisan test --filter="ReporteOperativo|ReservaItemPasajeroVuelo|ReservaPasajero"
```

En agencia-demo: en una reserva con servicios en el rango, escribir un vuelo propio a un pasajero
y guardar. En el reporte operativo, el texto sale en la columna «Vuelo propio»; en el PDF, debajo
de alimentación/discapacidad; en el Excel, en las dos hojas.

## 5. Ejercicios

1. ¿Por qué la migración copia con `DB::table('reserva_pasajeros')` y no con
   `ReservaPasajero::query()`?
2. Si corres `down()` y después `up()`, ¿qué pasa con los vuelos que estaban cargados? ¿Por qué?
3. Imagina que mañana se agrega «Observaciones generales» al pasajero. ¿Qué cuatro archivos tendrías
   que tocar para que salga en todo el reporte?
