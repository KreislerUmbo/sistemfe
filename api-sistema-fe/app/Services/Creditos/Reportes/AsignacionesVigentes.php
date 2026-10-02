<?php

declare(strict_types=1);

namespace App\Services\Creditos\Reportes;

use App\Enums\Creditos\FuncionCartera;
use App\Enums\Creditos\ModoAsignacionCartera;
use App\Models\Creditos\CarteraAsignacion;
use App\Models\User;

/** Asesor y cobrador vigentes de muchos clientes con dos consultas (04d). */
class AsignacionesVigentes
{
    /**
     * @param list<int> $clienteIds
     * @return array<int, array{asesor_id: int|null, asesor: string|null, cobrador_id: int|null, cobrador: string|null}>
     */
    public function de(array $clienteIds): array
    {
        $filas = CarteraAsignacion::vigentes()->where('tipo', ModoAsignacionCartera::Cliente)
            ->whereIn('referencia_id', array_values(array_unique($clienteIds)))->get(['referencia_id', 'funcion', 'usuario_id']);
        $nombres = User::withTrashed()->whereIn('id', $filas->pluck('usuario_id')->unique())->pluck('name', 'id');

        $resultado = [];
        foreach ($clienteIds as $id) {
            $resultado[$id] = ['asesor_id' => null, 'asesor' => null, 'cobrador_id' => null, 'cobrador' => null];
        }
        foreach ($filas as $f) {
            $clave = $f->funcion === FuncionCartera::Asesor ? 'asesor' : 'cobrador';
            $resultado[$f->referencia_id][$clave . '_id'] = (int) $f->usuario_id;
            $resultado[$f->referencia_id][$clave] = $nombres[$f->usuario_id] ?? null;
        }

        return $resultado;
    }
}
