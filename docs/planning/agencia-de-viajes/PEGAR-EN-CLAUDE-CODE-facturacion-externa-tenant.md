# Brief para Claude Code — Facturación externa por tenant + por reserva

> Pégale este archivo completo a una sesión nueva de Claude Code, **aparte**
> de la de 11u/11v (no la mezcles con esa, es un tema distinto: modelo de
> negocio del tenant, no lógica de facturación en sí). Puede correr antes,
> después, o en paralelo a 11v — no depende de ella para funcionar, aunque
> si 11v ya está mergeada, aprovecha para aplicar el guard nuevo también
> sobre el endpoint de facturación múltiple, no solo el de 11u.
>
> Contexto de diseño completo (léelo si necesitas más fondo, no es
> obligatorio para ejecutar este brief):
> `docs/planning/agencia-de-viajes/plan-modulo-planes-acceso.md` §1 (ya
> anticipaba un módulo "facturador" contratable aparte, nunca construido)
> y `docs/planning/arquitectura-multitenant-backend.md` (patrón core +
> verticals — el core de `Sale`/`SaleController` corre siempre para
> cualquier tenant, sin importar su giro).

**Problema real (conversación con el usuario, 20-ago-2026):** el sistema
de agencia de viajes va a tener tenants con dos modelos de negocio
distintos, no solo el de la agencia principal:

1. **Paquete completo** — cotizaciones/reservas/salidas + facturación
   electrónica en la misma plataforma (lo que ya construyó 11u/11v).
2. **Solo operativo** — la agencia usa el sistema para
   cotizar/reservar/operar, pero ya tiene su propio sistema de
   facturación electrónica aparte (u otro proveedor SUNAT) y no quiere
   pagar/usar ese módulo en esta plataforma.

Y el negocio necesita que un tenant pueda **moverse entre ambos modelos
en cualquier momento** — empezar solo operativo y migrar a facturación
completa más adelante, o al revés (empezó facturando con nosotros, migra
a un sistema más barato después) — sin perder ni tocar el historial de
comprobantes ya emitidos.

**Decisión de alcance confirmada con el usuario:** NO se construye el
motor completo de módulos/planes de `plan-modulo-planes-acceso.md`
(`modulos`/`plan_modulo`/`tenant_modulo_overrides`/`modulo_requiere`) —
eso sigue pendiente como proyecto aparte, más grande, cross-vertical. Acá
solo se resuelve el caso puntual con un flag simple.

---

## 1. Migración en `tenants` (central)

```php
Schema::table('tenants', function (Blueprint $table) {
    $table->boolean('facturacion_habilitada')->nullable();
});
```

**Nullable a propósito, sin default silencioso** — mismo criterio que
`tipo`/`sunat_modo` (`plan-modulo-infraestructura-multitenant.md` §3.1.c):
es un selector obligatorio en el wizard de creación de tenant, no algo
que se asuma. Antes de escribir la migración, **confirma en el código
real** cómo quedó resuelto el backfill de `tipo`/`sunat_modo` para
tenants existentes (¿qué valor les puso esa migración?) y aplica el
mismo criterio acá para no dejar tenants ya provisionados en un estado
inconsistente — probablemente: todos los tenants existentes de giro
`agencia_viajes` que YA facturaron algo (tienen `reserva_ventas`/`Sale`
reales) deberían quedar `facturacion_habilitada = true` en el backfill;
confírmalo con una query antes de decidir el valor, no asumas.

---

## 2. Migración en `reserva` (tenant, vertical agencia-viajes)

```php
Schema::table('reservas', function (Blueprint $table) {
    $table->boolean('facturacion_externa')->default(false);
    $table->string('referencia_externa')->nullable();
    $table->date('fecha_facturacion_externa')->nullable();
});
```

---

## 3. Reglas de negocio

### 3.1 `facturacion_habilitada` (nivel tenant)

- Se pregunta en el wizard de creación de tenant del panel superadmin,
  mismo nivel visual que `tipo`/`sunat_modo` — sin valor por defecto
  seleccionado.
- Editable después, en cualquier momento, desde la vista de detalle de
  tenant en el panel superadmin (nuevo campo/toggle, confirma con el
  usuario en qué tab de los 6 existentes tiene más sentido — probablemente
  el mismo tab que ya tiene `tipo`/`sunat_modo`, o el de Suscripción).
- **Apagar el flag NO afecta nada histórico.** No se ocultan ni bloquean
  `Sale`/`SaleDetail` ya creados, ni Cuentas por Cobrar, ni Notas de
  Crédito sobre comprobantes ya emitidos — todo eso es del core, corre
  siempre, no depende de este flag. Confírmalo leyendo cómo se resuelve
  el acceso a esas pantallas hoy (no deberían tener ningún chequeo nuevo
  que agregar).
- **Lo único que el flag controla:** si aparece la acción de facturar una
  reserva que **todavía no tiene ningún `Sale`** — tanto el botón
  "Facturar" en `reservas/detalle.vue` como los endpoints
  `GET reservas/{id}/preparar-factura` / `POST reservas/{id}/facturar`
  de 11u (y sus equivalentes de 11v si ya existen). Mismo patrón "sin
  upsell, doble capa" que ya usa el sistema para módulos (§3.5 de
  `plan-modulo-planes-acceso.md`): el backend no devuelve la acción en
  la respuesta de detalle de reserva si `facturacion_habilitada = false`,
  Y el endpoint la rechaza igual con 403 si se llama directo.
