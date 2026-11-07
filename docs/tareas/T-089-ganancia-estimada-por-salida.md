# T-089 — Ganancia estimada por salida operativa

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-088 (mínimo y servicios por grupo bien cargados; usa `PaquetePlantilla::serviciosPorGrupo()`) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/agencia-precio-y-margen.md` → P8 (a) y respuesta 7 (se juntan pasajeros de distintas reservas: el control de la ganancia va en la salida) |
| **Rama** | `feat/T-089-ganancia-por-salida` |

## Contexto
Los tours son compartidos: una salida junta pasajeros de varias reservas, y el vehículo y el guía
se pagan igual vayan 2 o 10. Hoy la salida solo avisa «bajo mínimo»; no dice si ese día se gana o
se pierde. Esta tarea **estima** la ganancia con las tarifas. La ganancia **real**, con las
facturas, llega con Compras (`plan-tours-venta-simple-costo-real.md`, "Medir lo real", fase 3) y
reemplaza estos montos en la misma pantalla.

## Alcance
- **Cálculo** en un método de servicio, por ejemplo
  `TourCostoEstimadoService::estimarSalida(SalidaOperativa $s): array`, sin consultas en bucle:
  - **Cobrado:** suma del precio vendido de los `reserva_items` enganchados a la salida
    (`salida_operativa_id`), de reservas no canceladas (mismo filtro que
    `SalidaOperacionService::reservasActivas()`). Es el monto de la línea de tour, ya
    descontado si vino en un combo.
  - **Pasajeros que cuentan:** los que ocupan asiento, con el mismo criterio que el tablero (el
    infante no cuenta, `SalidaOperacionService::ocupan()`).
  - **Costo variable:** Σ costo de las tarifas por persona del Incluye del tour × pasajeros que
    cuentan. Si un servicio no tiene tarifa, se usa su `costo_estimado_persona`.
  - **Costo fijo:** cada tarifa privada (sin hotel) × **cantidad de vehículos de la salida**
    (`salida_vehiculos`; si no hay ninguno, 1). Guía: `costo_diario` × días del tour (máximo
    `dia_relativo`, como `TourCostoEstimadoService::calcular()`).
  - **Temporada:** se usa la tarifa de la temporada de la fecha de la salida si existe (reusar
    `tarifaDeTemporada()`), y si no, la de referencia.
  - **Resultado:** `cobrado`, `costo_fijo`, `costo_variable`, `ganancia`, `markup_pct`,
    `margen_pct` (con el helper o la fórmula de T-087), `pasajeros`, `completo` (false si algún
    servicio no tiene costo) y `avisos` (servicios sin costo, montos en otra moneda).
- **Moneda:** todo en la moneda del tour. Un `reserva_item` en otra moneda **no se convierte**: se
  suma aparte y se avisa («1 reserva en USD no está sumada»).
- **Dónde se ve:**
  - **Detalle de la salida** (`SalidaOperativaController::show()` → `resumenSalida($s, true)`):
    bloque «Ganancia estimada» con `Cobrado · Costo fijo · Costo por persona · Ganancia` y la
    leyenda «Estimada con las tarifas; la real llega con Compras». En rojo si es negativa.
  - **Inicio** (`DashboardService`, lista de salidas): la ganancia junto a «bajo mínimo», en rojo si
    es negativa.
- **Quién la ve:** solo quien tiene `reservas.ver_todas`. Para los demás, el backend **no
  manda** los montos (no basta con ocultarlos en la pantalla).

## Fuera de alcance
- Costo real con facturas (Compras, fase 3 del plan de tours).
- Costo del vehículo por salida escrito a mano: hoy `salida_vehiculos` no tiene monto. Si hace
  falta, va como hallazgo y se resuelve con Compras.
- Restar el IGV (P4b, espera al contador).
- Salidas privadas (`privada_reserva_id`): se calculan igual, sin trato especial.
- El error E5 (guía de 1 día en el cotizador): esta tarea usa los días del tour, igual que la
  calculadora.

## Dónde tocar
- `api-sistema-fe/app/Services/AgenciaViajes/TourCostoEstimadoService.php` → método nuevo; reusar `tarifaDeTemporada()` (~150)
- `api-sistema-fe/app/Services/AgenciaViajes/SalidaOperacionService.php` → `reservasActivas()` (~383), `ocupan()` (~391): reusar, no copiar
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/SalidaOperativaController.php` → `show()` (~86), `resumenSalida()` (~572)
- `api-sistema-fe/app/Services/Dashboard/DashboardService.php` → bloque de salidas (~415-465)
- Pantallas del detalle de salida y de Inicio en `admin-start-kit/src/views/` (buscar quién consume `salidas-operativas/{id}` y `salidas` del dashboard)

