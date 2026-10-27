# T-067 — Unir a `main` y desplegar lo ya construido (fila 13 fases 1b-2, F1, F3, F4 etapa 1, F5)

| | |
|---|---|
| **Módulo** | PLA (despliegue) + AGE |
| **Depende de** | decisión del usuario sobre **cuándo** desplegar (ver Contexto) |
| **Reglas** | `CLAUDE.md` → "Cómo trabajar en este proyecto" (`tenants:migrate-verticales`); memoria `project_despliegue_produccion_ovh_estado` |
| **Decisiones** | revisión del backlog, 27-oct-2026 |
| **Rama** | `feat/f1-facturacion-por-servicio` → `main` → `origin/main` |

## Contexto
Al 27-oct-2026 hay código terminado y probado que no está en producción ni en `origin`:
- `main` local tiene **12 commits sin pushear**: fila 13 fases 1b, 1c y 2 (tour como una línea,
  facturación del tour, salidas operativas, órdenes de servicio, avisos, reprogramar salida, regla
  exonerado = Amazonía) y el merge del hotfix `89a1ff7` (ese ya está desplegado).
- La rama `feat/f1-facturacion-por-servicio` tiene **65 commits** sobre `main`: F1 (dos
  comprobantes por tipo de servicio), F3 (auditoría de reserva y facturación, `ReservaPolicy` en
  toda acción), F4 etapa 1 (quitar pasajero bloqueado hasta "Modificar reserva"), F5 (condición de
  pago contado/crédito en reservas y PDF de **todos los giros**), el renombre de las pestañas del
  cotizador y la documentación de esta revisión. Le faltan los 2 commits del hotfix que están en
  `main`.
Mientras más tareas se construyan encima, más difícil es unir y probar. Por eso va primero: al
menos **unir y pushear**; el despliegue puede esperar a la ola 1 si el usuario prefiere salir con
las correcciones de comprobantes.

## Alcance
1. **Unir:** `main` → rama (traer el hotfix), suite completa en verde contra Postgres, `type-check`
   y `build`; luego la rama → `main` y `git push origin main` (con permiso del usuario).
2. **Antes de desplegar** (lista de comprobación, anotar cada resultado en "Resultado"):
   - respaldo completo de la base (además del cron de las 03:00);
   - listar las migraciones nuevas de `core/` y de `verticals/agencia-viajes/` y qué datos tocan:
     corrección exonerado → Amazonía (con tabla de registro), hora y lugar de las salidas,
     `reserva_pasajeros.cotizacion_pasajero_id`, F5; probar `tenants:migrate-verticales` contra una
     copia de producción o, si no se puede, contra los tenants locales con datos parecidos;
   - confirmar que todos los tenants siguen en `modo_tours = clasico` (el modo nuevo se activa
     después, por tenant);
   - F5 cambia el PDF de **todos los giros** (condición de pago): revisar un PDF de retail y de
     créditos en local;
   - `deploy.sh` sigue aunque falle `tenants:migrate-verticales`: correrlo a mano y mirar la salida.
3. **Desplegar** (`deploy.sh`), luego: `tenants:migrate-verticales` revisado, `roles:sync-permisos-nuevos`
   si hay permisos nuevos (`reservas.modificar`…), limpiar la caché del menú, prueba rápida en
   cada tenant (login, una venta, un PDF).
4. **Operación pendiente en producción**, aprovechar la misma ventana (cada una con su resultado):
   claves VAPID (`php artisan webpush:claves`) para el push de Créditos; backfill de permisos de
   Gate Bucket A/B; `BREVO_API_KEY` y `roles:sync-permisos-nuevos` del envío de cotizaciones por
   correo (si se va a usar).

## Fuera de alcance
- Activar `modo_tours = nuevo` en algún tenant (decisión aparte, con el usuario).
- Corregir lo que aparezca: se anota en "Hallazgos" y se decide si bloquea.

## Archivos probables
- `deploy.sh`, migraciones de `database/migrations/tenant/` agregadas desde el último despliegue

## Criterios de aceptación
- [ ] **CA1** — `main` = rama unida, pusheada a `origin`, suite completa en verde. → salida de tests
- [ ] **CA2** — Lista de comprobación del punto 2 completa, con resultado de cada ítem.
- [ ] **CA3** — Desplegado; `tenants:migrate-verticales` sin errores en todos los tenants. → salida del comando
- [ ] **CA4** — Prueba rápida en cada tenant de producción sin errores.
- [ ] **CA5** — Memoria `project_despliegue_produccion_ovh_estado` actualizada con el commit desplegado.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: `roles:sync-permisos-nuevos` corrido
- [ ] Aislamiento de tenant: migraciones por tenant revisadas
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: migración exonerado → Amazonía revisada con su tabla de registro
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí, es el centro de la tarea

## Cómo verificar
```
php artisan test
cd admin-start-kit && npm run type-check && npm run build
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
