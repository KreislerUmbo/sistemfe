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
- **Una sola bandeja:** el plan de tours prevé una "bandeja única de pendientes" automáticos
  (salida bajo el mínimo, pago a proveedor por vencer, factura faltante…;
  `plan-tours-venta-simple-costo-real.md` §3.14, fila 11f). "Seguimiento" y "Mis pendientes" se
  arman como **esa** bandeja con una primera fuente (las acciones del CRM): la respuesta de la API
  lleva `fuente` (`crm` | `operacion`) y la pantalla no supone que todo es una acción manual, para
  que 11f sume sus pendientes sin otra pantalla. La tabla `recordatorios` (vertical, sin
  controller ni uso) no se usa aquí: decisión en la obs. 19. **Decidido 27-oct: una sola bandeja.** Pestañas o
  filtro por origen ("Mis acciones" · "Operación"); un pendiente automático no tiene "Marcar hecha"
  sino "Resolver" (enlace), y desaparece solo.

## Fuera de alcance
- Recordatorios por correo o push (motor 11f).
- Pasar acciones de un usuario que se va (T-061).

## Archivos probables
Base de T-050 (leer antes): `docs/aprendizaje/20-recorrido-crm-enviar-y-anotar-contacto.md`.

- migraciones nuevas: `crm_acciones` en `database/migrations/tenant/core/` (mismo patrón de
  referencia sin FK que `2026_11_02_100000_create_crm_actividades_table.php`);
  `dias_proximo_contacto` en `verticals/agencia-viajes/`
- `api-sistema-fe/app/Models/Client/CrmAccion.php` (nuevo, junto a `CrmActividad.php`) + controller
  nuevo en `app/Http/Controllers/Client/` (junto a `CrmActividadController.php`)
- **Proponer la acción al enviar:** los tres medios pasan por dos endpoints:
  `CotizacionActividadController::marcarEnviada()` (WhatsApp / otro medio) y
  `CotizacionEnvioController::enviar()` (correo, camino exitoso ~línea 190, junto a
  `registrarEnvio()`). Ambos aceptan un campo opcional `proxima_accion` (`{fecha, responsable_id}`
  o null = quitarla) y la crean en la misma operación. Frontend: `EnviarAlternativa.vue` (WhatsApp y
  otro medio) y el modal `EnviarCorreoCotizacion.vue`; la fecha propuesta = hoy Perú + días
  configurados (`hoyPeru()`/`sumarDiasISO()`).
- **Cerrar las pendientes al aceptar:** todos los caminos de aceptación llaman a
  `AlternativaController::descartarOtras()` (~línea 556): `ReservaController::aceptar()` (~313) y
  `VentaDirectaController::store()` (~123). Poner el cierre en un método compartido llamado junto a
  ese (no copiarlo en cada controller). «Cerrar como perdida» no existe todavía: lo trae T-052 y
  llamará al mismo método.
- **Marcar hecha con resultado:** la actividad se crea con `CrmActividad` (tipos manuales de
  `CrmActividad::TIPOS_MANUALES`); el modal puede reutilizar `components/Clientes/AnotarContacto.vue`
  o su lógica.
- Referencia `reserva`: hoy `CrmActividadController::store()` acepta solo `cotizacion`; para
  agendar desde la reserva hay que sumar `reserva` con `ReservaPolicy`.
- `configuracion_agencia`: `app/Models/AgenciaViajes/ConfiguracionAgencia.php` (`$fillable` ~30),
  `ConfiguracionAgenciaController.php` (valores por defecto ~25, validación ~62) y su pantalla.
- **Inicio:** `app/Services/Dashboard/DashboardService.php::inicio()` (~49; rama de agencia ~64);
  frontend `views/dashboards/analytics/index.vue` + `services/admin/dashboardService.ts`.
