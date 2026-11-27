# T-162 — Detalle del comprobante de compra: líneas como las de la factura del proveedor

| | |
|---|---|
| **Módulo** | COM (+ AGE, que es quien más compra servicios) |
| **Depende de** | T-154 (líneas de producto, en `main`); va después de T-152 para no mezclarla con su revisión |
| **Reglas** | `CLAUDE.md` → «Dinero y caja», «Multi-tenancy» (migración nueva, nunca editar una existente) |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7 (tabla de ayudas: SIRE trae montos, el detalle solo viene en el XML) y §3.7f (reglas de las líneas, T-151/T-154); conversación con el usuario del 26-nov-2026 |
| **Rama** | `feat/T-162-detalle-comprobante-compra` |

## Contexto
Desde T-154 una compra puede llevar líneas, pero **solo de productos del catálogo**, pensadas para
el stock: `compra_lineas.product_id` es obligatorio con FK a `products`, y la línea no guarda ni
la descripción de la factura ni la unidad, ni el código del ítem del proveedor, ni el tipo de
afectación del IGV. Una compra de Agencia (transporte, almuerzo, guía, entradas) queda «muy
general», solo con los montos de la cabecera, y una factura con líneas gravadas y exoneradas
(Amazonía) solo se distingue en los totales.

El usuario quiere que la compra pueda **reflejar el comprobante línea por línea**, para que la
futura importación (XML del proveedor, lector de PDF) entre tal como viene. El molde es la
**línea UBL** (`InvoiceLine`): descripción, cantidad, unidad, valor unitario, valor de venta,
afectación del IGV y código del ítem. **SIRE no trae detalle**: su propuesta del Registro de
Compras es por comprobante (§3.7). Esta tarea no importa nada: deja el modelo listo para eso.

**Decisiones (usuario, 27-nov-2026):**
- **Q1 — Unidad de medida:** **código SUNAT** (tabla 6: NIU, ZZ, KGM…), con el mismo criterio que
  `products.unidad_medida` (string). Línea sin producto: `ZZ` (servicio) por defecto; con
  producto: la unidad del producto. Así coincide con lo que trae el XML.
- **Q2 — Línea sin producto:** **igual que la de producto**: cantidad × valor unitario = valor de
  venta, + IGV = total, porque así viene en el XML. Un servicio suelto va con cantidad 1.

## Alcance
- **Migración nueva en `tenant/core/`** sobre `compra_lineas`:
  - `descripcion` (texto de la factura; obligatorio en la línea sin producto; en la de producto,
    se precarga con el nombre del producto y se puede cambiar).
  - `unidad_medida` (según Q1).
  - `codigo_proveedor` (nullable): el código del ítem del proveedor, para asociarlo solo a un
    producto en una importación futura.
  - `tipo_afectacion_igv` (código SUNAT de la tabla 7: 10, 20, 30, 40…), validado en PHP.
  - `product_id` pasa a **nullable** (la FK se mantiene para cuando hay producto).
  - Las líneas existentes de T-154 se completan: descripción = nombre del producto, unidad = la
    del producto, afectación según su IGV (10 si tiene IGV, si no, la de la cabecera o 20). Lo que
    no se pueda inferir se informa en la salida de la migración, sin inventar.
- **`CompraService`:**
  - `validar()`: `lineas.*.product_id` deja de ser obligatorio; `descripcion` obligatoria si no hay
    producto; `tipo_afectacion_igv` en el catálogo; IGV de la línea en 0 si la afectación no es gravada.
  - `prepararLineas()`: una línea **sin producto** no busca producto ni calcula costo de stock
    (`costo_unitario_base` nullable o 0, según cómo quede la columna); una línea con un producto que
    no controla stock sigue como hoy (no mueve stock ni costo).
  - Stock y costo promedio **solo** con las líneas que tienen un producto que controla stock (sin
    cambios en `StockService`).
  - **Cuadre:** las líneas no reemplazan la cabecera. Se suma por tipo de afectación y se compara con
    la base gravada, exonerada e inafecta de la compra; si no cuadra, aviso, igual que hoy (§3.7f).
  - `devolvible()` y `exigirDevolvible()` (NC de compra, D3): las líneas sin producto se devuelven
    por monto, no por unidades; la regla de «no pasar de lo que queda» sigue siendo por producto.
  - `firma()` / `exigirSinCambiosDeStock()`: incluir los campos nuevos solo donde afectan al stock.
