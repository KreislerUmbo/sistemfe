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
use Greenter\Model\Sale\Note as GreenterNote;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\SalePerception;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Support\Facades\Storage;

class GreenterService
{
    // ── Conexión con SUNAT ───────────────────────────────────────────
    // En entorno local usa el endpoint BETA (pruebas) de SUNAT
    // En producción usa el endpoint de producción real
    public function getSee(): See
    {
        $see = new See();
        $see->setCertificate(Storage::get('certificate-demo.pem'));
        $see->setService(
            app()->environment('local')
                ? SunatEndpoints::FE_BETA
                : SunatEndpoints::FE_PRODUCCION
        );
        $see->setClaveSOL(
            env("RUC"),
            env("USER_SOL"),
            env("USER_PASS")
        );

        return $see;
    }

    // ── Datos de la empresa emisora ──────────────────────────────────
    public function getCompany($empresa): Company
    {
        return (new Company())
            ->setRuc($empresa->n_document)
            ->setRazonSocial($empresa->razon_social)
            ->setNombreComercial($empresa->razon_social_comercial)
            ->setAddress($this->getDireccionEmpresa($empresa));
    }

    // ── Dirección de la empresa emisora ─────────────────────────────
    public function getDireccionEmpresa($empresa): Address
    {
        return (new Address())
            ->setUbigueo($empresa->ubigeo_distrito)
            ->setDepartamento($empresa->region)
            ->setProvincia($empresa->provincia)
            ->setDistrito($empresa->distrito)
            ->setUrbanizacion($empresa->urbanizacion)
            ->setDireccion($empresa->address)
            ->setCodLocal($empresa->cod_local);
    }

    // ── Datos del cliente receptor ───────────────────────────────────
    // Usa cod_tipo_doc_cliente guardado en la venta (no recalcula)
    // Este dato se copió del cliente al crear la venta para preservarlo
    public function getCliente($venta): Client
    {
        $cliente = $venta->client;

        // Usamos el código que ya calculamos y guardamos en la venta
        // Catálogo 06 SUNAT: 0=Sin doc, 1=DNI, 4=CE, 6=RUC, 7=Pasaporte
        $codigo_tipo_documento = $venta->cod_tipo_doc_cliente ?? '1';

        return (new Client())
            ->setTipoDoc($codigo_tipo_documento)
            ->setNumDoc($cliente->n_document)
            ->setRznSocial($cliente->full_name);
    }

