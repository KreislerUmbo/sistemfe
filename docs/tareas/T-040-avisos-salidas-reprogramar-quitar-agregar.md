# T-040 — Avisos a proveedores también al reprogramar, quitar un servicio, agregar un pasajero o cancelar la salida (H29, H37)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | D15 (`plan-tours-venta-simple-costo-real.md` §6) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión de reservas", H29, y "Revisión de salidas operativas", H37 (aprobadas 26-oct-2026: mismo aviso que al cancelar una reserva) |
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
  - Cambios de un servicio después de facturar (T-056) y antes: reasignar proveedor u hotel
    (`ReservaItemController::update()`, `ReservaController::reasignarHotel()`) avisa al proveedor
    que sale y al que entra; cambiar la fecha de un servicio enganchado, como reprogramar.
  - **«Modificar reserva»** (`ReservaModificacionController::aplicar()` →
    `ModificacionReservaService::aplicar()`): agrega y quita pasajeros y servicios, y es el único
    camino para quitar un pasajero (`ReservaPasajeroController::destroy()` responde 422). Foto de
    las salidas de la reserva antes de aplicar y foto después (salidas viejas **y** nuevas: un
    servicio nuevo puede engancharse a otra), respuesta `avisos_salidas`. En el frontend,
    `cotizador/editar.vue::aplicarModificacion()` pasa `avisos_salidas` por
    `dejarMensajeParaReserva()` y la ficha de la reserva muestra el cuadro al llegar. (Agregado en la
    revisión del 03-nov-2026.)
  - `SalidaOperativaController::cancelar()` (H37): "{proveedor} ({servicio}): la salida del {fecha}
    ya no va" para cada proveedor de servicios y vehículos. La salida no se borra, así que
    `avisos($antes, $despues)` no lo detecta: usar la foto de antes y el mismo texto que
    `avisos($antes, null)`. El tablero lo muestra.
- Frontend: el mismo cuadro "Avisa a los proveedores" de cancelar (`reservas/detalle.vue`, hoy en
  línea ~1928), sacado a una función que usen las acciones de la ficha y el mensaje que llega desde
  «Modificar reserva».
- Revisar si el vehículo necesita lo mismo: anotarlo en "Hallazgos".
- Sin cambios en salidas: sin cuadro (ayudas sin fricción).

## Fuera de alcance
- Guardar los avisos o una bandeja de pendientes (11f).
- Reprogramar la salida completa (D16, ya avisa).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaItemController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php`
- `api-sistema-fe/app/Services/AgenciaViajes/SalidaOperacionService.php`
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue`, `salidas/index.vue`, `cotizador/editar.vue`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaModificacionController.php`, `app/Services/AgenciaViajes/ModificacionReservaService.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/SalidaOperativaController.php`

## Criterios de aceptación
- [ ] **CA1** — Reprogramar una reserva de 3 pasajeros que comparte salida con otra → avisos de la salida vieja (menos pasajeros) y de la nueva. → test
- [ ] **CA2** — Quitar un servicio enganchado a una salida → aviso de esa salida. → test
- [ ] **CA3** — Agregar un pasajero a una reserva con salidas → aviso de cada salida. → test
- [ ] **CA3b** — Cancelar una salida con servicios con proveedor → un aviso "ya no va" por proveedor; el tablero lo muestra. → test + prueba funcional
- [ ] **CA3c** — Aplicar una modificación que quita un pasajero de una reserva con salidas → aviso de cada salida (menos pasajeros); la ficha lo muestra al volver del cotizador. → test + prueba funcional
- [ ] **CA4** — Reserva sin salidas → `avisos_salidas` vacío y sin cuadro. → test + prueba funcional
- [ ] **CA5** — La ficha muestra el cuadro en cada acción (y al volver de «Modificar reserva»). → prueba funcional en agencia-demo
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
