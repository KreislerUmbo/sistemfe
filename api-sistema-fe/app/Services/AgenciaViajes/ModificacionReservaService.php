<?php

namespace App\Services\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\SalidaMayorista;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// "Modificar reserva" — crear y descartar (T-002; R-AGE-01, R-AGE-02, R-AGE-10, D-AGE-030) y
// aplicar (T-005; R-AGE-07, R-AGE-08, D-AGE-031, D-AGE-032).
//
// Crear una modificación = abrir una cotización propia (origen 'modificacion') que es una
// copia de lo que la reserva tiene HOY: sus pasajeros y su alternativa vigente. El vendedor la
// edita en el cotizador de siempre; la versión vendida no se toca nunca (facturar lee el
// precio en vivo de alternativa_items, así que editarla cambiaría lo ya vendido). La diferencia
// la calcula ModificacionDiferenciaService (T-003); aplicar la recalcula bajo lock (T-005).
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
        private ModificacionDiferenciaService $diferencia,
        private ReservaItemCreacionService $itemsNuevos,
        private ReservaItemQuitarService $itemsQuitar,
        private QuitarPasajeroReservaService $pasajerosQuitar,
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

    public const MENSAJE_HUELLA_VIEJA = 'La reserva cambió desde que viste la diferencia. Revísala de nuevo antes de aplicar.';

    /**
     * Aplica la modificación a la reserva: pasa solo la diferencia, sin tocar lo facturado ni
     * perder los datos operativos (T-005; R-AGE-04, R-AGE-06 a R-AGE-09; D-AGE-030 a D-AGE-032).
     *
     * Todo ocurre en UNA transacción. Orden de locks: lo PRIMERO que se toma es la fila de la
     * reserva (`reserva`), el mismo lock y en el mismo lugar que ReservaFacturacionController::store()
     * y ReservaController::cancelar(), así no se bloquean entre sí. Después, la fila de la
     * modificación (solo la toma descartar(), que no toma el de la reserva: sin ciclo). Más
     * adentro, los pasos de siempre (quitar pasajero, ítems, `salidas_mayorista`) respetan ese
     * orden: nadie toma `salidas_mayorista` antes que la reserva (cancelar() tampoco). No se toma
     * ningún otro lock antes del de la reserva.
     *
     * @return array{modificacion: ReservaModificacion, alerta_cupo_excedido: bool}
     *
     * @throws HttpException 422 si la huella no coincide, hay bloqueos, no hay cambios o la reserva
     *                       o la modificación ya no están en estado de aplicarse
     */
    public function aplicar(ReservaModificacion $modificacion, string $huella, User $usuario): array
    {
        return DB::transaction(fn () => $this->aplicarBajoLock($modificacion, $huella, $usuario));
    }

    private function aplicarBajoLock(ReservaModificacion $modificacion, string $huella, User $usuario): array
    {
        // 1. Lock de la reserva (primero) y de la modificación.
        $reserva = Reserva::where('id', $modificacion->reserva_id)->lockForUpdate()->firstOrFail();
        $modificacion = ReservaModificacion::where('id', $modificacion->id)->lockForUpdate()->firstOrFail();

        // 2. Estado y diferencia, recalculada AQUÍ ADENTRO.
        if ($reserva->estado !== 'activa') {
            throw new HttpException(422, 'Solo se puede aplicar una modificación a una reserva activa.');
        }
        if (! $modificacion->estaAbierta()) {
            throw new HttpException(422, 'Esta modificación ya no está abierta.');
        }
        if ((int) $reserva->alternativa_id !== (int) $modificacion->alternativa_vendida_id) {
            throw new HttpException(422, 'La reserva ya no está en la versión que se modificó: abre una modificación nueva.');
        }

        $diferencia = $this->diferencia->calcular($modificacion);

        if (! hash_equals($diferencia->huella, $huella)) {
            throw new HttpException(422, self::MENSAJE_HUELLA_VIEJA);
        }
        if (! $diferencia->hayCambios) {
            throw new HttpException(422, 'La modificación no tiene cambios que aplicar.');
        }
        if ($diferencia->bloqueos !== []) {
            $mensajes = array_column($diferencia->bloqueos, 'mensaje');
            throw new HttpException(422, 'No se puede aplicar: '.$mensajes[0].(count($mensajes) > 1 ? ' (y '.(count($mensajes) - 1).' más)' : ''));
        }

        $vendida = Alternativa::findOrFail($modificacion->alternativa_vendida_id);
        $nueva = Alternativa::findOrFail($modificacion->alternativa_nueva_id);

        // El cupo del mayorista se mueve por pasajero en UNA salida. Si la versión nueva eligió otro
        // mayorista/salida, mover el cupo de una a otra no es parte de aplicar (para eso existe
        // "reasignar mayorista"): se rechaza antes de escribir nada.
        $opcionNueva = $nueva->opcionMayoristaElegida;
        $salidaMayoristaId = $vendida->opcionMayoristaElegida?->salida_mayorista_id;
        if ($salidaMayoristaId !== $opcionNueva?->salida_mayorista_id) {
            throw new HttpException(422, 'La modificación cambia el mayorista elegido: eso todavía no se puede aplicar desde aquí.');
        }

        // 3. Pasajeros: los existentes se re-vinculan a su copia y los agregados nacen ya vinculados
        //    (antes de quitar a nadie: la reserva nunca queda sin pasajeros).
        $mapaVendido = $this->mapaPasajerosDeLaReserva($reserva);
        [$mapaCopias, $agregados] = $this->aplicarPasajerosNuevos($reserva, $modificacion);

        // 4. Servicios. Las penalidades (servicios agregados) se crean ANTES de quitar al pasajero.
        $this->aplicarServicios($reserva, $nueva, $diferencia, $mapaVendido, $mapaCopias);

        // 3 (cont.). Los pasajeros quitados, con el servicio de siempre (borra su asignación a
        //    servicios y vuelos y devuelve un cupo al mayorista por cada uno).
        foreach ($diferencia->pasajeros as $fila) {
            if ($fila['estado'] === 'quitado') {
                $this->pasajerosQuitar->quitar(ReservaPasajero::findOrFail($fila['reserva_pasajero_id']));
            }
        }

        if (! ReservaItem::where('reserva_id', $reserva->id)->exists()) {
            throw new HttpException(422, 'La reserva no puede quedar sin servicios.');
        }

        // 5. Cupo del mayorista: quitar() ya devolvió uno por pasajero quitado; los agregados suman.
        $alertaCupoExcedido = false;
        if ($agregados > 0 && $salidaMayoristaId) {
            SalidaMayorista::where('id', $salidaMayoristaId)->increment('cupo_ocupado', $agregados);
            $salida = SalidaMayorista::find($salidaMayoristaId);
            $alertaCupoExcedido = $salida && $salida->cupo_total !== null && $salida->cupo_ocupado > $salida->cupo_total;
        }

        // 6. Cambio de versión.
        $vendida->update(['estado' => Alternativa::ESTADO_REEMPLAZADA]);
        $nueva->update(['estado' => 'aceptada']);
        $reserva->update([
            'alternativa_id' => $nueva->id,
            'mayorista_elegida_id' => $opcionNueva?->id,
        ]);

        // La cotización de modificación la abrió quien la editó (CotizacionPolicy decide por
        // vendedor_id), pero desde ahora es la cotización de la reserva y ReservaPolicy /
        // EscopablePorVendedor leen el dueño de ahí: vuelve a ser el de la cotización vendida,
        // para que la reserva no cambie de dueño ni desaparezca del vendedor original.
        Cotizacion::whereKey($modificacion->cotizacion_id)
            ->update(['vendedor_id' => Cotizacion::whereKey($vendida->cotizacion_id)->value('vendedor_id')]);

        $modificacion->update([
            'estado' => ReservaModificacion::ESTADO_APLICADA,
            'aplicado_por' => $usuario->id,
            'aplicado_en' => now(),
            'diferencia_aplicada' => $diferencia->toArray(),
        ]);

        return [
            'modificacion' => $modificacion->fresh(),
            'alerta_cupo_excedido' => $alertaCupoExcedido,
        ];
    }

    // cotizacion_pasajero_id (de la versión vendida) => reserva_pasajero_id, ANTES de re-vincular.
    private function mapaPasajerosDeLaReserva(Reserva $reserva): array
    {
        return ReservaPasajero::where('reserva_id', $reserva->id)
            ->whereNotNull('cotizacion_pasajero_id')
            ->pluck('id', 'cotizacion_pasajero_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array{0: array<int,int>, 1: int} mapa copia (cotizacion_pasajero_id de la modificación)
     *         => reserva_pasajero_id, y cuántos pasajeros se agregaron
     */
    private function aplicarPasajerosNuevos(Reserva $reserva, ReservaModificacion $modificacion): array
    {
        $mapa = [];
        $agregados = 0;

        foreach (CotizacionPasajero::where('cotizacion_id', $modificacion->cotizacion_id)->orderBy('id')->get() as $copia) {
            if ($copia->reserva_pasajero_id) {
                // Existente: su copia pasa a ser su vínculo con la cotización (el vendido ya no se usa).
                ReservaPasajero::where('id', $copia->reserva_pasajero_id)->where('reserva_id', $reserva->id)
                    ->update(['cotizacion_pasajero_id' => $copia->id]);
                $mapa[$copia->id] = (int) $copia->reserva_pasajero_id;

                continue;
            }

            $nuevo = ReservaPasajero::create([
                'reserva_id' => $reserva->id,
                'cotizacion_pasajero_id' => $copia->id,
                'tipo_pax' => $copia->tipo_pax,
                'residencia' => $copia->residencia,
            ]);
            $copia->update(['reserva_pasajero_id' => $nuevo->id]);
            $mapa[$copia->id] = $nuevo->id;
            $agregados++;
        }

        return [$mapa, $agregados];
    }

    /**
     * @param  array<int,int>  $mapaVendido  cotizacion_pasajero_id vendido => reserva_pasajero_id
     * @param  array<int,int>  $mapaCopias  copia => reserva_pasajero_id
     */
    private function aplicarServicios(Reserva $reserva, Alternativa $nueva, DiferenciaModificacion $diferencia, array $mapaVendido, array $mapaCopias): void
    {
        $itemsNuevos = AlternativaItem::whereIn('id', array_filter(array_column($diferencia->lineas, 'alternativa_item_id')))->get()->keyBy('id');
        $reservaItems = ReservaItem::where('reserva_id', $reserva->id)->with('alternativaItem')->get()->keyBy('id');
        $ajustes = [];

        foreach ($diferencia->lineas as $linea) {
            $itemNuevo = $linea['alternativa_item_id'] ? $itemsNuevos->get($linea['alternativa_item_id']) : null;
            $reservaItem = $linea['reserva_item_id'] ? $reservaItems->get($linea['reserva_item_id']) : null;

            if ($itemNuevo && $reservaItem) {
                // Facturado: no se toca; si subió de precio, su diferencia se cobra como "Ajuste".
                if ($linea['facturado']) {
                    if ($linea['estado'] === 'ajuste') {
                        $ajustes[] = [$linea, $itemNuevo, $reservaItem];
                    }

                    continue;
                }

                $this->repuntar($reservaItem, $itemNuevo, $mapaVendido, $mapaCopias);
            } elseif ($itemNuevo) {
                $this->itemsNuevos->crear($reserva, $itemNuevo, $reserva->fecha_viaje_desde, $mapaCopias);
            } elseif ($reservaItem && $linea['estado'] === 'quitado') {
                $this->itemsQuitar->quitar($reservaItem);
            }
        }

        foreach ($ajustes as [$linea, $itemNuevo, $reservaItem]) {
            $this->crearAjuste($reserva, $nueva, $linea, $itemNuevo, $reservaItem);
        }
    }

    // El servicio no facturado pasa a leer su precio del ítem nuevo. Se conservan sus datos
    // operativos (fecha y su origen, salida, check-in, proveedor/hotel/mayorista reasignados,
    // tratamiento tributario corregido a mano); lo que nunca se tocó toma el valor del ítem nuevo.
    private function repuntar(ReservaItem $reservaItem, AlternativaItem $nuevo, array $mapaVendido, array $mapaCopias): void
    {
        $viejo = $reservaItem->alternativaItem;
        $cambios = ['alternativa_item_id' => $nuevo->id];

        if ($reservaItem->proveedor_tarifa_original_id === null && $reservaItem->opcion_hotel_tarifa_original_id === null) {
            $cambios['proveedor_tarifa_id'] = $nuevo->proveedor_tarifa_id;
            $cambios['opcion_hotel_tarifa_id'] = $nuevo->opcion_hotel_tarifa_id;
        }
        if ($reservaItem->opcion_mayorista_original_id === null) {
            $cambios['opcion_mayorista_id'] = $nuevo->opcion_mayorista_id;
        }
        if ($reservaItem->motivo_override_tributario === null) {
            $cambios['tip_afe_igv'] = $nuevo->tip_afe_igv;
            $cambios['destino_tributario'] = $nuevo->destino_tributario;
        }
        $reservaItem->update($cambios);

        // reserva_item_pasajero se re-arma solo si el vendedor cambió quién usa el servicio: si no,
        // se respeta la asignación que ya tenga la reserva (null = para todos, sin filas).
        $antes = $this->paxDelItem($viejo, $mapaVendido);
        $despues = $this->paxDelItem($nuevo, $mapaCopias);
        if ($antes !== $despues) {
            // Solo se borra a quien salió y solo se crea a quien entró: la fila de quien sigue
            // lleva su check-in y no se toca.
            $deseados = $despues ?? [];
            $existentes = ReservaItemPasajero::where('reserva_item_id', $reservaItem->id)->pluck('reserva_pasajero_id')->map(fn ($id) => (int) $id)->all();
            ReservaItemPasajero::where('reserva_item_id', $reservaItem->id)->whereNotIn('reserva_pasajero_id', $deseados)->delete();
            foreach (array_diff($deseados, $existentes) as $reservaPasajeroId) {
                ReservaItemPasajero::create(['reserva_item_id' => $reservaItem->id, 'reserva_pasajero_id' => $reservaPasajeroId]);
            }
        }
    }

    /** @return array<int,int>|null reserva_pasajero_id ordenados; null = para todos */
    private function paxDelItem(?AlternativaItem $item, array $mapa): ?array
    {
        if (! $item || empty($item->pax_incluidos)) {
            return null;
        }

        $ids = array_values(array_filter(array_map(fn ($id) => $mapa[(int) $id] ?? null, $item->pax_incluidos)));
        sort($ids);

        return $ids;
    }

    // "Ajuste — <servicio>" (D-AGE-032): ítem manual de la versión nueva por la diferencia, con el
    // tratamiento tributario con que se facturó el servicio, y su servicio de reserva. Sin pasajeros
    // asignados: al facturar es un servicio "sin asignar" que el vendedor suma a la factura que quiera.
    private function crearAjuste(Reserva $reserva, Alternativa $nueva, array $linea, AlternativaItem $itemNuevo, ReservaItem $reservaItem): void
    {
        $monto = number_format($linea['diferencia_centavos'] / 100, 2, '.', '');

        $ajuste = AlternativaItem::create([
            'alternativa_id' => $nueva->id,
            'alternativa_destino_id' => $itemNuevo->alternativa_destino_id,
            'origen_tipo' => AlternativaItem::ORIGEN_MANUAL,
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
            'descripcion_manual' => 'Ajuste — '.$linea['descripcion'],
            'moneda_costo' => $nueva->moneda_cotizacion,
            'costo_snapshot' => 0,
            'precio_venta_snapshot' => $monto,
            'precio_convertido' => $monto,
            'tip_afe_igv' => $reservaItem->tip_afe_igv ?? $itemNuevo->tip_afe_igv,
            'destino_tributario' => $reservaItem->destino_tributario ?? $itemNuevo->destino_tributario,
            'ajuste_de_item_id' => $itemNuevo->id,
        ]);

        $this->itemsNuevos->crear($reserva, $ajuste, $reserva->fecha_viaje_desde, []);
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
        // Un servicio de ajuste (T-005) no se copia: lo cobrado ya está en el precio del ítem que
        // ajusta. Copiarlo lo contaría dos veces en la diferencia.
        $items = ReservaController::filtrarItemsParaReserva($vendida->items()->get())
            ->whereNull('ajuste_de_item_id')->values();

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
