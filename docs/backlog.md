# Backlog

> **Único lugar donde se escribe el estado de una tarea.** Ningún otro documento dice
> "pendiente", "en curso" o "hecho": enlaza a la línea de acá (`ver T-042`).
> Cómo se usa: `docs/proceso.md`.

**Estados:** `por hacer` · `en curso` · `en revisión` · `hecha` · `bloqueada` (decir por qué)

Cada línea: ID · qué · estado · depende de · rama o commit. El detalle está en
`docs/tareas/T-…md` (se borra al cerrar; para una tarea hecha, el commit es la referencia).
Próximo ID libre: **T-032**.

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
| T-008 | VEN + CAJ | Eliminar una venta anula sus movimientos de caja (todos los giros) | por hacer | — | |
| T-009 | CAJ | Caja, paso A: la moneda en cada movimiento | por hacer | — | |
| T-010 | VEN + CAJ | Servicio compartido de cobro, con n.º de operación y moneda de pago (Obs. 1) | por hacer | T-009 | |
| T-011 | AGE + CAJ | Cobro al contado al facturar una reserva (Obs. 1) | por hacer | T-010 | |
| T-012 | AGE + CAJ | Traspaso del cobro tras una nota de crédito | por hacer | T-011 | |
| T-013 | CAJ | Caja, paso B: por moneda + cambio de moneda. **Requisito para desplegar T-011 con dólares** | por hacer | T-009 | |
| T-014 | VEN | Forma de pago y n.º de operación en el PDF (Obs. 2) | por hacer | T-010 | |
| T-015 | PLA + AGE | Catálogo central de aerolíneas + panel central (Obs. 3) | por hacer | — | |
| T-016 | AGE | Buscador de aerolíneas, corrección de nombres y aerolínea por defecto (Obs. 3) | por hacer | T-015 | |
| T-017 | AGE | El cliente como primer pasajero al aceptar (Obs. 4) | por hacer | — | |
| T-018 | AGE | "Buscar pasajero" también en Clientes (Obs. 5) | por hacer | T-017 | |
| T-019 | AGE | Comparar hoteles: empezar la comparación con 1 sola habitación (Obs. 6) | por hacer | — | |
| T-020 | AGE | Fotos de hotel con tipo (fachada / habitación) en la galería del proveedor (Obs. 7) | por hacer | — | |
| T-021 | AGE | PDF de cotización: fotos de todos los hoteles de la alternativa (Obs. 7) | por hacer | T-020 | |
| T-022 | AGE | Cotizador: revisión antes de enviar ("¿lista para el cliente?") (Obs. 8) | por hacer | T-028 | |
| T-023 | AGE | PDF de cotización con la comparación de hoteles abierta + descuento bien calculado (H4, H10) | por hacer | — | |
| T-024 | AGE | La comparación de hoteles de una alternativa duplicada es independiente de la original (H1) | por hacer | — | |
| T-025 | AGE | Cotizador: cada acción verifica que la cotización sea del vendedor (H2) | por hacer | — | |
| T-026 | TRA | `CampoFecha` muestra el día correcto aunque reciba una fecha con hora y zona (H11) | por hacer | — | |
| T-027 | AGE | Eliminar un tour clásico en una sola operación (total guardado correcto) + subtotal por destino (H12, H14) | por hacer | — | |
| T-028 | AGE | La versión vigente de una tarifa: solo se ofrece y se usa la vigente; plantillas de tours a la versión nueva (H5, H16, H17) | por hacer | — | |
| T-029 | AGE | Tour clásico desde plantilla: cada servicio nace con su tratamiento tributario; costo sin infantes (H18, H19) | por hacer | — | |
| T-030 | AGE | Una alternativa aceptada no cambia de estado ni de mayorista por API (H3, H8) | por hacer | — | |
| T-031 | AGE | Servicio de tour sin precio de niño: el niño paga como adulto; hoteles no cambian (H7) | por hacer | — | |

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
| AGE | **Revisión de cotizaciones, lo que queda** (26-oct; el resto ya es tarea: T-023 a T-031): **H13** precio en línea que se pierde al recargar (verificar en pantalla); H9 y H15 bajas | `docs/requerimientos/observaciones-pruebas-usuario.md`, "Revisión del flujo de cotizaciones" |
| CAJ + NOT | Devolver dinero al cliente por una NC sin volver a facturar (hoy la NC no mueve la caja en ningún giro) | revisión de fallos de las observaciones, 25-oct-2026 |
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
