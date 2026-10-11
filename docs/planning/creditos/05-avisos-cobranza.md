# Fase 5 — Aviso diario de cobranza ("lo que cobras hoy")

Estado: **las 3 entregas construidas** (11-oct-2026). Correo (entregas 1 y 3) **en producción**
desde el 11-oct (`1c60195`); push (entrega 2) en la rama `feat/creditos-avisos-push` — al
desplegarla, ver "Entrega 2 → Al desplegar" (hay que generar las claves VAPID). Surge de un análisis con el usuario sobre
avisar a clientes y administradores; este documento cubre **solo el aviso interno** (administrador,
asesor que cobra, cobrador). El aviso al cliente (página "Mi crédito", ronda de recordatorios por
WhatsApp manual, WhatsApp automático pagado) queda para un documento propio — ver "Fuera de alcance".
Reglas de fondo: `00-reglas-y-modelo.md` y la Agenda de cobranza de `04d-reportes.md` (reporte 8),
que es la base de todo este aviso.

## Objetivo
Cada mañana, antes de salir a cobrar, cada usuario que cobra recibe **qué tiene que cobrar hoy**
(vencen hoy + atrasados) sin tener que entrar al sistema a buscarlo; el administrador recibe además
el PDF de toda la cartera del día.

## Decisiones (aprobadas 11-oct-2026)
1. **Canales gratuitos:** push del navegador (Web Push) + correo. Se descartó WhatsApp automático
   para este aviso por costo diario (cobra por mensaje) y SMS (cuesta igual o más). Telegram queda
   como alternativa no elegida.
2. **Hora: 06:45 Lima**, lunes a sábado por defecto.
3. **PDF adjunto para el administrador, link (con login) para cobrador/asesor.** Motivo: el
   administrador es el dueño de la cartera y lo usa sin fricción (sin login, sin señal, imprimible,
   reenviable); el cobrador rota de personal y con link pierde el acceso en cuanto se desactiva el
   usuario. Configurable por tenant.
4. **El PDF es una foto de las 06:45**; lo que está al día lo da el push, que abre la Agenda en vivo.

## Qué se reutiliza
| Pieza | Dónde |
|---|---|
| Quién ve qué (admin todo, cobrador su cartera) | `AgendaCobranzaService::agenda($usuario, $periodo, $incluirAtrasados)` + `AlcanceCartera` |
| PDF | `ReporteCreditoController::pdf()` (vista `pdf.creditos.reporte_a4`) — extraer la generación a un servicio para llamarla sin request |
| Fecha de negocio | `Creditos\Reloj` |
| Recorrido por tenant con aislamiento de fallas | Patrón de `CreditosEscalamiento` (un tenant que falla no frena a los demás) |
| Cron | `schedule:run` ya activo en producción |
| Envío de correo seguro (no rompe el proceso si falla) | Patrón de `TenantContactMailer::enviar()` |

## Flujo
```
06:45 Lima — creditos:aviso-cobranza-dia  (->withoutOverlapping())
  por cada tenant giro creditos, no archivado, con avisos activos
    por cada usuario activo con creditos.cobrar o creditos.ver_todos, con algún canal activo
      agenda(usuario, hoy, incluirAtrasados = config)
      si ya hay fila en credito_avisos(usuario, tipo, fecha) → saltar (idempotente)
      Push   → solo si hay cobros
      Correo → resumen; PDF adjunto o link según rol/config; "sin vencimientos" solo al admin
      registrar resultado por canal
```
Orden del día en el servidor: 02:30 castigos (los castigados ya no aparecen) · 03:00 backups ·
05:00 tipo de cambio · **06:45 este aviso** · 23:50 foto de cartera.

