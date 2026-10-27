# T-054 — Reporte operativo: mostrar el proveedor real de la reserva, no el cotizado (H53)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 15, H53 (aprobada 27-oct-2026) |
| **Rama** | `fix/T-054-reporte-proveedor-real` |

## Contexto
El reporte (pantalla, PDF y Excel) arma proveedor, hotel, servicio, destino y los filtros desde
`alternativa_item.proveedor_tarifa_id` (lo cotizado). Lo real está en
`reserva_items.proveedor_tarifa_id`, que cambian reasignar proveedor y reasignar hotel. Resultado:
reasignar desde el reporte no se ve, un ítem sin proveedor muestra el cotizado y a la vez "sin
asignar" (agencia-demo: ítems 14, 24 y 53), y el PDF del equipo de campo dice el hotel viejo.

## Alcance
- `ReporteOperativoController`: proveedor, hotel (`tipo_habitacion`), servicio, destino y
  `servicio_id` desde `reserva_item.proveedorTarifa` (eager load nuevo). Si el ítem tiene
  `opcion_hotel_tarifa_id` (hotel ad-hoc o de matriz, reasignado por `reasignarHotel()`), el
  hotel sale de ahí. Sin tarifa en la reserva → proveedor null ("Sin asignar"); el nombre del
  servicio cae al de la cotización (`resolverNombreItem()`), que no cambia al reasignar.
- `aplicarFiltrosDimension()` y `filtrosDisponibles()`: mismo origen (`proveedorTarifa` del ítem
  de la reserva).
- `AsignacionOperativa::sinAsignar()`: el "referencial" del proveedor también desde la tarifa
  de la reserva (hoy lee la cotizada).
- Buscar otros lectores del mismo error (Inicio, `reservas/detalle.vue`, órdenes de servicio) y
  anotarlos en "Hallazgos" si no se corrigen aquí.

## Fuera de alcance
- Hotel por noche (T-055); cambiar después de facturar (T-056).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReporteOperativoController.php`
- `api-sistema-fe/app/Services/AgenciaViajes/AsignacionOperativa.php`

## Criterios de aceptación
- [ ] **CA1** — Ítem reasignado a otro proveedor → el reporte muestra el nuevo (pantalla y PDF). → test
- [ ] **CA2** — Ítem sin proveedor en la reserva → proveedor null y "sin asignar". → test
- [ ] **CA3** — Hotel reasignado con `reasignarHotel()` → el reporte muestra el hotel nuevo. → test
- [ ] **CA4** — Filtro por hotel o servicio usa el real. → test
- [ ] **CA5** — Reasignar desde la pantalla del reporte y ver el cambio en la fila. → prueba funcional en agencia-demo
- [ ] **CA6** — Suite del vertical en verde.

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
php artisan test --filter=ReporteOperativo
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
