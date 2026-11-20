# Recorrido: los totales de la venta se validan en el backend contra sus líneas

20-nov-2026, tarea **T-114**. Construida por Sonnet, revisada por Opus, probada en pantalla con un
script de Playwright (9 regímenes + una edición). Archivos:
- `api-sistema-fe/app/Services/Ventas/TotalesVentaValidator.php`: `validar()`, `calcular()`, `regimen()`
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php`: `validarTotalesDeVenta()`, llamado en
  `store()` y `update()`
- Referencia (solo lectura): `admin-start-kit/src/views/sale/register.vue` → `sumDetails()` y el armado
  de `data` en `store()`
- Tests: `tests/Feature/Ventas/T114TotalesVentaTest.php` (20) y el trait
  `tests/Feature/Ventas/Concerns/CabeceraDePantalla.php`

**Al desplegar:** nada especial. Ojo: cualquier cliente que no sea la pantalla y mande la cabecera
incompleta (sin `mto_oper_*`, `valor_venta`, `mto_imp_venta`…) recibe 422.

---

## 1. El problema

La pantalla calcula la **cabecera** de la venta (subtotal, IGV, total, operaciones por afectación,
retención…) y el backend la guardaba tal cual. T-109 ya revisaba cada **línea** (sin el permiso de
precio), pero con un request armado a mano las líneas podían cuadrar y la cabecera decir menos. Y la
caja, el PDF y el XML de SUNAT usan la cabecera, no las líneas.

## 2. La idea: recalcular y comparar, no reemplazar

```
store() / update()
├─ validarPrecioDeVenta()     (T-109: cada línea, solo sin el permiso)
├─ validarTotalesDeVenta()    (T-114: la cabecera, SIEMPRE)  ← antes de DB::beginTransaction()
└─ transacción: guarda la cabecera TAL COMO LLEGÓ (ya comprobada)
```

El validador rehace la cuenta desde las líneas del payload y, si un total difiere más que la
tolerancia, responde 422. **No** guarda sus propios números: primero validar, para no cambiar
documentos que hoy cuadran (guardar lo calculado sería otra tarea).

El permiso `cambiar_precio_venta` deja cambiar precios; no deja mandar totales que no salen de las
líneas. Por eso corre con y sin permiso.

## 3. La cuenta (la misma de `sumDetails()`)

```
líneas de cobro = todas menos las gratuitas (11)
bruto           = Σ subtotal de las de cobro
neto(línea)     = subtotal − descuento_global × (subtotal / bruto)     ← prorrateo
IGV             = Σ neto × porcentaje_igv
ISC             = porcentual ('01','03'): sobre el neto; específico ('02'): tal cual
ICBPER          = tal cual (monto por bolsa, no se prorratea)
mto_oper_10/20/30/40 = Σ neto por afectación;  gratuitas = Σ subtotal de las 11
base            = (subtotal − descuento_global) + IGV + ICBPER + ISC
régimen         = base × tasa si base > mínimo (S/ 700 o el del código de detracción)
total           = base + percepción − retención − detracción
mto_imp_venta   = base redondeada a 0,1;  redondeo = la diferencia
```

Todo en `BigDecimal` (nada de `float`). Tolerancia de 1 céntimo (2 en el total), porque la pantalla
suma en coma flotante.

## 4. Las tasas: ¿quién manda?

La pantalla manda `porcentaje_retencion`, `porcentaje_percepcion` y `porcentaje_detraccion`. El
validador **no se fía** del número: comprueba que sea una tasa vigente (`tax_configs` para
retención/percepción, el código activo de `detraction_codes` para detracción) y recién ahí la usa.
Sin configuración, 3 % y 2 %, igual que la pantalla.

## 5. Sin fallback silencioso

Si llega una afectación que la pantalla no genera (fuera de 10/11/17/20/30/40), el validador no
puede reproducir su `mto_oper_*` y responde 422 en vez de dejarla pasar. Un campo ausente cuenta
como 0 (es lo que `store()` guardaría con `?? 0`), así que una cabecera incompleta se rechaza.

## 6. Cómo se probó en pantalla

Un script de `playwright-core` armó cada venta en `register.vue` (cliente → comprobante → datos de
la venta → productos; ese orden importa: cambiar el comprobante llama a `resetCamposFiscales()`) y
capturó el `POST sales` real sin dejarlo guardar. Cada envío se pasó por el validador: los 9 pasan;
con el total −1 o el IGV −0,50, todos dan 422. Una venta se guardó y se editó de verdad (200 y 200).

## 7. Para practicar

1. ¿Por qué el validador corre **antes** de `DB::beginTransaction()`? Mira el `catch` de `store()`
   y piensa qué pasaría con el correlativo si el 422 saliera a mitad de la transacción.
2. En `calcular()`, cambia mentalmente una línea a gratuita (11): ¿en qué totales deja de sumar y en
   cuál empieza?
3. ¿Por qué `mto_imp_venta` tiene una tolerancia de 0,06 y no de 0,01? (pista: el redondeo a 0,1 de
   un `…,x5` exacto puede caer distinto en JavaScript y en PHP).

**Relacionado:** recorridos 74 (precio de venta, T-109), 76 (stock con lock, T-113).
