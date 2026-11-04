# T-044 — Aviso cuando un guía o un chofer ya tiene otra salida ese día (H38)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión de salidas operativas", H38 (aprobada 26-oct-2026: avisar, **no bloquear**) |
| **Rama** | `feat/T-044-aviso-guia-chofer` |

## Contexto
Se puede poner el mismo guía (en la salida o en un vehículo) o el mismo chofer en dos salidas del
mismo día sin ningún aviso. A veces es a propósito (un tour de mañana y otro de tarde), por eso solo
se avisa.

## Alcance
- Al asignar un guía (`SalidaOperativaController::update()` con `guia_id`, `SalidaVehiculoController`
  store/update con `guia_id`) o un chofer (vehículo con `chofer_telefono`, comparado por dígitos):
  buscar otras salidas **en curso** (programada o confirmada) de la **misma fecha** donde ya esté.
- La respuesta trae `avisos` (no bloquea): "Juan Pérez ya está en la salida de Alto Mayo de las
  08:00 de ese día". Sin hora en alguna de las dos: "… ya tiene otra salida ese día".
- La pantalla muestra el aviso como toast de advertencia, sin pedir confirmación (ayudas sin
  fricción).
- En el tablero, la fila de la salida marca el guía repetido con un ícono y el mismo texto como
  `title`, para verlo sin abrir nada.

## Fuera de alcance
- Calcular solapamiento por duración del tour (no hay duración confiable): el criterio es el día.
- Choferes sin teléfono (solo nombre libre): no se comparan.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/SalidaOperativaController.php` (`update()`, `resumenSalida()`)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/SalidaVehiculoController.php`
- `admin-start-kit/src/views/agencia-viajes/salidas/index.vue`, `components/AgenciaViajes/SalidaServiciosVehiculos.vue`

## Criterios de aceptación
- [ ] **CA1** — Guía asignado a una salida del 10/11 y luego a otra del 10/11 → 200 con aviso que nombra la primera. → test
- [ ] **CA2** — Misma persona en una salida cancelada o realizada ese día → sin aviso. → test
- [ ] **CA3** — Chofer con el mismo teléfono (con y sin espacios o +51) en dos vehículos del mismo día → aviso. → test
- [ ] **CA4** — Otro día → sin aviso. → test
- [ ] **CA5** — El tablero marca el guía repetido. → prueba funcional
- [ ] **CA6** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica (es un aviso)
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: `salidas_operativas.fecha` es fecha de negocio
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=Salida
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:** uno solo en `feat/T-044-aviso-guia-chofer` (sin unir a `main`).
- **Tests:** 10 nuevos en `T044AvisoGuiaChoferTest` (CA1-CA4 + sin teléfono, teléfonos distintos, misma salida, guía de vehículo, sin hora, actualizar sin cambios, y el campo del tablero). `--filter=Salida`: 57 verdes. `--filter=AgenciaViajes`: 1042 verdes (4361 aserciones); el intermitente Fila13Fase1c no falló. `type-check`: 47 errores antes, 47 después (ninguno en los archivos tocados). Falta `build`.
- **CA5 (tablero):** probado solo por API (`show()` devuelve `aviso_guia_repetido`). La prueba en navegador NO se hizo: el MCP de Playwright no conectó ("Connection closed"). Falta mirar a mano en agencia-demo.
- **Desviaciones de la tarea y por qué:**
  - Servicio nuevo `CruceSalidasService` (la lógica la comparten los dos controllers y el tablero).
  - El aviso solo sale si el guía o el teléfono del chofer **cambiaron** en ese guardado (si no, cada edición de otro campo repetiría el toast). Al crear un vehículo se evalúa todo.
  - Tablero: la marca solo cubre al guía (el del encabezado y el de sus vehículos), como pide el alcance; el chofer se avisa solo en el toast.
  - Al asignar un guía en el tablero se marca esa fila; la otra salida se marca al recargar la lista.

- **Revisión (04-nov):** tres correcciones y 3 tests nuevos (13 en total):
  - Una salida cancelada o realizada ya no se marca en el tablero ni avisa al asignarle guía o chofer (antes solo se filtraba "la otra").
  - El tablero toma la marca de `salida.aviso_guia_repetido` de la respuesta, no de `avisos[0]`: cambiar el guía principal ya no borra la marca de un guía de vehículo repetido.
  - "Cambió el guía" con `wasChanged('guia_id')` en vez de `!==` (un `"5"` de texto avisaba de nuevo).
  - El listado lee las salidas en curso de cada fecha **una vez** (el servicio las guarda por fecha, y el controller usa una sola instancia por request); antes era una lectura (más tours y vehículos) por fila y por guía.
  - Texto del aviso: el tour y la hora van cada uno si existe ("… de Alto Mayo de ese día", "… otra salida a las 08:00 ese día"). **Desviación del Alcance**, que pedía el texto genérico si faltaba uno de los dos: se perdía un dato que sí estaba.
  - 14 tests en `T044AvisoGuiaChoferTest`. `--filter=Salida`: 58 verdes. `type-check`: 47, igual que antes.
- **Recorrido:** `docs/aprendizaje/33-recorrido-aviso-guia-chofer-repetido.md`.

## Hallazgos fuera de alcance
- Reprogramar una salida/reserva a un día donde el guía o el chofer ya está ocupado no avisa (solo se avisa al asignar).
- ~~`aviso_guia_repetido` hacía 3-4 consultas por guía de cada fila~~: resuelto en la revisión (una lectura por fecha).
