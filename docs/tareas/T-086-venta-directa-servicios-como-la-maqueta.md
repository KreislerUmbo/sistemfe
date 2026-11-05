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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
