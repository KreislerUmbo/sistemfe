<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

// Regla de un cronograma de cuotas de una venta a crédito (Módulo
// Amortizaciones, Fase 8). Salió de SaleController::validarConfiguracionCredito()
// (24-oct-2026, fila F5 de Agencia) para que Ventas y la facturación de una
// reserva validen igual, sin dos copias de la regla:
// - solo 'cuotas_fijas' ('libre' se crea por fuera de estos flujos);
// - cada cuota con número (sin repetir), monto > 0 y fecha de vencimiento;
// - la suma calza al céntimo con el saldo a financiar;
// - con mora, tasa > 0 y un tipo válido.
// Rechaza con HttpException(422) y un mensaje para el usuario.
class CronogramaCreditoValidator
{
    public const TIPOS_MORA = ['fijo_por_cuota', 'porcentaje_diario', 'porcentaje_fijo_unico'];

    public static function validar(
        $creditType,
        array $cronograma,
        float $saldoAFinanciar,
        bool $aplicaMora = false,
        $tasaMora = null,
        $tipoMora = null
    ): void {
        if ($creditType !== 'cuotas_fijas') {
            throw new HttpException(
                422,
                "Solo se soporta credit_type='cuotas_fijas' desde este formulario por ahora " .
                "(recibido: '{$creditType}'). 'libre' se sigue creando por fuera de este flujo."
            );
        }

        if (empty($cronograma)) {
            throw new HttpException(422, "Una venta a crédito requiere un cronograma de cuotas (cronograma).");
        }

        $numerosVistos = [];
        $sumaCronogramaCentavos = 0;

        foreach ($cronograma as $cuota) {
            if (!isset($cuota['numero_cuota'], $cuota['monto_programado'], $cuota['fecha_vencimiento'])) {
                throw new HttpException(
                    422,
                    "Cada cuota del cronograma requiere numero_cuota, monto_programado y fecha_vencimiento."
                );
            }

            if ((float) $cuota['monto_programado'] <= 0) {
                throw new HttpException(422, "monto_programado debe ser mayor a 0 en cada cuota del cronograma.");
            }

            $numero = (int) $cuota['numero_cuota'];
            if (in_array($numero, $numerosVistos, true)) {
                throw new HttpException(422, "numero_cuota no puede repetirse dentro del cronograma.");
            }
            $numerosVistos[] = $numero;

            $sumaCronogramaCentavos += (int) round((float) $cuota['monto_programado'] * 100);
        }

        $saldoCentavos = (int) round($saldoAFinanciar * 100);
        if ($sumaCronogramaCentavos !== $saldoCentavos) {
            throw new HttpException(
                422,
                sprintf(
                    "La suma del cronograma (%s) no calza con el saldo a financiar (%s).",
                    number_format($sumaCronogramaCentavos / 100, 2),
                    number_format($saldoCentavos / 100, 2)
                )
            );
        }

        if ($aplicaMora) {
            if ((float) $tasaMora <= 0) {
                throw new HttpException(422, "aplica_mora=true requiere tasa_mora > 0.");
            }
            if (!in_array($tipoMora, self::TIPOS_MORA, true)) {
                throw new HttpException(422, "tipo_mora inválido: '{$tipoMora}'.");
            }
        }
    }
}
