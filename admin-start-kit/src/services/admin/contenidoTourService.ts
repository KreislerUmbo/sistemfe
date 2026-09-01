// src/services/admin/contenidoTourService.ts — Sesión 12e
import httpClient from '@/helpers/http-client'
import type { ContenidoTour, ContenidoTourCategoria } from '@/types/agencia-viajes'

export const contenidoTourService = {
  async buscar(params: { categoria?: ContenidoTourCategoria; q?: string }): Promise<ContenidoTour[]> {
    const response = await httpClient.get('/contenido-tour', { params })
    return response.data.contenido_tour
  },
  async crear(data: {
    nombre: string
    categoria: ContenidoTourCategoria
    descripcion?: string
    incluye?: string
    no_incluye?: string
    destino_atractivo_id?: number | null
  }): Promise<ContenidoTour> {
    const response = await httpClient.post('/contenido-tour', data)
    return response.data.contenido_tour
  }
}
