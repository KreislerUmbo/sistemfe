# T-085 — Venta directa: pantalla nueva con varias líneas, fecha por línea, hotel por noches y vista previa con afectación (H46, H47)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-045 (hecha: vista previa, `PriceEngineService::ventaYCostoDeTarifa()`/`totalDeLinea()`) |
| **Reglas** | — |
| **Decisiones** | D-AGE-034 (léela entera antes de empezar); obs. 12, H46 y H47 |
| **Rama** | `feat/T-085-venta-directa-lineas` |

## Contexto
Venta directa admite un solo servicio: tour + traslado son dos reservas y dos comprobantes. El
rediseño aprobado el 05-nov (D-AGE-034) arma la venta con **varias líneas**, cada una con su
fecha, en una pantalla de dos columnas. Esta tarea construye esa estructura con lo que ya existe
(tarifa de proveedor y concepto libre). T-046 (pasajeros), T-047 (tours) y T-048 (cobro) llenan
su parte después, **sin rehacer la pantalla**. Absorbe T-049.

## Alcance

### Backend
- `VentaDirectaController::store()` acepta `lineas[]` (mínimo 1, máximo 20). Cada línea:
  - `origen_tipo`: `proveedor` | `manual` (T-047 suma `tour`).
  - `dia` (entero ≥ 1): día del viaje. Se guarda en `alternativa_items.dia_referencial`; la fecha
    del servicio la calcula `ReservaItemCreacionService` (fecha de inicio + día − 1). No escribir
    `reserva_items.fecha` a mano.
  - Proveedor: `proveedor_tarifa_id`, `modo_precio`, `cantidad`, como hoy.
  - Manual: `descripcion_manual`, `precio_venta_snapshot`, `costo_snapshot`, `moneda_costo`,
    `cantidad`, como hoy.
  - Cada línea pasa por `AlternativaItemController::store()`, como hoy la única. Nada
    reimplementado.
- **Hotel** = tarifa con `tipo_habitacion` no nulo (la misma señal que
  `ReservaFacturacionController` y `ReporteOperativoController::hotelDelItem()`): el backend
  fuerza `modo_precio='tarifa_fija'` y `cantidad` = noches (≥ 1). Si llega `por_persona` para un
  hotel → 422.
- `fecha_servicio` pasa a ser la **fecha de inicio** (`cotizacion.fecha_viaje_desde`).
  `fecha_viaje_hasta` = la fecha más tardía entre los servicios (inicio + día − 1) y la salida de
  cada hotel (inicio + día − 1 + noches).
- **Destino:** `destino_atractivo_id` opcional (validado con `exists` en el tenant). Si llega, el
  texto de `cotizacion.destino` es el nombre del destino. Se sigue aceptando `destino` como texto.
- **Moneda (D-AGE-034, una sola moneda por venta):** el payload trae `moneda` (`PEN` | `USD`,
  por defecto `PEN`) y es la `moneda_cotizacion` de la alternativa, con `tipo_cambio_aplicado = 1`
  como hoy. **No se convierte nada:** una línea de tarifa cuya `moneda` no es la de la venta → 422
  «La venta es en soles y «<servicio>» está en dólares. No se mezclan monedas.». El concepto libre
  toma la moneda de la venta (`moneda_costo` = `moneda`; precio y costo en esa moneda). Los tours
  (T-047) se cobran en la moneda de su precio; si no coincide, el mismo 422.
- **Payload viejo** (una línea con los campos en la raíz, sin `lineas`): sigue funcionando igual,
  como una línea con `dia = 1`.
- `vistaPrevia()` acepta el mismo `lineas[]` y devuelve, **sin guardar nada**:
  - Por línea: `total` (o, en una tarifa que no es hotel y no tiene modo elegido, las dos opciones
    como hoy), `fecha`, `es_hotel`, `noches`, `fecha_salida` (hotel) y su afectación
    (`tip_afe_igv`, `destino_tributario`).
  - General: `total`, `moneda`, `fecha_viaje_hasta` y `facturable`:
    `{ ok, motivo, requiere_confirmar_amazonia }`.
- **`facturable`** sale del **mismo criterio** que el guardia de `ReservaFacturacionController`
  (mezcla de afectaciones, exportación/extranjero, Amazonía sin confirmar). Extraer ese criterio a
  un servicio que trabaje sobre una lista neutral (afectación + carga de tour de cada línea) y que
  usen los dos: la facturación de reservas y la vista previa. **El comportamiento de la facturación
  no cambia**: sus tests siguen en verde sin tocarlos.

