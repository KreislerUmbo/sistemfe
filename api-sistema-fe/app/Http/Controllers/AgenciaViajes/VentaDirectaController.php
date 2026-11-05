<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\ConfiguracionCodigo;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Services\AgenciaViajes\CodigoGeneradorService;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\Client\Client;
use App\Services\AgenciaViajes\CriterioTributarioService;
use App\Services\AgenciaViajes\PasajeroCatalogoService;
use App\Services\AgenciaViajes\PriceEngineService;
use App\Services\Crm\AccionesCrm;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Atajo de §4.1 — un solo formulario arma en un solo paso la misma cadena
// que el flujo completo: cotización (header) → cotizacion_pasajeros → 1
// alternativa directo a 'aceptada' → N alternativa_items → reserva →
// reserva_pasajeros → reserva_items. Reusa AlternativaItemController::
// store() (misma validación/cálculo que el cotizador normal) y
// ReservaController::crearReservaDesdeAlternativa() — no duplica ninguna de
// las dos lógicas.
//
// T-085 (D-AGE-034): la venta lleva varias líneas (`lineas[]`), cada una con
// su día del viaje; una sola moneda por venta, sin conversión; un hotel se
// cobra por noches. Un payload viejo (campos de una sola línea en la raíz)
// sigue funcionando como una línea con día 1 y la moneda de su tarifa.
//
// Solo soporta origen_tipo 'proveedor' y 'manual': 'mayorista' necesita una
// opcion_mayorista elegida de antemano y 'pasaje_aereo' su propio submodelo
// (cotizacion_pasaje_aereo) — ninguno de los dos cabe en un atajo de un
// solo paso. Vender uno de esos sueltos sigue pasando por el flujo completo
// del cotizador.
class VentaDirectaController extends Controller
{
    private const MAX_LINEAS = 20;

    private const MAX_DIA = 14;

    private const NOMBRE_MONEDA = ['PEN' => 'soles', 'USD' => 'dólares'];

    private const CAMPOS_LINEA = [
        'origen_tipo', 'proveedor_tarifa_id', 'modo_precio', 'cantidad',
        'descripcion_manual', 'precio_venta_snapshot', 'costo_snapshot', 'moneda_costo',
    ];

