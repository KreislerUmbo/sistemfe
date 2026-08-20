<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\Sale\Sale;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

// PEGAR-EN-CLAUDE-CODE-facturacion-externa-tenant.md §3.2, 2026-08-20 —
// override por reserva de "esta reserva se factura afuera de la
// plataforma", independiente de tenants.facturacion_habilitada. Editable
// solo mientras la reserva no tenga ninguna fila en reserva_ventas.
// Mismo patrón de infraestructura que el resto del módulo: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class ReservaFacturacionExternaTest extends TestCase
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

        // users.role_id tiene default(1) a nivel de Postgres — Sale::factory()
        // crea un User::factory() de paso, revienta con FK violation sin esto
        // (mismo fixture que el resto de la suite AgenciaViajes).
        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->tenant = null;
        DB::rollBack();
        parent::tearDown();
    }

    private function setUpTenantFixture(?bool $facturacionHabilitada): void
    {
        $tenant = new Tenant();
        $tenant->id = 'test-tenant-' . uniqid();
        $tenant->facturacion_habilitada = $facturacionHabilitada;

        app(Tenancy::class)->tenant = $tenant;
    }

    /** Reserva activa simple, sin pasajeros/ítems especiales — no hace falta para estos tests. */
    private function crearReserva(): Reserva
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667788', 'full_name' => 'Cliente Test Facturación Externa',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0600-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('cotizacion_pasajeros')->insert([
            'cotizacion_id' => $cotizacionId, 'edad' => 30, 'tipo_pax' => 'adulto',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Ítem 1', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return $reserva;
    }

    public function test_puede_marcar_facturacion_externa_true_con_referencia_y_fecha(): void
    {
        $reserva = $this->crearReserva();

        $response = app(ReservaController::class)->actualizarFacturacionExterna(new Request([
            'facturacion_externa' => true,
            'referencia_externa' => 'FAC-EXT-0001',
            'fecha_facturacion_externa' => '2026-09-05',
        ]), (string) $reserva->id);

        $this->assertSame(200, $response->getStatusCode());
        $reserva->refresh();
        $this->assertTrue($reserva->facturacion_externa);
        $this->assertSame('FAC-EXT-0001', $reserva->referencia_externa);
        $this->assertSame('2026-09-05', $reserva->fecha_facturacion_externa->toDateString());
    }

    public function test_puede_desmarcar_y_limpia_referencia_y_fecha(): void
    {
        $reserva = $this->crearReserva();

        app(ReservaController::class)->actualizarFacturacionExterna(new Request([
            'facturacion_externa' => true,
            'referencia_externa' => 'FAC-EXT-0002',
            'fecha_facturacion_externa' => '2026-09-05',
        ]), (string) $reserva->id);

        $response = app(ReservaController::class)->actualizarFacturacionExterna(new Request([
            'facturacion_externa' => false,
        ]), (string) $reserva->id);

        $this->assertSame(200, $response->getStatusCode());
        $reserva->refresh();
        $this->assertFalse($reserva->facturacion_externa);
        $this->assertNull($reserva->referencia_externa);
        $this->assertNull($reserva->fecha_facturacion_externa);
    }

    public function test_rechaza_con_422_si_reserva_ya_tiene_venta(): void
    {
        $reserva = $this->crearReserva();

        ReservaVenta::create([
            'reserva_id' => $reserva->id,
            'sale_id' => Sale::factory()->create()->id,
            'reserva_item_ids' => [],
            'reserva_pasajero_ids' => [],
        ]);

        $response = app(ReservaController::class)->actualizarFacturacionExterna(new Request([
            'facturacion_externa' => true,
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $reserva->refresh();
        $this->assertFalse((bool) $reserva->facturacion_externa);
    }

    public function test_reversible_mientras_no_haya_venta(): void
    {
        $reserva = $this->crearReserva();

        app(ReservaController::class)->actualizarFacturacionExterna(new Request(['facturacion_externa' => true]), (string) $reserva->id);
        $this->assertTrue($reserva->refresh()->facturacion_externa);

        app(ReservaController::class)->actualizarFacturacionExterna(new Request(['facturacion_externa' => false]), (string) $reserva->id);
        $this->assertFalse($reserva->refresh()->facturacion_externa);

        app(ReservaController::class)->actualizarFacturacionExterna(new Request(['facturacion_externa' => true]), (string) $reserva->id);
        $this->assertTrue($reserva->refresh()->facturacion_externa);
    }

    // Confirma la independencia explícita del brief §3.2: el override por
    // reserva funciona igual sin importar el flag del tenant.
    public function test_funciona_igual_sin_importar_facturacion_habilitada_del_tenant(): void
    {
        foreach ([true, false] as $flagTenant) {
            $this->setUpTenantFixture($flagTenant);
            $reserva = $this->crearReserva();

            $response = app(ReservaController::class)->actualizarFacturacionExterna(new Request([
                'facturacion_externa' => true,
                'referencia_externa' => 'FAC-EXT-INDEP',
            ]), (string) $reserva->id);

            $this->assertSame(200, $response->getStatusCode(), "falló con facturacion_habilitada={$flagTenant}");
            $this->assertTrue($reserva->refresh()->facturacion_externa);
        }
    }
}
