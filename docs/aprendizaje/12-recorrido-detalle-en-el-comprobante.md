# Recorrido: el comprobante dice qué servicios se cobraron

24-oct-2026, pedido del usuario. La boleta de una reserva salía con una sola línea,
**"Tour / Actividad turística"**, sin decir qué tours eran.

**Archivos nuevos:** ninguno.

| Archivo | Qué cambió |
|---|---|
| `api-sistema-fe/app/Models/Sale/SaleDetail.php` | nueva `descripcionParaSunat()` |
| `api-sistema-fe/app/Http/Controllers/Greenter/GreenterService.php` | el XML usa `descripcionParaSunat()` |
| `api-sistema-fe/resources/views/pdf/a4.blade.php` y `ticket80mm.blade.php` | el detalle debajo del producto |
| `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php` | `construirLineas()` arma el detalle; nueva `nombreEnComprobante()` |
| `api-sistema-fe/tests/Feature/AgenciaViajes/Fila13Fase1cFacturacionTest.php` | 2 tests nuevos |

---

## 1. Por qué salía "Tour / Actividad turística"

Al facturar una reserva, cada línea del comprobante (`sale_details`) guarda dos cosas:

| Campo | Qué es | Ejemplo |
|---|---|---|
| `product_id` → `product.title` | Un **producto genérico** por categoría: SUNAT necesita un producto y la agencia no tiene uno por cada tour | "Tour / Actividad turística" |
| `descripcion_detalle` | El **detalle real**: los servicios con su fecha, o el "Texto para este comprobante" | "Alto Mayo (29/10/2026); Laguna Azul (30/10/2026)" |

El detalle se guardaba desde agosto, pero **nadie lo leía**: el PDF y el XML de SUNAT solo usaban
`product.title`. Hasta el "Texto para este comprobante" se guardaba sin salir en ningún lado.

## 2. La regla

- **Sin "Texto para este comprobante":** cada servicio con su fecha, en el orden del viaje.
  `Tour / Actividad turística - Alto Mayo (29/10/2026); Laguna Azul (30/10/2026)`
- **Con texto** (cuando el cliente quiere algo simple, por ejemplo "Tours en vacaciones al Alto
  Mayo y Sauce"): sale el texto en lugar de la lista.
- **Retail no cambia:** en una venta normal `descripcion_detalle` está vacío y sale solo el
  título del producto, como siempre.

## 3. El recorrido

```
 Facturar / Facturación especial   (campo "Texto para este comprobante")
     ▼
 ReservaFacturacionController::construirLineas()
     texto del vendedor  →  descripcion_detalle = el texto
     sin texto           →  descripcion_detalle = "Alto Mayo (29/10/2026); Laguna Azul (30/10/2026)"
     ▼
 tabla sale_details (una fila por línea del comprobante)
     ▼                                         ▼
 PDF (pdf/a4, pdf/ticket80mm)             XML a SUNAT (GreenterService)
   título del producto                      ->setDescripcion($detalle->descripcionParaSunat())
   + detalle debajo, en letra chica            "título - detalle" (máx. 500 caracteres)
```

## 4. Las funciones

- **`SaleDetail::descripcionParaSunat()`**: **la regla vive en un solo lugar**. Sin detalle
  devuelve el título del producto; con detalle, `título - detalle`, recortado a 500 caracteres
  (lo que acepta SUNAT en la descripción de una línea). El PDF muestra lo mismo en dos renglones,
  así lo impreso coincide con lo que recibe SUNAT.
- **`construirLineas()`** (cambiada): si el vendedor escribió un texto (`filled()`, que no cuenta
  los espacios en blanco), usa el texto; si no, recorre los servicios de la línea **ordenados por
  fecha** (`sortBy`), arma `nombre (dd/mm/aaaa)`, quita repetidos (`unique()`) y los une con `; `.
- **`nombreEnComprobante()`** (nueva): el nombre que ve el cliente. Un tour lleva su nombre tal
  cual: el nombre interno de un tour con IGV mezclado agrega " (exonerado)", que en la factura
  sobra porque cada línea ya dice su IGV. El resto usa el nombre "para el cliente" (sin datos
  internos del mayorista), igual que el PDF de la cotización.

## 5. Cosas a saber

- **El texto del vendedor es uno por comprobante:** si la factura tiene dos líneas (una
  exonerada y otra gravada), el texto sale en las dos.
- **Comprobantes viejos:** el detalle de las ventas creadas antes de este cambio quedó guardado
  con el formato anterior (fecha `2026-08-30`, a veces con "(exonerado)"), y ese es el que
  muestran. Las que **ya se enviaron a SUNAT** mandaron en su XML solo el título, así que su PDF
  ahora dice algo más que su XML. Solo pasa en `agencia-demo` (datos de prueba): ningún tenant de
  producción factura reservas todavía.
- **El orden importaba también para los tests:** sin `sortBy`, la lista salía en el orden en que
  llegaban los servicios, que no siempre era el mismo, y el test fallaba "a veces". Ordenar por
  fecha lo deja estable y además es más claro para el cliente.

## 6. Tests

En `Fila13Fase1cFacturacionTest.php`:
- `test_el_comprobante_lista_los_servicios_o_el_texto_del_vendedor`: dos tours; la factura sin
  texto dice `Alto Mayo (fecha); Laguna Azul (fecha)`; la otra, con texto, dice el texto; y
  `descripcionParaSunat()` es `título - detalle`.
- `test_sin_detalle_la_descripcion_para_sunat_es_el_titulo`: retail sale igual; un detalle de 600
  caracteres se recorta a 500.

Verificado también generando el PDF A4 real de una venta de `agencia-demo`.

## 7. Ejercicios

1. Abre `descripcionParaSunat()`. ¿Qué devuelve para una venta de `umbo` (retail)? ¿Por qué no
   hace falta preguntar el giro del tenant?
2. ¿Por qué la regla está en el modelo `SaleDetail` y no copiada en el PDF y en `GreenterService`?
3. En `construirLineas()`, cambia mentalmente `filled($textoPersonalizado)` por
   `$textoPersonalizado !== null`. ¿Qué pasaría si el vendedor escribe solo espacios?
4. Una factura tiene tres servicios del mismo día. ¿En qué orden salen? (Pista: mira el segundo
   valor del `sortBy`.)
