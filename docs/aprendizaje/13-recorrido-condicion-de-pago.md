# Recorrido: condición de pago (contado o crédito) al facturar una reserva y en el PDF

24-oct-2026, fila **F5** de `docs/planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md`.

---

## 1. El problema

- La factura de una reserva salía **siempre al contado**, aunque el cliente pagara días después.
  Ante SUNAT, "contado" significa "paga al emitir". Si paga después, lo correcto es **crédito con
  cuotas**: sus vencimientos viajan en el XML, las cuentas por cobrar quedan con fecha real y la
  factura se puede negociar (factoring).
- El PDF **no mostraba la condición de pago en ningún comprobante**, de ningún giro, aunque el XML
  sí la llevaba.

**Cómo trabaja la agencia (respuesta del usuario):** a veces factura cuando el cliente ya pagó y a
veces antes, cobrando después. A crédito, **varias cuotas si el monto es fuerte; una sola cuota con
fecha elegida en el calendario si no**.

## 2. Archivos

| Archivo | Nuevo o cambiado | Qué hace |
|---|---|---|
| `api-sistema-fe/app/Services/CronogramaCreditoValidator.php` | **nuevo** | La regla de un cronograma de cuotas, compartida por Ventas y Reservas |
| `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` | cambiado | usa el validador; bloquea editar una venta de reserva; carga la reserva de origen en el listado; arregla eliminar en retail |
| `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php` | cambiado | acepta `condicion_pago` y `cronograma`; nueva `anticiposQueSeAplican()`; la vista previa devuelve `saldo_a_cobrar` |
| `api-sistema-fe/app/Models/Sale/Sale.php` | cambiado | nuevas `cuotasVigentes()` y `reservaVenta()` |
| `api-sistema-fe/app/Models/AgenciaViajes/ReservaVenta.php` | cambiado | nueva `hayReservasEnEsteTenant()` |
| `api-sistema-fe/app/Http/Controllers/Greenter/GreenterService.php` | cambiado | las cuotas del XML salen de `Sale::cuotasVigentes()` |
| `api-sistema-fe/resources/views/pdf/a4.blade.php` y `ticket80mm.blade.php` | cambiado | condición de pago, cuotas y vencimiento |
| `api-sistema-fe/app/Http/Resources/Sale/SaleResource.php` | cambiado | `reserva_origen` en el listado de Ventas |
| `admin-start-kit/src/components/CronogramaCuotasCampos.vue` | **nuevo** | el bloque de cuotas (una o varias), usado por Ventas y Reservas |
| `admin-start-kit/src/components/AgenciaViajes/CondicionPagoCampos.vue` | **nuevo** | selector Contado / Crédito con sus ayudas |
| `admin-start-kit/src/helpers/condicionPago.ts` | **nuevo** | validar y armar el envío de la condición |
| `admin-start-kit/src/views/sale/register.vue` y `edit.vue` | cambiado | usan `CronogramaCuotasCampos` (antes tenían el bloque copiado) |
| `admin-start-kit/src/views/sale/index.vue` | cambiado | marca "De reserva …" y Editar bloqueado |
| `FacturarReservaSimpleModal.vue`, `FacturarReservaEspecialModal.vue` | cambiado | condición de pago |

## 3. El recorrido de una factura a crédito

```
 Modal de facturar  →  CondicionPagoCampos (Contado | Crédito)
                         └ CronogramaCuotasCampos (una cuota con su fecha, o varias)
     │  GET preparar-factura?client_id=…&advance_applications=…
     ▼
 ReservaFacturacionController::prepararFactura()
     anticiposQueSeAplican()  →  'saldo_a_cobrar' = total − anticipos   (lo que hay que financiar)
     │
     │  POST facturar { condicion_pago: 'credito', cronograma: [...] }
     ▼
 ReservaFacturacionController::store()   (todo dentro de una transacción con la reserva bloqueada)
     crea la venta → aplica anticipos (anticiposQueSeAplican(), la misma regla)
     CronogramaCreditoValidator::validar('cuotas_fijas', cronograma, saldo)   ← suma exacta al céntimo
     venta: type_payment=2, condicion_pago='credito', credit_type='cuotas_fijas'
     installments (una fila por cuota, estado 'pendiente')
     ▼
 Enviar a SUNAT: GreenterService → FormaPagoCredito + cuotas (de Sale::cuotasVigentes())
 PDF: CONDICIÓN DE PAGO: CRÉDITO + cuotas + vencimiento (de Sale::cuotasVigentes())
 Cobro: Cuentas por Cobrar (módulo Amortizaciones)
```

## 4. Las funciones, una por una

**Backend**
- **`CronogramaCreditoValidator::validar($creditType, $cronograma, $saldo, …)`**: solo
  `cuotas_fijas`; cada cuota con número sin repetir, monto mayor a 0 y fecha; la **suma calza al
  céntimo** con el saldo (se compara en céntimos enteros para no tener problemas de decimales); con
  mora, tasa y tipo válidos. Antes era privada de `SaleController`; ahora la usan los dos.
- **`anticiposQueSeAplican($reserva, $clienteId, $moneda, $total, $solicitadas)`**: qué anticipos
  se descuentan (solo del mismo cliente y moneda). Si el vendedor marcó algunos, los valida; si no,
  aplica lo disponible hasta cubrir el total. Salió de `store()` para que la vista previa calcule
  **exactamente el mismo saldo** que la emisión.
- **`Sale::cuotasVigentes()`**: las cuotas no anuladas, en orden. **Una sola fuente** para el XML y
  el PDF, así nunca dicen cosas distintas.
