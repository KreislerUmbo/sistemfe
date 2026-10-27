<?php

namespace App\Models\Concerns;

use App\Models\Client\Client;

/**
 * T-064 (obs. 18, H60) — el comprobante guarda una copia de los datos del
 * cliente: documento, razón social y dirección. Así el PDF, el XML y el QR de
 * un comprobante emitido no cambian si después se edita el cliente.
 *
 * - Al crear, se copia sola si nadie la llenó (cubre todos los caminos:
 *   venta, factura de reserva, adelanto). Una nota la recibe de su venta.
 * - Mientras el comprobante no tiene CDR (no aceptado por SUNAT), se vuelve a
 *   copiar al editarlo y al enviarlo: si el cliente se corrigió antes de
 *   enviar, sale corregido (ver copiarDatosDelCliente()).
 * - Comprobantes anteriores a la migración sin copia: se lee el cliente, como
 *   antes (datosClienteImpresos()).
 */
trait CopiaDatosDelCliente
{
    public static function bootCopiaDatosDelCliente(): void
    {
        static::creating(function (self $comprobante) {
            if ($comprobante->razon_social_cliente === null && $comprobante->client_id) {
                $comprobante->copiarDatosDelCliente();
            }
        });
    }

    // Sin guardar: quien llama decide cuándo (dentro de su transacción).
    public function copiarDatosDelCliente(): void
    {
        $cliente = Client::withTrashed()->find($this->client_id);
        if (! $cliente) {
            return;
        }

        $this->tipo_doc_cliente = $cliente->type_document;
        $this->num_doc_cliente = $cliente->n_document;
        $this->razon_social_cliente = $cliente->full_name;
        $this->direccion_cliente = $cliente->address;
    }

    /**
     * Lo que imprimen el PDF y el XML: la copia, o el cliente si no hay copia.
     *
     * @return array{tipo_doc: ?string, num_doc: ?string, razon_social: ?string, direccion: ?string}
     */
    public function datosClienteImpresos(): array
    {
        if ($this->razon_social_cliente !== null) {
            return [
                'tipo_doc' => $this->tipo_doc_cliente,
                'num_doc' => $this->num_doc_cliente,
                'razon_social' => $this->razon_social_cliente,
                'direccion' => $this->direccion_cliente,
            ];
        }

        $cliente = $this->client;

        return [
            'tipo_doc' => $cliente?->type_document,
            'num_doc' => $cliente?->n_document,
            'razon_social' => $cliente?->full_name,
            'direccion' => $cliente?->address,
        ];
    }
}