### Frontend (`venta-directa.vue`)
Pantalla de dos columnas; en un teléfono el resumen pasa abajo. Estilo de Agencia/Ventas (inputs
`-sm`, cards con `card-header`, `CampoFecha.vue`).
- **Columna principal, tres bloques numerados:**
  1. **Cliente y viaje:** cliente, destino con `DestinoTreeSelect`, «Fecha de inicio» con «Hoy» y
     «Mañana» (`hoyPeru()`, `sumarDiasISO()`) y «Moneda de la venta» (Soles por defecto / Dólares),
     con la ayuda «Todos los servicios tienen que estar en esta moneda.».
  2. **Pasajeros:** el bloque actual sin cambios (T-046 lo reemplaza).
  3. **Servicios:** la lista de líneas y, debajo, el botón «+ Agregar servicio». El botón abre el
     catálogo **en ese mismo lugar**, con pestañas «Tarifas de proveedor» y «Concepto libre» (T-047
     suma «Tours») y un botón «Cancelar». Sin líneas, el catálogo viene abierto. Al elegir, el
     catálogo se cierra y la línea nueva aparece al final, resaltada un momento.
     - El catálogo de tarifas se filtra por el destino elegido (`destino_atractivo_id`, que ya
       incluye descendientes) y muestra resultados **sin escribir**. Un hotel muestra «S/ X / noche».
       Una tarifa en otra moneda se ve con su precio en esa moneda y el botón deshabilitado «Está
       en dólares» (o «en soles»).
     - Cada línea: etiqueta del tipo (Proveedor / Hotel / Libre), nombre, proveedor, selector de
       fecha («mié 4/11 (inicio)», «jue 5/11 · día 2», … hasta el día 14), etiqueta de afectación
       (Exonerado · Amazonía / Gravado / …, según la vista previa), detalle del cálculo, total y
       quitar.
     - Tarifa que no es hotel: las dos tarjetas de T-045 (por persona / por unidad con la cantidad
       adentro), por línea. Mientras no se elija el modo, la línea se ve pendiente.
     - Hotel: solo un contador «Noches» y «Sale el vie 6/11». Sin «por persona».
     - Concepto libre: descripción, precio unitario, cantidad y **costo unitario** («0 si no
       tiene», 0 por defecto, como hoy), los dos en la moneda de la venta. Sin selector de moneda.
- **Resumen (columna derecha, fijo al bajar):** cliente; destino y rango de fechas («del mié 4/11
  al vie 6/11»); pasajeros; una fila por línea con su total («Por elegir» si falta el modo); total
  general, y un solo estado:
  - el motivo de lo que falta (sin pasajeros, sin servicios, modo sin elegir, cantidad inválida,
    o una línea en otra moneda porque se cambió la moneda de la venta después de agregarla: «La
    venta es en dólares y «X» está en soles. No se mezclan monedas: cambia la moneda de la venta o
    quita esa línea.»);
  - el aviso de `facturable.motivo` cuando no es `ok` (mezcla o exportación), dicho **al armar la
    venta**: «… no pueden ir en un mismo comprobante. Usa «Crear» y factura en dos comprobantes
    desde la reserva, o quita esa línea»;
  - o «Listo».
  - El botón de hoy («Crear venta directa · S/ X»), deshabilitado mientras falte algo o el total
    no esté al día. **Un aviso tributario no deshabilita «Crear»**: la venta se crea y se factura
    desde la reserva. T-048 agrega «Crear y cobrar».
- Total en vivo con el mismo cuidado de T-045 (debounce, `vistaPreviaSeq`, `calculando`, «…»
  mientras tanto, `motivoSinCalculo`).
- Textos en tuteo: cambiar los «Ingresá», «Seleccioná», «Agregá», «Completá» que quedan.

## Fuera de alcance
- Tours (T-047), pasajeros por cantidad y residencia (T-046), cobro y confirmación de la Amazonía
  (T-048).
