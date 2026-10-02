import { describe, expect, it } from 'vitest'
import { etiquetaPeriodo, fechasAgenda, fechasPeriodo, lunesDe, mensajeRecordatorio, nombreArchivoReporte, sumarDias, tituloDiaAgenda } from './reportes'

describe('fechas de los accesos rápidos', () => {
  it('sumarDias cruza fin de mes y de año', () => {
    expect(sumarDias('2026-10-31', 1)).toBe('2026-11-01')
    expect(sumarDias('2026-12-31', 2)).toBe('2027-01-02')
    expect(sumarDias('2026-03-01', -1)).toBe('2026-02-28')
  })

  it('lunesDe: el domingo pertenece a la semana que empezó el lunes anterior', () => {
    expect(lunesDe('2026-10-02')).toBe('2026-09-28') // viernes
    expect(lunesDe('2026-10-04')).toBe('2026-09-28') // domingo
    expect(lunesDe('2026-09-28')).toBe('2026-09-28') // lunes
  })

  it('agenda: mañana, pasado mañana y los próximos 7 días desde mañana', () => {
    expect(fechasAgenda('manana', '2026-10-02')).toEqual({ desde: '2026-10-03', hasta: '2026-10-03' })
    expect(fechasAgenda('pasado', '2026-10-02')).toEqual({ desde: '2026-10-04', hasta: '2026-10-04' })
    expect(fechasAgenda('semana', '2026-10-02')).toEqual({ desde: '2026-10-03', hasta: '2026-10-09' })
    expect(fechasAgenda('rango', '2026-10-02')).toBeNull()
  })

  it('reportes por período: semana desde el lunes y mes desde el 1, hasta hoy', () => {
    expect(fechasPeriodo('semana', '2026-10-02')).toEqual({ desde: '2026-09-28', hasta: '2026-10-02' })
    expect(fechasPeriodo('mes', '2026-10-02')).toEqual({ desde: '2026-10-01', hasta: '2026-10-02' })
    expect(fechasPeriodo('hoy', '2026-10-02')).toEqual({ desde: '2026-10-02', hasta: '2026-10-02' })
  })
})

describe('textos', () => {
  it('título del día de la agenda', () => {
    expect(tituloDiaAgenda(null, '2026-10-02')).toBe('Ya atrasados')
    expect(tituloDiaAgenda('2026-10-03', '2026-10-02')).toBe('Mañana · 03/10/2026')
    expect(tituloDiaAgenda('2026-10-04', '2026-10-02')).toBe('Pasado mañana · 04/10/2026')
    expect(tituloDiaAgenda('2026-10-08', '2026-10-02')).toBe('08/10/2026')
  })

  it('etiqueta del período de Ingresos', () => {
    expect(etiquetaPeriodo('2026-09', 'mes')).toBe('09/2026')
    expect(etiquetaPeriodo('2026-09-28', 'semana')).toBe('Semana del 28/09/2026')
    expect(etiquetaPeriodo('2026-09-30', 'dia')).toBe('30/09/2026')
  })

  it('nombre del Excel con las fechas del filtro o la fecha de corte', () => {
    expect(nombreArchivoReporte('ingresos', { desde: '2026-10-01', hasta: '2026-10-02' }, '2026-10-02')).toBe('ingresos_y_desembolsos_2026-10-01_al_2026-10-02.xlsx')
    expect(nombreArchivoReporte('agenda', { desde: '2026-10-03', hasta: '2026-10-03' }, '2026-10-02')).toBe('agenda_de_cobranza_2026-10-03.xlsx')
    expect(nombreArchivoReporte('cartera', {}, '2026-10-02')).toBe('cartera_de_creditos_2026-10-02.xlsx')
  })
})

describe('recordatorio de WhatsApp', () => {
  const fila = {
    numero_credito: 'CR-00000003', numero_cuota: 2, cuotas_total: 4, pendiente: '75.00', mora: '0.00',
    fecha_vencimiento: '2026-10-03', cliente: { nombre: 'Juan Pérez Ramírez' },
  }

  it('cuota por vencer: saluda por el primer nombre y dice la fecha', () => {
    expect(mensajeRecordatorio(fila, '2026-10-02', 'Préstamos Sur'))
      .toBe('Hola Juan, le recordamos que la cuota 2 de 4 de su crédito CR-00000003, por S/ 75.00, vence el 03/10/2026. Gracias. Préstamos Sur.')
  })

  it('cuota que vence hoy todavía no "venció"', () => {
    expect(mensajeRecordatorio(fila, '2026-10-03')).toContain('vence el 03/10/2026')
  })

  it('sin espacios no separables (se ven raros en algunos teléfonos)', () => {
    expect(mensajeRecordatorio(fila, '2026-10-02')).not.toContain('\u00a0')
  })

  it('cuota atrasada: "venció" y la mora aparte, sin sumarla', () => {
    const texto = mensajeRecordatorio({ ...fila, mora: '12.50' }, '2026-10-05')
    expect(texto).toContain('venció el 03/10/2026')
    expect(texto).toContain('mora de S/ 12.50')
    expect(texto).toContain('por S/ 75.00')
  })
})
