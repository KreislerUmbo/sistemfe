# Recorrido: Venta directa con total en vivo, modo de precio a elegir y fecha obligatoria

04-nov-2026, tarea **T-045** (obs. 12, H42 y H44).

---

## 1. El problema

En **Venta directa** el vendedor arma un servicio suelto y aprieta «Crear venta directa». Había
dos trampas:

| | Qué pasaba |
|---|---|
| **H42** | El modo de precio venía en «Tarifa fija». Una entrada de S/ 30 **por persona** para 4 pasajeros, con cantidad 1, cobraba **una sola** entrada (S/ 30 en vez de S/ 120). Y el monto recién se veía en la reserva, cuando ya estaba creada. |
| **H44** | La fecha era opcional. Sin fecha, el servicio no sale en el Reporte operativo. |

Ahora:

- La pantalla muestra **las dos opciones con su total**, y no se puede crear sin elegir una:
  «Por persona (4 pasajeros): S/ 120.00» y «Por unidad (cantidad 1): S/ 30.00».
- «Por persona» trae el detalle («2 adultos × 30 + 2 niños × 0»).
- El botón dice «Crear venta directa · S/ 120.00».
- La fecha es obligatoria y empieza en **hoy, en hora de Perú**, con los botones «Hoy» y «Mañana».

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `app/Services/AgenciaViajes/PriceEngineService.php` | `ventaYCostoDeTarifa()`, `totalDeLinea()`, `motivoTarifaNoUsable()` (nuevas) |
| `app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` | `crearItemProveedor()` ahora usa esas funciones |
| `app/Http/Controllers/AgenciaViajes/VentaDirectaController.php` | `vistaPrevia()` (nueva); `store()` con `fecha_servicio` obligatoria |
| `routes/api.php` | `POST venta-directa/vista-previa` con `permission:reservas.crear` |
| `admin-start-kit/src/views/agencia-viajes/venta-directa.vue` | tarjetas de modo, total en vivo, fecha |
| `admin-start-kit/src/services/admin/ventaDirectaService.ts` | `vistaPrevia()` y sus tipos |
| `tests/Feature/AgenciaViajes/VentaDirectaVistaPreviaTest.php` | 5 tests nuevos |

Tablas: ninguna nueva. La vista previa **no escribe nada**.

## 3. El recorrido

### 3.1 ¿Por qué un endpoint y no calcular en Vue?

Para cobrar «por persona» hay que saber cuántos pasajeros son adultos, niños o infantes. Eso lo
decide la **edad**, contra los límites que la agencia configura (`edad_max_infante`,
`edad_max_nino` en `ConfiguracionAgencia`). Pero `GET configuracion-agencia` pide el permiso
`agencia.configuracion`, y un vendedor normalmente no lo tiene.

Se podía abrir ese dato y repetir la fórmula en TypeScript, pero entonces la regla viviría en dos
lugares. **La misma lección del recorrido 36:** dos copias de una regla terminan separándose.
Por eso el total lo calcula el backend, con la misma función que usa la creación.

### 3.2 La fórmula, en un solo lugar

Antes, la cuenta estaba escrita adentro de `crearItemProveedor()`. Ahora vive en el motor de
precios:

```php
public function ventaYCostoDeTarifa(ProveedorTarifa $tarifa, array $conteo, string $modoPrecio): array
{
    $costo = (float) $tarifa->precio_costo;

    if ($modoPrecio === 'por_persona') {
        return [
            'venta' => $conteo['adulto'] * (float) $tarifa->precio_venta_adulto
                + $conteo['nino'] * (float) ($tarifa->precio_venta_nino ?? 0)
                + $conteo['infante'] * (float) ($tarifa->precio_venta_infante ?? 0),
            'costo' => round($costo * ($conteo['adulto'] + $conteo['nino']), 2),
        ];
    }

    return ['venta' => (float) $tarifa->precio_venta_adulto, 'costo' => $costo];
}
```

- `$conteo` es `['adulto' => 2, 'nino' => 1, 'infante' => 1]`.
- `?? 0`: si la tarifa no tiene precio de niño o de infante, ese pasajero vale **0**. T-045 no lo
  cambia (es la regla de todo el sistema); solo lo **muestra** en el detalle. Quedó en «Por
  analizar» del backlog.
- El costo por persona no cuenta infantes (decisión E2 de la fila 13).

`crearItemProveedor()` (cotizador y venta directa al crear) y `vistaPrevia()` (venta directa antes
de crear) llaman a esta misma función. El chequeo «tarifa retirada / fuera de vigencia» también se
movió (`motivoTarifaNoUsable()`), para que los dos respondan el mismo 422.

### 3.3 ¿Y la cantidad?

El total de una línea lo da `AlternativaItem::getTotalConvertidoAttribute()`:

