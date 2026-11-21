# T-116 — Editar venta: corregir los errores que dejó anotados T-110

| | |
|---|---|
| **Módulo** | VEN |
| **Depende de** | T-110 (hecha) |
| **Reglas** | — |
| **Decisiones** | Cobro de la edición con pagos al instante (usuario, 20-nov-2026, T-110) |
| **Rama** | `fix/T-116-edicion-venta-errores` |

## Contexto
T-110 pasó `sale/edit.vue` al diseño de la pantalla nueva sin cambiar su comportamiento, y
anotó en «Por analizar» del backlog los errores que encontró en la edición. El usuario pidió
el 20-nov que la edición «funcione correctamente»: esta tarea los corrige. No se cambia el
cobro de la edición por el de registro (sigue la decisión del 20-nov).

## Alcance
1. **Crédito sin poder agregar ni guardar.** `SalePaymentController::store()`/`destroy()`
   responden 422 a ventas a crédito, y `store()` de `edit.vue` exigía al menos un pago.
   En crédito el pago inicial se arma en memoria y lo guarda `PATCH sales/{id}` (que ya
   reemplaza todos los pagos del payload); el pago deja de ser obligatorio en crédito
   (igual que `register.vue`).
2. **Anticipo vacío al abrir.** `loadSale()` carga `n_comprobante_anticipo` y `amount_anticipo`.
3. **Sucursal equivocada.** `GET sales/{id}` devuelve `branch_id` (el de la serie de la venta)
   y `edit.vue` arranca en esa sucursal, no en `branches[0]`.
4. **Destino pisado por el cliente.** `loadSale()` asigna el destino guardado después de
   `selectClient()`.
5. **Venta enviada a SUNAT sin mensaje.** `store()` muestra el `message` de cualquier respuesta
   con `code` distinto de 200.
6. **Etiqueta de precio de la línea guardada.** Se calcula al cargar con el precio que tiene la
   línea («General», «Mayorista» o «Precio guardado»), no con el catálogo y el cliente de hoy.
7. **Decimales largos.** `getPrecioBaseSinIgv()` redondea a 6 decimales (también en registro).

## Fuera de alcance
- Unificar el cobro de edición con `CobroContado`/`CobroCredito`/`AdelantosVenta`.
- Implementar la búsqueda del comprobante de anticipo («Función pendiente»).

