# T-058 — Reporte operativo: "Mañana", búsqueda, check-in a todos, filtros recordados y rango controlado (H58, H59)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-041 (check-in en su tabla) para "Check-in a todos" |
| **Reglas** | ayudas sin fricción |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 15, H58 y H59 (aprobadas 27-oct-2026) |
| **Rama** | `feat/T-058-reporte-ajustes` |

## Contexto
Ajustes de uso pendientes desde el cierre de 11d, más el rango de fechas sin control: fecha
inválida da 500, "Hasta" antes de "Desde" sale vacío sin aviso, un rango largo trae todo.

## Alcance
- Botón **"Mañana"** junto a "Hoy" y "Esta semana".
- **Búsqueda** por nombre de pasajero, documento o código de reserva (en el servidor, parámetro
  `q`).
- **"Check-in a todos"** por servicio y día (botón en la cabecera de un grupo de servicio): marca a
  todos sus pasajeros en una sola petición (endpoint nuevo sobre la tabla de T-041).
- **Filtros recordados** al ir a una reserva y volver (en la URL, `query` de la ruta).
- Contador **"x/y pasajeros con check-in"** por pasajero distinto, no por fila.
- Backend: `fecha_desde`/`fecha_hasta` validadas (`date`, hasta ≥ desde, máximo 31 días) → 422
  claro. `filtrosDisponibles()` con consultas de `select distinct` en vez de cargar los ítems con
  todas sus relaciones.
- Pantalla: "Hasta" no permite una fecha anterior a "Desde".

## Fuera de alcance
- Lista de guías y buscador de proveedor contra el servidor (T-039).

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/reporte-operativo/index.vue`
- `admin-start-kit/src/services/admin/reporteOperativoService.ts`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReporteOperativoController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaItemPasajeroController.php` (check-in a todos)

## Criterios de aceptación
- [ ] **CA1** — `q` con parte del nombre o el código → solo esas filas. → test
- [ ] **CA2** — Rango de 40 días, fecha inválida o hasta < desde → 422 con mensaje. → test
- [ ] **CA3** — "Check-in a todos" en un servicio de 4 pasajeros → 4 check-ins. → test
- [ ] **CA4** — Ir a una reserva y volver conserva fechas y filtros. → prueba funcional
- [ ] **CA5** — Contador por pasajero: 2 pasajeros con 3 servicios cada uno, 1 con check-in → "1/2". → prueba funcional
- [ ] **CA6** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: check-in a todos con `ReservaPolicy` (update) de cada reserva afectada
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: `q` máx. 100; rango validado
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: "Mañana" con `sumarDiasISO(hoyPeru(), 1)`
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=ReporteOperativo
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
