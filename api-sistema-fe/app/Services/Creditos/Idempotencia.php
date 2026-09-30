<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Models\Creditos\CreditoOperacion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Idempotencia de las escrituras (00 §4, 03-api decisión 1). La fila de credito_operaciones
 * se inserta ANTES de ejecutar la acción, dentro de la misma transacción: un reintento
 * concurrente con la misma clave espera el índice único y, cuando el primero confirma,
 * recibe la respuesta guardada. Si la acción falla, la transacción revierte la fila y la
 * clave puede reintentarse.
 */
class Idempotencia
{
    /**
     * @param array<string, mixed> $solicitud contenido que define "la misma operación"
     * @param \Closure(): array{0: array<string, mixed>, 1: ?int} $accion devuelve [respuesta, credito_id]
     * @return array<string, mixed>
     */
    public function ejecutar(string $clave, string $operacion, array $solicitud, User $usuario, \Closure $accion): array
    {
        $hash = hash('sha256', $operacion . '|' . json_encode($this->normalizar($solicitud)));

        $existente = CreditoOperacion::where('clave', $clave)->first();
        if ($existente !== null) {
            return $this->repetida($existente, $hash, $usuario);
        }

        try {
            return DB::transaction(function () use ($clave, $operacion, $hash, $usuario, $accion): array {
                $registro = CreditoOperacion::create([
                    'clave' => $clave,
                    'operacion' => $operacion,
                    'usuario_id' => $usuario->id,
                    'hash_solicitud' => $hash,
                ]);

                [$respuesta, $creditoId] = $accion();
                $registro->update(['respuesta' => $respuesta, 'credito_id' => $creditoId]);

                return $respuesta;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existente = CreditoOperacion::where('clave', $clave)->first();
            if ($existente === null) {
                throw $e;
            }

            return $this->repetida($existente, $hash, $usuario);
        }
    }

    /** @return array<string, mixed> */
    private function repetida(CreditoOperacion $existente, string $hash, User $usuario): array
    {
        if ($existente->hash_solicitud !== $hash || $existente->usuario_id !== $usuario->id) {
            throw new HttpException(409, 'Esta clave de operación ya se usó con otros datos. Genera una nueva e intenta otra vez.');
        }

        return $existente->respuesta ?? [];
    }

    private function normalizar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }
        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return array_map($this->normalizar(...), $valor);
    }
}
