# T-005 — Aplicar una modificación a la reserva

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-003, T-004 |
| **Reglas** | R-AGE-04, R-AGE-06, R-AGE-07, R-AGE-08, R-AGE-09 |
| **Decisiones** | D-AGE-030, D-AGE-031, D-AGE-032 |
| **Rama** | `feat/T-005-aplicar-modificacion` |

## Contexto
Es la pieza más delicada de "Modificar reserva": pasa a la reserva solo la diferencia, sin
tocar lo facturado ni perder los datos operativos. Toda la información sale de
`ModificacionDiferenciaService` (T-003), recalculado **bajo lock**.

## Alcance

**Ruta** `POST reservas/{id}/modificacion/aplicar { huella }`, con el mismo permiso y Policy
que T-002.

**`ModificacionReservaService::aplicar(ReservaModificacion, string $huella, User)`**, en
**una** transacción:
1. Lock de la fila de la reserva. Es el mismo lock que facturar
   (`ReservaFacturacionController`) y cancelar (`ReservaController::cancelar()`): no tomar
   antes otro lock que ellos no toman, para que no se bloqueen entre sí. Revisar el orden y
   escribirlo en el docblock.
2. Reserva `activa`, modificación `abierta`; recalcular la diferencia. Si `huella` no coincide:
   422, "la reserva cambió desde que viste la diferencia" (caso 10). Si hay bloqueos o no hay
   cambios: 422 con el motivo.
3. **Pasajeros:**
   - existentes → `cotizacion_pasajero_id` apunta a su copia;
   - agregados → nuevo `ReservaPasajero`, vinculado a su copia;
   - quitados → `QuitarPasajeroReservaService::quitar()`. Usar el servicio, no el endpoint,
     que sigue bloqueado hasta T-007.
4. **Servicios:**
   - no facturados con sucesor → `alternativa_item_id` apunta al ítem nuevo. Se conservan
     `fecha`/`fecha_origen`, el proveedor u hotel reasignado, la salida, el check-in y el
     tratamiento tributario sobrescrito (si existe; verificar `overrideTratamientoTributario`).
     `reserva_item_pasajero` se re-arma desde `pax_incluidos` del ítem nuevo; si T-041 ya está hecha, el
     check-in vive en su propia tabla y re-armar las asignaciones no lo toca;
   - agregados (incluidas las penalidades) → reusar la creación de
     `crearReservaItemDesdeAlternativaItem()`, extrayéndola de `ReservaController` a un
     servicio sin cambiar su comportamiento;
   - quitados no facturados → misma lógica que `ReservaItemController::destroy()`
     (desenganchar la salida; nunca borrar una salida compartida);
   - facturados → no se tocan;
   - `ajuste` → crear el ítem manual "Ajuste — <servicio>" en la alternativa nueva, con el
     tratamiento tributario del servicio que ajusta, y su `reserva_item`.
5. **Cupo del mayorista** (caso 8): si cambió la cantidad de pasajeros y la reserva tiene
   `mayorista_elegida_id` con salida, sumar o restar la diferencia a `cupo_ocupado`. Devolver
   `alerta_cupo_excedido` igual que `aceptar()`.
6. **Cambio de versión:**
   - `reserva.alternativa_id` → versión nueva, que queda `aceptada`;
   - la vendida queda `reemplazada`;
   - la modificación queda `aplicada`, con `aplicado_por`, `aplicado_en` y
     `diferencia_aplicada` (la diferencia completa).
7. **Una reserva por cotización:** `ReservaController::cotizacionTieneReservaActiva()` debe
   seguir encontrando la reserva desde la cotización **original**, y desde cada cotización
   de modificación. Resolverlo vía `reserva_modificaciones` o el estado `reemplazada`.
   Test: en la cotización original no se puede aceptar otra alternativa (ni una duplicada)
   después de aplicar.

