# T-045 — Venta directa: total en vivo, modo de precio claro y fecha por defecto hoy (H42, H44)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 12, H42 y H44 (aprobadas 27-oct-2026) |
| **Rama** | `feat/T-045-venta-directa-total-fecha` |

## Contexto
En Venta directa el vendedor no ve el monto hasta llegar a la reserva, y el modo de precio viene en
"Tarifa fija": una entrada por persona para 4 pasajeros con cantidad 1 cobra una sola entrada. La
fecha es opcional y, si queda vacía, el servicio no sale en el Reporte operativo.

## Alcance
- **Total en vivo con un endpoint de vista previa** (decisión 04-nov-2026). Motivo: el backend
  clasifica a los pasajeros por edad con `ConfiguracionAgencia`, y un vendedor no puede leerla
  (`GET configuracion-agencia` pide `agencia.configuracion`).
  - `POST venta-directa/vista-previa`, mismo permiso que crear (`reservas.crear`). **No guarda
    nada**: ni cotización, ni correlativo, ni lock.
  - Recibe `pax` y, para proveedor, `proveedor_tarifa_id` + `cantidad`. Clasifica a los pasajeros
    con el mismo `umbralesEdad()`/`derivarTipoPax()` de `VentaDirectaController`.
  - Devuelve **las dos opciones**, `por_persona` y `tarifa_fija`, cada una con total, moneda de la
    tarifa y desglose (`{adulto, nino, infante}` × precio unitario). Si la tarifa está retirada o
    fuera de vigencia, responde 422 con el mismo mensaje que `crearItemProveedor()`.
  - **La fórmula vive en un solo lugar:** se saca de `AlternativaItemController::crearItemProveedor()`
    a un método puro (p. ej. en `PriceEngineService`: tarifa + conteo + modo + cantidad → venta y
    total, con el mismo redondeo que `convertirMoneda()` y `getTotalConvertidoAttribute()`). La
    usan `crearItemProveedor()` y la vista previa. Sin cambiar resultados: la suite del cotizador
    sigue en verde sin tocar sus asserts.
  - **Concepto libre:** total = `round(precio, 2) × cantidad`, calculado en Vue (sin conversión:
    la moneda de la alternativa es la del concepto). No llama al endpoint.
  - En Vue: llamada con debounce (~300 ms) al cambiar tarifa, cantidad o pasajeros; se descarta
    una respuesta vieja si ya llegó una más nueva.
- **Modo de precio sin valor por defecto** para una tarifa de proveedor: dos opciones visibles,
  "Por persona (N pasajeros): X" y "Por unidad (cantidad C): Y", con el total de cada una **en la
  moneda de la tarifa** (S/ o US$, no siempre S/). "Por persona" muestra el desglose (p. ej.
  "2 adultos × 30 + 2 niños × 0"). Al cambiar de tarifa, el modo vuelve a "sin elegir".
  La cantidad solo se muestra en "Por unidad"; en "Por persona" se envía `cantidad = 1` (el total
  no la usa).
- **Fecha obligatoria**, por defecto `hoyPeru()`, con acceso rápido "Mañana" (`sumarDiasISO()`).
  Backend: `fecha_servicio` pasa a `required|date`. **Se acepta una fecha pasada** (decisión
  04-nov-2026: para registrar una venta que se cargó tarde).
- El total se muestra también en el botón: "Crear venta directa · X" (con su moneda).
- Tests existentes que cambian: `test_sin_fecha_de_servicio_el_item_queda_sin_fecha` se reemplaza
  por el de CA3; `test_concepto_libre_sin_costo_responde_422_claro` necesita `fecha_servicio` para
  seguir probando el costo y no la fecha.

## Fuera de alcance
- Tours (T-047), pasajeros con residencia (T-046), cobrar en el mismo paso (T-048).
- Elegir el modo por la unidad de la tarifa: el vendedor elige, viendo los dos totales.
- Precio de niño o infante vacío en la tarifa = 0 en "por persona" (regla de todo el sistema):
  T-045 lo **muestra** en el desglose, no lo cambia (decisión 04-nov-2026; ver "Por analizar").
