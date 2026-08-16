# Plan: Panel Superadmin (gestión central de tenants)

## Contexto

Hoy la creación de tenants depende del comando `tenants:provision` corrido manualmente por
consola. Este plan formaliza un panel central (fuera de la resolución de tenancy, en su propio
guard de auth) para:

1. Dar de alta/gestionar tenants sin depender de terminal.
2. Completar el tramo que hoy falta después del provisioning: `Company` + `SunatConfig` +
   certificado digital (sin esto un tenant no puede facturar de verdad).
3. Controlar suscripciones y pagos mensuales, con suspensión configurable por falta de pago.
4. Backups por tenant, programados y bajo demanda, con restauración segura.
5. Dejar una base de datos y de servicios que escale sin migraciones correctivas dolorosas.

## Principios de diseño

- **Configuración como datos, no como código.** Nada de valores hardcodeados para cosas que
  varían por tenant o por ambiente (retención de backups, frecuencia, días de gracia, precios).
  Todo vive en `platform_settings` (default global) con override opcional por tenant.
- **Multi-admin desde el día uno.** `central_users` + `central_roles`, aunque hoy el único rol
  activo sea superadmin.
- **Todo comando Artisan es un wrapper delgado sobre un servicio invocable.** El panel HTTP y el
  comando de consola deben llamar a la misma clase de servicio — nunca lógica duplicada.
- **Auditoría desde el inicio.** Toda acción sensible (alta, cambio de certificado, restauración
  de backup, suspensión, archivado) queda en `central_audit_logs`.
- **Separar config de plataforma vs. config de tenant.** No mezclar en la misma tabla.
- **Guard de auth central separado del guard `api` de tenants.** Un JWT de un tenant nunca debe
  poder autenticar contra el panel central.

## Modelo de datos (central)

```
central_users            → admins de la plataforma
central_roles            → superadmin, soporte, solo-lectura
platform_settings         → key-value: config global (defaults de días de gracia, día de corte,
                             retención de backups, etc.)
tenant_plans               → catálogo de planes (límites: usuarios, comprobantes/mes, storage)
central_audit_logs         → quién hizo qué acción, sobre qué tenant, cuándo, con qué payload

tenant_backups              → tenant_id, path, tamaño, tipo (manual/auto), estado, fecha
tenant_subscriptions        → tenant_id, plan_id, monto_mensual_override (nullable),
                              dia_corte (nullable, default vía platform_settings),
                              dias_gracia_suspension (nullable, default vía platform_settings),
                              facturacion_automatica (bool, default false), estado
tenant_invoices              → tenant_id, subscription_id, periodo, folio_interno, monto,
                              fecha_vencimiento, estado (pendiente/pagado/vencido),
                              fecha_pago, sunat_document_id (nullable — nota futura abajo)
tenant_payment_vouchers        → invoice_id, path, fecha_subida, estado
                              (pendiente_verificacion/verificado/rechazado)
```

Ampliación a `tenants` (ya existente):
```
status   → agregar estado intermedio 'suspendido' (hoy: activo / archivado)
```

## Nota futura (no se implementa ahora)

**Autofacturación de umbo.** El propio umbo cobra mensualmente a RUCs peruanos por el servicio,
lo cual probablemente exige emitir comprobante electrónico propio. El campo
`sunat_document_id` en `tenant_invoices` queda reservado desde ya para que, el día que se
conecte, un job pueda generar la boleta/factura real usando el mismo `GreenterService` ya
existente, sin rediseñar la tabla.

## Casos de negocio ya resueltos en el diseño

- **Precio negociado por tenant** → `monto_mensual_override` (si es null, usa el precio del plan).
- **Días de gracia distintos por cliente** (2 meses estándar, hasta 6 para clientes de
  confianza) → `dias_gracia_suspension` nullable con default global en `platform_settings`.
- **Corte el día 28** → `platform_settings.dia_corte_default = 28`; override opcional por
  tenant si algún día se necesita.
- **Facturación mensual: manual por defecto, automática opcional por tenant** →
  `facturacion_automatica` bool. El scheduled command corre siempre, pero solo genera
  automáticamente para los tenants marcados; el resto requiere botón manual. Dashboard debe
  señalar tenants manuales con período vencido sin invoice generada aún.
- **Suspensión no debe cortar procesos fiscales a medias** → el middleware de suspensión
  bloquea *acceso nuevo* a la aplicación, pero no debe abortar jobs de envío a SUNAT ya en
  cola — evita dejar comprobantes del cliente a medio emitir por un tema de cobranza.
- **Notificaciones en checkpoints, no un solo salto** → recordatorio al vencer, aviso a mitad
  del período de gracia, confirmación al suspender/reactivar. Plantillas configurables
  (`notification_templates` o Blade versionado), no texto hardcodeado en el código.
- **Idempotencia de comandos programados** → `tenants:generate-monthly-invoices` y
  `tenants:check-overdue-payments` deben validar existencia previa (`where periodo=X and
  tenant_id=Y`) antes de crear/suspender, para tolerar reintentos o corridas dobles del cron.

## Nota de infraestructura (confirmada por diagnóstico del sistema actual)

No hay worker de colas corriendo en este entorno (`QUEUE_CONNECTION=database` sin worker
activo — ya se ve hoy en que `Jobs\SeedDatabase` está deshabilitado con `shouldBeQueued(false)`
porque de otro modo quedaría encolado sin ejecutarse nunca). Por lo tanto, todo lo que este plan
programe como recurrente (backups automáticos en Fase C, `tenants:generate-monthly-invoices` y
`tenants:check-overdue-payments` en Fase B.2) debe implementarse vía **Laravel Scheduler +
cron del sistema**, no vía jobs despachados a una cola. Si en el futuro se activa un worker real,
recién ahí evaluar mover estos procesos a jobs encolados.

## Fases

### Fase 0 — Cimientos ✅ Completada (2026-07-20)
- Migraciones: `central_users`, `central_roles`, `platform_settings`, `central_audit_logs`
- Guard de auth central, separado del guard `api` de tenants
- Servicio `AuditLogger` inyectable
- Seeder del primer `central_user`

**Nota de seguridad (confirmada durante implementación):** un provider/guard distinto no
alcanza para aislar el panel central, porque jwt-auth no ata el token a un guard específico y
el `JWT_SECRET` es compartido entre guards — un token de tenant con el mismo `id` numérico que
un `CentralUser` podría autenticar por accidente contra el guard `central`. Se cierra con:
`CentralUser::getJWTCustomClaims()` → `['guard' => 'central']`, más un middleware
`EnsureTokenIsCentralGuard` (mismo patrón que `EnsureTokenBelongsToTenant`) que verifica ese
claim activamente. Implementado ya en Fase 0, no diferido a Fase A, para que el guard quede
realmente aislado desde el día uno y no solo de nombre.

**Nota de conexión (histórica — revertida en "B.0.5", ver esa sección):** en Fase 0 se decidió
NO mover la clave `central` (compartida por `tenants`/`domains` y los 7 catálogos SUNAT) para no
migrar datos reales fuera del alcance de esa fase. Esa separación se sostuvo hasta después de
Fase B — cuando el proyecto seguía en desarrollo puro sin despliegue real, se decidió resolverla
del todo. Los 5 modelos del panel volvieron a usar `central` directo; ya no existe una clave
`db_tenant_central` separada.

### Fase A — Wrapper HTTP del provisioning ✅ Completada (2026-07-20)
- Extraer lógica de `ProvisionTenant` a `TenantProvisioningService` (reutilizada por el Command
  y por el Controller)
- `POST /api/central/tenants`, `GET /api/central/tenants`, `GET /api/central/tenants/{id}`
- Toda acción pasa por `AuditLogger`

### Fase B — Company + SunatConfig + certificado ✅ Completada (2026-07-20)

**Hallazgo crítico (confirmado al iniciar, 2026-07-20):** `GreenterService::getSee()` no usa
`SunatConfig` para RUC/usuario SOL/clave SOL — esos valores salen de `env('RUC')`/
`env('USER_SOL')`/`env('USER_PASS')`, únicos y globales para toda la instancia. El modo
beta/producción se decide por `app()->environment('local')`, no por `SunatConfig.modo`. Esto
significa que, hasta hoy, **todo tenant que facture emite bajo la misma identidad SUNAT**
(la de `umbo`, configurada en el `.env` del servidor) — viola el principio de "nunca silencioso
en campos fiscales". `SunatConfig` existe migrada (0 filas usadas nunca) pero solo su
`certificado_path` se lee, como fallback opcional al certificado demo.

**Hallazgo secundario:** el disco `local` en `config/filesystems.php` apunta a la misma carpeta
que `public` — no hay disco privado real. Ya estaba anotado en `CLAUDE.md` como pendiente sin
resolver; se aborda en B.2 porque Fase B va a subir certificados de clientes reales.

Alcance dividido en 3 sub-fases:

- **B.1** ✅ Completada (2026-07-20) — Conectada `SunatConfig` de verdad a
  `GreenterService::getSee()`/`resolveCertificado()`. `.env` (`RUC`/`USER_SOL`/`USER_PASS`,
  `app()->environment('local')`) eliminado por completo del archivo, sin fallback: sin fila
  `SunatConfig` activa → 422 explícito ("Este tenant no tiene configuración SUNAT activa...").
  `modo='produccion'` exige `certificado_path` presente + archivo existente +
  `certificado_fecha_vencimiento` futura, 422 si falla cualquiera; `modo='beta'` conserva el
  fallback al certificado demo central si el tenant no subió el propio (decisión explícita,
  el gate estricto es solo para producción). Migradas a `SunatConfig` explícita las
  credenciales que antes vivían implícitas en `.env` (RUC `20161515648`, SOL `MODDATOS`/
  `MODDATOS`, credenciales estándar de pruebas SUNAT) para `sandbox` y `umbo`, `modo='beta'`
  — `negocio2` sin `Company` propia, ya estaba fuera del flujo SUNAT antes de este cambio, no
  se tocó. **Verificado con una emisión real**: venta #36 de `sandbox` (draft, nunca antes
  enviada) pasada por `FacturacionElectronicaController::enviarSunat()` completo — SUNAT BETA
  la aceptó (`cdrResponse.code="0"`, "La Boleta numero B001-7, ha sido aceptada"), confirmando
  que el nuevo camino por `SunatConfig` funciona igual que el viejo por `.env`.
- **B.2** ✅ Completada (2026-07-20) — Disco `private` nuevo en `config/filesystems.php`
  (`storage/app/private`, `visibility: private`, `throw: true` — sin `url`, no queda
  mapeado a ningún symlink público, a diferencia de `local`/`public` que apuntan a la
  misma carpeta pública hoy). `GreenterService::resolveCertificado()` migrado a
  `Storage::disk('private')` para el certificado propio del tenant (`produccion` y el
  fallback opcional de `beta`); el certificado demo central sigue con `base_path()`
  directo, sin tocar (fuera de alcance). **Bug real encontrado y corregido antes de
  cerrar la fase**: el disco nuevo no quedaba aislado por tenant — `config/tenancy.php`
  (`filesystem.disks`) solo lista `local`/`public` para que
  `FilesystemTenancyBootstrapper` les aplique el sufijo `tenant{id}`; cualquier disco no
  listado ahí se queda siempre apuntando a la carpeta central sin importar qué tenant
  esté activo. Detectado con una prueba cruzada explícita (guardar en `sandbox`, leer la
  misma ruta relativa desde `umbo` → daba `SI`, mal) ANTES de dar la fase por cerrada, no
  después. Corregido agregando `private` a `filesystem.disks` y `root_override` en
  `config/tenancy.php` — única forma real de lograr el aislamiento, tocar ese archivo fue
  necesario pese a la restricción de no tocarlo en fases anteriores (avisado
  explícitamente). Prueba cruzada repetida tras el fix: `sandbox` no ve el archivo de
  `umbo` ni viceversa, y el contexto central (sin tenant activo) resuelve a una tercera
  carpeta propia — los 3 aislados entre sí, confirmado por el `root` físico resuelto de
  cada uno, no solo por inspección de código.
- **B.3** ✅ Completada (2026-07-20) — `TenantSunatController`
  (`app/Http/Controllers/Central/`), 4 rutas dentro de `auth:central`+`central.token`:
  `POST tenants/{id}/company` (upsert), `GET tenants/{id}/sunat-config` (sin exponer
  `sol_clave`/`certificado_password`), `POST tenants/{id}/sunat-config` (upsert con el
  gate de `modo=produccion`), `POST tenants/{id}/sunat-config/certificado` (sube `.pfx`+
  password, convierte a PEM, guarda en disco `private`). Todo dentro de `$tenant->run()`
  (mismo mecanismo que `TenantProvisioningService`, Fase A) — el panel vive fuera de la
  resolución de tenancy. `SunatConfig::tieneCertificadoPropio()`/`certificadoEsValido()`
  extraídos como único punto de verdad, reusados tanto por `GreenterService` (B.1) como
  por este controller — sin duplicar la condición.
  - **3 hallazgos reales, corregidos antes de cerrar la fase**:
    1. **Formato de certificado**: `SignedXml::setCertificate()` (vendor Greenter) exige
       PEM sin cifrar, no un `.pfx` crudo — confirmado leyendo el código del vendor
       (`X509Certificate`/`openssl_pkcs12_read` existen en el paquete pero no están
       conectados a este flujo). El endpoint de certificado convierte con
       `openssl_pkcs12_read()` + arma el PEM combinado (`cert+pkey`) antes de guardarlo;
       la fecha de vencimiento se lee del propio X.509 (`openssl_x509_parse`), no de un
       campo de formulario aparte que podría desincronizarse del archivo real.
    2. **`Tenant::run()` (vendor stancl/tenancy) no tiene try/finally**: si el closure
       lanza una excepción, la tenancy nunca revierte al contexto original. Ninguna
       validación de negocio de este controller lanza excepciones DENTRO de `run()` —
       todas devuelven `['error' => '...']`, y el controller relanza como
       `HttpException` recién DESPUÉS de que `run()` ya retornó normalmente.
    3. **Bug real encontrado por la propia prueba, no por lectura de código**:
       `company()` devolvía el modelo `Company` crudo fuera de `$tenant->run()` —
       `response()->json()` lo serializa después, y el `json_encode()` dispara
       `getDateFormat()->getConnection()` de forma perezosa sobre una conexión `tenant`
       que ya no existe en ese punto (tenancy ya revirtió) → excepción real,
       reproducida con una llamada aislada al controller antes de corregirla
       (`->toArray()` movido adentro del closure — mismo criterio que ya usaban
       `sunatConfigShow()`/`sunatConfigStore()`/`sunatConfigCertificado()`, que
       serializan `SunatConfig` adentro precisamente porque sus helpers de certificado
       consultan `Storage::disk('private')`, sensible al tenant activo).
  - **Verificado con una suite de 8 pasos reales contra `sandbox`/`umbo`** (controller
    invocado directo, sin pasar por HTTP): 404 de tenant inexistente; upsert de
    `Company` idempotente (sin duplicar fila); `GET sunat-config` sin `sol_clave`/
    `certificado_password` en la respuesta; upsert de `SunatConfig` idempotente; gate de
    `modo=produccion` bloqueando con 422 sin persistir nada cuando no hay certificado
    válido; subida real de un `.pfx` autofirmado descartable (generado con `openssl` solo
    para esta prueba) + **verificación cruzada explícita**: el PEM convertido queda
    visible desde `sandbox`, invisible desde `umbo`, invisible desde el contexto central
    — confirmado por el `root` físico resuelto en cada caso, no por inspección de
    código; `modo=produccion` aceptado una vez que el certificado ya es válido. `sandbox`
    restaurado a su estado post-B.1 (modo `beta`, sin certificado) al final — cero
    artefactos de prueba persistidos.
  - **Pendiente, explícitamente diferido**: migrar a `umbo` a su propia config SUNAT
    real (más allá de las credenciales de prueba ya migradas en B.1) es una decisión de
    negocio del usuario, no algo que se dispare solo. Company/SunatConfig de `sandbox`/
    `umbo` quedaron con los mismos valores de prueba que B.1 ya había migrado — sin
    certificado propio cargado en ninguno de los dos.

