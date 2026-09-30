<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Origen de un pago (plan 2 `credito_pagos.origen`, 12.10). */
enum OrigenPago: string
{
    case Cobro = 'cobro';
    case Liquidacion = 'liquidacion';
    case Renovacion = 'renovacion';
    case VentaPrenda = 'venta_prenda';
    case SaldoAFavor = 'saldo_a_favor';
    case SaldoInicial = 'saldo_inicial';

    /**
     * Operación que siempre cierra el crédito con el reparto de liquidación (1.5, 1.21).
     * La venta de prenda cierra solo si cubre la liquidación (1.11), por eso no está aquí.
     */
    public function esCierreObligatorio(): bool
    {
        return $this === self::Liquidacion || $this === self::Renovacion;
    }
}
