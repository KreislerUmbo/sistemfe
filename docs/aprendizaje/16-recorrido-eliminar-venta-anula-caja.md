# Recorrido: eliminar una venta anula sus cobros en caja (T-008)

27-oct-2026, tarea **T-008** (`docs/tareas/T-008-eliminar-venta-anula-caja.md`), revisión de fallos R1.

---

## 1. El problema

Una venta todavía no enviada a SUNAT se puede eliminar. Pero sus cobros en caja
(`cash_movements` de tipo `sale_payment`) quedaban vivos: al cerrar, la caja "esperaba" un dinero
de una venta que ya no existía, y el arqueo salía con faltante. Pasa en todos los giros.

La regla de Caja es que **ningún movimiento de dinero se borra ni se edita**: se anula con otro
movimiento de signo contrario (una *corrección*), y el original queda marcado como corregido. Así
el historial muestra qué pasó.

## 2. Archivos

| Archivo | Nuevo o cambiado | Qué hace |
|---|---|---|
| `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` | cambiado | `destroy()` corrige los cobros; dos métodos nuevos compartidos con la edición |
| `api-sistema-fe/tests/Feature/Cash/EliminarVentaAnulaCajaTest.php` | **nuevo** | 6 tests |

## 3. El recorrido

```
Ventas → Eliminar ──► DELETE sales/{id} ──► SaleController::destroy()
   │
   ├─ ¿enviada a SUNAT o con adelanto aplicado? → no se elimina (como antes)
   ├─ movimientosCajaActivos(): cobros de la venta sin corregir
   ├─ si hay cobros → sesionParaCorregirCaja():
   │      ├─ la sesión del cobro ya cerró y no tienes cash.close_others_session → 403
   │      └─ no tienes caja abierta → 422
   │   (todo esto ANTES de la transacción: si falla, nada cambió)
   └─ transacción:
          ├─ bloquea la venta (lockForUpdate) y vuelve a leer los cobros vivos
          ├─ CashCorrectionService::revertirMovimiento() por cada uno
          │      → movimiento "correction" de salida en TU caja abierta
          │      → el original queda con corrected_by / corrected_at
          ├─ borra reserva_ventas (solo en Agencia)
          └─ borra la venta (soft delete)
```

**¿Por qué la corrección va en tu caja abierta y no en la del cobro?** Porque esa caja pudo
cerrarse ya (con su arqueo hecho). El dinero que se devuelve hoy sale de la caja de hoy.

**¿Por qué volver a leer los cobros dentro de la transacción?** Si dos personas eliminan (o una
edita y otra elimina) la misma venta a la vez, sin el bloqueo las dos corregirían el mismo cobro.
Con `lockForUpdate()` la segunda espera, y al leer de nuevo ya no encuentra cobros vivos.

## 4. Las funciones

**`movimientosCajaActivos(Sale)`** — los `sale_payment` de la venta con `corrected_by` vacío.
Antes esta consulta vivía dentro de `prepararSincronizacionCaja()`; ahora la usan editar y
eliminar.

**`sesionParaCorregirCaja($movimientos, $mensajeSinCaja)`** — las dos reglas de "¿puedo corregir y
dónde?": permiso si la sesión original cerró, y tu sesión abierta. Devuelve esa sesión o lanza
403/422. El mensaje sin caja cambia según la acción (editar o eliminar).

**`CashCorrectionService::revertirMovimiento()`** (ya existía) — el único lugar que sabe crear una
corrección. Ventas, movimientos manuales y cobros de créditos la usan.

## 5. Tests

| Test | Criterio |
|---|---|
| `eliminar_corrige_el_cobro_y_el_esperado_vuelve_atras` | CA1: el esperado de la caja vuelve al valor de antes de la venta |
| `pago_mixto_corrige_los_dos_movimientos` | CA2 |
| `sesion_cerrada_sin_permiso_403_y_no_se_elimina` | CA3 |
| `sesion_cerrada_con_permiso_la_correccion_cae_en_la_sesion_actual` | CA3 |
| `sin_caja_abierta_422_y_no_se_elimina_nada` | CA4 |
| `venta_sin_cobros_se_elimina_sin_pedir_caja` | CA5 |

Con el código anterior fallan los 5 primeros (CA6).

## 6. Ejercicios

1. En el test de pago mixto, Yape no cuenta para el arqueo (`affects_cash_count = false`). ¿Cambia
   el esperado de la caja al eliminar? ¿Por qué igual se corrige el movimiento?
2. Abre `sale/index.vue` → `deleteSale()`. ¿Qué muestra si el servidor responde
   `{ code: 405 }` con HTTP 200? (Está en "Hallazgos" de la tarea.)
3. Si una venta tiene un cobro en una sesión cerrada y otro en una abierta, ¿qué sesión mira
   `sesionParaCorregirCaja()` para decidir el permiso? ¿Te parece suficiente?
