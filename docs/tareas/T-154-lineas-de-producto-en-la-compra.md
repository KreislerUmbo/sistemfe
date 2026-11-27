# T-154 — Líneas de producto en la compra: stock y costo promedio

| | |
|---|---|
| **Módulo** | COM + VEN |
| **Depende de** | T-135 (hecha), T-153 (hecha) |
| **Reglas** | «Dinero y caja» de `CLAUDE.md`, aplicada al stock con el kárdex de T-153 |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7f (T-151, 25-nov-2026); D1-D9 de abajo (usuario y revisión, 26-nov-2026) |
| **Rama** | `feat/T-154-lineas-producto-compra` |
| **Leer antes** | `docs/aprendizaje/94-recorrido-kardex-servicio-unico-de-stock.md` y `95-recorrido-comprobante-de-compra-y-modulos.md` |

## Contexto
Una tienda compra mercadería, y hoy el stock solo sube con un ajuste a mano. Con esta tarea, una
compra (T-135) puede detallar productos. Esas líneas suman stock por el servicio único (T-153) y
actualizan el costo promedio del producto. Una compra sin líneas sigue siendo solo montos (gastos
y todo lo de Agencia).

**Estado del código al empezar (verificado el 26-nov):**
- `App\Services\Stock\StockService` es el único que mueve stock. Todo pasa por
  `aplicar(array $delta, $tipo, $documentoTipo, $documentoId)`, que bloquea los productos en orden
  de id y escribe una fila de `stock_movimientos` por producto, dentro de su propia
  `DB::transaction` (anidada, queda en la transacción de quien la llama).
- `StockMovimiento` ya tiene las constantes `COMPRA`, `COMPRA_ANULADA` y `NC_COMPRA`, y la columna
  `costo_unitario` decimal(18,6) nullable, que hoy nadie llena.
- `Product::controlaStock()` dice si un producto mueve stock. `products` todavía no tiene costo.
- `App\Services\Compras\CompraService` tiene `guardar()` (crear y editar en una transacción, con el
  periodo bloqueado) y `anular()`. En `anular()`, un comentario marca dónde irá la regla de pagos
  de T-136. `Compra::TIPOS_NOTA = ['07', '08']`.
- Pantalla de compra: `admin-start-kit/src/views/compras/form.vue`. Buscador de productos de
  ventas: `components/Sales/BuscadorProductosVenta.vue` (`GET products?search=&take=10`).

## Decisiones (26-nov-2026)
- **D1 — Crédito fiscal (usuario).** Columna nueva `compras.con_credito_fiscal` (boolean,
  default false). La pantalla la propone **marcada** en 01, 07, 08 y 14, y **sin marcar** en el
  resto. El usuario la puede cambiar. Si va marcada, el costo de la línea es sin IGV; si no, con
  IGV. Se guarda en toda compra, con o sin líneas (T-136 y el Registro la van a usar).
- **D2 — Editar una compra con líneas (usuario).** Se puede editar la cabecera: serie, número,
  fechas, periodo, vencimiento, condición, montos del comprobante, adjuntos y observaciones.
  **Quedan fijos** las líneas, el proveedor, el tipo, la moneda, el tipo de cambio y
  `con_credito_fiscal`. Si cambia uno de esos, responde 422 «Esta compra tiene productos: anúlala
  y regístrala de nuevo». Una compra sin líneas no puede recibirlas al editarla (mismo mensaje).
- **D3 — NC de compra con líneas (usuario).** Solo con productos de la compra que la NC modifica,
  y sin pasar de lo comprado menos lo ya devuelto en otras NC vigentes de esa compra. Si no,
  responde 422 con el producto y el máximo.
- **D4 — Qué tipos llevan líneas.** De entrada: 01, 03, 04, 12 y 91. De salida, como devolución:
  07. Los demás (02, 05, 08, 10, 13, 14) dan 422 si traen líneas.
- **D5 — Moneda.** El costo se guarda siempre **en soles**. En USD es costo de la línea ×
  `compras.tipo_cambio`. Los montos de la línea quedan en la moneda de la compra.
- **D6 — Escalas.** `cantidad`, `factor`, `valor_unitario`, `costo_unitario_base` y
  `products.costo_promedio` van en decimal(18,6). `valor_venta`, `igv` y `total` de la línea van en
  decimal(14,2). Las cuentas se hacen con `Brick\Math\BigDecimal`, redondeando una sola vez al
  guardar (HALF_UP).
