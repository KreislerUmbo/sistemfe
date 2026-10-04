<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Fecha;

/** Cobro pedido por el usuario (00 1.4, 1.5, 1.12). Monto en centavos. */
final readonly class SolicitudCobro
{
    public function __construct(
        public int $montoRecibido,
        public DestinoExcedente $destinoExcedente,
        public ?int $paymentMethodId,
        public string $clave,
        public bool $usarSaldoAFavor = false,
        public ?int $pagadoPorClienteId = null,
        public ?string $referencia = null,
        public ?string $observaciones = null,
        /** Pago retroactivo (1.12): fecha real del cobro; null = hoy. */
        public ?Fecha $fechaPago = null,
        public ?string $motivoRetroactivo = null,
    ) {
    }

    /** @return array<string, mixed> contenido para el hash de idempotencia */
    public function huella(): array
    {
        return [
            'monto' => $this->montoRecibido, 'destino' => $this->destinoExcedente->value, 'metodo' => $this->paymentMethodId,
            'saldo_a_favor' => $this->usarSaldoAFavor, 'pagado_por' => $this->pagadoPorClienteId,
            'fecha_pago' => $this->fechaPago?->aTexto(),
        ];
    }
}
