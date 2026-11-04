// src/services/admin/ventaDirectaService.ts — Sesión 11c, atajo §4.1
import httpClient from '@/helpers/http-client'
import type { ReservaDetalleResponse } from '@/types/agencia-viajes'

export type VentaDirectaPayload = {
  cliente_id: number
  destino: string
  // T-045: obligatoria (sin fecha el servicio no sale en el Reporte operativo).
  fecha_servicio: string
  origen_tipo: 'proveedor' | 'manual'
  pax: Array<{ edad: number }>
  // origen_tipo=proveedor
  proveedor_tarifa_id?: number
  modo_precio?: 'por_persona' | 'tarifa_fija'
  cantidad?: number
  // origen_tipo=manual
  descripcion_manual?: string
  costo_snapshot?: number
  precio_venta_snapshot?: number
  moneda_costo?: 'PEN' | 'USD'
}

// T-045 — total en vivo de una tarifa de proveedor (POST venta-directa/vista-previa).
export type TipoPaxVistaPrevia = 'adulto' | 'nino' | 'infante'

export type VistaPreviaVentaDirecta = {
  code: number
  moneda: 'PEN' | 'USD'
  por_persona: {
    total: number
    desglose: Record<TipoPaxVistaPrevia, { cantidad: number; precio: number }>
  }
  tarifa_fija: { total: number; precio_unitario: number; cantidad: number }
}

export const ventaDirectaService = {
  async crear(data: VentaDirectaPayload) {
    const response = await httpClient.post('/venta-directa', data)
    return response.data as ReservaDetalleResponse & { code: number; message: string }
  },

  async vistaPrevia(data: { pax: Array<{ edad: number }>; proveedor_tarifa_id: number; cantidad: number }) {
    const response = await httpClient.post('/venta-directa/vista-previa', data)
    return response.data as VistaPreviaVentaDirecta
  }
}
