<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\TipoCambioAgenciaController;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Calculadora de conversión de moneda (07-sep-2026) — pedido real del
// usuario: "no sé cuánto tipo de cambio habré guardado" + necesita
// responderle al cliente al vuelo cuánto es en la otra moneda. Hallazgo
// que lo motivó: una alternativa real quedó con tipo_cambio_aplicado=1.0000
// (imposible para USD/PEN) sin que nadie lo notara — ver memoria de
// proyecto project_moneda_cotizador_diagnostico.md. Mismo patrón de
// infraestructura que el resto de la suite: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class TipoCambioAgenciaCalculadoraTest extends TestCase
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

    private function crearUsuario(): User
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Vendedor Test', 'email' => 'vendedor.tc.test.' . random_int(100000, 999999) . '@test.com',
            'password' => 'x', 'role_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($userId);
    }

    public function test_actual_devuelve_el_ultimo_registrado_sin_importar_el_origen(): void
    {
        $usuario = $this->crearUsuario();
        TipoCambioAgencia::create(['fecha' => '2026-09-01', 'origen' => 'agencia', 'valor' => 3.50, 'registrado_por' => $usuario->id]);
        TipoCambioAgencia::create(['fecha' => '2026-09-04', 'origen' => 'dia', 'valor' => 3.36, 'registrado_por' => $usuario->id]);

        $response = app(TipoCambioAgenciaController::class)->actual();

        $this->assertSame(200, $response->getStatusCode());
        $tipoCambio = $response->getData(true)['tipo_cambio_agencia'];
        $this->assertEquals(3.36, (float) $tipoCambio['valor']);
        $this->assertSame('2026-09-04', substr($tipoCambio['fecha'], 0, 10));
    }

    public function test_actual_devuelve_null_si_nunca_se_registro_nada(): void
    {
        $response = app(TipoCambioAgenciaController::class)->actual();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($response->getData(true)['tipo_cambio_agencia']);
    }

    public function test_store_registra_uno_nuevo(): void
    {
        $usuario = $this->crearUsuario();
        $request = new Request(['valor' => 3.42, 'origen' => 'dia']);
        $request->setUserResolver(fn () => $usuario);

        $response = app(TipoCambioAgenciaController::class)->store($request);

        $this->assertSame(200, $response->getStatusCode());
        $tipoCambio = $response->getData(true)['tipo_cambio_agencia'];
        $this->assertEquals(3.42, (float) $tipoCambio['valor']);
        $this->assertSame($usuario->id, $tipoCambio['registrado_por']);
        $this->assertSame(now()->toDateString(), substr($tipoCambio['fecha'], 0, 10));
    }

    public function test_store_rechaza_valor_cero_o_negativo(): void
    {
        $usuario = $this->crearUsuario();
        $request = new Request(['valor' => 0, 'origen' => 'dia']);
        $request->setUserResolver(fn () => $usuario);

        $response = app(TipoCambioAgenciaController::class)->store($request);

        $this->assertSame(422, $response->getStatusCode());
    }
}
