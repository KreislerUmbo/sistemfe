// Módulo Créditos — documentos (Fase 4b): nombres de archivo y mensajes para compartir.
// Funciones puras; la descarga y el "Compartir" viven en useDocumentosCredito.
import { telefonoInternacional } from './cobranza'

/** "recibo-RC-00000004.pdf" (sin espacios ni caracteres raros). */
export function nombreArchivo(base: string, numero?: string | null): string {
  const limpio = `${base}${numero ? `-${numero}` : ''}`.normalize('NFD').replace(/\p{Diacritic}/gu, '').replace(/[^A-Za-z0-9-]+/g, '-')
  return `${limpio.replace(/-+/g, '-').replace(/^-|-$/g, '')}.pdf`
}

/** Mensaje que acompaña al PDF compartido (o que se manda solo si no se puede adjuntar). */
export function mensajeDocumento(documento: string, cliente?: string | null): string {
  const saludo = cliente ? `Hola ${cliente.split(' ')[0]}, ` : 'Hola, '
  return `${saludo}le enviamos su ${documento}. Gracias por su pago.`
}

/** wa.me con el texto prellenado; sin celular abre WhatsApp para elegir el contacto. */
export function enlaceWhatsappConTexto(telefono: string | null | undefined, texto: string): string {
  const numero = telefonoInternacional(telefono)
  const destino = numero && numero.startsWith('519') ? numero : ''
  return `https://wa.me/${destino}?text=${encodeURIComponent(texto)}`
}
