# T-109 — Permiso para cambiar el precio en la venta y validaciones en el backend

| | |
|---|---|
| **Módulo** | VEN + PLA (permiso nuevo) |
| **Depende de** | T-106 (la pantalla bloquea la casilla y muestra los motivos) |
| **Reglas** | roles Propietario (`roles:sync-propietario`); lo que la pantalla bloquea, el backend lo rechaza |
| **Decisiones** | 18-nov-2026: solo quien tiene el permiso cambia el precio en la venta; sin permiso no hay ▾ ni precio a mano. La afectación IGV por línea queda libre por ahora. `docs/requerimientos/retail-presentaciones-y-precios.md` §7. **19-nov-2026:** una venta desde cotización comercial acepta, sin el permiso, el precio cotizado de cada línea; cambiarlo respecto de la cotización sí exige el permiso |
| **Rama** | `feat/T-109-permiso-precio-validaciones` |

## Contexto
Hoy cualquiera con `register_sale` cambia el precio de una línea; no hay ningún permiso sobre el
precio. Además, la pantalla nueva (T-106) bloquea casos que el backend acepta: boleta de más de
S/ 700 sin identificar al cliente (SUNAT lo exige) y crédito a «Clientes varios».

## Alcance
- **Permiso nuevo** `cambiar_precio_venta` (migración de permisos como
  `2026_07_19_150500_add_document_emission_permissions.php`; agregar a `PermissionsDemoSeeder`). El
  Propietario lo recibe con `roles:sync-propietario`; ningún otro rol lo recibe solo.
- **Backend** (`SaleController::store()` y `update()`): sin el permiso, el precio de cada línea debe
  ser el que corresponde (`price_general`, o `price_company` si el cliente es empresa). Ojo: el
  front manda `price_base` **sin IGV** calculado desde el precio de catálogo según `include_igv`
  (`register.vue` ~1192): comparar con la misma conversión y una tolerancia de redondeo de céntimos.
  Si no coincide → 422 «No tienes permiso para cambiar el precio».
  En `update()`, las líneas que no cambiaron de precio respecto de lo guardado no se rechazan.
- **Venta desde cotización comercial** (decisión del 19-nov): `register.vue` carga cada línea con el
  precio cotizado (`agregarProducto(..., { precio: item.unit_price })`, ~1324), y hoy el payload de
  `POST sales` **no** lleva la cotización (se marca convertida después, con
  `commercial-quotes/{id}/mark-converted`, ~1060). Mandar `commercial_quote_id` en el payload; en
  `store()`, sin el permiso, una línea vale si su precio coincide con el de la línea de **esa**
  cotización para el mismo producto (misma conversión sin IGV y tolerancia). La cotización tiene
  que ser del mismo cliente y estar en un estado que se pueda convertir (ver qué valida hoy
  `mark-converted` y `for-sale`); si no, se compara contra el catálogo como cualquier venta.
- Agencia (`ReservaFacturacionController`) y adelantos (`AdvanceController`) crean la venta con
  `Sale::create` sin pasar por `SaleController::store()`: no les aplica.
- **Validaciones nuevas** (422 con mensaje claro):
  - Boleta (03) con total **mayor a S/ 700** (o su equivalente si es en dólares, con el T.C. de la
    venta) y cliente sin documento → «SUNAT pide el DNI del cliente en boletas de más de S/ 700».
  - Venta a crédito (`type_payment = 2`) con cliente sin documento → en `validarConfiguracionCredito()`.
- **Pantalla**: el login ya trae los permisos (`getAllPermissions()`); sin el permiso, la casilla de
  precio es de solo lectura y no aparece ▾; con el permiso, ▾ y precio a mano («Precio manual»).

## Fuera de alcance
- Permiso para la afectación IGV (decisión: libre por ahora).
- Listas de precios y regla mayorista (plan aparte).
- Límite de descuento por línea: ya existe (`max_discount`).

