# T-115 — Precio mayorista por cantidad o por cliente mayorista (deja de depender de «empresa»)

| | |
|---|---|
| **Módulo** | VEN + PRO + CLI |
| **Depende de** | T-112 (`cantidadEnVenta()`), T-109 (`PrecioVentaValidator`) |
| **Reglas** | ninguna |
| **Decisiones** | Elegidas por el usuario el 19-nov-2026: (1) mayorista si la cantidad llega al mínimo **o** el cliente está marcado mayorista; (2) una sola escala (general + mayorista «desde N»); (3) los datos de hoy arrancan sin clientes mayoristas |
| **Rama** | `feat/T-115-precio-mayorista` |

## Contexto
Hoy `price_company` («Precio Cliente Empresa») se aplica a todo cliente con `type_client = 2`, sin
importar cuánto lleve. Además, al elegir el cliente, `recalcularPreciosPorCliente()` pisa el precio
que ya estaba en pantalla. Caso real (umbo, venta 41, 19-nov): el usuario agregó el producto 47 a
S/ 50, eligió INFONERC (empresa), la línea pasó a S/ 30 y el PDF salió a 30. Tener RUC solo decide
si el comprobante es factura o boleta; el precio menor es para **quien lleva bastante** o para un
**cliente mayorista** con precio negociado.

## Alcance
- **Datos:** migración core que agrega `products.cantidad_mayorista` (decimal 12,3, nullable) y
  `clients.es_mayorista` (boolean, default false). Ningún dato se rellena: nadie queda mayorista y
  ningún producto queda con mínimo (decisión 3).
- **Regla única** (función pura en `helpers/ventaRetail.ts`, que reemplaza a `precioCatalogoPorCliente()`):
  precio mayorista si `price_company > 0` **y** (`cliente.es_mayorista` **o**
  (`cantidad_mayorista > 0` **y** suma de cantidades del producto en toda la venta ≥ `cantidad_mayorista`)).
  Si no, el general. `type_client` deja de intervenir en el precio.
- **Venta (`register.vue`):** el precio de las líneas **sin precio a mano** se recalcula al cambiar de
  cliente **y** al cambiar la cantidad (de cualquier línea del producto, porque cuenta la suma). La
  línea muestra el nombre y el motivo: «Mayorista (desde 12 u.)», «Mayorista (cliente)» o «General».
  Al cambiar la cantidad no sale un aviso extra: basta con la etiqueta de la línea (las ayudas no deben estorbar).
- **Precio a mano nunca se pisa.** Escribir en la casilla de precio la marca como precio a mano
  aunque el número sea el mismo (hoy `@change` no se dispara si el valor no cambia: `TablaLineasVenta.vue::cambiarPrecio()`).
- Menú ▾ de la línea: «Empresa» pasa a llamarse «Mayorista».
- **Producto** (`product/register.vue`, `product/edit.vue`, `ProductFormQuick.vue`): «Precio Cliente
  Empresa» pasa a «Precio mayorista», con el campo «desde N unidades» al lado (vacío = solo para
  clientes mayoristas).
- **Cliente** (`ClienteDatosBase.vue`): casilla «Cliente mayorista (siempre precio mayorista)».
- **Backend:** `PrecioVentaValidator::precioBaseCatalogo()` aplica la misma regla, sumando las
  cantidades del producto en todo el payload. Así la pantalla, lo que se guarda y el PDF coinciden.
- `sale/edit.vue` (~línea 1164, `selectAndAddProduct()`): usar el helper nuevo en vez de
  `type_client === '2'`, para que el backend no rechace las líneas que agrega la edición. El rediseño
  de esa pantalla lo hace T-110.

**Ojo (20-nov, tras T-113 y T-114):**
- **T-114 valida la cabecera contra las líneas.** Cada vez que el precio de una línea cambie (por cliente
  o por cantidad), recalcular sus importes (`updateItem()`) y la cabecera (`sumDetails()`); si no,
  `TotalesVentaValidator` responde 422.
- **`edit.vue` también tiene que recalcular por cantidad.** `PrecioVentaValidator::precioValido()` acepta
  solo **el** precio que corresponde (no «general o mayorista»), salvo en líneas guardadas que no
  cambiaron de precio. Si en la edición se sube una línea nueva a ≥ N y queda al precio general, el
  backend la rechaza sin el permiso de precio. Mínimo: en `edit.vue`, las líneas sin precio a mano se
  recalculan al cambiar la cantidad con el mismo helper. Las líneas guardadas que no se tocan conservan
  su precio (el validador ya las acepta).
- `edit.vue` (~2131 y ~2159) tiene otros dos lugares que eligen el precio por `type_client`
  (`getPriceBaseCF()`/`getPriceBaseCE()`, en los eventos de Selectr). Comprobar si siguen vivos: si sí,
  mismo helper; si es código muerto, anotarlo como hallazgo y **no** borrarlo en esta tarea.

## Fuera de alcance
- Varias escalas (1-11 / 12-49 / 50+): se descartó (decisión 2).
- `type_client` sigue existiendo (se usa en la venta, los anticipos y en Agencia: `AlternativaPdfService:285`); solo deja de decidir el precio.
- El portal e-commerce (fuera del workspace): revisar si usa `price_company` y avisarlo como hallazgo.
- Cotizaciones comerciales: no usan `price_company` hoy; no se tocan.

