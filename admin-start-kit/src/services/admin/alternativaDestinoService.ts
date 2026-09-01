// src/services/admin/alternativaDestinoService.ts — Sesión 12f-1/12f-2
import httpClient from '@/helpers/http-client'
import type { AlternativaDestino } from '@/types/agencia-viajes'

export const alternativaDestinoService = {
  async listar(alternativaId: number): Promise<AlternativaDestino[]> {
    const response = await httpClient.get(`/alternativas/${alternativaId}/destinos`)
    return response.data.alternativa_destinos
  },
  async crear(alternativaId: number, data: {
    destino_atractivo_id?: number | null
    destino_texto: string
    fecha_inicio?: string | null
    fecha_fin?: string | null
  }): Promise<AlternativaDestino> {
    const response = await httpClient.post(`/alternativas/${alternativaId}/destinos`, data)
    return response.data.alternativa_destino
  },
  async actualizar(alternativaId: number, id: number, data: {
    destino_atractivo_id?: number | null
    destino_texto?: string
    fecha_inicio?: string | null
    fecha_fin?: string | null
  }): Promise<AlternativaDestino> {
    const response = await httpClient.put(`/alternativas/${alternativaId}/destinos/${id}`, data)
    return response.data.alternativa_destino
  },
  async eliminar(alternativaId: number, id: number) {
    const response = await httpClient.delete(`/alternativas/${alternativaId}/destinos/${id}`)
    return response.data
  }
}
