# Sub-plan — Módulo Planes y Control de Acceso por Módulo (feature gating)

> Módulo 11 (el documento raíz que lo indexaba,
> `plan-general-vertical-agencia-viajes.md`, ya se archivó — ver
> `historial-archivo.md`).
> Estado: modelo reconciliado con el Panel Superadmin (ya construido,
> ver `panel-superadmin/plan-panel-superadmin.md` — stub corto, detalle
> completo en su `historial-archivo.md`) el 23-jul-2026. Gran parte de
> 3.1 y 3.1.a ya estaba implementada bajo otros nombres — se descartó el
> diseño propio y se documentó cómo reutilizar lo existente. Tabla
> `planes` fusionada con `tenant_plans` (ya existente). Todos los gaps
> de la sección 3 (3.1 a 3.6) **resueltos a nivel de diseño** — no
> confundir con "construido": 3.1.c (mecanismo de tenant demo/real) y
> 3.4/3.5 (feature-gating de módulos en sí) quedaron marcados
> explícitamente como "trabajo genuinamente nuevo, sin conflicto" (ver
> abajo) — **no verificado en esta ronda de limpieza de documentación
> (20-ago-2026) si ya se construyeron o siguen pendientes**, confirmar
> antes de asumir cualquiera de los dos estados.
> Nota: el mecanismo de tenant demo/real (3.1.c) sigue siendo genérico
> para toda la plataforma y es trabajo nuevo real, sin conflicto con lo
> ya construido — ver referencia cruzada en
> `arquitectura-multitenant-backend.md`.
> Última actualización: 23-jul-2026

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

## 2. Modelo de datos

> **⚠️ Reconciliado el 23-jul-2026 con `plan-panel-superadmin.md`** (sistema
> ya construido y en producción, Fases 0-E cerradas). La versión original
> de este documento definía una tabla `planes` propia; se detectó que
> cumple la misma función organizadora que la tabla `tenant_plans` ya
> existente (el nivel de suscripción determina tanto límites de uso como
> funcionalidad), así que **se fusionan** — no hay tabla `planes` nueva.
> Ver sección 3.1 para el detalle completo de la reconciliación.

Vive en la base central (`db_tenant_central`), junto a la tabla `tenants`
y `tenant_plans` (ya existente, gestiona límites de usuarios/comprobantes-
mes/storage y precio).

```
tenant_plans                  ← YA EXISTE, no se crea de nuevo
 - id, nombre, precio, límites (usuarios, comprobantes/mes, storage)

modulos                       ← catálogo maestro de funcionalidades (NUEVO)
 - id, codigo (cotizaciones_mayoristas, pagos_proveedores,
   amortizaciones_cliente, amortizaciones_proveedor, liquidaciones,
   envio_whatsapp, envio_correo, aviso_pasaportes_vencer,
   felicitaciones_cumpleanos, facturador...)
 - nombre, descripcion
 - giro (opcional — algunos módulos son exclusivos de un vertical)

plan_modulo (pivote, NUEVO)
 - tenant_plan_id (FK a tenant_plans.id, NO a una tabla "planes" propia)
 - modulo_id

tenant_modulo_overrides (NUEVO)
 - tenant_id, modulo_id
 - habilitado: true | false
     (true = add-on agregado fuera del plan base; false = desactiva un
     módulo que el plan sí incluye)
 - precio_adicional (opcional, informativo)
 - notas (opcional)
```

`tenants` no necesita columna `plan_id` propia — el plan del tenant se
resuelve vía `tenant_subscriptions.tenant_id` → `tenant_subscriptions.plan_id`
→ `tenant_plans` (relación que ya existe en el sistema construido).

