<?php

namespace App\Services\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// "Modificar reserva" — crear y descartar (T-002; R-AGE-01, R-AGE-02, R-AGE-10, D-AGE-030).
//
// Crear una modificación = abrir una cotización propia (origen 'modificacion') que es una
// copia de lo que la reserva tiene HOY: sus pasajeros y su alternativa vigente. El vendedor la
// edita en el cotizador de siempre; la versión vendida no se toca nunca (facturar lee el
// precio en vivo de alternativa_items, así que editarla cambiaría lo ya vendido). La diferencia
// (T-003) y aplicar (T-005) vienen después.
//
// Los errores de regla se lanzan como HttpException(422): el controlador los devuelve como
// JSON, igual que ReservaController::aceptar().
class ModificacionReservaService
{
    // Edad que se usa para un pasajero agregado en la reserva cuya fecha de nacimiento no se
    // conoce. Se deriva de los umbrales de la agencia (siempre cae en el tipo del pasajero) y
    // la respuesta lo avisa en pasajeros_edad_estimada.
    private const EDAD_ADULTO_ESTIMADA = 30;

    public function __construct(
        private AlternativaCopiaService $alternativaCopia,
        private RecalculoPorPersonaService $recalculoPorPersona,
    ) {
    }

    /**
     * @return array{
     *     modificacion: ReservaModificacion,
     *     pasajeros_edad_estimada: array<int, array<string, mixed>>,
     *     items_recalculados: int,
     *     items_para_revisar: array<int, array<string, mixed>>
     * }
     */
    public function crear(Reserva $reserva, User $usuario): array
    {
        try {
            return DB::transaction(fn () => $this->crearBajoLock($reserva, $usuario));
        } catch (QueryException $e) {
            // El índice único parcial (una sola abierta por reserva) es la red de seguridad si
            // dos creaciones se cruzan pese al lock. 23505 = unique_violation de Postgres.
            if (($e->errorInfo[0] ?? null) === '23505' && str_contains($e->getMessage(), 'reserva_modificaciones_una_abierta')) {
                throw new HttpException(422, self::MENSAJE_YA_HAY_ABIERTA);
            }

            throw $e;
        }
    }

    public const MENSAJE_YA_HAY_ABIERTA = 'Esta reserva ya tiene una modificación abierta. Continúala o descártala antes de abrir otra.';

    private function crearBajoLock(Reserva $reserva, User $usuario): array
    {
        // Lock de la fila de la reserva: dos pestañas que crean a la vez esperan su turno, y la
        // segunda ya ve la modificación de la primera. Mismo orden de locks que aceptar() y
        // cancelar() (la reserva primero).
        $reserva = Reserva::where('id', $reserva->id)->lockForUpdate()->firstOrFail();

        if ($reserva->estado !== 'activa') {
            throw new HttpException(422, 'Solo se puede modificar una reserva activa.');
        }

        if (ReservaModificacion::abierta()->where('reserva_id', $reserva->id)->exists()) {
            throw new HttpException(422, self::MENSAJE_YA_HAY_ABIERTA);
        }

        $vendida = Alternativa::findOrFail($reserva->alternativa_id);
        $cotizacionOriginal = Cotizacion::findOrFail($vendida->cotizacion_id);

        $cotizacion = $this->crearCotizacion($reserva, $cotizacionOriginal, $usuario);

        [$mapaPasajeros, $edadEstimada] = $this->copiarPasajeros($reserva, $cotizacion);

        [$nueva, $mapaItems] = $this->copiarAlternativa($vendida, $cotizacion, $mapaPasajeros['cotizacion_pasajero']);

        // Si la composición de pasajeros cambió respecto de lo vendido, los servicios por
        // persona (y las líneas de tour) se reprecian con el mismo código del cotizador.
        $itemsRecalculados = 0;
        $itemsParaRevisar = [];
        if ($this->cambioLaComposicion($cotizacionOriginal, $cotizacion)) {
            [$itemsRecalculados, $itemsParaRevisar] = $this->recalculoPorPersona->recalcular(
                Cotizacion::with('alternativas')->findOrFail($cotizacion->id)
            );
        }

        $modificacion = ReservaModificacion::create([
            'reserva_id' => $reserva->id,
            'cotizacion_id' => $cotizacion->id,
            'alternativa_vendida_id' => $vendida->id,
            'alternativa_nueva_id' => $nueva->id,
            'estado' => ReservaModificacion::ESTADO_ABIERTA,
            'creado_por' => $usuario->id,
        ]);

        return [
            'modificacion' => $modificacion->fresh(),
            'pasajeros_edad_estimada' => $edadEstimada,
            'items_recalculados' => $itemsRecalculados,
            'items_para_revisar' => $itemsParaRevisar,
        ];
    }

