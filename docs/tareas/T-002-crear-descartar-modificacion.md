# T-002 — Crear y descartar una modificación de reserva

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-001 |
| **Reglas** | R-AGE-01, R-AGE-02, R-AGE-10 |
| **Decisiones** | D-AGE-030, D-AGE-031 |
| **Rama** | `feat/T-002-crear-modificacion` |

## Contexto
Primera pieza de "Modificar reserva" (`docs/requerimientos/agencia-modificar-reserva.md`).
Desde una reserva activa se crea una **cotización de modificación**: una copia de los pasajeros
que hoy tiene la reserva y de su alternativa vigente, que el vendedor edita en el cotizador
normal. En esta tarea solo se crea, se consulta y se descarta; la diferencia (T-003) y el
aplicar (T-005) vienen después.

## Alcance

**Datos (migraciones nuevas en `tenant/verticals/agencia-viajes/`, todas con `->comment()`):**
- `reserva_modificaciones`: `reserva_id`, `cotizacion_id` (la de modificación),
  `alternativa_vendida_id`, `alternativa_nueva_id`, `estado`
  (`abierta | aplicada | descartada`), `creado_por`, `aplicado_por`, `aplicado_en`,
  `descartado_por`, `descartado_en`, `diferencia_aplicada` (json, nullable; lo llena T-005).
  Índice único parcial de Postgres: una sola `abierta` por `reserva_id` (mismo patrón que las
  sesiones de caja).
- `cotizacion_pasajeros.reserva_pasajero_id` (nullable): a qué pasajero de la reserva
  representa la copia (null = pasajero nuevo de la modificación).
- `alternativa_items.item_origen_id` (nullable): de qué ítem de la versión vendida viene.
- `cotizaciones.origen` admite `'modificacion'` (es `string(20)`, no hace falta migración para
  el valor; sí agregar la constante `Cotizacion::ORIGEN_MODIFICACION`).
- `alternativas.estado` admite `'reemplazada'` (lo escribe T-005; acá solo la constante y la
  validación).

**Servicio `ModificacionReservaService`** (`app/Services/AgenciaViajes/`):
- `crear(Reserva, User)`: en una transacción, con lock de la fila de la reserva.
  1. Se exige reserva `activa` y ninguna modificación `abierta`.
  2. Crea la cotización con `origen = 'modificacion'`, el mismo cliente, moneda, fechas de
     viaje **de la reserva** (`reserva.fecha_viaje_desde/hasta`, nunca de la cotización
     original) y código `<código de la reserva>-M<n>`. **No consume correlativo:** verificar
     la restricción de unicidad de `cotizaciones.codigo`.
  3. Copia como `cotizacion_pasajeros` los `reserva_pasajeros` actuales, cada uno con su
     `reserva_pasajero_id`. `tipo_pax`, `edad` y `residencia` salen del pasajero de la
     cotización vinculado (`reserva_pasajeros.cotizacion_pasajero_id`). Si un pasajero se
     agregó en la reserva sin vínculo, la edad no existe: usar la del catálogo de pasajeros si
     tiene fecha de nacimiento; si no, una edad representativa de su tipo, y devolverlo en
     `pasajeros_edad_estimada` para que la pantalla lo avise.
  4. Copia la alternativa vigente (`reserva.alternativa_id`) a la cotización nueva, con estado
     `borrador`, cada ítem con su `item_origen_id`, y `pax_incluidos` re-mapeado a los
     `cotizacion_pasajeros` nuevos. **Reusar la lógica de `AlternativaController::duplicar()`**
     extrayéndola a un servicio que usen los dos (sin cambiar el comportamiento de
     `duplicar()`: `AlternativaDuplicarPreservaCamposTest` sigue en verde). Los ítems de
     opciones de hotel descartadas no se copian (mismo filtro que
     `ReservaController::filtrarItemsParaReserva()`).
  5. Si la cantidad de pasajeros copiados difiere de la cotización vendida (pasajeros
     agregados o quitados en la reserva), recalcula los ítems por persona con el mismo código
     que usa `CotizacionController` al cambiar pasajeros.
  6. Crea la fila `reserva_modificaciones` (`abierta`).
- `descartar(ReservaModificacion, User)`: solo si está `abierta`. Marca `descartada` y deja la
  cotización y la alternativa como están, como registro (no borrar).

**Rutas** (`routes/` del vertical, con permiso y Policy):
- `POST reservas/{id}/modificacion`: crea; devuelve la modificación y el id de la cotización
  para abrir en el cotizador.
- `GET reservas/{id}/modificacion`: la abierta o 404.
- `DELETE reservas/{id}/modificacion`: descarta la abierta.

