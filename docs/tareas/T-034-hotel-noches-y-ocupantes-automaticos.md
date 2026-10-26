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
- [ ] **CA1** — Cotización del 10/12 al 13/12: al elegir una habitación, noches = 3 y los 3 pasajeros marcados. → prueba funcional
- [ ] **CA2** — Con 2 destinos con fechas propias, usa las noches del destino activo. → prueba funcional
- [ ] **CA3** — Sin fechas: noches = 1. → prueba funcional
- [ ] **CA4** — Función de diferencia de días con su test (incluido cambio de mes). → Vitest
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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
