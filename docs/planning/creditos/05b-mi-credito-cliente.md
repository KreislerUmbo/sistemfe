# Fase 5b — "Mi crédito": el cliente consulta su cuota

Estado: diseño aprobado (11-oct-2026); **entregas 1 (página "Mi crédito") y 2 (QR en recibos y
contrato) construidas** en la rama `feat/creditos-mi-credito`, sin merge. Falta la 3 (ficha del
cliente y link en el recordatorio de WhatsApp). Es la otra
mitad del pedido original de la Fase 5: que el **cliente** sepa su cuota (número N de M), cuánto le
falta y la próxima fecha. El aviso interno (administrador/cobrador) está en `05-avisos-cobranza.md`.
Reglas de fondo: `00-reglas-y-modelo.md`.

## Objetivo
Que el cliente pueda ver **en cualquier momento, sin llamar al negocio**, el estado de su crédito,
con **costo cero por mensaje**: lo consulta él mismo desde un link/QR que recibe una vez. El
"aviso" automático pagado (WhatsApp API) queda descartado por costo (ver análisis en 05).

## Decisiones (aprobadas 11-oct-2026)
1. **Link permanente por cliente** (no por crédito): sirve para todos sus créditos activos y
   sobrevive a renovaciones; los QR de recibos viejos siguen funcionando.
2. **Protección:** la primera vez en cada celular pide los **últimos 4 dígitos de su documento**
   (DNI; si es RUC/CE/pasaporte, los últimos 4 caracteres). Ese celular queda **recordado 90 días**.
   **5 intentos fallidos** bloquean el link 15 minutos y queda registrado.
3. **QR en contrato y recibos** (ticket 80 mm y A4), con el link escrito debajo por si la cámara
   no lee. **Un solo QR por recibo**: resuelve también el pendiente "QR de verificación del
   recibo" (el cliente ve ese pago registrado en "Mi crédito").
4. **Regenerar el link** (si se filtró) invalida el anterior: los QR ya impresos muestran "Este
   enlace ya no es válido, pide uno nuevo al negocio".
5. **Envío por WhatsApp manual** desde el celular del cobrador (sin costo, sin riesgo de bloqueo).

## Qué ve el cliente
Página móvil, solo lectura, con el nombre y logo del negocio y su teléfono/WhatsApp de contacto.
Por cada crédito activo (los cancelados, solo "Cancelado el …"):
- **Próxima cuota:** "Cuota **3 de 6** · **S/ 150.00** · vence el **15/10/2026**".
- **Si está atrasado:** cuotas vencidas, días de atraso y mora → "Hoy debes S/ X".
- **Cuánto le falta:** cuotas restantes y saldo por pagar (capital + interés pendientes).
- **Pagos recientes:** fecha, monto y número de recibo (los últimos 5).
- Pie: "Datos al {fecha y hora}. Si ya pagaste hoy y no aparece, consulta con {negocio}."

Todos los montos salen del motor (los mismos que el estado de cuenta y la ficha:
`ConsultaCreditoService`/`ResumenDetalle` — `proxima`, `deuda_hoy`, `saldo_por_pagar`,
`cuotas_pagadas`/`cuotas_total`), nunca de una fórmula paralela.

## Diseño técnico

### Datos (tenant, `tenant/core`)
- `credito_enlaces_cliente`: `cliente_id` (único entre vigentes), `token_hash` (SHA-256; el token
  en claro no se guarda), `version` (sube al regenerar), `creado_por`, `revocado_en`,
  `intentos_fallidos`, `bloqueado_hasta`, `ultimo_acceso_en`, `accesos` (contador), timestamps.
- Registro de accesos y bloqueos en la auditoría existente del módulo (ficha del cliente).

### Link y QR
- URL corta del tenant: `https://{tenant}.umbosystem.com/c/{token}` (token aleatorio de ~16
  caracteres base62: imposible de adivinar; el DNI es una segunda barrera, no la única).
- Se crea **solo la primera vez que hace falta** (al imprimir un recibo/contrato o al enviarlo por
  WhatsApp); el cliente no tiene que "darse de alta".
- QR con `endroid/qr-code` (ya instalado, lo usa `QrCodeService` para comprobantes), embebido en el
  PDF como data URI. En el contrato va en un **bloque fijo** fuera de la plantilla editable (el
  negocio no puede borrarlo por error).