    // Descartar deja la cotización y la alternativa como están (registro, no se borra) y no toca
    // la reserva en nada.
    public function descartar(ReservaModificacion $modificacion, User $usuario): ReservaModificacion
    {
        return DB::transaction(function () use ($modificacion, $usuario) {
            $bloqueada = ReservaModificacion::where('id', $modificacion->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estaAbierta()) {
                throw new HttpException(422, 'Solo se puede descartar una modificación abierta.');
            }

            $bloqueada->update([
                'estado' => ReservaModificacion::ESTADO_DESCARTADA,
                'descartado_por' => $usuario->id,
                'descartado_en' => now(),
            ]);

            return $bloqueada->fresh();
        });
    }

    // Fechas de viaje: las de la RESERVA, nunca las de la cotización original (puede haber sido
    // reprogramada). El código no consume correlativo: se deriva del de la reserva.
    private function crearCotizacion(Reserva $reserva, Cotizacion $original, User $usuario): Cotizacion
    {
        $numero = ReservaModificacion::where('reserva_id', $reserva->id)->count() + 1;
        // Reservas anteriores al módulo de códigos no tienen código.
        $codigo = ($reserva->codigo ?? 'RES-'.$reserva->id).'-M'.$numero;

        if (Cotizacion::where('codigo', $codigo)->exists()) {
            throw new HttpException(422, "Ya existe una cotización con el código {$codigo}.");
        }

        return Cotizacion::create([
            'codigo_prefijo' => $original->codigo_prefijo,
            'codigo' => $codigo,
            'origen' => Cotizacion::ORIGEN_MODIFICACION,
            'cliente_id' => $original->cliente_id,
            // Quien abre la modificación es quien la edita en el cotizador: CotizacionPolicy
            // decide por vendedor_id.
            'vendedor_id' => $usuario->id,
            'destino' => $original->destino,
            'fecha_viaje_desde' => $reserva->fecha_viaje_desde,
            'fecha_viaje_hasta' => $reserva->fecha_viaje_hasta,
        ]);
    }

