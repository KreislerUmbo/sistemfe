// Módulo Créditos (Fase 5) — aviso diario de cobranza: formulario de la configuración y textos
// del registro de avisos. Puro (sin Vue) para probarlo con Vitest.
import type { AvisoRegistro, ConfiguracionCredito } from '@/types/creditos'

/** Lunes a sábado: lo mismo que asume el backend cuando avisos_dias es null. */
export const DIAS_AVISO_POR_DEFECTO = [1, 2, 3, 4, 5, 6]

export interface FormAvisos {
  activo: boolean
  hora: string
  dias: number[]
  incluirAtrasados: boolean
  sinCobros: boolean
}

export function formularioAvisos(config: ConfiguracionCredito): FormAvisos {
  return {
    activo: config.avisos_activo === true,
    hora: (config.avisos_hora ?? '06:45').slice(0, 5),
    dias: [...(config.avisos_dias?.length ? config.avisos_dias : DIAS_AVISO_POR_DEFECTO)].map(Number).sort(),
    incluirAtrasados: config.avisos_incluir_atrasados !== false,
    sinCobros: config.avisos_sin_cobros !== false,
  }
}

/** Solo los campos avisos_* que cambiaron, con los nombres de la API. */
export function cambiosAvisos(original: FormAvisos, form: FormAvisos): Record<string, unknown> {
  const cambios: Record<string, unknown> = {}
  if (form.activo !== original.activo) cambios.avisos_activo = form.activo
  if (form.hora !== original.hora) cambios.avisos_hora = form.hora
  if ([...form.dias].sort().join(',') !== [...original.dias].sort().join(',')) cambios.avisos_dias = [...form.dias].sort()
  if (form.incluirAtrasados !== original.incluirAtrasados) cambios.avisos_incluir_atrasados = form.incluirAtrasados
  if (form.sinCobros !== original.sinCobros) cambios.avisos_sin_cobros = form.sinCobros
  return cambios
}

/** Estado de un aviso en palabras y con su color (badge de Bootstrap). */
export function estadoAviso(aviso: Pick<AvisoRegistro, 'estado' | 'detalle'>): { texto: string; clase: string } {
  switch (aviso.estado) {
    case 'enviado': return { texto: 'Enviado', clase: 'bg-success-subtle text-success' }
    case 'fallido': return { texto: 'No se pudo enviar', clase: 'bg-danger-subtle text-danger' }
    case 'pendiente': return { texto: 'Enviando', clase: 'bg-secondary-subtle text-secondary' }
    default: return { texto: aviso.detalle === 'sin cobros hoy' ? 'Sin cobros ese día' : 'No enviado', clase: 'bg-warning-subtle text-warning' }
  }
}