    // ── Armar el Invoice completo para Greenter ──────────────────────
    public function getInvoice(array $datos_comprobante, $empresa, $venta): Invoice
    {
        $comprobante = (new Invoice())
            ->setUblVersion('2.1')
            ->setTipoOperacion($datos_comprobante['tipo_operacion']) // Catálogo 51
            ->setTipoDoc($datos_comprobante['tipo_doc'])             // Catálogo 01
            ->setSerie($datos_comprobante['serie'])
            ->setCorrelativo($datos_comprobante['correlativo'])
            ->setCompany($this->getCompany($empresa))
            ->setClient($this->getCliente($venta))
            ->setTipoMoneda($datos_comprobante['tipo_moneda'])       // 'PEN' o 'USD'
            ->setFechaEmision(new DateTime());

        // ── Forma de pago ─────────────────────────────────────────────
        // Si es crédito con más de una cuota, se detallan las cuotas
        $es_credito_con_cuotas = $venta->type_payment == 2
            && $venta->sale_payments->count() > 1;

        if ($es_credito_con_cuotas) {
            $comprobante->setFormaPago(
                new FormaPagoCredito($venta->sale_payments->sum('amount'))
            );

            // Armar las cuotas con fecha y monto de cada pago
            $cuotas = [];
            foreach ($venta->sale_payments as $pago) {
                $cuotas[] = (new Cuota())
                    ->setMonto($pago->amount)
                    ->setFechaPago(new DateTime($pago->date_payment));
            }
            $comprobante->setCuotas($cuotas);
        } else {
            $comprobante->setFormaPago(new FormaPagoContado());
        }

        // ── Régimen especial: Retención / Detracción / Percepción ─────
        switch ($venta->retencion_igv) {

            case 1: // Retención IGV 3% (Res. 037-2002/SUNAT)
                // Solo agentes de retención designados por SUNAT
                // Se declara como descuento tipo "62"
                $monto_retencion = round($datos_comprobante['mto_imp_venta'] * 0.03, 2);
                $comprobante->setDescuentos([
                    (new Charge())
                        ->setCodTipo("62")   // Catálogo 53: 62 = retención
                        ->setMontoBase($datos_comprobante['mto_imp_venta'])
                        ->setFactor(0.03)    // 3% según Res. 037-2002/SUNAT
                        ->setMonto($monto_retencion)
                ]);
                break;

            case 2: // Detracción SPOT (Res. 183-2004/SUNAT)
                // El porcentaje y código vienen guardados en la venta
                // (se copiaron del producto al registrar la venta)
                $codigo_bien_detraccion = $venta->codigo_detraccion ?? '037';
                $porcentaje_detraccion  = $venta->porcentaje_detraccion ?? 0.12;
                $monto_detraccion       = round(
                    $datos_comprobante['mto_imp_venta'] * $porcentaje_detraccion,
                    2
                );

                $comprobante->setDetraccion(
                    (new Detraction())
                        // Código del bien/servicio según Anexos 1,2,3 SUNAT
                        ->setCodBienDetraccion($codigo_bien_detraccion)
                        // Depósito en cuenta bancaria (código 001)
                        ->setCodMedioPago("001")
                        ->setCtaBanco(env("CTA_BANCO"))
                        ->setPercent($porcentaje_detraccion)
                        ->setMount($monto_detraccion)
                );
                break;

            case 3: // Percepción (Res. 058-2006/SUNAT)
                // El porcentaje viene guardado en la venta desde tax_configs
                $porcentaje_percepcion = $venta->porcentaje_percepcion ?? 0.02;
                $monto_percepcion      = round(
                    $datos_comprobante['mto_imp_venta'] * $porcentaje_percepcion,
                    2
                );

                $comprobante->setPerception(
                    (new SalePerception())
                        ->setCodReg("51")   // Régimen de percepciones venta interna
                        ->setPorcentaje($porcentaje_percepcion)
                        ->setMtoBase($datos_comprobante['mto_imp_venta'])
                        ->setMto($monto_percepcion)
                        ->setMtoTotal(round(
                            $datos_comprobante['mto_imp_venta'] + $monto_percepcion,
                            2
                        ))
                );
                break;
        }

        // ── ISC a nivel de comprobante (si hay ISC en algún ítem) ─────
        if ($datos_comprobante['isc'] > 0) {
            $comprobante
                ->setMtoBaseIsc($datos_comprobante['mto_oper_gravadas'])
                ->setMtoIsc($datos_comprobante['isc']);
        }

        // ── Descuento global de la venta ──────────────────────────────
        // Se declara como AllowanceCharge tipo "02" en el XML
        if ($venta->discount_global > 0) {
            $comprobante->setDescuentos([
                (new Charge())
                    ->setCodTipo("02")   // Catálogo 53: 02 = descuento global
                    ->setMontoBase($venta->discount_global)
                    ->setFactor(1)
                    ->setMonto($venta->discount_global)
            ]);
        }

        // ── Montos por tipo de operación ──────────────────────────────
        // Estos valores ya vienen calculados y guardados en la venta
        // El campo is_exportacion determina si es exportación o venta interna
        if ($venta->is_exportacion == 1) {
            // Exportación: solo se declara el monto exportado
            $comprobante->setMtoOperExportacion($datos_comprobante['mto_oper_exportacion'] ?? null);
        } else {
            // Venta interna: se declaran los montos por tipo de afectación IGV
            $comprobante
                ->setMtoOperGravadas($datos_comprobante['mto_oper_gravadas'] ?? null)
                ->setMtoOperExoneradas($datos_comprobante['mto_oper_exoneradas'] ?? null)
                ->setMtoOperInafectas($datos_comprobante['mto_oper_inafectas'] ?? null)
                ->setMtoOperGratuitas($datos_comprobante['mto_oper_gratuitas'] ?? null);
        }

        // ── IVAP (arroz pilado) ────────────────────────────────────────
        $comprobante
            ->setMtoBaseIvap($datos_comprobante['mto_base_ivap'] ?? null)
            ->setMtoIvap($datos_comprobante['mto_ivap'] ?? null);

        // ── Impuestos totales del comprobante ─────────────────────────
        $comprobante
            ->setMtoIGV($datos_comprobante['mto_igv'])
            ->setMtoIGVGratuitas($datos_comprobante['mto_igv_gratuitas'])
            ->setIcbper($datos_comprobante['icbper'])
            ->setTotalImpuestos($datos_comprobante['total_impuestos']);

        // ── Totales finales del comprobante ───────────────────────────
        $comprobante
            ->setValorVenta($datos_comprobante['valor_venta'])
            ->setSubTotal($datos_comprobante['sub_total'])
            ->setRedondeo($datos_comprobante['redondeo'])
            ->setMtoImpVenta($datos_comprobante['mto_imp_venta']);

        // ── Detalles e leyendas ───────────────────────────────────────
        $comprobante
            ->setDetails($this->getDetallesComprobante($venta->sale_details))
            ->setLegends($this->getLeyendas($datos_comprobante['legends']));

        return $comprobante;
    }