## Criterios de aceptación
Escenario base: movilidad privada 300, guía 150 por día (1 día), entrada 20 + almuerzo 25 por
persona, precio 205 por persona.

- [ ] **CA1** — Salida con 2 reservas de 2 adultos (4 pax), 1 vehículo: cobrado 820, fijo 450,
  variable 180, ganancia 190. → `GananciaSalidaTest::test_salida_con_cuatro_pasajeros`
- [ ] **CA2** — La misma salida con 1 reserva de 2 pax: ganancia −130. → `…::test_salida_bajo_minimo_pierde`
- [ ] **CA3** — Dos vehículos en la salida: el costo fijo de la movilidad se duplica. → `…::test_dos_vehiculos`
- [ ] **CA4** — Un infante no suma costo variable; una reserva cancelada no suma ni cobrado ni
  pasajeros. → `…::test_infante_y_cancelada`
- [ ] **CA5** — Tarifa de temporada vigente en la fecha de la salida: se usa su costo. → `…::test_tarifa_de_temporada`
- [ ] **CA6** — Servicio sin costo: `completo = false` y aviso con su nombre. → `…::test_servicio_sin_costo`
- [ ] **CA7** — Reserva en otra moneda: no se suma y hay aviso. → `…::test_otra_moneda`
- [ ] **CA8** — Usuario sin `reservas.ver_todas`: `show()` y el dashboard no traen los montos. → `…::test_sin_permiso_no_ve_montos`
- [ ] **CA9** — Pantalla: el bloque en el detalle y la ganancia en Inicio, en rojo si es negativa. → prueba en pantalla en `agencia-demo`

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: sin rutas nuevas; los montos solo con `reservas.ver_todas` (CA8)
- [ ] Aislamiento de tenant: no aplica (todo en la base del tenant)
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica (solo lectura)
- [ ] Dinero en centavos: sumar en centavos enteros y redondear una sola vez
- [ ] Fechas con `HoraPeru`: la fecha de la salida es fecha de negocio; la temporada se resuelve con esa fecha, sin `now()`
- [ ] Tributario sin fallback silencioso: no aplica (sin IGV en esta tarea)
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=GananciaSalidaTest
```
Prueba funcional en `agencia-demo`: una salida con varias reservas → detalle de la salida e
Inicio; con un usuario sin `reservas.ver_todas` no aparecen los montos.

---

## Resultado
- **Commits:** rama `feat/T-089-ganancia-por-salida` (backend, frontend y docs; ver `git log`).
- **Tests:** `GananciaSalidaTest`, 8 tests (CA1-CA8), todos en verde. Suite completa del backend:
  2079 passed. `npm run type-check`: los mismos 19 archivos con error antes y después (previos, ninguno
  de esta tarea). CA9 (pantalla): pendiente de la prueba conjunta en `agencia-demo`.
- **Desviaciones de la tarea y por qué:**
  - `SalidaOperacionService::reservasActivas()` y `ocupan()` pasaron de `private` a `public` para reusarlas.
  - `tarifaDeTemporada()` ahora acepta la fecha de vigencia (antes miraba "hoy") y delega en una versión
    por lote (`tarifasDeTemporada()`), para no consultar una vez por servicio. Sin esa fecha la tarifa
    vigente se evaluaba contra hoy, no contra el día de la salida.
  - Una reserva en otra moneda no suma su cobrado, pero **sus pasajeros sí cuentan** para el costo por
    persona (el costo es real). Eso hace la ganancia más pesimista; el aviso lo dice.
  - Una tarifa inutilizable (hotel u otra moneda) cae al `costo_estimado_persona` del servicio; sin ese
    costo, cuenta como «sin costo».
  - El Inicio manda solo `{moneda, ganancia, completo}`; el desglose va solo en el detalle.
  - Cobrado = `AlternativaItem::total_convertido` (ya con descuento), en la moneda de la alternativa.
  - `estimarSalida()` devuelve `null` si la salida no tiene tour de origen.

## Hallazgos fuera de alcance
- `costo_estimado_persona_ext` (costo del extranjero) no se usa: la estimación aplica el costo nacional
  a todos los pasajeros, como pide la tarea.
- Con varios vehículos, el guía se cuenta una vez por salida; los guías por vehículo
  (`salida_vehiculos.guia_id`) no suman costo.
- En el Inicio, la estimación hace 1 consulta de carga para todas las salidas y unas 4 **por salida**
  (Incluye, itinerario, temporada y tarifas): con el máximo de 8 salidas, unas 32 por carga. No es una
  por servicio, pero tampoco está agrupada por lote. Si el Inicio se pone lento, ese es el lugar.
- El detalle muestra «Costo variable» (total) con el unitario debajo («S/ 45 por persona × 4 pax»);
  la tarea decía «Costo por persona», pero mostraba el total y confundía.