Gate explícito: no se permite `modo=produccion` sin `SunatConfig` activo con certificado
válido. **Cerrado.**

### Fase B.0.5 — Consolidación de la conexión `central` ✅ Completada (2026-07-20)

Resuelve del todo el bloqueante que Fase 0 había dejado documentado (nota de arriba): la clave
`central` pasa a apuntar directo a `db_tenant_central`, sin dos conexiones separadas para lo
mismo. Decidido al arrancar la fase de Suscripciones — como el proyecto sigue en desarrollo puro
(sin despliegue ni facturación real), tenía más sentido resolverlo ahora que seguir arrastrando
la división.

- **Diagnóstico previo (nada de código hasta confirmarlo todo)**: de las 9 tablas originalmente
  identificadas (`tenants`, `domains`, `tax_configs`, `detraction_codes`, `note_motivos`,
  `tipos_comprobante`, `systems`, `system_categories`, `manual_recursos`), el mapeo de FKs
  reales en Postgres reveló **4 tablas adicionales obligatorias**: `system_modules`,
  `system_features`, `system_media`, `plans` — las 4 tienen FK real hacia `systems.id` y **no
  tienen ningún modelo Eloquent** (confirmado por grep, cero código de aplicación las toca hoy);
  aparecieron solo porque `information_schema` las muestra como dependientes de `systems`, no
  por ninguna migración `$connection='central'` oculta. Sin moverlas, un `DROP TABLE systems`
  en `sv_facturacion` habría fallado directo. **Grupo real: 13 tablas, no 9.**
  - Único FK cruzado encontrado hacia afuera del grupo: `products.codigo_detraccion ->
    detraction_codes.codigo` — pero ese `products` es la tabla residual pre-multitenancy que
    sigue en `sv_facturacion` (22 filas, ya documentada como pendiente arquitectónico en
    `CLAUDE.md`, no la tabla real de ningún tenant). Se dropeó ese FK puntual
    (`fk_producto_detraccion`) en vez de arrastrar `products` al grupo.
  - Conteos confirmados antes de tocar nada: tenants 4, domains 3, tax_configs 6,
    detraction_codes 33, note_motivos 13, tipos_comprobante 16, systems 6, system_categories 7,
    manual_recursos 3, system_modules 6, system_features 6, system_media 5, plans 12.
- **Mecanismo: `pg_dump -Fc` + `pg_restore`, no migraciones nuevas escritas a mano** — varias de
  estas tablas ya acumularon migraciones correctivas (`fix_tax_configs_rebuild_schema`, etc.);
  dump/restore trae el esquema real tal cual está en Postgres hoy (índices, constraints,
  secuencias), sin arriesgar una reconstrucción manual desincronizada.
  1. Backup completo de `sv_facturacion` como red de seguridad adicional (antes de tocar nada).
  2. `pg_dump` puntual de las 13 tablas (schema+data, formato custom).
  3. `pg_restore` contra `db_tenant_central` (la base que ya existía desde Fase 0).
  4. **Bug real encontrado en el restore**: un trigger (`set_timestamp_detraction_codes` en
     `detraction_codes`) depende de una función Postgres (`update_timestamp_detraction_codes()`)
     definida directo en la base, no vía migración Laravel — `pg_dump -t` no arrastra funciones
     sueltas, solo la tabla que las referencia. El restore de las 13 tablas terminó bien igual
     (`pg_restore` ignora el error puntual y sigue), pero el trigger quedó faltante — detectado
     verificando `information_schema.triggers` después del restore, no asumido. Recreados
     función + trigger manualmente en `db_tenant_central`, verificado que ya está.
  5. Verificación de conteos: las 13 tablas en `db_tenant_central` coinciden exacto con los
     números de arriba.
  6. `ALTER TABLE products DROP CONSTRAINT fk_producto_detraccion` en `sv_facturacion`.
  7. `DROP TABLE` de las 13 en `sv_facturacion`, en orden que respeta dependencias (hijos antes
     que padres: `domains`, `manual_recursos`, `system_modules`, `system_features`,
     `system_media`, `plans`, `systems`, `system_categories`, `tenants`, luego las 4 sin
     dependientes) — sin `CASCADE`, para que fallara ruidoso si el orden estaba mal. Las 13
     cayeron sin error, confirmando el mapeo de dependencias.
  8. `config/database.php`: `central.database` pasa de `'sv_facturacion'` a
     `'db_tenant_central'`; la clave `db_tenant_central` se eliminó (redundante). Los 4 modelos
     de Fase 0 (`CentralUser`/`CentralRole`/`PlatformSetting`/`CentralAuditLog`) volvieron a
     `protected $connection = 'central'`. Los 5 archivos de migración de Fase 0 y sus
     comentarios (que explicaban por qué NO se tocaba `central`) actualizados para reflejar la
     consolidación.
- **Verificado end-to-end, no solo por conteo de filas**: los 7 modelos con `CentralConnection`
  (`TaxConfig`, `DetractionCode`, `NoteMotivo`, `TipoComprobante`, `System`, `SystemCategory`,
  `ManualRecurso`) resuelven igual que antes; los 3 modelos del panel (`CentralUser`,
  `CentralRole`, `CentralAuditLog`) también — `central_audit_logs` conserva las 8 filas de la
  suite de pruebas de B.3, nada se perdió en la migración. `sv_facturacion` quedó con sus 30
  tablas legacy intactas (companies/products/users/sales/etc., pre-multitenancy), sin ninguna de
  las 13 migradas. Repetido el smoke test de emisión real de B.1 contra `sandbox`
  (`GreenterService::getSee()` con `SunatConfig` real) — funciona igual.
- **Verificación adicional del trigger, pedida aparte después de cerrar la fase** — 3 preguntas,
  las 3 confirmadas con evidencia real, no por lectura de código:
  1. **¿El trigger recreado funciona de verdad?** Sí — `UPDATE` real sobre una fila de
     `detraction_codes` (`codigo='001'`), reescribiendo el mismo valor de `nombre`:
     `updated_at` pasó de `2026-07-08 08:44:09` (fecha vieja, previa a la migración) a
     `2026-07-20 16:07:06` (momento exacto del update). El trigger corre, no solo "existe"
     en `information_schema`.
  2. **¿Era exactamente un solo error del restore, y solo ese?** Sí — se restauró el mismo
     dump en una base descartable nueva con `-v` (verbose), capturando el log completo sin
     filtrar nada (106 líneas, leídas todas): secuencia ininterrumpida de 13 tablas →
     secuencias → defaults → datos → sequence sets → constraints → el único índice → el
     trigger (único punto que falla) → las 7 FK constraints, todas sin problema → resumen
     final "1 error ignorado". Ningún segundo error ni advertencia en ningún otro punto.
  3. **¿Hay otro objeto "hecho a mano" similar sin documentar, en las otras 12 tablas o en
     el resto de la base?** No — auditado contra el backup completo de `sv_facturacion`
     (restaurado en otra base descartable, las 30 tablas originales, no solo las 13
     migradas): un solo trigger en toda la base (el mismo, `detraction_codes`), y de las 4
     funciones custom en el schema `public`, 3 pertenecen a la extensión estándar
     `pg_stat_statements` (no hechas a mano) y la cuarta es la misma
     `update_timestamp_detraction_codes` ya conocida. 2 vistas, ambas también de
     `pg_stat_statements`. Sin más deuda técnica de este tipo escondida en el proyecto.
     Las 2 bases descartables usadas para esta verificación (`verificacion_restore_temp`,
     `auditoria_objetos_temp`) se dropearon al terminar.
- **Implicación directa para B.2 (Suscripciones), la fase que sigue**: el diseño que se había
  propuesto para `tenant_subscriptions`/`tenant_invoices` (`tenant_id` como string SIN FK real,
  por ser cross-database) queda obsoleto — `tenants` vive ahora en la MISMA base física que
  estas tablas nuevas van a vivir. Revisar si conviene un FK real hacia `tenants.id` antes de
  escribir esas migraciones.

### Fase B.2 — Suscripciones y control de pagos

**Decisiones confirmadas al retomar (post B.0.5):**
- `tenant_subscriptions.estado`: solo `'activa'`/`'cancelada'`. La suspensión por falta de
  pago vive ENTERAMENTE en `tenants.status` (`activo`/`suspendido`/`archivado`) — no se
  duplica el concepto.
- Plantillas de notificación: vistas Blade versionadas en `resources/views/emails/`, no
  tabla `notification_templates`.
- `tenant_id` en las 4 migraciones nuevas: FK real hacia `tenants.id` (posible recién desde
  B.0.5, antes hubiera sido cross-database).

- **B.2.1** ✅ Completada (2026-07-20) — 4 migraciones nuevas + 1 correctiva, todas
  `connection='central'`: `tenant_plans` (catálogo propio, sin relación con la tabla
  `plans` existente del marketplace), `tenant_subscriptions` (FK real a `tenants.id` y
  `tenant_plans.id`, `CHECK` de `dia_corte` 1-31), `tenant_invoices` (FK real a
  `tenant_subscriptions`/`tenants`, `UNIQUE(tenant_subscription_id, periodo)` para
  idempotencia, `sunat_document_id` sin FK — apuntaría a `sales`, que vive en cada base de
  tenant, no en `central`), `tenant_payment_vouchers` (FK real a `tenant_invoices`, `path`
  pensado para el disco `private` de B.2.2). Más la migración que había quedado pendiente
  de antes del desvío a B.0.5: `CHECK (status IN ('activo','suspendido','archivado'))` en
  `tenants` — verificado limpio contra los 4 tenants reales antes de aplicarlo.
- **B.2.2** ✅ Completada (2026-07-20) — `TenantSubscriptionMiddleware` (alias
  `tenant.subscription`), mismo patrón que `EnsureTenantIsActive` pero para
  `status==='suspendido'` (concepto separado de `archivado`, con su propio mensaje).
  Decisiones de alcance sobre dónde aplicarlo (confirmadas explícitamente, no asumidas):
  **sí** en `/auth/*` (login), API principal de tenant, y `/portal/*` (el e-commerce
  público del tenant también se cae con la suspensión — es el negocio completo, no solo
  el panel admin); **no** en las 5 rutas de PDF con URL firmada (`sales-pdf`, `notas-pdf`,
  `payment-receipts-pdf`, `cash-sessions-pdf*`) — son documentos ya emitidos, no "acceso
  nuevo". Sin jobs en cola que abortar (el envío a SUNAT es síncrono en este proyecto, sin
  worker — bloquear el request nuevo ya cumple la regla de negocio del plan original.
  **Verificado con una prueba real de suspensión/reactivación** contra `sandbox`: primera
  corrida con una instancia de `Tenant` en memoria dio un falso "pasa" porque no reflejaba
  el `UPDATE` directo por SQL (bug de la prueba, no del middleware); repetida con una
  instancia fresca (`Tenant::find()` después del `UPDATE`, igual que resuelve cada request
  HTTP real vía `InitializeTenancyBySubdomain`) — bloqueó con 403 en `suspendido`, pasó
  normal al revertir a `activo`. `sandbox` quedó `activo` al final.
- **B.2.3** ✅ Completada (2026-07-20) — 4 modelos Eloquent nuevos (`TenantPlan`,
  `TenantSubscription`, `TenantInvoice`, `TenantPaymentVoucher`, ninguno existía todavía,
  solo las migraciones) + `TenantInvoiceService` (único punto de verdad:
  `generarParaPeriodo()` idempotente por `(tenant_subscription_id, periodo)`,
  `generarMensualParaActivas()` para el cron — filtra `estado='activa'` +
  `facturacion_automatica=true`, sin mirar `tenants.status`, gap documentado: un tenant
  archivado debería cancelar su suscripción aparte, no automatizado todavía).
  `folio_interno` determinístico (`INV-{subscription_id}-{periodo sin guión}`, sin
  contador separado). `fecha_vencimiento` calculada desde `dia_corte` con clamp al último
  día real del mes. `PlatformSettingSeeder` nuevo — `dia_corte_default`/
  `dias_gracia_suspension_default` sembrados (28 y 60, la tabla llevaba desde Fase 0
  vacía). `TenantSubscriptionController::generarInvoice()` (endpoint manual, sin
  `$tenant->run()` — a diferencia de `TenantSunatController`, estas tablas viven en
  `central` directo, no en la BD del tenant) + `tenants:generate-monthly-invoices`
  (`--periodo=`, default mes actual), ambos llamando al mismo servicio. Programado en
  `routes/console.php` con `->daily()` (no "el día del corte" — la idempotencia tolera que
  el cron se caiga justo el día 1 sin perder el período). **Verificado de punta a punta
  contra `sandbox`**: plan+suscripción de prueba reales, invoice generado por el endpoint
  con monto/fecha_vencimiento correctos, segunda llamada idéntica devolvió el mismo
  invoice (1 sola fila en BD, confirmado por conteo directo), `AuditLogger` con
  `tenant.invoice.generated`; comando corrido dos veces para un período nuevo — "1
  nuevo/0 existía" la primera, "0 nuevo/1 existía" la segunda, 1 sola fila real en BD.
  Suscripción/plan/invoices de prueba quedaron en `sandbox` a propósito — hacen falta para
  probar B.2.4/B.2.5.