**Regla de resolución de acceso efectivo:**
```
módulos_efectivos(tenant) =
   módulos del tenant_plan vigente (via tenant_subscriptions → plan_modulo)
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

### 3.1 Ciclo de vida de la suscripción — ✅ RESUELTO, reconciliado con `plan-panel-superadmin.md` (23-jul-2026)

> **Importante:** la primera versión de esta sección (23-jul, mañana)
> diseñaba un sistema propio (`estado_suscripcion` de 5 estados,
> `plan_vigente_hasta`, `tenant_plan_historial`, `configuracion_sistema`)
> **sin saber que ya existe un sistema equivalente construido y probado**
> en `plan-panel-superadmin.md` (Fases B.0.5, B.2.1-B.2.5, D, cerradas).
> Esta versión reemplaza aquella — no se implementa nada nuevo de ciclo
> de vida, se **reutiliza lo ya construido**.

**No hay estados nuevos que agregar.** `tenants.status`
(`activo`/`suspendido`/`archivado`) ya cubre lo necesario. Lo que se
había pensado como estados intermedios `por_vencer`/`gracia` ya está
resuelto de forma más limpia: son **checkpoints calculados sobre
fechas**, no estados persistidos —

```
checkpoint 1 (recordatorio)     → invoice pasa a estado 'vencido'
checkpoint 2 (aviso de gracia)  → intdiv(dias_gracia, 2) días vencido
checkpoint 3 (suspensión)       → dias_gracia completo, tenants.status → suspendido
```

**`dias_gracia` ya es por tenant, no una constante global.**
`tenant_subscriptions.dias_gracia_suspension` (nullable), con default
en `platform_settings.dias_gracia_suspension_default` (60 sembrado).
Se resuelve exactamente igual a como lo pedías: agencia de confianza →
override alto, agencia sin historial → usa el default.

**Vencimiento: el sistema ya construido vence el día `dia_corte`
(28) literal, no fin de mes calendario — decisión confirmada de
mantenerlo así (23-jul-2026).** `fecha_vencimiento` se calcula desde
`dia_corte` con clamp al último día real del mes (relevante solo para
`dia_corte` altos como 30/31 en meses cortos). Con el default 28, el
clamp nunca se activa: siempre vence el 28. Se decidió **no ajustar**
`TenantInvoiceService` para que corra hasta fin de mes — queda tal cual
está implementado y verificado.

**Notificaciones automáticas — ya implementadas**, no son una
recomendación pendiente: 3 Mailables (`InvoiceOverdueReminderMail`,
`InvoiceGraceMidpointWarningMail`, `TenantSuspendedForNonPaymentMail`),
con `MAIL_MAILER=log` en este entorno (no entrega real todavía, fuera
de alcance configurar SMTP). Un fallo de envío nunca bloquea la
transición de estado real (try/catch que solo reporta).

**Idempotencia — ya implementada** en `tenants:generate-monthly-invoices`
y `tenants:check-overdue-payments`, ambos wrappers delgados sobre
servicios (`TenantInvoiceService`, `TenantOverduePaymentService`) que
validan existencia previa antes de crear/transicionar.

**Mensaje de suspensión — usar `platform_settings` (ya existe como
key-value config global), no crear tabla `configuracion_sistema`.**
Agregar ahí las keys `telefono_soporte` y `mensaje_suspension`.

**Cobro manual/automático — ya cubierto por
`tenant_subscriptions.facturacion_automatica` (bool).** No se crean
`tenants.metodo_pago`/`referencia_pago_externa`; si más adelante se
integra una pasarela real, el campo de referencia externa se agrega
ahí mismo, sobre `tenant_subscriptions`, no sobre `tenants`.

**Historial de cambios de plan — cubierto en la práctica por
`tenant_invoices` + `central_audit_logs`**, no se crea
`tenant_plan_historial` como tabla separada.

**Gap real documentado en el propio B.2.3, todavía sin resolver ahí:**
`generarMensualParaActivas()` filtra `facturacion_automatica=true` sin
mirar `tenants.status` — un tenant archivado debería cancelar su
suscripción aparte, y hoy no está automatizado. No es un gap de este
sub-plan, es un pendiente ya anotado en `plan-panel-superadmin.md`
mismo; se deja la referencia acá para que no se pierda.

#### 3.1.a — Archivar tenant ≠ Eliminar tenant (terminología: "archivado", no "cancelado")

**Ya implementado tal cual, sin necesidad de construir nada nuevo.**
Se usa el término **"archivado"** de aquí en adelante (no "cancelado" —
evitar el sinónimo inventado para lo mismo).

| | **Archivar** | **Eliminar tenant** |
|---|---|---|
| Qué hace | `TenantProvisioningService::archivar()` — cambia `tenants.status` a `archivado` | Borra físicamente la BD del tenant |
| Toca la BD del tenant | No, nunca — solo `status`/`fecha_archivado` | Sí — acción destructiva e irreversible |
| Reversible | Sí — `TenantProvisioningService::restaurar()` | No |
| Quién la dispara | Manual desde el panel superadmin | Solo manual, deliberadamente estrecho |
| Guard aplicable | Ninguno — es solo un estado | **Sí**: `eliminarSiVacio()` — verifica que no queden productos reales (excluyendo el placeholder `ADELANTO-001` sembrado por migración en todo tenant nuevo). No es un chequeo directo de comprobantes SUNAT, pero da el mismo resultado en la práctica: si nunca facturó, tampoco tiene productos reales cargados. |

Ya verificado con evidencia real: ciclo completo archivar→(rechazo de
doble archivado)→restaurar→(rechazo de restaurar sin estar archivado),
con columna "Acciones" por fila en el panel (Archivar/Restaurar según
`status`, Eliminar con su propio guard).


#### 3.1.b — Recomendaciones adicionales (reconciliadas — algunas ya implementadas)

1. **Notificaciones automáticas — ✅ ya implementadas** en
   `plan-panel-superadmin.md` (ver 3.1). No hace falta la tabla
   `tenant_notificaciones_suscripcion` propuesta originalmente — el
   sistema real usa Mailables + la columna
   `tenant_invoices.aviso_gracia_enviado_at` para el checkpoint sin
   transición de estado propia. Se descarta esta recomendación tal como
   estaba escrita.

2. **Idempotencia del cron — ✅ ya implementada.** Los comandos reales
   (`tenants:generate-monthly-invoices`, `tenants:check-overdue-payments`)
   ya validan existencia previa antes de crear/transicionar, con el
   caso además ya cubierto de un bug real de Carbon 3.x corregido en
   verificación. Se descarta esta recomendación tal como estaba escrita.

3. **Dashboard de cartera en el panel superadmin** — sigue siendo
   backlog, no construido todavía. Con solo 3 estados reales
   (`activo`/`suspendido`/`archivado`) en vez de los 5 que se habían
   imaginado, la vista es más simple de lo previsto: agrupar por
   `status` + exponer los invoices `vencido` con su checkpoint actual
   (recordatorio/gracia) como indicador visual, sin necesitar un estado
   persistido nuevo.

4. **Tenant demo/real (zombie + trial) — ✅ RESUELTO, genuinamente
   nuevo, sin conflicto con lo ya construido.** Ver 3.1.c más abajo —
   esta pieza sí falta construirse, no existe nada parecido en
   `plan-panel-superadmin.md` (el único "demo" que ahí aparece es el
   certificado SUNAT de demostración, un concepto completamente
   distinto).

#### 3.1.c — Tenant demo/real (infraestructura genérica, no solo agencia de viajes)

**No es un embudo obligatorio.** El campo `tipo` es un selector directo
al crear el tenant, no un flujo secuencial demo→real. Un tenant que ya
sabe que va a producción se crea `tipo=real` desde el día uno, sin pasar
por demo. El demo es un atajo de venta para prospectos que quieren
probar antes de decidir — no un requisito de onboarding.

**Modelo: un único tenant demo, reutilizado y reseteado entre
prospectos** (no uno nuevo por cada prospecto que prueba).

**Campos nuevos en `tenants` (central), junto a los de 3.1:**
```
tenants
 - tipo: real | demo            -- selector obligatorio en el wizard,
                                    mismo nivel que giro; sin default
                                    silencioso
 - sunat_modo: pruebas | produccion
     -- 'pruebas' = usa el modo beta/sandbox de Greenter, nunca emite
        comprobantes con valor legal. Default 'pruebas' si tipo=demo.
