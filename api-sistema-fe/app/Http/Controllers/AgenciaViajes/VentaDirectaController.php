<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\ConfiguracionCodigo;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Services\AgenciaViajes\CodigoGeneradorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Atajo de §4.1 — un solo formulario arma en un solo paso la misma cadena
// que el flujo completo: cotización (header) → cotizacion_pasajeros → 1
// alternativa directo a 'aceptada' → 1 alternativa_item → reserva →
// reserva_pasajeros → reserva_items. Reusa AlternativaItemController::
// store() (misma validación/cálculo que el cotizador normal) y
// ReservaController::crearReservaDesdeAlternativa() — no duplica ninguna de
// las dos lógicas.
//
// Solo soporta origen_tipo 'proveedor' y 'manual': 'mayorista' necesita una
// opcion_mayorista elegida de antemano y 'pasaje_aereo' su propio submodelo
// (cotizacion_pasaje_aereo) — ninguno de los dos cabe en un atajo de un
// solo paso. Vender uno de esos sueltos sigue pasando por el flujo completo
// del cotizador.
class VentaDirectaController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cliente_id' => 'required|integer|exists:clients,id',
            'destino' => 'required|string|max:250',
            'fecha_servicio' => 'nullable|date',
            'origen_tipo' => 'required|in:proveedor,manual',
            'pax' => 'required|array|min:1',
            'pax.*.edad' => 'required|integer|min:0|max:120',
            'proveedor_tarifa_id' => 'required_if:origen_tipo,proveedor|integer|exists:proveedor_tarifas,id',
            'modo_precio' => 'required_if:origen_tipo,proveedor|in:por_persona,tarifa_fija',
            'cantidad' => 'nullable|integer|min:1',
            'descripcion_manual' => 'required_if:origen_tipo,manual|string|max:500',
            'precio_venta_snapshot' => 'required_if:origen_tipo,manual|numeric|min:0',
            'moneda_costo' => 'required_if:origen_tipo,manual|in:PEN,USD',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        [$edadMaxInfante, $edadMaxNino] = $this->umbralesEdad();

        try {
            [$reserva, $alertaCupoExcedido, $alternativa] = DB::transaction(function () use ($validado, $edadMaxInfante, $edadMaxNino) {
                $codigo = app(CodigoGeneradorService::class)->generar('venta_directa');
                $prefijo = ConfiguracionCodigo::where('tipo', 'venta_directa')->value('prefijo');

                $cotizacion = Cotizacion::create([
                    'cliente_id' => $validado['cliente_id'],
                    'codigo_prefijo' => $prefijo,
                    'codigo' => $codigo,
                    'destino' => $validado['destino'],
                    'fecha_viaje_desde' => $validado['fecha_servicio'] ?? null,
                    'fecha_viaje_hasta' => $validado['fecha_servicio'] ?? null,
                ]);

                foreach ($validado['pax'] as $pax) {
                    CotizacionPasajero::create([
                        'cotizacion_id' => $cotizacion->id,
                        'edad' => $pax['edad'],
                        'tipo_pax' => $this->derivarTipoPax((int) $pax['edad'], $edadMaxInfante, $edadMaxNino),
                    ]);
                }

                $monedaCotizacion = $validado['origen_tipo'] === 'manual'
                    ? $validado['moneda_costo']
                    : ProveedorTarifa::findOrFail($validado['proveedor_tarifa_id'])->moneda;

                $alternativa = Alternativa::create([
                    'cotizacion_id' => $cotizacion->id,
                    'nombre' => 'Venta directa',
                    'estado' => 'borrador', // AlternativaItemController::store() no exige ningún estado particular
                    'moneda_cotizacion' => $monedaCotizacion,
                    'tipo_cambio_aplicado' => 1, // mismo moneda_costo/moneda_cotizacion siempre acá, sin conversión real
                    'tipo_cambio_origen' => 'agencia',
                    'total' => 0,
                ]);

                // Reusa AlternativaItemController::store() completo — misma
                // validación y cálculo de costo/precio que el cotizador
                // normal, nada reimplementado acá. Request::create() con
                // método POST para que Request::input()/all() lean el
                // payload de la bolsa 'request' (POST), no de 'query'.
                $itemRequest = Request::create('/', 'POST', $validado);
                $itemResponse = app(AlternativaItemController::class)->store($itemRequest, (string) $alternativa->id);
                $itemPayload = $itemResponse->getData(true);

                if (($itemPayload['code'] ?? null) !== 200) {
                    abort(422, $itemPayload['message'] ?? 'No se pudo crear el ítem de la venta directa.');
                }

                $alternativa->update(['estado' => 'aceptada']);
                AlternativaController::descartarOtras($alternativa); // no-op acá (única alternativa) — mismo criterio de reuso que ReservaController::aceptar()

                [$reserva, $alertaCupoExcedido] = app(ReservaController::class)
                    ->crearReservaDesdeAlternativa($alternativa->fresh());

                return [$reserva, $alertaCupoExcedido, $alternativa->fresh()];
            });
        } catch (HttpException $e) {
            // El error real lo lanzó AlternativaItemController::store() (via
            // abort() arriba) — se reformatea acá al mismo {code, message}
            // que usa el resto de esta API (el render por defecto de
            // Laravel para HttpException no lleva 'code').
            $status = $e->getStatusCode();

            return response()->json(['code' => $status, 'message' => $e->getMessage() ?: 'No se pudo crear la venta directa.'], $status);
        }

        $reserva->load([
            'alternativa.cotizacion.cliente',
            'pasajeros',
            'items.alternativaItem',
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Venta directa creada correctamente',
            'reserva' => $reserva,
            'alternativa' => $alternativa,
            'alerta_cupo_excedido' => $alertaCupoExcedido,
        ]);
    }

    private function derivarTipoPax(int $edad, int $edadMaxInfante, int $edadMaxNino): string
    {
        if ($edad <= $edadMaxInfante) {
            return 'infante';
        }
        if ($edad <= $edadMaxNino) {
            return 'nino';
        }

        return 'adulto';
    }

    private function umbralesEdad(): array
    {
        $config = ConfiguracionAgencia::first();

        return [
            $config->edad_max_infante ?? 2,
            $config->edad_max_nino ?? 12,
        ];
    }
}
