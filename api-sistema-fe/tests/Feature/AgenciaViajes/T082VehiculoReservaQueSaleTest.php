<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaVehiculo;
use Illuminate\Http\Request;
use Tests\Feature\AgenciaViajes\Concerns\SalidasFixtures;
use Tests\TestCase;

// T-082 (hallazgo de T-040) — una reserva que deja una salida (reprogramar,
// cambiar la fecha o quitar el servicio) sale también de sus vehículos. Antes
// solo cancelar() la sacaba: la fila quedaba y, si la reserva volvía a esa
// salida, reaparecía sola en el vehículo. Fixtures en Concerns\SalidasFixtures.
class T082VehiculoReservaQueSaleTest extends TestCase
{
    use SalidasFixtures;

    public function test_reprogramar_saca_a_la_reserva_del_vehiculo_y_al_volver_no_reaparece(): void
    {
        [$salida, $van, $tres, $dos] = $this->salidaConVan();

        $this->reprogramar($tres, '2026-11-20');
        $this->assertSame([$dos->id], $van->reservas()->pluck('reserva.id')->all());

        $this->reprogramar($tres, '2026-11-15');
        $this->assertSame($salida->id, $this->salidaDe($tres)->id);
        $this->assertSame([$dos->id], $van->reservas()->pluck('reserva.id')->all());
    }

    public function test_cambiar_la_fecha_del_servicio_saca_a_la_reserva_del_vehiculo(): void
    {
        [, $van, $tres, $dos] = $this->salidaConVan();
        $item = ReservaItem::where('reserva_id', $tres->id)->whereNotNull('salida_operativa_id')->firstOrFail();

        app(ReservaItemController::class)->update(new Request(['fecha' => '2026-11-21']), (string) $item->id);

        $this->assertSame([$dos->id], $van->reservas()->pluck('reserva.id')->all());
    }

    public function test_quitar_el_servicio_saca_a_la_reserva_del_vehiculo(): void
    {
        [, $van, $tres, $dos] = $this->salidaConVan();
        $item = ReservaItem::where('reserva_id', $tres->id)->whereNotNull('salida_operativa_id')->firstOrFail();
        $copia = $item->replicate();
        $copia->salida_operativa_id = null;
        $copia->save();

        app(ReservaItemController::class)->destroy((string) $item->id);

        $this->assertSame([$dos->id], $van->reservas()->pluck('reserva.id')->all());
    }

    // Tour con varias líneas de IGV: mientras le quede una en la salida, sigue en el vehículo.
    public function test_con_otra_linea_en_la_salida_la_reserva_sigue_en_el_vehiculo(): void
    {
        [$salida, $van, $tres] = $this->salidaConVan();
        $item = ReservaItem::where('reserva_id', $tres->id)->whereNotNull('salida_operativa_id')->firstOrFail();
        $otraLinea = $item->replicate();
        $otraLinea->save();

        $item->update(['salida_operativa_id' => null]);
        SalidaOperativa::eliminarSiQuedoVacia($salida->id);

        $this->assertContains($tres->id, $van->reservas()->pluck('reserva.id')->all());
    }

    // ═══════════════════════════════════════════════════════════════
    // Apoyo
    // ═══════════════════════════════════════════════════════════════

    /**
     * Salida del 15-nov con una reserva de 3 adultos y otra de 2, las dos en la van.
     *
     * @return array{0: SalidaOperativa, 1: SalidaVehiculo, 2: Reserva, 3: Reserva}
     */
    private function salidaConVan(): array
    {
        $tour = $this->crearTour();
        $this->incluye($tour, 25, nombre: 'Almuerzo', proveedor: $this->proveedor('El Mirador'));
        $tres = $this->reservaConTour($tour, [['adulto', 40], ['adulto', 38], ['adulto', 30]]);
        $dos = $this->reservaConTour($tour, [['adulto', 35], ['adulto', 33]]);
        $salida = $this->salidaDe($tres);
        $van = SalidaVehiculo::create(['salida_operativa_id' => $salida->id, 'descripcion' => 'Van 14', 'capacidad' => 14]);
        $van->reservas()->sync([$tres->id, $dos->id]);

        return [$salida, $van, $tres, $dos];
    }

    private function reprogramar(Reserva $reserva, string $fecha): void
    {
        $respuesta = app(ReservaController::class)->reprogramar(
            new Request(['fecha_viaje_desde' => $fecha, 'motivo' => 'Lluvia']), (string) $reserva->id
        )->getData(true);
        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
    }
}
