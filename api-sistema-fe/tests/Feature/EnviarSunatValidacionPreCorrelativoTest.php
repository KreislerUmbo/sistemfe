<?php

namespace Tests\Feature;

use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Controllers\Sale\FacturacionElectronicaController;
use App\Models\Company;
use App\Models\Sale\Sale;
use App\Models\SunatConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Fase E, Paso 1 (plan-panel-superadmin.md) — fix de los hallazgos 1, 2 y 4
// del Paso 0 de auditoría: Company/SunatConfig ahora se validan ANTES de
// reservarCorrelativo() (antes: se quemaba un correlativo real igual, aunque
// el envío no pudiera completarse en absoluto), y el código HTTP real de esa
// falla llega hasta la respuesta (antes: siempre 200, con el error solo
// embebido en el body).
//
// Corre contra sistemafe_test_migrations (Postgres real, 76 migraciones,
// mismo fixture que ReservarCorrelativoTest/GreenterServiceFormaPagoTest).
// Nunca contra sv_facturacion ni ningún tenant real.
class EnviarSunatValidacionPreCorrelativoTest extends TestCase
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

        // users.role_id tiene default(1) a nivel de Postgres — esta base
        // recién migrada no trae seeds, así que sin esta fila cualquier
        // Sale::factory() (usa User::factory() para user_id) revienta con FK
        // violation antes de llegar a lo que este test quiere probar.
        DB::table('roles')->insert([
            'id' => 1,
            'name' => 'test-role',
            'guard_name' => 'api',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function ventaSinEnviar(): Sale
    {
        return Sale::factory()->create([
            'serie' => 'F001',
            'correlativo' => null,
            'n_operacion' => null,
            'xml' => null,
            'cdr' => null,
            'type_payment' => 1, // contado — evita el guard de crédito
            'retencion_igv' => 0,
            'is_exportacion' => 0,
        ]);
    }

    private function company(): Company
    {
        return Company::create([
            'razon_social' => 'Empresa de Prueba SAC',
            'razon_social_comercial' => 'Empresa de Prueba',
            'n_document' => '20123456789',
        ]);
    }

    // Caso 1 del Paso 0: sin Company, el fallo antes aparecía recién dentro
    // de GreenterService::getInvoice() (Error de PHP al leer
    // $empresa->n_document) DESPUÉS de reservarCorrelativo(). Ahora corta
    // antes, explícito.
    public function test_sin_company_lanza_422_y_no_reserva_correlativo(): void
    {
        // A propósito: NO se crea ninguna Company en esta transacción.
        $venta = $this->ventaSinEnviar();

        $controller = app(FacturacionElectronicaController::class);

        try {
            $controller->enviarSunat(new Request(['sale_id' => $venta->id]));
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            // Este es el código de estado real que Laravel usa para
            // renderizar la respuesta HTTP cuando la excepción escapa sin
            // capturar — llamar al controller directo (como el resto de la
            // suite) no pasa por el kernel HTTP, pero getStatusCode() es
            // exactamente el valor que ese kernel usaría.
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('no tiene datos de Company configurados', $e->getMessage());
        }

        $this->assertNull($venta->fresh()->correlativo);
    }

    // Caso 2 del Paso 0: sin SunatConfig activo. El guard 422 ya existía
    // dentro de getSee() — el fix es de ORDEN (antes corría después de
    // reservarCorrelativo()) y de CÓDIGO HTTP real (antes, aunque getSee()
    // lanzara este mismo HttpException, el catch que lo envolvía lo
    // convertía en un HTTP 200 con el error solo en el body).
    public function test_sin_sunat_config_activo_lanza_422_y_no_reserva_correlativo(): void
    {
        $this->company();
        // A propósito: NO se crea ningún SunatConfig en esta transacción.
        $venta = $this->ventaSinEnviar();

        $controller = app(FacturacionElectronicaController::class);

        try {
            $controller->enviarSunat(new Request(['sale_id' => $venta->id]));
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('no tiene configuración SUNAT activa', $e->getMessage());
        }

        $this->assertNull($venta->fresh()->correlativo);
    }

    // Caso 4 del Paso 0 (simulado, no hay tenant en producción real todavía):
    // modo=produccion sin certificado propio. El guard ya existía dentro de
    // GreenterService::resolveCertificado() — nunca cae al certificado demo
    // en este caso —, pero antes del fix de orden/código HTTP de esta fase
    // corría dentro del catch que lo devolvía como 200. Se confirma acá que,
    // con el fix, se comporta igual que los casos 1 y 2: 422 real, antes de
    // reservar el correlativo (nunca llega a intentar red real contra
    // SUNAT).
    public function test_modo_produccion_sin_certificado_lanza_422_y_no_reserva_correlativo(): void
    {
        $company = $this->company();
        SunatConfig::create([
            'company_id' => $company->id,
            'ruc' => '20123456789',
            'modo' => 'produccion',
            'sol_usuario' => 'usuario-prueba',
            'sol_clave' => 'clave-prueba',
            'certificado_path' => null,
            'activo' => true,
        ]);
        $venta = $this->ventaSinEnviar();

        $controller = app(FacturacionElectronicaController::class);

        try {
            $controller->enviarSunat(new Request(['sale_id' => $venta->id]));
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('Modo producción exige un certificado propio', $e->getMessage());
        }

        $this->assertNull($venta->fresh()->correlativo);
    }

    // Regresión: modo=beta sin certificado propio debe seguir funcionando
    // EXACTAMENTE igual que antes de esta sesión — sigue cayendo al
    // certificado demo compartido, sin lanzar nada. Se ejercita
    // resolveCertificado() directo (reflexión, sin BD ni red: el método solo
    // lee atributos del propio SunatConfig pasado por parámetro y, si hace
    // falta, Storage::disk('private') — que acá ni se toca porque
    // certificado_path es null) para aislar exactamente la función cuyo
    // comportamiento en beta no debía cambiar.
    public function test_modo_beta_sin_certificado_propio_sigue_usando_el_demo_sin_cambios(): void
    {
        $sunatConfig = new SunatConfig([
            'modo' => 'beta',
            'certificado_path' => null,
            'activo' => true,
        ]);

        $method = new \ReflectionMethod(GreenterService::class, 'resolveCertificado');
        $method->setAccessible(true);

        $certificado = $method->invoke(new GreenterService(), $sunatConfig);

        $this->assertSame(
            file_get_contents(base_path('storage/app/public/certificate-demo.pem')),
            $certificado
        );
    }

    // Mismo aislamiento que el test anterior, pero para el gate de
    // producción: confirma que resolveCertificado() en sí (no solo la
    // integración con enviarSunat() de arriba) nunca cae al demo cuando
    // modo=produccion.
    public function test_modo_produccion_sin_certificado_nunca_cae_al_demo_en_resolveCertificado(): void
    {
        $sunatConfig = new SunatConfig([
            'modo' => 'produccion',
            'certificado_path' => null,
            'activo' => true,
        ]);

        $method = new \ReflectionMethod(GreenterService::class, 'resolveCertificado');
        $method->setAccessible(true);

        try {
            $method->invoke(new GreenterService(), $sunatConfig);
            $this->fail('Se esperaba HttpException 422, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('Modo producción exige un certificado propio', $e->getMessage());
        }
    }
}
