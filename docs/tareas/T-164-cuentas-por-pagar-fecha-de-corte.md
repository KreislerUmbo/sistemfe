# T-164 — Cuentas por pagar a una fecha de corte («deuda al 31-oct»)

| | |
|---|---|
| **Módulo** | COM |
| **Depende de** | **T-137** (en `main`): esta tarea le agrega el corte a su servicio, pantalla y Excel |
| **Reglas** | `CLAUDE.md` → «Fechas y horas» (filtrar un instante por día con `HoraPeru::finDiaUtc()`, nunca `whereDate`) |
| **Decisiones** | recorrido `docs/aprendizaje/100-recorrido-cuentas-por-pagar.md` (D1-D4 de T-137 y definiciones de columnas, §2-§3); recorrido `docs/aprendizaje/99-recorrido-pagos-a-proveedores.md` §3, §6 y §7; decisión del usuario del 27-nov-2026 (abajo) |
| **Rama** | `feat/T-164-cuentas-por-pagar-corte` (desde `main`) |

## Contexto
T-137 muestra la deuda **a hoy**. El contador necesita la deuda **a un día pasado** (cierre de mes),
y que ese reporte no cambie después. El único cálculo de saldo es
`PagoProveedorService::saldos()`: el corte se le agrega ahí como parámetro opcional, sin crear un
segundo cálculo y sin cambiar lo que devuelve cuando no se pasa.

**Decisión del usuario (27-nov-2026) — D1: foto al corte.** El reporte al 31-oct se reconstruye con
las marcas que ya existen (`anulada_at`, `anulado_at`, `desaplicada_at`, `created_at` de la
aplicación): si en noviembre se anula un pago del 20-oct o se desaplica una aplicación, el reporte
al 31-oct **sale igual**.

## Reglas del corte
`corte` = fecha `Y-m-d` ≤ `HoraPeru::hoyTexto()`; `fin = HoraPeru::finDiaUtc(corte)`. Sin `corte`,
todo es exactamente como en T-137.

| Qué | Cuenta al corte si… |
|---|---|
| Compra (no NC) | `fecha_emision ≤ corte` y (`anulada_at` nulo o `> fin`) |
| Nota de crédito que la modifica | lo mismo: `fecha_emision ≤ corte` y no anulada a esa hora |
| Aplicación de un pago | `pagos_proveedor.fecha ≤ corte`, `aplicación.created_at ≤ fin` y (`desaplicada_at` nulo o `> fin`) |
| Estado del pago al corte | anulado con `anulado_at ≤ fin` → **no existe**; hoy `pendiente_aprobacion` → **por aprobar**; anulado después del corte con su `cash_movement` en `rejected` (rechazado en Caja o retirado mientras esperaba, recorrido 99 §6-§7) → **por aprobar**; cualquier otro (vigente hoy, o anulado después del corte sin rechazo) → **vigente** |
| Vencido y antigüedad | contra `corte` en lugar de hoy |
| A cuenta | pagos vigentes al corte con `fecha ≤ corte`, menos sus aplicaciones activas al corte |
| Reembolso (T-140) | `fecha ≤ corte`, `created_at ≤ fin` y (`anulado_at` nulo o `> fin`): suma al saldo de su compra o resta de lo «a cuenta» de su pago |

> **Desde T-140 (recorrido 101):** el saldo suma `aplicación.monto_compra` (moneda de la compra) y
> lo «a cuenta» resta `aplicación.monto` (moneda del pago); los dos difieren en una aplicación en
> otra moneda. Los reembolsos entran en los dos cálculos, como en `saldos()` y `disponibles()`.

**Límites conocidos (van en el recorrido, no se resuelven acá):**
- Un pago que se aprobó en Caja **después** del corte sale vigente al corte: ni el pago ni el
  movimiento guardan cuándo se aprobó.
- Si se editó el total de una compra después del corte, se usa el total actual (no hay historial;
  un periodo cerrado ya impide editarla).
- Un pago fechado antes del corte pero registrado después (transferencia anotada tarde) sale «a
  cuenta» al corte y no aplicado, porque su aplicación se creó después. El **Neto** es el mismo; solo
  cambia cómo se reparte entre Pendiente y A cuenta.
- Una compra registrada después del corte con emisión anterior **sí** entra (es deuda de esa fecha):
  el reporte puede crecer si se registran facturas atrasadas; nunca cambia por anular o desaplicar.

## Alcance
### 1. `PagoProveedorService`
- `saldos(array $compraIds, ?string $corte = null)`: con `corte`, las tres consultas filtran según la
  tabla de arriba (notas, aplicaciones con el estado del pago al corte — el `join` con
  `cash_movements` para el `rejected` solo cuando hay corte). Sin `corte`, las consultas son las de
  hoy, sin una condición más.
- `estados(Collection $compras, ?string $corte = null)`: vencido contra `corte ?? hoyTexto()`.
- `sinAplicarPorProveedor()` (de T-137) recibe el mismo `?string $corte`.
- Nada más llama a estos métodos con corte: compras, ficha, pagos y `compras-pendientes` siguen
  igual.

### 2. `CuentasPorPagarService` (T-137)
- `resumen()`, `detalle()` y el Excel reciben `?string $corte`; filtran las compras candidatas por la
  tabla de arriba y pasan el corte a `saldos()`, `estados()` y `sinAplicarPorProveedor()`.
