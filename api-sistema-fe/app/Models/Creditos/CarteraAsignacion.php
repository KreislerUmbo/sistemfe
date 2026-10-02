<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\FuncionCartera;
use App\Enums\Creditos\ModoAsignacionCartera;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Asignación de cartera (1.14, 04c): un usuario como asesor o cobrador de un cliente.
 * vigente_hasta null = vigente; una sola vigente por cliente y función (índice parcial).
 */
class CarteraAsignacion extends Model
{
    protected $table = 'cartera_asignaciones';

    protected $fillable = [
        'usuario_id',
        'funcion',
        'tipo',
        'referencia_id',
        'vigente_desde',
        'vigente_hasta',
        'asignado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => ModoAsignacionCartera::class,
            'funcion' => FuncionCartera::class,
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('vigente_hasta');
    }

    /** @param Builder<self> $query */
    public function scopeDelCliente(Builder $query, int $clienteId): void
    {
        $query->where('tipo', ModoAsignacionCartera::Cliente)->where('referencia_id', $clienteId);
    }

    /** Usuario vigente del cliente en esa función (null = sin asignar). */
    public static function usuarioVigente(int $clienteId, FuncionCartera $funcion): ?int
    {
        $id = self::vigentes()->delCliente($clienteId)->where('funcion', $funcion)->value('usuario_id');

        return $id === null ? null : (int) $id;
    }
}
