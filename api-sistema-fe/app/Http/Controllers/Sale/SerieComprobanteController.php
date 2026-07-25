<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Models\Cash\Branch;
use App\Models\Sale\SerieComprobante;
use App\Services\SerieComprobanteService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Módulo de series de comprobantes — Paso 3 (CRUD backend). No hay borrado
// real: correlativo_actual>0 bloquea editar branch_id/tipo_comprobante_codigo/
// moneda/serie (rompería trazabilidad), "desactivar" (activo=false) cumple
// el rol de soft-delete — mismo criterio que PaymentMethodController.
class SerieComprobanteController extends Controller
{
    public function __construct(private SerieComprobanteService $serieComprobanteService)
    {
    }

    // Prefijo esperado por tipo de comprobante — solo se exige cuando el tipo
    // es fiscal (es_documento_sunat=true); nota de venta (NV) tiene prefijo
    // libre, sin restricción normativa (no es requisito SUNAT, es práctica
    // contable interna para el resto de tipos).
    private const PREFIJOS_ESPERADOS = [
        '01' => ['F'],       // Factura
        '03' => ['B'],       // Boleta
        '07' => ['F', 'B'],  // Nota de crédito (FC.. o BC.. según el comprobante afectado)
        '08' => ['F', 'B'],  // Nota de débito (FD.. o BD.. según el comprobante afectado)
    ];

    public function index(Request $request)
    {
        $query = SerieComprobante::query();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('tipo_comprobante_codigo')) {
            $query->where('tipo_comprobante_codigo', $request->tipo_comprobante_codigo);
        }

        if ($request->has('activo')) {
            $query->where('activo', $request->boolean('activo'));
        }

        $series = $query->with('branch:id,name')
            ->orderBy('branch_id')
            ->orderBy('tipo_comprobante_codigo')
            ->paginate(20);