## Dónde tocar
- `api-sistema-fe/database/migrations/tenant/core/` → migración nueva de permiso
- `api-sistema-fe/database/seeders/PermissionsDemoSeeder.php`
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` → `store()` (~640), `update()`,
  `validarConfiguracionCredito()`
- Componente de líneas de T-106 (`admin-start-kit/src/components/Sales/`)

## Criterios de aceptación
- [ ] **CA1** — Sin el permiso, `POST sales` con un precio distinto del de catálogo → 422; con el
  precio de catálogo (general o empresa según cliente) → 200 → test de feature.
- [ ] **CA2** — Con el permiso, cualquier precio → 200 → test.
- [ ] **CA3** — Boleta de S/ 700.01 sin documento → 422; de S/ 700.00 → 200; con DNI → 200 → test.
- [ ] **CA4** — Crédito con cliente sin documento → 422 → test.
- [ ] **CA5** — Tras `roles:sync-propietario`, el Propietario tiene el permiso; otros roles no.
- [ ] **CA6** — Editar una venta vieja sin tocar precios, sin el permiso → 200 → test.
- [ ] **CA7** — Sin el permiso, venta desde una cotización con el precio cotizado (distinto del
  catálogo) → 200; con otro precio → 422; con una cotización de otro cliente → se compara contra el
  catálogo → test.

## Seguridad y correctitud
- [ ] Permisos: validados en backend, no solo ocultos en pantalla.
- [ ] Aislamiento de tenant: permiso en cada tenant (migración tenant).
- [ ] Dinero: comparación con tolerancia de céntimos, sin floats sueltos.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`; en deploy, `roles:sync-propietario`.
- [ ] `update()` sigue en una sola transacción.
- [ ] Lo demás: no aplica.

## Cómo verificar
```
php artisan test --filter=Sale
```
Usuario vendedor sin el permiso y Propietario, en un tenant retail.

---

## Resultado
- **Commits:** rama `feat/T-109-permiso-precio-validaciones` (ver `git log main..`).
- **Tests:** `tests/Feature/Ventas/T109PrecioYValidacionesVentaTest.php` (13 tests: CA1-CA7, más
  igv incluido con tolerancia, boleta en USD y cotización no convertible) y 1 caso nuevo en
  `ventaRetail.test.ts` (boleta en USD). Suite completa del backend (primera entrega): 2374 pasan y 1 falla
  (`Fila13Fase2cOperacionTest › en un viaje de varios dias solo se mueve el tour`, 422 en vez de 200);
  falla igual sin mis cambios, así que es previo (ver Hallazgos). Type-check:
  sin errores en los archivos tocados (los errores que quedan son previos, p. ej. `fake-backend.ts`).
  No hice prueba visual en navegador de la pantalla: queda para la revisión.
- **Desviaciones de la tarea y por qué:**
  - **Boleta en dólares:** el payload no trae tipo de cambio, así que (decisión del usuario, 19-nov)
    se convierte con el último T.C. SUNAT a hoy (`TipoCambioSunatResolver`, columna `venta`); si no
    hay ninguno cargado, no se bloquea. `GET sales/config` ahora devuelve `tipo_cambio_venta` y
    `motivoBloqueoCobro` lo usa, para que pantalla y backend coincidan (antes la pantalla solo
    bloqueaba en PEN).
  - **Cómo se calcula `price_base` hoy** (verificado en `register.vue` ~396, no ~1192): precio
    `price_company` solo si `type_client = 2` y `price_company > 0`, si no `price_general`; con
    `include_igv` se divide entre 1 + tasa (IVAP si `is_ivap = 2`, 0 si el producto es 20/30, IGV
    general en el resto); sin conversión de moneda; sin redondear. `PrecioVentaValidator` repite
    la cuenta con `BigDecimal` y tolerancia de S/ 0,01. El tipo de cliente se toma de la BD
    (`client_id`), no del payload.
  - El precio de la línea de cotización se compara tal cual contra `price_base` (el front lo pone
    sin convertir), y vale cualquiera de las líneas de esa cotización con el mismo producto.
  - La cotización solo se considera en `store()`; en `update()` no (la tarea lo dice así).
  - El 422 es un `HttpException` con `message` (como el resto de validadores del controlador), no
    un error por campo.
  - Fixture de `SaleControllerSerieComprobanteTest`: su producto tenía precio de catálogo 10 y
    vendía a 50; ahora el catálogo es 50 (la regla nueva lo habría rechazado).
- **Efecto a tener en cuenta:** la pantalla vieja `sale/edit.vue` deja editar el precio libremente;
  sin el permiso, el backend ahora rechaza ese cambio en `update()`. Se resuelve con T-110.
