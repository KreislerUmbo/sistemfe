<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\OpcionMayoristaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTipo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 12d — brief PEGAR-EN-CLAUDE-CODE-12d-opcion-mayorista-destino.md.
// Mismo patrón de infraestructura que el resto de la suite de
// agencia-viajes: Postgres real (sistemafe_test_migrations), transacción
// por test revertida.
class Sesion12dOpcionMayoristaDestinoTest extends TestCase
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

    private function crearAlternativaConDestino(string $destinoTexto = 'Destino Test 12d'): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '99887766', 'full_name' => 'Cliente Test 12d',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-06' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => $destinoTexto, 'fecha_viaje_desde' => '2026-10-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia', 'total' => 100,
        ]);
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => $destinoTexto, 'orden' => 1]);

        return $alternativa;
    }

    // ProveedorTipo es CentralConnection (catálogo compartido, plan-modulo-
    // proveedores.md §2.6) — se resuelve vía Eloquent, no DB::table() sobre
    // la conexión de tenant de este test (esa tabla no vive ahí).
    private function crearProveedorMayorista(): Proveedor
    {
        $tipoMayorista = ProveedorTipo::where('slug', ProveedorTipo::SLUG_MAYORISTA)->first();
        if (! $tipoMayorista) {
            $this->markTestSkipped('Catálogo central proveedor_tipos sin el slug agencia-mayorista en este entorno.');
        }

        return Proveedor::create(['razon_social' => 'Mayorista Test 12d SAC', 'estado' => true, 'tipo_id' => $tipoMayorista->id]);
    }

    // §5 del brief — store() resuelve alternativa_destino_id.

    public function test_store_resuelve_alternativa_destino_id_desde_la_alternativa(): void
    {
        $alternativa = $this->crearAlternativaConDestino();
        $proveedor = $this->crearProveedorMayorista();
        $destinoId = $alternativa->destinos()->value('id');

        $response = app(OpcionMayoristaController::class)->store(
            new Request(['proveedor_id' => $proveedor->id, 'moneda' => 'PEN']),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $opcionId = $response->getData(true)['opcion_mayorista']['id'];
        $opcion = OpcionMayorista::find($opcionId);

        $this->assertSame($alternativa->id, $opcion->alternativa_id);
        $this->assertSame($destinoId, $opcion->alternativa_destino_id);
    }

    // §6 del brief — duplicar() remapea alternativa_destino_id de la OpcionMayorista clonada.

    public function test_duplicar_remapea_alternativa_destino_id_de_la_opcion_clonada(): void
    {
        $original = $this->crearAlternativaConDestino('Destino Dup 12d');
        $proveedor = $this->crearProveedorMayorista();
        $destinoOriginal = $original->destinos()->first();
        $opcionOriginal = OpcionMayorista::create([
            'alternativa_id' => $original->id, 'alternativa_destino_id' => $destinoOriginal->id,
            'proveedor_id' => $proveedor->id, 'moneda' => 'PEN', 'estado' => 'candidata',
        ]);

        $response = app(AlternativaController::class)->duplicar((string) $original->id);
        $nuevaId = $response->getData(true)['alternativa']['id'];

        $destinoNuevo = AlternativaDestino::where('alternativa_id', $nuevaId)->first();
        $opcionClonada = OpcionMayorista::where('alternativa_id', $nuevaId)->first();

        $this->assertSame($destinoNuevo->id, $opcionClonada->alternativa_destino_id);
        $this->assertNotSame($opcionOriginal->alternativa_destino_id, $opcionClonada->alternativa_destino_id);
    }

    // §7 del brief — índice único parcial sobre la columna nueva.

    public function test_indice_unico_rechaza_dos_opciones_elegidas_para_el_mismo_destino(): void
    {
        $alternativa = $this->crearAlternativaConDestino();
        $proveedor = $this->crearProveedorMayorista();
        $destinoId = $alternativa->destinos()->value('id');

        OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destinoId,
            'proveedor_id' => $proveedor->id, 'moneda' => 'PEN', 'estado' => 'elegida',
        ]);
        $opcion2 = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destinoId,
            'proveedor_id' => $proveedor->id, 'moneda' => 'PEN', 'estado' => 'candidata',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $opcion2->update(['estado' => 'elegida']);
    }

    public function test_elegir_sigue_funcionando_sin_tocar_alternativa_destino_id(): void
    {
        $alternativa = $this->crearAlternativaConDestino();
        $proveedor = $this->crearProveedorMayorista();
        $destinoId = $alternativa->destinos()->value('id');

        $opcion1 = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destinoId,
            'proveedor_id' => $proveedor->id, 'moneda' => 'PEN', 'estado' => 'elegida',
        ]);
        $opcion2 = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destinoId,
            'proveedor_id' => $proveedor->id, 'moneda' => 'PEN', 'estado' => 'candidata',
        ]);

        $response = app(\App\Http\Controllers\AgenciaViajes\OpcionMayoristaController::class)->elegir((string) $opcion2->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('elegida', $opcion2->fresh()->estado);
        $this->assertSame('candidata', $opcion1->fresh()->estado);
        $this->assertSame($destinoId, $opcion2->fresh()->alternativa_destino_id, 'elegir() no debe tocar alternativa_destino_id');
    }
}