## Contenido
| | Administrador (`creditos.ver_todos`) | Cobrador / asesor que cobra |
|---|---|---|
| Alcance | Toda la cartera | Su cartera (ya resuelto por `AlcanceCartera`) |
| Push | "Hoy: 14 cobros · S/ 1,850 · 3 atrasados" | "Tienes 6 cobros hoy · S/ 620" |
| Correo | Total esperado, desglose por cobrador, atrasados, **PDF adjunto** | Resumen corto + botón "Ver PDF" (login) |
| Día sin cobros | Correo "sin vencimientos" (confirma que el proceso corrió) | Nada |

- El push lleva **solo totales**, nunca nombres de clientes (se ve en la pantalla bloqueada).
- El PDF lleva al pie "Confidencial — generado para {usuario} el {fecha/hora}" (técnica
  `position:fixed` de DomPDF, como el Reporte Operativo de Agencia).
- **Ojo con "Cajero de créditos":** también tiene `creditos.ver_todos`. El adjunto no debe
  decidirse por ese permiso sino por uno nuevo, **`creditos.avisos_pdf_adjunto`** (lo trae
  "Administrador de créditos" por seeder, configurable desde Roles). Sin él → link.

## Push (Web Push)
- Paquete `laravel-notification-channels/webpush` (VAPID). Entrega gratuita por los servicios de
  push de Google/Mozilla/Apple.
- **Frontend:** `admin-start-kit` pasa a PWA instalable (manifest + service worker que muestra la
  notificación y abre la URL al tocarla). En "Mi perfil": *Activar avisos en este dispositivo* /
  *Desactivar*, y "Enviar prueba".
- **Datos:** tabla `push_subscriptions` en la BD del tenant (una fila por dispositivo del usuario).
  Cada subdominio es su propio origen, así que las suscripciones quedan aisladas por tenant sin
  esfuerzo. **Un solo par de claves VAPID** para toda la plataforma, en `.env`.
- Respuesta 404/410 del servicio de push → borrar la suscripción (dispositivo desinstalado).
- **iPhone:** solo con el sistema agregado a la pantalla de inicio (iOS 16.4+). Android: directo
  desde Chrome. Se explica en la pantalla de activación.

## Correo
- Proveedor transaccional (Brevo plan gratis 300/día, o Amazon SES). Remitente
  `avisos@umbosystem.com`, nombre visible = razón social del tenant, `Reply-To` = correo de la
  empresa del tenant. SPF + DKIM + DMARC en el dominio.
- **Correo para avisos verificado:** campo propio en el usuario (por defecto su email de login) que
  se confirma con un enlace antes de recibir nada — evita mandar la cartera a un correo mal escrito
  o de prueba (`…@…test`). Sin verificar → no se envía correo, solo push.
- El PDF se genera en memoria y se adjunta; no se guarda en disco.

## Configuración
- **Por tenant** (en `credito_configuracion` o tabla propia `credito_avisos_config`): activo, hora
  (default 06:45), días (L-S; domingo opcional), incluir atrasados (sí), modo de PDF para quien no
  tiene el permiso de adjunto (link), aviso "sin vencimientos" al admin (sí).
- **Por usuario:** recibir push sí/no, recibir correo sí/no, correo para avisos.
- La hora configurable implica que el comando corra cada 15 min y despache a los tenants cuya hora
  ya pasó y no tienen aviso del día — la tabla `credito_avisos` hace que no se repita.

## Datos nuevos (tenant, `tenant/core` como el resto del módulo)
- `credito_avisos`: `user_id`, `tipo` (`cobranza_dia`), `fecha` (negocio), `canal` (`push|correo`),
  `estado` (`pendiente|enviado|fallido|omitido`), `intentos`, `detalle` (error o motivo),
  `cantidad_cobros`, `monto_esperado` (soles, decimal), `enviado_en`, `abierto_en` (clic del push).
  Único (`user_id`, `tipo`, `fecha`, `canal`).
- `credito_aviso_preferencias`: una fila por usuario (`correo`, `correo_verificado_en`,
  `recibir_correo`, `recibir_push`).
