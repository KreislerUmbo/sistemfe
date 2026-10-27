# T-052 — CRM C3: por qué se ganó o se perdió la venta (H51)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-050 |
| **Reglas** | ayudas sin fricción (el motivo de ganancia es opcional) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 13 (H51, respuesta 2: lista base que crecerá) y obs. 16, fase C3 (27-oct-2026) |
| **Rama** | `feat/T-052-crm-resultado` |

## Contexto
Hoy solo se descarta una alternativa, sin motivo; la cotización no tiene un estado "perdida" y no
se sabe por qué se gana ni por qué se pierde.

## Alcance
- Catálogo `crm_motivos_resultado` (vertical agencia): `tipo` (`ganada` | `perdida`), `nombre`,
  `activo`, `orden`. Sembrado:
  - perdida: Precio, Eligió otra agencia, Cambió de fechas o de planes, No respondió, Otro;
  - ganada: Precio, Rapidez de respuesta, Recomendación, Itinerario, Ya era cliente, Otro.
  Pantalla simple en Configuración de Agencia (agregar, renombrar, desactivar; nunca borrar uno
  en uso).
- Columnas en `cotizaciones`: `resultado_motivo_id`, `resultado_detalle`, `perdida_en`,
  `perdida_por`.
- **"Cerrar como perdida"** en la cabecera: motivo obligatorio, detalle obligatorio solo con
  "Otro". Las alternativas en borrador o enviada pasan a descartada; actividad `estado` (T-050);
  cierra las acciones pendientes (T-051, si ya existe). Con alternativa aceptada → 422.
- **"Reabrir"**: limpia la pérdida y deja otra actividad.
- **Al aceptar** (`ReservaController::aceptar()` / "Aceptado por cliente"): en el mismo modal de
  confirmación, "¿Qué decidió la venta?" con los motivos de ganancia como botones; **opcional**.
- `Cotizacion::estadoResumen()`: nuevo estado `perdida`. Badge y filtro en el listado; Inicio no
  la cuenta como activa.

## Fuera de alcance
- Reportes de motivos (T-062).
- Pedir motivo al descartar una sola alternativa.

## Archivos probables
- migraciones nuevas (vertical agencia) + seeder del catálogo
- `api-sistema-fe/app/Models/AgenciaViajes/Cotizacion.php`, `CotizacionController.php`, `ReservaController.php` (`aceptar()`)
- `api-sistema-fe/app/Services/Dashboard/DashboardService.php`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`, `index.vue`, pantalla de configuración

## Criterios de aceptación
- [ ] **CA1** — Cerrar con "Precio" con 2 alternativas enviadas → ambas descartadas, estado "Perdida", actividad. → test
- [ ] **CA2** — Con alternativa aceptada → 422; "Otro" sin detalle → 422. → test
- [ ] **CA3** — Reabrir → sin pérdida; las alternativas se reactivan a mano. → test
- [ ] **CA4** — Aceptar con motivo de ganancia → guardado; sin motivo → acepta igual. → test
- [ ] **CA5** — Motivo nuevo desde Configuración aparece; uno en uso solo se desactiva. → test
- [ ] **CA6** — Prueba funcional en agencia-demo; suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: cerrar/reabrir con el permiso de editar la cotización; catálogo con el de configuración de agencia
- [ ] Aislamiento de tenant: tablas en la base del tenant
- [ ] Validación de entrada / archivos: motivo activo y del tipo correcto; detalle máx. 500
- [ ] Concurrencia (`lockForUpdate`): cerrar como perdida y aceptar sobre la misma cotización con `lockForUpdate()` de la cotización
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: `perdida_en` es instante (UTC)
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí, con el seeder para tenants existentes

## Cómo verificar
```
php artisan test --filter="Cotizacion|ReservaAceptar"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
