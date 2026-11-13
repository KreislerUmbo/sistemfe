# Recorrido: PDF de cotización con la comparación de hoteles abierta + descuento bien calculado

13-nov-2026, tarea **T-023**. Origen: revisión del flujo de cotizaciones
(`docs/requerimientos/observaciones-pruebas-usuario.md`, hallazgos **H4**, aprobado el 26-oct, y **H10**).
Archivos:
- `api-sistema-fe/app/Services/AgenciaViajes/AlternativaPdfService.php` (`construir`, `conPrecioLista`, `precioPorOpcion`, `totalSiSeEligen`)
- `api-sistema-fe/resources/views/pdf/agencia-viajes/alternativa.blade.php` (sección «Precio»)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionEnvioController.php` (solo el comentario)
- `api-sistema-fe/tests/Feature/AgenciaViajes/T023PdfComparacionAbiertaTest.php`

**Al desplegar:** nada (sin migraciones; el frontend solo cambió un comentario).

---

## 1. La idea

Dos problemas del mismo bloque del PDF, el de **Precio**:

**H4 — el PDF se bloqueaba.** El flujo real de la agencia: arma una comparación de hoteles, manda el
PDF **sin marcar ninguno**, y cuando el cliente responde marca el que eligió y acepta. Pero desde el
19-sep el PDF daba 422 con una comparación abierta, porque el total salía con el hotel más barato sin
decirlo. La solución aprobada: **no bloquear, decirlo claro**.

**H10 — el PDF inventaba descuentos.** Decía «Incluye descuento de temporada aplicado» sin que hubiera
descuento. El monto «antes del descuento» se sumaba con otra fórmula que el total, y salía más alto.

## 2. Antes de seguir: cómo se guarda el precio de un ítem

Cada `alternativa_items` tiene dos precios:

| Columna | Qué es |
|---|---|
| `precio_venta_snapshot` | precio de **lista**, en la moneda del proveedor (`moneda_costo`) |
| `precio_convertido` | lo que se cobra, **ya convertido** a la moneda de la alternativa y **con descuento** |

Y el total de una línea (`AlternativaItem::getTotalConvertidoAttribute()`) multiplica por `cantidad`
**solo** si `modo_precio = 'tarifa_fija'`; en `por_persona` el precio ya es el total de la línea.

Con una comparación de hoteles (filas con el mismo `grupo_opcion_id`), la regla de
`AlternativaItem::calcularTotalEfectivo()` es:

```
ítem sin grupo                 → suma
grupo resuelto (hay elegida)   → suma solo la elegida
grupo abierto (ninguna)        → suma el MÍNIMO del grupo
```

Y el descuento global (`AlternativaController::aplicarDescuentoGlobal()`) solo se aplica a los ítems sin
grupo y a la **elegida** de cada grupo. Las filas de un grupo abierto quedan a precio de lista. Cuando
el vendedor marca una elegida (`AlternativaItemController::elegirOpcionGrupo()`), se vuelve a aplicar
el `descuento_global_pct` guardado y la elegida recibe el %.

## 3. El recorrido

### 3.1 H10: «antes del descuento» con la misma regla que el total

Antes, en `construir()`:

```php
$totalOriginal = $items->sum(fn ($item) => $item->precio_venta_snapshot * $item->cantidad);
```

Tres errores en una línea: sumaba **todas** las filas de una comparación, **no convertía** la moneda, y
multiplicaba por `cantidad` también los `por_persona`. Caso real (agencia-demo, 10-nov): Cumbaza 300 +
Rio Sol 560 (elegida) = 860 de «lista», contra un total de 560 → «descuento» de 300 que no existía.

Ahora:

```php
$totalOriginal = AlternativaItem::calcularTotalEfectivo($this->conPrecioLista($alternativa->items, $alternativa))['total'];
```

`conPrecioLista()` arma **copias en memoria** de los ítems (`replicate()`, no se guarda nada) y les pone
`precio_convertido` = precio de lista convertido con `PriceEngineService::convertirMoneda()`, la misma
conversión que usa `aplicarDescuentoGlobal()`. Después pasa por la **misma** función que el total. Un solo
cálculo: si mañana cambia la regla del total, cambia también la del «antes del descuento».

### 3.2 H4: qué total mostrar (`precioPorOpcion`)

`precioPorOpcion()` cuenta los grupos **abiertos** y devuelve un `modo` para la plantilla:

| Grupos abiertos | `modo` | Qué muestra el PDF |
|---|---|---|
| 0 | `ninguno` | el total de siempre (y la nota de descuento si la hay) |
| 1 | `por_opcion` | «El total depende del hotel que elijas:» y una línea por hotel: «Con {hotel} ({habitación}): PEN X» |
| 2 o más | `desde` | «Desde PEN X», con «las opciones más económicas; el total final depende de los hoteles que elijas» |

Con dos o más grupos no se listan las combinaciones (2 × 3 hoteles ya son 6 totales); se da el piso.

### 3.3 Un solo cálculo para los dos modos: `totalSiSeEligen`

```php
private function totalSiSeEligen(Collection $items, Collection $filas, float $pct): float
```

Responde: «¿cuánto daría el total si el vendedor marcara estas filas como elegidas?». Para eso:

1. copia los ítems en memoria;
2. en cada grupo que tiene una fila en `$filas`, marca esa como `opcion_elegida` y le aplica el %
   global (`precio_convertido × (1 − pct/100)`, redondeado a 2), igual que haría `elegirOpcionGrupo()`;
3. pasa las copias por `calcularTotalEfectivo()`.

- **Modo `por_opcion`:** se llama una vez por fila del grupo abierto, con esa fila.
- **Modo `desde`:** se llama una vez, con la fila más barata de cada grupo abierto.

**Lo que se corrigió en la revisión.** Al principio el modo `desde` usaba el total guardado de la
alternativa. Como las filas de un grupo abierto están a precio de lista (§2), con un descuento global de
10 % el «Desde» daba 2250 cuando elegir las más baratas costaba 2070. El test
`test_ca5_con_descuento_global_…` lo reprodujo antes de corregir.

### 3.4 Lo que no cambia

- Con el grupo resuelto, el PDF sale como antes: la elegida resaltada en la tabla «Opciones de hoteles»
  y un solo total.
- Mientras el grupo está abierto, ninguna fila de esa tabla sale resaltada (ya era así).
- **Aceptar** sigue exigiendo una elegida por grupo (`ReservaController::aceptar()`, 422).
- `CotizacionEnvioController::enviar()` genera los PDFs antes de mandar el correo; con un grupo abierto
  ya no falla. Su `catch (ValidationException)` se mantiene para cualquier otro error de generación.
- Con un grupo abierto **no** se muestra «Incluye descuento…»: cada opción tiene su total, no hay un
  único «antes del descuento».

### 3.5 La plantilla

El bloque Precio es una tabla de dos columnas (texto | monto). En `por_opcion` la primera fila no lleva
monto: el título ocupa las dos columnas (`colspan="2"`), y debajo va una fila por opción. El nombre del
hotel va con `{{ }}` (escapado).

## 4. Cómo se probó

- `T023PdfComparacionAbiertaTest` (10 tests): CA1 (grupo resuelto sin descuento no dice «Incluye
  descuento»), CA2 (ítem en otra moneda y ítem `por_persona`), CA3 (10 % real muestra el monto real),
  CA4 (un total por opción, y con descuento global igual al total que da elegir de verdad), CA5 («Desde»
  sin y con descuento, comparado con elegir de verdad), CA6 (grupo resuelto como antes) y el nombre
  escapado. CA1, CA2, CA3 y el CA5 con descuento fallaron antes del fix.
- CA7 en `EnvioCorreoCotizacionTest`: correo con grupo abierto sale con el PDF adjunto.
- `SesionM5OpcionesHotelesPdfTest`: el test que comprobaba el bloqueo pasó a comprobar que ya no bloquea.
- CA9: PDF real de agencia-demo (alt. 106) abierta y resuelta, rasterizado con Ghostscript.

## 5. Si algo falla

| Síntoma | Dónde mirar |
|---|---|
| El PDF vuelve a decir «Incluye descuento» sin descuento | `conPrecioLista()`: ¿algún ítem nuevo guarda en `precio_venta_snapshot` algo que no es el precio de lista de `precio_convertido`? |
| El total de una opción no coincide con el que queda al elegirla | `totalSiSeEligen()` contra `aplicarDescuentoGlobal()`: ¿cambió a qué ítems se aplica el %? |
| «Desde» más alto que lo que termina pagando el cliente | ¿se volvió a usar el total guardado en vez de `totalSiSeEligen()`? (§3.3) |
| El PDF da 422 con una comparación abierta | se volvió a validar que los grupos estén resueltos en `construir()` |
| «Datos de pago» partida entre páginas | conocido, en «Por analizar» del backlog (la caja de Precio crece con los totales por opción) |
