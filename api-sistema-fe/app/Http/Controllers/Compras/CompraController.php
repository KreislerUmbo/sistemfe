<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Models\Cash\PaymentMethod;
use App\Models\Compras\AplicacionPagoProveedor;
use App\Models\Compras\Compra;
use App\Models\Compras\CompraLinea;
use App\Models\Compras\CompraPeriodo;
use App\Models\CuentaBancaria;
use App\Models\Proveedor;
use App\Models\Sale\TipoComprobante;
use App\Services\AgenciaViajes\CompraAsignacionService;
use App\Services\BusquedaLike;
use App\Services\Compras\CompraService;
use App\Services\Compras\PagoProveedorService;
use App\Services\HoraPeru;
use App\Services\Tenancy\GiroActual;
use App\Services\TipoCambio\TipoCambioSunatResolver;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

// T-135 — comprobantes de compra. Las reglas viven en CompraService. No hay destroy(): una
// compra se anula (D8). Sin Policy de fila: las compras son del negocio.
class CompraController extends Controller
{
    private const ARCHIVOS = ['pdf', 'xml'];

    public function __construct(private CompraService $service, private PagoProveedorService $pagos) {}

    public function index(Request $request)
    {
        $query = Compra::query();
        $this->filtrar($query, $request);

        $totales = $this->totalesEnSoles(clone $query);
        $perPage = min(100, max(1, (int) $request->get('per_page', 15)));
        $compras = $query->with(['proveedor', 'referencia'])
            ->orderByDesc('fecha_emision')->orderByDesc('id')
            ->paginate($perPage);

        $contexto = $this->contexto($compras->getCollection()->pluck('periodo')->all());
        // T-136: saldo y estado de toda la página en lote.
        $contexto['pagos'] = $this->pagos->estados($compras->getCollection());

        return response()->json([
            'total' => $compras->total(),
            'paginate' => $perPage,
            'compras' => $compras->getCollection()->map(fn (Compra $c) => $this->datos($c, $contexto))->values(),
            'totales_pen' => $totales,
            'permite_asignar' => $this->repartoActivo(),
        ]);
    }

    private function repartoActivo(): bool
    {
        return app(GiroActual::class)->es('agencia_viajes');
    }

    public function show(string $id)
    {
        return $this->respuesta(Compra::findOrFail($id));
    }

    public function store(Request $request)
    {
        $compra = $this->service->registrar($request, (int) auth('api')->id());

        return $this->respuesta($compra, 'Compra registrada correctamente');
    }

    public function update(Request $request, string $id)
    {
        $compra = $this->service->editar($request, Compra::findOrFail($id));

        return $this->respuesta($compra, 'Compra actualizada correctamente');
    }

    public function anular(Request $request, string $id)
    {
        $compra = $this->service->anular(Compra::findOrFail($id), $request->input('motivo'), (int) auth('api')->id());

        return $this->respuesta($compra, 'Compra anulada');
    }

    /** Lo que el formulario necesita: los tipos de la tabla 10 y el tipo de cambio de una fecha. */
    public function catalogos(Request $request, TipoCambioSunatResolver $tipoCambio)
    {
        $fecha = (string) $request->query('fecha');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = HoraPeru::hoyTexto();
        }
        $cambio = $tipoCambio->resolverParaFecha(Carbon::parse($fecha));

