# Recorrido: cuentas por pagar

27-nov-2026, tarea **T-137** (todos los giros). T-136 dejó saldo y estado de pago en cada compra,
pero solo se veían compra por compra. Ahora hay una pantalla que responde **a quién le debo,
cuánto, qué venció y qué vence pronto**, con los pagos «a cuenta» que todavía no se aplicaron.
Es solo lectura: **no hay tablas nuevas ni otro cálculo de saldo**. La fecha de corte («deuda al
31-oct») es T-164 y se apoya en este recorrido.

Lo que se aprende acá:
- **reutilizar un cálculo en vez de copiarlo**: todo sale de `saldos()` (recorrido 99 §3);
- **agrupar por empresa y no por ficha**, y por qué la ficha sigue importando;
- **consultas por bloques** para no pasar el límite de parámetros de PDO;
- **dinero por moneda**, sin sumar soles con dólares;
- **un Excel que no ejecuta fórmulas** escritas en un nombre.

Archivos:
- `app/Services/Compras/CuentasPorPagarService.php`: `resumen()`, `detalle()`, y por dentro
  `calcular()` y `agrupar()`
- `app/Services/Compras/PagoProveedorService.php`: `vencimiento()` y `sinAplicarPorProveedor()`
  (nuevos); `saldos()` **sin cambios**
- `app/Models/Proveedor.php`: `claveDocumento()` (antes dentro de `CompraService`)
- `app/Http/Controllers/Compras/CuentasPorPagarController.php`, `app/Exports/CuentasPorPagarExport.php`
- Frontend: `src/views/compras/cuentas-por-pagar.vue`; `RegistrarPago.vue` (prop `proveedor`);
  tipos en `src/types/pagosProveedor.ts`
- Tests: `tests/Feature/Compras/CuentasPorPagarTest.php`

**Al desplegar:** `MenuItemsSeeder` (lo corre `deploy.sh`) para la entrada «Cuentas por pagar», y
limpiar la caché del menú. No hay migraciones.

---

## 1. Pantalla → tabla

| Qué pasa | Ruta | Permiso | Tablas |
|---|---|---|---|
| Compras → Cuentas por pagar | `GET compras/cuentas-por-pagar` (`dias`, `search`, `solo_vencido`, `page`) | `compras.ver` | lee `compras`, `pagos_proveedor`, `aplicaciones_pago_proveedor`, `proveedores` |
| Abrir un proveedor | `GET compras/cuentas-por-pagar/detalle?clave=…` | `compras.ver` | las mismas |
| Botón Excel | `GET compras/cuentas-por-pagar/excel` | `compras.ver` | las mismas |
| «Registrar pago» / «Aplicar pago a cuenta» | las rutas de T-136 (`POST compras/pagos`, `…/aplicaciones`) | `compras.pagar` | recorrido 99 |

## 2. Decisiones del usuario (27-nov-2026)

- **D1 — Una fila por RUC, con sus fichas dentro.** `proveedores.numero_documento` no es único: dos
  fichas pueden tener el mismo RUC (dos hoteles de la misma empresa). La fila suma por RUC; al
  abrirla se ve cada ficha, porque un pago **solo se aplica a compras de su misma ficha**
  (`crearAplicaciones()` compara `proveedor_id`). Por eso «Registrar pago» va en cada ficha.
- **D2 — Lo por aprobar no baja la deuda.** Lo que espera aprobación en Caja todavía se debe (puede
  rechazarse): va en su columna. `estados()` y el badge de la compra no cambian.
- **D3 — A cuenta y a favor aparte, con un neto**, siempre por moneda.
- **D4 — Entran** pagar y aplicar desde la pantalla, Excel y antigüedad. La fecha de corte, T-164.

La clave del grupo es el **documento actual de la ficha** (`Proveedor::claveDocumento()`:
mayúsculas, sin espacios; sin documento, `id:<n>`), no `compras.clave_proveedor`. Los pagos solo
tienen `proveedor_id`: con la clave de la ficha, compras y pagos caen en el mismo grupo aunque el
RUC de la ficha se haya corregido después.

## 3. Las columnas

Entran las compras vigentes que no son nota de crédito. Vencimiento:
`PagoProveedorService::vencimiento()` = `fecha_vencimiento`, o la emisión si es al contado (la
misma regla de `estados()`, ahora en un solo método). `hoy = HoraPeru::hoyTexto()`.

