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

**Nota de la revisión de T-003 (29-oct): modificaciones encadenadas.** Tras aplicar una, un
servicio facturado sigue apuntando a la versión anterior y D-AGE-032 crea un "Ajuste" en la
versión nueva. La siguiente modificación copiaría el servicio **y** el ajuste, y el ajuste se
contaría dos veces en la diferencia (`ModificacionDiferenciaService` ya sube por
`item_origen_id` para encontrar el servicio de la reserva, pero no conoce los ajustes). Decidir
cómo se marca un ítem de ajuste para que la copia de T-002 y la diferencia de T-003 lo traten
aparte, y cubrir con un test "aplicar dos modificaciones seguidas".

**Notas de la revisión de T-004 (30-oct): penalidades.** Una penalidad es un `alternativa_items`
manual con `penalidad_reserva_pasajero_id` (FK `nullOnDelete` a `reserva_pasajeros`). Al
quitar al pasajero en el paso 3 esa columna queda en NULL y el ítem pasa a ser un servicio
común: crear su `reserva_item` (paso 4, "agregados") **antes** de quitar al pasajero, o no
depender de esa columna después. La penalidad nace con `pax_incluidos = null` ("para todos"):
al facturar por pasajero se repartiría entre los que se quedan. Decidir a quién se le imputa
(preguntar al usuario si no está claro) y cubrirlo con un test que facture la penalidad.
Hoy, sacar a un pasajero y volver a agregarlo en el cotizador crea uno nuevo (`agregado`) y el
original sigue `quitado` con su penalidad vigente: no es tarea de T-005 cambiarlo, pero el
aplicar no debe romperse en ese caso.

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
- **Commits:** ver `git log main..feat/T-005-aplicar-modificacion`.
- **Tests:** `ReservaModificacionAplicarTest` (23 tests, uno o más por CA1-CA11; CA12 es la suite
  completa). Suite completa contra Postgres: 1739 pasan, 2 fallan (`Fila13Fase1cFacturacionTest` línea 306 y `ProveedorFiltroDestinoTest::sin filtro devuelve todos`, 6 en lugar de 2); los dos pasan al volver a correrlos (36 tests en verde) y corren antes de mi test en el orden de la suite, así que no son de esta rama: se anotan como intermitentes (el primero ya figuraba en CLAUDE.md). No se corrió prueba funcional en `agencia-demo`.
  El de CA7 con dos conexiones guarda de verdad y limpia a mano (igual que
  `ReservaAceptarConcurrencyTest`): prueba que `aplicar` espera el lock de la fila de `reserva`, el
  mismo de facturar y cancelar. No hay un test que corra facturar y aplicar en paralelo: se
  cubre con ese lock, con "factura entre ver y aplicar → huella vieja → 422 y nada cambia" y con
  la comprobación de que el primer `FOR UPDATE` de aplicar es el de `reserva` y antes no se escribe nada.
- **Orden de locks** (también en el docblock de `aplicar`): `reserva` (primero, como
  `ReservaFacturacionController::store()` y `ReservaController::cancelar()`) → `reserva_modificaciones`
  (solo la toma también `descartar()`, que no toma la de la reserva: sin ciclo) → lo de siempre
  (`salidas_mayorista` por `quitar()` e incremento de cupo; `cancelar()` los toma en ese orden también).
  No se toma ningún otro lock antes del de la reserva.
- **Respuestas del usuario antes de codificar:** (1) la penalidad queda como servicio **sin
  asignar** (sin filas en `reserva_item_pasajero`): facturar no la incluye sola y el vendedor la suma
  a la factura que quiera; hay un test que la factura de verdad (CA2). Lo mismo para el "Ajuste".
  (2) El ítem de ajuste se marca con una columna nueva `alternativa_items.ajuste_de_item_id`
  (migración `2026_10_30_100000_…`, FK a sí misma, `nullOnDelete`): la copia de T-002 no lo copia y la
  diferencia de T-003 no lo trata como servicio de la reserva. Test "aplicar dos modificaciones seguidas".
