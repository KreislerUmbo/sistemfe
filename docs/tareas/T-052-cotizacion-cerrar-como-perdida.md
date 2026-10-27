# T-052 — Cotización: cerrar como perdida con motivo (H51)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-050 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 13, H51 y respuesta 2 (27-oct-2026: lista base, se irán agregando motivos) |
| **Rama** | `feat/T-052-cotizacion-perdida` |

## Contexto
Hoy solo se puede descartar una alternativa, sin motivo. No se sabe por qué se pierden las ventas
(precio, otra agencia, no respondió…), y la cotización no tiene un estado que diga que se perdió.

## Alcance
- Catálogo `motivos_perdida_cotizacion` (vertical agencia): `nombre`, `activo`, `orden`. Sembrado
  con: Precio, Eligió otra agencia, Cambió de fechas o de planes, No respondió, Otro. Pantalla
  simple en Configuración de Agencia para agregar, renombrar y desactivar (nunca borrar uno en uso).
- Columnas en `cotizaciones`: `perdida_en`, `perdida_motivo_id`, `perdida_detalle` (texto, nullable),
  `perdida_por`.
- **"Cerrar como perdida"** en la cabecera de la cotización: motivo (obligatorio) + detalle
  (opcional, obligatorio solo con "Otro"). Las alternativas en borrador o enviada pasan a
  descartada; deja una fila `estado` en el seguimiento; limpia `proximo_contacto` (T-051).
  Con una alternativa aceptada → 422.
- **"Reabrir"**: limpia los campos de pérdida y deja otra fila `estado`. Las alternativas siguen
  descartadas (se reactivan a mano, como hoy).
- `Cotizacion::estadoResumen()`: nuevo estado `perdida` (antes que `descartada`). Badge y filtro
  en el listado. Inicio: no cuenta como activa.

## Fuera de alcance
- Reporte de conversión y motivos (se arma cuando haya datos; anotarlo en el backlog).
- Pedir motivo al descartar una sola alternativa.

## Archivos probables
- migraciones nuevas (vertical agencia) + seeder del catálogo
- `api-sistema-fe/app/Models/AgenciaViajes/Cotizacion.php` (`estadoResumen()`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionController.php`
- `api-sistema-fe/app/Services/Dashboard/DashboardService.php`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`, `index.vue`, pantalla de configuración

## Criterios de aceptación
- [ ] **CA1** — Cerrar con "Precio" una cotización con 2 alternativas enviadas → ambas descartadas, estado "Perdida", fila de seguimiento. → test
- [ ] **CA2** — Con alternativa aceptada → 422. → test
- [ ] **CA3** — "Otro" sin detalle → 422. → test
- [ ] **CA4** — Reabrir → vuelve a "Descartada" (todas descartadas) y se pueden reactivar. → test
- [ ] **CA5** — Motivo nuevo desde Configuración aparece en el selector; uno en uso no se borra, solo se desactiva. → test
- [ ] **CA6** — Filtro "Perdida" en el listado. → prueba funcional en agencia-demo
- [ ] **CA7** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: cerrar/reabrir con el permiso de editar cotización; catálogo con el de configuración de agencia
- [ ] Aislamiento de tenant: tablas en la base del tenant
- [ ] Validación de entrada / archivos: motivo activo y existente; detalle máx. 500
- [ ] Concurrencia (`lockForUpdate`): cerrar como perdida y aceptar sobre la misma cotización → `lockForUpdate()` de la cotización en los dos
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: `perdida_en` es instante (UTC)
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí, con el seeder del catálogo para tenants existentes

## Cómo verificar
```
php artisan test --filter=Cotizacion
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
