# T-137 — Cuentas por pagar: deuda con proveedores por RUC, con antigüedad, pago y Excel

| | |
|---|---|
| **Módulo** | COM |
| **Depende de** | T-136 (en `main`). **T-164** (fecha de corte) se construye después, sobre esta |
| **Reglas** | `CLAUDE.md` → «Fechas y horas» (vencido contra `HoraPeru::hoyTexto()`), «Dinero y caja» |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7 («Cuentas por pagar», «Pagos»); recorrido `docs/aprendizaje/99-recorrido-pagos-a-proveedores.md` §3 (saldo) y §6 (por aprobar); decisiones del usuario del 27-nov-2026 (abajo) |
| **Rama** | `feat/T-137-cuentas-por-pagar` (desde `main`) |

## Contexto
Desde T-136 cada compra tiene saldo y estado de pago, pero solo se ven compra por compra. Falta la
vista del dueño o del contador: **a quién le debo, cuánto, qué está vencido y qué vence pronto**,
y los pagos «a cuenta» que todavía no se aplicaron. Es una pantalla de lectura sobre el modelo de
T-136: **no hay tablas nuevas ni otro cálculo de saldo**. El saldo de cada compra sale siempre de
`PagoProveedorService::saldos()`.

**Decisiones del usuario (27-nov-2026):**
- **D1 — Una fila por RUC, con sus fichas dentro.** `proveedores.numero_documento` no es único: dos
  fichas pueden tener el mismo RUC (dos hoteles de la misma empresa). La fila suma por RUC; al
  abrirla se ve cada ficha con su deuda y sus pagos a cuenta, porque un pago **solo se aplica a
  compras de su misma ficha** (`crearAplicaciones()`, `proveedor_id`).
- **D2 — Lo por aprobar no baja la deuda.** Pendiente y vencido usan el **saldo** (lo que espera
  aprobación en Caja todavía se debe y puede rechazarse). Una columna **«Por aprobar»** lo informa.
  `estados()` y el badge de la compra **no cambian**.
- **D3 — A cuenta y a favor aparte, con un neto.** Columnas por moneda: Pendiente, Vencido, Vence en
  N días, Por aprobar, A cuenta, A favor y **Neto = Pendiente − A cuenta − A favor**. Nunca se
  convierte de moneda: soles y dólares van en filas o columnas separadas, nunca sumados.
- **D4 — Entra:** pagar y aplicar desde la pantalla, Excel y antigüedad de lo vencido (1-30, 31-60,
  61-90, más de 90 días). La **fecha de corte** («deuda al 31-oct») es **T-164**.

**Decisión de la preparación (no cambia comportamiento):** la clave de agrupación es el
**documento actual de la ficha** (`proveedores.numero_documento` normalizado, la misma regla que
`CompraService::clave()`; sin documento, `id:<proveedor_id>`), no `compras.clave_proveedor`. Así
compras y pagos (que solo tienen `proveedor_id`) caen en el mismo grupo aunque el documento de la
ficha se haya corregido después de registrar una compra.

## Definiciones (por grupo y por ficha, en cada moneda)
Compras que entran: `Compra::vigentes()` y `PagoProveedorService::sePaga()` (sin anuladas ni notas
de crédito). Vencimiento de una compra: `fecha_vencimiento`, o la emisión si es al contado (la regla
de `estados()`). `hoy = HoraPeru::hoyTexto()`.

| Columna | Cálculo |
|---|---|
| Pendiente | Σ `saldo` de las compras con saldo > 0 (incluye lo vencido) |
| Vencido | Σ `saldo` > 0 con vencimiento < hoy |
| Vence en N días | Σ `saldo` > 0 con hoy ≤ vencimiento ≤ hoy + N (`N` = parámetro `dias`, 7 por defecto, 1 a 365) |
| Antigüedad | lo **vencido** repartido por días de atraso (`hoy − vencimiento`): 1-30, 31-60, 61-90, más de 90. Las cuatro suman Vencido |
| Por aprobar | Σ `monto` de los pagos de la ficha en `pendiente_aprobacion` (aplicado o no) |
| A cuenta | Σ (`monto` − aplicado activo) de los pagos **vigentes** |
| A favor | Σ (−`saldo`) de las compras con saldo < 0 (una NC después de pagar; el reembolso es T-140) |
| Neto | Pendiente − A cuenta − A favor (negativo = el proveedor nos debe) |

Un grupo aparece si alguna de esas cifras es distinta de cero en alguna moneda. Todo con
`BigDecimal`; a texto con `toScale(2)` solo al responder.

