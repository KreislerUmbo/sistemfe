<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Concerns\AutorizaCotizacion;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajeAereo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ProveedorTipoConfig;
use App\Models\AgenciaViajes\ReservaItem;
use App\Services\AgenciaViajes\AltaItem\AltaGuia;
use App\Services\AgenciaViajes\AltaItem\AltaItemException;
use App\Services\AgenciaViajes\AltaItem\AltaManual;
use App\Services\AgenciaViajes\AltaItem\AltaMayorista;
use App\Services\AgenciaViajes\AltaItem\AltaPasajeAereo;
use App\Services\AgenciaViajes\AltaItem\AltaProveedor;
use App\Services\AgenciaViajes\ComboExplosionService;
use App\Services\AgenciaViajes\PriceEngineService;
use App\Services\AgenciaViajes\TourLineaException;
use App\Services\AgenciaViajes\TourLineaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

// Ítems de una alternativa del cotizador. store() delega el alta en un servicio por origen_tipo
// (Services/AgenciaViajes/AltaItem); update() es la edición en vivo de descuento_pct/precio_convertido
// con validación de piso.
class AlternativaItemController extends Controller
{
    use AutorizaCotizacion;

    public function __construct(
        private PriceEngineService $priceEngine,
        private ComboExplosionService $comboExplosion,
        private TourLineaService $tourLinea,
        private AltaManual $altaManual,
        private AltaGuia $altaGuia,
        private AltaMayorista $altaMayorista,
        private AltaPasajeAereo $altaPasajeAereo,
        private AltaProveedor $altaProveedor
    ) {
    }

    // POST alternativas/{id}/items: cada origen_tipo tiene su servicio de alta (Services/AgenciaViajes/AltaItem).
    // input() y no get(): get() no lee el cuerpo JSON.
    public function store(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $this->autorizarCotizacion('update', $alternativa);
        $origenTipo = $request->input('origen_tipo');

        if (! in_array($origenTipo, AlternativaItem::ORIGENES, true)) {
            return response()->json([
                'code' => 422,
                'message' => 'origen_tipo inválido — debe ser uno de: ' . implode(', ', AlternativaItem::ORIGENES),
            ], 422);
        }

        try {
            [$item, $mensaje] = match ($origenTipo) {
                AlternativaItem::ORIGEN_PROVEEDOR => [$this->altaProveedor->crear($alternativa, $request->all()), 'Ítem agregado correctamente'],
                AlternativaItem::ORIGEN_MAYORISTA => [$this->altaMayorista->crear($alternativa, $request->all()), 'Ítem de mayorista agregado correctamente'],
                AlternativaItem::ORIGEN_PASAJE_AEREO => [$this->altaPasajeAereo->crear($alternativa, $request->all()), 'Pasaje aéreo agregado correctamente'],
                AlternativaItem::ORIGEN_MANUAL => [$this->altaManual->crear($alternativa, $request->all()), 'Ítem manual agregado correctamente'],
                AlternativaItem::ORIGEN_GUIA => [$this->altaGuia->crear($alternativa, $request->all()), 'Ítem de guía agregado correctamente'],
            };
        } catch (AltaItemException $e) {
            return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
        }

        // El ítem con las relaciones del lienzo y la alternativa recargada (no la que está en memoria:
        // puede traer relaciones cargadas por el camino y la respuesta cambiaría).
        return response()->json([
            'code' => 200,
            'message' => $mensaje,
            'alternativa_item' => AlternativaItem::with(self::RELACIONES_LIENZO)->find($item->id)->toArray(),
            'alternativa' => $alternativa->fresh()->toArray(),
        ]);
    }

    // T-032 (R5): relaciones que el lienzo del cotizador lee de cada ítem (las mismas que carga
    // CotizacionController::show()). El ítem recién creado las lleva para que la pantalla lo
    // agregue en memoria sin volver a pedir toda la cotización.
    private const RELACIONES_LIENZO = [
        'proveedorTarifa.proveedorServicio.proveedor',
        'proveedorTarifa.proveedorServicio.destinoServicio.servicio',
        'proveedorTarifa.proveedorServicio.destinoServicio.destinoAtractivo',
        'opcionMayorista.proveedor',
        'opcionMayorista.tours.paquetePlantilla',
        'opcionHotelTarifa.opcionHotel',
        'opcionMayoristaOpcional',
        'guiaTarifa.guia',
        'guiaTarifa.destino',
        'cotizacionPasajeAereo',
        'tourOrigen',
        'reservaItem.reserva:id,codigo,estado',
    ];