- `push_subscriptions` (del paquete).
- Columnas de configuración por usuario/tenant (ver arriba).
- Recordar: `php artisan tenants:migrate-verticales`, no `tenants:migrate`.

## Costo en el servidor
VPS de producción (revisado 09-oct): 3.7 GB RAM, 2 GB swap, disco 25 %. El cron ya corre
`schedule:run` cada minuto: el aviso **no agrega procesos permanentes**.

| Paso por usuario | Tiempo | Memoria pico |
|---|---|---|
| Agenda (consultas) | 0.1–0.5 s | baja |
| PDF DomPDF A4 horizontal | 0.5–2 s | 30–80 MB (~150 MB con cientos de filas) |
| Correo SMTP externo | 0.5–1 s | baja |
| Push por dispositivo | 0.1–0.3 s | baja |

| Escenario | Avisos/día | Duración | Costo |
|---|---|---|---|
| Hoy (credishirley, 3–5 usuarios) | ~5 | < 15 s | US$0 |
| 10 tenants × 5 usuarios | 50 | 2–3 min | US$0 |
| 50 tenants × 10 usuarios | 500 | 15–20 min | US$0 servidor; correo dentro de plan gratis/centavos |

Protecciones: `withoutOverlapping()`, aislamiento de fallas por tenant, sin PDF si el usuario no
tiene cobros, PDF en memoria (uno a la vez). **Envío síncrono a propósito** (decidido al
construir la entrega 1): con el volumen actual sobra, el registro sabe en el momento si salió o
falló, y el PDF adjunto no viaja serializado en la cola. Los reintentos los da el propio comando
(cada 15 min, hasta 3 intentos por día). Si el volumen crece, el worker de colas
(`sistemafe-worker`) ya existe en producción.

## Analítica
- `credito_avisos` responde quién recibió qué, por qué canal, y si falló.
- El link del push lleva un marcador → `abierto_en` (¿el cobrador abrió la Agenda desde el aviso?).
- Indicador: **cobrado hoy ÷ esperado a las 06:45**, por cobrador (el esperado queda guardado en
  `monto_esperado`; el cobrado ya lo calcula el Panel). Base para un futuro resumen nocturno.

## Entregas
| # | Entrega | Días aprox. |
|---|---|---|
| 1 | Comando + `credito_avisos` + configuración + correo con PDF (adjunto/link) + correo para avisos verificado + permiso nuevo + tests | 1.5–2 |
| 2 | PWA (manifest, service worker) + suscripción push + activar/probar en "Mi perfil" + push en el comando | 1–1.5 |
| 3 | Pantalla de configuración en Créditos + verificación real en celular (Android e iPhone) | 0.5–1 |

La entrega 1 se apoya en lo existente y tiene el menor riesgo; la 2 es la parte nueva.

### Entrega 1 — construida (11-oct-2026, rama `feat/creditos-avisos-cobranza`)
- **Migraciones** (`tenant/core`): `2026_10_11_100000_creditos_avisos_cobranza` (5 columnas
  `avisos_*` en `credito_configuracion`, **apagado por defecto**; tablas `credito_aviso_preferencias`
  y `credito_avisos`) y `2026_10_11_100100_add_creditos_avisos_pdf_adjunto_permission` (al
  "Administrador de créditos"; también en `CreditosRolesSeeder` y en el catálogo de Roles).
- **Servicio** `Creditos\Avisos\AvisoCobranzaService` (`ejecutar`, `motivoParaNoCorrer`,
  `destinatarios` — activos con `creditos.cobrar` o `ver_todos`, sin Super-Admin —, `avisar`).
  Reutiliza `AgendaCobranzaService` con `Periodo::dia(hoy)`.
- **PDF:** generación extraída de `ReporteCreditoController::pdf()` a `Reportes\ReportePdf`
  (la usan la descarga y el aviso); pie "Confidencial — generado para … el …" en el aviso.
