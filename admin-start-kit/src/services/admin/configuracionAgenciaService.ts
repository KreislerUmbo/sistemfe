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
  },
  // Fila 13, fase 1b (§5b) — solo el Super-Admin; el backend responde 403 a
  // cualquier otro rol.
  async actualizarModoTours(modo_tours: 'clasico' | 'nuevo') {
    const response = await httpClient.put('/configuracion-agencia/modo-tours', { modo_tours })
    return response.data as { configuracion_agencia: ConfiguracionAgencia }
  }
}
