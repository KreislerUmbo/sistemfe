<?php

namespace Tests\Feature;

use App\Http\Controllers\Sale\SaleController;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\Sale\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Bug real (24-oct-2026): reserva_ventas es una tabla del vertical Agencia y
// no existe en retail ni en créditos, pero Ventas (eliminar una venta) y las
// Notas de crédito (al aceptarlas SUNAT) la consultaban siempre. Desde el
// 24-ago-2026 eso fallaba en esos tenants con "no existe la relación
// reserva_ventas". Aquí se simula un tenant de retail borrando la tabla dentro
// de la transacción del test (Postgres deshace el DROP al final).
class SistemaBaseSinReservasTest extends TestCase
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

        // FK de users.role_id (el factory de Sale crea un usuario).
        DB::table('roles')->insertOrIgnore(['id' => 1, 'name' => 'rol-base-sin-reservas', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);

        // "Tenant de retail": sin la tabla del vertical Agencia.
        Schema::drop('reserva_ventas');
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_sin_la_tabla_el_tenant_no_tiene_reservas(): void
    {
        $this->assertFalse(ReservaVenta::hayReservasEnEsteTenant());
    }

    public function test_eliminar_una_venta_no_falla_sin_reservas(): void
    {
        $venta = Sale::factory()->create();

        $respuesta = app(SaleController::class)->destroy((string) $venta->id);

        $this->assertSame(200, $respuesta->getData(true)['code'], json_encode($respuesta->getData(true)));
        $this->assertSoftDeleted('sales', ['id' => $venta->id]);
    }

    // NotaElectronicaController libera la reserva solo si
    // hayReservasEnEsteTenant() (ese código corre tras la aceptación de SUNAT
    // y no se puede llamar sin enviar la nota): con la tabla presente, la
    // pregunta dice que sí.
    public function test_con_la_tabla_el_tenant_si_tiene_reservas(): void
    {
        DB::rollBack();
        DB::beginTransaction();

        $this->assertTrue(ReservaVenta::hayReservasEnEsteTenant());
    }
}
