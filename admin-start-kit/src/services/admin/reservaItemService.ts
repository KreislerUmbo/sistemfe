// src/services/admin/reservaItemService.ts — Sesión 11c (reserva y pasajeros)
import httpClient from '@/helpers/http-client'
import type { ReservaItem, ReservaItemPasajero } from '@/types/agencia-viajes'

export const reservaItemService = {
  async actualizar(id: number, data: { guia_id?: number | null; proveedor_tarifa_id?: number | null; fecha?: string | null; hora?: string | null }) {
    const response = await httpClient.put(`/reserva-items/${id}`, data)
    return response.data as { code: number; message: string; reserva_item: ReservaItem }
  },

  // ── Asignación pasajero↔ítem (reserva_item_pasajero) ──────────────────
  async listarPasajerosAsignados(reservaItemId: number) {
    const response = await httpClient.get(`/reserva-items/${reservaItemId}/pasajeros`)
    return response.data as { reserva_item_pasajeros: ReservaItemPasajero[] }
  },
  async asignarPasajero(reservaItemId: number, reserva_pasajero_id: number) {
    const response = await httpClient.post(`/reserva-items/${reservaItemId}/pasajeros`, { reserva_pasajero_id })
    return response.data
  },
  async quitarPasajero(reservaItemPasajeroId: number) {
    const response = await httpClient.delete(`/reserva-item-pasajero/${reservaItemPasajeroId}`)
    return response.data
  }
}
