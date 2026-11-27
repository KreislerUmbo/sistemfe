# T-152 — Asignar a mano una compra a una reserva, una salida o «general»

| | |
|---|---|
| **Módulo** | COM + AGE |
| **Depende de** | T-135 (comprobante de compra) |
| **Reglas** | `docs/planning/agencia-de-viajes/plan-tours-venta-simple-costo-real.md` §3.7 (`compra_asignaciones`), §3.7d, §3.9b, §6 fase 3 |
| **Decisiones** | Q1-Q14 de «Decisiones aprobadas» (usuario, 26-nov-2026) |
| **Rama** | `feat/T-152-asignacion-compra` |

## Contexto
Una factura de compra hoy no dice a qué viaje corresponde. La agencia necesita repartir su monto
entre reservas, salidas o «general», y verla en la ficha de la reserva o de la salida. La usan
después T-143 (al llegar la factura, aplicarle los pagos a cuenta de **sus** reservas) y T-145
(repartir entre reservas la nota de crédito de comisión). Cómo quedó Compras:
recorridos `docs/aprendizaje/95-…` y `96-…`.

## Estado del código al empezar
Verificado el 26-nov-2026 en `main` (`c2477245`).

**Compras (sistema base)**
- No existe nada de asignación: ni tabla, ni modelo, ni ruta, ni pantalla.
- `app/Models/Compras/Compra.php`: `total` decimal(14,2) en la moneda del comprobante, `moneda`, `tipo_cambio`, `anulada_at`; `TIPOS_NOTA`, `scopeVigentes()`, `estaAnulada()`.
- `app/Services/Compras/CompraService.php`: `registrar()` (~48), `editar()` (~53), `anular()` (~59), `guardar()` (~101). `guardar()` y `anular()` bloquean en este orden: compra → periodo → productos. `editar()` permite cambiar los montos y el total.
- `app/Http/Controllers/Compras/CompraController.php`: `index()` (~30), `filtrar()` (~125), `respuesta()` (~171), `datos()` (~212). Sin Policy de fila.
- `app/Models/Compras/CompraPeriodo.php`: `bloquear()`; un periodo cerrado no admite cambios en sus compras.
- Rutas: `routes/api.php` ~416, grupo `modulo:compras`, permisos `compras.ver|registrar|anular|cerrar_periodo`.
- Pantallas: `admin-start-kit/src/views/compras/index.vue` (listado y modal de detalle, abre con `?ver=<id>`), `form.vue`, `types/compras.ts`, `helpers/compras.ts`.

**Reserva y salida (Agencia)**
- `app/Models/AgenciaViajes/Reserva.php`, tabla `reserva` (singular). Se identifica con `codigo`, que es **nullable**: las antiguas usan `alternativa.cotizacion.codigo` (así lo hace `SalidaOperativaController::resumenSalida()` ~657). Cliente: `alternativa.cotizacion.cliente`. Fecha: `fecha_viaje_desde/hasta` de la reserva. `estado`: `activa` | `cancelada`.
- `app/Policies/ReservaPolicy.php`: sin `reservas.ver_todas`, solo las propias. Ojo: `Cotizacion` tiene un scope global por vendedor.
- `app/Models/AgenciaViajes/SalidaOperativa.php`, tabla `salidas_operativas`. **No tiene código**: se identifica por tour (`tourOrigen.nombre`) + `fecha` (+ `hora`, y `privada_reserva_id` si es privada). Estados: programada, confirmada, realizada, cancelada.
- **Las salidas se borran:** `SalidaOperativa::eliminarSiQuedoVacia()` (~138) la elimina cuando se queda sin líneas. Se llama desde `ReservaController` (~478, ~951), `ReservaItemController` (~93), `ReservaItemQuitarService` (~35) y `SalidaOperativaController` (~333, ~544, ~564).
- **Reprogramar una salida completa** (`SalidaOperativaController::reprogramar()` ~262) crea o reutiliza **otra** salida, pasa la configuración con `llevarConfiguracion()` (~433) y borra la vieja.
- Fichas: `ReservaController::show()` (~223, `respuestaDetalle()` ~1352) → `views/agencia-viajes/reservas/detalle.vue` (pestañas ~233). `SalidaOperativaController::show()` (~95) → `views/agencia-viajes/salidas/index.vue` (`abrirDetalle()` ~498, ruta `agencia.salidas.detalle`).

