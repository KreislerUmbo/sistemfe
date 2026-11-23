import { describe, expect, it } from 'vitest'
import { diferenciaDiasISO, formatDiaCorto, formatFechaHora, hoyPeru, parsearFechaEscrita, soloFechaISO, sumarDiasISO } from './fecha'

// Regla única de fechas (08-oct-2026): todo se muestra en hora de Perú, sin depender de la
// zona horaria de la PC. Los casos van de 19:00 a 24:00 de Perú, donde UTC ya es el día siguiente.
describe('formatFechaHora', () => {
  it('un instante UTC ("Z") se muestra en hora de Perú', () => {
    expect(formatFechaHora('2026-10-09T00:39:27.000000Z')).toBe('08/10/2026 19:39')
    expect(formatFechaHora('2026-10-09T04:59:00Z')).toBe('08/10/2026 23:59')
  })

  it('un instante con offset de Perú se muestra igual', () => {
    expect(formatFechaHora('2026-10-08T23:30:00-05:00')).toBe('08/10/2026 23:30')
  })

  it('un texto sin zona ya viene en hora de Perú y se muestra tal cual', () => {
    expect(formatFechaHora('2026-10-08 16:58:00')).toBe('08/10/2026 16:58')
    expect(formatFechaHora('2026-08-28 12:55 PM')).toBe('28/08/2026 12:55')
    expect(formatFechaHora('2026-08-28 12:10 AM')).toBe('28/08/2026 00:10')
    expect(formatFechaHora('2026-08-28 07:05 PM')).toBe('28/08/2026 19:05')
  })

  it('vacío o inválido', () => {
    expect(formatFechaHora(null)).toBe('sin fecha')
    expect(formatFechaHora('no es fecha')).toBe('sin fecha')
  })
})

describe('hoyPeru', () => {
  it('de noche en Perú sigue siendo el mismo día', () => {
    expect(hoyPeru(new Date('2026-10-09T00:00:00Z'))).toBe('2026-10-08')
    expect(hoyPeru(new Date('2026-10-09T04:59:59Z'))).toBe('2026-10-08')
    expect(hoyPeru(new Date('2026-10-09T05:00:00Z'))).toBe('2026-10-09')
    expect(hoyPeru(new Date('2026-11-01T04:30:00Z'))).toBe('2026-10-31')
  })
})

describe('sumarDiasISO', () => {
  it('suma días de calendario cruzando mes y año', () => {
    expect(sumarDiasISO('2026-10-31', 1)).toBe('2026-11-01')
    expect(sumarDiasISO('2026-12-31', 7)).toBe('2027-01-07')
    expect(sumarDiasISO('2026-09-01T00:00:00.000000Z', 1)).toBe('2026-09-02')
  })
})

describe('soloFechaISO', () => {
  it('toma el día escrito de una fecha con hora y zona, sin convertirla', () => {
    expect(soloFechaISO('2026-08-20T00:00:00.000000Z')).toBe('2026-08-20')
    expect(soloFechaISO('2026-08-20 00:00:00')).toBe('2026-08-20')
  })

  it('una fecha sola queda igual; vacío o nulo da texto vacío', () => {
    expect(soloFechaISO('2026-08-20')).toBe('2026-08-20')
    expect(soloFechaISO('')).toBe('')
    expect(soloFechaISO(null)).toBe('')
    expect(soloFechaISO(undefined)).toBe('')
  })
})

describe('formatDiaCorto', () => {
  it('muestra el día de la semana y dd/mm de una fecha de negocio', () => {
    expect(formatDiaCorto('2026-10-30')).toBe('vie 30/10')
    expect(formatDiaCorto('2026-11-05')).toBe('jue 05/11')
    expect(formatDiaCorto('2026-11-08T00:00:00.000000Z')).toBe('dom 08/11')
  })

  it('sin fecha válida queda vacío', () => {
    expect(formatDiaCorto(null)).toBe('')
    expect(formatDiaCorto('mañana')).toBe('')
  })
})

describe('diferenciaDiasISO', () => {
  it('caso normal', () => expect(diferenciaDiasISO('2026-12-10', '2026-12-13')).toBe(3));
  it('cambio de mes', () => expect(diferenciaDiasISO('2026-10-30', '2026-11-02')).toBe(3));
  it('cambio de año', () => expect(diferenciaDiasISO('2026-12-30', '2027-01-02')).toBe(3));
  it('fechas iguales', () => expect(diferenciaDiasISO('2026-12-10', '2026-12-10')).toBe(0));
  it('acepta timestamps ISO y hasta anterior da negativo', () => {
    expect(diferenciaDiasISO('2026-12-10T00:00:00.000000Z', '2026-12-13')).toBe(3);
    expect(diferenciaDiasISO('2026-12-13', '2026-12-10')).toBe(-3);
  });
});

// T-117: una fecha escrita que no existe se rechaza (antes «31/11/2026» pasaba a ser 01/12/2026).
describe('parsearFechaEscrita', () => {
  it('rechaza fechas que no existen', () => {
    expect(parsearFechaEscrita('31/11/2026')).toBeNull()
    expect(parsearFechaEscrita('30/02/2026')).toBeNull()
    expect(parsearFechaEscrita('29/02/2026')).toBeNull()
    expect(parsearFechaEscrita('31/04/2026')).toBeNull()
    expect(parsearFechaEscrita('29/02/2100')).toBeNull()
    expect(parsearFechaEscrita('00/05/2026')).toBeNull()
    expect(parsearFechaEscrita('15/13/2026')).toBeNull()
  })

  it('acepta fechas que existen, con o sin cero adelante', () => {
    expect(parsearFechaEscrita('29/02/2028')).toBe('2028-02-29')
    expect(parsearFechaEscrita('29/02/2000')).toBe('2000-02-29')
    expect(parsearFechaEscrita('30/11/2026')).toBe('2026-11-30')
    expect(parsearFechaEscrita('1/3/2026')).toBe('2026-03-01')
    expect(parsearFechaEscrita(' 31/12/2026 ')).toBe('2026-12-31')
  })

  it('acepta «-» y «.» como separador, como el calendario', () => {
    expect(parsearFechaEscrita('30-11-2026')).toBe('2026-11-30')
    expect(parsearFechaEscrita('30.11.2026')).toBe('2026-11-30')
  })

  it('lo que no es una fecha da null', () => {
    expect(parsearFechaEscrita('')).toBeNull()
    expect(parsearFechaEscrita(null)).toBeNull()
    expect(parsearFechaEscrita('2026-11-30')).toBeNull()
    expect(parsearFechaEscrita('30/11/26')).toBeNull()
    expect(parsearFechaEscrita('hoy')).toBeNull()
  })
})
