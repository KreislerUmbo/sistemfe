# T-073 — Un servicio "por persona" que no se reprecia solo queda "para revisar" en la modificación

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-005 |
| **Reglas** | R-AGE-03, R-AGE-06 |
| **Decisiones** | D-AGE-032 |
| **Rama** | `fix/T-073-por-persona-sin-repreciar` |

## Contexto
En la prueba funcional de T-005 (30-oct, `agencia-demo`, reserva 29) se agregó un adulto a una
reserva con un pasaje aéreo `por_persona` ya facturado. El pasaje salió `igual` en la diferencia,
se aplicó sin bloqueos y facturar al pasajero nuevo dio **0**: viaja sin cobrarle.

Causa: al cambiar los pasajeros, `RecalculoPorPersonaService` solo reprecia los ítems
`por_persona` de `origen_tipo = proveedor` con `proveedor_tarifa` real. A los demás (pasaje
aéreo, manual, mayorista, proveedor sin tarifa) solo los devuelve como aviso "se debe repreciar
manualmente". Su precio no cambia, así que `ModificacionDiferenciaService::lineasDeServicios()`
los compara por monto y los da por `igual`. Para `tarifa_fija` ya existe la regla contraria:
si cambia la cantidad de pasajeros queda `para_revisar` (~129).

Hoy nadie puede aplicar desde la pantalla, así que tiene que estar antes de T-007.

## Alcance
- En `lineasDeServicios()`: un ítem `por_persona` que **no** se reprecia solo, con el mismo
  precio y distinta cantidad de pasajeros que lo usan (`pasajerosQueLoUsan`), queda
  `para_revisar`, con `pasajeros_vendidos` / `pasajeros_nuevos` en la línea (hoy solo los trae
  `tarifa_fija`). Se confirma con la ruta de siempre (`POST …/items/{itemId}/revisado`) o se
  corrige el precio en el cotizador (entonces sale `cambio_precio` / `ajuste`).
- "Se reprecia solo" se decide en **un** lugar: extraer la condición de
  `RecalculoPorPersonaService::recalcular()` (~84: proveedor con `proveedor_tarifa_id` y tarifa
  existente) a un método público y usarlo desde los dos servicios. No duplicarla.
- Un `por_persona` que sí se reprecia y cambió de pasajeros ya sale `cambio_precio` / `ajuste`:
  no cambia.
- Tours (`origen_tipo = tour`): `RecalculoPorPersonaService` los excluye. Verificar cómo se
  reprecian al cambiar pasajeros; si tampoco, mismo tratamiento. Anotar lo que se encontró.

## Fuera de alcance
- Repreciar automáticamente pasajes aéreos o ítems de mayorista.
- Pantallas (T-007 mostrará la línea como cualquier `para_revisar`).

## Dónde tocar
- `api-sistema-fe/app/Services/AgenciaViajes/ModificacionDiferenciaService.php` → `lineasDeServicios()` (~120-140)
- `api-sistema-fe/app/Services/AgenciaViajes/RecalculoPorPersonaService.php` → condición (~80-105)
- Tests: `tests/Feature/AgenciaViajes/ReservaModificacionDiferenciaTest.php` (y el de aplicar si hace falta)

## Criterios de aceptación
- [ ] **CA1** — Pasaje aéreo `por_persona` (no facturado) y se agrega un pasajero: la línea sale
  `para_revisar` con pasajeros vendidos y nuevos, y aplicar responde 422 por el bloqueo.
- [ ] **CA2** — El mismo caso con el pasaje facturado: también `para_revisar`; si se sube el
  precio en el cotizador, sale `ajuste`.
- [ ] **CA3** — "Revisado" lo saca de los bloqueos; si después cambian otra vez los pasajeros,
  vuelve a bloquear.
- [ ] **CA4** — Un `por_persona` de proveedor con tarifa se reprecia y sale `cambio_precio`,
  como hoy.
- [ ] **CA5** — Un `por_persona` sin cambio de pasajeros sigue `igual`.
- [ ] **CA6** — Los tests existentes de diferencia, penalidad y aplicar siguen en verde.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica (la diferencia ya se recalcula bajo lock al aplicar)
- [ ] Dinero en centavos: la comparación sigue en centavos
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no se esperan

## Cómo verificar
```
php artisan test --filter=ReservaModificacion
php artisan test
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
