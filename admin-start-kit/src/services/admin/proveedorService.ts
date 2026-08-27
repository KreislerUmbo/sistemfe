// src/services/admin/proveedorService.ts — Sesión 11a (agencia de viajes)
import httpClient from '@/helpers/http-client'
import type { ProveedorTipo, Proveedor, ProveedorServicio, ProveedorTarifa } from '@/types/agencia-viajes'

export const proveedorTipoService = {
  async listar() {
    const response = await httpClient.get('/proveedor-tipos')
    return response.data as { proveedor_tipos: ProveedorTipo[] }
  },
  async toggle(id: number) {
    const response = await httpClient.post(`/proveedor-tipos/${id}/toggle`)
    return response.data
  }
}

export const proveedorService = {
  async listar(params: { page?: number; search?: string; tipo_id?: number; estado?: boolean; destino_atractivo_id?: number } = {}) {
    const response = await httpClient.get('/proveedores', { params })
    return response.data
  },
  async obtener(id: number) {
    const response = await httpClient.get(`/proveedores/${id}`)
    return response.data as { proveedor: Proveedor }
  },
  async crear(data: FormData | Record<string, any>) {
    const response = await httpClient.post('/proveedores', data, {
      headers: data instanceof FormData ? { 'Content-Type': 'multipart/form-data' } : {}
    })
    return response.data
  },
  async actualizar(id: number, data: FormData | Record<string, any>) {
    // POST + _method implícito por multipart (mismo patrón que categories/{id})
    const response = await httpClient.post(`/proveedores/${id}`, data, {
      headers: data instanceof FormData ? { 'Content-Type': 'multipart/form-data' } : {}
    })
    return response.data
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/proveedores/${id}`)
    return response.data
  },

  // ── Servicios asociados (proveedor_servicios) ──────────────────────
  async listarServicios(proveedorId: number) {
    const response = await httpClient.get(`/proveedores/${proveedorId}/servicios`)
    return response.data as { proveedor_servicios: ProveedorServicio[] }
  },
  async asociarServicio(proveedorId: number, destino_servicio_id: number) {
    const response = await httpClient.post(`/proveedores/${proveedorId}/servicios`, { destino_servicio_id })
    return response.data
  },
  async desasociarServicio(proveedorId: number, servicioId: number) {
    const response = await httpClient.delete(`/proveedores/${proveedorId}/servicios/${servicioId}`)
    return response.data
  },

  // Mantenida pese a la consolidación de hoteles — usada por el flujo de
  // opcion_mayorista ("usar tarifa registrada" al armar el hotel manual de
  // una opción de mayorista, editar.vue::onCambiarProveedorHotel()), fuera
  // de alcance de esta sesión.
  async tarifasHotel(proveedorId: number) {
    const response = await httpClient.get(`/proveedores/${proveedorId}/tarifas-hotel`)
    return response.data as { proveedor_tarifas: ProveedorTarifa[] }
  },

  // Biblioteca del cotizador (Sesión 11b) — tarifas vigentes de TODOS los
  // proveedores, con búsqueda de texto (ver limitación documentada en el
  // backend: no filtra por destino, cotizaciones.destino no es FK).
  // Sesión 11l v2: además del texto libre, admite filtrar por
  // zona/servicio/proveedor (combinables entre sí). tipo_habitacion —
  // consolidación de hoteles — filtra directo por tipo de habitación entre
  // todas las tarifas de hotel.
  // Paginado real desde el backend (antes: limit(100) fijo, sin total ni
  // forma de pedir más — resultados 101+ se perdían en silencio).
  async biblioteca(params: { search?: string; destino_atractivo_id?: number; servicio_id?: number; proveedor_id?: number; tipo_habitacion?: string; page?: number; per_page?: number } = {}) {
    const response = await httpClient.get('/proveedor-tarifas', { params })
    return response.data as { proveedor_tarifas: ProveedorTarifa[]; total: number; per_page: number; current_page: number; last_page: number }
  },

  // ── Tarifas (proveedor_tarifas) ─────────────────────────────────────
  async listarTarifas(proveedorServicioId: number) {
    const response = await httpClient.get(`/proveedor-servicios/${proveedorServicioId}/tarifas`)
    return response.data as { proveedor_tarifas: ProveedorTarifa[] }
  },
  async crearTarifa(proveedorServicioId: number, data: Partial<ProveedorTarifa>) {
    const response = await httpClient.post(`/proveedor-servicios/${proveedorServicioId}/tarifas`, data)
    return response.data
  },
  async actualizarTarifa(tarifaId: number, data: Partial<ProveedorTarifa>) {
    const response = await httpClient.put(`/proveedor-tarifas/${tarifaId}`, data)
    return response.data
  },
  async eliminarTarifa(tarifaId: number) {
    const response = await httpClient.delete(`/proveedor-tarifas/${tarifaId}`)
    return response.data
  }
}
