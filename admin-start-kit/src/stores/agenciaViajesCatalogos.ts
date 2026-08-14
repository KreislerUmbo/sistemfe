// Cache de catálogos de solo-lectura del vertical Agencia de Viajes
// (proveedor_tipos, configuracion_agencia, servicios, proveedores activos)
// compartidos entre paquetes/detalle.vue y cotizador/editar.vue — antes cada
// mount de cualquiera de las dos pantallas volvía a pedir los 4 desde cero,
// aunque casi nunca cambian entre una navegación y la siguiente.
//
// TTL corto (60s) en vez de cache indefinido por sesión: proveedor_tipos y
// configuracion_agencia son casi estáticos (el primero se edita desde el
// panel superadmin, otra app; el segundo tiene su propia pantalla acá mismo
// pero se toca poco), pero servicios/proveedores SÍ se editan seguido desde
// sus propios CRUD dentro de esta misma SPA — un TTL corto evita servir
// datos desactualizados por mucho tiempo sin tener que enganchar invalidación
// manual en cada punto que los crea/edita/borra.
import { defineStore } from 'pinia'
import { proveedorTipoService, proveedorService } from '@/services/admin/proveedorService'
import { configuracionAgenciaService } from '@/services/admin/configuracionAgenciaService'
import { servicioService } from '@/services/admin/servicioService'

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

export const useAgenciaViajesCatalogosStore = defineStore('agencia_viajes_catalogos', () => {
  const proveedorTipos = crearCache(() =>
    proveedorTipoService.listar().then((r) => r.proveedor_tipos)
  )
  const configAgencia = crearCache(() =>
    configuracionAgenciaService.obtener().then((r) => r.configuracion_agencia)
  )
  const servicios = crearCache(() =>
    servicioService.listar({ per_page: 200 }).then((r) => r.servicios ?? [])
  )
  const proveedoresActivos = crearCache(() =>
    proveedorService.listar({ estado: true }).then((r) => r.proveedores ?? [])
  )

  return {
    obtenerProveedorTipos: proveedorTipos.obtener,
    obtenerConfigAgencia: configAgencia.obtener,
    obtenerServicios: servicios.obtener,
    obtenerProveedoresActivos: proveedoresActivos.obtener,
    // Invalidación manual — hoy solo se usa desde
    // configuracion/index.vue tras un PUT exitoso, para que el cambio se
    // vea de inmediato en vez de esperar el TTL. servicios/proveedores no
    // están enganchados a propósito: se editan mucho más seguido desde sus
    // propios CRUD y el TTL corto ya acota el riesgo sin tener que tocar
    // cada punto que los crea/edita/borra.
    invalidarConfigAgencia: configAgencia.invalidar,
  }
})
