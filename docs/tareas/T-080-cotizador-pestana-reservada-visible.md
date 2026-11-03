# T-080 — Cotizador: la alternativa reservada se distingue a simple vista y se abre por defecto

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | — |
| **Rama** | `feat/T-080-pestana-reservada` |

## Contexto
Observación del usuario (02-nov-2026, con CDKM-1126-0000003): en una cotización ya reservada el ✓
de la pestaña de la alternativa casi no se ve. Hereda el color de la pestaña: blanco sobre azul si
está activa y gris sobre gris claro si no. Además, al entrar a la cotización se abre siempre la
primera alternativa, aunque la reservada sea otra. Es solo visual y no es urgente.

## Alcance
- Indicador en la esquina de la pestaña de la alternativa, con el mismo patrón que el contador de
  notas de crédito de Ventas (`sale/index.vue` ~297: `badge rounded-pill position-absolute top-0
  start-100 translate-middle`):
  - **aceptada (reservada):** círculo verde (`bg-success`) con ✓ y borde verde de 2px en la
    pestaña, para que se vea aunque esté activa;
  - **reemplazada:** círculo gris (`bg-secondary`) con un ícono de historial, para distinguirla
    de la vigente;
  - **descartada:** sin cambios.
  - El texto «Reservada · código» con el enlace a la reserva se mantiene.
- Al abrir la cotización, la pestaña inicial es la **aceptada**; si no hay, la **reemplazada**; si
  no hay ninguna, la primera, como ahora. Solo en la primera carga: si el usuario cambia de
  pestaña, los recargos posteriores la respetan.

## Fuera de alcance
- El listado de cotizaciones (`cotizador/index.vue`): ya muestra el badge «Reservada» y la
  alternativa con su reserva.
- La cotización borrador de una modificación abierta: es otra cotización y se abre por su enlace.
- Elegir la pestaña por la URL (`?alternativa=`).

## Dónde tocar
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue` → pestañas de alternativas
  (~199-225): agregar `position-relative` a `.alt-pill`, que no tiene `overflow:hidden`.
- Mismo archivo → `cargarCotizacion()` (~1913): la elección inicial en vez de `alternativas[0]`.
- Mismo archivo → `<style scoped>` (~4584, junto a `.alt-pill-delete`), si hace falta el borde.

## Criterios de aceptación
- [ ] **CA1** — Dada una cotización reservada, la pestaña aceptada muestra el círculo verde en la
  esquina y el borde verde, tanto activa como inactiva → manual (agencia-demo, Playwright).
- [ ] **CA2** — Una alternativa reemplazada muestra el círculo gris, distinto del de la vigente
  → manual, con una reserva modificada.
- [ ] **CA3** — Al entrar a una cotización cuya reservada no es la primera, se abre la reservada
  → manual.
- [ ] **CA4** — Si se elige otra pestaña y después se recarga la cotización (por ejemplo, al
  editar pasajeros o agregar un ítem), se queda en la elegida → manual.
- [ ] **CA5** — Una cotización sin alternativas aceptadas o reemplazadas abre la primera, como
  ahora → manual.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
agencia-demo (`http://agencia-demo.sistemafe.test:5173`): abrir una cotización reservada cuya
alternativa aceptada no sea la primera y otra con una reserva modificada (alternativa reemplazada).
`npx vue-tsc --noEmit` en `admin-start-kit`.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
