# T-032 — Cotizador: actualizar solo lo que cambió e indicadores de carga (R5, R4)

| | |
|---|---|
| **Módulo** | AGE (frontend) |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 9, R4 y R5 (aprobadas 26-oct-2026); H13 |
| **Rama** | `feat/T-032-cotizador-sin-recargas` |

## Contexto
Medido en pantalla (obs. 9): después de **cada** acción del cotizador la pantalla tarda 1–3 s en
mostrar el cambio, sin ningún indicador, porque casi todas las acciones terminan en
`cargarCotizacion()` (vuelve a pedir toda la cotización con todas sus relaciones y reinicia los
campos de edición). En la biblioteca, al cambiar de categoría la lista vieja queda ~2 s y se puede
hacer clic en el resultado equivocado. Además (H13) al guardar un precio en línea se reinician
todos los campos: lo que se está escribiendo puede perderse.

## Alcance
- **R5 — actualización parcial.** Las acciones que devuelven el ítem o la alternativa actualizados
  (editar precio, agregar ítem, eliminar ítem, elegir opción de grupo, descuento global…) actualizan
  en memoria solo ese ítem y el total de la alternativa, en vez de `cargarCotizacion()`. Revisar qué
  devuelve cada endpoint; si alguno no devuelve lo necesario, agregarlo a su respuesta (sin cambiar
  su lógica). `cargarCotizacion()` queda para cambios de estructura (cambiar de alternativa,
  pasajeros, destinos, aceptar).
- **H13:** `inicializarEdicionItems()` no pisa el valor de un ítem que tiene una edición pendiente
  (timeout activo) ni el del campo con foco.
- **R4 — indicadores.** Biblioteca: al cambiar de categoría, filtro o página, la lista se atenúa con
  un indicador hasta que llega la respuesta, y no se puede hacer clic en ella mientras tanto (las
  respuestas viejas que lleguen tarde se descartan). Acciones del lienzo: el botón o la fila muestra
  su propio indicador (ya existe en algunas; completar en las que falte).
- Medir de nuevo los 3 recorridos de la obs. 9 (Playwright, `agencia-demo`) y anotar en "Resultado"
  los segundos antes y después.

## Fuera de alcance
- Cambios de diseño de la pantalla, del backend de precios o del PDF.
- Optimizar consultas del backend (anotar en "Hallazgos" si alguna resulta lenta).

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `admin-start-kit/src/services/admin/alternativaItemService.ts`, `alternativaService.ts`
- endpoints de `AlternativaItemController` / `AlternativaController` solo si falta algo en su respuesta

## Criterios de aceptación
- [ ] **CA1** — Editar el precio de un ítem no dispara `GET cotizaciones/{id}`; el total se actualiza. → prueba funcional (red del navegador)
- [ ] **CA2** — Agregar y eliminar un ítem tampoco recargan toda la cotización. → prueba funcional
- [ ] **CA3** — Escribir en el precio de un ítem mientras se guarda otro: no se pierde lo escrito. → prueba funcional (H13)
- [ ] **CA4** — Cambiar de categoría en la biblioteca: indicador visible, lista vieja no clicable, sin resultados cruzados si se cambia rápido dos veces. → prueba funcional
- [ ] **CA5** — Totales en pantalla iguales a los del backend después de cada acción (incluidos grupos de hoteles y descuento global). → prueba funcional
- [ ] **CA6** — Medición antes/después de los 3 recorridos en "Resultado".
- [ ] **CA7** — `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: no aplica
- [ ] Concurrencia: descartar respuestas viejas (la última petición gana)
- [ ] Dinero: el total mostrado sale del backend o de `totalEfectivoLocal()` (misma regla)
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
cd admin-start-kit && npm run type-check && npm run build
```
Recorridos de la obs. 9 en `agencia-demo` con Playwright; borrar los datos de prueba al terminar.

---

