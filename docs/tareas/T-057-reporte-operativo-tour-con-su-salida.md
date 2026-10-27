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
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
