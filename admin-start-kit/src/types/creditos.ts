// Módulo Créditos (docs/planning/creditos/04-frontend.md) — tipos de la API de
// routes/creditos.php. Los montos llegan y se envían como string decimal ("1234.50"):
// el frontend nunca hace aritmética de dinero, solo los muestra (00 §4).

export type Soles = string

export type CreditoEstado = 'borrador' | 'activo' | 'castigado' | 'finalizado' | 'anulado'
export type CuotaEstado = 'pendiente' | 'pagada' | 'anulada'
export type UnidadTasa = 'total' | 'mensual'
export type FrecuenciaUnidad = 'dia' | 'semana' | 'quincena' | 'mes' | 'anio'
export type ReglaNoLaborable = 'siguiente' | 'anterior' | 'mantener'
export type TopeMoraTipo = 'porcentaje_cuota' | 'porcentaje_capital' | 'dias_maximos' | 'sin_tope'
export type DestinoExcedente = 'devolver' | 'adelanto' | 'saldo_a_favor'
export type ReglaLimite = 'max_creditos' | 'deuda_maxima' | 'moroso' | 'bloqueado' | 'propio_garante' | 'garante_moroso' | 'garante_saturado'

/** Cuerpo de POST creditos/preview, POST creditos y PUT creditos/{id}. */
export interface CondicionesCredito {
  cliente_id: number | null
  monto_capital: string
  tasa_interes: string
  unidad_tasa: UnidadTasa
  frecuencia_unidad: FrecuenciaUnidad
  frecuencia_intervalo: number
  dias_quincena?: (number | 'ultimo')[] | null
  numero_cuotas: number
  fecha_desembolso: string
  fecha_primer_vencimiento?: string | null
  payment_method_id?: number | null
  dias_no_laborables?: number[]
  saltar_feriados?: boolean
  regla_no_laborable?: ReglaNoLaborable
  mora_cuenta_no_laborables?: boolean
  tasa_interes_minimo?: string
  dias_gracia?: number
  tope_mora_tipo?: TopeMoraTipo
  tope_mora_valor?: number | null
}

export interface CuotaPrevia {
  numero_cuota: number
  fecha_vencimiento: string
  monto_capital: Soles
  monto_interes: Soles
  monto_total: Soles
  fecha_forzada_a_siguiente: boolean
}

export interface CronogramaPrevio {
  interes_total: Soles
  monto_total: Soles
  primer_vencimiento: string
  ultimo_vencimiento: string
  cuotas: CuotaPrevia[]
}

export interface Infraccion {
  regla: ReglaLimite
  autorizable: boolean
  detalle: Record<string, number | null>
}

export interface ResultadoLimites {
  bloquea: boolean
  bloqueos: Infraccion[]
  advertencias: Infraccion[]
}

export interface PreviewCredito {
  cronograma: CronogramaPrevio
  limites: ResultadoLimites
}

export interface ClienteCredito {
  id: number
  nombre: string
  documento: string
  telefono: string | null
}

export interface Cuota {
  id: number
  numero_cuota: number
  fecha_inicio_periodo: string
  fecha_vencimiento: string
  fecha_vencimiento_original: string
  monto_capital: Soles
  monto_interes: Soles
  monto_total: Soles
  cargo_monto: Soles
  capital_pagado: Soles
  interes_pagado: Soles
  cargo_pagado: Soles
  mora_pagada: Soles
  mora_condonada: Soles
  mora_congelada: Soles
  interes_condonado: Soles
  estado: CuotaEstado
  fecha_pago: string | null
  dias_atraso_al_pagar: number | null
  // Solo en el detalle (situación calculada a hoy)
  mora_pendiente_hoy?: Soles
  dias_atraso_hoy?: number
  mora_tope_alcanzado?: boolean
}

export interface Credito {
  id: number
  numero_credito: string | null
  estado: CreditoEstado
  cliente?: ClienteCredito
  cliente_id: number
  monto_capital: Soles
  tasa_interes: string
  unidad_tasa: UnidadTasa
  interes_total: Soles
  monto_total: Soles
  frecuencia_unidad: FrecuenciaUnidad
  frecuencia_intervalo: number
  dias_quincena: (number | 'ultimo')[] | null
  numero_cuotas: number
  fecha_desembolso: string
  fecha_primer_vencimiento: string | null
  dias_no_laborables: number[]
  saltar_feriados: boolean
  regla_no_laborable: ReglaNoLaborable
  mora_cuenta_no_laborables: boolean
  tasa_interes_minimo: string
  dias_gracia: number
  tope_mora_tipo: TopeMoraTipo
  tope_mora_valor: number | null
  payment_method_id: number | null
  origen_registro: 'normal' | 'migracion' | 'renovacion'
  credito_renovado_id: number | null
  version_cronograma_actual: number
  fecha_castigo: string | null
  motivo_cierre: string | null
  motivo_anulacion: string | null
  cuotas?: Cuota[]
  created_at: string
}

export interface CreditoCreado {
  credito: Credito
  limites?: ResultadoLimites
}

/** GET clientes/{id}/resumen-credito — tarjeta del cliente en el formulario (00 1.10). */
export interface ResumenClienteCredito {
  creditos_activos: number
  max_creditos_activos: number
  deuda_actual: Soles
  deuda_maxima: Soles | null
  deuda_disponible: Soles | null
  dias_atraso_maximo: number
  cuotas_pagadas: number
  cuotas_pagadas_a_tiempo: number
  bloqueado: boolean
}

/** GET creditos/configuracion — defaults del negocio. */
export interface ConfiguracionCredito {
  tasa_interes_minimo: string
  dias_gracia: number
  paso_redondeo: string
  dias_no_laborables: number[]
  saltar_feriados: boolean
  regla_no_laborable: ReglaNoLaborable
  mora_cuenta_no_laborables: boolean
  tope_mora_tipo: TopeMoraTipo
  tope_mora_valor: number | null
  max_numero_cuotas: number
  tasa_maxima: string | null
  [clave: string]: unknown
}

export interface MetodoPago {
  id: number
  code: string
  name: string
}
