// src/services/admin/paquetePlantillaService.ts — Sesión 11b2 (agencia de viajes)
import httpClient from '@/helpers/http-client'
import type {
  PaquetePlantilla,
  PaquetePlantillaItem,
  TourItinerarioItem,
  PaquetePlantillaShowResponse,
  PaquetePlantillaPrecio,
  CalculadoraCostos,
} from '@/types/agencia-viajes'

export const paquetePlantillaService = {
  async listar(params: { page?: number; per_page?: number; search?: string; categoria?: string; tipo?: string; uso?: string; activo?: boolean; destino_atractivo_id?: number } = {}) {
    const response = await httpClient.get('/paquetes-plantilla', { params })
    return response.data
  },
  async obtener(id: number) {
    const response = await httpClient.get(`/paquetes-plantilla/${id}`)
    return response.data as PaquetePlantillaShowResponse
  },
  async crear(data: FormData | Record<string, any>) {
    const response = await httpClient.post('/paquetes-plantilla', data, {
      headers: data instanceof FormData ? { 'Content-Type': 'multipart/form-data' } : {}
    })
    return response.data
  },
  async actualizar(id: number, data: FormData | Record<string, any>) {
    const response = await httpClient.post(`/paquetes-plantilla/${id}`, data, {
      headers: data instanceof FormData ? { 'Content-Type': 'multipart/form-data' } : {}
    })
    return response.data
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/paquetes-plantilla/${id}`)
    return response.data
  },
  async duplicar(id: number) {
    const response = await httpClient.post(`/paquetes-plantilla/${id}/duplicar`)
    return response.data as { code: number; message: string; paquete_plantilla: PaquetePlantilla }
  },
  // 05-sep-2026 — mismo patrón que destinoAtractivoService.eliminarFoto().
  async eliminarFoto(id: number, path: string) {
    const response = await httpClient.delete(`/paquetes-plantilla/${id}/fotos`, { data: { path } })
    return response.data
  },
  // Mejora del PDF de cotización (plan-mejora-pdf-cotizacion-cliente.md
  // §4.5) — portada (una sola) + destacadas para la galería del itinerario
  // (hasta 4), ambas referencian paths ya existentes en `fotos`.
  async actualizarFotosPdf(id: number, data: { foto_portada: string | null; fotos_destacadas_pdf: string[] }) {
    const response = await httpClient.put(`/paquetes-plantilla/${id}/fotos-pdf`, data)
    return response.data
  },

  // ── Items incluidos (proveedor_tarifa o guia_tarifa) ────────────────
  async listarItems(paqueteId: number) {
    const response = await httpClient.get(`/paquetes-plantilla/${paqueteId}/items`)
    return response.data as { paquete_plantilla_items: PaquetePlantillaItem[] }
  },
  async agregarItem(paqueteId: number, data: { proveedor_tarifa_id?: number; guia_tarifa_id?: number; paquete_plantilla_hijo_id?: number; destino_servicio_id?: number; costo_estimado_persona?: number; orden?: number }) {
    const response = await httpClient.post(`/paquetes-plantilla/${paqueteId}/items`, data)
    return response.data
  },
  async quitarItem(itemId: number) {
    const response = await httpClient.delete(`/paquete-plantilla-items/${itemId}`)
    return response.data
  },
  // Fila 13, fase 1a — costo estimado, IGV y proveedor sugerido de un
  // servicio del Incluye (la tarifa de referencia no se cambia por acá).
  async actualizarItem(itemId: number, data: {
    proveedor_id?: number | null;
    costo_estimado_persona?: number | null;
    costo_estimado_persona_ext?: number | null;
    tip_afe_igv?: string | null;
    destino_tributario?: string | null;
  }) {
    const response = await httpClient.put(`/paquete-plantilla-items/${itemId}`, data)
    return response.data as { code: number; message: string; paquete_plantilla_item: PaquetePlantillaItem }
  },

  // ── Fila 13, fase 1a: calculadora y precios por temporada ───────────
  async calculadoraCostos(paqueteId: number, temporadaId: number | null = null) {
    const response = await httpClient.get(`/paquetes-plantilla/${paqueteId}/calculadora-costos`, {
      params: temporadaId ? { temporada_id: temporadaId } : {},
    })
    return response.data as CalculadoraCostos
  },
  async guardarPrecio(paqueteId: number, data: Partial<PaquetePlantillaPrecio>, precioId: number | null = null) {
    const response = precioId
      ? await httpClient.put(`/paquetes-plantilla/${paqueteId}/precios/${precioId}`, data)
      : await httpClient.post(`/paquetes-plantilla/${paqueteId}/precios`, data)
    return response.data as { code: number; message: string; precio: PaquetePlantillaPrecio }
  },
  async eliminarPrecio(paqueteId: number, precioId: number) {
    const response = await httpClient.delete(`/paquetes-plantilla/${paqueteId}/precios/${precioId}`)
    return response.data
  },
  async precioParaFecha(paqueteId: number, fecha: string) {
    const response = await httpClient.get(`/paquetes-plantilla/${paqueteId}/precio-para-fecha`, { params: { fecha } })
    return response.data as {
      precio_temporada: PaquetePlantillaPrecio | null;
      temporada: { id: number; nombre: string } | null;
      ocurrencia: { id: number; anio: number; fecha_desde: string; fecha_hasta: string } | null;
    }
  },

  // ── Itinerario día-por-día ───────────────────────────────────────────
  async listarItinerario(paqueteId: number) {
    const response = await httpClient.get(`/paquetes-plantilla/${paqueteId}/itinerario`)
    return response.data as { tour_itinerario_items: TourItinerarioItem[] }
  },
  async agregarPasoItinerario(paqueteId: number, data: Partial<TourItinerarioItem>) {
    const response = await httpClient.post(`/paquetes-plantilla/${paqueteId}/itinerario`, data)
    return response.data
  },
  async actualizarPasoItinerario(itemId: number, data: Partial<TourItinerarioItem>) {
    const response = await httpClient.put(`/tour-itinerario-items/${itemId}`, data)
    return response.data
  },
  async quitarPasoItinerario(itemId: number) {
    const response = await httpClient.delete(`/tour-itinerario-items/${itemId}`)
    return response.data
  },
  async reordenarItinerario(paqueteId: number, items: { id: number; dia_relativo: number; orden: number }[]) {
    const response = await httpClient.post(`/paquetes-plantilla/${paqueteId}/itinerario/reordenar`, { items })
    return response.data
  },
}

// Fila 13, fase 1c — conversión de los tours existentes al modelo nuevo
// (plan-tours-venta-simple-costo-real.md §3.11). Listar y ver la propuesta no
// escriben nada; confirmar() guarda lo que el usuario aceptó.
export type IgvServicio = { tip_afe_igv: string; destino_tributario: string }

export interface TourConversionFila {
  id: number
  codigo: string | null
  nombre: string
  activo: boolean
  precio_cargado: number | null
  precio_propuesto: number | null
  igv_tipos: string
  igv_mezclado: boolean
  servicios: number
  avisos: string[]
  conversion_confirmada_en: string | null
}

export interface TourConversionItem {
  id: number
  servicio: string
  proveedor: string | null
  costo_actual: number | null
  costo_sugerido: number | null
  detalle_sugerido: string | null
  motivo_sin_sugerencia: string | null
  costo_propuesto: number | null
  igv_actual: IgvServicio | null
  igv_tarifa: IgvServicio | null
  igv_propuesto: IgvServicio | null
}

export interface TourConversionPropuesta {
  id: number
  codigo: string | null
  nombre: string
  moneda: string
  igv_tour: IgvServicio
  igv_tour_es_default: boolean
  precio_cargado: number | null
  precio_propuesto: number | null
  precio_origen: 'cargado' | 'tarifas' | null
  suma_venta_tarifas: number
  ajuste_redondeo: number | null
  costo_tour_actual: number | null
  items: TourConversionItem[]
  avisos: string[]
  conversion_confirmada_en: string | null
}

export const conversionToursService = {
  async listar(): Promise<{ tours: TourConversionFila[]; fuera: { combos: number; solo_itinerario: number } }> {
    const response = await httpClient.get('/tours-conversion')
    return response.data
  },
  async propuesta(tourId: number): Promise<TourConversionPropuesta> {
    const response = await httpClient.get(`/tours-conversion/${tourId}`)
    return response.data.propuesta
  },
  async confirmar(tourId: number, data: {
    precio_venta_final: number
    costo_estimado_persona?: number | null
    items: { id: number; costo_estimado_persona: number | null; tip_afe_igv: string | null; destino_tributario: string | null }[]
  }): Promise<{ message: string; propuesta: TourConversionPropuesta }> {
    const response = await httpClient.post(`/tours-conversion/${tourId}/confirmar`, data)
    return response.data
  },
}
