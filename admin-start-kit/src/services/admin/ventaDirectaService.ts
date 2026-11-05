// src/services/admin/ventaDirectaService.ts — Sesión 11c, atajo §4.1; T-085: varias líneas
import httpClient from '@/helpers/http-client'
import type { ReservaDetalleResponse } from '@/types/agencia-viajes'

export type MonedaVenta = 'PEN' | 'USD'

// Una línea de la venta (T-085). `dia` es el día del viaje (1 = fecha de inicio).
// En un hotel `cantidad` son las noches y el modo se fuerza en el backend.
export type LineaVentaDirecta =
  | {
      origen_tipo: 'proveedor'
      dia: number
      proveedor_tarifa_id: number
      modo_precio: 'por_persona' | 'tarifa_fija' | null
      cantidad: number
    }
  | {
      origen_tipo: 'manual'
      dia: number
      descripcion_manual: string
      precio_venta_snapshot: number
      costo_snapshot: number
      cantidad: number
    }

export type ResidenciaPasajero = 'nacional' | 'extranjero'

export type PasajeroVentaDirecta = {
  edad: number
  residencia?: ResidenciaPasajero
  nombre?: string
  documento?: string
}

export type VentaDirectaPayload = {
  cliente_id: number
  destino: string
  destino_atractivo_id?: number | null
  // Fecha de INICIO del viaje; cada línea suma su día.
  fecha_servicio: string
  // Una sola moneda por venta, sin conversión (D-AGE-034).
  moneda: MonedaVenta
  // T-046: residencia (nacional por defecto); nombre y documento solo si el vendedor los escribió.
  pax: PasajeroVentaDirecta[]
  // El cliente (persona natural) va de primer adulto; false si el vendedor lo quitó.
  cliente_viaja?: boolean
  lineas: LineaVentaDirecta[]
}

// POST venta-directa/vista-previa — total en vivo (T-045) y afectación por línea (T-085).
export type TipoPaxVistaPrevia = 'adulto' | 'nino' | 'infante'

export type VistaPreviaLinea = {
  indice: number
  origen_tipo: 'proveedor' | 'manual'
  es_hotel: boolean
  noches: number | null
  fecha: string | null
  fecha_salida: string | null
  tip_afe_igv: string
  destino_tributario: 'nacional' | 'amazonia' | 'extranjero'
  // null = en una tarifa sin hotel todavía no se eligió el modo de cobro.
  total: number | null
  por_persona?: {
    total: number
    desglose: Record<TipoPaxVistaPrevia, { cantidad: number; precio: number; extranjeros: number }>
  }
  tarifa_fija?: { total: number; precio_unitario: number; cantidad: number }
}

export type VistaPreviaVentaDirecta = {
  code: number
  moneda: MonedaVenta
  // Rangos de edad de la agencia (el vendedor no puede leer la configuración).
  umbrales: { infante_max: number; nino_max: number }
  total: number | null
  fecha_viaje_hasta: string | null
  lineas: VistaPreviaLinea[]
  // Mismo criterio que la facturación de reservas (backend).
  facturable: { ok: boolean; motivo: string | null; requiere_confirmar_amazonia: boolean }
}

export const ventaDirectaService = {
  async crear(data: VentaDirectaPayload) {
    const response = await httpClient.post('/venta-directa', data)
    return response.data as ReservaDetalleResponse & { code: number; message: string }
  },

  async vistaPrevia(data: Pick<VentaDirectaPayload, 'moneda' | 'lineas'> & { pax: Array<Pick<PasajeroVentaDirecta, 'edad' | 'residencia'>> } & { fecha_servicio: string }) {
    const response = await httpClient.post('/venta-directa/vista-previa', data)
    return response.data as VistaPreviaVentaDirecta
  }
}
