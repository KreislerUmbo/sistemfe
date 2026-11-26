# T-153 — Kárdex y servicio único de stock

| | |
|---|---|
| **Módulo** | VEN + COM |
| **Depende de** | ninguna |
| **Reglas** | «Dinero y caja» de `CLAUDE.md` (ningún movimiento silencioso), aplicada al stock |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7f, punto 3 (T-151, 25-nov-2026) |
| **Rama** | `feat/T-153-kardex-stock` |

## Contexto
El stock baja con las ventas por un solo servicio con bloqueo (`StockVentaService`, T-113), pero
no queda historial, y sube editando la ficha del producto sin motivo ni rastro. Antes de que las
compras sumen stock (T-154), cada movimiento tiene que quedar registrado.

## Alcance
- Tabla `stock_movimientos` en `tenant/core/`: producto, tipo, cantidad con signo (en unidad base),
  costo unitario (nullable hasta T-154), saldo después del movimiento, documento de origen
  (tipo + id, sin FK polimórfica), motivo (texto, obligatorio en ajustes), usuario y `created_at` (UTC).
- Tipos: `venta`, `venta_edicion`, `venta_eliminada`, `nc_venta`, `ajuste` (y `compra`,
  `compra_anulada`, `nc_compra` reservados para T-154).
- `StockVentaService` pasa a ser el servicio único de stock (renombrarlo si conviene, p. ej.
  `StockService`, decirlo en el commit): cada cambio de stock que aplica escribe su fila con el saldo
  resultante, dentro de la misma transacción y del mismo bloqueo. Los productos que no controlan
  stock no escriben fila.
- Ajuste manual: `ProductController::update()` deja de escribir `stock` directamente; si el stock
  cambia, pasa por el servicio como `ajuste` con motivo obligatorio. En `store()`, el stock inicial
  queda como un primer `ajuste` («stock inicial»).
- Ficha del producto: el campo stock pide motivo al cambiarlo y muestra el historial (kárdex) del
  producto: fecha, tipo, documento, cantidad, saldo, usuario.

## Fuera de alcance
- Compras y costo promedio (T-154). Reconstruir el historial de ventas pasadas: el kárdex arranca
  con una fila `ajuste` «saldo inicial» por producto que controla stock, al migrar.
- Formato del Registro de Inventario Permanente de SUNAT; reportes de valorización.

## Dónde tocar
- `api-sistema-fe/app/Services/Ventas/StockVentaService.php` → `aplicar()` (escribe el kárdex).
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` → `store()` (~852), `update()` (~1270),
  eliminación (~1590): pasar el tipo y el documento al servicio.
- `api-sistema-fe/app/Http/Controllers/Sale/NotaElectronicaController.php` (~445): tipo `nc_venta`.
- `api-sistema-fe/app/Http/Controllers/Product/ProductController.php` → `store()`/`update()`: `stock` fuera de
  la asignación directa (está en la lista de campos, ~línea 36).
- `admin-start-kit` → formulario y ficha del producto.

## Criterios de aceptación
- [ ] **CA1** — Vender, editar una venta, eliminarla y emitir una NC con reposición deja una fila de
  kárdex por producto con el tipo, el documento y el saldo correctos; el saldo de la última fila es
  igual a `products.stock`.
- [ ] **CA2** — Cambiar el stock en la ficha sin motivo da 422; con motivo deja un `ajuste` con usuario.
- [ ] **CA3** — Un producto que no controla stock no escribe kárdex.
- [ ] **CA4** — Dos ventas simultáneas del mismo producto dejan saldos consecutivos sin repetir ni
  saltar (mismo bloqueo que T-113).
- [ ] **CA5** — Al migrar, cada producto que controla stock tiene su fila «saldo inicial» con su stock actual.

## Seguridad y correctitud
- [ ] Permisos: ver el kárdex con el permiso de ver productos; ajustar con el de editar productos.
- [ ] Aislamiento de tenant: tabla de tenant.
- [ ] Validación de entrada: motivo obligatorio en ajustes; cantidad numérica.
- [ ] Concurrencia: la fila se escribe dentro del `lockForUpdate` del producto.
- [ ] Dinero: no aplica (el costo llega en T-154).
- [ ] Fechas: `created_at` en UTC; mostrar con `formatFechaHora()`.
- [ ] Tributario, `v-html`: no aplica.
- [ ] Migración nueva en `tenant/core/` + `tenants:migrate-verticales`.

## Cómo verificar
```
php artisan test --filter="Stock|Kardex|T114TotalesVenta"
```
Tenant de tienda (market en local): vender, editar, NC y ajuste; revisar el kárdex en la ficha.

---

## Resultado
- **Commits:** `feat(ventas): kárdex y servicio único de stock (T-153)` en `feat/T-153-kardex-stock`.
  `StockVentaService` pasa a ser `App\Services\Stock\StockService` (lo usará también T-154).
- **Tests:** `Ventas/KardexStockTest` (CA1, CA2, CA3, CA5) y `Ventas/KardexConcurrenciaTest` (CA4:
  dos procesos PHP contra Postgres; sin el `lockForUpdate` falla con saldos 7 y 8). La NC de CA1
  pasa por `enviarNotaSunat()` con SUNAT simulado. `type-check` y `build` sin errores.
  Migración corrida en los 7 tenants locales: saldos iniciales iguales a `products.stock`.
- **Sin hacer:** la prueba en pantalla (ficha del producto y kárdex). La sesión no tenía permiso
  para crear un usuario de prueba. Queda para la revisión.
- **Desviaciones de la tarea y por qué:**
  - La venta descuenta stock después de crear la cabecera (antes, antes): el kárdex necesita su id.
    Sigue en la misma transacción y antes del correlativo y la caja.
  - El servicio abre su propia transacción: la reposición por NC se llamaba sin ninguna y el
    `lockForUpdate` no protegía nada.
  - La pantalla solo envía `stock` si se cambió, para que guardar la ficha no pise lo vendido
    mientras estaba abierta.
  - Un producto que **empieza** a controlar stock deja una fila `ajuste` automática («empieza a
    controlar stock») aunque la cantidad no cambie; si no, el último saldo no cuadraría.
  - El «saldo inicial» incluye los productos eliminados (una venta suya aún puede devolverles stock).
  - La base de tests (`sistemafe_test_migrations`) necesita la migración nueva, aplicada por archivo.

## Hallazgos fuera de alcance
- La reposición de stock de la NC corre después de marcar la nota como `aceptado` y fuera de una
  transacción común: si fallara, la nota queda aceptada sin reponer y no hay reintento.
- Los adelantos y la facturación de reservas crean `sale_details` sin pasar por el stock, pero
  eliminar esas ventas o su NC sí «devuelve». Hoy no tiene efecto porque usan productos que no
  controlan stock.
