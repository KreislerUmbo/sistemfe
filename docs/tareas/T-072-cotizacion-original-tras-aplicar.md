# T-072 — La cotización original sigue como "reservada" tras aplicar una modificación

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-005 |
| **Reglas** | R-AGE-08 |
| **Decisiones** | D-AGE-030, D-AGE-031 |
| **Rama** | `fix/T-072-cotizacion-original-tras-aplicar` |

## Contexto
Al aplicar una modificación (T-005), la alternativa vendida queda `reemplazada` y la reserva pasa
a la alternativa de la cotización de modificación (código `…-M1`). Dos lecturas se ven mal desde
ese momento (inventario de la revisión de T-005, 30-oct):
1. `Cotizacion::estadoResumen()` solo mira la alternativa `aceptada`: la cotización original
   vuelve a `borrador`/`enviada` en el listado comercial y sale de "reservadas / conversión" del
   Inicio, aunque su reserva sigue activa.
2. El tablero de salidas muestra como código de la reserva el de su cotización vigente
   (`…-M1`), sin usar el código propio de la reserva.

Hoy nadie puede aplicar desde la pantalla, así que todavía no se ve: tiene que estar antes de
T-007.

## Alcance
- `estadoResumen()`: una cotización con una alternativa `reemplazada` cuenta como `reservada`
  (o `anulada` si la reserva está cancelada). La reserva se encuentra por
  `reserva_modificaciones.alternativa_vendida_id` (la reserva ya no apunta a esa alternativa).
  Evitar N+1 en el listado: precargar lo necesario donde hoy se precarga `alternativas.reserva`.
- Revisar si el listado de cotizaciones o el Inicio filtran o cuentan "reservadas" en SQL
  (`whereHas` sobre `estado = 'aceptada'`) además de `estadoResumen()`; si es así, mismo criterio.
- Tablero de salidas: código de la reserva = `reserva.codigo` con fallback al de la cotización,
  igual que `ReporteOperativoController` (~224, ~469).
- **Ajuste sumado dos veces (hallazgo de T-006, 30-oct).** Tras aplicar con un ajuste, la
  versión vigente tiene el servicio facturado con el precio nuevo **y** el «Ajuste — …»
  (`alternativa_items.ajuste_de_item_id` no nulo), que ya está dentro de ese precio. Todo lo que
  suma los ítems de una alternativa cuenta la diferencia dos veces. La reserva está bien (su
  servicio facturado sigue al precio viejo + el ajuste): no tocarla. Excluir los ítems con
  `ajuste_de_item_id` en:
  - `AlternativaItem::calcularTotalEfectivo()` (de ahí sale `alternativas.total`, vía
    `AlternativaController` ~358, `AlternativaItemController` ~1787, `OpcionHotelController`
    ~348, `OpcionMayoristaController` ~267, `PenalidadPasajeroService` ~123);
  - `AlternativaPdfService` (~173: `totalOriginal` suma `$alternativa->items` aparte, y la
    lista de servicios del PDF de cotización);
  - revisar si `crearAjuste()` de `ModificacionReservaService` actualiza `alternativas.total`
    al crear el ajuste, y dejarlo en el total correcto.
  Buscar con `grep` otros `->items` que sumen precio (listado, Inicio, `Alternativa::total`) y
  anotar lo que se encontró.

## Fuera de alcance
- Que buscar una reserva por el código de la cotización original la encuentre (hallazgo de
  T-005; anotar si resulta trivial, no hacerlo).
- La cotización de modificación (`origen = 'modificacion'`) no aparece en el listado comercial
  (`Cotizacion::delCotizador()`); no cambia.

