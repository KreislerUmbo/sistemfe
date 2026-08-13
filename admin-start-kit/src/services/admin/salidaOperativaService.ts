// src/services/admin/salidaOperativaService.ts — feature/salida-operativa
import httpClient from '@/helpers/http-client'
import type { SalidaOperativa, SalidasOperativasResponse } from '@/types/agencia-viajes'

export const salidaOperativaService = {
  async listar(params: { fecha_desde?: string; fecha_hasta?: string; estado?: string } = {}) {
    const response = await httpClient.get<SalidasOperativasResponse>('/salidas-operativas', { params })
    return response.data
  },
  async ver(id: number) {
    const response = await httpClient.get(`/salidas-operativas/${id}`)
    return response.data as SalidaOperativa & { code: number }
  },
  async actualizar(id: number, data: Partial<SalidaOperativa>) {
    const response = await httpClient.put(`/salidas-operativas/${id}`, data)
    return response.data
  },
  async cancelar(id: number) {
    const response = await httpClient.put(`/salidas-operativas/${id}/cancelar`)
    return response.data
  },
  async adjuntarItem(salidaId: number, reservaItemId: number) {
    const response = await httpClient.post(`/salidas-operativas/${salidaId}/adjuntar-item`, { reserva_item_id: reservaItemId })
    return response.data
  },
  async desengancharItem(salidaId: number, reservaItemId: number) {
    const response = await httpClient.delete(`/salidas-operativas/${salidaId}/items/${reservaItemId}`)
    return response.data
  },
}
