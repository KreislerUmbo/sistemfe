<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaPasajeroController;
use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaServicio;
use App\Models\AgenciaViajes\SalidaVehiculo;
use App\Services\AgenciaViajes\SalidaOperacionService;
use Illuminate\Http\Request;
use Tests\Feature\AgenciaViajes\Concerns\SalidasFixtures;
use Tests\TestCase;

// T-040 (H29, H37) — avisos a los proveedores también al reprogramar la
// reserva, cambiar la fecha de un servicio, quitar un servicio, agregar un
// pasajero y cancelar la salida (D15). «Modificar reserva»: en
// ReservaModificacionAplicarTest. Fixtures en Concerns\SalidasFixtures.
class T040AvisosSalidasTest extends TestCase
{
    use SalidasFixtures;

    // CA1
    public function test_reprogramar_avisa_a_la_salida_vieja_y_a_la_nueva(): void
    {
        [$salida, $tres] = $this->salidaCompartida();

        $respuesta = $this->reprogramar($tres, '2026-11-20');

        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
        $porSalida = collect($respuesta['avisos_salidas'])->keyBy('fecha');
        $this->assertSame(['El Mirador (Almuerzo): ahora son 2, no 5.'], $porSalida['15/11/2026']['avisos']);
        $this->assertSame($salida->id, $porSalida['15/11/2026']['salida_id']);
        // La del 20 nació con la reprogramación: hay que reservarla.
        $nueva = $porSalida['20/11/2026']['avisos'];
        $this->assertCount(1, $nueva);
        $this->assertMatchesRegularExpression('/^El Mirador \(Almuerzo.*\): salida nueva del 20\/11\/2026, son 3\. Hay que reservar\.$/', $nueva[0]);
    }

    // CA1 — la salida del día nuevo ya existía: se compara con su foto de antes.
    public function test_reprogramar_a_una_salida_que_ya_existe_avisa_cuantos_son_ahora(): void
    {
        [, $tres] = $this->salidaCompartida();
        $otra = $this->reservaConTour($this->tour, [['adulto', 50]]);
        $this->reprogramar($otra, '2026-11-20');
        SalidaServicio::where('salida_operativa_id', $this->salidaDe($otra)->id)->update(['nombre' => 'Almuerzo']);

        $respuesta = $this->reprogramar($tres, '2026-11-20');

        $porSalida = collect($respuesta['avisos_salidas'])->keyBy('fecha');
        $this->assertSame(['El Mirador (Almuerzo): ahora son 4, no 1.'], $porSalida['20/11/2026']['avisos']);
    }

    public function test_cambiar_la_fecha_de_un_servicio_enganchado_avisa_como_reprogramar(): void
    {
        [, $tres] = $this->salidaCompartida();
        $item = ReservaItem::where('reserva_id', $tres->id)->whereNotNull('salida_operativa_id')->firstOrFail();

        $respuesta = app(ReservaItemController::class)->update(new Request(['fecha' => '2026-11-21']), (string) $item->id)->getData(true);

        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
        $porSalida = collect($respuesta['avisos_salidas'])->keyBy('fecha');
        $this->assertSame(['El Mirador (Almuerzo): ahora son 2, no 5.'], $porSalida['15/11/2026']['avisos']);
        $this->assertStringContainsString('salida nueva del 21/11/2026, son 3', $porSalida['21/11/2026']['avisos'][0]);
    }

    public function test_editar_un_servicio_sin_cambiar_la_fecha_no_avisa(): void
    {
        [, $tres] = $this->salidaCompartida();
        $item = ReservaItem::where('reserva_id', $tres->id)->whereNotNull('salida_operativa_id')->firstOrFail();

        $respuesta = app(ReservaItemController::class)->update(new Request(['hora' => '08:30']), (string) $item->id)->getData(true);

        $this->assertSame([], $respuesta['avisos_salidas']);
    }

    // CA2
    public function test_quitar_un_servicio_enganchado_avisa_a_su_salida(): void
    {
        [, $tres] = $this->salidaCompartida();
        $item = ReservaItem::where('reserva_id', $tres->id)->whereNotNull('salida_operativa_id')->firstOrFail();
        $this->otroServicioSinSalida($item);

        $respuesta = app(ReservaItemController::class)->destroy((string) $item->id)->getData(true);

        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
        $this->assertSame(['El Mirador (Almuerzo): ahora son 2, no 5.'], $respuesta['avisos_salidas'][0]['avisos']);
    }

