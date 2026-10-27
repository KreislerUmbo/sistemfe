<?php

namespace App\Services;

use InvalidArgumentException;
use Luecano\NumeroALetras\NumeroALetras;

// T-038 (H27): el monto en letras de un comprobante, en un solo lugar. Lo usan
// la leyenda 1000 del XML (venta y nota) y el PDF (Sale/Note::resumenImpresion()).
// Antes el XML de la venta decía "SOLES" siempre, también en una factura en
// dólares, y no coincidía con su propio PDF.
class MontoEnLetras
{
    // "CIENTO CINCUENTA Y DOS CON 50/100 DOLARES"
    public static function comprobante(float $monto, ?string $moneda): string
    {
        return (new NumeroALetras())->toInvoice($monto, 2, self::nombreMoneda($moneda));
    }

    // Sin moneda guardada = soles (ventas antiguas). Otra moneda no se adivina.
    private static function nombreMoneda(?string $moneda): string
    {
        return match ($moneda ?? 'PEN') {
            'PEN' => 'SOLES',
            'USD' => 'DOLARES',
            default => throw new InvalidArgumentException("Moneda sin nombre para el monto en letras: {$moneda}"),
        };
    }
}
