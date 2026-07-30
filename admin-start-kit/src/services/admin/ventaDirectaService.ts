// src/services/admin/ventaDirectaService.ts — Sesión 11c, atajo §4.1
import httpClient from '@/helpers/http-client'
import type { ReservaDetalleResponse } from '@/types/agencia-viajes'

export type VentaDirectaPayload = {
  cliente_id: number
  destino: string
  fecha_servicio?: string | null
  origen_tipo: 'proveedor' | 'manual'
  pax: Array<{ edad: number }>
  // origen_tipo=proveedor
  proveedor_tarifa_id?: number
  modo_precio?: 'por_persona' | 'tarifa_fija'
  cantidad?: number
  // origen_tipo=manual
  descripcion_manual?: string
  precio_venta_snapshot?: number
  moneda_costo?: 'PEN' | 'USD'
}

export const ventaDirectaService = {
  async crear(data: VentaDirectaPayload) {
    const response = await httpClient.post('/venta-directa', data)
    return response.data as ReservaDetalleResponse & { code: number; message: string }
  }
}
