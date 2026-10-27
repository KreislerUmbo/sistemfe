# T-055 — Reporte operativo: el hotel en cada noche de la estadía (H54)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-054 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 15, H54 (aprobada 27-oct-2026) |
| **Rama** | `feat/T-055-reporte-hotel-noches` |

## Contexto
Una estadía es un solo ítem con la fecha de entrada (`cantidad` = noches). El reporte la muestra
solo ese día: los días siguientes la columna "Hotel" queda vacía y el día de salida no aparece. El
guía no sabe dónde recoger al pasajero.

## Alcance
- Al armar el reporte, un ítem de alojamiento cubre `fecha` … `fecha + noches - 1`. Las noches
  salen de la `cantidad` del ítem de la cotización; confirmar en el código que es así para hotel
  de catálogo y de matriz antes de usarla, y anotar el criterio.
- "Hotel" (columna en pantalla, PDF y Excel) se llena en todos los días de la estadía.
- Fila "Salida del hotel" el día `fecha + noches`, con `hora_checkout` del hotel
  (`ProveedorAlojamientoDetalle`) si está cargada.
- Una estadía que empezó antes del rango y sigue dentro también aparece (la consulta toma ítems
  de alojamiento con entrada hasta 30 días antes de "Desde").
- El filtro por hotel incluye las noches dentro del rango.
- La fila de alojamiento del día de entrada se mantiene (y la supresión de duplicados de
  `suprimirAlojamientoDuplicado()` sigue funcionando).

## Fuera de alcance
- Guardar las noches como fechas en la base (se calcula al armar el reporte).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReporteOperativoController.php`
- `api-sistema-fe/app/Services/AgenciaViajes/AsignacionOperativa.php` (`itemsDelRango()`)
- `api-sistema-fe/resources/views/pdf/agencia-viajes/reporte-operativo.blade.php`

## Criterios de aceptación
- [ ] **CA1** — Hotel 3 noches desde el 10/11 → "Hotel" el 10, 11 y 12; "Salida del hotel" el 13. → test
- [ ] **CA2** — Rango 11/11–12/11 → la estadía aparece aunque entró el 10. → test
- [ ] **CA3** — Con `hora_checkout` cargada, la fila de salida la muestra. → test
- [ ] **CA4** — PDF y Excel con las noches. → prueba funcional en agencia-demo
- [ ] **CA5** — Suite del vertical en verde.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: fechas de negocio (`date`), sin zona
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