- **Correos:** `AvisoCobranzaDiaMail` (remitente `avisos@` con el nombre de la empresa, Reply-To al
  correo de la empresa) y `VerificarCorreoAvisosMail` (enlace firmado de 48 h ligado al correo
  guardado: si el correo cambia, el enlace viejo da 410).
- **API:** `GET/PUT creditos/avisos/preferencias`, `POST …/preferencias/reenviar-verificacion`,
  `POST creditos/avisos/prueba` (envía el de hoy ahora, sin contar como el del día), y la ruta
  firmada `GET creditos-avisos/verificar-correo`. La configuración del tenant va por el
  `PUT creditos/configuracion` existente (`avisos_activo`, `avisos_hora`, `avisos_dias`,
  `avisos_incluir_atrasados`, `avisos_sin_cobros`).
- **Comando** `creditos:aviso-cobranza-dia` cada 15 min con `withoutOverlapping(30)`.
- **Enlace al panel:** `config/creditos.php` → `TENANT_FRONTEND_URL` (default
  `https://{tenant}.umbosystem.com`; en local `http://{tenant}.sistemafe.test:5173`). La Agenda
  ahora acepta `?rango=hoy&atrasados=1`.
- **Hallazgo previo corregido:** `creditos.reportes` no estaba en el catálogo de Roles del
  frontend (no se podía asignar desde la pantalla).
- **Verificación:** `AvisoCobranzaTest` 10/10 (incluye el render real de ambos correos);
  Créditos + Unit 408/408; vue-tsc en el baseline (46). Correo y PDF revisados a mano (render a 400
  px y PDF rasterizado). Bug encontrado así: el desglose por cobrador salía sin coma de miles (las
  propiedades públicas del Mailable pisan las variables de `with`).
- **Al desplegar:** `tenants:migrate-verticales` (las migraciones son de `tenant/core`) y nada más;
  el cron `schedule:run` ya existe.

### Entrega 3 — construida (11-oct-2026, misma rama; se hizo antes que la 2 a pedido del usuario)
- **Configuración → pestaña "Avisos"** (`components/Creditos/ConfigAvisosCobranza.vue`, permiso
  `creditos.configurar`): encender/apagar, hora (CampoHora), días en que sale, sumar atrasados,
  avisar al admin sin cobros, y quién lo recibe. Guarda solo los `avisos_*` que cambiaron por el
  `PUT creditos/configuracion` existente. Helper puro `helpers/creditos/avisos.ts` (+ Vitest).
- **"Mis avisos"** (`views/creditos/avisos.vue`, ruta `/creditos/avisos`, permiso
  `creditos.cobrar|creditos.ver_todos`): correo para avisos (sugiere el de inicio de sesión),
  estado Confirmado / Sin confirmar con "Reenviar enlace", encender/apagar el correo, "Enviarme
  una prueba ahora" y los últimos 7 avisos. El interruptor de push queda para la entrega 2.
- **Accesos:** menú del usuario (arriba a la derecha) → "Mis avisos de cobranza", solo si el menú
  trae la Agenda (`menu.tieneRuta('creditos.agenda')`, así no aparece en otros giros); botón
  "Aviso diario" en la Agenda.
- **Verificación:** Vitest 141/141 (5 nuevos), vue-tsc en el baseline (46). En vivo contra
  `creditos-demo` (migraciones aplicadas solo ahí, por archivo): encender y guardar; guardar
  correo → enlace en el log → página "Correo confirmado"; "Enviarme una prueba" → correo
  `[Prueba] Hoy: 15 cobros · S/ 2,652.40` con PDF adjunto y enlace a `…/creditos/agenda?rango=hoy&atrasados=1`,
  que abre la Agenda con las mismas 15 cuotas y S/ 2,652.40; comando en domingo → "hoy no es día
  de aviso"; "Mis avisos" a 375 px. Datos de prueba revertidos (aviso apagado, correo borrado).
  Un 500 durante la prueba fue el intermitente conocido de Apache en Windows
  (`fe_sendauth: no password supplied`), no de este código.
