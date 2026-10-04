// Módulo Créditos — cache de catálogos de solo lectura (configuración del negocio y
// métodos de pago activos), con el mismo criterio que agenciaViajesCatalogos: TTL corto
// para no servir datos viejos si alguien edita la configuración en otra pestaña.
import { defineStore } from 'pinia'
import { creditoService } from '@/services/admin/creditoService'
import type { ConfiguracionCredito, MetodoPago } from '@/types/creditos'

const TTL_MS = 60_000

function crearCache<T>(fetcher: () => Promise<T>) {
  let valor: T | null = null
  let cargadoEn = 0
  let enVuelo: Promise<T> | null = null

  const obtener = async (): Promise<T> => {
    if (valor !== null && Date.now() - cargadoEn < TTL_MS) return valor
    if (enVuelo) return enVuelo
    enVuelo = fetcher()
      .then((res) => {
        valor = res
        cargadoEn = Date.now()
        return res
      })
      .finally(() => {
        enVuelo = null
      })
    return enVuelo
  }

  const invalidar = () => {
    valor = null
    cargadoEn = 0
  }

  return { obtener, invalidar }
}

export const useCreditosCatalogosStore = defineStore('creditos_catalogos', () => {
  const configuracion = crearCache<ConfiguracionCredito>(() => creditoService.configuracion())
  const metodosPago = crearCache<MetodoPago[]>(() => creditoService.metodosPago())

  return {
    obtenerConfiguracion: configuracion.obtener,
    invalidarConfiguracion: configuracion.invalidar,
    obtenerMetodosPago: metodosPago.obtener,
  }
})
