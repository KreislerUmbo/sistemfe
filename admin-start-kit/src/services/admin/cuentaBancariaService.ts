import httpClient from '@/helpers/http-client'
import type { CuentaBancaria } from '@/types/agencia-viajes'

export const cuentaBancariaService = {
  async listar() {
    const response = await httpClient.get('/cuentas-bancarias')
    return response.data as { cuentas_bancarias: CuentaBancaria[] }
  },
  async crear(data: Partial<CuentaBancaria>) {
    const response = await httpClient.post('/cuentas-bancarias', data)
    return response.data
  },
  async actualizar(id: number, data: Partial<CuentaBancaria>) {
    const response = await httpClient.put(`/cuentas-bancarias/${id}`, data)
    return response.data
  },
  async eliminar(id: number) {
    const response = await httpClient.delete(`/cuentas-bancarias/${id}`)
    return response.data
  },
}
