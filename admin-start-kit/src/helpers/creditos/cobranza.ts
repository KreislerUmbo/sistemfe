// Módulo Créditos — Cobranza del día (04-frontend pantalla 5, mockup 4): enlaces para
// contactar al cliente y textos de estado. Sin aritmética de dinero.
import type { PendienteCobranza } from '@/types/creditos'

/** Solo dígitos; los celulares peruanos (9 dígitos, empiezan en 9) llevan el 51 delante. */
export function telefonoInternacional(telefono: string | null | undefined): string | null {
  const digitos = (telefono ?? '').replace(/\D/g, '')
  if (digitos.length === 9 && digitos.startsWith('9')) return `51${digitos}`
  if (digitos.length === 11 && digitos.startsWith('519')) return digitos
  return digitos.length >= 7 ? digitos : null
}

export function enlaceLlamada(telefono: string | null | undefined): string | null {
  const digitos = (telefono ?? '').replace(/[^\d+]/g, '')
  return digitos.length >= 6 ? `tel:${digitos}` : null
}

/** WhatsApp solo con celular: un fijo no tiene WhatsApp. */
export function enlaceWhatsapp(telefono: string | null | undefined): string | null {
  const numero = telefonoInternacional(telefono)
  return numero && numero.startsWith('519') ? `https://wa.me/${numero}` : null
}

/** Coordenadas si la ficha las tiene; si no, la dirección como búsqueda. */
export function enlaceMapa(cliente: Pick<PendienteCobranza['cliente'], 'latitud' | 'longitud' | 'direccion_cobro'>): string | null {
  const consulta = cliente.latitud && cliente.longitud ? `${cliente.latitud},${cliente.longitud}` : cliente.direccion_cobro?.trim()
  return consulta ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(consulta)}` : null
}

export function textoEstado(p: Pick<PendienteCobranza, 'estado' | 'dias_atraso'>): { texto: string; clase: string } {
  if (p.estado === 'hoy') return { texto: 'Vence hoy', clase: 'text-success' }
  const dias = p.dias_atraso
  return { texto: dias > 0 ? `Vencido ${dias} día${dias === 1 ? '' : 's'}` : 'Vencido', clase: 'text-danger' }
}

/** "2026-10-15" → "Jueves 15 de octubre" (sin corrimiento de zona horaria). */
export function fechaLarga(ymd: string): string {
  const [anio, mes, dia] = ymd.split('-').map(Number)
  const texto = new Intl.DateTimeFormat('es-PE', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' })
    .format(new Date(Date.UTC(anio, mes - 1, dia)))
    .replace(',', '')
  return texto.charAt(0).toUpperCase() + texto.slice(1)
}

/** Búsqueda local por nombre, dirección o N.º de crédito (sin tildes ni mayúsculas). */
export function coincide(p: Pick<PendienteCobranza, 'numero_credito'> & { cliente: { nombre: string; direccion_cobro?: string | null } }, texto: string): boolean {
  const normalizar = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
  const buscado = normalizar(texto.trim())
  if (!buscado) return true
  return [p.cliente.nombre, p.cliente.direccion_cobro ?? '', p.numero_credito ?? ''].some((campo) => normalizar(campo).includes(buscado))
}
