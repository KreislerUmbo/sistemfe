<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ProveedorTipoConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Sesión M3 — hotel ad-hoc LOCAL, sin depender de un proveedor
// registrado (plan-matriz-hoteles-cotizador.md Ronda 4/P11-P12).
// Espejo del mecanismo que ya existía SOLO para Internacional/mayorista
// (OpcionMayoristaController::hoteles()) — acá el OpcionHotel nace
// standalone, sin opcion_mayorista_id (ya era nullable a nivel de
// schema, no hizo falta migración para permitirlo).
class OpcionHotelController extends Controller
{
    // POST opciones-hotel — alta de un hotel ad-hoc + su matriz de
    // precios por tipo de habitación, tipeado a mano desde la pestaña
    // Local del cotizador (consumido por
    // AlternativaItemController::crearItemProveedor() vía
    // opcion_hotel_tarifa_id, ver esa sesión). Sin FK propia hacia la
    // alternativa a propósito — se referencia hacia adelante solo a
    // través de los alternativa_items que lo usan, igual que cualquier
    // ProveedorTarifa real.
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre_hotel' => 'required|string|max:250',
            'categoria_estrellas' => 'nullable|integer|min:1|max:5',
            'proveedor_id' => 'nullable|integer|exists:proveedores,id',
            'moneda' => 'required|in:PEN,USD',
            'tarifas' => 'nullable|array',
            'tarifas.*.tipo_habitacion' => 'required_with:tarifas|in:simple,matrimonial,doble,triple,familiar',
            'tarifas.*.precio_costo' => 'required_with:tarifas|numeric|min:0',
            'tarifas.*.precio_venta' => 'required_with:tarifas|numeric|min:0',
            'tarifas.*.tip_afe_igv' => 'nullable|string|in:10,20,30',
            'tarifas.*.destino_tributario' => 'nullable|string|in:amazonia,nacional,extranjero',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $hotel = DB::transaction(function () use ($validado) {
            $hotel = OpcionHotel::create([
                'opcion_mayorista_id' => null,
                'proveedor_id' => $validado['proveedor_id'] ?? null,
                'nombre_hotel' => $validado['nombre_hotel'],
                'categoria_estrellas' => $validado['categoria_estrellas'] ?? null,
                'moneda' => $validado['moneda'],
            ]);

            foreach ($validado['tarifas'] ?? [] as $tarifa) {
                $tratamientoTributario = self::resolverTratamientoTributario(
                    $tarifa['tip_afe_igv'] ?? null,
                    $tarifa['destino_tributario'] ?? null
                );

                OpcionHotelTarifa::create([
                    'opcion_hotel_id' => $hotel->id,
                    'tipo_habitacion' => $tarifa['tipo_habitacion'],
                    'precio_costo' => $tarifa['precio_costo'],
                    'precio_venta' => $tarifa['precio_venta'],
                    'tip_afe_igv' => $tratamientoTributario['tip_afe_igv'],
                    'destino_tributario' => $tratamientoTributario['destino_tributario'],
                ]);
            }

            return $hotel;
        });

        $hotel->load('opcionesHotelTarifas');

