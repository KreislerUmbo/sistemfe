import publicHttpClient from '@/helpers/publicHttpClient'

export const ordenService = {
  async crearOrden(data: any) {
    const response = await publicHttpClient.post('/portal/orders', data)
    return response.data
  }
}