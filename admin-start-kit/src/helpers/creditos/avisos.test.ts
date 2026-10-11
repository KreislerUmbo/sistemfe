import { describe, expect, it } from 'vitest'
import { cambiosAvisos, estadoAviso, formularioAvisos } from './avisos'
import type { ConfiguracionCredito } from '@/types/creditos'

const config = (extra: Partial<ConfiguracionCredito> = {}) => ({ ...extra } as ConfiguracionCredito)

describe('formularioAvisos', () => {
  it('sin datos: apagado, 06:45, lunes a sábado, atrasados y sin cobros encendidos', () => {
    expect(formularioAvisos(config())).toEqual({ activo: false, hora: '06:45', dias: [1, 2, 3, 4, 5, 6], incluirAtrasados: true, sinCobros: true })
  })

  it('recorta los segundos de la hora y ordena los días', () => {
    const f = formularioAvisos(config({ avisos_activo: true, avisos_hora: '07:30:00', avisos_dias: [6, 1, 3], avisos_sin_cobros: false }))
    expect(f).toMatchObject({ activo: true, hora: '07:30', dias: [1, 3, 6], sinCobros: false })
  })
})

describe('cambiosAvisos', () => {
  const original = formularioAvisos(config())

  it('sin cambios no manda nada (el orden de los días no cuenta)', () => {
    expect(cambiosAvisos(original, { ...original, dias: [6, 5, 4, 3, 2, 1] })).toEqual({})
  })

  it('manda solo lo que cambió, con los nombres de la API', () => {
    expect(cambiosAvisos(original, { ...original, activo: true, hora: '06:00', dias: [7, 1] })).toEqual({
      avisos_activo: true, avisos_hora: '06:00', avisos_dias: [1, 7],
    })
  })
})

describe('estadoAviso', () => {
  it('distingue "sin cobros" de otras omisiones', () => {
    expect(estadoAviso({ estado: 'omitido', detalle: 'sin cobros hoy' }).texto).toBe('Sin cobros ese día')
    expect(estadoAviso({ estado: 'omitido', detalle: 'sin correo verificado o con el correo apagado' }).texto).toBe('No enviado')
    expect(estadoAviso({ estado: 'fallido', detalle: 'SMTP' }).texto).toBe('No se pudo enviar')
  })
})
