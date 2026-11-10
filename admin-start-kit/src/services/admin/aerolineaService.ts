// src/services/admin/aerolineaService.ts — T-016 (catálogo común de aerolíneas, central)
import httpClient from '@/helpers/http-client'

export interface AerolineaCatalogo { id: number; nombre: string; codigo_iata: string | null }

export const aerolineaService = {
  async buscar(buscar: string) {
    const response = await httpClient.get('/aerolineas', { params: { buscar: buscar || undefined } })
    return (response.data as { data: AerolineaCatalogo[] }).data
  },
  async agregar(nombre: string) {
    const response = await httpClient.post('/aerolineas', { nombre })
    return response.data as { data: AerolineaCatalogo; creada: boolean }
  },
}
