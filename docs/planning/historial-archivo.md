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
