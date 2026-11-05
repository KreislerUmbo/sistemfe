# T-086 — Venta directa: el bloque Servicios (y la moneda) como la maqueta aprobada

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-085 (el bloque que se rehace) y T-046 (cerrar antes: toca el mismo archivo) |
| **Reglas** | — |
| **Decisiones** | D-AGE-034 (incluye el enlace a la **maqueta aprobada** «Venta directa — rediseño B», 05-nov) |
| **Rama** | `feat/T-086-venta-directa-servicios-maqueta` |

## Contexto
T-085 construyó el bloque **3 · Servicios** leyendo solo el texto de la tarea, y la pantalla quedó
distinta de la maqueta aprobada (revisión del usuario, 05-nov). La lógica (líneas, vista previa,
precios, afectación) está bien y se prueba; lo que cambia es **cómo se ve y se usa**. Va antes de
T-047 porque T-047 agrega la pestaña «Tours» en este mismo catálogo.

## Alcance
Abrir la maqueta (enlace en D-AGE-034) y seguirla bloque por bloque, con el estilo del sistema
(cards con `card-header`, inputs `-sm`, `CampoFecha.vue`) en vez de sus colores verdes:
- **Sin servicios, el catálogo se abre solo** con «Todavía no agregaste servicios. Elige el
  primero:»; con servicios, se cierra y queda el botón **«+ Agregar servicio» a todo el ancho**
  (borde punteado). «Cancelar» solo si ya hay servicios.
- **Cabecera del catálogo:** «¿Qué servicio agregas?» a la izquierda; a la derecha las pestañas
  («Tarifas de proveedor», «Concepto libre»; T-047 suma «Tours» primera) y «Cancelar».
- **Tarifas de proveedor en tarjetas** (grilla de ~230 px): nombre del servicio, proveedor (o
  «Hotel · tipo de habitación»), precio (con «/ noche» si es hotel) y botón «Agregar» (o «Está en
  dólares» deshabilitado, como hoy). Se mantiene el **filtro de destino propio del catálogo**
  (aprobado en la revisión de T-085) junto al buscador.
- **Concepto libre: formulario dentro del catálogo** (Descripción, Precio unitario, Cantidad,
  Costo unitario «0 si no tiene», botón «Agregar» deshabilitado hasta tener descripción y precio).
  Al agregar se crea la línea ya llena; la tarjeta de la línea muestra «cantidad × precio» (no
  vuelve a ser un formulario).
- **Tarjeta de cada servicio agregado:** etiqueta a la izquierda (TOUR / PROVEEDOR / HOTEL /
  LIBRE); nombre y proveedor; debajo, en una fila, ícono de calendario + selector de fecha
  compacto («jue 5 nov (inicio)», «vie 6 nov · día 2»; en hotel «Entra el …») y el chip de
  afectación; una línea de detalle; total a la derecha; ícono de papelera (sin botón rojo). La
  recién agregada se marca «✓ Agregado» (borde resaltado, como ya hace `linea-resaltada`).
- **Hotel:** «Noches» con contador − / + (mínimo 1) y la estadía al lado.
- **Por persona / por unidad:** dos recuadros que se eligen con un clic; el de unidad lleva
  «Cantidad» con contador − / +. Mientras no se elige, la tarjeta queda **marcada como pendiente**
  (borde naranja, «¿Cómo se cobra? Elige una opción.», total «—», y en el resumen «Por elegir»).
- **Bloque 1, moneda:** dos botones «Soles (S/)» / «Dólares (US$)» en vez del desplegable.

## Fuera de alcance
- Pestaña «Tours» y tarjeta de tour (T-047).
- Resumen con «Crear y cobrar», cobro y casilla de la Amazonía (T-048).
- Cambiar precios, vista previa, payload o reglas del backend: la lógica de T-085 queda igual.

## Dónde tocar
- `admin-start-kit/src/views/agencia-viajes/venta-directa.vue` → bloque `<!-- 3 · Servicios -->`
  (~L153-318), `agregarLibre()`, `abrirCatalogo()`, `catalogoAbierto`, `etiquetaTipo()`,
  `etiquetaDia()`, moneda en el bloque 1 (~L66).
