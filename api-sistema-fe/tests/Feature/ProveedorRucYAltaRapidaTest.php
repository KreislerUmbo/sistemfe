<?php

namespace Tests\Feature;

use App\Http\Controllers\AgenciaViajes\ProveedorController as ProveedorAgenciaController;
use App\Http\Controllers\Cash\CashMovementController;
use App\Http\Controllers\Client\ClientController;
use App\Http\Controllers\ProveedorController as ProveedorBaseController;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\ProveedorTipoConfig;
use App\Models\Proveedor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// T-134 — RUC repetido con aviso, direcciones y ciudad, «sin documento» y no domiciliados.
// Los permisos (CA5) van por HTTP en ProveedorBaseTest.
class ProveedorRucYAltaRapidaTest extends TestCase
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
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        DB::rollBack();
        parent::tearDown();
    }

    private function crear(array $datos): \Illuminate\Http\JsonResponse
    {
        return app(ProveedorBaseController::class)->store(new Request($datos));
    }

    private function listar(array $filtros): array
    {
        return app(ProveedorBaseController::class)->index(new Request($filtros + ['per_page' => 100]))
            ->getData(true)['proveedores'];
    }

    public function test_ca1_un_ruc_repetido_avisa_con_los_existentes_y_con_confirmacion_se_crea(): void
    {
        $primero = ['razon_social' => 'T134 Casa Andina SAC', 'tipo_documento' => 'RUC', 'numero_documento' => '20134000001',
            'ciudad' => 'TARAPOTO', 'direccion' => 'Jr. Lima 100'];
        $this->assertSame(200, $this->crear($primero)->getStatusCode());

        $segundo = ['razon_social' => 'T134 Casa Andina SAC', 'tipo_documento' => 'RUC', 'numero_documento' => '20134000001', 'ciudad' => 'Cusco'];
        $aviso = $this->crear($segundo);

        $this->assertSame(409, $aviso->getStatusCode());
        $existentes = $aviso->getData(true)['existentes'];
        $this->assertCount(1, $existentes);
        $this->assertSame(['T134 Casa Andina SAC', 'Tarapoto', 'Jr. Lima 100'],
            [$existentes[0]['razon_social'], $existentes[0]['ciudad'], $existentes[0]['direccion']]);
        $this->assertSame(1, Proveedor::where('numero_documento', '20134000001')->count());

        $this->assertSame(200, $this->crear($segundo + ['confirmar_duplicado' => true])->getStatusCode());
        $this->assertSame(2, Proveedor::where('numero_documento', '20134000001')->count());

        // El alta de Agencia avisa igual.
        // La base compartida no tiene el catálogo central de amenidades que lee el alta de Agencia.
        if (! Schema::connection('central')->hasTable('amenidades')) {
            Schema::connection('central')->create('amenidades', fn (Blueprint $table) => $table->id());
        }
        $tipo = ProveedorTipo::create(['nombre' => 'Hotel T134', 'slug' => 't134-hotel', 'giro' => 'agencia_viajes', 'activo' => true]);
        ProveedorTipoConfig::create(['proveedor_tipo_id' => $tipo->id, 'habilitado' => true]);
        $deAgencia = $segundo + ['tipo_id' => $tipo->id];
        $controller = app(ProveedorAgenciaController::class);

        $this->assertSame(409, $controller->store(new Request($deAgencia))->getStatusCode());
        $this->assertSame(200, $controller->store(new Request($deAgencia + ['confirmar_duplicado' => '1']))->getStatusCode());
        $this->assertSame(3, Proveedor::where('numero_documento', '20134000001')->count());
    }

    public function test_ca2_la_consulta_ruc_trae_los_datos_y_un_fallo_no_expone_detalles(): void
    {
        config(['services.apisperu.token' => 'token-secreto-t134']);
        Log::spy();
        Http::fake(fn ($peticion) => match (true) {
            str_contains($peticion->url(), '/ruc/20134000002') => Http::response(
                ['razonSocial' => 'T134 SAC', 'direccion' => 'Av. Fiscal 1', 'provincia' => 'SAN MARTIN']),
            str_contains($peticion->url(), '/ruc/123') => Http::response(['success' => false, 'message' => 'RUC no válido'], 422),
            default => throw new ConnectionException('cURL error para https://dniruc.apisperu.com/?token=token-secreto-t134'),
        });
        $consultar = fn (string $ruc) => app(ClientController::class)->searchDocument('ruc', $ruc);

        $ok = $consultar('20134000002')->getData(true);
        $this->assertSame(['T134 SAC', 'Av. Fiscal 1'], [$ok['razonSocial'], $ok['direccion']]);

        $invalido = $consultar('123');
        $this->assertSame(422, $invalido->getStatusCode());
        $this->assertFalse($invalido->getData(true)['success']);

        $caida = $consultar('20999999999');
        $this->assertSame(500, $caida->getStatusCode());
        $this->assertStringNotContainsString('token', $caida->getContent());
        $this->assertStringNotContainsString('cURL', $caida->getContent());
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn ($mensaje, $contexto) => ! str_contains($contexto['error'], 'token-secreto-t134')
        );

        // Un RUC mal escrito tampoco se guarda.
        $this->assertSame(422, $this->crear(['razon_social' => 'X', 'tipo_documento' => 'RUC', 'numero_documento' => '123'])->getStatusCode());
    }

    public function test_ca3_los_buscadores_devuelven_la_ciudad(): void
    {
        $tipo = ProveedorTipo::create(['nombre' => 'Hotel T134c', 'slug' => 't134c-hotel', 'giro' => 'agencia_viajes', 'activo' => true]);
        Proveedor::create(['razon_social' => 'T134 Hotel Ciudad', 'ciudad' => 'Tarapoto', 'estado' => true]);
        DB::table('proveedores')->where('razon_social', 'T134 Hotel Ciudad')->update(['tipo_id' => $tipo->id]);

        $caja = app(CashMovementController::class)
            ->buscarContraparte(new Request(['type' => 'proveedor', 'q' => 'T134 Hotel Ciudad']))->getData(true)['results'];
        $agencia = app(ProveedorAgenciaController::class)
            ->index(new Request(['search' => 'T134 Hotel Ciudad']))->getData(true)['proveedores'];

        $this->assertSame('Tarapoto', $caja[0]['ciudad']);
        $this->assertSame('Tarapoto', $agencia[0]['ciudad']);
        $this->assertSame('Tarapoto', $this->listar(['search' => 'T134 Hotel Ciudad'])[0]['ciudad']);
    }

    public function test_ca4_sin_documento_no_incluye_referenciales_y_marca_lo_que_falta(): void
    {
        Proveedor::create(['razon_social' => 'T134 SD Pendiente', 'telefono' => '999']);
        Proveedor::create(['razon_social' => 'T134 SD Con Documento', 'numero_documento' => '20134000004']);
        Proveedor::create(['razon_social' => 'T134 SD Referencial']);
        DB::table('proveedores')->where('razon_social', 'T134 SD Referencial')->update(['es_referencial' => true]);

        $lista = $this->listar(['search' => 'T134 SD', 'sin_documento' => '1']);

        $this->assertSame(['T134 SD Pendiente'], array_column($lista, 'razon_social'));
        $this->assertSame(['documento', 'dirección'], $lista[0]['datos_faltantes']);

        $referencial = $this->listar(['search' => 'T134 SD Referencial'])[0];
        $this->assertSame([], $referencial['datos_faltantes']);
    }

    public function test_ca6_un_no_domiciliado_se_crea_sin_ruc_y_no_aparece_en_sin_documento(): void
    {
        // Sin país no.
        $this->assertSame(422, $this->crear(['razon_social' => 'T134 ND Hotel', 'es_no_domiciliado' => true])->getStatusCode());

        // En otro país «RUC» no son 11 dígitos de SUNAT: no se valida.
        $res = $this->crear(['razon_social' => 'T134 ND Hotel Quito', 'es_no_domiciliado' => true, 'pais' => 'Ecuador',
            'tipo_documento' => 'RUC', 'numero_documento' => '1790012345001']);
        $this->assertSame(200, $res->getStatusCode());
        $proveedor = $res->getData(true)['proveedor'];
        $this->assertSame([true, 'Ecuador', '1790012345001'],
            [$proveedor['es_no_domiciliado'], $proveedor['pais'], $proveedor['numero_documento']]);

        $this->assertSame([], $this->listar(['search' => 'T134 ND', 'sin_documento' => '1']));

        // El mismo número en otro país es otro documento; en el mismo país avisa.
        $otro = ['razon_social' => 'T134 ND Otro', 'es_no_domiciliado' => true, 'numero_documento' => '1790012345001'];
        $this->assertSame(200, $this->crear($otro + ['pais' => 'Perú vecino'])->getStatusCode());
        $this->assertSame(409, $this->crear($otro + ['pais' => 'ecuador'])->getStatusCode());

        // Al volver a domiciliado se limpia el país y el documento se valida como peruano.
        $controller = app(ProveedorBaseController::class);
        $this->assertSame(422, $controller->update(new Request(['es_no_domiciliado' => false]), (string) $proveedor['id'])->getStatusCode());
        $controller->update(new Request(['es_no_domiciliado' => false, 'numero_documento' => '20134000006']), (string) $proveedor['id']);
        $this->assertSame([false, null], [Proveedor::find($proveedor['id'])->es_no_domiciliado, Proveedor::find($proveedor['id'])->pais]);
    }
}
