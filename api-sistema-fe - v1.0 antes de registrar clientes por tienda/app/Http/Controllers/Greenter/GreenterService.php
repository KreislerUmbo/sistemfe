<?php

namespace App\Http\Controllers\Greenter;


use DateTime;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Charge;
use Greenter\Model\Sale\Cuota;
use Greenter\Model\Sale\Detraction;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\FormaPagos\FormaPagoCredito;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\SalePerception;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Support\Facades\Storage;


class GreenterService
{
    public function getSee()
    {
        $see = new See();
        $see->setCertificate(Storage::get('certificate-demo.pem'));
        $see->setService(env("APP_ENV") == "local" ? SunatEndpoints::FE_BETA : SunatEndpoints::FE_PRODUCCION);
        $see->setClaveSOL(env("RUC"), env("USER_SOL"), env("USER_PASS"));

        return $see;
    }

    public function getCompany($company)
    {
        return (new Company())
            ->setRuc($company->n_document)
            ->setRazonSocial($company->razon_social)
            ->setNombreComercial($company->razon_social_comercial)
            ->setAddress($this->getAddress($company)); //->setAddress(new Address($company->address, $company->ubigeo_region, $company->ubigeo_provincia, $company->ubigeo_distrito, $company->urbanizacion, $company->cod_local);),

    }

    public function getAddress($company)
    {
        return (new Address())
            ->setUbigueo($company->ubigeo_distrito)
            ->setDepartamento($company->region)
            ->setProvincia($company->provincia)
            ->setDistrito($company->distrito)
            ->setUrbanizacion($company->urbanizacion)
            ->setDireccion($company->address)
            ->setCodLocal($company->cod_local);
    }
    public function getClient($sale)
    {
        $cod_document = 1;
        $client = $sale->client;
        switch ($client->type_document) {
            case 'DNI':
                $cod_document = 1;
                break;
            case 'RUC':
                $cod_document = 6;
                break;
            case 'CARNET DE EXTRANJERIA':
                $cod_document = 4;
                break;
            case 'PASAPORTE':
                $cod_document = 7;
                break;
            default:
                $cod_document = 0;
                break;
        }


        return (new Client())
            ->setTipoDoc($cod_document)
            ->setNumDoc($client->n_document)
            ->setRznSocial($client->full_name);
    }

    public function getInvoice($data, $company, $sale)
    {
        $invoice = (new Invoice())
            ->setUblVersion('2.1')
            ->setTipoOperacion($data['tipo_operacion']) // 0101 venta -catalogo 51 
            ->setTipoDoc($data['tipo_doc']) //catalogo 01 //factura electronica 01
            ->setSerie($data['serie'])
            ->setCorrelativo($data['correlativo'])
            ->setCompany($this->getCompany($company))
            ->setClient($this->getClient($sale))
            ->setTipoMoneda($data['tipo_moneda'])
            ->setFechaEmision(new DateTime());

        if ($sale->type_payment == 2 && $sale->sale_payments->count() > 1) {
            $invoice->setFormaPago(new FormaPagoCredito($sale->sale_payments->sum('amount')));
            $cuotas = [];
            foreach ($sale->sale_payments as $payment) {
                array_push($cuotas, (new Cuota())
                    ->setMonto($payment->amount)
                    ->setFechaPago(new DateTime($payment->date_payment)));
            }
            $invoice->setCuotas($cuotas);
        } else {
            $invoice->setFormaPago(new FormaPagoContado());
        }

        if ($sale->retencion_igv > 0) { //para la retencion
            if ($sale->retencion_igv == 1) {
                $invoice->setDescuentos([
                    (new Charge())
                        ->setCodTipo("62")
                        ->setMontoBase($data['mto_imp_venta'])
                        ->setFactor(0.03)
                        ->setMonto(round($data['mto_imp_venta'] * 0.03, 2))
                ]);
            }

            if ($sale->retencion_igv == 2) { //para la detraccion
                $invoice->setDetraccion(
                    (new Detraction())
                        //CARNES Y DESPOJOS COMESTIBLES
                        ->setCodBienDetraccion("014") //SEGUN EL CATALOGO 54
                        //DEPOSITO EN CUENTA
                        ->setCodMedioPago("001")
                        ->setCtaBanco(env("CTA_BANCO"))
                        ->setPercent(0.04)
                        ->setMount(round($data['mto_imp_venta'] * 0.04, 2))
                );
            }
            if ($sale->retencion_igv == 3) { //para la percepcion
                $invoice->setPerception(
                    (new SalePerception())
                        ->setCodReg("51")
                        ->setPorcentaje(0.04)
                        ->setMtoBase($data['mto_imp_venta'])
                        ->setMto(round($data['mto_imp_venta'] * 0.04, 2))
                        ->setMtoTotal(round((($data['mto_imp_venta'] * 0.04) + $data['mto_imp_venta']), 2))
                );
            }
        }

        if ($data['isc'] > 0) {
            $invoice->setMtoBaseIsc($data['mto_oper_gravadas'])
                ->setMtoIsc($data['isc']);
        }
        if ($sale->discount_global > 0) {
            $invoice->setDescuentos([
                (new Charge())
                    ->setCodTipo("02")
                    ->setMontoBase($sale->discount_global)
                    ->setFactor(1)
                    ->setMonto($sale->discount_global)
            ]);
        }

        if ($sale->is_exportation == 1) {
            $invoice->setMtoOperExportacion($data['mto_oper_exportacion'] ?? null);
        } else {
            $invoice->setMtoOperGravadas($data['mto_oper_gravadas'] ?? null)
                ->setMtoOperExoneradas($data['mto_oper_exoneradas'] ?? null)
                ->setMtoOperInafectas($data['mto_oper_inafectas'] ?? null)
                ->setMtoOperGratuitas($data['mto_oper_gratuitas'] ?? null);
        }


        $invoice->setMtoBaseIvap($data['mto_base_ivap'] ?? null)
            ->setMtoIvap($data['mto_ivap'] ?? null)

            //impuestos

            ->setMtoIGV($data['mto_igv'])
            ->setMtoIGVGratuitas($data['mto_igv_gratuitas'])
            ->setIcbper($data['icbper'])
            ->setTotalImpuestos($data['total_impuestos'])

            //totales
            ->setValorVenta($data['valor_venta'])
            ->setSubTotal($data['sub_total'])
            ->setRedondeo($data['redondeo'])
            ->setMtoImpVenta($data['mto_imp_venta'])

            ->setDetails($this->getDetails($sale->sale_details))
            ->setLegends($this->getLegends($data['legends']));


        return $invoice;
    }

