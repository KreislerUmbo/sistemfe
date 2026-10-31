# T-076 — Pasaje aéreo: cargos y fee por pasajero, y quién ya tiene pasaje

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-007 |
| **Reglas** | — |
| **Decisiones** | Del usuario, 31-oct-2026: el fee de agencia es por pasajero; cargos por pasajero con «no aplica a infantes» por cargo (si hace falta más detalle, se ve después); repetir un pasajero en dos pasajes **no** se bloquea (ida y vuelta en aerolíneas distintas, tramos internos), solo se muestra; quitar el check de TUA y el tipo de cargo «Fee agencia» |
| **Rama** | `feat/T-076-pasaje-aereo-por-pasajero` |

## Contexto
Hoy, en `calcularPasajeAereo()`, la tarifa base se multiplica por pasajero, pero los cargos
(impuestos, tasas, TUA) y el fee se suman una sola vez («monto ya totalizado para el grupo»).
No coincide con cómo cotiza la aerolínea, que da los impuestos por pasajero, y no sigue a los
pasajeros: si se marcan 2 en vez de 1, la tarifa se duplica y los cargos no. Además el
formulario tiene dos campos sin función real:
- **«TUA incluida en la tarifa»** solo se guarda y se copia; no entra en el cálculo ni en el
  PDF. La lista de cargos ya dice lo mismo.
- El tipo de cargo **«Fee agencia»** viene del primer diseño
  (`plan-modulo-cotizaciones-reservas.md` §2.5, antes de existir `fee_agencia_monto`). Un cargo
  suma igual al costo y a la venta, así que un fee cargado ahí sale con ganancia cero y sin el
  tratamiento de IGV del fee.

## Alcance
- **Cargos por pasajero:** el monto de cada cargo es por pasajero y se multiplica por los
  marcados. Cada cargo tiene un check «no aplica a infantes» (desmarcado por defecto), y con él
  se multiplica solo por adultos y niños.
- **Fee de agencia por pasajero:** `fee_agencia_monto` × pasajeros marcados (infantes incluidos;
  confirmar con el usuario si el infante paga fee).
- **Los pasajes ya guardados no cambian de precio:** una marca nueva en
  `cotizacion_pasaje_aereo` (por ejemplo `montos_por_pasajero`, boolean, false en los
  existentes) decide la fórmula. Los nuevos nacen con true. Al editar uno viejo se mantiene su
  fórmula, o se pasa a la nueva solo si el vendedor lo pide: decidirlo al construir y mostrarlo.
  `AlternativaCopiaService` copia la marca.
- **Quién ya tiene pasaje:** junto a cada check de pasajero, la nota «ya tiene pasaje
  <aerolínea>» si figura en otro pasaje aéreo de la misma alternativa. En un pasaje nuevo, se
  marcan por defecto solo los que aún no tienen pasaje (si todos tienen, todos). Se combina con
  el criterio de T-007 para modificaciones (`paxPorDefecto()`: solo los pasajeros nuevos). Sin
  alertas ni bloqueos.
- **Quitar del formulario:** el check «TUA incluida en la tarifa» (la columna se queda, sin
  migración) y la opción «Fee agencia» del selector de cargos. Un pasaje viejo con un cargo
  `fee_agencia` se sigue leyendo y calculando igual. Antes, contar en dakamu cuántos cargos
  `fee_agencia` hay.
- Etiquetas: «monto por pasajero» en cargos y fee; el resumen muestra cuántos pasajeros se
  están cobrando.

## Fuera de alcance
- El IGV del recuadro «Análisis de impuestos» sobre el precio total (ver «Por analizar» del
  backlog, junto con el contador).
- Reprecio automático del pasaje al cambiar los pasajeros de la cotización: sigue «para
  revisar» (`RecalculoPorPersonaService`, T-073).
- Un pasaje que se queda sin pasajeros: T-075.

## Dónde tocar
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` → `validarPasajeAereo()` (~1453), `calcularPasajeAereo()` (~1505), `contarPasajerosPorTipo()` (~1772)
- `api-sistema-fe/app/Services/AgenciaViajes/AlternativaCopiaService.php` → copia de `cotizacion_pasaje_aereo` (~184)
- `api-sistema-fe/database/migrations/tenant/verticals/agencia_viajes/` → migración nueva de la marca
- `admin-start-kit/src/components/AgenciaViajes/PasajeAereoForm.vue` → cargos, fee, checks de pasajeros, `paxPorDefecto()`
- `admin-start-kit/src/types/agencia-viajes.ts` → tipo de `cotizacion_pasaje_aereo`
- El PDF de cotización, si muestra los cargos: revisar que no los presente como totales del grupo

## Criterios de aceptación
- [ ] **CA1** — Pasaje nuevo, 2 adultos marcados, tarifa 100, cargo TUA 20, fee 10 → costo 240, venta 260. → test
- [ ] **CA2** — 2 adultos + 1 infante, cargo TUA 20 con «no aplica a infantes» → TUA × 2. → test
- [ ] **CA3** — Pasaje guardado antes de T-076 → al recalcular o abrirlo, mismo costo y venta que antes. → test
- [ ] **CA4** — Duplicar una alternativa o crear una modificación copia la marca y el cálculo no cambia. → test
- [ ] **CA5** — Alternativa con un pasaje para Adulto 1 → al abrir un pasaje nuevo, Adulto 1 dice «ya tiene pasaje …» y solo Adulto 2 sale marcado. → prueba a mano
- [ ] **CA6** — El formulario no muestra el check de TUA ni la opción «Fee agencia». Un pasaje viejo con un cargo `fee_agencia` se abre y se guarda sin error. → test + a mano

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: `cargos.*.no_aplica_infante` boolean; `cargos.*.tipo` nuevo sin `fee_agencia` solo en pasajes nuevos
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: mantener el redondeo de `PriceEngineService`
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: el fee sigue siendo el único monto con `tip_afe_igv`
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí (marca en `cotizacion_pasaje_aereo`)

## Cómo verificar
```
php artisan test --filter=PasajeAereo
php artisan test   # suite completa, contra PostgreSQL
```
Prueba a mano en `agencia-demo`: cotización de 2 adultos, un pasaje para uno y otro para el otro.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