```

**Por qué `sunat_modo` es la pieza clave:** el guard de "Eliminar tenant"
(`eliminarSiVacio()`, ya definido en 3.1.a) verifica productos reales,
no `sunat_modo` directamente — pero un tenant demo en `sunat_modo=pruebas`
nunca genera productos/operaciones reales por definición de uso, así
que en la práctica nunca choca con ese guard. El reseteo del demo usa
su propio comando (`tenant_demo:reset`, trunca directo, no pasa por el
flujo de "Eliminar tenant" del panel), así que no depende de ese guard
en absoluto — se puede resetear en cualquier momento sin restricción.

**Comando de reseteo, no eliminación:**
```
tenant_demo:reset   (comando artisan)
 - trunca tablas transaccionales (cotizaciones, reservas, comprobantes
   sandbox, pasajeros, etc.)
 - conserva catálogos base de ejemplo (tours plantilla, proveedores
   demo, destinos con fotos) para que el siguiente prospecto no
   arranque de una pantalla vacía
```

**Flujo de conversión (prospecto que sí decide usarlo en serio):**
```
Prospecto prueba en el tenant demo compartido
   → data ficticia libre, sunat_modo=pruebas

Decide usarlo de verdad
   → se aprovisiona un tenant NUEVO: tipo=real, sunat_modo=produccion
   → staff decide MANUALMENTE si copia algo puntual del trial (ej. un
     catálogo de tours que sí armó en serio) — copia selectiva, nunca
     automática ni masiva, para no arrastrar data de prueba con nombres
     inventados a producción
   → el tenant demo original se resetea (tenant_demo:reset) y queda
     disponible para el próximo prospecto — el mismo demo sirve para
     mostrar el sistema a otros prospectos, no se destruye