- **Para activarlo en credishirley tras desplegar:** el dueño entra a Configuración → Avisos y lo
  enciende; cada usuario guarda y confirma su correo en "Mis avisos" y se manda una prueba.

### Entrega 2 — construida (11-oct-2026, rama `feat/creditos-avisos-push`)
- **Backend:** `minishlink/web-push` ^9 (Web Push estándar, VAPID). Tabla `push_suscripciones`
  (`tenant/core`, una fila por dispositivo, `endpoint_hash` único: el mismo navegador con otro
  usuario pasa a ese usuario). `Push\EnviadorPush` (interfaz; `WebPushEnviador` real, TTL 12 h,
  urgencia alta) → `ResultadoPush` (entregadas, vencidas 404/410 que se borran, errores). Claves en
  `config/services.php` → `webpush` (`VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY`/`VAPID_SUBJECT`); sin
  ellas el push se omite ("push no configurado en el servidor") y la pantalla lo dice.
- **El aviso** (`AvisoCobranzaService::avisar`) ahora calcula la agenda **una vez** y la reparte a
  los canales pedidos (correo y push), cada uno con su fila en `credito_avisos`. Push **solo los
  días con cobros** (también para el admin) y **solo totales** (pantalla bloqueada): "Hoy: 15
  cobros · S/ 2,652.40" / "Tienes 6 cobros hoy · S/ 620.00", cuerpo "N atrasados · Toca para abrir
  la Agenda". La URL lleva `&aviso={id}`: la Agenda llama `POST creditos/avisos/{id}/abierto` →
  `abierto_en` (solo el dueño del aviso, solo la primera vez).
- **API nueva:** `POST creditos/avisos/push` (activar este dispositivo; enciende `recibir_push`),
  `POST …/push/quitar`, `POST …/push/prueba`, `POST creditos/avisos/{id}/abierto`. Preferencias
  devuelven además `push_clave_publica`, `push_dispositivos`, `aviso_activo` y `aviso_hora`.
- **Comando** `php artisan webpush:claves`: imprime el par VAPID para pegar en el `.env` (no escribe
  nada). Cambiar las claves invalida todas las suscripciones.
- **Frontend:** app instalable (`public/manifest.webmanifest`, íconos en `public/icons/` generados
  — "U" blanca sobre `#22c55e`, reemplazables), `public/sw.js` (solo push y clic; **sin caché a
  propósito**), registrado en `main.ts`. Helper `helpers/creditos/push.ts` (+ Vitest). "Mis
  avisos" gana la tarjeta "Avisos en el celular": estados no soportado / iPhone sin instalar (con
  instrucciones) / bloqueado / activar / activo con prueba y desactivar, lista de dispositivos y
  el interruptor `recibir_push`; aviso visible si el negocio tiene el aviso apagado.
- **nginx:** cachea `.js` 1 año, pero el navegador siempre revalida el script del service worker
  (`updateViaCache: 'imports'` por defecto): no hace falta tocar la configuración de producción.
- **Verificación:** `AvisoPushTest` 7 (enviador falso) + `AvisoCobranzaTest` 10; Vitest 145/145;
  vue-tsc en el baseline. Enviador real contra un servidor local: cifrado `aes128gcm`,
  `Authorization: vapid …`, TTL 43200, 410 → vencida. **En vivo con el servicio real de Google
  (FCM)** abriendo el demo como `http://creditos-demo.localhost:5173` (contexto seguro sin
  HTTPS): activar → suscripción FCM real; "Enviarme un aviso de prueba" → notificación mostrada
  por el service worker; corrida programada (usuario temporal "Administrador de créditos",
  domingo habilitado a mano) → push con `&aviso=2` que reemplaza al anterior (misma etiqueta).
  Todo revertido después.
