<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Http\Controllers\AgenciaViajes\SalidaVehiculoController;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaVehiculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    // Revisión: el texto usa el tour y la hora cada uno si existe.
    public function test_guia_de_un_vehiculo_cuenta_y_sin_hora_nombra_el_tour(): void
    {
        $guia = $this->guia();
        $otra = $this->salida('Alto Mayo', hora: null);
        SalidaVehiculo::create(['salida_operativa_id' => $otra->id, 'descripcion' => 'Van', 'guia_id' => $guia->id]);

        $respuesta = $this->asignarGuia($this->salida('Laguna Azul'), $guia);

        $this->assertSame(['Juan Pérez ya está en la salida de Alto Mayo de ese día.'], $respuesta['avisos']);
    }

    // Revisión: el listado lee las salidas de una fecha una sola vez, no una
    // vez por fila; y aun así marca bien a cada fila.
    public function test_el_listado_lee_cada_fecha_una_sola_vez(): void
    {
        $guia = $this->guia();
        $salidas = collect(['Alto Mayo', 'Laguna Azul', 'Cusco', 'Tarapoto'])
            ->map(fn (string $tour) => $this->salida($tour));
        $salidas->each(fn (SalidaOperativa $s) => $this->asignarGuia($s, $guia));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $datos = app(SalidaOperativaController::class)
            ->index(new Request(['fecha_desde' => '2026-11-10', 'fecha_hasta' => '2026-11-10']))->getData(true);
        $lecturasDelDia = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'from "salidas_operativas"') && str_contains($q['query'], '"estado" in'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(1, $lecturasDelDia);
        $this->assertCount(4, array_filter(array_column($datos['salidas'],'aviso_guia_repetido')));
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

    // Revisión: una salida cancelada o realizada no se marca ni avisa, aunque
    // comparta guía o chofer con una activa del mismo día.
    public function test_salida_cancelada_no_se_marca_ni_avisa(): void
    {
        $guia = $this->guia();
        $activa = $this->salida('Alto Mayo');
        $this->asignarGuia($activa, $guia);
        $this->vehiculoConChofer($activa, '987654321');
        $cancelada = $this->salida('Laguna Azul');
        $this->asignarGuia($cancelada, $guia);
        $cancelada->update(['estado' => SalidaOperativa::ESTADO_CANCELADA]);

        $show = app(SalidaOperativaController::class)->show((string) $cancelada->id)->getData(true);
        $this->assertNull($show['aviso_guia_repetido']);

        $realizada = $this->salida('Cusco', estado: SalidaOperativa::ESTADO_REALIZADA);
        $this->assertSame([], $this->asignarGuia($realizada, $guia)['avisos']);
        $chofer = app(SalidaVehiculoController::class)->store(new Request(['chofer_telefono' => '987654321']), (string) $realizada->id)->getData(true);
        $this->assertSame([], $chofer['avisos']);
    }

    // Revisión: la respuesta trae la marca completa del tablero. Cambiar el guía
    // principal no la borra si el guía de un vehículo sigue repetido.
    public function test_cambiar_el_guia_principal_no_borra_la_marca_del_vehiculo(): void
    {
        $repetido = $this->guia();
        $this->asignarGuia($this->salida('Alto Mayo'), $repetido);
        $salida = $this->salida('Laguna Azul');
        SalidaVehiculo::create(['salida_operativa_id' => $salida->id, 'descripcion' => 'Van', 'guia_id' => $repetido->id]);

        $respuesta = $this->asignarGuia($salida, $this->guia('Otro'));

        $this->assertSame([], $respuesta['avisos']);
        $this->assertSame('Juan Pérez ya está en la salida de Alto Mayo de las 08:00 de ese día.', $respuesta['salida']['aviso_guia_repetido']);
    }

    // Revisión: el mismo guía mandado como texto ("5") no cuenta como cambio.
    public function test_reenviar_el_mismo_guia_como_texto_no_vuelve_a_avisar(): void
    {
        $guia = $this->guia();
        $this->asignarGuia($this->salida('Alto Mayo'), $guia);
        $salida = $this->salida('Laguna Azul');
        $this->asignarGuia($salida, $guia);

        $respuesta = app(SalidaOperativaController::class)->update(new Request(['guia_id' => (string) $guia->id]), (string) $salida->id)->getData(true);

        $this->assertSame([], $respuesta['avisos']);
    }
}
