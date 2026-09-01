<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaDestinoController;
use App\Http\Controllers\AgenciaViajes\OpcionMayoristaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTipo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 12f-2 — brief PEGAR-EN-CLAUDE-CODE-12f2-chips-destino-cotizador.md
// §1/§2 (backend). Los tests de frontend/UI de esta sesión se verifican
// en navegador real (Playwright), no acá. Mismo patrón de infraestructura
// que el resto de la suite: Postgres real (sistemafe_test_migrations),
// transacción por test revertida.
class Sesion12f2BackendTest extends TestCase
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

    private function crearAlternativa(string $estado = 'borrador'): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '11224433', 'full_name' => 'Cliente Test 12f2',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-09' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    // §1 del brief — AlternativaDestinoController::update()/destroy().

    public function test_update_edita_solo_lo_que_llega(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Original', 'orden' => 1]);

        $response = app(AlternativaDestinoController::class)->update(
            new Request(['destino_texto' => 'Editado']), (string) $alternativa->id, (string) $destino->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Editado', $destino->fresh()->destino_texto);
    }

    public function test_update_rechaza_si_alternativa_ya_aceptada(): void
    {
        $alternativa = $this->crearAlternativa('aceptada');
        $destino = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Original', 'orden' => 1]);

        $response = app(AlternativaDestinoController::class)->update(
            new Request(['destino_texto' => 'Editado']), (string) $alternativa->id, (string) $destino->id
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_destroy_elimina_un_destino_no_usado(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 1', 'orden' => 1]);
        $destino2 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 2', 'orden' => 2]);

        $response = app(AlternativaDestinoController::class)->destroy((string) $alternativa->id, (string) $destino2->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(AlternativaDestino::find($destino2->id));
    }

    public function test_destroy_rechaza_si_es_el_unico_destino(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Único', 'orden' => 1]);

        $response = app(AlternativaDestinoController::class)->destroy((string) $alternativa->id, (string) $destino->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotNull(AlternativaDestino::find($destino->id));
    }

    public function test_destroy_rechaza_si_tiene_items_asociados(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino1 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 1', 'orden' => 1]);
        $destino2 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 2', 'orden' => 2]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destino2->id,
            'origen_tipo' => 'manual', 'descripcion_manual' => 'Ítem test', 'modo_precio' => 'tarifa_fija',
            'cantidad' => 1, 'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        $response = app(AlternativaDestinoController::class)->destroy((string) $alternativa->id, (string) $destino2->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotNull(AlternativaDestino::find($destino2->id));
        $this->assertNotNull(AlternativaDestino::find($destino1->id));
    }

    // §2 del brief — OpcionMayoristaController::store() respeta alternativa_destino_id explícito.

    private function crearProveedorMayorista(): Proveedor
    {
        $tipoMayorista = ProveedorTipo::where('slug', ProveedorTipo::SLUG_MAYORISTA)->first();
        if (! $tipoMayorista) {
            $this->markTestSkipped('Catálogo central proveedor_tipos sin el slug agencia-mayorista en este entorno.');
        }

        return Proveedor::create(['razon_social' => 'Mayorista Test 12f2 SAC', 'estado' => true, 'tipo_id' => $tipoMayorista->id]);
    }

    public function test_opcion_mayorista_store_respeta_destino_explicito(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 1', 'orden' => 1]);
        $destino2 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 2', 'orden' => 2]);
        $proveedor = $this->crearProveedorMayorista();

        $response = app(OpcionMayoristaController::class)->store(
            new Request(['proveedor_id' => $proveedor->id, 'moneda' => 'PEN', 'alternativa_destino_id' => $destino2->id]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($destino2->id, $response->getData(true)['opcion_mayorista']['alternativa_destino_id']);
    }

    public function test_opcion_mayorista_store_rechaza_destino_de_otra_alternativa(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 1', 'orden' => 1]);
        $otraAlternativa = $this->crearAlternativa();
        $destinoAjeno = AlternativaDestino::create(['alternativa_id' => $otraAlternativa->id, 'destino_texto' => 'Ajeno', 'orden' => 1]);
        $proveedor = $this->crearProveedorMayorista();

        $response = app(OpcionMayoristaController::class)->store(
            new Request(['proveedor_id' => $proveedor->id, 'moneda' => 'PEN', 'alternativa_destino_id' => $destinoAjeno->id]),
            (string) $alternativa->id
        );

        $this->assertSame(422, $response->getStatusCode());
    }
}
