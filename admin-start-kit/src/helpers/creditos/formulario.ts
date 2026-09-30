// Módulo Créditos — formulario de condiciones (mockup 1): "Forma de pago" simple en la
// pantalla ↔ frecuencia_unidad/intervalo de la API. Sin cálculos de dinero: solo arma el
// cuerpo que el backend valida y calcula (00 §4).
import type { CondicionesCredito, ConfiguracionCredito, Credito, ReglaNoLaborable, TopeMoraTipo, UnidadTasa } from '@/types/creditos'

export type FormaPago = 'diario' | 'semanal' | 'quincenal' | 'quincenal_fijo' | 'mensual'

export const FORMAS_PAGO: { valor: FormaPago; etiqueta: string }[] = [
  { valor: 'diario', etiqueta: 'Diario' },
  { valor: 'semanal', etiqueta: 'Semanal' },
  { valor: 'quincenal', etiqueta: 'Quincenal (cada 15 días)' },
  { valor: 'quincenal_fijo', etiqueta: 'Quincenal (día 15 y fin de mes)' },
  { valor: 'mensual', etiqueta: 'Mensual' },
]

const FRECUENCIAS: Record<FormaPago, Pick<CondicionesCredito, 'frecuencia_unidad' | 'frecuencia_intervalo' | 'dias_quincena'>> = {
  diario: { frecuencia_unidad: 'dia', frecuencia_intervalo: 1, dias_quincena: null },
  semanal: { frecuencia_unidad: 'semana', frecuencia_intervalo: 1, dias_quincena: null },
  // 00 1.3: quincenal "corrido" = día + 15; "fijo" = quincena en días 15 y último.
  quincenal: { frecuencia_unidad: 'dia', frecuencia_intervalo: 15, dias_quincena: null },
  quincenal_fijo: { frecuencia_unidad: 'quincena', frecuencia_intervalo: 1, dias_quincena: [15, 'ultimo'] },
  mensual: { frecuencia_unidad: 'mes', frecuencia_intervalo: 1, dias_quincena: null },
}

export const DOMINGO = 7

export interface FormCredito {
  monto_capital: string
  tasa_interes: string
  unidad_tasa: UnidadTasa
  forma_pago: FormaPago
  numero_cuotas: string
  fecha_desembolso: string
  /** '' = automático (un intervalo después del desembolso). */
  fecha_primer_vencimiento: string
  payment_method_id: number | null
  dias_no_laborables: number[]
  saltar_feriados: boolean
  regla_no_laborable: ReglaNoLaborable
  mora_cuenta_no_laborables: boolean
  tasa_interes_minimo: string
  dias_gracia: string
  tope_mora_tipo: TopeMoraTipo
  tope_mora_valor: string
}

const MONTO = /^\d+(\.\d{1,2})?$/
const PORCENTAJE = /^\d{1,4}(\.\d{1,4})?$/
const ENTERO = /^\d+$/

export function formularioDesdeConfiguracion(config: ConfiguracionCredito, fechaDesembolso: string, paymentMethodId: number | null): FormCredito {
  return {
    monto_capital: '',
    tasa_interes: '',
    unidad_tasa: 'total',
    forma_pago: 'diario',
    numero_cuotas: '',
    fecha_desembolso: fechaDesembolso,
    fecha_primer_vencimiento: '',
    payment_method_id: paymentMethodId,
    dias_no_laborables: [...config.dias_no_laborables],
    saltar_feriados: config.saltar_feriados,
    regla_no_laborable: config.regla_no_laborable,
    mora_cuenta_no_laborables: config.mora_cuenta_no_laborables,
    tasa_interes_minimo: String(Number.parseFloat(config.tasa_interes_minimo)),
    dias_gracia: String(config.dias_gracia),
    tope_mora_tipo: config.tope_mora_tipo,
    tope_mora_valor: config.tope_mora_valor === null ? '' : String(config.tope_mora_valor),
  }
}