### Acceso público (sin usuario ni contraseña)
- Frontend: ruta pública `/c/:token` en `admin-start-kit` (como el portal, con `publicHttpClient`).
- API pública del tenant (sin `auth:api`, con `tenant` + `tenant.active` + `throttle` por IP):
  - `POST mi-credito/{token}/verificar` `{ultimos4}` → si coincide, devuelve una **credencial de
    dispositivo** firmada (HMAC con `app.key` sobre enlace + versión + vencimiento a 90 días), que
    el celular guarda en `localStorage`. Nada que guardar en el servidor; regenerar el link
    (versión nueva) invalida todas las credenciales de ese cliente.
  - `GET mi-credito/{token}` con la credencial en una cabecera → los datos de arriba.
- Límite: 5 fallos → `bloqueado_hasta = now + 15 min` (por link) + throttle por IP para que no
  se pueda barrer links distintos.
- Respuestas uniformes: link inexistente, revocado o bloqueado no revelan si el cliente existe.

### En el panel
- **Ficha del cliente → "Mi crédito"**: ver si tiene link, accesos recientes, **Enviar por
  WhatsApp** (abre `wa.me` con el texto y el link), **Copiar link**, **Regenerar** (permiso
  `creditos.crear`; pide confirmación porque invalida los QR impresos).
- **Agenda → recordatorio de WhatsApp**: el texto que ya arma `mensajeRecordatorio()` suma al final
  "Consulta tu crédito aquí: {link}".
- Recibos (80 mm y A4) y contrato: QR + link escrito.

## Proceso y legal
- **Consentimiento (Ley 29733):** cláusula en el contrato autorizando el tratamiento de sus datos y
  el envío de recordatorios — entra en la plantilla que está revisando el abogado.
- **Cobranza sin abusos (Código del Consumidor, art. 62):** la página es informativa (sin amenazas,
  sin datos de terceros); los recordatorios por WhatsApp los manda el cobrador en horario hábil.
- **Cliente sin documento registrado:** no se le puede pedir los 4 dígitos → el panel avisa al
  generar el link ("completa el documento del cliente") y el link no se activa hasta tenerlo.
- **Celular compartido o perdido:** el negocio regenera el link desde la ficha.

## Analítica
- `accesos` y `ultimo_acceso_en` por cliente: cuántos clientes usan la página.
- Indicador a comparar antes/después: cuotas pagadas a tiempo y días promedio de atraso entre
  clientes que entraron a "Mi crédito" y los que no.

## Costo
US$0 por mensaje. Servidor: una página liviana por consulta (el mismo cálculo que el estado de
cuenta); sin tareas programadas nuevas.

## Entregas
| # | Entrega | Días aprox. |
|---|---|---|
| 1 | Tabla, link por cliente, API pública (verificar 4 dígitos, credencial de 90 días, bloqueo, throttle), página `/c/:token` móvil, tests | 1.5–2 |
| 2 | QR + link en recibos (80 mm y A4) y contrato (bloque fijo) | 0.5–1 |
| 3 | Ficha del cliente (enviar por WhatsApp, copiar, regenerar, accesos) y link en el recordatorio de la Agenda | 0.5–1 |

### Entrega 1 — construida (11-oct-2026, rama `feat/creditos-mi-credito`)
- **Datos:** `credito_enlaces_cliente` (`2026_10_11_120000`): un link por cliente (`cliente_id`
  único), token **cifrado** con la APP_KEY (hay que reimprimirlo en cada recibo: no alcanza con el
  hash) + `token_hash` único para buscarlo, `version`, `intentos_fallidos`, `bloqueado_hasta`,
  `accesos`, `ultimo_acceso_en`, `creado_por`, `regenerado_en`.
- **`MiCredito\EnlaceClienteService`:** `paraCliente()` (lo crea la primera vez; sin documento →
  `SinDocumentoException` 422), `regenerar()` (nuevo token + versión, auditado), `verificar()`
  (5 fallos → 15 min, auditado), credencial de dispositivo `base64url(json{e,v,x}).hmac` firmada con
  la APP_KEY + id del tenant, `ultimos4()` (sin guiones/espacios, en mayúscula: sirve para RUC/CE).
  Token de 16 caracteres sin los que se confunden al tipear (0/O, 1/l/I).
- **`MiCredito\MiCreditoService`:** créditos activos y castigados (al cliente nunca se le dice
  "castigado": se ve "Con atraso") + los 3 cancelados más recientes; por crédito `proxima` (la
  primera cuota **todavía no vencida**), `vencido` (cuotas, días, monto, mora, total; `cuotas` del
  contador del motor porque una cuota pagada puede seguir debiendo mora), cuotas pagadas/restantes,
  saldo por pagar; últimos 5 pagos válidos (`fecha_pago` es fecha-hora de negocio Lima, tal cual).
  Todo sale de `ConsultaCreditoService::detallesEnBloque()`.
