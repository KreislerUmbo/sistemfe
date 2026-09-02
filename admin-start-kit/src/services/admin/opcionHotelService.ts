// src/services/admin/opcionHotelService.ts — Sesión M3/M4. Hotel ad-hoc
// LOCAL (standalone, sin opcion_mayorista_id), ver
// App\Http\Controllers\AgenciaViajes\OpcionHotelController.
import httpClient from '@/helpers/http-client'

export const opcionHotelService = {
  async crear(data: {
    nombre_hotel: string; moneda: 'PEN' | 'USD'; categoria_estrellas?: number | null; proveedor_id?: number | null;
    tarifas?: Array<{ tipo_habitacion: string; precio_costo: number; precio_venta: number; tip_afe_igv?: string | null; destino_tributario?: string | null }>;
  }) {
    const response = await httpClient.post('/opciones-hotel', data)
    return response.data
  },
  // Promueve TODA la matriz del hotel ad-hoc a un Proveedor real — sin
  // relink retroactivo, ver docblock de OpcionHotelController::promover().
  async promover(id: number, data: { destino_servicio_id: number; razon_social: string; tipo_documento?: string | null; numero_documento?: string | null }) {
    const response = await httpClient.post(`/opciones-hotel/${id}/promover`, data)
    return response.data
  }
}
