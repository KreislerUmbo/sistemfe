<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\TipoCambioAgenciaController;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Validación de rango de sanidad (2.0-6.0 USD/PEN) — prerequisito del
// módulo de Tipo de Cambio SUNAT, cierra el punto 4 (nunca implementado)
// de plan-fix-moneda-cotizador.md. Compartida por los dos caminos de
// escritura de tipo_cambio_agencia: AlternativaController::store() (al
// crear una alternativa con tipo_cambio_valor) y
// TipoCambioAgenciaController::store() (la calculadora de bolsillo).
// Hallazgo real que lo motivó: tipo_cambio_agencia.valor = 1.0000
// registrado 4 veces en agencia-demo sin que nada lo rechazara.
class TipoCambioSanityTest extends TestCase
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
            'name' => 'Vendedor Test', 'email' => 'vendedor.sanity.test.' . random_int(100000, 999999) . '@test.com',
            'password' => 'x', 'role_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($userId);
    }

    private function crearCotizacion(): int
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999),
            'full_name' => 'Cliente Sanity Test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-SANITY-' . random_int(100000, 999999),
            'cliente_id' => $clienteId, 'destino' => 'Tarapoto',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── AlternativaController::store() ──────────────────────────────

    public function test_alternativa_rechaza_tipo_cambio_fuera_de_rango_sin_confirmar(): void
    {
        $usuario = $this->crearUsuario();
        $cotizacionId = $this->crearCotizacion();
        $request = new Request([
            'nombre' => 'Plan poison', 'moneda_cotizacion' => 'PEN', 'tipo_cambio_origen' => 'dia',
            'tipo_cambio_valor' => 1.0,
        ]);
        $request->setUserResolver(fn () => $usuario);

        $respuesta = app(AlternativaController::class)->store($request, (string) $cotizacionId);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertTrue($respuesta->getData(true)['requiere_confirmacion']);
        $this->assertSame(0, TipoCambioAgencia::count());
    }

    public function test_alternativa_acepta_tipo_cambio_fuera_de_rango_con_confirmacion(): void
    {
        $usuario = $this->crearUsuario();
        $cotizacionId = $this->crearCotizacion();
        $request = new Request([
            'nombre' => 'Plan poison confirmado', 'moneda_cotizacion' => 'PEN', 'tipo_cambio_origen' => 'dia',
            'tipo_cambio_valor' => 1.0, 'tipo_cambio_confirmado' => true,
        ]);
        $request->setUserResolver(fn () => $usuario);

        $respuesta = app(AlternativaController::class)->store($request, (string) $cotizacionId);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame(1, TipoCambioAgencia::count());
        $this->assertEquals(1.0, (float) TipoCambioAgencia::first()->valor);
    }

    public function test_alternativa_acepta_tipo_cambio_normal_sin_pedir_confirmacion(): void
    {
        $usuario = $this->crearUsuario();
        $cotizacionId = $this->crearCotizacion();
        $request = new Request([
            'nombre' => 'Plan normal', 'moneda_cotizacion' => 'PEN', 'tipo_cambio_origen' => 'dia',
            'tipo_cambio_valor' => 3.75,
        ]);
        $request->setUserResolver(fn () => $usuario);

        $respuesta = app(AlternativaController::class)->store($request, (string) $cotizacionId);

        $this->assertSame(200, $respuesta->getStatusCode());
    }

    // ── TipoCambioAgenciaController::store() ────────────────────────

    public function test_calculadora_rechaza_valor_fuera_de_rango_sin_confirmar(): void
    {
        $usuario = $this->crearUsuario();
        $request = new Request(['valor' => 8.5, 'origen' => 'dia']);
        $request->setUserResolver(fn () => $usuario);

        $respuesta = app(TipoCambioAgenciaController::class)->store($request);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertTrue($respuesta->getData(true)['requiere_confirmacion']);
        $this->assertSame(0, TipoCambioAgencia::count());
    }

    public function test_calculadora_acepta_valor_fuera_de_rango_con_confirmacion(): void
    {
        $usuario = $this->crearUsuario();
        $request = new Request(['valor' => 8.5, 'origen' => 'dia', 'confirmado' => true]);
        $request->setUserResolver(fn () => $usuario);

        $respuesta = app(TipoCambioAgenciaController::class)->store($request);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame(1, TipoCambioAgencia::count());
    }
}
