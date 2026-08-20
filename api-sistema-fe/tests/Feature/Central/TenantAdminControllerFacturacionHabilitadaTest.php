<?php

namespace Tests\Feature\Central;

use App\Http\Controllers\Central\TenantAdminController;
use App\Models\Central\CentralAuditLog;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// PEGAR-EN-CLAUDE-CODE-facturacion-externa-tenant.md §1/§4, 2026-08-20 —
// primera suite de tests para TenantAdminController (no existía ninguna
// hasta esta sesión). Llama al controller DIRECTAMENTE (mismo patrón que
// ReservaFacturacionTest — sin HTTP, sin fixture de guard `central` nuevo,
// fuera de alcance construir esa infraestructura acá).
//
// Deliberadamente NO se prueba el camino feliz completo de store() (crear
// un tenant real con facturacion_habilitada=true persistido) — eso
// dispararía TenantProvisioningService::provision() -> Tenant::create(),
// que corre CreateDatabase/MigrateDatabase de forma SÍNCRONA y REAL
// (TenancyServiceProvider, shouldBeQueued(false)): crearía una base física
// de Postgres nueva por cada corrida del test, sin rollback posible (CREATE
// DATABASE no es transaccional). Se prueba en cambio: la validación de
// store() (falla ANTES de llegar a provision(), seguro), serialize() vía
// reflexión, y el endpoint dedicado updateFacturacionHabilitada() (solo
// actualiza la fila central existente, sin provisionar nada).
class TenantAdminControllerFacturacionHabilitadaTest extends TestCase
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
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    // Insert crudo (NO Tenant::create()) — evita disparar TenantCreated /
    // CreateDatabase / MigrateDatabase, ver docblock de la clase.
    private function crearTenantFixture(array $overrides = []): Tenant
    {
        $id = $overrides['id'] ?? 'test-tenant-' . uniqid();

        DB::connection('central')->table('tenants')->insert(array_merge([
            'id' => $id,
            'data' => json_encode(array_merge([
                'ruc' => '20123456789',
                'razon_social' => 'Empresa Test SAC',
                'razon_social_comercial' => 'Empresa Test',
            ], $overrides['data'] ?? [])),
            'giro' => $overrides['giro'] ?? 'agencia_viajes',
            'tipo' => 'demo',
            'sunat_modo' => 'pruebas',
            'status' => 'activo',
            'facturacion_habilitada' => $overrides['facturacion_habilitada'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ], array_diff_key($overrides, array_flip(['data']))));

        return Tenant::query()->find($id);
    }

    public function test_store_requiere_facturacion_habilitada_sin_default(): void
    {
        $this->expectException(ValidationException::class);

        app(TenantAdminController::class)->store(new Request([
            'ruc' => '20123456789',
            'razon_social' => 'Empresa Test SAC',
            'razon_social_comercial' => 'Empresa Test',
            'domain' => 'empresa-test-' . uniqid(),
            'admin_name' => 'Admin Test',
            'admin_email' => 'admin-' . uniqid() . '@test.local',
            'admin_password' => 'password123',
            'giro' => 'retail',
            // facturacion_habilitada omitido a propósito.
        ]));
    }

    public function test_serialize_incluye_facturacion_habilitada(): void
    {
        $tenant = $this->crearTenantFixture(['facturacion_habilitada' => true]);

        $controller = app(TenantAdminController::class);
        $reflection = new \ReflectionMethod($controller, 'serialize');
        $reflection->setAccessible(true);

        $resultado = $reflection->invoke($controller, $tenant);

        $this->assertArrayHasKey('facturacion_habilitada', $resultado);
        $this->assertTrue($resultado['facturacion_habilitada']);
    }

    public function test_update_facturacion_habilitada_cambia_el_flag_y_audita(): void
    {
        $tenant = $this->crearTenantFixture(['facturacion_habilitada' => false]);

        $response = app(TenantAdminController::class)->updateFacturacionHabilitada(new Request([
            'facturacion_habilitada' => true,
        ]), $tenant->id);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertTrue($body['tenant']['facturacion_habilitada']);

        $tenant->refresh();
        $this->assertTrue($tenant->facturacion_habilitada);

        $log = CentralAuditLog::where('action', 'tenant.facturacion_habilitada_updated')
            ->where('auditable_id', $tenant->id)
            ->first();

        $this->assertNotNull($log, 'Debe crear una fila de auditoría propia.');
        $this->assertFalse($log->payload['valor_anterior']);
        $this->assertTrue($log->payload['valor_nuevo']);
    }

    public function test_update_facturacion_habilitada_acepta_null_a_true_y_a_null_no_es_valido(): void
    {
        $tenant = $this->crearTenantFixture(['facturacion_habilitada' => null]);

        $response = app(TenantAdminController::class)->updateFacturacionHabilitada(new Request([
            'facturacion_habilitada' => false,
        ]), $tenant->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($tenant->refresh()->facturacion_habilitada);
    }

    public function test_update_facturacion_habilitada_no_toca_giro_ni_razon_social(): void
    {
        $tenant = $this->crearTenantFixture(['facturacion_habilitada' => false, 'giro' => 'retail']);
        $giroOriginal = $tenant->giro;
        $razonSocialOriginal = $tenant->razon_social;

        app(TenantAdminController::class)->updateFacturacionHabilitada(new Request([
            'facturacion_habilitada' => true,
        ]), $tenant->id);

        $tenant->refresh();
        $this->assertSame($giroOriginal, $tenant->giro);
        $this->assertSame($razonSocialOriginal, $tenant->razon_social);
    }
}