- **Inventario de lecturas de `reserva.alternativa.cotizacion`** (desde una reserva; después de
  aplicar apunta a la cotización de modificación `…-M1`, con el mismo cliente, moneda y pasajeros):
  | Lugar | Qué lee | ¿Se ve mal? |
  |---|---|---|
  | `Reserva::aplicarFiltroVendedor` y `ReservaPolicy::view` | `cotizacion.vendedor_id` (dueño de la reserva) | **Sí, si no se corrige**: la modificación nace con el vendedor que la abrió. Aplicar lo devuelve al de la cotización vendida (ver Desviaciones). |
  | `ReservaController::index` (listado) | cliente, vendedor (`with`), búsqueda por `cotizacion.codigo`/`destino`/cliente | El código `…-M1` pasa a ser el buscable; **buscar por el código de la cotización original ya no encuentra la reserva** (sí por el de la reserva, `R…`, que es lo normal). |
  | `ReservaController::respuestaDetalle` (detalle) | `cliente`, `destino`, `codigo_cotizacion`, y `codigo` con fallback al de la reserva | `codigo_cotizacion` pasa a `…-M1`. Para reservas sin código propio (anteriores al módulo 12) el código mostrado es el `-M1`. |
  | `ReservaController::cotizacionTieneReservaActiva` | `alternativa.cotizacion_id` | Corregido (CA10). |
  | `ReservaFacturacionController` (`prepararFactura`, `store`) | `cotizacion.cliente` (cliente por defecto), `cotizacion.codigo` en la descripción del `Sale` ("Facturación de reserva #N (…)") | La descripción de la venta lleva el código `…-M1`. El cliente es el mismo. |
  | `ReservaAnticipoController` | `cotizacion.cliente` y `alternativa.moneda_cotizacion` | No (cliente y moneda coinciden). |
  | `ReporteOperativoController` (líneas 224, 469) | `codigo_reserva` = código de la reserva con fallback al de la cotización | Solo para reservas sin código propio. |
  | `SalidaOperativaController` (612-620), `SalidaOperacionService` (160-161) | `cotizacion.codigo` y cliente del tablero de salidas | El tablero de salidas muestra `…-M1` como código de la reserva (también las que tienen código propio, porque ahí no hay fallback). |
  | `DashboardService` (347, 378) | cliente de "próximos viajes" | No. |
  | `DashboardService::cotizaciones` y `CotizacionController::index` (`alternativas.reserva` + `Cotizacion::estadoResumen`) | estado de las alternativas de la **cotización original** | **Sí**: la vendida queda `reemplazada` y `estadoResumen()` solo mira `aceptada`, así que la cotización original deja de contarse como `reservada` (vuelve a `borrador`/`enviada`) en el listado comercial y en "reservadas / conversión" del Inicio. Ver Hallazgos. |
  | `DiagnosticarFechasReserva` (comando) | `cotizacion.fecha_viaje_desde` de la reserva | Solo diagnóstico; la fecha de la reserva es la propia. |
  | `ReservaController::sincronizarItems` y `respuestaDetalle` (`items_pendientes_sincronizar`) | ítems de `reserva.alternativa_id` sin `reserva_item` | **Sí**: tras un ajuste, el ítem nuevo de un servicio facturado quedaba como "pendiente" y sincronizar lo habría facturado dos veces. Corregido (ver Desviaciones). |
  | `Reserva` (modelo), `AlternativaPdfService`, `CorreoAgenciaService`, `TourLineaService`, `RecalculoPorPersonaService`, `AlternativaItemController` | leen `alternativa.cotizacion` de una **alternativa** (cotizador, PDF, correo), no desde la reserva | No aplica: no pasan por la reserva. |
  Los ítems "Ajuste — …" y "Penalidad …" no aparecen en el Reporte Operativo ni en el Inicio: nacen sin fecha (`reserva_item.fecha` NULL) y esas pantallas filtran por fecha.