    // ── Armar la Nota de Crédito/Débito completa para Greenter ────────
    // Análogo a getInvoice(), pero construyendo Greenter\Model\Sale\Note
    // (misma clase para NC y ND — solo cambia tipoDoc y el catálogo de
    // motivo). No lleva forma de pago ni régimen especial
    // (retención/detracción/percepción) — eso es propio de Invoice, las
    // notas no lo llevan.
    //
    // $nota: App\Models\Sale\Note (con relaciones client y note_details ya
    // cargadas) — reutiliza getCliente() y getDetallesComprobante() sin
    // cambios porque ambos métodos solo leen atributos por nombre, sin
    // type hint sobre Sale.
    public function getNote(array $datos_nota, $empresa, $nota): GreenterNote
    {
        $comprobante = (new GreenterNote())
            ->setUblVersion('2.1')
            ->setTipoDoc($datos_nota['tipo_doc'])   // '07' NC, '08' ND
            ->setSerie($datos_nota['serie'])
            ->setCorrelativo($datos_nota['correlativo'])
            ->setCompany($this->getCompany($empresa))
            ->setClient($this->getCliente($nota))
            ->setTipoMoneda($datos_nota['tipo_moneda'])
            ->setFechaEmision(new DateTime())
            // ── Referencia al documento afectado (Catálogo 01 + serie-correlativo) ──
            ->setTipDocAfectado($nota->tipo_doc_afectado)
            ->setNumDocfectado(
                $nota->serie_afectada . '-' . str_pad((string) $nota->correlativo_afectado, 8, '0', STR_PAD_LEFT)
            )
            // ── Motivo (Catálogo 09 NC / 10 ND) ────────────────────────
            ->setCodMotivo($nota->cod_motivo)
            ->setDesMotivo($nota->des_motivo);

        // ── ISC a nivel de comprobante (si hay ISC en algún ítem) ─────
        if ($datos_nota['isc'] > 0) {
            $comprobante
                ->setMtoBaseIsc($datos_nota['mto_oper_gravadas'])
                ->setMtoIsc($datos_nota['isc']);
        }

        // ── Montos por tipo de operación ──────────────────────────────
        $comprobante
            ->setMtoOperGravadas($datos_nota['mto_oper_gravadas'] ?? null)
            ->setMtoOperExoneradas($datos_nota['mto_oper_exoneradas'] ?? null)
            ->setMtoOperInafectas($datos_nota['mto_oper_inafectas'] ?? null)
            ->setMtoOperExportacion($datos_nota['mto_oper_exportacion'] ?? null)
            ->setMtoOperGratuitas($datos_nota['mto_oper_gratuitas'] ?? null);

        // ── IVAP (arroz pilado) ────────────────────────────────────────
        $comprobante
            ->setMtoBaseIvap($datos_nota['mto_base_ivap'] ?? null)
            ->setMtoIvap($datos_nota['mto_ivap'] ?? null);

        // ── Impuestos totales del comprobante ─────────────────────────
        $comprobante
            ->setMtoIGV($datos_nota['mto_igv'])
            ->setMtoIGVGratuitas($datos_nota['mto_igv_gratuitas'])
            ->setIcbper($datos_nota['icbper'])
            ->setTotalImpuestos($datos_nota['total_impuestos']);

        // ── Totales finales del comprobante ───────────────────────────
        $comprobante
            ->setValorVenta($datos_nota['valor_venta'])
            ->setSubTotal($datos_nota['sub_total'])
            ->setRedondeo($datos_nota['redondeo'])
            ->setMtoImpVenta($datos_nota['mto_imp_venta']);

        // ── Detalles y leyendas ─────────────────────────────────────────
        // getDetallesComprobante() reutilizado sin cambios: no tiene type
        // hint, solo lee atributos por nombre — note_details expone los
        // mismos nombres que sale_details a propósito.
        $comprobante
            ->setDetails($this->getDetallesComprobante($nota->note_details))
            ->setLegends($this->getLeyendas($datos_nota['legends']));

        return $comprobante;
    }

