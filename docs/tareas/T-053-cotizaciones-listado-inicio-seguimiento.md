# T-053 — CRM C6a: listado de cotizaciones con el seguimiento a la vista (H52)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-051, T-052 |
| **Reglas** | ayudas sin fricción |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 13 (H52) y obs. 16, fase C6 (27-oct-2026) |
| **Rama** | `feat/T-053-cotizaciones-por-seguir` |

## Contexto
El listado de cotizaciones no dice cuándo se envió, qué toca hacer, cuándo vence ni qué hay que
seguir hoy. El bloque de pendientes de Inicio ya lo hace T-051; esto es el listado.

## Alcance
- **Columna "Seguimiento"** en `cotizador/index.vue`: "Enviada hace 3 días · llamar hoy (Ana)",
  "Vence mañana", "Vencida hace 2 días", "Perdida: Precio", "Ganada". Calculada en
  `CotizacionController::index()` con `HoraPeru::hoy()`, sin consultas por fila.
- **Columna "Origen"** si T-059 ya está hecha (si no, se agrega en T-059).
- **Filtro "Por seguir hoy"**: acción pendiente con fecha ≤ hoy, o alternativa enviada que vence
  dentro de `dias_aviso_vencimiento_cotizacion`; sin ganadas ni perdidas; lo más atrasado
  primero.
- **"Extender vigencia"** en la cotización (enviada, vencida o por vencer): nueva
  `fecha_vencimiento`; actividad `estado`.
- Respeta lo que el usuario puede ver (sus cotizaciones o todas, como el listado actual).

## Fuera de alcance
- Reportes (T-062); recordatorios por correo o push (11f).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionController.php` (`index()`)
- `admin-start-kit/src/views/agencia-viajes/cotizador/index.vue`, `editar.vue`

## Criterios de aceptación
- [ ] **CA1** — Acción de ayer → sale en "Por seguir hoy", primero. → test
- [ ] **CA2** — Enviada que vence pasado mañana con aviso a 2 días → sale; ganada o perdida → no. → test
- [ ] **CA3** — Textos de "Seguimiento" de cada caso. → test
- [ ] **CA4** — Listado de 50 con un número fijo de consultas. → test (contar consultas)
- [ ] **CA5** — Extender vigencia → deja de estar vencida y queda en el historial. → test
- [ ] **CA6** — Prueba funcional en agencia-demo; suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: extender vigencia con el permiso de editar la cotización
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: nueva vigencia no anterior a hoy
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: "hoy" con `HoraPeru::hoy()`; `fecha_vencimiento` es instante: comparar con `HoraPeru::finDiaUtc()`
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=Cotizacion
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