**Plataforma**
- Giro: `app/Services/Tenancy/GiroActual.php` (`es('agencia_viajes')`).
- Migraciones: `core/` corre antes que `verticals/agencia-viajes/` (`TenantProvisioningService`), así que una tabla del vertical puede tener FK a `compras`.
- `app/Services/AgenciaViajes/LimpiarOperacionPrueba.php`: toda tabla debe estar en una de sus dos listas.

## Alcance
- Tabla `compra_asignaciones` en `verticals/agencia-viajes/` (Q1): `compra_id`, `destino` (`reserva` | `salida` | `general`), `reserva_id` y `salida_operativa_id` nullable con FK **sin cascada**, `monto` decimal(14,2) en la moneda de la compra, `user_id`, timestamps. `CHECK`: el id presente corresponde al destino. Un mismo destino no se repite en una compra.
- Guardar el reparto **completo** de una compra de una vez (reemplaza el anterior): lista vacía = «sin asignar»; con filas, cada monto > 0 y la suma **igual al total** (Q2, Q3).
- Acción «Asignar» aparte del formulario de la compra, desde el listado y el detalle (Q5), con buscador de reservas (código, cliente) y de salidas (tour, fecha) y el botón «General».
- El detalle de la compra muestra su reparto, o «Sin asignar».
- Ficha de la reserva y ficha de la salida: bloque «Compras» con fecha, tipo, serie-número, proveedor, monto asignado a ese destino, total del comprobante y enlace a la compra. Solo compras vigentes.
- NC y ND se asignan igual que cualquier compra, con montos positivos; el modal propone los destinos de la compra que modifican (Q7).
- Editar el total o la moneda de una compra con reparto se rechaza (Q5, Q13). Anular conserva las filas, visibles en el detalle de la compra, y la saca de las fichas (Q8).
- Una salida con asignaciones no se borra; al reprogramar la salida completa, las asignaciones pasan a la nueva (Q9).
- Método de lectura para T-143 y T-145: las reservas asignadas a una compra con su monto, y las compras vigentes asignadas a una reserva.
- Agregar `compra_asignaciones` a `LimpiarOperacionPrueba` (ver Hallazgos).

## Fuera de alcance
- Reparto sugerido (P6) y criterios «por pasajeros» o «partes iguales»: fase 4.
- Reportes de rentabilidad y estimado contra real.
- Ítem del Incluye o categoría, y cantidad de pasajeros (Q4).
- Pagos y pagos a cuenta (T-136, T-143); comisiones (T-145); gastos sin comprobante; Rendiciones.
- Asignar por línea de producto (T-154): el reparto es por monto del comprobante.
- Asignación o centro de costo en giros que no son Agencia (Q1).
- Filtro «sin asignar» en el listado de compras.

## Dónde tocar
**Backend (`api-sistema-fe/`)**
- Nueva migración `database/migrations/tenant/verticals/agencia-viajes/…_create_compra_asignaciones_table.php`
- Nuevo `app/Models/AgenciaViajes/CompraAsignacion.php`
- Nuevo `app/Services/AgenciaViajes/CompraAsignacionService.php` (rechazos con `CompraException`)
- Nuevo `app/Http/Controllers/AgenciaViajes/CompraAsignacionController.php`: `PUT compras/{id}/asignaciones`, `GET compras/destinos?tipo=&search=`, `GET reservas/{id}/compras`, `GET salidas-operativas/{id}/compras`
- `routes/api.php` ~416, grupo `modulo:compras` (rutas fijas antes de `compras/{id}`)
- `CompraController::respuesta()` ~171: reparto en el detalle, solo en Agencia
- `CompraService::guardar()` ~101: no cambiar el total si hay reparto (sin acoplar el sistema base a Agencia fuera de `GiroActual`: la tabla no existe en otros giros)
- `SalidaOperativa::eliminarSiQuedoVacia()` ~138 y `SalidaOperativaController::reprogramar()` ~262
- `LimpiarOperacionPrueba` (listas de tablas)

