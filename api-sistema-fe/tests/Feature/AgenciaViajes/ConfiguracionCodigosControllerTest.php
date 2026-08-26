<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ConfiguracionCodigosController;
use App\Models\AgenciaViajes\ConfiguracionCodigo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Módulo 12 — plan-modulo-codigos-numeracion.md §6.2/§9. Mismo patrón de
// infraestructura que ReporteOperativoTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida, controllers
// invocados directo (sin HTTP), mismo criterio ya usado en este vertical.
class ConfiguracionCodigosControllerTest extends TestCase
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

    public function test_index_devuelve_las_cinco_filas_semilla(): void
    {
        $respuesta = app(ConfiguracionCodigosController::class)->index();
        $payload = $respuesta->getData(true);

        $this->assertCount(5, $payload['configuracion_codigos']);
        $tipos = array_column($payload['configuracion_codigos'], 'tipo');
        $this->assertEqualsCanonicalizing(
            ['tour', 'paquete', 'cotizacion', 'venta_directa', 'reserva'],
            $tipos
        );
    }

    public function test_update_fuerza_reinicio_nunca_cuando_no_incluye_periodo(): void
    {
        $request = Request::create('/', 'PUT', [
            'prefijo' => 'TDKM',
            'separador' => '-',
            'incluye_periodo' => false,
            'longitud_correlativo' => 4,
            'reinicio_correlativo' => 'mensual', // intento de forzarlo, debe ser ignorado
            'activo' => true,
        ]);

        $respuesta = app(ConfiguracionCodigosController::class)->update($request, 'tour');
        $payload = $respuesta->getData(true);

        $this->assertSame(200, $payload['code']);
        $this->assertSame('nunca', $payload['configuracion_codigo']['reinicio_correlativo']);
    }

    public function test_update_respeta_reinicio_cuando_incluye_periodo(): void
    {
        $request = Request::create('/', 'PUT', [
            'prefijo' => 'CDKM',
            'separador' => '-',
            'incluye_periodo' => true,
            'longitud_correlativo' => 7,
            'reinicio_correlativo' => 'anual',
            'activo' => true,
        ]);

        $respuesta = app(ConfiguracionCodigosController::class)->update($request, 'cotizacion');
        $payload = $respuesta->getData(true);

        $this->assertSame('anual', $payload['configuracion_codigo']['reinicio_correlativo']);
    }

    // §9: reserva (deriva_de='cotizacion') solo edita prefijo/separador — el
    // resto del payload se ignora, no se guarda como si tuviera periodo/
    // correlativo/reinicio propios.
    public function test_update_reserva_solo_toca_prefijo_y_separador(): void
    {
        $antes = ConfiguracionCodigo::where('tipo', 'reserva')->first();

        $request = Request::create('/', 'PUT', [
            'prefijo' => 'RDKM',
            'separador' => '_',
            'incluye_periodo' => true, // debe ser ignorado
            'longitud_correlativo' => 9, // debe ser ignorado (válido en sí mismo, distinto del actual)
            'reinicio_correlativo' => 'mensual', // debe ser ignorado
            'activo' => true,
        ]);

        $respuesta = app(ConfiguracionCodigosController::class)->update($request, 'reserva');
        $payload = $respuesta->getData(true);

        $this->assertSame('RDKM', $payload['configuracion_codigo']['prefijo']);
        $this->assertSame('_', $payload['configuracion_codigo']['separador']);
        $this->assertSame($antes->incluye_periodo, $payload['configuracion_codigo']['incluye_periodo']);
        $this->assertSame($antes->longitud_correlativo, $payload['configuracion_codigo']['longitud_correlativo']);
        $this->assertSame($antes->reinicio_correlativo, $payload['configuracion_codigo']['reinicio_correlativo']);
    }

    public function test_update_tipo_inexistente_devuelve_404(): void
    {
        $request = Request::create('/', 'PUT', [
            'prefijo' => 'X', 'separador' => '-', 'incluye_periodo' => false,
            'longitud_correlativo' => 4, 'reinicio_correlativo' => 'nunca', 'activo' => true,
        ]);

        $respuesta = app(ConfiguracionCodigosController::class)->update($request, 'no_existe');

        $this->assertSame(404, $respuesta->getData(true)['code']);
    }

    public function test_previsualizar_no_persiste_ningun_incremento(): void
    {
        $controller = app(ConfiguracionCodigosController::class);
        $request = Request::create('/', 'GET');

        $primera = $controller->previsualizar($request, 'paquete')->getData(true)['proximo_codigo'];
        $segunda = $controller->previsualizar($request, 'paquete')->getData(true)['proximo_codigo'];

        $this->assertSame($primera, $segunda);
        $this->assertSame('P-0001', $primera);
    }

    public function test_previsualizar_acepta_overrides_por_query_string(): void
    {
        $controller = app(ConfiguracionCodigosController::class);
        $request = Request::create('/', 'GET', ['prefijo' => 'PDKM', 'separador' => '_']);

        $codigo = $controller->previsualizar($request, 'paquete')->getData(true)['proximo_codigo'];

        $this->assertSame('PDKM_0001', $codigo);
        $this->assertSame('P', ConfiguracionCodigo::where('tipo', 'paquete')->value('prefijo'));
    }
}
