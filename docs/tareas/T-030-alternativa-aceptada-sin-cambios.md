# T-030 — Una alternativa aceptada no cambia de estado ni de mayorista por API

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna (combina con T-001, que bloquea el cambio de precio) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H3 y H8 |
| **Rama** | `fix/T-030-alternativa-aceptada` |

## Contexto
**H3.** `AlternativaController::update()` solo bloquea pasar una alternativa **a** "aceptada" (eso
lo hace `ReservaController::aceptar()`). Una alternativa **ya** aceptada (con reserva) se puede
pasar a "borrador", "enviada" o "descartada" por API: en borrador se desbloquean las ediciones que
miran `estado === 'aceptada'` y la reserva queda desalineada.

**H8.** En `OpcionMayoristaController`, `eliminar()` ya rechaza si la alternativa está aceptada,
pero `elegir()`, `descartar()`, `reactivar()`, `update()` y los que agregan o cambian hoteles,
opcionales y tours de la opción no. Cambiar la opción elegida después de aceptar deja la reserva
(`reserva.mayorista_elegida_id`, cupo de la salida) desalineada.

## Alcance
- **Reproducir primero** con tests que fallen: `PUT alternativas/{id}` con `estado=borrador` sobre
  una aceptada → hoy 200; `elegir()` sobre una opción de una alternativa aceptada → hoy 200.
- `AlternativaController::update()`: si la alternativa está aceptada, 422 ante **cualquier** cambio
  de `estado` (el nombre se puede seguir cambiando). Mensaje en la línea de los existentes.
- `OpcionMayoristaController`: el mismo guarda de `eliminar()` (422 si la alternativa está aceptada)
  en todo método que cambie la opción, sus hoteles, sus tarifas de hotel, sus opcionales o sus tours.
  Un helper privado para no repetirlo. Listar en "Resultado" cada método y si lleva el guarda.
- Revisar `OpcionHotelController` y `AlternativaDestinoController` con el mismo criterio: anotar en
  "Resultado" qué ya está cubierto y agregar el guarda donde falte, si es el mismo caso.
- Frontend: nada que cambiar si la pantalla ya oculta esas acciones en una alternativa aceptada;
  confirmarlo en `cotizador/editar.vue` y anotarlo.

## Fuera de alcance
- Cambio de precio de un ítem en alternativa aceptada (T-001).
- Verificación de dueño (T-025).
- Reasignar mayorista u hotel **desde la reserva** (`ReservaController::reasignarMayorista()`), que
  es el camino correcto y ya existe.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/OpcionMayoristaController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/OpcionHotelController.php`, `AlternativaDestinoController.php`
- tests nuevos en `tests/Feature/AgenciaViajes/`

## Criterios de aceptación
- [ ] **CA1 (H3)** — Alternativa aceptada: `PUT` con `estado` = borrador / enviada / descartada → 422
  y el estado no cambia; renombrarla sigue funcionando. → test (falla antes del fix)
- [ ] **CA2 (H8)** — Alternativa aceptada: elegir, descartar, reactivar, editar una opción de
  mayorista, y agregar/cambiar sus hoteles, opcionales y tours → 422. → test con data provider
- [ ] **CA3** — Alternativa en borrador o enviada: todo sigue funcionando igual. → tests existentes en verde
- [ ] **CA4** — Lista de métodos y su guarda en "Resultado".

## Seguridad y correctitud
- [ ] Permisos: no aplica (sin rutas nuevas)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: el guarda va antes de cualquier cambio
- [ ] Concurrencia: no aplica
- [ ] Dinero: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] `v-html`: no aplica
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter="AlternativaAceptada|OpcionMayorista"
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
