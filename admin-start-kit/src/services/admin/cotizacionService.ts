// src/services/admin/cotizacionService.ts — Sesión 11b (cotizador)
import httpClient from '@/helpers/http-client'
import type { Cotizacion, CotizacionPasajero } from '@/types/agencia-viajes'

export const cotizacionService = {
  async listar(params: { page?: number; search?: string; estado?: string } = {}) {
    const response = await httpClient.get('/cotizaciones', { params })
    return response.data
  },
  async obtener(id: number) {
    const response = await httpClient.get(`/cotizaciones/${id}`)
    return response.data as { cotizacion: Cotizacion }
  },
  async crear(data: {
    cliente_id: number
    codigo_prefijo: string
    destino: string
    fecha_viaje_tentativa?: string | null
    pasajeros: Array<{ edad: number }>
  }) {
    const response = await httpClient.post('/cotizaciones', data)
    return response.data
  },
  async actualizarPasajeros(id: number, pasajeros: Array<Partial<CotizacionPasajero>>) {
    const response = await httpClient.put(`/cotizaciones/${id}/pasajeros`, { pasajeros })
    return response.data
  }
}
