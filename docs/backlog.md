# Backlog

> **Único lugar donde se escribe el estado de una tarea.** Ningún otro documento dice
> "pendiente", "en curso" o "hecho": enlaza a la línea de acá (`ver T-042`).
> Cómo se usa: `docs/proceso.md`.

**Estados:** `por hacer` · `en curso` · `en revisión` · `hecha` · `bloqueada` (decir por qué)

Cada línea: ID · qué · estado · depende de · rama o commit. El detalle está en
`docs/tareas/T-…md` (se borra al cerrar; para una tarea hecha, el commit es la referencia).
Próximo ID libre: **T-001**.

## Activas

| ID | Módulo | Qué | Estado | Depende de | Rama / commit |
|---|---|---|---|---|---|

## Por analizar

> Ideas, pendientes y hallazgos que todavía no son tareas (no tienen criterios de
> aceptación). Una línea cada uno, con origen. Se convierten en tarea o se descartan.

| Módulo | Qué | Origen |
|---|---|---|
| AGE | Modificar reserva aceptada (F4 etapa 2) — requerimiento en análisis, faltan respuestas: `docs/requerimientos/agencia-modificar-reserva.md` | piloto del proceso, 24-oct-2026 |
| AGE | `AlternativaItemController::update()` no rechaza alternativa aceptada (precio vendido editable por API) — visto leyendo, sin probar | requerimiento anterior §2.2 |
| TRA | Escribir `docs/transversal/seguridad.md` (detalle del checklist de `proceso.md` §4) | `proceso.md`, 24-oct-2026 |
| TRA | Migración del proceso, pasos 2-6 de `proceso.md` §6 (piloto, adelgazar `CLAUDE.md`, archivar, pasar pendientes, limpiar memoria) | `proceso.md`, 24-oct-2026 |

## Hechas

> Las últimas, para tener contexto rápido. Las viejas se pueden quitar de acá: el commit
> queda en `git log`.

| ID | Módulo | Qué | Commit |
|---|---|---|---|
