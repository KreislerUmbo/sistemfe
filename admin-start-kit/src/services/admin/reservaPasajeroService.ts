// src/services/admin/reservaPasajeroService.ts — Sesión 11c (reserva y pasajeros)
import httpClient from '@/helpers/http-client'
import type { PasajeroCatalogo, ReservaPasajero } from '@/types/agencia-viajes'

export const reservaPasajeroService = {
  async actualizar(id: number, data: Partial<ReservaPasajero>) {
    const response = await httpClient.put(`/reserva-pasajeros/${id}`, data)
    return response.data as { code: number; message: string; reserva_pasajero: ReservaPasajero }
  },
  // Autocompletar desde pasajeros_catalogo (Sesión 9c) — debounce real
  // (250ms) en el componente que llama, mismo criterio que el buscador de
  // cliente en Ventas.
  async buscarCatalogo(search: string) {
    const response = await httpClient.get('/pasajeros-catalogo', { params: { search } })
    return response.data as { pasajeros_catalogo: PasajeroCatalogo[] }
  }
}
