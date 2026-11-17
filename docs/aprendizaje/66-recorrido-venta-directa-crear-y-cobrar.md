# Recorrido: Venta directa «Crear y cobrar» y columna «Afectación»

16-nov-2026, tarea **T-048** (H45). Decisiones: **D-AGE-034** (alcance y cobro de Venta directa, sin nota
de venta), **D-AGE-035** (C4: gravado, exonerado e inafecto en un comprobante; C5: sin confirmar la
Amazonía), **D-AGE-036** (series solo por moneda) y **D-AGE-037** (la afectación se ve y se corrige por
línea, con el mismo componente que al facturar). Están en `docs/modulos/agencia/decisiones/`; acá no se
repiten. Recorridos previos que este supone: 38 (varias líneas), 41 (tours), 65 (comprobante mixto y
columna «Afectación» al facturar), 13 (condición de pago) y 18 (traspaso del cobro tras NC).

Archivos:
- `admin-start-kit/src/views/agencia-viajes/venta-directa.vue`
- `admin-start-kit/src/components/AgenciaViajes/AfectacionLineasFactura.vue` (prop generalizada a `FilaAfectacion`)
- `admin-start-kit/src/components/AgenciaViajes/CobroFacturaCampos.vue` (prop `compacto`)
- `admin-start-kit/src/services/admin/ventaDirectaService.ts`, `reservaFacturacionService.ts` (tipo `FilaAfectacion`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php` (`vistaPrevia()`, `resumenTributario()`, `conAfectacionElegida()`, `store()`, `facturarYCobrar()`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php` (`prepararFactura()` usa el bloque extraído; `store()` sin cambios)
- `api-sistema-fe/app/Services/Cobro/CobroVentaService.php` (`datosDelBloqueDeCobro()`, nuevo)
- Tests: `T048VentaDirectaCrearYCobrarTest` (20) y 3 nuevos en `VentaDirectaTourTest`

**Al desplegar:** nada (sin migraciones). «Crear y cobrar» aparece solo con `AGENCIA_COBRO_AL_FACTURAR`
encendido, como el cobro de T-011. Frontend y backend juntos: la pantalla nueva espera `editable`,
`cambiado`, `montos`, `resumen` y `cobro` en la vista previa.

---

## 1. La idea

**Antes**, una venta que se paga en el momento eran tres pantallas: crear la venta directa, ir a la reserva,
«Facturar» (y ahí cobrar). **Ahora** el resumen de Venta directa tiene dos botones:

| Botón | Qué hace |
|---|---|
| **Crear y cobrar** | Crea la reserva, emite **boleta o factura** de la reserva completa, al contado, y registra el cobro en caja. Todo o nada |
| **Crear sin cobrar** | Lo de siempre: crea la reserva; se factura y cobra después desde la reserva (crédito, transferencia que no llegó, comprobantes separados) |

Y cada línea muestra su **Afectación** (Gravado / Exonerado – Amazonía / Inafecto), que el vendedor puede
cambiar antes de crear, con la misma celda que los modales de facturar (recorrido 65).

```
Pantalla (venta-directa.vue)
  ├─ cada cambio → POST venta-directa/vista-previa → VentaDirectaController::vistaPrevia()
  │                  (montos por afectación, resumen tributario, bloque de cobro; no guarda nada)
  └─ «Emitir y cobrar» → POST venta-directa (cobrar: true) → VentaDirectaController::store()
        DB::transaction {
          cotización + alternativa + ítems + reserva      (como siempre, recorridos 38 y 41)
          reserva_items cambiados en la columna            (tip_afe_igv, destino, fecha_override_tributario)
          facturarYCobrar() → ReservaFacturacionController::store()
                                → sales, sale_details, reserva_ventas
                                → CobroVentaService::registrar() → sale_payments, cash_movements
        }
```

## 2. La pantalla: `venta-directa.vue`

### 2.1 Dos fases en el resumen: `'armar'` y `'cobro'`

`fase = ref<'armar' | 'cobro'>('armar')`. El panel derecho cambia según la fase:

- **`armar`**: el total, el resumen tributario y los botones.
  - Si `puedeCrearYCobrar`: **«Crear y cobrar · S/ X»** (pasa a `fase = 'cobro'`, no crea nada todavía) y
    **«Crear sin cobrar»** (llama a `crear()` sin datos de cobro), con la ayuda «a crédito, si el pago llega
    después…».
  - Si no: un solo botón con su texto de siempre, «Crear venta directa». «Crear sin cobrar» solo tiene
    sentido al lado de «Crear y cobrar».
- **`cobro`**: el panel «Cobro» de la maqueta (D-AGE-034):
  - Comprobante: botones **Boleta / Factura** **sin valor por defecto** (`tipoComprobante = null`), filtrados
    por permiso (`tiposPermitidos`: `emitir_boleta`, `emitir_factura`). Factura deshabilitada si el cliente no
    tiene RUC (`cod_tipo_doc_sunat === '6'`); si se cambia a un cliente sin RUC con Factura elegida, se limpia.
  - `CobroFacturaCampos` (el bloque de T-011, **el mismo componente**, con `compacto` para caber en la columna):
    formas de pago, moneda, n.º de operación, pago mixto, vuelto.
  - `motivoEmitir`: un solo motivo, el más urgente (falta un dato → exportación → sin comprobante → sin caja
    abierta). Mientras haya motivo, «Emitir y cobrar» está deshabilitado.
  - «Cancelar» (`cerrarCobro()`) vuelve a `armar` y limpia comprobante y pagos.

```ts
const puedeCrearYCobrar = computed(() => !!vistaPrevia.value?.cobro.cobro_habilitado
    && authStore.isPermitedRoute('reservas.editar') && tiposPermitidos.value.length > 0);
```

Las tres condiciones las **revalida el backend** (§3.3): la pantalla solo evita mostrar un botón que va a fallar.

### 2.2 `crearYCobrar()` y `crear()`

`crearYCobrar()` valida con los helpers de T-011 (`validarCobro()` de `helpers/cobroFactura.ts`) y arma los
pagos con `payloadCobro()`, igual que el modal de facturar. Después llama al mismo `crear()` que «Crear sin
cobrar», con un objeto extra: `{ cobrar: true, tipo_comprobante_codigo, pagos, vuelto_moneda }`. No hay dos
caminos de guardado en el frontend: «sin cobrar» es `crear()` con el objeto vacío.

Al terminar, la pantalla abre la **ficha de la reserva**, que ya tiene la venta en «Comprobantes emitidos» con
su botón de imprimir. El envío a SUNAT sigue manual (lo dice el toast).

### 2.3 La celda «Afectación» de cada línea

Venta directa no tiene tabla de líneas: la celda va **en la tarjeta de cada línea**, donde antes estaban las
etiquetas. `filaAfectacion(l, i)` arma lo que pide el componente:

```ts
{ nombre, tip_afe_igv /* o 'mixto' si los grupos del tour difieren */, editable: lp.editable, cambiado: lp.cambiado }
```

Para eso `AfectacionLineasFactura.vue` dejó de recibir el tipo `DetalleComprobantePreview` (propio de reservas)
y recibe el tipo mínimo **`FilaAfectacion`** (`nombre`, `tip_afe_igv`, `editable`, `cambiado`, `excluido?`). Las
filas de los modales de facturar ya cumplen ese tipo, así que ellos no cambiaron (CA13).

La diferencia con el recorrido 65 es **quién guarda**:

| | Modales de facturar (T-091) | Venta directa (T-048) |
|---|---|---|
| Al cambiar la lista | `useAfectacionFactura` llama a `override-tratamiento-tributario` y lo guarda en el `reserva_item` | `@cambiar` solo hace `l.afectacion = tip` |
| Por qué | la reserva ya existe | **la reserva todavía no existe**: no hay dónde guardar |
| Cómo llega al backend | — | como `lineas.*.tip_afe_igv` en cada vista previa y en el `store` |

`l.afectacion` entra en la firma del `watch` que pide la vista previa, así que cambiar la lista recalcula el
resumen. La `:key` de la celda incluye el `tip_afe_igv` que vino del backend: la lista se redibuja con lo que
el servidor aceptó, no con lo que se eligió.

La etiqueta **«Exportación»** se conserva al lado (`avisosAfectacion()`): la celda no la muestra y es lo único
que bloquea el cobro.

### 2.4 El resumen tributario

Bajo el total, `resumenTributario` (lo que trae la vista previa, solo con los totales al día): op. gravadas,
exoneradas, inafectas, IGV y «El IGV sale del precio: el cliente paga lo mismo», como en
`DetalleComprobantePreview.vue`.

## 3. El backend: `VentaDirectaController`

### 3.1 `vistaPrevia()`: montos, resumen y bloque de cobro

No guarda nada. Sobre lo que ya hacía (recorridos 37 y 38), cada línea trae tres campos nuevos y la respuesta dos:

| Campo | De dónde sale |
|---|---|
| `lineas[].editable` | `false` en un tour (sus grupos pueden tener afectaciones distintas; se corrige en el tour) |
| `lineas[].cambiado` | lo devuelve `conAfectacionElegida()` (§3.2) |
| `lineas[].montos` | lo que aporta la línea a cada afectación: una línea suelta, `[{tip, total}]`; un tour nuevo, un monto por grupo con el reparto de `TourLineaService::calcular()`; un tour clásico, `montosTourClasico()` (un monto por servicio + el ajuste de redondeo con el valor por defecto de la agencia) |
| `resumen` | `resumenTributario()`, o `null` mientras falte algún total |
| `cobro` | `CobroVentaService::datosDelBloqueDeCobro()` (§4) |

`resumenTributario()` usa el **mismo criterio** que los `mto_oper_*` de `ReservaFacturacionController::store()`:
el precio ya incluye el IGV; 20 va a exoneradas, 30 a inafectas y el resto a gravadas, y
`gravadas = con IGV / 1.18`. Diferencia conocida: la factura calcula el IGV **por línea** (por categoría y
afectación) y el resumen sobre el total gravado; con gravados de varias categorías puede haber un céntimo de
diferencia en el IGV. El total nunca difiere.

### 3.2 `conAfectacionElegida()`: la columna reemplaza el tratamiento

`resolverLineas()` resuelve cada línea como siempre (tarifa → `AlternativaItemController::resolverTratamientoTributario()`;
concepto libre → el valor por defecto de la agencia) y después la pasa por `conAfectacionElegida()`:

```
sin tip_afe_igv           → la línea como estaba, cambiado = false
tour con tip_afe_igv      → 422 «La afectación de un tour se corrige en el tour.»  (nunca se ignora en silencio)
igual a la que ya tenía   → cambiado = false   (elegir lo mismo no es un cambio)
otra                      → tratamiento = { tip_afe_igv, destino: 20 ⇒ 'amazonia', si no 'nacional' }, cambiado = true
```

El destino sale del código, como en `ReservaController::overrideTratamientoTributario()` (recorrido 65 §2.4;
en Agencia, exonerado = Amazonía). Así no puede nacer un «10 + amazonia». La validación es
`lineas.*.tip_afe_igv => nullable|in:10,20,30`.

Como lo usan `vistaPrevia()` y `store()` por igual, la pantalla y lo que se guarda no pueden diferir.

### 3.3 `store()`: todo en **una** transacción

Antes de abrir la transacción, con `cobrar = true`:

1. `AGENCIA_COBRO_AL_FACTURAR` apagado → 422 «…usa «Crear sin cobrar»» (CA9).
2. Sin `tipo_comprobante_codigo` → 422 (CA3). `in:01,03`: una nota de venta (`00`) → 422 (CA6, D-AGE-034).
3. Sin `reservas.editar` → 403. Facturar una reserva pide ese permiso **en la ruta**
   (`reservas/{id}/facturar`); acá se llama al controller directo, sin pasar por la ruta, así que se revisa a mano.

Dentro de `DB::transaction`:

1. Cotización (`origen = 'venta_directa'`), alternativa, ítems y reserva, como siempre (recorridos 38 y 41).
2. Por cada línea con `cambiado`, se recuerda `alternativa_item_id => tratamiento`. Al final, el
   `reserva_item` de esa línea queda con `tip_afe_igv`, `destino_tributario` y
   **`fecha_override_tributario = now()`** = «corregido a mano» (recorrido 65 §2.5): lo respetan
   `resolverTipAfeIgvItem()` al facturar y `repuntar()` al modificar la reserva. Esto pasa **con o sin cobrar**.
   Una línea sin cambiar queda sin fecha de override (CA12).
3. Con `cobrar`: `facturarYCobrar()` (§3.4).

### 3.4 `facturarYCobrar()`: reusar `ReservaFacturacionController::store()` entero

```php
$respuesta = app(ReservaFacturacionController::class)->store(Request::create('/', 'POST', $datosCobro + [
    'pasajero_ids'            => /* todos los pasajeros de la reserva */,
    'reserva_item_ids_manual' => /* servicios sin pasajero que no son tour */,
    'client_id'               => $clienteId,   // el cliente de la venta
    'condicion_pago'          => 'contado',
]), (string) $reserva->id);
```

Es exactamente lo que mandaría el modal «Facturar» con todo marcado. **No se extrajo un servicio de
facturación**: este controller ya reusa así `AlternativaItemController::store()` y
`ReservaController::crearReservaDesdeAlternativa()`, y llamar a `store()` trae todo sin copiar nada: el guardia
tributario (solo exportación bloquea, D-AGE-035), la serie con lock (`SerieComprobanteService`, sucursal + tipo +
moneda, D-AGE-036), el permiso de emisión del tipo, el RUC para factura, el lock de la reserva y el cobro con
`CobroVentaService`. Por eso los mensajes de error son **los mismos** que al facturar una reserva (CA5, CA8b).

### 3.5 Por qué un rechazo deshace todo

`DB::transaction()` hace rollback solo si **sale una excepción** del closure. Un `return response()->json(422)`
no es una excepción. Hay tres formas en que la facturación puede decir que no, y las tres terminan en excepción:

| Cómo rechaza `ReservaFacturacionController::store()` | Qué pasa |
|---|---|
| Devuelve un JSON 422 (validación, reserva no activa, guardia tributario de exportación) | `facturarYCobrar()` mira el status y **lanza** `HttpException` con el mismo mensaje |
| Lanza `HttpException`: antes de su transacción (sin permiso de emisión, factura sin RUC, «Abre tu caja para cobrar» de `sesionAbierta()`) o dentro (reserva no activa bajo lock, «falta S/ X» de `validarContraSaldo()`) | sube tal cual; su transacción interna es un *savepoint* de la nuestra y se deshace con ella |
| `$this->authorize('update', $reserva)` niega (`ReservaPolicy`) | lanza `AuthorizationException`, que **no** es una `HttpException` |

En los tres casos la excepción atraviesa el closure, `DB::transaction()` deshace **todo** (cotización, reserva,
venta, pagos, movimientos de caja) y vuelve a lanzarla. `store()` la atrapa afuera:

- `catch (HttpException $e)` → `{code, message}` con su status.
- `catch (AuthorizationException)` → 403 en español («No tienes permiso para facturar esta reserva…»). Sin este
  catch salía el 403 en inglés de Laravel (corregido en la revisión).

Los tests lo comprueban contando filas antes y después (`conteos()`: cotizaciones, reservas, ventas, pagos,
movimientos): CA3, CA4 (cobro que no cubre el total, sin caja), CA8b y los de permisos.

## 4. `CobroVentaService::datosDelBloqueDeCobro()`

Lo único extraído. Lo que una pantalla necesita para dibujar `CobroFacturaCampos`:

| Clave | Qué es |
|---|---|
| `cobro_habilitado` | `config('agencia.cobro_al_facturar')` |
| `caja_abierta` | el usuario tiene una `cash_sessions` abierta |
| `formas_pago` | `payment_methods` activos, en orden, con sus monedas y si cuentan en el arqueo |
| `tipo_cambio_dia` | T.C. venta SUNAT de hoy (hora Perú), **como número** |

Antes lo armaba `prepararFactura()` en línea; ahora lo comparten los modales de facturar y Venta directa. El
`(float)` del tipo de cambio se agregó en la revisión: la columna es decimal y Eloquent la entrega como texto
(«3.3600»), y Vue avisaba en consola.

## 5. Las tablas que toca

| Tabla | Qué queda | Quién la escribe |
|---|---|---|
| `cotizaciones` | una, `origen = 'venta_directa'` (fuera de los listados comerciales: `Cotizacion::delCotizador()`) | `store()` |
| `alternativas`, `alternativa_items` | la alternativa aceptada y una fila por línea (un tour, varias) | `store()` → `AlternativaItemController` |
| `reserva`, `reserva_pasajeros` | la reserva activa y sus pasajeros | `ReservaController::crearReservaDesdeAlternativa()` |
| `reserva_items` | un servicio por ítem; los cambiados en la columna con `tip_afe_igv`, `destino_tributario` y `fecha_override_tributario` | `store()` (bloque T-048) |
| `sales` (+ `sale_details`) | **solo con cobrar**: boleta o factura al contado, `state_payment` pagada, saldo 0, el cliente de la venta, `mto_oper_*` por afectación | `ReservaFacturacionController::store()` |
| `reserva_ventas` | el vínculo reserva ↔ venta (lo que lee `ReservaVenta::itemIdsFacturados()`) | ídem |
| `sale_payments` | un pago por fila del bloque, con `nro_operacion` | `CobroVentaService::guardarPagos()` |
| `cash_movements` | un `sale_payment` (entrada) por pago y, si hay vuelto, un `sale_change` (salida) en efectivo | `CobroVentaService::crearMovimientos()` / `registrar()` |

## 6. Cómo se probó

- `T048VentaDirectaCrearYCobrarTest` (20): CA1 (boleta, efectivo S/ 120: reserva, venta pagada, 1 pago,
  1 movimiento, `reserva_ventas`), CA2 (n.º de operación), CA3, CA4 (dos casos), CA5, factura con RUC, CA6,
  permisos (emisión, `reservas.editar`, Policy en español), CA7 (Amazonía sin confirmar, con leyenda), CA8 (un
  comprobante gravado + exonerado), CA8b, CA9, bloque de cobro en la vista previa, T.C. como número, CA12 (S/ 118
  a Inafecto → inafectas 118, IGV 0; `reserva_item` 30/nacional con fecha de override), elegir lo mismo no es
  cambio, «Crear sin cobrar» guarda la afectación, concepto libre, código inválido.
- `VentaDirectaTourTest` (+3): tour mezclado de solo lectura con su resumen; tour con `tip_afe_igv` → 422; resumen
  del modo clásico con ajuste de redondeo.
- En pantalla (agencia-demo, Playwright): CA10 de punta a punta (tour + traslado, Boleta + Yape, PDF revisado con
  Ghostscript), CA12 y CA13. El detalle está en el commit `b28660c`.

## 7. Si algo falla

| Síntoma | Dónde mirar |
|---|---|
| No aparece «Crear y cobrar» | `puedeCrearYCobrar`: `cobro.cobro_habilitado` (flag), `reservas.editar`, algún permiso de emisión |
| Quedó la reserva aunque el cobro falló | algo en `facturarYCobrar()` devolvió sin lanzar; ver §3.5 |
| 403 en inglés al crear y cobrar | el `catch (AuthorizationException)` de `store()` |
| Cambio la afectación y el resumen no cambia | ¿`l.afectacion` está en la firma del `watch`?; ¿la línea es un tour (422)? |
| La factura sale con otra afectación que la elegida | ¿el `reserva_item` tiene `fecha_override_tributario`? Sin ella gana la cascada de `resolverTipAfeIgvItem()` |
| El IGV del resumen difiere en un céntimo del comprobante | esperado con gravados de varias categorías (§3.1) |
| El número sale «B001-0000null» en «Comprobantes emitidos» | el correlativo se asigna al enviar a SUNAT; ver «Por analizar» del backlog |

## 8. Ejercicios

1. En `facturarYCobrar()`, quita mentalmente el `throw new HttpException(...)` y deja solo el `return`. Con un
   cobro que no cubre el total, ¿qué filas quedarían en la base? ¿Qué test lo detectaría?
2. ¿Por qué `catch (HttpException)` no atrapa el rechazo de `ReservaPolicy`? Busca de qué clase hereda
   `AuthorizationException` y dónde la convierte Laravel en 403.
3. `store()` revisa `reservas.editar` a mano. ¿Qué middleware lo revisa en `reservas/{id}/facturar`? (pista:
   `routes/` y `RutasAgenciaPermisosTest`, recorrido 47). ¿Qué pasaría sin esa revisión?
4. Sigue un traslado de S/ 118 pasado a Inafecto: ¿qué valor tiene `lineas[0].montos` en la vista previa, qué
   guarda el `reserva_item` y de dónde saca `ReservaFacturacionController::store()` el 30 al armar la venta?
5. Un tour clásico con una entrada gravada de S/ 50 y un ajuste de redondeo de S/ 0.40: arma a mano los `montos`
   de `montosTourClasico()` y el resumen. ¿Con qué afectación entra el ajuste y por qué?
6. ¿Qué cambiaría en la pantalla y en el backend para permitir cobro **parcial** (fuera de alcance hoy)? ¿Por qué
   hoy se resuelve con «Crear sin cobrar» + facturar a crédito desde la reserva?
