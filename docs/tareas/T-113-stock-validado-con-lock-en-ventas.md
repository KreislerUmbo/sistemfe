# T-113 — Stock validado en el backend, con bloqueo, al guardar, editar y eliminar una venta

| | |
|---|---|
| **Módulo** | VEN |
| **Depende de** | T-112 (varias filas del mismo producto) |
| **Reglas** | CLAUDE.md «Dinero y caja» (lock real, nunca `MAX()`) y «Ventas» (atomicidad de `update()`) |
| **Decisiones** | 19-nov-2026, el usuario: **bloquear** la venta cuando no alcanza el stock (no permitir negativo con aviso). Para vender sin stock está la marca «venta sin stock» del producto (`disponiblidad = 1`) |
| **Rama** | `feat/T-113-stock-con-lock-ventas` |

## Contexto
Revisión del 19-nov de lo que pasa cuando dos personas venden a la vez:
- `SaleController::store()` descuenta con `decrement('stock')`, que es atómico y no pierde unidades, pero
  **no valida**: si quedaba 1 y A y B cobran a la vez, se guardan las dos y el stock queda en −1. El único
  control está en la pantalla, con el stock leído al buscar el producto. `products.stock` es `double`
  sin restricción.
- Descuenta **todo** producto, servicios incluidos: `controla_stock` existe desde el 28-jul, pero nunca
  se conectó. La pantalla usa otro criterio (`helpers/ventaRetail.ts::controlaStock()`:
  `tipo_bien_servicio !== 'SERVICIO' && disponiblidad !== 1`).
- Dos ventas con los mismos productos en distinto orden pueden bloquearse entre sí (deadlock): Postgres
  aborta una y el vendedor ve un 500.
- `destroy()` no devuelve el stock (la NC con `reponer_stock` sí lo devuelve).
- `update()` no bloquea la venta: dos ediciones a la vez de la misma venta pueden ajustar el stock dos veces.

## Alcance
- **Un solo criterio de «controla stock»**, igual en backend y pantalla: es un bien
  (`tipo_bien_servicio !== 'SERVICIO'`), no está marcado «venta sin stock» (`disponiblidad !== 1`) y
  `controla_stock = true`. En el backend, un método del modelo (p. ej. `Product::controlaStock()`), y
  `ventaRetail.ts::controlaStock()` se actualiza para sumar `controla_stock`.
- **Servicio de stock** (p. ej. `App\Services\Ventas\StockVentaService`), usado dentro de la transacción:
  1. Agrupa las cantidades **por producto** (sumando todas las líneas: T-112).
  2. Bloquea los productos con `lockForUpdate()`, **ordenados por `id`** (evita el deadlock).
  3. Valida, para los que controlan stock, que `stock >= cantidad pedida`; si no, **422** con
     «Solo quedan N unidades de «producto».» y nada guardado (rollback).
  4. Descuenta (o devuelve) solo los que controlan stock.
- `store()`: usa el servicio en lugar del `foreach` con `decrement` (~línea 983).
- `update()`: bloquea la venta (`Sale::whereKey()->lockForUpdate()`) al empezar la transacción y valida
  solo la **diferencia** por producto (lo nuevo menos lo que ya tenía la venta), sin romper la
  transacción única de venta/detalles/comprobante (~líneas 1142 y 1243-1335).
- `destroy()`: devuelve el stock de sus líneas dentro de la transacción que ya existe (~línea 1450).
- NC con `reponer_stock` (`NotaElectronicaController`, ~línea 446): devuelve solo a los productos que
  controlan stock, con el mismo criterio.
- La pantalla muestra el 422 del backend como aviso (sin pantalla nueva).

## Fuera de alcance
- Corregir el stock negativo que ya tengan los productos en los tenants (servicios descontados desde
  siempre): se mide con una consulta al desplegar y, si hace falta, es otra tarea (ajuste de inventario).
- Kardex o historial de movimientos de stock.
- Stock por sucursal.
- Pedidos del portal e-commerce (revisar si descuentan stock; si sí, anotarlo como hallazgo).

