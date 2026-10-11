# Fase 5 — Aviso diario de cobranza ("lo que cobras hoy")

Estado: **diseño aprobado** (11-oct-2026); prerrequisito de correo en producción **resuelto** el
mismo día (Brevo, ver "Verificar antes de construir"). Entrega 1 en curso en la rama
`feat/creditos-avisos-cobranza` (sale de `main`). Surge de un análisis con el usuario sobre
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

## Datos nuevos (tenant, `verticals/creditos`)
- `credito_avisos`: `user_id`, `tipo` (`cobranza_dia`), `fecha` (negocio), `canal` (`push|correo`),
  `estado` (`enviado|fallido|omitido`), `detalle` (error o motivo), `cantidad_cobros`,
  `monto_esperado` (centavos), `abierto_en` (clic del push). Único (`user_id`, `tipo`, `fecha`, `canal`).
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
tiene cobros, PDF en memoria (uno a la vez). El worker de colas ya existe en producción
(`sistemafe-worker`), así que el envío puede encolarse desde el inicio (reintentos gratis); el
comando solo arma y despacha.

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

## Verificar antes de construir
- ~~Producción sin correo~~ **RESUELTO 11-oct-2026:** hasta ese día `MAIL_MAILER=log` (ningún
  correo salía; en el log solo había un "Recordatorio de pago vencido — INV-1-202609" nunca
  entregado, ningún backup fallido). Ahora: **Brevo** (plan gratis) por SMTP
  (`smtp-relay.brevo.com:587`), remitente verificado `UmboSystem <avisos@umbosystem.com>`, dominio
  autenticado en Brevo (DKIM `brevo1/brevo2._domainkey` CNAME + `brevo-code` TXT + `_dmarc`
  `p=none`, en Cloudflare, nube gris). `avisos@` recibe vía **Cloudflare Email Routing** → reenvía
  a `umbosac@gmail.com` (MX `route1-3.mx.cloudflare.net` + SPF de Cloudflare). Prueba real
  entregada. Pendientes chicos: rotar la clave SMTP (quedó parcialmente expuesta en un
  `config:show`), endurecer DMARC a `p=quarantine` tras unas semanas estables, borrar en Brevo el
  remitente `umbosac@gmail.com` (no apto: dominio gmail.com).
- **Producción sí tiene worker de colas** (`sistemafe-worker` bajo supervisor, `deploy.sh` lo
  reinicia): el envío de correos/push puede ir a la cola (`ShouldQueue`) en vez de síncrono en el
  cron. Ojo: tras cambiar `.env`, `config:cache` + reload de php-fpm + `supervisorctl restart
  sistemafe-worker:*`.
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