- **D7 — Fórmulas** (S = stock antes, P = promedio antes, q = unidades base, c = costo unitario base):
  - Entrada (compra): si S ≤ 0 o P es null, el nuevo P es c; si no, (S·P + q·c) / (S + q).
  - Salida por NC: el stock baja q y P no cambia. La fila del kárdex lleva `costo_unitario` = P.
  - Anular una compra: requiere S ≥ q en cada producto, si no responde 422 (ver CA3). El nuevo P
    es (S·P − q·c) / (S − q). Si S − q = 0, o el resultado sale ≤ 0, **P no cambia**.
  - Anular una NC con líneas: las unidades vuelven como una entrada al costo con que salieron
    (tipo nuevo `nc_compra_anulada`).
- **D8 — Anular en orden.** Una compra que tiene una NC con líneas vigente no se anula: 422
  «Anula primero la nota de crédito {serie-número}». Las NC sin líneas no lo impiden. Esa regla
  sigue en «Por analizar».
- **D9 — Cuadre con el comprobante.** La suma de `valor_venta` de las líneas se compara con
  `base_gravada + exonerado + inafecto`. Si no cuadra, la respuesta trae
  `aviso: "Los productos suman X y la base del comprobante es Y"` y la pantalla lo muestra. No
  bloquea.

## Alcance
- **Migraciones** (`tenant/core/`):
  - `compra_lineas`: `compra_id` (FK), `product_id` (FK), `presentacion_id` (nullable, sin FK:
    las presentaciones no existen todavía), `factor` (default 1), `cantidad`, `valor_unitario`
    (sin IGV, en la moneda de la compra), `valor_venta`, `igv`, `total`, `costo_unitario_base`
    (soles, calculado al guardar) y timestamps;
  - `compras.con_credito_fiscal`;
  - `products.costo_promedio` (nullable).
- **Modelo:** `App\Models\Compras\CompraLinea`; `Compra::lineas()`; `costo_promedio` en `Product`.
- **`StockService`**, métodos nuevos que pasan por el mismo bloqueo:
  - `entrarPorCompra(Compra, lineas)`
  - `revertirCompra(Compra, lineas)`
  - `salirPorNotaCompra(Compra $nota, lineas)`
  - `revertirNotaCompra(Compra $nota, lineas)`

  `aplicar()` se amplía para recibir el costo unitario por producto y actualizar
  `costo_promedio` **bajo el mismo `lockForUpdate`**. Las ventas no cambian (su `costo_unitario`
  sigue null). Tipo nuevo `StockMovimiento::NC_COMPRA_ANULADA`. `documento_tipo` = `compra`.
- **`CompraService`:**
  - valida las líneas: producto existente, cantidad > 0, factor > 0, montos ≥ 0, y
    `valor_venta = cantidad × valor_unitario` al centavo;
  - calcula `costo_unitario_base` = (valor_venta, o total si `con_credito_fiscal` es falso) × TC
    ÷ (cantidad × factor);
  - llama a `StockService` dentro de la transacción de `guardar()` y `anular()`;
  - aplica D2, D3, D4 y D8.

  Un producto que no controla stock va en la línea, pero no mueve stock ni costo.
- **API:** las mismas rutas de T-135. `lineas[]` viaja en el multipart y `show` devuelve las
  líneas con el producto. Sin rutas nuevas.
- **Pantalla `form.vue`:**
  - casilla «El IGV va a crédito fiscal», con el valor sugerido por D1;
  - sección opcional «Productos», solo en los tipos de D4: `BuscadorProductosVenta` (o uno
    propio, si ese depende de cosas de venta como el precio), con cantidad, valor unitario sin
    IGV, IGV y total por línea;
  - suma de las líneas y el aviso de D9;
  - en una NC, las líneas salen de la compra de referencia, con su máximo;
  - en una compra con líneas, al editar, los campos fijos de D2 quedan deshabilitados.
- **Pantalla `index.vue` / detalle:** la compra muestra sus líneas.
- **Ficha del producto:** el kárdex muestra el costo unitario cuando lo hay, y la ficha muestra
  `costo_promedio` (solo lectura).

## Fuera de alcance
- Presentaciones: la línea queda preparada con `presentacion_id` y `factor`.
- Costo en cada línea de venta, ganancia por venta y reportes de valorización.
- Editar las líneas de una compra (D2): se anula y se registra de nuevo.
- Anular una compra que tiene una NC sin líneas vigente («Por analizar», hallazgo de T-135).
- Pagos (T-136) y asignación a reserva o salida (T-152).

