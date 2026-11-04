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
use App\Services\AgenciaViajes\PriceEngineService;
use App\Services\Crm\AccionesCrm;
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
            // T-045: obligatoria (sin fecha el servicio no sale en el Reporte
            // operativo). Se acepta una fecha pasada: venta cargada tarde.
            'fecha_servicio' => 'required|date',
            'origen_tipo' => 'required|in:proveedor,manual',
            'pax' => 'required|array|min:1',
            'pax.*.edad' => 'required|integer|min:0|max:120',
            'proveedor_tarifa_id' => 'required_if:origen_tipo,proveedor|integer|exists:proveedor_tarifas,id',
            'modo_precio' => 'required_if:origen_tipo,proveedor|in:por_persona,tarifa_fija',
            'cantidad' => 'nullable|integer|min:1',
            'descripcion_manual' => 'required_if:origen_tipo,manual|string|max:500',
            'precio_venta_snapshot' => 'required_if:origen_tipo,manual|numeric|min:0',
            // crearItemManual() lo exige desde la Sesión 11q — antes no
            // estaba en estas reglas, validated() lo descartaba y todo
            // "Concepto libre" fallaba con 422.
            'costo_snapshot' => 'required_if:origen_tipo,manual|numeric|min:0',
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
                    'origen' => Cotizacion::ORIGEN_VENTA_DIRECTA,
                    // Fase 1b (§3.3) — mismo criterio que CotizacionController::store().
                    'vendedor_id' => auth()->id(),
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
                //
                // cantidad: crearItemManual() la exige (1 si no vino).
                // dia_referencial=1: sin él, crearReservaDesdeAlternativa()
                // deja reserva_items.fecha en NULL aunque haya
                // fecha_servicio, y el servicio no aparece en el Reporte
                // Operativo ni se engancha a una Salida Operativa.
                $itemRequest = Request::create('/', 'POST', [
                    ...$validado,
                    'cantidad' => $validado['cantidad'] ?? 1,
                    'dia_referencial' => 1,
                ]);
                $itemResponse = app(AlternativaItemController::class)->store($itemRequest, (string) $alternativa->id);
                $itemPayload = $itemResponse->getData(true);

                if (($itemPayload['code'] ?? null) !== 200) {
                    abort(422, $itemPayload['message'] ?? 'No se pudo crear el ítem de la venta directa.');
                }

                $alternativa->update(['estado' => 'aceptada']);
                AlternativaController::descartarOtras($alternativa); // no-op acá (única alternativa) — mismo criterio de reuso que ReservaController::aceptar()
                app(AccionesCrm::class)->cerrarPendientesDeCotizacion($alternativa->cotizacion_id, 'Cotización aceptada'); // T-051: ídem, no-op en una cotización recién creada

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

    // T-045 — total en vivo de Venta directa para una tarifa de proveedor.
    // NO guarda nada (ni cotización, ni correlativo, ni lock). Vive en el
    // backend porque clasificar a los pasajeros por edad exige
    // ConfiguracionAgencia, que un vendedor no puede leer. Devuelve las dos
    // opciones (por persona / por unidad) en la moneda de la tarifa, con la
    // misma fórmula que crearItemProveedor() (PriceEngineService).
    public function vistaPrevia(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pax' => 'required|array|min:1',
            'pax.*.edad' => 'required|integer|min:0|max:120',
            'proveedor_tarifa_id' => 'required|integer|exists:proveedor_tarifas,id',
            'cantidad' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $tarifa = ProveedorTarifa::findOrFail($validado['proveedor_tarifa_id']);
        $motor = app(PriceEngineService::class);

        if ($motivo = $motor->motivoTarifaNoUsable($tarifa)) {
            return response()->json(['code' => 422, 'message' => $motivo], 422);
        }

        [$edadMaxInfante, $edadMaxNino] = $this->umbralesEdad();
        $conteo = ['adulto' => 0, 'nino' => 0, 'infante' => 0];
        foreach ($validado['pax'] as $pax) {
            $conteo[$this->derivarTipoPax((int) $pax['edad'], $edadMaxInfante, $edadMaxNino)]++;
        }

        $cantidad = $validado['cantidad'] ?? 1;
        $moneda = $tarifa->moneda;

        $ventaPorPersona = $motor->ventaYCostoDeTarifa($tarifa, $conteo, 'por_persona')['venta'];
        $ventaFija = $motor->ventaYCostoDeTarifa($tarifa, $conteo, 'tarifa_fija')['venta'];

        $precios = [
            'adulto' => (float) $tarifa->precio_venta_adulto,
            'nino' => (float) ($tarifa->precio_venta_nino ?? 0),
            'infante' => (float) ($tarifa->precio_venta_infante ?? 0),
        ];
        $desglose = [];
        foreach ($conteo as $tipo => $n) {
            $desglose[$tipo] = ['cantidad' => $n, 'precio' => $precios[$tipo]];
        }

        return response()->json([
            'code' => 200,
            'moneda' => $moneda,
            'por_persona' => [
                // cantidad no interviene en por persona (igual que getTotalConvertidoAttribute()).
                'total' => $motor->totalDeLinea($ventaPorPersona, $moneda, $moneda, 1, 'por_persona', 1),
                'desglose' => $desglose,
            ],
            'tarifa_fija' => [
                'total' => $motor->totalDeLinea($ventaFija, $moneda, $moneda, 1, 'tarifa_fija', $cantidad),
                'precio_unitario' => round($ventaFija, 2),
                'cantidad' => $cantidad,
            ],
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
