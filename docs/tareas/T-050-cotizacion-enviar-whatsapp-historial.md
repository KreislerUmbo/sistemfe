# T-050 — Cotización: enviar por WhatsApp u otro medio, y el historial de seguimiento (H48, H49)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 13, H48 y H49, respuesta 1 (27-oct-2026: se envía por correo y WhatsApp) |
| **Rama** | `feat/T-050-cotizacion-envio-seguimiento` |

## Contexto
Una alternativa solo pasa a "enviada" al mandarla por correo desde el sistema. Por WhatsApp (el
otro medio más usado) o en persona, queda en borrador: nunca llega a "Enviada" ni a "Vencida" y no
queda registro de quién la mandó ni cuándo. En agencia-demo ninguna alternativa pasó por "enviada".

## Alcance
- Tabla nueva `cotizacion_seguimientos` (vertical agencia): `cotizacion_id`, `alternativa_id`
  (nullable), `tipo` (`envio` | `contacto` | `estado`), `canal` (`correo` | `whatsapp` | `otro`,
  nullable), `nota` (nullable), `usuario_id`, `created_at`. Pensada para que T-051 y T-052 escriban
  ahí también.
- **Botón "Enviar"** en el panel de la alternativa, con tres opciones:
  - **Correo:** el componente actual (`EnviarCorreoCotizacion`); un envío exitoso deja también una
    fila `envio`/`correo` en el seguimiento.
  - **WhatsApp:** descarga el PDF y abre `wa.me/<teléfono del cliente>` con un texto armado
    (saludo, nombre de la alternativa, total, vigencia). Sin teléfono, abre WhatsApp sin número.
    Marca enviada y deja la fila `envio`/`whatsapp`.
  - **"Ya la envié por otro medio":** marca enviada y deja la fila `envio`/`otro`, con nota
    opcional.
- Endpoint `POST alternativas/{id}/marcar-enviada` (`canal`, `nota`): usa
  `Alternativa::camposAlMarcarEnviada()` (fecha de envío y vencimiento, nunca pisa los ya
  guardados). Una alternativa ya enviada se puede reenviar: queda otra fila, el estado no cambia.
  Aceptada o descartada → 422.
- **Historial de seguimiento** en el editor de la cotización (colapsable): envíos, con canal,
  usuario y hora de Perú. Reemplaza la lista de "Envíos por correo" del componente actual
  (los fallidos siguen visibles allí).
- Texto de WhatsApp configurable más adelante: por ahora fijo en el frontend.

## Fuera de alcance
- Notas de contacto y próximo contacto (T-051); cerrar como perdida (T-052); listado (T-053).
- Enlace al PDF para el cliente (punto 6 de la obs. 13, para después).
- Migrar los envíos de correo anteriores al historial (agencia-demo tiene 0).

## Archivos probables
- `api-sistema-fe/database/migrations/tenant/verticals/agencia-viajes/` (migración nueva)
- `api-sistema-fe/app/Models/AgenciaViajes/CotizacionSeguimiento.php` (nuevo)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaController.php` o un controller nuevo de seguimiento
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionEnvioController.php`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`, `components/AgenciaViajes/EnviarCorreoCotizacion.vue`

## Criterios de aceptación
- [ ] **CA1** — "Ya la envié por otro medio" en una alternativa en borrador → enviada, con `fecha_envio`, `fecha_vencimiento` (si hay días configurados) y una fila de seguimiento. → test
- [ ] **CA2** — Reenviar una alternativa ya enviada → otra fila; `fecha_envio` no cambia. → test
- [ ] **CA3** — Marcar enviada una aceptada o descartada → 422. → test
- [ ] **CA4** — Envío por correo exitoso → fila `envio`/`correo`; fallido → sin fila de seguimiento (queda en `cotizacion_envios`). → test
- [ ] **CA5** — WhatsApp: descarga el PDF y abre el chat con el teléfono del cliente y el texto. → prueba funcional en agencia-demo
- [ ] **CA6** — El listado muestra la cotización como "Enviada" y, pasada la vigencia, "Vencida". → test o prueba funcional
- [ ] **CA7** — Suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: mismo permiso que editar la cotización
- [ ] Aislamiento de tenant: tabla en la base del tenant
- [ ] Validación de entrada / archivos: `canal` en la lista; `nota` máx. 1000
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: el historial se muestra con `formatFechaHora()`
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: notas como texto
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí

## Cómo verificar
```
php artisan test --filter="Cotizacion|Alternativa"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
