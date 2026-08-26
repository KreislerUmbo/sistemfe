<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\AgenciaViajes\CodigoSecuencia;
use App\Models\AgenciaViajes\ConfiguracionCodigo;
use App\Models\AgenciaViajes\Cotizacion;
use App\Services\AgenciaViajes\CodigoGeneradorService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Módulo 12 — plan-modulo-codigos-numeracion.md (v3 + revisión 26-ago-2026).
// Mismo patrón de infraestructura que ReporteOperativoTest/
// SerieComprobanteServiceTest: Postgres real (sistemafe_test_migrations),
// transacción por test revertida. El test de lock reusa exactamente la
// técnica de dos conexiones de
// SerieComprobanteServiceTest::test_lock_bloquea_segunda_conexion_sobre_serie_nv()
// — el mecanismo de CodigoGeneradorService es una copia deliberada del de
// SerieComprobanteService::reservarCorrelativo().
class CodigoGeneradorServiceTest extends TestCase
{
    private CodigoGeneradorService $service;

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

        // La migración semilla deja tour/paquete/cotizacion/venta_directa en
        // correlativo 0 y prefijo=letra sola (sin sigla) — cada test arranca
        // desde ese estado limpio dentro de su propia transacción revertida.
        $this->service = app(CodigoGeneradorService::class);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearCotizacion(string $codigoPrefijo, string $codigo): Cotizacion
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '11223344', 'full_name' => 'Cliente Test Códigos',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Cotizacion::create([
            'cliente_id' => $clienteId,
            'codigo_prefijo' => $codigoPrefijo,
            'codigo' => $codigo,
            'destino' => 'Tarapoto',
        ]);
    }

    public function test_genera_primer_codigo_tour_con_padding_default(): void
    {
        $codigo = $this->service->generar('tour');

        $this->assertSame('T-0001', $codigo);
    }

    public function test_correlativo_incrementa_secuencialmente_sin_saltos(): void
    {
        $this->assertSame('T-0001', $this->service->generar('tour'));
        $this->assertSame('T-0002', $this->service->generar('tour'));
        $this->assertSame('T-0003', $this->service->generar('tour'));

        $this->assertSame(3, CodigoSecuencia::where('tipo', 'tour')->value('ultimo_correlativo'));
    }

    public function test_tipos_distintos_no_comparten_correlativo(): void
    {
        $this->assertSame('T-0001', $this->service->generar('tour'));
        $this->assertSame('P-0001', $this->service->generar('paquete'));
        $this->assertSame('T-0002', $this->service->generar('tour'));
    }

    public function test_cotizacion_incluye_periodo_mmaa_y_padding_de_7(): void
    {
        $codigo = $this->service->generar('cotizacion');

        $this->assertSame('C-'.now()->format('my').'-0000001', $codigo);
    }

    public function test_venta_directa_incluye_periodo_igual_que_cotizacion(): void
    {
        $codigo = $this->service->generar('venta_directa');

        $this->assertSame('V-'.now()->format('my').'-0000001', $codigo);
    }

    public function test_previsualizar_no_incrementa_el_correlativo_real(): void
    {
        $primeraVista = $this->service->previsualizar('tour');
        $segundaVista = $this->service->previsualizar('tour');

        $this->assertSame('T-0001', $primeraVista);
        $this->assertSame('T-0001', $segundaVista, 'previsualizar() no debe persistir ningún incremento.');
        $this->assertSame(0, CodigoSecuencia::where('tipo', 'tour')->value('ultimo_correlativo'));

        $this->assertSame('T-0001', $this->service->generar('tour'));
    }

    public function test_previsualizar_acepta_overrides_sin_persistirlos(): void
    {
        $conOverride = $this->service->previsualizar('tour', ['prefijo' => 'TDKM', 'separador' => '_']);
        $sinOverride = $this->service->previsualizar('tour');

        $this->assertSame('TDKM_0001', $conOverride);
        $this->assertSame('T-0001', $sinOverride);
        $this->assertSame('T', ConfiguracionCodigo::where('tipo', 'tour')->value('prefijo'));
    }

    public function test_generar_para_reserva_primera_reserva_sin_sufijo(): void
    {
        $cotizacion = $this->crearCotizacion('CDKM', 'CDKM-0826-0000005');

        $codigo = $this->service->generarParaReserva($cotizacion);

        $this->assertSame('R-0826-0000005', $codigo);
        $this->assertSame(1, $cotizacion->fresh()->reservas_generadas);
    }

    public function test_generar_para_reserva_segunda_y_tercera_reserva_agregan_sufijo(): void
    {
        $cotizacion = $this->crearCotizacion('CDKM', 'CDKM-0826-0000005');

        $this->assertSame('R-0826-0000005', $this->service->generarParaReserva($cotizacion));
        $this->assertSame('R-0826-0000005-2', $this->service->generarParaReserva($cotizacion->fresh()));
        $this->assertSame('R-0826-0000005-3', $this->service->generarParaReserva($cotizacion->fresh()));
    }

    // Formato viejo ({prefijo}-{año}-{correlativo:03d}, sin periodo real) —
    // convive con el formato nuevo durante la transición. El Str::after()
    // no asume cuántos segmentos tiene el código, así que deriva igual.
    public function test_generar_para_reserva_funciona_con_formato_viejo_de_cotizacion(): void
    {
        $cotizacion = $this->crearCotizacion('kur', 'kur-2026-001');

        $codigo = $this->service->generarParaReserva($cotizacion);

        $this->assertSame('R-2026-001', $codigo);
    }

    public function test_generar_lanza_422_si_el_tipo_esta_inactivo(): void
    {
        ConfiguracionCodigo::where('tipo', 'tour')->update(['activo' => false]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->generar('tour');
    }

    // Mismo mecanismo que SerieComprobanteService::reservarCorrelativo() —
    // reusa la técnica de dos conexiones Postgres reales de
    // SerieComprobanteServiceTest::test_lock_bloquea_segunda_conexion_sobre_serie_nv().
    // Un lock de fila entre dos sesiones solo es observable si la fila está
    // commiteada de verdad, así que se compensa con limpieza manual
    // garantizada en finally{} y reabriendo una transacción vacía para que
    // tearDown() no falle.
    public function test_lock_bloquea_segunda_conexion_sobre_codigo_secuencias(): void
    {
        DB::commit();

        $fila = CodigoSecuencia::where('tipo', 'tour')->first();

        $bloqueada = false;
        $mensajeError = null;

        try {
            DB::connection('pgsql')->beginTransaction();
            DB::connection('pgsql')->select(
                'select * from codigo_secuencias where id = ? for update',
                [$fila->id]
            );

            config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
            DB::purge('pgsql_b');
            DB::connection('pgsql_b')->beginTransaction();
            DB::connection('pgsql_b')->statement("SET LOCAL lock_timeout = '300ms'");

            try {
                DB::connection('pgsql_b')->select(
                    'select * from codigo_secuencias where id = ? for update',
                    [$fila->id]
                );
            } catch (\Throwable $e) {
                $bloqueada = true;
                $mensajeError = $e->getMessage();
            }

            DB::connection('pgsql_b')->rollBack();
            DB::connection('pgsql')->rollBack();
        } finally {
            DB::beginTransaction();
        }

        $this->assertTrue(
            $bloqueada,
            'Se esperaba que la segunda conexión no pudiera tomar el lock de codigo_secuencias mientras la primera lo sostiene abierto.'
        );
        $this->assertNotNull($mensajeError);
    }
}
