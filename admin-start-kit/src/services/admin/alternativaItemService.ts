// src/services/admin/alternativaItemService.ts — Sesión 11b (cotizador)
// Los 4 origen_tipo (proveedor/mayorista/pasaje_aereo/manual) comparten el
// mismo endpoint de creación — el payload varía, ver
// AlternativaItemController::store() en el backend.
import httpClient from '@/helpers/http-client'

export const alternativaItemService = {
  async agregarProveedor(alternativaId: number, data: Record<string, any>) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/items`, { ...data, origen_tipo: 'proveedor' })
    return response.data
  },
  async agregarMayorista(alternativaId: number, data: Record<string, any>) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/items`, { ...data, origen_tipo: 'mayorista' })
    return response.data
  },
  async agregarPasajeAereo(alternativaId: number, data: Record<string, any>) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/items`, { ...data, origen_tipo: 'pasaje_aereo' })
    return response.data
  },
  // Recalcula costo_total/precio_venta_total sin persistir — usado por
  // PasajeAereoForm.vue para el total en vivo mientras el vendedor edita
  // cargos/tarifas (nunca se reimplementa la suma en el frontend).
  async previewPasajeAereo(alternativaId: number, data: Record<string, any>) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/items/preview-pasaje-aereo`, data)
    return response.data
  },
  async agregarManual(alternativaId: number, data: Record<string, any>) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/items`, { ...data, origen_tipo: 'manual' })
    return response.data
  },
  // Edición en vivo — mandar SOLO uno de los dos (descuento_pct o
  // precio_convertido), el backend devuelve ambos recalculados +
  // alerta_piso/precio_minimo_permitido.
  async actualizar(id: number, data: { descuento_pct?: number; precio_convertido?: number }) {
    const response = await httpClient.put(`/alternativa-items/${id}`, data)
    return response.data
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/alternativa-items/${id}`)
    return response.data
  }
}
