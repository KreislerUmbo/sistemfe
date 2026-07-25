# Sub-plan — Módulo Planes y Control de Acceso por Módulo (feature gating)

> Parte de: `plan-general-vertical-agencia-viajes.md` — Módulo 11
> Estado: modelo base definido, con gaps identificados antes de pasar a
> ejecución (ver sección 3)
> Última actualización: 22-jul-2026

---

## 1. Qué problema resuelve

Distintos tenants de agencia de viajes contratan distintos niveles de
servicio (ej. económico S/80, estándar S/100, pro S/150), y cada nivel
habilita un set distinto de funcionalidad ya existente en el sistema
(cotizaciones simples vs con mayoristas, pagos a proveedores,
amortizaciones, liquidaciones, envíos por WhatsApp/correo, avisos de
pasaporte por vencer, felicitaciones de cumpleaños, etc.), más la
posibilidad de contratar módulos sueltos como add-on fuera del plan base
(ej. "facturador" +S/20 para una agencia que opera solo su parte interna
y factura con otro sistema).

**Distinción clave respecto al campo `giro`/`vertical` (módulo 0):**
`giro` decide qué tablas EXISTEN en la BD del tenant (fijo, se resuelve
con migraciones al aprovisionar). `plan` decide qué funcionalidad YA
EXISTENTE se puede usar (dinámico, cambia sin migraciones cuando el
tenant sube/baja de plan o compra un add-on).

---

## 2. Modelo de datos definido hasta ahora

Vive en la base central (`db_tenant_central`), junto a la tabla `tenants`.

```
planes
 - id, nombre (económico/estándar/pro), precio, descripcion

modulos ← catálogo maestro de funcionalidades
 - id, codigo (cotizaciones_mayoristas, pagos_proveedores,
 amortizaciones, liquidaciones, envio_whatsapp, envio_correo,
 aviso_pasaportes_vencer, felicitaciones_cumpleanos, facturador...)
 - nombre, descripcion
 - giro (opcional — algunos módulos son exclusivos de un vertical)

plan_modulo (pivote)
 - plan_id, modulo_id

tenant_modulo_overrides
 - tenant_id, modulo_id
 - habilitado: true | false
 (true = add-on agregado fuera del plan base; false = desactiva un
 módulo que el plan sí incluye)
 - precio_adicional (opcional, informativo)
 - notas (opcional)

tenants
 - ... (ya tiene giro)
 - plan_id
```

**Actualizado 25-jul-2026 — ver `plan-modulo-cotizaciones-reservas.md`
§8bis:** `aviso_pasaportes_vencer` y `felicitaciones_cumpleanos` estaban
catalogados acá como códigos de módulo, pero nunca se había diseñado el
mecanismo que los hace funcionar. Ya está resuelto — ambos son
disparadores del **sistema de recordatorios/notificaciones en-app**
diseñado en esa sesión (`recordatorios`/`tipos_recordatorio`), junto con
dos disparadores nuevos que no estaban contemplados aquí: aviso de pago
próximo a proveedor/mayorista y cotización estancada. El feature-gating
en sí (si el tenant tiene o no ese módulo habilitado según su plan) sigue
siendo responsabilidad de este documento — lo que cambia es que ahora
existe el motor real detrás del código de módulo.

**Regla de resolución de acceso efectivo:**
```
módulos_efectivos(tenant) =
 módulos del plan del tenant (via plan_modulo)
 + overrides con habilitado = true
 − overrides con habilitado = false
```
Resuelto por middleware con caché por tenant, apoyado en la
infraestructura de Spatie Permission ya usada en el stack.

**Dependencia con otros módulos:**
- El cotizador online del **Portal web (módulo 10)** debe consultar este
  motor antes de ofrecer una opción al cliente final (ej. si el plan no
  incluye "cotizaciones con mayoristas", esa opción no aparece en el
  cotizador público de esa agencia).

---

## 3. Qué falta antes de llevarlo a ejecución (evaluación de Claude)

Esto es una opinión basada en cómo suelen resolver este problema otros
sistemas SaaS multi-tenant con planes (no son requisitos de la agencia
todavía — son huecos que conviene cerrar con ellos antes de programar,
para no tener que alterar `tenants` después):

### 3.1 Ciclo de vida de la suscripción (el hueco más importante)
El modelo actual asume que `tenant.plan_id` es un estado fijo, pero en la
práctica un plan tiene ciclo de vida: fecha de inicio, fecha de
vencimiento/renovación, qué pasa si no paga. Falta decidir:
- `tenants.plan_vigente_hasta` (fecha)
- `tenants.estado_suscripcion`: activo | por_vencer | suspendido |
  cancelado
