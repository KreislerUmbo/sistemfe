import { describe, expect, it } from 'vitest'
import { coincide, enlaceLlamada, enlaceMapa, enlaceWhatsapp, fechaLarga, textoEstado } from './cobranza'

describe('enlaces de contacto', () => {
  it('WhatsApp a celular peruano con el 51 delante', () => {
    expect(enlaceWhatsapp('987 654 321')).toBe('https://wa.me/51987654321')
    expect(enlaceWhatsapp('+51 987654321')).toBe('https://wa.me/51987654321')
  })

  it('sin celular no hay WhatsApp (fijo o vacío)', () => {
    expect(enlaceWhatsapp('042 523456')).toBeNull()
    expect(enlaceWhatsapp(null)).toBeNull()
  })

  it('llamada conserva el + y quita espacios', () => {
    expect(enlaceLlamada('+51 987 654 321')).toBe('tel:+51987654321')
    expect(enlaceLlamada('')).toBeNull()
  })

  it('mapa usa coordenadas si las hay; si no, la dirección', () => {
    expect(enlaceMapa({ latitud: '-6.4854000', longitud: '-76.3689000', direccion_cobro: 'Jr. Los Pinos' }))
      .toBe('https://www.google.com/maps/search/?api=1&query=-6.4854000%2C-76.3689000')
    expect(enlaceMapa({ latitud: null, longitud: null, direccion_cobro: 'Jr. Los Pinos 245' }))
      .toBe('https://www.google.com/maps/search/?api=1&query=Jr.%20Los%20Pinos%20245')
    expect(enlaceMapa({ latitud: null, longitud: null, direccion_cobro: ' ' })).toBeNull()
  })
})

describe('textos', () => {
  it('estado con días en singular y plural', () => {
    expect(textoEstado({ estado: 'vencido', dias_atraso: 2 }).texto).toBe('Vencido 2 días')
    expect(textoEstado({ estado: 'vencido', dias_atraso: 1 }).texto).toBe('Vencido 1 día')
    expect(textoEstado({ estado: 'hoy', dias_atraso: 0 })).toEqual({ texto: 'Vence hoy', clase: 'text-success' })
  })

  it('fecha larga del mockup', () => {
    expect(fechaLarga('2026-10-15')).toBe('Jueves 15 de octubre')
  })

  it('la búsqueda ignora tildes y mayúsculas', () => {
    const item = { numero_credito: 'CR-00000123', cliente: { nombre: 'Juan Pérez Ramírez', direccion_cobro: 'Jr. Los Pinos 245' } }
    expect(coincide(item, 'perez')).toBe(true)
    expect(coincide(item, 'pinos')).toBe(true)
    expect(coincide(item, '0123')).toBe(true)
    expect(coincide(item, 'maria')).toBe(false)
  })
})
