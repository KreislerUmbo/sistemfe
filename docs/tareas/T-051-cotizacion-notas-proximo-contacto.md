# T-051 — Cotización: notas de contacto y próximo contacto (H50)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-050 |
| **Reglas** | ayudas sin fricción (nada obligatorio) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 13, H50 (aprobada 27-oct-2026) |
| **Rama** | `feat/T-051-cotizacion-proximo-contacto` |

## Contexto
Después de enviar no queda dónde anotar el contacto con el cliente ("llamé, lo está pensando") ni
cuándo volver a llamarlo. `dias_aviso_vencimiento_cotizacion` se configura y nada lo lee.

## Alcance
- Columna `cotizaciones.proximo_contacto` (date, nullable).
- **Al enviar** (T-050): se propone `hoy + N días` (nuevo `configuracion_agencia.dias_proximo_contacto`,
  por defecto 2, editable en Configuración de Agencia). Se puede cambiar o quitar en el mismo
  paso, sin pedir confirmación.
- **"Anotar contacto"** en el historial: nota (obligatoria, es lo único que se escribe) + próximo
  contacto propuesto (`hoy + N`, editable, se puede dejar vacío). Deja una fila
  `contacto` en `cotizacion_seguimientos` y actualiza `proximo_contacto`.
- Al aceptar o cerrar como perdida (T-052), `proximo_contacto` se limpia.
- La cabecera de la cotización muestra "Próximo contacto: jueves 30/10" (o "hoy", "vencido hace 2
  días") con un enlace para cambiarlo.
- Configuración: si `dias_aviso_vencimiento_cotizacion` no tiene uso tras T-053, anotarlo en
  "Hallazgos".

## Fuera de alcance
- Recordatorios por correo o push (motor 11f).
- Listado e Inicio (T-053).

## Archivos probables
- migración nueva (vertical agencia): `cotizaciones.proximo_contacto`, `configuracion_agencia.dias_proximo_contacto`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/` (controller de seguimiento de T-050)
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`, `configuracion/agencia.vue` (o donde viva)

## Criterios de aceptación
- [ ] **CA1** — Enviar con 2 días configurados → `proximo_contacto` = hoy (Perú) + 2. → test
- [ ] **CA2** — Enviar quitando la fecha → `proximo_contacto` null. → test
- [ ] **CA3** — Anotar contacto con nota y fecha → fila `contacto` + fecha actualizada. → test
- [ ] **CA4** — Nota vacía → 422. → test
- [ ] **CA5** — Aceptar la alternativa → `proximo_contacto` null. → test
- [ ] **CA6** — Prueba funcional en agencia-demo; suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: mismo permiso que editar la cotización
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: nota máx. 1000; fecha no anterior a hoy
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: `HoraPeru::hoy()` para proponer y comparar
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: notas como texto
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí

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
