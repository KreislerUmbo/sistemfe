<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Resources\Sale\SaleResource;
use App\Models\Company;
use App\Models\Sale\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Luecano\NumeroALetras\NumeroALetras;

use function Symfony\Component\Clock\now;

class FacturacionElectronicaController extends Controller
{
    //
    protected $greenter_service;
    public function __construct(GreenterService $greenter_service)
    {
        $this->greenter_service = $greenter_service;
    }

    public function getCorrelativo($serie)
    {
        $sale = Sale::where('serie', $serie)
            ->where('correlativo', "<>", null)
            ->where("n_operacion", "<>", null)
            ->orderBy('correlativo', 'desc')
            ->first();
        $correlativo =  1;
        if ($sale) {
            $correlativo = $sale->correlativo + 1;
        }

        return $correlativo;
    }

    public function sunat_seend(Request $request)
    {
      
    $sale_id = $request->sale_id;

        $sale = Sale::findOrFail($sale_id);
        $company = Company::first();
        $data = $this->setTotales($sale);
        $data = $this->setLegends($data);

        $see = $this->greenter_service->getSee();

        $tipo_operacion = "0101"; //segun el catalogo 51, 0101 es el codigo de venta interna de sunat para facturacion electronica

        switch ($sale->retencion_igv) {
            case 2: //si la venta tiene detraccion 
                $tipo_operacion = "1001";
                break;
            case 3: //si la venta tiene percepcion
                $tipo_operacion = "2001";
                break;
            default:
                break;
        }

        if ($sale->is_exportacion == 1) {
            $tipo_operacion = "0200"; //segun el catalogo 51, 0200 es el exportacion de bienes facturas y boletas
        }


        $data['tipo_operacion'] = $tipo_operacion;
        $data['tipo_doc'] = $sale->serie == "F001" ? "01" : "03"; // segun el catalogo 01, donde 01 es factura electronica y 03 es nota de credito 
        $data['serie'] = $sale->serie;
        $data['correlativo'] = $this->getCorrelativo($sale->serie);
        $data['tipo_moneda'] = $sale->currency == "S/." ? "PEN" : "USD";

        $invoice = $this->greenter_service->getInvoice($data, $company, $sale);
        $result = $see->send($invoice);

        $response = $this->greenter_service->sunatResponse($result);

        if (!isset($response['error'])) { //si no hay error
            //crear el objeto DOMDocument
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            $dom->loadXML($see->getFactory()->getLastXml());

            //formateamos el xml con indents
            $formattedXml = $dom->saveXML();

            //generamos un nombre unico para el archivo
            $file_name = "R-" . env("RUC") . "-" .  $data['correlativo'] . "-" . $sale->serie . "-" . $sale->id . ' ' . now()->format('Ymd_His') . '.xml';
            Storage::disk('public')->put('xml/' . $file_name, $formattedXml);

            //obtenemos la ruta publica del archivo
            $public_path_xml = Storage::url('xml/' . $file_name);

            $sale->update([
                "correlativo" => $data['correlativo'],
                "n_operacion" => $data['serie'] . "-" . $data['correlativo'],
                "cdr" => $response['cdrZip'],
                "xml" => $public_path_xml,
            ]);
            return response()->json([
                "sale" => SaleResource::make($sale),
                "response" => $response,
            ]);
        }

        return response()->json([
            "response" => $response,
        ]);
       

    }

    public function setTotales($sale)
    {
        $data = [];
        $data['mto_oper_gravadas'] = $sale->sale_details->where('tip_afe_igv', 10)->sum('subtotal');
        $data['mto_oper_exoneradas'] = $sale->sale_details->where('tip_afe_igv', 20)->sum('subtotal');
        $data['mto_oper_inafectas'] = $sale->sale_details->where('tip_afe_igv', 30)->sum('subtotal');

        $discount_global_anticipo = 0;
        if ($sale->discount_global > 0) {
            $discount_global_anticipo += $sale->discount_global;
        }
        if ($discount_global_anticipo > 0) {
            if ($data['mto_oper_gravadas'] > 0) {
                $data['mto_oper_gravadas'] = $data['mto_oper_gravadas']  - $discount_global_anticipo;
            }
            if ($data['mto_oper_exoneradas'] > 0) {
                $data['mto_oper_exoneradas'] = $data['mto_oper_exoneradas']  - $discount_global_anticipo;
            }
            if ($data['mto_oper_inafectas'] > 0) {
                $data['mto_oper_inafectas'] = $data['mto_oper_inafectas']  - $discount_global_anticipo;
            }
        }


        $data['mto_oper_exportacion'] = $sale->sale_details->where('tip_afe_igv', 40)->sum('subtotal');
        $data['mto_oper_gratuitas'] = $sale->sale_details->whereIn('tip_afe_igv', [11, 12, 13, 14, 15, 16, 31, 32, 33, 34, 35, 36, 37])->sum('subtotal');
        $data['mto_base_ivap'] = $sale->sale_details->where('tip_afe_igv', 17)->sum('subtotal');
        $data['mto_ivap'] = $sale->sale_details->where('tip_afe_igv', 17)->sum('igv');

        $data['mto_igv'] = round($sale->sale_details->whereIn('tip_afe_igv', [10, 20, 30, 40])->sum('igv'), 2) - $sale->igv_discount_general;
        $data['mto_igv_gratuitas'] = $sale->sale_details->whereIn('tip_afe_igv', [11, 12, 13, 14, 15, 16, 31, 32, 33, 34, 35, 36, 37])->sum('igv');

        $data['icbper'] = $sale->sale_details->sum('icbper');
        $data['isc'] = $sale->sale_details->sum('isc');

        $data['total_impuestos'] = $data['mto_igv'] + $data['icbper'] + $data['mto_ivap'] + $data['isc'];

        $data['valor_venta'] = $sale->sale_details->whereIn('tip_afe_igv', [10, 20, 30, 40, 17])->sum('subtotal') - $sale->discount_global;//el valor de venta es la suma de todos los subtotal menos el descuento global
        $data['sub_total'] = $data['valor_venta'] + $data['total_impuestos'];//subtotal = valor venta + total impuestos

        $data['mto_imp_venta'] = floor($data['sub_total'] * 10) / 10;
        $data['redondeo'] = $data['mto_imp_venta'] - $data['sub_total'];

       // error_log(json_encode($data));
        return $data;
    }

    public function setLegends($data)
    {
    $formatter = new NumeroALetras();
        $data['legends'] = [
            [
                'code' => '1000', //codigo de la leyenda 1000 es el monto en letras
                'value' => $formatter->toInvoice($data['mto_imp_venta'], 2, 'SOLES'),//aqui se pasa el monto en letras
            ]
        ];

        return $data;
    }
}
