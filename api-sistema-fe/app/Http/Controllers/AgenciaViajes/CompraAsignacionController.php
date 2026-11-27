<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\CompraAsignacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\Compras\Compra;
use App\Services\AgenciaViajes\CompraAsignacionService;
use App\Services\Tenancy\GiroActual;
use Illuminate\Http\Request;

/**
 * T-152 — reparto de una compra y bloque «Compras» de las fichas. Las rutas llevan
 * `modulo:compras` y el permiso de Compras; en otro giro responden 404 (la tabla no existe).
 * El permiso de reservas se comprueba aquí, después del giro, para que ese 404 no sea un 403.
 */
class CompraAsignacionController extends Controller
{
    public function __construct(private CompraAsignacionService $service, private GiroActual $giro) {}

    /** Guarda el reparto completo de la compra. */
    public function guardar(Request $request, string $id)
    {
        $this->soloAgencia();
        $compra = Compra::findOrFail($id);
        $this->service->guardar($compra, $request->input('filas'), (int) auth('api')->id());

        return response()->json([
            'code' => 200,
            'message' => 'Reparto guardado',
            'asignaciones' => $this->service->detalle($compra),
        ]);
    }

    public function destinos(Request $request)
    {
        $this->soloAgencia();
        $tipo = $request->query('tipo') === CompraAsignacion::DESTINO_SALIDA
            ? CompraAsignacion::DESTINO_SALIDA
            : CompraAsignacion::DESTINO_RESERVA;

        return response()->json([
            'destinos' => $this->service->destinos($tipo, trim((string) $request->query('search'))),
        ]);
    }

    public function deReserva(string $id)
    {
        $this->soloAgencia();
        $this->exigirVerReservas();
        $reserva = Reserva::findOrFail($id);
        $this->authorize('view', $reserva);

        return response()->json([
            'compras' => $this->service->comprasDeFicha(CompraAsignacion::DESTINO_RESERVA, $reserva->id),
        ]);
    }

    // Las salidas no tienen dueño (Q10).
    public function deSalida(string $id)
    {
        $this->soloAgencia();
        $this->exigirVerReservas();
        $salida = SalidaOperativa::findOrFail($id);

        return response()->json([
            'compras' => $this->service->comprasDeFicha(CompraAsignacion::DESTINO_SALIDA, $salida->id),
        ]);
    }

    private function soloAgencia(): void
    {
        abort_unless($this->giro->es('agencia_viajes'), 404);
    }

    private function exigirVerReservas(): void
    {
        $usuario = auth('api')->user();
        abort_unless($usuario?->can('reservas.ver') || $usuario?->can('reservas.ver_todas'), 403);
    }
}