## Dónde tocar
- `api-sistema-fe/database/migrations/tenant/core/`: las tres migraciones.
- `app/Models/Compras/CompraLinea.php`, `Compra.php` y `app/Models/Product/Product.php`.
- `app/Models/Product/StockMovimiento.php`: el tipo nuevo.
- `app/Services/Stock/StockService.php`: los métodos nuevos y `aplicar()`.
- `app/Services/Compras/CompraService.php`: `validar()`, `guardar()` y `anular()`.
- `app/Http/Controllers/Compras/CompraController.php`: `datos()` y `respuesta()` con las líneas;
  `con_credito_fiscal`.
- `app/Http/Controllers/Product/ProductController.php`: `kardex()` y `show` con el costo.
- Frontend: `views/compras/form.vue`, `index.vue`, `types/compras.ts`, `helpers/compras.ts` (y su
  test), `components/Product/KardexProducto.vue` y `views/product/edit.vue`.

## Criterios de aceptación
- [ ] **CA1** — Producto con 10 en stock a S/ 5, más una factura con `con_credito_fiscal` por 10
  unidades a S/ 6 sin IGV: queda en stock 20 con promedio 5.500000, y hay una fila de kárdex
  `compra` con costo 6 y saldo 20. La misma compra **sin** crédito fiscal (IGV 18 %) entra a
  costo 7.08.
- [ ] **CA2** — Una compra sin líneas no toca stock, kárdex ni costo.
- [ ] **CA3** — Anular la compra de CA1 sin ventas en medio deja stock 10 y promedio 5.000000.
  Con 15 vendidos en medio, responde 422 con el mensaje (NC del proveedor o ajuste).
- [ ] **CA4** — Una NC de 2 unidades de esa compra baja el stock en 2 y no cambia el promedio.
  Una NC por más de lo comprado, o con un producto que no estaba en la compra, da 422 (D3).
  Anular la NC devuelve las 2 unidades (`nc_compra_anulada`).
- [ ] **CA5** — Una línea con factor 12 y cantidad 2 suma 24, y su costo unitario base es el costo
  de la línea ÷ 24.
- [ ] **CA6** — Dos compras simultáneas del mismo producto dejan stock y promedio correctos (dos
  procesos, como `KardexConcurrenciaTest`).
- [ ] **CA7** — Una compra en USD con TC 3.75 y una línea a US$ 2 sin IGV, con crédito fiscal,
  entra a costo 7.500000 en soles.
- [ ] **CA8** — Al editar una compra con líneas se pueden cambiar la serie y las observaciones,
  pero no la moneda ni las líneas (422, D2). Anular una compra con una NC con líneas vigente da
  422 (D8).
- [ ] **CA9** — Líneas en un tipo fuera de D4 (por ejemplo 02) dan 422. Un producto que no
  controla stock entra en la línea sin mover stock ni costo. Las líneas que no cuadran con la base
  guardan igual y devuelven `aviso` (D9).

## Seguridad y correctitud
- [ ] Permisos: los de Compras (T-135), sin permisos nuevos.
- [ ] Aislamiento de tenant: todas las tablas son de tenant.
- [ ] Validación: producto existente, cantidad > 0, factor > 0 y montos ≥ 0, todo en `CompraService`.
- [ ] Concurrencia: `lockForUpdate` del producto (servicio de T-153), dentro de la transacción de
  la compra. Orden de bloqueo: compra, después periodo, después productos por id.
- [ ] Dinero: `BigDecimal` y un solo redondeo (D6).
- [ ] Fechas: no aplica más allá de T-135.
- [ ] Tributario: el costo con o sin IGV sale de `con_credito_fiscal`, que el usuario ve y puede
  cambiar. Sin fallback silencioso.
- [ ] `v-html`: no se usa.
- [ ] Migraciones nuevas en `tenant/core/` y `tenants:migrate-verticales`.

## Cómo verificar
```
php artisan test --filter="Compra|Stock|Kardex"
```
Después, la suite completa una vez. Frontend: `npm run type-check`, `npm run build` y Vitest de
`helpers/compras.test.ts`.

