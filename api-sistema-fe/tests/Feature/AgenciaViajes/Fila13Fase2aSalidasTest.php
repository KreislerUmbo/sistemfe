<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Services\AgenciaViajes\AsignacionOperativa;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Tests\Feature\AgenciaViajes\Concerns\SalidasFixtures;
use Tests\TestCase;

// Fila 13, fase 2a — la salida agrupa las líneas de tour
// (plan-tours-venta-simple-costo-real.md §3.6, D6 y D9). Fixtures en
// Concerns\SalidasFixtures (Postgres real, transacción por test revertida).
class Fila13Fase2aSalidasTest extends TestCase
{
    use SalidasFixtures;

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
}