## Dónde tocar
- `api-sistema-fe/app/Models/AgenciaViajes/Cotizacion.php` → `estadoResumen()` (~111)
- `api-sistema-fe/app/Services/DashboardService.php` → conteo de cotizaciones (buscar `estadoResumen`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionController.php` → `index()` (precarga y filtros por estado)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/SalidaOperativaController.php` (~612)
- `api-sistema-fe/app/Services/AgenciaViajes/SalidaOperacionService.php` (~160)
- Escenario de test: reusar el de `tests/Feature/AgenciaViajes/ReservaModificacionAplicarTest.php`

## Criterios de aceptación
- [ ] **CA1** — Tras aplicar una modificación, la cotización original sale `reservada` en el
  listado de cotizaciones y cuenta en "reservadas" del Inicio.
- [ ] **CA2** — Con la reserva cancelada después de aplicar, la cotización original sale `anulada`.
- [ ] **CA3** — Dos modificaciones aplicadas seguidas: la original sigue `reservada`.
- [ ] **CA4** — El tablero de salidas muestra el código propio de la reserva; sin código propio,
  el de la cotización.
- [ ] **CA5** — El listado de cotizaciones no agrega consultas por fila (contar queries en el test).
- [ ] **CA6** — Tras aplicar con un ajuste (servicio facturado que sube de 100 a 130): el total
  de la alternativa vigente es 130, no 160, y el PDF de su cotización lista el servicio una vez
  con 130 y suma 130.
- [ ] **CA7** — El total de la reserva no cambia (servicio a 100 + ajuste 30) y facturar el
  ajuste sigue funcionando.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica (solo lectura)
- [ ] Dinero en centavos: el total de la alternativa sin el ajuste; la reserva no cambia
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no se esperan

## Cómo verificar
```
php artisan test --filter=cotizacion_original_tras_aplicar
php artisan test
```

---

## Resultado
- **Commits:** rama `fix/T-072-cotizacion-original-tras-aplicar` (un commit; ver `git log`).
- **Tests:** 8 nuevos en `ReservaModificacionAplicarTest` (`--filter=cotizacion_original_tras_aplicar`),
  CA1 a CA7. Sin el arreglo fallan 6 (CA5 y CA7 son guardas: pasan antes y después). Suite
  completa: 1761 pasaron, 0 fallaron.
- **Cómo quedó:**
  - `Alternativa::modificacionAplicada()` (nueva) llega a la reserva por
    `reserva_modificaciones.alternativa_vendida_id`; `Cotizacion::estadoResumen()` la usa con la
    alternativa `reemplazada` (`reservada`, o `anulada` si la reserva está cancelada).
    `Cotizacion::RELACIONES_ESTADO_RESUMEN` es la precarga única que usan el listado y el Inicio.
    El filtro SQL `?estado=` del listado es por el estado literal de la alternativa y no cambia;
    `?estado_resumen=` ya pasa por `estadoResumen()` y queda bien.
  - Tablero de salidas (`SalidaOperativaController` y `SalidaOperacionService`): `reserva.codigo`
    con la cotización como respaldo.
  - Ajuste: `AlternativaItem::calcularTotalEfectivo()` ignora los ítems con `ajuste_de_item_id`
    (cubre `alternativas.total` en todos sus llamadores) y `AlternativaPdfService` los saca de
    `items` antes de armar el PDF (evita el servicio listado dos veces y un "descuento" falso de 50).
- **Desviaciones de la tarea y por qué:**
  - `crearAjuste()` no necesita tocar `alternativas.total`: el total guardado ya es el correcto
    (el ajuste se crea después de fijar el precio nuevo) y ahora cualquier recálculo lo excluye.
  - CA5 compara las consultas con 1 y con 3 filas **sin contar las de `alternativa_items`**: esas
    son un N+1 que ya existía (ver Hallazgos), no lo agrega esta tarea.
  - El filtro de "Cómo verificar" cambió a `cotizacion_original_tras_aplicar` porque los tests
    viven en la clase existente (reusan su escenario) y los nombres van en snake_case.

## Hallazgos fuera de alcance
- **N+1 previo en el listado de cotizaciones:** `Alternativa::$appends = ['tiene_grupos_sin_resolver']`
  lee `items` de cada alternativa al serializar → una consulta a `alternativa_items` por alternativa
  en `CotizacionController::index()`. Arreglo posible: precargar `alternativas.items` o no apendear
  el atributo en el listado.
- `AlternativaController::aplicarDescuentoGlobal()` (~273) recorre todos los ítems, ajustes incluidos,
  y les pone `descuento_pct`: cambiaría el monto del ajuste que cobra la reserva. Hoy no se alcanza
  (una alternativa `aceptada` no se edita), pero conviene excluir `ajuste_de_item_id` ahí también.
- Revisados y sin cambio: `ReservaController` (~1311, suma los ítems de la **reserva**, correcto),
  `RecalculoPorPersonaService` y `TourLineaService` (solo ítems por persona / de tour; el ajuste es
  tarifa fija manual).
