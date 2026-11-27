<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Models\Compras\AplicacionPagoProveedor;
use App\Models\Compras\Compra;
use App\Models\Compras\PagoProveedor;
use App\Models\Proveedor;
use App\Services\Compras\PagoProveedorService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

// T-136 — pagos a proveedores. Las reglas viven en PagoProveedorService. No hay destroy(): un pago
// se anula. Sin Policy de fila: los pagos son del negocio, como las compras.
class PagoProveedorController extends Controller
{
    public function __construct(private PagoProveedorService $service) {}

    public function index(Request $request)
    {
        $query = PagoProveedor::query();
        $this->filtrar($query, $request);

        $perPage = min(100, max(1, (int) $request->get('per_page', 15)));
        $pagos = $query->with(['proveedor', 'cuentaBancaria', 'metodoPago', 'aplicaciones.compra'])
            ->orderByDesc('fecha')->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'total' => $pagos->total(),
            'paginate' => $perPage,
            'pagos' => $pagos->getCollection()->map(fn (PagoProveedor $p) => $this->datos($p))->values(),
        ]);
    }

    public function show(string $id)
    {
        return $this->respuesta(PagoProveedor::findOrFail($id));
    }

    public function store(Request $request)
    {
        $pago = $this->service->registrar($request->all(), auth('api')->user());

        return $this->respuesta($pago, $pago->estado === PagoProveedor::PENDIENTE
            ? 'Pago registrado — el egreso de Caja queda pendiente de aprobación.'
            : 'Pago registrado correctamente');
    }

    public function aplicar(Request $request, string $id)
    {
        $pago = $this->service->aplicar(PagoProveedor::findOrFail($id), (array) $request->input('aplicaciones', []), auth('api')->user());

        return $this->respuesta($pago, 'Pago aplicado');
    }

    public function desaplicar(Request $request, string $id)
    {
        $pago = $this->service->desaplicar(AplicacionPagoProveedor::findOrFail($id), $request->input('motivo'), auth('api')->user());

        return $this->respuesta($pago, 'Pago desaplicado');
    }

    public function anular(Request $request, string $id)
    {
        $pago = $this->service->anular(PagoProveedor::findOrFail($id), $request->input('motivo'), auth('api')->user());

        return $this->respuesta($pago, 'Pago anulado');
    }

    /** Compras de un proveedor a las que todavía se les puede aplicar un pago (T-163 la usa en Caja). */
    public function comprasPendientes(Request $request, string $id)
    {
        $proveedor = Proveedor::findOrFail($id);
        $compras = Compra::vigentes()
            ->where('proveedor_id', $proveedor->id)
            ->where('tipo_comprobante_codigo', '<>', Compra::TIPO_NOTA_CREDITO)
            ->when($request->filled('moneda'), fn (Builder $q) => $q->where('moneda', $request->get('moneda')))
            ->orderBy('fecha_emision')->orderBy('id')
            ->get();

        $saldos = $this->service->saldos($compras->pluck('id')->all());
        $estados = $this->service->estados($compras);

        return response()->json([
            'compras' => $compras
                ->filter(fn (Compra $c) => $saldos[$c->id]['limite']->isPositive())
                ->map(fn (Compra $c) => [
                    'id' => $c->id,
                    'tipo_comprobante_codigo' => $c->tipo_comprobante_codigo,
                    'serie' => $c->serie,
                    'numero' => $c->numero,
                    'fecha_emision' => $c->fecha_emision->toDateString(),
                    'fecha_vencimiento' => $c->fecha_vencimiento?->toDateString(),
                    'moneda' => $c->moneda,
                    'total' => $c->total,
                    'saldo' => $estados[$c->id]['saldo'],
                    'estado_pago' => $estados[$c->id]['estado_pago'],
                    // Lo que se puede aplicar: el saldo menos lo que ya espera aprobación.
                    'maximo' => (string) $saldos[$c->id]['limite']->toScale(2),
                ])->values(),
        ]);
    }

    private function filtrar(Builder $query, Request $request): void
    {
        if ($request->filled('proveedor_id')) {
            $query->where('proveedor_id', (int) $request->get('proveedor_id'));
        }
        // `fecha` es una fecha de negocio, no un instante: se compara directo.
        foreach (['desde' => '>=', 'hasta' => '<='] as $campo => $operador) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->get($campo))) {
                $query->where('fecha', $operador, $request->get($campo));
            }
        }
        if ($request->filled('origen')) {
            $query->where('origen', $request->get('origen'));
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->get('estado'));
        }
        if ($request->boolean('con_saldo')) {
            $query->where('estado', '<>', PagoProveedor::ANULADO)
                ->whereRaw('monto > (select coalesce(sum(a.monto), 0) from aplicaciones_pago_proveedor a '
                    .'where a.pago_proveedor_id = pagos_proveedor.id and a.desaplicada_at is null)');
        }
    }

    private function respuesta(PagoProveedor $pago, ?string $mensaje = null)
    {
        $pago = PagoProveedor::with(['proveedor', 'cuentaBancaria', 'metodoPago', 'aplicaciones.compra', 'usuario', 'anulador'])
            ->findOrFail($pago->id);

        return response()->json(array_filter(['code' => 200, 'message' => $mensaje]) + ['pago' => $this->datos($pago)]);
    }

    private function datos(PagoProveedor $pago): array
    {
        $persona = fn ($usuario) => $usuario ? trim("{$usuario->name} {$usuario->surname}") : null;
        $activas = $pago->aplicaciones->whereNull('desaplicada_at');
        $aplicado = $activas->reduce(fn (BigDecimal $s, AplicacionPagoProveedor $a) => $s->plus($a->monto), BigDecimal::of('0.00'));
        $aCuenta = $pago->estaAnulado() ? BigDecimal::of('0.00') : BigDecimal::of($pago->monto)->minus($aplicado);

        return [
            'id' => $pago->id,
            'proveedor_id' => $pago->proveedor_id,
            'proveedor' => $pago->proveedor?->only(['id', 'razon_social', 'nombre_comercial', 'numero_documento']),
            'fecha' => $pago->fecha->toDateString(),
            'moneda' => $pago->moneda,
            'monto' => $pago->monto,
            'aplicado' => (string) $aplicado->toScale(2),
            'a_cuenta' => (string) $aCuenta->toScale(2),
            'origen' => $pago->origen,
            'estado' => $pago->estado,
            'metodo_pago' => $pago->metodoPago?->only(['id', 'name']),
            'cuenta_bancaria' => $pago->cuentaBancaria?->only(['id', 'banco', 'numero_cuenta', 'alias']),
            'numero_operacion' => $pago->numero_operacion,
            'observaciones' => $pago->observaciones,
            'anulado_at' => $pago->anulado_at?->toISOString(),
            'anulado_por' => $pago->relationLoaded('anulador') ? $persona($pago->anulador) : null,
            'motivo_anulacion' => $pago->motivo_anulacion,
            'registrado_por' => $pago->relationLoaded('usuario') ? $persona($pago->usuario) : null,
            'created_at' => $pago->created_at?->toISOString(),
            'aplicaciones' => $pago->aplicaciones->map(fn (AplicacionPagoProveedor $a) => [
                'id' => $a->id,
                'compra_id' => $a->compra_id,
                'compra' => $a->compra?->only(['id', 'tipo_comprobante_codigo', 'serie', 'numero', 'total', 'moneda']),
                'monto' => $a->monto,
                'desaplicada_at' => $a->desaplicada_at?->toISOString(),
                'motivo_desaplicacion' => $a->motivo_desaplicacion,
            ])->values(),
        ];
    }
}