| Columna | Cálculo |
|---|---|
| Pendiente | Σ `saldo` de las compras con saldo > 0 |
| Vencido | lo pendiente con vencimiento < hoy |
| Vence en N días | lo pendiente con hoy ≤ vencimiento ≤ hoy + N (`dias`, 7 por defecto, 1 a 365) |
| 1-30, 31-60, 61-90, +90 | lo vencido según los días de atraso; las cuatro suman Vencido |
| Por aprobar | Σ `monto` de los pagos en `pendiente_aprobacion` (aplicado o no) |
| A cuenta | Σ (monto − aplicado activo) de los pagos vigentes |
| A favor | Σ (−saldo) de las compras con saldo < 0 (NC después de pagar; reembolso: T-140) |
| Neto | Pendiente − A cuenta − A favor (negativo = el proveedor nos debe) |

Un grupo aparece si alguna cifra es distinta de cero. Todo se suma con `BigDecimal` y se pasa a
texto con `toScale(2)` recién al responder (`monedasATexto()`), donde también se calcula el neto.

> **Ejercicio:** ¿por qué «Por aprobar» no se resta en el Neto? (Pista: D2 y qué pasa si Caja
> rechaza el egreso.)

## 4. Cómo se calcula sin copiar el saldo

`calcular()`:
1. Trae las compras que entran (solo las columnas necesarias).
2. Llama a `saldos()` **por bloques de 1000 ids** (`array_chunk`). PostgreSQL acepta como mucho
   65.535 parámetros por consulta: un `whereIn` con todas las compras de un tenant grande fallaría.
3. Reparte cada saldo en su columna, por `proveedor_id` y moneda.
4. Suma «a cuenta» y «por aprobar» con **una** consulta (`sinAplicarPorProveedor()`): los pagos no
   anulados con lo aplicado de cada uno (`leftJoinSub`), agrupados por proveedor, moneda y estado.

`agrupar()` junta las fichas por `claveDocumento()` y suma sus cifras por moneda. La cantidad de
consultas no depende de cuántas compras o proveedores haya: `test_sin_n_mas_1` cuenta las consultas
con 3 y con 30 compras y exige el mismo número.

`detalle()` hace lo mismo solo para las fichas de una clave, y agrega las compras con saldo ≠ 0 (con
su badge de `estados()`) y los pagos con algo a cuenta o por aprobar.
`test_detalle_usa_el_mismo_saldo` comprueba que cada saldo es el mismo que muestran la ficha de la
compra y `compras-pendientes`.

> **Ejercicio:** cada carga recorre todas las compras vigentes del tenant, también las pagadas.
> ¿Qué consulta previa permitiría pasarle a `saldos()` solo las que pueden tener saldo, sin
> escribir un segundo cálculo del saldo? (Está anotado en «Por analizar».)

## 5. Monedas

Las cifras viven en `$cifras[proveedor_id][moneda]`: soles y dólares nunca se suman. La pantalla
muestra una línea por moneda dentro de cada proveedor, y el pie, un total por moneda (soles
primero). Convertir es T-140.

## 6. El Excel

`CuentasPorPagarExport`: una fila por grupo × moneda y un total por moneda al pie. Dos detalles:
- **Documento, razón social y fichas se escriben como texto** (`bindValue()` con
  `TYPE_STRING`): un proveedor llamado «=1+1» no se convierte en fórmula, y un RUC no pierde
  ceros. Los encabezados también («+90» se leía como el número 90).
- `WithStrictNullComparison`: sin él, los ceros salen como celdas vacías.

Los montos pasan a número recién al escribir la celda, ya redondeados por `BigDecimal`.

## 7. Pagar desde la pantalla

`RegistrarPago.vue` recibe la prop `proveedor` (`{ proveedor, moneda }`), que solo se usa cuando no
viene `compra`: el pago se abre con la ficha y la moneda elegidas. «Aplicar pago a cuenta» usa
`AplicarPagoACuenta.vue` tal como en el listado de compras. Al guardar, se recargan el resumen y el
proveedor abierto. Sin `compras.pagar`, los botones no aparecen, y las rutas de pago lo exigen
igual.

## 8. Lo que queda afuera (ver backlog)

- Fecha de corte → T-164. Dólares y reembolso del saldo a favor → T-140.
- El badge «vencido» de una compra cuyo pago espera aprobación (sigue en «Por analizar»).
- Recorrer menos compras en cada carga (anotado en «Por analizar»).