- **Desviaciones de la tarea y por qué:**
  1. **Migración nueva** `ajuste_de_item_id` (la tarea decía "no se esperan"): pedida a propósito, respondida por el usuario.
  2. **`reserva_item_pasajero` se re-arma solo si cambió quién usa el servicio**, y solo se borra a quien salió y se crea a quien entró. La tarea decía "se re-arma desde `pax_incluidos`", pero eso borraba el check-in de quien sigue en el servicio (vive en esa misma fila) y, para un servicio "para todos" con asignación manual, la asignación.
  3. **Datos tomados del ítem nuevo cuando nunca se tocaron:** `proveedor_tarifa_id`/`opcion_hotel_tarifa_id` (si no hay `*_original_id`), `opcion_mayorista_id` (si no hay `opcion_mayorista_original_id`) y el tratamiento tributario (si no hay `motivo_override_tributario`). Lo reasignado o corregido a mano se conserva. Sin esto, cambiar de hotel en la modificación cambiaba el precio pero no el hotel operativo.
  4. **La cotización de modificación recupera el `vendedor_id` de la cotización vendida** al aplicar: sin esto la reserva cambia de dueño (Policy y listados leen ese campo) y puede desaparecer del vendedor original.
  5. **`ReservaItem::alternativaItemIdsYaEnLaReserva()`** (nuevo) en `sincronizarItems` y en el detalle: un servicio facturado que sube de precio deja su ítem nuevo en la versión vigente y no es un pendiente. Lo pide el "Ajuste" (sin esto, facturar dos veces).
  6. **Si la versión nueva cambia el mayorista elegido a otra salida**, aplicar responde 422 (mover el cupo entre salidas no es parte de esta tarea; existe "reasignar mayorista").
  7. **`mayorista_elegida_id`** de la reserva pasa a la opción clonada de la versión nueva (la alternativa vigente cambia; nadie más lo lee).
  8. Extracciones sin cambio de comportamiento: `ReservaItemCreacionService` (de `ReservaController`) y `ReservaItemQuitarService` (de `ReservaItemController::destroy()`).
  9. Los tests se escribieron junto con el código, no antes.
- **Para desplegar:** correr `php artisan tenants:migrate-verticales` (la columna nueva la leen `copiar`, la diferencia y `sincronizarItems`: sin ella fallan).

## Hallazgos fuera de alcance
- **`Cotizacion::estadoResumen()` no cuenta la cotización original como `reservada` tras aplicar** (la vendida queda `reemplazada`): sale del conteo "reservadas/conversión" del Inicio y vuelve a `borrador`/`enviada` en el listado. Arreglo propuesto: tratar `reemplazada` como `aceptada` y mirar la reserva por `reserva_modificaciones`. No se tocó (la tarea pide no arreglar lecturas sin consultar).
- Buscar una reserva por el código de la cotización original deja de encontrarla; el tablero de salidas y el detalle muestran el código `…-M1` (ver inventario).
- `alternativa.total` de la versión nueva suma el ítem con el precio subido **y** el ajuste (sobrestima en el ajuste); la reserva no lo lee (suma sus servicios) y el siguiente cotizador lo recalcula al editar.
- Cambiar `dia_referencial` de un servicio en la modificación no mueve la `fecha` del servicio de la reserva (se conserva, como pide la tarea; mover fechas es `reprogramar`).
- Si un "Ajuste" aún sin facturar y el servicio que ajusta se quitan o bajan en una modificación siguiente, el ajuste queda como un servicio suelto (la diferencia no lo ve): se borra a mano desde la reserva.
- `desglose_costo_snapshot`/`carga_tour` del `reserva_item` no se actualizan con los del ítem nuevo (la mezcla tributaria de facturar usa `carga_tour` y re-armarlo partiría un grupo de líneas ya facturadas a medias).

