# Plan — Envío de la cotización por correo, desde el dominio de la agencia

> Estado: **CONSTRUIDO (14-oct-2026), sin desplegar** — rama `feat/envio-correo-cotizacion`.
> Etapas E1 (backend), E2 (frontend del tenant) y E3 (Superadmin) completas. Falta lo
> externo: registrar el dominio de la agencia en Brevo y que la agencia cargue sus DNS
> (§12), y desplegar. Decisiones D1-D6 tomadas con la recomendación (§10).
> Diseño original: 11-oct-2026.
> Requisito del usuario: el usuario de la agencia envía al cliente, por correo, el PDF de la
> cotización y el de condiciones generales; el correo sale **desde una dirección de la
> agencia**; el envío es **configurable por tenant**.
> Datos confirmados con el usuario: las agencias tienen **dominio propio**; Brevo está en
> **plan gratuito**.

---

## 1. Qué ya existe (punto de partida)

| Pieza | Dónde | Nota para este plan |
|---|---|---|
| Correo de producción | Brevo SMTP, `avisos@umbosystem.com` (`.env` de prod) | Una sola cuenta Brevo para todo el SaaS. Local: `MAIL_MAILER=log` |
| PDF de la alternativa | `AlternativaPdfService::generar($id)` | Devuelve una **respuesta HTTP** (descarga), no el contenido. Hay que separar "generar bytes" de "responder" |
| PDF de condiciones | `CondicionesGeneralesPdfService::generar()` | Igual: devuelve `->download(...)` |
| Correo del cliente | `cotizacion.cliente` → `clients.email` | Puede venir vacío: el modal debe permitir escribirlo |
| Estado "enviada" | `alternativas.estado` + `fecha_envio` (`AlternativaController::update()`) | Hoy se marca a mano. El envío real puede marcarlo (ver D4) |
| Colas | `QUEUE_CONNECTION=database`, `QueueTenancyBootstrapper` activo, worker `sistemafe-worker` en prod | **Ningún job del proyecto usa cola todavía** (los avisos de Créditos envían en el comando). Ver §5.4 |
| Mailables con contexto de tenant | `app/Mail/Creditos/*`, `TenantContactMailer` | Patrón a seguir |

---

## 2. Remitente: cómo sale "desde la agencia"

### 2.1 Por qué hace falta autenticar el dominio

Un correo con `From: ventas@agencia.com` enviado desde servidores que no son de esa agencia
falla las comprobaciones DMARC/SPF/DKIM: va a spam o lo rechazan. Brevo además no envía con
un remitente que no esté verificado (si el dominio no tiene DKIM válido, Brevo cambia el
remitente por uno `@brevosend.com`). La solución correcta es **autenticar el dominio de la
agencia en Brevo**: la agencia agrega 2 o 3 registros DNS (verificación de Brevo, DKIM y,
recomendado, DMARC), una sola vez.

Brevo permite hacer esto **por API** (`POST /v3/senders/domains` devuelve los registros DNS,
`PUT /v3/senders/domains/{dominio}/authenticate` verifica), así que se puede armar desde el
Panel Superadmin sin entrar a la consola de Brevo.

### 2.2 Las dos variantes posibles (decisión D1)

| | **V1. Cuenta Brevo de UmboSystem** (recomendada para empezar) | **V2. Cuenta Brevo propia de cada agencia** |
|---|---|---|
| Quién crea la cuenta | Nosotros (ya existe) | La agencia (gratis) |
| Alta del dominio | Superadmin, por API, desde el panel | La agencia en su consola de Brevo, o nosotros con su acceso |
| Credenciales a guardar | Ninguna nueva (la API key de Brevo vive en el `.env` central) | Usuario y clave SMTP de la agencia, **cifrados** por tenant |
| Cuota diaria | **300/día compartidos** entre todas las agencias + avisos de Créditos + correos del sistema | 300/día **por agencia** |
| Riesgo si una agencia genera quejas de spam | Brevo puede limitar o suspender **nuestra** cuenta → también caen los avisos de cobranza de Créditos | Aislado en la cuenta de esa agencia |
| Soporte | Bajo: lo controlamos todo | Medio: cuentas ajenas, claves que cambian |

