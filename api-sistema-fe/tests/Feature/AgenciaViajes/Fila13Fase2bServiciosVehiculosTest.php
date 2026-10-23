<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Http\Controllers\AgenciaViajes\SalidaServicioController;
use App\Http\Controllers\AgenciaViajes\SalidaVehiculoController;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaServicio;
use App\Models\AgenciaViajes\SalidaVehiculo;
use App\Services\AgenciaViajes\AsignacionOperativa;
use App\Services\AgenciaViajes\SalidaServiciosService;
use Illuminate\Http\Request;
use Tests\Feature\AgenciaViajes\Concerns\SalidasFixtures;
use Tests\TestCase;

// Fila 13, fase 2b — servicios a reservar y vehículos de la salida
// (plan-tours-venta-simple-costo-real.md §3.6, D10-D13). Fixtures en
// Concerns\SalidasFixtures.
class Fila13Fase2bServiciosVehiculosTest extends TestCase
{
    use SalidasFixtures;

    // ═══════════════════════════════════════════════════════════════
    // Servicios a reservar (D10, D11)
    // ═══════════════════════════════════════════════════════════════

    public function test_la_salida_arma_sus_servicios_desde_el_incluye_sin_guia_ni_duplicados(): void
    {
        $mirador = $this->proveedor('El Mirador');
        $tour = $this->crearTour();
        $this->incluye($tour, 25, nombre: 'Almuerzo', proveedor: $mirador);
        $this->incluye($tour, 30, nombre: 'Transporte');
        PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'guia_tarifa_id' => $this->guiaTarifa()->id, 'orden' => 3]);

        $rojas = $this->reservaConTour($tour, [['adulto', 40], ['adulto', 38]]);
        $this->reservaConTour($tour, [['adulto', 35], ['nino', 8], ['infante', 1]]);

        $detalle = app(SalidaOperativaController::class)->show((string) $this->salidaDe($rojas)->id)->getData(true);
        $servicios = collect($detalle['servicios']);

        // El guía no entra (se asigna en la salida o en el vehículo); la
        // segunda reserva no duplica la lista.
        $this->assertCount(2, $servicios);
        $almuerzo = $servicios->first(fn ($s) => str_starts_with($s['nombre'], 'Almuerzo'));
        $this->assertSame($mirador->id, $almuerzo['proveedor_id']);
        $this->assertSame(SalidaServicio::ESTADO_POR_RESERVAR, $almuerzo['estado']);
        // Cantidad automática: adultos + niños (4); el infante no.
        $this->assertSame(4, $almuerzo['cantidad']);
        $this->assertSame(1, $detalle['servicios_sin_proveedor']); // el transporte
    }

    public function test_actualizar_desde_el_tour_agrega_lo_nuevo_sin_tocar_lo_asignado(): void
    {
        $tour = $this->crearTour();
        $this->incluye($tour, 25, nombre: 'Almuerzo');
        $salida = $this->salidaDe($this->reservaConTour($tour, [['adulto', 40]]));
        $almuerzo = SalidaServicio::where('salida_operativa_id', $salida->id)->first();
        $otro = $this->proveedor('Otro restaurante');
        $almuerzo->update(['proveedor_id' => $otro->id, 'estado' => SalidaServicio::ESTADO_RESERVADO, 'cantidad_manual' => 7]);

        $this->incluye($tour, 10, nombre: 'Entrada');
        $respuesta = app(SalidaServicioController::class)->actualizarDesdeTour((string) $salida->id, app(SalidaServiciosService::class))->getData(true);

        $this->assertSame(1, $respuesta['agregados']);
        $almuerzo->refresh();
        $this->assertSame($otro->id, $almuerzo->proveedor_id);
        $this->assertSame(SalidaServicio::ESTADO_RESERVADO, $almuerzo->estado);
        $this->assertSame(7, $almuerzo->cantidad_manual);
    }

    public function test_cantidad_a_mano_y_reservado_exige_proveedor(): void
    {
        $tour = $this->crearTour();
        $this->incluye($tour, 30, nombre: 'Transporte');
        $salida = $this->salidaDe($this->reservaConTour($tour, [['adulto', 40], ['adulto', 38]]));
        $servicio = SalidaServicio::where('salida_operativa_id', $salida->id)->first();
        $controller = app(SalidaServicioController::class);

        // Sin proveedor no se puede marcar reservado.
        $this->assertSame(422, $controller->update(new Request(['estado' => 'reservado']), (string) $servicio->id)->getStatusCode());

        $this->assertSame(200, $controller->update(new Request([
            'proveedor_id' => $this->proveedor('Turismo Selva')->id, 'estado' => 'reservado', 'cantidad_manual' => 5,
        ]), (string) $servicio->id)->getStatusCode());
        $this->assertSame(5, $this->servicioDelDetalle($salida->id, $servicio->id)['cantidad']);

        // null = vuelve a la cantidad automática (2 adultos).
        $controller->update(new Request(['cantidad_manual' => null]), (string) $servicio->id);
        $this->assertSame(2, $this->servicioDelDetalle($salida->id, $servicio->id)['cantidad']);
    }

    public function test_servicio_agregado_a_mano(): void
    {
        $salida = $this->salidaDe($this->reservaConTour($this->crearTour(), [['adulto', 40]]));

        $respuesta = app(SalidaServicioController::class)->store(new Request(['nombre' => 'Botiquín extra', 'cantidad_manual' => 1]), (string) $salida->id)->getData(true);

        $this->assertSame(200, $respuesta['code']);
        $this->assertNull(SalidaServicio::find($respuesta['servicio']['id'])->paquete_plantilla_item_id);
    }

    public function test_un_servicio_sin_proveedor_deja_el_tour_sin_asignar(): void
    {
        $tour = $this->crearTour();
        $this->incluye($tour, 30, nombre: 'Transporte');
        $reserva = $this->reservaConTour($tour, [['adulto', 40]]);
        $item = fn () => ReservaItem::with(['salidaOperativa.servicios', 'alternativaItem'])->where('reserva_id', $reserva->id)->first();

        $this->assertTrue(AsignacionOperativa::sinAsignar($item()));

        SalidaServicio::where('salida_operativa_id', $this->salidaDe($reserva)->id)->update(['proveedor_id' => $this->proveedor()->id]);
        $this->assertFalse(AsignacionOperativa::sinAsignar($item()));
    }

    // ═══════════════════════════════════════════════════════════════
    // Vehículos (D12, D13)
    // ═══════════════════════════════════════════════════════════════

    public function test_varios_vehiculos_una_reserva_va_en_uno_solo_y_avisa_capacidad(): void
    {
        $tour = $this->crearTour();
        $rojas = $this->reservaConTour($tour, [['adulto', 40], ['adulto', 38], ['infante', 1]]);
        $perez = $this->reservaConTour($tour, [['adulto', 35]]);
        $salida = $this->salidaDe($rojas);
        $controller = app(SalidaVehiculoController::class);

        $vanA = $controller->store(new Request(['descripcion' => 'Van A', 'capacidad' => 1]), (string) $salida->id)->getData(true)['vehiculo'];
        // El primer vehículo lleva, de entrada, a todas las reservas de la salida.
        $this->assertEqualsCanonicalizing([$rojas->id, $perez->id], SalidaVehiculo::find($vanA['id'])->reservas()->pluck('reserva.id')->all());

        $vanB =$controller->store(new Request(['descripcion' => 'Van B', 'capacidad' => 10, 'chofer_nombre' => 'Juan']), (string) $salida->id)->getData(true)['vehiculo'];

        // Rojas: 2 que ocupan asiento (el infante no) en una van de 1 → aviso, no bloqueo.
        $respuesta = $controller->asignarReservas(new Request(['reserva_ids' => [$rojas->id]]), (string) $vanA['id'])->getData(true);
        $this->assertSame(200, $respuesta['code']);
        $this->assertTrue($respuesta['excede_capacidad']);
        $this->assertSame(2, $respuesta['pasajeros']);

        // Pasar a los Rojas a la van B los saca de la A.
        $controller->asignarReservas(new Request(['reserva_ids' => [$rojas->id, $perez->id]]), (string) $vanB['id']);
        $this->assertSame([], SalidaVehiculo::find($vanA['id'])->reservas()->pluck('reserva.id')->all());
        $this->assertCount(2, SalidaVehiculo::find($vanB['id'])->reservas);

        $detalle = app(SalidaOperativaController::class)->show((string) $salida->id)->getData(true);
        $this->assertSame(11, $detalle['capacidad_total']);
        $this->assertSame(0, $detalle['reservas_sin_vehiculo']);
    }

    public function test_no_se_asigna_a_un_vehiculo_una_reserva_de_otra_salida(): void
    {
        $salida = $this->salidaDe($this->reservaConTour($this->crearTour(), [['adulto', 40]]));
        $ajena = $this->reservaConTour($this->crearTour(['nombre' => 'Lamas']), [['adulto', 30]]);
        $van = SalidaVehiculo::create(['salida_operativa_id' => $salida->id, 'descripcion' => 'Van']);

        $respuesta = app(SalidaVehiculoController::class)->asignarReservas(new Request(['reserva_ids' => [$ajena->id]]), (string) $van->id);

        $this->assertSame(422, $respuesta->getStatusCode());
    }

    public function test_el_guia_del_vehiculo_es_el_guia_de_sus_reservas(): void
    {
        $tour = $this->crearTour();
        $rojas = $this->reservaConTour($tour, [['adulto', 40]]);
        $perez = $this->reservaConTour($tour, [['adulto', 35]]);
        $salida = $this->salidaDe($rojas);
        $principal = $this->guiaTarifa()->guia;
        $delVehiculo = $this->guiaTarifa()->guia;
        $salida->update(['guia_id' => $principal->id]);
        $van = SalidaVehiculo::create(['salida_operativa_id' => $salida->id, 'descripcion' => 'Van 2', 'guia_id' => $delVehiculo->id]);
        SalidaVehiculo::create(['salida_operativa_id' => $salida->id, 'descripcion' => 'Van 1']);
        $van->reservas()->sync([$perez->id]);

        $guiaDe = fn ($reserva) => AsignacionOperativa::guiaEfectivo(
            ReservaItem::with('salidaOperativa.vehiculos.reservas', 'salidaOperativa.vehiculos.guia', 'salidaOperativa.guia')->where('reserva_id', $reserva->id)->first()
        );

        $this->assertSame($delVehiculo->id, $guiaDe($perez)->id);
        $this->assertSame($principal->id, $guiaDe($rojas)->id); // sin vehículo con guía: el principal
    }

    // ═══════════════════════════════════════════════════════════════
    // Apoyo
    // ═══════════════════════════════════════════════════════════════

    private function servicioDelDetalle(int $salidaId, int $servicioId): array
    {
        $detalle = app(SalidaOperativaController::class)->show((string) $salidaId)->getData(true);

        return collect($detalle['servicios'])->firstWhere('id', $servicioId);
    }
}
