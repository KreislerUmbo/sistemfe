// Regla del tratamiento tributario en Agencia de Viajes (fila 13, 22-oct-2026,
// decidida con el usuario): un servicio turístico solo queda exonerado porque
// se presta en la Amazonía (Ley 27037). Por eso, en Agencia, elegir
// "Exonerado" (código SUNAT 20) fija el destino en Amazonía.
//
// Solo para pantallas de Agencia: en retail (productos y ventas) "exonerado +
// nacional" sí es válido (p. ej. alimentos del Apéndice I del IGV) y no se
// aplica esta regla. El backend la aplica también en los modelos de Agencia
// (App\Models\AgenciaViajes\Concerns\ExoneradoEsAmazonia).
export const IGV_EXONERADO = '20';

export const NOTA_EXONERADO_AGENCIA = 'En Agencia, el exonerado viene de la Amazonía (Ley 27037).';

// Devuelve el mismo tipo de destino que recibe (cada pantalla tiene el suyo),
// o 'amazonia' si el IGV es exonerado.
export function destinoParaIgvAgencia<T extends string | null | undefined>(tipAfeIgv: string | null | undefined, destinoActual: T): T | 'amazonia' {
  return tipAfeIgv === IGV_EXONERADO ? 'amazonia' : destinoActual;
}
