# T-040 — Avisos a proveedores también al reprogramar, quitar un servicio, agregar un pasajero o cancelar la salida (H29, H37)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | D15 (`plan-tours-venta-simple-costo-real.md` §6) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión de reservas", H29, y "Revisión de salidas operativas", H37 (aprobadas 26-oct-2026: mismo aviso que al cancelar una reserva) |
| **Rama** | `feat/T-040-avisos-salidas` |

## Contexto
Al cancelar una reserva, la respuesta trae qué cambió en cada salida operativa ("El Mirador: ahora
son 6, no 9") y la ficha lo muestra en "Avisa a los proveedores" (D15). Reprogramar la reserva desde
su ficha, quitar un servicio y agregar un pasajero también cambian las cantidades de las salidas y
no avisan: el proveedor puede quedar contratado para otra cantidad u otra fecha.

## Alcance
- Mismo mecanismo que `ReservaController::cancelar()`: foto de cada salida afectada antes
  (`SalidaOperacionService::foto()`), foto después, `avisos()`, respuesta `avisos_salidas`. Sacarlo
  a un helper para no copiarlo cuatro veces.
  - `ReservaController::reprogramar()`: salidas viejas **y** nuevas (la nueva puede crearse en la
    operación: su "antes" es vacío).
  - `ReservaItemController::destroy()`: la salida del servicio quitado.
  - `ReservaPasajeroController::store()`: las salidas de la reserva.
  - Cambios de un servicio después de facturar (T-056) y antes: reasignar proveedor u hotel
    (`ReservaItemController::update()`, `ReservaController::reasignarHotel()`) avisa al proveedor
    que sale y al que entra; cambiar la fecha de un servicio enganchado, como reprogramar.
  - **«Modificar reserva»** (`ReservaModificacionController::aplicar()` →
    `ModificacionReservaService::aplicar()`): agrega y quita pasajeros y servicios, y es el único
    camino para quitar un pasajero (`ReservaPasajeroController::destroy()` responde 422). Foto de
    las salidas de la reserva antes de aplicar y foto después (salidas viejas **y** nuevas: un
    servicio nuevo puede engancharse a otra), respuesta `avisos_salidas`. En el frontend,
    `cotizador/editar.vue::aplicarModificacion()` pasa `avisos_salidas` por
    `dejarMensajeParaReserva()` y la ficha de la reserva muestra el cuadro al llegar. (Agregado en la
    revisión del 03-nov-2026.)
  - `SalidaOperativaController::cancelar()` (H37): "{proveedor} ({servicio}): la salida del {fecha}
    ya no va" para cada proveedor de servicios y vehículos. La salida no se borra, así que
    `avisos($antes, $despues)` no lo detecta: usar la foto de antes y el mismo texto que
    `avisos($antes, null)`. El tablero lo muestra.
- Frontend: el mismo cuadro "Avisa a los proveedores" de cancelar (`reservas/detalle.vue`, hoy en
  línea ~1928), sacado a una función que usen las acciones de la ficha y el mensaje que llega desde
  «Modificar reserva».
- Revisar si el vehículo necesita lo mismo: anotarlo en "Hallazgos".
- Sin cambios en salidas: sin cuadro (ayudas sin fricción).

## Fuera de alcance
- Guardar los avisos o una bandeja de pendientes (11f).
- Reprogramar la salida completa (D16, ya avisa).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaItemController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaPasajeroController.php`
- `api-sistema-fe/app/Services/AgenciaViajes/SalidaOperacionService.php`
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue`, `salidas/index.vue`, `cotizador/editar.vue`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaModificacionController.php`, `app/Services/AgenciaViajes/ModificacionReservaService.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/SalidaOperativaController.php`

## Criterios de aceptación
- [x] **CA1** — Reprogramar una reserva de 3 pasajeros que comparte salida con otra → avisos de la salida vieja (menos pasajeros) y de la nueva. → test
- [x] **CA2** — Quitar un servicio enganchado a una salida → aviso de esa salida. → test
- [x] **CA3** — Agregar un pasajero a una reserva con salidas → aviso de cada salida. → test
- [x] **CA3b** — Cancelar una salida con servicios con proveedor → un aviso "ya no va" por proveedor; el tablero lo muestra. → test + prueba funcional
- [x] **CA3c** — Aplicar una modificación que quita un pasajero de una reserva con salidas → aviso de cada salida (menos pasajeros); la ficha lo muestra al volver del cotizador. → test + prueba funcional
- [x] **CA4** — Reserva sin salidas → `avisos_salidas` vacío y sin cuadro. → test + prueba funcional
- [x] **CA5** — La ficha muestra el cuadro en cada acción (y al volver de «Modificar reserva»). → prueba funcional en agencia-demo
- [x] **CA6** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [x] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [x] Aislamiento de tenant: no aplica
- [x] Validación de entrada / archivos: no aplica
- [x] Concurrencia (`lockForUpdate`): la foto "después" se toma tras la transacción, como en cancelar
- [x] Dinero en centavos: no aplica
- [x] Fechas con `HoraPeru`: fechas de los avisos como en cancelar
- [x] Tributario sin fallback silencioso: no aplica
- [x] Sin `v-html` sin sanitizar: el cuadro escapa los textos, como el de cancelar
- [x] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=Reserva
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:** `8cc55be` (código y tests) + este de docs, en `feat/T-040-avisos-salidas`.
- **Tests:**
  - Nuevos: `T040AvisosSalidasTest` (8: CA1 con salida nueva y con salida que ya existía, cambio de
    fecha de un servicio, editar sin cambiar fecha no avisa, CA2, CA3 con vehículo, CA3b, CA4) y en
    `ReservaModificacionAplicarTest` 2 (`test_t040_…`: CA3c y CA4 en «Modificar reserva»). Vitest:
    `helpers/avisosProveedores.test.ts` (2, escapa los textos).
  - Suite completa (Postgres): primera corrida 1926 ok / 5 fallas. 4 eran de esta rama
    (`Fila13Fase2aSalidasTest` llamaba a `cancelar($id)` sin el servicio inyectado): corregido,
    el servicio se resuelve dentro del método. La quinta, `ProveedorFiltroDestinoTest::sin filtro
    devuelve todos`, pasa sola y falla solo dentro de la suite: depende del orden y no tiene que ver
    con esta tarea (ver Hallazgos). Segunda corrida completa: **1931 ok, 0 fallas** (7776 aserciones).
  - `type-check`: 47 errores, todos previos (portal, `fake-backend.ts`, rutas); ninguno en los
    archivos tocados.
  - Prueba funcional en agencia-demo (Playwright) con una reserva de prueba `RDKM-T040` (3 pax)
    que comparte con `RDKM-1026-0000002` la salida del 08/11 de Tours a Lamas: reprogramar al 09/11
    (cuadro: la del 08 "ahora son 2, no 5" y la del 09 "salida nueva… son 3. Hay que reservar") y
    de vuelta al 08; «Modificar reserva» quitando al niño → al volver, la ficha muestra "ahora son
    4, no 5" (CA3c); quitar el tour → "ahora son 2, no 4"; cancelar la reserva ya sin salidas → sin
    cuadro (CA4); tablero: cancelar la salida → "Turismo Lamas Tours (…)/Museo Chanka (…): la salida
    del 08/11/2026 ya no va" (CA3b). Al terminar se reactivó la salida 47 (sus servicios ya estaban
    "por reservar"); la reserva de prueba quedó cancelada y la cotización `PRUEBA-T040` queda en la
    base de demo.
- **Desviaciones de la tarea y por qué:**
  - Reasignar proveedor u hotel (`ReservaItemController::update` con `proveedor_tarifa_id`,
    `reasignarHotel()`) **no avisa**: los proveedores de una salida salen de sus propios servicios
    (`salida_servicios`, del Incluye del tour) y vehículos, no del ítem de la reserva, y los hoteles
    no van en salidas; con el mecanismo de fotos no hay nada que comparar. Lo decidió el usuario
    al construir (opción "solo salidas"). Cambiar la fecha de un servicio enganchado sí avisa.
  - Agregar pasajero (`ReservaPasajeroController::store`) responde `avisos_salidas`, pero la ficha
    no tiene botón que lo llame (agregar se hace por «Modificar reserva»): sin prueba funcional de
    ese camino, solo test.
  - Una salida nacida en la operación no tiene "antes": se avisa "salida nueva del …, son N. Hay
    que reservar." Para no confundir una salida que ya existía con una nueva, antes de reprogramar,
    cambiar la fecha o aplicar una modificación se toma también la foto de las salidas donde
    caerían los servicios (`fotosDondeCaeria()`).
  - El cuadro vive en `helpers/avisosProveedores.ts` (no en una función de `detalle.vue`) porque
    también lo usa el tablero. Tiene que usar la instancia `sweetalert2/dist/sweetalert2.js`: con
    `sweetalert2` a secas el temporizador del toast de éxito lo cerraba a los ~3 s.

## Hallazgos fuera de alcance
- **Vehículo:** reprogramar, quitar un servicio y «Modificar reserva» no borran la fila de
  `salida_vehiculo_reservas` de la salida que la reserva deja (solo `cancelar()` lo hace). El aviso
  sale bien (la foto solo cuenta reservas enganchadas a la salida), pero la fila queda: si la
  reserva vuelve a esa salida, reaparece sola en el vehículo.
- **Reasignar proveedor del ítem vs. proveedor de la salida:** si en la ficha se cambia el proveedor
  de un servicio enganchado, la salida sigue mostrando (y avisando a) el proveedor de su
  `salida_servicio`. No hay aviso al proveedor que sale ni al que entra.
- `ProveedorFiltroDestinoTest::sin filtro devuelve todos` es intermitente: falló en una corrida completa y pasó en la siguiente
  (probablemente depende del orden o de datos que otro test deja sin revertir).