- **B.2.4** ✅ Completada (2026-07-20) — `tenants:check-overdue-payments`, mismo criterio
  que B.2.3: comando delgado sobre `TenantOverduePaymentService` (único punto de verdad).
  Pipeline por invoice vencido (`estado != 'pagado'` y `fecha_vencimiento <= hoy`, tenant
  no archivado): **checkpoint 1** (recordatorio) al transicionar `estado`
  `pendiente`→`vencido`; **checkpoint 2** (aviso a mitad de gracia) al cruzar
  `intdiv(dias_gracia, 2)` días vencido; **checkpoint 3** (suspensión) al cruzar
  `dias_gracia` completo, transicionando `tenants.status` `activo`→`suspendido`.
  `dias_gracia` = `tenant_subscriptions.dias_gracia_suspension` si no es null, si no
  `platform_settings.dias_gracia_suspension_default` (60).
  - **Idempotencia, decisión de diseño explícita**: los checkpoints 1 y 3 se apoyan en su
    propia transición de estado (una corrida repetida ya los encuentra en el estado nuevo
    y no repite nada) — el checkpoint 2 no tiene transición de estado propia (el invoice
    sigue `vencido` toda la ventana de gracia), así que necesitó una columna nueva
    dedicada: `tenant_invoices.aviso_gracia_enviado_at` (migración
    `add_aviso_gracia_to_tenant_invoices_table`).
  - **Primer Mailable del proyecto** (no existía ninguno hasta esta fase): 3 clases en
    `app/Mail/` (`InvoiceOverdueReminderMail`, `InvoiceGraceMidpointWarningMail`,
    `TenantSuspendedForNonPaymentMail`) + vistas Blade en `resources/views/emails/`, tal
    como confirmaron las "Decisiones confirmadas al retomar" de esta fase (plantillas
    Blade versionadas, no tabla `notification_templates`). `MAIL_MAILER=log` en este
    entorno — los correos se escriben en `storage/logs/laravel.log`, no se entregan de
    verdad todavía (fuera de alcance: configurar SMTP real).
  - **Un fallo de notificación nunca bloquea la transición de estado real**: el email de
    contacto se resuelve dentro de `$tenant->run()` (`Company::first()->email`, vive en la
    BD propia del tenant, no en `central` — mismo mecanismo ya usado por
    `TenantSunatController`, con el mismo cuidado de no lanzar excepciones dentro del
    closure) y el envío en sí va en un `try/catch` que solo reporta (`report($e)`) — la
    fila de `tenant_invoices`/`tenants` ya se persiste antes de intentar notificar.
  - **Bug real encontrado y corregido durante la verificación, no antes**: Carbon 3.x
    (versión instalada, `nesbot/carbon` 3.10.3) cambió el default de `diffInDays()` a
    valores CON signo según dirección cronológica — a diferencia de Carbon 2, que siempre
    devolvía absoluto. Sin `absolute: true` explícito, `fecha_vencimiento` en el pasado
    devolvía días vencidos NEGATIVOS y ningún checkpoint de gracia/suspensión llegaba a
    dispararse nunca (silencioso — el comando corría sin error, solo nunca avanzaba más
    allá del recordatorio). Detectado en la propia verificación contra `sandbox` (el
    checkpoint 2 no disparaba con un invoice de prueba a 40 días vencido, con
    `dias_gracia` efectivo de 60 — matemáticamente debía disparar), corregido antes de
    dar la fase por cerrada.
  - **Verificado con evidencia real contra `sandbox`** (usando el invoice real de prueba
    de B.2.3 más 2 invoices sintéticos, todos limpiados al final — `sandbox` quedó con el
    mismo invoice real de B.2.3, ahora genuinamente en `vencido` con recordatorio enviado,
    que es el resultado esperado y útil para probar B.2.5 según ya anotaba B.2.3): email de
    contacto seteado temporalmente en `Company::email` de `sandbox` para poder confirmar
    entrega real en el log; recordatorio disparado una vez y NO repetido en una segunda
    corrida inmediata (idempotencia checkpoint 1); aviso de mitad de gracia disparado
    exactamente una vez con un invoice a 40 días vencido (grace=60, punto medio=30) y NO
    repetido en corridas siguientes (idempotencia checkpoint 2, columna
    `aviso_gracia_enviado_at`); suspensión disparada con un invoice a 70 días vencido
    (`tenants.status` `activo`→`suspendido`) y NO repetida (idempotencia checkpoint 3); los
    3 `central_audit_logs` (`tenant.invoice.overdue_reminder_sent`,
    `tenant.invoice.grace_midpoint_notified`, `tenant.subscription.suspended_for_nonpayment`)
    confirmados con el payload correcto; las 4 notificaciones confirmadas en
    `storage/logs/laravel.log` con destinatario y asunto correctos. Estado final de
    `sandbox`: `tenants.status='activo'` (reactivado tras la prueba), `Company.email=null`
    (revertido), invoices/audit-logs sintéticos borrados.
  - Programado en `routes/console.php` con `->daily()`, después de
    `tenants:generate-monthly-invoices` — mismo mecanismo (Scheduler + cron del sistema,
    sin worker de colas).
- **B.2.5** ✅ Completada (2026-07-20) — endpoints de gestión manual, todos bajo
  `TenantSubscriptionController` (`TenantSubscriptionManagementService` como único punto de
  verdad, mismo criterio que el resto de Fase B.2): `POST tenants/{id}/invoices/{invoiceId}/
  mark-paid`, `POST .../vouchers` (subida, multipart), `POST .../vouchers/{voucherId}/verify`,
  `POST .../vouchers/{voucherId}/reject` (exige `motivo`), `POST tenants/{id}/suspend` (exige
  `motivo`), `POST tenants/{id}/reactivate`, `POST tenants/{id}/subscription/toggle-automatic`.
  - **Decisión de negocio confirmada explícitamente antes de construir**: si un tenant
    suspendido paga (manual o por voucher verificado) y ya no le queda NINGÚN invoice
    vencido sin pagar, se reactiva solo (`intentarReactivarPorPago()`) — sin esperar una
    acción manual aparte. Si todavía queda otro invoice vencido (deuda parcial), sigue
    suspendido. Verificado con 3 pagos escalonados sobre 3 invoices vencidos distintos del
    mismo tenant: los primeros 2 pagos no reactivan (queda deuda), el 3ro sí, con
    `tenant.subscription.reactivated_automatically` en el audit log.
  - **Verificar un voucher ES la confirmación de pago** — dispara el mismo
    `marcarPagado()` (con su reactivación automática) que el endpoint manual, no un camino
    aparte. Rechazar un voucher NO toca el invoice, solo el voucher (`estado='rechazado'` +
    `motivo_rechazo`, columna nueva — sin ella el motivo solo hubiera quedado en
    `central_audit_logs`, no visible en el propio recurso para una futura UI).
  - **`TenantContactMailer` extraído** de `TenantOverduePaymentService` (B.2.4) al
    necesitarse de nuevo acá — mismo criterio "un solo punto de verdad" ya usado en el
    resto del proyecto. 2 Mailables nuevos: `TenantReactivatedMail` (usado tanto por
    reactivación manual como automática) y `TenantSuspendedManuallyMail` (distinta de
    `TenantSuspendedForNonPaymentMail` de B.2.4 — motivo libre, acción de un
    `central_user`, no del cron).
  - **Voucher: disco `private` SIN `$tenant->run()`** — a diferencia del certificado SUNAT
    (B.3, que si necesita partición por tenant), `tenant_payment_vouchers` es un recurso
    central (billing de la plataforma), así que el archivo va a la carpeta central
    compartida (`storage/app/private/billing/vouchers/`), no a la partición de ningún
    tenant — confirmado que el panel corre fuera de la resolución de tenancy
    (`tenancy()->initialized` false ahí), así que `Storage::disk('private')` sin wrapping
    ya resuelve a la raíz central sin necesitar código adicional.
  - **Bug real encontrado y corregido, no relacionado al código nuevo de esta fase pero
    bloqueante para verificarla**: `CentralUser` (`app/Models/Central/CentralUser.php`)
    extendía `Illuminate\Database\Eloquent\Model` en vez de `Authenticatable` (a diferencia
    de `App\Models\User`, que sí lo hace) — `POST /api/central/auth/login` tiraba 500 para
    CUALQUIER credencial, válida o no (`JWTGuard::hasValidCredentials()` exige un
    `Authenticatable` real). El login central nunca había funcionado vía HTTP real desde
    que existe el panel — B.2.1 a B.2.4 se habían verificado con tinker (manipulación
    directa de modelos), nunca con el flujo de login real, así que nadie lo había notado.
    Corregido con el mismo patrón ya probado en `User.php`
    (`use Illuminate\Foundation\Auth\User as Authenticatable`). Sin este fix, B.2.5 no se
    podía verificar de punta a punta vía HTTP real (necesario para probar la subida de
    voucher multipart) — se arregló antes de continuar, no se pospuso.
  - **7 puntos de verificación, todos vía HTTP real** (login central real + JWT, contra
    `sandbox`, después del fix de arriba): mark-paid exitoso + guard 422 al repetir + guard
    404 cruzando tenant equivocado; subir voucher con archivo inválido (mime real, no solo
    extensión) → 422; subir voucher válido → 201, archivo confirmado en
    `storage/app/private/billing/vouchers/` (ruta central, sin sufijo de tenant); verificar
    voucher → invoice pasa a `pagado` automáticamente; rechazar voucher sin `motivo` → 422,
    con `motivo` → `rechazado` + `motivo_rechazo` guardado, invoice sin tocar; suspender
    manual → 200 + mail logueado, repetir → 422; reactivación automática escalonada (arriba);
    reactivar ya-activo → 422; toggle automático ida y vuelta (confirmado que
    `facturacion_automatica` volvió a su valor original, sin efecto colateral permanente).
    Todos los invoices/vouchers/audit-logs sintéticos de la prueba se borraron al final
    (incluidos los archivos físicos del disco `private`); el invoice real de B.2.3/B.2.4
    quedó genuinamente `pagado` (uso esperado, documentado en B.2.4 como el motivo de
    dejarlo `vencido`); `sandbox` terminó `status='activo'`, `Company.email=null`.
- **B.2.6** (UI de facturación) y Fase D completa — pospuestas por decisión (2026-07-20). B.2.6 depende de que exista un frontend del panel (Fase D), que todavía no se construyó. En vez de armar un scaffold mínimo ahora, se decidió seguir agotando el backend (Fase C, 100% verificable por HTTP/comando igual que todo lo anterior) y diseñar el frontend completo de una sola vez más adelante, evitando construir una base parcial que luego haya que rehacer. Todos los endpoints de B.2 siguen operables vía curl/Postman mientras tanto.

