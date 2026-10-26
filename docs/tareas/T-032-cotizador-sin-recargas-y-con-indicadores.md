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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