- Registrar el cambio de este flag en `central_audit_logs` (ya existe,
  reutilízalo) — es una decisión de modelo de negocio del tenant, vale
  la pena que quede trazado quién lo cambió y cuándo.

### 3.2 `facturacion_externa` (nivel reserva)

- Editable por el vendedor **solo si la reserva no tiene ninguna fila
  activa en `reserva_ventas` todavía** (confirmado con el usuario —
  evita el caso confuso de una reserva medio facturada acá, medio
  afuera). Si ya tiene un `Sale`, el campo queda bloqueado/no editable —
  422 explícito si se intenta cambiar vía API directa.
- **Reversible mientras no haya `Sale`:** el vendedor puede marcarlo y
  luego desmarcarlo libremente (ej. se equivocó, o cambió de opinión
  sobre dónde facturar esa reserva puntual) — no es un camino de una sola
  vía.
- Al marcarlo `true`, se habilita un formulario simple: `referencia_externa`
  (texto libre) + `fecha_facturacion_externa` — ambos opcionales, es solo
  anotación para trazabilidad, no valida nada contra ningún sistema
  externo.
- **Independiente de `facturacion_habilitada` del tenant** — funciona
  igual en los dos casos:
  - Tenant con `facturacion_habilitada = false`: el vendedor usa este
    campo para anotar referencia de lo que facturó afuera, en cualquier
    reserva.
  - Tenant con `facturacion_habilitada = true`: el vendedor puede
    igual marcar una reserva puntual como `facturacion_externa` (caso de
    transición o excepción — ej. ya la facturó afuera antes de migrar del
    todo), sin que eso afecte al resto de reservas del tenant.

### 3.3 Interacción con el botón "Facturar"

En `reservas/detalle.vue`:
- Si `facturacion_externa = true` para esa reserva → se muestra el
  formulario de referencia externa en vez del flujo de 11u/11v,
  independientemente del flag del tenant.
- Si `facturacion_externa = false` y `facturacion_habilitada` del tenant
  es `false` → no se muestra ninguna acción de facturación (ni el botón
  "Facturar" ni el formulario de referencia externa se ofrecen
  automáticamente) — **pero sí debe quedar accesible la opción de marcar
  `facturacion_externa = true` manualmente**, para el caso de la agencia
  que factura 100% afuera y quiere igual anotar su referencia.
- Si `facturacion_externa = false` y `facturacion_habilitada` del tenant
  es `true` → botón "Facturar" normal (11u/11v).

---

## 4. Panel superadmin — wizard y detalle de tenant

- Wizard de creación: agrega el selector `facturacion_habilitada` (sí/no,
  sin default marcado), mismo componente/patrón visual que `tipo`/
  `sunat_modo` ya usa.
- Vista de detalle de tenant: toggle editable para cambiar
  `facturacion_habilitada` después de creado, con confirmación (modal
  simple, no hace falta nada elaborado) que explique en una frase que
  esto no afecta comprobantes ya emitidos — para que quien lo opere desde
  soporte no dude si es una acción destructiva.

---

## 5. Fuera de alcance de esta sesión

- El motor completo de módulos/planes (`modulos`/`plan_modulo`/
  `tenant_modulo_overrides`/`modulo_requiere`) de
  `plan-modulo-planes-acceso.md` — sigue como proyecto aparte.
- Cualquier vínculo con `tenant_subscriptions`/cobro real del add-on
  "facturador" — el flag es una acción manual del superadmin por ahora,
  no está atado a un ciclo de facturación/pago automático.
- Reportes que agrupen "reservas facturadas externamente" vs
  "internamente" — el campo queda disponible para eso, pero construir el
  reporte en sí es otra sesión (probablemente parte de 11e, el reporte
  operativo).

---

## 6. Verificación esperada

- Tenant nuevo con `facturacion_habilitada = false`: la reserva no
  ofrece el botón "Facturar", pero sí permite marcar
  `facturacion_externa = true` con referencia.
- Tenant con `facturacion_habilitada = true`: flujo de 11u/11v funciona
  igual que antes de este cambio (sin regresión) — y además una reserva
  puntual puede marcarse `facturacion_externa = true` como excepción, sin
  afectar a las demás.
- Reserva con `Sale` activo: intentar cambiar `facturacion_externa` vía
  API directa devuelve 422.
- Cambiar `facturacion_habilitada` de `true` a `false` en un tenant con
  `Sale` históricos ya emitidos: confirmar que el historial de Ventas,
  Cuentas por Cobrar y Notas de Crédito de esos `Sale` sigue 100%
  accesible sin cambios — este es el caso que más le importa verificar al
  usuario, no lo des por sentado, pruébalo de punta a punta contra datos
  reales o un tenant de prueba con al menos un `Sale` ya emitido.
- Backfill de tenants existentes revisado con una query real antes de
  aplicar la migración (§1), no asumido.
- Suite de tests en verde, incluidos casos nuevos para los puntos de
  arriba.