# T-121 — Retirar las rutas legadas `sale_payments`/`sale_details` que mueven dinero sin caja

| | |
|---|---|
| **Módulo** | VEN + CAJ |
| **Depende de** | ninguna (sale de T-120, parte D, separada el 23-nov-2026) |
| **Reglas** | CLAUDE.md «Dinero y caja» (ningún movimiento de dinero es silencioso) |
| **Decisiones** | ninguna |
| **Rama** | `fix/T-121-retirar-rutas-legadas-pagos-y-lineas` |

## Contexto
`POST`/`DELETE /api/sale_payments` (`SalePaymentController::store()`/`destroy()`) cambian
`paid_out`/`debt`/`saldo_pendiente` de una venta sin movimiento de caja. Mismo caso
`POST`/`DELETE /api/sale_details` (`SaleDetailController`), que cambian el total y la deuda sin
caja ni stock. Ninguna pantalla del panel ni del central-panel los llama (verificado el 23-nov:
solo `GateBucketBRoutesTest`); desde T-119 los pagos y las líneas se editan con `PATCH sales/{id}`.
Falta confirmar que el portal e-commerce (fuera de este workspace) tampoco los usa.

## Alcance
- **Antes de construir:** el usuario corre en producción el `zgrep` de «Cómo verificar» y pega
  la salida. Si aparecen llamadas recientes, **parar** y no construir.
- `store()` y `destroy()` de los dos controllers responden **410** con el mensaje «Ruta
  retirada: los pagos y las líneas se editan desde la venta (PATCH sales/{id})», sin tocar la
  base. Borrar el código muerto de esos métodos.
- Las rutas y sus permisos quedan (`GateBucketBRoutesTest` no se rompe).

## Fuera de alcance
- Borrar las rutas o los permisos `register_/delete_sale_payment`/`_sale_detail`.
- `update()`/`show()`/`index()` de esos controllers (ya vacíos o sin uso).
- Recalcular `debt`/`paid_out`/`state_payment` en el backend.

## Dónde tocar
- `api-sistema-fe/app/Http/Controllers/Sale/SalePaymentController.php` → `store()` (~25), `destroy()` (~130)
- `api-sistema-fe/app/Http/Controllers/Sale/SaleDetailController.php` → `store()` (~16), `destroy()` (~115)
- `api-sistema-fe/routes/api.php` (~603-615): actualizar el comentario de esas rutas

## Criterios de aceptación
- [ ] **CA1** — `POST`/`DELETE sale_payments` → 410 y la venta (`paid_out`, `debt`,
  `saldo_pendiente`) y sus `sale_payments` no cambian → test: `Ventas/T121RutasLegadasTest::sale_payments_responden_410`
- [ ] **CA2** — `POST`/`DELETE sale_details` → 410 y la venta (`total`, `debt`) y sus líneas no
  cambian → test: `T121RutasLegadasTest::sale_details_responden_410`
- [ ] **CA3** — `GateBucketBRoutesTest` sigue en verde

## Seguridad y correctitud
- [x] Permisos / Policy en rutas nuevas: no hay rutas nuevas; las existentes conservan su permiso
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [x] Dinero en centavos: cierra dos caminos que movían dinero sin caja
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas: no aplica

## Cómo verificar
En el servidor, antes de construir (cubre los logs rotados que queden, ~14 días):
```
sudo zgrep -hE '"(POST|DELETE) /api/sale_(payments|details)' /var/log/nginx/sistemfe.access.log* | tail
```
Vacío = nadie los usa.
```
php artisan test --filter='T121|GateBucketBRoutes'
php artisan test   # suite completa al final
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
