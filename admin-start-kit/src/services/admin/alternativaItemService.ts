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
  },
  // Sesión 11q — edición estructural completa de un ítem manual
  // (descripción/proveedor/costo/cantidad/pax), separado de actualizar()
  // (que solo maneja descuento_pct/precio_convertido).
  async actualizarManual(itemId: number, data: Record<string, any>) {
    const response = await httpClient.put(`/alternativa-items/${itemId}/manual`, data)
    return response.data
  },
  // Auditoría del módulo Reservas/Cotizador (2026-08-27) — edición
  // estructural completa de un pasaje aéreo suelto (antes solo existía el
  // alta, sin forma de corregir tarifas/pax_incluidos/aerolínea después).
  // Mismo criterio que actualizarManual().
  async actualizarPasajeAereo(itemId: number, data: Record<string, any>) {
    const response = await httpClient.put(`/alternativa-items/${itemId}/pasaje-aereo`, data)
    return response.data
  },
  // Crea Proveedor + ProveedorServicio + ProveedorTarifa a partir de un
  // ítem manual — la cotización actual NO cambia, ver
  // AlternativaItemController::promoverAProveedor().
  async promoverAProveedor(itemId: number, data: {
    razon_social: string; tipo_documento?: string; numero_documento?: string;
    destino_servicio_id: number; costo: number; precio_venta_adulto: number;
    modalidad: 'compartido' | 'privado';
  }) {
    const response = await httpClient.post(`/alternativa-items/${itemId}/promover-a-proveedor`, data)
    return response.data
  }
}
