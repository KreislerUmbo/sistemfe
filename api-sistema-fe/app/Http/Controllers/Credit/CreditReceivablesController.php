<?php

namespace App\Http\Controllers\Credit;

use App\Http\Controllers\Controller;
use App\Models\Sale\Sale;
use App\Services\CreditSummaryCalculator;
use Illuminate\Http\Request;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §3.11, §4 (Fase 7).
// Pantalla de Cuentas por Cobrar: dos vistas conmutables sobre el mismo
// origen de datos (ventas con saldo_pendiente > 0, SIN exigir
// condicion_pago='credito' — corrección ya cerrada en Fase 1, §3.11/§9).
// La vista A es una agregación por client_id de la vista B — ambas delegan
// el cálculo por venta (mora, cuotas vencidas, estado) a
// CreditSummaryCalculator, compartido también con
// CreditPaymentController::creditSummary().
//
// Paginación en memoria (no SQL) a propósito: 'estado' (al_dia/por_vencer/
// vencida) es un valor calculado on-the-fly por venta, no una columna —
// filtrar/ordenar por él requiere tener ya armada cada fila. Escala bien
// al tamaño real de cartera de un POS por tenant; si eso deja de ser
// cierto, el filtro de estado tendría que bajar a SQL (ej. columna
// materializada por un job nocturno, ya contemplado como idea futura en
// §3.8 del plan para mora).
class CreditReceivablesController extends Controller
{
    private const PER_PAGE = 25;

    protected $summaryCalculator;

    public function __construct(CreditSummaryCalculator $summaryCalculator)
    {
        $this->summaryCalculator = $summaryCalculator;
    }

    // ── GET /credit-sales ─────────────────────────────────────────────────
    // Vista B — listado plano de ventas con saldo pendiente, una fila por
    // venta. Filtros: estado, rango de fechas de venta, cliente. Orden por
    // antigüedad (default) o por monto.
    public function creditSales(Request $request)
    {
        $request->validate([
            'estado' => 'nullable|string|in:al_dia,por_vencer,vencida',
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
            'client_id' => 'nullable|integer|exists:clients,id',
            'order_by' => 'nullable|string|in:antiguedad,monto',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = Sale::where('saldo_pendiente', '>', 0)->with('client');

        if ($request->filled('fecha_desde')) {
            $query->whereDate('date', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('date', '<=', $request->fecha_hasta);
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->client_id);
        }

        $filas = $query->orderBy('date')->get()->map(function (Sale $venta) {
            $fila = $this->summaryCalculator->resumenVenta($venta);
            $fila['client_nombre'] = optional($venta->client)->full_name;
            $fila['client_n_document'] = optional($venta->client)->n_document;

            return $fila;
        });

        if ($request->filled('estado')) {
            $filas = $filas->where('estado', $request->estado)->values();
        }

        $filas = $request->order_by === 'monto'
            ? $filas->sortByDesc('saldo_pendiente')->values()
            : $filas->sortBy('date')->values();

        return response()->json($this->paginar($filas, $request, 'sales'));
    }

    // ── GET /clients/credit-summary-list ────────────────────────────────
    // Vista A — listado agrupado por cliente con deuda total consolidada.
    // Buscador por nombre/documento. Orden por deuda total descendente
    // (caso más común: atender primero al cliente que más debe).
    public function creditSummaryList(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
        ]);

        $ventas = Sale::where('saldo_pendiente', '>', 0)->with('client')->get();

        $porCliente = $ventas->groupBy('client_id')
            ->filter(fn ($ventasCliente) => $ventasCliente->first()->client !== null)
            ->map(function ($ventasCliente) {
                $cliente = $ventasCliente->first()->client;
                $filas = $ventasCliente->map(fn (Sale $v) => $this->summaryCalculator->resumenVenta($v));

                return [
                    'client_id' => $cliente->id,
                    'client_nombre' => $cliente->full_name,
                    'n_document' => $cliente->n_document,
                    'deuda_total' => round($filas->sum('saldo_pendiente'), 2),
                    'cantidad_ventas_abiertas' => $filas->count(),
                    'cantidad_cuotas_vencidas' => $filas->sum('cuotas_vencidas'),
                    'saldo_a_favor' => (float) $cliente->saldo_a_favor,
                ];
            })
            ->values();

        if ($request->filled('search')) {
            $search = mb_strtolower($request->search);
            $porCliente = $porCliente->filter(function ($fila) use ($search) {
                return str_contains(mb_strtolower($fila['client_nombre'] ?? ''), $search)
                    || str_contains(mb_strtolower($fila['n_document'] ?? ''), $search);
            })->values();
        }

        $porCliente = $porCliente->sortByDesc('deuda_total')->values();

        return response()->json($this->paginar($porCliente, $request, 'clients'));
    }

    // Paginación en memoria compartida por ambas vistas — misma forma de
    // respuesta ('total'/'paginate'/clave de datos) que AdvanceController::index().
    private function paginar($coleccion, Request $request, string $key): array
    {
        $page = (int) ($request->page ?? 1);
        $items = $coleccion->forPage($page, self::PER_PAGE)->values();

        return [
            'total' => $coleccion->count(),
            'paginate' => self::PER_PAGE,
            $key => $items,
        ];
    }
}