export function formaPagoDe(credito: Pick<Credito, 'frecuencia_unidad' | 'frecuencia_intervalo'>): FormaPago {
  const { frecuencia_unidad: unidad, frecuencia_intervalo: intervalo } = credito
  if (unidad === 'dia') return intervalo === 15 ? 'quincenal' : 'diario'
  if (unidad === 'semana') return 'semanal'
  if (unidad === 'quincena') return 'quincenal_fijo'
  return 'mensual'
}

export function formularioDesdeCredito(c: Credito): FormCredito {
  return {
    monto_capital: c.monto_capital,
    tasa_interes: String(Number.parseFloat(c.tasa_interes)),
    unidad_tasa: c.unidad_tasa,
    forma_pago: formaPagoDe(c),
    numero_cuotas: String(c.numero_cuotas),
    fecha_desembolso: c.fecha_desembolso,
    fecha_primer_vencimiento: c.fecha_primer_vencimiento ?? '',
    payment_method_id: c.payment_method_id,
    dias_no_laborables: [...c.dias_no_laborables],
    saltar_feriados: c.saltar_feriados,
    regla_no_laborable: c.regla_no_laborable,
    mora_cuenta_no_laborables: c.mora_cuenta_no_laborables,
    tasa_interes_minimo: String(Number.parseFloat(c.tasa_interes_minimo)),
    dias_gracia: String(c.dias_gracia),
    tope_mora_tipo: c.tope_mora_tipo,
    tope_mora_valor: c.tope_mora_valor === null ? '' : String(c.tope_mora_valor),
  }
}

/** Cuerpo para la API, o null si faltan datos mínimos (no se pide preview todavía). */
export function aCondiciones(f: FormCredito, clienteId: number | null): CondicionesCredito | null {
  const monto = f.monto_capital.trim().replace(',', '.')
  const tasa = f.tasa_interes.trim().replace(',', '.')
  if (clienteId === null || !MONTO.test(monto) || !PORCENTAJE.test(tasa) || !ENTERO.test(f.numero_cuotas.trim())) {
    return null
  }

  return {
    cliente_id: clienteId,
    monto_capital: monto,
    tasa_interes: tasa,
    unidad_tasa: f.unidad_tasa,
    ...FRECUENCIAS[f.forma_pago],
    numero_cuotas: Number.parseInt(f.numero_cuotas, 10),
    fecha_desembolso: f.fecha_desembolso,
    fecha_primer_vencimiento: f.fecha_primer_vencimiento || null,
    payment_method_id: f.payment_method_id,
    dias_no_laborables: [...f.dias_no_laborables].sort(),
    saltar_feriados: f.saltar_feriados,
    regla_no_laborable: f.regla_no_laborable,
    mora_cuenta_no_laborables: f.mora_cuenta_no_laborables,
    tasa_interes_minimo: f.tasa_interes_minimo.trim().replace(',', '.') || undefined,
    dias_gracia: ENTERO.test(f.dias_gracia.trim()) ? Number.parseInt(f.dias_gracia, 10) : undefined,
    tope_mora_tipo: f.tope_mora_tipo,
    tope_mora_valor: f.tope_mora_tipo === 'sin_tope' || !ENTERO.test(f.tope_mora_valor.trim()) ? null : Number.parseInt(f.tope_mora_valor, 10),
  }
}

/** Texto de "Opciones avanzadas" plegado (mockup 1). */
export function resumenAvanzado(f: FormCredito): string {
  const tope = {
    porcentaje_cuota: `tope ${f.tope_mora_valor}% de la cuota`,
    porcentaje_capital: `tope ${f.tope_mora_valor}% del capital`,
    dias_maximos: `tope ${f.tope_mora_valor} días`,
    sin_tope: 'sin tope',
  }[f.tope_mora_tipo]

  return `Gracia ${f.dias_gracia} días · Interés mínimo ${f.tasa_interes_minimo}% · Mora diaria (${tope})`
}