## Alcance

### 1. Servicio `app/Services/Compras/CuentasPorPagarService.php` (nuevo)
- `resumen(int $dias, ?string $search): array` → grupos con sus fichas y las cifras de arriba por
  moneda, más los totales generales por moneda.
- `detalle(string $clave): array` → las compras del grupo con saldo ≠ 0 y los pagos con algo a
  cuenta o por aprobar, por ficha.
- **No calcula saldos:** junta los ids de las compras que entran y llama a
  `PagoProveedorService::saldos()` **por bloques de 1000 ids** (`array_chunk`), para no pasar el
  límite de parámetros de PDO con tenants grandes. A cuenta y por aprobar salen de **una** consulta
  agrupada por `proveedor_id` y `moneda` sobre `pagos_proveedor` + `aplicaciones_pago_proveedor`
  activas (método nuevo `PagoProveedorService::sinAplicarPorProveedor(): array`, que reemplaza la
  subconsulta de `PagoProveedorController::filtrar()` → `con_saldo` solo si cabe sin cambiar su
  respuesta; si no, se deja y se anota en Hallazgos).
- Sin N+1: la cantidad de consultas no crece con la cantidad de compras ni de proveedores.
- Orden de los grupos: primero los que tienen algo vencido (en cualquier moneda), después por razón
  social. Paginado en memoria (`per_page`, 15 por defecto, máximo 100).
- `search`: razón social, nombre comercial o documento de cualquiera de las fichas
  (`BusquedaLike::contiene`, `ilike`).

### 2. Dos extracciones chicas, sin cambiar comportamiento
- `PagoProveedorService::vencimiento(Compra $c): string` (público) con la regla que hoy está dentro
  de `estados()` (~línea 117); `estados()` pasa a usarlo. `CuentasPorPagarService` también.
- `Proveedor::claveDocumento(): string` (público, en `app/Models/Proveedor.php`) con la regla de
  `CompraService::clave()`/`normalizar()` (~líneas 836-847); `CompraService::clave()` pasa a
  delegar en él. `PagoProveedorTest` y los tests de compras siguen en verde sin tocarlos.

### 3. Controlador y rutas
`app/Http/Controllers/Compras/CuentasPorPagarController.php` (nuevo), dentro del grupo
`modulo:compras` + `permission:compras.ver` de `routes/api.php` (~línea 439), **antes** de
`compras/{id}`:
- `GET compras/cuentas-por-pagar` → `index()`: `dias`, `search`, `solo_vencido` (bool), `page`,
  `per_page`. `dias` fuera de 1-365 → 422.
- `GET compras/cuentas-por-pagar/detalle?clave=…` → `detalle()`. Clave inexistente o sin nada → 404.
- `GET compras/cuentas-por-pagar/excel` → `excel()`: los mismos filtros, **sin** paginar.

### 4. Excel `app/Exports/CuentasPorPagarExport.php` (nuevo)
`maatwebsite/excel` como `CashMovementsExport` (`FromArray`, `WithHeadings`,
`WithColumnFormatting` `#,##0.00`). Una fila por **grupo × moneda**: RUC/documento, razón social,
fichas (nombres separados por « / »), moneda, Pendiente, Vencido, Vence en N días, 1-30, 31-60,
61-90, +90, Por aprobar, A cuenta, A favor, Neto. Al pie, una fila de total por moneda. Nombre:
`cuentas-por-pagar-<hoy Perú>.xlsx`. Los montos se pasan a número solo al escribir la celda, ya
redondeados por `BigDecimal`.

### 5. Pantalla `admin-start-kit/src/views/compras/cuentas-por-pagar.vue` (nueva)
- Ruta `compras.cuentas_por_pagar`, `/compras/cuentas-por-pagar`, permiso `compras.ver`, en
  `src/router/routes.ts` junto a las de Compras (~línea 305).
- Menú: `MenuItemsSeeder` (~línea 113) agrega `compras.cuentas_por_pagar` «Cuentas por pagar»,
  permiso `compras.ver`, módulo Compras, orden 2; Pagos pasa a 3 y Periodos a 4.
- Filtros en card-header (estilo de `compras/pagos.vue`): buscar, «Vence en» (número, 7 por
  defecto), «Solo con vencido», botón Excel (`helpers/descargarArchivo.ts`).
- Tabla: una fila por grupo y, dentro, una línea por moneda con las columnas de arriba; Vencido en
  rojo si > 0; Neto negativo con la marca «a favor». Totales por moneda al pie.
