# T-008 — Eliminar una venta anula sus movimientos de caja

| | |
|---|---|
| **Módulo** | VEN + CAJ |
| **Depende de** | ninguna |
| **Reglas** | — (Caja aún sin `reglas.md`; regla vigente: "ningún movimiento de dinero es silencioso", `docs/planning/retail-facturacion-core/plan-modulo-caja.md`) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 1, "Eliminar una venta (R1)" |
| **Rama** | `fix/T-008-eliminar-venta-anula-caja` |

## Contexto
`SaleController::destroy()` borra (soft delete) una venta no enviada a SUNAT, pero sus
`cash_movements` tipo `sale_payment` siguen vivos: la caja espera al cierre un dinero que ya no
corresponde a ninguna venta. Pasa hoy en retail en todos los giros y pasaría en Agencia en cuanto
exista el cobro al contado (T-011). Revisión de fallos R1.

## Alcance
- Reproducir primero con un test que falle: venta con pago en efectivo → eliminar → el
  movimiento sigue vivo y el esperado de la sesión no cambia.
- En `destroy()`, antes de la transacción: resolver los movimientos activos de la venta
  (`reference_type='sale'`, `type='sale_payment'`, `corrected_by` nulo) con **las mismas reglas
  que `prepararSincronizacionCaja()`**: si la sesión original está cerrada hace falta
  `cash.close_others_session` (403); la corrección se registra en la sesión abierta **actual** de
  quien elimina (422 si no tiene caja abierta). Si la venta no tiene movimientos, no se pide caja.
- Dentro de la transacción: `CashCorrectionService::revertirMovimiento()` por cada movimiento,
  junto con el borrado de la venta y de `reserva_ventas`.
- Reusar la lógica en vez de copiarla: si conviene, extraer de `prepararSincronizacionCaja()` la
  parte "resolver sesión destino + permiso" a un método compartido. Sin cambiar su comportamiento
  en `update()`.

## Fuera de alcance
- El servicio compartido de cobro (T-010).
- Devolver dinero por una NC (está en "Por analizar").
- Cambiar los otros guards de `destroy()` (XML/CDR, adelanto aplicado).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` (`destroy()`, `prepararSincronizacionCaja()`)
- `api-sistema-fe/app/Services/CashCorrectionService.php`
- `api-sistema-fe/tests/Feature/...` (buscar los tests de Caja fase 3 de `update()` y sumar ahí o al lado)

## Criterios de aceptación
- [ ] **CA1** — Dada una venta con pago en efectivo en una sesión abierta, cuando se elimina,
  entonces su movimiento queda corregido (`corrected_by` lleno), existe el movimiento de
  corrección y `ExpectedCashCalculator::compute()` vuelve al valor previo a la venta. → test nuevo
- [ ] **CA2** — Venta con pago mixto (efectivo + Yape): se corrigen los dos movimientos. → test nuevo
- [ ] **CA3** — Si la sesión original ya cerró y el usuario no tiene `cash.close_others_session`:
  403 y la venta **no** se elimina. Con el permiso: se elimina y la corrección cae en la sesión
  abierta actual. → test nuevo
- [ ] **CA4** — Sin caja abierta y con movimientos que corregir: 422 y no se elimina nada. → test nuevo
- [ ] **CA5** — Venta sin pagos (a crédito sin inicial, o de reserva sin cobro): se elimina sin
  pedir caja, igual que hoy. → test nuevo
- [ ] **CA6** — El test de CA1 falla sin el fix (anotarlo en "Resultado").

## Seguridad y correctitud
- [ ] Permisos: reusa `cash.close_others_session`; sin rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: los 403/422 se lanzan **antes** de abrir la transacción
- [ ] Concurrencia: `lockForUpdate()` sobre la venta al eliminar (dos usuarios eliminando o
  editando a la vez no deben corregir dos veces el mismo movimiento)
- [ ] Dinero: la corrección copia el monto del original, sin recalcular
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=Eliminar
php artisan test   # suite completa, contra PostgreSQL
```
Prueba funcional en `umbo` local: abrir caja, registrar venta en efectivo, eliminarla, ver la
sesión de caja (el esperado vuelve atrás y el historial muestra la corrección).

---

## Resultado
- **Commits:** rama `fix/T-008-eliminar-venta-anula-caja` (ver `git log`).
- **Tests:** 6 nuevos (`tests/Feature/Cash/EliminarVentaAnulaCajaTest.php`), uno por criterio
  (CA1-CA5; CA3 en dos tests); suite completa 1535 en verde. **CA6:** con el `SaleController` anterior fallan 5 de 6; el de
  venta sin cobros (CA5) pasa igual, porque ese caso no cambia.
- **Cómo quedó:** `prepararSincronizacionCaja()` (edición) y `destroy()` comparten
  `movimientosCajaActivos()` y `sesionParaCorregirCaja()` (permiso si la sesión original cerró +
  sesión abierta actual). En la edición, mismos mensajes y comportamiento que antes. En
  `destroy()` se revisa antes de la transacción; dentro, con la venta bloqueada
  (`lockForUpdate()`), se vuelven a leer los cobros vivos y se corrigen con
  `CashCorrectionService::revertirMovimiento()`.
- **Desviaciones de la tarea y por qué:** ninguna.
- **Prueba funcional:** cubierta por los tests contra Postgres (sesión real, movimientos y
  `ExpectedCashCalculator`); falta la prueba a mano en umbo (abrir caja, vender en efectivo,
  eliminar, ver el esperado y la corrección en el historial).
- **Recorrido:** `docs/aprendizaje/16-recorrido-eliminar-venta-anula-caja.md`.

## Hallazgos fuera de alcance
- Los guards que ya existían en `destroy()` (venta enviada a SUNAT, venta con adelanto aplicado)
  responden **HTTP 200** con `code: 405` en el cuerpo. `sale/index.vue` → `deleteSale()` solo mira
  si la petición falló, así que muestra "Eliminada" y quita la fila aunque la venta **no** se
  eliminó (reaparece al recargar). Arreglo chico: devolver 409/422 real, o que la pantalla mire
  `code`.
- `destroy()` usa `Sale::find($id)` sin comprobar null: un id inexistente da 500.
