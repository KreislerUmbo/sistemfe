# T-046 — Venta directa: pasajeros rápidos con residencia y el cliente como primer pasajero (H43)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-017 (reutiliza `PasajeroCatalogoService::desdeCliente()`; no escribir otra forma de convertir un cliente en pasajero) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 12, H43 (aprobada 27-oct-2026) |
| **Rama** | `feat/T-046-venta-directa-pasajeros` |

## Contexto
Los pasajeros de una venta directa solo llevan edad. La residencia (nacional / extranjero) cambia el
precio de tours y entradas (`TourLineaService::conteo()`), así que sin ella T-047 no puede cobrar
bien. Además el cliente casi siempre viaja, y hoy no se propone.

## Alcance
- Pasajeros por **cantidad**: adultos / niños / infantes, cada grupo con nacionales y extranjeros
  (por defecto nacional). Las edades siguen saliendo de los umbrales de la agencia
  (`umbralesEdad()`); si hace falta la edad exacta de un niño, se puede escribir.
- **Nombres opcionales**: "Agregar nombres" despliega una fila por pasajero (nombre, documento).
  Se guardan en `cotizacion_pasajeros` y pasan a `reserva_pasajeros` como hoy.
- Si el cliente es persona natural, se propone como primer pasajero (nombre y documento llenos,
  editable, se puede quitar).
- `VentaDirectaController::store()`: acepta `residencia`, `nombre`, `documento` por pasajero (todo
  opcional; residencia por defecto nacional). Compatible con el payload actual.

## Fuera de alcance
- Buscar pasajeros en el catálogo (T-018).
- Nombres obligatorios: siguen siendo opcionales (ayudas sin fricción).

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/venta-directa.vue`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php`
- `api-sistema-fe/app/Models/AgenciaViajes/CotizacionPasajero.php` (`RESIDENCIA_*`)

## Criterios de aceptación
- [ ] **CA1** — 2 adultos nacionales + 1 adulto extranjero → 3 pasajeros en la reserva con esa residencia. → test
- [ ] **CA2** — Sin residencia en el payload → nacional. → test
- [ ] **CA3** — Cliente persona natural → aparece como primer pasajero; quitarlo deja solo las cantidades. → prueba funcional
- [ ] **CA4** — Nombres escritos llegan a `reserva_pasajeros` (y al catálogo de pasajeros, como al guardar uno desde la reserva). → test
- [ ] **CA5** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: `residencia` nacional/extranjero; nombre y documento con largo máximo
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=VentaDirecta
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
