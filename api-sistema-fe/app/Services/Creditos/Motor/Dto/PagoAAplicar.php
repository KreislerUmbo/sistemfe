<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Fecha;

/** Pago válido a repartir. Monto recibido en centavos. */
final readonly class PagoAAplicar
{
    public function __construct(
        public int|string $referencia,
        public Fecha $fechaPago,
        /** Desempate de pagos con la misma fecha (id o created_at en Fase 3). */
        public int $secuencia,
        public int $monto,
        public OrigenPago $origen = OrigenPago::Cobro,
        public DestinoExcedente $destinoExcedente = DestinoExcedente::Devolver,
        /**
         * Solo venta_prenda: null = decidir por monto >= liquidación (primer registro);
         * true/false = lo que se decidió al registrarla, para detectar 12.8 al reaplicar.
         */
        public ?bool $esCierre = null,
    ) {
    }
}
