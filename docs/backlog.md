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
| AGE + CAJ | **Obs. 1** — Al contado: forma de pago y n.º de operación al facturar una reserva (controlar caja) | `docs/requerimientos/observaciones-pruebas-usuario.md` §1, 24-oct-2026 |
| VEN | **Obs. 2** — PDF: forma de pago y n.º de operación (la condición ya sale desde F5) | observaciones §2 |
| AGE | **Obs. 3** — Catálogo de aerolíneas con buscador; aerolínea por defecto en Pasajeros | observaciones §3 |
| AGE | **Obs. 4** — El cliente que cotiza como primer pasajero por defecto | observaciones §4 |
| AGE | **Obs. 5** — "Buscar pasajero" también busca en Clientes | observaciones §5 |
| TRA | Sesión por inactividad: hoy el JWT vence a la hora fija desde el login (`JWT_TTL`, 60 min por defecto) y el aviso sale 2 min antes **aunque el usuario esté trabajando**. Propuesta: renovar en silencio mientras hay actividad y mostrar el aviso solo tras N minutos sin uso (falta decidir N). Solo frontend: `helpers/sessionExpiryWatcher.ts` + `helpers/http-client.ts`; `auth/refresh` ya existe | pregunta del usuario, 24-oct-2026 |

## Hechas

> Las últimas, para tener contexto rápido. Las viejas se pueden quitar de acá: el commit
> queda en `git log`.

| ID | Módulo | Qué | Commit |
|---|---|---|---|
| — | TRA | **Hotfix en producción:** eliminar venta y aceptar NC fallaban en retail y créditos (`reserva_ventas`). Desplegado 24-oct-2026 | `89a1ff7` (rama de trabajo: `224137c`) |
| F5 | AGE | Condición de pago al facturar una reserva (contado / crédito con cuotas) y en el PDF de todos los giros; venta de reserva no editable desde Ventas | `37eed40` (sin unir a `main`) |
| F1 | AGE | El comprobante de una reserva detalla los servicios cobrados | `9416387` (sin unir a `main`) |
| F1 | AGE | Casillas para elegir qué servicios entran; céntimos del tour; confirmar tributario encima del modal | `c957976`, `1a56a64` (sin unir a `main`) |