## Dónde tocar
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` → `store()` (~983), `update()` (~1142,
  ~1243-1335), `destroy()` (~1450).
- `api-sistema-fe/app/Http/Controllers/Sale/NotaElectronicaController.php` → reposición (~446).
- `api-sistema-fe/app/Models/Product.php` → `controlaStock()`.
- `api-sistema-fe/app/Services/Ventas/` → servicio nuevo.
- `admin-start-kit/src/helpers/ventaRetail.ts` → `controlaStock()` (+ test).

## Criterios de aceptación
- [ ] **CA1** — Bien con stock 1; venta de 2 → 422 «Solo quedan 1 unidades…», sin venta, sin caja, stock 1 → test.
- [ ] **CA2** — Bien con stock 5 en dos líneas (3 + 3) → 422 (valida la suma por producto) → test.
- [ ] **CA3** — Venta de un servicio, de un producto «venta sin stock» o con `controla_stock = false`:
  se guarda y su stock no cambia → test.
- [ ] **CA4** — `update()` que sube de 2 a 4 con stock 1 → 422 (la diferencia es 2) y la venta queda
  como estaba; bajar de 4 a 2 devuelve 2 → test.
- [ ] **CA5** — Eliminar una venta devuelve el stock de sus bienes; la de un servicio no lo toca → test.
- [ ] **CA6** — Dos ventas simultáneas por la última unidad: una se guarda y la otra recibe 422; el stock
  queda en 0, no en −1 → manual (dos peticiones en paralelo con un script) y revisión del lock en el código.
- [ ] **CA7** — Las suites de ventas, notas y caja siguen en verde (`SaleControllerSerieComprobanteTest`,
  `Fila13Fase1cFacturacionTest`, tests de NC).

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas).
- [ ] Aislamiento de tenant: no aplica (todo en la base del tenant).
- [ ] Validación de entrada / archivos: las cantidades ya se validan; el 422 nuevo va antes de tocar caja.
- [ ] Concurrencia (`lockForUpdate`): **es el centro de la tarea** (productos ordenados por `id`; venta en `update()`).
- [ ] Dinero en centavos: no aplica.
- [ ] Fechas con `HoraPeru`: no aplica.
- [ ] Tributario sin fallback silencioso: no aplica.
- [ ] Sin `v-html` sin sanitizar: no aplica.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no se prevén.

## Cómo verificar
```
php artisan test --filter=StockVenta
php artisan test --filter=SaleController
```
Manual (CA6): un bien con stock 1 en agencia-demo o umbo y dos `POST sales` en paralelo (script con
dos procesos `curl` o Playwright con dos pestañas).
Al desplegar: consulta de productos con `stock < 0` por tenant, para decidir el ajuste (fuera de alcance).

---

## Resultado
- **Commits:** rama `feat/T-113-stock-con-lock-ventas` (ver `git log`).
- **Tests:**
  - CA1–CA5: `tests/Feature/Ventas/StockVentaTest.php` (6 tests, verdes).
  - CA6 manual en agencia-demo: producto temporal con stock 1 y dos `POST /api/sales` en paralelo (curl) → una `200` (venta 36) y la otra `422 «Solo quedan 0 unidades de «T113 CA6 temporal».»`; stock quedó en 0 (no −1). Al eliminar la venta por la API el stock volvió a 1. Producto y venta temporales borrados después.
  - CA7: suite completa del backend contra Postgres: 2390 pasan, 2 fallan. (1) `SaleControllerSerieComprobanteTest::store venta nv a credito…` fallaba porque su producto era «venta sin stock» (`disponiblidad = 1`) y esperaba descuento: se corrigió el fixture a `disponiblidad = 2` (efecto del criterio único); ahora verde. (2) `Fila13Fase2cOperacionTest::en un viaje de varios dias solo se mueve el tour` falla también en `main` sin mis cambios (usa la fecha fija 18-nov-2026, que ya pasó: «no puede ser anterior a hoy»); no es de esta tarea.
  - Frontend: Vitest de `ventaRetail.test.ts` (31 verdes, con el caso nuevo de `controlaStock`). No corrí `type-check`/`build` ni prueba visual de la pantalla.
- **Desviaciones de la tarea y por qué:**
  - `ProductResource` ahora devuelve `controla_stock` para que la pantalla use el mismo criterio que el backend (necesario para el criterio único; no estaba listado en «Dónde tocar»).
  - `update()` ahora relanza los `HttpException` (antes todo se convertía en 500), para que el 422 de stock llegue como 422. La transacción sigue siendo una sola.
  - En `store()` el control de stock corre al inicio de la transacción (antes de cabecera, caja y correlativos), no al final.
  - La reposición de la NC no está en una transacción propia: el lock es de una sola sentencia y el `decrement` es atómico; no se agregó transacción por no ampliar alcance.
  - Efecto a vigilar al desplegar: servicios y productos «venta sin stock» ya no descuentan; productos que hoy tienen `disponiblidad = 1` dejan de bajar de stock.

## Hallazgos fuera de alcance
- `Fila13Fase2cOperacionTest` (viaje de varios días) está roto en `main` por una fecha fija en el pasado (18-nov-2026).
- `update()` toma `detalle_bd` por `SaleDetail::find($id)` sin comprobar que la línea sea de esa venta (preexistente).
- No revisé si el portal e-commerce descuenta stock (queda como hallazgo pendiente de la tarea).
