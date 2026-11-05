# T-046 — Venta directa: pasajeros rápidos con residencia y el cliente como primer pasajero (H43)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-085 (la pantalla nueva) y T-017 (reutiliza `PasajeroCatalogoService::desdeCliente()`; no escribir otra forma de convertir un cliente en pasajero) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 12, H43 (aprobada 27-oct-2026); D-AGE-034 |
| **Rama** | `feat/T-046-venta-directa-pasajeros` |

## Contexto
Los pasajeros de una venta directa solo llevan edad. La residencia (nacional / extranjero) cambia el
precio de tours y entradas (`TourLineaService::conteo()`), así que sin ella T-047 no puede cobrar
bien. Además el cliente casi siempre viaja, y hoy no se propone.

## Alcance
Reemplaza el bloque **2 · Pasajeros** de la pantalla de T-085. El resto de la pantalla no cambia.
- Pasajeros por **cantidad**, con contadores − / +: adultos / niños / infantes; adultos y niños con
  nacionales y extranjeros por separado (por defecto nacional). Bajo cada grupo, su rango de edad
  según los umbrales de la agencia («13 años o más», «3 a 12 años», «0 a 2 años»). Las edades
  siguen saliendo de `umbralesEdad()`; si hace falta la edad exacta de un niño, se puede escribir.
  Ojo: hoy `GET configuracion-agencia` pide `agencia.configuracion`; los rangos tienen que llegar
  por otro camino (por ejemplo, en la respuesta de la vista previa), no abriendo ese permiso.
- **Residencia:** se guarda en `cotizacion_pasajeros.residencia` (la columna ya existe) y
  `ReservaController::crearReservaDesdeAlternativa()` ya la copia a `reserva_pasajeros`. No hace
  falta tocar la creación de la reserva para esto.
- **Nombres opcionales**: «Agregar nombres (opcional)» despliega una fila por pasajero (tipo,
  nombre, documento). **Ojo (revisión del 05-nov):** `cotizacion_pasajeros` **no** tiene nombre ni
  documento, y no se le agregan columnas. Los nombres se escriben en los `reserva_pasajeros`
  recién creados, **dentro de la misma transacción** de `store()`, alineados por orden (id asc,
  el mismo orden de `cotizacion_pasajeros`), y se sincronizan con el catálogo de pasajeros por el
  **mismo camino** que `ReservaPasajeroController` (`sincronizarCatalogo()`, hoy privado: sacarlo
  a `PasajeroCatalogoService` en lugar de copiarlo). Una fila sin nombre queda como hoy (null).
- **El cliente como primer pasajero:** si es persona natural, la pantalla lo propone en la
  primera fila (nombre y documento llenos, editable, se puede quitar). En el backend, reutilizar
  el cierre `$pasajeroPorDefecto` que arma `ReservaController::aceptar()` (T-017: usa
  `datosDePasajero()` y `desdeCliente()`), sacándolo a un método compartido. Hoy `store()` llama a
  `crearReservaDesdeAlternativa($alternativa)` **sin** ese cierre, por eso la venta directa no
  pone al cliente. Payload: `cliente_viaja` (bool, por defecto `true`); si el vendedor lo quitó,
  `false`. Si el vendedor editó el nombre o documento de la primera fila, manda lo que escribió.
- El resumen muestra «2 adultos · 1 niño (1 ext.)». La vista previa de T-085 recibe la residencia
  y la devuelve en el desglose (lo usa T-047).
- `VentaDirectaController::store()`: acepta `residencia`, `nombre`, `documento` por pasajero (todo
  opcional; residencia por defecto nacional). Compatible con el payload actual.

## Fuera de alcance
- Buscar pasajeros en el catálogo (T-018).
- Nombres obligatorios: siguen siendo opcionales (ayudas sin fricción).

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/venta-directa.vue`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php`
- `api-sistema-fe/app/Models/AgenciaViajes/CotizacionPasajero.php` (`RESIDENCIA_*`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php` (`aceptar()` ~L340:
  el cierre `$pasajeroPorDefecto`; `crearReservaDesdeAlternativa()` ~L573)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php`
  (`sincronizarCatalogo()` ~L172)
- `api-sistema-fe/app/Services/AgenciaViajes/PasajeroCatalogoService.php`

## Criterios de aceptación
- [ ] **CA1** — 2 adultos nacionales + 1 adulto extranjero → 3 pasajeros en la reserva con esa residencia. → test
- [ ] **CA2** — Sin residencia en el payload → nacional. → test
- [ ] **CA3** — Cliente persona natural → aparece como primer pasajero; quitarlo deja solo las cantidades. → prueba funcional
- [ ] **CA3b** — Cliente persona natural y `cliente_viaja` por defecto → el primer adulto de la reserva lleva su nombre, documento y ficha del catálogo (igual que al aceptar una cotización); con `cliente_viaja=false` → sin nombre. Cliente con RUC → nadie se completa. → test
- [ ] **CA3c** — Los refactors (`$pasajeroPorDefecto` y `sincronizarCatalogo()` compartidos) no cambian el comportamiento: los tests de T-017 y de `ReservaPasajeroController` siguen en verde sin tocarlos. → test
- [ ] **CA4** — Nombres escritos llegan a `reserva_pasajeros` (y al catálogo de pasajeros, como al guardar uno desde la reserva). → test
- [ ] **CA5** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: `residencia` nacional/extranjero; nombre y documento con largo máximo
- [ ] Concurrencia (`lockForUpdate`): `desdeCliente()` bloquea la fila del cliente; va dentro de la transacción de `store()`, como en `aceptar()`
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
