<?php

namespace Tests\Unit;

use App\Services\TextoFormatoService;
use PHPUnit\Framework\TestCase;

class TextoFormatoServiceTest extends TestCase
{
    public function test_capitaliza_texto_todo_en_minuscula(): void
    {
        $this->assertSame('Hotel Rioja', TextoFormatoService::capitalizarNombrePropio('hotel rioja'));
    }

    public function test_capitaliza_texto_todo_en_mayuscula(): void
    {
        $this->assertSame('Hotel Rioja', TextoFormatoService::capitalizarNombrePropio('HOTEL RIOJA'));
    }

    public function test_deja_conectores_en_minuscula_salvo_la_primera_palabra(): void
    {
        $this->assertSame(
            'Traslado de la Selva',
            TextoFormatoService::capitalizarNombrePropio('traslado de la selva')
        );
    }

    public function test_capitaliza_conector_cuando_es_la_primera_palabra(): void
    {
        $this->assertSame('La Casa Grande', TextoFormatoService::capitalizarNombrePropio('la casa grande'));
    }

    public function test_recorta_espacios_al_inicio_y_final(): void
    {
        $this->assertSame('Hotel Rioja', TextoFormatoService::capitalizarNombrePropio('  hotel rioja  '));
    }

    public function test_colapsa_espacios_multiples_internos(): void
    {
        $this->assertSame('Hotel Rioja', TextoFormatoService::capitalizarNombrePropio('hotel    rioja'));
    }

    public function test_null_devuelve_null(): void
    {
        $this->assertNull(TextoFormatoService::capitalizarNombrePropio(null));
    }

    public function test_string_vacio_devuelve_string_vacio(): void
    {
        $this->assertSame('', TextoFormatoService::capitalizarNombrePropio(''));
    }

    public function test_string_solo_espacios_devuelve_vacio(): void
    {
        $this->assertSame('', TextoFormatoService::capitalizarNombrePropio('   '));
    }

    public function test_palabra_unica(): void
    {
        $this->assertSame('Rioja', TextoFormatoService::capitalizarNombrePropio('RIOJA'));
    }
}
