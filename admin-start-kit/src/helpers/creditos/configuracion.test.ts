import { describe, expect, it } from 'vitest'
import { cambiosConfig, formularioConfig } from './configuracion'
import type { ConfiguracionCredito } from '@/types/creditos'

const CONFIG = {
  tasa_interes_minimo: '10.0000', dias_gracia: 0, paso_redondeo: '0.10', dias_no_laborables: [7], saltar_feriados: false,
  regla_no_laborable: 'siguiente', mora_cuenta_no_laborables: true, cobra_mora: true, asesor_cobra: true, requisitos_ficha: ['dni_anverso', 'ubicacion'], tope_mora_tipo: 'porcentaje_cuota', tope_mora_valor: 100,
  max_numero_cuotas: 365, tasa_maxima: null, max_creditos_activos: 3, deuda_maxima_cliente: null, dias_atraso_bloqueo: 7,
  dias_max_pago_retroactivo: 3, dias_para_castigo: 90, umbral_alerta_anulaciones: 5, cargo_reprogramacion_tipo: 'ninguno',
  cargo_reprogramacion_monto: '0.00', max_garantias_por_garante: 2, dias_aviso_garante: 15, dias_para_venta: 30,
  porcentaje_prestamo_max: null, validacion_tasacion: 'ninguna',
} as unknown as ConfiguracionCredito

describe('formularioConfig', () => {
  it('muestra decimales sin ceros de relleno y vacío para null', () => {
    const f = formularioConfig(CONFIG)
    expect(f.tasa_interes_minimo).toBe('10')
    expect(f.tasa_maxima).toBe('')
    expect(f.dias_gracia).toBe('0')
    expect(f.dias_no_laborables).toEqual([7])
  })
})

describe('cambiosConfig', () => {
  const original = formularioConfig(CONFIG)

  it('sin cambios no envía nada', () => {
    expect(cambiosConfig(original, { ...original })).toEqual({ cambios: {}, errores: {} })
  })

  it('envía solo lo que cambió, con su tipo', () => {
    const r = cambiosConfig(original, {
      ...original, dias_gracia: '2', tasa_maxima: '4,5', saltar_feriados: true, dias_no_laborables: [6, 7], deuda_maxima_cliente: '',
    })
    expect(r.errores).toEqual({})
    expect(r.cambios).toEqual({ dias_gracia: 2, tasa_maxima: '4.5', saltar_feriados: true, dias_no_laborables: [6, 7] })
  })

  it('un campo opcional vaciado se envía como null', () => {
    const conTope = formularioConfig({ ...CONFIG, tasa_maxima: '8.0000' })
    expect(cambiosConfig(conTope, { ...conTope, tasa_maxima: '' }).cambios).toEqual({ tasa_maxima: null })
  })

  it('valida localmente y no manda campos ocultos', () => {
    const r = cambiosConfig(original, { ...original, dias_gracia: 'x', max_numero_cuotas: '', tope_mora_tipo: 'sin_tope', tope_mora_valor: 'basura' })
    expect(r.errores).toEqual({ dias_gracia: 'Número entero.', max_numero_cuotas: 'Obligatorio.' })
    expect(r.cambios).toEqual({ tope_mora_tipo: 'sin_tope' })
  })

  it('ficha exigida: el orden en que se marcan no cuenta como cambio', () => {
    expect(cambiosConfig(original, { ...original, requisitos_ficha: ['ubicacion', 'dni_anverso'] }).cambios).toEqual({})
    expect(cambiosConfig(original, { ...original, requisitos_ficha: ['ubicacion', 'dni_reverso', 'dni_anverso'] }).cambios)
      .toEqual({ requisitos_ficha: ['dni_anverso', 'dni_reverso', 'ubicacion'] })
    expect(cambiosConfig(original, { ...original, requisitos_ficha: [] }).cambios).toEqual({ requisitos_ficha: [] })
  })

  it('no permite dejar la semana sin días de cobro', () => {
    expect(cambiosConfig(original, { ...original, dias_no_laborables: [1, 2, 3, 4, 5, 6, 7] }).errores).toHaveProperty('dias_no_laborables')
  })
})
