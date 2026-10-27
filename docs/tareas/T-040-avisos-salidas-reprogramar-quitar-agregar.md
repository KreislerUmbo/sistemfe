# T-040 — Avisos a proveedores también al reprogramar, quitar un servicio o agregar un pasajero (H29)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | D15 (`plan-tours-venta-simple-costo-real.md` §6) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión de reservas", H29 (aprobada 26-oct-2026: mismo aviso que al cancelar) |
| **Rama** | `feat/T-040-avisos-salidas` |

## Contexto
Al cancelar una reserva, la respuesta trae qué cambió en cada salida operativa ("El Mirador: ahora
son 6, no 9") y la ficha lo muestra en "Avisa a los proveedores" (D15). Reprogramar la reserva desde
su ficha, quitar un servicio y agregar un pasajero también cambian las cantidades de las salidas y
no avisan: el proveedor puede quedar contratado para otra cantidad u otra fecha.

## Alcance
- Mismo mecanismo que `ReservaController::cancelar()`: foto de cada salida afectada antes
  (`SalidaOperacionService::foto()`), foto después, `avisos()`, respuesta `avisos_salidas`. Sacarlo
  a un helper para no copiarlo cuatro veces.
  - `ReservaController::reprogramar()`: salidas viejas **y** nuevas (la nueva puede crearse en la
    operación: su "antes" es vacío).
  - `ReservaItemController::destroy()`: la salida del servicio quitado.
  - `ReservaPasajeroController::store()`: las salidas de la reserva.
- Frontend: el mismo cuadro "Avisa a los proveedores" de cancelar, sacado a una función que usen
  las cuatro acciones en `reservas/detalle.vue`.
- Revisar si quitar el pasajero (`QuitarPasajeroReservaService`, hoy bloqueado) y el vehículo
  necesitan lo mismo cuando se habilite: anotarlo en "Hallazgos".
- Sin cambios en salidas: sin cuadro (ayudas sin fricción).

## Fuera de alcance
- Guardar los avisos o una bandeja de pendientes (11f).
- Reprogramar la salida completa (D16, ya avisa).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaItemController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php`
- `api-sistema-fe/app/Services/AgenciaViajes/SalidaOperacionService.php`
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue`

## Criterios de aceptación
- [ ] **CA1** — Reprogramar una reserva de 3 pasajeros que comparte salida con otra → avisos de la salida vieja (menos pasajeros) y de la nueva. → test
- [ ] **CA2** — Quitar un servicio enganchado a una salida → aviso de esa salida. → test
- [ ] **CA3** — Agregar un pasajero a una reserva con salidas → aviso de cada salida. → test
- [ ] **CA4** — Reserva sin salidas → `avisos_salidas` vacío y sin cuadro. → test + prueba funcional
- [ ] **CA5** — La ficha muestra el cuadro en las cuatro acciones. → prueba funcional en agencia-demo
- [ ] **CA6** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): la foto "después" se toma tras la transacción, como en cancelar
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: fechas de los avisos como en cancelar
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: el cuadro escapa los textos, como el de cancelar
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=Reserva
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
