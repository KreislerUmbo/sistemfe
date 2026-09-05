// src/services/admin/opcionMayoristaService.ts — Sesión 11b (cotizador)
import httpClient from '@/helpers/http-client'

export const opcionMayoristaService = {
  async listar(alternativaId: number) {
    const response = await httpClient.get(`/alternativas/${alternativaId}/opciones-mayorista`)
    return response.data
  },
  async crear(alternativaId: number, data: {
    proveedor_id: number
    salida_mayorista_id?: number | null
    moneda: 'PEN' | 'USD'
    incluye?: string
    // Simulación Panamá (04-sep-2026) — "No incluye" del paquete base.
    no_incluye?: string
    notas?: string
    vuelo_aerolinea?: string
    vuelo_detalle?: string
    contenido_tour_id?: number | null
    alternativa_destino_id?: number | null
    // Fix C1 (02-sep-2026) — único texto que el PDF comercial puede
    // mostrar para esta opción; el nombre del mayorista nunca llega ahí.
    descripcion_publica?: string
  }) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/opciones-mayorista`, data)
    return response.data
  },
  async elegir(id: number) {
    const response = await httpClient.post(`/opciones-mayorista/${id}/elegir`)
    return response.data
  },
  async actualizar(id: number, data: {
    proveedor_id: number
    moneda: 'PEN' | 'USD'
    incluye?: string
    no_incluye?: string
    descripcion_publica?: string
    notas?: string
    vuelo_aerolinea?: string
    vuelo_detalle?: string
  }) {
    const response = await httpClient.put(`/opciones-mayorista/${id}`, data)
    return response.data
  },
  async descartar(id: number) {
    const response = await httpClient.post(`/opciones-mayorista/${id}/descartar`)
    return response.data
  },
  async reactivar(id: number) {
    const response = await httpClient.post(`/opciones-mayorista/${id}/reactivar`)
    return response.data
  },
  // Borrado real del bloque completo (distinto de descartar(), que solo lo
  // oculta) — 04-sep-2026, pedido explícito del usuario.
  async eliminar(id: number) {
    const response = await httpClient.delete(`/opciones-mayorista/${id}`)
    return response.data
  },
  async listarHoteles(opcionMayoristaId: number) {
    const response = await httpClient.get(`/opciones-mayorista/${opcionMayoristaId}/hoteles`)
    return response.data
  },
  async crearHotel(opcionMayoristaId: number, data: {
    nombre_hotel: string
    categoria_estrellas?: number
    proveedor_id?: number | null
    tarifas?: Array<{ tipo_habitacion: string; precio_costo: number; precio_venta: number; proveedor_tarifa_id?: number | null }>
  }) {
    const response = await httpClient.post(`/opciones-mayorista/${opcionMayoristaId}/hoteles`, data)
    return response.data
  },
  // 04-sep-2026 — antes solo se podía crear un hotel, nunca corregirlo ni
  // borrarlo. actualizarHotel toca solo la metadata (nombre/categoría/
  // proveedor); cada tipo de habitación se edita/borra/agrega aparte.
  async actualizarHotel(hotelId: number, data: { nombre_hotel: string; categoria_estrellas?: number | null; proveedor_id?: number | null }) {
    const response = await httpClient.put(`/opciones-hotel/${hotelId}`, data)
    return response.data
  },
  async eliminarHotel(hotelId: number) {
    const response = await httpClient.delete(`/opciones-hotel/${hotelId}`)
    return response.data
  },
  async agregarTarifaHotel(hotelId: number, data: { tipo_habitacion: string; precio_costo: number; precio_venta: number; proveedor_tarifa_id?: number | null }) {
    const response = await httpClient.post(`/opciones-hotel/${hotelId}/tarifas`, data)
    return response.data
  },
  async actualizarTarifaHotel(tarifaId: number, data: { tipo_habitacion: string; precio_costo: number; precio_venta: number; proveedor_tarifa_id?: number | null }) {
    const response = await httpClient.put(`/opcion-hotel-tarifas/${tarifaId}`, data)
    return response.data
  },
  async eliminarTarifaHotel(tarifaId: number) {
    const response = await httpClient.delete(`/opcion-hotel-tarifas/${tarifaId}`)
    return response.data
  },
  async listarOpcionales(opcionMayoristaId: number) {
    const response = await httpClient.get(`/opciones-mayorista/${opcionMayoristaId}/opcionales`)
    return response.data
  },
  async crearOpcional(opcionMayoristaId: number, data: {
    nombre: string
    precio_por_persona: number
    moneda: 'PEN' | 'USD'
    incluye?: string
    no_incluye?: string
  }) {
    const response = await httpClient.post(`/opciones-mayorista/${opcionMayoristaId}/opcionales`, data)
    return response.data
  },
  async actualizarOpcional(opcionalId: number, data: {
    nombre: string
    precio_por_persona: number
    moneda: 'PEN' | 'USD'
    incluye?: string
    no_incluye?: string
  }) {
    const response = await httpClient.put(`/opcion-mayorista-opcionales/${opcionalId}`, data)
    return response.data
  },
  async eliminarOpcional(opcionalId: number) {
    const response = await httpClient.delete(`/opcion-mayorista-opcionales/${opcionalId}`)
    return response.data
  },

  // ── Tours incluidos con itinerario real (Simulación Panamá, 04-sep-2026) ──
  // Distinto de `incluye` (texto plano): vincula un PaquetePlantilla ya
  // creado (con sus propios pasos de itinerario) a esta opción de mayorista.
  async listarTours(opcionMayoristaId: number) {
    const response = await httpClient.get(`/opciones-mayorista/${opcionMayoristaId}/tours`)
    return response.data
  },
  async vincularTour(opcionMayoristaId: number, data: { paquete_plantilla_id: number; orden: number }) {
    const response = await httpClient.post(`/opciones-mayorista/${opcionMayoristaId}/tours`, data)
    return response.data
  },
  async quitarTour(opcionMayoristaTourId: number) {
    const response = await httpClient.delete(`/opcion-mayorista-tours/${opcionMayoristaTourId}`)
    return response.data
  },
  // Solo el "Día" (orden) del vínculo — el contenido del tour se edita
  // aparte, vía paquetePlantillaService.actualizar() (ya existe).
  async actualizarOrdenTour(opcionMayoristaTourId: number, orden: number) {
    const response = await httpClient.put(`/opcion-mayorista-tours/${opcionMayoristaTourId}`, { orden })
    return response.data
  }
}
