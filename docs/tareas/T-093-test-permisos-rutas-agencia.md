# T-093 — Test que impide una ruta de Agencia sin permiso

| | |
|---|---|
| **Módulo** | AGE + TRA |
| **Depende de** | T-092 (quita rutas; el test debe correr sobre la lista final) |
| **Reglas** | — |
| **Decisiones** | — |
| **Rama** | `test/T-093-permisos-rutas-agencia` |

## Contexto
Hoy las 246 rutas de Agencia tienen permiso (verificado a mano con `route:list` el 09-nov-2026),
pero nada lo garantiza: de 113 archivos de test de Agencia, 100 llaman al controller directo y
solo 1 hace una petición HTTP. Una ruta nueva sin `->middleware('permission:…')`, o una Policy
que se deja de llamar, no rompe ningún test.

## Alcance
1. **Test de barrido:** recorre `Route::getRoutes()`, toma toda acción de
   `App\Http\Controllers\AgenciaViajes\*` y falla si alguna no tiene `PermissionMiddleware`.
   Lista explícita de excepciones: solo las rutas firmadas (`reporte-operativo-pdf`), con su motivo.
   Patrón: `tests/Feature/GateBucketARoutesTest.php` (sin el stack de tenancy, que ningún test usa hoy).
2. **Permiso esperado por grupo:** tabla `prefijo de URI → permiso` para los grupos sensibles
   (`reservas/*/facturar*` → facturación, `configuracion-*` → `agencia.configuracion`, etc.), para que
   cambiar un permiso por otro más débil también falle.
3. **Fila por vendedor:** un test por controller que tiene Policy (cotización, alternativa, ítem,
   reserva, pasajero, facturación, anticipo, modificación): un vendedor sin `ver_todas` recibe 403
   sobre una cotización o reserva de otro vendedor. Uno por controller, no uno por método.

## Fuera de alcance
- Convertir los tests existentes a HTTP.
- Pasar el stack completo de tenancy (`tenant`, `tenant.token`) en los tests.

## Dónde tocar
- `api-sistema-fe/tests/Feature/AgenciaViajes/RutasAgenciaPermisosTest.php` (nuevo)
- `api-sistema-fe/tests/Feature/AgenciaViajes/PolicyFilaPorVendedorTest.php` (nuevo; ver
  `ReservaPolicyAccionesTest.php` para el armado de vendedores)

## Criterios de aceptación
- [ ] **CA1** — Si se borra el `->middleware('permission:…')` de cualquier ruta de Agencia, el test falla y nombra la ruta → paso manual (probarlo una vez y revertir).
- [ ] **CA2** — Cada grupo sensible exige su permiso exacto → test.
- [ ] **CA3** — Un vendedor sin `ver_todas` recibe 403 en cada controller con Policy → test.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: es el objeto de la tarea
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter="RutasAgenciaPermisos|PolicyFilaPorVendedor"
```

---

## Resultado
- **Commits:** RESULTADO_COMMITS
- **Tests:** `php artisan test --filter="RutasAgenciaPermisos|PolicyFilaPorVendedor"` → 14 pasan
  (4 de rutas, 10 de fila). Suite completa: 2096 pasan y 1 falla, `Fila13Fase1cFacturacionTest::descuento_del_combo…` (el intermitente conocido de CLAUDE.md; sola pasa).
  - CA1: probado a mano — quitado el `->middleware('permission:…')` de `PUT alternativa-items/{id}`
    el test falla y nombra la ruta; revertido (sin diff en `routes/api.php`).
  - CA2: 40 reglas de grupo; además falla si una regla deja de coincidir con alguna ruta.
  - CA3: probado a mano — quitado el `authorize` de `ReservaModificacionController::store`, falla
    solo ese test; revertido.
- **Desviaciones de la tarea y por qué:**
  - La tarea nombra "facturación" como permiso de `reservas/*/facturar*`, pero no existe un permiso
    así en Agencia: hoy esas rutas exigen `reservas.editar` (`preparar-factura`: `reservas.ver|ver_todas`).
    El test fija lo que hay hoy; ver hallazgo.
  - Las Policy que existen son solo `CotizacionPolicy` y `ReservaPolicy`; "alternativa" e "ítem de
    cotización" la usan vía `autorizarCotizacion()` y "ítem/pasajero/facturación/anticipo/modificación"
    vía `ReservaPolicy`. El test de fila cubre un método por controller (10 tests) llamando al
    controller directo, como `ReservaPolicyAccionesTest`.
  - Rutas con permiso detectadas por `PermissionMiddleware` (`permission:a|b`); la única excepción es
    `reporte-operativo-pdf` (el test además exige que lleve `signed`).

## Hallazgos fuera de alcance
- Facturar una reserva (`POST reservas/{id}/facturar`) solo exige `reservas.editar`: no hay un permiso
  propio de facturación. Decidir si hace falta uno (afecta roles ya creados).
