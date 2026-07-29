// src/services/admin/configuracionAgenciaService.ts — Sesión 11a (agencia de viajes)
// Singleton por tenant — sin listado, un solo GET/PUT.
import httpClient from '@/helpers/http-client'
import type { ConfiguracionAgencia } from '@/types/agencia-viajes'

export const configuracionAgenciaService = {
  async obtener() {
    const response = await httpClient.get('/configuracion-agencia')
    return response.data as { configuracion_agencia: ConfiguracionAgencia }
  },
  async actualizar(data: ConfiguracionAgencia) {
    const response = await httpClient.put('/configuracion-agencia', data)
    return response.data
  }
}
