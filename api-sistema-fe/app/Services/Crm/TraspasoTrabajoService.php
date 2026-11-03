<?php

namespace App\Services\Crm;

use App\Models\AgenciaViajes\Cotizacion;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * T-061 (CRM) — pasar el trabajo abierto de un usuario que se va (inactivo, eliminado o por irse)
 * a otro: sus cotizaciones abiertas (Cotizacion::abiertas()) y sus acciones pendientes. Las
 * cerradas conservan quién vendió o perdió; las reservas siguen con su vendedor. Mismo patrón que
 * «Traspasar cartera» de Créditos (ClienteCreditoService::traspasarCartera()). Solo Agencia.
 */
class TraspasoTrabajoService
{
    public const PERMISO_DESTINO = 'cotizaciones.editar';

    /** @return array{cotizaciones: int, acciones: int} */
    public function resumen(int $usuarioId): array
    {
        return [
            'cotizaciones' => Cotizacion::abiertas()->where('vendedor_id', $usuarioId)->count(),
            'acciones' => CrmAccion::pendientes()->where('responsable_id', $usuarioId)->count(),
        ];
    }

    /**
     * Usuarios que pueden recibir el trabajo (mismo criterio que validarDestino()). Un Super-Admin
     * solo se ofrece a otro Super-Admin, como en TraspasoTrabajoController::traspasar().
     *
     * @return list<array{id: int, nombre: string}>
     */
    public function destinos(int $desdeId, bool $incluirSuperAdmin): array
    {
        return User::permission(self::PERMISO_DESTINO)
            ->where('users.id', '!=', $desdeId)
            ->where('state', '!=', User::STATE_INACTIVO)
            ->when(! $incluirSuperAdmin, fn (Builder $q) => $q->whereDoesntHave('roles', fn (Builder $r) => $r->where('name', 'Super-Admin')))
            ->orderBy('name')->orderBy('surname')
            ->get(['users.id', 'name', 'surname'])
            ->map(static fn (User $u): array => ['id' => $u->id, 'nombre' => self::nombre($u)])
            ->values()->all();
    }

    /**
     * Todo en una transacción. La condición de «abierta» y de «pendiente» va dentro de cada
     * UPDATE (no en una lista de ids leída antes): si alguien aceptó una cotización o marcó hecha
     * una acción antes de que corra el UPDATE, ya no la toca. Una aceptación que se confirma en el
     * mismo instante puede no verse (READ COMMITTED: el EXISTS usa la foto del inicio de la
     * sentencia); la ventana es de milisegundos y no justifica bloquear las alternativas.
     *
     * @return array{cotizaciones: int, acciones: int, clientes: int}
     */
    public function traspasar(int $desdeId, User $hacia, User $actor): array
    {
        $this->validarDestino($desdeId, $hacia);
        $desde = User::withTrashed()->findOrFail($desdeId);

        return DB::transaction(function () use ($desde, $hacia, $actor): array {
            $ahora = now();
            $cotizaciones = $this->actualizarDevolviendo(
                Cotizacion::abiertas()->where('vendedor_id', $desde->id),
                ['vendedor_id' => $hacia->id, 'updated_at' => $ahora],
            );
            $acciones = $this->actualizarDevolviendo(
                CrmAccion::pendientes()->where('responsable_id', $desde->id),
                ['responsable_id' => $hacia->id, 'updated_at' => $ahora],
            );
            if ($cotizaciones->isEmpty() && $acciones->isEmpty()) {
                throw new HttpException(422, 'Ese usuario no tiene trabajo abierto para pasar.');
            }

            // Una actividad por cliente afectado, con lo que se le pasó de ese cliente.
            $porCliente = $cotizaciones->countBy('cliente_id');
            $accionesPorCliente = $acciones->countBy('cliente_id');
            $clientes = $porCliente->keys()->merge($accionesPorCliente->keys())->unique()->sort()->values();
            $de = self::nombre($desde);
            $a = self::nombre($hacia);
            foreach ($clientes as $clienteId) {
                CrmActividad::create([
                    'cliente_id' => $clienteId,
                    'referencia_tipo' => null,
                    'referencia_id' => null,
                    'tipo' => CrmActividad::TIPO_ESTADO,
                    'nota' => "Trabajo traspasado de {$de} a {$a}: " . self::cantidades($porCliente->get($clienteId, 0), $accionesPorCliente->get($clienteId, 0)),
                    'usuario_id' => $actor->id,
                ]);
            }

            return [
                'cotizaciones' => $cotizaciones->count(),
                'acciones' => $acciones->count(),
                'clientes' => $clientes->count(),
            ];
        });
    }

    /** Destino: distinto del origen, activo, no eliminado y que pueda abrir las cotizaciones. */
    private function validarDestino(int $desdeId, User $hacia): void
    {
        if ($hacia->id === $desdeId) {
            throw new HttpException(422, 'Elige un usuario distinto al que tiene hoy el trabajo.');
        }
        if ($hacia->trashed()) {
            throw new HttpException(422, 'El usuario que recibe el trabajo fue eliminado.');
        }
        if ((int) $hacia->state === User::STATE_INACTIVO) {
            throw new HttpException(422, 'El usuario que recibe el trabajo está inactivo.');
        }
        if (! $hacia->can(self::PERMISO_DESTINO)) {
            throw new HttpException(422, 'El usuario que recibe no tiene permiso para editar cotizaciones.');
        }
    }

    /**
     * UPDATE … WHERE (el del builder) RETURNING id, cliente_id: lo que de verdad cambió, para
     * armar las actividades por cliente sin leer los ids antes.
     *
     * @return Collection<int, object{id: int, cliente_id: int}>
     */
    private function actualizarDevolviendo(Builder $consulta, array $valores): Collection
    {
        $query = $consulta->toBase();
        $grammar = $query->getGrammar();
        $sql = $grammar->compileUpdate($query, $valores) . ' returning id, cliente_id';

        return collect(DB::select($sql, $grammar->prepareBindingsForUpdate($query->bindings, $valores), useReadPdo: false));
    }

    private static function nombre(User $usuario): string
    {
        return trim($usuario->name . ' ' . $usuario->surname);
    }

    /** «2 cotizaciones y 1 acción», «1 cotización», «3 acciones». */
    public static function cantidades(int $cotizaciones, int $acciones): string
    {
        $partes = [];
        if ($cotizaciones > 0) {
            $partes[] = $cotizaciones . ($cotizaciones === 1 ? ' cotización' : ' cotizaciones');
        }
        if ($acciones > 0) {
            $partes[] = $acciones . ($acciones === 1 ? ' acción' : ' acciones');
        }

        return implode(' y ', $partes);
    }
}