- **Hallazgos:** (1) en Windows, OpenSSL de PHP necesita `OPENSSL_CONF` para crear claves EC
  (`webpush:claves` y el cifrado fallan sin él); en el servidor Linux no pasa. (2) El usuario
  `admin@` de un tenant recién provisionado es **Super-Admin**, que el aviso excluye a propósito:
  quien cobra debe tener un rol del negocio (en credishirley el dueño ya tiene "Administrador de
  créditos"). (3) Bug propio corregido antes de cerrar: el aviso "negocio apagado" cortaba la
  cadena `v-if/v-else-if` y la pantalla quedaba vacía.
- **Al desplegar:**
  1. `./deploy.sh` (la migración `push_suscripciones` va por `tenants:migrate-verticales`).
  2. Una sola vez: `php artisan webpush:claves`, pegar las 3 líneas en el `.env` de
     producción, `php artisan config:cache`, reload de php-fpm y restart del worker.
  3. Cada usuario, desde su celular: Android → Chrome, abrir el panel, Mis avisos → "Activar
     avisos en este dispositivo" → "Enviarme un aviso de prueba". iPhone (iOS 16.4+) → Safari,
     Compartir → Agregar a inicio, abrir desde ese ícono e iniciar sesión, y lo mismo.
- **Local (Windows):** `composer.lock` está armado para PHP 8.5 (producción); el `vendor/` local
  es el del lock de `84e18f1` + web-push. **No correr `composer install` aquí** con el lock actual
  (instala paquetes que piden PHP 8.3+ y Laravel deja de arrancar).

## Verificar antes de construir
- ~~Producción sin correo~~ **RESUELTO 11-oct-2026:** hasta ese día `MAIL_MAILER=log` (ningún
  correo salía; en el log solo había un "Recordatorio de pago vencido — INV-1-202609" nunca
  entregado, ningún backup fallido). Ahora: **Brevo** (plan gratis) por SMTP
  (`smtp-relay.brevo.com:587`), remitente verificado `UmboSystem <avisos@umbosystem.com>`, dominio
  autenticado en Brevo (DKIM `brevo1/brevo2._domainkey` CNAME + `brevo-code` TXT + `_dmarc`
  `p=none`, en Cloudflare, nube gris). `avisos@` recibe vía **Cloudflare Email Routing** → reenvía
  a `umbosac@gmail.com` (MX `route1-3.mx.cloudflare.net` + SPF de Cloudflare). Prueba real
  entregada. Clave SMTP rotada el mismo día (había quedado parcialmente expuesta en un
  `config:show`). Pendientes chicos: endurecer DMARC a `p=quarantine` tras unas semanas estables, borrar en Brevo el
  remitente `umbosac@gmail.com` (no apto: dominio gmail.com).
- **Producción sí tiene worker de colas** (`sistemafe-worker` bajo supervisor, `deploy.sh` lo
  reinicia). La entrega 1 igual envía síncrono (ver "Costo en el servidor"). Ojo: tras cambiar
  `.env`, `config:cache` + reload de php-fpm + `supervisorctl restart sistemafe-worker:*`.
- Extensiones PHP del VPS que pide `minishlink/web-push` (openssl, curl, mbstring; gmp recomendada).
- Que los usuarios reales de credishirley tengan un correo real.
- Con asesor y `asesor_cobra = false`, la agenda del asesor sale vacía → no recibe aviso
  (confirmar que es lo esperado).

## Fuera de alcance (documento aparte)
- Aviso al **cliente**: página "Mi crédito" (link permanente revocable + QR en contrato y recibos),
  ronda de recordatorios por WhatsApp desde el celular del cobrador con registro de envíos, y
  WhatsApp automático pagado con reglas por frecuencia (nunca diario para créditos diarios) como
  adicional `creditos_whatsapp`.
- Resumen nocturno "cobrado vs esperado".