    // Edición en vivo de descuento_pct O precio_convertido — §3.1. El
    // backend siempre recalcula el que falta y devuelve AMBOS, más
    // alerta_piso/precio_minimo_permitido, para que el frontend nunca
    // tenga que hacer la conversión matemática él mismo.
    public function update(Request $request, string $id)
    {
        $item = AlternativaItem::with(['alternativa', 'proveedorTarifa'])->findOrFail($id);
        $this->autorizarCotizacion('update', $item);

        // T-001 (R-AGE-11): facturar una reserva lee el precio en vivo de
        // alternativa_items (total_convertido); cambiarlo aquí cambiaba lo que
        // faltaba facturar de una reserva ya vendida. Mismo guard que
        // precioCarga(), antes de calcular nada.
        if ($item->alternativa->estado === 'aceptada') {
            return response()->json(['code' => 422, 'message' => 'Esta alternativa ya fue aceptada y generó una reserva — el precio ya no se cambia desde acá.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'descuento_pct' => 'nullable|numeric|min:0|max:100',
            'precio_convertido' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        if (! isset($validado['descuento_pct']) && ! isset($validado['precio_convertido'])) {
            return response()->json(['code' => 422, 'message' => 'Enviá descuento_pct o precio_convertido.'], 422);
        }

        $alternativa = $item->alternativa;
        $precioListaConvertido = $this->priceEngine->convertirMoneda(
            (float) $item->precio_venta_snapshot,
            $item->moneda_costo,
            $alternativa->moneda_cotizacion,
            (float) $alternativa->tipo_cambio_aplicado
        );

        if (isset($validado['descuento_pct'])) {
            $descuentoPct = $validado['descuento_pct'];
            $precioConvertido = round($precioListaConvertido * (1 - $descuentoPct / 100), 2);
        } else {
            $precioConvertido = $validado['precio_convertido'];
            $descuentoPct = $precioListaConvertido > 0
                ? round((1 - $precioConvertido / $precioListaConvertido) * 100, 2)
                : 0;
        }

        // Piso solo si el ítem viene de una proveedor_tarifa real — ítems
        // manuales y "precio de referencia sin proveedor asignado" (§3) no
        // tienen tarifa de la que derivar descuento_maximo_pct/margen_minimo_pct.
        $alertaPiso = false;
        $precioMinimoPermitido = null;

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

            $precioMinimoPermitido = $pisoInfo['precio_minimo_permitido'];
            $alertaPiso = $pisoInfo['alerta_piso'];
        }

        $item->update([
            'descuento_pct' => $descuentoPct,
            'precio_convertido' => $precioConvertido,
            // Fila 13, fase 1b: en una línea de tour, cambiar el precio la
            // marca como precio a mano (el recálculo por pasajeros no lo pisa).
            'precio_manual' => $item->esLineaTour() ? true : $item->precio_manual,
        ]);

        $alternativa->recalcularTotal();

        return response()->json([
            'code' => 200,
            'message' => 'Ítem actualizado correctamente',
            'alternativa_item' => $item->fresh(),
            'alternativa' => $alternativa->fresh(),
            'precio_minimo_permitido' => $precioMinimoPermitido,
            'alerta_piso' => $alertaPiso,
        ]);
    }

    // PUT alternativa-items/{id}/actualizar-tarifa — T-022, "Actualizar al precio nuevo".
    // Re-engancha el ítem a la versión siguiente vigente de SU tarifa (se busca acá, en el
    // servidor: el frontend no manda ningún id de tarifa), con el costo y precio de esa versión
    // convertidos a la moneda de la alternativa como en el alta. Conserva descuento y cantidad.
    public function actualizarTarifa(Request $request, string $id): JsonResponse
    {
        $item = AlternativaItem::findOrFail($id);
        $this->autorizarCotizacion('update', $item);

        $rechazo = DB::transaction(function () use ($item) {
            // Mismo bloqueo que los demás cambios de precio: dos vendedores no pisan el total.
            $alternativa = Alternativa::whereKey($item->alternativa_id)->lockForUpdate()->firstOrFail();
            $item = AlternativaItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            // T-001 (R-AGE-11): la alternativa aceptada ya generó su reserva.
            if ($alternativa->estado === 'aceptada') {
                return response()->json(['code' => 422, 'message' => 'Esta alternativa ya fue aceptada y generó una reserva — el precio ya no se cambia desde acá.'], 422);
            }

            $actual = $item->origen_tipo === AlternativaItem::ORIGEN_PROVEEDOR ? $item->proveedorTarifa : null;
            if (! $actual) {
                return response()->json(['code' => 422, 'message' => 'Este servicio no usa una tarifa de proveedor.'], 422);
            }

            $nueva = $actual->siguienteVersionVigente();
            if (! $nueva) {
                return response()->json(['code' => 422, 'message' => 'Esta tarifa ya no tiene un precio nuevo para aplicar.'], 422);
            }

            $item->setRelation('alternativa', $alternativa);
            $precio = $this->priceEngine->precioDeItemConTarifa($item, $nueva, $actual);
            $tratamiento = ConfiguracionAgencia::completarTratamientoTributario($nueva->tip_afe_igv, $nueva->destino_tributario);

            $item->update([
                'proveedor_tarifa_id' => $nueva->id,
                'moneda_costo' => $precio['moneda_costo'],
                'costo_snapshot' => $precio['costo'],
                'precio_venta_snapshot' => $precio['venta'],
                'precio_convertido' => $precio['precio_convertido'],
                'tip_afe_igv' => $tratamiento['tip_afe_igv'],
                'destino_tributario' => $tratamiento['destino_tributario'],
            ]);

            $alternativa->recalcularTotal();

            return null;
        });

        if ($rechazo) {
            return $rechazo;
        }

        return response()->json([
            'code' => 200,
            'message' => 'Servicio actualizado al precio nuevo',
            'alternativa_item' => $item->fresh(),
            'total' => (float) Alternativa::whereKey($item->alternativa_id)->value('total'),
        ]);
    }

    // PUT alternativa-items/{id}/elegir-grupo — resuelve un grupo de opciones de hotel de M1: marca ESTA fila como
    // opcion_elegida=true, desmarca las demás del mismo grupo_opcion_id
    // (mismo patrón de exclusividad que OpcionMayoristaController::elegir()).
    // El vendedor puede cambiar de elegida después llamando de nuevo a este
    // mismo endpoint sobre otra fila del grupo.
    public function elegirOpcionGrupo(string $id)
    {
        $item = AlternativaItem::with('alternativa')->findOrFail($id);
        $this->autorizarCotizacion('update', $item);
        $alternativa = $item->alternativa;

        if ($alternativa->estado === 'aceptada') {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa ya fue aceptada y generó una reserva — el grupo ya no se puede modificar desde acá.',
            ], 422);
        }

        if (! $item->grupo_opcion_id) {
            return response()->json(['code' => 422, 'message' => 'Este ítem no pertenece a ningún grupo de opciones.'], 422);
        }

        DB::transaction(function () use ($item, $alternativa) {
            AlternativaItem::where('alternativa_id', $alternativa->id)
                ->where('grupo_opcion_id', $item->grupo_opcion_id)
                ->update(['opcion_elegida' => false]);

            $item->update(['opcion_elegida' => true]);

            // Reaplica el descuento global vigente sobre la fila recién
            // elegida — sin esto, quedaría a precio de lista hasta que el
            // vendedor vuelva a tocar el campo de descuento a mano (mismo
            // motor que AlternativaController::update(), idempotente: reaplicar el mismo %
            // mueve el descuento a la nueva elegida sin quedar pegado a la anterior).
            if ($alternativa->descuento_global_pct !== null && (float) $alternativa->descuento_global_pct > 0) {
                app(AlternativaController::class)->aplicarDescuentoGlobal($alternativa, (float) $alternativa->descuento_global_pct);
            } else {
                $alternativa->recalcularTotal();
            }
        });

        return response()->json([
            'code' => 200,
            'message' => 'Opción marcada como elegida correctamente',
            'alternativa' => $alternativa->fresh('items'),
        ]);
    }

    // Mismo bug que ya se encontró y corrigió una vez en
    // AlternativaController::destroy() (ver comentario ahí): reserva_items.
    // alternativa_item_id es una FK real sin onDelete (RESTRICT en
    // Postgres) — sin este guard, borrar un ítem cuya alternativa ya
    // generó una reserva (por ejemplo vía Venta Directa, que crea
    // alternativa→reserva→reserva_items en el mismo request) tiraba una
    // violación de FK sin capturar (500 crudo, "parecía que el botón no
    // hacía nada").
    public function destroy(string $id)
    {
        $item = AlternativaItem::with('alternativa')->findOrFail($id);
        $this->autorizarCotizacion('update', $item);
        $alternativa = $item->alternativa;

        if ($bloqueo = ReservaItem::mensajeBloqueo([$item->id], 'eliminar')) {
            return response()->json(['code' => 422, 'message' => $bloqueo], 422);
        }

        // Fila 13, fase 1b: una línea de tour se quita con toda su carga (las
        // demás líneas de IGV del mismo tour); quitar solo una dejaría el
        // tour cobrado a medias.
        if ($item->esLineaTour() && $item->carga_tour) {
            return $this->eliminarCarga((string) $alternativa->id, $item->carga_tour);
        }

        DB::transaction(function () use ($item) {
            if ($item->origen_tipo === AlternativaItem::ORIGEN_PASAJE_AEREO) {
                CotizacionPasajeAereo::where('alternativa_item_id', $item->id)->delete();
            }
            $item->delete();
        });

        $alternativa->recalcularTotal();

        return response()->json(['code' => 200, 'message' => 'Ítem eliminado correctamente', 'alternativa' => $alternativa->fresh('items')]);
    }

