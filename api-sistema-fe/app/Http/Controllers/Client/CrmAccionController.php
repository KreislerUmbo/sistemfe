<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\ValidaEnEspanol;
use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\Client\Client;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Models\User;
use App\Services\Crm\AccionesCrm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

// T-051 (CRM C2) — próximas acciones con el cliente desde la cotización, la reserva o la ficha:
// agendar, ver las pendientes, reprogramar y marcar hecha. Las reglas de quién puede qué viven
// en AccionesCrm.
class CrmAccionController extends Controller
{
    use ValidaEnEspanol;

    private const DIAS_PROXIMO_CONTACTO_DEFECTO = 2;

    public function __construct(private readonly AccionesCrm $acciones)
    {
    }

    // GET crm/acciones/opciones — lo que necesita la pantalla para proponer o agendar una acción.
    public function opciones(): JsonResponse
    {
        $dias = Schema::hasTable('configuracion_agencia')
            ? ConfiguracionAgencia::value('dias_proximo_contacto')
            : null;

        return response()->json([
            'dias_proximo_contacto' => $dias ?? self::DIAS_PROXIMO_CONTACTO_DEFECTO,
            'tipos' => collect(CrmAccion::TIPOS)->map(fn ($etiqueta, $valor) => ['valor' => $valor, 'etiqueta' => $etiqueta])->values(),
            'responsables' => User::query()
                ->whereNot('state', User::STATE_INACTIVO)
                ->orderBy('name')
                ->get(['id', 'name', 'surname'])
                ->map(fn (User $u) => ['id' => $u->id, 'nombre' => self::nombre($u)]),
        ]);
    }

    // GET crm/acciones?referencia_tipo=&referencia_id= | ?cliente_id= — pendientes de una
    // cotización, de una reserva o de todo el cliente (ficha), la más próxima primero.
    public function index(Request $request): JsonResponse
    {
        $validator = $this->validadorEs($request->all(), [
            'referencia_tipo' => 'nullable|in:' . CrmActividad::REFERENCIA_COTIZACION . ',' . CrmActividad::REFERENCIA_RESERVA,
            'referencia_id' => 'nullable|integer|required_with:referencia_tipo',
            'cliente_id' => 'nullable|integer|required_without:referencia_tipo',
        ]);
        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $datos = $validator->validated();
        $usuario = auth('api')->user();
        $consulta = CrmAccion::pendientes()->with('responsable:id,name,surname');

        if (! empty($datos['referencia_tipo'])) {
            $referencia = $this->acciones->buscarReferencia($datos['referencia_tipo'], (int) $datos['referencia_id']);
            abort_unless($referencia, 404);
            abort_unless($this->acciones->puedeVerReferencia($usuario, $referencia), 403);
            $consulta->deReferencia($datos['referencia_tipo'], (int) $datos['referencia_id']);
        } else {
            abort_unless($usuario->canAny(['list_client', 'edit_client']), 403);
            $consulta->where('cliente_id', Client::findOrFail($datos['cliente_id'])->id);
        }

        $acciones = $consulta->orderBy('fecha')->orderBy('id')->get()
            ->map(fn (CrmAccion $a) => $this->fila($a, $usuario));

        return response()->json(['acciones' => $acciones]);
    }

    // POST clients/{id}/acciones — "Agendar acción".
    public function store(Request $request, string $id): JsonResponse
    {
        $cliente = Client::findOrFail($id);

        $validator = $this->validadorEs($request->all(), [
            ...AccionesCrm::reglas(),
            'referencia_tipo' => 'nullable|in:' . CrmActividad::REFERENCIA_COTIZACION . ',' . CrmActividad::REFERENCIA_RESERVA,
            'referencia_id' => 'nullable|integer|required_with:referencia_tipo',
        ], ['tipo' => 'qué hacer', 'fecha' => 'fecha', 'responsable_id' => 'responsable', 'descripcion' => 'descripción']);
        $validator->setCustomMessages(AccionesCrm::mensajes());
        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $datos = $validator->validated();
        $usuario = auth('api')->user();

        if (! empty($datos['referencia_tipo'])) {
            $referencia = $this->acciones->buscarReferencia($datos['referencia_tipo'], (int) $datos['referencia_id']);
            abort_unless($referencia, 404);
            abort_unless($this->acciones->puedeEditarReferencia($usuario, $referencia), 403);

            if ((int) $this->acciones->clienteDeReferencia($referencia) !== (int) $cliente->id) {
                return response()->json(['code' => 422, 'message' => 'La referencia no es de este cliente.'], 422);
            }
        } else {
            abort_unless($usuario->can('edit_client'), 403);
        }

        $descripcion = trim((string) ($datos['descripcion'] ?? ''));

        $accion = CrmAccion::create([
            'cliente_id' => $cliente->id,
            'referencia_tipo' => $datos['referencia_tipo'] ?? null,
            'referencia_id' => ! empty($datos['referencia_tipo']) ? $datos['referencia_id'] : null,
            'tipo' => $datos['tipo'],
            'descripcion' => $descripcion !== '' ? $descripcion : null,
            'fecha' => $datos['fecha'],
            'responsable_id' => $datos['responsable_id'],
            'creada_por' => $usuario->id,
        ]);

        return response()->json(['code' => 200, 'message' => 'Acción agendada.', 'accion_id' => $accion->id]);
    }

