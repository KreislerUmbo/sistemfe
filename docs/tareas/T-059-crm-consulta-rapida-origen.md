# T-059 — CRM C4: "Nueva consulta" y origen de contacto

| | |
|---|---|
| **Módulo** | CLI + AGE |
| **Depende de** | T-050 |
| **Reglas** | ayudas sin fricción |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 16, fase C4 y respuestas 2 y 3 (27-oct-2026: origen por consulta, el cliente guarda el primero; sin responsable fijo) |
| **Rama** | `feat/T-059-crm-consulta-origen` |

## Contexto
Registrar a alguien que pregunta por WhatsApp obliga a crear el cliente con sus datos y una
cotización completa. Tampoco se registra por dónde llegó (WhatsApp, Facebook, recomendación…), así
que no se sabe qué canal trae ventas.

## Alcance
- Catálogo `crm_origenes_contacto` en **core**: `nombre`, `activo`, `orden`. Sembrado: WhatsApp,
  Facebook, Instagram, TikTok, Página web, Recomendación, Pasó por la oficina, Llamada, Cliente
  antiguo, Otro. Pantalla simple en Configuración de Agencia.
- `clients.origen_contacto_id` (core, nullable): el del primer contacto; se llena solo la primera
  vez y se puede corregir en la ficha.
- `cotizaciones.origen_contacto_id` (vertical, nullable): el de esa consulta. En la cotización
  nueva, por defecto el último que usó ese usuario; no es obligatorio.
- **"Nueva consulta"** (botón en el listado de cotizaciones y en Inicio): nombre, teléfono,
  origen, destino de interés, fechas tentativas, pasajeros (cantidad) y nota.
  - Busca primero si ya existe un cliente con ese teléfono o nombre y lo propone ("¿Es Juan Pérez,
    999…?").
  - Crea el cliente nuevo con documento `SND` si no lo da.
  - Crea la cotización en borrador con el usuario como vendedor, la nota como actividad (T-050) y,
    opcional, una acción "Enviar cotización" para hoy (T-051, si ya existe).
  - Lleva al editor de la cotización.
- Columna "Origen" en el listado de cotizaciones (filtro incluido).

## Fuera de alcance
- Consultas que entran solas por un formulario web o WhatsApp Business (integraciones).
- Reportes por origen (T-062).

## Archivos probables
- migraciones nuevas: catálogo y `clients.origen_contacto_id` (core), `cotizaciones.origen_contacto_id` (vertical)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionController.php` o controller nuevo de consulta
- `admin-start-kit/src/views/agencia-viajes/cotizador/index.vue`, componente nuevo `NuevaConsultaModal.vue`, `views/clients/ficha.vue`

## Criterios de aceptación
- [ ] **CA1** — Consulta con nombre, teléfono y origen "Facebook" → cliente SND con origen, cotización en borrador con el mismo origen y vendedor = usuario. → test
- [ ] **CA2** — Teléfono de un cliente existente → se propone y, si se acepta, no se crea otro. → test + prueba funcional
- [ ] **CA3** — Segunda cotización del mismo cliente con origen "Recomendación" → la cotización lo guarda; el cliente conserva "Facebook". → test
- [ ] **CA4** — Desactivar un origen en uso → deja de ofrecerse, los datos quedan. → test
- [ ] **CA5** — Prueba funcional en agencia-demo; suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: crear cotización + crear cliente
- [ ] Aislamiento de tenant: tablas en la base del tenant
- [ ] Validación de entrada / archivos: teléfono con el mismo formato que `ClienteRequest`; nombre obligatorio
- [ ] Concurrencia (`lockForUpdate`): código de cotización con `CodigoGeneradorService` como hoy
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: fechas tentativas son fechas de negocio
- [ ] Tributario sin fallback silencioso: un cliente SND no puede recibir factura (validación existente)
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí

## Cómo verificar
```
php artisan test --filter="Cotizacion|Cliente|Crm"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
