// src/services/admin/alternativaItemService.ts — Sesión 11b (cotizador)
// Los 4 origen_tipo (proveedor/mayorista/pasaje_aereo/manual) comparten el
// mismo endpoint de creación — el payload varía, ver
// AlternativaItemController::store() en el backend.
import httpClient from '@/helpers/http-client'
import type { DesdePlantillaResponse } from '@/types/agencia-viajes'

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
  // Fix guia-como-item-real — guía suelto con costo real (guia_tarifa_id),
  // ver AlternativaItemController::crearItemGuia().
  async agregarGuia(alternativaId: number, data: Record<string, any>) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/items`, { ...data, origen_tipo: 'guia' })
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
  },

  // Sesión 11b3 — "cargar desde plantilla" (tour_simple/paquete_combo
  // completo, explotado en vivo contra sus tarifas reales, ver
  // AlternativaItemController::desdePlantilla()).
  async cargarDesdePlantilla(alternativaId: number, data: { paquete_plantilla_id: number; dia_referencial: number }) {
    const response = await httpClient.post(`/alternativas/${alternativaId}/items/desde-plantilla`, data)
    return response.data as DesdePlantillaResponse
  },
  // Reasignar día de un ítem SUELTO (sin tour_origen_id) — ver moverBloque()
  // para ítems que pertenecen a un bloque de tour.
  async reasignarDia(itemId: number, dia_referencial: number) {
    const response = await httpClient.put(`/alternativa-items/${itemId}/dia`, { dia_referencial })
    return response.data
  },
  // Mueve TODOS los ítems de un mismo tour_origen_id juntos.
  async moverBloque(alternativaId: number, data: { tour_origen_id: number; dia_referencial: number }) {
    const response = await httpClient.put(`/alternativas/${alternativaId}/items/mover-bloque`, data)
    return response.data
  }
}
