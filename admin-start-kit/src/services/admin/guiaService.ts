// src/services/admin/guiaService.ts — Sesión 11a (agencia de viajes)
import httpClient from '@/helpers/http-client'
import type { Guia, GuiaTarifa } from '@/types/agencia-viajes'

export const guiaService = {
  async listar(params: { page?: number; search?: string } = {}) {
    const response = await httpClient.get('/guias', { params })
    return response.data
  },
  async obtener(id: number) {
    const response = await httpClient.get(`/guias/${id}`)
    return response.data as { guia: Guia }
  },
  async crear(data: Partial<Guia>) {
    const response = await httpClient.post('/guias', data)
    return response.data
  },
  async actualizar(id: number, data: Partial<Guia>) {
    const response = await httpClient.put(`/guias/${id}`, data)
    return response.data
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/guias/${id}`)
    return response.data
  },

  // ── Tarifas por destino/modalidad ────────────────────────────────────
  async listarTarifas(guiaId: number) {
    const response = await httpClient.get(`/guias/${guiaId}/tarifas`)
    return response.data as { guia_tarifas: GuiaTarifa[] }
  },
  async crearTarifa(guiaId: number, data: Record<string, any>) {
    const response = await httpClient.post(`/guias/${guiaId}/tarifas`, data)
    return response.data
  }
}
