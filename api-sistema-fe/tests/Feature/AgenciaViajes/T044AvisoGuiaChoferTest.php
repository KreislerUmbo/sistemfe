<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Http\Controllers\AgenciaViajes\SalidaVehiculoController;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaVehiculo;
use Illuminate\Http\Request;
use Tests\Feature\AgenciaViajes\Concerns\SalidasFixtures;
use Tests\TestCase;

// T-044 (H38) — aviso (nunca bloqueo) cuando un guía o un chofer ya está en
// otra salida en curso del mismo día.
class T044AvisoGuiaChoferTest extends TestCase
{
    use SalidasFixtures;

    private function salida(string $nombreTour, string $fecha = '2026-11-10', ?string $hora = '08:00', string $estado = SalidaOperativa::ESTADO_PROGRAMADA): SalidaOperativa
    {
        $tour = $this->crearTour(['nombre' => $nombreTour]);

        return SalidaOperativa::create([
            'tour_origen_id' => $tour->id, 'fecha' => $fecha, 'hora' => $hora, 'estado' => $estado,
        ]);
    }

    private function guia(string $nombre = 'Juan Pérez'): Guia
    {
        return Guia::create(['nombre' => $nombre, 'documento' => (string) random_int(10000000, 99999999), 'telefono' => '999999999', 'activo' => true]);
    }

    private function asignarGuia(SalidaOperativa $salida, Guia $guia): array
    {
        return app(SalidaOperativaController::class)->update(new Request(['guia_id' => $guia->id]), (string) $salida->id)->getData(true);
    }

    private function vehiculoConChofer(SalidaOperativa $salida, string $telefono): SalidaVehiculo
    {
        return SalidaVehiculo::create([
            'salida_operativa_id' => $salida->id, 'descripcion' => 'Van', 'chofer_nombre' => 'Pedro Chofer', 'chofer_telefono' => $telefono,
        ]);
    }

    // CA1
    public function test_guia_en_dos_salidas_del_mismo_dia_avisa_y_no_bloquea(): void
    {
        $guia = $this->guia();
        $this->asignarGuia($this->salida('Alto Mayo'), $guia);

        $respuesta = $this->asignarGuia($this->salida('Laguna Azul'), $guia);

        $this->assertSame(200, $respuesta['code']);
        $this->assertSame(['Juan Pérez ya está en la salida de Alto Mayo de las 08:00 de ese día.'], $respuesta['avisos']);
    }

    // CA1 — el guía de un vehículo también cuenta; sin hora, texto genérico.
    public function test_guia_de_un_vehiculo_cuenta_y_sin_hora_el_texto_es_generico(): void
    {
        $guia = $this->guia();
        $otra = $this->salida('Alto Mayo', hora: null);
        SalidaVehiculo::create(['salida_operativa_id' => $otra->id, 'descripcion' => 'Van', 'guia_id' => $guia->id]);

        $respuesta = $this->asignarGuia($this->salida('Laguna Azul'), $guia);

        $this->assertSame(['Juan Pérez ya tiene otra salida ese día.'], $respuesta['avisos']);
    }

    public function test_guia_en_un_vehiculo_avisa_al_asignarlo(): void
    {
        $guia = $this->guia();
        $this->asignarGuia($this->salida('Alto Mayo'), $guia);
        $salida = $this->salida('Laguna Azul');

        $respuesta = app(SalidaVehiculoController::class)->store(new Request(['guia_id' => $guia->id]), (string) $salida->id)->getData(true);

        $this->assertSame(200, $respuesta['code']);
        $this->assertCount(1, $respuesta['avisos']);
    }

    // CA2
    public function test_salida_cancelada_o_realizada_no_avisa(): void
    {
        $guia = $this->guia();
        $this->asignarGuia($this->salida('Alto Mayo', estado: SalidaOperativa::ESTADO_CANCELADA), $guia);
        $this->asignarGuia($this->salida('Cusco', estado: SalidaOperativa::ESTADO_REALIZADA), $guia);

        $respuesta = $this->asignarGuia($this->salida('Laguna Azul'), $guia);

        $this->assertSame([], $respuesta['avisos']);
    }

