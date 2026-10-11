<?php

declare(strict_types=1);

namespace App\Services\Creditos\MiCredito;

use App\Models\Client\Client;
use App\Services\QrCodeService;

/**
 * QR "Mi crédito" para los documentos impresos (Fase 5b, entrega 2): recibos y contrato. Crea el
 * link del cliente la primera vez que hace falta. Un cliente sin documento registrado no puede
 * tener link: el documento sale igual, sin QR — imprimir un cobro nunca falla por esto.
 */
class QrMiCredito
{
    public function __construct(
        private readonly EnlaceClienteService $enlaces,
        private readonly QrCodeService $qr,
    ) {
    }

    /** @return array{qr: string, url: string, texto: string}|null */
    public function paraCliente(?Client $cliente): ?array
    {
        if ($cliente === null) {
            return null;
        }
        try {
            $url = $this->enlaces->urlDe($this->enlaces->paraCliente($cliente));
        } catch (SinDocumentoException) {
            return null;
        }

        return [
            'qr' => $this->qr->generarQrTexto($url),
            'url' => $url,
            // Lo que se imprime debajo del QR: sin "https://", más corto para tipear.
            'texto' => (string) preg_replace('#^https?://#', '', $url),
        ];
    }
}
