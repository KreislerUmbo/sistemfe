// src/services/admin/reservaService.ts — Sesión 11c (reserva y pasajeros)
import httpClient from '@/helpers/http-client'
import type { ReservaDetalleResponse, Reservas, MotivoCancelacion } from '@/types/agencia-viajes'

export const reservaService = {
  async listar(params: { page?: number; search?: string; estado?: 'activa' | 'cancelada' } = {}) {
    const response = await httpClient.get('/reservas', { params })
    return response.data as Reservas
  },
  async obtener(id: number) {
    const response = await httpClient.get(`/reservas/${id}`)
    return response.data as ReservaDetalleResponse
  },
  // Aceptar una alternativa (AlternativaController::update() en 11b ya NO
  // dispara esto — este es el endpoint nuevo de 11c). pasajeroCatalogoIds
  // es opcional y alineado por orden con cotizacion_pasajeros.
  async aceptarAlternativa(alternativaId: number, pasajeroCatalogoIds?: Array<number | null>) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/aceptar`, {
      pasajero_catalogo_ids: pasajeroCatalogoIds ?? []
    })
    return response.data as ReservaDetalleResponse & { code: number; message: string }
  },
  async cancelar(id: number, motivo_cancelacion: MotivoCancelacion) {
    const response = await httpClient.put(`/reservas/${id}/cancelar`, { motivo_cancelacion })
    return response.data
  }
}
