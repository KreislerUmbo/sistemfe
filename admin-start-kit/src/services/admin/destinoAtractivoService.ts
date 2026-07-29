// src/services/admin/destinoAtractivoService.ts — Sesión 11a (agencia de viajes)
import httpClient from '@/helpers/http-client'
import type { DestinoAtractivo, DestinoServicio } from '@/types/agencia-viajes'

export const destinoAtractivoService = {
  async arbol() {
    const response = await httpClient.get('/destinos-atractivos')
    return response.data as { destinos_atractivos: DestinoAtractivo[] }
  },
  async listarPorTipo(tipo: 'zona' | 'lugar' | 'atractivo') {
    const response = await httpClient.get('/destinos-atractivos', { params: { tipo } })
    return response.data as { destinos_atractivos: DestinoAtractivo[] }
  },
  async crear(data: FormData | Partial<DestinoAtractivo>) {
    const response = await httpClient.post('/destinos-atractivos', data, {
      headers: data instanceof FormData ? { 'Content-Type': 'multipart/form-data' } : {}
    })
    return response.data
  },
  async actualizar(id: number, data: FormData | Partial<DestinoAtractivo>) {
    const response = await httpClient.post(`/destinos-atractivos/${id}`, data, {
      headers: data instanceof FormData ? { 'Content-Type': 'multipart/form-data' } : {}
    })
    return response.data
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/destinos-atractivos/${id}`)
    return response.data
  },

  // ── Servicios asociados a un destino (destino_servicio) ─────────────
  async listarServicios(destinoId: number) {
    const response = await httpClient.get(`/destinos-atractivos/${destinoId}/servicios`)
    return response.data as { destino_servicios: DestinoServicio[] }
  },
  async asociarServicio(destinoId: number, servicio_id: number) {
    const response = await httpClient.post(`/destinos-atractivos/${destinoId}/servicios`, { servicio_id })
    return response.data
  },
  async desasociarServicio(destinoServicioId: number) {
    const response = await httpClient.delete(`/destino-servicio/${destinoServicioId}`)
    return response.data
  }
}
