# Plan: Panel Superadmin (gestión central de tenants) — CERRADO

Este documento fue el diseño + bitácora de implementación completa del panel central de
gestión de tenants (`central-panel/`, proyecto Vue separado de `admin-start-kit`). Cubría:
alta/edición de tenants sin CLI, `Company`+`SunatConfig`+certificado por tenant, suscripciones
y pagos mensuales con suspensión automática, backups programados/manuales con restauración
segura, y un endpoint de verificación de emisión (`test-emission`) antes de habilitar
`modo=produccion`.

**Todas las fases (0, A, B, B.0.5, B.2 completa incluida B.2.6, C, D Pasos 0-5, E Pasos 0-2)
están cerradas y verificadas contra tenants reales/descartables.** El detalle completo
fase por fase — hallazgos reales, bugs encontrados y corregidos, evidencia de verificación —
se movió comprimido a `docs/planning/panel-superadmin/historial-archivo.md`. Ver también
`CLAUDE.md` (raíz del repo), sección "Panel Superadmin", para el resumen vigente.

## Pendientes reales, sin resolver

1. **Decisión de negocio: ¿`test-emission` debe ser gate obligatorio antes de
   `modo=produccion`, o sigue siendo solo informativo?** El endpoint
   (`POST tenants/{id}/test-emission`) y su frontend (`TestEmissionTab.vue`) ya están
   construidos y funcionando — falta solo la decisión de si además debe bloquear el envío
   real cuando nunca se corrió un test exitoso. Requiere decidir con el usuario, tiene
   implicancia de negocio (¿bloquea a un tenant real en medio de operar?), no es solo técnica.
2. **`market.umbosystem.com`**: candidato a tener `giro='retail'` por default de migración
   sin corresponder a su negocio real (se creó desde el formulario del panel antes de que
   existiera el selector de giro). El mecanismo para corregirlo ya existe (editar tenant
   desde el panel) — pendiente confirmar su giro real y pedir aprobación explícita del
   usuario antes de tocar un tenant con datos reales.
3. **No bloqueante, deferido a sesión futura**: `admin-start-kit/src/views/sale/index.vue`
   y `views/advances/show.vue` no leen el shape anidado de error
   (`response.error.message`) que el catch de `enviarSunat()` puede devolver ahora en
   fallos de red/construcción del comprobante (no en el rechazo normal de SUNAT, que sigue
   devolviendo 200 sin cambios) — cae al mensaje genérico "Error inesperado al enviar a
   SUNAT." en ese caso puntual. Detalle en `historial-archivo.md`, entrada de Fase E Paso 1.
