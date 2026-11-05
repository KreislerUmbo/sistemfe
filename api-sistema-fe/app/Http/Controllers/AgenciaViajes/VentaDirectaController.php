<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\ConfiguracionCodigo;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Services\AgenciaViajes\ComboExplosionService;
use App\Services\AgenciaViajes\TourLineaException;
use App\Services\AgenciaViajes\TourLineaService;
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
// T-047: también vende un tour del catálogo (`origen_tipo='tour'` +
// `paquete_plantilla_id`). Entra a la alternativa por el MISMO camino que el
// cotizador (AlternativaItemController::desdePlantilla(): respeta modo_tours) y
// al crear la reserva se engancha a la salida de su fecha como al aceptar una
// cotización. Un combo (paquete_combo) no se vende acá (D-AGE-034).
//
// Soporta origen_tipo 'proveedor', 'manual' y 'tour': 'mayorista' necesita una
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
        'origen_tipo', 'paquete_plantilla_id', 'proveedor_tarifa_id', 'modo_precio', 'cantidad',
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
            $inicio = Carbon::parse($validado['fecha_servicio'])->startOfDay();
            [$lineas, $moneda] = $this->resolverLineas($validado['lineas'], $validado['moneda'] ?? null, $legacy, $inicio);

            foreach ($lineas as $linea) {
                if ($linea['modo_precio'] === null) {
                    abort(422, "Elige cómo se cobra «{$linea['nombre']}»: por persona o por unidad.");
                }
            }

            $hasta = $this->fechaViajeHasta($lineas, $inicio);
            $destino = ! empty($validado['destino_atractivo_id'])
                ? DestinoAtractivo::findOrFail($validado['destino_atractivo_id'])->nombre
                : $validado['destino'];

            // T-046: el cliente viaja salvo que el vendedor lo haya quitado.
            $clienteViaja = (bool) ($validado['cliente_viaja'] ?? true);

            [$reserva, $alertaCupoExcedido, $alternativa, $avisos] = DB::transaction(function () use ($validado, $lineas, $moneda, $inicio, $hasta, $destino, $edadMaxInfante, $edadMaxNino, $clienteViaja) {
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
                $avisos = [];
                foreach ($lineas as $linea) {
                    // T-047: un tour entra por desdePlantilla() (el camino del cotizador).
                    $itemResponse = $linea['origen_tipo'] === 'tour'
                        ? app(AlternativaItemController::class)->desdePlantilla(Request::create('/', 'POST', $linea['payload']), (string) $alternativa->id)
                        : app(AlternativaItemController::class)->store(Request::create('/', 'POST', $linea['payload']), (string) $alternativa->id);
                    $itemPayload = $itemResponse->getData(true);

                    if (($itemPayload['code'] ?? null) !== 200) {
                        abort(422, $itemPayload['message'] ?? 'No se pudo crear el ítem de la venta directa.');
                    }

                    $avisos = array_merge($avisos, $itemPayload['avisos'] ?? []);
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

                return [$reserva, $alertaCupoExcedido, $alternativa->fresh(), $avisos];
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
            // T-042: la salida del tour puede estar cancelada; la pantalla lo avisa.
            'avisos_salida_cancelada' => SalidaOperativa::avisosCanceladas($reserva->id),
            // T-047: avisos del tour (p. ej. sin costo estimado).
            'avisos' => $avisos,
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
    // T-046 — rangos de edad de la agencia para los contadores de pasajeros («13 años o más»…).
    // Ruta propia con el permiso de la venta directa: GET configuracion-agencia pide
    // agencia.configuracion, que el vendedor no tiene, y la vista previa llega recién con un servicio.
    public function umbrales()
    {
        [$edadMaxInfante, $edadMaxNino] = $this->umbralesEdad();

        return response()->json([
            'code' => 200,
            'umbrales' => ['infante_max' => $edadMaxInfante, 'nino_max' => $edadMaxNino],
        ]);
    }

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

        $inicio = ! empty($validado['fecha_servicio']) ? Carbon::parse($validado['fecha_servicio'])->startOfDay() : null;

        try {
            [$lineas, $moneda] = $this->resolverLineas($validado['lineas'], $validado['moneda'] ?? null, false, $inicio);
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

            if ($linea['origen_tipo'] === 'tour') {
                $fila = $this->vistaPreviaTour($linea, $conteo, $extranjeros) + $fila;
            } elseif ($linea['origen_tipo'] === 'manual') {
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
        // Un tour de IGV mezclado son varias líneas de la MISMA carga (T-047): sí pueden ir juntas.
        $neutrales = collect($lineas)->flatMap(fn (array $l, int $i) => collect($l['tratamientos'] ?? [$l['tratamiento']])
            ->map(fn (array $t, int $g) => [
                'id' => "{$i}.{$g}",
                'destino' => $t['destino_tributario'],
                'carga_tour' => $l['origen_tipo'] === 'tour' ? "tour-{$i}" : null,
                'confirmado' => true,
            ]))->values();

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
            'lineas.*.origen_tipo' => 'required|in:proveedor,manual,tour',
            // T-047: validado contra la base del tenant; que sea un tour activo se revisa al resolver.
            'lineas.*.paquete_plantilla_id' => 'required_if:lineas.*.origen_tipo,tour|integer|exists:paquetes_plantilla,id',
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
    private function resolverLineas(array $lineas, ?string $monedaVenta, bool $legacy, ?Carbon $inicio = null): array
    {
        $motor = app(PriceEngineService::class);
        $resueltas = [];

        foreach ($lineas as $linea) {
            if ($linea['origen_tipo'] === 'tour') {
                $resueltas[] = $this->resolverTour($linea, $inicio);

                continue;
            }

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
            } else {
                foreach ($r['monedas'] ?? [$r['moneda']] as $monedaLinea) {
                    if ($monedaLinea !== $moneda) {
                        abort(422, 'La venta es en '.self::NOMBRE_MONEDA[$moneda]." y «{$r['nombre']}» está en ".self::NOMBRE_MONEDA[$monedaLinea].'. No se mezclan monedas.');
                    }
                }
            }
        }
        unset($r);

        return [$resueltas, $moneda];
    }

    // T-047 — un tour del catálogo como línea. Valida que se pueda vender acá y
    // deja lo necesario para la vista previa. Con modo_tours='nuevo' el precio sale
    // de TourLineaService (precio por persona, residencia y temporada de la FECHA
    // de la línea); con 'clasico', de desarmar el tour como en el cotizador.
    private function resolverTour(array $linea, ?Carbon $inicio): array
    {
        $tour = PaquetePlantilla::findOrFail($linea['paquete_plantilla_id']);
        $dia = (int) $linea['dia'];

        if ($tour->esPaqueteCombo()) {
            abort(422, 'Los combos se venden desde el cotizador.');
        }
        if (! $tour->activo) {
            abort(422, "«{$tour->nombre}» está desactivado: no se puede vender.");
        }
        if ($tour->esSoloItinerario()) {
            abort(422, "«{$tour->nombre}» es un tour solo de itinerario (de mayorista): no se puede vender.");
        }

        $base = [
            'origen_tipo' => 'tour',
            'dia' => $dia,
            'tour' => $tour,
            'nombre' => $tour->nombre,
            'es_hotel' => false,
            'modo_precio' => 'por_persona',
            'cantidad' => 1,
            'dias_extra' => max(1, (int) ($tour->paqueteItinerario()->max('dia_relativo') ?? 1)) - 1,
            'payload' => ['paquete_plantilla_id' => $tour->id, 'dia_referencial' => $dia],
        ];

        if (ConfiguracionAgencia::modoToursNuevo()) {
            try {
                $spec = app(TourLineaService::class)->specDelTour($tour, $inicio?->copy()->addDays($dia - 1)->toDateString());
            } catch (TourLineaException $e) {
                abort(422, $e->getMessage());
            }
            $tratamientos = array_map(fn (array $g) => ['tip_afe_igv' => $g['tip_afe_igv'], 'destino_tributario' => $g['destino_tributario']], $spec['grupos']);

            return $base + ['modo' => 'nuevo', 'spec' => $spec, 'moneda' => $spec['moneda'], 'tratamiento' => $tratamientos[0], 'tratamientos' => $tratamientos];
        }

        // Clásico: el tour se desarma en sus servicios, cada uno con su tarifa y su moneda.
        $entradas = app(ComboExplosionService::class)->explotarTourSimple($tour);
        $monedas = [];
        $tratamientos = [];
        foreach ($entradas as $entrada) {
            if ($entrada['proveedor_tarifa_id']) {
                $tarifa = ProveedorTarifa::findOrFail($entrada['proveedor_tarifa_id']);
                $monedas[] = $tarifa->moneda;
                $tratamientos[] = AlternativaItemController::resolverTratamientoTributario($tarifa->tip_afe_igv, $tarifa->destino_tributario);
            } elseif ($entrada['guia_tarifa_id'] && ($guia = GuiaTarifa::find($entrada['guia_tarifa_id']))) {
                $monedas[] = $guia->moneda;
                $tratamientos[] = AlternativaItemController::resolverTratamientoTributario(null, null);
            }
        }
        if ($tratamientos === []) {
            abort(422, "«{$tour->nombre}» no tiene servicios cargados: complétalo en el catálogo.");
        }

        return $base + [
            'modo' => 'clasico', 'entradas' => $entradas, 'moneda' => $monedas[0], 'monedas' => array_values(array_unique($monedas)),
            'tratamiento' => $tratamientos[0], 'tratamientos' => $tratamientos,
        ];
    }

    // T-047 — total, detalle y afectación de una línea de tour, sin guardar nada.
    // $conteo / $extranjeros: pasajeros por tipo y cuántos de ellos son extranjeros.
    private function vistaPreviaTour(array $linea, array $conteo, array $extranjeros): array
    {
        $fila = [
            'tip_afe_igv' => $linea['tratamiento']['tip_afe_igv'],
            'destino_tributario' => $linea['tratamiento']['destino_tributario'],
            'afectaciones' => $linea['tratamientos'],
        ];

        if ($linea['modo'] === 'clasico') {
            return $fila + ['total' => round($this->totalTourClasico($linea['tour'], $linea['entradas'], $conteo), 2), 'tour' => ['detalle' => [], 'temporada' => null]];
        }

        $spec = $linea['spec'];
        $porResidencia = [
            'adulto_nac' => $conteo['adulto'] - $extranjeros['adulto'], 'adulto_ext' => $extranjeros['adulto'],
            'nino_nac' => $conteo['nino'] - $extranjeros['nino'], 'nino_ext' => $extranjeros['nino'],
            'infante' => $conteo['infante'],
        ];
        $calculo = app(TourLineaService::class)->calcular([$spec], $porResidencia, null)[0];

        // «2 adultos nac. × 45 + 1 adulto ext. × 60…»: el infante figura con precio 0.
        $detalle = [];
        foreach (['adulto_nac' => 'adulto', 'adulto_ext' => 'adulto_ext', 'nino_nac' => 'nino', 'nino_ext' => 'nino_ext', 'infante' => null] as $grupo => $clavePrecio) {
            if ($porResidencia[$grupo] > 0) {
                $detalle[] = ['grupo' => $grupo, 'cantidad' => $porResidencia[$grupo], 'precio' => $clavePrecio ? $spec['precios'][$clavePrecio] : 0.0];
            }
        }

        return $fila + ['total' => round((float) $calculo['precio'], 2), 'tour' => ['detalle' => $detalle, 'temporada' => $spec['temporada_nombre']]];
    }

    // Modo clásico: la misma cuenta que AlternativaItemController::crearItemClasicoDesdeEntrada()
    // (un servicio compartido se cobra por pasajero real) más el ajuste de redondeo del tour.
    private function totalTourClasico(PaquetePlantilla $tour, array $entradas, array $conteo): float
    {
        $total = 0.0;
        foreach ($entradas as $entrada) {
            if ($entrada['proveedor_tarifa_id']) {
                $tarifa = ProveedorTarifa::findOrFail($entrada['proveedor_tarifa_id']);
                $total += ($entrada['modalidad'] === 'compartido' && $tarifa->tipo_habitacion === null)
                    ? $conteo['adulto'] * ($entrada['precio_venta_adulto'] ?? 0) + $conteo['nino'] * ($entrada['precio_venta_nino'] ?? 0) + $conteo['infante'] * ($entrada['precio_venta_infante'] ?? 0)
                    : $entrada['venta'];
            } elseif ($entrada['guia_tarifa_id'] && GuiaTarifa::find($entrada['guia_tarifa_id'])) {
                $total += $entrada['venta'];
            }
        }

        return $total + (float) ($tour->ajuste_redondeo ?? 0);
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
            $fecha = $inicio->copy()->addDays($linea['dia'] - 1 + ($linea['es_hotel'] ? $linea['cantidad'] : ($linea['dias_extra'] ?? 0)));
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
