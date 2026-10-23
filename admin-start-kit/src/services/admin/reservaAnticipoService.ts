// src/services/admin/reservaAnticipoService.ts — Tier 0, conexión
// Adelantos↔Reservas (hallazgo de auditoría del módulo Adelantos,
// 2026-08-21). Cobra un anticipo directo desde la pantalla de la reserva
// (no desde el módulo genérico de Adelantos) — internamente el backend
// sigue reusando AdvanceController::store(), acá solo se manda lo mínimo:
// la moneda se deriva de la reserva; el cliente también, salvo que se elija
// otro (F1, 23-oct-2026).
import httpClient from '@/helpers/http-client'
import type { AnticipoReserva } from '@/types/agencia-viajes'

export type CrearAnticipoPayload = {
  monto: number
  medio_pago: string
  // Tier 1 del módulo Adelantos (2026-08-24): tratamiento tributario del
  // comprobante del anticipo — '10' gravado, '20' exonerado, '30' inafecto.
  tip_afe_igv: '10' | '20' | '30'
  notas?: string | null
  // F1 (23-oct-2026): a nombre de quién sale el anticipo; vacío = el cliente
  // de la cotización. La moneda sigue saliendo de la reserva.
  client_id?: number | null
}

export const reservaAnticipoService = {
  async crear(reservaId: number, payload: CrearAnticipoPayload) {
    const response = await httpClient.post(`/reservas/${reservaId}/anticipos`, payload)
    return response.data as { code: number; message: string; reserva_anticipo: AnticipoReserva }
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/reserva-anticipos/${id}`)
    return response.data as { code: number; message: string }
  }
}