```php
if ($this->modo_precio === 'tarifa_fija') {
    return $precio * $this->cantidad;
}
return $precio;   // por persona: la cantidad no cuenta
```

Por eso, en la pantalla la cantidad está **dentro** de la tarjeta «Por unidad», y al crear «por
persona» se envía `cantidad = 1`: así no queda guardado un número que no significa nada.

### 3.4 La vista previa

`VentaDirectaController::vistaPrevia()` recibe `pax`, `proveedor_tarifa_id` y `cantidad`:

1. Valida, y si la tarifa no se puede usar responde 422 con `motivoTarifaNoUsable()`.
2. Cuenta los pasajeros por tipo con `umbralesEdad()` y `derivarTipoPax()`, **los mismos** que usa
   `store()` para crear los `cotizacion_pasajeros`.
3. Calcula las dos opciones con `ventaYCostoDeTarifa()` y `totalDeLinea()`.
4. Devuelve `moneda`, `por_persona { total, desglose }` y `tarifa_fija { total, precio_unitario, cantidad }`.

No crea cotización, no pide número correlativo ni bloquea filas. Se puede llamar en cada cambio sin
molestar a nadie.

**Moneda:** la alternativa de una venta directa toma la moneda de la tarifa, así que no hay
conversión. Una tarifa en dólares se muestra «US$», no «S/».

### 3.5 La pantalla: que lo que se ve sea lo que se cobra

En `venta-directa.vue`:

- **Debounce:** `pedirVistaPrevia()` espera 300 ms después del último cambio (tarifa, cantidad,
  pasajeros) antes de llamar al backend.
- **Respuestas viejas:** cada pedido lleva un número (`vistaPreviaSeq`). Si llega la respuesta de
  un pedido que ya no es el último, se descarta.
- **Total desactualizado** (lo que encontró la revisión): mientras hay un cálculo pendiente,
  `calculando` está en `true`. `totalesAlDia` es falso, los montos muestran «…» y
  `totalPendiente` deshabilita «Crear». Antes, si el vendedor pasaba de 4 a 5 pasajeros y
  apretaba «Crear» al instante, el botón decía S/ 120 y se creaba por S/ 150.
- **Sin spinner eterno:** `motivoSinCalculo` explica por qué no hay total («Agrega al menos un
  pasajero…», «Revisa las edades…», «La cantidad debe ser 1 o más.»). Con una cantidad inválida,
  las tarjetas siguen a la vista para poder corregirla.
- **Al cambiar de tarifa:** el modo vuelve a «sin elegir» y la cantidad a 1.
- **Concepto libre:** el total es `precio × cantidad` y se calcula en Vue (no hay pasajeros que
  clasificar ni conversión).

### 3.6 La fecha

- Backend: `'fecha_servicio' => 'required|date'`. Se acepta una fecha pasada, para cargar una venta
  que se registró tarde.
- Frontend: `ref(hoyPeru())`. **Nunca** `new Date().toISOString().slice(0, 10)`: de 19:00 a 24:00
  en Perú eso ya da el día siguiente (UTC). «Mañana» es `sumarDiasISO(hoyPeru(), 1)`.

## 4. Cómo probarlo

```
php artisan test --filter=VentaDirecta
```

El test clave es `test_el_total_de_la_vista_previa_coincide_con_el_de_la_venta_creada`: pide la
vista previa, crea la venta de verdad (por persona y por unidad, en PEN y en USD, con niño e
infante) y compara contra la suma de `total_convertido` de lo creado.

En agencia-demo: Agencia → Venta directa → elige una tarifa y agrega 4 adultos. Las dos tarjetas
muestran su total. Agrega un pasajero y mira el botón: pierde el total un instante y vuelve con el
nuevo.

## 5. Ejercicios

1. En §3.2, ¿por qué `crearItemProveedor()` le pasa un conteo de ceros a `ventaYCostoDeTarifa()`
   cuando el modo es «tarifa fija»? ¿Qué pasaría si le pasara el conteo real?
2. La cama adicional se quedó en `crearItemProveedor()` y no entró en `ventaYCostoDeTarifa()`.
   ¿Qué necesita la cama adicional que la vista previa no tiene? (Pista: `aplicarCamaAdicional()`.)
3. Quita el `finally` de `pedirVistaPrevia()`. ¿En qué caso queda «Crear» deshabilitado para
   siempre?
4. `totalDeLinea()` repite la regla de `getTotalConvertidoAttribute()`. ¿Qué test se pondría rojo
   si alguien cambiara solo una de las dos?
5. Si mañana se decide que «niño sin precio = precio de adulto», ¿cuántos lugares hay que tocar?
   Búscalos con `grep "precio_venta_nino ?? 0"`.
