// src/services/admin/configuracionCodigosService.ts — Módulo 12 (códigos y
// numeración). Mismo shape que configuracionAgenciaService.ts, pero N filas
// (una por tipo) en vez de un singleton.
import httpClient from '@/helpers/http-client'
import type { ConfiguracionCodigo } from '@/types/agencia-viajes'

export const configuracionCodigosService = {
  async obtener() {
    const response = await httpClient.get('/configuracion-codigos')
    return response.data as { configuracion_codigos: ConfiguracionCodigo[] }
  },
  async actualizar(tipo: string, data: Partial<ConfiguracionCodigo>) {
    const response = await httpClient.put(`/configuracion-codigos/${tipo}`, data)
    return response.data
  },
  async previsualizar(tipo: string, overrides?: {
    prefijo?: string
    separador?: string
    incluye_periodo?: boolean
    longitud_correlativo?: number
  }) {
    const response = await httpClient.get(`/configuracion-codigos/${tipo}/previsualizar`, { params: overrides })
    return response.data as { proximo_codigo: string }
  }
}