**Guards en código existente:**
- `ReservaController::aceptar()`: 422 si la cotización es `origen = 'modificacion'` (si no,
  generaría una segunda reserva).
- Confirmar que la cotización de modificación no aparece en los listados ni en los contadores
  de Inicio. Buscar los usos de `Cotizacion::delCotizador()` y los listados que **no** lo
  usan, y anotar en "Hallazgos" los que no la filtran.

**Permiso** `reservas.modificar`: agregarlo a `AgenciaViajesRolesSeeder` en los roles que hoy
tienen `reservas.editar`, y en `PermissionsDemoSeeder` si corresponde. Para tenants
existentes: `php artisan roles:sync-permisos-nuevos` (leer el comando antes y confirmar que
cubre este caso).

## Fuera de alcance
- Calcular la diferencia (T-003), penalidades (T-004), aplicar (T-005), PDF (T-006),
  pantallas (T-007).
- Cambiar cómo el cotizador edita pasajeros o ítems.

## Archivos probables
- `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/2026_10_2x_*`
- `api-sistema-fe/app/Models/AgenciaViajes/ReservaModificacion.php` (nuevo), `Cotizacion.php`,
  `CotizacionPasajero.php`, `AlternativaItem.php`, `Alternativa.php`, `Reserva.php`
- `api-sistema-fe/app/Services/AgenciaViajes/ModificacionReservaService.php` (nuevo)
- `api-sistema-fe/app/Services/AgenciaViajes/AlternativaCopiaService.php` (nuevo, extraído de
  `duplicar()`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaModificacionController.php` (nuevo)
- `AlternativaController::duplicar()`, `ReservaController::aceptar()`
- `database/seeders/AgenciaViajesRolesSeeder.php`
- `tests/Feature/AgenciaViajes/ReservaModificacionCrearTest.php` (nuevo)

## Criterios de aceptación
- [ ] **CA1** — Dada una reserva activa con 3 pasajeros y 4 servicios, al crear la
  modificación existe una cotización `origen='modificacion'` con 3 pasajeros (cada uno con
  `reserva_pasajero_id`) y una alternativa `borrador` con los mismos servicios, precios y
  `item_origen_id` correcto.
- [ ] **CA2** — Un servicio "solo para Carla" en la vendida queda en la copia con
  `pax_incluidos` = el id de la copia de Carla.
- [ ] **CA3** — Si en la reserva se agregó un pasajero sin vínculo, la copia lo incluye, los
  servicios por persona quedan recalculados y la respuesta lo lista en
  `pasajeros_edad_estimada` si no se conocía su edad.
- [ ] **CA4** — Con una modificación abierta, crear otra responde 422. Dos creaciones
  simultáneas dejan solo una (test con dos conexiones, como `ReservaAceptarConcurrencyTest`).
- [ ] **CA5** — Una reserva cancelada responde 422.
- [ ] **CA6** — Descartar marca `descartada` y la reserva no cambia en nada (comparar antes y
  después: pasajeros, ítems, `alternativa_id`). Después se puede crear otra modificación.
- [ ] **CA7** — `aceptar()` sobre la alternativa de una cotización de modificación responde
  422 y no crea reserva.
- [ ] **CA8** — La cotización de modificación no aparece en el listado del cotizador.
- [ ] **CA9** — Sin `reservas.modificar`, las 3 rutas responden 403. Sin poder ver la reserva
  (sin `reservas.ver_todas` y siendo de otro vendedor), también 403.
- [ ] **CA10** — `AlternativaDuplicarPreservaCamposTest` y la suite completa en verde.

## Seguridad y correctitud
- [ ] Permisos / Policy: `reservas.modificar` + `authorize('update', $reserva)` en las 3 rutas
- [ ] Aislamiento de tenant: todo en la base del tenant; ningún modelo central
- [ ] Validación de entrada: ids por ruta; nada que venga del cliente decide qué se copia
- [ ] Concurrencia: lock de la reserva + índice único parcial
- [ ] Dinero en centavos: la copia no recalcula precios, salvo el paso 5, que usa el código
  existente
- [ ] Fechas con `HoraPeru`: las fechas de viaje salen de la reserva; los instantes, en UTC
- [ ] Tributario: `tip_afe_igv` y `destino_tributario` se copian tal cual
- [ ] `v-html`: no aplica
- [ ] Migraciones: nuevas, con `down()`; `tenants:migrate-verticales`

## Cómo verificar
```
php artisan test --filter=ReservaModificacion
php artisan test
php artisan tenants:migrate-verticales   # en local, y probar con una reserva de agencia-demo
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
