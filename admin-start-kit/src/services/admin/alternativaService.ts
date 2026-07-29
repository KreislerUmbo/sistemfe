// src/services/admin/alternativaService.ts — Sesión 11b (cotizador)
import httpClient from '@/helpers/http-client'

export const alternativaService = {
  async crear(cotizacionId: number, data: {
    nombre: string
    moneda_cotizacion: 'PEN' | 'USD'
    tipo_cambio_origen: 'dia' | 'agencia'
    tipo_cambio_valor?: number | null
  }) {
    const response = await httpClient.post(`/cotizaciones/${cotizacionId}/alternativas`, data)
    return response.data
  },
  async actualizar(id: number, data: { nombre?: string; estado?: string; descuento_global_pct?: number | null }) {
    const response = await httpClient.put(`/alternativas/${id}`, data)
    return response.data
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/alternativas/${id}`)
    return response.data
  }
}
