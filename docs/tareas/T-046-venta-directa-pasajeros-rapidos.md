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
- **Commits** (rama `feat/T-046-venta-directa-pasajeros`):
  - `8e0ca33` refactor: `PasajeroCatalogoService::pasajeroPorDefecto()` (antes el cierre de `aceptar()`) y
    `sincronizarDesdeReserva()` (antes `ReservaPasajeroController::sincronizarCatalogo()`, privado). Sin cambio de comportamiento.
  - `b299c45` backend: `VentaDirectaController` (residencia, nombre, documento, `cliente_viaja`; umbrales y extranjeros en la vista previa).
  - `fd0d455` frontend: bloque 2 · Pasajeros de `venta-directa.vue` y tipos de `ventaDirectaService.ts`.
- **Tests:** 9 nuevos en `VentaDirectaPasajerosTest` (CA1, CA2, CA3b ×3, primera fila escrita a mano, CA4,
  largos máximos, vista previa). CA3c: los 25 tests de T017/T018/ReservaPasajero pasaron sin tocarlos.
  Suite completa: 2045 pasan, 1 falla (`Fila13Fase1cFacturacionTest`, la intermitente conocida; sola pasa 32/32).
  `type-check`: 47 errores antes y después (ninguno en los archivos tocados). No corrí `npm run build`.
  CA3 (prueba funcional, Playwright en agencia-demo): 2 adultos nac. + 1 adulto ext. + 1 niño ext. → resumen
  «3 adultos (1 ext.) · 1 niño (1 ext.)»; al elegir un cliente con DNI, la primera fila de adulto sale con su nombre y documento;
  «Quitar» deja solo las cantidades; sin errores en consola. No creé una venta real desde la pantalla.
- **Desviaciones de la tarea y por qué:**
  - Los nombres se alinean con `reserva_pasajeros` por `cotizacion_pasajero_id`, no por «id asc»: es el mismo orden, pero no depende de él.
  - El cliente solo va a la primera fila de adulto si el vendedor no escribió nombre ni documento ahí; si escribió a otra persona,
    manda lo escrito y no se crea la ficha del cliente (evita una ficha sin uso). Con `cliente_viaja` ausente es `true`, como pedía la tarea;
    por eso un `store()` con payload viejo y cliente persona natural ahora también pone al cliente de primer adulto.
  - La pantalla no manda nombre/documento de la fila del cliente si siguen siendo los del cliente (los completa el backend con su ficha);
    si el vendedor vacía ambos campos, manda `cliente_viaja=false`.
  - Un niño tiene la edad editable dentro de su rango (en «Agregar nombres»); adultos usan 30 y infantes 0.
  - Arreglo de la revisión: los rangos llegan con la primera vista previa, casi siempre después de contar a los pasajeros.
    Un niño agregado antes nacía con edad 3 y, con un tope de infante de 3 o más, el backend lo guardaba como infante.
    Ahora, al llegar los rangos, cada niño se lleva a su rango (`watch(umbrales)` → `ajustarEdad`).
  - La vista previa agrega `extranjeros` por tipo solo en la vista con `lineas[]`; la de tarifa suelta (T-045) queda igual.

## Hallazgos fuera de alcance
- Los rangos de edad («13 años o más»…) solo aparecen cuando ya hay una vista previa (necesita al menos un servicio): antes de eso el
  contador no los muestra. Se podría resolver devolviéndolos también en otra respuesta que el vendedor sí pueda leer.
- Los pasajeros de la venta directa siguen sin tipo de documento: `sincronizarDesdeReserva()` guarda todo como `DNI` (limitación previa del catálogo).