    // CA3
    public function test_agregar_un_pasajero_avisa_a_cada_salida(): void
    {
        [$salida, $tres, $dos] = $this->salidaCompartida();
        $van = SalidaVehiculo::create([
            'salida_operativa_id' => $salida->id, 'descripcion' => 'Van 14', 'capacidad' => 14,
            'proveedor_id' => $this->proveedor('Turismo Selva')->id,
        ]);
        $van->reservas()->sync([$tres->id, $dos->id]);

        $respuesta = app(ReservaPasajeroController::class)->store(new Request(['tipo_pax' => 'adulto']), (string) $tres->id)->getData(true);

        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
        $this->assertSame([
            'El Mirador (Almuerzo): ahora son 6, no 5.',
            'Turismo Selva (Van 14): ahora lleva 6 pasajero(s), no 5.',
        ], $respuesta['avisos_salidas'][0]['avisos']);
    }

    // CA3b
    public function test_cancelar_la_salida_avisa_que_ya_no_va_a_cada_proveedor(): void
    {
        [$salida] = $this->salidaCompartida();
        SalidaVehiculo::create([
            'salida_operativa_id' => $salida->id, 'descripcion' => 'Van 14', 'capacidad' => 14,
            'proveedor_id' => $this->proveedor('Turismo Selva')->id,
        ]);

        $respuesta = app(SalidaOperativaController::class)->cancelar((string) $salida->id)->getData(true);

        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
        $this->assertSame([
            'El Mirador (Almuerzo): la salida del 15/11/2026 ya no va.',
            'Turismo Selva (Van 14): la salida del 15/11/2026 ya no va.',
        ], $respuesta['avisos']);
        $this->assertSame(SalidaOperativa::ESTADO_CANCELADA, $salida->fresh()->estado);
    }

    // CA4
    public function test_una_reserva_sin_salidas_no_trae_avisos(): void
    {
        $reserva = $this->reservaConTour($this->tourConAlmuerzo(), [['adulto', 40], ['adulto', 41]]);
        $salidaId = $this->salidaDe($reserva)->id;
        $item = ReservaItem::where('reserva_id', $reserva->id)->firstOrFail();
        $item->update(['salida_operativa_id' => null]);
        SalidaOperativa::eliminarSiQuedoVacia($salidaId);
        $this->otroServicioSinSalida($item);

        $agregar = app(ReservaPasajeroController::class)->store(new Request(['tipo_pax' => 'adulto']), (string) $reserva->id)->getData(true);
        $quitar = app(ReservaItemController::class)->destroy((string) $item->id)->getData(true);
        $cancelar = app(ReservaController::class)->cancelar(new Request(['motivo_cancelacion' => 'voluntaria']), (string) $reserva->id)->getData(true);

        $this->assertSame([], $agregar['avisos_salidas']);
        $this->assertSame([], $quitar['avisos_salidas']);
        $this->assertSame([], $cancelar['avisos_salidas']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Apoyo
    // ═══════════════════════════════════════════════════════════════

    private $tour;

    private function tourConAlmuerzo()
    {
        $tour = $this->crearTour();
        $this->incluye($tour, 25, nombre: 'Almuerzo', proveedor: $this->proveedor('El Mirador'));

        return $tour;
    }

    /**
     * Salida del 15-nov con una reserva de 3 adultos y otra de 2; el almuerzo
     * de El Mirador.
     *
     * @return array{0: SalidaOperativa, 1: Reserva, 2: Reserva}
     */
    private function salidaCompartida(): array
    {
        $this->tour = $this->tourConAlmuerzo();
        $tres = $this->reservaConTour($this->tour, [['adulto', 40], ['adulto', 38], ['adulto', 30]]);
        $dos = $this->reservaConTour($this->tour, [['adulto', 35], ['adulto', 33]]);
        $salida = $this->salidaDe($tres);
        $this->assertSame($salida->id, $this->salidaDe($dos)->id);
        SalidaServicio::where('salida_operativa_id', $salida->id)->update(['nombre' => 'Almuerzo']);

        return [$salida->fresh(), $tres, $dos];
    }

    private function reprogramar(Reserva $reserva, string $fecha): array
    {
        return app(ReservaController::class)->reprogramar(
            new Request(['fecha_viaje_desde' => $fecha, 'motivo' => 'Lluvia']), (string) $reserva->id
        )->getData(true);
    }

    // Una reserva no puede quedar sin servicios: copia sin salida para poder quitar el otro.
    private function otroServicioSinSalida(ReservaItem $item): void
    {
        $copia = $item->replicate();
        $copia->salida_operativa_id = null;
        $copia->save();
    }
}
