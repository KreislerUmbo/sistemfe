<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\BibliotecaCotizadorController;
use App\Http\Controllers\AgenciaViajes\ProveedorTarifaController;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Servicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 27-ago-2026 — la biblioteca del cotizador (editar.vue, "agregar
// servicio") solo tenía chips de proveedor_tipo + texto libre, sin
// destino/servicio/proveedor como sí tiene paquetes/detalle.vue. Se
// agregan acá, mismos filtros combinables que ProveedorTarifaController::
// biblioteca() ya ofrecía para el otro picker. De paso se cierra un gap
// real encontrado al revisar: este endpoint (el que editar.vue usa de
// verdad) se había quedado sin el filtro `activo` agregado el día
// anterior a ProveedorTarifaController::biblioteca() — una tarifa
// desactivada seguía apareciendo acá. Mismo patrón de infraestructura que
// ProveedorFiltroDestinoTest: Postgres real (sistemafe_test_migrations),
// transacción por test revertida.
class BibliotecaCotizadorFiltrosTest extends TestCase
{
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
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearProveedorTarifa(DestinoAtractivo $destino, Servicio $servicio, Proveedor $proveedor): ProveedorTarifa
    {
        $destinoServicio = DestinoServicio::create([
            'destino_atractivo_id' => $destino->id,
            'servicio_id' => $servicio->id,
        ]);
        $proveedorServicio = ProveedorServicio::create([
            'proveedor_id' => $proveedor->id,
            'destino_servicio_id' => $destinoServicio->id,
        ]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => 80, 'margen_tipo' => 'porcentaje', 'margen_valor' => 25,
            'precio_venta_adulto' => 100, 'vigente_desde' => now()->toDateString(),
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    public function test_filtra_proveedor_tarifas_por_destino_con_descendientes(): void
    {
        $ica = DestinoAtractivo::create(['nombre' => 'Ica', 'tipo' => 'zona']);
        $huacachina = DestinoAtractivo::create(['nombre' => 'Huacachina', 'tipo' => 'lugar', 'parent_id' => $ica->id]);
        $tarapoto = DestinoAtractivo::create(['nombre' => 'Tarapoto', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'City tour']);
        $proveedor = Proveedor::create(['razon_social' => 'Test SAC', 'estado' => true]);

        $tarifaHuacachina = $this->crearProveedorTarifa($huacachina, $servicio, $proveedor);
        $this->crearProveedorTarifa($tarapoto, $servicio, $proveedor);

        $respuesta = app(BibliotecaCotizadorController::class)->index(new Request(['tipo' => 'proveedor', 'destino_atractivo_id' => $ica->id]));
        $ids = collect($respuesta->getData(true)['resultados'])->pluck('id');

        $this->assertContains($tarifaHuacachina->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_filtra_paquetes_por_destino(): void
    {
        $ica = DestinoAtractivo::create(['nombre' => 'Ica', 'tipo' => 'zona']);
        $tarapoto = DestinoAtractivo::create(['nombre' => 'Tarapoto', 'tipo' => 'zona']);

        $tourIca = PaquetePlantilla::create([
            'codigo' => 'T-ICA', 'categoria' => 'nacional', 'nombre' => 'Tour Ica',
            'destino_atractivo_id' => $ica->id, 'duracion_horas' => 4, 'activo' => true,
        ]);
        PaquetePlantilla::create([
            'codigo' => 'T-TAR', 'categoria' => 'nacional', 'nombre' => 'Tour Tarapoto',
            'destino_atractivo_id' => $tarapoto->id, 'duracion_horas' => 4, 'activo' => true,
        ]);

        $respuesta = app(BibliotecaCotizadorController::class)->index(new Request(['tipo' => 'tour', 'destino_atractivo_id' => $ica->id]));
        $ids = collect($respuesta->getData(true)['resultados'])->pluck('id');

        $this->assertContains($tourIca->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_filtro_servicio_excluye_paquetes_del_resultado(): void
    {
        $ica = DestinoAtractivo::create(['nombre' => 'Ica', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'City tour']);
        $proveedor = Proveedor::create(['razon_social' => 'Test SAC', 'estado' => true]);
        $this->crearProveedorTarifa($ica, $servicio, $proveedor);
        PaquetePlantilla::create([
            'codigo' => 'T-ICA', 'categoria' => 'nacional', 'nombre' => 'Tour Ica',
            'destino_atractivo_id' => $ica->id, 'duracion_horas' => 4, 'activo' => true,
        ]);

        $respuesta = app(BibliotecaCotizadorController::class)->index(new Request(['tipo' => 'todos', 'servicio_id' => $servicio->id]));
        $tipos = collect($respuesta->getData(true)['resultados'])->pluck('tipo_resultado');

        $this->assertNotContains('tour', $tipos, 'servicio_id no aplica a tours/paquetes, deben quedar excluidos del resultado.');
        $this->assertContains('proveedor_tarifa', $tipos);
    }

    public function test_filtro_proveedor_id_solo_trae_sus_tarifas(): void
    {
        $ica = DestinoAtractivo::create(['nombre' => 'Ica', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'City tour']);
        $proveedorA = Proveedor::create(['razon_social' => 'Proveedor A SAC', 'estado' => true]);
        $proveedorB = Proveedor::create(['razon_social' => 'Proveedor B SAC', 'estado' => true]);
        $tarifaA = $this->crearProveedorTarifa($ica, $servicio, $proveedorA);
        $this->crearProveedorTarifa($ica, $servicio, $proveedorB);

        $respuesta = app(BibliotecaCotizadorController::class)->index(new Request(['tipo' => 'proveedor', 'proveedor_id' => $proveedorA->id]));
        $ids = collect($respuesta->getData(true)['resultados'])->pluck('id');

        $this->assertSame([$tarifaA->id], $ids->all());
    }

    // Gap real cerrado 27-ago-2026: este endpoint (el que editar.vue usa
    // de verdad para "agregar servicio") se había quedado sin el filtro
    // activo que sí tenía ProveedorTarifaController::biblioteca().
    public function test_excluye_tarifas_desactivadas(): void
    {
        $ica = DestinoAtractivo::create(['nombre' => 'Ica', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'City tour']);
        $proveedor = Proveedor::create(['razon_social' => 'Test SAC', 'estado' => true]);
        $tarifa = $this->crearProveedorTarifa($ica, $servicio, $proveedor);
        app(ProveedorTarifaController::class)->desactivar((string) $tarifa->id);

        $respuesta = app(BibliotecaCotizadorController::class)->index(new Request(['tipo' => 'proveedor']));
        $ids = collect($respuesta->getData(true)['resultados'])->pluck('id');

        $this->assertNotContains($tarifa->id, $ids);
    }
}