**Frontend (`admin-start-kit/src/`)**
- Nuevo `components/Compras/AsignarCompra.vue` (modal)
- Nuevo `components/AgenciaViajes/ComprasAsignadas.vue` (bloque de las dos fichas)
- `views/compras/index.vue` (acción y detalle), `types/compras.ts`, `helpers/compras.ts` (suma en céntimos: `enCentimos()`)
- `views/agencia-viajes/reservas/detalle.vue` (~233) y `views/agencia-viajes/salidas/index.vue` (modal de detalle)

## Criterios de aceptación
Tests en `tests/Feature/AgenciaViajes/CompraAsignacionTest.php`.

- [ ] **CA1** — Una compra de 1 000 repartida en dos salidas (600) y una reserva (400) se guarda, y `GET compras/{id}` devuelve las tres filas → `test_reparte_entre_salidas_y_reserva`
- [ ] **CA2** — Si la suma es 999.99 o 1 000.01 → 422 con el total y la suma en el mensaje; queda el reparto anterior → `test_la_suma_debe_ser_el_total`
- [ ] **CA3** — Monto 0 o negativo, destino repetido, `reserva` sin `reserva_id`, `general` con id, o id inexistente → 422 → `test_rechaza_filas_invalidas`
- [ ] **CA4** — Guardar una lista vacía deja la compra «sin asignar»; todo el total a «general» es válido → `test_sin_asignar_y_general`
- [ ] **CA5** — Compra en USD: los montos se guardan en USD y las fichas los muestran con su moneda → `test_asignacion_en_dolares`
- [ ] **CA6** — `GET reservas/{id}/compras` y `salidas-operativas/{id}/compras` traen solo las vigentes, con el monto asignado a ese destino → `test_fichas_muestran_solo_su_parte`
- [ ] **CA7** — Anular una compra con reparto funciona, conserva las filas y la quita de las fichas; asignar una anulada → 422 → `test_anulada`
- [ ] **CA8** — Con el periodo de anotación cerrado se puede cambiar el reparto (Q6) → `test_periodo_cerrado_permite_reasignar`
- [ ] **CA9** — Editar el total o la moneda de una compra con reparto → 422 «quita el reparto antes»; editar la serie o las observaciones sí se puede → `test_no_cambia_el_total_con_reparto`
- [ ] **CA10** — Una NC se asigna a dos reservas, y el método de lectura devuelve ambas con su monto → `test_nota_de_credito_entre_reservas`
- [ ] **CA11** — Reservas canceladas, salidas canceladas o realizadas se pueden asignar → `test_destinos_cancelados_o_realizados`
- [ ] **CA12** — Al quitar la última línea de una salida con asignaciones, la salida no se borra; sin asignaciones, se borra como hoy → `test_salida_con_compras_no_se_borra`
- [ ] **CA13** — Al reprogramar la salida completa, sus asignaciones pasan a la salida nueva; si ya había una fila de esa compra para la nueva, se suman los montos. Si no se movió ninguna reserva, se quedan → `test_reprogramar_salida_lleva_las_compras`
- [ ] **CA14** — Sin `compras.registrar` → 403 al guardar; sin `compras.ver` → 403 en las fichas; módulo apagado → 403 → `test_permisos_y_modulo`
- [ ] **CA15** — Sin `reservas.ver_todas` se puede buscar y repartir entre reservas de cualquier vendedor; la ficha de una reserva ajena → 403 (Q12) → `test_reparte_reservas_de_otro_vendedor`
- [ ] **CA15b** — Cada fila guarda quién la ingresó: al volver a guardar, las filas sin cambios conservan su usuario (Q12) → `test_guarda_quien_ingreso_cada_fila`
- [ ] **CA16** — En un tenant que no es Agencia, las rutas nuevas responden 404, y registrar, editar y anular compras sigue igual → `ComprasMigracionesPorGiroTest` + `test_otro_giro`
- [ ] **CA17** — `LimpiarOperacionPrueba::tablasSinClasificar()` no incluye `compra_asignaciones` → `test_limpieza_clasifica_la_tabla`
- [ ] **CA18** (manual, `agencia-demo`) — Registrar una factura, asignarla, verla en la ficha de la reserva y en la de la salida, volver a la compra desde el enlace. En un tenant retail no aparece «Asignar».

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: `modulo:compras` + permiso en todas; `ReservaPolicy::view` en la ficha de la reserva (la seguridad de fila depende de llamarla en el controller). Asignar y buscar destinos no filtran por vendedor (Q12).
- [ ] Aislamiento de tenant: todo en la base del tenant; sin consultas centrales nuevas.
- [ ] Validación de entrada / archivos: `destino` con lista cerrada, ids existentes, `decimal:0,2`, tope de filas (100, mismo criterio que las líneas de T-154). Sin archivos.
- [ ] Concurrencia (`lockForUpdate`): bloquear la compra antes de reemplazar el reparto (serializa con `editar` y `anular`); la suma se valida contra el total **bloqueado**. Bloquear también las salidas de destino para que no se borren a la vez.
- [ ] Dinero en centavos: suma con `BigDecimal`; en pantalla, `enCentimos()`. No mueve caja ni saldos.
- [ ] Fechas con `HoraPeru`: no hay «hoy» nuevo; las fechas mostradas son de calendario.
- [ ] Tributario sin fallback silencioso: no aplica (no calcula impuestos ni costo).
- [ ] Sin `v-html` sin sanitizar: proveedor, cliente y tour como texto.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí.

