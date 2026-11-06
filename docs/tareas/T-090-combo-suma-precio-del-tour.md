# T-090 — El combo suma el precio y el costo de cada tour, no las tarifas de su Incluye

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.4 (N7) y fase 1b (combos con E3) |
| **Rama** | `fix/T-090-combo-precio-del-tour` |

## Contexto
Desde la fila 13, el precio de un tour es su `precio_venta_final` y su costo sale de
`TourCostoEstimadoService::costoDelTour()`. El cotizador en modo nuevo ya cobra así
(`TourLineaService::specDelTour()`). Pero la pantalla del combo y la validación del markup mínimo
siguen con el cálculo anterior: `ComboExplosionService::totalesTour()` suma la **venta de cada
tarifa de proveedor** del Incluye de cada tour. Caso real (agencia-demo, combo 15 «Paquete Clasico
Tarapoto 3d/2n», 06-nov): los tours valen S/ 150 + 150 + 75 = **S/ 375**, y el combo muestra
**venta S/ 205 / costo S/ 141** (las tarifas de sus Incluye). Hallazgo de la prueba de T-087.

## Alcance
- `totalesCombo()` arma los totales de cada tour del combo con:
  - **venta** = `precio_venta_final` del tour (precio de lista, adulto nacional, sin temporada);
  - **costo** = `costoDelTour($tour)['costo']` (escrito a mano o suma de costos del Incluye; mismo
    criterio que el cotizador).
- Tour del combo **sin precio** (`precio_venta_final` null): no suma y se avisa en un arreglo nuevo
  `componentes_sin_precio` (mismo formato que `componentes_sin_incluye`); el frontend lo muestra
  igual que los otros avisos del combo. Tour **sin costo**: suma venta, costo 0, y se avisa en
  `componentes_sin_costo` (el markup sale inflado, que se vea).
- Los **ítems sueltos** del combo (tarifa directa en el combo, sin tour-hijo) siguen sumando su
  tarifa como hoy.
- La validación del markup mínimo al guardar el combo (`PaquetePlantillaController` ~357) y al
  agregar un ítem (`PaquetePlantillaItemController` ~143) usa los totales nuevos sin tocar su código
  (los leen de `totalesCombo()`).
- Frontend (`paquetes/detalle.vue`): las filas de los tours ya muestran su precio; revisar que la
  vista previa (venta bruta, neta, markup) cuadre con la suma de esas filas.

## Fuera de alcance
- `totalesTour()` cuando se usa para un **tour suelto** o para el modo anterior del cotizador
  (`modo_tours` apagado, `AlternativaItemController::desdePlantilla()` → `explotarTourSimple`):
  no cambia. Si `totalesTour()` se usa en más lugares, crear un método nuevo para el combo
  (p. ej. `totalesTourEnCombo()`) en vez de cambiarlo para todos.
- Precio por temporada en la vista previa del combo (depende de la fecha; el cotizador ya lo hace).
- Precio de extranjero y de niño en la vista previa: solo adulto nacional, como el resto de la pantalla.
- Combo con tours de monedas distintas: hoy el cotizador lo rechaza (422); acá solo no romper.

## Dónde tocar
- `api-sistema-fe/app/Services/AgenciaViajes/ComboExplosionService.php` → `totalesCombo()` (~89-170),
  `totalesTour()` (~56); buscar antes todos los llamados a `totalesTour(`.
- `api-sistema-fe/app/Services/AgenciaViajes/TourCostoEstimadoService.php` → `costoDelTour()` (solo leer, reusar).
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/PaquetePlantillaController.php` (~104, ~183, ~357): sin cambios esperados, verificar.
- `admin-start-kit/src/views/agencia-viajes/paquetes/detalle.vue` → `preview` (~1039) y los avisos
  `componentes_sin_incluye` (agregar los dos nuevos con el mismo estilo).
- `admin-start-kit/src/types/agencia-viajes.ts` → tipo de `precio_calculado`.
- Tests: `tests/Feature/AgenciaViajes/PaqueteComboTest.php`, `ComboItemsSueltosTest.php`,
  `AjusteRedondeoTest.php` (hoy arman combos con tarifas: ajustar los datos, no las aserciones de
  lo que no cambió).

## Criterios de aceptación
- [ ] **CA1** — Combo con tours de precio 150, 150 y 75 y costos 70, 65 y 13 → `venta_bruta_combo`
  375, `costo_total_combo` 148, aunque las tarifas de sus Incluye sumen otra cosa.
  → test: `PaqueteComboTest::test_combo_suma_precio_y_costo_de_cada_tour`
- [ ] **CA2** — Tour sin `precio_venta_final` dentro del combo → no suma y aparece en
  `componentes_sin_precio`. → test
- [ ] **CA3** — Tour sin costo → suma su venta, costo 0 y aparece en `componentes_sin_costo`. → test
- [ ] **CA4** — Markup mínimo del combo 200 con los datos de CA1 → guardar el combo responde 422
  (validado con 375/148, no con las tarifas). → test
- [ ] **CA5** — Ítem suelto en el combo sigue sumando su tarifa. → `ComboItemsSueltosTest` en verde
- [ ] **CA6** — En agencia-demo, combo 15: la vista previa dice venta bruta S/ 375 y el markup
  coincide con (375 − costo) ÷ costo. → prueba en pantalla
- [ ] **CA7** — Suite completa del backend en verde; `type-check` sin errores nuevos y `build` en verde.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: redondeo a 2 decimales en un solo punto (como hoy, `round(..., 2)`)
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: un tour sin precio o sin costo se avisa, no se suma en silencio
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
cd api-sistema-fe && php artisan test --filter="PaqueteComboTest|ComboItemsSueltosTest|AjusteRedondeoTest"
php artisan test
cd ../admin-start-kit && npm run type-check && npm run build
```
Prueba funcional en `http://agencia-demo.sistemafe.test:5173`: Paquetes → «Paquete Clasico
Tarapoto 3d/2n» → pestaña Datos (vista previa) y panel lateral.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