    // CA3
    public function test_chofer_con_el_mismo_telefono_en_otro_formato_avisa(): void
    {
        $this->vehiculoConChofer($this->salida('Alto Mayo'), '+51 987 654 321');
        $salida = $this->salida('Laguna Azul');

        $respuesta = app(SalidaVehiculoController::class)->store(new Request(['chofer_nombre' => 'Pedro Chofer', 'chofer_telefono' => '987654321']), (string) $salida->id)->getData(true);

        $this->assertSame(200, $respuesta['code']);
        $this->assertSame(['Pedro Chofer ya está en la salida de Alto Mayo de las 08:00 de ese día.'], $respuesta['avisos']);
    }

    public function test_chofer_sin_telefono_no_se_compara_y_telefonos_distintos_tampoco(): void
    {
        $this->vehiculoConChofer($this->salida('Alto Mayo'), '987654321');
        $salida = $this->salida('Laguna Azul');
        $controller = app(SalidaVehiculoController::class);

        $sinTelefono = $controller->store(new Request(['chofer_nombre' => 'Pedro Chofer']), (string) $salida->id)->getData(true);
        $distinto = $controller->store(new Request(['chofer_telefono' => '912345678']), (string) $salida->id)->getData(true);

        $this->assertSame([], $sinTelefono['avisos']);
        $this->assertSame([], $distinto['avisos']);
    }

    public function test_actualizar_un_vehiculo_sin_cambiar_chofer_ni_guia_no_vuelve_a_avisar(): void
    {
        $this->vehiculoConChofer($this->salida('Alto Mayo'), '987654321');
        $van = $this->vehiculoConChofer($this->salida('Laguna Azul'), '987654321');

        $respuesta = app(SalidaVehiculoController::class)->update(new Request(['descripcion' => 'Van 14']), (string) $van->id)->getData(true);

        $this->assertSame([], $respuesta['avisos']);
    }

    // CA4
    public function test_otro_dia_no_avisa(): void
    {
        $guia = $this->guia();
        $this->asignarGuia($this->salida('Alto Mayo', '2026-11-11'), $guia);
        $this->vehiculoConChofer($this->salida('Cusco', '2026-11-11'), '987654321');
        $salida = $this->salida('Laguna Azul');

        $respuesta = $this->asignarGuia($salida, $guia);
        $chofer = app(SalidaVehiculoController::class)->store(new Request(['chofer_telefono' => '987654321']), (string) $salida->id)->getData(true);

        $this->assertSame([], $respuesta['avisos']);
        $this->assertSame([], $chofer['avisos']);
    }

    // La misma salida nunca cuenta contra sí misma.
    public function test_reasignar_el_mismo_guia_en_la_misma_salida_no_avisa(): void
    {
        $guia = $this->guia();
        $salida = $this->salida('Alto Mayo');
        $this->asignarGuia($salida, $guia);
        SalidaVehiculo::create(['salida_operativa_id' => $salida->id, 'descripcion' => 'Van', 'guia_id' => $guia->id]);

        $this->assertSame([], $this->asignarGuia($salida, $this->guia('Otro'))['avisos']);
        $this->assertNull(app(SalidaOperativaController::class)->show((string) $salida->id)->getData(true)['aviso_guia_repetido']);
    }

    // CA5 (parte API) — el tablero trae el aviso del guía repetido.
    public function test_el_tablero_marca_al_guia_repetido(): void
    {
        $guia = $this->guia();
        $primera = $this->salida('Alto Mayo');
        $segunda = $this->salida('Laguna Azul');
        $this->asignarGuia($primera, $guia);
        $this->asignarGuia($segunda, $guia);

        $show = app(SalidaOperativaController::class)->show((string) $segunda->id)->getData(true);

        $this->assertSame('Juan Pérez ya está en la salida de Alto Mayo de las 08:00 de ese día.', $show['aviso_guia_repetido']);
    }
}
