# T-042 — Reactivar una salida cancelada y avisar cuando una reserva cae en una (H35)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | D9 (estados de la salida, `plan-tours-venta-simple-costo-real.md` §6) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión de salidas operativas", H35 (aprobada 26-oct-2026: reactivar a mano + aviso; **nunca** reactivar sola) |
| **Rama** | `fix/T-042-salida-cancelada` |

## Contexto
Solo hay una salida compartida por tour y fecha, y una cancelada no vuelve atrás. Una reserva nueva
de ese tour y fecha (aceptar, sincronizar, reprogramar la reserva o la salida) cae en la salida
cancelada sin que nadie se entere: no hay órdenes de servicio y la ficha de la reserva no muestra el
estado de la salida.

## Alcance
- `cambiarEstado()`: permitir **cancelada → programada** ("Reactivar salida"). Botón en el tablero
  para una salida cancelada, con confirmación.
- Al reactivar, los servicios de la salida quedan en "Por reservar" (pudieron haberse liberado con
  el proveedor al cancelar).
- **Aviso al caer en una salida cancelada** (sin bloquear, sin reactivarla): `SalidaEngancheService::enganchar()`
  informa si la salida en la que dejó el ítem está cancelada; las acciones que enganchan (aceptar,
  sincronizar, reprogramar la reserva, reprogramar la salida) lo devuelven en la respuesta y la
  pantalla lo muestra: "La salida de {tour} del {fecha} está cancelada: reactívala desde Salidas o
  reprograma la reserva".
- **Ficha de la reserva**: en cada servicio enganchado a una salida cancelada, una marca "Salida
  cancelada" con enlace a la salida (hoy no se muestra nada; el comentario de `cancelar()` dice que
  sí).
- Corregir el comentario de `SalidaOperativaController::cancelar()`.

## Fuera de alcance
- Cambiar el índice único (una salida compartida por tour y fecha sigue siendo la regla).
- Avisos a proveedores al cancelar la salida (T-040).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/SalidaOperativaController.php`
- `api-sistema-fe/app/Services/AgenciaViajes/SalidaEngancheService.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php` (aceptar, sincronizar, reprogramar: devolver el aviso)
- `admin-start-kit/src/views/agencia-viajes/salidas/index.vue`
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue`, `cotizador/editar.vue` (aviso al aceptar)

## Criterios de aceptación
- [ ] **CA1** — Salida cancelada → "Reactivar" la deja programada con sus servicios "Por reservar". → test
- [ ] **CA2** — Realizada y confirmada siguen sin poder pasar a cancelada→programada por otro camino; programada no "reactiva". → test
- [ ] **CA3** — Aceptar una cotización con un tour cuya salida de ese día está cancelada → reserva creada, ítem en esa salida, respuesta con el aviso. → test
- [ ] **CA4** — Reprogramar una salida hacia una fecha con salida cancelada → aviso en la respuesta. → test
- [ ] **CA5** — La ficha de la reserva marca "Salida cancelada" en el servicio. → prueba funcional en agencia-demo
- [ ] **CA6** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: misma ruta `estado` con `reservas.editar`
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: transición nueva solo desde cancelada
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: reactivar una salida de fecha pasada → 422 (no tiene sentido operarla)
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: textos del aviso escapados
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter="Salida|ReservaAceptar"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:** rama `fix/T-042-salida-cancelada` (ver `git log`).
- **Tests:** en `Fila13Fase2aSalidasTest`, la prueba que esperaba "una cancelada no vuelve" se
  reemplazó por 3: reactivar (servicios a "Por reservar"), solo a programada y nunca si ya pasó,
  y una reserva nueva que cae en la salida cancelada trae el aviso (y no la reactiva). Suite
  completa 1553 en verde; `type-check` con los 46 errores previos.
- **Prueba funcional (agencia-demo, salida 8 del 01/11 de la reserva 12):** cancelada → la ficha
  de la reserva (pestaña Servicios) marca "Salida cancelada" con enlace a la salida y la
  respuesta trae el aviso; en el tablero, "Reactivar salida" con confirmación → "Salida
  reactivada…" y vuelve a Programada (estado original restaurado).
- **Cómo quedó:**
  - `cambiarEstado()`: cancelada → programada; 422 si la fecha ya pasó; en la misma transacción
    sus servicios pasan a "Por reservar". Otros cambios desde cancelada siguen en 422.
  - `SalidaOperativa::avisosCanceladas($reservaId)` / `avisoCancelada()`: un solo texto.
  - Avisos: `respuestaDetalle()` lleva `avisos_salida_cancelada` (cubre aceptar, sincronizar,
    reprogramar la reserva y ver la ficha); `ReservaItemController::update()` al cambiar la
    fecha; reprogramar la salida suma el aviso a `avisos` si la del día nuevo está cancelada.
  - Pantallas: toast de advertencia tras aceptar (cotizador), sincronizar, reprogramar y guardar
    un servicio (ficha), sin pedir confirmación; marca "Salida cancelada" en la tarjeta del
    servicio; botón "Reactivar salida" en el detalle de la salida (solo si no pasó la fecha).
  - Comentario de `SalidaOperativaController::cancelar()` corregido.
- **Desviaciones de la tarea y por qué:** el aviso va en `respuestaDetalle()` (todas las
  respuestas de la ficha) en vez de devolverlo desde `SalidaEngancheService::enganchar()`: un
  solo punto cubre las cuatro acciones; la pantalla decide cuándo mostrarlo (solo tras una
  acción, no al abrir la ficha).

## Hallazgos fuera de alcance
- Ninguno.
