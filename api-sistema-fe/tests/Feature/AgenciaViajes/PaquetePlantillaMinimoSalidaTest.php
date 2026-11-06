<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\PaquetePlantillaController;
use App\Http\Controllers\AgenciaViajes\PaquetePlantillaItemController;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Servicio;
use App\Services\AgenciaViajes\DiagnosticoToursService;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// T-088 — «mínimo para salir» obligatorio en tours con servicios por grupo o guía.
// Mismo patrón de infraestructura que Fila13Fase1aTourCatalogoTest (Postgres real).
class PaquetePlantillaMinimoSalidaTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearTarifa(string $servicioNombre, string $modalidad, array $extra = []): ProveedorTarifa
    {
        $servicio = Servicio::create(['nombre' => $servicioNombre]);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $this->destino->id, 'servicio_id' => $servicio->id]);
        $proveedor = Proveedor::create(['razon_social' => 'Proveedor '.uniqid(), 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

        return ProveedorTarifa::create($extra + [
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica',
            'modalidad' => $modalidad,
            'moneda' => 'PEN',
            'precio_costo' => 100,
            'margen_tipo' => 'porcentaje',
            'margen_valor' => 20,
            'precio_venta_adulto' => 120,
            'vigente_desde' => HoraPeru::hoyTexto(),
            'tip_afe_igv' => '20',
            'destino_tributario' => 'amazonia',
        ]);
    }

    private function crearGuiaTarifa(): GuiaTarifa
    {
        $guia = Guia::create(['nombre' => 'Guía Test', 'documento' => (string) random_int(10000000, 99999999), 'telefono' => '999999999', 'activo' => true]);

        return GuiaTarifa::create([
            'guia_id' => $guia->id, 'destino_id' => $this->destino->id, 'modalidad' => 'dia_local',
            'costo_diario' => 80, 'tipo_margen' => 'porcentaje', 'margen_valor' => 25, 'moneda' => 'PEN',
            'vigente_desde' => HoraPeru::hoyTexto(),
        ]);
    }

    private function crearTour(array $atributos = []): PaquetePlantilla
    {
        return PaquetePlantilla::create($atributos + [
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE, 'nombre' => 'Laguna Azul Full Day',
            'destino_atractivo_id' => $this->destino->id, 'duracion_horas' => 10, 'precio_venta_final' => 100,
        ]);
    }

    private function agregarTarifa(PaquetePlantilla $tour, ProveedorTarifa $tarifa): PaquetePlantillaItem
    {
        return PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $tour->id,
            'proveedor_tarifa_id' => $tarifa->id,
            'destino_servicio_id' => $tarifa->proveedorServicio->destino_servicio_id,
        ]);
    }

    private function payload(PaquetePlantilla $tour, array $extra = []): array
    {
        return $extra + [
            'categoria' => 'local', 'nombre' => $tour->nombre, 'destino_atractivo_id' => $this->destino->id,
            'duracion_horas' => 10, 'precio_venta_final' => 100,
        ];
    }

    private function actualizar(PaquetePlantilla $tour, array $extra = [])
    {
        return app(PaquetePlantillaController::class)->update(new Request($this->payload($tour, $extra)), (string) $tour->id);
    }

    public function test_exige_minimo_con_servicio_privado(): void
    {
        $tour = $this->crearTour();
        $this->agregarTarifa($tour, $this->crearTarifa('Movilidad privada', 'privado'));

        $sin = $this->actualizar($tour);
        $this->assertSame(422, $sin->status());
        $this->assertStringContainsString('Movilidad privada', $sin->getData()->message);
        $this->assertStringContainsString('mínimo', $sin->getData()->message);

        $con = $this->actualizar($tour, ['pax_minimo_salida' => 4]);
        $this->assertSame(200, $con->status());
    }

    public function test_exige_minimo_con_guia(): void
    {
        $tour = $this->crearTour();
        PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'guia_tarifa_id' => $this->crearGuiaTarifa()->id]);

        $sin = $this->actualizar($tour);
        $this->assertSame(422, $sin->status());
        $this->assertStringContainsString('Guía', $sin->getData()->message);

        $this->assertSame(200, $this->actualizar($tour, ['pax_minimo_salida' => 6])->status());
    }

    public function test_no_exige_minimo_sin_servicios_por_grupo(): void
    {
        // Solo servicios por persona.
        $porPersona = $this->crearTour();
        $this->agregarTarifa($porPersona, $this->crearTarifa('Entrada', 'compartido'));
        $this->assertSame(200, $this->actualizar($porPersona)->status());

        // Solo itinerario, aunque tenga un servicio privado.
        $itinerario = $this->crearTour(['uso' => PaquetePlantilla::USO_SOLO_ITINERARIO, 'precio_venta_final' => null]);
        $this->agregarTarifa($itinerario, $this->crearTarifa('Movilidad', 'privado'));
        $this->assertSame(200, $this->actualizar($itinerario, ['uso' => 'solo_itinerario', 'precio_venta_final' => null])->status());

        // Combo: su costo sale de los tours que incluye.
        $combo = $this->crearTour(['tipo' => PaquetePlantilla::TIPO_PAQUETE_COMBO, 'precio_venta_final' => null]);
        $this->assertSame(200, $this->actualizar($combo, ['tipo' => 'paquete_combo', 'precio_venta_final' => null])->status());

        // Desactivar un tour sin mínimo no se bloquea.
        $privado = $this->crearTour();
        $this->agregarTarifa($privado, $this->crearTarifa('Movilidad', 'privado'));
        $this->assertSame(200, $this->actualizar($privado, ['activo' => false])->status());
    }

    public function test_hotel_no_cuenta(): void
    {
        $tour = $this->crearTour();
        $this->agregarTarifa($tour, $this->crearTarifa('Hotel', 'privado', ['tipo_habitacion' => 'doble']));

        $this->assertTrue($tour->serviciosPorGrupo()->isEmpty());
        $this->assertSame(200, $this->actualizar($tour)->status());
    }

    // Decisión de la construcción: desactivar un tour viejo sin mínimo no se bloquea (no se vende).
    public function test_desactivar_no_exige_minimo(): void
    {
        $tour = $this->crearTour();
        $this->agregarTarifa($tour, $this->crearTarifa('Movilidad privada', 'privado'));

        $this->assertSame(200, $this->actualizar($tour, ['activo' => false])->status());
        $this->assertFalse($tour->fresh()->activo);
    }

    // El listado marca falta_minimo con el Incluye precargado y no lo devuelve en la respuesta.
    public function test_listado_marca_falta_minimo_sin_devolver_el_incluye(): void
    {
        $sinMinimo = $this->crearTour(['nombre' => 'Sin mínimo']);
        $this->agregarTarifa($sinMinimo, $this->crearTarifa('Movilidad privada', 'privado'));
        $conMinimo = $this->crearTour(['nombre' => 'Con mínimo', 'pax_minimo_salida' => 4]);
        $this->agregarTarifa($conMinimo, $this->crearTarifa('Movilidad privada', 'privado'));

        $datos = app(PaquetePlantillaController::class)->index(new Request(['per_page' => 100]))->getData(true);
        $filas = collect($datos['paquetes_plantilla'])->keyBy('id');

        $this->assertTrue($filas[$sinMinimo->id]['falta_minimo']);
        $this->assertFalse($filas[$conMinimo->id]['falta_minimo']);
        $this->assertArrayNotHasKey('items', $filas[$sinMinimo->id]);
    }

    public function test_agregar_servicio_privado_avisa(): void
    {
        $tour = $this->crearTour();
        $tarifa = $this->crearTarifa('Movilidad', 'privado');

        $respuesta = app(PaquetePlantillaItemController::class)->store(new Request(['proveedor_tarifa_id' => $tarifa->id]), (string) $tour->id);

        $this->assertSame(200, $respuesta->status());
        $this->assertTrue($respuesta->getData()->falta_minimo);

        $tour->update(['pax_minimo_salida' => 4]);
        $otra = $this->crearTarifa('Otra movilidad', 'privado');
        $conMinimo = app(PaquetePlantillaItemController::class)->store(new Request(['proveedor_tarifa_id' => $otra->id]), (string) $tour->id);
        $this->assertFalse($conMinimo->getData()->falta_minimo);
    }

    public function test_diagnostico_lista_falta_minimo(): void
    {
        $falta = $this->crearTour(['nombre' => 'Tour sin minimo']);
        $this->agregarTarifa($falta, $this->crearTarifa('Movilidad', 'privado'));
        $completo = $this->crearTour(['nombre' => 'Tour con minimo', 'pax_minimo_salida' => 4]);
        $this->agregarTarifa($completo, $this->crearTarifa('Movilidad', 'privado'));

        $filas = collect(app(DiagnosticoToursService::class)->diagnosticar())->keyBy('id');

        $this->assertTrue($filas[$falta->id]['falta_minimo']);
        $this->assertFalse($filas[$completo->id]['falta_minimo']);
    }
}
