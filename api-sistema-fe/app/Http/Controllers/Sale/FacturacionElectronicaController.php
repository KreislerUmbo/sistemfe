<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Resources\Sale\SaleResource;
use App\Models\Company;
use App\Models\Sale\Sale;
use App\Services\TotalesComprobanteCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Luecano\NumeroALetras\NumeroALetras;

class FacturacionElectronicaController extends Controller
{
    protected $greenter_service;
    protected $totales_calculator;

    public function __construct(GreenterService $greenter_service, TotalesComprobanteCalculator $totales_calculator)
    {
        $this->greenter_service   = $greenter_service;
        $this->totales_calculator = $totales_calculator;
    }

    // ── Obtener el próximo correlativo para una serie ─────────────────
    // Se busca la última venta con correlativo asignado en esa serie
    // y se le suma 1. Si no hay ninguna, arranca en 1.
    //
    // NOTA: este método es solo INFORMATIVO (vista previa, ej. mostrar
    // "el próximo sería F001-8" en el frontend). No usar para asignar
    // el correlativo real al enviar a SUNAT — para eso usar
    // reservarCorrelativo(), que sí bloquea la fila para evitar que dos
    // envíos simultáneos de la misma serie obtengan el mismo número.
    public function getCorrelativo(string $serie): int
    {
        $ultima_venta_con_correlativo = Sale::where('serie', $serie)
            ->whereNotNull('correlativo')
            ->whereNotNull('n_operacion')
            ->orderBy('correlativo', 'desc')
            ->first();

        if ($ultima_venta_con_correlativo) {
            return $ultima_venta_con_correlativo->correlativo + 1;
        }

        return 1; // primera emisión de esta serie
    }

    // ── Reservar el correlativo real de forma atómica ─────────────────
    // Envuelve lectura + escritura en una transacción con lockForUpdate,
    // para que dos envíos simultáneos de la misma serie (ej. dos cajas
    // enviando a SUNAT al mismo tiempo) no puedan leer el mismo "último
    // correlativo" y terminar duplicando el número. El lock obliga a que
    // el segundo request espere a que el primero termine su transacción.
    //
    // Devuelve el correlativo entero (sin padding) ya reservado en BD.
    private function reservarCorrelativo(Sale $venta): int
    {
        return DB::transaction(function () use ($venta) {
            $ultima = Sale::where('serie', $venta->serie)
                ->whereNotNull('correlativo')
                ->orderBy('correlativo', 'desc')
                ->lockForUpdate()
                ->first();

            $nuevo_correlativo = $ultima ? $ultima->correlativo + 1 : 1;

            // Reservar de inmediato dentro de la misma transacción,
            // así ningún otro request puede volver a leer este número como "último"
            $venta->update(['correlativo' => $nuevo_correlativo]);

            return $nuevo_correlativo;
        });
    }

