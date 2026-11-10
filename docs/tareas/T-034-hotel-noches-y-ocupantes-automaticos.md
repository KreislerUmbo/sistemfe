# T-034 — Hotel: noches calculadas y ocupantes marcados por defecto (R2)

| | |
|---|---|
| **Módulo** | AGE (frontend) |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 9, R2 (aprobada 26-oct-2026) |
| **Rama** | `feat/T-034-hotel-noches-ocupantes` |

## Contexto
Medido (obs. 9): al elegir una habitación, **Noches = 1** aunque el viaje sea del 10 al 13 (3
noches), y **ningún pasajero** viene marcado como ocupante. Son 2 a 5 acciones extra por hotel y un
riesgo de cotizar 1 noche por olvido.

## Alcance
- `HabitacionMatrixPicker.vue` (flujo Local/Nacional, `pasoDrawer === 'matrizHotel'`):
  - **noches por defecto** = noches del **destino activo** (`fecha_fin - fecha_inicio`) si tiene
    fechas; si no, las de la cotización (`fecha_viaje_hasta - fecha_viaje_desde`); si no hay
    fechas, 1. Si ya hay una comparación abierta, sigue mandando la cantidad del grupo (como hoy).
  - **ocupantes por defecto:** todos los pasajeros marcados (el vendedor desmarca si reparte en
    varias habitaciones).
  - Texto visible "3 noches (10/12 – 13/12)" junto al selector, para que se note de dónde salió.
- Igual en el modo "Comparar varias opciones".
- Mayorista (precio por adulto, "Adultos"): no cambia.

## Fuera de alcance
- Repartir pasajeros en varias habitaciones automáticamente.

## Archivos probables
- `admin-start-kit/src/components/AgenciaViajes/HabitacionMatrixPicker.vue`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `admin-start-kit/src/helpers/fecha.ts` (diferencia en días entre dos fechas `YYYY-MM-DD`, con su test)

## Criterios de aceptación
- [x] **CA1** — Cotización del 10/12 al 13/12: al elegir una habitación, noches = 3 y los 3 pasajeros marcados. → prueba funcional
- [x] **CA2** — Con 2 destinos con fechas propias, usa las noches del destino activo. → prueba funcional
- [x] **CA3** — Sin fechas: noches = 1. → prueba funcional
- [x] **CA4** — Función de diferencia de días con su test (incluido cambio de mes). → Vitest
- [ ] **CA5** — `type-check`, `build` y Vitest sin errores.

## Seguridad y correctitud
- [ ] Permisos: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: no aplica
- [ ] Concurrencia: no aplica
- [ ] Dinero: la cantidad sigue siendo editable antes de agregar
- [ ] Fechas: diferencia de fechas de negocio sin zona horaria (`YYYY-MM-DD`, como `sumarDiasISO`)
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
cd admin-start-kit && npx vitest run src/helpers/fecha.test.ts && npm run type-check && npm run build
```

---

## Resultado
- **Commits:** rama `feat/T-034-hotel-noches-ocupantes` (ver `git log`). En revisión, no marcada hecha.
- **Tests:** Vitest `fecha.test.ts` 15/15 (CA4: normal, cambio de mes, cambio de año, iguales, negativo).
  `build` OK. `type-check`: 47 errores, **los mismos con y sin mis cambios** (fake-backend, portal,
  rutas); ninguno en los archivos tocados.
  **CA1–CA3 probados en el navegador (Playwright, agencia-demo, 09-nov):** cotización nueva
  CDKM-1126-0000011 (id 90, Tarapoto 10/12–13/12, 3 adultos, Hotel Rio Sol):
  - CA1: Triple → noches 3, texto "3 noches (10/12 – 13/12)", 3 pasajeros marcados. Al agregar:
    `cantidad 3`, `pax_incluidos [125,126,127]`, PEN 340 × 3 = 1020.00 en el total.
  - CA2: segundo destino Lamas 14/12–16/12 activo → 2 noches, "2 noches (14/12 – 16/12)"; volviendo
    a Tarapoto → 3 noches. "Comparar varias opciones" muestra lo mismo junto a "Noches".
  - CA3: CDKM-1126-0000007 (sin fechas) → noches 1, sin texto, pasajeros marcados (no se agregó nada).
  - Consola sin errores.
  - `pax_incluidos`: el hotel es `tarifa_fija`, el precio no depende de los pasajeros (solo la cama
    adicional, que ahora tiene con quién validar). Diferencia con `null`: la lista queda fija, un
    pasajero agregado después a la cotización no entra solo a esa habitación.
- **Desviaciones de la tarea y por qué:**
  - Prop nuevo `textoNoches` y `marcarTodosLosPasajeros` (opt-in) en el picker; mayorista no los usa.
  - Si hay comparación abierta (`grupoAbierto`), se usa su cantidad y no se muestra el texto de noches.
  - Con todos los pasajeros marcados, `pax_incluidos` viaja con todos los ids (antes `null` si no
    se marcaba ninguno): confirmar que el backend lo trata igual en precio.
  - En modo "Comparar varias opciones" solo cambian noches y texto; `agregarGrupo` sin `pax_incluidos`.

## Hallazgos fuera de alcance
- `npm run type-check` ya falla con 47 errores en `main` (fake-backend, portal, rutas).
- Cosmético (revisión 09-nov), **corregido en la misma rama**: en la fila individual el
  `input-group` de noches no tenía ancho fijo (en el modo comparar sí, `width:110px`); con el texto
  de noches la celda se ensanchaba y el selector − / + se estiraba. Ahora `width:110px` + `mx-auto`,
  verificado en el navegador.
- Dato de prueba: la cotización CDKM-1126-0000011 (id 90) de agencia-demo se creó para esta prueba.
