<?php

namespace App\Http\Controllers\CommercialQuote;

use App\Http\Controllers\Controller;
use App\Models\Client\Client;
use App\Models\CommercialQuote\CommercialQuote;
use App\Models\CommercialQuote\CommercialQuoteAnticipo;
use App\Models\CommercialQuote\CommercialQuoteItem;
use App\Models\Company;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Services\StorageUrl;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Módulo "Cotizaciones Comerciales" — presupuestos para negocios retail/
// ecommerce que controlan stock, SIN ningún efecto fiscal ni de stock.
// Reemplaza a sales.state_sale (retirado, ver
// database/migrations/tenant/core/2026_08_25_100000_drop_state_sale_from_sales_table.php),
// que hacía exactamente lo contrario de lo que un presupuesto comercial
// debe hacer: descontaba stock, exigía caja abierta y podía dejar un
// cronograma de crédito real. Sin relación con la entidad Cotizacion del
// vertical Agencia de Viajes (tabla `cotizaciones`, viajes).
//
// paraVenta() NUNCA crea el Sale — toda lógica fiscal/stock/tributaria
// sigue viviendo exclusivamente en SaleController/register.vue. Este
// controller solo entrega los datos base (cliente, productos, precio
// congelado como referencia) para que el vendedor complete la venta real
// a través del flujo normal.
class CommercialQuoteController extends Controller
{
    // Transiciones manuales válidas de `status` vía update(). 'aceptada'
    // representa que el cliente aprobó el presupuesto — independiente de
    // si ya se convirtió en venta (eso lo marca únicamente
    // marcarConvertida(), nunca este mapa).
    private const TRANSICIONES = [
        'borrador'  => ['enviada', 'anulada'],
        'enviada'   => ['aceptada', 'rechazada', 'vencida', 'anulada'],
        'rechazada' => ['anulada'],
        'vencida'   => ['anulada'],
        'aceptada'  => ['anulada'],
        'anulada'   => [],
    ];

