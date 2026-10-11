# Fase 5b — "Mi crédito": el cliente consulta su cuota

Estado: **diseño aprobado en sus decisiones de fondo** (11-oct-2026), sin construir. Es la otra
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

## Fuera de alcance (posible fase siguiente)
- **Avisos push al cliente** desde la misma página "Mi crédito" (botón "Avisarme un día antes de
  mi cuota"), reutilizando la infraestructura Web Push de la entrega 2 de la Fase 5: costo cero,
  opt-in del cliente. En iPhone exige agregar la página a inicio.
- WhatsApp automático (API de Meta) como adicional pagado `creditos_whatsapp`.
- Pagar desde la página (pasarela/Yape): no previsto.