## Cómo verificar
```
php artisan test --filter=CompraAsignacionTest
php artisan test --filter=Compra
php artisan test --filter=Salida
```
Pantalla: `agencia-demo` con el módulo «Compras» encendido y una reserva con salida programada.

## Decisiones aprobadas
Usuario, 26-nov-2026.

- **Q1 — Otros giros:** sin tabla ni pantalla; toda su compra es general de forma implícita.
- **Q2 — Opcional:** sin filas = «sin asignar», distinto de «general». Con filas, suman el total exacto.
- **Q3 — Qué se reparte:** la compra se registra igual que el comprobante que envía el proveedor, y se reparte su **total** (con IGV), en su moneda. La base y el IGV no se reparten ni se recalculan: ya quedan en la compra.
- **Q4 — Ítem del Incluye y pasajeros:** quedan para la fase 4.
- **Q5 — Dónde:** acción «Asignar» aparte del formulario. Con reparto, el total no se cambia hasta quitarlo.
- **Q6 — Periodo cerrado:** el reparto sí se puede cambiar.
- **Q7 — NC y ND:** reparto propio con montos positivos; se proponen los destinos de la compra que modifican, sin guardar hasta confirmar.
- **Q8 — Anular:** no se bloquea; las filas se conservan, se ven en el detalle de la anulada y salen de las fichas.
- **Q9 — Salidas:** una salida con asignaciones (también de compras anuladas) no se borra. Al reprogramarla completa y moverse alguna reserva, las asignaciones pasan a la nueva. Mover o quitar líneas sueltas no mueve asignaciones.
- **Q10 — Permisos:** asigna `compras.registrar`; las fichas piden además `compras.ver`; la ficha de la reserva respeta `ReservaPolicy` (para asignar, ver Q12). Las salidas no tienen dueño.
- **Q11 — Comprobantes y destinos:** todos los tipos, con o sin líneas; reservas en cualquier estado, también de venta directa o con facturación externa; salidas en cualquier estado.
- **Q12 — Reservas de otro vendedor:** cualquiera con `compras.registrar` reparte entre cualquier reserva (el buscador muestra código y cliente de todas), para ganar tiempo. Se guarda el usuario que ingresó cada fila y se ve en el detalle de la compra. La ficha de una reserva ajena sigue cerrada.
- **Q13 — Moneda:** con reparto tampoco se cambia la moneda.
- **Q14 — Montos propuestos en NC/ND:** vacíos; con un solo destino, el total de la nota.

