<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajeAereo;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use App\Services\AgenciaViajes\PriceEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Alternativa de cotización — plan-modulo-cotizaciones-reservas.md §3.1/§3.2.
// Anidada bajo cotización (store), directa por su propio id (update/destroy).
class AlternativaController extends Controller
{
    private const MAX_ALTERNATIVAS_POR_COTIZACION = 5;

    public function __construct(private PriceEngineService $priceEngine)
    {
    }

    public function store(Request $request, string $cotizacionId)
    {
        $cotizacion = Cotizacion::findOrFail($cotizacionId);

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:100',
            'moneda_cotizacion' => 'required|in:PEN,USD',
            'tipo_cambio_origen' => 'required|in:dia,agencia',
            // Opcional: si se manda, registra un tipo_cambio_agencia NUEVO con
            // este valor (§3.4, "opción de digitar uno nuevo si no está
            // registrado todavía") en vez de reusar el último ya existente.
            'tipo_cambio_valor' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        if ($cotizacion->alternativas()->count() >= self::MAX_ALTERNATIVAS_POR_COTIZACION) {
            return response()->json([
                'code' => 422,
                'message' => 'Esta cotización ya tiene el máximo de ' . self::MAX_ALTERNATIVAS_POR_COTIZACION . ' alternativas permitidas.',
            ], 422);
        }

        $validado = $validator->validated();

        $tipoCambio = $this->resolverTipoCambio($validado['tipo_cambio_origen'], $validado['tipo_cambio_valor'] ?? null, $request);
        if ($tipoCambio instanceof JsonResponse) {
            return $tipoCambio;
        }

        $letra = chr(65 + $cotizacion->alternativas()->count()); // A, B, C...

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacion->id,
            'nombre' => $validado['nombre'] ?: "Alternativa {$letra}",
            'estado' => 'borrador',
            'moneda_cotizacion' => $validado['moneda_cotizacion'],
            'tipo_cambio_aplicado' => $tipoCambio->valor,
            'tipo_cambio_origen' => $validado['tipo_cambio_origen'],
            'total' => 0,
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Alternativa creada correctamente',
            'alternativa' => $alternativa,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $alternativa = Alternativa::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'nombre' => 'nullable|string|max:100',
            'estado' => 'nullable|in:borrador,enviada,aceptada,descartada',
            'descuento_global_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = array_filter($validator->validated(), fn ($v) => $v !== null);
        $lineasFueraDePiso = [];

        DB::transaction(function () use ($alternativa, $validado, &$lineasFueraDePiso) {
            if (($validado['estado'] ?? null) === 'enviada' && ! $alternativa->fecha_envio) {
                $validado['fecha_envio'] = now();
            }

            $alternativa->update($validado);

            // Aceptar una alternativa descarta automáticamente las demás de
            // la misma cotización — §3.2. Este PUT ya NO dispara creación de
            // reserva (Sesión 11c usa POST alternativas/{id}/aceptar,
            // ReservaController::aceptar(), que reusa descartarOtras() de
            // acá mismo — no se duplica esta lógica).
            if (($validado['estado'] ?? null) === 'aceptada') {
                self::descartarOtras($alternativa);
            }

            // §3.1 — "al aplicarse, se reparte a cada alternativa_items
            // respetando el piso individual de cada uno; si alguna línea no
            // lo permite, se avisa cuál en vez de bloquear todo en
            // silencio". Antes de esta sesión el campo se guardaba en la
            // alternativa pero nunca se aplicaba a ningún ítem — gap real
            // encontrado al conectar el panel de precio (Parte B, 11b3).
            if (array_key_exists('descuento_global_pct', $validado)) {
                $lineasFueraDePiso = $this->aplicarDescuentoGlobal($alternativa, (float) $validado['descuento_global_pct']);
            }
        });

        return response()->json([
            'code' => 200,
            'message' => 'Alternativa actualizada correctamente',
            'alternativa' => $alternativa->fresh('items'),
            'lineas_fuera_de_piso' => $lineasFueraDePiso,
        ]);
    }

