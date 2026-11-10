# T-036 — Mayorista: formulario con etiquetas, opción única elegida sola y "solo adultos" a la vista (R6, H22)

| | |
|---|---|
| **Módulo** | AGE (frontend) |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 9, R6 (aprobada 26-oct-2026); H22 (decisión del usuario: **por ahora el paquete de mayorista se cotiza solo por adultos**) |
| **Rama** | `feat/T-036-mayorista-claro` |

## Contexto
Medido (obs. 9): el formulario del mayorista no tiene etiquetas (solo texto gris dentro de cada
campo); hay que pulsar "Hoteles" para ver dónde se cargan y "Elegir mayorista" antes de confirmar
una habitación aunque sea la única opción. **H22:** el precio del paquete se multiplica por los
adultos; con 2 adultos + 1 niño el total fue USD 1,900 (950 × 2) y el panel dijo "Total (2 adultos
+ 1 niño)". El usuario decidió que por ahora se cotiza solo por adultos: lo que falta es que **se
note**, para no cotizar de menos sin darse cuenta.

## Alcance
- `OpcionMayoristaForm.vue`: etiqueta visible en cada campo (Proveedor mayorista, Moneda, Aerolínea,
  Detalle del vuelo, Descripción para el cliente, Incluye, No incluye), estilo de Agencia/Ventas.
- Al crear la **primera** opción de mayorista de una alternativa (o destino), queda **elegida** sola
  y con su sección de hoteles abierta. Con 2 o más opciones, el vendedor elige como hoy.
- **Solo adultos (H22):**
  - en la matriz del mayorista, junto a "Adultos": "Precio por adulto. Los niños no están
    incluidos: si el mayorista los cobra, agrégalos como servicio aparte";
  - en el lienzo y el panel, la línea del paquete dice "× N adultos";
  - si la cotización tiene niños o infantes y la alternativa tiene un paquete de mayorista, el
    total del panel dice "Total" con una nota corta "(el paquete de mayorista no incluye niños)".
    Sin bloquear nada (regla del usuario: ayudas sin fricción).

## Fuera de alcance
- Precio de niño en el paquete de mayorista (pendiente para cuando se decida; anotarlo en "Por analizar").
- El tratamiento tributario del paquete internacional (H21, espera al contador).

## Archivos probables
- `admin-start-kit/src/components/AgenciaViajes/OpcionMayoristaForm.vue`, `HabitacionMatrixPicker.vue`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/OpcionMayoristaController.php` (`store()`: elegir sola la primera, dentro de la misma transacción; respetar el índice único de elegida)

## Criterios de aceptación
- [ ] **CA1** — Primera opción de mayorista creada → queda elegida; la segunda, no. → test
- [ ] **CA2** — Formulario con etiquetas visibles. → prueba funcional
- [ ] **CA3** — Cotización con 2 adultos + 1 niño y paquete de mayorista: la línea dice "× 2 adultos" y el total lleva la nota. → prueba funcional
- [ ] **CA4** — Sin niños, no aparece la nota. → prueba funcional
- [ ] **CA5** — Suite completa en verde; `type-check` y `build` sin errores.

## Seguridad y correctitud
- [ ] Permisos: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: no aplica
- [ ] Concurrencia: elegir la primera respeta el índice único parcial de "elegida"
- [ ] Dinero: no cambia ningún cálculo; solo lo hace visible
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=OpcionMayorista
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:** ver `git log feat/T-036-mayorista-claro` (un commit: `feat(agencia): mayorista con etiquetas, primera opción elegida sola y "solo adultos" a la vista (T-036)`).
- **Tests:** `OpcionMayoristaCrudTest::test_la_primera_opcion_de_mayorista_queda_elegida_y_la_segunda_no` (CA1). Suite completa del backend: 2128 passed (8509 assertions). `vue-tsc`: 47 errores antes y 47 después, ninguno en `editar.vue` ni `OpcionMayoristaForm.vue`; `build-only` compila.
- **Prueba funcional (Playwright, agencia-demo, cotización 79 con 2 adultos + 1 niño temporal):**
  CA2 ✓ etiquetas visibles; CA1 ✓ la primera opción nace "Elegida" y con Hoteles abierto; aviso "Adultos: precio por adulto…" en la matriz ✓;
  CA3 ✓ lienzo y panel dicen "× 2 adultos" y el total dice "Total (el paquete de mayorista no incluye niños)";
  CA4 ✓ sin el niño el total vuelve a "Total (2 adultos)". Los datos de prueba (niño, opción, hotel, tarifa, ítem) se borraron y el total de la alternativa se recalculó (600.00).
- **Desviaciones de la tarea y por qué:**
  - "Elegida sola" se decide por **alternativa**, no solo por destino: el índice único parcial `opcion_mayorista_alternativa_elegida_unique` es por `alternativa_id`. Con varios destinos, la primera opción de un destino queda elegida solo si la alternativa no tiene ya otra elegida; si no, queda candidata (el vendedor elige como hoy).
  - El aviso "precio por adulto" va como párrafo encima de la matriz (en `editar.vue`), no dentro de `HabitacionMatrixPicker.vue` (no se tocó).
  - Además de las 7 etiquetas pedidas, se rotuló "Contenido reutilizable (opcional)" para que ningún campo del formulario quedara sin etiqueta.

## Hallazgos fuera de alcance
- Precio de niño en el paquete de mayorista: pendiente de decisión (anotar en "Por analizar").