- Si un tenant no paga, ¿se bloquea completamente, o pasa a un modo
  "solo lectura" (puede ver sus datos pero no crear cotizaciones nuevas)?
  Esto es una decisión de negocio, no técnica, pero cambia el diseño del
  middleware de acceso.
- Historial de cambios de plan (`tenant_plan_historial`) — útil para
  soporte/facturación interna de la propia empresa dueña del sistema
  ("¿desde cuándo está en plan pro esta agencia?").

### 3.2 Cómo se le cobra a la agencia (no cómo la agencia cobra a sus clientes)
Ojo: esto es distinto de la facturación SUNAT que ya maneja el core (esa
es la agencia facturando a SUS clientes). Acá falta resolver cómo la
empresa dueña del sistema le cobra la mensualidad a cada agencia-tenant
— manual (alguien registra el pago) vs. automatizado con pasarela. No
bloquea el diseño de módulos, pero si se automatiza más adelante,
convendría dejar el campo `tenants.estado_suscripcion` ya pensado desde
ahora para no reestructurar.

### 3.3 Módulos con límites de uso, no solo on/off
Varios sistemas similares no solo activan/desactivan un módulo — también
limitan cantidad (ej. "hasta 50 cotizaciones al mes en plan económico",
"hasta 3 usuarios del sistema"). Esto es común en agencias pequeñas
donde el diferenciador de precio no es solo qué módulos ve, sino cuánto
puede usar. **Pregunta abierta para la agencia:** ¿los planes que
mencionaste son puramente por funcionalidad, o también van a tener
límites de cantidad (usuarios, cotizaciones/mes, etc.)? Si la respuesta
es sí, `modulos` necesita un campo de tipo (`booleano` vs `con_limite`) y
una tabla de contadores de uso — mejor confirmarlo ahora que después.

### 3.4 Dependencias entre módulos
Ej. ¿tiene sentido que un tenant tenga "liquidaciones" habilitado sin
tener "pagos a proveedores"? Si hay dependencias reales del negocio entre
módulos, conviene declararlas explícitamente (`modulo_requiere`) para
evitar estados inconsistentes vendidos por error desde el panel
superadmin.

### 3.5 Cómo se comunica al usuario un módulo no disponible
No es solo ocultar el menú — vale la pena decidir si, al toparse con un
módulo no incluido en su plan, el usuario ve simplemente nada, o ve un
mensaje tipo "esta función está en el plan Pro" con opción de contactar
para subir de plan (upsell). Afecta al frontend (módulo 9), no al
backend, pero conviene decidirlo junto con este módulo.

### 3.6 Integración con el panel superadmin
El plan contratado y sus módulos/add-ons se van a gestionar desde el
panel superadmin (`plan-panel-superadmin.md`, proyecto aparte, ya
mencionado en la arquitectura base) — el wizard de creación/edición de
tenant ahí necesita, además del selector de giro, un selector de plan y
la gestión de add-ons. No es trabajo de este módulo, pero es el
consumidor principal de esta tabla.

---

## 4. Lo que SÍ está listo para pasar a Claude Code ya mismo

- El modelo base de `planes` / `modulos` / `plan_modulo` /
  `tenant_modulo_overrides` / `tenants.plan_id` — es correcto y no
  debería requerir rediseño, sea cual sea la respuesta a los puntos de
  la sección 3.
- El middleware de resolución de acceso efectivo (la fórmula ya está
  clara), aunque su alcance real (bloquear vs mostrar upsell) depende de
  3.5.

**Recomendación:** se puede empezar a programar el modelo de datos base
(sección 2) sin esperar a resolver la sección 3, siempre que se dejen
los campos de suscripción (3.1) contemplados desde el diseño de la
migración de `tenants` — aunque no se implemente la lógica de vencimiento
todavía, es mucho más barato agregar la columna ahora (vacía/nullable)
que hacerlo con tenants ya en producción.

---

## 5. Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 22-jul-2026 | Primera versión: modelo base de planes/módulos/overrides + evaluación de gaps (ciclo de vida de suscripción, límites de uso, dependencias entre módulos, comunicación de upsell) |
| 25-jul-2026 | Se agrega nota cruzada: `aviso_pasaportes_vencer` y `felicitaciones_cumpleanos` ya tienen su motor diseñado (sistema de recordatorios/notificaciones en-app, ver `plan-modulo-cotizaciones-reservas.md` §8bis) — el feature-gating de si el tenant lo tiene habilitado sigue siendo responsabilidad de este documento, sin cambios en el modelo de acá. |
