# T-079 — El login devuelve el id del usuario (Créditos y Caja no reconocían lo propio)

| | |
|---|---|
| **Módulo** | PLA + CRE + CAJ |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | — |
| **Rama** | `fix/T-079-login-id-usuario` |

## Contexto
`AuthController::respondWithToken()` (login y refresh) arma el `user` sin `id`, y el frontend lo
guarda tal cual en `localStorage.user`. Toda pantalla que compara "¿es mío?" contra
`auth.user.id` recibe `undefined`:
- **Créditos:** `usePermisosCredito().usuarioId` vale 0 → `puedeAnularPago()` nunca reconoce un pago
  propio; un cobrador con `creditos.cobrar` sin `creditos.anular_pago` no ve «Anular» en sus pagos
  (el backend, `AnulacionPagoService`, sí lo permitiría: no es un hueco de seguridad).
- **Caja:** `views/cash/dashboard.vue` (`puedeCerrar()`, `esAjena`): sin
  `cash.close_others_session`, la caja propia no se ofrece para cerrar desde el Dashboard, y la
  propia se trata como ajena.

Hallazgo de T-051 (02-nov-2026).

## Alcance
- `respondWithToken()` devuelve `user.id`.
- `types/auth.ts`: `User.id`.

## Fuera de alcance
- `crm/acciones/opciones` sigue devolviendo `usuario_id` (T-051): es del servidor y no depende de
  una sesión guardada antes del despliegue. Simplificarlo es opcional.

## Criterios de aceptación
- [x] **CA1** — Login responde `user.id` = id del usuario → `SeguridadRegistroYUsuarioInactivoTest::test_usuario_activo_inicia_sesion_e_inactivo_es_rechazado`
- [ ] **CA2** — Prueba funcional: con un usuario con `creditos.cobrar` sin `creditos.anular_pago`, su
  pago aparece con «Anular» en el detalle del crédito; con un cajero sin
  `cash.close_others_session`, su caja abierta se puede cerrar desde el Dashboard de Caja.

## Seguridad y correctitud
- Exponer el id del propio usuario a su sesión: sin riesgo (el backend decide siempre).
- Lo demás: no aplica. Sin migraciones.

## Al desplegar
Las sesiones ya abiertas no tienen el `id` hasta la próxima renovación del token
(`sessionExpiryWatcher`) o un nuevo login: hasta entonces se comportan como hoy.

---

## Resultado
- **Commits:** ver merge de `fix/T-079-login-id-usuario`
- **Tests:** CA1 en `SeguridadRegistroYUsuarioInactivoTest` (4/4).
- **Desviaciones:** —

## Hallazgos fuera de alcance
-