    // ── Armar los detalles (ítems) del comprobante ───────────────────
    public function getDetallesComprobante($detalles_venta): array
    {
        $detalles_greenter = [];

        foreach ($detalles_venta as $detalle) {
            // Protección: si tip_afe_igv viene vacío, usar '10' por defecto
            $tip_afe_igv = (string) ($detalle->tip_afe_igv ?? '10');
            if (empty(trim($tip_afe_igv))) {
                $tip_afe_igv = '10';
            }

            // ── Precio unitario ────────────────────────────────────────
            // Para retiros por premio (tip_afe_igv = '11'), el precio unitario
            // se declara como 0 porque es gratuito — el valor va en setMtoValorGratuito
            $precio_valor_unitario = $detalle->price_base;
            if ($detalle->tip_afe_igv == '11') {
                $precio_valor_unitario = 0;
            }

            // ── Valor de venta de la línea ─────────────────────────────
            // mto_valor_venta = precio_base × cantidad (ANTES del descuento)
            // Este valor ya viene calculado y guardado en sale_details
            $valor_venta_linea = $detalle->mto_valor_venta;

            // ── Base del IGV de la línea ───────────────────────────────
            // mto_base_igv = base neta (ya con descuento aplicado) + ISC
            // El ISC suma a la base porque el IGV se calcula sobre (base + ISC)
            $base_igv_linea = $detalle->mto_base_igv + $detalle->isc;

            // ── Porcentaje de IGV según tipo de afectación ────────────
            // IMPORTANTE: tip_afe_igv siempre se compara como string
            $porcentaje_igv_linea = match (true) {
                in_array((string) $detalle->tip_afe_igv, ['10', '11']) => 18, // gravado
                (string) $detalle->tip_afe_igv === '17'                => 4,  // IVAP
                default                                                 => 0,  // exonerado/inafecto/exportación
            };

            // ── Total de impuestos de la línea ────────────────────────
            $total_impuestos_linea = $detalle->igv + $detalle->icbper + $detalle->isc;

            // ── Armar el objeto SaleDetail de Greenter ─────────────────
            $detalle_greenter = (new SaleDetail())
                ->setCodProducto($detalle->product->sku ?? 'P001')
                ->setUnidad($detalle->unidad_medida)
                ->setDescripcion($detalle->product->title)
                ->setCantidad($detalle->quantity)
                // Precio unitario sin IGV
                ->setMtoValorUnitario($precio_valor_unitario)
                // Valor bruto de la línea (sin descuento)
                ->setMtoValorVenta($valor_venta_linea)
                // Base sobre la que se calcula el IGV
                ->setMtoBaseIgv($base_igv_linea)
                // Porcentaje de IGV: 18, 4, o 0
                ->setPorcentajeIgv($porcentaje_igv_linea)
                ->setIgv($detalle->igv)
                ->setTotalImpuestos($total_impuestos_linea)
                // Código de afectación IGV (Catálogo 07 SUNAT)
                // siempre como string: '10', '17', '20', '30', '40'
                ->setTipAfeIgv((string) $detalle->tip_afe_igv)
                // Precio unitario con IGV incluido
                ->setMtoPrecioUnitario($detalle->price_final);

            // ── Retiro por premio (gratuito) ──────────────────────────
            // tip_afe_igv = '11': el precio es 0, pero se declara el valor gratuito
            if ((string) $detalle->tip_afe_igv === '11') {
                $detalle_greenter->setMtoValorGratuito($detalle->price_base);
            }

            // ── ICBPER (bolsa plástica) ────────────────────────────────
            // per_icbper = monto por unidad (ej: 0.50)
            // icbper = monto total = cantidad × per_icbper
            if ($detalle->icbper > 0) {
                $detalle_greenter
                    ->setFactorIcbper($detalle->per_icbper)  // 0.50 por bolsa
                    ->setIcbper($detalle->icbper);            // total = qty × 0.50
            }

            // ── ISC (Impuesto Selectivo al Consumo) ───────────────────
            // Régimen ISC guardado en tipo_isc: '01', '02', o '03'
            if ($detalle->isc > 0) {
                // Base del ISC = valor de venta de la línea (sin descuento)
                $base_isc_linea = $valor_venta_linea;

                $detalle_greenter
                    ->setMtoBaseIsc($base_isc_linea)
                    // Régimen según Catálogo 08 SUNAT
                    ->setTipSisIsc((string) ($detalle->tipo_isc ?? '01'))
                    ->setPorcentajeIsc($detalle->percentage_isc)
                    ->setIsc($detalle->isc);
            }

            // ── Descuento por ítem ────────────────────────────────────
            // Se declara como AllowanceCharge tipo "00" en el XML
            // El factor = descuento / (precio_base × cantidad)
            if ($detalle->discount > 0) {
                $base_descuento_linea = round($detalle->price_base * $detalle->quantity, 2);
                $factor_descuento     = round($detalle->discount / $base_descuento_linea, 6);

                $detalle_greenter->setDescuentos([
                    (new Charge())
                        ->setCodTipo('00')              // Catálogo 53: descuento sobre base
                        ->setMontoBase($base_descuento_linea)
                        ->setFactor($factor_descuento)
                        ->setMonto($detalle->discount)
                ]);
            }

            // ── Código de producto SUNAT (solo en exportaciones) ──────
            // SUNAT requiere el código de producto para exportaciones
            if ($detalle->sale && $detalle->sale->is_exportacion == 1) {
                $detalle_greenter->setCodProdSunat($detalle->product->sku ?? '');
            }

            $detalles_greenter[] = $detalle_greenter;
        }

        return $detalles_greenter;
    }