**Recomendación:** construir el envío con una capa de "transporte por tenant" que soporte las
dos variantes, pero **implementar y usar solo V1 en la primera entrega**. Con 1 a 3 agencias y
el volumen típico de cotizaciones (decenas al día) la cuota alcanza. Pasar una agencia a V2,
o pasar a un plan pago de Brevo, es una decisión que se toma cuando el volumen lo pida, sin
rehacer nada. Ver §7.

### 2.3 Si el dominio todavía no está verificado (decisión D2)

Recomendación: **permitir el envío igual, con respaldo**:
`From: "Turismo XYZ" <cotizaciones@umbosystem.com>` + `Reply-To: ventas@turismoxyz.com`.
El cliente ve el nombre de la agencia y, si responde, la respuesta llega a la agencia. Así la
función sirve desde el primer día, mientras la agencia agrega los registros DNS. El modal de
envío avisa qué remitente se va a usar.

Alternativa: bloquear el envío hasta que el dominio esté verificado. Es más estricto, pero
deja la función sin uso hasta que la agencia toque su DNS.

> Requiere autenticar también `umbosystem.com` para `cotizaciones@` (ya está autenticado para
> `avisos@`, así que solo es otra dirección del mismo dominio). Verificar en Brevo.

---

## 3. Configuración por tenant

### 3.1 Nivel central (lo decide el Superadmin)

Columnas nuevas en `tenants` (mismo patrón que `facturacion_habilitada`):

- `correo_cotizaciones_habilitado` (bool, default `false`): interruptor de la función.
- `correo_limite_diario` (int, default `50`): tope de envíos por día (hora de Perú) para ese
  tenant, para que una agencia no se gaste la cuota de todas.

Tabla central nueva **`tenant_dominios_correo`**:
`id, tenant_id, dominio, brevo_estado (pendiente|verificado|fallido), dns_records (json),
verificado_en, ultimo_chequeo_en, timestamps`.
Es central porque la maneja el Superadmin y la API key de Brevo solo vive en central.

Pantalla en el detalle del tenant (`central-panel/`), sección "Correo":
1. Activar/desactivar y fijar el límite diario.
2. Escribir el dominio → "Registrar en Brevo" → se muestran los registros DNS a copiar
   (botón copiar por registro) para mandárselos a la agencia.
3. "Verificar ahora" → llama al endpoint de autenticación de Brevo y guarda el estado.

### 3.2 Nivel tenant (lo configura la agencia)

Tabla nueva en el vertical **`configuracion_correo`** (una fila, mismo criterio que
`configuracion_agencia`):

- `remitente_nombre` (default: nombre comercial de la Company)
- `remitente_local` (lo que va antes de la `@`, p. ej. `ventas`; el dominio sale del
  dominio verificado: el usuario **no puede** escribir un dominio distinto)
- `reply_to` (default: `companies.email`)
- `copia_oculta_agencia` (bool + dirección): con Brevo el correo no aparece en "Enviados" del
  buzón de la agencia; la copia oculta resuelve eso
- `asunto_plantilla`, `cuerpo_plantilla` (texto plano con variables: `{cliente}`,
  `{codigo}`, `{destino}`, `{fecha_viaje}`, `{vendedor}`, `{vigencia}`)
- `firma` (texto plano)
- `adjuntar_condiciones_por_defecto` (bool, default `true`)

