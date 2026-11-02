# T-052 — CRM C3: por qué se ganó o se perdió la venta (H51)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-050, T-051 (hechas) |
| **Reglas** | ayudas sin fricción (el motivo de ganancia es opcional) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 13 (H51, respuesta 2: lista base que crecerá) y obs. 16, fase C3 (27-oct-2026). **02-nov-2026 (usuario): «Reabrir» restaura solas las alternativas que descartó la pérdida** a su estado anterior |
| **Rama** | `feat/T-052-crm-resultado` |

## Contexto
Hoy solo se descarta una alternativa (al aceptar otra), sin motivo; la cotización no tiene un
estado "perdida" y no se sabe por qué se gana ni por qué se pierde. Base: recorridos 20 y 21 de
`docs/aprendizaje/` (T-050 y T-051).

## Alcance
- Catálogo `crm_motivos_resultado` (vertical agencia): `tipo` (`ganada` | `perdida`), `nombre`,
  `activo`, `orden`, timestamps. Las filas base las inserta **la misma migración** (cubre
  tenants existentes y nuevos; patrón: `2026_10_21_200000_create_salida_servicios_y_vehiculos_tables.php`):
  - perdida: Precio, Eligió otra agencia, Cambió de fechas o de planes, No respondió, Otro;
  - ganada: Precio, Rapidez de respuesta, Recomendación, Itinerario, Ya era cliente, Otro.
  Sección simple en Configuración de Agencia (agregar, renombrar, ordenar, desactivar; **nunca
  borrar**: no hay DELETE). «Otro» se reconoce por nombre fijo en una constante; no se puede
  renombrar ni desactivar.
- Columnas en `cotizaciones`: `resultado_motivo_id` (nullable, FK a `crm_motivos_resultado`:
  las dos tablas son del vertical agencia), `resultado_detalle` (string 500, nullable), `perdida_en`
  (timestamp UTC, nullable), `perdida_por` (FK users, nullable).
- Columna en `alternativas`: `estado_antes_de_perdida` (string 20, nullable): guarda `borrador` o
  `enviada` de cada alternativa que descartó la pérdida.
- **«Cerrar como perdida»** (cabecera del cotizador): modal con motivo (obligatorio) y detalle
  (obligatorio solo con «Otro»). En una transacción con `lockForUpdate()` de la cotización (mismo
  orden que `ReservaController::aceptar()`):
  1. 422 si alguna alternativa está `aceptada` o `reemplazada`, si la cotización es de
     modificación (`esModificacion()`) o si ya está perdida;
  2. cada alternativa en `borrador`/`enviada` → `estado_antes_de_perdida = estado`,
     `estado = 'descartada'` (las ya descartadas no se tocan);
  3. guarda motivo, detalle, `perdida_en`, `perdida_por`;
  4. actividad `estado` (T-050): «Cotización perdida: <motivo>[ — detalle]»;
  5. `AccionesCrm::cerrarPendientesDeCotizacion($id, 'Cotización perdida')` (T-051).
- **«Reabrir»** (en lugar de «Cerrar como perdida» cuando está perdida): en transacción con lock,
  cada alternativa con `estado_antes_de_perdida` vuelve a ese estado y la columna queda null;
  limpia motivo, detalle, `perdida_en`, `perdida_por`; actividad `estado` «Cotización reabierta».
  Las fechas de envío y vencimiento de las alternativas no se tocan (nunca se borraron).
- **Mientras está perdida:** crear o duplicar alternativas → 422 «Reabre la cotización primero».
  Enviar y aceptar ya quedan bloqueados solos (todas descartadas: `ESTADOS_SIN_ENVIO` y
  `alternativaAceptable()`); no agregar otra regla ahí.
- **Al aceptar** («Aceptado por cliente»): la confirmación actual (Swal) pasa a un modal propio
  con la misma advertencia y «¿Qué decidió la venta?» con los motivos de ganancia como botones
  (uno elegible, se puede desmarcar); **opcional**. `POST alternativas/{id}/aceptar` acepta
  `resultado_motivo_id` (nullable; si viene, motivo activo de tipo `ganada`) y lo guarda en la
  cotización dentro de la misma transacción. Con «Otro», detalle opcional (no se exige al ganar).
- `Cotizacion::estadoResumen()`: nuevo estado `perdida` (si `perdida_en` no es null), revisado
  **antes** de enviadas/vencida/descartada. Badge y opción de filtro en el listado. Inicio
  (`DashboardService::cotizaciones()`) no la cuenta como enviada ni borrador (sale sola al
  tener su propio estado); sumar `perdidas` al bloque.

## Fuera de alcance
- Reportes de motivos (T-062).
- Pedir motivo al descartar una sola alternativa.
- Venta directa (`VentaDirectaController::store()`): no pide motivo de ganancia.

## Dónde tocar
- **Migraciones nuevas** en `database/migrations/tenant/verticals/agencia-viajes/` (no editar las
  existentes): catálogo + filas base; columnas de `cotizaciones`; `alternativas.estado_antes_de_perdida`.
- `app/Models/AgenciaViajes/CrmMotivoResultado.php` (nuevo, constantes `TIPO_GANADA`,
  `TIPO_PERDIDA`, `NOMBRE_OTRO`).
- `app/Models/AgenciaViajes/Cotizacion.php` → `$fillable`/`$casts`, relación `resultadoMotivo()`,
  `estadoResumen()` (~117), helper `estaPerdida()`.
