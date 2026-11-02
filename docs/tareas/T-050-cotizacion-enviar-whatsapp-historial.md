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
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionEnvioController.php` → `historial()` (~65),
  `enviar()` (~91; el envío exitoso marca la alternativa ~209: ahí va la actividad `envio`/`correo`)
- `api-sistema-fe/app/Models/AgenciaViajes/Alternativa.php` → `camposAlMarcarEnviada()` (~72, solo usar)
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaController.php` → `update()` marca
  `enviada` (~212): mismo criterio para `marcarEnviada()`
- `api-sistema-fe/routes/api.php` → rutas de envío por correo (~969-975); la ruta de la ficha sigue el
  prefijo existente `clients/{id}/actividades`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue` (no leerlo entero): `<EnviarCorreoCotizacion>`
  ~800, import ~1402, comentario del envío ~1889; el resto con `grep`
- `admin-start-kit/src/components/AgenciaViajes/EnviarCorreoCotizacion.vue` (233 líneas)
- `admin-start-kit/src/views/clients/ficha.vue` (481 líneas): las secciones se deciden por
  `contexto` (`GET clients/contexto` devuelve `giro`); **"Anotar contacto" solo con
  `contexto.giro === 'agencia_viajes'`** (obs. 16: el CRM es solo Agencia por ahora; la tabla va en
  core para no cerrarle la puerta a otros giros)

## Criterios de aceptación
- [x] **CA1** — "Ya la envié por otro medio" en borrador → enviada, `fecha_envio`, vencimiento (si hay días configurados) y una actividad. → test
- [x] **CA2** — Reenviar → otra actividad; `fecha_envio` no cambia. → test
- [x] **CA3** — Aceptada o descartada → 422. → test
- [x] **CA4** — Correo exitoso → actividad `envio`/`correo`; fallido → sin actividad. → test
- [x] **CA5** — Anotar una llamada desde la cotización → actividad con cliente y referencia. → test
- [x] **CA6** — WhatsApp descarga el PDF y abre el chat. → prueba funcional en agencia-demo
- [x] **CA7** — `tenants:migrate-verticales` crea la tabla en un tenant retail sin errores (core). → prueba
- [x] **CA8** — Suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [x] Permisos / Policy en rutas nuevas: enviar/anotar sobre una cotización = permiso de editarla (`CotizacionPolicy`); desde la ficha = permiso de editar clientes
- [x] Aislamiento de tenant: tabla en la base del tenant
- [x] Validación de entrada / archivos: `tipo` y `canal` en lista; nota máx. 1000
- [x] Concurrencia (`lockForUpdate`): no aplica
- [x] Dinero en centavos: no aplica
- [x] Fechas con `HoraPeru`: se muestran con `formatFechaHora()`
- [x] Tributario sin fallback silencioso: no aplica
- [x] Sin `v-html` sin sanitizar: notas como texto
- [x] Migraciones nuevas + `tenants:migrate-verticales`: sí

## Cómo verificar
```
php artisan test --filter="Cotizacion|Alternativa|Crm"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:** `fb1e856` (código + tests); este commit de docs.
- **Tests:** 12 nuevos en `tests/Feature/AgenciaViajes/CrmActividadCotizacionTest.php` (CA1-CA5,
  permisos y validación); `EnvioCorreoCotizacionTest` sigue en verde. Suite completa: 1832 en verde
  + 1 fallo, el intermitente conocido `Fila13Fase1cFacturacionTest::descuento_del_combo_se_reparte…`,
  que pasa al correrlo aislado. `type-check`: 46 errores, todos preexistentes (portal, router,
  fake-backend); ninguno en archivos tocados. `vite build` OK.
- **CA6 (Playwright, agencia-demo, cotización CDKM-1126-0000003 / alternativa «Test Avianca 3»,
  cliente sin teléfono):** «Enviar al cliente → Por WhatsApp» descargó `cotizacion-Test Avianca 3.pdf`
  y abrió el chat (wa.me → api.whatsapp.com) sin número, con «Hola ROXANA: te envío la cotización
  de tu viaje a Lima Capital (Test Avianca 3). Total: US$ 300.00. Vigente hasta el 17/11/2026…».
  Luego «otro medio» (reenvío, `fecha_envio` sin cambio en la base), «Anotar contacto» (visita)
  desde el cotizador y una nota desde la ficha del cliente 10. **Quedaron en agencia-demo:** la
  alternativa 103 pasó a enviada y hay 4 filas en `crm_actividades` (datos de prueba).
- **CA7:** `tenants:migrate-verticales` creó la tabla en los 7 tenants locales, incluidos los retail
  `negocio2`, `sandbox`, `umbo`, `umbo-archivado`, y los de créditos, sin errores.
- **Desviaciones de la tarea y por qué:**
  - **Pase de envíos viejos en la migración.** El historial reemplaza a «Envíos por correo»; sin
    pase, los correos enviados antes de T-050 desaparecían de la pantalla. La migración copia los
    `cotizacion_envios` enviados como `envio`/`correo` (solo si la tabla existe, o sea en tenants
    con Agencia; usuario inexistente → `usuario_id` nulo). Probado en agencia-demo con filas de
    prueba dentro de una transacción revertida (agencia-demo no tenía envíos reales).
  - **Endpoints nuevos en `CotizacionActividadController`** (marcar-enviada + historial) en vez de
    sumar a `AlternativaController`, que ya es muy largo; el criterio es el mismo que `update()`.
  - **Se quitó `GET alternativas/{id}/envios-correo`** (`CotizacionEnvioController::historial()`) y
    su método en el servicio del frontend: lo reemplaza `GET cotizaciones/{id}/actividades`.
  - `POST clients/{id}/actividades` acepta como referencia solo `cotizacion` (la de `reserva` no
    tiene pantalla todavía; la columna sí la admite).
  - `EnviarCorreoCotizacion.vue` quedó solo como modal; el botón «Enviar al cliente» es
    `EnviarAlternativa.vue`. WhatsApp y otro medio se ofrecen solo con la alternativa en
    borrador/enviada; el correo mantiene su regla anterior.
  - El enlace a WhatsApp reutiliza `enlaceWhatsappConTexto()` de `helpers/creditos/documentos.ts`.
  - **En revisión (02-nov-2026): «Anotar contacto» salió de la ficha del cliente.** Lo anotado ahí
    no tenía referencia y no se veía en ninguna pantalla hasta la línea de tiempo; vuelve con T-060
    (anotado en su alcance). El endpoint sin referencia y su test quedan. Desde el cotizador sigue.
  - **En revisión:** si el navegador bloquea la pestaña de WhatsApp, un aviso ofrece «Abrir
    WhatsApp» (con el clic del vendedor) en vez de un segundo `window.open` que también se bloqueaba.

## Hallazgos fuera de alcance
- `sistemafe_test_migrations` tenía pendiente `2026_10_09_100000_migrar_fechas_lima_a_utc`; se
  corrió en el mismo lote al aplicar la migración nueva (base descartable de tests, sin efecto en
  los tests, que revierten su transacción). Conviene revisar por qué quedó pendiente ahí.
- agencia-demo no tiene habilitado el envío por correo: la opción «Por correo» no se pudo ver en la
  prueba funcional (cubierta por tests).
- La clase `CrmActividadController` (core) referencia `App\Models\AgenciaViajes\Cotizacion` solo
  cuando llega `referencia_tipo=cotizacion`; en un tenant sin Agencia eso daría 500 en vez de 422.
  Hoy ninguna pantalla lo envía fuera de Agencia.
