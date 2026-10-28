# T-025 — Cotizador: cada acción verifica que la cotización sea del vendedor (o que pueda ver todas)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — (regla vigente: Fase 1b, "el vendedor solo ve sus cotizaciones"; `CotizacionPolicy`) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H2 |
| **Rama** | `fix/T-025-cotizador-policy` |

## Contexto
`CotizacionPolicy` (`view`/`update`/`delete`: `cotizaciones.ver_todas` o `vendedor_id` = usuario)
se aplica en `CotizacionController::show/update/destroy`, `ReservaController::aceptar()` y
`CotizacionEnvioController`. Los demás controladores del cotizador solo piden el permiso general
(`cotizaciones.editar`, `cotizaciones.ver`), así que un vendedor puede cambiar, borrar o bajar el
PDF de alternativas, servicios, hoteles y mayoristas de cotizaciones de **otro** vendedor llamando
a la API con el id. Es el mismo hueco que se cerró en Reservas con `ReservaPolicy` (auditoría F3,
ver `docs/aprendizaje/` del F3 y `tests/Feature/AgenciaViajes/ReservaPolicyAccionesTest.php`).

## Alcance
- Resolver la cotización dueña en **cada** acción y llamar a `authorize()`:
  - lectura (`view`): PDF de la alternativa, preview de pasaje aéreo, listados de mayorista/hoteles/
    destinos/tours de una alternativa, temporadas de tours;
  - escritura (`update`): todo lo que crea, cambia o borra.
- Controladores: `AlternativaController` (store, update, destroy, duplicar, pdf),
  `AlternativaItemController` (todos los públicos), `OpcionMayoristaController`,
  `OpcionHotelController` (cuando el hotel pertenece a una alternativa vía mayorista o vía ítem;
  un hotel no registrado todavía sin ítem: decidir y anotar), `AlternativaDestinoController`,
  `CotizacionController::actualizarPasajeros()`, `TipoCambioAgenciaController` si actúa sobre una
  alternativa.
- Un solo helper para "cotización dueña de X" (alternativa, ítem, opción de mayorista, hotel,
  destino, grupo), para no repetir la cadena en cada método. Sugerencia: método en cada modelo
  (`cotizacionDueña()`) o un trait en el controlador.
- Antes de codificar, listar con `grep -n "public function"` cada método y marcar en "Resultado"
  qué verificación lleva cada uno (para que la revisión pueda comprobar que no quedó ninguno).

## Fuera de alcance
- Cambiar quién tiene qué permiso o los roles.
- El listado (`index`) ya filtra con `propias()`.

## Archivos probables
- Los controladores de arriba
- `api-sistema-fe/app/Policies/CotizacionPolicy.php` (sin cambios de regla)
- `api-sistema-fe/tests/Feature/AgenciaViajes/CotizacionPolicyAccionesTest.php` (nuevo, a imagen de `ReservaPolicyAccionesTest`)

## Criterios de aceptación
- [ ] **CA1** — Vendedor B (sin `ver_todas`) contra una alternativa del vendedor A: editar, borrar,
  duplicar, bajar PDF, agregar/editar/borrar ítems, elegir grupo, eliminar grupo, mayoristas,
  hoteles, destinos y pasajeros → **403** en todas. → test con un caso por endpoint (data provider)
- [ ] **CA2** — El vendedor A sobre lo suyo → todo funciona igual que hoy. → tests existentes en verde
- [ ] **CA3** — Usuario con `cotizaciones.ver_todas` sobre lo de A → permitido. → test
- [ ] **CA4** — La lista de métodos y su verificación queda en "Resultado".

## Seguridad y correctitud
- [ ] Permisos / Policy: **es el objetivo de la tarea**
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: la verificación va **antes** de cualquier cambio
- [ ] Concurrencia: no aplica
- [ ] Dinero: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=CotizacionPolicy
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:** rama `fix/T-025-cotizador-solo-el-dueno` (ver `git log`).
- **Tests:** `CotizacionPolicyAccionesTest` (3): un vendedor sin `ver_todas` no puede ejecutar 13
  acciones de lectura y escritura sobre la cotización de otro (403, nada cambia); el dueño y quien
  tiene `ver_todas` sí; un hotel suelto que nadie usa no tiene dueño. Sin el arreglo falla en la
  primera acción ("ver PDF"). Las 29 clases de prueba del cotizador que actuaban sin usuario ahora
  usan `ConAccesoATodasLasReservas` (da `cotizaciones.ver_todas`), igual que las de reservas.
  Suite completa 1562 en verde.
- **Cómo quedó:** `App\Services\AgenciaViajes\CotizacionDuena::de($recurso)` resuelve la
  cotización de cualquier objeto del cotizador (cotización, alternativa, ítem, destino, opción de
  mayorista, opcional, tour, hotel, tarifa de hotel); el trait
  `App\Http\Controllers\Concerns\AutorizaCotizacion::autorizarCotizacion($habilidad, $recurso)`
  llama a `CotizacionPolicy`. Va justo después de cargar el objeto, antes de cualquier otro guard.

| Controlador | Método | Verificación |
|---|---|---|
| AlternativaController | store, update, destroy, duplicar | update |
| | pdf | view |
| AlternativaItemController | store, update, elegirOpcionGrupo, destroy, eliminarGrupo, desdePlantilla, eliminarCarga, moverCarga, precioCarga, recalcularTemporadas, reasignarDia, moverBloque, actualizarPasajeAereo, actualizarManual, promoverAProveedor | update |
| | temporadasTours, previewPasajeAereo | view |
| OpcionMayoristaController | store, elegir, update, descartar, reactivar, eliminar, actualizarOpcional, eliminarOpcional, quitarTour, actualizarOrdenTour | update |
| | index | view |
| | hoteles, opcionales, tours | view (GET) / update (POST) |
| OpcionHotelController | update, agregarFotos, eliminarFoto, destroy, agregarTarifa, actualizarTarifa, eliminarTarifa, promover | update (si el hotel ya cuelga de una cotización) |
| | store | ninguna: crea un hotel suelto, sin cotización |
| AlternativaDestinoController | store, update, destroy | update |
| | index | view |
| CotizacionController | actualizarPasajeros | update |
| TipoCambioAgenciaController | actual, sugerenciaSunat, store | no aplica: no actúa sobre una alternativa |

- **Hotel no registrado** (decisión de la tarea): su dueño es la cotización de su opción de
  mayorista o, si es local, la del primer servicio que usa una de sus tarifas; si nadie lo usa
  todavía, no hay dueño y se permite (se puede reutilizar entre alternativas).
- **Venta directa** sigue funcionando: crea la cotización con `vendedor_id` = el usuario y luego
  llama a `AlternativaItemController::store()`, que lo reconoce como dueño.
- **Desviaciones de la tarea y por qué:** ninguna.

## Hallazgos fuera de alcance
- Un hotel no registrado que dos cotizaciones de vendedores distintos usan queda "de" la primera
  que lo usó (el primer servicio encontrado): el segundo vendedor recibe 403 al editarlo. Caso raro
  hoy (se crean por cotización); si pasa, decidir si el hotel suelto debe copiarse por cotización.
