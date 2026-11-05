# Recorrido: Venta directa con varias líneas, fecha por línea y hotel por noches

05-nov-2026, tarea **T-085** (obs. 12, H46 y H47; absorbió T-049). Decisión: **D-AGE-034**.

---

## 1. El problema

Venta directa vendía **un solo servicio**. Si el cliente compraba un traslado y dos noches de
hotel, había que hacer dos ventas, con dos reservas y dos comprobantes. Además:

- La reserva tenía **una sola fecha** para todo: con dos tours en días distintos, el Reporte
  operativo los mostraba el mismo día.
- El hotel se cobraba «por persona» o «por unidad», como cualquier tarifa, cuando su precio es
  **por habitación y por noche**.
- El vendedor recién se enteraba de que la reserva **no se podía facturar** (Amazonía mezclada con
  gravado) cuando ya estaba creada.

Ahora:

- Una venta tiene **de 1 a 20 líneas**: tarifas de proveedor y conceptos libres.
- Cada línea tiene su **día** (1 a 14) dentro del viaje.
- El hotel se cobra **por noches** y muestra «Sale el…».
- Una venta va en **una sola moneda**: una tarifa en otra moneda se bloquea con «No se mezclan
  monedas».
- El resumen avisa **antes de crear** si la venta se va a poder facturar.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Services/AgenciaViajes/CriterioTributarioService.php` | **Nuevo.** `evaluar()`: la regla de «qué se puede facturar junto», sacada de la facturación |
| `app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php` | `detectarMezclaTributaria()` ahora llama al servicio (mismo resultado que antes) |
| `app/Http/Controllers/AgenciaViajes/VentaDirectaController.php` | `store()` y `vistaPrevia()` con `lineas[]`; `resolverLineas()`, `fechaViajeHasta()`, `facturable()` |
| `app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` | `resolverTratamientoTributario()` pasó a `public static` |
| `admin-start-kit/src/views/agencia-viajes/venta-directa.vue` | Pantalla nueva de dos columnas |
| `admin-start-kit/src/services/admin/ventaDirectaService.ts` | Tipos `LineaVentaDirecta`, `VistaPreviaVentaDirecta` |
| `tests/Feature/AgenciaViajes/VentaDirectaLineasTest.php` | 15 tests (CA1 a CA7) |
| `tests/Unit/AgenciaViajes/CriterioTributarioServiceTest.php` | 6 tests de la regla |

Tablas: ninguna nueva. Se reusan `alternativa_items.dia_referencial` (el día de la línea) y
`cotizaciones.fecha_viaje_desde/hasta`.

## 3. El recorrido

### 3.1 Primero, sacar la regla tributaria a un servicio

La vista previa tenía que decir «esta venta no se puede facturar» con **el mismo motivo** que daría
la facturación. Si se copiaba la regla, las dos copias terminarían separándose (la lección del
recorrido 36). Por eso el primer commit (`8a56aa5`) es solo un refactor: la lógica de
`detectarMezclaTributaria()` se mudó a `CriterioTributarioService::evaluar()`.

`evaluar()` recibe una colección de líneas «neutrales», que no saben si vienen de una reserva o de
una venta que todavía no existe:

```php
['id' => …, 'destino' => 'gravado'|'amazonia'|'extranjero', 'carga_tour' => ?string, 'confirmado' => bool]
```

y devuelve `null` (se puede facturar) o el motivo. El orden de las preguntas:

1. ¿Hay `extranjero`? Bloqueado (la exportación está pausada).
2. ¿Hay más de un destino? Bloqueado, salvo que la mezcla esté dentro de **una** carga de tour.
3. ¿Hay líneas de la Amazonía **sin confirmar**? Bloqueado hasta que alguien las confirme.

### 3.2 La vista previa usa el mismo servicio

`VentaDirectaController::facturable()` arma esas líneas neutrales con lo que el vendedor eligió:

```php
'carga_tour' => null,   // las cargas de tour llegan con T-047
'confirmado' => true,   // la confirmación de la Amazonía es de T-048
```

Se dan por **confirmadas** porque, si no, cualquier venta de la Amazonía saldría «no facturable»
y el aviso no serviría. Lo que importa en la vista previa es la **mezcla**. Aparte devuelve
`requiere_confirmar_amazonia`, para que la pantalla avise que alguien tendrá que confirmarla.

El test `test_ca7_amazonia_mas_gravado_no_es_facturable_con_el_mismo_motivo_que_la_facturacion`
crea la reserva de verdad, intenta facturarla y compara los dos mensajes.

### 3.3 Las líneas: `resolverLineas()`

Por cada línea:

- **Tarifa de proveedor:** se carga con su proveedor y su servicio. Si está retirada o vencida,
  422 con `motivoTarifaNoUsable()` (el mismo de T-045).
- **Hotel:** `filled($tarifa->tipo_habitacion)`. Es **la misma señal** que usan la pantalla y
  `ReporteOperativoController::hotelDelItem()`. (La revisión lo corrigió: decía `!== null`, y una
  tarifa con texto vacío se habría tratado distinto en cada lado.) Un hotel se fuerza a «por
  unidad» con `cantidad` = noches; si llega «por persona», 422.
- **Concepto libre:** su tratamiento tributario sale del default de la agencia
  (`resolverTratamientoTributario()`), igual que en el cotizador.

### 3.4 Una sola moneda

```php
} elseif ($r['moneda'] !== $moneda) {
    abort(422, 'La venta es en soles y «…» está en dólares. No se mezclan monedas.');
}
```

La venta toma la moneda que elige el vendedor (soles por defecto) y **no convierte nada**. Un
concepto libre toma la moneda de la venta. Antes se pensó tomar la moneda de la primera línea, pero
eso era arbitrario: depende del orden en que se agregan las cosas (D-AGE-034).

En la pantalla, las tarifas en otra moneda salen deshabilitadas («Está en dólares») y
`motivoMoneda` deshabilita «Crear» si ya hay una línea en otra moneda.

### 3.5 Fecha por línea y fecha «hasta»

La línea guarda su día en `alternativa_items.dia_referencial`. Al crear la reserva,
`ReservaItemCreacionService::crear()` calcula la fecha de cada ítem: `fecha_viaje_desde + día − 1`.

La fecha «hasta» del viaje es la más tardía entre los servicios **y la salida de cada hotel**:

```php
$fecha = $inicio->copy()->addDays($linea['dia'] - 1 + ($linea['es_hotel'] ? $linea['cantidad'] : 0));
```

Ejemplo: inicio jueves 05/11, hotel desde el día 2 por 2 noches → entra el viernes 06, sale el
domingo 08. El viaje es «del jue 05/11 al dom 08/11». El Reporte operativo deriva la estadía y la
salida con la lógica de T-055 (lo comprueba el CA2).

### 3.6 El payload viejo sigue funcionando

Sin `lineas`, `store()` arma una línea con los campos de T-045 (`datosConLineas()`). La vista
previa conserva su respuesta vieja en `vistaPreviaTarifaSuelta()`. Hoy solo los usan los tests:
quedó en «Por analizar» para retirarlo.

### 3.7 La pantalla

- **Dos columnas:** a la izquierda, cliente, destino, fecha de inicio, pasajeros y servicios; a la
  derecha, un **resumen fijo** con el total y el botón.
- **Catálogo en el lugar:** «+ Agregar servicio» abre el catálogo donde están las líneas; al
  elegir, se cierra y la línea nueva queda **resaltada** 2,5 s (`resaltadoUid`).
- **Filtro de destino propio del catálogo** (agregado en la revisión): el «Destino» de arriba es
  el de la reserva; el catálogo tiene su propio filtro, que arranca en el destino de la venta y se
  puede cambiar o dejar en «Todos» sin tocar la reserva.
- **Total en vivo:** la misma técnica de T-045 (debounce de 300 ms, `vistaPreviaSeq` para descartar
  respuestas viejas, «…» y «Crear» deshabilitado mientras calcula).
- **Aviso tributario:** si la venta no se podrá facturar, el resumen lo dice, pero **no bloquea**
  «Crear»: la reserva se puede crear y corregir antes de facturar.
- **Sin permiso de catálogo** (403): el catálogo dice «Usa Concepto libre o pídelo al
  administrador».

## 4. Cómo probarlo

```
php artisan test --filter="VentaDirecta|CriterioTributario|ReservaFacturacion|ReporteOperativo"
```

En agencia-demo: Agencia → Venta directa. Agrega un traslado (día 1), un hotel de 2 noches (día 2)
y un concepto libre. Mira el rango del viaje en el resumen. Cambia la moneda a dólares: «Crear» se
deshabilita con «No se mezclan monedas».

## 5. Ejercicios

1. En §3.2, ¿qué pasaría si `facturable()` mandara `confirmado => false`? ¿Qué vería el vendedor en
   una venta solo de la Amazonía?
2. Una venta con un hotel de 3 noches el día 1 y un tour el día 2. ¿Qué devuelve
   `fechaViajeHasta()`? Hazlo a mano y compáralo con la pantalla.
3. Busca con `grep -rn "tipo_habitacion" app/` todos los lugares que deciden «es hotel». ¿Usan
   todos `filled()`?
4. `evaluar()` pregunta primero por `extranjero` y después por la mezcla. ¿Cambia algo si se
   invierte el orden? Prueba con `['extranjero', 'gravado']`.
5. ¿Por qué el filtro de destino del catálogo no se manda en `store()`?