- **Tipos con líneas:** revisar si `Compra::TIPOS_CON_LINEAS` (y su copia en `helpers/compras.ts`)
  sigue igual o se amplía, ahora que hay líneas que no son de stock (p. ej. recibo por honorarios 02).
- **Pantalla (`LineasCompra.vue` en `compras/form.vue`):** bloque **«Detallar comprobante»**
  plegable y opcional (cerrado por defecto: en Agencia lo normal sigue siendo registrar montos).
  Cada línea: descripción, producto opcional (el buscador de hoy), cantidad, unidad, valor
  unitario, afectación (por defecto la de la cabecera) y montos. Detalle de la compra y listado:
  mostrar la descripción en vez de solo el producto.

## Fuera de alcance
- **Leer el XML adjunto o un PDF** y llenar las líneas solas (tarea propia, cuando se decida).
- **Importación SIRE** (fase 5 / §3.8, y primero revisar su API, P3).
- **Asignar cada línea a una reserva o salida.** T-152 asigna montos de la compra; repartir por
  línea queda para la fase 4 (centro de costo) si hace falta.
- Presentaciones y factor por presentación (requerimiento de retail, fase A).
- Descuentos y cargos por línea (UBL los tiene; se agregan si un caso real los pide).

## Dónde tocar
- `api-sistema-fe/database/migrations/tenant/core/` — migración nueva sobre `compra_lineas`
  (la de T-154 es `2026_11_29_100000_create_compra_lineas_table.php`: no se edita).
- `api-sistema-fe/app/Models/Compras/CompraLinea.php` — fillable, casts, relación `product` opcional.
- `api-sistema-fe/app/Services/Compras/CompraService.php` — `validar()` (~línea 197, reglas de
  líneas ~230), `guardar()` (~103, chequeo de `TIPOS_CON_LINEAS` ~126), `prepararLineas()` (~364),
  `exigirDevolvible()` (~419), `devolvible()` (~451), `firma()` (~354), `exigirSinCambiosDeStock()` (~323).
- `api-sistema-fe/app/Models/Compras/Compra.php` — `TIPOS_CON_LINEAS`.
- `api-sistema-fe/app/Http/Controllers/Compras/CompraController.php` — lo que devuelve el detalle.
- `admin-start-kit/src/components/Compras/LineasCompra.vue` (armado de la línea ~157),
  `src/views/compras/form.vue`, `src/views/compras/index.vue`, `src/types/compras.ts`,
  `src/helpers/compras.ts` (+ `compras.test.ts`).
- Referencia del lado de ventas: `products.unidad_medida` y `GreenterService` (~658, `setUnidad`).

## Criterios de aceptación
- [ ] **CA1** — Una factura de servicios con dos líneas sin producto («Transporte Tarapoto–Lamas»,
  «Almuerzo 12 pax») se registra, muestra su detalle y no mueve stock ni costo →
  test: `CompraDetalleComprobanteTest::test_lineas_sin_producto_no_mueven_stock`
- [ ] **CA2** — Una factura mixta (una línea gravada 10 y una exonerada 20) cuadra contra la base
  gravada y la exonerada de la cabecera; si una no cuadra, se avisa → test del mismo archivo.
