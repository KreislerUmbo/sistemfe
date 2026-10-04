import { describe, expect, it } from 'vitest'
import { enlaceWhatsappConTexto, mensajeDocumento, nombreArchivo } from './documentos'

describe('documentos', () => {
  it('nombre de archivo sin espacios ni tildes', () => {
    expect(nombreArchivo('recibo', 'RC-00000004')).toBe('recibo-RC-00000004.pdf')
    expect(nombreArchivo('estado de cuenta', 'CR 0001')).toBe('estado-de-cuenta-CR-0001.pdf')
    expect(nombreArchivo('constancia')).toBe('constancia.pdf')
    expect(nombreArchivo('acuerdo de reprogramación', 'CR-1')).toBe('acuerdo-de-reprogramacion-CR-1.pdf')
  })

  it('el mensaje saluda por el primer nombre', () => {
    expect(mensajeDocumento('recibo de pago RC-1', 'Juan Pérez Ramírez')).toBe('Hola Juan, le enviamos su recibo de pago RC-1. Gracias por su pago.')
    expect(mensajeDocumento('estado de cuenta')).toMatch(/^Hola, /)
  })

  it('WhatsApp con texto: al celular del cliente o, sin celular, para elegir contacto', () => {
    expect(enlaceWhatsappConTexto('987654321', 'Hola')).toBe('https://wa.me/51987654321?text=Hola')
    expect(enlaceWhatsappConTexto('042 523456', 'a b')).toBe('https://wa.me/?text=a%20b')
  })
})
