// Módulo Créditos, Fase 4c — ficha del cliente exigida para prestar: textos de los requisitos
// (mismos que RequisitoFicha::etiqueta() del backend) y de la situación del cliente.
import type { RequisitoFicha } from '@/types/creditos'

export const ETIQUETA_REQUISITO: Record<RequisitoFicha, string> = {
  dni_anverso: 'DNI (anverso)',
  dni_reverso: 'DNI (reverso)',
  foto_cliente: 'Foto del cliente',
  telefono: 'Teléfono',
  direccion_cobro: 'Dirección de cobro',
  referencia: 'Referencia de la dirección',
  ubicacion: 'Ubicación en el mapa',
  ocupacion: 'Ocupación o negocio',
}

export const REQUISITOS = Object.keys(ETIQUETA_REQUISITO) as RequisitoFicha[]

/** "DNI (reverso), Ubicación en el mapa" a partir de los códigos que manda el motor. */
export function textoFaltan(faltan: unknown): string {
  if (!Array.isArray(faltan)) return ''
  return faltan.map((r) => ETIQUETA_REQUISITO[r as RequisitoFicha] ?? String(r)).join(', ')
}

export type SituacionCliente = 'al_dia' | 'atrasado' | 'bloqueado' | 'sin_creditos'

export const PRESENTACION_SITUACION: Record<SituacionCliente, { texto: string; clase: string }> = {
  al_dia: { texto: 'Al día', clase: 'bg-success-subtle text-success-emphasis' },
  atrasado: { texto: 'Atrasado', clase: 'bg-danger-subtle text-danger-emphasis' },
  bloqueado: { texto: 'Bloqueado', clase: 'bg-dark-subtle text-dark-emphasis' },
  sin_creditos: { texto: 'Sin créditos', clase: 'bg-secondary-subtle text-secondary-emphasis' },
}
