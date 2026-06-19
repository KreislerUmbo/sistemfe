// admin-start-kit/src/services/portal/ordenService.ts este archivo es para hacer peticiones a la api cuando se registra un nuevo cliente
import publicHttpClient from '@/helpers/publicHttpClient'

export const ordenService = {
  async crearOrden(data: any, customHeaders?: any) {
    const headers = customHeaders || {}
    const response = await publicHttpClient.post('/portal/orders', data, { headers })
    return response.data
  }
}