Pantalla: nueva sección "Correo" en el acordeón de Configuración de Agencia, con:
- estado del dominio (solo lectura: "verificado" / "pendiente de DNS — se enviará como
  cotizaciones@umbosystem.com");
- vista previa del correo;
- botón "Enviarme una prueba".

Permisos nuevos (tenant, Spatie):
- `cotizaciones.enviar_correo`: enviar desde el cotizador;
- `configuracion_agencia.correo`: editar la configuración. Puede reutilizarse el permiso
  actual de Configuración de Agencia, a decidir al implementar.

---

## 4. Flujo de envío (usuario)

1. En `cotizador/editar.vue`, cada alternativa tiene el botón **"Enviar por correo"**. Solo
   aparece si el tenant tiene la función habilitada y el usuario tiene el permiso.
2. Se abre un modal con:
   - **Para**: el correo del cliente ya puesto, editable, hasta 5 direcciones;
   - **CC** (opcional);
   - **Asunto** y **mensaje**, ya llenados desde la plantilla y editables;
   - casillas **"Adjuntar cotización (PDF)"** (marcada) y **"Adjuntar condiciones
     generales"** (según la configuración);
   - casilla **"Marcar la alternativa como enviada"** (ver D4);
   - línea informativa: "Se enviará desde: Turismo XYZ <ventas@turismoxyz.com>".
3. "Enviar" → spinner → resultado: éxito o error real del servidor.
4. Debajo de la alternativa, un **historial de envíos**: fecha y hora (Perú), quién envió, a
   quién, estado.

---

## 5. Backend

### 5.1 Refactor de los PDFs (sin cambiar lo que ven hoy)

- `AlternativaPdfService`: nuevo `contenido($id): array{bytes, nombre}`. `generar()` pasa a
  envolverlo y devolver la descarga como hoy.
- `CondicionesGeneralesPdfService`: lo mismo.
- Prueba: los PDFs descargados quedan idénticos (comparar tamaño y texto antes y después).

### 5.2 Endpoint

`POST alternativas/{id}/enviar-correo`, con `permission:cotizaciones.enviar_correo`.

Payload: `para[]`, `cc[]`, `asunto`, `mensaje`, `adjuntar_cotizacion`,
`adjuntar_condiciones`, `marcar_enviada`.

Validaciones:
- función habilitada para el tenant;
- límite diario no superado (se cuenta en `cotizacion_envios` con
  `HoraPeru::inicioDiaUtc/finDiaUtc`, nunca con `whereDate`);
- correos válidos, máximo 5 en Para y 5 en CC;
- alternativa no `descartada`;
- al menos un adjunto, o un mensaje no vacío.

### 5.3 Historial: `cotizacion_envios` (tenant, vertical agencia-viajes)

`id, cotizacion_id, alternativa_id, usuario_id, para (json), cc (json), bcc (json),
remitente (texto final usado), modo_remitente (dominio_agencia|plataforma), asunto, cuerpo,
adjuntos (json: nombre + bytes), estado (enviado|fallido), error (texto), message_id,
created_at, updated_at`.

Toda solicitud de envío deja una fila, también las que fallan ("ningún envío silencioso",
mismo criterio que Caja).

### 5.4 Envío síncrono en la primera entrega (decisión D3)

Recomendación: **enviar dentro de la misma petición**, sin cola.
- Generar los dos PDFs tarda lo mismo que hoy al descargarlos (unos segundos); el usuario ve
  el resultado real en el momento.
- Sería el primer job en cola del proyecto. Agregarlo después no cambia el diseño:
  `cotizacion_envios` ya tiene `estado`, y solo se suma `pendiente`.

El correo se arma con un Mailable `CotizacionClienteMail`:
- `from`: el remitente resuelto (§2.3);
- `replyTo`: `reply_to`;
- `bcc`: la copia a la agencia;
- adjuntos con `Attachment::fromData(...)`.

El cuerpo se arma desde la plantilla de **texto plano**: se escapa y se convierten los
saltos de línea, nunca HTML libre. Esto evita repetir el problema de `v-html` de la auditoría
de seguridad.

Transporte: en V1 es el mailer `smtp` actual de Brevo, cambiando solo el `from`. La resolución
del transporte va en un servicio propio, `CorreoAgenciaService::mailerPara(tenant)`, para que
V2 (SMTP propio de la agencia) se sume después sin tocar el flujo.

### 5.5 Al enviar con éxito

Si `marcar_enviada` y la alternativa está en `borrador`: pasa a `enviada` con `fecha_envio`,
reutilizando la misma lógica de `AlternativaController::update()` (extraerla, no duplicarla).
Al implementar, **verificar dónde se calcula `fecha_vencimiento`**: según el comentario de la
migración se calcula al fijar `fecha_envio`, pero en `update()` solo se ve `fecha_envio`.

### 5.6 Tamaño de los adjuntos

Las fuentes de Brevo no coinciden en el límite de tamaño. Su centro de ayuda dice que un
correo transaccional admite 20 MB en total y recomienda menos de 4 MB por adjunto. Su página
de SMTP dice 25 MB.
- **Antes de implementar:** medir el peso real del PDF de las alternativas de `agencia-demo`,
  sobre todo las que tienen fotos.
- Si alguno supera unos 4 MB: comprimir las fotos dentro del PDF (`ImagenRecorteService` ya
  existe), o enviar un **enlace firmado de descarga** en lugar del adjunto, como en
  "Mi crédito". El enlace queda para la segunda etapa salvo que la medición lo haga
  necesario.

---

## 6. Seguridad y abuso

- La API key de Brevo solo en el `.env` central. Nunca se expone al tenant ni al frontend.
- El dominio del remitente sale **siempre** del dominio verificado del tenant. El usuario solo
  elige lo que va antes de la `@` (en la configuración, no en cada envío).
- Hay un límite diario por tenant, y además límite de frecuencia por usuario
  (`throttle`, p. ej. 10 por minuto).
- Solo se envía a direcciones que escribe el usuario. No hay envío masivo a listas: es
  correo **transaccional**, uno a uno.
- Cada envío queda en el historial con su usuario.

---

## 7. Cuota de Brevo y cuándo pasar a plan pago

- El plan gratuito permite **300 correos por día para toda la cuenta**. Las copias (CC y
  copia oculta) cuentan como correos aparte: un envío con cliente + CC + copia a la agencia
  son 3.
- Hoy consumen esa cuota: los avisos de cobranza de Créditos (credishirley), los correos del
  sistema (backups fallidos, suspensiones) y la verificación de correo de los avisos.
- **Indicador:** el Superadmin ve los envíos del día por tenant (suma de
  `cotizacion_envios` de cada tenant, o contador central).
- **Cuándo cambiar:** si el total diario pasa de unos 200 de forma sostenida, o entra una
  cuarta agencia activa, pasar a un plan pago de Brevo o mover a esa agencia a V2. El precio
  de los planes pagos hay que confirmarlo en la web de Brevo en su momento.
- **Por confirmar en la cuenta real de Brevo:** si el plan gratuito permite autenticar
  **varios dominios**. No encontré documentación que lo limite ni que lo garantice; se prueba
  en la Etapa 0 registrando el dominio de una agencia real.

---

## 8. Etapas de entrega

| Etapa | Contenido | Sale con |
|---|---|---|
| **E0 — comprobaciones** (sin código de producto) | (a) registrar a mano en Brevo el dominio de una agencia real y confirmar que el plan gratuito lo permite; (b) autenticar `cotizaciones@umbosystem.com`; (c) medir el peso de los PDFs reales de `agencia-demo` | Resultados anotados en este documento |
| **E1 — backend** | Refactor de los PDFs (§5.1), migraciones (`configuracion_correo`, `cotizacion_envios`, columnas en `tenants`), `CorreoAgenciaService`, `CotizacionClienteMail`, endpoint, límite diario, marcar enviada, permisos + seeder | Tests: remitente resuelto en los 2 modos, límite diario con borde de medianoche en Perú, PDFs idénticos tras el refactor, permiso, fila de historial ante un fallo |
| **E2 — frontend del tenant** | Modal de envío + historial en `editar.vue`, sección "Correo" en Configuración de Agencia con vista previa y "Enviarme una prueba" | Verificación en vivo con Playwright contra `agencia-demo` (`MAIL_MAILER=log` en local: revisar el correo generado en el log) |
| **E3 — Superadmin** | Interruptor + límite en el detalle del tenant, alta y verificación del dominio vía API de Brevo, registros DNS para copiar | Prueba real con el dominio de E0 |
| **E4 — después** (sin fecha) | Webhooks de Brevo (entregado, rebotado, abierto) para el historial; enlace firmado en vez de adjunto; V2 (SMTP o cuenta Brevo propia de la agencia); cola de envío | Según volumen |

Despliegue: correr `tenants:migrate-verticales`. La migración central de `tenants` y
`tenant_dominios_correo` va con `migrate` central.

---

## 9. Etapa 0 — resultados (11-oct-2026)

**Agencia piloto: `dakamu` (producción), dominio `dkmxplore.com`.** Direcciones que usa:
`info@dkmxplore.com` y `reservas@dkmxplore.com`.

DNS públicos consultados (solo lectura, 11-oct):
- **Correo en Google Workspace** (MX `aspmx.l.google.com` y alternos). Los buzones siguen en
  Google; Brevo solo envía las cotizaciones. No se toca el MX.
- **DNS en Cloudflare** (`penny`/`tim.ns.cloudflare.com`): los registros se agregan desde el
  panel de Cloudflare de ese dominio.
- **SPF:** `v=spf1 include:_spf.google.com ~all`. Si Brevo pide incluirse en el SPF, se
  **fusiona en ese mismo registro**. Nunca crear un segundo registro SPF: con dos, ambos
  dejan de valer y también se afecta el correo de Google.
- **DMARC:** no tiene. Brevo lo recomienda o exige; agregar `_dmarc` con `p=none` no cambia
  nada del correo actual y es el punto de partida correcto.
- **DKIM de Google:** no se encontró con el selector por defecto (`google._domainkey`).
  Ajeno a este módulo; activarlo en Google Workspace es buena práctica, sobre todo al agregar
  DMARC.

Peso de los PDFs (medido generando las 17 alternativas reales de `agencia-demo`):
- PDF de cotización: promedio **351 KB**, máximo **1,4 MB** (alternativa 67, con fotos).
  Condiciones generales: **258 KB**.
- Conclusión: muy por debajo de 4 MB por adjunto y de 20 MB en total. **El enlace firmado no
  hace falta** en la primera versión; queda en E4.

Hallazgo para E1: `AlternativaPdfService` **se niega a generar** el PDF si un grupo de hotel
no tiene opción elegida (alternativa 55: "falta elegir una opción en este grupo de
hotel…"). El endpoint de envío debe generar los PDFs **antes** de enviar y devolver ese
mensaje como 422, sin dejar el envío a medias. La fila del historial se guarda como
`fallido` con ese motivo.

Confirmado con el usuario (11-oct):
- `dakamu` tiene `giro='agencia_viajes'` en producción.
- Remitente de las cotizaciones: **`reservas@dkmxplore.com`**, que también es el Reply-To.
- El Cloudflare de `dkmxplore.com` lo **administra la agencia**, no UmboSystem. Los
  registros DNS se le mandan para que los cargue ella. Esto confirma que E3 (registros listos
  para copiar desde el Superadmin) tiene valor real: será el flujo normal con cada agencia.

Pendiente de E0:
- (a) registrar `dkmxplore.com` en la cuenta Brevo de UmboSystem y confirmar que el plan
  gratuito acepta un segundo dominio;
- (b) confirmar que `cotizaciones@umbosystem.com` puede enviar;
- (c) mandar los registros a la agencia, esperar que los cargue y verificarlos con consultas
  DNS y con el botón "Autenticar" de Brevo.

---

## 10. Decisiones (tomadas el 14-oct-2026, con la recomendación)

> El usuario pidió construir el módulo completo para ofrecerlo a las agencias (posible
> adicional al plan mensual), dejando para después solo la configuración externa. Se tomaron
> las recomendaciones: **D1** V1 (cuenta Brevo de UmboSystem, capa lista para V2); **D2**
> enviar con respaldo `cotizaciones@umbosystem.com` + Reply-To de la agencia; **D3** envío
> síncrono; **D4** casilla "marcar como enviada", marcada por defecto, solo si está en
> borrador; **D5** cotización + condiciones generales; **D6** permiso
> `cotizaciones.enviar_correo` + la misma regla de fila que ver la cotización
> (`CotizacionPolicy::view`: sin `ver_todas`, solo las propias). Todas se pueden cambiar.
> Texto original de las preguntas:

- **D1. Variante de cuenta Brevo:** V1, la cuenta de UmboSystem (recomendada para empezar),
  o V2, una cuenta por agencia.
- **D2. Dominio sin verificar:** enviar con respaldo `cotizaciones@umbosystem.com` +
  Reply-To de la agencia (recomendado), o bloquear el envío.
- **D3. Envío síncrono en la primera entrega:** recomendado síncrono; cola más adelante.
- **D4. Marcar la alternativa como "enviada" al enviar:** casilla marcada por defecto
  (recomendado), siempre, o nunca.
- **D5. Adjuntos:** cotización + condiciones por defecto. ¿Hace falta adjuntar algo más
  (p. ej. cuentas bancarias, que ya salen dentro del PDF de cotización)?
- **D6. Quién envía:** cualquier usuario con `cotizaciones.enviar_correo`, o solo el
  vendedor dueño de la cotización (como `cotizaciones.ver` frente a `ver_todas`).

---

## 11. Cómo retomar (lista de pasos en orden)

1. **Aprobar las decisiones de la §10.** Pedirle al usuario un "ok" a las recomendaciones o
   los cambios que quiera. Sin esto no se empieza E1.
2. **Registrar el dominio en Brevo** (lo hace el usuario, que tiene la cuenta):
   Remitentes, dominios e IPs dedicadas → Dominios → Agregar dominio → `dkmxplore.com` →
   autenticación **manual**. No usar la conexión automática con Cloudflare, porque ese
   Cloudflare es de la agencia. Anotar aquí si el plan gratuito aceptó el segundo dominio
   (pendiente E0-a).
3. **Si Brevo pide incluirse en el SPF:** no mandarlo así. Armar un único registro
   combinado partiendo del actual (`v=spf1 include:_spf.google.com ~all`) y revisarlo antes de
   enviarlo a la agencia.
4. **Mandar a la agencia el mensaje de la §11.1**, con los valores reales que muestre Brevo.
5. **Cuando la agencia avise**, comprobar desde aquí con `nslookup` contra `8.8.8.8`:
   - TXT de `dkmxplore.com` (debe aparecer el `brevo-code` y **un solo** SPF);
   - CNAME de `brevo1._domainkey` y `brevo2._domainkey`;
   - TXT de `_dmarc.dkmxplore.com`.

   Luego pulsar "Autenticar" en Brevo.
6. **Comprobar `cotizaciones@umbosystem.com`** en Brevo, para el envío de respaldo (D2), si
   se aprueba (pendiente E0-b).
7. **E1 → E2 → E3**, según la §8. Se puede empezar E1 y E2 en local con `agencia-demo`
   (`MAIL_MAILER=log`) **sin esperar** los pasos 2 a 6: solo la prueba real de envío depende
   de ellos.
8. **Al desplegar:** activar la función solo para `dakamu` desde el Superadmin y configurar
   el remitente `reservas@dkmxplore.com` (Reply-To también `reservas@`). Mandar una
   cotización de prueba a una dirección de UmboSystem y revisar en "Mostrar original" de
   Gmail que SPF, DKIM y DMARC den `PASS`.

### 11.1 Mensaje para la agencia (plantilla)

Destinatario: quien administra el Cloudflare de `dkmxplore.com` en Dakamu. Completar los
`________` con lo que muestre Brevo y ajustar la tabla si Brevo pide otros registros.

> **Asunto:** Registros DNS para enviar cotizaciones desde reservas@dkmxplore.com
>
> Hola, para que las cotizaciones del sistema salgan desde **reservas@dkmxplore.com** sin
> caer en spam, necesitamos que agreguen estos registros en Cloudflare (dominio
> dkmxplore.com → DNS → Registros). Su correo actual de Google **no se ve afectado**: no hay
> que tocar los registros MX.
>
> | Tipo | Nombre | Contenido |
> |---|---|---|
> | TXT | `@` | `brevo-code:________` |
> | CNAME | `brevo1._domainkey` | `________` |
> | CNAME | `brevo2._domainkey` | `________` |
> | TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:reservas@dkmxplore.com` |
>
> Importante:
> - En los dos registros CNAME, dejen la nube en **gris ("Solo DNS")**, no en naranja.
> - **No creen un segundo registro SPF.** Ya existe uno
>   (`v=spf1 include:_spf.google.com ~all`) y no hay que modificarlo, salvo que les
>   indiquemos lo contrario.
>
> Cuando estén cargados, avísennos y lo verificamos. Gracias.

### 11.2 Fuentes consultadas (11-oct-2026)

- Cuota del plan gratuito (300 por día): https://www.brevo.com/free-smtp-server
- Alta y autenticación de dominios por API:
  https://developers.brevo.com/docs/domain-creation-and-management y
  https://developers.brevo.com/docs/domain-authentication-and-verification
- Límite de adjuntos en correo transaccional (20 MB en total, recomienda menos de 4 MB por
  adjunto): https://help.brevo.com/hc/en-us/articles/4402811730962
- No encontrado: si el plan gratuito limita la cantidad de dominios autenticados. Se
  confirma en el paso 2.

---

## 12. Implementación (14-oct-2026)

**Rama:** `feat/envio-correo-cotizacion` (sale de `docs/plan-tours-costo-real`; unir en ese
orden).

### Lo construido

| Pieza | Dónde |
|---|---|
| Interruptor y límite por tenant | `tenants.correo_cotizaciones_habilitado` / `correo_limite_diario` (central) |
| Dominio de la agencia en Brevo | tabla central `tenant_dominios_correo`, `BrevoDominiosService` (API v3: registrar, consultar, autenticar, eliminar) |
| Configuración de la agencia | `configuracion_correo` (vertical) + `ConfiguracionCorreoController` (ver, guardar, correo de prueba) |
| Historial y límite diario | `cotizacion_envios` (vertical); toda solicitud deja fila, también si falla |
| Remitente, plantillas, transporte | `CorreoAgenciaService` |
| Correo | `CotizacionClienteMail` + vistas `emails/agencia-viajes/cotizacion-cliente(-texto)` (texto plano escapado) |
| Envío | `CotizacionEnvioController` (estado, preparar, enviar, historial) |
| Superadmin | `TenantCorreoController` + pestaña "Correo" en el detalle del tenant (`central-panel`) |
| Cotizador | `EnviarCorreoCotizacion.vue` (botón, ventana, historial) en `cotizador/editar.vue` |
| Configuración de Agencia | `ConfiguracionCorreoSeccion.vue` (sección 13, con vista previa y "Enviarme una prueba") |
| Permiso | `cotizaciones.enviar_correo` (migración + `AgenciaViajesRolesSeeder`: Administrador, Supervisor, Vendedor) |
| Refactor de PDFs | `AlternativaPdfService::contenido()` y `CondicionesGeneralesPdfService::contenido()`; `generar()` igual que antes |

**Corrección de paso:** `alternativas.fecha_vencimiento` no se llenaba en ningún lado (solo se
leía), así que una cotización nunca llegaba a "vencida". Ahora `Alternativa::camposAlMarcarEnviada()`
la calcula con `dias_vigencia_cotizacion` al marcar "enviada", a mano o por correo.

### Verificación

- **Tests:** 25 nuevos (`EnvioCorreoCotizacionTest`, `BrevoDominiosServiceTest`). Suite completa
  1391/1391 antes de la corrección del Mailable; el archivo del envío, 19/19 después.
- **PDFs:** los 16 PDFs de cotización imprimibles de `agencia-demo` y el de condiciones salen
  idénticos con el refactor (solo varía el `/ID` aleatorio que DomPDF pone en cada generación).
- **Frontend:** type-check sin errores nuevos (46 preexistentes, la misma lista que en la base);
  `central-panel` sin errores.
- **En vivo contra `agencia-demo`** (Playwright, `MAIL_MAILER=log`): envío con CC y los dos
  adjuntos, alternativa marcada "enviada" con vencimiento a 15 días, historial, cliente sin
  correo (botón Enviar deshabilitado), validación de la dirección del remitente, vista previa
  en vivo y correo de prueba. Endpoints del Superadmin probados llamando al controller (sin
  `BREVO_API_KEY`: rechazo claro; dominio mal escrito: rechazo). Datos de prueba revertidos.
- **Bug real encontrado en vivo y corregido:** con adjuntos, la plantilla fallaba ("Array to
  string conversion") porque Laravel pasa a la vista las propiedades **públicas** del Mailable y
  la pública `$adjuntos` pisaba la lista de nombres. Los tests no lo veían porque `Mail::fake()`
  no dibuja la vista. Propiedades pasadas a privadas + test de regresión que dibuja el correo con
  adjuntos (falla sin la corrección).

### Pendiente (externo, no de código)

1. **Brevo** (lo hace el usuario, con su cuenta): verificar que `cotizaciones@umbosystem.com`
   pueda enviar (dominio `umbosystem.com` ya autenticado para `avisos@`), y crear la clave de
   **API** (no la SMTP) para `BREVO_API_KEY`.
2. **Producción, `.env` central:** `BREVO_API_KEY=...` y, si se cambia la dirección,
   `MAIL_COTIZACIONES_RESPALDO=...`.
3. **Despliegue:**
   - migraciones centrales: `php artisan migrate --path=database/migrations/2026_10_14_100000_add_correo_cotizaciones_to_tenants_table.php`
     y `--path=database/migrations/2026_10_14_100100_create_tenant_dominios_correo_table.php`
     (en local el historial central tenía pendientes ajenas; aplicar solo estas dos);
   - `php artisan tenants:migrate-verticales`;
   - `php artisan roles:sync-permisos-nuevos 'Database\Seeders\AgenciaViajesRolesSeeder'` (agrega
     `cotizaciones.enviar_correo` a los roles base sin pisar personalizaciones).
4. **Activar para una agencia** (cuando la contrate): Superadmin → tenant → pestaña "Correo" →
   habilitar y fijar el límite. Desde ese momento ya puede enviar con el respaldo.
5. **Dominio propio de la agencia** (opcional, mejora la entrega): en la misma pestaña,
   "Registrar en Brevo" → copiar los registros DNS → mandarlos a la agencia (§11.1) →
   "Verificar ahora". **No crear un segundo SPF** (§11, paso 3).
6. **Confirmar con la cuenta real** la forma exacta de `dns_records` que devuelve la API de
   Brevo: `BrevoDominiosService::registrosDns()` la normaliza de forma defensiva y la pestaña
   muestra lo que tenga tipo, nombre y valor.