Pantalla, en un tenant de tienda (encender «Compras» en el Panel Superadmin y apagarlo al final):
1. Registrar una compra con líneas.
2. Hacer una venta del producto.
3. Hacer una NC de compra.
4. Intentar anular la compra (422 por D8).
5. Anular la NC y después la compra.
6. Revisar el kárdex y el costo en la ficha del producto.

---

## Resultado
- **Commits:** `0b32ca92` (código y tests), en `feat/T-154-lineas-producto-compra`. Sin push.
- **Tests:**
  - Nuevos: `CompraLineasTest` (CA1-CA5, CA7-CA9) y `Ventas/CompraConcurrenciaTest` (CA6, dos procesos).
  - Suite completa (26-nov): 2510 pasan y 4 fallan. Las 4 eran de tests que borraban `compras` para
    simular un tenant antiguo y chocaban con la FK de `compra_lineas`. Se corrigieron solo esos dos
    tests y el filtro `Compra|Stock|Kardex|ProveedoresTablaBase` quedó en verde (42). La suite
    completa no se volvió a correr después de ese cambio.
  - Frontend: `type-check` y `build` sin errores; Vitest de `helpers/compras.test.ts` (6).
- **Prueba en pantalla** (tenant `sandbox`, retail, con Playwright): compra de 10 a S/ 6 → venta de 1
  → NC de compra de 2 → anular la compra (422, D8) → editar la compra (campos fijos) → anular la NC y
  la compra → kárdex con costo y ficha (stock 9, costo 4.944444, que es D7 con la venta en medio).
  «Compras» se encendió y apagó con `ModulosTenant::cambiar()` desde tinker, no desde el Panel
  Superadmin. Quedan en `sandbox`: producto `PRUEBA-T154`, su proveedor, dos compras anuladas, una
  boleta de S/ 10 sin enviar a SUNAT y una sesión de caja cerrada.
- **Al desplegar:** `tenants:migrate-verticales`.
- **Desviaciones de la tarea y por qué:**
  1. En D2 también queda fija **la compra que modifica** una NC con productos: no estaba en la lista
     de campos editables, y de ella salen los máximos de D3.
  2. **Máximo 100 líneas por compra.** Cada línea son 7 campos del multipart y PHP corta pasados 1000
     (`max_input_vars`) sin error: con más de ~140 líneas se habrían perdido en silencio.
  3. Anular solo revierte los productos que **de verdad movieron** stock (según el kárdex). Si un
     producto empezó a controlar stock después de la compra, no se le resta lo que nunca entró.
  4. `costo_promedio` sale solo en `products/{id}`, no en `ProductResource`: el listado lo usa
     también quien vende.
  5. `GET compras/{id}` devuelve además `devolvible` (máximo por producto), para la pantalla de la NC.
  6. El IGV de cada línea se propone en la proporción IGV / base del comprobante (sin tasa fija; 0 si
     no hay IGV) y se puede cambiar.
  7. Buscador de productos propio dentro de `LineasCompra.vue`: el de ventas muestra el precio de
     venta y ofrece crear productos.

## Revisión (26-nov-2026)
Diff revisado contra la tarea. Backend conforme. Se corrigió, con tests en `CompraLineasTest` (CA9):
1. `form.vue`: pasar de 08 a 07 con la referencia ya elegida dejaba la NC sin productos (el watch de
   la referencia no se disparaba). Ahora el cambio de tipo carga las líneas de la referencia.
2. `form.vue`: un valor unitario vacío se mandaba como 0 y entraba a costo cero. Ahora viaja vacío y
   el backend responde 422.
3. `CompraService::prepararLineas()`: el `total` de la línea debe ser `valor_venta + igv` (sin
   crédito fiscal es el costo). Era el primer hallazgo de abajo: queda cerrado.
4. `prepararLineas()`: cantidad × factor menor a 0.000001, o un costo que pase de decimal(18,6), da
   422 en vez de un 500.

Suite completa después de la revisión: 2513 pasan y 1 falla, el intermitente conocido
`Fila13Fase1cFacturacionTest::descuento_del_combo…`, que pasa al correrlo solo. Frontend:
`type-check`, `build` y Vitest (6) sin errores. No se repitió la prueba en pantalla. Pendiente
de la etapa 7: `/code-review` y `/security-review`.

## Hallazgos fuera de alcance
- Quien registre compras necesita además `list_product` para buscar productos (se suma al hallazgo de
  permisos de T-135).
- El costo se ve en el kárdex y la ficha con `list_product`: no hay un permiso aparte para ver costos.
