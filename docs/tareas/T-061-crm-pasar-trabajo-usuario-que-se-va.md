# T-061 — CRM: pasar el trabajo abierto de un usuario que se va

| | |
|---|---|
| **Módulo** | CLI + AGE |
| **Depende de** | T-051 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 16, respuesta 3 (27-oct-2026: contratos de 3 a 6 meses; sin responsable fijo, pero el trabajo abierto no puede quedar huérfano) |
| **Rama** | `feat/T-061-crm-traspaso-usuario` |

## Contexto
El personal rota cada 3 a 6 meses. Cuando alguien se va, sus cotizaciones abiertas y sus acciones
pendientes quedan a su nombre y nadie las ve en "Mis pendientes": el cliente se queda sin
seguimiento.

## Alcance
- **"Pasar su trabajo a…"** en Usuarios (y ofrecido al desactivar un usuario, sin obligar):
  elige el usuario destino y muestra cuánto se pasa: cotizaciones abiertas (borrador o enviada,
  sin ganar ni perder) y acciones pendientes. Al confirmar:
  - `cotizaciones.vendedor_id` → destino (solo las abiertas; las cerradas conservan quién vendió);
  - `crm_acciones.responsable_id` de las pendientes → destino;
  - una actividad por cliente afectado: "Traspasado de Ana a Luis".
- **Aviso al administrador** en Inicio (quien tenga `usuarios` o `cotizaciones.ver_todas`):
  "Ana (desactivada) tiene 5 cotizaciones abiertas y 3 acciones pendientes" con enlace a
  traspasar.
- Todo en una transacción.

## Fuera de alcance
- Repartir entre varios usuarios a la vez (se puede repetir eligiendo otro destino por partes:
  anotarlo si se pide).
- Reservas: siguen con su vendedor de origen.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/UserController.php` o controller nuevo de traspaso
- `api-sistema-fe/app/Services/Dashboard/DashboardService.php`
- `admin-start-kit/src/views/users/…`, vista de Inicio

## Criterios de aceptación
- [ ] **CA1** — Ana con 2 cotizaciones abiertas, 1 ganada y 3 acciones pendientes → traspaso a Luis: 2 cotizaciones y 3 acciones pasan; la ganada sigue de Ana. → test
- [ ] **CA2** — Actividad "Traspasado" en cada cliente afectado. → test
- [ ] **CA3** — Usuario desactivado con pendientes → aviso en Inicio del administrador; sin pendientes → sin aviso. → test
- [ ] **CA4** — Destino inactivo → 422. → test
- [ ] **CA5** — Prueba funcional en agencia-demo; suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: permiso de editar usuarios
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: origen y destino distintos, destino activo
- [ ] Concurrencia (`lockForUpdate`): transacción única
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter="Crm|User"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
