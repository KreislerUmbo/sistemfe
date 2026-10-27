# T-041 — El check-in en su propia tabla: marcar quién llegó no cambia la asignación ni la facturación (H30)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión de reservas", H30 (aprobada 26-oct-2026; datos existentes: opción a, se dejan como están) |
| **Rama** | `fix/T-041-checkin-propio` |

## Contexto
El check-in vive en `reserva_item_pasajero` (`checkin_realizado`, `checkin_hora`), la misma tabla
que asigna pasajeros a un servicio (sin filas = "para todos"). Para no volver "solo para uno" un
servicio de todos, `checkin()` crea filas para todos los pasajeros; desde ahí, en Facturación
especial el servicio queda "pendiente: faltan pasajeros". Mismo error de diseño que tuvo el vuelo de
la agencia (memoria `feedback_no_reusar_tabla_puente_para_conceptos_no_relacionados`), resuelto con
una tabla propia.

## Alcance
- Tabla nueva `reserva_item_checkins` (`reserva_item_id`, `reserva_pasajero_id`, `checkin_hora`,
  única por par; FK con borrado en cascada desde el ítem y el pasajero), migración en
  `tenant/verticals/agencia-viajes/`.
- Migración de datos: cada fila de `reserva_item_pasajero` con `checkin_realizado = true` pasa a la
  tabla nueva con su hora. **Las asignaciones existentes no se tocan** (decisión: opción a).
- `ReservaItemPasajeroController::checkin()`: marcar = crear la fila de check-in; desmarcar =
  borrarla. **Nunca** crea ni borra asignaciones. Se puede marcar el check-in de cualquier pasajero
  que viaja en ese servicio (asignado, o todos si el servicio no tiene asignación); otro → 422.
- `ReporteOperativoController`, `ReporteOperativoDatosSheet` (Excel) y el PDF leen el check-in de la
  tabla nueva.
- Las columnas viejas (`checkin_realizado`, `checkin_hora`) dejan de usarse; se quitan en una
  migración aparte cuando esto esté en producción (anotar en "Por analizar").
- Revisar `TenantProvisioningService` (menciona esas columnas) y cualquier otro lector.
- Borrar un servicio o un pasajero de la reserva limpia sus check-ins (la cascada lo hace; confirmar
  que `ReservaItemController::destroy()` no falla).

## Fuera de alcance
- Check-in masivo por servicio (pendiente del reporte operativo, en la hoja de ruta).
- Cambiar cómo se asignan pasajeros a un servicio.

## Archivos probables
- `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/…_create_reserva_item_checkins_table.php`
- `api-sistema-fe/app/Models/AgenciaViajes/ReservaItemCheckin.php` (nuevo), `ReservaItemPasajero.php`, `ReservaItem.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaItemPasajeroController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReporteOperativoController.php`
- `api-sistema-fe/app/Exports/ReporteOperativoDatosSheet.php`
- `admin-start-kit/src/views/agencia-viajes/reporte-operativo/index.vue`, `services/admin/reporteOperativoService.ts`, `types/agencia-viajes.ts`

## Criterios de aceptación
- [ ] **CA1** — Servicio "para todos" (sin asignación), marcar check-in de 1 pasajero → sigue sin asignación; Facturación especial por grupos lo sigue ofreciendo para elegir a mano. → test
- [ ] **CA2** — Desmarcar el check-in no toca la asignación. → test
- [ ] **CA3** — Check-in de un pasajero no asignado a un servicio con asignación → 422. → test
- [ ] **CA4** — Un check-in existente en `reserva_item_pasajero` aparece en el reporte tras migrar. → test de la migración de datos
- [ ] **CA5** — Reporte operativo (pantalla, Excel, PDF) muestra el check-in. → prueba funcional en agencia-demo
- [ ] **CA6** — Quitar un servicio con check-in no falla. → test
- [ ] **CA7** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: misma ruta y `authorize('update', reserva)` que hoy
- [ ] Aislamiento de tenant: tabla de tenant
- [ ] Validación de entrada / archivos: el pasajero pertenece a la reserva y viaja en el servicio
- [ ] Concurrencia (`lockForUpdate`): índice único por (ítem, pasajero) contra el doble clic
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: `checkin_hora` es un instante (UTC); se muestra en hora de Perú
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí, la tabla y la copia de datos

## Cómo verificar
```
php artisan test --filter="Checkin|ReporteOperativo|ReservaFacturacion"
php artisan tenants:migrate-verticales
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