- **Cerrar / reabrir:** controller nuevo `app/Http/Controllers/AgenciaViajes/CotizacionResultadoController.php`
  (`cerrarComoPerdida()`, `reabrir()`), lógica en un servicio
  `app/Services/AgenciaViajes/ResultadoCotizacionService.php` para que el controller sea delgado.
  Autorización: `cotizaciones.editar` en la ruta + `CotizacionPolicy::update` (como
  `CotizacionActividadController::marcarEnviada()`, que usa `autorizarCotizacion('update', …)`).
  Rutas junto a `cotizaciones/{id}/actividades` en `routes/api.php` (~1007):
  `POST cotizaciones/{id}/perdida`, `POST cotizaciones/{id}/reabrir`.
- **Catálogo:** `app/Http/Controllers/AgenciaViajes/CrmMotivoResultadoController.php` (index,
  store, update; sin destroy). `GET` con `permission:agencia.configuracion|cotizaciones.editar`
  (los modales lo leen); `POST`/`PUT` con `permission:agencia.configuracion`, junto a
  `configuracion-agencia` en `routes/api.php` (~813).
- **Aceptar:** `ReservaController::aceptar()` (~233): validar `resultado_motivo_id` antes de la
  transacción; guardarlo dentro, junto a `cerrarPendientesDeCotizacion()` (~316).
- **Bloquear alternativas nuevas:** `AlternativaController::store()` (~56) y `duplicar()` (~519),
  después de `autorizarCotizacion()`.
- `CotizacionController::show()` (~238): devolver `perdida_en`, `resultado_motivo` (id, nombre,
  tipo), `resultado_detalle` y quién la cerró (`perdidaPor:id,name,surname`).
- `app/Services/Dashboard/DashboardService.php::cotizaciones()` (~331): `perdidas`.
- Frontend:
  - `views/agencia-viajes/cotizador/editar.vue`: cabecera (~14-25, junto a la «Próxima acción» de
    T-051) con el aviso «Perdida: <motivo> · <fecha> (<quién>)» y los botones «Cerrar como
    perdida» / «Reabrir» (ocultos con `modificacionInfo` o alternativa aceptada); `marcarAceptada()`
    (~2148) usa el modal nuevo. Tras cerrar o reabrir: recargar la cotización,
    `historialRef.recargar()` y `accionesRef.recargar()`.
  - Componentes nuevos en `components/AgenciaViajes/`: `CerrarComoPerdidaModal.vue`,
    `AceptarAlternativaModal.vue` (modal con `Teleport`, como «otro medio» en `EnviarAlternativa.vue`).
  - `views/agencia-viajes/cotizador/index.vue`: `badgeEstado()` (~148, `perdida` → «Perdida»,
    `bg-danger-subtle text-danger`) y `<option value="perdida">` en el filtro (~32).
  - `types/agencia-viajes.ts` (~327): `'perdida'` en `estado_resumen`; campos nuevos de `Cotizacion`.
  - `views/agencia-viajes/configuracion/index.vue`: sección «Motivos de venta ganada / perdida».
  - Servicio nuevo `services/admin/crmMotivoResultadoService.ts`; métodos `cerrarComoPerdida` y
    `reabrir` en el servicio de cotizaciones existente.
  - `views/dashboards/analytics/index.vue`: mostrar `perdidas` en el bloque de cotizaciones.

## Criterios de aceptación
- [ ] **CA1** — Cerrar con «Precio» con 2 alternativas enviadas y 1 borrador → las 3 descartadas con su `estado_antes_de_perdida`, estado resumen «perdida», actividad `estado`, acciones pendientes cerradas. → test
- [ ] **CA2** — Con alternativa aceptada → 422; «Otro» sin detalle → 422; motivo de tipo `ganada` o inactivo → 422; ya perdida → 422. → test
- [ ] **CA3** — Reabrir → las 3 vuelven a enviada/enviada/borrador con su `fecha_envio` y `fecha_vencimiento` intactas; una alternativa que ya estaba descartada antes de perder sigue descartada; sin datos de pérdida; actividad. → test
- [ ] **CA4** — Aceptar con motivo de ganancia → guardado en la cotización; sin motivo → acepta igual; motivo de tipo `perdida` → 422. → test
- [ ] **CA5** — Motivo nuevo desde Configuración aparece en el modal; no hay forma de borrar uno; desactivado no se ofrece ni se acepta; «Otro» no se renombra ni desactiva. → test
- [ ] **CA6** — Perdida: crear o duplicar alternativa → 422. → test
- [ ] **CA7** — Prueba funcional en agencia-demo (cerrar, ver badge y filtro, reabrir, aceptar con motivo); suite del vertical en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: cerrar/reabrir con `cotizaciones.editar` + `CotizacionPolicy::update`; catálogo con `agencia.configuracion` (lectura también con `cotizaciones.editar`)
- [ ] Aislamiento de tenant: tablas en la base del tenant
- [ ] Validación de entrada / archivos: motivo activo y del tipo correcto; detalle máx. 500
- [ ] Concurrencia (`lockForUpdate`): cerrar, reabrir y aceptar bloquean la fila de la cotización (mismo orden que `aceptar()`); dentro del lock se vuelve a revisar el estado
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: `perdida_en` es instante (UTC); se muestra con `formatFechaHora()`
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: motivo y detalle como texto
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí (vertical agencia)

## Cómo verificar
```
php artisan test --filter="Cotizacion|Reserva|CrmAccion|Dashboard|MenuGiros"
cd admin-start-kit && npm run type-check && npx vitest run
```
Prueba funcional: agencia-demo, `admin@agencia-demo.test` (memoria `reference_agencia_demo_admin_login`).

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
