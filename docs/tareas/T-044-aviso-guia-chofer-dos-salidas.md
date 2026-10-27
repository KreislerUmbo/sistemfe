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
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