### Fase C — Backups
- **C.1** ✅ Completada (2026-07-20) — backup manual on-demand por tenant, `pg_dump -Fc`
  individual (nunca `pg_dumpall` — un backup gigante de toda la instancia no sirve para
  restaurar un solo tenant, y expondría datos de todos los negocios en un solo archivo).
  `TenantBackupService` (único punto de verdad) + `TenantBackupController`
  (`GET`/`POST tenants/{id}/backups`, dentro de `auth:central`+`central.token`). Corre
  síncrono dentro del propio request (mismo criterio que el resto — sin worker de colas en
  este entorno).
  - `tenant_backups` (migración nueva, `connection=central`): `tenant_id` (FK real),
    `path` (disco `private`, sin partición por tenant — mismo criterio que
    `tenant_payment_vouchers` de B.2.5, recurso de operación de plataforma, no dato de
    negocio del tenant), `size_bytes`, `tipo` (`manual`/`automatico`), `estado`
    (`en_proceso`/`completado`/`fallido`), `error_message`.
  - `platform_settings.pg_dump_path` nuevo — ruta al ejecutable `pg_dump`, específica de
    cada entorno/servidor, nunca hardcodeada (mismo principio "configuración como datos"
    del plan). Default portable `'pg_dump'` (asume PATH) en el seeder; el valor real de
    este entorno de desarrollo (`C:\Program Files\PostgreSQL\17\bin\pg_dump.exe`, Windows/
    XAMPP) se configuró directo en la BD, no en el seeder — mismo criterio que las
    credenciales SUNAT de sandbox/umbo en B.1 (dato de entorno real, no un default
    versionable).
  - **Bug real encontrado y corregido, no hipotético — diagnosticado con evidencia,
    no asumido**: bajo el SAPI del servidor embebido (`php artisan serve`), `$_SERVER` no
    trae `SystemRoot` (sí lo trae bajo CLI/tinker) — `Symfony\Process::getDefaultEnv()`
    solo hereda al proceso hijo las variables presentes en `getenv() ∩ $_SERVER`, así que
    `pg_dump.exe` quedaba sin `SystemRoot` y Winsock fallaba resolviendo "localhost"
    ("Non-recoverable failure in name resolution") — 100% reproducible vía HTTP real,
    100% invisible vía tinker (por eso la primera verificación con la misma llamada
    literal funcionó en tinker y falló por HTTP). Diagnosticado volcando
    `bin2hex($result->errorOutput())` a un archivo temporal (el mensaje real venía en la
    codepage de consola de Windows, no en UTF-8, ver punto siguiente) — no se asumió la
    causa, se leyó el byte real. Corregido pasando `SystemRoot` explícito en
    `Process::env()`.
  - **Segundo bug real, encontrado por el primero**: el mensaje de error de `pg_dump.exe`
    en Windows viene en la codepage de consola del sistema, no en UTF-8 (`«localhost»`
    con comillas angulares = byte `0xAB`, inválido en UTF-8) — guardar ese texto crudo en
    `tenant_backups.error_message` (columna Postgres, exige UTF-8 válido) hacía fallar el
    propio `UPDATE` de registro del fallo con una `PDOException` de encoding, escondiendo
    el error real de pg_dump detrás de un error distinto. Corregido con
    `iconv('UTF-8','UTF-8//IGNORE', ...)` antes de persistir — descarta bytes inválidos en
    vez de asumir una codepage de origen concreta (más robusto que asumir Windows-1252,
    ya que la codepage real depende de la configuración regional del servidor). Ambos
    bugs documentados como memoria de proyecto — riesgo genérico para cualquier
    `Process::run()` futuro invocado desde un request HTTP en este entorno Windows, no
    exclusivo de backups.
  - **Verificado con evidencia real contra `sandbox`**: backup real completado (`HTTP
    201`, 153.361 bytes) — archivo confirmado en
    `storage/app/private/backups/sandbox/`, validado como dump Postgres real y
    restaurable con `pg_restore --list` (367 entradas de TOC, `dbname: tenantsandbox`,
    `Format: CUSTOM`) — no solo el código de estado HTTP; listado (`GET`) confirmado;
    `central_audit_logs` con `tenant.backup.created`/`central_user_id=1` poblado (mismo
    patrón de verificación que B.2.5). Camino de fallo probado aparte (con
    `pg_dump_path` roto a propósito): `HTTP 500` con mensaje real y legible ("El sistema
    no puede encontrar la ruta especificada."), fila `estado='fallido'` con
    `error_message` correcto, `tenant.backup.failed` en el audit log. `pg_dump_path`
    restaurado al valor real después de la prueba; todos los backups/audit-logs
    sintéticos de la verificación borrados salvo uno (id=6, dejado a propósito como
    fixture real — hace falta un backup real para probar restauración en C.3).
- **C.2** ✅ Completada (2026-07-20) — backup automático programado. `TenantBackupService`
  refactorizado: lógica de dump compartida (`ejecutarDump()`, privado) entre `crearManual()`
  (C.1, `tipo=manual`) y `generarAutomaticoParaTodos()` (nuevo, `tipo=automatico`) — mismo
  criterio "un solo punto de verdad" del resto del proyecto. Comando delgado
  `tenants:run-automatic-backups`, programado `->dailyAt('02:00')` (horario de baja
  actividad, no medianoche exacta — evita competir con el corte de
  `tenants:generate-monthly-invoices`/`tenants:check-overdue-payments`, que si corren a
  medianoche vía `->daily()`).
  - **Alcance: todos los tenants no archivados**, decisión explícita (no pedida
    literalmente por el plan, pero consistente con su espíritu): a diferencia de
    `TenantInvoiceService::generarMensualParaActivas()` (que sí filtra por
    `facturacion_automatica`), backup es una preocupación de continuidad de datos, no de
    facturación — no filtra por esa bandera, y tampoco excluye tenants `suspendido` (un
    tenant suspendido por falta de pago igual necesita sus datos respaldados). Solo excluye
    `archivado`.
  - **Idempotente**: un tenant con un backup automático `completado` creado HOY no genera
    uno nuevo (tolera reintentos/corridas dobles del cron, mismo criterio que
    `TenantInvoiceService`/`TenantOverduePaymentService`). Un fallo de UN tenant nunca
    frena el resto del lote (`try/catch` por tenant dentro del loop).
  - **Retención**: `platform_settings.dias_retencion_backups_default` (30 días, seed
    nuevo) — poda backups `tipo=automatico` más viejos que el umbral (fila + archivo)
    después de cada corrida, por tenant. **Nunca poda `tipo=manual`** — decisión explícita:
    un backup manual es una acción deliberada de un `central_user`, no algo que un cron
    deba borrar en silencio. `tenant.backup.pruned` en el audit log usa `auditable_type=
    Tenant` (no `TenantBackup`) porque poda varias filas a la vez, sin un backup puntual al
    que referenciar.
  - **Notificación de fallo**: `TenantBackupFailedMail` nuevo, a todos los `central_users`
    (equipo de operación de la plataforma — un backup fallido no es algo que el tenant
    pueda resolver). Un email por tenant fallido, no un resumen consolidado — mismo motivo
    que el resto del proyecto favorece checkpoints granulares y auditables sobre un solo
    mensaje agregado.
  - **Verificado con evidencia real** (CLI, `sandbox`/`umbo`/`negocio2` reales —
    `umbo-archivado` correctamente excluido): corrida real → 3 backups nuevos completados
    (93KB/153KB/168KB, tamaños distintos y plausibles por tenant); reintento mismo día → 0
    nuevos, 3 "ya existía"; retención probada con un backup automático sintético
    backdateado 40 días → podado (fila + archivo confirmados borrados), el de hoy
    intacto, `tenant.backup.pruned` con el id correcto en el payload; fallo probado
    limpiando los backups de hoy + rompiendo `pg_dump_path` a propósito → 3 fallos
    reales, el lote completo igual procesó los 3 tenants (no se cortó en el primero), 3
    emails reales confirmados en el log (asunto + destinatario correctos, uno por
    tenant), 3 filas `fallido` con mensaje legible, 3 `tenant.backup.failed` en
    audit log. `pg_dump_path` restaurado, filas/audit-logs sintéticos de la prueba de
    fallo borrados; estado final real y limpio: 1 backup manual (fixture de C.1) + 3
    backups automáticos (uno por tenant no archivado).
- **C.3** ✅ Completada (2026-07-21) — restauración, con fricción intencional por diseño
  explícito (no un descuido): confirmación en 2 pasos (nunca un solo POST), backup de
  seguridad obligatorio que GATEA la restauración real, y auditoría en cada checkpoint.
  - **Diagnóstico previo, con evidencia real antes de escribir código** (pedido explícito
    del usuario — no arrancar por la opción más simple sin comparar): `DROP DATABASE` +
    `CREATE DATABASE` + restore descartado — la ventana entre el `DROP` y un restore
    exitoso deja la base irrecuperable si algo falla a mitad de camino (el único camino de
    vuelta sería re-restaurar desde el backup de seguridad, con su propio riesgo de fallo).
    Elegido en cambio: `pg_restore --clean --if-exists --single-transaction` **in-place**
    sobre la base existente — Postgres soporta DDL transaccional real, así que TODO el
    restore corre en un único `BEGIN`/`COMMIT`; un fallo en cualquier punto revierte
    absolutamente todo, sin dejar la base en un estado intermedio. Nunca se toca la base
    física (sin `DROP`/`CREATE DATABASE`), así que tampoco hace falta reconstruir
    encoding/collation/owner del original.
  - **2 pruebas reales de atomicidad contra Postgres 17.7 de este entorno, antes de
    escribir el servicio** (no asumidas, no solo documentación oficial citada):
    1. Fallo real de constraint SQL a mitad de una secuencia de 363 entradas TOC (tabla
       extra con FK real hacia una de las tablas del dump, forzando que `DROP CONSTRAINT`
       falle después de que varias tablas anteriores ya se hubieran restaurado dentro de
       la misma transacción) — las 45 tablas (44 originales + la bloqueadora) quedaron con
       exactamente los mismos conteos que el baseline, conexión sana después.
    2. `kill -9` del proceso `pg_restore` real (no `pg_terminate_backend`, el proceso
       cliente) — 4 corridas, 2 de ellas atrapadas exactamente en estado `idle in
       transaction` (el escenario más preocupante: backend con transacción abierta y
       cliente muerto). Las 4 dejaron `pg_stat_activity`/`pg_locks` en cero filas
       inmediatamente — Postgres detecta el cierre del socket a nivel de SO al instante en
       una conexión local (loopback), sin depender de `tcp_keepalives`/
       `idle_in_transaction_session_timeout` (confirmado que ambos están en `0`/`-1` —
       sin red de seguridad de servidor — en este entorno). **Límite explícito de la
       prueba, reconocido sin maquillar**: esto prueba muerte de *proceso*, no muerte de
       *red* (host caído, partición) — ese escenario silencioso sigue sin ejercitarse. Por
       eso el restore real fija `PGOPTIONS="-c idle_in_transaction_session_timeout=..."`
       (5 min) por conexión, como defensa en profundidad para el caso no probado.
  - **Flujo de 2 pasos**: `POST tenants/{id}/backups/{backupId}/restore-preview` — valida
    que el backup es de ese tenant, está `completado`, el archivo existe, y corre
    `pg_restore --list` (sin tocar ninguna base) para confirmar que el dump es legible —
    atrapa corrupción antes de comprometerse a nada. Genera `tenant_restores` con
    `estado=pendiente_confirmacion` + `confirm_token` (40 chars, único) + expiración (10
    min). `POST tenants/{id}/restores/{confirmToken}/confirm` — revalida todo (TOCTOU: el
    archivo pudo desaparecer entre preview y confirm), y recién ahí ejecuta.
  - **Secuencia real de `confirmarYEjecutar()`**: suspende el tenant (reusa
    `TenantSubscriptionMiddleware` de B.2.2, no un "modo mantenimiento" aparte — necesario
    porque `-1` sostiene locks exclusivos toda la transacción, cualquier request
    concurrente se colgaría esperando el lock) → termina conexiones sueltas
    (`pg_terminate_backend` sobre `pg_stat_activity` filtrado por `datname`) → **backup de
    seguridad obligatorio** (`TenantBackupService::crearPreRestore()`, mismo método
    compartido de C.1/C.2 con sus fixes de `SystemRoot`/encoding ya incluidos — si esto
    lanza, la restauración real NUNCA arranca) → `pg_restore --clean --if-exists -1` real
    → `finally`: el tenant vuelve exactamente al status que tenía antes de empezar (nunca
    lo reactiva si ya estaba `suspendido` por falta de pago).
  - **`tenant_backups.tipo` ampliado** a `pre_restore` (migración que altera el CHECK
    constraint existente) — nunca lo poda la retención de C.2 (que solo filtra
    `tipo=automatico`), sin cambios adicionales necesarios ahí.
  - **`tenant_restores` como recurso propio**, no mezclado con `tenant_backups` (que son
    artefactos, no operaciones) — mismo criterio "un recurso, una tabla" del resto del
    proyecto.
  - **Auditoría en los 3 checkpoints pedidos explícitamente**: `tenant.restore.preview_requested`,
    `tenant.restore.confirmed`, `tenant.restore.completed`/`tenant.restore.failed` (este
    último con `fase` en el payload — `backup_de_seguridad` o `restore` — para saber DÓNDE
    falló sin ambigüedad).
  - **Verificado con evidencia real de punta a punta contra `sandbox`** (HTTP real, login
    central real): guards — backup de otro tenant/inexistente → 404; backup no
    `completado` → 422; restauración concurrente ya en curso → 422; token inválido → 404;
    reintentar el mismo token ya usado → 422. **3 escenarios de ejecución real**: (1)
    backup de seguridad roto a propósito (`pg_dump_path` inválido) → restauración real
    NUNCA corrió, `pre_restore_backup_id` quedó `null`, tenant vuelto a `activo`; (2) backup
    de seguridad exitoso pero el `pg_restore` real fallando por una tabla bloqueadora con
    FK real agregada a la base física de `tenantsandbox` (mismo truco que la prueba de
    atomicidad, pero contra el tenant real, no una base descartable) → **los datos reales
    de `tenantsandbox` quedaron exactamente intactos** (`cash_movements`/`sales` con los
    mismos conteos de antes), `pre_restore_backup_id` sí poblado (ese paso había
    completado antes del fallo); (3) restauración real completa y exitosa (`HTTP 200`,
    backup de seguridad id generado, tenant vuelto a `activo`). Los 3 escenarios
    confirmados con audit logs reales (`central_user_id` poblado en los 9 registros — 3
    checkpoints × 3 escenarios). Datos sintéticos de las pruebas de fallo borrados al
    terminar; quedó 1 restauración real completada como fixture (igual criterio que el
    resto de Fase C).
- **C.4** ✅ Completada (2026-07-21) — verificación de integridad, deliberadamente NO
  absorbida del todo por el preview de C.3 (decisión explícita del usuario, corrigiendo mi
  propuesta original): el preview de C.3 solo detecta corrupción en el momento de querer
  restaurar — un backup corrupto al crearse (disco lleno durante `pg_dump`, corte de luz a
  mitad de camino) podía pasar meses sin que nadie lo note. Movida al momento de
  **creación** del backup, con re-chequeo bajo demanda como complemento, no reemplazo.
  - **`TenantBackupService::verificarIntegridad()`** (único punto de verdad, extraído del
    chequeo que antes vivía inline en `TenantRestoreService::crearPreview()`): corre
    `pg_restore --list` contra el archivo (nunca toca ninguna base), guarda
    `tenant_backups.integridad_verificada` (bool, `NULL` = nunca chequeado, distinto de
    `false` = chequeado y corrupto — relevante para el futuro dashboard de Fase D) +
    `verificado_at`.
  - **Automático al crear** (`ejecutarDump()`, C.1/C.2): corre inmediatamente después de
    marcar `estado='completado'`. Si `pg_dump` "tuvo éxito" (exit 0) pero el archivo
    resultante no pasa la verificación, el backup completo se trata como fallido — mismo
    camino de error que un `pg_dump` que falla de entrada (borra el archivo, `estado`
    pasa a `fallido`, mismo `error_message`/audit log/notificación de C.2 vía
    `TenantBackupFailedMail`, con `fase=verificacion_integridad` en el payload para
    distinguirlo de un fallo de `pg_dump` en sí). Nunca queda un backup corrupto
    mostrando `estado='completado'`.
  - **`POST tenants/{id}/backups/{backupId}/verify`** — re-chequeo bajo demanda,
    `TenantBackupService::reverificar()` (guard: solo sobre backups `completado`, 422 si
    no). A diferencia del chequeo automático de creación, **no** borra el archivo ni
    fuerza `estado='fallido'` solo por encontrar corrupción — es una consulta explícita de
    un `central_user`, que decide qué hacer con el hallazgo (el archivo corrupto puede
    servir para inspección forense). Resuelve el caso que el chequeo automático solo no
    cubre: backups anteriores a esta fase (backfill corrido sobre los 5 backups reales
    existentes, los 5 `integridad_verificada=true`) y decaimiento del disco después de
    creado (bit rot).
  - **`TenantRestoreService::crearPreview()` simplificado**: ya no duplica la llamada a
    `pg_restore --list` — llama a `verificarIntegridad()` compartido, que además de
    validar en el momento (el archivo pudo corromperse en disco DESPUÉS de creado, sigue
    revalidando siempre, nunca confía ciegamente en el valor guardado) deja
    `verificado_at` fresco como efecto secundario de cada intento de restauración.
  - **Verificado con evidencia real contra `sandbox`**: backup nuevo creado vía HTTP →
    `integridad_verificada=true`/`verificado_at` poblados automáticamente sin llamada
    aparte; corrupción forzada en el momento de crear (`pg_restore_path` roto a
    propósito, `pg_dump` corre bien) → backup terminó `fallido`, `path=null`, sin archivo
    huérfano en disco, audit log con `fase=verificacion_integridad`; re-verify bajo
    demanda sobre un backup sano → `200`, `verificado_at` actualizado; re-verify sobre un
    backup sintético genuinamente corrupto (dump truncado) → `integridad_verificada=false`
    correctamente detectado, `estado` se mantuvo `completado` y el archivo NO se borró
    (tal como diseñado — decisión queda en el humano); guard sobre backup no-`completado`
    → 422; smoke test de `restore-preview` post-refactor confirmado sin regresión. Datos
    sintéticos borrados al terminar; los 5 backups reales existentes quedaron con
    `integridad_verificada=true` real (backfill corrido, no solo sembrado a mano).

### Fase D — UI del panel
- Login central, dashboard de tenants con estado consolidado (activo/suspendido/archivado,
  sunat configurado, último backup, próximo vencimiento), wizard de alta, detalle de tenant,
  vista de `central_audit_logs`
- **Paso 0 (scaffold inicial, 2026-07-21) — CERRADO.** Proyecto Vue propio en
  `central-panel/` (hermano de `api-sistema-fe/`/`admin-start-kit/`), decisión ya tomada de
  no reusar el template Rizz. Solo el mínimo funcional — sin vistas de negocio todavía.
  - **Stack**: Vite + Vue 3 + TypeScript (`npm create vite@latest -- --template vue-ts`,
    misma elección que `admin-start-kit`), Bootstrap 5 vanilla (npm, sin `bootstrap-vue-next`
    ni ningún componente heredado del POS), Vue Router, Pinia, Axios — mismas librerías base
    que `admin-start-kit` para consistencia, pero **cero imports cruzados entre los dos
    proyectos**: `httpClient.ts`/`stores/auth.ts` propios, con claves de `localStorage`
    prefijadas `central_*` para dejar explícito que el token es del guard `central`, no del
    guard `api` de un tenant (la separación real ya la da el origen de navegador distinto,
    esto es solo claridad).
  - **Hallazgo real que cambió el diseño de dominio propuesto originalmente**: el plan
    original asumía necesitar un hostname dedicado tipo `central.sistemafe.test` con entrada
    en `central_domains` (`config/tenancy.php`) para no colisionar con la resolución de
    tenants. Confirmado leyendo `routes/api.php`: `Route::prefix('central')` (línea ~85) NO
    lleva el middleware `tenant`/`tenant.active` — el comentario del propio archivo ya lo
    documentaba ("el panel vive fuera de la resolución de tenancy por diseño"). Consecuencia
    real: el hostname usado para llegar a `/api/central/*` es irrelevante para
    `stancl/tenancy`, porque esa resolución nunca se ejecuta para este grupo de rutas.
    Confirmado además que `config/cors.php` no está publicado — Laravel usa el default del
    framework (`allowed_origins: ['*']` sobre `api/*`), verificado con un preflight `OPTIONS`
    real (`Access-Control-Allow-Origin: *`, method/headers correctos) — **cero cambios de
    backend necesarios** para que `central-panel` (puerto 5174) hable con la API (puerto
    8000) en local. `VITE_API_BASE_URL=http://localhost:8000/api/` en `.env`/`.env.example`,
    sin entrada nueva en el hosts file ni en `central_domains`. Si más adelante se quiere un
    hostname dedicado igual (aislar cookies/localStorage por claridad, no por necesidad
    técnica), queda documentado en el propio `.env.example` qué tocaría (hosts file +
    evaluar `central_domains`) — señalado como pregunta para decidir, no hecho.
  - **Alcance del scaffold**: layout base (`NavBar.vue` + `router-view`), `LoginView.vue`
    (formulario email/password → `POST central/auth/login` real → guarda
    `access_token`/`user` en Pinia+localStorage → redirige a dashboard), `DashboardView.vue`
    (placeholder, muestra usuario/roles de la sesión), guard de ruta (`router/index.ts`,
    `meta.public` en `login`; cualquier otra ruta exige `auth.isAuthenticated()`) +
    interceptor de respuesta 401 en `httpClient.ts` (limpia sesión y redirige a login) —
    cubre tanto "nunca hubo token" como "el token dejó de ser válido a mitad de sesión".
  - **Verificado con evidencia real** (no solo lectura de código): `vue-tsc -b` limpio, sin
    errores. `php artisan serve --host=127.0.0.1 --port=8000` levantado — login real vía
    `curl` contra `POST /api/central/auth/login` con el usuario central real
    (`umbosac@gmail.com`, credenciales de `.env` de `api-sistema-fe`) devolvió `access_token`
    JWT válido + `user` con rol `superadmin`. `npm run dev` (puerto 5174) sirviendo
    `index.html` con el título correcto; los 6 módulos del scaffold (`App.vue`,
    `router/index.ts`, `stores/auth.ts`, `services/httpClient.ts`, `views/DashboardView.vue`,
    `components/NavBar.vue`) transforman sin error 500 de Vite (confirmaría un fallo de
    compilación). **Limitación explícita de esta verificación**: no se pudo clickear el flujo
    de login en un navegador real dentro de esta sesión (sin herramienta de automatización de
    navegador disponible) — lo verificado es que el backend responde correctamente y que el
    bundle del frontend compila y sirve sin errores, no que el clic real en "Ingresar" pinte
    el dashboard. Recomendado: abrir `http://localhost:5174` en un navegador y loguearse a
    mano para confirmar visualmente antes de dar esto por 100% cerrado.
  - **Cómo correrlo en local**: `cd api-sistema-fe && php artisan serve --host=127.0.0.1
    --port=8000` (backend) + `cd central-panel && npm install && npm run dev` (frontend,
    puerto 5174 fijo en `vite.config.ts` para no chocar con el 5173 default de
    `admin-start-kit` si se corren ambos a la vez) → abrir `http://localhost:5174`.
  - **Qué falta explícitamente, para que la próxima sesión arranque directo**: ningún
    listado de tenants todavía (la vista de login/dashboard es 100% placeholder más allá de
    mostrar el usuario logueado) — la próxima sesión debería empezar por la vista de listado
    de tenants (`GET central/tenants`, ya existe en el backend desde Fase A) consumida desde
    `DashboardView.vue` o una vista nueva `TenantListView.vue`. Sin componentes de
    tabla/paginación propios todavía (no hay ningún `simple-datatables`/equivalente
    instalado a propósito — decisión de no traer dependencias de Rizz). Sin manejo de roles/
    permisos en el frontend más allá de mostrarlos (el backend no expone ningún gate de
    permisos central más allá de `auth:central`+`central.token`, confirmado por grep — no
    hay Spatie ni nada equivalente en el guard `central`, así que no hace falta un
    `isPermitedRoute()` como el de `admin-start-kit` por ahora).
- **Paso 1 (listado de tenants, 2026-07-21) — CERRADO.** Primera vista de negocio real,
  consumiendo `GET central/tenants` (backend sin cambios, ya existía desde Fase A).
  - **Shape real de la respuesta, confirmado contra el backend corriendo en local antes de
    armar columnas** (no asumido) — anotado acá para que la sesión de detalle no tenga que
    volver a inspeccionarlo:
    ```json
    {
      "total": 4,
      "paginate": 15,
      "tenants": [
        {
          "id": "sandbox",
          "ruc": "20100000001",
          "razon_social": "Empresa de Pruebas Sandbox S.A.C.",
          "razon_social_comercial": "Sandbox Test",
          "status": "activo",
          "fecha_archivado": null,
          "fecha_alta": "2026-07-15T23:06:12.000000Z",
          "domains": ["sandbox"]
        }
      ]
    }
    ```
    `domains` puede venir vacío (`[]`) — el tenant `umbo-archivado` (ver hallazgo abajo) no
    tiene ninguno. **La paginación del backend solo expone `total`/`paginate` (tamaño fijo de
    página), sin `current_page`/`last_page`/links** — con `paginate(15)` y los 4 tenants
    reales de hoy entra todo en una sola página, así que no hizo falta ninguna UI de
    paginación en este paso. Si el conteo de tenants supera 15 en el futuro, el backend
    necesitaría exponer la página actual para poder paginar de verdad desde el frontend —
    anotado, no bloqueante hoy, no tocado (fuera de alcance de esta sesión).
  - **Hallazgo real, no esperado**: la lista real trae **4 tenants**, no 2 — además de
    `sandbox`/`umbo` (los únicos mencionados en las auditorías de Fase E), existen
    `negocio2` (`status: activo`, con dominio propio) y `umbo-archivado` (`status:
    archivado`, `domains: []`, mismo `ruc`/`razon_social` que `umbo` — parece un remanente
    de la migración del negocio real de Umbo, Fase 2 del plan de multi-tenancy). No se
    investigó más a fondo por estar fuera de alcance de esta sesión (solo lectura/listado)
    — el propio listado ya lo hace visible para cuando alguien quiera revisarlo. Las
    auditorías anteriores de Fase E ("los 2 únicos tenants existentes hoy") seguían siendo
    válidas en lo que afirmaban (`sandbox`/`umbo` con `SunatConfig`/certificado demo,
    verificado con tinker sobre esos 2 específicamente) — la corrección es solo sobre el
    conteo total de tenants en la tabla `tenants`, no sobre esa conclusión.
  - **Implementado**: `stores/tenants.ts` (Pinia, `fetchTenants(force?)` con cache básico en
    memoria — no refetch si ya se cargó una vez, salvo `force: true` desde el botón
    "Actualizar"/"Reintentar"), `views/TenantListView.vue` (loading/error/empty states +
    tabla Bootstrap 5 con nombre comercial+id, RUC, dominios, badge de estado por color,
    fecha de alta formateada), `views/TenantDetailView.vue` (placeholder, muestra el `id` de
    la ruta y un link de vuelta al listado), rutas nuevas `tenants`/`tenant-detail` en
    `router/index.ts` (mismo guard de autenticación ya armado en el scaffold — nada nuevo que
    agregar ahí), link "Tenants" en `NavBar.vue`.
  - **No se tocó backend** — el shape de `GET central/tenants` alcanzó tal cual para esta
    vista; la única limitación real (paginación sin página actual) quedó anotada arriba, no
    bloqueó nada con solo 4 tenants.
  - **Verificado con evidencia real**: `vue-tsc -b` limpio. Login real + `GET
    central/tenants` vía `curl` con el usuario central real devolvió los 4 tenants reales
    (shape de arriba, confirmado antes de escribir la interfaz TypeScript). Los 5 módulos
    nuevos/tocados (`stores/tenants.ts`, `views/TenantListView.vue`,
    `views/TenantDetailView.vue`, `router/index.ts`, `components/NavBar.vue`) transforman
    sin error 500 de Vite. **Error state verificado a nivel de red, no visualmente**: se
    detuvo el backend real (identificado y matado tanto el proceso `artisan serve` como su
    hijo real `php -S 127.0.0.1:8000 ... server.php` — `artisan serve` no mata a su propio
    hijo al recibir `Stop-Process`, hallazgo operativo de esta sesión, no de código) y se
    confirmó con `curl` que la conexión se rechaza (`000`, "connection refused") — el mismo
    caso que el interceptor/catch del store ya contempla (`error.response` viene `undefined`,
    cae al mensaje genérico en español). Backend restaurado (instancia única y limpia)
    después de la prueba. **Limitación explícita, igual que en el Paso 0**: no se pudo abrir
    un navegador real para confirmar visualmente el loading breve, la tabla pintada, el
    alert de error, ni el link de detalle navegando — sin herramienta de automatización de
    navegador disponible en esta sesión. Recomendado confirmarlo a mano
    (`http://localhost:5174/tenants`) antes de dar este paso por 100% cerrado.
  - **Qué falta explícitamente para la sesión de detalle**: `TenantDetailView.vue` real con
    tabs de Company/SunatConfig+certificado (`GET/POST tenants/{id}/sunat-config`, `POST
    .../certificado`, ya existen desde Fase B.3), backups (Fase C), suscripción (Fase B.2), y
    el botón "probar emisión" (`POST tenants/{id}/test-emission`, Fase E Paso 2 — shape de
    respuesta ya documentado en esa sección). Ningún dato nuevo que exponer desde el backend
    identificado todavía para el listado en sí.
- **Paso 2 (vista de detalle, 6 tabs, 2026-07-21) — CERRADO.** `TenantDetailView.vue` real
  (reemplaza el placeholder), con `CompanyTab`/`SubscriptionTab`/`BackupsTab`/
  `SunatConfigTab`/`CertificadoTab`/`TestEmissionTab` en `components/tenant-detail/`, orden
  fijo Company → Suscripción → Backups → SunatConfig → Certificado → Test-emission. Flujo
  plan-first (`EnterPlanMode`/`ExitPlanMode`) — plan aprobado antes de escribir código.
  - **2 gaps reales de backend encontrados al auditar shapes antes de armar columnas
    (confirmados con el usuario antes de tocar nada, ambos aditivos y de solo lectura)**:
    1. **No existía `GET tenants/{id}/company`** (solo `POST` upsert-ciego) — sin esto, el
       tab Company no podía saber si un tenant ya tenía datos guardados (`sandbox`/`umbo` sí
       tienen) antes de mostrar el formulario. Cerrado con
       `TenantSunatController::companyShow()`, mismo patrón exacto que
       `sunatConfigShow()` ya existente — `Company::first()` dentro de `$tenant->run()`,
       `{company: {...}|null}`. Ruta `GET tenants/{id}/company` agregada junto a la del
       `POST`.
    2. **`TenantSubscriptionController::show()` no eager-cargaba `TenantInvoice::vouchers()`**
       (la relación ya existía en el modelo, nunca se usaba en este método) — sin esto, el
       tab Suscripción no podía mostrar/verificar vouchers subidos en sesiones anteriores,
       solo el recién subido en la sesión actual. Cerrado agregando `->with('vouchers')` a
       la query de `$invoices` — un solo `with()`, cero cambios de shape en ningún otro
       campo.
  - **Verificado con evidencia real, ambos gaps**: `GET tenants/sandbox/company` → trae la
    Company real de `sandbox` completa; `GET tenants/negocio2/company` → `{company: null}`
    (tenant sin Company). **Ciclo completo de voucher probado en vivo contra `sandbox`** (no
    solo el shape): subida real de un `.png` válido (generado con GD, no un archivo
    falsificado) al invoice #2 (`pendiente`) vía `POST .../invoices/2/vouchers` → confirmado
    que `GET .../subscription` ahora trae `invoices[1].vouchers` con 1 elemento
    (`estado: "pendiente_verificacion"`) → `POST .../vouchers/3/verify` → confirmado que el
    voucher pasa a `"verificado"` Y el invoice #2 pasa de `"pendiente"` a `"pagado"` (side
    effect de `verificarVoucher()` disparando `marcarPagado()` del lado del backend, tal
    como se había reportado en la auditoría) — esto confirma que la decisión de diseño del
    store (re-fetch completo de `subscription` tras `verifyVoucher()`, en vez de parchear el
    voucher en memoria) es necesaria, no solo prudente: sin el re-fetch, el frontend
    mostraría el invoice #2 como `pendiente` after de verificar su voucher, un estado
    incorrecto. **Dato real que quedó en `sandbox`** (no revertido, mismo criterio que otras
    sesiones de verificación de este proyecto): invoice #2 (`INV-1-202608`) quedó
    `estado=pagado` con 1 voucher `verificado` — estado legítimo, no un artefacto a limpiar.
  - **Frontend**: `src/types/tenant-detail.ts` (interfaces 1:1 con los shapes confirmados —
    ver sección de arriba para el detalle completo endpoint por endpoint). `stores/
    tenants.ts` ganó 5 grupos de estado (`subscription`, `backups`, `sunatConfig`,
    `certificado`, `testEmission`), cada uno con su propio `loading`/`error` — las acciones
    de escritura de un mismo grupo comparten un solo `actionLoading`/`actionError` (decisión
    explícita: este panel lo opera un solo admin de a un botón por vez, separar un
    loading/error por cada una de las 8 acciones de Suscripción hubiera sido sobre-diseño
    sin beneficio real). El overview del tenant (`GET tenants/{id}`) y Company siguen sin
    vivir en el store — fetch propio del componente, mismo criterio ya decidido en el Paso 1.
  - **Detalles de implementación que vale la pena recordar**:
    - `CompanyTab`/`SunatConfigTab`: opcionales vacíos se convierten a `null` antes de
      enviar (nunca `''`) — `birth_date` tiene `nullable|date` en el backend, y una fecha
      vacía como string `''` (no `null`) falla esa validación. Encontrado leyendo la regla
      antes de escribir el form, no por un 422 real en pruebas.
    - `SunatConfigTab`: `sol_clave` arranca vacío siempre (decisión ya tomada) — un `watch()`
      sobre `store.sunatConfig.data` re-llena el resto del formulario si `CertificadoTab`
      actualiza el mismo slice del store, pero preserva lo que el usuario ya haya tecleado
      en `sol_clave` (nunca se pisa sola a mitad de edición).
    - `CertificadoTab`: hace su propio `fetchSunatConfig()` al montar (no asume que
      `SunatConfigTab` ya corrió antes) — `TenantDetailView` solo monta el tab activo, así
      que el usuario puede entrar directo a Certificado sin haber visitado SunatConfig.
    - `BackupsTab`: restauración con fricción visual — preview → countdown en vivo de los
      10 minutos de `confirm_token_expires_at` (`setInterval`, limpiado en
      `onBeforeUnmount`) → botón de confirmación deshabilitado si el token ya expiró
      (`remainingSeconds <= 0`), sin depender de que el usuario intente y reciba el 422 del
      backend para enterarse.
    - Ningún modal de Bootstrap con JS imperativo (`bootstrap.Modal`) — las 3 confirmaciones
      que exigen motivo/doble-check (suspender, rechazar voucher, restaurar) usan paneles
      inline (`v-if` sobre un `ref` booleano/id), más simple de razonar en Vue que
      instanciar el plugin JS de Bootstrap a mano.
  - **Verificado**: `vue-tsc -b` limpio tras los 3 archivos de tipos/store y los 7
    componentes nuevos/tocados (`TenantDetailView` + 6 tabs). Los 9 módulos transforman sin
    error 500 de Vite. SPA fallback confirmado (`GET /tenants/sandbox` en el dev server de
    Vite → 200, sirve el shell). **Limitación explícita, igual que Pasos 0/1**: no se pudo
    clickear el flujo completo en un navegador real (sin herramienta de automatización de
    navegador disponible) — lo verificado es la lógica de negocio contra el backend real
    (incluido un ciclo de escritura completo, ver arriba) y que el frontend compila/sirve
    sin errores, no la experiencia visual de los 6 tabs. Recomendado confirmarlo a mano en
    `http://localhost:5174/tenants/sandbox` antes de dar este paso por 100% cerrado.
  - **Fuera de alcance, confirmado explícitamente antes de empezar**: wizard de alta de
    tenant (no tocado), asignar/cambiar plan de suscripción (no hay endpoint), vista de
    `central_audit_logs` (sesión futura), cualquier acción sobre el Tenant central en sí más
    allá de `status` vía suspender/reactivar de suscripción.