    // Reparte descuentoGlobalPct a CADA ítem de la alternativa (mismo % para
    // todos, sobre su propio precio de lista) — mismo motor matemático que
    // AlternativaItemController::update() ya usa para un ítem suelto
    // (PriceEngineService::evaluarPiso()), sin reimplementar la fórmula.
    // Mismo criterio ya establecido ahí: el piso NUNCA bloquea el guardado,
    // solo se informa qué líneas lo cruzan — el descuento global se aplica
    // igual a todas, el vendedor decide si corrige alguna a mano después.
    // Ítems sin proveedor_tarifa (manual/referencia) no tienen piso que
    // evaluar, pero SÍ reciben el descuento — "cada alternativa_items" del
    // plan no distingue por origen_tipo.
    private function aplicarDescuentoGlobal(Alternativa $alternativa, float $descuentoGlobalPct): array
    {
        $lineasFueraDePiso = [];

        foreach ($alternativa->items()->with('proveedorTarifa')->get() as $item) {
            $precioListaConvertido = $this->priceEngine->convertirMoneda(
                (float) $item->precio_venta_snapshot,
                $item->moneda_costo,
                $alternativa->moneda_cotizacion,
                (float) $alternativa->tipo_cambio_aplicado
            );

            $precioConvertido = round($precioListaConvertido * (1 - $descuentoGlobalPct / 100), 2);

            if ($item->proveedorTarifa) {
                $tarifa = $item->proveedorTarifa;
                $costoBaseConvertido = $this->priceEngine->convertirMoneda(
                    (float) $item->costo_snapshot,
                    $item->moneda_costo,
                    $alternativa->moneda_cotizacion,
                    (float) $alternativa->tipo_cambio_aplicado
                );

                $pisoInfo = $this->priceEngine->evaluarPiso(
                    precioEditado: $precioConvertido,
                    costoBase: $costoBaseConvertido,
                    ventaBase: $precioListaConvertido,
                    descuentoMaximoPct: $tarifa->descuento_maximo_pct !== null ? (float) $tarifa->descuento_maximo_pct : null,
                    margenMinimoPct: $tarifa->margen_minimo_pct !== null ? (float) $tarifa->margen_minimo_pct : null,
                );

                if ($pisoInfo['alerta_piso']) {
                    $lineasFueraDePiso[] = [
                        'alternativa_item_id' => $item->id,
                        'precio_minimo_permitido' => $pisoInfo['precio_minimo_permitido'],
                    ];
                }
            }

            $item->update([
                'descuento_pct' => $descuentoGlobalPct,
                'precio_convertido' => $precioConvertido,
            ]);
        }

        $total = $alternativa->items()->get()->sum(fn (AlternativaItem $item) => $item->total_convertido);
        $alternativa->update(['total' => round($total, 2)]);

        return $lineasFueraDePiso;
    }

    // Antes de esta sesión, delete() era directo — con ítems/opciones-mayorista
    // ya cargados, `alternativa_items.alternativa_id`/`opcion_mayorista.
    // alternativa_id` (ambos `constrained()` sin `onDelete`, RESTRICT en
    // Postgres) tiraban una violación de FK sin capturar (500 crudo). El
    // frontend (editar.vue::eliminarAlternativa(), sin try/catch hasta esta
    // sesión) no mostraba nada — parecía que el botón "no hacía nada". Ahora:
    // 422 explícito si ya generó una reserva (no se puede perder ese
    // vínculo); si no, cascada real de todo lo que cuelga de la alternativa
    // (ítems + su cotizacion_pasaje_aereo, y las opciones de mayorista con
    // sus opcionales/hoteles/tarifas propias) en una transacción, mismo
    // patrón que PaquetePlantillaController::destroy().
    public function destroy(string $id)
    {
        $alternativa = Alternativa::findOrFail($id);

        if (Reserva::where('alternativa_id', $alternativa->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar esta alternativa: ya generó una reserva.',
            ], 422);
        }

        DB::transaction(function () use ($alternativa) {
            $itemIds = AlternativaItem::where('alternativa_id', $alternativa->id)->pluck('id');
            CotizacionPasajeAereo::whereIn('alternativa_item_id', $itemIds)->delete();
            AlternativaItem::whereIn('id', $itemIds)->delete();

            $opciones = OpcionMayorista::where('alternativa_id', $alternativa->id)->get();
            foreach ($opciones as $opcion) {
                OpcionMayoristaOpcional::where('opcion_mayorista_id', $opcion->id)->delete();

                $hotelIds = OpcionHotel::where('opcion_mayorista_id', $opcion->id)->pluck('id');
                OpcionHotelTarifa::whereIn('opcion_hotel_id', $hotelIds)->delete();
                OpcionHotel::whereIn('id', $hotelIds)->delete();

                $opcion->delete();
            }

            $alternativa->delete();
        });

