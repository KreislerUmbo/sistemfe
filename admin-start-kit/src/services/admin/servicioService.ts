// src/services/admin/servicioService.ts — Sesión 11a (agencia de viajes)
import httpClient from '@/helpers/http-client'
import type { Servicio } from '@/types/agencia-viajes'

export const servicioService = {
  async listar(params: { page?: number; search?: string } = {}) {
    const response = await httpClient.get('/servicios', { params })
    return response.data
  },
  async crear(data: Partial<Servicio>) {
    const response = await httpClient.post('/servicios', data)
    return response.data
  },
  async actualizar(id: number, data: Partial<Servicio>) {
    const response = await httpClient.put(`/servicios/${id}`, data)
    return response.data
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/servicios/${id}`)
    return response.data
  }
}
