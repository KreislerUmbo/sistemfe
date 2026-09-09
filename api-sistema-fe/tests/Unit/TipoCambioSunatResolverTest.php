<?php

namespace Tests\Unit;

use App\Models\TipoCambio\TipoCambioSunat;
use App\Services\TipoCambio\TipoCambioSunatResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fase 4 del plan de Tipo de Cambio SUNAT. Decisión del usuario
// (09-sep-2026): NUNCA lanza excepción — devuelve null si no hay dato,
// el llamador sigue de largo sin bloquear la emisión.
class TipoCambioSunatResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('central')->beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function resolver(): TipoCambioSunatResolver
    {
        return new TipoCambioSunatResolver();
    }

    public function test_devuelve_la_fila_de_la_fecha_exacta_si_existe(): void
    {
        TipoCambioSunat::create(['fecha' => '2026-09-08', 'compra' => 3.70, 'venta' => 3.705, 'fuente' => 'decolecta', 'consultado_en' => now()]);

        $resultado = $this->resolver()->resolverParaFecha(Carbon::parse('2026-09-08'));

        $this->assertNotNull($resultado);
        $this->assertEquals(3.705, (float) $resultado->venta);
    }

    public function test_devuelve_el_ultimo_valor_habil_si_la_fecha_exacta_no_esta(): void
    {
        // Viernes 2026-09-04 — sin publicación el sábado/domingo siguiente.
        TipoCambioSunat::create(['fecha' => '2026-09-04', 'compra' => 3.69, 'venta' => 3.695, 'fuente' => 'decolecta', 'consultado_en' => now()]);

        $resultado = $this->resolver()->resolverParaFecha(Carbon::parse('2026-09-06')); // domingo

        $this->assertNotNull($resultado);
        $this->assertSame('2026-09-04', $resultado->fecha->toDateString());
    }

    public function test_devuelve_null_si_no_hay_ningun_dato_disponible(): void
    {
        $resultado = $this->resolver()->resolverParaFecha(Carbon::parse('2026-01-01'));

        $this->assertNull($resultado);
    }

    public function test_no_devuelve_una_fecha_futura_a_la_pedida(): void
    {
        TipoCambioSunat::create(['fecha' => '2026-09-10', 'compra' => 3.80, 'venta' => 3.805, 'fuente' => 'decolecta', 'consultado_en' => now()]);

        $resultado = $this->resolver()->resolverParaFecha(Carbon::parse('2026-09-08'));

        $this->assertNull($resultado);
    }
}