```

**Wizard del panel superadmin — mismo paso que `giro`:**
```
Crear tenant:
  Giro:  agencia_viajes / retail / veterinaria / publicidad / ...
  Tipo:  ○ demo   ○ real          ← obligatorio, sin default

php artisan tenants:provision --giro={giro} --tipo={demo|real}
```

Como es la misma tabla central y el mismo comando de provisioning para
cualquier giro, este mecanismo no se reimplementa por vertical — un
tenant demo de veterinaria usa exactamente el mismo campo `tipo` y el
mismo `tenant_demo:reset` (parametrizado por giro), sin lógica nueva.

### 3.2 Cómo se le cobra a la agencia — ✅ RESUELTO, reconciliado con lo ya construido
(Distinto de la facturación SUNAT que ya maneja el core — esa es la
agencia facturando a SUS clientes.) Ya cubierto por
`tenant_subscriptions.facturacion_automatica` (bool, default false) —
no se crean `tenants.metodo_pago`/`referencia_pago_externa` como se
había propuesto originalmente. Cuando se integre una pasarela real, el
campo de referencia externa se agrega sobre `tenant_subscriptions`
(mismo lugar que ya tiene `monto_mensual_override`), no sobre `tenants`.

### 3.3 Límite de usuarios por plan — ✅ RESUELTO, reconciliado con `tenant_plans` ya existente (23-jul-2026)

No hay límites de cotizaciones ni de ventas. El único límite de cantidad
es **usuarios del sistema**, configurable, con diferencia entre planes.
Los módulos siguen siendo puramente booleanos — no hace falta tabla de
contadores de uso ni campo `tipo` en `modulos`.

**`tenant_plans` ya tiene un límite de usuarios** (mencionado en su
descripción: "límites: usuarios, comprobantes/mes, storage") — no se
crea `planes.limite_usuarios` como se había propuesto originalmente.
Verificar el nombre exacto de esa columna en la migración real al
implementar; el diseño de acá asume que existe y solo falta el override
por tenant.

**Override por tenant — mismo lugar que `dias_gracia_suspension` y
`monto_mensual_override` (en `tenant_subscriptions`, no en `tenants`,
para ser consistentes con el patrón ya establecido):**
```
tenant_subscriptions
 - limite_usuarios_override   int, nullable
     -- para dar más/menos usuarios a un tenant puntual sin cambiarle
        el plan entero (mismo criterio que ya usan con monto_mensual_override)
```

**Límite efectivo:**
```
limite_usuarios(tenant) = tenant_subscriptions.limite_usuarios_override
                           ?? tenant_plans.<columna límite usuarios>
```

**Dónde se aplica — guard puntual, no middleware de rutas.** Solo se
chequea en el momento de crear un usuario nuevo:
```
al crear usuario:
    usuarios_activos_actuales = count(users WHERE tenant AND estado=activo)
    si usuarios_activos_actuales >= limite_usuarios(tenant):
        bloquear con mensaje: "Llegaste al límite de usuarios de tu
        plan (X). Contacta a soporte para ampliarlo."
    si no:
        permitir creación
```

**Confirmado con la agencia:**
- Cuenta **solo usuarios activos** — si se desactiva a un empleado que
  ya no trabaja ahí, el cupo se libera automáticamente sin necesidad de
  subir de plan. (Depende de que `users` en el core ya tenga un campo
  de estado activo/inactivo — verificar al implementar.)
- **El superadmin ve el uso actual** desde el panel (ej. "3/5 usuarios
  usados") — no se entera del límite solo cuando le rebota el error al
  crear el usuario #6. Este contador es de solo lectura (se calcula al
  vuelo con el `count()` de arriba), no requiere tabla de histórico.

### 3.4 Dependencias entre módulos — ✅ RESUELTO (23-jul-2026)

**Cambio al catálogo: `amortizaciones` se divide en dos módulos**, porque
en la práctica cubre dos escenarios con relación distinta a
`pagos_proveedores`:
```
modulos
 - amortizaciones_cliente     -- cuotas del cliente final hacia la
                                  agencia; independiente, sin dependencia
 - amortizaciones_proveedor   -- cuotas de la agencia hacia un mayorista/
                                  proveedor; SÍ depende de pagos_proveedores