## Dónde tocar
- `api-sistema-fe/database/migrations/tenant/core/` → migración nueva (dos columnas).
- `api-sistema-fe/app/Services/Ventas/PrecioVentaValidator.php` → `validar()` (calcular la suma por
  `product_id` antes del `foreach`) y `precioBaseCatalogo()` (~línea 237).
- `api-sistema-fe/app/Http/Controllers/Product/ProductController.php`, `Http/Resources/Product/ProductResource.php`,
  `Models/Product/Product.php` → `cantidad_mayorista` (fillable, validación ≥ 0, resource).
- `api-sistema-fe/app/Http/Requests/Client/ClienteRequest.php`, `ClientController.php` (~132),
  `Resources/Client/ClientResource.php`, `Models/Client/Client.php` → `es_mayorista`.
- `admin-start-kit/src/helpers/ventaRetail.ts` → `precioCatalogoPorCliente()` (~44) pasa a recibir
  el cliente y la cantidad total; ajustar `indiceLineaIntacta()`: el «precio del sistema» ahora depende de la suma.
- `admin-start-kit/src/views/sale/register.vue` → `precioBaseDe()` (~402), `recalcularPreciosPorCliente()`
  (~410, pasa a recalcular también por cantidad), `elegirPrecioLinea()` (~425), `agregarProducto()`, `updateItem()`.
- `admin-start-kit/src/components/Sales/TablaLineasVenta.vue` → `cambiarPrecio()` (~202, marcar a mano
  con `@input`), `precioBruto()`/`nombrePrecio()` (~176), opciones del ▾ (~182-186).
- `admin-start-kit/src/views/product/register.vue`, `product/edit.vue` (~53), `components/Sales/ProductFormQuick.vue` (~30).
- `admin-start-kit/src/components/Clientes/ClienteDatosBase.vue` → casilla nueva.
- `admin-start-kit/src/views/sale/edit.vue` (~1157).

## Criterios de aceptación
- [ ] **CA1** — Dado un producto general 50 / mayorista 30 desde 12 u. y un cliente no mayorista,
  cuando lleva 11, entonces el precio es 50; cuando llega a 12 (en una línea o sumando varias),
  entonces todas sus líneas sin precio a mano pasan a 30 con la etiqueta «Mayorista (desde 12 u.)» → test `ventaRetail` + manual.
- [ ] **CA2** — Dado un cliente empresa (`type_client = 2`) no mayorista que lleva 1 u., entonces el precio
  es el general (caso de la venta 41) → test front + test validador.
- [ ] **CA3** — Dado un cliente marcado mayorista que lleva 1 u., entonces el precio es el mayorista → test front + validador.
- [ ] **CA4** — Dado un producto con precio mayorista y sin «desde N», entonces solo lo reciben los clientes mayoristas → test.
- [ ] **CA5** — Una línea con precio a mano no cambia al elegir el cliente ni al cambiar la cantidad; escribir
  en la casilla el mismo número que ya tenía la marca como precio a mano → test + manual.
- [ ] **CA6** — Backend sin `cambiar_precio_venta`: 1 u. a 30 para un cliente no mayorista → 422;
  12 u. (en una línea o en 7 + 5) a 30 → pasa; cliente mayorista, 1 u. a 30 → pasa → `PrecioVentaValidatorTest`
  (ajustar los casos actuales que usan `type_client = 2`).
- [ ] **CA7** — El precio que muestra la pantalla al cobrar es el que sale en el PDF → manual en umbo, Ghostscript.
- [ ] **CA8** — Después de migrar, ningún cliente es mayorista y ningún producto tiene mínimo → verificación en la base.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas. Marcar un cliente como mayorista le da
  precio menor: queda con el permiso actual de editar clientes. Si hace falta un permiso propio, se decide en la revisión.
- [ ] Aislamiento de tenant: columnas en tablas tenant; nada central.
- [ ] Validación de entrada / archivos: `cantidad_mayorista` numérico ≥ 0 o null; `es_mayorista` boolean.
- [ ] Concurrencia (`lockForUpdate`): no aplica.
- [ ] Dinero en centavos: el validador sigue en BigDecimal con su tolerancia.
- [ ] Fechas con `HoraPeru`: no aplica.
- [ ] Tributario sin fallback silencioso: la reversión del IGV incluido (`getPrecioBaseSinIgv` / `tasaDe()`) no cambia.
- [ ] Sin `v-html` sin sanitizar: no aplica.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí, una migración core.

## Cómo verificar
```
cd C:/xampp/htdocs/sistemfe/admin-start-kit && npx vitest run src/helpers/ventaRetail.test.ts
cd C:/xampp/htdocs/sistemfe/api-sistema-fe && php artisan test --filter=PrecioVentaValidator
php artisan tenants:migrate-verticales
```
Manual en `http://umbo.sistemafe.test:5173`: producto 47 con mayorista 30 desde 12 u.; venta a
INFONERC (empresa) con 1 u. → 50; subir a 12 → 30; marcar un cliente mayorista → 30 con 1 u.; precio
escrito a mano que no cambia al elegir el cliente; PDF igual a la pantalla.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