- **Paso 3 (vista global de Audit Logs, 2026-07-21) — CERRADO.** Página aparte (no un tab
  de tenant) — todas las acciones sensibles del panel ya quedaban registradas por
  `AuditLogger::log()` desde varias fases anteriores, pero no existía ningún endpoint ni
  vista para leerlas. Flujo plan-first (`EnterPlanMode`/`ExitPlanMode`).
  - **Grep completo de `$this->auditLogger->log(` en toda la app, antes de escribir el
    selector de acciones** (no asumido) — **25 acciones distintas**, más de las que ya se
    conocían de sesiones anteriores:
    `tenant.created`, `tenant.company.updated`, `tenant.sunat_config.updated`,
    `tenant.certificado.uploaded`, `tenant.test_emission`, `tenant.invoice.generated`,
    `tenant.invoice.paid_manually`, `tenant.invoice.voucher_uploaded`,
    `tenant.invoice.voucher_verified`, `tenant.invoice.voucher_rejected`,
    `tenant.invoice.overdue_reminder_sent`, `tenant.invoice.grace_midpoint_notified`,
    `tenant.subscription.suspended_manually`,
    `tenant.subscription.suspended_for_nonpayment`,
    `tenant.subscription.reactivated_manually`,
    `tenant.subscription.reactivated_automatically`,
    `tenant.subscription.automatic_billing_toggled`, `tenant.backup.created`,
    `tenant.backup.failed`, `tenant.backup.integrity_checked`, `tenant.backup.pruned`,
    `tenant.restore.preview_requested`, `tenant.restore.confirmed`,
    `tenant.restore.completed`, `tenant.restore.failed`.
  - **Hallazgo real, menor pero real**: `tenant.invoice.generated` (a diferencia de los
    otros 3 `tenant.invoice.voucher_*`/`paid_manually`, que se auditan contra
    `TenantInvoice::class`) se audita contra `Tenant::class` — inconsistencia preexistente
    en el código, no introducida ni corregida en esta sesión (solo documentada, confirmada
    con evidencia real vía curl: filtrar por `auditable_type=App\Models\Tenant` sobre
    `sandbox` sí trae sus 2 filas de `tenant.invoice.generated`).
  - **Backend**: `CentralAuditLogController@index` (nuevo archivo) — filtros opcionales
    `auditable_type`/`auditable_id`/`action`, `paginate(20)`, `with('centralUser:id,name,
    email')`. Ruta `GET tenants` → no, `GET audit-logs` (mismo grupo `auth:central`+
    `central.token`). **Decisión de diseño**: el filtro de "tenant" del selector se resuelve
    en el FRONTEND armando `auditable_type=App\Models\Tenant&auditable_id=<slug>` con los 2
    parámetros que el endpoint ya acepta — no se agregó ningún parámetro nuevo de backend.
    **Limitación conocida, documentada, no resuelta en esta sesión**: ese filtro solo cubre
    logs que afectan directamente al `Tenant` — los que cuelgan de un sub-recurso
    (`TenantBackup`/`TenantInvoice`/`TenantRestore`/`TenantSubscription`, identificados por
    su propio `auditable_type`) no tienen forma de filtrarse por tenant sin un JOIN o una
    columna `tenant_id` propia en `central_audit_logs`, que no existe hoy — se deja
    anotado, no se tocó el schema.
  - **Frontend**: `types/audit-log.ts` (interfaces + las 25 acciones como constante +
    `AUDITABLE_TYPE_TENANT`), `views/AuditLogsView.vue` (nueva, mismo patrón visual que
    `TenantListView.vue` — loading/error/empty, paginación real con `current_page`/
    `last_page` igual que `BackupsTab.vue`, filtros combinables por tenant/acción, `payload`
    expandible por fila como JSON crudo sin parsear cada shape distinto). Estado 100% local
    al componente (no vive en `stores/tenants.ts` — es una página global, no parte del
    detalle de un tenant; reusa `useTenantsStore().tenants` solo para poblar el selector de
    tenant, sin duplicar esa llamada). Ruta `audit-logs` + link "Auditoría" en `NavBar.vue`.
  - **Verificado con evidencia real**: `php artisan route:list --path=audit` confirmó la
    ruta nueva registrada (antes vacío). `GET central/audit-logs` sin filtros trajo logs
    reales generados por las sesiones anteriores (creación de tenants de prueba, upload de
    voucher, test-emission, etc.). Filtro por `action=tenant.test_emission` → 1 resultado
    exacto. Filtro por `auditable_type=App\Models\Tenant&auditable_id=sandbox` → 13
    resultados exactos, incluida la inconsistencia de `tenant.invoice.generated`
    documentada arriba. `vue-tsc -b` limpio, los 4 módulos nuevos/tocados transforman sin
    error 500 de Vite, SPA fallback confirmado en `/audit-logs`. **Misma limitación que
    todos los pasos anteriores de Fase D**: no se pudo clickear el flujo en un navegador
    real (sin herramienta de automatización disponible) — recomendado confirmarlo a mano
    antes de dar este paso por 100% cerrado.
