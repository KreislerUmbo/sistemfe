<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ClientePerfilViajero;
use App\Models\AgenciaViajes\CrmTipoViaje;
use App\Models\Client\Client;
use App\Services\Tenancy\GiroActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

// T-060 (CRM C5) — preferencias del viajero (una fila por cliente). Se editan en la ficha del
// cliente (edit_client) y el cotizador las lee en solo lectura. Solo Agencia (404 en otro giro: la
// tabla no existe).
class ClientePerfilViajeroController extends Controller
{
    use ValidaEnEspanol;

    private const ATRIBUTOS = [
        'tipos_viaje' => 'tipos de viaje',
        'tipos_viaje.*' => 'tipo de viaje',
        'presupuesto_por_persona' => 'presupuesto por persona',
        'moneda' => 'moneda',
        'alimentacion' => 'alimentación',
        'movilidad' => 'movilidad',
        'hotel_preferido' => 'hotel preferido',
        'notas' => 'notas',
    ];

    public function __construct(private readonly GiroActual $giro)
    {
    }

    // GET clients/{id}/perfil-viajero — perfil null si nunca se guardó.
    public function show(string $id): JsonResponse
    {
        $cliente = $this->cliente($id);
        $perfil = ClientePerfilViajero::where('cliente_id', $cliente->id)->first();

        return response()->json(['perfil' => $perfil ? $this->presentar($perfil) : null]);
    }

    // PUT clients/{id}/perfil-viajero — crea o reemplaza el perfil completo.
    public function update(Request $request, string $id): JsonResponse
    {
        $cliente = $this->cliente($id);

        $validator = $this->validadorEs($request->all(), [
            'tipos_viaje' => 'present|array|max:30',
            'tipos_viaje.*' => ['integer', 'distinct', Rule::exists('crm_tipos_viaje', 'id')],
            'presupuesto_por_persona' => 'nullable|numeric|min:0|max:9999999999.99',
            'moneda' => ['nullable', 'required_with:presupuesto_por_persona', Rule::in(ClientePerfilViajero::MONEDAS)],
            'alimentacion' => 'nullable|string|max:' . ClientePerfilViajero::TEXTO_MAX,
            'movilidad' => 'nullable|string|max:' . ClientePerfilViajero::TEXTO_MAX,
            'hotel_preferido' => 'nullable|string|max:' . ClientePerfilViajero::TEXTO_MAX,
            'notas' => 'nullable|string|max:' . ClientePerfilViajero::NOTAS_MAX,
        ], self::ATRIBUTOS);

        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $datos = $validator->validated();
        $limpio = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $presupuesto = $datos['presupuesto_por_persona'] ?? null;

        $perfil = DB::transaction(fn () => ClientePerfilViajero::updateOrCreate(['cliente_id' => $cliente->id], [
            'tipos_viaje' => array_values(array_map('intval', $datos['tipos_viaje'])),
            'presupuesto_por_persona' => $presupuesto === null || $presupuesto === '' ? null : round((float) $presupuesto, 2),
            'moneda' => $presupuesto === null || $presupuesto === '' ? null : $datos['moneda'],
            'alimentacion' => $limpio($datos['alimentacion'] ?? null),
            'movilidad' => $limpio($datos['movilidad'] ?? null),
            'hotel_preferido' => $limpio($datos['hotel_preferido'] ?? null),
            'notas' => $limpio($datos['notas'] ?? null),
            'actualizado_por' => auth('api')->id(),
        ]));

        return response()->json(['code' => 200, 'message' => 'Preferencias guardadas.', 'perfil' => $this->presentar($perfil->fresh())]);
    }

    private function presentar(ClientePerfilViajero $perfil): array
    {
        $ids = $perfil->tipos_viaje ?? [];
        // En el orden del catálogo; un tipo desactivado se sigue mostrando.
        $nombres = $ids ? CrmTipoViaje::whereIn('id', $ids)->ordenados()->pluck('nombre')->all() : [];

        return [
            'tipos_viaje' => $ids,
            'tipos_viaje_nombres' => $nombres,
            'presupuesto_por_persona' => $perfil->presupuesto_por_persona !== null ? (float) $perfil->presupuesto_por_persona : null,
            'moneda' => $perfil->moneda,
            'alimentacion' => $perfil->alimentacion,
            'movilidad' => $perfil->movilidad,
            'hotel_preferido' => $perfil->hotel_preferido,
            'notas' => $perfil->notas,
            'vacio' => $perfil->estaVacio(),
            'actualizado_en' => $perfil->updated_at?->toJSON(),
        ];
    }

    private function cliente(string $id): Client
    {
        if (! $this->giro->es('agencia_viajes')) {
            throw new HttpException(404, 'No disponible para este giro.');
        }

        return Client::findOrFail($id);
    }
}
