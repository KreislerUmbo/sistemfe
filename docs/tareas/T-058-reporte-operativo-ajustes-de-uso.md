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
- **Commits:** ver `git log feat/T-058-reporte-ajustes` (un commit: `feat(AGE): … (T-058)`).
- **Tests:** 7 nuevos en `ReporteOperativoAjustesUsoTest` (q por nombre/documento/código, q > 100, rango
  40 días / fecha inválida / hasta < desde en `index`, `pdfSignedUrl`, `export` y `filtrosDisponibles`,
  borde 31/32 días, opciones de filtros, check-in a todos de 4 pasajeros, pares ajenos omitidos, 403 sin
  permiso sin marcar nada, lista obligatoria) + 1 en `ReporteOperativoHotelNochesTest` (q sigue al pasajero
  en las noches). `--filter="ReporteOperativo|Dashboard|AsignacionOperativa"`: 60 verdes.
  `--filter=AgenciaViajes`: 1026 verdes, 1 roja = el intermitente conocido `Fila13Fase1cFacturacionTest`.
  `type-check`: 47 errores antes y después (ninguno en los archivos tocados).
- **Navegador (Playwright, agencia-demo):** CA4 ✔ (con `q=aldana` y rango, ir a la reserva y volver
  conserva fechas, búsqueda y resultado; los filtros viven en la query de la URL). CA5 ✔ (5 pasajeros,
  uno con un check-in → "1/5 pasajeros con check-in", no "1/13"). "Mañana" ✔ (04→05-nov). "Check-in a todos"
  ✔ (marca los pendientes del servicio, persiste al recargar; se revirtió a mano, la base quedó sin
  check-ins). Rango de 59 días por URL → aviso "El rango no puede superar los 31 días." (422 como mensaje).
- **Desviaciones de la tarea y por qué:**
  - Endpoint nuevo `POST reporte-operativo/checkin-masivo` (en `ReservaItemPasajeroController::checkinMasivo`,
    permiso `reservas.editar`): recibe los pares (ítem, pasajero) que muestra la pantalla, no el id del
    servicio, para que respete la búsqueda activa. Se autoriza `ReservaPolicy::update` por cada reserva;
    si alguna se niega no se marca nada. Reserva no activa / pasajero ajeno o no asignado → se omite y se
    cuenta en `omitidos` (la pantalla avisa y recarga).
  - La pantalla agrupaba por fecha → pasajero, sin "grupo de servicio": el botón vive en la cabecera de cada
    día, uno por servicio (tour, o nombre del servicio). Noches/salida de hotel no entran.
  - "Pasajero con check-in" = tiene check-in en al menos uno de sus servicios del rango.
  - Máximo 31 días = 31 días incluidos (hasta − desde ≤ 30); la pantalla limita "Hasta" a desde+30.
  - `q` busca sin tildes ni mayúsculas; si coincide el código de reserva salen todos sus pasajeros.
  - `filtrosDisponibles` usa subconsultas `whereIn … select distinct` sobre ids (sin cargar ítems); no se
    tocó `itemsDelRango()` ni `sinAsignar()`.
  - Excel con 422: el error llega como Blob; se lee para mostrar el mensaje real.
- **No probé:** PDF/Excel descargados con `q` en el navegador (sí por test el 422 de `pdfSignedUrl`/`export`;
  `q` viaja en la URL firmada pero no abrí el PDF); el 403 de "Check-in a todos" desde la pantalla (solo test).

## Hallazgos fuera de alcance
- Pasajeros sin nombre se muestran como "Pasajero {id}" y la búsqueda por nombre no los encuentra (solo
  por documento o código de reserva).
