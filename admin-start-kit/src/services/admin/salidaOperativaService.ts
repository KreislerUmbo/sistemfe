// src/services/admin/salidaOperativaService.ts — feature/salida-operativa
import httpClient from '@/helpers/http-client'
import type { SalidaOperativa, SalidasOperativasResponse, SalidaServicio, SalidaVehiculo } from '@/types/agencia-viajes'

// Fila 13, fase 2c (D14): orden de servicio de un proveedor para una salida.
export type OrdenServicio = {
  proveedor_id: number
  proveedor: string
  telefono: string | null
  servicios: { nombre: string; cantidad: number; estado: string; notas: string | null }[]
  vehiculos: { descripcion: string; chofer: string | null; capacidad: number | null; reservas: { codigo: string; titular: string | null; pasajeros: number; infantes: number; con_discapacidad: number }[] }[]
  alimentacion_especial: Record<string, number>
  mensaje: string
  whatsapp_url: string | null
}

export const salidaOperativaService = {
  async listar(params: { fecha_desde?: string; fecha_hasta?: string; estado?: string; page?: number } = {}) {
    const response = await httpClient.get<SalidasOperativasResponse>('/salidas-operativas', { params })
    return response.data
  },
  async ver(id: number) {
    const response = await httpClient.get(`/salidas-operativas/${id}`)
    return response.data as SalidaOperativa & { code: number }
  },
  async actualizar(id: number, data: Partial<SalidaOperativa>) {
    const response = await httpClient.put(`/salidas-operativas/${id}`, data)
    return response.data
  },
  async cancelar(id: number) {
    const response = await httpClient.put(`/salidas-operativas/${id}/cancelar`)
    return response.data
  },
  // Fila 13, fase 2a (D9): avanza o retrocede un paso (programada ⇄ confirmada ⇄ realizada).
  async cambiarEstado(id: number, estado: 'programada' | 'confirmada' | 'realizada') {
    const response = await httpClient.put(`/salidas-operativas/${id}/estado`, { estado })
    return response.data
  },
  // Fila 13, fase 2c (D14): órdenes de servicio, una por proveedor.
  async ordenes(salidaId: number) {
    const response = await httpClient.get(`/salidas-operativas/${salidaId}/ordenes`)
    return response.data as { code: number; ordenes: OrdenServicio[] }
  },
  // Abre el PDF en una pestaña nueva (la ruta exige el token, por eso blob).
  async abrirOrdenPdf(salidaId: number, proveedorId: number) {
    const { data } = await httpClient.get(`/salidas-operativas/${salidaId}/ordenes/${proveedorId}/pdf`, { responseType: 'blob' })
    window.open(URL.createObjectURL(new Blob([data], { type: 'application/pdf' })), '_blank')
  },
  // Fila 13, fase 2c (D16): reprogramar la salida completa.
  async reprogramar(salidaId: number, data: { fecha_nueva: string; motivo: string; cancelar_reserva_ids?: number[]; motivo_cancelacion?: string }) {
    const response = await httpClient.post(`/salidas-operativas/${salidaId}/reprogramar`, data)
    return response.data as { code: number; message: string; salida_nueva_id: number | null; avisos: string[]; items_no_tocados: unknown[] }
  },
  // Fila 13, fase 2b (D10-D13): servicios a reservar y vehículos.
  async agregarServicio(salidaId: number, data: { nombre: string; proveedor_id?: number | null; cantidad_manual?: number | null }) {
    const response = await httpClient.post(`/salidas-operativas/${salidaId}/servicios`, data)
    return response.data
  },
  async actualizarServiciosDesdeTour(salidaId: number) {
    const response = await httpClient.post(`/salidas-operativas/${salidaId}/servicios/actualizar`)
    return response.data
  },
  async actualizarServicio(servicioId: number, data: Partial<Pick<SalidaServicio, 'nombre' | 'proveedor_id' | 'cantidad_manual' | 'estado' | 'notas'>>) {
    const response = await httpClient.put(`/salida-servicios/${servicioId}`, data)
    return response.data
  },
  async quitarServicio(servicioId: number) {
    const response = await httpClient.delete(`/salida-servicios/${servicioId}`)
    return response.data
  },
  async agregarVehiculo(salidaId: number, data: Partial<SalidaVehiculo>) {
    const response = await httpClient.post(`/salidas-operativas/${salidaId}/vehiculos`, data)
    return response.data
  },
  async actualizarVehiculo(vehiculoId: number, data: Partial<SalidaVehiculo>) {
    const response = await httpClient.put(`/salida-vehiculos/${vehiculoId}`, data)
    return response.data
  },
  async quitarVehiculo(vehiculoId: number) {
    const response = await httpClient.delete(`/salida-vehiculos/${vehiculoId}`)
    return response.data
  },
  async asignarReservasVehiculo(vehiculoId: number, reservaIds: number[]) {
    const response = await httpClient.put(`/salida-vehiculos/${vehiculoId}/reservas`, { reserva_ids: reservaIds })
    return response.data
  },
  async adjuntarItem(salidaId: number, reservaItemId: number) {
    const response = await httpClient.post(`/salidas-operativas/${salidaId}/adjuntar-item`, { reserva_item_id: reservaItemId })
    return response.data
  },
  async desengancharItem(salidaId: number, reservaItemId: number) {
    const response = await httpClient.delete(`/salidas-operativas/${salidaId}/items/${reservaItemId}`)
    return response.data
  },
}