        return response()->json([
            'total'  => $series->total(),
            'paginate' => 20,
            'series_comprobante' => $series->items(),
        ]);
    }

    public function store(Request $request)
    {
        $branch = Branch::find($request->branch_id);

        if (!$branch) {
            throw new HttpException(422, 'La sucursal indicada no existe.');
        }

        $tipo = $this->serieComprobanteService->validarTipoParaCrearSerie((string) $request->tipo_comprobante_codigo);

        $moneda = $request->moneda;
        if (!in_array($moneda, ['PEN', 'USD'], true)) {
            throw new HttpException(422, "Moneda inválida: '{$moneda}'. Debe ser 'PEN' o 'USD'.");
        }

        $serie_texto = trim((string) $request->serie);
        $this->validarPrefijoSerie($serie_texto, $tipo->codigo, $tipo->es_documento_sunat);

        $existe = SerieComprobante::where('branch_id', $branch->id)
            ->where('tipo_comprobante_codigo', $tipo->codigo)
            ->where('moneda', $moneda)
            ->exists();

        if ($existe) {
            throw new HttpException(
                422,
                "Ya existe una serie para esta sucursal, tipo de comprobante y moneda. " .
                "Desactiva la existente antes de crear otra, o edítala."
            );
        }

        $correlativo_inicial = (int) ($request->correlativo_inicial ?? 1);
        if ($correlativo_inicial < 1) {
            throw new HttpException(422, 'correlativo_inicial debe ser al menos 1.');
        }

        $serie = SerieComprobante::create([
            'branch_id'               => $branch->id,
            'tipo_comprobante_codigo' => $tipo->codigo,
            'moneda'                  => $moneda,
            'serie'                   => $serie_texto,
            // Fila semilla: correlativo_actual arranca en 0 sin importar
            // correlativo_inicial — reservarCorrelativo() suma 1 en la
            // primera reserva real. correlativo_inicial queda solo como
            // referencia/config de arranque (no se usa para calcular el
            // primer correlativo real; ver SerieComprobanteService).
            'correlativo_actual'      => 0,
            'correlativo_inicial'     => $correlativo_inicial,
            'fecha_inicio'            => $request->fecha_inicio ?? now()->format('Y-m-d'),
            'activo'                  => $request->boolean('activo', true),
        ]);

        return response()->json([
            'code'    => 200,
            'message' => 'Serie de comprobante creada correctamente.',
            'serie_comprobante' => $serie,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $serie = SerieComprobante::findOrFail($id);

        // Ya tiene correlativos reservados: solo se permite desactivar/
        // reactivar. branch_id/tipo_comprobante_codigo/moneda/serie/
        // correlativo_inicial son inmutables desde acá — cambiarlos
        // rompería la trazabilidad de qué serie generó qué correlativo.
        if ($serie->correlativo_actual > 0) {
            if ($request->has('activo')) {
                $serie->update(['activo' => $request->boolean('activo')]);
            }

            return response()->json([
                'code'    => 200,
                'message' => 'Serie actualizada (solo el estado activo/inactivo, ya tiene correlativos reservados).',
                'serie_comprobante' => $serie->fresh(),
            ]);
        }

        $branch = Branch::find($request->branch_id ?? $serie->branch_id);
        if (!$branch) {
            throw new HttpException(422, 'La sucursal indicada no existe.');
        }

        $tipo = $this->serieComprobanteService->validarTipoParaCrearSerie(
            (string) ($request->tipo_comprobante_codigo ?? $serie->tipo_comprobante_codigo)
        );

        $moneda = $request->moneda ?? $serie->moneda;
        if (!in_array($moneda, ['PEN', 'USD'], true)) {
            throw new HttpException(422, "Moneda inválida: '{$moneda}'. Debe ser 'PEN' o 'USD'.");
        }

        $serie_texto = trim((string) ($request->serie ?? $serie->serie));
        $this->validarPrefijoSerie($serie_texto, $tipo->codigo, $tipo->es_documento_sunat);

        $existe = SerieComprobante::where('id', '<>', $serie->id)
            ->where('branch_id', $branch->id)
            ->where('tipo_comprobante_codigo', $tipo->codigo)
            ->where('moneda', $moneda)
            ->exists();

        if ($existe) {
            throw new HttpException(422, 'Ya existe otra serie para esta sucursal, tipo de comprobante y moneda.');
        }

        $serie->update([
            'branch_id'               => $branch->id,
            'tipo_comprobante_codigo' => $tipo->codigo,
            'moneda'                  => $moneda,
            'serie'                   => $serie_texto,
            'correlativo_inicial'     => (int) ($request->correlativo_inicial ?? $serie->correlativo_inicial),
            'fecha_inicio'            => $request->fecha_inicio ?? $serie->fecha_inicio,
            'activo'                  => $request->has('activo') ? $request->boolean('activo') : $serie->activo,
        ]);

        return response()->json([
            'code'    => 200,
            'message' => 'Serie de comprobante actualizada correctamente.',
            'serie_comprobante' => $serie->fresh(),
        ]);
    }

    // No hay borrado real — desactiva. Mismo motivo que
    // PaymentMethodController::destroy(): ventas históricas ya emitidas con
    // esta serie no deben perder su referencia, solo se bloquea su uso en
    // ventas nuevas (SerieComprobanteService::resolverSerie() ya filtra
    // activo=true).
    public function destroy(string $id)
    {
        $serie = SerieComprobante::findOrFail($id);
        $serie->update(['activo' => false]);

        return response()->json([
            'code'    => 200,
            'message' => 'Serie de comprobante desactivada correctamente.',
        ]);
    }

    private function validarPrefijoSerie(string $serie, string $tipoCodigo, bool $esDocumentoSunat): void
    {
        if (empty($serie)) {
            throw new HttpException(422, 'La serie no puede estar vacía.');
        }

        if (!$esDocumentoSunat) {
            return; // nota de venta u otro documento interno: prefijo libre
        }

        $prefijos_validos = self::PREFIJOS_ESPERADOS[$tipoCodigo] ?? null;

        if (!$prefijos_validos) {
            return; // tipo fiscal sin regla de prefijo definida todavía
        }

        foreach ($prefijos_validos as $prefijo) {
            if (str_starts_with($serie, $prefijo)) {
                return;
            }
        }

        $lista = implode('/', $prefijos_validos);
        throw new HttpException(
            422,
            "La serie '{$serie}' no tiene un prefijo válido para este tipo de comprobante. " .
            "Debe empezar con: {$lista}."
        );
    }
}
