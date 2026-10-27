# T-056 — Cambiar proveedor, guía, fecha, hora y hotel después de facturar (H55)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 15, H55 y respuesta 1 (27-oct-2026: sí, el proveedor puede fallar a última hora) |
| **Rama** | `feat/T-056-cambios-tras-facturar` |

## Contexto
Facturada la reserva, `ReservaItemController::update()`, `ReservaController::reasignarHotel()` y
`reasignarMayorista()` rechazan cualquier cambio del ítem con "ya fue facturado". Ninguno toca el
precio del cliente ni el comprobante (el precio vive en la alternativa). Lo normal es cobrar antes
del viaje, así que la operación queda trabada justo cuando más se necesita.

## Alcance
- Quitar el bloqueo "ya facturado" de:
  - `ReservaItemController::update()` (guía, proveedor, fecha, hora);
  - `ReservaController::reasignarHotel()` y `reasignarMayorista()` (incluido el chequeo bajo lock).
- Se mantienen: reserva activa, `ReservaPolicy`, y el bloqueo en quitar ítem/pasajero (eso sí
  cambia lo facturado).
- Fecha de un ítem enganchado a una salida: revisar qué hace hoy `update()` con `fecha`; si no
  re-engancha la salida, al cambiarla desenganchar de la vieja y enganchar en la nueva con
  `SalidaEngancheService` (mismas reglas que reprogramar). Si no cabe en esta tarea, 422 "cámbiala
  con Reprogramar" y anotarlo.
- Pantalla: los botones de cambiar proveedor/guía/hotel/mayorista en la reserva y el reporte ya
  no se ocultan ni deshabilitan por estar facturado (revisar `reservas/detalle.vue`).

## Fuera de alcance
- Cambiar precio, cantidad o pasajeros de algo facturado (sigue por NC / Modificar reserva).
- Avisos a proveedores (T-040).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaItemController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php`
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue`
- tests que hoy esperan 422 por "facturado" en estos endpoints (actualizarlos)

## Criterios de aceptación
- [ ] **CA1** — Ítem facturado → cambiar guía, proveedor y hora → 200; el comprobante y el precio no cambian. → test
- [ ] **CA2** — Reasignar hotel y mayorista de un ítem facturado → 200. → test
- [ ] **CA3** — Quitar un ítem facturado → sigue 422. → test
- [ ] **CA4** — Cambiar la fecha de un tour facturado enganchado → queda en la salida de la nueva fecha (o 422 claro, según lo decidido). → test
- [ ] **CA5** — Prueba funcional en agencia-demo con una reserva facturada.
- [ ] **CA6** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas; `ReservaPolicy` se mantiene
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no cambia
- [ ] Concurrencia (`lockForUpdate`): los locks existentes se mantienen
- [ ] Dinero en centavos: no aplica (no toca precios)
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter="Reserva|ReporteOperativo"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
