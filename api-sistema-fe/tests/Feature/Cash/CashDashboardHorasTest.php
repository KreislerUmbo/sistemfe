<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Http\Controllers\Cash\CashSessionController;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * Caja › Historial (cash/dashboard): las horas de una caja abierta salen enteras. Con Carbon 3,
 * diffInHours() devolvía decimales ("Abierta hace 186.42596101166663 h").
 */
final class CashDashboardHorasTest extends CreditosTestCase
{
    public function test_horas_abiertas_enteras_y_alerta_pasadas_las_24(): void
    {
        $this->hoy('2026-10-15', '10:00:00');
        $usuario = $this->usuario(['cash.view_all']);
        $sesion = $this->abrirCaja($usuario);
        $sesion->update(['opened_at' => now()->subHours(30)->subMinutes(25)]);

        $datos = app(CashSessionController::class)->dashboard()->getData(true);
        $caja = collect($datos['registers'])->firstWhere('session_id', $sesion->id);

        $this->assertSame(30, $caja['elapsed_hours']);
        $this->assertTrue($caja['is_stale']);
    }
}
