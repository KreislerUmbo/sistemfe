<?php

namespace Tests\Feature;

use App\Http\Controllers\AgenciaViajes\ProveedorController as ProveedorAgenciaController;
use App\Http\Controllers\Cash\CashMovementController;
use App\Http\Controllers\ProveedorController as ProveedorBaseController;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTipo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-133 (CA1, CA3) — un solo catálogo de proveedores: Caja ve todos, Agencia solo turísticos.
class ProveedorFiltroYCajaTest extends TestCase
{
    private Proveedor $turistico;

    private Proveedor $sinTipo;

    private Proveedor $deTipoGeneral;

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

        $hotel = ProveedorTipo::create(['nombre' => 'Hotel T133', 'slug' => 't133-hotel', 'giro' => 'agencia_viajes', 'activo' => true]);
        $insumos = ProveedorTipo::create(['nombre' => 'Insumos T133', 'slug' => 't133-insumos', 'giro' => 'general', 'activo' => true]);

        $this->turistico = Proveedor::create([
            'razon_social' => 'T133 Hotel Turístico SAC', 'numero_documento' => '20111111111', 'tipo_id' => $hotel->id, 'estado' => true,
        ]);
        $this->sinTipo = Proveedor::create([
            'razon_social' => 'T133 Distribuidora de Agua', 'numero_documento' => '20222222222', 'estado' => true,
        ]);
        $this->deTipoGeneral = Proveedor::create([
            'razon_social' => 'T133 Librería', 'tipo_id' => $insumos->id, 'estado' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        DB::rollBack();
        parent::tearDown();
    }

    private function buscarEnCaja(string $q): array
    {
        return app(CashMovementController::class)
            ->buscarContraparte(new Request(['type' => 'proveedor', 'q' => $q]))
            ->getData(true)['results'];
    }

    private function contraparte(int $id): array
    {
        return (new ReflectionMethod(CashMovementController::class, 'resolverContraparte'))->invoke(
            app(CashMovementController::class),
            new Request(['counterparty_type' => 'proveedor', 'counterparty_id' => $id])
        );
    }

    private function idsDe(object $controller, array $params = []): array
    {
        return collect($controller->index(new Request($params + ['search' => 'T133', 'per_page' => 100]))->getData(true)['proveedores'])
            ->pluck('id')->sort()->values()->all();
    }

    public function test_ca1_el_buscador_de_caja_encuentra_turisticos_y_generales(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->turistico->id, $this->sinTipo->id, $this->deTipoGeneral->id],
            array_column($this->buscarEnCaja('T133'), 'id')
        );
        $this->assertSame(
            [['id' => $this->sinTipo->id, 'name' => 'T133 Distribuidora de Agua', 'document' => '20222222222', 'ciudad' => null]],
            $this->buscarEnCaja('20222222')
        );

        $this->sinTipo->update(['estado' => false]);
        $this->assertSame([], $this->buscarEnCaja('20222222'));
    }

    public function test_ca1_el_movimiento_toma_nombre_y_documento_del_proveedor(): void
    {
        $this->assertSame([
            'counterparty_type' => 'proveedor',
            'counterparty_id' => $this->turistico->id,
            'counterparty_name' => 'T133 Hotel Turístico SAC',
            'counterparty_document' => '20111111111',
        ], $this->contraparte($this->turistico->id));

        $this->expectException(HttpException::class);
        $this->contraparte(999999999);
    }

    public function test_ca3_agencia_muestra_solo_turisticos_y_el_catalogo_base_todos(): void
    {
        $todos = [$this->turistico->id, $this->sinTipo->id, $this->deTipoGeneral->id];
        sort($todos);

        $this->assertSame([$this->turistico->id], $this->idsDe(app(ProveedorAgenciaController::class)));
        $this->assertSame($todos, $this->idsDe(app(ProveedorAgenciaController::class), ['alcance' => 'todos']));
        $this->assertSame($todos, $this->idsDe(app(ProveedorBaseController::class)));
    }

    public function test_no_se_elimina_un_proveedor_con_movimientos_de_caja(): void
    {
        // Solo hace falta la fila que apunta al proveedor.
        DB::statement('SET LOCAL session_replication_role = replica');
        DB::table('cash_movements')->insert([
            'cash_session_id' => 1, 'type' => 'manual_expense', 'payment_method_id' => 1, 'direction' => 'out',
            'amount' => 10, 'counterparty_type' => 'proveedor', 'counterparty_id' => $this->sinTipo->id,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET LOCAL session_replication_role = origin');

        $respuesta = app(ProveedorAgenciaController::class)->destroy((string) $this->sinTipo->id);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertNotNull(Proveedor::find($this->sinTipo->id));
    }
}
