# T-053 — Listado de cotizaciones e Inicio con el seguimiento a la vista (H52)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-051, T-052 |
| **Reglas** | ayudas sin fricción |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 13, H52 (aprobada 27-oct-2026) |
| **Rama** | `feat/T-053-cotizaciones-por-seguir` |

## Contexto
El listado de cotizaciones no dice cuándo se envió, cuánto lleva sin respuesta, cuándo vence ni a
quién toca llamar hoy. Con T-050 a T-052 esos datos existen; falta mostrarlos donde se trabaja.

## Alcance
- **Columna "Seguimiento"** en `cotizador/index.vue`, un texto corto según el caso:
  "Enviada hace 3 días · contactar hoy", "Contactar el jue 30/10", "Vence mañana",
  "Vencida hace 2 días", "Perdida: Precio". Calculado en el backend (`CotizacionController::index()`)
  con `HoraPeru::hoy()`, sin consultas por fila.
- **Filtro "Por seguir hoy"**: `proximo_contacto <= hoy` o alternativa enviada que vence en los
  próximos `dias_aviso_vencimiento_cotizacion` días; excluye aceptadas y perdidas. Orden: lo más
  atrasado primero.
- **Bloque "Cotizaciones por seguir"** en Inicio (agencia, con permiso de cotizaciones): las 5
  primeras del mismo filtro y el total, con enlace al listado filtrado. Reemplaza o completa el
  conteo actual de "enviadas" de `DashboardService::cotizaciones()`.
- **"Extender vigencia"** en la cotización (alternativa enviada, vencida o por vencer): nueva
  `fecha_vencimiento`; deja una fila `estado` en el seguimiento.
- Si el usuario no ve todas las cotizaciones (solo las suyas, criterio actual del listado), el
  filtro y el bloque respetan lo mismo.

## Fuera de alcance
- Recordatorio por correo o push (motor 11f).
- Reporte de conversión.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionController.php` (`index()`)
- `api-sistema-fe/app/Services/Dashboard/DashboardService.php`
- `admin-start-kit/src/views/agencia-viajes/cotizador/index.vue`, `editar.vue`
- vista de Inicio (`views/dashboard/…`)

## Criterios de aceptación
- [ ] **CA1** — Cotización con próximo contacto ayer → sale en "Por seguir hoy" y primero. → test
- [ ] **CA2** — Alternativa enviada que vence pasado mañana con aviso a 2 días → sale; aceptada o perdida → no sale. → test
- [ ] **CA3** — El texto de "Seguimiento" de cada caso (enviada, contactar, vence, vencida, perdida). → test
- [ ] **CA4** — Sin N+1: el listado de 50 hace un número fijo de consultas. → test (contar consultas)
- [ ] **CA5** — Extender vigencia → deja de salir "Vencida" y queda en el historial. → test
- [ ] **CA6** — Inicio muestra el bloque y el enlace abre el listado filtrado. → prueba funcional en agencia-demo
- [ ] **CA7** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: extender vigencia con el permiso de editar cotización
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
php artisan test --filter="Cotizacion|Dashboard"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
