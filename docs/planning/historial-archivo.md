# Historial archivado — documentos transversales (raíz de `docs/planning/`)

> Resumen comprimido de planes/guías que ya cumplieron su propósito y
> fueron retirados de la raíz para no tener que releerlos completos en
> cada sesión nueva. Si necesitás el detalle línea por línea de algo de
> acá, probablemente no lo necesites — el resumen ya captura las
> decisiones y hallazgos reales; el resto era proceso de llegar ahí.
> Ver también `INDICE.md` (raíz de `docs/planning/`) para el mapa vigente
> de qué documento activo cubre qué.

---

## `plan-modulo-infraestructura-multitenant.md` (207 líneas, archivado 20-ago-2026)

Plan de ejecución de la Sesión 0 del vertical Agencia de Viajes: separar
`database/migrations/tenant/` en `core/` (facturación/retail, compartido
por todos los giros) vs. `verticals/{giro}/` (específico de cada rubro).
**Cerrado desde el 23-jul-2026** — las 67 migraciones reales del tenant
se movieron a `tenant/core/` sin necesidad de clasificar caso por caso
(confirmado que ninguna era de vertical todavía), `giro='retail'` quedó
como default con backfill automático de tenants existentes, y se creó un
tenant dedicado (`agenciatest`) para no mezclar el historial de pruebas
con el de facturación/panel superadmin.

Dos bugs reales del mecanismo, encontrados después y ya corregidos (ver
`arquitectura-multitenant-backend_1.md` y `CLAUDE.md` para el detalle
completo): (1) Sesión 2 — `migrarVertical()` construía la ruta de
carpeta en snake_case cuando la carpeta real usa kebab-case, quedó oculto
porque `verticals/agencia-viajes/` estaba vacía al principio; (2)
30-jul-2026 — el comando genérico `tenants:migrate` (mantenimiento sobre
tenants ya provisionados, distinto del provisioning inicial) nunca corría
`tenant/verticals/*` porque `config/tenancy.php` tenía el `--path`
hardcodeado a `tenant/core/` — corregido con el comando nuevo
`tenants:migrate-verticales`, que reemplaza al genérico para este caso de
uso desde entonces (rama `fix/infra-migracion-verticals-pendientes`,
mergeada en `3fc2c6f`).

---

## `guia-despliegue-produccion-ovh.md.md` (802 líneas, archivado 20-ago-2026)

Guía paso a paso escrita el 15-ago-2026 para llevar el sistema a un
servidor real de producción en OVH (Ubuntu 26.04, PHP 8.5, PostgreSQL 18,
Redis) — hardening, PHP, Postgres, Redis, deploy de los 2 frontends,
dominio/certificado, y el selector de giro/edición de tenant del panel
superadmin.

**El despliegue ya se hizo.** Confirmado con evidencia real dentro de la
propia guía y coincide con la memoria de proyecto
(`project_despliegue_produccion_ovh_estado.md`): se ejecutó con Claude
Desktop (no Claude Code) el 16/17-ago-2026, no con estas instrucciones
paso a paso literales. Puntos que la guía deja confirmados como resueltos
en producción real: fix de `maatwebsite/excel` para PHP 8.5 (commit ya en
`main`, ver `9ed9187`), `deploy-ovh/scripts/deploy.sh` reconstruyendo
`central-panel` correctamente (commit `aaf40a8`), y selector de
giro/editar tenant/reset de password ya visible en producción (commit
`0af82e2`).

**Pendiente real que sí sigue abierto** (no resuelto en la guía, requiere
aprobación explícita del usuario antes de tocarlo — tenant con datos
reales): revisar y, si hace falta, corregir el `giro` real de
`market.umbosystem.com`.

**Por qué se archiva y no se actualiza:** la guía describe el estado
*antes* del despliegue (comandos a correr, huecos a resolver) — una vez
que el servidor está en producción real, cualquier cambio de
infraestructura futuro debe verificarse contra el servidor real por SSH,
no releyendo esta guía como si describiera el estado actual. Si se
necesita desplegar un cambio nuevo a producción, partir del estado real
del servidor (o de `deploy-ovh/scripts/deploy.sh`, que sí sigue vigente),
no de esta guía.

---

## URL de API dinámica en dev + URLs de storage tenant-aware (cerrado 2026-07-31)