        return response()->json([
            'tipos' => TipoComprobante::where('uso_compras', true)->orderBy('codigo')->get(['codigo', 'nombre']),
            'tipo_cambio' => $cambio ? ['fecha' => $cambio->fecha->toDateString(), 'venta' => $cambio->venta] : null,
            // T-136: lo que se elige al pagar.
            'cuentas_bancarias' => CuentaBancaria::where('activo', true)->orderBy('orden')->orderBy('id')
                ->get(['id', 'banco', 'numero_cuenta', 'alias', 'moneda']),
            'metodos_pago' => PaymentMethod::where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name']),
        ]);
    }

    // D9. Dentro de auth:api: devuelve la URL firmada de 10 minutos.
    public function adjuntoUrl(string $id, string $archivo)
    {
        abort_unless(in_array($archivo, self::ARCHIVOS, true), 404);

        $compra = Compra::findOrFail($id);
        if (! $compra->{"adjunto_{$archivo}"}) {
            return response()->json(['message' => 'Esta compra no tiene '.strtoupper($archivo).' adjunto.'], 404);
        }

        return response()->json([
            'url' => URL::temporarySignedRoute('compras.archivo', now()->addMinutes(10), ['id' => $compra->id, 'archivo' => $archivo]),
        ]);
    }

    // Ruta firmada (sin login): el navegador la abre en otra pestaña.
    public function descargar(string $id, string $archivo)
    {
        abort_unless(in_array($archivo, self::ARCHIVOS, true), 404);

        $compra = Compra::findOrFail($id);
        $ruta = $compra->{"adjunto_{$archivo}"};
        if (! $ruta || ! Storage::disk('private')->exists($ruta)) {
            abort(404, 'El archivo ya no está en el servidor.');
        }

        // Symfony no acepta «/» ni «\» en el nombre de la descarga (500), y una factura del
        // exterior suele numerarse «INV/2026/001»: solo el nombre del archivo los cambia por «-».
        $nombre = str_replace(['/', '\\'], '-', "{$compra->tipo_comprobante_codigo}-{$compra->serie}-{$compra->numero}");

        return Storage::disk('private')->download($ruta, "{$nombre}.{$archivo}");
    }

    private function filtrar(Builder $query, Request $request): void
    {
        if (! $request->boolean('incluir_anuladas')) {
            $query->vigentes();
        }
        if ($request->filled('periodo')) {
            $query->where('periodo', $request->get('periodo'));
        }
        if ($request->filled('proveedor_id')) {
            $query->where('proveedor_id', (int) $request->get('proveedor_id'));
        }
        if ($request->filled('tipo')) {
            $query->where('tipo_comprobante_codigo', $request->get('tipo'));
        }
        // Para elegir la compra que modifica una NC/ND: las del mismo documento que el proveedor.
        if ($request->filled('referencia_para')) {
            $proveedor = Proveedor::findOrFail((int) $request->get('referencia_para'));
            $documento = mb_strtoupper(preg_replace('/\s+/u', '', (string) $proveedor->numero_documento));
            $query->where('clave_proveedor', $documento === '' ? "id:{$proveedor->id}" : $documento)
                ->whereNotIn('tipo_comprobante_codigo', Compra::TIPOS_NOTA);
        }
        if ($request->filled('search')) {
            $texto = BusquedaLike::contiene(trim((string) $request->get('search')));
            $query->where(function (Builder $q) use ($texto) {
                $q->whereRaw("(serie || '-' || numero) ilike ?", [$texto])
                    ->orWhereHas('proveedor', fn (Builder $p) => $p->where('razon_social', 'ilike', $texto)
                        ->orWhere('nombre_comercial', 'ilike', $texto));
            });
        }
    }

    // Totales del filtro en soles: sin anuladas, y la nota de crédito resta.
    private function totalesEnSoles(Builder $query): array
    {
        $columnas = collect([...Compra::MONTOS, 'total'])->map(fn (string $campo) => sprintf(
            "coalesce(sum(round(%s * coalesce(tipo_cambio, 1), 2) * case when tipo_comprobante_codigo = '%s' then -1 else 1 end), 0) as %s",
            $campo, Compra::TIPO_NOTA_CREDITO, $campo
        ))->implode(', ');

        $fila = $query->vigentes()->toBase()->selectRaw($columnas)->first();

        return collect([...Compra::MONTOS, 'total'])
            ->mapWithKeys(fn (string $campo) => [$campo => number_format((float) $fila->{$campo}, 2, '.', '')])
            ->all();
    }

    private function respuesta(Compra $compra, ?string $mensaje = null)
    {
        $compra = Compra::with(['proveedor', 'referencia', 'usuario', 'anulador', 'lineas.producto'])->findOrFail($compra->id);

        $datos = $this->datos($compra, $this->contexto([$compra->periodo]) + ['pagos' => $this->pagos->estados(collect([$compra]))]);
        $datos['aplicaciones_pago'] = $this->aplicacionesDe($compra);
        $datos['lineas'] = $compra->lineas->map(fn (CompraLinea $l) => $l->only([
            'id', 'product_id', 'descripcion', 'unidad_medida', 'codigo_proveedor', 'tipo_afectacion_igv',
            'factor', 'cantidad', 'valor_unitario', 'valor_venta', 'igv', 'total', 'costo_unitario_base',
        ]) + ['producto' => $l->producto?->only(['id', 'title', 'sku'])])->values();
        // Lo que una nota de crédito todavía puede devolver de esta compra, en unidades base (D3).
        $datos['devolvible'] = collect($this->service->devolvible($compra->id))
            ->map(fn (BigDecimal $maximo, int $id) => ['product_id' => $id, 'maximo' => $maximo->toFloat()])
            ->values();
        // T-152: el reparto solo existe en Agencia; en otros giros la clave no sale.
        if ($this->repartoActivo()) {
            $datos['asignaciones'] = app(CompraAsignacionService::class)->detalle($compra);
        }

        return response()->json(array_filter(['code' => 200, 'message' => $mensaje, 'aviso' => $this->aviso($compra)])
            + ['compra' => $datos]);
    }

    // T-136: la ficha muestra también las desaplicadas, para que se vea qué pasó.
    private function aplicacionesDe(Compra $compra): Collection
    {
        return $compra->aplicaciones()->with(['pago.cuentaBancaria', 'pago.metodoPago'])->get()
            ->map(fn (AplicacionPagoProveedor $a) => [
                'id' => $a->id,
                'monto' => $a->monto,
                'desaplicada_at' => $a->desaplicada_at?->toISOString(),
                'motivo_desaplicacion' => $a->motivo_desaplicacion,
                'pago' => [
                    'id' => $a->pago->id,
                    'fecha' => $a->pago->fecha->toDateString(),
                    'monto' => $a->pago->monto,
                    'origen' => $a->pago->origen,
                    'estado' => $a->pago->estado,
                    'numero_operacion' => $a->pago->numero_operacion,
                    'metodo_pago' => $a->pago->metodoPago?->name,
                    'cuenta' => $a->pago->cuentaBancaria
                        ? "{$a->pago->cuentaBancaria->banco} {$a->pago->cuentaBancaria->numero_cuenta}" : null,
                ],
            ]);
    }

    // T-154 (D9): las líneas que no cuadran con la base se guardan igual, pero se avisa.
    // T-162: si la suma cuadra, además se compara cada afectación con su monto de la cabecera.
    private function aviso(Compra $compra): ?string
    {
        if ($compra->lineas->isEmpty()) {
            return null;
        }

        $sumar = fn (iterable $valores) => collect($valores)
            ->reduce(fn (BigDecimal $total, $valor) => $total->plus((string) $valor), BigDecimal::of('0.00'));
        $nombre = $compra->lineas->contains(fn (CompraLinea $l) => $l->product_id === null) ? 'Las líneas' : 'Los productos';

        $lineas = $sumar($compra->lineas->pluck('valor_venta'));
        $base = $sumar([$compra->base_gravada, $compra->exonerado, $compra->inafecto]);
        if (! $lineas->isEqualTo($base)) {
            return "{$nombre} suman {$lineas} y la base del comprobante es {$base}";
        }
        // Una línea anterior a T-162 puede no tener afectación: no hay con qué comparar por tipo.
        if ($compra->lineas->contains(fn (CompraLinea $l) => $l->tipo_afectacion_igv === null)) {
            return null;
        }

        $diferencias = [];
        foreach ([
            ['gravado', ['10'], $compra->base_gravada],
            ['exonerado', ['20'], $compra->exonerado],
            // La exportación no tiene monto propio en la cabecera: va con lo inafecto.
            ['inafecto', ['30', '40'], $compra->inafecto],
        ] as [$tipo, $codigos, $cabecera]) {
            $suma = $sumar($compra->lineas->whereIn('tipo_afectacion_igv', $codigos)->pluck('valor_venta'));
            if (! $suma->isEqualTo((string) $cabecera)) {
                $diferencias[] = "{$tipo} {$suma} en las líneas y {$cabecera} en el comprobante";
            }
        }

        return $diferencias ? 'No cuadra por afectación del IGV: '.implode('; ', $diferencias) : null;
    }

    private function contexto(array $periodos): array
    {
        return [
            'tipos' => TipoComprobante::pluck('nombre', 'codigo'),
            'cerrados' => CompraPeriodo::whereIn('periodo', $periodos)->where('estado', CompraPeriodo::CERRADO)->pluck('periodo')->all(),
        ];
    }

    // Las rutas de los adjuntos nunca salen: solo si los hay.
    private function datos(Compra $compra, array $contexto): array
    {
        $persona = fn ($usuario) => $usuario ? trim("{$usuario->name} {$usuario->surname}") : null;
        $proveedor = $compra->proveedor;

        return [
            'id' => $compra->id,
            'proveedor_id' => $compra->proveedor_id,
            'proveedor' => $proveedor?->only(['id', 'razon_social', 'nombre_comercial', 'ciudad', 'numero_documento']),
            'proveedor_documento' => $compra->proveedor_documento,
            'tipo_comprobante_codigo' => $compra->tipo_comprobante_codigo,
            'tipo_nombre' => $contexto['tipos'][$compra->tipo_comprobante_codigo] ?? $compra->tipo_comprobante_codigo,
            'serie' => $compra->serie,
            'numero' => $compra->numero,
            'fecha_emision' => $compra->fecha_emision->toDateString(),
            'periodo' => $compra->periodo,
            'periodo_cerrado' => in_array($compra->periodo, $contexto['cerrados'], true),
            'moneda' => $compra->moneda,
            'tipo_cambio' => $compra->tipo_cambio,
            'base_gravada' => $compra->base_gravada,
            'igv' => $compra->igv,
            'exonerado' => $compra->exonerado,
            'inafecto' => $compra->inafecto,
            'otros' => $compra->otros,
            'total' => $compra->total,
            'condicion' => $compra->condicion,
            // T-136: derivados; null en anuladas y notas de crédito.
            'saldo' => $contexto['pagos'][$compra->id]['saldo'],
            'estado_pago' => $contexto['pagos'][$compra->id]['estado_pago'],
            'por_aprobar' => $contexto['pagos'][$compra->id]['por_aprobar'],
            'fecha_vencimiento' => $compra->fecha_vencimiento?->toDateString(),
            'compra_referencia_id' => $compra->compra_referencia_id,
            'con_credito_fiscal' => $compra->con_credito_fiscal,
            'referencia' => $compra->referencia?->only(['id', 'tipo_comprobante_codigo', 'serie', 'numero']),
            'tiene_pdf' => $compra->adjunto_pdf !== null,
            'tiene_xml' => $compra->adjunto_xml !== null,
            'estado_sunat' => $compra->estado_sunat ?? 'sin_validar',
            'observaciones' => $compra->observaciones,
            'anulada_at' => $compra->anulada_at?->toISOString(),
            'anulada_por' => $compra->relationLoaded('anulador') ? $persona($compra->anulador) : null,
            'motivo_anulacion' => $compra->motivo_anulacion,
            'registrada_por' => $compra->relationLoaded('usuario') ? $persona($compra->usuario) : null,
            'created_at' => $compra->created_at?->toISOString(),
        ];
    }
}