## Dónde tocar
- `admin-start-kit/src/views/sale/edit.vue` → `store()`, `loadSale()`, `cargarTiposYSucursales()`
- `admin-start-kit/src/components/Sales/CobroEdicion.vue` → `addPayment()`, `removePayment()`
- `admin-start-kit/src/components/Sales/TablaLineasVenta.vue` → `nombrePrecio()`, `opcionesPrecio()`
- `admin-start-kit/src/composables/useProductPricing.ts` → `getPrecioBaseSinIgv()`
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` → `show()`

## Criterios de aceptación
- [ ] **CA1** — Venta a crédito: agregar y quitar pago inicial no da error; se guarda con o sin pago inicial → prueba en navegador
- [ ] **CA2** — Venta con anticipo: al abrir se ven el comprobante y el monto → prueba en navegador
- [ ] **CA3** — Con `can_switch_branch`, la sucursal inicial es la de la serie de la venta → test `show` + navegador
- [ ] **CA4** — Venta `nacional` de cliente de Amazonía abre en «Fuera» → navegador
- [ ] **CA5** — Guardar una venta enviada a SUNAT muestra el aviso del backend → navegador
- [ ] **CA6** — Línea guardada con precio general y cliente mayorista dice «General» → navegador
- [ ] **CA7** — 53,10 con IGV da precio base 45 exacto → Vitest

## Seguridad y correctitud
- Permisos: no aplica (sin rutas nuevas). Tenancy: no aplica. Entrada: el pago en memoria lo
  valida `CobroVentaService::prepararPagos()` en `update()`. Concurrencia: no aplica.
  Dinero: redondeo a 6 decimales del precio unitario, dentro de la tolerancia de
  `PrecioVentaValidator` (0,0001). Fechas: no aplica. Tributario: el destino guardado ya no
  se pisa. `v-html`: no aplica. Migraciones: no aplica.

## Cómo verificar
```
php artisan test --filter=SaleShow
npx vitest run src/composables/useProductPricing.test.ts
npm run type-check && npm run build
```

---

## Resultado
- **Commits:** ver la rama `fix/T-116-edicion-venta-errores`.
- **Tests:** nuevos `SaleControllerSerieComprobanteTest::test_show_devuelve_la_sucursal_de_la_serie_de_la_venta`
  y `useProductPricing.test.ts` (4). Tests de ventas del backend (100), Vitest (350), `vite build` en verde.
  `vue-tsc`: no agrega errores (el de `edit.vue` en `selectClient(sale.client)` ya estaba, ver T-101).
- **Navegador** (`e2e/verif-t116-edicion.mjs`, agencia-demo, PATCH interceptados salvo el de la venta 19):
  CA1 la venta 35 pasada a crédito guarda sin pago inicial y con un pago inicial en memoria
  (`payments` en el payload, ningún POST/DELETE a `sale_payments`); CA2 el anticipo simulado se ve al
  abrir y se reenvía; CA3 con una sucursal simulada primero en la lista, arranca en la de la serie;
  CA4 destino `nacional` simulado con cliente de Amazonía abre en «Fuera»; CA5 la venta 19 muestra
  «No se puede editar una venta ya facturada electrónicamente…»; CA6 línea guardada a precio general
  con cliente mayorista dice «General» (y «Precio guardado» la de precio variable).
- **Desviaciones de la tarea y por qué:** CA2, CA3 y CA4 se probaron con datos simulados en la respuesta:
  agencia-demo no tiene ventas con anticipo, ni dos sucursales, ni una boleta nacional con cliente de
  Amazonía. No se guardó de verdad una venta a crédito, para no dejar cuotas y movimientos en la demo.

- **Ampliación (pedido del usuario, 20-nov):** `update()` guarda el anticipo
  (`anticipoDeLaEdicion()`): sin comprobante el monto queda en 0; monto negativo, no numérico o mayor
  que el total → 422; un payload sin los campos conserva lo guardado. Test `AnticipoEdicionVentaTest` (5).
- **Ampliación 2 (pedido del usuario, 20-nov — los dos hallazgos de la ampliación 1):**
  - Pagos contra total − anticipo: `prepararPagosVenta()` resta el anticipo en `update()` (el del
    payload o, si no viene, el guardado); en `edit.vue`, `totalACobrar()` = total − anticipo mide el
    límite de pagos (al agregar y al guardar), `state_payment`, `debt` y el monto a financiar. Antes,
    con anticipo, una venta a crédito no se podía guardar: el cronograma sumaba total − anticipo −
    pagos y `debt` iba como total − pagos (el backend valida el cronograma contra `debt`). El resumen
    muestra «Anticipo (ya pagado)». Tests nuevos en `AnticipoEdicionVentaTest` (7 en total).
  - Venta guardada a crédito pasada a «Contado»: `CobroEdicion` arma los pagos en memoria si la venta
    está guardada a crédito (`guardadaACredito`); los guarda el PATCH, que con cronograma responde con
    su aviso (mostrado por `store()`).
  - Navegador (`e2e/verif-t116-anticipo-pagos.mjs`, venta 35 con anticipo y crédito simulados): aviso
    «superan lo que queda por cobrar (PEN 1.00, ya restado el anticipo)» sin PATCH; en crédito → Contado,
    ningún POST/DELETE a `sale_payments` y el pago va en `payments`. Backend Sale/Cobro/Venta/Credit/Caja: 773 en verde.
