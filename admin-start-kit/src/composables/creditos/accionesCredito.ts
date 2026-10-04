// Módulo Créditos (04-frontend "Detalle"): qué acciones se muestran según el estado del
// crédito y los permisos del usuario. Función pura (testeable); el backend vuelve a
// validar todo, esto solo evita ofrecer botones que van a fallar.
import type { CreditoEstado } from '@/types/creditos'

export type AccionCredito =
  | 'editar' | 'activar' | 'cobrar' | 'liquidar' | 'reprogramar' | 'condonar'
  | 'corregir' | 'anular' | 'castigar' | 'revertir_castigo' | 'renovar'

const REGLAS: Record<AccionCredito, { permiso: string; estados: CreditoEstado[] }> = {
  editar: { permiso: 'creditos.crear', estados: ['borrador'] },
  activar: { permiso: 'creditos.crear', estados: ['borrador'] },
  cobrar: { permiso: 'creditos.cobrar', estados: ['activo', 'castigado'] },
  liquidar: { permiso: 'creditos.cobrar', estados: ['activo', 'castigado'] },
  reprogramar: { permiso: 'creditos.reprogramar', estados: ['activo'] },
  condonar: { permiso: 'creditos.condonar_mora', estados: ['activo', 'castigado'] },
  // Corregir y anular requieren además "sin pagos válidos" (1.9): lo decide tienePagos.
  corregir: { permiso: 'creditos.corregir', estados: ['activo'] },
  anular: { permiso: 'creditos.corregir', estados: ['borrador', 'activo'] },
  castigar: { permiso: 'creditos.castigar', estados: ['activo'] },
  revertir_castigo: { permiso: 'creditos.castigar', estados: ['castigado'] },
  renovar: { permiso: 'creditos.crear', estados: ['activo', 'castigado'] },
}

const SOLO_SIN_PAGOS: AccionCredito[] = ['corregir', 'anular']

export function accionesDisponibles(
  estado: CreditoEstado,
  tienePermiso: (permiso: string) => boolean,
  tienePagos: boolean,
  cobraMora = true,
  /** Reprogramaciones, condonaciones o cargos vigentes: corregir los dejaría sin efecto (se anula y se registra de nuevo). */
  tieneAjustes = false,
): AccionCredito[] {
  return (Object.keys(REGLAS) as AccionCredito[]).filter((accion) => {
    const regla = REGLAS[accion]
    if (accion === 'condonar' && !cobraMora) return false
    if (!regla.estados.includes(estado) || !tienePermiso(regla.permiso)) return false
    if (SOLO_SIN_PAGOS.includes(accion) && estado === 'activo' && tienePagos) return false
    if (accion === 'corregir' && tieneAjustes) return false
    return true
  })
}

/** "Anular pago": propio (el backend valida que su caja siga abierta) o con creditos.anular_pago (1.8). */
export function puedeAnularPago(registradoPor: number, usuarioId: number, tienePermiso: (permiso: string) => boolean): boolean {
  return tienePermiso('creditos.anular_pago') || (tienePermiso('creditos.cobrar') && registradoPor === usuarioId)
}
