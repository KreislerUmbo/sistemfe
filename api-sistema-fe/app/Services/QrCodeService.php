<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Sale\Note;
use App\Models\Sale\Sale;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class QrCodeService
{
    // ── Generar el QR de la representación impresa ────────────────────
    // Solo tiene sentido si la venta ya fue emitida y aceptada por SUNAT
    // (tiene xml + hash_cpe) — antes de eso no hay comprobante electrónico
    // que representar. Devuelve un data URI PNG listo para <img src="...">
    // en el Blade, sin necesidad de escribir archivos a disco.
    public function generarQrComprobante(Sale $venta): ?string
    {
        if (!$venta->xml || !$venta->hash_cpe) {
            return null;
        }

        $empresa = Company::first();

        // Catálogo 01 SUNAT: '01' = Factura, '03' = Boleta (según prefijo de serie)
        $tipo_doc = str_starts_with($venta->serie, 'F') ? '01' : '03';

        $valor_qr = $this->construirCadenaQr(
            $empresa,
            $tipo_doc,
            $venta->serie,
            $venta->correlativo,
            (float) $venta->igv,
            (float) $venta->mto_imp_venta,
            $venta->date,
            $venta->cod_tipo_doc_cliente,
            $venta->client->n_document ?? '',
            $venta->hash_cpe
        );

        return $this->renderizarQr($valor_qr);
    }

    // ── Generar el QR de una Nota de Crédito/Débito ────────────────────
    // Paralelo a generarQrComprobante(), pero con tipoDoc fijo ('07'/'08',
    // no derivado de un prefijo de serie como en Sale) y todos los campos
    // tomados de la NOTA, no de la venta original que referencia.
    public function generarQrNota(Note $nota): ?string
    {
        if (!$nota->xml || !$nota->hash_cpe) {
            return null;
        }

        $empresa = Company::first();

        $valor_qr = $this->construirCadenaQr(
            $empresa,
            $nota->tipo_doc, // '07' NC, '08' ND
            $nota->serie,
            $nota->correlativo,
            (float) $nota->mto_igv,
            (float) $nota->mto_imp_venta,
            optional($nota->sunat_sent_at)->format('Y-m-d'),
            $nota->cod_tipo_doc_cliente,
            $nota->client->n_document ?? '',
            $nota->hash_cpe
        );

        return $this->renderizarQr($valor_qr);
    }

    // ── String según especificación SUNAT (Res. 183-2016 y modificatorias) ──
    // RUC|tipoDoc|serie|correlativo|IGV|total|fecha|tipoDocCliente|nroDocCliente|hash
    private function construirCadenaQr(
        $empresa,
        string $tipoDoc,
        string $serie,
        ?int $correlativo,
        float $igv,
        float $total,
        ?string $fecha,
        ?string $tipoDocCliente,
        string $numDocCliente,
        string $hash
    ): string {
        return implode('|', [
            $empresa->n_document ?? env('RUC'),
            $tipoDoc,
            $serie,
            $correlativo,
            number_format($igv, 2, '.', ''),
            number_format($total, 2, '.', ''),
            $fecha,
            $tipoDocCliente,
            $numDocCliente,
            $hash,
        ]);
    }

    private function renderizarQr(string $valorQr): string
    {
        $qrCode = new QrCode(
            data: $valorQr,
            size: 180,
            margin: 4,
        );

        $writer = new PngWriter();

        return $writer->write($qrCode)->getDataUri();
    }
}