        return response()->json(['code' => 200, 'message' => 'Alternativa eliminada correctamente']);
    }

    // Sesión 11h — clona la alternativa activa completa (ítems + su
    // cotizacion_pasaje_aereo si aplica, y el árbol de opciones de
    // mayorista con hoteles/tarifas/opcionales) en una alternativa NUEVA de
    // la misma cotización, nombre + " (copia)". Clonar el árbol de
    // opciones de mayorista (en vez de solo remapear alternativa_items al
    // original) es a propósito: la idea de "duplicar" es poder editar la
    // copia libremente sin afectar la alternativa original, y un ítem
    // origen_tipo=mayorista que siguiera apuntando a la opción del
    // original rompería esa independencia (el comparador de la copia no la
    // listaría como propia, ver OpcionMayoristaController::index()).
    // Mismo límite de 5 alternativas que store() — duplicar no lo esquiva.
    public function duplicar(string $id)
    {
        $original = Alternativa::findOrFail($id);

        if ($original->cotizacion->alternativas()->count() >= self::MAX_ALTERNATIVAS_POR_COTIZACION) {
            return response()->json([
                'code' => 422,
                'message' => 'Esta cotización ya tiene el máximo de ' . self::MAX_ALTERNATIVAS_POR_COTIZACION . ' alternativas permitidas.',
            ], 422);
        }

        $nueva = DB::transaction(function () use ($original) {
            $nueva = Alternativa::create([
                'cotizacion_id' => $original->cotizacion_id,
                'nombre' => $original->nombre . ' (copia)',
                'estado' => 'borrador',
                'moneda_cotizacion' => $original->moneda_cotizacion,
                'tipo_cambio_aplicado' => $original->tipo_cambio_aplicado,
                'tipo_cambio_origen' => $original->tipo_cambio_origen,
                'descuento_global_pct' => $original->descuento_global_pct,
                'total' => $original->total,
            ]);

            // Ítems primero (sin opcion_mayorista_id todavía si vienen de
            // mayorista — se remapea al final, una vez clonado el árbol de
            // opciones más abajo).
            $itemsClonados = [];
            foreach ($original->items()->get() as $item) {
                $nuevoItem = AlternativaItem::create([
                    'alternativa_id' => $nueva->id,
                    'origen_tipo' => $item->origen_tipo,
                    'proveedor_tarifa_id' => $item->proveedor_tarifa_id,
                    'tour_origen_id' => $item->tour_origen_id,
                    'dia_referencial' => $item->dia_referencial,
                    'descripcion_manual' => $item->descripcion_manual,
                    'modo_precio' => $item->modo_precio,
                    'cantidad' => $item->cantidad,
                    'pax_incluidos' => $item->pax_incluidos,
                    'moneda_costo' => $item->moneda_costo,
                    'costo_snapshot' => $item->costo_snapshot,
                    'precio_venta_snapshot' => $item->precio_venta_snapshot,
                    'descuento_pct' => $item->descuento_pct,
                    'precio_convertido' => $item->precio_convertido,
                ]);
                $itemsClonados[$item->id] = $nuevoItem;

                if ($item->origen_tipo === AlternativaItem::ORIGEN_PASAJE_AEREO) {
                    $pasaje = CotizacionPasajeAereo::where('alternativa_item_id', $item->id)->first();
                    if ($pasaje) {
                        CotizacionPasajeAereo::create([
                            'alternativa_item_id' => $nuevoItem->id,
                            'aerolinea' => $pasaje->aerolinea,
                            'itinerario' => $pasaje->itinerario,
                            'moneda' => $pasaje->moneda,
                            'tarifa_base_adulto' => $pasaje->tarifa_base_adulto,
                            'tarifa_base_nino' => $pasaje->tarifa_base_nino,
                            'tarifa_base_infante' => $pasaje->tarifa_base_infante,
                            'cargos' => $pasaje->cargos,
                            'tua_incluida_en_tarifa' => $pasaje->tua_incluida_en_tarifa,
                            'fee_agencia_monto' => $pasaje->fee_agencia_monto,
                            'tip_afe_igv' => $pasaje->tip_afe_igv,
                            'fecha_cotizado' => $pasaje->fecha_cotizado,
                            'costo_total' => $pasaje->costo_total,
                            'precio_venta_total' => $pasaje->precio_venta_total,
                        ]);
                    }
                }
            }

            $opcionesClonadas = [];
            foreach (OpcionMayorista::where('alternativa_id', $original->id)->get() as $opcion) {
                $nuevaOpcion = OpcionMayorista::create([
                    'alternativa_id' => $nueva->id,
                    'proveedor_id' => $opcion->proveedor_id,
                    'salida_mayorista_id' => $opcion->salida_mayorista_id,
                    'moneda' => $opcion->moneda,
                    'incluye' => $opcion->incluye,
                    'notas' => $opcion->notas,
                    'vuelo_aerolinea' => $opcion->vuelo_aerolinea,
                    'vuelo_detalle' => $opcion->vuelo_detalle,
                    'estado' => $opcion->estado,
                ]);
                $opcionesClonadas[$opcion->id] = $nuevaOpcion->id;

                foreach (OpcionMayoristaOpcional::where('opcion_mayorista_id', $opcion->id)->get() as $opcional) {
                    OpcionMayoristaOpcional::create([
                        'opcion_mayorista_id' => $nuevaOpcion->id,
                        'nombre' => $opcional->nombre,
                        'precio_por_persona' => $opcional->precio_por_persona,
                        'moneda' => $opcional->moneda,
                        'incluye' => $opcional->incluye,
                        'no_incluye' => $opcional->no_incluye,
                    ]);
                }

                foreach (OpcionHotel::where('opcion_mayorista_id', $opcion->id)->get() as $hotel) {
                    $nuevoHotel = OpcionHotel::create([
                        'opcion_mayorista_id' => $nuevaOpcion->id,
                        'paquete_plantilla_id' => $hotel->paquete_plantilla_id,
                        'proveedor_id' => $hotel->proveedor_id,
                        'nombre_hotel' => $hotel->nombre_hotel,
                        'categoria_estrellas' => $hotel->categoria_estrellas,
                    ]);

                    foreach (OpcionHotelTarifa::where('opcion_hotel_id', $hotel->id)->get() as $tarifa) {
                        OpcionHotelTarifa::create([
                            'opcion_hotel_id' => $nuevoHotel->id,
                            'tipo_habitacion' => $tarifa->tipo_habitacion,
                            'precio_costo' => $tarifa->precio_costo,
                            'precio_venta' => $tarifa->precio_venta,
                        ]);
                    }
                }
            }

            foreach ($original->items()->where('origen_tipo', AlternativaItem::ORIGEN_MAYORISTA)->get() as $itemOriginal) {
                if (isset($itemsClonados[$itemOriginal->id], $opcionesClonadas[$itemOriginal->opcion_mayorista_id])) {
                    $itemsClonados[$itemOriginal->id]->update([
                        'opcion_mayorista_id' => $opcionesClonadas[$itemOriginal->opcion_mayorista_id],
                    ]);
                }
            }

            return $nueva;
        });

        return response()->json([
            'code' => 200,
            'message' => 'Alternativa duplicada correctamente',
            'alternativa' => $nueva->fresh('items'),
        ]);
    }

    // Compartido con ReservaController::aceptar() y VentaDirectaController::store()
    // (Sesión 11c) — descartar las demás alternativas de la misma cotización
    // es siempre el mismo movimiento sin importar quién marcó "aceptada".
    public static function descartarOtras(Alternativa $alternativa): void
    {
        Alternativa::where('cotizacion_id', $alternativa->cotizacion_id)
            ->where('id', '!=', $alternativa->id)
            ->update(['estado' => 'descartada']);
    }

    private function resolverTipoCambio(string $origen, ?float $valorNuevo, Request $request): TipoCambioAgencia|JsonResponse
    {
        if ($valorNuevo !== null) {
            return TipoCambioAgencia::create([
                'fecha' => now()->toDateString(),
                'origen' => $origen,
                'valor' => $valorNuevo,
                'registrado_por' => $request->user()->id,
            ]);
        }

        $ultimo = TipoCambioAgencia::where('origen', $origen)->orderByDesc('fecha')->orderByDesc('id')->first();

        if (! $ultimo) {
            return response()->json([
                'code' => 422,
                'message' => "No hay ningún tipo de cambio '{$origen}' registrado todavía — indicá tipo_cambio_valor para registrar uno nuevo.",
            ], 422);
        }

        return $ultimo;
    }
}
