<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Resources\Sale\SaleResource;
use App\Models\Company;
use App\Models\Sale\Sale;
use App\Models\Sale\SerieComprobante;
use App\Models\Sale\TipoComprobante;
use App\Services\SerieComprobanteService;
use App\Services\TotalesComprobanteCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Luecano\NumeroALetras\NumeroALetras;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FacturacionElectronicaController extends Controller
{
    protected $greenter_service;
    protected $totales_calculator;
    protected $serie_comprobante_service;

    public function __construct(
        GreenterService $greenter_service,
        TotalesComprobanteCalculator $totales_calculator,
        SerieComprobanteService $serie_comprobante_service
    ) {
        $this->greenter_service          = $greenter_service;
        $this->totales_calculator        = $totales_calculator;
        $this->serie_comprobante_service = $serie_comprobante_service;
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
        $ultima_venta_con_correlativo = Sale::withTrashed()
            ->where('serie', $serie)
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
    // Módulo de series de comprobantes: el bloqueo ya NO es MAX(sales.
    // correlativo) — es lockForUpdate() sobre la fila semilla de
    // serie_comprobantes (SerieComprobanteService::reservarCorrelativo()),
    // que existe desde que la serie se crea, antes de cualquier venta. Esto
    // cierra el bug real de concurrencia que tenía el mecanismo viejo: una
    // serie nueva sin ninguna venta previa no tenía ninguna fila que
    // bloquear, así que dos envíos simultáneos de esa serie podían calcular
    // ambos correlativo=1.
    //
    // Fallback legado: ventas creadas ANTES de este módulo no tienen
    // serie_comprobante_id (sin backfill, ver migración). Para esas —y solo
    // esas— se preserva el mecanismo viejo tal cual, para no romper el envío
    // de una venta vieja que quedó pendiente de SUNAT. withTrashed() en ese
    // camino sigue siendo necesario por el mismo motivo documentado
    // originalmente: un soft-delete de una venta ya aceptada "libera" su
    // correlativo y el siguiente envío real podría reusar un número que
    // SUNAT ya tiene registrado para OTRO comprobante (pasó de verdad el
    // 2026-07-12, ver memoria del proyecto, módulo Adelantos).
    //
    // Devuelve el correlativo entero (sin padding) ya reservado en BD.
    private function reservarCorrelativo(Sale $venta): int
    {
        if ($venta->serie_comprobante_id) {
            $serie = SerieComprobante::findOrFail($venta->serie_comprobante_id);
            $nuevo_correlativo = $this->serie_comprobante_service->reservarCorrelativo($serie);

            // El contador real vive en serie_comprobantes.correlativo_actual
            // (ya incrementado arriba), pero sales.correlativo debe quedar
            // sincronizado de inmediato igual que el camino legado de abajo
            // — enviarSunat() lee $venta->correlativo más adelante (armado
            // del nombre del XML, etc.) antes de llegar a su propio
            // $venta->update() de éxito.
            $venta->update(['correlativo' => $nuevo_correlativo]);

            return $nuevo_correlativo;
        }

        return DB::transaction(function () use ($venta) {
            $ultima = Sale::withTrashed()
                ->where('serie', $venta->serie)
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

        // ── Guard: Company debe existir antes de intentar emitir ──────
        // Antes no se validaba contra null — el fallo recién aparecía más
        // abajo, dentro de GreenterService::getInvoice() (Error de PHP al
        // leer $empresa->n_document), después de ya haber reservado un
        // correlativo real (Fase E, Paso 0 del plan). Se corta acá, antes
        // de cualquier otro guard, con el mismo criterio explícito que el
        // resto de guards fiscales del proyecto. Extraído a
        // GreenterService::validarCompanyPresente() en el Paso 2 (mismo
        // guard, ahora reusado también por el endpoint `test-emission`).
        $this->greenter_service->validarCompanyPresente($empresa);

        // ── Guard: nunca enviar a SUNAT un documento no-fiscal ─────────
        // Defensivo — register.vue/edit.vue ni siquiera ofrecen el botón de
        // envío para nota de venta, pero el endpoint no puede confiar solo
        // en eso. Lee es_documento_sunat (no activo_greenter): son campos
        // distintos, este es el que decide si el documento pasa por
        // Greenter en absoluto. Ventas creadas ANTES de este módulo tienen
        // tipo_comprobante_codigo=null (sin backfill) — se tratan como
        // fiscales por compatibilidad, ya que todas las ventas de antes de
        // este módulo eran fiscales (factura/boleta, no existía otro tipo).
        if ($venta->tipo_comprobante_codigo) {
            $tipo_comprobante = TipoComprobante::find($venta->tipo_comprobante_codigo);

            if ($tipo_comprobante && !$tipo_comprobante->es_documento_sunat) {
                throw new HttpException(
                    422,
                    "La venta #{$venta->id} es tipo '{$venta->tipo_comprobante_codigo}' " .
                    "({$tipo_comprobante->nombre}), un documento interno no fiscal — no puede " .
                    "enviarse a SUNAT."
                );
            }
        }

        // Verificar que la venta no haya sido emitida ya
        if ($venta->xml && $venta->cdr) {
            return response()->json([
                'code'    => 405,
                'message' => 'Este comprobante ya fue enviado a SUNAT. N° Operación: ' . $venta->n_operacion,
            ]);
        }

        // ── Calcular los totales del comprobante ──────────────────────
        // calcularTotalesComprobante() deriva todo desde sale_details, con
        // el valor ÍNTEGRO de los ítems — no descuenta adelantos. Así lo
        // exige el lineamiento SUNAT de pagos anticipados: el detalle y
        // mto_oper_gravadas/igv/valor_venta reflejan la valorización
        // completa del bien/servicio, y solo el total a pagar
        // (PayableAmount / mto_imp_venta) se reduce, más abajo.
        $datos_comprobante = $this->calcularTotalesComprobante($venta);
        $datos_comprobante = $this->armarLeyendas($datos_comprobante);

        // ── Adelantos aplicados a esta venta (PrepaidPayment) ─────────
        $totalAnticipos = round((float) $venta->advance_applications()->sum('amount_applied'), 2);
        $datos_comprobante['total_anticipos'] = $totalAnticipos;

        if ($totalAnticipos > 0) {
            $datos_comprobante['mto_imp_venta'] = round($datos_comprobante['mto_imp_venta'] - $totalAnticipos, 2);

            if ($datos_comprobante['mto_imp_venta'] < 0) {
                throw new HttpException(
                    422,
                    "Los adelantos aplicados (S/ {$totalAnticipos}) superan el total actual del " .
                    "comprobante (posiblemente la venta cambió después de aplicarlos). Revisa la " .
                    "venta antes de enviar a SUNAT."
                );
            }
        }

        // ── Tipo de operación (Catálogo 51 SUNAT) ─────────────────────
        // 0101 = Venta interna (default)
        // 1001 = Operación sujeta a detracción
        // 2001 = Operación sujeta a percepción
        // 0200 = Exportación de bienes o servicios
        //
        // IMPORTANTE — investigado y corregido 2026-07-12 tras rechazo real de
        // SUNAT: el catálogo 51 YA NO tiene ningún código para "anticipos".
        // Una guía PDF oficial de SUNAT (¡pero desactualizada!) documenta
        // "0104 Venta Interna – Anticipos", y así se implementó primero — pero
        // SUNAT rechazó el envío real: "Valor no se encuentra en el catálogo:
        // 51, ID: 0104". Se confirmó contra un catálogo 51 mantenido y
        // actualizado (github.com/jldamians/sunat-catalogs) que el rango
        // 0102–0110 de esa guía fue retirado; los códigos vigentes hoy son
        // 0101, 0112, 0113, 0200–0208, 0301–0303, 0401, 1001–1004, 2001 — NINGUNO
        // para anticipos. Conclusión: el comprobante del propio adelanto se
        // emite con el código estándar de venta interna (0101), igual que
        // cualquier venta — la "anticipo-idad" del documento se representa
        // enteramente en la venta FINAL que lo consume, vía el bloque
        // PrepaidPayment (ver GreenterService::getInvoice()).
        //
        // PENDIENTE (no resuelto en esta sesión, requiere investigación
        // dedicada antes de producción): los códigos de error de SUNAT 2500–
        // 2530 (PrepaidPayment/PaidAmount, OriginatorDocumentReference, "tipo
        // documento del emisor debe ser 6", "grupo de facturas con tag venta
        // anticipada I/II") sugieren que el mecanismo real de SUNAT para
        // anticipos exige más estructura XML de la que Greenter\Model\Sale\
        // Prepayment modela (que solo cubre tipoDocRel/nroDocRel/total). Antes
        // de aplicar un adelanto real en producción, verificar contra SUNAT
        // beta que el XML de la venta final con PrepaidPayment sea aceptado
        // sin observaciones (ver plan, Fase 7 / matriz de pruebas).
        if ($venta->type === 'advance') {
            $tipo_operacion = "0101";
        } else {
            $tipo_operacion = "0101"; // venta interna estándar

            switch ($venta->retencion_igv) {
                case 2: $tipo_operacion = "1001"; break; // detracción
                case 3: $tipo_operacion = "2001"; break; // percepción
            }

            if ($venta->is_exportacion == 1) {
                $tipo_operacion = "0200"; // exportación
            }
        }

        $datos_comprobante['tipo_operacion'] = $tipo_operacion;

        // ── Tipo de comprobante (Catálogo 01 SUNAT) ───────────────────
        // Fuente de verdad: sales.tipo_comprobante_codigo (Módulo de series
        // de comprobantes) — nunca se infiere del prefijo de 'serie', eso se
        // rompe apenas exista una serie con un prefijo no estándar. El
        // fallback por prefijo queda SOLO para ventas creadas antes de este
        // módulo (tipo_comprobante_codigo=null, sin backfill).
        $datos_comprobante['tipo_doc'] = $venta->tipo_comprobante_codigo
            ?? (str_starts_with($venta->serie, 'F') ? '01' : '03');

        $datos_comprobante['serie']       = $venta->serie;

        // ── Blindaje venta a crédito (Módulo Amortizaciones — Fase 8.0) ──
        // Antes de reservar el correlativo: si la venta es a crédito
        // (type_payment==2) pero no tiene un cronograma vigente
        // (installments), GreenterService::getInvoice() la va a rechazar
        // más abajo de todos modos — pero para entonces ya se habría
        // quemado un correlativo real de SUNAT sin usarlo (exactamente el
        // bug que motivó el try/catch de más abajo, ver su comentario
        // sobre la venta #16). Cortar acá, antes de reservarCorrelativo(),
        // evita repetir esa clase de incidente.
        if ((int) $venta->type_payment === 2 && $this->greenter_service->cuotasActivasParaCredito($venta)->isEmpty()) {
            throw new HttpException(
                422,
                "La venta #{$venta->id} es a crédito (type_payment=2) pero no tiene un cronograma " .
                "de cuotas vigente (installments) — no se puede enviar a SUNAT así. Verifica " .
                "credit_type/installments antes de reintentar."
            );
        }

        // ── Validar SunatConfig/certificado ANTES de reservar correlativo ──
        // getSee() ya lanza 422 explícito si no hay SunatConfig activo, y
        // GreenterService::resolveCertificado() ya lanza 422 explícito si
        // modo=produccion sin certificado propio válido (nunca cae al
        // certificado demo en ese caso) — pero hasta esta sesión ambos
        // corrían DENTRO del try/catch de más abajo, que (a) los atrapaba
        // junto con fallos genuinos de envío y (b) siempre devolvía HTTP
        // 200 con el error embebido en el body, sin importar el código
        // real (Fase E, Paso 0 del plan). Se resuelve acá, sin try/catch,
        // mismo criterio que el guard de crédito de arriba: si esto va a
        // fallar, que falle ANTES de quemar un correlativo real, y como
        // excepción real (Laravel la convierte en la respuesta 4xx que ya
        // trae el HttpException).
        $see = $this->greenter_service->getSee();

        // ── Reservar el correlativo real ANTES de armar el comprobante ──
        // Con lock, para que dos envíos simultáneos de la misma serie
        // nunca obtengan el mismo número (ver reservarCorrelativo()).
        $correlativo = $this->reservarCorrelativo($venta);
        $datos_comprobante['correlativo'] = $correlativo;

        // Tipo de moneda en código ISO (PEN = soles, USD = dólares)
        // El frontend guarda 'PEN' o 'USD' directamente
        $datos_comprobante['tipo_moneda'] = $venta->currency ?? 'PEN';

        // ── Enviar a SUNAT usando Greenter ────────────────────────────
        // Envuelto en try/catch: antes, si Greenter lanzaba una excepción
        // de validación (ej. un campo requerido nulo) ANTES de llegar a
        // procesarRespuestaSunat(), el correlativo ya reservado quedaba
        // huérfano sin ningún rastro del motivo — pasó en la práctica con
        // la venta #16 (correlativo quemado, sin xml/cdr, sin mensaje de
        // error guardado en ningún lado). getSee() ya no vive acá (ver
        // arriba) — lo que queda son fallos de construcción del
        // comprobante o de la comunicación real con SUNAT.
        try {
            $invoice = $this->greenter_service->getInvoice($datos_comprobante, $empresa, $venta);
            $result  = $see->send($invoice);
        } catch (\Throwable $e) {
            $venta->update([
                'sunat_error_code'    => null,
                'sunat_error_message' => $e->getMessage(),
                'sunat_sent_at'       => now(),
            ]);

            // Código de estado real: si es un HttpException (ej. el guard
            // de crédito duplicado dentro de getInvoice()) se respeta su
            // código; cualquier otro \Throwable no trae un código de
            // negocio propio — 500 es lo que Laravel habría devuelto de
            // todos modos si este catch no existiera (Fase E, Paso 0).
            $statusCode = $e instanceof HttpException ? $e->getStatusCode() : 500;

            return response()->json([
                'response' => ['error' => ['message' => $e->getMessage()]],
            ], $statusCode);
        }

        // Igual criterio que el try/catch de arriba (venta #16): un fallo acá
        // (storage al guardar el CDR, respuesta de SUNAT con forma inesperada)
        // significa que SUNAT ya recibió el envío pero la app no llegó a
        // procesarlo — sin este catch, el correlativo queda huérfano sin
        // ningún rastro del motivo, igual que el bug original pero un paso
        // más adelante en el flujo.
        try {
            $respuesta = $this->greenter_service->procesarRespuestaSunat($result);
        } catch (\Throwable $e) {
            $mensaje = "CDR recibido pero no procesado: {$e->getMessage()}";

            $venta->update([
                'sunat_error_code'    => null,
                'sunat_error_message' => $mensaje,
                'sunat_sent_at'       => now(),
            ]);

            return response()->json([
                'response' => ['error' => ['message' => $mensaje]],
            ]);
        }

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
                "n_operacion"         => $datos_comprobante['serie'] . "-" . str_pad($correlativo, 8, '0', STR_PAD_LEFT),
                "cdr"                 => $respuesta['cdrZip'],
                "xml"                 => $ruta_publica_xml,
                "hash_cpe"            => $hash_cpe,
                "sunat_sent_at"       => now(),
                // Limpiar el error de un intento previo fallido — si no,
                // un reenvío exitoso deja sunat_error_code/message con el
                // valor del rechazo anterior aunque xml/cdr ya estén bien.
                "sunat_error_code"    => null,
                "sunat_error_message" => null,
            ]);

            return response()->json([
                "sale"     => SaleResource::make($venta),
                "response" => $respuesta,
            ]);
        }

        // ── Si SUNAT rechazó o hubo error ─────────────────────────────
        // Antes se perdía (solo se devolvía en el response transitorio) —
        // ver migración alter_sales_add_sunat_rejection_fields.
        $venta->update([
            'sunat_error_code'    => $respuesta['error']['code'] ?? null,
            'sunat_error_message' => $respuesta['error']['message'] ?? null,
            'sunat_sent_at'       => now(),
        ]);

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