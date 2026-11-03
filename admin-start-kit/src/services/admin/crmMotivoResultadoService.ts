// src/services/admin/crmMotivoResultadoService.ts — CRM C3 (T-052): motivos de venta ganada /
// perdida. Configuración de Agencia los mantiene (sin borrar: se desactivan); los modales de
// «Cerrar como perdida» y «Aceptar» leen los activos de su tipo.
import httpClient from '@/helpers/http-client'

export type TipoMotivoResultado = 'ganada' | 'perdida'

export type MotivoResultado = {
  id: number
  tipo: TipoMotivoResultado
  nombre: string
  activo: boolean
  orden: number
  /** «Otro»: no se renombra ni se desactiva; al perder exige detalle. */
  es_otro: boolean
}

export const DETALLE_RESULTADO_MAX = 500

export const crmMotivoResultadoService = {
  async listar(params: { tipo?: TipoMotivoResultado; solo_activos?: boolean } = {}) {
    const response = await httpClient.get('/crm-motivos-resultado', {
      params: { tipo: params.tipo, solo_activos: params.solo_activos ? 1 : undefined },
    })
    return response.data.motivos as MotivoResultado[]
  },
  async crear(data: { tipo: TipoMotivoResultado; nombre: string }) {
    const response = await httpClient.post('/crm-motivos-resultado', data)
    return response.data.motivo as MotivoResultado
  },
  async actualizar(id: number, data: Partial<Pick<MotivoResultado, 'nombre' | 'activo' | 'orden'>>) {
    const response = await httpClient.put(`/crm-motivos-resultado/${id}`, data)
    return response.data.motivo as MotivoResultado
  },
}
