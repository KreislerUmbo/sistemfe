// src/services/admin/alternativaService.ts — Sesión 11b (cotizador)
import httpClient from '@/helpers/http-client'
import type { AlternativaResponse } from '@/types/agencia-viajes'

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
  async actualizar(id: number, data: { nombre?: string; estado?: string; descuento_global_pct?: number | null; descuento_global_monto?: number | null }) {
    const response = await httpClient.put(`/alternativas/${id}`, data)
    return response.data as AlternativaResponse
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/alternativas/${id}`)
    return response.data
  },
  // Sesión 11h — clona la alternativa completa (ítems + opciones de
  // mayorista) en una alternativa nueva de la misma cotización.
  async duplicar(id: number) {
    const response = await httpClient.post(`/alternativas/${id}/duplicar`)
    return response.data as AlternativaResponse
  }
}
