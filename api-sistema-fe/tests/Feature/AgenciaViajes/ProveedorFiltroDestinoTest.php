<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ProveedorController;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\Servicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 27-ago-2026 — un proveedor no tiene destino propio (puede operar en
// varios, uno por cada proveedor_servicio), así que ProveedorController::
// index() filtra "tiene al menos un servicio en este destino o sus
// descendientes" — mismo patrón (idsConDescendientes + whereHas) que ya
// usa ProveedorTarifaController::biblioteca() para el mismo problema.
// Mismo patrón de infraestructura que ProveedorTarifaDesactivarTest:
// Postgres real (sistemafe_test_migrations), transacción por test
// revertida.
class ProveedorFiltroDestinoTest extends TestCase
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

    private function crearProveedorEnDestino(string $razonSocial, DestinoAtractivo $destino): Proveedor
    {
        $servicio = Servicio::create(['nombre' => 'Traslado']);
        $destinoServicio = DestinoServicio::create([
            'destino_atractivo_id' => $destino->id,
            'servicio_id' => $servicio->id,
        ]);
        $proveedor = Proveedor::create(['razon_social' => $razonSocial, 'estado' => true]);
        ProveedorServicio::create([
            'proveedor_id' => $proveedor->id,
            'destino_servicio_id' => $destinoServicio->id,
        ]);

        return $proveedor;
    }

    public function test_filtra_por_proveedores_del_destino_exacto(): void
    {
        $ica = DestinoAtractivo::create(['nombre' => 'Ica', 'tipo' => 'zona']);
        $tarapoto = DestinoAtractivo::create(['nombre' => 'Tarapoto', 'tipo' => 'zona']);

        $proveedorIca = $this->crearProveedorEnDestino('Operador Ica SAC', $ica);
        $this->crearProveedorEnDestino('Operador Tarapoto SAC', $tarapoto);

        $respuesta = app(ProveedorController::class)
            ->index(new Request(['destino_atractivo_id' => $ica->id]));
        $ids = collect($respuesta->getData(true)['proveedores'])->pluck('id');

        $this->assertContains($proveedorIca->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_incluye_proveedores_de_destinos_descendientes(): void
    {
        $ica = DestinoAtractivo::create(['nombre' => 'Ica', 'tipo' => 'zona']);
        $huacachina = DestinoAtractivo::create(['nombre' => 'Huacachina', 'tipo' => 'lugar', 'parent_id' => $ica->id]);

        $proveedor = $this->crearProveedorEnDestino('Operador Huacachina SAC', $huacachina);

        $respuesta = app(ProveedorController::class)
            ->index(new Request(['destino_atractivo_id' => $ica->id]));
        $ids = collect($respuesta->getData(true)['proveedores'])->pluck('id');

        $this->assertContains($proveedor->id, $ids, 'Un proveedor en un descendiente (Huacachina) debe aparecer al buscar por el nodo padre (Ica).');
    }

    public function test_un_proveedor_con_varios_servicios_aparece_una_sola_vez(): void
    {
        $ica = DestinoAtractivo::create(['nombre' => 'Ica', 'tipo' => 'zona']);
        $servicio2 = Servicio::create(['nombre' => 'City tour']);
        $destinoServicio2 = DestinoServicio::create(['destino_atractivo_id' => $ica->id, 'servicio_id' => $servicio2->id]);

        $proveedor = $this->crearProveedorEnDestino('Operador Multi-servicio SAC', $ica);
        ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio2->id]);

        $respuesta = app(ProveedorController::class)
            ->index(new Request(['destino_atractivo_id' => $ica->id]));
        $ids = collect($respuesta->getData(true)['proveedores'])->pluck('id');

        $this->assertSame(1, $ids->filter(fn ($id) => $id === $proveedor->id)->count(), 'whereHas no debe duplicar filas.');
    }

    public function test_sin_filtro_devuelve_todos(): void
    {
        $ica = DestinoAtractivo::create(['nombre' => 'Ica', 'tipo' => 'zona']);
        $this->crearProveedorEnDestino('Operador Ica SAC', $ica);
        Proveedor::create(['razon_social' => 'Sin servicios SAC', 'estado' => true]);

        $respuesta = app(ProveedorController::class)
            ->index(new Request());

        $this->assertSame(2, $respuesta->getData(true)['total']);
    }
}
