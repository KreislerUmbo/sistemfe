<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Servicio;
use App\Services\AgenciaViajes\PlantillasATarifaVigenteService;
use App\Services\HoraPeru;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// T-028 (H17, CA5): el comando pasa los tours que quedaron con una versión
// vieja de tarifa a la vigente equivalente, solo si hay exactamente una.
class T028PlantillasATarifaVigenteTest extends TestCase
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

        $this->destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function dias(int $n): string
    {
        return Carbon::parse(HoraPeru::hoyTexto())->addDays($n)->toDateString();
    }

    private function proveedorServicio(): ProveedorServicio
    {
        $proveedor = Proveedor::create(['razon_social' => 'Operador T028 '.uniqid().' SAC', 'estado' => true]);
        $servicio = Servicio::create(['nombre' => 'Tour T028 '.uniqid()]);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $this->destino->id, 'servicio_id' => $servicio->id]);

        return ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);
    }

    private function tarifa(ProveedorServicio $servicio, array $cambios = []): ProveedorTarifa
    {
        return ProveedorTarifa::create(array_merge([
            'proveedor_servicio_id' => $servicio->id, 'tipo_tarifa' => 'publica', 'modalidad' => 'compartido',
            'moneda' => 'PEN', 'precio_costo' => 50, 'margen_tipo' => 'porcentaje', 'margen_valor' => 20,
            'precio_venta_adulto' => 100, 'vigente_desde' => $this->dias(-30), 'activo' => true,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ], $cambios))->fresh();
    }

    // Tarifa vieja ya cerrada, usada por un ítem de tour.
    private function itemConTarifaVieja(ProveedorServicio $servicio): PaquetePlantillaItem
    {
        $vieja = $this->tarifa($servicio, ['vigente_hasta' => $this->dias(-1)]);
        $tour = PaquetePlantilla::create([
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE, 'nombre' => 'Tour T028 '.uniqid(),
            'destino_atractivo_id' => $this->destino->id, 'duracion_horas' => 8,
        ]);

        return PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'proveedor_tarifa_id' => $vieja->id, 'orden' => 1]);
    }

    /** de la salida del servicio, solo lo que toca a estos ítems (la base de pruebas tiene otros datos) */
    private function suyos(array $filas, array $itemIds): array
    {
        return array_values(array_filter($filas, fn ($f) => in_array($f['item_id'], $itemIds, true)));
    }

    public function test_ca5_reapunta_el_caso_univoco_y_lo_registra(): void
    {
        $servicio = $this->proveedorServicio();
        $item = $this->itemConTarifaVieja($servicio);
        $viejaId = $item->proveedor_tarifa_id;
        $nueva = $this->tarifa($servicio, ['precio_venta_adulto' => 130]);

        $resultado = app(PlantillasATarifaVigenteService::class)->reapuntar(simular: false);

        $cambios = $this->suyos($resultado['cambios'], [$item->id]);
        $this->assertCount(1, $cambios);
        $this->assertSame($nueva->id, $cambios[0]['tarifa_nueva_id']);
        $this->assertSame($nueva->id, $item->fresh()->proveedor_tarifa_id);
        $this->assertSame(1, DB::table('ajustes_plantilla_tarifa')
            ->where('paquete_plantilla_item_id', $item->id)->where('tarifa_anterior_id', $viejaId)->where('tarifa_nueva_id', $nueva->id)->count());
    }

    public function test_ca5_deja_el_ambiguo_y_el_que_no_tiene_equivalente(): void
    {
        $servicioAmbiguo = $this->proveedorServicio();
        $ambiguo = $this->itemConTarifaVieja($servicioAmbiguo);
        $viejaAmbiguo = $ambiguo->proveedor_tarifa_id;
        $this->tarifa($servicioAmbiguo);
        $this->tarifa($servicioAmbiguo, ['precio_venta_adulto' => 120]);

        $servicioSin = $this->proveedorServicio();
        $sin = $this->itemConTarifaVieja($servicioSin);
        $viejaSin = $sin->proveedor_tarifa_id;

        $resultado = app(PlantillasATarifaVigenteService::class)->reapuntar(simular: false);

        $this->assertSame([], $this->suyos($resultado['cambios'], [$ambiguo->id, $sin->id]));
        $motivos = collect($this->suyos($resultado['revisar'], [$ambiguo->id, $sin->id]))->pluck('motivo', 'item_id');
        $this->assertSame('varias tarifas vigentes equivalentes', $motivos[$ambiguo->id]);
        $this->assertSame('sin tarifa vigente equivalente', $motivos[$sin->id]);
        $this->assertSame($viejaAmbiguo, $ambiguo->fresh()->proveedor_tarifa_id);
        $this->assertSame($viejaSin, $sin->fresh()->proveedor_tarifa_id);
    }

    public function test_ca5_simular_no_escribe_y_correrlo_dos_veces_no_cambia_nada(): void
    {
        $servicio = $this->proveedorServicio();
        $item = $this->itemConTarifaVieja($servicio);
        $viejaId = $item->proveedor_tarifa_id;
        $nueva = $this->tarifa($servicio);
        $servicioDelComando = app(PlantillasATarifaVigenteService::class);

        $simulado = $servicioDelComando->reapuntar(simular: true);
        $this->assertCount(1, $this->suyos($simulado['cambios'], [$item->id]));
        $this->assertSame($viejaId, $item->fresh()->proveedor_tarifa_id, '--simular no escribe');
        $this->assertSame(0, DB::table('ajustes_plantilla_tarifa')->where('paquete_plantilla_item_id', $item->id)->count());

        $servicioDelComando->reapuntar(simular: false);
        $segunda = $servicioDelComando->reapuntar(simular: false);

        $this->assertSame([], $this->suyos($segunda['cambios'], [$item->id]), 'correrlo de nuevo no cambia nada');
        $this->assertSame($nueva->id, $item->fresh()->proveedor_tarifa_id);
        $this->assertSame(1, DB::table('ajustes_plantilla_tarifa')->where('paquete_plantilla_item_id', $item->id)->count());
    }
}