    public function store(Request $request)
    {
        [$datos, $legacy] = $this->datosConLineas($request);

        $validator = Validator::make($datos, [
            'cliente_id' => 'required|integer|exists:clients,id',
            'destino' => 'required_without:destino_atractivo_id|nullable|string|max:250',
            'destino_atractivo_id' => 'nullable|integer|exists:destinos_atractivos,id',
            // T-045: obligatoria (sin fecha el servicio no sale en el Reporte
            // operativo). Se acepta una fecha pasada: venta cargada tarde.
            // T-085: es la fecha de INICIO; cada línea suma su día.
            'fecha_servicio' => 'required|date',
            'moneda' => 'nullable|in:PEN,USD',
            'pax' => 'required|array|min:1',
            'pax.*.edad' => 'required|integer|min:0|max:120',
            // T-046: todo opcional. Sin residencia es nacional; el nombre y el documento
            // llegan a reserva_pasajeros (cotizacion_pasajeros no los tiene).
            'pax.*.residencia' => 'nullable|in:'.CotizacionPasajero::RESIDENCIA_NACIONAL.','.CotizacionPasajero::RESIDENCIA_EXTRANJERO,
            'pax.*.nombre' => 'nullable|string|max:250',
            'pax.*.documento' => 'nullable|string|max:50',
            'cliente_viaja' => 'nullable|boolean',
        ] + $this->reglasLineas($legacy));

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        [$edadMaxInfante, $edadMaxNino] = $this->umbralesEdad();

        try {
            [$lineas, $moneda] = $this->resolverLineas($validado['lineas'], $validado['moneda'] ?? null, $legacy);

            foreach ($lineas as $linea) {
                if ($linea['modo_precio'] === null) {
                    abort(422, "Elige cómo se cobra «{$linea['nombre']}»: por persona o por unidad.");
                }
            }

            $inicio = Carbon::parse($validado['fecha_servicio'])->startOfDay();
            $hasta = $this->fechaViajeHasta($lineas, $inicio);
            $destino = ! empty($validado['destino_atractivo_id'])
                ? DestinoAtractivo::findOrFail($validado['destino_atractivo_id'])->nombre
                : $validado['destino'];

            // T-046: el cliente viaja salvo que el vendedor lo haya quitado.
            $clienteViaja = (bool) ($validado['cliente_viaja'] ?? true);

            [$reserva, $alertaCupoExcedido, $alternativa] = DB::transaction(function () use ($validado, $lineas, $moneda, $inicio, $hasta, $destino, $edadMaxInfante, $edadMaxNino, $clienteViaja) {
                $codigo = app(CodigoGeneradorService::class)->generar('venta_directa');
                $prefijo = ConfiguracionCodigo::where('tipo', 'venta_directa')->value('prefijo');

                $cotizacion = Cotizacion::create([
                    'cliente_id' => $validado['cliente_id'],
                    'codigo_prefijo' => $prefijo,
                    'codigo' => $codigo,
                    'origen' => Cotizacion::ORIGEN_VENTA_DIRECTA,
                    // Fase 1b (§3.3) — mismo criterio que CotizacionController::store().
                    'vendedor_id' => auth()->id(),
                    'destino' => $destino,
                    'fecha_viaje_desde' => $inicio->toDateString(),
                    'fecha_viaje_hasta' => $hasta->toDateString(),
                ]);

                // Orden de creación = orden de id: es el que usa crearReservaDesdeAlternativa()
                // para armar reserva_pasajeros, y con él se alinean los nombres de más abajo.
                $cotizacionPasajeros = [];
                foreach ($validado['pax'] as $pax) {
                    $cotizacionPasajeros[] = CotizacionPasajero::create([
                        'cotizacion_id' => $cotizacion->id,
                        'edad' => $pax['edad'],
                        'tipo_pax' => $this->derivarTipoPax((int) $pax['edad'], $edadMaxInfante, $edadMaxNino),
                        'residencia' => $pax['residencia'] ?? CotizacionPasajero::RESIDENCIA_NACIONAL,
                    ]);
                }

                $alternativa = Alternativa::create([
                    'cotizacion_id' => $cotizacion->id,
                    'nombre' => 'Venta directa',
                    'estado' => 'borrador', // AlternativaItemController::store() no exige ningún estado particular
                    'moneda_cotizacion' => $moneda,
                    'tipo_cambio_aplicado' => 1, // una sola moneda por venta: nunca hay conversión
                    'tipo_cambio_origen' => 'agencia',
                    'total' => 0,
                ]);

                // Reusa AlternativaItemController::store() completo — misma
                // validación y cálculo de costo/precio que el cotizador
                // normal, nada reimplementado acá. Request::create() con
                // método POST para que Request::input()/all() lean el
                // payload de la bolsa 'request' (POST), no de 'query'.
                //
                // dia_referencial: la fecha del servicio la calcula
                // ReservaItemCreacionService (inicio + día − 1); sin él,
                // reserva_items.fecha queda NULL y el servicio no aparece en
                // el Reporte Operativo ni se engancha a una Salida Operativa.
                foreach ($lineas as $linea) {
                    $itemResponse = app(AlternativaItemController::class)
                        ->store(Request::create('/', 'POST', $linea['payload']), (string) $alternativa->id);
                    $itemPayload = $itemResponse->getData(true);

                    if (($itemPayload['code'] ?? null) !== 200) {
                        abort(422, $itemPayload['message'] ?? 'No se pudo crear el ítem de la venta directa.');
                    }
                }

                $alternativa->update(['estado' => 'aceptada']);
                AlternativaController::descartarOtras($alternativa); // no-op acá (única alternativa) — mismo criterio de reuso que ReservaController::aceptar()
                app(AccionesCrm::class)->cerrarPendientesDeCotizacion($alternativa->cotizacion_id, 'Cotización aceptada'); // T-051: ídem, no-op en una cotización recién creada

                // T-046: el cliente (persona natural) va de primer adulto, igual que al aceptar una
                // cotización (T-017). Si el vendedor escribió a alguien en ese lugar, manda lo que
                // escribió y la ficha del cliente no se crea.
                $primerAdulto = collect($cotizacionPasajeros)->search(fn (CotizacionPasajero $c) => $c->tipo_pax === 'adulto');
                $escritoEnElPrimero = $primerAdulto !== false && $this->tieneDatos($validado['pax'][$primerAdulto]);
                $pasajeroPorDefecto = $clienteViaja && ! $escritoEnElPrimero
                    ? app(PasajeroCatalogoService::class)->pasajeroPorDefecto(Client::find($validado['cliente_id']))
                    : null;

                [$reserva, $alertaCupoExcedido] = app(ReservaController::class)
                    ->crearReservaDesdeAlternativa($alternativa->fresh(), [], $pasajeroPorDefecto);

                $this->guardarNombres($reserva, $cotizacionPasajeros, $validado['pax']);

                return [$reserva, $alertaCupoExcedido, $alternativa->fresh()];
            });
        } catch (HttpException $e) {
            // El error real lo lanzó una validación de arriba o
            // AlternativaItemController::store() (via abort()) — se
            // reformatea acá al mismo {code, message} que usa el resto de esta
            // API (el render por defecto de Laravel para HttpException no
            // lleva 'code').
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

    private function tieneDatos(array $pax): bool
    {
        return filled($pax['nombre'] ?? null) || filled($pax['documento'] ?? null);
    }

    // T-046: los nombres opcionales van a los reserva_pasajeros recién creados (cotizacion_pasajeros
    // no tiene nombre ni documento), alineados por su cotizacion_pasajero_id. Con nombre y documento
    // se sincroniza el catálogo por el mismo camino que ReservaPasajeroController. Una fila sin
    // datos queda como está (null, o el cliente si le tocó).
    private function guardarNombres($reserva, array $cotizacionPasajeros, array $pax): void
    {
        $catalogo = app(PasajeroCatalogoService::class);

        foreach ($cotizacionPasajeros as $i => $cotizacionPasajero) {
            if (! $this->tieneDatos($pax[$i])) {
                continue;
            }

            $reservaPasajero = ReservaPasajero::where('reserva_id', $reserva->id)
                ->where('cotizacion_pasajero_id', $cotizacionPasajero->id)
                ->firstOrFail();
            $reservaPasajero->update([
                'nombre' => filled($pax[$i]['nombre'] ?? null) ? trim($pax[$i]['nombre']) : null,
                'documento' => filled($pax[$i]['documento'] ?? null) ? trim($pax[$i]['documento']) : null,
            ]);

            if ($reservaPasajero->nombre && $reservaPasajero->documento) {
                $catalogo->sincronizarDesdeReserva($reservaPasajero);
            }
        }
    }

    // T-045 / T-085 — total en vivo de Venta directa. NO guarda nada (ni
    // cotización, ni correlativo, ni lock). Vive en el backend porque
    // clasificar a los pasajeros por edad exige ConfiguracionAgencia, que un
    // vendedor no puede leer, y porque la afectación y el guardia tributario
    // son del backend (CriterioTributarioService, el mismo de la facturación).
    //
    // Con `lineas[]` devuelve el total de cada línea y el general, la fecha de
    // cada una, el hotel (noches y salida) y si lo armado se podrá facturar en
    // un solo comprobante. Sin `lineas` (payload de T-045: una tarifa suelta)
    // devuelve las dos opciones de esa tarifa, como siempre.
    public function vistaPrevia(Request $request)
    {
        if (! $request->has('lineas')) {
            return $this->vistaPreviaTarifaSuelta($request);
        }

        $validator = Validator::make($request->all(), [
            'fecha_servicio' => 'nullable|date',
            'moneda' => 'nullable|in:PEN,USD',
            'pax' => 'required|array|min:1',
            'pax.*.edad' => 'required|integer|min:0|max:120',
            'pax.*.residencia' => 'nullable|in:'.CotizacionPasajero::RESIDENCIA_NACIONAL.','.CotizacionPasajero::RESIDENCIA_EXTRANJERO,
        ] + $this->reglasLineas(false));

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $motor = app(PriceEngineService::class);

        try {
            [$lineas, $moneda] = $this->resolverLineas($validado['lineas'], $validado['moneda'] ?? null, false);
        } catch (HttpException $e) {
            return response()->json(['code' => $e->getStatusCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }

        [$edadMaxInfante, $edadMaxNino] = $this->umbralesEdad();
        $conteo = ['adulto' => 0, 'nino' => 0, 'infante' => 0];
        $extranjeros = $conteo; // T-046: cuántos de cada tipo son extranjeros (lo usa T-047 para el precio del tour)
        foreach ($validado['pax'] as $pax) {
            $tipo = $this->derivarTipoPax((int) $pax['edad'], $edadMaxInfante, $edadMaxNino);
            $conteo[$tipo]++;
            if (($pax['residencia'] ?? null) === CotizacionPasajero::RESIDENCIA_EXTRANJERO) {
                $extranjeros[$tipo]++;
            }
        }

        $inicio = ! empty($validado['fecha_servicio']) ? Carbon::parse($validado['fecha_servicio'])->startOfDay() : null;
        $salida = [];
        $total = 0.0;
        $totalCompleto = true;

        foreach ($lineas as $i => $linea) {
            $fila = [
                'indice' => $i,
                'origen_tipo' => $linea['origen_tipo'],
                'es_hotel' => $linea['es_hotel'],
                'noches' => $linea['es_hotel'] ? $linea['cantidad'] : null,
                'fecha' => $inicio?->copy()->addDays($linea['dia'] - 1)->toDateString(),
                'fecha_salida' => ($linea['es_hotel'] && $inicio)
                    ? $inicio->copy()->addDays($linea['dia'] - 1 + $linea['cantidad'])->toDateString()
                    : null,
                'tip_afe_igv' => $linea['tratamiento']['tip_afe_igv'],
                'destino_tributario' => $linea['tratamiento']['destino_tributario'],
                'total' => null,
            ];

            if ($linea['origen_tipo'] === 'manual') {
                $fila['total'] = $motor->totalDeLinea((float) $linea['precio_venta'], $moneda, $moneda, 1, 'tarifa_fija', $linea['cantidad']);
            } else {
                $tarifa = $linea['tarifa'];
                $ventaPorPersona = $motor->ventaYCostoDeTarifa($tarifa, $conteo, 'por_persona')['venta'];
                $ventaFija = $motor->ventaYCostoDeTarifa($tarifa, $conteo, 'tarifa_fija')['venta'];
                $porPersona = $motor->totalDeLinea($ventaPorPersona, $moneda, $moneda, 1, 'por_persona', 1);
                $fija = $motor->totalDeLinea($ventaFija, $moneda, $moneda, 1, 'tarifa_fija', $linea['cantidad']);

                // Un hotel no ofrece "por persona": sale solo la tarifa fija.
                if (! $linea['es_hotel']) {
                    $fila['por_persona'] = ['total' => $porPersona, 'desglose' => $this->desglose($tarifa, $conteo, $extranjeros)];
                }
                $fila['tarifa_fija'] = [
                    'total' => $fija,
                    'precio_unitario' => round($ventaFija, 2),
                    'cantidad' => $linea['cantidad'],
                ];
                $fila['total'] = match ($linea['modo_precio']) {
                    'por_persona' => $porPersona,
                    'tarifa_fija' => $fija,
                    default => null, // modo sin elegir: el vendedor todavía tiene que decidir
                };
            }

            $totalCompleto = $totalCompleto && $fila['total'] !== null;
            $total += (float) ($fila['total'] ?? 0);
            $salida[] = $fila;
        }

        return response()->json([
            'code' => 200,
            'moneda' => $moneda,
            // T-046: los rangos de edad que la pantalla muestra bajo cada grupo. Salen acá
            // porque GET configuracion-agencia pide un permiso que el vendedor no tiene.
            'umbrales' => ['infante_max' => $edadMaxInfante, 'nino_max' => $edadMaxNino],
            'total' => $totalCompleto ? round($total, 2) : null,
            'fecha_viaje_hasta' => $inicio ? $this->fechaViajeHasta($lineas, $inicio)->toDateString() : null,
            'lineas' => $salida,
            'facturable' => $this->facturable($lineas),
        ]);
    }

    // ¿Se podrá facturar lo armado en un solo comprobante? Mismo criterio que
    // el guardia de la facturación de reservas. La confirmación de la
    // Amazonía no se pide acá (la hace el cobro, T-048): todo cuenta como
    // confirmado, y `requiere_confirmar_amazonia` avisa que hará falta.
    private function facturable(array $lineas): array
    {
        $neutrales = collect($lineas)->map(fn (array $l, int $i) => [
            'id' => $i,
            'destino' => $l['tratamiento']['destino_tributario'],
            'carga_tour' => null, // las cargas de tour llegan con T-047
            'confirmado' => true,
        ]);

        $bloqueo = app(CriterioTributarioService::class)->evaluar($neutrales);

        return [
            'ok' => $bloqueo === null,
            'motivo' => $bloqueo['motivo'] ?? null,
            'requiere_confirmar_amazonia' => $neutrales->contains(fn (array $l) => $l['destino'] === 'amazonia'),
        ];
    }

    // Payload de T-045: una tarifa suelta → las dos opciones (por persona /
    // por unidad) en la moneda de la tarifa.
    private function vistaPreviaTarifaSuelta(Request $request)
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

        return response()->json([
            'code' => 200,
            'moneda' => $moneda,
            'por_persona' => [
                // cantidad no interviene en por persona (igual que getTotalConvertidoAttribute()).
                'total' => $motor->totalDeLinea($ventaPorPersona, $moneda, $moneda, 1, 'por_persona', 1),
                'desglose' => $this->desglose($tarifa, $conteo),
            ],
            'tarifa_fija' => [
                'total' => $motor->totalDeLinea($ventaFija, $moneda, $moneda, 1, 'tarifa_fija', $cantidad),
                'precio_unitario' => round($ventaFija, 2),
                'cantidad' => $cantidad,
            ],
        ]);
    }

    private function desglose(ProveedorTarifa $tarifa, array $conteo, ?array $extranjeros = null): array
    {
        $precios = [
            'adulto' => (float) $tarifa->precio_venta_adulto,
            'nino' => (float) ($tarifa->precio_venta_nino ?? 0),
            'infante' => (float) ($tarifa->precio_venta_infante ?? 0),
        ];
        $desglose = [];
        foreach ($conteo as $tipo => $n) {
            $desglose[$tipo] = ['cantidad' => $n, 'precio' => $precios[$tipo]]
                + ($extranjeros === null ? [] : ['extranjeros' => $extranjeros[$tipo]]);
        }

        return $desglose;
    }

    // Un payload sin `lineas` es el de antes de T-085: los campos de una
    // sola línea en la raíz. Se arma como una línea con día 1.
    private function datosConLineas(Request $request): array
    {
        $datos = $request->all();
        if (array_key_exists('lineas', $datos)) {
            return [$datos, false];
        }

        $datos['lineas'] = [$request->only(self::CAMPOS_LINEA) + ['dia' => 1]];

        return [$datos, true];
    }

    private function reglasLineas(bool $legacy): array
    {
        $manual = 'required_if:lineas.*.origen_tipo,manual';

        return [
            'lineas' => 'required|array|min:1|max:'.self::MAX_LINEAS,
            'lineas.*.origen_tipo' => 'required|in:proveedor,manual',
            'lineas.*.dia' => 'required|integer|min:1|max:'.self::MAX_DIA,
            'lineas.*.proveedor_tarifa_id' => 'required_if:lineas.*.origen_tipo,proveedor|integer|exists:proveedor_tarifas,id',
            'lineas.*.modo_precio' => ($legacy ? 'required_if:lineas.*.origen_tipo,proveedor|' : 'nullable|').'in:por_persona,tarifa_fija',
            'lineas.*.cantidad' => 'nullable|integer|min:1',
            'lineas.*.descripcion_manual' => $manual.'|string|max:500',
            'lineas.*.precio_venta_snapshot' => $manual.'|numeric|min:0',
            // crearItemManual() lo exige desde la Sesión 11q.
            'lineas.*.costo_snapshot' => $manual.'|numeric|min:0',
            // Con `lineas` la moneda es la de la venta; solo el payload viejo la manda por línea.
            'lineas.*.moneda_costo' => ($legacy ? $manual : 'nullable').'|in:PEN,USD',
        ];
    }

    // Normaliza las líneas ya validadas: resuelve la tarifa, detecta el hotel,
    // fuerza modo y cantidad, aplica la regla de una sola moneda y trae el
    // tratamiento tributario con que se creará cada ítem. Lanza HttpException
    // 422 con el motivo si alguna línea no se puede vender. Devuelve
    // [lineas, moneda de la venta].
    private function resolverLineas(array $lineas, ?string $monedaVenta, bool $legacy): array
    {
        $motor = app(PriceEngineService::class);
        $resueltas = [];

        foreach ($lineas as $linea) {
            $esProveedor = $linea['origen_tipo'] === 'proveedor';
            $cantidad = (int) ($linea['cantidad'] ?? 1);
            $item = [
                'origen_tipo' => $linea['origen_tipo'],
                'cantidad' => $cantidad,
                'dia_referencial' => (int) $linea['dia'],
            ];

            if ($esProveedor) {
                $tarifa = ProveedorTarifa::with('proveedorServicio.proveedor', 'proveedorServicio.destinoServicio.servicio')
                    ->findOrFail($linea['proveedor_tarifa_id']);

                if ($motivo = $motor->motivoTarifaNoUsable($tarifa)) {
                    abort(422, $motivo);
                }

                $nombre = $this->nombreDeTarifa($tarifa);
                // Misma señal que ReporteOperativoController::hotelDelItem() y la pantalla.
                $esHotel = filled($tarifa->tipo_habitacion);
                $modo = $linea['modo_precio'] ?? null;

                if ($esHotel) {
                    // Hotel (D-AGE-034): precio × noches; `cantidad` = noches.
                    if ($modo === 'por_persona') {
                        abort(422, "«{$nombre}» es un hotel: se cobra por noche, no por persona.");
                    }
                    $modo = 'tarifa_fija';
                } elseif ($modo === 'por_persona') {
                    $cantidad = 1; // la cantidad no cuenta en por persona
                }

                $item += ['proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => $modo, 'cantidad' => $cantidad];
                $resueltas[] = [
                    'origen_tipo' => 'proveedor',
                    'dia' => (int) $linea['dia'],
                    'tarifa' => $tarifa,
                    'nombre' => $nombre,
                    'es_hotel' => $esHotel,
                    'modo_precio' => $modo,
                    'cantidad' => $cantidad,
                    'moneda' => $tarifa->moneda,
                    'tratamiento' => AlternativaItemController::resolverTratamientoTributario($tarifa->tip_afe_igv, $tarifa->destino_tributario),
                    'payload' => $item,
                ];
            } else {
                $item += [
                    'descripcion_manual' => $linea['descripcion_manual'],
                    'precio_venta_snapshot' => $linea['precio_venta_snapshot'],
                    'costo_snapshot' => $linea['costo_snapshot'],
                ];
                $resueltas[] = [
                    'origen_tipo' => 'manual',
                    'dia' => (int) $linea['dia'],
                    'tarifa' => null,
                    'nombre' => $linea['descripcion_manual'],
                    'es_hotel' => false,
                    'modo_precio' => 'tarifa_fija',
                    'cantidad' => $cantidad,
                    'moneda' => $linea['moneda_costo'] ?? null, // null = la de la venta
                    'precio_venta' => (float) $linea['precio_venta_snapshot'],
                    // El concepto libre toma el tratamiento por defecto de la agencia, como hoy.
                    'tratamiento' => AlternativaItemController::resolverTratamientoTributario(null, null),
                    'payload' => $item,
                ];
            }
        }

        // Payload viejo: la moneda es la de su (única) línea, como siempre.
        // Con `lineas`: la que eligió el vendedor (soles por defecto), sin convertir nada.
        $moneda = $legacy ? $resueltas[0]['moneda'] : ($monedaVenta ?? 'PEN');

        foreach ($resueltas as &$r) {
            if ($r['origen_tipo'] === 'manual') {
                $r['moneda'] = $legacy ? $r['moneda'] : $moneda;
                $r['payload']['moneda_costo'] = $r['moneda'];
            } elseif ($r['moneda'] !== $moneda) {
                abort(422, 'La venta es en '.self::NOMBRE_MONEDA[$moneda]." y «{$r['nombre']}» está en ".self::NOMBRE_MONEDA[$r['moneda']].'. No se mezclan monedas.');
            }
        }
        unset($r);

        return [$resueltas, $moneda];
    }

    private function nombreDeTarifa(ProveedorTarifa $tarifa): string
    {
        $proveedor = $tarifa->proveedorServicio?->proveedor;

        return $tarifa->proveedorServicio?->destinoServicio?->servicio?->nombre
            ?? $proveedor?->nombre_comercial
            ?? $proveedor?->razon_social
            ?? 'este servicio';
    }

    // La fecha más tardía entre los servicios (inicio + día − 1) y la salida de
    // cada hotel (+ sus noches).
    private function fechaViajeHasta(array $lineas, Carbon $inicio): Carbon
    {
        $hasta = $inicio->copy();
        foreach ($lineas as $linea) {
            $fecha = $inicio->copy()->addDays($linea['dia'] - 1 + ($linea['es_hotel'] ? $linea['cantidad'] : 0));
            if ($fecha->gt($hasta)) {
                $hasta = $fecha;
            }
        }

        return $hasta;
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
