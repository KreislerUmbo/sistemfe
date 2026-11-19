# Recorrido: la descripción editable de una línea de venta

19-nov-2026, tarea **T-108**. Maqueta aprobada el 18-nov: `docs/maquetas/venta-retail.html`
(«Editar descripción»). Archivos:
- Migración `database/migrations/tenant/core/2026_11_19_100000_add_descripcion_linea_to_sale_details_and_note_details.php`
- `app/Models/Concerns/ConDescripcionLinea.php` (nuevo), `SaleDetail.php`, `NoteDetail.php`
- `SaleController.php` (store/update), `NotaElectronicaController.php`, `NoteDetailAmountCalculator.php`
- PDF: `a4`, `ticket80mm`, `nota_a4`, `nota_ticket80mm`
- `admin-start-kit/src/components/Sales/ModalDescripcionLinea.vue` (nuevo), `TablaLineasVenta.vue`,
  `helpers/ventaRetail.ts` (`descripcionAGuardar()`)
- Tests: `tests/Feature/DescripcionLineaVentaTest.php` (10) y `ventaRetail.test.ts` (4 nuevos)

**Al desplegar:** backend + frontend, y `php artisan tenants:migrate-verticales` (columna nueva).
**Ojo:** este deploy también arregla el envío a SUNAT de **todas** las notas de crédito/débito (ver §5).

---

## 1. La idea

El producto del catálogo es genérico («Antivirus ESET NOD32 1 PC 1 año»), pero la boleta tiene que
decir lo de esta venta (equipo, fecha, vencimiento). El vendedor escribe un texto que **reemplaza**
al nombre en el XML, el PDF A4 y el ticket. El catálogo no cambia.

No confundir con `descripcion_detalle` (Agencia): esa **se agrega** al título («título - detalle»).
Si una línea tiene las dos, gana la editada, sola.

## 2. De la pantalla a la tabla

```
TablaLineasVenta.vue  «Editar descripción»
  └─ ModalDescripcionLinea.vue  texto (empieza con el nombre), contador /500, «Volver al nombre», vista previa
       └─ descripcionAGuardar()  vacío o igual al nombre → null; si no, texto limpio (\n), tope 500
            └─ línea.descripcion_linea  viaja en POST sales (register.vue manda la línea entera)
                 └─ SaleController::store()/update()
                      validarDescripcionesLinea()   string ≤ 500, ANTES de abrir la transacción → 422
                      descripcionLineaParaGuardar() vacío → null
                 └─ sale_details.descripcion_linea (text, nullable)
```

`update()`: si la línea **no trae** la clave, conserva el texto que tenía (la pantalla vieja
`edit.vue` no lo conoce hasta T-110); si la trae vacía, lo borra.

## 3. Una sola regla para todos los documentos

`ConDescripcionLinea` (trait de `SaleDetail` y `NoteDetail`) decide qué cuenta como «editada»:
`descripcionLineaLimpia()` (null si vacío) y `tieneDescripcionEditada()`.

| Documento | Cómo la lee |
|---|---|
| XML (venta y nota) | `GreenterService::getDetallesComprobante()` → `descripcionParaSunat()` |
| PDF A4 / ticket | `@if ($detalle->tieneDescripcionEditada())` → `nl2br(e(...))`: escapado y con saltos de línea |
| Nota de crédito/débito | la línea de la nota **copia** `descripcion_linea` al crearse (`clonarLineasTotal()`, `prorratear()`, `porMonto()`) |

`nl2br(e(...))` va con `{!! !!}` porque `e()` ya escapó: un `<b>` escrito por el vendedor sale como texto.

## 4. Por qué una columna nueva

La tarea proponía reutilizar `sale_details.description`. No se hizo: la escribe `AdvanceController`
(líneas de adelanto) y en `note_details` es `varchar(255)` y los PDF de notas ya la leen. Reutilizarla
cambiaba documentos ya emitidos.

## 5. El hallazgo: el XML de las notas estaba roto

Desde el 24-oct, `getDetallesComprobante()` llama a `descripcionParaSunat()` también con las líneas
de nota, y `NoteDetail` no tenía ese método: enviar cualquier NC/ND a SUNAT fallaba con «método
indefinido» (también en producción). T-108 agrega el método a `NoteDetail`; el test
`ca3_la_nota_de_credito_copia_la_descripcion_de_la_venta` ahora arma el XML de una nota.

## 6. Para probarlo

```
php artisan test --filter=DescripcionLineaVentaTest
```
Pantalla de venta → «Editar descripción» con dos líneas → cobrar → ver PDF A4 y ticket: solo el texto.
