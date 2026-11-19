# Recorrido: la pantalla de venta retail (caja en dos columnas)

19-nov-2026, tarea **T-106**. Maqueta aprobada por el usuario el 18-nov:
`docs/maquetas/venta-retail.html`. Construida por Sonnet, revisada y corregida por Opus.
Archivos:
- `admin-start-kit/src/views/sale/register.vue` — ahora es el **orquestador** (de ~2.170 a ~1.490 líneas)
- `admin-start-kit/src/components/Sales/` (nuevos): `BuscadorProductosVenta.vue`, `TablaLineasVenta.vue`,
  `PanelClienteComprobante.vue`, `DatosVentaPanel.vue`, `TotalesVenta.vue`, `AdelantosVenta.vue`,
  `CobroContado.vue`, `CobroCredito.vue`, `AvisoVenta.vue`
- `admin-start-kit/src/helpers/ventaRetail.ts` (nuevo: reglas puras) y `ventaRetail.test.ts` (17 tests)
- `admin-start-kit/src/composables/usePrintComprobante.ts` (`imprimirDirecto()`, `verPdfComprobante()`)
- `ProductFormQuick.vue` (prop `initialData`)

**Al desplegar:** solo frontend (`npm run build`). Sin migraciones ni cambios de backend.

---

## 1. La idea

Antes: cuatro tarjetas apiladas, con lo raro primero (retención, detracción, destino) y el cobro al
fondo. Ahora es una pantalla de caja: **productos a la izquierda, cobro fijo a la derecha**.

Lo más importante para mantenerla: **solo cambió la presentación**. El JSON que se manda a
`POST sales` es el mismo de antes y todos los cálculos (IGV, ISC, ICBPER, exoneración Amazonía,
retención, detracción, percepción, exportación, adelantos, cuotas) siguen siendo las mismas funciones
de `register.vue`, sin tocar. Se comprobó en la revisión comparando la versión vieja con la nueva
función por función: de 105 funciones comunes, ninguna de cálculo cambió.

## 2. Quién hace qué

```
register.vue  (orquestador: guarda el estado, calcula, arma el payload y llama a store())
├── DatosVentaPanel      «Datos de la venta ▾»: moneda, fecha, sucursal, destino, condición especial, código de detracción
├── BuscadorProductosVenta  F2 enfoca; flechas + Enter agregan; «+ Crear producto o servicio»
├── TablaLineasVenta     cantidad − / +, precio (marca «Precio manual»), afectación IGV por línea, descuento, quitar
├── PanelClienteComprobante  cliente, tipo de comprobante y serie, y la dirección (SelectorDireccionComprobante, T-107)
├── TotalesVenta         solo las filas que aplican y el total grande
├── AdelantosVenta       solo con condición especial «anticipo»
├── CobroContado / CobroCredito
└── AvisoVenta           avisos con acción («Deshacer», «Reimprimir», «Ver PDF A4»)
```

Los componentes **no calculan**: reciben y devuelven datos por `v-model` a las mismas variables que
usaba la pantalla vieja (`currency`, `condicion_especial`, `codigo_detraccion_sel`, `sale_payments`,
`cronograma`…). Por eso los cálculos de siempre siguen funcionando igual.

## 3. Las reglas nuevas (todas en `helpers/ventaRetail.ts`, con tests)

| Regla | Función |
|---|---|
| Vuelto y «Falta» del efectivo; lo que se envía es lo que cubre la venta, **no** lo recibido | `calcularEfectivo()` |
| Atajos de billetes (siguiente 10/20/50/100/200 que cubre, máx. 4) | `atajosEfectivo()` |
| Precio según el cliente (empresa → `price_company` si tiene; si no, general) | `precioCatalogoPorCliente()` |
| Qué unidades admiten decimales (KGM, litros…) | `admiteDecimales()` |
| Un producto controla stock si es un bien y no es «venta sin stock» | `controlaStock()` |
| Por qué el botón Cobrar está deshabilitado | `motivoBloqueoCobro()` |

### 3.1 El botón Cobrar

`motivoBloqueoCobro()` devuelve el **primer** motivo que aplica (vacío = se puede cobrar). En orden:
sin productos → sin stock → sin cliente → factura sin RUC → boleta > S/ 700 sin DNI → crédito sin
cliente identificado → detracción sin código → adelantos que superan el total → caja cerrada (solo si
hay que cobrar ahora) → dos pagos en efectivo → **pagos que superan el total** → (crédito) cronograma,
cuotas que no calzan, mora sin tasa → (contado) falta dinero.

«Pagos que superan el total» se agregó en la revisión: la pantalla vieja lo impedía al agregar un pago y
el backend lo rechaza con 422 (`CobroVentaService`); sin la regla, el botón se habilitaba y el error
llegaba recién al guardar.

### 3.2 Precio por cliente

Al elegir o quitar el cliente, `recalcularPreciosPorCliente()` reprecia las líneas **sin** precio puesto
a mano y avisa cuántas cambiaron. Una línea queda «manual» si se escribe el precio en la tabla, si se
elige General/Empresa con el ▾, o si viene de una cotización comercial. `updateItem()` recalcula la
línea y los totales.

### 3.3 El cobro al contado

`CobroContado.vue` arma `sale_payments` con el mismo formato que el `addPayment()` de antes
(`method_payment`, `amount`, `date_payment`, `nro_operacion`):
- un método principal (botones); en efectivo, «¿Con cuánto paga?» y el vuelto; en los demás, el n.º de
  operación;
- «+ Pagar con dos métodos» agrega pagos extra con su método, monto y, si no es efectivo, **su n.º de
  operación** (corregido en la revisión: antes iba siempre vacío). El método principal cubre lo que
  falta después de los extras.

## 4. Al guardar

`store()` arma el mismo payload de siempre (quita `uid` y `precio_manual`, que son solo de pantalla).
Si sale bien: imprime directo en el formato por defecto del usuario, limpia la pantalla, enfoca el
buscador y muestra «✓ Boleta B001 guardada, cobrado S/ …» con «Reimprimir» y «Ver PDF A4». Muestra la
**serie**, no el número: el correlativo de un comprobante fiscal se asigna al enviarlo a SUNAT.

## 5. Qué NO cambió (y queda para otras tareas)

- «Clientes varios» no existe: la venta sigue pidiendo un cliente (decisión del usuario). → «Por analizar».
- El destino por defecto sigue fijo en `amazonia` en el front: no hay dónde configurarlo. → «Por analizar».
- `edit.vue` sigue siendo la pantalla vieja → **T-110** (usará estos componentes).
- Descripción editable de la línea → T-108. Permiso para cambiar precio y validaciones en el backend → T-109.

## 6. Para probarlo

```
cd admin-start-kit && npx vitest run src/helpers/ventaRetail.test.ts
```

A mano: boleta contado (vuelto con «¿con cuánto paga?»), pago con dos métodos (Yape con n.º de
operación), factura a un cliente con RUC, crédito con cuotas, una venta con detracción y otra con
adelanto. Cambiar de cliente con productos cargados y ver el aviso de precios.