    // ── Enviar comprobante a SUNAT ────────────────────────────────────
    public function enviarSunat(Request $request)
    {
        $venta_id = $request->sale_id;

        $venta   = Sale::findOrFail($venta_id);
        $empresa = Company::first();

        // Verificar que la venta no haya sido emitida ya
        if ($venta->xml && $venta->cdr) {
            return response()->json([
                'code'    => 405,
                'message' => 'Este comprobante ya fue enviado a SUNAT. N° Operación: ' . $venta->n_operacion,
            ]);
        }

        // ── Calcular los totales del comprobante ──────────────────────
        $datos_comprobante = $this->calcularTotalesComprobante($venta);
        $datos_comprobante = $this->armarLeyendas($datos_comprobante);

        // ── Tipo de operación (Catálogo 51 SUNAT) ─────────────────────
        // 0101 = Venta interna (default)
        // 1001 = Operación sujeta a detracción
        // 2001 = Operación sujeta a percepción
        // 0200 = Exportación de bienes o servicios
        $tipo_operacion = "0101"; // venta interna estándar

        switch ($venta->retencion_igv) {
            case 2: $tipo_operacion = "1001"; break; // detracción
            case 3: $tipo_operacion = "2001"; break; // percepción
        }

        if ($venta->is_exportacion == 1) {
            $tipo_operacion = "0200"; // exportación
        }

        $datos_comprobante['tipo_operacion'] = $tipo_operacion;

        // ── Tipo de comprobante (Catálogo 01 SUNAT) ───────────────────
        // '01' = Factura electrónica (serie F001)
        // '03' = Boleta de venta electrónica (serie B001)
        $datos_comprobante['tipo_doc'] = str_starts_with($venta->serie, 'F') ? '01' : '03';

        $datos_comprobante['serie']       = $venta->serie;

        // ── Reservar el correlativo real ANTES de armar el comprobante ──
        // Con lock, para que dos envíos simultáneos de la misma serie
        // nunca obtengan el mismo número (ver reservarCorrelativo()).
        $correlativo = $this->reservarCorrelativo($venta);
        $datos_comprobante['correlativo'] = $correlativo;

        // Tipo de moneda en código ISO (PEN = soles, USD = dólares)
        // El frontend guarda 'PEN' o 'USD' directamente
        $datos_comprobante['tipo_moneda'] = $venta->currency ?? 'PEN';

        // ── Enviar a SUNAT usando Greenter ────────────────────────────
        $see     = $this->greenter_service->getSee();
        $invoice = $this->greenter_service->getInvoice($datos_comprobante, $empresa, $venta);
        $result  = $see->send($invoice);

        $respuesta = $this->greenter_service->procesarRespuestaSunat($result);

        // ── Si SUNAT aceptó el comprobante ────────────────────────────
        if (!isset($respuesta['error'])) {

            // Formatear y guardar el XML generado por Greenter
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput       = true;
            $dom->loadXML($see->getFactory()->getLastXml());
            $xml_formateado = $dom->saveXML();

            // Nombre único del archivo XML para identificarlo fácilmente
            $nombre_xml = "R-" . env("RUC") . "-"
                . $datos_comprobante['correlativo'] . "-"
                . $venta->serie . "-"
                . $venta->id . "_"
                . now()->format('Ymd_His') . ".xml";
$xml = $see->getFactory()->getLastXml();
Storage::disk('public')->put('debug_xml.xml', $xml);
            Storage::disk('public')->put('xml/' . $nombre_xml, $xml_formateado);
            $ruta_publica_xml = Storage::url('xml/' . $nombre_xml);

            // Hash SUNAT (DigestValue) embebido en el XML firmado — se usa para
            // armar el código QR de la representación impresa (ver SaleController::pdf()).
            $hash_cpe = $this->greenter_service->extraerHashDigest($xml);

            // Actualizar la venta con el n_operacion definitivo (con padding a 8 dígitos)
            // y las rutas del XML/CDR. El correlativo ya quedó reservado en
            // reservarCorrelativo(), no se vuelve a tocar aquí.
            $venta->update([
                "n_operacion" => $datos_comprobante['serie'] . "-" . str_pad($correlativo, 8, '0', STR_PAD_LEFT),
                "cdr"         => $respuesta['cdrZip'],
                "xml"         => $ruta_publica_xml,
                "hash_cpe"    => $hash_cpe,
            ]);

            return response()->json([
                "sale"     => SaleResource::make($venta),
                "response" => $respuesta,
            ]);
        }

        // ── Si SUNAT rechazó o hubo error ─────────────────────────────
        return response()->json([
            "response" => $respuesta,
        ]);
    }

    // ── Calcular totales del comprobante ─────────────────────────────
    // Delega en TotalesComprobanteCalculator (compartido con
    // NotaElectronicaController) — ver ese archivo para la lógica completa
    // de agrupación por tip_afe_igv y redondeo SUNAT. Extraído para que
    // ventas y notas de crédito/débito usen exactamente el mismo cálculo,
    // sin duplicar la lógica de redondeo en dos controladores distintos.
    public function calcularTotalesComprobante($venta): array
    {
        return $this->totales_calculator->calcular(
            $venta->sale_details,
            (float) ($venta->discount_global ?? 0)
        );
    }

    // ── Armar las leyendas del comprobante ────────────────────────────
    // La leyenda más común es el monto en letras (código 1000)
    // Ej: "SON: CIENTO CINCUENTA Y DOS CON 50/100 SOLES"
    public function armarLeyendas(array $datos): array
    {
        $convertidor = new NumeroALetras();

        $datos['legends'] = [
            [
                'code'  => '1000', // Catálogo 52: 1000 = monto en letras
                'value' => $convertidor->toInvoice($datos['mto_imp_venta'], 2, 'SOLES'),
            ]
        ];

        return $datos;
    }
}