    /**
     * Copia los pasajeros que la reserva tiene hoy. Los vinculados a un pasajero de cotización
     * heredan de él tipo, edad y residencia; los agregados en la reserva sin vínculo usan la
     * edad del catálogo de pasajeros si hay fecha de nacimiento, o una estimada de su tipo.
     *
     * @return array{0: array{reserva_pasajero: array<int,int>, cotizacion_pasajero: array<int,int>}, 1: array<int, array<string, mixed>>}
     *         mapas reserva_pasajero_id => copia y cotizacion_pasajero_id vendido => copia; y los de edad estimada
     */
    private function copiarPasajeros(Reserva $reserva, Cotizacion $cotizacion): array
    {
        $pasajeros = $reserva->pasajeros()->with('pasajeroCatalogo')->orderBy('id')->get();
        $vinculados = CotizacionPasajero::whereIn('id', $pasajeros->pluck('cotizacion_pasajero_id')->filter())
            ->get()
            ->keyBy('id');

        [$edadMaxInfante, $edadMaxNino] = RecalculoPorPersonaService::umbralesEdad();

        $porReservaPasajero = [];
        $porCotizacionPasajero = [];
        $edadEstimada = [];

        foreach ($pasajeros as $pasajero) {
            $origen = $pasajero->cotizacion_pasajero_id ? $vinculados->get($pasajero->cotizacion_pasajero_id) : null;

            if ($origen) {
                $tipo = $origen->tipo_pax;
                $edad = $origen->edad;
                $residencia = $origen->residencia;
            } else {
                $residencia = $pasajero->residencia ?: CotizacionPasajero::RESIDENCIA_NACIONAL;
                $nacimiento = $pasajero->pasajeroCatalogo?->fecha_nacimiento;

                if ($nacimiento) {
                    $edad = (int) floor($nacimiento->diffInYears($reserva->fecha_viaje_desde, true));
                    $tipo = RecalculoPorPersonaService::derivarTipoPax($edad, $edadMaxInfante, $edadMaxNino);
                } else {
                    $tipo = $pasajero->tipo_pax ?: 'adulto';
                    $edad = $this->edadRepresentativa($tipo, $edadMaxInfante, $edadMaxNino);
                    $edadEstimada[] = [
                        'reserva_pasajero_id' => $pasajero->id,
                        'nombre' => $pasajero->nombre,
                        'tipo_pax' => $tipo,
                        'edad_estimada' => $edad,
                    ];
                }
            }

            $copia = CotizacionPasajero::create([
                'cotizacion_id' => $cotizacion->id,
                'reserva_pasajero_id' => $pasajero->id,
                'tipo_pax' => $tipo,
                'edad' => $edad,
                'residencia' => $residencia,
            ]);

            $porReservaPasajero[$pasajero->id] = $copia->id;
            if ($origen) {
                $porCotizacionPasajero[$origen->id] = $copia->id;
            }
        }

        return [['reserva_pasajero' => $porReservaPasajero, 'cotizacion_pasajero' => $porCotizacionPasajero], $edadEstimada];
    }

    // Una edad que cae dentro del tipo según los umbrales de la agencia.
    private function edadRepresentativa(string $tipo, int $edadMaxInfante, int $edadMaxNino): int
    {
        return match ($tipo) {
            'infante' => min(1, $edadMaxInfante),
            'nino' => min($edadMaxInfante + 1, $edadMaxNino),
            default => max(self::EDAD_ADULTO_ESTIMADA, $edadMaxNino + 1),
        };
    }

    /**
     * Copia la alternativa vigente (sin las opciones de hotel descartadas, igual que al crear
     * la reserva), deja en cada ítem su origen y re-mapea pax_incluidos a los pasajeros nuevos.
     * No recalcula precios: solo los copia.
     *
     * @param  array<int,int>  $mapaPasajeros  cotizacion_pasajero_id vendido => id de la copia
     * @return array{0: Alternativa, 1: array<int, \App\Models\AgenciaViajes\AlternativaItem>}
     */
    private function copiarAlternativa(Alternativa $vendida, Cotizacion $cotizacion, array $mapaPasajeros): array
    {
        $items = ReservaController::filtrarItemsParaReserva($vendida->items()->get());

        [$nueva, $itemsClonados] = $this->alternativaCopia->copiar(
            $vendida,
            $cotizacion->id,
            $vendida->nombre.' (modificación)',
            $items,
        );

        foreach ($items as $item) {
            $copia = $itemsClonados[$item->id];
            $cambios = ['item_origen_id' => $item->id];

            // null = todos los pasajeros: sigue valiendo en la copia (incluye a los nuevos).
            if (is_array($item->pax_incluidos)) {
                $cambios['pax_incluidos'] = array_values(array_filter(array_map(
                    fn ($id) => $mapaPasajeros[(int) $id] ?? null,
                    $item->pax_incluidos
                )));
            }

            $copia->update($cambios);
        }

        return [$nueva, $itemsClonados];
    }

    // ¿Cambió la cantidad de pasajeros o su reparto por tipo respecto de la cotización vendida?
    private function cambioLaComposicion(Cotizacion $original, Cotizacion $copia): bool
    {
        $contar = fn (Cotizacion $c) => CotizacionPasajero::where('cotizacion_id', $c->id)
            ->selectRaw('tipo_pax, count(*) as total')
            ->groupBy('tipo_pax')
            ->pluck('total', 'tipo_pax')
            ->map(fn ($n) => (int) $n)
            ->sortKeys()
            ->all();

        return $contar($original) !== $contar($copia);
    }
}
