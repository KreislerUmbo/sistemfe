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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