- **API pública** (`tenant` + `tenant.active` + `tenant.subscription` + `tenant.token`, sin sesión):
  `POST mi-credito/{token}/verificar` (`throttle:10,1`; 200 credencial / 422 con intentos
  restantes / 423 bloqueado) y `GET mi-credito/{token}` (`throttle:60,1`; cabecera
  `X-Mi-Credito`; 401 = pedir documento). Link inexistente o regenerado → 404 siempre igual.
- **API del panel** (para la ficha, entrega 3): `GET clientes/{id}/mi-credito` (`creditos.ver`,
  dentro de la cartera; crea el link si no existe) y `POST …/mi-credito/regenerar`
  (`creditos.crear`).
- **Frontend:** ruta pública `/c/:token` → `views/creditos/mi-credito.vue` (móvil, sin el menú del
  panel), `services/public/miCreditoService.ts` (axios propio sin sesión: no reutiliza el cliente
  del portal porque manda su token y redirige a su login ante un 401; credencial en
  `localStorage`, en memoria si el navegador no deja), helper `helpers/creditos/miCredito.ts`
  (+ Vitest). La marca del negocio sale del endpoint público `branding` (se ve antes de verificar).
  Botón WhatsApp al negocio (o llamar si el teléfono no es celular) y "Salir en este celular".
- **Verificación:** `MiCreditoTest` 7/7 (cuota y próxima fecha, atrasado con mora y pago que lo
  pone al día, bloqueo y desbloqueo a los 15 min, regenerar invalida link y celulares, credencial
  de 90 días y no transferible a otro cliente, firma alterada, documentos con letras, cliente sin
  documento, 404 sin pistas); Vitest 151/151; vue-tsc en el baseline. En vivo contra
  `creditos-demo` a 375 px con un cliente real con 3 créditos atrasados: dígitos incorrectos
  ("te quedan 4 intentos"), correctos → sus 3 créditos con mora y últimos pagos, "Salir en este
  celular", link inexistente. Link de prueba borrado después.
- **Al desplegar:** `tenants:migrate-verticales` (migración de `tenant/core`). Sin `.env` nuevo.
  Hasta la entrega 3 el link se obtiene con `GET clientes/{id}/mi-credito`.

### Entrega 2 — construida (11-oct-2026, misma rama)
- **`MiCredito\QrMiCredito::paraCliente()`**: crea el link si falta y devuelve el QR (data URI PNG,
  `QrCodeService::generarQrTexto()` nuevo, `endroid/qr-code`) + el link escrito sin `https://`.
  **Cliente sin documento → null: el documento sale igual, sin QR** (imprimir un cobro nunca falla
  por esto).
- **Partial** `pdf/creditos/_mi_credito_qr.blade.php`: ticket (centrado, QR 92 px + link) y A4
  (recuadro con QR 86 px y "Consulta tu crédito… te pediremos los últimos 4 dígitos de tu DNI").
- **Recibo** 80 mm y A4: QR al final (el ticket crece 135 pt). Resuelve el pendiente "QR de
  verificación del recibo": el cliente ve ese pago en "Mi crédito".
- **Contrato**: bloque **al final, después de las firmas** (cambio respecto del diseño, que decía
  "primera página": arriba va la plantilla del abogado y un QR superpuesto podría tapar el título;
  al final no choca con nada y queda fuera de lo editable). Solo en contratos **nuevos**: el
  contrato se congela la primera vez, los ya generados no se redibujan. La **vista previa** de la
  plantilla muestra un recuadro "[QR Mi crédito]" sin crear el link de ningún cliente.
- **Verificación:** `MiCreditoTest` 10/10 (+3: recibo con QR crea un solo link, sin documento sale
  sin QR y sin link, vista previa no crea links); `DocumentosCreditoTest` 11/11 sin cambios. PDFs
  revisados rasterizados (ticket, recibo A4, contrato).

## Fuera de alcance (posible fase siguiente)
- **Avisos push al cliente** desde la misma página "Mi crédito" (botón "Avisarme un día antes de
  mi cuota"), reutilizando la infraestructura Web Push de la entrega 2 de la Fase 5: costo cero,
  opt-in del cliente. En iPhone exige agregar la página a inicio.
- WhatsApp automático (API de Meta) como adicional pagado `creditos_whatsapp`.
- Pagar desde la página (pasarela/Yape): no previsto.
