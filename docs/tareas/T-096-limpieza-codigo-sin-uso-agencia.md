# T-096 — Agencia: limpiar código sin uso y mensajes con voseo

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | — |
| **Reglas** | — |
| **Decisiones** | — |
| **Rama** | `refactor/T-096-limpieza-agencia` |

## Contexto
La auditoría del 09-nov-2026 encontró código que ya nadie usa: comandos de una sola vez, métodos
de servicio del frontend sin llamadas y mensajes al usuario con voseo (la regla del proyecto es
tuteo). Las columnas y los modelos sin uso van en T-083, no acá.

## Alcance
> Los comandos de una sola vez salieron de esta tarea (09-nov-2026): todavía no corrieron en
> producción. Van en T-099. **No tocar** `app/Console/Commands/` ni sus servicios.

1. **Métodos de servicio sin uso (frontend):** `opcionMayoristaService.listarHoteles/listarOpcionales/listarTours`,
   `salidaOperativaService.adjuntarItem/desengancharItem`, `destinoAtractivoService.listarPorTipo`,
   `temporadaService.listarOcurrencias`. Por cada uno, ver si su endpoint lo usa otra pantalla; si
   nadie, retirar método y ruta (y el método del controller si queda huérfano).
2. **Voseo → tuteo** en mensajes al usuario del vertical (backend y frontend, unos 19; p. ej.
   `DestinoServicioController.php:174` «No podés fusionar…», `AlternativaItemController` «mové todo el bloque»,
   `LimpiarOperacionAgencia` «Escribí el ID»). Buscar con
   `grep -rE "(mové|usá|elegí|escribí|cargá|revisá|tenés|podés|querés|guardá|completá|confirmá|seleccioná|ingresá) "`.

3. **`use` sin uso:** `ReservaPasajeroController.php:7` importa `PasajeroDocumento` y no lo usa
   (quedó al retirar `store()` en T-092). Borrar la línea.

## Fuera de alcance
- Los comandos de una sola vez de `app/Console/Commands/` (T-099).
- Modelos `PagoProveedor`, `CronogramaPagoProveedor` (esperan a Compras) y `Recordatorio*` (11f).
- Recortar comentarios que narran historia: se hace al tocar cada archivo en T-094/T-095.

## Dónde tocar
- `admin-start-kit/src/services/admin/{opcionMayoristaService,salidaOperativaService,destinoAtractivoService,temporadaService}.ts`
  y, si quedan sin uso, sus rutas en `api-sistema-fe/routes/api.php` y el método del controller
- Los mensajes con voseo que devuelva el `grep` (vistas de Agencia y controllers de `AgenciaViajes`)

## Criterios de aceptación
- [ ] **CA1** — Ningún método retirado tiene llamadas (`grep`); `type-check` y `build` en verde (el
  `type-check` ya tiene 47 errores previos: el número no debe subir).
- [ ] **CA2** — Si se retira una ruta, `RutasAgenciaPermisosTest` sigue pasando (si una regla de
  `PERMISO_POR_GRUPO` se queda sin rutas, quitar esa regla); la suite de Agencia pasa.
- [ ] **CA3** — El `grep` de voseo del alcance no devuelve nada en Agencia. Si un test compara el
  texto de un mensaje, se actualiza el test.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica
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
php artisan test --filter=AgenciaViajes
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
