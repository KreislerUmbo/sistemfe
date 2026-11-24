# Recorrido: retirar rutas legadas que movían dinero sin caja

23-nov-2026, tarea **T-121** (era la parte D de T-120). Cómo se retira una ruta vieja sin romper a nadie:
**confirmar que nadie la usa → responder 410 → conservar la ruta y su permiso**.

Archivos:
- `api-sistema-fe/app/Http/Controllers/Sale/SalePaymentController.php`
- `api-sistema-fe/app/Http/Controllers/Sale/SaleDetailController.php`
- `api-sistema-fe/routes/api.php` (~603, `Route::resource("sale_details"…)` y `("sale_payments"…)`)
- Test: `tests/Feature/Ventas/T121RutasLegadasTest.php`

**Al desplegar:** solo backend; no hay migración.

---

## 1. El problema: dinero que se movía «en silencio»

| Ruta | Qué hacía | Qué le faltaba |
|---|---|---|
| `POST sale_payments` | Agregaba un pago: subía `paid_out`, bajaba `debt` y `saldo_pendiente` | El ingreso en caja (`cash_movements`) |
| `DELETE sale_payments/{id}` | Borraba un pago y devolvía la deuda | La corrección en caja |
| `POST sale_details` | Agregaba una línea y descontaba stock | Caja, `StockVentaService` (lock), totales validados |
| `DELETE sale_details/{id}` | Borraba una línea, bajaba el total y la deuda, devolvía stock | Lo mismo |

Regla del proyecto (CLAUDE.md, «Dinero y caja»): **ningún movimiento de dinero es silencioso**. El camino
correcto existe desde T-119: la pantalla de edición manda todo junto con `PATCH sales/{id}`, y
`SaleController::update()` sincroniza pagos, líneas y caja en **una** transacción.

## 2. Antes de retirar: ¿alguien la usa?

- **En el código:** `grep` de `sale_payments`/`sale_details` en `admin-start-kit/src` y `central-panel/src`.
  Ojo con los falsos positivos: `sale_payments` aparece como **nombre de variable** en `register.vue` y
  `edit.vue`, pero ninguna llamada `httpClient.post('sale_payments')`.
- **En producción:** el portal e-commerce vive fuera de este repositorio, así que el código no alcanza. Se
  miró el log de nginx:
  ```bash
  sudo zgrep -hE '"(POST|DELETE) /api/sale_(payments|details)' /var/log/nginx/sistemfe.access.log* | tail
  ```
  `zgrep` lee también los logs rotados y comprimidos (`.gz`), que guardan unas dos semanas.

## 3. ¿Por qué 410 y no borrar la ruta?

- **404** («no existe») confunde: parece un error de URL.
- **410 Gone** dice «existió y se retiró a propósito», y el mensaje explica qué usar en su lugar.
- La ruta y su permiso (`register_sale_payment`, `delete_sale_detail`…) siguen registrados, así que
  `GateBucketBRoutesTest` (que verifica que cada ruta exige su permiso) no cambia. Si algún día se confirma
  que nada los llama en meses, se pueden borrar las rutas y los permisos en una tarea aparte.

## 4. El test

`T121RutasLegadasTest` arma una venta real con `SaleController::store()`, toma una «foto» (total, deuda,
pagado, saldo, estado, ids de pagos y líneas, stock) y comprueba que, después de llamar a las cuatro rutas, la
foto es **idéntica**. Es más fuerte que mirar solo el código de respuesta: prueba que no se escribió nada.

Se quitó un test de T-119 que probaba el recálculo de `state_payment` del `DELETE` viejo: probaba un
comportamiento que ya no existe.

## 5. Ejercicios

1. Si el portal sí hubiera llamado a `POST sale_payments`, ¿qué habría que construir antes de retirarla?
2. ¿Por qué el test compara los **ids** de pagos y líneas y no solo la cantidad?
3. Busca en `routes/api.php` otra ruta `Route::resource` cuyo controller tenga métodos vacíos (`//`). ¿Qué
   responde Laravel si alguien la llama?
