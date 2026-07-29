// src/services/admin/temporadaService.ts — Sesión 11a (agencia de viajes)
// Catálogo CENTRAL (compartido por todo el rubro) — ver comentario de
// TemporadaController en el backend antes de editar/eliminar a la ligera.
import httpClient from '@/helpers/http-client'
import type { Temporada, TemporadaOcurrencia } from '@/types/agencia-viajes'

export const temporadaService = {
  async listar() {
    const response = await httpClient.get('/temporadas')
    return response.data as { temporadas: Temporada[] }
  },
  async crear(data: Partial<Temporada>) {
    const response = await httpClient.post('/temporadas', data)
    return response.data
  },
  async actualizar(id: number, data: Partial<Temporada>) {
    const response = await httpClient.put(`/temporadas/${id}`, data)
    return response.data
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/temporadas/${id}`)
    return response.data
  },

  // ── Ocurrencias anuales ──────────────────────────────────────────────
  async listarOcurrencias(temporadaId: number) {
    const response = await httpClient.get(`/temporadas/${temporadaId}/ocurrencias`)
    return response.data as { temporada_ocurrencias: TemporadaOcurrencia[] }
  },
  async crearOcurrencia(temporadaId: number, data: Partial<TemporadaOcurrencia>) {
    const response = await httpClient.post(`/temporadas/${temporadaId}/ocurrencias`, data)
    return response.data
  }
}