---

## Resultado
<!-- Lo completa quien construye, al terminar. -->
- **Commits:** en `feat/T-152-asignacion-compra` (ver `git log main..`).
- **Tests:** nuevos `tests/Feature/AgenciaViajes/CompraAsignacionTest.php` (18, CA1-CA17 y CA15b); ajustados `CompraTest` (rutas nuevas) y `ComprasMigracionesPorGiroTest` (la tabla solo en Agencia). Suite completa: 2530 de 2532; los 2 que fallaron eran el escenario simulado de `ProveedoresTablaBaseTest` (borraba `compras` dejándola como migrada). Ajustado el test, pasan ese archivo, `CompraAsignacionTest` y `ComprasMigracionesPorGiroTest` (23); la suite completa no se volvió a correr después del ajuste. `type-check`, `build` y Vitest (366) en verde.
- **Prueba en pantalla (CA18, `agencia-demo`):** factura de S/ 1 000 repartida entre una reserva, una salida y «general»; con S/ 999.99 el botón queda bloqueado; se ve en el detalle y en las dos fichas; el enlace vuelve a la compra; una NC propone los mismos destinos con montos vacíos. La factura y la NC se registraron por la API (el formulario no cambió). Los datos de prueba se borraron y el módulo quedó apagado, como estaba.
- **Sin verificar en pantalla:** que en un tenant retail no aparezca «Asignar» (no hay credencial documentada de uno). Lo cubre `test_otro_giro` (`permite_asignar` = false, rutas en 404).
- **Desviaciones de la tarea y por qué:**
  - CA15 y Q10 cambiaron antes de construir, por decisión del usuario (Q12-Q14).
  - El permiso `reservas.ver|ver_todas` de las fichas se comprueba en el controller y no en la ruta, para que otro giro reciba 404 y no 403.
  - El detalle de la compra muestra el reparto con código y cliente a quien tenga `compras.ver`, aunque la reserva sea de otro vendedor (consecuencia de Q12).
- **No corrido:** `/code-review` y `/security-review`.
- **Al desplegar:** `php artisan tenants:migrate-verticales`.

## Hallazgos fuera de alcance
- Cuando un test de migraciones falla a mitad (`ProveedoresTablaBaseTest`, `ComprasMigracionesPorGiroTest`), su tenant descartable queda en `sistemafe_test_migrations` y hace fallar las corridas siguientes. Se borraron los 4 que dejó esta tarea; hay 14 más antiguos.
- Una salida que queda sin reservas pero con compras sigue en el tablero con 0 pasajeros (es lo que pide Q9). Si se junta mucha, puede convenir marcarla o cerrarla de otra forma.
- `LimpiarOperacionPrueba` no nombra `compras`, `compra_lineas`, `compra_periodos` ni `stock_movimientos` en ninguna de sus listas: según `motivosParaNoLimpiar()`, el comando se negaría a limpiar un tenant que tenga esas tablas. Visto al leer el código, sin ejecutarlo. Al construir T-152 hay que clasificar `compra_asignaciones` y, como apunta a `reserva` y `salidas_operativas` (que se borran), va en las que se borran; clasificar las otras cuatro es una decisión aparte.