- Al abrir un grupo (fila expandible o modal `lg`): por ficha, sus compras con saldo ≠ 0
  (comprobante, emisión, vencimiento, días de atraso, moneda, total, notas, aplicado, por aprobar,
  saldo, badge con `ESTADOS_PAGO` de `helpers/pagosProveedor.ts`) y sus pagos con algo a cuenta o
  por aprobar. Cada compra enlaza a `compras.index?ver=<id>` (ya lo soporta `index.vue`, ~línea 519).
- Con `compras.pagar`: botón «Registrar pago» por ficha y moneda, que abre `RegistrarPago.vue` con
  el proveedor y la moneda elegidos; y «Aplicar pago a cuenta» en cada compra, con
  `AplicarPagoACuenta.vue` (ya recibe `{ id, proveedor_id, moneda, saldo, por_aprobar }`). Al
  guardar, se recargan el resumen y el detalle abierto.
- `RegistrarPago.vue` (~línea 88): prop opcional nueva `proveedor?: { proveedor, moneda }`, usada
  solo cuando no viene `compra`; con ella el selector de proveedor queda elegido (editable). Lo que
  hace hoy con `compra` no cambia.
- Tipos en `src/types/pagosProveedor.ts`. La pantalla no calcula montos: los formatea.

## Fuera de alcance
- Fecha de corte → **T-164**.
- Rentabilidad («el costo cuenta en la fecha del comprobante, pagado o no», §3.7): es de la fase 4;
  esta pantalla muestra deuda, no costo.
- Cambiar `estados()` o el badge por lo que espera aprobación (sigue en «Por analizar»).
- Convertir monedas, reembolso del saldo a favor, diferencia de cambio → T-140.
- Obligaciones y cuotas de mayoristas → T-142/T-143; detracción y retención → T-163.
- Aplicar un pago de una ficha a una compra de otra ficha del mismo RUC (sigue prohibido).
- Permiso propio para la pantalla: alcanza `compras.ver`.

## Dónde tocar
- `api-sistema-fe/app/Services/Compras/PagoProveedorService.php` → `saldos()` (~45, **no se
  modifica**), `estados()` (~102), nuevos `vencimiento()` y `sinAplicarPorProveedor()`
- `api-sistema-fe/app/Services/Compras/CompraService.php` → `clave()`/`normalizar()` (~836)
- `api-sistema-fe/app/Models/Proveedor.php` → `claveDocumento()`
- `api-sistema-fe/app/Http/Controllers/Compras/PagoProveedorController.php` → `filtrar()` (~124,
  `con_saldo`), `comprasPendientes()` (~74) como ejemplo de uso de `saldos()` + `estados()`
- `api-sistema-fe/routes/api.php` (~424-466)
- `api-sistema-fe/app/Exports/CashMovementsExport.php` (modelo) y `CashMovementController::export()` (~402)
- `api-sistema-fe/database/seeders/MenuItemsSeeder.php` (~111-115)
- `admin-start-kit/src/router/routes.ts` (~274-324), `src/views/compras/pagos.vue` (estilo y
  filtros), `src/components/Compras/RegistrarPago.vue`, `AplicarPagoACuenta.vue`,
  `src/helpers/pagosProveedor.ts`, `src/helpers/descargarArchivo.ts`

## Criterios de aceptación
Tests nuevos en `api-sistema-fe/tests/Feature/Compras/CuentasPorPagarTest.php`, que extiende
`CreditosTestCase` y arma datos como `PagoProveedorTest` (`setUp()`, `compra()`, `nota()`,
`porBanco()`, `hoy()`).

- [ ] **CA1** — Dos fichas con el mismo RUC (una con espacios o minúsculas en el documento) y otra
  sin documento → dos grupos: el RUC con sus dos fichas y cifras sumadas, y `id:<n>` solo →
  `test_agrupa_por_ruc_con_sus_fichas`
- [ ] **CA2** — Con hoy congelado: compra a crédito vencida ayer, una que vence en 5 días, una en
  10 y una al contado emitida hace 3 días → con `dias=7`, Vencido = la de ayer + la de contado,
  Vence en 7 días = la de 5, Pendiente = las cuatro → `test_pendiente_vencido_y_por_vencer`
- [ ] **CA3** — A las 20:00 de Lima (ya es mañana en UTC), una compra que vence hoy **no** está
  vencida → `test_vencido_segun_la_fecha_de_peru`
- [ ] **CA4** — Antigüedad: vencidas hace 1, 30, 31, 60, 61, 90 y 91 días caen en 1-30 (2),
  31-60 (2), 61-90 (2), +90 (1) y suman Vencido → `test_antiguedad_de_lo_vencido`
