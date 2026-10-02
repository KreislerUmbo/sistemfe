<?php

namespace App\Http\Controllers\Client;

use App\Enums\Creditos\CreditoEstado;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ClienteRequest;
use App\Http\Resources\Client\ClientResource;
use App\Models\Client\Client;
use App\Models\Creditos\Credito;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\ClienteCreditoService;
use App\Services\Creditos\ClientesCreditoConsulta;
use Illuminate\Validation\Rule;
use App\Services\Tenancy\GiroActual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Clientes, todos los giros. Las respuestas de negocio mantienen el formato histórico
 * ({code, message}, HTTP 200) que leen las pantallas de Ventas/Agencia:
 *  - 405: el documento ya es de otro cliente (nunca se repite, 04c).
 *  - 410: el documento es de un cliente eliminado: se ofrece restaurarlo.
 *  - 409: cliente sin documento con un nombre ya registrado: se guarda solo si se confirma.
 * En el giro Créditos, sin creditos.ver_todos solo se ven los clientes de su cartera (04c P3).
 */
class ClientController extends Controller
{
    public function __construct(
        private readonly AlcanceCartera $alcance,
        private readonly ClienteCreditoService $creditos,
    ) {
    }

    // ── Lista paginada con búsqueda ──────────────────────────────────
    public function index(Request $request)
    {
        $search = $request->get('search', '');

        // ?per_page= opcional (default 25, igual que antes) — acotado entre
        // 1 y 100, mismo criterio que SaleController::index().
        $perPage = min(100, max(1, (int) $request->get('per_page', 25)));

        $consulta = $this->visibles(Client::query())->whereRaw(
            "(COALESCE(clients.phone,'') || ' ' || COALESCE(clients.name,'') || ' ' || COALESCE(clients.full_name,'') || ' ' || COALESCE(clients.n_document,'')) ILIKE ?",
            ["%{$search}%"]
        );

        // 04c: en el giro Créditos el listado suma asesor, situación y ficha, con sus filtros.
        $creditos = $this->esCreditos() ? app(ClientesCreditoConsulta::class) : null;
        if ($creditos !== null) {
            $filtros = $request->validate([
                'asesor_id' => ['nullable', 'integer', 'min:0'],
                'situacion' => ['nullable', Rule::in(ClientesCreditoConsulta::SITUACIONES)],
                'ficha_incompleta' => ['nullable', 'boolean'],
            ]);
            $creditos->filtrar($consulta, [
                'asesor_id' => isset($filtros['asesor_id']) ? (int) $filtros['asesor_id'] : null,
                'situacion' => $filtros['situacion'] ?? null,
                'ficha_incompleta' => $request->boolean('ficha_incompleta'),
            ]);
        }

        $clients = $consulta->orderBy('id', 'desc')->paginate($perPage);
        // Misma forma que ClientCollection ({data: [...]}), con los datos de crédito por fila.
        $filas = ClientResource::collection($clients->getCollection())->resolve();
        if ($creditos !== null) {
            $datos = $creditos->datos($clients->pluck('id')->all());
            $filas = array_map(static fn (array $c): array => [...$c, 'credito' => $datos[$c['id']] ?? null], $filas);
        }
        $lista = ['data' => $filas];

        return response()->json([
            'total'    => $clients->total(),
            'paginate' => $perPage,
            'clients'  => $lista,
        ]);
    }

    /**
     * Qué secciones muestra la página del cliente (04c). El frontend no conoce el giro del
     * tenant y los permisos no alcanzan: el Super-Admin los tiene todos en cualquier giro.
     */
    public function contexto(): JsonResponse
    {
        return response()->json([
            'giro' => app(GiroActual::class)->valor(),
            'creditos' => $this->esCreditos(),
        ]);
    }

