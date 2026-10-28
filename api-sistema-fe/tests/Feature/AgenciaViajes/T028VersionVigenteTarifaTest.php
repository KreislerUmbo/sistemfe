<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ProveedorTarifaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Servicio;
use App\Services\HoraPeru;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-028 (H5, H16, H17): editar el precio de una tarifa ya usada crea una
// versión nueva. La vieja debe dejar de ofrecerse y de usarse desde el día
// en que empieza la nueva, y los tours deben pasar a la nueva.
//
// Regla de fechas (decidida al preparar la tarea, 28-oct-2026): el
// formulario de proveedores manda la tarifa entera, con su vigente_desde
// original. Para la versión nueva, una fecha de inicio que no sea futura
// significa "desde hoy"; una fecha futura se respeta (precio programado).
// La vieja se cierra el día anterior al inicio de la nueva.
class T028VersionVigenteTarifaTest extends TestCase
{
    use ConAccesoATodasLasReservas;

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

        $this->verTodasLasReservas();
        $this->destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function hoy(): string
    {
        return HoraPeru::hoyTexto();
    }

    private function dias(int $n): string
    {
        return Carbon::parse($this->hoy())->addDays($n)->toDateString();
    }

    // Tarifa de hotel (matrimonial) de un proveedor propio, vigente desde hace un mes.
    private function tarifa(float $venta = 200, string $desde = null): ProveedorTarifa
    {
        $proveedor = Proveedor::create(['razon_social' => 'Hotel T028 '.uniqid().' SAC', 'estado' => true]);
        $servicio = Servicio::create(['nombre' => 'Alojamiento T028 '.uniqid()]);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $this->destino->id, 'servicio_id' => $servicio->id]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id, 'tipo_tarifa' => 'publica', 'modalidad' => 'compartido',
            'tipo_habitacion' => 'matrimonial', 'moneda' => 'PEN', 'precio_costo' => $venta / 2,
            'margen_tipo' => 'porcentaje', 'margen_valor' => 20, 'precio_venta_adulto' => $venta,
            'vigente_desde' => $desde ?? $this->dias(-30), 'activo' => true,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ])->fresh();
    }

    private function alternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999), 'full_name' => 'Cliente T028',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T028-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa T028', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function agregarAlaCotizacion(Alternativa $alternativa, ProveedorTarifa $tarifa)
    {
        return app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR, 'proveedor_tarifa_id' => $tarifa->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'dia_referencial' => 1,
        ]), (string) $alternativa->id);
    }

    // Lo mismo que hace proveedores/detalle.vue: manda la tarifa entera con el precio cambiado.
    private function editarPrecio(ProveedorTarifa $tarifa, float $venta, array $extra = []): array
    {
        $respuesta = app(ProveedorTarifaController::class)->update(
            new Request(array_merge($tarifa->fresh()->toArray(), ['precio_venta_adulto' => $venta, 'precio_costo' => $venta / 2], $extra)),
            (string) $tarifa->id
        );
        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));

        return $respuesta->getData(true);
    }

    /** ids que ofrece la biblioteca del cotizador para ese proveedor */
    private function enBiblioteca(ProveedorTarifa $tarifa): array
    {
        $proveedorId = $tarifa->proveedorServicio->proveedor_id;
        $datos = app(ProveedorTarifaController::class)->biblioteca(new Request(['proveedor_id' => $proveedorId, 'per_page' => 50]))->getData(true);

        return collect($datos['proveedor_tarifas'])->pluck('id')->all();
    }

    public function test_ca1_la_version_vieja_se_cierra_ayer_y_la_biblioteca_muestra_solo_la_nueva(): void
    {
        $vieja = $this->tarifa(200);
        $this->assertSame(200, $this->agregarAlaCotizacion($this->alternativa(), $vieja)->getStatusCode());

        $nuevaId = $this->editarPrecio($vieja, 260)['proveedor_tarifa']['id'];
        $nueva = ProveedorTarifa::findOrFail($nuevaId);

        $this->assertNotSame($vieja->id, $nueva->id, 'una tarifa usada se versiona');
        $this->assertSame($this->dias(-1), $vieja->fresh()->vigente_hasta->toDateString());
        $this->assertSame($this->hoy(), $nueva->vigente_desde->toDateString(), 'la fecha de inicio vieja que manda el formulario significa "desde hoy"');
        $this->assertSame($vieja->id, $nueva->version_anterior_id);
        $this->assertSame([$nueva->id], $this->enBiblioteca($vieja));
    }

    public function test_precio_programado_a_futuro_la_vieja_sigue_hasta_la_vispera(): void
    {
        $vieja = $this->tarifa(200);
        $this->agregarAlaCotizacion($this->alternativa(), $vieja);

        $nuevaId = $this->editarPrecio($vieja, 260, ['vigente_desde' => $this->dias(10)])['proveedor_tarifa']['id'];

        $this->assertSame($this->dias(9), $vieja->fresh()->vigente_hasta->toDateString());
        $this->assertSame($this->dias(10), ProveedorTarifa::find($nuevaId)->vigente_desde->toDateString());
        $this->assertSame([$vieja->id], $this->enBiblioteca($vieja), 'hoy todavía se ofrece la vieja; la nueva es futura');
    }

    public function test_ca3_no_se_puede_agregar_a_una_cotizacion_una_version_vieja(): void
    {
        $vieja = $this->tarifa(200);
        $this->agregarAlaCotizacion($this->alternativa(), $vieja);
        $this->editarPrecio($vieja, 260);

        $respuesta = $this->agregarAlaCotizacion($this->alternativa(), $vieja->fresh());

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('ya no está vigente', $respuesta->getData(true)['message']);
    }

    public function test_ca3_tampoco_una_futura(): void
    {
        $futura = $this->tarifa(200, $this->dias(5));

        $this->assertSame(422, $this->agregarAlaCotizacion($this->alternativa(), $futura)->getStatusCode());
    }

    public function test_ca4_el_tour_que_usaba_la_vieja_pasa_a_la_nueva(): void
    {
        $vieja = $this->tarifa(200);
        $this->agregarAlaCotizacion($this->alternativa(), $vieja);
        $tour = PaquetePlantilla::create([
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE, 'nombre' => 'Tour T028 '.uniqid(),
            'destino_atractivo_id' => $this->destino->id, 'duracion_horas' => 8,
        ]);
        $itemTour = PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'proveedor_tarifa_id' => $vieja->id, 'orden' => 1]);

        $nuevaId = $this->editarPrecio($vieja, 260)['proveedor_tarifa']['id'];

        $this->assertSame($nuevaId, $itemTour->fresh()->proveedor_tarifa_id);
        $this->assertEquals(260.0, (float) $itemTour->fresh()->proveedorTarifa->precio_venta_adulto);
    }

    public function test_ca6_las_cotizaciones_ya_armadas_no_cambian(): void
    {
        $vieja = $this->tarifa(200);
        $alternativa = $this->alternativa();
        $this->agregarAlaCotizacion($alternativa, $vieja);
        $item = AlternativaItem::where('alternativa_id', $alternativa->id)->firstOrFail();
        $precioAntes = (float) $item->precio_venta_snapshot;

        $this->editarPrecio($vieja, 260);

        $this->assertSame($vieja->id, $item->fresh()->proveedor_tarifa_id, 'el ítem sigue con la tarifa con que se cotizó');
        $this->assertSame($precioAntes, (float) $item->fresh()->precio_venta_snapshot);
    }

    public function test_una_tarifa_sin_uso_se_corrige_en_el_lugar_sin_versionar(): void
    {
        $tarifa = $this->tarifa(200);

        $datos = $this->editarPrecio($tarifa, 210);

        $this->assertSame($tarifa->id, $datos['proveedor_tarifa']['id']);
        $this->assertNull($tarifa->fresh()->vigente_hasta);
    }

    // Cuatro tarifas del mismo servicio: vigente, vieja (cerrada), desactivada y futura.
    private function cuatroTarifas(): array
    {
        $vigente = $this->tarifa(200);
        $copiar = fn (array $cambios) => ProveedorTarifa::create(array_merge(
            collect($vigente->getAttributes())->except(['id', 'created_at', 'updated_at'])->all(),
            ['diferenciador' => null],
            $cambios
        ));
        $vieja = $copiar(['vigente_desde' => $this->dias(-60), 'vigente_hasta' => $this->dias(-31)]);
        $desactivada = $copiar(['activo' => false]);
        $futura = $copiar(['vigente_desde' => $this->dias(5)]);

        return [$vigente, $vieja, $desactivada, $futura];
    }

    public function test_ca2_index_con_solo_vigentes_filtra_y_sin_parametro_devuelve_todo(): void
    {
        [$vigente, $vieja, $desactivada, $futura] = $this->cuatroTarifas();
        $servicioId = (string) $vigente->proveedor_servicio_id;
        $controlador = app(ProveedorTarifaController::class);

        $soloVigentes = collect($controlador->index(new Request(['solo_vigentes' => '1']), $servicioId)->getData(true)['proveedor_tarifas'])->pluck('id')->all();
        $todas = collect($controlador->index(new Request, $servicioId)->getData(true)['proveedor_tarifas'])->pluck('id')->all();

        $this->assertSame([$vigente->id], $soloVigentes);
        $this->assertEqualsCanonicalizing([$vigente->id, $vieja->id, $desactivada->id, $futura->id], $todas);
    }

    public function test_ca2_tarifas_hotel_ofrece_solo_la_vigente(): void
    {
        [$vigente] = $this->cuatroTarifas();
        $tipoId = \App\Models\AgenciaViajes\ProveedorTipo::where('slug', 'alojamiento-hoteles')->value('id');
        if (! $tipoId) {
            $this->markTestSkipped("Catálogo central sin 'alojamiento-hoteles' en este entorno.");
        }
        $proveedor = $vigente->proveedorServicio->proveedor;
        $proveedor->update(['tipo_id' => $tipoId]);

        $datos = app(\App\Http\Controllers\AgenciaViajes\ProveedorController::class)->tarifasHotel((string) $proveedor->id)->getData(true);

        $this->assertSame([$vigente->id], collect($datos['proveedor_tarifas'])->pluck('id')->all());
    }

    public function test_vigente_hasta_anterior_al_inicio_de_la_nueva_version_da_422_y_no_cambia_nada(): void
    {
        $vieja = $this->tarifa(200);
        $this->agregarAlaCotizacion($this->alternativa(), $vieja);

        $respuesta = app(ProveedorTarifaController::class)->update(
            new Request(array_merge($vieja->fresh()->toArray(), ['precio_venta_adulto' => 260, 'vigente_hasta' => $this->dias(-5)])),
            (string) $vieja->id
        );

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('La fecha de fin no puede ser anterior', $respuesta->getData(true)['message']);
        $this->assertNull($vieja->fresh()->vigente_hasta);
        $this->assertSame(1, ProveedorTarifa::where('proveedor_servicio_id', $vieja->proveedor_servicio_id)->count());
    }

    // Revisión Opus: doble clic en "Guardar" o editar una fila "Vencida" del historial.
    public function test_una_version_ya_reemplazada_no_se_vuelve_a_reemplazar(): void
    {
        $vieja = $this->tarifa(200);
        $this->agregarAlaCotizacion($this->alternativa(), $vieja);
        $nuevaId = $this->editarPrecio($vieja, 260)['proveedor_tarifa']['id'];

        $respuesta = app(ProveedorTarifaController::class)->update(
            new Request(array_merge($vieja->fresh()->toArray(), ['precio_venta_adulto' => 300, 'vigente_hasta' => null])),
            (string) $vieja->id
        );

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('ya fue reemplazada', $respuesta->getData(true)['message']);
        $this->assertSame([$nuevaId], $this->enBiblioteca($vieja), 'sigue habiendo una sola vigente');
    }

    public function test_editar_una_tarifa_ya_vencida_no_estira_su_fecha_de_fin(): void
    {
        $vieja = $this->tarifa(200);
        $this->agregarAlaCotizacion($this->alternativa(), $vieja);
        $vieja->update(['vigente_hasta' => $this->dias(-20)]);

        $nuevaId = $this->editarPrecio($vieja, 260, ['vigente_hasta' => null])['proveedor_tarifa']['id'];

        $this->assertSame($this->dias(-20), $vieja->fresh()->vigente_hasta->toDateString());
        $this->assertSame($this->hoy(), ProveedorTarifa::find($nuevaId)->vigente_desde->toDateString());
    }
}
