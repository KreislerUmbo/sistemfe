<?php

declare(strict_types=1);

namespace Tests\Unit\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\PoliticaLimites;
use App\Services\Creditos\Motor\Dto\ResultadoLimites;
use App\Services\Creditos\Motor\Dto\ResumenCreditoCliente;
use App\Services\Creditos\Motor\Dto\ResumenGarante;
use App\Services\Creditos\Motor\Dto\SolicitudOtorgamiento;
use App\Services\Creditos\Motor\Enums\ReglaLimite;
use App\Services\Creditos\Motor\EvaluadorLimites;
use PHPUnit\Framework\TestCase;

/** Cliente 10. Política por defecto: 2 créditos, deuda máxima 5,000, moroso > 7 días, 3 garantías. */
class EvaluadorLimitesTest extends TestCase
{
    private const CLIENTE = 10;

    /**
     * @param list<ResumenCreditoCliente> $creditos
     * @param list<ResumenGarante> $garantes
     */
    private function evaluar(
        array $creditos = [],
        int $nuevo = 100_000,
        bool $bloqueado = false,
        array $garantes = [],
        ?int $renovar = null,
        bool $migracion = false,
    ): ResultadoLimites {
        return (new EvaluadorLimites())->evaluar(
            new SolicitudOtorgamiento(self::CLIENTE, $nuevo, $creditos, $bloqueado, $garantes, $renovar, $migracion),
            new PoliticaLimites(2, 500_000, 7, 3),
        );
    }

    public function test_cliente_sin_problemas_no_tiene_infracciones(): void
    {
        $r = $this->evaluar([new ResumenCreditoCliente(1, 100_000, 0)]);

        $this->assertSame([], $r->infracciones);
        $this->assertFalse($r->bloquea());
    }

    public function test_limite_de_creditos_simultaneos_bloquea_y_es_autorizable(): void
    {
        $r = $this->evaluar([new ResumenCreditoCliente(1, 50_000, 0), new ResumenCreditoCliente(2, 50_000, 0)]);

        $infraccion = $r->infraccion(ReglaLimite::MaxCreditos);
        $this->assertNotNull($infraccion);
        $this->assertTrue($infraccion->bloquea);
        $this->assertTrue($r->esAutorizable());
        $this->assertSame(['creditos_activos' => 2, 'maximo' => 2], $infraccion->detalle);
    }

    public function test_deuda_maxima_suma_saldo_actual_mas_el_nuevo(): void
    {
        $excede = $this->evaluar([new ResumenCreditoCliente(1, 300_000, 0)], 250_000);
        $justo = $this->evaluar([new ResumenCreditoCliente(1, 300_000, 0)], 200_000);

        $this->assertSame(550_000, $excede->infraccion(ReglaLimite::DeudaMaxima)?->detalle['deuda_con_nuevo']);
        $this->assertNull($justo->infraccion(ReglaLimite::DeudaMaxima));
    }

    public function test_cliente_moroso_bloquea_y_es_autorizable(): void
    {
        $moroso = $this->evaluar([new ResumenCreditoCliente(1, 50_000, 8)]);
        $alLimite = $this->evaluar([new ResumenCreditoCliente(1, 50_000, 7)]);

        $this->assertTrue($moroso->bloquea());
        $this->assertTrue($moroso->esAutorizable());
        $this->assertNull($alLimite->infraccion(ReglaLimite::Moroso));
    }

    public function test_cliente_bloqueado_manualmente(): void
    {
        $r = $this->evaluar(bloqueado: true);

        $this->assertNotNull($r->infraccion(ReglaLimite::Bloqueado));
        $this->assertTrue($r->esAutorizable());
    }

    public function test_cliente_no_puede_ser_su_propio_garante_ni_con_autorizacion(): void
    {
        $r = $this->evaluar(garantes: [new ResumenGarante(self::CLIENTE, 0, 0)]);

        $this->assertTrue($r->bloquea());
        $this->assertFalse($r->esAutorizable());
    }

    public function test_propio_garante_bloquea_tambien_en_migracion(): void
    {
        $r = $this->evaluar(garantes: [new ResumenGarante(self::CLIENTE, 0, 0)], migracion: true);

        $this->assertTrue($r->infraccion(ReglaLimite::PropioGarante)?->bloquea);
    }

    public function test_garante_moroso_o_saturado_solo_advierte(): void
    {
        $r = $this->evaluar(garantes: [new ResumenGarante(20, 3, 0), new ResumenGarante(21, 0, 3)]);

        $this->assertFalse($r->bloquea());
        $this->assertCount(2, $r->advertencias());
        $this->assertNotNull($r->infraccion(ReglaLimite::GaranteMoroso));
        $this->assertNotNull($r->infraccion(ReglaLimite::GaranteSaturado));
    }

    public function test_renovacion_excluye_el_credito_renovado_de_la_deuda_y_del_conteo(): void
    {
        $creditos = [new ResumenCreditoCliente(1, 300_000, 0), new ResumenCreditoCliente(2, 100_000, 0)];

        $r = $this->evaluar($creditos, 300_000, renovar: 1);

        $this->assertNull($r->infraccion(ReglaLimite::MaxCreditos));
        $this->assertNull($r->infraccion(ReglaLimite::DeudaMaxima));   // 100,000 + 300,000
    }

    public function test_renovar_a_un_moroso_requiere_autorizacion(): void
    {
        $r = $this->evaluar([new ResumenCreditoCliente(1, 30_000, 12)], renovar: 1);

        $this->assertNotNull($r->infraccion(ReglaLimite::Moroso));
        $this->assertTrue($r->esAutorizable());
    }

    public function test_en_migracion_las_reglas_de_politica_solo_advierten(): void
    {
        $r = $this->evaluar(
            [new ResumenCreditoCliente(1, 450_000, 20), new ResumenCreditoCliente(2, 50_000, 0)],
            bloqueado: true,
            migracion: true,
        );

        $this->assertFalse($r->bloquea());
        $this->assertCount(4, $r->advertencias());
    }
}
