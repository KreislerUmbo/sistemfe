import { describe, expect, it } from 'vitest'
import { claveABytes, esIOS, nombreDispositivo } from './push'

describe('claveABytes', () => {
  it('convierte base64url (sin relleno) a bytes', () => {
    // "AQID_w" = bytes 1, 2, 3, 255 en base64url.
    expect(Array.from(claveABytes('AQID_w'))).toEqual([1, 2, 3, 255])
  })

  it('una clave VAPID pública tiene 65 bytes y empieza con 0x04', () => {
    const clave = claveABytes('BO1GsRNl35sapHn48jOHG79ij6BCZNKssDqhKV02DL1wxPrxn9g5mSLx6kwApznSQ1vMZFRlrYwuNo2BBq1oWi4')
    expect(clave.length).toBe(65)
    expect(clave[0]).toBe(4)
  })
})

describe('nombreDispositivo', () => {
  it('reconoce navegador y sistema', () => {
    expect(nombreDispositivo('Mozilla/5.0 (Linux; Android 14; SM-A146M) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36'))
      .toBe('Chrome · Android')
    expect(nombreDispositivo('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'))
      .toBe('Safari · iPhone')
    expect(nombreDispositivo(null)).toBe('Dispositivo')
  })
})

describe('esIOS', () => {
  it('detecta iPhone por el User-Agent', () => {
    expect(esIOS('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)')).toBe(true)
    expect(esIOS('Mozilla/5.0 (Linux; Android 14)')).toBe(false)
  })
})
