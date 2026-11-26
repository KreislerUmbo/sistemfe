import { describe, expect, it } from 'vitest'
import { datosDeConsultaRuc, esRuc, nombreConCiudad } from './proveedor'

describe('proveedor', () => {
  it('toma razón social, dirección fiscal y la provincia como ciudad (CA2)', () => {
    const datos = datosDeConsultaRuc({
      razonSocial: 'HOTEL SAC', nombreComercial: '-', direccion: 'JR. LIMA 100', provincia: 'SAN MARTIN', distrito: 'TARAPOTO',
    })
    expect(datos).toEqual({ razon_social: 'HOTEL SAC', nombre_comercial: '', direccion_fiscal: 'JR. LIMA 100', ciudad: 'SAN MARTIN' })
    expect(datosDeConsultaRuc({ razonSocial: 'X', provincia: '', distrito: 'MORALES' })?.ciudad).toBe('MORALES')
  })

  it('un RUC inválido o sin resultado no produce datos (CA2)', () => {
    expect(esRuc('2013400000')).toBe(false)
    expect(esRuc('2013400000A')).toBe(false)
    expect(esRuc(' 20134000001 ')).toBe(true)
    expect(datosDeConsultaRuc({ success: false, message: 'no válido' })).toBeNull()
    expect(datosDeConsultaRuc({})).toBeNull()
    expect(datosDeConsultaRuc(null)).toBeNull()
  })

  it('muestra la ciudad al lado del nombre (CA3)', () => {
    expect(nombreConCiudad('Casa Andina', 'Tarapoto')).toBe('Casa Andina — Tarapoto')
    expect(nombreConCiudad('Casa Andina', null)).toBe('Casa Andina')
  })
})