```
Esta división también deja soportado, sin trabajo extra, vender solo una
de las dos en un plan (ej. plan económico con amortización de cliente
pero sin la de proveedor).

**Tabla de dependencias explícitas:**
```
modulo_requiere
 - modulo_id            -- el módulo que depende
 - modulo_requerido_id  -- el módulo del que depende
```
Con las dependencias confirmadas hasta ahora:
```
liquidaciones            requiere  pagos_proveedores
amortizaciones_proveedor requiere  pagos_proveedores
aviso_pasaportes_vencer  requiere  envio_correo   -- específicamente
felicitaciones_cumpleanos requiere envio_correo      correo, NO whatsapp
```
`cotizaciones_mayoristas`, `amortizaciones_cliente` y `facturador`
quedan sin dependencias — son independientes.

**Dónde se aplica el guard.** No es un chequeo en tiempo de uso
(middleware de rutas) — es una validación en el momento de **armar o
editar el plan/overrides desde el panel superadmin**: al intentar
habilitar un módulo que requiere otro no incluido, el sistema bloquea
la operación con mensaje explícito ("`liquidaciones` requiere
`pagos_proveedores`, actívalo primero"). Esto evita que se venda o
configure una combinación inconsistente desde el origen, en vez de
descubrirlo después con el tenant ya operando con una pantalla rota o
vacía.

**Caso borde a definir en implementación:** si un tenant tiene ambos
módulos activos y luego se **desactiva** el módulo requerido (ej. se le
quita `pagos_proveedores` por downgrade de plan), ¿se desactiva en
cascada el módulo dependiente (`liquidaciones`), o se bloquea el
downgrade hasta que el superadmin desactive primero el dependiente?
Recomiendo cascada automática con aviso, para no trabar operaciones de
downgrade por una dependencia que el superadmin quizás ni recuerde —
pero es una decisión de UX menor, no bloquea el modelo de datos.

### 3.5 Cómo se comunica al usuario un módulo no disponible — ✅ RESUELTO (23-jul-2026)

**Decisión: sin upsell, sin candados visibles.** El tenant solo ve los
módulos que tiene habilitados — nada de menú deshabilitado ni mensaje
de "esta función está en el plan Pro". Lo que no está en
`módulos_efectivos(tenant)` no existe para su interfaz.

**Doble capa de aplicación (no basta con ocultar en frontend):**
```
1. Al armar el menú/navegación → el backend solo entrega en la respuesta
   los módulos habilitados; los demás ni se listan.
2. En cada endpoint del módulo → el middleware de acceso efectivo sigue
   bloqueando aunque alguien intente llamar la ruta directamente sin
   pasar por el menú (defensa en profundidad — no basta con que el
   frontend no lo muestre).
