# T-050 — CRM C1: historial de actividades del cliente y "Enviar" la cotización (H48, H49)

| | |
|---|---|
| **Módulo** | CLI + AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 13 (H48, H49) y obs. 16, fase C1 (aprobadas 27-oct-2026: historial por cliente; se envía por correo y WhatsApp) |
| **Rama** | `feat/T-050-crm-actividades` |

## Contexto
Una alternativa solo pasa a "enviada" al mandarla por correo desde el sistema; por WhatsApp o en
persona queda en borrador y no queda rastro. Tampoco hay dónde anotar un contacto con el cliente.
Es la primera fase del CRM: el historial vive **por cliente**, con referencia opcional a la
cotización o reserva.

## Alcance
- Tabla `crm_actividades` en **core** (`database/migrations/tenant/core/`): `cliente_id` (FK
  `clients`), `referencia_tipo` (`cotizacion` | `reserva` | null) + `referencia_id` (sin FK: las
  tablas de Agencia son de un vertical), `alternativa_id` (sin FK, nullable), `tipo` (`envio` |
  `llamada` | `whatsapp` | `visita` | `nota` | `estado`), `canal` (`correo` | `whatsapp` | `otro`,
  nullable), `nota` (nullable), `usuario_id`, timestamps. Índices por cliente y por referencia.
- **Botón "Enviar"** en el panel de la alternativa:
  - **Correo:** el componente actual; un envío exitoso deja una actividad `envio`/`correo`.
  - **WhatsApp:** descarga el PDF y abre `wa.me/<teléfono del cliente>` con un texto armado
    (saludo, alternativa, total, vigencia); sin teléfono, sin número. `wa.me` no adjunta archivos:
    el vendedor adjunta el PDF.
  - **"Ya la envié por otro medio":** nota opcional.
  - Los tres marcan la alternativa como enviada con `Alternativa::camposAlMarcarEnviada()`
    (`POST alternativas/{id}/marcar-enviada`); reenviar deja otra actividad sin cambiar la fecha
    de envío. Aceptada o descartada → 422.
- **"Anotar contacto"** (llamada, WhatsApp, visita, nota) desde la cotización y desde la ficha del
  cliente: `POST clientes/{id}/actividades`.
- **Historial** colapsable en el editor de la cotización (actividades de esa cotización, con
  usuario y hora de Perú). Reemplaza la lista "Envíos por correo" (los fallidos siguen visibles).

## Fuera de alcance
- Próximas acciones (T-051), motivos (T-052), línea de tiempo completa en la ficha (T-060).
- Enlace al PDF para el cliente (para después).

## Archivos probables
- migración nueva en `api-sistema-fe/database/migrations/tenant/core/`
- `api-sistema-fe/app/Models/Client/CrmActividad.php` (nuevo) + controller nuevo
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionEnvioController.php`, `AlternativaController.php`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`, `components/AgenciaViajes/EnviarCorreoCotizacion.vue`

## Criterios de aceptación
- [ ] **CA1** — "Ya la envié por otro medio" en borrador → enviada, `fecha_envio`, vencimiento (si hay días configurados) y una actividad. → test
- [ ] **CA2** — Reenviar → otra actividad; `fecha_envio` no cambia. → test
- [ ] **CA3** — Aceptada o descartada → 422. → test
- [ ] **CA4** — Correo exitoso → actividad `envio`/`correo`; fallido → sin actividad. → test
- [ ] **CA5** — Anotar una llamada desde la cotización → actividad con cliente y referencia. → test
- [ ] **CA6** — WhatsApp descarga el PDF y abre el chat. → prueba funcional en agencia-demo
- [ ] **CA7** — `tenants:migrate-verticales` crea la tabla en un tenant retail sin errores (core). → prueba
- [ ] **CA8** — Suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: enviar/anotar sobre una cotización = permiso de editarla (`CotizacionPolicy`); desde la ficha = permiso de editar clientes
- [ ] Aislamiento de tenant: tabla en la base del tenant
- [ ] Validación de entrada / archivos: `tipo` y `canal` en lista; nota máx. 1000
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: se muestran con `formatFechaHora()`
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: notas como texto
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí

## Cómo verificar
```
php artisan test --filter="Cotizacion|Alternativa|Crm"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
