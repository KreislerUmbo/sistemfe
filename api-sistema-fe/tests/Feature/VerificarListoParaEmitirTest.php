<?php

namespace Tests\Feature;

use App\Http\Controllers\Greenter\GreenterService;
use App\Models\Company;
use App\Models\SunatConfig;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Fase E, Paso 2 (plan-panel-superadmin.md) — GreenterService::verificarListoParaEmitir()
// es el único punto de verdad reusado tanto por enviarSunat() (vía
// validarCompanyPresente()/getSee(), sin cambios de comportamiento desde el Paso 1) como
// por TenantSunatController::testEmission() (endpoint nuevo de este Paso 2). Este test
// cubre la lógica de negocio en sí, contra sistemafe_test_migrations (Postgres real, mismo
// fixture que EnviarSunatValidacionPreCorrelativoTest) — la integración HTTP/tenancy/audit
// log del endpoint se verificó por separado con evidencia real vía tinker contra un tenant
// descartable (ver plan-panel-superadmin.md, Fase E, Paso 2, sección de verificación).
class VerificarListoParaEmitirTest extends TestCase
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

    private function company(): Company
    {
        return Company::create([
            'razon_social' => 'Empresa de Prueba SAC',
            'razon_social_comercial' => 'Empresa de Prueba',
            'n_document' => '20123456789',
        ]);
    }

    // Caso 1: sin Company.
    public function test_sin_company_lanza_422(): void
    {
        try {
            (new GreenterService())->verificarListoParaEmitir(null);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('no tiene datos de Company configurados', $e->getMessage());
        }
    }

    // Caso 2: Company existe, sin SunatConfig activo.
    public function test_sin_sunat_config_activo_lanza_422(): void
    {
        $empresa = $this->company();
        // A propósito: no se crea ningún SunatConfig.

        try {
            (new GreenterService())->verificarListoParaEmitir($empresa);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('no tiene configuración SUNAT activa', $e->getMessage());
        }
    }

    // Caso 3: modo=beta sin certificado propio — éxito, detalle indica "demo".
    public function test_modo_beta_sin_certificado_propio_devuelve_detalle_usando_demo(): void
    {
        $empresa = $this->company();
        SunatConfig::create([
            'company_id' => $empresa->id,
            'ruc' => '20123456789',
            'modo' => 'beta',
            'sol_usuario' => 'usuario-prueba',
            'sol_clave' => 'clave-prueba',
            'certificado_path' => null,
            'activo' => true,
        ]);

        $detalle = (new GreenterService())->verificarListoParaEmitir($empresa);

        $this->assertSame('beta', $detalle['modo']);
        $this->assertFalse($detalle['certificado']['cargado']);
        $this->assertSame('demo', $detalle['certificado']['propio_o_demo']);
        $this->assertNull($detalle['certificado']['valido']);
        $this->assertSame($empresa->id, $detalle['company']['id']);
    }

    // Caso 4 (simulado): modo=produccion sin certificado propio → 422, mismo motivo del
    // gate ya existente en GreenterService::resolveCertificado() (Paso 1, sin cambios).
    public function test_modo_produccion_sin_certificado_lanza_422(): void
    {
        $empresa = $this->company();
        SunatConfig::create([
            'company_id' => $empresa->id,
            'ruc' => '20123456789',
            'modo' => 'produccion',
            'sol_usuario' => 'usuario-prueba',
            'sol_clave' => 'clave-prueba',
            'certificado_path' => null,
            'activo' => true,
        ]);

        try {
            (new GreenterService())->verificarListoParaEmitir($empresa);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('Modo producción exige un certificado propio', $e->getMessage());
        }
    }
}
