# Sub-plan — Módulo 0: Infraestructura core/verticals

> Parte de: `plan-general-vertical-agencia-viajes.md` — Módulo 0
> Referencia de diseño: `arquitectura-multitenant-backend.md` (describe
> el patrón; este documento es el plan de EJECUCIÓN para aplicarlo por
> primera vez)
> Estado: inventario real verificado, todas las preguntas abiertas de
> la sección 5 resueltas. Listo para pasar a ejecución.
> Última actualización: 23-jul-2026

---

## 1. Objetivo (corregido tras revisar el inventario real)

**Hallazgo clave:** la separación conexión-central vs conexión-tenant
**ya existe** — `database/migrations/` (raíz) es 100% conexión central,
`database/migrations/tenant/` es 100% conexión tenant. Esto no es parte
del trabajo pendiente.

Lo que sí falta es la separación **dentro** de `database/migrations/tenant/`:

```
database/migrations/               ← YA EXISTE, conexión central, no se toca
└── tenant/                        ← YA EXISTE como carpeta, pero todo
    ├── (todas las 67 migraciones    plano adentro, sin distinguir
    │    de facturación, hoy planas)  qué es core de qué es vertical
    │
    │   ↓ se reorganiza en:
    │
    ├── core/                      ← NUEVO: se corre en TODOS los tenants
    └── verticals/
        └── agencia-viajes/        ← NUEVO: solo si tenants.giro = 'agencia_viajes'
```

Y dejar `tenants:provision` capaz de recibir el `giro` y correr
`--path=database/migrations/tenant/core` +
`--path=database/migrations/tenant/verticals/{giro}` en ese orden.

## 2. Inventario real verificado (23-jul-2026)

**Confirmado por captura directa de ambas carpetas:**

- **Raíz (`database/migrations/`)** — conexión central. Contiene
  `create_tenants_table`/`create_domains_table` (stancl/tenancy),
  catálogos SUNAT (`tax_configs`, `detraction_codes`, `tipos_comprobante`),
  y toda la infraestructura del panel superadmin ya reconciliada en el
  módulo 11 (`central_users`, `tenant_plans`, `tenant_subscriptions`,
  `tenant_invoices`, `tenant_backups`, `add_status_to_tenants_table`,
  etc.) — coincide exactamente con lo ya mapeado en
  `plan-modulo-planes-acceso.md`. **No se toca en este módulo.**

- **Raíz también contiene `systems`/`system_features`/`system_modules`/
  `plans`/`manual_recursos` (2026_06_01)** — confirmado con el usuario:
  es contenido de marketing/landing page de umbo (sistemas que la
  empresa vende a prospectos), **sin relación con el acceso de
  tenants**. No colisiona con `modulos`/`plan_modulo`/`tenant_plans` del
  módulo 11 — son conceptos distintos a pesar del nombre parecido
  (`plans` acá vs `tenant_plans` allá). Se descarta como riesgo.

- **`database/migrations/tenant/`** — conexión tenant, 67 migraciones,
  **todas de facturación/retail, ninguna específica de agencia de
  viajes todavía** (confirmado visualmente: users, clients, companies,
  products, sales, orders, notes, advances, sunat_configs, credits,
  installments, cash management completo, branches, comprobantes/series).
  Esto simplifica el primer movimiento: **el 100% del contenido actual
  de `tenant/` se mueve a `tenant/core/`**, no hay que decidir caso por
  caso qué es core y qué es vertical — hoy no existe nada de vertical
  todavía.

## 3. Por qué es la dependencia dura de todo lo demás

Ningún tenant con `giro=agencia_viajes` puede aprovisionarse hasta que
esto exista — ni siquiera para pruebas. Los módulos 1 a 10 del mapa
general pueden madurar su modelo de datos en paralelo (como ya está
pasando con cotizaciones/reservas), pero nada de eso se puede *correr*
contra un tenant real hasta que `tenants:provision` sepa qué hacer con
el campo `giro`.

## 4. Checklist actualizado (reemplaza el del plan general)

- [ ] Crear `database/migrations/tenant/core/` y mover ahí el 100% del
      contenido actual de `database/migrations/tenant/` (refactor
      mecánico, sin tocar contenido de ningún archivo)
- [ ] Verificar que el orden de ejecución se preserva — riesgo bajo
      porque TODO el set se mueve junto a la misma carpeta nueva (ver
      sección 5.1, el riesgo real aparece más adelante, no en este
      primer movimiento)
- [ ] Agregar campo `giro` a la tabla `tenants` (central) — junto con
      `tipo`/`sunat_modo` del módulo 11, misma migración (ver sección 6)
- [ ] Crear carpeta vacía `database/migrations/tenant/verticals/agencia-viajes/`
- [ ] Actualizar `tenants:provision` para que corra
      `--path=database/migrations/tenant/core` primero, luego
      `--path=database/migrations/tenant/verticals/{giro}` si el giro
      tiene carpeta con contenido
- [ ] Probar provisioning de un tenant de prueba con `giro=agencia_viajes`
      (carpeta vertical vacía todavía) para validar que el mecanismo
      funciona de punta a punta sin romper nada del core

## 5. Preguntas abiertas / riesgos

### 5.1 Riesgo de orden — bajo para este primer movimiento, real para el futuro
Como **todo** el contenido de `tenant/` se mueve junto a `tenant/core/`
(nada se separa todavía, no hay vertical con contenido), el orden
relativo entre los 67 archivos no cambia — siguen ordenándose por
timestamp de nombre dentro de la misma carpeta. El riesgo real aparece
**después**, el día que empiecen a crear migraciones dentro de
`verticals/agencia-viajes/` que dependan de tablas de `core/` (ej. una
tabla `cotizaciones` con FK a `clients`) — ahí si `tenants:provision` no
corre `core/` completo antes de `verticals/`, falla. El checklist ya
contempla el orden correcto (core primero, vertical después).

