<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\GuiaController;
use App\Http\Controllers\AgenciaViajes\GuiaTarifaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// Tarifas de guía: el mismo versionado que T-028 dio a proveedor_tarifas. Editar el precio de una
// tarifa ya usada cierra la versión vieja la víspera (nunca dos vigentes el mismo día), crea la
// nueva con version_anterior_id, pasa los tours a la nueva y solo la versión vigente se ofrece y
// se cotiza. Postgres real (sistemafe_test_migrations), transacción por test revertida.
class GuiaTarifaVersionVigenteTest extends TestCase
{
    use ConAccesoATodasLasReservas;

    private DestinoAtractivo $destino;

    private Guia $guia;

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

        $this->verTodasLasReservas();

        $this->destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo '.uniqid(), 'tipo' => 'zona']);
        $this->guia = Guia::create(['nombre' => 'Guía Versión', 'documento' => (string) random_int(10000000, 99999999), 'telefono' => '999000111']);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function tarifa(float $costo = 80, array $extra = []): GuiaTarifa
    {
        return GuiaTarifa::create($extra + [
            'guia_id' => $this->guia->id, 'destino_id' => $this->destino->id, 'modalidad' => 'dia_local',
            'costo_diario' => $costo, 'tipo_margen' => 'porcentaje', 'margen_valor' => 25, 'moneda' => 'PEN',
            'vigente_desde' => '2026-01-01',
        ]);
    }

