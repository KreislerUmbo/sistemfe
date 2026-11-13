// src/services/admin/revisionAlternativaService.ts — T-022: "¿esta propuesta está lista para el
// cliente?". El backend (RevisionAlternativaService) arma la lista; acá solo se pide y se aplica
// "Actualizar al precio nuevo".
import httpClient from '@/helpers/http-client'

export type NivelRevision = 'bloquea' | 'aviso'

export type CodigoRevision =
  | 'sin_servicios'
  | 'precio_cero'
  | 'markup_bajo'
  | 'tarifa_precio_nuevo'
  | 'tarifa_no_vigente'
  | 'pasaje_tarifa_vencida'
  | 'propuesta_vencida'

export type DetallePrecioNuevo = {
  item_id: number
  tarifa_nueva_id: number
  precio_actual: number
  precio_nuevo: number
}

export type RevisionEntrada = {
  codigo: CodigoRevision
  nivel: NivelRevision
  mensaje: string
  cantidad: number
  destino: {
    item_ids: number[]
    /** 'lienzo' | 'datos_cotizacion' | 'pasaje_aereo' cuando no hay un servicio al que ir. */
    seccion: string | null
  }
  /** Solo 'tarifa_precio_nuevo' trae un detalle utilizable (DetallePrecioNuevo[]). */
  detalle: Array<Partial<DetallePrecioNuevo> & Record<string, unknown>>
}

export const revisionAlternativaService = {
  async obtener(alternativaId: number) {
    const response = await httpClient.get(`/alternativas/${alternativaId}/revision`)
    return response.data as { revision: RevisionEntrada[]; bloquea: boolean }
  },
  async actualizarTarifa(itemId: number) {
    const response = await httpClient.put(`/alternativa-items/${itemId}/actualizar-tarifa`)
    return response.data as { message: string; total: number }
  },
}

export const hayBloqueo = (revision: RevisionEntrada[]): boolean => revision.some((r) => r.nivel === 'bloquea')