    // ── Armar las leyendas del comprobante ───────────────────────────
    // Leyenda más común: código '1000' = monto en letras ("SON: CIEN SOLES")
    public function getLeyendas(array $leyendas): array
    {
        $leyendas_greenter = [];
        foreach ($leyendas as $leyenda) {
            $leyendas_greenter[] = (new Legend())
                ->setCode($leyenda['code'] ?? null)
                ->setValue($leyenda['value'] ?? null);
        }
        return $leyendas_greenter;
    }

    // ── Extraer el hash (DigestValue) del XML firmado ────────────────
    // Necesario para armar el string del código QR de la representación
    // impresa (Catálogo: RUC|tipoDoc|serie|correlativo|IGV|total|fecha|
    // tipoDocCliente|nroDocCliente|hash). El hash es el DigestValue que
    // greenter/xmldsig calcula al firmar el XML (ver XMLSecurityDSig::
    // calculateDigest()) y que queda embebido dentro del propio XML firmado,
    // así que se extrae parseándolo en vez de recalcularlo.
    public function extraerHashDigest(string $xmlFirmado): ?string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xmlFirmado);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $nodos = $xpath->query('//ds:DigestValue');

        if ($nodos->length === 0) {
            return null;
        }

        return trim($nodos->item(0)->textContent);
    }

    // ── Procesar respuesta de SUNAT ───────────────────────────────────
    public function procesarRespuestaSunat($resultado): array
    {
        $respuesta = [];
        $respuesta['success'] = $resultado->isSuccess();

        // Si no tuvo éxito, retornar el error para mostrarlo al usuario
        if (!$respuesta['success']) {
            $respuesta['error'] = [
                'code'    => $resultado->getError()->getCode(),
                'message' => $resultado->getError()->getMessage(),
            ];
            return $respuesta;
        }

        // Guardar el CDR (Constancia de Recepción) de SUNAT
        $nombre_archivo_cdr = "cdrs/" . uniqid() . ".zip";
        Storage::disk('public')->put($nombre_archivo_cdr, $resultado->getCdrZip());
        $respuesta['cdrZip'] = Storage::url($nombre_archivo_cdr);

        // Extraer la respuesta del CDR para mostrar al usuario
        $cdr = $resultado->getCdrResponse();
        $respuesta['cdrResponse'] = [
            'code'    => $cdr->getCode(),
            'message' => $cdr->getDescription(),
            'notes'   => $cdr->getNotes(),
        ];

        return $respuesta;
    }

    // Alias del método anterior para compatibilidad con el código existente
    // Se puede eliminar cuando se actualice FacturacionElectronicaController
    public function sunatResponse($resultado): array
    {
        return $this->procesarRespuestaSunat($resultado);
    }
}
