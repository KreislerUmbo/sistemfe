<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\ModoAsignacionCartera;
use App\Models\Creditos\CarteraAsignacion;
use App\Models\Creditos\Credito;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Solo su cartera" (00 1.14, 03-api decisión 4): sin creditos.ver_todos, un usuario solo
 * ve y cobra créditos de clientes asignados a él en cartera_asignaciones (vigentes, por
 * cliente). Se aplica en el backend; ocultar en pantalla no alcanza.
 */
class AlcanceCartera
{
    public const PERMISO_VER_TODOS = 'creditos.ver_todos';

    /**
     * @param Builder<Credito> $creditos
     * @return Builder<Credito>
     */
    public function aplicar(Builder $creditos, User $usuario): Builder
    {
        if ($usuario->can(self::PERMISO_VER_TODOS)) {
            return $creditos;
        }

        return $creditos->whereIn('cliente_id', $this->clientesAsignados($usuario));
    }

    public function puedeVer(Credito $credito, User $usuario): bool
    {
        return $usuario->can(self::PERMISO_VER_TODOS)
            || CarteraAsignacion::vigentes()
                ->where('tipo', ModoAsignacionCartera::Cliente)
                ->where('referencia_id', $credito->cliente_id)
                ->where('cobrador_id', $usuario->id)
                ->exists();
    }

    /** 404 y no 403: fuera de su cartera, el crédito "no existe" para ese usuario. */
    public function asegurar(Credito $credito, User $usuario): void
    {
        if (! $this->puedeVer($credito, $usuario)) {
            throw new HttpException(404, 'Crédito no encontrado.');
        }
    }

    private function clientesAsignados(User $usuario): \Closure
    {
        return fn ($q) => $q->select('referencia_id')
            ->from('cartera_asignaciones')
            ->where('tipo', ModoAsignacionCartera::Cliente->value)
            ->where('cobrador_id', $usuario->id)
            ->whereNull('vigente_hasta');
    }
}
