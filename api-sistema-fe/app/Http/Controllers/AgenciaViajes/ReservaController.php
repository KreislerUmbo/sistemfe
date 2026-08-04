<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SalidaMayorista;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// De alternativa aceptada a Reserva — plan-modulo-cotizaciones-reservas.md
// §4. aceptar() reemplaza el "TODO Sesión 11c" que dejó
// AlternativaController::update() (Sesión 11b) — reusa
// AlternativaController::descartarOtras() en vez de duplicar esa lógica.
class ReservaController extends Controller
{
    private const RELACIONES_DETALLE = [
        'alternativa.cotizacion.cliente',
        'pasajeros.pasajeroCatalogo',
        'items.alternativaItem.proveedorTarifa.proveedorServicio.proveedor',
        'items.alternativaItem.proveedorTarifa.proveedorServicio.destinoServicio.servicio',
        'items.alternativaItem.opcionMayorista.proveedor',
        'items.alternativaItem.cotizacionPasajeAereo',
        'items.guia',
        'items.proveedorTarifa.proveedorServicio.proveedor',
        'items.pasajeros',
    ];

    public function index(Request $request)
    {
        $query = Reserva::with('alternativa.cotizacion.cliente');

        if ($request->filled('estado')) {
            $query->where('estado', $request->get('estado'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->whereHas('alternativa.cotizacion', function ($q) use ($search) {
                $q->where('codigo', 'ilike', "%{$search}%")
                    ->orWhere('destino', 'ilike', "%{$search}%")
                    ->orWhereHas('cliente', function ($qq) use ($search) {
                        $qq->where('full_name', 'ilike', "%{$search}%")
                            ->orWhere('n_document', 'ilike', "%{$search}%");
                    });
            });
        }

        $reservas = $query->orderByDesc('id')->paginate(15);

        return response()->json([
            'total' => $reservas->total(),
            'paginate' => 15,
            'reservas' => $reservas->items(),
        ]);
    }

    public function show(string $id)
    {
        $reserva = Reserva::with(self::RELACIONES_DETALLE)->findOrFail($id);

        return response()->json($this->respuestaDetalle($reserva));
    }

    // POST alternativas/{id}/aceptar — punto 1 del prompt. Reusa
    // AlternativaController::descartarOtras() y crearReservaDesdeAlternativa()
    // (esta última también la usa VentaDirectaController::store(), §4.1).
    public function aceptar(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);

        if (! in_array($alternativa->estado, ['borrador', 'enviada'], true)) {
            return response()->json([
                'code' => 422,
                'message' => 'La alternativa no está en un estado válido para aceptar (ya fue aceptada o descartada).',
            ], 422);
        }

        $existeReservaActiva = Reserva::whereHas(
            'alternativa',
            fn ($q) => $q->where('cotizacion_id', $alternativa->cotizacion_id)
        )->where('estado', 'activa')->exists();

        if ($existeReservaActiva) {
            return response()->json([
                'code' => 422,
                'message' => 'Esta cotización ya tiene una reserva activa.',
            ], 422);
        }

        // Opcional: el vendedor ya sabe quién es cada pasajero al aceptar —
        // lista alineada por orden (id asc) con cotizacion_pasajeros, cada
        // posición nullable.
        $pasajeroCatalogoIds = $request->input('pasajero_catalogo_ids', []);

        [$reserva, $alertaCupoExcedido] = DB::transaction(function () use ($alternativa, $pasajeroCatalogoIds) {
            $alternativa->update(['estado' => 'aceptada']);
            AlternativaController::descartarOtras($alternativa);

            return $this->crearReservaDesdeAlternativa($alternativa->fresh(), $pasajeroCatalogoIds);
        });

        $reserva->load(self::RELACIONES_DETALLE);

        return response()->json(array_merge(
            ['code' => 200, 'message' => 'Reserva creada correctamente', 'alerta_cupo_excedido' => $alertaCupoExcedido],
            $this->respuestaDetalle($reserva)
        ));
    }

    public function cancelar(Request $request, string $id)
    {
        $reserva = Reserva::findOrFail($id);

        if ($reserva->estado === 'cancelada') {
            return response()->json(['code' => 422, 'message' => 'La reserva ya está cancelada.'], 422);
        }

        // Sin esto, se podía cancelar una reserva que ya generó una venta
        // real facturada (SUNAT) sin ningún aviso — reserva_ventas es la
        // tabla puente hacia Sale, ver ReservaVenta.
        if (ReservaVenta::where('reserva_id', $reserva->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede cancelar: esta reserva ya tiene una venta/comprobante asociado.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'motivo_cancelacion' => 'required|in:voluntaria,fuerza_mayor,clima,falta_pago_cuotas',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $motivo = $validator->validated()['motivo_cancelacion'];

        DB::transaction(function () use ($reserva, $motivo) {
            $reserva->update([
                'estado' => 'cancelada',
                'fecha_cancelacion' => now(),
                'motivo_cancelacion' => $motivo,
            ]);

            // Liberación de cupo (§4.2, entra en el primer lanzamiento aunque
            // el resto de 4.2 sea Fase 2) — mismo movimiento que el
            // incremento al aceptar, en reversa.
            $alternativa = $reserva->alternativa;
            $opcionElegida = $alternativa?->opcionMayoristaElegida;

            if ($opcionElegida && $opcionElegida->salida_mayorista_id) {
                $totalPax = $reserva->pasajeros()->count();
                SalidaMayorista::where('id', $opcionElegida->salida_mayorista_id)->decrement('cupo_ocupado', $totalPax);
            }
        });

        return response()->json(['code' => 200, 'message' => 'Reserva cancelada correctamente', 'reserva' => $reserva->fresh()]);
    }

    // Compartido con VentaDirectaController::store() — arma
    // reserva/reserva_pasajeros/reserva_items desde una alternativa YA
    // marcada 'aceptada' (el caller decide cuándo/cómo llegó a ese estado).
    // No abre su propia transacción — el caller ya está dentro de una.
    public function crearReservaDesdeAlternativa(Alternativa $alternativa, array $pasajeroCatalogoIds = []): array
    {
        $opcionElegida = $alternativa->opcionMayoristaElegida;

        $reserva = Reserva::create([
            'alternativa_id' => $alternativa->id,
            'mayorista_elegida_id' => $opcionElegida?->id,
            'estado_reserva_mayorista' => $opcionElegida ? 'pendiente' : null,
            'estado' => 'activa',
        ]);

        $cotizacionPasajeros = CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)
            ->orderBy('id')
            ->get();

        foreach ($cotizacionPasajeros as $index => $cotizacionPasajero) {
            ReservaPasajero::create([
                'reserva_id' => $reserva->id,
                'tipo_pax' => $cotizacionPasajero->tipo_pax,
                'pasajero_catalogo_id' => $pasajeroCatalogoIds[$index] ?? null,
            ]);
        }

        $alternativaItems = AlternativaItem::where('alternativa_id', $alternativa->id)->get();

        foreach ($alternativaItems as $alternativaItem) {
            ReservaItem::create([
                'reserva_id' => $reserva->id,
                'alternativa_item_id' => $alternativaItem->id,
                'proveedor_tarifa_id' => $alternativaItem->proveedor_tarifa_id,
                // Sesión 11b4: propaga de qué tour vino el ítem (si vino de
                // explotar un paquete_combo) para que la agrupación visual
                // "Día 1/Día 2" sobreviva también en la reserva.
                'tour_origen_id' => $alternativaItem->tour_origen_id,
            ]);
        }

        $alertaCupoExcedido = false;

        if ($opcionElegida && $opcionElegida->salida_mayorista_id) {
            $totalPax = $cotizacionPasajeros->count();
            SalidaMayorista::where('id', $opcionElegida->salida_mayorista_id)->increment('cupo_ocupado', $totalPax);

            $salida = SalidaMayorista::find($opcionElegida->salida_mayorista_id);
            if ($salida && $salida->cupo_total !== null && $salida->cupo_ocupado > $salida->cupo_total) {
                $alertaCupoExcedido = true;
            }
        }

        return [$reserva, $alertaCupoExcedido];
    }

    // "resumen" para el panel de precio en vivo (§7.1 del prototipo) — el
    // frontend no recalcula nada, solo pinta nombre + total_convertido +
    // el TOTAL ya cerrado.
    private function respuestaDetalle(Reserva $reserva): array
    {
        $resumen = $reserva->items->map(function (ReservaItem $item) {
            return [
                'reserva_item_id' => $item->id,
                'nombre' => $this->resolverNombreItem($item->alternativaItem),
                'precio_venta_snapshot' => $item->alternativaItem->precio_venta_snapshot,
                'total_convertido' => $item->alternativaItem->total_convertido,
            ];
        })->values();

        $total = $reserva->items->sum(fn (ReservaItem $item) => (float) $item->alternativaItem->total_convertido);

        $cotizacion = $reserva->alternativa->cotizacion;

        return [
            'reserva' => $reserva,
            'resumen' => $resumen,
            'total' => round($total, 2),
            'moneda' => $reserva->alternativa->moneda_cotizacion,
            'cabecera' => [
                'cliente' => $cotizacion->cliente,
                'destino' => $cotizacion->destino,
                'fecha_viaje_desde' => $cotizacion->fecha_viaje_desde,
                'fecha_viaje_hasta' => $cotizacion->fecha_viaje_hasta,
                'codigo_cotizacion' => $cotizacion->codigo,
            ],
        ];
    }

    // Mismo criterio que etiquetaItem() en cotizador/editar.vue (Sesión
    // 11b) — replicado acá para que el resumen de la reserva no dependa de
    // que el frontend recalcule con la cadena completa de relaciones.
    private function resolverNombreItem(AlternativaItem $item): string
    {
        if ($item->origen_tipo === AlternativaItem::ORIGEN_MANUAL) {
            return $item->descripcion_manual ?? 'Ítem manual';
        }
        if ($item->origen_tipo === AlternativaItem::ORIGEN_PASAJE_AEREO) {
            return $item->cotizacionPasajeAereo?->aerolinea ?? 'Pasaje aéreo';
        }
        if ($item->origen_tipo === AlternativaItem::ORIGEN_MAYORISTA) {
            return $item->opcionMayorista?->proveedor?->razon_social ?? 'Paquete mayorista';
        }
        if ($item->proveedorTarifa?->tipo_habitacion) {
            $proveedor = $item->proveedorTarifa->proveedorServicio?->proveedor?->razon_social ?? 'Hotel';

            return "{$proveedor} · {$item->proveedorTarifa->tipo_habitacion}";
        }

        return $item->proveedorTarifa?->proveedorServicio?->destinoServicio?->servicio?->nombre ?? 'Servicio';
    }
}