- **Revisión (19-nov), puntos 0 a 5:** hechos en commits separados.
  1. **Catálogo en 0 = precio libre:** `PrecioVentaValidator` no compara si el precio de catálogo que
     corresponde (general, o empresa si > 0) es 0; en pantalla `TablaLineasVenta` deja escribir el
     precio de esas líneas aunque no haya permiso (`precioEditable()`). Test incluido.
  2. **Roles administradores:** la migración (aún sin merge, por eso se editó) da el permiso a los
     roles que EXISTAN entre `Admin-General`, `Administrador de agencia` y `Admin Empresario`; no los
     crea. `AgenciaViajesRolesSeeder` lo lista en `PERMISOS` y se lo da solo al Administrador de
     agencia (provisioning nuevo). No incluí «Administrador de créditos»: ese giro no tiene
     `register_sale` y no vende por esta pantalla. En retail no hay rol administrador en los seeders.
     CA5 cubre Propietario (sync) y la migración (admins sí, vendedor y cajero no, sin crear roles).
  3. **Coherencia de importes (sin el permiso):** `importesIncoherentes()` recalcula cada línea con la
     cuenta de `updateItem()` (leída entera) y compara `mto_valor_venta`, `mto_base_igv`, `subtotal`,
     `igv`, `isc`, `icbper`, `total_impuestos`, `porcentaje_igv` y `price_final × cantidad`, con
     tolerancia de S/ 0,01 por línea (más 0,00005 × cantidad en `price_final`, que es unitario y se
     redondea a 4 decimales). Cubre gratuita 11, exonerada 20, inafecta 30, exportación 40, IVAP 17,
     ISC 01/02/03 e ICBPER; la tasa debe corresponder a la afectación elegida (que sigue libre). Los
     datos del producto (ISC, litros, `is_icbper`, `max_discount`) salen de la BD, no del payload. No
     hubo ningún tipo de línea que no se pudiera reproducir con seguridad. **Añadido que la tarea no
     pedía:** el descuento tiene que estar entre 0 y `max_discount` del producto (la pantalla ya lo
     limitaba; sin esto el descuento era una forma de rebajar el precio). En `update()`, una línea con
     `id` que no cambió en precio, cantidad, descuento ni afectación no se revisa (ventas viejas
     guardadas con otra cuenta). Con el permiso no cambia nada.
     **Fixtures:** 4 tests existentes mandaban líneas sin `total_impuestos` o con cantidad 3 y montos
     de cantidad 2 (la pantalla real siempre manda importes coherentes); se corrigieron
     (`SaleControllerSerieComprobanteTest`, `AdvanceIntegridadTest`, `T009MonedaCajaTest`,
     `DescripcionLineaVentaTest`). Tests nuevos: uno por afectación, ISC, ICBPER, `subtotal`/IGV/
     `price_final` rebajados → 422 (y con el permiso → 200), descuento sobre el máximo, y edición de
     línea sin cambios.
  4. **Backlog:** el hallazgo de `es_default` pasó a «Por analizar». `docs/backlog.md` además
     trae cambios tuyos sin commitear (T-112, T-113, próximo ID): no lo commiteé, queda para ti.
  5. **Al desplegar:** ver abajo.
- **Suite completa del backend (tras los puntos 1 a 3):** 2381 pasan y 1 falla, la misma previa
  (`Fila13Fase2cOperacionTest`).
- **Al desplegar:** `tenants:migrate-verticales` y `roles:sync-propietario`. **Antes del deploy**, en
  producción, listar los roles que tienen usuarios en market, dakamu y credishirley y confirmar que los
  nombres de la migración (`Admin-General`, `Administrador de agencia`, `Admin Empresario`) coinciden;
  si un tenant llama distinto a su administrador, esa migración no le da el permiso (no crea ni adivina
  roles) y habría que asignarlo a mano.

## Revisión (Opus, 19-nov-2026) — cambios pedidos, decididos por el usuario
Lo construido está bien (13 tests en verde; la cuenta de `PrecioVentaValidator` coincide con
`register.vue`). Antes de aprobar, en la misma rama:

0. **Commitear** lo que ya está (hoy nada está commiteado), y después cada punto de abajo.
1. **Precio de catálogo 0 = precio libre.** Si el precio de catálogo que corresponde (general o
   empresa) es 0, la línea no se valida: es un producto «a precio variable». Caso real: en Agencia
   los genéricos (Boleto Aéreo, Tour / Actividad turística, Servicio de Hotelería, Transporte, Otros
   Servicios Turísticos) tienen `price_general = 0` y siempre se venden con precio a mano; sin esto
   ningún vendedor podría venderlos. En pantalla, sin el permiso, esas líneas sí dejan escribir el
   precio. Test: sin el permiso, producto con catálogo 0 a S/ 100 → 200.
2. **Roles administradores también reciben el permiso.** En producción el rol Propietario no tiene
   usuarios todavía (T-078), así que «solo Propietario» dejaría sin el permiso a todos salvo soporte.
   La migración se lo asigna además a los roles administradores **que existan** en el tenant
   (sin crearlos): ver los nombres reales en los seeders (`AgenciaViajesRolesSeeder` y los de
   retail/créditos); conocidos: `Admin-General`, `Administrador de agencia`, `Admin Empresario`
   (dakamu). Vendedores, cajeros y demás, no. Actualizar CA5 y el test.
3. **Coherencia de importes (sin el permiso).** `store()`/`update()` guardan `price_final`,
   `subtotal`, `mto_valor_venta`, `mto_base_igv` e `igv` tal como llegan: con un request armado se
   puede mandar el `price_base` correcto y montos menores. Sin el permiso, validar también que
   esos campos de cada línea sean los que salen de `price_base`, cantidad y descuento con la
   **misma cuenta de `updateItem()`** (`register.vue` ~530): leerla entera antes y cubrir gratuita
   (11), exonerada/inafecta (20/30), IVAP, ISC e ICBPER. Tolerancia de céntimos (por línea, no por
   unidad). Si algún caso no se puede reproducir con seguridad, preguntar antes de dejarlo pasar.
   Con el permiso no cambia nada. Tests: un caso por afectación y uno con `subtotal` rebajado → 422.
4. Pasar a «Por analizar» del backlog el hallazgo de `es_default` (abajo); los demás hallazgos ya
   están ahí.
5. **Al desplegar** (actualizar en Resultado): antes del deploy, listar en producción los roles con
   usuarios de market, dakamu y credishirley para confirmar que los nombres del punto 2 coinciden.

## Revisión 2 (Opus, 19-nov-2026) — cambios pedidos
Puntos 1 a 3 de la primera revisión, bien resueltos: `importesIncoherentes()` coincide con
`updateItem()` en todas las afectaciones (20 tests en verde). Falta:

1. **Descuento global (decisión del usuario):** sin `cambiar_precio_venta`, `discount_global`
   tiene que ser 0. Hoy no tiene permiso ni límite (`SaleController.php` ~801 lo guarda tal cual;
   `TotalesVenta.vue` ~44 solo exige que no sea negativo), así que un vendedor sin el permiso baja el
   total desde la pantalla. Backend: 422 «No tienes permiso para aplicar un descuento global» en
   `store()` y en `update()` (en `update()`, si no cambió respecto de lo guardado, pasa: misma
   idea que las líneas). Pantalla: sin el permiso no aparece «+ Descuento global». Tests: sin el
   permiso → 422; sin el permiso editando una venta que ya tenía descuento sin cambiarlo → 200;
   con el permiso → 200.
2. **Descuento de línea:** `importesIncoherentes()` acepta descuento hasta `max_discount` aunque
   el producto no admita descuento (`is_discount ≠ 2`); la pantalla ni muestra el botón. Si
   `is_discount ≠ 2`, el descuento tiene que ser 0. Test.
3. ~~Commitear `docs/backlog.md`~~: lo commiteó Opus junto con esta revisión.
4. Los totales de la cabecera (subtotal, IGV, total) van en **T-114** (decisión del usuario):
   no tocarlos en T-109.

## Hallazgos fuera de alcance
- `Fila13Fase2cOperacionTest › en un viaje de varios dias solo se mueve el tour` falla en `main`
  (422 en vez de 200), sin relación con T-109. Sin analizar.
- `SaleController::config()` no devuelve `es_default` de `tax_configs`, así que
  `getIgvGeneralTasa()` de `register.vue` nunca encuentra el IGV «default» y usa siempre 18 de
  respaldo. Hoy coincide con la tasa real; si el IGV general cambiara, el front calcularía mal el
  precio sin IGV y el backend (que sí lee `es_default`) lo rechazaría. Conviene agregar la columna
  al `get([...])` de `config()`.