        return response()->json(['code' => 200, 'message' => 'Hotel agregado correctamente', 'opcion_hotel' => $hotel]);
    }

    // POST opciones-hotel/{id}/promover — Ronda 4/P12. Promueve TODA la
    // matriz de un hotel ad-hoc a Proveedor real de una sola vez (1
    // Proveedor + 1 ProveedorServicio + N ProveedorTarifa, una por cada
    // OpcionHotelTarifa existente) — NO reusa
    // AlternativaItemController::promoverAProveedor(), que solo
    // promueve una línea suelta de un ítem manual. Sin relink
    // retroactivo: los alternativa_items que ya usan este hotel siguen
    // apuntando a opcion_hotel_tarifa_id exactamente igual, el
    // Proveedor nuevo queda disponible recién para la próxima
    // cotización — mismo criterio ya establecido por la promoción de
    // ítem manual.
    public function promover(Request $request, string $id)
    {
        $hotel = OpcionHotel::with('opcionesHotelTarifas')->findOrFail($id);

        if ($hotel->proveedor_promovido_id) {
            return response()->json(['code' => 422, 'message' => 'Este hotel ya fue promovido a proveedor.'], 422);
        }

        if ($hotel->opcionesHotelTarifas->isEmpty()) {
            return response()->json(['code' => 422, 'message' => 'Este hotel no tiene ninguna tarifa cargada para promover.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'destino_servicio_id' => 'required|integer|exists:destino_servicio,id',
            'razon_social' => 'required|string|max:250',
            'tipo_documento' => 'nullable|in:DNI,RUC',
            'numero_documento' => 'nullable|string|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $v = $validator->validated();

        $destinoServicio = DestinoServicio::with('servicio')->findOrFail($v['destino_servicio_id']);
        $tipoId = $destinoServicio->servicio->tipo_proveedor_id;

        if (! $tipoId) {
            return response()->json([
                'code' => 422,
                'message' => 'El servicio elegido no tiene tipo de proveedor configurado — asignalo en el catálogo de Servicios antes de promover.',
            ], 422);
        }

        $tipoHabilitado = ProveedorTipoConfig::where('proveedor_tipo_id', $tipoId)->where('habilitado', true)->exists();
        if (! $tipoHabilitado) {
            return response()->json([
                'code' => 422,
                'message' => 'El tipo de proveedor de ese servicio no está habilitado para este negocio.',
            ], 422);
        }

        [$proveedor, $tarifas] = DB::transaction(function () use ($hotel, $v, $destinoServicio, $tipoId) {
            $proveedor = Proveedor::create([
                'razon_social' => $v['razon_social'],
                'tipo_documento' => $v['tipo_documento'] ?? null,
                'numero_documento' => $v['numero_documento'] ?? null,
                'tipo_id' => $tipoId,
                'estado' => true,
            ]);

            $proveedorServicio = ProveedorServicio::create([
                'proveedor_id' => $proveedor->id,
                'destino_servicio_id' => $destinoServicio->id,
            ]);

            $tarifas = $hotel->opcionesHotelTarifas->map(function (OpcionHotelTarifa $t) use ($proveedorServicio, $hotel) {
                // Defensivo — una OpcionHotelTarifa de antes de la
                // migración de M3 podría tener tributario null todavía
                // (sin backfill retroactivo); resolverTratamientoTributario()
                // solo rellena lo que falte, no pisa nada ya cargado.
                $tratamientoTributario = self::resolverTratamientoTributario($t->tip_afe_igv, $t->destino_tributario);

                return ProveedorTarifa::create([
                    'proveedor_servicio_id' => $proveedorServicio->id,
                    'tipo_tarifa' => 'publica',
                    'modalidad' => 'privado',
                    'moneda' => $hotel->moneda,
                    'precio_costo' => $t->precio_costo,
                    'margen_tipo' => 'fijo',
                    'margen_valor' => round((float) $t->precio_venta - (float) $t->precio_costo, 2),
                    'precio_venta_adulto' => $t->precio_venta,
                    'tipo_habitacion' => $t->tipo_habitacion,
                    'vigente_desde' => now()->toDateString(),
                    'tip_afe_igv' => $tratamientoTributario['tip_afe_igv'],
                    'destino_tributario' => $tratamientoTributario['destino_tributario'],
                ]);
            });

            $hotel->update(['proveedor_promovido_id' => $proveedor->id]);

            return [$proveedor, $tarifas];
        });

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor creado — las cotizaciones existentes con este hotel no cambiaron, queda disponible para próximas cotizaciones.',
            'proveedor' => $proveedor,
            'proveedor_tarifas' => $tarifas,
        ]);
    }

    // Mismo criterio que AlternativaItemController::resolverTratamientoTributario()
    // (privado ahí, replicado acá — misma familia de 6 líneas que ya se
    // repite en 2-3 lugares del vertical, no amerita una clase compartida
    // por esto solo).
    private static function resolverTratamientoTributario(?string $tipAfeIgv, ?string $destinoTributario): array
    {
        if ($tipAfeIgv !== null && $destinoTributario !== null) {
            return ['tip_afe_igv' => $tipAfeIgv, 'destino_tributario' => $destinoTributario];
        }

        $default = ConfiguracionAgencia::tratamientoTributarioDefault();

        return [
            'tip_afe_igv' => $tipAfeIgv ?? $default['tip_afe_igv'],
            'destino_tributario' => $destinoTributario ?? $default['destino_tributario'],
        ];
    }
}
