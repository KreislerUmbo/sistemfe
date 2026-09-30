import { describe, expect, it } from 'vitest'
import type { ConfiguracionCredito, Credito } from '@/types/creditos'
import { aCondiciones, formularioDesdeConfiguracion, formularioDesdeCredito, resumenAvanzado } from './formulario'

const config = {
  tasa_interes_minimo: '10.0000', dias_gracia: 0, paso_redondeo: '0.10', dias_no_laborables: [7],
  saltar_feriados: false, regla_no_laborable: 'siguiente', mora_cuenta_no_laborables: true,
  tope_mora_tipo: 'porcentaje_cuota', tope_mora_valor: 100, max_numero_cuotas: 365, tasa_maxima: null,
} as ConfiguracionCredito

const completo = () => ({
  ...formularioDesdeConfiguracion(config, '2026-10-01', 4),
  monto_capital: '1000', tasa_interes: '20', numero_cuotas: '30',
})

describe('formulario de crédito', () => {
  it('no arma condiciones hasta tener cliente, monto, tasa y cuotas válidos', () => {
    expect(aCondiciones(completo(), null)).toBeNull()
    expect(aCondiciones({ ...completo(), monto_capital: '1000.555' }, 1)).toBeNull()
    expect(aCondiciones({ ...completo(), numero_cuotas: '' }, 1)).toBeNull()
  })

  it('traduce la forma de pago a la frecuencia de la API', () => {
    expect(aCondiciones(completo(), 1)).toMatchObject({ frecuencia_unidad: 'dia', frecuencia_intervalo: 1, numero_cuotas: 30 })
    expect(aCondiciones({ ...completo(), forma_pago: 'quincenal' }, 1)).toMatchObject({ frecuencia_unidad: 'dia', frecuencia_intervalo: 15 })
    expect(aCondiciones({ ...completo(), forma_pago: 'quincenal_fijo' }, 1)).toMatchObject({ frecuencia_unidad: 'quincena', dias_quincena: [15, 'ultimo'] })
    expect(aCondiciones({ ...completo(), forma_pago: 'mensual' }, 1)).toMatchObject({ frecuencia_unidad: 'mes' })
  })

  it('primer pago vacío es automático; el monto admite coma decimal', () => {
    const c = aCondiciones({ ...completo(), monto_capital: '1500,50' }, 1)
    expect(c?.fecha_primer_vencimiento).toBeNull()
    expect(c?.monto_capital).toBe('1500.50')
  })

  it('sin tope de mora no envía valor', () => {
    expect(aCondiciones({ ...completo(), tope_mora_tipo: 'sin_tope' }, 1)?.tope_mora_valor).toBeNull()
  })

  it('un borrador vuelve al formulario con la misma forma de pago', () => {
    const credito = {
      monto_capital: '1000.00', tasa_interes: '20.0000', unidad_tasa: 'total', frecuencia_unidad: 'dia', frecuencia_intervalo: 15,
      numero_cuotas: 8, fecha_desembolso: '2026-10-01', fecha_primer_vencimiento: null, payment_method_id: 4,
      dias_no_laborables: [7], saltar_feriados: false, regla_no_laborable: 'siguiente', mora_cuenta_no_laborables: true,
      tasa_interes_minimo: '10.0000', dias_gracia: 0, tope_mora_tipo: 'porcentaje_cuota', tope_mora_valor: 100,
    } as unknown as Credito

    const f = formularioDesdeCredito(credito)
    expect(f.forma_pago).toBe('quincenal')
    expect(f.tasa_interes).toBe('20')
    expect(resumenAvanzado(f)).toBe('Gracia 0 días · Interés mínimo 10% · Mora diaria (tope 100% de la cuota)')
  })
})