- Cama adicional y `pax_incluidos`: la venta directa no los usa.

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/venta-directa.vue`
- `admin-start-kit/src/services/admin/ventaDirectaService.ts`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php` (+ `vistaPrevia()`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` (solo extraer la fórmula)
- `api-sistema-fe/app/Services/AgenciaViajes/PriceEngineService.php`
- `api-sistema-fe/routes/api.php`
- `api-sistema-fe/tests/Feature/AgenciaViajes/VentaDirectaBloque1Test.php`

## Criterios de aceptación
- [ ] **CA1** — Tarifa de S/ 30 con 4 pasajeros: las opciones muestran S/ 120 (por persona) y S/ 30 (por unidad); sin elegir una, no se puede crear. → prueba funcional en agencia-demo
- [ ] **CA2** — El total de la vista previa coincide con el de la reserva creada (suma de `total_convertido`), por persona y por unidad, incluida una tarifa en USD y pasajeros niño/infante. → test (vista previa vs. creado). Concepto libre → prueba funcional
- [ ] **CA2b** — La vista previa no crea nada (misma cantidad de cotizaciones, mismo correlativo de `venta_directa`); sin `reservas.crear` → 403. → test
- [ ] **CA3** — Sin fecha → 422. → test
- [ ] **CA4** — La pantalla abre con la fecha de hoy en hora de Perú; "Mañana" pone el día siguiente. → prueba funcional
- [ ] **CA4b** — Tarifa sin precio de niño, 1 adulto + 1 niño: "Por persona" muestra el desglose con el niño en 0. → prueba funcional
- [ ] **CA5** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: `venta-directa/vista-previa` con `permission:reservas.crear`
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: `fecha_servicio` obligatoria
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: el total en vivo redondea igual que el backend
- [ ] Fechas con `HoraPeru`: hoy = `hoyPeru()`, nunca `toISOString()`
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
- **Commits:** rama `feat/T-045-venta-directa-total-fecha` — `docs: T-045 verificada…` + el commit de la construcción (ver `git log`).
- **Tests:** 5 nuevos en `VentaDirectaVistaPreviaTest` (CA2 PEN y USD con niño/infante, CA4b desglose con precio vacío = 0, CA2b no crea nada, tarifa retirada/futura → 422, ruta con `reservas.crear`); `VentaDirectaBloque1Test`: CA3 reemplaza al viejo test y el de costo recibe `fecha_servicio`. `--filter=VentaDirecta`: 13 en verde. Suite `tests/Feature/AgenciaViajes` + `tests/Unit`: 1280 en verde y 1 rojo, el intermitente conocido `Fila13Fase1cFacturacionTest::descuento del combo…`, que pasa solo (32/32). Suite del cotizador sin tocar asserts. `type-check`: sin errores en los archivos tocados (los que hay son previos, en otros archivos).
- **Prueba funcional (agencia-demo, Playwright):** CA4 hoy = 2026-11-04 y «Mañana» = 2026-11-05. CA1 tarifa S/ 30 con 4 pasajeros: «Por persona S/ 120.00», «Por unidad S/ 30.00»; el botón dice «Crear venta directa · S/ 120.00» / «· S/ 30.00» según el modo, y sin elegir no trae total. CA4b tarifa sin precio de niño, 1 adulto + 1 niño: «1 adulto × 90 + 1 niño × 0». Tarifa en USD muestra «US$». No se creó ninguna venta real.
- **Desviaciones de la tarea y por qué:**
  - La fórmula salió a tres métodos de `PriceEngineService` (`motivoTarifaNoUsable`, `ventaYCostoDeTarifa`, `totalDeLinea`) y no a uno: el chequeo de activo/vigente también se comparte para que el 422 sea el mismo mensaje. La cama adicional sigue en `crearItemProveedor()` (la venta directa no la usa).
  - Agregué un botón «Hoy» junto a «Mañana» (la tarea pedía solo «Mañana»), útil si se cambió la fecha.
  - La cantidad está dentro de la tarjeta «Por unidad» (no en un campo aparte), porque el total de esa opción depende de ella antes de elegir el modo.
  - Revisión (etapa 7): mientras se recalcula (debounce o pedido en vuelo) o con datos inválidos,
    los montos se ocultan («…») y «Crear» queda deshabilitado, para no crear con un total distinto
    al que se ve. Sin pasajeros, con una edad vacía o con una cantidad inválida se muestra el motivo
    en lugar del spinner sin fin. Al cambiar de tarifa, la cantidad vuelve a 1. Verificado con
    Playwright (4→5 adultos: el botón pierde el total al instante y vuelve con S/ 1,750.00).
  - No probé de punta a punta en el navegador crear la venta (no quise dejar datos en agencia-demo); la coincidencia vista previa = reserva creada la cubre el test CA2.

## Hallazgos fuera de alcance
- (verificación, 04-nov-2026) Precio de niño/infante vacío = 0 en "por persona": pasado a "Por analizar" del backlog.
