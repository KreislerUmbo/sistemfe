// Módulo Créditos — filtros del listado (04-frontend pantalla 4). Viven en la URL
// (?buscar=&vista=&orden=&dir=&pagina=) para que volver desde el detalle los conserve.
import type { CreditoEstado } from '@/types/creditos'

export type VistaListado = 'todos' | 'activos' | 'atrasados' | 'borradores' | 'castigados' | 'finalizados' | 'anulados'
export type OrdenListado = 'reciente' | 'numero' | 'cliente' | 'desembolso' | 'monto'
export type Direccion = 'asc' | 'desc'

export interface FiltrosListado {
  buscar: string
  vista: VistaListado
  orden: OrdenListado
  direccion: Direccion
}

export const FILTROS_INICIALES: FiltrosListado = { buscar: '', vista: 'todos', orden: 'reciente', direccion: 'desc' }

/** Chips de estado; "Con atraso" son los activos o castigados con cuotas vencidas. */
export const VISTAS: { id: VistaListado; texto: string; estado?: CreditoEstado; conAtraso?: boolean }[] = [
  { id: 'todos', texto: 'Todos' },
  { id: 'activos', texto: 'Activos', estado: 'activo' },
  { id: 'atrasados', texto: 'Con atraso', conAtraso: true },
  { id: 'borradores', texto: 'Borradores', estado: 'borrador' },
  { id: 'castigados', texto: 'Castigados', estado: 'castigado' },
  { id: 'finalizados', texto: 'Finalizados', estado: 'finalizado' },
  { id: 'anulados', texto: 'Anulados', estado: 'anulado' },
]

const ORDENES: OrdenListado[] = ['reciente', 'numero', 'cliente', 'desembolso', 'monto']
/** Primer clic en una columna: texto de A a Z; fechas y montos del mayor al menor. */
const DIRECCION_INICIAL: Record<OrdenListado, Direccion> = { reciente: 'desc', numero: 'asc', cliente: 'asc', desembolso: 'desc', monto: 'desc' }

export function paramsListado(f: FiltrosListado): Record<string, string | number> {
  const vista = VISTAS.find((v) => v.id === f.vista)
  const params: Record<string, string | number> = { orden: f.orden, direccion: f.direccion }
  if (f.buscar.trim()) params.buscar = f.buscar.trim()
  if (vista?.estado) params.estado = vista.estado
  if (vista?.conAtraso) params.con_atraso = 1
  return params
}

type Query = Record<string, unknown>
const texto = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : typeof v === 'string' ? v : '')

export function desdeQuery(query: Query): { filtros: FiltrosListado; pagina: number } {
  const vista = texto(query.vista) as VistaListado
  const orden = texto(query.orden) as OrdenListado
  const direccion = texto(query.dir) as Direccion
  const pagina = Number.parseInt(texto(query.pagina), 10)
  return {
    filtros: {
      buscar: texto(query.buscar).slice(0, 100),
      vista: VISTAS.some((v) => v.id === vista) ? vista : FILTROS_INICIALES.vista,
      orden: ORDENES.includes(orden) ? orden : FILTROS_INICIALES.orden,
      direccion: direccion === 'asc' || direccion === 'desc' ? direccion : DIRECCION_INICIAL[ORDENES.includes(orden) ? orden : 'reciente'],
    },
    pagina: Number.isFinite(pagina) && pagina > 1 ? pagina : 1,
  }
}

/** Solo lo que difiere de lo inicial, para URLs cortas. */
export function aQuery(f: FiltrosListado, pagina: number): Record<string, string> {
  const q: Record<string, string> = {}
  if (f.buscar.trim()) q.buscar = f.buscar.trim()
  if (f.vista !== FILTROS_INICIALES.vista) q.vista = f.vista
  if (f.orden !== FILTROS_INICIALES.orden) q.orden = f.orden
  if (f.direccion !== DIRECCION_INICIAL[f.orden]) q.dir = f.direccion
  if (pagina > 1) q.pagina = String(pagina)
  return q
}

/** Clic en la cabecera: misma columna invierte; otra columna arranca en su dirección natural. */
export function alternarOrden(f: FiltrosListado, columna: OrdenListado): FiltrosListado {
  if (f.orden === columna) return { ...f, direccion: f.direccion === 'asc' ? 'desc' : 'asc' }
  return { ...f, orden: columna, direccion: DIRECCION_INICIAL[columna] }
}