- **Paso 4 (alta de tenant por UI, 2026-07-21) — CERRADO.** Gap real señalado por el
  usuario: `POST central/tenants` (`TenantAdminController::store()`) existía desde la Fase
  A y nunca tuvo formulario — los 4 tenants reales de hoy (`sandbox`/`umbo`/`negocio2`/
  `umbo-archivado`) se crearon todos por CLI/tinker, ninguno desde el panel. Cero cambios
  de backend — el endpoint ya soportaba exactamente lo que hacía falta.
  - **Frontend**: panel inline en `TenantListView.vue` (mismo patrón que los paneles de
    suspender/rechazar de `SubscriptionTab.vue` — sin modal de Bootstrap con JS
    imperativo), con los 7 campos que `store()` valida (`ruc`, `razon_social`,
    `razon_social_comercial`, `domain`, `admin_name`, `admin_email`, `admin_password`).
    Botón "Generar" para la contraseña (conveniencia — el endpoint del panel exige una
    contraseña real, a diferencia del Command CLI que puede generarla sola; el superadmin
    puede igual editarla antes de enviar). Aviso explícito en el formulario: la creación
    dispara `CreateDatabase`+`MigrateDatabase` de forma síncrona del lado del backend
    (puede tardar varios segundos, no es instantáneo).
  - `stores/tenants.ts` ganó `creating`/`createError`/`createTenant()` — al crear con
    éxito, fuerza un refresh completo del listado (`fetchTenants(true)`) en vez de
    insertar el tenant nuevo a mano en el array local.
  - **Verificado con un ciclo completo real, no solo el shape**: creado un tenant
    descartable (`uitest76461`) vía `curl` con el mismo payload exacto que envía el
    formulario → `201`, confirmado que aparece en `GET central/tenants` (`total` pasó de 4
    a 5) → destruido (base física dropeada, `domains`/`tenant` borrados, mismo mecanismo
    de limpieza ya usado en Fase E Paso 2) → confirmado `total` de vuelta en 4, sin rastro.
    `vue-tsc -b` limpio, los 2 módulos tocados transforman sin error de Vite. Misma
    limitación que el resto de Fase D: no se pudo clickear el formulario en un navegador
    real.
- **Paso 5 (verificación end-to-end del flujo de alta + archivar/restaurar/eliminar
  tenant, 2026-07-21) — CERRADO.** El usuario pidió probar de punta a punta el flujo
  "crear tenant → Company → SunatConfig → certificado → test-emission" (nunca se había
  encadenado completo en una sola prueba, solo por partes en sesiones anteriores) y
  agregar un botón de eliminar tenant, con el caso de uso explícito: "el cliente pidió un
  tenant y después se desanimó" — para eso el usuario mismo pidió distinguir entre
  **archivar** (tenants con datos reales) y **eliminar** (solo si nunca llegó a tener
  nada), después de que se le señalara la política ya existente de "archivado, no
  borrado" (retención legal SUNAT, documentada en
  `EnsureTenantIsActive.php`/`TenantProvisioningService.php`).
  - **Flujo completo verificado real, con un tenant descartable de punta a punta**: crear
    → `GET company` (null) → `POST company` (crea) → `POST test-emission` ANTES de
    SunatConfig (422 correcto) → `POST sunat-config` modo beta → `test-emission` (200,
    `certificado.propio_o_demo: "demo"`) → intento de `modo=produccion` sin certificado
    (422 correcto) → certificado `.pfx` real autofirmado generado con `openssl` (no un
    archivo falso) subido vía `POST sunat-config/certificado` (200,
    `certificado_valido: true`) → `modo=produccion` aceptado → `test-emission` final (200,
    `certificado.propio_o_demo: "propio"`, `valido: true`). Las 10 llamadas encadenadas
    sobre el MISMO tenant, sin recrear nada entre pasos — primera vez que se prueba la
    cadena completa real, no por partes. **Hallazgo cosmético menor, no bloqueante**: la
    respuesta de `POST sunat-config` justo después de crearlo muestra `activo: null` (el
    controller devuelve el modelo recién creado sin `->fresh()`, así que el default de
    Postgres para esa columna no se refleja todavía en esa respuesta puntual) — un `GET`
    inmediato después ya confirma `activo: true` correctamente, y es lo que
    `getSee()`/`test-emission` realmente leen; no afecta ningún comportamiento real, solo
    anotado por si alguna vez confunde a alguien mirando la respuesta cruda de ese POST.
  - **Archivar/Restaurar**: gap real — nunca existió ningún endpoint HTTP, solo 2 comandos
    CLI (`tenants:archive`/`tenants:restore`) desconectados del panel. Extraídos a
    `TenantProvisioningService::archivar()`/`restaurar()` (mismo criterio que
    `provision()`: el Command pasa a ser wrapper delgado sobre el servicio, nunca duplica
    lógica) — los 2 comandos CLI se refactorizaron para llamarlos. Nuevos
    `TenantAdminController::archive()`/`restore()` (`POST tenants/{id}/archive`/`/restore`)
    — nunca tocan la base física ni el storage, solo `status`/`fecha_archivado`
    (bloqueando login/API vía `EnsureTenantIsActive`, ya existente). Auditado como
    `tenant.archived`/`tenant.restored` (2 acciones nuevas, ver Audit Logs).
  - **Eliminar — deliberadamente estrecho, a pedido explícito del usuario**: nuevo
    `TenantProvisioningService::eliminarSiVacio()` — solo ejecuta el borrado físico real
    (mismo mecanismo que `rollback()`, extraído a `eliminarBaseFisica()` privado
    compartido por ambos) si el tenant NUNCA llegó a tener `Company`, `SunatConfig`,
    clientes, productos o ventas; si tiene cualquiera de esos, `TenantProvisioningException`
    → 422 ("Archivalo en su lugar"). `TenantAdminController::destroy()`
    (`DELETE tenants/{id}`) expone esto, auditado como `tenant.deleted` (con `ruc` en el
    payload — el tenant ya no existe en `tenants` para cuando se audita, se usa el dato
    que ya se tenía en memoria antes de borrar).
    - **Bug real encontrado y corregido probando el propio método antes de darlo por
      bueno** (no hipotético): el chequeo de "productos" bloqueaba SIEMPRE, incluso en un
      tenant recién creado sin tocar nada — porque **todo tenant, sin excepción, sale de
      `tenants:provision` con 1 producto ya sembrado** (`ADELANTO-001`, migración
      `2026_07_11_100004_seed_advance_special_product.php` — concepto placeholder para el
      módulo Adelantos, "no representa inventario real"). Sin excluir ese SKU específico,
      `eliminarSiVacio()` habría rechazado el 100% de los tenants nuevos, siempre,
      volviendo el botón "Eliminar" inútil en la práctica. Corregido con
      `Product::where('sku', '!=', 'ADELANTO-001')` — deliberadamente por SKU exacto, no
      excluyendo toda la categoría `is_especial_nota` (un tenant real puede crear sus
      propios productos-concepto para ND01/ND03, esos SÍ deben contar como dato real).
      Confirmado que no hay ningún seed equivalente en `clients`/`sales`/`companies`/
      `sunat_configs` (grep de `DB::table(...)->insert` en las migraciones de tenant).
  - **Verificado con evidencia real, ciclo completo**: tenant A (`archtest*`) — archivar
    (200) → archivar de nuevo (422, "ya está archivado") → restaurar (200) → restaurar de
    nuevo (422, "no está archivado") → eliminar estando vacío, ANTES del fix del SKU (422
    incorrecto, bug detectado acá) → **fix aplicado** → eliminar de nuevo (200) → `GET`
    confirma 404. Tenant B (`datatest*`) — con `Company` real creada → eliminar (422,
    mensaje correcto, tenant intacto después) → limpiado por tinker directo (bypass
    deliberado del guard, mismo criterio ya usado para artefactos de QA en sesiones
    anteriores — no es lo que un superadmin real haría). `central_audit_logs` confirmado
    con `tenant.archived`/`tenant.restored`/`tenant.deleted` reales, borrados junto con los
    2 tenants de prueba al final (`total` de tenants confirmado de vuelta en 4).
  - **Frontend**: `stores/tenants.ts` ganó `archiving`/`archiveError`/`archiveTenant()`/
    `restoreTenant()` y `deleting`/`deleteError`/`deleteTenant()` (mismo patrón que
    `createTenant()` — refresca el listado completo al terminar). `TenantListView.vue`
    ganó columna "Acciones" por fila (Archivar/Restaurar según `status`, Eliminar con
    confirmación inline — sin modal de Bootstrap con JS imperativo, mismo patrón ya usado
    en el resto del panel — mensaje explícito de que solo funciona si el tenant está
    vacío). `types/audit-log.ts::AUDIT_LOG_ACTIONS` ganó las 3 acciones nuevas (28 en
    total). `vue-tsc -b` limpio.
  - **Fuera de alcance, no pedido**: bloquear/ocultar el botón "Eliminar" en el frontend
    según si el tenant "parece" vacío (ej. mirando si tiene Company cacheada) — se decidió
    mostrarlo siempre y confiar en el 422 del backend como única fuente de verdad, más
    simple y sin duplicar la condición de "vacío" en 2 lugares.

