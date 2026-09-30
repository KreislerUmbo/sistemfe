<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Http\Controllers\Controller;
use App\Models\Creditos\Credito;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\Idempotencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * Base de los controllers del módulo (00 §4: FormRequest → servicio → Resource). Los créditos se
 * buscan por id en el controller (no binding implícito: SubstituteBindings corre antes que el
 * middleware de tenancy) y siempre pasan por el alcance de cartera.
 */
abstract class ControllerCreditos extends Controller
{
    protected function usuario(): User
    {
        return auth('api')->user();
    }

    protected function credito(int $id): Credito
    {
        $credito = Credito::findOrFail($id);
        app(AlcanceCartera::class)->asegurar($credito, $this->usuario());

        return $credito;
    }

    /**
     * Ejecuta una escritura una sola vez por clave (03-api decisión 1).
     *
     * @param \Closure(): array{0: array<string, mixed>, 1: ?int} $accion devuelve [respuesta, credito_id]
     * @param array<string, mixed> $extra datos de la ruta que también definen "la misma operación"
     */
    protected function idempotente(FormRequest $request, string $operacion, \Closure $accion, array $extra = [], int $estado = 200): JsonResponse
    {
        $respuesta = app(Idempotencia::class)->ejecutar(
            (string) $request->input('clave_idempotencia'),
            $operacion,
            [...Arr::except($request->validated(), ['clave_idempotencia']), ...$extra],
            $this->usuario(),
            $accion,
        );

        return response()->json($respuesta, $estado);
    }
}