```

**Consecuencia asumida:** no hay ningún gancho de upsell dentro de la
plataforma. Subir de plan depende enteramente de que ventas/soporte se
lo ofrezca por fuera del sistema (llamada, WhatsApp, etc.) — decisión
intencional, más simple de implementar, sin mecanismo automático de
"esto existe y podrías tenerlo".

### 3.6 Integración con el panel superadmin — checklist reconciliado (23-jul-2026)

> **Corrección importante:** la versión de esta mañana asumía que el
> panel superadmin era "un proyecto aparte" sin nada construido — al
> revisar `plan-panel-superadmin.md` directamente, resultó ser un
> sistema real, ya en producción, con Fases 0 a E cerradas. La mayoría
> del wizard de tenant, archivar/restaurar/eliminar, suscripciones e
> invoices **ya existe**. Este checklist queda ajustado a lo que
> realmente falta agregar ahí — no es una lista desde cero.

**Ya existe en el panel superadmin (no se construye de nuevo):**
- Wizard de alta de tenant (`TenantListView.vue` + `POST central/tenants`)
- Selector de `giro` en el wizard
- Botones Archivar/Restaurar/Eliminar con sus guards
- Vista de detalle de tenant (6 tabs: Company/Suscripción/Backups/
  SunatConfig/Certificado/Test-emission)
- Vista global de `central_audit_logs` con filtros
- Gestión de `tenant_subscriptions` (plan, `dias_gracia_suspension`,
  `monto_mensual_override`, `facturacion_automatica`)

**Falta agregar ahí (nuevo, aditivo sobre lo ya construido):**
- Selector `tipo: demo | real` en el wizard, mismo nivel que `giro`
  (3.1.c) — campo nuevo, wizard ya existe
- Gestión de `tenant_modulo_overrides` (add-ons) en la vista de detalle
  de tenant — nueva sección/tab
- Al habilitar un módulo, validar `modulo_requiere` (3.4) y bloquear si
  falta una dependencia
- Campo `limite_usuarios_override` editable, en el tab de Suscripción
  ya existente (3.3)
- Botón para `tenant_demo:reset`, no solo CLI (3.1.c)
- Dos claves nuevas editables en la configuración de plataforma
  (`telefono_soporte`, `mensaje_suspension`) — `platform_settings` ya
  tiene UI de key-value, solo se agregan las keys

**Backlog, no bloqueante:**
- Dashboard de cartera agrupado por `tenants.status` (3.1.b, punto 3)

**Fuera de alcance de este módulo, pero dependiente de él:**
- El cotizador del Portal web (módulo 10) ya tenía anotado que depende
  de este motor de módulos efectivos — sigue vigente, sin cambios.

---

## 4. Estado final — listo para pasar a Claude Code

Con la reconciliación de hoy contra `plan-panel-superadmin.md` (sistema
ya construido), **toda la sección 3 queda resuelta** — pero buena parte
del trabajo real ya existe, no hay que construirlo de nuevo. Esto es lo
que efectivamente falta programar:

**Ya existe, se reutiliza tal cual (nada nuevo que construir):**
- `tenants.status` (activo/suspendido/archivado), `tenant_subscriptions`
  (`dias_gracia_suspension`, `dia_corte`, `facturacion_automatica`,
  `monto_mensual_override`), `tenant_invoices`, checkpoints de
  notificación (3 Mailables), idempotencia de los comandos programados,
  `TenantProvisioningService::archivar()`/`restaurar()`, botón
  "Eliminar" con `eliminarSiVacio()`, `platform_settings` (key-value),
  `tenant_plans` (catálogo de planes con límite de usuarios).

**Nuevo — genuinamente falta construir:**
- `modulos`, `plan_modulo` (referenciando `tenant_plans.id`, no una
  tabla `planes` propia), `tenant_modulo_overrides`, `modulo_requiere`
  (3.4) — el catálogo completo de feature-gating no existe todavía.
- `tenant_subscriptions.limite_usuarios_override` (3.3) — columna nueva
  sobre una tabla existente, no tabla nueva.
- `tenants.tipo` (`demo`/`real`) y `tenants.sunat_modo`
  (`pruebas`/`produccion`) + comando `tenant_demo:reset` (3.1.c) —
  mecanismo completo, sin nada parecido hoy.
- Middleware de resolución de acceso efectivo por módulo (fórmula de la
  sección 2), incluyendo la regla de "sin upsell" (3.5): backend no
  lista módulos no habilitados, ni en menú ni por endpoint directo.
- Dos claves nuevas en `platform_settings`: `telefono_soporte`,
  `mensaje_suspension`.

**Descartado del diseño original de esta mañana** (redundante con lo ya
construido): `tenants.estado_suscripcion` de 5 estados,
`plan_vigente_hasta`, `tenants.dias_gracia`, `tenant_plan_historial`,
`configuracion_sistema` como tabla, `tenants.metodo_pago`/
`referencia_pago_externa`, tabla `planes` propia,
`tenant_notificaciones_suscripcion`.

**Alcance del panel superadmin (3.6)**: en gran parte ya construido —
lo que falta ahí es exponer en su UI el selector `tipo`/`sunat_modo` del
wizard, la gestión de `tenant_modulo_overrides`, y el guard de
`modulo_requiere` al armar un plan. Checklist original de 3.6 sigue
sirviendo como referencia, ajustado a lo que ya existe.

**Recomendación:** empezar por las piezas "genuinamente nuevas" listadas
arriba — son aditivas sobre el sistema ya construido, no requieren
tocar nada de lo que ya está en producción y probado.

---

## 5. Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 22-jul-2026 | Primera versión: modelo base de planes/módulos/overrides + evaluación de gaps (ciclo de vida de suscripción, límites de uso, dependencias entre módulos, comunicación de upsell) |
| 23-jul-2026 | Gap 3.1 resuelto y consolidado: máquina de estados de suscripción (activo/por_vencer/gracia/suspendido/cancelado), `plan_vigente_hasta` como fin de mes calendario, `dias_gracia` configurable por tenant, suspendido = bloqueo total con mensaje/teléfono vía `configuracion_sistema`, cobro manual con terreno preparado para pasarela, historial de planes. Agregada distinción formal cancelar vs eliminar tenant (3.1.a) con guard de comprobantes SUNAT. Gap 3.2 marcado como resuelto (cubierto dentro de 3.1). |
| 23-jul-2026 | Agregada sección 3.1.c: mecanismo de tenant demo/real, genérico para toda la plataforma (no exclusivo de agencia de viajes). Campos `tipo` y `sunat_modo` en `tenants`, comando `tenant_demo:reset`, flujo de conversión demo→producción vía tenant nuevo con copia selectiva manual. Fusiona y resuelve los puntos "tenants zombie" y "trial" que habían quedado abiertos en la recomendación 3.1.b. |
| 23-jul-2026 | Gap 3.3 resuelto: no hay límites de cotizaciones/ventas, solo límite de usuarios por plan (`planes.limite_usuarios` + `tenants.limite_usuarios_override`), guard puntual al crear usuario contando solo activos, visible para el superadmin en el panel. Los módulos siguen siendo puramente booleanos, sin tabla de contadores de uso. |
| 23-jul-2026 | Gap 3.4 resuelto: catálogo dividido en `amortizaciones_cliente` (independiente) y `amortizaciones_proveedor` (requiere `pagos_proveedores`). Confirmadas 4 dependencias vía tabla `modulo_requiere`: liquidaciones→pagos_proveedores, amortizaciones_proveedor→pagos_proveedores, aviso_pasaportes_vencer→envio_correo, felicitaciones_cumpleanos→envio_correo (específicamente correo, no whatsapp). Guard aplicado al configurar plan/overrides desde el superadmin, no en tiempo de uso. Pendiente decidir en implementación si la desactivación de un módulo requerido cascade al dependiente. |
| 23-jul-2026 | Gap 3.5 resuelto: decisión de no usar upsell — el tenant solo ve módulos habilitados, sin candados ni mensajes de "sube de plan". Aplicación en dos capas (backend no lista módulos no habilitados + middleware bloquea acceso directo por endpoint). Sección 3.6 documentada como checklist de alcance para `plan-panel-superadmin.md` (proyecto aparte), no como gap a resolver aquí. |
| 23-jul-2026 | **Reconciliación mayor con `plan-panel-superadmin.md`** (sistema ya construido y en producción, Fases 0-E cerradas, no un proyecto pendiente). Se descubrió solapamiento real: el ciclo de vida de suscripción diseñado en 3.1 (estados, gracia, historial) y la distinción cancelar/eliminar de 3.1.a ya estaban construidos bajo otros nombres (`tenants.status`, `tenant_subscriptions`, `TenantProvisioningService::archivar()/restaurar()`, checkpoints de notificación con Mailables, comandos idempotentes). Se descartó el diseño propio duplicado y se documentó cómo reutilizar lo existente. Terminología unificada a "archivado" (no "cancelado"). Confirmado explícitamente: el vencimiento sigue calculándose desde `dia_corte` (28) literal, no fin de mes calendario — se mantiene el sistema tal cual está construido. Tabla `planes` propia eliminada del diseño y fusionada con `tenant_plans` ya existente (`plan_modulo` ahora referencia `tenant_plans.id`); `planes.limite_usuarios` reemplazado por el límite ya existente en `tenant_plans`, con override movido a `tenant_subscriptions.limite_usuarios_override` (mismo patrón que `monto_mensual_override`/`dias_gracia_suspension`). `configuracion_sistema` reemplazada por `platform_settings` (ya existe). `metodo_pago`/`referencia_pago_externa` reemplazados por `tenant_subscriptions.facturacion_automatica`. Se confirma que 3.1.c (tenant demo/real) y 3.4/3.5 (feature-gating de módulos) son trabajo genuinamente nuevo, sin conflicto — nada parecido existe en el panel superadmin. |
