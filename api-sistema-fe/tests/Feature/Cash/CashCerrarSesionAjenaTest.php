<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Http\Controllers\Cash\CashSessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * Caja › Dashboard: un supervisor cierra la sesión que otro cajero olvidó abierta (cash_session_id
 * explícito + cash.close_others_session). Sin ese permiso, 403.
 */
final class CashCerrarSesionAjenaTest extends CreditosTestCase
{
    public function test_supervisor_cierra_la_sesion_de_otro_cajero(): void
    {
        $cajero = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($cajero);
        $supervisor = $this->usuario(['cash.view_all', 'cash.close_others_session']);

        $this->cerrar($sesion->id);

        $sesion->refresh();
        $this->assertSame('closed', $sesion->status);
        $this->assertSame($supervisor->id, (int) $sesion->closed_by);
        $this->assertSame($cajero->id, (int) $sesion->opened_by);
    }

    public function test_sin_permiso_no_cierra_la_sesion_de_otro(): void
    {
        $cajero = $this->usuario(['cash.open_session']);
        $sesion = $this->abrirCaja($cajero);
        $this->usuario(['cash.open_session']);

        try {
            $this->cerrar($sesion->id);
            $this->fail('Debió rechazar el cierre de una sesión ajena.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame('open', $sesion->fresh()->status);
    }

    public function test_la_ruta_admite_solo_el_permiso_de_cerrar_ajenas(): void
    {
        $ruta = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => str_ends_with($r->uri(), 'cash/close') && in_array('POST', $r->methods(), true));

        $this->assertNotNull($ruta);
        $this->assertContains('permission:cash.open_session|cash.close_others_session', $ruta->gatherMiddleware());
    }

    private function cerrar(int $sesionId): void
    {
        $request = Request::create('/cash/close', 'POST', ['cash_session_id' => $sesionId, 'counted_cash' => 0]);
        $request->setUserResolver(fn () => Auth::guard('api')->user());
        $this->app->instance('request', $request);

        app(CashSessionController::class)->close($request);
    }
}
