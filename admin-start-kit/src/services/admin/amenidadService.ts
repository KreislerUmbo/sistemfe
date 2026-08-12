// src/services/admin/amenidadService.ts — Consolidación de hoteles
// Catálogo central de amenidades — solo lectura, sin CRUD desde acá por
// ahora (mismo criterio que proveedorTipoService.listar()).
import httpClient from '@/helpers/http-client'
import type { Amenidad } from '@/types/agencia-viajes'

export const amenidadService = {
  async listar() {
    const response = await httpClient.get('/amenidades')
    return response.data as { amenidades: Amenidad[] }
  }
}