- [ ] **CA5** — Pago desde Caja que queda `pendiente_aprobacion` aplicado a una compra: Pendiente
  no baja, Por aprobar = su monto, y `GET compras/{id}` sigue mostrando el mismo saldo y estado →
  `test_lo_por_aprobar_no_baja_la_deuda`
- [ ] **CA6** — Pago vigente de 1,500 con 1,000 aplicados → A cuenta 500, Neto = Pendiente − 500;
  un pago anulado no cuenta → `test_a_cuenta_y_neto`
- [ ] **CA7** — Compra pagada entera y después una NC de 200 → A favor 200, Pendiente 0, Neto −200 →
  `test_saldo_a_favor_de_una_nota`
- [ ] **CA8** — Una compra en USD y otra en PEN del mismo proveedor salen en monedas separadas y
  ningún total las suma → `test_monedas_separadas`
- [ ] **CA9** — Anuladas, notas de crédito y compras pagadas no suman a Pendiente; un proveedor con
  todo en cero no aparece; uno con solo un pago a cuenta sí → `test_que_entra_al_listado`
- [ ] **CA10** — El saldo de cada compra del detalle es igual al de `GET compras/{id}` y al de
  `proveedores/{id}/compras-pendientes` → `test_detalle_usa_el_mismo_saldo`
- [ ] **CA11** — La cantidad de consultas de `index` es la misma con 3 y con 30 compras de 3 y 10
  proveedores (`DB::enableQueryLog()`) → `test_sin_n_mas_1`
- [ ] **CA12** — `search` por RUC y por nombre comercial; `solo_vencido`; `dias=0` y `dias=400` →
  422 → `test_filtros`
- [ ] **CA13** — Sin `compras.ver` → 403; con el módulo Compras apagado → 403 →
  `test_permisos_y_modulo`
- [ ] **CA14** — El Excel trae una fila por grupo × moneda con los encabezados de §4, las mismas
  cifras que el JSON y el total por moneda (leer el archivo con PhpSpreadsheet en el test) →
  `test_excel`
- [ ] **CA15** — `PagoProveedorTest` y los demás de `tests/Feature/Compras/` siguen en verde tras las
  extracciones de §2, sin cambios en esos tests.
- [ ] **CA16** — Pantalla (Playwright o a mano, `agencia-demo`): el menú muestra «Cuentas por
  pagar»; se abre un grupo, «Registrar pago» llega con el proveedor y la moneda elegidos, se paga
  por Banco una compra y el resumen se actualiza; «Aplicar pago a cuenta» desde el detalle; el
  Excel se descarga. Sin `compras.pagar`, los botones no aparecen.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: `compras.ver` + `modulo:compras`; pagar y aplicar siguen
  en las rutas de T-136 (`compras.pagar`). Sin Policy de fila (las compras son del negocio)
- [ ] Aislamiento de tenant: solo tablas del tenant; nada central
- [ ] Validación de entrada / archivos: `dias` entero 1-365, `per_page` 1-100, `clave` string ≤ 40;
  `search` con `BusquedaLike`
- [ ] Concurrencia (`lockForUpdate`): no aplica (solo lectura; pagar usa las rutas de T-136)
- [ ] Dinero en centavos: `BigDecimal` en todo el cálculo; nunca `float` salvo al escribir la celda
  del Excel; nunca se suman monedas distintas
- [ ] Fechas con `HoraPeru`: `hoyTexto()` para vencido y días de atraso; nombre del Excel con la
  fecha de Perú
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: razón social y nombres como texto
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no hay migraciones. Al desplegar,
  `MenuItemsSeeder` (lo corre `deploy.sh`) y limpiar la caché del menú

## Cómo verificar
```
cd api-sistema-fe
php artisan test --filter=CuentasPorPagarTest
php artisan test tests/Feature/Compras
php artisan test            # suite completa contra Postgres
cd ../admin-start-kit && yarn type-check && yarn build
php artisan db:seed --class=MenuItemsSeeder   # local, para ver el menú
```
Prueba funcional en `http://agencia-demo.sistemafe.test:5173` (credencial: memoria
`reference_agencia_demo_admin_login`), con compras a crédito vencidas y por vencer, un pago a cuenta
y una NC después de pagar.

---

## Resultado
<!-- Lo completa quien construye, al terminar. -->
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
<!-- Problemas vistos y NO arreglados. Se convierten en tareas o en "Por analizar". -->
-