- Paquetes y combos (D-AGE-034: solo desde el cotizador).
- Mayorista y pasaje aéreo.
- Aviso de capacidad de la habitación (la tarifa no la guarda).
- Cama adicional en un hotel de venta directa.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ReservaFacturacionController.php` (solo para
  usar el servicio extraído)
- `api-sistema-fe/app/Services/AgenciaViajes/` (servicio nuevo del criterio tributario)
- `admin-start-kit/src/views/agencia-viajes/venta-directa.vue`
- `admin-start-kit/src/services/admin/ventaDirectaService.ts`
- `admin-start-kit/src/components/AgenciaViajes/DestinoTreeSelect.vue` (usar, no cambiar)

## Criterios de aceptación
- [ ] **CA1** — Traslado (día 1) + concepto libre (día 2) → una reserva con las dos líneas; sus
  `reserva_items.fecha` son inicio e inicio + 1; un solo comprobante al facturar. → test
- [ ] **CA2** — Hotel de 2 noches desde el día 1 → `cantidad = 2`, total precio × 2,
  `fecha_viaje_hasta` = inicio + 2; el reporte operativo muestra la estadía y la salida. → test
- [ ] **CA3** — Hotel con `modo_precio='por_persona'` → 422. → test
- [ ] **CA4** — Venta en soles con una tarifa en USD → 422 y no se crea nada; venta en dólares con
  esa tarifa y un concepto libre → alternativa en USD, concepto libre con `moneda_costo = USD`,
  `tipo_cambio_aplicado = 1`. → test
- [ ] **CA5** — Payload viejo de una línea → igual que hoy. → test (los de T-045 siguen en verde)
- [ ] **CA6** — Vista previa: total general = suma de `total_convertido` de lo creado con el mismo
  payload (varias líneas, hotel, venta en USD); con una línea en otra moneda, la vista previa
  devuelve el motivo en vez del total. → test
- [ ] **CA7** — Vista previa con una línea Amazonía y otra gravada → `facturable.ok = false` con el
  mismo motivo que daría facturar esa reserva; solo Amazonía → `ok = true`,
  `requiere_confirmar_amazonia = true`. → test
- [ ] **CA8** — Tests de `ReservaFacturacion*` en verde sin cambiarlos. → suite
- [ ] **CA9** — Elegir un destino muestra sus tarifas sin escribir; el catálogo se abre y se
  cierra en su lugar; la línea nueva aparece resaltada. → prueba funcional en agencia-demo
- [ ] **CA10** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas; la biblioteca de tarifas pide
  `cotizaciones.ver|ver_todas` y la venta directa `reservas.crear`: si un vendedor tiene solo el
  segundo, el catálogo queda vacío. Comprobarlo en agencia-demo y anotarlo como hallazgo si pasa
- [ ] Aislamiento de tenant: `destino_atractivo_id` y `proveedor_tarifa_id` con `exists` en la base
  del tenant
- [ ] Validación de entrada / archivos: `lineas` 1-20; `dia` 1-14; `cantidad` ≥ 1
- [ ] Concurrencia (`lockForUpdate`): todo en la transacción actual; correlativo con
  `CodigoGeneradorService`, como hoy
- [ ] Dinero en centavos: no hay conversión (una sola moneda); totales redondeados a 2 decimales como hoy
- [ ] Fechas con `HoraPeru`: fecha de inicio con `hoyPeru()`; fechas por línea con `sumarDiasISO()`;
  nunca `new Date().toISOString()`
- [ ] Tributario sin fallback silencioso: la vista previa usa el mismo criterio que la facturación
  (servicio extraído), nunca una copia
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica (si hiciera falta una, parar y
  preguntar)

## Cómo verificar
```
php artisan test --filter="VentaDirecta|ReservaFacturacion|ReporteOperativo"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits** (rama `feat/T-085-venta-directa-lineas`):
  1. `8a56aa5` refactor: `CriterioTributarioService` extraído de `ReservaFacturacionController`.
  2. `a7543fc` feat: `lineas[]`, moneda única, hotel por noches, `fecha_viaje_hasta`, vista previa con afectación.
  3. `bfd507d` feat: pantalla nueva (`venta-directa.vue`, `ventaDirectaService.ts`).
- **Tests:**
  - Nuevos: `VentaDirectaLineasTest` (15: CA1–CA7 y límites) y `CriterioTributarioServiceTest` (6, unitario).
  - `tests/Feature/AgenciaViajes` + `tests/Unit`: 1302 pasan, sin tocar ningún test existente
    (CA5, CA8 y CA10 de backend). Suite completa: ver el resultado final en la conversación.
  - Frontend: `type-check` sin errores en los archivos tocados (quedan 47 previos en otros archivos:
    portal, `fake-backend`, rutas). Prueba funcional en agencia-demo con Playwright (ver abajo).
