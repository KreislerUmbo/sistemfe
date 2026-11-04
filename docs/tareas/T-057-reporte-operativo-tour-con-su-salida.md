# T-057 — Reporte operativo: el tour muestra su salida, guía, vehículo y por qué está pendiente (H57)

| | |
|---|---|
| **Módulo** | AGE (frontend + poco backend) |
| **Depende de** | ninguna |
| **Reglas** | D12, D13 (`plan-tours-venta-simple-costo-real.md` §6) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 15, H57 (aprobada 27-oct-2026) |
| **Rama** | `feat/T-057-reporte-tour-salida` |

## Contexto
La fila de un tour en la pantalla dice solo "Tour completo". El guía y el vehículo de su salida ya
vienen en la fila (`guia`, `salida_vehiculo`), pero no se muestran; tampoco hay enlace a la salida,
y si cuenta como "sin asignar" no dice por qué.

## Alcance
- Backend: `AsignacionOperativa` devuelve el **motivo** además del sí/no
  (`motivoSinAsignar()`: "Sin salida", "Salida cancelada", "Sin guía", "Servicio sin proveedor:
  {nombre}"); `sinAsignar()` queda como `motivoSinAsignar() !== null`. La fila lleva
  `motivo_pendiente`.
- Pantalla, fila de tour: guía (o "Sin guía"), vehículo, enlace "Ver salida" y, si está pendiente,
  el motivo en ámbar.
- Mismo motivo en las filas de proveedor y guía ("Sin proveedor", "Proveedor referencial").

## Fuera de alcance
- Asignar el guía del tour desde el reporte (se hace en la salida).

## Archivos probables
- `api-sistema-fe/app/Services/AgenciaViajes/AsignacionOperativa.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReporteOperativoController.php`
- `admin-start-kit/src/views/agencia-viajes/reporte-operativo/index.vue`, `types/agencia-viajes.ts`

## Criterios de aceptación
- [ ] **CA1** — Tour con salida cancelada → `motivo_pendiente` "Salida cancelada". → test
- [ ] **CA2** — Tour con un servicio de la salida sin proveedor → motivo con el nombre del servicio. → test
- [ ] **CA3** — `sinAsignar()` da lo mismo que antes en los casos existentes (Inicio sin cambios). → tests actuales
- [ ] **CA4** — La fila de tour muestra guía, vehículo y enlace. → prueba funcional en agencia-demo
- [ ] **CA5** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter="ReporteOperativo|Dashboard|AsignacionOperativa"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:** ver `git log feat/T-057-reporte-tour-salida` (un commit).
- **Tests:** nuevos: `test_motivo_pendiente_de_un_tour_con_salida_cancelada` (CA1, incluye la fila de `ReporteOperativoController::index`) y ampliación de `test_un_servicio_sin_proveedor_deja_el_tour_sin_asignar` (CA2, y `null` cuando ya está asignado). Filtro de la tarea (`Fila13Fase2|ReporteOperativo|Dashboard|AsignacionOperativa`): 81 pasan. Vertical completo (`--filter=AgenciaViajes`): 1016 pasan y 1 falla, `Fila13Fase1cFacturacionTest` (el intermitente conocido de CLAUDE.md, ajeno a esta tarea). Suite completa del backend: no corrida. `type-check`: 47 errores, todos preexistentes (p. ej. `fake-backend.ts`); ninguno en los archivos tocados.
- **Prueba funcional (CA4):** NO hecha en el navegador; falta verla en agencia-demo (fila de tour con guía, vehículo, "Ver salida" y motivo en ámbar).
- **Desviaciones de la tarea y por qué:** (1) Guía: la tarea solo da textos para proveedor; usé "Sin guía" y "Guía referencial" por analogía. (2) El motivo también se muestra en la fila de guía vía salida (la tarea decía solo tour/proveedor/guía sin salida). (3) Si un tour tiene varios servicios sin proveedor, el motivo nombra solo el primero. (4) Al cambiar el guía inline se actualiza `motivo_pendiente` en el cliente; al cambiar proveedor la pantalla ya recargaba.

## Hallazgos fuera de alcance
- Las filas sintéticas (noches de hotel, vuelos) no llevan `motivo_pendiente` (el campo es opcional en el tipo).
