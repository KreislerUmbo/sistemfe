# Recorrido: las líneas de una compra como las del comprobante del proveedor

27-nov-2026, tarea **T-162** (todos los giros; en Agencia se nota más). Desde T-154 una compra
podía llevar líneas, pero solo de **productos del catálogo**, para el stock. Ahora una línea puede
ser lo que dice la factura: «Transporte Iquitos–Nauta», «Flete», «Almuerzo 12 pax», con su unidad,
el código del proveedor y la afectación del IGV, **tenga o no producto**. El molde es la línea del
XML de SUNAT (`InvoiceLine`), para que una importación futura entre tal como viene.

Lo que se aprende acá:
- **pasar una columna a opcional sin perder lo viejo**: completar las filas existentes dentro de la migración y avisar lo que no se pudo deducir;
- **quién mueve stock**: solo la línea con un producto que controla stock;
- **el cuadre por afectación**: aviso, no bloqueo, y el mismo cálculo en backend y frontend;
- **por qué la pantalla manda las líneas como texto JSON** (`max_input_vars`);
- **el tope de una nota de crédito** y cuándo se revisa.

Archivos:
- Migración: `database/migrations/tenant/core/2026_11_30_200000_add_detalle_comprobante_to_compra_lineas.php`
- `app/Models/Compras/CompraLinea.php` (`AFECTACIONES`, `UNIDAD_SERVICIO`, `UNIDADES`)
- `app/Models/Compras/Compra.php` (`TIPOS_CON_LINEAS_SIN_PRODUCTO`: 02, 05 y 14)
- `app/Services/Compras/CompraService.php`: `validar()`, `comoFormulario()`, `prepararLineas()`,
  `firma()` / `mismasLineas()`, `exigirQueCubraSusNotas()`, `cambiaLoQueAcredita()`, `acreditado()`,
  `exigirDevolvible()`, `devolvible()`, `anular()`
- `app/Http/Controllers/Compras/CompraController.php`: `aviso()` (cuadre)
- Frontend: `src/components/Compras/LineasCompra.vue`, `src/helpers/compras.ts` (`avisoDeCuadre()`),
  `src/views/compras/form.vue`, `index.vue`, `src/types/compras.ts`
- Tests: `tests/Feature/CompraDetalleComprobanteTest.php`, `CompraLineasTest`, `CompraConcurrenciaTest`,
  `tests/Unit/CompraUnidadesTest.php`, `src/helpers/compras.test.ts`

**Al desplegar:** `tenants:migrate-verticales`, y leer si la migración informa líneas «sin afectación».

---

## 1. Pantalla → tabla

| Qué pasa | Ruta | Permiso | Tablas |
|---|---|---|---|
| Registrar o editar una compra con el bloque «Detallar comprobante» abierto | `POST compras` / `POST compras/{id}` | `compras.registrar` | `compras` + `compra_lineas` (y `stock_movimientos` solo por las líneas con producto que controla stock) |
| Ver el detalle | `GET compras/{id}` | `compras.ver` | `compra_lineas` con descripción, unidad, código y afectación |
| NC con líneas precargadas | `GET compras/{id}` de la original + `devolvible()` | `compras.ver` | lee las líneas de la compra que modifica |

El bloque «Detallar comprobante» es **plegable y viene cerrado**: lo normal en Agencia sigue siendo
registrar solo los montos de la cabecera.

## 2. La tabla: cuatro columnas nuevas y `product_id` opcional

`compra_lineas` suma `descripcion` (500), `unidad_medida` (código de la tabla 6 de SUNAT: NIU, ZZ,
KGM…), `codigo_proveedor` (opcional) y `tipo_afectacion_igv` (10, 20, 30 o 40). `product_id` y
`costo_unitario_base` pasan a **nullable**: una línea sin producto no tiene costo de stock.

La migración hace tres pasos, en orden:
1. agrega las columnas como nullable;
2. **completa las líneas existentes** (todas tenían producto): descripción = nombre del producto,
   unidad = la del producto (o NIU), afectación deducida — 10 si la línea tiene IGV; 20 o 30 si la
   cabecera solo tiene exonerado o solo inafecto; si no, **vacía** y la informa por pantalla;
3. recién entonces pone `descripcion` y `unidad_medida` como obligatorias.

> **Ejercicio:** ¿por qué la migración no pone 20 cuando no puede deducir la afectación? (Pista:
> «sin fallback silencioso», `CLAUDE.md`. Un 20 inventado haría cuadrar mal una factura con IGV.)

## 3. Quién mueve stock

Nada cambió en `StockService`. `prepararLineas()` separa:
- **línea con producto que controla stock** → stock y costo promedio, como en T-154;
- **línea con producto que no controla stock** → se guarda, no mueve nada;
- **línea sin producto** → descripción obligatoria, cantidad × valor unitario = valor de venta
  (Q2: igual que en el XML; un servicio va con cantidad 1), `costo_unitario_base` en null.

`firma()` y `mismasLineas()` deciden si al editar «cambiaron las líneas». Desde la revisión también
comparan descripción, unidad, código y afectación; un texto que el cliente no manda se toma de la
línea guardada.

## 4. El cuadre: aviso, no bloqueo

Las líneas **no reemplazan** la cabecera. `CompraController::aviso()` compara:
1. la suma de las líneas contra la base (gravada + exonerada + inafecta);
2. si la suma cuadra y todas tienen afectación, cada grupo contra su monto: 10 → gravado,
   20 → exonerado, 30 y 40 → inafecto.

Si algo no cuadra, la compra **se guarda igual** y la respuesta trae `aviso`. El frontend hace el
mismo cálculo antes de guardar (`avisoDeCuadre()` en `helpers/compras.ts`, en céntimos enteros),
y con el bloque cerrado lo muestra bajo el encabezado.

## 5. Las líneas viajan como texto JSON

Una línea tiene 11 campos; 100 líneas en `multipart/form-data` pasan de `max_input_vars` (1000) y
PHP corta el resto **sin error**. Por eso `form.vue` manda `lineas` como un solo texto JSON. El
backend acepta las dos formas. Trampa que encontró la revisión: el texto JSON no pasa por los
middlewares de Laravel que recortan espacios y convierten `""` en null, así que
`comoFormulario()` lo hace a mano (un `product_id: ""` daba «el producto no existe»).

## 6. El tope de la nota de crédito

Toda NC (con o sin líneas) no puede pasar de lo que queda por acreditar de su compra, y va en su
misma moneda. Del lado de la compra: con notas que la modifican no baja su total por debajo de lo
acreditado, no cambia de moneda ni de proveedor, ni se vuelve nota. Y una compra con una NC
vigente **no se anula** hasta anular la nota (decisión del usuario, 27-nov).

`cambiaLoQueAcredita()` hace que el tope se revise **solo** al crear la nota o si cambia tipo,
total, moneda o compra que modifica: una nota vieja fuera de regla se sigue pudiendo editar para
adjuntarle el XML.

> **Ejercicio:** en `CompraDetalleComprobanteTest`, busca
> `test_nota_antigua_fuera_de_tope_se_edita_si_no_cambia_lo_que_acredita` y explica qué fallaría si
> el tope se revisara en toda edición.

## 7. Lo que queda afuera (ver backlog)

- La ND (08) no amplía lo acreditable.
- Gratuitas e IVAP (afectaciones 11-17 y 21-37), antes de importar XML.
- La lista de unidades está copiada en `CompraLinea::UNIDADES` y `UNITS` (`types/products.ts`);
  `CompraUnidadesTest` falla si se separan.