### Fase E — Verificación de emisión
- Botón "probar emisión" contra beta antes de habilitar producción real
- **Paso 0 (auditoría, 2026-07-20) — CERRADO, solo diagnóstico, sin implementación.**
  Objetivo: entender por qué un tenant puede terminar emitiendo con el certificado demo
  sin que nadie lo note, y qué le falta al tramo `tenants:provision` → `Company` →
  `SunatConfig` → `enviarSunat()`. Los 4 puntos, con evidencia real (no solo lectura de
  código):

  1. **Provisioning actual**: `TenantProvisioningService::provision()`
     (`app/Services/TenantProvisioningService.php`) crea `Tenant` + `Domain` + rol/permisos
     (`PermissionsDemoSeeder`) + catálogos de Caja (`PaymentMethodSeeder`/
     `CashConceptSeeder`) + usuario admin — **nunca crea `Company` ni `SunatConfig`**, ni
     el Command CLI (`ProvisionTenant.php:78`, el propio mensaje ya lo advierte: "Pendiente:
     companies NO se creó — es un paso aparte") ni el flujo HTTP del panel
     (`TenantAdminController::store()`, mismo `provisioningService->provision()`). Ambos
     quedan exclusivamente a cargo de `TenantSunatController` (Fase B.3, panel), en dos
     pasos separados (`POST tenants/{id}` para Company vía `CompanyController`... en
     realidad `TenantSunatController::company()`, y `POST tenants/{id}/sunat-config` para
     SunatConfig) — nada dispara esos dos pasos automáticamente al provisionar.

  2. **Fallback al certificado demo — mecanismo exacto**
     (`GreenterService::resolveCertificado()`, líneas 64-96): es un `if
     ($sunatConfig->tieneCertificadoPropio())` / `else` plano. La rama `else` (beta sin
     certificado propio) hace `file_get_contents(base_path('storage/app/public/
     certificate-demo.pem'))` **sin ningún log, sin ninguna escritura en BD, sin ningún
     rastro** — no hay `Log::warning`, no hay campo tipo `emitido_con_certificado_demo` en
     `sales` ni en `sunat_configs`. Es indistinguible después del hecho: un XML firmado con
     el demo se ve en la BD exactamente igual que uno firmado con certificado propio, salvo
     que alguien abra el XML y note el RUC/razón social del certificado
     (`certificate-demo.pem` es el certificado demo público de Greenter — RUC 20606296526,
     "TU EMPRESA S.A. — CERTIFICADO PARA DEMOSTRACIÓN", el mismo que usan todos los
     tutoriales de Greenter, confirmado leyendo el archivo). El gate `modo='produccion'`
     SÍ exige certificado propio válido (línea 66-79, 422 explícito) — el hueco es
     exclusivo de `modo='beta'`, por diseño (el plan siempre asumió beta como modo de
     pruebas sin certificado), pero nada distingue "beta a propósito, sabiendo que es
     demo" de "beta porque nadie subió el certificado todavía y no se dieron cuenta".

  3. **Shape real y estado de los únicos 2 tenants existentes** (verificado con `tinker`
     real, no asumido): `sunat_configs` tiene `company_id, ruc, razon_social_sunat, modo,
     sol_usuario/sol_clave (encrypted), certificado_path, certificado_password (encrypted),
     certificado_fecha_vencimiento, cuenta_bancaria_detraccion, endpoint_produccion,
     endpoint_beta (estas 2 últimas: columnas muertas — `getSee()` usa
     `SunatEndpoints::FE_BETA`/`FE_PRODUCCION` hardcodeado, nunca lee estos campos),
     activo`. **Hallazgo real, no hipotético**: los dos únicos tenants que existen hoy
     (`sandbox` Y `umbo` — el tenant real del negocio, ver memoria del proyecto) tienen
     `SunatConfig` con `modo='beta'`, `activo=true`, **`certificado_path=null` en ambos** —
     es decir, **ambos tenants reales están emitiendo (o emitirían) hoy mismo con el
     certificado demo compartido**, sin que exista ningún indicador en el panel (Fase D,
     la UI, está pospuesta) que lo muestre. Para `umbo` esto coincide con lo ya anotado en
     memoria ("falta certificado SUNAT propio") — pero confirma que el hueco no es
     hipotético, es el estado actual real de producción de este momento.

  4. **Punto de entrada de `enviarSunat()`** (`FacturacionElectronicaController::
     enviarSunat()`): `$empresa = Company::first()` (línea 123) **nunca se valida contra
     null** — si `Company` no existe, esto llega intacto hasta `getInvoice($datos,
     $empresa, $venta)` dentro del `try` de la línea 276-290, donde revienta como
     `Error` de PHP ("Attempt to read property... on null") al primer
     `$empresa->n_document`. **Hallazgo real y más serio que el caso Company**: el
     `reservarCorrelativo($venta)` (línea 262) corre **ANTES** de ese `try` — es decir,
     tanto la falta de `Company` como la falta de `SunatConfig` activo (el guard 422
     explícito ya existe en `getSee()`, línea 36-41) **queman un correlativo real
     igual**, porque el `try/catch(\Throwable)` que las atrapa (pensado originalmente
     solo para errores de validación de Greenter, ver comentario línea 269-275 sobre la
     venta #16) está DESPUÉS de reservar el número. Además, ese `catch` devuelve
     `response()->json([...])` **sin código de estado** → HTTP 200 con el error
     embebido en el body, así que ni siquiera el 422 explícito de `getSee()` llega como
     422 real al frontend en este flujo — quien solo mire el status code vería un 200
     "exitoso". Conclusión: el principio ya establecido en el proyecto ("nunca fallback
     silencioso en campos fiscales, todo falla explícito con 4xx") se cumple a nivel de
     mensaje pero NO a nivel de: (a) proteger el correlativo antes de haber confirmado que
     el envío es viable, y (b) el código HTTP real devuelto. Esto ya era cierto antes de
     esta auditoría — no es un bug introducido por Fase E, pero es exactamente el patrón
     que un botón "probar emisión" ingenuo repetiría en cada click si reusara
     `enviarSunat()` tal cual.

  **Diagnóstico de causa raíz**: un tenant nuevo puede terminar emitiendo con el
  certificado demo sin que nadie lo note porque (a) `Company`/`SunatConfig` son pasos
  manuales separados del provisioning, sin ningún checklist forzado ni validación
  cruzada; (b) `modo='beta'` sin certificado propio es un estado válido y silencioso por
  diseño (para permitir pruebas), sin ningún campo/log que distinga "beta consciente" de
  "beta por descuido"; y (c) no existe ningún chequeo previo al primer envío real — el
  primer momento en que alguien se entera de que falta algo es cuando ya se intentó
  enviar (y, en el caso Company/SunatConfig ausente, después de ya haber quemado un
  correlativo).

  **Propuesta de diseño (sin implementar, a revisar) — `POST tenants/{id}/test-emission`**
  (panel superadmin, análogo a `TenantSunatController`, corre dentro de `$tenant->run()`):
  - Valida en orden, cortando en el primer fallo (nunca intenta enviar nada a SUNAT
    real): (1) existe `Company::first()`; (2) existe `SunatConfig` con `activo=true`;
    (3) el certificado resuelve — reusa la misma lógica de `resolveCertificado()` pero
    **exponiendo explícitamente** si el resultado es "certificado propio" o "certificado
    demo" (hoy esa distinción existe en el código pero se pierde, nunca se expone a
    ningún caller); (4) el certificado (si es propio) es legible/parseable con
    `openssl_x509_parse` y no está vencido; (5) conectividad real al endpoint BETA de
    SUNAT (`See::setService(FE_BETA)` + un intento de handshake/login SOL, sin llegar a
    enviar un comprobante real — a definir si Greenter permite esto sin armar un Invoice
    completo, o si el "test" real mínimo viable es efectivamente enviar un comprobante de
    prueba descartable).
  - Nunca debe pasar por `reservarCorrelativo()` — es exactamente el problema del punto 4
    de arriba. Un test de emisión no debe tener efecto sobre ningún correlativo real.
  - Respuesta: 200 con un objeto `{ company_ok, sunat_config_ok, certificado:
    {cargado, propio_o_demo, valido, vencimiento}, conectividad_beta_ok }` en vez de un
    simple pass/fail — el panel necesita mostrar CUÁL de los pasos falló, no solo que
    falló.
  - Código de error: 422 explícito y distinto por cada paso que falta (mismo criterio ya
    usado en `TenantSunatController::sunatConfigStore()`), nunca un 500 ni un 200 con
    error embebido (cerrar el hueco del punto 4).
  - **Pregunta abierta, no decidida en esta sesión**: ¿este endpoint es solo informativo
    (un botón que el superadmin corre manualmente antes de habilitar producción), o
    además debería convertirse en un gate que bloquea `enviarSunat()` en `modo='produccion'`
    si nunca se corrió un test exitoso (o si el último resultado guardado fue fallido)?
    Requiere decidir juntos antes de implementar — tiene implicancia de negocio (¿bloquea
    a un tenant real en medio de operar si el test nunca se corrió?), no es solo técnica.

- **Paso 1 (fix de validación, 2026-07-21) — CERRADO.** Corrige los hallazgos 1, 2 y 4 del
  Paso 0 en `FacturacionElectronicaController::enviarSunat()`. No toca el endpoint
  `test-emission` (Paso 2, sin empezar), ni el formulario/flujo de subida de certificado, ni
  series/correlativos/`SaleController` fuera de esta cadena.
  - **Orden de validación**: `Company::first()` ahora se valida contra `null` de inmediato
    (antes: `$empresa` llegaba intacto hasta `GreenterService::getInvoice()` y reventaba como
    `Error` de PHP al leer `$empresa->n_document`, YA con el correlativo quemado).
    `$this->greenter_service->getSee()` (valida `SunatConfig` activo Y resuelve/valida el
    certificado — incluido el gate de `modo=produccion` sin certificado, que YA existía en
    `GreenterService::resolveCertificado()` sin cambios de código ahí) ahora se llama ANTES
    de `reservarCorrelativo()`, fuera de cualquier try/catch — mismo criterio que el guard de
    crédito que ya cortaba antes de reservar. Los tres casos (`Company` ausente, `SunatConfig`
    activo ausente, `modo=produccion` sin certificado propio) ahora lanzan `HttpException`
    real, sin capturar, antes de tocar `serie_comprobantes`/`sales.correlativo`.
  - **Código de estado HTTP real**: el catch que envuelve `getInvoice()`/`$see->send()`
    (lo único que queda ahí tras sacar `getSee()`) ahora propaga
    `$e instanceof HttpException ? $e->getStatusCode() : 500` en vez de devolver siempre
    HTTP 200 con el error solo en el body — 500 como default (no 422) porque es exactamente
    lo que Laravel habría devuelto de todos modos si este catch no existiera; nunca se
    inventa un código de negocio para un `\Throwable` genérico. El catch de
    `procesarRespuestaSunat()` (CDR) y el flujo normal de rechazo SUNAT (`$result->
    isSuccess() === false`, vía `procesarRespuestaSunat()`, sin excepción de por medio) NO
    se tocaron — fuera del alcance de este Paso 1, y es el camino feliz de "SUNAT rechazó por
    una regla de negocio", no una falla de configuración.
  - **Hallazgo downstream, señalado y NO corregido en esta sesión (a pedido explícito)**: el
    frontend (`admin-start-kit/src/views/sale/index.vue::enviarSunat()` y
    `views/advances/show.vue::enviarComprobanteSunat()`) usa el patrón `try { if
    (res.data.response?.error) {...título específico...} } catch (error) { título genérico +
    error.response?.data?.message }`. Axios rechaza la promesa para cualquier respuesta no-2xx
    — antes de este fix, TODO devolvía 200, así que el bloque `try` siempre manejaba el
    resultado (éxito o rechazo SUNAT) con el título correcto ("Error SUNAT"/"Rechazado por
    SUNAT"). Ahora: los 3 guards nuevos (Company/SunatConfig/certificado producción) lanzan
    como excepción real sin capturar — Laravel los renderiza como `{"message": "..."}` plano
    (confirmado: `bootstrap/app.php` no tiene `withExceptions()` custom), que SÍ calza con
    `error.response?.data?.message` del `catch` — el mensaje específico llega bien, solo bajo
    el título genérico "Error" en vez de uno más específico. Pero el catch de
    `getInvoice()`/`send()` (fallos de red/construcción, no rechazos normales de SUNAT) ahora
    también puede traer un código no-200 con el body ANIDADO
    `{"response": {"error": {"message": ...}}}` — ahí `error.response?.data?.message` es
    `undefined` (el mensaje vive en `data.response.error.message`, no en `data.message`), así
    que ese caso específico cae al string genérico hardcodeado ("Error inesperado al enviar a
    SUNAT.") perdiendo el detalle. Acotado: solo afecta fallos de red/comunicación con SUNAT o
    errores de construcción del comprobante — el rechazo normal de SUNAT por regla de negocio
    sigue devolviendo 200 sin cambios, y sigue mostrando el mensaje específico correctamente.
    Pendiente para una sesión futura (no bloqueante): alinear el shape del body en ese catch
    (agregar `message` plano además de `response.error.message`) o actualizar los 2 call
    sites del frontend para leer ambos shapes.
  - **Verificado con evidencia real** (`tests/Feature/EnviarSunatValidacionPreCorrelativoTest.php`,
    nuevo, contra `sistemafe_test_migrations`/Postgres real, transacción por test revertida en
    `tearDown()`): sin `Company` → `HttpException` 422, `sales.correlativo` sigue `null`; sin
    `SunatConfig` activo → mismo resultado; `modo=produccion` sin `certificado_path` (tenant de
    prueba descartable, dentro de la transacción revertida) → mismo resultado, nunca llega a
    intentar red real contra SUNAT (el guard corta en `resolveCertificado()`, antes de
    `$see->send()`); `modo=beta` sin certificado propio → `resolveCertificado()` (vía
    reflexión, sin BD/red) sigue devolviendo el mismo `certificate-demo.pem`, cero regresión.
    Actualizado también `EnviarSunatCdrFailureTest::
    test_fallo_antes_de_procesar_respuesta_sunat_no_lleva_el_prefijo_cdr` (simulaba el fallo
    haciendo que `getSee()` lanzara — eso ya no vive dentro de ningún catch, así que se
    adaptó para simular el fallo dentro de `getInvoice()` en cambio, preservando el propósito
    original del test: distinguir este catch del catch de `procesarRespuestaSunat()`; se le
    agregó además la aserción del nuevo código 500). **30/30 tests verdes** (5 nuevos + 2
    `EnviarSunatCdrFailureTest` adaptados + 23 preexistentes de la misma cadena — `GreenterServiceFormaPagoTest`,
    `ReservarCorrelativoTest`, `ValidarRegimenEspecialTest` — sin regresión).
  - **Flujo beta→producción para un tenant real, anotado para cuando exista** (no construido
    en este Paso 1): completar `SunatConfig` en `modo=beta` primero (sin certificado, usa el
    demo — comportamiento intencional y sin cambios), subir el certificado propio cuando esté
    disponible (`POST tenants/{id}/sunat-config/certificado`, ya existe desde Fase B.3), y
    solo entonces cambiar `modo` a `produccion` (el gate de este Paso 1 ya lo exige). Ese
    cambio de modo es candidato a quedar registrado en `central_audit_logs` cuando exista un
    flujo formal de activación — fuera de alcance de este Paso 1, solo dejarlo anotado.

- **Paso 2 (endpoint informativo, 2026-07-21) — CERRADO.** `POST
  tenants/{id}/test-emission` (guard `auth:central`+`central.token`, mismo grupo de rutas que
  el resto de `TenantSunatController`). Nunca llama a `reservarCorrelativo()`, nunca construye
  un `Invoice` ni llama a `$see->send()`, nunca toca `sales`/`serie_comprobantes` — es de
  solo lectura sobre `Company`/`SunatConfig`/certificado.
  - **Sin duplicar el Paso 1**: se extrajo `GreenterService::validarCompanyPresente($empresa)`
    (antes vivía inline en `enviarSunat()`, mismo mensaje, sin cambio de comportamiento) y
    `GreenterService::verificarListoParaEmitir($empresa)` (nuevo — llama a
    `validarCompanyPresente()` + `getSee()`, sin construir nada más, y arma el detalle
    estructurado: `company`, `modo`, `certificado.{cargado, propio_o_demo, valido,
    fecha_vencimiento}`). `enviarSunat()` ahora llama a `validarCompanyPresente($empresa)` en
    vez de su chequeo inline — mismo mensaje exacto, confirmado con los tests existentes sin
    tocar sus aserciones. `TenantSunatController::testEmission()` llama a
    `verificarListoParaEmitir()` — ninguna de las 2 reglas (Company, SunatConfig+certificado)
    vive duplicada en ningún lado.
  - **`TenantSunatController::testEmission()`**: mismo patrón de closure que
    `company()`/`sunatConfigStore()`/`sunatConfigCertificado()` del mismo archivo — la
    llamada a `verificarListoParaEmitir()` corre DENTRO de `$tenant->run()`, envuelta en su
    propio try/catch que devuelve `['error' => ...]` en vez de dejar escapar la excepción
    (mismo motivo ya documentado en la clase: `TenantRun::run()` no revierte tenancy si el
    callback lanza). El `HttpException` real se relanza DESPUÉS de que `run()` ya retornó.
  - **Auditoría en `central_audit_logs`, éxito y fallo por igual** (`action:
    'tenant.test_emission'`, `payload: {resultado: 'ok'|'error', motivo, modo}`) — se
    registra siempre, no solo cuando falla, mismo criterio de "toda acción sensible queda
    auditada" ya usado por el resto del archivo.
  - **Punto 4 del diseño original (conectividad real contra SUNAT sin emitir) — investigado,
    NO implementado, documentado como limitación conocida (no un olvido).** Greenter sí
    expone una operación de solo-consulta genuina:
    `Greenter\Ws\Services\ConsultCdrService::getStatus()/getStatusCdr()` (consulta un
    comprobante YA emitido por ruc/tipo/serie/número — no emite nada, distinto de
    `See::getStatus($ticket)`, que consulta un ticket asíncrono previo, tampoco aplicable
    acá). Se descartó ejercitarla en este Paso 2 por dos motivos concretos, no por falta de
    tiempo: (a) `Greenter\Ws\Services\SunatEndpoints` solo trae la URL de este servicio para
    producción (`FE_CONSULTA_CDR`) — sin constante equivalente para beta, que es el único
    modo que existe hoy en la práctica (`sandbox` y `umbo`, ver Fase E Paso 0); (b) interpretar
    con sentido la respuesta (o el `SoapFault`) de esta consulta exige distinguir
    "credenciales SOL inválidas" de "el documento consultado no existe" — un error en esa
    interpretación podría hacer que el panel reporte un falso negativo ("no puede emitir")
    o, peor, un falso positivo, sobre una herramienta que un superadmin va a tomar como
    fuente de verdad antes de decidir activar producción. El endpoint, tal como quedó,
    confirma exactamente los 3 primeros puntos (Company, SunatConfig activo, certificado
    resoluble) — no confirma conectividad de red real contra el WSDL de SUNAT ni que las
    credenciales SOL sean correctas más allá de que existan. Queda anotado como alcance real
    conocido, no prometido.
  - **Verificado con evidencia real, en dos niveles**:
    - `tests/Feature/VerificarListoParaEmitirTest.php` (nuevo, 4 tests, mismo fixture que
      `EnviarSunatValidacionPreCorrelativoTest` contra `sistemafe_test_migrations`): cubre la
      lógica de negocio en sí (sin Company → 422; sin SunatConfig activo → 422; beta sin
      certificado → detalle con `propio_o_demo: 'demo'`; producción sin certificado → 422,
      mismo mensaje del gate del Paso 1).
    - **Endpoint completo (routing + tenancy + audit log), verificado vía `tinker` contra un
      tenant real descartable** (`faseetest612489`, provisionado con
      `TenantProvisioningService` — mismo mecanismo que `tenants:provision`/el panel — y
      destruido al final: base física dropeada, `domains`/`tenants` borrados, confirmado
      después que `Tenant::find()` devuelve `null` y que no quedó ninguna base
      `faseetest*` en Postgres). Los 4 casos, en secuencia sobre el MISMO tenant (mutando su
      `Company`/`SunatConfig` entre llamadas): sin `Company` → `HttpException 422` con el
      mensaje exacto; con `Company` sin `SunatConfig` → `HttpException 422` con el mensaje
      exacto; `modo=beta` sin certificado → `200` con
      `certificado.propio_o_demo: "demo"`; `modo=produccion` sin certificado → `HttpException
      422` con el mensaje del gate. **`central_audit_logs` confirmado con las 4 filas
      exactas** (3 `resultado: "error"` + 1 `resultado: "ok"`, cada una con su `motivo`/`modo`
      correctos), borradas al final junto con el tenant descartable — no quedó ningún rastro
      sintético permanente. **34/34 tests verdes en total** (4 nuevos de este Paso 2 + 30 del
      Paso 1, sin regresión).
  - **No incluido en este Paso 2** (a pedido explícito): frontend del botón (Fase D,
    pospuesta — el endpoint queda listo para cuando se arme); formulario/endpoint de subida de
    certificado (ya existe desde Fase B.3, no auditado de nuevo acá); conversión en gate
    obligatorio del flujo real (sigue siendo informativo/opcional; el gate real de
    producción-sin-certificado ya vive en `enviarSunat()` desde el Paso 1, independiente de
    este endpoint).
  - **Ejemplo de request/response, para cuando se arme el frontend (Fase D)**:
    - `POST /api/central/tenants/{id}/test-emission` (sin body).
    - Éxito: `200 {"test_emission": {"company": {"id", "razon_social", "n_document"}, "modo":
      "beta"|"produccion", "certificado": {"cargado": bool, "propio_o_demo": "propio"|"demo",
      "valido": bool|null, "fecha_vencimiento": string|null}}}`.
    - Falla (primer punto que falla, sin importar cuál): `422 {"message": "<motivo
      específico>"}` (render default de Laravel para `HttpException`, sin envoltorio
      adicional).

## Siguiente paso

Fase 0, Fase A, Fase B (B.1/B.2/B.3) y B.0.5 (consolidación de `central`) completas. Dentro
de Fase B.2 (Suscripciones), B.2.1 a B.2.5 completas y verificadas (B.2.5 vía HTTP real,
login central incluido — ver el fix de `CentralUser` arriba). **B.2.6 pospuesta** (depende de
la vista de detalle de tenant, ver Fase D). **Fase D, Pasos 0/1/2 cerrados 2026-07-21**:
scaffold, listado de tenants, y vista de detalle completa (6 tabs: Company/Suscripción/
Backups/SunatConfig/Certificado/Test-emission) — 2 gaps reales de backend cerrados en el
camino (`GET tenants/{id}/company` nuevo, eager-load de vouchers en
`TenantSubscriptionController::show()`), ambos aditivos y de solo lectura, verificados con
un ciclo de escritura completo (subir/verificar voucher) contra `sandbox`. **B.2.6 sigue
pospuesta** — aunque ya existe la vista de detalle, esa sub-fase específica (sección
"Facturación" con más detalle del que el tab Suscripción cubre hoy) no se retomó en esta
sesión, sin decidir si sigue haciendo falta algo más allá de lo ya construido. **Fase D,
Paso 3 (vista global de Audit Logs) y Paso 4 (alta de tenant por UI) cerrados
2026-07-21** — endpoint nuevo de auditoría (`CentralAuditLogController`, antes no existía
ninguno) + vista con filtros por tenant/acción, 25 acciones reales confirmadas por grep; y
formulario de alta de tenant en `TenantListView.vue` (gap señalado por el usuario — el
endpoint `POST central/tenants` existía desde Fase A pero nunca tuvo UI, los 4 tenants
reales de hoy se crearon todos por CLI/tinker). **Paso 5 (verificación end-to-end +
archivar/restaurar/eliminar tenant) cerrado 2026-07-21** — flujo completo crear→Company→
SunatConfig→certificado→test-emission probado real de punta a punta por primera vez
(10 llamadas encadenadas sobre el mismo tenant descartable, incluido certificado .pfx real
autofirmado y el gate de producción); botones Archivar/Restaurar (wrapean los comandos CLI
`tenants:archive`/`tenants:restore`, nunca tocan la base física — política "archivado, no
borrado" por retención legal SUNAT) y Eliminar (deliberadamente estrecho, solo si el
tenant nunca tuvo datos reales) — con un bug real encontrado y corregido en el camino
(todo tenant nuevo trae 1 producto placeholder sembrado por migración, `ADELANTO-001`,
que sin excluirlo bloqueaba el borrado de cualquier tenant siempre). **Fase D queda
cerrada en su alcance
actual** — falta decidir la pregunta abierta de Fase E (¿test-emission como gate
obligatorio?). **Fase E, Paso 0 (auditoría) cerrado
2026-07-20** — diagnóstico + propuesta de diseño del endpoint `test-emission` documentados
arriba. **Fase E, Paso 1 (fix de validación/orden/código HTTP) cerrado 2026-07-21** —
30/30 tests verdes, sin regresión; hallazgo downstream de frontend señalado y no corregido
(no bloqueante, ver detalle en Fase E). **Fase E, Paso 2 (endpoint `POST
tenants/{id}/test-emission`) cerrado 2026-07-21** — 34/34 tests verdes, verificado además
con tinker contra un tenant real descartable (creado y destruido en la misma sesión);
conectividad real contra SUNAT (punto 4 del diseño) investigada y descartada a propósito,
documentada como limitación conocida. **Fase E queda cerrada en su alcance actual** — falta
decidir, cuando se retome, la pregunta abierta del Paso 0 (¿gate bloqueante para
`modo=produccion` o sigue siendo solo informativo?) — el frontend de `test-emission` ya
se construyó (Fase D, Paso 2, `TestEmissionTab.vue`). **Fase C (Backups) completa — C.1, C.2,
C.3 y C.4**, las 4 sub-fases verificadas con evidencia real contra `sandbox` (C.1/C.2 el
2026-07-20 con 2 bugs reales de `Process`/Windows corregidos; C.3 el 2026-07-21 con 2
pruebas reales de atomicidad de Postgres antes de escribir código y una restauración real
completa exitosa; C.4 el mismo día, movida al momento de creación del backup en vez de
quedar absorbida por el preview de C.3 — decisión explícita del usuario corrigiendo mi
propuesta original de absorberla del todo). **Fase C queda cerrada.**

**Estado real y definitivo al 2026-07-21, fin de sesión** (las menciones de "Fase D
pospuesta" más arriba en este documento son historia — quedaron desactualizadas apenas se
decidió invertir en el frontend, ver nota debajo): **Fases 0, A, B, B.0.5, C, D (Pasos
0-5) y E (Pasos 0-2) están CERRADAS.** Lo único que sigue abierto en todo el panel
superadmin es: (1) B.2.6 (sección "Facturación" en el detalle de tenant, sin decidir si
hace falta algo más allá de lo que el tab Suscripción ya cubre); (2) la pregunta de
negocio de Fase E (¿`test-emission` gate obligatorio o solo informativo?); (3) la vista de
`central_audit_logs` YA está construida (Fase D, Paso 3) — no es un pendiente, corregir
si algún párrafo viejo de este documento todavía la lista como tal. `central-panel/` es un
proyecto Vue nuevo y separado (no `admin-start-kit`), correr con `php artisan serve
--host=127.0.0.1 --port=8000` (backend) + `npm run dev` en `central-panel/` (frontend,
puerto 5174 fijo) → `http://localhost:5174`.

`sandbox` quedó con 5 backups reales (1 manual + 3 automáticos + 1
`pre_restore`), todos con `integridad_verificada=true` confirmado, tenant `status=activo`.
El invoice real de B.2.3 (id=1, sub=1) sigue `pagado`, hace falta generar uno nuevo (`POST
tenants/sandbox/invoices`) o esperar al cron si se quiere seguir probando el ciclo de
facturación completo.

**Actualización (2026-07-20):** la Fase B.0.5 del panel superadmin resolvió la mitad de este
bloqueante — `tenants`, `domains` y los catálogos SUNAT se migraron fuera de `sv_facturacion`
hacia la conexión `central` consolidada (`db_tenant_central`). `sv_facturacion` ya no cumple
ningún rol de infraestructura; contiene únicamente los datos históricos del negocio original
(incluida la tabla legacy `products`, con su FK hacia `detraction_codes` ya removido). La
pregunta original sigue abierta: ¿esos datos se migran a un tenant `es_base=true`, o quedan
archivados tal cual? Ver `plan-panel-superadmin.md`, Fase B.0.5, para el detalle completo de la
migración.

## Gap cerrado (2026-08-15) — gestión completa de un tenant desde el panel: giro, edición, reset de password del admin

Origen: `docs/planning/panel-superadmin/sesion-giro-selector-panel.md` (brief, ver también
`PEGAR-EN-CLAUDE-CODE-giro-selector.md.md`, contenido casi idéntico). El pedido original era
solo "selector de giro al crear" — se amplió el mismo día porque el usuario confirmó que todo
el ciclo de vida de un tenant debe manejarse desde el panel, sin CLI/SSH/tinker manual. Motivo
concreto: `market.umbosystem.com` se creó desde el formulario del panel (confirmado por el
usuario), candidato altamente probable a `giro='retail'` por default de migración sin importar
el negocio real — sin este fix no había forma de verlo ni corregirlo desde la UI.

**Tres piezas, las tres cerradas:**
1. **Selector de `giro` al crear** — `TenantAdminController::store()` ahora valida
   `giro` (`required`, `Rule::in(TenantProvisioningService::GIROS_VALIDOS)`, constante
   nueva y pública — antes vivía duplicada como `private const` en `ProvisionTenant.php`,
   el Command CLI ahora la referencia desde el servicio). `TenantListView.vue`: `<select>`
   sin opción preseleccionada (fuerza elección explícita, a pedido del brief — evita crear
   otro tenant `retail` por descuido) + columna "Giro" nueva en la tabla del listado.
2. **Editar un tenant ya creado** — antes no existía ninguna vía salvo SQL/tinker.
   `TenantProvisioningService::actualizar()` nuevo (razón social/comercial/giro, todos
   opcionales) — si `giro` cambia, dispara `migrarVertical()` (el mismo método privado que
   ya usa `provision()`, sin duplicar lógica) para que las tablas del vertical nuevo
   aparezcan retroactivamente; idempotente por diseño (Laravel trackea qué migración ya
   corrió por tenant). `TenantAdminController::update()` nuevo (`PUT central/tenants/{id}`,
   validación `sometimes` por campo, 422 explícito si no llega ningún campo). `domain` queda
   fuera a propósito (toca el registro de `stancl/tenancy`, caso más delicado, brief lo
   excluyó explícitamente). Frontend: modo edición inline en `TenantDetailView.vue` (botón
   "Editar" en el header) — si el giro elegido difiere del actual, exige un checkbox de
   confirmación explícita antes de habilitar "Guardar cambios" (advierte que la migración
   retroactiva puede tardar y no es reversible con un click, a pedido del brief).
3. **Restablecer el password del admin de un tenant** — antes solo por tinker/SQL directo.
   `TenantAdminController::resetAdminPassword()` nuevo (`POST
   tenants/{id}/reset-admin-password`), entra al tenant vía `$tenant->run()`, busca el
   usuario con rol `Super-Admin` (opcionalmente filtrado por `admin_email` si hay más de
   uno — 422 explícito listando los candidatos en vez de adivinar), asigna el password en
   texto plano (`User::$casts` ya tiene `password` como `'hashed'`, no hace falta
   `bcrypt()`/`Hash::make()` a mano) y audita la acción (`tenant.admin_password_reset`).
   Frontend: sección separada del formulario de edición a propósito (acción más sensible,
   más fácil de auditar sola), con el mismo botón "Generar" ya usado en el alta de tenant.
- **Bug real encontrado y corregido al verificar `resetAdminPassword()` contra un tenant
  descartable (no solo por lectura de código)**: `User::role('Super-Admin')` (scope de
  Spatie `HasRoles`) revienta con "Non-static method App\Models\User::role() cannot be
  called statically" — `User` ya declara un método de instancia propio `role()` (relación
  legacy hacia `role_id` singular, ver la nota de Fase D/E sobre `respondWithToken()` más
  arriba en este documento) que colisiona con el nombre del scope de Spatie. Corregido con
  `User::whereHas('roles', fn ($q) => $q->where('name', 'Super-Admin'))` — la relación
  plural `roles()` no está sobreescrita. Sin este fix, el endpoint habría fallado el 100%
  de las veces con un 500.
- `serialize()` ganó `giro`, `tipo` y `sunat_modo` (ninguno de los tres se exponía antes —
  ni el listado ni el detalle de un tenant los podían mostrar).
- **Verificado en 2 niveles, contra tenants descartables creados y destruidos en la misma
  sesión (nunca contra `sandbox`/`umbo`/`negocio2`/`agencia-demo` reales, salvo un `GET`
  de solo lectura sobre `umbo` para confirmar que `tipo`/`sunat_modo` aparecen bien)**:
  (1) a nivel de servicio vía tinker (`TenantProvisioningService::actualizar()` cambiando
  `retail`→`agencia_viajes` confirmado con `Schema::hasTable('configuracion_agencia')`
  antes/después; reset de password confirmado con `Hash::check()` real; idempotencia de
  una segunda llamada con el mismo giro; `eliminarSiVacio()` sin dejar rastro) — este
  primer intento reveló el bug de `User::role()` de arriba; (2) a nivel HTTP real
  (`php artisan serve` + curl, login central real con las credenciales de `.env`): los 7
  casos del checklist del brief (`store()` sin/con giro inválido/válido, `update()` con
  campo válido/sin campos, `reset-admin-password` válido/con email inexistente) dieron
  exactamente el código HTTP esperado, tenant descartable eliminado al final sin dejar
  rastro. `vue-tsc -b` limpio en `central-panel/` en todo momento.
- **Pendiente, explícitamente fuera de este fix (§ "Pendiente aparte" del brief)**:
  corregir el `giro` real de `market.umbosystem.com` — ahora que el mecanismo existe, el
  camino correcto es entrar a su detalle en el panel y usarlo ahí, no SQL manual. Requiere
  primero confirmar cuál es su giro real y pedir aprobación explícita antes de tocarlo,
  como cualquier tenant con datos reales.