- **Menú «Seguimiento»:** `database/seeders/MenuItemsSeeder.php::menuAgencia()` (~155), grupo
  VENTAS (~162); el comentario de ~153 ya lo anuncia. Mismo permiso que Cotizaciones.
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue` (no leerlo entero: `grep`
  `EnviarAlternativa`, `HistorialActividadesCotizacion`), `views/agencia-viajes/reservas/detalle.vue`,
  `views/clients/ficha.vue` (las secciones de Agencia van con `contexto.giro === 'agencia_viajes'`)

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
- **Commits:** `80251b3` parte 1 (tabla, propuesta al enviar, agendar / hecha / reprogramar, cierre al
  aceptar: CA1, CA2, CA3, CA5) · `74d0c14` parte 2 (bandeja «Mis pendientes de hoy», cabecera
  «Próxima acción», pantalla Seguimiento y su menú: CA4).
- **Tests:** nuevos en `tests/Feature/AgenciaViajes/CrmAccionTest.php` (19, con el reloj a las 21:00
  de Lima para probar HoraPeru): CA1 `test_whatsapp_con_la_propuesta_…`, `test_correo_con_la_propuesta_…`,
  `test_la_propuesta_acepta_hoy_peru_…`; CA2 `test_enviar_quitando_la_propuesta_…`; CA3
  `test_aceptar_la_cotizacion_cierra_…`, `test_aceptar_sin_acciones_pendientes_…`; CA4
  `test_mis_pendientes_de_hoy_en_inicio_…`, `test_seguimiento_trae_las_propias_…`,
  `test_de_todos_o_de_otro_responsable_…`; CA5 `test_marcar_hecha_con_resultado_…`; más permisos,
  validación y reprogramar. Ajustados: `MenuGirosTest` (Seguimiento en VENTAS) y `DashboardTest`
  (bloque `pendientes`). Vitest: `formatDiaCorto` en `helpers/fecha.test.ts` (170/170).
  Suite completa: 1851 pasan y 1 falla, el intermitente conocido `Fila13Fase1cFacturacionTest::descuento_del_combo…`, que pasa al correrlo solo (32/32, dos veces). `type-check`: 46 errores, todos previos (en `main` con los mismos archivos
  hay más); ninguno en archivos tocados. `build` OK.
- **Prueba funcional (CA6)** en agencia-demo con Playwright, cotización `CDKM-1126-0000003` (id 66),
  alternativa «Test Avianca 3»: el panel «Enviar al cliente» propone «llamar el mié 04/11 (tú)»;
  «otro medio» la crea y aparece la cabecera «Próxima acción»; «Agendar acción» (cobrar saldo hoy);
  «Hecha» con resultado + siguiente (actividad `llamada` y nueva acción); Inicio muestra solo la de
  hoy y «De todos»; Seguimiento con los tres bloques, «Reprogramar» desde la fila; la tarjeta aparece
  en la reserva 31 y en la ficha del cliente 10. **Datos de prueba que quedaron en agencia-demo:**
  `crm_acciones` 1-3 y `crm_actividades` 5-6 (cliente 10, cotización 66); borrarlos si molestan.
- **Desviaciones de la tarea y por qué:**
  - La propuesta «Llamar en N días» vive en el panel «Enviar al cliente» (una línea sobre los tres
    medios, editable o quitable) y se repite en el modal del correo: el envío por WhatsApp no tiene
    paso propio. Decidido con el usuario.
  - Marcar hecha / reprogramar: su responsable siempre, o quien puede editar la referencia
    (CotizacionPolicy / ReservaPolicy; sin referencia, `edit_client`). Decidido con el usuario.
  - Si la cotización ya tiene una acción pendiente, al reenviar no se propone otra (evita duplicar
    llamadas; se puede agendar a mano desde la misma línea).
  - Al aceptar, la actividad `estado` se escribe solo si se cerró alguna acción (sin acciones no hay
    nada que contar).
  - `GET crm/acciones/opciones` devuelve `usuario_id`: el usuario que el login guarda en el navegador
    no trae su `id` (ver hallazgos), y la propuesta tiene que ir «a nombre de quien envía».
  - En Inicio, «hoy» sale del `$hoy` que `DashboardService::inicio()` ya calcula en hora de Lima (mismo
    día que `HoraPeru::hoy()`), para respetar el reloj inyectable del servicio; el endpoint de la
    bandeja usa `HoraPeru::hoyTexto()`.
  - Las pestañas por origen («Mis acciones» · «Operación») aparecen solo cuando la bandeja trae más de
    una `fuente`; hoy solo hay `crm`, así que no se muestran pestañas vacías.
  - La tarjeta de acciones en la ficha del cliente se muestra solo en Agencia (obs. 16, respuesta 5).

- **Revisión de Opus (02-nov):** el caché de `crmAccionService.opciones()` (una consulta por carga de la
  app) guardaba el `usuario_id` de la sesión, y el logout no recarga la página: quien entraba
  después en la misma pestaña proponía las llamadas a nombre del anterior (y la pantalla decía «tú»).
  Se vacía con `olvidarOpciones()` en `auth.removeSession()` y al guardar la Configuración de
  agencia (`dias_proximo_contacto`). El login sin `id` pasó a T-079 (rama
  `fix/T-079-login-id-usuario`); el envío por correo con `view`, a «Por analizar» del backlog.

## Hallazgos fuera de alcance
- **El usuario guardado al iniciar sesión no tiene `id`** (`localStorage.user`: fullname, email,
  avatar, role, permissions…). `usePermisosCredito().usuarioId` vale siempre 0, así que en
  `views/creditos/detalle.vue` `puedeAnularPago(p.registrado_por, usuarioId, …)` nunca reconoce un pago
  propio. Revisar qué devuelve el login y si Créditos depende de eso.
- **HMR de Vite falla al recargar componentes** con `ReferenceError: Cannot access 'PortalLayout'
  before initialization` en `router/routes.ts`: los cambios no se ven hasta una recarga completa (y a
  veces ni así, hubo que tocar el archivo). Solo afecta al desarrollo.
- `CotizacionEnvioController::enviar()` autoriza con `CotizacionPolicy::view` (no `update`), así que
  quien solo puede ver una cotización y tiene `cotizaciones.enviar_correo` también deja la acción
  propuesta. Es coherente con el envío actual, pero conviene decidir si enviar = editar, como en
  WhatsApp / otro medio.
- El 500 transitorio de Apache (memoria «fe_sendauth no password») apareció una vez en
  `GET crm/acciones` durante la prueba; al reintentar respondió 200.
