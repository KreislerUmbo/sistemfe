# T-108 — Descripción editable de una línea de venta (reemplaza el nombre del catálogo)

| | |
|---|---|
| **Módulo** | VEN + NOT |
| **Depende de** | T-106 (la ventana vive en la pantalla nueva) |
| **Reglas** | XML, PDF y ticket dicen lo mismo (`SaleDetail::descripcionParaSunat()`) |
| **Decisiones** | maqueta aprobada el 18-nov-2026 (`docs/maquetas/venta-retail.html`, «Editar descripción») |
| **Rama** | `feat/T-108-descripcion-editable-linea` |

## Contexto
El producto del catálogo es genérico («Antivirus ESET NOD32 1 PC 1 año»), pero en la boleta hay que
poner el detalle de esa venta (equipo, fecha de instalación, vencimiento). El usuario pidió que el
texto editado **reemplace** al nombre, no que se agregue debajo, para que no aparezca dos veces. El
catálogo no cambia.

Hoy existe `sale_details.descripcion_detalle` (Agencia): se **agrega** al título
(`descripcionParaSunat()` = título + « - » + detalle). Agencia sigue así.

## Alcance
- **Dónde guardar el texto**: primero verificar quién escribe `sale_details.description` (columna
  casi sin uso: `SaleDetailController.php:59`, y `NoteDetailAmountCalculator.php:40,99` la copia a
  las notas). Si nadie más la usa, reutilizarla como «descripción que reemplaza al título»; si hay
  duda, columna nueva (migración en `tenant/core/`, nunca editar una existente). Decidir en la tarea
  y anotarlo en Resultado.
- **Una sola fuente**: `SaleDetail` gana un método (o amplía `descripcionParaSunat()`): si hay
  descripción de reemplazo → esa (máx. 500 caracteres, varias líneas); si no → la regla de hoy.
  La usan el XML (`GreenterService`), el PDF A4 (`a4.blade.php` ~363), el ticket
  (`ticket80mm.blade.php` ~141) y los PDF/XML de notas de crédito y débito.
- **Notas de crédito/débito**: la línea de la nota copia la descripción de la venta.
- **API**: `SaleController::store()` y `update()` aceptan el campo por línea (validar string ≤ 500).
- **Pantalla (T-106)**: enlace «Editar descripción» en cada línea → ventana con el texto (empieza
  con el nombre del catálogo), contador, «Volver al nombre del catálogo» y vista «Así sale en la
  boleta». La línea muestra el texto editado y, en chico, «Descripción editada · catálogo: …».

## Fuera de alcance
- Cambiar la regla de Agencia (`descripcion_detalle` sigue agregándose al título).
- Editar el producto del catálogo desde la venta.

## Dónde tocar
- `api-sistema-fe/app/Models/Sale/SaleDetail.php` → `descripcionParaSunat()` (~86)
- `api-sistema-fe/app/Http/Controllers/Greenter/GreenterService.php` → armado de líneas (~650)
- `api-sistema-fe/resources/views/pdf/a4.blade.php` (~363), `ticket80mm.blade.php` (~141), PDF de notas
- `api-sistema-fe/app/Services/NoteDetailAmountCalculator.php` (~40, ~99)
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` → `$campos_detalle` en store/update
- Componente de líneas de T-106 en `admin-start-kit/src/components/Sales/`

## Criterios de aceptación
- [ ] **CA1** — Una línea con descripción editada sale **solo con ese texto** en el XML, el PDF A4 y
  el ticket → test de `descripcionParaSunat()` + verificación con Ghostscript.
- [ ] **CA2** — Sin descripción editada, retail sigue mostrando el título y Agencia título + detalle
  (tests existentes en verde).
- [ ] **CA3** — Una nota de crédito sobre esa venta lleva la misma descripción → test.
- [ ] **CA4** — Más de 500 caracteres → 422 → test.
- [ ] **CA5** — El producto del catálogo no cambia.

## Seguridad y correctitud
- [ ] Validación de entrada: string ≤ 500; se escapa en Blade (`{{ }}`), nunca `{!! !!}`.
- [ ] Sin `v-html` en la pantalla.
- [ ] Migraciones nuevas (si hay) + `tenants:migrate-verticales`.
- [ ] `SaleController::update()` sigue en una sola transacción.
- [ ] Lo demás: no aplica.

## Cómo verificar
```
php artisan test --filter=SaleDetail
```
Venta en un tenant retail con una línea editada en varias líneas; PDF A4 y ticket con `gswin64c.exe`.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
