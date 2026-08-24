<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Http\Resources\Client\ClientCollection;
use App\Http\Resources\Product\ProductCollection;
use App\Http\Resources\Sale\SaleCollection;
use App\Http\Resources\Sale\SaleResource;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Models\Company;
use App\Models\Credit\Installment;
use App\Models\Product\Product;
use App\Models\Product\TaxConfig;
use App\Models\Product\DetractionCode;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Models\Sale\SalePayment;
use App\Models\Sale\SerieComprobante;
use App\Models\Sale\TipoComprobante;
use App\Services\AdvanceApplicationService;
use App\Services\CashCorrectionService;
use App\Services\QrCodeService;
use App\Services\SerieComprobanteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaleController extends Controller
{
    public function __construct(
        private CashCorrectionService $cashCorrection,
        private SerieComprobanteService $serieComprobanteService,
        private AdvanceApplicationService $advanceApplicationService
    ) {
    }

    // Mapa tipo_comprobante_codigo → permiso Spatie que habilita emitirlo
    // desde este controlador. Deliberadamente NO incluye '07'/'08' (NC/ND)
    // — esos se emiten desde NotaElectronicaController, un flujo separado
    // que este módulo no toca.
    private const PERMISOS_EMISION = [
        '01' => 'emitir_factura',
        '03' => 'emitir_boleta',
        'NV' => 'emitir_nota_venta',
    ];

    // ── Blindaje ISC: el backend es la fuente de verdad, no el frontend ──
    // El cálculo de ISC ocurre en el frontend (sale/register.vue) y aquí
    // solo se persistía tal cual llegaba. Si el producto tiene datos
    // residuales de ISC (bug conocido: is_isc=false pero percentage_isc o
    // monto_isc_fijo > 0) o el payload fue manipulado, el ISC se colaba en
    // la venta y en el comprobante SUNAT. Aquí se verifica is_isc del
    // producto real en BD y, si es false, se tratan percentage_isc,
    // monto_isc_fijo y tipo_isc como huérfanos (se descartan) sin importar
    // qué haya mandado el cliente.
    private function sanitizeIscForDetail(array $campos_detalle, int $product_id): array
    {
        $producto = Product::find($product_id);

        if (!$producto || !$producto->is_isc) {
            $isc_removido = (float) ($campos_detalle['isc'] ?? 0);

            $campos_detalle['percentage_isc'] = 0;
            $campos_detalle['isc'] = 0;
            $campos_detalle['tipo_isc'] = null;
            $campos_detalle['monto_isc_fijo'] = 0;

            // Mantener consistente el total de impuestos de la línea
            // (igv + isc + icbper) tras descartar el ISC indebido.
            if ($isc_removido > 0 && isset($campos_detalle['total_impuestos'])) {
                $campos_detalle['total_impuestos'] = max(0, $campos_detalle['total_impuestos'] - $isc_removido);
            }
        }

        return $campos_detalle;
    }

    // ── Blindaje regímenes especiales: retención/detracción/percepción son ──
    // incompatibles con operaciones exoneradas, inafectas o de exportación.
    // SUNAT no permite aplicar estos regímenes sobre ese tipo de operaciones
    // (Res. de Superintendencia del Régimen de Retenciones, art. 7.2).
    // retencion_igv es un SELECTOR ÚNICO: 0=ninguno, 1=retención, 2=detracción, 3=percepción.
    // Se valida aquí, antes de abrir transacción, para no dejar ventas
    // inconsistentes que luego fallen (o peor, se envíen mal) a Greenter/SUNAT.
    private function validarRegimenEspecial(Request $request): void
    {
        $retencion_igv = (int) $request->retencion_igv;

        if ($retencion_igv === 0) {
            return; // no se aplicó ningún régimen especial, nada que validar
        }

        $tiene_exoneracion = (float) ($request->mto_oper_exoneradas ?? 0) > 0;
        $tiene_inafecto     = (float) ($request->mto_oper_inafectas ?? 0) > 0;
        $es_exportacion     = (int) ($request->is_exportacion ?? 0) === 1;

        if (!$tiene_exoneracion && !$tiene_inafecto && !$es_exportacion) {
            return; // operación gravada normal, el régimen especial es válido
        }

        $regimenes = [1 => 'Retención', 2 => 'Detracción', 3 => 'Percepción'];
        $nombre_regimen = $regimenes[$retencion_igv] ?? 'Régimen especial';

        $motivo = $es_exportacion
            ? 'una operación de exportación'
            : ($tiene_exoneracion ? 'una operación exonerada' : 'una operación inafecta');

        throw new HttpException(
            422,
            "No se puede aplicar {$nombre_regimen} sobre {$motivo}. SUNAT no permite " .
            "retención, detracción ni percepción del IGV en operaciones exoneradas, " .
            "inafectas o de exportación. Revisa la configuración tributaria de la venta."
        );
    }

    // ── Blindaje detracción: la BD ya no puede garantizar esto sola ──
    // Hasta ahora, si se guardaba un codigo_detraccion inexistente, la FK
    // de Postgres lo rechazaba. Con la migración a multi-tenancy,
    // detraction_codes va a vivir en una base central distinta — Postgres
    // ya no puede validar esta referencia con una FK normal, así que el
    // chequeo pasa a vivir acá.
    private function validarCodigoDetraccion(Request $request): void
    {
        if (empty($request->codigo_detraccion)) {
            return;
        }

        $existe_detraccion = DetractionCode::where('codigo', $request->codigo_detraccion)
            ->where('estado', true)
            ->exists();

        if (!$existe_detraccion) {
            throw new HttpException(422, 'Código de detracción inválido');
        }
    }

    // ── Módulo de series de comprobantes: resolver sucursal + tipo + serie ──
    // Corre ANTES de DB::beginTransaction() — mismo motivo que el resto de
    // validadores de este controlador (el catch() de store() convierte
    // cualquier HttpException lanzada dentro de la transacción en un 500).
    //
    // Devuelve la sucursal, el TipoComprobante y la SerieComprobante ya
    // resueltos — store() los usa para persistir serie/tipo_comprobante_codigo/
    // serie_comprobante_id, y (solo para tipos no-fiscales) reservar el
    // correlativo de inmediato.
    private function resolverSerieComprobante(Request $request): array
    {
        $usuario = auth('api')->user();

        // ── Tipo de comprobante + permiso de emisión ─────────────────────
        $codigo_tipo = $request->tipo_comprobante_codigo;

        if (empty($codigo_tipo)) {
            throw new HttpException(422, 'tipo_comprobante_codigo es requerido.');
        }

        $permiso_requerido = self::PERMISOS_EMISION[$codigo_tipo] ?? null;

        if (!$permiso_requerido) {
            throw new HttpException(
                422,
                "El tipo de comprobante '{$codigo_tipo}' no se puede emitir desde este formulario."
            );
        }

        if (!$usuario->can($permiso_requerido)) {
            throw new HttpException(
                422,
                "Tu usuario no tiene permiso para emitir este tipo de comprobante ({$permiso_requerido})."
            );
        }

        // ── Sucursal + serie activa: resolución compartida con AdvanceController ──
        $branch_id_solicitado = ($usuario->can('can_switch_branch') && $request->filled('branch_id'))
            ? (int) $request->branch_id
            : null;
        $moneda = $request->currency ?? 'PEN';

        return $this->serieComprobanteService->resolverParaUsuario($usuario, $codigo_tipo, $moneda, $branch_id_solicitado);
    }

    // ── Preview en vivo de la serie resuelta (register.vue/edit.vue) ─────
    // Reusa exactamente la misma validación de resolverSerieComprobante()
    // (permiso, sucursal, serie activa) — nunca persiste nada. El usuario
    // necesitaba ver QUÉ serie/correlativo va a usar antes de guardar (el
    // <select> viejo mostraba "F001 — Factura" directamente; el nuevo
    // selector de tipo de documento no muestra ninguna serie por sí solo).
    public function previewSerieComprobante(Request $request)
    {
        $resuelta = $this->resolverSerieComprobante($request);

        return response()->json([
            'serie' => $resuelta['serie']->serie,
            'siguiente_correlativo' => $resuelta['serie']->correlativo_actual + 1,
        ]);
    }

    // ── Módulo de series de comprobantes: resolver tipo/serie en update() ──
    // Editar el tipo de documento de una venta ya creada solo es seguro
    // mientras NO tenga un correlativo reservado — ese momento es idéntico
    // para fiscales (recién en enviarSunat()) y para NV (inmediato en
    // store()), así que un solo chequeo (`correlativo === null`) cubre
    // ambos casos. Con correlativo ya reservado, cambiar de tipo dejaría un
    // correlativo reservado bajo un tipo de documento que ya no es el que
    // la venta declara — se bloquea con 422 explícito si el payload intenta
    // cambiarlo; si no lo intenta (mismo tipo de siempre), no hay error,
    // simplemente no se re-resuelve nada.
    private function resolverSerieComprobanteUpdate(Sale $venta, Request $request): array
    {
        $puedeCambiar = $venta->correlativo === null;

        if (!$puedeCambiar) {
            $tipoSolicitado = $request->tipo_comprobante_codigo;

            if ($tipoSolicitado && $tipoSolicitado !== $venta->tipo_comprobante_codigo) {
                throw new HttpException(
                    422,
                    "No se puede cambiar el tipo de documento de la venta #{$venta->id} — ya tiene " .
                    "un correlativo reservado (" . ($venta->n_operacion
                        ? "enviada a SUNAT, N° Operación {$venta->n_operacion}"
                        : "nota de venta ya numerada, correlativo {$venta->correlativo}") . ")."
                );
            }

            return [
                'cambia' => false,
                'tipo'   => TipoComprobante::find($venta->tipo_comprobante_codigo),
                'serie'  => $venta->serie_comprobante_id ? SerieComprobante::find($venta->serie_comprobante_id) : null,
            ];
        }

        $resuelta = $this->resolverSerieComprobante($request);

        return ['cambia' => true, 'tipo' => $resuelta['tipo'], 'serie' => $resuelta['serie']];
    }

    // ── Blindaje adelantos: validación de forma que no requiere BD ────
    // Solo valida la forma del payload y que la suma no supere el total —
    // la pertenencia al cliente, el saldo disponible y que el adelanto ya
    // haya sido enviado a SUNAT se validan dentro de la transacción (ver
    // store()), con lockForUpdate() para evitar condiciones de carrera si
    // dos ventas aplican el mismo adelanto al mismo tiempo.
    private function validarAdelantosPayload(Request $request): void
    {
        $aplicaciones = $request->advance_applications ?? [];

        if (empty($aplicaciones)) {
            return;
        }

        // Una cotización no es una venta confirmada — no debe poder reservar/
        // consumir el saldo de un adelanto real de cliente (hallazgo de
        // auditoría del módulo, 2026-08-21).
        if ((int) $request->state_sale === 2) {
            throw new HttpException(422, "No se puede aplicar un adelanto a una cotización, solo a una venta confirmada.");
        }

        $suma = 0;
        $ids_vistos = [];
        foreach ($aplicaciones as $aplicacion) {
            if (!isset($aplicacion['advance_id'], $aplicacion['amount']) || (float) $aplicacion['amount'] <= 0) {
                throw new HttpException(422, "Cada adelanto aplicado requiere advance_id y amount > 0.");
            }

            $advance_id = $aplicacion['advance_id'];
            if (in_array($advance_id, $ids_vistos, true)) {
                throw new HttpException(422, "El adelanto #{$advance_id} aparece más de una vez en la misma venta.");
            }
            $ids_vistos[] = $advance_id;

            $suma += (float) $aplicacion['amount'];
        }

        if (round($suma, 2) > round((float) $request->total, 2)) {
            throw new HttpException(
                422,
                "La suma de adelantos aplicados (S/ " . number_format($suma, 2) . ") " .
                "supera el total de la venta (S/ " . number_format((float) $request->total, 2) . ")."
            );
        }
    }

    // ── Blindaje pagos: la suma no puede superar el total a pagar ────
    // No había ningún candado (ni frontend al guardar, ni backend) que
    // impidiera registrar una venta con pagos por encima del total — el
    // frontend solo valida cada pago contra el total vigente al momento de
    // agregarlo (sale/register.vue addPayment()), pero si el total baja
    // después (se quita una línea, cambia un descuento/régimen) ese pago
    // ya agregado nunca se re-valida. $request->total es el total ANTES
    // de descontar adelantos (ver "Total/mto_imp_venta netos del
    // anticipo" más abajo), así que acá se resta lo mismo que ya se
    // validó en validarAdelantosPayload() para comparar contra lo que
    // realmente queda por pagar.
    private function validarPagosPayload(Request $request): void
    {
        $pagos = $request->payments ?? [];

        if (empty($pagos)) {
            return;
        }

        $suma_pagos = 0;
        foreach ($pagos as $pago) {
            if (!isset($pago['amount']) || (float) $pago['amount'] <= 0) {
                throw new HttpException(422, "Cada pago requiere un amount > 0.");
            }

            // Módulo Caja — Fase 0 (plan-modulo-caja.md §3): el método de pago
            // recibido debe existir y estar activo en payment_methods.
            // Comparación normalizada (LOWER) como cinturón de seguridad ante
            // inconsistencias de formato — no transforma method_payment, solo
            // valida; sales.payment_method sigue guardándose tal cual llega.
            $metodo_pago = trim($pago['method_payment'] ?? '');
            $existe_metodo_activo = PaymentMethod::whereRaw('LOWER(code) = ?', [strtolower($metodo_pago)])
                ->where('is_active', true)
                ->exists();

            if (!$existe_metodo_activo) {
                throw new HttpException(422, "Método de pago no válido o inactivo: \"{$metodo_pago}\".");
            }

            $suma_pagos += (float) $pago['amount'];
        }

        $suma_adelantos = collect($request->advance_applications ?? [])
            ->sum(fn ($aplicacion) => (float) ($aplicacion['amount'] ?? 0));

        $total_a_pagar = round((float) $request->total - $suma_adelantos, 2);

        if (round($suma_pagos, 2) > $total_a_pagar) {
            throw new HttpException(
                422,
                "La suma de pagos (S/ " . number_format($suma_pagos, 2) . ") " .
                "supera el total a pagar de la venta (S/ " . number_format($total_a_pagar, 2) . ")."
            );
        }
    }

    // ── Blindaje venta a crédito (Módulo Amortizaciones — Fase 8) ────
    // Reutiliza el selector type_payment existente (plan-modulo-amortizaciones.md
    // §6 punto 8, §9): type_payment==2 es el único hecho de origen — acá se
    // valida la configuración de crédito que store()/update() persisten
    // dentro de la misma transacción de la venta. 'libre' queda fuera de
    // alcance de esta fase (decisión explícita, no un olvido): el
    // formulario solo ofrece 'cuotas_fijas'.
    //
    // $request->debt ya viene neto de adelantos y pagos iniciales, calculado
    // en el frontend (register.vue, Math.max(0, totalConAdelanto - total_payments))
    // — mismo criterio que el resto de este controlador: los totales
    // financieros se confían del frontend, no se recalculan acá. El
    // cronograma se valida contra ESE monto, no contra el total bruto.
    private function validarConfiguracionCredito(Request $request): void
    {
        if ((int) $request->type_payment !== 2) {
            return;
        }

        if ($request->credit_type !== 'cuotas_fijas') {
            throw new HttpException(
                422,
                "Solo se soporta credit_type='cuotas_fijas' desde este formulario por ahora " .
                "(recibido: '{$request->credit_type}'). 'libre' se sigue creando por fuera de este flujo."
            );
        }

        $cronograma = $request->cronograma ?? [];
        if (empty($cronograma)) {
            throw new HttpException(422, "Una venta a crédito requiere un cronograma de cuotas (cronograma).");
        }

        $numerosVistos = [];
        $sumaCronogramaCentavos = 0;

        foreach ($cronograma as $cuota) {
            if (!isset($cuota['numero_cuota'], $cuota['monto_programado'], $cuota['fecha_vencimiento'])) {
                throw new HttpException(
                    422,
                    "Cada cuota del cronograma requiere numero_cuota, monto_programado y fecha_vencimiento."
                );
            }

            if ((float) $cuota['monto_programado'] <= 0) {
                throw new HttpException(422, "monto_programado debe ser mayor a 0 en cada cuota del cronograma.");
            }

            $numero = (int) $cuota['numero_cuota'];
            if (in_array($numero, $numerosVistos, true)) {
                throw new HttpException(422, "numero_cuota no puede repetirse dentro del cronograma.");
            }
            $numerosVistos[] = $numero;

            $sumaCronogramaCentavos += (int) round((float) $cuota['monto_programado'] * 100);
        }

        $saldoCentavos = (int) round((float) $request->debt * 100);

        if ($sumaCronogramaCentavos !== $saldoCentavos) {
            throw new HttpException(
                422,
                sprintf(
                    "La suma del cronograma (%s) no calza con el saldo a financiar (%s).",
                    number_format($sumaCronogramaCentavos / 100, 2),
                    number_format($saldoCentavos / 100, 2)
                )
            );
        }

        if ($request->boolean('aplica_mora')) {
            if ((float) $request->tasa_mora <= 0) {
                throw new HttpException(422, "aplica_mora=true requiere tasa_mora > 0.");
            }
            if (!in_array($request->tipo_mora, ['fijo_por_cuota', 'porcentaje_diario', 'porcentaje_fijo_unico'], true)) {
                throw new HttpException(422, "tipo_mora inválido: '{$request->tipo_mora}'.");
            }
        }
    }

    // ── Blindaje Caja (Módulo Caja — Fase 3, plan-modulo-caja.md §5 regla
    // #3, §6). Cualquier pago inmediato (payments[] con amount > 0) exige
    // una cash_session abierta del cajero — incluye el pago inicial de una
    // venta a crédito (type_payment==2 con payments no vacío). Una venta
    // 100% a crédito sin pago inicial (payments vacío) nunca exige caja,
    // coherente con la regla #3: el financiamiento no genera cash_movement
    // al momento de la venta, solo cuando se cobra una cuota
    // (CreditPaymentController, módulo Amortizaciones).
    //
    // Sin distinción de canal (pos/ecommerce): confirmado por grep que el
    // portal e-commerce no pasa por este controller — usa Order/
    // OrderController (tabla `orders`), completamente separado de `Sale`.
    // El guard aplica a todo store()/update() sin condición de canal.
    private function resolverSesionCajaAbierta(Request $request): ?CashSession
    {
        $pagos = $request->payments ?? [];

        if (empty($pagos)) {
            return null;
        }

        $sesion = CashSession::where('opened_by', auth('api')->user()->id)
            ->where('status', 'open')
            ->first();

        if (!$sesion) {
            throw new HttpException(
                422,
                'No hay una sesión de caja abierta. Debes abrir caja antes de registrar ventas.'
            );
        }

        return $sesion;
    }

    // Genera un cash_movement tipo 'sale_payment' por cada pago — compartido
    // por store() (alta directa) y aplicarSincronizacionCaja() (update()).
    private function crearCashMovementsPorPagos(array $pagos, CashSession $sesion, Sale $venta, int $userId): void
    {
        foreach ($pagos as $pago) {
            $metodo = PaymentMethod::whereRaw('LOWER(code) = ?', [strtolower(trim($pago['method_payment']))])->first();

            // No debería pasar — validarPagosPayload() ya validó que el
            // método existe y está activo antes de llegar acá — pero esas
            // son dos llamadas separadas en el tiempo, y si este helper
            // alguna vez se invoca desde otro lugar sin pasar por esa
            // validación primero, un $metodo->id sobre null sería un 500
            // sin contexto en vez de un error controlado.
            if (!$metodo) {
                throw new HttpException(500, "Método de pago no resuelto al generar cash_movement: \"{$pago['method_payment']}\" — esto no debería pasar si validarPagosPayload() ya corrió antes.");
            }

            CashMovement::create([
                'cash_session_id'   => $sesion->id,
                'type'              => 'sale_payment',
                'payment_method_id' => $metodo->id,
                'direction'         => 'in',
                'amount'            => $pago['amount'],
                'reference_type'    => 'sale',
                'reference_id'      => $venta->id,
                'status'            => 'confirmed',
                'created_by'        => $userId,
            ]);
        }
    }

    // ── Sincronización Caja en update() (Módulo Caja — Fase 3, plan §5
    // regla #1). Los cash_movements nunca se editan/borran a nivel de dato.
    // Si los pagos de la venta cambian, se generan movimientos 'correction'
    // que anulan los viejos (marcados con corrected_by/corrected_at,
    // contenido intacto) más movimientos 'sale_payment' nuevos con el dato
    // correcto — nunca un UPDATE directo sobre la fila original.
    //
    // Corre ANTES de DB::beginTransaction() — misma razón que el resto de
    // validadores de este controlador: el catch() de update() es un solo
    // catch(\Throwable) que no distingue HttpException de un error real, así
    // que cualquier 422/403 lanzado DENTRO de la transacción se convertiría
    // en un 500 al hacer rollback. Toda decisión (incluido el permiso de
    // supervisor) se resuelve acá; aplicarSincronizacionCaja() solo ejecuta.
    private function prepararSincronizacionCaja(Sale $venta, array $pagosNuevos): array
    {
        $movimientosActivos = CashMovement::where('reference_type', 'sale')
            ->where('reference_id', $venta->id)
            ->where('type', 'sale_payment')
            ->whereNull('corrected_by')
            ->with('paymentMethod')
            ->get();

        $firmaVieja = $movimientosActivos
            ->map(fn ($m) => strtolower($m->paymentMethod->code) . '|' . number_format((float) $m->amount, 2, '.', ''))
            ->sort()->values()->all();

        $firmaNueva = collect($pagosNuevos)
            ->map(fn ($p) => strtolower(trim($p['method_payment'])) . '|' . number_format((float) $p['amount'], 2, '.', ''))
            ->sort()->values()->all();

        if ($firmaVieja === $firmaNueva) {
            return ['accion' => 'ninguna'];
        }

        // Algo cambió (montos, métodos, o se agregaron/quitaron pagos). Si
        // había movimientos vivos y su sesión ya cerró (ya se hizo el
        // arqueo de ese día), corregirlos exige permiso de supervisor —
        // igual criterio que el cierre de sesión por terceros (Fase 2).
        if ($movimientosActivos->isNotEmpty()) {
            $sesionOriginal = CashSession::find($movimientosActivos->first()->cash_session_id);

            if ($sesionOriginal && $sesionOriginal->status === 'closed'
                && !auth('api')->user()->can('cash.close_others_session')) {
                throw new HttpException(
                    403,
                    'Los pagos de esta venta ya fueron registrados en una sesión de caja cerrada — ' .
                    'corregirlos requiere permiso de supervisor (cash.close_others_session).'
                );
            }
        }

        // La corrección y/o los pagos nuevos siempre se registran en la
        // sesión abierta ACTUAL de quien edita — nunca en la sesión vieja
        // (aunque siga abierta y sea la misma), mismo criterio que la regla
        // #2 del plan para reembolsos de NC.
        $sesionActual = CashSession::where('opened_by', auth('api')->user()->id)
            ->where('status', 'open')
            ->first();

        if (!$sesionActual) {
            throw new HttpException(
                422,
                'No hay una sesión de caja abierta. Debes abrir caja antes de modificar los pagos de una venta.'
            );
        }

        return [
            'accion'                 => 'sincronizar',
            'sesion_destino_id'      => $sesionActual->id,
            'movimientos_a_corregir' => $movimientosActivos,
            'pagos_nuevos'           => $pagosNuevos,
        ];
    }

    // Corre DENTRO de la transacción de update(), junto al reemplazo de
    // sale_payments — solo ejecuta el plan ya decidido y validado por
    // prepararSincronizacionCaja(), sin volver a lanzar HttpException.
    //
    // Módulo Caja — Fase 6: delega la corrección en CashCorrectionService
    // (extraído tras encontrar este mismo patrón replicado en
    // CashMovementController/CreditPaymentController). Cambio de
    // comportamiento explícitamente acordado: antes cada 'correction' se
    // guardaba con reference_type='sale'/reference_id=$venta->id; ahora,
    // con la convención unificada del servicio, queda
    // reference_type='cash_movement'/reference_id=$original->id (apunta a
    // QUÉ anula, no a la venta que lo originó — la venta sigue siendo
    // recoverable navegando desde $original->reference_id). No afecta
    // ningún conteo/monto ya verificado en el checklist de Fase 3 (esos
    // solo revisaban cantidades y el contenido intacto del original, nunca
    // este campo).
    private function aplicarSincronizacionCaja(array $plan, Sale $venta): void
    {
        if ($plan['accion'] === 'ninguna') {
            return;
        }

        $userId = auth('api')->user()->id;
        $sesionDestino = CashSession::find($plan['sesion_destino_id']);

        foreach ($plan['movimientos_a_corregir'] as $original) {
            $this->cashCorrection->revertirMovimiento($original, auth('api')->user());
        }

        $this->crearCashMovementsPorPagos($plan['pagos_nuevos'], $sesionDestino, $venta, $userId);
    }

    // ── Listado de ventas con filtros ────────────────────────────────
    public function index(Request $request)
    {
        $buscar_producto  = $request->search_product;
        $categorie_id     = $request->categorie_id;
        $buscar_id_venta  = $request->search;
        $buscar_cliente   = $request->search_client;
        $estado_venta     = $request->state_sale;
        $tipo_pago        = $request->type_payment;
        $fecha_inicio     = $request->start_date;
        $fecha_fin        = $request->end_date;

        $ventas = Sale::filterMultiple(
            $buscar_producto,
            $categorie_id,
            $buscar_id_venta,
            $buscar_cliente,
            $estado_venta,
            $tipo_pago,
            $fecha_inicio,
            $fecha_fin
        )
            // Notas ya emitidas por venta (eager load: evita N+1 al armar
            // el badge de notas en el listado, ver SaleResource).
            ->with("notes")
            ->orderBy("id", "desc")
            ->paginate(25);

        return response()->json([
            "total"    => $ventas->total(),
            "paginate" => 25,
            "sales"    => SaleCollection::make($ventas),
        ]);
    }

    // ── Configuración inicial del formulario de ventas ───────────────
    public function config()
    {
        $fecha_hoy = today();

        // Clientes activos ordenados por nombre para el selector
        $clientes = Client::where("state", 1)
            ->orderBy("full_name", "asc")
            ->get();

        // Productos activos (excluye los de nota especial)
        $productos = Product::where("state", 1)
            ->where("is_especial_nota", 0)
            ->orderBy("title", "asc")
            ->get();

        // Próximo número de transacción interno — ver Sale::siguienteNumeroTransaccion()
        $siguiente_n_transaction = Sale::siguienteNumeroTransaccion();

        // Parámetros tributarios desde tax_configs (tasas configuradas por el contador)
        // El frontend los usa para calcular IGV, detracción, percepción, etc.
        // No se usan valores hardcodeados en el código
        $parametros_tributarios = TaxConfig::where('es_activo', true)
            ->get(['nombre', 'tipo', 'tasa_porcentaje', 'codigo_sunat', 'ubigeos_aplicables'])
            ->groupBy('tipo');

        // Códigos de detracción activos para el selector del formulario
        $codigos_detraccion = DetractionCode::where('estado', true)
            ->orderBy('codigo')
            ->get(['codigo', 'anexo', 'nombre', 'tasa_porcentaje', 'monto_minimo']);

        // Defaults de mora de la empresa (Módulo Amortizaciones — Fase 8,
        // plan §3.1 punto 5) — el frontend prellena el checkbox aplica_mora
        // de la tarjeta de Configuración de Crédito con esto, editable por
        // venta puntual.
        $empresa = Company::first();

        return response()->json([
            "clients"                => ClientCollection::make($clientes),
            "products"               => ProductCollection::make($productos),
            "today"                  => $fecha_hoy->format("Y-m-d"),
            "n_transaction"          => $siguiente_n_transaction,
            // Agrupados por tipo: { "IGV": [...], "IVAP": [...], "ICBPER": [...], "ISC": [...] }
            "parametros_tributarios" => $parametros_tributarios,
            "codigos_detraccion"     => $codigos_detraccion,
            "company_mora_defaults"  => [
                "mora_habilitada_default" => $empresa?->mora_habilitada_default ?? false,
                "tasa_mora_default"       => $empresa?->tasa_mora_default,
                "tipo_mora_default"       => $empresa?->tipo_mora_default,
            ],
        ]);
    }

    // ── Generar la representación impresa (PDF) de la venta ──────────
    // Solo accesible vía URL firmada temporal (middleware 'signed' en la
    // ruta) — ver pdfSignedUrl() para el endpoint autenticado que genera
    // esa URL. Soporta 2 formatos: 'a4' (factura/boleta normal) y
    // 'ticket80mm' (impresora térmica), seleccionable con ?format=.
    public function pdf(string $id, Request $request)
    {
        $venta = Sale::with(['client', 'user', 'sale_details.product', 'sale_details.product_categorie', 'sale_payments'])
            ->find($id);

        if (!$venta) {
            return abort(404);
        }

        $formato = $request->query('format');
        if (!in_array($formato, ['a4', 'ticket80mm'])) {
            $formato = $venta->user->formato_impresion_default ?? 'a4';
        }

        $empresa = Company::first();
        $qr      = app(QrCodeService::class)->generarQrComprobante($venta);
        $vista   = $formato === 'ticket80mm' ? 'pdf.ticket80mm' : 'pdf.a4';

        $pdf = Pdf::loadView($vista, compact('venta', 'empresa', 'qr'));

        if ($formato === 'ticket80mm') {
            // Ancho fijo 80mm (≈226.77pt). Dompdf no soporta alto "auto" real,
            // así que se estima el alto según cantidad de ítems.
            $alto_estimado = 480 + ($venta->sale_details->count() * 40);
            $pdf->setPaper([0, 0, 226.77, $alto_estimado], 'portrait');
        } else {
            // Papel A4 explícito — el default de Dompdf es "letter" (carta US),
            // y este comprobante es para el mercado peruano.
            $pdf->setPaper('a4', 'portrait');
        }

        return $pdf->stream('comprobante_' . $id . '.pdf');
    }

    // ── Generar URL firmada temporal para imprimir/ver el PDF ────────
    // Requiere estar autenticado (ruta dentro del grupo auth:api). Devuelve
    // una URL que incluye firma + expiración (10 min) para la ruta pública
    // 'sales.pdf' — así se puede compartir/abrir sin login pero sin exponer
    // los comprobantes a cualquiera que adivine el ID.
    public function pdfSignedUrl(Request $request, string $id)
    {
        $venta = Sale::with('user')->findOrFail($id);

        $formato = $request->query('format');
        if (!in_array($formato, ['a4', 'ticket80mm'])) {
            $formato = $venta->user->formato_impresion_default ?? 'a4';
        }

        $url = URL::temporarySignedRoute('sales.pdf', now()->addMinutes(10), [
            'id'     => $id,
            'format' => $formato,
        ]);

        return response()->json(['url' => $url]);
    }

    // ── Registrar nueva venta ────────────────────────────────────────
    public function store(Request $request)
    {
        $this->validarRegimenEspecial($request);
        $this->validarCodigoDetraccion($request);
        $this->validarAdelantosPayload($request);
        $this->validarPagosPayload($request);
        $sesionCaja = $this->resolverSesionCajaAbierta($request);
        $this->validarConfiguracionCredito($request);
        $serieResuelta = $this->resolverSerieComprobante($request);

        $detalles_venta       = $request->sale_details;
        $pagos_venta          = $request->payments;
        $aplicaciones_adelanto = $request->advance_applications ?? [];
        $cronograma_credito    = $request->cronograma ?? [];

        // condicion_pago se DERIVA de type_payment, no se acepta como campo
        // separado del payload — un solo hecho de origen (§9 del plan), no
        // dos campos que alguien podría desincronizar desde el frontend.
        $condicion_pago = (int) $request->type_payment === 2 ? 'credito' : 'contado';

        try {
            DB::beginTransaction();

            // ── Crear cabecera de la venta ────────────────────────────
            $venta = Sale::create([
                // Identificación
                "date"            => $request->date,
                // serie/tipo_comprobante_codigo/serie_comprobante_id vienen de
                // resolverSerieComprobante() (Módulo de series de comprobantes)
                // — ya no es un valor libre elegido en el frontend, se resuelve
                // contra la serie activa de la sucursal del usuario.
                "serie"                   => $serieResuelta['serie']->serie,
                "tipo_comprobante_codigo" => $serieResuelta['tipo']->codigo,
                "serie_comprobante_id"    => $serieResuelta['serie']->id,
                "n_transaction"   => $request->n_transaction,
                // n_operacion NO se asigna aquí para documentos fiscales. El
                // correlativo real solo existe cuando la venta se envía a SUNAT
                // (ver FacturacionElectronicaController::enviarSunat() →
                // reservarCorrelativo()). Asignarlo aquí producía valores rotos
                // tipo "F001-" para ventas aún no enviadas.
                //
                // Nota de venta (es_documento_sunat=false) es la única
                // excepción: nunca pasa por enviarSunat(), así que su
                // correlativo se reserva de inmediato más abajo, apenas la
                // cabecera existe — pero tampoco setea n_operacion, ese campo
                // es exclusivamente del envío SUNAT real.

                // Relaciones
                "user_id"         => auth('api')->user()->id,
                "client_id"       => $request->client_id,
                "type_client"     => $request->type_client,

                // Tipo de documento del cliente (Catálogo 06 SUNAT)
                // Se copia aquí para preservarlo aunque el cliente cambie después
                "cod_tipo_doc_cliente" => $request->cod_tipo_doc_cliente,

                // Configuración de la operación
                "currency"        => $request->currency,      // 'PEN' o 'USD'
                "is_exportacion"  => $request->is_exportacion,
                "destino"         => $request->destino,       // 'amazonia' o 'nacional'
                "state_sale"      => $request->state_sale,    // 1=venta, 2=cotización
                "type_payment"    => $request->type_payment,  // 1=contado, 2=crédito

                // Totales calculados en el frontend
                "subtotal"        => $request->subtotal,
                "igv"             => $request->igv,
                "total"           => $request->total,
                "discount"        => $request->discount,
                "discount_global" => $request->discount_global,
                "igv_discount_general" => $request->igv_discount_general ?? 0,

                // Totales pre-calculados para Greenter (evita recalcularlos al emitir)
                "mto_oper_gravadas"    => $request->mto_oper_gravadas ?? 0,
                "mto_oper_exoneradas"  => $request->mto_oper_exoneradas ?? 0,
                "mto_oper_inafectas"   => $request->mto_oper_inafectas ?? 0,
                "mto_oper_exportacion" => $request->mto_oper_exportacion ?? 0,
                "mto_oper_gratuitas"   => $request->mto_oper_gratuitas ?? 0,

                // Impuestos desagregados por tipo
                "isc_total"        => $request->isc_total ?? 0,
                "icbper_total"     => $request->icbper_total ?? 0,
                "ivap_total"       => $request->ivap_total ?? 0,
                "total_impuestos"  => $request->total_impuestos ?? 0,

                // Totales finales del comprobante
                "valor_venta"      => $request->valor_venta ?? 0,
                "mto_imp_venta"    => $request->mto_imp_venta ?? 0,
                "redondeo"         => $request->redondeo ?? 0,

                // Regímenes especiales
                "retencion_igv"        => $request->retencion_igv,   // 0,1,2,3
                "monto_retencion"      => $request->monto_retencion ?? 0,
                "codigo_detraccion"    => $request->codigo_detraccion,
                "porcentaje_detraccion" => $request->porcentaje_detraccion ?? 0,
                "monto_detraccion"     => $request->monto_detraccion ?? 0,
                "porcentaje_percepcion" => $request->porcentaje_percepcion ?? 0,
                "monto_percepcion"     => $request->monto_percepcion ?? 0,

                // Estado de pago
                "state_payment"   => $request->state_payment,  // 1=pendiente, 2=parcial, 3=pagado
                "debt"            => $request->debt,            // saldo pendiente
                "paid_out"        => $request->paid_out,        // monto ya pagado

                // Anticipo (si aplica)
                "n_comprobante_anticipo" => $request->n_comprobante_anticipo,
                "amount_anticipo"        => $request->amount_anticipo ?? 0,

                "description"     => $request->description,

                // ── Módulo Amortizaciones (Fase 8) ────────────────────
                // credit_type='libre' queda fuera de alcance de esta fase
                // (validarConfiguracionCredito() ya rechazó cualquier otro
                // valor si condicion_pago='credito') — este formulario solo
                // llega acá con 'cuotas_fijas' o null.
                "condicion_pago"  => $condicion_pago,
                "credit_type"     => $condicion_pago === 'credito' ? $request->credit_type : null,
                "aplica_mora"     => $condicion_pago === 'credito' && $request->boolean('aplica_mora'),
                "tasa_mora"       => $condicion_pago === 'credito' && $request->boolean('aplica_mora') ? $request->tasa_mora : null,
                "tipo_mora"       => $condicion_pago === 'credito' && $request->boolean('aplica_mora') ? $request->tipo_mora : null,
                // saldo_pendiente = $request->debt tal cual: ya viene neto de
                // adelantos y pagos iniciales (register.vue, Math.max(0,
                // totalConAdelanto - total_payments)) — mismo criterio que el
                // resto de store(), no se recalcula acá.
                //
                // Sin condicionar a condicion_pago==='credito': Cuentas por
                // Cobrar (CreditSummaryCalculator) filtra por
                // saldo_pendiente > 0 SIN exigir condicion_pago='credito' —
                // así incluye deuda informal contado (ver comentario en ese
                // archivo). Antes esto quedaba hardcodeado a 0 para toda
                // venta contado, así que una venta contado con pago inicial
                // parcial (ej. #37, total 700, pago 300) nunca aparecía en
                // Cuentas por Cobrar pese a tener debt=400 real — bug real
                // reportado 2026-07-21.
                "saldo_pendiente" => $request->debt,
            ]);

            // ── Reservar correlativo de inmediato para documentos no-fiscales ──
            // Nota de venta (y cualquier otro tipo interno futuro) nunca pasa
            // por enviarSunat() — no hay un paso de "envío" posterior donde
            // reservar el correlativo, así que se reserva aquí mismo, apenas
            // la cabecera existe. n_operacion se deja sin tocar a propósito:
            // ese campo es exclusivamente del envío SUNAT real (ver
            // FacturacionElectronicaController::enviarSunat()).
            if (!$serieResuelta['tipo']->es_documento_sunat) {
                $correlativo_nv = $this->serieComprobanteService->reservarCorrelativo($serieResuelta['serie']);
                $venta->update(['correlativo' => $correlativo_nv]);
            }

            // ── Aplicar adelanto(s) del cliente (si los hay) ───────────
            // La suma ya se validó contra el total en
            // validarAdelantosPayload(); AdvanceApplicationService valida
            // lo que requiere BD (pertenencia/moneda/n_operacion/saldo bajo
            // lockForUpdate()) — extraído del bloque que vivía acá para
            // reusarlo también desde ReservaFacturacionController::store()
            // (Tier 0, conexión Adelantos↔Reservas).
            $total_aplicado_adelantos = $this->advanceApplicationService->aplicar($venta, $aplicaciones_adelanto);

            // Total/mto_imp_venta netos del anticipo — solo el total a pagar
            // se reduce, no mto_oper_gravadas/igv/valor_venta (esos deben
            // reflejar el valor íntegro del bien/servicio, según el
            // lineamiento SUNAT de pagos anticipados). enviarSunat()
            // recalcula esto de forma independiente desde sale_details al
            // momento de emitir — esta actualización es solo para que la
            // venta muestre el saldo correcto antes de enviarse.
            if ($total_aplicado_adelantos > 0) {
                $venta->update([
                    "total"         => round($venta->total - $total_aplicado_adelantos, 2),
                    "mto_imp_venta" => round(($venta->mto_imp_venta ?: $venta->total) - $total_aplicado_adelantos, 2),
                ]);
            }

            // ── Persistir cronograma de cuotas (Módulo Amortizaciones — Fase 8) ──
            // validarConfiguracionCredito() ya validó forma + suma exacta en
            // centavos contra saldo_pendiente antes de abrir esta transacción
            // — acá solo se persiste, mismo shape que
            // CreditInstallmentController::store() (estado inicial 'pendiente').
            if ($condicion_pago === 'credito') {
                foreach ($cronograma_credito as $cuota) {
                    Installment::create([
                        'sale_id'           => $venta->id,
                        'numero_cuota'      => $cuota['numero_cuota'],
                        'monto_programado'  => $cuota['monto_programado'],
                        'fecha_vencimiento' => $cuota['fecha_vencimiento'],
                        'estado'            => 'pendiente',
                    ]);
                }
            }

            // ── Guardar detalle de productos ──────────────────────────
            foreach ($detalles_venta as $detalle) {
                $campos_detalle = [
                    "sale_id"              => $venta->id,
                    "product_id"           => $detalle["product"]["id"],
                    "product_categorie_id" => $detalle["product"]["categorie_id"],
                    "unidad_medida"        => $detalle["unidad_medida"],

                    // Cantidades y precios
                    "quantity"             => $detalle["quantity"],
                    "price_base"           => $detalle["price_base"],    // precio sin IGV
                    "price_final"          => $detalle["price_final"],   // precio con IGV

                    // Descuento de la línea
                    "discount"             => $detalle["discount"],

                    // Totales de la línea
                    // subtotal = base neta (con descuento aplicado, sin IGV)
                    "subtotal"             => $detalle["subtotal"],

                    // Campos requeridos por Greenter en cada SaleDetail
                    // mto_valor_venta = precio_base × cantidad (ANTES del descuento)
                    "mto_valor_venta"      => $detalle["mto_valor_venta"],
                    // mto_base_igv = base neta sobre la que se calcula el IGV
                    "mto_base_igv"         => $detalle["mto_base_igv"],
                    // porcentaje_igv: 18 (gravado), 4 (IVAP), 0 (exonerado/inafecto)
                    "porcentaje_igv"       => $detalle["porcentaje_igv"],

                    // Impuestos de la línea
                    "igv"                  => $detalle["igv"],
                    // tip_afe_igv: '10'=gravado, '17'=IVAP, '20'=exonerado, '30'=inafecto, '40'=exportación
                    // IMPORTANTE: guardar siempre como string para consistencia con Greenter
                    "tip_afe_igv"          => (string) $detalle["tip_afe_igv"],

                    // ISC (Impuesto Selectivo al Consumo)
                    "percentage_isc"       => $detalle["percentage_isc"] ?? 0,
                    "isc"                  => $detalle["isc"] ?? 0,
                    // Régimen ISC: '01'=Al valor, '02'=Específico, '03'=Al valor/PVP
                    "tipo_isc"             => $detalle["tipo_isc"] ?? '01',
                    // Monto fijo por unidad (solo régimen '02')
                    "monto_isc_fijo"       => $detalle["monto_isc_fijo"] ?? 0,

                    // ICBPER (bolsa plástica)
                    "per_icbper"           => $detalle["per_icbper"] ?? 0,   // monto por unidad (0.50)
                    "icbper"               => $detalle["icbper"] ?? 0,       // total = qty × 0.50

                    // Total de impuestos de la línea = IGV + ISC + ICBPER
                    "total_impuestos"      => $detalle["total_impuestos"] ?? 0,
                ];

                $campos_detalle = $this->sanitizeIscForDetail($campos_detalle, $detalle["product"]["id"]);

                SaleDetail::create($campos_detalle);
            }

            // ── Guardar pagos ─────────────────────────────────────────
            foreach ($pagos_venta as $pago) {
                SalePayment::create([
                    "sale_id"        => $venta->id,
                    "method_payment" => $pago["method_payment"],
                    "amount"         => $pago["amount"],
                    "date_payment"   => $pago["date_payment"],
                ]);
            }

            // ── Módulo Caja — Fase 3: cash_movement por cada pago inmediato ──
            // $sesionCaja es null cuando la venta es 100% a crédito sin pago
            // inicial (regla #3) — nada que generar en ese caso.
            if ($sesionCaja) {
                $this->crearCashMovementsPorPagos($pagos_venta, $sesionCaja, $venta, auth('api')->user()->id);
            }

            // ── Descontar stock de cada producto ─────────────────────
            foreach ($detalles_venta as $detalle) {
                $producto = Product::find($detalle["product"]["id"]);
                // decrement es atómico — evita condiciones de carrera si hay concurrencia
                $producto->decrement('stock', $detalle["quantity"]);
            }

            DB::commit();
        } catch (HttpException $error) {
            // Errores de validación (422) lanzados arriba (regímenes
            // especiales, adelantos) — no enmascarar como 500.
            DB::rollBack();
            throw $error;
        } catch (\Throwable $error) {
            DB::rollBack();
            throw new HttpException(500, $error->getMessage());
        }

        return response()->json([
            "code"    => 200,
            "message" => "Venta creada exitosamente",
            "sale_id" => $venta->id,
        ]);
    }

    // ── Ver detalle de una venta ─────────────────────────────────────
    public function show(string $id)
    {
        $venta = Sale::with("notes")->find($id);

        return response()->json([
            "sale" => SaleResource::make($venta),
            "code" => 200,
           
        ]);
    }


  
public function update(Request $request, string $id)
{
    $venta = Sale::findOrFail($id);

    // ── Protección: no editar ventas ya emitidas a SUNAT ─────────
    if ($venta->xml || $venta->cdr) {
        return response()->json([
            "code"    => 405,
            "message" => "No se puede editar una venta ya facturada electrónicamente. " .
                         "Si hay un error, emite una Nota de Crédito.",
        ]);
    }

    // ── Blindaje venta a crédito (Módulo Amortizaciones — Fase 8) ──
    // update() NO crea ni edita cronogramas (eso sigue siendo exclusivo
    // de CreditInstallmentController, Fase 3) — solo evita huérfanar un
    // cronograma/pagos ya reales si alguien intenta destildar "Crédito"
    // desde este formulario. Chequeo ANTES de abrir la transacción, mismo
    // criterio que el resto de los validadores de este controlador.
    if ((int) $venta->type_payment === 2 && (int) $request->type_payment !== 2) {
        $tieneSeguimientoReal = $venta->installments()->exists() || $venta->paymentApplications()->exists();
        if ($tieneSeguimientoReal) {
            throw new HttpException(
                422,
                "La venta #{$venta->id} ya tiene cronograma de cuotas y/o pagos aplicados del " .
                "módulo de amortizaciones — no se puede cambiar a 'contado' sin antes anular ese " .
                "seguimiento (ver CreditInstallmentController/CreditPaymentController)."
            );
        }
    }

    // ── Blindaje: venta con adelanto aplicado, sin importar type_payment ──
    // Antes este chequeo solo corría dentro del bloque "sigue siendo
    // crédito" de abajo — una venta CONTADO con un adelanto ya aplicado
    // (AdvanceApplication real, plata ya comprometida) quedaba libre para
    // cambiar cliente/moneda/productos/total sin ningún candado. Corre
    // siempre, antes de abrir transacción, mismo criterio que el resto de
    // los guards de este método (hallazgo de auditoría del módulo,
    // 2026-08-21).
    if ($venta->advance_applications()->exists()) {
        throw new HttpException(
            422,
            "La venta #{$venta->id} ya tiene un adelanto aplicado — no se puede editar cliente, " .
            "moneda, productos ni el pago inicial desde aquí. Para corregir, anula la aplicación " .
            "primero desde el módulo de Adelantos."
        );
    }

    // ── Blindaje edición de venta a crédito con seguimiento real ──
    // Si la venta sigue siendo crédito, solo se permite editar
    // productos/pago inicial mientras NO haya plata ya comprometida por
    // fuera de esta pantalla (cuota cobrada vía Amortizaciones). El
    // adelanto ya se descartó arriba, incondicional. El pago inicial
    // (sale_payments) SÍ se sincroniza más abajo — es un error de tipeo
    // típico (monto/método equivocado al crear la venta), corregible
    // libremente si nada de lo anterior existe todavía.
    if ((int) $request->type_payment === 2) {
        $tieneCobrosFormales = $venta->paymentApplications()->where('estado', 'activo')->exists();

        if ($tieneCobrosFormales) {
            throw new HttpException(
                422,
                "La venta #{$venta->id} ya tiene cuotas cobradas — no se puede editar productos " .
                "ni el pago inicial desde aquí. Para corregir un cobro real, anúlalo primero " .
                "desde Cuentas por Cobrar."
            );
        }

        // Cualquier edición de una venta a crédito (productos, precio o el
        // pago inicial) invalida el cronograma por igual — ambos alimentan
        // la misma fórmula de "monto a financiar" — así que se exige un
        // cronograma nuevo y válido en cada guardado, sin importar qué
        // cambió puntualmente.
        $this->validarConfiguracionCredito($request);
    }

    // condicion_pago se DERIVA de type_payment, mismo criterio que store()
    // (§9 del plan) — no se acepta como campo separado del payload.
    $condicion_pago = (int) $request->type_payment === 2 ? 'credito' : 'contado';

    $this->validarRegimenEspecial($request);
    $this->validarCodigoDetraccion($request);
    $this->validarPagosPayload($request);
    $serieResuelta = $this->resolverSerieComprobanteUpdate($venta, $request);

    $detalles_nuevos = $request->sale_details ?? [];
    $pagos_nuevos    = $request->payments ?? [];

    // Módulo Caja — Fase 3: toda la validación/decisión (incluido el
    // permiso de supervisor sobre sesión cerrada) corre acá, antes de abrir
    // la transacción — ver comentario de prepararSincronizacionCaja().
    $planCaja = $this->prepararSincronizacionCaja($venta, $pagos_nuevos);

    try {
        DB::beginTransaction();

        // ── 1. Actualizar cabecera de la venta ────────────────────
        $venta->update([
            // Identificación — serie/tipo_comprobante_codigo/serie_comprobante_id
            // vienen de resolverSerieComprobanteUpdate(): si la venta ya tiene
            // un correlativo reservado, mantiene los valores actuales tal
            // cual (esa validación ya bloqueó con 422 si el payload intentaba
            // cambiarlos); si no, resuelve la serie nueva igual que store().
            "serie"                   => $serieResuelta['serie']?->serie ?? $venta->serie,
            "tipo_comprobante_codigo" => $serieResuelta['tipo']?->codigo ?? $venta->tipo_comprobante_codigo,
            "serie_comprobante_id"    => $serieResuelta['serie']?->id ?? $venta->serie_comprobante_id,
            "n_transaction"  => $request->n_transaction,

            // Cliente
            "client_id"            => $request->client_id,
            "type_client"          => $request->type_client,
            "cod_tipo_doc_cliente" => $request->cod_tipo_doc_cliente,

            // Configuración de la operación
            "currency"       => $request->currency,
            "is_exportacion" => $request->is_exportacion,
            "destino"        => $request->destino,
            "state_sale"     => $request->state_sale,
            "type_payment"   => $request->type_payment,
            // condicion_pago derivado arriba, antes de la transacción —
            // update() no crea/edita cronograma (Fase 8, alcance cerrado).
            "condicion_pago" => $condicion_pago,

            // Totales
            "subtotal"        => $request->subtotal,
            "igv"             => $request->igv,
            "total"           => $request->total,
            "discount"        => $request->discount,
            "discount_global" => $request->discount_global,

            // Totales por tipo de operación (para Greenter)
            "mto_oper_gravadas"    => $request->mto_oper_gravadas ?? 0,
            "mto_oper_exoneradas"  => $request->mto_oper_exoneradas ?? 0,
            "mto_oper_inafectas"   => $request->mto_oper_inafectas ?? 0,
            "mto_oper_exportacion" => $request->mto_oper_exportacion ?? 0,
            "mto_oper_gratuitas"   => $request->mto_oper_gratuitas ?? 0,

            // Impuestos desagregados
            "isc_total"       => $request->isc_total ?? 0,
            "icbper_total"    => $request->icbper_total ?? 0,
            "ivap_total"      => $request->ivap_total ?? 0,
            "total_impuestos" => $request->total_impuestos ?? 0,
            "valor_venta"     => $request->valor_venta ?? 0,
            "mto_imp_venta"   => $request->mto_imp_venta ?? 0,
            "redondeo"        => $request->redondeo ?? 0,

            // Regímenes especiales
            "retencion_igv"          => $request->retencion_igv,
            "monto_retencion"        => $request->monto_retencion ?? 0,
            "codigo_detraccion"      => $request->codigo_detraccion,
            "porcentaje_detraccion"  => $request->porcentaje_detraccion ?? 0,
            "monto_detraccion"       => $request->monto_detraccion ?? 0,
            "porcentaje_percepcion"  => $request->porcentaje_percepcion ?? 0,
            "monto_percepcion"       => $request->monto_percepcion ?? 0,

            // Estado de pago
            "state_payment" => $request->state_payment,
            "debt"          => $request->debt,
            "paid_out"      => $request->paid_out,
            // Cuentas por Cobrar filtra por saldo_pendiente > 0 sin exigir
            // condicion_pago='credito' (deuda informal contado incluida,
            // ver CreditSummaryCalculator) — antes update() solo lo
            // sincronizaba para ventas a crédito (bloque más abajo), así
            // que editar una venta contado con saldo (o su pago inicial)
            // nunca actualizaba saldo_pendiente. Mismo bug real que en
            // store(), 2026-07-21.
            "saldo_pendiente" => $request->debt,

            "description"   => $request->description,
        ]);

        // ── Reservar correlativo si el tipo cambió A uno no-fiscal ────
        // Mismo criterio que store(): nota de venta nunca pasa por
        // enviarSunat(), así que reserva su correlativo de inmediato. Solo
        // aplica cuando resolverSerieComprobanteUpdate() SÍ resolvió una
        // serie nueva ('cambia' => true) — si la venta ya tenía correlativo,
        // ni siquiera se llega aquí con un tipo distinto (bloqueado con 422
        // más arriba).
        if ($serieResuelta['cambia'] && !$serieResuelta['tipo']->es_documento_sunat) {
            $correlativo_nv = $this->serieComprobanteService->reservarCorrelativo($serieResuelta['serie']);
            $venta->update(['correlativo' => $correlativo_nv]);
        }

        // ── 2. Sincronizar detalles ───────────────────────────────
        // Estrategia: comparar los detalles que vienen del frontend
        // con los que ya están en la BD.
        //
        // Casos posibles:
        //   a) Ítem con id → ya existe en BD → actualizar
        //   b) Ítem sin id → es nuevo → crear
        //   c) Ítem en BD que no vino → fue eliminado → borrar y devolver stock

        // IDs que vienen del frontend (los que deben quedar)
        $ids_nuevos = collect($detalles_nuevos)
            ->filter(fn($d) => !empty($d['id']))
            ->pluck('id')
            ->toArray();

        // Obtener detalles actuales en BD
        $detalles_actuales = SaleDetail::where('sale_id', $venta->id)->get();

        // Eliminar los que ya no están y devolver su stock
        foreach ($detalles_actuales as $detalle_actual) {
            if (!in_array($detalle_actual->id, $ids_nuevos)) {
                // Devolver stock al producto
                $producto = Product::find($detalle_actual->product_id);
                if ($producto) {
                    $producto->increment('stock', $detalle_actual->quantity);
                }
                $detalle_actual->delete();
            }
        }

        // Crear o actualizar cada detalle que viene del frontend
        foreach ($detalles_nuevos as $detalle) {
            $campos_detalle = [
                "sale_id"              => $venta->id,
                "product_id"           => $detalle["product"]["id"],
                "product_categorie_id" => $detalle["product"]["categorie_id"],
                "unidad_medida"        => $detalle["unidad_medida"],
                "quantity"             => $detalle["quantity"],
                "price_base"           => $detalle["price_base"],
                "price_final"          => $detalle["price_final"],
                "discount"             => $detalle["discount"],
                "subtotal"             => $detalle["subtotal"],

                // Campos requeridos por Greenter
                "mto_valor_venta"      => $detalle["mto_valor_venta"],
                "mto_base_igv"         => $detalle["mto_base_igv"],
                "porcentaje_igv"       => $detalle["porcentaje_igv"],
                "total_impuestos"      => $detalle["total_impuestos"],

                // IGV
                "igv"                  => $detalle["igv"],
                // tip_afe_igv siempre como string
                "tip_afe_igv"          => (string) $detalle["tip_afe_igv"],

                // ISC
                "percentage_isc"       => $detalle["percentage_isc"] ?? 0,
                "isc"                  => $detalle["isc"] ?? 0,
                "tipo_isc"             => $detalle["tipo_isc"] ?? '01',
                "monto_isc_fijo"       => $detalle["monto_isc_fijo"] ?? 0,

                // ICBPER
                "per_icbper"           => $detalle["per_icbper"] ?? 0,
                "icbper"               => $detalle["icbper"] ?? 0,
            ];

            $campos_detalle = $this->sanitizeIscForDetail($campos_detalle, $detalle["product"]["id"]);

            if (!empty($detalle['id'])) {
                // Ítem existente → actualizar
                $detalle_bd = SaleDetail::find($detalle['id']);
                if ($detalle_bd) {
                    // Si cambió la cantidad, ajustar el stock
                    $diferencia_qty = $detalle["quantity"] - $detalle_bd->quantity;
                    if ($diferencia_qty !== 0) {
                        $producto = Product::find($detalle["product"]["id"]);
                        if ($producto) {
                            if ($diferencia_qty > 0) {
                                // Pide más → descontar stock
                                $producto->decrement('stock', $diferencia_qty);
                            } else {
                                // Pide menos → devolver stock
                                $producto->increment('stock', abs($diferencia_qty));
                            }
                        }
                    }
                    $detalle_bd->update($campos_detalle);
                }
            } else {
                // Ítem nuevo → crear y descontar stock
                SaleDetail::create($campos_detalle);
                $producto = Product::find($detalle["product"]["id"]);
                if ($producto) {
                    $producto->decrement('stock', $detalle["quantity"]);
                }
            }
        }

        // ── 3. Sincronizar pago inicial (sale_payments) ───────────
        // Antes se ignoraba $request->payments por completo (bug real,
        // encontrado al intentar corregir un pago inicial mal tipeado en
        // una venta a crédito) — reemplazo total, más simple y seguro que
        // un diff por id ya que sale_payments no tiene relaciones propias
        // que dependan de conservar el mismo id entre ediciones.
        SalePayment::where('sale_id', $venta->id)->delete();
        foreach ($pagos_nuevos as $pago) {
            SalePayment::create([
                "sale_id"        => $venta->id,
                "method_payment" => $pago['method_payment'],
                "amount"         => $pago['amount'],
                "date_payment"   => $pago['date_payment'] ?? now(),
            ]);
        }

        // Módulo Caja — Fase 3: ejecuta el plan ya decidido arriba (antes de
        // la transacción). No vuelve a validar ni a lanzar HttpException.
        $this->aplicarSincronizacionCaja($planCaja, $venta);

        // ── 4. Regenerar cronograma (venta a crédito, ya validada arriba) ──
        // Sin SoftDeletes en installments a propósito — las cuotas viejas
        // quedan para siempre con estado='anulada', no hay conflicto de
        // numero_cuota con las nuevas (índice no único, y el guard de
        // "cronograma activo" en CreditInstallmentController::store() solo
        // mira estado != 'anulada'). No se reutiliza el endpoint HTTP
        // installments/{id}/anular: acá ya se garantizó arriba que no hay
        // ningún pago aplicado, así que el bulk update directo es más
        // simple y correcto que su lógica de redistribución/condonación
        // (pensada para cuotas parcialmente pagadas).
        if ((int) $request->type_payment === 2) {
            Installment::where('sale_id', $venta->id)
                ->where('estado', '!=', 'anulada')
                ->update([
                    "estado"           => 'anulada',
                    "motivo_anulacion" => 'Cronograma regenerado por edición de la venta antes de enviar a SUNAT.',
                    "anulado_por"      => auth('api')->user()->id,
                    "anulado_en"       => now(),
                ]);

            foreach ($request->cronograma as $cuota) {
                Installment::create([
                    "sale_id"           => $venta->id,
                    "numero_cuota"      => $cuota['numero_cuota'],
                    "monto_programado"  => $cuota['monto_programado'],
                    "fecha_vencimiento" => $cuota['fecha_vencimiento'],
                    "estado"            => 'pendiente',
                ]);
            }

            // saldo_pendiente ya se sincronizó arriba, en el update() general
            // de cabecera — acá solo lo específico de crédito.
            $venta->update([
                "credit_type"     => $request->credit_type,
                "aplica_mora"     => $request->boolean('aplica_mora'),
                "tasa_mora"       => $request->boolean('aplica_mora') ? $request->tasa_mora : null,
                "tipo_mora"       => $request->boolean('aplica_mora') ? $request->tipo_mora : null,
            ]);
        }

        DB::commit();

    } catch (\Throwable $error) {
        DB::rollBack();
        throw new HttpException(500, $error->getMessage());
    }

    return response()->json([
        "code"    => 200,
        "message" => "Venta actualizada correctamente",
    ]);
}
    

    // ── Eliminar venta ───────────────────────────────────────────────
    public function destroy(string $id)
    {
        $venta = Sale::find($id);

        // Protección: no se puede eliminar una venta ya emitida
        if ($venta->xml || $venta->cdr) {
            return response()->json([
                "code"    => 405,
                "message" => "No se puede eliminar una venta ya facturada electrónicamente.",
            ]);
        }

        // Protección: no se puede eliminar una venta con adelanto aplicado.
        // $venta->delete() es un soft delete (Sale usa SoftDeletes) — nunca
        // dispara el onDelete('restrict') que advance_applications declara
        // sobre sales.id, así que sin este guard el adelanto quedaba con
        // applied_amount consumido por una venta que desapareció del
        // listado, sin ningún rastro (hallazgo de auditoría del módulo,
        // 2026-08-21). No existe todavía un flujo de "anular aplicación de
        // adelanto" — hasta que exista, la única salida es no permitir
        // borrar la venta.
        if ($venta->advance_applications()->exists()) {
            return response()->json([
                "code"    => 405,
                "message" => "No se puede eliminar una venta con un adelanto aplicado. Anula la " .
                             "aplicación del adelanto primero desde el módulo de Adelantos.",
            ]);
        }

        DB::transaction(function () use ($venta) {
            \App\Models\AgenciaViajes\ReservaVenta::where('sale_id', $venta->id)->delete();
            $venta->delete();
        });

        return response()->json([
            "code"    => 200,
            "message" => "Venta eliminada correctamente",
        ]);
    }
}
