<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use App\Models\TipoCambio\TipoCambioSunat;
use App\Models\User;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-033 — la primera alternativa nace con la cotización (nombre y moneda elegibles), su tipo de
// cambio sale solo (último 'dia' → SUNAT → pedirlo), la moneda se cambia sin servicios, "+ Nueva"
// sin nombre usa el sugerido y los 422 salen en español con el error por campo.
// Postgres real (sistemafe_test_migrations), transacción por test revertida.
class AlternativaAutomaticaTest extends TestCase
{
    use ConAccesoATodasLasReservas;

    private User $usuario;

    private int $clienteId;

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
        // tipo_cambio_sunat vive en 'central' (base compartida de dev): se limpia dentro de su
        // propia transacción y tearDown() devuelve los datos reales intactos.
        DB::connection('central')->beginTransaction();

        $this->usuario = $this->verTodasLasReservas();

        // Cada test arma su propio escenario de tipo de cambio.
        DB::table('tipo_cambio_agencia')->delete();
        TipoCambioSunat::query()->delete();

        $this->clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '70330033', 'full_name' => 'Cliente Test T-033',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        DB::rollBack();
        parent::tearDown();
    }

    private function registrarTipoCambioDia(float $valor): void
    {
        TipoCambioAgencia::create([
            'fecha' => HoraPeru::hoyTexto(), 'origen' => 'dia', 'valor' => $valor, 'registrado_por' => $this->usuario->id,
        ]);
    }

    private function crearCotizacion(array $extra = [])
    {
        return app(CotizacionController::class)->store(new Request([
            'cliente_id' => $this->clienteId,
            'destino' => 'Tarapoto',
            'fecha_viaje_desde' => '2026-12-01',
            'fecha_viaje_hasta' => '2026-12-05',
            'pasajeros' => [['edad' => 30]],
        ] + $extra));
    }

    private function alternativasDe(int $cotizacionId)
    {
        return Alternativa::where('cotizacion_id', $cotizacionId)->orderBy('id')->get();
    }

    // CA1
    public function test_crear_cotizacion_deja_alternativa_a_en_moneda_por_defecto_con_su_destino(): void
    {
        $this->registrarTipoCambioDia(3.75);
        DB::table('configuracion_agencia')->update(['moneda_cotizacion_default' => 'USD']);

        $respuesta = $this->crearCotizacion();

        $this->assertSame(200, $respuesta->getStatusCode());
        $cotizacionId = $respuesta->getData(true)['cotizacion']['id'];
        $alternativas = $this->alternativasDe($cotizacionId);
        $this->assertCount(1, $alternativas);
        $this->assertSame('Alternativa A', $alternativas[0]->nombre);
        $this->assertSame('USD', $alternativas[0]->moneda_cotizacion);
        $this->assertSame('dia', $alternativas[0]->tipo_cambio_origen);
        $this->assertEquals(3.75, (float) $alternativas[0]->tipo_cambio_aplicado);
        $this->assertSame(1, AlternativaDestino::where('alternativa_id', $alternativas[0]->id)->count());
        $this->assertCount(1, $respuesta->getData(true)['cotizacion']['alternativas']);
    }

    // CA2
    public function test_nombre_y_moneda_escritos_se_respetan(): void
    {
        $this->registrarTipoCambioDia(3.75);

        $respuesta = $this->crearCotizacion(['alternativa_nombre' => 'opción económica', 'moneda_cotizacion' => 'USD']);

        $alternativa = $this->alternativasDe($respuesta->getData(true)['cotizacion']['id'])->first();
        $this->assertSame('Opción Económica', $alternativa->nombre);
        $this->assertSame('USD', $alternativa->moneda_cotizacion);
    }

    // CA8 — orden del tipo de cambio.
    public function test_tipo_cambio_usa_el_ultimo_dia_registrado(): void
    {
        $this->registrarTipoCambioDia(3.70);
        TipoCambioSunat::create(['fecha' => HoraPeru::hoyTexto(), 'compra' => 3.6, 'venta' => 3.9, 'fuente' => 'test', 'consultado_en' => now()]);

        $respuesta = $this->crearCotizacion();

        $alternativa = $this->alternativasDe($respuesta->getData(true)['cotizacion']['id'])->first();
        $this->assertEquals(3.70, (float) $alternativa->tipo_cambio_aplicado);
        $this->assertSame(1, TipoCambioAgencia::count());
    }

    public function test_sin_tipo_cambio_registrado_usa_venta_sunat_y_lo_registra(): void
    {
        TipoCambioSunat::create(['fecha' => HoraPeru::hoyTexto(), 'compra' => 3.6, 'venta' => 3.81, 'fuente' => 'test', 'consultado_en' => now()]);

        $respuesta = $this->crearCotizacion();

        $this->assertSame(200, $respuesta->getStatusCode());
        $alternativa = $this->alternativasDe($respuesta->getData(true)['cotizacion']['id'])->first();
        $this->assertEquals(3.81, (float) $alternativa->tipo_cambio_aplicado);
        $registrado = TipoCambioAgencia::sole();
        $this->assertSame('dia', $registrado->origen);
        $this->assertEquals(3.81, (float) $registrado->valor);
        $this->assertSame($this->usuario->id, $registrado->registrado_por);
    }

    public function test_sin_tipo_cambio_ni_sunat_no_crea_nada_y_reenviando_con_valor_crea_las_dos(): void
    {
        $cotizacionesAntes = Cotizacion::count();

        $respuesta = $this->crearCotizacion();

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertTrue($respuesta->getData(true)['requiere_tipo_cambio']);
        $this->assertSame($cotizacionesAntes, Cotizacion::count());

        $respuesta = $this->crearCotizacion(['tipo_cambio_valor' => 3.77]);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame($cotizacionesAntes + 1, Cotizacion::count());
        $alternativa = $this->alternativasDe($respuesta->getData(true)['cotizacion']['id'])->first();
        $this->assertEquals(3.77, (float) $alternativa->tipo_cambio_aplicado);
    }

    public function test_valor_fuera_de_rango_pide_confirmacion_sin_crear_nada(): void
    {
        $cotizacionesAntes = Cotizacion::count();

        $respuesta = $this->crearCotizacion(['tipo_cambio_valor' => 37.7]);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertTrue($respuesta->getData(true)['requiere_confirmacion']);
        $this->assertSame($cotizacionesAntes, Cotizacion::count());

        $respuesta = $this->crearCotizacion(['tipo_cambio_valor' => 37.7, 'tipo_cambio_confirmado' => true]);
        $this->assertSame(200, $respuesta->getStatusCode());
    }

    // CA3
    public function test_cambiar_moneda_sin_servicios_ok_y_con_servicios_422(): void
    {
        $this->registrarTipoCambioDia(3.75);
        $cotizacionId = $this->crearCotizacion()->getData(true)['cotizacion']['id'];
        $alternativa = $this->alternativasDe($cotizacionId)->first();

        $respuesta = app(AlternativaController::class)->update(new Request(['moneda_cotizacion' => 'USD']), (string) $alternativa->id);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('USD', $alternativa->fresh()->moneda_cotizacion);

        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => 'Traslado',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD',
            'costo_snapshot' => 10, 'precio_venta_snapshot' => 20, 'precio_convertido' => 20,
        ]);

        $respuesta = app(AlternativaController::class)->update(new Request(['moneda_cotizacion' => 'PEN']), (string) $alternativa->id);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('crea otra alternativa en PEN', $respuesta->getData(true)['message']);
        $this->assertSame('USD', $alternativa->fresh()->moneda_cotizacion);

        // La misma moneda no es un cambio: no bloquea renombrar con servicios.
        $respuesta = app(AlternativaController::class)->update(new Request(['moneda_cotizacion' => 'USD', 'nombre' => 'Plan B']), (string) $alternativa->id);
        $this->assertSame(200, $respuesta->getStatusCode());
    }

    // CA5
    public function test_nueva_sin_nombre_crea_alternativa_b(): void
    {
        $this->registrarTipoCambioDia(3.75);
        $cotizacionId = $this->crearCotizacion()->getData(true)['cotizacion']['id'];

        $respuesta = app(AlternativaController::class)->store(
            new Request(['nombre' => '', 'moneda_cotizacion' => 'PEN', 'tipo_cambio_origen' => 'dia']),
            (string) $cotizacionId
        );

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('Alternativa B', $respuesta->getData(true)['alternativa']['nombre']);
    }

    // CA6 (backend) — mensaje en español y error por campo.
    public function test_errores_de_validacion_en_espanol_con_error_por_campo(): void
    {
        $respuesta = app(CotizacionController::class)->store(new Request(['pasajeros' => [['edad' => 30]]]));

        $this->assertSame(422, $respuesta->getStatusCode());
        $datos = $respuesta->getData(true);
        $this->assertSame('El campo cliente es obligatorio.', $datos['message']);
        $this->assertSame(['El campo destino es obligatorio.'], $datos['errors']['destino']);

        $this->registrarTipoCambioDia(3.75);
        $cotizacionId = $this->crearCotizacion()->getData(true)['cotizacion']['id'];
        $respuesta = app(AlternativaController::class)->store(
            new Request(['nombre' => 'X', 'moneda_cotizacion' => 'EUR', 'tipo_cambio_origen' => 'dia']),
            (string) $cotizacionId
        );
        $this->assertSame('El valor de moneda no es válido.', $respuesta->getData(true)['message']);
    }
}
