// src/services/admin/tipoCambioAgenciaService.ts — calculadora de tipo de
// cambio del cotizador (07-sep-2026). Ver TipoCambioAgenciaController en el
// backend: actual() nunca crea nada (devuelve null si no hay nada
// registrado todavía), store() es una acción explícita separada, nunca
// implícita dentro de un cálculo.
import httpClient from '@/helpers/http-client'

export interface TipoCambioAgencia {
  id: number
  fecha: string
  origen: 'dia' | 'agencia'
  valor: string | number
  registrado_por: number
}

export const tipoCambioAgenciaService = {
  async obtenerActual() {
    const response = await httpClient.get('/tipo-cambio-agencia/actual')
    return response.data as { code: number; tipo_cambio_agencia: TipoCambioAgencia | null }
  },
  async guardar(data: { valor: number; origen: 'dia' | 'agencia' }) {
    const response = await httpClient.post('/tipo-cambio-agencia', data)
    return response.data as { code: number; message: string; tipo_cambio_agencia: TipoCambioAgencia }
  }
}
