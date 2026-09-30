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

/** GET creditos/{id}: crédito + situación calculada a hoy por el backend. */
export interface ResumenDetalle {
  saldo_capital: Soles
  saldo_interes: Soles
  mora_pendiente: Soles
  exigible_hoy: Soles
  dias_atraso: number
  saldo_por_pagar: Soles
  total_pagado: Soles
  cuotas_pagadas: number
  cuotas_total: number
  cuotas_vencidas: number
  proxima: { numero_cuota: number; fecha_vencimiento: string; pendiente: Soles } | null
}

export interface DetalleCredito {
  credito: Credito
  resumen: ResumenDetalle
  cuotas: Cuota[]
}

export type OrigenPago = 'cobro' | 'liquidacion' | 'renovacion' | 'venta_prenda' | 'saldo_a_favor' | 'saldo_inicial'

export interface Pago {
  id: number
  credito_id: number
  numero_recibo: string
  monto_recibido: Soles
  monto_aplicado: Soles
  monto_excedente: Soles
  destino_excedente: DestinoExcedente | null
  fecha_pago: string
  origen: OrigenPago
  es_cierre: boolean
  pagado_por_cliente_id: number | null
  payment_method_id: number | null
  referencia: string | null
  observaciones: string | null
  estado: 'valido' | 'anulado'
  motivo_anulacion: string | null
  registrado_por: number
  aplicaciones?: { numero_cuota: number | null; concepto: 'interes' | 'capital' | 'cargo' | 'mora'; monto: Soles }[]
}

export interface EstadoCuenta {
  credito: Credito
  cuotas: Cuota[]
  pagos: Pago[]
  condonaciones: { numero_cuota: number | null; monto: Soles; motivo: string; estado: string; fecha: string | null }[]
  cargos: { numero_cuota: number | null; monto: Soles; estado: string }[]
  castigos: { fecha_castigo: string; fecha_reversion: string | null; tipo: string; motivo: string | null }[]
  reprogramaciones: { fecha: string | null; motivo: string; cargo: Soles; accion_mora: string; cuotas: { cuota_id: number; fecha_anterior: string; fecha_nueva: string }[] }[]
  total_pagado: Soles
}

export interface Liquidacion {
  fecha: string
  capital_pendiente: Soles
  interes_devengado: Soles
  interes_minimo: Soles
  interes_final: Soles
  interes_cobrado: Soles
  interes_a_cobrar: Soles
  interes_descontado: Soles
  cargos_pendientes: Soles
  mora_pendiente: Soles
  monto_liquidacion: Soles
  reparto: { numero_cuota: number; capital: Soles; interes: Soles; interes_descontado: Soles; cargo: Soles; mora: Soles }[]
}

export interface SolicitudReprogramacion {
  modo: 'desplazar' | 'editar'
  desde_cuota?: number
  dias?: number
  fechas?: Record<number, string>
  accion_mora?: 'mantener' | 'condonar'
  cargo?: string | null
}

export interface PreviewReprogramacion {
  cambios: { numero_cuota: number; fecha_anterior: string; fecha_nueva: string; mora_congelada: Soles; cae_en_no_laborable: boolean }[]
  mora_acumulada: Soles
  accion_mora: 'mantener' | 'condonar' | 'no_aplica'
  cargo_tipo: 'ninguno' | 'fijo' | 'interes_por_dias'
  cargo_sugerido: Soles
  cargo: Soles
}

export interface PreviewRenovacion {
  liquidacion: Liquidacion
  capital_nuevo: Soles
  entrega_neta: Soles
  cronograma: CronogramaPrevio
  limites: ResultadoLimites
}

export interface MetodoPago {
  id: number
  code: string
  name: string
}