    // Borra TODAS las filas de un mismo grupo_opcion_id de una sola vez (repetir "Comparar varias
    // opciones" deja bloques "N opciones de hotel" idénticos y borrarlos fila por fila no es intuitivo) — mismo
    // guard de reserva que destroy(), evaluado sobre el grupo completo antes
    // de borrar cualquier fila (todo o nada).
    //
    // T-024: acotado a una alternativa. Antes buscaba solo por el grupo, y
    // una alternativa duplicada compartía el id con la original: borrar el
    // bloque en la copia borraba también el de la original.
    public function eliminarGrupo(string $alternativaId, string $grupoOpcionId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $this->autorizarCotizacion('update', $alternativa);

        $items = Str::isUuid($grupoOpcionId)
            ? AlternativaItem::where('alternativa_id', $alternativa->id)->where('grupo_opcion_id', $grupoOpcionId)->get()
            : collect();

        if ($items->isEmpty()) {
            return response()->json(['code' => 404, 'message' => 'Grupo no encontrado en esta alternativa.'], 404);
        }

        if ($alternativa->estado === 'aceptada') {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa ya fue aceptada y generó una reserva — el grupo ya no se puede modificar desde acá.',
            ], 422);
        }

        if ($bloqueo = ReservaItem::mensajeBloqueo($items->pluck('id'), 'eliminar estas opciones')) {
            return response()->json(['code' => 422, 'message' => $bloqueo], 422);
        }

        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                if ($item->origen_tipo === AlternativaItem::ORIGEN_PASAJE_AEREO) {
                    CotizacionPasajeAereo::where('alternativa_item_id', $item->id)->delete();
                }
                $item->delete();
            }
        });

        $alternativa->recalcularTotal();

        return response()->json(['code' => 200, 'message' => 'Grupo eliminado correctamente']);
    }

    // "Cargar desde plantilla": explota TODOS los
    // ítems de un tour_simple/paquete_combo en la alternativa activa,
    // resueltos en vivo contra su proveedor_tarifa real (nunca copia
    // precio_venta_final del catálogo como un solo número — mismo criterio
    // ya usado por clicBibliotecaItem() para un ítem suelto, en bucle).
    // Reusa ComboExplosionService::explotarTourSimple()/explotarItems() —
    // mismo motor que ya resuelve el precio "calculado" que el vendedor ve
    // en el catálogo (paquetes/detalle.vue), así que el total acá siempre
    // coincide con el que ya vio ahí.
    //
    // Con modo_tours='nuevo' el tour entra como UNA línea con su propio precio por persona y por
    // temporada (desdePlantillaModoNuevo()); este camino es el modo 'clasico'. Ver
    // plan-tours-venta-simple-costo-real.md (N6, §3.12, §5b). Este método no consulta la
    // temporada, y el ajuste de redondeo (más abajo) no se multiplica por pasajero.
    public function desdePlantilla(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $this->autorizarCotizacion('update', $alternativa);

        $validator = Validator::make($request->all(), [
            'paquete_plantilla_id' => 'required|integer|exists:paquetes_plantilla,id',
            'dia_referencial' => 'required|integer|min:1',
            'alternativa_destino_id' => ['nullable', 'integer', $alternativa->reglaDestinoId()],
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();
        $paquete = PaquetePlantilla::findOrFail($validado['paquete_plantilla_id']);
        // Resuelto UNA sola vez por request — todos los ítems de una misma
        // carga de plantilla van al mismo destino (§0/§3 del brief 12f-1).
        $alternativaDestinoId = $alternativa->destinoIdODefault($validado['alternativa_destino_id'] ?? null);

        // Fila 13, fase 1b (§5b): con modo_tours='nuevo' el tour entra como
        // línea propia (TourLineaService); todo lo de abajo es el camino
        // 'clasico', igual que siempre.
        if (ConfiguracionAgencia::modoToursNuevo()) {
            return $this->desdePlantillaModoNuevo($alternativa, $paquete, (int) $validado['dia_referencial'], $alternativaDestinoId);
        }

        // Cada entrada trae su propio dia_referencial ya resuelto — para un
        // tour_simple suelto, el mismo día activo para todos sus ítems
        // (decisión confirmada: un tour multi-día cae en un solo bloque, no
        // se distribuye ítem por ítem dentro del tour, ver CLAUDE.md). Para
        // un paquete_combo, cada tour-hijo ocupa su PROPIO día de inicio —
        // mismo offset que ComboExplosionService::itinerarioDerivado() ya usa
        // para el itinerario derivado (tour_itinerario_items.dia_relativo
        // máximo de cada tour), no un solo día plano para todo el combo.
        // dia_final_combo: el
        // último día que este combo ocupa, contando incluso un tour-hijo sin
        // itinerario cargado (?? 1 — ocupa igual el día en que arranca,
        // nunca "desaparece" del rango de días). Sin esto, un tour sin
        // itinerario/incluye podía dejar un día sin ningún ítem y sin
        // siquiera una pestaña creada en el cotizador.
        $entradas = [];
        $diaFinalCombo = $validado['dia_referencial'];
        if ($paquete->esPaqueteCombo()) {
            $offsetDia = 0;
            $diaFinalCombo = $validado['dia_referencial'] - 1;
            foreach ($this->comboExplosion->toursDelCombo($paquete) as $tourHijo) {
                $diaDelTour = $validado['dia_referencial'] + $offsetDia;

                foreach ($this->comboExplosion->explotarTourSimple($tourHijo) as $entrada) {
                    $entrada['dia_referencial'] = $diaDelTour;
                    $entradas[] = $entrada;
                }

                $diasDelTourActual = (int) ($tourHijo->paqueteItinerario()->max('dia_relativo') ?? 1);
                $diaFinalCombo = max($diaFinalCombo, $diaDelTour + $diasDelTourActual - 1);
                $offsetDia += $diasDelTourActual;
            }

            // Ítems sueltos del combo: un hotel/guía agregado DIRECTO al combo (sin envolverlo en un
            // tour-hijo) no sale de toursDelCombo(). Ocupan el día de inicio del combo (no tienen
            // tour propio del que derivar un día/offset).
            foreach ($this->comboExplosion->explotarItemsSueltos($paquete) as $entrada) {
                $entrada['dia_referencial'] = $validado['dia_referencial'];
                $entradas[] = $entrada;
            }

            // Un combo compuesto solo por ítems sueltos (sin ningún
            // tour-hijo) igual ocupa al menos su propio día de inicio.
            $diaFinalCombo = max($diaFinalCombo, $validado['dia_referencial']);
        } else {
            foreach ($this->comboExplosion->explotarTourSimple($paquete) as $entrada) {
                $entrada['dia_referencial'] = $validado['dia_referencial'];
                $entradas[] = $entrada;
            }
        }

        // Ajuste de redondeo: la suma real de ítems casi nunca da un número redondo (ej. S/93.66) pero
        // el negocio quiere cobrar S/100. ajuste_redondeo (positivo o negativo) es la palanca, y tiene
        // que llegar a la cotización real del cliente, no solo al catálogo (totalesTour()/
        // totalesCombo()). Se refleja como UN AlternativaItem manual adicional, auditable (no un
        // número mágico), con costo=0 porque es un ajuste de precio de venta, no un servicio nuevo
        // con costo de proveedor. Solo el ajuste_redondeo del
        // paquete de nivel superior (el que se está cargando) aplica — el de
        // un tour-hijo dentro de un combo NO se propaga acá: cada
        // paquete_plantilla corrige su PROPIO total final, no el de su
        // contenedor (mismo criterio que totalesCombo() usa para
        // costo_total_combo/venta_bruta_combo, que tampoco lo aplican por
        // tour-hijo, ver ComboExplosionService::totalesTour()).
        $ajusteRedondeo = $paquete->ajuste_redondeo !== null ? (float) $paquete->ajuste_redondeo : null;

        $itemsCreados = [];
        $guiasPendientes = [];

        DB::transaction(function () use ($alternativa, $entradas, $ajusteRedondeo, $validado, $alternativaDestinoId, &$itemsCreados, &$guiasPendientes) {
            foreach ($entradas as $entrada) {
                $item = $this->crearItemClasicoDesdeEntrada($alternativa, $entrada, $alternativaDestinoId, $guiasPendientes);
                if ($item) {
                    $itemsCreados[] = $item;
                }
            }

            if ($ajusteRedondeo !== null && $ajusteRedondeo != 0.0) {
                $itemsCreados[] = AlternativaItem::create([
                    'alternativa_id' => $alternativa->id,
                    'alternativa_destino_id' => $alternativaDestinoId,
                    'origen_tipo' => AlternativaItem::ORIGEN_MANUAL,
                    'descripcion_manual' => 'Ajuste de redondeo',
                    'dia_referencial' => $validado['dia_referencial'],
                    'modo_precio' => 'tarifa_fija',
                    'cantidad' => 1,
                    'moneda_costo' => $alternativa->moneda_cotizacion,
                    'costo_snapshot' => 0,
                    'precio_venta_snapshot' => $ajusteRedondeo,
                    'precio_convertido' => $this->priceEngine->convertirMoneda($ajusteRedondeo, $alternativa->moneda_cotizacion, $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
                ]);
            }

            $alternativa->recalcularTotal();
        });

        return response()->json([
            'code' => 200,
            'message' => 'Plantilla cargada correctamente',
            'items_agregados' => $itemsCreados,
            'guias_pendientes' => $guiasPendientes,
            'dia_final_combo' => $diaFinalCombo,
            'resumen' => [
                'tours' => $paquete->esPaqueteCombo() ? $this->comboExplosion->toursDelCombo($paquete)->count() : null,
                'items' => count($itemsCreados),
            ],
        ]);
    }

    // Una entrada de ComboExplosionService → un AlternativaItem del modo
    // clásico. Extraído tal cual del bucle de desdePlantilla() (fila 13, fase
    // 1b) para reusarlo con los ítems sueltos de un combo en el modo nuevo;
    // test_desde_plantilla_da_el_mismo_resultado_que_antes vigila que el
    // clásico no cambie.
    private function crearItemClasicoDesdeEntrada(Alternativa $alternativa, array $entrada, ?int $alternativaDestinoId, array &$guiasPendientes): ?AlternativaItem
    {
        if ($entrada['proveedor_tarifa_id']) {
            $tarifa = ProveedorTarifa::findOrFail($entrada['proveedor_tarifa_id']);

            // 'compartido' se cobra por pasajero REAL de
            // la cotización (no un adulto plano) — mismo cálculo que
            // AltaProveedor usa para modo_precio=
            // 'por_persona' (contarPasajerosPorTipo()). 'privado' (o
            // sin modalidad, ej. guía suelto que igual cae acá si
            // algún día tuviera proveedor_tarifa_id) sigue con
            // tarifa_fija/cantidad=1 tal como antes: el precio que
            // trae la explosión ya es el tier adulto plano, sin
            // repartir por pax — mismo criterio que el precio
            // "calculado" del catálogo (totalesTour()/totalesCombo()).
            if ($entrada['modalidad'] === 'compartido' && $tarifa->tipo_habitacion === null) {
                $conteo = $this->contarPasajerosPorTipo($alternativa->cotizacion_id, null);
                $ventaPorPersona = $conteo['adulto'] * ($entrada['precio_venta_adulto'] ?? 0)
                    + $conteo['nino'] * $entrada['precio_venta_nino']
                    + $conteo['infante'] * ($entrada['precio_venta_infante'] ?? 0);
                $costoPorPersona = ($entrada['precio_costo'] ?? 0)
                    * ($conteo['adulto'] + $conteo['nino'] + $conteo['infante']);

                return AlternativaItem::create([
                    'alternativa_id' => $alternativa->id,
                    'alternativa_destino_id' => $alternativaDestinoId,
                    'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
                    'proveedor_tarifa_id' => $tarifa->id,
                    'tour_origen_id' => $entrada['tour_origen_id'],
                    'dia_referencial' => $entrada['dia_referencial'],
                    'modo_precio' => 'por_persona',
                    // cantidad NOT NULL en BD (default 1) — sin
                    // efecto real acá, el accessor total() no
                    // multiplica para 'por_persona' (mismo criterio
                    // que AltaProveedor usa).
                    'cantidad' => 1,
                    'moneda_costo' => $tarifa->moneda,
                    'costo_snapshot' => $costoPorPersona,
                    'precio_venta_snapshot' => $ventaPorPersona,
                    'precio_convertido' => $this->priceEngine->convertirMoneda($ventaPorPersona, $tarifa->moneda, $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
                ]);
            }

            return AlternativaItem::create([
                'alternativa_id' => $alternativa->id,
                'alternativa_destino_id' => $alternativaDestinoId,
                'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
                'proveedor_tarifa_id' => $tarifa->id,
                'tour_origen_id' => $entrada['tour_origen_id'],
                'dia_referencial' => $entrada['dia_referencial'],
                'modo_precio' => 'tarifa_fija',
                'cantidad' => 1,
                'moneda_costo' => $tarifa->moneda,
                'costo_snapshot' => $entrada['costo'],
                'precio_venta_snapshot' => $entrada['venta'],
                'precio_convertido' => $this->priceEngine->convertirMoneda($entrada['venta'], $tarifa->moneda, $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
            ]);
        }

        if ($entrada['guia_tarifa_id']) {
            // Fix guia-como-item-real: guiasPendientes se mantiene
            // igual — sigue siendo el aviso de "falta confirmar cuál
            // guía puntual" (esa asignación es a nivel reserva,
            // reserva_items.guia_id). Lo nuevo es el AlternativaItem
            // real de abajo: costo/venta ya vienen resueltos por
            // ComboExplosionService::resolverCostoVentaItem() (mismo
            // cálculo que AltaGuia usa para un guía suelto),
            // así que el costo SÍ entra al total desde que se carga
            // el combo, aunque el guía puntual siga sin asignar.
            $guiaTarifa = GuiaTarifa::with(['guia', 'destino'])->find($entrada['guia_tarifa_id']);
            $tourOrigen = PaquetePlantilla::find($entrada['tour_origen_id']);

            $guiasPendientes[] = [
                'tour_origen_id' => $entrada['tour_origen_id'],
                'tour_origen_nombre' => $tourOrigen?->nombre,
                'guia_nombre' => $guiaTarifa?->guia?->nombre,
                'destino_nombre' => $guiaTarifa?->destino?->nombre,
            ];

            if ($guiaTarifa) {
                return AlternativaItem::create([
                    'alternativa_id' => $alternativa->id,
                    'alternativa_destino_id' => $alternativaDestinoId,
                    'origen_tipo' => AlternativaItem::ORIGEN_GUIA,
                    'guia_tarifa_id' => $guiaTarifa->id,
                    'tour_origen_id' => $entrada['tour_origen_id'],
                    'dia_referencial' => $entrada['dia_referencial'],
                    'modo_precio' => 'tarifa_fija',
                    'cantidad' => 1,
                    'moneda_costo' => $guiaTarifa->moneda,
                    'costo_snapshot' => $entrada['costo'],
                    'precio_venta_snapshot' => $entrada['venta'],
                    'precio_convertido' => $this->priceEngine->convertirMoneda($entrada['venta'], $guiaTarifa->moneda, $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
                ]);
            }
        }

        return null;
    }

    // Fila 13, fase 1b — camino modo_tours='nuevo' de desdePlantilla(): el tour
    // (o cada tour de un combo) es una línea propia con su precio por persona
    // y temporada (TourLineaService). Los ítems sueltos de un combo (hotel,
    // guía) siguen como en el clásico, agrupados con el combo por carga_combo.
    // Sin "ajuste de redondeo": no se usa en este modo (§5b).
    private function desdePlantillaModoNuevo(Alternativa $alternativa, PaquetePlantilla $paquete, int $dia, ?int $alternativaDestinoId): JsonResponse
    {
        $guiasPendientes = [];

        try {
            $resultado = DB::transaction(function () use ($alternativa, $paquete, $dia, $alternativaDestinoId, &$guiasPendientes) {
                $resultado = $this->tourLinea->cargar($alternativa, $paquete, $dia, $alternativaDestinoId);

                if ($paquete->esPaqueteCombo()) {
                    foreach ($this->comboExplosion->explotarItemsSueltos($paquete) as $entrada) {
                        $entrada['dia_referencial'] = $dia;
                        $item = $this->crearItemClasicoDesdeEntrada($alternativa, $entrada, $alternativaDestinoId, $guiasPendientes);
                        if ($item) {
                            $item->update(['carga_combo' => $resultado['carga_combo'], 'combo_origen_id' => $paquete->id]);
                            $resultado['items'][] = $item;
                        }
                    }
                }

                $alternativa->recalcularTotal();

                return $resultado;
            });
        } catch (TourLineaException $e) {
            return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Tour cargado correctamente',
            'modo_tours' => ConfiguracionAgencia::MODO_TOURS_NUEVO,
            'items_agregados' => $resultado['items'],
            'guias_pendientes' => $guiasPendientes,
            'avisos' => $resultado['avisos'],
            'dia_final_combo' => $resultado['dia_final_combo'],
            'resumen' => [
                'tours' => $paquete->esPaqueteCombo() ? $this->comboExplosion->toursDelCombo($paquete)->count() : null,
                'items' => count($resultado['items']),
            ],
        ]);
    }

    // ── Cargas de tour (fila 13, fase 1b) ─────────────────────────────
    // Las líneas de una carga de tour (una por grupo de IGV) y, si vino de un
    // combo, todas las del combo, se mueven, quitan y reprecian juntas.

    private function lineasDeCarga(Alternativa $alternativa, string $carga)
    {
        return AlternativaItem::where('alternativa_id', $alternativa->id)
            ->where(fn ($q) => $q->where('carga_tour', $carga)->orWhere('carga_combo', $carga))
            ->get();
    }

    // DELETE alternativas/{id}/cargas/{carga} — quita una carga de tour (o un
    // combo entero, con sus ítems sueltos).
    public function eliminarCarga(string $alternativaId, string $carga): JsonResponse
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $this->autorizarCotizacion('update', $alternativa);
        $items = $this->lineasDeCarga($alternativa, $carga);

        if ($items->isEmpty()) {
            return response()->json(['code' => 404, 'message' => 'No se encontró ese tour en esta alternativa.'], 404);
        }
        if ($bloqueo = ReservaItem::mensajeBloqueo($items->pluck('id'), 'quitar este tour')) {
            return response()->json(['code' => 422, 'message' => $bloqueo], 422);
        }

        DB::transaction(function () use ($items, $alternativa) {
            AlternativaItem::whereIn('id', $items->pluck('id'))->delete();
            $alternativa->recalcularTotal();
        });

        return response()->json(['code' => 200, 'message' => 'Tour quitado correctamente']);
    }

    // T-027 (H12): quitar un tour clásico (ítems con el mismo tour_origen_id, sin
    // carga_tour) borraba con un DELETE por ítem en paralelo, y cada destroy()
    // recalculaba el total con los ítems que veía en ese momento: el último en
    // guardar podía dejar el total mal, y un fallo dejaba el tour a medias.
    // Acá se borra todo en una transacción con un solo recálculo.
    public function eliminarBloque(Request $request, string $alternativaId): JsonResponse
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $this->autorizarCotizacion('update', $alternativa);

        $validator = Validator::make($request->all(), [
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'integer|distinct',
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }
        if ($alternativa->estado === 'aceptada') {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa ya fue aceptada y generó una reserva — no se pueden eliminar ítems acá.',
            ], 422);
        }

        $ids = $validator->validated()['item_ids'];

        $rechazo = DB::transaction(function () use ($alternativa, $ids) {
            Alternativa::whereKey($alternativa->id)->lockForUpdate()->first();
            $items = AlternativaItem::where('alternativa_id', $alternativa->id)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($ids)) {
                return response()->json(['code' => 404, 'message' => 'No se encontró ese tour en esta alternativa.'], 404);
            }
            $tourOrigen = $items->pluck('tour_origen_id')->unique();
            if ($tourOrigen->count() !== 1 || $tourOrigen->first() === null || $items->contains(fn ($i) => $i->carga_tour !== null)) {
                return response()->json(['code' => 422, 'message' => 'Estos servicios no forman un solo tour.'], 422);
            }
            if ($bloqueo = ReservaItem::mensajeBloqueo($items->pluck('id'), 'quitar este tour')) {
                return response()->json(['code' => 422, 'message' => $bloqueo], 422);
            }

            $aereos = $items->where('origen_tipo', AlternativaItem::ORIGEN_PASAJE_AEREO)->pluck('id');
            if ($aereos->isNotEmpty()) {
                CotizacionPasajeAereo::whereIn('alternativa_item_id', $aereos)->delete();
            }
            AlternativaItem::whereIn('id', $items->pluck('id'))->delete();
            $alternativa->recalcularTotal();

            return null;
        });

        if ($rechazo) {
            return $rechazo;
        }

        return response()->json(['code' => 200, 'message' => 'Tour eliminado correctamente']);
    }

    // POST alternativas/{id}/mover-carga — mueve una carga de tour a otro día;
    // un combo se desplaza entero, conservando la distancia entre sus días.
    public function moverCarga(Request $request, string $alternativaId): JsonResponse
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $this->autorizarCotizacion('update', $alternativa);

        $validator = Validator::make($request->all(), [
            'carga' => 'required|uuid',
            'dia_referencial' => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }
        if ($alternativa->estado === 'aceptada') {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa ya fue aceptada y generó una reserva — usa reprogramar sobre la reserva en vez de mover ítems acá.',
            ], 422);
        }

        $validado = $validator->validated();
        $items = $this->lineasDeCarga($alternativa, $validado['carga']);
        if ($items->isEmpty()) {
            return response()->json(['code' => 422, 'message' => 'No se encontró ese tour en esta alternativa.'], 422);
        }

        $delta = (int) $validado['dia_referencial'] - (int) $items->min('dia_referencial');
        DB::transaction(function () use ($items, $delta) {
            foreach ($items as $item) {
                $item->update(['dia_referencial' => max(1, (int) $item->dia_referencial + $delta)]);
            }
        });

        return response()->json([
            'code' => 200,
            'message' => 'Tour movido correctamente',
            'temporadas_desactualizadas' => $this->tourLinea->temporadasDesactualizadas($alternativa),
        ]);
    }

    // PUT alternativas/{id}/cargas/{carga}/precio — el vendedor cambia el
    // precio total de un tour (moneda de la cotización); se reparte entre sus
    // líneas de IGV y queda como precio a mano (los recálculos no lo pisan).
    public function precioCarga(Request $request, string $alternativaId, string $carga): JsonResponse
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $this->autorizarCotizacion('update', $alternativa);

        $validator = Validator::make($request->all(), ['precio' => 'required|numeric|min:0']);
        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }
        if ($alternativa->estado === 'aceptada') {
            return response()->json(['code' => 422, 'message' => 'Esta alternativa ya fue aceptada y generó una reserva — el precio ya no se cambia desde acá.'], 422);
        }

        $lineas = AlternativaItem::where('alternativa_id', $alternativa->id)
            ->where('carga_tour', $carga)
            ->where('origen_tipo', AlternativaItem::ORIGEN_TOUR)
            ->get();
        if ($lineas->isEmpty()) {
            return response()->json(['code' => 404, 'message' => 'No se encontró ese tour en esta alternativa.'], 404);
        }

        DB::transaction(function () use ($lineas, $alternativa, $validator) {
            $this->tourLinea->fijarPrecio($lineas, $alternativa, (float) $validator->validated()['precio']);
            $alternativa->recalcularTotal();
        });

        return response()->json(['code' => 200, 'message' => 'Precio actualizado correctamente', 'alternativa' => $alternativa->fresh('items')]);
    }

    // GET alternativas/{id}/temporadas-tours — tours cuya temporada ya no es
    // la de su día (cambió la fecha de la cotización). Solo aviso (§3.12).
    public function temporadasTours(string $alternativaId): JsonResponse
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $this->autorizarCotizacion('view', $alternativa);

        return response()->json(['temporadas_desactualizadas' => $this->tourLinea->temporadasDesactualizadas($alternativa)]);
    }

    // POST alternativas/{id}/recalcular-temporadas — botón "Recalcular precios
    // por temporada". Nunca se dispara solo.
    public function recalcularTemporadas(string $alternativaId): JsonResponse
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $this->autorizarCotizacion('update', $alternativa);

        if ($alternativa->estado === 'aceptada') {
            return response()->json(['code' => 422, 'message' => 'Esta alternativa ya fue aceptada: el precio vendido no cambia.'], 422);
        }

        $resultado = DB::transaction(function () use ($alternativa) {
            $resultado = $this->tourLinea->recalcularTemporadas($alternativa);
            $alternativa->recalcularTotal();

            return $resultado;
        });

        return response()->json(['code' => 200, 'message' => 'Precios por temporada recalculados'] + $resultado + ['alternativa' => $alternativa->fresh('items')]);
    }

    // Reasigna el día de un ítem SUELTO (sin tour_origen_id).
    // Los ítems que pertenecen a un bloque de tour se mueven juntos, ver
    // moverBloque() — separado de update() (piso/descuento) a propósito: es
    // otro concern y no debía arriesgar esa validación ya verificada.
    public function reasignarDia(Request $request, string $id)
    {
        $item = AlternativaItem::with('alternativa')->findOrFail($id);
        $this->autorizarCotizacion('update', $item);

        $validator = Validator::make($request->all(), [
            'dia_referencial' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        // Una alternativa 'aceptada' ya generó una reserva a partir de un snapshot de dia_referencial:
        // mover el día acá desincroniza en silencio esa reserva ya congelada. El punto de corrección
        // para una reserva ya aceptada es ReservaController::reprogramar(), no este endpoint.
        if ($item->alternativa->estado === 'aceptada') {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa ya fue aceptada y generó una reserva — usa reprogramar sobre la reserva en vez de mover ítems acá.',
            ], 422);
        }

        if ($item->tour_origen_id !== null) {
            return response()->json([
                'code' => 422,
                'message' => 'Este ítem pertenece a un bloque de tour — mueve todo el bloque desde su encabezado.',
            ], 422);
        }

        $item->update(['dia_referencial' => $validator->validated()['dia_referencial']]);

        return response()->json(['code' => 200, 'message' => 'Ítem movido correctamente', 'alternativa_item' => $item->fresh()]);
    }

    // Reasigna el día de TODOS los ítems de un mismo tour_origen_id juntos —
    // §7.1, decisión confirmada con el usuario: un bloque de tour se mueve
    // como unidad, no ítem por ítem.
    public function moverBloque(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $this->autorizarCotizacion('update', $alternativa);

        $validator = Validator::make($request->all(), [
            'tour_origen_id' => 'required|integer',
            'dia_referencial' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        // Mismo guard que reasignarDia(), ver comentario ahí.
        if ($alternativa->estado === 'aceptada') {
            return response()->json([
                'code' => 422,
                'message' => 'Esta alternativa ya fue aceptada y generó una reserva — usa reprogramar sobre la reserva en vez de mover ítems acá.',
            ], 422);
        }

        $validado = $validator->validated();

        // whereNull('carga_tour') (fila 13, fase 1b): las líneas de tour del
        // modo nuevo se mueven por su carga (moverCarga()); sin esto, mover el
        // bloque clásico de un tour arrastraba también su línea nueva.
        $actualizados = AlternativaItem::where('alternativa_id', $alternativa->id)
            ->where('tour_origen_id', $validado['tour_origen_id'])
            ->whereNull('carga_tour')
            ->update(['dia_referencial' => $validado['dia_referencial']]);

        if ($actualizados === 0) {
            return response()->json([
                'code' => 422,
                'message' => 'No se encontraron ítems de ese tour en esta alternativa.',
            ], 422);
        }

        return response()->json(['code' => 200, 'message' => 'Bloque movido correctamente']);
    }

    // Recalculo en vivo del formulario de pasaje aéreo (PasajeAereoForm.vue): la misma validación y el
    // mismo cálculo que el alta, sin persistir nada. El frontend no reimplementa la suma.
    public function previewPasajeAereo(Request $request, string $alternativaId)
    {
        $alternativa = Alternativa::findOrFail($alternativaId);
        $this->autorizarCotizacion('view', $alternativa);

        try {
            $validado = $this->altaPasajeAereo->validar($request->all(), $alternativa);
        } catch (AltaItemException $e) {
            return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
        }

        $resultado = $this->altaPasajeAereo->calcular($alternativa, $validado);

        return response()->json(['code' => 200, 'resultado' => $resultado]);
    }

    // PUT alternativa-items/{id}/pasaje-aereo — edición estructural completa de un pasaje aéreo
    // suelto. Calcado de actualizarManual() — mismo guard de "no editar si ya generó una reserva", y
    // reusa AltaPasajeAereo::validar()/calcular() (las MISMAS que usa el alta y el preview) para que
    // nunca haya una segunda fórmula que se pueda desincronizar de la primera.
    public function actualizarPasajeAereo(Request $request, string $id): JsonResponse
    {
        $item = AlternativaItem::with(['alternativa', 'cotizacionPasajeAereo'])->findOrFail($id);
        $this->autorizarCotizacion('update', $item);

        if ($item->origen_tipo !== AlternativaItem::ORIGEN_PASAJE_AEREO) {
            return response()->json(['code' => 422, 'message' => 'Solo se puede editar así un pasaje aéreo suelto.'], 422);
        }

        if ($bloqueo = ReservaItem::mensajeBloqueo([$item->id], 'editar')) {
            return response()->json(['code' => 422, 'message' => $bloqueo], 422);
        }

        $alternativa = $item->alternativa;
        try {
            $validado = $this->altaPasajeAereo->validar($request->all(), $alternativa);
        } catch (AltaItemException $e) {
            return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
        }
        // T-076: un pasaje anterior mantiene su fórmula salvo que el vendedor pida pasarlo a la nueva.
        $validado['montos_por_pasajero'] ??= (bool) $item->cotizacionPasajeAereo->montos_por_pasajero;
        $resultado = $this->altaPasajeAereo->calcular($alternativa, $validado);
        // Si no manda valor nuevo, conserva el que ya tenía el ítem (mismo
        // criterio que actualizarManual() — el default de agencia solo
        // aplica al crear, no se recalcula al editar).
        $tipAfeIgv = $validado['tip_afe_igv'] ?? $item->tip_afe_igv;
        $destinoTributario = $validado['destino_tributario'] ?? $item->destino_tributario;

        DB::transaction(function () use ($item, $alternativa, $validado, $resultado, $tipAfeIgv, $destinoTributario) {
            $item->update([
                'pax_incluidos' => $validado['pax_incluidos'] ?? null,
                'moneda_costo' => $validado['moneda'],
                'costo_snapshot' => $resultado['costo_total'],
                'precio_venta_snapshot' => $resultado['venta_total'],
                'precio_convertido' => $this->priceEngine->convertirMoneda($resultado['venta_total'], $validado['moneda'], $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
                'tip_afe_igv' => $tipAfeIgv,
                'destino_tributario' => $destinoTributario,
            ]);

            $item->cotizacionPasajeAereo->update([
                'aerolinea' => $validado['aerolinea'],
                'itinerario' => $validado['itinerario'] ?? null,
                'moneda' => $validado['moneda'],
                'tarifa_base_adulto' => $validado['tarifa_base_adulto'],
                'tarifa_base_nino' => $validado['tarifa_base_nino'] ?? null,
                'tarifa_base_infante' => $validado['tarifa_base_infante'] ?? null,
                'cargos' => $validado['cargos'] ?? [],
                'tua_incluida_en_tarifa' => $validado['tua_incluida_en_tarifa'] ?? false,
                'fee_agencia_monto' => $validado['fee_agencia_monto'] ?? 0,
                'montos_por_pasajero' => $validado['montos_por_pasajero'],
                'tip_afe_igv' => $validado['tip_afe_igv'] ?? null,
                'tarifa_valida_hasta' => $validado['tarifa_valida_hasta'],
                'costo_total' => $resultado['costo_total'],
                'precio_venta_total' => $resultado['venta_total'],
            ]);
        });

        $alternativa->recalcularTotal();
        $item->load('cotizacionPasajeAereo');

        return response()->json(['code' => 200, 'message' => 'Pasaje aéreo actualizado correctamente', 'alternativa_item' => $item->fresh(['cotizacionPasajeAereo'])]);
    }

    // Edición estructural de un ítem manual (descripción/proveedor/costo/
    // cantidad/pax) — separado de update() (que solo maneja
    // descuento_pct/precio_convertido para CUALQUIER origen_tipo, ese flujo
    // de edición inline de precio en el lienzo sigue existiendo tal cual).
    // Mismo guard que destroy(): si el ítem ya generó una reserva, no se
    // edita más, hay que cancelar la reserva primero.
    public function actualizarManual(Request $request, string $id): JsonResponse
    {
        $item = AlternativaItem::with('alternativa')->findOrFail($id);
        $this->autorizarCotizacion('update', $item);

        if ($item->origen_tipo !== AlternativaItem::ORIGEN_MANUAL) {
            return response()->json(['code' => 422, 'message' => 'Solo se puede editar así un ítem manual.'], 422);
        }

        if ($bloqueo = ReservaItem::mensajeBloqueo([$item->id], 'editar')) {
            return response()->json(['code' => 422, 'message' => $bloqueo], 422);
        }

        $validator = Validator::make($request->all(), [
            'descripcion_manual' => 'required|string|max:500',
            'proveedor_sugerido_manual' => 'nullable|string|max:250',
            'costo_snapshot' => 'required|numeric|min:0',
            'precio_venta_snapshot' => 'required|numeric|min:0',
            'moneda_costo' => 'required|in:PEN,USD',
            'cantidad' => 'required|integer|min:1',
            'pax_incluidos' => 'nullable|array',
            'pax_incluidos.*' => 'integer|exists:cotizacion_pasajeros,id',
            'tip_afe_igv' => 'nullable|string|in:10,20,30',
            'destino_tributario' => 'nullable|string|in:amazonia,nacional,extranjero',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }
        $validado = $validator->validated();

        $alternativa = $item->alternativa;

        // Nota: si el ítem ya tiene proveedor_promovido_id (ya fue
        // promovido), igual se puede editar sin problema — no relinkea ni
        // toca el proveedor ya creado, son independientes por diseño (mismo
        // criterio de promoverAProveedor()). Tratamiento tributario: si no
        // se manda, se conserva el que ya tenía el ítem (no se recalcula el
        // default de agencia acá — solo aplica al crear).
        $item->update([
            'descripcion_manual' => $validado['descripcion_manual'],
            'proveedor_sugerido_manual' => $validado['proveedor_sugerido_manual'] ?? null,
            'costo_snapshot' => $validado['costo_snapshot'],
            'moneda_costo' => $validado['moneda_costo'],
            'precio_venta_snapshot' => $validado['precio_venta_snapshot'],
            'precio_convertido' => $this->priceEngine->convertirMoneda((float) $validado['precio_venta_snapshot'], $validado['moneda_costo'], $alternativa->moneda_cotizacion, (float) $alternativa->tipo_cambio_aplicado),
            'cantidad' => $validado['cantidad'],
            'pax_incluidos' => $validado['pax_incluidos'] ?? null,
            'tip_afe_igv' => $validado['tip_afe_igv'] ?? $item->tip_afe_igv,
            'destino_tributario' => $validado['destino_tributario'] ?? $item->destino_tributario,
        ]);

        $alternativa->recalcularTotal();

        return response()->json(['code' => 200, 'message' => 'Ítem manual actualizado correctamente', 'alternativa_item' => $item->fresh()]);
    }

    // Crea Proveedor + ProveedorServicio + ProveedorTarifa a partir de un
    // ítem manual — el ítem de ESTA cotización NO se toca (snapshot ya
    // congelado, mismo criterio que el resto de alternativa_items). Solo
    // registra proveedor_promovido_id para no promover dos veces y mostrar
    // "Proveedor creado" en el lienzo. Sin relink retroactivo — decisión
    // confirmada: el proveedor creado queda disponible para próximas
    // cotizaciones, esta no se mueve.
    public function promoverAProveedor(Request $request, string $id): JsonResponse
    {
        $item = AlternativaItem::findOrFail($id);
        $this->autorizarCotizacion('update', $item);

        if ($item->origen_tipo !== AlternativaItem::ORIGEN_MANUAL) {
            return response()->json(['code' => 422, 'message' => 'Solo se puede promover un ítem manual.'], 422);
        }
        if ($item->proveedor_promovido_id) {
            return response()->json(['code' => 422, 'message' => 'Este ítem ya fue promovido a proveedor.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'razon_social' => 'required|string|max:250',
            'tipo_documento' => 'nullable|in:DNI,RUC',
            'numero_documento' => 'nullable|string|max:30',
            'destino_servicio_id' => 'required|integer|exists:destino_servicio,id',
            'costo' => 'required|numeric|min:0',
            'precio_venta_adulto' => 'required|numeric|min:0',
            'modalidad' => 'required|in:compartido,privado',
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

        $margenValor = round($v['precio_venta_adulto'] - $v['costo'], 2);
        // Ya no se hardcodea '10'/'nacional' — usa lo que el ítem manual ya
        // tiene capturado (AltaManual/actualizarManual()), con
        // fallback al default de agencia solo si el ítem es de antes de
        // este fix y nunca lo tuvo.
        $tratamientoTributario = ConfiguracionAgencia::completarTratamientoTributario($item->tip_afe_igv, $item->destino_tributario);

        [$proveedor, $tarifa] = DB::transaction(function () use ($item, $v, $destinoServicio, $tipoId, $margenValor, $tratamientoTributario) {
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

            $tarifa = ProveedorTarifa::create([
                'proveedor_servicio_id' => $proveedorServicio->id,
                'tipo_tarifa' => 'publica',
                'modalidad' => $v['modalidad'],
                'moneda' => $item->moneda_costo,
                'precio_costo' => $v['costo'],
                'margen_tipo' => 'fijo',
                'margen_valor' => $margenValor,
                'precio_venta_adulto' => $v['precio_venta_adulto'],
                'vigente_desde' => \App\Services\HoraPeru::hoyTexto(),
                'tip_afe_igv' => $tratamientoTributario['tip_afe_igv'],
                'destino_tributario' => $tratamientoTributario['destino_tributario'],
            ]);

            $item->update(['proveedor_promovido_id' => $proveedor->id]);

            return [$proveedor, $tarifa];
        });

        return response()->json([
            'code' => 200,
            'message' => 'Proveedor creado — esta cotización no cambió, queda disponible para próximas cotizaciones.',
            'proveedor' => $proveedor,
            'proveedor_tarifa' => $tarifa,
        ]);
    }

    private function contarPasajerosPorTipo(int $cotizacionId, ?array $paxIncluidosIds): array
    {
        // T-022: movido a PriceEngineService para que "Actualizar al precio nuevo" cuente igual.
        return $this->priceEngine->contarPasajerosPorTipo($cotizacionId, $paxIncluidosIds);
    }

}