    // ── Búsqueda online DNI / RUC (apisperu) ────────────────────────
    public function searchDocument(string $type, string $number)
    {
        $token    = config('services.apisperu.token');
        $endpoint = strtolower($type) === 'dni' ? 'dni' : 'ruc';
        if (! $token) {
            return response()->json(['success' => false, 'message' => 'La búsqueda en línea de documentos no está configurada.'], 503);
        }

        try {
            $response = Http::get("https://dniruc.apisperu.com/api/v1/{$endpoint}/{$number}", [
                'token' => $token,
            ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'success' => false,
                'message' => 'No se pudo obtener la información',
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar el documento: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ── Campos permitidos (evita mass assignment no deseado) ─────────
    // password y user_id NO están aquí — no se pueden pisar desde este endpoint
    private function allowedFields(): array
    {
        return [
            'type_client',
            'type_document',
            'n_document',
            'cod_tipo_doc_sunat',   // nuevo — Catálogo 06 SUNAT
            'es_amazonia',          // nuevo — zona Amazonía Ley 27037
            'name',
            'surname',
            'full_name',
            'name_comerc',
            'email',
            'phone',
            'birth_date',
            'gender',
            'address',
            'ubigeo_region',
            'ubigeo_provincia',
            'ubigeo_distrito',
            'region',
            'provincia',
            'distrito',
            'state',
            'regimen_tributario',
            'es_agente_retencion',
        ];
    }

    // ── Crear cliente ────────────────────────────────────────────────
    public function store(ClienteRequest $request)
    {
        if ($conflicto = $this->conflicto($request, null)) {
            return $conflicto;
        }

        try {
            $client = DB::transaction(function () use ($request): Client {
                $client = Client::create($request->only($this->allowedFields()));
                if ($this->esCreditos()) {
                    $elegido = $request->filled('asesor_id') ? User::find((int) $request->input('asesor_id')) : null;
                    $this->creditos->asignarAlRegistrar($client, $this->usuario(), $elegido);
                }

                return $client;
            });
        } catch (QueryException $e) {
            return $this->documentoDuplicado($e) ?? throw $e;
        }

        return response()->json([
            'code'    => 200,
            'message' => 'Cliente creado con éxito.',
            'client'  => ClientResource::make($client),
        ]);
    }

    public function show(string $id)
    {
        return response()->json([
            'code'   => 200,
            'client' => ClientResource::make($this->visible($id)),
        ]);
    }

    // ── Actualizar cliente ───────────────────────────────────────────
    public function update(ClienteRequest $request, string $id)
    {
        $client = $this->visible($id);

        if ($conflicto = $this->conflicto($request, $client->id)) {
            return $conflicto;
        }

        try {
            $client->update($request->only($this->allowedFields()));
        } catch (QueryException $e) {
            return $this->documentoDuplicado($e) ?? throw $e;
        }

        return response()->json([
            'code'    => 200,
            'message' => 'Cliente actualizado con éxito.',
            'client'  => ClientResource::make($client),
        ]);
    }

    // ── Eliminar cliente (soft delete) ───────────────────────────────
    public function destroy(string $id)
    {
        $client = $this->visible($id);

        // 04c P4: un crédito no puede quedar apuntando a un cliente eliminado.
        $tieneCreditos = Credito::where('cliente_id', $client->id)->where('estado', '!=', CreditoEstado::Anulado)->exists();
        if ($tieneCreditos) {
            return response()->json([
                'code'    => 405,
                'message' => 'El cliente tiene créditos registrados y no se puede eliminar. Puedes marcarlo como inactivo.',
            ]);
        }

        $client->delete();

        return response()->json([
            "code" => 200,
            "message" => "Cliente eliminado con exito"
        ]);
    }

    // ── Restaurar un cliente eliminado (respuesta 410 del alta) ──────
    public function restore(string $id)
    {
        $client = Client::onlyTrashed()->findOrFail($id);
        $ocupado = $client->type_document !== 'SND' && Client::where('type_document', $client->type_document)
            ->where('n_document', $client->n_document)->exists();
        if ($ocupado) {
            return response()->json([
                'code'    => 405,
                'message' => 'Ya existe otro cliente activo con ese número de documento.',
            ]);
        }

        DB::transaction(function () use ($client): void {
            $client->restore();
            if ($this->esCreditos() && ! $this->alcance->puedeVerCliente($client->id, $this->usuario())) {
                $this->creditos->asignarAlRegistrar($client, $this->usuario());
            }
        });

        return response()->json([
            'code'    => 200,
            'message' => 'Cliente restaurado.',
            'client'  => ClientResource::make($client->refresh()),
        ]);
    }

    /**
     * Reglas de duplicado (01-oct-2026, todos los giros): el número de documento nunca se repite;
     * el nombre repetido solo importa en clientes sin documento, y ahí solo advierte.
     */
    private function conflicto(ClienteRequest $request, ?int $excluirId): ?JsonResponse
    {
        $tipo = $request->input('type_document');

        if ($tipo !== 'SND') {
            $mismoDocumento = Client::withTrashed()
                ->where('type_document', $tipo)
                ->where('n_document', $request->input('n_document'))
                ->when($excluirId !== null, static fn (Builder $q) => $q->where('id', '!=', $excluirId))
                ->orderByRaw('deleted_at IS NOT NULL')   // primero el activo
                ->first();
            if ($mismoDocumento === null) {
                return null;
            }

            if ($mismoDocumento->trashed()) {
                return response()->json([
                    'code'    => 410,
                    'message' => "Ese documento pertenece a un cliente eliminado ({$mismoDocumento->full_name}). Puedes restaurarlo en vez de registrar uno nuevo.",
                    'cliente_eliminado' => ['id' => $mismoDocumento->id, 'full_name' => $mismoDocumento->full_name],
                ]);
            }

            // Fuera de su cartera no se revela de quién es (datos personales).
            $visible = ! $this->esCreditos() || $this->alcance->puedeVerCliente($mismoDocumento->id, $this->usuario());

            return response()->json([
                'code'    => 405,
                'message' => $visible
                    ? "Ya existe un cliente con ese número de documento: {$mismoDocumento->full_name}."
                    : 'Ese número de documento ya está registrado en la cartera de otro asesor.',
                'cliente_existente' => $visible ? ['id' => $mismoDocumento->id, 'full_name' => $mismoDocumento->full_name] : null,
            ]);
        }

        if ($request->boolean('confirmar_nombre_repetido')) {
            return null;
        }
        $mismoNombre = Client::where('type_document', 'SND')
            ->whereRaw('lower(full_name) = lower(?)', [$request->input('full_name')])
            ->when($excluirId !== null, static fn (Builder $q) => $q->where('id', '!=', $excluirId))
            ->exists();

        return $mismoNombre ? response()->json([
            'code'    => 409,
            'message' => 'Ya hay un cliente sin documento con ese nombre. ¿Es otra persona? Confirma para registrarlo igual.',
            'requiere_confirmacion' => true,
        ]) : null;
    }

    /** Dos altas simultáneas con el mismo documento: la segunda choca con el índice único. */
    private function documentoDuplicado(QueryException $e): ?JsonResponse
    {
        if ($e->getCode() !== '23505' || ! str_contains($e->getMessage(), 'clients_documento_unico')) {
            return null;
        }

        return response()->json([
            'code'    => 405,
            'message' => 'Ya existe un cliente con ese número de documento.',
        ]);
    }

    private function visible(string $id): Client
    {
        return $this->visibles(Client::query())->findOrFail($id);
    }

    /**
     * @param Builder<Client> $consulta
     * @return Builder<Client>
     */
    private function visibles(Builder $consulta): Builder
    {
        return $this->esCreditos() ? $this->alcance->aplicarAClientes($consulta, $this->usuario()) : $consulta;
    }

    private function esCreditos(): bool
    {
        return app(GiroActual::class)->es(GiroActual::CREDITOS);
    }

    private function usuario(): User
    {
        /** @var User */
        return auth('api')->user();
    }
}