    // PUT crm-acciones/{id} — reprogramar: otra fecha y, si se quiere, otro responsable.
    public function update(Request $request, string $id): JsonResponse
    {
        $accion = CrmAccion::findOrFail($id);
        $usuario = auth('api')->user();
        abort_unless($this->acciones->puedeGestionar($usuario, $accion), 403);

        $reglas = AccionesCrm::reglas('', false);
        $validator = $this->validadorEs($request->all(), [
            'fecha' => $reglas['fecha'],
            'responsable_id' => ['sometimes', ...$reglas['responsable_id']],
        ], ['fecha' => 'fecha', 'responsable_id' => 'responsable']);
        $validator->setCustomMessages(AccionesCrm::mensajes());
        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        if (! $accion->estaPendiente()) {
            return response()->json(['code' => 422, 'message' => 'Esta acción ya está hecha: agenda una nueva.'], 422);
        }

        $accion->update($validator->validated());

        return response()->json(['code' => 200, 'message' => 'Acción reprogramada.']);
    }

    // POST crm-acciones/{id}/hecha — con el resultado (actividad) y la siguiente, ambos opcionales.
    public function marcarHecha(Request $request, string $id): JsonResponse
    {
        $accion = CrmAccion::findOrFail($id);
        $usuario = auth('api')->user();
        abort_unless($this->acciones->puedeGestionar($usuario, $accion), 403);

        $validator = $this->validadorEs($request->all(), [
            'resultado' => 'nullable|array',
            'resultado.tipo' => ['required_with:resultado', Rule::in(CrmActividad::TIPOS_MANUALES)],
            'resultado.nota' => 'nullable|string|max:' . CrmActividad::NOTA_MAX . '|required_if:resultado.tipo,' . CrmActividad::TIPO_NOTA,
            'siguiente' => 'nullable|array',
            ...AccionesCrm::reglas('siguiente.'),
        ], [
            'resultado.tipo' => 'tipo de contacto', 'resultado.nota' => 'nota',
            'siguiente.tipo' => 'qué hacer', 'siguiente.fecha' => 'fecha', 'siguiente.responsable_id' => 'responsable',
            'siguiente.descripcion' => 'descripción',
        ]);
        $validator->setCustomMessages([...AccionesCrm::mensajes('siguiente.'), 'resultado.nota.required_if' => 'Escribe la nota.']);
        if ($validator->fails()) {
            return $this->respuestaValidacionEs($validator);
        }

        $datos = $validator->validated();

        try {
            $hecho = $this->acciones->marcarHecha($accion, $usuario, $datos['resultado'] ?? null, $datos['siguiente'] ?? null);
        } catch (HttpException $e) {
            return response()->json(['code' => $e->getStatusCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json([
            'code' => 200,
            'message' => 'Acción marcada como hecha.',
            'actividad_id' => $hecho['actividad']?->id,
            'siguiente_id' => $hecho['siguiente']?->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function fila(CrmAccion $accion, User $usuario): array
    {
        return [
            'id' => $accion->id,
            'cliente_id' => $accion->cliente_id,
            'referencia_tipo' => $accion->referencia_tipo,
            'referencia_id' => $accion->referencia_id,
            'tipo' => $accion->tipo,
            'tipo_etiqueta' => $accion->etiquetaTipo(),
            'descripcion' => $accion->descripcion,
            'fecha' => $accion->fecha->toDateString(),
            'responsable' => ['id' => $accion->responsable_id, 'nombre' => self::nombre($accion->responsable)],
            'puede_gestionar' => $this->acciones->puedeGestionar($usuario, $accion),
        ];
    }

    private static function nombre(?User $usuario): string
    {
        return trim(($usuario?->name ?? '') . ' ' . ($usuario?->surname ?? ''));
    }
}
