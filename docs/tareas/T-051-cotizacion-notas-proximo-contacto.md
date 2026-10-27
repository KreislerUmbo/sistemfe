# T-051 — CRM C2: próximas acciones y "Mis pendientes de hoy" (H50)

| | |
|---|---|
| **Módulo** | CLI + AGE |
| **Depende de** | T-050 |
| **Reglas** | ayudas sin fricción (nada obligatorio) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 13 (H50) y obs. 16, fase C2 y respuesta 3 (27-oct-2026: responsable por acción, sin responsable fijo del cliente) |
| **Rama** | `feat/T-051-crm-acciones` |

## Contexto
Después de enviar no hay forma de agendar el siguiente paso ni de ver qué toca hacer hoy.
`dias_aviso_vencimiento_cotizacion` se configura y nada lo lee.

## Alcance
- Tabla `crm_acciones` en **core**: `cliente_id`, `referencia_tipo`/`referencia_id` (como T-050),
  `tipo` (`llamar` | `whatsapp` | `enviar_cotizacion` | `cobrar_saldo` | `saludar` | `otro`),
  `descripcion` (nullable), `fecha` (date), `responsable_id`, `hecha_en` (nullable),
  `hecha_por`, `creada_por`, timestamps.
- **Al enviar** una alternativa (T-050): se propone "Llamar en N días" (nuevo
  `configuracion_agencia.dias_proximo_contacto`, por defecto 2) con responsable = quien envía.
  Se puede cambiar o quitar en el mismo paso, sin confirmación.
- **"Agendar acción"** desde la cotización, la reserva o la ficha del cliente (tipo, fecha,
  responsable, descripción). **Marcar hecha** ofrece anotar el resultado como actividad (T-050) y
  agendar la siguiente, ambos opcionales.
- Al aceptar o cerrar como perdida una cotización, sus acciones pendientes se cierran solas
  (`hecha_en` + actividad `estado`).
- **Inicio: "Mis pendientes de hoy"** (agencia): acciones del usuario con fecha ≤ hoy, las
  atrasadas primero, con cliente, motivo y enlace; marcar hecha desde ahí. Quien tiene
  `cotizaciones.ver_todas` puede ver también "de todos".
- Cabecera de la cotización: "Próxima acción: llamar el jue 30/10 (Ana)".
- **Pantalla "Seguimiento"** (menú VENTAS, ver T-063): bandeja de acciones pendientes en tres
  bloques (atrasadas, hoy, próximos 7 días), filtros por tipo y responsable ("de todos" solo con
  `cotizaciones.ver_todas`), marcar hecha y reprogramar desde la fila. Ruta nueva
  `agencia.seguimiento`; si T-063 aún no está, la entrada de menú se agrega aquí y T-063 la ubica.

## Fuera de alcance
- Recordatorios por correo o push (motor 11f).
- Pasar acciones de un usuario que se va (T-061).

## Archivos probables
- migraciones nuevas: `crm_acciones` (core), `dias_proximo_contacto` (vertical agencia)
- `api-sistema-fe/app/Models/Client/CrmAccion.php`, controller nuevo
- `api-sistema-fe/app/Services/Dashboard/DashboardService.php`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`, `reservas/detalle.vue`, `views/clients/ficha.vue`, vista de Inicio

## Criterios de aceptación
- [ ] **CA1** — Enviar con 2 días configurados → acción "llamar" para hoy (Perú) + 2 a nombre de quien envía. → test
- [ ] **CA2** — Enviar quitando la acción → ninguna. → test
- [ ] **CA3** — Aceptar la cotización → sus acciones pendientes cerradas. → test
- [ ] **CA4** — "Mis pendientes" muestra solo las del usuario con fecha ≤ hoy, atrasadas primero. → test
- [ ] **CA5** — Marcar hecha con resultado → actividad creada. → test
- [ ] **CA6** — Prueba funcional en agencia-demo; suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: crear/editar acciones de una cotización con su Policy; "de todos" solo con `cotizaciones.ver_todas`
- [ ] Aislamiento de tenant: tabla en la base del tenant
- [ ] Validación de entrada / archivos: fecha no anterior a hoy al crear; responsable activo
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: `HoraPeru::hoy()` para proponer y para "hoy"
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: descripciones como texto
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí

## Cómo verificar
```
php artisan test --filter="Crm|Cotizacion|Dashboard"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