    private function alternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente Guía',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-GT-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function cotizar(Alternativa $alternativa, GuiaTarifa $tarifa): array
    {
        return app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'guia', 'guia_tarifa_id' => $tarifa->id,
        ]), (string) $alternativa->id)->getData(true);
    }

    private function editar(GuiaTarifa $tarifa, float $costo, array $extra = []): array
    {
        $respuesta = app(GuiaTarifaController::class)->update(new Request($extra + [
            'destino_id' => $tarifa->destino_id, 'modalidad' => $tarifa->modalidad, 'costo_diario' => $costo,
            // Lo que manda la pantalla de edición: la fecha de inicio vieja de la tarifa.
            'tipo_margen' => 'porcentaje', 'margen_valor' => 25, 'moneda' => 'PEN', 'vigente_desde' => $tarifa->vigente_desde->toDateString(),
        ]), (string) $tarifa->id);

        return $respuesta->getData(true);
    }

    public function test_editar_una_tarifa_usada_cierra_la_vieja_la_vispera_y_crea_la_nueva_desde_hoy(): void
    {
        $vieja = $this->tarifa(80);
        $this->cotizar($this->alternativa(), $vieja);

        $resultado = $this->editar($vieja, 100);

        $hoy = HoraPeru::hoyTexto();
        $nueva = GuiaTarifa::findOrFail($resultado['guia_tarifa']['id']);
        $this->assertSame($vieja->id, $nueva->version_anterior_id, 'el vínculo queda en la base, no solo en el JSON');
        $this->assertSame($hoy, $nueva->vigente_desde->toDateString(), 'el vigente_desde viejo del formulario no cuenta');
        $this->assertSame(Carbon::parse($hoy)->subDay()->toDateString(), $vieja->fresh()->vigente_hasta->toDateString());
        $this->assertEquals(100.0, (float) $nueva->costo_diario);
    }

    public function test_nunca_hay_dos_versiones_vigentes_el_mismo_dia(): void
    {
        $vieja = $this->tarifa(80);
        $this->cotizar($this->alternativa(), $vieja);

        $this->editar($vieja, 100);

        $vigentes = GuiaTarifa::where('guia_id', $this->guia->id)->vigentes()->get();
        $this->assertCount(1, $vigentes);
        $this->assertEquals(100.0, (float) $vigentes->first()->costo_diario);
    }

    public function test_una_version_ya_reemplazada_no_se_reemplaza_de_nuevo(): void
    {
        $vieja = $this->tarifa(80);
        $this->cotizar($this->alternativa(), $vieja);
        $this->editar($vieja, 100);

        $segundo = app(GuiaTarifaController::class)->update(new Request([
            'destino_id' => $vieja->destino_id, 'modalidad' => 'dia_local', 'costo_diario' => 120,
            'tipo_margen' => 'porcentaje', 'margen_valor' => 25, 'moneda' => 'PEN', 'vigente_desde' => '2026-01-01',
        ]), (string) $vieja->id);

        $this->assertSame(422, $segundo->getStatusCode());
        $this->assertSame(2, GuiaTarifa::where('guia_id', $this->guia->id)->count(), 'no se creó una tercera versión');
        $this->assertCount(1, GuiaTarifa::where('guia_id', $this->guia->id)->vigentes()->get());
    }

    public function test_los_tours_pasan_a_la_version_nueva_y_las_cotizaciones_conservan_la_vieja(): void
    {
        $vieja = $this->tarifa(80);
        $alternativa = $this->alternativa();
        $this->cotizar($alternativa, $vieja);
        $tour = PaquetePlantilla::create([
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE, 'nombre' => 'Tour guía '.uniqid(),
            'destino_atractivo_id' => $this->destino->id, 'duracion_horas' => 8,
        ]);
        $itemTour = PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'guia_tarifa_id' => $vieja->id, 'orden' => 1]);

        $resultado = $this->editar($vieja, 100);

        $nuevaId = $resultado['guia_tarifa']['id'];
        $this->assertSame($nuevaId, $itemTour->fresh()->guia_tarifa_id);
        $this->assertStringContainsString('1 tour(s)', $resultado['message']);
        $this->assertSame($vieja->id, $alternativa->items()->first()->guia_tarifa_id, 'lo ya cotizado no cambia');
    }

    public function test_el_selector_del_cotizador_solo_ofrece_la_version_vigente(): void
    {
        $vieja = $this->tarifa(80);
        $this->cotizar($this->alternativa(), $vieja);
        $nuevaId = $this->editar($vieja, 100)['guia_tarifa']['id'];

        $ids = collect(app(GuiaController::class)->show((string) $this->guia->id)->getData(true)['guia']['guia_tarifas'])->pluck('id')->all();

        $this->assertSame([$nuevaId], $ids);
    }

    public function test_una_version_vieja_no_se_cotiza(): void
    {
        $vieja = $this->tarifa(80);
        $this->cotizar($this->alternativa(), $vieja);
        $this->editar($vieja, 100);

        $respuesta = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'guia', 'guia_tarifa_id' => $vieja->id,
        ]), (string) $this->alternativa()->id);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('ya no está vigente', $respuesta->getData(true)['message']);
    }

    public function test_un_precio_programado_no_se_cotiza_ni_se_ofrece_hasta_su_fecha(): void
    {
        $vieja = $this->tarifa(80);
        $this->cotizar($this->alternativa(), $vieja);
        $futura = Carbon::parse(HoraPeru::hoyTexto())->addDays(10)->toDateString();

        $nuevaId = $this->editar($vieja, 100, ['vigente_desde' => $futura])['guia_tarifa']['id'];

        $this->assertSame($futura, GuiaTarifa::find($nuevaId)->vigente_desde->toDateString());
        $this->assertTrue($vieja->fresh()->estaVigente(), 'la vieja sigue hasta la víspera de la nueva');
        $this->assertSame(
            Carbon::parse($futura)->subDay()->toDateString(),
            $vieja->fresh()->vigente_hasta->toDateString()
        );
        $ids = collect(app(GuiaController::class)->show((string) $this->guia->id)->getData(true)['guia']['guia_tarifas'])->pluck('id')->all();
        $this->assertSame([$vieja->id], $ids, 'la programada todavía no se ofrece');

        $respuesta = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'guia', 'guia_tarifa_id' => $nuevaId,
        ]), (string) $this->alternativa()->id);
        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('todavía no está vigente', $respuesta->getData(true)['message']);
    }

    public function test_la_fecha_de_fin_no_puede_ser_anterior_al_inicio_de_la_nueva_version(): void
    {
        $vieja = $this->tarifa(80);
        $this->cotizar($this->alternativa(), $vieja);

        $respuesta = app(GuiaTarifaController::class)->update(new Request([
            'destino_id' => $vieja->destino_id, 'modalidad' => 'dia_local', 'costo_diario' => 100,
            'tipo_margen' => 'porcentaje', 'margen_valor' => 25, 'moneda' => 'PEN',
            'vigente_desde' => '2026-01-01', 'vigente_hasta' => '2026-02-01',
        ]), (string) $vieja->id);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame(1, GuiaTarifa::where('guia_id', $this->guia->id)->count(), 'no se versionó nada');
    }

    public function test_sin_uso_se_edita_directo_sin_versionar(): void
    {
        $tarifa = $this->tarifa(80);

        $resultado = $this->editar($tarifa, 95);

        $this->assertArrayNotHasKey('version_anterior_id', $resultado);
        $this->assertSame(1, GuiaTarifa::where('guia_id', $this->guia->id)->count());
        $this->assertEquals(95.0, (float) $tarifa->fresh()->costo_diario);
    }
}
