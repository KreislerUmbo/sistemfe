<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoCuota;
use App\Services\Creditos\ActivacionService;
use App\Services\Creditos\CreditoBorradorService;
use App\Services\Creditos\LimitesExcedidos;
use App\Services\Creditos\LimitesService;
use App\Services\Creditos\Motor\Enums\ReglaLimite;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 03-api "Activar": número solo al activar, desembolso en caja, límites y autorización. */
class ActivacionTest extends CreditosTestCase
{
    public function test_el_borrador_no_tiene_numero_ni_cuotas(): void
    {
        $usuario = $this->usuario();
        $borrador = app(CreditoBorradorService::class)->crear($this->datos($this->cliente()->id), $usuario);

        $this->assertSame(CreditoEstado::Borrador, $borrador->estado);
        $this->assertNull($borrador->numero_credito);
        $this->assertSame('1000.00', $borrador->interes_total);
        $this->assertSame(0, CreditoCuota::where('credito_id', $borrador->id)->count());
    }

    public function test_activar_asigna_numero_guarda_cronograma_y_desembolsa_en_caja(): void
    {
        $usuario = $this->usuario();
        $sesion = $this->abrirCaja($usuario);

        $credito = $this->activo($usuario);

        $this->assertSame(CreditoEstado::Activo, $credito->estado);
        $this->assertMatchesRegularExpression('/^CR-\d{8}$/', $credito->numero_credito);
        $cuotas = $credito->cuotasVigentes()->get();
        $this->assertCount(10, $cuotas);
        $this->assertSame('2026-01-31', $cuotas[0]->fecha_vencimiento->format('Y-m-d'));
        $this->assertSame('600.00', $cuotas[0]->monto_total);
        $this->assertSame('-5000.00', $this->saldoCaja($sesion->id));
        $this->assertSame('credito_desembolso', DB::table('cash_movements')->where('id', $credito->cash_movement_id)->value('reference_type'));
    }

    public function test_sin_caja_abierta_no_se_activa_ni_se_consume_numero(): void
    {
        $usuario = $this->usuario();
        $borrador = app(CreditoBorradorService::class)->crear($this->datos($this->cliente()->id), $usuario);

        try {
            DB::transaction(fn () => app(ActivacionService::class)->activar($borrador, $usuario));
            $this->fail('Debía rechazar sin caja abierta.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(CreditoEstado::Borrador, $borrador->fresh()->estado);
        $this->assertNull($borrador->fresh()->numero_credito);
    }

    public function test_limite_de_creditos_bloquea_y_se_supera_con_autorizacion(): void
    {
        $usuario = $this->usuario();
        $this->abrirCaja($usuario);
        $cliente = $this->cliente();
        $this->activo($usuario, $cliente);
        $this->activo($usuario, $cliente);
        $tercero = app(CreditoBorradorService::class)->crear($this->datos($cliente->id), $usuario);

        try {
            app(ActivacionService::class)->activar($tercero, $usuario);
            $this->fail('Debía bloquear por máximo de créditos activos.');
        } catch (LimitesExcedidos $e) {
            $this->assertSame('max_creditos', $e->detalle()[0]['regla']);
        }

        app(LimitesService::class)->autorizar($tercero, ReglaLimite::MaxCreditos, 'Cliente puntual, autorizado por gerencia', $usuario);
        $this->assertSame(CreditoEstado::Activo, app(ActivacionService::class)->activar($tercero, $usuario)->estado);
    }

    public function test_desembolso_debe_ser_hoy(): void
    {
        $usuario = $this->usuario();
        $this->abrirCaja($usuario);
        $borrador = app(CreditoBorradorService::class)->crear($this->datos($this->cliente()->id), $usuario);
        $this->hoy('2026-01-03');

        $this->expectException(HttpException::class);
        app(ActivacionService::class)->activar($borrador, $usuario);
    }

    public function test_numero_no_se_repite(): void
    {
        $usuario = $this->usuario();
        $this->abrirCaja($usuario);

        $a = $this->activo($usuario);
        $b = $this->activo($usuario);

        $this->assertNotSame($a->numero_credito, $b->numero_credito);
        $this->assertSame(2, Credito::whereIn('id', [$a->id, $b->id])->whereNotNull('numero_credito')->count());
    }
}
