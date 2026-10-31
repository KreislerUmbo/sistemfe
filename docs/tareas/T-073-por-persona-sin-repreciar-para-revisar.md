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
  `RecalculoPorPersonaService::recalcular()` a un método público y usarlo desde los dos
  servicios. No duplicarla. Hoy se reprecian solos:
  - proveedor con `proveedor_tarifa_id` y la tarifa existente (~84-105);
  - línea de tour (`origen_tipo = tour`) **sin** `precio_manual`.
- Un `por_persona` que sí se reprecia y cambió de pasajeros ya sale `cambio_precio` / `ajuste`:
  no cambia.
- **Tours (verificado el 30-oct):** `recalcular()` los reprecia aparte, por unidad (combo
  entero o carga de tour suelto), con `TourLineaService::recalcularUnidad()` (~60-78): mismos
  precios y temporada ya cotizados, conteo de pasajeros actual. Al aplicar una modificación se
  llama si cambió la composición (`ModificacionReservaService` ~101). **Excepción:** con
  `precio_manual` solo actualiza el costo; el precio queda igual y vuelve como aviso "revisa el
  precio", así que en la diferencia sale `igual`: el mismo hueco que el pasaje aéreo. Entra en
  esta tarea: tour con `precio_manual` y distinta cantidad de pasajeros → `para_revisar`.
  Ojo: las líneas de una carga (una por grupo de IGV) son varias filas del mismo tour; cada una
  sale como su propia línea en la diferencia, así que "Revisado" se confirma una por una (o
  en bloque desde T-007).

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
- [ ] **CA5b** — Tour con `precio_manual` y un pasajero agregado: sus líneas salen
  `para_revisar`; el mismo tour sin precio a mano sale `cambio_precio`, como hoy.
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
- **Commits:** ver `git log fix/T-073-por-persona-sin-repreciar` (un commit de código + tests).
- **Tests:** 8 nuevos en `ReservaModificacionDiferenciaTest` (CA1, CA2, CA3, CA4, CA5, CA5b con
  tour a mano y con tour real, tarifa borrada) y 1 en `ReservaModificacionAplicarTest` (pasaje
  facturado como la reserva 29: aplicar responde 422; subido el precio, se aplica con ajuste).
  Sin el cambio de código fallan 5 de los 8 de diferencia (los otros 3 son los que ya andaban:
  CA4, CA5 y tour sin precio a mano). Suite completa: ver mensaje de entrega.
- **Cómo quedó:** `RecalculoPorPersonaService::seRepreciaSolo($item)` es la única regla (proveedor
  con tarifa existente y pasajeros asignados; tour cuya unidad no tiene `precio_manual`);
  `recalcular()` usa los mismos métodos (`motivoSinRepreciar`, `tourSeRepreciaSolo`) para sus
  avisos y `ModificacionDiferenciaService::lineasDeServicios()` la consulta. Si el ítem es
  `tarifa_fija` o `por_persona` que no se reprecia solo, y cambia la cantidad de pasajeros con el
  mismo precio, queda `para_revisar` con `pasajeros_vendidos`/`pasajeros_nuevos`.
- **Desviaciones de la tarea y por qué:** además del pasaje/manual/mayorista, entran como "para
  revisar" un proveedor cuya tarifa ya no existe o sin pasajeros asignados, porque
  `recalcular()` tampoco los reprecia (misma regla, un solo lugar). Un tour sin `carga_tour`
  (anterior al modo nuevo) tampoco se reprecia: también queda "para revisar".

## Hallazgos fuera de alcance
- La comparación es por **cantidad** de pasajeros. Si se cambia un adulto por un niño (misma
  cantidad) en un servicio por persona que no se reprecia solo, sigue saliendo `igual` aunque la
  tarifa sea distinta. Habría que comparar por tipo de pasajero; no se tocó.