### 5.2 Valor de `giro` para tenants existentes — ✅ RESUELTO (23-jul-2026)

**`giro = 'retail'`**, con comentario en la migración para que quede
claro qué significa a simple vista (no autoexplicativo solo por el
nombre de la columna):

```php
$table->string('giro')->default('retail')
    ->comment('Giro de negocio del tenant. retail = facturación
    electrónica estándar (el core original, sin vertical específico).
    agencia_viajes y futuros verticales usan su propio valor.');
```

Se eligió `retail` (no `facturacion_electronica` ni `general`) porque
es el término que ya usa `arquitectura-multitenant-backend.md` para
describir el giro implícito de estos tenants, y mantiene el mismo
patrón de nombres cortos en snake_case que `agencia_viajes`. El
`default('retail')` en la migración cubre el backfill automático de
los tenants existentes sin necesitar un `UPDATE` manual aparte — todos
los tenants de hoy quedan con `giro=retail` apenas corre la migración.

### 5.3 ¿Contra qué tenant probar el provisioning? — ✅ RESUELTO (23-jul-2026)

**Se crea un tenant nuevo y dedicado**, no se reutiliza `sandbox`.
Razón: `sandbox` ya tiene historial real de pruebas de facturación y
del panel superadmin (backups, invoices, subscriptions de B.2.3-B.2.5,
Fase C/D/E) — mezclar ahí las primeras pruebas del vertical de agencia
de viajes generaría ruido difícil de distinguir después ("¿este dato es
de la prueba de facturación o de la prueba de agencia viajes?"), y
arriesga interferir con datos que otras pruebas ya dependen de tener en
un estado conocido.

**Nombre sugerido:** algo identificable como prueba del vertical, ej.
`agenciatest` o `viajestest` (mismo patrón de nombres descartables ya
usado en Fase E: `archtest*`, `faseetest*`). Se crea con
`giro=agencia_viajes` desde el inicio (no hace falta crearlo como
`retail` y migrarlo después) — es exactamente el caso que este módulo
necesita validar de punta a punta: que `tenants:provision` sepa correr
`core/` + `verticals/agencia-viajes/` (vacía, pero el mecanismo debe
funcionar igual) para un giro nuevo.

Al ser un tenant de prueba descartable, puede combinarse directamente
con el mecanismo `tipo=demo`/`sunat_modo=pruebas` ya definido en el
módulo 11 (3.1.c) — de hecho, este podría ser la primera vez que ese
mecanismo se usa en la práctica, aunque su propósito ahí es distinto
(vitrina reutilizable para prospectos, no prueba técnica de
provisioning). Quedan como conceptos relacionados pero no se fusionan:
este tenant de prueba de Fase 0 se descarta al terminar de validar el
mecanismo; el tenant demo del módulo 11 se conserva y resetea.

## 6. Conexión con el módulo 11 (ya reconciliado)

El campo `giro` en `tenants` va a convivir con `tenants.tipo`
(`demo`/`real`) y `tenants.sunat_modo` que ya definimos en el módulo 11
(sección 3.1.c de `plan-modulo-planes-acceso.md`) — mismo wizard de
creación, misma migración de `tenants`, misma tabla central. Conviene
que ambas migraciones (`giro` + `tipo`/`sunat_modo`) se agreguen juntas
en una sola sesión de trabajo para no tocar `tenants` dos veces.

Importante: `tenants:provision` de este módulo y `tenant_demo:reset`
del módulo 11 son comandos relacionados pero distintos — el primero
crea un tenant desde cero corriendo migraciones, el segundo trunca
tablas transaccionales de un tenant demo ya existente. No se
reimplementa uno sobre el otro, pero comparten la lógica de "qué
carpetas de migraciones le corresponden a este giro".

---

## 7. Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 23-jul-2026 | Primera versión: checklist trasladado del plan general, conexión con módulo 11 documentada, preguntas abiertas sobre inventario real de migraciones y tratamiento de tenants existentes |
| 23-jul-2026 | Inventario real verificado contra capturas del repo. Corrección importante: la separación central/tenant ya existe (`database/migrations/` vs `database/migrations/tenant/`); el trabajo real es separar core/verticals **dentro** de `tenant/`. Confirmado que las 76 migraciones actuales son 100% facturación/retail, ninguna de vertical — se mueven todas juntas a `tenant/core/` sin necesidad de clasificar caso por caso. Descartada colisión con tablas `systems`/`plans`/`system_modules` (confirmado: contenido de marketing, sin relación con acceso de tenants). Riesgo de orden de migraciones recalificado como bajo para este movimiento inicial. |
| 23-jul-2026 | Gap 5.2 resuelto: `giro='retail'` como default en la migración, con comentario explicativo en la columna. Cubre backfill automático de tenants existentes sin `UPDATE` manual aparte. |
| 23-jul-2026 | Gap 5.3 resuelto: se crea un tenant nuevo y dedicado (ej. `agenciatest`) con `giro=agencia_viajes` desde el inicio, no se reutiliza `sandbox` — decisión explícita del usuario para no mezclar el historial de pruebas de facturación/panel superadmin con las primeras pruebas del vertical nuevo. **Con esto, toda la sección 5 queda cerrada — módulo 0 listo para pasar a ejecución.** |
| 27-jul-2026 | Corrección de conteo: el "~76" era una estimación; conteo real verificado contra el repo es 67 migraciones en tenant/. No cambia ninguna decisión de este documento, solo el número exacto citado en 3 lugares. |
