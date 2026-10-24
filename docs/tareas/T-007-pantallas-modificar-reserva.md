# T-007 — Pantallas de "Modificar reserva"

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-005, T-006 |
| **Reglas** | R-AGE-01 a R-AGE-10 |
| **Decisiones** | D-AGE-030 a D-AGE-033 |
| **Rama** | `feat/T-007-pantallas-modificar-reserva` |

## Contexto
Conecta todo el backend (T-002 a T-006) en las pantallas. El vendedor trabaja en el cotizador
de siempre, con un panel extra que le dice qué cambia, qué falta resolver y lo lleva a cada
lugar donde se corrige.

## Alcance

**`reservas/detalle.vue`:**
- Botón "Modificar reserva": se ve solo con `reservas.modificar` y la reserva activa. Crea la
  modificación (o abre la que ya está abierta) y navega al cotizador.
- Si hay una abierta: un aviso con "Continuar modificación" y "Descartar".
- El ícono de "quitar pasajero" (bloqueado desde la etapa 1) explica que se hace desde
  "Modificar reserva" y lleva ahí.
- Historial: modificaciones aplicadas, con quién y cuándo, y el enlace a su PDF.

**`cotizador/editar.vue` con una cotización de modificación:**
- Cabecera clara: "Modificación de la reserva <código>", con el enlace de vuelta a la
  reserva. Sin las acciones que no aplican (aceptar, enviar como cotización, duplicar).
- Aviso si la respuesta trae `pasajeros_edad_estimada`.
- **Panel "Diferencia"** (de `GET …/diferencia`, refrescado al guardar cualquier cambio):
  - líneas y pasajeros con su estado, totales y la lista de bloqueos;
  - cada bloqueo **lleva al lugar donde se corrige**: resaltar el ítem en el lienzo
    (`para_revisar` + botón "Revisado"), abrir el comprobante (`requiere_nota_credito`), o
    abrir el formulario de penalidad (`falta_penalidad`).
- Formulario de penalidad por pasajero quitado: monto, motivo y tratamiento de IGV (select
  sin valor preseleccionado).
- Botones "PDF para el cliente", "Descartar" y "Aplicar a la reserva". Aplicar está
  deshabilitado mientras haya bloqueos y manda la `huella` que se mostró. Si responde 422
  porque la reserva cambió, recargar la diferencia y avisarlo.
- Al aplicar: volver a la reserva con un mensaje de éxito y la alerta de cupo, si viene.

**Estilo:** el de Agencia y Ventas (inputs `-sm`, cards con `card-header`; memoria
`feedback_estilo_ui_como_agencia_y_ventas`). Llamadas con `httpClient`. Llamadas
independientes en paralelo (`Promise.all`), no en cadena.

## Fuera de alcance
- Cambios en el backend, salvo un ajuste chico de forma de respuesta. Si hace falta más,
  anotarlo en "Hallazgos".
- "Agregar pasajero rápido" en la reserva para tours (viene después de probar esto).

## Archivos probables
- `admin-start-kit/src/views/agencia-viajes/reservas/detalle.vue`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`
- `admin-start-kit/src/components/agencia-viajes/ModificacionDiferenciaPanel.vue` (nuevo)
- `admin-start-kit/src/components/agencia-viajes/PenalidadPasajeroModal.vue` (nuevo)
- `admin-start-kit/src/composables/useModificacionReserva.ts` (nuevo)

## Criterios de aceptación
- [ ] **CA1** — Sin `reservas.modificar` el botón no se ve; con el permiso y la reserva
  cancelada, tampoco.
- [ ] **CA2** — Recorrido completo en `agencia-demo` (Playwright): crear la modificación,
  agregar un pasajero, ver la diferencia, descargar el PDF, aplicar y ver la reserva con el
  pasajero y el total nuevo.
- [ ] **CA3** — Recorrido con un pasajero quitado: el panel bloquea, el bloqueo abre el
  formulario de penalidad, y al cargarla se puede aplicar.
- [ ] **CA4** — Un `para_revisar` resalta el ítem en el lienzo; "Revisado" lo saca de los
  bloqueos.
- [ ] **CA5** — Abrir la misma modificación en dos pestañas, aplicar en una e intentar en la
  otra: la segunda avisa que la reserva cambió.
- [ ] **CA6** — Descartar vuelve a la reserva sin cambios.
- [ ] **CA7** — `npm run type-check` y `npm run build` sin errores; Vitest en verde si se agregó
  lógica en el composable.
- [ ] **CA8** — Los datos de prueba de `agencia-demo` quedan revertidos al terminar.

## Seguridad y correctitud
- [ ] Permisos: el botón se oculta sin permiso, pero la protección real es del backend (ya
  probada en T-002 a T-006)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: el formulario valida, y el backend también
- [ ] Concurrencia: se manda la `huella`; se maneja el 422 de "cambió"
- [ ] Dinero: se muestra lo que da el backend, sin recalcular en el navegador
- [ ] Fechas: `formatFechaHora()` para los instantes del historial
- [ ] Tributario: el IGV de la penalidad, sin valor preseleccionado
- [ ] `v-html`: no se usa para el motivo ni para los nombres
- [ ] Migraciones: no aplica

## Cómo verificar
```
cd admin-start-kit && npm run type-check && npm run build
```
Y el recorrido con Playwright contra `agencia-demo` (memoria
`reference_playwright_browser_testing_windows`; login en
`reference_agencia_demo_admin_login`).

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
