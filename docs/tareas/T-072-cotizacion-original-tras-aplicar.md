# T-072 — La cotización original sigue como "reservada" tras aplicar una modificación

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-005 |
| **Reglas** | R-AGE-08 |
| **Decisiones** | D-AGE-030, D-AGE-031 |
| **Rama** | `fix/T-072-cotizacion-original-tras-aplicar` |

## Contexto
Al aplicar una modificación (T-005), la alternativa vendida queda `reemplazada` y la reserva pasa
a la alternativa de la cotización de modificación (código `…-M1`). Dos lecturas se ven mal desde
ese momento (inventario de la revisión de T-005, 30-oct):
1. `Cotizacion::estadoResumen()` solo mira la alternativa `aceptada`: la cotización original
   vuelve a `borrador`/`enviada` en el listado comercial y sale de "reservadas / conversión" del
   Inicio, aunque su reserva sigue activa.
2. El tablero de salidas muestra como código de la reserva el de su cotización vigente
   (`…-M1`), sin usar el código propio de la reserva.

Hoy nadie puede aplicar desde la pantalla, así que todavía no se ve: tiene que estar antes de
T-007.

## Alcance
- `estadoResumen()`: una cotización con una alternativa `reemplazada` cuenta como `reservada`
  (o `anulada` si la reserva está cancelada). La reserva se encuentra por
  `reserva_modificaciones.alternativa_vendida_id` (la reserva ya no apunta a esa alternativa).
  Evitar N+1 en el listado: precargar lo necesario donde hoy se precarga `alternativas.reserva`.
- Revisar si el listado de cotizaciones o el Inicio filtran o cuentan "reservadas" en SQL
  (`whereHas` sobre `estado = 'aceptada'`) además de `estadoResumen()`; si es así, mismo criterio.
- Tablero de salidas: código de la reserva = `reserva.codigo` con fallback al de la cotización,
  igual que `ReporteOperativoController` (~224, ~469).

## Fuera de alcance
- Que buscar una reserva por el código de la cotización original la encuentre (hallazgo de
  T-005; anotar si resulta trivial, no hacerlo).
- La cotización de modificación (`origen = 'modificacion'`) no aparece en el listado comercial
  (`Cotizacion::delCotizador()`); no cambia.

## Dónde tocar
- `api-sistema-fe/app/Models/AgenciaViajes/Cotizacion.php` → `estadoResumen()` (~111)
- `api-sistema-fe/app/Services/DashboardService.php` → conteo de cotizaciones (buscar `estadoResumen`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionController.php` → `index()` (precarga y filtros por estado)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/SalidaOperativaController.php` (~612)
- `api-sistema-fe/app/Services/AgenciaViajes/SalidaOperacionService.php` (~160)
- Escenario de test: reusar el de `tests/Feature/AgenciaViajes/ReservaModificacionAplicarTest.php`

## Criterios de aceptación
- [ ] **CA1** — Tras aplicar una modificación, la cotización original sale `reservada` en el
  listado de cotizaciones y cuenta en "reservadas" del Inicio.
- [ ] **CA2** — Con la reserva cancelada después de aplicar, la cotización original sale `anulada`.
- [ ] **CA3** — Dos modificaciones aplicadas seguidas: la original sigue `reservada`.
- [ ] **CA4** — El tablero de salidas muestra el código propio de la reserva; sin código propio,
  el de la cotización.
- [ ] **CA5** — El listado de cotizaciones no agrega consultas por fila (contar queries en el test).

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica (solo lectura)
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no se esperan

## Cómo verificar
```
php artisan test --filter=CotizacionOriginalTrasAplicar
php artisan test
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
