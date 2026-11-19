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
- **Commits:** ver `git log feat/T-108-descripcion-editable-linea` (una sola rama, en revisión).
- **Dónde se guarda:** columna **nueva** `descripcion_linea` (text, nullable) en `sale_details` y `note_details`
  (migración `2026_11_19_100000_…`, aplicada con `tenants:migrate-verticales`). `ConDescripcionLinea` (trait de
  modelo) decide qué cuenta como «descripción editada» (vacío = no); `SaleDetail::descripcionParaSunat()` y
  `NoteDetail::descripcionParaSunat()` la usan → XML, PDF A4, ticket y notas dicen lo mismo.
- **Tests:** `DescripcionLineaVentaTest` (10 tests: CA1 modelo/XML/PDF A4/ticket con texto escapado, CA2, CA3 con
  nota y sin texto, CA4 en store y update, CA5, conservación en `update()`, `SaleResource`) y 4 de Vitest
  (`descripcionAGuardar`). Suite completa del backend (Postgres real): 2361 pasan, 1 falla (el conocido `Fila13Fase2cOperacionTest`, también en main). `vue-tsc --noEmit` y `vite build` sin errores.
- **Prueba funcional (agencia-demo, Playwright):** línea «Boleto Aéreo» → «Editar descripción» con texto de 2 líneas
  y `<b>` → precio a mano S/ 100 → nota de venta cobrada (venta #29): se guardó el texto y el PDF A4 y el ticket
  (Ghostscript) muestran solo ese texto en dos líneas, sin el nombre del catálogo y sin interpretar el `<b>`.
  No se probó en umbo (sin credencial). La venta #29 de agencia-demo es de prueba.
- **Desviaciones de la tarea y por qué:**
  - No se reutilizó `sale_details.description`: la escribe `AdvanceController` (líneas de adelanto) y
    `SaleDetailController::store`; reutilizarla habría cambiado el XML/PDF de adelantos ya emitidos. En
    `note_details` además es `varchar(255)` y los PDF de notas ya la usan (`description ?? título`) → columna nueva.
  - `update()` guarda el campo solo si la línea trae la clave; si no la trae (edit.vue, hasta T-110) conserva la
    existente. `SaleResource` ya la expone vía `SaleDetailResource`.
  - Los PDF de nota sin texto editado conservan `description ?? título` (sin cambio).
  - «Así sale en el comprobante»: la maqueta dice «boleta»; el modal acepta el nombre por prop pero la pantalla
    no lo pasa todavía (dice «comprobante»).
  - La base de tests `sistemafe_test_migrations` se migró a mano (`DB_DATABASE=sistemafe_test_migrations php artisan
    migrate --path=database/migrations/tenant/core`).

## Hallazgos fuera de alcance
- `GreenterService::getDetallesComprobante()` llama a `descripcionParaSunat()` también con líneas de nota, y
  `NoteDetail` no tenía ese método (desde que se agregó en SaleDetail, 24-oct): el XML de cualquier nota habría
  fallado con «método indefinido». Quedó cubierto aquí al agregarlo a `NoteDetail`; conviene revisar si hay un
  test de XML de notas que lo habría detectado.
- `Fila13Fase2cOperacionTest::test_en_un_viaje_de_varios_dias_solo_se_mueve_el_tour` falla también en main
  (fechas fijas caducadas), no es de esta tarea.
