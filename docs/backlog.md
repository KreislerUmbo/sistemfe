# Backlog

> **Único lugar donde se escribe el estado de una tarea.** Ningún otro documento dice
> "pendiente", "en curso" o "hecho": enlaza a la línea de acá (`ver T-042`).
> Cómo se usa: `docs/proceso.md`.

**Estados:** `por hacer` · `en curso` · `en revisión` · `hecha` · `bloqueada` (decir por qué)

Cada línea: ID · qué · estado · depende de · rama o commit. El detalle está en
`docs/tareas/T-…md` (se borra al cerrar; para una tarea hecha, el commit es la referencia).
Próximo ID libre: **T-008**.

## Activas

| ID | Módulo | Qué | Estado | Depende de | Rama / commit |
|---|---|---|---|---|---|
| T-001 | AGE | Bloquear el cambio de precio de un servicio en una alternativa aceptada | por hacer | — | |
| T-002 | AGE | Modificar reserva: crear y descartar la modificación (cotización propia + copia) | por hacer | T-001 | |
| T-003 | AGE | Modificar reserva: calcular la diferencia con lo vendido | por hacer | T-002 | |
| T-004 | AGE | Modificar reserva: penalidad por pasajero quitado | por hacer | T-003 | |
| T-005 | AGE | Modificar reserva: aplicar a la reserva | por hacer | T-003, T-004 | |
| T-006 | AGE | Modificar reserva: PDF para el cliente | por hacer | T-003 | |
| T-007 | AGE | Modificar reserva: pantallas | por hacer | T-005, T-006 | |

## Por analizar

> Ideas, pendientes y hallazgos que todavía no son tareas (no tienen criterios de
> aceptación). Una línea cada uno, con origen. Se convierten en tarea o se descartan.

| Módulo | Qué | Origen |
|---|---|---|
| AGE | IGV de la penalidad por cancelación: preguntar al contador (hoy lo elige el vendedor, D-AGE-033) | requerimiento Modificar reserva, 24-oct-2026 |
| AGE | Penalidad calculada con `reglas_cancelacion` (franjas por días) | D-AGE-033 |
| AGE | Agregar pasajero rápido en la reserva para tours por persona (después de probar Modificar reserva) | fila F4 de la hoja de ruta |
| AGE | Enviar el PDF de modificación por correo | T-006, fuera de alcance |
| TRA | Escribir `docs/transversal/seguridad.md` (detalle del checklist de `proceso.md` §4) | `proceso.md`, 24-oct-2026 |
| TRA | Migración del proceso, pasos 3-6 de `proceso.md` §6 (adelgazar `CLAUDE.md`, archivar, pasar pendientes, limpiar memoria) | `proceso.md`, 24-oct-2026 |

## Hechas

> Las últimas, para tener contexto rápido. Las viejas se pueden quitar de acá: el commit
> queda en `git log`.

| ID | Módulo | Qué | Commit |
|---|---|---|---|
