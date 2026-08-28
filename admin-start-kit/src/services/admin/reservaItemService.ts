// src/services/admin/reservaItemService.ts — Sesión 11c (reserva y pasajeros)
import httpClient from '@/helpers/http-client'
import type { ReservaItem, ReservaItemPasajero, ReservaItemVueloPasajero } from '@/types/agencia-viajes'

export type ActualizarVueloPayload = {
  vuelo_numero_ida?: string | null
  vuelo_fecha_ida?: string | null
  vuelo_hora_ida?: string | null
  vuelo_numero_vuelta?: string | null
  vuelo_fecha_vuelta?: string | null
  vuelo_hora_vuelta?: string | null
  vuelo_aerolinea_confirmada?: string | null
}

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
  },

  // Quitar un servicio completo de la reserva (Fase D del backend, conectado
  // recién en la auditoría 2026-08-27 — el endpoint ya existía sin ningún
  // botón que lo llamara).
  async eliminar(id: number) {
    const response = await httpClient.delete(`/reserva-items/${id}`)
    return response.data as { code: number; message: string }
  },

  // Vuelo vendido por la AGENCIA (corrección 2026-08-27 — tabla propia
  // ReservaItemVueloPasajero, desacoplada del checkbox de Asignación tras
  // un bug real encontrado en pruebas). Distinto de
  // reservaPasajeroService.actualizar() (vuelo por cuenta propia del
  // pasajero). Solo aplica a ítems origen_tipo='pasaje_aereo'.
  async actualizarVuelo(reservaItemId: number, pasajeroId: number, payload: ActualizarVueloPayload) {
    const response = await httpClient.put(`/reserva-items/${reservaItemId}/pasajeros/${pasajeroId}/vuelo`, payload)
    return response.data as { code: number; message: string; reserva_item_vuelo_pasajero: ReservaItemVueloPasajero }
  }
}
