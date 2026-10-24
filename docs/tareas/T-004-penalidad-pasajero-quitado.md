# T-004 — Penalidad por quitar un pasajero en una modificación

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-003 |
| **Reglas** | R-AGE-05, R-AGE-06 |
| **Decisiones** | D-AGE-033 |
| **Rama** | `feat/T-004-penalidad-pasajero` |

## Contexto
Quitar un pasajero no facturado lleva una penalidad según los términos y condiciones. Como hoy
son texto libre, el vendedor escribe el monto y el motivo, y elige el tratamiento de IGV (no
hay valor por defecto: está pendiente de confirmar con el contador). La penalidad es un
servicio más de la versión nueva, así que la diferencia, el aplicar y la facturación la tratan
como cualquier otro servicio.

## Alcance
- Columna `alternativa_items.penalidad_reserva_pasajero_id` (nullable): el pasajero de la
  reserva al que corresponde.
- Rutas, con el mismo permiso y Policy que T-002:
  - `POST reservas/{id}/modificacion/penalidades`
    `{ reserva_pasajero_id, monto, motivo, tip_afe_igv, destino_tributario }`: crea un ítem
    `origen_tipo = manual` en la alternativa nueva, llamado
    "Penalidad por cancelación — <nombre del pasajero>", con el motivo como descripción.
  - `PUT` y `DELETE` de la misma penalidad.
- Validaciones:
  - el pasajero está `quitado` en la diferencia y **no** está facturado;
  - solo una penalidad por pasajero;
  - `monto` ≥ 0 y múltiplo de 0.01;
  - `motivo` obligatorio;
  - `tip_afe_igv` y `destino_tributario` obligatorios, con los mismos valores válidos que
    acepta un ítem manual del cotizador hoy.
- Si el pasajero vuelve a la modificación, su penalidad deja de valer: la diferencia la marca
  como sobrante y bloquea hasta que se borre. No se borra sola.
- T-003 deja de marcar `falta_penalidad` para los pasajeros que ya tienen la suya. La
  penalidad aparece en la diferencia como `agregado`.

## Fuera de alcance
- Calcular el monto con `reglas_cancelacion` (después).
- Fijar un tratamiento de IGV (se escribe una decisión nueva cuando responda el contador).

## Archivos probables
- migración nueva; `AlternativaItem.php`
- `ReservaModificacionController` o un controller propio de penalidades
- `ModificacionDiferenciaService` (de T-003)
- `tests/Feature/AgenciaViajes/ReservaModificacionPenalidadTest.php`

## Criterios de aceptación
- [ ] **CA1** — Con un pasajero quitado no facturado, crear la penalidad con monto 50 y motivo
  la agrega a la versión nueva; la diferencia deja de bloquear por él y suma 50.
- [ ] **CA2** — El monto 0 con motivo es válido; sin motivo, 422.
- [ ] **CA3** — Sin `tip_afe_igv`, 422 (no hay valor por defecto).
- [ ] **CA4** — Una penalidad para un pasajero que no está quitado, o que está facturado: 422.
- [ ] **CA5** — Una segunda penalidad para el mismo pasajero: 422.
- [ ] **CA6** — Si el pasajero vuelve a la modificación, la diferencia bloquea por la
  penalidad sobrante.
- [ ] **CA7** — Editar y borrar funcionan, y la diferencia se actualiza.
- [ ] **CA8** — Permisos: sin `reservas.modificar`, 403; un `reserva_pasajero_id` de otra
  reserva, 422 o 404.

## Seguridad y correctitud
- [ ] Permisos / Policy: igual que T-002
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: FormRequest; el pasajero pertenece a **esta** reserva
- [ ] Concurrencia: lock de la reserva al crear, para no duplicar la penalidad de un pasajero
- [ ] Dinero en centavos: el monto se guarda como lo guarda un ítem manual hoy
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: **sin fallback** — obligatorio y elegido por el usuario
- [ ] `v-html`: el motivo se muestra como texto
- [ ] Migraciones: una nueva

## Cómo verificar
```
php artisan test --filter=ReservaModificacionPenalidad
php artisan test
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