    // ── GET /commercial-quotes ────────────────────────────────────────
    public function index(Request $request)
    {
        $query = CommercialQuote::with('client:id,full_name,n_document')
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $buscar = $request->search;
            $query->where(function ($q) use ($buscar) {
                $q->where('code', 'ILIKE', "%{$buscar}%")
                    ->orWhere('client_name_free', 'ILIKE', "%{$buscar}%")
                    ->orWhereHas('client', function ($sub) use ($buscar) {
                        $sub->whereRaw(
                            "(COALESCE(full_name,'') || ' ' || COALESCE(n_document,'')) ILIKE ?",
                            ["%{$buscar}%"]
                        );
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59',
            ]);
        }

        $cotizaciones = $query->paginate(25);

        return response()->json([
            'total'    => $cotizaciones->total(),
            'paginate' => 25,
            // through() mantiene la estructura completa del paginador
            // (current_page/links/etc.) — el frontend espera un array
            // plano, mismo shape que SaleCollection::make() en SaleController.
            'commercial_quotes' => $cotizaciones->getCollection()
                ->map(fn (CommercialQuote $q) => $this->formatearResumen($q))
                ->values(),
        ]);
    }

    // ── GET /commercial-quotes/{id} ───────────────────────────────────
    public function show(string $id)
    {
        $cotizacion = CommercialQuote::with(['items.product', 'client', 'registradoPor', 'convertedSale:id,n_operacion,serie,correlativo', 'anticipos.advance.sale:id,xml'])
            ->findOrFail($id);

        return response()->json(['commercial_quote' => $this->formatearDetalle($cotizacion)]);
    }

    // ── POST /commercial-quotes ───────────────────────────────────────
    public function store(Request $request)
    {
        $this->validarCliente($request);
        $items = $this->validarYArmarItems($request->items ?? []);

        [$subtotal, $total] = $this->calcularTotales($items, (float) ($request->discount_global ?? 0));

        $cotizacion = DB::transaction(function () use ($request, $items, $subtotal, $total) {
            $nueva = CommercialQuote::create([
                'code'              => CommercialQuote::siguienteCodigo(),
                'client_id'         => $request->client_id,
                'client_name_free'  => $request->client_id ? null : $request->client_name_free,
                'client_phone_free' => $request->client_id ? null : $request->client_phone_free,
                'user_id'           => auth('api')->user()->id,
                'currency'          => $request->currency ?? 'PEN',
                'status'            => 'borrador',
                'subtotal'          => $subtotal,
                'discount_global'   => (float) ($request->discount_global ?? 0),
                'total'             => $total,
                'valid_until'       => $request->valid_until,
                'observacion'       => $request->observacion,
            ]);

            foreach ($items as $item) {
                CommercialQuoteItem::create($item + ['commercial_quote_id' => $nueva->id]);
            }

            return $nueva;
        });

        return response()->json([
            'code'                 => 200,
            'message'              => 'Cotización comercial creada exitosamente',
            'commercial_quote_id'  => $cotizacion->id,
        ]);
    }

    // ── PUT /commercial-quotes/{id} ───────────────────────────────────
    public function update(Request $request, string $id)
    {
        $cotizacion = CommercialQuote::findOrFail($id);

        if ($cotizacion->converted_sale_id) {
            throw new HttpException(422, "Esta cotización ya fue convertida en la venta #{$cotizacion->converted_sale_id} — no se puede editar.");
        }

        $nuevoStatus = $request->status ?? $cotizacion->status;

        if ($nuevoStatus !== $cotizacion->status) {
            $permitidas = self::TRANSICIONES[$cotizacion->status] ?? [];
            if (!in_array($nuevoStatus, $permitidas, true)) {
                throw new HttpException(422, "No se puede cambiar el estado de '{$cotizacion->status}' a '{$nuevoStatus}'.");
            }
        }

        // Los datos de la cotización (cliente/items/montos) solo se pueden
        // editar mientras sigue en borrador/enviada — un cambio de estado
        // puro (ej. marcar rechazada) es válido en cualquier estado de
        // origen permitido por el mapa de arriba, sin tocar el contenido.
        $editaContenido = $request->has('items');
        if ($editaContenido && !in_array($cotizacion->status, ['borrador', 'enviada'], true)) {
            throw new HttpException(422, "Solo se puede editar el contenido de una cotización en borrador o enviada.");
        }

        if ($editaContenido) {
            $this->validarCliente($request);
            $items = $this->validarYArmarItems($request->items ?? []);
            [$subtotal, $total] = $this->calcularTotales($items, (float) ($request->discount_global ?? 0));
        }

        $items = $items ?? [];
        $subtotal = $subtotal ?? null;
        $total = $total ?? null;

        DB::transaction(function () use ($request, $cotizacion, $nuevoStatus, $editaContenido, $items, $subtotal, $total) {
            $datos = ['status' => $nuevoStatus];

            if ($editaContenido) {
                $datos = array_merge($datos, [
                    'client_id'         => $request->client_id,
                    'client_name_free'  => $request->client_id ? null : $request->client_name_free,
                    'client_phone_free' => $request->client_id ? null : $request->client_phone_free,
                    'currency'          => $request->currency ?? $cotizacion->currency,
                    'subtotal'          => $subtotal,
                    'discount_global'   => (float) ($request->discount_global ?? 0),
                    'total'             => $total,
                    'valid_until'       => $request->valid_until,
                    'observacion'       => $request->observacion,
                ]);
            }

            $cotizacion->update($datos);

            if ($editaContenido) {
                $cotizacion->items()->delete();
                foreach ($items as $item) {
                    CommercialQuoteItem::create($item + ['commercial_quote_id' => $cotizacion->id]);
                }
            }
        });

        return response()->json(['code' => 200, 'message' => 'Cotización actualizada exitosamente']);
    }

    // ── GET /commercial-quotes/{id}/for-sale ──────────────────────────
    // Solo lectura — NO crea el Sale ni marca nada. El vendedor completa
    // tributación/stock/comprobante/cobro a través del flujo normal de
    // Sale (register.vue?from_quote=<id>).
    public function paraVenta(string $id)
    {
        $cotizacion = CommercialQuote::with(['items.product', 'client'])->findOrFail($id);

        if (in_array($cotizacion->status, ['anulada', 'rechazada', 'vencida'], true)) {
            throw new HttpException(422, "No se puede convertir una cotización '{$cotizacion->status}'.");
        }

        if ($cotizacion->converted_sale_id) {
            throw new HttpException(422, "Esta cotización ya fue convertida en la venta #{$cotizacion->converted_sale_id}.");
        }

        return response()->json([
            'client' => $cotizacion->client ? [
                'id'          => $cotizacion->client->id,
                'full_name'   => $cotizacion->client->full_name,
                'n_document'  => $cotizacion->client->n_document,
                'type_document' => $cotizacion->client->type_document,
            ] : null,
            'client_name_free'  => $cotizacion->client_name_free,
            'client_phone_free' => $cotizacion->client_phone_free,
            'currency' => $cotizacion->currency,
            'observacion' => $cotizacion->observacion,
            'items' => $cotizacion->items->map(fn (CommercialQuoteItem $item) => [
                'product_id'  => $item->product_id,
                'product'     => $item->product,
                'description' => $item->description,
                'quantity'    => (float) $item->quantity,
                'unit_price'  => (float) $item->unit_price,
            ]),
        ]);
    }

    // ── POST /commercial-quotes/{id}/mark-converted ───────────────────
    public function marcarConvertida(Request $request, string $id)
    {
        $request->validate(['sale_id' => 'required|integer']);

        $cotizacion = CommercialQuote::findOrFail($id);

        if ($cotizacion->converted_sale_id) {
            throw new HttpException(422, "Esta cotización ya fue convertida en la venta #{$cotizacion->converted_sale_id}.");
        }

        Sale::findOrFail($request->sale_id);

        // Asignación directa + save(), NO update() masivo: converted_sale_id/
        // converted_at están deliberadamente fuera de $fillable (para que
        // store()/update() genéricos nunca los toquen) — un update([...]) acá
        // los descartaría en silencio por el mismo motivo, sin excepción ni
        // log (mismo bug ya documentado en este proyecto para
        // Company::$fillable).
        $cotizacion->converted_sale_id = $request->sale_id;
        $cotizacion->converted_at = now();
        $cotizacion->status = 'aceptada';
        $cotizacion->save();

        return response()->json(['code' => 200, 'message' => 'Cotización marcada como convertida']);
    }

    // ── GET /commercial-quotes-pdf-url/{id} ───────────────────────────
    public function pdfSignedUrl(string $id)
    {
        CommercialQuote::findOrFail($id);

        $url = URL::temporarySignedRoute('commercial-quotes.pdf', now()->addMinutes(10), ['id' => $id]);

        return response()->json(['url' => $url]);
    }

    // ── GET /commercial-quotes-pdf/{id} ───────────────────────────────
    // Solo accesible vía URL firmada (middleware 'signed'). Sin QR fiscal
    // — no es un comprobante SUNAT.
    public function pdf(string $id)
    {
        $cotizacion = CommercialQuote::with(['items.product', 'client', 'registradoPor'])->find($id);

        if (!$cotizacion) {
            return abort(404);
        }

        $empresa = Company::first();
        $logo = StorageUrl::resolveParaPdf($empresa?->logo_horizontal);

        $pdf = Pdf::loadView('pdf.cotizacion_comercial_a4', ['cotizacion' => $cotizacion, 'empresa' => $empresa, 'logo' => $logo]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream($cotizacion->code . '.pdf');
    }

    // ── Helpers privados ───────────────────────────────────────────────

    private function validarCliente(Request $request): void
    {
        if (!$request->client_id && !$request->filled('client_name_free')) {
            throw new HttpException(422, 'Indica un cliente registrado (client_id) o al menos un nombre (client_name_free).');
        }

        if ($request->client_id) {
            Client::findOrFail($request->client_id);
        }
    }

    // Valida forma + calcula subtotal de cada ítem. No persiste nada —
    // devuelve arrays listos para CommercialQuoteItem::create().
    private function validarYArmarItems(array $items): array
    {
        if (empty($items)) {
            throw new HttpException(422, 'Agrega al menos un ítem a la cotización.');
        }

        $armados = [];
        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            $description = $item['description'] ?? null;

            if (!$productId && empty($description)) {
                throw new HttpException(422, 'Cada ítem sin producto requiere una descripción.');
            }

            // Con producto: la descripción se copia del catálogo al momento
            // de agregar (igual que sale_details.descripcion_detalle) salvo
            // que el vendedor la haya sobreescrito a mano.
            if ($productId && empty($description)) {
                $description = Product::findOrFail($productId)->title;
            }

            $cantidad   = (float) ($item['quantity'] ?? 0);
            $precio     = (float) ($item['unit_price'] ?? 0);
            $descuento  = (float) ($item['discount_percent'] ?? 0);

            if ($cantidad <= 0 || $precio < 0) {
                throw new HttpException(422, 'Cantidad y precio unitario deben ser válidos (cantidad > 0, precio >= 0).');
            }

            $bruto    = round($cantidad * $precio, 2);
            $subtotal = round($bruto - ($bruto * $descuento / 100), 2);

            $armados[] = [
                'product_id'        => $productId,
                'description'       => $description,
                'unidad_medida'     => $item['unidad_medida'] ?? null,
                'quantity'          => $cantidad,
                'unit_price'        => $precio,
                'discount_percent'  => $descuento,
                'subtotal'          => $subtotal,
            ];
        }

        return $armados;
    }

    // Calcula subtotal (suma de líneas) y total (neto del descuento
    // global) SIEMPRE en backend — nunca confía en lo que mande el
    // frontend, mismo criterio de blindaje ya aplicado al resto de Sale.
    private function calcularTotales(array $items, float $descuentoGlobal): array
    {
        $subtotal = round(array_sum(array_column($items, 'subtotal')), 2);
        $total    = round(max(0, $subtotal - $descuentoGlobal), 2);

        return [$subtotal, $total];
    }

    private function formatearResumen(CommercialQuote $q): array
    {
        return [
            'id'         => $q->id,
            'code'       => $q->code,
            'client'     => $q->client ? ['id' => $q->client->id, 'full_name' => $q->client->full_name] : null,
            'client_name_free' => $q->client_name_free,
            'status'     => $q->status,
            'total'      => (float) $q->total,
            'currency'   => $q->currency,
            'valid_until' => $q->valid_until?->format('Y-m-d'),
            'converted_sale_id' => $q->converted_sale_id,
            'created_at' => $q->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function formatearDetalle(CommercialQuote $q): array
    {
        return array_merge($this->formatearResumen($q), [
            'client_phone_free' => $q->client_phone_free,
            'discount_global' => (float) $q->discount_global,
            'subtotal' => (float) $q->subtotal,
            'observacion' => $q->observacion,
            'converted_at' => $q->converted_at?->format('Y-m-d H:i:s'),
            'converted_sale' => $q->convertedSale ? [
                'id' => $q->convertedSale->id,
                'n_operacion' => $q->convertedSale->n_operacion,
                'serie' => $q->convertedSale->serie,
                'correlativo' => $q->convertedSale->correlativo,
            ] : null,
            'registrado_por' => $q->registradoPor ? trim($q->registradoPor->name . ' ' . $q->registradoPor->surname) : null,
            'items' => $q->items->map(fn (CommercialQuoteItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product ? ['id' => $item->product->id, 'title' => $item->product->title] : null,
                'description' => $item->description,
                'unidad_medida' => $item->unidad_medida,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount_percent' => (float) $item->discount_percent,
                'subtotal' => (float) $item->subtotal,
            ]),
            'anticipos' => $q->anticipos->map(fn (CommercialQuoteAnticipo $a) => [
                'id' => $a->id,
                'advance_id' => $a->advance_id,
                'monto_asignado' => (float) $a->monto_asignado,
                'disponible' => $a->advance ? $a->advance->availableBalance() : 0,
                'currency' => $a->advance?->currency,
                'payment_method' => $a->advance?->payment_method,
                'fecha_asignacion' => $a->fecha_asignacion?->format('Y-m-d'),
                'sunat_enviado' => $a->advance ? (bool) $a->advance->sale?->xml : false,
            ]),
        ]);
    }
}
