<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\OpcionMayoristaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 12a — brief PEGAR-EN-CLAUDE-CODE-fase0-gaps-mayoristas-multidestino.md,
// §1 y §2. Dos hallazgos de bajo riesgo de la auditoría arquitectónica
// (§3.2/§3.4), sin relación entre sí más que compartir sesión. Mismo patrón
// de infraestructura que AlternativaItemBloqueaMoverSiAceptadaTest: Postgres
// real (sistemafe_test_migrations), transacción por test revertida.
class Sesion12aFase0GapsTest extends TestCase
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

    private function crearAlternativa(string $estado): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '77889900', 'full_name' => 'Cliente Test 12a',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0301', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'estado' => $estado,
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
            'total' => 100,
        ]);
    }

    // §1 — guard de congelamiento faltante en AlternativaController::update().

    public function test_update_rechaza_descuento_global_pct_si_alternativa_ya_aceptada(): void
    {
        $alternativa = $this->crearAlternativa('aceptada');

        $response = app(AlternativaController::class)->update(
            new Request(['descuento_global_pct' => 10]),
            (string) $alternativa->id
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('ya fue aceptada', $response->getData(true)['message']);
        $this->assertNull($alternativa->fresh()->descuento_global_pct, 'no debió tocarse');
    }

    public function test_update_rechaza_descuento_global_monto_si_alternativa_ya_aceptada(): void
    {
        $alternativa = $this->crearAlternativa('aceptada');

        $response = app(AlternativaController::class)->update(
            new Request(['descuento_global_monto' => 15]),
            (string) $alternativa->id
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('ya fue aceptada', $response->getData(true)['message']);
    }

    public function test_update_sigue_permitiendo_descuento_global_pct_si_no_esta_aceptada(): void
    {
        $alternativa = $this->crearAlternativa('borrador');

        $response = app(AlternativaController::class)->update(
            new Request(['descuento_global_pct' => 10]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEquals(10, $alternativa->fresh()->descuento_global_pct);
    }

    public function test_update_sigue_permitiendo_otros_campos_sobre_alternativa_aceptada(): void
    {
        $alternativa = $this->crearAlternativa('aceptada');

        $response = app(AlternativaController::class)->update(
            new Request(['nombre' => 'Nuevo nombre']),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        // capitalizarNombrePropio() titulariza el nombre — mismo comportamiento
        // esperado en cualquier alternativa, no algo que este guard deba tocar.
        $this->assertSame('Nuevo Nombre', $alternativa->fresh()->nombre);
    }

    // §2 — índice único parcial: solo una OpcionMayorista 'elegida' por alternativa.

    public function test_indice_unico_rechaza_dos_opciones_elegidas_para_la_misma_alternativa(): void
    {
        $alternativa = $this->crearAlternativa('borrador');
        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Test SAC', 'estado' => true]);

        $opcion1 = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id,
            'moneda' => 'PEN', 'estado' => 'elegida',
        ]);
        $opcion2 = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id,
            'moneda' => 'PEN', 'estado' => 'candidata',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        // Insert directo sin pasar por elegir() — el índice, no la lógica de
        // aplicación, es lo que debe bloquear esto.
        $opcion2->update(['estado' => 'elegida']);
    }

    public function test_elegir_sigue_funcionando_normal_desmarcando_la_anterior(): void
    {
        $alternativa = $this->crearAlternativa('borrador');
        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Test SAC', 'estado' => true]);

        $opcion1 = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id,
            'moneda' => 'PEN', 'estado' => 'elegida',
        ]);
        $opcion2 = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id,
            'moneda' => 'PEN', 'estado' => 'candidata',
        ]);

        $response = app(OpcionMayoristaController::class)->elegir((string) $opcion2->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('elegida', $opcion2->fresh()->estado);
        $this->assertSame('candidata', $opcion1->fresh()->estado);
    }
}
