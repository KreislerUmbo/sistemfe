<?php

namespace Tests\Feature;

use App\Console\Commands\InventarioColumnasSinUso;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\AltaItem\Concerns\PreparaAltaItem;
use Tests\TestCase;

// T-083: el inventario cuenta filas y datos sin escribir nada. Postgres real (las tablas son del
// vertical Agencia), con una transacción por test que se deshace.
class InventarioColumnasSinUsoTest extends TestCase
{
    use PreparaAltaItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
    }

    protected function tearDown(): void
    {
        $this->deshacerBase();
        parent::tearDown();
    }

    private function contar(string $tabla, ?string $columna): string
    {
        $metodo = new \ReflectionMethod(InventarioColumnasSinUso::class, 'contar');

        return $metodo->invoke(new InventarioColumnasSinUso(), $tabla, $columna);
    }

    public function test_cuenta_filas_de_tabla_y_datos_de_columna_sin_escribir(): void
    {
        DB::table('reglas_cancelacion')->insert([
            ['dias_min_antes' => 30, 'dias_max_antes' => null, 'porcentaje_reembolso' => 100],
            ['dias_min_antes' => 7, 'dias_max_antes' => 29, 'porcentaje_reembolso' => 0],
        ]);
        $antes = DB::table('reglas_cancelacion')->get()->toJson();

        $this->assertSame('2', $this->contar('reglas_cancelacion', null));
        // Columna numérica: el 0 cuenta como «sin dato» (es lo que deja un default).
        $this->assertSame('1', $this->contar('reglas_cancelacion', 'porcentaje_reembolso'));
        $this->assertSame('1', $this->contar('reglas_cancelacion', 'dias_max_antes'));
        $this->assertSame($antes, DB::table('reglas_cancelacion')->get()->toJson());
    }

    public function test_tabla_o_columna_inexistente_da_guion(): void
    {
        $this->assertSame('—', $this->contar('tabla_que_no_existe', null));
        $this->assertSame('—', $this->contar('reglas_cancelacion', 'columna_que_no_existe'));
    }
}
