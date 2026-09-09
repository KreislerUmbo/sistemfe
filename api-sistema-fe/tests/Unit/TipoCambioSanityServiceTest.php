<?php

namespace Tests\Unit;

use App\Services\TipoCambio\TipoCambioSanityService;
use PHPUnit\Framework\TestCase;

class TipoCambioSanityServiceTest extends TestCase
{
    private TipoCambioSanityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TipoCambioSanityService();
    }

    public function test_acepta_un_valor_tipico_de_usd_pen(): void
    {
        $this->assertTrue($this->service->esRazonable(3.75));
    }

    public function test_acepta_el_limite_inferior_del_rango(): void
    {
        $this->assertTrue($this->service->esRazonable(2.0));
    }

    public function test_acepta_el_limite_superior_del_rango(): void
    {
        $this->assertTrue($this->service->esRazonable(6.0));
    }

    public function test_rechaza_justo_debajo_del_limite_inferior(): void
    {
        $this->assertFalse($this->service->esRazonable(1.99));
    }

    public function test_rechaza_justo_encima_del_limite_superior(): void
    {
        $this->assertFalse($this->service->esRazonable(6.01));
    }

    public function test_rechaza_el_valor_real_encontrado_en_produccion(): void
    {
        // tipo_cambio_agencia.valor = 1.0000, registrado 4 veces sin
        // validación — el hallazgo real que motivó este servicio.
        $this->assertFalse($this->service->esRazonable(1.0));
    }
}
