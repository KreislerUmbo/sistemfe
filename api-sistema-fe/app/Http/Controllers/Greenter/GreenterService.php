<?php

namespace App\Http\Controllers\Greenter;

use App\Models\Sale\Sale;
use App\Models\SunatConfig;
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
use Greenter\Model\Sale\Prepayment;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\SalePerception;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GreenterService
{
    // ── Conexión con SUNAT ───────────────────────────────────────────
    // En entorno local usa el endpoint BETA (pruebas) de SUNAT
    // En producción usa el endpoint de producción real
    public function getSee(): See
    {
        $sunatConfig = SunatConfig::where('activo', true)->first();

        if (! $sunatConfig) {
            throw new HttpException(
                422,
                'Este tenant no tiene configuración SUNAT activa. Configúrala antes de emitir.'
            );
        }

        $see = new See();
        $see->setCertificate($this->resolveCertificado($sunatConfig));
        $see->setService(
            $sunatConfig->modo === 'produccion'
                ? SunatEndpoints::FE_PRODUCCION
                : SunatEndpoints::FE_BETA
        );
        $see->setClaveSOL($sunatConfig->ruc, $sunatConfig->sol_usuario, $sunatConfig->sol_clave);

        return $see;
    }

    // ── Guard: Company debe existir antes de intentar emitir ──────────
    // Extraído en Fase E, Paso 1 (antes vivía inline en
    // FacturacionElectronicaController::enviarSunat()) para que el Paso 2
    // (endpoint de verificación `test-emission`, panel superadmin) pueda
    // reusar exactamente el mismo guard/mensaje sin duplicarlo. Antes de
    // este guard, $empresa=null llegaba intacto hasta getInvoice() y
    // reventaba como Error de PHP al leer $empresa->n_document, ya con el
    // correlativo quemado (ver Fase E, Paso 0 del plan).
    public function validarCompanyPresente($empresa): void
    {
        if (! $empresa) {
            throw new HttpException(
                422,
                'Este tenant no tiene datos de Company configurados — complétalos antes de emitir.'
            );
        }
    }

    // ── Verificación completa: ¿este tenant está listo para emitir? ───
    // Fase E, Paso 2 (plan-panel-superadmin.md) — único punto de verdad
    // reusado tanto por enviarSunat() (antes de reservarCorrelativo(), sin
    // cambios de comportamiento desde el Paso 1) como por el endpoint
    // informativo `POST tenants/{id}/test-emission`. Nunca construye un
    // Invoice ni llama a $see->send() — solo confirma que Company,
    // SunatConfig activo y el certificado (demo en beta, propio en
    // producción, con el gate de resolveCertificado() ya existente) están
    // en condiciones, sin tocar ninguna tabla de negocio ni reservar
    // ningún correlativo. Lanza el mismo HttpException 422 explícito que
    // ya lanzan validarCompanyPresente()/getSee() en el primer punto que
    // falle — el caller decide qué hacer con eso (enviarSunat() lo deja
    // escapar tal cual; TenantSunatController::testEmission() lo atrapa
    // dentro de $tenant->run() y lo relanza después, ver ese archivo).
    public function verificarListoParaEmitir($empresa): array
    {
        $this->validarCompanyPresente($empresa);

        // getSee() ya valida SunatConfig activo + resuelve/valida el
        // certificado (incluido el gate de modo=produccion sin
        // certificado propio) — se descarta el See real acá, solo
        // interesa que no haya lanzado.
        $this->getSee();

        // SunatConfig activo ya está garantizado por getSee() de arriba —
        // esta segunda consulta es barata (1 fila por PK/índice) y evita
        // cambiar la firma de getSee() (usado también por enviarSunat()
        // para el See real).
        $sunatConfig = SunatConfig::where('activo', true)->first();

        return [
            'company' => [
                'id' => $empresa->id,
                'razon_social' => $empresa->razon_social,
                'n_document' => $empresa->n_document,
            ],
            'modo' => $sunatConfig->modo,
            'certificado' => [
                'cargado' => $sunatConfig->tieneCertificadoPropio(),
                'propio_o_demo' => $sunatConfig->tieneCertificadoPropio() ? 'propio' : 'demo',
                'valido' => $sunatConfig->tieneCertificadoPropio() ? $sunatConfig->certificadoEsValido() : null,
                'fecha_vencimiento' => $sunatConfig->certificado_fecha_vencimiento,
            ],
        ];
    }

    // ── Resolver certificado: propio del tenant si existe, si no el demo central ──
    // (solo en modo beta — produccion nunca cae al demo, ver abajo). Este gate aplica
    // SOLO acá (envío real a SUNAT) — ninguna otra funcionalidad (productos, clientes,
    // ventas locales) depende de si hay certificado o no.
    //
    // Disco 'private' (Fase B.2) — 'local'/'public' son la misma carpeta pública
    // (storage:link), nunca sirvieron para un certificado digital. El certificado
    // propio del tenant vive aislado ahí; el demo central sigue siendo la excepción
    // que usa base_path() directo (ver más abajo), no Storage.
    protected function resolveCertificado(SunatConfig $sunatConfig): string
    {
        if ($sunatConfig->modo === 'produccion') {
            if (! $sunatConfig->tieneCertificadoPropio()) {
                throw new HttpException(
                    422,
                    'Modo producción exige un certificado propio cargado — no se encontró el archivo configurado.'
                );
            }

            if (! $sunatConfig->certificadoEsValido()) {
                throw new HttpException(
                    422,
                    'El certificado configurado está vencido — no se puede emitir en modo producción.'
                );
            }

            return Storage::disk('private')->get($sunatConfig->certificado_path);
        }

        // Beta: certificado propio si el tenant ya subió uno, si no el demo central.
        if ($sunatConfig->tieneCertificadoPropio()) {
            return Storage::disk('private')->get($sunatConfig->certificado_path);
        }

        // Fallback (solo beta): certificado demo central, compartido entre tenants sin
        // certificado propio todavía. Ruta absoluta vía base_path() a propósito —
        // NO usar Storage::get()/storage_path() acá: bajo tenancy activa,
        // storage_path() queda repuntado a storage/tenant{id}/
        // (FilesystemTenancyBootstrapper, ver config/tenancy.php), y este archivo
        // es un recurso central compartido, nunca se copia por tenant.
        return file_get_contents(base_path('storage/app/public/certificate-demo.pem'));
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
        // Módulo Amortizaciones — Fase 8.0 (fix bloqueante antes de
        // integrar SaleController/register.vue con este módulo).
        //
        // Antes, "crédito con cuotas" se armaba desde sale_payments (con
        // date_payment por fila) si había más de una — mecanismo previo,
        // sin relación con installments (Fase 3). Confirmado contra datos
        // reales de dev (tenant umbo): 0 filas dependían de esa rama (la
        // única venta type_payment=2 real, id=16/F001-00000024, tiene
        // sale_payments_count=1 — no entraba ahí, cayó al default
        // FormaPagoContado() y así quedó ACEPTADA por SUNAT siendo en
        // realidad una venta a crédito interna — hallazgo confirmado, no
        // corregido acá porque un XML ya aceptado no se edita). Se
        // elimina esa rama vieja sin reemplazo — cero riesgo de regresión.
        //
        // Nueva fuente de verdad: installments (cronograma real del
        // módulo). Si type_payment==2 pero no hay ninguna installment
        // activa (credit_type='libre', legado debt/paid_out sin
        // cronograma, o cronograma 100% anulado), NUNCA se cae en
        // silencio a FormaPagoContado() — se bloquea explícito. Ver
        // cuotasActivasParaCredito(), reusado también por el guard
        // temprano de FacturacionElectronicaController::enviarSunat()
        // (antes de reservar correlativo) para no quemar un número SUNAT
        // en una venta que de todos modos va a fallar acá.
        if ((int) $venta->type_payment === 2) {
            $cuotasActivas = $this->cuotasActivasParaCredito($venta);

            if ($cuotasActivas->isEmpty()) {
                throw new HttpException(
                    422,
                    "La venta #{$venta->id} es a crédito (type_payment=2) pero no tiene un " .
                    "cronograma de cuotas vigente (installments) — no se puede declarar su forma " .
                    "de pago ante SUNAT así. Verifica credit_type/installments antes de enviarla."
                );
            }

            $comprobante->setFormaPago(
                new FormaPagoCredito($cuotasActivas->sum('monto_programado'))
            );

            $cuotas = [];
            foreach ($cuotasActivas as $installment) {
                $cuotas[] = (new Cuota())
                    ->setMonto($installment->monto_programado)
                    ->setFechaPago(new DateTime($installment->fecha_vencimiento));
            }
            $comprobante->setCuotas($cuotas);
        } else {
            $comprobante->setFormaPago(new FormaPagoContado());
        }

        // ── Régimen especial: Retención / Detracción / Percepción ─────
        // $descuentos se acumula acá y se aplica UNA sola vez al final
        // (setDescuentos() sobrescribe, no agrega — antes cada caso lo
        // llamaba por separado y el último en ejecutarse pisaba a los
        // demás; con adelantos aplicados esto ya podía chocar con
        // discount_global, así que se corrige acá).
        $descuentos = [];

        switch ($venta->retencion_igv) {

            case 1: // Retención IGV 3% (Res. 037-2002/SUNAT)
                // Solo agentes de retención designados por SUNAT
                // Se declara como descuento tipo "62"
                $monto_retencion = round($datos_comprobante['mto_imp_venta'] * 0.03, 2);
                $descuentos[] = (new Charge())
                    ->setCodTipo("62")   // Catálogo 53: 62 = retención
                    ->setMontoBase($datos_comprobante['mto_imp_venta'])
                    ->setFactor(0.03)    // 3% según Res. 037-2002/SUNAT
                    ->setMonto($monto_retencion);
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
        // NO se declara como AllowanceCharge de cabecera. Se probó así
        // (código "02") y SUNAT rechazaba con error 3291 ("cálculo del IGV
        // incorrecto") porque el descuento ya se aplica prorrateado en cada
        // línea (mto_valor_venta/mto_base_igv, ver getDetallesComprobante())
        // y en los totales del header (TotalesComprobanteCalculator) — un
        // AllowanceCharge adicional aquí duplicaba la resta en el cálculo
        // de LegalMonetaryTotal (venta #4, 2026-07-14). El descuento sigue
        // siendo visible: la línea queda con su valor ya neto y el PDF
        // muestra el descuento global aparte (discount_global en la venta).

        // ── Montos por tipo de operación ──────────────────────────────
        // Estos valores ya vienen calculados y guardados en la venta
        // El campo is_exportacion determina si es exportación o venta interna
        //
        // Adelantos: NO se reduce la base gravada/IGV/valorVenta/subTotal
        // por el anticipo (a diferencia de lo probado en la sesión
        // anterior, que llevó al error 3277). Confirmado contra el ejemplo
        // XML oficial de SUNAT: el detalle de ítems y sus totales de línea
        // quedan con el valor ÍNTEGRO del bien/servicio; solo
        // PrepaidAmount/PayableAmount (más abajo, y mto_imp_venta ya
        // neteado desde FacturacionElectronicaController::enviarSunat())
        // reflejan el descuento del anticipo.
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

        // ── Adelantos aplicados (PrepaidPayment + AllowanceCharge Catálogo 53) ──
        // Requiere que el comprobante de cada adelanto ya tenga n_operacion
        // (enviado y aceptado por SUNAT) — se valida al aplicar el adelanto
        // en SaleController::store(), no acá. RUC del emisor lo agrega
        // Greenter automáticamente desde $empresa (mismo emisor en ambos
        // comprobantes, ver invoice2.1.xml.twig IssuerParty).
        //
        // tipoDocRel usa Catálogo 12 (documentos relacionados tributarios):
        // '02' Factura emitida por anticipos, '03' Boleta emitida por
        // anticipos — NO el Catálogo 01 normal ('01' ahí es "Factura
        // emitida para corregir error en el RUC", no aplica). Confirmado
        // contra el error de Greenter/SUNAT 2505 "Código de documento de
        // referencia debe ser 02 o 03".
        if ($venta->advance_applications->isNotEmpty()) {
            $anticipos = [];

            foreach ($venta->advance_applications as $aplicacion) {
                $ventaAdelanto = $aplicacion->advance->sale;

                $anticipos[] = (new Prepayment())
                    ->setTipoDocRel(str_starts_with($ventaAdelanto->serie, 'F') ? '02' : '03')
                    ->setNroDocRel($ventaAdelanto->n_operacion)
                    ->setTotal((float) $aplicacion->amount_applied);
            }

            $comprobante
                ->setAnticipos($anticipos)
                ->setTotalAnticipos($datos_comprobante['total_anticipos'] ?? 0);

            // AllowanceCharge simbólico, SIN relación numérica con el
            // anticipo. Probado primero SIN este tag → error 3287. Luego
            // con código '00' (Catálogo 53, "Otros descuentos", monto
            // 0.01) → SUNAT devolvió EXACTAMENTE el mismo error 3287,
            // mismo texto, mismo nodo/valor citado — es decir, un
            // AllowanceCharge genérico no satisface la regla: SUNAT exige
            // un código específico de "descuento por anticipos" (Catálogo
            // 53), no cualquier descuento >0. El ejemplo XML oficial usa
            // código '00' pero eso corresponde a un caso SIN el error 3287
            // (esa guía no prueba contra el validador real).
            //
            // Código '04' confirmado 2 veces contra SUNAT beta real: venta
            // gravada (18%) y venta 100% exonerada por Amazonía
            // (mto_oper_gravadas=0). Se probó también '05' en la venta
            // exonerada y SUNAT lo aceptó igual — el validador no exige el
            // código específico por tipo de afectación IGV, solo que sea
            // uno de los códigos "reales" del Catálogo 53 (no el genérico
            // '00'). Se deja '04' fijo por simplicidad: ya está probado en
            // ambos escenarios, sin necesidad de lógica condicional.
            $descuentos[] = (new Charge())
                ->setCodTipo("04")
                ->setMontoBase(0.01)
                ->setFactor(0)
                ->setMonto(0.01);

            // AllowanceTotalAmount = suma de este AllowanceCharge simbólico.
            // El ejemplo oficial de SUNAT sí trae AllowanceTotalAmount
            // cuadrando con la suma de sus AllowanceCharge — sin este set,
            // Greenter omite el tag por completo (ver invoice2.1.xml.twig,
            // condicional `doc.sumOtrosDescuentos is not null`).
            $comprobante->setSumOtrosDescuentos(0.01);
        }

        if (!empty($descuentos)) {
            $comprobante->setDescuentos($descuentos);
        }

        // ── Detalles e leyendas ───────────────────────────────────────
        $comprobante
            ->setDetails($this->getDetallesComprobante($venta->sale_details, (float) ($venta->discount_global ?? 0)))
            ->setLegends($this->getLeyendas($datos_comprobante['legends']));

        return $comprobante;
    }

    // ── Cuotas vigentes del cronograma de crédito de una venta ────────
    // Módulo Amortizaciones — Fase 8.0. Único criterio de "¿esta venta
    // tiene un cronograma financiable para declarar ante SUNAT?" —
    // reusado por getInvoice() (arriba) y por el guard temprano de
    // FacturacionElectronicaController::enviarSunat() (antes de reservar
    // correlativo), para no duplicar la condición en dos lugares. Excluye
    // 'anulada': una cuota anulada ya no es parte del plan de pago
    // vigente (su monto fue redistribuido o condonado por
    // CreditInstallmentController::anular()). Orden por numero_cuota —
    // mismo orden en que Greenter espera las Cuota[] del XML.
    public function cuotasActivasParaCredito(Sale $venta)
    {
        return $venta->installments()
            ->where('estado', '!=', 'anulada')
            ->orderBy('numero_cuota')
            ->get();
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
        // mismos nombres que sale_details a propósito. discount_global solo
        // es distinto de 0 en notas NC04 (descuento global) — para el resto
        // de las notas este default no cambia nada (mismo comportamiento
        // que antes).
        $comprobante
            ->setDetails($this->getDetallesComprobante($nota->note_details, (float) ($nota->discount_global ?? 0)))
            ->setLegends($this->getLeyendas($datos_nota['legends']));

        return $comprobante;
    }

    // ── Armar los detalles (ítems) del comprobante ───────────────────
    // $descuentoGlobal: venta->discount_global. Solo las ventas lo tienen
    // (las notas no) — por eso el default 0 no rompe getNote().
    public function getDetallesComprobante($detalles_venta, float $descuentoGlobal = 0): array
    {
        $detalles_greenter = [];

        // Misma base de prorrateo que TotalesComprobanteCalculator::calcular():
        // el descuento global solo se reparte entre gravadas/exoneradas/
        // inafectas (no exportación ni IVAP/gratuitas).
        $codigos_prorrateables = ['10', '20', '30'];
        $subtotal_prorrateable = (float) $detalles_venta
            ->whereIn('tip_afe_igv', $codigos_prorrateables)
            ->sum('mto_valor_venta');

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
            // mto_valor_venta = precio_base × cantidad (ANTES del descuento
            // de ítem). Este valor ya viene calculado y guardado en sale_details
            $valor_venta_linea = $detalle->mto_valor_venta;

            // ── Base del IGV de la línea ───────────────────────────────
            // mto_base_igv = base neta (ya con descuento de ítem aplicado) + ISC
            // El ISC suma a la base porque el IGV se calcula sobre (base + ISC)
            $base_igv_linea = $detalle->mto_base_igv + $detalle->isc;
            $igv_linea       = $detalle->igv;

            // ── Porcentaje de IGV según tipo de afectación ────────────
            // IMPORTANTE: tip_afe_igv siempre se compara como string
            $porcentaje_igv_linea = match (true) {
                in_array((string) $detalle->tip_afe_igv, ['10', '11']) => 18, // gravado
                (string) $detalle->tip_afe_igv === '17'                => 4,  // IVAP
                default                                                 => 0,  // exonerado/inafecto/exportación
            };

            // ── Precio unitario con IGV incluido ──────────────────────
            $precio_final_linea = $detalle->price_final;

            // ── Descuento global prorrateado a esta línea ─────────────
            // SUNAT exige, para esta línea, que LineExtensionAmount ==
            // TaxSubtotal.TaxableAmount == precio_unitario × cantidad —
            // sin excepción, ni siquiera con un AllowanceCharge de línea
            // documentando la diferencia (se probó: error 3272 igual).
            // Por eso acá se mueven mto_valor_venta, mto_base_igv, el IGV
            // de línea Y el precio unitario TODOS juntos y consistentes —
            // no hay Charge de por medio, el valor de la línea queda
            // directamente neto del descuento global (venta #4, incidente
            // 2026-07-14: 3270/3272/3275/3291 probados y descartados en
            // ese orden antes de llegar a este enfoque).
            if ($descuentoGlobal > 0 && in_array($tip_afe_igv, $codigos_prorrateables, true) && $subtotal_prorrateable > 0) {
                $proporcion             = $valor_venta_linea / $subtotal_prorrateable;
                $descuento_linea_global = round($descuentoGlobal * $proporcion, 2);
                $valor_venta_linea      = round($valor_venta_linea - $descuento_linea_global, 2);
                $base_igv_linea         = round($base_igv_linea - $descuento_linea_global, 2);
                $igv_linea              = round($base_igv_linea * ($porcentaje_igv_linea / 100), 2);

                if ($detalle->quantity > 0) {
                    $precio_valor_unitario = round($valor_venta_linea / $detalle->quantity, 6);
                    $precio_final_linea    = round(($valor_venta_linea + $igv_linea) / $detalle->quantity, 6);
                }
            }

            // ── Total de impuestos de la línea ────────────────────────
            $total_impuestos_linea = $igv_linea + $detalle->icbper + $detalle->isc;

            // ── Armar el objeto SaleDetail de Greenter ─────────────────
            $detalle_greenter = (new SaleDetail())
                ->setCodProducto($detalle->product->sku ?? 'P001')
                ->setUnidad($detalle->unidad_medida)
                ->setDescripcion($detalle->product->title)
                ->setCantidad($detalle->quantity)
                // Precio unitario sin IGV
                ->setMtoValorUnitario($precio_valor_unitario)
                // Valor de la línea — neto del descuento global prorrateado
                // si la venta tiene uno (ver arriba)
                ->setMtoValorVenta($valor_venta_linea)
                // Base sobre la que se calcula el IGV
                ->setMtoBaseIgv($base_igv_linea)
                // Porcentaje de IGV: 18, 4, o 0
                ->setPorcentajeIgv($porcentaje_igv_linea)
                ->setIgv($igv_linea)
                ->setTotalImpuestos($total_impuestos_linea)
                // Código de afectación IGV (Catálogo 07 SUNAT)
                // siempre como string: '10', '17', '20', '30', '40'
                ->setTipAfeIgv((string) $detalle->tip_afe_igv)
                // Precio unitario con IGV incluido
                ->setMtoPrecioUnitario($precio_final_linea);

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

            // ── Descuentos de línea (ítem + prorrateo del descuento global) ──
            // El descuento global NO se declara como Charge de línea (se
            // probó — error 3272 igual, ver comentario más arriba: SUNAT
            // exige LineExtensionAmount == TaxableAmount sin excepción).
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
