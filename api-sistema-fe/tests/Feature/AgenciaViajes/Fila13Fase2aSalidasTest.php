<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\TourItinerarioItem;
use App\Services\AgenciaViajes\AsignacionOperativa;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fila 13, fase 2a — la salida agrupa las líneas de tour
// (plan-tours-venta-simple-costo-real.md §3.6, D6 y D9). Mismo patrón que los
// tests de la fase 1: Postgres real (sistemafe_test_migrations), transacción
// por test revertida.
class Fila13Fase2aSalidasTest extends TestCase
{
    private DestinoAtractivo $destino;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => env('DB_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => env('DB_PORT', '5432'),
            'database.connections.pgsql.database' => 'sistemafe_test_migrations',
            'database.connections.pgsql.username' => env('DB_USERNAME', 'root'),
            'database.connections.pgsql.password' => env('DB_PASSWORD', ''),
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();

        $this->destino = DestinoAtractivo::create(['nombre' => 'Laguna Azul '.uniqid(), 'tipo' => 'zona']);
        ConfiguracionAgencia::first()->update([
            'modo_tours' => ConfiguracionAgencia::MODO_TOURS_NUEVO,
            'tip_afe_igv_default' => '10',
            'destino_tributario_default' => 'nacional',
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════
    // Enganche automático
    // ═══════════════════════════════════════════════════════════════

    public function test_tour_compartido_dos_reservas_van_a_la_misma_salida_con_sus_pasajeros(): void
    {
        $tour = $this->crearTour(['pax_minimo_salida' => 6]);
        $rojas = $this->reservaConTour($tour, [['adulto', 40], ['adulto', 38]]);
        $perez = $this->reservaConTour($tour, [['adulto', 35], ['nino', 8], ['infante', 1, 'extranjero']]);

        $salidaRojas = $this->salidaDe($rojas);
        $this->assertNotNull($salidaRojas);
        $this->assertSame($salidaRojas->id, $this->salidaDe($perez)->id);
        $this->assertSame(SalidaOperativa::ESTADO_PROGRAMADA, $salidaRojas->estado);
        $this->assertFalse($salidaRojas->esPrivada());

        $resumen = app(SalidaOperativaController::class)->show((string) $salidaRojas->id)->getData(true);
        $this->assertSame(5, $resumen['total_pax']);
        $this->assertSame(2, $resumen['total_reservas']);
        $this->assertSame(['adultos' => 3, 'ninos' => 1, 'infantes' => 1, 'extranjeros' => 1], $resumen['pax']);
        // 4 que cuentan (el infante no) contra un mínimo de 6.
        $this->assertSame(4, $resumen['pax_que_cuentan']);
        $this->assertTrue($resumen['bajo_minimo']);
        $this->assertCount(5, $resumen['pasajeros']);
    }

    public function test_tour_privado_cada_reserva_tiene_su_propia_salida(): void
    {
        $tour = $this->crearTour(['modalidad' => 'privado']);
        $rojas = $this->reservaConTour($tour, [['adulto', 40]]);
        $perez = $this->reservaConTour($tour, [['adulto', 35]]);

        $salidaRojas = $this->salidaDe($rojas);
        $salidaPerez = $this->salidaDe($perez);

        $this->assertNotSame($salidaRojas->id, $salidaPerez->id);
        $this->assertSame($rojas->id, $salidaRojas->privada_reserva_id);
        $this->assertSame($perez->id, $salidaPerez->privada_reserva_id);
        $this->assertSame($salidaRojas->fecha->toDateString(), $salidaPerez->fecha->toDateString());
    }

    public function test_reprogramar_mueve_la_linea_de_tour_a_la_salida_de_la_nueva_fecha(): void
    {
        $tour = $this->crearTour();
        $reserva = $this->reservaConTour($tour, [['adulto', 40]]);
        $salidaVieja = $this->salidaDe($reserva);

        $respuesta = app(ReservaController::class)->reprogramar(new Request([
            'fecha_viaje_desde' => '2026-12-01',
            'fecha_viaje_hasta' => '2026-12-01',
            'motivo' => 'Lluvia',
        ]), (string) $reserva->id);

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $salidaNueva = $this->salidaDe($reserva);
        $this->assertSame('2026-12-01', $salidaNueva->fecha->toDateString());
        $this->assertNotSame($salidaVieja->id, $salidaNueva->id);
        // La vieja quedó sin nadie: se borra (SalidaOperativa::eliminarSiQuedoVacia()).
        $this->assertNull(SalidaOperativa::find($salidaVieja->id));
    }

    // ═══════════════════════════════════════════════════════════════
    // Estados (D9)
    // ═══════════════════════════════════════════════════════════════

    public function test_estados_avanzan_un_paso_y_realizada_no_puede_ser_futura(): void
    {
        $salida = $this->salidaDe($this->reservaConTour($this->crearTour(), [['adulto', 40]]));
        $controller = app(SalidaOperativaController::class);
        $cambiar = fn (string $estado) => $controller->cambiarEstado(new Request(['estado' => $estado]), (string) $salida->id);

        // De programada no se salta a realizada.
        $this->assertSame(422, $cambiar('realizada')->getStatusCode());
        $this->assertSame(200, $cambiar('confirmada')->getStatusCode());
        // La salida es del 15-nov-2026, en el futuro: todavía no puede estar realizada.
        $this->assertSame(422, $cambiar('realizada')->getStatusCode());

        $salida->update(['fecha' => HoraPeru::hoyTexto()]);
        $this->assertSame(200, $cambiar('realizada')->getStatusCode());
        $this->assertSame(422, $controller->cancelar((string) $salida->id)->getStatusCode()); // realizada no se cancela
        $this->assertSame(200, $cambiar('confirmada')->getStatusCode()); // deshacer un paso
    }

    public function test_una_salida_cancelada_no_vuelve_por_cambiar_estado(): void
    {
        $salida = $this->salidaDe($this->reservaConTour($this->crearTour(), [['adulto', 40]]));
        app(SalidaOperativaController::class)->cancelar((string) $salida->id);

        $respuesta = app(SalidaOperativaController::class)->cambiarEstado(new Request(['estado' => 'programada']), (string) $salida->id);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame(SalidaOperativa::ESTADO_CANCELADA, $salida->fresh()->estado);
    }

    // ═══════════════════════════════════════════════════════════════
    // Enganche manual
    // ═══════════════════════════════════════════════════════════════

    public function test_una_linea_de_tour_no_se_engancha_a_la_salida_de_otro_tour(): void
    {
        $reserva = $this->reservaConTour($this->crearTour(), [['adulto', 40]]);
        $otra = $this->salidaDe($this->reservaConTour($this->crearTour(['nombre' => 'Lamas']), [['adulto', 30]]));
        $item = ReservaItem::where('reserva_id', $reserva->id)->first();

        $respuesta = app(SalidaOperativaController::class)->attachReservaItem(new Request(['reserva_item_id' => $item->id]), (string) $otra->id);

        $this->assertSame(422, $respuesta->getStatusCode());
    }

    public function test_las_lineas_de_un_tour_mezclado_se_desenganchan_juntas(): void
    {
        $tour = $this->crearTour(['costo_estimado_persona' => null]);
        $this->incluye($tour, 60, '20', 'amazonia');
        $this->incluye($tour, 20, '10', 'nacional');
        $reserva = $this->reservaConTour($tour, [['adulto', 40]]);
        $lineas = ReservaItem::where('reserva_id', $reserva->id)->get();
        $this->assertCount(2, $lineas);
        $salidaId = $lineas->first()->salida_operativa_id;

        app(SalidaOperativaController::class)->detachReservaItem((string) $salidaId, (string) $lineas->first()->id);

        $this->assertSame([null, null], ReservaItem::where('reserva_id', $reserva->id)->pluck('salida_operativa_id')->all());
        $this->assertNull(SalidaOperativa::find($salidaId)); // quedó vacía
    }

    // ═══════════════════════════════════════════════════════════════
    // Sin asignar (Reporte Operativo e Inicio)
    // ═══════════════════════════════════════════════════════════════

    public function test_linea_de_tour_sin_asignar_si_el_tour_lleva_guia_y_la_salida_no_tiene(): void
    {
        $conGuia = $this->crearTour();
        $tarifaGuia = $this->guiaTarifa();
        PaquetePlantillaItem::create(['paquete_plantilla_id' => $conGuia->id, 'guia_tarifa_id' => $tarifaGuia->id, 'costo_estimado_persona' => 20, 'orden' => 1]);
        $item = ReservaItem::with('salidaOperativa', 'alternativaItem')->where('reserva_id', $this->reservaConTour($conGuia, [['adulto', 40]])->id)->first();

        $this->assertTrue(AsignacionOperativa::sinAsignar($item));

        $item->salidaOperativa->update(['guia_id' => $tarifaGuia->guia_id]);
        $this->assertFalse(AsignacionOperativa::sinAsignar($item->fresh(['salidaOperativa', 'alternativaItem'])));

        // Un tour sin guía en su Incluye no queda pendiente por falta de guía.
        $sinGuia = ReservaItem::with('salidaOperativa', 'alternativaItem')
            ->where('reserva_id', $this->reservaConTour($this->crearTour(['nombre' => 'Sin guía']), [['adulto', 40]])->id)->first();
        $this->assertFalse(AsignacionOperativa::sinAsignar($sinGuia));

        // Sin salida (o con la salida cancelada): pendiente.
        $sinGuia->salidaOperativa->update(['estado' => SalidaOperativa::ESTADO_CANCELADA]);
        $this->assertTrue(AsignacionOperativa::sinAsignar($sinGuia->fresh(['salidaOperativa', 'alternativaItem'])));
    }

    // ═══════════════════════════════════════════════════════════════
    // Fixtures
    // ═══════════════════════════════════════════════════════════════

    private function crearTour(array $atributos = []): PaquetePlantilla
    {
        $tour = PaquetePlantilla::create($atributos + [
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Laguna Azul Full Day',
            'destino_atractivo_id' => $this->destino->id,
            'duracion_horas' => 10,
            'precio_venta_final' => 150,
            'costo_estimado_persona' => 100,
            'modalidad' => 'compartido',
        ]);
        TourItinerarioItem::create([
            'tour_id' => $tour->id, 'dia_relativo' => 1, 'orden' => 1,
            'destino_atractivo_id' => $this->destino->id, 'descripcion' => 'Día 1',
        ]);

        return $tour;
    }

    private function incluye(PaquetePlantilla $tour, float $costo, string $igv, string $destino): void
    {
        $servicio = \App\Models\AgenciaViajes\Servicio::create(['nombre' => 'Servicio '.uniqid()]);
        $destinoServicio = \App\Models\AgenciaViajes\DestinoServicio::create(['destino_atractivo_id' => $this->destino->id, 'servicio_id' => $servicio->id]);
        PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $tour->id, 'destino_servicio_id' => $destinoServicio->id,
            'costo_estimado_persona' => $costo, 'tip_afe_igv' => $igv, 'destino_tributario' => $destino,
            'orden' => PaquetePlantillaItem::where('paquete_plantilla_id', $tour->id)->count() + 1,
        ]);
    }

    private function guiaTarifa(): GuiaTarifa
    {
        $guia = Guia::create(['nombre' => 'Guía Test', 'documento' => (string) random_int(10000000, 99999999), 'telefono' => '999999999', 'activo' => true]);

        return GuiaTarifa::create([
            'guia_id' => $guia->id, 'destino_id' => $this->destino->id, 'modalidad' => 'dia_local',
            'costo_diario' => 100, 'tipo_margen' => 'porcentaje', 'margen_valor' => 25,
            'moneda' => 'PEN', 'vigente_desde' => HoraPeru::hoyTexto(),
        ]);
    }

    /**
     * Cotización con el tour cargado el día 1 (15-nov-2026) y su reserva aceptada.
     *
     * @param  array<int, array{0: string, 1: int, 2?: string}>  $pasajeros  [tipo, edad, residencia]
     */
    private function reservaConTour(PaquetePlantilla $tour, array $pasajeros): Reserva
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente Test 2a',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-F2A-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-11-15', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($pasajeros as $p) {
            CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => $p[0], 'edad' => $p[1], 'residencia' => $p[2] ?? 'nacional']);
        }
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 3.75, 'tipo_cambio_origen' => 'dia',
        ]);
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_atractivo_id' => $this->destino->id, 'orden' => 1]);

        $carga = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );
        $this->assertLessThan(300, $carga->getStatusCode(), json_encode($carga->getData(true)));

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return $reserva;
    }

    private function salidaDe(Reserva $reserva): ?SalidaOperativa
    {
        $id = ReservaItem::where('reserva_id', $reserva->id)->value('salida_operativa_id');

        return $id ? SalidaOperativa::find($id) : null;
    }
}