- [ ] **CA3** — Una línea exonerada o inafecta con IGV distinto de cero da 422 con mensaje claro.
- [ ] **CA4** — Una compra con líneas de producto (T-154) sigue sumando stock y costo promedio
  igual que antes: `CompraLineasTest` y `CompraConcurrenciaTest` en verde sin cambiar sus expectativas.
- [ ] **CA5** — La migración completa las líneas existentes (descripción, unidad, afectación) y
  no deja ninguna con descripción vacía → verificar en `sandbox` antes y después.
- [ ] **CA6** — NC de compra (07) sobre una compra con líneas sin producto: no pasa del monto de la
  compra original; con productos, sigue la regla de D3 → test.
- [ ] **CA7** — En pantalla, «Detallar comprobante» cerrado por defecto; una compra sin líneas se
  registra igual que hoy. Prueba manual en `agencia-demo` y en `sandbox`.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas (los permisos de Compras no cambian).
- [ ] Aislamiento de tenant: tabla de tenant; el catálogo de afectaciones/unidades, si es central, sin FK y validado en PHP.
- [ ] Validación de entrada / archivos: descripción con largo máximo; códigos contra catálogo; tope de 100 líneas (`max_input_vars`, T-154).
- [ ] Concurrencia (`lockForUpdate`): solo las líneas con producto entran al bloqueo de `StockService`; sin cambios.
- [ ] Dinero en centavos: `BigDecimal` como en `prepararLineas()`, cuadre al centavo.
- [ ] Fechas con `HoraPeru`: no aplica.
- [ ] Tributario sin fallback silencioso: una línea sin afectación toma la de la cabecera **a la vista** en el formulario; el backend no la inventa.
- [ ] Sin `v-html` sin sanitizar: la descripción viene del usuario (y mañana de un XML): mostrar como texto.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí, al desplegar.

## Cómo verificar
```
php artisan test --filter=CompraDetalleComprobanteTest
php artisan test --filter=CompraLineasTest
php artisan test --filter=CompraConcurrenciaTest
```
Frontend: type-check, build y Vitest (`compras.test.ts`), desde `C:/` (ver memoria de vitest).
Pantalla: registrar en `agencia-demo` una factura de transporte con dos líneas, y en `sandbox`
una de mercadería mezclando una línea de producto y una de flete sin producto.

---

## Resultado
- **Commits:** en `feat/T-162-detalle-comprobante-compra` (ver `git log main..`).
- **Tests:** `CompraDetalleComprobanteTest` (11: CA1, CA2, CA3, CA5, CA6); `CompraLineasTest` y
  `CompraConcurrenciaTest` en verde (CA4). Suite completa: 2542 pasan y 1 falla, intermitente y ajena
  (ver Hallazgos). Vitest 370, type-check y build en verde.
- **Verificado a mano:**
  - CA5 en `sandbox`: antes de migrar había 2 líneas sin las columnas; después, las dos con la
    descripción del producto, `NIU` y afectación 10.
  - CA7 en `agencia-demo`: bloque cerrado por defecto; compra sin líneas; factura de transporte con
    dos líneas (gravada y exonerada); producto de servicio + flete (aviso de cuadre, costo solo en
    la de producto, `<b>` mostrado como texto); NC con líneas precargadas y rechazo por monto.
    Quedan las compras `F162-1` a `F162-3` como datos de prueba.
  - **Falta CA7 en `sandbox`** (mercadería + flete): no hay credencial documentada y no se reseteó.
- **Decididas con el usuario (27-nov):** 02, 05 y 14 admiten líneas sin producto; el tope por monto
  rige para toda NC; afectaciones 10, 20, 30 y 40; la afectación se deduce de la cabecera o queda
  vacía (la migración la informa) en vez de caer en 20.
