# Fase 4b — Documentos del crédito

Estado: **construida** el 2026-10-01 (rama `feat/creditos-fase4b-documentos`); **en producción** desde el
05-oct-2026 (main `1974b7c`). El recibo de una renovación muestra desde el 08-oct el desglose "cubierto con
el crédito X" / "pagado por el cliente". Reglas de fondo: Plan §1.15 y `00-reglas-y-modelo.md`.

## Decisiones tomadas con el usuario (01-oct-2026)
| Tema | Plan original (1.15) | Decidido |
|---|---|---|
| WhatsApp | `wa.me` + link firmado (recibo permanente, contrato 7 días) | **Compartir el PDF**: en el celular, hoja "Compartir" nativa con el archivo adjunto (sin links públicos con datos personales); en la PC se descarga el PDF y se abre WhatsApp con un mensaje para adjuntarlo |
| Congelar el contrato | Al activar | **Al pedirlo la primera vez** (normalmente en la entrega); después siempre el mismo archivo |

## Documentos
| Documento | Formatos | Dónde |
|---|---|---|
| Recibo de pago | 80mm, A4 | Al terminar el cobro (original) y en la pestaña Pagos (reimpresión = **COPIA**; anulado = **ANULADO**) |
| Contrato | A4 | Detalle → Documentos. Plantilla vigente + PDF guardado (`credito_documentos`, versión y SHA-256) |
| Contrato firmado | Foto o PDF subido | Detalle → Documentos → Subir (cámara en celular). Sirve también para créditos migrados |
| Cronograma | A4, 80mm | Detalle → Documentos |
| Estado de cuenta | A4, 80mm | Detalle → Documentos |
| Constancia de cancelación | A4 | Detalle → Documentos, solo crédito `finalizado` |
| Acuerdo de reprogramación | A4 | Detalle → Documentos, uno por reprogramación (lo pide 00 1.18) |

Saldo y próximo pago del recibo: a la fecha de ESE pago (motor con los pagos válidos hasta él),
no el de hoy.

## Piezas
- Backend: `Services/Creditos/Documentos/` (`DocumentoCreditoService`, `PlantillaContratoService`,
  `FormatoDocumento`), `CreditoDocumentoController`, `CreditoPlantillaController`, vistas en
  `resources/views/pdf/creditos/`. Migración `2026_10_01_100000` siembra la plantilla base
  ("BORRADOR — revisar con su abogado") si el tenant no tiene ninguna.
- URLs firmadas de 10 min (patrón de ventas/notas); la firma incluye el usuario (`?u=`) y el PDF
  vuelve a validar su cartera (`AlcanceCartera`).
- Plantilla: reemplazo simple de variables de lista blanca (nunca Blade), HTML sanitizado con
  `DOMDocument` al guardar, variables desconocidas rechazadas, cada guardado = versión nueva.
- Frontend: `DocumentosCredito.vue` (menú del Detalle), `PlantillaContrato.vue` (Configuración →
  Contrato), `useDocumentosCredito` (abrir/compartir), recibos en `PagosCredito`/`CobrarPanel`.

## Pendiente
- QR de verificación en el recibo (Plan 1.15): necesita una página pública de verificación; no se hizo.
- Actas de prenda (ingreso, devolución, venta): Fase 7.
- Texto legal real del contrato: lo define el abogado del cliente.
- Impresión silenciosa en ticketera (sin diálogo del navegador): sigue la decisión abierta del
  proyecto (kiosko / servicio local / ESC-POS).
