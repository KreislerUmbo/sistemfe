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
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- (verificación, 04-nov-2026) Precio de niño/infante vacío = 0 en "por persona": pasado a "Por analizar" del backlog.