- Controlador: parámetro `corte` (`date_format:Y-m-d`, `before_or_equal:` hoy de Perú) en `index`,
  `detalle` y `excel`; futuro → 422.
- Excel: el título y el nombre del archivo llevan la fecha de corte
  (`cuentas-por-pagar-al-<corte>.xlsx`).

### 3. Pantalla `compras/cuentas-por-pagar.vue`
- Filtro «Al» con `CampoFecha.vue` (vacío = hoy; máximo `hoyPeru()`), junto a los de T-137.
- Con corte, un aviso chico en el card-header («Deuda al 31/10/2026») y **sin** botones de pagar ni
  aplicar (se paga sobre la deuda de hoy).

## Fuera de alcance
- Guardar cuándo se aprobó un pago (columna nueva) → anotar en Hallazgos si se ve necesario.
- Historial de ediciones de compras.
- Corte en otras pantallas (listado de compras, pagos).

## Dónde tocar
- `api-sistema-fe/app/Services/Compras/PagoProveedorService.php` → `saldos()` (~45), `estados()`
  (~102), `sinAplicarPorProveedor()`
- `api-sistema-fe/app/Services/Compras/CuentasPorPagarService.php`,
  `app/Http/Controllers/Compras/CuentasPorPagarController.php`, `app/Exports/CuentasPorPagarExport.php`
- `api-sistema-fe/app/Services/HoraPeru.php` → `finDiaUtc()`
- `admin-start-kit/src/views/compras/cuentas-por-pagar.vue`, `src/components/CampoFecha.vue`

## Criterios de aceptación
Tests nuevos en `tests/Feature/Compras/CuentasPorPagarCorteTest.php`, con la hora congelada y los
mismos ayudantes de `CuentasPorPagarTest`. Para «pasó después del corte», mover el reloj
(`$this->hoy(...)`) entre pasos.

- [ ] **CA1** — Sin `corte`, `saldos()`, `estados()` y el resumen dan lo mismo que antes:
  `PagoProveedorTest` y `CuentasPorPagarTest` en verde sin cambios → suite
- [ ] **CA2** — Compra emitida el 15-oct, pago aplicado el 20-oct y anulado el 05-nov → al 31-oct
  Pendiente 0; hoy, Pendiente = total → `test_pago_anulado_despues_del_corte_cuenta`
- [ ] **CA3** — Aplicación del 20-oct desaplicada el 05-nov → al 31-oct sigue aplicada y no «a
  cuenta» → `test_desaplicada_despues_del_corte_sigue_aplicada`
- [ ] **CA4** — Pago del 25-oct sin aplicar, aplicado el 03-nov → al 31-oct, A cuenta = su monto y
  la compra con saldo completo; el Neto es el mismo que hoy → `test_aplicacion_posterior_al_corte`
- [ ] **CA5** — Compra emitida el 02-nov no entra al 31-oct; compra del 10-oct anulada el 05-nov sí
  entra; NC del 03-nov no baja el saldo al 31-oct → `test_que_compras_y_notas_entran_al_corte`
- [ ] **CA6** — Pago desde Caja por aprobar el 20-oct y rechazado el 02-nov → al 31-oct aparece en
  Por aprobar y no baja Pendiente; hoy no aparece → `test_rechazado_despues_del_corte_estaba_por_aprobar`
- [ ] **CA7** — Una compra que venció el 25-oct está vencida al 31-oct con 6 días de atraso
  (antigüedad 1-30); una que vence el 05-nov no está vencida al 31-oct → `test_vencido_contra_el_corte`
- [ ] **CA8** — `corte` mañana (en hora de Perú) → 422; a las 20:00 de Lima, `corte` = hoy de Perú
  se acepta → `test_corte_no_puede_ser_futuro`
- [ ] **CA9** — El Excel con `corte` trae las mismas cifras que el JSON con ese corte y el nombre con
  la fecha → `test_excel_al_corte`
- [ ] **CA10** — Pantalla: con «Al» elegido, el aviso aparece, los botones de pagar y aplicar no, y el
  Excel baja con la fecha de corte; al vaciar «Al» vuelve todo lo de T-137.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas (parámetro en las de T-137)
- [ ] Aislamiento de tenant: no aplica (mismas tablas del tenant)
- [ ] Validación de entrada / archivos: `corte` fecha válida y no futura en hora de Perú
- [ ] Concurrencia (`lockForUpdate`): no aplica (lectura). `crearAplicaciones()` sigue llamando a
  `saldos()` **sin** corte
- [ ] Dinero en centavos: `BigDecimal`, como T-137
- [ ] Fechas con `HoraPeru`: `finDiaUtc(corte)` para los instantes (`created_at`, `anulado_at`,
  `anulada_at`, `desaplicada_at`); fechas de negocio (`fecha`, `fecha_emision`) se comparan directo;
  nunca `whereDate`
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no hay

## Cómo verificar
```
cd api-sistema-fe
php artisan test --filter=CuentasPorPagar
php artisan test tests/Feature/Compras
php artisan test            # suite completa contra Postgres
cd ../admin-start-kit && yarn type-check && yarn build
```
Prueba funcional en `agencia-demo`: deuda a hoy y al último día del mes anterior; anular un pago del
mes anterior y comprobar que el reporte al corte no cambia.

---

## Resultado
<!-- Lo completa quien construye, al terminar. -->
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
<!-- Problemas vistos y NO arreglados. Se convierten en tareas o en "Por analizar". -->
-