- Si el modo de cobro pendiente cambia lo que se manda: hoy `modoPrecio` por defecto; confirmar
  que una línea sin elegir no se pueda crear (el backend ya lo exige con `modo_precio`).

## Criterios de aceptación
- [ ] **CA1** — Pantalla sin servicios → catálogo abierto con el texto de la maqueta; al agregar uno se cierra y aparece «+ Agregar servicio». → prueba funcional
- [ ] **CA2** — Concepto libre desde el formulario del catálogo → línea llena con «2 × S/ 50.00»; «Agregar» deshabilitado sin descripción o precio. → prueba funcional
- [ ] **CA3** — Tarifa que no es hotel recién agregada → pendiente (naranja, total «—», «Por elegir» en el resumen) hasta elegir por persona o por unidad; «Crear» deshabilitado mientras haya una pendiente. → prueba funcional
- [ ] **CA4** — Hotel con contador de noches → total = precio × noches y la estadía cambia. → prueba funcional
- [ ] **CA5** — Moneda con dos botones; una tarifa en la otra moneda sigue deshabilitada. → prueba funcional
- [ ] **CA6** — Los tests de venta directa siguen en verde sin tocarlos; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: el backend ya valida; el formulario de concepto libre solo evita mandar vacío
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica (la vista previa calcula)
- [ ] Fechas con `HoraPeru`: las etiquetas de fecha con `helpers/fecha.ts` (`sumarDiasISO`), nunca `new Date().toISOString()`
- [ ] Tributario sin fallback silencioso: el chip de afectación sale de la vista previa, como hoy
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=VentaDirecta
cd admin-start-kit && npm run type-check
```
Prueba funcional en agencia-demo (`/agencia-viajes/venta-directa`), con la maqueta abierta al lado:
una tarifa por persona, un hotel de 2 noches y un concepto libre.

---

## Resultado
- **Commits:** uno solo en `feat/T-086-venta-directa-servicios-maqueta` (construida por Sonnet sin commitear; revisada y commiteada por Opus).
- **Revisión (Opus, 05-nov):** prueba funcional en agencia-demo con Playwright, sin errores en consola. CA1: sin
  servicios el catálogo abre solo en tarjetas. CA2: «Agregar» del concepto libre deshabilitado sin descripción o
  precio; «Cena show» 2 × S/ 50.00 → S/ 100.00. CA3: Desayuno recién agregado en naranja, total «—», «Por elegir»
  en el resumen; con «Por persona» → S/ 60.00 (2 adultos). CA4: hotel doble, 2 noches con el contador → S/ 560.00,
  «Sale el sáb 07/11». CA5: en dólares la tarifa en soles muestra «Está en soles». Total S/ 720.00.
  Ajustes: el archivo había quedado entero en CRLF (se volvió a LF); el texto de «Por persona» quedaba centrado
  en vertical (ahora arriba, como «Por unidad»). El precio mayor que 0 del concepto libre es el de la maqueta.
- **Tests:** `php artisan test --filter=VentaDirecta` → 38 pasan (153 aserciones), sin tocarlos. `npm run type-check`: ningún error en `venta-directa.vue` (los que salen son de archivos ajenos: fake-backend, portal, router). **Prueba funcional en el navegador (CA1-CA5): no hecha** — el navegador de Playwright estaba ocupado; falta que la revises en agencia-demo con la maqueta al lado.
- **Desviaciones de la tarea y por qué:**
  - Solo se tocó el frontend (`venta-directa.vue`); el payload y el backend quedan igual. Una línea sin elegir cobro sigue sin poder crearse (`faltante` ya lo exigía).
  - La fecha de cada servicio sigue siendo un selector de «día del viaje» (no un `CampoFecha`), porque el dato es el número de día, no una fecha libre. Va compacto, con ícono de calendario.
  - «Agregar» del concepto libre exige precio mayor que 0 (como la maqueta); antes se aceptaba 0.
  - La tarjeta recién agregada se marca con el `linea-resaltada` de siempre (2,5 s), ahora con borde verde y «✓ Agregado».

## Hallazgos fuera de alcance
-