- **Desviaciones de la tarea y por qué:**
  - `CompraLineasTest` cambió lo que envía, no lo que espera: sus líneas ahora mandan la
    afectación y, en CA8, la NC sin líneas baja de S/ 118 a S/ 11.80 (el tope nuevo la rechazaba).
  - `ComprasMigracionesPorGiroTest` suma la migración nueva a su lista.
  - La pantalla envía `lineas` como un texto JSON: con 11 campos por línea, 100 líneas pasaban de
    `max_input_vars` (1000). El backend sigue aceptando el arreglo.
  - Tope de NC: además exige la misma moneda que la compra, y la compra no puede bajar de sus notas
    ni cambiar de moneda si las tiene.
  - Una compra sin productos deja editar la cabecera (no hubo stock); sus líneas siguen fijas.
  - Anular una compra ya no exige anular primero una NC cuyas líneas no tienen producto.
  - `costo_unitario_base` queda `null` sin producto; `factor` queda en 1.
  - El listado no mostraba productos: la descripción se ve en el detalle.
- **Al desplegar:** `tenants:migrate-verticales` y revisar si la migración informa líneas sin
  afectación.
- **No se corrieron** `/code-review` ni `/security-review`.
- **Revisión del diff (27-nov):** corregido que las líneas en texto JSON no pasaban por el recorte
  de espacios ni por «vacío = null» de Laravel (un `product_id: ""` daba «el producto no existe»), y
  que la afectación como número (`10`) se tomaba por no gravada. Test:
  `test_texto_json_con_producto_vacio_y_afectacion_numerica`.
- **`/code-review` (27-nov), 8 hallazgos.** Corregidos:
  1. `CompraLinea::UNIDADES` tenía 64 códigos y la pantalla ofrece los 161 de `UNITS`: «KG», «GAL»…
     daban 422. Ahora son los mismos; `tests/Unit/CompraUnidadesTest` falla si se separan.
  2. Una compra con notas que la modifican no puede pasar a ser nota ni cambiar de proveedor (el
     hueco existía también antes, en compras sin líneas).
  4. `lineas` como objeto JSON da 422 «no se pudieron leer» (probablemente nunca dio 500:
     `validar()` ya hacía `array_values`).
  6. Al editar, cambiar el tipo ya no vacía en pantalla las líneas guardadas.
  7. `firma()` compara también descripción, unidad, código y afectación; un texto no enviado se
     toma de la línea guardada (`mismasLineas()`).
  Tests: `test_al_editar_los_textos_de_la_linea_tambien_cuentan`,
  `test_unidades_de_la_pantalla_y_lineas_que_no_son_lista`,
  `test_compra_con_notas_no_se_vuelve_nota_ni_cambia_de_proveedor`.
  **Sin decidir (usuario):**
  3. Anular la original con una NC vigente sin productos se permite, y esa NC queda sin poder
     editarse («la compra de referencia está anulada»). ¿Prohibirlo con cualquier NC vigente?
  5. `exigirDentroDelOriginal()` corre en toda edición de una NC: una NC anterior a T-162 en otra
     moneda o que pase del total no se puede editar ni para adjuntar el XML. ¿Revisar solo si
     cambian total, moneda o referencia, o revisar los datos de producción antes de desplegar?
  8. El aviso de cuadre queda oculto con el bloque cerrado. ¿Mostrarlo en el encabezado?
- **Falta** `/security-review`.

## Hallazgos fuera de alcance
- Una nota de débito (08) no amplía lo que se puede acreditar: el tope es el total de la compra
  original. Si un proveedor acredita una ND, hoy no entra.
- La lista de unidades (tabla 6) está copiada en `CompraLinea::UNIDADES` y en `UNITS`
  (`types/products.ts`); la ficha del producto no la valida en el backend.
- Las gratuitas y el IVAP (afectaciones 11-17 y 21-37) hay que agregarlas antes de importar XML.
- `CompraAsignacionTest::test_guarda_quien_ingreso_cada_fila` es intermitente: falló en la suite
  completa y pasó 3 de 3 sola. Lee las filas sin `orderBy` y compara con `assertSame`, que exige el
  mismo orden.