- **`ReservaVenta::hayReservasEnEsteTenant()`**: ¿existe la tabla `reserva_ventas`? Solo en
  Agencia. Ver la sección 6.

**Frontend**
- **`CronogramaCuotasCampos.vue`**: con `permitirUnaCuota` (reservas) muestra "Una cuota" (todo el
  saldo, fecha en el calendario) o "Varias cuotas" (número + periodicidad + "Generar cronograma
  sugerido", tabla editable). Ventas lo usa solo en "varias", como siempre. Usa `defineModel`: el
  cronograma vive en la pantalla que lo usa y el componente lo edita (`v-model`).
- **`CondicionPagoCampos.vue`**: el selector. Si los anticipos cubren todo, Crédito queda
  deshabilitado ("va al contado"). Si queda saldo y está en Contado, avisa: *"el cliente paga hoy
  S/ X; si pagará después, elige Crédito"*.
- **`validarCondicionPago()`** (`helpers/condicionPago.ts`): la misma regla que el backend, para
  avisar antes de enviar.

## 5. Ventas: una venta de reserva no se edita

La reserva guarda qué servicios, qué pasajeros y qué parte de cada tour cobró cada venta
(`reserva_ventas`, `sale_detail_items`). Si en Ventas se cambiaban montos, líneas o la condición de
pago, la reserva no se enteraba y quedaba descuadrada. Ahora:
- `SaleController::update()` la rechaza: *"Esta venta salió de la reserva RDKM-…. Para corregirla,
  elimínala (mientras no se haya enviado a SUNAT) y vuelve a facturar desde la reserva."*
- El listado de Ventas muestra **"De reserva RDKM-…"** y el botón Editar explica lo mismo.
- Eliminarla sí funciona: libera los servicios y pasajeros en la reserva.

## 6. Un error que ya estaba en producción (encontrado aquí)

`reserva_ventas` es una tabla de **Agencia**: no existe en retail ni en créditos. Desde el
**24-ago-2026**, dos lugares del sistema base la consultaban siempre:
- **eliminar una venta** (`SaleController::destroy()`) → en retail fallaba con *"no existe la
  relación reserva_ventas"*;
- **aceptar una nota de crédito** (`NotaElectronicaController`, al liberar la reserva) → la nota
  quedaba aceptada, pero la respuesta daba error 500 y lo que seguía (por ejemplo, el saldo de un
  adelanto reembolsado) no se actualizaba.

La protección que agregué en Ventas habría roto también la edición en retail. Por eso nació
`ReservaVenta::hayReservasEnEsteTenant()`, y los tres lugares preguntan antes.

> **Regla:** todo código del sistema base que toque una tabla de un vertical pregunta antes si el
> tenant la tiene.

## 7. Dos errores encontrados al probar en el navegador

- **Las casillas de ayer no sacaban un servicio suelto:** desde la pantalla los ids llegan por la
  URL como **texto** (`"35"`) y `separarExcluidos()` los comparaba en modo estricto contra números.
  Con los tours funcionaba por otro camino. Ahora se pasan a número (`array_map('intval', …)`), y lo
  mismo con el id del anticipo marcado. Los tests no lo veían porque mandaban números: el test nuevo
  manda texto, como la pantalla.
- **El ticket mostraba código:** `@php(...)` con paréntesis anidados confunde a Blade, que deja de
  interpretar el resto de la plantilla. Se usa `@php … @endphp`. Un test dibuja ahora las dos
  plantillas y falla si aparece código sin interpretar.

## 8. Tests

- `Fila13Fase1cFacturacionTest`: factura a crédito guarda sus cuotas (y Greenter las ve); cuotas que
  no calzan se rechazan sin crear nada; contado por defecto; una venta de reserva no se edita desde
  Ventas.
- `ReservaAnticipoTest`: la vista previa dice el saldo a cobrar (con anticipo, con otro cliente, con
  ids como texto) y un servicio desmarcado con id como texto sale.
- `SistemaBaseSinReservasTest` (nuevo): un tenant sin la tabla puede eliminar ventas y aceptar notas
  de crédito. Simula retail borrando la tabla dentro de la transacción del test.
- `ComprobantePdfCondicionPagoTest` (nuevo): A4 y ticket al contado y a crédito (cuotas, último
  vencimiento, cuota anulada que no sale) y sin código sin interpretar.

En el navegador (sin emitir): reserva 16 con un servicio de la Amazonía desmarcado → aparece
"Condición de pago"; una cuota propone el saldo (PEN 210.00); varias cuotas genera 3 × 70 que calzan.
Registrar venta (Ventas) sigue mostrando su cronograma y su mora.

## 9. Ejercicios

1. Una factura de S/ 300 con un anticipo de S/ 100 del mismo cliente, a crédito en 2 cuotas. ¿Cuánto
   tiene que sumar el cronograma? ¿Dónde se calcula ese número antes de emitir?
2. ¿Por qué `CronogramaCreditoValidator` compara en céntimos enteros (`round($monto * 100)`) y no
   los montos tal cual?
3. Abre `Sale::cuotasVigentes()`. Si se anula la cuota 2 de 3, ¿qué muestran el PDF y el XML?
4. En un tenant de retail, ¿qué devuelve `ReservaVenta::hayReservasEnEsteTenant()`? ¿Qué pasaba
   antes, sin esa pregunta, al eliminar una venta?
5. En `separarExcluidos()`, ¿por qué `whereIn` funcionaba con texto y `in_array(..., true)` no?