## Resultado
- **Commits:** rama `feat/T-032-cotizador-sin-recargas` (sin unir a `main`; la tarea sigue en revisión).
- **Tests:** `T032RespuestasParaActualizacionParcialTest` (4) + 61 existentes del controller en verde. `type-check` sin errores en los archivos tocados; `build` ok.
- **Prueba funcional (Playwright, `agencia-demo`; los datos de prueba se borraron y la configuración de la agencia quedó como estaba):**
  - CA1 editar precio: PUT sin GET posterior. CA2 agregar (manual) y eliminar: POST/DELETE sin GET posterior.
  - CA3 (H13): escribir en el ítem A mientras se guarda B conserva lo escrito, con el campo enfocado, en vuelo y desenfocado. En un bloque de tour **falló** (el precio del tour volvía al valor guardado porque Vue vuelve a fijar el `value`); se corrigió con un borrador y se repitió: ya conserva lo escrito y no envía nada al restaurar.
  - CA4 biblioteca: durante la carga `aria-busy`, opacidad y `pointer-events:none`; al terminar vuelve y queda el chip correcto.
  - CA5: pantalla = backend (precio de cada ítem, precio del tour y total) con descuento global **en monto y en %** (la agencia usa monto; el % se probó cambiando el modo un momento y restaurándolo) y con un grupo de hoteles: descuento global, luego elegir la otra opción. Sin recargas de la cotización y con spinner en cada acción.
  - CA6 (medición, `agencia-demo`, alternativa de 2 hoteles + 1 ítem manual; «antes» = frontend de `main` en un worktree, «después» = la rama; mismo backend y datos; **una corrida por árbol**, el servidor local varía): milisegundos hasta la última respuesta de red.

    | Acción | Antes | Después | Recargas de la cotización |
    |---|---|---|---|
    | Editar precio (incluye 400 ms de espera por escritura) | 2147 | 1127 | 1 → 0 |
    | Descuento global | 1189 | 681 | 1 → 0 |
    | Elegir opción de grupo | 1154 | 768 | 1 → 0 |
    | Eliminar ítem (desde «Sí, eliminar») | 1791 | 1382 | 1 → 0 |
- **Desviaciones de la tarea y por qué:**
  - **CA6:** se midieron las 4 acciones que T-032 toca, no los 3 recorridos completos de la obs. 9: son ~80 acciones y T-032 cambia la espera de cada acción, que es lo que se midió (T-033, T-034 y T-019 ya estaban en `main`, así que el «antes» las incluía; corregido en la revisión, 13-nov). No se midió «agregar ítem» (pasa por el drawer de varios pasos); se comprobó solo que no recarga (CA2).
  - **R4 en el lienzo:** ya tenían indicador eliminar ítem, eliminar grupo, elegir opción y eliminar/precio de una línea de tour. Se agregaron el **descuento global** (campo deshabilitado con spinner) y el **guardado del precio de cada ítem** (spinner junto al campo, que sigue editable para no perder lo escrito). No queda acción del lienzo sin indicador.
  - Siguen con `cargarCotizacion()`: altas en bucle (comparación de hoteles), quitar una línea de tour o un grupo (esa respuesta no trae la alternativa) y los cambios de estructura (alternativa, pasajeros, destinos, aceptar).
  - `itemConFoco()` tenía el bug `split(/s+/)` (corregido). H13 ahora también protege a un ítem con el guardado en vuelo.
  - **Corregido en la revisión (13-nov):** al proteger el ítem en vuelo, sus propios campos ya no tomaban lo guardado
    (escribir 340.555 dejaba 340.555 a la vista; el servidor guarda 340.56). `enviarEdicion()` vuelve a poner los
    valores del servidor en ese ítem, salvo que el vendedor haya vuelto a escribir o haya otro guardado suyo en vuelo.
    Probado en el navegador (agencia-demo, ítem 522): campo 340.56 con el foco puesto, sin recargar; dato restaurado.

## Hallazgos fuera de alcance
- 500 intermitente de Apache (`fe_sendauth: no password supplied`, ya conocido) apareció 3 veces durante las pruebas; no tiene relación con esta tarea.
