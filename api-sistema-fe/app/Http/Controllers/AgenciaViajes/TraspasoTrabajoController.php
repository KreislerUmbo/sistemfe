<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crm\TraspasoTrabajoService;
use App\Services\Tenancy\GiroActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

// T-061 (CRM) — «Pasar su trabajo a…» desde Usuarios. La ruta exige edit_user; solo existe en
// Agencia (en otro giro no hay cotizaciones: 404). El usuario de origen puede estar inactivo o
// eliminado. La lógica vive en TraspasoTrabajoService.
class TraspasoTrabajoController extends Controller
{
    use ValidaEnEspanol;

    private const SUPER_ADMIN = 'Super-Admin';

    public function __construct(
        private readonly TraspasoTrabajoService $traspaso,
        private readonly GiroActual $giro,
    ) {
    }

    // GET users/{id}/trabajo-abierto — cuántas cotizaciones abiertas y acciones pendientes tiene, y
    // a quién se le puede pasar (para el selector del modal).
    public function resumen(string $id): JsonResponse
    {
        $this->soloAgencia();
        $usuario = User::withTrashed()->findOrFail($id);

        return response()->json([
            'data' => $this->traspaso->resumen($usuario->id),
            'destinos' => $this->traspaso->destinos($usuario->id, auth('api')->user()->hasRole(self::SUPER_ADMIN)),
        ]);
    }

    // POST users/{id}/traspasar-trabajo {hacia_usuario_id}
    public function traspasar(Request $request, string $id): JsonResponse
    {
        $this->soloAgencia();
        $desde = User::withTrashed()->findOrFail($id);

        $validator = $this->validadorEs($request->all(), [
            'hacia_usuario_id' => ['required', 'integer'],
        ], ['hacia_usuario_id' => 'usuario que recibe']);
        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }
        $hacia = User::withTrashed()->findOrFail((int) $request->input('hacia_usuario_id'));

        // Como UserController::protegerPrivilegios(): solo un Super-Admin toca a otro Super-Admin.
        $actor = auth('api')->user();
        if (! $actor->hasRole(self::SUPER_ADMIN) && ($desde->hasRole(self::SUPER_ADMIN) || $hacia->hasRole(self::SUPER_ADMIN))) {
            throw new HttpException(403, 'Solo un Super-Admin puede pasar el trabajo de o a un usuario Super-Admin.');
        }

        return response()->json(['data' => $this->traspaso->traspasar($desde->id, $hacia, $actor)]);
    }

    private function soloAgencia(): void
    {
        if (! $this->giro->es('agencia_viajes')) {
            throw new HttpException(404, 'No disponible para este giro.');
        }
    }
}