**URL de API dinámica en dev** (rama `feature/frontend-api-url-dinamica-dev`, mergeada en
`399f3c5`): `VITE_API_BASE_URL` era un valor fijo en `.env`, solo se podía probar un
tenant a la vez en dev. `admin-start-kit/src/helpers/apiBaseUrl.ts::resolveApiBaseUrl()`
calcula la URL en el momento a partir del hostname actual en dev (puerto configurable vía
`VITE_API_DEV_PORT`); producción sigue usando `VITE_API_BASE_URL` fijo, sin cambios.
Verificado con Playwright: dos pestañas de tenants distintos abiertas a la vez contra el
mismo `npm run dev`, cada una pegando a su propio backend sin pisarse. Mismo commit quita
el prefijo `/rizz_v/` del base path.

**URLs de storage tenant-aware** (rama `fix/infra-storage-urls-tenant-aware`): bug real
confirmado (imagen de un producto de un tenant mostrando URL de otro) — `env('APP_URL')`
fijo en 6 puntos del backend armaba URLs con el host de donde apuntara `.env` en ese
momento, no el del tenant que pedía. `App\Services\StorageUrl::resolve()`/
`resolveMuchas()` centraliza esto — ver regla completa en `CLAUDE.md`, sección "Cómo
trabajar en este proyecto". Hallazgo más profundo, encontrado recién al verificar con
navegador real: arreglar el host no bastaba — `public/storage` es un symlink ESTÁTICO que
Apache sirve directo, siempre apuntando a la carpeta CENTRAL, nunca a
`storage/tenant{slug}/...` (donde vive de verdad el archivo de cualquier tenant posterior
al split). "umbo" (tenant original) "funcionaba" solo porque sus archivos quedaron
duplicados a mano en ambas carpetas — cualquier tenant nuevo daba 403 sin importar el host
de la URL. Resuelto con `tenant_asset()` (helper de `stancl/tenancy`), que necesitó un fix
adicional (`TenancyServiceProvider::configureTenantAssetsMiddleware()`, el paquete
registra su ruta con `InitializeTenancyByDomain` hardcodeado pero el proyecto identifica
tenants por subdominio). Verificado con navegador real: aislamiento cruzado confirmado
(pedir el archivo de un tenant con el host de otro da 404). 92/92 tests backend en verde.

**Pendiente real, sin resolver:** `SystemCategory`/`ManualRecurso` (modelos centrales)
siguen subiendo sus archivos al disco `public` *suffijado por tenant* — quedan aislados en
la partición del tenant activo al subir, inconsistente con ser datos centrales que
deberían verse igual desde cualquier tenant.

## Spinners de carga + editor de texto enriquecido + cache de preflight CORS (2026-08-10/11)

**Spinners + editor** (rama `feature/spinners-y-editor-enriquecido`, mergeada en
`6eaf3c7`): spinners en toda acción async de `paquetes/detalle.vue`/`cotizador/editar.vue`
que no los tenía. `RichTextEditor.vue` nuevo (wrapper de `@vueup/vue-quill`, ya instalado
sin uso) reemplaza `<textarea>` en descripciones de paquetes/destinos. Verificado con
Playwright real, type-check en 0 errores nuevos.

**Hallazgo de rendimiento en dev, diagnosticado a fondo, sin resolver (no bloquea nada,
dev-only)**: abrir un paquete/tour tarda 2.8-3s. Causa dominante confirmada con Network
Timing + CDP real: `php artisan serve` (sin `--workers`) atiende una sola request a la vez
— `detalle.vue` dispara 6-9 requests casi simultáneas al montar, se encolan en escalera de
~400-450ms cada una. El N+1 conocido de `ComboExplosionService` es real pero secundario
(~180ms de diferencia). `CACHE_STORE=database` descartado como causa (~0.2-0.3ms/request).

**Cache de preflight OPTIONS de CORS** (rama `fix/cors-preflight-cache`, mergeada en
`7bf1810`): el proyecto nunca tuvo `config/cors.php` propio — el default de Laravel trae
`max_age=0`, el navegador nunca cacheaba el preflight y volvía a preguntar en cada request
real, duplicando round-trips en TODAS las pantallas. Publicado con los mismos valores
efectivos + `max_age=3600`. Verificado con CDP real (no con Playwright, que filtra
preflights): con `max_age=0`, 10 preflights en cada F5; con `max_age=3600`, 0 en el F5
posterior a la primera carga. 103/103 tests backend en verde.
