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
- **Commits:** `fix(agencia): el check-in en su propia tabla, no cambia la asignación ni la facturación (T-041)` en `fix/T-041-checkin-propio`.
- **Qué cambió:**
  - Tabla `reserva_item_checkins` (`2026_11_03_200000`, FK con cascada a ítem y pasajero, única por par)
    y copia de datos aparte (`2026_11_03_200100`, `INSERT … ON CONFLICT DO NOTHING`, idempotente; la
    hora vacía cae a `updated_at`). Modelo `ReservaItemCheckin`.
  - `ReservaItemPasajeroController::checkin()`: marcar = `createOrFirst` (el índice único frena el
    doble clic sin 500), desmarcar = borrar la fila; pasajero no asignado a un servicio con
    asignación → 422. Ya no crea asignaciones. Responde `reserva_item_checkin` (antes
    `reserva_item_pasajero`; el frontend no lo lee).
  - `ReporteOperativoController` lee la tabla nueva; Excel y PDF salen de la misma fila armada, sin
    cambios propios.
  - `ReservaItemPasajero` sin `checkin_*` en `fillable`/`casts`; tipo TS `ReservaItemPasajero` igual.
  - `LimpiarOperacionPrueba`: la tabla nueva entra en `TABLAS_A_BORRAR` (si no, el comando frena).
  - `TenantProvisioningService`: solo el docblock (cobertura transitiva por FK NOT NULL).
  - `ReservaItemQuitarService`/`QuitarPasajeroReservaService` sin cambios: la cascada limpia.
  - Backlog: línea en «Por analizar» para quitar las columnas viejas tras producción.
- **Tests:** `ReporteOperativoCheckinTest` reescrito (12): CA1 ×2 (sin asignación + la vista previa de
  facturación lo ofrece en `items_sin_asignar_disponibles`, no en pendientes), CA2 ×2, CA3, doble
  marcado, otra reserva, reserva no activa, reporte, CA4 (corre la migración de copia dos veces),
  CA6 (quitar servicio), quitar pasajero (vía `QuitarPasajeroReservaService`).
  `ReservaModificacionAplicarTest::ca4` adaptado a la tabla nueva. Filtro de la tarea + relacionados:
  134 en verde. **Suite completa contra PostgreSQL: 1908 pasan, 1 falla** —
  `ProveedorFiltroDestinoTest::sin filtro devuelve todos`, el intermitente ya anotado en «Por
  analizar»; repetido solo, pasa (4/4). `type-check`: 47 errores, igual que la base.
- **Migraciones:** aplicadas a `sistemafe_test_migrations` por archivo (con `down()` probado) y con
  `tenants:migrate-verticales` (agencia-demo; no tenía check-ins viejos que copiar).
- **Prueba funcional (CA5)** en agencia-demo como admin@agencia-demo.test: reserva
  RDKM-1026-0000002, tour del 06-nov sin asignación, 2 pasajeros. Marcar a KREISLER → «1/6
  check-in», queda tras recargar, Rosa sigue en el tour; en la base una fila en
  `reserva_item_checkins` (hora UTC) y 0 asignaciones del ítem. Excel (hojas «Vista operativa» y
  «Datos») y PDF (texto vía Ghostscript): Sí para Kreisler, No para Rosa. Desmarcado al final:
  agencia-demo quedó como estaba.
- **Desviaciones de la tarea y por qué:**
  - Dos migraciones en vez de una: la copia de datos aparte se puede probar sola (CA4) y repetir.
  - Quitar un pasajero se probó con `QuitarPasajeroReservaService`: `ReservaPasajeroController::destroy()`
    hoy responde siempre 422 (se quita desde «Modificar reserva»).
  - `checkin_hora` no se muestra en ninguna pantalla ni export (solo viaja en el JSON, como antes);
    no se agregó.

## Hallazgos fuera de alcance
- Un check-in queda guardado si después se desasigna a ese pasajero del servicio (antes se perdía
  con la fila de asignación). No se ve en el reporte (solo salen los que viajan) y reaparece si se
  lo vuelve a asignar. Decidir si al desasignar hay que borrarlo.
- Las asignaciones «para todos» que creó el check-in viejo siguen (opción a). Los servicios así
  siguen saliendo como «pendiente: faltan pasajeros» al facturar por grupos hasta que alguien las
  quite a mano en la pestaña Asignación.