- **Desviaciones de la tarea y por qué:**
  - **Payload viejo de `vistaPrevia()`:** sin `lineas` conserva su respuesta de T-045
    (`por_persona` / `tarifa_fija` en la raíz) en `vistaPreviaTarifaSuelta()`, para no tocar los
    tests de T-045. La pantalla nueva ya no la usa: queda código sin consumidor (ver hallazgos).
  - **Moneda inválida en la vista previa:** responde 422 con el motivo (mismo mensaje que `store()`),
    no 200 con `motivo`; la pantalla ya la detecta antes de llamar.
  - **CA1 «un solo comprobante»:** el test no emite un comprobante real (esa ruta ya tiene su
    suite): comprueba una sola reserva, las fechas por línea y el total. Que dos líneas sin mezcla
    tributaria no bloquean la facturación lo cubre `CriterioTributarioServiceTest` y el caso
    «solo gravado es facturable» de la vista previa.
  - **`AlternativaItemController::resolverTratamientoTributario()`** pasó de `private` a
    `public static` para que la vista previa use la misma resolución (con el default de la agencia)
    que la creación; sin cambio de comportamiento.
  - **`requiere_confirmar_amazonia`** vale `true` si hay alguna línea Amazonía, aunque `ok` sea falso.
  - La vista previa trata toda línea Amazonía como confirmada (la confirmación es de T-048); por eso
    «Amazonía + gravado» da el mensaje de mezcla, igual que daría facturar esa reserva.
  - Sin migraciones.
  - **Filtro de destino propio del catálogo (agregado en la revisión, 05-nov):** el «Destino» de
    arriba filtraba el catálogo y a la vez era el destino de la reserva; buscar un hotel en otro
    lugar obligaba a cambiar el destino de la venta. Ahora el catálogo tiene su propio filtro, que
    toma el destino de la venta al elegirlo y al abrir el catálogo, y se puede dejar en «Todos».
    Solo pantalla. Varios destinos por venta (`alternativa_destinos`) quedan fuera: ver hallazgos.
- **CA9 — prueba funcional hecha con Playwright en agencia-demo** (sin crear ventas, para no
  ensuciar el demo): el catálogo carga sin escribir; con el destino «Alto Mayo» baja de ~20 a 4
  tarifas; «Está en dólares» sale deshabilitado; la línea nueva aparece resaltada y el catálogo se
  cierra; hotel de 2 noches desde el día 2 → «Sale el dom 08/11» y rango «del jue 05/11 al dom 08/11»;
  cambiar la moneda a dólares muestra el mensaje de «No se mezclan monedas» y deshabilita «Crear».

## Hallazgos fuera de alcance
- **Permiso del catálogo:** `GET proveedor-tarifas` pide `cotizaciones.ver|ver_todas` y la venta
  directa `reservas.crear` (`routes/api.php` ~L985 y ~L1329). Un vendedor con solo el segundo ve el
  catálogo vacío; la pantalla muestra un aviso en el 403 («Usa Concepto libre o pídelo al
  administrador»). No se comprobó con un usuario real en agencia-demo: queda por probar a mano.
- **Voseo en un mensaje de la facturación:** `CriterioTributarioService::MENSAJE_AMAZONIA_SIN_CONFIRMAR`
  (y `MENSAJE_FACTURACION_NO_HABILITADA` en el controller) dicen «Confirmá», «Contactá»; se dejaron
  igual porque la tarea pide no cambiar el comportamiento de la facturación.
- **Código sin consumidor:** `VentaDirectaController::vistaPreviaTarifaSuelta()` (payload de T-045) y
  el camino de payload viejo de `store()` solo los usan los tests; retirarlos cuando se decida que
  ningún cliente externo manda ese formato.
- **Parpadeo breve de «…» al elegir el modo de cobro:** el modo entra en la firma de la vista previa,
  así que al marcar «por persona / por unidad» los totales se recalculan (300 ms). Se podría evitar
  calculando el total de la línea en el front con las dos opciones ya recibidas.
- **Nombre del hotel en la reserva:** el catálogo ahora titula los hoteles por el proveedor porque
  su servicio siempre es «Tipo de Habitación»; vale revisar si otras pantallas tienen el mismo problema.
- **Venta directa no crea `alternativa_destinos`** (hallado en la revisión): crea la alternativa con
  `Alternativa::create()` sin pasar por `AlternativaCreacionService`, así que la reserva queda solo
  con el texto de `cotizaciones.destino`. Si se quieren varios destinos por venta, deducirlos de las
  líneas (cada tarifa ya tiene su destino) y crear una fila por destino, no un campo JSON.