    public function getDetails($sale_details)
    {
        $greenter_sale_details = [];
        foreach ($sale_details as $sale_detail) {
            $mto_valor_unitario = $sale_detail->price_base;
            $mto_valor_venta = $sale_detail->subtotal;
            $mto_base_igv = $sale_detail->subtotal + $sale_detail->isc;

            $porcentaje_igv = 18;
            if (!in_array($sale_detail->tip_afe_igv, ['10', '11'])) {
                $porcentaje_igv = 0;
            }
            if ($sale_detail->tip_afe_igv == 11) {
                $mto_valor_unitario = 0;
            }
            if ($sale_detail->tip_afe_igv == 17) {
                $porcentaje_igv = 4;
            }

            $igv = $sale_detail->igv;
            $total_impuestos = $igv + $sale_detail->icbper + $sale_detail->isc;
            $mto_precio_unitario = $sale_detail->price_final;

            $detail = (new SaleDetail())
                ->setCodProducto('P001')
                ->setUnidad($sale_detail->unidad_medida)
                ->setDescripcion($sale_detail->product->title)
                ->setCantidad($sale_detail->quantity)
                ->setMtoValorUnitario($mto_valor_unitario)
                ->setMtoValorVenta($mto_valor_venta)
                ->setMtoBaseIgv($mto_base_igv)
                ->setPorcentajeIgv($porcentaje_igv)
                ->setIgv($igv)
                ->setTotalImpuestos($total_impuestos)
                ->setTipAfeIgv($sale_detail->tip_afe_igv) //el codigo del tipo de afe igv catalogo 01
                ->setMtoPrecioUnitario($mto_precio_unitario);

            if ($sale_detail->tip_afe_igv == 11) {
                $detail->setMtoValorGratuito($sale_detail->price_base);
            }

            if ($sale_detail->icbper > 0) {
                $detail->setFactorIcbper($sale_detail->icbper)
                ->setIcbper($sale_detail->icbper);
            }
            
            if ($sale_detail->isc > 0) {
                $detail->setMtoBaseIsc($mto_valor_venta)
                    ->setTipSisIsc('01')
                    ->setPorcentajeIsc($sale_detail->percentage_isc) //17%
                    ->setIsc($sale_detail->isc);
            }

            if ($sale_detail->discount > 0) {
                $monto_base = round($sale_detail->price_base * $sale_detail->quantity, 2);
                $detail->setDescuentos([
                    (new Charge())
                        ->setCodTipo('00')//catalgo 53, 00: descuento que afecta a la base imponible
                        ->setMontoBase($monto_base)
                        ->setFactor(round($sale_detail->discount / $monto_base, 6))
                        ->setMonto($sale_detail->discount)
                ]);
            }

            if ($sale_detail->sale && $sale_detail->sale->is_exportacion == 1) {
                $detail->setCodProdSunat($sale_detail->product->sku);
            }

            array_push($greenter_sale_details, $detail);
        }
        return $greenter_sale_details;
    }

    public function getLegends($legends)
    {
        $green_legends = [];
        foreach ($legends as $legend) {
            $green_legends[] = (new Legend())
                ->setCode($legend['code'] ?? null)
                ->setValue($legend['value'] ?? null);
        }
        return $green_legends;
    }

    public function sunatResponse($result)
    {
        $response = [];
        $response['success'] = $result->isSuccess();

        if (!$response['success']) {
            $response['error'] = [
                'code' => $result->getError()->getCode(),
                'message' => $result->getError()->getMessage(),
            ];
            return $response;
        }

        //generamos un nombre unico para el archivo
        $file_name = "cdrs/" . uniqid() . '.' . 'zip';
        Storage::disk('public')->put($file_name, $result->getCdrZip());

        //obtenemos la ruta publica del archivo
        $public_path = Storage::url($file_name);
        $response['cdrZip'] = $public_path;


        $cdr = $result->getCdrResponse();
        $response['cdrResponse'] = [
            'code' => $cdr->getCode(),
            'message' => $cdr->getDescription(),
            'notes' => $cdr->getNotes(),
        ];
        return $response;
    }
}
