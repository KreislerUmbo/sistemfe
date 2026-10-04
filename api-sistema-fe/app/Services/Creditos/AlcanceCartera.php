<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\FuncionCartera;
use App\Enums\Creditos\ModoAsignacionCartera;
use App\Models\Creditos\CarteraAsignacion;
use App\Models\Creditos\Credito;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Solo su cartera" (00 1.14, 03-api decisión 4, 04c): sin creditos.ver_todos, un usuario solo
 * ve los clientes de los que es asesor o cobrador vigente, y sus créditos. Se aplica en el
 * backend; ocultar en pantalla no alcanza.
 */
class AlcanceCartera
{
    public const PERMISO_VER_TODOS = 'creditos.ver_todos';

    /**
     * @param Builder<Credito> $creditos
     * @param FuncionCartera|null $funcion null = asesor o cobrador; Cobrador = su ruta de cobro
     * @return Builder<Credito>
     */
    public function aplicar(Builder $creditos, User $usuario, ?FuncionCartera $funcion = null): Builder
    {
        if ($usuario->can(self::PERMISO_VER_TODOS)) {
            return $creditos;
        }

        return $creditos->whereIn('cliente_id', $this->clientesAsignados($usuario, $funcion));
    }

    /**
     * Mismo filtro sobre una consulta de clientes (tabla clients).
     *
     * @template T of Builder
     * @param T $clientes
     * @return T
     */
    public function aplicarAClientes(Builder $clientes, User $usuario): Builder
    {
        if ($usuario->can(self::PERMISO_VER_TODOS)) {
            return $clientes;
        }

        return $clientes->whereIn($clientes->getModel()->getQualifiedKeyName(), $this->clientesAsignados($usuario, null));
    }

    public function puedeVer(Credito $credito, User $usuario): bool
    {
        return $this->puedeVerCliente($credito->cliente_id, $usuario);
    }

    public function puedeVerCliente(int $clienteId, User $usuario): bool
    {
        return $usuario->can(self::PERMISO_VER_TODOS)
            || CarteraAsignacion::vigentes()->delCliente($clienteId)->where('usuario_id', $usuario->id)->exists();
    }

    /** 404 y no 403: fuera de su cartera, el crédito "no existe" para ese usuario. */
    public function asegurar(Credito $credito, User $usuario): void
    {
        if (! $this->puedeVer($credito, $usuario)) {
            throw new HttpException(404, 'Crédito no encontrado.');
        }
    }

    private function clientesAsignados(User $usuario, ?FuncionCartera $funcion): \Closure
    {
        return static fn ($q) => $q->select('referencia_id')
            ->from('cartera_asignaciones')
            ->where('tipo', ModoAsignacionCartera::Cliente->value)
            ->where('usuario_id', $usuario->id)
            ->when($funcion !== null, static fn ($q) => $q->where('funcion', $funcion->value))
            ->whereNull('vigente_hasta');
    }
}