**Inventario obligatorio** (va en "Resultado"): buscar todas las lecturas de
`reserva.alternativa.cotizacion` / `alternativa.cotizacion` desde una reserva (controllers,
servicios, PDF, reportes, búsqueda del listado de reservas, Reporte Operativo, Inicio) y anotar
qué leen. Cliente, moneda y pasajeros coinciden en la copia; el **código** de cotización
cambia (`…-M1`). Anotar en "Hallazgos" cualquier lugar donde eso se vea mal; no arreglarlo
acá sin consultar.

## Fuera de alcance
- PDF (T-006) y pantallas (T-007).
- Emitir la nota de crédito: el aplicar la exige antes, no la hace.
- Desbloquear `DELETE reserva-pasajeros/{id}` (lo decide T-007).

## Archivos probables
- `ModificacionReservaService`, `ReservaModificacionController`
- `ReservaController` (`cotizacionTieneReservaActiva`, extracción de la creación de ítems)
- `QuitarPasajeroReservaService`, `ReservaItemController::destroy()` (reusar)
- `SalidaEngancheService`
- `tests/Feature/AgenciaViajes/ReservaModificacionAplicarTest.php`

## Criterios de aceptación
- [ ] **CA1** (caso 1) — Agregar un adulto y aplicar: la reserva tiene un pasajero más,
  vinculado; el tour apunta al ítem nuevo; el total a facturar sube exactamente el precio de
  un adulto; facturar la reserva después cobra el monto nuevo.
- [ ] **CA2** (caso 2) — Quitar un pasajero con penalidad: el pasajero sale, la penalidad
  queda como servicio de la reserva y se puede facturar.
- [ ] **CA3** (caso 5) — Con un servicio facturado que sube: el facturado sigue apuntando a la
  versión vendida y aparece el servicio "Ajuste" por la diferencia, facturable aparte.
- [ ] **CA4** — Datos operativos conservados: una fecha manual, un hotel reasignado, un
  check-in y un pasajero del catálogo siguen igual después de aplicar.
- [ ] **CA5** (caso 9) — Un servicio enganchado a una salida compartida con otra reserva: la
  salida sigue existiendo y la otra reserva no cambia.
- [ ] **CA6** (caso 8) — `cupo_ocupado` sube o baja exactamente la diferencia de pasajeros.
- [ ] **CA7** (caso 10) — Con una `huella` vieja: 422 y nada cambia. Aplicar y facturar al
  mismo tiempo (dos conexiones): no queda nada facturado dos veces ni a medias.
- [ ] **CA8** — Con bloqueos, o sin cambios: 422 y nada cambia.
- [ ] **CA9** — Todo o nada: si falla un paso intermedio (forzado en el test), la reserva
  queda exactamente como estaba.
- [ ] **CA10** — Después de aplicar, en la cotización original no se puede aceptar otra
  alternativa, y se puede abrir una modificación nueva sobre la reserva.
- [ ] **CA11** — Registro: la modificación queda `aplicada`, con usuario, instante (UTC) y la
  diferencia.
- [ ] **CA12** — La suite completa en verde, incluidos los tests de facturación de reservas
  (`Fila13Fase1c…`, `ReservaFacturacion…`).

## Seguridad y correctitud
- [ ] Permisos / Policy: igual que T-002
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: `huella` obligatoria; nada más viene del cliente
- [ ] Concurrencia: lock de la reserva, en el mismo orden que facturar y cancelar;
  diferencia recalculada adentro
- [ ] Dinero en centavos: cuadre exacto en CA1 y CA3
- [ ] Fechas con `HoraPeru`: `aplicado_en` en UTC, automático
- [ ] Tributario: ajuste y penalidad con su tratamiento; el guardia de mezcla de facturar
  sigue funcionando
- [ ] `v-html`: no aplica
- [ ] Migraciones: no se esperan (si aparece alguna, nueva)

## Cómo verificar
```
php artisan test --filter=ReservaModificacion
php artisan test
```
Y en `agencia-demo`, con una reserva de prueba: crear la modificación, agregar un pasajero,
aplicar y facturar. Revertir los datos al terminar.

---

## Resultado
- **Commits:**
- **Tests:**
- **Inventario de lecturas de `reserva.alternativa.cotizacion`:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
