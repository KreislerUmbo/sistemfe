# T-040 — Dos entradas al cotizador: "tours y servicios" o "paquete de mayorista" (obs. 11)

| | |
|---|---|
| **Módulo** | AGE (frontend) |
| **Depende de** | T-035 (toca el mismo modal; hacer después o junto), T-039 (listas de mayorista) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 11 (aprobada 26-oct-2026) |
| **Rama** | `feat/T-040-entrada-cotizador` |

## Contexto
El cotizador muestra todo junto (servicios de la agencia, mayorista, guías) y se siente cargado. La
mayoría de las cotizaciones son de un solo tipo, pero algunas mezclan (paquete de mayorista + pasaje
Tarapoto–Lima), así que no se separa en dos módulos: se separa **cómo se entra**.

## Alcance
- Menú de Agencia: "Cotizar tours y servicios" y "Cotizar paquete de mayorista" (o una pregunta en
  `nueva.vue`; elegir lo que encaje mejor con T-033, que ya toca `nueva.vue`). Las dos crean una
  cotización normal y llevan al mismo `editar.vue` con un parámetro de entrada en la URL.
- Al llegar por primera vez (cotización sin ítems), el modal "Agregar servicio" se abre solo:
  - **mayorista:** pestaña "Paquete de mayorista" con el formulario de nueva opción abierto;
  - **tours y servicios:** pestaña "Servicios de la agencia" (filtrada por destino, T-035), sin la
    pestaña de mayorista; abajo, enlace "+ Agregar paquete de mayorista".
- Si la alternativa ya tiene un ítem de mayorista, la pestaña de mayorista siempre se ve.
- Sin parámetro de entrada (cotizaciones viejas, enlaces directos): como hoy, todas las pestañas.
- Renombrar pestañas: "Local / Nacional" → "Servicios de la agencia"; "Internacional" → "Paquete de
  mayorista".
- Cargar la lista de mayoristas y de hoteles del mayorista al abrir esa pestaña, no en `onMounted`.
- La entrada **no** se guarda en la base: solo decide cómo arranca la pantalla.

## Fuera de alcance
- Separar la cotización, la reserva o la facturación por tipo.
- Permisos distintos por tipo de entrada.

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `admin-start-kit/src/views/agencia-viajes/cotizador/nueva.vue`
- menú (`menu_items` sembrado: revisar cómo se agrega un ítem en el vertical; ver memoria del menú dinámico)

## Criterios de aceptación
- [ ] **CA1** — "Cotizar paquete de mayorista" → cotización creada, modal abierto en la pestaña de mayorista con el formulario listo. → prueba funcional
- [ ] **CA2** — "Cotizar tours y servicios" → modal en "Servicios de la agencia", sin pestaña de mayorista; el enlace la muestra. → prueba funcional
- [ ] **CA3** — Cotización con ítem de mayorista abierta por la entrada de tours → la pestaña de mayorista se ve. → prueba funcional
- [ ] **CA4** — Cotización vieja abierta desde el listado → todas las pestañas, como hoy. → prueba funcional
- [ ] **CA5** — Entrando por tours, no se pide la lista de mayoristas al abrir la pantalla. → prueba funcional (pestaña Red)
- [ ] **CA6** — `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: los ítems de menú usan el mismo permiso que el cotizador
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: el parámetro de entrada solo acepta los dos valores; otro valor = como hoy
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: solo si el menú necesita filas nuevas en `menu_items`

## Cómo verificar
```
cd admin-start-kit && npm run type-check && npm run build
```
Prueba funcional en agencia-demo con las 4 situaciones de los criterios.

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
