# T-088 — "Mínimo para salir" obligatorio en tours con servicios por grupo o guía

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/agencia-precio-y-margen.md` → P9 y respuestas 3 y 4 (05-nov-2026) |
| **Rama** | `feat/T-088-minimo-para-salir` |

## Contexto
El costo por persona de un tour reparte lo que se cobra por grupo (vehículo, guía) entre
`pax_minimo_salida` (`TourCostoEstimadoService::sugerirItem()`). Sin ese mínimo, la calculadora
no puede sugerir el costo, el costo queda escrito a mano y el margen del tour no tiene base. En
`agencia-demo`, **12 de 13 tours no tienen mínimo**. La ganancia por salida (T-089) también lo
usa.

## Alcance
- **Regla:** un tour de venta (`esTourSimple()`, no `esSoloItinerario()`, no combo) que tiene en
  su Incluye al menos un servicio **por grupo** necesita `pax_minimo_salida`. Por grupo significa:
  - una tarifa con `modalidad = 'privado'` y sin `tipo_habitacion`, o
  - un `guia_tarifa_id`.
- **Backend:**
  - `PaquetePlantillaController::update()` (validación en `validarPayload()`, ~590-660): si el
    tour cumple la regla y el payload deja `pax_minimo_salida` vacío, responde 422 con un mensaje
    en español que nombra los servicios por grupo. Ejemplo: «Este tour tiene servicios que se
    cobran por grupo (Movilidad, Guía): indica el mínimo de pasajeros para salir.»
  - `store()` no cambia, porque un tour nuevo todavía no tiene Incluye.
  - Agregar un servicio por grupo al Incluye (`PaquetePlantillaItemController::store()` /
    `update()`) **no se bloquea**. La respuesta incluye `falta_minimo: true`, para que la pantalla
    avise.
- **Pantalla:**
  - `TourPrecioVentaCampos.vue`: el campo "mínimo para salir" lleva asterisco y la ayuda «Se usa
    para repartir el vehículo y el guía entre los pasajeros» cuando el tour tiene servicios por
    grupo.
  - `TourIncluyeCostos.vue`: aviso amarillo cuando falta el mínimo y hay servicios por grupo. Hoy
    el texto ya dice «lo divide entre el mínimo para salir».
  - Listado de tours (`paquetes/index` o el que corresponda): badge «Falta mínimo» en los tours
    que cumplen la regla y no lo tienen.
- **Datos existentes:** sin cambio automático. Agregar la columna «Falta mínimo (sí/no)» al
  comando de solo lectura `agencia-viajes:diagnosticar-tours` (`DiagnosticoToursService`, que ya
  calcula `tiene_guia` y `tiene_privados`). Correrlo en `agencia-demo` y anotar el resultado en
  "Resultado".

## Fuera de alcance
- El cotizador **no se bloquea** por falta de mínimo (respuesta 3: solo avisar). Las cotizaciones
  ya hechas no cambian.
- Combos (su costo sale de los tours que incluye).
- Calcular el costo real de un tour privado (P1, diferido).
- Ganancia por salida (T-089).

## Dónde tocar
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/PaquetePlantillaController.php` → `update()` (~302), `validarPayload()` (~590)
- `api-sistema-fe/app/Models/AgenciaViajes/PaquetePlantilla.php` → método nuevo, por ejemplo `serviciosPorGrupo(): Collection` (lo usan el controlador, el diagnóstico y T-089)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/PaquetePlantillaItemController.php` → `store()` / `update()` (agregar `falta_minimo` a la respuesta)
- `api-sistema-fe/app/Services/AgenciaViajes/DiagnosticoToursService.php` (~50-75) y `app/Console/Commands/DiagnosticarTours.php`
- `admin-start-kit/src/components/AgenciaViajes/TourPrecioVentaCampos.vue` (~41)
- `admin-start-kit/src/components/AgenciaViajes/TourIncluyeCostos.vue` (~19)
- Listado de tours en `admin-start-kit/src/views/agencia-viajes/paquetes/`

## Criterios de aceptación
- [ ] **CA1** — Tour con una tarifa privada en el Incluye: `PUT` sin `pax_minimo_salida` → 422 con
  el nombre del servicio. Con el mínimo → 200. → `PaquetePlantillaMinimoSalidaTest::test_exige_minimo_con_servicio_privado`
- [ ] **CA2** — Igual con un guía en el Incluye. → `…::test_exige_minimo_con_guia`
- [ ] **CA3** — Tour solo con servicios por persona, tour solo de itinerario y combo: guardan sin
  mínimo. → `…::test_no_exige_minimo_sin_servicios_por_grupo`
- [ ] **CA4** — Una tarifa de hotel (`tipo_habitacion` no nulo) con modalidad privada no cuenta
  como servicio por grupo. → `…::test_hotel_no_cuenta`
- [ ] **CA5** — Agregar un servicio privado a un tour sin mínimo responde 201 con
  `falta_minimo: true`. → `…::test_agregar_servicio_privado_avisa`
- [ ] **CA6** — El diagnóstico lista «Falta mínimo» en los tours correctos. → test del servicio o
  ejecución en `agencia-demo` anotada
- [ ] **CA7** — Pantalla: asterisco, aviso en el Incluye y badge en el listado. → prueba en pantalla

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas; las existentes conservan su permiso)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: la regla nueva vive en `validarPayload()`
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=PaquetePlantillaMinimoSalidaTest
php artisan agencia-viajes:diagnosticar-tours   (en agencia-demo)
```
Prueba funcional en `agencia-demo`: un tour con movilidad privada o guía → borrar el mínimo y
guardar → mensaje; el listado muestra el badge.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
